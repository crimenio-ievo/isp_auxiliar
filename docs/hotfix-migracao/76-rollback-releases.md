# Rollback de releases

Os scripts `rollback_beta.sh` e `rollback_stable.sh` aceitam somente uma release imutável do próprio canal, com release ID e hash completo conferidos no manifesto.

Antes da troca, eles criam backup administrativo, auditam migrations, executam testes e health CLI. O symlink é atualizado atomicamente, a release substituída vira a anterior e o health HTTP é executado.

Garantias:

- rollback Beta não toca o symlink Stable;
- rollback Stable não toca o symlink Beta;
- nenhum rollback executa `apply_migrations.php`;
- banco não é revertido automaticamente;
- storage permanente não é apagado;
- divergência de canal, manifesto, commit ou caminho aborta a operação.

Se uma reversão de banco for realmente necessária, ela exige plano separado, backup confirmado, análise de compatibilidade e autorização explícita.

