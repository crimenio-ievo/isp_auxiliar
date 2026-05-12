#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Infrastructure\Database\Database;

require dirname(__DIR__) . '/backend/bootstrap/app.php';

$app = bootstrapApplication();
$rootDir = dirname(__DIR__);
$appEnv = strtolower(trim((string) $app->config()->get('app.env', 'production')));
$appUrl = (string) $app->config()->get('app.url', 'http://localhost');
$forceFlag = in_array('--force', $argv ?? [], true);
$confirmationPrompt = 'DIGITE RESETAR PARA CONTINUAR:';
$confirmationToken = 'RESETAR_DADOS_TESTE';
$confirmationValue = trim((string) getenv('RESETAR_DADOS_TESTE'));
$timestamp = date('Ymd_His');
$backupRoot = $rootDir . '/backups/reset_test_operational_data_' . $timestamp;
$storageBackup = $backupRoot . '/storage_operational.tar.gz';
$sqlBackup = $backupRoot . '/operational_tables.sql';
$tablesToClean = [
    'client_registrations',
    'installation_checkpoints',
    'client_contracts',
    'contract_acceptances',
    'financial_tasks',
    'notification_logs',
    'audit_logs',
];
$storagePaths = [
    'storage/uploads/clientes',
    'storage/cache/client_drafts',
];
$allowedEnvironments = ['local', 'development', 'testing', 'homolog', 'staging'];

echo "ISP Auxiliar - reset operacional de testes\n";
echo "Diretorio base: {$rootDir}\n";
echo "APP_ENV: {$appEnv}\n";
echo "APP_URL: {$appUrl}\n";
echo "Backup destino: {$backupRoot}\n\n";
echo "Tabelas que serao limpas:\n";
foreach ($tablesToClean as $table) {
    echo " - {$table}\n";
}

echo "\nPastas que serao limpas:\n";
foreach ($storagePaths as $path) {
    echo " - {$path}\n";
}

echo "\nComandos que serao usados:\n";
echo ' - mysqldump ' . implode(' ', array_map(static fn (string $table): string => escapeshellarg($table), $tablesToClean)) . "\n";
echo " - tar -czf " . escapeshellarg($storageBackup) . " -C " . escapeshellarg($rootDir) . " " . implode(' ', array_map(static fn (string $path): string => escapeshellarg($path), $storagePaths)) . "\n";
echo " - TRUNCATE TABLE " . implode(', ', $tablesToClean) . "\n\n";

if (!in_array($appEnv, $allowedEnvironments, true)) {
    echo "RESET BLOQUEADO: ambiente de producao detectado.\n";
    logResetEvent($app, 'blocked', [
        'app_env' => $appEnv,
        'app_url' => $appUrl,
        'hostname' => gethostname() ?: php_uname('n'),
        'user' => get_current_user() ?: getlogin() ?: 'unknown',
    ]);
    exit(1);
}

if (!$forceFlag) {
    echo "Execucao bloqueada.\n";
    echo "Informe a flag --force para continuar.\n";
    logResetEvent($app, 'blocked', [
        'app_env' => $appEnv,
        'app_url' => $appUrl,
        'hostname' => gethostname() ?: php_uname('n'),
        'user' => get_current_user() ?: getlogin() ?: 'unknown',
        'reason' => 'missing_force_flag',
    ]);
    exit(1);
}

if ($confirmationValue !== $confirmationToken) {
    echo "Execucao bloqueada.\n";
    echo "Para executar de verdade, defina:\n";
    echo "  RESETAR_DADOS_TESTE={$confirmationToken} php scripts/reset_test_operational_data.php --force\n";
    logResetEvent($app, 'blocked', [
        'app_env' => $appEnv,
        'app_url' => $appUrl,
        'hostname' => gethostname() ?: php_uname('n'),
        'user' => get_current_user() ?: getlogin() ?: 'unknown',
        'reason' => 'missing_env_confirmation',
    ]);
    exit(1);
}

if (!function_exists('posix_isatty') || !posix_isatty(STDIN)) {
    echo "Execucao bloqueada.\n";
    echo "Este script exige confirmacao interativa no terminal.\n";
    logResetEvent($app, 'blocked', [
        'app_env' => $appEnv,
        'app_url' => $appUrl,
        'hostname' => gethostname() ?: php_uname('n'),
        'user' => get_current_user() ?: getlogin() ?: 'unknown',
        'reason' => 'non_interactive',
    ]);
    exit(1);
}

echo $confirmationPrompt . "\n";
$interactiveValue = trim((string) fgets(STDIN));
if ($interactiveValue !== 'RESETAR') {
    echo "Execucao cancelada pelo operador.\n";
    logResetEvent($app, 'cancelled', [
        'app_env' => $appEnv,
        'app_url' => $appUrl,
        'hostname' => gethostname() ?: php_uname('n'),
        'user' => get_current_user() ?: getlogin() ?: 'unknown',
    ]);
    exit(1);
}

if (!is_dir($backupRoot) && !mkdir($backupRoot, 0775, true) && !is_dir($backupRoot)) {
    fwrite(STDERR, "Nao foi possivel criar o diretorio de backup.\n");
    exit(1);
}

$database = new Database($app->config());
$pdo = $database->pdo();
$dbName = (string) $app->config()->get('database.database', 'isp_auxiliar');
$dbHost = (string) $app->config()->get('database.host', '127.0.0.1');
$dbPort = (string) $app->config()->get('database.port', '3306');
$dbUser = (string) $app->config()->get('database.username', '');
$dbPassword = (string) $app->config()->get('database.password', '');
$dumpTables = implode(' ', array_map(static fn (string $table): string => escapeshellarg($table), $tablesToClean));

if (trim(shell_exec('command -v mysqldump 2>/dev/null') ?? '') === '') {
    fwrite(STDERR, "mysqldump nao encontrado. Abortei para nao executar sem backup SQL.\n");
    exit(1);
}

$dumpCommand = sprintf(
    'MYSQL_PWD=%s mysqldump -h %s -P %s -u %s --single-transaction --skip-lock-tables --routines --triggers %s %s > %s',
    escapeshellarg($dbPassword),
    escapeshellarg($dbHost),
    escapeshellarg($dbPort),
    escapeshellarg($dbUser),
    escapeshellarg($dbName),
    $dumpTables,
    escapeshellarg($sqlBackup)
);

echo "Gerando backup SQL...\n";
system($dumpCommand, $dumpExitCode);
if ($dumpExitCode !== 0) {
    fwrite(STDERR, "Falha ao gerar backup SQL.\n");
    exit(1);
}

$tarPaths = implode(' ', array_map(static fn (string $path): string => escapeshellarg($path), $storagePaths));
$tarCommand = sprintf(
    'tar -czf %s -C %s %s',
    escapeshellarg($storageBackup),
    escapeshellarg($rootDir),
    $tarPaths
);

echo "Gerando backup do storage operacional...\n";
system($tarCommand, $tarExitCode);
if ($tarExitCode !== 0) {
    fwrite(STDERR, "Falha ao gerar backup do storage.\n");
    exit(1);
}

echo "Aplicando limpeza nas tabelas operacionais...\n";
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($tablesToClean as $table) {
    $pdo->exec('TRUNCATE TABLE `' . $table . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

foreach ($storagePaths as $path) {
    $absolute = $rootDir . '/' . $path;
    clearDirectoryContents($absolute);
}

echo "\nResumo final:\n";
echo " - Backup SQL: {$sqlBackup}\n";
echo " - Backup storage: {$storageBackup}\n";
echo " - Tabelas limpas: " . implode(', ', $tablesToClean) . "\n";
echo " - Pastas limpas: " . implode(', ', $storagePaths) . "\n";
echo " - Configuracoes, provider_settings, usuarios e migrations preservados.\n";
echo " - Limpeza concluida com sucesso.\n";
logResetEvent($app, 'executed', [
    'app_env' => $appEnv,
    'app_url' => $appUrl,
    'hostname' => gethostname() ?: php_uname('n'),
    'user' => get_current_user() ?: getlogin() ?: 'unknown',
    'backup_sql' => $sqlBackup,
    'backup_storage' => $storageBackup,
]);

function clearDirectoryContents(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $items = array_diff(scandir($directory) ?: [], ['.', '..']);
    foreach ($items as $item) {
        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            clearDirectoryContents($path);
            @rmdir($path);
            continue;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
    }
}

function logResetEvent($app, string $result, array $context = []): void
{
    try {
        $database = new Database($app->config());
        $repository = new \App\Infrastructure\Local\LocalRepository(
            $database,
            (string) $app->config()->get('app.provider_key', 'default')
        );

        $repository->log(
            null,
            (string) ($context['user'] ?? 'system'),
            'reset.operational_data.' . $result,
            'system',
            null,
            $context,
            '',
            ''
        );
    } catch (\Throwable) {
        // Registro local nao pode impedir o reset seguro.
    }
}
