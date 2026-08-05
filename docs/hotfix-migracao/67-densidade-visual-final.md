# Densidade visual final

## Ajustes

- fonte-base desktop de 13 px;
- títulos de página entre 24 e 27 px;
- títulos de seção entre 17 e 19 px;
- controles e botões com 38 px no desktop;
- cartões entre 12 e 16 px de padding;
- gaps principais entre 10 e 14 px;
- sidebar de 150 px;
- jornada entre 270 e 292 px;
- conteúdo limitado a 1680 px.

Não foi utilizado `zoom`, `transform: scale()` global ou dependência de zoom do navegador.

Em até 800 px de altura, cabeçalho, navegação, cartões e espaços verticais são reduzidos. Em telas móveis, controles mantêm no mínimo 44 px, a jornada abre em drawer, as ações permanecem acessíveis e os modais ocupam quase toda a viewport.

## Validação

Há verificações estáticas automatizadas para os tokens de densidade, media query de altura, alvos móveis e ausência de escala global. O servidor não possui Chromium/Playwright instalado; a conferência visual em zoom 100% deve ser feita no roteiro 78 nos oito viewports solicitados.

