<?php

declare(strict_types=1);

namespace App\Infrastructure\Contracts;

use App\Infrastructure\Database\Database;

/**
 * Repositorio base para contratos do ISP Auxiliar.
 *
 * Nesta fase ele apenas concentra as operacoes de leitura e escrita dos
 * contratos, sem acoplar o fluxo atual de cadastro.
 */
final class ContractRepository
{
    public function __construct(private Database $database)
    {
    }

    public function create(array $data): ?int
    {
        $this->database->execute(
            'INSERT INTO client_contracts
                (client_id, mkauth_login, technician_name, technician_login, nome_cliente, telefone_cliente, tipo_adesao, valor_adesao, parcelas_adesao, valor_parcela_adesao, vencimento_primeira_parcela, fidelidade_meses, beneficio_valor, multa_total, tipo_aceite, observacao_adesao, upgrade_snapshot_json, status_financeiro, lifecycle_status, supersedes_contract_id, superseded_by_contract_id, revision_number, cancellation_reason, cancelled_at, cancelled_by_user_id, cancelled_by_login, created_at, updated_at)
             VALUES
                (:client_id, :mkauth_login, :technician_name, :technician_login, :nome_cliente, :telefone_cliente, :tipo_adesao, :valor_adesao, :parcelas_adesao, :valor_parcela_adesao, :vencimento_primeira_parcela, :fidelidade_meses, :beneficio_valor, :multa_total, :tipo_aceite, :observacao_adesao, :upgrade_snapshot_json, :status_financeiro, :lifecycle_status, :supersedes_contract_id, :superseded_by_contract_id, :revision_number, :cancellation_reason, :cancelled_at, :cancelled_by_user_id, :cancelled_by_login, NOW(), NOW())',
            $this->normalizeData($data)
        );

        return $this->database->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM client_contracts WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    public function findByClientId(int $clientId): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM client_contracts WHERE client_id = :client_id ORDER BY updated_at DESC, id DESC LIMIT 1',
            ['client_id' => $clientId]
        );
    }

    public function findByLogin(string $login): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM client_contracts WHERE LOWER(mkauth_login) = LOWER(:login) ORDER BY updated_at DESC, id DESC LIMIT 1',
            ['login' => trim($login)]
        );
    }

    public function listByLogin(string $login, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        return $this->database->fetchAll(
            'SELECT * FROM client_contracts WHERE LOWER(mkauth_login) = LOWER(:login) ORDER BY updated_at DESC, id DESC LIMIT ' . (int) $limit,
            ['login' => trim($login)]
        );
    }

    public function updateById(int $id, array $data): int
    {
        return $this->database->execute(
            'UPDATE client_contracts
             SET client_id = :client_id,
                 mkauth_login = :mkauth_login,
                 technician_name = :technician_name,
                 technician_login = :technician_login,
                 nome_cliente = :nome_cliente,
                 telefone_cliente = :telefone_cliente,
                 tipo_adesao = :tipo_adesao,
                 valor_adesao = :valor_adesao,
                 parcelas_adesao = :parcelas_adesao,
                 valor_parcela_adesao = :valor_parcela_adesao,
                 vencimento_primeira_parcela = :vencimento_primeira_parcela,
                 fidelidade_meses = :fidelidade_meses,
                 beneficio_valor = :beneficio_valor,
                 multa_total = :multa_total,
                 tipo_aceite = :tipo_aceite,
                 observacao_adesao = :observacao_adesao,
                 upgrade_snapshot_json = :upgrade_snapshot_json,
                 status_financeiro = :status_financeiro,
                 lifecycle_status = :lifecycle_status,
                 supersedes_contract_id = :supersedes_contract_id,
                 superseded_by_contract_id = :superseded_by_contract_id,
                 revision_number = :revision_number,
                 cancellation_reason = :cancellation_reason,
                 cancelled_at = :cancelled_at,
                 cancelled_by_user_id = :cancelled_by_user_id,
                 cancelled_by_login = :cancelled_by_login,
                 updated_at = NOW()
             WHERE id = :id',
            array_merge(['id' => $id], $this->normalizeData($data))
        );
    }

    public function listByStatus(string $status, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

        return $this->database->fetchAll(
            'SELECT * FROM client_contracts WHERE status_financeiro = :status ORDER BY updated_at DESC, id DESC LIMIT ' . (int) $limit,
            ['status' => $status]
        );
    }

    public function updateStatus(int $id, string $status): int
    {
        return $this->database->execute(
            'UPDATE client_contracts SET status_financeiro = :status, updated_at = NOW() WHERE id = :id',
            [
                'id' => $id,
                'status' => $status,
            ]
        );
    }

    public function updateUpgradeSnapshot(int $id, array $snapshot): int
    {
        return $this->database->execute(
            'UPDATE client_contracts
             SET upgrade_snapshot_json = :upgrade_snapshot_json,
                 updated_at = NOW()
             WHERE id = :id AND tipo_aceite = "upgrade_migracao"',
            [
                'id' => $id,
                'upgrade_snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ]
        );
    }

    public function markLifecycle(int $id, string $status, string $reason, ?int $userId, string $userLogin, ?int $supersededByContractId = null): int
    {
        return $this->database->execute(
            'UPDATE client_contracts
             SET lifecycle_status = :lifecycle_status,
                 cancellation_reason = :cancellation_reason,
                 cancelled_at = NOW(),
                 cancelled_by_user_id = :cancelled_by_user_id,
                 cancelled_by_login = :cancelled_by_login,
                 superseded_by_contract_id = :superseded_by_contract_id,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'id' => $id,
                'lifecycle_status' => $status,
                'cancellation_reason' => trim($reason),
                'cancelled_by_user_id' => $userId,
                'cancelled_by_login' => trim($userLogin) !== '' ? trim($userLogin) : null,
                'superseded_by_contract_id' => $supersededByContractId,
            ]
        );
    }

    private function normalizeData(array $data): array
    {
        return [
            'client_id' => $data['client_id'] ?? null,
            'mkauth_login' => (string) ($data['mkauth_login'] ?? ''),
            'technician_name' => $this->normalizeNullableString($data['technician_name'] ?? null),
            'technician_login' => $this->normalizeNullableString($data['technician_login'] ?? null),
            'nome_cliente' => (string) ($data['nome_cliente'] ?? ''),
            'telefone_cliente' => (string) ($data['telefone_cliente'] ?? ''),
            'tipo_adesao' => (string) ($data['tipo_adesao'] ?? 'cheia'),
            'valor_adesao' => $this->normalizeAmount($data['valor_adesao'] ?? '0.00'),
            'parcelas_adesao' => (string) ($data['parcelas_adesao'] ?? '1'),
            'valor_parcela_adesao' => $this->normalizeAmount($data['valor_parcela_adesao'] ?? '0.00'),
            'vencimento_primeira_parcela' => $data['vencimento_primeira_parcela'] ?? null,
            'fidelidade_meses' => (string) ($data['fidelidade_meses'] ?? '12'),
            'beneficio_valor' => $this->normalizeAmount($data['beneficio_valor'] ?? '0.00'),
            'multa_total' => $this->normalizeAmount($data['multa_total'] ?? '0.00'),
            'tipo_aceite' => (string) ($data['tipo_aceite'] ?? 'nova_instalacao'),
            'observacao_adesao' => (string) ($data['observacao_adesao'] ?? ''),
            'upgrade_snapshot_json' => $this->normalizeNullableString($data['upgrade_snapshot_json'] ?? null),
            'status_financeiro' => (string) ($data['status_financeiro'] ?? 'pendente_lancamento'),
            'lifecycle_status' => (string) ($data['lifecycle_status'] ?? 'active'),
            'supersedes_contract_id' => isset($data['supersedes_contract_id']) && (int) $data['supersedes_contract_id'] > 0 ? (int) $data['supersedes_contract_id'] : null,
            'superseded_by_contract_id' => isset($data['superseded_by_contract_id']) && (int) $data['superseded_by_contract_id'] > 0 ? (int) $data['superseded_by_contract_id'] : null,
            'revision_number' => max(1, (int) ($data['revision_number'] ?? 1)),
            'cancellation_reason' => $this->normalizeNullableString($data['cancellation_reason'] ?? null),
            'cancelled_at' => $this->normalizeNullableString($data['cancelled_at'] ?? null),
            'cancelled_by_user_id' => isset($data['cancelled_by_user_id']) && (int) $data['cancelled_by_user_id'] > 0 ? (int) $data['cancelled_by_user_id'] : null,
            'cancelled_by_login' => $this->normalizeNullableString($data['cancelled_by_login'] ?? null),
        ];
    }

    private function normalizeAmount(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
