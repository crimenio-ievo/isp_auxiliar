#!/usr/bin/env bash

set -Eeuo pipefail

BASE_URL=""
COOKIE_FILE=""
EXPECTED_CHANNEL=""
EXPECTED_COMMIT=""
DRY_RUN=false

usage() {
    echo "Uso: $0 --url URL [--cookie-file COOKIE_ADMIN] [--expected-channel stable|beta] [--expected-commit HASH] [--dry-run]"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --url) BASE_URL="${2%/}"; shift ;;
        --cookie-file) COOKIE_FILE="$2"; shift ;;
        --expected-channel) EXPECTED_CHANNEL="$2"; shift ;;
        --expected-commit) EXPECTED_COMMIT="$2"; shift ;;
        --dry-run) DRY_RUN=true ;;
        -h|--help) usage; exit 0 ;;
        *) usage; exit 2 ;;
    esac
    shift
done

[[ "${BASE_URL}" =~ ^https://[^[:space:]]+$ ]] || { echo "ERRO: URL HTTPS obrigatória." >&2; exit 2; }
[[ -z "${EXPECTED_CHANNEL}" || "${EXPECTED_CHANNEL}" =~ ^(stable|beta)$ ]] || { echo "ERRO: canal esperado inválido." >&2; exit 2; }
[[ -z "${EXPECTED_COMMIT}" || "${EXPECTED_COMMIT}" =~ ^[0-9a-fA-F]{40}$ ]] || { echo "ERRO: commit esperado inválido." >&2; exit 2; }
if [[ -n "${COOKIE_FILE}" ]]; then
    [[ -f "${COOKIE_FILE}" ]] || { echo "ERRO: cookie administrativo não localizado." >&2; exit 2; }
    [[ "$(stat -c '%a' "${COOKIE_FILE}")" =~ ^[0-6]00$ ]] || { echo "ERRO: proteja o arquivo de cookie com modo 600." >&2; exit 2; }
fi

if [[ "${DRY_RUN}" == true ]]; then
    echo "dry-run: seriam consultados /api/health e, com cookie administrativo, /api/release em ${BASE_URL}."
    exit 0
fi

health_file="$(mktemp)"
release_file="$(mktemp)"
trap 'rm -f "${health_file}" "${release_file}"' EXIT
chmod 600 "${health_file}" "${release_file}"

curl --fail --silent --show-error --location --max-time 20 \
    --header 'Accept: application/json' "${BASE_URL}/api/health" > "${health_file}"
php -r '$d=json_decode((string)file_get_contents($argv[1]),true); if(!is_array($d)||($d["status"]??"")!=="ok") exit(2);' "${health_file}" \
    || { echo "ERRO: health da aplicação inválido." >&2; exit 1; }
echo "health=ok"

if [[ -z "${COOKIE_FILE}" ]]; then
    echo "release=nao_verificada_sem_cookie_admin"
    exit 0
fi

curl --fail --silent --show-error --location --max-time 20 --cookie "${COOKIE_FILE}" \
    --header 'Accept: application/json' "${BASE_URL}/api/release" > "${release_file}"
php -r '
    $d=json_decode((string)file_get_contents($argv[1]),true);
    if(!is_array($d)||($d["status"]??"")!=="ok") exit(2);
    if($argv[2]!=="" && ($d["channel"]??"")!==$argv[2]) exit(3);
    if($argv[3]!=="" && !hash_equals(strtolower($argv[3]),strtolower((string)($d["commit"]??"")))) exit(4);
    foreach(["schema_version","external_writes_enabled","notification_dry_run","ticket_dry_run"] as $key){if(!array_key_exists($key,$d))exit(5);}
    if(($d["external_writes_enabled"]??true)!==false)exit(6);
' "${release_file}" "${EXPECTED_CHANNEL}" "${EXPECTED_COMMIT}" \
    || { echo "ERRO: metadados administrativos da release divergentes." >&2; exit 1; }
echo "release=ok"
