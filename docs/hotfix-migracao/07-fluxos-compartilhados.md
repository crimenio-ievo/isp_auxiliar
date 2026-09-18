# Segunda iteração — fluxos compartilhados

## Núcleo comum

Os três processos reutilizam:

- `client_contracts` como documento contratual;
- `contract_acceptances` para token, prazo, estado e evidência;
- `AcceptanceWorkflowService` para preparar o mesmo formato de aceite;
- a rota pública `/aceite/{token}`;
- o mesmo canvas de assinatura em `public/assets/js/app.js`;
- `EvotrixService` e `EmailService`;
- `notification_logs`;
- `FinancialTaskRepository` e `MkAuthTicketService`, quando aplicáveis;
- `audit_logs`;
- `OperationalProcessService` para checklist, retomada e conclusão.

WhatsApp e e-mail recebem o mesmo link e o mesmo token. Reenvio reutiliza o
aceite pendente; não cria outro processo.

## Nova instalação

O fluxo anterior de rascunho, evidência, provisionamento, aceite e conexão foi
preservado. Quando `syncContractArtifacts()` identifica contrato e aceite, ele
associa um processo `installation`.

Específico da instalação:

- cadastro e dados comerciais;
- evidências de instalação;
- checkpoint;
- ativação e validação da conexão.

## Migração

`syncUpgradeContractArtifacts()` cria o documento/aceite existentes e associa
um processo `migration`. O snapshot comercial/técnico já gravado no contrato é
reutilizado como metadado do processo.

Específico da migração:

- plano e tecnologia anterior/nova;
- troca física;
- confirmação de equipamento;
- troca de plano;
- validação da reconexão;
- revisão financeira posterior.

Cancelar o contrato de migração também cancela o processo operacional, sem
apagar contrato, aceite ou evidências. Uma correção gera outro contrato e,
portanto, outro processo versionado; a versão antiga permanece histórica.

## Solicitação avulsa

`requestDigitalContractSignature()` associa um processo
`standalone_signature`. O fluxo não cria instalação nem checkpoint.

Específico:

- localizar/identificar o contrato atual;
- revisar o documento;
- enviar ou abrir no aparelho;
- acompanhar e validar a assinatura;
- concluir a solicitação.

Quando existe aceite pendente, o fluxo o reutiliza. Se o operador muda de
remoto para assinatura no aparelho, o aceite pendente anterior é cancelado e o
novo passa a ser o único ativo daquela versão.

## Rotas compartilhadas

- `GET /processos`
- `GET /processos/detalhe?id={id}`
- `GET /processos/etapa?id={id}&step={chave}`
- `POST /processos/etapa`
- `POST /processos/concluir`
- `POST /processos/cancelar`

As rotas públicas de aceite não foram duplicadas.

## Duplicações removidas

As três funções que montavam token, expiração e campos básicos de aceite agora
chamam `AcceptanceWorkflowService`. Os fluxos da instalação, migração e
assinatura avulsa já compartilham `dispatchAcceptanceChannels()` no mesmo
controller, além dos dois adapters de notificação.
