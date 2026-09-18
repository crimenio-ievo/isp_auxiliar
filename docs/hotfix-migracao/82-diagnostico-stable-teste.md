# Diagnóstico da Stable de testes

Data: 2026-08-06. Diretório: `/var/www/html/isp_auxiliar_stable`.

## Resultado

A falha relatada não foi reproduzida no estado recebido. A configuração operacional já presente apontava a URL oficial para o `public/` correto, habilitava `.htaccess` e isolava cookie, caminho e storage de sessão. Como não havia erro atual, nenhuma alteração de código, migration, tag, commit, Apache ou `.env` foi aplicada à Stable.

Não é possível afirmar uma causa histórica sem log contemporâneo. O cenário é compatível com configuração externa de roteamento/sessão que já havia sido corrigida antes desta retomada; registrar uma causa mais específica seria especulação.

## Evidências

- URL oficial: `https://teste.ievo.com.br/isp_auxiliar_stable/public/`;
- raiz oficial e acesso por IP: HTTP 302 para o login;
- login, CSS e JavaScript: HTTP 200;
- dashboard, pesquisa, detalhe e contratos com sessão temporária: HTTP 200;
- logout: HTTP 302; após logout, dashboard Stable volta a 302;
- sessão Beta permaneceu autenticada ao encerrar a Stable;
- POST real do seletor Beta: HTTP 302 para `https://teste.ievo.com.br/isp_auxiliar_stable/public`;
- certificado validado localmente para `teste.ievo.com.br`;
- nenhum erro Apache relacionado foi observado.

Validação Git autorizada para a tag anotada:

```bash
git rev-parse 'stable-prod-2026-08-04^{commit}'
# 73a95b2e6adae4435d23f94cb9b4304c3317e017
```

O checkout permaneceu detached no mesmo commit, com arquivos versionados limpos. A tag não foi recriada, alterada ou forçada. Nenhuma migration foi executada na Stable e a Stable hotfix não foi usada.
