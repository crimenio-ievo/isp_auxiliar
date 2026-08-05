# Compatibilidade Stable/Beta

## Estado preservado

- Stable: `/var/www/html/isp_auxiliar_stable`, commit `73a95b2e6adae4435d23f94cb9b4304c3317e017`.
- Beta: `/var/www/html/isp_auxiliar`, branch `fix/finalizacao-beta-operacional`.
- Hotfix preservada: `/var/www/html/isp_auxiliar_stable_hotfix`, commit `3c73d385d8689594ec55c4c21b70b152b48b6de1`.

Nenhum arquivo, `.env`, storage, configuração Apache, commit ou worktree da Stable/hotfix foi alterado. A validação da tag Stable deve usar `stable-prod-2026-08-04^{commit}` ou `git rev-list -n 1 stable-prod-2026-08-04`.

Stable e Beta continuam usando o banco de testes `isp_auxiliar`. Esta rodada não criou nem aplicou migration; portanto, não houve alteração de schema compartilhado. O dump administrativo anterior às mudanças está em `/var/backups/isp_auxiliar_beta_final/20260805T213810Z/` com diretório 700 e arquivos 600.

O health da Stable foi executado antes das mudanças e deve ser repetido ao final. A Stable antiga continua sem seletor por decisão explícita e permanece acessível diretamente.

