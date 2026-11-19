# Handoff — N clientes ativos por ONU + modo PPPoE por vínculo (2026-11-19)

Branch `feature/vinculo-n-clientes` (worktree `/var/www/html/_isp_auxiliar_vinculo_n`), a partir do `main` 05c993b
(já com `feature/mapa-tracado`). Base: `docs/estudo-vinculo/RELATORIO.md` seção 5 (branch `estudo/vinculo-onu-cliente`, só lido).
**Sem push, sem merge. A migration 048 NÃO foi aplicada no banco real do .89.** Sem contato com OLT, MkAuth (só o diretório
falso nos testes), cron ou .86. Legenda: **FATO** = verificado nesta rodada; **HIPÓTESE**; **NÃO VERIFICADO**.

## Proposta da 048 (para você aprovar antes de aplicar)
Arquivo `database/migrations/048_binding_n_clientes_por_onu.sql`, um único `ALTER TABLE client_network_bindings`
(reaplicável: `DROP INDEX IF EXISTS` / `ADD COLUMN IF NOT EXISTS`, MariaDB 10.11):

| Índice/coluna | Antes (045) | Depois (048) |
|---|---|---|
| `cnb_onu_ativa (onu_ativa)` | UNIQUE | índice **não único** |
| `cnb_par_ativo (provider_id, onu_ativa, login_ativo)` | — | **UNIQUE** (par ONU+login ativo) |
| `cnb_login_ativo (provider_id, login_ativo)` | UNIQUE | **igual** (login ativo único por provedor) |
| `cnb_porta_ativa (porta_ativa)` | UNIQUE | índice **não único** (ver abaixo) |
| `modo_pppoe` | — | `ENUM('pppoe_na_onu','atras_da_onu') NULL DEFAULT NULL` (NULL = não verificado) |

Rollback: `scripts/rollback_048_binding_n_clientes_por_onu.sql` (atômico: se houver >1 vínculo ativo na mesma ONU/porta o
`ADD UNIQUE` falha e **nada** muda; a coluna `modo_pppoe` e o modo registrado se perdem).

### Como fica o `cnb_porta_ativa` (a decisão que exige mudar mais que o índice)
- **Modelo:** a porta da CTO é ocupação **física da ONU**. Todas as linhas ativas da mesma ONU repetem a mesma
  `cto_node_id/cto_port_id`; uma porta só aceita linhas ativas de **uma única ONU** (ou de um único cliente sem ONU, como
  antes). Escolhi a forma mais simples: **não criei tabela nova** (uma tabela `onu↔porta` exigiria migrar dados e não cobriria o
  cliente que está numa porta sem ONU identificada).
- **FATO — o que muda além do índice:** a regra "porta só de uma ONU" passa do banco para a **aplicação**
  (`NetBindingRepository`), dentro de transação com a porta travada (`SELECT … FOR UPDATE` em `map_ports`).
  Consequências implementadas: ligar um cliente que tem ONU a uma porta move **a ONU inteira**; cliente novo numa ONU já ligada
  herda CTO/porta; cliente com porta própria que entra numa ONU sem porta faz a porta virar da ONU; CTO diferente → 409
  explicando; tirar a CTO de um cliente da ONU tira de todos; tirar um cliente da ONU com outros ainda nela não o deixa com a porta.
- **Risco aceito (FATO do desenho):** o banco deixa de impedir, sozinho, duas ONUs na mesma porta. Só um `INSERT` direto por SQL
  contornaria a regra (o smoke prova que a aplicação recusa). **HIPÓTESE:** a corrida entre dois gestores é coberta pelo lock da
  porta; não testei concorrência real.
- `cnb_par_ativo` é **redundante** com `cnb_login_ativo` enquanto o login ativo for único (um login = uma linha ativa). Criei
  porque você pediu e protege o par se essa regra um dia for relaxada.

## O que foi feito
- **Repositório:** `forOnus` devolve **lista** por ONU; `boundSets.onus` = onu_id → nº de clientes; novos `activeListByOnu`,
  `activeListByPort`, `setMode`; `bindCto`/`bindOnu`/`remove` com as regras acima e modo; `MODOS`.
- **Leitores/API:** `NetFiberReader` (árvore e ficha): `clientes[]` (login, nome, modo), `n_clientes`, `cliente` (primeiro, forma
  antiga, para compatibilidade) e `cto` da ONU; `NetCtoRepository::clientPorts` e a lista de CTOs separam `clientes`, `portas` e
  **`onus`**; ficha da CTO devolve `portas[].clientes[]` e `contagem {clientes, onus, portas_com_cliente}`; busca global devolve
  **uma linha por ONU** ("João +3"; sem duplicar por cliente). Rotas novas: `GET /api/rede-vinculos/onus`, `POST /api/rede-vinculos/modo`;
  `POST /api/rede-vinculos/onu` ganhou `acao=adicionar` e `modo`.
- **Telas:** `/rede-fibra` coluna Cliente = primeiro + "+N" (nomes no `title`) e a ficha da ONU lista todos com o modo;
  `/rede-vinculos` ganhou "ONUs já vinculadas" (lista, modo por cliente, "Tirar da ONU", "Adicionar cliente" por busca) e escolha
  de modo ao confirmar sugestão; ficha da CTO conta "N cliente(s) em M ONU(s)" e lista os clientes da porta; `/rede-ctos` mostra "4 · 1 ONU(s)".
- **Decisões minhas:** (1) a sugestão pelo MAC (`confirmar`) continua só para ONU **sem** vínculo (409 orienta "adicionar");
  empilhar cliente é sempre ação explícita; (2) "adicionar" grava `origem='manual'`; (3) um login com ONU não é movido para outra ONU
  (409; remover antes); (4) o último cliente a sair da ONU fica com a porta como cliente **sem ONU** (comportamento anterior à 048).

## Testes (todos verdes)
- Novo `tests/Feature/NetBindingNClientesSmoke.php` (67 verificações, schema descartável): **4 clientes na mesma ONU**, porta da ONU,
  409s, leitores em lista, CTO (clientes × ONUs), busca, modo, mover/remover e o **rollback** (falha atômica com 2 na mesma ONU,
  sucesso com 1, reaplicação e idempotência). Ele achou um bug real na 048 (reaplicar duplicava `cnb_par_ativo`), corrigido.
- Ajustados: `NetBindingSmoke` (101; o teste "banco só aceita 1 por porta" virou "índices da 048"), `NetFiberReaderSmoke` (128; novas chaves),
  jsdom `rede_vinculos.js` (53), `rede_fibra.js` (184), `rede_ctos.js` (54). `tests/net/run_all.sh`: **0 grupos com falha** (inclui o smoke novo).
  Mapa: jsdom completo + `MapMapeamentoFlow/Tracado/CableImport/Route`, `FeatureFlags`, `PreMapsRouting` verdes.
  **Não rodei** `tests/map/suite*.php` (gravam "ZZ TESTE" no banco real).
- **Ensaio da 048 pela ferramenta oficial** (`scripts/apply_migrations.php`) numa **cópia** do banco real (dump → `isp_auxiliar_pre048_sim`):
  aplicou sem erro, índices/coluna como na tabela acima, e o rollback também funcionou; cópia removida.
- **Navegador real** (`node tests/browser/vinculo_n_clientes.js`, dados fictícios, schema descartável, sessão de harness e `.env` temporário removidos): 14 verificações, 0 falhas
  (coluna "+3", ficha da ONU, lista e ficha da CTO, "ONUs já vinculadas", mudar modo, tirar cliente). Capturas em
  `/root/isp-auxiliar-backups/capturas-vinculo-n-clientes/`. Achado do teste: clicar no centro da linha da ONU cai num link de cliente (não é bug novo).

## Dump e estado do banco real (ainda não aplicado)
- **Dump pré-048:** `/root/isp-auxiliar-backups/isp_auxiliar-pre048-20261119-182107.sql` (717 KB, `--single-transaction`).
- **FATO:** `client_network_bindings` tem **0 linhas**; migrations até a 047 aplicadas. Com 0 vínculos a 048 não pode falhar por dados.
- **Para aplicar (só com sua ordem):** `php scripts/apply_migrations.php` na worktree do `main` depois do merge. Código antigo do `main`
  continua funcionando com o banco novo (o `INSERT` da 045 não usa `modo_pppoe`).
- **.86:** além das migrations já pendentes (019–021, 030–034, 045–047), agora também a **048**.

## NÃO VERIFICADO / limites
- "Adicionar cliente" com busca real no MkAuth (só jsdom e diretório falso; o scratch do navegador não alcança o MkAuth).
- Concorrência real (dois gestores na mesma porta). Desempenho com muitas ONUs (a lista usa 1 consulta por 500 ONUs).
- Mensagem do mapa ao reduzir portas/excluir CTO cita o **primeiro** cliente da porta (`MapRepository::activeBindingOnPort`); funciona, mas não conta os demais.
- Sugestões pelo MAC não consideram "ONU já vinculada, mas com espaço para mais um" (decisão 1).
- O estudo apontou que MAC por si só não prova vínculo (RELATORIO §3/§6): esta rodada só abre a **modelagem**; nada vincula automaticamente.

## PARA O USUÁRIO
1. Aprovar a proposta (principalmente a regra da porta na aplicação) e ordenar a aplicação da 048 no .89 (dump já feito).
2. Confirmar as decisões 1–4 acima.
3. Merge ff-only no `main`: aguardando sua ordem.
