# Handoff — mapa: traçado do cabo, distância real, seguir rua, importar KML (2026-11-19)

Branch `feature/mapa-tracado` (worktree `/var/www/html/_isp_auxiliar_mapa_tracado`), criada a partir do `main` 95ccbe0.
Servidor .89 (TESTE). **Sem push, sem merge.** Nesta rodada não houve contato com a OLT, o MkAuth, o cron nem o .86.
Não houve migration, nem escrita no banco real: todos os testes usam schema descartável (`isp_auxiliar_*_sim`).

Legenda: **FATO** = verificado por teste ou leitura nesta rodada; **HIPÓTESE** = plausível, não provado;
**NÃO VERIFICADO** = não testado.

## Etapa 1 — traçado do cabo (commit eebc68b, junto com a 2)
- **FATO:** `map_links.path_json` JSON NULL já existia desde a migration 019, já era lido (`MapRepository`) e nunca
  gravado. `attrs_json` **não** serve: o validador só aceita escalares e 4 KB. **Não foi preciso migration** (portanto
  sem dump nem rollback novos).
- Formato: `{"pontos":[[lat,lng],…], "reservas":[{"local","m"}], "folga_pct":n}`. Só pontos **intermediários**; as pontas
  vêm dos nós (mover a CTO leva a ponta junto). NULL = cabo antigo, linha reta (continua igual, até no DOM: `<line>`).
- Servidor: `MapPath::normalize` (até 2000 pontos, 50 reservas, lat/lng válidos, reserva 0–10000 m, folga 0–100 %);
  campo `path` em `POST /api/mapa/links[/editar]`; vazio apaga; sem o campo, preserva. Auditoria guarda só a contagem de
  pontos. Inverter sentido inverte o traçado.
- Tela: *Editar traçado* (painel/menu de contexto): clique acrescenta ao fim, arrastar move, "+" do segmento cria ponto,
  duplo clique/botão direito/Delete/botão removem, desfazer, limpar, salvar, Esc cancela. Cabo novo: *Desenhar traçado
  no mapa* no modal *Novo trecho*.

## Etapa 2 — distância
- Total = traçado (soma haversine, R = 6371 km) + folga % **do traçado** + reservas. Traçado, folga, reservas e total
  aparecem em linhas separadas. `length_m` preenchido = **manual**: prevalece, aparece "(manual)" e o calculado fica ao lado.
- **Decisão minha** (diga se prefere outra): a folga incide **só sobre o traçado**, não sobre as reservas (já são metros
  explícitos). Padrão da rede = `provider_settings` `map.cable.slack_pct`, **0 % se não configurado**; por cabo no campo *Folga*.
  Não há tela para o padrão da rede (só SQL).
- O total calculado **não é gravado** (só `length_m` manual): recalcula na leitura. Quem precisar do total no banco
  (ex.: orçamento óptico) terá de calcular com a mesma fórmula (`MapPath::lengths`, igual ao JS).
- **FATO:** conferido em navegador contra a soma haversine feita fora da tela (244 m, 300 m, 307 m).

## Etapa 3 — seguir rua (commit b376360)
- **FATO (wiki do OSRM, lida hoje):** servidor público `router.project-osrm.org`: máx. 1 req/s, só uso "razoável e não
  comercial", sem garantia. Provedor de internet = uso comercial → **não é o padrão**. A URL é `provider_settings`
  `map.routing.osrm_url` (vazia = desligado) + `map.routing.profile`. O servidor do ISP_Auxiliar faz a consulta
  (`POST /api/mapa/rota`, gestor + CSRF, trecho máx. 20 km, timeout 6 s, só http/https, no máx. 400 pontos). Falha ou sem
  URL = 503 e a tela segue no modo manual (**FATO**, testado em jsdom, PHP e navegador).
- **NÃO VERIFICADO:** chamada a um OSRM real (nenhum instalado; só transporte simulado). Nomes de perfil do demo
  (`driving` é o do exemplo oficial). RAM/disco/Docker do .89 para o recorte de MG. Guia em `docs/osrm-seguir-rua.md`.
  **Nada foi instalado.**
- **HIPÓTESE:** o perfil de carro respeita mão única, o que não vale para cabo; os pontos são editáveis por isso.

## Etapa 4 — importar KML/KMZ (commit f121445)
- Em *Adicionar → Importar cabos (KML/KMZ)*. Reaproveita as proteções do `KmlCtoReader` (agora com `document()` público;
  `NetKmlImportSmoke` segue 25/25). Prévia lista, por linha, origem → destino com a distância de cada ponta; raio padrão
  25 m, 1–500 m. Status: pronto, sem elemento na ponta, mesmo elemento, ambíguo (< 1 m de diferença), já existe, recusado
  (mesmas regras de `POST /links`). Elementos candidatos: CTO, CEO, POP, torre. Grava só as marcadas, tudo-ou-nada.
- **NÃO VERIFICADO:** KMZ real do Google My Maps/Earth (só KML/KMZ montados em teste); KML com estilos/pastas
  aninhadas grandes; MultiGeometry só em teste sintético.
- Limite: a prévia não desenha as linhas no mapa; o cabo importado ligando POP/torre aparece como "a detalhar"
  (ponta no site, regra antiga).

## Etapa 5 — nomes de rua sobre o satélite
- **FATO:** já existe a base **"Satélite + ruas"** (Esri World_Transportation + Places). Baixei o tile z17 de Coimbra/MG e
  em navegador real aparecem "Rua Capitão Rafael", "Rua Luís Franco" etc. sobre o satélite (captura 01). Nada foi
  codificado. **Limite herdado:** ruas só até z19 e localidades até z17; "Satélite (Esri)" continua sem rótulos de propósito.
  Se quiser rótulos como camada à parte sobre qualquer base, é uma caixa de seleção pequena: diga.

## Etapa 6 — navegador real
- `node tests/browser/mapa_tracado.js` (Chromium headless; Playwright em `/tmp/net7-browser`). Sobe schema descartável
  `isp_auxiliar_browser_sim` + `php -S :8099` + **uma sessão de harness** (gestor fictício, sem usuário no banco, em
  diretório temporário); no fim remove schema e sessão. Precisa de um `.env` mínimo (gitignored, sem segredos) na
  worktree, porque `Env` só lê do arquivo as chaves que ele declara; **removido ao fim**.
- 29 verificações, 0 falhas: desenhar, editar (arrastar, "+", duplo clique), cancelar, seguir rua sem serviço, reservas,
  folga, manual, arrastar CTO com a distância acompanhando, prévia e importação de KML, sem erro de console.
- Achou 2 bugs que o jsdom não pegava, ambos corrigidos: botões do painel de traçado sobrepostos (CSS) e duplo clique
  que não chega ao `dblclick` porque a tela é redesenhada entre os cliques (agora detecção própria de duplo toque).
- Capturas (fictícias, sem cliente): `/root/isp-auxiliar-backups/capturas-mapa-tracado/` (9 imagens, 1366×820).
- **NÃO VERIFICADO:** toque/celular (só mouse); zoom real do navegador; Firefox/Safari; mapa com centenas de cabos
  (desempenho); `/mapa` dentro do iframe (fora do escopo, próxima rodada).

## Testes (todos verdes ao fim)
`MapTracadoSmoke` (≈60), `MapCableImportSmoke` (21), `MapMapeamentoFlowSmoke`, `MapRouteSmoke`, `MapTestsGuardSmoke`,
`NetKmlImportSmoke`, `NetCtoSmoke`, `FeatureFlagsSmoke`, `PreMapsRoutingRegression`, `NetBindingSmoke`; jsdom do mapa
(`tests/map/js/*` + `b c dd e f diagram`, incl. novos `tracado`, `rota`, `importar`) e `tests/net/run_all.sh`.
**Não rodei** `tests/map/suite*.php` (gravam nós "ZZ TESTE" no banco real); a cobertura equivalente de cabos está nos
smokes em schema descartável. Mudei `tests/map/e.js` ("(informado)" → "(manual)") e o passo 5/6 de
`MapMapeamentoFlowSmoke` (traçado e reserva agora existem).

## Desvios do pedido
- Etapas 1 e 2 ficaram **num único commit** (o código de traçado e de distância se entrelaçou no mesmo arquivo); a 5 não
  gerou commit (nada a mudar); a 6 e este handoff vão em commit próprio.

## PARA O USUÁRIO
1. Conferir no navegador (cadastro real): *Editar traçado*, reservas/folga, *Adicionar → Importar cabos* com um KML seu.
2. Definir a folga padrão da rede se quiser (ex.: 5 %): `INSERT … provider_settings` chave `map.cable.slack_pct`.
3. Decidir se quer OSRM próprio (`docs/osrm-seguir-rua.md`; confirmar RAM/disco antes). Sem ele, o traçado é manual.
4. Decisões minhas a confirmar: folga só sobre o traçado; padrão 0 %; total calculado não gravado no banco.
5. **Deploy .86:** nenhuma migration nova; basta o código. Os dados de `map_links.path_json` vão junto com a cópia das
   tabelas do mapa (`scripts/backup_map.sh`).
6. Merge ff-only no `main`: **aguardando sua ordem** (resumo abaixo na conversa).
