<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Helper central para checagem simples de permissões por login MkAuth.
 *
 * A estrutura opera com perfis leves em vez de RBAC completo nesta etapa.
 */
final class AccessControl
{
    public static function normalizeLogin(string $login): string
    {
        return strtolower(trim($login));
    }

    public static function isAdmin(array $access): bool
    {
        return !empty($access['is_admin']) || !empty($access['gestor_admin']);
    }

    public static function isGestor(array $access): bool
    {
        return self::isAdmin($access) || !empty($access['is_manager']);
    }

    public static function can(array $access, string $ability): bool
    {
        $ability = self::normalizeLogin($ability);

        if (self::isAdmin($access) || self::isGestor($access)) {
            return true;
        }

        return match ($ability) {
            'configuracoes' => !empty($access['can_manage_settings']),
            'contratos' => !empty($access['can_access_contracts']),
            'financeiro' => !empty($access['can_manage_financial']),
            'usuarios', 'sistema' => !empty($access['can_manage_users']) || !empty($access['can_manage_system']),
            default => false,
        };
    }
}
