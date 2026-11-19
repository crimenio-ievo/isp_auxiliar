# Modelo de equipamentos, credenciais e telemetria (CP-0)

Base do futuro inventário. Escrito em 2026-11-19 junto com as migrations **046** e **047**. O que está marcado
**(existe)** já foi implementado e testado; **(depois)** é encaixe previsto, sem tabela nova.

## As cinco coisas, e só uma origem para cada dado

| Conceito | Tabela | Dono do quê |
|---|---|---|
| **Modelo** (catálogo) | `net_equipment_models` | Fabricante, modelo, tipo (olt/onu/ont/switch/roteador/radio/outro), tecnologia, portas por família (`ports_json`), perfil de telemetria padrão, ficha técnica (`spec_json`). **Única origem de fabricante e modelo.** |
| **Equipamento físico** | `net_equipment` | Uma unidade: modelo, série, MAC, patrimônio, situação (estoque/instalado/manutenção/baixado), local atual (`location_kind` + nó do mapa ou observação). Histórico mínimo em `net_equipment_movements`. |
| **Nó do mapa** | `map_nodes` | A posição/lugar na rede. **Um ativo por nó**: `map_nodes.equipment_id` (coluna que já existia, sem FK) aponta para o equipamento que está nele. |
| **Credencial** | `net_credentials` | Segredo cifrado (libsodium secretbox), nonce, versão da chave, quem/quando. Nunca volta por API, tela, log ou auditoria. |
| **Telemetria** | `net_devices` | Como coletar: nó do mapa (1:1), perfil, IP, intervalos e **`credential_id`** (ou `credential_ref` do .env como alternativa). Não guarda fabricante, modelo nem segredo. |

Fluxo de leitura (**existe**): a tela de OLTs lê fabricante/modelo de
`map_nodes.equipment_id → net_equipment → net_equipment_models`. Só se o nó não tiver equipamento ligado usa o
texto antigo de `attrs_json` (compatibilidade).

## Regras

- **Catálogo** (**existe**): chave única por provedor+fabricante+modelo. Não exclui modelo em uso. Pré-cadastro só de
  fatos: Hioso HA7004S (EPON, 4 PON, perfil ativo), Hioso HA7308VX (EPON, 8 PON, perfil "ainda não suportado") e
  V-SOL "a identificar" (sem tecnologia nem portas inventadas, perfil não suportado).
- **Equipamento físico** (**existe**): série única por modelo, MAC e patrimônio únicos por provedor (todos opcionais).
  Mudar situação ou local gera uma linha de movimentação (de/para situação e nó, quem, quando). `baixado` libera o nó.
- **Um ativo por nó** (**existe**): não dá para pôr dois equipamentos no mesmo nó (salvo o anterior baixado).
- **Credencial** (**existe**): sem `NET_SECRET_KEY` no `.env` o cadastro é bloqueado. **Perder a chave = perder as
  credenciais** (o banco só tem a cifra). A chave tem de ir no backup do `.env`, separada do dump do banco.
- **Telemetria** (**existe**): a coleta nasce desativada; mudar IP, perfil ou credencial desativa de novo.

## Migração do que já existe (por script, com simulação)

`scripts/net_equipment_migrate.php [--link-node=ID --model=NOME] [--apply]` lê fabricante/modelo de
`attrs_json`, casa (sem diferenciar maiúsculas) com o catálogo, cria o equipamento físico, liga o nó
(`equipment_id`) e **só no `--apply`** remove `fabricante`/`modelo` do `attrs_json`. Nós sem correspondência são
só relatados (o script não inventa modelo). `--link-node=42 --model=HA7004S` liga a OLT-001 ao HA7004S mesmo sem
texto no `attrs_json`.

`scripts/net_migrate_credentials.php --ref=NET_CRED_OLT_89_COMMUNITY --name="OLT 89" [--apply]` grava a credencial
cifrada e aponta os equipamentos para ela; mantém `credential_ref` como alternativa.

## Como o resto encaixa depois, sem mudar estas tabelas

- **Estoque** (**depois**): equipamento com `status = estoque` e `location_kind = estoque` (já permitido), sem nó.
  Instalar = editar situação/nó (gera movimentação). Falta só uma tela de entrada de lote.
- **ONU/ONT de cliente** (**depois**): são linhas em `net_equipment` com modelo do tipo `onu`/`ont`, `mac` = MAC da
  ONU, `location_kind = cliente` e `location_note` com o login. O vínculo cliente↔ONU continua em
  `client_network_bindings` (já existe) e a telemetria da ONU continua em `net_onus` (não é `net_devices`).
- **Rádios e switches** (**depois**): modelo com tipo `radio`/`switch`, nó do mapa do tipo correspondente,
  `net_devices` só se houver coleta. `credential_id` cobre SNMP v2c/v3, SSH, telnet e HTTP (campo `kind`).
- **Novas famílias de porta**: acrescentar a chave em `ports_json` (JSON), sem coluna nova.

## Limites conhecidos

- Só `snmp_v2c` é usado pelo coletor hoje; os outros tipos de credencial são aceitos e cifrados, mas nada os lê.
- `map_nodes.equipment_id` não tem chave estrangeira (a coluna já existia); a integridade é mantida pelo
  repositório. Excluir um equipamento físico não é oferecido (usa-se `baixado`).
- Nada foi migrado no banco real por esta rodada: ver o handoff (seção "Aplicação").
