#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_DIR="${BACKUP_DIR:-${ROOT_DIR}/backups}"
DATE_TAG="$(date +%F_%H%M%S)"
ARCHIVE_NAME="isp_auxiliar_storage_${DATE_TAG}.tar.gz"
DB_NAME="${DB_NAME:-isp_auxiliar}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_USER="${DB_USER:-isp_auxiliar}"
DB_PASSWORD="${DB_PASSWORD:-}"

mkdir -p "${BACKUP_DIR}"

echo "ISP Auxiliar - backup"
echo "Diretorio base: ${ROOT_DIR}"
echo "Destino: ${BACKUP_DIR}"

if [[ -d "${ROOT_DIR}/storage" ]]; then
    tar -czf "${BACKUP_DIR}/${ARCHIVE_NAME}" -C "${ROOT_DIR}" storage
    echo "Arquivo de storage criado: ${BACKUP_DIR}/${ARCHIVE_NAME}"
else
    echo "Aviso: diretorio storage nao encontrado."
fi

if command -v mysqldump >/dev/null 2>&1; then
    DB_DUMP_NAME="isp_auxiliar_db_${DATE_TAG}.sql"
    if [[ -n "${DB_PASSWORD}" ]]; then
        MYSQL_PWD="${DB_PASSWORD}" mysqldump -h "${DB_HOST}" -u "${DB_USER}" "${DB_NAME}" > "${BACKUP_DIR}/${DB_DUMP_NAME}"
    else
        mysqldump -h "${DB_HOST}" -u "${DB_USER}" "${DB_NAME}" > "${BACKUP_DIR}/${DB_DUMP_NAME}"
    fi
    echo "Dump do banco criado: ${BACKUP_DIR}/${DB_DUMP_NAME}"
else
    echo "Aviso: mysqldump nao encontrado. Dump do banco nao foi gerado."
fi

if [[ -f "${ROOT_DIR}/.env" ]]; then
    cp "${ROOT_DIR}/.env" "${BACKUP_DIR}/.env.${DATE_TAG}"
    echo "Copia do .env salva em ${BACKUP_DIR}/.env.${DATE_TAG}"
fi

echo "Backup concluido."
