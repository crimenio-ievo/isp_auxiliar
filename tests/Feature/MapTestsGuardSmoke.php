<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
require $root . '/backend/bootstrap/app.php';
require $root . '/tests/support/ScratchSchema.php';
require $root . '/tests/map/guard_real_db.php';
use Tests\Support\ScratchSchema;

$fails = 0;
$check = function (bool $cond, string $label) use (&$fails): void {
    echo ($cond ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
    if (!$cond) { $fails++; }
};
$schema = (new ScratchSchema($root, 'mapguard'))->create();
try {
    $pdo = $schema->database->pdo();
    $pdo->exec("INSERT INTO providers (name,slug,status,created_at,updated_at) VALUES ('GUARD','guard','active',NOW(),NOW())");
    $pid = (int) $pdo->lastInsertId();
    $ins = $pdo->prepare("INSERT INTO map_nodes (provider_id,node_type,name,status,lifecycle,created_at,updated_at) VALUES (?,'cto',?,'active','active',NOW(),NOW())");
    for ($i = 1; $i <= 10; $i++) { $ins->execute([$pid, "CTO real {$i}"]); }
    for ($i = 1; $i <= 5; $i++) { $ins->execute([$pid, "ZZ TESTE {$i}"]); }
    [$ok, $real] = mapTestsGuardCheck($pdo, false);
    $check($ok && $real === 10, '10 nós reais + 5 ZZ TESTE: permite');
    $ins->execute([$pid, 'CTO real 11']);
    [$ok, $real] = mapTestsGuardCheck($pdo, false);
    $check(!$ok && $real === 11, '11 nós reais: recusa');
    [$ok] = mapTestsGuardCheck($pdo, '0');
    $check(!$ok, 'MAP_TESTS_ALLOW_REAL_DB=0 não libera');
    [$ok] = mapTestsGuardCheck($pdo, '1');
    $check($ok, 'MAP_TESTS_ALLOW_REAL_DB=1 libera');
    $runner = (string) file_get_contents($root . '/tests/map/run_all.sh');
    $check(strpos($runner, 'php tests/map/guard_real_db.php || exit') < strpos($runner, 'for suite in'), 'run_all.sh chama a proteção antes das suítes');
} finally {
    $schema->drop();
}
echo $fails === 0 ? "OK: todos os testes passaram\n" : "HOUVE FALHAS: {$fails}\n";
exit($fails === 0 ? 0 : 1);
