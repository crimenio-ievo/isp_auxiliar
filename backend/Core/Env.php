<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Leitor simples de variaveis de ambiente via arquivo `.env`.
 */
final class Env
{
    private static array $loaded = [];

    public static function load(string $filePath): void
    {
        if (!is_file($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $trimmed, 2), 2, '');

            $name = trim($name);
            $value = trim($value);

            if ($name === '') {
                continue;
            }

            $value = self::normalizeValue($value);
            $processValue = getenv($name);
            if ($processValue !== false) {
                // Variáveis injetadas pelo serviço/deploy têm precedência sobre
                // o arquivo local, permitindo isolamento sem reescrever .env.
                $value = (string) $processValue;
            }

            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            self::$loaded[$name] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return $_ENV[$key] ?? $_SERVER[$key] ?? self::$loaded[$key] ?? $default;
    }

    /**
     * Somente os NOMES das variaveis carregadas do .env que comecam com o prefixo (nunca os valores).
     *
     * @return list<string>
     */
    public static function names(string $prefix): array
    {
        $out = array_values(array_filter(
            array_map('strval', array_keys(self::$loaded)),
            static fn (string $name): bool => str_starts_with($name, $prefix)
        ));
        sort($out);

        return $out;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    private static function normalizeValue(string $value): string
    {
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
