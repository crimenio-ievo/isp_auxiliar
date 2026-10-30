-- Rollback manual da migration 020 (NAO executado automaticamente).
ALTER TABLE map_links DROP COLUMN lifecycle;
ALTER TABLE map_nodes DROP KEY map_nodes_provider_lifecycle, DROP COLUMN lifecycle;
DELETE FROM schema_migrations WHERE version = '020_isp_map_lifecycle.sql' OR filename = '020_isp_map_lifecycle.sql';
