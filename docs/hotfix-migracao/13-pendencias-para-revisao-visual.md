# Segunda iteração — pendências para revisão visual

## Estado da entrega

A implementação está pronta para teste funcional em homologação, validação
física no celular e revisão tela a tela. Não está pronta para produção.

## Roteiro mobile

Testar em Android/Chrome e iOS/Safari:

1. abrir detalhe do cliente;
2. conferir card de processo incompleto;
3. tocar em `Continuar próxima pendência`;
4. navegar anterior/próxima;
5. salvar parcial;
6. pular com motivo;
7. voltar ao checklist;
8. confirmar que a pendência continua;
9. desenhar assinatura em retrato;
10. girar para paisagem;
11. limpar e redesenhar;
12. simular conexão lenta;
13. tentar duplo toque no submit;
14. voltar pelo navegador;
15. concluir somente depois das obrigatórias.

## Revisão tela a tela

- `/clientes/detalhe`: hierarquia e quantidade de cards.
- `/processos`: filtros, textos e estados.
- `/processos/detalhe`: 11 etapas sem poluição visual.
- `/processos/etapa`: alvos de toque, teclado e detalhes recolhidos.
- `/aceite/{token}`: canvas, checkbox, documento, mensagens e rotação.
- `/aceite/{token}/termo`: CSRF e liberação do termo.
- `/contratos/detalhe`: tarefa/chamado financeiro anterior.

## Cenários funcionais

- migração via assinatura local;
- migração via WhatsApp;
- migração via e-mail;
- ambos os canais com o mesmo link;
- falha de um canal;
- reenvio sem novo processo/token;
- aceite pendente e execução técnica posterior;
- dry-run de plano;
- confirmação manual do plano;
- conexão manual;
- chamado simulado;
- acompanhamento somente leitura;
- fechamento manual;
- conclusão bloqueada;
- exceção de gestor;
- instalação reutilizando o núcleo;
- assinatura avulsa sem criar instalação;
- correção/substituição;
- cancelamento.

## Pendências conhecidas

- confirmar versão exata do MkAuth com o administrador;
- homologar payload de `cliente/editar`;
- confirmar nome versus UUID do plano;
- confirmar impacto financeiro nativo;
- validar se existe desconexão segura suportada;
- validar textos/cores com operação;
- decidir se a listagem de processos entra no menu para todos os técnicos;
- revisar necessidade de anexos binários por etapa em iteração futura;
- executar automação de navegador quando disponível.

## Critério para futura revisão de release

Somente iniciar a revisão final depois de:

1. teste funcional completo em homologação;
2. validação física nos dois sistemas móveis;
3. correções visuais pequenas;
4. prova controlada de integração MkAuth;
5. revisão de diff e migrations;
6. plano de rollback;
7. confirmação explícita de que a escrita externa continuará bloqueada até a
   janela aprovada.
