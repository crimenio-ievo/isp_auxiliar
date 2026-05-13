#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Core\Env;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\MkAuth\MkAuthDatabase;
use App\Infrastructure\MkAuth\MkAuthClient;

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
$providerSettings = [];
$hasLocalAdmin = false;
if ($repository instanceof LocalRepository && $provider !== null) {
    try {
        $providerSettings = $repository->providerSettings();
        $providerSettingsCount = count($providerSettings);
    } catch (\Throwable) {
        $providerSettings = [];
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
$mkauthDatabase = null;
try {
    $mkauthDatabase = new MkAuthDatabase(
        (string) Env::get('MKAUTH_DB_HOST', ''),
        (string) Env::get('MKAUTH_DB_PORT', '3306'),
        (string) Env::get('MKAUTH_DB_NAME', 'mkradius'),
        (string) Env::get('MKAUTH_DB_USER', ''),
        (string) Env::get('MKAUTH_DB_PASSWORD', ''),
        (string) Env::get('MKAUTH_DB_CHARSET', 'utf8mb4'),
        (string) Env::get('MKAUTH_DB_HASH_ALGOS', 'sha256,sha1')
    );
} catch (\Throwable) {
    $mkauthDatabase = null;
}
$urlAlert = buildUrlAlert($appUrl);
$migrationStatus = $dbConfigured && $pdo instanceof \PDO ? migrationStatus($pdo, $storageRoot . '/database/migrations') : ['applied' => 0, 'pending' => 0, 'pending_files' => []];
$providerLegal = resolveProviderLegalData($app->config(), $provider, $providerSettings, $mkauthDatabase);

echo "ISP Auxiliar - cheque de instalacao\n";
echo "APP_ENV: {$appEnv}\n";
echo "APP_URL: {$appUrl}\n";
echo "APP_PROVIDER_KEY: {$providerKey}\n";
echo "DB_HOST: {$dbHost}\n";
echo "DB_DATABASE: {$dbDatabase}\n";
echo 'provider encontrado: ' . ($provider === null ? 'nao' : ((string) ($provider['slug'] ?? '-') . ' / ' . (string) ($provider['name'] ?? '-'))) . "\n";
echo 'provider_settings existentes: ' . $providerSettingsCount . "\n";
echo 'admin local existente: ' . boolLabel($hasLocalAdmin) . ' (' . ($hasLocalAdmin ? 'OK' : 'FALHOU') . ")\n";
echo 'Dados jurídicos do provedor: ' . ($providerLegal['complete'] ? 'completo' : 'incompleto') . "\n";
echo 'Fonte: ' . $providerLegal['source'] . "\n";
echo 'provider_anatel_process configurado: ' . boolLabel(trim((string) ($providerLegal['data']['anatel_process'] ?? '')) !== '') . "\n";
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
echo 'integracao de consulta de chamado MkAuth: ' . boolLabel($mkauthDatabase instanceof MkAuthDatabase && $mkauthDatabase->isConfigured()) . "\n";
echo 'MKAUTH_TICKET_MESSAGE_FALLBACK ativo: ' . boolLabel(!isset($mkauthTicket['message_fallback']) || !empty($mkauthTicket['message_fallback'])) . "\n";
echo 'AUTO_CREATE_FINANCIAL_TICKET ativo: ' . boolLabel(!empty($mkauthTicket['auto_create'])) . "\n";
echo 'alerta URL: ' . ($urlAlert === '' ? 'nenhum' : $urlAlert) . "\n";
echo 'migrations aplicadas: ' . $migrationStatus['applied'] . "\n";
echo 'migrations pendentes: ' . $migrationStatus['pending'] . "\n";
echo 'financial_tasks com campo de ticket MkAuth: ' . boolLabel(tableColumnExists($pdo, 'financial_tasks', 'mkauth_ticket_id')) . "\n";
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

function resolveProviderLegalData(\App\Core\Config $config, ?array $provider, array $providerSettings, ?MkAuthDatabase $mkauthDatabase = null): array
{
    $provider = is_array($provider) ? $provider : [];
    $providerSettings = is_array($providerSettings) ? $providerSettings : [];

    $getSetting = static function (array $settings, string $key, string $fallback = ''): string {
        $value = trim((string) ($settings[$key] ?? ''));

        return $value !== '' ? $value : $fallback;
    };

    $local = [
        'brand_name' => $getSetting($providerSettings, 'provider_name', (string) ($provider['name'] ?? '')),
        'legal_name' => $getSetting($providerSettings, 'provider_legal_name', (string) ($provider['name'] ?? '')),
        'document' => $getSetting($providerSettings, 'provider_cnpj', (string) ($provider['document'] ?? '')),
        'address' => $getSetting($providerSettings, 'provider_address', ''),
        'neighborhood' => $getSetting($providerSettings, 'provider_neighborhood', ''),
        'city' => $getSetting($providerSettings, 'provider_city', ''),
        'state' => $getSetting($providerSettings, 'provider_state', ''),
        'zip' => $getSetting($providerSettings, 'provider_zip', ''),
        'phone' => $getSetting($providerSettings, 'provider_phone', ''),
        'site' => $getSetting($providerSettings, 'provider_site', ''),
        'email' => $getSetting($providerSettings, 'provider_email', ''),
        'anatel_process' => $getSetting($providerSettings, 'provider_anatel_process', ''),
        'central_assinante_url' => $getSetting(
            $providerSettings,
            'central_assinante_url',
            (string) $config->get('contracts.commercial.central_assinante_url', 'https://sistema.ievo.com.br/central')
        ),
    ];

    $needMkAuth = false;
    foreach (['brand_name', 'legal_name', 'document', 'address', 'neighborhood', 'city', 'state', 'zip', 'phone', 'site', 'email', 'anatel_process'] as $field) {
        if (trim((string) ($local[$field] ?? '')) === '') {
            $needMkAuth = true;
            break;
        }
    }

    $mkauth = [];
    if ($needMkAuth) {
        $client = new MkAuthClient(
            (string) Env::get('MKAUTH_BASE_URL', ''),
            (string) Env::get('MKAUTH_API_TOKEN', ''),
            (string) Env::get('MKAUTH_CLIENT_ID', ''),
            (string) Env::get('MKAUTH_CLIENT_SECRET', '')
        );

        if ($client->isConfigured()) {
            try {
                $response = $client->listCompany();
                $mkauth = extractMkAuthCompanyProfile($response);
            } catch (\Throwable) {
                $mkauth = [];
            }
        }
    }

    if ($needMkAuth && $mkauth === [] && $mkauthDatabase instanceof MkAuthDatabase && $mkauthDatabase->isConfigured()) {
        try {
            $profile = $mkauthDatabase->companyLegalProfile();
            if (is_array($profile) && !empty($profile['data']) && is_array($profile['data'])) {
                $mkauth = $profile['data'];
            }
        } catch (\Throwable) {
            $mkauth = [];
        }
    }

    $resolved = [];
    $usedLocal = false;
    $usedMkAuth = false;
    foreach ($local as $field => $value) {
        $value = trim((string) $value);
        if ($value !== '') {
            $resolved[$field] = $value;
            $usedLocal = true;
            continue;
        }

        $mkValue = '';
        switch ($field) {
            case 'brand_name':
                $mkValue = (string) ($mkauth['nome'] ?? '');
                break;
            case 'legal_name':
                $mkValue = (string) (($mkauth['razao'] ?? '') ?: ($mkauth['nome'] ?? ''));
                break;
            case 'document':
                $mkValue = (string) ($mkauth['cnpj'] ?? '');
                break;
            case 'address':
                $mkValue = (string) ($mkauth['endereco'] ?? '');
                break;
            case 'neighborhood':
                $mkValue = (string) ($mkauth['bairro'] ?? '');
                break;
            case 'city':
                $mkValue = (string) ($mkauth['cidade'] ?? '');
                break;
            case 'state':
                $mkValue = (string) ($mkauth['estado'] ?? '');
                break;
            case 'zip':
                $mkValue = (string) ($mkauth['cep'] ?? '');
                break;
            case 'phone':
                $mkValue = (string) (($mkauth['telefone'] ?? '') ?: ($mkauth['fone'] ?? '') ?: ($mkauth['celular'] ?? ''));
                break;
            case 'site':
                $mkValue = (string) ($mkauth['site'] ?? '');
                break;
            case 'email':
                $mkValue = (string) ($mkauth['email'] ?? '');
                break;
            case 'anatel_process':
                $mkValue = (string) (($mkauth['autorizacao_anatel'] ?? '') ?: ($mkauth['processo_scm'] ?? ''));
                break;
        }

        $mkValue = trim($mkValue);
        if ($mkValue !== '') {
            $resolved[$field] = $mkValue;
            $usedMkAuth = true;
            continue;
        }

        $resolved[$field] = '';
    }

    $resolved['central_assinante_url'] = trim((string) ($resolved['central_assinante_url'] ?? ''));
    if ($resolved['central_assinante_url'] === '') {
        $resolved['central_assinante_url'] = 'https://sistema.ievo.com.br/central';
    }

    if (trim((string) ($resolved['anatel_process'] ?? '')) === '') {
        $providerSlug = strtolower(trim((string) ($provider['slug'] ?? '')));
        $providerDisplay = strtolower(trim((string) ($resolved['brand_name'] ?? ($provider['name'] ?? ''))));
        if ($providerSlug === 'ievo' || str_contains($providerDisplay, 'ievo')) {
            $resolved['anatel_process'] = 'Processo nº 53500.292642/2022-7';
        }
    }

    $complete = trim((string) ($resolved['legal_name'] ?? '')) !== ''
        && trim((string) ($resolved['document'] ?? '')) !== ''
        && trim((string) ($resolved['address'] ?? '')) !== ''
        && trim((string) ($resolved['city'] ?? '')) !== ''
        && trim((string) ($resolved['state'] ?? '')) !== ''
        && trim((string) ($resolved['phone'] ?? '')) !== ''
        && trim((string) ($resolved['email'] ?? '')) !== '';

    if ($usedMkAuth) {
        $source = 'mkauth';
    } elseif ($usedLocal || !empty($providerSettings)) {
        $source = 'local';
    } elseif (trim((string) ($provider['name'] ?? '')) !== '') {
        $source = 'providers';
    } else {
        $source = 'fallback';
    }

    return [
        'source' => $source,
        'complete' => $complete,
        'data' => $resolved,
    ];
}

function extractMkAuthCompanyProfile(array $response): array
{
    $candidates = [
        $response['data'] ?? null,
        $response['dados'] ?? null,
        $response['empresa'] ?? null,
        $response['empresas'] ?? null,
        $response['result'] ?? null,
        $response['items'] ?? null,
        $response['records'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (is_array($candidate) && $candidate !== []) {
            if (array_is_list($candidate)) {
                $first = $candidate[0] ?? null;
                if (is_array($first)) {
                    return $first;
                }
            } else {
                return $candidate;
            }
        }
    }

    foreach ($response as $value) {
        if (is_array($value) && $value !== []) {
            if (array_is_list($value)) {
                $first = $value[0] ?? null;
                if (is_array($first)) {
                    return $first;
                }
            } else {
                return $value;
            }
        }
    }

    return [];
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

function tableColumnExists(?\PDO $pdo, string $table, string $column): bool
{
    if (!$pdo instanceof \PDO) {
        return false;
    }

    $statement = $pdo->prepare(
        'SELECT 1
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND column_name = :column_name
         LIMIT 1'
    );
    $statement->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return (bool) $statement->fetchColumn();
}
