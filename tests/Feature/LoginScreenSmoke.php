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

$render = static function (string $error, ?string $env) use ($root): string {
    $program = <<<'PHP'
require $argv[1] . '/backend/bootstrap/autoload.php';
if ($argv[3] !== '__ausente__') { $_ENV['APP_ENVIRONMENT'] = $argv[3]; }
App\Core\Url::setBasePath('/x');
session_start();
$view = new App\Core\View($argv[1] . '/backend/Views');
echo $view->render('auth/login', ['appName' => 'ISP Auxiliar', 'pageTitle' => 'Entrar', 'layoutMode' => 'guest', 'mkauthConfigured' => true, 'errorMessage' => $argv[2] !== '' ? $argv[2] : null]);
PHP;

    return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($program) . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($error) . ' ' . escapeshellarg($env ?? '__ausente__') . ' 2>&1');
};

$html = $render('', 'test');
$assert(str_contains($html, 'login__logo') && str_contains($html, '>IA<') && str_contains($html, 'ISP Auxiliar'), 'selo IA + ISP Auxiliar');
$assert(str_contains($html, 'name="username"') && str_contains($html, 'name="password"') && str_contains($html, 'action="/x/login"') && str_contains($html, 'method="post"'), 'campos e destino do login intactos');
$assert(substr_count($html, '<button') === 1, 'um único botão');
foreach (['Cadastro integrado', 'Sistema auxiliar', 'Operação ISP', 'Gestores', 'MkAuth'] as $intro) {
    $assert(!str_contains($html, $intro), "sem texto de apresentação: {$intro}");
}
$assert(!str_contains($html, 'class="topbar') && str_contains($html, 'AMBIENTE DE TESTES'), 'sem cabeçalho antigo; banner de testes continua no ambiente de teste');
$assert(!str_contains($render('', 'production'), 'AMBIENTE DE TESTES'), 'produção sem banner');
$err = $render('Senha invalida para este usuario.', 'test');
$assert(str_contains($err, 'alert--error') && str_contains($err, 'Senha invalida para este usuario.'), 'mensagem de erro aparece');

$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$assert(preg_match('/\.layout-guest \{[^}]*background: var\(--surface-page\)/s', $css) === 1 || preg_match('/\.layout-app,\s*\.layout-guest \{[^}]*background: var\(--surface-page\)/s', $css) === 1, 'fundo #f6f3ee');
$assert(str_contains($css, '--surface-page: #f6f3ee') && str_contains($css, '.login__card') && str_contains($css, 'max-width: 360px'), 'cartão do login');

echo "LoginScreenSmoke: {$checks} checks passed\n";
