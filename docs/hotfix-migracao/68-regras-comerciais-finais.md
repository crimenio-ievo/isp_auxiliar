# Regras comerciais finais

## Princípio

Plano, tecnologia, operação, valor mensal, benefício automático e elegibilidade da fidelidade são recalculados no servidor com o catálogo oficial. Flags ou valores ocultos enviados pelo navegador não classificam a operação.

## Regras

- Rádio para Fibra: migração, isenção conforme configuração, benefício igual à adesão configurada e fidelidade automática somente se o benefício for positivo e identificável.
- Mesma tecnologia superior: upgrade; não herda o benefício da migração.
- Mesma tecnologia inferior: downgrade; retenção é uma condição comercial separada e nunca nasce apenas da redução de preço.
- Sem benefício positivo e descrito: fidelidade zero.
- Ajuste manual: somente perfil comercial autorizado, com justificativa; snapshot preserva valor automático, valor final, operador e data.
- Mudança de plano no navegador limpa benefício, retenção, justificativa e fidelidade da seleção anterior.

## Casos automatizados

`FinalBetaSmoke` cobre Rádio→Fibra, Rádio→Rádio superior, plano sem benefício, retorno à Fibra e downgrade com retenção documentada. O snapshot final não recebe valor anterior silenciosamente.
