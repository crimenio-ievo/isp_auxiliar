# Deploy de produção

> Duas partes: **A) runbook do pacote Mapa + Rede no .86** (para o Codex Lab executar) e **B) referência geral** (instalação nova e atualização, a de sempre).
> Escrito em 2026-11-19 a partir do `main` do .89. **Nada aqui foi executado no .86.** Quem executa confirma cada passo com o dono do sistema:
> promover código, trocar symlink, aplicar migration e ligar coleta exigem confirmação explícita no momento de executar.

# A) Runbook: Mapa + Rede no .86

## Versão de referência (2026-11-19)
- **Código:** `main` do .89 no commit **`c5d9c5d`** (`feat(rede): Definir CTO direto da ONU…`). Commits de documentação posteriores a ele não alteram código; ao montar a release use o hash completo do código que foi homologado (`git rev-parse c5d9c5d`).
- **Migrations em `database/migrations/` (24 arquivos):** `001`, `002`, `003`, `004`, `005_contract_acceptances_remote_signature`, `005_financial_tasks_mkauth_ticket_status`, `006`, `007`, `008`, `016`, `017`, `018`, `019`, `020`, `021`, `030`, `031`, `032`, `033`, `034`, `045`, `046`, `047`, `048`. Há lacunas de numeração (009–015, 022–029, 035–044) que **não** são arquivos faltando.
- **Aplicadas no banco do .89 (teste):** todas, inclusive a 048 (18:35 de 19/11). **Pendentes no .86:** conferir pelo passo 3 (candidatas: 019–021, 030–034, 045–048).
- **Nenhuma migration nova depois da 048:** as rodadas de mapa-traçado, pré-produção e ficha da ONU não criaram migration (traçado e reservas usam `map_links.path_json`, que existe desde a 019).

## 0. O que entra e o que NÃO entra
- **Entra:** módulo `/mapa` (traçado, reservas, seguir rua, importar KML), `/rede-*` (fibra, CTOs, vínculos N clientes por ONU, equipamentos, credenciais, modelos, inventário) e a telemetria da OLT.
- **Não muda:** MkAuth (só leitura), cadastro/contratos, o `.env` de integrações (SMTP, Evotrix), a OLT em si.
- **Fato (docs do projeto):** `feature.map` e `feature.net` nascem **desligadas em produção** (`APP_ENVIRONMENT=production`); a coleta (`NET_COLLECTOR_ENABLED`) também nasce **desligada em produção**. Sem passo explícito, nada do pacote aparece nem coleta.
- **Não verificado** (o .86 não foi tocado): estado atual de `schema_migrations`, nome do banco, caminho exato das releases, se o Apache de lá tem o `isp-hardening.conf`, versão do PHP e extensões (`curl`, `sodium`). Cada item abaixo diz como conferir.

## 1. Pré-requisitos (conferir antes, sem alterar nada)
1. PHP 8.2+ com `pdo_mysql`, `sodium` (credenciais cifradas), `curl` (Seguir rua) e `zip` (KMZ): `php -m | grep -Ei "pdo_mysql|sodium|curl|zip"`.
2. Banco acessível pelo `.env` de produção e espaço para um dump: `df -h` na pasta de backup.
3. Quem será o gestor que fará o cadastro de rede (gestor/admin no ISP_Auxiliar) e qual é o `provider_id` do .86: `SELECT id, name, slug FROM providers;`.
4. A release a promover já foi **homologada no .89** (candidata) e `git log` mostra o commit esperado; o mesmo `main` do .89 contém as migrations 019–048.

## 2. Backup (obrigatório) e rollback
Guarde tudo **fora** da pasta da release e do repositório.
```
TS=$(date +%Y%m%d-%H%M%S); B=/root/isp-auxiliar-backups/pre-deploy-$TS; mkdir -p $B && chmod 700 $B
# banco local do ISP_Auxiliar (nome em DB_DATABASE do .env de produção)
mysqldump --single-transaction --routines --triggers NOME_DO_BANCO > $B/banco.sql
# código: a release atual é imutável; registre qual é e copie o .env SEPARADO do dump
readlink -f /var/www/html/isp_auxiliar_stable_current > $B/release-atual.txt
cp -p CAMINHO/.env $B/env.bak && chmod 600 $B/env.bak
# dados operacionais (fotos, assinaturas, contratos)
tar -czf $B/storage.tgz -C CAMINHO_DOS_DADOS_COMPARTILHADOS storage
```
- **Rollback do código:** `scripts/releases/rollback_stable.sh --release-id ID_ANTERIOR --commit HASH_ANTERIOR --health-url https://HOST/ [--dry-run]` (volta o symlink; ver `docs/fluxo-release-candidata-producao.md`).
- **Rollback do banco:** as migrations são aditivas (o código antigo ignora as tabelas novas). Só reverta se for necessário, **na ordem inversa**, com `scripts/rollback_048…`, `047`, `046`, `045`, `034`…`030`, `021`, `020`, `019` (cada um apaga as tabelas/colunas da etapa; o 048 recusa-se sozinho se houver 2+ clientes na mesma ONU/porta). Antes de qualquer rollback, novo dump.
- **A chave `NET_SECRET_KEY` (passo 7) NÃO está no dump do banco.** Perder o `.env` = perder as credenciais cifradas. Por isso o `env.bak` acima.

## 3. Migrations pendentes no .86
**Candidatas (precisa conferir):** 019, 020, 021 (mapa); 030, 031, 032, 033, 034 (telemetria); 045, 046, 047, 048 (vínculos, credenciais, catálogo, N clientes). As 001–008 e 016–018 são as anteriores ao pacote e devem já estar aplicadas.
Conferir o que falta, sem alterar:
```
mysql NOME_DO_BANCO -e "SELECT filename FROM schema_migrations ORDER BY filename"
ls database/migrations   # na release nova: tudo o que não estiver no SELECT acima está pendente
```
O aplicador oficial só aplica o que falta, registra checksum e recusa divergência: `php scripts/apply_migrations.php` (rodar **na release nova**, com o `.env` de produção). Resultado esperado: `erros: 0`.
- **Ordem:** migrations ANTES de trocar o symlink (o `promote_release.sh` recusa se o schema divergir do homologado).
- **Compatibilidade (fato de rodadas anteriores):** o código antigo da produção (R5) não usa as tabelas novas; 046 deixa `credential_ref` aceitar NULL e o cron antigo continuava funcionando no .89. **Não verificado no .86.**
- Depois: `SHOW INDEX FROM client_network_bindings` deve mostrar `cnb_onu_ativa` e `cnb_porta_ativa` **não únicos** e `cnb_par_ativo` único; `SHOW COLUMNS FROM client_network_bindings LIKE 'modo_pppoe'` = ENUM nullable.

## 4. Como o código chega ao .86 (decisão antes de promover)
**Fato do repositório:** `deploy_candidate.sh --source CHECKOUT --commit HASH` monta a release com `git archive` de um **checkout local da máquina onde roda**, e o commit precisa existir nesse checkout; `promote_release.sh` copia a candidata na mesma máquina. O guia antigo (`scripts/deploy_update.sh`) faz `git pull origin main`. O `origin` é `git@github.com:crimenio-ievo/isp_auxiliar.git` e o `main` do .89 está **156 commits à frente** do `origin/main` (nada foi enviado). Caminhos possíveis (escolha do dono; **não verificado qual o .86 tem**):
1. **Via GitHub (exige `git push` do .89):** push do `main`, depois `git fetch origin` no checkout do .86 e `deploy_candidate.sh` com o hash. É o caminho do `deploy_update.sh`.
2. **Pacote Git, sem push:** no .89 `git bundle create /root/isp-main.bundle 95ccbe0..c5d9c5d` (ou o `main` inteiro), copiar para o .86 e `git fetch /caminho/isp-main.bundle main:refs/remotes/bundle/main` no checkout de lá; depois `deploy_candidate.sh`. Não exige push.
3. **Pull direto do .89 por SSH**, se o .86 alcançar o .89: `git fetch ssh://USUARIO@.89/var/www/html/_isp_auxiliar_unificacao main`. Não exige push; **não verificado** se há rota e chave entre os dois.
Em qualquer caminho, confira que o hash recebido é o homologado (`git rev-parse`) antes de montar a candidata.

## 5. Promover o código
Seguir `docs/fluxo-release-candidata-producao.md` (candidata → `promote_release.sh --dry-run` → promoção real → health HTTP). **Só com a confirmação do dono.** Conferir que o `.env` da release de produção tem `APP_ENVIRONMENT=production` (o banner "AMBIENTE DE TESTES" some).
> Aviso sobre scripts locais não commitados: ver a seção "Alterações pendentes em scripts/releases" do `docs/handoff-pre-producao.md`.

## 6. Ligar as flags `feature.map` e `feature.net`
Por provedor, no banco (o `.env` `FEATURE_MAP/FEATURE_NET` também vale, mas a configuração por banco tem prioridade). Troque `<ID>` pelo id do passo 1.3:
```
INSERT INTO provider_settings (provider_id, setting_key, setting_value, is_secret, updated_at) VALUES (<ID>, 'feature.map', '1', 0, NOW()) ON DUPLICATE KEY UPDATE setting_value = '1', updated_at = NOW();
INSERT INTO provider_settings (provider_id, setting_key, setting_value, is_secret, updated_at) VALUES (<ID>, 'feature.net', '1', 0, NOW()) ON DUPLICATE KEY UPDATE setting_value = '1', updated_at = NOW();
```
Desfazer: o mesmo comando com `'0'`. Desligado: menu oculto, páginas 404, APIs 403.

## 7. Chave de cifragem própria do .86 e backup do `.env`
1. `php scripts/net_secret_key.php` na release de produção (gera `NET_SECRET_KEY` no `.env` **só se não existir**; não imprime a chave; não troca uma existente).
2. **Imediatamente** `cp -p .env $B/env-com-chave.bak && chmod 600` e guardar **separado** do dump do banco.
3. **Não** copie a `NET_SECRET_KEY` do .89: cada servidor tem a sua. Credenciais cifradas de um servidor não abrem no outro.

## 8. Credencial da OLT pela tela
1. Entrar como gestor/admin → `/rede-credenciais` → cadastrar a credencial SNMP da OLT (nome ex.: "OLT 86"). A tela mostra "definida em DATA por USUÁRIO"; o segredo nunca volta na resposta.
2. `/rede-equipamentos` → cadastrar/editar a OLT (modelo do catálogo, IP de gerência, perfil Hioso EPON), escolher a credencial, intervalos de status e potência. A coleta nasce **desativada** por equipamento.
3. "Testar conexão" **só funciona com a coleta ligada neste servidor** (passo 9): é um contato real com a OLT.
4. Mapa/vínculos feitos no .89: **não vêm sozinhos**. Se for reaproveitar o mapeamento do .89, copie as tabelas (`scripts/backup_map.sh` gera o dump de `map_*` e `client_network_binding*`) **não recrie**, e confira antes que os ids de provedor e de usuário do .86 batem com os do .89. Se não bater, remapeie no .86 pela tela.

## 9. Coleta: ligada no .86 e desligada no .89, no MESMO passo (nunca as duas)
A OLT tem limite de pedidos SNMP; duas coletas dobram a carga e embaralham o estado. Sequência (uma pessoa, uma janela):
1. **.89 primeiro (desligar):** garantir que o `main` com `NET_COLLECTOR_ENABLED` já está no .89; acrescentar `NET_COLLECTOR_ENABLED=0` ao `.env` do .89 (o padrão ali é LIGADO). Conferir no `storage/logs/net_tick-AAAA-MM-DD.log` do .89 a linha `collector_disabled` ("coleta desativada neste servidor") no próximo minuto e que nenhum lock/ciclo está em andamento. *Alternativa se a chave ainda não existir lá:* apagar `/etc/cron.d/isp-net-telemetria` do .89 (o arquivo diz "Desativar: apagar este arquivo").
2. **.86 depois (ligar):** `NET_COLLECTOR_ENABLED=1` no `.env` de produção. Cron do .86 (usuário do Apache, 1 min), apontando para a **release atual** (symlink, para sobreviver a trocas de release):
```
* * * * * www-data php /var/www/html/isp_auxiliar_stable_current/scripts/net_tick.php --all >/dev/null 2>&1
```
3. Equipamento novo falha com `identity_mismatch` até aprovar a identidade de referência (uma vez, com a janela supervisionada): `php scripts/net_collect.php --device=ID --kind=status --approve-identity`. Ative a coleta do equipamento pela tela (`/rede-equipamentos` → "Ativar coleta").
4. **Reverter (se algo estranho):** `NET_COLLECTOR_ENABLED=0` no .86 (nada contata a OLT; nem banco) e só então ligar o .89 de novo.

## 10. Checklist pós-deploy (anotar horário e resultado de cada item)
Substitua `HOST` pelo domínio de produção. Sem cookie de sessão:
| Verificação | Esperado |
|---|---|
| `curl -sk -o /dev/null -w "%{http_code}" https://HOST/login` | 200 |
| `/mapa`, `/rede-fibra`, `/rede-ctos`, `/rede-vinculos`, `/rede-equipamentos` | 302 (com as flags ligadas; 404/403 = flag desligada) |
| `/.env`, `/.git/HEAD`, `/storage/` | 403 |
| `/api/health`, `/api/release` | commit e canal esperados (`channel=production`) |
| Banner "AMBIENTE DE TESTES" | ausente |
| Com sessão de gestor: `/mapa` carrega, `/rede-credenciais` mostra a chave como disponível | ok |
| Primeiro ciclo de telemetria: `SELECT last_result, consecutive_failures FROM net_device_poll_state;` | `ok` e `0` (pode levar até o intervalo configurado, ex. 10 min) |
| `storage/logs/net_tick-*.log` | linhas `result: ok`, sem `error` |
| .89 no mesmo horário | linhas `collector_disabled`, `net_device_poll_state` parado |
**Se o `.env`/`.git` não der 403:** o Apache do .86 precisa do `isp-hardening.conf` (ver `docs/handoff-seguranca-apache.md`; não verificado lá).

## 11. O que NÃO fazer
Não copiar `NET_SECRET_KEY` entre servidores; não ligar a coleta nos dois; não aplicar rollback de migration sem dump novo; não editar `.env` sem backup; não promover sem confirmação; não restaurar dump da produção no teste sem seguir o anexo de dados sensíveis do `docs/handoff-pre-producao.md`.

---

# B) Referência geral (instalação nova e atualização)

## Objetivo

Este guia cobre instalação nova, atualização segura e diagnóstico básico do `ISP_AUXILIAR`.

## Instalação nova

1. Clonar o repositório.
2. Copiar `.env.example` para `.env` e revisar `APP_URL`, `APP_PROVIDER_KEY`, `DB_*`, `MKAUTH_*`, `SMTP_*` e `EVOTRIX_*`.
3. Garantir permissões de escrita em `storage/`, `logs/` e `backups/`.
4. Executar as migrations oficiais:
   - `php scripts/apply_migrations.php`
5. Garantir um administrador local ou remoto:
   - `php scripts/ensure_admin.php crimenio`
6. Validar o ambiente:
   - `php scripts/check_install.php crimenio`

## Atualização segura

1. Fazer backup do banco e do storage operacional.
2. Aplicar a atualização do código:
   - `git pull origin main`
3. Rodar migrations pendentes:
   - `php scripts/apply_migrations.php`
4. Garantir acesso administrativo:
   - `php scripts/ensure_admin.php crimenio`
5. Conferir o ambiente:
   - `php scripts/check_install.php crimenio`
6. Recarregar o Apache:
   - `bash scripts/deploy_update.sh crimenio`

## Produção real

Antes de liberar o piloto real:

- `APP_URL` deve apontar para o domínio público final.
- `SMTP` deve estar autenticado e com remetente válido.
- `Evotrix` deve estar configurado e com teste/produção conforme o cenário.
- `MkAuth` deve estar configurado com base URL, API e banco remoto.
- `MKAUTH_TICKET_MESSAGE_FALLBACK` deve ficar ativo se o MkAuth não gravar a mensagem inicial.
- As permissões locais e a detecção automática de admin pelo MkAuth devem estar validadas.
- A tela de usuários deve mostrar a origem da permissão: `Local` ou `Admin detectado pelo MkAuth`.

## Scripts oficiais

- `php scripts/apply_migrations.php`
- `php scripts/ensure_admin.php crimenio`
- `php scripts/check_install.php crimenio`
- `bash scripts/deploy_update.sh crimenio`

## O que cada script faz

### `scripts/apply_migrations.php`

- Cria `schema_migrations` se precisar.
- Lê `database/migrations/*.sql`.
- Aplica somente migrations pendentes.
- Registra `filename`, `checksum` e `applied_at`.
- Ignora migrations já aplicadas.

### `scripts/ensure_admin.php`

- Normaliza o login informado.
- Cria o provider atual se ele ainda não existir.
- Mescla o login nas permissões simples do provider.
- Mostra o resultado de `accessForLogin(login)`.

### `scripts/check_install.php`

- Mostra diagnóstico do `APP_ENV`, `APP_URL`, provider, banco, permissões e integrações.
- Indica se o ambiente está em localhost ou IP.
- Mostra migrations aplicadas e pendentes.

### `scripts/deploy_update.sh`

- Faz `git pull origin main`.
- Executa migrations.
- Garante admin.
- Ajusta permissões de diretório.
- Roda o diagnóstico final.
- Recarrega o Apache.

## Observação

O fluxo operacional continua dependente de configurações válidas de banco, `APP_URL`, `SMTP`, `Evotrix` e `MkAuth`. Este guia não altera o banco do MkAuth nem cria cobrança.

**Nunca executar `scripts/reset_test_operational_data.php` em produção.**

## Banner "AMBIENTE DE TESTES — NÃO É PRODUÇÃO" (`APP_ENVIRONMENT`)

O banner fixo no topo aparece **sempre**, exceto quando `APP_ENVIRONMENT=production` (valor exato, sem
diferenciar maiúsculas/espaços). Variável ausente, vazia ou com outro valor (`test`, `staging`, erro de digitação)
**mostra** o banner — a falha é segura. Não confundir com `APP_ENV`, que continua controlando as travas de dry-run.

Onde definir o valor (a leitura é `Env::get`, com precedência: variável do processo/serviço > `.env`):

- **Produção (.86):** no arquivo de ambiente que alimenta as releases (o `--stable-env-file` /
  `source env` usado por `release_prepare_env`, que é copiado para o `.env` de cada release) coloque
  `APP_ENVIRONMENT=production`. Como esse arquivo é copiado a cada release, defina uma vez ali e ele acompanha as
  próximas. Confirme depois com `php scripts/check_install.php` (linha `APP_ENVIRONMENT … banner`).
- **Testes (.89):** deixe ausente ou use `APP_ENVIRONMENT=test`.
- Atenção a variável injetada pelo Apache/PHP-FPM (`SetEnv`, `env[...]` do pool): ela **sobrepõe** o `.env`.
- Não há arquivo persistido (`storage/contracts/config.json`) envolvido: esse arquivo não guarda variáveis de ambiente.
