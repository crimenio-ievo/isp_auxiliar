# Segunda iteração — envio e assinatura

## Documento e aceite

O documento é representado pelo contrato existente e por um snapshot imutável
em `operational_process_documents`. O hash do snapshot e o `termo_hash` do
aceite permitem verificar qual conteúdo foi apresentado.

`AcceptanceWorkflowService` centraliza:

- token aleatório de 128 bits;
- validade configurável, com padrão atual de 48 horas;
- versão do termo;
- hash do termo;
- modo local/remoto;
- telefone;
- IP e user agent iniciais.

## Um aceite ativo

Há uma referência ativa por processo, tipo de documento e versão. Ao vincular
outro aceite pendente à mesma versão, o pendente anterior é revogado e
cancelado. Aceites concluídos e evidências antigas são preservados.

## Canais

Instalação, migração e solicitação avulsa reutilizam:

- `EvotrixService`;
- `EmailService`;
- `NotificationLogRepository`;
- templates e auditoria existentes;
- o mesmo contrato, aceite, token e URL.

O ambiente validado está com Evotrix em `dry_run=true` e destino de teste
restrito. E-mail também está em `dry_run=true` e destino de teste restrito.
Nenhum envio real foi executado.

## Assinatura no aparelho

O componente comum usa:

- Pointer Events;
- `preventDefault()`;
- pointer capture;
- `touch-action: none`;
- escala por `devicePixelRatio`;
- coordenadas relativas ao canvas;
- preservação no resize/orientação;
- limpeza;
- PNG em data URL;
- detecção de canvas vazio;
- trava de duplo submit no aceite;
- o mesmo POST público para todos os tipos.

O backend agora valida:

- CSRF de sessão;
- token/contrato/versão ativos sob lock;
- uso único;
- assinatura obrigatória conforme estado;
- data URL válida;
- imagem PNG/JPEG válida;
- dimensões entre 1 e 8000 pixels;
- limite de 5 MB;
- persistência antes de concluir;
- associação pelo ID do aceite.

## Segurança pública

CSRF foi adicionado a:

- confirmação do aceite;
- liberação do termo assinado.

Token inválido, expirado, revogado, cancelado, substituído ou já utilizado
continua sendo tratado pelo fluxo público anterior. A confirmação concorrente
usa `SELECT ... FOR UPDATE` e atualização condicional.

## Validação física pendente

Ainda é obrigatório testar em celulares reais:

- Android/Chrome;
- iOS/Safari;
- modo retrato e paisagem;
- rotação durante o desenho;
- toque longo e rolagem;
- conexão lenta e interrupção;
- desenho pequeno/grande;
- limpeza e repetição;
- modal/teclado;
- duplo toque.
