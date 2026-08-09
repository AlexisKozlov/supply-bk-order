<template>
  <UiBrandState chip="Стоп-лист">
    <template #art>
      <!-- Ролик проигрывается один раз и замирает на кадре с замком: на этой
           странице человек читает текст и ищет кнопку, вечное движение сбоку
           мешает. Первым кадром — тот же последний кадр, поэтому подмены не
           видно. На телефонах и при отключённой анимации только картинка:
           видео там лишний трафик. -->
      <video v-if="showVideo" class="na-video" poster="/no-access.jpg"
             autoplay muted playsinline preload="metadata">
        <source src="/no-access.mp4" type="video/mp4" />
      </video>
      <img v-else src="/no-access.jpg" alt="" />
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

// Видео — только на большом экране и только если человек не выключил анимации
// в системе. Считаем один раз при открытии: страница живёт секунды, следить за
// поворотом телефона незачем.
const showVideo = window.matchMedia('(min-width: 761px)').matches
  && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

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
