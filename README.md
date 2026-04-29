## ISP Auxiliar

Sistema auxiliar em PHP puro para cadastro padronizado de clientes, coleta de fotos, assinatura/aceite, integração com MkAuth e validação final de conexão Radius.

## Funcionalidades Atuais

- Login usando usuários do MkAuth.
- Cadastro de novo cliente com validação de CPF/CNPJ, login, telefone, e-mail e CEP.
- Consulta real de planos, vencimentos, cidades, usuários e duplicidade no MkAuth.
- Envio do cadastro para o MkAuth por API.
- Registro local de fotos da instalação, assinatura e metadados de aceite.
- Banco complementar local para provedores, gestores, configurações, índices de evidências, checkpoints e logs.
- Link público de evidências gravado no campo `obs` do cliente no MkAuth.
- Tela final para validar se o login aparece conectado no Radius.
- Dashboard, instalações, usuários e logs com dados operacionais disponíveis.

## Onde Os Dados São Salvos

- Cliente principal: banco do MkAuth, tabela `sis_cliente`, via API.
- Fotos da instalação: `storage/uploads/clientes/<referencia>/foto_*.jpg|png`.
- Assinatura: `storage/uploads/clientes/<referencia>/assinatura.png`.
- Metadados do aceite: `storage/uploads/clientes/<referencia>/aceite.json`.
- Checkpoints de instalação/conexão: `storage/installations/<token>.json`.
- Banco complementar: tabelas `providers`, `provider_settings`, `app_users`, `client_registrations`, `evidence_files`, `installation_checkpoints` e `audit_logs`.

As imagens continuam em arquivos por serem grandes e fáceis de servir/backup. O banco guarda os índices, vínculos, status e trilha de auditoria.

## Requisitos

- Linux com Apache ou Nginx.
- PHP 8.2 ou superior.
- Extensões PHP: `curl`, `pdo`, `pdo_mysql`, `json`, `mbstring`, `openssl`, `fileinfo`.
- HTTPS ativo no servidor do ISP Auxiliar para uso confiável de GPS no celular.
- Acesso HTTP/HTTPS do ISP Auxiliar para o MkAuth.
- Acesso MySQL do ISP Auxiliar para o banco do MkAuth, preferencialmente com permissões controladas.

## Instalação Rápida

1. Copie o projeto para o servidor:

```bash
cd /var/www/html
git clone <repositorio> isp_auxiliar
```

2. Crie o `.env`:

```bash
cd /var/www/html/isp_auxiliar
cp .env.example .env
```

3. Ajuste as credenciais no `.env`.

4. Crie o banco local e execute as migrations. Se o usuário do `.env` já tiver permissão de criação:

```bash
php scripts/console.php db:create
php scripts/console.php migrate
```

Se preferir criar manualmente, crie o banco `isp_auxiliar`, conceda permissões ao usuário configurado no `.env` e rode apenas `php scripts/console.php migrate`.

5. Crie o primeiro gestor local:

```bash
php scripts/console.php user:create-manager "Gestor iEvo" gestor@provedor.com.br "SENHA_FORTE"
```

6. Opcionalmente, sincronize as configurações do `.env` para o menu administrativo:

```bash
php scripts/console.php settings:sync-env
```

7. Garanta permissão de escrita:

```bash
chown -R www-data:www-data storage logs tmp
chmod -R 775 storage logs tmp
```

8. Aponte o servidor web para `public/` ou acesse pelo subdiretório configurado.

9. Abra:

```text
https://SEU_SERVIDOR/isp_auxiliar/public/login
```

Gestores acessam com o usuário local criado no comando acima. Técnicos acessam com o usuário já cadastrado no MkAuth.

## Configuração MkAuth

No MkAuth, habilite a API do usuário que será usada pelo sistema e libere os endpoints necessários:

- `cliente` com `GET`, `POST` e `PUT`.
- Permissões de consulta para planos, clientes e demais endpoints usados pela API, conforme a política do servidor.

No MySQL do MkAuth, o ISP Auxiliar consulta principalmente:

- `sis_acesso`
- `sis_cliente`
- `sis_adicional`
- `sis_plano`
- `sis_boleto`
- `sis_contrato`
- `radacct`
- `radpostauth`

## Documentação

O manual completo de produção está em:

```text
docs/producao.md
```

Os próximos complementos recomendados estão em:

```text
docs/roadmap.md
```

A estratégia multiempresa está em:

```text
docs/multiempresa.md
```
