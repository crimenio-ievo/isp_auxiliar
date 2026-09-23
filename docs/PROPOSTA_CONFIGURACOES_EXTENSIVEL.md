# Proposta — "registro de integrações" x arquitetura multiempresa

Documento de proposta (sem implementação), cruzando `docs/multiempresa.md` (já existente) com a
ideia de "registro de integrações" — cada serviço novo se registra e aparece sozinho no menu de
Configurações, em vez de precisar de código novo em vários lugares.

## 1. O que `multiempresa.md` já prevê

- Decisão atual: uma instância do ISP Auxiliar por provedor (arquivos, banco, `.env` e
  `APP_PROVIDER_KEY` separados). Simples e de baixo risco operacional agora.
- Mas o banco **já foi modelado com `provider_id`** pensando numa evolução futura: uma instalação
  só, compartilhada por vários provedores — desde que todo controller/repository/relatório filtre
  por provedor atual.
- Concretamente, isso já existe hoje: a tabela `provider_settings` guarda, por provedor, URL do
  MkAuth, Client ID/Secret, token de API, host/porta/banco/usuário/senha do MySQL de consulta,
  charset e algoritmos de senha — preenchível pelo menu **Configurações**, por gestores locais.
- Perfis já prontos para essa evolução: `platform_admin` (múltiplos provedores), `manager` (um
  provedor), `technician`.
- Evidências (fotos/assinaturas) ainda ficam em `storage/uploads/clientes/<referencia>`, sem
  segmentação por provedor; o próprio documento já aponta isso como ajuste futuro necessário
  (`storage/uploads/provedores/<slug>/clientes/<referencia>`) para o cenário compartilhado.

## 2. O que existe de fato hoje, sobre "registro de integrações" (investigado, não hipotético)

Fui conferir no código se já existe algum mecanismo de plugin/registro para as integrações
(Email, Evotrix/WhatsApp, MkAuth) — e não existe:

- A tela de Configurações é um arquivo único com um `if/elseif` por aba
  (`backend/Views/settings/index.php`); as chaves de aba estão **hardcoded em três lugares
  independentes** que precisam ficar sincronizados manualmente: o array `$tabs` na view, o
  whitelist em `SettingsController::normalizeTab()` e o `match` em `saveSection()`.
- Não existe nenhuma interface/classe `*Integration`, `*Registry` ou `*Provider` no código —
  busca por esse padrão não encontrou nada.
- **MkAuth e Geral/Provedor já usam `provider_settings`** (a tabela chave-valor por provedor
  descrita em `multiempresa.md`) — essa parte já é genuinamente flexível: dá pra gravar uma chave
  nova sem migration.
- **Email e Evotrix/WhatsApp NÃO usam `provider_settings`.** Eles são gravados num arquivo JSON
  único e fixo, `storage/contracts/config.json` (`SettingsController::localPanelConfigPath()`),
  com uma chave de topo por integração (`email`, `evotrix`, etc.), mais um arquivo de config
  estático em PHP (`backend/config/email.php`, `backend/config/evotrix.php`) para os valores
  padrão. **Esse caminho de arquivo é fixo — não tem `provider_id` nem `provider_slug` em lugar
  nenhum.**
- Hoje, adicionar uma integração nova (ex.: um provedor de SMS) exige mexer em código em pelo
  menos três lugares (view, `normalizeTab`, `saveSection`), mais um arquivo de config PHP novo e,
  se quiser teste de conectividade, mais um branch em `runConnectivityTest()`. Não existe like
  "implementa uma interface e aparece sozinho".

## 3. Cruzamento: mesma ideia, complementares, ou conflito?

**São ideias complementares, e o cruzamento revela um ponto concreto que merece atenção antes de
avançar num SaaS compartilhado — não é uma contradição, é uma lacuna real.**

- `multiempresa.md` resolve o eixo "para qual provedor vale este dado" (`provider_id`,
  `provider_settings`).
- "Registro de integrações" resolveria o eixo "quais tipos de configuração existem" (permitir
  adicionar um canal/serviço novo sem editar três arquivos hardcoded).
- Esses dois eixos são ortogonais e não conflitam entre si em princípio — mas **hoje, na prática,
  metade das integrações já vive no eixo "por provedor" (MkAuth, via `provider_settings`) e a
  outra metade não vive em eixo nenhum (Email/Evotrix, arquivo único e global, sem
  `provider_id`)**. Isso significa que, se a instalação compartilhada de `multiempresa.md`
  (\"Preparação Para Produto Comercial\") virar realidade antes de Email/Evotrix migrarem para um
  armazenamento com escopo de provedor, **as configurações de e-mail/WhatsApp de um provedor
  vazariam ou se sobreporiam às de outro**, porque o arquivo `storage/contracts/config.json` é um
  só para a instalação inteira, não um por provedor.
- Ou seja: **a ideia de "registro de integrações" e a evolução multiempresa já descrita
  compartilham o mesmo pré-requisito técnico** — todo armazenamento de configuração de integração
  precisa estar no mesmo eixo `provider_id` que `provider_settings` já usa. Se o registro de
  integrações for desenhado desde já para gravar tudo em `provider_settings` (ou numa tabela
  equivalente já particionada por `provider_id`) em vez de arquivos soltos, ele resolve ao mesmo
  tempo (a) o hardcode de três lugares por aba e (b) a lacuna de isolamento multiempresa que
  Email/Evotrix já têm hoje — mesmo antes de a instalação compartilhada existir de verdade.

## 4. Recomendação

Não implementar o registro de integrações "genérico" isoladamente. Se/quando for abordado, desenhar
já em cima de `provider_settings` (ou de uma tabela nova com a mesma forma: chave-valor,
`provider_id`, sem exigir migration por integração nova), migrando Email/Evotrix para esse mesmo
armazenamento no mesmo movimento — assim a extensibilidade de "novo tipo de integração" e a
extensibilidade de "novo provedor" avançam juntas, em vez de precisar refazer Email/Evotrix depois
por causa do isolamento multiempresa.

Isto é só uma constatação arquitetural para orientar prioridade futura — nenhum código foi alterado
nesta fase, e a decisão de instância-por-provedor "para produção agora" definida em
`multiempresa.md` continua valendo sem mudança.
