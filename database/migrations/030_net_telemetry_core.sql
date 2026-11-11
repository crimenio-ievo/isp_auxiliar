-- Telemetria de fibra - etapa 1: perfis, equipamentos, estado de coleta, inventario e estado atual das ONUs, eventos.
-- Aditiva: so cria tabelas novas (prefixo net_) e um perfil semente. Rollback manual em scripts/rollback_030_net_telemetry_core.sql.
-- Nao usar ponto e virgula dentro de textos ou comentarios: o aplicador separa comandos por ponto e virgula.

CREATE TABLE IF NOT EXISTS net_device_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(80) NOT NULL,
    vendor VARCHAR(80) NOT NULL,
    technology ENUM('epon','gpon','other') NOT NULL DEFAULT 'other',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    match_json JSON NULL,
    profile_json JSON NOT NULL,
    status ENUM('draft','active','deprecated') NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY net_device_profiles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS net_devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NOT NULL,
    map_node_id BIGINT UNSIGNED NOT NULL,
    profile_id BIGINT UNSIGNED NOT NULL,
    mgmt_host VARCHAR(255) NOT NULL,
    snmp_version ENUM('2c','3') NOT NULL DEFAULT '2c',
    credential_ref VARCHAR(120) NOT NULL,
    expected_identity_json JSON NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    status_interval_s INT UNSIGNED NOT NULL DEFAULT 300,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY net_devices_map_node (map_node_id),
    KEY net_devices_provider (provider_id),
    KEY net_devices_profile (profile_id),
    CONSTRAINT fk_net_devices_provider FOREIGN KEY (provider_id) REFERENCES providers (id) ON DELETE CASCADE,
    CONSTRAINT fk_net_devices_map_node FOREIGN KEY (map_node_id) REFERENCES map_nodes (id) ON DELETE RESTRICT,
    CONSTRAINT fk_net_devices_profile FOREIGN KEY (profile_id) REFERENCES net_device_profiles (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS net_device_poll_state (
    device_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    last_attempt_at DATETIME NULL,
    last_success_at DATETIME NULL,
    last_result ENUM('ok','timeout','identity_mismatch','partial','error') NULL,
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    identity_seen_json JSON NULL,
    requests_last_cycle INT UNSIGNED NULL,
    cycle_ms INT UNSIGNED NULL,
    last_uptime_ticks BIGINT UNSIGNED NULL,
    last_error VARCHAR(500) NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_net_poll_state_device FOREIGN KEY (device_id) REFERENCES net_devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS net_onus (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NOT NULL,
    device_id BIGINT UNSIGNED NOT NULL,
    slot SMALLINT UNSIGNED NULL,
    pon_index SMALLINT UNSIGNED NOT NULL,
    onu_index SMALLINT UNSIGNED NOT NULL,
    snmp_index VARCHAR(64) NOT NULL,
    onu_identifier VARCHAR(64) NOT NULL,
    identifier_type ENUM('epon_mac','gpon_sn') NOT NULL,
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    removed_at DATETIME NULL,
    UNIQUE KEY net_onus_identifier (provider_id, identifier_type, onu_identifier),
    KEY net_onus_device_pon_onu (device_id, pon_index, onu_index),
    CONSTRAINT fk_net_onus_provider FOREIGN KEY (provider_id) REFERENCES providers (id) ON DELETE CASCADE,
    CONSTRAINT fk_net_onus_device FOREIGN KEY (device_id) REFERENCES net_devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS net_onu_state (
    onu_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    oper_status ENUM('online','offline','unknown') NOT NULL DEFAULT 'unknown',
    raw_status_code VARCHAR(32) NULL,
    status_since DATETIME NULL,
    stale TINYINT(1) NOT NULL DEFAULT 0,
    missing_cycles SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_net_onu_state_onu FOREIGN KEY (onu_id) REFERENCES net_onus (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS net_onu_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    onu_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM('new','up','down','moved','removed') NOT NULL,
    details_json JSON NULL,
    occurred_at DATETIME NOT NULL,
    KEY net_onu_events_onu_date (onu_id, occurred_at),
    KEY net_onu_events_date (occurred_at),
    CONSTRAINT fk_net_onu_events_onu FOREIGN KEY (onu_id) REFERENCES net_onus (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO net_device_profiles (code, vendor, technology, version, match_json, profile_json, status, created_at, updated_at)
VALUES (
    'hioso-epon-25355-4-4-v1',
    'Hioso',
    'epon',
    1,
    '{"sys_object_id": "1.3.6.1.4.1.25355.4.4"}',
    '{
      "schema": 1,
      "identity": {
        "oids": {
          "sys_object_id": "1.3.6.1.2.1.1.2.0",
          "self_ip": "1.3.6.1.4.1.25355.3.1.1.0",
          "engine_id": "1.3.6.1.6.3.10.2.1.1.0",
          "uptime": "1.3.6.1.2.1.1.3.0",
          "sys_name": "1.3.6.1.2.1.1.5.0"
        },
        "compare": ["sys_object_id", "self_ip", "engine_id"],
        "recheck_every_requests": 100
      },
      "pacing": {
        "interval_ms": 500,
        "timeout_ms": 3000,
        "retries": 0,
        "abort_after_consecutive_timeouts": 2,
        "abort_on_unreachable": true,
        "max_requests_per_cycle": 300,
        "operations": ["get", "getnext"]
      },
      "index": {"decoder": "split_dots", "parts": ["slot", "pon", "onu"]},
      "status_walk": ["onu_identifier"],
      "status": {
        "validated": false,
        "note": "Sem coluna de status validada: a coluna 13 da tabela de ONUs e o numero de portas Ethernet, nao o status. Enquanto nao houver status validado, oper_status fica unknown e o coletor nao gera eventos up ou down. Candidata nao validada: coluna 39 (vale 1 nas ONUs online)."
      },
      "metrics": {
        "onu_identifier": {"oid": "1.3.6.1.4.1.25355.3.2.6.3.2.1.11", "type": "hex12_string", "identifier_type": "epon_mac"}
      },
      "agent_quirks": {
        "transient_nosuch": "O agente devolve noSuchInstance de forma intermitente para celulas que existem (7 de 123 GETs, todas presentes na releitura) e o GETNEXT pode pular linhas (DDM das ONUs 1.1.21 a 1.1.23). ONU conhecida ausente do walk e conferida por GET direto antes de contar como ausente."
      },
      "pending_metrics": {
        "ports_eth": {"oid": "1.3.6.1.4.1.25355.3.2.6.3.2.1.13", "type": "int", "validated": "7 de 7 ONUs do gabarito"},
        "rtt_raw": {"oid": "1.3.6.1.4.1.25355.3.2.6.3.2.1.25", "type": "int", "unit": "unidade bruta do firmware (hipotese: quantum de 16 ns)", "validated": "7 de 7 ONUs do gabarito"},
        "distance_m": {"derived_from": "rtt_raw", "formula": "(rtt_raw - 193.2) * 1.6395", "validated": "ajuste em 7 ONUs com erro maximo de 0,4 m, derivada e nao e coluna SNMP"},
        "onu_rx_dbm": {"oid": "1.3.6.1.4.1.25355.3.2.6.14.2.1.8", "type": "string_float", "read": "get_by_index", "validated": "5 ONUs do gabarito, diferenca ate 0,2 dB"},
        "onu_tx_dbm": {"oid": "1.3.6.1.4.1.25355.3.2.6.14.2.1.4", "type": "string_float", "read": "get_by_index", "validated": "1 ONU do gabarito"},
        "onu_temp_c": {"oid": "1.3.6.1.4.1.25355.3.2.6.14.2.1.7", "type": "string_float", "read": "get_by_index", "validated": "1 ONU do gabarito"},
        "oper_status_candidate": {"oid": "1.3.6.1.4.1.25355.3.2.6.3.2.1.39", "type": "int", "validated": false}
      },
      "not_identified": ["deregister_count", "offline_reason", "olt_rx_from_onu", "fdb", "onu_uptime", "onu_model_firmware"],
      "removal_after_missing_cycles": 3
    }',
    'active',
    NOW(),
    NOW()
);
