# Telemetria — etapa 6

## Passo 1 — segmentos de relógio

**Fato:** implementados segmentos com corte inclusivo, validação, conversão para fuso do app, auditoria e recusa atômica em divergência da data de início. Chave continua usando horário bruto. Testes `NetClockSegmentsSmoke` 13 e `NetLogImportSmoke` 33 passaram. Nenhum acesso à OLT ou leitura de `.env`: scripts de verificação foram executados com substituto temporário de Env e socket local root.

**Fato:** oito resíduos em segundos: 133, 43, 89, -11, -8, 10, -6, 231. Três negativos violam 0..660 exigido; importação bloqueada por essa condição. Simulação independente: 92 retornos, 41 quedas, 51 manutenção, 0 abertas, 0 divergentes. Nenhum `--apply` executado; dump e conferência pós-import não executados.

**Hipótese:** os desvios negativos pequenos podem indicar erro no offset, diferença de relógio ou captura; a tarefa não autorizou tolerância negativa. Não escolher causa nem importar com esse resultado.

**Não verificado:** equivalência pós-import, contagens no banco, e proteção em corrida entre checagem e gravação concorrente. Nenhum dado foi importado.

## CP-A — layout
Fato: eventos após árvore/ficha, cinco inicialmente e botão até 20; árvore com altura útil e rolagem, breakpoint 1100 px. `tests/net/run_all.sh`: 0 falhas, 42/88/97 verificações. Aparência real não verificada.

## CP-B — período único
Fato: seletor padrão 7 dias antes do gráfico; API `serie` limitada ao período com amostra anterior marcada e posicionada no início, gráfico e quedas do mesmo período, eixo no horário de Brasília; últimas quedas preservadas. Suíte net: 42/90/97, zero falhas. Aparência real não verificada.

## CP-C — régua e gráfico
Fato: faixas e marcadores identificados com formas e cores, agrupamento de valores próximos e alturas diferentes para rótulos próximos; gráfico compacto com referências, vizinhos, faixas de queda e números decimais em português. Suíte net: 42/90/100, zero falhas. Aparência real e colisões em todos os tamanhos não foram verificadas em navegador.
