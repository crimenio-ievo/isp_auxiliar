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

## CP3 — CLI `scripts/net_log_import.php` (feito)

- Classes: `NetLogImporter`, `Store/NetOutageStore` (interface) com `InMemoryNetOutageStore` e `PdoNetOutageStore`. Sem acesso à OLT.
- Uso: `php scripts/net_log_import.php --device=ID --file=ARQUIVO [--olt-tz=America/Sao_Paulo] [--apply]`. Simulação por padrão (nada gravado, resumo em JSON); `--apply` grava em UMA transação junto com a auditoria `net.log_import` (guarda só contagens, período, fuso e sha256 do conteúdo, nunca o texto do log). Falha de auditoria desfaz tudo.
- Idempotência: chave `event_key` (MAC + início no relógio da OLT) + índice único (device, source, event_key). Reimportar o mesmo arquivo: 0 novas, tudo "inalteradas". Queda em aberto é fechada pelo arquivo seguinte que traga o retorno (`atualizadas_com_retorno`); arquivo mais antigo/truncado nunca reabre queda já fechada (`mantidas_fechadas`).
- Grava também as ocorrências de manutenção/boot, com `category='manutencao'` e motivo, para auditoria; elas NÃO entram nas contagens de queda.
- MAC sem ONU em `net_onus` é gravado com `onu_id` NULL; se a ONU passar a existir, o próximo import do mesmo arquivo preenche o `onu_id` (sem duplicar e sem reabrir queda fechada). A leitura (CP4) também casa pelo MAC enquanto `onu_id` for NULL.
- Saída só com MAC mascarado (`xx`). Arquivo até 20 MB.
- **Fuso (DECISÃO SUA PENDENTE):** o syslog não traz fuso. O relógio da OLT pode estar em UTC ou em horário de Brasília; o servidor está em UTC. `--apply` exige `--olt-tz` explícito para não adivinhar; a simulação assume o fuso do app e imprime isso. A conversão vai do relógio da OLT para o fuso do app (UTC) antes de gravar. Como conferir: comparar um `down` do poll em `net_onu_events` (hora do servidor) com a mesma queda no log; a diferença é o fuso da OLT.
- Testes: `php tests/Feature/NetLogImportSmoke.php` → 29 verificações, 0 falhas (memória + schema descartável exclusivo `isp_auxiliar_net034imp_sim`, socket local): simulação sem escrita, contagens do fixture, MAC mascarado, apply, reimport sem duplicar, fuso, queda aberta → fechada, arquivo antigo não reabre, atomicidade com falha de auditoria (memória e PDO), equipamento inexistente, fuso inválido, arquivo vazio.
- A CLI contra o banco real (sem 034) responde "migration 034 não está aplicada"; validação de argumentos conferida à mão. Nenhuma escrita no banco principal.

## CP4 — leitura por período (feito, sem UI)

- `backend/Infrastructure/Net/NetOutageReader.php` (+ `pollTransitions()` na interface/stores). `period('1h'|'24h'|'7d'|'custom', from, to)` (custom: início <= fim, máx. 366 dias, sem fim = agora); `forOnu($deviceId, $onuId, $period, $includeMaintenance=false)` e `forPon($deviceId, $ponIndex, $period, ...)`.
- Cada queda: `started_at`, `ended_at` (retorno), `open`, `duration_s` (null se em aberto), `downtime_in_period_s` (recortado ao período, em aberto conta até agora), `cause` + `reason` (texto), `source` (`log`/`poll`), `precisao` (`exata` / `aproximada (ciclo de status)`), MAC mascarado. Totais: quedas, em aberto, downtime no período, por motivo, do log/do poll, log sem ONU cadastrada.
- **Poll × log sem contar duas vezes:** a queda do poll (par down→up de `net_onu_events`) é descartada quando uma queda do log (inclusive manutenção/boot) da mesma ONU a cobre: início do poll entre início do log e fim do log, com folga de um ciclo de status (`status_interval_s`, 600 s). Sem log, a do poll aparece com `source=poll`, motivo "desconhecida". O poll olha até 30 dias antes do período para pegar quedas que começaram antes.
- Por PON: só ONUs presentes em `net_onus` (o índice 0/x:y do log é ignorado de propósito). Quedas do log de MAC sem ONU cadastrada ficam em `log_sem_onu_cadastrada`.
- Teste: `php tests/Feature/NetOutageReaderSmoke.php` → 31 verificações, 0 falhas (memória + schema descartável `isp_auxiliar_net034rd_sim`).

## Resumo final

**Pronto:** parser (CP1), migration 034 + rollback testados em schema descartável e NÃO aplicados (CP2), CLI de importação idempotente (CP3), leitura por ONU/PON e período (CP4). Nenhum contato com a OLT, nada gravado no banco principal, sem cron, sem push, `.env` não lido, log completo fora do Git.

**Testes (todos 0 falhas):** OltLogParser 37, NetLogImport 29, NetOutageReader 31, Net034Migration 9, Net033Migration 20, NetStatusCollector 117, NetPowerCollector 44, NetAlertEngine 43, NetMaintenance 27, NetTick 34, MapRouteSmoke 12, MigrationAuditor 18, mapa 25 cenários.

**Falta / decisões suas:**
1. **Fuso do relógio da OLT** (UTC ou Brasília?): o log não diz. Define o `--olt-tz` do import.
2. Aplicar a 034 no `isp_auxiliar` (só com sua autorização).
3. Os alertas da 2B NÃO usam as quedas do log (continuam no poll). Integrar "queda por energia" ao `NetAlertEngine` é etapa à parte.
4. UI/endpoint de leitura (só funções de repositório nesta etapa).
5. Importação periódica automática (hoje manual por arquivo exportado pela web; a OLT não tem export por SNMP identificado).
6. Quedas do log só viram "por PON" se a ONU existir em `net_onus`.
7. `first time linkup` fecha queda aberta (decisão 1 do CP1): me diga se prefere o contrário.

## Import real supervisionado (NÃO executar sem autorização)

```sh
cd /var/www/html/_isp_auxiliar_telemetria_fibra
git status --short && git log --oneline -5               # conferir branch feature/telemetria-fibra
php scripts/apply_migrations.php                         # aplica SÓ a 034 (conferir que é a única pendente)
# Simulação (nada gravado). Teste os dois fusos e compare:
php scripts/net_log_import.php --device=1 --file="/var/www/html/isp_auxiliar/tmp/sw (2).log" --olt-tz=UTC
php scripts/net_log_import.php --device=1 --file="/var/www/html/isp_auxiliar/tmp/sw (2).log" --olt-tz=America/Sao_Paulo
# Esperado no arquivo de exemplo: 69 linkdown->retorno, 18 quedas (16 energia + 2 sem aviso), 51 manutenção (22 boot + 29 troca de PON), 0 em aberto.
# Conferir o fuso: comparar a hora de uma queda do log com um 'down' do poll da mesma ONU em net_onu_events.
php scripts/net_log_import.php --device=1 --file="/var/www/html/isp_auxiliar/tmp/sw (2).log" --olt-tz=<FUSO_ESCOLHIDO> --apply
# Idempotência: repetir o mesmo comando --apply deve dar novas=0, inalteradas=69.
```
Rollback da 034 se preciso: `scripts/rollback_034_net_onu_outages.sql` (destrói só as quedas importadas, reimportáveis do arquivo).
