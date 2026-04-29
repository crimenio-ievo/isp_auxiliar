#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Core\Application;
use App\Core\Env;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Database\MigrationRunner;
use App\Infrastructure\Local\LocalRepository;

require dirname(__DIR__) . '/backend/bootstrap/app.php';

$app = bootstrapApplication();
$command = $argv[1] ?? 'about';

exit(runConsoleCommand($app, $argv));

function runConsoleCommand(Application $app, array $argv): int
{
    $command = $argv[1] ?? 'about';

    switch ($command) {
        case 'about':
            echo "ISP Auxiliar\n";
            echo "Ambiente: " . $app->config()->get('app.env', 'production') . "\n";
            echo "URL: " . $app->config()->get('app.url', 'http://localhost') . "\n";
            return 0;

        case 'routes':
            foreach ($app->router()->all() as $route) {
                $name = $route['name'] ?? '-';
                echo sprintf(
                    "%-6s %-20s %s\n",
                    $route['method'],
                    $route['path'],
                    $name === null ? '-' : $name
                );
            }
            return 0;

        case 'db:create':
            return createDatabase($app);

        case 'migrate':
            return runMigrations($app);

        case 'user:create-manager':
            return createManagerUser($app, $argv);

        case 'settings:sync-env':
            return syncSettingsFromEnv($app);

        default:
            fwrite(STDERR, "Comando nao reconhecido: {$command}\n");
            fwrite(STDERR, "Use: about, routes, db:create, migrate, settings:sync-env ou user:create-manager\n");
            return 1;
    }
}

function createDatabase(Application $app): int
{
    $database = (string) $app->config()->get('database.database', 'isp_auxiliar');

    if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
        fwrite(STDERR, "Nome de banco invalido: {$database}\n");
        return 1;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;charset=%s',
        (string) $app->config()->get('database.host', '127.0.0.1'),
        (string) $app->config()->get('database.port', '3306'),
        (string) $app->config()->get('database.charset', 'utf8mb4')
    );

    try {
        $pdo = new \PDO(
            $dsn,
            (string) $app->config()->get('database.username', ''),
            (string) $app->config()->get('database.password', ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        $charset = (string) $app->config()->get('database.charset', 'utf8mb4');
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET {$charset} COLLATE {$charset}_unicode_ci");
    } catch (\Throwable $exception) {
        fwrite(STDERR, "Nao foi possivel criar o banco: {$exception->getMessage()}\n");
        return 1;
    }

    echo "Banco local pronto: {$database}\n";
    return 0;
}

function runMigrations(Application $app): int
{
    try {
        $database = new Database($app->config());
        $runner = new MigrationRunner($database, dirname(__DIR__) . '/database/migrations');
        $ran = $runner->run();
    } catch (\Throwable $exception) {
        fwrite(STDERR, "Nao foi possivel executar migrations: {$exception->getMessage()}\n");
        return 1;
    }

    if ($ran === []) {
        echo "Nenhuma migration pendente.\n";
        return 0;
    }

    foreach ($ran as $version) {
        echo "Migration executada: {$version}\n";
    }

    return 0;
}

function createManagerUser(Application $app, array $argv): int
{
    $name = trim((string) ($argv[2] ?? ''));
    $email = trim((string) ($argv[3] ?? ''));
    $password = (string) ($argv[4] ?? '');
    $role = trim((string) ($argv[5] ?? 'manager')) ?: 'manager';

    if ($name === '' || $email === '' || $password === '') {
        fwrite(STDERR, "Uso: php scripts/console.php user:create-manager \"Nome\" email@provedor.com senha [manager|platform_admin]\n");
        return 1;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fwrite(STDERR, "E-mail invalido.\n");
        return 1;
    }

    if (!in_array($role, ['manager', 'platform_admin'], true)) {
        fwrite(STDERR, "Perfil invalido. Use manager ou platform_admin.\n");
        return 1;
    }

    try {
        $repository = new LocalRepository(
            new Database($app->config()),
            (string) ($app->config()->get('app.provider_key', getenv('APP_PROVIDER_KEY') ?: 'default'))
        );
        $userId = $repository->createManagerUser($name, $email, $password, $role);
    } catch (\Throwable $exception) {
        fwrite(STDERR, "Nao foi possivel criar o gestor: {$exception->getMessage()}\n");
        return 1;
    }

    echo "Gestor criado com ID {$userId}: {$email}\n";
    return 0;
}

function syncSettingsFromEnv(Application $app): int
{
    try {
        $repository = new LocalRepository(
            new Database($app->config()),
            (string) $app->config()->get('app.provider_key', 'default')
        );
        $appUrl = (string) $app->config()->get('app.url', '');
        $urlPath = (string) (parse_url($appUrl, PHP_URL_PATH) ?: '');

        $repository->saveProviderProfile([
            'name' => (string) $app->config()->get('app.name', 'ISP Auxiliar'),
            'domain' => (string) (parse_url($appUrl, PHP_URL_HOST) ?: ''),
            'base_path' => $urlPath,
        ]);
        $repository->saveProviderSettings([
            'mkauth_base_url' => (string) Env::get('MKAUTH_BASE_URL', ''),
            'mkauth_api_token' => (string) Env::get('MKAUTH_API_TOKEN', ''),
            'mkauth_client_id' => (string) Env::get('MKAUTH_CLIENT_ID', ''),
            'mkauth_client_secret' => (string) Env::get('MKAUTH_CLIENT_SECRET', ''),
            'mkauth_db_host' => (string) Env::get('MKAUTH_DB_HOST', ''),
            'mkauth_db_port' => (string) Env::get('MKAUTH_DB_PORT', '3306'),
            'mkauth_db_name' => (string) Env::get('MKAUTH_DB_NAME', 'mkradius'),
            'mkauth_db_user' => (string) Env::get('MKAUTH_DB_USER', ''),
            'mkauth_db_password' => (string) Env::get('MKAUTH_DB_PASSWORD', ''),
            'mkauth_db_charset' => (string) Env::get('MKAUTH_DB_CHARSET', 'utf8mb4'),
            'mkauth_db_hash_algos' => (string) Env::get('MKAUTH_DB_HASH_ALGOS', 'sha256,sha1'),
        ]);
    } catch (\Throwable $exception) {
        fwrite(STDERR, "Nao foi possivel sincronizar configuracoes: {$exception->getMessage()}\n");
        return 1;
    }

    echo "Configuracoes do .env sincronizadas para o banco complementar.\n";
    return 0;
}
