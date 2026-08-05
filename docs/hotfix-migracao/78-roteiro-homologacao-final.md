# Roteiro de homologação final

## Pré-condições

1. Confirmar Beta em `fix/finalizacao-beta-operacional` e working tree sem mudanças versionadas.
2. Confirmar Stable pelo commit dereferenciado `73a95b2e6adae4435d23f94cb9b4304c3317e017`.
3. Confirmar flags de escrita, notificações, chamado e IA bloqueadas.
4. Confirmar cookies distintos dos dois canais.

## Seletor e release

1. Entrar na Beta e conferir `Canal: Beta`, banner e release curta.
2. Abrir Stable e confirmar redirecionamento para a raiz configurada.
3. Fazer login independente na Stable; nenhuma sessão deve ser copiada.
4. Voltar diretamente à Beta.
5. Como administrador, conferir `/api/release`, campos esperados e ausência de segredos.
6. Confirmar ausência de release nas páginas públicas.

## Jornada

1. Abrir cliente e iniciar Upgrade/Migração: deve aparecer etapa 1 dentro do workspace.
2. Alternar Rádio→Fibra, Rádio→Rádio superior, plano sem benefício, retorno à Fibra e retenção; conferir que nenhum valor antigo permanece.
3. Testar assinatura local e remota; desmarcar remoto deve apagar o motivo.
4. Enviar em dry-run, atualizar sem reenviar e reenviar sem trocar token.
5. Corrigir contato e confirmar retorno ao mesmo processo.
6. Preencher equipamento/evidência; verificar PPPoE repetidamente sem perder campos.
7. Testar offline com e sem exceção autorizada.
8. Finalizar e confirmar que dry-run não aparece como ação real.
9. Cancelar antes do aceite, depois do aceite e reiniciar com novo processo.

## Matriz visual em zoom 100%

Validar 1920×1080, 1440×900, 1366×768, 1024×768, 768×1024, 412×915, 390×844 e 360×800. Conferir ausência de overflow, drawer de etapas, ações sticky, assinatura, upload, modal e foco por teclado.

## Encerramento

Reexecutar os smokes, health Beta e Stable, confirmar hashes/worktrees e registrar evidências. Esta entrega pode ser declarada funcionalmente finalizada para homologação, nunca pronta para produção sem aprovação administrativa separada.
