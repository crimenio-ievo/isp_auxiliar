-- Rollback manual da migration 031 (NAO executado automaticamente).
-- Volta os equipamentos para o perfil v1, reativa o v1 e remove o v2.
UPDATE net_devices SET profile_id = (SELECT id FROM net_device_profiles WHERE code = 'hioso-epon-25355-4-4-v1'), updated_at = NOW() WHERE profile_id = (SELECT id FROM net_device_profiles WHERE code = 'hioso-epon-25355-4-4-v2');
UPDATE net_device_profiles SET status = 'active', updated_at = NOW() WHERE code = 'hioso-epon-25355-4-4-v1';
DELETE FROM net_device_profiles WHERE code = 'hioso-epon-25355-4-4-v2';
DELETE FROM schema_migrations WHERE version = '031_net_profile_hioso_v2.sql' OR filename = '031_net_profile_hioso_v2.sql';
