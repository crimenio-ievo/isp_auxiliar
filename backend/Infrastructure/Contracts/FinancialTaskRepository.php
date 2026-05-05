<?php

declare(strict_types=1);

namespace App\Infrastructure\Contracts;

use App\Infrastructure\Database\Database;

/**
 * Repositorio de tarefas financeiras associadas a contratos.
 *
 * Ele existe para preparar a central financeira sem interferir no fluxo atual.
 */
final class FinancialTaskRepository
{
    public function __construct(private Database $database)
    {
    }

    public function create(array $data): ?int
    {
        $this->database->execute(
            'INSERT INTO financial_tasks
                (contract_id, mkauth_login, titulo, descricao, setor, status, created_at, updated_at)
             VALUES
                (:contract_id, :mkauth_login, :titulo, :descricao, :setor, :status, NOW(), NOW())',
            $this->normalizeData($data)
        );

        return $this->database->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM financial_tasks WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    public function findByContractId(int $contractId): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM financial_tasks WHERE contract_id = :contract_id ORDER BY id DESC LIMIT 1',
            ['contract_id' => $contractId]
        );
    }

    public function listByStatus(string $status, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

        return $this->database->fetchAll(
            'SELECT * FROM financial_tasks WHERE status = :status ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit,
            ['status' => $status]
        );
    }

    public function updateStatus(int $id, string $status): int
    {
        return $this->database->execute(
            'UPDATE financial_tasks SET status = :status, updated_at = NOW() WHERE id = :id',
            [
                'id' => $id,
                'status' => $status,
            ]
        );
    }

    private function normalizeData(array $data): array
    {
        return [
            'contract_id' => $data['contract_id'] ?? null,
            'mkauth_login' => (string) ($data['mkauth_login'] ?? ''),
            'titulo' => (string) ($data['titulo'] ?? ''),
            'descricao' => (string) ($data['descricao'] ?? ''),
            'setor' => (string) ($data['setor'] ?? 'financeiro'),
            'status' => (string) ($data['status'] ?? 'aberto'),
        ];
    }
}
