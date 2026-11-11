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
use App\Infrastructure\Net\NetStatusCollector;
use App\Infrastructure\Net\NetTransportFactory;
use App\Infrastructure\Net\Store\PdoNetStore;

require dirname(__DIR__) . '/backend/bootstrap/app.php';

$opts = getopt('', ['device:', 'kind:', 'dry-run', 'approve-identity']);
$deviceId = isset($opts['device']) && ctype_digit((string) $opts['device']) ? (int) $opts['device'] : 0;
$kind = (string) ($opts['kind'] ?? '');
if ($deviceId < 1 || $kind !== 'status') {
    fwrite(STDERR, "Uso: php scripts/net_collect.php --device=ID --kind=status [--dry-run] [--approve-identity]\n");
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
$collector = new NetStatusCollector(new PdoNetStore($pdo), new NetTransportFactory());

$summary = $collector->run($deviceId, $approve, $dryRun);
$summary['kind'] = $kind;
$summary['finished_at'] = date('Y-m-d H:i:s');

$logDir = $root . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}
@file_put_contents($logDir . '/net_collect-' . date('Y-m-d') . '.log', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

echo 'Telemetria - ciclo de status do equipamento ' . $deviceId . ($dryRun ? ' (SIMULAÇÃO: nada gravado)' : '') . "\n";
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
foreach ($summary['warnings'] ?? [] as $w) {
    echo "AVISO: {$w}\n";
}
flock($lock, LOCK_UN);
exit($summary['result'] === 'ok' ? 0 : 2);
