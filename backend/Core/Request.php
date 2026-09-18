<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * Encapsula os dados principais da requisicao HTTP.
 */
final class Request
{
    public function __construct(
        private string $method,
        private string $path,
        private string $basePath = '',
        private array $queryParams = [],
        private array $bodyParams = [],
        private array $headers = [],
        private array $server = [],
        private array $routeParams = []
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $configuredBasePath = Env::get('APP_BASE_PATH');
        $basePath = $configuredBasePath === null
            ? self::detectBasePath($scriptName)
            : self::normalizeConfiguredBasePath($configuredBasePath);
        $normalizedPath = self::stripBasePath($path, $basePath);
        if ($normalizedPath === '/index.php') {
            $normalizedPath = '/';
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $normalizedPath === '' ? '/' : $normalizedPath,
            $basePath,
            $_GET,
            $_POST,
            self::extractHeaders(),
            $_SERVER
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function url(string $path = '/'): string
    {
        $normalizedPath = '/' . ltrim($path, '/');
        $basePath = rtrim($this->basePath, '/');

        if ($normalizedPath === '/') {
            return $basePath === '' ? '/' : $basePath . '/';
        }

        return $basePath . $normalizedPath;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->bodyParams[$key] ?? $default;
    }

    public function header(string $key, mixed $default = null): mixed
    {
        $normalizedKey = strtolower($key);

        foreach ($this->headers as $header => $value) {
            if (strtolower($header) === $normalizedKey) {
                return $value;
            }
        }

        return $default;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    public function route(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function withRouteParams(array $routeParams): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->basePath,
            $this->queryParams,
            $this->bodyParams,
            $this->headers,
            $this->server,
            $routeParams
        );
    }

    private static function extractHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();

            return is_array($headers) ? $headers : [];
        }

        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (!str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $name = str_replace('_', '-', substr($key, 5));
            $headers[$name] = $value;
        }

        return $headers;
    }

    private static function detectBasePath(string $scriptName): string
    {
        $directory = str_replace('\\', '/', dirname($scriptName));

        if ($directory === '/' || $directory === '.') {
            return '';
        }

        return rtrim($directory, '/');
    }

    private static function normalizeConfiguredBasePath(string $basePath): string
    {
        $basePath = trim($basePath);

        if ($basePath === '' || $basePath === '/') {
            return '';
        }

        if (
            !str_starts_with($basePath, '/') ||
            str_starts_with($basePath, '//') ||
            str_contains($basePath, '://') ||
            str_contains($basePath, '?') ||
            str_contains($basePath, '#')
        ) {
            throw new InvalidArgumentException('APP_BASE_PATH deve conter somente um caminho público absoluto.');
        }

        $basePath = preg_replace('#/+#', '/', $basePath) ?? '';
        $segments = explode('/', trim($basePath, '/'));

        foreach ($segments as $segment) {
            if (rawurldecode($segment) === '..') {
                throw new InvalidArgumentException('APP_BASE_PATH não pode conter segmentos de travessia.');
            }
        }

        return rtrim($basePath, '/');
    }

    private static function stripBasePath(string $path, string $basePath): string
    {
        if ($basePath !== '' && str_starts_with($path, $basePath . '/')) {
            return substr($path, strlen($basePath));
        }

        if ($basePath !== '' && $path === $basePath) {
            return '/';
        }

        return $path;
    }
}
