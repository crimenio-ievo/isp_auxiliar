<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Helper central para montar URLs respeitando o subdiretorio atual.
 */
final class Url
{
    private static string $basePath = '';
    private static string $applicationUrl = '';

    public static function setBasePath(string $basePath): void
    {
        self::$basePath = rtrim($basePath, '/');
    }

    public static function basePath(): string
    {
        return self::$basePath;
    }

    public static function setApplicationUrl(string $url): void
    {
        self::$applicationUrl = rtrim(trim($url), '/');
    }

    public static function applicationUrl(): string
    {
        return self::$applicationUrl;
    }

    public static function to(string $path = '/'): string
    {
        $normalizedPath = '/' . ltrim($path, '/');

        if ($normalizedPath === '/') {
            return self::$basePath === '' ? '/' : self::$basePath . '/';
        }

        return self::$basePath . $normalizedPath;
    }

    public static function asset(string $path): string
    {
        return self::to('/assets/' . ltrim($path, '/'));
    }

    public static function absolute(string $path = '/'): string
    {
        $baseUrl = self::$applicationUrl !== '' ? self::$applicationUrl : 'http://localhost';
        $basePath = trim(self::$basePath, '/');

        if ($basePath !== '') {
            $parsedPath = (string) (parse_url($baseUrl, PHP_URL_PATH) ?? '');
            $normalizedBasePath = '/' . $basePath;

            if ($parsedPath === '' || !str_ends_with(rtrim($parsedPath, '/'), $normalizedBasePath)) {
                $baseUrl = rtrim($baseUrl, '/') . $normalizedBasePath;
            }
        }

        $normalizedPath = '/' . ltrim($path, '/');

        return rtrim($baseUrl, '/') . $normalizedPath;
    }

    public static function isLocalUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return true;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return true;
        }

        $host = strtolower($host);

        return in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true);
    }

    public static function resolveOperationalBaseUrl(string $appUrl, ?string $providerDomain = null, ?string $providerBasePath = null): array
    {
        $appUrl = trim($appUrl);
        $providerDomain = trim((string) $providerDomain);
        $providerBasePath = trim((string) $providerBasePath);

        if ($appUrl !== '' && !self::isLocalUrl($appUrl)) {
            return [
                'url' => self::normalizeAbsoluteUrl($appUrl),
                'source' => 'app.url',
                'is_local' => false,
                'is_ip' => self::isIpUrl($appUrl),
            ];
        }

        if ($providerDomain !== '') {
            $domain = preg_match('#^https?://#i', $providerDomain) === 1
                ? $providerDomain
                : 'https://' . $providerDomain;
            $path = self::normalizeBasePath($providerBasePath);
            $domain = self::normalizeAbsoluteUrl($domain);

            if (!self::isLocalUrl($domain)) {
                return [
                    'url' => rtrim($domain . $path, '/'),
                    'source' => 'provider.domain',
                    'is_local' => false,
                    'is_ip' => self::isIpUrl($domain),
                ];
            }
        }

        $fallback = $appUrl !== '' ? $appUrl : 'http://localhost:8000';

        return [
            'url' => self::normalizeAbsoluteUrl($fallback),
            'source' => $appUrl !== '' ? 'app.url.fallback' : 'internal',
            'is_local' => self::isLocalUrl($fallback),
            'is_ip' => self::isIpUrl($fallback),
        ];
    }

    private static function normalizeBasePath(string $basePath): string
    {
        $basePath = trim($basePath);

        if ($basePath === '') {
            return '';
        }

        return '/' . trim($basePath, '/');
    }

    private static function normalizeAbsoluteUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return 'http://localhost:8000';
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return rtrim($url, '/');
        }

        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) $parts['host'];
        $port = $parts['port'] ?? null;
        $path = (string) ($parts['path'] ?? '');

        if ($port !== null && (int) $port === 8000) {
            $port = null;
        }

        $normalized = $scheme . '://' . $host;
        if ($port !== null && !in_array((int) $port, [80, 443], true)) {
            $normalized .= ':' . (int) $port;
        }

        return rtrim($normalized . $path, '/');
    }

    private static function isIpUrl(string $url): bool
    {
        $host = parse_url(trim($url), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }
}
