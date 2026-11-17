# Unificação — testes.ievo.com.br passa a servir a worktree unificada (parte A)

Servidor .89 de TESTE, 2026-11-17. Sem push, sem OLT, sem alterar cron nem vhosts :8444/:8445, sem criar usuário.

## A0 — main por avanço reto
Fato: `main` (ff424b2) era ancestral de `integracao/unica`; `git branch -f main integracao/unica` → ambos `fe18d7e2204074b08b7b277638490d1ee99fe906`. `main` não estava em checkout em nenhuma worktree. `origin/main` (54c3446) também é ancestral (tratado por `-s ours`, ff424b2).

## A1 — verificações
- a) `cf85e9a` (mapa-fase1) está contido em `main` e em `integracao/unica`: SIM.
- b) A worktree unificada tem `.env` (640 root:www-data). Só nomes de chaves comparados com o da `_isp_auxiliar_redesign_shell`: 46 × 45; nada falta; sobra só `NET_CRED_OLT_89_COMMUNITY` (telemetria). Valores NÃO comparados (o `.env` da unificada foi copiado da telemetria): não sei se `APP_*`/banco/sessão diferem do `.env` do redesign.
- c) Vhost: `/etc/apache2/sites-available/testes.ievo.com.br.conf` (portas 80 e 443, certificado snakeoil). DocumentRoot antigo `/var/www/html/_isp_auxiliar_redesign_shell/public`. **Sem restrição de IP** (`Require all granted`): a proteção é só o login da aplicação. No código: `Application::handle` redireciona qualquer rota não pública sem sessão para `/login`; `NetFiberController` exige gestor/admin (página redireciona ao /dashboard, APIs dão 403). Teste sem sessão (curl): `/rede-fibra`, `/api/rede-fibra`, `/api/rede-fibra/onu`, `/buscar`, `/eventos` → 302 /login, 0 bytes de corpo. Com sessão de perfil não gestor (simulada em processo): página 302 /dashboard; APIs 403 "Acesso negado.".
- d) Apache roda como `www-data`; lê código e `.env`. Na worktree unificada `tmp`, `logs` e `storage/*` eram `root:root 755` (não gravável). Corrigido: `chgrp www-data` + `chmod 2770` em `tmp logs storage/{cache,contracts,installations,sessions,uploads}` (mesmo padrão do redesign; não é versionado). Verificado com `www-data`.
  Dados de runtime do redesign: `storage/` só tinha `uploads/clientes/drafts`, `cache/client_drafts` e 81 arquivos de sessão; sem `contracts/config.json`. Nada copiado. **Consequência: sessões abertas no testes.ievo.com.br caem; é preciso logar de novo.**

## A2 — troca
Backup `/etc/apache2/sites-available/testes.ievo.com.br.conf.bak-20261117`. Alterado só DocumentRoot e `<Directory>` (nos dois VirtualHosts) para `/var/www/html/_isp_auxiliar_unificacao/public`. `apache2ctl configtest` Syntax OK; `systemctl reload apache2` (reload, não restart; Apache ativo).

## A3 — smoke
Fato (HTTP real, sem sessão): `/login` 200 (título "Entrar no sistema | ISP Auxiliar"), `/api/health` 200, `/dashboard`, `/mapa`, `/clientes/novo`, `/rede-fibra` e APIs → 302 /login; `/assets/css|js/rede-fibra.*` 200; rota inexistente 404. Nenhum 500. `testes-error.log`: só o aviso de certificado snakeoil (já existia); sem erro de PHP. `:8444` responde 200 e `:8445` 200 (por 192.168.23.89), inalterados.
Autenticado: sem credenciais e sem criar usuário/sessão, usei um script EM PROCESSO (`Application::handle`, só GET, `$_SESSION` em memória, nenhum arquivo de sessão gravado, banco só leitura) como admin: `/dashboard`, `/mapa`, `/api/mapa`, `/clientes`, `/contratos/novos`, `/rede-fibra`, `/api/rede-fibra`, `/buscar`, `/eventos` → todos 200, sem exceção.
**Não verificado:** login real pelo navegador no vhost; envio de formulário de cadastro; foto/assinatura; que o `.env` copiado aponte para o mesmo banco que o do redesign (se o apontamento for outro, o mapa pode mostrar dados diferentes); certificado/DNS externo de testes.ievo.com.br (testei com `--resolve` para 127.0.0.1). Não há rota `/cadastro`; usei `/clientes` e `/contratos/novos` como cadastro.

## A4 — rollback
```
cp -p /etc/apache2/sites-available/testes.ievo.com.br.conf.bak-20261117 /etc/apache2/sites-available/testes.ievo.com.br.conf
apache2ctl configtest && systemctl reload apache2
```
(O ajuste de permissões na worktree unificada é inofensivo e pode ficar.) Não foi necessário executar o rollback.
