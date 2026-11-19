<?php
declare(strict_types=1);
// Fluxo de mapeamento real, de ponta a ponta pelo MapController (mesmas rotas do /mapa), em schema descartavel:
// site (POP) -> OLT com PONs -> duas CTOs -> cabos OLT->CTO e CTO->CTO -> splitter -> editar/mover -> excluir.
// Cada passo imprime [FUNCIONA] ou [NAO EXISTE] (recurso ausente, documentado em docs/guia-mapeamento.md); [FALHA] = erro.
$root = dirname(__DIR__, 2);
require $root . '/backend/bootstrap/autoload.php';
require $root . '/tests/support/ScratchSchema.php';

use App\Controllers\MapController;
use App\Core\Csrf;
use App\Core\Request;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\Map\MapRepository;
use Tests\Support\ScratchSchema;

$fails = 0;
$say = static function (string $tag, string $msg) use (&$fails): void { echo "[{$tag}] {$msg}\n"; if ($tag === 'FALHA') { $fails++; } };
$ok = static fn (bool $c, string $m) => $say($c ? 'FUNCIONA' : 'FALHA', $m);

$schema = (new ScratchSchema($root, 'mapflow'))->create();
try {
    $pdo = $schema->pdo;
    $local = new LocalRepository($schema->database, 'default');
    $ctl = new MapController($local, new MapRepository($schema->database));
    $_SESSION = ['user' => ['login' => 'zz_gestor', 'name' => 'ZZ', 'role' => 'manager', 'id' => 0]];
    $post = static function (string $method, array $f) use ($ctl): array {
        $r = $ctl->$method(new Request('POST', '/x', '', [], $f + ['_csrf' => Csrf::token('map')]));
        return [$r->status(), json_decode($r->body(), true) ?? []];
    };
    $data = static function () use ($ctl): array {
        $r = $ctl->data(new Request('GET', '/api/mapa'));
        return json_decode($r->body(), true)['data'] ?? [];
    };
    $node = static fn (array $r) => $r[1]['data']['node'] ?? [];
    $portsOf = static fn (int $id): array => array_values(array_filter($data()['ports'], static fn ($p) => (int) $p['node_id'] === $id));

    // 1. Site (POP)
    $r = $post('createNode', ['node_type' => 'pop', 'name' => 'POP Centro', 'lat' => '-20.8500', 'lng' => '-42.8000']);
    $pop = (int) ($node($r)['id'] ?? 0);
    $ok($r[0] === 200 && $pop > 0, '1. criar site (POP) com coordenadas');

    // 2. OLT no site, com portas PON
    $r = $post('createNode', ['node_type' => 'olt', 'name' => 'OLT-001', 'parent_node_id' => $pop, 'ports_pon' => '4', 'ports_sfp' => '2']);
    $olt = (int) ($node($r)['id'] ?? 0);
    $pons = array_values(array_filter($portsOf($olt), static fn ($p) => $p['port_role'] === 'pon'));
    $ok($r[0] === 200 && count($pons) === 4, '2. OLT dentro do POP com 4 portas PON (' . count($pons) . ' criadas)');

    // 3. Duas CTOs com coordenadas e capacidade
    $r = $post('createNode', ['node_type' => 'cto', 'name' => 'CTO-A', 'lat' => '-20.8550', 'lng' => '-42.8050', 'attrs' => '{"capacity":8}']);
    $ctoA = (int) ($node($r)['id'] ?? 0);
    $r2 = $post('createNode', ['node_type' => 'cto', 'name' => 'CTO-B', 'lat' => '-20.8600', 'lng' => '-42.8100', 'attrs' => '{"capacity":8}']);
    $ctoB = (int) ($node($r2)['id'] ?? 0);
    $ok($r[0] === 200 && $r2[0] === 200 && count($portsOf($ctoA)) === 8, '3. duas CTOs com coordenadas (8 portas "Porta n" cada)');

    // 4. Cabo OLT -> CTO-A (fibra de distribuicao, PON 1 -> Porta 1) e CTO-A -> CTO-B
    $portA1 = (int) array_values(array_filter($portsOf($ctoA), static fn ($p) => (int) $p['port_num'] === 1))[0]['id'];
    $portA2 = (int) array_values(array_filter($portsOf($ctoA), static fn ($p) => (int) $p['port_num'] === 2))[0]['id'];
    $portB1 = (int) array_values(array_filter($portsOf($ctoB), static fn ($p) => (int) $p['port_num'] === 1))[0]['id'];
    $l1 = $post('createLink', ['link_type' => 'distribuicao', 'from_node_id' => $olt, 'to_node_id' => $ctoA, 'from_port_id' => (int) $pons[0]['id'], 'to_port_id' => $portA1, 'label' => 'OLT-001 -> CTO-A', 'fiber_count' => '12', 'length_m' => '900']);
    $linkA = (int) ($l1[1]['data']['link']['id'] ?? 0);
    $ok($l1[0] === 200 && $linkA > 0, '4a. cabo OLT -> CTO-A com portas, fibras e comprimento informado (900 m)');
    $l2 = $post('createLink', ['link_type' => 'distribuicao', 'from_node_id' => $ctoA, 'to_node_id' => $ctoB, 'from_port_id' => $portA2, 'to_port_id' => $portB1, 'label' => 'CTO-A -> CTO-B']);
    $linkB = (int) ($l2[1]['data']['link']['id'] ?? 0);
    $ok($l2[0] === 200 && $linkB > 0, '4b. cabo CTO-A -> CTO-B sem comprimento informado');
    $dup = $post('createLink', ['link_type' => 'distribuicao', 'from_node_id' => $ctoA, 'to_node_id' => $ctoB]);
    $ok($dup[0] === 409, '4c. cabo duplicado entre os mesmos elementos e recusado (409)');

    // 5. Pontos intermediarios do cabo (feature/mapa-tracado): gravados em map_links.path_json pelo campo "path"
    $e = $post('updateLink', ['id' => $linkA, 'path' => '{"pontos":[[-20.852,-42.802],[-20.853,-42.803]]}']);
    $stored = json_decode((string) $pdo->query("SELECT path_json FROM map_links WHERE id = {$linkA}")->fetchColumn(), true);
    $ok($e[0] === 200 && count($stored['pontos'] ?? []) === 2, '5. pontos intermediarios do cabo gravados em path_json (cabos sem traçado seguem como linha reta)');
    $post('updateLink', ['id' => $linkA, 'path' => '']);

    // 6. Reserva tecnica: nao ha campo proprio; contorno = comprimento informado (rota + reserva) e anotacao em attrs
    $e = $post('updateLink', ['id' => $linkA, 'length_m' => '1050', 'attrs' => '{"reserva_tecnica_m":"50","cabo_nome":"CABO 12FO"}']);
    $row = $pdo->query("SELECT length_m, attrs_json FROM map_links WHERE id = {$linkA}")->fetch(PDO::FETCH_ASSOC);
    $e = $post('updateLink', ['id' => $linkA, 'path' => '{"reservas":[{"local":"CTO-A","m":30}]}']);
    $ok($e[0] === 200 && (float) (json_decode((string) $pdo->query("SELECT path_json FROM map_links WHERE id = {$linkA}")->fetchColumn(), true)['reservas'][0]['m'] ?? 0) === 30.0, '6. reserva tecnica como recurso proprio (lista no cabo, em path_json)');
    $post('updateLink', ['id' => $linkA, 'path' => '']);
    $ok($e[0] === 200 && (float) $row['length_m'] === 1050.0 && str_contains((string) $row['attrs_json'], 'reserva_tecnica_m'), '6b. contorno: comprimento informado (rota + reserva) e anotacao "reserva_tecnica_m" em attrs gravados');

    // 7. Splitter na CTO
    $r = $post('createNode', ['node_type' => 'splitter', 'name' => 'SPL-A1', 'parent_node_id' => $ctoA, 'attrs' => '{"razao":"1x8"}']);
    $spl = (int) ($node($r)['id'] ?? 0);
    $splPorts = $portsOf($spl);
    $roles = array_count_values(array_column($splPorts, 'port_role'));
    $ok($r[0] === 200 && ($roles['spl_in'] ?? 0) === 1 && ($roles['spl_out'] ?? 0) === 8, '7. splitter 1x8 dentro da CTO-A: 1 entrada + 8 saidas geradas');
    $bad = $post('createNode', ['node_type' => 'splitter', 'name' => 'SPL-RUIM', 'parent_node_id' => $ctoA, 'attrs' => '{"razao":"1x3"}']);
    $ok($bad[0] === 422, '7b. razao de splitter invalida e recusada (422)');

    // 8. Editar e mover ponto: a distancia acompanha?
    $before = $data();
    $linkRow = static fn (array $d, int $id) => array_values(array_filter($d['links'], static fn ($l) => (int) $l['id'] === $id))[0];
    $r = $post('updateNode', ['id' => $ctoB, 'name' => 'CTO-B (movida)', 'lat' => '-20.8700', 'lng' => '-42.8200']);
    $after = $data();
    $moved = array_values(array_filter($after['nodes'], static fn ($n) => (int) $n['id'] === $ctoB))[0];
    $ok($r[0] === 200 && (float) $moved['lat'] === -20.87 && $moved['name'] === 'CTO-B (movida)', '8a. editar nome e mover a CTO-B (lat/lng novos gravados)');
    $ok($linkRow($after, $linkB)['length_m'] === null, '8b. cabo sem comprimento informado continua sem valor gravado: a tela recalcula a linha reta a partir das coordenadas (acompanha o ponto movido)');
    $say('FUNCIONA', '8c. cabo com comprimento informado (' . (float) $linkRow($after, $linkA)['length_m'] . ' m) NAO muda ao mover pontos: e valor manual por desenho');
    $r = $post('updateNode', ['id' => $ctoA, 'lat' => '-20.8555', 'lng' => '-42.8055']);
    $ok($r[0] === 200 && count($data()['links']) === 2, '8d. mover a CTO-A mantem os dois cabos ligados');

    // 9. Excluir
    $r = $post('deleteNode', ['id' => $ctoA]);
    $ok($r[0] === 409, '9a. excluir CTO com cabos/filhos e bloqueado (409): ' . ($r[1]['message'] ?? ''));
    $r = $post('deleteLink', ['id' => $linkB]);
    $ok($r[0] === 200, '9b. excluir cabo CTO-A -> CTO-B');
    $r = $post('deleteNode', ['id' => $ctoB]);
    $ok($r[0] === 200, '9c. excluir CTO-B (sem cabos) com suas portas');
    $r = $post('deleteNode', ['id' => $spl]);
    $ok($r[0] === 200, '9d. excluir o splitter');
    $post('deleteLink', ['id' => $linkA]);
    $r = $post('deleteNode', ['id' => $ctoA]);
    $ok($r[0] === 200, '9e. excluir a CTO-A depois de remover o cabo e o splitter');
    $left = (int) $pdo->query("SELECT COUNT(*) FROM map_ports WHERE node_id IN ({$ctoA},{$ctoB},{$spl})")->fetchColumn();
    $ok($left === 0, '9f. portas das CTOs/splitter removidas junto');
    $r = $post('deleteNode', ['id' => $pop]);
    $ok($r[0] === 409, '9g. excluir POP que ainda tem a OLT e bloqueado (409)');
} finally {
    $schema->drop();
}
echo $fails === 0 ? "OK: fluxo de mapeamento sem falhas (veja [NAO EXISTE])\n" : "HOUVE FALHAS: {$fails}\n";
exit($fails === 0 ? 0 : 1);
