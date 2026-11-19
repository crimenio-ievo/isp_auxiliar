<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

use App\Infrastructure\Map\MapPath;
use PDO;

/**
 * Busca de CTO para "Definir CTO" de uma ONU: por nome (LIKE) e/ou por proximidade (coordenadas do cliente), com as portas LIVRES
 * de cada uma. Porta livre = porta de fibra de cliente ('fo') com status livre, fora de qualquer ligacao do mapa e sem vinculo ativo.
 * So leitura.
 */
final class NetCtoPicker
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array{0: float, 1: float}|null $near [lat, lng] de referencia (ordena por distancia)
     * @return list<array{id:int,nome:string,distancia_m:?int,total_portas:int,livres:list<array{id:int,rotulo:string}>}>
     */
    public function search(int $providerId, string $q, ?array $near, int $limit = 8): array
    {
        $q = trim($q);
        $sql = "SELECT id, name, lat, lng FROM map_nodes WHERE provider_id = ? AND node_type = 'cto'";
        $params = [$providerId];
        if ($q !== '') {
            $sql .= " AND name LIKE ? ESCAPE '!'";
            $params[] = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $dist = $near !== null && $r['lat'] !== null && $r['lng'] !== null ? MapPath::haversine($near, [(float) $r['lat'], (float) $r['lng']]) : null;
            $rows[] = ['id' => (int) $r['id'], 'nome' => (string) $r['name'], 'distancia_m' => $dist === null ? null : (int) round($dist)];
        }
        usort($rows, static function (array $a, array $b): int {
            if ($a['distancia_m'] !== null || $b['distancia_m'] !== null) {
                return ($a['distancia_m'] ?? PHP_INT_MAX) <=> ($b['distancia_m'] ?? PHP_INT_MAX) ?: strnatcasecmp($a['nome'], $b['nome']);
            }

            return strnatcasecmp($a['nome'], $b['nome']);
        });
        $rows = array_slice($rows, 0, max(1, min(20, $limit)));
        if ($rows === []) {
            return [];
        }
        $ids = array_column($rows, 'id');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $free = [];
        $total = [];
        $st = $this->pdo->prepare("SELECT node_id, COUNT(*) FROM map_ports WHERE provider_id = ? AND port_role = 'fo' AND node_id IN ({$marks}) GROUP BY node_id");
        $st->execute(array_merge([$providerId], $ids));
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$nid, $n]) {
            $total[(int) $nid] = (int) $n;
        }
        $st = $this->pdo->prepare(
            "SELECT p.id, p.node_id, p.label FROM map_ports p
              WHERE p.provider_id = ? AND p.port_role = 'fo' AND p.status = 'livre' AND p.node_id IN ({$marks})
                AND NOT EXISTS (SELECT 1 FROM map_links l WHERE l.provider_id = p.provider_id AND (l.from_port_id = p.id OR l.to_port_id = p.id))
                AND NOT EXISTS (SELECT 1 FROM client_network_bindings b WHERE b.provider_id = p.provider_id AND b.ativo = 1 AND b.cto_port_id = p.id)
              ORDER BY p.node_id, p.port_num"
        );
        $st->execute(array_merge([$providerId], $ids));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $free[(int) $p['node_id']][] = ['id' => (int) $p['id'], 'rotulo' => (string) $p['label']];
        }

        return array_map(static fn (array $r): array => $r + ['total_portas' => $total[$r['id']] ?? 0, 'livres' => $free[$r['id']] ?? []], $rows);
    }
}
