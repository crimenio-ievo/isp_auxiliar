#!/usr/bin/env bash

set -Eeuo pipefail

DRY_RUN=false
CONFIRM_MIGRATIONS=false
ENABLE_REAL_OPERATIONS=false
REAL_OPERATIONS_CONFIRMATION=""
RELEASE_LOG_FILE=""
RELEASE_LOCK_FD=9
RELEASE_COMMON_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

release_log() {
    local line
    line="[$(date -u +%FT%TZ)] $*"
    printf '%s\n' "${line}"
    if [[ -n "${RELEASE_LOG_FILE}" && "${DRY_RUN}" == false ]]; then
        printf '%s\n' "${line}" >> "${RELEASE_LOG_FILE}"
    fi
}

release_die() {
    release_log "ERRO: $*"
    exit 2
}

release_run() {
    release_log "$*"
    if [[ "${DRY_RUN}" == false ]]; then
        "$@"
    fi
}

release_require_command() {
    command -v "$1" >/dev/null 2>&1 || release_die "comando obrigatório indisponível: $1"
}

release_require_safe_dir() {
    local target="${1:-}"
    [[ "${target}" == /* ]] || release_die "diretório absoluto obrigatório: ${target}"
    case "${target}" in
        /|/var|/var/www|/var/www/html|/etc|/tmp) release_die "diretório amplo ou inseguro: ${target}" ;;
    esac
}

release_require_checkout() {
    local target="$1"
    release_require_safe_dir "${target}"
    [[ -d "${target}" ]] || release_die "checkout não localizado: ${target}"
    git -C "${target}" rev-parse --is-inside-work-tree >/dev/null 2>&1 || release_die "Git não localizado em ${target}"
}

release_require_commit() {
    local target="$1"
    local commit="$2"
    [[ "${commit}" =~ ^[0-9a-fA-F]{40}$ ]] || release_die "hash completo de 40 caracteres obrigatório"
    git -C "${target}" cat-file -e "${commit}^{commit}" 2>/dev/null || release_die "commit não localizado no checkout"
    local resolved
    resolved="$(git -C "${target}" rev-parse "${commit}^{commit}")"
    [[ "${resolved}" == "${commit,,}" ]] || release_die "commit resolvido não corresponde ao hash solicitado"
}

release_require_release_id() {
    [[ "${1:-}" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{2,79}$ ]] || release_die "release ID inválido"
}

release_require_https_url() {
    [[ "${1:-}" =~ ^https://[^[:space:]]+$ ]] || release_die "URL HTTPS obrigatória"
}

release_set_log() {
    local log_root="$1"
    local channel="$2"
    release_require_safe_dir "${log_root}"
    RELEASE_LOG_FILE="${log_root}/${channel}-releases.log"
    if [[ "${DRY_RUN}" == false ]]; then
        install -d -m 700 "${log_root}"
        touch "${RELEASE_LOG_FILE}"
        chmod 600 "${RELEASE_LOG_FILE}"
    fi
}

release_acquire_lock() {
    local name="$1"
    local lock_file="/var/lock/isp-auxiliar-${name}.lock"
    if [[ "${DRY_RUN}" == true ]]; then
        release_log "dry-run: lock exclusivo seria adquirido em ${lock_file}"
        return
    fi
    eval "exec ${RELEASE_LOCK_FD}>\"${lock_file}\""
    flock -n "${RELEASE_LOCK_FD}" || release_die "outra operação de release está em andamento"
}

release_require_safe_operations() {
    if [[ "${ENABLE_REAL_OPERATIONS}" == true ]]; then
        [[ "${REAL_OPERATIONS_CONFIRMATION}" == "EU_CONFIRM_REAL_OPERATIONS" ]] \
            || release_die "--enable-real-operations exige --confirm-real-operations EU_CONFIRM_REAL_OPERATIONS"
        release_log "ATENÇÃO: operações reais foram explicitamente solicitadas para a configuração desta release."
    fi
}

release_write_env_setting() {
    local env_file="$1"
    local key="$2"
    local value="$3"
    if grep -qE "^${key}=" "${env_file}"; then
        sed -i -E "s#^${key}=.*#${key}=${value}#" "${env_file}"
    else
        printf '%s=%s\n' "${key}" "${value}" >> "${env_file}"
    fi
}

release_prepare_env() {
    local source_env="$1"
    local target_env="$2"
    local channel="$3"
    local release_id="$4"
    local commit="$5"
    [[ -f "${source_env}" ]] || release_die "arquivo de ambiente não localizado"
    if [[ "${DRY_RUN}" == true ]]; then
        release_log "dry-run: preparar .env ${channel} para release ${release_id}, sem imprimir segredos"
        return
    fi
    install -m 600 "${source_env}" "${target_env}"
    release_write_env_setting "${target_env}" APP_RELEASE_CHANNEL "${channel}"
    release_write_env_setting "${target_env}" APP_RELEASE_ID "${release_id}"
    release_write_env_setting "${target_env}" APP_RELEASE_COMMIT "${commit}"
    release_write_env_setting "${target_env}" APP_RELEASE_BUILD_DATE "$(date -u +%FT%TZ)"
    release_write_env_setting "${target_env}" AI_ENABLED false
    release_write_env_setting "${target_env}" AI_ACTIONS_ENABLED false
    release_write_env_setting "${target_env}" AI_REAL_CALLS_ENABLED false
    if [[ "${ENABLE_REAL_OPERATIONS}" == false ]]; then
        release_write_env_setting "${target_env}" MKAUTH_WRITE_ENABLED false
        release_write_env_setting "${target_env}" EVOTRIX_DRY_RUN true
        release_write_env_setting "${target_env}" EMAIL_DRY_RUN true
        release_write_env_setting "${target_env}" MKAUTH_TICKET_DRY_RUN true
    fi
}

release_pending_migrations() {
    local target="$1"
    php -r '
        require $argv[1] . "/backend/bootstrap/app.php";
        $app = bootstrapApplication();
        $db = new App\Infrastructure\Database\Database($app->config());
        $rows = $db->fetchAll("SELECT COALESCE(NULLIF(filename, \"\"), version) AS filename FROM schema_migrations");
        $applied = array_fill_keys(array_filter(array_map(static fn(array $r): string => basename((string)($r["filename"] ?? "")), $rows)), true);
        foreach (glob($argv[1] . "/database/migrations/*.sql") ?: [] as $file) {
            if (!isset($applied[basename($file)])) echo $file, PHP_EOL;
        }
    ' "${target}"
}

release_migration_is_compatible() {
    local file="$1"
    php "${RELEASE_COMMON_DIR}/migration_auditor.php" "${file}"
}

release_audit_migrations() {
    local target="$1"
    local pending=()
    mapfile -t pending < <(release_pending_migrations "${target}")
    if [[ ${#pending[@]} -eq 0 ]]; then
        release_log "auditoria de migrations: nenhuma migration pendente"
        return
    fi
    local file
    for file in "${pending[@]}"; do
        [[ -f "${file}" ]] || release_die "migration pendente não localizada"
        if ! release_migration_is_compatible "${file}"; then
            release_die "migration pendente incompatível detectada: $(basename "${file}")"
        fi
        release_log "migration pendente classificada como aditiva para revisão: $(basename "${file}")"
    done
    [[ "${CONFIRM_MIGRATIONS}" == true ]] || release_die "há migrations pendentes; use --confirm-migrations após revisão e backup"
}

release_apply_migrations() {
    local target="$1"
    local pending=()
    mapfile -t pending < <(release_pending_migrations "${target}")
    [[ ${#pending[@]} -gt 0 ]] || return
    [[ "${CONFIRM_MIGRATIONS}" == true ]] || release_die "migrations pendentes sem confirmação"
    release_run php "${target}/scripts/apply_migrations.php"
}

release_backup_database() {
    local target="$1"
    local backup_file="$2"
    if [[ "${DRY_RUN}" == true ]]; then
        release_log "dry-run: dump transacional seria salvo em ${backup_file}"
        return
    fi
    release_require_command mysqldump
    local db_host db_port db_name db_user db_password
    db_host="$(php -r '$v=parse_ini_file($argv[1],false,INI_SCANNER_RAW)?:[];echo $v["DB_HOST"]??"127.0.0.1";' "${target}/.env")"
    db_port="$(php -r '$v=parse_ini_file($argv[1],false,INI_SCANNER_RAW)?:[];echo $v["DB_PORT"]??"3306";' "${target}/.env")"
    db_name="$(php -r '$v=parse_ini_file($argv[1],false,INI_SCANNER_RAW)?:[];echo $v["DB_DATABASE"]??"";' "${target}/.env")"
    db_user="$(php -r '$v=parse_ini_file($argv[1],false,INI_SCANNER_RAW)?:[];echo $v["DB_USERNAME"]??"";' "${target}/.env")"
    db_password="$(php -r '$v=parse_ini_file($argv[1],false,INI_SCANNER_RAW)?:[];echo $v["DB_PASSWORD"]??"";' "${target}/.env")"
    [[ -n "${db_name}" && -n "${db_user}" ]] || release_die "configuração do banco incompleta"
    MYSQL_PWD="${db_password}" mysqldump --host="${db_host}" --port="${db_port}" --user="${db_user}" \
        --single-transaction --quick --routines --triggers --events "${db_name}" | gzip -9 > "${backup_file}"
    chmod 600 "${backup_file}"
    unset db_password MYSQL_PWD
    gzip -t "${backup_file}" || release_die "dump de banco inválido"
}

release_backup_current() {
    local channel="$1"
    local current_link="$2"
    local backup_root="$3"
    local env_source="$4"
    local stamp backup_dir current_target
    stamp="$(date -u +%Y%m%dT%H%M%SZ)"
    backup_dir="${backup_root}/${stamp}/${channel}"
    release_require_safe_dir "${backup_root}"
    if [[ "${DRY_RUN}" == true ]]; then
        release_log "dry-run: backup do canal ${channel} seria criado em ${backup_dir}"
        return
    fi
    install -d -m 700 "${backup_dir}"
    current_target=""
    if [[ -e "${current_link}" || -L "${current_link}" ]]; then
        current_target="$(readlink -f "${current_link}" 2>/dev/null || true)"
    fi
    printf '%s\n' "${current_target}" > "${backup_dir}/previous-release-path.txt"
    chmod 600 "${backup_dir}/previous-release-path.txt"
    [[ -f "${env_source}" ]] && install -m 600 "${env_source}" "${backup_dir}/channel.env"
    if [[ -n "${current_target}" && -f "${current_target}/.env" ]]; then
        release_backup_database "${current_target}" "${backup_dir}/database.sql.gz"
    elif [[ -f "${env_source}" ]]; then
        local temporary_dir
        temporary_dir="$(mktemp -d)"
        install -m 600 "${env_source}" "${temporary_dir}/.env"
        release_backup_database "${temporary_dir}" "${backup_dir}/database.sql.gz"
        rm -f "${temporary_dir}/.env"
        rmdir "${temporary_dir}"
    fi
}

release_run_tests() {
    local target="$1"
    local validation_target="${target}"
    local smoke_root=""
    local status=0

    if [[ "${DRY_RUN}" == false ]]; then
        smoke_root="$(mktemp -d /tmp/isp-auxiliar-release-smoke-XXXXXX)"
        chmod 700 "${smoke_root}"
        trap 'release_cleanup_smoke_sandbox "${smoke_root}"' RETURN
        release_prepare_smoke_sandbox "${target}" "${smoke_root}"
        validation_target="${smoke_root}"
    fi

    local test
    while IFS= read -r test; do
        if release_run_smoke_test "${test}"; then
            continue
        else
            status=$?
            break
        fi
    done < <(find "${validation_target}/tests/Feature" -maxdepth 1 -type f -name '*Smoke.php' | sort)

    if [[ -n "${smoke_root}" ]]; then
        release_cleanup_smoke_sandbox "${smoke_root}"
        smoke_root=""
        trap - RETURN
    fi

    return "${status}"
}

release_prepare_smoke_sandbox() {
    local source="$1"
    local sandbox="$2"
    release_require_safe_dir "${source}"
    release_require_safe_dir "${sandbox}"
    release_require_command tar
    release_log "preparando sandbox efêmero e local para os Smoke Tests"

    tar \
        --exclude='./.env' \
        --exclude='./storage/contracts' \
        --exclude='./storage/uploads' \
        --exclude='./storage/installations' \
        --exclude='./storage/sessions' \
        --exclude='./storage/cache' \
        --exclude='./logs' \
        --exclude='./tmp' \
        -C "${source}" -cf - . | tar -C "${sandbox}" -xf -

    install -d -m 700 \
        "${sandbox}/storage/contracts" \
        "${sandbox}/storage/uploads" \
        "${sandbox}/storage/installations" \
        "${sandbox}/storage/sessions" \
        "${sandbox}/storage/cache" \
        "${sandbox}/logs" \
        "${sandbox}/tmp"
    [[ -f "${source}/.env" ]] && install -m 600 "${source}/.env" "${sandbox}/.env"

    local config_file
    for config_file in config.json config.example.json; do
        if [[ -f "${source}/storage/contracts/${config_file}" ]]; then
            install -m 600 \
                "${source}/storage/contracts/${config_file}" \
                "${sandbox}/storage/contracts/${config_file}"
        fi
    done
}

release_cleanup_smoke_sandbox() {
    local sandbox="${1:-}"
    [[ "${sandbox}" == /tmp/isp-auxiliar-release-smoke-* ]] \
        || release_die "sandbox Smoke fora do padrão seguro"
    [[ -d "${sandbox}" ]] || return
    rm -rf -- "${sandbox}"
}

release_run_smoke_test() {
    local test="$1"
    release_log "smoke isolado: php ${test}"
    if [[ "${DRY_RUN}" == false ]]; then
        env \
            APP_ENV=test \
            MKAUTH_WRITE_ENABLED=false \
            EVOTRIX_DRY_RUN=true \
            EMAIL_DRY_RUN=true \
            MKAUTH_TICKET_DRY_RUN=true \
            AI_ENABLED=false \
            AI_ACTIONS_ENABLED=false \
            AI_REAL_CALLS_ENABLED=false \
            php "${test}"
    fi
}

release_prepare_storage_links() {
    local release_dir="$1"
    local channel="$2"
    local shared_root="$3"
    local seed_root="${4:-}"
    release_require_safe_dir "${release_dir}"
    release_require_safe_dir "${shared_root}"
    local persistent=(contracts uploads installations)
    local runtime=(sessions cache)
    local name
    for name in "${persistent[@]}"; do
        if [[ ! -d "${shared_root}/permanent/${name}" && -n "${seed_root}" && -d "${seed_root}/storage/${name}" ]]; then
            release_run install -d -m 770 "${shared_root}/permanent/${name}"
            release_run cp -a "${seed_root}/storage/${name}/." "${shared_root}/permanent/${name}/"
        fi
        release_run install -d -m 770 "${shared_root}/permanent/${name}"
        release_run rm -rf "${release_dir}/storage/${name}"
        release_run ln -s "${shared_root}/permanent/${name}" "${release_dir}/storage/${name}"
    done
    for name in "${runtime[@]}"; do
        release_run install -d -m 770 "${shared_root}/runtime/${channel}/${name}"
        release_run rm -rf "${release_dir}/storage/${name}"
        release_run ln -s "${shared_root}/runtime/${channel}/${name}" "${release_dir}/storage/${name}"
    done
    release_run install -d -m 770 "${shared_root}/runtime/${channel}/logs" "${shared_root}/runtime/${channel}/tmp"
    release_run rm -rf "${release_dir}/logs" "${release_dir}/tmp"
    release_run ln -s "${shared_root}/runtime/${channel}/logs" "${release_dir}/logs"
    release_run ln -s "${shared_root}/runtime/${channel}/tmp" "${release_dir}/tmp"
}

release_schema_version() {
    local target="$1"
    php -r '
        require $argv[1] . "/backend/bootstrap/app.php";
        $app = bootstrapApplication();
        $db = new App\Infrastructure\Database\Database($app->config());
        $row = $db->fetchOne("SELECT version FROM schema_migrations ORDER BY executed_at DESC, version DESC LIMIT 1");
        echo trim((string)($row["version"] ?? "none"));
    ' "${target}"
}

release_cli_health_check() {
    local target="$1"
    local expected_channel="$2"
    local expected_commit="$3"
    if [[ "${DRY_RUN}" == true ]]; then
        release_log "dry-run: health CLI validaria canal e commit em ${target}"
        return
    fi
    php -r '
        require $argv[1] . "/backend/bootstrap/app.php";
        $app = bootstrapApplication();
        $channel = (string)$app->config()->get("app.release.channel", "");
        $commit = (string)$app->config()->get("app.release.commit", "");
        if ($channel !== $argv[2] || !hash_equals(strtolower($argv[3]), strtolower($commit))) exit(2);
        $db = new App\Infrastructure\Database\Database($app->config());
        $db->fetchOne("SELECT 1 AS ok");
    ' "${target}" "${expected_channel}" "${expected_commit}" || release_die "health CLI da release falhou"
}

release_seal_immutable_code() {
    local target="$1"
    release_require_safe_dir "${target}"
    release_run find "${target}" -type f ! -name .env -exec chmod 444 {} +
    release_run find "${target}" -type d -exec chmod 555 {} +
    if [[ "${DRY_RUN}" == false ]]; then
        chown root:www-data "${target}/.env"
        chmod 640 "${target}/.env"
    else
        release_log "dry-run: .env ficaria root:www-data 640 e fora dos logs"
    fi
}

release_atomic_switch() {
    local current_link="$1"
    local release_dir="$2"
    local previous_link="${current_link}_previous"
    local current_target temporary_link previous_tmp
    current_target=""
    if [[ -e "${current_link}" || -L "${current_link}" ]]; then
        current_target="$(readlink -f "${current_link}" 2>/dev/null || true)"
    fi
    if [[ "${DRY_RUN}" == true ]]; then
        release_log "dry-run: ${previous_link} preservaria ${current_target:-nenhuma release}"
        release_log "dry-run: ${current_link} apontaria atomicamente para ${release_dir}"
        return
    fi
    install -d -m 755 "$(dirname "${current_link}")"
    if [[ -n "${current_target}" ]]; then
        previous_tmp="${previous_link}.tmp.$$"
        ln -s "${current_target}" "${previous_tmp}"
        mv -Tf "${previous_tmp}" "${previous_link}"
    fi
    temporary_link="${current_link}.tmp.$$"
    ln -s "${release_dir}" "${temporary_link}"
    mv -Tf "${temporary_link}" "${current_link}"
}

release_validate_manifest() {
    local release_dir="$1"
    local release_id="$2"
    local commit="$3"
    local manifest="${release_dir}/.release-manifest"
    [[ -f "${manifest}" ]] || release_die "manifesto da release ausente"
    grep -Fxq "release_id=${release_id}" "${manifest}" || release_die "release ID não confere com o manifesto"
    grep -Fxq "commit=${commit}" "${manifest}" || release_die "commit não confere com o manifesto"
}

release_validate_manifest_channel() {
    local release_dir="$1"
    local channel="$2"
    grep -Fxq "channel=${channel}" "${release_dir}/.release-manifest" || release_die "canal da release não confere"
}
