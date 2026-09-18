<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$auditor = $root . '/scripts/releases/migration_auditor.php';
require $auditor;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

$assert(!release_migration_is_compatible('DROP TABLE teste;'), 'DROP real não foi bloqueado.');
$assert(release_migration_is_compatible("-- DROP TABLE teste;\nCREATE TABLE teste (id INT);"), 'DROP em comentário -- foi bloqueado.');
$assert(release_migration_is_compatible('/* DROP TABLE teste; */ CREATE TABLE teste (id INT);'), 'DROP em comentário de bloco foi bloqueado.');
$assert(release_migration_is_compatible("# TRUNCATE TABLE teste;\nCREATE TABLE teste (id INT);"), 'Operação em comentário # foi bloqueada.');
$assert(!release_migration_is_compatible('ALTER TABLE teste DROP COLUMN campo;'), 'ALTER TABLE com DROP real não foi bloqueado.');
$assert(!release_migration_is_compatible('ALTER TABLE teste MODIFY campo BIGINT;'), 'ALTER TABLE com MODIFY real não foi bloqueado.');
$assert(!release_migration_is_compatible('DELETE FROM teste WHERE id = 1;'), 'DELETE FROM real não foi bloqueado.');
$assert(!release_migration_is_compatible('TRUNCATE TABLE teste;'), 'TRUNCATE real não foi bloqueado.');
$assert(!release_migration_is_compatible('RENAME TABLE teste TO teste_antigo;'), 'RENAME real não foi bloqueado.');
$assert(!release_migration_is_compatible('ALTER TABLE teste ADD COLUMN campo BIGINT NOT NULL;'), 'ADD COLUMN NOT NULL real não foi bloqueado.');
$assert(release_migration_is_compatible('ALTER TABLE teste ADD COLUMN campo BIGINT NOT NULL DEFAULT 1;'), 'ADD COLUMN com default não nulo seguro foi bloqueado.');
$assert(release_migration_is_compatible(
    <<<'SQL'
INSERT INTO auditoria (mensagem, detalhe)
VALUES ('DROP TABLE teste; -- texto', "DELETE FROM teste", 'it''s /* RENAME */');
SQL
), 'Palavra destrutiva dentro de string SQL gerou falso positivo.');
$assert(!release_migration_is_compatible('/*!50000 DROP TABLE teste */;'), 'Comentário executável MySQL escapou da auditoria.');

$migration016 = $root . '/database/migrations/016_operational_processes.sql';
$migration017 = $root . '/database/migrations/017_messages_and_scanned_contracts.sql';
$assert(release_migration_is_compatible((string) file_get_contents($migration016)), 'Migration 016 foi classificada como incompatível.');
$assert(release_migration_is_compatible((string) file_get_contents($migration017)), 'Migration 017 foi classificada como incompatível.');

$runAuditor = static function (string $file) use ($auditor): int {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($auditor) . ' ' . escapeshellarg($file) . ' 2>&1';
    exec($command, $output, $status);
    return $status;
};
$assert($runAuditor($migration016) === 0, 'CLI do auditor recusou a migration 016 real.');
$assert($runAuditor($migration017) === 0, 'CLI do auditor recusou a migration 017 real.');

$synthetic = tempnam(sys_get_temp_dir(), 'migration_auditor_');
if ($synthetic === false) {
    throw new RuntimeException('Não foi possível criar migration sintética.');
}

try {
    file_put_contents($synthetic, "CREATE TABLE teste (id INT);\nTRUNCATE TABLE teste;\n");
    $assert($runAuditor($synthetic) === 1, 'CLI do auditor aceitou migration sintética destrutiva.');
} finally {
    @unlink($synthetic);
}

echo 'MigrationAuditorSmoke OK - ' . $checks . " verificações; nenhum banco ou serviço externo acessado.\n";
