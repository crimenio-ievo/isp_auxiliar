# Promoção futura Beta para Stable

Não promover nesta execução.

`promote_beta_to_stable.sh` exige o diretório imutável da Beta homologada, o mesmo release ID, o mesmo hash completo, o schema confirmado e o `.env` separado da Stable. O manifesto impede selecionar outra build.

O script faz backup, executa os testes sobre a Beta aprovada, copia exatamente seus arquivos para a árvore Stable, altera apenas a configuração de canal/runtime, valida health e troca `isp_auxiliar_stable_current` atomicamente. A Stable anterior é preservada.

Não há rebuild, merge implícito, checkout de outro commit ou migration durante a promoção.

## Nova Beta após promoção

1. A Beta aprovada torna-se Stable.
2. `main` recebe exatamente o mesmo commit por processo Git autorizado separado.
3. Uma nova branch Beta nasce da nova Stable.
4. A nova Beta é implantada no canal e runtime Beta.
5. A Stable permanece na release já aprovada.

