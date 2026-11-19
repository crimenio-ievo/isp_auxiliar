<?php

declare(strict_types=1);

namespace App\Infrastructure\Map;

/**
 * Traçado de um cabo (map_links.path_json) e cálculo do comprimento.
 *
 * Formato gravado (objeto JSON):
 *   {"pontos": [[lat,lng], ...],            // SÓ os pontos intermediários; as pontas vêm dos nós
 *    "reservas": [{"local": "...", "m": 30, "pos": [lat,lng]}],  // reservas técnicas; "pos" (opcional) é o ponto no traçado
 *                                              // onde a reserva aparece no mapa; sem "pos" = reserva antiga, vale no total
 *    "folga_pct": 5}                        // folga % sobre o traçado; ausente = padrão da rede
 * path_json NULL = cabo antigo, linha reta entre os dois nós (continua valendo).
 *
 * Comprimento (mesma fórmula do /mapa, em isp-network-map.html):
 *   traçado = soma haversine de [ponta A, ...pontos, ponta B]
 *   folga   = traçado × folga% / 100   (a folga NÃO incide sobre as reservas, que já são metros explícitos)
 *   total   = traçado + folga + reservas
 * length_m preenchido é comprimento MANUAL e prevalece sobre o total calculado.
 */
final class MapPath
{
    public const MAX_POINTS = 2000;
    public const MAX_RESERVES = 50;
    public const MAX_RESERVE_M = 10000.0;
    public const MAX_SLACK_PCT = 100.0;
    private const EARTH_RADIUS_M = 6371000.0;

    /**
     * Valida e normaliza o traçado vindo do cliente (string JSON ou array já decodificado).
     * Devolve o array canônico, null (sem traçado: linha reta) ou acrescenta erros.
     *
     * @param list<string> $errors
     * @return array{pontos: list<array{0: float, 1: float}>, reservas: list<array{local: string, m: float, pos?: array{0: float, 1: float}}>, folga_pct?: float}|null
     */
    public static function normalize(mixed $raw, array &$errors): ?array
    {
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '' || $raw === 'null') {
                return null;
            }
            if (strlen($raw) > 400000) {
                $errors[] = 'Traçado grande demais.';

                return null;
            }
            $raw = json_decode($raw, true);
            if ($raw === null && json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = 'Traçado inválido (JSON malformado).';

                return null;
            }
        }
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw)) {
            $errors[] = 'Traçado inválido.';

            return null;
        }
        if ($raw !== [] && array_is_list($raw)) {
            $raw = ['pontos' => $raw];   // lista pura de pontos também é aceita (formato mínimo)
        }

        $pontos = [];
        $rawPoints = $raw['pontos'] ?? [];
        if (!is_array($rawPoints) || !array_is_list($rawPoints)) {
            $errors[] = 'Pontos do traçado inválidos.';

            return null;
        }
        if (count($rawPoints) > self::MAX_POINTS) {
            $errors[] = 'Traçado com pontos demais (máximo ' . self::MAX_POINTS . ').';

            return null;
        }
        foreach ($rawPoints as $p) {
            if (!is_array($p) || count($p) !== 2 || !isset($p[0], $p[1]) || !is_numeric($p[0]) || !is_numeric($p[1])) {
                $errors[] = 'Ponto do traçado inválido (esperado [lat, lng]).';

                return null;
            }
            $lat = (float) $p[0];
            $lng = (float) $p[1];
            if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0 || !is_finite($lat) || !is_finite($lng)) {
                $errors[] = 'Ponto do traçado fora dos limites de latitude/longitude.';

                return null;
            }
            $pontos[] = [round($lat, 6), round($lng, 6)];
        }

        $reservas = [];
        $rawReserves = $raw['reservas'] ?? [];
        if (!is_array($rawReserves) || !array_is_list($rawReserves) || count($rawReserves) > self::MAX_RESERVES) {
            $errors[] = 'Reservas técnicas inválidas (máximo ' . self::MAX_RESERVES . ').';

            return null;
        }
        foreach ($rawReserves as $r) {
            if (!is_array($r) || !isset($r['m']) || !is_numeric($r['m'])) {
                $errors[] = 'Reserva técnica inválida: informe local e metros.';

                return null;
            }
            $m = (float) $r['m'];
            $local = trim((string) ($r['local'] ?? ''));
            if ($m < 0 || $m > self::MAX_RESERVE_M || !is_finite($m)) {
                $errors[] = 'Reserva técnica fora da faixa (0 a ' . (int) self::MAX_RESERVE_M . ' m).';

                return null;
            }
            if (mb_strlen($local) > 60) {
                $errors[] = 'Local da reserva deve ter até 60 caracteres.';

                return null;
            }
            $item = ['local' => $local, 'm' => round($m, 2)];
            if (isset($r['pos'])) {
                $p = $r['pos'];
                if (!is_array($p) || count($p) !== 2 || !isset($p[0], $p[1]) || !is_numeric($p[0]) || !is_numeric($p[1]) || abs((float) $p[0]) > 90 || abs((float) $p[1]) > 180) {
                    $errors[] = 'Posição da reserva técnica inválida (esperado [lat, lng]).';

                    return null;
                }
                $item['pos'] = [round((float) $p[0], 6), round((float) $p[1], 6)];
            }
            $reservas[] = $item;
        }

        $out = ['pontos' => $pontos, 'reservas' => $reservas];
        if (isset($raw['folga_pct']) && $raw['folga_pct'] !== '') {
            if (!is_numeric($raw['folga_pct']) || (float) $raw['folga_pct'] < 0 || (float) $raw['folga_pct'] > self::MAX_SLACK_PCT) {
                $errors[] = 'Folga inválida (0 a ' . (int) self::MAX_SLACK_PCT . ' %).';

                return null;
            }
            $out['folga_pct'] = round((float) $raw['folga_pct'], 2);
        }
        if ($pontos === [] && $reservas === [] && !isset($out['folga_pct'])) {
            return null;
        }

        return $out;
    }

    /**
     * Lê o que veio do banco (array decodificado, lista pura legada ou null) no formato canônico, sem validar de novo.
     *
     * @return array{pontos: list<array{0: float, 1: float}>, reservas: list<array{local: string, m: float}>, folga_pct?: float}|null
     */
    public static function fromStored(mixed $stored): ?array
    {
        $errors = [];

        return is_array($stored) ? self::normalize($stored, $errors) : null;
    }

    /**
     * @param array{0: float, 1: float} $a
     * @param array{0: float, 1: float} $b
     */
    public static function haversine(array $a, array $b): float
    {
        $rad = M_PI / 180;
        $h = sin(($b[0] - $a[0]) * $rad / 2) ** 2
            + cos($a[0] * $rad) * cos($b[0] * $rad) * sin(($b[1] - $a[1]) * $rad / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($h)));
    }

    /**
     * @param list<array{0: float, 1: float}> $line pontas incluídas
     */
    public static function polylineMeters(array $line): float
    {
        $sum = 0.0;
        for ($i = 1, $n = count($line); $i < $n; $i++) {
            $sum += self::haversine($line[$i - 1], $line[$i]);
        }

        return $sum;
    }

    /**
     * Comprimento detalhado. Se $manualM (length_m) estiver preenchido, ele prevalece em "total_m" e "manual" fica true;
     * o detalhe calculado continua disponível ao lado.
     *
     * @param array{0: float, 1: float} $from
     * @param array{0: float, 1: float} $to
     * @return array{trace_m: float, slack_pct: float, slack_m: float, reserves_m: float, calculated_m: float, total_m: float, manual: bool, has_path: bool}
     */
    public static function lengths(array $from, array $to, ?array $path, ?float $manualM, float $defaultSlackPct): array
    {
        $pts = $path['pontos'] ?? [];
        $trace = self::polylineMeters([$from, ...$pts, $to]);
        $slackPct = isset($path['folga_pct']) ? (float) $path['folga_pct'] : $defaultSlackPct;
        $slack = $trace * $slackPct / 100;
        $reserves = 0.0;
        foreach ($path['reservas'] ?? [] as $r) {
            $reserves += (float) $r['m'];
        }
        $calculated = $trace + $slack + $reserves;

        return [
            'trace_m' => round($trace, 2),
            'slack_pct' => $slackPct,
            'slack_m' => round($slack, 2),
            'reserves_m' => round($reserves, 2),
            'calculated_m' => round($calculated, 2),
            'total_m' => $manualM !== null ? round($manualM, 2) : round($calculated, 2),
            'manual' => $manualM !== null,
            'has_path' => $pts !== [],
        ];
    }

    /**
     * Simplificação Douglas-Peucker (aproximação plana local), para limitar traçados importados/roteados.
     *
     * @param list<array{0: float, 1: float}> $pts
     * @return list<array{0: float, 1: float}>
     */
    public static function simplify(array $pts, float $toleranceM): array
    {
        $n = count($pts);
        if ($n < 3) {
            return $pts;
        }
        $lat0 = $pts[0][0] * M_PI / 180;
        $kx = 111320.0 * cos($lat0);
        $ky = 110540.0;
        $keep = array_fill(0, $n, false);
        $keep[0] = $keep[$n - 1] = true;
        $stack = [[0, $n - 1]];
        while ($stack !== []) {
            [$s, $e] = array_pop($stack);
            $ax = $pts[$s][1] * $kx;
            $ay = $pts[$s][0] * $ky;
            $bx = $pts[$e][1] * $kx;
            $by = $pts[$e][0] * $ky;
            $dx = $bx - $ax;
            $dy = $by - $ay;
            $len2 = $dx * $dx + $dy * $dy;
            $maxD = -1.0;
            $idx = -1;
            for ($i = $s + 1; $i < $e; $i++) {
                $px = $pts[$i][1] * $kx;
                $py = $pts[$i][0] * $ky;
                $t = $len2 > 0 ? max(0.0, min(1.0, (($px - $ax) * $dx + ($py - $ay) * $dy) / $len2)) : 0.0;
                $d = hypot($px - ($ax + $t * $dx), $py - ($ay + $t * $dy));
                if ($d > $maxD) {
                    $maxD = $d;
                    $idx = $i;
                }
            }
            if ($maxD > $toleranceM && $idx > 0) {
                $keep[$idx] = true;
                $stack[] = [$s, $idx];
                $stack[] = [$idx, $e];
            }
        }
        $out = [];
        foreach ($pts as $i => $p) {
            if ($keep[$i]) {
                $out[] = $p;
            }
        }

        return $out;
    }
}
