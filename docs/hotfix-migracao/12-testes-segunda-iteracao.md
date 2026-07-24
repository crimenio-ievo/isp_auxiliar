# Segunda iteração — testes executados

Data: 2026-07-24. Ambiente local/homologação. Escrita MkAuth bloqueada.

## Migration

Comando:

```bash
php scripts/apply_migrations.php
```

Resultado: código 0; `016_operational_processes.sql` aplicada; nove migrations
anteriores ignoradas; zero avisos; zero erros.

## Rollback estrutural

Pré-condição confirmada:

```text
operational_processes          0
operational_process_steps      0
operational_process_documents  0
```

Foram removidas somente as duas FKs/colunas novas e as três tabelas novas. A
ausência foi confirmada:

```text
TABLES_AFTER_ROLLBACK  0
COLUMNS_AFTER_ROLLBACK 0
```

A migration 016 foi reaplicada pelo comando oficial com código 0, zero avisos e
zero erros.

Limitação: o usuário local tem privilégios somente sobre `isp_auxiliar`, então
o teste usou o banco local de homologação, depois de confirmar que as tabelas
novas estavam vazias. Nenhuma tabela preexistente foi removida.

## Teste de processos

Comando:

```bash
php tests/Feature/OperationalProcessSmoke.php
```

Resultado final: código 0; 60 verificações; rollback transacional; nenhum envio;
nenhuma escrita no MkAuth.

Cobertura:

- schema;
- token compartilhado, validade e versão;
- CSRF válido/inválido;
- criação de migração;
- criação de instalação;
- criação de solicitação avulsa;
- templates de 11/11/8 etapas;
- progresso;
- próxima pendência;
- retomada idempotente;
- documento sem duplicidade;
- avanço não linear;
- pular por enquanto;
- responsável/data da pendência;
- bloqueio da conclusão;
- aceite automático;
- origem automática;
- confirmações manuais com evidência;
- conclusão normal;
- exceção autorizada;
- cancelamento;
- renderização do checklist e da etapa mobile;
- botões de retomada;
- trava visual de duplo submit;
- payload sintético do dry-run de troca de plano;
- ausência explícita de desconexão/recálculo prometidos;
- bloqueio real do `MkAuthWriteGuard`;
- acesso do gestor e bloqueio do visualizador;
- rollback dos dados sintéticos.

## Regressão do hotfix anterior

Comando:

```bash
php tests/Feature/UpgradeCorrectionSmoke.php
```

Resultado: código 0; 40 verificações; rollback; nenhum envio; nenhuma escrita no
MkAuth.

## Sintaxe

Comandos:

```bash
php -l <cada PHP alterado>
node --check public/assets/js/app.js
git diff --check
```

Resultados: código 0 em todos os arquivos verificados; JavaScript válido; diff
sem erro de whitespace.

## Testes de segurança cobertos

- CSRF do núcleo novo;
- autorização por perfil nas rotas;
- isolamento de processo por provedor;
- aceite/contrato vinculados;
- uso único/lock já existente no aceite;
- observação/evidência obrigatória em conclusão manual;
- justificativa obrigatória na exceção;
- conclusão bloqueada;
- processo cancelado não reaberto;
- dry-run não conclui alteração real;
- escrita MkAuth bloqueada;
- nenhuma dependência da IA.

## Não executado

- envio real por WhatsApp/e-mail;
- escrita real no MkAuth;
- alteração real de plano;
- desconexão real;
- abertura/fechamento real de chamado;
- teste em celular físico;
- automação completa de navegador.

Esses itens não podem ser classificados como aprovados.
