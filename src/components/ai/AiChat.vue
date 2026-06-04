<template>
  <div class="aic" :class="{ 'aic--compact': compact }">
    <!-- Лента сообщений -->
    <div ref="scrollEl" class="aic-feed">
      <!-- Пустое состояние с подсказками -->
      <div v-if="!state.messages.length" class="aic-empty">
        <BkIcon name="analytics" size="lg" />
        <p class="aic-empty-title">ИИ-помощник закупок</p>
        <p class="aic-empty-desc">Спросите про остатки, заказы, поставки, цены, сроки годности или сводку. Отвечаю по реальным данным портала.</p>
        <div class="aic-suggestions">
          <button v-for="s in suggestions" :key="s" class="aic-chip" @click="send(s)">{{ s }}</button>
        </div>
      </div>

      <div v-for="(m, i) in state.messages" :key="i" class="aic-msg" :class="`aic-msg--${m.role}`">
        <div class="aic-bubble" :class="{ 'aic-bubble--error': m.error }">
          <template v-if="m.role === 'assistant'">
            <span v-html="renderAnswer(m.content)"></span>
          </template>
          <template v-else>{{ m.content }}</template>
        </div>
      </div>

      <div v-if="state.loading" class="aic-msg aic-msg--assistant">
        <div class="aic-bubble aic-typing">
          <span></span><span></span><span></span>
        </div>
      </div>
    </div>

    <!-- Ввод -->
    <div class="aic-input-row">
      <textarea
        ref="inputEl"
        v-model="draft"
        class="aic-input"
        rows="1"
        placeholder="Спросите ИИ-помощника…"
        :disabled="state.loading"
        @keydown.enter.exact.prevent="send()"
        @input="autoGrow"
      ></textarea>
      <button class="aic-send" :disabled="state.loading || !draft.trim()" @click="send()" title="Отправить">
        <BkIcon name="arrowRight" size="sm" />
      </button>
    </div>
    <div class="aic-foot">
      <button v-if="state.messages.length" class="aic-clear" @click="clear">Очистить</button>
      <span class="aic-hint">Enter — отправить · Shift+Enter — перенос строки</span>
    </div>
  </div>
</template>

<script setup>
import { ref, nextTick, watch } from 'vue';
import BkIcon from '@/components/ui/BkIcon.vue';
import { useAiAssistant, renderAnswer } from '@/composables/useAiAssistant.js';

defineProps({
  compact: { type: Boolean, default: false },
});

const { state, ask, clear } = useAiAssistant();
const draft = ref('');
const scrollEl = ref(null);
const inputEl = ref(null);

const suggestions = [
  'Что заканчивается? Топ-5 по запасу в днях',
  'Сводка по закупкам на сегодня',
  'Какие сроки годности горят?',
  'Какие поставки уже в пути?',
];

async function send(text) {
  const q = (text ?? draft.value).trim();
  if (!q || state.loading) return;
  draft.value = '';
  await nextTick();
  if (inputEl.value) inputEl.value.style.height = 'auto';
  await ask(q);
}

function autoGrow() {
  const el = inputEl.value;
  if (!el) return;
  el.style.height = 'auto';
  el.style.height = Math.min(el.scrollHeight, 120) + 'px';
}

// Автопрокрутка вниз при новых сообщениях / печати
watch(() => [state.messages.length, state.loading], async () => {
  await nextTick();
  if (scrollEl.value) scrollEl.value.scrollTop = scrollEl.value.scrollHeight;
});
</script>

<style scoped>
.aic {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  font-family: var(--tk-font);
  color: var(--tk-text);
}
.aic-feed {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: var(--tk-s-4);
  display: flex;
  flex-direction: column;
  gap: var(--tk-s-3);
}

.aic-empty {
  margin: auto;
  text-align: center;
  max-width: 460px;
  color: var(--tk-text-muted);
}
.aic-empty-title { margin: var(--tk-s-3) 0 var(--tk-s-1); font-size: var(--tk-fz-lg); font-weight: var(--tk-fw-bold); color: var(--tk-text); }
.aic-empty-desc { margin: 0 0 var(--tk-s-4); font-size: var(--tk-fz-sm); }
.aic-suggestions { display: flex; flex-wrap: wrap; gap: var(--tk-s-2); justify-content: center; }
.aic-chip {
  padding: var(--tk-s-2) var(--tk-s-3);
  background: var(--tk-n-0);
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-pill);
  font-size: var(--tk-fz-sm);
  color: var(--tk-text);
  cursor: pointer;
}
.aic-chip:hover { border-color: var(--tk-accent); color: var(--tk-accent-text); }

.aic-msg { display: flex; }
.aic-msg--user { justify-content: flex-end; }
.aic-msg--assistant { justify-content: flex-start; }
.aic-bubble {
  max-width: 86%;
  padding: var(--tk-s-3) var(--tk-s-4);
  border-radius: var(--tk-r-lg);
  font-size: var(--tk-fz-md);
  line-height: var(--tk-lh-base);
  white-space: pre-wrap;
  word-break: break-word;
}
.aic-msg--user .aic-bubble {
  background: var(--tk-accent);
  color: #fff;
  border-bottom-right-radius: var(--tk-r-sm);
}
.aic-msg--assistant .aic-bubble {
  background: var(--tk-n-0);
  border: 1px solid var(--tk-border);
  border-bottom-left-radius: var(--tk-r-sm);
}
.aic-bubble--error { background: var(--tk-danger-soft); border-color: var(--tk-danger); color: var(--tk-danger); }
.aic-bubble :deep(a) { color: var(--tk-accent-text); }
.aic-bubble :deep(b) { font-weight: var(--tk-fw-bold); }
.aic-bubble :deep(ul), .aic-bubble :deep(ol) { margin: var(--tk-s-1) 0; padding-left: 1.2em; }

.aic-typing { display: flex; gap: 4px; }
.aic-typing span {
  width: 7px; height: 7px; border-radius: 50%;
  background: var(--tk-text-muted);
  animation: aic-blink 1.2s infinite both;
}
.aic-typing span:nth-child(2) { animation-delay: 0.2s; }
.aic-typing span:nth-child(3) { animation-delay: 0.4s; }
@keyframes aic-blink { 0%, 80%, 100% { opacity: 0.3; } 40% { opacity: 1; } }
@media (prefers-reduced-motion: reduce) { .aic-typing span { animation: none; } }

.aic-input-row {
  display: flex;
  gap: var(--tk-s-2);
  padding: var(--tk-s-3) var(--tk-s-4) 0;
  align-items: flex-end;
}
.aic-input {
  flex: 1;
  resize: none;
  padding: var(--tk-s-3);
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-md);
  font-family: inherit;
  font-size: var(--tk-fz-md);
  color: var(--tk-text);
  background: var(--tk-n-0);
  max-height: 120px;
}
.aic-input:focus { outline: none; box-shadow: var(--tk-focus-ring); border-color: var(--tk-accent); }
.aic-send {
  flex-shrink: 0;
  width: 40px; height: 40px;
  display: flex; align-items: center; justify-content: center;
  background: var(--tk-accent);
  color: #fff;
  border: none;
  border-radius: var(--tk-r-md);
  cursor: pointer;
}
.aic-send:disabled { opacity: 0.5; cursor: default; }
.aic-send:not(:disabled):hover { background: var(--tk-accent-hover); }

.aic-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--tk-s-2);
  padding: var(--tk-s-2) var(--tk-s-4) var(--tk-s-3);
}
.aic-clear { background: none; border: none; color: var(--tk-text-muted); font-size: var(--tk-fz-sm); cursor: pointer; text-decoration: underline; }
.aic-hint { margin-left: auto; font-size: var(--tk-fz-xs); color: var(--tk-text-muted); }
.aic--compact .aic-hint { display: none; }
</style>
