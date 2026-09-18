# Cancelamento e reinício

O cancelamento usa `OperationalProcessService::cancelProcess`; não apaga processo,
etapas, contrato, aceite, documento ou evidências.

- antes do aceite: exige motivo;
- depois do aceite: exige confirmação específica e preserva o documento no histórico;
- depois de ação externa: exige gestor, confirmação reforçada e descrição da correção/reversão;
- não promete rollback externo automático;
- zera a próxima pendência e impede retomada do processo cancelado.

“Cancelar e iniciar nova migração” só redireciona após o cancelamento confirmado.
A abertura seguinte cria outro processo em rascunho, sem reaproveitar contrato,
aceite, token ou valores corrigidos. O processo anterior permanece consultável.

Testes cobrem cancelamento simples, detecção de ação externa, criação do novo ID e
seleção do novo processo — nunca do cancelado — como ativo.
