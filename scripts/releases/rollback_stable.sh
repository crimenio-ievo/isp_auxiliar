#!/usr/bin/env bash

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/common.sh"

STABLE_DIR="/var/www/html/isp_auxiliar"
COMMIT=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run) DRY_RUN=true ;;
        --dir) STABLE_DIR="$2"; shift ;;
        --commit) COMMIT="$2"; shift ;;
        *) echo "Uso: $0 --commit HASH_ANTERIOR [--dir CAMINHO] [--dry-run]"; exit 2 ;;
    esac
    shift
done

release_require_checkout "${STABLE_DIR}"
release_require_clean_tracked "${STABLE_DIR}"
release_set_log "${STABLE_DIR}" stable
[[ -n "${COMMIT}" ]] || { release_log "ERRO: --commit anterior é obrigatório."; exit 2; }
release_require_commit "${STABLE_DIR}" "${COMMIT}"
release_archive_current "${STABLE_DIR}" stable-before-rollback
release_run git -C "${STABLE_DIR}" checkout --detach "${COMMIT}"
release_log "Rollback de código concluído. Banco e segredos não foram alterados automaticamente."
