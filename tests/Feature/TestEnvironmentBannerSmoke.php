<?php

declare(strict_types=1);

use App\Core\Environment;

require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';

$root = dirname(__DIR__, 2);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

// Regra: só "production" esconde; ausente/vazio/qualquer outro valor mostra.
foreach ([null, '', '   ', 'test', 'staging', 'homologation', 'prod', 'productio', 'produção'] as $value) {
    $assert(Environment::showTestBanner($value), 'Valor ' . var_export($value, true) . ' deveria mostrar o banner.');
}
foreach (['production', 'PRODUCTION', '  Production  '] as $value) {
    $assert(!Environment::showTestBanner($value), 'Valor ' . var_export($value, true) . ' deveria esconder o banner.');
}

// Renderização real do layout (processo isolado, variável injetada via $_ENV).
$render = static function (?string $value) use ($root): string {
    $program = <<<'PHP'
require $argv[1] . '/backend/bootstrap/autoload.php';
if ($argv[2] !== '__ausente__') { $_ENV['APP_ENVIRONMENT'] = $argv[2]; }
App\Core\Url::setBasePath('/x');
session_start();
$view = new App\Core\View($argv[1] . '/backend/Views');
echo $view->render('layouts/app', ['layoutMode' => 'guest', 'pageTitle' => 'T', 'content' => '<p>ok</p>', 'hideFooter' => true, 'user' => ['name' => 'x', 'role' => 'manager']]);
PHP;
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($program) . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($value ?? '__ausente__') . ' 2>&1');
    if (!is_string($out)) {
        throw new RuntimeException('Falha ao renderizar o layout.');
    }

    return $out;
};

$banner = 'AMBIENTE DE TESTES — NÃO É PRODUÇÃO';
foreach ([null, 'test', 'staging', ''] as $value) {
    $html = $render($value);
    $assert(str_contains($html, $banner) && str_contains($html, 'has-test-banner'), 'Layout sem produção deveria exibir o banner (' . var_export($value, true) . ').');
}
$prod = $render('production');
$assert(!str_contains($prod, $banner) && !str_contains($prod, 'test-environment-banner') && str_contains($prod, 'no-test-banner'), 'Layout com APP_ENVIRONMENT=production não deveria exibir o banner.');
$assert(str_contains($prod, '<p>ok</p>'), 'Layout de produção deixou de renderizar o conteúdo.');

// CSS: banner fixo na pilha sticky do topo.
$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$assert(str_contains($css, '.app-topbar-stack {') && preg_match('/\.app-topbar-stack \{[^}]*position: sticky;[^}]*top: 0;/s', $css) === 1, 'Pilha do topo deixou de ser sticky.');
$assert(str_contains($css, '.no-test-banner { --topbar-stack-height: var(--topbar-stack-height-base); }'), 'Altura da pilha sem banner não foi ajustada.');

echo "TestEnvironmentBannerSmoke: {$checks} checks passed\n";
