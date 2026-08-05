# Aceite final

A etapa visual única reúne preparação, assinatura, envio e confirmação, mantendo as etapas internas para auditoria.

## Assinatura

- `Solicitar assinatura remota` substitui a noção limitada de ausência física.
- O motivo é obrigatório e usa uma lista fechada de situações operacionais.
- Ao desmarcar, o motivo é apagado do formulário e o snapshot local não o recebe.
- Sem solicitação remota, a assinatura é coletada localmente.

## Contatos e estados

WhatsApp e e-mail aparecem somente para seleção, com valores cadastrados somente leitura. A correção usa fluxo controlado, auditado e retorna ao mesmo processo.

- Antes do envio: `Enviar confirmação` e `Salvar e sair`.
- Enviado: `Atualizar confirmação`, `Reenviar confirmação` e `Salvar e sair`.
- Confirmado: `Continuar para execução`.

Atualizar executa apenas a releitura do estado. Reenviar reutiliza o mesmo aceite e token, sem exigir nova assinatura e sem gerar token adicional. Horários de última verificação e confirmação são exibidos.

Na homologação, WhatsApp e e-mail permanecem em dry-run.

