CREATE TABLE IF NOT EXISTS operational_processes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NOT NULL,
    process_type VARCHAR(40) NOT NULL,
    mkauth_login VARCHAR(80) NOT NULL,
    client_name VARCHAR(190) NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'draft',
    current_step_key VARCHAR(80) NULL,
    responsible_user_id BIGINT UNSIGNED NULL,
    responsible_login VARCHAR(80) NULL,
    responsible_name VARCHAR(160) NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_by_login VARCHAR(80) NULL,
    registration_id BIGINT UNSIGNED NULL,
    contract_id BIGINT UNSIGNED NULL,
    acceptance_id BIGINT UNSIGNED NULL,
    equipment_reference VARCHAR(190) NULL,
    external_ticket_id VARCHAR(80) NULL,
    financial_task_id BIGINT UNSIGNED NULL,
    progress_completed SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    progress_total SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_pending_key VARCHAR(80) NULL,
    next_pending_label VARCHAR(190) NULL,
    notes TEXT NULL,
    metadata_json LONGTEXT NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY operational_processes_provider_login (provider_id, mkauth_login),
    KEY operational_processes_provider_status (provider_id, status),
    KEY operational_processes_type_status (process_type, status),
    KEY operational_processes_contract (contract_id),
    KEY operational_processes_acceptance (acceptance_id),
    KEY operational_processes_financial_task (financial_task_id),
    UNIQUE KEY operational_processes_contract_type (provider_id, contract_id, process_type),
    CONSTRAINT fk_operational_processes_provider
        FOREIGN KEY (provider_id) REFERENCES providers(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_operational_processes_creator
        FOREIGN KEY (created_by_user_id) REFERENCES app_users(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_operational_processes_responsible
        FOREIGN KEY (responsible_user_id) REFERENCES app_users(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_operational_processes_registration
        FOREIGN KEY (registration_id) REFERENCES client_registrations(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_operational_processes_contract
        FOREIGN KEY (contract_id) REFERENCES client_contracts(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_operational_processes_acceptance
        FOREIGN KEY (acceptance_id) REFERENCES contract_acceptances(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_operational_processes_financial_task
        FOREIGN KEY (financial_task_id) REFERENCES financial_tasks(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS operational_process_steps (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    process_id BIGINT UNSIGNED NOT NULL,
    step_key VARCHAR(80) NOT NULL,
    label VARCHAR(190) NOT NULL,
    step_order SMALLINT UNSIGNED NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'not_started',
    is_required TINYINT(1) NOT NULL DEFAULT 1,
    responsible_role VARCHAR(40) NULL,
    responsible_login VARCHAR(80) NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    completed_by_user_id BIGINT UNSIGNED NULL,
    completed_by_login VARCHAR(80) NULL,
    completion_origin VARCHAR(32) NULL,
    observation TEXT NULL,
    pending_reason TEXT NULL,
    evidence_json LONGTEXT NULL,
    next_action VARCHAR(255) NULL,
    external_reference VARCHAR(190) NULL,
    last_checked_at DATETIME NULL,
    deferred_at DATETIME NULL,
    deferred_by_login VARCHAR(80) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY operational_process_steps_process_key (process_id, step_key),
    KEY operational_process_steps_process_order (process_id, step_order),
    KEY operational_process_steps_status (status),
    CONSTRAINT fk_operational_process_steps_process
        FOREIGN KEY (process_id) REFERENCES operational_processes(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_operational_process_steps_completed_by
        FOREIGN KEY (completed_by_user_id) REFERENCES app_users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS operational_process_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    process_id BIGINT UNSIGNED NOT NULL,
    contract_id BIGINT UNSIGNED NOT NULL,
    document_type VARCHAR(60) NOT NULL,
    document_version VARCHAR(40) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'prepared',
    content_snapshot LONGTEXT NOT NULL,
    snapshot_hash CHAR(64) NOT NULL,
    active_acceptance_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY operational_process_documents_version (process_id, document_type, document_version),
    KEY operational_process_documents_contract (contract_id),
    KEY operational_process_documents_acceptance (active_acceptance_id),
    CONSTRAINT fk_operational_process_documents_process
        FOREIGN KEY (process_id) REFERENCES operational_processes(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_operational_process_documents_contract
        FOREIGN KEY (contract_id) REFERENCES client_contracts(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_operational_process_documents_acceptance
        FOREIGN KEY (active_acceptance_id) REFERENCES contract_acceptances(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE contract_acceptances
    ADD COLUMN operational_process_id BIGINT UNSIGNED NULL AFTER contract_id,
    ADD COLUMN process_document_id BIGINT UNSIGNED NULL AFTER operational_process_id,
    ADD KEY contract_acceptances_operational_process (operational_process_id),
    ADD KEY contract_acceptances_process_document (process_document_id),
    ADD CONSTRAINT fk_contract_acceptances_operational_process
        FOREIGN KEY (operational_process_id) REFERENCES operational_processes(id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_contract_acceptances_process_document
        FOREIGN KEY (process_document_id) REFERENCES operational_process_documents(id)
        ON DELETE SET NULL;

-- Rollback manual, somente no banco de homologacao:
-- ALTER TABLE contract_acceptances DROP FOREIGN KEY fk_contract_acceptances_process_document,
--   DROP FOREIGN KEY fk_contract_acceptances_operational_process,
--   DROP KEY contract_acceptances_process_document,
--   DROP KEY contract_acceptances_operational_process,
--   DROP COLUMN process_document_id,
--   DROP COLUMN operational_process_id;
-- DROP TABLE operational_process_documents;
-- DROP TABLE operational_process_steps;
-- DROP TABLE operational_processes;
