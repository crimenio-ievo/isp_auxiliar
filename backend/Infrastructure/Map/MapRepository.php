<?php

declare(strict_types=1);

namespace App\Infrastructure\Map;

use App\Infrastructure\Database\Database;

/**
 * ISP Map: leitura da topologia (NODE -> PORT -> LINK) do provedor.
 */
final class MapRepository
{
    public function __construct(private Database $database)
    {
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, ports: list<array<string, mixed>>, links: list<array<string, mixed>>}
     */
    public function topology(int $providerId): array
    {
        $pdo = $this->database->pdo();
        $params = ['provider_id' => $providerId];

        $nodes = $this->fetch($pdo, 'SELECT id, node_type, name, code, lat, lng, parent_node_id, status, attrs_json, equipment_id, notes
             FROM map_nodes WHERE provider_id = :provider_id ORDER BY id', $params);
        $ports = $this->fetch($pdo, 'SELECT id, node_id, port_num, label, port_role, status, attrs_json
             FROM map_ports WHERE provider_id = :provider_id ORDER BY node_id, port_role, port_num', $params);
        $links = $this->fetch($pdo, 'SELECT id, link_type, medium, from_node_id, to_node_id, from_port_id, to_port_id, status, label,
                    length_m, fiber_count, attrs_json, path_json
             FROM map_links WHERE provider_id = :provider_id ORDER BY id', $params);

        return [
            'nodes' => array_map(fn (array $r): array => $this->normalize($r, ['id', 'parent_node_id', 'equipment_id'], ['lat', 'lng'], ['attrs_json']), $nodes),
            'ports' => array_map(fn (array $r): array => $this->normalize($r, ['id', 'node_id', 'port_num'], [], ['attrs_json']), $ports),
            'links' => array_map(fn (array $r): array => $this->normalize($r, ['id', 'from_node_id', 'to_node_id', 'from_port_id', 'to_port_id', 'fiber_count'], ['length_m'], ['attrs_json', 'path_json']), $links),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function fetch(\PDO $pdo, string $sql, array $params): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $ints
     * @param list<string> $floats
     * @param list<string> $jsons
     * @return array<string, mixed>
     */
    private function normalize(array $row, array $ints, array $floats, array $jsons): array
    {
        foreach ($ints as $key) {
            $row[$key] = ($row[$key] ?? null) === null ? null : (int) $row[$key];
        }
        foreach ($floats as $key) {
            $row[$key] = ($row[$key] ?? null) === null ? null : (float) $row[$key];
        }
        foreach ($jsons as $key) {
            $decoded = ($row[$key] ?? null) === null ? null : json_decode((string) $row[$key], true);
            $row[$key] = $decoded;
        }

        return $row;
    }
}
