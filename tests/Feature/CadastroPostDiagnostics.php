<?php
declare(strict_types=1);

use App\Core\{Application, Config, Container, Csrf, Request, Response, Router, Url};

require dirname(__DIR__, 2) . '/backend/bootstrap/autoload.php';

$assert = static function (bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
};
$log = tempnam(dirname(__DIR__, 2) . '/tmp', 'cadastro-test-');
$originalLog = ini_get('error_log');
$originalPost = $_POST;
$originalSession = $_SESSION ?? [];
ini_set('error_log', $log);
try {
    $router = new Router();
    $calls = 0;
    foreach (['/clientes/novo', '/clientes/novo/aceite', '/outra'] as $path) {
        $router->post($path, static function () use (&$calls): Response {
            $calls++;
            return Response::html('controller');
        });
    }
    $app = new Application(new Config([]), new Container(), $router);
    Url::setBasePath('');
    $limit = ini_parse_quantity((string) ini_get('post_max_size'));
    $assert($limit > 0, 'Execute com post_max_size positivo.');
    foreach (['/clientes/novo', '/clientes/novo/aceite'] as $path) {
        $_POST = [];
        $_SESSION = ['user' => ['login' => 'not-to-be-logged']];
        $request = new Request('POST', $path, '', [], [], [], ['CONTENT_LENGTH' => $limit + 1]);
        $response = $app->handle($request);
        $assert((new ReflectionProperty($response, 'status'))->getValue($response) === 413, 'Deve rejeitar excesso antes do controller.');
        $assert(str_contains((new ReflectionProperty($response, 'body'))->getValue($response), 'Envio maior que o limite'), 'Mensagem específica de tamanho.');
        $_SESSION = [];
        $response = $app->handle(new Request('POST', $path));
        $headers = (new ReflectionProperty($response, 'headers'))->getValue($response);
        $assert($headers['Location'] === '/login?error=cadastro-auth', 'Sem login deve redirecionar com motivo.');
        $_SESSION = ['user' => ['login' => 'not-to-be-logged']];
        $_POST = ['_csrf' => 'not-to-be-logged'];
        $app->handle(new Request('POST', $path, '', [], $_POST, [], ['CONTENT_LENGTH' => $limit + 1]));
        $_POST = [];
        $app->handle(new Request('POST', $path, '', [], [], [], ['CONTENT_LENGTH' => $limit]));
    }
    $assert($calls === 4, 'POST preenchido ou no limite não pode ser classificado como descartado.');
    $_SESSION = [];
    $token = Csrf::token('client_create');
    $assert(Csrf::verify(new Request('POST', '/clientes/novo', '', [], ['_csrf' => $token]), 'client_create'), 'CSRF atual deve funcionar.');
    $assert(!Csrf::verify(new Request('POST', '/clientes/novo', '', [], ['_csrf' => 'old-token']), 'client_create'), 'CSRF antigo deve falhar.');
    $text = file_get_contents($log);
    $assert(str_contains($text, 'case=post_too_large') && str_contains($text, 'case=unauthenticated'), 'Casos devem ser registrados.');
    $assert(!str_contains($text, 'not-to-be-logged') && !str_contains($text, $token), 'Log não pode conter identidade nem token.');
    echo "OK: ambas as rotas, tamanho, autenticação, CSRF e logs sem dados pessoais; sem banco/rede.\n";
} finally {
    ini_set('error_log', $originalLog);
    $_POST = $originalPost;
    $_SESSION = $originalSession;
    unlink($log);
}
