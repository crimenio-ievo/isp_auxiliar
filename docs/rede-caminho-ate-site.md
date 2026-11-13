# Caminho até o site — auditoria somente leitura

Consulta local em 13/11/2026, banco `isp_auxiliar`. Nenhum dado de topologia alterado.

## Fato observado

- Nós: 1 POP, 1 OLT e 2 CTOs. Nenhum rádio, splitter ou DIO cadastrado.
- Portas: 4 PON, 32 FO e 1 ETH. As duas CTOs têm ligação; zero splitter/DIO para avaliar.
- Ligações: 1 backbone/fibra e 2 distribuição/fibra.
- Ligações por id: 5, nó 14 → 8, sem portas; 10, nó 33 → 14, sem portas; 81, OLT 42 → CTO 14, portas 84 → 31. OLT 42 pertence ao POP 8 por `parent_node_id`.
- É possível caminhar por adjacência entre nós, mas isso não demonstra continuidade óptica: existem caminhos alternativos ao POP 8 e ligações sem porta definida. Não há cadeia CTO → splitter → DIO → PON → OLT completa no cadastro atual. Não há dado de emenda/fibra interna persistida que prove a passagem pelo splitter/DIO.
- Rádio é um tipo admitido no mapa. `map_links` suporta `wireless` e mídia derivada; nenhuma ligação real dessa modalidade foi encontrada neste banco. A representação real entre antenas não pôde ser validada.

## Proposta de API de leitura

`GET /api/mapa/caminho?no=ID&direcao=montante|jusante` (gestor/admin, isolamento por provedor), opcional `porta=ID`, limites de profundidade/quantidade e identificação de ciclos. O mapa permanece dono da topologia; telemetria somente acrescenta leituras quando houver vínculo explícito.

Resposta proposta: origem, direção, nós, portas, arestas, ramos/caminhos, ambiguidades e lacunas. Em cada nó: id/tipo/nome/site; em cada aresta: ids de portas, medium, tipo, sentido cadastrado e completude. Não escolher silenciosamente um caminho quando houver mais de um. Dependentes são os nós alcançáveis a jusante, com indicação de caminhos alternativos; não afirmar interrupção certa quando houver redundância.

Nível filho por tipo: OLT → PON (port_role=pon e port_num); rádio → antena ou setor quando houver atributo/porta explícito; sem informação, mostrar "a identificar". Splitter/DIO → portas físicas correspondentes. Não chamar interface de rádio de PON. ONU só é folha ligada à telemetria da PON; não inferir CTO ou cliente sem vínculo.

Montante/jusante requerem orientação de rede confiável. O sentido de cadastro de uma ligação não prova o fluxo físico; a API deve devolver hipóteses/ambiguidades enquanto não houver definição de raiz/site e passagem por portas. Parentesco físico de equipamento com POP complementa a adjacência, mas deve aparecer como relação de contenção, não como cabo.

## Hipótese e limites

A estrutura atual permite uma API de grafo para fibra e rádio com um contrato comum, mas não basta para calcular continuidade por fibra ou dependência exata do assinante. Faltam vínculos ONU→CTO, emendas/fibras internas, portas de trechos incompletos e dados reais de rádio. Nenhum percurso foi validado em campo. Não foi implementada API nesta auditoria.
