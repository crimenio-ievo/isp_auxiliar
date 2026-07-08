# Relatório de Correção Pós-Deploy

## Contexto

Comparação entre:

- teste: `/var/www/html/isp_auxiliar`
- cópia da produção: `/var/www/html/isp_auxiliar_producao`

## Diagnóstico

Os arquivos versionados relevantes estavam iguais entre teste e produção:

- `public/assets/js/app.js`
- `public/assets/css/app.css`
- `backend/Views/clients/index.php`
- `backend/Views/clients/upgrade.php`
- `backend/Controllers/ClientController.php`
- `backend/config/email.php`
- `backend/config/evotrix.php`

O único arquivo com diferença local confirmada na cópia da produção foi:

- `storage/contracts/config.json`

Essa diferença é de configuração local e não faz parte do código versionado.

## Causa da busca automática não disparar

A busca automática existe em `public/assets/js/app.js`, com listener em `data-client-search-input` e requisição AJAX para `/clientes/buscar`.

O problema observado após o deploy foi compatível com cache de assets:

- o layout ainda apontava para `app.js?v=20260429a`
- o CSS ainda apontava para `app.css?v=20260612a`

Como esses sufixos de versão não mudavam, o navegador podia continuar usando JS/CSS antigos, o que explicava:

- busca automática não disparar
- atualização visual não aparecer
- comportamento do upgrade continuar parecendo o de antes

## Causa do valor mensal ficar `R$ 0,00`

O upgrade depende do JavaScript atualizado para ler:

- `data-upgrade-monthly-value`
- `data-upgrade-technology`

Com asset cacheado, o navegador podia carregar uma versão anterior do JS sem a lógica atual de preenchimento automático.

## Causa da nova tecnologia ficar vazia

Mesma raiz do item anterior: o valor vem do JS que lê o `option` selecionado. Se o navegador usar JS antigo, a tecnologia não é preenchida.

## Correção aplicada no teste atual

Atualizei apenas o layout de assets para forçar recarregamento:

- `backend/Views/layouts/app.php`

Mudanças:

- `app.css?v=20260612a` -> `app.css?v=20260708a`
- `app.js?v=20260429a` -> `app.js?v=20260708a`

Isso força a entrega das versões atuais de CSS e JS sem alterar regra de negócio.

## Testes executados

- `php -l backend/Views/layouts/app.php`
- `node --check public/assets/js/app.js`
- `git diff --check`
- comparação de arquivos entre teste e produção com `cmp`

## Riscos pendentes

- Se houver proxy/CDN intermediário, pode haver necessidade de nova limpeza de cache.
- A cópia de produção ainda mantém `storage/contracts/config.json` diferente, por ser configuração local.

## Próximos comandos sugeridos

1. `git status --short`
2. `git add backend/Views/layouts/app.php RELATORIO_CORRECAO_POS_DEPLOY.md`
3. `git commit -m "fix: atualizar cache busting dos assets"`
4. `git push origin main`
5. Atualizar a produção a partir do repositório

