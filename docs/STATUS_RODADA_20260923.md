# Status da rodada autônoma — 2026-09-23

Worktree: `/var/www/html/_isp_auxiliar_redesign_shell`
Branch: `feature/redesign-shell-fase1`
Ponto de partida: commit `899348e` (fix de performance do dashboard, já commitado em rodada anterior)

Sessão sem supervisão, rodando fase por fase. Cada fase implementada é validada e commitada
isoladamente antes de seguir para a próxima. Fases 3/4/5 são só investigação + documento de
proposta (sem código), por instrução explícita.

---

## Fase 1 — Linha do tempo clicável — CONCLUÍDA E COMMITADA

**Investigação primeiro** (feita via agente de exploração antes de qualquer edição):
- Controller: `backend/Controllers/ClientController.php`, método `buildClientTimeline()` (monta os
  eventos) e `translateAuditEvent()` (rotula os eventos de auditoria).
- View: `backend/Views/clients/detail.php`, seção `.recent-events` (linha ~156).
- Tipos de evento reais mapeados, com destino real ou não:
  - `Cadastro local` (registro MkAuth/local) — **sem destino real**, não existe tela para ver um
    registro isolado. Continua sem link.
  - `Instalacao` / Checkpoint — destino real: `/clientes/conexao?token=...` (tela de
    validação/retomada de conexão). Vira link. Observação: é uma tela de ação, não uma tela de
    visualização passiva — ver pergunta pendente abaixo.
  - `Contrato {id}` — destino real: `/contratos/detalhe?id={id}`. Vira link (id já disponível por
    linha, sem query extra).
  - `Aceite {status}` — destino real: `/contratos/detalhe?id={contrato}` (não existe tela avulsa de
    aceite por id para uso interno; a aceitação some embutida na tela do contrato). Vira link,
    usando o id do contrato principal já carregado.
  - `Financeiro` (pendência financeira) — destino real: `/contratos/detalhe?id={contrato}` (mesma
    razão do item acima). Vira link.
  - `Auditoria` — vira link para `/contratos/detalhe?id={contrato}` quando `entity_type` da linha de
    auditoria é `client_contract`, `contract_acceptance` ou `financial_task` (todas essas linhas já
    pertencem ao contrato principal da ficha, confirmado lendo o filtro de
    `auditLogsForContract()`). Quando `entity_type` é `client_registration` (ou qualquer outro não
    mapeado), **sem destino real** — continua sem link. As ações `operational_process.*` mapeadas em
    `translateAuditEvent()` nunca aparecem de fato nesta timeline (o filtro de busca de logs não
    inclui esse `entity_type` aqui) — não precisou de destino.
- Nenhum destino foi inventado: eventos sem registro real navegável continuam como `<article>`
  plano; só os que já têm rota + id disponível viraram `<a>`.

**Implementação:**
- `ClientController::buildClientTimeline()` passou a receber o id do contrato principal e a incluir
  `'url'` em cada evento (`null` quando não há destino).
- `clients/detail.php`: cada item da timeline agora renderiza como `<a class="recent-events__row
  recent-events__row--linked">` quando há `url`, ou `<article class="recent-events__row">` (mesma
  marcação de antes, só renomeada a classe) quando não há.
- `app.css`: seletor `.recent-events article` renomeado para `.recent-events__row` (compartilhado
  entre `<a>` e `<article>`), com estado de hover/foco (`--linked`) para indicar que é clicável.

**Validação real (não só `php -l`):**
- `php -l` limpo nos 2 arquivos PHP alterados.
- Testado ao vivo no vhost persistente já existente
  (`https://teste.ievo.com.br/_isp_auxiliar_redesign_shell/public/`, credenciais em
  `/root/.prod_test_once`, arquivo mantido conforme instrução anterior).
- Login real via curl, depois `/clientes/detalhe?login=...` para 3 clientes reais distintos
  (`samuel`, `crimenio`, `crim015604`) — todos 200.
- Conferido manualmente o HTML de cada um: eventos de contrato/aceite/financeiro/auditoria/
  checkpoint saíram como `<a href="...">` com a URL certa (`/contratos/detalhe?id=N` ou
  `/clientes/conexao?token=...`); eventos de cadastro local e auditoria de `client_registration`
  saíram como `<article>` sem link, exatamente como esperado.
- Nenhum warning/notice/fatal novo no log do Apache atribuível a esta mudança (havia ruído antigo de
  uma rodada anterior sobre permissão de `.env`/sessões, já resolvido antes desta rodada — timestamps
  confirmam que é log velho, não gerado pelos meus testes de agora).
- Rodei as suítes de regressão que tocam `ClientController` (`tests/Feature/*.php`). Duas falhas
  pré-existentes, sem relação com esta fase (verificadas lendo o próprio teste): `ClientModalPendingSmoke`
  (espera o seletor de canal Stable/Beta, removido em rodada anterior) e `FourthIterationSmoke`
  (espera o banner "AMBIENTE BETA", removido em rodada anterior) e `FinalBetaSmoke` (limite de
  densidade visual antigo, também anterior a esta fase). Nenhuma delas referencia
  `buildClientTimeline` nem a seção `.recent-events`. Não foram tocadas nesta fase — corrigir/
  atualizar essas suítes para refletir o redesenho já aprovado é trabalho separado, fora do escopo
  desta fase.

**Pergunta pendente (não bloqueou a fase, só decisão de UX que só você pode tomar):**
- O evento de "Checkpoint" (Instalação) agora linka para `/clientes/conexao?token=...`, que é a tela
  real de validação/retomada da conexão — mas é uma tela de **ação** (permite finalizar conexão,
  reenviar contato), não uma tela de leitura passiva do checkpoint. Deixei o link porque é o único
  destino real que existe para aquele registro, mas quer que eu troque o rótulo/ícone para deixar
  claro que é uma ação e não só "ver detalhes", ou prefere manter como está?

**Commit:** `dab17dc` — `feat(clientes): linha do tempo com links para destinos reais`

---

## Fase 2 — Configurações agrupadas por domínio de comunicação — CONCLUÍDA E COMMITADA

**Investigação primeiro:**
- Tela é um arquivo único (`backend/Views/settings/index.php`, 753 linhas), sem partials por aba — cada
  aba é um bloco `if/elseif` dentro do mesmo arquivo, com formulário próprio.
- Troca de aba é 100% server-side via query string (`?tab=chave`), sem JS. Nenhuma lógica de
  salvar/carregar depende de posição no DOM — só da string da chave (`settings_section` no form e
  `tab` na URL), conferido em `SettingsController::normalizeTab()`/`saveSection()`.
- 7 abas reais hoje: `geral` (Geral/Provedor), `mkauth`, `contratos`, `email`, `evotrix`,
  `mensagens`, `sistema`. Confirmado que MkAuth e Provedor (`geral`) usam a mesma convenção visual
  das outras (form + `section.card`) e não dependem de posição.

**Implementação (só reorganização visual, zero mudança em salvar/carregar):**
- `backend/Views/settings/index.php`: as chaves/abas `email`, `evotrix`, `mensagens` continuam
  exatamente iguais (mesmo `<form>`, mesmo `settings_section`, mesmo conteúdo) — só a barra de
  navegação passou a agrupar as três sob um rótulo "Comunicação" com sub-navegação própria
  (`.settings-tabs__group`), em vez de 3 botões soltos na mesma fileira das outras abas. MkAuth,
  Geral/Provedor e Sistema continuam como botões de nível superior, sem nenhuma alteração.
- `public/assets/css/app.css`: novo bloco `.settings-tabs__group` (cápsula tracejada com rótulo +
  sub-botões), sem tocar em nenhuma regra usada pelas abas que não mudaram de lugar.

**Validação real:**
- `php -l` limpo.
- Testado ao vivo no vhost persistente: login real, `curl` nas 7 combinações de `?tab=...` — todas
  200. Conferido por HTML que o grupo "Comunicação" aparece com `is-active` só quando a aba atual é
  email/evotrix/mensagens, e o sub-botão certo recebe `is-active`; os outros 4 níveis-superiores
  continuam soltos como antes.
- Conferido que o `<input type="hidden" name="settings_section" value="...">` de cada aba continua
  idêntico ao valor da chave (nenhuma mudança de lógica de salvar).
- Rodei `tests/Feature/ContractSettingsPersistenceRegression.php` (única suíte que toca
  `SettingsController`/persistência de config local) — 13 verificações, todas passaram.

**Pergunta pendente:** nenhuma — reorganização puramente visual, sem decisão de negócio envolvida.

**Commit:** `8fb5b68` — `feat(configuracoes): agrupar Email/Evotrix/Mensagens em "Comunicação"`

## Fase 3 — Proposta de agrupamento Contratos/Processos/Aceites — CONCLUÍDA (só documento)

Investigação completa da relação real entre `ContractController`, `OperationalProcessController` e
`AcceptanceController` (rotas, controllers, FKs, cross-references, sequenciamento de negócio).
Achado central: o código já trata Processo como container do fluxo e Contrato+Aceite como artefato
gerado dentro dele (aceite confirmado/não revogado é trava real para o resto do processo avançar) —
dois documentos já existentes (`docs/hotfix-migracao/06-arquitetura-de-processos.md` e
`26-area-operacional-unica.md`) já descrevem isso. Na tela, porém, Contratos e Processos são dois
itens de nav soltos, sem nenhum link cruzado; Aceites não tem item próprio (é sub-página de
Contratos + fluxo de cliente sem tela de operador).

Documento: `docs/PROPOSTA_AGRUPAMENTO_CONTRATOS.md` — apresenta duas opções (A: fundir em nav único
organizado por fluxo; B: manter nav como está e só adicionar os links cruzados que faltam entre
contrato ↔ processo), recomendação (B primeiro, A como evolução futura se compensar), e reforça que
Aceites não deve virar item de nível superior (contradiria o próprio modelo já documentado).

**Pergunta pendente:** qual opção seguir (A, B, ou nenhuma agora) — decisão sua, registrada no
próprio documento de proposta.

**Nenhum código foi alterado nesta fase**, só o documento.

**Commit:** `c50235a` — `docs(proposta): mapear e propor agrupamento de Contratos/Processos/Aceites`

## Fase 4 — Proposta de categorização de Logs — CONCLUÍDA (só documento)

Investigação exaustiva encontrou 7 origens reais de "log": `audit_logs` (mais de 70 tipos de
evento), `notification_logs` (envio de WhatsApp/e-mail), `sis_suporte`/`sis_msg` do MkAuth
(externo), dois arquivos (`client-plan-confirmation.log`, `mkauth-write-guard.log`), `error_log`
do Apache/PHP, e `logs/app.log` — que **não existe de fato** (configuração morta no `.env`, sem
nenhum código que escreva nele). Achado importante: já existem 3 telas parciais mostrando pedaços
disso (`/logs`, que é **órfã de navegação e sem checagem de permissão**; aba de notificações/
auditoria na ficha do contrato; linha do tempo da ficha do cliente), cada uma com seu próprio
dicionário de tradução de eventos, incompleto e diferente das outras duas. Nenhuma origem tem
severidade real (só `notification_logs.status` chega perto) e não existe rotina automática de
limpeza (só um script manual, bloqueado em produção).

Documento: `docs/PROPOSTA_LOGS.md` — propõe categorizar por significado (Contrato/Aceite,
Provisionamento, Processos operacionais, Acesso/segurança, Configurações/testes, Chamados
financeiros no MkAuth) em vez de por tabela de origem, e sinaliza decisões que a implementação
futura vai precisar tomar (unificar os 3 dicionários de tradução; decidir o destino da rota `/logs`
órfã e sem permissão; decidir o que fazer com `logs/app.log`, que é promessa não cumprida).

**Pergunta pendente:** nenhuma decisão bloqueante nesta fase — as decisões de implementação ficam
sinalizadas no próprio documento para quando a tela for de fato desenhada.

**Nenhum código foi alterado nesta fase**, só o documento.

**Commit:** `b8d03a2` — `docs(proposta): mapear origens de log e propor categorização`

## Fase 5 — Cruzamento multiempresa x Configurações extensível — CONCLUÍDA (só documento)

Li `docs/multiempresa.md` por completo e investiguei se já existe algum padrão de "registro de
integrações" (interface/registry/plugin) na tela de Configurações — não existe: as 7 abas são
hardcoded em 3 lugares (view + 2 pontos no controller). Achado mais importante: **MkAuth/Geral já
usam `provider_settings` (chave-valor por provedor, como `multiempresa.md` já prevê), mas Email e
Evotrix/WhatsApp gravam num arquivo JSON único e global (`storage/contracts/config.json`, caminho
fixo, sem `provider_id`/`provider_slug`)** — ou seja, metade das integrações já está no eixo
multiempresa e a outra metade não está em eixo nenhum.

Documento: `docs/PROPOSTA_CONFIGURACOES_EXTENSIVEL.md` — conclui que as duas ideias são
complementares (não a mesma, não conflitantes), mas compartilham um pré-requisito técnico comum:
se o registro de integrações for desenhado em cima de `provider_settings` (em vez de arquivos
soltos), ele resolve ao mesmo tempo o hardcode de abas e a lacuna de isolamento multiempresa que
Email/Evotrix já têm hoje. Recomendação: não implementar isoladamente; se abordado, desenhar já
sobre `provider_settings`, migrando Email/Evotrix no mesmo movimento.

**Pergunta pendente:** nenhuma decisão bloqueante — é uma constatação arquitetural para orientar
prioridade futura, registrada no próprio documento.

**Nenhum código foi alterado nesta fase**, só o documento.

**Commit:** `5529b6d` — `docs(proposta): cruzar multiempresa.md com ideia de registro de integrações`

---

## Resumo final da rodada — todas as 5 fases concluídas

Nenhuma fase travou. Nenhuma janela fechou no meio. Ordem de execução seguiu exatamente o pedido,
com validação real e commit isolado antes de cada fase seguinte.

### Implementado e commitado (código)

| Fase | O quê | Commit |
|---|---|---|
| 1 | Linha do tempo da ficha do cliente vira link quando existe destino real | `dab17dc` |
| 2 | Configurações: Email/Evotrix/Mensagens agrupados em "Comunicação" | `8fb5b68` |

### Virou documento de proposta (sem implementação, aguardando aprovação)

| Fase | Documento | Commit |
|---|---|---|
| 3 | `docs/PROPOSTA_AGRUPAMENTO_CONTRATOS.md` | `c50235a` |
| 4 | `docs/PROPOSTA_LOGS.md` | `b8d03a2` |
| 5 | `docs/PROPOSTA_CONFIGURACOES_EXTENSIVEL.md` | `5529b6d` |

(mais 3 commits pequenos só de atualização deste arquivo de status, entre as fases)

### Perguntas que só você pode responder

1. **Fase 1 (UX, não bloqueou):** o evento "Checkpoint" na timeline agora linka para
   `/clientes/conexao?token=...`, que é uma tela de **ação** (permite finalizar conexão), não uma
   tela passiva de detalhe. É o único destino real que existe para aquele registro — mantive o
   link, mas quer que eu ajuste o rótulo/ícone pra deixar claro que é uma ação?
2. **Fase 3:** qual caminho seguir para Contratos/Processos/Aceites — Opção A (fundir num nav só,
   organizado por fluxo), Opção B (manter nav como está, só adicionar os links cruzados que faltam
   entre contrato e processo — minha recomendação, por ser a de menor risco), ou nenhuma mudança
   agora? Aceites, nas duas opções, não deve virar item de nível superior.
3. **Fase 4:** nenhuma pergunta bloqueante; fica sinalizado no documento que a tela nova vai
   precisar decidir o destino da rota `/logs` (hoje órfã e sem checagem de permissão) e o que fazer
   com o `LOG_PATH` morto no `.env`.
4. **Fase 5:** nenhuma pergunta bloqueante; é uma constatação arquitetural (Email/Evotrix hoje
   gravam config num arquivo único e global, sem `provider_id` — viraria problema se a instalação
   compartilhada multiempresa avançar antes disso ser corrigido) para orientar prioridade, não uma
   decisão urgente.
5. **Pendências flagradas em rodadas anteriores, ainda sem resposta** (não geradas por esta rodada,
   só continuam em aberto): valor da variável CSS `--border`, remoção de "Logs" do rail de
   navegação, realocação do botão de logout para a topbar, reaproveitamento de cor entre as
   variantes `attention`/`queue`.

### O que ainda não foi tocado

- Implementação de qualquer uma das 3 propostas (Fases 3, 4, 5) — aguardando sua decisão.
- Tudo que estava fora do escopo desta rodada: "Central de Atualizações", merge dos worktrees de
  feature protegidos, rotação de credenciais SMTP/Evotrix expostas, destino do diretório
  `isp_auxiliar_stable_legacy_...`, correção de nomenclatura `.env` em produção.
- Nenhuma promoção para produção foi feita ou tentada (nem seria, sem confirmação explícita na hora).
- O vhost de teste persistente (`https://teste.ievo.com.br/_isp_auxiliar_redesign_shell/public/`,
  credenciais em `/root/.prod_test_once`, arquivo mantido) continua no ar para você validar tudo
  visualmente quando quiser, antes de decidir os próximos passos.
