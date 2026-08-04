#!/usr/bin/env bash

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/common.sh"

BETA_DIR="/var/www/html/isp_auxiliar_beta"
COMMIT=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run) DRY_RUN=true ;;
        --dir) BETA_DIR="$2"; shift ;;
        --commit) COMMIT="$2"; shift ;;
        *) echo "Uso: $0 --commit HASH_ANTERIOR [--dir CAMINHO] [--dry-run]"; exit 2 ;;
    esac
    shift
done

release_require_checkout "${BETA_DIR}"
release_require_clean_tracked "${BETA_DIR}"
release_set_log "${BETA_DIR}" beta
[[ -n "${COMMIT}" ]] || { release_log "ERRO: --commit anterior é obrigatório."; exit 2; }
release_require_commit "${BETA_DIR}" "${COMMIT}"
release_archive_current "${BETA_DIR}" beta-before-rollback
release_run git -C "${BETA_DIR}" checkout --detach "${COMMIT}"
release_log "Rollback de código concluído. Banco não foi revertido; restaure o backup separado se a matriz exigir."
