# Hotfix bloqueador — modal e pendências do cliente

Data: 2026-08-04. Branch local `fix/modal-pendencia-cliente`, baseada em
`9a5669b69d305f3730ace88202874d2a5583554d`. O hotfix permanece restrito à
homologação: nenhuma integração externa foi habilitada.

## Causa raiz reproduzida

A view criava quatro `.client-detail-panel`, todos com o atributo `hidden`. A
regra de autoria `.client-detail-panel { display: grid; }` prevalecia sobre o
estilo padrão do navegador para `[hidden]`, pois não havia uma regra explícita
que mantivesse esses elementos fora da renderização.

Os quatro painéis eram, portanto, empilhados na carga. Endereço podia ficar por
baixo e Financeiro, por ser o último, aparecia por cima com o texto estático
“Carregando detalhes financeiros...”. O fetch financeiro não havia sido
disparado: ele só era iniciado pelo clique. Por isso o texto permanecia para
sempre. Como o JavaScript ainda mantinha `activePanel = null`, o fechamento
retornava antes de remover o painel, overlay e bloqueio visual.

## Modal central único

Os conteúdos Cliente, Conexão, Endereço e Financeiro agora ficam em quatro
`template` inertes. Há somente um elemento com `role="dialog"`, um overlay e um
gerenciador de estado. O diálogo inicia com `hidden`, `aria-hidden="true"` e
`inert`, além da proteção CSS explícita `[hidden] { display: none !important; }`.

Somente o clique no cartão clona o conteúdo correspondente. Antes da troca, o
gerenciador fecha o estado anterior, aborta o fetch, limpa conteúdo e título,
remove o listener de teclado e libera scroll. X, Esc, overlay, botão Voltar no
celular e a ação de erro usam o mesmo fechamento. O foco retorna ao cartão de
origem.

## Carregamento financeiro e conexão

A página principal não chama `clientFinancialSummary()`. O cartão e o início do
modal usam o resumo já disponível; a consulta detalhada ocorre somente depois do
clique. O cliente usa `AbortController` e timeout configurável por
`CLIENT_DETAIL_TIMEOUT_SECONDS`, limitado entre 8 e 12 segundos e com padrão 10.

São tratados status HTTP, content-type diferente de JSON, resposta vazia, JSON
inválido, redirect para login e sessão expirada. Timeout e erros encerram o
loading e oferecem Tentar novamente e Fechar. O endpoint responde com `ok`,
`data`, `message` e `retryable`; falhas técnicas registram apenas área, hash do
login e classe da exceção.

A conexão MySQL somente leitura do MkAuth também recebeu timeout de conexão e,
quando suportado pelo driver, de leitura. `MKAUTH_WRITE_ENABLED` permanece falso.

## Pendências canceladas

O perfil recebe uma única projeção `activeProcess`, calculada a partir da lista
reconciliada pelo `OperationalProcessService` e validada contra status terminal,
lifecycle cancelado/substituído e aceite revogado/cancelado. A tarefa financeira
só é operacionalmente ativa quando pertence a esse processo e não possui status
terminal.

Processos e tarefas cancelados permanecem na linha do tempo e no histórico, mas
não geram painel atual, alerta financeiro nem abertura de modal. O botão
Continuar processo conserva a `resume_url` da primeira etapa pendente; ele não é
um atalho para o modal Financeiro do cadastro.
