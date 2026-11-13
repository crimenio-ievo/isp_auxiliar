# Telemetria fibra — Etapa 4: tela "Rede de fibra" (somente leitura)

Worktree `_isp_auxiliar_telemetria_fibra`, branch `feature/telemetria-fibra`, só no .89 de teste. Sem push. Não altera MkAuth, OLT nem o banco principal (nenhuma migration aplicada, nenhuma escrita). A migration 034 continua NÃO aplicada no `isp_auxiliar`: a tela funciona sem ela (só quedas do poll, `fontes.quedas_log=false`).

## CP1 — endpoints JSON de leitura (feito)

- `backend/Controllers/NetFiberController.php` (gestor/admin como o MapController; 403 JSON, `Cache-Control: private, no-store`; só GET, por isso sem CSRF/auditoria; não lê MkAuth).
  - `GET /api/rede-fibra[?dispositivo=ID]` → árvore OLT > PON > ONU.
  - `GET /api/rede-fibra/onu?id=ID&periodo=1h|24h|7d|custom[&de=&ate=]` → ficha (`de`/`ate` aceitam `AAAA-MM-DD hh:mm[:ss]` ou `datetime-local`; custom exige `de`, início <= fim, máx. 366 dias; 422 para entrada inválida, 404 para ONU inexistente/de outro provedor/removida).
  - `GET /rede-fibra` (página; CP2). Rotas em `backend/routes.php`; item de menu "Rede de fibra" (ícone próprio) só para gestor/admin em `layouts/sidebar.php`.
- `backend/Infrastructure/Net/NetFiberReader.php` (somente SELECT) + `Store/PollOnlyOutageStore.php` (quedas do poll quando a tabela da 034 não existe) + `NetOutageReader::forDevice()`.
- Árvore: equipamento (nome do nó do mapa), PONs do mapa (`port_num == pon_index`, inclusive vazias), ONUs ativas com MAC, estado e desde quando, sinal (`rx_dbm`, `rx_band`, `rx_medido_em`), `tx_dbm`, temperatura, distância, `quedas_24h`, `cliente` e `cto` (sempre null hoje), mais `resumo`, `limiares` e `fontes`.
- Ficha: leitura atual e referência (mediana das 5 primeiras leituras), mediana dos vizinhos (outras ONUs online da PON com leitura) e diferença, série de 7 dias de `net_onu_power_samples` (+ leitura atual), quedas do período (via `NetOutageReader`), últimas 5 quedas em 30 dias, `estavel` (sem queda no período = estável, com texto).
- Limiares: vêm de `NetPowerCollector::thresholds()` + `provider_settings net.*` (padrão atenção < −25, crítico < −27 dBm, saturado > −8).
- **Distância = ESTIMATIVA** pelo RTT guardado em `net_onu_state.raw_status_code`: (RTT − 193,2) × 1,6395 m. Hipótese validada em 7 ONUs (erro ≤ 0,4 m); rotulada `estimada pelo RTT`. Offline = sem distância.
- **O que não existe e volta como null/texto fixo:** vínculo cliente (não há tabela `client_network_bindings`), CTO/porta de CTO, sinal recebido na OLT (`olt_rx_dbm`, "ainda não lido").
- Sem afirmar fibra: motivos só `energia` ("Queda de energia (Dying Gasp)"), `sem_aviso_energia` ("Sem aviso de energia") ou "Detectada pelo poll, motivo não informado".
- Teste: `php tests/Feature/NetFiberReaderSmoke.php` → 48 verificações (schema descartável `isp_auxiliar_netfiber_sim` com TODAS as migrations 001–034, banco local via socket; controller com `LocalRepository` real: técnico 403/redirect, admin 200, validações, isolamento por provedor, sem 034). Também conferido SOMENTE LEITURA no banco real: 33 ONUs, 4 PONs (23/8/2/0), distância 22 638 m na ONU 1 (≈ 22,64 km), `quedas_log=false`.
