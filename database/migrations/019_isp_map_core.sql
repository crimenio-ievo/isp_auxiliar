CREATE TABLE IF NOT EXISTS map_nodes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NOT NULL,
    node_type VARCHAR(30) NOT NULL,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(60) NULL,
    lat DECIMAL(10,7) NULL,
    lng DECIMAL(10,7) NULL,
    parent_node_id BIGINT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'normal',
    attrs_json JSON NULL,
    equipment_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY map_nodes_provider_type (provider_id, node_type),
    KEY map_nodes_parent (parent_node_id),
    KEY map_nodes_provider_name (provider_id, name),
    CONSTRAINT fk_map_nodes_provider
        FOREIGN KEY (provider_id) REFERENCES providers(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_map_nodes_parent
        FOREIGN KEY (parent_node_id) REFERENCES map_nodes(id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_map_nodes_creator
        FOREIGN KEY (created_by_user_id) REFERENCES app_users(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_map_nodes_updater
        FOREIGN KEY (updated_by_user_id) REFERENCES app_users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS map_ports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NOT NULL,
    node_id BIGINT UNSIGNED NOT NULL,
    port_num SMALLINT UNSIGNED NOT NULL,
    label VARCHAR(60) NULL,
    port_role VARCHAR(20) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'livre',
    attrs_json JSON NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY map_ports_node_role_num (node_id, port_role, port_num),
    KEY map_ports_provider (provider_id),
    CONSTRAINT fk_map_ports_provider
        FOREIGN KEY (provider_id) REFERENCES providers(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_map_ports_node
        FOREIGN KEY (node_id) REFERENCES map_nodes(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_map_ports_creator
        FOREIGN KEY (created_by_user_id) REFERENCES app_users(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_map_ports_updater
        FOREIGN KEY (updated_by_user_id) REFERENCES app_users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS map_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NOT NULL,
    link_type VARCHAR(30) NOT NULL,
    medium VARCHAR(20) NOT NULL,
    from_node_id BIGINT UNSIGNED NOT NULL,
    to_node_id BIGINT UNSIGNED NOT NULL,
    from_port_id BIGINT UNSIGNED NULL,
    to_port_id BIGINT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'normal',
    label VARCHAR(120) NULL,
    length_m DECIMAL(10,2) NULL,
    fiber_count SMALLINT UNSIGNED NULL,
    attrs_json JSON NULL,
    path_json JSON NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY map_links_provider (provider_id),
    KEY map_links_from_node (from_node_id),
    KEY map_links_to_node (to_node_id),
    CONSTRAINT fk_map_links_provider
        FOREIGN KEY (provider_id) REFERENCES providers(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_map_links_from_node
        FOREIGN KEY (from_node_id) REFERENCES map_nodes(id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_map_links_to_node
        FOREIGN KEY (to_node_id) REFERENCES map_nodes(id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_map_links_from_port
        FOREIGN KEY (from_port_id) REFERENCES map_ports(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_map_links_to_port
        FOREIGN KEY (to_port_id) REFERENCES map_ports(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_map_links_creator
        FOREIGN KEY (created_by_user_id) REFERENCES app_users(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_map_links_updater
        FOREIGN KEY (updated_by_user_id) REFERENCES app_users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
