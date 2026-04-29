# Arquitetura Multiempresa

## Decisão Recomendada Agora

Para a primeira produção, use uma instância do ISP Auxiliar por provedor:

- arquivos separados;
- banco local separado;
- `.env` separado;
- `APP_PROVIDER_KEY` do provedor;
- backup e suporte mais simples.

Essa abordagem reduz risco operacional enquanto o cadastro de novos clientes entra em uso real.

## Preparação Para Produto Comercial

Mesmo usando uma instância por provedor, o banco já foi modelado com `provider_id`.

Isso permite evoluir depois para uma instalação compartilhada, com vários provedores no mesmo sistema, desde que todo controller, repository e relatório filtre dados pelo provedor atual.

## Perfis

- `platform_admin`: administra a plataforma e pode futuramente enxergar múltiplos provedores.
- `manager`: gestor do provedor, configura MkAuth e acompanha operação.
- `technician`: perfil previsto localmente; na operação atual, técnicos entram pelo usuário do MkAuth.

## Configuração MkAuth Por Provedor

Cada provedor possui registros em `provider_settings`:

- URL do MkAuth;
- Client ID;
- Client Secret;
- token de API, quando usado;
- host, porta, banco, usuário e senha MySQL de consulta;
- charset e algoritmos de senha.

Esses dados podem ser preenchidos pelo menu `Configuracoes`, acessível a gestores locais.

## Isolamento De Evidências

As fotos e assinaturas continuam em `storage/uploads/clientes/<referencia>`.

Para SaaS compartilhado, o próximo ajuste recomendado é incluir o `provider_slug` no caminho, por exemplo:

```text
storage/uploads/provedores/ievo/clientes/<referencia>
```

Na instância por provedor isso não é obrigatório, mas já fica mapeado como evolução simples.
