# Alteracoes do hotfix de migracao

## Preservacao da IA

- Criada branch local `feature/integracao-ia-pausada`.
- Criado commit local `0f894d5716b73376218ffeacd968ab2b714ebdd7` com os arquivos de IA/telemetria/vault/testes que estavam no working tree.
- A branch `hotfix/migracao-piloto` foi criada a partir de `58a08d0`, sem os arquivos de IA.

## Commits funcionais aplicados

- `9ed8e00` - corrige aceite publico de upgrade.
- `5c5e0de` - adiciona confirmacao segura para envio de contrato digital e migration `007`.
- `f66a7e2` - libera fluxo tecnico e adiciona guarda de escrita no MkAuth.
- `e40f04e` - permite corrigir/substituir upgrade incorreto e adiciona migration `008`.

## Mudancas principais

- Upgrade / Migracao passou a ter:
  - deteccao de processo aberto por login;
  - lock transacional por cliente;
  - validacao de tipo da operacao;
  - revisao obrigatoria antes de gerar aceite;
  - modo de assinatura local no aparelho do tecnico ou remota por link;
  - cancelamento/correcao com revogacao do aceite antigo;
  - checklist de execucao tecnica apos aceite confirmado.
- Aceite publico passou a:
  - tratar token cancelado/substituido como indisponivel;
  - impedir confirmacao duplicada;
  - salvar assinatura e evidencia;
  - esconder o termo completo atras do botao "Ler contrato e condicoes completas" no fluxo de migracao;
  - apresentar resumo curto para o cliente e botao "Confirmar migracao".
- MkAuth:
  - `MkAuthWriteGuard` bloqueia escritas reais quando `MKAUTH_WRITE_ENABLED` nao estiver explicitamente habilitado;
  - o fluxo de migracao cria pendencia/checklist local e nao altera plano/tecnologia diretamente no MkAuth durante o teste.

## Banco de dados

- `database/migrations/007_contracts_digital_acceptance_type.sql` adiciona o tipo `contrato_digital`.
- `database/migrations/008_upgrade_corrections.sql` adiciona campos de ciclo de vida/revisao para contratos e revogacao de aceites.

## Rotas alteradas/adicionadas

- `POST /clientes/upgrade/cancelar`.
- `POST /clientes/upgrade/corrigir`.
- `POST /clientes/upgrade/execucao-tecnica`.
- Rotas existentes de upgrade e aceite foram mantidas.
