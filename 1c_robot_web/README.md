# 1C Robot Pro Web

Веб-часть готовит Excel-файлы для загрузки в 1С и раздаёт актуальный установщик локальной программы.

## Запуск сайта

```bash
cd 1c_robot_web
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
./run.sh
```

Сайт будет доступен на `http://localhost:8000`.

## Как обработать Excel

1. Откройте главную страницу.
2. Загрузите справочник товаров. В нём должны быть колонки `Штрихкод` и `Артикул`.
3. Загрузите накладную СТТ или сводную таблицу.
4. Выберите режим обработки.
5. Нажмите `Обработать Excel`.
6. На странице результата скачайте `queue_ok.xlsx`.

## Где лежат результаты

Файлы сохраняются в `storage/outputs/{дата_и_время}/`.

Для одной накладной создаются:

- `queue.xlsx`
- `queue_ok.xlsx`
- `queue_errors.xlsx`

Для сводной таблицы дополнительно создаются отдельные файлы по каждому номеру ЭТТН.

## Как выложить установщик

1. Соберите установщик в локальном проекте `1C_Robot_Pro`.
2. Скопируйте файл `installer_output/1C_Robot_Setup.exe` в `1c_robot_web/storage/releases/1C_Robot_Setup.exe`.
3. Обновите `1c_robot_web/storage/releases/version.json`.

Для основного сайта `supply-department.online` актуальные публичные файлы лежат в:

- `public/version.json`
- `public/releases/1C_Robot_Setup.exe`

После сборки фронтенда они попадают в `dist/`.

Формат `version.json`:

```json
{
  "version": "1.0.0",
  "installer_url": "/releases/1C_Robot_Setup.exe",
  "notes": "Описание изменений"
}
```

Страница скачивания: `/download`.
