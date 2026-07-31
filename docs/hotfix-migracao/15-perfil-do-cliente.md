# Terceira iteração — perfil do cliente

O detalhe virou uma central operacional com cabeçalho compacto, ações rápidas,
alerta de processo aberto, quatro cartões, documentos e linha do tempo.

## Cartões e carregamento

- Cliente: identidade, documento, status, contatos e dados pessoais presentes.
- Conexão: status, login, plano e tecnologia; sessão/equipamento são carregados
  sob demanda.
- Endereço: composição sem fragmentos vazios, referência e mapa.
- Financeiro: resumo leve; títulos e vencimentos são carregados sob demanda e
  somente para perfil autorizado.

No desktop os cartões abrem painel lateral; em telas estreitas o painel ocupa a
visão e a faixa usa rolagem horizontal com `scroll-snap`. Botões têm foco
visível e os painéis podem ser fechados por botão, backdrop ou Escape.

## Ações seguras

- telefone: somente dígitos válidos geram `tel:` e WhatsApp;
- e-mail: somente endereço validado gera `mailto:`;
- mapa: coordenadas dentro dos limites ou pesquisa por endereço suficiente;
- login/coordenadas: cópia explícita;
- IP: somente IP validado e usuário autorizado;
- documentos: âncora para a área unificada do próprio cliente.

Todos os rótulos e valores MkAuth são escapados. Valores vazios são omitidos e
o detalhe não mostra “Novo cliente”. O atalho de WhatsApp é atendimento rápido,
não substitui o envio oficial do aceite.

## Processo e histórico

O botão de migração deriva o estado do processo operacional: iniciar, continuar
com progresso, aguardar cliente, resolver atenção ou ver histórico. Processos
concluídos/cancelados não aparecem como alerta pendente.

“Documentos e contratos” reúne contratos locais, aceite, PDF digitalizado e
ações compartilhadas. A linha do tempo usa registros locais/auditoria e não
expõe logs técnicos completos no resumo.
