#!/usr/bin/env bash
# Suite da tela "Rede de fibra": PHP (leitor, controller, rotas, menu, pagina) + jsdom. Usa schema descartavel local.
set -u
cd "$(dirname "$0")/../.." || exit 1
if [[ ! -d tests/map/node_modules/jsdom ]]; then echo 'Instale as dependencias: cd tests/map && npm ci'; exit 1; fi
failed=0
for cmd in "php tests/Feature/NetOutageReaderSmoke.php" "php tests/Feature/NetFiberReaderSmoke.php" "php tests/Feature/NetDeviceCadastroSmoke.php" "php tests/Feature/NetTickAllSmoke.php" "node tests/net/rede_fibra.js" "node tests/net/global_network_search.js" "node tests/net/rede_equipamentos.js" "php tests/Feature/NetCtoSmoke.php" "node tests/net/rede_ctos.js" "php tests/Feature/NetBindingSmoke.php" "node tests/net/rede_vinculos.js"; do
  echo "=== $cmd ==="
  out=$($cmd 2>&1); status=$?
  printf '%s\n' "$out"
  if (( status != 0 )) || printf '%s\n' "$out" | grep -Eq '\[FALHA\]|falhas: [1-9]'; then ((failed+=1)); fi
done
echo "RESUMO: $failed grupo(s) com falha."
(( failed == 0 ))
