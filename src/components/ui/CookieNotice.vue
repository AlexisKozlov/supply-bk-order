<template>
  <Transition name="cookie-fade">
    <div v-if="visible" class="cookie" role="dialog" aria-label="Использование cookie">
      <div class="cookie-text">
        Портал использует только технические cookie — они держат вход в систему и
        помнят выбранное юрлицо. Рекламных и следящих нет. Подробнее — в
        <router-link to="/data-rules">правилах обработки данных</router-link>.
      </div>
      <button class="cookie-btn" @click="accept">Понятно</button>
    </div>
  </Transition>
</template>

<script setup>
/**
 * Одноразовое уведомление об использовании cookie.
 *
 * Кнопки «отклонить» нет намеренно: без cookie вход в портал не работает
 * вообще — это не выбор, а условие работы. Поэтому не спрашиваем согласия,
 * которое нельзя не дать, а честно сообщаем один раз.
 *
 * Ответ храним в localStorage, а не в cookie: иначе уведомление о cookie
 * само ставило бы cookie до того, как человек его прочитал.
 */
import { ref, onMounted } from 'vue';

const KEY = 'sd_cookie_notice_v1';
const visible = ref(false);

onMounted(() => {
  try {
    if (localStorage.getItem(KEY) !== '1') visible.value = true;
  } catch {
    // Приватный режим браузера: показывать нечего — молча пропускаем.
  }
});

function accept() {
  visible.value = false;
  try { localStorage.setItem(KEY, '1'); } catch { /* см. выше */ }
}
</script>

<style scoped>
.cookie {
  position: fixed;
  left: 16px; right: 16px; bottom: 16px;
  z-index: 9000;
  max-width: 720px;
  margin: 0 auto;
  display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
  padding: 14px 18px;
  background: rgba(28, 16, 8, .96);
  border: 1px solid rgba(245, 230, 208, .14);
  border-radius: 16px;
  box-shadow: 0 12px 40px rgba(0, 0, 0, .45);
  backdrop-filter: blur(10px);
}
.cookie-text {
  flex: 1; min-width: 220px;
  font-size: 13px; line-height: 1.5;
  color: rgba(245, 230, 208, .72);
}
.cookie-text a { color: #F4A261; font-weight: 700; text-decoration: none; }
.cookie-text a:hover { text-decoration: underline; }
.cookie-btn {
  flex-shrink: 0;
  min-height: 40px; padding: 10px 24px;
  border: 0; border-radius: 999px;
  background: var(--brand-red, #D62300); color: #fff;
  font-family: inherit; font-size: 14px; font-weight: 700; cursor: pointer;
}
.cookie-btn:hover { background: #B52200; }
.cookie-btn:focus-visible { outline: 2px solid #FF8732; outline-offset: 2px; }

.cookie-fade-enter-active, .cookie-fade-leave-active { transition: opacity .25s ease, transform .25s ease; }
.cookie-fade-enter-from, .cookie-fade-leave-to { opacity: 0; transform: translateY(12px); }

@media (max-width: 560px) {
  .cookie { flex-direction: column; align-items: stretch; text-align: center; gap: 12px; }
  .cookie-btn { width: 100%; }
}
</style>
