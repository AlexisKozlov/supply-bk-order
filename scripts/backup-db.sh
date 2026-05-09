#!/usr/bin/env bash
# Создаёт бэкап БД проекта bk-calc в /var/backups/bk-calc/.
# Каталог за пределами webroot, права 600 (только root читает).
#
# Использование:
#   ./scripts/backup-db.sh                         # полный дамп всей БД
#   ./scripts/backup-db.sh restaurant_sales        # дамп одной таблицы
#   ./scripts/backup-db.sh -- before_migration_X   # дамп с пометкой в имени
#
# Результат: /var/backups/bk-calc/<метка>_YYYYMMDD_HHMMSS.sql.gz

set -euo pipefail

ENV_FILE="/var/www/bk-calc-secrets/.env"
DEST_DIR="/var/backups/bk-calc"

if [[ ! -r "$ENV_FILE" ]]; then
  echo "Не найден $ENV_FILE — некуда взять креденшелы БД" >&2
  exit 1
fi

# shellcheck disable=SC1090
set -a; . "$ENV_FILE"; set +a

mkdir -p "$DEST_DIR"
chmod 700 "$DEST_DIR"

label="${1:-full}"
[[ "$label" == "--" ]] && shift && label="${1:-full}"

ts="$(date +%Y%m%d_%H%M%S)"
out="$DEST_DIR/${label}_${ts}.sql.gz"

# --single-transaction для согласованности на InnoDB без блокировок.
# --no-tablespaces чтобы не требовать привилегию PROCESS.
mysqldump \
  -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" \
  --single-transaction --no-tablespaces \
  "$DB_NAME" "${@:2}" \
  | gzip > "$out"

chmod 600 "$out"

# Чистка старых дампов: оставляем последние 30.
find "$DEST_DIR" -maxdepth 1 -type f -name '*.sql.gz' -printf '%T@ %p\n' \
  | sort -rn | awk 'NR>30 {print $2}' \
  | xargs -r rm -f

ls -lh "$out"
