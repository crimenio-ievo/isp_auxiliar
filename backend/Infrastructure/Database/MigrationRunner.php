<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

/**
 * Executor simples de migrations SQL.
 */
final class MigrationRunner
{
    public function __construct(
        private Database $database,
        private string $migrationsPath
    ) {
    }

    public function run(): array
    {
        $this->ensureMigrationTable();
        $executed = $this->executedVersions();
        $ran = [];

        foreach (glob(rtrim($this->migrationsPath, '/') . '/*.sql') ?: [] as $file) {
            $version = basename($file);

            if (in_array($version, $executed, true)) {
                continue;
            }

            $sql = trim((string) file_get_contents($file));

            if ($sql === '') {
                continue;
            }

            try {
                foreach ($this->splitStatements($sql) as $statement) {
                    $this->database->pdo()->exec($statement);
                }

                $this->database->execute(
                    'INSERT INTO schema_migrations (version, executed_at) VALUES (:version, NOW())',
                    ['version' => $version]
                );
                $ran[] = $version;
            } catch (\Throwable $exception) {
                if ($this->database->pdo()->inTransaction()) {
                    $this->database->pdo()->rollBack();
                }

                throw $exception;
            }
        }

        return $ran;
    }

    private function ensureMigrationTable(): void
    {
        $this->database->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(100) NOT NULL PRIMARY KEY,
                executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function executedVersions(): array
    {
        $rows = $this->database->fetchAll('SELECT version FROM schema_migrations ORDER BY version ASC');

        return array_map(static fn (array $row): string => (string) $row['version'], $rows);
    }

    private function splitStatements(string $sql): array
    {
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            static fn (string $statement): bool => $statement !== ''
        );

        return array_values($statements);
    }
}
