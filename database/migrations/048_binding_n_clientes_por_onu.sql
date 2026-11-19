-- N clientes ativos por ONU/ONT (varias portas LAN, cada cliente disca PPPoE no proprio roteador) e modo por vinculo.
-- Altera so client_network_bindings (045). Rollback manual em scripts/rollback_048_binding_n_clientes_por_onu.sql.
-- 1) cnb_onu_ativa deixa de ser UNIQUE (varias linhas ativas na mesma ONU).
-- 2) cnb_par_ativo: UNIQUE por par ativo (provider, ONU, login). Redundante com cnb_login_ativo enquanto o login ativo for unico, mas protege o par.
-- 3) cnb_porta_ativa deixa de ser UNIQUE: a porta da CTO e ocupacao FISICA da ONU. Varias linhas ativas na mesma porta so sao
--    aceitas pelo codigo quando todas tem a MESMA ONU (regra na aplicacao, dentro de transacao com a porta travada).
-- 4) modo_pppoe: NULL = nao verificado, sem default.
-- Nao usar ponto e virgula dentro de textos ou comentarios: o aplicador separa comandos por ponto e virgula.

ALTER TABLE client_network_bindings
    DROP INDEX IF EXISTS cnb_par_ativo,
    DROP INDEX IF EXISTS cnb_onu_ativa,
    DROP INDEX IF EXISTS cnb_porta_ativa,
    ADD KEY cnb_onu_ativa (onu_ativa),
    ADD KEY cnb_porta_ativa (porta_ativa),
    ADD UNIQUE KEY cnb_par_ativo (provider_id, onu_ativa, login_ativo),
    ADD COLUMN IF NOT EXISTS modo_pppoe ENUM('pppoe_na_onu','atras_da_onu') NULL DEFAULT NULL AFTER confianca;
