<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';

use App\Infrastructure\Net\NetLogImporter;
use App\Infrastructure\Net\OltLogParser;
use App\Infrastructure\Net\Store\InMemoryNetOutageStore;
use App\Infrastructure\Net\Store\InMemoryNetStore;
use App\Infrastructure\Net\Store\PdoNetOutageStore;
use App\Infrastructure\Net\Store\PdoNetStore;

$n = 0;
$check = static function (bool $ok, string $m) use (&$n): void { $n++; if (!$ok) { throw new RuntimeException("FALHA: {$m}"); } };
$fixture = (string) file_get_contents(dirname(__DIR__) . '/fixtures/olt_syslog_reduced.log');
$L = static fn (string $ts, string $mac, string $ev): string => 'Nov  9 ' . substr($ts, 11) . ' EPON: [' . $ts . '] ONU 0/1:1      [ ' . $mac . " ] [Na] {$ev}\n";

$net = new InMemoryNetStore();
$net->devices = [1 => ['id' => 1, 'provider_id' => 1]];
$net->onus = [7 => ['id' => 7, 'provider_id' => 1, 'device_id' => 1, 'identifier_type' => 'epon_mac', 'onu_identifier' => '002EC7000009']];
$store = new InMemoryNetOutageStore();
$clock = static fn (): string => '2026-11-13 10:00:00';
$imp = new NetLogImporter($net, $store, new OltLogParser(), $clock);

// Simulacao: calcula tudo e nao grava.
$s = $imp->run(1, $fixture, 'UTC', 'UTC', false);
$check($store->rows === [] && $store->audit === [] && $s['modo'] === 'simulacao', 'simulacao nao grava');
$check($s['eventos_linkdown'] === 18 && $s['quedas'] === 5 && $s['manutencao_boot_ou_troca_pon'] === 13 && $s['quedas_em_aberto'] === 1 && $s['novas'] === 18, 'contagens da simulacao');
$check($s['macs_sem_onu_cadastrada'] === 9, 'MACs sem ONU cadastrada contados (9 de 10)');
$json = json_encode($s);
$check(!preg_match('/[0-9A-F]{2}:[0-9A-F]{2}:[0-9A-F]{2}:00:00:[0-9A-F]{2}/', (string) $json) && count($s['exemplos']) > 0 && str_contains((string) $json, 'xx'), 'saida so com MAC mascarado');

// Apply grava tudo; reimportar nao duplica.
$s = $imp->run(1, $fixture, 'UTC', 'UTC', true);
$check(count($store->rows) === 18 && count($store->audit) === 1 && $store->audit[0]['context']['novas'] === 18, 'apply grava 18 e audita');
$check(strlen($store->audit[0]['context']['conteudo_sha256']) === 64 && !str_contains(json_encode($store->audit[0]), 'ONU 0/'), 'auditoria sem conteudo do log');
$s = $imp->run(1, $fixture, 'UTC', 'UTC', true);
$check($s['novas'] === 0 && $s['inalteradas'] === 18 && count($store->rows) === 18 && count($store->audit) === 1, 'reimportar o mesmo arquivo nao duplica nem audita de novo');
$linked = array_values(array_filter($store->rows, static fn ($r) => $r['onu_id'] === 7));
$check(count($linked) === 1 && $linked[0]['source'] === 'log' && $linked[0]['onu_identifier'] === '002EC7000009', 'ONU casada por MAC; demais com onu_id NULL');
$q = array_values(array_filter($store->rows, static fn ($r) => $r['started_at'] === '2026-11-09 23:36:02'))[0];
$check($q['cause'] === 'energia' && $q['category'] === 'queda' && $q['duration_s'] === 161 && $q['dying_gasp_at'] === '2026-11-09 23:36:01', 'campos da queda por energia');
$check(count(array_filter($store->rows, static fn ($r) => $r['category'] === 'manutencao' && in_array($r['maintenance_reason'], ['boot', 'troca_pon'], true))) === 13, 'manutencao gravada com motivo, fora da contagem de quedas');

// Fuso: relogio da OLT -> fuso do aplicativo; a chave nao muda.
$net2 = new InMemoryNetStore(); $net2->devices = $net->devices;
$st2 = new InMemoryNetOutageStore();
$i2 = new NetLogImporter($net2, $st2, new OltLogParser(), $clock);
$i2->run(1, $fixture, 'America/Sao_Paulo', 'UTC', true);
$q2 = array_values(array_filter($st2->rows, static fn ($r) => $r['dying_gasp_at'] === '2026-11-10 02:36:01'));
$check(count($q2) === 1 && $q2[0]['started_at'] === '2026-11-10 02:36:02' && $q2[0]['event_key'] === $q['event_key'], 'fuso da OLT convertido (+3 h) e event_key estavel');
$s = $i2->run(1, $fixture, 'America/Sao_Paulo', 'UTC', true);
$check($s['novas'] === 0 && count($st2->rows) === 18, 'idempotente tambem com conversao de fuso');

// Queda em aberto fecha quando o arquivo seguinte traz o retorno; arquivo mais antigo nao reabre.
$net3 = new InMemoryNetStore(); $net3->devices = $net->devices; $st3 = new InMemoryNetOutageStore();
$i3 = new NetLogImporter($net3, $st3, new OltLogParser(), $clock);
$M = 'AA:BB:CC:00:00:01';
$open = $L('2026-11-12 10:00:00', $M, 'linkdown');
$closed = $open . $L('2026-11-12 10:03:00', $M, 'linkup');
$s = $i3->run(1, $open, 'UTC', 'UTC', true);
$check($s['quedas_em_aberto'] === 1 && count($st3->rows) === 1 && array_values($st3->rows)[0]['ended_at'] === null, 'queda em aberto gravada');
$s = $i3->run(1, $closed, 'UTC', 'UTC', true);
$r = array_values($st3->rows)[0];
$check($s['atualizadas_com_retorno'] === 1 && count($st3->rows) === 1 && $r['ended_at'] === '2026-11-12 10:03:00' && $r['duration_s'] === 180, 'retorno fecha a queda sem duplicar');
$s = $i3->run(1, $open, 'UTC', 'UTC', true);
$check($s['mantidas_fechadas'] === 1 && array_values($st3->rows)[0]['ended_at'] === '2026-11-12 10:03:00', 'arquivo antigo nao reabre queda fechada');

// Atomicidade e entradas invalidas.
$net4 = new InMemoryNetStore(); $net4->devices = $net->devices; $st4 = new InMemoryNetOutageStore(); $st4->failAudit = true;
try { (new NetLogImporter($net4, $st4, new OltLogParser(), $clock))->run(1, $fixture, 'UTC', 'UTC', true); $check(false, 'auditoria falhou e gravou'); }
catch (RuntimeException $e) { $check($st4->rows === [] && $st4->audit === [], 'falha na auditoria nao deixa queda gravada'); }
try { $imp->run(99, $fixture, 'UTC', 'UTC', false); $check(false, 'equipamento inexistente aceito'); } catch (InvalidArgumentException $e) { $check(true, 'equipamento inexistente rejeitado'); }
try { $imp->run(1, $fixture, 'Nao/Existe', 'UTC', false); $check(false, 'fuso invalido aceito'); } catch (Throwable $e) { $check(true, 'fuso invalido rejeitado'); }
$s = $imp->run(1, '', 'UTC', 'UTC', true);
$check($s['eventos_linkdown'] === 0 && $s['novas'] === 0, 'arquivo vazio nao grava');

// PDO em schema descartavel exclusivo (socket local, sem .env).
$pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$schema = 'isp_auxiliar_net034imp_sim';
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
    $pdo->exec('CREATE TABLE audit_logs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,provider_id BIGINT UNSIGNED,actor_login VARCHAR(80),action VARCHAR(120),entity_type VARCHAR(80),entity_id BIGINT UNSIGNED,context_json JSON,created_at DATETIME)');
    foreach (['030_net_telemetry_core.sql', '031_net_profile_hioso_v2.sql', '032_net_power.sql', '033_net_alerts.sql', '034_net_onu_outages.sql'] as $f) { $apply("$root/database/migrations/$f"); }
    $pdo->exec('INSERT INTO providers VALUES (1)'); $pdo->exec('INSERT INTO map_nodes VALUES (1)');
    $pdo->exec("INSERT INTO net_devices (id,provider_id,map_node_id,profile_id,mgmt_host,credential_ref,created_at,updated_at) SELECT 1,1,1,id,'fake','FAKE',NOW(),NOW() FROM net_device_profiles WHERE version=3");
    $pdo->exec("INSERT INTO net_onus (id,provider_id,device_id,pon_index,onu_index,snmp_index,onu_identifier,identifier_type,first_seen_at,last_seen_at) VALUES (7,1,1,1,1,'1.1.1','002EC7000009','epon_mac',NOW(),NOW())");
    $pi = new NetLogImporter(new PdoNetStore($pdo), new PdoNetOutageStore($pdo), new OltLogParser(), $clock);
    $s = $pi->run(1, $fixture, 'America/Sao_Paulo', 'UTC', false);
    $check((int) $pdo->query('SELECT COUNT(*) FROM net_onu_outages')->fetchColumn() === 0 && $s['novas'] === 18, 'PDO simulacao nao grava');
    $pi->run(1, $fixture, 'America/Sao_Paulo', 'UTC', true);
    $s = $pi->run(1, $fixture, 'America/Sao_Paulo', 'UTC', true);
    $check((int) $pdo->query('SELECT COUNT(*) FROM net_onu_outages')->fetchColumn() === 18 && $s['novas'] === 0 && $s['inalteradas'] === 18, 'PDO idempotente');
    $check((int) $pdo->query('SELECT COUNT(*) FROM audit_logs WHERE action=\'net.log_import\'')->fetchColumn() === 1, 'PDO auditoria uma vez');
    $check((int) $pdo->query('SELECT COUNT(*) FROM net_onu_outages WHERE onu_id=7')->fetchColumn() === 1 && (int) $pdo->query('SELECT COUNT(*) FROM net_onu_outages WHERE category=\'manutencao\'')->fetchColumn() === 13, 'PDO onu_id e categoria');
    $row = $pdo->query("SELECT started_at, cause FROM net_onu_outages WHERE dying_gasp_at='2026-11-10 02:36:01'")->fetch(PDO::FETCH_ASSOC);
    $check($row['started_at'] === '2026-11-10 02:36:02' && $row['cause'] === 'energia', 'PDO fuso convertido');
    $po = new NetLogImporter(new PdoNetStore($pdo), new PdoNetOutageStore($pdo), new OltLogParser(), $clock);
    $po->run(1, $open, 'UTC', 'UTC', true); $po->run(1, $closed, 'UTC', 'UTC', true);
    $r = $pdo->query("SELECT ended_at, duration_s FROM net_onu_outages WHERE onu_identifier='AABBCC000001'")->fetchAll(PDO::FETCH_ASSOC);
    $check(count($r) === 1 && $r[0]['ended_at'] === '2026-11-12 10:03:00' && (int) $r[0]['duration_s'] === 180, 'PDO fecha queda em aberto');
    $pdo->exec('DROP TABLE audit_logs');
    $before = (int) $pdo->query('SELECT COUNT(*) FROM net_onu_outages')->fetchColumn();
    try { $po->run(1, $L('2026-11-12 12:00:00', 'AA:BB:CC:00:00:02', 'linkdown'), 'UTC', 'UTC', true); $check(false, 'sem audit_logs deveria falhar'); }
    catch (Throwable $e) { $check((int) $pdo->query('SELECT COUNT(*) FROM net_onu_outages')->fetchColumn() === $before, 'PDO rollback integral se a auditoria falhar'); }
    echo "NetLogImportSmoke: {$n} verificacoes, zero falhas\n";
} finally {
    $pdo->exec('DROP DATABASE `isp_auxiliar_net034imp_sim`');
    echo "Schema descartavel removido.\n";
}
