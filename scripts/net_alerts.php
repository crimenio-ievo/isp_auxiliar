#!/usr/bin/env php
<?php
declare(strict_types=1);
use App\Infrastructure\Database\Database;
use App\Infrastructure\Net\NetAlertEngine;
use App\Infrastructure\Net\Store\PdoNetStore;
use App\Infrastructure\Net\Store\PdoNetAlertStore;

require dirname(__DIR__) . '/backend/bootstrap/app.php';
$opts = getopt('', ['device:', 'dry-run']);
$id = filter_var($opts['device'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) { fwrite(STDERR, "Uso: php scripts/net_alerts.php --device=ID [--dry-run]\n"); exit(1); }
$lock = fopen(dirname(__DIR__) . "/tmp/net_collect_device_{$id}.lock", 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { fwrite(STDERR, "Equipamento ocupado.\n"); exit(3); }
try {
    $app = bootstrapApplication();
    $pdo = (new Database($app->config()))->pdo();
    $summary = (new NetAlertEngine(new PdoNetStore($pdo), new PdoNetAlertStore($pdo)))->run($id, isset($opts['dry-run']));
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) { fwrite(STDERR, "Falha ao avaliar alertas; consulte a configuração e a migration 033.\n"); exit(2); }
finally { flock($lock, LOCK_UN); fclose($lock); }
