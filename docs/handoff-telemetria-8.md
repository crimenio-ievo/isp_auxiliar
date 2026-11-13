# Telemetria — etapa 8, somente CP-B

## Fato

Referência principal inicialmente ausente. `docs/design/mockups/index.html` contém “protótipo funcional”; foi copiado para `docs/design/rede-fibra-mockup.html` e versionado conforme autorização. Original não rastreado preservado. Desenho usado como referência, sem reutilizar seus dados simulados na aplicação.

Implementados: barra de sinal com três regiões iguais e preenchimento por faixa, ausência de leitura com barra vazia; contagens nas linhas de site/equipamento/PON; clique na linha abre/fecha painel, seta de 32 px só expande/recolhe; painel de site, equipamento e PON com leituras/cadastro reais. Atualização PON desativada, CPU não lida, GBIC/saída a coletar. Pior ONU abre ficha. Cabeçalho informa “ONU ou ONT: a identificar”, sem seletor.

API aditiva, sem migration: `modelo` (somente fabricante/modelo de map_nodes.attrs_json) e `ip` (net_devices.mgmt_host). Nunca devolve credential_ref ou outros atributos arbitrários. Listas de chaves atualizadas nos dois testes. Fabricante/modelo ausentes do cadastro atual aparecem como “a identificar”; não foi inferido modelo pelo SNMP nem copiado do mockup. Cores existentes das faixas preservadas. Saturado permanece na cor de atenção e entra nessa contagem agregada; estado individual continua Saturado.

Testes: Feature + Acceptance 48/48 em cópia isolada e schema descartável; mapa 25/25; rede sem falhas (42/99/109 + 7), e teste final adicional do contrato/segredo passou 100 verificações PHP. Novo teste cobre clique/segundo clique, painel de PON, botão desativado, dados do painel, barras/contagens, e whitelist de fabricante/modelo. Sintaxe PHP/JS e diff --check verdes. Schema/cópia temporários removidos, sem escrita de fixtures no banco real.

## Capturas, fora do Git

Chromium headless com HTML renderizado da aplicação e snapshot SELECT do banco local, sem usuário novo ou leitura de .env. Mockup servido apenas como referência estática. Serviço temporário escutou só em 127.0.0.1 e foi encerrado. Links “Abrir web da OLT” não foram acionados.

Comparações lado a lado (mockup à esquerda, aplicação à direita):
- `tmp/net8-100-comparacao-arvore.png`
- `tmp/net8-100-comparacao-grupo.png`
- `tmp/net8-100-comparacao-onu.png`
- `tmp/net8-125-comparacao-arvore.png`
- `tmp/net8-125-comparacao-grupo.png`
- `tmp/net8-125-comparacao-onu.png`

Originais individuais: `tmp/net8-{100,125}-{mockup,arvore,grupo,onu}.png`. Capturas de grupo mostram equipamento. Os testes exercitam diretamente equipamento e PON; site compartilha o mecanismo, mas não teve verificação visual individual. Não há captura individual de site/PON. Nenhum elemento hidden visível nas duas escalas; imagens de equipamento e ficha revisadas. Zoom foi em escala CSS 1 e 1,25, não comprova todos os mecanismos de zoom nativo de navegador.

## Hipótese e não verificado

O desenho foi adaptado aos dados reais e aos componentes atuais; não é reprodução pixel a pixel, principalmente por preservar as cores atuais das faixas. Não foi validado login real no vhost nem a aparência em todos os tamanhos/dispositivos. Modelo real da OLT não cadastrado em attrs_json permanece desconhecido para esta tela. Nenhum contato com OLT, SNMP, escrita em configuração, interrupção de cron, push ou leitura de .env.
