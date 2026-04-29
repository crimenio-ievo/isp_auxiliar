## Arquitetura

O ISP Auxiliar é uma aplicação PHP pura, sem framework, organizada em camadas simples.

## Camadas

- `public/`: ponto de entrada HTTP e assets públicos.
- `backend/bootstrap/`: autoload, ambiente, container e inicialização.
- `backend/config/`: configuração de aplicação, paths e banco.
- `backend/routes.php`: rotas HTTP centrais.
- `backend/Core/`: `Router`, `Request`, `Response`, `View`, `Config`, `Env`, `Container` e `Flash`.
- `backend/Controllers/`: fluxo das páginas e endpoints internos.
- `backend/Infrastructure/MkAuth/`: API HTTP, consulta MySQL e mapeamento de payload para MkAuth.
- `backend/Infrastructure/Database/`: conexão e migrations do banco local.
- `backend/Infrastructure/Local/`: repositório do banco complementar do ISP Auxiliar.
- `backend/Views/`: templates PHP e layouts.
- `storage/`: evidências, checkpoints, sessões e arquivos gerados em execução.
- `docs/`: documentação operacional.

## Perfis De Acesso

- Gestor local: salvo em `app_users`, acessa configuração do provedor e módulos administrativos do ISP Auxiliar.
- Técnico MkAuth: autenticado em `sis_acesso`, usa o cadastro de cliente, evidências e validação de instalação.
- Administrador da plataforma: previsto em `app_users.role = platform_admin` para cenários multiempresa.

## Fluxo De Cadastro

1. `GET /clientes/novo` abre o formulário.
2. Frontend aplica máscaras, validações e rascunho local.
3. `POST /clientes/novo` valida no backend e salva fotos temporárias.
4. `GET /clientes/novo/aceite` mostra dados para confirmação e assinatura.
5. `POST /clientes/novo/aceite` salva evidências locais e envia ao MkAuth.
6. `GET /clientes/conexao` orienta validação Radius.
7. `POST /clientes/conexao/finalizar` consulta `radacct` e finaliza se houver sessão ativa.

## Integração MkAuth

- `MkAuthClient`: autentica e chama API do MkAuth.
- `ClientPayloadMapper`: transforma dados internos no payload aceito pelo MkAuth.
- `ClientProvisioner`: cria e depois atualiza o cliente para garantir campos avançados.
- `MkAuthDatabase`: consulta MySQL do MkAuth para usuários, duplicidade, planos, vencimentos e Radius.

## Armazenamento Local

O sistema usa banco local complementar para índices e auditoria, mantendo arquivos grandes em disco.

Tabelas principais:

- `providers`: empresas/provedores.
- `provider_settings`: conexão MkAuth por provedor.
- `app_users`: gestores e administradores locais.
- `client_registrations`: cadastros enviados ao MkAuth.
- `evidence_files`: índice das fotos, assinatura e aceite.
- `installation_checkpoints`: etapa final de validação Radius.
- `audit_logs`: eventos operacionais.

Arquivos gerados em disco:

- `storage/uploads/clientes/<referencia>/aceite.json`
- `storage/uploads/clientes/<referencia>/assinatura.png`
- `storage/uploads/clientes/<referencia>/foto_*`
- `storage/installations/<token>.json`

## Estratégia Multiempresa

O caminho mais seguro para a primeira produção é manter uma instância por provedor, com banco local próprio e `APP_PROVIDER_KEY` definido. Isso simplifica backup, suporte e isolamento.

A estrutura já tem `provider_id` em todas as tabelas relevantes. Portanto, quando fizer sentido comercialmente, o sistema pode evoluir para uma instalação compartilhada com múltiplos provedores no mesmo banco, desde que as consultas e permissões continuem sempre filtradas por provedor.
