<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

use PDO;

/**
 * Cadastro de equipamentos da telemetria (net_devices). O equipamento e SEMPRE um no do mapa (map_nodes);
 * aqui so ficam os dados de coleta (IP de gerencia, perfil, referencia de credencial, intervalos, ativacao).
 * Nao grava nos do mapa (isso e feito pelo MapNodeGateway) e nunca le o valor de credenciais.
 */
final class NetDeviceRegistry
{
    /** Familias de OLT que ainda nao tem perfil de coleta (so aparecem como "perfil ainda nao suportado"). */
    public const PLANNED_PROFILES = [
        'vsol' => 'V-SOL',
        'ha7308vx' => 'HA7308VX',
        'outro' => 'Outro modelo',
    ];

    public const STATUS_INTERVAL_DEFAULT = 600;
    public const POWER_INTERVAL_DEFAULT = 1800;
    public const STATUS_INTERVAL_RANGE = [300, 86400];
    public const POWER_INTERVAL_RANGE = [600, 86400];
    public const TEST_COOLDOWN_SECONDS = 30;

    private $clock;

    public function __construct(private PDO $pdo, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): string => date('Y-m-d H:i:s');
    }

    /**
     * Perfis ativos e validos (os unicos que a coleta sabe usar).
     *
     * @return list<array{id:int,code:string,vendor:string,technology:string,version:int}>
     */
    public function supportedProfiles(): array
    {
        $out = [];
        $rows = $this->pdo->query("SELECT id, code, vendor, technology, version, profile_json FROM net_device_profiles WHERE status = 'active' ORDER BY vendor, code")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            try {
                DeviceProfile::fromJson((string) $r['code'], (string) $r['profile_json']);
            } catch (\Throwable) {
                continue;
            }
            $out[] = ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'vendor' => (string) $r['vendor'], 'technology' => (string) $r['technology'], 'version' => (int) $r['version']];
        }

        return $out;
    }

    /**
     * Todas as OLTs do mapa do provedor, com ou sem registro de coleta.
     *
     * @return list<array<string,mixed>>
     */
    public function listOlts(int $providerId): array
    {
        $st = $this->pdo->prepare(
            'SELECT n.id AS node_id, n.name, n.attrs_json, n.parent_node_id, st.name AS site_name,
                    d.id AS device_id, d.mgmt_host, d.credential_ref, d.credential_id, cr.name AS credential_name, d.enabled,
                    em.id AS model_id, em.vendor AS model_vendor, em.model AS model_name, eq.serial AS eq_serial, eq.mac AS eq_mac, eq.asset_tag AS eq_asset, d.status_interval_s, d.power_interval_s, d.expected_identity_json,
                    d.profile_id, pr.code AS profile_code, pr.vendor AS profile_vendor, pr.status AS profile_status,
                    ps.last_attempt_at, ps.last_success_at, ps.last_result, ps.consecutive_failures
               FROM map_nodes n
               LEFT JOIN map_nodes st ON st.id = n.parent_node_id AND st.node_type IN (\'pop\', \'tower\')
               LEFT JOIN net_devices d ON d.map_node_id = n.id AND d.provider_id = n.provider_id
               LEFT JOIN net_credentials cr ON cr.id = d.credential_id
               LEFT JOIN net_equipment eq ON eq.id = n.equipment_id AND eq.provider_id = n.provider_id
               LEFT JOIN net_equipment_models em ON em.id = eq.model_id
               LEFT JOIN net_device_profiles pr ON pr.id = d.profile_id
               LEFT JOIN net_device_poll_state ps ON ps.device_id = d.id
              WHERE n.provider_id = ? AND n.node_type = \'olt\'
              ORDER BY st.name IS NULL, st.name, n.name, n.id'
        );
        $st->execute([$providerId]);
        $now = ($this->clock)();
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $attrs = json_decode((string) ($r['attrs_json'] ?? ''), true);
            $attrs = is_array($attrs) ? $attrs : [];
            $hasDevice = $r['device_id'] !== null;
            $planned = (string) ($attrs['perfil_pretendido'] ?? '');
            $out[] = [
                'node_id' => (int) $r['node_id'],
                'nome' => (string) $r['name'],
                'site' => $r['site_name'] === null ? null : (string) $r['site_name'],
                'site_id' => $r['parent_node_id'] === null ? null : (int) $r['parent_node_id'],
                // Uma origem: o catalogo (map_nodes.equipment_id -> net_equipment -> modelo). attrs_json so por compatibilidade.
                'fabricante' => $r['model_id'] !== null ? (string) $r['model_vendor'] : (string) ($attrs['fabricante'] ?? ''),
                'modelo' => $r['model_id'] !== null ? (string) $r['model_name'] : (string) ($attrs['modelo'] ?? ''),
                'modelo_id' => $r['model_id'] === null ? null : (int) $r['model_id'],
                'serie' => $r['eq_serial'], 'mac' => $r['eq_mac'], 'patrimonio' => $r['eq_asset'],
                'device_id' => $hasDevice ? (int) $r['device_id'] : null,
                'ip' => $hasDevice ? (string) $r['mgmt_host'] : null,
                'perfil' => $hasDevice ? ['id' => (int) $r['profile_id'], 'code' => (string) $r['profile_code'], 'status' => (string) $r['profile_status']] : null,
                'perfil_pretendido' => $planned === '' ? null : $planned,
                'credencial' => $hasDevice && $r['credential_id'] === null ? (string) $r['credential_ref'] : null,
                'credencial_id' => $hasDevice && $r['credential_id'] !== null ? (int) $r['credential_id'] : null,
                'credencial_nome' => $r['credential_name'],
                'coleta_ativa' => $hasDevice && (int) $r['enabled'] === 1,
                'intervalo_status_s' => $hasDevice ? (int) $r['status_interval_s'] : null,
                'intervalo_potencia_s' => $hasDevice ? (int) $r['power_interval_s'] : null,
                'identidade_aprovada' => $hasDevice && $r['expected_identity_json'] !== null,
                'ultima_tentativa' => $r['last_attempt_at'],
                'ultima_leitura_ok' => $r['last_success_at'],
                'ultimo_resultado' => $r['last_result'],
                'falhas_seguidas' => (int) ($r['consecutive_failures'] ?? 0),
                'saude' => $this->health($hasDevice, (int) ($r['enabled'] ?? 0) === 1, (int) ($r['status_interval_s'] ?? 600), $r['last_success_at'], $r['last_result'], $planned, $now),
            ];
        }

        return $out;
    }

    /**
     * @return array{estado:string,texto:string,minutos:?int}
     */
    public function health(bool $hasDevice, bool $enabled, int $interval, ?string $lastOk, ?string $lastResult, string $planned, string $now): array
    {
        if (!$hasDevice) {
            return ['estado' => 'sem_coleta', 'minutos' => null, 'texto' => $planned !== '' ? 'Perfil ainda não suportado: coleta desativada' : 'Sem registro de coleta'];
        }
        $age = $lastOk === null ? null : max(0, (int) strtotime($now . ' UTC') - (int) strtotime($lastOk . ' UTC'));
        $minutes = $age === null ? null : intdiv($age, 60);
        if (!$enabled) {
            return ['estado' => 'desativada', 'minutos' => $minutes, 'texto' => 'Coleta desativada'];
        }
        $limit = 2 * max(60, $interval);
        return match (true) {
            $age === null => ['estado' => 'sem_leitura', 'minutos' => null, 'texto' => 'Ainda não lida'],
            $lastResult !== null && $lastResult !== 'ok' => ['estado' => 'falha', 'minutos' => $minutes, 'texto' => 'Última tentativa falhou (' . $lastResult . ')'],
            $age > $limit => ['estado' => 'atrasada', 'minutos' => $minutes, 'texto' => 'Leitura atrasada'],
            default => ['estado' => 'ok', 'minutos' => $minutes, 'texto' => 'Leitura em dia'],
        };
    }

    /** @return list<array{id:int,nome:string,tipo:string}> POPs e torres (sites) do provedor. */
    public function sites(int $providerId): array
    {
        $st = $this->pdo->prepare("SELECT id, name, node_type FROM map_nodes WHERE provider_id = ? AND node_type IN ('pop','tower') ORDER BY name, id");
        $st->execute([$providerId]);

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'nome' => (string) $r['name'], 'tipo' => (string) $r['node_type']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function findDevice(int $providerId, int $deviceId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM net_devices WHERE id = ? AND provider_id = ?');
        $st->execute([$deviceId, $providerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findDeviceByNode(int $providerId, int $nodeId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM net_devices WHERE map_node_id = ? AND provider_id = ?');
        $st->execute([$nodeId, $providerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findProfile(int $profileId): ?array
    {
        $st = $this->pdo->prepare('SELECT id, code, status, profile_json FROM net_device_profiles WHERE id = ?');
        $st->execute([$profileId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** O IP ja esta em outro equipamento do provedor? (ignora $exceptDeviceId) */
    public function hostInUse(int $providerId, string $host, ?int $exceptDeviceId = null): bool
    {
        $st = $this->pdo->prepare('SELECT id FROM net_devices WHERE provider_id = ? AND mgmt_host = ? AND id <> ? LIMIT 1');
        $st->execute([$providerId, $host, $exceptDeviceId ?? 0]);

        return $st->fetchColumn() !== false;
    }

    /**
     * Cria o registro de coleta SEMPRE desativado.
     *
     * @param array{map_node_id:int,profile_id:int,mgmt_host:string,credential_ref?:?string,credential_id?:?int,status_interval_s:int,power_interval_s:int} $f
     */
    public function createDevice(int $providerId, array $f): int
    {
        $this->pdo->prepare(
            "INSERT INTO net_devices (provider_id, map_node_id, profile_id, mgmt_host, snmp_version, credential_ref, credential_id, expected_identity_json, enabled, status_interval_s, power_interval_s, created_at, updated_at)
             VALUES (?, ?, ?, ?, '2c', ?, ?, NULL, 0, ?, ?, NOW(), NOW())"
        )->execute([$providerId, $f['map_node_id'], $f['profile_id'], $f['mgmt_host'], $f['credential_ref'] ?? null, $f['credential_id'] ?? null, $f['status_interval_s'], $f['power_interval_s']]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Atualiza so as colunas de coleta enviadas (lista fixa).
     *
     * @param array<string,scalar> $fields
     */
    public function updateDevice(int $providerId, int $deviceId, array $fields): void
    {
        $sets = [];
        $params = [];
        foreach (['profile_id', 'mgmt_host', 'credential_ref', 'credential_id', 'status_interval_s', 'power_interval_s', 'enabled'] as $col) {
            if (array_key_exists($col, $fields)) {
                $sets[] = "{$col} = ?";
                $params[] = $fields[$col];
            }
        }
        if ($sets === []) {
            return;
        }
        $sets[] = 'updated_at = NOW()';
        $params[] = $deviceId;
        $params[] = $providerId;
        $this->pdo->prepare('UPDATE net_devices SET ' . implode(', ', $sets) . ' WHERE id = ? AND provider_id = ?')->execute($params);
    }

    /** Ultimo "testar conexao" do equipamento (audit_logs), para o limite de 1 teste a cada 30 s. */
    public function lastTestAt(int $providerId, int $deviceId): ?string
    {
        $st = $this->pdo->prepare("SELECT MAX(created_at) FROM audit_logs WHERE provider_id = ? AND action = 'net.device.test' AND entity_type = 'net_device' AND entity_id = ?");
        $st->execute([$providerId, $deviceId]);
        $v = $st->fetchColumn();

        return $v === false || $v === null ? null : (string) $v;
    }

    /** Segundos que faltam para liberar um novo teste (0 = liberado). */
    public function testCooldownLeft(int $providerId, int $deviceId): int
    {
        $last = $this->lastTestAt($providerId, $deviceId);
        if ($last === null) {
            return 0;
        }
        // created_at do audit_logs usa NOW() do MySQL; compara com o relogio do banco para nao depender do fuso do PHP.
        $st = $this->pdo->prepare('SELECT GREATEST(0, ? - TIMESTAMPDIFF(SECOND, ?, NOW()))');
        $st->execute([self::TEST_COOLDOWN_SECONDS, $last]);

        return (int) $st->fetchColumn();
    }
}
