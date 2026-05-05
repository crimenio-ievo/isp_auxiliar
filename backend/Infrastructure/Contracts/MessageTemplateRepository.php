<?php

declare(strict_types=1);

namespace App\Infrastructure\Contracts;

use App\Infrastructure\Database\Database;

/**
 * Repositorio de templates de mensagem.
 *
 * Mantem os textos padrao isolados para que a comunicacao futura fique
 * centralizada e versionavel.
 */
final class MessageTemplateRepository
{
    public function __construct(private Database $database)
    {
    }

    public function create(array $data): ?int
    {
        $this->database->execute(
            'INSERT INTO message_templates
                (name, channel, purpose, body, variables_json, active, created_at, updated_at)
             VALUES
                (:name, :channel, :purpose, :body, :variables_json, :active, NOW(), NOW())',
            $this->normalizeData($data)
        );

        return $this->database->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM message_templates WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    public function findByName(string $name, string $channel = 'whatsapp'): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM message_templates WHERE name = :name AND channel = :channel LIMIT 1',
            [
                'name' => trim($name),
                'channel' => trim($channel) ?: 'whatsapp',
            ]
        );
    }

    public function listActive(?string $channel = null): array
    {
        if ($channel === null || trim($channel) === '') {
            return $this->database->fetchAll(
                'SELECT * FROM message_templates WHERE active = 1 ORDER BY name ASC'
            );
        }

        return $this->database->fetchAll(
            'SELECT * FROM message_templates WHERE active = 1 AND channel = :channel ORDER BY name ASC',
            ['channel' => trim($channel)]
        );
    }

    public function updateByName(string $name, array $data, string $channel = 'whatsapp'): int
    {
        return $this->database->execute(
            'UPDATE message_templates
             SET purpose = :purpose,
                 body = :body,
                 variables_json = :variables_json,
                 active = :active,
                 updated_at = NOW()
             WHERE name = :name AND channel = :channel',
            array_merge(
                [
                    'name' => trim($name),
                    'channel' => trim($channel) ?: 'whatsapp',
                ],
                $this->normalizeData($data, false)
            )
        );
    }

    private function normalizeData(array $data, bool $includeName = true): array
    {
        $normalized = [
            'channel' => (string) ($data['channel'] ?? 'whatsapp'),
            'purpose' => (string) ($data['purpose'] ?? ''),
            'body' => (string) ($data['body'] ?? ''),
            'variables_json' => $this->normalizeJson($data['variables_json'] ?? null),
            'active' => isset($data['active']) ? (int) (bool) $data['active'] : 1,
        ];

        if ($includeName) {
            $normalized['name'] = (string) ($data['name'] ?? '');
        }

        return $normalized;
    }

    private function normalizeJson(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
    }
}
