# Padronização das ações da jornada

Data: 2026-08-06. Escopo: Beta de testes, sem alterar o motor de processos ou a topologia Stable/Beta.

## Implementação

O componente `backend/Views/components/migration_actions.php` passou a renderizar, nas quatro etapas, uma ação principal de largura total, navegação inferior e o menu `Mais ações`. O cancelamento continua usando o fluxo auditável existente e não é confundido com `Voltar`.

| Etapa | Ação principal | Voltar | Saída e avanço |
|---|---|---|---|
| Nova condição | Salvar/Corrigir nova condição | Cliente | Salvar e sair; Continuar para o aceite |
| Aceite pronto | Enviar confirmação | Nova condição | Salvar e sair; execução bloqueada |
| Aceite enviado | Atualizar confirmação | Nova condição | Reenviar; Salvar e sair; execução bloqueada |
| Aceite confirmado | Continuar para execução | Nova condição, quando autorizada | Salvar e sair |
| Execução | Verificar conexão agora | Aceite | Salvar sem concluir; Continuar após conexão ou exceção justificada |
| Finalização pendente | Atualizar verificações | Execução | Salvar e sair |
| Finalização pronta | Finalizar atendimento técnico | Execução | Salvar e sair |

`Salvar e sair` na Execução usa uma gravação de progresso e não conclui equipamento, conexão ou etapa. A finalização reutiliza a orquestração idempotente existente: itens concluídos são ignorados e o `request_id` correlaciona a retomada.

## Estado visual e evidências

`MigrationJourneyService` fornece `visual_state`. O estado atual prevalece sobre concluído, há exatamente um `current`, concluído usa check e fundo suave, pendência usa amarelo e não iniciado permanece neutro. A regra vale para a barra superior e para o painel lateral.

A Execução mantém o storage protegido existente e oferece seleção de arquivos e câmera traseira (`accept="image/*" capture="environment"`), preview, remoção antes do envio e múltiplos arquivos. A validação real de MIME, tamanho, hash, processo, etapa e operador continua em `MigrationEvidenceService`.

## Validação

`HomologationCorrectionsSmoke` verifica as quatro projeções visuais, o componente compartilhado, bloqueio do aceite, câmera/preview e matriz da finalização. A validação física em desktop e celular permanece como etapa manual de homologação; não foi simulada como aprovada.
