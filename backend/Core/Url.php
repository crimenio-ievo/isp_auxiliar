<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Helper central para montar URLs respeitando o subdiretorio atual.
 */
final class Url
{
    private static string $basePath = '';

    public static function setBasePath(string $basePath): void
    {
        self::$basePath = rtrim($basePath, '/');
    }

    public static function basePath(): string
    {
        return self::$basePath;
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
}
