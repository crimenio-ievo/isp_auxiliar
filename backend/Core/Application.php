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

        if (!$this->isPublicRoute($request) && empty($_SESSION['user'])) {
            return Response::redirect('/login');
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
