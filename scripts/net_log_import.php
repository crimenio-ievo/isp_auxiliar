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
 * Janelas de manutenção (horário do relógio da OLT, como no log): a padrão é a troca de PON de 2026-11-09 20:23:00 a 20:26:59.
 * --maintenance-window="INICIO,FIM[,motivo]" (repetível) ACRESCENTA janelas à padrão; --no-default-window remove a padrão.
 * Quedas cujo linkdown cai numa janela (ou até 2 min após "EPON System Start") são gravadas como manutenção, fora da contagem.
 *
 * Uso: php scripts/net_log_import.php --device=ID --file=/caminho/log.txt [--olt-tz=America/Sao_Paulo] [--apply]
 *        [--maintenance-window="2026-11-09 20:23:00,2026-11-09 20:26:59,troca_pon" ...] [--no-default-window]
 */

use App\Infrastructure\Database\Database;
use App\Infrastructure\Net\NetCliOptions;
use App\Infrastructure\Net\NetLogImporter;
use App\Infrastructure\Net\OltLogParser;
use App\Infrastructure\Net\Store\PdoNetOutageStore;
use App\Infrastructure\Net\Store\PdoNetStore;

require dirname(__DIR__) . '/backend/bootstrap/app.php';

$usage = "Uso: php scripts/net_log_import.php --device=ID --file=ARQUIVO [--olt-tz=America/Sao_Paulo] [--apply]\n       [--maintenance-window=\"INICIO,FIM[,motivo]\" ...] [--no-default-window]\n";
try {
    $args = array_slice($argv, 1);
    $windowSpecs = NetCliOptions::extractRepeatable($args, 'maintenance-window');
    $opts = NetCliOptions::parse($args, ['device', 'file', 'olt-tz'], ['apply', 'no-default-window']);
    $windows = isset($opts['no-default-window']) ? [] : OltLogParser::DEFAULT_WINDOWS;
    if (count($windowSpecs) > OltLogParser::MAX_WINDOWS) {
        throw new InvalidArgumentException('Janelas demais (máximo ' . OltLogParser::MAX_WINDOWS . ').');
    }
    foreach ($windowSpecs as $spec) {
        $windows[] = OltLogParser::windowFromString($spec);
    }
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
    $importer = new NetLogImporter(new PdoNetStore($pdo), new PdoNetOutageStore($pdo), new OltLogParser($windows));
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
