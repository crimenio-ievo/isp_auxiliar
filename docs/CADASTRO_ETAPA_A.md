# Cadastro — Etapa A

Branch `fix/cadastro-rascunho`, base R5 `3967195`, worktree próprio.
Sem heartbeat, relogin, alteração de banco ou infraestrutura.

## A1 — segurança do rascunho local

O rascunho não coleta, grava nem restaura CSRF, tokens, credenciais,
identificadores de rascunho ou de envio. Dados antigos são filtrados na leitura.
Assinatura desenhada continua sendo evidência do cadastro, não token de autenticação.

Validação: `node tests/Feature/CadastroLocalDraft.js`, `node --check public/assets/js/app.js`,
`git diff --check`. Não há PHP alterado neste commit; `php -l` não se aplica.
Reversão: `git revert <commit-A1>` nesta branch, sem reset nem alteração de outros worktrees.

## A2 — diagnóstico do POST

Nas duas rotas, corpo vazio acima de post_max_size é identificado antes do controller.
Com login, recebe HTTP 413 e “Envio maior que o limite”; sem login, continua indo
para /login, com mensagem explícita. CSRF inválido com login recebe mensagem própria.
Quando ambos os problemas coexistem, os dois casos são registrados e prevalece o login.

Log: mecanismo PHP `error_log` já usado pela aplicação (normalmente o ErrorLog
do vhost Apache). Prefixo `[cadastro.post]`, casos `post_too_large`,
`unauthenticated`, `invalid_csrf`. Somente rota fixa, tamanho e limite; sem
query string, corpo, identidade, IP ou token.

Validação: `php tests/Feature/CadastroPostDiagnostics.php`, lint de Application.php,
AuthController.php, ClientController.php e do teste; regressão A1.
Reversão: `git revert <commit-A2>`.

## A3 — proteção de tamanho

Menor solução adotada: reutilizar o compressor que já existe (máximo 1280×1280,
JPEG qualidade 0,72) e acrescentar pré-checagem dos limites reais publicados pelo
PHP nas duas views. Sem alteração de php.ini/Apache nem reorganização do JS.
O envio é bloqueado enquanto fotos são preparadas, se um arquivo ultrapassar
upload_max_filesize ou se o total estimado exceder post_max_size. O total inclui
campos/assinatura e reserva conservadora de 64 KiB + 1 KiB por campo para multipart.
O tamanho total das fotos aparece na fila, além do tamanho individual existente.
Se o navegador não conseguir comprimir/substituir o arquivo, a checagem ainda
considera o arquivo original e impede excesso. O servidor mantém a barreira A2.

Estimativa de uso: ordem de centenas de KB por foto, variável conforme conteúdo e
codificador. Medição sintética local em GD: imagem 1280×960 com gradiente/ruído,
JPEG Q72 = 346570 bytes (338 KiB); três = 1039710 bytes (0,99 MiB). É referência
sintética, não medição de fotos reais de celular nem do canvas do navegador.
O roteiro manual deve registrar os tamanhos reais exibidos com 3+ fotos.

Validação: teste JS A1 ampliado para tamanho por arquivo, lote de fotos, assinatura,
limite ilimitado e processamento pendente; node --check; php -l nas duas views.
Reversão: `git revert <commit-A3>`. Nenhuma elevação de limite é necessária agora.

## A4 — salvamento antes da navegação

O submit aguarda leitura dos arquivos e gravação local antes de requestSubmit,
que executa novamente os validadores existentes. O listener fica após os demais
validadores; eventos já cancelados continuam cancelados. Clique duplo é bloqueado
durante a gravação e o botão/valor original é preservado. Campos são coletados
após a leitura dos arquivos. Salvamentos concorrentes são serializados por formulário.

Falha de quota/leitura aparece em alerta dentro do formulário, inclusive durante
autosave. No submit a página fica aberta e o alerta recebe foco; após liberar espaço
ou reduzir fotos é possível tentar novamente. Não há promessa de persistência no
servidor: o texto de sucesso diz “neste navegador”.

Validação: testes JS com leitura retardada, clique duplo, assinatura, validador que
cancela envio, quota cheia e nova tentativa, concorrência entre autosave e submit;
node --check e regressões A1–A3. Sem PHP alterado neste commit, php -l não se aplica.
Reversão: `git revert <commit-A4>`.

## Entrega e validação

| Etapa | Commit |
|---|---|
| A1 | a083e07 |
| A2 | 49adbc4 |
| A3 | e2505fd |
| A4 | 0ed0c0f |

Também passaram: HomologationCorrectionsSmoke (31),
AcceptanceSignatureFormatRegression (19), ClientPlanConfirmationSmoke (23),
ClientPlanSnapshotSmoke (16), AppBasePathSmoke (17). Os testes usam fakes/dados
sintéticos e temporários dentro deste worktree; nenhuma chamada a banco ou rede.
Não foi executada finalização real nem validação pelo Apache/navegador.

Para reverter toda a etapa, somente nesta branch e com árvore limpa:

```bash
git -C /var/www/html/_isp_auxiliar_cadastro_rascunho revert 0ed0c0f e2505fd 49adbc4 a083e07
```

## Proposta de Apache — NÃO APLICADA

Um novo vhost HTTPS na porta 8444, sem alterar os vhosts atuais. Confirmar que a
porta continua livre antes de ativar. URL: `https://teste.ievo.com.br:8444/`.
O certificado de teste abaixo é o já usado no .89; o navegador pode alertar.
Porta separada isola localStorage por origem. Cookie tem nome próprio (portas
sozinhas não isolam cookies). Storage e sessões devem pertencer a este worktree.

Arquivo exato proposto: `/etc/apache2/sites-available/isp-auxiliar-cadastro-rascunho.conf`:

```apache
Listen 8444 https
<VirtualHost *:8444>
    ServerName teste.ievo.com.br
    DocumentRoot /var/www/html/_isp_auxiliar_cadastro_rascunho/public
    SSLEngine on
    SSLCertificateFile /etc/ssl/certs/ssl-cert-snakeoil.pem
    SSLCertificateKeyFile /etc/ssl/private/ssl-cert-snakeoil.key
    SetEnv APP_ENV homologation
    SetEnv APP_BASE_PATH ""
    SetEnv APP_URL https://teste.ievo.com.br:8444
    SetEnv SESSION_COOKIE_NAME isp_auxiliar_cadastro_rascunho_session
    SetEnv SESSION_PATH /var/www/html/_isp_auxiliar_cadastro_rascunho/storage/sessions
    SetEnv SESSION_COOKIE_SECURE true
    SetEnv MKAUTH_WRITE_ENABLED false
    SetEnv EMAIL_DRY_RUN true
    SetEnv EVOTRIX_DRY_RUN true
    <Directory /var/www/html/_isp_auxiliar_cadastro_rascunho/public>
        Options -Indexes
        AllowOverride All
        Require all granted
        DirectoryIndex index.php
    </Directory>
    ErrorLog ${APACHE_LOG_DIR}/cadastro-rascunho-error.log
    CustomLog ${APACHE_LOG_DIR}/cadastro-rascunho-access.log combined
</VirtualHost>
```

Antes de ativar: preparar `.env` próprio não versionado, com a configuração de
leitura do .89 e MKAUTH_WRITE_ENABLED=false, APP_ENV=homologation, notificações
em dry-run e sessão exclusiva também no próprio arquivo. Não usar symlink para
storage/.env de outro worktree. Sem isso, não testar login: a R5 tem modo demo
quando a integração não está configurada. O arquivo ainda não foi copiado nem
credenciais lidas nesta etapa. Preparar permissões de escrita apenas nos diretórios
de runtime deste worktree para www-data; não mudar permissões de outros projetos.

Depois de autorização explícita, instalação do arquivo e preparação do runtime:

```bash
ss -ltn 'sport = :8444'
sudo a2ensite isp-auxiliar-cadastro-rascunho.conf
sudo apache2ctl configtest && sudo systemctl reload apache2
```

Desfazer a publicação, preservando o worktree/dados:

```bash
sudo a2dissite isp-auxiliar-cadastro-rascunho.conf
sudo apache2ctl configtest && sudo systemctl reload apache2
```

Isso também retira o Listen 8444 do conjunto habilitado. Não há necessidade de
apagar o arquivo ou trocar branch. Nenhum desses comandos de infraestrutura foi executado.

## Roteiro manual após autorização do Apache

Usar operador existente e a URL da porta 8444. Não criar usuário de teste.
MkAuth .88 permanece com escrita bloqueada. Não salvar Configurações nem executar
migrações. A finalização real no MkAuth não faz parte deste teste.

1. **Reabrir com rascunho:** preencher um cadastro, anexar foto e esperar “Rascunho
   salvo neste navegador”. Para simular legado, no DevTools substituir somente
   `_csrf` em `localStorage['client-create']` por um texto sintético antigo; recarregar.
   Os dados/fotos devem voltar e o token no input deve continuar sendo o do HTML
   novo (comparar localmente, não copiar token para relatório). Salvar cadastro e
   abrir aceite: não deve aparecer “Token de segurança inválido”. Finalizar somente
   até a trava de escrita MkAuth descrita abaixo.
2. **3+ fotos de celular:** escolher ao menos três fotos grandes, aguardar a fila
   estabilizar e registrar tamanhos individuais/total exibidos. Tentar enviar enquanto
   prepara: deve avisar para aguardar. Após preparar, se estiver dentro dos limites,
   deve abrir aceite e conservar fotos. Um lote acima do total deve ficar na página
   com aviso específico, sem POST. Aumentar lote só para testar o bloqueio; não
   reduzir evidências necessárias para contornar o limite.
3. **Logout em outra aba:** com rascunho salvo, abrir outra aba na MESMA porta e
   sair. Na primeira, enviar cadastro; deve ir ao login com mensagem clara e gerar
   `case=unauthenticated`. Entrar e abrir Novo Cliente na mesma origem: recuperar
   dados locais sem restaurar token antigo. Repetir no aceite se necessário.
   Não há modal de relogin nem retomada automática nesta etapa.
4. **Aceite com assinatura:** chegar ao aceite com fotos já salvas, tentar concluir
   sem marcar aceite/sem assinatura: os validadores devem impedir. Marcar e desenhar,
   esperar o salvamento local; recarregar e verificar assinatura/dados restaurados.
   Concluir: não deve haver falha de CSRF; a finalização deve PARAR na trava de escrita
   MkAuth, sem alterar o .88. Não considerar o cadastro concluído no MkAuth.
5. **Quota/erro local:** no DevTools, simular falha de Storage.setItem apenas para
   a chave do rascunho (ou restringir armazenamento desta origem). Ao editar/enviar,
   deve aparecer alerta dentro do formulário; submit fica na página. Desfazer a
   simulação e tentar novamente: deve salvar e prosseguir. Não limpar todos os
   dados do navegador, pois isso apagaria o rascunho que se está validando.
6. **Diagnóstico servidor:** com login, DevTools pode alterar apenas o input `_csrf`
   para texto sintético: POST deve registrar `case=invalid_csrf`, sem PII/token no
   log. Para validar A2 acima do limite apesar do bloqueio JS, usar request sintético
   pelo DevTools, sem dados pessoais e sem token válido, e corpo acima do limite;
   deve retornar 413, “Envio maior que o limite”, antes do controller. Não alterar
   limites PHP/Apache. O teste automatizado cobre também a rota de aceite.

Coletar somente resultado, status HTTP e tamanho; não copiar tokens/CPF/fotos para logs.
Os passos de cadastro/aceite escrevem rascunhos/evidências no storage próprio e
podem registrar auditoria normal no banco compartilhado do .89; não executá-los
automaticamente. Não haverá escrita no MkAuth devido à trava obrigatória.

Comandos de inspeção do .86, para execução manual pelo usuário:
[CADASTRO_VERIFICACAO_86.md](CADASTRO_VERIFICACAO_86.md).
