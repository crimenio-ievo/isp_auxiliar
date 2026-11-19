# Handoff — rodada geral 2 (2026-11-19)

Continuação de `docs/handoff-rodada-geral.md`, cujos itens 1 e 2 estão no `main` 8104317. O servidor é o .89
(TESTE). Trabalho no branch `feature/rodada-geral-2`, worktree temporária `/var/www/html/_isp_auxiliar_rodada2`.
Nesta rodada não houve contato com a OLT, escrita no MkAuth (.88), alteração no .86 ou mudança no cron de
telemetria.

## 3a. /rede-fibra: Cliente e CTO visíveis por padrão

- Código: `public/assets/js/rede-fibra.js`. O padrão passa a ser Estado, Sinal, Distância, **Cliente**,
  **CTO**, Quedas 24 h e MAC.
- Migração única da preferência: a escolha de colunas fica no navegador (`localStorage['rf.cols']`). Se uma
  preferência salva não tem a marca `rf.cols.vinc`, Cliente e CTO são acrescentadas uma vez e a marca é
  gravada. Toda gravação de preferência (`saveCols`) também grava a marca, então ocultá-las depois é
  respeitado.
- **Fato:** essa preferência é por navegador, não por usuário no banco. Não houve migration de banco.
- Testes (`tests/net/rede_fibra.js`, 181 verificações): preferência antiga ganha Cliente/CTO e a marca; depois
  de ocultar Cliente, ela não volta; combinação com a migração antiga (rx/band → Sinal) sem duplicar; usuário
  sem preferência anterior que oculta CTO não a vê reaparecer.
