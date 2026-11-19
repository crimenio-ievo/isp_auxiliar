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

## Suítes e merge

Ver a seção "Resultado final" abaixo.
