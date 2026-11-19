<?php
declare(strict_types=1);
// CP-3: importacao de CTOs por KML/KMZ (Google My Maps/Earth) com a mesma previa e validacao do CSV. Schema descartavel.
$root = dirname(__DIR__, 2);
require $root . '/backend/bootstrap/autoload.php';
require $root . '/tests/support/ScratchSchema.php';

use App\Controllers\MapController;
use App\Controllers\NetCtoController;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\Map\MapRepository;
use App\Infrastructure\Net\CtoCsvImporter;
use App\Infrastructure\Net\KmlCtoReader;
use App\Services\Net\MapNodeGateway;
use Tests\Support\ScratchSchema;

$n = 0;
$check = static function (bool $ok, string $m) use (&$n): void { $n++; if (!$ok) { throw new RuntimeException("FALHA: {$m}"); } };
$kml = (string) file_get_contents($root . '/tests/fixtures/ctos_exemplo.kml');

// --- leitor (puro) ---
$r = KmlCtoReader::toCsv($kml);
$check($r['ok'] && $r['pontos'] === 2 && $r['ignorados'] === 1, 'KML: 2 pontos viram CTO; a linha (cabo) e ignorada');
$lines = explode("\n", trim($r['csv']));
$check($lines[0] === 'nome,lat,lng,capacidade,observacao' && str_starts_with($lines[1], '"CTO-KML-01","-20.8455534","-42.8023600","8","Poste 12Rua A & B"') && str_starts_with($lines[2], '"CTO-KML-02","-20.8500","-42.8100","",""'), 'lon,lat do KML viram lat,lng; capacidade pelo dado "capacidade"; descricao sem HTML');
$an = CtoCsvImporter::analyze($r['csv'], []);
$check($an['ok'] && $an['resumo'] === ['total' => 2, 'ok' => 2, 'erro' => 0, 'duplicado' => 0, 'proximo' => 0] && $an['linhas'][0]['lat'] === -20.8455534 && $an['linhas'][0]['capacidade'] === 8 && $an['linhas'][1]['capacidade'] === CtoCsvImporter::DEFAULT_CAPACITY, 'mesma analise do CSV: coordenadas, capacidade e padrao 16 sem capacidade');
// KMZ (precisa da extensao zip: servidor sem ela deve responder com a mensagem amigavel; o teste so pula a montagem do KMZ)
$hasZip = class_exists(ZipArchive::class);
$kmzPath = sys_get_temp_dir() . '/t_' . getmypid() . '.kmz';
if ($hasZip) {
    $zip = new ZipArchive(); $zip->open($kmzPath, ZipArchive::CREATE | ZipArchive::OVERWRITE); $zip->addFromString('files/icone.png', 'x'); $zip->addFromString('doc.kml', $kml); $zip->close();
    $kmz = (string) file_get_contents($kmzPath);
    $rz = KmlCtoReader::toCsv($kmz);
    $check($rz['ok'] && $rz['csv'] === $r['csv'], 'KMZ: le o doc.kml de dentro do zip (resultado identico ao KML)');
} else {
    echo "SKIP KMZ (extensão zip ausente)\n";
    $rz = KmlCtoReader::toCsv("PK\x03\x04conteudo-de-um-kmz");
    $check(!$rz['ok'] && str_contains((string) $rz['erro'], 'Este servidor não lê KMZ') && $rz['csv'] === '', 'sem zip: KMZ devolve a mensagem amigavel ("Este servidor não lê KMZ")');
}
// sem namespace e namespace antigo
$check(KmlCtoReader::toCsv('<kml><Placemark><name>X</name><Point><coordinates>-42.1,-20.1</coordinates></Point></Placemark></kml>')['pontos'] === 1 && KmlCtoReader::toCsv(str_replace('2.2', '2.0', $kml))['pontos'] === 2, 'KML sem namespace e com namespace 2.0');
// seguranca
$xxe = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><kml><Placemark><name>&e;</name><Point><coordinates>1,1</coordinates></Point></Placemark></kml>';
$rx = KmlCtoReader::toCsv($xxe);
$check(!$rx['ok'] && str_contains((string) $rx['erro'], 'DOCTYPE') && !str_contains(json_encode($rx), 'root:'), 'XXE/DOCTYPE recusado sem ler arquivo');
$bomb = '<?xml version="1.0"?><!DOCTYPE l [<!ENTITY a "aaaa"><!ENTITY b "&a;&a;&a;&a;">]><kml>&b;</kml>';
$check(!KmlCtoReader::toCsv($bomb)['ok'], 'entidades recursivas recusadas');
foreach ([['', 'vazio'], ['isto nao e xml', 'xml invalido'], ['<kml><Document/></kml>', 'sem Placemark'], ['<kml><Placemark><name>L</name><LineString><coordinates>1,1 2,2</coordinates></LineString></Placemark></kml>', 'so linhas'], [str_repeat('a', KmlCtoReader::MAX_BYTES + 1), 'grande demais'], ["PK\x03\x04quebrado", 'zip corrompido']] as [$bad, $why]) {
    $b = KmlCtoReader::toCsv($bad);
    $check(!$b['ok'] && $b['erro'] !== null && $b['csv'] === '', "recusado: {$why}");
}
if ($hasZip) {
    $zip = new ZipArchive(); $zip->open($kmzPath, ZipArchive::CREATE | ZipArchive::OVERWRITE); $zip->addFromString('leia.txt', 'x'); $zip->close();
    $check(!KmlCtoReader::toCsv((string) file_get_contents($kmzPath))['ok'], 'KMZ sem .kml: recusado');
    $zip = new ZipArchive(); $zip->open($kmzPath, ZipArchive::CREATE | ZipArchive::OVERWRITE); $zip->addFromString('doc.kml', '<kml>' . str_repeat(' ', KmlCtoReader::MAX_XML_BYTES) . '</kml>'); $zip->close();
    $check(!KmlCtoReader::toCsv((string) file_get_contents($kmzPath))['ok'], 'KMZ que descompacta alem do limite: recusado');
    unlink($kmzPath);
} else {
    echo "SKIP KMZ (extensão zip ausente)\n";
}
// nomes/coordenadas ruins passam pela mesma validacao do CSV
$ruim = '<kml><Placemark><name></name><Point><coordinates>-42.1,-20.1</coordinates></Point></Placemark><Placemark><name>Fora</name><Point><coordinates>-42.1,95</coordinates></Point></Placemark><Placemark><name>Cap</name><ExtendedData><Data name="capacity"><value>999</value></Data></ExtendedData><Point><coordinates>-42.2,-20.2</coordinates></Point></Placemark></kml>';
$anr = CtoCsvImporter::analyze(KmlCtoReader::toCsv($ruim)['csv'], []);
$check($anr['resumo']['erro'] === 3, 'nome vazio, latitude fora da faixa e capacidade 999: tres erros na previa');

// --- endpoint ---
$schema = (new ScratchSchema($root, 'netkml'))->create();
try {
    $pdo = $schema->pdo;
    $local = new LocalRepository($schema->database, 'default');
    $providerId = (int) $local->currentProviderId();
    $mapRepo = new MapRepository($schema->database);
    $ctl = new NetCtoController(new View($root . '/backend/Views'), new Config(['app' => ['name' => 'T']]), $local, $schema->database, $mapRepo, new MapNodeGateway(new MapController($local, $mapRepo)));
    $asManager = static function (): void { $_SESSION = ['user' => ['login' => 'zz_gestor', 'name' => 'ZZ', 'role' => 'manager', 'id' => 0]]; };
    $asTech = static function (): void { $_SESSION = ['user' => ['login' => 'zz_tec', 'name' => 'ZZ', 'role' => 'technician', 'id' => 0]]; };
    $json = static fn (Response $r): array => (array) json_decode($r->body(), true);
    $post = static function (array $f, bool $csrf = true) use ($ctl): Response { if ($csrf) { $f['_csrf'] = Csrf::token('rede'); } return $ctl->import(new Request('POST', '/x', '', [], $f)); };
    $count = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();
    $asManager();
    $b64 = base64_encode($kml);
    $r = $post(['kml_b64' => $b64]); $j = $json($r);
    $check($r->status() === 200 && $j['data']['previa'] === true && $j['data']['origem'] === 'kml' && $j['data']['ignorados_sem_ponto'] === 1 && $j['data']['resumo']['ok'] === 2 && $count("SELECT COUNT(*) FROM map_nodes WHERE node_type='cto'") === 0, 'previa de KML pelo endpoint: 2 prontas, 1 ignorada, nada gravado');
    $check($post(['kml_b64' => $b64], false)->status() === 419, 'sem CSRF: 419');
    $check($post(['kml_b64' => '###'])->status() === 422 && $post(['kml_b64' => base64_encode('lixo')])->status() === 422 && $post(['kml_b64' => str_repeat('A', 3_000_000)])->status() === 422, 'base64 invalido, xml invalido ou grande demais: 422');
    $r = $post(['kml_b64' => $b64, 'confirmar' => '1']); $j = $json($r);
    $check($r->status() === 200 && count($j['data']['relatorio']['criadas']) === 2 && $count("SELECT COUNT(*) FROM map_nodes WHERE node_type='cto'") === 2 && $count("SELECT COUNT(*) FROM map_ports WHERE port_role='fo'") === 8 + 16, 'confirmar KML: 2 CTOs criadas, portas pela capacidade (8 + 16 padrao)');
    $audit = (string) $pdo->query("SELECT context_json FROM audit_logs WHERE action='net.cto.import'")->fetchColumn();
    $check(str_contains($audit, '"origem": "kml"') || str_contains($audit, '"origem":"kml"'), 'auditoria registra a origem kml');
    $check($post(['kml_b64' => $b64, 'confirmar' => '1'])->status() === 422 && $count("SELECT COUNT(*) FROM map_nodes WHERE node_type='cto'") === 2, 'reimportar o mesmo KML nao duplica (422)');
    // KMZ pelo endpoint
    if ($hasZip) {
        $zp = sys_get_temp_dir() . '/k_' . getmypid() . '.kmz';
        $z = new ZipArchive(); $z->open($zp, ZipArchive::CREATE | ZipArchive::OVERWRITE); $z->addFromString('doc.kml', str_replace(['CTO-KML-01', 'CTO-KML-02', '-20.8455534', '-20.8500'], ['CTO-KMZ-01', 'CTO-KMZ-02', '-20.9455534', '-20.9500'], $kml)); $z->close();
        $r = $post(['kml_b64' => base64_encode((string) file_get_contents($zp))]); unlink($zp);
        $check($r->status() === 200 && $json($r)['data']['resumo']['ok'] === 2, 'KMZ pelo endpoint');
    } else {
        echo "SKIP KMZ (extensão zip ausente)\n";
        $r = $post(['kml_b64' => base64_encode("PK\x03\x04conteudo-de-um-kmz")]);
        $check($r->status() === 422 && str_contains((string) ($json($r)['message'] ?? ''), 'Este servidor não lê KMZ'), 'sem zip: KMZ pelo endpoint = 422 com a mensagem amigavel');
    }
    // CSV continua igual
    $check($json($post(['csv' => "nome,lat,lng\nC1,-20.7,-42.7\n"]))['data']['origem'] === 'csv', 'CSV segue funcionando (origem csv)');
    $asTech();
    $check($post(['kml_b64' => $b64])->status() === 403, 'tecnico: importar KML = 403');
} finally {
    $schema->drop();
}
echo "NetKmlImportSmoke: {$n} verificacoes, zero falhas\n";
