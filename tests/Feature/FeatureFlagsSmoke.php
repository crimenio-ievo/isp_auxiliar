<?php
declare(strict_types=1);
// Fase 6: flags por provedor feature.map e feature.net (resolucao, rotas, menu e busca). Sem escrita em banco.
$root = dirname(__DIR__, 2);
require $root . '/backend/bootstrap/app.php';

use App\Core\Features;
use App\Core\Request;

$n = 0;
$check = static function (bool $ok, string $m) use (&$n): void { $n++; if (!$ok) { throw new RuntimeException("FALHA: {$m}"); } };

// --- Resolucao: provider_settings > .env > padrao (ligado em teste, desligado em producao) ---
$check(Features::resolve(null, null, false) === true && Features::resolve('', '', false) === true, 'sem configuracao, ambiente de teste: ligado');
$check(Features::resolve(null, null, true) === false && Features::resolve('', null, true) === false && Features::resolve('lixo', 'lixo', true) === false, 'sem configuracao (ou valor invalido), producao: DESLIGADO');
foreach (['1', 'true', 'ON', ' sim ', 'Ligado', 'yes'] as $v) { $check(Features::resolve($v, null, true) === true, "setting '{$v}' liga mesmo em producao"); }
foreach (['0', 'false', 'OFF', 'nao', 'não', 'desligado', 'no'] as $v) { $check(Features::resolve($v, null, false) === false, "setting '{$v}' desliga mesmo em teste"); }
$check(Features::resolve('0', '1', false) === false && Features::resolve('1', '0', true) === true, 'o provedor vence o .env');
$check(Features::resolve(null, '0', false) === false && Features::resolve('', '1', true) === true, '.env vale quando o provedor nao definiu');
$check(Features::featureForPath('/mapa') === 'map' && Features::featureForPath('/mapa/diagram.js') === 'map' && Features::featureForPath('/api/mapa') === 'map' && Features::featureForPath('/api/mapa/nos/editar') === 'map', 'caminhos do mapa');
foreach (['/rede-fibra', '/rede-equipamentos', '/rede-ctos', '/rede-ctos/ficha', '/rede-vinculos', '/api/rede-fibra', '/api/rede-fibra/buscar', '/api/rede-equipamentos/testar', '/api/rede-ctos/importar', '/api/rede-vinculos/onu'] as $p) { $check(Features::featureForPath($p) === 'net', "caminho de rede {$p}"); }
foreach (['/', '/login', '/dashboard', '/clientes', '/mapas', '/api/mapas', '/redes', '/api/health', '/configuracoes', '/contratos'] as $p) { $check(Features::featureForPath($p) === null, "sem flag: {$p}"); }
Features::configure(['feature.map' => '0', 'feature.net' => '1', 'outra' => '0']);
$check(Features::enabled('map') === false && Features::enabled('net') === true, 'configure() le feature.map e feature.net do provedor');
Features::configure([]);

// --- Rotas com a aplicacao real (sem sessao; nada e gravado) ---
$_SESSION = [];
$app = bootstrapApplication();
$status = static fn (string $m, string $p) => $app->handle(new Request($m, $p));
$mapPaths = [['GET', '/mapa'], ['GET', '/mapa/diagram.js'], ['GET', '/api/mapa'], ['POST', '/api/mapa/nos'], ['POST', '/api/mapa/links/excluir']];
$netPaths = [['GET', '/rede-fibra'], ['GET', '/rede-equipamentos'], ['GET', '/rede-ctos'], ['GET', '/rede-ctos/ficha'], ['GET', '/rede-vinculos'], ['GET', '/api/rede-fibra'], ['GET', '/api/rede-fibra/buscar'], ['POST', '/api/rede-equipamentos'], ['POST', '/api/rede-equipamentos/testar'], ['POST', '/api/rede-ctos/importar'], ['POST', '/api/rede-vinculos/onu']];

Features::override(['map' => true, 'net' => true]);
foreach (array_merge($mapPaths, $netPaths) as [$m, $p]) {
    $r = $status($m, $p);
    $check($r->status() === 302 && $r->header('Location') === '/login', "ligado: {$m} {$p} segue o fluxo normal (sem sessao vai ao login)");
}

Features::override(['map' => false, 'net' => true]);
foreach ($mapPaths as [$m, $p]) {
    $r = $status($m, $p);
    if (str_starts_with($p, '/api/')) { $j = json_decode($r->body(), true); $check($r->status() === 403 && $j['ok'] === false && str_contains($j['message'], 'desativado') && $r->header('Cache-Control') === 'private, no-store', "mapa desligado: API {$m} {$p} = 403 JSON"); }
    else { $check($r->status() === 404, "mapa desligado: pagina {$p} = 404"); }
}
foreach ($netPaths as [$m, $p]) { $check($status($m, $p)->status() === 302, "mapa desligado: rede continua ({$p})"); }

Features::override(['map' => true, 'net' => false]);
foreach ($netPaths as [$m, $p]) {
    $r = $status($m, $p);
    if (str_starts_with($p, '/api/')) { $check($r->status() === 403 && (json_decode($r->body(), true)['ok'] ?? true) === false, "rede desligada: API {$m} {$p} = 403"); }
    else { $check($r->status() === 404, "rede desligada: pagina {$p} = 404"); }
}
foreach ($mapPaths as [$m, $p]) { $check($status($m, $p)->status() === 302, "rede desligada: mapa continua ({$p})"); }

Features::override(['map' => false, 'net' => false]);
foreach (['/', '/login', '/dashboard', '/clientes'] as $p) {
    $r = $status('GET', $p);
    $check($r->status() !== 403 && $r->status() !== 404, "tudo desligado: {$p} nao e afetado ({$r->status()})");
}
$r = $status('GET', '/api/health');
$check($r->status() === 200, 'tudo desligado: /api/health continua 200');
Features::override(null);

// --- Menu, busca global e link da ficha, em processo limpo ---
$render = static function (array $flags, array $access, string $what) use ($root): string {
    $program = <<<'PHP'
require $argv[1] . '/backend/bootstrap/autoload.php';
App\Core\Url::setBasePath('/x');
App\Core\Features::override(json_decode($argv[2], true));
$user = ['name' => 'U', 'role' => 'x', 'access' => json_decode($argv[3], true)];
$appName = 'ISP';
if ($argv[4] === 'nav') { $currentPath = '/dashboard'; require $argv[1] . '/backend/Views/layouts/sidebar.php'; } else { require $argv[1] . '/backend/Views/layouts/content_top.php'; }
PHP;
    return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($program) . ' ' . escapeshellarg($root) . ' ' . escapeshellarg(json_encode($flags)) . ' ' . escapeshellarg(json_encode($access)) . ' ' . $what . ' 2>&1');
};
$mgr = ['is_manager' => true];
$tech = ['can_search_clients' => true];
$both = $render(['map' => true, 'net' => true], $mgr, 'nav');
$check(str_contains($both, 'href="/x/mapa"') && str_contains($both, 'href="/x/rede-fibra"'), 'menu: gestor com tudo ligado ve Mapa e Rede');
$noMap = $render(['map' => false, 'net' => true], $mgr, 'nav');
$check(!str_contains($noMap, '/x/mapa') && str_contains($noMap, 'href="/x/rede-fibra"'), 'menu: mapa desligado oculta so o Mapa');
$noNet = $render(['map' => true, 'net' => false], $mgr, 'nav');
$check(str_contains($noNet, 'href="/x/mapa"') && !str_contains($noNet, 'rede-fibra') && !str_contains($noNet, 'rede-'), 'menu: rede desligada oculta a Rede (todas as abas)');
$techOn = $render(['map' => true, 'net' => true], $tech, 'nav');
$techOff = $render(['map' => true, 'net' => false], $tech, 'nav');
$check(str_contains($techOn, 'href="/x/rede-ctos"') && !str_contains($techOff, 'rede-ctos') && !str_contains($techOn, '/x/mapa'), 'menu: tecnico ve CTOs so com a rede ligada e nunca ve o Mapa');
$off = $render(['map' => false, 'net' => false], $mgr, 'nav');
$check(!str_contains($off, 'mapa') && !str_contains($off, 'rede-') && str_contains($off, 'href="/x/dashboard"') && str_contains($off, 'href="/x/clientes"'), 'menu: tudo desligado = menu sem mapa nem rede, o resto igual');
$top = $render(['map' => true, 'net' => true], $mgr, 'top');
$topOff = $render(['map' => true, 'net' => false], $mgr, 'top');
$check(str_contains($top, 'data-network-search="/x/api/rede-fibra/buscar"') && !str_contains($topOff, 'data-network-search') && str_contains($topOff, 'data-global-search'), 'busca global: sugestoes de rede so com a rede ligada; a busca de clientes continua');
$topTech = $render(['map' => true, 'net' => true], $tech, 'top');
$topTechOff = $render(['map' => true, 'net' => false], $tech, 'top');
$topNone = $render(['map' => true, 'net' => true], ['is_manager' => false], 'top');
$check(str_contains($topTech, 'data-network-search="/x/api/rede-fibra/buscar"') && !str_contains($topTechOff, 'data-network-search') && !str_contains($topNone, 'data-network-search'), 'busca global: tecnico (busca de clientes) recebe a busca de rede so leitura; sem permissao, nao');
echo "FeatureFlagsSmoke: {$n} verificacoes, zero falhas\n";
