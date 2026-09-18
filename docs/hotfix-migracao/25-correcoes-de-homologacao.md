# Quarta iteração — correções de homologação

Data: 2026-08-03. Branch local `fix/homologacao-migracao-ui`, criada a partir
do commit `16b40f220573ed459b52ab33262e5f9255733c37`. Esta entrega permanece restrita
à homologação local: não houve push, merge, deploy ou alteração em produção.

## Diagnóstico reproduzido

O processo local #25 estava cancelado, mas conservava `contract_id=150`,
`acceptance_id=128`, progresso 3/11 e `next_pending_key=send_acceptance`. O aceite
estava marcado como aceito e as etapas registravam `confirm_acceptance` concluída
com `send_acceptance` não iniciada. A projeção operacional, o contrato, o aceite
e o checklist eram atualizados por caminhos diferentes.

Após a implementação, a reconciliação local do #25 preservou as 11 etapas e as
3 conclusões reais, marcou as outras 8 como canceladas, revogou o aceite sem
apagar sua evidência aceita, marcou o contrato como cancelado e zerou
`current_step_key`, `next_pending_key` e `next_pending_label`.

O bloqueio ao salvar o plano tinha uma causa independente e objetiva:
`buildUpgradeContractData()` gravava `tipo_adesao=nao_aplicavel`, mas o enum real
de `client_contracts.tipo_adesao` aceita somente `cheia`, `promocional` ou
`isenta`. Em SQL estrito, o insert falhava antes de criar a condição e o aceite.

## Correções aplicadas

- `tipo_adesao` agora usa `isenta` quando existe isenção e `cheia` nos demais
  casos; valor total e parcela da adesão são coerentes.
- o plano selecionado, a tecnologia e o valor continuam nos metadados internos,
  mas a opção visível foi simplificada;
- erros retornam na própria tela, preservam a seleção e apontam o primeiro campo
  inválido;
- cancelamento passa por reconciliação central, revoga o aceite, encerra
  pendências, limpa a próxima ação e preserva o histórico;
- aceite confirmado conclui também preparação e abertura/envio anteriores,
  evitando a combinação impossível “confirmado sem envio”;
- condição com aceite pendente é revisada no mesmo contrato e processo, com novo
  aceite e versão documental; condição aceita exige substituição controlada;
- perfil, contrato, checklist e URLs de retomada usam a mesma projeção do
  processo operacional.

## Salvaguardas mantidas

`MKAUTH_WRITE_ENABLED=false`; Evotrix, e-mail e chamado financeiro em dry-run;
nenhum arquivo de IA rastreado. `.env`, backups, aceites, documentos e evidências
existentes não foram removidos. Nenhum arquivo em
`storage/contracts/acceptances/` faz parte dos commits.

## Limite desta entrega

As correções foram validadas por testes de renderização, transações com rollback,
sintaxe e HTTP local. A jornada visual em navegador real e os testes físicos
Android/iOS permanecem obrigatórios. O resultado está pronto para continuar a
homologação, não para produção.
