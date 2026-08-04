#!/usr/bin/env bash

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/common.sh"

BETA_DIR="/var/www/html/isp_auxiliar_beta"
REPOSITORY=""
COMMIT=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run) DRY_RUN=true ;;
        --confirm-migrations) CONFIRM_MIGRATIONS=true ;;
        --dir) BETA_DIR="$2"; shift ;;
        --repository) REPOSITORY="$2"; shift ;;
        --commit) COMMIT="$2"; shift ;;
        *) echo "Uso: $0 --repository URL_OU_PATH --commit HASH [--dir CAMINHO] [--dry-run] [--confirm-migrations]"; exit 2 ;;
    esac
    shift
done

release_require_safe_dir "${BETA_DIR}"
[[ -n "${REPOSITORY}" && -n "${COMMIT}" ]] || { release_log "ERRO: --repository e --commit são obrigatórios."; exit 2; }
[[ ! -e "${BETA_DIR}" ]] || { release_log "ERRO: ${BETA_DIR} já existe; nada foi sobrescrito."; exit 2; }

release_run git clone --no-hardlinks "${REPOSITORY}" "${BETA_DIR}"
if [[ "${DRY_RUN}" == false ]]; then
    release_require_commit "${BETA_DIR}" "${COMMIT}"
fi
release_run git -C "${BETA_DIR}" checkout --detach "${COMMIT}"
release_set_log "${BETA_DIR}" beta
release_run cp "${BETA_DIR}/.env.beta.example" "${BETA_DIR}/.env"
release_run mkdir -p "${BETA_DIR}/storage/sessions-beta" "${BETA_DIR}/storage/uploads-beta" "${BETA_DIR}/storage/cache-beta" "${BETA_DIR}/logs/beta" "${BETA_DIR}/tmp/beta" "${BETA_DIR}/backups/releases"
release_apply_migrations_if_confirmed "${BETA_DIR}"
release_log "Instalação Beta preparada. Edite o .env e valide /api/health e /api/release antes de habilitar o vhost."
