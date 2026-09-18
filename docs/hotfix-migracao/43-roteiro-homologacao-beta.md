# Roteiro de homologação Beta

## Antes de abrir acesso

1. Criar backup de código, banco local, `.env`, storage, documentos, evidências e logs.
2. Instalar checkout independente com `install_beta.sh --dry-run` e depois, sob autorização, sem `--dry-run`.
3. Revisar `.env` a partir de `.env.beta.example`; usar DB e sessão exclusivos.
4. Confirmar `MKAUTH_WRITE_ENABLED=false`, Evotrix/e-mail/chamado em dry-run.
5. Aplicar migrations apenas no DB Beta, com backup e `--confirm-migrations`.
6. Habilitar DNS/certificado/vhost apenas após revisão da infraestrutura.
7. Confirmar commits diferentes em `/api/release` com sessão administrativa.

## Cenário funcional

1. Abrir cliente sintético e iniciar migração; deve abrir “Etapa 1 de 4”.
2. Validar regras automáticas e ajuste justificado de benefício/fidelidade.
3. Preparar aceite presente e ausente; confirmar que mensagens ficam em dry-run.
4. Corrigir contato e confirmar retorno ao mesmo processo/aceite.
5. Anexar JPG/PNG/WebP/PDF, abrir miniatura e remover antes de concluir.
6. Conferir PPPoE online e offline; testar exceção somente com gestor.
7. Finalizar com escrita bloqueada e verificar pendência `waiting_mkauth`.
8. Em ambiente isolado com fakes, validar retry parcial e chamado.
9. Cancelar antes/depois do aceite e com ação externa sintética; iniciar processo novo.
10. Fechar tarefa financeira sintética e confirmar conclusão automática/timeline.

## Viewports

Executar em zoom 100% nos oito tamanhos documentados em
`42-testes-quinta-iteracao.md`, verificando fonte, sidebar, quatro etapas, campos
avançados, modal, upload e finalização. Registrar capturas e aparelho/browser.

Não habilitar escrita ou envio real apenas para completar um cenário. Qualquer
teste real exige plano próprio, cliente de teste, janela, backup e autorização.
