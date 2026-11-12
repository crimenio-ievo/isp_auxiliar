# Contrato de dados de rede — ISP Map × telemetria de fibra

Status: PROPOSTA (auditoria somente leitura, 2026-11-12). Nenhum código, migration ou banco foi alterado.
Legenda: **[FATO]** conferido em código/migration/roadmap · **[HIPÓTESE]** inferência · **[não verificado]** não conferido.

Fontes lidas: branches `feature/mapa-fase1` (worktree `_isp_auxiliar_redesign_shell`, HEAD cf85e9a) e `feature/telemetria-fibra` (HEAD 42de72e, contém o mapa até 71cf274), `modules/isp_map/`, `/var/www/html/isp_map2` (docs). Não li `.env` nem consultei o banco: contagens de linhas e dados reais são **[não verificado]**.

## 1. O que existe e o que está em uso

### 1.1 Mapa (migrations 019–021, aplicadas no .89 segundo o roadmap)
- **[FATO]** `map_nodes` (id, provider_id, node_type, name, code, lat, lng, parent_node_id, status, lifecycle, attrs_json, equipment_id, notes, auditoria). `node_type` ∈ pop, tower, olt, switch, router, radio, server, other, ceo, cto, splitter, dio (`MapController::NODE_TYPES`).
- **[FATO]** `map_ports` (id, provider_id, node_id, port_num, label, port_role, media, is_external, status, attrs_json). Único: (node_id, port_role, port_num). Roles em uso: eth, sfp, pon, fo, spl_in, spl_out, dio. `status` livre/reservada/defeito.
- **[FATO]** `map_links` (id, provider_id, link_type, medium, from/to_node_id, from/to_port_id, status, lifecycle, label, length_m, fiber_count, attrs_json, path_json). Uma porta só pode estar em uma ligação.
- **[FATO]** Regras de posição: POP/torre e CEO/CTO têm lat/lng; equipamentos e splitter/DIO herdam do pai (`parent_node_id`). Capacidade da CTO = `attrs_json.capacity` (gera portas `fo`); PON tem `attrs.tx_dbm` e `attrs.max_clientes`; splitter `attrs.razao`; DIO `attrs.capacidade`.
- **[FATO]** `map_nodes.equipment_id` existe sem FK; fora do `MapRepository` não achei leitor/escritor em `backend/` ou `scripts/` da telemetria. **[não verificado]** se há uso em outras branches.
- **[FATO]** Em produção estável (`isp_auxiliar_stable_current` → release pre-maps-v0) não há rota/módulo de mapa; o mapa roda só em candidata/.89.
- **[FATO]** Planejado e NÃO feito: fase 1E "vínculo cliente/equipamento", 2D emendas (migration 022), 2H medições (023).

### 1.2 Telemetria (migrations 030–033, aplicadas no .89)
- **[FATO]** `net_devices` (provider_id, **map_node_id UNIQUE, FK RESTRICT → map_nodes**, profile_id, mgmt_host, credential_ref (só o NOME da variável), expected_identity_json, intervalos). Equipamento #1 = nó 42 (OLT-001, Hioso HA7004S).
- **[FATO]** `net_onus` (provider_id, device_id, slot, pon_index, onu_index, snmp_index, onu_identifier = MAC, first/last_seen, removed_at), `net_onu_state`, `net_onu_events`, `net_onu_power`, `net_onu_power_samples`, `net_alerts`, `net_interventions`, `net_device_poll_state`, `net_device_profiles`.
- **[FATO]** `net_onus` NÃO tem FK nem coluna para `map_ports`/`map_nodes`. A relação ONU→PON é só numérica (`pon_index`).
- **[FATO]** `net_alerts.scope_type='pon'` usa `scope_id` = índice da PON (`pon_index`), não `map_ports.id`.
- **[FATO]** `net_register_device.php` valida: nó é `olt` e nº de portas `pon` do mapa == `--expect-pons`. É a única ponte mapa↔telemetria no código.
- **[FATO]** Limiares de potência em `provider_settings` (`net.*`); padrões ópticos do mapa em `provider_settings` (`map.*`). Chaves separadas, sem colisão conhecida.

### 1.3 `client_network_bindings`
- **[FATO]** NÃO existe tabela, migration ou código com esse nome em nenhuma branch local. Só aparece como texto no roadmap ("vínculo em client_network_bindings") e em um comentário de `net_match_report.php`. É decisão de projeto, não implementação.
- **[FATO]** Casamento ONU↔cliente hoje: relatório somente leitura `net_match_report.php` (MAC da ONU × caller-id PPPoE do radacct do MkAuth). Na amostra de 19 ONUs: 11 candidatos únicos, 8 sem candidato; o MAC do PPPoE difere do da ONU por deslocamento no último octeto (**[HIPÓTESE]** fixo por OUI). Nada é gravado.

### 1.4 `isp_map2` (projeto separado)
- **[FATO]** Persistência em `localStorage`; modelo Site/Node/Port/Link/Measurement/Route; `database-roadmap.md` prevê tabelas `sites`, `site_nodes`, `site_ports`, `links`. Sem banco, sem integração com o ISP_Auxiliar. Pela regra do projeto, não presumir integração.

## 2. Contrato proposto

Princípio: um registro por coisa física. Mapa é dono de identidade, nome, posição, capacidade e topologia. Telemetria só referencia por id.

### 2.1 NODE (= `map_nodes`, canônico)
Mínimo, todos já existem: `id` (estável, nunca reutilizado, nunca muda), `provider_id`, `node_type`, `name` (atributo editável, nunca chave), `code` (opcional, rótulo), `lat/lng` (só site e caixa de campo), `parent_node_id`, `lifecycle` (projeto/ativo/desativado), `attrs_json` (capacidade, fabricante/modelo, razão do splitter).
Regras: site, CTO e equipamento (OLT) são o mesmo registro nos dois sistemas. Exclusão de nó referenciado por telemetria é bloqueada (já é RESTRICT em `net_devices`); desativar usa `lifecycle`, não DELETE.

### 2.2 PORT (= `map_ports`)
Mínimo: `id` (estável), `node_id`, `port_role`, `port_num`, `status` (livre/reservada/defeito), `is_external`, `attrs_json`. Identidade lógica = (node_id, port_role, port_num). Regra: apagar porta com vínculo ativo é recusado (hoje só impede se há ligação; precisa passar a considerar vínculo de ONU).

### 2.3 LINK (= `map_links`)
Mínimo: `id`, `link_type`, `medium`, `from_node_id/to_node_id`, `from_port_id/to_port_id`, `lifecycle`, `length_m` (informado), `fiber_count`, `path_json`. Telemetria nunca escreve aqui.

### 2.4 Como a ONU aponta para a porta da CTO
Cadeia proposta (tudo por id, nada de nome):

```
net_onus.id ──(vínculo, NOVO)──> map_ports.id  (CTO, port_role='fo')
net_onus.device_id ─> net_devices.map_node_id ─> map_nodes.id (OLT)
net_onus.pon_index ─(regra explícita, NOVA)──> map_ports.id (OLT, port_role='pon')
```

1. **Vínculo ONU→porta da CTO** (a ONU não é nó do mapa; é folha): nova tabela de telemetria `net_onu_attachments` (nome final a decidir; é o papel pretendido de `client_network_bindings`): `id`, `provider_id`, `onu_id`, `cto_port_id` (FK map_ports, ON DELETE RESTRICT), `client_ref` (id/login do MkAuth, texto opaco, sem copiar dados do cliente), `source` (manual | mac_match), `confirmed_by_user_id`, `valid_from`, `valid_to`. Único: uma porta de CTO com no máximo um vínculo aberto (`valid_to IS NULL`) e uma ONU com no máximo um. Histórico preservado ao trocar de porta.
2. **PON física**: hoje `pon_index` (nº da PON no SNMP) e `map_ports.port_num` (role pon) são ligados só por convenção. Proposta: coluna ou tabela de mapeamento explícito `net_device_pon_ports (device_id, slot, pon_index, map_port_id)`, preenchida e conferida no `net_register_device.php`. **[não verificado]** se `port_num` do mapa começa em 1 e coincide com o `pon_index` da OLT; conferir antes de assumir.
3. Ocupação de porta de CTO: o mapa passa a *ler* os vínculos para mostrar "ocupada por cliente", sem gravar status nem copiar dados. Não sobrescrever `map_ports.status` (que é decisão manual do operador).
4. Casamento por MAC só **sugere** (`source=mac_match`, precisa de confirmação humana), por causa do deslocamento de MAC e das 8 ONUs sem candidato.

### 2.5 Quem lê e escreve

| Dado | Escreve | Lê |
|---|---|---|
| NODE/PORT/LINK (nome, coordenadas, capacidade, topologia, ciclo de vida) | só mapa | telemetria (somente por id), mapa |
| `provider_settings map.*` | mapa | mapa |
| Perfil de coleta, `mgmt_host`, `credential_ref`, intervalos (`net_*`) | telemetria | telemetria |
| Leituras: status, potência, amostras, eventos, alertas, estado de coleta | só coletor | mapa e Home (leitura) |
| Vínculo ONU↔porta CTO↔cliente | operador pela UI, ou sugestão do match | mapa (ocupação), telemetria |
| Cliente (login, contrato) | MkAuth / ISP_Auxiliar | telemetria só guarda `client_ref` |

Proibido: telemetria escrever em `map_*`; mapa escrever em `net_*` de coleta; guardar nome, lat/lng, capacidade ou vendor duplicados na telemetria.

## 3. Conflitos com o que existe

1. **Duplicidade parcial de `provider_id`** em `net_devices` e `net_onus` (derivável de `map_nodes`). Nada garante que coincidam. Sugestão: manter (índice/escopo), mas validar igualdade no registro do equipamento.
2. **ONU sem ligação ao mapa** (só `pon_index` numérico) e alertas de PON por índice, não por porta. Quando a PON for renumerada/trocada no mapa, histórico perde o sentido.
3. **`equipment_id` morto em `map_nodes`**: seria a ponte "natural", mas o desenho escolhido é `net_devices.map_node_id`. Evitar usar os dois sentidos. Decidir: deixar a coluna sem uso (não remover sem evidência, regra do projeto).
4. **Vendor/modelo** vivem em `map_nodes.attrs_json` (livre) e em `net_device_profiles.vendor`: dois lugares, sem sincronismo. Perfil é verdade para coleta; attrs é rótulo do operador.
5. **Estado**: `map_nodes.status/lifecycle` (decisão do operador) × `oper_status` da telemetria (medido). Não fundir; a UI mostra os dois.
6. **Vocabulário**: no `isp_map2` "Node" = elemento interno e "Site" = local; no ISP_Auxiliar `map_nodes` é qualquer elemento (inclusive site). O contrato usa a definição do ISP_Auxiliar. Qualquer import do `isp_map2` exige tradução explícita (não presumir).
7. **`client_network_bindings` citado no roadmap mas inexistente**: o nome do roadmap e o desenho da seção 2.4 devem ser unificados num só registro de decisão.
8. **Intervalo de status**: o roadmap dizia "status 5 min" (corrigido para 600 s), mas a migration 033 fez `UPDATE net_devices SET status_interval_s=600 WHERE =300`; o intervalo efetivo é 600 s (explica o tick automático das 14:12, ~9 min após o forçado). Corrigir o texto do roadmap. Observação fora do escopo do contrato.
9. **[não verificado]** conteúdo real das tabelas `map_*` (contagens, CTOs existentes além do nó 42/43) e se `map_ports` de PON da OLT 42 têm `port_num` 1..4.

## 4. Ordem sugerida de migração (aditiva e reversível)

Cada passo: migration com `IF NOT EXISTS`, script de rollback em `scripts/rollback_NNN_*.sql`, teste em schema descartável antes de aplicar, nenhuma alteração destrutiva, aplicação só com autorização explícita.

0. **Sem schema**: conferir no .89 (somente leitura) `port_num` das PONs do nó 42 × `pon_index` da OLT; decidir nomes. Corrigir o texto do intervalo no roadmap.
1. **034 `net_device_pon_ports`** (nova tabela, FK RESTRICT para `map_ports`); preencher para o device 1 por script em modo simulação + `--apply`. Rollback: DROP TABLE.
2. **035 `net_onu_attachments`** (nova, FK `net_onus` e `map_ports`); vazia no início. Rollback: DROP TABLE.
3. **Código**: leitura do mapa mostrando ocupação por vínculo (somente SELECT); bloqueio de excluir porta/nó com vínculo. Reversível por reverter commit.
4. **Cadastro de vínculos**: primeiro manual na UI do mapa (fase 1E), depois sugestão por MAC com confirmação.
5. **Alertas por porta**: acrescentar `port_id` em `net_alerts` (coluna nova NULL, mantendo `scope_id` atual) e migrar a leitura gradualmente. Só depois decidir se descontinua `pon_index`.
6. **Opcional/futuro**: remover `map_nodes.equipment_id` somente com evidência de não uso em todas as branches.

Não fazer nesta rodada: tocar no MkAuth, importar dados do `isp_map2`, mexer em produção estável.
