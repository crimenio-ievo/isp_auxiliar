#!/usr/bin/env bash

set -euo pipefail

BASE_URL=""
COOKIE_FILE=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --url) BASE_URL="${2%/}"; shift ;;
        --cookie-file) COOKIE_FILE="$2"; shift ;;
        *) echo "Uso: $0 --url URL [--cookie-file ARQUIVO_DE_COOKIE_AUTENTICADO]"; exit 2 ;;
    esac
    shift
done

[[ "${BASE_URL}" =~ ^https://[^[:space:]]+$ ]] || { echo "ERRO: URL HTTPS obrigatória."; exit 2; }
curl --fail --silent --show-error --location --max-time 15 "${BASE_URL}/api/health"
printf '\n'
if [[ -n "${COOKIE_FILE}" ]]; then
    [[ -f "${COOKIE_FILE}" ]] || { echo "ERRO: arquivo de cookie não localizado."; exit 2; }
    curl --fail --silent --show-error --location --max-time 15 --cookie "${COOKIE_FILE}" "${BASE_URL}/api/release"
    printf '\n'
else
    echo "Release info não consultada: forneça cookie administrativo autenticado."
fi
