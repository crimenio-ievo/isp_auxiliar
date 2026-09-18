# Segunda iteração — checklist e retomada

## Comportamento comum

Cada etapa é aberta diretamente e mostra apenas objetivo, situação, pendência,
ação principal, salvar/sair e retorno ao checklist. Detalhes técnicos ficam em
`<details>`.

As ações são:

- `Salvar e continuar`: conclusão manual, exigindo observação ou evidência;
- `Salvar e voltar depois`: mantém a etapa em andamento;
- `Pular por enquanto`: mantém `waiting` e exige motivo;
- `Atualizar situação`: consulta somente as fontes já disponíveis;
- `Registrar dry-run`: grava a proposta, sem marcar ação externa como feita;
- `Voltar ao checklist`.

O duplo clique é bloqueado no JavaScript. O valor da ação é copiado para um
campo oculto antes de desabilitar os botões.

## Migração — 11 etapas

1. Dados da migração.
2. Preparar documento.
3. Enviar ou abrir aceite.
4. Confirmar aceite.
5. Executar instalação ou troca.
6. Confirmar equipamento.
7. Alterar plano no MkAuth.
8. Validar conexão.
9. Abrir chamado financeiro.
10. Acompanhar chamado financeiro.
11. Concluir migração.

## Instalação — 11 etapas

1. Cadastro.
2. Dados comerciais.
3. Preparar contrato.
4. Enviar ou abrir aceite.
5. Confirmar aceite.
6. Execução técnica.
7. Confirmar equipamento.
8. Ativar serviço.
9. Abrir chamado ou validar no MkAuth.
10. Acompanhar chamado.
11. Concluir instalação.

## Assinatura avulsa — 8 etapas

1. Selecionar cliente.
2. Identificar documento sem assinatura.
3. Preparar solicitação.
4. Revisar dados.
5. Enviar ou abrir aceite.
6. Acompanhar e confirmar aceite.
7. Validar documento assinado.
8. Concluir solicitação.

## Avanço não linear

Concluir uma etapa posterior não apaga uma pendência anterior. A próxima
pendência continua sendo a primeira etapa obrigatória ainda aberta. O teste
automatizado conclui execução técnica enquanto o aceite aguarda o cliente e
confirma que `confirm_acceptance` continua sendo a próxima pendência.

## Bloqueio de conclusão

A etapa final não pode ser concluída pelo formulário comum. O botão geral:

1. carrega todas as etapas;
2. exclui somente a própria etapa final da verificação;
3. lista as obrigatórias pendentes;
4. bloqueia a conclusão normal;
5. conclui e audita quando todas estiverem válidas.

Gestor/administrador pode usar exceção autorizada. A justificativa é
obrigatória e o snapshot das pendências fica na evidência da conclusão.

## Retomada

O detalhe do cliente mostra cards com tipo, estado, progresso, responsável e
próxima pendência. As ações são:

- `Continuar próxima pendência`;
- `Ver todas as etapas`.

A listagem `/processos` filtra por situação, tipo e login. Processos concluídos
e cancelados permanecem consultáveis.
