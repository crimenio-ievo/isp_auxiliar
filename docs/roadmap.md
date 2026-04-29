# Roadmap Recomendado

## Objetivo Imediato

Colocar o cadastro de novos clientes em produção com segurança, mantendo o MkAuth como sistema principal e o ISP Auxiliar como camada operacional complementar.

## Próximos Passos Prioritários

1. Criar tela de consulta de clientes cadastrados pelo ISP Auxiliar usando `client_registrations`.
2. Criar tela de detalhes da instalação com fotos, assinatura, coordenada e status Radius.
3. Melhorar logs com filtros por técnico, data, cliente e ação.
4. Registrar UUID/id do cliente retornado pelo MkAuth quando a API disponibilizar esse dado de forma confiável.
5. Criar rotina automatizada de backup do banco local e de `storage/uploads/clientes`.
6. Adicionar checklist técnico antes da assinatura.
7. Iniciar módulo simples de equipamentos em comodato.

## Complementos Simples E Úteis

### Consulta De Cadastro

Uma tela para buscar por nome, login, CPF/CNPJ ou período e abrir:

- dados enviados ao MkAuth;
- link de evidências;
- status de validação Radius;
- operador responsável;
- data/hora do aceite.

É simples e entrega valor rápido.

### Auditoria

Ampliar eventos como:

- operador fez login;
- iniciou cadastro;
- salvou dados;
- assinou aceite;
- enviou ao MkAuth;
- validou conexão Radius;
- abriu evidências.

Isso ajuda em rastreabilidade e suporte.

### Checklist De Instalação

Adicionar perguntas objetivas antes da assinatura:

- roteador instalado;
- potência/sinal conferido;
- cabo organizado;
- teste de navegação realizado;
- cliente orientado;
- equipamento em comodato confirmado.

Pode ser salvo junto do aceite.

### Consulta Radius

Uma tela simples para digitar login e ver:

- online/offline;
- IP recebido;
- NAS;
- MAC/calling station;
- última autenticação;
- última falha.

Isso já aproveita `radacct` e `radpostauth`.

## Estoque Integrado Ao MkAuth

Estoque é útil, mas eu faria em duas fases.

### Fase 1: Controle Local Simples

- Produtos/equipamentos cadastrados no ISP Auxiliar.
- Entrada e saída manual.
- Vínculo de equipamento ao cadastro do cliente.
- Registro de comodato nas evidências.

### Fase 2: Integração Com MkAuth

- Consultar produtos/estoque do MkAuth se a API e permissões forem suficientes.
- Baixar item do estoque ao finalizar instalação.
- Registrar serial/MAC/ONU/roteador vinculados ao cliente.

Começar local é mais seguro, porque estoque costuma ter regra operacional específica e impacto financeiro.

## Melhor Primeiro Módulo Após Cadastro

O melhor próximo módulo é "Consulta e Histórico de Instalações".

Motivo:

- usa dados que o sistema já gera hoje;
- reduz dependência de novo mapeamento MkAuth;
- facilita suporte e auditoria;
- prepara o caminho para estoque e comodato.

Depois dele, o estoque entra com base mais firme.
