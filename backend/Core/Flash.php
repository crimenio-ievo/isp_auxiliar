<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Flash messages simples para feedback entre requests.
 */
final class Flash
{
    private const KEY = '_flash_messages';

    public static function set(string $type, string $message): void
    {
        $_SESSION[self::KEY] = [
            'type' => $type,
            'message' => $message,
        ];
    }

    public static function get(): ?array
    {
        if (!isset($_SESSION[self::KEY]) || !is_array($_SESSION[self::KEY])) {
            return null;
        }

        $flash = $_SESSION[self::KEY];
        unset($_SESSION[self::KEY]);

        return $flash;
    }
}
