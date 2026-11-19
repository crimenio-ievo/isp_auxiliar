# Handoff — rodada geral (2026-11-19, 15:44–15:57 UTC)

Servidor .89 (TESTE). Branch `feature/rodada-geral` a partir de `main` 04213d5, worktree temporária
`/var/www/html/_isp_auxiliar_rodada`. A rodada começou às 15:44 UTC, com só 13 minutos até o limite (15:57).
Por isso só os itens 1 e 2 couberam. **Nada foi feito na OLT, no MkAuth, no .86 ou no cron de telemetria.**

| Item | Situação | Commit |
|---|---|---|
| 1. Backup do mapeamento | feito e verificado | `feat(backup): backup diário das tabelas do mapa e vínculos` |
| 2. Proteção do tests/map | feito, com teste | `test(map): recusar tests/map em banco com dados reais` |
| 3. Decisões do usuário (a, b, c) | **não iniciado**: não cabia no horário | — |
| 4. Fluxo de mapeamento real + guia | **não iniciado** | — |
| 5. Equipamentos e credenciais (CP-0 a CP-3) | **não iniciado** | — |

## 1. Backup do mapeamento

- Script: `scripts/backup_map.sh`. Descobre as tabelas pelo `information_schema` (padrões `map\_%` e
  `client\_network\_binding%`). **Fato:** hoje são `client_network_binding_rejections`,
  `client_network_bindings`, `map_links`, `map_nodes` e `map_ports`.
- Usa `mysqldump --single-transaction` pelo socket do root (sem senha em linha de comando). Grava em
  `/root/isp-auxiliar-backups/mapa/mapa-AAAAMMDD-HHMM.sql.gz` com permissão 600 e guarda os 30 mais recentes
  (`MAP_BACKUP_KEEP`).
- O dump é feito num arquivo `.parcial`, conferido com `gzip -t` e pelo rodapé `-- Dump completed`, e só
  então renomeado.
- Cron: `/etc/cron.d/isp-map-backup`, diário às 02:17, como root. Aponta para
  `/var/www/html/_isp_auxiliar_unificacao/scripts/backup_map.sh` e só roda se o script existir lá (ou seja,
  após o merge). Log em `/var/log/isp-map-backup.log`.
- **Verificado:** uma execução manual às 15:45 gerou `mapa-20261119-1545.sql.gz`: `gzip -t` ok,
  5 `CREATE TABLE` e 3 `INSERT` (vínculos ainda vazios). Esse arquivo foi restaurado num schema descartável
  (`isp_auxiliar_mapbackup_sim`, já apagado): as contagens de `map_nodes` e `map_ports` bateram com o banco
  real.

### Como restaurar só essas tabelas

O dump traz `DROP TABLE IF EXISTS` + `CREATE TABLE` + dados **apenas** dessas 5 tabelas. O resto do banco
não é tocado, mas essas 5 são substituídas inteiras.

1. Faça antes um backup novo do estado atual: `scripts/backup_map.sh`.
2. Restaure primeiro num schema de conferência:
   `mysql -e "CREATE DATABASE isp_auxiliar_mapa_conf"; zcat ARQUIVO.sql.gz | mysql isp_auxiliar_mapa_conf`.
   Confira as contagens.
3. Para valer: `zcat ARQUIVO.sql.gz | mysql isp_auxiliar`. O dump desliga `FOREIGN_KEY_CHECKS` durante a
   carga. Os nós referenciam `providers` e `users`, que precisam existir com os mesmos ids.
4. Apague o schema de conferência: `mysql -e "DROP DATABASE isp_auxiliar_mapa_conf"`.

Para o deploy no .86, o mesmo arquivo serve para **copiar** o mapeamento feito no .89. Os ids de provedor e
de usuários precisam corresponder, o que é **não verificado** para o .86.

## 2. Proteção do tests/map

- `tests/map/run_all.sh` agora chama `tests/map/guard_real_db.php` antes de tudo. Se o banco configurado
  tiver **mais de 10 nós** cujo nome não começa com `ZZ TESTE`, a suíte é recusada (saída 2), a menos que
  `MAP_TESTS_ALLOW_REAL_DB=1`. A suíte em si não foi reescrita.
- **Fato:** o banco real tem hoje 4 nós reais, então a suíte ainda roda. Quando o mapeamento real passar
  de 10 nós, ela vai recusar. Aí, ou se aponta `DB_DATABASE` para um banco de teste (variável de processo
  tem precedência sobre o `.env`), ou se usa a liberação explícita.
- Teste: `tests/Feature/MapTestsGuardSmoke.php` (schema descartável: 10 reais + 5 ZZ permite; 11 recusa;
  `=0` não libera; `=1` libera; o runner chama a proteção antes das suítes).

## Não feito nesta rodada (motivo: horário)

A ordem abaixo segue o pedido original. Nada disso foi iniciado e nenhum código parcial ficou no branch.

- **3a** `/rede-fibra`: colunas Cliente e CTO visíveis por padrão, mais a migração única da preferência salva
  (com teste). Hoje continuam ocultas por padrão.
- **3b** busca de rede do topo para o técnico, só leitura. Hoje só o gestor a vê. Será preciso ajustar o
  teste que exige 403 para o técnico.
- **3c** `/rede-vinculos`: confiança "média" confirmável, com selo e motivo ("MAC −1", "+9 no último
  octeto"), e o aviso "A base do MkAuth lida por este servidor é de DATA". Hipótese para a DATA: o maior
  cadastro/`datacad` de cliente que a integração enxerga; a coluna real no MkAuth está **não verificada**.
- **4** teste de ponta a ponta do mapeamento no navegador headless e `docs/guia-mapeamento.md`.
- **5** CP-0 a CP-3: modelo de equipamentos, credenciais cifradas (libsodium, `NET_SECRET_KEY`), catálogo e
  equipamento físico, importação KML/KMZ. Lembrete para o CP-1: a chave `NET_SECRET_KEY`, quando existir,
  terá de entrar no backup (perder a chave = perder as credenciais). O `backup_map.sh` atual **não** a inclui.

## PARA O USUÁRIO

1. O backup diário do mapa está ativo (02:17, root) a partir do merge. Arquivos em
   `/root/isp-auxiliar-backups/mapa/`, retenção 30. Restauração na seção 1.
2. Quando o mapeamento real passar de 10 nós, `tests/map/run_all.sh` vai recusar rodar no banco real. É o
   esperado. Use um banco de teste via `DB_DATABASE=…` ou `MAP_TESTS_ALLOW_REAL_DB=1` conscientemente.
3. Os itens 3, 4 e 5 ficam para a próxima rodada. Sugiro dar a ela pelo menos algumas horas: o item 5
   sozinho envolve migrations novas (≥ 046), criptografia e telas.
4. Para o deploy no .86, as tabelas do mapa e dos vínculos devem ser **copiadas** (o dump deste script
   serve), não recriadas.

## Resultado final

**Verificado às 15:49 UTC, no branch com os itens 1 e 2:**

- `tests/Feature/*.php`: todas passaram, incluindo a nova `MapTestsGuardSmoke`.
- `tests/Feature/CadastroLocalDraft.js`: ok.
- `tests/net/run_all.sh`: 0 grupos com falha.
- `tests/map/run_all.sh`: 25 de 25 passaram. A proteção liberou, com 4 nós reais.

A primeira tentativa de net/map falhou por ambiente: a worktree nova não tinha o symlink
`tests/map/node_modules` (jsdom). Criei o symlink (fora do Git) e as duas passaram.

- Depois da suíte de mapa, o banco real tem 4 nós e nenhum `ZZ TESTE`.
- Não houve contato com a OLT nem escrita no MkAuth. O cron de telemetria não foi alterado.
- Merge ff-only em `main` e conferência do `testes.ievo.com.br`: ver o resumo da sessão; a conferência
  foi feita logo após este commit.
