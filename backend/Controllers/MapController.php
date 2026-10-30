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
            $data['defaults'] = $this->networkDefaults($providerId);
        } catch (\Throwable) {
            return Response::json(['ok' => false, 'message' => 'Não foi possível ler o mapa.'], 500, ['Cache-Control' => 'private, no-store']);
        }

        return Response::json(['ok' => true, 'data' => $data], 200, ['Cache-Control' => 'private, no-store']);
    }

    /*
     * Categorias de nó:
     * - contêiner (site): pop, tower — coordenada própria; tower pode ficar dentro de um pop.
     * - equipamento: fica OBRIGATORIAMENTE dentro de um contêiner e não tem coordenada (herda a do site).
     *   É também o ponto onde as portas da fase 1G vão se pendurar.
     * - campo: ceo, cto — coordenada própria, sem pai.
     */
    private const CONTAINER_TYPES = ['pop', 'tower'];
    private const EQUIPMENT_TYPES = ['olt', 'switch', 'router', 'radio', 'server', 'other'];
    private const FIELD_TYPES = ['ceo', 'cto'];
    private const NODE_TYPES = ['pop', 'tower', 'olt', 'switch', 'router', 'radio', 'server', 'other', 'ceo', 'cto'];
    private const NODE_STATUSES = ['normal', 'alerta', 'critico', 'offline'];
    private const NODE_LIFECYCLES = ['projeto', 'ativo', 'desativado'];
    private const ATTRS_MAX_BYTES = 4096;

    /*
     * Portas (fase 1G). port_role é a família/numeração; media é DERIVADA dela aqui no servidor e nunca vem do
     * cliente (nem é editável): eth → rj45, sfp → sfp, pon → pon, fo → fibra.
     * Padrão de is_external: pon = externa; fo de CTO = externa; eth/sfp/fo de equipamento = interna (editável depois).
     * Equipamento informa quantidades (ports_eth, ports_sfp, ports_pon, ports_fo); CTO gera "Porta n" (fo) pela
     * attrs.capacity. Aumentar acrescenta no fim; reduzir remove as de maior número, só se estiverem livres (sem
     * ligação) — senão 409 listando as ocupadas. "Ocupada" não é gravado: é a porta estar em alguma ligação.
     */
    private const PORT_MEDIA = ['eth' => 'rj45', 'sfp' => 'sfp', 'pon' => 'pon', 'fo' => 'fibra'];
    private const PORT_LABEL_PREFIX = ['eth' => 'ETH', 'sfp' => 'SFP', 'pon' => 'PON', 'fo' => 'FO'];
    private const PORT_STATUSES = ['livre', 'reservada', 'defeito'];
    private const PORT_MAX_PER_FAMILY = 128;

    /*
     * Potência por porta PON (fase 2B): attrs da porta guardam tx_dbm (−10 a +10, 2 casas) e max_clientes (1 a 256),
     * só em port_role 'pon' (o módulo pode variar por porta). A OLT guarda os padrões tx_padrao_dbm e
     * max_clientes_padrao (mesmas faixas), que só preenchem PONs sem valor próprio. Vazio apaga o valor.
     * Padrões da rede ficam em provider_settings (chave => valor padrão abaixo) e vão em GET /api/mapa data.defaults.
     */
    private const MAP_DEFAULTS = ['map.loss.fusion_db' => 0.1, 'map.loss.connector_db' => 0.5, 'map.atten.1490_db_km' => 0.30, 'map.onu.min_rx_dbm' => -27.0];
    private const CTO_MAX_CAPACITY = 144;

    public function createNode(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard instanceof Response) {
            return $guard;
        }
        [$providerId, $userId, $actor] = $guard;

        $errors = [];
        $fields = $this->validateNodeInput($request, $providerId, null, true, $errors);
        $portPlan = $this->validatePortPlan($request, (string) ($fields['node_type'] ?? ''), $fields, $errors);
        if ($errors !== []) {
            return $this->fail(implode(' ', $errors), 422);
        }

        $pdo = $this->mapRepository->pdo();
        $pdo->beginTransaction();
        try {
            $id = $this->mapRepository->createNode($providerId, $userId, $fields);
            $node = $this->mapRepository->findNode($providerId, $id);
            $this->audit($userId, $actor, 'map.node.create', $id, ['node' => $node]);
            $this->applyPortChanges($providerId, $userId, $actor, $node, $this->planPortChanges($providerId, $node, $portPlan));
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
        $portPlan = $this->validatePortPlan($request, (string) $before['node_type'], $fields, $errors);
        if ($errors !== []) {
            return $this->fail(implode(' ', $errors), 422);
        }
        $portChanges = $this->planPortChanges($providerId, $before, $portPlan);
        if ($portChanges['busy'] !== []) {
            return Response::json([
                'ok' => false,
                'message' => 'Não é possível reduzir as portas: ' . implode(', ', array_map(
                    static fn (array $b): string => $b['label'] . ' (ligação #' . $b['link_id'] . ')',
                    $portChanges['busy']
                )) . ' estão em uso. Remova as ligações antes.',
                'busy_ports' => $portChanges['busy'],
            ], 409, ['Cache-Control' => 'private, no-store']);
        }

        $pdo = $this->mapRepository->pdo();
        $pdo->beginTransaction();
        try {
            if ($fields !== []) {
                $this->mapRepository->updateNode($providerId, $id, $userId, $fields);
            }
            $after = $this->mapRepository->findNode($providerId, $id);
            $this->audit($userId, $actor, 'map.node.update', $id, ['before' => $before, 'after' => $after]);
            $this->applyPortChanges($providerId, $userId, $actor, $after, $portChanges);
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
     * @return array<string, float>
     */
    private function networkDefaults(int $providerId): array
    {
        $stored = $this->mapRepository->settings($providerId, array_keys(self::MAP_DEFAULTS));
        $out = [];
        foreach (self::MAP_DEFAULTS as $key => $default) {
            $out[$key] = isset($stored[$key]) && is_numeric($stored[$key]) ? (float) $stored[$key] : $default;
        }

        return $out;
    }

    /**
     * Valida campos de potência (tx_* em dBm; o resto é contagem de clientes) e devolve as mudanças a mesclar em
     * attrs: chave => valor, ou null para apagar (campo vazio). Só entram as chaves enviadas.
     *
     * @param callable(string): mixed $get
     * @param list<string> $names
     * @param list<string> $errors
     * @return array<string, int|float|null>
     */
    private function powerAttrChanges(callable $get, array $names, array &$errors): array
    {
        $changes = [];
        foreach ($names as $name) {
            $raw = $get($name);
            if ($raw === null) {
                continue;
            }
            $raw = trim(str_replace(',', '.', (string) $raw));
            $isTx = str_starts_with($name, 'tx');
            if ($raw === '') {
                $changes[$name] = null;
            } elseif ($isTx && (preg_match('/^[+-]?[0-9]{1,2}(\\.[0-9]{1,2})?$/', $raw) !== 1 || abs((float) $raw) > 10)) {
                $errors[] = 'Potência de saída inválida (−10 a +10 dBm, até 2 casas).';
            } elseif (!$isTx && (preg_match('/^[0-9]{1,3}$/', $raw) !== 1 || (int) $raw < 1 || (int) $raw > 256)) {
                $errors[] = 'Máximo de clientes inválido (inteiro de 1 a 256).';
            } else {
                $changes[$name] = $isTx ? round((float) $raw, 2) : (int) $raw;
            }
        }

        return $changes;
    }

    /**
     * @param array<string, mixed>|null $current
     * @param array<string, int|float|null> $changes
     */
    private function mergeAttrs(?array $current, array $changes): ?string
    {
        $attrs = $current ?? [];
        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($attrs[$key]);
            } else {
                $attrs[$key] = $value;
            }
        }

        return $attrs === [] ? null : json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function updatePort(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard instanceof Response) {
            return $guard;
        }
        [$providerId, $userId, $actor] = $guard;

        $id = $this->intOrNull($request->input('id'));
        $before = $id === null ? null : $this->mapRepository->findPort($providerId, $id);
        if ($before === null) {
            return $this->fail('Porta não encontrada.', 404);
        }

        // Só label, is_external e status: media deriva de port_role e é ignorada se vier do cliente.
        $errors = [];
        $fields = [];
        if ($request->input('label') !== null) {
            $label = trim((string) $request->input('label'));
            if ($label === '' || mb_strlen($label) > 60) {
                $errors[] = 'Rótulo da porta é obrigatório (até 60 caracteres).';
            }
            $fields['label'] = $label;
        }
        if ($request->input('is_external') !== null) {
            $raw = trim((string) $request->input('is_external'));
            if (!in_array($raw, ['0', '1'], true)) {
                $errors[] = 'Externa/interna inválido (0 ou 1).';
            }
            $fields['is_external'] = $raw === '1' ? 1 : 0;
        }
        if ($request->input('status') !== null) {
            $status = trim((string) $request->input('status'));
            if (!in_array($status, self::PORT_STATUSES, true)) {
                $errors[] = 'Status da porta inválido (livre, reservada ou defeito).';
            }
            $fields['status'] = $status;
        }
        $power = $this->powerAttrChanges(static fn (string $k): mixed => $request->input($k), ['tx_dbm', 'max_clientes'], $errors);
        if ($power !== [] && $before['port_role'] !== 'pon') {
            $errors[] = 'Potência de saída e máximo de clientes só existem em porta PON.';
        }
        if ($power !== [] && $errors === []) {
            $fields['attrs_json'] = $this->mergeAttrs(is_array($before['attrs_json']) ? $before['attrs_json'] : null, $power);
        }
        if ($errors !== []) {
            return $this->fail(implode(' ', $errors), 422);
        }

        // Porta em uso não pode virar interna se a ligação dela sai do site.
        if (($fields['is_external'] ?? null) === 0 && $before['is_external']) {
            $using = $this->mapRepository->linkUsingPort($providerId, (int) $before['id']);
            if ($using !== null && !$this->sameSite($providerId, (int) $using['from_node_id'], (int) $using['to_node_id'])) {
                return $this->fail('A porta está na ligação #' . $using['id'] . ', que sai do site: ela precisa continuar externa.', 409);
            }
        }

        $pdo = $this->mapRepository->pdo();
        $pdo->beginTransaction();
        try {
            if ($fields !== []) {
                $this->mapRepository->updatePort($providerId, $id, $userId, $fields);
            }
            $after = $this->mapRepository->findPort($providerId, $id);
            $this->audit($userId, $actor, 'map.port.update', $id, ['before' => $before, 'after' => $after], 'map_port');
            $pdo->commit();
        } catch (\Throwable) {
            $pdo->rollBack();

            return $this->fail('Não foi possível salvar a porta.', 500);
        }

        return Response::json(['ok' => true, 'data' => ['port' => $after]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function deletePort(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard instanceof Response) {
            return $guard;
        }
        [$providerId, $userId, $actor] = $guard;

        $id = $this->intOrNull($request->input('id'));
        $port = $id === null ? null : $this->mapRepository->findPort($providerId, $id);
        if ($port === null) {
            return $this->fail('Porta não encontrada.', 404);
        }
        $using = $this->mapRepository->linkUsingPort($providerId, $id);
        if ($using !== null) {
            return $this->fail('A porta ' . $port['label'] . ' está em uso na ligação #' . $using['id'] . '. Remova a ligação antes.', 409);
        }

        $pdo = $this->mapRepository->pdo();
        $pdo->beginTransaction();
        try {
            $this->audit($userId, $actor, 'map.port.delete', $id, ['port' => $port], 'map_port');
            $this->mapRepository->deletePort($providerId, $id);
            $pdo->commit();
        } catch (\Throwable) {
            $pdo->rollBack();

            return $this->fail('Não foi possível excluir a porta.', 500);
        }

        return Response::json(['ok' => true, 'data' => ['id' => $id]], 200, ['Cache-Control' => 'private, no-store']);
    }

    /**
     * Quantidade desejada por família de porta: equipamento via ports_eth/sfp/pon/fo; CTO via attrs.capacity.
     * Só as famílias enviadas entram no plano (o que não veio fica como está).
     *
     * @param array<string, mixed> $fields campos já validados do nó
     * @param list<string> $errors
     * @return array<string, int>
     */
    private function validatePortPlan(Request $request, string $type, array $fields, array &$errors): array
    {
        $plan = [];
        $sent = array_values(array_filter(array_keys(self::PORT_MEDIA), fn (string $f): bool => $request->input('ports_' . $f) !== null));

        if (in_array($type, self::EQUIPMENT_TYPES, true)) {
            foreach ($sent as $family) {
                $raw = trim((string) $request->input('ports_' . $family));
                $raw = $raw === '' ? '0' : $raw;
                if (preg_match('/^[0-9]{1,3}$/', $raw) !== 1 || (int) $raw > self::PORT_MAX_PER_FAMILY) {
                    $errors[] = 'Quantidade de portas ' . self::PORT_LABEL_PREFIX[$family] . ' inválida (0 a ' . self::PORT_MAX_PER_FAMILY . ').';
                    continue;
                }
                $plan[$family] = (int) $raw;
            }

            return $plan;
        }
        if ($sent !== []) {
            $errors[] = 'Só equipamentos têm quantidades de portas por tipo.';
        }

        if ($type === 'cto' && array_key_exists('attrs_json', $fields) && is_string($fields['attrs_json'])) {
            $attrs = json_decode($fields['attrs_json'], true);
            if (is_array($attrs) && array_key_exists('capacity', $attrs)) {
                $cap = $attrs['capacity'];
                if (!is_int($cap) && !(is_string($cap) && preg_match('/^[0-9]{1,3}$/', $cap) === 1)) {
                    $errors[] = 'Capacidade da CTO inválida (1 a ' . self::CTO_MAX_CAPACITY . ').';
                } elseif ((int) $cap < 1 || (int) $cap > self::CTO_MAX_CAPACITY) {
                    $errors[] = 'Capacidade da CTO inválida (1 a ' . self::CTO_MAX_CAPACITY . ').';
                } else {
                    $plan['fo'] = (int) $cap;
                }
            }
        }

        return $plan;
    }

    /**
     * Compara o plano com as portas existentes. busy = portas que teriam de sair mas estão em ligação.
     *
     * @param array<string, mixed> $node
     * @param array<string, int> $plan
     * @return array{add: list<array{role: string, num: int, label: string, media: string, external: bool}>, remove: list<array<string, mixed>>, busy: list<array<string, mixed>>}
     */
    private function planPortChanges(int $providerId, array $node, array $plan): array
    {
        $out = ['add' => [], 'remove' => [], 'busy' => []];
        $isCto = $node['node_type'] === 'cto';
        foreach ($plan as $family => $target) {
            $current = $this->mapRepository->portsOfFamily($providerId, (int) $node['id'], $family);
            $count = count($current);
            if ($target > $count) {
                $next = $current === [] ? 1 : max(array_column($current, 'port_num')) + 1;
                for ($i = 0; $i < $target - $count; $i++, $next++) {
                    $out['add'][] = [
                        'role' => $family,
                        'num' => $next,
                        'label' => ($isCto ? 'Porta' : self::PORT_LABEL_PREFIX[$family]) . ' ' . $next,
                        'media' => self::PORT_MEDIA[$family],
                        'external' => $family === 'pon' || $isCto,
                    ];
                }
            } elseif ($target < $count) {
                foreach (array_slice($current, $target) as $port) {
                    $using = $this->mapRepository->linkUsingPort($providerId, (int) $port['id']);
                    if ($using !== null) {
                        $out['busy'][] = ['id' => $port['id'], 'label' => $port['label'], 'link_id' => $using['id']];
                    }
                    $out['remove'][] = $port;
                }
            }
        }

        return $out;
    }

    /**
     * Aplica o plano (dentro da transação do nó) e audita como map.port.sync.
     *
     * @param array<string, mixed>|null $node
     * @param array{add: list<array<string, mixed>>, remove: list<array<string, mixed>>, busy: list<array<string, mixed>>} $changes
     */
    private function applyPortChanges(int $providerId, ?int $userId, string $actor, ?array $node, array $changes): void
    {
        if ($node === null || ($changes['add'] === [] && $changes['remove'] === [])) {
            return;
        }
        if ($changes['busy'] !== []) {
            throw new \RuntimeException('Portas ocupadas no plano de redução.');
        }
        $added = [];
        foreach ($changes['add'] as $p) {
            $added[] = $this->mapRepository->findPort($providerId, $this->mapRepository->createPort(
                $providerId, $userId, (int) $node['id'], $p['role'], $p['num'], $p['label'], $p['media'], $p['external']
            ));
        }
        foreach ($changes['remove'] as $p) {
            $this->mapRepository->deletePort($providerId, (int) $p['id']);
        }
        $this->audit($userId, $actor, 'map.port.sync', (int) $node['id'], ['added' => $added, 'removed' => $changes['remove']]);
    }

    /**
     * Site de um nó: o próprio contêiner, o contêiner pai de um equipamento, ou null (campo: CEO/CTO).
     *
     * @param array<string, mixed>|null $node
     */
    private function siteIdOf(?array $node): ?int
    {
        if ($node === null) {
            return null;
        }
        if (in_array($node['node_type'], self::CONTAINER_TYPES, true)) {
            return (int) $node['id'];
        }

        return in_array($node['node_type'], self::EQUIPMENT_TYPES, true) && $node['parent_node_id'] !== null ? (int) $node['parent_node_id'] : null;
    }

    /**
     * Distância em linha reta (haversine, metros) entre dois nós; equipamento usa a coordenada do site.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function straightMeters(int $providerId, array $a, array $b): ?float
    {
        $coord = function (array $n) use ($providerId): ?array {
            for ($guard = 0; $n !== null && $guard < 5; $guard++) {
                if ($n['lat'] !== null && $n['lng'] !== null) {
                    return [(float) $n['lat'], (float) $n['lng']];
                }
                $n = $n['parent_node_id'] === null ? null : $this->mapRepository->findNode($providerId, (int) $n['parent_node_id']);
            }

            return null;
        };
        $p = $coord($a);
        $q = $coord($b);
        if ($p === null || $q === null) {
            return null;
        }
        $rad = M_PI / 180;
        $h = sin(($q[0] - $p[0]) * $rad / 2) ** 2 + cos($p[0] * $rad) * cos($q[0] * $rad) * sin(($q[1] - $p[1]) * $rad / 2) ** 2;

        return 2 * 6371000 * asin(min(1.0, sqrt($h)));
    }

    private function sameSite(int $providerId, int $nodeA, int $nodeB): bool
    {
        $a = $this->siteIdOf($this->mapRepository->findNode($providerId, $nodeA));

        return $a !== null && $a === $this->siteIdOf($this->mapRepository->findNode($providerId, $nodeB));
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
        $conflicts = [];
        $warnings = [];
        $fields = $this->validateLinkInput($request, $providerId, null, $errors, $conflicts, $warnings);
        if ($errors !== []) {
            return $this->fail(implode(' ', $errors), 422);
        }
        if ($conflicts !== []) {
            return $this->fail(implode(' ', $conflicts), 409);
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

        return Response::json(['ok' => true, 'data' => ['link' => $link, 'warnings' => $warnings]], 200, ['Cache-Control' => 'private, no-store']);
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
        $conflicts = [];
        $warnings = [];
        $fields = $this->validateLinkInput($request, $providerId, $before, $errors, $conflicts, $warnings);
        if ($errors !== []) {
            return $this->fail(implode(' ', $errors), 422);
        }
        if ($conflicts !== []) {
            return $this->fail(implode(' ', $conflicts), 409);
        }

        $pdo = $this->mapRepository->pdo();
        $pdo->beginTransaction();
        try {
            $endsChanged = $fields['from_node_id'] !== $before['from_node_id'] || $fields['to_node_id'] !== $before['to_node_id'];
            if ($endsChanged && $this->mapRepository->linkExists($providerId, $fields['from_node_id'], $fields['to_node_id'], (string) $before['link_type'], $id)) {
                $pdo->rollBack();

                return $this->fail('Já existe uma ligação deste tipo entre esses dois elementos.', 409);
            }
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

        return Response::json(['ok' => true, 'data' => ['link' => $after, 'warnings' => $warnings]], 200, ['Cache-Control' => 'private, no-store']);
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

    /*
     * Ligação por porta (fase 1G). Regras, nesta ordem (422 salvo indicação):
     * - a porta pertence ao nó daquela ponta (e ao mesmo provedor);
     * - status 'defeito' não entra em ligação; 'reservada' entra, com aviso;
     * - ligação entre sites diferentes (ou site↔campo) só usa porta EXTERNA; porta interna só liga dentro do mesmo site;
     * - mídia × meio: PON e fibra só em ligação de fibra; RJ45 só em ethernet (cabo); SFP em fibra ou ethernet;
     *   ligação sem fio (radio) não usa porta;
     * - uma porta só pode estar em UMA ligação (409, indicando qual);
     * - ethernet com distância em linha reta > 100 m grava, mas devolve aviso em warnings[].
     * Na edição, cada ponta só pode ser trocada por outro nó do MESMO site da ponta atual (ex.: POP → OLT daquele POP);
     * inverter o sentido (from↔to, com as portas) é permitido. Trocar de site continua proibido.
     */
    private const PORT_MEDIA_BY_MEDIUM = ['fibra' => ['pon', 'fibra', 'sfp'], 'cabo' => ['rj45', 'sfp'], 'radio' => []];
    private const ETHERNET_MAX_M = 100.0;

    /**
     * @param array<string, mixed>|null $existing ligação atual (edição) ou null (criação)
     * @param list<string> $errors 422
     * @param list<string> $conflicts 409
     * @param list<string> $warnings gravam, mas o cliente mostra o aviso
     * @return array<string, mixed> campos prontos para gravar (na edição, pontas e portas sempre presentes)
     */
    private function validateLinkInput(Request $request, int $providerId, ?array $existing, array &$errors, array &$conflicts, array &$warnings): array
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
            $medium = $fields['medium'];
        } else {
            $type = (string) $existing['link_type'];
            if ($has('link_type') && trim((string) $request->input('link_type')) !== $type) {
                $errors[] = 'O tipo da ligação não pode ser alterado; exclua e crie a ligação de novo.';
            }
            $from = $has('from_node_id') ? $this->intOrNull($request->input('from_node_id')) : (int) $existing['from_node_id'];
            $to = $has('to_node_id') ? $this->intOrNull($request->input('to_node_id')) : (int) $existing['to_node_id'];
            $medium = (string) $existing['medium'];
        }

        $fromNode = $from === null ? null : $this->mapRepository->findNode($providerId, $from);
        $toNode = $to === null ? null : $this->mapRepository->findNode($providerId, $to);
        if ($from === null || $to === null) {
            $errors[] = 'Origem e destino são obrigatórios.';
        } elseif ($from === $to) {
            $errors[] = 'Origem e destino devem ser elementos diferentes.';
        } elseif ($fromNode === null || $toNode === null) {
            $errors[] = 'Origem ou destino não encontrado.';
        } elseif (!$creating && ($from !== $existing['from_node_id'] || $to !== $existing['to_node_id'])) {
            $endOk = fn (int $old, array $new): bool => $old === (int) $new['id']
                || (($site = $this->siteIdOf($this->mapRepository->findNode($providerId, $old))) !== null && $site === $this->siteIdOf($new));
            $straight = $endOk((int) $existing['from_node_id'], $fromNode) && $endOk((int) $existing['to_node_id'], $toNode);
            $inverted = $endOk((int) $existing['to_node_id'], $fromNode) && $endOk((int) $existing['from_node_id'], $toNode);
            if (!$straight && !$inverted) {
                $errors[] = 'Só é possível trocar a ponta por outro elemento do mesmo site (ou inverter o sentido); mudar de site não é permitido.';
            }
        }
        $fields['from_node_id'] = $from;
        $fields['to_node_id'] = $to;

        // Portas: '' = sem porta. Na edição, porta não enviada acompanha o nó se ele continuar numa das pontas (ex.: inverter).
        $existingPorts = $creating ? [] : array_filter([(int) $existing['from_node_id'] => $existing['from_port_id'], (int) $existing['to_node_id'] => $existing['to_port_id']]);
        $internal = $fromNode !== null && $toNode !== null && $this->sameSite($providerId, (int) $fromNode['id'], (int) $toNode['id']);
        foreach (['from' => $fromNode, 'to' => $toNode] as $end => $endNode) {
            $key = $end . '_port_id';
            if ($has($key)) {
                $raw = trim((string) $request->input($key));
                $portId = $raw === '' ? null : $this->intOrNull($raw);
                if ($raw !== '' && $portId === null) {
                    $errors[] = 'Porta inválida.';
                }
            } else {
                $portId = $endNode === null ? null : ($existingPorts[(int) $endNode['id']] ?? null);
            }
            $fields[$key] = $portId;
            if ($portId === null || $endNode === null) {
                continue;
            }

            $port = $this->mapRepository->findPort($providerId, $portId);
            $name = $port === null ? '' : $port['label'] . ' de ' . $endNode['name'];
            if ($port === null || $port['node_id'] !== (int) $endNode['id']) {
                $errors[] = 'A porta escolhida não pertence a "' . $endNode['name'] . '".';
                continue;
            }
            // Porta que já estava nesta ligação: status alterado depois não impede editar o resto do trecho.
            $kept = !$creating && in_array($portId, [$existing['from_port_id'], $existing['to_port_id']], true);
            if (!$kept && $port['status'] === 'defeito') {
                $errors[] = $name . ' está com defeito e não pode entrar em ligação.';
            } elseif (!$kept && $port['status'] === 'reservada') {
                $warnings[] = $name . ' está reservada.';
            }
            if (!$internal && !$port['is_external']) {
                $errors[] = $name . ' é interna: só liga dentro do mesmo site.';
            }
            if (!in_array((string) $port['media'], self::PORT_MEDIA_BY_MEDIUM[$medium] ?? [], true)) {
                $errors[] = $medium === 'radio'
                    ? 'Ligação sem fio não usa porta.'
                    : $name . ' (' . strtoupper((string) $port['media']) . ') não serve para ligação ' . ($medium === 'cabo' ? 'ethernet' : 'de fibra') . '.';
            }
            $using = $this->mapRepository->linkUsingPort($providerId, $portId, $creating ? null : (int) $existing['id']);
            if ($using !== null) {
                $conflicts[] = $name . ' já está em uso na ligação #' . $using['id'] . ($using['label'] ? ' (' . $using['label'] . ')' : '') . '.';
            }
        }

        if ($type === 'ethernet' && $fromNode !== null && $toNode !== null) {
            $meters = $this->straightMeters($providerId, $fromNode, $toNode);
            if ($meters !== null && $meters > self::ETHERNET_MAX_M) {
                $warnings[] = 'Ethernet com ' . number_format($meters, 0, ',', '.') . ' m em linha reta: acima do limite de 100 m do cabo.';
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

        $existing = $selfId === null ? null : $this->mapRepository->findNode($providerId, $selfId);
        if ($creating) {
            $type = trim((string) $request->input('node_type', ''));
            if (!in_array($type, self::NODE_TYPES, true)) {
                $errors[] = 'Tipo de elemento inválido.';
            }
            $fields['node_type'] = $type;
        } else {
            $type = (string) ($existing['node_type'] ?? '');
            if ($has('node_type') && trim((string) $request->input('node_type')) !== $type) {
                $errors[] = 'O tipo do elemento não pode ser alterado.';
            }
        }
        $isEquipment = in_array($type, self::EQUIPMENT_TYPES, true);

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

        if ($isEquipment) {
            // Equipamento não tem posição própria: usa a do contêiner.
            if ($creating || $has('lat') || $has('lng') || ($existing !== null && ($existing['lat'] !== null || $existing['lng'] !== null))) {
                $fields['lat'] = null;
                $fields['lng'] = null;
            }
        }
        foreach ($isEquipment ? [] : ['lat' => 90.0, 'lng' => 180.0] as $key => $limit) {
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

        if ($creating || $has('parent_node_id')) {
            $rawParent = trim((string) $request->input('parent_node_id', ''));
            $parent = null;
            if ($rawParent !== '') {
                $parentId = $this->intOrNull($rawParent);
                $parent = $parentId === null ? null : $this->mapRepository->findNode($providerId, $parentId);
                if ($parent === null) {
                    $errors[] = 'Elemento pai não encontrado.';
                } elseif ($parentId === $selfId) {
                    $errors[] = 'Um elemento não pode estar dentro dele mesmo.';
                } elseif ($selfId !== null && in_array($parentId, $this->mapRepository->descendantIds($providerId, $selfId), true)) {
                    $errors[] = 'Elemento pai inválido (geraria um ciclo).';
                }
            }
            $parentType = (string) ($parent['node_type'] ?? '');

            if ($isEquipment) {
                if ($rawParent === '') {
                    $errors[] = 'Equipamento precisa estar dentro de um POP ou torre.';
                } elseif ($parent !== null && !in_array($parentType, self::CONTAINER_TYPES, true)) {
                    $errors[] = 'Equipamento só pode ficar dentro de um POP ou torre.';
                }
            } elseif ($type === 'tower') {
                if ($parent !== null && $parentType !== 'pop') {
                    $errors[] = 'Torre só pode ficar dentro de um POP.';
                }
            } elseif ($rawParent !== '' && $type !== '') {
                $errors[] = ($type === 'pop' ? 'POP' : strtoupper($type)) . ' não pode ficar dentro de outro elemento.';
            }
            $fields['parent_node_id'] = $parent === null ? null : (int) $parent['id'];
        }

        if ($creating || $has('attrs')) {
            $attrs = $this->parseAttrs((string) $request->input('attrs', ''));
            if ($attrs === false) {
                $errors[] = 'Atributos inválidos (objeto JSON simples, só valores escalares, até 4 KB).';
            } else {
                $fields['attrs_json'] = $attrs;
                $decoded = is_string($attrs) ? json_decode($attrs, true) : null;
                $hasDefaults = is_array($decoded) && (array_key_exists('tx_padrao_dbm', $decoded) || array_key_exists('max_clientes_padrao', $decoded));
                if ($hasDefaults && $type !== 'olt') {
                    $errors[] = 'Padrões de Tx e máximo de clientes só existem na OLT.';
                } elseif ($hasDefaults) {
                    // padrões da OLT: mesma faixa das portas; vazio apaga a chave
                    $norm = $this->powerAttrChanges(
                        static fn (string $k): mixed => array_key_exists($k, $decoded) ? $decoded[$k] : null,
                        ['tx_padrao_dbm', 'max_clientes_padrao'],
                        $errors
                    );
                    $fields['attrs_json'] = $this->mergeAttrs($decoded, $norm);
                }
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

    public function diagramScript(Request $request): Response
    {
        $user = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
        $access = $this->localRepository->accessProfileForUser($user);

        if (!AccessControl::isGestor($access)) {
            Flash::set('error', 'Seu usuário não possui acesso ao mapa.');

            return new Response('', 302, [
                'Location' => Url::to('/dashboard'),
                'Cache-Control' => 'private, no-store',
            ]);
        }

        $file = dirname(__DIR__, 2) . '/modules/isp_map/diagram.js';
        $script = is_file($file) ? file_get_contents($file) : false;
        $headers = [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ];

        return is_string($script)
            ? new Response($script, 200, $headers)
            : new Response('', 404, $headers);
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
        $html = str_replace('src="/mapa/diagram.js"', 'src="' . htmlspecialchars(Url::to('/mapa/diagram.js'), ENT_QUOTES, 'UTF-8') . '"', $html);

        return Response::html($html, 200, ['Cache-Control' => 'private, no-store']);
    }
}
