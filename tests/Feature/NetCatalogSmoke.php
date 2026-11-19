<?php
declare(strict_types=1);
// CP-0/CP-2: catalogo de modelos, equipamento fisico, pre-cadastro, /rede-equipamentos com catalogo e credencial do banco, migrador de attrs. Schema descartavel.
$root = dirname(__DIR__, 2);
require $root . '/backend/bootstrap/autoload.php';
require $root . '/tests/support/ScratchSchema.php';

use App\Controllers\MapController;
use App\Controllers\NetCatalogController;
use App\Controllers\NetDeviceController;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Router;
use App\Core\View;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\Map\MapRepository;
use App\Infrastructure\Net\NetConnectionTester;
use App\Infrastructure\Net\NetCredentialRepository;
use App\Infrastructure\Net\NetDeviceLock;
use App\Infrastructure\Net\NetEquipmentMigrator;
use App\Infrastructure\Net\NetEquipmentRepository;
use App\Infrastructure\Net\NetSecretBox;
use App\Services\Net\MapNodeGateway;
use Tests\Support\ScratchSchema;

$n = 0;
$check = static function (bool $ok, string $m) use (&$n): void { $n++; if (!$ok) { throw new RuntimeException("FALHA: {$m}"); } };

$schema = (new ScratchSchema($root, 'netcatalog'))->create();
$tmp = sys_get_temp_dir() . '/netcatalog_' . getmypid(); mkdir($tmp, 0700);
try {
    $pdo = $schema->pdo;
    $local = new LocalRepository($schema->database, 'default');
    $providerId = (int) $local->currentProviderId();
    $pdo->exec("INSERT INTO providers (name,slug,status,created_at,updated_at) VALUES ('OUTRO','outro','active',NOW(),NOW())");
    $other = (int) $pdo->lastInsertId();
    $app = $schema->database->pdo();
    $repo = new NetEquipmentRepository($app);

    // --- pre-cadastro (so fatos): a migration semeia os provedores existentes; provedores novos recebem pelo seedFacts ---
    $repo->seedFacts($providerId); $repo->seedFacts($providerId); $repo->seedFacts($other);
    $models = $repo->models($providerId);
    $by = array_column($models, null, 'modelo');
    $check(isset($by['HA7004S'], $by['HA7308VX'], $by['a identificar']) && count($models) === 3, 'pre-cadastro: so os 3 modelos conhecidos');
    $check($by['HA7004S']['fabricante'] === 'Hioso' && $by['HA7004S']['tecnologia'] === 'EPON' && (array) $by['HA7004S']['portas'] === ['pon' => 4] && $by['HA7004S']['perfil_padrao'] === 'hioso-epon-25355-4-4-v3', 'HA7004S: Hioso, EPON, 4 PON, perfil de telemetria ativo');
    $check($by['HA7308VX']['tecnologia'] === 'EPON' && (array) $by['HA7308VX']['portas'] === ['pon' => 8] && $by['HA7308VX']['perfil_padrao'] === 'ha7308vx', 'HA7308VX: EPON, 8 PON, perfil ainda nao suportado');
    $check($by['a identificar']['fabricante'] === 'V-SOL' && $by['a identificar']['perfil_padrao'] === 'vsol' && $by['a identificar']['tecnologia'] === null, 'V-SOL: modelo "a identificar", sem tecnologia inventada, perfil ainda nao suportado');
    $check(count($repo->models($other)) === 3 && count($repo->equipment($providerId)) === 0, 'cada provedor recebe o catalogo; nenhum equipamento fisico inventado');

    // --- modelos: validacao ---
    [$id, $err] = $repo->saveModel($providerId, null, ['vendor' => 'Ubiquiti', 'model' => 'UF-OLT', 'type' => 'olt', 'technology' => 'GPON', 'ports' => ['pon' => '8', 'sfp' => '2', 'eth' => ''], 'spec' => ['potencia_max_dbm' => 7]]);
    $check($id !== null && $err === [], 'modelo valido criado');
    foreach ([['vendor' => '', 'model' => 'x'], ['vendor' => 'x', 'model' => ''], ['vendor' => 'x', 'model' => 'y', 'type' => 'zzz'], ['vendor' => 'x', 'model' => 'y', 'ports' => ['pon' => '999']], ['vendor' => 'x', 'model' => 'y', 'ports' => ['xyz' => '1']], ['vendor' => 'Ubiquiti', 'model' => 'UF-OLT'], ['vendor' => 'x', 'model' => 'y', 'default_profile_code' => 'a b!']] as $bad) {
        [$bid, $berr] = $repo->saveModel($providerId, null, $bad + ['type' => 'olt']);
        $check($bid === null && $berr !== [], 'modelo invalido recusado: ' . json_encode($bad));
    }

    // --- equipamento fisico ---
    $pdo->exec("INSERT INTO map_nodes (id,provider_id,node_type,name,lat,lng,created_at,updated_at) VALUES (8,$providerId,'pop','POP',-20.1,-42.1,NOW(),NOW())");
    $pdo->exec("INSERT INTO map_nodes (id,provider_id,node_type,name,parent_node_id,attrs_json,created_at,updated_at) VALUES (42,$providerId,'olt','OLT-001',8,'{\"tx_padrao_dbm\":5}',NOW(),NOW()),(43,$providerId,'olt','OLT-002',8,'{\"fabricante\":\"hioso\",\"modelo\":\"ha7308vx\"}',NOW(),NOW()),(44,$providerId,'olt','OLT-X',8,'{\"fabricante\":\"Marca\",\"modelo\":\"Inexistente\"}',NOW(),NOW())");
    $ha = (int) $by['HA7004S']['id'];
    [$e1, $err] = $repo->saveEquipment($providerId, null, ['model_id' => $ha, 'serial' => 'SN-1', 'mac' => '00-2e-c7-aa-bb-01', 'asset_tag' => 'PAT-1', 'status' => 'instalado', 'location_kind' => 'no_mapa', 'location_node_id' => 42], 'gestor');
    $row = $repo->equipmentRow($providerId, (int) $e1);
    $check($e1 !== null && $row['mac'] === '00:2E:C7:AA:BB:01' && $app->query('SELECT equipment_id FROM map_nodes WHERE id = 42')->fetchColumn() == $e1, 'equipamento criado: MAC normalizado e no do mapa aponta para ele (equipment_id)');
    [$dup, $err] = $repo->saveEquipment($providerId, null, ['model_id' => $ha, 'serial' => 'SN-2', 'status' => 'instalado', 'location_kind' => 'no_mapa', 'location_node_id' => 42], 'gestor');
    $check($dup === null && str_contains(implode(' ', $err), 'um ativo por elemento'), 'um ativo por no: segundo equipamento no mesmo no recusado');
    [$dupSerial, $err] = $repo->saveEquipment($providerId, null, ['model_id' => $ha, 'serial' => 'SN-1', 'status' => 'estoque', 'location_kind' => 'estoque'], 'gestor');
    $check($dupSerial === null && str_contains(implode(' ', $err), 'já cadastrado'), 'serie repetida no mesmo modelo recusada');
    [$st, $err] = $repo->saveEquipment($providerId, null, ['model_id' => $ha, 'serial' => 'SN-9', 'status' => 'estoque', 'location_kind' => 'estoque'], 'gestor');
    $check($st !== null && $app->query("SELECT COUNT(*) FROM map_nodes WHERE equipment_id = $st")->fetchColumn() == 0, 'equipamento em estoque: sem no do mapa (encaixa sem mudar tabelas)');
    foreach ([['model_id' => 999999], ['model_id' => $ha, 'mac' => 'zz'], ['model_id' => $ha, 'status' => 'quebrado'], ['model_id' => $ha, 'location_kind' => 'no_mapa'], ['model_id' => $ha, 'location_kind' => 'no_mapa', 'location_node_id' => 99999]] as $bad) {
        [$x, $err] = $repo->saveEquipment($providerId, null, $bad + ['status' => 'instalado', 'location_kind' => 'estoque'], 'g');
        $check($x === null && $err !== [], 'equipamento invalido: ' . json_encode($bad));
    }
    // mover para estoque libera o no e gera historico
    [$ok] = $repo->saveEquipment($providerId, (int) $e1, ['model_id' => $ha, 'serial' => 'SN-1', 'mac' => '00:2E:C7:AA:BB:01', 'asset_tag' => 'PAT-1', 'status' => 'manutencao', 'location_kind' => 'estoque'], 'gestor');
    $mv = $repo->movements($providerId, (int) $e1);
    $check($ok === (int) $e1 && $app->query('SELECT equipment_id FROM map_nodes WHERE id = 42')->fetchColumn() === null && count($mv) === 2 && $mv[0]['to_status'] === 'manutencao' && $mv[0]['from_status'] === 'instalado', 'mover para manutencao/estoque: no liberado e historico minimo (2 movimentos)');
    $repo->saveEquipment($providerId, (int) $e1, ['model_id' => $ha, 'serial' => 'SN-1', 'status' => 'instalado', 'location_kind' => 'no_mapa', 'location_node_id' => 42], 'gestor');
    $check($app->query('SELECT equipment_id FROM map_nodes WHERE id = 42')->fetchColumn() == $e1, 'voltar ao no: equipment_id restaurado');
    $check($repo->modelInUse($providerId, $ha) && !$repo->modelInUse($providerId, (int) $by['HA7308VX']['id']), 'modelo em uso detectado');

    // --- migrador de attrs ---
    $mig = new NetEquipmentMigrator($app, $repo);
    $sim = $mig->run(false, [42 => 'HA7004S']);
    $acts = array_column($sim['itens'], 'acao', 'no_id');
    $check($acts[42] === 'ja_ligado' && $acts[43] === 'ligar_ao_catalogo' && $acts[44] === 'sem_modelo_no_catalogo' && !$sim['aplicado'], 'simulacao: OLT-001 ja ligada, OLT-002 casa com o catalogo (case-insensitive), OLT-X sem modelo no catalogo');
    $check($app->query('SELECT equipment_id FROM map_nodes WHERE id = 43')->fetchColumn() === null && str_contains((string) $app->query('SELECT attrs_json FROM map_nodes WHERE id = 43')->fetchColumn(), 'ha7308vx'), 'simulacao nao grava nada');
    $pdo->exec("INSERT INTO map_nodes (id,provider_id,node_type,name,parent_node_id,created_at,updated_at) VALUES (45,$providerId,'olt','OLT-LINK',8,NOW(),NOW())");
    $ap = $mig->run(true, [45 => 'HA7004S']);
    $a43 = $app->query('SELECT equipment_id, attrs_json FROM map_nodes WHERE id = 43')->fetch(PDO::FETCH_ASSOC);
    $a45 = $app->query('SELECT equipment_id FROM map_nodes WHERE id = 45')->fetchColumn();
    $check($ap['aplicado'] && $a43['equipment_id'] !== null && $a43['attrs_json'] === null && $a45 !== null && (int) $app->query("SELECT model_id FROM net_equipment WHERE id = {$a45}")->fetchColumn() === $ha, '--apply: liga ao catalogo, tira fabricante/modelo do attrs_json e liga o no escolhido ao HA7004S');
    $check($app->query('SELECT attrs_json FROM map_nodes WHERE id = 44')->fetchColumn() !== null && $mig->run(true)['itens'][0]['acao'] !== 'erro', 'no sem correspondencia nao e alterado; repetir e seguro');

    // --- controller: catalogo ---
    $mapRepo = new MapRepository($schema->database);
    $box = new NetSecretBox(random_bytes(32));
    $creds = new NetCredentialRepository($schema->database->pdo(), $box);
    $cat = new NetCatalogController(new View($root . '/backend/Views'), new Config(['app' => ['name' => 'T']]), $local, $schema->database, $mapRepo, $creds);
    $mgr = static function (): void { $_SESSION = ['user' => ['login' => 'zz_gestor', 'name' => 'ZZ', 'role' => 'manager', 'id' => 0]]; };
    $tec = static function (): void { $_SESSION = ['user' => ['login' => 'zz_tec', 'name' => 'ZZ', 'role' => 'technician', 'id' => 0]]; };
    $post = static fn ($c, string $m, array $f, bool $csrf = true) => $c->$m(new Request('POST', '/x', '', [], $f + ($csrf ? ['_csrf' => Csrf::token('rede')] : [])));
    $get = static fn ($c, string $m, array $q = []) => $c->$m(new Request('GET', '/x', '', $q));
    $json = static fn ($r): array => json_decode($r->body(), true) ?? [];
    $mgr();
    $r = $get($cat, 'models'); $j = $json($r);
    $check($r->status() === 200 && count($j['data']['modelos']) === 4 && $j['data']['tipos'] === NetEquipmentRepository::TYPES, 'GET modelos');
    $check($post($cat, 'modelSave', ['fabricante' => 'X', 'modelo' => 'Y'], false)->status() === 419, 'modelo sem CSRF: 419');
    $r = $post($cat, 'modelSave', ['fabricante' => 'Fab', 'modelo' => 'Mod 1', 'tipo' => 'switch', 'portas_eth' => '24', 'portas_sfp' => '4', 'ficha' => '{"poe":true}']);
    $mid = (int) ($json($r)['data']['id'] ?? 0);
    $check($r->status() === 200 && $mid > 0, 'POST modelo valido');
    $check($post($cat, 'modelSave', ['fabricante' => 'Fab', 'modelo' => 'Mod 1', 'tipo' => 'switch'])->status() === 422 && $post($cat, 'modelSave', ['fabricante' => 'F', 'modelo' => 'M', 'ficha' => '{lixo'])->status() === 422, 'modelo duplicado ou ficha JSON invalida: 422');
    $check($post($cat, 'modelSave', ['id' => (string) $mid, 'fabricante' => 'Fab', 'modelo' => 'Mod 1b', 'tipo' => 'switch'])->status() === 200, 'editar modelo');
    $check($post($cat, 'modelDelete', ['id' => (string) $ha])->status() === 409, 'excluir modelo em uso: 409');
    $check($post($cat, 'modelDelete', ['id' => (string) $mid])->status() === 200 && $post($cat, 'modelDelete', ['id' => (string) $mid])->status() === 404, 'excluir modelo livre: 200; de novo 404');
    $foreignModel = (int) $app->query("SELECT id FROM net_equipment_models WHERE provider_id = $other LIMIT 1")->fetchColumn();
    $check($post($cat, 'modelDelete', ['id' => (string) $foreignModel])->status() === 404, 'modelo de outro provedor: 404');
    $r = $get($cat, 'equipment', ['historico' => (string) $e1]); $j = $json($r);
    $check($r->status() === 200 && count($j['data']['equipamentos']) >= 3 && count($j['data']['historico']) >= 2 && in_array('OLT-001', array_column($j['data']['nos'], 'nome'), true), 'GET inventario: equipamentos, nos do mapa e historico');
    $r = $post($cat, 'equipmentSave', ['modelo_id' => (string) $ha, 'serie' => 'SN-77', 'situacao' => 'estoque', 'local_tipo' => 'estoque']);
    $check($r->status() === 200 && $post($cat, 'equipmentSave', ['modelo_id' => '999', 'situacao' => 'estoque', 'local_tipo' => 'estoque'])->status() === 422 && $post($cat, 'equipmentSave', ['id' => '9999', 'modelo_id' => (string) $ha, 'situacao' => 'estoque', 'local_tipo' => 'estoque'])->status() === 404, 'POST inventario: ok, modelo invalido 422, id inexistente 404');
    $tec();
    foreach ([['models', 'get'], ['equipment', 'get']] as [$m]) { $check($get($cat, $m)->status() === 403, "tecnico GET {$m}: 403"); }
    foreach (['modelSave', 'modelDelete', 'equipmentSave'] as $m) { $check($post($cat, $m, ['id' => '1'])->status() === 403, "tecnico POST {$m}: 403"); }
    $check($cat->modelsPage(new Request('GET', '/rede-modelos', ''))->status() === 302 && $cat->equipmentPage(new Request('GET', '/rede-inventario', ''))->status() === 302, 'tecnico: paginas redirecionam');
    $mgr();
    $pg = $cat->equipmentPage(new Request('GET', '/rede-inventario', ''));
    $check($pg->status() === 200 && str_contains($pg->body(), 'data-section="inventario"') && str_contains($pg->body(), 'class="rc-tabs"') && str_contains($pg->body(), '/rede-credenciais') && str_contains($pg->body(), '/rede-modelos'), 'pagina do inventario com abas');
    $audit = (string) json_encode($app->query('SELECT action FROM audit_logs')->fetchAll(PDO::FETCH_COLUMN));
    $check(str_contains($audit, 'net.model.create') && str_contains($audit, 'net.model.delete') && str_contains($audit, 'net.equipment.create'), 'auditoria: net.model.* e net.equipment.*');
    $router = new Router(); (require $root . '/backend/routes.php')($router);
    foreach ([['GET', '/rede-modelos'], ['GET', '/api/rede-modelos'], ['POST', '/api/rede-modelos'], ['POST', '/api/rede-modelos/excluir'], ['GET', '/rede-inventario'], ['GET', '/api/rede-inventario'], ['POST', '/api/rede-inventario']] as [$m, $p]) { $check($router->match($m, $p) !== null, "rota {$m} {$p}"); }

    // --- /rede-equipamentos usa o catalogo e a credencial do banco ---
    $gateway = new MapNodeGateway(new MapController($local, $mapRepo));
    $tester = new NetConnectionTester(new NetDeviceLock($tmp), null, $creds);
    $dev = new NetDeviceController(new View($root . '/backend/Views'), new Config(['app' => ['name' => 'T']]), $local, $schema->database, $mapRepo, $gateway, $tester, $creds);
    $cid = $creds->create($providerId, 'OLT 89', 'snmp_v2c', null, 'segredo-DB-777', 'g');
    $pdo->exec("INSERT INTO map_ports (provider_id,node_id,port_num,label,port_role,media,created_at,updated_at) SELECT $providerId,45,n,CONCAT('PON ',n),'pon','pon',NOW(),NOW() FROM (SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4) t");
    $l = $json($get($dev, 'list'))['data'];
    $check(in_array('HA7004S', array_column(array_map(static fn ($m) => ['m' => explode(' ', $m['rotulo'], 2)[1]], $l['modelos']), 'm'), true) && $l['credenciais_banco'][0]['nome'] === 'OLT 89' && !str_contains(json_encode($l), 'segredo-DB-777'), 'list: modelos do catalogo (so OLT) e credenciais do banco, sem segredo');
    $r = $post($dev, 'save', ['node_id' => '45', 'perfil' => '3', 'mgmt_host' => '192.168.0.60', 'credential_ref' => 'id:' . $cid, 'modelo_id' => (string) $ha, 'serie' => 'SN-NEW', 'patrimonio' => 'PAT-NEW', 'fabricante' => 'texto-livre', 'modelo' => 'texto-livre']);
    $d = $app->query('SELECT * FROM net_devices WHERE map_node_id = 45')->fetch(PDO::FETCH_ASSOC);
    $check($r->status() === 200 && (int) $d['credential_id'] === $cid && $d['credential_ref'] === null && (int) $d['enabled'] === 0 && !str_contains($r->body(), 'segredo-DB-777'), 'cadastrar OLT com credencial do banco: coleta nasce DESATIVADA, credential_id gravado, segredo fora da resposta');
    $row = $app->query('SELECT n.attrs_json, e.serial, e.asset_tag FROM map_nodes n JOIN net_equipment e ON e.id = n.equipment_id WHERE n.id = 45')->fetch(PDO::FETCH_ASSOC);
    $check($row['serial'] === 'SN-NEW' && $row['asset_tag'] === 'PAT-NEW' && !str_contains((string) $row['attrs_json'], 'texto-livre'), 'OLT: equipamento fisico (serie/patrimonio) ligado ao no; texto livre nao vira segunda origem');
    $lst = array_values(array_filter($json($get($dev, 'list'))['data']['equipamentos'], static fn ($x) => $x['node_id'] === 45))[0];
    $check($lst['fabricante'] === 'Hioso' && $lst['modelo'] === 'HA7004S' && $lst['credencial_id'] === $cid && $lst['credencial_nome'] === 'OLT 89' && $lst['serie'] === 'SN-NEW', 'lista da OLT le fabricante/modelo do catalogo e mostra a credencial por nome');
    $check($post($dev, 'save', ['node_id' => '45', 'device_id' => (string) $d['id'], 'perfil' => '3', 'mgmt_host' => '192.168.0.60', 'credential_ref' => 'id:999999'])->status() === 422, 'credencial do banco inexistente: 422');
    $check($post($dev, 'save', ['node_id' => '45', 'device_id' => (string) $d['id'], 'perfil' => '3', 'mgmt_host' => '192.168.0.60', 'credential_ref' => 'id:' . $cid, 'modelo_id' => '999999'])->status() === 422, 'modelo do catalogo inexistente: 422');
    $r = $post($dev, 'toggle', ['device_id' => (string) $d['id'], 'ativar' => '1', 'confirmar' => '1']);
    $check($r->status() === 200, 'ativar coleta com credencial do banco (chave disponivel)');
    $noKey = new NetDeviceController(new View($root . '/backend/Views'), new Config(['app' => ['name' => 'T']]), $local, $schema->database, $mapRepo, $gateway, $tester, new NetCredentialRepository($schema->database->pdo(), new NetSecretBox(null)));
    $post($noKey, 'toggle', ['device_id' => (string) $d['id'], 'ativar' => '0']);
    $check($post($noKey, 'toggle', ['device_id' => (string) $d['id'], 'ativar' => '1', 'confirmar' => '1'])->status() === 422, 'sem NET_SECRET_KEY: nao ativa coleta de equipamento com credencial do banco');
} finally {
    $schema->drop();
    @array_map('unlink', glob($tmp . '/*') ?: []); @rmdir($tmp);
}
echo "NetCatalogSmoke: {$n} verificacoes, zero falhas\n";
