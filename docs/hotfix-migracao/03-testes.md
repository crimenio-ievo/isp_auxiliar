# Testes do hotfix de migracao

## Automatizados previstos

- `php scripts/apply_migrations.php`
- `php tests/Feature/UpgradeCorrectionSmoke.php`
- `php -l` nos arquivos PHP alterados
- `node --check public/assets/js/app.js`
- `git diff --check`

## Cobertura esperada

- Inicio de migracao por cliente.
- Bloqueio de nova migracao quando existe processo aberto.
- Correcao/substituicao de upgrade incorreto.
- Cancelamento com aceite revogado.
- Token invalido/cancelado/substituido.
- Aceite confirmado somente uma vez.
- Checklist tecnico apos aceite.
- Escrita real no MkAuth bloqueada por configuracao.

## Testes manuais antes de producao

1. Abrir um cliente radio elegivel em `/clientes`.
2. Iniciar `Upgrade / Migracao`.
3. Escolher tipo `Migracao de tecnologia` e plano de fibra.
4. Conferir resumo obrigatorio.
5. Testar assinatura neste aparelho.
6. Repetir com envio de link ao cliente.
7. Confirmar pelo link publico em viewport de celular.
8. Atualizar a pagina apos aceite e verificar que nao duplica.
9. Retornar ao detalhe do cliente e concluir checklist tecnico.
10. Confirmar que o MkAuth nao recebeu escrita real.

## Observacao

Os testes devem rodar em ambiente de teste ou com `MKAUTH_WRITE_ENABLED=false`. Nao usar credenciais reais de escrita do MkAuth durante validacao.
