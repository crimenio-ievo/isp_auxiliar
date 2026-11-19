<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

use PDO;

/**
 * Catalogo de MODELOS (net_equipment_models) e EQUIPAMENTO FISICO (net_equipment). Fabricante/modelo tem uma unica origem:
 * o catalogo. map_nodes.equipment_id liga um no do mapa ao seu ativo (um ativo por no). So validacao e SQL: sem regra de tela.
 */
final class NetEquipmentRepository
{
    public const TYPES = ['olt', 'onu', 'ont', 'switch', 'roteador', 'radio', 'outro'];
    public const STATUSES = ['estoque', 'instalado', 'manutencao', 'baixado'];
    public const LOCATIONS = ['no_mapa', 'estoque', 'cliente', 'outro'];
    public const PORT_FAMILIES = ['pon', 'sfp', 'eth', 'fo'];

    public function __construct(private PDO $pdo)
    {
    }

    public function available(): bool
    {
        try {
            $this->pdo->query('SELECT id FROM net_equipment_models LIMIT 0');
            $this->pdo->query('SELECT id FROM net_equipment LIMIT 0');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Pre-cadastro de FATOS conhecidos (mesmos da migration 047), para provedores criados depois dela. Idempotente.
     * So roda quando o provedor ainda nao tem nenhum modelo.
     */
    public function seedFacts(int $providerId): void
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM net_equipment_models WHERE provider_id = ?');
        $st->execute([$providerId]);
        if ((int) $st->fetchColumn() > 0) {
            return;
        }
        $ins = $this->pdo->prepare('INSERT IGNORE INTO net_equipment_models (provider_id, vendor, model, equipment_type, technology, ports_json, default_profile_code, notes, created_at, updated_at) VALUES (?, ?, ?, \'olt\', ?, ?, ?, ?, NOW(), NOW())');
        foreach ([
            ['Hioso', 'HA7004S', 'EPON', '{"pon":4}', 'hioso-epon-25355-4-4-v3', 'Perfil de telemetria ativo.'],
            ['Hioso', 'HA7308VX', 'EPON', '{"pon":8}', 'ha7308vx', 'Perfil de telemetria ainda nao suportado.'],
            ['V-SOL', 'a identificar', null, null, 'vsol', 'Modelo ainda nao identificado. Perfil de telemetria ainda nao suportado.'],
        ] as [$vendor, $model, $tech, $ports, $profile, $note]) {
            $ins->execute([$providerId, $vendor, $model, $tech, $ports, $profile, $note]);
        }
    }

    // ------------------------------------------------------------------ modelos

    /** @return list<array<string,mixed>> */
    public function models(int $providerId): array
    {
        $st = $this->pdo->prepare(
            'SELECT m.*, (SELECT COUNT(*) FROM net_equipment e WHERE e.model_id = m.id) AS em_uso
               FROM net_equipment_models m WHERE m.provider_id = ? ORDER BY m.vendor, m.model'
        );
        $st->execute([$providerId]);

        return array_map([$this, 'modelView'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function model(int $providerId, int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT m.*, 0 AS em_uso FROM net_equipment_models m WHERE m.id = ? AND m.provider_id = ?');
        $st->execute([$id, $providerId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);

        return $r === false ? null : $this->modelView($r);
    }

    /**
     * @param array<string,mixed> $in vendor, model, type, technology, ports (array familia=>int), default_profile_code, spec (array|null), notes
     * @return array{0:?int,1:list<string>} [id, erros]
     */
    public function saveModel(int $providerId, ?int $id, array $in): array
    {
        $errors = [];
        $vendor = trim((string) ($in['vendor'] ?? ''));
        $model = trim((string) ($in['model'] ?? ''));
        $type = (string) ($in['type'] ?? 'outro');
        $tech = trim((string) ($in['technology'] ?? ''));
        $profile = trim((string) ($in['default_profile_code'] ?? ''));
        $notes = trim((string) ($in['notes'] ?? ''));
        if ($vendor === '' || mb_strlen($vendor) > 80) {
            $errors[] = 'Fabricante é obrigatório (até 80 caracteres).';
        }
        if ($model === '' || mb_strlen($model) > 120) {
            $errors[] = 'Modelo é obrigatório (até 120 caracteres).';
        }
        if (!in_array($type, self::TYPES, true)) {
            $errors[] = 'Tipo inválido.';
        }
        if (mb_strlen($tech) > 40) {
            $errors[] = 'Tecnologia deve ter até 40 caracteres.';
        }
        if (mb_strlen($profile) > 80 || ($profile !== '' && preg_match('/^[a-z0-9._-]+$/i', $profile) !== 1)) {
            $errors[] = 'Perfil de telemetria padrão inválido.';
        }
        if (mb_strlen($notes) > 500) {
            $errors[] = 'Observação deve ter até 500 caracteres.';
        }
        $ports = [];
        foreach ((array) ($in['ports'] ?? []) as $family => $qty) {
            if ($qty === '' || $qty === null) {
                continue;
            }
            if (!in_array($family, self::PORT_FAMILIES, true) || filter_var($qty, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 256]]) === false) {
                $errors[] = 'Portas por família inválidas (pon, sfp, eth, fo: 0 a 256).';
                break;
            }
            if ((int) $qty > 0) {
                $ports[(string) $family] = (int) $qty;
            }
        }
        $spec = $in['spec'] ?? null;
        $specJson = null;
        if (is_array($spec) && $spec !== []) {
            $specJson = json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($specJson === false || strlen($specJson) > 4096) {
                $errors[] = 'Ficha técnica inválida ou maior que 4 KB.';
            }
        }
        if ($errors !== []) {
            return [null, $errors];
        }
        $dup = $this->pdo->prepare('SELECT id FROM net_equipment_models WHERE provider_id = ? AND vendor = ? AND model = ? AND id <> ?');
        $dup->execute([$providerId, $vendor, $model, $id ?? 0]);
        if ($dup->fetchColumn() !== false) {
            return [null, ['Já existe esse modelo para esse fabricante.']];
        }
        $values = [$vendor, $model, $type, $tech === '' ? null : $tech, $ports === [] ? null : json_encode($ports), $profile === '' ? null : $profile, $specJson, $notes === '' ? null : $notes];
        if ($id === null) {
            $this->pdo->prepare('INSERT INTO net_equipment_models (provider_id, vendor, model, equipment_type, technology, ports_json, default_profile_code, spec_json, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
                ->execute(array_merge([$providerId], $values));

            return [(int) $this->pdo->lastInsertId(), []];
        }
        $st = $this->pdo->prepare('UPDATE net_equipment_models SET vendor = ?, model = ?, equipment_type = ?, technology = ?, ports_json = ?, default_profile_code = ?, spec_json = ?, notes = ?, updated_at = NOW() WHERE id = ? AND provider_id = ?');
        $st->execute(array_merge($values, [$id, $providerId]));

        return $this->model($providerId, $id) === null ? [null, ['Modelo não encontrado.']] : [$id, []];
    }

    public function modelInUse(int $providerId, int $id): bool
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM net_equipment WHERE model_id = ? AND provider_id = ?');
        $st->execute([$id, $providerId]);

        return (int) $st->fetchColumn() > 0;
    }

    public function deleteModel(int $providerId, int $id): bool
    {
        $st = $this->pdo->prepare('DELETE FROM net_equipment_models WHERE id = ? AND provider_id = ?');
        $st->execute([$id, $providerId]);

        return $st->rowCount() > 0;
    }

    // ------------------------------------------------------------------ equipamento fisico

    /** @return list<array<string,mixed>> */
    public function equipment(int $providerId): array
    {
        $st = $this->pdo->prepare(
            'SELECT e.*, m.vendor, m.model, m.equipment_type, n.name AS node_name
               FROM net_equipment e JOIN net_equipment_models m ON m.id = e.model_id
               LEFT JOIN map_nodes n ON n.id = e.location_node_id AND n.provider_id = e.provider_id
              WHERE e.provider_id = ? ORDER BY m.vendor, m.model, e.serial, e.id'
        );
        $st->execute([$providerId]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'modelo_id' => (int) $r['model_id'], 'fabricante' => (string) $r['vendor'], 'modelo' => (string) $r['model'],
            'tipo' => (string) $r['equipment_type'], 'serie' => $r['serial'], 'mac' => $r['mac'], 'patrimonio' => $r['asset_tag'],
            'situacao' => (string) $r['status'], 'local_tipo' => (string) $r['location_kind'],
            'no_id' => $r['location_node_id'] === null ? null : (int) $r['location_node_id'], 'no_nome' => $r['node_name'],
            'local_obs' => $r['location_note'], 'observacao' => $r['notes'], 'atualizado_em' => (string) $r['updated_at'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null linha crua */
    public function equipmentRow(int $providerId, int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM net_equipment WHERE id = ? AND provider_id = ?');
        $st->execute([$id, $providerId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);

        return $r === false ? null : $r;
    }

    /** @return array<string,mixed>|null ativo ligado ao no (map_nodes.equipment_id) */
    public function equipmentOfNode(int $providerId, int $nodeId): ?array
    {
        $st = $this->pdo->prepare('SELECT e.* FROM map_nodes n JOIN net_equipment e ON e.id = n.equipment_id AND e.provider_id = n.provider_id WHERE n.id = ? AND n.provider_id = ?');
        $st->execute([$nodeId, $providerId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);

        return $r === false ? null : $r;
    }

    /**
     * Cria ou edita o equipamento fisico.
     *
     * @param array<string,mixed> $in model_id, serial, mac, asset_tag, status, location_kind, location_node_id, location_note, notes
     * @return array{0:?int,1:list<string>}
     */
    public function saveEquipment(int $providerId, ?int $id, array $in, string $actor): array
    {
        $errors = [];
        $modelId = (int) ($in['model_id'] ?? 0);
        $serial = trim((string) ($in['serial'] ?? ''));
        $mac = strtoupper(trim((string) ($in['mac'] ?? '')));
        $asset = trim((string) ($in['asset_tag'] ?? ''));
        $status = (string) ($in['status'] ?? 'instalado');
        $kind = (string) ($in['location_kind'] ?? 'no_mapa');
        $nodeId = isset($in['location_node_id']) && $in['location_node_id'] !== '' && $in['location_node_id'] !== null ? (int) $in['location_node_id'] : null;
        $locNote = trim((string) ($in['location_note'] ?? ''));
        $notes = trim((string) ($in['notes'] ?? ''));
        if ($this->model($providerId, $modelId) === null) {
            $errors[] = 'Escolha o modelo do catálogo.';
        }
        if (mb_strlen($serial) > 80 || mb_strlen($asset) > 60) {
            $errors[] = 'Série (até 80) ou patrimônio (até 60) longo demais.';
        }
        if ($mac !== '') {
            $hex = preg_replace('/[^0-9A-F]/', '', $mac) ?? '';
            if (strlen($hex) !== 12 || preg_match('/^[0-9A-F:.\- ]+$/', $mac) !== 1) {
                $errors[] = 'MAC inválido (12 dígitos hexadecimais).';
            } else {
                $mac = implode(':', str_split($hex, 2));
            }
        }
        if (!in_array($status, self::STATUSES, true)) {
            $errors[] = 'Situação inválida (estoque, instalado, manutenção ou baixado).';
        }
        if (!in_array($kind, self::LOCATIONS, true)) {
            $errors[] = 'Local inválido.';
        }
        if (mb_strlen($locNote) > 160 || mb_strlen($notes) > 500) {
            $errors[] = 'Observação longa demais.';
        }
        if ($kind === 'no_mapa') {
            $st = $this->pdo->prepare('SELECT id FROM map_nodes WHERE id = ? AND provider_id = ?');
            $st->execute([$nodeId ?? 0, $providerId]);
            if ($nodeId === null || $st->fetchColumn() === false) {
                $errors[] = 'Escolha o elemento do mapa onde o equipamento está.';
            } else {
                $other = $this->pdo->prepare('SELECT id FROM net_equipment WHERE provider_id = ? AND location_node_id = ? AND status <> \'baixado\' AND id <> ? LIMIT 1');
                $other->execute([$providerId, $nodeId, $id ?? 0]);
                if ($status !== 'baixado' && $other->fetchColumn() !== false) {
                    $errors[] = 'Esse elemento do mapa já tem outro equipamento (um ativo por elemento).';
                }
            }
        } else {
            $nodeId = null;
        }
        if ($errors !== []) {
            return [null, $errors];
        }
        $before = $id === null ? null : $this->equipmentRow($providerId, $id);
        if ($id !== null && $before === null) {
            return [null, ['Equipamento não encontrado.']];
        }
        $vals = [$modelId, $serial === '' ? null : $serial, $mac === '' ? null : $mac, $asset === '' ? null : $asset, $status, $kind, $nodeId, $locNote === '' ? null : $locNote, $notes === '' ? null : $notes];
        try {
            $this->pdo->beginTransaction();
            if ($id === null) {
                $this->pdo->prepare('INSERT INTO net_equipment (provider_id, model_id, serial, mac, asset_tag, status, location_kind, location_node_id, location_note, notes, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
                    ->execute(array_merge([$providerId], $vals, [$actor]));
                $id = (int) $this->pdo->lastInsertId();
            } else {
                $this->pdo->prepare('UPDATE net_equipment SET model_id = ?, serial = ?, mac = ?, asset_tag = ?, status = ?, location_kind = ?, location_node_id = ?, location_note = ?, notes = ?, updated_at = NOW() WHERE id = ? AND provider_id = ?')
                    ->execute(array_merge($vals, [$id, $providerId]));
            }
            if ($before === null || $before['status'] !== $status || (int) ($before['location_node_id'] ?? 0) !== (int) ($nodeId ?? 0)) {
                $this->pdo->prepare('INSERT INTO net_equipment_movements (provider_id, equipment_id, moved_at, from_status, to_status, from_node_id, to_node_id, note, actor) VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?)')
                    ->execute([$providerId, $id, $before['status'] ?? null, $status, $before['location_node_id'] ?? null, $nodeId, $before === null ? 'cadastro' : null, $actor]);
            }
            // Um ativo por no: o no aponta para este equipamento enquanto ele estiver nele e nao baixado.
            if ($before !== null && $before['location_node_id'] !== null && (int) $before['location_node_id'] !== (int) ($nodeId ?? 0)) {
                $this->pdo->prepare('UPDATE map_nodes SET equipment_id = NULL WHERE id = ? AND provider_id = ? AND equipment_id = ?')->execute([$before['location_node_id'], $providerId, $id]);
            }
            if ($nodeId !== null && $status !== 'baixado') {
                $this->pdo->prepare('UPDATE map_nodes SET equipment_id = ? WHERE id = ? AND provider_id = ?')->execute([$id, $nodeId, $providerId]);
            } elseif ($nodeId !== null) {
                $this->pdo->prepare('UPDATE map_nodes SET equipment_id = NULL WHERE id = ? AND provider_id = ? AND equipment_id = ?')->execute([$nodeId, $providerId, $id]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $dupe = $e instanceof \PDOException && ($e->errorInfo[1] ?? 0) === 1062;

            return [null, [$dupe ? 'Série, MAC ou patrimônio já cadastrado em outro equipamento.' : 'Não foi possível salvar o equipamento.']];
        }

        return [$id, []];
    }

    /** @return list<array<string,mixed>> histórico mínimo, mais recente primeiro */
    public function movements(int $providerId, int $equipmentId): array
    {
        $st = $this->pdo->prepare('SELECT moved_at, from_status, to_status, from_node_id, to_node_id, note, actor FROM net_equipment_movements WHERE provider_id = ? AND equipment_id = ? ORDER BY id DESC LIMIT 50');
        $st->execute([$providerId, $equipmentId]);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function modelView(array $r): array
    {
        $ports = json_decode((string) ($r['ports_json'] ?? ''), true);
        $spec = json_decode((string) ($r['spec_json'] ?? ''), true);

        return [
            'id' => (int) $r['id'], 'fabricante' => (string) $r['vendor'], 'modelo' => (string) $r['model'], 'tipo' => (string) $r['equipment_type'],
            'tecnologia' => $r['technology'], 'portas' => is_array($ports) ? $ports : (object) [], 'perfil_padrao' => $r['default_profile_code'],
            'ficha' => is_array($spec) ? $spec : (object) [], 'observacao' => $r['notes'], 'em_uso' => (int) ($r['em_uso'] ?? 0),
        ];
    }
}
