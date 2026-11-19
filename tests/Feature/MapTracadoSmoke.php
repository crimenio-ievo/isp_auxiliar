<?php
declare(strict_types=1);
// Traçado do cabo (map_links.path_json) e distância: pelo MapController, em schema descartável (nunca no banco real).
// Procedural, não PHPUnit: php tests/Feature/MapTracadoSmoke.php
$root = dirname(__DIR__, 2);
require $root . '/backend/bootstrap/autoload.php';
require $root . '/tests/support/ScratchSchema.php';

use App\Controllers\MapController;
use App\Core\Csrf;
use App\Core\Request;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\Map\MapPath;
use App\Infrastructure\Map\MapRepository;
use Tests\Support\ScratchSchema;

$fails = 0;
$ok = static function (bool $c, string $m) use (&$fails): void { echo ($c ? '[OK]    ' : '[FALHA] ') . $m . "\n"; if (!$c) { $fails++; } };
$near = static fn (float $a, float $b, float $tol): bool => abs($a - $b) <= $tol;

// ---- MapPath puro ----
$e = [];
$ok(MapPath::normalize('', $e) === null && MapPath::normalize(null, $e) === null && $e === [], 'path vazio/nulo = sem traçado (linha reta)');
$n = MapPath::normalize('{"pontos":[[-20.1234567,-42.7],[-20.2,-42.8]],"reservas":[{"local":" CTO-A ","m":"30.456"}],"folga_pct":"7"}', $e);
$ok($e === [] && $n['pontos'][0] === [-20.123457, -42.7] && $n['reservas'][0] === ['local' => 'CTO-A', 'm' => 30.46] && $n['folga_pct'] === 7.0, 'normaliza: 6 casas, trim, reserva 2 casas, folga numérica');
$ok(MapPath::normalize('[[-20.1,-42.1]]', $e) === ['pontos' => [[-20.1, -42.1]], 'reservas' => []], 'lista pura de pontos é aceita');
foreach ([
    'lat fora' => '{"pontos":[[91,0]]}', 'lng fora' => '{"pontos":[[0,181]]}', 'ponto não numérico' => '{"pontos":[["a",1]]}',
    'ponto com 3 valores' => '{"pontos":[[1,2,3]]}', 'reserva negativa' => '{"reservas":[{"local":"x","m":-1}]}',
    'reserva enorme' => '{"reservas":[{"local":"x","m":10001}]}', 'folga 101' => '{"pontos":[[1,1]],"folga_pct":101}',
    'folga negativa' => '{"pontos":[[1,1]],"folga_pct":-1}', 'json quebrado' => '{"pontos":', 'escalar' => '5',
] as $label => $raw) {
    $err = [];
    $r = MapPath::normalize($raw, $err);
    $ok($r === null && $err !== [], "recusa: {$label}");
}
$many = json_encode(['pontos' => array_fill(0, MapPath::MAX_POINTS + 1, [1, 1])]);
$err = [];
MapPath::normalize($many, $err);
$ok($err !== [], 'recusa mais de ' . MapPath::MAX_POINTS . ' pontos');
$many = json_encode(['reservas' => array_fill(0, MapPath::MAX_RESERVES + 1, ['local' => 'a', 'm' => 1])]);
$err = [];
MapPath::normalize($many, $err);
$ok($err !== [], 'recusa mais de ' . MapPath::MAX_RESERVES . ' reservas');

// 1 grau de latitude ~ 111195 m (R=6371 km); a fórmula é a mesma do /mapa
$ok($near(MapPath::haversine([0, 0], [1, 0]), 111194.93, 0.5), 'haversine: 1° de latitude = 111,19 km');
$A = [-20.85, -42.80];
$B = [-20.86, -42.81];
$straight = MapPath::haversine($A, $B);
$L = MapPath::lengths($A, $B, null, null, 0.0);
$ok($near($L['total_m'], $straight, 0.01) && !$L['has_path'] && !$L['manual'], 'sem traçado: total = linha reta (cabo antigo continua funcionando)');
$path = ['pontos' => [[-20.85, -42.81]], 'reservas' => [['local' => 'A', 'm' => 30.0], ['local' => 'B', 'm' => 20.0]], 'folga_pct' => 10.0];
$L = MapPath::lengths($A, $B, $path, null, 0.0);
$trace = MapPath::haversine($A, [-20.85, -42.81]) + MapPath::haversine([-20.85, -42.81], $B);
$ok($near($L['trace_m'], $trace, 0.01) && $near($L['slack_m'], $trace * 0.10, 0.01) && $L['reserves_m'] === 50.0
    && $near($L['total_m'], $trace * 1.10 + 50, 0.02) && $L['has_path'], 'total = traçado + folga% do traçado + reservas, cada parcela em separado');
$L2 = MapPath::lengths($A, $B, ['pontos' => $path['pontos'], 'reservas' => []], null, 5.0);
$ok($L2['slack_pct'] === 5.0 && $near($L2['slack_m'], $trace * 0.05, 0.01), 'sem folga própria, vale o padrão da rede');
$L3 = MapPath::lengths($A, $B, $path, 1234.5, 0.0);
$ok($L3['manual'] && $L3['total_m'] === 1234.5 && $near($L3['calculated_m'], $L['calculated_m'], 0.01), 'comprimento manual prevalece e o calculado continua disponível');
// reservas com posicao no traçado (B2): "pos" e opcional; sem pos = reserva antiga (vale no total, aparece "sem posicao")
$rp = MapPath::normalize('{"pontos":[[-20.851,-42.801]],"reservas":[{"local":"CTO-A","m":30,"pos":[-20.8512345678,-42.8011]},{"local":"poste 3","m":20}]}', $e);
$ok(($rp['reservas'][0]['pos'] ?? null) === [-20.851235, -42.8011] && !array_key_exists('pos', $rp['reservas'][1]) && $rp['reservas'][1]['m'] === 20.0, 'reserva com pos (6 casas) e reserva antiga sem pos convivem');
foreach (['pos fora' => '{"reservas":[{"local":"x","m":1,"pos":[95,0]}]}', 'pos incompleta' => '{"reservas":[{"local":"x","m":1,"pos":[1]}]}', 'pos texto' => '{"reservas":[{"local":"x","m":1,"pos":["a","b"]}]}', 'pos escalar' => '{"reservas":[{"local":"x","m":1,"pos":5}]}'] as $label => $raw) {
    $err = [];
    $ok(MapPath::normalize($raw, $err) === null && $err !== [], "recusa reserva: {$label}");
}
$withPos = MapPath::lengths($A, $B, $rp, null, 0.0);
$noPos = $rp; unset($noPos['reservas'][0]['pos']);
$ok($withPos['reserves_m'] === 50.0 && MapPath::lengths($A, $B, $noPos, null, 0.0)['total_m'] === $withPos['total_m'], 'a posicao nao muda o total: as duas reservas (30 + 20) entram igual');
$simple = MapPath::simplify([[0, 0], [0.0000001, 0.0005], [0, 0.001]], 1.0);
$ok(count($simple) === 2, 'simplify remove ponto colinear dentro da tolerância');
$simple = MapPath::simplify([[0, 0], [0.001, 0.0005], [0, 0.001]], 1.0);
$ok(count($simple) === 3, 'simplify mantém desvio maior que a tolerância');

// ---- Controlador, schema descartável ----
$schema = (new ScratchSchema($root, 'tracado'))->create();
try {
    $pdo = $schema->pdo;
    $ctl = new MapController(new LocalRepository($schema->database, 'default'), new MapRepository($schema->database));
    $_SESSION = ['user' => ['login' => 'zz_gestor', 'name' => 'ZZ', 'role' => 'manager', 'id' => 0]];
    $post = static function (string $method, array $f, bool $csrf = true) use ($ctl): array {
        $r = $ctl->$method(new Request('POST', '/x', '', [], $f + ($csrf ? ['_csrf' => Csrf::token('map')] : [])));
        return [$r->status(), json_decode($r->body(), true) ?? []];
    };
    $get = static function () use ($ctl): array {
        $r = $ctl->data(new Request('GET', '/api/mapa'));
        return json_decode($r->body(), true)['data'] ?? [];
    };
    $node = static fn (array $r): int => (int) ($r[1]['data']['node']['id'] ?? 0);
    $a = $node($post('createNode', ['node_type' => 'cto', 'name' => 'CTO-A', 'lat' => '-20.85', 'lng' => '-42.80', 'attrs' => '{"capacity":8}']));
    $b = $node($post('createNode', ['node_type' => 'cto', 'name' => 'CTO-B', 'lat' => '-20.86', 'lng' => '-42.81', 'attrs' => '{"capacity":8}']));
    $c = $node($post('createNode', ['node_type' => 'cto', 'name' => 'CTO-C', 'lat' => '-20.87', 'lng' => '-42.82', 'attrs' => '{"capacity":8}']));

    $pathA = '{"pontos":[[-20.85,-42.805],[-20.855,-42.81]],"reservas":[{"local":"CTO-A","m":25}],"folga_pct":5}';
    $r = $post('createLink', ['link_type' => 'distribuicao', 'from_node_id' => $a, 'to_node_id' => $b, 'label' => 'A-B', 'path' => $pathA]);
    $id1 = (int) ($r[1]['data']['link']['id'] ?? 0);
    $stored = $get()['links'][0]['path_json'] ?? null;
    $ok($r[0] === 200 && $id1 > 0 && count($stored['pontos'] ?? []) === 2 && (float) ($stored['reservas'][0]['m'] ?? 0) === 25.0 && (float) ($stored['folga_pct'] ?? 0) === 5.0, 'criar cabo com traçado, reserva e folga: GET /api/mapa devolve path_json');
    $ok($get()['links'][0]['length_m'] === null, 'criar com traçado não grava length_m (manual só quando informado)');

    $r = $post('createLink', ['link_type' => 'distribuicao', 'from_node_id' => $b, 'to_node_id' => $c, 'label' => 'B-C']);
    $id2 = (int) ($r[1]['data']['link']['id'] ?? 0);
    $ok($r[0] === 200 && $r[1]['data']['link']['path_json'] === null, 'cabo sem path continua sem traçado (reta; cabos antigos)');
    $ok($post('updateLink', ['id' => $id2, 'label' => 'B-C v2'])[0] === 200 && $get()['links'][1]['path_json'] === null, 'editar cabo antigo sem enviar path não cria traçado');

    $r = $post('updateLink', ['id' => $id1, 'label' => 'renomeado']);
    $ok($r[0] === 200 && count($get()['links'][0]['path_json']['pontos'] ?? []) === 2, 'editar rótulo sem enviar path preserva o traçado');

    $r = $post('updateLink', ['id' => $id1, 'path' => '{"pontos":[[-20.851,-42.801]]}']);
    $s = $get()['links'][0]['path_json'];
    $ok($r[0] === 200 && count($s['pontos']) === 1 && $s['reservas'] === [], 'atualizar traçado substitui o anterior (adicionar/arrastar/remover pontos)');
    $r = $post('updateLink', ['id' => $id1, 'path' => '']);
    $ok($r[0] === 200 && $get()['links'][0]['path_json'] === null, 'path vazio remove o traçado (volta à linha reta)');

    foreach (['lat 91' => '{"pontos":[[91,0]]}', 'reserva negativa' => '{"reservas":[{"local":"x","m":-5}]}', 'folga 200' => '{"pontos":[[1,1]],"folga_pct":200}', 'lixo' => 'abc'] as $label => $raw) {
        $before = json_encode($get()['links'][0]['path_json']);
        $r = $post('updateLink', ['id' => $id1, 'path' => $raw]);
        $ok($r[0] === 422 && json_encode($get()['links'][0]['path_json']) === $before, "path inválido ({$label}) = 422 e nada é gravado");
    }
    $r = $post('createLink', ['link_type' => 'distribuicao', 'from_node_id' => $a, 'to_node_id' => $c, 'path' => '{"pontos":[[500,0]]}']);
    $ok($r[0] === 422 && (int) $pdo->query('SELECT COUNT(*) FROM map_links')->fetchColumn() === 2, 'criar com path inválido = 422 e não cria o cabo');

    $r = $post('updateLink', ['id' => $id1, 'path' => $pathA, 'length_m' => '777']);
    $row = $pdo->query("SELECT length_m FROM map_links WHERE id = {$id1}")->fetch(PDO::FETCH_ASSOC);
    $ok($r[0] === 200 && (float) $row['length_m'] === 777.0 && count($get()['links'][0]['path_json']['pontos']) === 2, 'comprimento manual (length_m) coexiste com o traçado, sem apagá-lo');

    // moves a CTO: o traçado intermediário fica; as pontas seguem o nó (calculado na leitura)
    $post('updateNode', ['id' => $b, 'lat' => '-20.90', 'lng' => '-42.90']);
    $ok(count($get()['links'][0]['path_json']['pontos']) === 2, 'mover a CTO não mexe nos pontos intermediários gravados');

    // auditoria sem a lista de pontos
    $audit = $pdo->query("SELECT context_json FROM audit_logs WHERE action = 'map.link.update' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $ok(is_string($audit) && !str_contains($audit, '-20.855') && str_contains($audit, 'pontos'), 'auditoria guarda só a contagem de pontos, não as coordenadas');

    // reservas com posicao: gravadas, devolvidas, movidas sem mexer no total; antigas sem pos continuam
    $pr = '{"pontos":[[-20.855,-42.805]],"reservas":[{"local":"CTO-A","m":30,"pos":[-20.8531,-42.8031]},{"local":"antiga","m":20}]}';
    $r = $post('updateLink', ['id' => $id1, 'path' => $pr]);
    $rv = $get()['links'][0]['path_json']['reservas'] ?? [];
    $ok($r[0] === 200 && count($rv) === 2 && $rv[0]['pos'] === [-20.8531, -42.8031] && !isset($rv[1]['pos']), 'GET devolve a reserva com pos e a antiga sem pos');
    $r = $post('updateLink', ['id' => $id1, 'path' => '{"pontos":[[-20.855,-42.805]],"reservas":[{"local":"CTO-A","m":30,"pos":[-20.8541,-42.8041]},{"local":"antiga","m":20}]}']);
    $rv2 = $get()['links'][0]['path_json']['reservas'];
    $ok($r[0] === 200 && $rv2[0]['pos'] === [-20.8541, -42.8041] && $rv2[0]['m'] == 30 && $rv2[1]['m'] == 20, 'mover a reserva so troca a pos (metros e a antiga intactos)');
    $before = json_encode($get()['links'][0]['path_json']);
    $r = $post('updateLink', ['id' => $id1, 'path' => '{"pontos":[],"reservas":[{"local":"x","m":1,"pos":[200,0]}]}']);
    $ok($r[0] === 422 && json_encode($get()['links'][0]['path_json']) === $before, 'pos invalida = 422 e nada e gravado');
    $post('updateLink', ['id' => $id1, 'path' => '']);

    // permissão e CSRF
    $r = $post('updateLink', ['id' => $id1, 'path' => '{"pontos":[[1,1]]}'], false);
    $ok($r[0] === 419, 'sem CSRF = 419');
    $_SESSION['user']['role'] = 'technician';
    $r = $post('updateLink', ['id' => $id1, 'path' => '{"pontos":[[1,1]]}']);
    $ok($r[0] === 403, 'sem perfil de gestor = 403');
    $_SESSION['user']['role'] = 'manager';

    // padrão de folga da rede
    $ok((float) ($get()['defaults']['map.cable.slack_pct'] ?? -1) === 0.0, 'padrão de folga da rede = 0 % enquanto não configurado');
    $pid = (int) $pdo->query('SELECT id FROM providers LIMIT 1')->fetchColumn();
    $pdo->prepare("INSERT INTO provider_settings (provider_id, setting_key, setting_value, updated_at) VALUES (?, 'map.cable.slack_pct', '8', NOW())")->execute([$pid]);
    $ok((float) ($get()['defaults']['map.cable.slack_pct'] ?? -1) === 8.0, 'padrão de folga lido de provider_settings (8 %)');

    // ---- Seguir rua (OSRM): sem serviço = 503 manual; com serviço (transporte falso) = pontos simplificados ----
    $rota = static fn (array $f = []): array => $post('route', $f + ['from_lat' => '-20.85', 'from_lng' => '-42.80', 'to_lat' => '-20.86', 'to_lng' => '-42.81']);
    $ok(($get()['routing']['enabled'] ?? null) === false, 'rotas: serviço desativado por padrão (URL vazia)');
    $r = $rota();
    $ok($r[0] === 503 && str_contains((string) ($r[1]['message'] ?? ''), 'não configurado'), 'rotas: sem URL = 503 e mensagem para seguir no modo manual');
    $pdo->prepare("INSERT INTO provider_settings (provider_id, setting_key, setting_value, updated_at) VALUES (?, 'map.routing.osrm_url', 'http://127.0.0.1:5000/', NOW())")->execute([$pid]);
    $ok(($get()['routing']['enabled'] ?? null) === true, 'rotas: habilitado com URL configurada');
    $asked = [];
    $coords = [];
    for ($i = 0; $i <= 50; $i++) {
        $coords[] = [-42.80 - $i * 0.0002, -20.85 - $i * 0.0002 + (($i % 2) * 0.00001)];
    }
    $coords[] = [-42.81, -20.86];
    $ctl->routeHttp = static function (string $url) use (&$asked, $coords): ?string { $asked[] = $url; return json_encode(['code' => 'Ok', 'routes' => [['geometry' => ['coordinates' => $coords]]]]); };
    $r = $rota();
    $pts = $r[1]['data']['pontos'] ?? [];
    $ok($r[0] === 200 && count($pts) >= 1 && count($pts) < 52 && $pts[count($pts) - 1] === [-20.86, -42.81], 'rotas: devolve [lat,lng] simplificados, terminando no destino');
    $ok(count($asked) === 1 && str_starts_with($asked[0], 'http://127.0.0.1:5000/route/v1/driving/-42.800000,-20.850000;-42.810000,-20.860000?') && str_contains($asked[0], 'geometries=geojson'), 'rotas: URL no formato OSRM (lon,lat) sem barra dupla');
    $ctl->routeHttp = static fn (string $u): ?string => null;
    $ok($rota()[0] === 503, 'rotas: serviço fora do ar = 503 (tela segue manual)');
    $ctl->routeHttp = static fn (string $u): ?string => '{"code":"NoRoute"}';
    $ok($rota()[0] === 503, 'rotas: NoRoute = 503');
    $ctl->routeHttp = static fn (string $u): ?string => 'lixo';
    $ok($rota()[0] === 503, 'rotas: resposta inválida = 503');
    $ok($rota(['to_lat' => '95'])[0] === 422 && $rota(['from_lat' => 'x'])[0] === 422, 'rotas: coordenadas inválidas = 422');
    $ok($rota(['to_lat' => '-21.5'])[0] === 422, 'rotas: trecho acima de 20 km = 422');
    $ok($post('route', ['from_lat' => '1', 'from_lng' => '1', 'to_lat' => '1', 'to_lng' => '1.1'], false)[0] === 419, 'rotas: sem CSRF = 419');
    $ok(\App\Infrastructure\Map\OsrmRouter::validBase('file:///etc/passwd') === null && \App\Infrastructure\Map\OsrmRouter::validBase('http://x y') === null && \App\Infrastructure\Map\OsrmRouter::validBase('') === null && \App\Infrastructure\Map\OsrmRouter::validBase('https://osrm.local:5000/') === 'https://osrm.local:5000', 'rotas: só http(s) é aceito como URL do serviço');
} finally {
    $schema->drop();
}

echo $fails === 0 ? "OK: traçado sem falhas\n" : "HOUVE FALHAS: {$fails}\n";
exit($fails === 0 ? 0 : 1);
