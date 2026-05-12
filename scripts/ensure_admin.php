#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Core\Env;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;

require dirname(__DIR__) . '/backend/bootstrap/app.php';

$loginArgument = trim((string) ($argv[1] ?? ''));
if ($loginArgument === '') {
    fwrite(STDERR, "Uso: php scripts/ensure_admin.php login_mkauth\n");
    exit(1);
}

$app = bootstrapApplication();
$database = new Database($app->config());

try {
    $repository = new LocalRepository($database, (string) Env::get('APP_PROVIDER_KEY', 'default'));
    $provider = $repository->currentProvider();
} catch (\Throwable $exception) {
    fwrite(STDERR, "Nao foi possivel preparar o banco local: {$exception->getMessage()}\n");
    exit(1);
}

$login = $repository->normalizeLogin($loginArgument);
if ($login === '') {
    fwrite(STDERR, "Login invalido.\n");
    exit(1);
}

$existingPermissionMap = loadPermissionMap($repository->providerSetting('mkauth_permission_map', ''));
$existingPermissionMap[$login] = [
    'login' => $login,
    'gestor_admin' => true,
    'contratos' => true,
    'financeiro' => true,
    'tecnico' => true,
    'configuracoes' => true,
];

$managerLogins = mergeLoginList($repository->providerSettingList('mkauth_manager_logins'), $login);
$adminLogins = mergeLoginList($repository->providerSettingList('admin_access_logins'), $login);
$settingsLogins = mergeLoginList($repository->providerSettingList('settings_access_logins'), $login);
$contractLogins = mergeLoginList($repository->providerSettingList('contract_access_logins'), $login);
$financialLogins = mergeLoginList($repository->providerSettingList('financial_access_logins'), $login);

try {
    $repository->saveProviderSettings([
        'mkauth_manager_login' => $login,
        'mkauth_manager_logins' => implode("\n", $managerLogins),
        'admin_access_logins' => implode("\n", $adminLogins),
        'settings_access_logins' => implode("\n", $settingsLogins),
        'contract_access_logins' => implode("\n", $contractLogins),
        'financial_access_logins' => implode("\n", $financialLogins),
        'mkauth_permission_map' => json_encode(array_values($existingPermissionMap), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
    ]);
} catch (\Throwable $exception) {
    fwrite(STDERR, "Nao foi possivel salvar as permissoes: {$exception->getMessage()}\n");
    exit(1);
}

$access = $repository->accessForLogin($login);

echo "ISP Auxiliar - ensure admin\n";
if (is_array($provider)) {
    echo 'Provider: ' . (string) ($provider['slug'] ?? '-') . " / " . (string) ($provider['name'] ?? '-') . "\n";
}
echo "Login normalizado: {$login}\n";
echo "Permissoes mescladas com sucesso.\n";
echo "accessForLogin({$login}):\n";
echo ' - can_manage_settings: ' . boolLabel((bool) ($access['can_manage_settings'] ?? false)) . "\n";
echo ' - can_access_contracts: ' . boolLabel((bool) ($access['can_access_contracts'] ?? false)) . "\n";
echo ' - can_manage_contracts: ' . boolLabel((bool) ($access['can_manage_contracts'] ?? false)) . "\n";
echo ' - can_manage_financial: ' . boolLabel((bool) ($access['can_manage_financial'] ?? false)) . "\n";
echo ' - can_manage_system: ' . boolLabel((bool) ($access['can_manage_system'] ?? false)) . "\n";
echo ' - permission_origin: ' . (string) ($access['permission_origin_label'] ?? $access['permission_origin'] ?? 'Local') . "\n";

exit(0);

function mergeLoginList(array $items, string $login): array
{
    $normalized = [];

    foreach ($items as $item) {
        $value = strtolower(trim((string) $item));
        if ($value === '') {
            continue;
        }
        $normalized[$value] = $value;
    }

    $normalized[$login] = $login;

    return array_values($normalized);
}

function loadPermissionMap(string $raw): array
{
    if (trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $map = [];
    foreach ($decoded as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $entryLogin = strtolower(trim((string) ($entry['login'] ?? '')));
        if ($entryLogin === '') {
            continue;
        }

        $map[$entryLogin] = [
            'login' => $entryLogin,
            'gestor_admin' => !empty($entry['gestor_admin']),
            'contratos' => !empty($entry['contratos']),
            'financeiro' => !empty($entry['financeiro']),
            'tecnico' => !empty($entry['tecnico']),
            'configuracoes' => !empty($entry['configuracoes']),
        ];
    }

    return $map;
}

function boolLabel(bool $value): string
{
    return $value ? 'sim' : 'nao';
}
