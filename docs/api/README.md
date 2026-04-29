## Endpoints Internos

- `GET /api/health`: status básico da aplicação.
- `GET /api/cep/lookup?cep=00000000`: consulta CEP e retorna cidade, UF, IBGE, logradouro e bairro.
- `GET /api/cliente/validar?type=login&value=...`: valida duplicidade de login no MkAuth.
- `GET /api/cliente/validar?type=cpf_cnpj&value=...`: valida duplicidade de CPF/CNPJ no MkAuth.
- `GET /api/cliente/conexao?login=...`: consulta se o login possui sessão ativa no Radius.
- `GET /api/usuario/validar?login=...`: valida existência de usuário operador no MkAuth.

## Endpoints De Evidência

- `GET /clientes/evidencias?ref=...`: tela pública por referência opaca com fotos, assinatura e metadados.
- `GET /clientes/evidencias/arquivo?ref=...&file=...`: entrega imagem de evidência.

Os endpoints de evidência dependem da referência gravada no campo `obs` do cliente no MkAuth.
