# Stable/Beta — estado real durante o hotfix de modal

Data: 2026-08-04.

## Constatação local

Stable e Beta ainda executam o mesmo checkout em
`/var/www/html/isp_auxiliar`. No ambiente inspecionado:

- `APP_RELEASE_CHANNEL=stable`;
- `APP_RELEASE_ID` e `APP_RELEASE_COMMIT` não estão preenchidos;
- `APP_STABLE_BASE_URL` e `APP_BETA_BASE_URL` não estão preenchidos;
- existe um único diretório de assets e o mesmo cache;
- o seletor grava preferência local auditada, mas não redireciona neste estado.

Portanto, não existem duas releases independentes. A interface agora declara o
canal selecionado e, sem dois destinos distintos, informa que Beta ainda utiliza
a mesma build de Stable. Se URLs válidas e diferentes forem configuradas no
futuro, a interface informa somente que a troca usa destinos configurados; isso
não equivale por si só a homologar duas instalações.

## Cache de assets

O sufixo fixo anterior foi removido. `app.css` e `app.js` usam a mesma versão,
resolvida nesta ordem:

1. `APP_RELEASE_ID`;
2. `APP_RELEASE_COMMIT`;
3. maior `filemtime` dos dois assets como fallback local/de implantação.

Não é gerado timestamp novo a cada requisição. Quando houver releases separadas,
cada checkout poderá fornecer seu próprio ID/commit e, consequentemente, seu
próprio cache busting.

## Limite

Este hotfix não criou diretórios Stable/Beta, não alterou servidor, não promoveu
release e não configurou hosts. A fundação continua sendo apenas configuração,
permissão, preferência e auditoria.
