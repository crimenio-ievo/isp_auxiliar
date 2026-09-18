# Terceira iteração — mensagens configuráveis

`Configurações → Mensagens e notificações` administra 10 eventos iniciais em
duas variantes, WhatsApp e e-mail. Os 20 registros são escopados por provedor e
reutilizam `message_templates`.

Eventos: solicitar, reenviar e concluir instalação; solicitar, reenviar e
concluir migração; solicitar/reenviar assinatura avulsa; pendência operacional;
chamado financeiro aberto.

## Canais

O registry central contém:

- `whatsapp`: disponível, adapter Evotrix;
- `email`: disponível, adapter SMTP;
- `sms` e `push`: registrados como indisponíveis, sem adapter e não ativáveis.

Cada variante guarda canal, assunto quando e-mail, corpo, canais suportados e
habilitados, ativo, padrão, versão, operador e data. Instalação, migração e
assinatura avulsa selecionam o evento correspondente no envio compartilhado.

## Segurança e histórico

A lista fechada aceita apenas `%nomecliente%`, `%nomeresumido%`,
`%documentocliente%`, `%logincliente%`, `%telefonecliente%`, `%emailcliente%`,
`%planoatual%`, `%novoplano%`, `%valoratual%`, `%novovalor%`,
`%tecnologiaatual%`, `%novatecnologia%`, `%beneficio%`, `%fidelidade%`,
`%linkaceite%`, `%data%`, `%nomeprovedor%` e `%protocoloprocesso%`.

Variável desconhecida, canal sem adapter, PHP, script ou URL JavaScript
bloqueiam ativação. A prévia usa dados fictícios e saída escapada. Cada envio
auditado recebe snapshot do template. Salvar cria versão, restaurar padrão cria
nova versão e uma versão histórica pode ser reativada como nova versão. O editor
tem autorização de configurações, escopo por provedor e CSRF próprio.

Nesta homologação a edição é real apenas no banco local; adapters de cliente
continuam em dry-run.
