# Testes finais da Beta

## Automação

- lint PHP dos arquivos alterados;
- `node --check public/assets/js/app.js`;
- `git diff --check`;
- todos os arquivos `tests/Feature/*Smoke.php`;
- `FinalBetaSmoke`: regras comerciais, workspace, aceite, PPPoE, finalização e densidade;
- `ReleaseScriptsSmoke`: sintaxe, locks, backups, manifesto, segurança, symlink atômico e isolamento de canal;
- `deploy_beta.sh --dry-run` com comprovação de ausência de diretórios e symlinks criados;
- health HTTP da Beta e da Stable;
- estado Git dos três worktrees.

Os testes com banco usam transação e rollback. Nenhuma escrita MkAuth, notificação, chamado, IA, push, merge, promoção ou implantação foi executada.

## Limites honestos

Não havia navegador automatizado instalado no servidor. A matriz visual em zoom 100% e a jornada autenticada ponta a ponta permanecem como homologação administrativa manual no roteiro 78. O endpoint administrativo `/api/release` também requer uma sessão administrativa real para validação HTTP completa.

