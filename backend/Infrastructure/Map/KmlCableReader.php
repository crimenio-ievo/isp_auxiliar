<?php

declare(strict_types=1);

namespace App\Infrastructure\Map;

use App\Infrastructure\Net\KmlCtoReader;

/**
 * Lê as LineString (e MultiGeometry com linhas) de um KML/KMZ como candidatos a cabo. Não grava nada.
 * Mesmas proteções do leitor de CTOs (KmlCtoReader::document): sem DOCTYPE/entidades, sem rede, limites de tamanho.
 */
final class KmlCableReader
{
    public const MAX_LINES = 500;

    /** @return array{ok: bool, erro: ?string, linhas: list<array{nome: string, pontos: list<array{0: float, 1: float}>}>, ignorados: int} */
    public static function lines(string $bytes): array
    {
        $loaded = KmlCtoReader::document($bytes);
        if ($loaded['doc'] === null) {
            return ['ok' => false, 'erro' => $loaded['erro'], 'linhas' => [], 'ignorados' => 0];
        }
        $placemarks = $loaded['doc']->xpath("//*[local-name()='Placemark']");
        $out = [];
        $ignored = 0;
        foreach ($placemarks ?: [] as $pm) {
            $strings = $pm->xpath(".//*[local-name()='LineString']/*[local-name()='coordinates']");
            if ($strings === false || $strings === []) {
                $ignored++;   // ponto, polígono ou pasta sem linha
                continue;
            }
            $nameNodes = $pm->xpath("./*[local-name()='name']");
            $name = trim($nameNodes !== false && $nameNodes !== [] ? (string) $nameNodes[0] : '');
            foreach ($strings as $i => $coordNode) {
                $pts = [];
                foreach (preg_split('/\s+/', trim((string) $coordNode)) ?: [] as $tuple) {
                    $p = explode(',', $tuple);
                    if (count($p) < 2 || !is_numeric($p[0]) || !is_numeric($p[1])) {
                        continue;
                    }
                    $lat = (float) $p[1];
                    $lng = (float) $p[0];
                    if (abs($lat) > 90 || abs($lng) > 180) {
                        continue;
                    }
                    $pts[] = [round($lat, 6), round($lng, 6)];
                }
                $out[] = ['nome' => mb_substr($name, 0, 100), 'pontos' => $pts];
                if (count($out) > self::MAX_LINES) {
                    return ['ok' => false, 'erro' => 'Linhas demais no arquivo (máximo ' . self::MAX_LINES . ').', 'linhas' => [], 'ignorados' => 0];
                }
            }
        }
        if ($out === []) {
            return ['ok' => false, 'erro' => 'Nenhuma linha (LineString) encontrada: pontos e polígonos não viram cabo.', 'linhas' => [], 'ignorados' => $ignored];
        }

        return ['ok' => true, 'erro' => null, 'linhas' => $out, 'ignorados' => $ignored];
    }
}
