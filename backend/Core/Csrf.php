<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Token de sessão para as novas mutações operacionais e para o aceite público.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_tokens';

    public static function token(string $scope = 'default'): string
    {
        $scope = self::normalizeScope($scope);
        $tokens = is_array($_SESSION[self::SESSION_KEY] ?? null) ? $_SESSION[self::SESSION_KEY] : [];
        $token = trim((string) ($tokens[$scope] ?? ''));
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            $tokens[$scope] = $token;
            $_SESSION[self::SESSION_KEY] = $tokens;
        }

        return $token;
    }

    public static function verify(Request $request, string $scope = 'default'): bool
    {
        $expected = self::token($scope);
        $provided = trim((string) $request->input('_csrf', $request->header('X-CSRF-Token', '')));

        return $provided !== '' && hash_equals($expected, $provided);
    }

    public static function field(string $scope = 'default'): string
    {
        return '<input type="hidden" name="_csrf" value="'
            . htmlspecialchars(self::token($scope), ENT_QUOTES, 'UTF-8')
            . '">';
    }

    private static function normalizeScope(string $scope): string
    {
        $scope = preg_replace('/[^a-zA-Z0-9_.:-]+/', '_', trim($scope)) ?: 'default';

        return substr($scope, 0, 120);
    }
}
