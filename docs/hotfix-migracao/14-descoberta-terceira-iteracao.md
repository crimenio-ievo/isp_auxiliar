# Terceira iteração — descoberta técnica

Data: 2026-07-31. Ambiente local/homologação. Nenhuma chamada externa de
escrita ou envio foi usada neste levantamento.

## Base e proteções

- branch de origem: `hotfix/migracao-piloto`;
- commit de origem confirmado: `8f7ea4f`;
- branch de trabalho: `feature/cliente-migracao-simplificacao`;
- `APP_ENV=local` e `MKAUTH_WRITE_ENABLED=false`;
- WhatsApp/Evotrix e e-mail permanecem em dry-run;
- os dois `.env.backup-*` e `storage/contracts/acceptances/` foram preservados;
- não havia arquivo rastreado da integração pausada com IA.

## Dados encontrados no MkAuth

`sis_cliente` é a fonte principal. A consulta do perfil usa a linha completa e
normaliza somente o que é exibido. Foram localizados:

- identidade: `id`, `uuid_cliente`, `nome`, `nome_res`, `cpf_cnpj`, `rg`,
  `nascimento`, `pessoa`, `responsavel`, `login`;
- contato: `fone`, `celular`, `celular2`, `email`;
- endereço: `endereco`, `numero`, `complemento`, `bairro`, `cidade`, `estado`,
  `cep`, `cidade_ibge`, `dot_ref`, `coordenadas`;
- contrato/plano: `contrato`, `plano`, `cli_ativado`, `bloqueado`, `cadastro`,
  `last_update`;
- conexão/equipamento: `user_ip`, `user_mac`, `equipamento`, `onu_ont`,
  `porta_olt`, `caixa_herm`, `porta_splitter`, `interface`;
- cobrança resumida: `venc`, `tipo_cob`, `conta`, `tit_abertos`,
  `tit_vencidos`, `parc_abertas`, `desconto`, `acrescimo`.

`sis_plano` fornece `uuid_plano`, `nome`, `valor`, `tecnologia`, `veldown`,
`velup`, `descricao` e `oculto`. O vínculo é por nome ou UUID. O sistema não
deduz tecnologia pelo nome comercial do plano.

`radacct` fornece sessão ativa por `username`, `framedipaddress`,
`nasipaddress`, `callingstationid`, `acctstarttime` e `acctupdatetime`;
`connected_seconds` é derivado da hora de início. `radpostauth` oferece a última
autenticação por `reply`, `authdate`, `ip`, `mac` e `ramal`.

`sis_lanc` fornece leitura de títulos (`datavenc`, `datapag`, `status`, `tipo`,
`tipocob`, `formapag`, `valor`, `valorpag`, `referencia`). Não foi criada tabela
financeira paralela nem operação de edição.

`sis_suporte` fornece número/UUID, assunto, abertura, fechamento, status,
cliente/login, atendente, técnico, `login_atend`, prioridade e motivo de
fechamento. O responsável só é considerado confiável quando `login_atend` vem
preenchido. `sis_msg` permanece uma fonte auxiliar do chamado.

Os campos de perfil existem no schema, mas são opcionais por cliente. Telefones,
e-mail secundário, coordenadas, referência, IP, equipamento/ONU e dados de OLT
foram tratados como eventualmente vazios ou nulos. Status, tecnologia e campos
financeiros podem conter códigos/valores históricos; a interface não os promove
a uma conclusão sem regra conhecida.

## Tecnologia

O código histórico da coleta SCM/SICI identifica `D` como FWA e `H` como FTTH.
O mapper central traduz:

| Código | Exibição | Família operacional |
|---|---|---|
| `D` | Rádio fixo (FWA) | `radio` |
| `H` | Fibra até o imóvel (FTTH) | `fibra` |
| `FWA` | Rádio fixo (FWA) | `radio` |
| `FTTH` | Fibra até o imóvel (FTTH) | `fibra` |
| outro | Tecnologia não identificada | sem família |

O código desconhecido fica disponível somente no detalhe técnico. Fontes:
[tabela histórica SCM/SICI da Anatel](https://www.anatel.gov.br/Portal/verificaDocumentos/documento.asp?assuntoPublicacao=Dados+informativos+-+Banda+Larga&caminhoRel=null&documentoPath=257088.pdf&filtro=1&numeroPublicacao=257088)
e [coleta atual de acessos da Anatel](https://www.gov.br/anatel/pt-br/regulado/universalizacao/coletas-de-dados-de-acessos).

## Matriz de capacidades

| Função | Evidência | Classificação nesta entrega |
|---|---|---|
| consultar cliente/plano | banco e GETs do adapter | somente leitura, comprovada |
| consultar sessão | `radacct`/`radpostauth` | somente leitura, comprovada |
| consultar financeiro | `sis_cliente`/`sis_lanc` | somente leitura, comprovada |
| consultar chamado | `sis_suporte` | somente leitura, comprovada |
| alterar plano | `PUT /api/cliente/editar`, payload `{uuid, plano}` | parcialmente comprovada; somente dry-run |
| consultar antes/depois | banco de leitura | preparada; pós-escrita não executada |
| desconectar sessão | não localizado endpoint oficial seguro | não encontrada; fallback manual |
| recalcular financeiro | efeito pós-plano não comprovado | não recomendado |
| abrir chamado | `MkAuthTicketService`/endpoint configurável | preparada, escrita bloqueada |
| fechar chamado | endpoint apenas documentado | não implementada/não validada |
| responsável pelo fechamento | `login_atend` | confiável apenas quando preenchido |

O OpenAPI local é evidência documental, não prova de comportamento. Não houve
PUT/POST real, alteração direta no banco MkAuth, RADIUS CoA, SSH ou automação de
MikroTik.

## Checkout antigo

O checkout `/var/www/html/isp_auxiliar_old_20260612_1815` não possuía URL/alias
Apache próprio; sua aplicação esperava a pasta `public`, base URL e reescrita
coerentes. A tentativa pelo DocumentRoot atual também misturava caminho de
script e sessão com a aplicação principal.

A correção de teste criou o alias `/isp-auxiliar-old-reference`, ajustou a URL
local do checkout, isolou nome/caminho do cookie de sessão, bloqueou POSTs (salvo
login), exigiu perfil administrativo e incluiu o banner
“LAYOUT ANTIGO — SOMENTE REFERÊNCIA”. Dependências/autoload e PHP abriram após o
alias; a referência não substitui o DocumentRoot atual.

