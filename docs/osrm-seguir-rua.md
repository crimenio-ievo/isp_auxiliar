# "Seguir rua" no /mapa: serviço de rotas (OSRM)

## Como funciona
No editor de traçado, *Seguir rua ao clicar* e *Seguir rua até o destino* pedem a rota ao **servidor do ISP_Auxiliar**
(`POST /api/mapa/rota`, só gestor + CSRF), que consulta um OSRM configurado e devolve pontos simplificados (máx. 400).
Os pontos viram pontos comuns do cabo, editáveis. Só coordenadas saem do servidor, nunca dado de cliente.
**Sem serviço configurado, ou se ele falhar, a tela segue no modo manual** (o clique vira um ponto simples).

## Servidor público: não usar de forma contínua (fato, wiki do OSRM lida em 2026-11-19)
`router.project-osrm.org` (FOSSGIS): "Do not exceed 1 request per second", uso "restricted to reasonable,
non-commercial use-cases", "no guarantees wrt. uptime, latency, or data updates". Um provedor de internet é uso
comercial, então **ele não é o padrão**: a URL vem vazia. Perfis do demo diferem dos padrões do osrm-backend.
Por isso o caminho recomendado é uma instância própria.

## Configuração (provider_settings, por provedor)
| Chave | Valor |
|---|---|
| `map.routing.osrm_url` | ex.: `http://127.0.0.1:5000` (só http/https; vazio = desligado) |
| `map.routing.profile` | `driving` (padrão), `car`, `bike`, `foot`... conforme o perfil do seu OSRM |

Exemplo (executar só quando decidir; ajuste `provider_id`):
`INSERT INTO provider_settings (provider_id, setting_key, setting_value, updated_at) VALUES (1,'map.routing.osrm_url','http://127.0.0.1:5000',NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW();`

## Instalar OSRM próprio com o recorte de Minas Gerais no .89 (NÃO executado; peça autorização antes)
**Não verificado nesta máquina:** espaço em disco/RAM e se o Docker está instalado. O recorte de MG exige poucos GB
de RAM no pré-processamento; confira antes. Ordem sugerida (Docker, imagem oficial `osrm/osrm-backend`):
1. Baixar o recorte (Geofabrik tem Sudeste; MG pode ser extraído dele com `osmium extract` e um polígono de MG, ou usar
   `southeast-latest.osm.pbf` inteiro, que é maior): `https://download.geofabrik.de/south-america/brazil/sudeste.html`.
2. Pré-processar (uma vez; perfil de carro serve para ruas, mas veja a nota abaixo):
   `docker run -t -v /srv/osrm:/data osrm/osrm-backend osrm-extract -p /opt/car.lua /data/sudeste-latest.osm.pbf`
   `docker run -t -v /srv/osrm:/data osrm/osrm-backend osrm-partition /data/sudeste-latest.osrm`
   `docker run -t -v /srv/osrm:/data osrm/osrm-backend osrm-customize /data/sudeste-latest.osrm`
3. Subir só no loopback: `docker run -d --restart unless-stopped -p 127.0.0.1:5000:5000 -v /srv/osrm:/data osrm/osrm-backend osrm-routed --algorithm mld /data/sudeste-latest.osrm`
4. Testar: `curl 'http://127.0.0.1:5000/route/v1/driving/-42.80,-20.85;-42.81,-20.86?overview=false'` deve devolver `"code":"Ok"`.
5. Gravar a URL na chave acima.
Notas: o perfil de carro respeita sentidos de mão única, que **não** valem para cabo; o traçado é só guia, e você ajusta
os pontos. Atualizar o mapa = repetir os passos 1–3 (ex.: a cada alguns meses). Não exponha a porta 5000 na rede.
