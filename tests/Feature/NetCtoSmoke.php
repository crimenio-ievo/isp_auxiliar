<?php
declare(strict_types=1);
// Fase 3: /rede-ctos (rotas, permissao, CSRF, validacao, criar/editar/excluir pelo repositorio do mapa, origem, importacao CSV). Schema descartavel.
$root = dirname(__DIR__, 2);
require $root . '/backend/bootstrap/autoload.php';
require $root . '/tests/support/ScratchSchema.php';

use App\Controllers\MapController;
use App\Controllers\NetCtoController;
use App\Core\Application;
use App\Core\Config;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\Map\MapRepository;
use App\Infrastructure\Net\Coordinates;
use App\Infrastructure\Net\CtoCsvImporter;
use App\Services\Net\MapNodeGateway;
use Tests\Support\ScratchSchema;

$n = 0;
$check = static function (bool $ok, string $m) use (&$n): void { $n++; if (!$ok) { throw new RuntimeException("FALHA: {$m}"); } };

// --- Coordinates (puro) ---
foreach ([
    ['-20.8455534, -42.80236', [-20.8455534, -42.80236]], ['-20,8455 -42,8023', [-20.8455, -42.8023]], ['-20.8455,-42.8023', [-20.8455, -42.8023]],
    ['  -20.8455 , -42.8023  ', [-20.8455, -42.8023]], ['20 -40', [20.0, -40.0]], ['91, 10', null], ['10, 181', null], ['0, 0', null], ['abc', null], ['', null], ['-20,84, -42,80', null], ['1,2,3', null], ["-20.1\n-42.1", [-20.1, -42.1]],
] as [$in, $out]) {
    $check(Coordinates::parsePair($in) === $out, 'parsePair(' . json_encode($in) . ')');
}
$check(abs(Coordinates::meters(-20.85, -42.80, -20.85, -42.8001) - 10.4) < 0.3, 'distancia haversine ~10,4 m para 0,0001 grau de longitude');

// --- CSV (puro) ---
$existing = [['nome' => 'CTO-001', 'lat' => -20.86, 'lng' => -42.81]];
$sample = (string) file_get_contents($root . '/tests/fixtures/ctos_exemplo.csv');
$a = CtoCsvImporter::analyze($sample, $existing);
$st = array_column($a['linhas'], 'status', 'nome');
$check($a['ok'] && $a['resumo'] === ['total' => 9, 'ok' => 3, 'erro' => 3, 'duplicado' => 2, 'proximo' => 1], 'arquivo de exemplo: 3 prontas, 1 perto, 2 duplicadas, 3 com erro: ' . json_encode($a['resumo']));
$check($st['CTO-EX-01'] === 'ok' && $st['CTO-EX-02'] === 'ok' && $st['CTO-EX-03'] === 'ok' && $st['CTO-001'] === 'duplicado' && $st['CTO-EX-05'] === 'proximo' && $st['CTO-EX-06'] === 'erro' && $st['CTO-EX-07'] === 'erro' && $st['cto-ex-02'] === 'duplicado', 'status linha a linha');
$byName = array_column($a['linhas'], null, 'nome');
$check($byName['CTO-EX-02']['lat'] === -20.85025 && $byName['CTO-EX-02']['lng'] === -42.8003 && $byName['CTO-EX-03']['capacidade'] === 16 && $byName['CTO-EX-03']['mensagens'] !== [], 'virgula decimal entre aspas e capacidade em branco = 16 (avisado)');
$check($a['linhas'][0]['linha'] === 2 && $a['linhas'][7]['linha'] === 9, 'numero da linha conta o cabecalho');
$near = CtoCsvImporter::analyze("nome,lat,lng\nA,-20.85,-42.8\nB,-20.85,-42.80005\nC,-20.86,-42.8101\n", $existing);
$check(array_column($near['linhas'], 'status') === ['ok', 'proximo', 'proximo'], 'proximidade (< 15 m) entre linhas do arquivo e com CTO existente: ' . json_encode(array_column($near['linhas'], 'status')));
$semi = CtoCsvImporter::analyze("\xEF\xBB\xBFnome;lat;lng;capacidade\nX;-20,85;-42,8;12\n", []);
$check($semi['ok'] && $semi['linhas'][0]['lat'] === -20.85 && $semi['linhas'][0]['capacidade'] === 12 && $semi['linhas'][0]['status'] === 'ok', 'BOM, ponto e virgula e virgula decimal');
$win = CtoCsvImporter::analyze(mb_convert_encoding("nome,lat,lng\nCTO Praça Açaí,-20.85,-42.8\n", 'Windows-1252', 'UTF-8'), []);
$check($win['linhas'][0]['nome'] === 'CTO Praça Açaí', 'arquivo Windows-1252 convertido');
$nohead = CtoCsvImporter::analyze("Y,-20.85,-42.8,8\n", []);
$check($nohead['ok'] && $nohead['linhas'][0]['nome'] === 'Y' && $nohead['linhas'][0]['linha'] === 1, 'sem cabecalho: ordem nome,lat,lng,capacidade');
$check(!CtoCsvImporter::analyze('', [])['ok'] && !CtoCsvImporter::analyze("nome,capacidade\nZ,1\n", [])['ok'], 'vazio e cabecalho sem lat/lng recusados');
$check(!CtoCsvImporter::analyze(str_repeat('a', CtoCsvImporter::MAX_BYTES + 1), [])['ok'], 'arquivo grande demais');
$rows = "nome,lat,lng\n" . implode("\n", array_map(static fn (int $i): string => "C{$i}," . (-20 - $i / 1000) . ',-42.8', range(1, 501)));
$check(!CtoCsvImporter::analyze($rows, [])['ok'], 'mais de 500 linhas recusado');
$formula = CtoCsvImporter::analyze("nome,lat,lng\n=CMD|calc,-20.85,-42.8\n", []);
$check($formula['linhas'][0]['status'] === 'ok' && $formula['linhas'][0]['nome'] === '=CMD|calc', 'texto de formula entra como texto puro (nunca interpretado)');

// --- Rotas e anonimo ---
$router = new Router();
(require $root . '/backend/routes.php')($router);
foreach ([['GET', '/rede-ctos'], ['GET', '/api/rede-ctos'], ['POST', '/api/rede-ctos'], ['POST', '/api/rede-ctos/excluir'], ['POST', '/api/rede-ctos/importar']] as [$m, $p]) {
    $check($router->match($m, $p) !== null, "rota {$m} {$p}");
}
$check($router->match('GET', '/api/rede-ctos/excluir') === null && $router->match('GET', '/api/rede-ctos/importar') === null, 'excluir e importar so por POST');
$_SESSION = [];
$app = new Application(new Config([]), new Container(), $router);
foreach ([['GET', '/rede-ctos'], ['GET', '/api/rede-ctos'], ['POST', '/api/rede-ctos/importar']] as [$m, $p]) {
    $resp = $app->handle(new Request($m, $p));
    $check($resp->status() === 302 && $resp->header('Location') === '/login', "anonimo em {$m} {$p} vai para o login");
}

$schema = (new ScratchSchema($root, 'netcto'))->create();
try {
    $pdo = $schema->pdo;
    $local = new LocalRepository($schema->database, 'default');
    $providerId = (int) $local->currentProviderId();
    $pdo->exec("INSERT INTO providers (name,slug,status,created_at,updated_at) VALUES ('OUTRO','outro','active',NOW(),NOW())");
    $other = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO map_nodes (id,provider_id,node_type,name,lat,lng,created_at,updated_at) VALUES (8,$providerId,'pop','POP-T',-20.1,-42.1,NOW(),NOW()),(70,$other,'cto','CTO-DE-OUTRO',-20.5,-42.5,NOW(),NOW())");
    $pdo->exec("INSERT INTO map_nodes (id,provider_id,node_type,name,parent_node_id,created_at,updated_at) VALUES (42,$providerId,'olt','OLT-T',8,NOW(),NOW())");
    for ($i = 1; $i <= 2; $i++) { $pdo->exec("INSERT INTO map_ports (provider_id,node_id,port_num,label,port_role,media,is_external,created_at,updated_at) VALUES ($providerId,42,$i,'PON $i','pon','pon',1,NOW(),NOW())"); }
    $mapRepo = new MapRepository($schema->database);
    $gateway = new MapNodeGateway(new MapController($local, $mapRepo));
    $ctl = new NetCtoController(new View($root . '/backend/Views'), new Config(['app' => ['name' => 'T']]), $local, $schema->database, $mapRepo, $gateway);

    $asManager = static function (): void { $_SESSION = ['user' => ['login' => 'zz_gestor', 'name' => 'ZZ', 'role' => 'manager', 'id' => 0]]; };
    $asTech = static function (): void { $_SESSION = ['user' => ['login' => 'zz_tec', 'name' => 'ZZ', 'role' => 'technician', 'id' => 0]]; };
    $asNobody = static function (): void { $_SESSION = ['user' => ['login' => 'zz_x', 'name' => 'ZZ', 'role' => 'visitante', 'id' => 0]]; };
    $json = static fn (Response $r): array => (array) json_decode($r->body(), true);
    $post = static function (string $method, array $f, bool $csrf = true) use ($ctl): Response {
        if ($csrf) { $f['_csrf'] = Csrf::token('rede'); }
        return $ctl->{$method}(new Request('POST', '/x', '', [], $f));
    };
    $get = static fn (string $m, array $q = []) => $ctl->{$m}(new Request('GET', '/x', '', $q));
    $audit = static fn (string $a): int => (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='$a'")->fetchColumn();
    $count = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();

    // --- Permissao ---
    $asNobody();
    $check($get('list')->status() === 403 && $get('page')->status() === 302, 'sem perfil: lista 403 e pagina redireciona');
    $asTech();
    $r = $get('list');
    $check($r->status() === 200 && $json($r)['data']['pode_editar'] === false, 'tecnico le a lista (somente leitura)');
    $page = $get('page');
    $check($page->status() === 200 && str_contains($page->body(), 'data-can-edit="0"') && !str_contains($page->body(), 'id="ct-form"') && !str_contains($page->body(), 'ct-import-open') && !str_contains($page->body(), 'Rede de fibra</a>'), 'pagina do tecnico sem formulario, sem importar e sem as abas de gestor');
    foreach (['save' => ['nome' => 'X'], 'delete' => ['id' => '1'], 'import' => ['csv' => 'a']] as $m => $f) {
        $check($post($m, $f)->status() === 403, "tecnico nao grava ({$m} = 403)");
    }
    $asManager();
    $page = $get('page');
    $check($page->status() === 200 && str_contains($page->body(), 'data-can-edit="1"') && str_contains($page->body(), 'id="ct-form"') && str_contains($page->body(), 'data-csrf="' . Csrf::token('rede') . '"') && str_contains($page->body(), 'rede-ctos.js'), 'gestor ve formulario, importacao e token CSRF');
    foreach (['save', 'delete', 'import'] as $m) {
        $check($post($m, ['nome' => 'X', 'id' => '1', 'csv' => 'a'], false)->status() === 419, "{$m} sem CSRF = 419");
    }
    $check($ctl->save(new Request('POST', '/x', '', [], ['_csrf' => Csrf::token('map')]))->status() === 419, 'token do escopo map nao vale aqui');

    // --- Validacao ---
    $ok = ['nome' => 'CTO-A', 'coordenadas' => '-20.8455534, -42.8023600', 'capacidade' => '16'];
    foreach ([
        ['nome vazio', ['nome' => '']], ['nome longo', ['nome' => str_repeat('x', 121)]], ['coordenada fora da faixa', ['coordenadas' => '95, 10']], ['coordenada 0,0', ['coordenadas' => '0, 0']],
        ['coordenada texto', ['coordenadas' => 'perto da praça']], ['sem coordenada', ['coordenadas' => '']], ['capacidade zero', ['capacidade' => '0']], ['capacidade 145', ['capacidade' => '145']],
        ['capacidade texto', ['capacidade' => 'x']], ['observacao longa', ['observacao' => str_repeat('y', 2001)]], ['origem invalida', ['origem' => 'qualquer']], ['site inexistente', ['origem' => 'site:999']], ['PON inexistente', ['origem' => 'pon:999']],
    ] as [$label, $over]) {
        $r = $post('save', $over + $ok);
        $check($r->status() === 422 && $json($r)['ok'] === false, "validacao: {$label} -> 422");
    }
    $check($count("SELECT COUNT(*) FROM map_nodes WHERE node_type='cto' AND provider_id=$providerId") === 0, 'nada gravado pelas validacoes');

    // --- Criar (pelas regras do mapa) ---
    $r = $post('save', $ok + ['observacao' => 'poste 12']);
    $j = $json($r);
    $check($r->status() === 200 && $j['ok'] === true && $j['message'] === 'CTO criada.', 'cria CTO: ' . $r->body());
    $node = $pdo->query("SELECT * FROM map_nodes WHERE name='CTO-A'")->fetch(PDO::FETCH_ASSOC);
    $check($node['node_type'] === 'cto' && (float) $node['lat'] === -20.8455534 && (float) $node['lng'] === -42.80236 && json_decode($node['attrs_json'], true) === ['capacity' => 16] && $node['notes'] === 'poste 12' && $node['parent_node_id'] === null && $node['lifecycle'] === 'ativo', 'no do mapa: tipo, coordenadas coladas, capacidade em attrs, observacao');
    $ports = $pdo->query("SELECT port_num,label,port_role,media,is_external FROM map_ports WHERE node_id={$node['id']} ORDER BY port_num")->fetchAll(PDO::FETCH_ASSOC);
    $check(count($ports) === 16 && $ports[0]['label'] === 'Porta 1' && $ports[15]['label'] === 'Porta 16' && $ports[0]['port_role'] === 'fo' && (int) $ports[0]['is_external'] === 1, '16 portas "Porta n" (fo, externas) como o mapa faz');
    $check($audit('net.cto.create') === 1 && $audit('map.node.create') === 1 && $audit('map.port.sync') === 1, 'auditoria net.cto.create + map.node.create + map.port.sync');
    $ctoA = (int) $node['id'];
    $topo = $mapRepo->topology($providerId);
    $check(in_array('CTO-A', array_column($topo['nodes'], 'name'), true), 'a CTO criada aqui esta na topologia do mapa');
    // Virgula decimal + espaco
    $r = $post('save', ['nome' => 'CTO-B', 'coordenadas' => '-20,8500000 -42,8100000', 'capacidade' => '8']);
    $check($r->status() === 200 && (float) $pdo->query("SELECT lat FROM map_nodes WHERE name='CTO-B'")->fetchColumn() === -20.85 && $count("SELECT COUNT(*) FROM map_ports p JOIN map_nodes n ON n.id=p.node_id WHERE n.name='CTO-B'") === 8, 'virgula decimal colada do Google Maps; 8 portas');
    // Nome duplicado (sem diferenciar maiusculas) e de outro provedor nao conta
    $check($post('save', ['nome' => 'cto-a'] + $ok)->status() === 422, 'nome duplicado (maiusculas/minusculas) = 422');
    $check($post('save', ['nome' => 'CTO-DE-OUTRO', 'coordenadas' => '-20.7, -42.7', 'capacidade' => '4'])->status() === 200, 'mesmo nome em outro provedor nao conflita');
    // Proximidade (< 15 m)
    $r = $post('save', ['nome' => 'CTO-PERTO', 'coordenadas' => '-20.8455534, -42.8023700', 'capacidade' => '4']);
    $j = $json($r);
    $check($r->status() === 409 && $j['codigo'] === 'proximo' && $j['proximas'][0]['nome'] === 'CTO-A' && $j['proximas'][0]['metros'] < 15 && $count("SELECT COUNT(*) FROM map_nodes WHERE name='CTO-PERTO'") === 0, 'CTO a menos de 15 m: 409 pedindo confirmacao, nada gravado');
    $r = $post('save', ['nome' => 'CTO-PERTO', 'coordenadas' => '-20.8455534, -42.8023700', 'capacidade' => '4', 'confirmar_proximo' => '1']);
    $check($r->status() === 200 && $count("SELECT COUNT(*) FROM map_nodes WHERE name='CTO-PERTO'") === 1, 'confirmada, grava');
    $check($count("SELECT COUNT(*) FROM map_nodes WHERE name='CTO-DE-OUTRO' AND provider_id=$providerId") === 1, 'CTO do mesmo nome criada so no provedor atual');

    // --- Origem: site e PON ---
    $r = $post('save', ['nome' => 'CTO-SITE', 'coordenadas' => '-20.80, -42.70', 'capacidade' => '4', 'origem' => 'site:8']);
    $ctoSite = (int) $pdo->query("SELECT id FROM map_nodes WHERE name='CTO-SITE'")->fetchColumn();
    $link = $pdo->query("SELECT * FROM map_links WHERE from_node_id=$ctoSite")->fetch(PDO::FETCH_ASSOC);
    $check($r->status() === 200 && $json($r)['data']['aviso'] === null && $link['link_type'] === 'distribuicao' && (int) $link['to_node_id'] === 8 && $link['medium'] === 'fibra', 'origem site cria ligacao de distribuicao CTO -> POP');
    $pon1 = (int) $pdo->query("SELECT id FROM map_ports WHERE node_id=42 AND port_num=1")->fetchColumn();
    $r = $post('save', ['nome' => 'CTO-PON', 'coordenadas' => '-20.79, -42.69', 'capacidade' => '8', 'origem' => "pon:$pon1"]);
    $ctoPon = (int) $pdo->query("SELECT id FROM map_nodes WHERE name='CTO-PON'")->fetchColumn();
    $link = $pdo->query("SELECT * FROM map_links WHERE to_node_id=$ctoPon")->fetch(PDO::FETCH_ASSOC);
    $check($r->status() === 200 && $link['link_type'] === 'backbone' && (int) $link['from_node_id'] === 42 && (int) $link['from_port_id'] === $pon1 && $link['to_port_id'] !== null, 'origem PON cria backbone OLT/PON -> primeira porta livre da CTO');
    $check($post('save', ['nome' => 'CTO-PON2', 'coordenadas' => '-20.78, -42.68', 'capacidade' => '8', 'origem' => "pon:$pon1"])->status() === 422, 'PON ja ligada nao pode ser origem de outra CTO');
    $row = array_values(array_filter($json($get('list'))['data']['ctos'], static fn (array $c): bool => $c['nome'] === 'CTO-PON'))[0];
    $check($row['origem'] === 'OLT-T · PON 1' && $row['portas'] === ['total' => 8, 'ocupadas' => 1, 'livres' => 7, 'indisponiveis' => 0, 'em_ligacao' => 1, 'com_cliente' => 0] && $row['capacidade'] === 8 && $row['clientes'] === 0, 'lista: origem OLT · PON, portas 1 ocupada/7 livres, 0 clientes (tabela de vinculos existe)');
    $rowSite = array_values(array_filter($json($get('list'))['data']['ctos'], static fn (array $c): bool => $c['nome'] === 'CTO-SITE'))[0];
    $check($rowSite['origem'] === 'POP-T', 'origem pelo site');
    $d = $json($get('list'))['data'];
    $check(!in_array('CTO-DE-OUTRO', array_column(array_filter($d['ctos'], static fn (array $c): bool => $c['id'] === 70), 'nome'), true) && count(array_filter($d['ctos'], static fn (array $c): bool => $c['id'] === 70)) === 0, 'lista nao traz CTO de outro provedor');
    $check($d['origens']['pons'][0]['livre'] === false && $d['origens']['pons'][1]['livre'] === true && $d['origens']['sites'][0]['nome'] === 'POP-T' && $d['vinculos_disponiveis'] === true, 'opcoes de origem (PON 1 usada, PON 2 livre) e vinculos disponiveis');
    $check($post('save', ['id' => (string) $ctoPon, 'origem' => 'site:8'] + $ok + ['nome' => 'CTO-PON'])->status() === 422, 'origem so em CTO sem ligacoes');

    // --- Editar ---
    $r = $post('save', ['id' => (string) $ctoA, 'nome' => 'CTO-A2', 'coordenadas' => '-20.8700, -42.8300', 'capacidade' => '24', 'observacao' => '']);
    $check($r->status() === 200 && $json($r)['message'] === 'CTO atualizada.', 'edita: ' . $r->body());
    $after = $pdo->query("SELECT * FROM map_nodes WHERE id=$ctoA")->fetch(PDO::FETCH_ASSOC);
    $check($after['name'] === 'CTO-A2' && (float) $after['lat'] === -20.87 && $after['notes'] === null && json_decode($after['attrs_json'], true) === ['capacity' => 24] && $count("SELECT COUNT(*) FROM map_ports WHERE node_id=$ctoA") === 24, 'editar muda nome/coordenadas/observacao e acrescenta portas (16 -> 24)');
    $check($audit('net.cto.update') === 1 && $audit('map.node.update') >= 1, 'auditoria da edicao');
    $check($post('save', ['id' => (string) $ctoA, 'nome' => 'CTO-B', 'coordenadas' => '-20.8700, -42.8300', 'capacidade' => '24'])->status() === 422, 'editar para nome ja usado = 422');
    $r = $post('save', ['id' => (string) $ctoA, 'nome' => 'CTO-A2', 'coordenadas' => '-20.8700, -42.8300']);
    $check($r->status() === 200 && $count("SELECT COUNT(*) FROM map_ports WHERE node_id=$ctoA") === 24, 'capacidade em branco na edicao mantem a atual');
    // reduzir com porta ocupada: usa a ligacao da CTO-PON (porta 1 em uso)
    $r = $post('save', ['id' => (string) $ctoPon, 'nome' => 'CTO-PON', 'coordenadas' => '-20.79, -42.69', 'capacidade' => '1']);
    $check($r->status() === 200 && $count("SELECT COUNT(*) FROM map_ports WHERE node_id=$ctoPon") === 1, 'reduzir para 1 porta (a de baixo esta em uso e fica)');
    $pdo->exec("UPDATE map_links SET to_port_id = (SELECT id FROM map_ports WHERE node_id=$ctoPon AND port_num=1) WHERE to_node_id=$ctoPon");
    $r = $post('save', ['id' => (string) $ctoA, 'nome' => 'CTO-A2', 'coordenadas' => '-20.8700, -42.8300', 'capacidade' => '8']);
    $check($r->status() === 200, 'reduzir portas livres de A funciona');
    $check($post('save', ['id' => '999999'] + $ok)->status() === 404 && $post('save', ['id' => '70'] + $ok)->status() === 404 && $post('save', ['id' => '42'] + $ok)->status() === 404, 'editar CTO inexistente, de outro provedor ou que nao e CTO = 404');

    // --- Excluir ---
    $r = $post('delete', ['id' => (string) $ctoPon]);
    $j = $json($r);
    $check($r->status() === 409 && str_contains($j['message'], 'ligação') && $j['motivos'] !== [] && $count("SELECT COUNT(*) FROM map_nodes WHERE id=$ctoPon") === 1, 'excluir CTO com ligacao: 409 com o motivo');
    $pdo->exec("INSERT INTO map_nodes (id,provider_id,node_type,name,parent_node_id,attrs_json,created_at,updated_at) VALUES (80,$providerId,'splitter','SPL-T',$ctoA,'{\"razao\":\"1x2\"}',NOW(),NOW())");
    $r = $post('delete', ['id' => (string) $ctoA]);
    $check($r->status() === 409 && str_contains($json($r)['message'], 'dentro da caixa'), 'CTO com splitter dentro: 409');
    $pdo->exec('DELETE FROM map_nodes WHERE id=80');
    $r = $post('delete', ['id' => (string) $ctoA]);
    $check($r->status() === 200 && $count("SELECT COUNT(*) FROM map_nodes WHERE id=$ctoA") === 0 && $count("SELECT COUNT(*) FROM map_ports WHERE node_id=$ctoA") === 0 && $audit('net.cto.delete') === 1 && $audit('map.node.delete') === 1, 'excluir CTO livre: no e portas removidos, auditoria');
    $check($post('delete', ['id' => '70'])->status() === 404 && $post('delete', ['id' => '42'])->status() === 404 && $post('delete', ['id' => '999'])->status() === 404, 'excluir de outro provedor, nao-CTO ou inexistente = 404');

    // --- Importacao CSV ---
    $before = $count("SELECT COUNT(*) FROM map_nodes WHERE node_type='cto' AND provider_id=$providerId");
    $pdo->exec("INSERT INTO map_nodes (id,provider_id,node_type,name,lat,lng,attrs_json,created_at,updated_at) VALUES (90,$providerId,'cto','CTO-001',-20.86,-42.81,'{\"capacity\":16}',NOW(),NOW())");
    $before++;
    $r = $post('import', ['csv' => $sample]);
    $j = $json($r);
    $check($r->status() === 200 && $j['data']['previa'] === true && $j['data']['resumo'] === ['total' => 9, 'ok' => 3, 'erro' => 3, 'duplicado' => 2, 'proximo' => 1], 'previa pelo endpoint: contagens');
    $check($count("SELECT COUNT(*) FROM map_nodes WHERE node_type='cto' AND provider_id=$providerId") === $before, 'a previa NAO grava nada');
    $check($post('import', ['csv' => ''])->status() === 422, 'csv vazio = 422');
    $r = $post('import', ['csv' => $sample, 'confirmar' => '1']);
    $j = $json($r);
    $check($r->status() === 200 && count($j['data']['relatorio']['criadas']) === 3 && count($j['data']['relatorio']['ignoradas']) === 6, 'confirmar: cria as 3 prontas, relata as 6 ignoradas: ' . $r->body());
    $check($count("SELECT COUNT(*) FROM map_nodes WHERE node_type='cto' AND provider_id=$providerId") === $before + 3 && $count("SELECT COUNT(*) FROM map_ports p JOIN map_nodes n ON n.id=p.node_id WHERE n.name IN ('CTO-EX-01','CTO-EX-03') AND p.port_role='fo'") === 32 && $count("SELECT COUNT(*) FROM map_ports p JOIN map_nodes n ON n.id=p.node_id WHERE n.name='CTO-EX-02'") === 8, 'CTOs e portas por capacidade (16, 16 e 8)');
    $check($audit('net.cto.import') === 1 && $count("SELECT COUNT(*) FROM audit_logs WHERE action='map.node.create' AND JSON_UNQUOTE(JSON_EXTRACT(context_json,'$.node.name')) LIKE 'CTO-EX-%'") === 3, 'auditoria do lote + uma map.node.create por CTO');
    // Reimportar o mesmo arquivo: tudo duplicado, nada a importar (nao duplica)
    $r = $post('import', ['csv' => $sample, 'confirmar' => '1']);
    $check($r->status() === 422 && $count("SELECT COUNT(*) FROM map_nodes WHERE name LIKE 'CTO-EX-%'") === 3, 'reimportar o mesmo arquivo nao duplica (422)');
    // Forcar a linha "proximo"
    $csv2 = "nome,lat,lng,capacidade\nCTO-N1,-20.9,-42.9,4\nCTO-N2,-20.90005,-42.9,4\n";
    $a2 = $json($post('import', ['csv' => $csv2]))['data']['resumo'];
    $check($a2['ok'] === 1 && $a2['proximo'] === 1, 'previa 2: uma pronta, uma perto');
    $r = $post('import', ['csv' => $csv2, 'confirmar' => '1']);
    $check(count($json($r)['data']['relatorio']['criadas']) === 1 && $count("SELECT COUNT(*) FROM map_nodes WHERE name='CTO-N2'") === 0, 'sem marcar "mesmo assim", a linha perto fica de fora');
    $csv3 = "nome,lat,lng,capacidade\nCTO-M1,-20.95,-42.95,4\nCTO-M2,-20.95005,-42.95,4\n";
    $r = $post('import', ['csv' => $csv3, 'confirmar' => '1', 'forcar' => '3']);
    $check($r->status() === 200 && count($json($r)['data']['relatorio']['criadas']) === 2 && $count("SELECT COUNT(*) FROM map_nodes WHERE name='CTO-M2'") === 1, 'marcando a linha 3, ela entra');
    $r = $post('import', ['csv' => "nome,lat,lng,capacidade\nCTO-Q1,-20.97,-42.97,4\nCTO-Q2,-20.98,-42.98,4\n", 'confirmar' => '1', 'forcar' => '2']);
    $check($count("SELECT COUNT(*) FROM map_nodes WHERE name IN ('CTO-Q1','CTO-Q2')") === 2, 'forcar de linha que ja esta pronta e inofensivo');
    // Tudo ou nada: um gatilho de teste derruba a 2a insercao do lote.
    $pdo->exec("CREATE TRIGGER zz_falha BEFORE INSERT ON map_nodes FOR EACH ROW BEGIN IF NEW.name = 'CTO-FALHA' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'falha de teste'; END IF; END");
    $nodesBefore = $count('SELECT COUNT(*) FROM map_nodes'); $portsBefore = $count('SELECT COUNT(*) FROM map_ports'); $auditBefore = $count("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'map.%'");
    $r = $post('import', ['csv' => "nome,lat,lng,capacidade\nCTO-OK1,-21.1,-43.1,8\nCTO-OK2,-21.2,-43.2,8\nCTO-FALHA,-21.3,-43.3,8\n", 'confirmar' => '1']);
    $check($r->status() === 500 && str_contains($json($r)['message'], 'Nada foi gravado') && $count('SELECT COUNT(*) FROM map_nodes') === $nodesBefore && $count('SELECT COUNT(*) FROM map_ports') === $portsBefore && $count("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'map.%'") === $auditBefore, 'falha no meio do lote: tudo desfeito (nos, portas e auditoria do mapa)');
    $check(!$schema->database->pdo()->inTransaction(), 'sem transacao pendente apos a falha');
    $pdo->exec('DROP TRIGGER zz_falha');

    // O mapa continua gravando sozinho (transacao propria) e dentro de transacao externa ele nao a fecha.
    $appPdo = $schema->database->pdo();
    $r = (new MapController($local, $mapRepo))->createNode(new Request('POST', '/api/mapa/nos', '', [], ['_csrf' => Csrf::token('map'), 'node_type' => 'cto', 'name' => 'CTO-MAPA', 'lat' => '-20.5', 'lng' => '-42.5', 'attrs' => '{"capacity":4}']));
    $check($r->status() === 200 && !$appPdo->inTransaction() && $count("SELECT COUNT(*) FROM map_ports p JOIN map_nodes n ON n.id=p.node_id WHERE n.name='CTO-MAPA'") === 4, 'MapController::createNode continua com transacao propria');
    $appPdo->beginTransaction();
    (new MapController($local, $mapRepo))->createNode(new Request('POST', '/api/mapa/nos', '', [], ['_csrf' => Csrf::token('map'), 'node_type' => 'cto', 'name' => 'CTO-ANINHADA', 'lat' => '-20.6', 'lng' => '-42.6', 'attrs' => '{"capacity":2}']));
    $check($appPdo->inTransaction(), 'dentro de transacao externa o mapa nao a encerra');
    $appPdo->rollBack();
    $check($count("SELECT COUNT(*) FROM map_nodes WHERE name='CTO-ANINHADA'") === 0, 'rollback externo desfaz o no criado pelo mapa');

    // --- Texto malicioso e pagina com foco ---
    $r = $post('save', ['nome' => '<script>alert(1)</script>', 'coordenadas' => '-21.5, -43.5', 'capacidade' => '2']);
    $check($r->status() === 200 && !str_contains($get('page', ['cto' => '1'])->body(), '<script>alert(1)'), 'nome com HTML e gravado como texto; a pagina nao o renderiza');
    $check(str_contains($get('page', ['cto' => '90'])->body(), 'data-focus="90"') && str_contains($get('page', ['cto' => 'x'])->body(), 'data-focus=""'), 'parametro cto vira data-focus (so numeros)');
    echo "NetCtoSmoke: {$n} verificacoes, zero falhas\n";
} finally {
    $schema->drop();
}
