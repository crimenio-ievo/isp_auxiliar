<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

use SimpleXMLElement;
use ZipArchive;

/**
 * Le KML ou KMZ (Google My Maps / Earth) e devolve as CTOs como o MESMO CSV da importacao por CSV
 * (nome,lat,lng,capacidade,observacao), para a previa e a validacao serem identicas. Nao grava nada.
 * Cada Placemark com Point vira uma linha. Linhas, poligonos e pastas sem ponto sao contados como "ignorados".
 * Seguranca: sem DOCTYPE/entidades (XXE e "billion laughs"), sem rede, limite de tamanho (inclusive descompactado).
 */
final class KmlCtoReader
{
    public const MAX_BYTES = 2097152;       // arquivo enviado
    public const MAX_XML_BYTES = 5242880;   // KML descompactado

    /** @return array{ok:bool,erro:?string,csv:string,pontos:int,ignorados:int} */
    public static function toCsv(string $bytes): array
    {
        $fail = static fn (string $e): array => ['ok' => false, 'erro' => $e, 'csv' => '', 'pontos' => 0, 'ignorados' => 0];
        if ($bytes === '') {
            return $fail('O arquivo está vazio.');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            return $fail('Arquivo grande demais (máximo ' . intdiv(self::MAX_BYTES, 1048576) . ' MB).');
        }
        $xml = $bytes;
        if (str_starts_with($bytes, "PK\x03\x04")) {
            $xml = self::unzip($bytes, $err);
            if ($xml === null) {
                return $fail($err);
            }
        }
        $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml) ?? $xml;
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return $fail('KML recusado: declarações DOCTYPE/ENTITY não são permitidas.');
        }
        $prev = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        if (!$doc instanceof SimpleXMLElement) {
            return $fail('Não foi possível ler o arquivo: KML/XML inválido.');
        }
        $placemarks = $doc->xpath("//*[local-name()='Placemark']");
        if ($placemarks === false || $placemarks === []) {
            return $fail('Nenhum Placemark encontrado no arquivo.');
        }

        $rows = [];
        $ignored = 0;
        foreach ($placemarks as $pm) {
            $coords = $pm->xpath(".//*[local-name()='Point']/*[local-name()='coordinates']");
            if ($coords === false || $coords === []) {
                $ignored++;
                continue;
            }
            $parts = array_map('trim', explode(',', trim((string) $coords[0])));
            $lng = $parts[0] ?? '';
            $lat = $parts[1] ?? '';
            $name = self::text($pm->xpath("./*[local-name()='name']"));
            $desc = self::plain(self::text($pm->xpath("./*[local-name()='description']")));
            $cap = '';
            foreach ($pm->xpath(".//*[local-name()='Data']|.//*[local-name()='SimpleData']") ?: [] as $d) {
                $key = mb_strtolower(trim((string) ($d['name'] ?? '')));
                if (in_array($key, ['capacidade', 'capacity', 'portas'], true)) {
                    $v = $d->xpath("./*[local-name()='value']");
                    $cap = trim($v !== false && $v !== [] ? (string) $v[0] : (string) $d);
                    break;
                }
            }
            $rows[] = [$name, $lat, $lng, $cap, $desc];
        }
        if ($rows === []) {
            return $fail('Nenhum ponto (Placemark com Point) encontrado: linhas e polígonos não são importados como CTO.');
        }

        // CSV com aspas e sem quebras de linha dentro dos campos (o analisador do CSV le linha a linha).
        $out = "nome,lat,lng,capacidade,observacao\n";
        foreach ($rows as $r) {
            $out .= implode(',', array_map(static fn (string $c): string => '"' . str_replace('"', '""', preg_replace('/[\r\n\t]+/', ' ', $c) ?? $c) . '"', $r)) . "\n";
        }

        return ['ok' => true, 'erro' => null, 'csv' => $out, 'pontos' => count($rows), 'ignorados' => $ignored];
    }

    private static function unzip(string $bytes, ?string &$error): ?string
    {
        if (!class_exists(ZipArchive::class)) {
            $error = 'Este servidor não lê KMZ (extensão zip ausente). Exporte como KML.';

            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'kmz');
        if ($tmp === false) {
            $error = 'Não foi possível ler o KMZ.';

            return null;
        }
        try {
            file_put_contents($tmp, $bytes);
            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) {
                $error = 'KMZ inválido (não é um arquivo zip).';

                return null;
            }
            $pick = null;
            for ($i = 0; $i < min($zip->numFiles, 200); $i++) {
                $st = $zip->statIndex($i);
                if ($st === false || !preg_match('/\.kml$/i', $st['name']) || str_contains($st['name'], '..')) {
                    continue;
                }
                if ($pick === null || strcasecmp(basename($st['name']), 'doc.kml') === 0) {
                    $pick = $st;
                }
            }
            if ($pick === null) {
                $error = 'KMZ sem arquivo .kml dentro.';

                return null;
            }
            if ($pick['size'] > self::MAX_XML_BYTES) {
                $error = 'KML dentro do KMZ é grande demais.';

                return null;
            }
            $content = $zip->getFromName($pick['name'], self::MAX_XML_BYTES + 1);
            $zip->close();
            if ($content === false || strlen($content) > self::MAX_XML_BYTES) {
                $error = 'Não foi possível ler o KML dentro do KMZ.';

                return null;
            }

            return $content;
        } finally {
            @unlink($tmp);
        }
    }

    /** @param list<SimpleXMLElement>|false|null $nodes */
    private static function text(array|false|null $nodes): string
    {
        return $nodes === false || $nodes === null || $nodes === [] ? '' : trim((string) $nodes[0]);
    }

    private static function plain(string $html): string
    {
        $t = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return mb_strlen($t) > 500 ? mb_substr($t, 0, 500) : $t;
    }
}
