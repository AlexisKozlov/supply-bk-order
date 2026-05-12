/**
 * SVG-иконки для личного кабинета ресторана.
 * Вынесено из RestaurantCabinetView.vue для облегчения основного файла.
 *
 * Использование:
 *   import { cabIconSvg, tileIconSvg, supplierIconSvg, tabIconSvg, supplierIcon, trustedSupplierIcon } from '@/lib/cabinetIcons.js';
 *   <span v-html="cabIconSvg.dashboard"></span>
 *
 * ВАЖНО: иконки берутся ТОЛЬКО из этого словаря, не из API. Если в будущем
 * кто-то решит подгружать SVG с сервера — пройдут через trustedSupplierIcon
 * (защита от XSS): неизвестный ключ вернёт пустую строку.
 */

// ── Иконки сайдбара/таббара ──
export const cabIconSvg = {
  dashboard: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20h14V9.5"/><path d="M9.5 20v-6h5v6"/></svg>',
  orders: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8l8-4 8 4-8 4-8-4Z"/><path d="M4 8v8l8 4 8-4V8"/><path d="M12 12v8"/></svg>',
  history: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 1 0 2.4-5.7"/><path d="M4 4v5h5"/><path d="M12 8v4l3 2"/></svg>',
  info: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6"/><path d="M12 7.5h.01"/></svg>',
  surveys: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14v16H5z"/><path d="M9 9h6"/><path d="M9 13h6"/><path d="M8.5 17l1.5 1.5 3-3"/></svg>',
  stock: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4h10v16H7z"/><path d="M9 8h6"/><path d="M9 12h6"/><path d="M9 16h4"/></svg>',
  warehouse: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9l9-5 9 5"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/><path d="M8 12h8"/></svg>',
  scanner: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7V5a1 1 0 0 1 1-1h2"/><path d="M17 4h2a1 1 0 0 1 1 1v2"/><path d="M20 17v2a1 1 0 0 1-1 1h-2"/><path d="M7 20H5a1 1 0 0 1-1-1v-2"/><path d="M8 8v8"/><path d="M12 8v8"/><path d="M16 8v8"/></svg>',
  search: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/></svg>',
  profile: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M5 20a7 7 0 0 1 14 0"/></svg>',
  external: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 8h8v8"/><path d="M16 8 7 17"/></svg>',
  help: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.8 9a2.4 2.4 0 0 1 4.6 1.2c0 1.8-2.4 2-2.4 3.8"/><path d="M12 17.5h.01"/></svg>',
  file: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h7l3 3v15H7z"/><path d="M14 3v4h4"/><path d="M9.5 12h5"/><path d="M9.5 16h5"/></svg>',
  check: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5 10 17l9-10"/></svg>',
  x: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7l10 10"/><path d="M17 7 7 17"/></svg>',
  skip: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M7 17 17 7"/></svg>',
  edit: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 16.5-.5 4 4-.5L18.5 9 15 5.5 4 16.5Z"/><path d="m13.5 7 3.5 3.5"/></svg>',
  truck: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
  kegReturn: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8 Q 4 3 12 3 Q 20 3 20 8"/><polyline points="6.5 6 4 8 6.5 10.5"/><ellipse cx="12" cy="10.5" rx="5" ry="1.5"/><path d="M7 10.5V20c0 .8 2.2 1.5 5 1.5s5-.7 5-1.5v-9.5"/><path d="M7 14c0 .8 2.2 1.5 5 1.5s5-.7 5-1.5"/><path d="M7 17.5c0 .8 2.2 1.5 5 1.5s5-.7 5-1.5"/></svg>',
  reminders: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9a6 6 0 0 1 12 0v5l1.5 2.5h-15L6 14V9Z"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>',
  corrections: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h10l4 4v12H5z"/><path d="M15 4v4h4"/><path d="m11 11 4.5 4.5"/><path d="m8 18 1.5-3.5 3.5-3.5 2 2-3.5 3.5L8 18Z"/></svg>',
};

// ── Иконки для крупных плиток на дашборде ──
export const tileIconSvg = {
  scanner: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="6" width="18" height="12" rx="2.5"/><line x1="7" y1="9" x2="7" y2="15"/><line x1="10.5" y1="9" x2="10.5" y2="15"/><line x1="13.5" y1="9" x2="13.5" y2="15"/><line x1="17" y1="9" x2="17" y2="15"/><line x1="3" y1="12" x2="21" y2="12" stroke-width="2.4" stroke-linecap="round" opacity="0.55"/></svg>',
  warehouse: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11V8.5L12 4l9 4.5V11"/><path d="M4 11h16v9.5H4z"/><path d="M9 14.5h6"/><path d="M9 17.5h6"/><circle cx="17.5" cy="6.5" r="3" fill="currentColor" opacity="0.18" stroke="none"/><path d="M17.5 5v1.5l1 .8" stroke-width="1.6"/></svg>',
  keg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8 Q 4 3 12 3 Q 20 3 20 8"/><polyline points="6.5 6 4 8 6.5 10.5"/><ellipse cx="12" cy="10.5" rx="5" ry="1.5"/><path d="M7 10.5V20c0 .8 2.2 1.5 5 1.5s5-.7 5-1.5v-9.5"/><path d="M7 14c0 .8 2.2 1.5 5 1.5s5-.7 5-1.5"/><path d="M7 17.5c0 .8 2.2 1.5 5 1.5s5-.7 5-1.5"/></svg>',
  search: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="13" height="16" rx="2.5"/><path d="M6.5 8h7"/><path d="M6.5 12h5"/><circle cx="17" cy="15.5" r="3.8" fill="currentColor" fill-opacity="0.1"/><path d="M19.7 18.2 22 20.5"/></svg>',
  reminders: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9a6 6 0 0 1 12 0v5l1.5 2.5h-15L6 14V9Z"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>',
};

// ── Иконки поставщиков для внешних ссылок ──
export const supplierIconSvg = {
  drinks: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 3h4"/><path d="M9 3v3l-2 2v11a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2V8l-2-2V3"/><path d="M7 12h6"/><path d="M16 7h2l1 3v9a2 2 0 0 1-2 2h-1"/><path d="M16 11h3"/></svg>',
  vegetables: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 14c-2.2-2.8-.4-6.5 4.5-8 0 4-1.5 6.5-4.5 8Z"/><path d="M14 14c.2-3.8 3-6.2 6.5-5.8-1 3.5-3.4 5.6-6.5 5.8Z"/><path d="M5 15h14l-1.2 3.4A4 4 0 0 1 14 21h-4a4 4 0 0 1-3.8-2.6L5 15Z"/><path d="M10 16.5h4"/></svg>',
  sauce: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3h6"/><path d="M10 3v4l-2 3v8a3 3 0 0 0 3 3h2a3 3 0 0 0 3-3v-8l-2-3V3"/><path d="M8 12h8"/><path d="M9 16h6"/><path d="M12 8v1"/></svg>',
  package: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8l8-4 8 4-8 4-8-4Z"/><path d="M4 8v8l8 4 8-4V8"/><path d="M12 12v8"/><path d="M8 6.2 16 10"/></svg>',
};

// Безопасное получение SVG поставщика только из локального словаря (защита от XSS).
export function trustedSupplierIcon(key) {
  return supplierIconSvg[key] || '';
}

// Подбор иконки поставщика по имени.
export function supplierIcon(name) {
  const n = String(name || '').toLowerCase();
  if (n.includes('камако')) return { svg: supplierIconSvg.sauce, className: 'supplier-icon-sauce' };
  if (n.includes('лидск')) return { svg: supplierIconSvg.drinks, className: 'supplier-icon-drinks' };
  if (n.includes('салатор') || n.includes('планета')) return { svg: supplierIconSvg.vegetables, className: 'supplier-icon-vegetables' };
  return { svg: supplierIconSvg.package, className: 'supplier-icon-neutral' };
}

// SVG для табов сайдбара/нижнего таббара.
export function tabIconSvg(tabId) {
  if (tabId === 'warehouse-stock') return cabIconSvg.warehouse;
  if (tabId === 'keg-returns') return cabIconSvg.kegReturn;
  if (tabId === 'reminders') return cabIconSvg.reminders;
  return cabIconSvg[tabId] || cabIconSvg.profile;
}
