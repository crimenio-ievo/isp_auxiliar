# Telemetria fibra — Etapa 3: syslog da OLT (quedas com hora exata e motivo)

Worktree `/var/www/html/_isp_auxiliar_telemetria_fibra`, branch `feature/telemetria-fibra`. Sem contato com a OLT, sem migration no banco principal, sem cron, sem push, `.env` não lido. O log completo (`tmp/sw (2).log`) fica fora do Git.

## CP1 — parser puro (feito)

- `backend/Infrastructure/Net/OltLogParser.php`: sem banco e sem rede. `parse(string)` devolve `outages[]` e `summary`.
- Cada queda: `event_key` (sha256 de MAC|início, estável para idempotência), `mac`, `started_at`, `ended_at`, `duration_s`, `open`, `cause` (`energia` | `sem_aviso_energia`), `dying_gasp_at`, `maintenance` (`boot` | `troca_pon` | null), `counts_as_outage`, `returned_via`, `log_index_start/end` (só informativo, nunca chave).
- Regras implementadas como pedidas: timestamp entre colchetes (hora do relógio da OLT, SEM fuso: tratado como texto/UTC naive, nunca convertido); linhas sem colchetes ignoradas; chave = MAC; Dying Gasp ±5 s do linkdown = energia, senão "sem aviso de energia" (não afirma fibra); 3 Dying Gasp iguais = 1; linkup repetido sem linkdown = 1; queda = linkdown até o próximo linkup, sem linkup = em aberto; "first time linkup" não é queda; manutenção = até 2 min após "EPON System Start" (inclusive) e janela de troca de PON 2026-11-09 20:23:00–20:26:59 (janelas configuráveis no construtor).
- Decisões minhas (não estavam na especificação, documentadas e testadas):
  1. "first time linkup" fecha uma queda aberta do mesmo MAC (a ONU voltou, mesmo registrada de novo; no log real isso ocorre nas 10 ONUs da troca de PON). Sem isso ficariam "em aberto". Não cria queda.
  2. linkdown repetido sem linkup entre eles = 1 (vale o primeiro).
  3. Classificação manutenção/boot usa a hora do linkdown. A causa observada (energia/sem aviso) continua registrada mesmo quando não conta como queda.
  4. A regra de "várias ONUs no mesmo intervalo curto" foi implementada só como janela explícita de horário: uma regra automática confundiria queda em massa real (energia de uma CTO) com manutenção.
- Resultado no log real (somente leitura, local): 226 eventos de ONU; 20 Dying Gasp e 19 linkup duplicados descartados; 69 eventos linkdown→retorno: 22 boot, 29 troca de PON, **18 quedas reais** (16 por energia, 2 sem aviso de energia), nenhuma em aberto.
- Fixture `tests/fixtures/olt_syslog_reduced.log`: 65 linhas reais, MACs mascarados (OUI mantido, 3 últimos octetos trocados por contador sintético). Sem login/credencial; contém só os IPs de gerência internos já presentes nas linhas de login (10.4.0.2).
- Teste: `php tests/Feature/OltLogParserSmoke.php` → 37 verificações, 0 falhas (cada regra acima + fixture).

## CP2 — auditoria de net_onu_events e migration 034 (feita, NÃO aplicada)

FATO (auditoria de 030/032 e do código):
- `net_onu_events`: `id, onu_id (FK net_onus), event_type ENUM(new,up,down,moved,removed,band_change,rx_deviation), details_json, occurred_at`; índices (onu_id, occurred_at) e (occurred_at). Os eventos `up`/`down` são PONTUAIS, gravados pelo poll com a hora do ciclo (precisão de ~10 min), sem fim, duração, motivo, origem ou chave de idempotência.
- O `NetAlertEngine` consome `up`/`down` (massa por PON, ONU instável, fechamento de alerta). Gravar quedas do log ali como `down`/`up` dobraria a contagem e mudaria alertas já ativos.

Conclusão: NÃO reaproveitar `net_onu_events` para as quedas do log (falta intervalo, motivo, origem e unicidade; e há risco de contagem dobrada nos alertas). Eles continuam sendo a fonte do poll.

Entregue:
- `database/migrations/034_net_onu_outages.sql` (aditiva, `IF NOT EXISTS`, reaplicável) + `scripts/rollback_034_net_onu_outages.sql`. Tabela `net_onu_outages`: uma linha por queda, `onu_identifier` (MAC, 12 hex) sempre guardado, `onu_id` NULL quando o MAC não está em `net_onus` (FK ON DELETE SET NULL), `source` ('log'|'poll'), `event_key` (sha256 MAC|início), `started_at/ended_at/duration_s`, `cause`, `category`, `maintenance_reason`, `dying_gasp_at`, `returned_via`. Único (device_id, source, event_key) = reimportar não duplica. Não altera `net_onu_events`.
- Poll × log (sem contar duas vezes) será resolvido na leitura (CP3/CP4): a queda do poll é derivada de pares down→up em `net_onu_events` e descartada quando há queda do log da mesma ONU que a cubra; o log tem preferência.
- Teste: `php tests/Feature/Net034MigrationSmoke.php` (schema exclusivo `isp_auxiliar_net034_sim`, socket local, sem `.env`, removido ao final): aplicar/reaplicar, unicidade, source distinto, FK, onu_id NULL, SET NULL, rollback, reaplicar após rollback — 9 verificações. `Net033MigrationSmoke` segue 20/20.
- `scripts/net_register_device.php`: nova validação de que o `port_num` das PONs do nó é exatamente 1..N (contrato `port_num == pon_index`). Sem `net_device_pon_ports`. Simulação rodada contra o nó 42: sem novo erro.
- **034 NÃO aplicada no `isp_auxiliar`.** Aplicação futura, só com autorização: `php scripts/apply_migrations.php`.
