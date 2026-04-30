# Портал закупок: документация для разработчика

Портал закупок — рабочая система для отдела закупок и ресторанов. Это не только калькулятор заказа: в проекте есть основной заказ, история, планирование, аналитика, база товаров, цены и ПСЦ, график доставки, ресторанные заказы, заявки поставщикам, загрузка машин, Telegram, 1C Robot и административные инструменты.

Документация проекта лежит в папке [docs](/var/www/bk-calc/docs).

## Что читать новому разработчику

Рекомендуемый порядок:

1. [Онбординг разработчика](/var/www/bk-calc/docs/developer-onboarding.md)
2. [Обзор проекта](/var/www/bk-calc/docs/overview.md)
3. [Архитектура](/var/www/bk-calc/docs/architecture.md)
4. [Карта кода](/var/www/bk-calc/docs/code-map.md)
5. [Бизнес-сценарии](/var/www/bk-calc/docs/workflows.md)
6. [Бэкенд и API](/var/www/bk-calc/docs/backend.md)
7. [Справочник API](/var/www/bk-calc/docs/api-reference.md)
8. [Модель данных](/var/www/bk-calc/docs/data-model.md)
9. [Права доступа](/var/www/bk-calc/docs/access-control.md)
10. [Диагностика проблем](/var/www/bk-calc/docs/troubleshooting.md)

## Быстрый старт

```bash
npm install
npm run dev
```

Сборка:

```bash
npm run build
```

Команда сборки создаёт `dist/`, собирает Vue-приложение и копирует PHP API в `dist/api/`.

## Основные документы

- [Онбординг разработчика](/var/www/bk-calc/docs/developer-onboarding.md)
- [Обзор проекта](/var/www/bk-calc/docs/overview.md)
- [Архитектура](/var/www/bk-calc/docs/architecture.md)
- [Карта кода](/var/www/bk-calc/docs/code-map.md)
- [Фронтенд](/var/www/bk-calc/docs/frontend.md)
- [Бэкенд и API](/var/www/bk-calc/docs/backend.md)
- [Справочник API](/var/www/bk-calc/docs/api-reference.md)
- [Бизнес-модули](/var/www/bk-calc/docs/modules.md)
- [База данных и миграции](/var/www/bk-calc/docs/database.md)
- [Модель данных](/var/www/bk-calc/docs/data-model.md)
- [Бизнес-сценарии](/var/www/bk-calc/docs/workflows.md)
- [Права доступа](/var/www/bk-calc/docs/access-control.md)
- [Запуск и деплой](/var/www/bk-calc/docs/deployment.md)
- [Поддержка и безопасные изменения](/var/www/bk-calc/docs/maintenance.md)
- [Диагностика проблем](/var/www/bk-calc/docs/troubleshooting.md)

## Точки входа

- Фронтенд: [index.html](/var/www/bk-calc/index.html) -> [src/main.js](/var/www/bk-calc/src/main.js) -> [src/App.vue](/var/www/bk-calc/src/App.vue) -> [src/router/index.js](/var/www/bk-calc/src/router/index.js)
- API: [api/index.php](/var/www/bk-calc/api/index.php)
- Сборка: [package.json](/var/www/bk-calc/package.json)

Корневого `index.php` в актуальной версии нет.

## Важные правила

- Не коммитить секреты из `api/.env`.
- Не править `dist/` как исходник.
- При изменениях прав доступа проверять и фронтенд, и бэкенд.
- При изменениях юрлиц проверять [src/lib/legalEntities.js](/var/www/bk-calc/src/lib/legalEntities.js) и [api/includes/legal_entities.php](/var/www/bk-calc/api/includes/legal_entities.php).
- Автотестов и линтера нет, поэтому после изменений обязательны сборка и ручная проверка.
