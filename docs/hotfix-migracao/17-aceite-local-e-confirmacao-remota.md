# Terceira iteração — aceite local e confirmação remota

A tela 2 reutiliza o contrato, aceite, token, snapshot e histórico já existentes.
Não foi criado fluxo paralelo.

## Cliente presente

O padrão coleta assinatura no canvas do aparelho do técnico. A imagem PNG/JPEG é
validada, limitada e armazenada fora da pasta pública, junto com JSON de
evidência, operador, data, origem, contatos e canais. Depois o sistema prepara o
mesmo link para WhatsApp, e-mail ou ambos. A assinatura local não encerra o
aceite: o cliente ainda confirma os dígitos do documento e a contratação no link.

## Cliente ausente

“Cliente não está presente” exige motivo, não coleta assinatura local e mantém o
aceite em modo de assinatura remota. No link, o cliente confirma documento,
checkbox e assinatura.

Correções de telefone/e-mail ficam na evidência e auditoria. Pelo menos um canal
válido é obrigatório. Não há botão de copiar link ou mensagem para envio manual.

## Interface pública e estados

A página pública mostra apenas titular, alteração, plano, valores, adesão,
fidelidade, resumo, termo e confirmação. Token, IDs, JSON, IP, user agent, versão
e checklist não são exibidos. Assinatura local já coletada não é solicitada de
novo.

Tentativa, aceite pelo provedor, falha e confirmação do cliente permanecem
estados distintos. “Entregue” só pode vir de recibo real. Nesta homologação os
adapters estão em dry-run, portanto nenhum envio é classificado como entrega.

