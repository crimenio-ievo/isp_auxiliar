# Telemetria — ajustes da tela /rede-fibra, rodada 2 (parte B)

Branch `integracao/unica`, servidor de TESTE. Referência: `docs/design/rede-fibra-mockup.html` (desenho, não código). Só variáveis `--rf-*`. Parte A (vhost): `docs/handoff-unificacao-vhost.md`.
Observação: como o vhost testes.ievo.com.br serve esta worktree, cada commit de arquivos já fica no ar ali (o `main` só foi avançado até fe18d7e, antes da Parte B; `main` NÃO acompanha estes commits).

## CP-1 — ONU offline (commit 6592c80, junto com o CP-2)
Fato: offline e "sem resposta" (`desatualizado`) mostram barra no trilho cinza (sem preenchimento de faixa), valor apagado "último: -21,00 dBm" (peso 600, cor `--rf-none-ink`), sem pílula de faixa e sem `data-sort-value`. Sem leitura segue "sem leitura".
Decisão: o valor em dBm da coluna passou a usar vírgula ("-20,00 dBm", como a ficha e o mockup; antes "-20.00") em todas as linhas, para não misturar formatos na mesma coluna. Os testes foram atualizados.

## CP-2 — ordenação por coluna (commit 6592c80)
Fato: cabeçalhos de Estado, Sinal, Tx, Temp., Distância, Quedas 24 h e MAC são botões; clique alterna crescente → decrescente → ordem normal. `aria-sort` no `<th>` (`ascending`/`descending`/`none`), indicador ↕ ▲ ▼ por CSS (o texto do cabeçalho continua limpo), foco volta ao botão após reordenar. Ordena só as ONUs dentro de cada PON; site, equipamento e PON mantêm a ordem (teste). Sinal usa o dBm numérico; offline, "sem resposta" e sem leitura vão sempre para o fim nos dois sentidos (Distância, Tx e Temp. com valor ausente também). Estado: offline, crítico, atenção (inclui saturado), normal, depois sem leitura. Empate: número da ONU. Só uma coluna ativa.
Persistência: chave própria `rf.sort` = `{"k":"sinal","d":"asc"}` (separada de `rf.cols`, para não mudar o formato da preferência de colunas já migrada). Valor corrompido/chave desconhecida/sentido inválido/coluna oculta → ordem normal, sem erro. Cliente e CTO (ocultas) e a coluna "ONU" não são ordenáveis.

## CP-3 — régua da ficha (commit 9d0312e)
Fato: números -30, -27, -25 e -12 em `left = pct(v)%` (mesma função das marcas: 0; 16,67; 27,78; 100 %), extremos alinhados às bordas. Os rótulos de "Esta ONU", "Vizinhos" e "Referência" são medidos (`getBoundingClientRect`; sem layout, estimativa pelo texto) e, se colidirem, vão para outra linha (3 linhas); só se nem assim coubessem seriam juntados num rótulo só (com 1 rótulo acima e no máximo 2 abaixo, esse caso não ocorre, é uma guarda). Valores praticamente iguais (< 0,3 dB) continuam num rótulo só ("Esta ONU = Vizinhos = Referência"). Rótulos limitados às bordas da régua.
Medido em Chromium real (100% e 125%): com -25,6 / -25,2 / -26,1 sobreposições = 0, todos dentro da régua; ticks a 10, 64, 107 e 378 px de 386 px.
Teste jsdom com -20,0 / -20,4 / -21,0, perto da borda (-12,4/-12,0/-12,9), iguais e distantes.

## CP-4 — gráfico da ficha (commit 5c2f01b)
Fato: margem direita 72 → 104 px (painel, viewBox 420) e 124 (modal, 840); rótulo em `x = W - mr + 6`. Se Vizinhos e Referência ficarem a menos de 14 px, o segundo vai 15 px abaixo (ou acima, se não couber). Medido em Chromium: folga de 45 px (Ref.) e 16 px (Vizinhos) no painel; 88 e 50 px no modal, nenhum cortado, a 100% e 125%.
Nota: o teste do cursor assumia a largura do gráfico (310) e foi ajustado para 278.

## CP-5 — rótulos (commit 050f510)
Fato: o seletor de período da ficha agora é "Período"; a seção de quedas continua "Quedas". Teste jsdom.
**Fabricante/modelo do OLT-001 (nada gravado no banco).** O mapa PERMITE editar: em `/mapa`, escolha o nó da OLT (clicando no desenho ou em "Equipamentos do site" → botão "Editar" ao lado do OLT-001; ou o botão "Editar" da ficha do nó). O formulário de equipamento tem os campos "Fabricante" e "Modelo" (`modules/isp_map/isp-network-map.html`, edição ~linha 2429, grava `attrs_json` via `POST /api/mapa/nos/editar`). Digite `Hioso` e `HA7004S` e salve; as chaves entram em `attrs_json.fabricante`/`.modelo` mesclando com os atributos existentes (o formulário reenvia os `_attrs` atuais). Só gestor/admin e com CSRF do mapa. A tela /rede-fibra lê exatamente essas duas chaves (`NetFiberReader`, `array_intersect_key(attrs_json, [fabricante, modelo])`) e passará a mostrar "Hioso HA7004S". Não executei a edição.
Não verificado: efeitos colaterais do salvamento do formulário completo do nó (status, situação, site, observações são reenviados com os valores atuais do formulário).

## CP-6 — limpeza (commits 3b4a008 e 3f753b4)
`tests/map/node_modules` (symlink, por isso sem barra final) agora no `.gitignore`. `.env.bak-*` NÃO estava coberto (só `.env.backup-*`): adicionado em commit próprio. Confirmado com `git check-ignore` para `.env.bak-2026-10-06` e `.env.bak-20261117`.

## Testes
`bash tests/net/run_all.sh`: 0 falhas (Outage 42, Fiber 100, rede_fibra 162, busca global 7). Feature + Acceptance 49/49; `CadastroLocalDraft.js` OK; `bash tests/map/run_all.sh`: 25/25. A API não mudou: listas de chaves de `NetFiberReaderSmoke.php` e `tests/net/rede_fibra.js` intactas.

## Capturas (fora do Git) — `/var/www/html/isp_auxiliar/tmp/ui-cores/`, prefixo `v2-`
`v2-{100,125}-arvore-ordenada-sinal.png`, `v2-{100,125}-ficha.png` (régua + gráfico), `v2-{100,125}-grafico-ampliado.png`, `v2-{100,125}-onu-offline-linha.png`, `v2-{100,125}-onu-offline-ficha.png`, `v2-100-testes-rede-fibra-sem-login.png`.
Sem login em `https://testes.ievo.com.br/rede-fibra` (Chromium, host resolvido para 127.0.0.1, certificado snakeoil aceito): 302 → `/login` (200), título "Entrar no sistema", 0 linhas de ONU.

## Hipóteses e não verificado
- Capturas com servidor temporário em 127.0.0.1 e dados de TESTE (todas as faixas, ficha com valores próximos); não são dados reais nem sessão real.
- Tela autenticada no vhost real não foi vista (sem credenciais); o smoke autenticado da parte A foi em processo.
- Marcas da régua (círculo, losango, triângulo) se sobrepõem quando os valores são muito próximos; só os rótulos foram tratados.
- Os números da escala continuam na parte de baixo da régua, abaixo dos rótulos (layout anterior mantido; só as posições horizontais mudaram).
- Zoom nativo do navegador, Firefox/Safari e telas pequenas não testados; 125% foi simulado (viewport 1152 px, DPR 1,25).
- CP-1 e CP-2 foram feitos nos mesmos trechos e saíram num único commit.
