<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

use PDO;

/**
 * Migra fabricante/modelo de map_nodes.attrs_json para o catalogo (uma unica origem) e liga cada no ao seu equipamento fisico
 * (map_nodes.equipment_id). Tambem liga um no explicito a um modelo ($links). Simulacao por padrao: so grava com $apply.
 * Nao cria modelos: sem correspondencia no catalogo o no e apenas relatado.
 */
final class NetEquipmentMigrator
{
    private const NODE_TYPES = ['olt', 'switch', 'router', 'radio', 'server', 'other'];

    public function __construct(private PDO $pdo, private NetEquipmentRepository $repo)
    {
    }

    /**
     * @param array<int,string> $links no do mapa => modelo (ex.: [42 => 'HA7004S'])
     * @return array{aplicado:bool,itens:list<array<string,mixed>>}
     */
    public function run(bool $apply, array $links = []): array
    {
        $in = implode(',', array_map(static fn (string $t): string => "'{$t}'", self::NODE_TYPES));
        $nodes = $this->pdo->query("SELECT id, provider_id, node_type, name, attrs_json, equipment_id FROM map_nodes WHERE node_type IN ({$in}) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $items = [];
        foreach ($nodes as $node) {
            $nodeId = (int) $node['id'];
            $providerId = (int) $node['provider_id'];
            $attrs = json_decode((string) ($node['attrs_json'] ?? ''), true);
            $attrs = is_array($attrs) ? $attrs : [];
            $item = ['no_id' => $nodeId, 'nome' => (string) $node['name'], 'acao' => '', 'modelo' => null];
            if ($node['equipment_id'] !== null) {
                $item['acao'] = 'ja_ligado';
                $items[] = $item;
                continue;
            }
            $vendor = trim((string) ($attrs['fabricante'] ?? ''));
            $model = trim((string) ($links[$nodeId] ?? ($attrs['modelo'] ?? '')));
            if ($model === '') {
                $item['acao'] = 'sem_fabricante_modelo';
                $items[] = $item;
                continue;
            }
            $found = $this->findModel($providerId, isset($links[$nodeId]) ? '' : $vendor, $model);
            if ($found === null) {
                $item['acao'] = 'sem_modelo_no_catalogo';
                $item['modelo'] = trim($vendor . ' ' . $model);
                $items[] = $item;
                continue;
            }
            $item['acao'] = 'ligar_ao_catalogo';
            $item['modelo'] = $found['fabricante'] . ' ' . $found['modelo'];
            $item['limpar_attrs'] = array_values(array_intersect(['fabricante', 'modelo'], array_keys($attrs)));
            if ($apply) {
                [$id, $errors] = $this->repo->saveEquipment($providerId, null, [
                    'model_id' => $found['id'], 'status' => 'instalado', 'location_kind' => 'no_mapa', 'location_node_id' => $nodeId,
                ], 'script net_equipment_migrate');
                if ($id === null) {
                    $item['acao'] = 'erro';
                    $item['erro'] = implode(' ', $errors);
                    $items[] = $item;
                    continue;
                }
                $item['equipamento_id'] = $id;
                if ($item['limpar_attrs'] !== []) {
                    $rest = array_diff_key($attrs, ['fabricante' => 1, 'modelo' => 1]);
                    $this->pdo->prepare('UPDATE map_nodes SET attrs_json = ?, updated_at = NOW() WHERE id = ? AND provider_id = ?')
                        ->execute([$rest === [] ? null : json_encode($rest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $nodeId, $providerId]);
                }
            }
            $items[] = $item;
        }

        return ['aplicado' => $apply, 'itens' => $items];
    }

    /** @return array<string,mixed>|null */
    private function findModel(int $providerId, string $vendor, string $model): ?array
    {
        foreach ($this->repo->models($providerId) as $m) {
            if (strcasecmp($m['modelo'], $model) === 0 && ($vendor === '' || strcasecmp($m['fabricante'], $vendor) === 0)) {
                return $m;
            }
        }

        return null;
    }
}
