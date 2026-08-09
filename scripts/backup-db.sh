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

# .env НЕЛЬЗЯ подключать через `.` — это не скрипт, а список настроек.
# Значения там бывают с пробелами и кириллицей, и bash пытался выполнить их
# как команды: скрипт падал на строке с названием юрлица («command not found»)
# и молча не делал копию. Поэтому разбираем построчно и берём только
# нужные ключи.
read_env() {
  local key="$1"
  sed -n "s/^[[:space:]]*${key}[[:space:]]*=[[:space:]]*//p" "$ENV_FILE" \
    | head -1 | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/" -e 's/[[:space:]]*$//'
}
DB_HOST="$(read_env DB_HOST)"; DB_HOST="${DB_HOST:-localhost}"
DB_NAME="$(read_env DB_NAME)"
DB_USER="$(read_env DB_USER)"
DB_PASS="$(read_env DB_PASS)"
if [[ -z "$DB_NAME" || -z "$DB_USER" ]]; then
  echo "В $ENV_FILE не нашлись DB_NAME/DB_USER" >&2
  exit 1
fi

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

# Чистка старых копий: держим 14 последних ежедневных. Копия весит ~4 МБ,
# то есть весь запас — около 55 МБ. Ручные копии с другими метками
# (before_*, restaurant_sales_*) не трогаем: их делают перед миграциями,
# и удалять их по расписанию нельзя.
ls -t "$DEST_DIR"/daily_*.sql.gz 2>/dev/null | tail -n +15 | xargs -r rm -f
