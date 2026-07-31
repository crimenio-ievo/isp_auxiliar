CREATE TABLE IF NOT EXISTS notification_channel_registry (
    channel_key VARCHAR(40) NOT NULL PRIMARY KEY,
    label VARCHAR(80) NOT NULL,
    available TINYINT(1) NOT NULL DEFAULT 0,
    adapter_key VARCHAR(120) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO notification_channel_registry (channel_key, label, available, adapter_key, created_at, updated_at)
VALUES
    ('whatsapp', 'WhatsApp', 1, 'evotrix', NOW(), NOW()),
    ('email', 'E-mail', 1, 'smtp', NOW(), NOW()),
    ('sms', 'SMS', 0, NULL, NOW(), NOW()),
    ('push', 'Aplicativo / push', 0, NULL, NOW(), NOW())
ON DUPLICATE KEY UPDATE label = VALUES(label), available = VALUES(available), adapter_key = VALUES(adapter_key), updated_at = NOW();

ALTER TABLE message_templates ADD COLUMN description VARCHAR(255) NULL AFTER purpose;
ALTER TABLE message_templates ADD COLUMN provider_id BIGINT UNSIGNED NULL AFTER id;
UPDATE message_templates SET provider_id = (SELECT MIN(id) FROM providers) WHERE provider_id IS NULL;
ALTER TABLE message_templates MODIFY provider_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE message_templates DROP INDEX message_templates_name_channel;
ALTER TABLE message_templates ADD UNIQUE KEY message_templates_provider_name_channel (provider_id, name, channel);
ALTER TABLE message_templates ADD CONSTRAINT fk_message_templates_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE RESTRICT;
ALTER TABLE message_templates ADD COLUMN subject VARCHAR(255) NULL AFTER description;
ALTER TABLE message_templates ADD COLUMN default_subject VARCHAR(255) NULL AFTER subject;
ALTER TABLE message_templates ADD COLUMN default_body MEDIUMTEXT NULL AFTER default_subject;
ALTER TABLE message_templates ADD COLUMN supported_channels_json JSON NULL AFTER variables_json;
ALTER TABLE message_templates ADD COLUMN enabled_channels_json JSON NULL AFTER supported_channels_json;
ALTER TABLE message_templates ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER active;
ALTER TABLE message_templates ADD COLUMN updated_by_user_id BIGINT UNSIGNED NULL AFTER version;
ALTER TABLE message_templates ADD COLUMN updated_by_login VARCHAR(120) NULL AFTER updated_by_user_id;

CREATE TABLE IF NOT EXISTS message_template_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    template_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    subject VARCHAR(255) NULL,
    body MEDIUMTEXT NOT NULL,
    variables_json JSON NULL,
    supported_channels_json JSON NULL,
    enabled_channels_json JSON NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_by_login VARCHAR(120) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY message_template_versions_unique (template_id, version),
    KEY message_template_versions_template (template_id),
    CONSTRAINT fk_message_template_versions_template FOREIGN KEY (template_id) REFERENCES message_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NOT NULL,
    mkauth_login VARCHAR(120) NOT NULL,
    contract_id BIGINT UNSIGNED NULL,
    document_type VARCHAR(80) NOT NULL DEFAULT 'printed_contract',
    status VARCHAR(40) NOT NULL DEFAULT 'final',
    original_name VARCHAR(255) NULL,
    storage_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    sha256 CHAR(64) NOT NULL,
    page_count INT UNSIGNED NOT NULL DEFAULT 1,
    metadata_json JSON NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_by_login VARCHAR(120) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY client_documents_provider_login (provider_id, mkauth_login),
    KEY client_documents_contract (contract_id),
    KEY client_documents_hash (sha256),
    CONSTRAINT fk_client_documents_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_client_documents_contract FOREIGN KEY (contract_id) REFERENCES client_contracts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
