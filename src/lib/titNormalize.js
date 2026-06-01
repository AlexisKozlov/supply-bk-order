// Нормализация номера машины и телефона водителя для модуля «Заявка на пропуск».
// Зеркало api/includes/tit_normalize.php — одинаковая логика на бэке и фронте,
// чтобы и при ручном вводе в портале, и при парсинге письма результат был один.

const CYRILLIC_TO_LATIN = {
  А: 'A', В: 'B', Е: 'E', К: 'K', М: 'M',
  Н: 'H', О: 'O', Р: 'P', С: 'C', Т: 'T',
  У: 'Y', Х: 'X',
  а: 'A', в: 'B', е: 'E', к: 'K', м: 'M',
  н: 'H', о: 'O', р: 'P', с: 'C', т: 'T',
  у: 'Y', х: 'X',
};

// «АМ2324-5» / «am 23 24 5» → «AM23245»
export function normalizePlate(input) {
  const raw = String(input ?? '').trim();
  if (!raw) return { plate: '', raw: '', valid: false };
  let s = '';
  for (const ch of raw) s += CYRILLIC_TO_LATIN[ch] ?? ch;
  s = s.toUpperCase().replace(/[^A-Z0-9]/g, '');
  return { plate: s, raw, valid: s.length >= 5 && s.length <= 10 };
}

// «+375 (29) 537-43-11» / «80 29 537 43 11» → «375295374311»
export function normalizePhone(input) {
  const raw = String(input ?? '').trim();
  if (!raw) return { phone: '', raw: '', valid: false };
  let digits = raw.replace(/\D+/g, '');
  // 80XXXXXXXXX → 375XXXXXXXXX (белорусский национальный → международный).
  // «8» — национальный префикс, «0» — код-замена; в международном их заменяет «375».
  if (digits.length === 11 && digits.startsWith('80')) {
    digits = '375' + digits.slice(2);
  }
  const valid = digits.length === 12 && digits.startsWith('375');
  return { phone: valid ? digits : '', raw, valid };
}

// Удобная проверка пары — возвращает массив сообщений (пустой = всё ок).
export function validatePair(plate, phone) {
  const issues = [];
  if (!normalizePlate(plate).valid) issues.push('Номер машины: 5–10 символов, только буквы и цифры');
  if (!normalizePhone(phone).valid) issues.push('Телефон водителя: 12 цифр, формат 375XXXXXXXXX');
  return issues;
}
