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

## 3b. Busca de rede do topo liberada para o técnico (só leitura)

- `GET /api/rede-fibra/buscar` agora aceita, além do gestor/admin, quem tem `clients.view` (o técnico). Sem
  essa permissão continua 403. O atributo `data-network-search` do topo segue a mesma regra e só aparece com
  `feature.net` ligada.
- O filtro é feito **no servidor** (`NetSearchReader::search(..., readOnly: true)`). O técnico só recebe
  destinos que já pode abrir sem editar:
  - **CTO** → `/rede-ctos/ficha?id=` (a ficha já era só leitura para ele);
  - **cliente vinculado** → `/clientes/detalhe`;
  - **ONU** → só quando vinculada, abrindo a CTO, ou o cliente quando não há CTO.
- PON, equipamento, telemetria e IP de gerência ficam de fora para o técnico, porque levam a `/rede-fibra` e
  `/rede-equipamentos`, que são só de gestor. **Decisão minha**: a alternativa seria abrir `/rede-fibra` para
  o técnico, o que vai além do pedido.
- Extra: o resultado de ONU vinculada passou a mostrar "cliente X · CTO Y" também para o gestor.
- Testes: `NetFiberReaderSmoke` (128; o teste que exigia 403 do técnico agora exige 200; perfil sem
  permissão continua 403; nenhum resultado do técnico aponta para `/rede-fibra`, `/rede-equipamentos`,
  `/rede-vinculos`, `/mapa` ou API) e `FeatureFlagsSmoke` (99; o topo do técnico tem a busca de rede só com a
  rede ligada).
