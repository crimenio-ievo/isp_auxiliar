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

$render = static function (array $access, string $path = '/dashboard') use ($root): string {
    $program = <<<'PHP'
require $argv[1] . '/backend/bootstrap/autoload.php';
App\Core\Url::setBasePath('/x');
$user = ['name' => 'U', 'role' => 'x', 'access' => json_decode($argv[3], true)];
$currentPath = $argv[2];
$appName = 'ISP';
require $argv[1] . '/backend/Views/layouts/sidebar.php';
PHP;

    return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($program) . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($path) . ' ' . escapeshellarg(json_encode($access)) . ' 2>&1');
};
$labels = static function (string $html, string $class): array {
    preg_match_all('#<a\s[^>]*class="' . $class . '[^"]*"[^>]*>.*?<span class="app-nav__link-label">([^<]+)</span>#s', $html, $m);

    return $m[1];
};

// Gestor: 4 fixos no mobile, Mais com Configurações e Logs.
$gestor = $render(['is_manager' => true]);
preg_match_all('#<a\s+class="app-nav__link(?![^"]*app-nav__link--mobile-hidden)[^"]*"[^>]*>.*?<span class="app-nav__link-label">([^<]+)</span>#s', $gestor, $fixed);
$assert($fixed[1] === ['Home', 'Clientes', 'Contratos', 'Processos'], 'fixos do gestor: ' . implode(',', $fixed[1]));
preg_match('#data-nav-more-panel.*?</div>#s', $gestor, $panel);
$assert(str_contains($panel[0], '>Configurações<') && str_contains($panel[0], '>Logs<') && substr_count($panel[0], 'app-nav__more-item"') + substr_count($panel[0], 'app-nav__more-item is-active') === 2, 'painel Mais traz Configurações e Logs');
$assert(str_contains($gestor, 'data-nav-more-toggle') && str_contains($gestor, 'aria-expanded="false"') && str_contains($gestor, 'aria-controls="app-nav-more-panel"'), 'botão Mais acessível');
$assert(count($fixed[1]) <= 4, 'no máximo 4 fixos');

// Perfil enxuto: sem contratos/config/logs não há o que colocar em Mais.
$basic = $render(['can_search_clients' => true]);
$assert(!str_contains($basic, 'data-nav-more-toggle') && !str_contains($basic, 'data-nav-more-panel'), 'sem itens extras não mostra Mais');

// Página dentro do Mais marca o botão como ativo.
$onLogs = $render(['is_manager' => true], '/logs');
$assert(preg_match('#class="app-nav__link app-nav__more is-active"#', $onLogs) === 1, 'Mais fica ativo em /logs');

// Desktop inalterado: a trilha continua listando todos (mobile-hidden é só classe, escondida em ≤767px).
$assert(substr_count($gestor, '<a        class="app-nav__link') + substr_count($gestor, 'class="app-nav__link') >= 6, 'trilha desktop mantém todos os itens');

$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$assert(preg_match('/\.app-nav__more,\s*\.app-nav__more-panel \{\s*display: none;/', $css) === 1, 'Mais é escondido no desktop');
$assert(preg_match('/@media \(max-width: 767px\).*\.app-nav__link--mobile-hidden \{\s*display: none;/s', $css) === 1, 'itens extras escondidos só no mobile');
$js = (string) file_get_contents($root . '/public/assets/js/app.js');
$assert(str_contains($js, 'data-nav-more-toggle') && str_contains($js, "event.key === 'Escape'"), 'JS abre/fecha o painel e fecha com Esc');

echo "MobileNavMoreSmoke: {$checks} checks passed\n";
