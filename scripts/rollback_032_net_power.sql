-- Rollback manual da migration 032 (NAO executado automaticamente).
-- Apaga potencia/amostras, remove eventos band_change e rx_deviation, volta o equipamento ao perfil v2 e remove o v3.
UPDATE net_devices SET profile_id = (SELECT id FROM net_device_profiles WHERE code = 'hioso-epon-25355-4-4-v2'), updated_at = NOW() WHERE profile_id = (SELECT id FROM net_device_profiles WHERE code = 'hioso-epon-25355-4-4-v3');
UPDATE net_device_profiles SET status = 'active', updated_at = NOW() WHERE code = 'hioso-epon-25355-4-4-v2';
DELETE FROM net_device_profiles WHERE code = 'hioso-epon-25355-4-4-v3';
DROP TABLE IF EXISTS net_onu_power_samples;
DROP TABLE IF EXISTS net_onu_power;
DELETE FROM net_onu_events WHERE event_type IN ('band_change','rx_deviation');
ALTER TABLE net_onu_events MODIFY event_type ENUM('new','up','down','moved','removed') NOT NULL;
DELETE FROM schema_migrations WHERE version = '032_net_power.sql' OR filename = '032_net_power.sql';
