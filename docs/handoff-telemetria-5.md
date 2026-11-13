# Telemetria fibra — Etapa 5

Worktree `_isp_auxiliar_telemetria_fibra`, branch `feature/telemetria-fibra`. Regras desta etapa: sem contato com a OLT, nenhum `--apply` do import, nenhuma migration nova no banco principal, cron de telemetria intacto, sem push, sem trocar de branch, `.env` não lido, só SELECT no banco real.

## PASSO 0 — acesso ao vhost interno (feito)

- FATO: o log `/var/log/apache2/telemetria-interno-error.log` mostra `AH01630: client denied by server configuration` para `client 10.4.0.2` (4 negações em `telemetria-interno-access.log`, todas de `10.4.0.2`, que é também o IP que aparece nas linhas de login da OLT).
- Alterado (único ponto fora do repositório): `/etc/apache2/sites-available/isp-auxiliar-telemetria-interno.conf`, linha do `Require ip`:
  `Require ip 192.168.23.0/24 10.4.0.2` (rede atual mantida, +1 endereço, não faixa). Backup da versão anterior só na área temporária da sessão.
- `apachectl configtest` → Syntax OK; só então `systemctl reload apache2`.
- Conferido com `curl -k`: sem sessão, `https://192.168.23.89:8445/rede-fibra` → 302 para `/login`; `/api/rede-fibra` → 302 para `/login`; `/login` → 200.
- **Como remover:** editar o arquivo acima e voltar a linha para `Require ip 192.168.23.0/24`, depois `apachectl configtest && systemctl reload apache2`. (Remoção do vhost inteiro, como já documentado no cabeçalho do arquivo: `a2dissite isp-auxiliar-telemetria-interno && apachectl configtest && systemctl reload apache2`.)
- Nenhum outro vhost foi criado ou alterado.

## CP0 — fuso do relógio da OLT e simulação do import (feito, somente leitura)

### Simulação do import (`--olt-tz=America/Sao_Paulo`, sem `--apply`)
Resultado idêntico à contagem independente: **92 retornos, 22 boot, 29 troca de PON, 41 quedas (39 energia + 2 sem aviso), 0 em aberto**, 331 eventos de ONU, 56 Dying Gasp e 19 linkup duplicados descartados, 0 MAC sem ONU cadastrada. Nenhuma divergência. As contagens não dependem do fuso.

### Fuso: NÃO é UTC nem Brasília — o relógio da OLT estava errado (FATO + HIPÓTESE)
Ferramenta nova, somente leitura: `php scripts/net_log_clock_check.php --device=1 --file=ARQ [--expect=SEG] [--tolerance=SEG]` (compara quedas do log com os down/up de `net_onu_events`; MACs mascarados).
- FATO: 8 pares MAC a MAC (log × coleta). O deslocamento (hora da coleta em UTC − hora do log lida como UTC) é **22h49m–22h53m (mediana 22h50m)** em todos. Com `--expect=0` (UTC) e `--expect=10800` (UTC-3) a ferramenta não casa nenhum par. Se o relógio da OLT fosse Brasília, o deslocamento esperado seria 3 h; é ≈ 19h50m a mais.
- FATO: pares incluem o 0/2:8 (log 12/11 13:39:44 → 14:17:57; coleta down 13/11 12:33:01 UTC → up 13:14:01 UTC): duração 38 min no log, 41 min na coleta; deslocamento 22h53m.
- FATO: existe um salto no arquivo, na ordem das linhas: `Nov 12 14:23:39` (login web) → `Nov 13 10:13:47` (próxima linha), ≈ 19h50m. Isso bate com o deslocamento medido (≈ 19h49m a Brasília). Ou seja: o relógio da OLT foi corrigido entre essas duas linhas (a sua atualização desta manhã) e o que veio antes carrega o erro. Sua observação "o log não tem salto" não se confirma: o salto está nessa passagem, sem evento no meio.
- FATO: os down/up da coleta entre 12/11 19:36 UTC e 13/11 13:14 UTC (8 pares) aparecem no log com a hora errada (12/11 de manhã); não há evento de ONU no log depois de 12/11 14:17:57 (hora errada).
- HIPÓTESE: antes da correção a hora gravada era `Brasília − 19h49m (±4 min)` e constante. Confirmada só para o intervalo coberto pela coleta (log de 11/11 ~20:44 a 12/11 14:17, hora errada). Para o que veio antes (9/11 a 11/11) NÃO há coleta para conferir: não sei se o erro já existia no boot de 9/11 ou surgiu depois. Se você realizou a troca de PON na hora real 9/11 20:23, o erro não existia naquela data.
- **Consequência para o import real:** `--olt-tz` (um fuso só) não resolve: com America/Sao_Paulo todas as horas do arquivo ficariam ≈ 19h49m erradas. Falta a DECISÃO sua: (a) corrigir por trecho (somar ≈ 19h49m até a linha do salto, nada depois) com uma opção explícita no import, e escolher o ponto de início do erro (9/11? 11/11?), ou (b) importar só quedas após a correção do relógio, ou (c) importar com horas "na hora errada da OLT" marcadas como não confiáveis. Não implementei nada disso (decisão sua). **Não rodar `--apply` antes.**
- Limite: a coleta tem precisão de um ciclo (600 s); 8 pares concordam entre si em ±4 min, o que é forte, mas todos vêm de um intervalo de ~1 dia.

## CP1 — nível Site na tela "Rede de fibra" (feito)

- Sem migration. `NetFiberReader::tree()` junta `map_nodes` (equipamento) com o pai `parent_node_id` só quando o pai é `pop`/`tower` (o site) e com `net_device_profiles.technology`.
- API: cada item de `dispositivos` mantém as chaves atuais e ganha `tecnologia` (hoje `epon`, do perfil), `tipo_no` (tipo do nó do mapa: olt, switch, router, radio, server, other) e `site` (`{id, nome, tipo}` ou `null`). Equipamento cujo pai não é site (ou sem pai) vem com `site: null` e a tela o mostra em "Sem site". Chaves do topo da árvore inalteradas.
- Tela: Site > Equipamento > PON > ONU, com filtro "Site" (inclui "Sem site") e o filtro "OLT" virou "Equipamento". O rótulo do equipamento vem do tipo do nó ("OLT OLT-001", "Switch SW-1"…, "Equipamento" para tipos desconhecidos) mais a tecnologia em maiúsculas quando existe; nada assume OLT. `net_onus` não foi generalizado.
- Conferido SOMENTE LEITURA no banco real: OLT-001, tecnologia `epon`, site POP "iEvo".
- Testes: `NetFiberReaderSmoke` 65 verificações (site/pop pai, equipamento `other` com pai CTO → "Sem site", chaves novas) e `tests/net/rede_fibra.js` 69 (rótulos, ordem da árvore, filtro por site, recolher site, Switch sem tecnologia). Listas de chaves compartilhadas atualizadas nos dois.

## CP2 — saúde da coleta na tela (feito, somente leitura)

- API: cada equipamento ganha `saude` = `{estado, minutos, limite_s}`; o resumo ganha `sem_resposta` (ONUs com `desatualizado`). Regras (no servidor, relógio do banco): `sem_leitura` (nunca houve leitura ok) · `falha` (último resultado diferente de ok, inclusive `partial`, mesmo com leitura recente) · `atrasada` (última leitura ok mais velha que 2 × `status_interval_s`; no limite exato ainda é ok) · `ok`. `minutos` = tempo desde a última leitura ok.
- Tela: faixa de alerta no topo, uma linha por equipamento fora do normal: "{OLT|Switch|…} {nome} sem resposta há X min: os estados abaixo podem estar desatualizados (última leitura ok: dd/mm hh:mm, horário de Brasília; resultado da última coleta: …)" ou "…: ainda sem nenhuma leitura registrada". Não afirma a causa (nada de rede, energia ou fibra).
- ONU com `desatualizado` (a OLT não respondeu, `markDeviceStale`) aparece como pílula "Sem resposta · último: Online/Offline", distinta de "Offline" (que só vale quando a OLT respondeu). Filtro "Sem resposta (desatualizado)" novo; os filtros Online/Offline excluem as desatualizadas; o resumo mostra "sem resposta: N".
- Testes: `NetFiberReaderSmoke` 73 verificações (ok/atrasada/falha/parcial/sem_leitura, limite, stale vs offline, ficha) e `tests/net/rede_fibra.js` 82 (faixa, texto, horário de Brasília, sem causa, um aviso por equipamento, pílula, filtros, resumo).
- Observação (FATO do banco real, somente leitura): hoje o OLT-001 está com leitura ok recente e nenhuma ONU desatualizada, então a faixa fica oculta.
