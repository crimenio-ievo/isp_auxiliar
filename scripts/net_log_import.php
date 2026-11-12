#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Telemetria de fibra - importa o syslog da OLT (exportado pela web) para net_onu_outages (migration 034).
 * Não acessa a OLT. Simulação por padrão; grava só com --apply (uma transação + auditoria net.log_import).
 * Idempotente: reimportar o mesmo arquivo não duplica; queda em aberto é fechada se o arquivo novo tiver o retorno.
 * Com --apply, --olt-tz é obrigatório (fuso do relógio da OLT, ex.: America/Sao_Paulo); na simulação, o padrão é o
 * fuso do aplicativo e isso aparece no resultado. Imprime só MACs mascarados.
 *
 * Uso: php scripts/net_log_import.php --device=ID --file=/caminho/log.txt [--olt-tz=America/Sao_Paulo] [--apply]
 */

use App\Infrastructure\Database\Database;
use App\Infrastructure\Net\NetCliOptions;
use App\Infrastructure\Net\NetLogImporter;
use App\Infrastructure\Net\OltLogParser;
use App\Infrastructure\Net\Store\PdoNetOutageStore;
use App\Infrastructure\Net\Store\PdoNetStore;

require dirname(__DIR__) . '/backend/bootstrap/app.php';

$usage = "Uso: php scripts/net_log_import.php --device=ID --file=ARQUIVO [--olt-tz=America/Sao_Paulo] [--apply]\n";
try {
    $opts = NetCliOptions::parse(array_slice($argv, 1), ['device', 'file', 'olt-tz'], ['apply']);
    $deviceId = NetCliOptions::positive($opts, 'device', true);
    $file = (string) ($opts['file'] ?? '');
    $apply = isset($opts['apply']);
    $tz = (string) ($opts['olt-tz'] ?? date_default_timezone_get());
    if ($file === '' || !is_file($file) || !is_readable($file) || filesize($file) > 20 * 1024 * 1024) {
        throw new InvalidArgumentException('Arquivo inexistente, ilegível ou maior que 20 MB.');
    }
    if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
        throw new InvalidArgumentException('Fuso inválido.');
    }
    if ($apply && !isset($opts['olt-tz'])) {
        throw new InvalidArgumentException('--apply exige --olt-tz explícito (fuso do relógio da OLT).');
    }
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n" . $usage);
    exit(1);
}

try {
    $app = bootstrapApplication();
    $pdo = (new Database($app->config()))->pdo();
    $importer = new NetLogImporter(new PdoNetStore($pdo), new PdoNetOutageStore($pdo), new OltLogParser());
    $summary = $importer->run($deviceId, (string) file_get_contents($file), $tz, date_default_timezone_get(), $apply);
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    if (!$apply) {
        echo "Simulação: nada gravado. Rode com --apply --olt-tz=... para gravar.\n";
    }
} catch (PDOException $e) {
    fwrite(STDERR, ($e->errorInfo[0] ?? '') === '42S02' ? "A migration 034 (net_onu_outages) não está aplicada.\n" : "Falha de banco na importação.\n");
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, 'Falha na importação: ' . get_class($e) . ' (confira a migration 034 e o equipamento).' . "\n");
    exit(2);
}
