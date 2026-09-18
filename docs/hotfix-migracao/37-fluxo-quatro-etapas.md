# Fluxo operacional em quatro etapas

## 1. Nova condição

Abre diretamente como “Etapa 1 de 4”. Cria/retoma o rascunho, mostra condição
atual, plano alvo, tecnologia calculada, adesão, benefício e fidelidade. Campos
avançados ficam recolhidos. Salvar continua no mesmo processo.

## 2. Aceite

Agrupa `prepare_document`, `send_acceptance` e `confirm_acceptance`. WhatsApp e
e-mail são exibidos como valores somente leitura. A correção ocorre em formulário
separado, validado e auditado; contrato, processo, token e aceite são mantidos.
Cliente presente usa assinatura local; cliente ausente exige motivo. Enviar,
reenviar e atualizar situação permanecem na mesma tela. Quando o aceite é
confirmado, a retomada abre automaticamente a Execução técnica.

## 3. Execução técnica

Agrupa execução e conferência de equipamentos em um formulário: serviço,
equipamento instalado/retirado, serial ou referência, observação e anexos. As
evidências aceitas são JPG, PNG, WebP e PDF, ficam fora da pasta pública, recebem
hash SHA-256 e podem ser removidas antes da conclusão. O PPPoE é consultado em
leitura; offline só avança com permissão gerencial e justificativa.

## 4. Finalização

Uma única ação confirma pré-requisitos, prepara/aplica o plano, relê o cliente,
confirma o plano final, consulta PPPoE e abre o chamado financeiro. Não existe
desconexão automática porque o mecanismo oficial não foi comprovado; a interface
orienta reconexão manual. O `request_id` é preservado e etapas já concluídas são
ignoradas no retry.

O atendimento técnico termina após plano/conexão/chamado. O processo geral fica
aguardando financeiro e só é concluído automaticamente quando o fechamento do
chamado/tarefa é reconciliado.
