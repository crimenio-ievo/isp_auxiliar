# Diagnostico do hotfix de migracao

## Base e preservacao

- Repositorio funcional: `/var/www/html/isp_auxiliar`.
- Branch inicial encontrada: `main`.
- Commit inicial encontrado em `main`: `73a95b2e6adae4435d23f94cb9b4304c3317e017`.
- Checkout local identificado como producao: `/var/www/html/isp_auxiliar_producao`.
- Commit do checkout de producao local: `58a08d0fc50681944104aa18cf9cc49f924f974f`.
- Branch do hotfix criada a partir de `58a08d0`: `hotfix/migracao-piloto`.
- Alteracoes de IA estavam no working tree e foram preservadas em `feature/integracao-ia-pausada`, commit local `0f894d5716b73376218ffeacd968ab2b714ebdd7`.

## Evidencia de producao

O checkout `isp_auxiliar_producao` apontava para `58a08d0` em `main`. A documentacao de deploy em `docs/deploy-producao.md` e `scripts/deploy_update.sh` indica atualizacao por `git pull origin main`, `php scripts/apply_migrations.php`, diagnostico e reload do Apache.

Nao foi localizado pipeline automatico no repositorio durante a analise local. A evidencia de producao deve ser confirmada no servidor real antes do deploy, porque o host atual se identifica como `teste.ievo.com.br`.

## Fluxo existente

- Rotas principais:
  - `GET /clientes`, `GET /clientes/buscar`, `GET /clientes/detalhe`.
  - `GET /clientes/upgrade`, `POST /clientes/upgrade`.
  - `POST /clientes/upgrade/cancelar`, `POST /clientes/upgrade/execucao-tecnica`.
  - `GET /aceite/{token}`, `POST /aceite/{token}/confirmar`, `GET /aceite/{token}/termo`.
- Controllers:
  - `ClientController`: pesquisa, detalhe, criacao/correcao/cancelamento do Upgrade / Migracao e execucao tecnica.
  - `AcceptanceController`: tela publica, validacao do token, assinatura, evidencia e fila operacional.
  - `ContractController`: reenvio de aceite, detalhe do contrato e tarefas operacionais.
- Views:
  - `backend/Views/clients/upgrade.php`.
  - `backend/Views/clients/detail.php`.
  - `backend/Views/contracts/acceptance.php`.
  - `backend/Views/contracts/detalhe.php`.
- JavaScript:
  - `public/assets/js/app.js`: assinatura, validacoes, revisao do upgrade, bloqueio de duplo submit e copia de link.
- Tabelas:
  - `client_contracts`.
  - `contract_acceptances`.
  - `financial_tasks`.
  - `notification_logs`.
  - `audit_logs`.

## Causas dos riscos encontrados

- O fluxo antigo podia gerar novo Upgrade / Migracao sem bloquear outro processo em andamento.
- A troca/correcao de upgrade incorreto precisava invalidar o aceite anterior e encerrar pendencias antigas.
- A assinatura mobile dependia de preservacao correta do canvas ao redimensionar e de bloqueio de duplo envio.
- A tela publica do aceite ainda exibia informacao demais para o caso de migracao.
- Escritas reais no MkAuth precisavam de uma trava explicita para evitar alteracao acidental durante piloto/testes.
