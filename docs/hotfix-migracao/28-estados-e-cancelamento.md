# Quarta iteração — estados, progresso e cancelamento

## Reconciliação central

`OperationalProcessService::reconcileState()` é o ponto que reconcilia processo,
etapas, contrato, aceite, documento e tarefa financeira. `ensureForContract()`,
sincronização de aceite e leitura de processos ativos passam por essa regra.

Estados derivados:

- `waiting_client`: primeira etapa obrigatória pendente pertence ao cliente;
- `waiting_mkauth`: pendência pertence ao MkAuth;
- `waiting_financial`: pendência pertence ao financeiro;
- `waiting_technician`: pendência pertence ao técnico;
- `attention`: existe inconsistência que requer ação;
- `completed` e `cancelled`: estados terminais preservados.

## Aceite

Aceite `aceito` e não revogado conclui automaticamente `prepare_document`,
`send_acceptance` e `confirm_acceptance`. Aceite pendente mantém confirmação em
espera. Aceite revogado não volta a ser evidência válida de confirmação.

## Cancelamento transacional

Ao cancelar:

1. o processo recebe `cancelled` e motivo;
2. o aceite é revogado; se já aceito, a evidência histórica é preservada, mas
   `revoked_at` impede seu uso como aceite ativo;
3. o documento vinculado fica revogado;
4. o contrato recebe lifecycle `cancelled`;
5. tarefa financeira aberta é cancelada;
6. etapas não concluídas viram `cancelled` e perdem pendência/próxima ação;
7. progresso é recalculado, preservando apenas conclusões verdadeiras;
8. `current_step_key`, `next_pending_key` e `next_pending_label` ficam nulos.

Nenhuma linha histórica é apagada. `listByLogin()` continua exibindo o processo
cancelado no histórico, mas o perfil ignora processos terminais ao escolher o
painel ativo. Uma nova solicitação cria novo contrato, aceite e processo.

## Revisão de condição

Com aceite pendente, o contrato e processo atuais são reutilizados; o token
anterior é revogado, uma nova versão de aceite é criada e as etapas de envio e
confirmação são reiniciadas. Com aceite confirmado, a condição anterior é
substituída por novo contrato/revisão, mantendo auditoria e snapshots antes/depois.
