<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';
// Exclusivamente socket local, sem .env, sem dados do banco da aplicacao.
$pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$schema = 'isp_auxiliar_net033_sim';
$root = dirname(__DIR__, 2);
$n = 0;
$check = static function (bool $ok, string $msg) use (&$n): void { if (!$ok) { throw new RuntimeException($msg); } $n++; };
$apply = static function (string $file) use ($pdo): void {
    if (!is_file($file)) { throw new RuntimeException('SQL inexistente: ' . basename($file)); }
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
    foreach (explode(';', $sql) as $s) { if (trim($s) !== '') { $pdo->exec($s); } }
};
if ($pdo->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$schema'")->fetchColumn()) {
    throw new RuntimeException('Schema descartavel ja existe: preservar e parar.');
}
$pdo->exec("CREATE DATABASE `$schema`");
try {
    $pdo->exec("USE `$schema`");
    $pdo->exec('CREATE TABLE providers (id BIGINT UNSIGNED PRIMARY KEY)');
    $pdo->exec('CREATE TABLE map_nodes (id BIGINT UNSIGNED PRIMARY KEY)');
    $pdo->exec('CREATE TABLE schema_migrations (version VARCHAR(190) PRIMARY KEY,filename VARCHAR(190))');
    foreach (['030_net_telemetry_core.sql', '031_net_profile_hioso_v2.sql', '032_net_power.sql'] as $f) { $apply("$root/database/migrations/$f"); }
    $pdo->exec('INSERT INTO providers VALUES (1)');
    $pdo->exec('INSERT INTO map_nodes VALUES (1)');
    $pdo->exec("INSERT INTO net_devices (id,provider_id,map_node_id,profile_id,mgmt_host,credential_ref,created_at,updated_at) SELECT 1,1,1,id,'fake','FAKE',NOW(),NOW() FROM net_device_profiles WHERE version=3");
    $file = "$root/database/migrations/033_net_alerts.sql";
    $apply($file); $apply($file);
    $check((int) $pdo->query('SELECT status_interval_s FROM net_devices')->fetchColumn() === 600, 'intervalo status');
    $check((int) $pdo->query('SELECT power_interval_s FROM net_devices')->fetchColumn() === 1800, 'intervalo potencia');
    $insert = "INSERT INTO net_alerts (provider_id,device_id,alert_type,scope_type,scope_id,severity,opened_at,last_seen_at) VALUES (1,1,'device_unreachable','device',1,'critico',NOW(),NOW())";
    $pdo->exec($insert);
    try { $pdo->exec($insert); $check(false, 'aberto duplicado aceito'); } catch (PDOException $e) { $check((int) $e->errorInfo[1] === 1062, 'unique aberto'); }
    $pdo->exec('UPDATE net_alerts SET closed_at=NOW()');
    $pdo->exec($insert); $pdo->exec('UPDATE net_alerts SET closed_at=NOW()'); $pdo->exec($insert);
    $check((int) $pdo->query('SELECT COUNT(*) FROM net_alerts')->fetchColumn() === 3, 'historico fechado e nova abertura');
    $pdo->exec("INSERT INTO schema_migrations VALUES ('033_net_alerts.sql','033_net_alerts.sql')");
    $apply("$root/scripts/rollback_033_net_alerts.sql");
    $check((int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()===0,'rollback remove metadados para permitir reaplicacao oficial');
    $check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='net_alerts'")->fetchColumn() === 0, 'rollback');
    $apply($file);
    $check((int) $pdo->query('SELECT COUNT(*) FROM net_alerts')->fetchColumn() === 0, 'reaplicar apos rollback');
    $store = new App\Infrastructure\Net\Store\PdoNetAlertStore($pdo);
    $a = ['provider_id'=>1,'alert_type'=>'device_unreachable','scope_type'=>'device','scope_id'=>1,'severity'=>'critico','details'=>['x'=>1],'silenced_until'=>null];
    $store->applyAlerts(1, [$a], [], '2026-11-12 12:00:00');
    $store->applyAlerts(1, [$a], [], '2026-11-12 12:01:00');
    $alerts=$store->openAlerts(1);
    $check(count($alerts)===1 && $alerts[0]['details']===['x'=>1], 'PDO upsert e details');
    $a['silenced_until']='2026-11-13 12:00:00';
    $store->applyAlerts(1, [$a], [], '2026-11-12 12:02:00');
    $a['silenced_until']=null;
    $store->applyAlerts(1, [$a], [], '2026-11-12 12:03:00');
    $check($store->openAlerts(1)[0]['silenced_until']==='2026-11-13 12:00:00', 'PDO preserva silencio');
    $store->applyAlerts(1, [], [$alerts[0]['id']], '2026-11-12 12:04:00');
    $check($store->openAlerts(1)===[], 'PDO fecha');
    $pdo->exec("INSERT INTO net_interventions (provider_id,device_id,scope_type,scope_id,created_at,silenced_until) VALUES (1,1,'pon',1001,'2026-11-12 12:00:00','2026-11-13 12:00:00')");
    $check($store->silencedUntil(1,'onu',22,1,'2026-11-12 12:00:00')==='2026-11-13 12:00:00', 'PDO silencio herdado');
    $check($store->silencedUntil(1,'onu',22,2,'2026-11-12 12:00:00')===null, 'PDO escopo isolado');
    $pdo->exec("INSERT INTO net_onus (id,provider_id,device_id,pon_index,onu_index,snmp_index,onu_identifier,identifier_type,first_seen_at,last_seen_at) VALUES (1,1,1,1,1,'1.1.1','001122334455','epon_mac',NOW(),NOW())");
    $pdo->exec("INSERT INTO net_onu_state (onu_id,oper_status,updated_at) VALUES (1,'online',NOW())");
    $net=new App\Infrastructure\Net\Store\PdoNetStore($pdo);
    $fields=['onu_rx_dbm'=>-28,'onu_tx_dbm'=>2,'onu_temp_c'=>30,'measured_at'=>'2026-11-12 12:00:00','rx_band'=>'critico','baseline_rx_dbm'=>-20,'baseline_set_at'=>'2026-11-01 00:00:00','baseline_n'=>5,'last_sample_rx_dbm'=>-28,'last_sample_at'=>'2026-11-12 12:00:00','bad_streak'=>2,'ok_streak'=>0];
    $net->applyPower(['power'=>[1=>$fields],'samples'=>[],'events'=>[[1,'rx_deviation',['state'=>'enter']],[1,'rx_deviation',['state'=>'exit']]]],'2026-11-12 12:00:00');
    $check((int)$net->powerStates(1)[1]['bad_streak']===2,'PDO persiste streaks');
    $check($store->latestDeviations(1)[1]['details']['state']==='exit' && count($store->eventsSince(1,'2026-01-01'))===2,'PDO ultimo evento por data e id');
    $maintenance=new App\Infrastructure\Net\Store\PdoNetMaintenanceStore($pdo);
    $i=['provider_id'=>1,'device_id'=>1,'scope_type'=>'pon','scope_id'=>1001,'created_at'=>'2026-11-12 12:00:00','silenced_until'=>'2026-11-14 12:00:00','reason'=>'teste'];
    $nBefore=(int)$pdo->query('SELECT COUNT(*) FROM net_interventions')->fetchColumn();
    try { $maintenance->intervene($i,[1]); $check(false,'auditoria ausente deveria falhar'); }
    catch(PDOException $e) { $check((int)$pdo->query('SELECT COUNT(*) FROM net_interventions')->fetchColumn()===$nBefore && (int)$net->powerStates(1)[1]['baseline_n']===5,'PDO rollback integral se auditoria falhar'); }
    $pdo->exec('CREATE TABLE audit_logs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,provider_id BIGINT UNSIGNED,actor_login VARCHAR(80),action VARCHAR(120),entity_type VARCHAR(80),entity_id BIGINT UNSIGNED,context_json JSON,created_at DATETIME)');
    $maintenance->intervene($i,[1]);
    $check($net->powerStates(1)[1]['baseline_rx_dbm']===null && (int)$net->powerStates(1)[1]['bad_streak']===0,'PDO intervencao zera referencia/streaks');
    $check($pdo->query('SELECT action FROM audit_logs')->fetchColumn()==='net.intervention.create','PDO auditoria');
    $pdo->exec("INSERT INTO net_onu_power_samples (onu_id,sampled_at,reason) VALUES (1,'2026-01-01','first'),(1,'2026-11-12','daily')");
    $check($maintenance->countExpired('samples',1,'2026-09-01')===1,'PDO conta expiradas');
    $check($maintenance->purgeBatch('samples',1,'2026-09-01')===1 && $maintenance->purgeBatch('events',1,'2026-01-01')===0,'PDO purga com join e limite');
    $check($maintenance->purgeBatch('alerts',1,'2027-01-01')===1,'PDO purga somente fechados');
    echo "Net033MigrationSmoke: $n verificacoes, zero falhas\n";
} finally {
    $pdo->exec('DROP DATABASE `isp_auxiliar_net033_sim`');
    echo "Schema descartavel removido.\n";
}
