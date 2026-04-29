<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Roteador simples baseado em metodo HTTP e caminho exato.
 *
 * Para esta etapa ele cobre bem o necessario e mantem as URLs amigaveis sem
 * exigir framework.
 */
final class Router
{
    private array $routes = [];

    public function add(string $method, string $path, mixed $action, ?string $name = null): void
    {
        $normalizedMethod = strtoupper($method);
        $normalizedPath = $this->normalizePath($path);

        $this->routes[] = [
            'method' => $normalizedMethod,
            'path' => $normalizedPath,
            'action' => $action,
            'name' => $name,
        ];
    }

    public function get(string $path, mixed $action, ?string $name = null): void
    {
        $this->add('GET', $path, $action, $name);
    }

    public function post(string $path, mixed $action, ?string $name = null): void
    {
        $this->add('POST', $path, $action, $name);
    }

    public function match(string $method, string $path): ?array
    {
        $normalizedMethod = strtoupper($method);
        $normalizedPath = $this->normalizePath($path);

        foreach ($this->routes as $route) {
            $methodMatches = $route['method'] === $normalizedMethod;

            // HEAD pode reutilizar rotas GET para facilitar testes e proxies.
            if ($normalizedMethod === 'HEAD' && $route['method'] === 'GET') {
                $methodMatches = true;
            }

            if ($methodMatches && $route['path'] === $normalizedPath) {
                return $route;
            }
        }

        return null;
    }

    public function all(): array
    {
        return $this->routes;
    }

    private function normalizePath(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        $normalized = '/' . trim($path, '/');

        return $normalized === '//' ? '/' : $normalized;
    }
}
