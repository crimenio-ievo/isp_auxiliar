-- Manual. Nao restaura intervalos personalizados nem desfaz intervencoes/referencias zeradas.
DROP TABLE IF EXISTS net_interventions;
DROP TABLE IF EXISTS net_alerts;
ALTER TABLE net_devices DROP COLUMN IF EXISTS power_interval_s;
ALTER TABLE net_device_poll_state DROP COLUMN IF EXISTS last_power_attempt_at;
ALTER TABLE net_onu_power DROP COLUMN IF EXISTS bad_streak;
ALTER TABLE net_onu_power DROP COLUMN IF EXISTS ok_streak;
-- status_interval_s=600 e preservado: nao ha como distinguir ajuste manual de migracao.
