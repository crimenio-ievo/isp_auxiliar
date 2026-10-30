# Regressão ISP Map

Executar somente no servidor de teste (.89), no worktree desta branch:

```sh
cd tests/map
npm ci
cd ../..
bash tests/map/run_all.sh
```

Requisitos: PHP 8.2 com PDO MySQL, Node 18 ou superior e configuração local
da aplicação já existente. Não imprimir nem copiar credenciais. A dependência
jsdom 24.1.0 fica restrita a estes testes; a aplicação não usa npm/bundler.

O runner executa sete suites PHP e dezesseis cenários jsdom, imprime a saída
original e resume quantos passaram/falharam. Retorna 1 se houver falha,
exceção ou erro de console. Alguns harnesses antigos são observacionais:
imprimem valores para revisão, sem afirmar todos os resultados automaticamente.
Não executar em paralelo.

Os PHP exercitam as rotas diretamente com sessão fictícia, sem criar usuário.
Gravam exclusivamente fixtures com nomes `ZZ TESTE`, usam `finally` para
limpar nós/ligações/portas e removem a auditoria nova da sessão fictícia ao
encerrar. A execução recusa nós `ZZ TESTE` preexistentes para não apagá-los.
Nenhuma migration é executada. Não executar em produção.

Nenhum teste pode escrever em tabelas de configuração reais. A checagem de
sobreposição de `map.loss.fusion_db` em `suite_tx.php` é pulada com aviso
`[PULADO]`: não faz INSERT, UPDATE nem DELETE em `provider_settings`.
A leitura de `data.defaults` continua testada. Os demais testes escrevem
somente nas fixtures do mapa e na auditoria das sessões fictícias.

Filtro exato da limpeza de auditoria (inalterado, somente atores fictícios):

```sql
DELETE FROM audit_logs
WHERE id > ?
  AND actor_login IN ('zz_teste_manager', 'zz_teste_technician', 'zz_teste_tec')
  AND action LIKE 'map.%'
```

Os jsdom usam topologias e respostas HTTP fictícias, sem gravar no banco.
`harness.js` resolve o HTML relativamente ao repositório; os scripts antigos
em `js/` recebem esse caminho pelo runner.
`read_html.js` inclui o asset separado na ordem dos scripts para o jsdom,
sem buscar recursos externos. Para validar também a rota e o carregamento
em subdiretório: `php tests/map/check_diagram_route.php` (somente leitura;
sem sessão, técnico, gestor e admin; conteúdo, tipo MIME e no-store).

Origem preservada:

- `suite.php`, `suite_life.php`, `suite_site.php`, `suite_links_1d.php` e `js/`:
  harnesses da fase 1C–1F (`367f9011`). O nome `suite_links_1d.php` preserva
  a suite antiga sem sobrescrever a suite de portas/ligações posterior.
- `common.php`, `suite_ports.php`, `suite_links.php`, `harness.js`, `b.js`,
  `c.js`, `dd.js`, `e.js`: harnesses da fase 1G (`05151c2f`).
- `suite_tx.php`, `f.js`: fase 2B, mesma origem (`05151c2f`).
- `diagram.js`: regressão da extração 2R pela interface (emendas, perdas,
  tracing, menus, zoom e recarga), com hash do SVG/painéis para comparação.
- `js/dark.js`: utilitário histórico de geração de imagens com tiles reais,
  fora do runner (não é teste jsdom e depende de `pngjs`, não instalado).

A checagem histórica da auditoria de lifecycle busca a transição específica
`ativo` → `desativado` pelo `entity_id` e por `context_json`, em vez da última
edição (que pode ser só de nome). Falhas na linha de base devem ser relatadas
antes de qualquer extração do motor.
