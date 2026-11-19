-- Vinculos de rede: cliente (MkAuth, so o login) <-> CTO/porta e ONU, mais as sugestoes recusadas.
-- Aditiva: so cria tabelas novas. Rollback manual em scripts/rollback_045_client_network_bindings.sql.
-- Uma linha por cliente ativo. Unicidade entre ativos por colunas geradas (MariaDB nao tem indice unico parcial):
-- um login, uma porta de CTO e uma ONU so podem ter um vinculo ativo. Historico fica com ativo = 0.
-- Nao usar ponto e virgula dentro de textos ou comentarios: o aplicador separa comandos por ponto e virgula.

CREATE TABLE IF NOT EXISTS client_network_bindings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NOT NULL,
    mkauth_login VARCHAR(120) NOT NULL,
    cliente_nome VARCHAR(160) NULL,
    cto_node_id BIGINT UNSIGNED NULL,
    cto_port_id BIGINT UNSIGNED NULL,
    onu_id BIGINT UNSIGNED NULL,
    origem ENUM('manual','sugestao_mac','instalacao') NOT NULL DEFAULT 'manual',
    confianca VARCHAR(12) NULL,
    confirmado_por VARCHAR(80) NULL,
    confirmado_em DATETIME NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    desativado_por VARCHAR(80) NULL,
    desativado_em DATETIME NULL,
    observacao VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    login_ativo VARCHAR(120) GENERATED ALWAYS AS (IF(ativo = 1, mkauth_login, NULL)) VIRTUAL,
    porta_ativa BIGINT UNSIGNED GENERATED ALWAYS AS (IF(ativo = 1, cto_port_id, NULL)) VIRTUAL,
    onu_ativa BIGINT UNSIGNED GENERATED ALWAYS AS (IF(ativo = 1, onu_id, NULL)) VIRTUAL,
    UNIQUE KEY cnb_login_ativo (provider_id, login_ativo),
    UNIQUE KEY cnb_porta_ativa (porta_ativa),
    UNIQUE KEY cnb_onu_ativa (onu_ativa),
    KEY cnb_cto (provider_id, cto_node_id, ativo),
    KEY cnb_login (provider_id, mkauth_login),
    CONSTRAINT fk_cnb_provider FOREIGN KEY (provider_id) REFERENCES providers (id) ON DELETE CASCADE,
    CONSTRAINT fk_cnb_cto FOREIGN KEY (cto_node_id) REFERENCES map_nodes (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cnb_port FOREIGN KEY (cto_port_id) REFERENCES map_ports (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cnb_onu FOREIGN KEY (onu_id) REFERENCES net_onus (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_network_binding_rejections (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NOT NULL,
    onu_id BIGINT UNSIGNED NOT NULL,
    mkauth_login VARCHAR(120) NOT NULL,
    rejected_by VARCHAR(80) NULL,
    rejected_at DATETIME NOT NULL,
    UNIQUE KEY cnbr_onu_login (provider_id, onu_id, mkauth_login),
    CONSTRAINT fk_cnbr_provider FOREIGN KEY (provider_id) REFERENCES providers (id) ON DELETE CASCADE,
    CONSTRAINT fk_cnbr_onu FOREIGN KEY (onu_id) REFERENCES net_onus (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
