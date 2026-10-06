<?php

declare(strict_types=1);

use App\Core\Application;
use App\Core\Config;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';
$root = dirname(__DIR__, 2);
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) { throw new RuntimeException($message); }
    $checks++;
};

// Rota registrada, arquivo fora de public/ e sem cópia pública.
$router = new Router();
(require $root . '/backend/routes.php')($router);
$assert($router->match('GET', '/mapa') !== null, 'rota GET /mapa registrada');
$assert(is_file($root . '/modules/isp_map/isp-network-map.html'), 'mapa em modules/isp_map/');
$assert(glob($root . '/public/**/isp-network-map*') === [] && !is_file($root . '/public/isp-network-map.html'), 'mapa não fica em public/');

// Sem login: redireciona para /login e nunca entrega o HTML do mapa.
$_SESSION = [];
$app = new Application(new Config([]), new Container(), $router);
$status = new ReflectionProperty(Response::class, 'status');
$headers = new ReflectionProperty(Response::class, 'headers');
$body = new ReflectionProperty(Response::class, 'body');
$response = $app->handle(new Request('GET', '/mapa'));
$assert($status->getValue($response) === 302 && ($headers->getValue($response)['Location'] ?? '') === '/login', 'anônimo em /mapa é redirecionado ao login');
$assert(!str_contains((string) $body->getValue($response), 'ISP MAP'), 'anônimo não recebe o HTML do mapa');
foreach (['/modules/isp_map/isp-network-map.html', '/isp-network-map.html', '/assets/isp-network-map.html'] as $direct) {
    $assert($router->match('GET', $direct) === null, "URL direta {$direct} não é rota (o mapa só sai por /mapa)");
}

// Menu: item "Mapa" só para gestor/admin (trilha desktop e "Mais" no mobile).
$render = static function (array $access) use ($root): string {
    $program = <<<'PHP'
require $argv[1] . '/backend/bootstrap/autoload.php';
App\Core\Url::setBasePath('/x');
$user = ['name' => 'U', 'role' => 'x', 'access' => json_decode($argv[2], true)];
$currentPath = '/dashboard';
$appName = 'ISP';
require $argv[1] . '/backend/Views/layouts/sidebar.php';
PHP;

    return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($program) . ' ' . escapeshellarg($root) . ' ' . escapeshellarg(json_encode($access)) . ' 2>&1');
};
$gestor = $render(['is_manager' => true]);
$assert(str_contains($gestor, 'href="/x/mapa"') && substr_count($gestor, 'href="/x/mapa"') === 2, 'gestor: Mapa na trilha e no Mais');
$assert(str_contains($render(['is_admin' => true, 'can_search_clients' => true]), 'href="/x/mapa"'), 'admin vê o Mapa');
$assert(!str_contains($render(['can_search_clients' => true]), '/mapa'), 'técnico não vê o Mapa');

// Controller: bloqueia quem não é gestor/admin.
$controller = (string) file_get_contents($root . '/backend/Controllers/MapController.php');
$assert(str_contains($controller, 'AccessControl::isGestor($access)'), 'controller exige gestor/admin');

echo "MapRouteSmoke: {$checks} checks passed\n";
