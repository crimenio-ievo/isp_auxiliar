# Validação crítica do novo cliente

Data: 2026-08-06.

## Causa raiz e rotas auditadas

O POST inicial `/clientes/novo` já validava a maior parte do formulário. A vulnerabilidade estava na retomada: `/clientes/novo/aceite` recarregava o rascunho e validava somente assinatura/aceite. Além disso, `ClientProvisioner` mapeava documento vazio e podia chegar ao gateway sem uma última barreira. O único chamador de criação externa localizado é `ClientController::finalize` → `ClientProvisioner::provision` → `ClientGateway::createClient`; não foi encontrada outra rota interna de criação rápida ou AJAX.

`ClientCompletionValidator` agora normaliza e valida o snapshot em três pontos: no formulário inicial, novamente na conclusão do rascunho e dentro do provisionador antes de resolver o plano ou chamar o gateway. Os dois POSTs possuem CSRF. Erros preservam o rascunho, retornam à etapa de dados, destacam os campos e focam o primeiro inválido.

## Matriz de conclusão

| Campo | Origem | Rascunho incompleto | Conclusão | Validação | Destinos |
|---|---|---:|---:|---|---|
| Pessoa | Inferida do documento | permitido | obrigatório | PF/PJ coerente | snapshot/MkAuth |
| CPF/CNPJ | formulário | permitido | obrigatório | dígitos, tamanho, verificadores, repetição, tipo | local/MkAuth |
| Nome/razão social | formulário | permitido | obrigatório | não vazio | local/MkAuth/contrato |
| Login | formulário | permitido | obrigatório | `a-z`, número, ponto, hífen, underscore; 3–64 | local/MkAuth |
| Plano | catálogo MkAuth | permitido | obrigatório | UUID/código oficial compatível; releitura posterior | local/MkAuth/contrato |
| Telefone | formulário | permitido | obrigatório | 10 ou 11 dígitos com DDD | local/MkAuth/aceite |
| Endereço, número, bairro | formulário | permitido | obrigatório | não vazios; `SN` aceito | local/MkAuth |
| Cidade/UF | diretório/MkAuth | permitido | obrigatório | cidade não vazia e UF com 2 letras | local/MkAuth |
| Vencimento | lista MkAuth | permitido | obrigatório | dia permitido e entre 1–31 | local/MkAuth |
| Tecnologia/Local DICI | catálogo/formulário | permitido | obrigatório | fibra/rádio e urbano/rural | local/MkAuth |
| Coordenadas | GPS/mapa | permitido | obrigatório | latitude/longitude em faixa | local/MkAuth/evidência |
| Adesão, parcelas, fidelidade | configuração/formulário | permitido | obrigatório | regras comerciais configuradas | contrato/local |
| Foto e aceite | câmera/formulário | permitido | obrigatório na etapa correspondente | MIME/evidência/assinatura | storage/contrato |

Planos sem marca explícita de localidade são tratados como compatíveis com ambos os locais; quando nome/descrição identifica rural ou urbano, a restrição é preservada. O UUID/código oficial continua obrigatório e `ClientPlanConfirmationService` mantém a releitura, divergência parcial e retry pelo UUID/request_id.

## Falha parcial e transação

Após sucesso externo, registro, contrato, evidência e checkpoint locais são gravados em transação. Se falharem, há rollback local e o rascunho preserva UUID, `request_id` e estado `external_created_local_pending`, evitando novo POST no retry. Mensagens e chamados continuam fora dessa transação e bloqueados em homologação.

## Investigação somente leitura

Resultado em 2026-08-06:

- registros locais em `client_registrations` sem documento: 0;
- registros no MkAuth sem documento: 2, IDs mascarados `mk-489e75fe` e `mk-b88fbaa5`;
- ambos ativos, sem `cadastro` e sem `data_ins`, com o mesmo operador mascarado `usr-35ed3cd7`;
- nenhum possui correspondência em `client_registrations`;
- um não possui auditoria do Auxiliar; o outro possui somente auditoria posterior de contrato/aceite, não de provisionamento.

Conclusão provável: registros legados ou criados diretamente no MkAuth, anteriores/externos ao fluxo atual. Não há evidência de que o POST atual do ISP Auxiliar os tenha criado. Nenhum registro foi corrigido nesta tarefa; a recomendação é saneamento administrativo separado, com confirmação documental do titular.
