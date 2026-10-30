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

O runner executa seis suites PHP e quatorze cenários jsdom, imprime a saída
original e resume quantos passaram/falharam. Retorna 1 se houver falha,
exceção ou erro de console. Alguns harnesses antigos são observacionais:
imprimem valores para revisão, sem afirmar todos os resultados automaticamente.
Não executar em paralelo.

Os PHP exercitam as rotas diretamente com sessão fictícia, sem criar usuário.
Gravam exclusivamente fixtures com nomes `ZZ TESTE`, usam `finally` para
limpar nós/ligações/portas e removem a auditoria nova da sessão fictícia ao
encerrar. A execução recusa nós `ZZ TESTE` preexistentes para não apagá-los.
Nenhuma migration é executada. Não executar em produção.

Os jsdom usam topologias e respostas HTTP fictícias, sem gravar no banco.
`harness.js` resolve o HTML relativamente ao repositório; os scripts antigos
em `js/` recebem esse caminho pelo runner.

Origem preservada:

- `suite.php`, `suite_life.php`, `suite_site.php`, `suite_links_1d.php` e `js/`:
  harnesses da fase 1C–1F (`367f9011`). O nome `suite_links_1d.php` preserva
  a suite antiga sem sobrescrever a suite de portas/ligações posterior.
- `common.php`, `suite_ports.php`, `suite_links.php`, `harness.js`, `b.js`,
  `c.js`, `dd.js`, `e.js`: harnesses da fase 1G (`05151c2f`).
- `js/dark.js`: utilitário histórico de geração de imagens com tiles reais,
  fora do runner (não é teste jsdom e depende de `pngjs`, não instalado).

As expectativas históricas não foram atualizadas. Falhas na linha de base
devem ser relatadas antes de qualquer extração do motor.
