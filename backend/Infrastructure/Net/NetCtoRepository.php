<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

use PDO;

/**
 * Leitura das CTOs do mapa (map_nodes node_type='cto') com portas, origem e vinculos. So SELECT:
 * toda escrita passa pelo MapNodeGateway (regras do mapa).
 */
final class NetCtoRepository
{
    public const NEAR_METERS = 15.0;

    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function list(int $providerId): array
    {
        $st = $this->pdo->prepare("SELECT id, name, lat, lng, attrs_json, notes, lifecycle FROM map_nodes WHERE provider_id = ? AND node_type = 'cto' ORDER BY name, id");
        $st->execute([$providerId]);
        $nodes = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($nodes === []) {
            return [];
        }

        // Por porta: em ligacao do mapa, com cliente ativo ou indisponivel (defeito/reservada). Livre = nenhuma das tres.
        $boundPorts = [];
        if ($this->bindingsAvailable()) {
            $st = $this->pdo->prepare('SELECT cto_port_id FROM client_network_bindings WHERE provider_id = ? AND ativo = 1 AND cto_port_id IS NOT NULL');
            $st->execute([$providerId]);
            $boundPorts = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
        }
        $ports = [];
        $st = $this->pdo->prepare(
            "SELECT p.id, p.node_id, p.status,
                    EXISTS(SELECT 1 FROM map_links l WHERE l.provider_id = p.provider_id AND (l.from_port_id = p.id OR l.to_port_id = p.id)) AS em_ligacao
               FROM map_ports p WHERE p.provider_id = ? AND p.port_role = 'fo' AND p.node_id IN (SELECT id FROM map_nodes WHERE provider_id = ? AND node_type = 'cto')"
        );
        $st->execute([$providerId, $providerId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $nid = (int) $r['node_id'];
            $ports[$nid] ??= ['total' => 0, 'em_ligacao' => 0, 'com_cliente' => 0, 'indisponiveis' => 0];
            $ports[$nid]['total']++;
            if ((int) $r['em_ligacao'] === 1) {
                $ports[$nid]['em_ligacao']++;
            } elseif (isset($boundPorts[(int) $r['id']])) {
                $ports[$nid]['com_cliente']++;
            } elseif ($r['status'] !== 'livre') {
                $ports[$nid]['indisponiveis']++;
            }
        }
        $clients = $this->clientPorts($providerId);
        $origins = $this->origins($providerId);

        $out = [];
        foreach ($nodes as $n) {
            $id = (int) $n['id'];
            $attrs = json_decode((string) ($n['attrs_json'] ?? ''), true);
            $attrs = is_array($attrs) ? $attrs : [];
            $total = $ports[$id]['total'] ?? 0;
            $linked = $ports[$id]['em_ligacao'] ?? 0;
            $clientPorts = $ports[$id]['com_cliente'] ?? 0;
            $unavailable = $ports[$id]['indisponiveis'] ?? 0;
            $capacity = isset($attrs['capacity']) && is_numeric($attrs['capacity']) ? (int) $attrs['capacity'] : $total;
            $used = $linked + $clientPorts;
            $out[] = [
                'id' => $id,
                'nome' => (string) $n['name'],
                'lat' => $n['lat'] === null ? null : (float) $n['lat'],
                'lng' => $n['lng'] === null ? null : (float) $n['lng'],
                'capacidade' => $capacity,
                'portas' => ['total' => $total, 'ocupadas' => $used, 'livres' => max(0, $total - $used - $unavailable), 'indisponiveis' => $unavailable, 'em_ligacao' => $linked, 'com_cliente' => $clientPorts],
                'clientes' => $clients['disponivel'] ? ($clients['clientes'][$id] ?? 0) : null,
                'onus' => $clients['disponivel'] ? ($clients['onus'][$id] ?? 0) : null,
                'origem' => $origins[$id] ?? null,
                'observacao' => $n['notes'] === null ? '' : (string) $n['notes'],
                'situacao' => (string) $n['lifecycle'],
            ];
        }

        return $out;
    }

    private function bindingsAvailable(): bool
    {
        try {
            $this->pdo->query('SELECT id FROM client_network_bindings LIMIT 0');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed>|null */
    public function find(int $providerId, int $id): ?array
    {
        foreach ($this->list($providerId) as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    public function nameExists(int $providerId, string $name, ?int $exceptId = null): bool
    {
        $st = $this->pdo->prepare("SELECT id FROM map_nodes WHERE provider_id = ? AND node_type = 'cto' AND LOWER(TRIM(name)) = LOWER(?) AND id <> ? LIMIT 1");
        $st->execute([$providerId, trim($name), $exceptId ?? 0]);

        return $st->fetchColumn() !== false;
    }

    /**
     * CTOs a menos de $meters da coordenada.
     *
     * @return list<array{id:int,nome:string,metros:float}>
     */
    public function near(int $providerId, float $lat, float $lng, float $meters = self::NEAR_METERS, ?int $exceptId = null): array
    {
        $st = $this->pdo->prepare("SELECT id, name, lat, lng FROM map_nodes WHERE provider_id = ? AND node_type = 'cto' AND lat IS NOT NULL AND lng IS NOT NULL AND id <> ?");
        $st->execute([$providerId, $exceptId ?? 0]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = Coordinates::meters($lat, $lng, (float) $r['lat'], (float) $r['lng']);
            if ($d < $meters) {
                $out[] = ['id' => (int) $r['id'], 'nome' => (string) $r['name'], 'metros' => round($d, 1)];
            }
        }
        usort($out, static fn (array $a, array $b): int => $a['metros'] <=> $b['metros']);

        return $out;
    }

    /** Ha ligacao, no filho ou cliente vinculado que impeca excluir? Devolve os motivos em texto. */
    public function deleteBlockers(int $providerId, int $id): array
    {
        $reasons = [];
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM map_links WHERE provider_id = ? AND (from_node_id = ? OR to_node_id = ?)');
        $st->execute([$providerId, $id, $id]);
        if (($n = (int) $st->fetchColumn()) > 0) {
            $reasons[] = $n . ' ligação(ões) no mapa';
        }
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM map_nodes WHERE provider_id = ? AND parent_node_id = ?');
        $st->execute([$providerId, $id]);
        if (($n = (int) $st->fetchColumn()) > 0) {
            $reasons[] = $n . ' elemento(s) dentro da caixa (ex.: splitter)';
        }
        $c = $this->clientPorts($providerId);
        if (($c['clientes'][$id] ?? 0) > 0) {
            $reasons[] = $c['clientes'][$id] . ' cliente(s) vinculado(s)';
        }

        return $reasons;
    }

    /**
     * Vinculos cliente<->CTO (tabela client_network_bindings, quando existir).
     *
     * Por CTO: `clientes` = vinculos ativos (assinantes), `portas` = portas distintas ocupadas, `onus` = ONUs distintas ocupando portas.
     * Com varios clientes na mesma ONU, clientes pode ser maior que portas e onus.
     *
     * @return array{disponivel:bool,clientes:array<int,int>,portas:array<int,int>,onus:array<int,int>}
     */
    public function clientPorts(int $providerId): array
    {
        $out = ['disponivel' => false, 'clientes' => [], 'portas' => [], 'onus' => []];
        try {
            $st = $this->pdo->prepare('SELECT cto_node_id, COUNT(*) AS clientes, COUNT(DISTINCT cto_port_id) AS portas, COUNT(DISTINCT onu_id) AS onus FROM client_network_bindings WHERE provider_id = ? AND ativo = 1 GROUP BY cto_node_id');
            $st->execute([$providerId]);
        } catch (\Throwable) {
            return $out;
        }
        $out['disponivel'] = true;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out['clientes'][(int) $r['cto_node_id']] = (int) $r['clientes'];
            $out['onus'][(int) $r['cto_node_id']] = (int) $r['onus'];
            $out['portas'][(int) $r['cto_node_id']] = (int) $r['portas'];
        }

        return $out;
    }

    /**
     * Origem de cada CTO pelas ligacoes do mapa: OLT/PON, depois site (POP/torre), depois outra caixa.
     *
     * @return array<int,string>
     */
    private function origins(int $providerId): array
    {
        $st = $this->pdo->prepare(
            "SELECT l.from_node_id, l.to_node_id, l.from_port_id, l.to_port_id,
                    fn.node_type AS ft, fn.name AS fname, tn.node_type AS tt, tn.name AS tname,
                    fp.label AS fplabel, fp.port_role AS fprole, tp.label AS tplabel, tp.port_role AS tprole
               FROM map_links l
               JOIN map_nodes fn ON fn.id = l.from_node_id JOIN map_nodes tn ON tn.id = l.to_node_id
               LEFT JOIN map_ports fp ON fp.id = l.from_port_id LEFT JOIN map_ports tp ON tp.id = l.to_port_id
              WHERE l.provider_id = ? AND (fn.node_type = 'cto' OR tn.node_type = 'cto') ORDER BY l.id"
        );
        $st->execute([$providerId]);
        $best = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            foreach ([['from', 'to'], ['to', 'from']] as [$a, $b]) {
                $ctoType = $r[$a === 'from' ? 'ft' : 'tt'];
                if ($ctoType !== 'cto') {
                    continue;
                }
                $ctoId = (int) $r[$a . '_node_id'];
                $otherType = (string) $r[$b === 'from' ? 'ft' : 'tt'];
                $otherName = (string) $r[$b === 'from' ? 'fname' : 'tname'];
                $portLabel = (string) ($r[($b === 'from' ? 'f' : 't') . 'plabel'] ?? '');
                if ($otherType === 'cto' && (int) $r[$b . '_node_id'] === $ctoId) {
                    continue;
                }
                [$rank, $text] = match (true) {
                    $otherType === 'olt' => [1, $otherName . ($portLabel !== '' ? ' · ' . $portLabel : '')],
                    in_array($otherType, ['pop', 'tower'], true) => [2, $otherName],
                    default => [3, 'via ' . strtoupper($otherType) . ' ' . $otherName],
                };
                if (!isset($best[$ctoId]) || $rank < $best[$ctoId][0]) {
                    $best[$ctoId] = [$rank, $text];
                }
            }
        }

        return array_map(static fn (array $v): string => $v[1], $best);
    }
}
