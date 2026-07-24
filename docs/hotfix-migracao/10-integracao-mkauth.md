# Segunda iteração — diagnóstico do MkAuth

## Fontes inspecionadas

- adapter atual `MkAuthClient`;
- leitura atual `MkAuthDatabase`;
- `MkAuthTicketService`;
- `MkAuthWriteGuard`;
- schema somente leitura da instalação conectada;
- OpenAPI local encontrado no checkout histórico:
  `/var/www/html/isp_auxiliar_old_20260612_1815/tmp/MkAuth/openapi.yaml`.

Nenhum endpoint remoto foi chamado para este diagnóstico. Nenhuma credencial é
documentada aqui.

## Versão

O OpenAPI local se identifica como `1.0.0`, mas esse é o número do documento,
não uma confirmação da versão do produto MkAuth instalado. O schema não possui
campo de versão confiável. Portanto, a versão exata do produto fica classificada
como **não encontrada/precisa de validação administrativa**.

Os servidores de banco observados foram:

- MkAuth: MariaDB `10.3.27`;
- banco local: MariaDB `10.11.14`.

Esses números não são versão do MkAuth.

## Matriz de capacidade

| Função | Evidência | Classificação |
|---|---|---|
| Consultar cliente | API `cliente/show`, `cliente/listar` e `sis_cliente` | disponível e segura para leitura |
| Consultar planos | API `plano/listar`, `plano/show` e `sis_plano` | disponível e segura para leitura |
| Alterar plano | `PUT /api/cliente/editar`; documentação diz aceitar campos de `sis_cliente` exceto login/id/UUID | disponível, mas não validada para escrita |
| Alterar tecnologia | consequência provável do plano; não há endpoint específico comprovado | parcialmente disponível/não validada |
| Atualizar contrato do cliente | campo `contrato` existe e API genérica pode editar | disponível, mas não validada |
| Aplicar novo valor mensal | valor pertence a `sis_plano`; não foi comprovada alteração por cliente | exige operação manual/não recomendada |
| Desconectar sessão | `radacct` permite verificar sessão; endpoint seguro de desconexão não foi encontrado | exige operação manual |
| Auto desconecta/Radius incoming | tabelas/atributos existem, sem contrato seguro comprovado | não recomendada nesta iteração |
| Recalcular financeiro | endpoints financeiros existem no OpenAPI, sem relação pós-troca comprovada | não recomendada |
| Corrigir boletos/carnês | estruturas existem, sem operação idempotente comprovada para migração | exige chamado/manual |
| Abrir chamado | `POST /api/chamado/inserir` e service atual | disponível, escrita bloqueada |
| Consultar chamado | API documentada e leitura atual de `sis_suporte` | disponível e segura |
| Fechar chamado | `PUT /api/chamado/fechar` documentado | disponível, mas não implementada/validada |
| Status externo | `sis_suporte.status`/`fechamento` e `radacct` | parcialmente disponível |

## Alteração de plano preparada

`MkAuthPlanChangeService`:

1. localiza o cliente por leitura;
2. localiza o plano no catálogo;
3. exige `uuid_cliente`;
4. prepara payload `{uuid, plano}`;
5. mostra antes/depois, endpoint e limitações;
6. grava somente a evidência do dry-run na etapa.

`apply()` passa primeiro por `MkAuthWriteGuard` e depois pelo adapter nativo.
Com `MKAUTH_WRITE_ENABLED=false`, o guard lança a mensagem de bloqueio antes de
qualquer requisição.

O dry-run não conclui `change_plan`.

## Fallbacks manuais

- Alterar plano manualmente no MkAuth.
- Desconectar/reiniciar equipamento quando necessário.
- Confirmar reconexão e navegação.
- Confirmar equipamento/serial/ONU.
- Revisar boletos/carnês via chamado financeiro.

Cada confirmação manual exige observação ou evidência, usuário, data e origem.

## Ativação futura

Antes de habilitar escrita:

1. confirmar a versão exata do MkAuth;
2. homologar `cliente/editar` com cliente sintético;
3. confirmar se `plano` espera nome ou UUID;
4. consultar o cliente depois da escrita;
5. definir rollback do plano;
6. validar impacto financeiro;
7. manter confirmação explícita do operador;
8. manter `MkAuthWriteGuard`.
