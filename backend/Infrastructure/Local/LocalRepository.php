<?php

declare(strict_types=1);

namespace App\Infrastructure\Local;

use App\Infrastructure\Database\Database;

/**
 * Repositório local do ISP Auxiliar.
 *
 * Mantém o acesso ao banco complementar concentrado para evitar espalhar SQL
 * pelos controllers.
 */
final class LocalRepository
{
    public function __construct(
        private Database $database,
        private string $providerKey = 'default'
    ) {
        $this->providerKey = $this->slug($providerKey ?: 'default');
    }

    public function isAvailable(): bool
    {
        try {
            $this->database->fetchOne('SELECT 1 FROM providers LIMIT 1');
            return true;
        } catch (\Throwable $exception) {
            return false;
        }
    }

    public function currentProvider(): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $provider = $this->database->fetchOne(
            'SELECT * FROM providers WHERE slug = :slug LIMIT 1',
            ['slug' => $this->providerKey]
        );

        if ($provider !== null) {
            return $provider;
        }

        $this->database->execute(
            'INSERT INTO providers (name, slug, status, created_at, updated_at)
             VALUES (:name, :slug, "active", NOW(), NOW())',
            [
                'name' => strtoupper($this->providerKey),
                'slug' => $this->providerKey,
            ]
        );

        return $this->database->fetchOne(
            'SELECT * FROM providers WHERE slug = :slug LIMIT 1',
            ['slug' => $this->providerKey]
        );
    }

    public function currentProviderId(): ?int
    {
        $provider = $this->currentProvider();

        return $provider === null ? null : (int) $provider['id'];
    }

    public function providerSettings(): array
    {
        $providerId = $this->currentProviderId();

        if ($providerId === null) {
            return [];
        }

        $rows = $this->database->fetchAll(
            'SELECT setting_key, setting_value FROM provider_settings WHERE provider_id = :provider_id',
            ['provider_id' => $providerId]
        );

        $settings = [];

        foreach ($rows as $row) {
            $settings[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }

        return $settings;
    }

    public function providerSetting(string $key, string $default = ''): string
    {
        $key = trim($key);

        if ($key === '') {
            return $default;
        }

        $settings = $this->providerSettings();

        return (string) ($settings[$key] ?? $default);
    }

    public function saveProviderSettings(array $settings): void
    {
        $providerId = $this->currentProviderId();

        if ($providerId === null) {
            return;
        }

        foreach ($settings as $key => $value) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }

            $this->database->execute(
                'INSERT INTO provider_settings (provider_id, setting_key, setting_value, is_secret, updated_at)
                 VALUES (:provider_id, :setting_key, :setting_value, :is_secret, NOW())
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_secret = VALUES(is_secret), updated_at = NOW()',
                [
                    'provider_id' => $providerId,
                    'setting_key' => $key,
                    'setting_value' => (string) $value,
                    'is_secret' => str_contains($key, 'secret') || str_contains($key, 'password') || str_contains($key, 'token') ? 1 : 0,
                ]
            );
        }
    }

    public function saveProviderProfile(array $profile): void
    {
        $providerId = $this->currentProviderId();

        if ($providerId === null) {
            return;
        }

        $this->database->execute(
            'UPDATE providers
             SET name = :name, document = :document, domain = :domain, base_path = :base_path, updated_at = NOW()
             WHERE id = :id',
            [
                'id' => $providerId,
                'name' => trim((string) ($profile['name'] ?? '')) ?: strtoupper($this->providerKey),
                'document' => trim((string) ($profile['document'] ?? '')),
                'domain' => trim((string) ($profile['domain'] ?? '')),
                'base_path' => trim((string) ($profile['base_path'] ?? '')),
            ]
        );
    }

    public function authenticateLocalUser(string $username, string $password): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $user = $this->findLocalUserByUsername($username);

        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            return null;
        }

        $this->database->execute(
            'UPDATE app_users SET last_login_at = NOW(), updated_at = NOW() WHERE id = :id',
            ['id' => (int) $user['id']]
        );

        return $user;
    }

    public function localUserExists(string $username): bool
    {
        return $this->findLocalUserByUsername($username) !== null;
    }

    private function findLocalUserByUsername(string $username): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $providerId = $this->currentProviderId();

        return $this->database->fetchOne(
            'SELECT * FROM app_users
             WHERE status = "active"
             AND (provider_id = :provider_id OR role = "platform_admin")
             AND (LOWER(email) = LOWER(:username_email) OR LOWER(login) = LOWER(:username_login))
             LIMIT 1',
            [
                'provider_id' => $providerId,
                'username_email' => trim($username),
                'username_login' => trim($username),
            ]
        );
    }

    public function createManagerUser(string $name, string $email, string $password, string $role = 'manager'): int
    {
        $providerId = $role === 'platform_admin' ? null : $this->currentProviderId();
        $login = $this->slug(strtok($email, '@') ?: $email);

        $this->database->execute(
            'INSERT INTO app_users (provider_id, name, email, login, password_hash, role, status, created_at, updated_at)
             VALUES (:provider_id, :name, :email, :login, :password_hash, :role, "active", NOW(), NOW())',
            [
                'provider_id' => $providerId,
                'name' => $name,
                'email' => $email,
                'login' => $login,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
            ]
        );

        return $this->database->lastInsertId();
    }

    public function listLocalUsers(int $limit = 100): array
    {
        $providerId = $this->currentProviderId();

        if ($providerId === null || !$this->isAvailable()) {
            return [];
        }

        $limit = max(1, min(500, $limit));

        return $this->database->fetchAll(
            'SELECT id, provider_id, name, email, login, role, status, last_login_at, created_at
             FROM app_users
             WHERE provider_id = :provider_id OR role = "platform_admin"
             ORDER BY role ASC, name ASC
             LIMIT ' . (int) $limit,
            ['provider_id' => $providerId]
        );
    }

    public function log(?int $userId, string $actorLogin, string $action, string $entityType = '', ?int $entityId = null, array $context = [], string $ip = '', string $userAgent = ''): void
    {
        $providerId = $this->currentProviderId();

        if ($providerId === null || !$this->isAvailable()) {
            return;
        }

        $this->database->execute(
            'INSERT INTO audit_logs (provider_id, user_id, actor_login, action, entity_type, entity_id, ip_address, user_agent, context_json, created_at)
             VALUES (:provider_id, :user_id, :actor_login, :action, :entity_type, :entity_id, :ip_address, :user_agent, :context_json, NOW())',
            [
                'provider_id' => $providerId,
                'user_id' => $userId,
                'actor_login' => $actorLogin,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    public function createClientRegistration(array $data): ?int
    {
        $providerId = $this->currentProviderId();

        if ($providerId === null || !$this->isAvailable()) {
            return null;
        }

        $this->database->execute(
            'INSERT INTO client_registrations
                (provider_id, mkauth_uuid, mkauth_login, client_name, cpf_cnpj, plan_name, status, evidence_ref, evidence_url, radius_token, created_by_user_id, created_by_login, created_at, updated_at)
             VALUES
                (:provider_id, :mkauth_uuid, :mkauth_login, :client_name, :cpf_cnpj, :plan_name, :status, :evidence_ref, :evidence_url, :radius_token, :created_by_user_id, :created_by_login, NOW(), NOW())',
            [
                'provider_id' => $providerId,
                'mkauth_uuid' => (string) ($data['mkauth_uuid'] ?? ''),
                'mkauth_login' => (string) ($data['mkauth_login'] ?? ''),
                'client_name' => (string) ($data['client_name'] ?? ''),
                'cpf_cnpj' => (string) ($data['cpf_cnpj'] ?? ''),
                'plan_name' => (string) ($data['plan_name'] ?? ''),
                'status' => (string) ($data['status'] ?? 'awaiting_connection'),
                'evidence_ref' => (string) ($data['evidence_ref'] ?? ''),
                'evidence_url' => (string) ($data['evidence_url'] ?? ''),
                'radius_token' => (string) ($data['radius_token'] ?? ''),
                'created_by_user_id' => $data['created_by_user_id'] ?? null,
                'created_by_login' => (string) ($data['created_by_login'] ?? ''),
            ]
        );

        return $this->database->lastInsertId();
    }

    public function upsertClientRegistrationByRadiusToken(array $data): ?int
    {
        $providerId = $this->currentProviderId();
        $radiusToken = trim((string) ($data['radius_token'] ?? ''));

        if ($providerId === null || !$this->isAvailable() || $radiusToken === '') {
            return null;
        }

        $existing = $this->findClientRegistrationByRadiusToken($radiusToken);

        if (is_array($existing) && isset($existing['id'])) {
            $this->database->execute(
                'UPDATE client_registrations
                 SET mkauth_uuid = :mkauth_uuid,
                     mkauth_login = :mkauth_login,
                     client_name = :client_name,
                     cpf_cnpj = :cpf_cnpj,
                     plan_name = :plan_name,
                     status = :status,
                     evidence_ref = :evidence_ref,
                     evidence_url = :evidence_url,
                     created_by_user_id = :created_by_user_id,
                     created_by_login = :created_by_login,
                     updated_at = NOW()
                 WHERE provider_id = :provider_id AND radius_token = :radius_token',
                [
                    'provider_id' => $providerId,
                    'radius_token' => $radiusToken,
                    'mkauth_uuid' => (string) ($data['mkauth_uuid'] ?? ''),
                    'mkauth_login' => (string) ($data['mkauth_login'] ?? ''),
                    'client_name' => (string) ($data['client_name'] ?? ''),
                    'cpf_cnpj' => (string) ($data['cpf_cnpj'] ?? ''),
                    'plan_name' => (string) ($data['plan_name'] ?? ''),
                    'status' => (string) ($data['status'] ?? 'awaiting_connection'),
                    'evidence_ref' => (string) ($data['evidence_ref'] ?? ''),
                    'evidence_url' => (string) ($data['evidence_url'] ?? ''),
                    'created_by_user_id' => $data['created_by_user_id'] ?? null,
                    'created_by_login' => (string) ($data['created_by_login'] ?? ''),
                ]
            );

            return (int) $existing['id'];
        }

        $this->database->execute(
            'INSERT INTO client_registrations
                (provider_id, mkauth_uuid, mkauth_login, client_name, cpf_cnpj, plan_name, status, evidence_ref, evidence_url, radius_token, created_by_user_id, created_by_login, created_at, updated_at)
             VALUES
                (:provider_id, :mkauth_uuid, :mkauth_login, :client_name, :cpf_cnpj, :plan_name, :status, :evidence_ref, :evidence_url, :radius_token, :created_by_user_id, :created_by_login, NOW(), NOW())',
            [
                'provider_id' => $providerId,
                'mkauth_uuid' => (string) ($data['mkauth_uuid'] ?? ''),
                'mkauth_login' => (string) ($data['mkauth_login'] ?? ''),
                'client_name' => (string) ($data['client_name'] ?? ''),
                'cpf_cnpj' => (string) ($data['cpf_cnpj'] ?? ''),
                'plan_name' => (string) ($data['plan_name'] ?? ''),
                'status' => (string) ($data['status'] ?? 'awaiting_connection'),
                'evidence_ref' => (string) ($data['evidence_ref'] ?? ''),
                'evidence_url' => (string) ($data['evidence_url'] ?? ''),
                'radius_token' => $radiusToken,
                'created_by_user_id' => $data['created_by_user_id'] ?? null,
                'created_by_login' => (string) ($data['created_by_login'] ?? ''),
            ]
        );

        return $this->database->lastInsertId();
    }

    public function findClientRegistrationByRadiusToken(string $token): ?array
    {
        $providerId = $this->currentProviderId();
        $token = trim($token);

        if ($providerId === null || !$this->isAvailable() || $token === '') {
            return null;
        }

        return $this->database->fetchOne(
            'SELECT *
             FROM client_registrations
             WHERE provider_id = :provider_id AND radius_token = :radius_token
             LIMIT 1',
            [
                'provider_id' => $providerId,
                'radius_token' => $token,
            ]
        );
    }

    public function findClientRegistrationById(int $registrationId): ?array
    {
        $providerId = $this->currentProviderId();

        if ($providerId === null || !$this->isAvailable() || $registrationId <= 0) {
            return null;
        }

        return $this->database->fetchOne(
            'SELECT *
             FROM client_registrations
             WHERE provider_id = :provider_id AND id = :registration_id
             LIMIT 1',
            [
                'provider_id' => $providerId,
                'registration_id' => $registrationId,
            ]
        );
    }

    public function deleteClientRegistrationByRadiusToken(string $token): void
    {
        $providerId = $this->currentProviderId();
        $token = trim($token);

        if ($providerId === null || !$this->isAvailable() || $token === '') {
            return;
        }

        $this->database->execute(
            'DELETE FROM client_registrations
             WHERE provider_id = :provider_id AND radius_token = :radius_token',
            [
                'provider_id' => $providerId,
                'radius_token' => $token,
            ]
        );
    }

    public function deleteEvidenceFilesByRegistrationId(?int $registrationId): void
    {
        $providerId = $this->currentProviderId();

        if ($providerId === null || !$this->isAvailable() || $registrationId === null) {
            return;
        }

        $this->database->execute(
            'DELETE FROM evidence_files
             WHERE provider_id = :provider_id AND registration_id = :registration_id',
            [
                'provider_id' => $providerId,
                'registration_id' => $registrationId,
            ]
        );
    }

    public function deleteInstallationCheckpointByToken(string $token): void
    {
        $providerId = $this->currentProviderId();
        $token = trim($token);

        if ($providerId === null || !$this->isAvailable() || $token === '') {
            return;
        }

        $this->database->execute(
            'DELETE FROM installation_checkpoints
             WHERE provider_id = :provider_id AND token = :token',
            [
                'provider_id' => $providerId,
                'token' => $token,
            ]
        );
    }

    public function registerEvidenceFiles(?int $registrationId, string $evidenceRef, string $folder): void
    {
        $providerId = $this->currentProviderId();

        if ($providerId === null || !$this->isAvailable() || !is_dir($folder)) {
            return;
        }

        foreach (array_diff(scandir($folder) ?: [], ['.', '..']) as $file) {
            $path = $folder . '/' . $file;
            if (!is_file($path)) {
                continue;
            }

            $this->database->execute(
                'INSERT INTO evidence_files
                    (provider_id, registration_id, evidence_ref, file_kind, relative_path, mime_type, size_bytes, sha256, created_at)
                 VALUES
                    (:provider_id, :registration_id, :evidence_ref, :file_kind, :relative_path, :mime_type, :size_bytes, :sha256, NOW())',
                [
                    'provider_id' => $providerId,
                    'registration_id' => $registrationId,
                    'evidence_ref' => $evidenceRef,
                    'file_kind' => str_starts_with($file, 'assinatura') ? 'signature' : (str_starts_with($file, 'foto_') ? 'photo' : 'metadata'),
                    'relative_path' => 'storage/uploads/clientes/' . $evidenceRef . '/' . $file,
                    'mime_type' => mime_content_type($path) ?: '',
                    'size_bytes' => filesize($path) ?: 0,
                    'sha256' => hash_file('sha256', $path) ?: '',
                ]
            );
        }
    }

    public function createInstallationCheckpoint(?int $registrationId, string $token, array $record): ?int
    {
        $providerId = $this->currentProviderId();

        if ($providerId === null || !$this->isAvailable()) {
            return null;
        }

        $this->database->execute(
            'INSERT INTO installation_checkpoints
                (provider_id, registration_id, token, mkauth_login, status, payload_json, created_at, updated_at)
             VALUES
                (:provider_id, :registration_id, :token, :mkauth_login, :status, :payload_json, NOW(), NOW())
             ON DUPLICATE KEY UPDATE registration_id = VALUES(registration_id), mkauth_login = VALUES(mkauth_login), status = VALUES(status), payload_json = VALUES(payload_json), updated_at = NOW()',
            [
                'provider_id' => $providerId,
                'registration_id' => $registrationId,
                'token' => $token,
                'mkauth_login' => (string) ($record['login'] ?? ''),
            'status' => (string) ($record['status'] ?? 'awaiting_connection'),
            'payload_json' => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]
    );

        return $this->database->lastInsertId();
    }

    public function findLatestInstallationCheckpointByLogin(string $login): ?array
    {
        $providerId = $this->currentProviderId();
        $login = trim($login);

        if ($providerId === null || !$this->isAvailable() || $login === '') {
            return null;
        }

        return $this->database->fetchOne(
            'SELECT *
             FROM installation_checkpoints
             WHERE provider_id = :provider_id AND LOWER(mkauth_login) = LOWER(:login)
             ORDER BY updated_at DESC, id DESC
             LIMIT 1',
            [
                'provider_id' => $providerId,
                'login' => $login,
            ]
        );
    }

    public function findLatestInstallationCheckpointByRegistrationId(int $registrationId): ?array
    {
        $providerId = $this->currentProviderId();

        if ($providerId === null || !$this->isAvailable() || $registrationId <= 0) {
            return null;
        }

        return $this->database->fetchOne(
            'SELECT *
             FROM installation_checkpoints
             WHERE provider_id = :provider_id AND registration_id = :registration_id
             ORDER BY updated_at DESC, id DESC
             LIMIT 1',
            [
                'provider_id' => $providerId,
                'registration_id' => $registrationId,
            ]
        );
    }

    public function findInstallationCheckpointByToken(string $token): ?array
    {
        $providerId = $this->currentProviderId();
        $token = trim($token);

        if ($providerId === null || !$this->isAvailable() || $token === '') {
            return null;
        }

        return $this->database->fetchOne(
            'SELECT *
             FROM installation_checkpoints
             WHERE provider_id = :provider_id AND token = :token
             LIMIT 1',
            [
                'provider_id' => $providerId,
                'token' => $token,
            ]
        );
    }

    public function findLatestClientRegistrationByLogin(string $login): ?array
    {
        $providerId = $this->currentProviderId();
        $login = trim($login);

        if ($providerId === null || !$this->isAvailable() || $login === '') {
            return null;
        }

        return $this->database->fetchOne(
            'SELECT *
             FROM client_registrations
             WHERE provider_id = :provider_id AND LOWER(mkauth_login) = LOWER(:login)
             ORDER BY updated_at DESC, id DESC
             LIMIT 1',
            [
                'provider_id' => $providerId,
                'login' => $login,
            ]
        );
    }

    public function updateInstallationCheckpoint(string $token, array $record): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $this->database->execute(
            'UPDATE installation_checkpoints
             SET status = :status, payload_json = :payload_json, completed_at = :completed_at, updated_at = NOW()
             WHERE token = :token',
            [
                'status' => (string) ($record['status'] ?? 'awaiting_connection'),
                'payload_json' => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'completed_at' => (string) ($record['status'] ?? '') === 'completed' ? ($record['completed_at'] ?? date('Y-m-d H:i:s')) : null,
                'token' => $token,
            ]
        );
    }

    public function recentAuditLogs(int $limit = 100): array
    {
        $providerId = $this->currentProviderId();

        if ($providerId === null || !$this->isAvailable()) {
            return [];
        }

        $limit = max(1, min(500, $limit));

        return $this->database->fetchAll(
            'SELECT id, actor_login, action, entity_type, entity_id, ip_address, context_json, created_at
             FROM audit_logs
             WHERE provider_id = :provider_id
             ORDER BY created_at DESC
             LIMIT ' . (int) $limit,
            ['provider_id' => $providerId]
        );
    }

    public function auditLogsForContract(
        ?int $contractId = null,
        ?int $acceptanceId = null,
        ?int $financialTaskId = null,
        ?int $registrationId = null,
        int $limit = 100
    ): array {
        $providerId = $this->currentProviderId();

        if ($providerId === null || !$this->isAvailable()) {
            return [];
        }

        $conditions = [];
        $params = ['provider_id' => $providerId];

        if ($contractId !== null && $contractId > 0) {
            $conditions[] = '(entity_type = "client_contract" AND entity_id = :contract_id)';
            $params['contract_id'] = $contractId;
        }

        if ($acceptanceId !== null && $acceptanceId > 0) {
            $conditions[] = '(entity_type = "contract_acceptance" AND entity_id = :acceptance_id)';
            $params['acceptance_id'] = $acceptanceId;
        }

        if ($financialTaskId !== null && $financialTaskId > 0) {
            $conditions[] = '(entity_type = "financial_task" AND entity_id = :financial_task_id)';
            $params['financial_task_id'] = $financialTaskId;
        }

        if ($registrationId !== null && $registrationId > 0) {
            $conditions[] = '(entity_type = "client_registration" AND entity_id = :registration_id)';
            $params['registration_id'] = $registrationId;
        }

        if ($conditions === []) {
            return [];
        }

        $limit = max(1, min(500, $limit));

        return $this->database->fetchAll(
            'SELECT id, actor_login, action, entity_type, entity_id, ip_address, context_json, created_at
             FROM audit_logs
             WHERE provider_id = :provider_id
             AND (' . implode(' OR ', $conditions) . ')
             ORDER BY created_at DESC
             LIMIT ' . (int) $limit,
            $params
        );
    }

    private function slug(string $value): string
    {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = $converted === false ? $value : $converted;
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';

        return trim($value, '_') ?: 'default';
    }
}
