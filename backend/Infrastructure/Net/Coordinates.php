<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

/**
 * Coordenadas: aceita "lat, lng" colado do Google Maps (ponto ou virgula decimal), valida a faixa e mede distancias.
 */
final class Coordinates
{
    /**
     * @return array{0: float, 1: float}|null null quando nao e um par valido
     */
    public static function parsePair(string $text): ?array
    {
        $text = trim($text);
        if ($text === '' || strlen($text) > 80) {
            return null;
        }
        $text = str_replace(["\u{00A0}", ';'], [' ', ' '], $text);
        // "-20.84, -42.80" (virgula separa) | "-20.84 -42.80" | "-20,84 -42,80" (virgula decimal, espaco separa)
        if (preg_match('/^([+-]?\d{1,3}(?:[.,]\d+)?)\s*,\s*([+-]?\d{1,3}(?:[.,]\d+)?)$/', $text, $m) === 1
            && !(str_contains($m[1], ',') || str_contains($m[2], ','))) {
            [$a, $b] = [$m[1], $m[2]];
        } elseif (preg_match('/^([+-]?\d{1,3}(?:[.,]\d+)?)\s+([+-]?\d{1,3}(?:[.,]\d+)?)$/', $text, $m) === 1) {
            [$a, $b] = [$m[1], $m[2]];
        } else {
            return null;
        }

        return self::validRange((float) str_replace(',', '.', $a), (float) str_replace(',', '.', $b))
            ? [round((float) str_replace(',', '.', $a), 7), round((float) str_replace(',', '.', $b), 7)]
            : null;
    }

    public static function validRange(float $lat, float $lng): bool
    {
        if (abs($lat) > 90 || abs($lng) > 180) {
            return false;
        }

        return !($lat === 0.0 && $lng === 0.0);
    }

    /** Numero unico ("-20,84" ou "-20.84") dentro da faixa, ou null. */
    public static function parseNumber(string $text, float $limit): ?float
    {
        $text = trim($text);
        if (preg_match('/^[+-]?\d{1,3}(?:[.,]\d+)?$/', $text) !== 1) {
            return null;
        }
        $v = (float) str_replace(',', '.', $text);

        return abs($v) <= $limit ? round($v, 7) : null;
    }

    /** Distancia em metros (haversine). */
    public static function meters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $rad = M_PI / 180;
        $h = sin(($lat2 - $lat1) * $rad / 2) ** 2 + cos($lat1 * $rad) * cos($lat2 * $rad) * sin(($lng2 - $lng1) * $rad / 2) ** 2;

        return 2 * 6371000 * asin(min(1.0, sqrt($h)));
    }
}
