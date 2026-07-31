/**
 * Два приложения на одном сайте.
 *
 * Портал закупок и кабинет ресторана устанавливаются как РАЗНЫЕ приложения:
 * своё имя, своя иконка, свой стартовый экран и свои быстрые действия.
 * Технически это разные манифесты, и нужный подставляется по адресу страницы:
 * пока человек в /restaurant/* — браузер видит манифест кабинета, во всём
 * остальном портале — манифест закупок.
 *
 * Почему так, а не один общий: у общего манифеста стартовый адрес «/», и
 * ресторан, установив приложение, попадал бы на экран входа закупок.
 *
 * Вызывается из router.afterEach (см. src/router/index.js).
 */

const PORTAL = {
  manifest: '/manifest.webmanifest',
  appleIcon: '/apple-touch-icon-180x180.png',
  appleTitle: 'Закупки',
};

const RESTAURANT = {
  manifest: '/manifest-restaurant.webmanifest',
  appleIcon: '/apple-touch-icon-rest-180x180.png',
  appleTitle: 'Кабинет',
};

export function syncPwaIdentity(path) {
  if (typeof document === 'undefined') return;
  const isRestaurant = String(path || '').startsWith('/restaurant');
  const cfg = isRestaurant ? RESTAURANT : PORTAL;
  try {
    // В собранном index.html тег манифеста встречается дважды (свой + от
    // vite-plugin-pwa), поэтому обновляем все.
    document.querySelectorAll('link[rel="manifest"]').forEach((link) => {
      if (link.getAttribute('href') !== cfg.manifest) link.setAttribute('href', cfg.manifest);
    });
    document.querySelectorAll('link[rel="apple-touch-icon"]').forEach((link) => {
      if (link.getAttribute('href') !== cfg.appleIcon) link.setAttribute('href', cfg.appleIcon);
    });
    const title = document.querySelector('meta[name="apple-mobile-web-app-title"]');
    if (title && title.getAttribute('content') !== cfg.appleTitle) {
      title.setAttribute('content', cfg.appleTitle);
    }
  } catch (e) { /* не критично: без этого просто установится «общее» приложение */ }
}
