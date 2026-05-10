#!/usr/bin/env bash
# Сборка фронта с переносом старых чанков.
#
# Зачем: ленивые модальные/тяжёлые чанки (см. globIgnores в vite.config.js)
# не попадают в precache Service Worker'а. Если пользователь держит
# открытой вкладку через деплой и нажимает «Позже» в баннере обновления,
# его старый бандл при попытке открыть модалку или импортировать XLSX
# обращается за чанком по старому хешу — а он уже удалён `rm -rf dist`.
# В итоге кнопка «не реагирует»: импорт падает, errorHandler снова
# поднимает баннер обновления — без возможности доработать.
#
# Решение: при новой сборке сохранять старые чанки одного предыдущего
# цикла (dist.prev). Свежий dist получает все новые файлы; недостающие
# (со старыми хешами) подкладываются из dist.prev. Так пользователь
# на N-1 версии может спокойно дотянуть до перерыва — а через N+1
# сборок мы всё равно его попросим обновиться.

set -euo pipefail
cd "$(dirname "$0")/.."

# npm run кладёт node_modules/.bin в PATH, но при ручном запуске — нет.
export PATH="$PWD/node_modules/.bin:$PATH"

# Блокировка от параллельных запусков (например, ручной build + Stop-hook
# одновременно). Без неё две сборки гонятся за dist/dist.prev и старые
# чанки исчезают. -n: если другой билд уже идёт — молча пропускаем
# (а не висим в очереди, иначе Stop-hook упадёт по таймауту посреди
# чужого vite build и оставит проект без dist).
exec 200>/tmp/bk-calc-build.lock
flock -n -x 200 || { echo "[build.sh] another build in progress — skipping"; exit 0; }

# Переносим текущий dist в dist.prev (предыдущий .prev удаляем — нам
# нужен ровно один цикл совместимости, иначе dist разрастается).
if [ -d dist.prev ]; then rm -rf dist.prev; fi
if [ -d dist ]; then mv dist dist.prev; fi

# Сборка фронта в чистый dist + копирование PHP API.
vite build
mkdir -p dist/api
cp -r api/*.php api/includes api/migrations dist/api/

# Подкладываем чанки из предыдущей сборки, которые не появились в новой.
# `cp -n` (no-clobber) не перетирает свежие файлы; в результате старые
# хеши остаются, чтобы пользователи на N-1 могли дотянуть с лениво
# подгружаемыми чанками. Логи — чтобы видеть, что шаг отработал.
if [ -d dist.prev/assets ]; then
    before=$(find dist/assets -maxdepth 1 -type f | wc -l)
    cp -nR dist.prev/assets/. dist/assets/ 2>/dev/null || true
    after=$(find dist/assets -maxdepth 1 -type f | wc -l)
    echo "[build.sh] preserved $((after - before)) old chunks from dist.prev/assets"
else
    echo "[build.sh] no dist.prev/assets — skipping preservation (первая сборка)"
fi

# Чистим dist.prev — он больше не нужен, чанки уже перенесены.
rm -rf dist.prev
