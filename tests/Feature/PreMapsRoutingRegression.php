<?php

declare(strict_types=1);

use App\Core\Application;
use App\Core\Config;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) { throw new RuntimeException($message); }
    $checks++;
};
$router = new Router();
(require dirname(__DIR__, 2) . '/backend/routes.php')($router);
$keys = [];
foreach ($router->all() as $route) {
    $key = $route['method'] . ' ' . $route['path'];
    $assert(!isset($keys[$key]), 'Rota duplicada: ' . $key);
    $keys[$key] = true;
    $action = $route['action'];
    $assert(is_array($action) && method_exists($action[0], $action[1]), 'Controller/ação inexistente: ' . $key);
}
foreach (['backend/routes/api.php', 'backend/routes/web.php'] as $dead) {
    $assert(!file_exists(dirname(__DIR__, 2) . '/' . $dead), 'Arquivo de rotas obsoleto restaurado.');
}
$_SESSION = [];
$app = new Application(new Config([]), new Container(), $router);
$status = new ReflectionProperty(Response::class, 'status');
$headers = new ReflectionProperty(Response::class, 'headers');
foreach ([['GET', '/processos/migracao'], ['POST', '/clientes/migracao/aceite-preparar'], ['POST', '/clientes/contrato/solicitar']] as [$method, $path]) {
    $response = $app->handle(new Request($method, $path));
    $assert($status->getValue($response) === 302 && ($headers->getValue($response)['Location'] ?? '') === '/login', 'Endpoint operacional permitiu usuário anônimo.');
}
$scope = 'operational_process:123';
$assert(!Csrf::verify(new Request('POST', '/processos/etapa'), $scope), 'CSRF ausente aceito.');
$token = Csrf::token($scope);
$request = new Request('POST', '/processos/etapa', '', [], ['_csrf' => $token]);
$assert(Csrf::verify($request, $scope), 'CSRF válido recusado.');
$assert(!Csrf::verify($request, 'operational_process:456'), 'CSRF de outro processo aceito.');
$assert(!Csrf::verify(new Request('POST', '/processos/etapa', '', [], ['_csrf' => 'invalid']), $scope), 'CSRF inválido aceito.');
echo "PreMapsRoutingRegression: {$checks} checks passed; sem bootstrap, banco ou rede.\n";
