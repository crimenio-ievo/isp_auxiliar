# Telemetria — cores, barra de sinal e coluna Sinal (tela /rede-fibra)

Branch `integracao/unica`, servidor de TESTE. Referência visual: `docs/design/rede-fibra-mockup.html` (desenho copiado, nunca o código nem os dados simulados).
Esta tarefa substitui a instrução anterior "não mude as cores das faixas".

## CP-0 — levantamento (sem alterar código)

### Cores por faixa: mockup × atual (tema claro)

O mockup usa uma única família por faixa, com 4 papéis: **fill** (barra/segmento/régua), **ink** (texto), **bg** (fundo de pílula) e **borda** (marcadores).
Atenção: no mockup a faixa "normal" é AZUL (`--s-ok`/`--t-ok`); o verde (`--online`) é do ESTADO "Online", não da faixa.

| Faixa | Papel | Mockup | Atual (rede-fibra.css / app.css) | Aplicado |
|---|---|---|---|---|
| Crítico | preenchimento (barra, segmento, régua) | `#E5484D` (`--s-crit`) | `#fbe4e2` (pílula) · régua `#e8a7a1` · gráfico `#f6d8d5` | _(CP-1)_ |
| Crítico | texto | `#B42318` (`--t-crit`) | `#7a221d` | _(CP-1)_ |
| Crítico | fundo de pílula | `#FDE8E6` (`--crit-bg`) | `#fbe4e2` | _(CP-1)_ |
| Crítico | borda/marcador | `#E5484D` (`border-color`/`border-bottom` de marcadores) | — (sem borda) | _(CP-1)_ |
| Atenção | preenchimento | `#F2A33A` (`--s-warn`) | `#fff4cc` · régua `#f1d27a` · gráfico `#fbefc7` | _(CP-1)_ |
| Atenção | texto | `#9A4D00` (`--t-warn`) | `#5f4300` | _(CP-1)_ |
| Atenção | fundo de pílula | `#FFF3DC` (`--warn-bg`) | `#fff4cc` | _(CP-1)_ |
| Atenção | borda/marcador | `#F2A33A` | — | _(CP-1)_ |
| Normal | preenchimento | `#3B82F6` (`--s-ok`, azul) | `#e2f3e8` (verde-claro) · régua `#8cc9a0` · gráfico `#e6f4ea` | _(CP-1)_ |
| Normal | texto | `#1D4ED8` (`--t-ok`) | `#1f6b3a` (verde) | _(CP-1)_ |
| Normal | fundo de pílula | `#E8EFFD` (`--info-bg`) na faixa; `#E7F6EC` (`--online-bg`) só no estado Online | `#e2f3e8` | _(CP-1)_ |
| Normal | borda/marcador | `#3B82F6` | — | _(CP-1)_ |
| Offline | preenchimento | (mockup não desenha segmento de offline) | `#fbe4e2` (idêntico ao crítico) | _(CP-1)_ |
| Offline | texto | `#B42318` (pílula `p-crit`) | `#7a221d` | _(CP-1)_ |
| Offline | fundo de pílula | `#FDE8E6` (`p-crit`) | `#fbe4e2` | _(CP-1)_ |
| Sem leitura | preenchimento | barra vazia (trilho `#ECEFED`, `--line-soft`) | trilho `#edf0f2` | _(CP-1)_ |
| Sem leitura | texto | `#5B6864` (`--muted`) | `#617587` | _(CP-1)_ |
| Sem leitura | fundo de pílula | `#ECEFED` (`p-muted`) | `#f7f9fc` | _(CP-1)_ |
| Estado Online | texto / fundo | `#15803D` / `#E7F6EC` | `#1f6b3a` / `#e2f3e8` | _(CP-1)_ |
| Trilho da barra | — | `#ECEFED`, divisórias 2 px da cor da superfície | `#edf0f2` com divisórias brancas | _(CP-2)_ |

Fatos sobre o "atual": a barra de sinal usa a MESMA classe da pílula (`rf-pill--ok` etc.), por isso o preenchimento é o tom pastel da pílula (contraste ~1,0:1 com o trilho — causa do defeito 1). Offline e crítico têm o mesmo tom. Régua, gráfico e segmentos empilhados têm tons próprios, soltos por componente (defeito 8).

### Pesos de fonte: mockup × atual

| Elemento | Mockup | Atual | Aplicado |
|---|---|---|---|
| Linha de site (nível 0) | 800 (16 px) | 800 | _(CP-1)_ |
| Linha de equipamento (nível 1) | 800 (15 px) | 800 | _(CP-1)_ |
| Linha de PON (nível 2) | 700 (14 px) | 700 | _(CP-1)_ |
| Nome da ONU (folha) | 600 (13,5 px) | 400 (herdado) | _(CP-1)_ |
| Valor em dBm | 800 (`.g-val`; colorido em atenção/crítico) | 400, cor do texto | _(CP-1)_ |
| Palavra da faixa (pílula) | 700 (12 px) | 700 (0,78 rem) | _(CP-1)_ |
| Cabeçalhos de coluna | 700 (13 px, cor muted) | 700 | _(CP-1)_ |
| Contagens por faixa (nº) | 800 (`.stk-n b`), rótulo 700 | 700 (pílula "ok 4") | _(CP-1)_ |

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
