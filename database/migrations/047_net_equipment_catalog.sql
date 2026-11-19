-- Catalogo de modelos e equipamento fisico (base do inventario). Aditiva: so cria tabelas e semeia fatos conhecidos.
-- Modelo (net_equipment_models) e a UNICA origem de fabricante/modelo. Equipamento fisico (net_equipment) aponta para o modelo e
-- para o local atual (no do mapa ou, no futuro, estoque/cliente). Um no do mapa tem no maximo um ativo, via map_nodes.equipment_id (ja existente).
-- Historico minimo em net_equipment_movements. Rollback manual em scripts/rollback_047_net_equipment_catalog.sql.
-- Nao usar ponto e virgula dentro de textos ou comentarios: o aplicador separa comandos por ponto e virgula.

CREATE TABLE IF NOT EXISTS net_equipment_models (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NOT NULL,
    vendor VARCHAR(80) NOT NULL,
    model VARCHAR(120) NOT NULL,
    equipment_type ENUM('olt','onu','ont','switch','roteador','radio','outro') NOT NULL DEFAULT 'outro',
    technology VARCHAR(40) NULL,
    ports_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL CHECK (ports_json IS NULL OR JSON_VALID(ports_json)),
    default_profile_code VARCHAR(80) NULL,
    spec_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL CHECK (spec_json IS NULL OR JSON_VALID(spec_json)),
    notes VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY net_equipment_models_unique (provider_id, vendor, model),
    CONSTRAINT fk_net_equipment_models_provider FOREIGN KEY (provider_id) REFERENCES providers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS net_equipment (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NOT NULL,
    model_id BIGINT UNSIGNED NOT NULL,
    serial VARCHAR(80) NULL,
    mac VARCHAR(17) NULL,
    asset_tag VARCHAR(60) NULL,
    status ENUM('estoque','instalado','manutencao','baixado') NOT NULL DEFAULT 'instalado',
    location_kind ENUM('no_mapa','estoque','cliente','outro') NOT NULL DEFAULT 'no_mapa',
    location_node_id BIGINT UNSIGNED NULL,
    location_note VARCHAR(160) NULL,
    notes VARCHAR(500) NULL,
    created_by VARCHAR(80) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY net_equipment_serial (provider_id, model_id, serial),
    UNIQUE KEY net_equipment_asset (provider_id, asset_tag),
    UNIQUE KEY net_equipment_mac (provider_id, mac),
    KEY net_equipment_node (location_node_id),
    CONSTRAINT fk_net_equipment_provider FOREIGN KEY (provider_id) REFERENCES providers (id) ON DELETE CASCADE,
    CONSTRAINT fk_net_equipment_model FOREIGN KEY (model_id) REFERENCES net_equipment_models (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS net_equipment_movements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NOT NULL,
    equipment_id BIGINT UNSIGNED NOT NULL,
    moved_at DATETIME NOT NULL,
    from_status VARCHAR(20) NULL,
    to_status VARCHAR(20) NOT NULL,
    from_node_id BIGINT UNSIGNED NULL,
    to_node_id BIGINT UNSIGNED NULL,
    note VARCHAR(255) NULL,
    actor VARCHAR(80) NULL,
    KEY net_equipment_movements_eq (equipment_id, moved_at),
    CONSTRAINT fk_net_equipment_movements_eq FOREIGN KEY (equipment_id) REFERENCES net_equipment (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO net_equipment_models (provider_id, vendor, model, equipment_type, technology, ports_json, default_profile_code, notes, created_at, updated_at)
SELECT id, 'Hioso', 'HA7004S', 'olt', 'EPON', '{"pon":4}', 'hioso-epon-25355-4-4-v3', 'Perfil de telemetria ativo.', NOW(), NOW() FROM providers;

INSERT IGNORE INTO net_equipment_models (provider_id, vendor, model, equipment_type, technology, ports_json, default_profile_code, notes, created_at, updated_at)
SELECT id, 'Hioso', 'HA7308VX', 'olt', 'EPON', '{"pon":8}', 'ha7308vx', 'Perfil de telemetria ainda nao suportado.', NOW(), NOW() FROM providers;

INSERT IGNORE INTO net_equipment_models (provider_id, vendor, model, equipment_type, technology, ports_json, default_profile_code, notes, created_at, updated_at)
SELECT id, 'V-SOL', 'a identificar', 'olt', NULL, NULL, 'vsol', 'Modelo ainda nao identificado. Perfil de telemetria ainda nao suportado.', NOW(), NOW() FROM providers;
