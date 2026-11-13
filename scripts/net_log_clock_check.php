#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Telemetria de fibra - confere o relógio da OLT comparando o syslog exportado com os down/up da coleta (net_onu_events, UTC).
 * SOMENTE LEITURA (sessão do banco em READ ONLY; não acessa a OLT). MACs mascarados.
 * Para cada ONU com queda no log E par down->up da coleta, casa a queda do log mais próxima de (início da coleta - deslocamento
 * esperado) e imprime a diferença. A coleta tem precisão de um ciclo de status (padrão 600 s): poucos pares = sem conclusão.
 * Resultado em "deslocamento" = (hora da coleta, UTC) - (hora do log lida como UTC).
 *   0 = relógio da OLT em UTC | 10800 (3 h) = em Brasília (UTC-3) | outro valor = relógio da OLT errado/ajustado.
 *
 * Uso: php scripts/net_log_clock_check.php --device=ID --file=ARQUIVO [--expect=SEGUNDOS] [--tolerance=SEGUNDOS]
 */

use App\Infrastructure\Database\Database;
use App\Infrastructure\Net\NetCliOptions;
use App\Infrastructure\Net\OltLogParser;
use App\Infrastructure\Net\ValueDecoder;

require dirname(__DIR__) . '/backend/bootstrap/app.php';

try {
    $o = NetCliOptions::parse(array_slice($argv, 1), ['device', 'file', 'expect', 'tolerance'], []);
    $deviceId = NetCliOptions::positive($o, 'device', true);
    $file = (string) ($o['file'] ?? '');
    if (!is_file($file) || !is_readable($file)) {
        throw new InvalidArgumentException('Arquivo ilegível.');
    }
    $expect = isset($o['expect']) ? (int) $o['expect'] : 0;
    $tol = isset($o['tolerance']) ? max(60, (int) $o['tolerance']) : 3 * 3600;
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\nUso: php scripts/net_log_clock_check.php --device=ID --file=ARQUIVO [--expect=SEGUNDOS] [--tolerance=SEGUNDOS]\n");
    exit(1);
}
$app = bootstrapApplication();
$pdo = (new Database($app->config()))->pdo();
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$ts = static fn (string $s): int => (int) strtotime($s . ' UTC');
$fmt = static function (int $s): string { $sg = $s < 0 ? '-' : ''; $s = abs($s); return sprintf('%s%dh%02dm', $sg, intdiv($s, 3600), intdiv($s % 3600, 60)); };

$outages = array_values(array_filter((new OltLogParser())->parse((string) file_get_contents($file))['outages'], static fn (array $x): bool => $x['counts_as_outage'] && $x['ended_at'] !== null));
$st = $pdo->prepare('SELECT id, onu_identifier FROM net_onus WHERE device_id = ?');
$st->execute([$deviceId]);
$mac = $st->fetchAll(PDO::FETCH_KEY_PAIR);
$st = $pdo->prepare("SELECT e.onu_id, e.event_type, e.occurred_at FROM net_onu_events e JOIN net_onus o ON o.id = e.onu_id WHERE o.device_id = ? AND e.event_type IN ('up','down') ORDER BY e.onu_id, e.occurred_at, e.id");
$st->execute([$deviceId]);
$events = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) { $events[(int) $e['onu_id']][] = $e; }

$deltas = [];
foreach ($events as $id => $list) {
    $open = null;
    foreach ($list as $e) {
        if ($e['event_type'] === 'down') { $open = $e; continue; }
        if ($open === null) { continue; }
        $downAt = $open['occurred_at']; $pd = $ts($downAt); $pu = $ts($e['occurred_at']); $open = null;
        $best = null;
        foreach ($outages as $q) {
            if (str_replace(':', '', $q['mac']) !== ($mac[$id] ?? '')) { continue; }
            $d = $pd - $ts($q['started_at']);
            if (abs($d - $expect) < $tol && ($best === null || abs($d - $expect) < abs($best['d'] - $expect))) { $best = ['q' => $q, 'd' => $d]; }
        }
        if ($best === null) { continue; }
        $q = $best['q'];
        $deltas[] = $best['d'];
        printf("%s coleta %s -> %s (%d min) | log %s -> %s (%d min) | deslocamento %s\n", ValueDecoder::maskMac((string) $mac[$id]), $downAt, substr($e['occurred_at'], 11), intdiv($pu - $pd, 60), $q['started_at'], substr($q['ended_at'], 11), intdiv($ts($q['ended_at']) - $ts($q['started_at']), 60), $fmt($best['d']));
    }
}
if ($deltas === []) { echo "Nenhum par casado: sem conclusão.\n"; exit(0); }
sort($deltas);
printf("pares: %d | mínimo %s | mediana %s | máximo %s (deslocamento = coleta UTC - log lido como UTC)\n", count($deltas), $fmt($deltas[0]), $fmt($deltas[intdiv(count($deltas), 2)]), $fmt($deltas[count($deltas) - 1]));
echo "Precisão da coleta: um ciclo de status. Poucos pares ou valores espalhados: não concluir.\n";
