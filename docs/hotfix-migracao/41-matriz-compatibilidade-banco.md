# Matriz de compatibilidade do banco

A Stable candidata contém migrations `001` a `006`. A Beta acrescenta `007`,
`008`, `016` e `017`. A recomendação inicial é banco local Beta separado.

| Migration | Mudança | Natureza | Leitura pela Stable | Risco de banco compartilhado |
| --- | --- | --- | --- | --- |
| 007 | adiciona `contrato_digital` ao enum de aceite | ampliação de enum | código antigo pode não traduzir o novo valor | médio |
| 008 | colunas/FKs de ciclo e revisão em contratos/aceites | aditiva | colunas extras são ignoráveis | médio, por novos estados |
| 016 | três tabelas de processos e duas FKs opcionais no aceite | aditiva | tabelas/colunas extras são ignoráveis | médio, por processos que a Stable não conhece |
| 017 | canais, versões de template e documentos; torna template provider-scoped | aditiva com mudança de constraint e backfill | Stable anterior não usa as novas tabelas | alto sem ensaio de escrita concorrente |

Não há `DROP` executado nas migrations de ida; os `DROP` de 008/016 aparecem
somente em comentários de rollback manual. Mesmo assim, backward-compatible no
DDL não significa compatível semanticamente: a Stable não entende cancelamentos,
revisões, novos tipos de aceite nem o estado dos processos Beta.

## Ensaio realizado

Em banco descartável `isp_auxiliar_codex_20260804`:

1. o runner oficial aplicou 11 arquivos, sem aviso/erro;
2. foram confirmados 11 registros, 19 tabelas e 29 colunas em `operational_processes`;
3. o banco inteiro foi descartado e recriado como rollback estrutural isolado;
4. as 11 migrations foram reaplicadas sem erro;
5. nova execução ignorou as 11, confirmando idempotência;
6. o banco temporário foi removido.

O usuário normal não possui privilégio de criar schemas — comportamento desejável
em produção — e o ensaio usou apenas root local via socket. Não foi testado
rollback destrutivo in-place de 017; para a primeira Beta, rollback de banco deve
ser restauração do dump/snapshot. Compartilhamento Stable/Beta não está aprovado.
