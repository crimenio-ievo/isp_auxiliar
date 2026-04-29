# Deploy de Producao

## Objetivo

Este checklist ajuda a instalar o `isp_auxiliar` em outro servidor com risco reduzido.

## Antes de publicar

- Confirmar PHP 8.2 ou superior.
- Confirmar extensoes `curl`, `pdo`, `pdo_mysql`, `json`, `mbstring`, `openssl` e `fileinfo`.
- Confirmar Apache ou Nginx com HTTPS funcionando.
- Confirmar acesso ao MySQL/MariaDB local do servidor.
- Confirmar acesso de rede ao MkAuth.
- Confirmar que o servidor sera usado para teste ou producao controlada.

## Arquivos de configuracao

- Copiar `.env.example` para `.env`.
- Revisar `APP_URL`, `APP_PROVIDER_KEY`, `DB_*` e `MKAUTH_*`.
- Nao publicar com credenciais de teste.

## Instalação

1. Copiar o projeto para o servidor.
2. Ajustar permissões de `storage/`, `logs/` e `tmp/`.
3. Apontar o servidor web para `public/`.
4. Garantir `AllowOverride All` no diretório público.
5. Ativar `rewrite` e `ssl` no Apache, se for o caso.
6. Criar o banco local.
7. Executar as migrations.
8. Criar o primeiro gestor local.
9. Sincronizar configurações iniciais do `.env` para o banco local.
10. Testar login, dashboard e cadastro.

## Scripts Incluidos

- `scripts/install_production.sh`
- `scripts/backup.sh`

## Checklist de validacao

- Abrir `/login` no navegador.
- Validar login de gestor local.
- Validar login de tecnico MkAuth.
- Validar acesso ao dashboard.
- Validar criacao de cliente em ambiente de teste.
- Validar salvamento de evidencias.
- Validar que a tela continua responsiva em celular.

## Backup minimo

- Banco local do `isp_auxiliar`.
- `storage/uploads/clientes/`.
- `storage/installations/`.
- `.env`.

## Risco Operacional

O sistema continua dependente do MkAuth para o cadastro real do cliente e consulta de Radius. Em producao, qualquer alteracao de configuracao deve ser feita primeiro em ambiente de teste.
