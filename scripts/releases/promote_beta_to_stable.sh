#!/usr/bin/env bash

set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/common.sh"

BETA_RELEASE=""
RELEASE_ID=""
COMMIT=""
STABLE_ENV_FILE=""
EXPECTED_SCHEMA=""
RELEASE_ROOT="/var/www/html/isp_auxiliar_releases"
CURRENT_LINK="/var/www/html/isp_auxiliar_stable_current"
SHARED_ROOT="/var/www/html/isp_auxiliar_shared"
BACKUP_ROOT="/var/backups/isp_auxiliar_releases"
LOG_ROOT="/var/log/isp_auxiliar"
HEALTH_URL=""
COOKIE_FILE=""

usage() {
    echo "Uso: $0 --beta-release DIRETORIO --release-id ID --commit HASH_COMPLETO --stable-env-file ARQUIVO --health-url URL [--expected-schema VERSAO] [--dry-run]"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --beta-release) BETA_RELEASE="$2"; shift ;;
        --release-id) RELEASE_ID="$2"; shift ;;
        --commit) COMMIT="$2"; shift ;;
        --stable-env-file) STABLE_ENV_FILE="$2"; shift ;;
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

[[ -n "${BETA_RELEASE}" && -n "${RELEASE_ID}" && -n "${COMMIT}" && -n "${STABLE_ENV_FILE}" && -n "${HEALTH_URL}" ]] || { usage; exit 2; }
release_require_safe_dir "${BETA_RELEASE}"
release_require_safe_dir "${RELEASE_ROOT}"
release_require_safe_dir "${CURRENT_LINK}"
release_require_safe_dir "${SHARED_ROOT}"
release_require_safe_dir "${BACKUP_ROOT}"
release_require_release_id "${RELEASE_ID}"
release_require_https_url "${HEALTH_URL}"
[[ -d "${BETA_RELEASE}" ]] || release_die "release Beta homologada não localizada"
[[ -f "${STABLE_ENV_FILE}" ]] || release_die ".env Stable não localizado"
release_validate_manifest "${BETA_RELEASE}" "${RELEASE_ID}" "${COMMIT}"
release_validate_manifest_channel "${BETA_RELEASE}" beta
release_require_safe_operations
release_set_log "${LOG_ROOT}" stable
release_acquire_lock stable

manifest_schema="$(sed -n 's/^schema_version=//p' "${BETA_RELEASE}/.release-manifest" | head -1)"
[[ -n "${EXPECTED_SCHEMA}" ]] || EXPECTED_SCHEMA="${manifest_schema}"
[[ -n "${EXPECTED_SCHEMA}" ]] || release_die "schema homologado não consta no manifesto; informe --expected-schema"
current_schema="$(release_schema_version "${BETA_RELEASE}")"
[[ "${current_schema}" == "${EXPECTED_SCHEMA}" ]] || release_die "schema atual diverge da Beta homologada"

STABLE_RELEASE="${RELEASE_ROOT}/stable/${RELEASE_ID}"
[[ ! -e "${STABLE_RELEASE}" ]] || release_die "release Stable imutável já existe"
release_log "promovendo exatamente a Beta ${RELEASE_ID} no commit ${COMMIT}"
release_backup_current stable "${CURRENT_LINK}" "${BACKUP_ROOT}" "${STABLE_ENV_FILE}"
release_run_tests "${BETA_RELEASE}"
release_run install -d -m 755 "${RELEASE_ROOT}/stable"
release_run cp -a "${BETA_RELEASE}" "${STABLE_RELEASE}"
if [[ "${DRY_RUN}" == false ]]; then
    chmod -R u+w "${STABLE_RELEASE}"
fi
release_prepare_env "${STABLE_ENV_FILE}" "${STABLE_RELEASE}/.env" stable "${RELEASE_ID}" "${COMMIT}"
release_prepare_storage_links "${STABLE_RELEASE}" stable "${SHARED_ROOT}"
if [[ "${DRY_RUN}" == false ]]; then
    sed -i 's/^channel=beta$/channel=stable/' "${STABLE_RELEASE}/.release-manifest"
    chmod 444 "${STABLE_RELEASE}/.release-manifest"
fi
if [[ "${DRY_RUN}" == true ]]; then
    release_cli_health_check "${BETA_RELEASE}" stable "${COMMIT}"
else
    release_cli_health_check "${STABLE_RELEASE}" stable "${COMMIT}"
fi
release_seal_immutable_code "${STABLE_RELEASE}"
release_atomic_switch "${CURRENT_LINK}" "${STABLE_RELEASE}"

health_args=(--url "${HEALTH_URL}" --expected-channel stable --expected-commit "${COMMIT}")
[[ -n "${COOKIE_FILE}" ]] && health_args+=(--cookie-file "${COOKIE_FILE}")
[[ "${DRY_RUN}" == true ]] && health_args+=(--dry-run)
"${SCRIPT_DIR}/release_health_check.sh" "${health_args[@]}"
release_log "Promoção concluída com os arquivos homologados da Beta; a Stable anterior foi preservada."
