#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Telemetria de fibra - coletor de status (inventário de ONUs + online/offline) de UM equipamento.
 * Somente leitura no equipamento (SNMP GET/GETNEXT, uma OID por requisição, ritmo do perfil).
 *
 * Uso: php scripts/net_collect.php --device=ID --kind=status [--dry-run] [--approve-identity]
 *   --dry-run           lê o equipamento, mas não grava nada no banco (nem o estado de coleta)
 *   --approve-identity  grava a identidade lida como referência quando o equipamento ainda não tem uma
 *
 * Saída: resumo no terminal + uma linha JSON por ciclo em storage/logs/net_collect-AAAA-MM-DD.log (sem segredos).
 * Códigos de saída: 0 ok; 1 uso inválido; 2 ciclo sem sucesso; 3 outro ciclo do mesmo equipamento em andamento.
 * Cron sugerido (NÃO instalado; sem o espaço entre "*" e "/5", que aqui fecharia o comentário): * /5 * * * * www-data php /caminho/scripts/net_collect.php --device=ID --kind=status
 */

use App\Infrastructure\Database\Database;
use App\Infrastructure\Net\NetPowerCollector;
use App\Infrastructure\Net\NetStatusCollector;
use App\Infrastructure\Net\NetCredentialRepository;
use App\Infrastructure\Net\NetSecretBox;
use App\Infrastructure\Net\NetTransportFactory;
use App\Infrastructure\Net\Store\PdoNetStore;

require dirname(__DIR__) . '/backend/bootstrap/app.php';

$opts = getopt('', ['device:', 'kind:', 'dry-run', 'approve-identity', 'pon:', 'onu:']);
$deviceId = isset($opts['device']) && ctype_digit((string) $opts['device']) ? (int) $opts['device'] : 0;
$kind = (string) ($opts['kind'] ?? '');
$pon = isset($opts['pon']) && ctype_digit((string) $opts['pon']) ? (int) $opts['pon'] : null;
$onuId = isset($opts['onu']) && ctype_digit((string) $opts['onu']) ? (int) $opts['onu'] : null;
if ($deviceId < 1 || !in_array($kind, ['status', 'power'], true) || (isset($opts['pon']) && $pon === null) || (isset($opts['onu']) && $onuId === null)) {
    fwrite(STDERR, "Uso: php scripts/net_collect.php --device=ID --kind=status [--dry-run] [--approve-identity]\n     php scripts/net_collect.php --device=ID --kind=power [--pon=N] [--onu=ID_NET_ONUS] [--dry-run]\n");
    exit(1);
}
$dryRun = isset($opts['dry-run']);
$approve = isset($opts['approve-identity']);

$root = dirname(__DIR__);
$lockDir = $root . '/tmp';
$lock = fopen($lockDir . "/net_collect_device_{$deviceId}.lock", 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Outro ciclo do equipamento {$deviceId} está em andamento; nada feito.\n");
    exit(3);
}

$app = bootstrapApplication();
$pdo = (new Database($app->config()))->pdo();
$store = new PdoNetStore($pdo);
if ($kind === 'power') {
    $summary = (new NetPowerCollector($store, new NetTransportFactory(new NetCredentialRepository($pdo, NetSecretBox::fromEnv()))))->run($deviceId, $pon, $onuId, $dryRun);
} else {
    $summary = (new NetStatusCollector($store, new NetTransportFactory(new NetCredentialRepository($pdo, NetSecretBox::fromEnv()))))->run($deviceId, $approve, $dryRun);
}
$summary['kind'] = $kind;
$summary['finished_at'] = date('Y-m-d H:i:s');

$logDir = $root . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}
@file_put_contents($logDir . '/net_collect-' . date('Y-m-d') . '.log', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

echo 'Telemetria - ciclo de ' . ($kind === 'power' ? 'potência' : 'status') . ' do equipamento ' . $deviceId . ($dryRun ? ' (SIMULAÇÃO: nada gravado)' : '') . "\n";
echo 'Resultado: ' . $summary['result'] . (isset($summary['error']) ? ' - ' . $summary['error'] : '') . "\n";
echo 'Requisições SNMP: ' . ($summary['requests'] ?? 0) . ' | duração: ' . ($summary['cycle_ms'] ?? 0) . " ms\n";
if (isset($summary['identity'])) {
    $id = $summary['identity'];
    echo 'Identidade: sysObjectID=' . ($id['sys_object_id'] ?? '?') . ' ip=' . ($id['self_ip'] ?? '?') . ' engineID=' . ($id['engine_id'] ?? '?') . ' uptime=' . ($id['uptime'] ?? '?') . ' sysName=' . ($id['sys_name'] ?? '?') . "\n";
}
if (!empty($summary['identity_approved'])) {
    echo $dryRun ? "Identidade seria aprovada como referência.\n" : "Identidade aprovada e gravada como referência.\n";
}
if (isset($summary['per_pon'])) {
    echo 'Baseline: ' . (!empty($summary['baseline']) ? 'sim' : 'não') . ' | ONUs vistas: ' . $summary['onus_seen'] . "\n";
    foreach ($summary['per_pon'] as $pon => $c) {
        echo "  PON {$pon}: {$c['total']} ONUs (online {$c['online']}, offline {$c['offline']}, desconhecido {$c['unknown']})\n";
    }
    echo 'Eventos: ' . json_encode($summary['events']) . "\n";
    if (empty($summary['status_validated'])) {
        echo "Status: NÃO validado no perfil (todas as ONUs 'desconhecido'; nenhum evento up/down é gerado).\n";
    }
    if (!empty($summary['rescued_by_get'])) {
        echo 'ONUs confirmadas por GET direto (ausentes do walk): ' . $summary['rescued_by_get'] . "\n";
    }
}
if ($kind === 'power' && $summary['result'] === 'ok') {
    echo "ONUs lidas: {$summary['onus_lidas']} | puladas (não online): {$summary['puladas']} | sem leitura: " . count($summary['sem_leitura']) . "\n";
    echo 'Faixas: ' . json_encode($summary['faixas']) . ' | rx mín/máx: ' . ($summary['rx_min'] ?? '-') . ' / ' . ($summary['rx_max'] ?? '-') . "\n";
    echo 'Amostras: ' . $summary['amostras'] . ' | eventos: ' . json_encode($summary['eventos'] ?: new stdClass()) . "\n";
}
foreach ($summary['warnings'] ?? [] as $w) {
    echo "AVISO: {$w}\n";
}
flock($lock, LOCK_UN);
exit($summary['result'] === 'ok' ? 0 : 2);
