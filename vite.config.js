import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { VitePWA } from 'vite-plugin-pwa';
import { resolve } from 'path';
import { existsSync, readdirSync, rmSync } from 'fs';
import { join } from 'path';

function cleanupCopiedLegacyPublicAssets() {
  let outDir = 'dist';
  return {
    name: 'cleanup-copied-legacy-public-assets',
    configResolved(config) {
      outDir = config.build.outDir;
    },
    writeBundle() {
      const sourceDir = resolve(__dirname, 'public/assets');
      const targetDir = resolve(__dirname, outDir, 'assets');
      if (!existsSync(sourceDir) || !existsSync(targetDir)) return;
      for (const file of readdirSync(sourceDir)) {
        const target = join(targetDir, file);
        if (existsSync(target)) rmSync(target, { force: true });
      }
    },
  };
}

export default defineConfig({
  plugins: [
    vue(),
    cleanupCopiedLegacyPublicAssets(),
    VitePWA({
      // 'autoUpdate': новый SW моментально захватывает старые вкладки.
      // Если старый JS попытается загрузить чанк, которого больше нет, —
      // main.js ловит «Failed to fetch dynamically imported module» и
      // показывает баннер «Доступна новая версия» через UpdatePrompt.
      // Это страхует UX, но при этом обычные пользователи без действий
      // получают свежую версию сразу (а не висят на старом index.html
      // из SW-кэша, как было в режиме 'prompt').
      registerType: 'autoUpdate',
      manifest: {
        name: 'Supply Department — Отдел закупок',
        short_name: 'Закупки',
        description: 'Портал закупок',
        theme_color: '#502314',
        background_color: '#FAF6EF',
        display: 'standalone',
        lang: 'ru',
        icons: [
          { src: '/pwa-192x192.png', sizes: '192x192', type: 'image/png' },
          { src: '/pwa-512x512.png', sizes: '512x512', type: 'image/png' },
          { src: '/pwa-512x512.png', sizes: '512x512', type: 'image/png', purpose: 'any maskable' },
        ],
      },
      workbox: {
        // skipWaiting + clientsClaim: новый SW активируется сразу при
        // обнаружении обновления и захватывает уже открытые вкладки.
        // Защита от поломки старых вкладок — обработчик ошибок чанков
        // в main.js, который поднимает UpdatePrompt-баннер.
        skipWaiting: true,
        clientsClaim: true,
        cleanupOutdatedCaches: true,
        globPatterns: ['**/*.{js,css,html,ico,png,svg,otf,woff,woff2}'],
        globIgnores: [
          '**/xlsx-*.js',
          '**/excelExport-*.js',
          '**/protocolExport-*.js',
          '**/useFormDirty-*.js',
          '**/zxing-*.js',
          '**/ScannerView-*.js',
          '**/ScannerView-*.css',
          '**/BarcodeScanner-*.js',
          '**/BarcodeScanner-*.css',
          '**/AbcXyzView-*.js',
          '**/AbcXyzView-*.css',
          '**/MarketingGantt-*.js',
          '**/MarketingGantt-*.css',
          '**/SupplierOrdersManagerView-*.js',
          '**/SupplierOrdersManagerView-*.css',
          '**/*Modal-*.js',
          '**/*Modal-*.css',
          'cda-*.html',
          'presentation.html',
          'sidebar-variants.html',
          'faq-for-management.html',
          'maintenance.html',
          'mockups/**',
          'edi-autofill.user.js',
        ],
        navigateFallback: 'index.html',
        navigateFallbackDenylist: [/^\/api\//],
        runtimeCaching: [
          {
            urlPattern: /\/api\/uploads\//,
            handler: 'NetworkOnly',
          },
          {
            // Справочники — кэшируем для офлайн-работы. Не содержат личных
            // данных пользователя, поэтому остаются после logout без риска.
            urlPattern: /\/api\/(products|suppliers|restaurants|settings|delivery_schedule|cards)(\?|$)/,
            handler: 'StaleWhileRevalidate',
            options: {
              cacheName: 'api-reference-data',
              expiration: { maxEntries: 50, maxAgeSeconds: 24 * 60 * 60 },
            },
          },
          {
            // Все остальные API — НЕ кэшируем. Иначе после logout в браузере
            // остаются чужие заказы, чаты, юзеры, оплаты и т.п. Это перекрывает
            // утечку данных при смене аккаунта на одном устройстве и при выходе.
            urlPattern: /\/api\//,
            handler: 'NetworkOnly',
          },
          {
            urlPattern: /\.(?:png|svg|otf|woff|woff2)$/,
            handler: 'CacheFirst',
            options: {
              cacheName: 'static-assets',
              expiration: { maxEntries: 100, maxAgeSeconds: 30 * 24 * 60 * 60 },
            },
          },
        ],
      },
    }),
  ],
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
    },
  },
  build: {
    target: 'es2020',
    rollupOptions: {
      output: {
        manualChunks: {
          'vue-vendor': ['vue', 'vue-router', 'pinia'],
          'xlsx': ['xlsx-js-style'],
          'zxing': ['@zxing/browser'],
        },
      },
    },
  },
  optimizeDeps: {
    include: ['vue', 'vue-router', 'pinia'],
  },
});
