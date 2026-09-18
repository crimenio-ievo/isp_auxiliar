# Quarta iteração — roteiro de retomada da homologação

## Pré-condições

1. Usar somente ambiente local/controlado na branch
   `fix/homologacao-migracao-ui`.
2. Confirmar `MKAUTH_WRITE_ENABLED=false`, Evotrix/e-mail/chamado em dry-run.
3. Aplicar migrations com `php scripts/apply_migrations.php` e executar as
   quatro suites descritas no documento 31.
4. Usar cliente sintético ou expressamente autorizado; registrar navegador,
   resolução, zoom, perfil do operador e horário.

## Jornada funcional exata

1. Abrir um cliente sem processo; confirmar botão Iniciar Upgrade / Migração.
2. Abrir e fechar os modais Cliente, Conexão, Endereço e Financeiro por botão,
   backdrop e Escape; conferir retorno do foco.
3. Identificar telefone Principal e Alternativo; testar Ligar, WhatsApp e Copiar
   sem efetuar mensagem real.
4. Iniciar migração rádio → fibra.
5. Selecionar o novo plano; conferir label comercial, operação, tecnologia,
   mensalidade, adesão configurada e benefício.
6. Salvar e continuar; confirmar que não há erro SQL e que abre o mesmo
   workspace.
7. Repetir com o plano atual; confirmar erro visível, dados preservados e foco.
8. Testar retenção sem observação e com justificativa.
9. Testar fidelidade desligada; depois sem benefício, com prazo 0/13 e com uma
   condição válida de 1–12 meses.
10. Abrir checklist, escolher etapa e avançar por Continuar → Continuar até
    Finalizar; testar também Salvar e sair/retomar.
11. Coletar assinatura local sintética, escolher canais e confirmar somente
    resultados dry-run.
12. Confirmar o aceite público de teste; voltar ao processo e conferir
    preparação, envio e confirmação concluídos.
13. Com aceite ainda pendente, corrigir a condição, informar motivo e confirmar
    mesmo ID de processo, nova revisão e token anterior revogado.
14. Com aceite confirmado, tentar corrigir e confirmar mensagem de substituição,
    permissão e histórico antes/depois.
15. Cancelar uma solicitação pendente com motivo; confirmar ausência do painel
    aguardando, próxima pendência vazia e histórico preservado.
16. Iniciar nova migração após cancelamento; confirmar novo processo limpo.
17. Abrir detalhe do contrato; confirmar ausência de “Abrir configurações”,
    títulos simplificados e eventos em linguagem humana.
18. Alternar Stable/Beta com usuário autorizado em ambiente controlado; testar
    usuário sem permissão, retorno a Stable e auditoria. Não configurar host de
    produção.

## Matriz visual a 100% de zoom

Executar perfil, modais, formulário, workspace, checklist, contrato, banner e
menu em: 1920×1080, 1440×900, 1366×768, 1024×768, 768×1024, 412×915, 390×844 e
360×800. Capturar referências em 1920×1080, 1366×768 e 390×844. Verificar texto,
overflow, botões, sticky, drawer, scroll-snap, rotação e teclado.

## Aparelhos e saída

Repetir em Android/Chrome e iOS/Safari físicos; testar toque, teclado, rotação e
leitor de tela. Registrar defeitos com processo/contrato/aceite, etapa, viewport
e evidência, sem incluir credenciais ou assinatura real.

Critério de saída desta retomada: jornada funcional e visual aprovada, guardas
externos ainda desligados e pendências físicas documentadas. Qualquer produção,
push, merge, deploy ou habilitação externa requer autorização separada.
