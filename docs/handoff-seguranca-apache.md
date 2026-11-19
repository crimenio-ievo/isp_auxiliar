# Handoff — segurança do Apache no .89 (Fase 0)

Servidor .89 de TESTE, 2026-11-19. Nada tocado no .86, no MkAuth ou na OLT. Nenhum `.env` foi lido (só códigos HTTP).

## 0.1 — Exposição de `.env`/`.git`/`storage` pelos vhosts padrão `teste.ievo.com.br`

### Antes (Fato observado, GET a 127.0.0.1 com `Host: teste.ievo.com.br`, só código HTTP)
| Caminho | HTTP |
|---|---|
| `/isp_auxiliar/.env` | 200 (HTTP e HTTPS) |
| `/isp_auxiliar/.git/HEAD` | 200 |
| `/isp_auxiliar/storage/` | 200 (listagem) |
| `/_isp_auxiliar_unificacao/.env` | 200 |

Causa: `000-default.conf` (:80) e `default-ssl.conf` (:443) têm `DocumentRoot /var/www/html` (pasta-mãe de todas as worktrees), sem bloqueio de arquivos ocultos nem `Options -Indexes`.

### Logs (Fato)
Contados `access.log*`, `testes-access.log*` (inclusive `.gz`) e `other_vhosts_access.log` (510 linhas no total, desde 01/11/2026; rotações mais antigas não existem).
Pedidos a `/.git` ou `.env`, por IP: **127.0.0.1 → 1 (`/.git`) e 11 (`.env`)**; nenhum outro IP. Todos são testes de verificação (17/11 às 22:57 e 19/11 às 11:06, feitos por mim). **Nenhum pedido externo registrado.** Limite: não há log antes de 01/11 e os logs dos vhosts desativados (cadastro-rascunho/telemetria-interno) não foram varridos; o tráfego do túnel Cloudflare aparece como 127.0.0.1, então "127.0.0.1" não distingue um pedido externo vindo pelo túnel — mas os horários batem exatamente com os meus testes.

### Correção aplicada
Backups (mesma pasta): `sites-available/000-default.conf.bak-20261118`, `sites-available/default-ssl.conf.bak-20261118`.
1. Novo `/etc/apache2/conf-available/isp-hardening.conf` (habilitado com `a2enconf`, vale para todos os vhosts):
   - `<DirectoryMatch "/\.git">` → `Require all denied`
   - `<FilesMatch "^\.(?!well-known)">` → `Require all denied`
2. Nos dois vhosts padrão: `<Directory /var/www/html>` com `Options -Indexes` + `Require all denied`. O bloco `<Directory /var/www/html/isp_auxiliar/public>` (Require all granted) foi mantido: por ser mais específico, prevalece — a VPN 10.4.0.2 continua usando `/isp_auxiliar/public/login`. Os `<Directory .../<worktree>/public>` de `conf-enabled` também seguem liberados.
3. `apache2ctl configtest` → Syntax OK; `systemctl reload apache2`.

### Depois (Fato observado)
| Caminho | HTTP |
|---|---|
| `/isp_auxiliar/.env`, `/_isp_auxiliar_unificacao/.env` | 403 (HTTP e HTTPS) |
| `/isp_auxiliar/.git/HEAD` | 403 |
| `/isp_auxiliar/storage/`, `/_isp_auxiliar_unificacao/storage/` | 403 |
| `/isp_auxiliar/public/.env` | 403 |
| `/` (teste.ievo.com.br) | 403 |
| `/isp_auxiliar/public/login` (HTTP, HTTPS, e pelo IP 192.168.23.89) | 200 |
| `testes.ievo.com.br/login` | 200 |
| `testes.ievo.com.br/.env`, `/.git/HEAD` | 403 |
| `testes.ievo.com.br/rede-fibra` sem sessão | 302 |

### Rollback
```
a2disconf isp-hardening
cp -p /etc/apache2/sites-available/000-default.conf.bak-20261118 /etc/apache2/sites-available/000-default.conf
cp -p /etc/apache2/sites-available/default-ssl.conf.bak-20261118 /etc/apache2/sites-available/default-ssl.conf
apache2ctl configtest && systemctl reload apache2
```

### Recomendação (não executada) — decisão do usuário
Não dá para provar que ninguém leu o `.env` antes de 01/11/2026 (sem logs). Se o `teste.ievo.com.br` era publicado no túnel Cloudflare, considerar rotacionar credenciais (banco, MkAuth, comunidade SNMP). Isso exige ação do usuário e não foi feito.

## 0.2 — Commits de produção contidos em `main`? (somente leitura, sem merge)
`main` = 56ec7a4 no momento da verificação (`git merge-base --is-ancestor`).
| Commit | Descrição | Contido em main? |
|---|---|---|
| `3967195` (tag `prod-r5-3967195`) | Release: backup do banco usa --defaults-file | **Sim** |
| `4af16c6` (tag `prod-r2-4af16c6`) | fix(release): ocultar seletor sem destinos | **Sim** |
| `c19bbe0` (`hotfix/fotos-instalacao-validacao`) | fix(clientes): foto de instalação não pode ser descartada por erro em outro campo | **Sim** |

Nenhum commit faltante; nada a listar nem a mesclar. Obs.: o worktree `isp_auxiliar` está no branch `release/pre-maps-v0` (84cb4be), que também contém 4af16c6, e tem 2 arquivos de `scripts/releases/` modificados e um relatório não rastreado — **não tocados**.
