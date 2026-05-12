# Deploy de Produção

## Objetivo

Este guia cobre instalação nova, atualização segura e diagnóstico básico do `ISP_AUXILIAR`.

## Instalação nova

1. Clonar o repositório.
2. Copiar `.env.example` para `.env` e revisar `APP_URL`, `APP_PROVIDER_KEY`, `DB_*`, `MKAUTH_*`, `SMTP_*` e `EVOTRIX_*`.
3. Garantir permissões de escrita em `storage/`, `logs/` e `backups/`.
4. Executar as migrations oficiais:
   - `php scripts/apply_migrations.php`
5. Garantir um administrador local ou remoto:
   - `php scripts/ensure_admin.php crimenio`
6. Validar o ambiente:
   - `php scripts/check_install.php crimenio`

## Atualização segura

1. Fazer backup do banco e do storage operacional.
2. Aplicar a atualização do código:
   - `git pull origin main`
3. Rodar migrations pendentes:
   - `php scripts/apply_migrations.php`
4. Garantir acesso administrativo:
   - `php scripts/ensure_admin.php crimenio`
5. Conferir o ambiente:
   - `php scripts/check_install.php crimenio`
6. Recarregar o Apache:
   - `bash scripts/deploy_update.sh crimenio`

## Produção real

Antes de liberar o piloto real:

- `APP_URL` deve apontar para o domínio público final.
- `SMTP` deve estar autenticado e com remetente válido.
- `Evotrix` deve estar configurado e com teste/produção conforme o cenário.
- `MkAuth` deve estar configurado com base URL, API e banco remoto.
- `MKAUTH_TICKET_MESSAGE_FALLBACK` deve ficar ativo se o MkAuth não gravar a mensagem inicial.
- As permissões locais e a detecção automática de admin pelo MkAuth devem estar validadas.
- A tela de usuários deve mostrar a origem da permissão: `Local` ou `Admin detectado pelo MkAuth`.

## Scripts oficiais

- `php scripts/apply_migrations.php`
- `php scripts/ensure_admin.php crimenio`
- `php scripts/check_install.php crimenio`
- `bash scripts/deploy_update.sh crimenio`

## O que cada script faz

### `scripts/apply_migrations.php`

- Cria `schema_migrations` se precisar.
- Lê `database/migrations/*.sql`.
- Aplica somente migrations pendentes.
- Registra `filename`, `checksum` e `applied_at`.
- Ignora migrations já aplicadas.

### `scripts/ensure_admin.php`

- Normaliza o login informado.
- Cria o provider atual se ele ainda não existir.
- Mescla o login nas permissões simples do provider.
- Mostra o resultado de `accessForLogin(login)`.

### `scripts/check_install.php`

- Mostra diagnóstico do `APP_ENV`, `APP_URL`, provider, banco, permissões e integrações.
- Indica se o ambiente está em localhost ou IP.
- Mostra migrations aplicadas e pendentes.

### `scripts/deploy_update.sh`

- Faz `git pull origin main`.
- Executa migrations.
- Garante admin.
- Ajusta permissões de diretório.
- Roda o diagnóstico final.
- Recarrega o Apache.

## Observação

O fluxo operacional continua dependente de configurações válidas de banco, `APP_URL`, `SMTP`, `Evotrix` e `MkAuth`. Este guia não altera o banco do MkAuth nem cria cobrança.
