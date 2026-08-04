#!/usr/bin/env bash

set -euo pipefail

DRY_RUN=false
CONFIRM_MIGRATIONS=false
RELEASE_LOG_FILE=""

release_usage_flags() {
    echo "Opções comuns: --dry-run --confirm-migrations"
}

release_log() {
    local line
    line="[$(date -u +%FT%TZ)] $*"
    printf '%s\n' "${line}"
    if [[ -n "${RELEASE_LOG_FILE}" && "${DRY_RUN}" == false ]]; then
        printf '%s\n' "${line}" >> "${RELEASE_LOG_FILE}"
    fi
}

release_set_log() {
    local target="$1"
    local channel="$2"
    RELEASE_LOG_FILE="${target}/logs/releases/${channel}.log"
    if [[ "${DRY_RUN}" == false ]]; then
        mkdir -p "$(dirname "${RELEASE_LOG_FILE}")"
        touch "${RELEASE_LOG_FILE}"
    fi
}

release_run() {
    release_log "$*"
    if [[ "${DRY_RUN}" == false ]]; then
        "$@"
    fi
}

release_require_safe_dir() {
    local target="$1"
    if [[ "${target}" != /* || "${target}" == "/" || "${target}" == "/var" || "${target}" == "/var/www" || "${target}" == "/var/www/html" ]]; then
        release_log "ERRO: diretório absoluto e específico obrigatório: ${target}"
        exit 2
    fi
}

release_require_checkout() {
    local target="$1"
    release_require_safe_dir "${target}"
    if [[ ! -d "${target}" ]] || ! git -C "${target}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        release_log "ERRO: checkout Git não localizado em ${target}"
        exit 2
    fi
}

release_require_commit() {
    local target="$1"
    local commit="$2"
    if [[ ! "${commit}" =~ ^[0-9a-fA-F]{7,40}$ ]] || ! git -C "${target}" cat-file -e "${commit}^{commit}" 2>/dev/null; then
        release_log "ERRO: commit não encontrado no checkout: ${commit}"
        exit 2
    fi
}

release_require_clean_tracked() {
    local target="$1"
    if [[ -n "$(git -C "${target}" status --short --untracked-files=no)" ]]; then
        release_log "ERRO: há alterações rastreadas em ${target}; operação abortada."
        exit 2
    fi
}

release_archive_current() {
    local target="$1"
    local channel="$2"
    local previous
    local stamp
    previous="$(git -C "${target}" rev-parse HEAD)"
    stamp="$(date -u +%Y%m%dT%H%M%SZ)"
    release_run mkdir -p "${target}/backups/releases"
    release_run git -C "${target}" branch "release-archive/${channel}-${stamp}" "${previous}"
    if [[ "${DRY_RUN}" == false ]]; then
        printf '%s\n' "${previous}" > "${target}/backups/releases/${channel}-${stamp}.commit"
    else
        release_log "registrar ${previous} em backups/releases/${channel}-${stamp}.commit"
    fi
}

release_apply_migrations_if_confirmed() {
    local target="$1"
    if [[ "${CONFIRM_MIGRATIONS}" == true ]]; then
        release_run php "${target}/scripts/apply_migrations.php"
    else
        release_log "migrations NÃO executadas; use --confirm-migrations somente após backup e revisão."
    fi
}
