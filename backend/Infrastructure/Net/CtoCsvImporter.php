<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

/**
 * Importacao de CTOs por CSV: SOMENTE analisa (previa linha a linha). Quem grava e o controller, depois da confirmacao.
 * Colunas: nome, lat, lng, capacidade, observacao (cabecalho opcional; separador "," ou ";"; decimal com ponto ou virgula).
 * Status por linha: ok | erro | duplicado (nome ja existe, no banco ou no proprio arquivo) | proximo (< 15 m de outra CTO; so entra se forcado).
 */
final class CtoCsvImporter
{
    public const MAX_ROWS = 500;
    public const MAX_BYTES = 262144;
    public const DEFAULT_CAPACITY = 16;
    private const HEADERS = [
        'nome' => 'nome', 'name' => 'nome', 'cto' => 'nome',
        'lat' => 'lat', 'latitude' => 'lat',
        'lng' => 'lng', 'lon' => 'lng', 'long' => 'lng', 'longitude' => 'lng',
        'capacidade' => 'capacidade', 'portas' => 'capacidade', 'capacity' => 'capacidade',
        'observacao' => 'observacao', 'obs' => 'observacao', 'observacoes' => 'observacao', 'notas' => 'observacao',
    ];

    /**
     * @param list<array{nome:string,lat:?float,lng:?float}> $existing CTOs ja cadastradas
     * @return array{ok:bool,erro:?string,linhas:list<array<string,mixed>>,resumo:array<string,int>}
     */
    public static function analyze(string $csv, array $existing): array
    {
        $empty = ['ok' => false, 'erro' => null, 'linhas' => [], 'resumo' => ['total' => 0, 'ok' => 0, 'erro' => 0, 'duplicado' => 0, 'proximo' => 0]];
        if (strlen($csv) > self::MAX_BYTES) {
            return ['erro' => 'Arquivo grande demais (máximo ' . intdiv(self::MAX_BYTES, 1024) . ' KB).'] + $empty;
        }
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        if (!mb_check_encoding($csv, 'UTF-8')) {
            // Planilhas antigas salvam em Windows-1252: converte so quando o arquivo nao e UTF-8 valido.
            $csv = (string) mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
        }
        $lines = preg_split('/\r\n|\n|\r/', $csv) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $l): bool => trim($l) !== ''));
        if ($lines === []) {
            return ['erro' => 'O arquivo está vazio.'] + $empty;
        }
        $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';

        $first = array_map(static fn (?string $c): string => self::norm((string) $c), str_getcsv($lines[0], $delimiter, '"', ''));
        $hasHeader = count(array_intersect(array_keys(self::HEADERS), $first)) >= 2 || ($first[0] ?? '') === 'nome';
        $columns = ['nome', 'lat', 'lng', 'capacidade', 'observacao'];
        $lineNo = 1;
        if ($hasHeader) {
            $map = [];
            foreach ($first as $i => $h) {
                if (isset(self::HEADERS[$h])) {
                    $map[self::HEADERS[$h]] = $i;
                }
            }
            foreach (['nome', 'lat', 'lng'] as $required) {
                if (!isset($map[$required])) {
                    return ['erro' => 'Cabeçalho sem a coluna obrigatória "' . $required . '". Use: nome, lat, lng, capacidade, observacao.'] + $empty;
                }
            }
            array_shift($lines);
            $lineNo = 2;
        } else {
            $map = array_flip($columns);
        }
        if (count($lines) > self::MAX_ROWS) {
            return ['erro' => 'Linhas demais (máximo ' . self::MAX_ROWS . ' por arquivo).'] + $empty;
        }

        $taken = [];   // nomes ja usados (minusculos)
        foreach ($existing as $e) {
            $taken[mb_strtolower(trim($e['nome']))] = true;
        }
        $points = [];  // coordenadas ja ocupadas (existentes + linhas anteriores do arquivo)
        foreach ($existing as $e) {
            if ($e['lat'] !== null && $e['lng'] !== null) {
                $points[] = [$e['nome'], (float) $e['lat'], (float) $e['lng']];
            }
        }

        $rows = [];
        $summary = ['total' => 0, 'ok' => 0, 'erro' => 0, 'duplicado' => 0, 'proximo' => 0];
        foreach ($lines as $i => $line) {
            $cells = str_getcsv($line, $delimiter, '"', '');
            $get = static fn (string $k): string => isset($map[$k], $cells[$map[$k]]) ? trim((string) $cells[$map[$k]]) : '';
            $row = ['linha' => $lineNo + $i, 'nome' => $get('nome'), 'lat' => null, 'lng' => null, 'capacidade' => null, 'observacao' => $get('observacao'), 'status' => 'ok', 'mensagens' => []];
            $errors = [];
            if ($row['nome'] === '' || mb_strlen($row['nome']) > 120 || preg_match('/[\x00-\x1F\x7F]/', $row['nome']) === 1) {
                $errors[] = 'Nome obrigatório (até 120 caracteres).';
            }
            $lat = Coordinates::parseNumber($get('lat'), 90.0);
            $lng = Coordinates::parseNumber($get('lng'), 180.0);
            if ($lat === null || $lng === null || !Coordinates::validRange($lat, $lng)) {
                $errors[] = 'Coordenadas inválidas (lat entre -90 e 90, lng entre -180 e 180).';
            } else {
                $row['lat'] = $lat;
                $row['lng'] = $lng;
            }
            $cap = $get('capacidade');
            if ($cap === '') {
                $row['capacidade'] = self::DEFAULT_CAPACITY;
                $row['mensagens'][] = 'Capacidade em branco: será ' . self::DEFAULT_CAPACITY . '.';
            } elseif (preg_match('/^[0-9]{1,3}$/', $cap) !== 1 || (int) $cap < 1 || (int) $cap > 144) {
                $errors[] = 'Capacidade inválida (1 a 144).';
            } else {
                $row['capacidade'] = (int) $cap;
            }
            if (mb_strlen($row['observacao']) > 2000) {
                $errors[] = 'Observação acima de 2000 caracteres.';
            }

            if ($errors !== []) {
                $row['status'] = 'erro';
                $row['mensagens'] = $errors;
            } else {
                $key = mb_strtolower($row['nome']);
                if (isset($taken[$key])) {
                    $row['status'] = 'duplicado';
                    $row['mensagens'][] = 'Já existe uma CTO com este nome (no mapa ou em linha anterior do arquivo).';
                } else {
                    $near = [];
                    foreach ($points as [$name, $plat, $plng]) {
                        $d = Coordinates::meters($lat, $lng, $plat, $plng);
                        if ($d < NetCtoRepository::NEAR_METERS) {
                            $near[] = $name . ' (' . number_format($d, 0, ',', '.') . ' m)';
                        }
                    }
                    if ($near !== []) {
                        $row['status'] = 'proximo';
                        $row['mensagens'][] = 'A menos de ' . (int) NetCtoRepository::NEAR_METERS . ' m de: ' . implode(', ', array_slice($near, 0, 3)) . '. Só entra se você marcar "importar mesmo assim".';
                    }
                    $taken[$key] = true;
                    $points[] = [$row['nome'], $lat, $lng];
                }
            }
            $summary['total']++;
            $summary[$row['status']]++;
            $rows[] = $row;
        }

        return ['ok' => true, 'erro' => null, 'linhas' => $rows, 'resumo' => $summary];
    }

    private static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['ç' => 'c', 'ã' => 'a', 'á' => 'a', 'à' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u']);

        return $s;
    }
}
