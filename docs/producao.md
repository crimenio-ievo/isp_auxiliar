# Manual de Produção

Este guia descreve como colocar o ISP Auxiliar em um servidor novo, integrado a um MkAuth existente.

## 1. Visão Geral

O ISP Auxiliar roda separado do MkAuth.

Ele usa:

- API do MkAuth para cadastrar e atualizar clientes.
- MySQL do MkAuth para consultar usuários, planos, vencimentos, duplicidade e Radius.
- Banco local do ISP Auxiliar para provedores, gestores, configurações, índices de evidências, checkpoints e logs.
- Arquivos locais para guardar fotos, assinatura e metadados de aceite.

O MkAuth continua sendo a base principal do cliente. O ISP Auxiliar guarda os dados complementares que o MkAuth não armazena bem, como fotos, assinatura, trilha de aceite, configuração por provedor e histórico operacional.

## 2. Fluxo Atual Do Sistema

1. Técnico acessa o ISP Auxiliar com usuário do MkAuth.
2. Técnico preenche o cadastro do cliente.
3. Sistema valida CPF/CNPJ, login, CEP, telefone, plano e vencimento.
4. Técnico anexa fotos da instalação.
5. Cliente assina o aceite na tela.
6. Sistema salva fotos, assinatura e `aceite.json` em `storage/uploads/clientes/`.
7. Sistema envia o cliente ao MkAuth via API.
8. Sistema grava no `obs` do cliente no MkAuth o link das evidências locais.
9. Sistema abre a tela de validação Radius.
10. Técnico configura o login no equipamento do cliente.
11. Sistema consulta `radacct` e finaliza quando encontra sessão ativa.

## 3. Onde Cada Dado Fica

| Dado | Local |
| --- | --- |
| Cliente, plano, vencimento e financeiro | Banco do MkAuth, tabela `sis_cliente` |
| Usuários operadores | Banco do MkAuth, tabela `sis_acesso` |
| Planos | Banco do MkAuth, tabela `sis_plano` |
| Contrato padrão | Banco do MkAuth, tabela `sis_contrato` |
| Conta boleto padrão | Banco do MkAuth, tabela `sis_boleto` |
| Validação Radius | Banco do MkAuth, tabelas `radacct` e `radpostauth` |
| Fotos da instalação | `storage/uploads/clientes/<referencia>/foto_*` |
| Assinatura | `storage/uploads/clientes/<referencia>/assinatura.png` |
| Aceite/metadados | `storage/uploads/clientes/<referencia>/aceite.json` |
| Configurações por empresa | Banco local, `provider_settings` |
| Gestores do ISP Auxiliar | Banco local, `app_users` |
| Índice de evidências | Banco local, `evidence_files` |
| Cadastros enviados | Banco local, `client_registrations` |
| Etapa de validação Radius | Banco local, `installation_checkpoints`, e espelho JSON em `storage/installations/<token>.json` |
| Logs operacionais | Banco local, `audit_logs` |

## 4. Requisitos Do Servidor Do ISP Auxiliar

- Debian, Ubuntu ou distribuição Linux equivalente.
- Apache 2.4 ou Nginx.
- PHP 8.2 ou superior.
- Extensões PHP:
  - `curl`
  - `pdo`
  - `pdo_mysql`
  - `json`
  - `mbstring`
  - `openssl`
  - `fileinfo`
- HTTPS ativo.
- Acesso de rede ao MkAuth pela API.
- Acesso de rede ao MySQL/MariaDB do MkAuth.

## 5. Instalação No Servidor Novo

Instale pacotes básicos:

```bash
apt update
apt install -y apache2 php php-curl php-mysql php-mbstring php-xml php-zip unzip git
```

Copie o projeto:

```bash
cd /var/www/html
git clone <repositorio> isp_auxiliar
cd isp_auxiliar
cp .env.example .env
```

Crie o banco local. Em servidores Debian/MariaDB, normalmente isso é feito como `root` pelo socket:

```sql
CREATE DATABASE IF NOT EXISTS isp_auxiliar CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'isp_auxiliar'@'localhost' IDENTIFIED BY 'SENHA_LOCAL_FORTE';
GRANT ALL PRIVILEGES ON isp_auxiliar.* TO 'isp_auxiliar'@'localhost';
FLUSH PRIVILEGES;
```

Depois ajuste `DB_USERNAME` e `DB_PASSWORD` no `.env` e rode as migrations:

```bash
php scripts/console.php migrate
```

Se o usuário configurado no `.env` tiver permissão para criar banco, também é possível usar:

```bash
php scripts/console.php db:create
php scripts/console.php migrate
```

Crie o primeiro gestor do provedor:

```bash
php scripts/console.php user:create-manager "Gestor do Provedor" gestor@provedor.com.br "SENHA_FORTE"
```

Se a primeira configuração estiver no `.env`, sincronize para o menu administrativo:

```bash
php scripts/console.php settings:sync-env
```

Permissões:

```bash
chown -R www-data:www-data storage logs tmp
chmod -R 775 storage logs tmp
```

Configure o Apache para permitir reescrita em `public/`:

```apache
<Directory /var/www/html/isp_auxiliar/public>
    AllowOverride All
    Require all granted
    DirectoryIndex index.php
</Directory>
```

Ative módulos necessários:

```bash
a2enmod rewrite ssl
systemctl reload apache2
```

## 6. HTTPS

Use HTTPS em produção. O GPS do celular depende de contexto seguro.

Para produção real, use certificado válido, como Let's Encrypt:

```bash
apt install -y certbot python3-certbot-apache
certbot --apache -d seu-dominio
```

Se usar IP interno sem domínio, é possível usar certificado interno ou autoassinado, mas o celular precisará confiar no certificado.

## 7. Arquivo `.env`

Campos principais:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://seu-dominio/isp_auxiliar/public
APP_PROVIDER_KEY=ievo
APP_TIMEZONE=America/Sao_Paulo

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=isp_auxiliar
DB_USERNAME=isp_auxiliar
DB_PASSWORD=senha_local_segura

MKAUTH_BASE_URL=https://ip-ou-dominio-do-mkauth
MKAUTH_CLIENT_ID=Client_Id_informado_no_mkauth
MKAUTH_CLIENT_SECRET=Client_Secret_informado_no_mkauth

MKAUTH_DB_HOST=ip-ou-dominio-do-mysql-mkauth
MKAUTH_DB_PORT=3306
MKAUTH_DB_NAME=mkradius
MKAUTH_DB_USER=isp_auxiliar
MKAUTH_DB_PASSWORD=senha_segura
```

Depois do primeiro acesso como gestor, esses dados podem ser mantidos pelo menu `Configuracoes`. O `.env` fica como fallback e configuração mínima de boot.

## 8. Configuração No MkAuth

No menu DEV/API do usuário:

- Configure `Client_Id` e `Client_Secret`.
- Libere permissões necessárias para cliente:
  - `GET`
  - `POST`
  - `PUT`

O sistema usa API para inserir e atualizar cliente. A atualização após criação é importante porque alguns campos avançados do MkAuth não são aplicados corretamente apenas no `POST`.

## 9. Usuário MySQL Do MkAuth

Para produção, prefira permissão de leitura nas tabelas consultadas e escrita somente se alguma rotina futura precisar.

Exemplo mais seguro:

```sql
CREATE USER 'isp_auxiliar'@'192.168.%' IDENTIFIED BY 'SENHA_FORTE_AQUI';

GRANT SELECT ON mkradius.sis_acesso TO 'isp_auxiliar'@'192.168.%';
GRANT SELECT ON mkradius.sis_cliente TO 'isp_auxiliar'@'192.168.%';
GRANT SELECT ON mkradius.sis_adicional TO 'isp_auxiliar'@'192.168.%';
GRANT SELECT ON mkradius.sis_plano TO 'isp_auxiliar'@'192.168.%';
GRANT SELECT ON mkradius.sis_boleto TO 'isp_auxiliar'@'192.168.%';
GRANT SELECT ON mkradius.sis_contrato TO 'isp_auxiliar'@'192.168.%';
GRANT SELECT ON mkradius.radacct TO 'isp_auxiliar'@'192.168.%';
GRANT SELECT ON mkradius.radpostauth TO 'isp_auxiliar'@'192.168.%';

FLUSH PRIVILEGES;
```

No ambiente de testes foi usado acesso mais amplo, mas em produção é melhor restringir.

## 10. Backup

Faça backup de:

- Projeto ou repositório Git.
- `.env`.
- Banco local do ISP Auxiliar.
- `storage/uploads/clientes/`.
- `storage/installations/`.
- `logs/`, quando logs persistentes forem ativados.

Exemplo:

```bash
mysqldump -u isp_auxiliar -p isp_auxiliar > /backup/isp_auxiliar_db_$(date +%F).sql
tar -czf /backup/isp_auxiliar_storage_$(date +%F).tar.gz /var/www/html/isp_auxiliar/storage
```

## 11. Limpeza Antes De Produção

Antes de copiar para o servidor real:

- Remover evidências de teste de `storage/uploads/clientes/`.
- Remover checkpoints de teste de `storage/installations/`.
- Remover registros de teste do banco local, se houver.
- Revisar `.env`.
- Confirmar HTTPS.
- Criar gestor real e trocar senhas temporárias.
- Testar login com usuário real do MkAuth.
- Fazer um cadastro real controlado e validar no MkAuth.
- Confirmar que o link de evidências abre pelo navegador.

## 12. Banco Complementar Local

O banco complementar já está estruturado para operação e evolução multiempresa.

Tabelas principais:

- `providers`: empresas/provedores atendidos.
- `provider_settings`: credenciais e parâmetros por provedor.
- `app_users`: gestores locais do ISP Auxiliar.
- `client_registrations`: índice dos cadastros enviados ao MkAuth.
- `evidence_files`: índice das fotos, assinatura e metadados.
- `installation_checkpoints`: validação final de conexão Radius.
- `audit_logs`: trilha de ações.

Para a primeira produção, a recomendação operacional é uma instância por provedor usando `APP_PROVIDER_KEY`. A modelagem já usa `provider_id`, então uma versão SaaS compartilhada pode evoluir depois sem reescrever a base.
