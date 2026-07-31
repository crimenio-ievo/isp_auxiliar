# Terceira iteração — simplificação da migração

O template interno de 11 etapas foi preservado. A navegação do técnico foi
agrupada em quatro telas, sem criar um segundo motor:

1. Nova condição.
2. Conferência, assinatura e envio.
3. Execução técnica e conexão.
4. Plano, financeiro e conclusão.

A retomada e a próxima pendência agora apontam para a tela agrupada adequada; o
checklist de 11 etapas continua acessível como detalhe técnico.

## Nova condição

O técnico escolhe o plano MkAuth e informa apenas retenção, benefício adicional,
observação e, se aplicável, fidelidade. Nome, velocidade, valor, tecnologia e
UUID/código vêm do catálogo. A operação é calculada no servidor:

- famílias tecnológicas conhecidas diferentes: migração;
- mesma família e velocidade/valor superior: upgrade;
- mesma família e condição inferior: downgrade;
- sem mudança ou tecnologia sem família comprovada: avanço bloqueado.

Retenção é motivo comercial separado do tipo técnico. Migração rádio→fibra não
concede isenção por presunção: a isenção depende da configuração comercial
`isentar_adesao_migracao_radio_fibra`, desabilitada por padrão.

Nova fidelidade começa desabilitada. Quando marcada, exige benefício real,
descrição, valor maior que zero e prazo entre 1 e 12 meses. O snapshot registra
operação, motivo comercial, benefício, valor, prazo e responsável. Termo e telas
mostram “não aplicada” quando o prazo é zero; não existe renovação automática.

Erros retornam HTTP 422, preservam o POST, aparecem no resumo e junto ao campo,
e direcionam foco ao primeiro erro. As ações “Salvar e continuar” e “Salvar e
voltar depois” têm proteção contra duplo envio.
