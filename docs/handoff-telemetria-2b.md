# Telemetria fibra — Etapa 2B, código e testes

Worktree: `/var/www/html/_isp_auxiliar_telemetria_fibra`, branch `feature/telemetria-fibra`.
Base conferida: `ae82a5d`. Trabalho concluído sem supervisão, com equipamento real fora dos testes.

## Commits

- `a9b1cd4`: checkpoint 1, migration 033/rollback, defaults e streaks de potência.
- `8068592`: checkpoint 2, NetAlertEngine, interface/stores de alertas e CLI.
- `39289de`: checkpoint 3, intervenção auditada, referência nova e retenção em lotes.
- `30e0ccb`: checkpoint 4, agendador, lock compartilhado, backoff, log seguro e testes.
- Este documento e o fechamento do roadmap ficam em commit documental posterior.

## FATO — pronto

- 033 aditiva e reaplicável: alertas com índice único somente para abertos, intervenções persistidas para silenciar também alertas futuros, intervalos status/potência, data da tentativa de potência e streaks. 030–032 intactas.
- Referência de potência recalculada só com a nova série após intervenção; histórico antigo preservado. Streaks saturam em 65535.
- Alertas de equipamento inacessível, massa por PON, ONU instável, faixa e desvio. Equipamento inacessível suprime criação/avaliação de filhos até status ok; filhos existentes ficam preservados. Sem inferir dying gasp.
- Massa: denominador reconstruído no início da janela por eventos up/down e estado atual; offline anterior não conta. Coorte de ONUs preservada após a janela; fechamento exige mais da metade novamente online com evento up. PON com duas ONUs pode abrir; uma nunca abre massa. Escopo PON suporta índices 1–999, pela codificação solicitada `device_id*1000+pon_index`.
- Silêncio não apaga alertas nem cria duplicatas: continuam registrados com `silenced_until`. Intervenções PON abrangem alertas da PON e das ONUs nela; intervenções ONU abrangem aquela ONU. Reset/auditoria/silêncio são atômicos; falha de auditoria desfaz tudo. Eventos/amostras antigos não são apagados na intervenção.
- Retenção: simulação por padrão, `--apply` em lotes de 5000; amostras 60 dias/eventos 180 por configuração do provedor; alertas somente fechados há mais de 180 dias. Preserva borda exata e alertas abertos. Sem inventar saída de desvio quando seu evento antigo é removido pela retenção.
- Tick reutiliza os dois coletores com transporte injetável. Status vencido desde `last_attempt_at`; potência desde `last_power_attempt_at` e somente após status ok (deste tick ou último). Falha de potência registra tentativa, sem sobrescrever resultado do status. Alertas avaliados após coleta ou decisão de não coletar.
- Lock usa o mesmo caminho/flock do net_collect; net_alerts também compartilha. Force vence intervalos, mas não backoff nem requisito de status ok. Pré-validação da 033 na CLI impede SNMP com schema incompleto.
- `net_tick --dry-run` só calcula plano e alertas sobre o estado salvo: não inicializa transporte, não escreve no store nem log. Adquire o lock transitório. Difere de `net_collect --dry-run`, que lê equipamento: **não usar esse último como teste sem rede**.
- Log `storage/logs/net_tick-AAAA-MM-DD.log`: linhas JSON com resultados/contagens permitidos; sem credencial, identidade ou mensagem arbitrária de transporte. Erros retornam mensagem genérica; diagnóstico detalhado exigirá inspeção supervisionada da configuração/perfil.

## Testes

| Comando | Resultado final |
|---|---:|
| `php tests/Feature/NetStatusCollectorSmoke.php` | 117 verificações, 0 falhas |
| `php tests/Feature/NetPowerCollectorSmoke.php` | 44 verificações, 0 falhas (39 anteriores + 5) |
| `php tests/Feature/NetAlertEngineSmoke.php` | 43 verificações, 0 falhas |
| `php tests/Feature/NetMaintenanceSmoke.php` | 27 verificações, 0 falhas |
| `php tests/Feature/NetTickSmoke.php` | 34 verificações, 0 falhas |
| `php tests/Feature/Net033MigrationSmoke.php` | 20 verificações, 0 falhas |
| `php tests/Feature/MapRouteSmoke.php` | 12 verificações, 0 falhas |
| `bash tests/map/run_all.sh` | 25 cenários passaram, 0 falharam |

Os quatro testes de regressão exigidos foram executados ao fim de cada checkpoint. Sintaxe PHP e `git diff --check` verdes.

Os testes de telemetria usam memória/FakeSnmpTransport ou socket local no schema **exclusivo** `isp_auxiliar_net033_sim`, sem carregar `.env`. O teste SQL recusa schema preexistente; cria dependências sintéticas, aplica 030–033 somente nele, reaplica 033, verifica unicidade/histórico, executa rollback, reaplica, testa PDO/transações e remove o schema em finally. Zero schemas descartáveis restantes ao final. Não executar esse teste em paralelo com ele próprio.

A suíte preexistente do mapa usa a configuração local e grava suas fixtures `ZZ TESTE`/auditoria fictícia, limpando-as ao final; esse comportamento foi mantido conforme a execução pedida. Seus avisos de headers e uma checagem de configuração real pulada já existiam. As notas “sem banco principal” dos checkpoints significam **sem migration ou escrita de telemetria no banco principal**, não ausência das fixtures obrigatórias do mapa.

## FATO — deliberadamente não feito

- Nenhum acesso de rede a equipamentos, nenhum tick/coleta SNMP real. CLIs net_collect, net_alerts e net_tick não foram executadas; classes testadas diretamente com transporte falso e scripts lintados.
- 033 não aplicada ao `isp_auxiliar`; aplicador oficial não executado. Nenhuma alteração em migrations 030–032.
- Nenhum cron instalado, nenhum push, deploy ou promoção. Nenhuma leitura manual/impressão de `.env` ou `/root/*.env`.
- `tests/map/node_modules` é symlink não rastreado preexistente e ficou fora dos commits.

## HIPÓTESE / limites

Os testes demonstram comportamento com dados e transporte simulados; não demonstram disponibilidade, latência ou comportamento atual da OLT. A causa de queda em massa permanece indeterminada: não há dying gasp validado no SNMP. Não houve teste visual de interface, pois esta etapa não altera a interface.

## Amanhã — execução supervisionada, não autorizada por este handoff

1. Confirmar autorização na conversa de execução para migration, UM tick real e cron. Conferir worktree/branch, status e commits acima; não trocar branch nem incorporar outros trabalhos. Conferir permissões de `tmp/net_collect_device_1.lock` e `storage/logs` para `www-data`, sem apagar lock ativo ou ler credenciais. Não agendar net_collect em paralelo com net_tick.
2. Conferir migrations pendentes e backup pelo procedimento operacional; a única nova desta etapa é `033_net_alerts.sql`. Aplicar com o aplicador oficial **somente após autorização**:

   ```sh
   cd /var/www/html/_isp_auxiliar_telemetria_fibra
   php scripts/apply_migrations.php
   ```

   Confirmar aplicação da 033 e campos/tabelas novos. Até lá, não usar coleta de potência com este código, pois os streaks exigem a 033. Rollback manual: `scripts/rollback_033_net_alerts.sql`, destrói histórico de alertas/intervenções, remove colunas e metadados da 033; não recupera baselines zerados e preserva status_interval_s=600 para não desfazer configuração manual. Não executar automaticamente em reação a falha de teste.

3. Rodar **UM** tick real supervisionado (sem `--approve-identity`; preserva a referência aprovada):

   ```sh
   php scripts/net_tick.php --device=1 --force-status --force-power
   ```

   Conferir retorno JSON, status/potência, contagens de ONUs, faixas, alertas, datas de tentativa e log sem segredos. `--force-*` não ignora backoff: se retornar `backoff`, respeitar o prazo e revisar antes de nova execução. `locked` retorna código 3; falhas retornam 2. Identidade diferente interrompe coleta e bloqueia potência. Não refazer baseline de inventário nem aprovar identidade automaticamente.

4. Após resultado real revisado e autorização, instalar **uma** linha em cron de sistema (campo de usuário incluído):

   ```cron
   * * * * * www-data php /var/www/html/_isp_auxiliar_telemetria_fibra/scripts/net_tick.php --device=1
   ```

   Status padrão a cada 600 s e potência 1800 s; cron apenas verifica vencimento a cada minuto. Verificar execução/permissões pelo usuário do cron e logs subsequentes. Não instalar a sugestão antiga `*/5 ... net_collect.php` junto.

5. Intervenção e retenção, quando necessárias, podem ser revisadas primeiro sem escrita:

   ```sh
   php scripts/net_alerts.php intervene --device=1 --pon=1 --hours=24 --reason="Manutencao planejada" --dry-run
   php scripts/net_purge.php
   ```

   Para executar uma intervenção autorizada, remover `--dry-run`; para retenção autorizada, acrescentar `--apply`. Nenhuma dessas execuções foi feita nesta tarefa. Não foi proposto cron adicional para retenção.
