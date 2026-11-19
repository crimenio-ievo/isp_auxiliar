<?php
declare(strict_types=1);
// Importação de cabos por KML/KMZ (LineString) no /mapa: prévia (nada grava), raio, status por linha e gravação tudo-ou-nada.
// Schema descartável; procedural: php tests/Feature/MapCableImportSmoke.php
$root = dirname(__DIR__, 2);
require $root . '/backend/bootstrap/autoload.php';
require $root . '/tests/support/ScratchSchema.php';

use App\Controllers\MapController;
use App\Core\Csrf;
use App\Core\Request;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\Map\KmlCableReader;
use App\Infrastructure\Map\MapRepository;
use Tests\Support\ScratchSchema;

$fails = 0;
$ok = static function (bool $c, string $m) use (&$fails): void { echo ($c ? '[OK]    ' : '[FALHA] ') . $m . "\n"; if (!$c) { $fails++; } };

$ls = static fn (string $name, array $pts): string => '<Placemark><name>' . $name . '</name><LineString><coordinates>'
    . implode(' ', array_map(static fn (array $p): string => $p[1] . ',' . $p[0] . ',0', $pts)) . '</coordinates></LineString></Placemark>';
$A = [-20.85, -42.80]; $B = [-20.86, -42.81]; $C = [-20.87, -42.82]; $D = [-20.88, -42.83]; $E = [-20.8800036, -42.83];
$kml = '<?xml version="1.0"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document>'
    . $ls('A-B', [[-20.85002, -42.80001], [-20.853, -42.803], [-20.857, -42.806], [-20.86001, -42.81]])           // ok, 2 pontos no meio
    . $ls('B-C', [$B, [-20.865, -42.815], $C])                                                                    // ok
    . $ls('longe', [[-21.5, -43.5], [-21.6, -43.6]])                                                                // sem_pontas
    . $ls('so-inicio', [$A, [-20.9, -42.9]])                                                                        // sem_destino
    . $ls('volta', [$A, [-20.851, -42.801], [-20.85001, -42.80001]])                                                // mesmo_no
    . $ls('A-B repetido', [$A, [-20.855, -42.805], $B])                                                             // duplicado (já visto na linha 1)
    . $ls('ambiguo', [$C, $D])                                                                                      // fim entre D e E (0,4 m)
    . '<Placemark><name>ponto</name><Point><coordinates>-42.8,-20.85,0</coordinates></Point></Placemark>'
    . '</Document></kml>';

// ---- leitor puro ----
$r = KmlCableReader::lines($kml);
$ok($r['ok'] && count($r['linhas']) === 7 && $r['ignorados'] === 1 && $r['linhas'][0]['pontos'][0] === [-20.85002, -42.80001], 'leitor: 7 linhas, lon,lat do KML viram [lat,lng], ponto isolado é ignorado');
$ok(!KmlCableReader::lines('<kml><Placemark><name>p</name><Point><coordinates>1,1</coordinates></Point></Placemark></kml>')['ok'], 'leitor: só pontos = recusado');
$ok(!KmlCableReader::lines('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><kml><Placemark><LineString><coordinates>&e;</coordinates></LineString></Placemark></kml>')['ok'], 'leitor: DOCTYPE/XXE recusado');
$mg = KmlCableReader::lines('<kml><Placemark><name>m</name><MultiGeometry><LineString><coordinates>-42.8,-20.85 -42.81,-20.86</coordinates></LineString><LineString><coordinates>-42.81,-20.86 -42.82,-20.87</coordinates></LineString></MultiGeometry></Placemark></kml>');
$ok($mg['ok'] && count($mg['linhas']) === 2, 'leitor: MultiGeometry com 2 linhas vira 2 candidatos');
$bad = KmlCableReader::lines('<kml><Placemark><LineString><coordinates>-42.8,-20.85 xx,yy -42.9,95 -42.81,-20.86</coordinates></LineString></Placemark></kml>');
$ok($bad['ok'] && count($bad['linhas'][0]['pontos']) === 2, 'leitor: coordenadas inválidas/fora da faixa são descartadas');
$zip = sys_get_temp_dir() . '/mci_' . getmypid() . '.kmz';
$z = new ZipArchive(); $z->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE); $z->addFromString('doc.kml', $kml); $z->close();
$ok(count(KmlCableReader::lines((string) file_get_contents($zip))['linhas']) === 7, 'leitor: KMZ igual ao KML');
unlink($zip);

// ---- controlador ----
$schema = (new ScratchSchema($root, 'cabos'))->create();
try {
    $pdo = $schema->pdo;
    $ctl = new MapController(new LocalRepository($schema->database, 'default'), new MapRepository($schema->database));
    $_SESSION = ['user' => ['login' => 'zz_gestor', 'name' => 'ZZ', 'role' => 'manager', 'id' => 0]];
    $post = static function (array $f, bool $csrf = true) use ($ctl): array {
        $r = $ctl->importCables(new Request('POST', '/x', '', [], $f + ($csrf ? ['_csrf' => Csrf::token('map')] : [])));
        return [$r->status(), json_decode($r->body(), true) ?? []];
    };
    $mk = static function (string $name, array $ll) use ($ctl): int {
        $r = $ctl->createNode(new Request('POST', '/x', '', [], ['node_type' => 'cto', 'name' => $name, 'lat' => (string) $ll[0], 'lng' => (string) $ll[1], 'attrs' => '{"capacity":8}', '_csrf' => Csrf::token('map')]));
        return (int) (json_decode($r->body(), true)['data']['node']['id'] ?? 0);
    };
    $ids = ['A' => $mk('CTO-A', $A), 'B' => $mk('CTO-B', $B), 'C' => $mk('CTO-C', $C), 'D' => $mk('CTO-D', $D), 'E' => $mk('CTO-E', $E)];
    $links = static fn (): int => (int) $pdo->query('SELECT COUNT(*) FROM map_links')->fetchColumn();
    $b64 = base64_encode($kml);

    $r = $post(['kml_b64' => $b64]);
    $st = array_column($r[1]['data']['linhas'] ?? [], 'status', 'indice');
    $ok($r[0] === 200 && ($r[1]['data']['previa'] ?? false) === true && $links() === 0, 'prévia: 200 e NADA gravado');
    $ok(($st[0] ?? '') === 'ok' && ($st[1] ?? '') === 'ok', 'prévia: A-B e B-C ligam elementos pelas pontas mais próximas');
    $ok(($st[2] ?? '') === 'sem_pontas' && ($st[3] ?? '') === 'sem_destino' && ($st[4] ?? '') === 'mesmo_no' && ($st[5] ?? '') === 'duplicado' && ($st[6] ?? '') === 'ambiguo', 'prévia: sem_pontas, sem_destino, mesmo_no, duplicado (no arquivo) e ambíguo (D e E a 0,4 m)');
    $l0 = $r[1]['data']['linhas'][0];
    $ok($l0['origem']['nome'] === 'CTO-A' && $l0['destino']['nome'] === 'CTO-B' && $l0['n_pontos_meio'] === 2 && $l0['origem']['dist_m'] < 5 && str_contains($l0['mensagem'], 'CTO-A → CTO-B'), 'prévia lista o que liga a qual CTO, com distância e pontos intermediários');
    $ok(str_contains($r[1]['data']['linhas'][3]['mensagem'], '25 m') && str_contains($r[1]['data']['linhas'][2]['mensagem'], 'mais próximo'), 'prévia explica o motivo e o elemento mais próximo');

    // raio configurável: 0,5 m deixa a linha 0 (a 1-2,5 m das CTOs) sem ponta
    $r2 = $post(['kml_b64' => $b64, 'raio_m' => '1']);
    $ok(($r2[1]['data']['linhas'][0]['status'] ?? '') === 'sem_pontas', 'raio de 1 m: A-B deixa de ligar (raio é respeitado)');
    $ok($post(['kml_b64' => $b64, 'raio_m' => '0'])[0] === 422 && $post(['kml_b64' => $b64, 'raio_m' => '9999'])[0] === 422, 'raio fora de 1–500 m = 422');

    // confirmar só a linha 0
    $r = $post(['kml_b64' => $b64, 'confirmar' => '1', 'selecionados' => '0', 'tipo' => 'distribuicao', 'lifecycle' => 'projeto', 'fibras' => '12']);
    $row = $pdo->query('SELECT * FROM map_links')->fetchAll(PDO::FETCH_ASSOC);
    $path = json_decode((string) ($row[0]['path_json'] ?? ''), true);
    $ok($r[0] === 200 && count($row) === 1 && (int) $row[0]['from_node_id'] === $ids['A'] && (int) $row[0]['to_node_id'] === $ids['B'] && count($path['pontos'] ?? []) === 2, 'confirmar grava só a linha escolhida, com traçado de 2 pontos');
    $ok($row[0]['lifecycle'] === 'projeto' && (int) $row[0]['fiber_count'] === 12 && $row[0]['length_m'] === null && str_contains((string) $row[0]['attrs_json'], 'A-B') && str_contains((string) $row[0]['attrs_json'], '12FO'), 'situação, fibras e nome do cabo (KML) gravados; comprimento fica calculado (length_m vazio)');
    $audit = (string) $pdo->query("SELECT context_json FROM audit_logs WHERE action='map.link.create' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $ok(str_contains($audit, 'importacao_kml'), 'auditoria marca a origem como importação KML');

    // segunda importação: A-B agora é duplicado; B-C entra
    $r = $post(['kml_b64' => $b64]);
    $ok(($r[1]['data']['linhas'][0]['status'] ?? '') === 'duplicado', 'reimportar: A-B vira duplicado (cabo já existe)');
    $r = $post(['kml_b64' => $b64, 'confirmar' => '1']);
    $ok($r[0] === 200 && $links() === 2, 'sem "selecionados" importa todas as linhas ok restantes (só B-C)');
    // linha não importável escolhida = 422 e nada grava
    $before = $links();
    $r = $post(['kml_b64' => $b64, 'confirmar' => '1', 'selecionados' => '2']);
    $ok($r[0] === 422 && $links() === $before, 'escolher linha não importável = 422 e nada gravado');

    // ethernet: fibras ignoradas (medium não é fibra); wireless recusado
    $pdo->exec('DELETE FROM map_links');
    $r = $post(['kml_b64' => $b64, 'confirmar' => '1', 'selecionados' => '0', 'tipo' => 'ethernet', 'fibras' => '12']);
    $ok($r[0] === 200 && $pdo->query('SELECT fiber_count FROM map_links')->fetchColumn() === null, 'ethernet: nº de fibras é ignorado');
    $ok($post(['kml_b64' => $b64, 'tipo' => 'wireless'])[0] === 422, 'tipo wireless não é aceito para cabo');
    $pdo->exec('DELETE FROM map_links');

    // arquivo / permissões
    $ok($post(['kml_b64' => 'não é base64!!'])[0] === 422 && $post([])[0] === 422, 'arquivo ausente/inválido = 422');
    $ok($post(['kml_b64' => $b64], false)[0] === 419, 'sem CSRF = 419');
    $_SESSION['user']['role'] = 'technician';
    $ok($post(['kml_b64' => $b64])[0] === 403, 'sem perfil de gestor = 403');
    $_SESSION['user']['role'] = 'manager';
    $ok($links() === 0, 'nenhuma gravação fora dos casos confirmados');
} finally {
    $schema->drop();
}
echo $fails === 0 ? "OK: importação de cabos sem falhas\n" : "HOUVE FALHAS: {$fails}\n";
exit($fails === 0 ? 0 : 1);
