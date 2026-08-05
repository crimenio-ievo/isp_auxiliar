#!/usr/bin/env bash

set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/common.sh"

SOURCE_DIR=""
COMMIT=""
RELEASE_ID=""
ENV_FILE=""
RELEASE_ROOT="/var/www/html/isp_auxiliar_releases"
CURRENT_LINK="/var/www/html/isp_auxiliar_beta_current"
SHARED_ROOT="/var/www/html/isp_auxiliar_shared"
BACKUP_ROOT="/var/backups/isp_auxiliar_releases"
LOG_ROOT="/var/log/isp_auxiliar"
HEALTH_URL=""
COOKIE_FILE=""

usage() {
    echo "Uso: $0 --source CHECKOUT --commit HASH_COMPLETO --release-id ID --env-file ARQUIVO --health-url URL [--dry-run] [--confirm-migrations] [--enable-real-operations --confirm-real-operations EU_CONFIRM_REAL_OPERATIONS]"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --source) SOURCE_DIR="$2"; shift ;;
        --commit) COMMIT="$2"; shift ;;
        --release-id) RELEASE_ID="$2"; shift ;;
        --env-file) ENV_FILE="$2"; shift ;;
        --release-root) RELEASE_ROOT="$2"; shift ;;
        --current-link) CURRENT_LINK="$2"; shift ;;
        --shared-root) SHARED_ROOT="$2"; shift ;;
        --backup-root) BACKUP_ROOT="$2"; shift ;;
        --log-root) LOG_ROOT="$2"; shift ;;
        --health-url) HEALTH_URL="$2"; shift ;;
        --cookie-file) COOKIE_FILE="$2"; shift ;;
        --confirm-migrations) CONFIRM_MIGRATIONS=true ;;
        --enable-real-operations) ENABLE_REAL_OPERATIONS=true ;;
        --confirm-real-operations) REAL_OPERATIONS_CONFIRMATION="$2"; shift ;;
        --dry-run) DRY_RUN=true ;;
        -h|--help) usage; exit 0 ;;
        *) usage; release_die "opção inválida: $1" ;;
    esac
    shift
done

[[ -n "${SOURCE_DIR}" && -n "${COMMIT}" && -n "${RELEASE_ID}" && -n "${ENV_FILE}" && -n "${HEALTH_URL}" ]] || { usage; exit 2; }
release_require_checkout "${SOURCE_DIR}"
release_require_commit "${SOURCE_DIR}" "${COMMIT}"
release_require_release_id "${RELEASE_ID}"
release_require_safe_dir "${RELEASE_ROOT}"
release_require_safe_dir "${CURRENT_LINK}"
release_require_safe_dir "${SHARED_ROOT}"
release_require_safe_dir "${BACKUP_ROOT}"
release_require_https_url "${HEALTH_URL}"
[[ -f "${ENV_FILE}" ]] || release_die "arquivo .env Beta não localizado"
[[ "${CURRENT_LINK}" != *stable* ]] || release_die "deploy Beta recebeu symlink da Stable"
release_require_safe_operations
release_set_log "${LOG_ROOT}" beta
release_acquire_lock beta

RELEASE_DIR="${RELEASE_ROOT}/beta/${RELEASE_ID}"
[[ ! -e "${RELEASE_DIR}" ]] || release_die "a release imutável já existe: ${RELEASE_DIR}"

release_log "preparando Beta ${RELEASE_ID} no commit ${COMMIT}"
release_run install -d -m 755 "${RELEASE_ROOT}/beta"
release_run install -d -m 755 "${RELEASE_DIR}"
if [[ "${DRY_RUN}" == false ]]; then
    git -C "${SOURCE_DIR}" archive --format=tar "${COMMIT}" | tar -xf - -C "${RELEASE_DIR}"
fi
release_prepare_env "${ENV_FILE}" "${RELEASE_DIR}/.env" beta "${RELEASE_ID}" "${COMMIT}"
release_prepare_storage_links "${RELEASE_DIR}" beta "${SHARED_ROOT}" "${SOURCE_DIR}"

if [[ -f "${SOURCE_DIR}/composer.json" ]]; then
    release_require_command composer
    release_run composer install --working-dir="${RELEASE_DIR}" --no-dev --prefer-dist --no-interaction --optimize-autoloader
fi

if [[ "${DRY_RUN}" == false ]]; then
    printf 'channel=beta\nrelease_id=%s\ncommit=%s\n' "${RELEASE_ID}" "${COMMIT}" > "${RELEASE_DIR}/.release-manifest"
fi

VALIDATION_DIR="${RELEASE_DIR}"
[[ "${DRY_RUN}" == true ]] && VALIDATION_DIR="${SOURCE_DIR}"
release_audit_migrations "${VALIDATION_DIR}"
if [[ "${DRY_RUN}" == true ]]; then
    release_log "dry-run: auditoria real da cópia usou o checkout fonte"
else
    release_backup_current beta "${CURRENT_LINK}" "${BACKUP_ROOT}" "${ENV_FILE}"
    release_apply_migrations "${RELEASE_DIR}"
    schema_version="$(release_schema_version "${RELEASE_DIR}")"
    printf 'schema_version=%s\n' "${schema_version}" >> "${RELEASE_DIR}/.release-manifest"
    chmod 444 "${RELEASE_DIR}/.release-manifest"
fi

release_run_tests "${VALIDATION_DIR}"
release_cli_health_check "${VALIDATION_DIR}" beta "${COMMIT}"
release_seal_immutable_code "${RELEASE_DIR}"
release_atomic_switch "${CURRENT_LINK}" "${RELEASE_DIR}"

health_args=(--url "${HEALTH_URL}" --expected-channel beta --expected-commit "${COMMIT}")
[[ -n "${COOKIE_FILE}" ]] && health_args+=(--cookie-file "${COOKIE_FILE}")
[[ "${DRY_RUN}" == true ]] && health_args+=(--dry-run)
"${SCRIPT_DIR}/release_health_check.sh" "${health_args[@]}"
release_log "Beta ${RELEASE_ID} preparada; a Stable não foi alterada e a release anterior foi preservada."
