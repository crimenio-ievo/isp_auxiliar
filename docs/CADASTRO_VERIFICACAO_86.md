# Verificação do .86 — somente leitura, NÃO executada pelo Codex

Rodar manualmente no .86. Não criar phpinfo, não chamar login/finalização, não
executar migrations, sessionclean, cron ou comandos de reload. Não imprimir .env,
conteúdo/nomes de arquivos de sessão, tokens ou linhas brutas dos logs de acesso.

## 1. Localizar a release realmente servida

```bash
sudo apache2ctl -S
sudo apache2ctl -t -D DUMP_INCLUDES
sudo grep -RInE '^[[:space:]]*(DocumentRoot|Alias[[:space:]]|PHPIniDir|SetHandler|ProxyPassMatch)' /etc/apache2/sites-enabled /etc/apache2/conf-enabled
```

A partir do DocumentRoot/Alias e dos symlinks encontrados, definir o caminho real
da release (pasta que contém backend/public, não o checkout histórico de dados):

```bash
cadastro_release='/CAMINHO/REAL/DA/RELEASE/ATUAL'
readlink -f "$cadastro_release"
```

## 2. SESSION_PATH configurado na release e overrides

Ler somente a configuração da aplicação, sem bootstrap completo/banco; exibir
apenas as opções de sessão. O código lê .env internamente sem exibir credenciais:

```bash
php -r '
$root = realpath($argv[1]);
if (!$root || !is_file($root . "/backend/config/app.php")) { exit(1); }
require $root . "/backend/bootstrap/autoload.php";
App\Core\Env::load($root . "/.env");
$app = require $root . "/backend/config/app.php";
$session = $app["session"] ?? [];
$path = trim((string) ($session["save_path"] ?? ""));
echo "SESSION_PATH configurado=" . ($path === "" ? "(herda PHP/Apache)" : $path) . PHP_EOL;
if ($path !== "") {
    $absolute = str_starts_with($path, "/") ? $path : $root . "/" . $path;
    echo "SESSION_PATH resolvido=" . (realpath($absolute) ?: $absolute) . PHP_EOL;
}
echo "cookie_name=" . ($session["cookie_name"] ?? "(padrão)") . PHP_EOL;
' "$cadastro_release"

sudo grep -RInE 'session\.(save_path|save_handler|gc_maxlifetime|gc_probability|gc_divisor|cookie_lifetime)|SetEnv[[:space:]]+SESSION_(PATH|COOKIE_NAME)|ini_set.*session|session_save_path|session_set_cookie_params' \
    /etc/apache2/sites-enabled /etc/apache2/conf-enabled /etc/apache2/mods-enabled \
    "$cadastro_release/public/index.php" "$cadastro_release/backend/config/app.php"
```

Confirmar a precedência no public/index.php da release. O SESSION_PATH da aplicação
pode substituir session.save_path do Apache; SetEnv/getenv também pode substituir
o valor do .env. Não presumir que o valor mostrado pelo CLI é o da requisição HTTP.

## 3. PHP do Apache e limites configurados

```bash
sudo apache2ctl -M | grep -E 'php|proxy_fcgi|mpm'
ls -l /etc/apache2/mods-enabled/php*.load
sudo grep -RInE '^[[:space:]]*(session\.(save_path|save_handler|gc_maxlifetime|gc_probability|gc_divisor|cookie_lifetime)|post_max_size|upload_max_filesize|max_input_time|max_execution_time)[[:space:]]*=' /etc/php/*/apache2/php.ini /etc/php/*/apache2/conf.d
sudo grep -RInE '^[[:space:]]*(php_(admin_)?(value|flag)[[:space:]]+(session\.|post_max_size|upload_max_filesize|max_input_time|max_execution_time)|LimitRequestBody)' /etc/apache2/sites-enabled /etc/apache2/conf-enabled /etc/apache2/mods-enabled
sudo find "$cadastro_release" -name .htaccess -o -name .user.ini
```

Nos .htaccess/.user.ini listados, procurar somente as mesmas diretivas acima.
Se usar FPM, examinar também /etc/php/*/fpm/php.ini, conf.d e php_value/php_admin_value
dos pools, incluindo os overrides equivalentes. Não usar php --ini como prova da
SAPI Apache. Em especial, `php -c .../apache2/php.ini -r ...` continua sendo CLI e
pode mostrar max_input_time=-1/max_execution_time=0 por comportamento da SAPI.

Estes comandos confirmam a cadeia de configuração em disco. Sem um endpoint de
diagnóstico HTTP já existente e restrito, não é possível observar estritamente em
somente leitura o ini_get efetivo de uma requisição do Apache. Não criar tal endpoint
nesta verificação; registrar essa limitação se houver dúvida sobre config carregada.

## 4. Limpeza da pasta efetiva de sessões

Depois de resolver o caminho acima:

```bash
cadastro_sessions='/CAMINHO/EFETIVO/DAS/SESSOES'
sudo stat -Lc '%n modo=%a dono=%U grupo=%G' "$cadastro_sessions"
sudo find "$cadastro_sessions" -maxdepth 1 -type f -name 'sess_*' -printf '%T@\n' |
    awk -v now="$(date +%s)" '{n++; if(now-$1>1440) old++} END {print "arquivos=" n+0, "sem alterar ha mais de 24min=" old+0}'
sudo cat /etc/cron.d/php
sudo sed -n '1,220p' /usr/lib/php/sessionclean
sudo systemctl cat phpsessionclean.timer phpsessionclean.service
sudo systemctl list-timers --all phpsessionclean.timer
sudo journalctl -u phpsessionclean.service --since '7 days ago' --no-pager
sudo grep -RIlE 'sessionclean|sess_\*|session.*(delete|unlink)' /etc/cron.d /etc/cron.daily /etc/cron.hourly /etc/systemd/system /usr/local/bin /usr/local/sbin
sudo grep -RIlF "$cadastro_sessions" /etc/cron.d /etc/systemd/system /usr/local/bin /usr/local/sbin
```

O limiar de 24 minutos acima é apenas comparação; substituir pelo gc_maxlifetime
encontrado no .86 se for diferente. Comparar os save_path INI efetivamente varridos
pelo sessionclean com a pasta da aplicação. Examinar os arquivos adicionais listados,
sem executar os comandos de limpeza. Não concluir cobertura só porque o timer existe.

## 5. POSTs rejeitados — contagem sem PII

Escolher os logs do vhost correto após a inspeção do Apache:

```bash
cadastro_access='/var/log/apache2/ACCESS-DO-VHOST.log'
cadastro_error='/var/log/apache2/ERROR-DO-VHOST.log'

sudo tail -n 50000 "$cadastro_access" | awk -F '"' '
{split($2,r," "); split($3,s," "); p=r[2]; sub(/\?.*/,"",p);
 if(r[1]=="POST" && p ~ /\/clientes\/novo(\/aceite)?$/) count[p " HTTP=" s[1]]++}
END {for(k in count) print k, "quantidade=" count[k]}'

sudo tail -n 50000 "$cadastro_error" | awk '
/POST Content-Length.*exceeds/ {size++}
/request body.*(exceed|limit)|Request body exceeds/ {apache++}
/\[cadastro.post\] case=post_too_large/ {large++}
/\[cadastro.post\] case=unauthenticated/ {auth++}
/\[cadastro.post\] case=invalid_csrf/ {csrf++}
END {print "PHP POST acima do limite=" size+0;
 print "Apache limite de corpo=" apache+0;
 print "diagnostico post_too_large=" large+0;
 print "diagnostico unauthenticated=" auth+0;
 print "diagnostico invalid_csrf=" csrf+0}'
```

Confirmar o formato do CustomLog: o awk acima assume `combined`/`common` com
requisição entre aspas e status logo após. Nas últimas 50 mil linhas, HTTP 413
é indício de tamanho; HTTP 302 sozinho não diferencia sucesso, CSRF ou falta de login.
O prefixo `[cadastro.post]` só existirá onde a Etapa A tiver sido implantada; não
é esperado na R5 original. Warnings PHP de tamanho podem aparecer sem a rota,
portanto não comprovam isoladamente qual formulário falhou. Contagens zeradas não
descartam ocorrências antigas/rotacionadas nem logging PHP em outro destino.
