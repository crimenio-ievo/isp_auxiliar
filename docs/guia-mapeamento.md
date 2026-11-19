# Guia de mapeamento no /mapa (1 página)

Para quem vai desenhar a rede no `/mapa` em vez de importar KMZ. Verificado em 2026-11-19 pelo teste
`tests/Feature/MapMapeamentoFlowSmoke.php` (usa as mesmas regras do servidor do `/mapa`, em schema descartável).
A interface foi conferida **só lendo o código**, sem navegador real (**não verificado** em tela).

## Ordem recomendada

1. **Site (POP)**: *Adicionar → POP*, com coordenadas. Torres também são sites e podem ficar dentro do POP.
2. **OLT**: dentro do site (*Adicionar equipamento aqui dentro*), informando as portas PON (e SFP/ETH). A OLT
   herda a posição do site; **equipamento não tem coordenada própria**.
3. **CTOs**: *Adicionar → CTO*, com coordenadas e **capacidade** (gera "Porta 1…N"). Dá para colar "lat, lng"
   ou usar `/rede-ctos` (cadastro rápido, geolocalização e importação CSV).
4. **Cabos**: arraste do conector do elemento (ou *Conectar*) até o destino e escolha o tipo (distribuição, drop…),
   fibras e portas: PON da OLT → CTO-A, depois CTO-A → CTO-B. Um segundo cabo igual entre os mesmos elementos é
   recusado.
5. **Splitters**: no painel da CTO, *Adicionar splitter* (1x2 … 1x64): gera 1 entrada e N saídas.

## O que funciona (testado)

Criar, editar, mover e excluir site, OLT, CTO e splitter; portas geradas por capacidade/razão; cabos com portas,
fibras e comprimento; bloqueios de exclusão (CTO com cabo/filho, POP com equipamento), com mensagem; mover uma CTO
não solta os cabos.

## Traçado do cabo, reservas e distância (feature/mapa-tracado)

- **Traçado:** cabo existente → painel do trecho → *Editar traçado*. Clique no mapa para acrescentar pontos ao fim;
  arraste um ponto para movê-lo; clique ou arraste o "+" de um segmento para criar um ponto no meio; duplo clique
  ou botão direito num ponto o remove (ou clique no ponto e *Remover ponto selecionado*). Nada é gravado até
  *Salvar traçado*; *Cancelar* ou Esc descarta. Cabo novo: no modal *Novo trecho*, *Desenhar traçado no mapa*.
  Cabos antigos (sem traçado) continuam como linha reta.
- **Pontos intermediários só:** as pontas são sempre os dois elementos; mover uma CTO leva a ponta do cabo junto, e
  os pontos do meio ficam onde estão.
- **Distância:** total = traçado + folga + reservas, mostrados em separado no painel do trecho.
  - *Traçado*: soma das distâncias (haversine) ponta A → pontos → ponta B.
  - *Folga %*: sobre o traçado (cobre curvas e flechas); por cabo (campo *Folga*), ou o padrão da rede
    (`provider_settings` chave `map.cable.slack_pct`; sem valor = 0 %). **Não** incide sobre as reservas.
  - *Reservas técnicas*: lista no cabo (local + metros), somada ao total (editor no *Editar trecho*).
  - *Comprimento manual*: se preenchido em *Editar trecho*, **prevalece** e aparece marcado "manual"; o calculado
    continua visível ao lado. Apague para voltar ao calculado.
- **Seguir rua:** no editor de traçado, liga um modo em que cada clique busca a rota pelas ruas e a transforma em pontos
  editáveis. Depende de um serviço OSRM configurado (`docs/osrm-seguir-rua.md`); sem ele, a tela segue no modo manual.
- **Onde fica gravado:** coluna `map_links.path_json` (já existia desde a migration 019; **sem migration nova**).

## O que ainda não existe e como contornar

| Falta | Contorno |
|---|---|
| **Inserir caixa no meio do cabo** (sangria): botão desativado, "em breve". | Crie a caixa (CEO/CTO) como elemento e ligue os dois trechos. |
| **Distância acumulada até a OLT**: "ainda não calculada". | Some os trechos manualmente. |

## Aviso importante: onde ficam os dados

Tudo que você desenhar fica **no banco do servidor de teste (.89)** até o deploy. O .86 (produção) **não** recebe
nada sozinho. No deploy, as tabelas do mapa e dos vínculos (`map_nodes`, `map_ports`, `map_links`,
`client_network_bindings`, `client_network_binding_rejections`) têm de ser **copiadas** para o .86, **não
recriadas**: use o backup diário (`scripts/backup_map.sh`, arquivos em `/root/isp-auxiliar-backups/mapa/`).
Confira antes se os ids de provedor e de usuário do .86 batem com os do .89. Um backup novo é gerado a qualquer
momento rodando `scripts/backup_map.sh`.
