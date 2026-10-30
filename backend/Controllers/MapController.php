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
    private const NODE_LIFECYCLES = ['projeto', 'ativo', 'desativado'];
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

    private const LINK_MEDIUMS = ['backbone' => 'fibra', 'distribuicao' => 'fibra', 'drop' => 'fibra', 'ethernet' => 'cabo', 'wireless' => 'radio'];

    public function createLink(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard instanceof Response) {
            return $guard;
        }
        [$providerId, $userId, $actor] = $guard;

        $errors = [];
        $fields = $this->validateLinkInput($request, $providerId, null, $errors);
        if ($errors !== []) {
            return $this->fail(implode(' ', $errors), 422);
        }

        $pdo = $this->mapRepository->pdo();
        $pdo->beginTransaction();
        try {
            if ($this->mapRepository->linkExists($providerId, $fields['from_node_id'], $fields['to_node_id'], $fields['link_type'])) {
                $pdo->rollBack();

                return $this->fail('Já existe uma ligação deste tipo entre esses dois elementos.', 409);
            }
            $id = $this->mapRepository->createLink($providerId, $userId, $fields);
            $link = $this->mapRepository->findLink($providerId, $id);
            $this->audit($userId, $actor, 'map.link.create', $id, ['link' => $link], 'map_link');
            $pdo->commit();
        } catch (\Throwable) {
            $pdo->rollBack();

            return $this->fail('Não foi possível salvar a ligação.', 500);
        }

        return Response::json(['ok' => true, 'data' => ['link' => $link]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function updateLink(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard instanceof Response) {
            return $guard;
        }
        [$providerId, $userId, $actor] = $guard;

        $id = $this->intOrNull($request->input('id'));
        $before = $id === null ? null : $this->mapRepository->findLink($providerId, $id);
        if ($before === null) {
            return $this->fail('Ligação não encontrada.', 404);
        }

        $errors = [];
        $fields = $this->validateLinkInput($request, $providerId, $before, $errors);
        if ($errors !== []) {
            return $this->fail(implode(' ', $errors), 422);
        }

        $pdo = $this->mapRepository->pdo();
        $pdo->beginTransaction();
        try {
            if ($fields !== []) {
                $this->mapRepository->updateLink($providerId, $id, $userId, $fields);
            }
            $after = $this->mapRepository->findLink($providerId, $id);
            $this->audit($userId, $actor, 'map.link.update', $id, ['before' => $before, 'after' => $after], 'map_link');
            $pdo->commit();
        } catch (\Throwable) {
            $pdo->rollBack();

            return $this->fail('Não foi possível salvar a ligação.', 500);
        }

        return Response::json(['ok' => true, 'data' => ['link' => $after]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function deleteLink(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard instanceof Response) {
            return $guard;
        }
        [$providerId, $userId, $actor] = $guard;

        $id = $this->intOrNull($request->input('id'));
        $link = $id === null ? null : $this->mapRepository->findLink($providerId, $id);
        if ($link === null) {
            return $this->fail('Ligação não encontrada.', 404);
        }

        $pdo = $this->mapRepository->pdo();
        $pdo->beginTransaction();
        try {
            // O registro completo vai para a auditoria na mesma transação do DELETE.
            $this->audit($userId, $actor, 'map.link.delete', $id, ['link' => $link], 'map_link');
            $this->mapRepository->deleteLink($providerId, $id);
            $pdo->commit();
        } catch (\Throwable) {
            $pdo->rollBack();

            return $this->fail('Não foi possível excluir a ligação.', 500);
        }

        return Response::json(['ok' => true, 'data' => ['id' => $id]], 200, ['Cache-Control' => 'private, no-store']);
    }

    /**
     * @param array<string, mixed>|null $existing ligação atual (edição) ou null (criação)
     * @param list<string> $errors
     * @return array<string, mixed> campos prontos para gravar (na edição, só os enviados)
     */
    private function validateLinkInput(Request $request, int $providerId, ?array $existing, array &$errors): array
    {
        $creating = $existing === null;
        $fields = [];
        $has = static fn (string $key): bool => $request->input($key) !== null;

        // medium nunca vem do cliente: é derivado do tipo.
        if ($creating) {
            $type = trim((string) $request->input('link_type', ''));
            if (!isset(self::LINK_MEDIUMS[$type])) {
                $errors[] = 'Tipo de ligação inválido.';
                $type = '';
            }
            $fields['link_type'] = $type;
            $fields['medium'] = self::LINK_MEDIUMS[$type] ?? '';

            $from = $this->intOrNull($request->input('from_node_id'));
            $to = $this->intOrNull($request->input('to_node_id'));
            if ($from === null || $to === null) {
                $errors[] = 'Origem e destino são obrigatórios.';
            } elseif ($from === $to) {
                $errors[] = 'Origem e destino devem ser elementos diferentes.';
            } elseif ($this->mapRepository->findNode($providerId, $from) === null || $this->mapRepository->findNode($providerId, $to) === null) {
                $errors[] = 'Origem ou destino não encontrado.';
            }
            $fields['from_node_id'] = $from;
            $fields['to_node_id'] = $to;
            $medium = $fields['medium'];
        } else {
            foreach (['link_type', 'from_node_id', 'to_node_id'] as $immutable) {
                if ($has($immutable) && trim((string) $request->input($immutable)) !== (string) $existing[$immutable]) {
                    $errors[] = 'Tipo, origem e destino não podem ser alterados; exclua e crie a ligação de novo.';
                    break;
                }
            }
            $medium = (string) $existing['medium'];
        }

        if ($creating || $has('status')) {
            $status = trim((string) $request->input('status', 'normal'));
            if (!in_array($status, self::NODE_STATUSES, true)) {
                $errors[] = 'Status inválido.';
            }
            $fields['status'] = $status;
        }

        if ($creating || $has('lifecycle')) {
            $lifecycle = trim((string) $request->input('lifecycle', 'ativo'));
            if (!in_array($lifecycle, self::NODE_LIFECYCLES, true)) {
                $errors[] = 'Situação inválida (projeto, ativo ou desativado).';
            }
            $fields['lifecycle'] = $lifecycle;
        }

        if ($creating || $has('label')) {
            $label = trim((string) $request->input('label', ''));
            if (mb_strlen($label) > 120) {
                $errors[] = 'Rótulo deve ter até 120 caracteres.';
            }
            $fields['label'] = $label === '' ? null : $label;
        }

        if ($has('length_m')) {
            $raw = trim((string) $request->input('length_m'));
            if ($raw === '') {
                $fields['length_m'] = null;
            } elseif (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 99999999.99) {
                $errors[] = 'Comprimento inválido (metros, maior ou igual a zero).';
            } else {
                $fields['length_m'] = round((float) $raw, 2);
            }
        }

        if ($has('fiber_count')) {
            $raw = trim((string) $request->input('fiber_count'));
            if ($raw === '') {
                $fields['fiber_count'] = null;
            } elseif ($medium !== 'fibra') {
                $errors[] = 'Número de fibras só se aplica a ligações de fibra.';
            } elseif (preg_match('/^[0-9]{1,3}$/', $raw) !== 1 || (int) $raw < 1 || (int) $raw > 288) {
                $errors[] = 'Número de fibras inválido (1 a 288).';
            } else {
                $fields['fiber_count'] = (int) $raw;
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

        if ($creating || $has('lifecycle')) {
            $lifecycle = trim((string) $request->input('lifecycle', 'ativo'));
            if (!in_array($lifecycle, self::NODE_LIFECYCLES, true)) {
                $errors[] = 'Situação inválida (projeto, ativo ou desativado).';
            }
            $fields['lifecycle'] = $lifecycle;
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
    private function audit(?int $userId, string $actor, string $action, int $entityId, array $context, string $entityType = 'map_node'): void
    {
        $this->localRepository->log(
            $userId,
            $actor,
            $action,
            $entityType,
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
