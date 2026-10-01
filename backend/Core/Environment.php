<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Identifica se esta instalação é produção, para decidir o banner de testes.
 *
 * Regra à prova de falha: só `APP_ENVIRONMENT=production` (exato, ignorando
 * caixa e espaços) esconde o banner. Variável ausente, vazia ou com qualquer
 * outro valor (test, staging, typo…) MOSTRA o banner.
 *
 * Independente de APP_ENV, que tem outro papel (travas de dry-run).
 */
final class Environment
{
    public static function isProduction(?string $value = null): bool
    {
        $value ??= Env::get('APP_ENVIRONMENT');

        return strtolower(trim((string) $value)) === 'production';
    }

    public static function showTestBanner(?string $value = null): bool
    {
        return !self::isProduction($value);
    }
}
