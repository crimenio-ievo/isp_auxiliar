#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Infrastructure\Database\Database;

require dirname(__DIR__) . '/backend/bootstrap/app.php';

$app = bootstrapApplication();
$database = new Database($app->config());
$migrationsPath = dirname(__DIR__) . '/database/migrations';

try {
    $pdo = $database->pdo();
} catch (\Throwable $exception) {
    fwrite(STDERR, "Nao foi possivel conectar ao banco local: {$exception->getMessage()}\n");
    exit(1);
}

ensureSchemaMigrationsTable($pdo);
backfillLegacyMigrationMetadata($pdo);

$files = glob(rtrim($migrationsPath, '/') . '/*.sql') ?: [];
$appliedRows = loadAppliedMigrations($pdo);
$summary = [
    'aplicada' => [],
    'ignorada' => [],
    'aviso' => [],
    'erro' => [],
];

echo "ISP Auxiliar - aplicador oficial de migrations\n";
echo "Diretorio: {$migrationsPath}\n\n";

foreach ($files as $file) {
    $filename = basename($file);
    $checksum = hash_file('sha256', $file) ?: '';
    $existing = $appliedRows[$filename] ?? null;
    $existingChecksum = is_array($existing) ? trim((string) ($existing['checksum'] ?? '')) : '';

    if (is_array($existing)) {
        if ($existingChecksum !== '' && $existingChecksum !== $checksum) {
            $summary['aviso'][] = "{$filename} checksum diferente da aplicacao anterior; mantido como ignorado.";
            echo "[AVISO] {$filename} checksum diferente da instalacao anterior. Ignorado para evitar reaplicacao.\n";
            continue;
        }

        if ($existingChecksum === '') {
            updateMigrationRecord($pdo, $filename, $checksum);
            $summary['ignorada'][] = $filename;
            echo "[IGNORADA] {$filename} ja aplicada. Metadados atualizados.\n";
            continue;
        }

        $summary['ignorada'][] = $filename;
        echo "[IGNORADA] {$filename} ja aplicada.\n";
        continue;
    }

    $sql = trim((string) file_get_contents($file));
    if ($sql === '') {
        $summary['ignorada'][] = $filename;
        echo "[IGNORADA] {$filename} vazia.\n";
        continue;
    }

    $warningMessages = [];
    try {
        foreach (splitSqlStatements($sql) as $statement) {
            executeMigrationStatement($pdo, $statement, $warningMessages);
        }

        recordMigration($pdo, $filename, $checksum);

        if ($warningMessages !== []) {
            $summary['aplicada'][] = $filename . ' (com avisos)';
            echo "[APLICADA] {$filename} (com avisos)\n";
            foreach ($warningMessages as $warningMessage) {
                echo "  - AVISO: {$warningMessage}\n";
            }
        } else {
            $summary['aplicada'][] = $filename;
            echo "[APLICADA] {$filename}\n";
        }
    } catch (\Throwable $exception) {
        $message = $exception->getMessage();
        $summary['erro'][] = $filename . ' :: ' . $message;
        echo "[ERRO] {$filename} :: {$message}\n";
    }
}

echo "\nResumo final:\n";
echo ' - aplicadas: ' . count($summary['aplicada']) . "\n";
echo ' - ignoradas: ' . count($summary['ignorada']) . "\n";
echo ' - avisos: ' . count($summary['aviso']) . "\n";
echo ' - erros: ' . count($summary['erro']) . "\n";

if ($summary['erro'] !== []) {
    exit(1);
}

exit(0);

function ensureSchemaMigrationsTable(\PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(190) NOT NULL PRIMARY KEY,
            executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $columns = [
        'filename' => 'ALTER TABLE schema_migrations ADD COLUMN filename VARCHAR(190) NULL AFTER version',
        'checksum' => 'ALTER TABLE schema_migrations ADD COLUMN checksum CHAR(64) NULL AFTER filename',
        'applied_at' => 'ALTER TABLE schema_migrations ADD COLUMN applied_at DATETIME NULL AFTER checksum',
    ];

    foreach ($columns as $column => $sql) {
        if (!columnExists($pdo, 'schema_migrations', $column)) {
            $pdo->exec($sql);
        }
    }
}

function backfillLegacyMigrationMetadata(\PDO $pdo): void
{
    $pdo->exec(
        'UPDATE schema_migrations
         SET filename = COALESCE(NULLIF(filename, ""), version),
             applied_at = COALESCE(applied_at, executed_at, NOW())
         WHERE version IS NOT NULL AND version <> ""'
    );
}

function columnExists(\PDO $pdo, string $table, string $column): bool
{
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

function loadAppliedMigrations(\PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT version, filename, checksum, COALESCE(applied_at, executed_at) AS applied_at
         FROM schema_migrations
         ORDER BY COALESCE(applied_at, executed_at) ASC'
    )->fetchAll();

    $migrations = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        $filename = trim((string) ($row['filename'] ?? $row['version'] ?? ''));
        if ($filename === '') {
            continue;
        }

        $migrations[$filename] = [
            'version' => (string) ($row['version'] ?? $filename),
            'filename' => $filename,
            'checksum' => (string) ($row['checksum'] ?? ''),
            'applied_at' => (string) ($row['applied_at'] ?? ''),
        ];
    }

    return $migrations;
}

function updateMigrationRecord(\PDO $pdo, string $filename, string $checksum): void
{
    $statement = $pdo->prepare(
        'UPDATE schema_migrations
         SET filename = :filename,
             checksum = :checksum,
             applied_at = COALESCE(applied_at, executed_at, NOW())
         WHERE version = :version_filter OR filename = :filename_filter'
    );
    $statement->execute([
        'version_filter' => $filename,
        'filename_filter' => $filename,
        'filename' => $filename,
        'checksum' => $checksum,
    ]);
}

function recordMigration(\PDO $pdo, string $filename, string $checksum): void
{
    $statement = $pdo->prepare(
        'INSERT INTO schema_migrations (version, filename, checksum, applied_at, executed_at)
         VALUES (:version, :filename, :checksum, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
             filename = VALUES(filename),
             checksum = VALUES(checksum),
             applied_at = VALUES(applied_at),
             executed_at = VALUES(executed_at)'
    );
    $statement->execute([
        'version' => $filename,
        'filename' => $filename,
        'checksum' => $checksum,
    ]);
}

function splitSqlStatements(string $sql): array
{
    $cleanSql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $cleanSql = preg_replace('/^\s*#.*$/m', '', $cleanSql) ?? $cleanSql;
    $statements = array_filter(
        array_map('trim', explode(';', $cleanSql)),
        static fn (string $statement): bool => $statement !== ''
    );

    return array_values($statements);
}

function executeMigrationStatement(\PDO $pdo, string $statement, array &$warnings): void
{
    try {
        $pdo->exec($statement);
    } catch (\Throwable $exception) {
        if (isSafeSchemaError($exception)) {
            $warnings[] = $exception->getMessage();
            return;
        }

        throw $exception;
    }
}

function isSafeSchemaError(\Throwable $exception): bool
{
    $message = strtolower($exception->getMessage());
    $codes = ['1050', '1060', '1061', '1091', '42s01', '42s21'];

    if (str_contains($message, 'already exists')) {
        return true;
    }

    if (str_contains($message, 'duplicate column') || str_contains($message, 'duplicate key')) {
        return true;
    }

    $code = strtolower((string) $exception->getCode());
    return in_array($code, $codes, true);
}
