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

## Validação final e exemplos

**Fato:** a suíte final foi executada numa cópia temporária do código, sem `.env` do worktree, e em schemas descartáveis clonados do banco de teste, removidos ao final. `tests/Feature/*.php` mais `AcceptanceResendRegression.php`: 48 passaram, 0 falharam. `tests/map/run_all.sh`: 25 passaram, 0 falharam. `tests/net/run_all.sh`: 42/90/100 verificações, zero falhas. A primeira execução isolada do mapa deu 23/25 porque o ambiente temporário selecionou provedor `default`; após configurar o provedor `ievo` presente na cópia, a repetição passou 25/25. A primeira execução Feature com substituto de Env deu 45/48 por não reproduzir variáveis de ambiente; a repetição sem esse substituto, em cópia sem `.env`, passou 48/48. A suíte de rede foi repetida após os ajustes finais. Nenhuma fixture dos testes foi gravada no banco real.

A simulação contou exatamente 22 boot, 29 troca de PON, 39 energia, 2 sem aviso e nenhuma em aberto. Exemplos convertidos (MAC mascarado, início UTC → Brasília):

- `00:6D:61:xx:xx:xx`: 10/11 19:30:45 → 10/11 16:30:45.
- `00:6D:61:xx:xx:xx`: 10/11 21:41:47 → 10/11 18:41:47.
- `00:2E:C7:xx:xx:xx`: 10/11 22:25:28 → 10/11 19:25:28.
- `00:6D:61:xx:xx:xx`: 11/11 00:09:57 → 10/11 21:09:57.
- `F4:4C:7F:xx:xx:xx`: 11/11 07:48:31 → 11/11 04:48:31.

Resíduos dos oito pares (coleta UTC menos log corrigido), na ordem do clock check: 133, 43, 89, -11, -8, 10, -6, 231 segundos; mínimo -11, mediana 26,5, máximo 231. Os três negativos impedem o apply pela condição explícita 0..660. Sem dump de pré-import nem escrita de import. O teste de conversão do caso 0/2:8 confirma 13/11 09:29:10 em Brasília para o início, mas não houve gravação no banco para confirmar a linha completa.

**Hipótese:** diferenças negativas de poucos segundos podem decorrer de deslocamento impreciso ou fontes com relógios distintos; não há evidência para escolher uma explicação. Nenhum limiar foi relaxado.

**Não verificado:** aparência no navegador, leitura autenticada real da tela, as contagens e desduplicação após importação, e comportamento em concorrência entre dois imports. Não houve acesso à OLT, interrupção do cron ou push.
