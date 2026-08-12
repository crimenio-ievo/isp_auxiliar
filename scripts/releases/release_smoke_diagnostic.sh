#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/common.sh"

TARGET=""
REPORT_FILE=""
EXPECTED_COUNT=16

usage() {
    echo "Uso: $0 --target RELEASE_DIR --report ARQUIVO_TSV [--expected-count TOTAL]"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --target) TARGET="$2"; shift ;;
        --report) REPORT_FILE="$2"; shift ;;
        --expected-count) EXPECTED_COUNT="$2"; shift ;;
        -h|--help) usage; exit 0 ;;
        *) usage; release_die "opção inválida: $1" ;;
    esac
    shift
done

[[ -n "${TARGET}" && -n "${REPORT_FILE}" ]] || { usage; exit 2; }
release_require_safe_dir "${TARGET}"
[[ -d "${TARGET}/tests/Feature" && -f "${TARGET}/.env" ]] \
    || release_die "release diagnóstica incompleta"
[[ "${REPORT_FILE}" == /* ]] || release_die "relatório diagnóstico deve usar caminho absoluto"
release_require_safe_dir "$(dirname "${REPORT_FILE}")"
[[ "${EXPECTED_COUNT}" =~ ^[1-9][0-9]*$ ]] || release_die "total esperado inválido"

smoke_root="$(mktemp -d /tmp/isp-auxiliar-release-smoke-diagnostic-XXXXXX)"
chmod 700 "${smoke_root}"
trap 'release_cleanup_smoke_sandbox "${smoke_root}"' EXIT
release_prepare_smoke_sandbox "${TARGET}" "${smoke_root}"

mapfile -t tests < <(find "${smoke_root}/tests/Feature" -maxdepth 1 -type f -name '*Smoke.php' | sort)
[[ ${#tests[@]} -eq ${EXPECTED_COUNT} ]] \
    || release_die "quantidade de Smokes divergente: esperado ${EXPECTED_COUNT}, encontrado ${#tests[@]}"

printf 'smoke\tresultado\tstatus\tverificacao\n' > "${REPORT_FILE}"
passed=0
failed=0

for test in "${tests[@]}"; do
    name="$(basename "${test}")"
    test_log="${smoke_root}/${name}.log"
    status=0
    if release_run_smoke_test "${test}" "${TARGET}/.env" > "${test_log}" 2>&1; then
        result=PASS
        passed=$((passed + 1))
    else
        status=$?
        result=FAIL
        failed=$((failed + 1))
    fi

    while IFS= read -r line || [[ -n "${line}" ]]; do
        printf '[%s] %s\n' "${name}" "${line}"
    done < "${test_log}"

    verification="$(tail -n 1 "${test_log}")"
    verification="${verification//$'\t'/ }"
    verification="${verification//$'\r'/ }"
    printf '%s\t%s\t%s\t%s\n' "${name}" "${result}" "${status}" "${verification}" >> "${REPORT_FILE}"
done

release_log "diagnóstico concluído: ${passed}/${EXPECTED_COUNT} PASS; ${failed}/${EXPECTED_COUNT} FAIL"
[[ ${failed} -eq 0 ]]
