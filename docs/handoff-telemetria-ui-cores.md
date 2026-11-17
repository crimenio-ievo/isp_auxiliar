# Telemetria — cores, barra de sinal e coluna Sinal (tela /rede-fibra)

Branch `integracao/unica`, servidor de TESTE. Referência visual: `docs/design/rede-fibra-mockup.html` (desenho copiado, nunca o código nem os dados simulados).
Esta tarefa substitui a instrução anterior "não mude as cores das faixas".

## CP-0 — levantamento (sem alterar código)

### Cores por faixa: mockup × atual (tema claro)

O mockup usa uma única família por faixa, com 4 papéis: **fill** (barra/segmento/régua), **ink** (texto), **bg** (fundo de pílula) e **borda** (marcadores).
Atenção: no mockup a faixa "normal" é AZUL (`--s-ok`/`--t-ok`); o verde (`--online`) é do ESTADO "Online", não da faixa.

| Faixa | Papel | Mockup | Atual (rede-fibra.css / app.css) | Aplicado |
|---|---|---|---|---|
| Crítico | preenchimento (barra, segmento, régua) | `#E5484D` (`--s-crit`) | `#fbe4e2` (pílula) · régua `#e8a7a1` · gráfico `#f6d8d5` | `--rf-crit-fill` `#E5484D` (barra, empilhado, régua, zona do gráfico a 14%) |
| Crítico | texto | `#B42318` (`--t-crit`) | `#7a221d` | `--rf-crit-ink` `#B42318` |
| Crítico | fundo de pílula | `#FDE8E6` (`--crit-bg`) | `#fbe4e2` | `--rf-crit-bg` `#FDE8E6` |
| Crítico | borda/marcador | `#E5484D` (`border-color`/`border-bottom` de marcadores) | — (sem borda) | `--rf-crit-fill` (bordas de alerta de saúde e de evento múltiplo; antes `#e8a7a1`) |
| Atenção | preenchimento | `#F2A33A` (`--s-warn`) | `#fff4cc` · régua `#f1d27a` · gráfico `#fbefc7` | `--rf-warn-fill` `#F2A33A` (saturado usa a mesma) |
| Atenção | texto | `#9A4D00` (`--t-warn`) | `#5f4300` | `--rf-warn-ink` `#9A4D00` |
| Atenção | fundo de pílula | `#FFF3DC` (`--warn-bg`) | `#fff4cc` | `--rf-warn-bg` `#FFF3DC` (também o aviso `.rf-note`) |
| Atenção | borda/marcador | `#F2A33A` | — | `--rf-warn-fill` |
| Normal | preenchimento | `#3B82F6` (`--s-ok`, azul) | `#e2f3e8` (verde-claro) · régua `#8cc9a0` · gráfico `#e6f4ea` | `--rf-ok-fill` `#3B82F6` |
| Normal | texto | `#1D4ED8` (`--t-ok`) | `#1f6b3a` (verde) | `--rf-ok-ink` `#1D4ED8` |
| Normal | fundo de pílula | `#E8EFFD` (`--info-bg`) na faixa; `#E7F6EC` (`--online-bg`) só no estado Online | `#e2f3e8` | `--rf-ok-bg` `#E8EFFD` na faixa; Online do estado = `--rf-online-bg` `#E7F6EC` |
| Normal | borda/marcador | `#3B82F6` | — | `--rf-ok-fill` |
| Offline | preenchimento | (mockup não desenha segmento de offline) | `#fbe4e2` (idêntico ao crítico) | `--rf-off-fill` `#B42318` (decisão: o mockup não desenha; usei o vermelho escuro do próprio mockup para distinguir de crítico no empilhado e nas quedas do gráfico) |
| Offline | texto | `#B42318` (pílula `p-crit`) | `#7a221d` | `--rf-off-ink` `#B42318` |
| Offline | fundo de pílula | `#FDE8E6` (`p-crit`) | `#fbe4e2` | `--rf-off-bg` `#FDE8E6` |
| Sem leitura | preenchimento | barra vazia (trilho `#ECEFED`, `--line-soft`) | trilho `#edf0f2` | barra vazia sobre `--rf-track` `#ECEFED` |
| Sem leitura | texto | `#5B6864` (`--muted`) | `#617587` | `--rf-none-ink` `#5B6864` |
| Sem leitura | fundo de pílula | `#ECEFED` (`p-muted`) | `#f7f9fc` | `--rf-none-bg` `#ECEFED` |
| Estado Online | texto / fundo | `#15803D` / `#E7F6EC` | `#1f6b3a` / `#e2f3e8` | `--rf-online-ink` `#157E3C` / `--rf-online-bg` `#E7F6EC` (luminosidade ajustada, 4,60:1) |
| Trilho da barra | — | `#ECEFED`, divisórias 2 px da cor da superfície | `#edf0f2` com divisórias brancas | trilho `--rf-track`, divisórias 2 px `--rf-sep` (branco) em 33,333% e 66,667% |

Fatos sobre o "atual": a barra de sinal usa a MESMA classe da pílula (`rf-pill--ok` etc.), por isso o preenchimento é o tom pastel da pílula (contraste ~1,0:1 com o trilho — causa do defeito 1). Offline e crítico têm o mesmo tom. Régua, gráfico e segmentos empilhados têm tons próprios, soltos por componente (defeito 8).

### Pesos de fonte: mockup × atual

| Elemento | Mockup | Atual | Aplicado |
|---|---|---|---|
| Linha de site (nível 0) | 800 (16 px) | 800 | mantido 800 |
| Linha de equipamento (nível 1) | 800 (15 px) | 800 | mantido 800 |
| Linha de PON (nível 2) | 700 (14 px) | 700 | mantido 700 |
| Nome da ONU (folha) | 600 (13,5 px) | 400 (herdado) | 600 (`.rf-row--onu td:first-child`) |
| Valor em dBm | 800 (`.g-val`; colorido em atenção/crítico) | 400, cor do texto | 800; cor `ink` em atenção/crítico, 600 muted em sem leitura |
| Palavra da faixa (pílula) | 700 (12 px) | 700 (0,78 rem) | mantido 700 |
| Cabeçalhos de coluna | 700 (13 px, cor muted) | 700 | mantido 700 |
| Contagens por faixa (nº) | 800 (`.stk-n b`), rótulo 700 | 700 (pílula "ok 4") | número 800 (`.rf-count-n`), rótulo 700; rótulos agora normal/atenção/crítico/offline |

### Contraste (WCAG, texto sobre fundo de pílula; mínimo 4,5:1)

Cálculo: `tmp`/scratchpad `contrast.py` (luminância relativa WCAG 2.x).

| Par (texto / fundo) | Razão |
|---|---|
| Normal `#1D4ED8` / `#E8EFFD` | 5,81 |
| Atenção `#9A4D00` / `#FFF3DC` | 5,56 |
| Crítico/Offline `#B42318` / `#FDE8E6` | 5,59 |
| Sem leitura `#5B6864` / `#ECEFED` | 5,03 |
| Online `#15803D` / `#E7F6EC` | **4,49 — falha por 0,01** |
| Preenchimento sobre o trilho (não é texto; referência 3:1): normal 3,18 · crítico 3,38 · atenção 1,80 | |
| ATUAL: preenchimento pastel sobre o trilho: ok 1,01 · atenção 1,04 · crítico 1,06 | |

Online do mockup falha por 0,01; ajuste só de luminosidade na CP-1: `#157E3C` (4,60).
O amarelo de atenção (`#F2A33A`) sobre o trilho dá 1,80:1; é a cor do mockup e é um preenchimento (não texto) separado por divisórias; mantido, com limite registrado.

### Capturas "antes" (fora do Git)
Em `/var/www/html/isp_auxiliar/tmp/ui-cores/`: `antes-{100,125}-{arvore,grupo,onu}.png` (tela real, fixtures de teste com todas as faixas) e `mockup-{100,125}-{arvore,grupo,onu}.png`. 125% = viewport 1152 px com devicePixelRatio 1,25.


## CP-1 — paleta única (defeito 8)
Fato: variáveis `--rf-*` declaradas só em `.rf` (escopo da tela; `app.css` e o resto do sistema não mudam). Pílulas, barra, empilhado (painel de site/equipamento/PON), régua e zonas do gráfico leem as mesmas variáveis; o CSS não tem mais nenhum tom antigo de faixa (teste confere). Online `#15803D` → `#157E3C` (4,49 → 4,60:1), único ajuste de luminosidade. Todos os pares texto/fundo ≥ 4,5:1 (teste calcula).
Decisões a conferir: (1) normal = AZUL, como no mockup; o verde ficou só no estado "Online"; (2) offline = vermelho escuro `#B42318` no preenchimento; (3) a régua não escreve mais o nome da faixa sobre a cor (nenhum fill forte chega a 4,5:1 com texto — branco/escuro dão 3,9/4,2/3,7/4,5), como no mockup; o nome continua no DOM para leitor de tela (`font-size:0`) e na legenda; (4) as cores das linhas do gráfico (ONU, vizinhos, referência) não são de faixa e ficaram como estavam.

## CP-2 — barra de sinal (defeito 1)
Fato: preenchimento usa a cor forte da faixa (`.rf-fill--*`), não mais a da pílula; 3 estágios iguais com divisórias; normal ocupa 67–100%, crítico 0–33%, atenção 33–67%; largura mínima 4% quando há leitura. Sem leitura = barra vazia + "sem leitura". Barra 108×10 px, como no mockup.

## CP-3 — coluna Sinal (defeito 5)
Fato: "Sinal (dBm)" e "Faixa" viraram "Sinal": barra · valor em dBm · palavra da faixa, numa linha (altura de linha 38 px em todas as ONUs a 100% e 125%, ou seja, sem quebra). Cliente e CTO ocultas por padrão e disponíveis em "Colunas". Padrão: ONU, Estado, Sinal, Distância, Quedas 24 h, MAC.
Migração de `localStorage['rf.cols']`: `rx`/`band` → `sinal` (uma vez), chaves desconhecidas descartadas, preferência regravada no formato novo; JSON inválido → padrão, sem erro. Preferência de Cliente/CTO já salva é respeitada.
Ordenação NÃO implementada (defeito 2); a célula tem `data-sort-value` com o dBm numérico (ausente quando sem leitura) e o cabeçalho `data-col="sinal" data-sort-key="sort-value" data-sort-type="number"`.
API não mudou: listas de chaves de `NetFiberReaderSmoke.php` e `tests/net/rede_fibra.js` intactas.

## Testes
`bash tests/net/run_all.sh`: 0 grupos com falha (Outage 42, Fiber 100, rede_fibra 128 — eram 109, +19 novos —, busca global 7). Feature + Acceptance: 49/49. `CadastroLocalDraft.js` OK. Novos testes jsdom: cor de preenchimento por faixa (lida do CSS), nenhum tom antigo, uma só paleta, contraste ≥ 4,5, barra por faixa/estágio, ONU sem leitura, coluna unificada e ordem barra-valor-palavra, `data-sort-value`, Cliente/CTO ocultas, 6 casos de migração. Não rodei `tests/map` (nenhum arquivo do mapa foi tocado).

## Capturas (fora do Git), em `/var/www/html/isp_auxiliar/tmp/ui-cores/`
- Antes: `antes-{100,125}-{arvore,grupo,onu}.png`
- Depois: `depois-{100,125}-{arvore,grupo,onu}.png`
- Mockup: `mockup-{100,125}-{arvore,grupo,onu}.png`
- Lado a lado (mockup à esquerda, tela real à direita): `comparacao-depois-{100,125}-{arvore,grupo,onu}.png`

## Hipótese e não verificado
- Capturas usam um servidor temporário em 127.0.0.1 com o HTML do `render_page.php` e dados de TESTE (todas as faixas), não o banco real nem login. Servidor encerrado.
- O mockup foi capturado com seus dados, que não têm ONUs em atenção/crítico: o contraste visual dessas faixas foi comparado pelo CSS, não pela captura.
- Amarelo de atenção sobre o trilho dá 1,80:1 (cor do mockup, não é texto, separado por divisórias): mantido.
- ONU offline com leitura antiga mostra a barra na faixa do último valor (Estado mostra "Offline"); não alterei essa regra.
- Tema escuro: o app não tem; só valores claros do mockup foram usados.
- Zoom nativo de navegador não testado (usei viewport 1152 px com DPR 1,25).
- CP-2 e CP-3 foram escritos nos mesmos trechos de JS/CSS e saíram em UM commit (não consegui separar sem retrabalho).
