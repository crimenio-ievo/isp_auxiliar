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

## CP2 — página "Rede de fibra" (feita)

- `GET /rede-fibra` → `backend/Views/net/fiber.php` (layout do shell: menu lateral, cabeçalho de página, cartões do `app.css`) + `public/assets/css/rede-fibra.css` (só classes `rf-*`, usa as variáveis do app) + `public/assets/js/rede-fibra.js` (JS puro, sem biblioteca; todo texto vem do servidor via `textContent`).
- Árvore OLT > PON > ONU recolhível (PONs vazias aparecem), uma linha por ONU, clique/Enter abre a ficha. Filtros: OLT, PON, estado, busca por MAC, "Caíram em 24 h", "Sem vínculo". Colunas escolhíveis (Estado, Sinal, Faixa, Tx, Temp., Distância, Cliente, CTO, Quedas 24 h, MAC), escolha salva em `localStorage` (com try/catch).
- Ficha em painel lateral (embaixo no celular): régua de sinal de −30 a −12 dBm com as faixas crítico/atenção/normal vindas dos limiares do servidor e marcas de atual, referência e mediana dos vizinhos; gráfico SVG de 7 dias (linha em degraus pelas amostras) com as quedas como faixas verticais (em aberto vai até agora), cursor com data/hora/valor (e "em queda (motivo)" quando dentro de uma faixa), botão **Ampliar** (janela grande, Esc/× fecha); seletor de período 1 h / 24 h / 1 semana / personalizado (datetime-local → `de`/`ate`); lista do período e lista curta das últimas 5 quedas (início, retorno ou "em aberto", duração, motivo). "Sem queda registrada no período (estável)" quando não há registro.
- O que ainda não existe aparece como texto, nunca simulado: cliente "sem vínculo", CTO "ainda não lido", sinal recebido na OLT "ainda não lido", ONU sem leitura "sem leitura". Aviso no topo quando o log da OLT ainda não foi importado (quedas do poll: hora aproximada, sem motivo).
- Motivos exibidos: "Queda de energia (Dying Gasp)", "Sem aviso de energia", "Detectada pelo poll, motivo não informado". Nunca "fibra".
- Teste jsdom: `node tests/net/rede_fibra.js` (usa `tests/net/render_page.php` para renderizar a página real; fixtures de teste com o formato da API) → 48 verificações, 0 falhas, sem erro de console.
- **Limite honesto:** não há navegador neste servidor, então a aparência (CSS, tamanhos, responsividade) NÃO foi vista em tela real; só a estrutura/comportamento via jsdom. Conferir visualmente em /rede-fibra.
