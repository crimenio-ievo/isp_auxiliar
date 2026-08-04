# Novo cliente — validação do plano no MkAuth

## Evidência e causa

Foi localizado um snapshot de 2026-07-07 no qual a condição escolhida era
`FibraRural_100mbps` (UUID `0EAE777C-C1F2-431D-9486-980E96C8E5FC`), mas a
releitura final indicava `RadioRural_10mbps`. O checkpoint conservou a mensagem
“Cliente editado com sucesso”. Um caso de controle do mesmo período terminou no
plano esperado. Foram conferidos snapshots, checkpoints, logs locais, guard de
escrita, tratamento de exceções e estado final disponível; dados pessoais não
são reproduzidos neste documento.

A causa operacional confirmada é a ausência de conferência pós-escrita, somada a
um tratamento genérico que considerava a mera existência do cliente como
recuperação bem-sucedida. Os logs antigos não preservaram request/response de
baixo nível suficientes para provar por que o MkAuth ignorou ou não persistiu a
primeira alteração.

## Hotfix Stable isolado

- Stable operacional identificada: `58a08d0fc50681944104aa18cf9cc49f924f974f`;
- worktree: `/var/www/html/isp_auxiliar_stable_hotfix`;
- branch: `hotfix/stable-validacao-plano-novo-cliente`;
- código: `cb86f46e2066a9e5bdd863b77c800f774caa3d9c`;
- teste: `3c73d385d8689594ec55c4c21b70b152b48b6de1`.

O provisionamento resolve UUID/nome oficial, cria o cliente, relê o estado direto
do MkAuth e compara o plano. Se divergir, aplica correção por UUID e relê. Timeout
após criação é recuperado por leitura; retry com UUID/request ID não repete POST.
Falha parcial preserva o identificador e jamais mostra sucesso total.

Aplicação futura, somente após autorização:

```bash
git cherry-pick cb86f46e2066a9e5bdd863b77c800f774caa3d9c 3c73d385d8689594ec55c4c21b70b152b48b6de1
```

Rollback por novos commits:

```bash
git revert 3c73d385d8689594ec55c4c21b70b152b48b6de1 cb86f46e2066a9e5bdd863b77c800f774caa3d9c
```

Sem o hotfix, a Stable pode continuar declarando sucesso mesmo com plano final
divergente. O worktree Stable não recebeu deploy, merge ou push.
