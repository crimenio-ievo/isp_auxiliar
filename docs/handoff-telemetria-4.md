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

## CP3 — testes e suíte completa (feito)

- Novos testes: `tests/Feature/NetFiberReaderSmoke.php` (63 verificações: rotas só GET, anônimo → login, menu só gestor/admin, leitor com schema descartável 001–034, controller com `LocalRepository` real, validações 403/422/404, página 200 com shell, contrato de chaves da API, sem 034) e `tests/net/rede_fibra.js` (jsdom, 50 verificações: árvore, filtros, colunas, recolher, ficha, régua, gráfico/faixas/cursor/ampliar, períodos, estável, em aberto, erros, XSS por texto, sem erro de console). Atalho: `bash tests/net/run_all.sh`.
- Os dois testes compartilham a lista de chaves da API: se a API mudar, ambos falham até serem atualizados juntos.
- `MobileNavMoreSmoke` foi atualizado de propósito (o painel "Mais" do gestor agora tem 5 itens, com Rede de fibra).
- **Suíte completa (este worktree):** `tests/Feature/*.php` + `AcceptanceResendRegression.php`: 45 passam, 1 falha; `bash tests/map/run_all.sh`: 25 cenários, 0 falhas; `bash tests/net/run_all.sh`: 0 falhas.
- **A única falha é `ReleaseScriptsSmoke.php`:** "O cenário exige todas as migrations aplicadas com checksums corretos." Hipótese (não confirmada trocando de branch, o que é proibido neste worktree): é a migration 034 existir no repositório e NÃO estar aplicada no `isp_auxiliar` (esse teste confere o estado real das migrations); deve voltar a passar quando a 034 for aplicada com autorização. Não está relacionada ao código desta etapa.

## O que falta

- Aplicar a 034 (e importar o syslog) para as quedas terem hora exata e motivo na tela; hoje a tela avisa que só há quedas do poll.
- Vínculo cliente↔ONU e CTO/porta (contrato de dados de rede): hoje "sem vínculo" / "ainda não lido".
- Sinal recebido na OLT (`olt_rx_dbm`): não existe por SNMP identificado.
- Conferência visual em navegador (não há navegador neste servidor).
- Distância é estimativa pelo RTT; trocar quando houver fonte direta.

## Ajustes pós-CP3 (2026-11-13)

### 1. Horário em Brasília na tela
- A API entrega horários em UTC (`AAAA-MM-DD hh:mm:ss`). `public/assets/js/rede-fibra.js` agora interpreta como UTC e mostra tudo em `America/Sao_Paulo` via `Intl.DateTimeFormat`: `fmtDT` (árvore, resumo, listas, ficha), eixo do gráfico (dia = meia-noite de Brasília), cursor e dicas das faixas de queda. O texto do período indica "horário de Brasília".
- Período personalizado: os campos `datetime-local` são horário de Brasília; antes de enviar, `de`/`ate` são convertidos para UTC (08:00 → 11:00; 23:30 → 02:30 do dia seguinte). O campo continua mostrando o que o usuário digitou; `ate` vazio não é enviado.
- Servidor e API inalterados. Teste: `node tests/net/rede_fibra.js` (60 verificações) cobre as conversões, o eixo, o cursor e as listas.
