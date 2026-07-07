# PLANO DEPLOY PRODUCAO

## 1. Conclusao objetiva

Nao encontrei automacao de deploy via GitHub Actions, Makefile ou pasta `deploy/`.
O caminho operacional existente e um script local em `scripts/deploy_update.sh`, apoiado por utilitarios de migracao, backup e checagem.

O ponto importante e que o script atual foi escrito para atualizar a `main`:

- faz `git pull origin main`
- roda migrations
- ajusta permissao de pastas
- roda checagem de instalacao
- recarrega Apache

Para subir a branch `modernizacao-basica` em producao, esse passo de `git pull` precisa ser tratado com cuidado, porque o script nao esta parametrizado para outra branch.

## 2. O que existe hoje

### Metodo atual de deploy

- `scripts/deploy_update.sh`

Fluxo atual desse script:

1. `git pull origin main`
2. `php scripts/apply_migrations.php`
3. `php scripts/ensure_admin.php "<login>"`
4. ajuste de permissao em `storage`, `logs` e `backups`
5. `php scripts/check_install.php "<login>"`
6. reload do Apache

### Comandos disponiveis

No `scripts/console.php`:

- `about`
- `routes`
- `db:create`
- `migrate`
- `user:create-manager`
- `settings:sync-env`

Outros utilitarios relevantes:

- `scripts/apply_migrations.php`
- `scripts/backup.sh`
- `scripts/ensure_admin.php`
- `scripts/check_install.php`
- `scripts/install_production.sh`
- `scripts/deploy_update.sh`
- `scripts/reset_test_operational_data.php`
- `scripts/test_contract_artifacts.php`

### Backup automatico

Nao existe backup automatico agendado ou orquestrado pelo projeto.

Existe um script manual:

- `scripts/backup.sh`

Ele faz:

- tar de `storage/`
- dump do banco com `mysqldump`
- copia do `.env`

Mas ele nao e chamado automaticamente pelo deploy atual.

### Rollback

Nao existe rollback automatizado.

O rollback pratico hoje depende de:

- backup anterior de `storage/`, banco e `.env`
- revert manual da branch/commit
- reaplicacao de migrations apenas se houver estrategia externa para isso

### Aplicacao de migrations

Ha duas entradas:

- `php scripts/console.php migrate`
- `php scripts/apply_migrations.php`

O aplicador oficial e `scripts/apply_migrations.php`.
Ele:

- cria/usa `schema_migrations`
- compara checksum
- executa apenas migrations nao aplicadas
- registra status e checksum
- evita reaplicar arquivo ja aplicado

## 3. Arquivos e dados que nao devem ser sobrescritos

Nao sobrescrever:

- `.env`
- `storage/`
- `backups/`
- `logs/`
- `tmp/`
- uploads e evidencias geradas em runtime
- base local complementar do sistema
- `schema_migrations`

Ao fazer update de codigo, o cuidado principal e manter intactos:

- credenciais do ambiente
- evidencias e anexos
- logs operacionais
- dados locais de configuracao e auditoria

## 4. Sinais de ausencia de automacao moderna

Nao ha:

- `Makefile`
- pasta `deploy/`
- workflows em `.github/workflows/`

Ou seja, nao existe pipeline CI/CD neste repositorio neste momento.

## 5. Passo a passo seguro para producao

### Antes de mexer

1. Confirmar branch alvo em producao.
2. Fazer backup manual com `scripts/backup.sh`.
3. Validar que o backup inclui:
   - `storage/`
   - dump do banco
   - `.env`
4. Conferir se ha espaco em disco suficiente.

### Atualizacao de codigo

5. Trazer a branch correta do remoto.
6. Garantir que a versao em producao esta na branch `modernizacao-basica`.
7. Conferir `git status --short` antes de prosseguir.

### Migracoes e validacao

8. Executar `php scripts/apply_migrations.php`.
9. Rodar `php scripts/check_install.php <login>`.
10. Se necessario, rodar `php scripts/ensure_admin.php <login> --local-password='...'`.

### Finalizacao

11. Ajustar permissoes de pasta se necessario.
12. Recarregar Apache.
13. Validar login, cadastro e contrato/aceite no navegador.

## 6. Observacao importante para a branch modernizacao-basica

O deploy atual esta amarrado na `main`.
Para a branch `modernizacao-basica`, o procedimento seguro e substituir esse passo por uma atualizacao explicita da branch correta, sem misturar com producao antes do backup:

- `git fetch`
- `git checkout modernizacao-basica`
- `git pull origin modernizacao-basica`

Isso deve ser feito apenas no momento do deploy, depois do backup e da confirmacao de que a branch e a desejada.

## 7. Resposta direta

1. Metodo atual de deploy: script local `scripts/deploy_update.sh`.
2. Comandos existentes: `about`, `routes`, `db:create`, `migrate`, `user:create-manager`, `settings:sync-env`, mais os scripts auxiliares citados acima.
3. Backup automatico: nao.
4. Rollback: nao automatizado.
5. Como aplicar migrations: `php scripts/apply_migrations.php` ou `php scripts/console.php migrate`.
6. Nao sobrescrever: `.env`, `storage/`, `backups/`, `logs/`, `tmp/` e evidencias.
7. Passo seguro: backup completo, trocar para a branch certa, aplicar migrations, checar instalacao, validar, e so depois recarregar o servidor web.
