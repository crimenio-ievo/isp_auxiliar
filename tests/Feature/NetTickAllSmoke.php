<?php
declare(strict_types=1);
// Fase 2: `net_tick.php --all` (NetTickAll + validacao de argumentos do script). Sem rede, sem banco real, sem tocar no cron.
require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';
use App\Infrastructure\Net\DeviceProfile;
use App\Infrastructure\Net\NetAlertEngine;
use App\Infrastructure\Net\NetDeviceLock;
use App\Infrastructure\Net\NetTick;
use App\Infrastructure\Net\NetTickAll;
use App\Infrastructure\Net\Snmp\FakeSnmpTransport;
use App\Infrastructure\Net\Store\InMemoryNetAlertStore;
use App\Infrastructure\Net\Store\InMemoryNetStore;

$n = 0;
$check = static function (bool $ok, string $m) use (&$n): void { $n++; if (!$ok) { throw new RuntimeException("FALHA: {$m}"); } };
$root = dirname(__DIR__, 2);

// --- Argumentos do script (sai antes de qualquer banco/SNMP) ---
$run = static function (string $args) use ($root): array {
    $out = []; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/net_tick.php') . ' ' . $args . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};
foreach (['', '--all --device=1', '--device=1 --all', '--all --x', '--all=1', '--device=abc', '--all --all', '--device=0'] as $args) {
    [$code, $out] = $run($args);
    $check($code === 1 && str_contains($out, '--device=ID|--all'), "argumentos invalidos '{$args}' -> uso e exit 1");
}

// --- NetTickAll (callables) ---
$ran = [];
$all = new NetTickAll(static fn (): array => [3, 1, 2, 2, 0, -5], static function (int $id) use (&$ran): array { $ran[] = $id; return ['result' => $id === 1 ? 'timeout' : 'ok', 'secret' => 'nao-deve-sair']; });
$r = $all->run();
$check($ran === [3, 1, 2], 'um por equipamento, sem repetir nem ids invalidos, na ordem recebida');
$check($r['result'] === 'partial' && $r['counts'] === ['ok' => 2, 'timeout' => 1] && $r['mode'] === 'all', 'falha de um marca o resumo como partial');
$check(!str_contains(json_encode($r), 'nao-deve-sair'), 'resumo so traz id e resultado');
$ran = [];
$r = (new NetTickAll(static fn (): array => [1, 2, 3], static function (int $id) use (&$ran): array { $ran[] = $id; if ($id === 2) { throw new RuntimeException('detalhe-interno-sensivel'); } return ['result' => 'ok']; }))->run();
$check($ran === [1, 2, 3] && $r['devices'][1] === ['device_id' => 2, 'result' => 'error'] && $r['result'] === 'partial', 'excecao em um equipamento nao para os outros');
$check(!str_contains(json_encode($r), 'detalhe-interno'), 'mensagem da excecao nunca sai no resumo');
$r = (new NetTickAll(static fn (): array => [], static fn (int $id): array => ['result' => 'ok']))->run();
$check($r['result'] === 'ok' && $r['devices'] === [], 'sem equipamento ativo: ok e vazio');
$r = (new NetTickAll(static fn (): array => [1, 2], static fn (int $id): array => ['result' => $id === 1 ? 'locked' : 'idle']))->run();
$check($r['result'] === 'ok' && $r['counts'] === ['locked' => 1, 'idle' => 1], 'locked e idle nao sao falha');

// --- Integracao com NetTick real: um lock por equipamento, um falho nao para o outro ---
$now = '2026-11-12 12:00:00';
$clock = static function () use (&$now) { return $now; };
$dir = sys_get_temp_dir() . '/net-tick-all-' . bin2hex(random_bytes(6)); mkdir($dir, 0700);
$sql = file_get_contents($root . '/database/migrations/032_net_power.sql');
preg_match("/'(\{\s*\"schema\".*?\})',\s*'active'/s", $sql, $m);
$data = json_decode($m[1], true); $data['enumeration']['probe']['pons'] = [1]; $data['enumeration']['probe']['max_onu'] = 2;
$json = json_encode($data); $profile = DeviceProfile::fromJson('fake', $json);
$mib = [];
$identity = ['sys_object_id' => ['OID', '1.3.6.1.4.1.25355.4.4'], 'self_ip' => ['IpAddress', '198.51.100.1'], 'engine_id' => ['Hex-STRING', 'AA BB'], 'uptime' => ['Timeticks', '9000000'], 'sys_name' => ['STRING', 'TESTE']];
foreach ($profile->identityOids() as $k => $oid) { $mib[$oid] = $identity[$k]; }
$mib[$profile->metric('onu_identifier')['oid'] . '.1.1.1'] = ['STRING', '001122334455'];
$mib[$profile->metric('oper_status')['oid'] . '.1.1.1'] = ['INTEGER', '200'];
foreach ($profile->powerGet() as $k => $metric) { $mib[$metric['oid'] . '.1.1.1'] = ['STRING', $k === 'onu_rx_dbm' ? '-20.00' : '2.00']; }
try {
    $net = new InMemoryNetStore();
    foreach ([1, 2, 3] as $id) {
        $net->devices[$id] = ['id' => $id, 'provider_id' => 1, 'enabled' => $id === 3 ? 0 : 1, 'status_interval_s' => 600, 'power_interval_s' => 1800, 'profile_code' => 'fake', 'profile_json' => $json, 'mgmt_host' => '198.51.100.' . $id, 'credential_ref' => 'NET_CRED_FAKE',
            'expected_identity_json' => json_encode(['sys_object_id' => '1.3.6.1.4.1.25355.4.4', 'self_ip' => '198.51.100.1', 'engine_id' => 'AABB'])];
        $net->pollStates[$id] = ['last_result' => 'ok', 'consecutive_failures' => 0, 'last_attempt_at' => '2026-11-12 11:00:00', 'last_power_attempt_at' => '2026-11-12 11:59:00'];
    }
    $alerts = new InMemoryNetAlertStore($net);
    $hosts = [];
    $factory = static function (array $device) use ($mib, &$hosts) {
        $hosts[] = $device['id'];
        // O equipamento 1 nao responde; o 2 responde normalmente.
        return new FakeSnmpTransport($mib, (int) $device['id'] === 1 ? static fn () => 'timeout' : null);
    };
    $tick = new NetTick($net, new NetAlertEngine($net, $alerts, $clock), $factory, new NetDeviceLock($dir), $dir, $clock);
    $enabled = static fn (): array => array_keys(array_filter($net->devices, static fn (array $d): bool => (int) $d['enabled'] === 1));
    $r = (new NetTickAll($enabled, static fn (int $id): array => $tick->run($id)))->run();
    $check($hosts === [1, 2] && $r['devices'][0]['result'] === 'timeout' && $r['devices'][1]['result'] === 'ok' && $r['result'] === 'partial', 'equipamento 1 em timeout nao impede o 2; o desativado (3) nao e visitado');
    // Lock do equipamento 1 em uso: so ele e pulado.
    $hosts = [];
    $net->pollStates[1]['last_attempt_at'] = '2026-11-12 11:00:00'; $net->pollStates[2]['last_attempt_at'] = '2026-11-12 11:00:00';
    $net->pollStates[1]['consecutive_failures'] = 0;
    $busy = new NetDeviceLock($dir);
    $check($busy->acquire(1), 'lock do equipamento 1 em uso');
    $r = (new NetTickAll($enabled, static fn (int $id): array => $tick->run($id)))->run();
    $check($r['devices'][0]['result'] === 'locked' && $r['devices'][1]['result'] === 'ok' && $hosts === [2], 'um lock por equipamento: o ocupado e pulado, o outro roda');
    $busy->release();
    $logs = glob($dir . '/net_tick-*.log');
    $lines = array_filter(explode("\n", (string) file_get_contents($logs[0])));
    $check(count($lines) >= 2 && str_contains($lines[0], '"device_id":1'), 'cada equipamento grava a sua linha de log (mesmo formato do --device)');
} finally {
    array_map('unlink', glob($dir . '/*') ?: []); @rmdir($dir);
}
echo "NetTickAllSmoke: {$n} verificacoes, zero falhas\n";
