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
