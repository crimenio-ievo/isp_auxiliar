# Fase 2R — linha de base

## Continuação — nova linha de base verde (2026-10-30)

Aplicação ainda intacta em `af77117`; harnesses incorporados em `697b59b`
e ajustados nesta continuação. Comando: `bash tests/map/run_all.sh`.
Resultado: **23 passaram; 0 falharam**, exit code 0 (7 suites PHP,
16 cenários jsdom). Sintaxe PHP e shell validada.

- `suite_life.php` agora filtra a transição `ativo` → `desativado` no
  `context_json` do `entity_id` da fixture, sem depender da última edição.
- Testes 2B (`suite_tx.php` e `f.js`) passaram. A checagem de sobreposição
  em `provider_settings` é explicitamente **pulada** com aviso; a escrita
  e a exclusão históricas foram removidas. Nenhuma configuração real escrita.
- `diagram.js` passou nas 12 asserções de interface. Hash do SVG e painéis:
  `1afbec8e402663b2aec9d7eeea9e53b82e40d2b25c47adb76bdedb21fd641e1c`.
- Zero erros de console reportados; permanecem os warnings históricos de
  headers no CLI em `suite.php`, sem falha de asserção.
- Sete suites PHP encerraram com zero fixtures `ZZ TESTE`. Contagens
  preservadas: **4 nós, 2 ligações, 37 portas**. Limpeza de auditoria
  permanece restrita aos atores fictícios (filtro exato no README).

Pós-extração: pendente no momento deste commit de testes.

## Histórico — primeira execução interrompida

Executada em 2026-10-30 no .89, worktree `_isp_auxiliar_redesign_shell`,
branch `feature/mapa-fase1`, aplicação no commit `af77117`.

Comando: `bash tests/map/run_all.sh` (PHP 8.2.30, Node 18.20.4,
jsdom 24.1.0). Saída final: **19 passaram; 1 falhou**, exit code 1.

| Cenário | Antes da extração | Depois |
| --- | --- | --- |
| PHP suite.php | Passou nas asserções; warnings de headers | Não executado |
| PHP suite_life.php | **Falhou: auditoria registra lifecycle antes/depois** | Não executado |
| PHP suite_links.php | Passou | Não executado |
| PHP suite_links_1d.php | Passou | Não executado |
| PHP suite_ports.php | Passou | Não executado |
| PHP suite_site.php | Passou | Não executado |
| js/run.js: empty, one, session, forbidden | 4 passaram | Não executado |
| js/coords.js, flow.js, life.js, links.js, name.js, site.js | 6 passaram | Não executado |
| b.js, c.js, dd.js, e.js | 4 passaram | Não executado |

Os quatorze cenários jsdom terminaram sem erros de console reportados.
Os scripts observacionais preservam seus limites descritos no README.
`suite.php` emite `Cannot modify header information` ao enviar respostas
depois de imprimir asserções no CLI; nenhuma asserção dela falhou.

A falha de `suite_life.php` é anterior a qualquer refatoração. O harness
consulta o último `map.node.update` depois de editar o nome do nó, e exige
nesse registro a transição `ativo` → `desativado` feita em uma chamada
anterior. Isso sugere expectativa histórica inadequada para o registro
selecionado; não houve correção da expectativa nem da aplicação nesta fase.

Limpeza observada: todas as seis suites terminaram com **0 nós ZZ TESTE**.
Contagens finais: **4 nós, 2 ligações e 37 portas**, iguais às iniciais.
A auditoria nova gerada pelas sessões fictícias é removida por `safety.php`.
Não houve migration nem alteração de registros reais.

**Extração interrompida pela regra da tarefa:** nenhum arquivo da aplicação
foi alterado, `diagram.js` e sua rota não foram criados. Não há comparação
pós-extração nem segundo commit de extração.
