/**
 * Единый источник данных о юридических лицах.
 * Все названия, группы и маппинги — только здесь.
 */

export const LEGAL_ENTITIES = [
  'ООО "Бургер БК"',
  'ООО "Воглия Матта"',
  'ООО "Пицца Стар"',
];

export const DEFAULT_ENTITY = LEGAL_ENTITIES[0];

// Коды групп юрлиц для колонки legal_entity_group в таблицах restaurants/products/suppliers.
export const ENTITY_GROUP_BK_VM = 'BK_VM';
export const ENTITY_GROUP_PS = 'PS';

/**
 * Таблицы, которые НАМЕРЕННО общие для группы BK+VM (не делятся по юрлицам).
 * Для них фильтрация идёт по группе через .or()/applyEntityFilter, а не по одному
 * юрлицу. Любое добавление в этот список должно дублироваться в helpers.php
 * (обратное также: убирай из $ENTITY_TABLES на бэке).
 */
export const SHARED_BK_VM_TABLES = [
  'order_corrections',
  'chat_conversations',
  'chat_messages',
  'marketing_activities',
];

export const ENTITY_SHORT_NAMES = {
  'ООО "Бургер БК"': 'БК',
  'ООО "Воглия Матта"': 'ВМ',
  'ООО "Пицца Стар"': 'ПС',
};

/**
 * Группы для справочников (товары, поставщики).
 * БК и ВМ — одна группа, ПС — отдельная.
 * Возвращает массив юрлиц — для старой логики фильтрации через .or() по колонке legal_entity.
 */
export function getEntityGroup(legalEntity) {
  if (legalEntity === 'ООО "Пицца Стар"') return ['ООО "Пицца Стар"'];
  return ['ООО "Бургер БК"', 'ООО "Воглия Матта"'];
}

/**
 * Код группы юрлиц для новой колонки legal_entity_group (таблицы products, suppliers, restaurants).
 * Возвращает 'BK_VM' | 'PS'.
 */
export function getEntityGroupCode(legalEntity) {
  if (legalEntity && legalEntity.includes('Пицца Стар')) return 'PS';
  return 'BK_VM';
}

/**
 * Красивое отображение номера ресторана.
 * Для Пицца Стар (PS) номера в базе лежат в диапазоне 1001+, а в интерфейсе
 * показываются как 'PS01', 'PS02' и т.п. Для БК+ВМ — обычное число.
 * group: 'BK_VM' | 'PS' — если не задан, определяем по числу (1000+ = PS).
 */
export function formatRestaurantNumber(number, group = null) {
  const n = parseInt(number, 10);
  if (!Number.isFinite(n)) return '';
  const g = group || (n >= 1000 ? 'PS' : 'BK_VM');
  if (g === 'PS') {
    const inGroup = n - 1000;
    return 'PS' + String(inGroup).padStart(2, '0');
  }
  return String(n);
}

/**
 * Обратная операция: принимает текст от пользователя и возвращает
 * число для базы. Понимает 'PS01', 'ps1', '1001', '1', ' 24 '.
 * Возвращает { number, group } или null, если не распознано.
 */
export function parseRestaurantInput(input) {
  if (input == null) return null;
  const s = String(input).trim().toUpperCase();
  if (!s) return null;
  // 'PS01', 'PS1', 'PS-1', 'PS 1'
  const psMatch = s.match(/^PS[\s\-]?0*(\d{1,3})$/);
  if (psMatch) {
    const inGroup = parseInt(psMatch[1], 10);
    if (!inGroup) return null;
    return { number: 1000 + inGroup, group: 'PS' };
  }
  // Чистое число
  const numMatch = s.match(/^0*(\d+)$/);
  if (numMatch) {
    const n = parseInt(numMatch[1], 10);
    if (!n) return null;
    return { number: n, group: n >= 1000 ? 'PS' : 'BK_VM' };
  }
  return null;
}

/**
 * Маппинг названий из Excel-файлов → стандартное юрлицо (ключи в нижнем регистре).
 */
export const ENTITY_IMPORT_MAP = {
  'сбарро':              'ООО "Пицца Стар"',
  'додо':                'ООО "Пицца Стар"',
  'пицца стар':          'ООО "Пицца Стар"',
  'ооо "пицца стар"':    'ООО "Пицца Стар"',
  'ооо пицца стар':      'ООО "Пицца Стар"',
  'бургер бк':           'ООО "Бургер БК"',
  'ооо "бургер бк"':     'ООО "Бургер БК"',
  'ооо бургер бк':       'ООО "Бургер БК"',
  'воглия матта':        'ООО "Воглия Матта"',
  'ооо "воглия матта"':  'ООО "Воглия Матта"',
  'ооо воглия матта':    'ООО "Воглия Матта"',
};

/**
 * Маппинг заказчиков из файлов сроков годности (короткие имена → группа без ООО).
 */
export const CUSTOMER_MAP = {
  'додо': 'Пицца Стар',
  'сбарро': 'Пицца Стар',
  'dodo': 'Пицца Стар',
  'sbarro': 'Пицца Стар',
  'бургер бк': 'Бургер БК',
  'воглия': 'Воглия Матта',
};
