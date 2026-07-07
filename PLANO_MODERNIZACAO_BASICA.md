# PLANO_MODERNIZACAO_BASICA

**Diretório antigo preservado:** isp_auxiliar_old_20260612_1815

**Branch base atual (novo clone):** main

**Remote usado:** git@github.com:crimenio-ievo/isp_auxiliar.git

## Arquivos apenas no diretório antigo (exemplos relevantes)
Only in isp_auxiliar_old_20260612_1815: .env
Only in isp_auxiliar_old_20260612_1815: AUDITORIA_GERAL.md
Only in isp_auxiliar_old_20260612_1815: RELATORIO_CSRF_COBERTURA.md
Only in isp_auxiliar_old_20260612_1815: RESUMO_ALTERACOES_PRODUCAO.md

## Arquivos que diferem entre antigo e novo (exemplos relevantes)
isp_auxiliar_old_20260612_1815/.git/FETCH_HEAD
isp_auxiliar_old_20260612_1815/.git/ORIG_HEAD
isp_auxiliar_old_20260612_1815/.git/config
isp_auxiliar_old_20260612_1815/.git/index
isp_auxiliar_old_20260612_1815/.git/logs/HEAD
isp_auxiliar_old_20260612_1815/.git/logs/refs/heads/main
isp_auxiliar_old_20260612_1815/AGENTS.md
isp_auxiliar_old_20260612_1815/backend/Controllers/AuthController.php
isp_auxiliar_old_20260612_1815/backend/Controllers/ClientController.php
isp_auxiliar_old_20260612_1815/backend/Controllers/ContractController.php
isp_auxiliar_old_20260612_1815/backend/Controllers/DashboardController.php
isp_auxiliar_old_20260612_1815/backend/Controllers/SettingsController.php
isp_auxiliar_old_20260612_1815/backend/Controllers/SystemController.php
isp_auxiliar_old_20260612_1815/backend/Core/AccessControl.php
isp_auxiliar_old_20260612_1815/backend/Core/Application.php
isp_auxiliar_old_20260612_1815/backend/Infrastructure/Contracts/ContractRepository.php
isp_auxiliar_old_20260612_1815/backend/Infrastructure/Local/LocalRepository.php
isp_auxiliar_old_20260612_1815/backend/Infrastructure/MkAuth/MkAuthDatabase.php
isp_auxiliar_old_20260612_1815/backend/Views/auth/login.php
isp_auxiliar_old_20260612_1815/backend/Views/clients/acceptance.php
isp_auxiliar_old_20260612_1815/backend/Views/clients/connection.php
isp_auxiliar_old_20260612_1815/backend/Views/clients/create.php
isp_auxiliar_old_20260612_1815/backend/Views/contracts/acceptance.php
isp_auxiliar_old_20260612_1815/backend/Views/contracts/aceites_pendentes.php
isp_auxiliar_old_20260612_1815/backend/Views/contracts/detalhe.php
isp_auxiliar_old_20260612_1815/backend/Views/contracts/index.php
isp_auxiliar_old_20260612_1815/backend/Views/contracts/novos.php
isp_auxiliar_old_20260612_1815/backend/Views/contracts/termo.php
isp_auxiliar_old_20260612_1815/backend/Views/dashboard/index.php
isp_auxiliar_old_20260612_1815/backend/Views/installations/index.php
isp_auxiliar_old_20260612_1815/backend/Views/layouts/app.php
isp_auxiliar_old_20260612_1815/backend/Views/layouts/sidebar.php
isp_auxiliar_old_20260612_1815/backend/Views/logs/index.php
isp_auxiliar_old_20260612_1815/backend/Views/settings/index.php
isp_auxiliar_old_20260612_1815/backend/Views/users/index.php
isp_auxiliar_old_20260612_1815/backend/bootstrap/app.php
isp_auxiliar_old_20260612_1815/backend/routes.php
isp_auxiliar_old_20260612_1815/public/assets/css/app.css
isp_auxiliar_old_20260612_1815/public/assets/js/app.js
isp_auxiliar_old_20260612_1815/public/index.php

## Candidatos a reaproveitamento (sugestão inicial)
- `backend/Views/layouts/v2/components.php` (layout v2)
- `backend/Views/layouts/app.php`
- `public/assets/css/app-layout-v2.css` (se existir)
- `public/assets/js/app-layout-v2.js` (se existir)
- `backend/Views/clients/*` (index, create, detail, evidence, acceptance)
- `backend/Controllers/ClientController.php`
- `backend/Controllers/ContractController.php`
- `backend/Controllers/AcceptanceController.php`
- `backend/Views/contracts/*`
- `storage/uploads/clientes/` (evidências) — copiar manualmente do diretório antigo
- `.env` (copiar manualmente do diretório antigo)

## Arquivos que NÃO devem ser reaproveitados
- `backend/Controllers/ChamadosV2Controller.php` e `backend/Views/chamados-v2/*`
- `backend/Controllers/AppV2Controller.php` e `backend/Views/app-v2/*`
- `isp_map*/`, `isp_net_manager/`, `isp_map2/`
- `backend/Core/ModuleGate.php` e alterações que introduzam feature flags complexos
- Telemetria e módulos experimentais

## Riscos
- Login (AuthController / LocalRepository) — testar autenticação
- Fluxo de cadastro de cliente (ClientController) — testar rascunho, upload, envio
- Aceite e contratos — testar geração de evidências, assinatura e reenvio
- Uploads e permissões de pasta — verificar `storage/uploads` e `storage/sessions`

## Checklist de testes
- [ ] `php -l` nos arquivos alterados
- [ ] Testar `login` via HTTPS
- [ ] Abrir `dashboard` e navegar para `Clientes`
- [ ] Criar novo cliente e fazer upload de fotos
- [ ] Realizar aceite e criar contrato
- [ ] Verificar logs e provider settings

## Plano de aplicação (arquivo por arquivo)
- Revisar `backend/Views/layouts/v2/components.php` e adaptar menu para o conjunto mínimo.
- Trazer `backend/Views/layouts/app.php`, `header.php`, `footer.php` (apenas assets e markup necessários).
- Trazer `backend/Controllers/ClientController.php` e `backend/Views/clients/*` com testes locais.
- Trazer `backend/Controllers/ContractController.php`, `AcceptanceController.php` e `backend/Views/contracts/*`.
- Copiar `.env` e `storage/uploads/clientes` do diretório antigo para o novo clone (manual, sem sobrescrever código).
- Rodar `php -l` e testes manuais.

