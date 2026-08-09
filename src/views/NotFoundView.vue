<template>
  <UiBrandState chip="Ошибка 404" standalone>
    <template #art>
      <!-- Верхняя булка сдвинута и наклонена, под ней пусто, вниз сыплются
           крошки. Читается как «подняли — а начинки нет». -->
      <!-- Та же геометрия бургера, что на странице отказа: она проверена и
           читается. Отличие — бейдж с лупой: «искали и не нашли». -->
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

        <!-- лупа-бейдж; кремовое кольцо отделяет его от булки -->
        <circle cx="162" cy="42" r="38" fill="var(--brand-cream)" />
        <circle cx="162" cy="42" r="32" fill="var(--brand-red)" />
        <circle cx="158" cy="38" r="11" fill="none" stroke="var(--brand-cream)" stroke-width="5.5" />
        <path d="M166 46 176 56" stroke="var(--brand-cream)" stroke-width="7" stroke-linecap="round" />
      </svg>
    </template>

    <template #title>Такой страницы<br />не нашлось</template>

    Ссылка устарела или в адресе опечатка. Проверьте адрес — или вернитесь
    туда, откуда пришли.

    <template #actions>
      <button class="ubs-btn ubs-btn-primary" @click="goBack">Вернуться назад</button>
      <router-link :to="{ name: 'home' }" class="ubs-btn">На главную</router-link>
    </template>
  </UiBrandState>
</template>

<script setup>
import { useRouter } from 'vue-router';
import UiBrandState from '@/components/ui/UiBrandState.vue';

const router = useRouter();

function goBack() {
  if (window.history.length > 1) router.back();
  else router.push({ name: 'home' });
}
</script>
