<?php

declare(strict_types=1);

namespace App\Infrastructure\Contracts;

use App\Infrastructure\Database\Database;

/**
 * Repositorio de aceitações digitais de contrato.
 *
 * Nesta fase ele apenas concentra o acesso ao banco para a futura camada
 * de aceite digital e trilha de evidências.
 */
final class ContractAcceptanceRepository
{
    public function __construct(private Database $database)
    {
    }

    public function create(array $data): ?int
    {
        $this->database->execute(
            'INSERT INTO contract_acceptances
                (contract_id, technician_name, technician_login, token_hash, token_expires_at, status, telefone_enviado, whatsapp_message_id, sent_at, accepted_at, ip_address, user_agent, termo_versao, termo_hash, pdf_path, evidence_json_path, created_at, updated_at)
             VALUES
                (:contract_id, :technician_name, :technician_login, :token_hash, :token_expires_at, :status, :telefone_enviado, :whatsapp_message_id, :sent_at, :accepted_at, :ip_address, :user_agent, :termo_versao, :termo_hash, :pdf_path, :evidence_json_path, NOW(), NOW())',
            $this->normalizeData($data)
        );

        return $this->database->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM contract_acceptances WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    public function findLatestByContractId(int $contractId): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM contract_acceptances WHERE contract_id = :contract_id ORDER BY updated_at DESC, id DESC LIMIT 1',
            ['contract_id' => $contractId]
        );
    }

    public function findByTokenHash(string $tokenHash): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM contract_acceptances WHERE token_hash = :token_hash LIMIT 1',
            ['token_hash' => $this->normalizeTokenHash($tokenHash)]
        );
    }

    public function updateById(int $id, array $data): int
    {
        return $this->database->execute(
            'UPDATE contract_acceptances
             SET contract_id = :contract_id,
                 technician_name = :technician_name,
                 technician_login = :technician_login,
                 token_hash = :token_hash,
                 token_expires_at = :token_expires_at,
                 status = :status,
                 telefone_enviado = :telefone_enviado,
                 whatsapp_message_id = :whatsapp_message_id,
                 sent_at = :sent_at,
                 accepted_at = :accepted_at,
                 ip_address = :ip_address,
                 user_agent = :user_agent,
                 termo_versao = :termo_versao,
                 termo_hash = :termo_hash,
                 pdf_path = :pdf_path,
                 evidence_json_path = :evidence_json_path,
                 updated_at = NOW()
             WHERE id = :id',
            array_merge(['id' => $id], $this->normalizeData($data))
        );
    }

    public function listByContractId(int $contractId): array
    {
        return $this->database->fetchAll(
            'SELECT * FROM contract_acceptances WHERE contract_id = :contract_id ORDER BY id DESC',
            ['contract_id' => $contractId]
        );
    }

    public function updateStatus(int $id, string $status): int
    {
        return $this->database->execute(
            'UPDATE contract_acceptances SET status = :status, updated_at = NOW() WHERE id = :id',
            [
                'id' => $id,
                'status' => $status,
            ]
        );
    }

    public function markSent(int $id, ?string $whatsappMessageId = null, ?string $sentAt = null): int
    {
        return $this->database->execute(
            'UPDATE contract_acceptances
             SET status = "enviado",
                 whatsapp_message_id = :whatsapp_message_id,
                 sent_at = :sent_at,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'id' => $id,
                'whatsapp_message_id' => $whatsappMessageId !== null ? trim($whatsappMessageId) : null,
                'sent_at' => $sentAt !== null && trim($sentAt) !== '' ? $sentAt : date('Y-m-d H:i:s'),
            ]
        );
    }

    public function markAccepted(
        int $id,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $acceptedAt = null
    ): int {
        return $this->database->execute(
            'UPDATE contract_acceptances
             SET status = "aceito",
                 accepted_at = :accepted_at,
                 ip_address = :ip_address,
                 user_agent = :user_agent,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'id' => $id,
                'accepted_at' => $acceptedAt !== null && trim($acceptedAt) !== '' ? $acceptedAt : date('Y-m-d H:i:s'),
                'ip_address' => $ipAddress !== null ? trim($ipAddress) : null,
                'user_agent' => $userAgent !== null ? trim($userAgent) : null,
            ]
        );
    }

    public function markExpired(int $id): int
    {
        return $this->database->execute(
            'UPDATE contract_acceptances SET status = "expirado", updated_at = NOW() WHERE id = :id',
            ['id' => $id]
        );
    }

    public function cancel(int $id): int
    {
        return $this->database->execute(
            'UPDATE contract_acceptances SET status = "cancelado", updated_at = NOW() WHERE id = :id',
            ['id' => $id]
        );
    }

    private function normalizeData(array $data): array
    {
        return [
            'contract_id' => $data['contract_id'] ?? null,
            'technician_name' => $this->normalizeNullableString($data['technician_name'] ?? null),
            'technician_login' => $this->normalizeNullableString($data['technician_login'] ?? null),
            'token_hash' => $this->normalizeTokenHash((string) ($data['token_hash'] ?? $data['token'] ?? '')),
            'token_expires_at' => (string) ($data['token_expires_at'] ?? date('Y-m-d H:i:s')),
            'status' => (string) ($data['status'] ?? 'criado'),
            'telefone_enviado' => trim((string) ($data['telefone_enviado'] ?? '')),
            'whatsapp_message_id' => $data['whatsapp_message_id'] ?? null,
            'sent_at' => $data['sent_at'] ?? null,
            'accepted_at' => $data['accepted_at'] ?? null,
            'ip_address' => $data['ip_address'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
            'termo_versao' => (string) ($data['termo_versao'] ?? ''),
            'termo_hash' => (string) ($data['termo_hash'] ?? ''),
            'pdf_path' => $data['pdf_path'] ?? null,
            'evidence_json_path' => $data['evidence_json_path'] ?? null,
        ];
    }

    private function normalizeTokenHash(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/^[a-f0-9]{64}$/i', $value) === 1) {
            return strtolower($value);
        }

        return hash('sha256', $value);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
