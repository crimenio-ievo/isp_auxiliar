# Implantação isolada de Stable e Beta

## Estado observado

- candidato local da Stable operacional: `/var/www/html/isp_auxiliar_producao`,
  commit `58a08d0fc50681944104aa18cf9cc49f924f974f`, `APP_ENV=production`;
- desenvolvimento Beta: `/var/www/html/isp_auxiliar`, branch
  `feature/migracao-operacional-beta`, base `94db2346528434a17aa38faccd4127a9953d2e97`;
- o Apache deste host anuncia `teste.ievo.com.br` com `DocumentRoot /var/www/html`;
- a URL pública observada da instalação candidata é `https://ispaux.ievo.com.br`;
- a ligação entre esse endpoint público e o checkout local não é demonstrável
  criptograficamente pela configuração Apache disponível neste host.

A Stable candidata possui alterações operacionais locais preexistentes. Ela foi
somente inspecionada e não deve ser usada como alvo de automação antes de backup
e conciliação da árvore.

## Topologia preparada

Primeira instalação recomendada:

```text
/var/www/html/isp_auxiliar          checkout Stable mantido no caminho vigente
/var/www/html/isp_auxiliar_beta     clone/check-out Beta independente
```

Como o vhost atual expõe a raiz inteira, um vhost/subdomínio Beta separado é mais
seguro do que ampliar aliases no mesmo host. O template está em
`deploy/apache/isp_auxiliar-beta.conf.example`; DNS, certificado e habilitação
continuam pendentes da infraestrutura. Nenhum vhost foi habilitado.

Stable e Beta devem ter `.env`, DB local, cookie, `SESSION_PATH`, código, storage,
logs, tmp, assets, release ID e commit próprios. O template Beta mantém escrita
MkAuth desabilitada, mensagens e chamados em dry-run e usa banco
`isp_auxiliar_beta`. Leituras do MkAuth dependem de usuário somente leitura
autorizado.

## Release info

`GET /api/health` continua público e mínimo. `GET /api/release` exige sessão e
perfil administrador e retorna channel, release ID, commit, build date, versão
do schema e flags de escrita/dry-run, sem credenciais. Cookie e diretório de
sessão são aplicados antes de `session_start()`. O seletor usa exclusivamente as
duas URLs configuradas, registra auditoria e mostra a build real.

## Segurança de configuração

`storage/contracts/config.json` foi removido do índice e ignorado, sem excluir a
cópia local. Há um exemplo sanitizado. O histórico contém versões anteriores do
arquivo, portanto rotação de SMTP/Evotrix e invalidação de tokens são bloqueadores
de produção. A rotação e eventual reescrita de histórico não foram executadas.
