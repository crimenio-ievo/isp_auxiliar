# Terceira iteração — layout antigo de referência

Alterações realizadas somente no host local de testes, fora do repositório
atual:

- URL: `/isp-auxiliar-old-reference`;
- alias Apache para
  `/var/www/html/isp_auxiliar_old_20260612_1815/public`;
- `AllowOverride All`, `Options -Indexes` e `DirectoryIndex index.php`;
- `APP_URL` próprio e escrita MkAuth/mensagens em modo seguro;
- nome e caminho de cookie de sessão separados da aplicação atual;
- exigência de usuário administrativo;
- bloqueio de POSTs, exceto login;
- redirecionamento do caminho físico público para o alias por regra baseada em
  `THE_REQUEST`, sem capturar o rewrite interno;
- banner fixo “LAYOUT ANTIGO — SOMENTE REFERÊNCIA”.

Configuração criada:
`/etc/apache2/conf-available/isp-auxiliar-old-reference.conf`.
Backups:

- `/etc/apache2/sites-available/000-default.conf.bak-20260731-old-reference`;
- `/etc/apache2/sites-available/default-ssl.conf.bak-20260731-old-reference`.

`apache2ctl configtest` retornou `Syntax OK`; foi usado apenas reload gracioso.
O login da referência respondeu HTTP 200, o acesso pela raiz redirecionou para
o alias e o login atual continuou HTTP 200.

Rollback:

```bash
a2disconf isp-auxiliar-old-reference
apache2ctl configtest
systemctl reload apache2
```

Depois de desabilitar o alias, remover somente as duas regras adicionadas a
`public/.htaccess`, o bloco de sessão/read-only/ajuste de `SCRIPT_NAME` inserido
em `public/index.php` e o banner inserido em `backend/Views/layouts/app.php`.
Restaurar no `.env` antigo a URL anterior, mantendo as flags de escrita/envio
bloqueadas até decisão administrativa. Essas remoções devem ser pontuais porque
o checkout antigo já continha alterações históricas não relacionadas e não pode
receber um reset amplo.

O checkout antigo não foi copiado para o projeto atual, não compartilha sessão
e não é baseline de release. A navegação além do login ainda requer validação
administrativa funcional com usuário autorizado.
