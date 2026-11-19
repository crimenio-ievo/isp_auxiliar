<?php
declare(strict_types=1);
// Schema descartavel isp_auxiliar_browser_sim com 1 OLT, 4 ONUs, 1 CTO e vinculos FICTICIOS (4 clientes na mesma ONU) + sessao de harness.
// Imprime JSON {sessionDir, sessionId}. Teardown: php tests/browser/scratch_down.php <sessionDir>
$root = dirname(__DIR__, 2);
require $root . '/backend/bootstrap/autoload.php';
require $root . '/tests/support/ScratchSchema.php';

use App\Controllers\MapController;
use App\Core\Csrf;
use App\Core\Request;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\Map\MapRepository;
use Tests\Support\ScratchSchema;

$schema = (new ScratchSchema($root, 'browser'))->create();
$pdo = $schema->pdo;
$local = new LocalRepository($schema->database, 'default');
$ctl = new MapController($local, new MapRepository($schema->database));
$_SESSION = ['user' => ['login' => 'zz_harness', 'name' => 'Harness', 'role' => 'manager', 'id' => 0]];
$mk = static function (array $f) use ($ctl): int {
    $r = $ctl->createNode(new Request('POST', '/x', '', [], $f + ['_csrf' => Csrf::token('map')]));
    return (int) (json_decode($r->body(), true)['data']['node']['id'] ?? 0);
};
$pop = $mk(['node_type' => 'pop', 'name' => 'POP-T', 'lat' => '-20.8550', 'lng' => '-42.8010']);
$olt = $mk(['node_type' => 'olt', 'name' => 'OLT-T', 'parent_node_id' => $pop, 'ports_pon' => '2']);
$cto = $mk(['node_type' => 'cto', 'name' => 'CTO-T1', 'lat' => '-20.8570', 'lng' => '-42.8010', 'attrs' => '{"capacity":4}']);
$cto2 = $mk(['node_type' => 'cto', 'name' => 'CTO-T2', 'lat' => '-20.8570', 'lng' => '-42.7990', 'attrs' => '{"capacity":4}']);
$pid = (int) $local->currentProviderId();
$pdo->exec("USE `{$schema->schema}`");
$pdo->exec("INSERT INTO net_device_profiles (code,vendor,technology,version,profile_json,status,created_at,updated_at) VALUES ('p-teste','X','epon',1,'{}','active',NOW(),NOW())");
$prof = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO net_devices (id,provider_id,map_node_id,profile_id,mgmt_host,credential_ref,enabled,created_at,updated_at) VALUES (1,$pid,$olt,$prof,'192.0.2.1','NET_CRED_X',0,NOW(),NOW())");
foreach ([[1, 1, '006D61AA0010'], [2, 2, '006D61BB0020'], [3, 3, 'AABBCC000001']] as [$id, $idx, $mac]) {
    $pdo->exec("INSERT INTO net_onus (id,provider_id,device_id,slot,pon_index,onu_index,snmp_index,onu_identifier,identifier_type,first_seen_at,last_seen_at) VALUES ($id,$pid,1,1,1,$idx,'1.1.$idx','$mac','epon_mac',NOW(),NOW())");
    $pdo->exec("INSERT INTO net_onu_state (onu_id,oper_status,raw_status_code,status_since,stale,updated_at) VALUES ($id,'online',1,NOW(),0,NOW())");
}
$port = (int) $pdo->query("SELECT id FROM map_ports WHERE node_id=$cto AND port_num=1")->fetchColumn();
foreach ([['cliente_a', 'Cliente Teste A', 'pppoe_na_onu'], ['cliente_b', 'Cliente Teste B', 'atras_da_onu'], ['cliente_c', 'Cliente Teste C', null], ['cliente_d', 'Cliente Teste D', 'pppoe_na_onu']] as [$l, $n, $m]) {
    $modo = $m === null ? 'NULL' : "'$m'";
    $pdo->exec("INSERT INTO client_network_bindings (provider_id,mkauth_login,cliente_nome,cto_node_id,cto_port_id,onu_id,origem,modo_pppoe,ativo,created_at,updated_at) VALUES ($pid,'$l','$n',$cto,$port,1,'manual',$modo,1,NOW(),NOW())");
}
$pdo->exec("INSERT INTO client_network_bindings (provider_id,mkauth_login,cliente_nome,onu_id,origem,ativo,created_at,updated_at) VALUES ($pid,'cliente_e','Cliente Teste E',2,'manual',1,NOW(),NOW())");

// "MkAuth" FICTICIO (schema local descartavel) para a ficha da ONU: so SELECT, com colunas sensiveis presentes para provar que nao saem.
$pdo->exec('DROP DATABASE IF EXISTS isp_auxiliar_mksim');
$pdo->exec('CREATE DATABASE isp_auxiliar_mksim');
$pdo->exec('CREATE TABLE isp_auxiliar_mksim.sis_plano (nome VARCHAR(60), uuid_plano VARCHAR(40))');
$pdo->exec('CREATE TABLE isp_auxiliar_mksim.sis_cliente (id INT AUTO_INCREMENT PRIMARY KEY, login VARCHAR(60), nome VARCHAR(100), bairro VARCHAR(60), cidade VARCHAR(60), endereco VARCHAR(100), numero VARCHAR(20), coordenadas VARCHAR(60), plano VARCHAR(60), bloqueado VARCHAR(5), status_corte VARCHAR(10), cli_ativado VARCHAR(5), cpf_cnpj VARCHAR(20), celular VARCHAR(20), email VARCHAR(80))');
$pdo->exec("INSERT INTO isp_auxiliar_mksim.sis_plano VALUES ('Plano 100M', 'u1')");
foreach ([['cliente_a', 'Cliente Teste A', 'Centro', 'Rua das Flores', '10', '-20.8571,-42.8011', 'nao', 's'], ['cliente_b', 'Cliente Teste B', 'Centro', 'Rua B', '20', '-20.8572,-42.8012', 'sim', 's'], ['cliente_c', 'Cliente Teste C', 'Vila Nova', 'Rua C', '30', '', 'nao', 'n'], ['cliente_d', 'Cliente Teste D', 'Vila Nova', 'Rua D', '40', '-20.8574,-42.8014', 'nao', 's'], ['cliente_e', 'Cliente Teste E', 'Alto', 'Rua E', '50', '-20.8551,-42.7992', 'nao', 's']] as [$l, $n, $b, $e, $nu, $co, $bl, $at]) {
    $pdo->exec("INSERT INTO isp_auxiliar_mksim.sis_cliente (login,nome,bairro,cidade,endereco,numero,coordenadas,plano,bloqueado,status_corte,cli_ativado,cpf_cnpj,celular,email) VALUES ('$l','$n','$b','Coimbra','$e','$nu','$co','Plano 100M','$bl','ok','$at','11122233344','31988887777','sigiloso@exemplo.com')");
}
foreach (['mkauth_db_host' => 'localhost', 'mkauth_db_name' => 'isp_auxiliar_mksim', 'mkauth_db_user' => 'root', 'mkauth_db_password' => '', 'mkauth_db_port' => '3306'] as $k => $v) {
    $pdo->exec("INSERT INTO provider_settings (provider_id, setting_key, setting_value, is_secret, updated_at) VALUES ($pid, '$k', '$v', " . ($k === 'mkauth_db_password' ? 1 : 0) . ', NOW())');
}

$dir = sys_get_temp_dir() . '/mapa_harness_' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
session_save_path($dir);
session_name('isp_auxiliar_session');
$id = 'harness' . bin2hex(random_bytes(8));
session_id($id);
session_start();
$_SESSION = ['user' => ['login' => 'zz_harness', 'name' => 'Harness', 'role' => 'manager', 'id' => 0]];
session_write_close();
echo json_encode(['sessionDir' => $dir, 'sessionId' => $id, 'schema' => $schema->schema]), "\n";
