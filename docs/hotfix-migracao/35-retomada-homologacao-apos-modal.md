# Retomada da homologação após o hotfix de modal

## Pré-condições

1. Usar `fix/modal-pendencia-cliente` em ambiente local/controlado.
2. Confirmar `MKAUTH_WRITE_ENABLED=false` e notificações/chamado em dry-run.
3. Forçar recarga dos assets e confirmar que `app.css` e `app.js` possuem `?v=`.
4. Usar somente dados autorizados e não executar mensagem, escrita ou chamado real.

## Jornada nos clientes observados

Repetir com `marquinhos_laranjeiras`, `abel_crindiuba` e
`elisabete_capelinha`:

1. abrir `/clientes/detalhe?login={login}` e confirmar que nenhum modal abriu;
2. confirmar página utilizável, sem overlay e com scroll normal;
3. abrir Cliente e fechar por X;
4. abrir Endereço e fechar por overlay e Esc;
5. abrir Endereço e, depois, Financeiro; confirmar que existe somente um diálogo;
6. fechar Financeiro enquanto “Carregando detalhes financeiros...” estiver visível;
7. reabrir e confirmar conclusão ou erro controlado;
8. simular timeout e conferir mensagem, Tentar novamente e Fechar;
9. expirar a sessão e conferir orientação para voltar ao login;
10. conferir o retorno do foco ao cartão que abriu o modal.

Para `abel_crindiuba`, confirmar especificamente “Processo cancelado” no
histórico, ausência de painel/pendência financeira ativa e ausência de próxima
ação. Para os outros dois, confirmar ausência de processo ativo. Nos três, o
detalhe financeiro remoto só deve ser consultado após clique explícito.

## Processo e canal

Em um cliente sintético com processo ativo, confirmar que o painel mostra
situação, próxima pendência e Continuar processo. Esse botão deve abrir a
`resume_url` da etapa operacional, sem abrir o modal Financeiro.

Repetir a jornada selecionando Stable e Beta. No estado atual, ambos devem
informar que usam a mesma build e carregar os mesmos assets versionados. Não
interpretar a preferência como duas instalações independentes.

## Validação automatizada

Executar:

```bash
php tests/Feature/ClientModalPendingSmoke.php
php tests/Feature/OperationalProcessSmoke.php
php tests/Feature/UpgradeCorrectionSmoke.php
php tests/Feature/ThirdIterationSmoke.php
php tests/Feature/FourthIterationSmoke.php
php scripts/apply_migrations.php
node --check public/assets/js/app.js
git diff --check
```

Também executar `php -l` em todos os PHP alterados. A nova suíte cobre exatamente
28 categorias do hotfix.

## Resultado executado em 2026-08-04

Em execução local controlada:

- `ClientModalPendingSmoke`: 28 verificações aprovadas;
- `OperationalProcessSmoke`: 62 verificações aprovadas, com rollback;
- `UpgradeCorrectionSmoke`: 40 verificações aprovadas, com rollback;
- `ThirdIterationSmoke`: 40 verificações aprovadas;
- `FourthIterationSmoke`: 57 verificações aprovadas, com rollback;
- total: 227 verificações automatizadas aprovadas;
- migrações: 0 aplicadas, 11 já aplicadas, sem aviso ou erro.

Uma sessão HTTP temporária e explícita, removida ao fim da prova, confirmou para
`marquinhos_laranjeiras`, `abel_crindiuba` e `elisabete_capelinha`: detalhe com
HTTP 200, um único diálogo, diálogo inicialmente oculto, quatro templates, nenhum
painel de processo ativo e nenhum alerta de pendência financeira ativa. O
endpoint financeiro respondeu HTTP 200, `application/json`, JSON válido e
`ok=true` nos três casos; a maior duração observada foi 1,456 segundo. Sem sessão,
o endpoint respondeu com redirect HTTP 302 para login, como esperado.

No banco local, `abel_crindiuba` possui processo #25 cancelado, contrato #150 com
lifecycle cancelado e tarefa financeira cancelada; não há próxima pendência.
Os outros dois logins não possuem processo operacional local. Não havia cliente
real com processo ativo disponível nessa base: painel, próxima ação e retomada
foram cobertos pelo cenário sintético da suíte, e ainda exigem conferência manual
com um registro autorizado quando existir.

## Limitações e saída

O host não possui navegador automatizado. X/Esc/overlay, timeout visual, foco,
scroll e mobile ainda precisam de confirmação em navegador real; não declarar
essa validação como aprovada por inspeção de fonte. Android/Chrome e iOS/Safari
físicos continuam pendentes.

A saída deste hotfix libera somente a continuação da homologação. Push, merge,
deploy, topologia dual e qualquer integração externa exigem autorização separada.
