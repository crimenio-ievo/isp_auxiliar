# Plano de release e rollback

Nenhum comando desta página foi executado contra Stable/Beta real. Use primeiro
`--dry-run`, substitua hashes pelos commits revisados e registre o operador.

## Checklist de backup

- snapshot/backup da VM ou volume;
- `git status`, HEAD, branch, remote e lista de worktrees;
- dump consistente do banco e teste de leitura do dump;
- cópia protegida de `.env` e do secret store;
- cópia de storage, contratos, aceites, documentos e uploads;
- retenção de logs de auditoria e integração;
- registro do release ID/commit anterior;
- validação de espaço livre, permissões e dono dos diretórios;
- confirmação de que o plano de restauração tem operador e janela.

## Scripts preparados

```bash
scripts/releases/install_beta.sh --repository REPO --commit HASH --dry-run
scripts/releases/update_beta.sh --dir /var/www/html/isp_auxiliar_beta --commit HASH --dry-run
scripts/releases/rollback_beta.sh --dir /var/www/html/isp_auxiliar_beta --commit HASH_ANTERIOR --dry-run
scripts/releases/promote_beta_to_stable.sh --dir /var/www/html/isp_auxiliar --commit HASH_HOMOLOGADO --dry-run
scripts/releases/rollback_stable.sh --dir /var/www/html/isp_auxiliar --commit HASH_ANTERIOR --dry-run
scripts/releases/health_check.sh --url https://URL --cookie-file COOKIE_ADMIN
```

Os scripts abortam em erro, validam caminho/checkout/commit, recusam árvore
rastreada suja, não apagam Stable, criam branch de arquivo e arquivo `.commit`
da release anterior, registram log e não executam migrations sem
`--confirm-migrations`.

## Promoção

1. homologar Beta com escrita e mensagens bloqueadas;
2. resolver o bloqueador de segredos e rotacionar credenciais;
3. backup completo;
4. conferir ancestralidade/diff do commit Beta e aplicar o hotfix Stable no
   caminho de release escolhido;
5. executar promoção em dry-run;
6. executar promoção autorizada, ainda sem migrations;
7. revisar matriz/backup e só então confirmar migrations;
8. preencher `APP_RELEASE_CHANNEL`, ID e commit;
9. validar health/release, login, sessão e smoke; liberar tráfego gradualmente.

## Rollback

Interromper tráfego Beta/Stable afetado, registrar estado, executar o rollback de
código para o hash arquivado e validar health. Se migrations/dados forem
incompatíveis, não usar DDL reverso improvisado: restaurar o dump/snapshot do
banco correspondente e storage consistente. Revogar/inutilizar mensagens ou
tokens externos requer procedimento específico; os scripts não prometem
compensação externa.

Bloqueadores atuais de produção: segredo histórico sem rotação/invalidação,
ausência de homologação visual nos oito viewports, ausência de vínculo
criptográfico do endpoint público com o checkout local e integrações reais não
homologadas. O estado é pronto para homologação Beta controlada, não para produção.
