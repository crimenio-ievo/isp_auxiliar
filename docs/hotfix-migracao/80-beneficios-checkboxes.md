# Benefícios por checkboxes

Data: 2026-08-06. O cálculo foi centralizado em `UpgradeBenefitService`; JavaScript apenas antecipa a seleção, e o servidor recalcula tudo com catálogo e configuração atuais.

## Seleção e regras

A seção `Benefícios e condições` apresenta checkboxes independentes para migração Rádio → Fibra, isenção de adesão/instalação, upgrade, retenção, outro benefício e nova fidelidade.

- Rádio → Fibra marca migração, isenção quando o modo configurado é automático e upgrade quando também há aumento de velocidade ou valor.
- A isenção usa `valor_adesao_padrao`; não há valor fixo embutido na regra.
- Upgrade na mesma tecnologia marca upgrade, mas não herda isenção nem R$ 1.200,00.
- Downgrade não vira retenção automaticamente. Retenção exige seleção autorizada, vantagem mensurável e justificativa.
- Outro benefício exige descrição; seu texto e valor são limpos ao desmarcar.
- Fidelidade automática só existe com benefício descrito e valor positivo. Desmarcar uma fidelidade automática é divergência e exige justificativa.

Perfis sem autorização comercial recebem a seleção automática, ignorando alterações manipuladas no POST. Para perfil autorizado, qualquer diferença de flags, valor ou fidelidade exige `benefit_adjustment_reason`.

## Snapshot e auditoria

O snapshot registra `benefit_automatic_flags`, `benefit_final_flags`, valor original/final, outro benefício, fidelidade automática/final, justificativa, usuário e data do ajuste. O evento `contract.upgrade.created` também registra a comparação sem incluir dados pessoais completos.

A apresentação mostra rótulos completos: `Valor cobrado` e `Benefício concedido`. A correção de condição passa pelo mesmo recálculo e não reutiliza silenciosamente a seleção antiga.

## Validação

`FinalBetaSmoke` cobre migração, upgrade sem herança, retenção e fidelidade. `HomologationCorrectionsSmoke` cobre cálculo Rádio → Fibra, aumento simultâneo, upgrade na mesma tecnologia e normalização do array de checkboxes.
