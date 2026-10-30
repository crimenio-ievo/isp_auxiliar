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

## Comparação após extração

Commit de testes e linha de base: `c77a1ed`. Mesmos comandos, ambiente e
expectativas após mover o motor para `modules/isp_map/diagram.js`:

| Verificação | Nova linha de base | Após extração |
| --- | --- | --- |
| Suites PHP | 7 passaram | 7 passaram |
| Cenários jsdom | 16 passaram | 16 passaram |
| Total / exit code | **23 / 0** | **23 / 0** |
| Falhas / erros de console | 0 / 0 | 0 / 0 |
| Checagem de escrita em configurações | 1 pulada com aviso | 1 pulada com aviso |
| Fixtures ZZ TESTE restantes | 0 | 0 |
| Nós / ligações / portas após limpeza | 4 / 2 / 37 | 4 / 2 / 37 |
| Hash SVG + painéis do diagrama | `1afbec8e402663b2aec9d7eeea9e53b82e40d2b25c47adb76bdedb21fd641e1c` | Idêntico |

As 1.164 linhas movidas foram comparadas textualmente com o commit de
testes: idênticas ao normalizar somente os acessores `state.*` para os
seis arrays compartilhados. SHA-256 desse código normalizado:
`6a75ffeee623144d4073804005e41d6f64bdd36052db0c858d5d48a363228db3`.
Constantes de perda e splitter, algoritmos, menus, painéis e estado de
edição foram preservados. O motor expõe apenas `openDiagram` e `init`;
a ponte por acessores acompanha substituições dos arrays nas recargas.
A saída completa dos 16 cenários jsdom também foi comparada textualmente:
idêntica à linha de base, incluindo hash, mensagens e resumo final.

Verificações adicionais (fora do contador de regressão):

- `php tests/map/check_diagram_route.php`: 12 verificações passaram, sem
  escrita no banco; sessão exigida, técnico restrito como `/mapa`, gestor
  e admin recebem JS idêntico ao arquivo com MIME JavaScript e no-store.
  HTML referencia o asset antes da inicialização, inclusive em subdiretório.
- Sintaxe PHP do controller, rotas e teste de rota; sintaxe do JS externo
  e inline; `git diff --check`: passaram.
- Smoke no vhost local **testes.ievo.com.br** (.89): HTTP `/login` 200;
  `/mapa` e `/mapa/diagram.js` sem sessão 302. O certificado HTTPS local
  não cobre esse hostname; repetindo localmente sem validar o certificado,
  os mesmos status foram obtidos. Configuração do servidor não alterada.

Desvios: somente a checagem de escrita em configurações foi pulada, por
regra explícita, e os warnings históricos de headers CLI foram mantidos.
Sem migration, merge, push, deploy ou início de outras fases.

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
