<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$checks = 0;
$assert = static function (bool $ok, string $msg) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException($msg);
    }
    $checks++;
};

$render = static function (string $layoutMode, array $user, bool $hero = false) use ($root): string {
    $program = <<<'PHP'
require $argv[1] . '/backend/bootstrap/autoload.php';
App\Core\Url::setBasePath('/x');
session_start();
$_GET['q'] = 'joao"<b>';
$view = new App\Core\View($argv[1] . '/backend/Views');
echo $view->render('layouts/content_top', ['layoutMode' => $argv[2], 'homeHero' => $argv[4] === '1', 'user' => json_decode($argv[3], true), 'appName' => 'ISP']);
PHP;
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($program) . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($layoutMode) . ' ' . escapeshellarg(json_encode($user)) . ' ' . escapeshellarg($hero ? '1' : '0') . ' 2>&1');

    return (string) $out;
};

$gestor = $render('app', ['name' => 'G', 'role' => 'manager', 'access' => ['is_manager' => true]]);
$assert(str_contains($gestor, 'data-global-search') && str_contains($gestor, 'action="/x/clientes"') && str_contains($gestor, 'name="q"'), 'gestor vê a busca global apontando para /clientes?q=');
$assert(str_contains($gestor, 'Buscar cliente, login, CPF/CNPJ, contrato ou equipamento'), 'placeholder pedido');
$assert(!str_contains($gestor, 'joao"<b>') && str_contains($gestor, 'joao&quot;&lt;b&gt;'), 'termo atual é escapado');
$assert(str_contains($render('app', ['name' => 'X', 'role' => 'x']), 'data-global-search'), 'perfil desconhecido mantém a busca (a rota valida permissão)');
$assert(!str_contains($render('app', ['name' => 'S', 'role' => 'x', 'access' => ['can_search_clients' => false]]), 'data-global-search'), 'sem permissão de busca o campo some');
$assert(!str_contains($gestor, 'global-search--hero') && !str_contains($gestor, 'home-title'), 'páginas comuns usam a busca compacta, sem título da Home');
$assert(str_contains($gestor, 'Ctrl K'), 'indicador Ctrl K na busca compacta');
$assert(str_contains($gestor, 'mobile-brand') && str_contains($gestor, '>IA<'), 'cabeçalho mobile com selo IA');

$hero = $render('app', ['name' => 'G', 'role' => 'manager', 'access' => ['is_manager' => true]], true);
$assert(str_contains($hero, 'global-search--hero') && str_contains($hero, 'O que você precisa fazer agora?') && str_contains($hero, 'Ctrl K'), 'Home: busca grande, título e Ctrl K');
$assert(substr_count($hero, 'data-global-search') === 1, 'Home tem uma única busca');

// Sem barra superior no layout autenticado; o header.php é só das páginas públicas.
$headerSrc = (string) file_get_contents($root . '/backend/Views/layouts/header.php');
$assert(!str_contains($headerSrc, 'data-global-search') && !str_contains($headerSrc, 'topbar__user'), 'header.php não carrega mais busca nem usuário');
$app = (string) file_get_contents($root . '/backend/Views/layouts/app.php');
$assert(str_contains($app, "layoutMode === 'guest'") && str_contains($app, 'content_top.php'), 'layout: header só no guest, busca no topo do conteúdo');

$home = (string) file_get_contents($root . '/backend/Views/dashboard/index.php');
$assert(!str_contains($home, 'name="q"') && str_contains($home, '$homeHero = true') && !str_contains($home, 'Fluxo do dia'), 'Home usa a busca do layout e não tem mais Fluxo do dia');
$inst = (string) file_get_contents($root . '/backend/Views/installations/index.php');
$assert(str_contains($inst, 'Fluxo do dia'), 'Fluxo do dia mudou para /instalacoes');

$js = (string) file_get_contents($root . '/public/assets/js/app.js');
$assert(str_contains($js, "event.key.toLowerCase() === 'k'") && str_contains($js, 'data-global-search'), 'Ctrl K foca a busca');

echo "GlobalSearchHeaderSmoke: {$checks} checks passed\n";
