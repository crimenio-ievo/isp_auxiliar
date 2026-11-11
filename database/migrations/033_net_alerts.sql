-- Telemetria 2B, aditiva e reaplicavel. Rollback manual em scripts/rollback_033_net_alerts.sql.
CREATE TABLE IF NOT EXISTS net_alerts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NOT NULL,
    device_id BIGINT UNSIGNED NOT NULL,
    alert_type ENUM('device_unreachable','mass_down_pon','onu_unstable','rx_range','rx_deviation') NOT NULL,
    scope_type ENUM('device','pon','onu') NOT NULL,
    scope_id BIGINT UNSIGNED NOT NULL,
    severity ENUM('info','atencao','critico') NOT NULL,
    opened_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    closed_at DATETIME NULL,
    silenced_until DATETIME NULL,
    details_json JSON NULL,
    open_scope BIGINT UNSIGNED GENERATED ALWAYS AS (IF(closed_at IS NULL, scope_id, NULL)) PERSISTENT,
    UNIQUE KEY net_alerts_open (alert_type, scope_type, open_scope),
    KEY net_alerts_closed (closed_at),
    KEY net_alerts_device_closed (device_id, closed_at),
    CONSTRAINT fk_net_alerts_device FOREIGN KEY (device_id) REFERENCES net_devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mantem o silencio de escopo inclusive para alertas que ainda nao existem.
CREATE TABLE IF NOT EXISTS net_interventions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NOT NULL,
    device_id BIGINT UNSIGNED NOT NULL,
    scope_type ENUM('pon','onu') NOT NULL,
    scope_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    silenced_until DATETIME NOT NULL,
    reason VARCHAR(500) NOT NULL DEFAULT '',
    KEY net_interventions_device_until (device_id, silenced_until),
    CONSTRAINT fk_net_interventions_device FOREIGN KEY (device_id) REFERENCES net_devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE net_devices ADD COLUMN IF NOT EXISTS power_interval_s INT UNSIGNED NOT NULL DEFAULT 1800;
UPDATE net_devices SET status_interval_s = 600 WHERE status_interval_s = 300;
ALTER TABLE net_device_poll_state ADD COLUMN IF NOT EXISTS last_power_attempt_at DATETIME NULL;
ALTER TABLE net_onu_power ADD COLUMN IF NOT EXISTS bad_streak SMALLINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE net_onu_power ADD COLUMN IF NOT EXISTS ok_streak SMALLINT UNSIGNED NOT NULL DEFAULT 0;
