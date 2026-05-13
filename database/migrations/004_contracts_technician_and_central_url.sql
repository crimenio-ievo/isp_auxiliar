ALTER TABLE client_contracts
    ADD COLUMN technician_name VARCHAR(160) NULL AFTER mkauth_login,
    ADD COLUMN technician_login VARCHAR(80) NULL AFTER technician_name;

ALTER TABLE contract_acceptances
    ADD COLUMN technician_name VARCHAR(160) NULL AFTER contract_id,
    ADD COLUMN technician_login VARCHAR(80) NULL AFTER technician_name;
