#!/usr/bin/env bash

set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/common.sh"

TARGET_RELEASE=""
RELEASE_ID=""
COMMIT=""
RELEASE_ROOT="/var/www/html/isp_auxiliar_production_releases"
CURRENT_LINK="/var/www/html/isp_auxiliar_stable_current"
BACKUP_ROOT="/var/backups/isp_auxiliar_releases"
LOG_ROOT="/var/log/isp_auxiliar"
HEALTH_URL=""
COOKIE_FILE=""

usage() {
    echo "Uso: $0 --release-id ID --commit HASH_COMPLETO --health-url URL [--to-release DIRETORIO] [--dry-run]"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --to-release) TARGET_RELEASE="$2"; shift ;;
        --release-id) RELEASE_ID="$2"; shift ;;
        --commit) COMMIT="$2"; shift ;;
        --release-root) RELEASE_ROOT="$2"; shift ;;
        --current-link) CURRENT_LINK="$2"; shift ;;
        --backup-root) BACKUP_ROOT="$2"; shift ;;
        --log-root) LOG_ROOT="$2"; shift ;;
        --health-url) HEALTH_URL="$2"; shift ;;
        --cookie-file) COOKIE_FILE="$2"; shift ;;
        --dry-run) DRY_RUN=true ;;
        -h|--help) usage; exit 0 ;;
        *) usage; release_die "opção inválida: $1" ;;
    esac
    shift
done

[[ -n "${RELEASE_ID}" && -n "${COMMIT}" && -n "${HEALTH_URL}" ]] || { usage; exit 2; }
if [[ -z "${TARGET_RELEASE}" && ( -e "${CURRENT_LINK}_previous" || -L "${CURRENT_LINK}_previous" ) ]]; then
    TARGET_RELEASE="$(readlink -f "${CURRENT_LINK}_previous" 2>/dev/null || true)"
fi
[[ -n "${TARGET_RELEASE}" ]] || release_die "release de produção anterior não localizada"
release_require_safe_dir "${TARGET_RELEASE}"
release_require_safe_dir "${RELEASE_ROOT}"
release_require_safe_dir "${CURRENT_LINK}"
release_require_safe_dir "${BACKUP_ROOT}"
release_require_release_id "${RELEASE_ID}"
release_require_https_url "${HEALTH_URL}"
# Alvo: qualquer release imutável dentro de RELEASE_ROOT (inclui as promovidas em production/ e as
# anteriores ao processo candidata->produção, como pre-maps-v0-*), com manifesto channel=production|stable.
case "$(readlink -f "${TARGET_RELEASE}")" in
    "$(readlink -m "${RELEASE_ROOT}")"/*) ;;
    *) release_die "release alvo não pertence a ${RELEASE_ROOT} (canal de produção)" ;;
esac
release_validate_manifest "${TARGET_RELEASE}" "${RELEASE_ID}" "${COMMIT}"
release_validate_manifest_channel_any "${TARGET_RELEASE}" production stable
release_set_log "${LOG_ROOT}" stable
release_acquire_lock stable

release_backup_current stable "${CURRENT_LINK}" "${BACKUP_ROOT}" "${TARGET_RELEASE}/.env"
release_audit_migrations "${TARGET_RELEASE}"
release_run_tests "${TARGET_RELEASE}"
release_cli_health_check "${TARGET_RELEASE}" "$(release_manifest_channel "${TARGET_RELEASE}")" "${COMMIT}"
release_atomic_switch "${CURRENT_LINK}" "${TARGET_RELEASE}"

health_args=(--url "${HEALTH_URL}" --expected-channel "$(release_manifest_channel "${TARGET_RELEASE}")" --expected-commit "${COMMIT}")
[[ -n "${COOKIE_FILE}" ]] && health_args+=(--cookie-file "${COOKIE_FILE}")
[[ "${DRY_RUN}" == true ]] && health_args+=(--dry-run)
"${SCRIPT_DIR}/release_health_check.sh" "${health_args[@]}"
release_log "Rollback de produção concluído. O banco não foi revertido automaticamente; a release substituída foi preservada."
