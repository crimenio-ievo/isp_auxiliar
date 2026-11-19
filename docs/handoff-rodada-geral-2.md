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
- **Fato (leituras reais, SELECT, às ~16:00 UTC):** `data_ins` máximo 2026-10-08. Na primeira consulta `radacct`
  tinha 0 linhas; minutos depois `latestVisibleDate()` devolveu 2026-11-19 (origem "sessão"). **Não verificado**
  por quê (hipótese: o `radacct` está sendo recarregado/atualizado por outro processo). Conclusão: a data
  mostrada depende do momento da leitura; confira a tela antes de confiar nas sugestões.
- Testes: `NetBindingSmoke` (101) e `rede_vinculos.js` (39).

## 4. Fluxo de mapeamento real

- Teste: `tests/Feature/MapMapeamentoFlowSmoke.php` (schema descartável; controlador do `/mapa` com CSRF e
  sessão de gestor): 20 passos funcionam; 2 recursos **não existem** (pontos intermediários do cabo, reserva
  técnica como recurso próprio). Nada de ZZ TESTE no banco real.
- Guia para o usuário: `docs/guia-mapeamento.md` (ordem, o que falta e contornos, aviso de copiar as tabelas para o .86).
- **Não feito:** a passagem por navegador headless (Playwright). Os scripts de browser da rodada anterior
  não existem mais e a interface foi avaliada só pelo código e pelos testes jsdom já existentes. Ficam como
  **não verificados em tela**: a distância ao arrastar um ponto, o botão de splitter e o conector do cabo.
- Nenhuma correção de código foi necessária (nenhuma falha encontrada).

## 5. Equipamentos e credenciais (CP-1 e CP-2; CP-0 e CP-3 abaixo)

Migrations **046** (`net_credentials`, `net_devices.credential_id`, `credential_ref` aceita NULL) e **047**
(`net_equipment_models`, `net_equipment`, `net_equipment_movements` + pré-cadastro). Rollbacks em
`scripts/rollback_046_net_credentials.sql` e `scripts/rollback_047_net_equipment_catalog.sql`. **Fato:** no
momento deste commit as duas migrations ainda **não** estão aplicadas no banco real do .89 (ver "Aplicação").

### CP-1 Credenciais cifradas
- `NetSecretBox` (libsodium secretbox, chave `NET_SECRET_KEY` base64 de 32 bytes, versão `NET_SECRET_KEY_VERSION`),
  `NetCredentialRepository` (listas só com metadados; `secretFor()` é o único caminho ao texto claro e só o
  coletor o usa) e `NetCredentialMigrator`.
- Tela `/rede-credenciais` (gestor/admin; CSRF escopo `rede`; auditoria `net.credential.*` sem segredo):
  mostra "definida em DATA por USUÁRIO", "Substituir" e "Excluir" (bloqueado se um equipamento usa). Sem chave
  ou sem a tabela, o cadastro e a substituição ficam bloqueados com aviso.
- Coletor (`net_tick.php`, `net_collect.php`) e "Testar conexão": preferem `credential_id` e usam
  `credential_ref` (.env) como alternativa. O equipamento atual (credential_id NULL) continua igual.
- `scripts/net_secret_key.php`: gera `NET_SECRET_KEY` no .env **só se não existir** e não imprime a chave.
  **Não foi executado** no .env real (decisão sua; ver PARA O USUÁRIO).
- `scripts/net_migrate_credentials.php --ref=NET_CRED_OLT_89_COMMUNITY --name="OLT 89" [--apply]`: simulação
  por padrão. **Não rodei `--apply`** nem testei contra a OLT, como pedido.
- **Perder a chave = perder as credenciais cifradas.** O backup do `.env` (que guarda a chave) tem de ser
  guardado separado do dump do banco. `backup_map.sh` não inclui credenciais (só mapa e vínculos).
- Testes: `NetCredentialSmoke` (55) e `rede_catalogo.js` (25, jsdom): o segredo digitado nunca reaparece em
  resposta, HTML, auditoria nem log; sem chave bloqueia; migrador idempotente; script de chave não troca chave.

### CP-2 Catálogo e equipamento físico
- Telas `/rede-modelos` (catálogo) e `/rede-inventario` (equipamento físico, situação, local, histórico
  mínimo). `/rede-equipamentos` agora tem modelo do catálogo + série/MAC/patrimônio opcionais + credencial
  (.env ou banco) e a coleta nasce desativada. Um ativo por nó (`map_nodes.equipment_id`).
- Pré-cadastro só de fatos: Hioso HA7004S (EPON, 4 PON, perfil ativo), Hioso HA7308VX (EPON, 8 PON, perfil
  "ainda não suportado"), V-SOL "a identificar" (sem tecnologia inventada, perfil não suportado). Provedores
  criados depois da migration recebem o mesmo pré-cadastro na primeira abertura (`seedFacts`).
- `scripts/net_equipment_migrate.php [--link-node=42 --model=HA7004S] [--apply]`: migra fabricante/modelo do
  `attrs_json` para o catálogo e liga a OLT-001 ao HA7004S (simulação por padrão). **Não rodei `--apply`.**
  Nós sem modelo no catálogo só são relatados.
- Testes: `NetCatalogSmoke` (62), `NetDeviceCadastroSmoke` (104) e `rede_equipamentos.js` (35) ajustados.
- Achado: o teste jsdom pegou um bug da própria tela (select de tipo vazio ao criar); corrigido.
