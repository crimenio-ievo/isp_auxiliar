-- Credenciais de equipamentos de rede no banco, cifradas (libsodium secretbox) com a chave NET_SECRET_KEY do .env.
-- Aditiva: cria net_credentials e acrescenta net_devices.credential_id (NULL). credential_ref (NET_CRED_* do .env) passa a
-- aceitar NULL e continua valendo como alternativa quando credential_id for NULL. Rollback manual em scripts/rollback_046_net_credentials.sql.
-- O segredo nunca e lido por tela, API, log ou auditoria. Perder a chave NET_SECRET_KEY = perder as credenciais.
-- Nao usar ponto e virgula dentro de textos ou comentarios: o aplicador separa comandos por ponto e virgula.

CREATE TABLE IF NOT EXISTS net_credentials (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    kind ENUM('snmp_v2c','snmp_v3','ssh','telnet','http') NOT NULL DEFAULT 'snmp_v2c',
    username VARCHAR(120) NULL,
    secret_cipher VARBINARY(1024) NOT NULL,
    nonce VARBINARY(24) NOT NULL,
    key_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_by VARCHAR(80) NULL,
    created_at DATETIME NOT NULL,
    updated_by VARCHAR(80) NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY net_credentials_name (provider_id, name),
    CONSTRAINT fk_net_credentials_provider FOREIGN KEY (provider_id) REFERENCES providers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE net_devices ADD COLUMN IF NOT EXISTS credential_id BIGINT UNSIGNED NULL AFTER credential_ref;

ALTER TABLE net_devices ADD CONSTRAINT fk_net_devices_credential FOREIGN KEY (credential_id) REFERENCES net_credentials (id) ON DELETE RESTRICT;

ALTER TABLE net_devices MODIFY credential_ref VARCHAR(120) NULL;
