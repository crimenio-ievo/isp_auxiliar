<?php

declare(strict_types=1);

namespace App\Infrastructure\Contracts;

use App\Infrastructure\Database\Database;
use App\Infrastructure\Local\LocalRepository;

/**
 * Repositorio de templates de mensagem.
 *
 * Mantem os textos padrao isolados para que a comunicacao futura fique
 * centralizada e versionavel.
 */
final class MessageTemplateRepository
{
    public function __construct(private Database $database, private LocalRepository $localRepository)
    {
    }

    public function create(array $data): ?int
    {
        $this->database->execute(
            'INSERT INTO message_templates
                (provider_id, name, channel, purpose, description, subject, default_subject, body, default_body,
                 variables_json, supported_channels_json, enabled_channels_json, active, version,
                 updated_by_user_id, updated_by_login, created_at, updated_at)
             VALUES
                (:provider_id, :name, :channel, :purpose, :description, :subject, :default_subject, :body, :default_body,
                 :variables_json, :supported_channels_json, :enabled_channels_json, :active, :version,
                 :updated_by_user_id, :updated_by_login, NOW(), NOW())',
            $this->normalizeData($data)
        );

        return $this->database->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM message_templates
             WHERE id = :id AND (provider_id = :provider_id OR provider_id IS NULL)
             ORDER BY provider_id IS NULL ASC
             LIMIT 1',
            ['id' => $id, 'provider_id' => $this->providerId()]
        );
    }

    public function findByPurpose(string $purpose, string $channel = 'whatsapp'): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM message_templates
             WHERE (provider_id = :provider_id OR provider_id IS NULL)
               AND purpose = :purpose
               AND channel = :channel
             ORDER BY provider_id IS NULL ASC, id ASC
             LIMIT 1',
            [
                'provider_id' => $this->providerId(),
                'purpose' => trim($purpose),
                'channel' => trim($channel) ?: 'whatsapp',
            ]
        );
    }

    public function findByName(string $name, string $channel = 'whatsapp'): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM message_templates
             WHERE (provider_id = :provider_id OR provider_id IS NULL)
               AND name = :name
               AND channel = :channel
             ORDER BY provider_id IS NULL ASC, id ASC
             LIMIT 1',
            [
                'provider_id' => $this->providerId(),
                'name' => trim($name),
                'channel' => trim($channel) ?: 'whatsapp',
            ]
        );
    }

    public function upsertByName(string $name, array $data, string $channel = 'whatsapp'): ?int
    {
        $existing = $this->findByName($name, $channel);

        if (is_array($existing) && isset($existing['id'])) {
            $this->database->execute(
                'UPDATE message_templates
                 SET purpose = :purpose,
                     channel = :channel,
                     description = :description,
                     subject = :subject,
                     default_subject = :default_subject,
                     body = :body,
                     default_body = :default_body,
                     variables_json = :variables_json,
                     supported_channels_json = :supported_channels_json,
                     enabled_channels_json = :enabled_channels_json,
                     active = :active,
                     version = :version,
                     updated_by_user_id = :updated_by_user_id,
                     updated_by_login = :updated_by_login,
                     updated_at = NOW()
                 WHERE id = :id AND (provider_id = :provider_id OR provider_id IS NULL)',
                array_merge(
                    ['id' => (int) $existing['id']],
                    $this->normalizeData(array_merge($data, ['name' => $name, 'channel' => $channel]), false)
                )
            );

            return (int) $existing['id'];
        }

        return $this->create(array_merge($data, ['name' => $name, 'channel' => $channel]));
    }

    public function ensureDefaults(array $templates): array
    {
        $ids = [];

        foreach ($templates as $name => $template) {
            if (!is_array($template)) {
                continue;
            }

            $channel = (string) ($template['channel'] ?? 'whatsapp');
            $existing = $this->findByName((string) $name, $channel);
            $ids[$name] = is_array($existing) && isset($existing['id'])
                ? (int) $existing['id']
                : $this->create(array_merge($template, [
                    'name' => (string) $name,
                    'default_body' => (string) ($template['default_body'] ?? $template['body'] ?? ''),
                    'default_subject' => (string) ($template['default_subject'] ?? $template['subject'] ?? ''),
                ]));
        }

        return $ids;
    }

    public function listActive(?string $channel = null): array
    {
        if ($channel === null || trim($channel) === '') {
            return $this->database->fetchAll(
                'SELECT * FROM message_templates
                 WHERE (provider_id = :provider_id OR provider_id IS NULL) AND active = 1
                 ORDER BY name ASC',
                ['provider_id' => $this->providerId()]
            );
        }

        return $this->database->fetchAll(
            'SELECT * FROM message_templates
             WHERE (provider_id = :provider_id OR provider_id IS NULL) AND active = 1 AND channel = :channel
             ORDER BY name ASC',
            ['provider_id' => $this->providerId(), 'channel' => trim($channel)]
        );
    }

    public function updateByName(string $name, array $data, string $channel = 'whatsapp'): int
    {
        return $this->database->execute(
            'UPDATE message_templates
             SET purpose = :purpose,
                 description = :description,
                 subject = :subject,
                 default_subject = :default_subject,
                 body = :body,
                 default_body = :default_body,
                 variables_json = :variables_json,
                 supported_channels_json = :supported_channels_json,
                 enabled_channels_json = :enabled_channels_json,
                 active = :active,
                 version = :version,
                 updated_by_user_id = :updated_by_user_id,
                 updated_by_login = :updated_by_login,
                 updated_at = NOW()
             WHERE (provider_id = :provider_id OR provider_id IS NULL) AND name = :name AND channel = :channel',
            array_merge(
                [
                    'name' => trim($name),
                    'channel' => trim($channel) ?: 'whatsapp',
                    'provider_id' => $this->providerId(),
                ],
                $this->normalizeData($data, false)
            )
        );
    }

    public function listAll(): array
    {
        return $this->database->fetchAll(
            'SELECT * FROM message_templates
             WHERE provider_id = :provider_id OR provider_id IS NULL
             ORDER BY purpose, channel, name',
            ['provider_id' => $this->providerId()]
        );
    }

    public function saveManaged(int $id, array $data, array $operator): int
    {
        $template = $this->findById($id);
        if (!is_array($template)) {
            throw new \RuntimeException('Template não localizado para este provedor.');
        }
        $version = max(1, (int) ($template['version'] ?? 1)) + 1;
        $this->database->execute(
            'INSERT INTO message_template_versions
                (template_id, version, subject, body, variables_json, supported_channels_json, enabled_channels_json,
                 active, created_by_user_id, created_by_login, created_at)
             VALUES
                (:template_id, :version, :subject, :body, :variables_json, :supported_channels_json, :enabled_channels_json,
                 :active, :created_by_user_id, :created_by_login, NOW())',
            [
                'template_id' => $id,
                'version' => max(1, (int) ($template['version'] ?? 1)),
                'subject' => $template['subject'] ?? null,
                'body' => (string) ($template['body'] ?? ''),
                'variables_json' => $template['variables_json'] ?? null,
                'supported_channels_json' => $template['supported_channels_json'] ?? null,
                'enabled_channels_json' => $template['enabled_channels_json'] ?? null,
                'active' => !empty($template['active']) ? 1 : 0,
                'created_by_user_id' => $operator['id'] ?? null,
                'created_by_login' => $operator['login'] ?? null,
            ]
        );

        return $this->database->execute(
            'UPDATE message_templates
             SET subject = :subject, body = :body, enabled_channels_json = :enabled_channels_json,
                 active = :active, version = :version, updated_by_user_id = :updated_by_user_id,
                 updated_by_login = :updated_by_login, updated_at = NOW()
             WHERE id = :id AND (provider_id = :provider_id OR provider_id IS NULL)',
            [
                'id' => $id,
                'provider_id' => $this->providerId(),
                'subject' => trim((string) ($data['subject'] ?? '')) ?: null,
                'body' => (string) ($data['body'] ?? ''),
                'enabled_channels_json' => $this->normalizeJson($data['enabled_channels_json'] ?? []),
                'active' => !empty($data['active']) ? 1 : 0,
                'version' => $version,
                'updated_by_user_id' => $operator['id'] ?? null,
                'updated_by_login' => trim((string) ($operator['login'] ?? '')) ?: null,
            ]
        );
    }

    public function history(int $templateId): array
    {
        if (!is_array($this->findById($templateId))) {
            return [];
        }
        return $this->database->fetchAll(
            'SELECT * FROM message_template_versions WHERE template_id = :template_id ORDER BY version DESC',
            ['template_id' => $templateId]
        );
    }

    public function findVersion(int $templateId, int $version): ?array
    {
        if ($version < 1 || !is_array($this->findById($templateId))) {
            return null;
        }

        return $this->database->fetchOne(
            'SELECT * FROM message_template_versions WHERE template_id = :template_id AND version = :version LIMIT 1',
            ['template_id' => $templateId, 'version' => $version]
        );
    }

    private function normalizeData(array $data, bool $includeName = true): array
    {
        $normalized = [
            'provider_id' => (int) ($data['provider_id'] ?? $this->providerId()),
            'channel' => (string) ($data['channel'] ?? 'whatsapp'),
            'purpose' => (string) ($data['purpose'] ?? ''),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'subject' => trim((string) ($data['subject'] ?? '')) ?: null,
            'default_subject' => trim((string) ($data['default_subject'] ?? $data['subject'] ?? '')) ?: null,
            'body' => (string) ($data['body'] ?? ''),
            'default_body' => (string) ($data['default_body'] ?? $data['body'] ?? ''),
            'variables_json' => $this->normalizeJson($data['variables_json'] ?? null),
            'supported_channels_json' => $this->normalizeJson($data['supported_channels_json'] ?? [$data['channel'] ?? 'whatsapp']),
            'enabled_channels_json' => $this->normalizeJson($data['enabled_channels_json'] ?? [$data['channel'] ?? 'whatsapp']),
            'active' => isset($data['active']) ? (int) (bool) $data['active'] : 1,
            'version' => max(1, (int) ($data['version'] ?? 1)),
            'updated_by_user_id' => isset($data['updated_by_user_id']) ? (int) $data['updated_by_user_id'] : null,
            'updated_by_login' => trim((string) ($data['updated_by_login'] ?? '')) ?: null,
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

    private function providerId(): int
    {
        $providerId = (int) ($this->localRepository->currentProviderId() ?? 0);
        if ($providerId <= 0) {
            throw new \RuntimeException('Provedor não identificado para templates.');
        }
        return $providerId;
    }
}
