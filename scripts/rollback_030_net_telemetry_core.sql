-- Rollback manual da migration 030 (NAO executado automaticamente).
-- Apaga todas as tabelas net_ e os dados de telemetria coletados. Ordem respeita as FKs.
DROP TABLE IF EXISTS net_onu_events;
DROP TABLE IF EXISTS net_onu_state;
DROP TABLE IF EXISTS net_onus;
DROP TABLE IF EXISTS net_device_poll_state;
DROP TABLE IF EXISTS net_devices;
DROP TABLE IF EXISTS net_device_profiles;
DELETE FROM schema_migrations WHERE version = '030_net_telemetry_core.sql' OR filename = '030_net_telemetry_core.sql';
