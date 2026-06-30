# Inventário de melhorias reaproveitáveis do diretório antigo

Data: 2026-06-12  
Clone limpo atual: `/var/www/html/isp_auxiliar`  
Referência antiga: `/var/www/html/isp_auxiliar_old_20260612_1815`  
Branch do clone limpo: `modernizacao-basica`

## Resumo executivo

O diretório antigo contém melhorias reais importantes, mas elas estão misturadas com uma migração visual APP V2, módulos experimentais e alterações de segurança de alcance global.

As melhorias mais valiosas e separáveis são:

1. camada read-only padronizada de consulta de clientes no MkAuth;
2. hub legado de clientes com busca, autocomplete e detalhe consolidando MkAuth e dados locais;
3. validação não destrutiva do login de cliente;
4. dashboard com contagens operacionais reais;
5. login com identidade configurável e logout completo;
6. permissões mais granulares para clientes;
7. hardening de CSRF, sessão, erros públicos e MIME de uploads;
8. biblioteca visual clean documentada e parcialmente implementada;
9. compatibilidade opcional com `flow_type` em contratos.

O núcleo de cadastro, rascunho, evidências, aceite, termo, assinatura, envio ao MkAuth e reenvio de aceite já existe no clone limpo. Não há motivo para copiar esses fluxos inteiros do antigo.

## 1. Histórico Git e base de comparação

### Comandos executados no diretório antigo

- `git log --oneline --decorate --all -30`
- `git status --short`
- `git branch --show-current`
- `git diff --stat main`
- `git diff --name-status main`

### Resultado

- Branch atual antiga: `main`
- `HEAD`, `main` e `origin/main`: `7983fb3 Finaliza ANATEL e sincronizacao financeira MkAuth`
- O clone limpo também parte exatamente do commit `7983fb3`.
- `git merge-base HEAD main` no antigo: `7983fb3`.

Portanto, **a `main` local é a base correta**. As melhorias posteriores no diretório antigo estão como alterações locais não commitadas e arquivos não rastreados.

### Histórico recente disponível

Os 17 commits disponíveis vão da versão inicial até:

- telas iniciais de contratos e aceites;
- integração de artefatos de contrato ao cadastro;
- ajustes de contratos, aceite e fluxo mobile;
- estabilização de integrações e base path;
- bloqueio de finalização sem aceite;
- piloto real com aceite automático;
- deploy/admin automático;
- recuperação de acesso;
- finalização ANATEL e sincronização financeira MkAuth.

Esses commits já compõem a base `7983fb3` presente nos dois diretórios. O inventário abaixo trata somente das melhorias locais posteriores existentes no antigo.

### Tamanho do diff rastreado contra `main`

- 34 arquivos rastreados alterados;
- aproximadamente 3.775 inserções e 1.177 remoções;
- dezenas de arquivos não rastreados, principalmente APP V2, labs, módulos e documentação.

## 2. Documentações e instruções encontradas

### Documentos principais

| Documento | Conteúdo útil | Uso recomendado |
|---|---|---|
| `AGENTS.md` | Define evolução gradual, reversível, preservação do legado e padrões operacionais APP V2 | DOCUMENTAR APENAS; contém menu/módulos proibidos nesta release |
| `AUDITORIA_GERAL.md` | Mapeia arquitetura, integrações, banco, módulos, riscos e maturidade | DOCUMENTAR APENAS; boa referência de arquitetura |
| `RELATORIO_CSRF_COBERTURA.md` | Lista cobertura CSRF por endpoint e riscos de regressão | PORTAR DEPOIS, junto da Etapa 4 |
| `RESUMO_ALTERACOES_PRODUCAO.md` | Resume CSRF, MIME, sessão, erros públicos e testes feitos | PORTAR DEPOIS; roteiro de segurança |
| `backend/docs/RELATORIO_MODULOS_FEATURE_FLAGS.md` | Documenta `ModuleGate` e modo enxuto | DESCARTAR / EXPERIMENTAL nesta release |
| `docs/identidade-visual-candidata.md` | Paleta clean clara, sidebar, cards, tabelas e responsividade | DOCUMENTAR APENAS e usar como referência visual |
| `docs/design-system.md` | Tokens, espaçamento, botões, cards, tabelas e status | DOCUMENTAR APENAS; fonte para extração seletiva |
| `docs/design-lab.md` e `docs/design-lab-v2.md` | Laboratórios visuais e critérios de decisão | DESCARTAR código; preservar documentação |
| `docs/migracao-layout-v2.md` | Estratégia gradual/reversível e componentes compartilhados | DOCUMENTAR APENAS |
| `docs/ux-operacional-v2.md` | Busca única, filtros, densidade e cores operacionais | DOCUMENTAR APENAS |
| `docs/ui/app-v2-guidelines.md` | Catálogo detalhado de componentes APP V2 | DOCUMENTAR APENAS; extrair padrões, não o shell |
| `docs/ui/app-v2-migration-map.md` | Estado e intenção das rotas V2 | DOCUMENTAR APENAS; parte diverge das regras desta release |
| `docs/roadmap.md` | Já previa consulta de cadastro e auditoria | DOCUMENTAR APENAS |
| documentos de Chamados, Operações e Rede | Mapeamentos e labs futuros | DESCARTAR / EXPERIMENTAL nesta release |

### Observação de confiabilidade

Alguns documentos descrevem APP V2 como oficial, porém o código está não commitado, misturado com mocks/placeholders e proibido nesta release. Devem ser usados como registro de intenção, não como prova de prontidão para produção.

## 3. Consulta, listagem e busca de clientes

## 3.1 A função de consulta existe?

**Sim. Ela existe e é uma das melhorias mais úteis do diretório antigo.**

Ela está dividida em três camadas.

### Camada 1: consulta padronizada read-only do MkAuth

Arquivo: `backend/Infrastructure/MkAuth/MkAuthDatabase.php`

Métodos novos:

- `countClientsByStatus(string $status): int`
- `findClientProfile(string $loginOrCpfCnpj): ?array`
- `searchClients(string $term, int $limit = 20, int $offset = 0): array`
- `listClients(int $limit = 20, int $offset = 0, array $filters = [], string $orderBy = 'nome'): array`
- `listKnownLocations(int $limit = 300): array`
- `normalizeClientProfileRow(array $row): array`
- helpers privados de normalização, tokens e filtros.

Capacidades:

- busca por nome, login, CPF/CNPJ, telefone, endereço, bairro, cidade, estado, plano e número;
- normalização de acentos e caixa;
- ranking por login/documento/telefone exato, prefixo, tokens e `SOUNDEX`;
- paginação por `limit` e `offset`;
- filtros por status, plano, localização e campo específico;
- atalhos para bloqueados, desconectados, sem telefone, sem plano e sem localização;
- ordenação por nome, login, plano, cidade/bairro, status, vencimento, cadastro e atualização;
- união com `sis_plano`;
- leitura de conexão atual em `radacct`;
- retorno normalizado em um formato comum para o novo sistema.

Formato padronizado inclui:

- identidade: `id`, `uuid_cliente`, `nome`, `login`, `cpf_cnpj`;
- contato e localização;
- plano, valor e tecnologia;
- vencimento;
- status normalizado;
- `online_now`;
- datas de cadastro/alteração.

**Fonte:** MkAuth e Radius. Não altera MkAuth.

### Camada 2: hub legado sem APP V2

Arquivo: `backend/Controllers/ClientController.php`

Métodos reaproveitáveis:

- `index()`
- `search()`
- `detail()`
- `canCreateClient()`
- `canSearchClients()`
- `canManageContracts()`
- `canManageFinancial()`
- `canManageSettings()`
- `buildClientHubSearchResults()`
- `buildRecentClientItems()`
- `buildClientDetail()`
- `buildClientTimeline()`
- `normalizeContractSummary()`
- `detectClientSearchMode()`
- formatadores/máscaras.

Comportamento:

- `/clientes` pesquisa o MkAuth e mostra registros locais recentes;
- `/clientes/buscar` devolve JSON para autocomplete;
- `/clientes/detalhe` consolida dados do MkAuth, cadastro local, checkpoints, contratos, aceite, pendência financeira, notificações e auditoria;
- indisponibilidade do MkAuth não impede o detalhe local;
- falhas de busca são auditadas.

Arquivos de suporte:

- `backend/Infrastructure/Local/LocalRepository.php`
  - `recentClientRegistrations()`
  - `findClientRegistrationsByLogin()`
  - `findInstallationCheckpointsByLogin()`
- `backend/Infrastructure/Contracts/ContractRepository.php`
  - `listByLogin()`
- repositórios de aceite, financeiro, notificações e auditoria já presentes na base.

Views reaproveitáveis:

- `backend/Views/clients/index.php`
- `backend/Views/clients/detail.php`

Assets reaproveitáveis:

- bloco de autocomplete em `public/assets/js/app.js`;
- classes `.client-search-*` em `public/assets/css/app.css`.

Rotas mínimas:

- `GET /clientes` -> `ClientController::index`
- `GET /clientes/buscar` -> `ClientController::search`
- `GET /clientes/detalhe` -> `ClientController::detail`

### Camada 3: listagem rica APP V2

Arquivos:

- `backend/Controllers/ClientV2Controller.php`
- `backend/Views/clients-v2/index.php`
- `backend/Views/clients-v2/screens.php`
- `backend/Views/layouts/v2/components.php`
- `public/assets/css/app-layout-v2.css`
- `public/assets/js/app-layout-v2.js`

Benefícios existentes:

- lista inicial automática;
- paginação e limites;
- quick reads/contagens por status;
- filtros avançados por drawer;
- filtros por plano/localização;
- tabela densa e badges;
- busca global.

Porém, essa camada depende do APP V2, contém IA simulada e shell experimental. Não deve entrar nesta release.

### Respostas objetivas sobre a consulta

- **Existe consulta reaproveitável?** Sim.
- **Usa MkAuth, local ou ambos?** A busca/lista usa MkAuth/Radius; o detalhe usa MkAuth e banco local.
- **Depende do `ClientV2Controller`?** Não. O hub legado usa `ClientController`.
- **Pode ser separada para `/clientes` limpa?** Sim.
- **Métodos mínimos:** `MkAuthDatabase::searchClients`, `findClientProfile`, `normalizeClientProfileRow` e helpers; `ClientController::index`, `search`, `detail` e seus formatadores/builders; métodos locais de histórico; `ContractRepository::listByLogin`.
- **Views mínimas:** `clients/index.php` e `clients/detail.php`.
- **JS/CSS mínimos:** autocomplete de clientes em `app.js` e `.client-search-*` em `app.css`.
- **Partes experimentais a excluir:** `ClientV2Controller`, `clients-v2/*`, APP V2, IA contextual, quick filters V2, drawer V2 e placeholders de fluxos futuros.

## 4. Cadastro de clientes

### Melhorias úteis ausentes

#### Validação não destrutiva do login

No clone limpo, caracteres inválidos podem ser convertidos silenciosamente para `_`.

No antigo:

- `ClientController::normalizeLoginInput()` apenas normaliza acentos/caixa;
- a regex rejeita caracteres inválidos;
- JS mantém espaços inválidos para a validação informar erro;
- a mensagem explica que ponto, hífen e underscore são permitidos.

O `_` já é permitido nos dois diretórios.

Classificação: **PORTAR AGORA**, como mudança pequena na futura Etapa 2.

#### Validação MIME real

No antigo, `storeUploadedPhotos()` usa `finfo_file()` e allowlist de imagens.

Classificação: **PORTAR DEPOIS**, Etapa 4. Deve retornar erro claro em vez de ignorar arquivo inválido silenciosamente.

### Funcionalidades equivalentes, que não precisam ser portadas

- rascunho e autosave;
- fotos temporárias;
- reaproveitamento de fotos existentes;
- evidências finais;
- coordenadas/GPS/mapa do cadastro;
- retomada por login/checkpoint;
- validação de duplicidade;
- envio ao MkAuth;
- recuperação após timeout/duplicidade;
- checkpoint de conexão Radius;
- mensagens e auditoria centrais.

O antigo não contém uma correção funcional ampla nesses pontos que justifique copiar o controller inteiro.

## 5. Aceite e contratos

### Funcional equivalente

`AcceptanceController.php` é idêntico nos dois diretórios. Já existem no clone limpo:

- aceite público;
- validação por documento;
- termo;
- assinatura;
- evidência JSON;
- hash/versão do termo;
- IP, data, hora e dispositivo;
- acesso ao termo assinado.

O reenvio por WhatsApp/e-mail e regras reais do `ContractController` também já existem no clone limpo.

### Diferenças úteis reais

#### CSRF nos formulários

O antigo inclui tokens em aceite, termo, cadastro, contratos, conexão, instalações, usuários, configurações e login.

Dependências:

- `backend/Core/Csrf.php`;
- mudanças globais em `Application.php`;
- helper em bootstrap/layout;
- ajuste JS da limpeza de rascunho.

Classificação: **PORTAR DEPOIS**, Etapa 4.

#### Histórico de múltiplos contratos por login

`ContractRepository::listByLogin()` permite ao detalhe do cliente mostrar mais de um contrato e construir timeline.

Classificação: **PORTAR AGORA** somente se o detalhe consolidado entrar.

#### Compatibilidade opcional com `flow_type`

O antigo inclui migration `006_client_contracts_flow_type.sql`, mas o repositório verifica se a coluna existe antes de usá-la.

Benefício futuro:

- diferenciar instalação nova, upgrade, titularidade, mudança de endereço e regularização.

Risco:

- altera banco se a migration for aplicada;
- os fluxos relacionados ainda são placeholders.

Classificação: **PORTAR DEPOIS** e não aplicar migration nesta release sem aprovação.

### Diferenças apenas visuais / APP V2

As grandes reescritas de `backend/Views/contracts/*`:

- usam shell APP V2;
- usam `_v2_common.php`;
- alteram organização em métricas/tabelas;
- contêm IA simulada;
- em alguns casos removem detalhes presentes na view limpa.

Classificação: **DOCUMENTAR APENAS** ou **DESCARTAR / EXPERIMENTAL** para esta release.

## 6. Outras melhorias reais encontradas

### Login com identidade do provedor

Arquivos:

- `backend/Controllers/AuthController.php`
- `backend/Views/auth/login.php`
- `public/assets/css/app.css`
- `public/assets/css/app-public-v2.css`

Benefícios:

- nome, slogan/subtítulo e logo configuráveis;
- monograma fallback;
- visual mais profissional;
- remove footer técnico da tela de login.

Risco: baixo, se separado de CSRF e APP V2.

Classificação: **PORTAR AGORA** visualmente, após aprovação da Etapa 1 complementar.

### Logout completo

`AuthController::destroySession()` limpa sessão, cookie e encerra a sessão.

Benefício: logout mais confiável.

Risco: baixo, requer regressão HTTP/HTTPS.

Classificação: **PORTAR DEPOIS**, junto do pacote de segurança.

### Dashboard com dados reais

Arquivos:

- `DashboardController.php`
- `dashboard/index.php`
- `MkAuthDatabase::countClientsByStatus()`
- container/bootstrap para injetar banco local.

Benefícios:

- clientes ativos, bloqueados, desconectados e em observação;
- contratos, aceites pendentes, pendências financeiras;
- cadastros recentes e instalações pendentes;
- análises e alertas rule-based.

Riscos:

- queries adicionais no MkAuth e banco local;
- view atual antiga depende do APP V2/IA simulada;
- uma query de status deve ser testada contra o schema real.

Classificação: **PORTAR DEPOIS**, extraindo somente contagens/alertas reais para o dashboard legado.

### Permissões granulares de clientes

Arquivos:

- `SettingsController.php`
- `settings/index.php`
- `AccessControl.php`
- leitura em `ClientController`.

Permissões:

- criar cliente;
- buscar clientes;
- gerenciar contratos;
- regularizar;
- transferir titularidade;
- upgrade de plano.

Benefício: separação operacional mais precisa.

Risco: amplia matriz de permissões e interface; parte controla fluxos ainda placeholder.

Classificação: **PORTAR DEPOIS**. Inicialmente, portar apenas `create_client` e `search_clients` se necessários.

### Erros públicos e sessão

`public/index.php` antigo:

- configura cookie `HttpOnly`, `SameSite=Lax` e `Secure` conforme HTTPS;
- esconde detalhes de exceção pública.

Benefício: segurança e menor exposição.

Risco:

- handler global pode ocultar diagnóstico e não registra explicitamente o erro;
- sessão precisa ser testada em HTTP e HTTPS.

Classificação: **PORTAR DEPOIS**, Etapa 4, com logging antes da resposta genérica.

### Busca e leitura de chamados / operações

Há consultas e repositórios read-only bem desenvolvidos em:

- `MkAuthDatabase::listSupportTickets()` e `listSupportTicketMessages()`;
- `ChamadosRepository`;
- `OperationsRepository`.

Benefício futuro: contexto operacional consolidado.

Classificação: **DESCARTAR / EXPERIMENTAL** nesta release por serem módulos proibidos. Documentar para uma iniciativa futura.

### Relatórios novos

`SystemController::reports()` e `backend/Views/reports/index.php`.

Classificação: **DESCARTAR / EXPERIMENTAL** nesta release.

### ModuleGate e modo enxuto

Arquivos:

- `backend/Core/ModuleGate.php`;
- alterações em Application/bootstrap/settings/sidebar;
- documentação em `backend/docs`.

Classificação: **DESCARTAR / EXPERIMENTAL**, explicitamente proibidos.

## 7. Layout e interface

### Por que o visual atual ficou diferente do clean anterior

O visual aplicado na Etapa 1 modernizou o shell legado existente, que usa:

- `layouts/app.php`, `header.php`, `sidebar.php`, `footer.php`;
- `public/assets/css/app.css`;
- identidade escura na sidebar e tons verde/laranja.

O visual clean antigo usa um sistema separado:

- sidebar branca recolhível;
- topbar com busca;
- paleta azul/cinza;
- cards e métricas APP V2;
- classes `appv2-*`;
- componentes PHP próprios;
- JS próprio de shell, drawers e IA simulada.

Como o APP V2 e seus módulos foram corretamente excluídos da Etapa 1, o shell atual não ficou visualmente igual ao clean.

### Partes visuais extraíveis sem APP V2

#### Tokens e direção

Extrair como referência para `app.css`, sem importar `app-layout-v2.css` inteiro:

- fundo `#f6f8fb`;
- superfícies `#ffffff` e `#f8fafc`;
- texto `#0f172a`, `#334155`, `#64748b`;
- primária azul `#2563eb`;
- bordas discretas;
- sombras leves;
- raios de 12px a 26px;
- cores operacionais suaves para perigo, atenção, sucesso, informação e neutro.

#### Componentes visuais úteis

Podem ser recriados no shell legado:

- `section_header`;
- `metric_card`;
- `data_table`;
- `empty_state`;
- `status_badge`;
- botões primário/secundário/compacto;
- page header;
- painéis de filtro com `<details>`;
- cards de leitura rápida;
- tabelas com cabeçalho discreto e badges;
- login público clean de `app-public-v2.css`.

#### Partes a não trazer

- `appv2_shell`;
- sidebar/topbar APP V2 completas;
- IA/FAB/drawer;
- navegação por hash;
- `app-layout-v2.js` integral;
- classes específicas de Chamados, Operações, Rede e Financeiro;
- Design Lab e mocks.

### Como aproximar do clean sem perder identidade operacional

1. manter o shell e rotas legados;
2. clarear a sidebar gradualmente, sem trocar toda a arquitetura;
3. substituir gradientes decorativos por superfícies claras e bordas discretas;
4. usar azul como ação principal e tons operacionais suaves para estado;
5. padronizar cards, tabelas, badges e filtros em `app.css`;
6. manter conteúdo denso e útil, sem ficar minimalista vazio;
7. aplicar primeiro no login, dashboard e hub de clientes;
8. não importar IA, drawers globais ou shell V2.

## 8. Classificação final

| Categoria | Melhoria | Arquivos principais | Risco | Dependências | Benefício | Banco? | Rotas? | Módulo proibido? | Recomendação |
|---|---|---|---|---|---|---:|---:|---:|---|
| PORTAR AGORA | Validação não destrutiva do login | `ClientController.php`, `clients/create.php`, `app.js` | Baixo | Nenhuma nova | Evita login alterado silenciosamente; preserva `_` | Não | Não | Não | Aplicar isoladamente na Etapa 2 |
| PORTAR AGORA | Camada padronizada de busca/perfil MkAuth | `MkAuthDatabase.php` | Moderado | Schema MkAuth/Radius | Busca robusta e dados normalizados | Não | Não | Não | Portar métodos de clientes e testar queries |
| PORTAR AGORA | Hub legado `/clientes` read-only | `ClientController.php`, `clients/index.php`, `app.js`, `app.css` | Moderado | Camada MkAuth padronizada | Ponto de entrada real para Clientes | Não | Sim | Não | Entrar na Etapa 2 após aprovação |
| PORTAR AGORA | Detalhe consolidado do cliente | `ClientController.php`, `clients/detail.php`, `LocalRepository.php`, `ContractRepository.php` | Moderado | Repositórios existentes | Une MkAuth, local, contrato, aceite e histórico | Não | Sim | Não | Portar após a lista básica, removendo ações placeholder |
| PORTAR AGORA | Autocomplete JSON | `ClientController::search`, `app.js`, `.client-search-*` | Baixo | `/clientes/buscar` | Busca rápida operacional | Não | Sim | Não | Portar junto do hub |
| PORTAR AGORA | Identidade configurável no login | `AuthController.php`, `auth/login.php`, CSS público | Baixo | Provider settings existentes | Login profissional e contextual | Não | Não | Não | Extrair sem APP V2/CSRF |
| PORTAR DEPOIS | Dashboard com contagens reais | `DashboardController.php`, `MkAuthDatabase.php`, view | Moderado/alto | MkAuth e banco local | Home operacional útil | Não | Não | Parcialmente APP V2 | Extrair dados, não copiar view integral |
| PORTAR DEPOIS | Permissões granulares de Clientes | Settings, AccessControl, ClientController | Moderado | Matriz de permissões | Melhor separação de função | Não | Não | Não | Portar somente após definir papéis |
| PORTAR DEPOIS | CSRF completo | `Csrf.php`, Application, views, JS | Alto | Todos POSTs | Proteção crítica | Não | Comportamento global | Não | Etapa 4 com regressão completa |
| PORTAR DEPOIS | MIME real em uploads | `ClientController.php` | Moderado | PHP fileinfo | Bloqueia upload inválido | Não | Não | Não | Etapa 4 com erro explícito |
| PORTAR DEPOIS | Sessão segura e erro público genérico | `public/index.php`, AuthController | Moderado | HTTP/HTTPS/logging | Segurança e logout confiável | Não | Não | Não | Etapa 4 |
| PORTAR DEPOIS | `flow_type` opcional | ContractRepository + migration 006 | Alto | Aprovação de banco | Suporta fluxos contratuais futuros | Sim | Não | Não | Não aplicar migration nesta release |
| PORTAR DEPOIS | Quick reads e filtros avançados de clientes | ClientV2Controller + MkAuthDatabase | Moderado | APP V2 na forma atual | Lista operacional rica | Não | Sim | Sim, na forma atual | Reimplementar no legado futuramente |
| DOCUMENTAR APENAS | Tokens clean e componentes visuais | docs + `app-layout-v2.css` + components | Baixo | Extração seletiva | Aproxima visual clean | Não | Não | Não, se extraído | Usar como referência, não copiar integral |
| DOCUMENTAR APENAS | Histórico/timeline consolidada | ClientController detail | Moderado | Vários repositórios | Visão completa do cliente | Não | Sim | Não | Preservar ideia e aplicar por partes |
| DOCUMENTAR APENAS | Consultas de chamados/operações | MkAuthDatabase, ChamadosRepository, OperationsRepository | Alto | Módulos proibidos | Base futura read-only | Não | Sim | Sim | Guardar para projeto futuro |
| DOCUMENTAR APENAS | Docs de Rede/Topologia | `docs/rede-topologia/*` | Alto | Módulo proibido | Conhecimento futuro | Futuro | Futuro | Sim | Não trazer nesta release |
| DESCARTAR / EXPERIMENTAL | `ClientV2Controller` e `clients-v2/*` como pacote | Controller/views APP V2 | Alto | APP V2/IA/shell | Piloto visual | Não | Sim | Sim | Não copiar; extrair ideias |
| DESCARTAR / EXPERIMENTAL | APP V2 / Design Lab | app-v2, design-lab, assets V2 integrais | Alto | Mocks, IA, menu experimental | Laboratório visual | Não | Sim | Sim | Não trazer |
| DESCARTAR / EXPERIMENTAL | ModuleGate | Core/bootstrap/settings | Alto | Provider settings e rotas | Feature flags | Não | Comportamento global | Sim | Proibido nesta release |
| DESCARTAR / EXPERIMENTAL | Modo enxuto por ambiente | sidebar/components/docs | Moderado | Variável de ambiente | Menu reduzido | Não | Não | Sim | Proibido; menu final já é explícito |
| DESCARTAR / EXPERIMENTAL | Chamados V2, Operações, Agenda, Rede, Relatórios novos e Financeiro placeholder | vários | Alto | Módulos proibidos | Funcionalidade futura | Variável | Sim | Sim | Não trazer |

## 9. Riscos técnicos importantes

- As mudanças antigas não foram commitadas e não formam uma release validada única.
- Muitos arquivos V2 são não rastreados e misturam dado real, mock, IA simulada e placeholders.
- A busca MkAuth é valiosa, mas contém queries complexas; deve ser testada no schema real antes de adoção.
- O detalhe consolidado referencia ações futuras placeholder; essas ações devem ser removidas na versão limpa.
- Copiar o `ClientController` inteiro traria centenas de linhas fora do escopo.
- Copiar `Application.php` antigo traria simultaneamente CSRF e `ModuleGate`, o que é proibido.
- A infraestrutura CSRF exige cobertura integral de formulários e chamadas JS.
- O handler global de exceção antigo responde mensagem genérica, mas não mostra gravação explícita do erro real.
- A migration `flow_type` altera banco e não é indispensável à release atual.
- O visual APP V2 integral mudaria radicalmente o shell e traria módulos proibidos.

## 10. Plano mínimo recomendado

### Etapa 2, após aprovação

1. portar a validação não destrutiva de login;
2. portar somente os métodos read-only de clientes em `MkAuthDatabase`;
3. criar `/clientes`, `/clientes/buscar` e `/clientes/detalhe` com `ClientController`;
4. trazer `clients/index.php` e `clients/detail.php` sem APP V2;
5. trazer autocomplete e CSS mínimo;
6. remover ações placeholder do detalhe;
7. testar MkAuth indisponível, busca, lista, detalhe e dados locais;
8. manter cadastro/aceite existentes sem cópia ampla.

### Depois

1. extrair contagens reais para a Home;
2. aplicar segurança CSRF/MIME/sessão em etapa própria;
3. evoluir permissões;
4. aplicar tokens clean seletivamente;
5. avaliar `flow_type` somente com aprovação de banco.

## 11. Respostas finais objetivas

### 1. Quais melhorias úteis existem no antigo e ainda não estão no novo clone?

- busca/listagem/perfil padronizado de clientes no MkAuth;
- hub `/clientes` read-only com autocomplete;
- detalhe consolidado MkAuth + local + contratos + aceite + histórico;
- validação de login não destrutiva;
- login com identidade do provedor;
- dashboard com contagens reais;
- permissões granulares;
- CSRF, MIME real, sessão/erros públicos e logout completo;
- tokens/componentes visuais clean;
- suporte opcional futuro a `flow_type`.

### 2. A função de consulta de clientes existe? Onde?

Sim:

- consultas e normalização: `backend/Infrastructure/MkAuth/MkAuthDatabase.php`;
- hub simples: `backend/Controllers/ClientController.php`;
- views simples: `backend/Views/clients/index.php` e `detail.php`;
- autocomplete: `public/assets/js/app.js`;
- histórico local: `LocalRepository.php`;
- versão rica experimental: `ClientV2Controller.php` e `clients-v2/*`.

### 3. Ela deve entrar agora na Etapa 2?

**Sim, mas somente a versão limpa/read-only baseada no `ClientController`, sem APP V2.**

Ela resolve o menu Clientes, oferece valor operacional real e não altera banco nem MkAuth. Deve entrar em uma mudança separada e testada após a correção mínima do login.

### 4. Quais arquivos mínimos seriam necessários sem `ClientV2Controller`/APP V2?

- `backend/Infrastructure/MkAuth/MkAuthDatabase.php`: somente métodos de clientes e helpers;
- `backend/Infrastructure/Local/LocalRepository.php`: três métodos de histórico de clientes;
- `backend/Infrastructure/Contracts/ContractRepository.php`: `listByLogin()`, se o detalhe consolidado entrar;
- `backend/Controllers/ClientController.php`: somente index/search/detail, builders e formatadores necessários;
- `backend/Views/clients/index.php`;
- `backend/Views/clients/detail.php`;
- `backend/routes.php`: três rotas;
- `public/assets/js/app.js`: bloco de autocomplete;
- `public/assets/css/app.css`: `.client-search-*` e estilos mínimos das views.

### 5. Quais melhorias não podem ser perdidas, mas devem ficar para depois?

- CSRF completo;
- MIME real dos uploads;
- sessão segura, logout completo e erros públicos sem detalhes;
- dashboard com contagens reais;
- permissões granulares;
- tokens clean, cards, tabelas e badges;
- timeline consolidada do cliente;
- quick filters/listagem rica como conceito;
- `flow_type` e fluxos contratuais futuros;
- documentação de Chamados, Operações e Rede, sem trazer seus módulos nesta release.
