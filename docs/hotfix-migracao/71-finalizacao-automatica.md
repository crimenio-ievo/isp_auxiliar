# Finalização automática

A etapa 4 mostra uma única sequência operacional:

1. aceite confirmado;
2. execução técnica concluída;
3. aplicar novo plano;
4. confirmar plano por releitura;
5. verificar PPPoE;
6. abrir chamado financeiro.

`MigrationFinalizationService` continua idempotente por etapa e `request_id`. A aplicação futura do plano reutiliza `ClientPlanConfirmationService` e só avança quando UUID, código ou nome oficial permitido confere na leitura do MkAuth. Divergência bloqueia as ações seguintes.

Conexão e chamado são executados depois da confirmação do plano. Chamado real exige ID e não é repetido. A conclusão técnica mantém o processo geral aguardando revisão financeira; o fechamento reconciliado conclui o processo.

Na Beta atual, escrita MkAuth e chamado real estão bloqueados. O botão registra apenas dry-run e nunca o apresenta como execução real. O estado de processamento é `Finalizando atendimento...`.

