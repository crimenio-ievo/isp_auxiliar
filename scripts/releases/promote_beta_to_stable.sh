#!/usr/bin/env bash

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/common.sh"

STABLE_DIR=""
COMMIT=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run) DRY_RUN=true ;;
        --confirm-migrations) CONFIRM_MIGRATIONS=true ;;
        --dir) STABLE_DIR="$2"; shift ;;
        --commit) COMMIT="$2"; shift ;;
        *) echo "Uso: $0 --dir CAMINHO_STABLE_CONFIRMADO --commit HASH_BETA_HOMOLOGADO [--dry-run] [--confirm-migrations]"; exit 2 ;;
    esac
    shift
done

[[ -n "${STABLE_DIR}" ]] || { echo "ERRO: --dir da Stable confirmada é obrigatório." >&2; exit 2; }
[[ -n "${COMMIT}" ]] || { release_log "ERRO: --commit homologado é obrigatório."; exit 2; }
release_require_checkout "${STABLE_DIR}"
release_require_clean_tracked "${STABLE_DIR}"
release_set_log "${STABLE_DIR}" stable
release_run git -C "${STABLE_DIR}" fetch --prune origin
release_require_commit "${STABLE_DIR}" "${COMMIT}"
release_archive_current "${STABLE_DIR}" stable
release_run git -C "${STABLE_DIR}" checkout --detach "${COMMIT}"
release_apply_migrations_if_confirmed "${STABLE_DIR}"
release_log "Promoção de código concluída. Atualize APP_RELEASE_* e execute os health checks antes de liberar tráfego."
