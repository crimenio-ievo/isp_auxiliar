# Simplificação final da migração

Data do levantamento: 2026-08-04. Branch: `feature/migracao-operacional-beta`.
Base funcional: `94db2346528434a17aa38faccd4127a9953d2e97`.

## Resultado

A experiência de Upgrade / Migração foi reduzida a quatro etapas visíveis sem
substituir o motor existente. `MigrationJourneyService` apenas projeta as onze
etapas persistidas em: Nova condição, Aceite, Execução técnica e Finalização.
O detalhe técnico das onze etapas continua disponível em um painel recolhível.

A abertura de `/clientes/upgrade` cria um processo real em rascunho antes de
renderizar a Nova condição. Se houver processo ativo com contrato, ele é
retomado; processo concluído ou cancelado não bloqueia um novo rascunho.

## Condições automáticas

- plano e tecnologia são derivados do catálogo oficial;
- operação é calculada como migração, upgrade ou downgrade;
- adesão e benefício seguem a configuração comercial;
- alteração do benefício exige justificativa e deixa valores original/final no snapshot;
- fidelidade automática é configurável por migração e upgrade;
- downgrade pode sugerir retenção, mantendo edição manual explícita em “Ajustar condições”.

## Limites preservados

Não houve novo motor, exclusão de históricos ou alteração real no MkAuth. O
checkout de produção não foi modificado. O fluxo permanece Beta até completar
a homologação administrativa, visual e das integrações em ambiente autorizado.
