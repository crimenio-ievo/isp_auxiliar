# Status da rodada — 2026-10-02

Branch `feature/redesign-shell-fase1`. Base da rodada: `4ff741c`. Nada foi enviado para produção, nem feito push/merge.

## Commits

| # | Hash | Item |
|---|---|---|
| 1 | `1302b5f` | Ficha do cliente: esconde "Ver documentos" de quem não tem permissão |
| 2 | `1f1b618` | Logs: categorização por significado, dicionário único e `/logs` restrita a admin/gestor |
| 3 | `8ab9a1c` | Configurações: Email/Evotrix passam a ser guardados em `provider_settings` |

## O que mudou

1. **"Ver documentos"** só aparece com a mesma permissão do botão "Ver contrato assinado"
   (gestor/admin/`can_access_contracts`/`can_view_contracts`), igual à checagem de `/contratos/detalhe`.
2. **Logs** (`docs/PROPOSTA_LOGS.md`):
   - `AuditEventCatalog` é o dicionário único (texto + categoria). Substitui as 3 traduções divergentes
     (tela `/logs`, linha do tempo do cliente, aba de logs do contrato); os textos que já existiam foram preservados.
   - `/logs` tem as 6 categorias da proposta + "Outros eventos", filtro `?categoria=`, e junta `audit_logs`,
     `notification_logs` e o final dos dois arquivos técnicos (`mkauth-write-guard.log`, `client-plan-confirmation.log`).
   - O nível `INFO` inventado saiu: aparece "Falha" (deduzida do nome do evento / `status=erro`) ou a categoria.
   - Permissão: `AccessControl::isGestor` (admin **ou** gestor); os demais são redirecionados. Link "Logs" no menu para esse perfil.
3. **Email/Evotrix → `provider_settings`**: uma linha por campo (`email.smtp_host`, `evotrix.token`…), segredos
   marcados `is_secret`. Valores inalterados. `scripts/migrate_integration_settings.php` copia o que está no
   `storage/contracts/config.json` (simulação por padrão; `--apply` grava; idempotente; não mexe no arquivo).

## Testes

Suíte completa (`tests/AcceptanceResendRegression.php` + `tests/Feature/*.php`): **25 de 27 passam**.
Passaram, entre outros: AcceptanceResendRegression (17), AcceptanceSignatureFormatRegression (19),
AcceptanceTokenRotationRegression (9), ContractTemplateResendRegression (19), ClientModalPendingSmoke (27),
FinalBetaSmoke (32), FourthIterationSmoke (55), ContractSettingsPersistenceRegression (15, ajustado),
**LogCategorizationRegression (22, novo)**, **IntegrationSettingsStoreRegression (8, novo)**.

Falham (**já falhavam antes desta rodada**, confirmado sem as minhas mudanças):
`ReleaseChannelHeaderSmoke` ("Ambiente com dois destinos não exibe o canal Beta") e
`ReleaseSmokeEnvironmentSmoke` (verificação 25) — mesma causa do banner Beta removido que corrigi em
ClientModalPendingSmoke/FinalBetaSmoke/FourthIterationSmoke.

Validação ao vivo (banco local, sessão simulada):
- Item 1: operador com `can_search_clients` sem contratos → botão oculto; com `can_view_contracts` → aparece.
- Item 2: admin 200 com todas as categorias e contagens; operador sem perfil → 302 `/dashboard`; sem login → 302 `/login`.
- Item 3 (arquivo legado com valores distintos dos padrões): configuração efetiva **antes** (arquivo) e **depois**
  (só `provider_settings`, arquivo removido) **idêntica** (`diff` vazio, tipos preservados). Salvar pelo
  `SettingsController` (POST email e evotrix com senha/token em branco) → processo novo carrega os valores salvos,
  senha e token mantidos, abas mostram os valores. Banco e arquivo restaurados ao estado inicial (0 linhas, sem `config.json`).

## Pendências e dúvidas (precisam de decisão sua)

1. **2 smokes quebrados** (acima): atualizar para o banner Beta removido, como feito nos outros 3? Não mexi por estar fora do pedido.
2. **Deploy do item 3**: rodar `php scripts/migrate_integration_settings.php --apply` na produção (com sua autorização)
   *antes* de salvar qualquer coisa pela tela. Até lá o arquivo continua valendo como fallback, então nada quebra.
   Depois de salvar no código novo, os valores ficam só no banco: **voltar ao código antigo faria o sistema ler o
   arquivo (valores velhos)**. Considere isso no plano de rollback.
3. **Itens que continuam no arquivo** (próximos passos da proposta): `commercial`, `mkauth_ticket`, `system` e o carimbo
   "salvo em/por".
4. **Categorias de Logs a confirmar**: "Termo assinado consultado por operador" foi para *Acesso e segurança*;
   `clients_v2.list_failed` ficou em *Outros eventos*; chamados (`*.ticket.*`) ficam só em *Chamados financeiros*,
   mesmo os que nascem de um contrato.
5. `notification_logs` não tem `provider_id`, então a lista de mensagens em `/logs` é global (como já era nas outras telas).
   Relevante só para o cenário multiempresa compartilhado.
6. `logs/app.log` / `LOG_CHANNEL` no `.env` (item 7 da proposta) continua sendo configuração morta; não removi nada.
7. "Ver documentos" foi escondido, mas a lista "Contrato #N" abaixo dele ainda leva ao mesmo `/contratos/detalhe`
   para quem não tem permissão (redireciona ao clicar). Escondo também?
8. `release()` usa `is_admin`, e `/logs` usa admin **ou** gestor (como você pediu). Se a intenção era o mesmo critério estrito, é uma linha.
9. Os testes ao vivo do botão "Ver contrato assinado" deixaram ~6 linhas `contract.signed_term.operator_viewed` em `audit_logs` do banco local.
