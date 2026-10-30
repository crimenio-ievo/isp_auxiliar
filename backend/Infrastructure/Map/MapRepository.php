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

    public function pdo(): \PDO
    {
        return $this->database->pdo();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findNode(int $providerId, int $nodeId): ?array
    {
        $rows = $this->fetch(
            $this->database->pdo(),
            'SELECT id, node_type, name, code, lat, lng, parent_node_id, status, attrs_json, equipment_id, notes
             FROM map_nodes WHERE id = :id AND provider_id = :provider_id',
            ['id' => $nodeId, 'provider_id' => $providerId]
        );

        return $rows === [] ? null : $this->normalize($rows[0], ['id', 'parent_node_id', 'equipment_id'], ['lat', 'lng'], ['attrs_json']);
    }

    /**
     * @param array<string, mixed> $fields node_type, name, code, lat, lng, parent_node_id, status, attrs_json (string|null), notes
     */
    public function createNode(int $providerId, ?int $userId, array $fields): int
    {
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare(
            'INSERT INTO map_nodes (provider_id, node_type, name, code, lat, lng, parent_node_id, status, attrs_json, notes,
                    created_by_user_id, updated_by_user_id, created_at, updated_at)
             VALUES (:provider_id, :node_type, :name, :code, :lat, :lng, :parent_node_id, :status, :attrs_json, :notes,
                    :user_id, :user_id2, NOW(), NOW())'
        );
        $statement->execute([
            'provider_id' => $providerId,
            'node_type' => $fields['node_type'],
            'name' => $fields['name'],
            'code' => $fields['code'] ?? null,
            'lat' => $fields['lat'],
            'lng' => $fields['lng'],
            'parent_node_id' => $fields['parent_node_id'] ?? null,
            'status' => $fields['status'],
            'attrs_json' => $fields['attrs_json'] ?? null,
            'notes' => $fields['notes'] ?? null,
            'user_id' => $userId,
            'user_id2' => $userId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Atualiza só as colunas presentes em $fields (whitelist abaixo).
     *
     * @param array<string, mixed> $fields
     */
    public function updateNode(int $providerId, int $nodeId, ?int $userId, array $fields): void
    {
        $allowed = ['name', 'code', 'lat', 'lng', 'parent_node_id', 'status', 'attrs_json', 'notes'];
        $sets = [];
        $params = ['id' => $nodeId, 'provider_id' => $providerId, 'user_id' => $userId];
        foreach ($allowed as $column) {
            if (array_key_exists($column, $fields)) {
                $sets[] = "{$column} = :{$column}";
                $params[$column] = $fields[$column];
            }
        }
        $sets[] = 'updated_by_user_id = :user_id';
        $sets[] = 'updated_at = NOW()';

        $this->database->pdo()
            ->prepare('UPDATE map_nodes SET ' . implode(', ', $sets) . ' WHERE id = :id AND provider_id = :provider_id')
            ->execute($params);
    }

    /**
     * Ids dos nós que descendem de $nodeId (para impedir ciclos de parent_node_id).
     *
     * @return list<int>
     */
    public function descendantIds(int $providerId, int $nodeId): array
    {
        $found = [];
        $frontier = [$nodeId];
        while ($frontier !== []) {
            $marks = implode(',', array_fill(0, count($frontier), '?'));
            $statement = $this->database->pdo()->prepare("SELECT id FROM map_nodes WHERE provider_id = ? AND parent_node_id IN ({$marks})");
            $statement->execute(array_merge([$providerId], $frontier));
            $frontier = array_values(array_diff(array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN)), $found));
            $found = array_merge($found, $frontier);
        }

        return $found;
    }

    /**
     * O que impede a exclusão: nós filhos e ligações que usam o nó.
     *
     * @return array{children: list<array<string, mixed>>, links: list<array<string, mixed>>}
     */
    public function deleteBlockers(int $providerId, int $nodeId): array
    {
        $pdo = $this->database->pdo();

        return [
            'children' => $this->fetch($pdo, 'SELECT id, name FROM map_nodes WHERE provider_id = :p AND parent_node_id = :n ORDER BY id', ['p' => $providerId, 'n' => $nodeId]),
            'links' => $this->fetch($pdo, 'SELECT id, label FROM map_links WHERE provider_id = :p AND (from_node_id = :n OR to_node_id = :n2) ORDER BY id', ['p' => $providerId, 'n' => $nodeId, 'n2' => $nodeId]),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function portsOfNode(int $providerId, int $nodeId): array
    {
        return $this->fetch($this->database->pdo(), 'SELECT * FROM map_ports WHERE provider_id = :p AND node_id = :n ORDER BY id', ['p' => $providerId, 'n' => $nodeId]);
    }

    public function deleteNode(int $providerId, int $nodeId): void
    {
        $this->database->pdo()
            ->prepare('DELETE FROM map_nodes WHERE id = :id AND provider_id = :provider_id')
            ->execute(['id' => $nodeId, 'provider_id' => $providerId]);
    }

    public function userExists(int $providerId, int $userId): bool
    {
        $rows = $this->fetch($this->database->pdo(), 'SELECT id FROM app_users WHERE id = :id AND (provider_id = :p OR provider_id IS NULL)', ['id' => $userId, 'p' => $providerId]);

        return $rows !== [];
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
