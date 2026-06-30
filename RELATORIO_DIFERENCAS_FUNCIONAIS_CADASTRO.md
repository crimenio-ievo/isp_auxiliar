# Relatório de diferenças funcionais de cadastro e aceite

Data da análise: 2026-06-12  
Branch analisada: `modernizacao-basica`  
Produção limpa atual: `/var/www/html/isp_auxiliar`  
Referência antiga: `/var/www/html/isp_auxiliar_old_20260612_1815`

## Escopo e conclusão executiva

Esta análise foi feita somente por comparação de código. Nenhum arquivo funcional, rota, controller, banco, `.env`, `storage/` ou integração MkAuth foi alterado.

O núcleo funcional de cadastro, rascunho, coleta de evidências, envio ao MkAuth, aceite público, termo e reenvio já está presente na branch limpa. A referência antiga não contém uma reescrita necessária desses fluxos; as diferenças comprovadamente úteis e ausentes são pontuais:

1. validação de login não destrutiva, que rejeita caracteres inválidos em vez de convertê-los silenciosamente em `_`;
2. validação do MIME real das fotos com `finfo`;
3. uma tela legada de busca/listagem/detalhe de clientes em leitura, separável do `ClientV2Controller`;
4. proteção CSRF ampla, que depende de alterações globais e deve permanecer para a Etapa 4.

As grandes diferenças visuais de clientes e contratos no diretório antigo dependem do shell APP V2, componentes V2, IA contextual e rotas experimentais. Não devem ser copiadas como parte da modernização básica.

## 1. Login do cliente/cadastro

### O underscore é aceito?

**Sim, nos dois diretórios.**

A regex backend é a mesma:

```php
/^[a-z0-9_.-]+$/
```

Ela aceita letras minúsculas, números, ponto, underscore e hífen.

- Branch limpa: `backend/Controllers/ClientController.php`, função `isValidLogin()`, aproximadamente linha 2219.
- Referência antiga: `backend/Controllers/ClientController.php`, função `isValidLogin()`, aproximadamente linha 2926.
- Validação JavaScript da branch limpa: `public/assets/js/app.js`, aproximadamente linhas 2192-2202.
- Validação JavaScript antiga: `public/assets/js/app.js`, aproximadamente linhas 2320-2330.

Exemplo: `cliente_teste` passa na regex das duas versões.

### Correção existente no antigo

A diferença não é liberar `_`; ele já estava liberado na branch limpa. A correção antiga evita alterar silenciosamente o login informado antes da validação.

Na branch limpa:

- `collectFormData()`, aproximadamente linha 1103, chama `sanitizeLogin()`.
- `sanitizeLogin()`, aproximadamente linhas 2224-2232, troca qualquer sequência inválida por `_`.
- No JavaScript, `normalizeLoginValue()`, aproximadamente linha 780, também troca caracteres inválidos por `_`.

Assim, `cliente teste` pode virar `cliente_teste` automaticamente e ser aceito sem o operador perceber a mudança.

Na referência antiga:

- `collectFormData()`, aproximadamente linha 1787, chama `normalizeLoginInput()`.
- `normalizeLoginInput()`, aproximadamente linhas 2931-2937, apenas remove acentos, converte para minúsculas e aplica `trim`.
- A regex de `isValidLogin()` rejeita espaços e demais caracteres inválidos.
- No JavaScript, `normalizeLoginValue()`, aproximadamente linha 910, apenas normaliza acentos, caixa e espaços externos.
- A mensagem informa explicitamente que ponto, hífen e underscore são permitidos.

**Conclusão:** a correção antiga deve ser reaplicada de forma mínima. O underscore já funciona; o ganho real é impedir que caracteres inválidos sejam convertidos silenciosamente em underscore.

### Risco

Baixo a moderado. A mudança pode fazer cadastros antes “corrigidos automaticamente” falharem com uma mensagem explícita. Isso é desejável para integridade do login, mas deve ser testado com login contendo espaço, acento, ponto, hífen e underscore.

## 2. Rota Clientes

### Branch limpa

Não existe rota GET `/clientes`. Em `backend/routes.php`, aproximadamente linhas 43-56, existem apenas:

- `/clientes/novo`;
- fluxo de aceite;
- conexão;
- retomada;
- evidências;
- APIs auxiliares.

O `ClientController` limpo também não possui os métodos `index()`, `search()` e `detail()`.

### Referência antiga

Em `backend/routes.php`, aproximadamente linhas 67-75:

- `/clientes` aponta para `ClientV2Controller::index`;
- `/clientes-legado` aponta para `ClientController::index`;
- `/clientes/buscar` aponta para `ClientController::search`;
- `/clientes/detalhe` aponta para `ClientController::detail`;
- há rotas placeholder para upgrade, titularidade, endereço e regularização.

O `ClientV2Controller` declara explicitamente ser um piloto visual V2. Portanto, a rota antiga `/clientes` não deve ser copiada como está.

Existe, porém, uma implementação legada separável e read-only:

- métodos `index()`, `search()` e `detail()` no `ClientController` antigo;
- `backend/Views/clients/index.php`;
- `backend/Views/clients/detail.php`;
- busca autocomplete em `public/assets/js/app.js`;
- estilos de resultados de busca em `public/assets/css/app.css`.

### Recomendação para o menu

- **Estado atual:** manter temporariamente o item Clientes apontando para `/clientes/novo`, porque `/clientes` retornaria 404.
- **Após uma aplicação controlada na Etapa 2:** o menu deve apontar para `/clientes`, mas somente depois de criar uma rota não experimental para `ClientController::index` e trazer apenas a tela legada de busca/leitura.
- Não usar `ClientV2Controller`, `/clientes-v2`, placeholders de fluxo ou APP V2.

## 3. Cadastro de cliente

### Diferenças relevantes

#### Validação de login

Ausente na branch limpa:

- `normalizeLoginInput()` não destrutivo no backend;
- normalização não destrutiva equivalente no JavaScript;
- mensagem de ajuda indicando ponto, hífen e underscore.

Já existente nos dois:

- regex permitindo `_`;
- consulta de duplicidade de login no MkAuth;
- normalização para minúsculas e remoção de acentos.

#### Rascunho

Os métodos centrais são equivalentes nos dois diretórios:

- `saveDraft()`;
- `storeDraftPhotos()`;
- `loadDraft()`;
- `loadDraftMedia()`;
- `saveFormDraft()`;
- `storeAcceptanceEvidence()`;
- `persistDraftRecord()`;
- retomada por login/checkpoint.

A diferença antiga no JavaScript adiciona `_csrf` ao pedido de limpeza do rascunho. Ela só funciona corretamente junto da infraestrutura CSRF antiga e deve ser tratada na Etapa 4, não isoladamente na Etapa 2.

#### Upload de fotos

Correção ausente na branch limpa:

- A branch limpa usa o MIME informado pelo cliente/nome do arquivo em `storeUploadedPhotos()`, aproximadamente linhas 2034-2072.
- A referência antiga usa `finfo_file(FILEINFO_MIME_TYPE)` e uma allowlist real de JPEG, PNG, WEBP e GIF, aproximadamente linhas 2719-2779.

Essa é uma correção de segurança útil, mas deve ser anunciada e aplicada na Etapa 4 conforme o plano aprovado. Há também um comportamento a melhorar antes de portar: o antigo ignora silenciosamente MIME inválido; o ideal é retornar erro claro ao operador.

#### Evidências

O fluxo funcional principal é equivalente:

- armazenamento das fotos do rascunho;
- cópia para evidências finais;
- gravação de assinatura;
- metadados `aceite.json`;
- visualização e arquivo de evidência;
- registro dos arquivos no repositório local.

Não foi encontrada correção funcional exclusiva no antigo, além da validação MIME e da proteção CSRF.

#### Campos obrigatórios

`validateDraft()` é funcionalmente equivalente nos dois diretórios. Ambos exigem:

- nome completo;
- CPF/CNPJ válido;
- login válido e não duplicado;
- tipo de instalação;
- plano compatível;
- vencimento permitido;
- cidade;
- condição comercial válida;
- autorização para promoção/isenção;
- fidelidade;
- e-mail e telefone válidos;
- coordenadas;
- ao menos uma foto, salvo quando já existe foto preservada.

Não foi encontrada nova obrigatoriedade de cadastro no antigo.

#### Envio para MkAuth

O fluxo de provisionamento, recuperação após timeout/duplicidade, checkpoint, contrato, disparo do aceite e auditoria é essencialmente equivalente.

Não foi encontrada correção exclusiva no antigo que justifique copiar o `ClientController` inteiro. Isso traria centenas de linhas de hub de clientes, permissões e placeholders fora do escopo.

#### Tratamento de erro

O tratamento central de cadastro e envio ao MkAuth é equivalente. As diferenças antigas relevantes são:

- mensagens mais explícitas na validação de login;
- auditoria de falha da busca de clientes;
- validação MIME real, atualmente silenciosa quando rejeita arquivo.

## 4. Aceite e contratos

### Núcleo de aceite

`backend/Controllers/AcceptanceController.php` é idêntico nos dois diretórios.

Portanto, já existem na branch limpa:

- aceite público por token;
- validação de documento;
- confirmação;
- registro de IP, data, hora e dispositivo;
- assinatura existente como evidência;
- hash e versão do termo;
- gravação do JSON de evidência;
- acesso protegido ao termo assinado.

### Contratos e reenvio

O `ContractController` antigo só adiciona `canManageSettings` aos dados enviados para três views. Os métodos reais de reenvio por WhatsApp/e-mail e demais regras de contrato já existem na branch limpa.

Já existem na branch limpa:

- `enviarAceiteWhatsapp()`;
- `enviarAceiteEmail()`;
- tela de aceites pendentes;
- detalhe com reenvio;
- termo e evidências;
- status e auditoria.

### Diferenças das views

Correções antigas pontuais:

- inclusão de `csrf_input()` em:
  - `backend/Views/clients/acceptance.php`;
  - `backend/Views/contracts/acceptance.php`;
  - `backend/Views/contracts/termo.php`;
  - formulários do detalhe/configurações de contratos.

Essas inclusões dependem de:

- `backend/Core/Csrf.php`;
- mudanças globais em `backend/Core/Application.php`;
- inicialização/helper em `backend/Views/layouts/app.php`;
- tokens em todos os POSTs protegidos.

Devem ser avaliadas em conjunto na Etapa 4. Copiar somente os inputs quebraria a branch limpa porque `csrf_input()` não existe nela.

As grandes alterações em `backend/Views/contracts/*` são majoritariamente visuais e dependem de `_v2_common.php`, `layouts/v2/components.php` e `app-layout-v2.css`. Algumas removem detalhes presentes na view limpa. Não são correções funcionais seguras para cópia ampla.

## 5. Comparação visual

### Por que o visual aplicado agora não ficou parecido com o layout clean anterior

O layout aplicado na Etapa 1 modernizou o shell legado já ativo:

- `backend/Views/layouts/app.php`;
- `header.php`;
- `sidebar.php`;
- `footer.php`;
- `public/assets/css/app.css`.

O “layout clean” da referência antiga vem de outro sistema visual:

- `backend/Views/layouts/v2/components.php`;
- `public/assets/css/app-layout-v2.css`;
- `public/assets/js/app-layout-v2.js`;
- views reescritas com classes `appv2-*`.

Esse shell usa sidebar clara, tokens azuis, topbar com busca, métricas, tabelas e seções próprias. Ele também contém IA contextual, navegação e módulos experimentais. Como esses arquivos não foram usados na Etapa 1, o resultado atual manteve a identidade do shell legado: sidebar escura, cards e gradientes verde/laranja.

### Arquivos visuais antigos potencialmente reaproveitáveis

Reaproveitamento seguro exige extração seletiva, não cópia integral:

- `public/assets/css/app-layout-v2.css`: aproveitar somente tokens de cor, bordas, sombras, botões, seções, métricas e tabelas; excluir shell, IA, drawers, módulos e classes específicas.
- `backend/Views/layouts/v2/components.php`: usar apenas como referência para markup de `section_header`, `metric_card`, `data_table` e `empty_state`; não importar sidebar/topbar/IA integralmente.
- `public/assets/css/app-public-v2.css`: pode servir como referência para telas públicas/login/aceite, após revisão seletiva.
- `public/assets/css/app.css` antigo: contém pequenas melhorias de login e resultados de busca que podem ser portadas isoladamente.

Não reaproveitar integralmente:

- `public/assets/js/app-layout-v2.js`;
- sidebar/topbar V2;
- IA contextual;
- Design Lab;
- APP V2;
- views completas de contratos V2;
- `ClientV2Controller`.

## Correções ausentes e prioridade

| Correção | Existe no antigo | Ausente na branch | Etapa recomendada |
|---|---:|---:|---|
| `_` permitido no login | Sim | Não, já funciona | Nenhuma correção necessária |
| Rejeitar caracteres inválidos sem convertê-los para `_` | Sim | Sim | Etapa 2 |
| Mensagem explícita de caracteres permitidos | Sim | Sim | Etapa 2 |
| Hub legado `/clientes` em leitura | Sim | Sim | Etapa 2, aplicação seletiva |
| Busca/autocomplete/detalhe de clientes | Sim | Sim | Etapa 2, aplicação seletiva |
| Rascunho, retomada e fotos preservadas | Sim | Não, já existe | Apenas regressão/testes |
| Evidências e assinatura | Sim | Não, já existe | Apenas regressão/testes |
| Envio e recuperação MkAuth | Sim | Não, já existe | Apenas regressão/testes |
| MIME real com `finfo` | Sim | Sim | Etapa 4 |
| CSRF global e tokens em formulários | Sim | Sim | Etapa 4 |
| Reenvio de aceite | Sim | Não, já existe | Apenas regressão/testes |

## Arquivos candidatos para aplicação mínima na Etapa 2

Aplicar seletivamente:

1. `backend/Controllers/ClientController.php`
   - adicionar `normalizeLoginInput()`;
   - usar essa função apenas na entrada/validação do cadastro;
   - opcionalmente trazer `index()`, `search()`, `detail()` e helpers estritamente necessários para o hub legado.
2. `backend/Views/clients/create.php`
   - atualizar somente a ajuda do login;
   - não importar shell APP V2 nem `csrf_input()` nesta etapa.
3. `public/assets/js/app.js`
   - alterar somente `normalizeLoginValue()` e mensagem de validação;
   - trazer autocomplete apenas se `/clientes/buscar` for aprovado.
4. `backend/Views/clients/index.php` e `backend/Views/clients/detail.php`
   - candidatas para uma rota `/clientes` não experimental, após revisar dependências.
5. `public/assets/css/app.css`
   - trazer somente estilos de busca de clientes se o hub for aprovado.
6. `backend/routes.php`
   - somente após aprovação explícita: `/clientes`, `/clientes/buscar` e `/clientes/detalhe` apontando para `ClientController`;
   - não trazer `ClientV2Controller`, `/clientes-v2` ou fluxos placeholder.

Não copiar integralmente:

- `ClientController.php` antigo;
- `ClientV2Controller.php`;
- `backend/routes.php` antigo;
- `backend/Views/clients/create.php` antiga;
- `backend/Views/contracts/*` antigas;
- assets APP V2.

## Riscos

- Copiar o `ClientController` antigo inteiro traz hub, permissões, placeholders e dependências fora do escopo.
- Copiar a rota `/clientes` antiga aponta para um piloto V2 proibido nesta release.
- Copiar views APP V2 integra IA contextual e assets experimentais.
- Copiar apenas os inputs CSRF sem toda a infraestrutura quebra formulários.
- Copiar toda a infraestrutura CSRF agora pode causar regressão ampla em login, aceite público e POSTs existentes.
- A validação MIME antiga rejeita arquivo silenciosamente; deve retornar erro claro quando aplicada.
- Alterar a normalização do login exige testar duplicidade e envio real/simulado ao MkAuth com `_`, `.`, `-`, espaço e acento.

## Plano mínimo recomendado

1. Corrigir apenas a validação de login:
   - preservar `_`, `.`, `-`;
   - normalizar caixa/acentos;
   - rejeitar espaços e caracteres inválidos;
   - atualizar mensagem da view e do JavaScript.
2. Executar regressão do cadastro existente:
   - rascunho;
   - fotos;
   - coordenadas;
   - retomada;
   - aceite;
   - envio/recuperação MkAuth.
3. Em uma mudança separada e aprovada, criar `/clientes` com o hub legado read-only:
   - `ClientController::index/search/detail`;
   - views legadas;
   - sem `ClientV2Controller` e sem placeholders.
4. Manter MIME real e CSRF para a Etapa 4, com anúncio prévio de risco e impacto.
5. Não alterar contratos/aceite nesta Etapa 2, salvo correção comprovada por teste; o núcleo já é equivalente.

## Resposta direta sobre `_`

Sim, a referência antiga permite `_`.

- Regex responsável: `ClientController::isValidLogin()`, aproximadamente linha 2926 do diretório antigo.
- Normalização antiga que preserva `_`: `ClientController::normalizeLoginInput()`, aproximadamente linhas 2931-2937.
- Validação JavaScript antiga: `public/assets/js/app.js`, aproximadamente linhas 2320-2330.
- Ajuda visual antiga: `backend/Views/clients/create.php`, aproximadamente linha 147.

A branch limpa também permite `_`; sua diferença problemática é converter caracteres inválidos para `_` automaticamente em `sanitizeLogin()` e `normalizeLoginValue()`.
