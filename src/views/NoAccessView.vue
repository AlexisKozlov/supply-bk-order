<template>
  <UiBrandState chip="Стоп-лист">
    <template #art>
      <!-- Порядок слоёв снизу вверх, как в реальном бургере. Котлета
           нарисована ДО сыра: так уголки сыра свисают поверх неё и слой
           читается сыром, а не непонятной жёлтой полосой. -->
      <svg viewBox="0 0 200 196" xmlns="http://www.w3.org/2000/svg">
        <path d="M18 92A82 58 0 0 1 182 92Z" fill="var(--brand-bun)" />
        <ellipse cx="72" cy="62" rx="7" ry="4.6" fill="var(--brand-cream)" transform="rotate(-14 72 62)" />
        <ellipse cx="102" cy="51" rx="7" ry="4.6" fill="var(--brand-cream)" transform="rotate(6 102 51)" />
        <ellipse cx="132" cy="64" rx="7" ry="4.6" fill="var(--brand-cream)" transform="rotate(16 132 64)" />
        <ellipse cx="88" cy="76" rx="7" ry="4.6" fill="var(--brand-cream)" transform="rotate(-6 88 76)" />
        <ellipse cx="118" cy="77" rx="7" ry="4.6" fill="var(--brand-cream)" transform="rotate(10 118 77)" />

        <path d="M16 96c8-11 16-11 24 0 8-11 16-11 24 0 8-11 16-11 24 0 8-11 16-11 24 0 8-11 16-11 24 0 8-11 16-11 24 0 8-11 16-11 24 0v10H16Z"
              fill="var(--brand-green)" />
        <rect x="24" y="106" width="152" height="16" rx="8" fill="var(--brand-red)" />
        <rect x="18" y="136" width="164" height="26" rx="13" fill="var(--brand-brown)" />
        <path d="M28 122h144v8l-15 17-15-12-15 15-15-15-15 12-15-17Z" fill="var(--brand-yellow)" />
        <path d="M26 162h148v12c0 8-6 14-14 14H40c-8 0-14-6-14-14Z" fill="var(--brand-bun)" />

        <!-- замок-бейдж; кремовое кольцо отделяет его от булки -->
        <circle cx="162" cy="42" r="38" fill="var(--brand-cream)" />
        <circle cx="162" cy="42" r="32" fill="var(--brand-red)" />
        <path d="M153 37v-6a9 9 0 0 1 18 0v6" fill="none" stroke="var(--brand-cream)"
              stroke-width="6" stroke-linecap="round" />
        <rect x="148" y="37" width="28" height="23" rx="6" fill="var(--brand-cream)" />
        <circle cx="162" cy="46" r="3.8" fill="var(--brand-red)" />
        <path d="M162 49.4 159.6 56.5h4.8Z" fill="var(--brand-red)" />
      </svg>
    </template>

    <template #title>
      <template v-if="sectionLabel">«{{ sectionLabel }}»<br />не для вас</template>
      <template v-else>Этот раздел не для вас</template>
    </template>

    Доступ к разделам выдаёт администратор портала. Напишите
    <a :href="`https://t.me/${support}`" target="_blank" rel="noopener">@{{ support }}</a>
    и скажите, что нужно для работы — включит.

    <template #actions>
      <button class="ubs-btn ubs-btn-primary" @click="goBack">Вернуться назад</button>
      <router-link v-if="homeRoute" :to="{ name: homeRoute }" class="ubs-btn">На главную</router-link>
    </template>
  </UiBrandState>
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
import UiBrandState from '@/components/ui/UiBrandState.vue';

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
