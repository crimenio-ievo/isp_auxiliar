<?php
declare(strict_types=1);
// B1: chave de coleta por servidor (NET_COLLECTOR_ENABLED) e por equipamento (enabled + intervalos), em schema descartavel.
// Nenhum equipamento e contatado (NetTick em dry-run; o script roda com a chave desligada e com banco inalcancavel).
$root = dirname(__DIR__, 2);
require $root . '/backend/bootstrap/autoload.php';
require $root . '/tests/support/ScratchSchema.php';

use App\Infrastructure\Net\NetAlertEngine;
use App\Infrastructure\Net\NetCollectorSwitch;
use App\Infrastructure\Net\NetDeviceLock;
use App\Infrastructure\Net\NetTick;
use App\Infrastructure\Net\NetTickAll;
use App\Infrastructure\Net\Store\PdoNetAlertStore;
use App\Infrastructure\Net\Store\PdoNetStore;
use Tests\Support\ScratchSchema;

$n = 0;
$check = static function (bool $ok, string $m) use (&$n): void { $n++; if (!$ok) { throw new RuntimeException("FALHA: {$m}"); } };

// ---- chave por servidor: interpretacao ----
foreach (['1', 'true', 'ON', ' sim ', 'ligado', 'yes'] as $v) { $check(NetCollectorSwitch::enabled($v, true) === true, "ligada: '{$v}'"); }
foreach (['0', 'false', 'off', 'nao', 'não', 'desligado', 'NO'] as $v) { $check(NetCollectorSwitch::enabled($v, false) === false, "desligada: '{$v}'"); }
$check(NetCollectorSwitch::enabled('', false) === true && NetCollectorSwitch::enabled('lixo', false) === true, 'ausente/invalido FORA da producao: liga (o teste continua coletando sem mexer no .env)');
$check(NetCollectorSwitch::enabled('', true) === false && NetCollectorSwitch::enabled('talvez', true) === false, 'ausente/invalido EM producao: desligada (precisa ligar de proposito)');

// ---- log "coleta desativada neste servidor": no maximo 1 linha por hora ----
$dir = sys_get_temp_dir() . '/net-switch-' . bin2hex(random_bytes(6));
$check(NetCollectorSwitch::logDisabled($dir, '2026-11-19 10:00:00') === true, 'grava a primeira linha');
$check(NetCollectorSwitch::logDisabled($dir, '2026-11-19 10:01:00') === false && NetCollectorSwitch::logDisabled($dir, '2026-11-19 10:59:00') === false, 'o cron roda a cada minuto: nao repete dentro de 1 h');
$check(NetCollectorSwitch::logDisabled($dir, '2026-11-19 11:00:01') === true, 'depois de 1 h grava de novo');
$check(NetCollectorSwitch::logDisabled($dir, '2026-11-20 00:00:00') === true && is_file($dir . '/net_tick-2026-11-20.log'), 'dia novo: arquivo novo');
$lines = array_map(static fn (string $l): array => json_decode($l, true), file($dir . '/net_tick-2026-11-19.log', FILE_IGNORE_NEW_LINES));
$check(count($lines) === 2 && $lines[0]['result'] === 'collector_disabled' && $lines[0]['message'] === 'coleta desativada neste servidor', 'linha registra result e a mensagem esperada');
$check(NetCollectorSwitch::logDisabled('/proc/nao/pode/criar', '2026-11-19 10:00:00') === false, 'log indisponivel nao lanca (cron nao pode quebrar)');
array_map('unlink', glob($dir . '/*') ?: []); rmdir($dir);

// ---- script real net_tick.php com a chave desligada: sai antes do banco e de qualquer equipamento ----
$envFile = $root . '/.env';
if (is_file($envFile)) {
    echo "[PULADO] script com .env temporario: ja existe um .env aqui (nao sobrescrevo)\n";
} else {
    $logDir = $root . '/storage/logs';
    $logFile = $logDir . '/net_tick-' . date('Y-m-d') . '.log';
    $hadLog = is_file($logFile);
    try {
        // banco INALCANCAVEL (porta 1): se o script tocasse no banco, sairia com erro 2
        file_put_contents($envFile, "DB_HOST=127.0.0.1\nDB_PORT=1\nDB_DATABASE=nao_existe\nDB_USERNAME=x\nDB_PASSWORD=\nNET_COLLECTOR_ENABLED=0\n");
        $run = static function (string $script, string $args) use ($root): array {
            $out = []; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/' . $script) . ' ' . $args . ' 2>&1', $out, $code);
            return [$code, implode("\n", $out)];
        };
        foreach (['--all', '--device=1'] as $a) {
            [$code, $out] = $run('net_tick.php', $a);
            $j = json_decode($out, true);
            $check($code === 0 && ($j['result'] ?? '') === 'collector_disabled' && ($j['message'] ?? '') === 'coleta desativada neste servidor', "net_tick {$a} com a chave desligada: exit 0, nao toca o banco nem equipamento");
        }
        [$code, $out] = $run('net_collect.php', '--device=1 --kind=status --dry-run');
        $check($code === 4 && str_contains($out, 'coleta desativada neste servidor'), 'net_collect com a chave desligada: recusa (exit 4)');
        $check(is_file($logFile) && str_contains((string) file_get_contents($logFile), 'collector_disabled'), 'registra "coleta desativada neste servidor" no log do dia');
        file_put_contents($envFile, "DB_HOST=127.0.0.1\nDB_PORT=1\nDB_DATABASE=nao_existe\nDB_USERNAME=x\nDB_PASSWORD=\nNET_COLLECTOR_ENABLED=1\n");
        [$code] = $run('net_tick.php', '--all');
        $check($code === 2, 'chave ligada: o script segue e tenta o banco (aqui inalcancavel => exit 2): a chave e a unica diferenca');
    } finally {
        @unlink($envFile);
        if (!$hadLog) { @unlink($logFile); }
    }
}

// ---- por equipamento (B1b): coletar sim/nao e intervalo, gravados no banco; --all respeita ----
$schema = (new ScratchSchema($root, 'collector'))->create();
try {
    $pdo = $schema->pdo;
    $pid = $schema->providerId();
    if ($pid < 1) { $pdo->exec("INSERT INTO providers (name,slug,status,created_at,updated_at) VALUES ('P','p','active',NOW(),NOW())"); $pid = (int) $pdo->lastInsertId(); }
    $pdo->exec("INSERT INTO net_device_profiles (code,vendor,technology,version,profile_json,status,created_at,updated_at) VALUES ('p','X','epon',1,'{}','active',NOW(),NOW())");
    $prof = (int) $pdo->lastInsertId();
    foreach ([1, 2, 3] as $i) {
        $pdo->exec("INSERT INTO map_nodes (id,provider_id,node_type,name,created_at,updated_at) VALUES ($i,$pid,'olt','OLT-$i',NOW(),NOW())");
    }
    $pdo->exec("INSERT INTO net_devices (id,provider_id,map_node_id,profile_id,mgmt_host,credential_ref,enabled,status_interval_s,power_interval_s,created_at,updated_at) VALUES
        (1,$pid,1,$prof,'192.0.2.1','NET_CRED_X',1,600,1800,NOW(),NOW()),
        (2,$pid,2,$prof,'192.0.2.2','NET_CRED_X',0,600,1800,NOW(),NOW()),
        (3,$pid,3,$prof,'192.0.2.3','NET_CRED_X',1,60,300,NOW(),NOW())");
    $check(NetTickAll::enabledIds($pdo) === [1, 3], '--all so percorre os equipamentos com coleta ativa (o 2 esta desativado)');
    $pdo->exec('UPDATE net_devices SET enabled = 0 WHERE id = 3');
    $check(NetTickAll::enabledIds($pdo) === [1], 'desativar pela tela (enabled=0) tira da lista do proximo ciclo');
    $pdo->exec('UPDATE net_devices SET enabled = 1 WHERE id IN (2,3)');
    $check(NetTickAll::enabledIds($pdo) === [1, 2, 3], 'ativar volta a entrar');

    // intervalos por equipamento: NetTick (dry-run, sem contato) decide o vencimento com o intervalo de CADA um
    $now = '2026-11-19 12:10:00';
    foreach ([1, 3] as $id) {
        $pdo->exec("INSERT INTO net_device_poll_state (device_id,last_attempt_at,last_success_at,last_result,consecutive_failures,last_power_attempt_at,updated_at) VALUES ($id,'2026-11-19 12:07:00','2026-11-19 12:07:00','ok',0,'2026-11-19 12:07:00','$now')");
    }
    $net = new PdoNetStore($pdo);
    $tmp = sys_get_temp_dir() . '/net-switch-lock-' . bin2hex(random_bytes(4)); mkdir($tmp, 0700);
    $tick = new NetTick($net, new NetAlertEngine($net, new PdoNetAlertStore($pdo)), static fn () => throw new RuntimeException('nao deve abrir transporte'), new NetDeviceLock($tmp), null, static fn (): string => $now);
    $d1 = $tick->run(1, true);   // ultima tentativa ha 180 s; status a cada 600 s, potencia a cada 1800 s
    $d3 = $tick->run(3, true);   // mesma idade; status a cada 60 s, potencia a cada 300 s
    $check($d1['result'] === 'dry_run' && $d1['due']['status'] === false && $d1['due']['power'] === false, 'intervalo 600/1800 s: ainda nao venceu (180 s)');
    $check($d3['due']['status'] === true && $d3['due']['power'] === false, 'intervalo 60/300 s: status venceu e potencia ainda nao: cada equipamento usa o proprio intervalo');
    $pdo->exec('UPDATE net_devices SET status_interval_s = 120 WHERE id = 1');
    $check($tick->run(1, true)['due']['status'] === true, 'mudar o intervalo no banco vale no proximo ciclo (sem tocar no cron)');
    $pdo->exec('UPDATE net_devices SET enabled = 0 WHERE id = 3');
    $r3 = $tick->run(3, true);
    $check($r3['result'] === 'error' && !isset($r3['status']), 'equipamento desativado nunca e coletado, nem por --device');
    array_map('unlink', glob($tmp . '/*') ?: []); @rmdir($tmp);
} finally {
    $schema->drop();
}
echo "NetCollectorSwitchSmoke: {$n} verificacoes, zero falhas\n";
