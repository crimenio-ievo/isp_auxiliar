# Fluxo Basico com Git

## Objetivo

Este guia resume o uso basico do Git no `isp_auxiliar` para manter o historico organizado e reduzir risco de sobrescrever trabalho em andamento.

## Comandos Principais

### `git status`

Mostra o estado atual do repositorio.

Use para verificar:
- arquivos modificados;
- arquivos novos;
- arquivos prontos para commit;
- arquivos nao rastreados.

Exemplo:

```bash
git status
```

### `git add`

Marca arquivos para o proximo commit.

Exemplos:

```bash
git add docs/git-fluxo-basico.md
git add .gitignore
```

Ou para adicionar tudo, com mais cuidado:

```bash
git add .
```

### `git commit`

Grava as alteracoes adicionadas no historico local.

Exemplo:

```bash
git commit -m "Atualiza fluxo basico do Git"
```

### `git pull`

Busca alteracoes do repositório remoto e tenta mesclar com o trabalho local.

Exemplo:

```bash
git pull origin main
```

Se houver conflito, revisar arquivo por arquivo antes de continuar.

### `git push`

Envia os commits locais para o repositório remoto.

Exemplo:

```bash
git push origin main
```

## Fluxo Recomendado

1. Verificar o estado atual com `git status`.
2. Revisar os arquivos alterados.
3. Adicionar apenas o que faz parte da tarefa com `git add`.
4. Criar um commit com mensagem clara.
5. Sincronizar com o remoto usando `git pull` antes de publicar.
6. Enviar para o remoto com `git push`.

## Como Atualizar Produção

Ao atualizar o servidor de producao:

1. Fazer backup antes de qualquer alteracao.
2. Entrar no diretorio do projeto.
3. Confirmar o branch correto.
4. Rodar `git pull`.
5. Verificar se houve conflito ou mudanca de arquivo sensivel.
6. Validar o sistema no navegador.
7. Conferir login, dashboard e cadastro.

Exemplo:

```bash
cd /var/www/html/isp_auxiliar
git status
git pull origin main
```

## Cuidados

- Nao commitar `.env`.
- Nao commitar arquivos com credenciais.
- Nao enviar arquivos de `storage/`, `logs/`, `tmp/` ou `backups/`.
- Antes de `push`, revisar o diff.
- Em caso de mudança estrutural, validar o sistema no navegador antes de publicar.
