## Storage

Arquivos gerados em tempo de execucao devem ficar aqui.

Subpastas iniciais:

- `cache/`
- `installations/`
- `sessions/`
- `uploads/`

Dados operacionais atuais:

- `uploads/clientes/<referencia>/`: fotos, assinatura e `aceite.json`.
- `installations/<token>.json`: checkpoint da validação Radius após cadastro.

Em produção, esta pasta precisa entrar na rotina de backup.
