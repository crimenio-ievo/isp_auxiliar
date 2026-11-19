-- Manual. Apaga as credenciais cifradas do banco (as NET_CRED_* do .env continuam valendo). Faca um dump antes.
-- Equipamentos que usavam credential_id ficam sem credencial ate voltarem a usar credential_ref.
UPDATE net_devices SET credential_ref = '' WHERE credential_ref IS NULL;
ALTER TABLE net_devices DROP FOREIGN KEY fk_net_devices_credential;
ALTER TABLE net_devices DROP COLUMN credential_id;
ALTER TABLE net_devices MODIFY credential_ref VARCHAR(120) NOT NULL;
DROP TABLE IF EXISTS net_credentials;
DELETE FROM schema_migrations WHERE version = '046_net_credentials.sql' OR filename = '046_net_credentials.sql';
