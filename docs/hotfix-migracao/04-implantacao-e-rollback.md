# Implantacao e rollback

## Antes de implantar

1. Confirmar o host real de producao.
2. Fazer snapshot/backup do banco local do ISP Auxiliar.
3. Fazer backup de `storage/`, principalmente evidencias e contratos.
4. Confirmar o commit atualmente implantado no servidor real.
5. Confirmar que `MKAUTH_WRITE_ENABLED` esta configurado conforme a politica operacional.

## Aplicacao sugerida por cherry-pick

Na branch de producao, aplicar somente os commits do hotfix:

```bash
git fetch origin
git checkout main
git pull --ff-only origin main
git cherry-pick 9ed8e00 5c5e0de f66a7e2 e40f04e <commit-final-do-hotfix>
php scripts/apply_migrations.php
php tests/Feature/UpgradeCorrectionSmoke.php
php scripts/check_install.php crimenio
```

Substitua `<commit-final-do-hotfix>` pelo commit final criado na branch `hotfix/migracao-piloto`.

## Rollback

Se falhar antes de uso operacional:

```bash
git revert <commit-final-do-hotfix> e40f04e f66a7e2 5c5e0de 9ed8e00
php scripts/apply_migrations.php
```

Se houver dados reais criados no banco local, restaurar o snapshot do banco e do `storage/` feito antes da implantacao.

## Validacao pos-deploy

1. Abrir `/api/health`.
2. Rodar `php scripts/check_install.php crimenio`.
3. Abrir `/clientes` com usuario tecnico autorizado.
4. Criar uma migracao piloto controlada.
5. Confirmar aceite pelo celular.
6. Confirmar que o checklist tecnico aparece e que nao houve escrita real inesperada no MkAuth.
