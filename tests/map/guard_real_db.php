<?php
// Recusa rodar tests/map contra um banco com dados reais (mais de 10 nós que não são "ZZ TESTE"),
// salvo com MAP_TESTS_ALLOW_REAL_DB=1. Saída 0 = pode rodar; 2 = recusado; 1 = erro.
declare(strict_types=1);

/** @return array{0:bool,1:int} [pode rodar, nós reais] */
function mapTestsGuardCheck(PDO $pdo, $allow, int $limit = 10): array
{
    $real = (int) $pdo->query("SELECT COUNT(*) FROM map_nodes WHERE name NOT LIKE 'ZZ TESTE%'")->fetchColumn();
    return [$real <= $limit || $allow === '1', $real];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return; // incluído por teste
}
require dirname(__DIR__, 2).'/backend/bootstrap/app.php';
try {
    $app = bootstrapApplication();
    $pdo = (new App\Infrastructure\Database\Database($app->config()))->pdo();
    [$ok, $real] = mapTestsGuardCheck($pdo, getenv('MAP_TESTS_ALLOW_REAL_DB'));
} catch (Throwable $e) {
    fwrite(STDERR, "Proteção: não foi possível contar os nós do mapa.\n");
    exit(1);
}
if (!$ok) {
    fwrite(STDERR, "RECUSADO: o banco tem {$real} nós reais (limite 10). Estes testes gravam nós \"ZZ TESTE\" no banco configurado.\n"
        ."Rode contra um banco de teste ou, ciente do risco, use MAP_TESTS_ALLOW_REAL_DB=1.\n");
    exit(2);
}
echo "Proteção: {$real} nós reais (limite 10) — ok.\n";
exit(0);
