#!/usr/bin/env bash
# Backup só das tabelas do mapa e dos vínculos de rede (map_*, client_network_binding*).
# Roda como root pelo socket local do MariaDB (sem senha em linha de comando).
# Uso: scripts/backup_map.sh            (gera /root/isp-auxiliar-backups/mapa/mapa-AAAAMMDD-HHMM.sql.gz)
# Variáveis: MAP_BACKUP_DIR, MAP_BACKUP_DB (padrão isp_auxiliar), MAP_BACKUP_KEEP (padrão 30).
set -euo pipefail

DB="${MAP_BACKUP_DB:-isp_auxiliar}"
DIR="${MAP_BACKUP_DIR:-/root/isp-auxiliar-backups/mapa}"
KEEP="${MAP_BACKUP_KEEP:-30}"

if [[ ! "$DB" =~ ^[A-Za-z0-9_]+$ ]]; then echo "Banco inválido." >&2; exit 1; fi
if [[ ! "$KEEP" =~ ^[0-9]+$ ]] || (( KEEP < 1 )); then echo "MAP_BACKUP_KEEP inválido." >&2; exit 1; fi

mapfile -t TABLES < <(mysql -N -B -e "SELECT table_name FROM information_schema.tables
  WHERE table_schema='${DB}' AND table_type='BASE TABLE'
  AND (table_name LIKE 'map\\_%' OR table_name LIKE 'client\\_network\\_binding%')
  ORDER BY table_name")
if (( ${#TABLES[@]} == 0 )); then echo "Nenhuma tabela do mapa encontrada em ${DB}." >&2; exit 1; fi

umask 077
mkdir -p "$DIR"
OUT="${DIR}/mapa-$(date +%Y%m%d-%H%M).sql.gz"
TMP="${OUT}.parcial"
trap 'rm -f "$TMP"' EXIT

mysqldump --single-transaction --skip-lock-tables --routines=false --triggers \
  --default-character-set=utf8mb4 "$DB" "${TABLES[@]}" | gzip -9 > "$TMP"
gzip -t "$TMP"
zcat "$TMP" | tail -n 1 | grep -q '^-- Dump completed' || { echo "Dump incompleto." >&2; exit 1; }
mv "$TMP" "$OUT"
trap - EXIT

# Retenção: mantém os KEEP mais recentes.
ls -1t "$DIR"/mapa-*.sql.gz 2>/dev/null | tail -n +$((KEEP + 1)) | xargs -r rm -f --

echo "Backup do mapa: ${OUT} (${#TABLES[@]} tabelas: ${TABLES[*]})"
