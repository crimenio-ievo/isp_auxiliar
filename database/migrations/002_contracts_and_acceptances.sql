CREATE TABLE IF NOT EXISTS client_contracts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NULL,
    mkauth_login VARCHAR(80) NOT NULL,
    nome_cliente VARCHAR(190) NOT NULL,
    telefone_cliente VARCHAR(32) NOT NULL,
    tipo_adesao ENUM('cheia', 'promocional', 'isenta') NOT NULL DEFAULT 'cheia',
    valor_adesao DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    parcelas_adesao TINYINT UNSIGNED NOT NULL DEFAULT 1,
    valor_parcela_adesao DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    vencimento_primeira_parcela DATE NULL,
    fidelidade_meses SMALLINT UNSIGNED NOT NULL DEFAULT 12,
    beneficio_valor DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    multa_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tipo_aceite ENUM(
        'nova_instalacao',
        'regularizacao_contrato',
        'alteracao_plano',
        'renovacao_fidelidade',
        'aceite_promocao'
    ) NOT NULL DEFAULT 'nova_instalacao',
    observacao_adesao TEXT NULL,
    status_financeiro ENUM('pendente_lancamento', 'lancado', 'dispensado') NOT NULL DEFAULT 'pendente_lancamento',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY client_contracts_client_id (client_id),
    KEY client_contracts_login (mkauth_login),
    KEY client_contracts_status_financeiro (status_financeiro),
    KEY client_contracts_tipo_aceite (tipo_aceite)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contract_acceptances (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    contract_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    token_expires_at DATETIME NOT NULL,
    status ENUM('criado', 'enviado', 'aceito', 'expirado', 'cancelado') NOT NULL DEFAULT 'criado',
    telefone_enviado VARCHAR(32) NOT NULL,
    whatsapp_message_id VARCHAR(120) NULL,
    sent_at DATETIME NULL,
    accepted_at DATETIME NULL,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    termo_versao VARCHAR(40) NOT NULL,
    termo_hash CHAR(64) NOT NULL,
    pdf_path VARCHAR(255) NULL,
    evidence_json_path VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY contract_acceptances_contract_id (contract_id),
    KEY contract_acceptances_status (status),
    CONSTRAINT fk_contract_acceptances_contract
        FOREIGN KEY (contract_id) REFERENCES client_contracts(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    contract_id BIGINT UNSIGNED NULL,
    acceptance_id BIGINT UNSIGNED NULL,
    channel ENUM('whatsapp') NOT NULL DEFAULT 'whatsapp',
    provider VARCHAR(40) NOT NULL DEFAULT 'evotrix',
    recipient VARCHAR(32) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('simulado', 'enviado', 'erro') NOT NULL DEFAULT 'simulado',
    provider_response TEXT NULL,
    created_at DATETIME NOT NULL,
    KEY notification_logs_contract (contract_id),
    KEY notification_logs_acceptance (acceptance_id),
    KEY notification_logs_status (status),
    CONSTRAINT fk_notification_logs_contract
        FOREIGN KEY (contract_id) REFERENCES client_contracts(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_notification_logs_acceptance
        FOREIGN KEY (acceptance_id) REFERENCES contract_acceptances(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS financial_tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    contract_id BIGINT UNSIGNED NOT NULL,
    mkauth_login VARCHAR(80) NOT NULL,
    titulo VARCHAR(190) NOT NULL,
    descricao TEXT NOT NULL,
    setor ENUM('financeiro') NOT NULL DEFAULT 'financeiro',
    status ENUM('aberto', 'em_andamento', 'concluido', 'cancelado') NOT NULL DEFAULT 'aberto',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY financial_tasks_contract (contract_id),
    KEY financial_tasks_login (mkauth_login),
    KEY financial_tasks_status (status),
    CONSTRAINT fk_financial_tasks_contract
        FOREIGN KEY (contract_id) REFERENCES client_contracts(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    channel VARCHAR(40) NOT NULL DEFAULT 'whatsapp',
    purpose VARCHAR(80) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    variables_json JSON NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY message_templates_name_channel (name, channel),
    KEY message_templates_purpose (purpose),
    KEY message_templates_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
