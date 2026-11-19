<?php

declare(strict_types=1);

// Migra uma credencial NET_CRED_* do .env para o banco (cifrada) e aponta os equipamentos que a usam.
// Uso: php scripts/net_migrate_credentials.php --ref=NET_CRED_OLT_89_COMMUNITY --name="OLT 89" [--apply]
// Sem --apply é só simulação. Requer NET_SECRET_KEY (scripts/net_secret_key.php) e a migration 046. Nunca imprime o segredo.

use App\Core\Env;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Net\NetCredentialMigrator;
use App\Infrastructure\Net\NetCredentialRepository;
use App\Infrastructure\Net\NetSecretBox;

require dirname(__DIR__) . '/backend/bootstrap/app.php';

$opts = getopt('', ['ref:', 'name:', 'apply']);
$ref = (string) ($opts['ref'] ?? 'NET_CRED_OLT_89_COMMUNITY');
$name = (string) ($opts['name'] ?? 'OLT 89');
try {
    $app = bootstrapApplication();
    $pdo = (new Database($app->config()))->pdo();
    $report = (new NetCredentialMigrator($pdo, new NetCredentialRepository($pdo, NetSecretBox::fromEnv())))
        ->run($ref, $name, isset($opts['apply']), static fn (string $n): string => (string) (Env::get($n, '') ?? ''));
} catch (Throwable) {
    fwrite(STDERR, "Não foi possível executar (banco ou configuração indisponível).\n");
    exit(1);
}
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
exit($report['ok'] ? 0 : 2);
