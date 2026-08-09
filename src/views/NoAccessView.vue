<template>
  <div class="na">
    <div class="na-wrap">
      <!-- Замок: смысловая графика, а не украшение. Дужка защёлкивается,
           корпус обводится, из скважины расходятся волны. -->
      <div class="na-art" aria-hidden="true">
        <!-- viewBox обрезан по самому замку: иначе фигура занимала треть кадра
             и выглядела мелкой. Волны выходят за границы — overflow: visible. -->
        <svg class="na-lock" viewBox="46 28 128 152" fill="none" xmlns="http://www.w3.org/2000/svg">
          <circle class="na-ring na-ring-1" cx="110" cy="132" r="58" />
          <circle class="na-ring na-ring-2" cx="110" cy="132" r="58" />

          <path class="na-shackle" d="M78 96V72a32 32 0 0 1 64 0v24" />

          <rect class="na-body-fill" x="58" y="94" width="104" height="82" rx="18" />
          <rect class="na-body" x="58" y="94" width="104" height="82" rx="18" />

          <g class="na-hole">
            <circle cx="110" cy="126" r="10" />
            <path d="M110 136 106 152h8Z" />
          </g>
        </svg>
      </div>

      <div class="na-content">
        <span class="na-status" style="--i: 0">Доступ закрыт</span>

        <h1 class="na-title" style="--i: 1">
          <!-- Пробел перед переносом обязателен: на мобильном <br> скрыт,
               и без него получалось «Аналитика»вам». -->
          <template v-if="sectionLabel">Раздел «{{ sectionLabel }}» <br />вам пока не открыт</template>
          <template v-else>Этот раздел вам пока не открыт</template>
        </h1>

        <p class="na-text" style="--i: 2">
          Так и задумано: доступ к разделам выдаёт администратор портала.
          Напишите
          <a class="na-link" :href="`https://t.me/${support}`" target="_blank" rel="noopener">@{{ support }}</a>
          и скажите, какой раздел нужен для работы.
        </p>

        <div class="na-actions" style="--i: 3">
          <button class="na-btn na-btn-primary" @click="goBack">Вернуться назад</button>
          <router-link v-if="homeRoute" :to="{ name: homeRoute }" class="na-btn">На главную</router-link>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * Честный ответ вместо молчаливого редиректа.
 *
 * Раньше человек без прав на раздел просто оказывался на «Новом заказе» —
 * без объяснения. Выглядело как поломка портала: жмёшь ссылку, попадаешь
 * не туда. Теперь роутер ведёт сюда и передаёт ключ раздела в ?section=.
 */
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useUserStore } from '@/stores/userStore.js';
import { useSupportContact } from '@/lib/supportContact.js';
import { ALL_NAV_ITEMS } from '@/lib/navSections.js';

const route = useRoute();
const router = useRouter();
const userStore = useUserStore();
const support = useSupportContact();

const sectionLabel = computed(() => {
  const key = String(route.query.section || '');
  if (!key) return '';
  const item = ALL_NAV_ITEMS.find(i => i.module === key || i.route === key);
  return item?.label || '';
});

// «На главную» ведём на первый открытый раздел: у людей с урезанными правами
// обычного дашборда может не быть, и кнопка утыкалась бы в тот же отказ.
const homeRoute = computed(() => {
  const item = ALL_NAV_ITEMS.find(i => i.module && i.route && userStore.hasAccess(i.module, 'view'));
  return item?.route || null;
});

function goBack() {
  if (window.history.length > 1) router.back();
  else if (homeRoute.value) router.push({ name: homeRoute.value });
}
</script>

<style scoped>
.na {
  display: flex; align-items: center;
  min-height: 68vh;
  padding: var(--tk-s-7) var(--tk-s-5);
}
.na-wrap {
  display: grid;
  grid-template-columns: 230px minmax(0, 1fr);
  align-items: center;
  gap: var(--tk-s-7);
  width: 100%;
  max-width: 780px;
  margin: 0 auto;
}

/* ── Графика ── */
.na-art { display: flex; justify-content: center; }
.na-lock { width: 100%; max-width: 220px; height: auto; overflow: visible; }

.na-ring {
  fill: none;
  stroke: var(--tk-accent);
  stroke-width: 1.5;
  opacity: 0;
  transform-box: fill-box;
  transform-origin: center;
  animation: na-pulse 3.6s cubic-bezier(.22, .61, .36, 1) infinite;
}
.na-ring-2 { animation-delay: 1.8s; }

.na-shackle {
  fill: none;
  stroke: var(--tk-n-400);
  stroke-width: 13;
  stroke-linecap: round;
  transform-box: fill-box;
  transform-origin: center bottom;
  animation: na-shackle-lock 700ms cubic-bezier(.34, 1.4, .64, 1) both;
  animation-delay: 120ms;
}

.na-body-fill {
  fill: var(--tk-accent-soft);
  opacity: 0;
  animation: na-fade 400ms ease both;
  animation-delay: 640ms;
}
.na-body {
  fill: none;
  stroke: var(--tk-accent);
  stroke-width: 4;
  stroke-linejoin: round;
  stroke-dasharray: 380;
  stroke-dashoffset: 380;
  animation: na-draw 900ms cubic-bezier(.65, 0, .35, 1) both;
  animation-delay: 300ms;
}

.na-hole {
  fill: var(--tk-accent-text);
  opacity: 0;
  transform-box: fill-box;
  transform-origin: center;
  animation: na-pop 420ms cubic-bezier(.34, 1.5, .64, 1) both;
  animation-delay: 980ms;
}

@keyframes na-shackle-lock {
  from { transform: translateY(-16px) scaleY(1.12); opacity: 0; }
  to   { transform: translateY(0) scaleY(1); opacity: 1; }
}
@keyframes na-draw {
  from { stroke-dashoffset: 380; }
  to   { stroke-dashoffset: 0; }
}
@keyframes na-fade  { from { opacity: 0; } to { opacity: 1; } }
@keyframes na-pop   { from { opacity: 0; transform: scale(.2); } to { opacity: 1; transform: scale(1); } }
@keyframes na-pulse {
  0%   { opacity: 0;   transform: scale(.62); }
  22%  { opacity: .38; }
  100% { opacity: 0;   transform: scale(1.35); }
}

/* ── Текст ── */
.na-content > * {
  opacity: 0;
  animation: na-rise 460ms cubic-bezier(.22, .61, .36, 1) both;
  animation-delay: calc(360ms + var(--i, 0) * 90ms);
}
@keyframes na-rise {
  from { opacity: 0; transform: translateY(10px); }
  to   { opacity: 1; transform: translateY(0); }
}

.na-status {
  display: inline-block;
  padding: var(--tk-s-1) var(--tk-s-3);
  border-radius: var(--tk-r-pill);
  background: var(--tk-accent-soft);
  color: var(--tk-accent-text);
  font-size: var(--tk-fz-xs);
  font-weight: var(--tk-fw-bold);
  letter-spacing: .06em;
  text-transform: uppercase;
}
.na-title {
  font-family: 'Flame', var(--tk-font);
  font-size: var(--tk-fz-display);
  font-weight: var(--tk-fw-bold);
  line-height: var(--tk-lh-tight);
  color: var(--tk-text);
  margin: var(--tk-s-4) 0 var(--tk-s-3);
}
.na-text {
  font-size: var(--tk-fz-lg);
  line-height: var(--tk-lh-loose);
  color: var(--tk-text-secondary);
  margin: 0 0 var(--tk-s-6);
  max-width: 46ch;
}
.na-link {
  color: var(--tk-accent-text);
  font-weight: var(--tk-fw-semibold);
  text-decoration: none;
  border-bottom: 1.5px solid var(--tk-accent-soft-strong);
  transition: border-color var(--tk-transition);
}
.na-link:hover { border-bottom-color: var(--tk-accent); }
.na-link:focus-visible { outline: none; box-shadow: var(--tk-focus-ring); border-radius: var(--tk-r-sm); }

.na-actions { display: flex; flex-wrap: wrap; gap: var(--tk-s-2); }
.na-btn {
  display: inline-flex; align-items: center; justify-content: center;
  min-height: var(--tk-touch-min);
  padding: var(--tk-s-2) var(--tk-s-5);
  border: 1.5px solid var(--tk-border);
  border-radius: var(--tk-r-md);
  background: var(--tk-bg-card);
  color: var(--tk-text-secondary);
  font-family: inherit;
  font-size: var(--tk-fz-lg);
  font-weight: var(--tk-fw-semibold);
  text-decoration: none;
  cursor: pointer;
  transition: transform var(--tk-transition), border-color var(--tk-transition),
              background var(--tk-transition), color var(--tk-transition),
              box-shadow var(--tk-transition);
}
.na-btn:hover { transform: translateY(-1px); border-color: var(--tk-accent); color: var(--tk-accent-text); }
.na-btn:focus-visible { outline: none; box-shadow: var(--tk-focus-ring); }
.na-btn-primary {
  border-color: var(--tk-accent);
  background: var(--tk-accent);
  color: var(--tk-n-0);
  box-shadow: var(--tk-shadow-card);
}
.na-btn-primary:hover {
  background: var(--tk-accent-hover);
  border-color: var(--tk-accent-hover);
  color: var(--tk-n-0);
  box-shadow: var(--tk-shadow-card-hover);
}

@media (max-width: 720px) {
  .na { min-height: auto; padding: var(--tk-s-6) var(--tk-s-4); }
  .na-wrap { grid-template-columns: 1fr; gap: var(--tk-s-5); justify-items: start; }
  .na-lock { max-width: 132px; }
  .na-title { font-size: var(--tk-fz-h2); }
  .na-title br { display: none; }
  .na-actions { width: 100%; }
  .na-btn { flex: 1 1 auto; }
}

/* Правило дизайн-системы: анимация не навязывается тем, кто её отключил. */
@media (prefers-reduced-motion: reduce) {
  .na-ring { animation: none; opacity: 0; }
  .na-shackle, .na-body, .na-body-fill, .na-hole, .na-content > * {
    animation: none;
    opacity: 1;
    transform: none;
    stroke-dashoffset: 0;
  }
  .na-btn:hover { transform: none; }
}
</style>
