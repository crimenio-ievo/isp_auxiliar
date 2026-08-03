# Quarta iteração — fundação Stable e Beta

Esta rodada prepara configuração, autorização, auditoria e interface. Não cria
dois diretórios, não altera servidor e não ativa produção dual.

## Configuração

```dotenv
APP_RELEASE_CHANNEL=stable
APP_STABLE_BASE_URL=
APP_BETA_BASE_URL=
APP_RELEASE_ID=
APP_RELEASE_COMMIT=
```

URLs são lidas somente da configuração. O service aceita apenas HTTP/HTTPS com
host, rejeita credenciais embutidas e nunca recebe URL do formulário. Stable é
o padrão. Beta exige gestor/admin ou login presente em
`beta_release_access_logins`.

A preferência é salva por usuário em `provider_settings`, com chave derivada de
hash do login, e cada alteração gera `release.channel.preference_changed` em
auditoria. A preferência é restaurada na sessão após login. O seletor permite
voltar a Stable. Quando uma URL válida está configurada, a alternância pode
redirecionar somente para esse destino controlado.

O redirecionamento automático imediatamente após login não foi ativado: a
topologia ainda não possui autenticação compartilhada homologada, e forçar isso
poderia causar novo login ou loop entre hosts. Esta é a opção segura prevista
para a fundação desta rodada.

## Identificação visual

Uma instância Stable não mostra banner Beta. Uma instância com
`APP_RELEASE_CHANNEL=beta` mostra permanentemente “AMBIENTE BETA — Recursos em
homologação. Verifique os dados antes de concluir operações.” O texto não depende
somente de cor e fica antes do header sem cobrir a interface.

## Topologia futura

Alvo recomendado:

```text
/var/www/html/isp_auxiliar_stable
/var/www/html/isp_auxiliar_beta
```

ou releases imutáveis com symlinks separados. Cada canal deve ter diretório,
cache, temporários, `.env`, release ID e commit próprios. Storage persistente só
pode ser compartilhado após análise explícita.

## Compatibilidade de banco

Compartilhar banco exige migrations aditivas, leitura compatível por Stable e
barreiras de escrita externa. Esta iteração não adiciona migration: a preferência
reutiliza `provider_settings`. Processos, aceites, contratos, instalação e
assinatura avulsa mantêm formatos existentes.

## Promoção e rollback

Fluxo: Desenvolvimento → Beta → homologação → aprovação → Stable.

Antes de promover: snapshot de banco/storage, release ID/commit, migrations
aditivas aplicadas com runner canônico e smokes com integrações bloqueadas. Para
rollback: apontar o symlink ao release Stable anterior, restaurar somente dados
quando houver plano de rollback compatível e manter auditoria/evidências. Nenhuma
dessas ações foi executada nesta entrega.
