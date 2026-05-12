#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Core\Env;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;

require dirname(__DIR__) . '/backend/bootstrap/app.php';

$loginArgument = trim((string) ($argv[1] ?? 'crimenio'));

$app = bootstrapApplication();
$database = new Database($app->config());

try {
    $pdo = $database->pdo();
    $dbConfigured = true;
} catch (\Throwable $exception) {
    $pdo = null;
    $dbConfigured = false;
}

$repository = null;
try {
    $repository = new LocalRepository($database, (string) Env::get('APP_PROVIDER_KEY', 'default'));
} catch (\Throwable) {
    $repository = null;
}

$provider = null;
if ($repository instanceof LocalRepository) {
    try {
        $provider = $repository->peekCurrentProvider();
    } catch (\Throwable) {
        $provider = null;
    }
}

$providerSettingsCount = 0;
$hasLocalAdmin = false;
if ($repository instanceof LocalRepository && $provider !== null) {
    try {
        $providerSettingsCount = count($repository->providerSettings());
    } catch (\Throwable) {
        $providerSettingsCount = 0;
    }

    try {
        $hasLocalAdmin = $repository->hasLocalAdminUser();
    } catch (\Throwable) {
        $hasLocalAdmin = false;
    }
}

$access = $repository instanceof LocalRepository ? $repository->accessForLogin($loginArgument) : [];
$appUrl = (string) $app->config()->get('app.url', '');
$appEnv = (string) $app->config()->get('app.env', 'production');
$providerKey = (string) $app->config()->get('app.provider_key', 'default');
$dbHost = (string) $app->config()->get('database.host', '127.0.0.1');
$dbDatabase = (string) $app->config()->get('database.database', 'isp_auxiliar');
$storageRoot = dirname(__DIR__);
$writeChecks = [
    'storage/logs' => isWritablePath($storageRoot . '/storage/logs'),
    'storage/backups' => isWritablePath($storageRoot . '/backups'),
];
$email = (array) $app->config()->get('email', []);
$evotrix = (array) $app->config()->get('evotrix', []);
$mkauthTicket = (array) $app->config()->get('contracts.mkauth_ticket', []);
$mkauthConfigured = trim((string) Env::get('MKAUTH_BASE_URL', '')) !== '';
$urlAlert = buildUrlAlert($appUrl);
$migrationStatus = $dbConfigured && $pdo instanceof \PDO ? migrationStatus($pdo, $storageRoot . '/database/migrations') : ['applied' => 0, 'pending' => 0, 'pending_files' => []];

echo "ISP Auxiliar - cheque de instalacao\n";
echo "APP_ENV: {$appEnv}\n";
echo "APP_URL: {$appUrl}\n";
echo "APP_PROVIDER_KEY: {$providerKey}\n";
echo "DB_HOST: {$dbHost}\n";
echo "DB_DATABASE: {$dbDatabase}\n";
echo 'provider encontrado: ' . ($provider === null ? 'nao' : ((string) ($provider['slug'] ?? '-') . ' / ' . (string) ($provider['name'] ?? '-'))) . "\n";
echo 'provider_settings existentes: ' . $providerSettingsCount . "\n";
echo 'admin local existente: ' . boolLabel($hasLocalAdmin) . ' (' . ($hasLocalAdmin ? 'OK' : 'FALHOU') . ")\n";
echo 'permissões para ' . $loginArgument . ":\n";
echo ' - can_manage_settings: ' . boolLabel((bool) ($access['can_manage_settings'] ?? false)) . "\n";
echo ' - can_access_contracts: ' . boolLabel((bool) ($access['can_access_contracts'] ?? false)) . "\n";
echo ' - can_manage_contracts: ' . boolLabel((bool) ($access['can_manage_contracts'] ?? false)) . "\n";
echo ' - can_manage_financial: ' . boolLabel((bool) ($access['can_manage_financial'] ?? false)) . "\n";
echo ' - can_manage_system: ' . boolLabel((bool) ($access['can_manage_system'] ?? false)) . "\n";
echo ' - permission_origin: ' . (string) ($access['permission_origin_label'] ?? $access['permission_origin'] ?? 'Local') . "\n";
echo 'storage/logs: ' . ($writeChecks['storage/logs'] ? 'OK' : 'FALHOU') . "\n";
echo 'storage/backups: ' . ($writeChecks['storage/backups'] ? 'OK' : 'FALHOU') . "\n";
echo 'SMTP configurado: ' . smtpConfigured($email) . "\n";
echo 'Evotrix configurado: ' . evotrixConfigured($evotrix) . "\n";
echo 'MkAuth API configurado: ' . ($mkauthConfigured ? 'sim' : 'nao') . "\n";
echo 'MKAUTH_TICKET_MESSAGE_FALLBACK ativo: ' . boolLabel(!isset($mkauthTicket['message_fallback']) || !empty($mkauthTicket['message_fallback'])) . "\n";
echo 'AUTO_CREATE_FINANCIAL_TICKET ativo: ' . boolLabel(!empty($mkauthTicket['auto_create'])) . "\n";
echo 'alerta URL: ' . ($urlAlert === '' ? 'nenhum' : $urlAlert) . "\n";
echo 'migrations aplicadas: ' . $migrationStatus['applied'] . "\n";
echo 'migrations pendentes: ' . $migrationStatus['pending'] . "\n";
if (($migrationStatus['pending_files'] ?? []) !== []) {
    echo 'pendentes: ' . implode(', ', $migrationStatus['pending_files']) . "\n";
}

exit(0);

function boolLabel(bool $value): string
{
    return $value ? 'sim' : 'nao';
}

function isWritablePath(string $path): bool
{
    if (!is_dir($path)) {
        return false;
    }

    return is_writable($path);
}

function smtpConfigured(array $email): string
{
    $configured = trim((string) ($email['smtp_host'] ?? '')) !== ''
        && trim((string) ($email['smtp_username'] ?? '')) !== ''
        && trim((string) ($email['smtp_from'] ?? '')) !== '';

    return boolLabel($configured);
}

function evotrixConfigured(array $evotrix): string
{
    $configured = !empty($evotrix['enabled'])
        && trim((string) ($evotrix['api_base'] ?? '')) !== ''
        && trim((string) ($evotrix['api_key'] ?? '')) !== '';

    return boolLabel($configured);
}

function buildUrlAlert(string $appUrl): string
{
    $appUrl = trim($appUrl);
    if ($appUrl === '') {
        return 'APP_URL nao configurada';
    }

    $host = strtolower((string) (parse_url($appUrl, PHP_URL_HOST) ?? ''));
    $port = (string) (parse_url($appUrl, PHP_URL_PORT) ?? '');
    if ($host === 'localhost' || $host === '127.0.0.1' || str_contains($host, ':8000')) {
        return 'APP_URL aponta para localhost ou porta de desenvolvimento';
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if ($port === '8000') {
            return 'APP_URL usa IP e porta 8000; ajuste para o dominio HTTPS final';
        }
        return 'APP_URL usa IP; prefira dominio publico para producao real';
    }

    if ($port === '8000') {
        return 'APP_URL usa porta 8000; ajuste para porta padrao HTTPS';
    }

    return '';
}

function migrationStatus(\PDO $pdo, string $migrationsPath): array
{
    $files = glob(rtrim($migrationsPath, '/') . '/*.sql') ?: [];
    $applied = [];

    try {
        $rows = $pdo->query(
            'SELECT version, filename, checksum
             FROM schema_migrations'
        )->fetchAll();
        foreach (is_array($rows) ? $rows : [] as $row) {
            $name = trim((string) ($row['filename'] ?? $row['version'] ?? ''));
            if ($name === '') {
                continue;
            }
            $applied[$name] = true;
        }
    } catch (\Throwable) {
        return ['applied' => 0, 'pending' => count($files), 'pending_files' => array_map('basename', $files)];
    }

    $pending = [];
    foreach ($files as $file) {
        $name = basename($file);
        if (!isset($applied[$name])) {
            $pending[] = $name;
        }
    }

    return [
        'applied' => max(0, count($files) - count($pending)),
        'pending' => count($pending),
        'pending_files' => $pending,
    ];
}
