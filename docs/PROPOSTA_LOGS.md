# Proposta — categorização de Logs

Documento de proposta (sem implementação). Investigação exaustiva de tudo que hoje se chama ou
funciona como "log" no sistema, para propor uma categorização em linguagem simples antes de
desenhar a tela nova.

## 1. Origens reais de log hoje (nada hipotético)

| # | Origem | Onde mora | Quem escreve | O que significa, em português simples |
|---|---|---|---|---|
| 1 | `audit_logs` | Tabela local | `LocalRepository::log()`, chamado por quase todo controller/serviço | "Quem fez o quê, quando, e de onde" — login, cadastro enviado ao MkAuth, contrato criado/atualizado, aceite confirmado, pendência financeira criada/concluída/cancelada, chamado aberto, etapa de migração concluída/pulada, exclusão de registro local, teste de conexão. Mais de 70 tipos de evento (`action`) diferentes gravados hoje. É o "livro de ocorrências" administrativo, para responsabilização. |
| 2 | `notification_logs` | Tabela local | `EmailService`, `EvotrixService` | "Quais mensagens (WhatsApp ou e-mail) o sistema realmente tentou mandar pro cliente, pra qual contato, e se deu certo" — inclui o texto e a resposta do provedor. Também guarda os testes de conexão feitos pela equipe. |
| 3 | `sis_suporte` / `sis_msg` (MkAuth) | Banco **externo** do MkAuth | `MkAuthTicketService` (só escreve mensagem quando abre chamado) | "O histórico de chamados de atendimento/financeiro dentro do próprio MkAuth" — não é log deste sistema; é o que o financeiro do provedor já vê no MkAuth. Aqui só existe o reflexo disso (as ações `*.mkauth_ticket.*` em `audit_logs`). |
| 4 | `logs/client-plan-confirmation.log` | Arquivo (JSON por linha) | `ClientProvisioner` | "Diário técnico de cada tentativa de cadastrar/confirmar um cliente no MkAuth" — usado só por suporte técnico quando algo trava no meio do cadastro. |
| 5 | `logs/mkauth-write-guard.log` | Arquivo (texto) | `MkAuthWriteGuard` | "Alarme de segurança: toda vez que o sistema tentou gravar algo real no MkAuth mas a trava de ambiente de teste impediu." Não deveria ter nada em produção normal. |
| 6 | `error_log` (Apache/PHP) | Fora da aplicação | 2 pontos no código + o PHP sozinho | "Erros técnicos brutos que o servidor registra" — só interessa a quem mexe em código. |
| 7 | `logs/app.log` (`LOG_CHANNEL`/`LOG_PATH` no `.env`) | — | **ninguém** | Não existe de fato. É configuração prevista no `.env` mas sem nenhum código que escreva nele — promessa não cumprida, `logs/` está vazio (só `.gitkeep`). |

## 2. O que já existe de tela hoje (nenhuma delas completa)

Não existe uma tela central de Logs. Existem **três vistas parciais e desalinhadas**:

- **`/logs` (órfã):** rota existe (`SystemController::logs()`), mas **não está no menu lateral nem
  linkada de nenhuma tela** — só é acessível digitando a URL. Mostra só `audit_logs` (100 mais
  recentes, globais), com um `level` inventado (`'INFO'` fixo, não é dado real) e uma tradução para
  português cobrindo só 5 dos mais de 70 tipos de evento — o resto aparece cru
  (`"{action} por {actor}."`). Não tem checagem de permissão de admin/gestor.
- **Aba "Notificações e histórico" na ficha do contrato:** mistura `notification_logs` +
  `audit_logs` filtrados por aquele contrato, com sua própria tradução de eventos (`$auditLabel`
  inline na view).
- **Linha do tempo da ficha do cliente:** mistura cadastro, contrato, aceite, financeiro e
  `audit_logs` traduzidos num feed só (13 eventos mapeados + 2 genéricos), com sua própria terceira
  tradução (`translateAuditEvent()`).

Ou seja: **três dicionários de tradução diferentes e incompletos**, cada um cobrindo uma fatia, sem
nenhum lugar que mostre o quadro completo.

## 3. Severidade / nível

Nenhuma origem tem uma modelagem de severidade real:
- `audit_logs` não tem coluna de nível — a tela `/logs` inventa `'INFO'` para tudo.
- `notification_logs` tem `status` (`simulado`/`enviado`/`erro`) — é o mais próximo de uma
  severidade real que existe hoje em qualquer lugar (erro = falha de envio).
- MkAuth (`sis_suporte`) tem status de workflow (aberto/fechado), não severidade técnica.
- Os dois arquivos (`client-plan-confirmation.log`, `mkauth-write-guard.log`) distinguem
  sucesso/falha só pelo nome do evento, sem campo formal.

## 4. Retenção / limpeza

Não existe rotina automática nenhuma. O único mecanismo de limpeza é
`scripts/reset_test_operational_data.php` — uma ferramenta manual de linha de comando, **bloqueada
em produção** (só roda em `local/development/testing/homolog/staging`), que faz backup antes,
depois `TRUNCATE` em `audit_logs`/`notification_logs` (entre outras tabelas), e registra o próprio
reset como um novo evento de auditoria. Os dois arquivos de log crescem sem limite (sem
`logrotate` configurado para eles).

## 5. Proposta de categorização (para a tela nova)

Categorizar por **"o que essa entrada me diz", não por tabela de origem** — a mesma lógica já usada
no agrupamento de Configurações (Fase 2) e na proposta de Contratos/Processos (Fase 3):

1. **Atividade de contrato e aceite** — tudo de `audit_logs` com `entity_type` em
   `client_contract`/`contract_acceptance`/`financial_task`, mais `notification_logs` (WhatsApp/
   e-mail enviados) — o que hoje já aparece (fragmentado) na aba do contrato.
2. **Provisionamento e cadastro** — eventos de `client.*`/`installation.*` em `audit_logs`, mais
   `client-plan-confirmation.log` para quem precisar do detalhe técnico de uma falha específica.
3. **Processos operacionais (migração/instalação)** — eventos `operational_process.*`.
4. **Acesso e segurança** — `auth.login`, `auth.remote_admin_detected`, e o alarme
   `mkauth-write-guard.log` (bloqueio de escrita indevida).
5. **Configurações e testes de conexão** — `settings.*.test`/`.failed`, exclusões locais
   (`installation.deleted`, `manager_user.updated`).
6. **Chamados financeiros no MkAuth** — reflexo local das ações `*.mkauth_ticket.*`/
   `*.financial_task.ticket.*`, com um lembrete na própria tela de que o histórico completo real
   mora no MkAuth (`sis_suporte`/`sis_msg`), não aqui.

Cada categoria precisa de **um único dicionário de tradução compartilhado** (hoje são três
incompletos e divergentes) — condição prévia para a tela nova não nascer com o mesmo problema de
fragmentação que já existe.

## 6. O que a tela nova precisaria decidir (fora do escopo desta fase, só sinalizando)

- Se `/logs` vira essa tela central ou se é substituída por uma nova rota — hoje ela é órfã de
  navegação e sem checagem de permissão de admin/gestor, então qualquer que seja o caminho escolhido
  precisa também resolver isso.
- Se vale unificar os três dicionários de tradução (`SystemController::auditMessage()`,
  `ClientController::translateAuditEvent()`, o `$auditLabel` inline em `contracts/detalhe.php`) num
  serviço só, reaproveitado nas três telas existentes além da nova.
- `logs/app.log` (item 7) — como é configuração morta, vale decidir se remove do `.env`/documentação
  ou se algum dia é implementado de verdade; não faz sentido a tela nova prometer mostrá-lo.

Nenhum código foi alterado nesta fase — é só este documento, com a investigação completa por trás.
