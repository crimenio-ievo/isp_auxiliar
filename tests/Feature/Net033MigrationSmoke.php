<?php
declare(strict_types=1);
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
    $apply("$root/scripts/rollback_033_net_alerts.sql");
    $check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='net_alerts'")->fetchColumn() === 0, 'rollback');
    $apply($file);
    $check((int) $pdo->query('SELECT COUNT(*) FROM net_alerts')->fetchColumn() === 0, 'reaplicar apos rollback');
    echo "Net033MigrationSmoke: $n verificacoes, zero falhas\n";
} finally {
    $pdo->exec('DROP DATABASE `isp_auxiliar_net033_sim`');
    echo "Schema descartavel removido.\n";
}
