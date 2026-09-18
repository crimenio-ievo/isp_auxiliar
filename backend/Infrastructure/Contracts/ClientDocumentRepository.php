<?php

declare(strict_types=1);

namespace App\Infrastructure\Contracts;

use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;

final class ClientDocumentRepository
{
    public function __construct(private Database $database, private LocalRepository $localRepository)
    {
    }

    public function create(array $data): int
    {
        $this->database->execute(
            'INSERT INTO client_documents
                (provider_id, mkauth_login, contract_id, document_type, status, original_name,
                 storage_path, mime_type, size_bytes, sha256, page_count, metadata_json,
                 created_by_user_id, created_by_login, created_at, updated_at)
             VALUES
                (:provider_id, :mkauth_login, :contract_id, :document_type, :status, :original_name,
                 :storage_path, :mime_type, :size_bytes, :sha256, :page_count, :metadata_json,
                 :created_by_user_id, :created_by_login, NOW(), NOW())',
            [
                'provider_id' => $this->providerId(),
                'mkauth_login' => strtolower(trim((string) ($data['mkauth_login'] ?? ''))),
                'contract_id' => isset($data['contract_id']) && (int) $data['contract_id'] > 0 ? (int) $data['contract_id'] : null,
                'document_type' => (string) ($data['document_type'] ?? 'printed_contract'),
                'status' => (string) ($data['status'] ?? 'final'),
                'original_name' => trim((string) ($data['original_name'] ?? '')) ?: null,
                'storage_path' => (string) ($data['storage_path'] ?? ''),
                'mime_type' => (string) ($data['mime_type'] ?? 'application/pdf'),
                'size_bytes' => max(0, (int) ($data['size_bytes'] ?? 0)),
                'sha256' => (string) ($data['sha256'] ?? ''),
                'page_count' => max(1, (int) ($data['page_count'] ?? 1)),
                'metadata_json' => json_encode($data['metadata'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                'created_by_user_id' => isset($data['created_by_user_id']) ? (int) $data['created_by_user_id'] : null,
                'created_by_login' => trim((string) ($data['created_by_login'] ?? '')) ?: null,
            ]
        );
        return $this->database->lastInsertId();
    }

    public function listByLogin(string $login): array
    {
        return $this->database->fetchAll(
            'SELECT * FROM client_documents WHERE provider_id = :provider_id AND mkauth_login = :login ORDER BY id DESC',
            ['provider_id' => $this->providerId(), 'login' => strtolower(trim($login))]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM client_documents WHERE id = :id AND provider_id = :provider_id LIMIT 1',
            ['id' => $id, 'provider_id' => $this->providerId()]
        );
    }

    private function providerId(): int
    {
        $id = (int) ($this->localRepository->currentProviderId() ?? 0);
        if ($id <= 0) throw new \RuntimeException('Provedor não identificado.');
        return $id;
    }
}
