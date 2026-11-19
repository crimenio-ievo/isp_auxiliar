# Cadastro de rede (OLT, CTO, vínculos) — guia e lacunas

Levantamento de 2026-11-19 no `main` (56ec7a4) do servidor .89 de TESTE. Fonte: leitura do código, do banco do .89 (somente SELECT) e execução real das telas com uma sessão fictícia de gestor (sem criar usuário). Nada foi escrito no MkAuth nem na OLT.
Convenção: **Fato** = observado/lido; **Hipótese** = inferido, não provado; **Não verificado** = não testei.

## 1. Como cadastrar HOJE (passo a passo)

### 1.1 Pelo mapa (`/mapa`, só gestor/admin)
1. **Site/POP**: botão *Adicionar* → tipo POP (ou Torre) → nome, coordenadas (clicando no mapa; a barra "Adicionar elemento aqui" aparece ao marcar um ponto) → salvar. Torre pode ficar dentro de um POP.
2. **OLT**: no painel do POP/torre, *Adicionar equipamento aqui dentro* → tipo OLT, nome, e as quantidades de portas por família (`ETH`, `SFP`, `PON`, `FO`). As portas `PON 1..N` são criadas pelo servidor (a OLT não tem coordenada: herda a do POP). Opcionalmente "Tx padrão" e "máx. clientes padrão".
3. **CTO**: *Adicionar* → CTO, nome, coordenada (clique no mapa) e **Número de portas** (1–144). O servidor cria `Porta 1..N` (família `fo`, externas) a partir de `attrs.capacity`. Mudar a capacidade depois soma portas no fim ou remove as de maior número, só se livres.
4. **Splitter**: dentro da CTO/CEO (painel da caixa → *Adicionar splitter*), razão do catálogo (1x2…1x64 ou 5/95…50/50); entrada e saídas viram portas.
5. **Ligação OLT→CTO**: no painel do elemento, *Conectar*; tipo `backbone`/`distribuicao`/`drop`, porta de cada ponta (`PON n` da OLT → `Porta n` da CTO). Uma porta só pode estar em uma ligação (409 se já usada); porta `defeito` é recusada, `reservada` avisa.
6. Tudo grava via `POST /api/mapa/*` (CSRF do escopo `map`, auditoria `map.*` em `audit_logs`, transação por nó).

**Dado real no .89 (Fato):** 1 POP (`iEvo`, id 8), 1 OLT (`OLT-001`, id 42, PON 1–4 + ETH 1), 2 CTOs (`CTO` id 14 e `CTO-001` id 33, capacidade 16), 3 ligações (OLT-001 PON 1 → CTO `Porta 1` backbone; CTO→iEvo e CTO-001→CTO distribuição). 33 ONUs lidas.

### 1.2 O que funciona / falha / falta (Fato)
| Item | Situação |
|---|---|
| Criar POP, OLT, CTO, splitter e ligações no mapa | Funciona (suíte `tests/map` cobre rotas e regras). |
| Lista de CTOs/OLTs fora do mapa | **Não existe**: só dá para ver clicando no mapa. |
| Coordenada da CTO | Só por clique no mapa. Não aceita "lat, lng" colado do Google Maps nem geolocalização do aparelho (campo numérico `lat`/`lng` existe na API, mas não há formulário). |
| Importação em massa | **Não existe** (CSV). |
| Ocupação da CTO (portas ocupadas × livres × clientes) | "Ocupada" = porta em alguma ligação (`map_links`). **Não conta clientes**: não há vínculo cliente↔porta. |
| Ligar OLT→CTO | Funciona, mas só dentro do mapa e porta a porta. |
| Excluir CTO | Bloqueado (409) enquanto houver filhos (splitters) ou ligações; não olha clientes (não existem). |

## 2. Como uma OLT entra na telemetria HOJE
- **Só por linha de comando** (não há tela): `php scripts/net_register_device.php --map-node=ID --host=IP --profile=CODE --credential-ref=NET_CRED_X --expect-pons=N [--apply]`. Simula por padrão; `--apply` grava em `net_devices` (+ auditoria `net.device.create`, ator `cli`).
- **Pré-requisitos validados pelo script** (Fato): o nó do mapa existe e é `olt`; tem exatamente N portas PON e `port_num` = 1..N (contrato: `port_num` da PON no mapa = `pon_index` da OLT); o nó ainda não tem `net_devices`; o perfil existe, está `active` e é válido; `host` é IP; a referência segue `NET_CRED_[A-Z0-9_]+`.
- **Credencial**: `net_devices.credential_ref` guarda só o NOME da variável do `.env` (`NetTransportFactory` lê o valor em tempo de coleta e nunca grava/loga). Chave existente no .env do .89 (só o nome): `NET_CRED_OLT_89_COMMUNITY`.
- Depois: o 1º ciclo precisa de `php scripts/net_collect.php --device=ID --kind=status --approve-identity` para gravar a identidade de referência (sysObjectID, IP próprio, engineID); sem isso o coletor não confia no equipamento.
- **Coleta**: cron `/etc/cron.d/isp-net-telemetria` chama `scripts/net_tick.php --device=1` a cada minuto (status a cada 600 s, potência a cada 1800 s, backoff após falhas). **Um equipamento por linha de cron** — não existe modo "todos".
- **Perfis suportados** (Fato, tabela `net_device_profiles`): só **Hioso EPON** (`hioso-epon-25355-4-4`): v1 e v2 `deprecated`, **v3 `active`**. Transporte: só SNMP v2c (GET/GETNEXT). Não existe perfil para V-SOL nem HA7308VX; `DeviceProfile` aceita novos perfis por JSON (precisa levantar as OIDs com a OLT).
- `equipamento` no .89: `net_devices` #1 → nó 42, host 192.168.0.89, perfil #3, ativo.

## 3. Vínculos (Fato)
- **Não existe** `client_network_bindings` nem equivalente: nenhuma tabela liga cliente↔CTO/porta ou ONU↔cliente. `NetFiberReader` devolve `cliente=null`, `cto=null` e `fontes.vinculo_cliente=false`; a tela mostra "sem vínculo"/"ainda não lido".
- `scripts/net_match_report.php` (somente leitura, não grava): casa o MAC da ONU (EPON) com o `callingstationid` do último registro do `radacct` de cada login ativo na janela de N dias, pelo prefixo de 5 octetos com diferença no último octeto (`caller − ONU`). Resultado de hoje (janela de 30 dias do registro mais recente do radacct, 2026-10-13, clone .88 parado): 33 ONUs → 12 com candidato único (36,4%), 0 ambíguos, 21 sem candidato; distâncias `{0:1, +1:4, +9:7}`; nenhum login casa com 2 ONUs. A regra "MAC −1" (ONU = caller − 1, distância +1) aparece em 4 pares; o grupo "+9" em 7 (padrão consistente, mas não verificado se é regra do modelo de ONU).
- **MkAuth** (Fato, `MkAuthDatabase::normalizeClientProfileRow`): a integração já lê `login`, `nome`, `cpf_cnpj`, `coordenadas`, `caixa_herm`, `porta_splitter`, `porta_olt`, `onu_ont`, `equipamento`, `user_mac`. Contagem no clone .88 (SELECT): 807 clientes, 608 ativos, **0 com `caixa_herm`, `porta_splitter`, `porta_olt` ou `onu_ont` preenchidos**, **807 com `coordenadas`**. Ou seja, o MkAuth não tem a informação de CTO; o vínculo precisa nascer aqui.

## 4. Busca global do topo e OLT/equipamento (Fato + hipóteses)
Verificado no navegador (Playwright, `main`, sessão fictícia de gestor, banco real, só leitura):
- No `main`, a busca **funciona**: digitar "OLT" (≥ 2 caracteres, 250 ms) chama `GET /api/rede-fibra/buscar?q=OLT` e mostra a sugestão `OLT-001` (link `/rede-fibra?dispositivo=1`); clicável em Home, Clientes e Rede de fibra, em 1366 px e 390 px. `NetSearchReader` também acha por IP (`192.168.0.89`) e ONU por MAC parcial (`002e`).
- O que o usuário provavelmente não vê, em ordem de probabilidade:
  1. **Outra pasta/URL**: `teste.ievo.com.br/isp_auxiliar/public` (a que a VPN 10.4.0.2 usa) serve o worktree `isp_auxiliar` no branch `release/pre-maps-v0`, que **não contém** o commit c5c3cf2 (busca de rede) nem `/rede-fibra`. Só `testes.ievo.com.br` serve o `main` com a busca. (Fato: `git merge-base --is-ancestor c5c3cf2 HEAD` falha lá.)
  2. **Enter em vez de esperar a sugestão**: o formulário envia para `/clientes?q=` (só clientes MkAuth); a sugestão de rede só existe no dropdown.
  3. **Cobertura limitada**: só nós `olt/switch/router/radio/server/other` por nome ou IP de gerência, e ONU por MAC ≥ 4 hex. **CTO, PON, POP e cliente-com-CTO não são buscados** (a busca por "CTO" devolve `[]`).
  4. **Hipótese (não verificada)**: o `?v=` dos assets do layout usa o id de release fixo (`beta-test-final-2026.08`), então um navegador com `app.js` antigo em cache pode não ter o código da busca de rede. Telas novas devem usar `filemtime` (como `rede-fibra.js`).
- Permissão: `data-network-search` só é emitido para gestor/admin; técnico não recebe (por desenho).

## 5. Lacunas (o que esta rodada resolve)
1. Sem tela de equipamentos (cadastro de OLT só por CLI) → **Fase 2** `/rede-equipamentos`.
2. Sem lista/cadastro rápido/CSV de CTO, sem paste de coordenada e geolocalização → **Fase 3** `/rede-ctos`.
3. Sem vínculo cliente↔CTO/porta e ONU↔cliente → **Fase 4** `client_network_bindings` (migration ≥ 045).
4. Busca global sem CTO/PON/vínculo → **Fase 5**.
5. Sem flags `feature.map`/`feature.net` por provedor (necessário antes de levar ao .86) → **Fase 6**.
6. Coletor sem modo "todos os equipamentos" (um cron por equipamento) → `net_tick.php --all` (Fase 2).
7. **Fora do escopo desta rodada**: perfis de OLT V-SOL e HA7308VX; abrir o mapa centrado em um nó por URL (necessário para "ver no mapa": será acrescentado de forma mínima na Fase 3).

## Não verificado
- Acesso real do navegador do usuário (URL usada, cache) — só reproduzi com um navegador de teste.
- O comportamento do `radacct` ao vivo: o clone .88 está parado desde 2026-10-13.
