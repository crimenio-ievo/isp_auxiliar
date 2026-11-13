# Integração com testes.ievo.com.br — auditoria sem merge

## Fato

- Vhost HTTP/HTTPS `testes.ievo.com.br`: `/etc/apache2/sites-enabled/testes.ievo.com.br.conf`, DocumentRoot `/var/www/html/_isp_auxiliar_redesign_shell/public`. Pasta Git `/var/www/html/_isp_auxiliar_redesign_shell`, branch `feature/mapa-fase1`, HEAD `cf85e9a033f8f02a0d2f73514a1850ae6067c94f`. Há `.env.bak-2026-10-06` não rastreado, preservado, não lido.
- `git log feature/telemetria-fibra..feature/mapa-fase1`: vazio. No sentido inverso havia 40 commits na medição, incluindo documentação desta rodada; a quantidade cresce com commits seguintes. `feature/mapa-fase1` é ancestral da telemetria. `git merge --ff-only feature/telemetria-fibra` nessa branch seria fast-forward no estado medido.
- `git merge-tree --write-tree feature/mapa-fase1 feature/telemetria-fibra`: código 0, árvore `5a0689ac189d62356f67eec47972cfe15bc01f0d`, sem conflitos. Não executado merge, checkout, switch ou push.
- Contra metadados do banco `isp_auxiliar`, todos os arquivos SQL presentes na branch do mapa já estão aplicados e com checksum correspondente. 030–034 estão aplicadas no banco e ausentes nessa branch. Também existem registros 009–015 de outra frente.
- O auditor de release em `scripts/releases/common.sh:release_pending_migrations` percorre os arquivos locais e compara com registros aplicados. Não considera pendente um registro extra no banco cujo arquivo esteja ausente. O aplicador oficial também percorre arquivos locais. Portanto 030–034 extras não provocam falha nesse auditor por ausência do arquivo. O auditor de política SQL examina somente migrations pendentes.
- Os dois vhosts não injetam configuração de banco no Apache; usam a configuração de cada worktree. Os arquivos `.env` são caminhos/inodes distintos. Não foram lidos.

## Hipótese / não verificado

Não foi possível confirmar que `testes` e `:8445` usam o mesmo banco sem ler a configuração de ambiente ou obter uma sessão administrativa real; nenhuma dessas ações foi feita. O padrão em código é `isp_auxiliar`, mas pode ser sobrescrito. Não tratar essa hipótese como confirmação. A auditoria de checksums acima foi contra o banco local conhecido, não prova a conexão efetiva do vhost `testes`.

Merge-tree não valida comportamento em navegador, cookie, permissões, banco efetivo ou configuração externa. As tabelas aditivas não exigem rollback para servir o mapa antigo, mas FKs da telemetria continuam protegendo nós referenciados. A aparência e o login real em `testes` após integração não foram verificados.

## Plano para uma rodada autorizada

1. Conferir novamente HEADs/status, vhost, banco efetivo e cookies/sessão. Preservar os arquivos não rastreados. Fazer dump e backup da configuração Apache fora do Git.
2. Preparar um worktree detached no commit `cf85e9a` para rollback de código, sem trocar a branch do worktree em andamento. Preparar acesso de ambiente e sessão por procedimento autorizado, sem versionar credenciais.
3. Repetir testes isolados e simulação de merge. Com autorização explícita, fazer somente o fast-forward da branch servida para o commit de telemetria revisado. Não incorporar mudanças não commitadas, não alterar main ou produção .86.
4. Confirmar DocumentRoot, cookie/base URL e usuário do PHP; configtest antes de qualquer reload. Conferir login, mapa e Rede de fibra, API e fontes. Não recriar cron nem importar syslog de novo para integrar a interface; o cron existente permanece no worktree de telemetria.
5. Rollback de interface: apontar o DocumentRoot para o worktree detached preparado, restaurar configuração Apache salva, configtest e reload. Não usar reset destrutivo ou checkout no worktree de feature; não desfazer 030–034 nem apagar dados de telemetria, pois o cron depende delas. A criação/remoção do worktree usa `git worktree add/remove`, nunca remoção manual.

Nenhum passo de integração ou rollback foi executado nesta auditoria.
