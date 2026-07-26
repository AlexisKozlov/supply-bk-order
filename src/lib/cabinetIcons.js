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
  contacts: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="2"/><circle cx="12" cy="10" r="2.5"/><path d="M8 17c.7-2 2.2-3 4-3s3.3 1 4 3"/><path d="M4 8h2"/><path d="M4 12h2"/><path d="M4 16h2"/></svg>',
};

// ── Иконки для крупных плиток на дашборде ──
export const tileIconSvg = {
  scanner: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="6" width="18" height="12" rx="2.5"/><line x1="7" y1="9" x2="7" y2="15"/><line x1="10.5" y1="9" x2="10.5" y2="15"/><line x1="13.5" y1="9" x2="13.5" y2="15"/><line x1="17" y1="9" x2="17" y2="15"/><line x1="3" y1="12" x2="21" y2="12" stroke-width="2.4" stroke-linecap="round" opacity="0.55"/></svg>',
  warehouse: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11V8.5L12 4l9 4.5V11"/><path d="M4 11h16v9.5H4z"/><path d="M9 14.5h6"/><path d="M9 17.5h6"/><circle cx="17.5" cy="6.5" r="3" fill="currentColor" opacity="0.18" stroke="none"/><path d="M17.5 5v1.5l1 .8" stroke-width="1.6"/></svg>',
  keg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8 Q 4 3 12 3 Q 20 3 20 8"/><polyline points="6.5 6 4 8 6.5 10.5"/><ellipse cx="12" cy="10.5" rx="5" ry="1.5"/><path d="M7 10.5V20c0 .8 2.2 1.5 5 1.5s5-.7 5-1.5v-9.5"/><path d="M7 14c0 .8 2.2 1.5 5 1.5s5-.7 5-1.5"/><path d="M7 17.5c0 .8 2.2 1.5 5 1.5s5-.7 5-1.5"/></svg>',
  search: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="13" height="16" rx="2.5"/><path d="M6.5 8h7"/><path d="M6.5 12h5"/><circle cx="17" cy="15.5" r="3.8" fill="currentColor" fill-opacity="0.1"/><path d="M19.7 18.2 22 20.5"/></svg>',
  reminders: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9a6 6 0 0 1 12 0v5l1.5 2.5h-15L6 14V9Z"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>',
  corrections: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h10l4 4v12H5z"/><path d="M15 4v4h4"/><path d="m9 13 4.5-4.5 2 2L11 15H9v-2Z" fill="currentColor" fill-opacity="0.12"/><path d="M9 17h6"/></svg>',
  assistant: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4v2.5h6V4"/><path d="m9.5 12 2 2 3.5-3.5" fill="currentColor" fill-opacity="0.12"/><path d="M8.5 16h7"/></svg>',
  contacts: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="12" cy="11" r="3" fill="currentColor" fill-opacity="0.1"/><path d="M7 17.5c1-2.3 2.8-3.5 5-3.5s4 1.2 5 3.5"/><path d="M3 9h2"/><path d="M3 13h2"/><path d="M3 17h2"/></svg>',
};

// ── Иконки поставщиков для внешних ссылок ──
export const supplierIconSvg = {
  drinks: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 3h4"/><path d="M9 3v3l-2 2v11a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2V8l-2-2V3"/><path d="M7 12h6"/><path d="M16 7h2l1 3v9a2 2 0 0 1-2 2h-1"/><path d="M16 11h3"/></svg>',
  vegetables: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 14c-2.2-2.8-.4-6.5 4.5-8 0 4-1.5 6.5-4.5 8Z"/><path d="M14 14c.2-3.8 3-6.2 6.5-5.8-1 3.5-3.4 5.6-6.5 5.8Z"/><path d="M5 15h14l-1.2 3.4A4 4 0 0 1 14 21h-4a4 4 0 0 1-3.8-2.6L5 15Z"/><path d="M10 16.5h4"/></svg>',
  sauce: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3h6"/><path d="M10 3v4l-2 3v8a3 3 0 0 0 3 3h2a3 3 0 0 0 3-3v-8l-2-3V3"/><path d="M8 12h8"/><path d="M9 16h6"/><path d="M12 8v1"/></svg>',
  package: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8l8-4 8 4-8 4-8-4Z"/><path d="M4 8v8l8 4 8-4V8"/><path d="M12 12v8"/><path d="M8 6.2 16 10"/></svg>',
  meat: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13.5 4.5a5 5 0 0 1 6 6c-1.2 3-4 4.3-6.3 5.1l-1.6 3.6a2 2 0 0 1-3.6-1.7l1-2.2-.4-.4a2 2 0 0 1 2.6-3l.4.4C11.7 9 12 6.3 13.5 4.5Z"/><circle cx="15.5" cy="8.5" r="1.2"/></svg>',
  bread: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 11a4 4 0 0 1 4-4h6a4 4 0 0 1 0 8H9a4 4 0 0 1-4-4Z"/><path d="M9 8v6"/><path d="M12 8v6"/><path d="M15 8v6"/></svg>',
  dairy: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 3h8l-1 4v12a2 2 0 0 1-2 2h-2a2 2 0 0 1-2-2V7L8 3Z"/><path d="M8.5 7h7"/><path d="M10 11h4v4h-4z"/></svg>',
  fish: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12c3-4 8-5 12-3 2 1 4 2 6 3-2 1-4 2-6 3-4 2-9 1-12-3Z"/><path d="M17 10.5l3-2.5v8l-3-2.5"/><circle cx="8" cy="11" r="1"/></svg>',
  fruit: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8c-3 0-6 2-6 6a5 5 0 0 0 5 5c.7 0 1-.2 1-.2s.3.2 1 .2a5 5 0 0 0 5-5c0-4-3-6-6-6Z"/><path d="M12 8c0-2 1.5-3.5 3.5-3.5"/></svg>',
  cleaning: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3l3 3-2 2-3-3z"/><path d="M12 5 6 11l-2 6 6-2 6-6"/><path d="M6 11l3 3"/></svg>',
  coffee: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h13v5a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5V8Z"/><path d="M17 9h2a2 2 0 0 1 0 4h-2"/><path d="M7 3v2"/><path d="M11 3v2"/></svg>',
  link: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1"/></svg>',
  pizza: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3c4.5 0 8.5 2.4 10 6L12 21 2 9c1.5-3.6 5.5-6 10-6Z"/><path d="M4 8.5c4-1.5 12-1.5 16 0"/><circle cx="10" cy="9" r="1"/><circle cx="14" cy="10.5" r="1"/><circle cx="11.5" cy="13.5" r="1"/></svg>',
  cheese: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 15 15 5l6 5v5H3z"/><path d="M3 15v3h18v-3"/><circle cx="8" cy="13" r="1"/><circle cx="13" cy="14" r="1"/><circle cx="17" cy="12.5" r="1"/></svg>',
  egg: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3c3.3 0 6 4 6 8.5S15.3 20 12 20s-6-3.6-6-8.5S8.7 3 12 3Z"/><path d="M10 12a2.2 2.2 0 0 0 4 0"/></svg>',
  chicken: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4a5 5 0 0 1 4 8c-1.4 1.6-3.6 2-5.3 2.4l-1.5 4.3a2 2 0 0 1-3.8-1.3l1.4-4.2C7 12.6 7 10 8.4 8 9.7 6 11.8 4.5 14 4Z"/><path d="m6 16-2 4"/></svg>',
  oil: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 3h4"/><path d="M11 3v3l-1.5 2v10a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2V8L13 6V3"/><path d="M12 12c1.5 1.5 1.5 3 0 4-1.5-1-1.5-2.5 0-4Z"/></svg>',
  spice: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 9h10l-1 11H8L7 9Z"/><path d="M8 9V6a4 4 0 0 1 8 0v3"/><path d="M10 5h.01"/><path d="M12 4h.01"/><path d="M14 5h.01"/></svg>',
  frozen: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2v20"/><path d="M4 7l16 10"/><path d="M20 7 4 17"/><path d="M12 5 9.5 7 12 9l2.5-2L12 5Z"/><path d="M5 9l.5 3-3 .8"/><path d="M19 9l-.5 3 3 .8"/></svg>',
  water: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3c3 4 6 7 6 10.5A6 6 0 0 1 6 13.5C6 10 9 7 12 3Z"/><path d="M9.5 14a2.5 2.5 0 0 0 2.5 2.5"/></svg>',
  grain: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21V9"/><path d="M12 9c0-3 2-5 5-5 0 3-2 5-5 5Z"/><path d="M12 13c0-3 2-5 5-5 0 3-2 5-5 5Z"/><path d="M12 9c0-3-2-5-5-5 0 3 2 5 5 5Z"/><path d="M12 13c0-3-2-5-5-5 0 3 2 5 5 5Z"/></svg>',
  sweets: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="5" width="12" height="14" rx="1"/><path d="M6 9h12"/><path d="M6 13h12"/><path d="M12 5v14"/></svg>',
  seafood: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 6c-5 0-9 2.5-9 7 0 3 2 5 5 5 4 0 5-3 3-5"/><path d="M8 13c-2 0-4-1-4-3"/><path d="M17 6c1-1 2-1.5 3-1"/><circle cx="15" cy="9" r="1"/></svg>',
  napkin: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 8a7 3 0 0 1 14 0v9a7 3 0 0 1-14 0V8Z"/><path d="M5 8a7 3 0 0 0 14 0"/><path d="M12 11v6"/></svg>',
  sausage: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6c0 8 6 14 14 14 2 0 2-2 0-2C11 18 6 13 6 6c0-2-2-2-2 0Z"/><path d="M6 6 4 4"/><path d="M18 18l2 2"/></svg>',
  pepper: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 6c3 0 4 3 3 6-1.5 4.5-6 8-11 8-2 0-3-2-1-3 4-2 6-5 6-8 0-2 1-3 4-3Z"/><path d="M16 6c0-2 1-3 3-3"/></svg>',
  mushroom: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 11a8 4.5 0 0 1 16 0H4Z"/><path d="M10 11v5a2 2 0 0 0 4 0v-5"/></svg>',
  burger: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8a8 4 0 0 1 16 0H4Z"/><path d="M4 12h16"/><path d="M4 15h16"/><path d="M5 17a7 3 0 0 0 14 0"/></svg>',
  fries: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 10h12l-1 9a2 2 0 0 1-2 1.8H9A2 2 0 0 1 7 19L6 10Z"/><path d="M8 10 8 4"/><path d="M11 10 11 3"/><path d="M14 10 14 4"/><path d="M17 10 17 5"/></svg>',
  hotdog: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 15a5 5 0 0 1 5-5h6a5 5 0 0 1 0 10H9a5 5 0 0 1-5-5Z"/><path d="M7 15c2-1.5 8-1.5 10 0"/></svg>',
  corn: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21c-3-2-4-6-4-10 0-3 2-6 5-7 3 1 5 4 5 7 0 4-1 8-4 10"/><path d="M9 8v9"/><path d="M11 6l4-3"/></svg>',
  carrot: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 6 6 17c-1 1-3 1-3 1s0-2 1-3L15 4l2 2Z"/><path d="M17 6c1-2 3-2 4-1-1 2-3 2-4 1Z"/><path d="M15 4c0-2 1-3 2-3 1 1 0 3-2 3Z"/><path d="m8 13 3 3"/></svg>',
  tomato: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="14" r="7"/><path d="M12 7c-1-2-3-2.5-4-2 .5 1.5 2 2.5 4 2Z"/><path d="M12 7c1-2 3-2.5 4-2-.5 1.5-2 2.5-4 2Z"/><path d="M12 5v2"/></svg>',
  onion: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21c-4 0-7-3-7-7 0-4 4-7 7-9 3 2 7 5 7 9 0 4-3 7-7 7Z"/><path d="M9.5 11c-1 3-1 6 0 9"/><path d="M14.5 11c1 3 1 6 0 9"/><path d="M12 5V3"/></svg>',
  leaf: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 19c0-8 6-14 14-14 0 8-6 14-14 14Z"/><path d="M5 19 12 12"/></svg>',
  nuts: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4c4 0 7 3 7 7 0 4-3 9-7 9s-7-5-7-9c0-4 3-7 7-7Z"/><path d="M12 5v14"/><path d="M6 10c4 1 8 1 12 0"/></svg>',
  berries: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="15" r="4"/><circle cx="16" cy="14" r="3.5"/><path d="M9 11c0-3 2-5 5-6"/><path d="M13 4c1 0 2 .5 2.5 1.5"/></svg>',
  honey: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 9h10v9a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2V9Z"/><path d="M6 9h12l-1-3H7L6 9Z"/><path d="M12 12v5"/><path d="M12 21v2"/></svg>',
  milk: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 3h8v3l2 3v10a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V9l2-3V3Z"/><path d="M6.5 9h11"/><rect x="9" y="12" width="6" height="4" rx="1"/></svg>',
  yogurt: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 9h10l-1 10a2 2 0 0 1-2 1.8h-4A2 2 0 0 1 8 19L7 9Z"/><path d="M6 6h12v3H6z"/><path d="M10 13h4"/></svg>',
  can: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="7" y="4" width="10" height="16" rx="1"/><path d="M7 8h10"/><path d="M7 16h10"/><path d="M10 11h4"/></svg>',
  bottle: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 2h4"/><path d="M11 2v3.5c0 1-2 2-2 4V20a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2V9.5c0-2-2-3-2-4V2"/><path d="M9 13h6"/></svg>',
  jar: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8h10v10a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2V8Z"/><path d="M8 8V5a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v3"/><path d="M7 12h10"/></svg>',
  salt: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 10h8l-.6 9a1.5 1.5 0 0 1-1.5 1.4h-3.8A1.5 1.5 0 0 1 8.6 19L8 10Z"/><path d="M9 10V7a3 3 0 0 1 6 0v3"/><path d="M11 5h.01"/><path d="M13 4h.01"/></svg>',
  rice: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 5 0 0 0 16 0"/><path d="M4 12a8 5 0 0 1 16 0"/><path d="M8 8.5l1.5-2"/><path d="M12 8l1.5-2"/><path d="M16 8.5l1.5-2"/><path d="M9 18l6-2"/></svg>',
  noodles: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 11h14l-1.2 7A3 3 0 0 1 15 20H9a3 3 0 0 1-2.8-2L5 11Z"/><path d="M8 11c0-3 1-6 3-8"/><path d="M12 11c0-3 1-6 3-8"/><path d="m14 5 5-1"/></svg>',
  wine: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h10v4a5 5 0 0 1-10 0V3Z"/><path d="M7 6h10"/><path d="M12 12v7"/><path d="M8 21h8"/></svg>',
  juice: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8h10l-1 11a2 2 0 0 1-2 1.8h-4A2 2 0 0 1 8 19L7 8Z"/><path d="M7 11h10"/><path d="M14 8 16 3"/></svg>',
  icecream: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 9a4 4 0 0 1 8 0"/><path d="M8 9h8l-4 12-4-12Z"/><path d="M9 13h6"/></svg>',
  donut: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="2.8"/><path d="M7 8c1 1 1 2 2 2"/><path d="M15 7c0 1.5 1 1.5 1 3"/></svg>',
  gloves: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 12V8a1.5 1.5 0 0 1 3 0v3m0 0V6a1.5 1.5 0 0 1 3 0v5m0 0V7a1.5 1.5 0 0 1 3 0v5m0-2a1.5 1.5 0 0 1 3 0v4a6 6 0 0 1-6 6h-1a6 6 0 0 1-6-6v-2Z"/></svg>',
  gas: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 8a4 4 0 0 1 8 0v9a3 3 0 0 1-3 3h-2a3 3 0 0 1-3-3V8Z"/><path d="M10 8V5a2 2 0 0 1 4 0v3"/><path d="M8 12h8"/></svg>',
  flour: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8c-1-1-1-3 1-4h8c2 1 2 3 1 4l1 11a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L7 8Z"/><path d="M10 12l2 2 2-2"/><path d="M12 14v3"/></svg>',
};

// Ключи иконок, доступные для выбора (ссылки кабинета + поставщики). Единый набор.
export const supplierIconKeys = ['package', 'link', 'drinks', 'water', 'juice', 'coffee', 'wine', 'sweets', 'donut', 'icecream', 'vegetables', 'fruit', 'berries', 'tomato', 'carrot', 'onion', 'corn', 'mushroom', 'pepper', 'leaf', 'spice', 'salt', 'sauce', 'honey', 'oil', 'meat', 'chicken', 'sausage', 'hotdog', 'burger', 'fries', 'fish', 'seafood', 'nuts', 'dairy', 'milk', 'yogurt', 'cheese', 'egg', 'bread', 'grain', 'flour', 'rice', 'noodles', 'pizza', 'can', 'jar', 'bottle', 'frozen', 'napkin', 'gloves', 'gas', 'cleaning'];
export const cabinetLinkIconKeys = supplierIconKeys;

// Центральная палитра цветов иконок [цвет обводки, фон]. Используется через
// supplierIconStyle() как inline-стиль — чтобы не дублировать CSS по файлам.
export const SUPPLIER_ICON_COLORS = {
  package:    ['#6b3e2c', '#FAF7F4'],
  link:       ['#4f46e5', '#eef2ff'],
  drinks:     ['#0f766e', '#ecfeff'],
  water:      ['#0284c7', '#f0f9ff'],
  coffee:     ['#7c4a20', '#f5ede4'],
  sweets:     ['#9d174d', '#fdf2f8'],
  vegetables: ['#15803d', '#f0fdf4'],
  fruit:      ['#dc2626', '#fef2f2'],
  mushroom:   ['#78350f', '#f6f1ea'],
  pepper:     ['#b91c1c', '#fef2f2'],
  spice:      ['#b45309', '#fffbeb'],
  sauce:      ['#c2410c', '#fff7ed'],
  oil:        ['#ca8a04', '#fefce8'],
  meat:       ['#be123c', '#fff1f2'],
  chicken:    ['#c2410c', '#fff7ed'],
  sausage:    ['#9a3412', '#fff7ed'],
  fish:       ['#0369a1', '#f0f9ff'],
  seafood:    ['#e11d48', '#fff1f2'],
  dairy:      ['#1d4ed8', '#eff6ff'],
  cheese:     ['#d97706', '#fffbeb'],
  egg:        ['#a16207', '#fefce8'],
  bread:      ['#b45309', '#fffbeb'],
  grain:      ['#a16207', '#fefce8'],
  pizza:      ['#c2410c', '#fff7ed'],
  frozen:     ['#0891b2', '#ecfeff'],
  napkin:     ['#0f766e', '#f0fdfa'],
  cleaning:   ['#2563eb', '#eff6ff'],
  burger:     ['#b45309', '#fffbeb'],
  fries:      ['#d97706', '#fffbeb'],
  hotdog:     ['#c2410c', '#fff7ed'],
  corn:       ['#ca8a04', '#fefce8'],
  carrot:     ['#ea580c', '#fff7ed'],
  tomato:     ['#dc2626', '#fef2f2'],
  onion:      ['#a21caf', '#fdf4ff'],
  leaf:       ['#16a34a', '#f0fdf4'],
  nuts:       ['#92400e', '#fef3c7'],
  berries:    ['#9d174d', '#fdf2f8'],
  honey:      ['#b45309', '#fffbeb'],
  milk:       ['#2563eb', '#eff6ff'],
  yogurt:     ['#4f46e5', '#eef2ff'],
  can:        ['#475569', '#f1f5f9'],
  bottle:     ['#0d9488', '#f0fdfa'],
  jar:        ['#a16207', '#fefce8'],
  salt:       ['#64748b', '#f1f5f9'],
  rice:       ['#a16207', '#fefce8'],
  noodles:    ['#b45309', '#fffbeb'],
  wine:       ['#9f1239', '#fff1f2'],
  juice:      ['#ea580c', '#fff7ed'],
  icecream:   ['#db2777', '#fdf2f8'],
  donut:      ['#c026d3', '#fdf4ff'],
  gloves:     ['#2563eb', '#eff6ff'],
  gas:        ['#dc2626', '#fef2f2'],
  flour:      ['#a16207', '#fefce8'],
};

// Inline-стиль для окрашивания иконки поставщика/ссылки по ключу.
export function supplierIconStyle(key) {
  const c = SUPPLIER_ICON_COLORS[key] || SUPPLIER_ICON_COLORS.package;
  return { color: c[0], background: c[1], borderColor: 'rgba(0,0,0,.08)' };
}

// Итоговая иконка поставщика: если задан icon_key вручную — берём его,
// иначе автоподбор по названию (обратная совместимость).
// Возвращает { svg, style, className } — style задан только для ручного ключа.
export function resolveSupplierIcon(sup) {
  const key = sup && sup.icon_key;
  if (key && supplierIconSvg[key]) {
    return { svg: supplierIconSvg[key], style: supplierIconStyle(key), className: '' };
  }
  const legacy = supplierIcon(sup && sup.name);
  return { svg: legacy.svg, style: null, className: legacy.className };
}

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
  if (tabId === 'novelties') return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l2.2 5.2L19.5 10l-5.3 1.8L12 17l-2.2-5.2L4.5 10l5.3-1.8z"/><path d="M18.5 14.8l.6 1.6 1.6.6-1.6.6-.6 1.6-.6-1.6-1.6-.6 1.6-.6z"/></svg>';
  return cabIconSvg[tabId] || cabIconSvg.profile;
}
