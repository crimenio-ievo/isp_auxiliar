# Segunda iteração — arquitetura de processos

## Resultado

A estrutura operacional foi adicionada de forma aditiva e reutilizável para:

- `installation`: nova instalação;
- `migration`: upgrade/migração, com prioridade funcional para migração;
- `standalone_signature`: solicitação avulsa de assinatura.

Ela não substitui `client_contracts`, `contract_acceptances`, `notification_logs`,
`financial_tasks`, `client_registrations`, `installation_checkpoints` nem
`audit_logs`. Essas estruturas continuam sendo as fontes especializadas.

## Componentes

### `operational_processes`

Guarda cliente/login, tipo, estado geral, responsável, criador, progresso,
próxima pendência e vínculos com cadastro, contrato, aceite, equipamento,
chamado externo e tarefa financeira.

Estados usados:

- `draft`;
- `in_progress`;
- `waiting_client`;
- `waiting_technician`;
- `waiting_mkauth`;
- `waiting_financial`;
- `attention`;
- `completed`;
- `cancelled`.

### `operational_process_steps`

Guarda a etapa, ordem, obrigatoriedade, responsável, situação, início,
conclusão, origem manual/automática, observação, motivo da pendência, evidência,
próxima ação, referência externa e última verificação.

Estados usados:

- `not_started`;
- `in_progress`;
- `waiting`;
- `attention`;
- `completed`;
- `not_applicable`.

### `operational_process_documents`

Vincula processo, contrato, tipo e versão do documento. O snapshot e seu hash
são imutáveis depois da criação. `active_acceptance_id` identifica o aceite
ativo da versão. Novo aceite pendente para a mesma versão revoga o pendente
anterior; evidências históricas não são apagadas.

### Vínculos no aceite

`contract_acceptances` recebeu somente campos opcionais:

- `operational_process_id`;
- `process_document_id`.

Links antigos continuam funcionando sem esses campos.

## Responsabilidades

- `OperationalProcessRepository`: persistência e isolamento por provedor.
- `OperationalProcessService`: templates, progresso, retomada, sincronização e
  bloqueio da conclusão.
- `AcceptanceWorkflowService`: token, validade, modo de assinatura, versão e
  metadados comuns do aceite.
- `OperationalProcessController`: interface e autorização das etapas.
- `MkAuthPlanChangeService`: inspeção e proposta de troca de plano em dry-run.

## Compatibilidade

Registros antigos não são migrados em massa. As telas anteriores continuam
mostrando seus resumos legados. Um processo novo é associado quando um dos três
fluxos grava ou reutiliza contrato e aceite. Reabrir o mesmo contrato/tipo
retoma o processo existente pela chave única, sem criar duplicidade.

## Migration

Arquivo: `database/migrations/016_operational_processes.sql`.

O número 016 foi escolhido porque o banco local de homologação já continha
metadados 009–015 de outra linha de trabalho que não existe nesta branch.
Nenhum arquivo ou tabela de IA foi trazido ou modificado.

Rollback manual está no fim da migration. A ordem é:

1. remover as duas FKs do aceite;
2. remover índices e colunas opcionais;
3. remover documentos;
4. remover etapas;
5. remover processos.

O rollback foi exercitado somente no banco local de homologação e a migration
foi reaplicada em seguida.
