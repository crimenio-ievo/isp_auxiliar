# Segunda iteração — chamados e financeiro

## Estrutura reutilizada

Não foi criada uma tabela financeira paralela. Permanecem:

- `financial_tasks`;
- `MkAuthTicketService`;
- `ContractController`;
- `sis_suporte` somente leitura;
- auditoria e notas da tarefa.

O processo guarda apenas `financial_task_id` e `external_ticket_id`.

## Abertura

O service atual prepara `POST /api/chamado/inserir`. A documentação local
confirma login, nome, e-mail, assunto e prioridade.

Para migração, o conteúdo operacional esperado é:

- categoria: Financeiro – Boletos/Carnês;
- assunto: Revisar boletos após migração;
- cliente/login;
- processo, contrato e aceite;
- plano/valor anterior e novo;
- data e responsável;
- observações.

## Idempotência

A proteção atual combina:

- uma tarefa por contrato;
- `send_request_id` contra duplo clique;
- histórico da tentativa;
- `mkauth_ticket_id` quando há retorno real;
- bloqueio de nova tentativa real sem `force_resend`;
- chave única de processo por contrato/tipo.

Dry-run sem ID externo não conclui `open_financial_ticket`. A etapa só é
concluída automaticamente quando existe um ID externo real.

## Ambiente atual

Embora a configuração de chamado esteja habilitada, `MKAUTH_WRITE_ENABLED=false`
bloqueia a chamada real pelo `MkAuthWriteGuard`. O teste automatizado confirma
que o bloqueio ocorre antes do adapter HTTP.

## Acompanhamento

`checkFinancialTicketStatus()` consulta `sis_suporte` em modo somente leitura e
classifica:

- aberto;
- fechado;
- não encontrado;
- ambíguo.

Ao identificar fechamento, a tarefa local é concluída e o processo sincroniza
`follow_financial_ticket`.

## Fechamento manual

O fluxo financeiro existente permite conclusão manual por usuário autorizado.
Ao reabrir o processo, a sincronização lê a tarefa concluída e conclui a etapa
com evidência automática da tarefa.

Se a confirmação for feita diretamente na etapa, uma observação é obrigatória
e a origem fica `manual`.

## Fora do escopo

- edição direta de títulos, boletos ou carnês;
- recálculo financeiro próprio;
- job de sincronização contínua;
- fechamento automático via endpoint não homologado.
