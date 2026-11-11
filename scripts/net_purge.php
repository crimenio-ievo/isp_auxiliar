#!/usr/bin/env php
<?php
declare(strict_types=1);
use App\Infrastructure\Database\Database;
use App\Infrastructure\Net\NetCliOptions;
use App\Infrastructure\Net\NetPurge;
use App\Infrastructure\Net\Store\PdoNetStore;
use App\Infrastructure\Net\Store\PdoNetMaintenanceStore;

require dirname(__DIR__) . '/backend/bootstrap/app.php';
try { $opts = NetCliOptions::parse(array_slice($argv,1), [], ['apply']); }
catch (InvalidArgumentException $e) { fwrite(STDERR, "Uso: php scripts/net_purge.php [--apply] (simulacao por padrao)\n"); exit(1); }
try {
    $app = bootstrapApplication();
    $pdo = (new Database($app->config()))->pdo();
    echo json_encode((new NetPurge(new PdoNetStore($pdo), new PdoNetMaintenanceStore($pdo)))->run(isset($opts['apply'])), JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) { fwrite(STDERR, "Falha na retencao; verifique a migration 033.\n"); exit(2); }
