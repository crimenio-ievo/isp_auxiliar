CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(100) NOT NULL PRIMARY KEY,
    executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS providers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    slug VARCHAR(80) NOT NULL UNIQUE,
    document VARCHAR(32) NULL,
    domain VARCHAR(190) NULL,
    base_path VARCHAR(190) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provider_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NOT NULL,
    setting_key VARCHAR(120) NOT NULL,
    setting_value TEXT NULL,
    is_secret TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY provider_setting_unique (provider_id, setting_key),
    CONSTRAINT fk_provider_settings_provider
        FOREIGN KEY (provider_id) REFERENCES providers(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NULL,
    name VARCHAR(160) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    login VARCHAR(80) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('platform_admin','manager','technician') NOT NULL DEFAULT 'manager',
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY app_users_provider_role (provider_id, role),
    CONSTRAINT fk_app_users_provider
        FOREIGN KEY (provider_id) REFERENCES providers(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_registrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NOT NULL,
    mkauth_uuid VARCHAR(64) NULL,
    mkauth_login VARCHAR(80) NOT NULL,
    client_name VARCHAR(190) NOT NULL,
    cpf_cnpj VARCHAR(32) NOT NULL,
    plan_name VARCHAR(120) NULL,
    status ENUM('draft','sent_to_mkauth','awaiting_connection','completed','failed') NOT NULL DEFAULT 'awaiting_connection',
    evidence_ref VARCHAR(190) NULL,
    evidence_url VARCHAR(255) NULL,
    radius_token CHAR(32) NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_by_login VARCHAR(80) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY client_registrations_provider_login (provider_id, mkauth_login),
    KEY client_registrations_provider_document (provider_id, cpf_cnpj),
    KEY client_registrations_status (provider_id, status),
    CONSTRAINT fk_client_registrations_provider
        FOREIGN KEY (provider_id) REFERENCES providers(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_client_registrations_user
        FOREIGN KEY (created_by_user_id) REFERENCES app_users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evidence_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NOT NULL,
    registration_id BIGINT UNSIGNED NULL,
    evidence_ref VARCHAR(190) NOT NULL,
    file_kind ENUM('photo','signature','metadata','other') NOT NULL DEFAULT 'other',
    relative_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    sha256 CHAR(64) NULL,
    created_at DATETIME NOT NULL,
    KEY evidence_files_ref (provider_id, evidence_ref),
    KEY evidence_files_registration (registration_id),
    CONSTRAINT fk_evidence_files_provider
        FOREIGN KEY (provider_id) REFERENCES providers(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_evidence_files_registration
        FOREIGN KEY (registration_id) REFERENCES client_registrations(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS installation_checkpoints (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NOT NULL,
    registration_id BIGINT UNSIGNED NULL,
    token CHAR(32) NOT NULL UNIQUE,
    mkauth_login VARCHAR(80) NOT NULL,
    status ENUM('awaiting_connection','completed','failed') NOT NULL DEFAULT 'awaiting_connection',
    payload_json JSON NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY installation_provider_status (provider_id, status),
    KEY installation_provider_login (provider_id, mkauth_login),
    CONSTRAINT fk_installation_provider
        FOREIGN KEY (provider_id) REFERENCES providers(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_installation_registration
        FOREIGN KEY (registration_id) REFERENCES client_registrations(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    actor_login VARCHAR(80) NULL,
    action VARCHAR(120) NOT NULL,
    entity_type VARCHAR(80) NULL,
    entity_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    context_json JSON NULL,
    created_at DATETIME NOT NULL,
    KEY audit_provider_date (provider_id, created_at),
    KEY audit_provider_action (provider_id, action),
    CONSTRAINT fk_audit_provider
        FOREIGN KEY (provider_id) REFERENCES providers(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_audit_user
        FOREIGN KEY (user_id) REFERENCES app_users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
