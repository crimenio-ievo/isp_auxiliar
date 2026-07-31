# Terceira iteração — testes executados

Data: 2026-07-31. Banco local de homologação. Escrita MkAuth e mensagens reais
bloqueadas.

## Migration 017

Comando canônico:

```bash
php scripts/apply_migrations.php
```

Resultado: código 0; `017_messages_and_scanned_contracts.sql` aplicada. Foram
criadas `notification_channel_registry`, `message_template_versions` e
`client_documents`, e `message_templates` foi ampliada com escopo de provedor,
assunto/padrão, canais, versão e autoria.

Rollback estrutural local: FKs, índice e colunas aditivos foram removidos na
ordem inversa, as três tabelas novas foram removidas e o registro da migration
foi apagado. A ausência estrutural foi conferida. A migration foi reaplicada com
código 0. Estado final: 11 migrations aplicadas, 0 pendentes. Os defaults
prepararam 20 variantes novas; com os dois templates legados, o banco local
ficou com 22 registros.

## Suites

```bash
php tests/Feature/ThirdIterationSmoke.php
php tests/Feature/UpgradeCorrectionSmoke.php
php tests/Feature/OperationalProcessSmoke.php
```

Resultados:

- terceira iteração: código 0, 38 verificações;
- regressão Upgrade/Migração: código 0, 40 verificações e rollback;
- processos compartilhados: código 0, 60 verificações e rollback.

Total: 138 verificações automatizadas informadas pelas suites. Nenhuma executou
envio e nenhuma escreveu no MkAuth. O log de bloqueio do guard no teste é o
resultado esperado.

Cobertura nova: mapper D/H e desconhecido; URLs rápidas; operação automática;
fidelidade válida/inválida; defaults/variáveis/canais/snapshot de templates;
schema; render do perfil, lazy-load e quatro telas; assinatura/canais sem copiar
link; geração PDF; MIME falso; dry-run de plano; desconexão não prometida;
chamado forçado a dry-run; ausência de IA.

## Sintaxe e inspeções

```bash
php -l <cada PHP alterado ou novo>
node --check public/assets/js/app.js
git diff --check
apache2ctl configtest
```

Todos retornaram código 0. As rotas novas foram conferidas no arquivo central e
os endpoints mutáveis novos exigem autenticação/permissão; scanner e processo
exigem CSRF. Downloads são escopados por provedor.

Testes HTTP locais:

- aplicação atual/login: HTTP 200;
- raiz do checkout antigo: redirecionamento para o alias;
- login do alias antigo: HTTP 200, banner e assets presentes.

## Não aprovado nesta etapa

- envio real de WhatsApp/e-mail;
- PUT real de plano, desconexão ou chamado real;
- consulta real antes/depois de uma escrita;
- jornada integral com navegador automatizado;
- Android/iOS físico, câmera física, rotação, zoom e leitor de tela em aparelho;
- comparação visual administrativa completa do layout antigo.

Esses itens permanecem pendentes e a entrega não está pronta para produção.
