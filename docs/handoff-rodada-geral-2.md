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

## 3c. /rede-vinculos: sugestões "média" confirmáveis, com motivo e data da base

- **Fato:** a confiança "média" (até 16 no último octeto, candidato único) já vinha com os botões
  Confirmar/Recusar. Agora cada sugestão mostra selo + motivo: "MAC −1 no último octeto" (alta),
  "MAC +9 no último octeto (diferença maior que 1: confira antes de confirmar)" (média) e "ambígua ou distante:
  só confirme com certeza" (baixa). Nada é vinculado sem o clique humano; o POST segue exigindo CSRF e gestor.
- Aviso na tela: "A base do MkAuth lida por este servidor vai até DD/MM/AAAA (cliente/sessão mais recente)".
  Nova leitura SELECT-only `MkAuthDatabase::latestVisibleDate()` (maior `sis_cliente.data_ins` ou
  `radacct.acctstarttime`), exposta como `base_mkauth_ate` na API de sugestões.
- **Fato (leitura real):** `data_ins` máximo 2026-10-08; `radacct` está **vazio** (0 linhas) neste clone, então
  hoje não há sessão PPPoE para sugerir por MAC. A nota anterior falava em radacct até 2026-10-13: é
  **hipótese não verificada** que o clone tenha sido recarregado desde então.
- Testes: `NetBindingSmoke` (101) e `rede_vinculos.js` (39).
