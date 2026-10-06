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

$render = static function (string $layoutMode) use ($root): string {
    $program = <<<'PHP'
require $argv[1] . '/backend/bootstrap/autoload.php';
App\Core\Url::setBasePath('/x');
session_start();
$view = new App\Core\View($argv[1] . '/backend/Views');
echo $view->render('layouts/app', ['layoutMode' => $argv[2], 'pageTitle' => 'T', 'content' => '<p>ok</p>', 'hideFooter' => true, 'currentPath' => '/clientes', 'user' => ['name' => 'Ana', 'role' => 'manager', 'access' => ['is_manager' => true]]]);
PHP;

    return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($program) . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($layoutMode) . ' 2>&1');
};

$app = $render('app');
$assert(str_contains($app, 'data-ai-panel') && str_contains($app, 'Assistente') && str_contains($app, 'em breve'), 'painel "Assistente — em breve" no layout autenticado');
$assert(str_contains($app, 'data-ai-search') && str_contains($app, 'action="/x/clientes"') && str_contains($app, 'name="q"'), 'painel tem a mesma busca (/clientes?q=)');
$assert(preg_match('#<button[^>]*class="app-nav__brand"[^>]*data-ai-open#', $app) === 1, 'selo IA da trilha é botão que abre o painel');
$assert(str_contains($app, 'class="ai-fab"') && str_contains($app, 'data-ai-close') && str_contains($app, 'data-ai-backdrop'), 'botão flutuante, X e fundo para clique fora');
$assert(preg_match('#<aside class="ai-panel"[^>]*\shidden>#', $app) === 1, 'painel começa fechado');
$assert(!str_contains($render('guest'), 'data-ai-panel'), 'páginas públicas não têm o painel');

$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$assert(preg_match('/\.ai-fab \{\s*display: none;/', $css) === 1, 'botão flutuante escondido no desktop');
$assert(preg_match('/@media \(max-width: 767px\) \{\s*\.ai-fab \{[^}]*right: 16px;[^}]*bottom: 92px;[^}]*width: 52px;/s', $css) === 1, 'mobile: botão flutuante 52px no canto inferior direito, acima da barra');
$assert(preg_match('/\.ai-panel \{\s*left: 0;\s*right: 0;/', $css) === 1, 'mobile: painel em tela cheia');

$js = (string) file_get_contents($root . '/public/assets/js/app.js');
$assert(str_contains($js, "event.key === 'Escape'") && str_contains($js, 'data-ai-backdrop') && str_contains($js, 'data-ai-close'), 'JS fecha por Esc, clique fora e X');
$assert(!preg_match('/fetch\(|XMLHttpRequest|openai|anthropic/i', (string) file_get_contents($root . '/backend/Views/layouts/ai_panel.php')), 'painel não faz chamada de IA');

echo "AiPanelSmoke: {$checks} checks passed\n";
