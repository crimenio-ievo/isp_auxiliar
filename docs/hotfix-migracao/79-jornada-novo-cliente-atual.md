# Jornada atual de novo cliente

## Escopo deste levantamento

Este documento registra o fluxo existente na candidata Beta de agosto de 2026.
O hotfix de identidade do plano não altera rotas, ordem de telas nem regras de
conclusão. A numeração abaixo descreve a experiência atual, não uma nova
implementação visual.

## Etapas e telas atuais

1. **Cadastro inicial** — `GET/POST /clientes/novo`, em
   `backend/Views/clients/create.php` e `ClientController::create/store`.
   Reúne identificação, contato, credenciais PPPoE, plano, condição comercial,
   endereço, GPS e fotos. O POST valida o cadastro completo e grava sessão,
   rascunho em arquivo e mídia temporária.
2. **Confirmação técnica e aceite local/remoto** —
   `GET/POST /clientes/novo/aceite`, em
   `backend/Views/clients/acceptance.php` e
   `ClientController::acceptance/finalize`. A mesma ação final salva evidências,
   provisiona o cliente no MkAuth, relê e confirma o plano, cria contrato e
   aceite, prepara o processo operacional, tenta os canais escolhidos e cria o
   checkpoint de instalação.
3. **Aceite público do cliente** — `GET/POST /aceite/{token}` e termo público,
   em `AcceptanceController` e `backend/Views/contracts/acceptance.php` /
   `termo.php`. Esta parte já é compartilhada com migração e assinatura avulsa.
4. **Conexão e conclusão** — `GET /clientes/conexao` e
   `POST /clientes/conexao/finalizar`, em
   `backend/Views/clients/connection.php` e
   `ClientController::connection/completeConnection`. A tela acompanha o
   aceite, permite correção controlada de contato antes da assinatura, consulta
   o Radius e só conclui quando aceite e conexão estão confirmados.

Em paralelo, `OperationalProcessService` projeta a instalação em 11 passos
internos: cadastro, dados comerciais, contrato, envio e confirmação do aceite,
execução técnica, equipamento, ativação, chamado financeiro, acompanhamento e
conclusão. Os três primeiros são concluídos automaticamente pelo fluxo legado.

## Serviços já compartilhados com migração

- `AcceptanceWorkflowService`: token, expiração, versão, hash e estado inicial
  do aceite;
- `AcceptanceEvidenceService`: assinatura/evidência controlada fora da pasta
  pública;
- `OperationalProcessService`: processo, documento, checklist, retomada,
  cancelamento e reconciliação;
- `ContractRepository`, `ContractAcceptanceRepository` e
  `FinancialTaskRepository`;
- `EvotrixService`, `EmailService`, `NotificationTemplateService` e logs de
  notificação;
- rotas e views públicas de aceite/termo;
- `ClientPlanConfirmationService`, usado para identidade oficial e releitura do
  plano também na finalização da migração.

O `ClientProvisioner` continua específico do cadastro de cliente. O
`MkAuthPlanChangeService`, a evidência técnica da migração e
`MigrationJourneyService` continuam específicos da migração.

## Partes ainda legadas

- `clients/create.php` concentra cadastro, condição comercial e coleta de
  evidências numa única tela extensa;
- `clients/acceptance.php` mistura conferência do técnico, assinatura local,
  escolha de canais e disparo da operação de provisionamento;
- `ClientController::finalize` reúne gravação de evidência, MkAuth, contrato,
  aceite, processo, checkpoint e notificações numa mesma transação de jornada;
- `clients/connection.php` combina instalação, acompanhamento do aceite,
  correção de contato, consulta Radius e conclusão;
- a projeção de 11 passos de instalação existe no backend, mas ainda não é
  apresentada pelo componente visual de quatro etapas usado na migração.

## Conversão futura para quatro etapas

1. **Cadastro** — agrupar identificação, endereço, PPPoE, plano e condição
   comercial; salvar o rascunho e apresentar uma revisão antes de provisionar.
2. **Aceite** — agrupar preparação do contrato, assinatura local/remota, envio,
   reenvio e confirmação pública, reutilizando integralmente o núcleo já
   compartilhado.
3. **Instalação** — mover fotos, execução técnica, equipamento e acompanhamento
   da conexão para uma etapa própria, com retomada pelo processo operacional.
4. **Finalização** — concentrar barreiras finais e idempotentes: cliente criado,
   plano relido e confirmado, aceite válido, evidências mínimas, Radius e tarefa
   financeira conforme a regra operacional.

Antes dessa conversão será necessário criar uma projeção visual de instalação
equivalente à `MigrationJourneyService`, mapear os 11 passos internos nos quatro
grupos, separar `ClientController::finalize` em comandos idempotentes e definir
como rascunhos/checkpoints antigos retomam na etapa correta. Isso deve ser feito
em tarefa própria, com homologação visual e de compatibilidade; não faz parte
deste hotfix.
