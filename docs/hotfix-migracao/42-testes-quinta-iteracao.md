# Testes da quinta iteração

## Automatizados

- `ClientPlanConfirmationSmoke`: 20 assertions de UUID, uma/duas chamadas,
  divergência, timeout, resposta parcial, retry, guard e log sem segredo;
- `FifthIterationSmoke`: 30 verificações correspondentes à jornada solicitada,
  com upload real em diretório temporário, cancelamento/reinício em transação e rollback;
- `OperationalProcessSmoke`: 62 verificações, três tipos de processo e rollback;
- `FourthIterationSmoke`: 57 verificações e rollback;
- `ThirdIterationSmoke`: 40 verificações;
- `UpgradeCorrectionSmoke`: 40 verificações e rollback;
- `ClientModalPendingSmoke`: 28 verificações.
- `ReleaseIsolationSmoke`: 18 verificações de autorização, metadados sem
  credenciais, destinos fechados, sessão e flags Beta.

Todas as suítes usam escrita MkAuth bloqueada. Os testes que exercitam banco
revertem as transações. O teste de evidência remove arquivo e diretórios temporários.

Também são obrigatórios antes de homologar: `php -l` em todos os PHP, `node
--check public/assets/js/app.js`, `bash -n scripts/releases/*.sh`, `git diff
--check`, inspeção das rotas e execução dos scripts de release em `--dry-run`.

## Validação visual

O código define layout responsivo para 1920×1080, 1440×900, 1366×768,
1024×768, 768×1024, 412×915, 390×844 e 360×800. Nesta sessão não havia navegador
automatizado/aparelho físico disponível; portanto os oito viewports e o toque em
aparelho real continuam pendentes. Não há aprovação visual física declarada.
