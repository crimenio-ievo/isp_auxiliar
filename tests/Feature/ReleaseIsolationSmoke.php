<?php

declare(strict_types=1);

use App\Controllers\SystemController;
use App\Core\Config;
use App\Core\Env;
use App\Core\Request;
use App\Core\View;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\MkAuth\MkAuthDatabase;
use App\Infrastructure\MkAuth\MkAuthWriteGuard;
use App\Services\Releases\ReleaseChannelService;

require dirname(__DIR__, 2) . '/backend/bootstrap/app.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$root = dirname(__DIR__, 2);
$app = bootstrapApplication();
$database = new Database($app->config());
$local = new LocalRepository($database, (string) Env::get('APP_PROVIDER_KEY', 'default'));
$view = new View((string) $app->config()->get('paths.views'));
$controller = new SystemController(
    $view,
    $app->config(),
    new MkAuthDatabase('', '3306', '', '', '', 'utf8mb4', 'sha256', new MkAuthWriteGuard('test', false)),
    $local,
    $database
);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$responseData = static function (object $response): array {
    $reflection = new ReflectionClass($response);
    $status = $reflection->getProperty('status');
    $body = $reflection->getProperty('body');
    $status->setAccessible(true);
    $body->setAccessible(true);

    return [(int) $status->getValue($response), (string) $body->getValue($response)];
};

$_SESSION['user'] = ['login' => 'release.manager.test', 'name' => 'Gestor', 'role' => 'manager'];
[$managerStatus] = $responseData($controller->release(new Request('GET', '/api/release')));
$assert($managerStatus === 403, 'Endpoint de release aceitou gestor sem perfil administrador.');

$_SESSION['user'] = ['login' => 'release.admin.test', 'name' => 'Admin', 'role' => 'admin'];
[$adminStatus, $adminBody] = $responseData($controller->release(new Request('GET', '/api/release')));
$release = json_decode($adminBody, true);
$assert($adminStatus === 200 && is_array($release), 'Administrador não recebeu release info.');
foreach (['channel', 'release_id', 'commit', 'build_date', 'schema_version', 'external_writes_enabled', 'notification_dry_run', 'ticket_dry_run'] as $field) {
    $assert(array_key_exists($field, $release), 'Campo de release ausente: ' . $field);
}
$assert(!preg_match('/smtp_password|api_token|client_secret|db_password/i', $adminBody), 'Release info expôs campo de credencial.');

$safeConfig = new Config(['app' => ['release' => [
    'channel' => 'beta',
    'stable_base_url' => 'https://stable.example.test/app',
    'beta_base_url' => 'https://beta.example.test/app',
]]]);
$safeChannels = new ReleaseChannelService($safeConfig, $local);
$assert($safeChannels->destination('stable') === 'https://stable.example.test/app', 'Destino Stable configurado foi alterado.');
$assert($safeChannels->destination('beta') === 'https://beta.example.test/app', 'Destino Beta configurado foi alterado.');
$switch = $safeChannels->resolveSwitch(['login' => 'release.admin.test', 'role' => 'admin'], 'beta');
$assert(empty($switch['redirect']) && ($switch['destination'] ?? '') === '', 'Seleção do próprio canal provocaria recarga.');
$switch = $safeChannels->resolveSwitch(['login' => 'release.admin.test', 'role' => 'admin'], 'stable');
$assert(!empty($switch['redirect']) && ($switch['destination'] ?? '') === 'https://stable.example.test/app', 'Troca não resolveu o destino configurado.');
$unsafeChannels = new ReleaseChannelService(new Config(['app' => ['release' => [
    'stable_base_url' => 'https://usuario:senha@host.example.test/app',
]]]), $local);
$assert($unsafeChannels->destination('stable') === '', 'URL com credencial foi aceita pelo seletor.');

$footerPath = var_export($root . '/backend/Views/layouts/footer.php', true);
$guestFooter = (string) shell_exec(PHP_BINARY . ' -r ' . escapeshellarg('$layoutMode="guest"; define("APP_RELEASE_INFO", ["channel"=>"beta","id"=>"secret-release","commit"=>"abcdef123"]); ob_start(); require ' . $footerPath . '; echo ob_get_clean();'));
$assert($guestFooter === '', 'Página pública exibiu identificação de release.');
$layoutPath = var_export($root . '/backend/Views/layouts/app.php', true);
$autoloadPath = var_export($root . '/backend/bootstrap/autoload.php', true);
$guestLayout = (string) shell_exec(PHP_BINARY . ' -r ' . escapeshellarg('require ' . $autoloadPath . '; $layoutMode="guest"; $hideHeader=true; $hideFooter=true; $pageTitle="Pública"; $appName="ISP Auxiliar"; $content="ok"; define("APP_RELEASE_INFO", ["channel"=>"beta","id"=>"secret-release","commit"=>"abcdef123"]); require ' . $layoutPath . ';'));
$assert(!str_contains($guestLayout, 'secret-release') && !str_contains($guestLayout, 'abcdef123'), 'Página pública expôs release no cache busting dos assets.');

$publicIndex = (string) file_get_contents($root . '/public/index.php');
$assert(strpos($publicIndex, 'bootstrapApplication()') < strpos($publicIndex, 'session_start()'), 'Sessão abriu antes da configuração da release.');
$assert(str_contains($publicIndex, 'session_name(') && str_contains($publicIndex, 'session_save_path('), 'Nome e path da sessão não foram isolados.');
$betaEnv = (string) file_get_contents($root . '/.env.beta.example');
$assert(str_contains($betaEnv, 'SESSION_COOKIE_NAME=isp_auxiliar_beta_session')
    && str_contains($betaEnv, 'DB_DATABASE=isp_auxiliar_beta'), 'Template Beta não separou cookie e banco.');
$assert(str_contains($betaEnv, 'MKAUTH_WRITE_ENABLED=false')
    && str_contains($betaEnv, 'EVOTRIX_DRY_RUN=true')
    && str_contains($betaEnv, 'EMAIL_DRY_RUN=true')
    && str_contains($betaEnv, 'MKAUTH_TICKET_DRY_RUN=true'), 'Template Beta não bloqueou integrações reais.');

echo 'ReleaseIsolationSmoke OK - ' . $checks . " verificações; nenhuma mutação externa.\n";
