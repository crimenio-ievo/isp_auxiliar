<?php

declare(strict_types=1);

/*
 * Telemetria de fibra - coletor de potência com transporte SNMP FALSO e store em memória (sem rede, sem banco).
 * Uso: php tests/Feature/NetPowerCollectorSmoke.php
 */

use App\Infrastructure\Net\DeviceProfile;
use App\Infrastructure\Net\NetPowerCollector;
use App\Infrastructure\Net\Snmp\FakeSnmpTransport;
use App\Infrastructure\Net\Snmp\SnmpValue;
use App\Infrastructure\Net\Store\InMemoryNetStore;
use App\Infrastructure\Net\ValueDecoder;

require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';

$n = 0;
$failures = 0;
$check = static function (bool $ok, string $msg) use (&$n, &$failures): void {
    $n++;
    if (!$ok) {
        $failures++;
        echo "[FALHA] {$msg}\n";
    }
};

$sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/032_net_power.sql');
preg_match("/'(\\{\\s*\"schema\".*?\\})',\\s*'active'/s", $sql, $m);
$json = $m[1] ?? '';
$check($json !== '' && substr_count($json, ';') === 0 && substr_count($json, "'") === 0, 'perfil v3 na migration 032, sem ; nem apóstrofo');
$profile = DeviceProfile::fromJson('hioso-epon-25355-4-4-v3', $json);
$pg = $profile->powerGet();
$check(array_keys($pg) === ['onu_rx_dbm', 'onu_tx_dbm', 'onu_temp_c'] && str_ends_with($pg['onu_rx_dbm']['oid'], '.14.2.1.8') && str_ends_with($pg['onu_tx_dbm']['oid'], '.14.2.1.4') && str_ends_with($pg['onu_temp_c']['oid'], '.14.2.1.7'), 'v3: power_get com rx, tx e temperatura');
$check($profile->hasStatus() && $profile->probe() !== null && $profile->identityRecheckEvery() === 50, 'v3: mantém status, enumeração e recheck 50');
$bad = json_decode($json, true);
$bad['power_get']['onu_rx_dbm']['type'] = 'int';
try {
    DeviceProfile::fromJson('x', (string) json_encode($bad));
    $check(false, 'power_get com tipo errado deveria ser rejeitado');
} catch (InvalidArgumentException) {
    $check(true, 'power_get com tipo errado rejeitado');
}
$bad = json_decode($json, true);
$bad['power_get']['onu_rx_dbm']['oid'] = 'abc';
try {
    DeviceProfile::fromJson('x', (string) json_encode($bad));
    $check(false, 'power_get com OID inválida deveria ser rejeitado');
} catch (InvalidArgumentException) {
    $check(true, 'power_get com OID inválida rejeitado');
}
$f = static fn (string $v) => ValueDecoder::float(new SnmpValue('x', 'STRING', $v));
$check($f('-23.19') === -23.19 && $f('2.48') === 2.48 && $f(' 21.16 ') === 21.16 && $f('-5') === -5.0, 'float: valores válidos');
$check($f('na') === null && $f('') === null && $f('NA') === null && $f('abc') === null && $f('1e5') === null && $f('-23,19') === null && $f('999') === null, 'float: na, vazio, texto e absurdo viram null');

// ---------- fixtures ----------
$io = $profile->identityOids();
$identity = ['sys_object_id' => ['OID', '1.3.6.1.4.1.25355.4.4'], 'self_ip' => ['IpAddress', '192.168.0.89'],
    'engine_id' => ['Hex-STRING', '80 00 1F 88 80 27 AA 1C 32 3D F6 F2 6A'], 'uptime' => ['Timeticks', '9000000'], 'sys_name' => ['STRING', 'EPON HIOSO 1']];
$mkStore = static function () use ($json): InMemoryNetStore {
    $s = new InMemoryNetStore();
    $s->devices[7] = ['id' => 7, 'provider_id' => 1, 'map_node_id' => 42, 'enabled' => 1, 'profile_code' => 'v3', 'profile_json' => $json, 'mgmt_host' => 'x',
        'snmp_version' => '2c', 'credential_ref' => 'NET_CRED_TEST', 'expected_identity_json' => json_encode(['sys_object_id' => '1.3.6.1.4.1.25355.4.4', 'self_ip' => '192.168.0.89', 'engine_id' => '80001F888027AA1C323DF6F26A'])];
    // id => [pon, onu, status, removed]
    foreach ([1 => [1, 1, 'online', null], 2 => [1, 2, 'online', null], 3 => [1, 3, 'online', null], 4 => [2, 1, 'online', null], 5 => [2, 2, 'offline', null], 6 => [2, 3, 'online', '2026-01-01 00:00:00']] as $id => [$pon, $onu, $st, $rem]) {
        $s->onus[$id] = ['id' => $id, 'provider_id' => 1, 'device_id' => 7, 'slot' => 1, 'pon_index' => $pon, 'onu_index' => $onu, 'snmp_index' => "1.{$pon}.{$onu}",
            'onu_identifier' => sprintf('006D61%06X', $id), 'identifier_type' => 'epon_mac', 'removed_at' => $rem, 'oper_status' => $st, 'raw_status_code' => '1', 'stale' => 0, 'missing_cycles' => 0];
    }

    return $s;
};
$mib = static function (array $vals) use ($io, $identity, $pg): array {
    $d = [];
    foreach ($identity as $k => $v) {
        $d[$io[$k]] = $v;
    }
    foreach ($vals as $idx => [$rx, $tx, $tp]) {
        $d[$pg['onu_rx_dbm']['oid'] . ".{$idx}"] = ['STRING', $rx];
        $d[$pg['onu_tx_dbm']['oid'] . ".{$idx}"] = ['STRING', $tx];
        $d[$pg['onu_temp_c']['oid'] . ".{$idx}"] = ['STRING', $tp];
    }

    return $d;
};
$now = '2026-11-12 10:00:00';
$clock = static function () use (&$now): string {
    return $now;
};
$run = static function (InMemoryNetStore $s, FakeSnmpTransport $t, ?int $pon = null, ?int $onu = null, bool $dry = false) use ($clock): array {
    return (new NetPowerCollector($s, static fn () => $t, $clock))->run(7, $pon, $onu, $dry);
};
$vals = ['1.1.1' => ['-20.10', '2.40', '30.00'], '1.1.2' => ['-26.00', '2.30', '31.00'], '1.1.3' => ['-28.50', '2.20', '32.00'], '1.2.1' => ['-5.00', '2.10', '33.00'],
    '1.2.2' => ['-18.00', '2.00', '34.00'], '1.2.3' => ['-19.00', '2.00', '34.00']];

// 1) Primeiro ciclo: faixas, offline e removida fora, GETs apenas.
$s = $mkStore();
$t = new FakeSnmpTransport($mib($vals));
$r = $run($s, $t);
$check($r['result'] === 'ok' && $r['onus_lidas'] === 4 && $r['puladas'] === 1, 'ciclo 1: 4 ONUs online lidas, 1 offline pulada, removida fora');
$check($r['faixas'] === ['ok' => 1, 'atencao' => 1, 'critico' => 1, 'saturado' => 1] && $r['rx_min'] === -28.5 && $r['rx_max'] === -5.0, 'ciclo 1: faixas ok/atenção/crítico/saturado e rx mín/máx');
$check(array_diff($t->operations, ['get']) === [] && $r['requests'] === $t->requests && $r['requests'] === 4 * 3 + 5 * 2, 'ciclo 1: só GET, 3 por ONU + identidade (' . $r['requests'] . ' pedidos)');
$check(count($s->samples) === 4 && $s->samples[0]['reason'] === 'first' && $s->power[1]['baseline_n'] === 1 && $s->power[1]['baseline_rx_dbm'] === null, 'ciclo 1: amostra first, baseline_n=1, sem referência ainda');
$check(!isset($s->power[5]) && !isset($s->power[6]) && $s->power[1]['olt_rx_from_onu_dbm'] === null && $s->events === [], 'ONU offline/removida sem potência gravada, olt_rx NULL, sem evento na 1ª leitura');
$ops = array_filter($t->operations);
$check($s->power[1]['rx_band'] === 'ok' && $s->power[2]['rx_band'] === 'atencao' && $s->power[3]['rx_band'] === 'critico' && $s->power[4]['rx_band'] === 'saturado', 'faixas gravadas');

// 2) Limites exatos: -25 ok, -27 atenção, -8 ok (sat é > -8).
$th = NetPowerCollector::thresholds([]);
$check(NetPowerCollector::band(-25.0, $th) === 'ok' && NetPowerCollector::band(-25.01, $th) === 'atencao' && NetPowerCollector::band(-27.0, $th) === 'atencao' && NetPowerCollector::band(-27.01, $th) === 'critico' && NetPowerCollector::band(-8.0, $th) === 'ok' && NetPowerCollector::band(-7.99, $th) === 'saturado', 'limiares: bordas de ok/atenção/crítico/saturado');
$th2 = NetPowerCollector::thresholds(['net.rx.warn_dbm' => '-22', 'net.baseline.samples' => '3', 'net.rx.crit_dbm' => 'lixo']);
$check($th2['net.rx.warn_dbm'] === -22.0 && $th2['net.baseline.samples'] === 3 && $th2['net.rx.crit_dbm'] === -27.0, 'limiares: provider_settings sobrescreve, valor inválido cai no default');

// 3) "na" e vazio: sem leitura, nada gravado.
$s = $mkStore();
$v2 = $vals;
$v2['1.1.2'] = ['na', '0.00', '0.00'];
$v2['1.1.3'] = ['', '', ''];
$r = $run($s, new FakeSnmpTransport($mib($v2)));
$check($r['onus_lidas'] === 2 && count($r['sem_leitura']) === 2 && !isset($s->power[2]) && !isset($s->power[3]) && isset($s->power[1]), '"na"/vazio: ONU sem leitura não grava potência');

// 4) Deadband, daily, baseline por mediana (N=5) e eventos.
$s = $mkStore();
$only1 = ['1.1.1' => ['-20.00', '2.40', '30.00']];
$series = ['-20.00', '-20.50', '-19.50', '-20.20', '-19.80'];
foreach ($series as $i => $rx) {
    $now = '2026-11-12 10:0' . $i . ':00';
    $only1['1.1.1'][0] = $rx;
    $r = $run($s, new FakeSnmpTransport($mib($only1)), null, 1);
}
$check($s->power[1]['baseline_n'] === 5 && $s->power[1]['baseline_rx_dbm'] === -20.0 && count($s->samples) === 5, 'baseline: mediana das 5 primeiras leituras (-20.00), 5 amostras first');
$check($s->events === [], 'baseline: nenhum evento enquanto estável');
// Mesmo dia, variação pequena: sem amostra nova.
$now = '2026-11-12 11:00:00';
$only1['1.1.1'][0] = '-20.40';
$run($s, new FakeSnmpTransport($mib($only1)), null, 1);
$check(count($s->samples) === 5 && $s->power[1]['onu_rx_dbm'] === -20.4 && $s->power[1]['last_sample_rx_dbm'] === -19.8, 'mesmo dia, variação < deadband: atualiza net_onu_power, sem amostra');
// Deadband: >= 1,0 dB da última amostra (-19.8 -> -21.0 = 1,2). Referência -20.0: desvio de 1 dB, abaixo de 3, sem evento.
$only1['1.1.1'][0] = '-21.00';
$run($s, new FakeSnmpTransport($mib($only1)), null, 1);
$check(count($s->samples) === 6 && $s->samples[5]['reason'] === 'deadband' && $s->events === [], 'deadband: variação >= 1 dB grava amostra, sem evento');
// Dia seguinte, variação pequena: daily.
$now = '2026-11-13 08:00:00';
$only1['1.1.1'][0] = '-21.20';
$run($s, new FakeSnmpTransport($mib($only1)), null, 1);
$check(count($s->samples) === 7 && $s->samples[6]['reason'] === 'daily', 'daily: primeira leitura do dia grava amostra');
$run($s, new FakeSnmpTransport($mib($only1)), null, 1);
$check(count($s->samples) === 7, 'daily: segunda leitura do mesmo dia não repete');

// Desvio: referência -20.0, limite 3 dB. Entra em -23.5, não repete, sai ao voltar. Faixa ok -> atenção em -25.5 gera band_change.
$s->events = [];
$only1['1.1.1'][0] = '-23.50';
$run($s, new FakeSnmpTransport($mib($only1)), null, 1);
$check($s->eventTypes() === ['rx_deviation'] && $s->events[0]['details']['state'] === 'enter' && $s->events[0]['details']['delta_db'] === -3.5, 'desvio: evento de entrada (|rx - referência| >= 3 dB)');
$run($s, new FakeSnmpTransport($mib($only1)), null, 1);
$only1['1.1.1'][0] = '-23.80';
$run($s, new FakeSnmpTransport($mib($only1)), null, 1);
$check($s->eventTypes() === ['rx_deviation'], 'desvio: continua desviado sem repetir evento');
$only1['1.1.1'][0] = '-25.50';
$run($s, new FakeSnmpTransport($mib($only1)), null, 1);
$check($s->eventTypes() === ['rx_deviation', 'band_change'] && $s->events[1]['details'] === ['from' => 'ok', 'to' => 'atencao', 'rx_dbm' => -25.5], 'band_change: ok -> atenção, só na mudança');
$only1['1.1.1'][0] = '-20.30';
$run($s, new FakeSnmpTransport($mib($only1)), null, 1);
$check($s->eventTypes() === ['rx_deviation', 'band_change', 'band_change', 'rx_deviation'] && $s->events[3]['details']['state'] === 'exit', 'desvio: evento de saída ao voltar ao normal (e band_change de volta)');

// 5) Escopo --pon e --onu.
$s = $mkStore();
$t = new FakeSnmpTransport($mib($vals));
$r = $run($s, $t, 2);
$check($r['onus_lidas'] === 1 && array_keys($s->power) === [4], '--pon=2: só as ONUs online da PON 2');
$s = $mkStore();
$r = $run($s, new FakeSnmpTransport($mib($vals)), null, 3);
$check($r['onus_lidas'] === 1 && array_keys($s->power) === [3], '--onu=3: só essa ONU');
$s = $mkStore();
$r = $run($s, new FakeSnmpTransport($mib($vals)), null, 5);
$check($r['onus_lidas'] === 0 && $r['puladas'] === 1 && $s->power === [], '--onu de ONU offline: pulada, nada lido');

// 6) Dry-run: lê, não grava.
$s = $mkStore();
$r = $run($s, new FakeSnmpTransport($mib($vals)), null, null, true);
$check($r['result'] === 'ok' && $r['onus_lidas'] === 4 && $s->power === [] && $s->samples === [] && $s->events === [], 'dry-run: lê mas não grava potência, amostras nem eventos');

// 7) OLT sem resposta: nada alterado.
$s = $mkStore();
$run($s, new FakeSnmpTransport($mib($vals)));
$powerBefore = $s->power;
$samplesBefore = $s->samples;
$r = $run($s, new FakeSnmpTransport($mib($vals), static fn () => 'timeout'));
$check($r['result'] === 'timeout' && $s->power === $powerBefore && $s->samples === $samplesBefore && isset($s->power[1]), 'OLT sem resposta: nada apagado nem alterado');
$r = $run($s, new FakeSnmpTransport($mib($vals), static fn (int $n) => $n > 8 ? 'timeout' : null));
$check($r['result'] === 'partial' && $s->power === $powerBefore, '2 timeouts no meio do ciclo: descarta, nada alterado');

// 8) Identidade muda no meio / diferente da referência.
$s = $mkStore();
$r = $run($s, new FakeSnmpTransport($mib($vals), static function (int $n, string $op, string $oid, FakeSnmpTransport $f) use ($io): ?string {
    if ($n === 6) {
        $f->set($io['engine_id'], 'Hex-STRING', 'AA BB CC');
    }

    return null;
}));
$check($r['result'] === 'identity_mismatch' && $s->power === [] && $s->samples === [], 'identidade muda no meio do ciclo: nada gravado');
$s = $mkStore();
$other = $mib($vals);
$other[$io['self_ip']] = ['IpAddress', '10.0.0.9'];
$r = $run($s, new FakeSnmpTransport($other));
$check($r['result'] === 'identity_mismatch' && $r['requests'] === 5 && $s->power === [], 'identidade diferente da referência: para na identidade, nenhuma leitura de potência');
$s = $mkStore();
$s->devices[7]['expected_identity_json'] = null;
$r = $run($s, new FakeSnmpTransport($mib($vals)));
$check($r['result'] === 'identity_mismatch' && $s->power === [], 'sem identidade aprovada: não coleta');

// 9) noSuchInstance intermitente: releitura resolve.
$s = $mkStore();
$tg = $pg['onu_rx_dbm']['oid'] . '.1.1.2';
$gets = 0;
$t = new FakeSnmpTransport($mib($vals), static function (int $n, string $op, string $oid, FakeSnmpTransport $f) use ($tg, &$gets, $vals): ?string {
    if ($oid === $tg) {
        $gets++;
        if ($gets === 1) {
            $f->remove($tg);
        } else {
            $f->set($tg, 'STRING', $vals['1.1.2'][0]);
        }
    }

    return null;
});
$r = $run($s, $t);
$check($r['result'] === 'ok' && $r['onus_lidas'] === 4 && $gets === 2 && $s->power[2]['onu_rx_dbm'] === -26.0, 'noSuchInstance intermitente: relido e gravado');

// 10) ONU stale não é lida.
$s = $mkStore();
$s->onus[1]['stale'] = 1;
$r = $run($s, new FakeSnmpTransport($mib($vals)));
$check($r['onus_lidas'] === 3 && $r['puladas'] === 2 && !isset($s->power[1]), 'ONU stale: pulada');

// 11) Teto de pedidos do perfil: descarta.
$capJson = json_decode($json, true);
$capJson['pacing']['max_requests_per_cycle'] = 10;
$s = $mkStore();
$s->devices[7]['profile_json'] = json_encode($capJson);
$r = $run($s, new FakeSnmpTransport($mib($vals)));
$check($r['result'] === 'partial' && $s->power === [] && $r['requests'] === 10, 'teto de pedidos: ciclo descartado no teto');

// 12) Fonte do coletor sem OID de fabricante.
$src = (string) file_get_contents(dirname(__DIR__, 2) . '/backend/Infrastructure/Net/NetPowerCollector.php');
$check(!preg_match('/1\.3\.6\.1\.4\.1\.\d+/', $src) && stripos($src, 'hioso') === false && !str_contains($src, 'getNext'), 'coletor de potência: sem OID de fabricante e sem GETNEXT');

// Streaks saturam e sao zerados na faixa oposta, sem escrita em dry-run.
$s = $mkStore();
$badVals = ['1.1.1' => ['-28.50', '2.40', '30.00']];
$run($s, new FakeSnmpTransport($mib($badVals)), null, 1);
$run($s, new FakeSnmpTransport($mib($badVals)), null, 1);
$check($s->power[1]['bad_streak'] === 2 && $s->power[1]['ok_streak'] === 0, 'duas leituras ruins acumulam bad_streak');
$goodVals = ['1.1.1' => ['-20.00', '2.40', '30.00']];
$run($s, new FakeSnmpTransport($mib($goodVals)), null, 1);
$check($s->power[1]['bad_streak'] === 0 && $s->power[1]['ok_streak'] === 1, 'ok zera bad e incrementa ok');
$s->power[1]['ok_streak'] = 65535;
$run($s, new FakeSnmpTransport($mib($goodVals)), null, 1);
$check($s->power[1]['ok_streak'] === 65535, 'streak satura em SMALLINT');
$before = $s->power;
$run($s, new FakeSnmpTransport($mib($badVals)), null, 1, true);
$check($s->power === $before, 'dry-run preserva streaks');

echo "NetPowerCollectorSmoke: {$n} verificações, falhas: {$failures}\n";
exit($failures === 0 ? 0 : 1);
