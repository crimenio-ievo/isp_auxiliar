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

## O que não existe e como contornar

| Falta | Contorno |
|---|---|
| **Pontos intermediários no cabo** (traçado curvo): o cabo é sempre uma reta entre os dois elementos; o servidor não grava o traçado. | Informe o **comprimento real** do cabo em *comprimento (m)*. A reta serve só como desenho. |
| **Reserva técnica** como recurso próprio (marca, soma automática). | Some a reserva ao comprimento informado e anote em *atributos* (`{"reserva_tecnica_m":"50"}`); o servidor guarda. |
| **Inserir caixa no meio do cabo** (sangria): botão desativado, "em breve". | Crie a caixa (CEO/CTO) como elemento e ligue os dois trechos. |
| **Distância acumulada até a OLT**: "ainda não calculada". | Some os trechos manualmente. |

## Distância ao mover pontos

- Cabo **sem** comprimento informado: a tela recalcula a linha reta a partir das coordenadas (acompanha o ponto
  movido; **lido no código**, não visto em tela).
- Cabo **com** comprimento informado: o valor fica fixo; não muda ao mover. Corrija à mão se mudar o trajeto.

## Aviso importante: onde ficam os dados

Tudo que você desenhar fica **no banco do servidor de teste (.89)** até o deploy. O .86 (produção) **não** recebe
nada sozinho. No deploy, as tabelas do mapa e dos vínculos (`map_nodes`, `map_ports`, `map_links`,
`client_network_bindings`, `client_network_binding_rejections`) têm de ser **copiadas** para o .86, **não
recriadas**: use o backup diário (`scripts/backup_map.sh`, arquivos em `/root/isp-auxiliar-backups/mapa/`).
Confira antes se os ids de provedor e de usuário do .86 batem com os do .89. Um backup novo é gerado a qualquer
momento rodando `scripts/backup_map.sh`.
