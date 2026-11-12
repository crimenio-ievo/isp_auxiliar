-- Telemetria etapa 3: quedas de ONU importadas do syslog da OLT (hora exata e motivo). Aditiva e reaplicavel.
-- Rollback manual em scripts/rollback_034_net_onu_outages.sql. NAO altera net_onu_events nem os alertas da 2B.
-- Nao usar ponto e virgula ou apostrofo dentro de textos ou comentarios: o aplicador separa comandos por ponto e virgula.
CREATE TABLE IF NOT EXISTS net_onu_outages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NOT NULL,
    device_id BIGINT UNSIGNED NOT NULL,
    onu_id BIGINT UNSIGNED NULL,
    onu_identifier VARCHAR(64) NOT NULL,
    source ENUM('log','poll') NOT NULL DEFAULT 'log',
    event_key CHAR(64) NOT NULL,
    started_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    duration_s INT UNSIGNED NULL,
    cause ENUM('energia','sem_aviso_energia','desconhecida') NOT NULL DEFAULT 'desconhecida',
    category ENUM('queda','manutencao') NOT NULL DEFAULT 'queda',
    maintenance_reason VARCHAR(30) NULL,
    dying_gasp_at DATETIME NULL,
    returned_via VARCHAR(20) NULL,
    imported_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY net_onu_outages_key (device_id, source, event_key),
    KEY net_onu_outages_onu_start (onu_id, started_at),
    KEY net_onu_outages_device_start (device_id, started_at),
    KEY net_onu_outages_open (device_id, ended_at),
    CONSTRAINT fk_net_onu_outages_device FOREIGN KEY (device_id) REFERENCES net_devices (id) ON DELETE CASCADE,
    CONSTRAINT fk_net_onu_outages_onu FOREIGN KEY (onu_id) REFERENCES net_onus (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
