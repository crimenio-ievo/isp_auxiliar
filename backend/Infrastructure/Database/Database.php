<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Core\Config;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Conexão local do ISP Auxiliar.
 *
 * Este banco não substitui o MkAuth. Ele guarda dados complementares do
 * sistema auxiliar: provedores, usuários gestores, configurações, evidências,
 * logs e índices pesquisáveis.
 */
final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private Config $config)
    {
    }

    public function isConfigured(): bool
    {
        return (string) $this->config->get('database.database', '') !== ''
            && (string) $this->config->get('database.username', '') !== '';
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        if (!$this->isConfigured()) {
            throw new RuntimeException('Banco local do ISP Auxiliar não configurado.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            (string) $this->config->get('database.host', '127.0.0.1'),
            (string) $this->config->get('database.port', '3306'),
            (string) $this->config->get('database.database', 'isp_auxiliar'),
            (string) $this->config->get('database.charset', 'utf8mb4')
        );

        try {
            $this->pdo = new PDO(
                $dsn,
                (string) $this->config->get('database.username', ''),
                (string) $this->config->get('database.password', ''),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $exception) {
            throw new RuntimeException('Falha ao conectar no banco local: ' . $exception->getMessage(), 0, $exception);
        }

        return $this->pdo;
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }
}
