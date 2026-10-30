<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AccessControl;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Url;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\Map\MapRepository;

/**
 * ISP Map: serve o HTML de modules/isp_map/ (fora de public/), só para usuário autenticado com perfil
 * de gestor/admin, injetando a configuração da página (token CSRF e URL da API), e expõe a leitura
 * da topologia em GET /api/mapa.
 */
final class MapController
{
    public function __construct(private LocalRepository $localRepository, private MapRepository $mapRepository)
    {
    }

    public function data(Request $request): Response
    {
        $user = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
        $access = $this->localRepository->accessProfileForUser($user);

        if (!AccessControl::isGestor($access)) {
            return Response::json(['ok' => false, 'message' => 'Acesso negado.'], 403, ['Cache-Control' => 'private, no-store']);
        }

        $providerId = $this->localRepository->currentProviderId();
        if ($providerId === null) {
            return Response::json(['ok' => false, 'message' => 'Provedor não configurado.'], 500, ['Cache-Control' => 'private, no-store']);
        }

        try {
            $data = $this->mapRepository->topology($providerId);
        } catch (\Throwable) {
            return Response::json(['ok' => false, 'message' => 'Não foi possível ler o mapa.'], 500, ['Cache-Control' => 'private, no-store']);
        }

        return Response::json(['ok' => true, 'data' => $data], 200, ['Cache-Control' => 'private, no-store']);
    }

    private const NODE_TYPES = ['pop', 'tower', 'olt', 'switch', 'radio', 'ceo', 'cto'];
    private const NODE_STATUSES = ['normal', 'alerta', 'critico', 'offline'];
    private const ATTRS_MAX_BYTES = 4096;

    public function createNode(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard instanceof Response) {
            return $guard;
        }
        [$providerId, $userId, $actor] = $guard;

        $errors = [];
        $fields = $this->validateNodeInput($request, $providerId, null, true, $errors);
        if ($errors !== []) {
            return $this->fail(implode(' ', $errors), 422);
        }

        $pdo = $this->mapRepository->pdo();
        $pdo->beginTransaction();
        try {
            $id = $this->mapRepository->createNode($providerId, $userId, $fields);
            $node = $this->mapRepository->findNode($providerId, $id);
            $this->audit($userId, $actor, 'map.node.create', $id, ['node' => $node]);
            $pdo->commit();
        } catch (\Throwable) {
            $pdo->rollBack();

            return $this->fail('Não foi possível salvar o elemento.', 500);
        }

        return Response::json(['ok' => true, 'data' => ['node' => $node]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function updateNode(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard instanceof Response) {
            return $guard;
        }
        [$providerId, $userId, $actor] = $guard;

        $id = $this->intOrNull($request->input('id'));
        $before = $id === null ? null : $this->mapRepository->findNode($providerId, $id);
        if ($before === null) {
            return $this->fail('Elemento não encontrado.', 404);
        }

        $errors = [];
        $fields = $this->validateNodeInput($request, $providerId, $id, false, $errors);
        if ($errors !== []) {
            return $this->fail(implode(' ', $errors), 422);
        }

        $pdo = $this->mapRepository->pdo();
        $pdo->beginTransaction();
        try {
            if ($fields !== []) {
                $this->mapRepository->updateNode($providerId, $id, $userId, $fields);
            }
            $after = $this->mapRepository->findNode($providerId, $id);
            $this->audit($userId, $actor, 'map.node.update', $id, ['before' => $before, 'after' => $after]);
            $pdo->commit();
        } catch (\Throwable) {
            $pdo->rollBack();

            return $this->fail('Não foi possível salvar o elemento.', 500);
        }

        return Response::json(['ok' => true, 'data' => ['node' => $after]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function deleteNode(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard instanceof Response) {
            return $guard;
        }
        [$providerId, $userId, $actor] = $guard;

        $id = $this->intOrNull($request->input('id'));
        $node = $id === null ? null : $this->mapRepository->findNode($providerId, $id);
        if ($node === null) {
            return $this->fail('Elemento não encontrado.', 404);
        }

        $blockers = $this->mapRepository->deleteBlockers($providerId, $id);
        if ($blockers['children'] !== [] || $blockers['links'] !== []) {
            return Response::json([
                'ok' => false,
                'message' => 'Não é possível excluir "' . $node['name'] . '": ' . count($blockers['children']) . ' elemento(s) filho(s) e '
                    . count($blockers['links']) . ' ligação(ões) dependem dele.',
                'blockers' => $blockers,
            ], 409, ['Cache-Control' => 'private, no-store']);
        }

        $pdo = $this->mapRepository->pdo();
        $pdo->beginTransaction();
        try {
            // O registro completo vai para a auditoria na mesma transação do DELETE.
            $this->audit($userId, $actor, 'map.node.delete', $id, ['node' => $node, 'ports' => $this->mapRepository->portsOfNode($providerId, $id)]);
            $this->mapRepository->deleteNode($providerId, $id);
            $pdo->commit();
        } catch (\Throwable) {
            $pdo->rollBack();

            return $this->fail('Não foi possível excluir o elemento.', 500);
        }

        return Response::json(['ok' => true, 'data' => ['id' => $id]], 200, ['Cache-Control' => 'private, no-store']);
    }

    /**
     * Gestor (403) e CSRF do escopo "map" (419). Devolve [provider_id, user_id|null, login] ou a resposta de erro.
     *
     * @return array{0: int, 1: ?int, 2: string}|Response
     */
    private function guardWrite(Request $request): array|Response
    {
        $user = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
        $access = $this->localRepository->accessProfileForUser($user);

        if (!AccessControl::isGestor($access)) {
            return $this->fail('Acesso negado.', 403);
        }
        if (!Csrf::verify($request, 'map')) {
            return $this->fail('Sessão do mapa expirada. Recarregue a página.', 419);
        }
        $providerId = $this->localRepository->currentProviderId();
        if ($providerId === null) {
            return $this->fail('Provedor não configurado.', 500);
        }

        // Usuários vindos do MkAuth não têm linha em app_users: a autoria fica NULL.
        $userId = (int) ($user['id'] ?? 0);
        try {
            $userId = $userId > 0 && $this->mapRepository->userExists($providerId, $userId) ? $userId : 0;
        } catch (\Throwable) {
            $userId = 0;
        }

        return [$providerId, $userId > 0 ? $userId : null, (string) ($user['login'] ?? '')];
    }

    /**
     * @param list<string> $errors
     * @return array<string, mixed> campos prontos para gravar (na edição, só os enviados)
     */
    private function validateNodeInput(Request $request, int $providerId, ?int $selfId, bool $creating, array &$errors): array
    {
        $fields = [];
        $has = static fn (string $key): bool => $request->input($key) !== null;

        if ($creating) {
            $type = trim((string) $request->input('node_type', ''));
            if (!in_array($type, self::NODE_TYPES, true)) {
                $errors[] = 'Tipo de elemento inválido.';
            }
            $fields['node_type'] = $type;
        } elseif ($has('node_type')) {
            $errors[] = 'O tipo do elemento não pode ser alterado.';
        }

        if ($creating || $has('name')) {
            $name = trim((string) $request->input('name', ''));
            if ($name === '' || mb_strlen($name) > 120) {
                $errors[] = 'Nome é obrigatório (até 120 caracteres).';
            }
            $fields['name'] = $name;
        }

        if ($creating || $has('code')) {
            $code = trim((string) $request->input('code', ''));
            if (mb_strlen($code) > 60) {
                $errors[] = 'Código deve ter até 60 caracteres.';
            }
            $fields['code'] = $code === '' ? null : $code;
        }

        foreach (['lat' => 90.0, 'lng' => 180.0] as $key => $limit) {
            if ($creating || $has($key)) {
                $raw = $request->input($key);
                if (!is_numeric($raw) || abs((float) $raw) > $limit) {
                    $errors[] = ($key === 'lat' ? 'Latitude' : 'Longitude') . ' inválida (entre -' . (int) $limit . ' e ' . (int) $limit . ').';
                } else {
                    $fields[$key] = round((float) $raw, 7);
                }
            }
        }

        if ($creating || $has('status')) {
            $status = trim((string) $request->input('status', 'normal'));
            if (!in_array($status, self::NODE_STATUSES, true)) {
                $errors[] = 'Status inválido.';
            }
            $fields['status'] = $status;
        }

        if ($creating || $has('notes')) {
            $notes = trim((string) $request->input('notes', ''));
            if (mb_strlen($notes) > 2000) {
                $errors[] = 'Observação deve ter até 2000 caracteres.';
            }
            $fields['notes'] = $notes === '' ? null : $notes;
        }

        if ($has('parent_node_id')) {
            $rawParent = trim((string) $request->input('parent_node_id'));
            if ($rawParent === '') {
                $fields['parent_node_id'] = null;
            } else {
                $parentId = $this->intOrNull($rawParent);
                if ($parentId === null || $parentId === $selfId) {
                    $errors[] = 'Elemento pai inválido.';
                } elseif ($this->mapRepository->findNode($providerId, $parentId) === null) {
                    $errors[] = 'Elemento pai não encontrado.';
                } elseif ($selfId !== null && in_array($parentId, $this->mapRepository->descendantIds($providerId, $selfId), true)) {
                    $errors[] = 'Elemento pai inválido (geraria um ciclo).';
                } else {
                    $fields['parent_node_id'] = $parentId;
                }
            }
        }

        if ($creating || $has('attrs')) {
            $attrs = $this->parseAttrs((string) $request->input('attrs', ''));
            if ($attrs === false) {
                $errors[] = 'Atributos inválidos (objeto JSON simples, só valores escalares, até 4 KB).';
            } else {
                $fields['attrs_json'] = $attrs;
            }
        }

        return $fields;
    }

    /**
     * Objeto JSON plano com valores escalares/nulos, até 4 KB. false se inválido; null se vazio.
     */
    private function parseAttrs(string $raw): string|false|null
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (strlen($raw) > self::ATTRS_MAX_BYTES) {
            return false;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return false;
        }
        foreach ($decoded as $key => $value) {
            if (!is_string($key) || $key === '' || strlen($key) > 60 || (!is_scalar($value) && $value !== null)) {
                return false;
            }
        }
        if ($decoded === []) {
            return null;
        }
        $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) && strlen($encoded) <= self::ATTRS_MAX_BYTES ? $encoded : false;
    }

    private function intOrNull(mixed $value): ?int
    {
        $text = trim((string) $value);

        return preg_match('/^[1-9][0-9]{0,18}$/', $text) === 1 ? (int) $text : null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function audit(?int $userId, string $actor, string $action, int $entityId, array $context): void
    {
        $this->localRepository->log(
            $userId,
            $actor,
            $action,
            'map_node',
            $entityId,
            $context,
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)
        );
    }

    private function fail(string $message, int $status): Response
    {
        return Response::json(['ok' => false, 'message' => $message], $status, ['Cache-Control' => 'private, no-store']);
    }

    public function show(Request $request): Response
    {
        $user = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
        $access = $this->localRepository->accessProfileForUser($user);

        if (!AccessControl::isGestor($access)) {
            Flash::set('error', 'Seu usuário não possui acesso ao mapa.');

            return Response::redirect('/dashboard');
        }

        $file = dirname(__DIR__, 2) . '/modules/isp_map/isp-network-map.html';
        $html = is_file($file) ? file_get_contents($file) : false;

        if (!is_string($html)) {
            return Response::html('Mapa indisponível.', 404);
        }

        $config = json_encode(
            ['csrf' => Csrf::token('map'), 'api' => Url::to('/api/mapa')],
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        $html = preg_replace('#</head>#i', '<script>window.ISP_MAP=' . $config . ';</script></head>', $html, 1) ?? $html;

        return Response::html($html, 200, ['Cache-Control' => 'private, no-store']);
    }
}
