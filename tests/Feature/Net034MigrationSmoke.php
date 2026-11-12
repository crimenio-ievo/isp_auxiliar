<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';
// Exclusivamente socket local, sem .env, sem dados do banco da aplicacao. Schema descartavel proprio.
$pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$schema = 'isp_auxiliar_net034_sim';
$root = dirname(__DIR__, 2);
$n = 0;
$check = static function (bool $ok, string $msg) use (&$n): void { if (!$ok) { throw new RuntimeException($msg); } $n++; };
$apply = static function (string $file) use ($pdo): void {
    if (!is_file($file)) { throw new RuntimeException('SQL inexistente: ' . basename($file)); }
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
    foreach (explode(';', $sql) as $s) { if (trim($s) !== '') { $pdo->exec($s); } }
};
$exists = static fn (): bool => (int) $GLOBALS['pdo']->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='net_onu_outages'")->fetchColumn() === 1;
if ($pdo->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$schema'")->fetchColumn()) {
    throw new RuntimeException('Schema descartavel ja existe: preservar e parar.');
}
$pdo->exec("CREATE DATABASE `$schema`");
try {
    $pdo->exec("USE `$schema`");
    $pdo->exec('CREATE TABLE providers (id BIGINT UNSIGNED PRIMARY KEY)');
    $pdo->exec('CREATE TABLE map_nodes (id BIGINT UNSIGNED PRIMARY KEY)');
    $pdo->exec('CREATE TABLE schema_migrations (version VARCHAR(190) PRIMARY KEY,filename VARCHAR(190))');
    foreach (['030_net_telemetry_core.sql', '031_net_profile_hioso_v2.sql', '032_net_power.sql', '033_net_alerts.sql'] as $f) { $apply("$root/database/migrations/$f"); }
    $pdo->exec('INSERT INTO providers VALUES (1)');
    $pdo->exec('INSERT INTO map_nodes VALUES (1)');
    $pdo->exec("INSERT INTO net_devices (id,provider_id,map_node_id,profile_id,mgmt_host,credential_ref,created_at,updated_at) SELECT 1,1,1,id,'fake','FAKE',NOW(),NOW() FROM net_device_profiles WHERE version=3");
    $pdo->exec("INSERT INTO net_onus (id,provider_id,device_id,pon_index,onu_index,snmp_index,onu_identifier,identifier_type,first_seen_at,last_seen_at) VALUES (1,1,1,1,1,'1.1.1','AABBCC000001','epon_mac',NOW(),NOW())");
    $before = (int) $pdo->query('SELECT COUNT(*) FROM net_onu_events')->fetchColumn();
    $file = "$root/database/migrations/034_net_onu_outages.sql";
    $apply($file); $apply($file);
    $check($exists(), 'tabela criada e reaplicavel');
    $ins = "INSERT INTO net_onu_outages (provider_id,device_id,onu_id,onu_identifier,source,event_key,started_at,cause,imported_at,updated_at) VALUES (1,1,1,'AABBCC000001','log',?, '2026-11-10 08:59:05','energia',NOW(),NOW())";
    $st = $pdo->prepare($ins); $key = str_repeat('a', 64);
    $st->execute([$key]);
    try { $st->execute([$key]); $check(false, 'duplicata aceita'); } catch (PDOException $e) { $check((int) $e->errorInfo[1] === 1062, 'unique device+source+event_key'); }
    $pdo->prepare(str_replace("'log'", "'poll'", $ins))->execute([$key]);
    $check((int) $pdo->query('SELECT COUNT(*) FROM net_onu_outages')->fetchColumn() === 2, 'mesma chave em source distinto convive');
    try { $pdo->exec("INSERT INTO net_onu_outages (provider_id,device_id,onu_identifier,event_key,started_at,imported_at,updated_at) VALUES (1,99,'X','k',NOW(),NOW(),NOW())"); $check(false, 'FK device'); } catch (PDOException $e) { $check((int) $e->errorInfo[1] === 1452, 'FK device exige equipamento'); }
    $pdo->exec("INSERT INTO net_onu_outages (provider_id,device_id,onu_id,onu_identifier,event_key,started_at,imported_at,updated_at) VALUES (1,1,NULL,'FFEEDD000009','z',NOW(),NOW(),NOW())");
    $check((int) $pdo->query('SELECT COUNT(*) FROM net_onu_outages WHERE onu_id IS NULL')->fetchColumn() === 1, 'MAC sem ONU cadastrada aceito (onu_id NULL)');
    $pdo->exec('DELETE FROM net_onus WHERE id=1');
    $check((int) $pdo->query('SELECT COUNT(*) FROM net_onu_outages WHERE onu_id IS NULL')->fetchColumn() === 3, 'apagar ONU preserva a queda (SET NULL)');
    $check((int) $pdo->query('SELECT COUNT(*) FROM net_onu_events')->fetchColumn() === $before, '034 nao altera net_onu_events');
    $pdo->exec("INSERT INTO schema_migrations VALUES ('034_net_onu_outages.sql','034_net_onu_outages.sql')");
    $apply("$root/scripts/rollback_034_net_onu_outages.sql");
    $check(!$exists() && (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 0, 'rollback remove tabela e metadado');
    $apply($file);
    $check($exists() && (int) $pdo->query('SELECT COUNT(*) FROM net_onu_outages')->fetchColumn() === 0, 'reaplicar apos rollback');
    echo "Net034MigrationSmoke: $n verificacoes, zero falhas\n";
} finally {
    $pdo->exec('DROP DATABASE `isp_auxiliar_net034_sim`');
    echo "Schema descartavel removido.\n";
}
