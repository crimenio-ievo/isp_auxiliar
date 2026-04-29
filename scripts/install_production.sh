#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${ROOT_DIR}/.env"
ENV_EXAMPLE="${ROOT_DIR}/.env.example"

echo "ISP Auxiliar - instalacao para producao"
echo "Diretorio base: ${ROOT_DIR}"

if [[ ! -f "${ENV_FILE}" ]]; then
    if [[ -f "${ENV_EXAMPLE}" ]]; then
        cp "${ENV_EXAMPLE}" "${ENV_FILE}"
        echo "Arquivo .env criado a partir de .env.example"
    else
        echo "ERRO: .env e .env.example nao encontrados."
        exit 1
    fi
fi

echo "Checklist minimo antes da publicacao:"
echo "1. Ajustar credenciais em .env"
echo "2. Confirmar PHP 8.2+ e extensoes pdo_mysql, curl, mbstring, openssl e fileinfo"
echo "3. Garantir acesso ao banco local e ao MkAuth"
echo "4. Criar o banco local isp_auxiliar"
echo "5. Rodar: php scripts/console.php migrate"
echo "6. Criar o primeiro gestor local"
echo "7. Validar login no navegador"
echo "8. Validar acesso via HTTPS"

echo
echo "Comandos sugeridos:"
echo "  php ${ROOT_DIR}/scripts/console.php db:create"
echo "  php ${ROOT_DIR}/scripts/console.php migrate"
echo "  php ${ROOT_DIR}/scripts/console.php user:create-manager \"Gestor\" gestor@provedor.com.br \"SENHA_FORTE\""
echo "  php ${ROOT_DIR}/scripts/console.php settings:sync-env"

echo
echo "Configuracao do servidor web:"
echo "- Apontar DocumentRoot para public/"
echo "- Permitir AllowOverride All no diretorio public/"
echo "- Ativar rewrite e ssl"

echo
echo "Instalacao preparada. Revise o .env antes de publicar."
