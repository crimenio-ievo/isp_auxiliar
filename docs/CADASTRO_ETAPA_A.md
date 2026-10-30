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
