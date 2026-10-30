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
