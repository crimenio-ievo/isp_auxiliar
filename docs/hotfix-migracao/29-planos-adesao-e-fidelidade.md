# Quarta iteração — planos, adesão, retenção e fidelidade

## Planos

A opção visível mostra nome comercial, velocidade legível quando necessária e
valor em reais. UUID, código técnico, velocidades originais, tecnologia e
descrição permanecem em atributos/snapshot para o cálculo e para auditoria. A
lista é ordenada por família, velocidade, valor e nome; listas extensas recebem
busca local.

A operação é calculada:

- troca de família tecnológica: migração;
- mesma família com velocidade/valor superior: upgrade;
- mesma família com velocidade/valor inferior: downgrade;
- mesmo plano ou metadados insuficientes: bloqueio com erro visível.

## Adesão

O valor vem de `contracts.commercial.valor_adesao_padrao`; não existe valor de
adesão fixo no controller. A configuração oferece três modos para rádio → fibra:

- `automatic`: isenta automaticamente;
- `disabled`: cobra a adesão configurada;
- `manual`: exige confirmação explícita de isenção.

Na configuração local atual, o modo é `automatic`. Assim, migração rádio → fibra
grava `tipo_adesao=isenta`, valor/parcela zero e benefício igual à adesão padrão.
Sem isenção, grava `tipo_adesao=cheia`, valor e parcela iguais à configuração.
Esses são valores válidos no enum existente, eliminando o erro SQL de
`nao_aplicavel`.

## Retenção

Retenção é uma condição comercial independente do tipo técnico da operação. Se
ativada, exige justificativa em observação e fica registrada no snapshot como
`commercial_reason=retention`.

## Fidelidade

Fidelidade começa desligada. Desligada, descrição e prazo ficam `disabled` e não
são enviados. Ligada, exige benefício real com valor, descrição e prazo de 1 a
12 meses. Labels e mensagens de erro são associados aos controles. A regra não
aplica renovação automática por simples troca de tecnologia.
