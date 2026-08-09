<template>
  <div v-if="total > 0" class="att">
    <div class="att-head">
      <div class="att-title">
        Требуют внимания
        <span class="att-total">{{ total }}</span>
      </div>
      <button class="att-refresh" :disabled="loading" title="Обновить" @click="load">
        <span :class="{ spin: loading }">↻</span>
      </button>
    </div>

    <div class="att-blocks">
      <div v-for="b in blocks" :key="b.key" class="att-block">
        <button class="att-block-head" @click="toggle(b.key)">
          <span class="att-chevron" :class="{ open: opened[b.key] }">›</span>
          <span class="att-block-title">{{ b.title }}</span>
          <span class="att-block-count">{{ b.count }}</span>
        </button>

        <div v-if="opened[b.key]" class="att-items">
          <p class="att-hint">{{ b.hint }}</p>

          <div v-for="it in b.items" :key="b.key + '-' + it.id" class="att-item">
            <div class="att-item-main">
              <div class="att-item-title">{{ it.title }}</div>
              <div v-if="it.subtitle" class="att-item-sub">{{ it.subtitle }}</div>
            </div>

            <!-- Обычно считаем «сколько дней просрочено». Передача дел ещё не
                 просрочена, а только начинается, поэтому у неё своя подпись. -->
            <span v-if="it.days_label || it.days !== null" class="att-days" :class="daysClass(it)">
              {{ it.days_label || (it.days + ' дн.') }}
            </span>

            <div class="att-item-actions">
              <button
                v-if="b.action"
                class="att-btn att-btn-do"
                :disabled="busy === b.key + '-' + it.id"
                @click="runAction(b, it)"
              >{{ actionLabel(b.action) }}</button>
              <router-link :to="{ name: b.route }" class="att-btn">Открыть</router-link>
            </div>
          </div>

          <router-link :to="{ name: b.route }" class="att-all">
            Весь раздел →
          </router-link>
        </div>
      </div>
    </div>

    <ConfirmModal
      v-if="confirmModal.show"
      :title="confirmModal.title"
      :message="confirmModal.message"
      :ok-text="confirmModal.okText"
      :cancel-text="confirmModal.cancelText"
      :danger="confirmModal.danger"
      @confirm="onConfirm"
      @cancel="onCancel"
    />
  </div>
</template>

<script setup>
/**
 * Блок «Требуют внимания» на дашборде.
 *
 * Собирает незакрытые дела из всех модулей одним запросом: просроченные задачи
 * и решения совещаний, оплаты, неотмеченные приёмки, тендеры, акции, сессии
 * распределения, опросы и рестораны, до которых не дойдёт ни одно напоминание.
 *
 * Что можно закрыть прямо отсюда — закрывается кнопкой; где нужен полный
 * разбор (приёмка, тендер, акция) — ведём в карточку, а не делаем вид,
 * что решение принимается вслепую.
 *
 * Права проверяет сервер: блока не будет вовсе, если нет доступа к разделу.
 */
import { ref, reactive, watch, onMounted, defineAsyncComponent } from 'vue';
import { db } from '@/lib/apiClient.js';
import { tasksApi } from '@/lib/tasksApi.js';
import { useToastStore } from '@/stores/toastStore.js';
import { useConfirm } from '@/composables/useConfirm.js';

const ConfirmModal = defineAsyncComponent(() => import('@/components/modals/ConfirmModal.vue'));

const props = defineProps({
  legalEntity: { type: String, default: '' },
});

const toast = useToastStore();
const { confirmModal, confirm, onConfirm, onCancel } = useConfirm();

const blocks = ref([]);
const total = ref(0);
const loading = ref(false);
const busy = ref('');
const opened = reactive({});

const ACTION_LABELS = {
  postpone: 'Перенести на неделю',
  mark_paid: 'Отметить оплаченным',
  close_session: 'Закрыть сессию',
  close_survey: 'Закрыть опрос',
};
function actionLabel(a) { return ACTION_LABELS[a] || 'Готово'; }

function daysClass(it) {
  if (it.urgent) return 'is-old';   // срочность задана явно, а не числом дней
  const d = it.days;
  if (d >= 30) return 'is-old';
  if (d >= 7) return 'is-mid';
  return '';
}

function toggle(key) { opened[key] = !opened[key]; }

async function load() {
  loading.value = true;
  try {
    const { data, error } = await db.rpc('attention_overview', { legal_entity: props.legalEntity || '' });
    if (error) throw new Error(error);
    blocks.value = data?.blocks || [];
    total.value = data?.total || 0;
    // Первый блок раскрыт сразу — иначе виден только счётчик и надо угадывать.
    if (blocks.value.length && opened[blocks.value[0].key] === undefined) {
      opened[blocks.value[0].key] = true;
    }
  } catch (e) {
    toast.error('Не удалось загрузить', String(e.message || e));
  } finally {
    loading.value = false;
  }
}

async function runAction(block, item) {
  const key = block.key + '-' + item.id;
  const ok = await confirm(
    actionLabel(block.action),
    confirmText(block, item),
    { okText: actionLabel(block.action) },
  );
  if (!ok) return;

  busy.value = key;
  try {
    if (block.action === 'postpone') {
      const due = new Date();
      due.setDate(due.getDate() + 7);
      const iso = due.toISOString().slice(0, 10) + ' 23:59:59';
      await tasksApi.updateCard(item.id, { due_date: iso });
    } else if (block.action === 'mark_paid') {
      const { error } = await db.rpc('update_payment', { id: item.id, status: 'paid' });
      if (error) throw new Error(error);
    } else if (block.action === 'close_session') {
      const { error } = await db.rpc('dist_close_session', { session_id: item.id });
      if (error) throw new Error(error);
    } else if (block.action === 'close_survey') {
      const { error } = await db.rpc('survey_close', { id: item.id });
      if (error) throw new Error(error);
    }
    toast.success('Готово', item.title);
    await load();
  } catch (e) {
    toast.error('Не получилось', String(e.message || e));
  } finally {
    busy.value = '';
  }
}

function confirmText(block, item) {
  if (block.action === 'postpone') return `«${item.title}» — новый срок через неделю.`;
  if (block.action === 'mark_paid') return `«${item.title}», ${item.subtitle}. Отметить как оплаченный?`;
  if (block.action === 'close_session') return `Закрыть сессию «${item.title}»? Изменения в ней станут недоступны.`;
  if (block.action === 'close_survey') return `Закрыть опрос «${item.title}»? Рестораны больше не смогут ответить.`;
  return item.title;
}

// Дашборд подставляет юрлицо уже после первой отрисовки, а человек может
// переключить его руками. Без этого счётчик показывал бы одно, а список —
// другое: число «прыгало» при первом же действии.
watch(() => props.legalEntity, load);

defineExpose({ load });
onMounted(load);
</script>

<style scoped>
.att {
  background: var(--card-bg, #fff);
  border: 1px solid var(--border-color, #e3e6ea);
  border-radius: 10px;
  padding: 16px 18px;
  margin-bottom: 18px;
}
.att-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.att-title { font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
.att-total {
  background: #b4432e; color: #fff; border-radius: 11px;
  min-width: 22px; height: 22px; padding: 0 7px;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 700;
}
.att-refresh { background: none; border: none; cursor: pointer; font-size: 16px; color: var(--text-muted, #7a8794); }
.att-refresh:disabled { opacity: .5; cursor: default; }
.spin { display: inline-block; animation: att-spin 1s linear infinite; }
@keyframes att-spin { to { transform: rotate(360deg); } }

.att-blocks { display: flex; flex-direction: column; gap: 2px; }
.att-block { border-top: 1px solid var(--border-color, #eceff2); }
.att-block:first-child { border-top: none; }

.att-block-head {
  width: 100%; display: flex; align-items: center; gap: 9px;
  background: none; border: none; cursor: pointer;
  padding: 9px 2px; text-align: left; font-size: 14px; color: inherit;
}
.att-block-head:hover { background: var(--hover-bg, #f6f7f9); }
.att-chevron { transition: transform .15s; color: var(--text-muted, #7a8794); font-size: 15px; }
.att-chevron.open { transform: rotate(90deg); }
.att-block-title { flex: 1; font-weight: 600; }
.att-block-count {
  font-variant-numeric: tabular-nums; font-weight: 700;
  color: var(--text-muted, #7a8794); min-width: 26px; text-align: right;
}

.att-items { padding: 2px 0 10px 26px; }
.att-hint { margin: 0 0 8px; font-size: 12px; color: var(--text-muted, #7a8794); }

.att-item {
  display: flex; align-items: center; gap: 10px;
  padding: 7px 0; border-top: 1px dashed var(--border-color, #eceff2);
}
.att-item-main { flex: 1; min-width: 0; }
.att-item-title { font-size: 13.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.att-item-sub { font-size: 12px; color: var(--text-muted, #7a8794); }

.att-days {
  font-size: 12px; font-variant-numeric: tabular-nums;
  color: var(--text-muted, #7a8794); white-space: nowrap;
}
.att-days.is-mid { color: #9a6b12; font-weight: 600; }
.att-days.is-old { color: #b4432e; font-weight: 700; }

.att-item-actions { display: flex; gap: 6px; flex-shrink: 0; }
.att-btn {
  font-size: 12px; padding: 3px 9px; border-radius: 5px;
  border: 1px solid var(--border-color, #d7dce2);
  background: none; color: inherit; cursor: pointer;
  text-decoration: none; white-space: nowrap;
}
.att-btn:hover { background: var(--hover-bg, #f0f2f5); }
.att-btn-do { border-color: #2a4a7f; color: #2a4a7f; }
.att-btn-do:disabled { opacity: .5; cursor: default; }

.att-all {
  display: inline-block; margin-top: 8px;
  font-size: 12.5px; color: #2a4a7f; text-decoration: none;
}
.att-all:hover { text-decoration: underline; }

@media (max-width: 640px) {
  .att-item { flex-wrap: wrap; }
  .att-item-actions { width: 100%; justify-content: flex-end; }
}
</style>
