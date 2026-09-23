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

## Fase 2 — Configurações agrupadas por domínio de comunicação

Status: **ainda não iniciada**.

## Fase 3 — Proposta de agrupamento Contratos/Processos/Aceites

Status: **ainda não iniciada**.

## Fase 4 — Proposta de categorização de Logs

Status: **ainda não iniciada**.

## Fase 5 — Cruzamento multiempresa x Configurações extensível

Status: **ainda não iniciada**.
