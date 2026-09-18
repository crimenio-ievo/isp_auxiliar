# ISP_Auxiliar — instruções para Claude Code

## Contexto técnico
- PHP 8.2+ puro, JavaScript e CSS sem framework pesado (sem Laravel/Symfony/React/Vue/npm/Vite).
- Micro-framework MVC próprio em `backend/Core/` (Router, Application, Request, Response, Container).
- Não introduza Laravel, React, Vue, npm/Vite etc. apenas por "modernização" — só se houver benefício concreto e autorização explícita.

## Regras de trabalho
- Entender antes de alterar: ler o código relevante e o histórico antes de propor mudança.
- Preservar a regra de negócio existente. Mudanças pequenas, testáveis e reversíveis — evitar refatoração ampla sem autorização explícita.
- MkAuth (`backend/Infrastructure/MkAuth/`) é a integração mais crítica do sistema (billing/provisionamento externo) — cautela redobrada e teste antes de qualquer mudança ali.
- Código antigo só deve ser removido quando houver evidência concreta de que não é utilizado (grep/git log confirmando ausência de referências), nunca por suposição.
- `isp_map`, `isp_map2`, `isp_map_gis` e `isp_net_manager` são projetos separados — não presuma integração ou incorpore código deles automaticamente.
- Prefira simplicidade e benefício concreto a reorganização por preferência estética.

## Git e segurança
- Rodar `git status` antes de qualquer mudança; nunca sobrescrever trabalho não commitado.
- Nunca ler `.env` ou seus backups sem necessidade explícita.
- Nunca versionar dados de runtime ou credenciais (`.env*`, `storage/` operacional, `backups/`, `logs/`, `tmp/`).
- Nunca alterar banco de dados ou executar migrations sem autorização explícita.

## Testes
- `tests/Feature/*.php` e `tests/AcceptanceResendRegression.php` são scripts procedurais (não PHPUnit) — rodar via `php tests/Feature/Arquivo.php`.
- Executar os smoke/regression tests relacionados ao módulo alterado após qualquer mudança.

## Comunicação
- Responder em português do Brasil.
- Distinguir explicitamente fato observado, hipótese e estimativa.
