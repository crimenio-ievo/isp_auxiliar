<?php

declare(strict_types=1);

namespace App\Infrastructure\Contracts;

use App\Infrastructure\Database\Database;

/**
 * Repositorio de logs de notificacao.
 *
 * Nesta fase ele apenas grava os eventos simulados e prepara o histórico.
 */
final class NotificationLogRepository
{
    public function __construct(private Database $database)
    {
    }

    public function create(array $data): ?int
    {
        $this->database->execute(
            'INSERT INTO notification_logs
                (contract_id, acceptance_id, channel, provider, recipient, message, status, provider_response, created_at)
             VALUES
                (:contract_id, :acceptance_id, :channel, :provider, :recipient, :message, :status, :provider_response, NOW())',
            $this->normalizeData($data)
        );

        return $this->database->lastInsertId();
    }

    public function listByContractId(int $contractId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

        return $this->database->fetchAll(
            'SELECT * FROM notification_logs WHERE contract_id = :contract_id ORDER BY id DESC LIMIT ' . (int) $limit,
            ['contract_id' => $contractId]
        );
    }

    public function listByAcceptanceId(int $acceptanceId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

        return $this->database->fetchAll(
            'SELECT * FROM notification_logs WHERE acceptance_id = :acceptance_id ORDER BY id DESC LIMIT ' . (int) $limit,
            ['acceptance_id' => $acceptanceId]
        );
    }

    private function normalizeData(array $data): array
    {
        return [
            'contract_id' => $data['contract_id'] ?? null,
            'acceptance_id' => $data['acceptance_id'] ?? null,
            'channel' => (string) ($data['channel'] ?? 'whatsapp'),
            'provider' => (string) ($data['provider'] ?? 'evotrix'),
            'recipient' => (string) ($data['recipient'] ?? ''),
            'message' => (string) ($data['message'] ?? ''),
            'status' => (string) ($data['status'] ?? 'simulado'),
            'provider_response' => $this->normalizeResponse($data['provider_response'] ?? null),
        ];
    }

    private function normalizeResponse(mixed $response): ?string
    {
        if ($response === null || $response === '') {
            return null;
        }

        if (is_string($response)) {
            return $response;
        }

        return json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
    }
}
