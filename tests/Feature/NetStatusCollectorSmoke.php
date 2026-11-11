<?php

declare(strict_types=1);

/*
 * Telemetria de fibra - coletor de status com transporte SNMP FALSO e store em memória (sem rede, sem banco).
 * O perfil usado é o perfil semente da migration 030 (garante que o JSON gravado é válido e suficiente).
 * Uso: php tests/Feature/NetStatusCollectorSmoke.php
 */

use App\Infrastructure\Net\DeviceProfile;
use App\Infrastructure\Net\MacMatcher;
use App\Infrastructure\Net\NetStatusCollector;
use App\Infrastructure\Net\Snmp\FakeSnmpTransport;
use App\Infrastructure\Net\Snmp\NetSnmpCliTransport;
use App\Infrastructure\Net\Snmp\SnmpTimeoutException;
use App\Infrastructure\Net\Snmp\SnmpUnreachableException;
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

// ---------- perfil semente (extraído da migration) ----------
$sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/030_net_telemetry_core.sql');
preg_match("/'(\\{\\s*\"schema\".*?\\})',\\s*'active'/s", $sql, $m);
$seedJson = $m[1] ?? '';
$check($seedJson !== '', 'perfil semente encontrado na migration');
$check(!str_contains($sql, ';' . "\n" . '      "') && substr_count($seedJson, ';') === 0, 'perfil semente sem ponto e vírgula (o aplicador separa por ;)');
$seed = DeviceProfile::fromJson('hioso-epon-25355-4-4-v1', $seedJson);
$seedData = json_decode($seedJson, true);
$check(!$seed->hasStatus() && $seed->statusWalk() === ['onu_identifier'] && !isset($seedData['metrics']['oper_status']), 'perfil semente: sem status (coluna 13 não é status), só identificador no walk');
$check(!str_contains($seedJson, '"3": "online"') && !str_contains($seedJson, 'distance_raw') && isset($seedData['pending_metrics']['rtt_raw']), 'perfil semente: sem o mapa 3/5 errado, rtt_raw no lugar de distance_raw');
$check(!isset($seedData['metrics']['distance_m']) && !isset($seedData['metrics']['offline_reason']) && in_array('offline_reason', $seedData['not_identified'], true), 'perfil semente: motivo da queda e DeregisterCnt não identificados (fora das métricas)');
$check(($seedData['pending_metrics']['oper_status_candidate']['validated'] ?? null) === false, 'perfil semente: coluna 39 marcada como candidata NÃO validada');
// Variante SINTÉTICA com status (OID/códigos fictícios): exercita up/down/stale sem reaproveitar mapa de fabricante.
$statusData = $seedData;
$statusData['status_walk'][] = 'oper_status';
$statusData['metrics']['oper_status'] = ['oid' => '1.3.6.1.4.1.99999.3.2.1.7', 'type' => 'int_enum', 'map' => ['1' => 'online', '2' => 'offline'], 'default' => 'unknown'];
$profileJson = (string) json_encode($statusData);
$profile = DeviceProfile::fromJson('perfil-teste-com-status', $profileJson);
$check($profile->hasStatus(), 'perfil sintético: com status e mapa de códigos');
$check($profile->identifierType() === 'epon_mac', 'perfil: identifier_type epon_mac');
$check($profile->indexParts() === ['slot', 'pon', 'onu'], 'perfil: índice split_dots slot.pon.onu');
$check($profile->decodeIndex('1.2.17') === ['slot' => 1, 'pon' => 2, 'onu' => 17], 'perfil: decodifica 1.2.17');
$check($profile->decodeIndex('1.2') === null && $profile->decodeIndex('1.x.3') === null, 'perfil: índice malformado => null');
$check($profile->intervalMs() === 500 && $profile->timeoutMs() === 3000 && $profile->abortAfterConsecutiveTimeouts() === 2, 'perfil: ritmo 500 ms, timeout 3 s, 2 timeouts');

$bad = json_decode($profileJson, true);
$bad['pacing']['operations'] = ['get', 'getnext', 'set'];
try {
    DeviceProfile::fromJson('x', (string) json_encode($bad));
    $check(false, 'perfil com SET deveria ser rejeitado');
} catch (InvalidArgumentException) {
    $check(true, 'perfil com SET rejeitado');
}
$bad = json_decode($profileJson, true);
unset($bad['metrics']['oper_status']['map']);
try {
    DeviceProfile::fromJson('x', (string) json_encode($bad));
    $check(false, 'oper_status sem mapa deveria ser rejeitado');
} catch (InvalidArgumentException) {
    $check(true, 'oper_status sem mapa de códigos rejeitado');
}
$bad = json_decode($profileJson, true);
unset($bad['metrics']['oper_status']['oid']);
try {
    DeviceProfile::fromJson('x', (string) json_encode($bad));
    $check(false, 'perfil sem OID de status deveria ser rejeitado');
} catch (InvalidArgumentException) {
    $check(true, 'perfil sem OID de status rejeitado');
}

// ---------- fixtures ----------
$io = $profile->identityOids();
$colId = (string) $profile->metric('onu_identifier')['oid'];
$colSt = (string) $profile->metric('oper_status')['oid'];
$identity = ['sys_object_id' => ['OID', '1.3.6.1.4.1.25355.4.4'], 'self_ip' => ['IpAddress', '192.168.0.89'],
    'engine_id' => ['Hex-STRING', '80 00 1F 88 80 27 AA 1C 32 3D F6 F2 6A'], 'uptime' => ['Timeticks', '8183900'], 'sys_name' => ['STRING', 'EPON HIOSO 1']];
$mac = static fn (int $pon, int $onu): string => sprintf('006d61%02x%04x', $pon, $onu);
/** @param array<string,array{0:int,1:int,2:int}> $onus chave = mac => [pon, onu, código] */
$mib = static function (array $onus, array $idOverride = [], bool $withStatus = true) use ($io, $identity, $colId, $colSt): array {
    $d = [];
    foreach (array_merge($identity, $idOverride) as $k => $v) {
        $d[$io[$k]] = $v;
    }
    foreach ($onus as $m => [$pon, $onu, $code]) {
        $d["{$colId}.1.{$pon}.{$onu}"] = ['STRING', $m];
        if ($withStatus) {
            $d["{$colSt}.1.{$pon}.{$onu}"] = ['INTEGER', (string) $code];
        }
    }
    // Vizinhos fora das colunas, para o walk precisar parar sozinho.
    $d['1.3.6.1.4.1.25355.3.2.6.3.2.1.10.1.1.1'] = ['STRING', ''];
    $d['1.3.6.1.4.1.25355.3.2.6.3.2.1.14.1.1.1'] = ['STRING', '0000'];

    return $d;
};
// PON1: 23 ONUs (20 online, 3 offline), PON2: 5, PON3: 2, PON4: 0
$base = [];
for ($i = 1; $i <= 23; $i++) {
    $base[$mac(1, $i)] = [1, $i, $i <= 20 ? 1 : 2];
}
for ($i = 1; $i <= 5; $i++) {
    $base[$mac(2, $i)] = [2, $i, 1];
}
$base[$mac(3, 1)] = [3, 1, 1];
$base[$mac(3, 2)] = [3, 2, 2];

$tick = 0;
$clock = static function () use (&$tick): string {
    $tick++;

    return date('Y-m-d H:i:s', 1_800_000_000 + $tick * 300);
};
$newStore = static function (?string $json = null) use ($profileJson): InMemoryNetStore {
    $s = new InMemoryNetStore();
    $s->devices[7] = ['id' => 7, 'provider_id' => 1, 'map_node_id' => 42, 'enabled' => 1, 'profile_code' => 'perfil-teste',
        'profile_json' => $json ?? $profileJson, 'mgmt_host' => '192.168.0.89', 'snmp_version' => '2c', 'credential_ref' => 'NET_CRED_TEST', 'expected_identity_json' => null];

    return $s;
};
/** @var FakeSnmpTransport|null $last */
$last = null;
$run = static function (InMemoryNetStore $store, FakeSnmpTransport $t, bool $approve = false, bool $dry = false) use (&$last, $clock): array {
    $last = $t;

    return (new NetStatusCollector($store, static fn () => $t, $clock))->run(7, $approve, $dry);
};
$uptime = 8183900;
$up = static function () use (&$uptime): array {
    $uptime += 30000;

    return ['uptime' => ['Timeticks', (string) $uptime]];
};

// 1) Sem identidade aprovada e sem --approve-identity: nada gravado.
$s = $newStore();
$r = $run($s, new FakeSnmpTransport($mib($base, $up())));
$check($r['result'] === 'identity_mismatch' && $s->onus === [] && $s->events === [], 'sem referência e sem aprovação: nada de ONU gravado');
$check(($s->pollStates[7]['last_result'] ?? '') === 'identity_mismatch', 'sem referência: estado de coleta registra identity_mismatch');

// 2) Baseline com --approve-identity.
$r = $run($s, new FakeSnmpTransport($mib($base, $up())), true);
$check($r['result'] === 'ok' && $r['baseline'] === true, 'baseline: ciclo ok marcado como baseline');
$check(count($s->onus) === 30 && $s->events === [], 'baseline: 30 ONUs conhecidas, nenhum evento');
$check($r['per_pon'][1] === ['total' => 23, 'online' => 20, 'offline' => 3, 'unknown' => 0], 'baseline: PON 1 com 23 (20 online, 3 offline)');
$check(!isset($r['per_pon'][4]) && $r['per_pon'][2]['total'] === 5 && $r['per_pon'][3]['offline'] === 1, 'baseline: contagem por PON');
$check(json_decode((string) $s->devices[7]['expected_identity_json'], true) === ['sys_object_id' => '1.3.6.1.4.1.25355.4.4', 'self_ip' => '192.168.0.89', 'engine_id' => '80001F888027AA1C323DF6F26A'], 'baseline: referência gravada (sysObjectID, IP próprio, engineID)');
$o = $s->onuByIdentifier(strtoupper($mac(1, 21)));
$check($o !== null && $o['oper_status'] === 'offline' && $o['raw_status_code'] === '2' && $o['pon_index'] === 1 && $o['onu_index'] === 21 && $o['snmp_index'] === '1.1.21', 'baseline: MAC normalizado em maiúsculas, índice e status bruto');
$check(array_diff($last->operations, ['get', 'getnext']) === [], 'transporte: só GET e GETNEXT');
$check($r['requests'] <= 300 && $r['requests'] === $last->requests, 'transporte: requisições dentro do teto e contadas');
$check($s->pollStates[7]['last_result'] === 'ok' && $s->pollStates[7]['consecutive_failures'] === 0, 'baseline: estado de coleta ok');

// 3) ONU nova.
$cur = $base;
$cur[$mac(4, 1)] = [4, 1, 1];
$r = $run($s, new FakeSnmpTransport($mib($cur, $up())));
$check($r['result'] === 'ok' && $r['baseline'] === false && $s->eventTypes() === ['new'], 'ONU nova: um evento new');
$check($s->events[0]['details']['pon'] === 4 && $s->events[0]['details']['onu'] === 1, 'ONU nova: evento com PON/ONU');

// 4) Queda e volta.
$s->events = [];
$cur[$mac(1, 5)] = [1, 5, 2];
$r = $run($s, new FakeSnmpTransport($mib($cur, $up())));
$check($s->eventTypes() === ['down'] && $s->onuByIdentifier(strtoupper($mac(1, 5)))['oper_status'] === 'offline', 'queda: evento down e status offline');
$cur[$mac(1, 5)] = [1, 5, 1];
$run($s, new FakeSnmpTransport($mib($cur, $up())));
$check($s->eventTypes() === ['down', 'up'], 'volta: evento up');

// 5) OLT sem resposta: stale, sem down, 2 timeouts seguidos.
$s->events = [];
$before = array_map(static fn ($o) => $o['oper_status'], $s->onus);
$t = new FakeSnmpTransport($mib($cur, $up()), static fn () => 'timeout');
$r = $run($s, $t);
$check($r['result'] === 'timeout' && $t->requests === 2, 'OLT sem resposta: aborta após 2 timeouts seguidos');
$check($s->events === [] && array_map(static fn ($o) => $o['oper_status'], $s->onus) === $before, 'OLT sem resposta: nenhuma ONU vira offline, sem eventos');
$check(count(array_filter($s->onus, static fn ($o) => (int) $o['stale'] === 1)) === 31, 'OLT sem resposta: todas as ONUs marcadas stale');
$check($s->pollStates[7]['last_result'] === 'timeout' && $s->pollStates[7]['consecutive_failures'] === 1, 'OLT sem resposta: falha contada');
$r = $run($s, new FakeSnmpTransport($mib($cur, $up()), static fn () => 'unreachable'));
$check($r['result'] === 'timeout' && str_contains($r['error'], 'unreachable') && $s->pollStates[7]['consecutive_failures'] === 2, 'ICMP unreachable: aborta na hora, tratado como sem resposta');
$cur[$mac(2, 3)] = [2, 3, 2];
$r = $run($s, new FakeSnmpTransport($mib($cur, $up())));
$check($r['result'] === 'ok' && count(array_filter($s->onus, static fn ($o) => (int) $o['stale'] === 1)) === 0, 'volta da OLT: stale limpo');
$check($s->eventTypes() === ['down'] && $s->events[0]['details']['after_stale'] === true, 'volta da OLT: queda ocorrida no apagão registrada com after_stale');

// 6) Um timeout isolado é tolerado (2º pedido responde).
$s->events = [];
$t = new FakeSnmpTransport($mib($cur, $up()), static fn (int $i) => $i === 10 ? 'timeout' : null);
$r = $run($s, $t);
$check($r['result'] === 'ok' && $s->events === [], 'um timeout isolado: repete o pedido e conclui');

// 7) Identidade divergente.
$snap = $s->onus;
$r = $run($s, new FakeSnmpTransport($mib($cur, $up() + ['engine_id' => ['Hex-STRING', '80 00 1F 88 80 00 00 00 00 00 00 00 01']])));
$check($r['result'] === 'identity_mismatch' && str_contains($r['error'], 'engine_id') && $s->onus === $snap && $s->events === [], 'identidade divergente (engineID): nada gravado');
$r = $run($s, new FakeSnmpTransport($mib($cur, $up() + ['self_ip' => ['IpAddress', '192.168.0.88']])), true);
$check($r['result'] === 'identity_mismatch' && $s->onus === $snap, 'identidade divergente (IP próprio): --approve-identity não sobrescreve referência');

// 8) Identidade muda durante o ciclo (sysName) e uptime diminui durante o ciclo.
$t = new FakeSnmpTransport($mib($cur, $up()), static function (int $i, string $op, string $oid, FakeSnmpTransport $f) use ($io): ?string {
    if ($i === 40) {
        $f->set($io['sys_name'], 'STRING', 'OUTRA OLT');
    }

    return null;
});
$r = $run($s, $t);
$check($r['result'] === 'identity_mismatch' && str_contains($r['error'], 'durante o ciclo') && $s->onus === $snap, 'sysName mudou no meio do ciclo: descartado');
$t = new FakeSnmpTransport($mib($cur, $up()), static function (int $i, string $op, string $oid, FakeSnmpTransport $f) use ($io): ?string {
    if ($i === 40) {
        $f->set($io['uptime'], 'Timeticks', '100');
    }

    return null;
});
$r = $run($s, $t);
$check($r['result'] === 'identity_mismatch' && str_contains($r['error'], 'uptime') && $s->onus === $snap, 'uptime diminuiu no meio do ciclo: descartado');

// 9) Walk parcial.
$t = new FakeSnmpTransport($mib($cur, $up()), static fn (int $i) => $i >= 30 ? 'timeout' : null);
$r = $run($s, $t);
$check($r['result'] === 'partial' && $s->onus === $snap && $s->events === [], 'walk interrompido por timeouts: ciclo descartado (partial)');
$check(count(array_filter($s->onus, static fn ($o) => (int) $o['stale'] === 1)) === 0, 'walk parcial: não marca stale nem offline');
$d = $mib($cur, $up());
unset($d["{$colSt}.1.2.4"]);
$r = $run($s, new FakeSnmpTransport($d));
$check($r['result'] === 'partial' && str_contains($r['error'], 'índices diferentes') && $s->onus === $snap, 'colunas incoerentes (status faltando): partial');
$small = json_decode($profileJson, true);
$small['pacing']['max_requests_per_cycle'] = 20;
$s2 = $newStore();
$s2->devices[7]['profile_json'] = (string) json_encode($small);
$r = (new NetStatusCollector($s2, static fn () => new FakeSnmpTransport($mib($cur, $up())), $clock))->run(7, true);
$check($r['result'] === 'partial' && str_contains($r['error'], 'teto') && $s2->onus === [] && $r['requests'] === 20, 'teto de requisições: para no teto e descarta');

// 10) Reinício da OLT entre ciclos.
$uptime = 500;
$r = $run($s, new FakeSnmpTransport($mib($cur, ['uptime' => ['Timeticks', '600']])));
$check($r['result'] === 'ok' && $r['restart_detected'] === true && str_contains((string) $s->pollStates[7]['last_error'], 'reinício'), 'reinício da OLT (uptime menor): registrado e ciclo aceito');
$check((int) $s->pollStates[7]['last_uptime_ticks'] === 600, 'reinício: novo uptime guardado');
$uptime = 1000;

// 11) Status desconhecido.
$s->events = [];
$cur[$mac(1, 7)] = [1, 7, 7];
$r = $run($s, new FakeSnmpTransport($mib($cur, $up())));
$o = $s->onuByIdentifier(strtoupper($mac(1, 7)));
$check($o['oper_status'] === 'unknown' && $o['raw_status_code'] === '7' && $s->events === [], 'status desconhecido: unknown com código bruto, sem up/down');
$check((bool) array_filter($r['warnings'], static fn ($w) => str_contains($w, 'código bruto 7')), 'status desconhecido: aviso no resumo');
$cur[$mac(1, 7)] = [1, 7, 1];
$run($s, new FakeSnmpTransport($mib($cur, $up())));
$check($s->events === [], 'unknown -> online: sem evento up');

// 12) Mesmo MAC em outro índice (moved) e identificador inválido.
$m6 = $mac(1, 6);
unset($cur[$m6]);
$cur[$m6] = [2, 9, 1];
$cur['000000000000'] = [3, 9, 1];
$r = $run($s, new FakeSnmpTransport($mib($cur, $up())));
$o = $s->onuByIdentifier(strtoupper($m6));
$check($s->eventTypes() === ['moved'] && $o['pon_index'] === 2 && $o['onu_index'] === 9 && $s->events[0]['details']['from']['snmp_index'] === '1.1.6', 'mesmo MAC em outro índice: moved');
$check((bool) array_filter($r['warnings'], static fn ($w) => str_contains($w, 'identificador inválido')), 'MAC zerado ignorado com aviso');
unset($cur['000000000000']);

// 13) Removida só após 3 ciclos ausente.
$s->events = [];
$gone = $mac(3, 2);
unset($cur[$gone]);
for ($i = 1; $i <= 3; $i++) {
    $run($s, new FakeSnmpTransport($mib($cur, $up())));
    $o = $s->onuByIdentifier(strtoupper($gone));
    $check(($i < 3 && $o['removed_at'] === null && $s->events === []) || ($i === 3 && $o['removed_at'] !== null && $s->eventTypes() === ['removed']), "ausente {$i} ciclo(s): " . ($i < 3 ? 'ainda não removida' : 'removed'));
}
$cur[$gone] = [3, 2, 1];
$run($s, new FakeSnmpTransport($mib($cur, $up())));
$reappeared = array_values(array_filter($s->events, static fn ($e) => $e['event_type'] === 'new' && ($e['details']['reappeared'] ?? false) === true));
$check(count($reappeared) === 1 && $s->onuByIdentifier(strtoupper($gone))['removed_at'] === null, 'removida que volta: um evento new (reappeared) e removed_at limpo');

// 14) Dry-run não grava nada.
$snap = $s->onus;
$ev = count($s->events);
$poll = $s->pollStates[7];
$cur[$mac(4, 2)] = [4, 2, 1];
$r = $run($s, new FakeSnmpTransport($mib($cur, $up())), false, true);
$check($r['result'] === 'ok' && $r['events']['new'] === 1 && $s->onus === $snap && count($s->events) === $ev && $s->pollStates[7] === $poll, 'dry-run: calcula, mas não grava nada');

// 15) Equipamento inexistente / desabilitado.
$t = new FakeSnmpTransport([]);
$r = (new NetStatusCollector($s, static fn () => $t, $clock))->run(99);
$check($r['result'] === 'error' && $t->requests === 0, 'equipamento inexistente: erro sem tocar a rede');
$s->devices[7]['enabled'] = 0;
$check($run($s, new FakeSnmpTransport([]))['result'] === 'error', 'equipamento desabilitado: não coleta');

// 16) Perfil semente real (sem status validado): inventário sim, up/down nunca.
$ss = $newStore($seedJson);
$ss->devices[7]['profile_code'] = 'hioso-epon-25355-4-4-v1';
$seedMib = static fn (array $o, array $over = []): array => $mib($o, $over, false);
$r = $run($ss, new FakeSnmpTransport($seedMib($base, $up())), true);
$check($r['result'] === 'ok' && $r['baseline'] === true && $r['status_validated'] === false && count($ss->onus) === 30 && $ss->events === [], 'perfil semente: baseline registra o inventário (30 ONUs) sem eventos');
$check(count(array_filter($ss->onus, static fn ($o) => $o['oper_status'] === 'unknown' && $o['raw_status_code'] === null)) === 30, 'perfil semente: todas as ONUs unknown, sem código bruto');
$check($r['per_pon'][1] === ['total' => 23, 'online' => 0, 'offline' => 0, 'unknown' => 23] && $r['per_pon'][3]['total'] === 2, 'perfil semente: contagem por PON (23, 5, 2) como unknown');
$cur2 = $base;
$cur2[$mac(4, 1)] = [4, 1, 1];
$r = $run($ss, new FakeSnmpTransport($seedMib($cur2, $up())));
$check($ss->eventTypes() === ['new'], 'perfil semente: ONU nova gera new');
$ss->events = [];
// Mesmo que a OLT mude os códigos que antes eram lidos como status, sem coluna de status validada nada vira up/down.
$cur2[$mac(1, 5)] = [1, 5, 2];
$cur2[$mac(1, 6)] = [1, 6, 99];
$run($ss, new FakeSnmpTransport($seedMib($cur2, $up())));
$check($ss->events === [], 'perfil semente: sem coluna de status, nenhum evento up/down');
// Sumiço real (GET também não encontra): removed só no 3º ciclo, sem down antes.
$gone2 = $mac(2, 5);
unset($cur2[$gone2]);
for ($i = 1; $i <= 3; $i++) {
    $run($ss, new FakeSnmpTransport($seedMib($cur2, $up())));
}
$check($ss->eventTypes() === ['removed'] && $ss->onuByIdentifier(strtoupper($gone2))['removed_at'] !== null, 'perfil semente: ONU realmente sumida (GET confirma ausência) vira removed só no 3º ciclo');

// 17) Agente que pula linha no GETNEXT: ONU conhecida é conferida por GET e NÃO vira ausente/removed.
$ss->events = [];
$hidden = strtoupper($mac(1, 23));
for ($i = 1; $i <= 4; $i++) {
    $t = new FakeSnmpTransport($seedMib($cur2, $up()));
    $t->hideFromWalk("{$colId}.1.1.23");
    $r = $run($ss, $t);
    $o = $ss->onuByIdentifier($hidden);
    $check($r['result'] === 'ok' && $r['rescued_by_get'] === 1 && (int) $o['missing_cycles'] === 0 && $o['removed_at'] === null, "linha pulada pelo GETNEXT (ciclo {$i}): confirmada por GET, sem ausência");
}
$check($ss->events === [], 'linha pulada pelo GETNEXT por 4 ciclos: nenhum removed/down');
$t = new FakeSnmpTransport($seedMib($cur2, $up()));
$t->hideFromWalk("{$colId}.1.1.23");
$t->hideFromWalk("{$colId}.1.1.22");
$r = $run($ss, $t);
$check($r['rescued_by_get'] === 2 && $r['requests'] <= 300, 'duas linhas puladas: ambas confirmadas e requisições dentro do teto');
$check(!str_contains(json_encode($r['warnings']), strtolower($mac(1, 23))), 'resumo não expõe MAC completo');

// ---------- parser de saída do net-snmp ----------
$p = static fn (string $out, string $err = '', int $rc = 0) => NetSnmpCliTransport::parseOutput($out, $err, $rc, '1.3');
$v = $p('.1.3.6.1.2.1.1.5.0 = STRING: "EPON HIOSO 1"');
$check($v->oid === '1.3.6.1.2.1.1.5.0' && $v->type === 'STRING' && $v->value === 'EPON HIOSO 1', 'parser: STRING');
$check($p('.1.3.6.1.2.1.1.3.0 = Timeticks: 8183900')->value === '8183900' && $p('.1.3.6.1.2.1.1.3.0 = Timeticks: (8183900) 22:43:59.00')->value === '8183900', 'parser: Timeticks (com e sem -Ot)');
$check($p(".1.3.6.1.6.3.10.2.1.1.0 = Hex-STRING: 80 00 1F 88 80 27 AA 1C 32 3D F6 F2 6A \n")->value === '80 00 1F 88 80 27 AA 1C 32 3D F6 F2 6A', 'parser: Hex-STRING');
$check($p('.1.3.6.1.2.1.1.2.0 = OID: .1.3.6.1.4.1.25355.4.4')->value === '1.3.6.1.4.1.25355.4.4', 'parser: OID');
$check($p('.1.3.6.1.4.1.25355.3.1.1.0 = IpAddress: 192.168.0.89')->value === '192.168.0.89', 'parser: IpAddress');
$check($p('.1.3.6.1.4.1.25355.3.2.6.3.2.1.10.1.1.1 = ""')->value === '' && $p('.1.3.6.1.4.1.25355.3.2.6.3.2.1.10.1.1.1 = ""')->type === 'STRING', 'parser: string vazia');
$check($p('.1.3.6.1.6.3.1 = No more variables left in this MIB View (It is past the end of the MIB tree)')->isEnd(), 'parser: fim da MIB');
$check($p('.1.3.6.1.4.1.25355.3.2.6.14.2.1.8.1.1.99 = No Such Instance currently exists at this OID')->isNoSuch(), 'parser: noSuchInstance');
foreach ([['Timeout: No Response from 192.168.0.89.', SnmpTimeoutException::class], ['snmpget: Connection refused', SnmpUnreachableException::class]] as [$err, $cls]) {
    try {
        $p('', $err, 1);
        $check(false, "parser: {$cls} esperado");
    } catch (Throwable $e) {
        $check($e instanceof $cls, "parser: erro '{$err}' vira " . basename(str_replace('\\', '/', $cls)));
    }
}

// ---------- credencial temporária do transporte real (sem rede) ----------
$tr = new NetSnmpCliTransport('192.0.2.1', 'COMUNIDADE_TESTE_X', 500, 3000);
$dir = (new ReflectionProperty($tr, 'dir'))->getValue($tr);
$conf = $dir . '/snmp.conf';
$check(is_file($conf) && (fileperms($conf) & 0777) === 0600 && (fileperms($dir) & 0777) === 0700, 'transporte real: snmp.conf 600 em diretório 700');
$cmdSource = (string) file_get_contents(dirname(__DIR__, 2) . '/backend/Infrastructure/Net/Snmp/NetSnmpCliTransport.php');
$check(!preg_match("/'-c'/", $cmdSource) && !str_contains($cmdSource, 'snmpset') && !str_contains($cmdSource, 'snmpbulk'), 'transporte real: sem -c no argv, sem snmpset/snmpbulk*');
$tr->close();
$check(!file_exists($conf) && !is_dir($dir), 'transporte real: close() apaga a credencial e o diretório');
$collectorSource = (string) file_get_contents(dirname(__DIR__, 2) . '/backend/Infrastructure/Net/NetStatusCollector.php');
$check(!preg_match('/1\.3\.6\.1\.4\.1\.\d+/', $collectorSource) && stripos($collectorSource, 'hioso') === false, 'coletor: nenhuma OID de fabricante fixa no código');

// ---------- normalização e casamento de MAC ----------
$check(ValueDecoder::mac('006d61aabbcc') === '006D61AABBCC' && ValueDecoder::mac('00:6d:61:aa:bb:cc') === '006D61AABBCC' && ValueDecoder::mac('00 6D 61 AA BB CC') === '006D61AABBCC', 'MAC: formatos aceitos');
$check(ValueDecoder::mac('000000000000') === null && ValueDecoder::mac('006d61aabb') === null && ValueDecoder::mac('NA') === null && ValueDecoder::mac('zz6d61aabbcc') === null, 'MAC: inválidos rejeitados');
$check(ValueDecoder::maskMac('006D61AABBCC') === '00:6D:61:xx:xx:xx', 'MAC: máscara dos 3 últimos octetos');
$res = MacMatcher::match(
    [['id' => 1, 'mac' => '006D61000010', 'pon' => 1, 'onu' => 1], ['id' => 2, 'mac' => '006D61000020', 'pon' => 1, 'onu' => 2], ['id' => 3, 'mac' => 'AABBCC000001', 'pon' => 1, 'onu' => 3]],
    ['cli_a' => '006D61000010', 'cli_b' => '006D61000021', 'cli_c' => '006D61000023']
);
$check($res['unique'] === 0 && $res['ambiguous'] === 2 && $res['none'] === 1, 'casamento: mesmo prefixo de 5 octetos gera ambiguidade');
$check($res['exact'] === 1 && $res['distances'][1] === 1 && $res['distances'][0] === 1, 'casamento: distâncias contadas');
$res = MacMatcher::match([['id' => 1, 'mac' => '006D61AA0010', 'pon' => 1, 'onu' => 1]], ['cli_a' => '006D61AA0011', 'cli_b' => '006D61BB0011']);
$check($res['unique'] === 1 && $res['items'][0]['candidates'][0]['distance'] === 1, 'casamento: candidato único a +1');
$check(MacMatcher::maskLogin('fulano123') === 'ful***', 'casamento: login mascarado');

echo "NetStatusCollectorSmoke: {$n} verificações, falhas: {$failures}\n";
exit($failures === 0 ? 0 : 1);
