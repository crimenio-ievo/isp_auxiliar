# Deploy futuro da Beta

Não executar este procedimento nesta homologação.

O script `scripts/releases/deploy_beta.sh` recebe checkout, hash completo, release ID, `.env` e URL de health. Ele:

1. valida caminhos, release ID e commit exato;
2. adquire lock e inicia log protegido;
3. extrai o commit para uma release nova e imutável;
4. prepara `.env` Beta sem imprimir segredos;
5. conecta storage permanente compartilhado e runtime exclusivo Beta;
6. instala dependências, quando existentes;
7. audita migrations pendentes;
8. cria backup e dump transacional;
9. aplica somente migrations explicitamente confirmadas;
10. executa todos os smokes e health CLI;
11. sela o código como somente leitura;
12. troca somente `isp_auxiliar_beta_current` por symlink atômico;
13. preserva a release anterior e executa health HTTP.

Exemplo sem alteração:

```bash
scripts/releases/deploy_beta.sh \
  --source /caminho/checkout \
  --commit HASH_COMPLETO \
  --release-id beta-AAAA.MM.N \
  --env-file /caminho/seguro/beta.env \
  --health-url https://beta.exemplo.invalid \
  --dry-run
```

Em uma rede interna que use HTTP por IP e não possua TLS válido para esse IP,
somente o deploy/rollback Beta pode receber `--allow-http-health`; a URL deve
ser `http://` e a opção nunca flexibiliza os fluxos Stable.

Por padrão, MkAuth, notificações, chamados e IA ficam bloqueados. Operações reais exigem `--enable-real-operations` e a confirmação literal adicional documentada pelo `--help`.

## Base pública em Alias ou symlink

Quando o Apache expõe uma release por `Alias` ou symlink e o `SCRIPT_NAME` PHP
passa a refletir o caminho físico, configure `APP_BASE_PATH` somente com o
caminho público, por exemplo `/isp_auxiliar/public`. O valor não aceita URL,
host, query string, fragmento nem segmentos `..`; vazio mantém a autodetecção
legada.
