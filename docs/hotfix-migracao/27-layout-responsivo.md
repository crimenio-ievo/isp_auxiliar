# Quarta iteração — layout responsivo

## Dimensões de referência

- sidebar desktop: 172 px;
- sidebar compacta: 64 px;
- largura máxima do conteúdo: 1680 px;
- controle desktop: 40 px; mobile: 44 px;
- botão desktop: 40 px; mobile: 46 px;
- modal desktop: até 1040 px, margem mínima de 24 px por lado, altura limitada a
  820 px ou ao viewport;
- checklist desktop: `clamp(280px, 24vw, 360px)`;
- cards do cliente: altura mínima de 154 px.

## Desktop — 1200 px ou mais

Conteúdo e checklist ficam lado a lado; o checklist é sticky. O formulário usa
no máximo duas colunas e ações compactas. O conteúdo ocupa o espaço útil em
Full HD sem exigir zoom abaixo de 100%.

## Tablet — 769 a 1199 px

A sidebar fica compacta, o conteúdo usa largura total e o checklist passa a
drawer. A barra de progresso e as ações continuam compactas e permanecem em
linha quando houver espaço.

## Celular — até 768 px

Uma coluna, controles com alvo de toque, botão “Ver etapas”, checklist em drawer
inferior e ações principais sticky. O modal ocupa quase toda a tela usando
`100dvh`. O trilho de cards mantém scroll-snap e o CSS bloqueia overflow
horizontal do workspace.

## Modal central

Os quatro cartões usam `role=dialog`, `aria-modal`, título associado, foco
inicial, trap de Tab, fechamento por botão/backdrop/Escape e retorno de foco ao
cartão de origem. A rolagem do body é bloqueada durante a abertura.

## Validação disponível e pendente

O ambiente não possui Chromium, Chrome, Firefox, Playwright ou `wkhtmltoimage`.
Por isso não foram geradas screenshots nesta rodada. Foram validados HTML
renderizado, breakpoints/CSS, JavaScript e respostas HTTP locais. Ainda é
necessário testar visualmente em 1920×1080, 1440×900, 1366×768, 1024×768,
768×1024, 412×915, 390×844 e 360×800, sempre a 100% de zoom. Não há validação
física mobile declarada.
