<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AccessControl;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\Map\MapRepository;
use App\Infrastructure\Net\Coordinates;
use App\Infrastructure\Net\CtoCsvImporter;
use App\Infrastructure\Net\NetCtoRepository;
use App\Services\Net\MapNodeGateway;

/**
 * /rede-ctos: lista e cadastro rapido de CTOs. Gestor/admin leem e gravam; tecnico (perfil de busca de clientes) so le.
 * A CTO e sempre o no do mapa: criar/editar/excluir passa pelo MapNodeGateway (mesmas regras, portas por capacidade,
 * auditoria map.*). Escritas: CSRF do escopo "rede" (419) e auditoria net.cto.*.
 */
final class NetCtoController
{
    private const NO_STORE = ['Cache-Control' => 'private, no-store'];

    public function __construct(
        private View $view,
        private Config $config,
        private LocalRepository $localRepository,
        private Database $database,
        private MapRepository $mapRepository,
        private MapNodeGateway $gateway
    ) {
    }

    public function page(Request $request): Response
    {
        $user = $this->viewUser();
        if (!$this->canRead($user['access'])) {
            Flash::set('error', 'Seu usuário não possui acesso às CTOs.');

            return Response::redirect('/dashboard');
        }
        $focus = $request->query('cto');
        $html = $this->view->render('net/ctos', [
            'pageTitle' => 'CTOs',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $user,
            'csrf' => Csrf::token(NetDeviceController::CSRF_SCOPE),
            'canEdit' => AccessControl::isGestor($user['access']),
            'focusCto' => is_string($focus) && ctype_digit($focus) ? (int) $focus : null,
        ]);

        return Response::html($html, 200, self::NO_STORE);
    }

    public function list(Request $request): Response
    {
        $ctx = $this->guardRead($denied);
        if ($ctx === null) {
            return $denied;
        }
        [$providerId, $canEdit] = $ctx;
        try {
            $repo = $this->repo();
            $pdo = $this->database->pdo();
            $st = $pdo->prepare("SELECT id, name, node_type FROM map_nodes WHERE provider_id = ? AND node_type IN ('pop','tower') ORDER BY name, id");
            $st->execute([$providerId]);
            $sites = array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'nome' => (string) $r['name'], 'tipo' => (string) $r['node_type']], $st->fetchAll(\PDO::FETCH_ASSOC));
            $st = $pdo->prepare(
                "SELECT p.id, p.label, n.name AS olt, EXISTS(SELECT 1 FROM map_links l WHERE l.provider_id = p.provider_id AND (l.from_port_id = p.id OR l.to_port_id = p.id)) AS usada
                   FROM map_ports p JOIN map_nodes n ON n.id = p.node_id WHERE p.provider_id = ? AND p.port_role = 'pon' AND n.node_type = 'olt' ORDER BY n.name, p.port_num"
            );
            $st->execute([$providerId]);
            $pons = array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'rotulo' => $r['olt'] . ' · ' . $r['label'], 'livre' => (int) $r['usada'] === 0], $st->fetchAll(\PDO::FETCH_ASSOC));

            return Response::json(['ok' => true, 'data' => [
                'ctos' => $repo->list($providerId),
                'pode_editar' => $canEdit,
                'origens' => ['sites' => $sites, 'pons' => $pons],
                'vinculos_disponiveis' => $repo->clientPorts($providerId)['disponivel'],
                'limites' => ['capacidade' => [1, 144], 'proximidade_m' => NetCtoRepository::NEAR_METERS, 'csv_linhas' => CtoCsvImporter::MAX_ROWS],
            ]], 200, self::NO_STORE);
        } catch (\Throwable) {
            return $this->fail('Não foi possível ler as CTOs.', 500);
        }
    }

    /**
     * Cria (sem id) ou edita (com id). Campos: nome, coordenadas ("lat, lng") ou lat+lng, capacidade, observacao,
     * origem ("site:ID" | "pon:PORTA_ID"), confirmar_proximo=1.
     */
    public function save(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard instanceof Response) {
            return $guard;
        }
        [$providerId, $userId, $actor] = $guard;
        $repo = $this->repo();
        $in = static fn (string $k): string => trim((string) $request->input($k, ''));
        $errors = [];

        $id = preg_match('/^[1-9][0-9]{0,14}$/', $in('id')) === 1 ? (int) $in('id') : null;
        $current = null;
        if ($in('id') !== '') {
            $current = $id === null ? null : $this->mapRepository->findNode($providerId, $id);
            if ($current === null || $current['node_type'] !== 'cto') {
                return $this->fail('CTO não encontrada.', 404);
            }
        }

        $name = $in('nome');
        if ($name === '' || mb_strlen($name) > 120 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            $errors[] = 'Nome é obrigatório (até 120 caracteres).';
        } elseif ($repo->nameExists($providerId, $name, $id)) {
            $errors[] = 'Já existe uma CTO com o nome "' . $name . '".';
        }
        if ($in('coordenadas') !== '') {
            $pair = Coordinates::parsePair($in('coordenadas'));
        } else {
            $la = Coordinates::parseNumber($in('lat'), 90.0);
            $ln = Coordinates::parseNumber($in('lng'), 180.0);
            $pair = $la !== null && $ln !== null && Coordinates::validRange($la, $ln) ? [$la, $ln] : null;
        }
        if ($pair === null) {
            $errors[] = 'Coordenadas inválidas: cole "lat, lng" do Google Maps (lat entre -90 e 90, lng entre -180 e 180).';
        }
        $cap = $in('capacidade');
        if ($cap === '' && $current !== null) {
            $cap = (string) ($current['attrs_json']['capacity'] ?? '16');
        }
        if ($cap === '') {
            $cap = (string) CtoCsvImporter::DEFAULT_CAPACITY;
        }
        if (preg_match('/^[0-9]{1,3}$/', $cap) !== 1 || (int) $cap < 1 || (int) $cap > 144) {
            $errors[] = 'Capacidade inválida (1 a 144).';
        }
        $notes = $in('observacao');
        if (mb_strlen($notes) > 2000) {
            $errors[] = 'Observação deve ter até 2000 caracteres.';
        }

        // Origem opcional (so ao criar, ou numa CTO ainda sem nenhuma ligacao).
        $origin = null;
        $originRaw = $in('origem');
        if ($originRaw !== '') {
            $canLink = $current === null || $repo->deleteBlockers($providerId, (int) $current['id']) === [];
            if (!$canLink) {
                $errors[] = 'Esta CTO já tem ligações: altere a origem no mapa.';
            } elseif (preg_match('/^site:([1-9][0-9]{0,14})$/', $originRaw, $m) === 1) {
                $site = $this->mapRepository->findNode($providerId, (int) $m[1]);
                if ($site === null || !in_array($site['node_type'], ['pop', 'tower'], true)) {
                    $errors[] = 'Site de origem inválido.';
                } else {
                    $origin = ['kind' => 'site', 'node' => $site];
                }
            } elseif (preg_match('/^pon:([1-9][0-9]{0,14})$/', $originRaw, $m) === 1) {
                $port = $this->mapRepository->findPort($providerId, (int) $m[1]);
                $olt = $port === null ? null : $this->mapRepository->findNode($providerId, (int) $port['node_id']);
                if ($port === null || $port['port_role'] !== 'pon' || $olt === null || $olt['node_type'] !== 'olt') {
                    $errors[] = 'PON de origem inválida.';
                } elseif ($this->mapRepository->linkUsingPort($providerId, (int) $port['id']) !== null) {
                    $errors[] = 'A ' . $port['label'] . ' de ' . $olt['name'] . ' já está ligada a outro elemento.';
                } else {
                    $origin = ['kind' => 'pon', 'port' => $port, 'node' => $olt];
                }
            } else {
                $errors[] = 'Origem inválida.';
            }
        }
        if ($errors !== []) {
            return $this->fail(implode(' ', $errors), 422);
        }

        [$lat, $lng] = $pair;
        $near = $repo->near($providerId, $lat, $lng, NetCtoRepository::NEAR_METERS, $id);
        if ($near !== [] && (string) $request->input('confirmar_proximo', '') !== '1') {
            return Response::json([
                'ok' => false, 'codigo' => 'proximo', 'proximas' => $near,
                'message' => 'Já existe CTO a menos de ' . (int) NetCtoRepository::NEAR_METERS . ' m: ' . implode(', ', array_map(static fn (array $n): string => $n['nome'] . ' (' . number_format($n['metros'], 0, ',', '.') . ' m)', array_slice($near, 0, 3))) . '. Confirme para gravar mesmo assim.',
            ], 409, self::NO_STORE);
        }

        $attrs = is_array($current['attrs_json'] ?? null) ? $current['attrs_json'] : [];
        $attrs['capacity'] = (int) $cap;
        $fields = ['name' => $name, 'lat' => (string) $lat, 'lng' => (string) $lng, 'attrs' => json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'notes' => $notes];
        if ($current === null) {
            $res = $this->gateway->create(['node_type' => 'cto'] + $fields);
        } else {
            $res = $this->gateway->update((int) $current['id'], $fields);
        }
        if (!$res['ok'] || $res['node'] === null) {
            return $this->fail($res['message'] !== '' ? $res['message'] : 'Não foi possível salvar a CTO.', $res['status'] >= 400 ? $res['status'] : 500);
        }
        $nodeId = (int) $res['node']['id'];
        $this->audit($userId, $actor, $current === null ? 'net.cto.create' : 'net.cto.update', $nodeId, ['nome' => $name, 'lat' => $lat, 'lng' => $lng, 'capacidade' => (int) $cap, 'origem' => $originRaw === '' ? null : $originRaw, 'proxima_confirmada' => $near !== []]);

        $warning = null;
        if ($origin !== null) {
            $warning = $this->linkOrigin($providerId, $nodeId, $origin);
        }
        $message = $current === null ? 'CTO criada.' : 'CTO atualizada.';
        if ($warning !== null) {
            $message .= ' Atenção: ' . $warning;
        }

        return Response::json(['ok' => true, 'message' => $message, 'data' => ['id' => $nodeId, 'aviso' => $warning]], 200, self::NO_STORE);
    }

    public function delete(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard instanceof Response) {
            return $guard;
        }
        [$providerId, $userId, $actor] = $guard;
        $id = preg_match('/^[1-9][0-9]{0,14}$/', trim((string) $request->input('id', ''))) === 1 ? (int) $request->input('id') : null;
        $node = $id === null ? null : $this->mapRepository->findNode($providerId, $id);
        if ($node === null || $node['node_type'] !== 'cto') {
            return $this->fail('CTO não encontrada.', 404);
        }
        $reasons = $this->repo()->deleteBlockers($providerId, (int) $node['id']);
        if ($reasons !== []) {
            return Response::json(['ok' => false, 'message' => 'Não é possível excluir "' . $node['name'] . '": há ' . implode(', ', $reasons) . '. Remova-os antes.', 'motivos' => $reasons], 409, self::NO_STORE);
        }
        $res = $this->gateway->delete((int) $node['id']);
        if (!$res['ok']) {
            return $this->fail($res['message'] !== '' ? $res['message'] : 'Não foi possível excluir a CTO.', $res['status'] >= 400 ? $res['status'] : 500);
        }
        $this->audit($userId, $actor, 'net.cto.delete', (int) $node['id'], ['nome' => $node['name']]);

        return Response::json(['ok' => true, 'message' => 'CTO "' . $node['name'] . '" excluída.', 'data' => ['id' => (int) $node['id']]], 200, self::NO_STORE);
    }

    /**
     * Importacao por CSV em dois passos com o MESMO endpoint: sem "confirmar" devolve a previa (nada gravado);
     * com confirmar=1 reanalisa o arquivo e grava as linhas "ok" (+ as "proximo" listadas em "forcar") numa transacao unica.
     */
    public function import(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard instanceof Response) {
            return $guard;
        }
        [$providerId, $userId, $actor] = $guard;
        $repo = $this->repo();
        $existing = array_map(static fn (array $c): array => ['nome' => $c['nome'], 'lat' => $c['lat'], 'lng' => $c['lng']], $repo->list($providerId));
        $analysis = CtoCsvImporter::analyze((string) $request->input('csv', ''), $existing);
        if (!$analysis['ok']) {
            return $this->fail((string) $analysis['erro'], 422);
        }
        if ((string) $request->input('confirmar', '') !== '1') {
            return Response::json(['ok' => true, 'message' => 'Prévia: nada foi gravado.', 'data' => ['previa' => true] + $analysis], 200, self::NO_STORE);
        }

        $force = array_flip(array_filter(array_map('intval', explode(',', (string) $request->input('forcar', ''))), static fn (int $n): bool => $n > 0));
        $toImport = array_values(array_filter($analysis['linhas'], static fn (array $r): bool => $r['status'] === 'ok' || ($r['status'] === 'proximo' && isset($force[$r['linha']]))));
        if ($toImport === []) {
            return $this->fail('Nenhuma linha válida para importar.', 422);
        }

        $pdo = $this->database->pdo();
        $created = [];
        $pdo->beginTransaction();
        try {
            foreach ($toImport as $row) {
                $res = $this->gateway->create([
                    'node_type' => 'cto', 'name' => $row['nome'], 'lat' => (string) $row['lat'], 'lng' => (string) $row['lng'],
                    'attrs' => json_encode(['capacity' => $row['capacidade']]), 'notes' => $row['observacao'],
                ]);
                if (!$res['ok'] || $res['node'] === null) {
                    throw new \RuntimeException('Linha ' . $row['linha'] . ': ' . ($res['message'] !== '' ? $res['message'] : 'falha ao gravar.'));
                }
                $created[] = ['linha' => $row['linha'], 'id' => (int) $res['node']['id'], 'nome' => $row['nome']];
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $detail = $e instanceof \RuntimeException ? $e->getMessage() : 'erro interno';

            return $this->fail('Nada foi gravado (a importação é tudo ou nada). ' . $detail, 500);
        }
        $ignored = array_values(array_filter($analysis['linhas'], static fn (array $r): bool => !in_array($r['linha'], array_column($created, 'linha'), true)));
        $report = [
            'criadas' => $created,
            'ignoradas' => array_map(static fn (array $r): array => ['linha' => $r['linha'], 'nome' => $r['nome'], 'status' => $r['status'], 'motivo' => implode(' ', $r['mensagens'])], $ignored),
        ];
        $this->audit($userId, $actor, 'net.cto.import', 0, ['criadas' => count($created), 'ignoradas' => count($ignored), 'ids' => array_column($created, 'id')]);

        return Response::json(['ok' => true, 'message' => count($created) . ' CTO(s) importada(s); ' . count($ignored) . ' linha(s) ignorada(s).', 'data' => ['previa' => false, 'relatorio' => $report]], 200, self::NO_STORE);
    }

    /**
     * Liga a CTO a origem escolhida pelas regras do mapa. So avisa (nao desfaz a CTO) se a ligacao nao puder ser feita.
     *
     * @param array<string,mixed> $origin
     */
    private function linkOrigin(int $providerId, int $ctoId, array $origin): ?string
    {
        if ($origin['kind'] === 'site') {
            $link = $this->gateway->createLink(['link_type' => 'distribuicao', 'from_node_id' => $ctoId, 'to_node_id' => (int) $origin['node']['id'], 'status' => 'normal', 'lifecycle' => 'ativo']);

            return $link['ok'] ? null : 'a CTO foi salva, mas a ligação com o site não pôde ser criada (' . $link['message'] . ').';
        }
        $free = null;
        foreach ($this->mapRepository->portsOfFamily($providerId, $ctoId, 'fo') as $port) {
            if ($this->mapRepository->linkUsingPort($providerId, (int) $port['id']) === null && $port['status'] === 'livre') {
                $free = $port;
                break;
            }
        }
        if ($free === null) {
            return 'a CTO foi salva, mas não há porta livre para a ligação com a PON.';
        }
        $link = $this->gateway->createLink([
            'link_type' => 'backbone', 'from_node_id' => (int) $origin['node']['id'], 'to_node_id' => $ctoId,
            'from_port_id' => (int) $origin['port']['id'], 'to_port_id' => (int) $free['id'], 'status' => 'normal', 'lifecycle' => 'ativo',
        ]);

        return $link['ok'] ? null : 'a CTO foi salva, mas a ligação com a PON não pôde ser criada (' . $link['message'] . ').';
    }

    private function repo(): NetCtoRepository
    {
        return new NetCtoRepository($this->database->pdo());
    }

    /** @param array<string,mixed> $access */
    private function canRead(array $access): bool
    {
        return AccessControl::isGestor($access) || AccessControl::can($access, 'clients.view');
    }

    /** @return array<string,mixed> */
    private function viewUser(): array
    {
        $user = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
        $user['login'] = $this->localRepository->normalizeLogin((string) ($user['login'] ?? ''));
        $user['access'] = $this->localRepository->accessProfileForUser($user);

        return $user;
    }

    /** @return array{0:int,1:bool}|null */
    private function guardRead(?Response &$denied): ?array
    {
        $user = $this->viewUser();
        if (!$this->canRead($user['access'])) {
            $denied = $this->fail('Acesso negado.', 403);

            return null;
        }
        $providerId = $this->localRepository->currentProviderId();
        if ($providerId === null) {
            $denied = $this->fail('Provedor não configurado.', 500);

            return null;
        }

        return [$providerId, AccessControl::isGestor($user['access'])];
    }

    /** @return array{0:int,1:?int,2:string}|Response */
    private function guardWrite(Request $request): array|Response
    {
        $user = $this->viewUser();
        if (!AccessControl::isGestor($user['access'])) {
            return $this->fail('Acesso negado.', 403);
        }
        if (!Csrf::verify($request, NetDeviceController::CSRF_SCOPE)) {
            return $this->fail('Sessão expirada. Recarregue a página.', 419);
        }
        $providerId = $this->localRepository->currentProviderId();
        if ($providerId === null) {
            return $this->fail('Provedor não configurado.', 500);
        }
        $raw = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
        $userId = (int) ($raw['id'] ?? 0);
        try {
            $userId = $userId > 0 && $this->mapRepository->userExists($providerId, $userId) ? $userId : 0;
        } catch (\Throwable) {
            $userId = 0;
        }

        return [$providerId, $userId > 0 ? $userId : null, (string) ($raw['login'] ?? '')];
    }

    /** @param array<string,mixed> $context */
    private function audit(?int $userId, string $actor, string $action, int $entityId, array $context): void
    {
        $this->localRepository->log($userId, $actor, $action, 'map_node', $entityId === 0 ? null : $entityId, $context, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255));
    }

    private function fail(string $message, int $status): Response
    {
        return Response::json(['ok' => false, 'message' => $message], $status, self::NO_STORE);
    }
}
