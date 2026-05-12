#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOGIN="${1:-crimenio}"

echo "ISP Auxiliar - atualizacao oficial"
echo "Diretorio base: ${ROOT_DIR}"
echo "Login alvo: ${LOGIN}"

cd "${ROOT_DIR}"

echo
echo "[1/5] git pull origin main"
git pull origin main

echo
echo "[2/5] apply migrations"
php scripts/apply_migrations.php

echo
echo "[3/5] ensure admin"
php scripts/ensure_admin.php "${LOGIN}"

echo
echo "[4/5] ajustar permissoes de pasta"
mkdir -p storage/logs backups
chmod -R ug+rwX storage logs backups || true

echo
echo "[5/5] check install"
php scripts/check_install.php "${LOGIN}"

echo
echo "[6/6] reload apache"
if command -v sudo >/dev/null 2>&1; then
    sudo -n systemctl reload apache2 || sudo -n systemctl reload httpd || apache2ctl graceful
elif command -v systemctl >/dev/null 2>&1; then
    systemctl reload apache2 || systemctl reload httpd || apache2ctl graceful
else
    apache2ctl graceful
fi

echo
echo "Atualizacao concluida com sucesso."
