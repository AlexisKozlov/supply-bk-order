<template>
  <!-- Заглушка: человек отказался от cookie. Портал без них не работает —
       вход держится именно на них, поэтому вместо неработающего интерфейса
       показываем честный экран с возможностью передумать. -->
  <div v-if="state === 'declined' && route.name !== 'data-rules'" class="cookie-block" role="alertdialog" aria-label="Требуются cookie">
    <div class="cookie-block-card">
      <div class="cookie-block-mark"><SupplyLogo :size="64" /></div>
      <h1 class="cookie-block-title">Портал не сможет работать</h1>
      <p class="cookie-block-text">
        Вы отказались от cookie. Портал держит на них вход в систему: без cookie
        нельзя ни войти, ни остаться в системе после перехода между страницами.
      </p>
      <p class="cookie-block-text">
        Рекламных и следящих cookie здесь нет — только технические. Что именно
        хранится, написано в
        <router-link to="/data-rules">правилах обработки данных</router-link>.
      </p>
      <div class="cookie-block-actions">
        <button class="cookie-btn" @click="accept">Принять и продолжить</button>
        <a class="cookie-block-leave" href="https://burgerking.by" rel="noopener">Закрыть портал</a>
      </div>
    </div>
  </div>

  <!-- Обычное уведомление, до выбора -->
  <Transition name="cookie-fade">
    <div v-if="state === 'ask'" class="cookie" role="dialog" aria-label="Использование cookie">
      <div class="cookie-text">
        Портал использует только технические cookie — они держат вход в систему и
        помнят выбранное юрлицо. Рекламных и следящих нет. Подробнее — в
        <router-link to="/data-rules">правилах обработки данных</router-link>.
      </div>
      <div class="cookie-actions">
        <button class="cookie-btn" @click="accept">Принять</button>
        <button class="cookie-btn-ghost" @click="decline">Отклонить</button>
      </div>
    </div>
  </Transition>
</template>

<script setup>
/**
 * Уведомление об использовании cookie и заглушка при отказе.
 *
 * Состояния: 'hidden' — выбор уже сделан в пользу cookie, 'ask' — спрашиваем,
 * 'declined' — человек отказался, показываем заглушку вместо портала.
 *
 * Ответ храним в localStorage, а не в cookie: иначе уведомление о cookie
 * само ставило бы cookie до того, как человек его прочитал.
 */
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import SupplyLogo from '@/components/ui/SupplyLogo.vue';

// На странице правил заглушку не показываем: иначе ссылка «читайте правила»
// вела бы на экран, который сама же и закрывает.
const route = useRoute();

const KEY = 'sd_cookie_notice_v1';
const state = ref('hidden');

onMounted(() => {
  try {
    const saved = localStorage.getItem(KEY);
    if (saved === '1') state.value = 'hidden';
    else if (saved === 'no') state.value = 'declined';
    else state.value = 'ask';
  } catch {
    // Приватный режим браузера: спрашивать негде — работаем как обычно.
    state.value = 'hidden';
  }
});

function accept() {
  state.value = 'hidden';
  try { localStorage.setItem(KEY, '1'); } catch { /* см. выше */ }
}

function decline() {
  state.value = 'declined';
  try { localStorage.setItem(KEY, 'no'); } catch { /* см. выше */ }
}
</script>

<style scoped>
/* ── Уведомление ── */
.cookie {
  position: fixed;
  left: 16px; right: 16px; bottom: 16px;
  z-index: 9000;
  max-width: 760px;
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
.cookie-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.cookie-btn {
  min-height: 40px; padding: 10px 24px;
  border: 0; border-radius: 999px;
  background: var(--brand-red, #D62300); color: #fff;
  font-family: inherit; font-size: 14px; font-weight: 700; cursor: pointer;
}
.cookie-btn:hover { background: #B52200; }
.cookie-btn:focus-visible { outline: 2px solid #FF8732; outline-offset: 2px; }
.cookie-btn-ghost {
  min-height: 40px; padding: 10px 18px;
  border: 1px solid rgba(245, 230, 208, .22); border-radius: 999px;
  background: none; color: rgba(245, 230, 208, .7);
  font-family: inherit; font-size: 14px; font-weight: 600; cursor: pointer;
}
.cookie-btn-ghost:hover { color: #F5EBDC; border-color: rgba(245, 230, 208, .45); }
.cookie-btn-ghost:focus-visible { outline: 2px solid #FF8732; outline-offset: 2px; }

.cookie-fade-enter-active, .cookie-fade-leave-active { transition: opacity .25s ease, transform .25s ease; }
.cookie-fade-enter-from, .cookie-fade-leave-to { opacity: 0; transform: translateY(12px); }

/* ── Заглушка при отказе ── */
.cookie-block {
  position: fixed; inset: 0; z-index: 9500;
  display: flex; align-items: center; justify-content: center;
  padding: 24px;
  background: radial-gradient(120% 90% at 50% 90%, #3a1d0c 0%, #1d0f07 55%, #120a05 100%);
  overflow: auto;
}
.cookie-block-card { max-width: 520px; text-align: center; }
.cookie-block-mark { display: flex; justify-content: center; margin-bottom: 20px; }
.cookie-block-title {
  font-family: 'Flame', 'Sora', sans-serif;
  font-size: 34px; line-height: 1.05; text-transform: uppercase;
  color: #F5E6D0; margin: 0 0 16px;
}
.cookie-block-text {
  font-size: 15px; line-height: 1.6;
  color: rgba(245, 230, 208, .68);
  margin: 0 0 12px;
}
.cookie-block-text a { color: #F4A261; font-weight: 700; text-decoration: none; }
.cookie-block-text a:hover { text-decoration: underline; }
.cookie-block-actions {
  display: flex; align-items: center; justify-content: center; gap: 18px;
  flex-wrap: wrap; margin-top: 22px;
}
.cookie-block-leave { font-size: 13px; color: rgba(245, 230, 208, .45); text-decoration: none; }
.cookie-block-leave:hover { color: rgba(245, 230, 208, .8); text-decoration: underline; }

@media (max-width: 560px) {
  .cookie { flex-direction: column; align-items: stretch; text-align: center; gap: 12px; }
  .cookie-actions { flex-direction: column-reverse; }
  .cookie-btn, .cookie-btn-ghost { width: 100%; }
  .cookie-block-title { font-size: 26px; }
}
</style>
