# Unificação 2 — main na worktree unificada, cron migrado, vhosts antigos desativados

Servidor .89 de TESTE, 2026-11-17 (UTC). Sem push, sem OLT/SNMP SET manual, sem ler `.env`, sem criar usuário, nenhuma worktree removida.
Obs.: o cron continua consultando a OLT por SNMP (somente leitura) como já fazia; só mudou o caminho do script.

## Parte 1 — main na worktree unificada
Fato: `main` (fe18d7e) era ancestral de `integracao/unica` (5621ba8). Working tree limpa. `git branch -f main integracao/unica` → ambos `5621ba8`; `git switch main` na worktree unificada. `git status`: "On branch main … nothing to commit, working tree clean" ("ahead of origin/main by 124 commits"; nada enviado). `git log --oneline -3`: 5621ba8 docs(net) parte B · 050f510 período · 3f753b4 .env.bak-*.
`integracao/unica` foi mantido (mesmo commit), **deixou de ser usado**; pode ser apagado depois, quando você decidir.
Rollback: `git -C /var/www/html/_isp_auxiliar_unificacao switch integracao/unica` (enquanto os dois apontarem para o mesmo commit).

## Parte 2 — cron da telemetria
Fato (antes): `/etc/cron.d/isp-net-telemetria`: `* * * * * www-data php /var/www/html/_isp_auxiliar_telemetria_fibra/scripts/net_tick.php --device=1 >/dev/null 2>&1`. O stdout/stderr vai para /dev/null; o registro real é `<raiz>/storage/logs/net_tick-AAAA-MM-DD.log` (JSON por tick). O lock é `flock` em `<raiz>/tmp/net_collect_device_1.lock` (`NetDeviceLock($root.'/tmp')`) — por isso os locks das duas pastas NÃO se enxergam. O estado de coleta (última tentativa/leitura, streaks) fica no banco, não em arquivo.
Conferências: `diff -rq` de `scripts/` e `backend/Infrastructure/Net` entre as duas worktrees = sem diferenças (net_tick, net_collect, NetTick e NetDeviceLock idênticos byte a byte). `.env` da unificada tem `NET_CRED_OLT_89_COMMUNITY` (só existência; também na da telemetria). Banco: `DB_HOST=127.0.0.1` e `DB_DATABASE=isp_auxiliar` nas DUAS (iguais). `tmp` já era `root:www-data 2770`; `storage/logs` NÃO existia na unificada (e `storage` é root:root 755, então o www-data não conseguiria criar): criei `storage/logs` `root:www-data 2770` antes da troca (não versionado).
Troca: backup `/root/isp-auxiliar-backups/isp-net-telemetria.cron.bak-20261117` (idêntico ao original, fora de /etc/cron.d). O tick das 22:45:01 era um ciclo de status de 67 s; esperei até não haver `net_tick` rodando e o lock antigo estar livre (`flock -n`) e troquei só o caminho às 22:46:11, antes do tick das 22:47.
Validação (Fato): 65 amostras por segundo durante o ciclo de 22:55 e 170 antes: nunca mais de 1 processo `php net_tick.php` por vez, sempre do caminho `_isp_auxiliar_unificacao`; o caminho antigo nunca reapareceu (último tick no log antigo: 22:45:01). Log novo (`unificacao/storage/logs`): 22:47 idle; 22:48 power ok (127 requisições, 77,7 s, 33 ONUs); 22:50–22:54 idle; 22:55:01 status ok (130 requisições, 67 s, 33 ONUs). Banco: `last_power_attempt_at` 22:48:01 e `last_attempt_at`/`last_success_at` 22:55:01, `last_result=ok`, `last_error=null` — o ciclo de status de 600 s depois da troca gravou normalmente.
Hipótese: não há linha de 22:49:01 no log novo porque o ciclo de power de 22:48 durou até ~22:49:19 e esse tick encontrou o lock ocupado (`NetTick` devolve `locked` sem gravar linha). Consistente com o código; não confirmei por outro meio.
Limite: como o cron descarta stderr, um erro de PHP no tick não apareceria; só vi resultado "ok" no JSON e o avanço no banco.
O lock antigo (`telemetria/tmp/net_collect_device_1.lock`) e o histórico de logs antigos ficam na pasta velha (nada movido nem apagado).
Rollback: com nenhum `net_tick` rodando (`pgrep -f scripts/net_tick.php` vazio) e esperando o fim de qualquer ciclo em curso:
```
cp -p /root/isp-auxiliar-backups/isp-net-telemetria.cron.bak-20261117 /etc/cron.d/isp-net-telemetria
```
(Não troque durante um ciclo: os dois locks são independentes e poderia haver coleta em dobro.)

## Parte 3 — vhosts antigos
Fato: `fix/cadastro-rascunho` (7ab9c60) e `feature/telemetria-fibra` (987269a) estão contidos em `main`. `a2dissite isp-auxiliar-cadastro-rascunho isp-auxiliar-telemetria-interno`; `apache2ctl configtest` Syntax OK; `systemctl reload apache2`. Os `.conf` continuam em `sites-available`. Smoke: `testes.ievo.com.br/login` 200; `/rede-fibra` sem sessão 302 → `/login`; `:8444` e `:8445` recusam conexão (nada escutando; os `Listen` ficavam dentro desses .conf). Sem erro novo no `testes-error.log`.
Rollback: `a2ensite isp-auxiliar-cadastro-rascunho isp-auxiliar-telemetria-interno && apache2ctl configtest && systemctl reload apache2`.

## Parte 4 — levantamento: limitar a exposição do testes.ievo.com.br (NADA aplicado)
### Fatos observados
- `testes.ievo.com.br` (:80 e :443): `Require all granted`, certificado `snakeoil` (autoassinado). Não há `certbot` nem `/etc/letsencrypt`. `mod_remoteip` NÃO está habilitado.
- DNS de `testes.ievo.com.br` e de `teste.ievo.com.br` resolve para IPs da Cloudflare (172.67.147.183, 104.21.28.219). Roda `cloudflared tunnel run --token-file /etc/cloudflared/token` neste servidor (túnel; as rotas/hostnames ficam no painel Cloudflare, que NÃO li). Servidor tem só IP privado (192.168.23.89/24).
- Nos logs, o tráfego aparece como `127.0.0.1`/`::1` (compatível com túnel) e `10.4.0.2` (cliente interno/VPN acessando `https://192.168.23.89/…`). O IP real de quem vem pelo túnel só estaria no cabeçalho `CF-Connecting-IP`, que o Apache hoje não usa.
### ACHADO URGENTE (fora do que foi pedido; nada alterado)
Os vhosts padrão `teste.ievo.com.br` (`000-default.conf` :80 e `default-ssl.conf` :443) têm `DocumentRoot /var/www/html`, a pasta-mãe de TODAS as worktrees, sem bloqueio de arquivos ocultos. Teste só de status (sem ler o conteúdo): `GET /_isp_auxiliar_unificacao/.env` → **200** (1439 bytes), e o mesmo para `/isp_auxiliar/.env`, `/_isp_auxiliar_redesign_shell/.env`, `/_isp_auxiliar_telemetria_fibra/.env`, `/_isp_auxiliar_cadastro_rascunho/.env`, em HTTP e HTTPS com Host `teste.ievo.com.br` a partir de 127.0.0.1. `/<worktree>/storage/` também lista (200); `/…/backend/config/app.php` dá 500 (PHP executa, sem vazar código). Isso já existia antes desta tarefa (não decorre do vhost testes nem do cron).
Não sei se é alcançável da internet: depende de o túnel publicar `teste.ievo.com.br` (ou de acesso pelo IP interno/VPN). Nos logs do Apache de 2026-10-23 em diante (14 rotações) NÃO há nenhum pedido a `.env` além dos meus testes de hoje às 22:57 (todos de 127.0.0.1). Logs mais antigos não existem mais, então não dá para afirmar nada antes de 23/10.
Sugestão (não aplicada; peça e eu aplico com backup/rollback): nos dois vhosts `teste.ievo.com.br`, `<FilesMatch "^\.(?!well-known)">Require all denied</FilesMatch>` e `Options -Indexes` em `/var/www/html`, ou melhor, apontar o DocumentRoot para `isp_auxiliar/public` (ou desativar esses vhosts). Se houver chance de o `.env` ter sido buscado de fora, considerar a troca das credenciais (banco, MkAuth, comunidade SNMP).
### Opções para limitar o acesso ao testes.ievo.com.br
| Opção | O que exige | Observação |
|---|---|---|
| **A. Cloudflare Access (Zero Trust)** na hostname do túnel | Acesso ao painel Cloudflare; política por e-mail/IdP (e, se quiser, por país/IP); nenhuma mudança no Apache | Funciona com o túnel atual; não precisa DNS novo, porta 80 nem certificado no Apache. Provavelmente a mais simples SE o acesso é pelo túnel. |
| **B. `Require ip` no Apache** | Habilitar `mod_remoteip` (`RemoteIPHeader CF-Connecting-IP`, `RemoteIPInternalProxy 127.0.0.1`) para o Apache enxergar o IP real; lista das faixas dos técnicos (ex.: LAN 192.168.23.0/24, VPN — o 10.4.0.x visto nos logs —, IP fixo do escritório) | Sem o remoteip, `Require ip` só veria 127.0.0.1 (bloquearia o túnel inteiro ou liberaria todos). Técnicos em 4G/CGNAT ou IP variável ficam de fora. |
| **C. Certificado válido (Let's Encrypt)** | HTTP-01: DNS público (sem proxy) apontando para o IP público do servidor e porta 80 aberta/redirecionada até o .89; com o túnel (sem porta de entrada) use DNS-01 com token da API de DNS; ou certificado de origem da Cloudflare com SSL "Full (strict)" | Resolve só a confiança do TLS; **não limita quem acessa**. Hoje, atrás da Cloudflare, o navegador já vê o certificado da Cloudflare. |
| **D. Só rede interna/VPN** | Retirar a hostname do túnel (ou do DNS público) e servir só em 192.168.23.89; técnicos entram pela VPN; certificado de CA interna ou válido via DNS-01 | Mais restritivo; exige que todos tenham VPN. |
| **E. Autenticação básica do Apache** (`Require valid-user`) à frente do login | Criar `htpasswd` e distribuir a senha | Rápido, 2º fator simples; sem granularidade e sem proteção para as chamadas de API automatizadas. |
Para escolher: preciso saber (1) se o acesso dos técnicos é pelo túnel Cloudflare, pela LAN ou por VPN; (2) quais faixas de IP eles usam (LAN, VPN 10.4.0.0/?, IP do escritório); (3) se você controla a conta Cloudflare (para A e para DNS-01); (4) se a porta 80 pública do .89 pode ser aberta (só para HTTP-01).

## Não verificado
- Alcance externo real (não testei de fora, não vi o ingress do túnel).
- O conteúdo de qualquer `.env` (só status/tamanho).
- Erros de PHP do cron (stderr descartado).
- O próximo ciclo de status depois do das 22:55 (23:05).
- Que nada mais use `:8444/:8445` (busquei referências em `scripts/`, `backend/`, `tests/`: nenhuma; não busquei fora do repositório).
