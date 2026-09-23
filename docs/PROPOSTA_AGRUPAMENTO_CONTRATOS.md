# Proposta — agrupamento de Contratos, Processos e Aceites

Documento de proposta (sem implementação). Mesma lógica da Central de Atualizações: primeiro
alinhar o entendimento e a direção, implementar só depois de aprovação explícita.

## 1. O que existe hoje (fato observado no código)

- **Três controllers, três fluxos diferentes:**
  - `ContractController` — módulo "Contratos e Aceites" (o próprio código já chama assim no
    comentário de topo). Tem aba interna própria (`resumo`, `novos`, `aceites-pendentes`,
    `configuracoes`) dentro de `/contratos`.
  - `OperationalProcessController` — módulo "Processos" (`/processos`), cobre 3 tipos de processo:
    migração, instalação nova e assinatura avulsa.
  - `AcceptanceController` — fluxo **do cliente**, sem autenticação, acessado por link enviado por
    WhatsApp/e-mail (`/aceite/{token}`). Não aparece na navegação do operador em nenhum lugar.
- **Na navegação do operador (rail lateral), Contratos e Processos são dois itens soltos, sem
  nenhum link cruzado.** Aceites não tem item próprio — só existe como sub-página de Contratos
  (`/contratos/aceites/pendentes`).
- **Zero links diretos entre os dois módulos.** Nenhuma view de Contratos linka para Processos, e
  vice-versa (confirmado por busca no código, nos dois sentidos). Quem liga as pontas hoje é sempre
  a ficha do cliente (`ClientController`/`clientes/detalhe`): é lá que aparecem os botões "Ver
  documentos" (→ contrato) e "Ver checklist técnico completo" / "Continuar processo" (→ processo).

## 2. O que o modelo de dados e a lógica de negócio já dizem, mesmo sem UI mostrar isso

- No banco, `operational_processes` referencia `client_contracts` e `contract_acceptances`, e
  `contract_acceptances` também referencia `operational_processes`/`operational_process_documents`
  de volta — as duas tabelas apontam uma para a outra, mas ambas as FKs são opcionais
  (`ON DELETE SET NULL`). Ou seja: **no schema, nada obriga o acoplamento; o acoplamento é
  garantido pelo código da aplicação, não pelo banco.**
- E o código da aplicação acopla os três com bastante força: um Processo (migração, instalação ou
  assinatura avulsa) é **o container do fluxo**; o Contrato e o Aceite são artefatos gerados
  **dentro** do processo, especificamente para cumprir a fase "assinatura do cliente". O resto do
  processo (execução técnica, troca de plano, validação de conexão, chamado financeiro, conclusão)
  fica **bloqueado** até o aceite estar confirmado e não revogado — é uma trava de negócio real,
  implementada em `OperationalProcessService::reconcileState()`.
- Confirmação vem de fonte fria (não é dedução minha): dois documentos já existentes no repositório
  já descrevem exatamente essa relação —
  `docs/hotfix-migracao/06-arquitetura-de-processos.md` ("Um processo novo é associado quando um
  dos três fluxos grava ou reutiliza contrato e aceite") e
  `docs/hotfix-migracao/26-area-operacional-unica.md` (workspace único de migração já organizado em
  "quatro fases: Condição, Aceite, Execução e Plano/financeiro" — ou seja, o Aceite já é tratado
  como **fase 2 de 4 dentro do processo**, não como módulo irmão).

## 3. Conclusão direta

A base de código já entende Contratos+Processos+Aceites como **um fluxo contínuo só**. A tela é
que ainda apresenta como três coisas separadas (duas listas paralelas sem link cruzado, mais um
fluxo de cliente invisível para o operador). O desalinhamento é só de navegação/IA, não de
arquitetura de dados — o que torna o agrupamento visual seguro (não exige mudar schema nem regra de
negócio).

## 4. Duas direções possíveis (para você escolher, não decidi por conta própria)

### Opção A — Fundir em um item de navegação só, organizado por fluxo real

Um item de nav só (ex.: renomear "Contratos" para algo como "Contratos & Processos", ou manter o
nome "Contratos" mas ampliar seu escopo), com sub-navegação interna organizada pelo fluxo real —
não pela tabela:
- **Em andamento** (processos ativos: migração/instalação/assinatura, cada linha mostrando a fase
  atual — Condição / Aceite / Execução / Financeiro — reaproveitando a nomenclatura que
  `26-area-operacional-unica.md` já usa);
- **Aguardando assinatura** (o que hoje é `aceites-pendentes`, sem mudar a query nem a regra —
  só o rótulo já existente na aba interna de Contratos);
- **Concluídos / histórico** (contratos com processo fechado).

Vantagem: reflete o que o código já faz de fato, elimina a navegação dupla (hoje o operador
frequentemente precisa ir em Contratos para ver o documento e em Processos para ver o checklist do
mesmo cliente). `ContractController` já tem um sistema de abas internas (`resumo`, `novos`,
`aceites-pendentes`, `configuracoes`) — dá pra estender esse mesmo mecanismo em vez de inventar um
novo, reduzindo risco.

Desvantagem: é uma mudança de navegação maior, pode exigir ajuste de hábito de quem já usa
`/processos` e `/contratos` como destinos separados; também precisa decidir o que acontece com as
duas rotas hoje independentes (manter as duas rotas funcionando e só mudar onde aparecem no rail,
ou consolidar de fato).

### Opção B — Manter os dois itens de navegação como estão, só adicionar os links cruzados que faltam

Não mexe na estrutura de navegação (Contratos e Processos continuam dois itens separados no rail),
só fecha o buraco real encontrado na investigação: hoje **nenhuma tela de Contratos linka para o
Processo correspondente, e nenhuma tela de Processo linka para o Contrato/documento
correspondente** — quem quiser ver os dois lados de um mesmo cliente precisa passar pela ficha do
cliente. Adicionar:
- Na tela de detalhe do contrato (`contracts/detalhe.php`): um botão/link "Ver processo" quando o
  contrato tiver um `operational_process_id` vinculado.
- No workspace do processo (`processos/migracao`, `processos/detalhe`): um link "Ver contrato/
  documento assinado" quando houver `contract_id`/`active_acceptance_id`.

Vantagem: risco bem menor, mudança pequena e reversível, resolve a dor concreta (navegação
truncada) sem mexer em hábito nem em estrutura de nav.

Desvantagem: não resolve a duplicação conceitual (duas telas "de nível superior" pra um fluxo só);
o operador ainda vê duas listas paralelas.

## 5. Recomendação

Sugiro a **Opção B primeiro** (baixo risco, fecha a lacuna real e imediata) e deixar a **Opção A**
como evolução posterior, se depois de usar os links cruzados por um tempo ainda fizer sentido
fundir de fato — assim não se muda hábito de navegação sem confirmar que o ganho compensa.

## 6. O que NÃO deve virar item de nível superior, de qualquer forma

**Aceites não deve virar um terceiro item na navegação.** O próprio modelo de dados e os documentos
já existentes tratam o aceite como um estado/fase que pertence ao Contrato (é a versão do documento
que foi de fato assinada) e como uma etapa dentro do Processo (`confirm_acceptance`), nunca como
módulo independente. Promovê-lo a item de nível superior contradiria a própria arquitetura já
documentada.

## 7. Pendências para leitura futura (não bloqueiam esta proposta)

A investigação encontrou mais documentos em `docs/hotfix-migracao/` que aprofundam nuances de
aceite local vs. remoto (`07-fluxos-compartilhados.md`, `17-aceite-local-e-confirmacao-remota.md`,
`36-simplificacao-final-migracao.md`, `37-fluxo-quatro-etapas.md`) mas que não foram lidos linha a
linha nesta rodada. Se a Opção A for escolhida, vale ler esses documentos antes de desenhar a
sub-navegação em detalhe, para não reinventar nomenclatura que já existe.

---

**Pergunta para você decidir:** Opção A, Opção B, ou nenhuma agora (deixar como está e revisar
depois)? Nenhum código foi alterado nesta fase — é só este documento.
