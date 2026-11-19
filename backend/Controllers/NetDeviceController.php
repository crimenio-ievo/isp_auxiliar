<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AccessControl;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\Map\MapRepository;
use App\Infrastructure\Net\DeviceProfile;
use App\Infrastructure\Net\NetConnectionTester;
use App\Infrastructure\Net\NetCredentialRepository;
use App\Infrastructure\Net\NetEquipmentRepository;
use App\Infrastructure\Net\NetDeviceRegistry;
use App\Infrastructure\Net\NetTransportFactory;
use App\Services\Net\MapNodeGateway;

/**
 * /rede-equipamentos: cadastro das OLTs da telemetria. So gestor/admin. O equipamento e sempre o no do mapa
 * (criado ou reaproveitado pelo MapNodeGateway); aqui se grava so a coleta (net_devices).
 * Coleta nasce DESATIVADA; ativar exige confirmacao; "Testar conexao" faz so GET de sysName/sysDescr/sysUpTime.
 * Escritas: CSRF do escopo "rede" (419), auditoria net.device.* em audit_logs.
 */
final class NetDeviceController
{
    private const NO_STORE = ['Cache-Control' => 'private, no-store'];
    public const CSRF_SCOPE = 'rede';

    public function __construct(
        private View $view,
        private Config $config,
        private LocalRepository $localRepository,
        private Database $database,
        private MapRepository $mapRepository,
        private MapNodeGateway $gateway,
        private NetConnectionTester $tester,
        private ?NetCredentialRepository $credentials = null
    ) {
    }

    public function page(Request $request): Response
    {
        $user = $this->viewUser();
        if (!AccessControl::isGestor($user['access'])) {
            Flash::set('error', 'Seu usuário não possui acesso aos equipamentos de rede.');

            return Response::redirect('/dashboard');
        }
        $html = $this->view->render('net/devices', [
            'pageTitle' => 'Equipamentos de rede',
            'currentPath' => $request->path(),
            'basePath' => $request->basePath(),
            'appName' => $this->config->get('app.name', 'ISP Auxiliar'),
            'user' => $user,
            'csrf' => Csrf::token(self::CSRF_SCOPE),
        ]);

        return Response::html($html, 200, self::NO_STORE);
    }

    public function list(Request $request): Response
    {
        $providerId = $this->guard($denied);
        if ($providerId === null) {
            return $denied;
        }
        try {
            $registry = $this->registry();
            $catalog = new NetEquipmentRepository($this->database->pdo());
            if ($catalog->available()) {
                $catalog->seedFacts($providerId);
            }

            return Response::json(['ok' => true, 'data' => [
                'equipamentos' => $registry->listOlts($providerId),
                'perfis' => [
                    'suportados' => $registry->supportedProfiles(),
                    'planejados' => array_map(static fn (string $code, string $label): array => ['code' => $code, 'label' => $label], array_keys(NetDeviceRegistry::PLANNED_PROFILES), array_values(NetDeviceRegistry::PLANNED_PROFILES)),
                ],
                'credenciais' => Env::names('NET_CRED_'),
                'credenciais_banco' => $this->credentials !== null && $this->credentials->available() ? array_map(static fn (array $c): array => ['id' => $c['id'], 'nome' => $c['nome'], 'tipo' => $c['tipo']], $this->credentials->list($providerId)) : [],
                'modelos' => $catalog->available() ? array_map(static fn (array $m): array => ['id' => $m['id'], 'rotulo' => $m['fabricante'] . ' ' . $m['modelo'], 'tipo' => $m['tipo'], 'portas' => $m['portas'], 'perfil_padrao' => $m['perfil_padrao']], array_values(array_filter($catalog->models($providerId), static fn (array $m): bool => $m['tipo'] === 'olt'))) : [],
                'sites' => $registry->sites($providerId),
                'padroes' => ['intervalo_status_s' => NetDeviceRegistry::STATUS_INTERVAL_DEFAULT, 'intervalo_potencia_s' => NetDeviceRegistry::POWER_INTERVAL_DEFAULT],
                'limites' => ['status_s' => NetDeviceRegistry::STATUS_INTERVAL_RANGE, 'potencia_s' => NetDeviceRegistry::POWER_INTERVAL_RANGE],
            ]], 200, self::NO_STORE);
        } catch (\Throwable) {
            return $this->fail('Não foi possível ler os equipamentos.', 500);
        }
    }

    /**
     * Cria ou edita. Campos: node_id (OLT existente) OU name+site_id (+ports_pon) para criar o no; fabricante, modelo,
     * mgmt_host, perfil (id numerico de perfil suportado ou codigo de perfil planejado), credential_ref, intervalo_status_s,
     * intervalo_potencia_s. device_id presente = edicao.
     */
    public function save(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard instanceof Response) {
            return $guard;
        }
        [$providerId, $userId, $actor] = $guard;
        $registry = $this->registry();
        $in = static fn (string $k): string => trim((string) $request->input($k, ''));
        $errors = [];

        // --- Validacao dos campos de coleta (sem tocar em nada) ---
        $profileRaw = $in('perfil');
        $planned = null;
        $profile = null;
        if (preg_match('/^[1-9][0-9]{0,9}$/', $profileRaw) === 1) {
            $profile = $registry->findProfile((int) $profileRaw);
            $supportedIds = array_column($registry->supportedProfiles(), 'id');
            if ($profile === null || !in_array((int) $profile['id'], $supportedIds, true)) {
                $errors[] = 'Perfil inválido ou inativo.';
                $profile = null;
            }
        } elseif (isset(NetDeviceRegistry::PLANNED_PROFILES[$profileRaw])) {
            $planned = $profileRaw;
        } else {
            $errors[] = 'Escolha o perfil do equipamento.';
        }

        $host = $in('mgmt_host');
        if ($planned === null) {
            if (!self::validHost($host)) {
                $errors[] = 'IP de gerência inválido (informe um IPv4 de equipamento, ex.: 192.168.0.10).';
            }
            $ref = $in('credential_ref');
            $credentialId = null;
            if (preg_match('/^id:([1-9][0-9]{0,14})$/', $ref, $cm) === 1) {
                // Credencial cifrada no banco (net_credentials), escolhida pelo id; o segredo nunca passa por aqui.
                $credentialId = (int) $cm[1];
                if ($this->credentials === null || !$this->credentials->available() || $this->credentials->find($providerId, $credentialId) === null) {
                    $errors[] = 'Credencial do banco não encontrada.';
                }
                $ref = '';
            } elseif (!NetTransportFactory::isValidCredentialRef($ref) || !in_array($ref, Env::names('NET_CRED_'), true)) {
                $errors[] = 'Referência de credencial inválida: escolha uma credencial da lista.';
            }
            $statusInterval = $this->interval($in('intervalo_status_s'), NetDeviceRegistry::STATUS_INTERVAL_DEFAULT, NetDeviceRegistry::STATUS_INTERVAL_RANGE, 'Intervalo de status', $errors);
            $powerInterval = $this->interval($in('intervalo_potencia_s'), NetDeviceRegistry::POWER_INTERVAL_DEFAULT, NetDeviceRegistry::POWER_INTERVAL_RANGE, 'Intervalo de potência', $errors);
        }
        $fabricante = $in('fabricante');
        $modelo = $in('modelo');
        foreach (['Fabricante' => $fabricante, 'Modelo' => $modelo] as $label => $value) {
            if (mb_strlen($value) > 80) {
                $errors[] = $label . ' deve ter até 80 caracteres.';
            }
        }

        $catalog = new NetEquipmentRepository($this->database->pdo());
        $modelId = $this->idOrNull($in('modelo_id'));
        if ($modelId !== null && (!$catalog->available() || $catalog->model($providerId, $modelId) === null)) {
            $errors[] = 'Modelo do catálogo não encontrado.';
        }
        if ($modelId !== null && $catalog->available()) {
            $mdl = $catalog->model($providerId, $modelId);
            if ($mdl !== null && $mdl['tipo'] !== 'olt') {
                $errors[] = 'O modelo escolhido não é de OLT.';
            }
        }
        $serie = $in('serie');
        $assetTag = $in('patrimonio');
        $macEq = $in('mac');
        if (mb_strlen($serie) > 80 || mb_strlen($assetTag) > 60 || mb_strlen($macEq) > 17) {
            $errors[] = 'Série, patrimônio ou MAC longo demais.';
        }

        // --- No do mapa: existente ou novo ---
        $deviceId = $this->idOrNull($in('device_id'));
        $nodeId = $this->idOrNull($in('node_id'));
        $existingDevice = $deviceId === null ? null : $registry->findDevice($providerId, $deviceId);
        if ($deviceId !== null && $existingDevice === null) {
            return $this->fail('Equipamento não encontrado.', 404);
        }
        if ($existingDevice !== null) {
            $nodeId = (int) $existingDevice['map_node_id'];
        }
        $node = $nodeId === null ? null : $this->mapRepository->findNode($providerId, $nodeId);
        $creatingNode = false;
        if ($nodeId === null && $in('node_mode') === 'new') {
            $creatingNode = true;
            if ($in('name') === '' || mb_strlen($in('name')) > 120) {
                $errors[] = 'Nome da OLT é obrigatório (até 120 caracteres).';
            }
            $siteId = $this->idOrNull($in('site_id'));
            $site = $siteId === null ? null : $this->mapRepository->findNode($providerId, $siteId);
            if ($site === null || !in_array($site['node_type'], ['pop', 'tower'], true)) {
                $errors[] = 'Escolha o site (POP ou torre) onde a OLT fica.';
            }
            if (preg_match('/^[0-9]{1,2}$/', $in('ports_pon')) !== 1 || (int) $in('ports_pon') < 1 || (int) $in('ports_pon') > 32) {
                $errors[] = 'Informe a quantidade de portas PON da OLT (1 a 32).';
            }
        } elseif ($node === null) {
            $errors[] = 'Escolha uma OLT do mapa ou crie uma nova.';
        } elseif ($node['node_type'] !== 'olt') {
            $errors[] = 'O nó escolhido não é uma OLT.';
        } elseif ($existingDevice === null && $registry->findDeviceByNode($providerId, (int) $node['id']) !== null) {
            $errors[] = 'Essa OLT já tem registro de coleta; edite o existente.';
        }
        if ($planned !== null && $existingDevice !== null) {
            $errors[] = 'Este equipamento já tem coleta; escolha um perfil suportado.';
        }
        if ($planned === null && ($host !== '') && $registry->hostInUse($providerId, $host, $existingDevice === null ? null : (int) $existingDevice['id'])) {
            $errors[] = 'Esse IP de gerência já está em outro equipamento.';
        }
        if ($planned === null && $node !== null && $existingDevice === null && $errors === []) {
            // Contrato de dados: port_num das PONs do mapa == pon_index da OLT (1..N).
            $nums = array_map(static fn (array $p): int => (int) $p['port_num'], $this->mapRepository->portsOfFamily($providerId, (int) $node['id'], 'pon'));
            if ($nums === [] || $nums !== range(1, count($nums))) {
                $errors[] = 'A OLT precisa ter portas PON numeradas de 1 a N no mapa (hoje: ' . ($nums === [] ? 'nenhuma' : implode(',', $nums)) . ').';
            }
        }
        if ($errors !== []) {
            return $this->fail(implode(' ', $errors), 422);
        }

        // --- Gravacao ---
        $nodeAttrs = static function (?array $current) use ($fabricante, $modelo, $planned, $modelId): ?string {
            $attrs = is_array($current) ? $current : [];
            if ($modelId !== null) {
                // Fabricante/modelo passam a ter uma unica origem (o catalogo): sai do texto livre do no.
                $fabricante = '';
                $modelo = '';
            }
            foreach (['fabricante' => $fabricante, 'modelo' => $modelo] as $k => $v) {
                $v === '' ? $attrs = array_diff_key($attrs, [$k => 1]) : $attrs[$k] = $v;
            }
            if ($planned !== null) {
                $attrs['perfil_pretendido'] = $planned;
            } else {
                unset($attrs['perfil_pretendido']);
            }

            return $attrs === [] ? null : json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        };

        $createdNodeId = null;
        if ($creatingNode) {
            $fields = [
                'node_type' => 'olt', 'name' => $in('name'), 'parent_node_id' => (string) $siteId,
                'attrs' => $nodeAttrs(null), 'ports_pon' => $in('ports_pon'),
            ];
            if ($in('ports_eth') !== '') {
                $fields['ports_eth'] = $in('ports_eth');
            }
            $created = $this->gateway->create($fields);
            if (!$created['ok'] || $created['node'] === null) {
                return $this->fail($created['message'] !== '' ? $created['message'] : 'Não foi possível criar a OLT no mapa.', $created['status'] >= 400 ? $created['status'] : 500);
            }
            $createdNodeId = (int) $created['node']['id'];
            $nodeId = $createdNodeId;
            $node = $created['node'];
        } elseif ($node !== null) {
            $newAttrs = $nodeAttrs(is_array($node['attrs_json'] ?? null) ? $node['attrs_json'] : null);
            $oldAttrs = $node['attrs_json'] === null ? null : json_encode($node['attrs_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($newAttrs !== $oldAttrs) {
                $updated = $this->gateway->update((int) $node['id'], ['attrs' => $newAttrs ?? '']);
                if (!$updated['ok']) {
                    return $this->fail($updated['message'] !== '' ? $updated['message'] : 'Não foi possível atualizar a OLT no mapa.', $updated['status'] >= 400 ? $updated['status'] : 500);
                }
            }
        }

        $warning = null;
        try {
            if ($planned !== null) {
                $this->audit($userId, $actor, 'net.device.plan', (int) $nodeId, ['map_node_id' => $nodeId, 'perfil_pretendido' => $planned], 'map_node');
                $message = 'OLT salva no mapa. Perfil ainda não suportado: coleta desativada.';
                $result = ['node_id' => $nodeId, 'device_id' => null];
            } elseif ($existingDevice === null) {
                $newId = $registry->createDevice($providerId, [
                    'map_node_id' => (int) $nodeId, 'profile_id' => (int) $profile['id'], 'mgmt_host' => $host, 'credential_ref' => $ref === '' ? null : $ref, 'credential_id' => $credentialId,
                    'status_interval_s' => $statusInterval, 'power_interval_s' => $powerInterval,
                ]);
                $this->audit($userId, $actor, 'net.device.create', $newId, ['map_node_id' => $nodeId, 'host' => $host, 'profile' => $profile['code'], 'credential_ref' => $ref === '' ? null : $ref, 'credential_id' => $credentialId, 'enabled' => false]);
                $message = 'Equipamento cadastrado com a coleta DESATIVADA.';
                $result = ['node_id' => $nodeId, 'device_id' => $newId];
            } else {
                $changes = ['profile_id' => (int) $profile['id'], 'mgmt_host' => $host, 'credential_ref' => $ref === '' ? null : $ref, 'credential_id' => $credentialId, 'status_interval_s' => $statusInterval, 'power_interval_s' => $powerInterval];
                $critical = (int) $existingDevice['profile_id'] !== (int) $profile['id'] || $existingDevice['mgmt_host'] !== $host
                    || (string) ($existingDevice['credential_ref'] ?? '') !== $ref || ($existingDevice['credential_id'] === null ? null : (int) $existingDevice['credential_id']) !== $credentialId;
                if ($critical && (int) $existingDevice['enabled'] === 1) {
                    $changes['enabled'] = 0;
                    $warning = 'IP, perfil ou credencial mudaram: a coleta foi DESATIVADA. Teste a conexão e reative.';
                }
                $registry->updateDevice($providerId, (int) $existingDevice['id'], $changes);
                $this->audit($userId, $actor, 'net.device.update', (int) $existingDevice['id'], [
                    'antes' => ['host' => $existingDevice['mgmt_host'], 'profile_id' => (int) $existingDevice['profile_id'], 'credential_ref' => $existingDevice['credential_ref'], 'credential_id' => $existingDevice['credential_id'], 'status_interval_s' => (int) $existingDevice['status_interval_s'], 'power_interval_s' => (int) $existingDevice['power_interval_s']],
                    'depois' => ['host' => $host, 'profile' => $profile['code'], 'credential_ref' => $ref === '' ? null : $ref, 'credential_id' => $credentialId, 'status_interval_s' => $statusInterval, 'power_interval_s' => $powerInterval],
                    'desativada_por_mudanca' => $warning !== null,
                ]);
                $message = 'Equipamento atualizado.' . ($warning !== null ? ' ' . $warning : '');
                $result = ['node_id' => $nodeId, 'device_id' => (int) $existingDevice['id']];
            }
            if ($modelId !== null && $nodeId !== null) {
                $this->attachEquipment($catalog, $providerId, (int) $nodeId, $modelId, $serie, $macEq, $assetTag, $actor);
            }
        } catch (\Throwable) {
            // Compensa o no criado nesta chamada para nao deixar uma OLT sem registro por erro nosso.
            if ($createdNodeId !== null) {
                $this->gateway->delete($createdNodeId);
            }

            return $this->fail('Não foi possível salvar o equipamento.', 500);
        }

        return Response::json(['ok' => true, 'message' => $message, 'data' => $result], 200, self::NO_STORE);
    }

    public function toggle(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard instanceof Response) {
            return $guard;
        }
        [$providerId, $userId, $actor] = $guard;
        $registry = $this->registry();
        $device = ($id = $this->idOrNull((string) $request->input('device_id', ''))) === null ? null : $registry->findDevice($providerId, $id);
        if ($device === null) {
            return $this->fail('Equipamento não encontrado.', 404);
        }
        $enable = (string) $request->input('ativar', '') === '1';
        if ($enable) {
            if ((string) $request->input('confirmar', '') !== '1') {
                return $this->fail('Confirme a ativação da coleta na tela.', 422);
            }
            $profile = $registry->findProfile((int) $device['profile_id']);
            try {
                if ($profile === null || $profile['status'] !== 'active') {
                    throw new \InvalidArgumentException('inativo');
                }
                DeviceProfile::fromJson((string) $profile['code'], (string) $profile['profile_json']);
            } catch (\Throwable) {
                return $this->fail('Perfil ainda não suportado: a coleta não pode ser ativada.', 422);
            }
            if ($device['credential_id'] !== null) {
                if ($this->credentials === null || !$this->credentials->box()->available() || $this->credentials->find($providerId, (int) $device['credential_id']) === null) {
                    return $this->fail('A credencial cadastrada não pôde ser usada: confira a chave NET_SECRET_KEY e a credencial.', 422);
                }
            } elseif (!in_array((string) $device['credential_ref'], Env::names('NET_CRED_'), true)) {
                return $this->fail('A credencial ' . $device['credential_ref'] . ' não está definida no .env deste servidor.', 422);
            }
        }
        $registry->updateDevice($providerId, (int) $device['id'], ['enabled' => $enable ? 1 : 0]);
        $this->audit($userId, $actor, $enable ? 'net.device.enable' : 'net.device.disable', (int) $device['id'], ['map_node_id' => (int) $device['map_node_id'], 'host' => $device['mgmt_host']]);

        return Response::json(['ok' => true, 'message' => $enable
            ? 'Coleta ATIVADA. O próximo ciclo do cron fará a primeira leitura; sem identidade de referência aprovada ela falha com "identity_mismatch" até rodar net_collect.php --device=' . $device['id'] . ' --kind=status --approve-identity.'
            : 'Coleta desativada.', 'data' => ['device_id' => (int) $device['id'], 'coleta_ativa' => $enable]], 200, self::NO_STORE);
    }

    public function test(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard instanceof Response) {
            return $guard;
        }
        [$providerId, $userId, $actor] = $guard;
        $registry = $this->registry();
        $device = ($id = $this->idOrNull((string) $request->input('device_id', ''))) === null ? null : $registry->findDevice($providerId, $id);
        if ($device === null) {
            return $this->fail('Equipamento não encontrado.', 404);
        }
        $left = $registry->testCooldownLeft($providerId, (int) $device['id']);
        if ($left > 0) {
            return Response::json(['ok' => false, 'message' => 'Aguarde ' . $left . ' s para testar este equipamento de novo (limite: 1 teste a cada ' . NetDeviceRegistry::TEST_COOLDOWN_SECONDS . ' s).', 'data' => ['aguarde_s' => $left]], 429, self::NO_STORE + ['Retry-After' => (string) $left]);
        }
        $result = $this->tester->test($device);
        if ($result['codigo'] !== 'coleta_em_andamento') {
            // Registra quem testou, quando e o resultado (sem credencial). Teste que nem chegou a rodar nao consome o limite.
            $this->audit($userId, $actor, 'net.device.test', (int) $device['id'], [
                'host' => $device['mgmt_host'], 'resultado' => $result['codigo'], 'ok' => $result['ok'], 'duracao_ms' => $result['duracao_ms'],
            ]);
        }

        return Response::json(['ok' => $result['ok'], 'message' => $result['mensagem'], 'data' => $result], 200, self::NO_STORE);
    }

    public static function validHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $first = (int) explode('.', $host)[0];

        return $first < 224; // sem multicast/broadcast
    }

    /**
     * @param list<string> $errors
     * @param array{0:int,1:int} $range
     */
    private function interval(string $raw, int $default, array $range, string $label, array &$errors): int
    {
        if ($raw === '') {
            return $default;
        }
        if (preg_match('/^[0-9]{1,6}$/', $raw) !== 1 || (int) $raw < $range[0] || (int) $raw > $range[1]) {
            $errors[] = $label . ' inválido (' . $range[0] . ' a ' . $range[1] . ' s).';

            return $default;
        }

        return (int) $raw;
    }

    private function registry(): NetDeviceRegistry
    {
        return new NetDeviceRegistry($this->database->pdo());
    }

    private function idOrNull(string $raw): ?int
    {
        return preg_match('/^[1-9][0-9]{0,14}$/', $raw) === 1 ? (int) $raw : null;
    }

    /** @return array<string,mixed> */
    private function viewUser(): array
    {
        $user = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
        $user['login'] = $this->localRepository->normalizeLogin((string) ($user['login'] ?? ''));
        $user['access'] = $this->localRepository->accessProfileForUser($user);

        return $user;
    }

    private function guard(?Response &$denied): ?int
    {
        $user = $this->viewUser();
        if (!AccessControl::isGestor($user['access'])) {
            $denied = $this->fail('Acesso negado.', 403);

            return null;
        }
        $providerId = $this->localRepository->currentProviderId();
        if ($providerId === null) {
            $denied = $this->fail('Provedor não configurado.', 500);

            return null;
        }

        return $providerId;
    }

    /** @return array{0:int,1:?int,2:string}|Response */
    private function guardWrite(Request $request): array|Response
    {
        $providerId = $this->guard($denied);
        if ($providerId === null) {
            return $denied;
        }
        if (!Csrf::verify($request, self::CSRF_SCOPE)) {
            return $this->fail('Sessão expirada. Recarregue a página.', 419);
        }
        $user = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
        $userId = (int) ($user['id'] ?? 0);
        try {
            $userId = $userId > 0 && $this->mapRepository->userExists($providerId, $userId) ? $userId : 0;
        } catch (\Throwable) {
            $userId = 0;
        }

        return [$providerId, $userId > 0 ? $userId : null, (string) ($user['login'] ?? '')];
    }

    /** Liga/atualiza o equipamento fisico (catalogo) do no da OLT: um ativo por no, modelo + serie/MAC/patrimonio opcionais. */
    private function attachEquipment(NetEquipmentRepository $catalog, int $providerId, int $nodeId, int $modelId, string $serial, string $mac, string $asset, string $actor): void
    {
        $existing = $catalog->equipmentOfNode($providerId, $nodeId);
        [$id, $errors] = $catalog->saveEquipment($providerId, $existing === null ? null : (int) $existing['id'], [
            'model_id' => $modelId, 'serial' => $serial, 'mac' => $mac, 'asset_tag' => $asset, 'status' => 'instalado',
            'location_kind' => 'no_mapa', 'location_node_id' => $nodeId, 'notes' => $existing['notes'] ?? null,
        ], $actor);
        if ($id === null) {
            throw new \RuntimeException(implode(' ', $errors));
        }
    }

    /** @param array<string,mixed> $context */
    private function audit(?int $userId, string $actor, string $action, int $entityId, array $context, string $entityType = 'net_device'): void
    {
        $this->localRepository->log($userId, $actor, $action, $entityType, $entityId, $context, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255));
    }

    private function fail(string $message, int $status): Response
    {
        return Response::json(['ok' => false, 'message' => $message], $status, self::NO_STORE);
    }
}
