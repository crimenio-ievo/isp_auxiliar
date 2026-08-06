# Testes da homologação final

Data: 2026-08-06. Backup anterior: `/var/backups/isp_auxiliar_homologacao_final/20260806T121025Z`.

## Automação executada

Todos os arquivos em `tests/Feature/*.php` passaram:

- `ClientModalPendingSmoke`: 28 verificações;
- `ClientPlanConfirmationSmoke`: 23, incluindo zero chamadas no gateway para CPF ausente/inválido;
- `FifthIterationSmoke`: 30;
- `FinalBetaSmoke`: 20;
- `FourthIterationSmoke`: 57;
- `HomologationCorrectionsSmoke`: 31;
- `OperationalProcessSmoke`: 62;
- `ReleaseIsolationSmoke`: 22;
- `ReleaseScriptsSmoke`: 32;
- `ThirdIterationSmoke`: 40;
- `UpgradeCorrectionSmoke`: 40.

Os testes que usam banco executaram rollback. Os guards confirmaram `MKAUTH_WRITE_ENABLED=false`; nenhum envio, chamado ou escrita externa real foi executado. PHP lint, `node --check`, `git diff --check` e renderização sintética também foram usados.

## Casos cobertos

- ação principal e navegação das quatro etapas;
- aceite pronto/enviado/confirmado e bloqueio do avanço;
- estado visual único nas quatro etapas;
- câmera, preview, MIME/storage e vínculo da evidência;
- finalização idempotente e lista de pendências;
- checkboxes automáticos/finais, isenção, upgrade, retenção, outro benefício e fidelidade;
- CPF/CNPJ vazio, inválido, repetido e normalizado;
- campos obrigatórios, POST direto e zero chamada ao gateway em erro;
- plano oficial, releitura, divergência parcial e retry sem duplicidade;
- preservação do formulário, erros por campo e foco inicial;
- isolamento Stable/Beta e destino do seletor.

## Homologação manual pendente

Não havia navegador físico nesta execução. Permanecem para validação administrativa manual: desktop 1366×768, notebook baixo, Android, iPhone/iPad, teclado, zoom 100%/125%, câmera real, rotação, preview e fluxo completo com usuário de cada perfil. Esta entrega está pronta para nova homologação manual na Beta, não para produção.

Não houve migration nova ou executada, push, merge ou deploy de produção.
