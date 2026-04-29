# AGENTS.md

## Objetivo
Manter e evoluir o `isp_auxiliar` como plataforma auxiliar de ISP, separada do MkAuth, com integração por API e apoio operacional para cadastro, evidências, assinatura e validação de instalação.

## Sistemas Relacionados
- `isp_auxiliar`: projeto atual e principal desta conversa.
- `isp_map2`: projeto atual de mapa. Se houver tarefa de mapa, trabalhar aqui.
- `isp_map`: projeto legado. Não alterar salvo pedido explícito.

## Princípios
- Preserve o funcionamento atual do `isp_auxiliar` antes de qualquer refatoração.
- Evite mudanças amplas sem necessidade.
- Priorize correções pequenas, seguras e verificáveis.
- Não apague fluxos já funcionais sem substituição pronta.
- Não dependa de manipulação manual da interface do MkAuth para resolver integração.
- Quando houver dúvida de regra de negócio, pare e confirme antes de mudar comportamento.

## Tecnologias
- Backend em PHP puro.
- Banco MySQL/MariaDB.
- Frontend responsivo, leve e funcional em celular.
- Sem framework pesado nesta fase.

## Regras de Trabalho
- Não misturar regra de negócio com apresentação.
- Centralizar integração com MkAuth em camada própria.
- Validar no backend mesmo quando houver validação no frontend.
- Priorizar segurança, rastreabilidade e compatibilidade.
- Manter o sistema navegável e funcional durante a evolução.
- Não reverter alterações do usuário.
- Não usar comandos destrutivos como `git reset --hard` ou `git checkout --` sem autorização.

## Processo de Mudança
- Ler os arquivos relacionados antes de editar.
- Fazer alterações pequenas e coerentes.
- Preferir `apply_patch` para edições manuais.
- Verificar sintaxe PHP antes de finalizar.
- Quando possível, testar o fluxo alterado no navegador ou por comando.
- Atualizar documentação quando a mudança afetar uso, instalação ou operação.

## Prioridades Funcionais
1. Login e perfis de acesso.
2. Cadastro de cliente.
3. Captura de CEP, GPS, fotos e assinatura.
4. Registro de evidências e trilha de auditoria.
5. Integração com MkAuth.
6. Consulta e histórico operacional.
7. Evolução para módulos complementares.

## Estrutura Esperada
- `backend/`
- `frontend/`
- `database/`
- `docs/`
- `scripts/`
- `tests/`
- `storage/`
- `logs/`
- `tmp/`
- `public/`

## Banco e Persistência
- Usar banco local complementar para configuração, histórico e auditoria quando aplicável.
- Usar arquivos em `storage/` para evidências grandes como fotos e assinatura.
- Não mover dados para o banco sem motivo claro.
- Manter compatibilidade com o que já está salvo em produção/teste.

## Integração MkAuth
- Tratar MkAuth como sistema externo principal para dados do cliente.
- Concentrar consultas e chamadas de API em uma camada própria.
- Não alterar o MkAuth diretamente como solução de atalho.
- Sempre que possível, registrar o vínculo local do que foi enviado ao MkAuth.

## Entregas Esperadas
- Código base funcional.
- Explicação breve da arquitetura quando necessário.
- SQL/migrations quando houver mudança de estrutura.
- Próximos passos pequenos e seguros.
- Sem quebrar o fluxo já existente.

## Controle de Escopo
- Nunca refatorar múltiplos arquivos sem solicitação explícita.
- Nunca alterar estrutura de diretórios sem aprovação.
- Não criar novos padrões de arquitetura sem alinhamento.
- Evitar mudanças que impactem múltiplos módulos ao mesmo tempo.

## Validação e Testes
- Sempre informar como testar a alteração.
- Garantir que o sistema continua acessível via navegador.
- Validar rotas afetadas.
- Validar integração com banco quando houver alteração.

## Visão Futura
- Este sistema deve evoluir para arquitetura modular.
- `isp_auxiliar` será o núcleo administrativo.
- `isp_map2` será integrado futuramente como módulo de mapa.
- Evitar decisões que dificultem essa integração.
