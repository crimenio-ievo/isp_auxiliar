#!/usr/bin/env bash
set -u
set -o pipefail
cd "$(dirname "$0")/../.." || exit 1
if [[ ! -d tests/map/node_modules/jsdom ]]; then
  echo 'Instale as dependências: cd tests/map && npm ci'
  exit 1
fi
passed=0
failed=0
run() {
  local output status
  echo "=== $* ==="
  output=$("$@" 2>&1)
  status=$?
  printf '%s\n' "$output"
  # Os harnesses antigos imprimiam falhas mas sempre retornavam zero.
  if (( status != 0 )) || printf '%s\n' "$output" | grep -Eq '\[FALHA\]|HOUVE FALHAS|falhas: [1-9]'; then
    ((failed+=1))
  elif printf '%s\n' "$output" | grep -E 'erros( console)?:' | grep -qvE 'erros( console)?: nenhum'; then
    ((failed+=1))
  else
    ((passed+=1))
  fi
}
for suite in tests/map/suite*.php; do run php "$suite"; done
html=modules/isp_map/isp-network-map.html
for mode in empty one session forbidden; do run node tests/map/js/run.js "$html" "$mode"; done
for suite in coords flow life links name site; do run node "tests/map/js/$suite.js" "$html"; done
for suite in b c dd e; do run node "tests/map/$suite.js"; done
echo "RESUMO: $passed passaram; $failed falharam."
(( failed == 0 ))
