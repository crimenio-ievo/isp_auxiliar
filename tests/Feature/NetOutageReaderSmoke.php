<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';

use App\Infrastructure\Net\NetOutageReader;
use App\Infrastructure\Net\Store\InMemoryNetOutageStore;
use App\Infrastructure\Net\Store\InMemoryNetStore;
use App\Infrastructure\Net\Store\PdoNetOutageStore;
use App\Infrastructure\Net\Store\PdoNetStore;

$n = 0;
$check = static function (bool $ok, string $m) use (&$n): void { $n++; if (!$ok) { throw new RuntimeException("FALHA: {$m}"); } };
$now = '2026-11-13 12:00:00';
$clock = static fn (): string => $now;
$net = new InMemoryNetStore();
$net->devices = [1 => ['id' => 1, 'provider_id' => 1, 'status_interval_s' => 600]];
foreach ([[1, 1, 'AABBCC000001'], [2, 1, 'AABBCC000002'], [3, 2, 'AABBCC000003']] as [$id, $pon, $mac]) {
    $net->onus[$id] = ['id' => $id, 'provider_id' => 1, 'device_id' => 1, 'pon_index' => $pon, 'onu_index' => $id, 'onu_identifier' => $mac, 'identifier_type' => 'epon_mac', 'removed_at' => null];
}
$st = new InMemoryNetOutageStore();
$row = static fn (string $mac, ?int $onu, string $key, string $start, ?string $end, string $cause = 'energia', string $cat = 'queda', ?string $why = null): array => [
    'provider_id' => 1, 'device_id' => 1, 'onu_id' => $onu, 'onu_identifier' => $mac, 'source' => 'log', 'event_key' => $key,
    'started_at' => $start, 'ended_at' => $end, 'duration_s' => $end === null ? null : strtotime($end . ' UTC') - strtotime($start . ' UTC'),
    'cause' => $cause, 'category' => $cat, 'maintenance_reason' => $why, 'dying_gasp_at' => null, 'returned_via' => null, 'imported_at' => $now, 'updated_at' => $now,
];
$put = static function (array $r) use ($st): void { $st->rows["{$r['device_id']}|{$r['source']}|{$r['event_key']}"] = $r; };
$put($row('AABBCC000001', 1, 'a', '2026-11-13 10:00:00', '2026-11-13 10:01:00'));                     // ONU1 energia, 60 s
$put($row('AABBCC000001', 1, 'b', '2026-11-13 11:00:00', '2026-11-13 11:05:00', 'sem_aviso_energia')); // ONU1 sem aviso, 300 s
$put($row('AABBCC000002', null, 'c', '2026-11-13 09:00:00', null));                                    // ONU2 (onu_id NULL, achada pelo MAC), em aberto
$put($row('AABBCC000003', 3, 'd', '2026-11-12 23:50:00', '2026-11-13 00:10:00'));                      // ONU3 (PON 2) cruza o inicio de 24h? nao: antes de 11-12 12:00? nao
$put($row('AABBCC000001', 1, 'e', '2026-11-13 08:00:00', '2026-11-13 08:00:30', 'sem_aviso_energia', 'manutencao', 'boot'));
$put($row('FFEEDD000009', null, 'f', '2026-11-13 07:00:00', '2026-11-13 07:00:10'));                    // MAC sem ONU cadastrada
// Poll: ONU1 down 10:02 -> up 10:03 (mesma queda 'a', cobre por tolerancia) / ONU3 down 06:00 -> up 06:20 (so poll) / ONU2 down 09:05 (aberta no poll, coberta pelo log 'c')
// ONU1 down 08:00:40 -> up 08:01 (coberta por manutencao do log 'e') / ONU3 down antes do periodo 1h
$st->pollEvents = [
    ['onu_id' => 1, 'event_type' => 'down', 'occurred_at' => '2026-11-13 10:02:00'], ['onu_id' => 1, 'event_type' => 'up', 'occurred_at' => '2026-11-13 10:03:00'],
    ['onu_id' => 3, 'event_type' => 'down', 'occurred_at' => '2026-11-13 06:00:00'], ['onu_id' => 3, 'event_type' => 'up', 'occurred_at' => '2026-11-13 06:20:00'],
    ['onu_id' => 2, 'event_type' => 'down', 'occurred_at' => '2026-11-13 09:05:00'],
    ['onu_id' => 1, 'event_type' => 'down', 'occurred_at' => '2026-11-13 08:00:40'], ['onu_id' => 1, 'event_type' => 'up', 'occurred_at' => '2026-11-13 08:01:00'],
    ['onu_id' => 1, 'event_type' => 'new', 'occurred_at' => '2026-11-13 05:00:00'],
];
$rd = new NetOutageReader($net, $st, $clock);

// Periodos.
$p = $rd->period('1h'); $check($p['from'] === '2026-11-13 11:00:00' && $p['to'] === $now, 'periodo 1h');
$p = $rd->period('24h'); $check($p['from'] === '2026-11-12 12:00:00', 'periodo 24h');
$p = $rd->period('7d'); $check($p['from'] === '2026-11-06 12:00:00', 'periodo 1 semana');
$p = $rd->period('custom', '2026-11-13 09:30:00', '2026-11-13 10:30:00'); $check($p['kind'] === 'custom' && $p['to'] === '2026-11-13 10:30:00', 'periodo personalizado');
$p = $rd->period('custom', '2026-11-13 09:30:00'); $check($p['to'] === $now, 'personalizado sem fim = agora');
foreach ([['custom', '2026-11-14 00:00:00', '2026-11-13 00:00:00'], ['custom', null, null], ['custom', 'lixo', null], ['ano', null, null], ['custom', '2025-01-01 00:00:00', '2026-11-13 00:00:00']] as [$k, $f, $t]) {
    try { $rd->period($k, $f, $t); $check(false, "periodo invalido aceito: {$k}"); } catch (InvalidArgumentException $e) { $check(true, 'periodo invalido rejeitado'); }
}

// ONU: log e poll, sem contar duas vezes.
$r = $rd->forOnu(1, 1, $rd->period('24h'));
$q = $r['outages'];
$check(count($q) === 2 && $q[0]['started_at'] === '2026-11-13 10:00:00' && $q[1]['started_at'] === '2026-11-13 11:00:00', 'ONU1: so 2 quedas (poll 10:02 coberto pelo log, manutencao fora)');
$check($q[0]['source'] === 'log' && $q[0]['precisao'] === 'exata' && $q[0]['duration_s'] === 60 && $q[0]['reason'] === 'Queda de energia (Dying Gasp)' && $q[0]['open'] === false && $q[0]['ended_at'] === '2026-11-13 10:01:00', 'inicio, retorno, duracao e motivo');
$check($q[1]['cause'] === 'sem_aviso_energia' && $q[1]['reason'] === 'Sem aviso de energia' && !str_contains(json_encode($q[1]), 'fibra'), 'motivo sem aviso de energia (sem afirmar fibra)');
$check($r['totals']['quedas'] === 2 && $r['totals']['downtime_no_periodo_s'] === 360 && $r['totals']['por_motivo'] === ['energia' => 1, 'sem_aviso_energia' => 1] && $r['totals']['do_poll'] === 0, 'totais da ONU');
$check(preg_match('/^[0-9A-Fa-f]{2}:[0-9A-Fa-f]{2}:[0-9A-Fa-f]{2}:xx/', $q[0]['onu_identifier_mascarado']) === 1, 'MAC mascarado na saida');
$r = $rd->forOnu(1, 1, $rd->period('24h'), true);
$check(count($r['outages']) === 3 && $r['outages'][0]['category'] === 'manutencao' && $r['outages'][0]['maintenance_reason'] === 'boot' && $r['totals']['quedas'] === 2, 'manutencao so aparece quando pedida e nao entra nos totais');
$r = $rd->forOnu(1, 1, $rd->period('1h'));
$check(count($r['outages']) === 1 && $r['outages'][0]['started_at'] === '2026-11-13 11:00:00', 'periodo 1h filtra');
$r = $rd->forOnu(1, 1, $rd->period('custom', '2026-11-13 10:00:30', '2026-11-13 10:30:00'));
$check(count($r['outages']) === 1 && $r['outages'][0]['downtime_in_period_s'] === 30, 'queda que cruza o inicio do periodo conta so o trecho dentro');

// Em aberto (achada pelo MAC porque onu_id e NULL) e poll aberto coberto pelo log.
$r = $rd->forOnu(1, 2, $rd->period('24h'));
$check(count($r['outages']) === 1 && $r['outages'][0]['open'] === true && $r['outages'][0]['ended_at'] === null && $r['outages'][0]['duration_s'] === null && $r['outages'][0]['downtime_in_period_s'] === 10800 && $r['totals']['em_aberto'] === 1, 'em aberto: sem fim/duracao, tempo parado ate agora (3 h)');
$check($r['outages'][0]['source'] === 'log' && $r['totals']['do_poll'] === 0, 'poll aberto coberto pelo log nao duplica');

// Somente poll quando nao ha log (aproximada, motivo desconhecido).
$r = $rd->forOnu(1, 3, $rd->period('24h'));
$check(count($r['outages']) === 2, 'ONU3: log + poll sem log');
$pollOnly = array_values(array_filter($r['outages'], static fn ($o) => $o['source'] === 'poll'))[0];
$check($pollOnly['cause'] === 'desconhecida' && str_contains($pollOnly['precisao'], 'aproximada') && $pollOnly['started_at'] === '2026-11-13 06:00:00' && $pollOnly['duration_s'] === 1200, 'queda so do poll marcada como aproximada, motivo desconhecido');

// PON: soma as ONUs da PON, log sem ONU cadastrada fica de fora e e contado.
$r = $rd->forPon(1, 1, $rd->period('24h'));
$check($r['totals']['quedas'] === 3 && count(array_filter($r['outages'], static fn ($o) => $o['onu_id'] === 3)) === 0, 'PON 1: ONUs 1 e 2, sem a ONU da PON 2');
$check($r['totals']['log_sem_onu_cadastrada'] === 1, 'log sem ONU cadastrada contado a parte');
$r = $rd->forPon(1, 2, $rd->period('24h'));
$check($r['totals']['quedas'] === 2 && $r['totals']['do_poll'] === 1 && $r['totals']['do_log'] === 1, 'PON 2');
$r = $rd->forPon(1, 9, $rd->period('24h'));
$check($r['outages'] === [] && $r['totals']['quedas'] === 0, 'PON sem ONU');

// Quedas do poll que comecaram antes do periodo (lookback) e equipamento inexistente.
$st->pollEvents[] = ['onu_id' => 3, 'event_type' => 'down', 'occurred_at' => '2026-11-13 11:30:00'];
$r = $rd->forOnu(1, 3, $rd->period('1h'));
$check(count($r['outages']) === 1 && $r['outages'][0]['source'] === 'poll' && $r['outages'][0]['open'] === true, 'queda aberta so no poll dentro do periodo');
try { $rd->forOnu(99, 1, $rd->period('1h')); $check(false, 'equipamento inexistente'); } catch (InvalidArgumentException $e) { $check(true, 'equipamento inexistente rejeitado'); }

// Quedas correlacionadas por PON (ate 10 s do inicio do grupo), sem manutencao/boot.
$netG = new InMemoryNetStore(); $netG->devices = [1 => ['id' => 1, 'provider_id' => 1, 'status_interval_s' => 600]];
foreach ([[1, 1], [2, 1], [3, 1], [4, 1], [5, 2], [6, 2], [7, 1], [8, 1]] as [$id, $pon]) {
    $netG->onus[$id] = ['id' => $id, 'provider_id' => 1, 'device_id' => 1, 'pon_index' => $pon, 'onu_index' => $id, 'onu_identifier' => 'AABBCC00000' . $id, 'identifier_type' => 'epon_mac', 'removed_at' => null];
}
$stG = new InMemoryNetOutageStore();
$mk = static function (int $onu, string $start, ?string $end, string $cause = 'energia', string $cat = 'queda', ?string $why = null) use ($stG, $now): void {
    $stG->rows["1|log|g{$onu}{$start}"] = ['provider_id' => 1, 'device_id' => 1, 'onu_id' => $onu, 'onu_identifier' => 'AABBCC00000' . $onu, 'source' => 'log', 'event_key' => "g{$onu}{$start}", 'started_at' => $start, 'ended_at' => $end,
        'duration_s' => $end === null ? null : strtotime($end . ' UTC') - strtotime($start . ' UTC'), 'cause' => $cause, 'category' => $cat, 'maintenance_reason' => $why, 'dying_gasp_at' => null, 'returned_via' => null, 'imported_at' => $now, 'updated_at' => $now];
};
$mk(1, '2026-11-13 10:00:01', '2026-11-13 10:01:05');   // PON 1: 1,2 e 7 no mesmo segundo e a ate 10 s do primeiro
$mk(2, '2026-11-13 10:00:01', '2026-11-13 10:01:07');
$mk(7, '2026-11-13 10:00:11', '2026-11-13 10:01:09');   // exatamente 10 s do primeiro: entra
$mk(8, '2026-11-13 10:00:12', '2026-11-13 10:01:09');   // 11 s do primeiro: grupo novo (nao encadeia)
$mk(5, '2026-11-13 10:00:01', '2026-11-13 10:01:00');   // PON 2, mesmo instante: grupo a parte
$mk(6, '2026-11-13 10:00:03', '2026-11-13 10:01:00', 'sem_aviso_energia'); // PON 2: mistura energia + sem aviso
$mk(3, '2026-11-13 11:30:00', null, 'sem_aviso_energia'); // PON 1, sozinha, em aberto
$mk(4, '2026-11-13 09:00:00', '2026-11-13 09:00:40', 'sem_aviso_energia', 'manutencao', 'boot'); // fora dos grupos
$rdG = new NetOutageReader($netG, $stG, $clock);
$gs = $rdG->groupedByPon(1, $rdG->period('24h'));
$check(count($gs) === 4, 'grupos: PON1 (3 ONUs), PON1 (1 ONU), PON2 (2 ONUs) e PON1 aberta = 4 (manutencao fora)');
$by = static function (array $gs, int $pon, string $start) { foreach ($gs as $g) { if ($g['pon_index'] === $pon && $g['inicio'] === $start) { return $g; } } return null; };
$g1 = $by($gs, 1, '2026-11-13 10:00:01');
$check($g1['quantidade'] === 3 && array_column($g1['quedas'], 'onu_id') === [1, 2, 7] && $g1['todas_dying_gasp'] === true && $g1['motivo'] === 'energia' && $g1['correlacionada'] === true && $g1['precisao'] === 'exata' && $g1['fim'] === '2026-11-13 10:01:09' && $g1['em_aberto'] === 0, 'grupo da PON 1: 3 ONUs (ate 10 s do primeiro), todas com Dying Gasp');
$check($g1['texto'] === 'queda de energia em 3 ONUs da PON 1', 'texto: queda de energia em 3 ONUs da PON 1');
$g2 = $by($gs, 1, '2026-11-13 10:00:12');
$check($g2['quantidade'] === 1 && $g2['correlacionada'] === false && $g2['texto'] === 'queda de energia em 1 ONU da PON 1', '11 s depois: grupo novo (sem encadear), singular');
$g3 = $by($gs, 2, '2026-11-13 10:00:01');
$check($g3['quantidade'] === 2 && $g3['todas_dying_gasp'] === false && $g3['motivo'] === 'misto' && $g3['texto'] === 'queda em 2 ONUs da PON 2 (1 com aviso de energia)', 'PON 2: mesmo instante mas PON diferente = outro grupo; mistura nao diz energia');
$g4 = $by($gs, 1, '2026-11-13 11:30:00');
$check($g4['em_aberto'] === 1 && $g4['fim'] === null && $g4['texto'] === 'queda sem aviso de energia em 1 ONU da PON 1' && $g4['motivo'] === 'sem_aviso_energia', 'queda em aberto sem aviso de energia (nao afirma causa)');
$check($gs[0]['inicio'] === '2026-11-13 11:30:00', 'mais recente primeiro');
$check(count($rdG->groupedByPon(1, $rdG->period('1h'))) === 1, 'periodo limita os grupos');
$stG->pollEvents = [['onu_id' => 7, 'event_type' => 'down', 'occurred_at' => '2026-11-13 08:00:00'], ['onu_id' => 8, 'event_type' => 'down', 'occurred_at' => '2026-11-13 08:00:00'], ['onu_id' => 7, 'event_type' => 'up', 'occurred_at' => '2026-11-13 08:10:00'], ['onu_id' => 8, 'event_type' => 'up', 'occurred_at' => '2026-11-13 08:10:00']];
$gp = $rdG->groupedByPon(1, $rdG->period('24h'));
$gpp = $by($gp, 1, '2026-11-13 08:00:00');
$check($gpp !== null && $gpp['quantidade'] === 2 && $gpp['motivo'] === 'desconhecida' && str_contains($gpp['precisao'], 'aproximada') && $gpp['texto'] === 'queda em 2 ONUs da PON 1 (motivo não informado)', 'quedas so do poll: agrupadas, hora aproximada, motivo nao informado');
$check(!str_contains(strtolower(json_encode($gs)), 'fibra'), 'nunca afirma fibra');
$stG->rows = []; $stG->pollEvents = [];
$check($rdG->groupedByPon(1, $rdG->period('24h')) === [], 'sem quedas: nenhum grupo');

// PDO em schema descartavel exclusivo: mesmas leituras com eventos reais do poll.
$pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$schema = 'isp_auxiliar_net034rd_sim';
$root = dirname(__DIR__, 2);
if ($pdo->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$schema'")->fetchColumn()) { throw new RuntimeException('Schema descartavel ja existe: preservar e parar.'); }
$pdo->exec("CREATE DATABASE `$schema`");
try {
    $pdo->exec("USE `$schema`");
    $apply = static function (string $file) use ($pdo): void {
        $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
        foreach (explode(';', $sql) as $x) { if (trim($x) !== '') { $pdo->exec($x); } }
    };
    $pdo->exec('CREATE TABLE providers (id BIGINT UNSIGNED PRIMARY KEY)');
    $pdo->exec('CREATE TABLE map_nodes (id BIGINT UNSIGNED PRIMARY KEY)');
    foreach (['030_net_telemetry_core.sql', '031_net_profile_hioso_v2.sql', '032_net_power.sql', '033_net_alerts.sql', '034_net_onu_outages.sql'] as $f) { $apply("$root/database/migrations/$f"); }
    $pdo->exec('INSERT INTO providers VALUES (1)'); $pdo->exec('INSERT INTO map_nodes VALUES (1)');
    $pdo->exec("INSERT INTO net_devices (id,provider_id,map_node_id,profile_id,mgmt_host,credential_ref,created_at,updated_at) SELECT 1,1,1,id,'fake','FAKE',NOW(),NOW() FROM net_device_profiles WHERE version=3");
    foreach ([[1, 1, 'AABBCC000001'], [2, 1, 'AABBCC000002'], [3, 2, 'AABBCC000003']] as [$id, $pon, $mac]) {
        $pdo->exec("INSERT INTO net_onus (id,provider_id,device_id,pon_index,onu_index,snmp_index,onu_identifier,identifier_type,first_seen_at,last_seen_at) VALUES ($id,1,1,$pon,$id,'1.$pon.$id','$mac','epon_mac',NOW(),NOW())");
    }
    $pdo->exec('CREATE TABLE audit_logs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,provider_id BIGINT UNSIGNED,actor_login VARCHAR(80),action VARCHAR(120),entity_type VARCHAR(80),entity_id BIGINT UNSIGNED,context_json JSON,created_at DATETIME)');
    $ps = new PdoNetOutageStore($pdo);
    foreach ($st->rows as $r) { $ps->save([$r], [], ['provider_id' => 1, 'device_id' => 1, 'created_at' => $now, 'context' => []]); }
    $ev = $pdo->prepare('INSERT INTO net_onu_events (onu_id,event_type,occurred_at) VALUES (?,?,?)');
    foreach ($st->pollEvents as $e) { $ev->execute([$e['onu_id'], $e['event_type'], $e['occurred_at']]); }
    $prd = new NetOutageReader(new PdoNetStore($pdo), $ps, $clock);
    $a = $prd->forPon(1, 1, $prd->period('24h')); $b = $prd->forPon(1, 2, $prd->period('24h')); $c = $prd->forOnu(1, 2, $prd->period('24h'));
    $mem = $rd->forPon(1, 1, $rd->period('24h'));
    $strip = static fn (array $x): array => array_map(static fn ($o) => [$o['onu_id'], $o['source'], $o['started_at'], $o['ended_at'], $o['cause']], $x['outages']);
    $check($strip($a) === $strip($mem) && $a['totals']['quedas'] === 3, 'PDO: PON 1 igual ao resultado em memoria');
    $check($b['totals']['do_poll'] === 2 && $b['totals']['do_log'] === 1, 'PDO: PON 2 mistura log e poll (2 do poll: 06:00 e a aberta das 11:30)');
    $check($c['outages'][0]['open'] === true && $c['totals']['downtime_no_periodo_s'] === 10800, 'PDO: em aberto, MAC casado quando onu_id NULL');
    echo "NetOutageReaderSmoke: {$n} verificacoes, zero falhas\n";
} finally {
    $pdo->exec('DROP DATABASE `isp_auxiliar_net034rd_sim`');
    echo "Schema descartavel removido.\n";
}
