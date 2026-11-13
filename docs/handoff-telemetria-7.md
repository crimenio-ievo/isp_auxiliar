# Telemetria — etapa 7

## Passo 0
Fato: branch `feature/telemetria-fibra`, início em `95010a1`; não rastreados `docs/design/mockups/index.html` e symlink `tests/map/node_modules`, preservados. Nenhum teste em execução nem schema `isp_auxiliar_net*_sim` órfão no início. Lock de coleta existente, não removido. Logs desde 12/11 14:01:33 até 13/11 15:58:01: 1362 ticks e nenhum intervalo maior que 1800 s (3 ciclos de status de 600 s). Não é possível conferir antes do primeiro registro disponível. Cron não interrompido.

Suíte de linha de base executada em cópia temporária sem `.env` real, com schema descartável: Feature + Acceptance 48/48, mapa 25/25, net sem falhas. Nenhum reparo de trabalho parcial necessário.

## CP-B
Pulado: `docs/design/rede-fibra-mockup.html` não existe. O arquivo não rastreado em outro caminho não foi tomado como referência nem alterado. Visual não recriado de memória.

## Passo 1 — import autorizado
Fato: `NetStatusCollector::run` captura `$now` antes de consultar; `applyCycle` repassa esse valor a `insertEvents`, que grava `occurred_at`. É hora do início, não fim. Oito cycle_ms reais: 64817, 66291, 61123, 65756, 65261, 66837, 66369, 66702. Todos os resíduos 133,43,89,-11,-8,10,-6,231 passam na faixa individual `-(cycle_ms/1000+5)..600+cycle_ms/1000`, sem alterar 82166. Simulação: 92 retornos, 22 boot, 29 troca de PON, 41 quedas, 0 abertas/divergentes.

Dump `/root/isp-auxiliar-backups/isp_auxiliar-antes-import-net7-20261113T160131Z.sql` (635391 bytes, modo 600). Import com o segmento e fuso pedidos: 92 novas; repetição: novas=0, divergentes=0, inalteradas=92. Banco: queda/energia 39, queda/sem_aviso_energia 2, manutencao/boot 22, manutencao/troca_pon 29. Caso 0/2:8: UTC 13/11 12:29:10–13:07:23, Brasília 09:29:10–10:07:23, 2293 s.

Comparação em mesmo instante congelado: `resumo.quedas_24h` da árvore 6 → 15 (contador da árvore; não confundir com número de pares do poll). Oito intervalos do poll deixaram de aparecer, cobertos pelo log. Inícios UTC: ONU id59 12/11 19:36:01 e 13/11 08:49:01; id67 13/11 00:40:02 e 01:01:01; id60 07:27:01; id79 08:27:02; id74 08:59:01; id82 12:33:01. Horas exatas/durações do log passam a ter preferência. Não houve escrita fora do import/auditoria no banco real, nem contato com a OLT.
