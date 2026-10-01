# Fluxo de release: candidata → produção (sem Beta)

Substitui o fluxo Beta/Stable (`deploy_beta.sh`, `update_beta.sh`, `install_beta.sh`, `rollback_beta.sh`,
`promote_beta_to_stable.sh`, removidos). Os documentos `docs/hotfix-migracao/74…77` descrevem o fluxo antigo.

## Peças

| Etapa | Script | Resultado |
|---|---|---|
| 1. Montar candidata | `scripts/releases/deploy_candidate.sh` | `${RELEASE_ROOT}/<id>` com `.release-manifest` `channel=candidate`, `.env` com `APP_RELEASE_CHANNEL=candidate`, `APP_ENVIRONMENT=test` (banner de testes visível) e dry-run de integrações; symlink próprio `isp_auxiliar_candidate_current` |
| 2. Homologar | você, no ambiente de testes | — |
| 3. Promover | `scripts/releases/promote_release.sh` | cópia imutável em `${RELEASE_ROOT}/production/<id>` com `channel=production`, `.env` de produção, `APP_ENVIRONMENT=production` (banner some), troca atômica do symlink `isp_auxiliar_stable_current` |
| 4. Voltar | `scripts/releases/rollback_stable.sh` | reaponta para a release anterior (`…_previous`) ou `--to-release` (qualquer release imutável de `${RELEASE_ROOT}` com manifesto `production`/`stable`) |

`RELEASE_ROOT` padrão: `/var/www/html/isp_auxiliar_production_releases`.

## O que o promote mantém (igual ao fluxo anterior)

Lock exclusivo (mesmo do rollback), validação de manifesto (release_id + commit + `channel=candidate`), schema
igual ao homologado, backup da release atual e do `.env`, testes isolados (`release_run_tests`), health CLI
(canal/commit/banco), troca atômica e health HTTP (`/api/health`, `/api/release`). Não aplica nem reverte
migrations: elas devem estar aplicadas antes (o promote recusa se o schema divergir).

## Detalhes importantes

- `channel=production` é aceito como alias de `stable` na verificação de canal: as releases anteriores ao fluxo
  (ex.: `pre-maps-v0-…-4af16c6`) têm `APP_RELEASE_CHANNEL=stable` no `.env` e continuam podendo ser alvo de rollback.
- O runtime (sessões, cache, logs) da produção continua em `runtime/stable`, para a promoção não deslogar ninguém.
- `APP_ENVIRONMENT` é gravado no `.env` de cada release pelos scripts (production na promoção, test na candidata);
  veja `docs/deploy-producao.md`.
- Promover continua exigindo confirmação explícita sua: nenhum script é executado por automação.

## Exemplos (use `--dry-run` primeiro)

```
scripts/releases/deploy_candidate.sh --source CHECKOUT --commit HASH_COMPLETO --release-id ID \
  --env-file ENV_ORIGEM --health-url http://HOST/caminho/public --allow-http-health [--dry-run]
scripts/releases/promote_release.sh --candidate DIR --release-id ID --commit HASH_COMPLETO \
  --production-env-file ENV_PRODUCAO --health-url https://HOST/ [--dry-run]
scripts/releases/rollback_stable.sh --release-id ID_ANTERIOR --commit HASH_ANTERIOR --health-url https://HOST/ [--dry-run]
```
