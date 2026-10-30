-- Rollback manual da migration 019 (NAO executado automaticamente).
-- Ordem: links -> ports -> nodes (dependencias de FK).
DROP TABLE IF EXISTS map_links;
DROP TABLE IF EXISTS map_ports;
DROP TABLE IF EXISTS map_nodes;
DELETE FROM schema_migrations WHERE version = '019_isp_map_core.sql' OR filename = '019_isp_map_core.sql';
