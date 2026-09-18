# Pré-Maps V0 — conciliação para homologação

Branch: `release/pre-maps-v0`. Integração por merge normal de `origin/main`
(`73a95b2e6adae4435d23f94cb9b4304c3317e017`) com a candidata preservada
`simplificacao/etapa3-limpeza` (`24d14f5286b674972469d9e37e93d3bc2baf0fc3`).
O hash integrado é o commit de merge desta entrega, consultável por
`git rev-parse release/pre-maps-v0`; não há tag final nem autorização de deploy.

## Evidência e decisões do merge

`git cherry simplificacao/etapa3-limpeza origin/main` confirmou equivalência
dos patches `ebe2b2c`, `dbd137b`, `dea12b6`, `73a95b2`, respectivamente com
`9ed8e00`, `5c5e0de`, `f66a7e2`, `e40f04e` na candidata. As árvores de
`73a95b2` e `e40f04e` diferem apenas no relatório pós-deploy e nos sufixos
de versão de assets do commit `5d4ec85`. Esses sufixos fixos foram substituídos
pelo versionamento dinâmico da candidata; o relatório histórico foi preservado.

Os caminhos abaixo são relativos a `backend/`, exceto o teste indicado.

| Arquivo conflitante | Decisão aplicada |
| --- | --- |
| Controllers/AcceptanceController.php | Serviço de evidências PNG/JPEG, histórico aceito, estados indisponíveis e transação da candidata; removidas duplicações do merge automático. |
| Controllers/ClientController.php | Processos operacionais, retomada, revisão, CSRF, validação de catálogo e transações da candidata; fluxo antigo substituído. |
| Controllers/ContractController.php | Reenvio de expirados permitido para rotação; corrigido o hash persistido na renovação. |
| Infrastructure/Local/LocalRepository.php | Permissões existentes e autorização Beta da candidata. |
| Infrastructure/MkAuth/MkAuthDatabase.php | Write guard preservado, timeout limitado e catálogo ampliado da candidata. |
| Views/clients/detail.php | Perfil e ações dos processos atuais, confirmação de envio e CSRF da candidata. |
| Views/clients/upgrade.php | Jornada atual, validação no backend e ações no mesmo processo; sem restaurar formulário antigo. |
| Views/contracts/acceptance.php | Estado indisponível genérico, saída escapada e tela atual de confirmação. |
| Views/contracts/detalhe.php | Benefícios atuais, tipos de operação e CSRF; sem botões duplicados. |
| Views/layouts/app.php | Cache busting dinâmico substitui sufixos fixos de julho. |
| bootstrap/app.php | Serviços atuais, guard e timeout coerentes com construtores. |
| routes.php | Registro único de rotas ativas; dois arquivos de rotas mortas continuam removidos. |
| tests/Feature/UpgradeCorrectionSmoke.php (raiz) | Teste atualizado da candidata: catálogo/tecnologia, permissões, revisão e links revogados. |

Não foi necessária uma correção exclusiva de código da main: seus quatro
patches funcionais já estavam incorporados na linhagem da candidata.

## Correção e validação de 2026-09-18

O teste novo `AcceptanceTokenRotationRegression` reproduziu falha anterior à
correção: o repositório priorizava o hash antigo em vez do novo token, levando
ao rollback da renovação. Uma linha em `ContractController` atualiza também
`token_hash`. Controller e repositório reais são exercitados com PDO fake;
renovação, reenvio válido, aceite concluído, cancelamento e revogação passam.

- Lint: 87 PHP distintos, incluindo os dois testes novos; JS `node --check` OK.
- 16 smokes + 7 regressões: 839 verificações aprovadas.
- Rotas: sem duplicação método/caminho, ações existentes; proteção de endpoints
  operacionais sem sessão e CSRF válido/inválido/escopo cruzado exercitados.
- PNG/JPEG, persistência atômica de configuração, templates, permissões,
  Upgrade/Migração e MkAuth simulado aprovados.
- Execução em cópia temporária apenas dos arquivos versionados, com storage
  separado e runner que força modo test, dry-run e IA desativada.
- Banco local: 18 registros em schema_migrations, zero pendentes e zero
  divergências de checksum. Migrations 016/017 são aditivas no auditor e não
  mudaram em relação à candidata; nenhuma migration foi executada.
- Nenhum marcador de conflito; `git diff --check` aprovado.

## Limites e próximos passos

Os testes de transação usam rollback local; o fake de token não comprova
concorrência entre conexões reais. A aprovação visual, sessão em navegador e
integrações reais continuam dependentes de homologação autorizada.

Antes de produção ainda é necessário concluir a preparação operacional de
release (procedimento canônico, backup/restauração, destino e hash publicado),
e confirmar a situação da credencial histórica mencionada na documentação.
Esta conciliação não comprova rotação nem autoriza habilitar operações externas.
O relatório pós-deploy preservado é histórico, não um roteiro desta release.

Backups e evidências locais permanecem fora do Git, cobertos pelo .gitignore.
ISP MAP, telemetria e IA não integram esta entrega. Produção não foi alterada.
