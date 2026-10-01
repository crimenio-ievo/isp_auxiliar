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

$render = static function (string $layoutMode, array $user) use ($root): string {
    $program = <<<'PHP'
require $argv[1] . '/backend/bootstrap/autoload.php';
App\Core\Url::setBasePath('/x');
session_start();
$_GET['q'] = 'joao"<b>';
$view = new App\Core\View($argv[1] . '/backend/Views');
echo $view->render('layouts/header', ['layoutMode' => $argv[2], 'user' => json_decode($argv[3], true), 'appName' => 'ISP']);
PHP;
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($program) . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($layoutMode) . ' ' . escapeshellarg(json_encode($user)) . ' 2>&1');

    return (string) $out;
};

$gestor = $render('app', ['name' => 'G', 'role' => 'manager', 'access' => ['is_manager' => true]]);
$assert(str_contains($gestor, 'data-global-search') && str_contains($gestor, 'action="/x/clientes"') && str_contains($gestor, 'name="q"'), 'gestor vê a busca global apontando para /clientes?q=');
$assert(str_contains($gestor, 'Buscar cliente, login, CPF/CNPJ, contrato ou equipamento'), 'placeholder pedido');
$assert(!str_contains($gestor, 'joao"<b>') && str_contains($gestor, 'joao&quot;&lt;b&gt;'), 'termo atual é escapado');
$assert(str_contains($render('app', ['name' => 'X', 'role' => 'x']), 'data-global-search'), 'perfil desconhecido mantém a busca (a rota valida permissão)');
$assert(!str_contains($render('app', ['name' => 'S', 'role' => 'x', 'access' => ['can_search_clients' => false]]), 'data-global-search'), 'sem permissão de busca o campo some');
$assert(!str_contains($render('guest', ['name' => 'C']), 'data-global-search'), 'páginas públicas (guest) não têm busca');

$home = (string) file_get_contents($root . '/backend/Views/dashboard/index.php');
$assert(!str_contains($home, 'home-search') && !str_contains($home, 'name="q"'), 'Home não duplica a busca');

echo "GlobalSearchHeaderSmoke: {$checks} checks passed\n";
