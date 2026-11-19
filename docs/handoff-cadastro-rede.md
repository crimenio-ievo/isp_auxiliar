# Handoff — cadastro de rede (feature/cadastro-rede)

Servidor .89 de TESTE, sessão autônoma de 2026-11-19. Convenção: **Fato** (verificado), **Hipótese**, **Não verificado**.
Branch `feature/cadastro-rede` (a partir do main 56ec7a4), desenvolvida na worktree temporária `/var/www/html/_isp_auxiliar_cadastro_rede`.
Nada tocado: .86, MkAuth (só SELECT), `/etc/cron.d/isp-net-telemetria`, outras OLTs. Sem push, sem usuário de teste.

## O que foi feito, por fase e commit
| Fase | Commit | Entrega |
|---|---|---|
| 0 | 0e93b7c | Apache: `isp-hardening.conf` + vhosts padrão negando `/var/www/html`; `.env`/`.git`/`storage` agora 403. Registro em `docs/handoff-seguranca-apache.md`. Commits de produção (3967195, 4af16c6, c19bbe0) já estão no main. |
| 1 | b43ae8d | Levantamento + `docs/rede-cadastro-guia.md` (como cadastrar hoje, lacunas, causa da busca global). |
| 2 | eb6a61d | `/rede-equipamentos` (cadastro de OLT sobre o nó do mapa, coleta nasce desativada, ativação com confirmação, teste de conexão), `net_tick.php --all`. |
| 3 | 60e7f00 (+ test(nav) a seguir) | `/rede-ctos` (lista, cadastro rápido, "lat, lng" colado, geolocalização, CSV com prévia e gravação tudo-ou-nada, exclusão protegida), `/mapa?no=ID` centra e abre o painel. |
| 4 | e4fb05f | Migration 045 `client_network_bindings` (aplicada), ficha da CTO, `/rede-vinculos` (sugestões pelo MAC: confirmar/recusar), Cliente/CTO reais e nível CTO na `/rede-fibra`. |
| 5 | d1ba235 | Busca global: CTO, PON, equipamento, ONU e clientes vinculados (CTO + ONU no resultado). |
| 6 | 0d796de | Flags `feature.map` / `feature.net` por provedor. |
| 7 | (este) | Handoff, roadmap, merge. |

Testes (Fato, todos verdes no fim): Feature (inclui `CadastroLocalDraft.js`), `tests/net/run_all.sh` (inclui jsdom das telas novas), `tests/map/run_all.sh` (25 de 25). Novos: `NetDeviceCadastroSmoke` (104), `NetTickAllSmoke` (19), `NetCtoSmoke` (109), `NetBindingSmoke` (101), `FeatureFlagsSmoke` (98), jsdom `rede_equipamentos` (35), `rede_ctos` (49), `rede_vinculos` (36), mais extensões em `NetFiberReaderSmoke` (121), `rede_fibra.js` (176), `global_network_search.js` (10). Todos usam schema descartável (`tests/support/ScratchSchema.php`), exceto a suíte `tests/map` (padrão antigo do projeto: grava nós `ZZ TESTE` no banco real e limpa; conferi depois: 4 nós, 0 `ZZ TESTE`).

## Fase 2 — equipamentos
- Teste real na OLT .89 (2 de 3 permitidos): 1º com timeout de 2 s → sem resposta; 2º com 3 s → respondeu (sysName `EPON HIOSO 1`, sysDescr `unknown`, uptime 8d 16h, 1,07 s). Hipótese: o agente SNMP às vezes passa de 2 s; por isso `NetConnectionTester::TIMEOUT_MS = 3000`. Só GET, nenhum SET.
- Decisões tomadas sozinho (alternativas): (a) perfil não suportado (V-SOL, HA7308VX, Outro) só grava `attrs.perfil_pretendido` no nó, sem `net_devices` (alternativa: perfis "draft" fictícios por migration); (b) mudar IP/perfil/credencial com coleta ativa desativa a coleta; (c) IPv4 apenas, sem loopback/link-local/multicast; (d) o teste usa o mesmo lock por equipamento da coleta e a trava de 30 s vem de `audit_logs` (sem migration); (e) item de menu "Rede de fibra" virou "Rede" (abas: Rede de fibra · Equipamentos · CTOs · Vínculos).
- Aprovação da identidade de referência da OLT continua só por CLI: sem ela, a coleta de um equipamento novo falha com `identity_mismatch` (3 GETs a cada 15 min após o backoff). A confirmação de ativação na tela avisa e mostra o comando.

## Fase 3 — CTOs
- Escrita via `MapNodeGateway` → `MapController` (mesmas regras, portas por capacidade, auditoria `map.*`). Mudança mínima no mapa: `createNode` não abre transação se já houver uma aberta (permite a importação em lote atômica); fora disso o comportamento é idêntico (`MapController::createNode` coberto no `NetCtoSmoke` e na suíte map).
- Origem opcional na criação: `site:ID` (distribuição CTO → POP/torre) ou `pon:PORTA_ID` (backbone OLT/PON → 1ª porta livre da CTO). Só ao criar ou em CTO sem ligações.
- Proximidade < 15 m: aviso com confirmação (cadastro) e linha "muito perto" (CSV: só entra se marcada). Nome duplicado (sem diferenciar maiúsculas) é bloqueado.
- CSV: UTF-8 ou Windows-1252, `,` ou `;`, decimal com vírgula, máx. 500 linhas / 256 KB, exemplo em `tests/fixtures/ctos_exemplo.csv`.
- Técnico (perfil `clients.view`) tem item "CTOs" no menu, só leitura. A busca de rede do topo segue só para gestor (teste existente exige 403 ao técnico).

## Fase 4 — vínculos
- **Migration 045** (`database/migrations/045_client_network_bindings.sql`, aditiva; rollback `scripts/rollback_045_client_network_bindings.sql`). Aplicada no banco do .89 em 2026-11-19 11:48 com o aplicador oficial, **dump antes**: `/root/isp-auxiliar-backups/isp_auxiliar-pre045-20261119-114821.sql` (fora do Git). Tabelas novas: `client_network_bindings` (uma linha por cliente ativo; unicidade de login, porta de CTO e ONU entre ativos por colunas geradas, testada) e `client_network_binding_rejections`. Estado real hoje: 0 vínculos.
- O MkAuth só é lido: `ClientDirectory` (interface) + `MkAuthClientDirectory`, que usa `searchClients`, `findClientProfile` e dois SELECTs novos em `MkAuthDatabase` (`recentCallerMacs`, `clientNamesByLogins`). O vínculo guarda só o login (+ nome como foto de exibição); a auditoria nunca guarda CPF.
- Sugestões pelo MAC (`OnuMatchSuggester`, regra do `MacMatcher`): alta (MAC igual ou ±1 no último octeto, candidato único), média (até 16), baixa (ambíguo/outro). Dado real (somente leitura, clone .88 parado em 2026-10-13): 33 ONUs sem vínculo → 12 com sugestão (5 alta, 7 média), 21 sem candidato; nada foi gravado.
- Proteção no mapa: excluir CTO com cliente vinculado e reduzir portas ocupadas por cliente dão 409 (também pelo mapa). Histórico (vínculos desativados) é apagado junto com a CTO; a trilha fica em `audit_logs`.
- `/rede-fibra`: cada ONU traz `cliente {login,nome}` e `cto {id,nome,porta}` (ou `null`); a árvore ganha o nível CTO ("sem CTO" para as demais). As listas de chaves da API **não mudaram** (só o formato do valor de `cliente`/`cto`), por isso os dois testes de chaves continuam iguais; texto "sem CTO" substitui "ainda não lido" na ficha quando a tabela existe.
- Colunas Cliente e CTO continuam ocultas por padrão (decisão anterior do usuário); ligáveis em "Colunas". Ver "PARA O USUÁRIO".
- "Vincular cliente a porta" no painel da CTO do mapa: só um link "Abrir a ficha da CTO" (a ficha é a tela de vínculo).

## Fase 5 — busca
- Fato (Fase 1d): a busca já funcionava no `main` para OLT por nome/IP e ONU por MAC. O que o usuário provavelmente não via: (1) `teste.ievo.com.br/isp_auxiliar/public` serve o branch `release/pre-maps-v0`, sem a busca de rede; (2) Enter envia só para `/clientes?q=`; (3) CTO/PON/vínculos não eram buscados. Hipótese não verificada: cache do `app.js` (o `?v=` usa o id de release fixo `beta-test-final-2026.08`; não mudei o layout porque `ClientModalPendingSmoke` fixa essa regra).
- Resultados novos: ficha da CTO, `/rede-fibra?dispositivo=ID&pon=N` (a tela agora pré-seleciona a PON), `/rede-equipamentos?no=ID` (destaca a OLT), `/rede-fibra?onu=ID`, perfil do cliente com CTO e ONU. Mudança em `public/assets/js/app.js` e `app.css` (tipo e detalhe do resultado).

## Fase 6 — flags
- Ordem: `provider_settings` (`feature.map`, `feature.net` = `1/0`) > `.env` (`FEATURE_MAP`, `FEATURE_NET`) > padrão **ligado em teste, DESLIGADO em produção** (`APP_ENVIRONMENT=production`, mesma regra do banner). Desligado: menu oculto, páginas 404, APIs 403 (inclusive anônimo), busca de rede e links cruzados omitidos. Decisão minha: padrão desligado em produção em vez de "ligado no .89 / desligado documentado no .86", porque esquecer de configurar o .86 não deve expor o módulo.
- Desligar no .86 não exige nada (padrão). Para ligar um provedor em produção: `INSERT INTO provider_settings (provider_id, setting_key, setting_value, is_secret, updated_at) VALUES (<id>, 'feature.net', '1', 0, NOW()) ON DUPLICATE KEY UPDATE setting_value = '1', updated_at = NOW();` (idem `feature.map`). Para desligar no .89: mesmo comando com `'0'`.
- `feature.net` não exige `feature.map` (a rede usa as tabelas do mapa; criar nós passa pelo controller do mapa internamente).

## Pendente / não verificado
- Não verificado: geolocalização em celular real (só jsdom com mock); capturas a 125% foram emuladas por viewport 1093 px com escala 1,25.
- Não implementado: aprovação da identidade da OLT pela tela; vínculo ONU↔cliente manual (sem sugestão); troca de origem de CTO que já tem ligações (fica no mapa); busca de rede para técnico; Cliente/CTO nas listas de `/clientes`.
- Perfis de OLT V-SOL e HA7308VX: sem perfil (precisa levantar OIDs com a OLT). Só Hioso EPON v3 coleta.
- Fase 0: sem logs antes de 01/11/2026; não dá para afirmar que ninguém leu o `.env` antes disso.
- Worktree `isp_auxiliar` (branch `release/pre-maps-v0`) continua com 2 arquivos modificados e 1 relatório não rastreado do usuário: não toquei.

## PARA O USUÁRIO
1. **Trocar o cron para `--all`** (não troquei). Linha sugerida: `* * * * * www-data php /var/www/html/_isp_auxiliar_unificacao/scripts/net_tick.php --all >/dev/null 2>&1`. Procedimento seguro: `pgrep -f scripts/net_tick.php` vazio e fora de um ciclo (status ~70 s a cada 10 min, potência ~77 s a cada 30 min); `cp -p /etc/cron.d/isp-net-telemetria /root/isp-auxiliar-backups/isp-net-telemetria.cron.bak-<data>`; editar só o trecho `--device=1` → `--all`; conferir 2–3 ticks em `storage/logs/net_tick-*.log`. Atenção: com `--all`, qualquer equipamento ativado na tela passa a ser coletado sozinho.
2. **Aprovar identidade** de toda OLT nova ativada pela tela: `php scripts/net_collect.php --device=ID --kind=status --approve-identity` (a tela mostra o comando ao ativar).
3. **Perfis faltantes**: V-SOL e HA7308VX só aparecem como "perfil ainda não suportado: coleta desativada". Preciso das OIDs/tabelas dessas OLTs (e acesso para um levantamento somente leitura).
4. **Migration 045**: aplicada no .89 (dump acima). Rollback: com o dump à mão, `mysql isp_auxiliar < scripts/rollback_045_client_network_bindings.sql` (apaga vínculos e recusas; o histórico também está em `audit_logs`, ações `net.binding.*`). As numerações 035–044 continuam reservadas.
5. **Produção (.86)** — o que falta: aplicar as migrations 019–021 (mapa) e 030–034 e 045 (rede) antes de ligar os módulos; decidir ligar `feature.map`/`feature.net` (padrão desligado); ter `APP_ENVIRONMENT=production` no .env do .86; credenciais `NET_CRED_*` no .env; rever o `.env` do `teste.ievo.com.br` exposto até hoje (considerar rotacionar banco, MkAuth e comunidade SNMP); a URL `teste.ievo.com.br/isp_auxiliar/public` serve o branch antigo `release/pre-maps-v0` e não tem a busca/telas novas.
6. **Decisões suas**: (a) Cliente e CTO visíveis por padrão na `/rede-fibra`? (hoje ocultas); (b) liberar a busca de rede para técnico; (c) aceitar sugestões "média" (+9 no último octeto, 7 casos reais) ou exigir só "alta"; (d) padrão das flags em produção (hoje desligado).
7. Confirmar visualmente no navegador real: ficha da CTO, `/rede-vinculos` e o fluxo do CSV (capturas em `/root/isp-auxiliar-backups/capturas-cadastro-rede/`, sem dados de clientes).

## Como reverter esta entrega
`git -C /var/www/html/_isp_auxiliar_unificacao reset --hard 56ec7a4` (volta o main ao estado anterior; confirmar com o usuário antes) + rollback da 045 (acima). O hardening do Apache tem rollback próprio em `docs/handoff-seguranca-apache.md`.
