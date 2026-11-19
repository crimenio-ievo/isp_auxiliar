<?php
declare(strict_types=1);
// Fase 2: /rede-equipamentos (rotas, permissao, CSRF, validacao, cadastro, ativacao, "testar conexao"). Schema descartavel; sem rede real.
$root = dirname(__DIR__, 2);
require $root . '/backend/bootstrap/autoload.php';
require $root . '/tests/support/ScratchSchema.php';

use App\Controllers\MapController;
use App\Controllers\NetDeviceController;
use App\Core\Application;
use App\Core\Config;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\Map\MapRepository;
use App\Infrastructure\Net\NetConnectionTester;
use App\Infrastructure\Net\NetDeviceLock;
use App\Infrastructure\Net\Snmp\FakeSnmpTransport;
use App\Services\Net\MapNodeGateway;
use Tests\Support\ScratchSchema;

$n = 0;
$check = static function (bool $ok, string $m) use (&$n): void { $n++; if (!$ok) { throw new RuntimeException("FALHA: {$m}"); } };

// --- Rotas e anonimo (sem banco) ---
$router = new Router();
(require $root . '/backend/routes.php')($router);
foreach ([['GET', '/rede-equipamentos'], ['GET', '/api/rede-equipamentos'], ['POST', '/api/rede-equipamentos'], ['POST', '/api/rede-equipamentos/coleta'], ['POST', '/api/rede-equipamentos/testar']] as [$m, $p]) {
    $check($router->match($m, $p) !== null, "rota {$m} {$p}");
}
$check($router->match('GET', '/api/rede-equipamentos/testar') === null && $router->match('GET', '/api/rede-equipamentos/coleta') === null, 'testar e coleta nao existem por GET');
$_SESSION = [];
$app = new Application(new Config([]), new Container(), $router);
foreach ([['GET', '/rede-equipamentos'], ['GET', '/api/rede-equipamentos'], ['POST', '/api/rede-equipamentos/testar']] as [$m, $p]) {
    $resp = $app->handle(new Request($m, $p));
    $check($resp->status() === 302 && $resp->header('Location') === '/login', "anonimo em {$m} {$p} vai para o login");
}

// --- Fixture ---
$tmp = sys_get_temp_dir() . '/net-dev-cadastro-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);
file_put_contents($tmp . '/.env', "NET_CRED_TESTE_A=segredo-que-nunca-aparece\nNET_CRED_TESTE_B=outro-segredo-xyz\nOUTRA_CHAVE=nao-lista\n");
Env::load($tmp . '/.env');
$SECRETS = ['segredo-que-nunca-aparece', 'outro-segredo-xyz'];

$schema = (new ScratchSchema($root, 'netdev'))->create();
try {
    $pdo = $schema->pdo;
    $local = new LocalRepository($schema->database, 'default');
    $providerId = (int) $local->currentProviderId();
    $check($providerId > 0, 'provedor padrao existe');
    $pdo->exec("INSERT INTO providers (name,slug,status,created_at,updated_at) VALUES ('OUTRO','outro','active',NOW(),NOW())");
    $otherProvider = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO map_nodes (id,provider_id,node_type,name,lat,lng,created_at,updated_at) VALUES (8,$providerId,'pop','POP-TESTE',-20.1,-42.1,NOW(),NOW())");
    // OLT ja no mapa, com PON 1..4 (pronta) e outra sem portas PON; OLT de outro provedor.
    $pdo->exec("INSERT INTO map_nodes (id,provider_id,node_type,name,parent_node_id,created_at,updated_at) VALUES (42,$providerId,'olt','OLT-EXISTE',8,NOW(),NOW()),(43,$providerId,'olt','OLT-SEM-PON',8,NOW(),NOW()),(60,$otherProvider,'olt','OLT-DE-OUTRO',NULL,NOW(),NOW())");
    for ($i = 1; $i <= 4; $i++) { $pdo->exec("INSERT INTO map_ports (provider_id,node_id,port_num,label,port_role,media,created_at,updated_at) VALUES ($providerId,42,$i,'PON $i','pon','pon',NOW(),NOW())"); }

    $mapRepo = new MapRepository($schema->database);
    $gateway = new MapNodeGateway(new MapController($local, $mapRepo));
    $lockDir = $tmp . '/locks'; mkdir($lockDir, 0700);
    $mode = 'ok';
    $transports = new ArrayObject();
    $factory = static function (string $host, string $secret) use (&$mode, $transports): FakeSnmpTransport {
        $mib = ['1.3.6.1.2.1.1.5.0' => ['STRING', 'OLT-FAKE'], '1.3.6.1.2.1.1.1.0' => ['STRING', "Fake OLT\nv1"], '1.3.6.1.2.1.1.3.0' => ['Timeticks', '12345600']];
        $t = new FakeSnmpTransport($mib, $mode === 'timeout' ? static fn () => 'timeout' : null);
        $transports[] = [$host, $t];
        return $t;
    };
    $tester = new NetConnectionTester(new NetDeviceLock($lockDir), $factory);
    $ctl = new NetDeviceController(new View($root . '/backend/Views'), new Config(['app' => ['name' => 'T']]), $local, $schema->database, $mapRepo, $gateway, $tester);

    $asManager = static function (): void { $_SESSION = ['user' => ['login' => 'zz_gestor', 'name' => 'ZZ', 'role' => 'manager', 'id' => 0]]; };
    $asTech = static function (): void { $_SESSION = ['user' => ['login' => 'zz_tec', 'name' => 'ZZ', 'role' => 'technician', 'id' => 0]]; };
    $json = static fn (Response $r): array => (array) json_decode($r->body(), true);
    $post = static function (string $method, array $fields, bool $csrf = true) use ($ctl): Response {
        if ($csrf) { $fields['_csrf'] = Csrf::token('rede'); }
        return $ctl->{$method}(new Request('POST', '/x', '', [], $fields));
    };
    $audit = static fn (string $action): int => (int) $GLOBALS['pdo']->query("SELECT COUNT(*) FROM audit_logs WHERE action = '$action'")->fetchColumn();
    $GLOBALS['pdo'] = $pdo;
    $noSecret = static function (Response $r) use ($SECRETS): bool { foreach ($SECRETS as $s) { if (str_contains($r->body(), $s)) { return false; } } return true; };

    // --- Permissao ---
    $asTech();
    $check($ctl->list(new Request('GET', '/api/rede-equipamentos'))->status() === 403, 'tecnico nao le a lista (403)');
    $check($post('save', ['perfil' => '1'])->status() === 403, 'tecnico nao grava (403)');
    $check($post('toggle', ['device_id' => '1'])->status() === 403 && $post('test', ['device_id' => '1'])->status() === 403, 'tecnico nao ativa nem testa (403)');
    $page = $ctl->page(new Request('GET', '/rede-equipamentos'));
    $check($page->status() === 302 && str_contains((string) $page->header('Location'), '/dashboard'), 'tecnico na pagina vai para /dashboard');
    $asManager();
    $page = $ctl->page(new Request('GET', '/rede-equipamentos'));
    $check($page->status() === 200 && str_contains($page->body(), 'id="rc-app"') && str_contains($page->body(), 'data-csrf="' . Csrf::token('rede') . '"') && str_contains($page->body(), 'rede-equipamentos.js'), 'gestor ve a pagina com token CSRF do escopo rede');
    $check(!str_contains($page->body(), 'segredo-que-nunca'), 'pagina sem credencial');

    // --- CSRF ---
    foreach (['save', 'toggle', 'test'] as $m) {
        $r = $post($m, ['perfil' => '1', 'device_id' => '1'], false);
        $check($r->status() === 419, "{$m} sem CSRF = 419");
    }
    $wrong = $ctl->save(new Request('POST', '/x', '', [], ['_csrf' => Csrf::token('map')]));
    $check($wrong->status() === 419, 'token de outro escopo (map) nao vale');
    $viaHeader = $ctl->save(new Request('POST', '/x', '', [], [], ['X-CSRF-Token' => Csrf::token('rede')]));
    $check($viaHeader->status() === 422, 'token no cabecalho X-CSRF-Token e aceito (cai na validacao)');

    // --- Lista inicial ---
    $d = $json($ctl->list(new Request('GET', '/api/rede-equipamentos')))['data'];
    $check(count($d['equipamentos']) === 2 && $d['equipamentos'][0]['nome'] === 'OLT-EXISTE' && $d['equipamentos'][0]['device_id'] === null, 'lista: so as OLTs do provedor (sem a de outro), sem coleta');
    $check($d['equipamentos'][0]['saude']['estado'] === 'sem_coleta' && $d['equipamentos'][0]['site'] === 'POP-TESTE', 'saude sem_coleta e site');
    $check(count($d['perfis']['suportados']) === 1 && $d['perfis']['suportados'][0]['code'] === 'hioso-epon-25355-4-4-v3', 'so o perfil ativo v3 e suportado (v1 e v2 deprecated)');
    $check(array_column($d['perfis']['planejados'], 'code') === ['vsol', 'ha7308vx', 'outro'], 'perfis planejados (nao suportados)');
    $check($d['credenciais'] === ['NET_CRED_TESTE_A', 'NET_CRED_TESTE_B'], 'so os NOMES NET_CRED_* do .env');
    $raw = $ctl->list(new Request('GET', '/api/rede-equipamentos'));
    $check($noSecret($raw) && !str_contains($raw->body(), 'OUTRA_CHAVE'), 'lista nunca traz valor de credencial nem chave fora do prefixo');
    $profileId = (string) $d['perfis']['suportados'][0]['id'];
    $base = ['perfil' => $profileId, 'mgmt_host' => '192.168.0.10', 'credential_ref' => 'NET_CRED_TESTE_A', 'node_id' => '42'];

    // --- Validacao (422) ---
    foreach ([
        ['IP de loopback', ['mgmt_host' => '127.0.0.1']], ['IP de multicast', ['mgmt_host' => '224.0.0.5']], ['nome de host', ['mgmt_host' => 'olt.local']], ['IP vazio', ['mgmt_host' => '']],
        ['credencial fora do .env', ['credential_ref' => 'NET_CRED_NAO_EXISTE']], ['credencial sem prefixo', ['credential_ref' => 'OUTRA_CHAVE']],
        ['perfil inexistente', ['perfil' => '999999']], ['perfil deprecated', ['perfil' => '1']], ['perfil vazio', ['perfil' => '']],
        ['intervalo de status baixo', ['intervalo_status_s' => '10']], ['intervalo de potencia alto', ['intervalo_potencia_s' => '999999']], ['intervalo nao numerico', ['intervalo_status_s' => 'abc']],
        ['no inexistente', ['node_id' => '999']], ['no de outro provedor', ['node_id' => '60']], ['fabricante longo', ['fabricante' => str_repeat('x', 81)]],
        ['OLT sem PON 1..N', ['node_id' => '43']],
    ] as [$label, $over]) {
        $r = $post('save', $over + $base);
        $check($r->status() === 422 && ($json($r)['ok'] ?? true) === false, "validacao: {$label} -> 422");
    }
    $check((int) $pdo->query('SELECT COUNT(*) FROM net_devices')->fetchColumn() === 0, 'nada gravado em net_devices pelas validacoes');
    $r = $post('save', ['node_mode' => 'new', 'name' => '', 'site_id' => '8', 'ports_pon' => '4'] + array_diff_key($base, ['node_id' => 1]));
    $check($r->status() === 422, 'criar no: nome obrigatorio');
    $r = $post('save', ['node_mode' => 'new', 'name' => 'OLT-NOVA', 'site_id' => '999', 'ports_pon' => '4'] + array_diff_key($base, ['node_id' => 1]));
    $check($r->status() === 422, 'criar no: site invalido');
    $r = $post('save', ['node_mode' => 'new', 'name' => 'OLT-NOVA', 'site_id' => '8', 'ports_pon' => '0'] + array_diff_key($base, ['node_id' => 1]));
    $check($r->status() === 422 && (int) $pdo->query("SELECT COUNT(*) FROM map_nodes WHERE name='OLT-NOVA'")->fetchColumn() === 0, 'criar no: PON invalida nao cria o no');

    // --- Cadastro de OLT existente: nasce DESATIVADA ---
    $r = $post('save', $base);
    $j = $json($r);
    $check($r->status() === 200 && $j['ok'] === true && str_contains($j['message'], 'DESATIVADA'), 'cadastra OLT existente');
    $dev = $pdo->query('SELECT * FROM net_devices')->fetch(PDO::FETCH_ASSOC);
    $check((int) $dev['enabled'] === 0 && (int) $dev['status_interval_s'] === 600 && (int) $dev['power_interval_s'] === 1800 && $dev['mgmt_host'] === '192.168.0.10' && $dev['credential_ref'] === 'NET_CRED_TESTE_A' && $dev['snmp_version'] === '2c' && $dev['expected_identity_json'] === null, 'coleta nasce desativada, intervalos padrao 600/1800, sem identidade');
    $deviceId = (int) $dev['id'];
    $check($audit('net.device.create') === 1, 'auditoria net.device.create');
    $ctx = (string) $pdo->query("SELECT context_json FROM audit_logs WHERE action='net.device.create'")->fetchColumn();
    $check($noSecret(new Response($ctx)) && str_contains($ctx, 'NET_CRED_TESTE_A'), 'auditoria guarda so o NOME da credencial');
    $r = $post('save', ['node_id' => '42', 'mgmt_host' => '192.168.0.11'] + $base);
    $check($r->status() === 422, 'a OLT ja registrada nao cria segundo registro');
    $r = $post('save', ['node_id' => '43', 'mgmt_host' => '192.168.0.10', 'perfil' => $profileId, 'credential_ref' => 'NET_CRED_TESTE_A']);
    $check($r->status() === 422, 'IP ja usado por outro equipamento (ou OLT sem PON)');
    $row = $json($ctl->list(new Request('GET', '/x')))['data']['equipamentos'][0];
    $check($row['device_id'] === $deviceId && $row['coleta_ativa'] === false && $row['saude']['estado'] === 'desativada' && $row['ultima_leitura_ok'] === null && $row['identidade_aprovada'] === false && $row['perfil']['code'] === 'hioso-epon-25355-4-4-v3', 'lista apos cadastro: desativada, sem leitura, identidade a aprovar');

    // --- Criar OLT nova (no do mapa pelo mesmo repositorio) ---
    $r = $post('save', ['node_mode' => 'new', 'name' => 'OLT-NOVA', 'site_id' => '8', 'ports_pon' => '4', 'fabricante' => 'Hioso', 'modelo' => 'HA7304', 'mgmt_host' => '192.168.0.20', 'perfil' => $profileId, 'credential_ref' => 'NET_CRED_TESTE_B', 'intervalo_status_s' => '900', 'intervalo_potencia_s' => '3600']);
    $check($r->status() === 200, 'cria OLT nova junto com a coleta: ' . $r->body());
    $newNode = $pdo->query("SELECT * FROM map_nodes WHERE name='OLT-NOVA'")->fetch(PDO::FETCH_ASSOC);
    $check($newNode['node_type'] === 'olt' && (int) $newNode['parent_node_id'] === 8 && $newNode['lat'] === null && json_decode($newNode['attrs_json'], true) === ['fabricante' => 'Hioso', 'modelo' => 'HA7304'], 'no criado como OLT dentro do site, atributos fabricante/modelo');
    $pons = $pdo->query("SELECT port_num FROM map_ports WHERE node_id={$newNode['id']} AND port_role='pon' ORDER BY port_num")->fetchAll(PDO::FETCH_COLUMN);
    $check($pons === ['1', '2', '3', '4'] || $pons === [1, 2, 3, 4], 'PONs 1..4 criadas pelas regras do mapa');
    $check($audit('map.node.create') === 1 && $audit('map.port.sync') === 1, 'auditoria do mapa (map.node.create, map.port.sync) vem das regras do proprio mapa');
    $dev2 = $pdo->query("SELECT * FROM net_devices WHERE map_node_id={$newNode['id']}")->fetch(PDO::FETCH_ASSOC);
    $check((int) $dev2['enabled'] === 0 && (int) $dev2['status_interval_s'] === 900 && (int) $dev2['power_interval_s'] === 3600, 'coleta da OLT nova desativada, intervalos informados');
    // O mapa ve o mesmo no (mesmo repositorio): aparece na topologia.
    $topo = $mapRepo->topology($providerId);
    $check(in_array('OLT-NOVA', array_column($topo['nodes'], 'name'), true) && count(array_filter($topo['ports'], static fn (array $p): bool => (int) $p['node_id'] === (int) $newNode['id'])) === 4, 'o no criado aqui aparece na topologia do mapa');

    // --- Perfil ainda nao suportado: so o no, coleta desativada ---
    $r = $post('save', ['node_mode' => 'new', 'name' => 'OLT-VSOL', 'site_id' => '8', 'ports_pon' => '8', 'fabricante' => 'V-SOL', 'perfil' => 'vsol']);
    $j = $json($r);
    $check($r->status() === 200 && str_contains($j['message'], 'Perfil ainda não suportado: coleta desativada') && $j['data']['device_id'] === null, 'perfil planejado: sem registro de coleta');
    $vsolId = (int) $pdo->query("SELECT id FROM map_nodes WHERE name='OLT-VSOL'")->fetchColumn();
    $check((int) $pdo->query("SELECT COUNT(*) FROM net_devices WHERE map_node_id=$vsolId")->fetchColumn() === 0, 'nao cria net_devices para perfil nao suportado');
    $vrow = array_values(array_filter($json($ctl->list(new Request('GET', '/x')))['data']['equipamentos'], static fn (array $e): bool => $e['nome'] === 'OLT-VSOL'))[0];
    $check($vrow['perfil_pretendido'] === 'vsol' && $vrow['saude']['texto'] === 'Perfil ainda não suportado: coleta desativada' && $vrow['coleta_ativa'] === false, 'lista mostra "perfil ainda nao suportado: coleta desativada"');
    $r = $post('save', ['node_id' => '42', 'device_id' => (string) $deviceId, 'perfil' => 'vsol']);
    $check($r->status() === 422, 'equipamento que ja coleta nao troca para perfil nao suportado');

    // --- Ativacao exige confirmacao ---
    $r = $post('toggle', ['device_id' => (string) $deviceId, 'ativar' => '1']);
    $check($r->status() === 422 && (int) $pdo->query("SELECT enabled FROM net_devices WHERE id=$deviceId")->fetchColumn() === 0, 'ativar sem confirmacao e recusado');
    $r = $post('toggle', ['device_id' => '9999', 'ativar' => '1', 'confirmar' => '1']);
    $check($r->status() === 404, 'ativar equipamento inexistente = 404');
    $pdo->exec("UPDATE net_devices SET credential_ref='NET_CRED_FALTANDO' WHERE id=$deviceId");
    $r = $post('toggle', ['device_id' => (string) $deviceId, 'ativar' => '1', 'confirmar' => '1']);
    $check($r->status() === 422 && (int) $pdo->query("SELECT enabled FROM net_devices WHERE id=$deviceId")->fetchColumn() === 0, 'nao ativa se a credencial nao esta no .env');
    $pdo->exec("UPDATE net_devices SET credential_ref='NET_CRED_TESTE_A' WHERE id=$deviceId");
    $r = $post('toggle', ['device_id' => (string) $deviceId, 'ativar' => '1', 'confirmar' => '1']);
    $check($r->status() === 200 && (int) $pdo->query("SELECT enabled FROM net_devices WHERE id=$deviceId")->fetchColumn() === 1 && $audit('net.device.enable') === 1, 'ativa com confirmacao e audita');
    $check(str_contains($json($r)['message'], '--approve-identity'), 'mensagem de ativacao orienta a aprovar a identidade');
    // Perfil nao suportado nao ativa
    $pdo->exec("UPDATE net_device_profiles SET status='draft' WHERE version=3");
    $dev2Id = (int) $dev2['id'];
    $r = $post('toggle', ['device_id' => (string) $dev2Id, 'ativar' => '1', 'confirmar' => '1']);
    $check($r->status() === 422 && (int) $pdo->query("SELECT enabled FROM net_devices WHERE id=$dev2Id")->fetchColumn() === 0, 'perfil nao ativo: coleta nao pode ser ativada');
    $pdo->exec("UPDATE net_device_profiles SET status='active' WHERE version=3");

    // --- Edicao: mudar IP com coleta ativa desativa ---
    $r = $post('save', ['device_id' => (string) $deviceId, 'mgmt_host' => '192.168.0.12', 'intervalo_status_s' => '700'] + $base);
    $j = $json($r);
    $check($r->status() === 200 && str_contains($j['message'], 'DESATIVADA') && (int) $pdo->query("SELECT enabled FROM net_devices WHERE id=$deviceId")->fetchColumn() === 0 && (int) $pdo->query("SELECT status_interval_s FROM net_devices WHERE id=$deviceId")->fetchColumn() === 700, 'mudar IP com coleta ativa desativa a coleta e avisa');
    $r = $post('save', ['device_id' => (string) $deviceId, 'mgmt_host' => '192.168.0.12', 'fabricante' => 'Hioso', 'modelo' => 'X1'] + $base);
    $check($r->status() === 200 && json_decode((string) $pdo->query('SELECT attrs_json FROM map_nodes WHERE id=42')->fetchColumn(), true) === ['fabricante' => 'Hioso', 'modelo' => 'X1'], 'editar fabricante/modelo atualiza o no do mapa');
    $check($audit('net.device.update') === 2 && $audit('map.node.update') >= 1, 'auditoria net.device.update e map.node.update');
    $r = $post('save', ['device_id' => '9999'] + $base);
    $check($r->status() === 404, 'editar equipamento inexistente = 404');

    // --- Testar conexao ---
    $mode = 'ok';
    $r = $post('test', ['device_id' => '9999']);
    $check($r->status() === 404, 'testar equipamento inexistente = 404');
    $before = count($transports);
    $r = $post('test', ['device_id' => (string) $deviceId]);
    $j = $json($r);
    $check($r->status() === 200 && $j['ok'] === true && $j['data']['leituras']['sysName'] === 'OLT-FAKE' && $j['data']['leituras']['sysDescr'] === 'Fake OLT v1' && $j['data']['leituras']['sysUpTime'] === '1d 10h 17min', 'teste devolve sysName, sysDescr (sem quebras de linha) e sysUpTime formatado');
    $check($noSecret($r), 'resposta do teste nunca traz a credencial');
    [$thost, $tfake] = $transports[$before];
    $check($thost === '192.168.0.12' && $tfake->requests === 3 && $tfake->operations === ['get', 'get', 'get'], 'exatamente 3 GET (nenhum getnext, nenhum set)');
    $check($audit('net.device.test') === 1, 'auditoria do teste (quem/quando/resultado)');
    $actx = json_decode((string) $pdo->query("SELECT context_json FROM audit_logs WHERE action='net.device.test'")->fetchColumn(), true);
    $check($actx['resultado'] === 'ok' && $actx['ok'] === true && !str_contains(json_encode($actx), 'segredo'), 'auditoria do teste: resultado sem segredo');
    $actor = $pdo->query("SELECT actor_login FROM audit_logs WHERE action='net.device.test'")->fetchColumn();
    $check($actor === 'zz_gestor', 'auditoria registra o ator');
    $r = $post('test', ['device_id' => (string) $deviceId]);
    $j = $json($r);
    $check($r->status() === 429 && $j['data']['aguarde_s'] > 0 && $j['data']['aguarde_s'] <= 30 && $r->header('Retry-After') !== null && count($transports) === $before + 1, 'segundo teste em menos de 30 s = 429, sem novo SNMP');
    $check($audit('net.device.test') === 1, 'o teste bloqueado nao e auditado como execucao');
    // Outro equipamento nao e afetado pelo limite
    $r = $post('test', ['device_id' => (string) $dev2Id]);
    $check($r->status() === 200, 'limite e por equipamento');
    // Passados 31 s libera; timeout devolve mensagem sem expor nada.
    $pdo->exec("UPDATE audit_logs SET created_at = created_at - INTERVAL 31 SECOND WHERE action='net.device.test'");
    $mode = 'timeout';
    $r = $post('test', ['device_id' => (string) $deviceId]);
    $j = $json($r);
    $check($r->status() === 200 && $j['ok'] === false && $j['data']['codigo'] === 'sem_resposta' && $noSecret($r), 'apos 30 s libera; timeout vira mensagem amigavel');
    $last = $transports[count($transports) - 1][1];
    $check($last->requests === 1, 'sem retry: apos o 1o timeout para (1 requisicao)');
    // Coleta em andamento (lock) nao consome o limite nem toca a OLT.
    $pdo->exec("UPDATE audit_logs SET created_at = created_at - INTERVAL 31 SECOND WHERE action='net.device.test'");
    $mode = 'ok';
    $other = new NetDeviceLock($lockDir);
    $check($other->acquire($deviceId), 'lock de coleta adquirido pelo teste');
    $count = count($transports);
    $r = $post('test', ['device_id' => (string) $deviceId]);
    $j = $json($r);
    $check($j['ok'] === false && $j['data']['codigo'] === 'coleta_em_andamento' && count($transports) === $count, 'coleta em andamento: nao consulta a OLT');
    $other->release();
    $audits = $audit('net.device.test');
    $r = $post('test', ['device_id' => (string) $deviceId]);
    $check($r->status() === 200 && $json($r)['ok'] === true && $audit('net.device.test') === $audits + 1, 'liberado o lock, o teste roda (o bloqueado por lock nao consumiu o limite)');
    // Credencial ausente / IP invalido gravado: mensagem sem tocar a rede
    $pdo->exec("UPDATE audit_logs SET created_at = created_at - INTERVAL 31 SECOND WHERE action='net.device.test'");
    $pdo->exec("UPDATE net_devices SET credential_ref='NET_CRED_FALTANDO' WHERE id=$deviceId");
    $count = count($transports);
    $j = $json($post('test', ['device_id' => (string) $deviceId]));
    $check($j['ok'] === false && $j['data']['codigo'] === 'credencial_ausente' && count($transports) === $count, 'credencial ausente no .env: nao consulta a OLT');

    // --- Isolamento por provedor ---
    $pdo->exec("INSERT INTO net_devices (provider_id,map_node_id,profile_id,mgmt_host,credential_ref,enabled,created_at,updated_at) SELECT $otherProvider,60,id,'10.9.9.9','NET_CRED_TESTE_A',0,NOW(),NOW() FROM net_device_profiles WHERE version=3");
    $foreign = (int) $pdo->lastInsertId();
    $check($post('toggle', ['device_id' => (string) $foreign, 'ativar' => '1', 'confirmar' => '1'])->status() === 404 && $post('test', ['device_id' => (string) $foreign])->status() === 404 && $post('save', ['device_id' => (string) $foreign] + $base)->status() === 404, 'equipamento de outro provedor: 404 em ativar/testar/editar');
    $check(!str_contains($ctl->list(new Request('GET', '/x'))->body(), '10.9.9.9'), 'lista nao vaza equipamento de outro provedor');

    // --- Validador de host ---
    foreach (['192.168.0.89' => true, '10.0.0.1' => true, '8.8.8.8' => true, '127.0.0.1' => false, '0.0.0.0' => false, '169.254.1.1' => false, '224.0.0.1' => false, '255.255.255.255' => false, '::1' => false, 'abc' => false, '192.168.0.1:161' => false, '192.168.0' => false] as $h => $expect) {
        $check(NetDeviceController::validHost((string) $h) === $expect, "validHost({$h}) = " . ($expect ? 'sim' : 'nao'));
    }
    echo "NetDeviceCadastroSmoke: {$n} verificacoes, zero falhas\n";
} finally {
    $schema->drop();
    array_map('unlink', glob($lockDir . '/*') ?: []);
    @rmdir($lockDir); @unlink($tmp . '/.env'); @rmdir($tmp);
}
