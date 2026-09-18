# Terceira iteração — pendências para revisão final de release

Esta branch está pronta para homologação funcional, não para produção.

## Bloqueadores antes de release

- concluir roteiro funcional com operador administrativo/técnico/financeiro;
- executar teste físico Android e iOS, inclusive câmera, scroll-snap, painéis,
  assinatura e PDF;
- revisar acessibilidade com teclado, zoom, contraste e leitor de tela;
- validar visualmente o layout antigo e decidir quando remover o alias;
- homologar PDF real autorizado e política definitiva de retenção/originais;
- revisar juridicamente os textos de benefício/fidelidade sem transformar regra
  provisória em decisão legal ampla;
- validar os templates e contatos fictícios por evento/canal;
- confirmar estados reais oferecidos pelos provedores antes de usar “entregue”.

## Integrações ainda bloqueadas

- confirmar versão e documentação efetiva do MkAuth instalado;
- testar `PUT /api/cliente/editar` com cliente sintético e autorização separada;
- confirmar se `plano` recebe nome ou UUID, efeito financeiro, sessão,
  idempotência, consulta pós-escrita e rollback;
- manter desconexão manual até localizar mecanismo oficial comprovado;
- homologar abertura/consulta do chamado e significado de `login_atend`;
- definir autorização separada para qualquer envio real WhatsApp/e-mail.

## Release controlado futuro

1. Revisão final de release com raciocínio Ultra e diff/commits já homologados.
2. Snapshot/backup de aplicação e banco.
3. Plano de migration 017 e rollback validado no ambiente-alvo.
4. Deploy controlado sem `storage/contracts/acceptances/` nem backups `.env`.
5. Smoke pós-deploy com escritas e mensagens ainda bloqueadas.
6. Habilitações externas, se aprovadas, em mudanças separadas e reversíveis.
7. Monitoramento de auditoria, notificações, processos e storage.
8. Rollback explícito de código, banco e configuração Apache quando aplicável.

Nenhum push, merge, deploy ou alteração no servidor de produção faz parte desta
entrega.
