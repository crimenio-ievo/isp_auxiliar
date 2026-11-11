-- Telemetria de fibra - etapa 2A: perfil v3 (potencia da ONU por GET), tabelas de potencia e amostras, eventos de faixa e desvio.
-- Aditiva: nao altera as migrations 030 e 031. Rollback manual em scripts/rollback_032_net_power.sql.
-- Nao usar ponto e virgula dentro de textos ou comentarios: o aplicador separa comandos por ponto e virgula.

CREATE TABLE IF NOT EXISTS net_onu_power (
    onu_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    onu_rx_dbm DECIMAL(6,2) NULL,
    onu_tx_dbm DECIMAL(6,2) NULL,
    onu_temp_c DECIMAL(5,2) NULL,
    olt_rx_from_onu_dbm DECIMAL(6,2) NULL,
    measured_at DATETIME NOT NULL,
    rx_band ENUM('ok','atencao','critico','saturado','unknown') NOT NULL DEFAULT 'unknown',
    baseline_rx_dbm DECIMAL(6,2) NULL,
    baseline_set_at DATETIME NULL,
    baseline_n TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_sample_rx_dbm DECIMAL(6,2) NULL,
    last_sample_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_net_onu_power_onu FOREIGN KEY (onu_id) REFERENCES net_onus (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS net_onu_power_samples (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    onu_id BIGINT UNSIGNED NOT NULL,
    sampled_at DATETIME NOT NULL,
    onu_rx_dbm DECIMAL(6,2) NULL,
    onu_tx_dbm DECIMAL(6,2) NULL,
    onu_temp_c DECIMAL(5,2) NULL,
    reason ENUM('first','deadband','daily') NOT NULL,
    KEY net_onu_power_samples_onu_date (onu_id, sampled_at),
    KEY net_onu_power_samples_date (sampled_at),
    CONSTRAINT fk_net_onu_power_samples_onu FOREIGN KEY (onu_id) REFERENCES net_onus (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE net_onu_events MODIFY event_type ENUM('new','up','down','moved','removed','band_change','rx_deviation') NOT NULL;

INSERT IGNORE INTO net_device_profiles (code, vendor, technology, version, match_json, profile_json, status, created_at, updated_at)
VALUES (
    'hioso-epon-25355-4-4-v3',
    'Hioso',
    'epon',
    3,
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
        "compare": [
          "sys_object_id",
          "self_ip",
          "engine_id"
        ],
        "recheck_every_requests": 50
      },
      "pacing": {
        "interval_ms": 500,
        "timeout_ms": 3000,
        "retries": 0,
        "abort_after_consecutive_timeouts": 2,
        "abort_on_unreachable": true,
        "max_requests_per_cycle": 300,
        "operations": [
          "get",
          "getnext"
        ]
      },
      "index": {
        "decoder": "split_dots",
        "parts": [
          "slot",
          "pon",
          "onu"
        ]
      },
      "status_walk": [
        "onu_identifier"
      ],
      "status": {
        "validated": "derivado",
        "note": "Status derivado do RTT (coluna 25): online se RTT maior que 0, offline se 0. Validado em 5 indices (RTT 0 nos 3 registros antigos lidos e col 39 = 2, RTT positivo e col 39 = 1 nas 2 ONUs vivas). Coluna 39 concorda mas nao e usada. Potencia e temperatura de ONU que nao esta online nao valem."
      },
      "metrics": {
        "onu_identifier": {
          "oid": "1.3.6.1.4.1.25355.3.2.6.3.2.1.11",
          "type": "hex12_string",
          "identifier_type": "epon_mac"
        },
        "oper_status": {
          "oid": "1.3.6.1.4.1.25355.3.2.6.3.2.1.25",
          "type": "int_threshold",
          "online_if_gt": 0,
          "read": "get_by_index"
        }
      },
      "agent_quirks": {
        "transient_nosuch": "O agente devolve noSuchInstance de forma intermitente para celulas que existem (7 de 123 GETs, todas presentes na releitura) e o GETNEXT pode pular linhas (DDM das ONUs 1.1.21 a 1.1.23). ONU conhecida ausente do walk e conferida por GET direto antes de contar como ausente."
      },
      "pending_metrics": {
        "ports_eth": {
          "oid": "1.3.6.1.4.1.25355.3.2.6.3.2.1.13",
          "type": "int",
          "validated": "7 de 7 ONUs do gabarito"
        },
        "rtt_raw": {
          "oid": "1.3.6.1.4.1.25355.3.2.6.3.2.1.25",
          "type": "int",
          "unit": "unidade bruta do firmware (hipotese: quantum de 16 ns)",
          "validated": "7 de 7 ONUs do gabarito"
        },
        "distance_m": {
          "derived_from": "rtt_raw",
          "formula": "(rtt_raw - 193.2) * 1.6395",
          "validated": "ajuste em 7 ONUs com erro maximo de 0,4 m, derivada e nao e coluna SNMP"
        },
        "oper_status_corroboration": {
          "oid": "1.3.6.1.4.1.25355.3.2.6.3.2.1.39",
          "type": "int",
          "validated": "1 = online, 2 = offline em 5 indices"
        }
      },
      "not_identified": [
        "deregister_count",
        "offline_reason",
        "olt_rx_from_onu",
        "fdb",
        "onu_uptime",
        "onu_model_firmware"
      ],
      "removal_after_missing_cycles": 3,
      "status_get": [
        "oper_status"
      ],
      "enumeration": {
        "probe": {
          "slots": [
            1
          ],
          "pons": [
            1,
            2,
            3,
            4
          ],
          "stop_after_absent": 2,
          "max_onu": 128
        },
        "note": "GETNEXT do agente pula linhas em qualquer coluna (coluna 11 e coluna 39). Depois do walk, cada indice slot.pon.onu e conferido por GET (ausencia = duas leituras sem valor, apos o maior indice visto na PON)."
      },
      "stale_registrations": {
        "rule": "MAC repetido em mais de um indice: vale o registro vivo (RTT maior que 0). Os demais sao registros antigos (ex.: PON 4 com RTT 0) e nao viram ONU nem evento."
      },
      "power_get": {
        "onu_rx_dbm": {
          "oid": "1.3.6.1.4.1.25355.3.2.6.14.2.1.8",
          "type": "string_float",
          "only_when": "online",
          "unit": "dBm"
        },
        "onu_tx_dbm": {
          "oid": "1.3.6.1.4.1.25355.3.2.6.14.2.1.4",
          "type": "string_float",
          "only_when": "online",
          "unit": "dBm"
        },
        "onu_temp_c": {
          "oid": "1.3.6.1.4.1.25355.3.2.6.14.2.1.7",
          "type": "string_float",
          "only_when": "online",
          "unit": "C"
        }
      },
      "power_note": "Leitura por GET no indice slot.pon.onu (o GETNEXT da tabela pula linhas). ONU nao online devolve valor congelado ou na: nao ler nem gravar. Rx medido na OLT (olt_rx_from_onu) nao existe por SNMP."
    }',
    'active',
    NOW(),
    NOW()
);

UPDATE net_devices
SET profile_id = (SELECT id FROM net_device_profiles WHERE code = 'hioso-epon-25355-4-4-v3'), updated_at = NOW()
WHERE profile_id = (SELECT id FROM net_device_profiles WHERE code = 'hioso-epon-25355-4-4-v2');

UPDATE net_device_profiles SET status = 'deprecated', updated_at = NOW() WHERE code = 'hioso-epon-25355-4-4-v2';
