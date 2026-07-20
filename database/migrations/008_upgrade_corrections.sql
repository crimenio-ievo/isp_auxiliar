ALTER TABLE client_contracts
    ADD COLUMN lifecycle_status ENUM('active', 'correction_pending', 'cancelled', 'superseded') NOT NULL DEFAULT 'active' AFTER status_financeiro,
    ADD COLUMN supersedes_contract_id BIGINT UNSIGNED NULL AFTER lifecycle_status,
    ADD COLUMN superseded_by_contract_id BIGINT UNSIGNED NULL AFTER supersedes_contract_id,
    ADD COLUMN revision_number SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER superseded_by_contract_id,
    ADD COLUMN cancellation_reason TEXT NULL AFTER revision_number,
    ADD COLUMN cancelled_at DATETIME NULL AFTER cancellation_reason,
    ADD COLUMN cancelled_by_user_id BIGINT UNSIGNED NULL AFTER cancelled_at,
    ADD COLUMN cancelled_by_login VARCHAR(80) NULL AFTER cancelled_by_user_id,
    ADD KEY client_contracts_lifecycle_status (lifecycle_status),
    ADD KEY client_contracts_supersedes (supersedes_contract_id),
    ADD KEY client_contracts_superseded_by (superseded_by_contract_id),
    ADD CONSTRAINT fk_client_contracts_supersedes
        FOREIGN KEY (supersedes_contract_id) REFERENCES client_contracts(id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_client_contracts_superseded_by
        FOREIGN KEY (superseded_by_contract_id) REFERENCES client_contracts(id)
        ON DELETE SET NULL;

ALTER TABLE contract_acceptances
    ADD COLUMN revoked_at DATETIME NULL AFTER accepted_at,
    ADD COLUMN revoked_by_user_id BIGINT UNSIGNED NULL AFTER revoked_at,
    ADD COLUMN revoked_by_login VARCHAR(80) NULL AFTER revoked_by_user_id,
    ADD COLUMN revocation_reason TEXT NULL AFTER revoked_by_login,
    ADD KEY contract_acceptances_revoked_at (revoked_at);

-- Rollback manual, se necessario:
-- ALTER TABLE contract_acceptances DROP KEY contract_acceptances_revoked_at,
--   DROP COLUMN revocation_reason, DROP COLUMN revoked_by_login,
--   DROP COLUMN revoked_by_user_id, DROP COLUMN revoked_at;
-- ALTER TABLE client_contracts DROP FOREIGN KEY fk_client_contracts_superseded_by,
--   DROP FOREIGN KEY fk_client_contracts_supersedes,
--   DROP KEY client_contracts_superseded_by, DROP KEY client_contracts_supersedes,
--   DROP KEY client_contracts_lifecycle_status, DROP COLUMN cancelled_by_login,
--   DROP COLUMN cancelled_by_user_id, DROP COLUMN cancelled_at,
--   DROP COLUMN cancellation_reason, DROP COLUMN revision_number,
--   DROP COLUMN superseded_by_contract_id, DROP COLUMN supersedes_contract_id,
--   DROP COLUMN lifecycle_status;
