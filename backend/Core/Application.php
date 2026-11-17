<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\SystemController;
use InvalidArgumentException;

/**
 * Coordenador principal da requisicao HTTP.
 *
 * Ele encontra a rota, resolve o controller no container e transforma o
 * retorno em uma resposta HTTP padronizada.
 */
final class Application
{
    public function __construct(
        private Config $config,
        private Container $container,
        private Router $router
    ) {
    }

    public function handle(Request $request): Response
    {
        $route = $this->router->match($request->method(), $request->path());

        if ($route === null) {
            return $this->notFound($request);
        }

        $registrationPost = $request->method() === 'POST'
            && in_array($request->path(), ['/clientes/novo', '/clientes/novo/aceite'], true);
        $postLimit = ini_parse_quantity((string) ini_get('post_max_size'));
        $contentLength = max(0, (int) $request->server('CONTENT_LENGTH', 0));
        $postTooLarge = $registrationPost && empty($_POST)
            && $postLimit > 0 && $contentLength > $postLimit;
        if ($postTooLarge) {
            error_log(sprintf('[cadastro.post] case=post_too_large route=%s bytes=%d limit=%d',
                $request->path(), $contentLength, $postLimit));
        }

        if (!$this->isPublicRoute($request) && empty($_SESSION['user'])) {
            if ($registrationPost) {
                error_log('[cadastro.post] case=unauthenticated route=' . $request->path());
                return Response::redirect('/login?error=cadastro-auth');
            }
            return Response::redirect('/login');
        }

        if ($postTooLarge) {
            // O PHP descartou também draft_id e CSRF: não tente interpretar o POST.
            return Response::html('<!doctype html><html lang="pt-BR"><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<title>Envio maior que o limite</title><h1>Envio maior que o limite</h1>'
                . '<p>O envio ultrapassou o limite do servidor e não foi processado. '
                . 'Volte pelo navegador e reduza o tamanho ou a quantidade de fotos antes de tentar novamente.</p>'
                . '</html>', 413);
        }

        $result = $this->dispatch($route['action'], $request->withRouteParams(is_array($route['params'] ?? null) ? $route['params'] : []));

        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result)) {
            return Response::json($result);
        }

        return Response::html((string) $result);
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function router(): Router
    {
        return $this->router;
    }

    private function dispatch(mixed $action, Request $request): mixed
    {
        if (is_callable($action)) {
            return $action($request, $this);
        }

        if (is_array($action) && count($action) === 2 && is_string($action[0]) && is_string($action[1])) {
            [$controllerClass, $method] = $action;
            $controller = $this->container->get($controllerClass);

            return $controller->{$method}($request);
        }

        throw new InvalidArgumentException('Acao de rota invalida.');
    }

    private function isPublicRoute(Request $request): bool
    {
        $publicPaths = [
            '/',
            '/login',
            '/logout',
            '/api/health',
            '/api/usuario/validar',
            '/clientes/evidencias',
            '/clientes/evidencias/arquivo',
        ];

        if (in_array($request->path(), $publicPaths, true)) {
            return true;
        }

        return (bool) preg_match('#^/aceite/[^/]+(?:/(?:confirmar|termo))?$#', $request->path());
    }

    /**
     * Centraliza o fallback de 404 para manter a experiencia consistente.
     */
    private function notFound(Request $request): Response
    {
        /** @var SystemController $controller */
        $controller = $this->container->get(SystemController::class);

        return $controller->notFound($request);
    }
}
