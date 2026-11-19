# Handoff — rodada pré-produção (2026-11-19)

Branch `feature/pre-producao` (worktree `/var/www/html/_isp_auxiliar_pre_producao`), a partir do `main` 2152261 (já com mapa-traçado e vínculo N clientes;
a 048 está **aplicada** no banco do .89). **Sem push, sem merge (aguardando sua ordem).** Nada no .86, no MkAuth, na OLT (além da coleta normal do cron) nem no cron.
**FATO (coleta real do .89 durante o trabalho):** não parou. Ela tem timeouts esporádicos de base (11 em 18/11 e 11 em 19/11, desde antes desta rodada; o de 19:02 deixou
`consecutive_failures=1`, último sucesso 18:52, a OLT responde a ping). Nada do que escrevi roda no cron: o código novo só está nesta branch. Legenda: **FATO** = verificado; **HIPÓTESE**; **NÃO VERIFICADO**.

## Etapa A (fechada)
Merge ff-only do vínculo N (main = 2152261), 048 aplicada com `scripts/apply_migrations.php` (0 erros; `SHOW INDEX`: `cnb_onu_ativa` e `cnb_porta_ativa` não únicos,
`cnb_par_ativo` e `cnb_login_ativo` únicos; `modo_pppoe` ENUM nullable). Rotas no testes.ievo.com.br: `/login` 200, `/mapa` e `/rede-*` 302, `/.env` e `/.git/HEAD` 403.
Primeiro ciclo de telemetria depois do merge: 18:42:01, `ok`, `consecutive_failures=0`. Worktree `_isp_auxiliar_vinculo_n` removida (branch mantido).
A5 (pendência futura) está no ROADMAP: quando o último cliente sai da ONU por vínculo errado, oferecer também "tirar da porta".

## B1 — controle de coleta (sem mexer no cron)
### a) Chave por servidor: `NET_COLLECTOR_ENABLED` no `.env` (decisão e justificativa)
- **Por que `.env` e não `provider_settings`:** o banco da produção será copiado para o teste toda noite; uma chave no banco ligaria/desligaria a coleta no servidor errado
  depois de cada restore. O `.env` é do servidor (não entra em dump nem em deploy de código). Ver B5 (o dump também zera `net_devices.enabled`, cinto e suspensório).
- Valores: `1/true/on/yes/sim/ligado` liga; `0/false/off/no/nao/desligado` desliga. **Ausente ou inválido: liga fora da produção e DESLIGA em produção**
  (`APP_ENVIRONMENT=production`), mesma lógica das flags de módulo. Efeito: o .89 continua coletando sem ninguém editar o `.env`; o .86 só coleta depois de ligar de propósito.
- Desligada: `net_tick.php` (`--all` e `--device`) sai com exit 0 **antes de abrir o banco** e sem contatar equipamento, imprime/loga
  `collector_disabled` · "coleta desativada neste servidor" (no máximo 1 linha por hora no `storage/logs/net_tick-AAAA-MM-DD.log`, porque o cron roda a cada minuto);
  `net_collect.php` recusa (exit 4); "Testar conexão" devolve 409; `/rede-equipamentos` mostra a faixa "Coleta desativada neste servidor" (`coletor_servidor=false` na API).
- **FATO (testes):** `NetCollectorSwitchSmoke` (33): interpretação, log 1×/h, scripts reais com `.env` temporário e banco **inalcançável** (prova que não toca o banco),
  ligada = segue; `NetDeviceCadastroSmoke` (107): Testar conexão bloqueado e nenhum transporte aberto. **A coleta real do .89 não parou:** nada disso está no `main` ainda,
  e o `.env` do .89 não foi tocado.
- **Atenção (decisão a confirmar):** com a chave ausente, **o .89 continua ligado** e a produção, desligada. Para desligar o .89 no deploy do .86 é preciso `NET_COLLECTOR_ENABLED=0` no `.env` dele (passo 8 do runbook).

### b) Por equipamento (`coletar` sim/não e intervalo) — **já existia; verifiquei e testei**
- **FATO:** `/rede-equipamentos` já tem "Ativar/Desativar coleta" por equipamento (`net_devices.enabled`, com confirmação, validação de perfil/credencial e auditoria
  `net.device.enable/disable`) e os campos **intervalo de status** e **de potência** (`status_interval_s`, `power_interval_s`), gravados no banco. `net_tick.php --all` percorre só `enabled = 1`
  e o `NetTick` usa o intervalo de **cada** equipamento. Não reimplementei nada; extraí a consulta para `NetTickAll::enabledIds` (o script e o teste usam a mesma).
- **FATO (teste em schema descartável):** `--all` só vê os ativos; desativar/ativar muda a lista do próximo ciclo; intervalos 600/1800 s × 60/300 s dão vencimentos diferentes para a mesma idade;
  mudar o intervalo no banco vale no próximo ciclo; equipamento desativado nunca é coletado, nem por `--device`.

### c) Cron do .89: proposta, **não trocado**
Atual (`/etc/cron.d/isp-net-telemetria`):
```
* * * * * www-data php /var/www/html/_isp_auxiliar_unificacao/scripts/net_tick.php --device=1 >/dev/null 2>&1
```
Proposta (só troca `--device=1` por `--all`):
```
* * * * * www-data php /var/www/html/_isp_auxiliar_unificacao/scripts/net_tick.php --all >/dev/null 2>&1
```
**FATO:** hoje há 1 equipamento (id 1, ativo), então `--all` coleta o mesmo que `--device=1`; a diferença é que passa a obedecer o liga/desliga e o intervalo de cada equipamento novo.
**HIPÓTESE:** a troca é segura (um `--device` e um `--all` usam o mesmo `NetTick` e o mesmo lock por equipamento); **não testada contra a OLT real**. Rollback: voltar a linha.

## B2 — reserva técnica como ponto no mapa
- **Sem migration.** A estrutura atual já cabia: cada reserva de `path_json.reservas` ganha `pos: [lat, lng]` opcional (`MapPath::normalize` valida e arredonda a 6 casas). Sem `pos` = reserva antiga: **continua somando no total** e aparece no painel como "sem posição" com o botão **Posicionar**.
- Tela: ícone próprio (círculo âmbar com laço) sobre o cabo; **marcar** = botão "Marcar reserva técnica no cabo" (ou menu do botão direito "Marcar reserva técnica aqui") + clique no cabo + local e metros; **arrastar** (só com o cabo selecionado, para não mover sem querer) e a posição sempre **encaixa no traçado**; **clicar** no ícone mostra local e metros (e seleciona o cabo). Editar metros/local no modal preserva a posição.
- **FATO:** `MapTracadoSmoke` (+7), jsdom `reserva.js` (14) e navegador real (marcar, posicionar a antiga, arrastar, clicar, 2 reservas e total 20 + 12). Capturas em `/root/isp-auxiliar-backups/capturas-mapa-tracado/05b-…`, `05c-…`.
- **NÃO VERIFICADO:** reserva num cabo muito curto (ícones sobrepostos); a reserva não acompanha o cabo se um nó for arrastado (a posição fica onde estava, ficando fora da linha até ser reposicionada: **hipótese de limite aceitável**, decisão sua).

## B3 — "Seguir rua" só com a URL do OSRM
- **FATO: funciona com a URL e mais nada além do servidor OSRM.** Teste de ponta a ponta em navegador real (`tests/browser/seguir_rua.js`): OSRM **falso** (HTTP, formato do OSRM) + PHP real (`OsrmRouter` com curl) + URL gravada em `provider_settings`:
  CTO A → CTO B gerou 4 pontos de traçado (rua faz a volta: 476 m contra 208 m em linha reta), "seguir rua ao clicar" liga e consulta do último ponto ao clique, OSRM fora do ar (503) cai no manual sem quebrar a tela, sem URL a tela diz "não configurado".
- **O que faltava além do servidor (corrigido):** (1) a URL só podia ser gravada por SQL à mão: criei `scripts/map_config.php` (`--show`; simulação por padrão; `--apply` grava; também a folga padrão); (2) `OsrmRouter` agora usa **curl** (timeouts, só http/https) com *fallback* para `file_get_contents`; (3) rota praticamente reta até o destino agora explica "não há pontos a acrescentar" em vez de parecer falha.
- **FATO (.89):** o Apache do .89 tem `curl` e `allow_url_fopen=On`. **NÃO VERIFICADO:** o mesmo no .86; um OSRM real (nenhum instalado; guia em `docs/osrm-seguir-rua.md`); a qualidade de rota real em MG.

## B4 — `docs/deploy-producao.md` (runbook do .86)
Escrito (parte A) e o guia geral antigo mantido (parte B). Cobre backup/rollback, migrations pendentes (019–021, 030–034, 045–048; como conferir o que falta), flags, `NET_SECRET_KEY` própria e backup do `.env`,
credencial da OLT pela tela, coleta ligada no .86 e desligada no .89 **na mesma janela** (com a ordem), checklist pós-deploy. **Nada foi executado no .86.**
**NÃO VERIFICADO sobre o .86:** estado de `schema_migrations`, nome do banco, caminhos das releases, `isp-hardening.conf`, extensões PHP.

### Alterações pendentes em `scripts/releases` (em `/var/www/html/isp_auxiliar`, branch `release/pre-maps-v0`) — não commitei nem descartei
**FATO (diff lido):** são só 2 arquivos modificados (e 1 relatório `docs/RELATORIO_HANDOFF_CODEX_CLAUDE_ISP_AUXILIAR_2026-09-18.md` não rastreado):
1. `common.sh`: `release_validate_manifest_channel` passa a aceitar **vários** canais (`shift; for channel in "$@"`); chamada com 1 canal continua igual.
2. `promote_beta_to_stable.sh`: valida `beta` **ou** `candidate` e, ao promover, troca `channel=(beta|candidate)` por `stable` no manifesto. O comentário diz: promoção direta de release candidata validada por processo equivalente.
**Afetam a promoção?** **Não, se a promoção usar o fluxo do `main`.** No `main` o `promote_beta_to_stable.sh` foi **removido**; o `promote_release.sh` já exige `channel=candidate` (função de canal único, sem mudança) e grava `channel=production`. A mudança só vale para quem rodar o script **antigo** a partir do checkout `release/pre-maps-v0`
(a produção atual R5 foi promovida por esse caminho). **HIPÓTESE:** a produção atual foi promovida com estes ajustes locais; se o Codex Lab for repetir esse caminho antigo, os ajustes são necessários e **precisam ser commitados** em algum branch (hoje existem só no disco). **NÃO VERIFICADO:** qual dos dois caminhos o .86 usará nesta promoção. Recomendação: usar o fluxo do `main` (`deploy_candidate.sh` + `promote_release.sh`) e tratar o diff como histórico.

## B5 — anexo: o que o dump noturno da PRODUÇÃO → TESTE deve excluir ou zerar
Conferido no schema real do .89 (`isp_auxiliar`, 048 aplicada, 42 tabelas); **NÃO VERIFICADO no .86** (terá as mesmas tabelas depois das migrations). Nomes de tabela/coluna reais; valores **não** foram lidos.
**Regra 1:** o `.env` do teste **nunca** é sobrescrito nem copiado (segredos de SMTP/Evotrix/MkAuth do `.env`, `NET_SECRET_KEY`, `NET_CRED_*`, `NET_COLLECTOR_ENABLED`). **Regra 2:** o `storage/` (uploads de clientes, **sessões em arquivo**, logs) e `config.json`/`tmp/` **não vão no dump**: sessão de produção copiada valeria no teste.

| Grupo | Tabela.coluna (real) | Ação no teste | Por quê |
|---|---|---|---|
| Conexão com o MkAuth | `provider_settings`: `mkauth_api_token` (secreto), `mkauth_client_secret` (secreto), `mkauth_db_password` (secreto), `mkauth_db_host/name/user/port/charset/hash_algos`, `mkauth_base_url`, `mkauth_client_id` | **Não restaurar**; manter as do teste (ou restaurar e **reaplicar** as do teste depois) | apontariam o teste para o MkAuth de **produção** |
| Quem é gestor/admin | `provider_settings`: `admin_access_logins`, `contract_access_logins`, `financial_access_logins`, `settings_access_logins`, `mkauth_manager_login(s)`, `mkauth_permission_map`, `modules_enabled` | manter as do teste | permissões de produção valeriam no teste |
| Flags do teste | `provider_settings`: `feature.map`, `feature.net` (quando existirem) | manter as do teste (ligadas) | o dump as desligaria |
| Preferência por usuário | `provider_settings`: `release_channel_user_<hash>` | pode ir | sem segredo |
| Credenciais de integração | `integration_credentials`: `secret_ciphertext`, `secret_nonce`, `secret_key_version`, `secret_last_four`, `username` | **TRUNCATE** | cifra de produção (hoje: `openai_api_key`) |
| Credenciais de rede | `net_credentials`: `secret_cipher`, `nonce`, `key_version` | **TRUNCATE** | cifradas com a `NET_SECRET_KEY` da produção; no teste não abrem |
| Coleta da OLT | `net_devices`: `enabled`, `credential_id`, `credential_ref`, `mgmt_host` | `UPDATE net_devices SET enabled=0, credential_id=NULL` **sempre** | senão o teste (padrão ligado fora de produção) coletaria da OLT de produção |
| Coleta legada | `telemetry_equipments`: `credential_ref`, `credential_id`, `source_url`, `last_error_message` | zerar credenciais/URL (hoje 0 linhas) | |
| IA | `ai_provider_configs` (`enabled`, `credential_id`, `fake_mode`…), `ai_conversation_authorizations` (`authorization_hash`), `ai_assistant_messages`/`_proposals`/`_sessions`, `ai_audit_logs`, `ai_research_runs` | configs: `enabled=0, credential_id=NULL`; demais **TRUNCATE** | conversas podem ter dados de clientes; evita chamada real à API |
| Usuários locais | `app_users`: `password_hash`, `email` | zerar `password_hash` e inativar, ou **não restaurar**; usuário do teste por decisão do dono | senha de produção não pode abrir o teste |
| Links/tokens de cliente | `contract_acceptances`: `token_hash`, `token_expires_at`, `whatsapp_message_id`, `telefone_enviado`; `installation_checkpoints`: `token`, `payload_json`; `client_registrations`: `radius_token`, `evidence_url` | `token_hash=NULL, token_expires_at=NULL`; `token=NULL`; `radius_token=NULL` (ou excluir as linhas pendentes: `contract_acceptances.status IN ('criado','assinatura_pendente')`) | link público de aceite/instalação de produção não pode valer no teste |
| "Filas" de envio | **FATO: não existe tabela de fila/jobs/outbox.** Equivalentes: `notification_logs` (`recipient`, `message`, `provider_response`, `status` simulado/enviado/erro), `contract_acceptances` (status `assinatura_pendente`), `financial_tasks` (`aberto`/`em_andamento`) | `notification_logs` **TRUNCATE**; `contract_acceptances` pendentes e `financial_tasks` abertas: excluir ou marcar `cancelado` | nada pendente pode ser reenviado a partir do teste |
| Canais de envio | `notification_channel_registry` (`email`/smtp, `whatsapp`/evotrix, `push`, `sms`; `available`) | manter; no teste os envios ficam em dry-run pelo `.env` | |
| Dados pessoais (LGPD) | `client_contracts.telefone_cliente`, `client_registrations.cpf_cnpj`/`client_name`, `contract_acceptances.telefone_enviado`/`ip_address`/`user_agent`, `audit_logs.ip_address`/`user_agent` (+ contexto), `client_network_bindings.cliente_nome`, `client_documents`, `evidence_files` (arquivos não vão) | **decisão do dono**: anonimizar ou excluir | o teste não precisa de CPF/telefone reais |
| Telemetria | `net_onus`, `net_onu_*`, `net_alerts`, `net_onu_outages`, `net_device_poll_state` | podem ir (sem segredo; MACs de equipamentos); `net_device_poll_state` reinicia ao desativar | |

**Como gerar (recomendado):** excluir na **origem** com `mysqldump --ignore-table` o que é `TRUNCATE` acima (`integration_credentials`, `net_credentials`, `ai_*`, `notification_logs`) e **não incluir** `provider_settings`; aplicar o SQL de sanitização logo após o restore e **conferir**:
`SELECT COUNT(*) FROM net_devices WHERE enabled=1` = 0, nenhum `token_hash`/`token`/`radius_token` não nulo, `provider_settings` sem `mkauth_*` de produção. O arquivo do dump tem segredos mesmo assim: modo 600, fora de pasta pública, apagar depois do restore.
**Ordem no teste:** (1) guardar as chaves próprias do teste; (2) restaurar; (3) sanitizar; (4) reaplicar chaves próprias/flags; (5) `php scripts/apply_migrations.php`; (6) conferir `.env` intacto e `NET_COLLECTOR_ENABLED=0` no teste enquanto a produção coleta.

## Testes (todos verdes)
`NetCollectorSwitchSmoke` (33), `NetDeviceCadastroSmoke` (107), `NetTickAllSmoke` (19), `MapTracadoSmoke`, `MapCableImportSmoke`, `MapMapeamentoFlowSmoke`, `MapRouteSmoke`, `FeatureFlagsSmoke`, `PreMapsRoutingRegression`;
jsdom do mapa (inclui `reserva`, `rota`, `tracado`, `importar`) e `tests/net/run_all.sh`; navegador real: `tests/browser/mapa_tracado.js` e `seguir_rua.js` (schema descartável, sessão de harness e `.env` temporário removidos). Não rodei `tests/map/suite*.php` (gravam "ZZ TESTE" no banco real).

## PARA O USUÁRIO
1. Autorizar o merge ff-only de `feature/pre-producao` no `main`.
2. Decidir: padrão da chave por servidor (ausente = liga fora da produção, desliga em produção) e a troca do cron para `--all` (linha acima).
3. Repassar `docs/deploy-producao.md` ao Codex Lab; decidir se o diff antigo de `scripts/releases` vira commit (só se o caminho antigo for usado).
4. Para o dump noturno: decidir a política de dados pessoais (anonimizar ou excluir) e implementar o SQL de sanitização do anexo B5.
