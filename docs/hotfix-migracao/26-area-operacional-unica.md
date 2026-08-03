# Quarta iteração — área operacional única

## Fonte de verdade

A migração é apresentada em `/processos/migracao?id={id}&step={chave}`. A rota
antiga de detalhe redireciona migrações para esse workspace, e a rota de etapa
também preserva o mesmo destino. Não existem mais quatro telas paralelas como
fonte de navegação.

O workspace contém:

- cabeçalho com cliente, processo e etapa ativa;
- um único progresso `concluídas/total`;
- legenda compacta de quatro fases: Condição, Aceite, Execução e Plano/financeiro;
- conteúdo somente da etapa selecionada;
- checklist compartilhado de 11 etapas;
- ações Voltar, Salvar e sair, Continuar e Finalizar, conforme a etapa.

## Navegação Next → Next → Finish

`Continuar` conclui a etapa manual válida e volta ao workspace usando a próxima
pendência derivada pelo service. Etapas automáticas já concluídas oferecem a
próxima etapa. `Finalizar migração` continua sujeito à validação de todas as
etapas obrigatórias; exceção administrativa exige permissão e justificativa.

`Salvar e sair` conserva evidência/observação e retorna ao perfil. A retomada usa
`resume_url`, calculada pela mesma `next_pending_key` do checklist. O valor do
botão acionado é copiado antes do bloqueio contra clique duplo, evitando perder
`next_action` ou `continue_to` quando os controles são desabilitados.

## Correção e cancelamento no workspace

- aceite pendente: “Corrigir nova condição” mantém o processo e troca o aceite;
- aceite confirmado: mostra aviso explícito e exige substituição com motivo;
- processo cancelado: fica somente histórico, sem pendência ativa, e oferece
  iniciar nova migração;
- processo concluído: não volta a ser painel ativo no cliente.

## Invariantes

O progresso, o status e a próxima pendência são derivados das etapas requeridas.
Um processo cancelado não tem `current_step_key` nem `next_pending_key`. Um aceite
aceito e não revogado implica preparação, envio/abertura e confirmação concluídos.
Dry-run de plano não equivale a plano aplicado.
