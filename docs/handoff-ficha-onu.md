# Handoff — ficha da ONU, "Definir CTO", colunas Login/Bairro (2026-11-19)

Branch `feature/ficha-onu` (worktree `/var/www/html/_isp_auxiliar_ficha_onu`), a partir do `main` 8817131 (já com pré-produção). **Sem merge, sem push, sem migration.**
Nada na OLT, no MkAuth (só SELECT), no cron ou no .86. Legenda: **FATO** = verificado; **HIPÓTESE**; **NÃO VERIFICADO**.

## Etapa A (fechada)
Merge ff-only da pré-produção; cron do .89 trocado para `--all` (backup em `/root/isp-auxiliar-backups/isp-net-telemetria.cron.bak-*`). **FATO:** primeiro ciclo real depois da troca (19:33:01) `ok`, 0 falhas, 33 ONUs lidas.
Rotas: `/login` 200, `/mapa` e `/rede-*` 302, `/.env` e `/.git/HEAD` 403. **FATO:** `net_devices` tem só a OLT-001 (ativa, perfil Hioso suportado); nenhum equipamento novo (V-SOL/HA7308VX) cadastrado até 19:37.

## B1 — como se liga uma ONU a uma CTO/porta
**Antes (HOJE, no `main` até aqui):** só pelo CLIENTE, em duas telas. (1) `/rede-vinculos` vincula o cliente à ONU (sugestão pelo MAC ou "adicionar cliente"); (2) a CTO é escolhida na **ficha da CTO** (`/rede-ctos` → Ficha → porta livre → "Vincular cliente" → buscar o cliente no MkAuth → clicar nele),
que grava `cto_node_id/cto_port_id` na linha do cliente; com a 048, se esse cliente já tem ONU, a ONU inteira vai para a porta. Não havia caminho a partir da ONU, nem ajuda de proximidade.
**Agora:** na ficha da ONU em `/rede-fibra` e na seção "ONUs já vinculadas" de `/rede-vinculos`, o botão **Definir CTO** (ou **Trocar CTO**) abre a busca: sem texto lista as CTOs **mais próximas das coordenadas do cliente** (primeiro cliente da ONU com coordenada no MkAuth), com distância e portas livres;
com texto, busca por nome. Clicar numa porta livre pede confirmação ("Ligar/Mover a ONU (N clientes) para CTO, Porta X?"); se a ONU já está numa porta o servidor devolve 409 `mover` com a contagem. Regras da 048: **a ONU inteira vai** (todas as linhas), a porta só aceita a mesma ONU.
- **Porta livre** = porta de fibra de cliente, status livre, fora de qualquer ligação do mapa e sem vínculo ativo (defeito/em ligação/ocupada por outra ONU nunca aparecem; o servidor revalida e recusa).
- **Limite (FATO):** ONU **sem nenhum cliente vinculado** não pode ter CTO (a CTO fica na linha do vínculo); a tela orienta "vincule um cliente primeiro". Decisão minha, coerente com a 048.
- Código: `NetCtoPicker` (busca), `NetBindingController::nearbyCtos` / `bindOnuCto` (reusa `NetBindingRepository::bindCto`), seletor compartilhado `rede-cto-picker.js`. Auditoria `net.binding.onu.cto` (ONU, nº de clientes, linhas movidas).

## B2 — ficha da ONU com mais dados (somente leitura)
Bloco "Dados do cliente(s)" no painel: login, nome (link), **endereço curto** (rua, número · bairro · cidade), coordenadas com **"ver no mapa"** (`/mapa?lat=&lng=`: o mapa marca o ponto e centraliza), plano, **situação no MkAuth** (ativo/bloqueado/desativado), modo PPPoE e a CTO/porta que já existia.
Endpoint `GET /api/rede-vinculos/onu-ficha` (gestor). **FATO (privacidade):** o leitor `MkAuthDatabase::clientBriefByLogins` seleciona uma lista fechada de colunas; CPF/CNPJ, telefone e e-mail **não são lidos** e o teste prova que não saem (tabela fictícia com esses campos preenchidos). MkAuth fora do ar não derruba a ficha (`mkauth_ok=false`, nome cai para a foto do vínculo). Dados carregados depois da ficha (a ficha em si não depende do MkAuth).

## B3 — colunas Login e Bairro/Localidade (desligadas por padrão)
**Login** vem da própria árvore (sem MkAuth): primeiro login + "+N". **Bairro/Localidade** vem de `GET /api/rede-vinculos/clientes-resumo` (uma consulta ao MkAuth para todos os clientes com ONU), **só quando a coluna é ligada** (uma vez por página); mostra o bairro (ou a cidade se não houver) do primeiro cliente + "+N"; MkAuth fora = "indisponível" e a tela segue. Não são ordenáveis.

## B4 — testes
`NetFichaOnuSmoke` (34, schema descartável; inclui 4 consultas de privacidade, proximidade, portas livres, ONU inteira, 409/422/403/419, `/mapa?lat=`), jsdom `rede_fibra.js` (195) e `rede_vinculos.js` (60), suíte `tests/net/run_all.sh` (0 grupos com falha), jsdom do mapa e smokes de mapa/flags/roteamento.
**Um único** roteiro em navegador real, sem capturas: `node tests/browser/ficha_onu.js` (schema descartável + "MkAuth" fictício local + sessão de harness + `.env` temporário, todos removidos): 22 verificações, 0 falhas (colunas, ficha, ver no mapa, Definir CTO por proximidade/nome, Trocar CTO com 4 clientes, `/mapa?lat=`).
**NÃO VERIFICADO:** MkAuth real (só fictício e fake de teste); coordenadas reais fora do formato "lat,lng"; desempenho do resumo com milhares de vínculos (1 consulta `IN` em lotes de 200). Não re-rodei `vinculo_n_clientes.js` (o seeder do navegador ganhou o MkAuth fictício; não o afeta). Interface `ClientDirectory` ganhou `briefByLogins` (fakes de teste atualizados).

## PARA O USUÁRIO
1. Autorizar o merge ff-only de `feature/ficha-onu` no `main`.
2. Decidir se "ONU sem cliente" deve poder ter CTO (exigiria um registro de ONU↔porta fora do vínculo; hoje não).
