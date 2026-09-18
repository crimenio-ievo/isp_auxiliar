# Quarta iteração — testes e verificações

Data original: 2026-08-03. Revalidação corretiva: 2026-08-04. Banco local de
homologação. Nenhuma migration nova foi necessária; `provider_settings` foi
reutilizada para a preferência Stable/Beta.

## Suites Feature

```bash
php tests/Feature/OperationalProcessSmoke.php
php tests/Feature/UpgradeCorrectionSmoke.php
php tests/Feature/ThirdIterationSmoke.php
php tests/Feature/FourthIterationSmoke.php
```

Resultados atuais:

- processos operacionais: código 0, 62 verificações e rollback;
- correção de Upgrade/Migração: código 0, 40 verificações e rollback;
- terceira iteração: código 0, 40 verificações;
- quarta iteração: código 0, exatamente 57 categorias e rollback.

Total: 199 verificações informadas pelas suites. Os logs de bloqueio do
`MkAuthWriteGuard` são o resultado esperado. Nenhuma suite enviou notificação ou
fez escrita externa.

O runner canônico retornou código 0: 0 migrations aplicadas, 11 já aplicadas,
0 avisos e 0 erros. Como não houve migration nova, rollback estrutural não se
aplica nesta iteração.

## Cobertura das 57 categorias novas

- estados 1–9: painel único, espera, cancelamento, histórico, terminal,
  progresso e pré-requisitos do aceite;
- planos 10–19: seleção, validação, mesmo plano, label/metadados, cálculos,
  preservação e foco;
- adesão 20–24: configuração, rádio → fibra, benefício, ausência de hardcode e
  modo desabilitado;
- retenção/fidelidade 25–29: separação, campos desabilitados, benefício, prazo e
  labels, incluindo preservação e rejeição de prazo fora de 1–12;
- correção 30–34: revisão no mesmo processo, não duplicação, revogação,
  substituição e novo processo após cancelamento;
- interface 35–42: telefones, modal, fechamento/foco, alerta único, contrato e
  eventos traduzidos; o ciclo de Tab fica restrito ao diálogo central;
- Stable/Beta 43–49: padrão, permissão, URL configurada, auditoria, banners e
  bloqueio de open redirect;
- compatibilidade 50–57: registros antigos, instalação, assinatura, MkAuth,
  notificações e ausência de IA.

## Verificações técnicas

Executar na revisão final:

```bash
php scripts/apply_migrations.php
php -l <cada PHP alterado ou novo>
node --check public/assets/js/app.js
git diff --check
git status --short
```

PHP lint dos arquivos alterados/novos, `node --check` e `git diff --check`
retornaram código 0. Também foram inspecionados rotas, permissões, templates,
chamadas externas, flags efetivas e arquivos rastreados. O teste HTTP local
retornou 302 na raiz e 200 em `/login`.

Uma revisão posterior da própria entrega acrescentou regressões para impedir
normalização silenciosa do prazo, rejeitar metadados derivados adulterados e
exigir CSRF nas ações de cancelar/substituir Upgrade / Migração.

Na revalidação de 2026-08-04, as quatro suites repetiram os mesmos resultados
(199 verificações), o runner confirmou as 11 migrations já aplicadas, 23
arquivos PHP passaram no lint, e `node --check` e `git diff --check` retornaram
código 0. O HTTP local da aplicação em `/isp_auxiliar/public/` retornou 302 para
o login e `/isp_auxiliar/public/login` retornou 200. O processo de homologação
25 permaneceu cancelado, com contrato cancelado, aceite revogado, 3 etapas
concluídas, 8 canceladas e nenhuma próxima pendência.

As flags efetivas permaneceram em `APP_ENV=local`,
`MKAUTH_WRITE_ENABLED=false`, e-mail/Evotrix/chamado MkAuth em dry-run e canal
Stable. Nenhum analisador estático PHP dedicado está instalado no projeto; a
checagem disponível foi composta por lint, suites, inspeção de fontes e teste
HTTP.

## Limitações

Não há navegador automatizado instalado no host; screenshots e jornadas por
viewport não foram produzidas. Permanecem testes visuais a 100%, teclado/leitor
de tela e aparelhos Android/iOS reais. A aprovação é de continuação da
homologação, não de produção.

A inspeção de segredos identificou valores não vazios preexistentes nas chaves
`email.smtp_password` e `evotrix.token` de `storage/contracts/config.json`. Eles
já existiam no commit de referência e não foram adicionados por esta branch,
mas devem ser rotacionados e retirados do arquivo rastreado em uma atividade de
segurança separada. Os valores não foram copiados para código, documentação ou
relatório.
