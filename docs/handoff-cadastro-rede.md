# Handoff — cadastro de rede (feature/cadastro-rede)

Servidor .89 de TESTE. Sessão autônoma iniciada em 2026-11-19. Convenção: **Fato** (verificado), **Hipótese**, **Não verificado**.
Worktree temporária `/var/www/html/_isp_auxiliar_cadastro_rede` (branch `feature/cadastro-rede`, a partir do main 56ec7a4).
Documento em construção: atualizado a cada fase.

## Fases concluídas
| Fase | Commit | Resumo |
|---|---|---|
| 0 | 0e93b7c | Hardening do Apache (`docs/handoff-seguranca-apache.md`); commits de produção estão no main. |
| 1 | b43ae8d | Levantamento e guia (`docs/rede-cadastro-guia.md`). |
| 2 | eb6a61d | `/rede-equipamentos`, teste de conexão, `net_tick.php --all`. |

## Fase 2 — detalhes
- Fato: 104 verificações (`NetDeviceCadastroSmoke`), 19 (`NetTickAllSmoke`), 34 jsdom (`rede_equipamentos.js`).
- Teste real com a OLT .89 (2 de 3 permitidos): 1º com timeout de 2 s → **sem resposta**; 2º com 3 s → respondeu (sysName/sysDescr/sysUpTime, 1,07 s). Por isso `NetConnectionTester::TIMEOUT_MS = 3000` (o mesmo do perfil Hioso). Hipótese: o agente SNMP às vezes passa de 2 s na 1ª leitura. Nenhum SET.
- Decisões tomadas sozinho: (a) perfis não suportados (V-SOL, HA7308VX, Outro) só gravam `attrs.perfil_pretendido` no nó do mapa e NÃO criam `net_devices` (a tabela exige perfil válido) — alternativa descartada: perfis "draft" fictícios via migration; (b) mudar IP/perfil/credencial com coleta ativa desativa a coleta (conservador); (c) IPv4 apenas, sem loopback/link-local/multicast (evita usar o teste como sonda interna); (d) o teste usa o mesmo lock por equipamento da coleta e a trava de 30 s vem de `audit_logs` (sem migration); (e) menu lateral: item "Rede de fibra" renomeado para "Rede" (aba interna: Rede de fibra · Equipamentos…).
- Coletor `--all`: **cron NÃO alterado**. Ver "PARA O USUÁRIO".
