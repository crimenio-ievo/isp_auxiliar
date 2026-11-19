<?php

declare(strict_types=1);

namespace App\Infrastructure\Map;

/**
 * Prévia da importação de cabos: liga cada ponta de cada linha ao elemento mais próximo dentro de um raio.
 * Puro (sem banco): recebe os nós e os cabos já existentes. Status por linha:
 *  ok | sem_origem | sem_destino | sem_pontas | mesmo_no | ambiguo | duplicado | curta
 * Só "ok" pode ser importado. Elementos candidatos: os que têm coordenada própria (CTO, CEO, POP, torre).
 */
final class CableImportPlanner
{
    public const DEFAULT_RADIUS_M = 25.0;
    public const MIN_RADIUS_M = 1.0;
    public const MAX_RADIUS_M = 500.0;
    /** Dois elementos a distâncias que diferem menos que isto na mesma ponta = ambíguo (não adivinha). */
    public const AMBIGUITY_M = 1.0;
    private const SNAP_TYPES = ['cto', 'ceo', 'pop', 'tower'];

    /**
     * @param list<array<string, mixed>> $nodes linhas de map_nodes (id, node_type, name, lat, lng)
     * @param list<array{nome: string, pontos: list<array{0: float, 1: float}>}> $lines
     * @param list<array{0: int, 1: int, 2: string}> $existing [from, to, link_type] dos cabos já gravados
     * @return list<array<string, mixed>>
     */
    public static function plan(array $nodes, array $lines, float $radiusM, string $linkType, array $existing): array
    {
        $cands = [];
        foreach ($nodes as $n) {
            if (in_array($n['node_type'], self::SNAP_TYPES, true) && $n['lat'] !== null && $n['lng'] !== null) {
                $cands[] = ['id' => (int) $n['id'], 'nome' => (string) $n['name'], 'p' => [(float) $n['lat'], (float) $n['lng']]];
            }
        }
        $taken = [];
        foreach ($existing as [$a, $b, $t]) {
            $taken[$t . ':' . min($a, $b) . ':' . max($a, $b)] = true;
        }

        $out = [];
        foreach ($lines as $i => $line) {
            $pts = $line['pontos'];
            $row = ['indice' => $i, 'nome' => $line['nome'], 'n_pontos' => count($pts), 'comprimento_m' => round(MapPath::polylineMeters($pts), 2),
                'origem' => null, 'destino' => null, 'pontos' => [], 'status' => 'ok', 'mensagem' => ''];
            if (count($pts) < 2) {
                $row['status'] = 'curta';
                $row['mensagem'] = 'Linha com menos de 2 pontos.';
                $out[] = $row;
                continue;
            }
            [$a, $amb1, $near1] = self::nearest($cands, $pts[0], $radiusM);
            [$b, $amb2, $near2] = self::nearest($cands, $pts[count($pts) - 1], $radiusM);
            $row['origem'] = $a;
            $row['destino'] = $b;
            $fmt = static fn (?array $near): string => $near === null ? 'nenhum elemento cadastrado' : 'mais próximo: ' . $near['nome'] . ' a ' . round($near['dist_m']) . ' m';
            if ($a === null && $b === null) {
                $row['status'] = 'sem_pontas';
                $row['mensagem'] = 'Nenhuma ponta chega a um elemento dentro de ' . round($radiusM) . ' m (início: ' . $fmt($near1) . '; fim: ' . $fmt($near2) . ').';
            } elseif ($a === null) {
                $row['status'] = 'sem_origem';
                $row['mensagem'] = 'O início não chega a um elemento dentro de ' . round($radiusM) . ' m (' . $fmt($near1) . ').';
            } elseif ($b === null) {
                $row['status'] = 'sem_destino';
                $row['mensagem'] = 'O fim não chega a um elemento dentro de ' . round($radiusM) . ' m (' . $fmt($near2) . ').';
            } elseif ($amb1 !== null || $amb2 !== null) {
                $row['status'] = 'ambiguo';
                $row['mensagem'] = 'Ponta equidistante de ' . ($amb1 ?? $amb2) . ': ajuste o raio ou a posição.';
            } elseif ($a['id'] === $b['id']) {
                $row['status'] = 'mesmo_no';
                $row['mensagem'] = 'As duas pontas caem no mesmo elemento (' . $a['nome'] . ').';
            } else {
                $key = $linkType . ':' . min($a['id'], $b['id']) . ':' . max($a['id'], $b['id']);
                if (isset($taken[$key])) {
                    $row['status'] = 'duplicado';
                    $row['mensagem'] = 'Já existe um cabo deste tipo entre ' . $a['nome'] . ' e ' . $b['nome'] . ' (ou repetido no arquivo).';
                } else {
                    $taken[$key] = true;
                    // sem pontos colados e limitado a MAX_POINTS (a simplificação preserva a forma dentro de ~1 m)
                    $tol = 0.5;
                    $simple = MapPath::simplify($pts, $tol);
                    while (count($simple) - 2 > MapPath::MAX_POINTS && $tol < 256) {
                        $tol *= 2;
                        $simple = MapPath::simplify($pts, $tol);
                    }
                    $row['pontos'] = array_slice($simple, 1, -1);
                    $row['mensagem'] = $a['nome'] . ' → ' . $b['nome'];
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param list<array{id: int, nome: string, p: array{0: float, 1: float}}> $cands
     * @param array{0: float, 1: float} $p
     * @return array{0: ?array{id: int, nome: string, dist_m: float}, 1: ?string, 2: ?array{nome: string, dist_m: float}} [dentro do raio, nomes ambíguos, mais próximo geral]
     */
    private static function nearest(array $cands, array $p, float $radiusM): array
    {
        $best = null;
        $second = null;
        foreach ($cands as $c) {
            $d = MapPath::haversine($p, $c['p']);
            $item = ['id' => $c['id'], 'nome' => $c['nome'], 'dist_m' => $d];
            if ($best === null || $d < $best['dist_m']) {
                $second = $best;
                $best = $item;
            } elseif ($second === null || $d < $second['dist_m']) {
                $second = $item;
            }
        }
        if ($best === null) {
            return [null, null, null];
        }
        if ($best['dist_m'] > $radiusM) {
            return [null, null, ['nome' => $best['nome'], 'dist_m' => $best['dist_m']]];
        }
        $amb = $second !== null && $second['dist_m'] <= $radiusM && $second['dist_m'] - $best['dist_m'] < self::AMBIGUITY_M ? $best['nome'] . ' e ' . $second['nome'] : null;
        $best['dist_m'] = round($best['dist_m'], 1);

        return [$best, $amb, null];
    }
}
