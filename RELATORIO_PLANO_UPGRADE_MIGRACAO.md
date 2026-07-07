# Relatorio de Plano - Etapa 3B: Upgrade / Migracao / Refidelizacao

## Objetivo

Permitir abrir um cliente existente, escolher um novo plano/tecnologia/beneficio e gerar um aceite contratual para assinatura remota, reutilizando o fluxo da Etapa 3A.

## Resposta objetiva

### Precisa migration?

Sim, muito provavelmente.

Motivos:

- `client_contracts.tipo_aceite` hoje e um `ENUM` com valores fixos e nao inclui `upgrade_migracao`.
- O contrato atual nao possui campos explicitos para guardar, de forma auditavel, o estado "atual x novo" de plano e tecnologia.
- Para manter a prova do que foi apresentado ao cliente sem depender apenas de texto solto, o ideal e persistir um snapshot estruturado do upgrade.

Menor caminho recomendado:

- migration para ampliar `tipo_aceite` com `upgrade_migracao`;
- migration para guardar o snapshot do upgrade, de preferencia em um campo JSON/texto ou em colunas discretas.

### Tabelas reaproveitaveis

Reaproveitaveis:

- `client_contracts`
- `contract_acceptances`
- `notification_logs`

Opcional, dependendo do fluxo operacional:

- `financial_tasks` para abrir pendencia interna apos aceite, se a equipe quiser rastrear a conclusao manual no MkAuth.

Nao parece necessario criar tabela nova para esta etapa.

## Menor implementacao possivel

A ideia mais simples e segura e esta:

1. Abrir o detalhe do cliente.
2. Exibir um botao `Upgrade / Migracao`.
3. Abrir uma tela/formulario nova, pre-preenchida com os dados atuais do cliente e do contrato.
4. Permitir escolher:
   - plano atual
   - novo plano
   - tecnologia atual
   - nova tecnologia
   - beneficio concedido
   - valor do beneficio
   - novo valor mensal
   - prazo de fidelidade
   - observacao
5. Gerar um novo contrato local com `tipo_aceite = upgrade_migracao`.
6. Gerar aceite remoto usando o fluxo ja pronto da Etapa 3A.
7. Ao aceitar, o operador faz a alteracao manual no MkAuth.

Ponto importante:

- Nao alterar MkAuth automaticamente.
- Nao mexer em login/senha/roteador.
- Nao trocar titularidade.
- Nao criar um caminho novo de aceite; reaproveitar o de `assinatura_pendente` e `aceito`.

## Arquivos a alterar

### Provaveis arquivos principais

- `backend/Controllers/ClientController.php`
- `backend/Views/clients/detail.php`
- `backend/routes.php`
- `backend/Controllers/AcceptanceController.php`
- `backend/Controllers/ContractController.php`
- `backend/Infrastructure/Contracts/ContractRepository.php`
- `backend/Infrastructure/Contracts/ContractAcceptanceRepository.php`

### Views provaveis

- nova view para o formulario de upgrade, por exemplo `backend/Views/clients/upgrade.php`
- `backend/Views/contracts/acceptance.php` para ajustar o texto do termo remoto quando o tipo for `upgrade_migracao`
- `backend/Views/contracts/detalhe.php` para exibir o novo tipo de contrato de forma amigavel
- `backend/Views/contracts/index.php` e `backend/Views/contracts/novos.php` se a lista precisar mostrar o novo tipo

### Migration

- nova migration para:
  - ampliar o enum de `tipo_aceite` para incluir `upgrade_migracao`
  - persistir snapshot do upgrade, se optarmos por dados estruturados

## O que ja existe e ajuda bastante

### No detalhe do cliente

- O detalhe do cliente ja abre por login.
- Ja existe um card com contratos locais.
- Ja existe acesso ao contrato local em `/contratos/detalhe?id=...`.

Isso e um bom ponto para colocar o botao `Upgrade / Migracao` sem inventar outra area de navegacao.

### No fluxo de contrato

O sistema ja possui:

- geracao de termo;
- criacao de aceite;
- envio de link;
- aceite remoto com assinatura pendente;
- evidencias de aceite;
- listagem de aceites pendentes;
- detalhe do contrato.

Ou seja, a Etapa 3B pode ser um novo tipo de contrato reaproveitando o mesmo esqueleto.

## Riscos

1. `tipo_aceite` hoje e enum fechado; se nao for ampliado, o novo tipo pode quebrar validacao ou ficar gravado de forma inconsistente.
2. Plano e tecnologia precisam ser preservados como snapshot. Se depender apenas do estado atual do cliente, o termo pode ficar desencontrado depois de uma mudanca posterior no cadastro.
3. Nao pode haver acoplamento com MkAuth automatico, senao a Etapa 3B perde o objetivo operacional.
4. Existe risco de misturar upgrade com regularizacao ou nova instalacao se o formulario e os rótulos nao forem claros.
5. Se reutilizarmos views demais sem separar o contexto, o usuario pode nao perceber que esta gerando um aceite de upgrade e nao um novo cadastro.

## Checklist de testes

### Fluxo UI

- abrir detalhe do cliente existente;
- clicar em `Upgrade / Migracao`;
- ver campos atuais preenchidos;
- alterar plano e tecnologia;
- preencher beneficio e fidelidade;
- gerar contrato;
- conferir se o aceite remoto foi criado em status `assinatura_pendente`.

### Fluxo de aceite

- abrir `/aceite/{token}`;
- conferir se o termo mostra o tipo `upgrade_migracao`;
- assinar remotamente;
- conferir se o status final vira `aceito`;
- conferir se a evidencias foi salva.

### Fluxo operacional

- validar que nao houve alteracao automatica no MkAuth;
- validar que o contrato ficou registrado localmente;
- validar que o operador consegue concluir manualmente a mudanca depois do aceite.

### Regressao

- novo cadastro continua funcionando;
- aceite local da Etapa 3A continua funcionando;
- listas de contratos continuam exibindo os contratos antigos;
- nenhuma rota experimental nova entra no menu principal.

## Recomendacao final

Para a Etapa 3B, eu seguiria este corte minimo:

- criar uma tela nova de upgrade a partir do detalhe do cliente;
- reutilizar o fluxo de aceite remoto da Etapa 3A;
- ampliar apenas o necessario em `client_contracts` para registrar `upgrade_migracao` e o snapshot do upgrade;
- manter MkAuth fora do fluxo automatico.

Esse caminho entrega o objetivo com menor area de risco e sem abrir uma segunda arquitetura paralela.
