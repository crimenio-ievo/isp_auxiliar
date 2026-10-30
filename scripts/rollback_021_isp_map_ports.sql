-- Rollback manual da migration 021 (NAO executado automaticamente).
ALTER TABLE map_ports DROP COLUMN is_external, DROP COLUMN media;
DELETE FROM schema_migrations WHERE version = '021_isp_map_ports.sql' OR filename = '021_isp_map_ports.sql';
