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
