<?php

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';

use App\Core\Application;
use App\Core\Config;
use App\Core\Flash;
use App\Core\Container;
use App\Core\Env;
use App\Core\Url;
use App\Core\Router;
use App\Core\View;
use App\Infrastructure\Contracts\ContractAcceptanceRepository;
use App\Infrastructure\Contracts\ContractRepository;
use App\Infrastructure\Contracts\FinancialTaskRepository;
use App\Infrastructure\Contracts\MessageTemplateRepository;
use App\Infrastructure\Contracts\NotificationLogRepository;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;
use App\Infrastructure\Notifications\EvotrixService;
use App\Infrastructure\Notifications\EmailService;
use App\Infrastructure\MkAuth\ClientPayloadMapper;
use App\Infrastructure\MkAuth\MkAuthDatabase;
use App\Infrastructure\MkAuth\ClientProvisioner;
use App\Infrastructure\MkAuth\MkAuthClient;
use App\Infrastructure\MkAuth\MkAuthTicketService;

/**
 * Monta a aplicacao com configuracao, container, views e rotas.
 *
 * Nesta fase a ideia e manter um bootstrap pequeno, claro e facil de evoluir
 * quando entrarem banco, servicos e integracoes externas.
 */
function bootstrapApplication(): Application
{
    $rootPath = dirname(__DIR__, 2);

    Env::load($rootPath . '/.env');

    if (!defined('APP_VERSION_INFO')) {
        define('APP_VERSION_INFO', is_file($rootPath . '/config/version.php')
            ? require $rootPath . '/config/version.php'
            : [
                'app_name' => 'ISP Auxiliar',
                'app_version' => '0.1.0',
                'build_date' => date('Y-m-d'),
                'modules' => [
                    'isp_auxiliar' => '0.1.0',
                    'isp_map2' => '0.1.0',
                ],
            ]);
    }

    $config = new Config([
        'app' => require __DIR__ . '/../config/app.php',
        'database' => require __DIR__ . '/../config/database.php',
        'paths' => require __DIR__ . '/../config/paths.php',
        'contracts' => require __DIR__ . '/../config/contracts.php',
        'evotrix' => require __DIR__ . '/../config/evotrix.php',
        'email' => require __DIR__ . '/../config/email.php',
    ]);

    date_default_timezone_set((string) $config->get('app.timezone', 'UTC'));

    $container = new Container();
    $localDatabase = new Database($config);
    $localRepository = new LocalRepository(
        $localDatabase,
        (string) Env::get('APP_PROVIDER_KEY', 'default')
    );
    $providerSettings = [];

    try {
        $providerSettings = $localRepository->providerSettings();
    } catch (\Throwable) {
        // O banco complementar pode ainda nao existir durante a primeira instalacao.
        $providerSettings = [];
    }

    $provider = null;
    try {
        $provider = $localRepository->currentProvider();
    } catch (\Throwable) {
        $provider = null;
    }

    $operationalBase = Url::resolveOperationalBaseUrl(
        (string) $config->get('app.url', ''),
        is_array($provider) ? (string) ($provider['domain'] ?? '') : '',
        is_array($provider) ? (string) ($provider['base_path'] ?? '') : ''
    );
    Url::setApplicationUrl((string) ($operationalBase['url'] ?? 'http://localhost:8000'));

    $providerDisplayName = 'nossa equipe';
    if (is_array($provider) && trim((string) ($provider['name'] ?? '')) !== '') {
        $candidate = trim((string) $provider['name']);
        if (!in_array(strtolower($candidate), ['isp auxiliar', 'provedor', 'nossa equipe'], true)) {
            $providerDisplayName = $candidate;
        }
    }

    if ($providerDisplayName === 'nossa equipe') {
        $candidate = trim((string) $config->get('app.name', ''));
        if ($candidate !== '' && !in_array(strtolower($candidate), ['isp auxiliar', 'provedor', 'nossa equipe'], true)) {
            $providerDisplayName = $candidate;
        }
    }

    $setting = static function (string $key, string $envKey, string $default = '') use ($providerSettings): string {
        $saved = trim((string) ($providerSettings[$key] ?? ''));

        return $saved !== '' ? $saved : (string) Env::get($envKey, $default);
    };

    $container->set(Config::class, $config);
    $container->set(View::class, new View((string) $config->get('paths.views')));
    $container->set(Database::class, $localDatabase);
    $container->set(LocalRepository::class, $localRepository);
    $container->set(ContractRepository::class, new ContractRepository($localDatabase));
    $container->set(ContractAcceptanceRepository::class, new ContractAcceptanceRepository($localDatabase));
    $container->set(FinancialTaskRepository::class, new FinancialTaskRepository($localDatabase));
    $container->set(MessageTemplateRepository::class, new MessageTemplateRepository($localDatabase));
    $container->set(NotificationLogRepository::class, new NotificationLogRepository($localDatabase));
    $container->set(EvotrixService::class, new EvotrixService(
        (array) $config->get('evotrix', []),
        $container->get(NotificationLogRepository::class)
    ));
    $container->set(EmailService::class, new EmailService(
        array_replace((array) $config->get('email', []), [
            'provider_name' => $providerDisplayName,
        ]),
        $container->get(NotificationLogRepository::class)
    ));
    $container->set(MkAuthClient::class, new MkAuthClient(
        $setting('mkauth_base_url', 'MKAUTH_BASE_URL'),
        $setting('mkauth_api_token', 'MKAUTH_API_TOKEN'),
        $setting('mkauth_client_id', 'MKAUTH_CLIENT_ID'),
        $setting('mkauth_client_secret', 'MKAUTH_CLIENT_SECRET')
    ));
    $container->set(MkAuthDatabase::class, new MkAuthDatabase(
        $setting('mkauth_db_host', 'MKAUTH_DB_HOST'),
        $setting('mkauth_db_port', 'MKAUTH_DB_PORT', '3306'),
        $setting('mkauth_db_name', 'MKAUTH_DB_NAME'),
        $setting('mkauth_db_user', 'MKAUTH_DB_USER'),
        $setting('mkauth_db_password', 'MKAUTH_DB_PASSWORD'),
        $setting('mkauth_db_charset', 'MKAUTH_DB_CHARSET', 'utf8mb4'),
        $setting('mkauth_db_hash_algos', 'MKAUTH_DB_HASH_ALGOS', 'sha256,sha1')
    ));
    $container->set(ClientPayloadMapper::class, new ClientPayloadMapper());
    $container->set(ClientProvisioner::class, new ClientProvisioner(
        $container->get(ClientPayloadMapper::class),
        $container->get(MkAuthClient::class)
    ));
    $container->set(MkAuthTicketService::class, new MkAuthTicketService(
        (array) $config->get('contracts.mkauth_ticket', []),
        $setting('mkauth_base_url', 'MKAUTH_BASE_URL'),
        $container->get(MkAuthDatabase::class),
        $setting('mkauth_api_token', 'MKAUTH_API_TOKEN'),
        $setting('mkauth_client_id', 'MKAUTH_CLIENT_ID'),
        $setting('mkauth_client_secret', 'MKAUTH_CLIENT_SECRET')
    ));

    $router = new Router();
    $registerRoutes = require __DIR__ . '/../routes.php';
    $registerRoutes($router);

    return new Application($config, $container, $router);
}
