#!/usr/bin/env php
<?php
declare(strict_types=1);
use App\Infrastructure\Database\Database;
use App\Infrastructure\Net\NetAlertEngine;
use App\Infrastructure\Net\NetIntervention;
use App\Infrastructure\Net\NetCliOptions;
use App\Infrastructure\Net\NetDeviceLock;
use App\Infrastructure\Net\Store\PdoNetStore;
use App\Infrastructure\Net\Store\PdoNetAlertStore;
use App\Infrastructure\Net\Store\PdoNetMaintenanceStore;

require dirname(__DIR__) . '/backend/bootstrap/app.php';
$args = array_slice($argv, 1);
$intervene = ($args[0] ?? '') === 'intervene';
if ($intervene) { array_shift($args); }
try {
    $opts = NetCliOptions::parse($args, $intervene ? ['device','pon','onu','hours','reason'] : ['device'], ['dry-run']);
    $id = NetCliOptions::positive($opts, 'device', true);
    $pon = NetCliOptions::positive($opts, 'pon'); $onu = NetCliOptions::positive($opts, 'onu'); $hours = NetCliOptions::positive($opts, 'hours');
    if ($intervene && (($pon===null) === ($onu===null))) { throw new InvalidArgumentException('Escolha exatamente PON ou ONU.'); }
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, "Uso: php scripts/net_alerts.php --device=ID [--dry-run]\n     php scripts/net_alerts.php intervene --device=ID (--pon=N | --onu=ID) [--hours=24] [--reason=texto] [--dry-run]\n"); exit(1);
}
$lock = new NetDeviceLock(dirname(__DIR__) . '/tmp');
try {
    if (!$lock->acquire($id)) { fwrite(STDERR, "Equipamento ocupado.\n"); exit(3); }
    $app = bootstrapApplication();
    $pdo = (new Database($app->config()))->pdo();
    $net = new PdoNetStore($pdo);
    $summary = $intervene
        ? (new NetIntervention($net, new PdoNetMaintenanceStore($pdo)))->run($id, $pon, $onu, $hours, (string)($opts['reason'] ?? ''), isset($opts['dry-run']))
        : (new NetAlertEngine($net, new PdoNetAlertStore($pdo)))->run($id, isset($opts['dry-run']));
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) { fwrite(STDERR, "Falha ao avaliar alertas; consulte a configuração e a migration 033.\n"); exit(2); }
finally { $lock->release(); }
