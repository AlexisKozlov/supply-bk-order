<template>
  <div class="na">
    <div class="na-card">
      <div class="na-art" aria-hidden="true">
        <!-- Плоская бренд-иллюстрация: бургер и замок-бейдж.
             Без обводок и градиентов — так рисует сам бренд. -->
        <svg class="na-burger" viewBox="0 0 200 186" xmlns="http://www.w3.org/2000/svg">
          <!-- верхняя булка -->
          <path d="M18 96A82 60 0 0 1 182 96Z" fill="var(--bk-bun)" />
          <ellipse cx="72" cy="66" rx="7" ry="4.6" fill="var(--bk-cream)" transform="rotate(-14 72 66)" />
          <ellipse cx="102" cy="55" rx="7" ry="4.6" fill="var(--bk-cream)" transform="rotate(6 102 55)" />
          <ellipse cx="132" cy="68" rx="7" ry="4.6" fill="var(--bk-cream)" transform="rotate(16 132 68)" />
          <ellipse cx="88" cy="80" rx="7" ry="4.6" fill="var(--bk-cream)" transform="rotate(-6 88 80)" />
          <ellipse cx="118" cy="81" rx="7" ry="4.6" fill="var(--bk-cream)" transform="rotate(10 118 81)" />

          <!-- салат -->
          <path d="M16 100c8-11 16-11 24 0 8-11 16-11 24 0 8-11 16-11 24 0 8-11 16-11 24 0 8-11 16-11 24 0 8-11 16-11 24 0 8-11 16-11 24 0v10H16Z"
                fill="var(--bk-green)" />

          <!-- сыр -->
          <path d="M26 110h148v10l-16 16-14-10-16 12-16-12-14 10-16-16Z" fill="var(--bk-yellow)" />

          <!-- котлета -->
          <rect x="18" y="126" width="164" height="24" rx="12" fill="var(--bk-brown)" />

          <!-- нижняя булка -->
          <path d="M26 152h148v10c0 8-6 14-14 14H40c-8 0-14-6-14-14Z" fill="var(--bk-bun)" />

          <!-- замок-бейдж -->
          <circle cx="160" cy="42" r="34" fill="var(--bk-red)" />
          <path d="M150 36v-6a10 10 0 0 1 20 0v6" fill="none" stroke="var(--bk-cream)"
                stroke-width="6.5" stroke-linecap="round" />
          <rect x="145" y="36" width="30" height="24" rx="6" fill="var(--bk-cream)" />
          <circle cx="160" cy="46" r="4" fill="var(--bk-red)" />
          <path d="M160 49.5 157.5 57h5Z" fill="var(--bk-red)" />
        </svg>
      </div>

      <div class="na-content">
        <span class="na-stop">Стоп-лист</span>

        <h1 class="na-title">
          <template v-if="sectionLabel">«{{ sectionLabel }}»<br />не для вас</template>
          <template v-else>Этот раздел не для вас</template>
        </h1>

        <p class="na-text">
          Доступ к разделам выдаёт администратор портала. Напишите
          <a class="na-link" :href="`https://t.me/${support}`" target="_blank" rel="noopener">@{{ support }}</a>
          и скажите, что нужно для работы — включит.
        </p>

        <div class="na-actions">
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
 *
 * «Стоп-лист» — слово из ресторана: так называют позицию, которой сейчас
 * нет. Аудитория портала поймёт мгновенно.
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
  display: flex; align-items: center; justify-content: center;
  min-height: 72vh;
  padding: var(--tk-s-6) var(--tk-s-4);
}

/* Кремовая плашка — фирменный фон, а не белый лист интерфейса. */
.na-card {
  display: grid;
  grid-template-columns: 260px minmax(0, 1fr);
  align-items: center;
  gap: var(--tk-s-7);
  width: 100%;
  max-width: 860px;
  padding: var(--tk-s-7);
  border-radius: 28px;
  background: var(--bk-cream);
}

.na-art { display: flex; justify-content: center; }
.na-burger { width: 100%; max-width: 260px; height: auto; display: block; }

/* Плашка «Стоп-лист» — слово из ресторанной кухни. */
.na-stop {
  display: inline-block;
  padding: 6px var(--tk-s-4);
  border-radius: var(--tk-r-pill);
  background: var(--bk-red);
  color: var(--bk-cream);
  font-family: 'Flame', var(--tk-font);
  font-size: var(--tk-fz-md);
  font-weight: var(--tk-fw-bold);
  letter-spacing: .1em;
  text-transform: uppercase;
}

.na-title {
  font-family: 'Flame', var(--tk-font);
  font-size: var(--tk-fz-hero);
  font-weight: var(--tk-fw-bold);
  line-height: 1.02;
  letter-spacing: -.01em;
  color: var(--bk-brown);
  margin: var(--tk-s-4) 0 var(--tk-s-4);
  text-transform: uppercase;
}

.na-text {
  font-size: var(--tk-fz-xl);
  line-height: var(--tk-lh-base);
  color: var(--bk-brown);
  margin: 0 0 var(--tk-s-6);
  max-width: 42ch;
}
.na-link {
  color: var(--bk-red);
  font-weight: var(--tk-fw-bold);
  text-decoration: none;
  box-shadow: inset 0 -2px 0 currentColor;
}
.na-link:focus-visible { outline: none; box-shadow: var(--tk-focus-ring); border-radius: var(--tk-r-sm); }

.na-actions { display: flex; flex-wrap: wrap; gap: var(--tk-s-3); }
.na-btn {
  display: inline-flex; align-items: center; justify-content: center;
  min-height: var(--tk-touch-min);
  padding: var(--tk-s-3) var(--tk-s-6);
  border: 2.5px solid var(--bk-brown);
  border-radius: var(--tk-r-pill);
  background: transparent;
  color: var(--bk-brown);
  font-family: 'Flame', var(--tk-font);
  font-size: var(--tk-fz-xl);
  font-weight: var(--tk-fw-bold);
  text-decoration: none;
  cursor: pointer;
  transition: background var(--tk-transition), color var(--tk-transition),
              border-color var(--tk-transition);
}
.na-btn:hover { background: var(--bk-brown); color: var(--bk-cream); }
.na-btn:focus-visible { outline: none; box-shadow: var(--tk-focus-ring); }
.na-btn-primary {
  border-color: var(--bk-red);
  background: var(--bk-red);
  color: var(--bk-cream);
}
.na-btn-primary:hover { background: var(--bk-brown); border-color: var(--bk-brown); }

@media (max-width: 760px) {
  .na { min-height: auto; padding: var(--tk-s-4) var(--tk-s-3); }
  .na-card {
    grid-template-columns: 1fr;
    gap: var(--tk-s-5);
    padding: var(--tk-s-6) var(--tk-s-5);
    border-radius: 22px;
  }
  .na-burger { max-width: 168px; }
  .na-title { font-size: var(--tk-fz-display); }
  .na-title br { display: none; }
  .na-text { font-size: var(--tk-fz-lg); }
  .na-actions { width: 100%; }
  .na-btn { flex: 1 1 auto; }
}
</style>
