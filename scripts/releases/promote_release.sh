#!/usr/bin/env bash

set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/common.sh"

CANDIDATE_RELEASE=""
RELEASE_ID=""
COMMIT=""
PRODUCTION_ENV_FILE=""
EXPECTED_SCHEMA=""
RELEASE_ROOT="/var/www/html/isp_auxiliar_production_releases"
CURRENT_LINK="/var/www/html/isp_auxiliar_stable_current"
SHARED_ROOT="/var/www/html/isp_auxiliar_shared"
BACKUP_ROOT="/var/backups/isp_auxiliar_releases"
LOG_ROOT="/var/log/isp_auxiliar"
HEALTH_URL=""
COOKIE_FILE=""

usage() {
    echo "Uso: $0 --candidate DIRETORIO --release-id ID --commit HASH_COMPLETO --production-env-file ARQUIVO --health-url URL [--expected-schema VERSAO] [--dry-run]"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --candidate) CANDIDATE_RELEASE="$2"; shift ;;
        --release-id) RELEASE_ID="$2"; shift ;;
        --commit) COMMIT="$2"; shift ;;
        --production-env-file) PRODUCTION_ENV_FILE="$2"; shift ;;
        --expected-schema) EXPECTED_SCHEMA="$2"; shift ;;
        --release-root) RELEASE_ROOT="$2"; shift ;;
        --current-link) CURRENT_LINK="$2"; shift ;;
        --shared-root) SHARED_ROOT="$2"; shift ;;
        --backup-root) BACKUP_ROOT="$2"; shift ;;
        --log-root) LOG_ROOT="$2"; shift ;;
        --health-url) HEALTH_URL="$2"; shift ;;
        --cookie-file) COOKIE_FILE="$2"; shift ;;
        --enable-real-operations) ENABLE_REAL_OPERATIONS=true ;;
        --confirm-real-operations) REAL_OPERATIONS_CONFIRMATION="$2"; shift ;;
        --dry-run) DRY_RUN=true ;;
        -h|--help) usage; exit 0 ;;
        *) usage; release_die "opção inválida: $1" ;;
    esac
    shift
done

[[ -n "${CANDIDATE_RELEASE}" && -n "${RELEASE_ID}" && -n "${COMMIT}" && -n "${PRODUCTION_ENV_FILE}" && -n "${HEALTH_URL}" ]] || { usage; exit 2; }
release_require_safe_dir "${CANDIDATE_RELEASE}"
release_require_safe_dir "${RELEASE_ROOT}"
release_require_safe_dir "${CURRENT_LINK}"
release_require_safe_dir "${SHARED_ROOT}"
release_require_safe_dir "${BACKUP_ROOT}"
release_require_release_id "${RELEASE_ID}"
release_require_https_url "${HEALTH_URL}"
[[ -d "${CANDIDATE_RELEASE}" ]] || release_die "candidata homologada não localizada"
[[ -f "${PRODUCTION_ENV_FILE}" ]] || release_die ".env de produção não localizado"
release_validate_manifest "${CANDIDATE_RELEASE}" "${RELEASE_ID}" "${COMMIT}"
release_validate_manifest_channel "${CANDIDATE_RELEASE}" candidate
release_require_safe_operations
# Mesmo lock/log do rollback_stable.sh (o symlink de produção continua chamado "stable_current"): promoção e rollback não rodam juntos.
release_set_log "${LOG_ROOT}" stable
release_acquire_lock stable

manifest_schema="$(sed -n 's/^schema_version=//p' "${CANDIDATE_RELEASE}/.release-manifest" | head -1)"
[[ -n "${EXPECTED_SCHEMA}" ]] || EXPECTED_SCHEMA="${manifest_schema}"
[[ -n "${EXPECTED_SCHEMA}" ]] || release_die "schema homologado não consta no manifesto; informe --expected-schema"
current_schema="$(release_schema_version "${CANDIDATE_RELEASE}")"
[[ "${current_schema}" == "${EXPECTED_SCHEMA}" ]] || release_die "schema atual diverge da candidata homologada"

PRODUCTION_RELEASE="${RELEASE_ROOT}/production/${RELEASE_ID}"
[[ ! -e "${PRODUCTION_RELEASE}" ]] || release_die "release de produção imutável já existe"
release_log "promovendo exatamente a candidata ${RELEASE_ID} no commit ${COMMIT}"
release_backup_current production "${CURRENT_LINK}" "${BACKUP_ROOT}" "${PRODUCTION_ENV_FILE}"
release_run_tests "${CANDIDATE_RELEASE}"
release_run install -d -m 755 "${RELEASE_ROOT}/production"
release_run cp -a "${CANDIDATE_RELEASE}" "${PRODUCTION_RELEASE}"
if [[ "${DRY_RUN}" == false ]]; then
    chmod -R u+w "${PRODUCTION_RELEASE}"
fi
release_prepare_env "${PRODUCTION_ENV_FILE}" "${PRODUCTION_RELEASE}/.env" production "${RELEASE_ID}" "${COMMIT}"
# O runtime (sessões, cache, logs) da produção continua em runtime/stable: evita deslogar todos na promoção.
release_prepare_storage_links "${PRODUCTION_RELEASE}" stable "${SHARED_ROOT}"
if [[ "${DRY_RUN}" == false ]]; then
    sed -i 's/^channel=candidate$/channel=production/' "${PRODUCTION_RELEASE}/.release-manifest"
    chmod 444 "${PRODUCTION_RELEASE}/.release-manifest"
fi
if [[ "${DRY_RUN}" == true ]]; then
    release_cli_health_check "${CANDIDATE_RELEASE}" production "${COMMIT}"
else
    release_cli_health_check "${PRODUCTION_RELEASE}" production "${COMMIT}"
fi
release_seal_immutable_code "${PRODUCTION_RELEASE}"
release_atomic_switch "${CURRENT_LINK}" "${PRODUCTION_RELEASE}"

health_args=(--url "${HEALTH_URL}" --expected-channel production --expected-commit "${COMMIT}")
[[ -n "${COOKIE_FILE}" ]] && health_args+=(--cookie-file "${COOKIE_FILE}")
[[ "${DRY_RUN}" == true ]] && health_args+=(--dry-run)
"${SCRIPT_DIR}/release_health_check.sh" "${health_args[@]}"
release_log "Promoção concluída com os arquivos homologados da candidata; a produção anterior foi preservada (rollback: rollback_stable.sh)."
