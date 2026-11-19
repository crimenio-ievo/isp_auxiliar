-- Manual. Volta a UM cliente ativo por ONU e por porta e apaga a coluna modo_pppoe (o modo registrado se perde).
-- Faca um dump antes. O ALTER e atomico: se existir mais de um vinculo ativo na mesma ONU ou na mesma porta, o ADD UNIQUE falha,
-- NADA e alterado e o script para (use o mysql sem --force). Confira antes com:
--   SELECT onu_id, COUNT(*) FROM client_network_bindings WHERE ativo = 1 AND onu_id IS NOT NULL GROUP BY onu_id HAVING COUNT(*) > 1
--   SELECT cto_port_id, COUNT(*) FROM client_network_bindings WHERE ativo = 1 AND cto_port_id IS NOT NULL GROUP BY cto_port_id HAVING COUNT(*) > 1
-- e desative (ativo = 0) ou remova os excedentes pela tela antes de reverter.
ALTER TABLE client_network_bindings
    DROP INDEX IF EXISTS cnb_par_ativo,
    DROP INDEX IF EXISTS cnb_onu_ativa,
    DROP INDEX IF EXISTS cnb_porta_ativa,
    ADD UNIQUE KEY cnb_onu_ativa (onu_ativa),
    ADD UNIQUE KEY cnb_porta_ativa (porta_ativa),
    DROP COLUMN IF EXISTS modo_pppoe;
DELETE FROM schema_migrations WHERE version = '048_binding_n_clientes_por_onu.sql' OR filename = '048_binding_n_clientes_por_onu.sql';
