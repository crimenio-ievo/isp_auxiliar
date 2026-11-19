-- Manual. Apaga o catalogo de modelos, o equipamento fisico e o historico de movimentacao. Faca um dump antes.
-- Nao altera map_nodes (equipment_id pode ficar apontando para um id que nao existe mais: rode antes o UPDATE abaixo).
UPDATE map_nodes SET equipment_id = NULL WHERE equipment_id IS NOT NULL AND equipment_id IN (SELECT id FROM net_equipment);
DROP TABLE IF EXISTS net_equipment_movements;
DROP TABLE IF EXISTS net_equipment;
DROP TABLE IF EXISTS net_equipment_models;
DELETE FROM schema_migrations WHERE version = '047_net_equipment_catalog.sql' OR filename = '047_net_equipment_catalog.sql';
