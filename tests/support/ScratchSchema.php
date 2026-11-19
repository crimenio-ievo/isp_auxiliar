<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Config;
use App\Infrastructure\Database\Database;
use PDO;
use RuntimeException;

/**
 * Schema MySQL descartavel para os testes (padrao do projeto: nunca escrever no banco real).
 * Cria `isp_auxiliar_<nome>_sim`, aplica TODAS as migrations de database/migrations em ordem e remove no fim.
 * Se o schema ja existir, recusa e para (nao apaga nada que nao tenha criado).
 */
final class ScratchSchema
{
    public PDO $pdo;
    public Database $database;
    public string $schema;
    private bool $created = false;

    public function __construct(private string $root, string $name)
    {
        $this->schema = 'isp_auxiliar_' . preg_replace('/[^a-z0-9_]/', '', strtolower($name)) . '_sim';
        $this->pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function create(?int $upToVersion = null): self
    {
        $exists = $this->pdo->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='{$this->schema}'")->fetchColumn();
        if ($exists) {
            throw new RuntimeException('Schema descartavel ja existe: preservar e parar (' . $this->schema . ').');
        }
        $this->pdo->exec("CREATE DATABASE `{$this->schema}`");
        $this->created = true;
        $this->pdo->exec("USE `{$this->schema}`");
        $files = glob($this->root . '/database/migrations/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            if ($upToVersion !== null && (int) basename($file) > $upToVersion) {
                continue;
            }
            $this->applyFile($file);
        }
        $this->database = new Database(new Config(['database' => [
            'host' => 'localhost', 'port' => '3306', 'database' => $this->schema,
            'username' => 'root', 'password' => '', 'charset' => 'utf8mb4',
        ]]));

        return $this;
    }

    public function applyFile(string $file): void
    {
        $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
        foreach (explode(';', (string) $sql) as $statement) {
            if (trim($statement) !== '') {
                $this->pdo->exec($statement);
            }
        }
    }

    public function drop(): void
    {
        if ($this->created) {
            $this->pdo->exec("DROP DATABASE IF EXISTS `{$this->schema}`");
            $this->created = false;
        }
    }

    public function providerId(): int
    {
        return (int) $this->pdo->query("SELECT id FROM `{$this->schema}`.providers ORDER BY id LIMIT 1")->fetchColumn();
    }
}
