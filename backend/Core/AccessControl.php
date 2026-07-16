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
            'clients.view' => !empty($access['can_search_clients']),
            'clients.create' => !empty($access['can_create_client']),
            'clients.upgrade_request' => !empty($access['can_upgrade_request']),
            'contracts.view' => !empty($access['can_view_contracts']),
            'contracts.request_signature' => !empty($access['can_request_contract_signature']),
            'contracts.resend_acceptance' => !empty($access['can_resend_contract_acceptance']),
            'clients.upgrade_technical_complete' => !empty($access['can_complete_upgrade_technical']),
            'clients.upgrade_commercial' => !empty($access['can_upgrade_commercial']),
            default => false,
        };
    }
}
