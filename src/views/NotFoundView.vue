<template>
  <UiBrandState chip="Ошибка 404" standalone>
    <template #art>
      <!-- Тот же бургер, что на странице «нет доступа»: кадр из ролика, снятый
           до появления замка. Раньше здесь была нарисованная плоская картинка,
           и две соседние страницы выглядели как из разных продуктов.
           Бейдж лупы посажен в те же координаты, что бейдж замка в ролике
           (центр 71,6% / 28,1%, диаметр 35%) — измерено по кадру. -->
      <div class="nf-art">
        <img src="/not-found.jpg" alt="" />
        <!-- Бейдж завёрнут в div: общее правило оболочки задаёт ширину любому
             svg внутри слота и перебивало размер лупы — она раздувалась. -->
        <div class="nf-badge">
        <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
          <circle cx="50" cy="50" r="50" fill="var(--brand-red-clip)" />
          <circle cx="44" cy="44" r="19" fill="none" stroke="var(--brand-cream)" stroke-width="9" />
          <path d="M58 58 74 74" stroke="var(--brand-cream)" stroke-width="11" stroke-linecap="round" />
        </svg>
        </div>
      </div>
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

<style scoped>
/* Ширину задаём здесь, а не в общей оболочке: обёртка нужна только этой
   странице, и без ограничения бейдж считал проценты от всей колонки —
   лупа раздувалась на пол-карточки. */
.nf-art { position: relative; width: 100%; max-width: 260px; }
.nf-art img { width: 100%; height: auto; display: block; }
@media (max-width: 760px) { .nf-art { max-width: 168px; } }
.nf-badge {
  position: absolute;
  left: 54.1%;
  top: 11%;
  width: 35%;
  line-height: 0;
}
.nf-badge svg { width: 100%; height: auto; display: block; }
</style>
