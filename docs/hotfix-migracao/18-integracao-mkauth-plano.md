# Terceira iteração — plano, conexão e financeiro no MkAuth

## Alteração de plano

`MkAuthPlanChangeService` consulta cliente e plano, exige `uuid_cliente` e
prepara o payload nativo `{uuid, plano}` para `PUT /api/cliente/editar`. O
dry-run registra:

- plano, tecnologia, valor e sessão antes;
- plano, UUID, tecnologia e valor pretendidos;
- endpoint e payload;
- escrita habilitada/bloqueada;
- limitações de desconexão e financeiro.

`apply()` passa pelo `MkAuthWriteGuard` antes do adapter. Nesta entrega
`MKAUTH_WRITE_ENABLED=false`; nenhum PUT é enviado e a etapa não é marcada como
aplicada pelo dry-run. A confirmação do formato e efeito real do endpoint,
consulta pós-escrita e rollback de plano continuam pendentes para homologação
controlada futura.

## PPPoE e desconexão

Sessão ativa em `radacct` é evidência suficiente para o piloto. Sem consulta
disponível ou sessão ativa, a etapa permite confirmação manual autorizada com
observação/evidência. MAC é auxiliar e não define tecnologia nem bloqueia a
migração.

Não foi encontrado mecanismo oficial e comprovado de desconexão no contexto
inspecionado. O fluxo orienta desconectar/reiniciar manualmente e consultar de
novo. Não foi criada integração própria com RADIUS, CoA, SSH ou MikroTik.

## Chamado financeiro

O núcleo existente `MkAuthTicketService` e `financial_tasks` é reutilizado. O
processo impede duplicidade, vincula identificador externo e consulta
`sis_suporte`. A abertura permanece forçada a dry-run em ambiente diferente de
produção ou quando a escrita MkAuth está bloqueada.

O encerramento registra data real de `fechamento`; responsável só é salvo quando
`login_atend` estiver preenchido. Resultado ambíguo permanece pendente e exige
confirmação manual autorizada. A execução técnica pode estar concluída enquanto
o processo segue “Aguardando financeiro”.
