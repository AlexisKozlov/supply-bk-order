<template>
  <div class="bct">
    <div class="bct-mode">
      <button class="bct-mode-btn" :class="{ active: mode === 'broadcast' }" @click="mode = 'broadcast'">
        <BkIcon name="bell" size="sm"/> Уведомления
      </button>
      <button class="bct-mode-btn" :class="{ active: mode === 'changelog' }" @click="switchToChangelog">
        <BkIcon name="bulb" size="sm"/> Что нового
      </button>
    </div>

    <!-- ═══ Рассылка ═══ -->
    <template v-if="mode === 'broadcast'">
      <div class="bct-card">
        <h4 class="bct-card-title">Новое сообщение</h4>
        <p class="bct-card-hint">Один и тот же текст уйдёт во все выбранные направления.</p>

        <input v-model="title" class="bct-input" placeholder="Заголовок (необязательно)" maxlength="120" />
        <textarea v-model="message" class="bct-input bct-textarea" rows="4"
                  placeholder="Текст сообщения…" maxlength="2000"></textarea>
        <div class="bct-counter" :class="{ near: message.length > 1800 }">{{ message.length }} из 2000</div>

        <div class="bct-targets">
          <label v-for="t in targetList" :key="t.key" class="bct-target" :class="{ on: targets[t.key] }">
            <input type="checkbox" v-model="targets[t.key]" />
            <span class="bct-target-check"><BkIcon name="success" size="sm"/></span>
            <span class="bct-target-body">
              <span class="bct-target-name">{{ t.label }}</span>
              <span class="bct-target-count">{{ audienceText(t.key) }}</span>
            </span>
          </label>
        </div>

        <div v-if="message.trim()" class="bct-preview">
          <div class="bct-preview-label">Так это увидят</div>
          <div class="bct-preview-box">
            <div class="bct-preview-title">{{ title.trim() || 'Важное сообщение' }}</div>
            <div class="bct-preview-text">{{ message.trim() }}</div>
          </div>
        </div>

        <div class="bct-send-row">
          <button class="btn primary" @click="send" :disabled="sending || !message.trim() || !hasTarget">
            {{ sending ? 'Отправка…' : 'Отправить' }}
          </button>
          <span v-if="hasTarget && totalReach" class="bct-reach">получат примерно {{ formatInt(totalReach) }} {{ plural(totalReach, 'человек', 'человека', 'человек') }}</span>
          <span v-else-if="!hasTarget" class="bct-reach muted">выберите, куда отправлять</span>
        </div>
      </div>

      <div class="bct-card">
        <div class="bct-card-head">
          <h4 class="bct-card-title">История рассылок</h4>
          <button class="bct-mini" @click="loadHistory" :disabled="historyLoading" title="Обновить">
            <BkIcon name="redo" size="sm"/>
          </button>
        </div>

        <div v-if="historyLoading && !history.length" class="bct-state"><BurgerSpinner text="Загрузка…" /></div>
        <div v-else-if="!history.length" class="bct-state">Рассылок ещё не было</div>
        <div v-else class="bct-history">
          <div v-for="b in history" :key="b.broadcast_group || b.id" class="bct-item">
            <div class="bct-item-body">
              <div class="bct-item-title">{{ b.title || 'Важное сообщение' }}</div>
              <div class="bct-item-text">{{ b.message }}</div>
              <div class="bct-item-meta">
                {{ b.sender || b.created_by }} · {{ fmtDate(b.created_at) }}
                <template v-if="targetsOf(b)"> · {{ targetsOf(b) }}</template>
                <template v-if="tgStats(b)"> · {{ tgStats(b) }}</template>
              </div>
            </div>
            <button class="bct-del" @click="remove(b)" :disabled="b._deleting" title="Удалить рассылку">
              <BkIcon name="delete" size="sm"/>
            </button>
          </div>
        </div>
      </div>
    </template>

    <!-- ═══ Что нового ═══ -->
    <template v-else>
      <div class="bct-card">
        <div class="bct-card-head">
          <h4 class="bct-card-title">Записи об обновлениях</h4>
          <button class="btn primary bct-add" @click="openEntry(null)">
            <BkIcon name="add" size="sm"/> Добавить
          </button>
        </div>
        <p class="bct-card-hint">Их видят все пользователи в разделе «Уведомления».</p>

        <div v-if="changelogLoading && !entries.length" class="bct-state"><BurgerSpinner text="Загрузка…" /></div>
        <div v-else-if="!entries.length" class="bct-state">Записей пока нет</div>
        <div v-else class="bct-history">
          <div v-for="e in entries" :key="e.id" class="bct-item">
            <div class="bct-item-body">
              <div class="bct-item-title">
                <span class="bct-version">v{{ e.version }}</span>{{ e.title }}
              </div>
              <div v-if="e.description" class="bct-item-text">{{ e.description }}</div>
              <div class="bct-item-meta">{{ e.created_by }} · {{ fmtDate(e.created_at) }}</div>
            </div>
            <div class="bct-item-actions">
              <button class="bct-del" @click="openEntry(e)" title="Изменить"><BkIcon name="edit" size="sm"/></button>
              <button class="bct-del" @click="removeEntry(e)" title="Удалить"><BkIcon name="delete" size="sm"/></button>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { db } from '@/lib/apiClient.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import { useToastStore } from '@/stores/toastStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { appConfirm } from '@/lib/appDialogs.js';
import { formatMoscowDateTime, formatInt, plural } from '@/lib/utils.js';

const emit = defineEmits(['open-changelog']);

const toast = useToastStore();
const userStore = useUserStore();

const mode = ref('broadcast');
const title = ref('');
const message = ref('');
const sending = ref(false);
const history = ref([]);
const historyLoading = ref(false);
const entries = ref([]);
const changelogLoading = ref(false);
const audience = reactive({ staff_cabinet: 0, restaurant_cabinet: 0, staff_telegram: 0, restaurant_telegram: 0 });

const targets = reactive({
  staffCabinet: true,
  restaurantCabinet: false,
  staffTelegram: false,
  restaurantTelegram: false,
});

const targetList = [
  { key: 'staffCabinet', label: 'Кабинет отдела закупок', audience: 'staff_cabinet' },
  { key: 'restaurantCabinet', label: 'Кабинет ресторанов', audience: 'restaurant_cabinet' },
  { key: 'staffTelegram', label: 'Telegram отдела закупок', audience: 'staff_telegram' },
  { key: 'restaurantTelegram', label: 'Telegram ресторанов', audience: 'restaurant_telegram' },
];

const hasTarget = computed(() => Object.values(targets).some(Boolean));
const totalReach = computed(() =>
  targetList.reduce((sum, t) => sum + (targets[t.key] ? (audience[t.audience] || 0) : 0), 0)
);

function audienceText(key) {
  const t = targetList.find(x => x.key === key);
  const n = audience[t.audience] || 0;
  if (!n) return 'некому';
  return `${formatInt(n)} ${plural(n, 'получатель', 'получателя', 'получателей')}`;
}

const fmtDate = formatMoscowDateTime;

function targetsOf(b) {
  const parts = [];
  if (b.target_staff_cabinet) parts.push('кабинет закупок');
  if (b.target_restaurant_cabinet) parts.push('кабинет ресторанов');
  if (b.target_staff_telegram) parts.push('Telegram закупок');
  if (b.target_restaurant_telegram) parts.push('Telegram ресторанов');
  return parts.join(', ');
}
function tgStats(b) {
  const parts = [];
  if (Number(b.staff_telegram_sent || 0) > 0) parts.push(`закупки TG: ${b.staff_telegram_sent}`);
  if (Number(b.restaurant_telegram_sent || 0) > 0) parts.push(`рестораны TG: ${b.restaurant_telegram_sent}`);
  return parts.join(', ');
}

async function loadAudience() {
  try {
    const { data } = await db.rpc('get_broadcast_audience', {});
    if (data) Object.assign(audience, data);
  } catch { /* цифра справочная */ }
}

async function loadHistory() {
  historyLoading.value = true;
  try {
    const { data } = await db.rpc('get_broadcast_history', { limit: 20 });
    history.value = data || [];
  } catch (e) {
    console.warn('[broadcast] history:', e);
  } finally {
    historyLoading.value = false;
  }
}

async function send() {
  if (!message.value.trim() || !hasTarget.value) return;

  // Рассылка — самое громкое действие в портале: всплывающее окно у всех и
  // сообщения в Telegram. Отправлять без подтверждения нельзя.
  const where = targetList.filter(t => targets[t.key]).map(t => t.label.toLowerCase());
  const ok = await appConfirm(
    `Сообщение уйдёт в: ${where.join(', ')}. Это примерно ${formatInt(totalReach.value)} ${plural(totalReach.value, 'человек', 'человека', 'человек')}. Отменить отправку будет нельзя.`,
    { title: 'Отправить рассылку?', okText: 'Отправить', danger: true }
  );
  if (!ok) return;

  sending.value = true;
  try {
    const { data } = await db.rpc('send_broadcast', {
      user_name: userStore.currentUser.name,
      title: title.value.trim() || 'Важное сообщение',
      message: message.value.trim(),
      to_staff_cabinet: targets.staffCabinet,
      to_restaurants_cabinet: targets.restaurantCabinet,
      to_staff_telegram: targets.staffTelegram,
      to_restaurants_telegram: targets.restaurantTelegram,
    });
    if (data?.success) {
      const parts = [];
      if (data.staff_telegram_sent > 0) parts.push(`закупки Telegram: ${data.staff_telegram_sent}`);
      if (data.restaurant_telegram_sent > 0) parts.push(`рестораны Telegram: ${data.restaurant_telegram_sent}`);
      toast.success('Отправлено', parts.join(', ') || 'Рассылка ушла');
      title.value = '';
      message.value = '';
      loadHistory();
    } else {
      toast.error('Ошибка', data?.error || 'Не удалось отправить');
    }
  } catch {
    toast.error('Ошибка', 'Не удалось отправить сообщение');
  } finally {
    sending.value = false;
  }
}

async function remove(b) {
  const ok = await appConfirm(`Сообщение «${b.title || 'Важное сообщение'}» пропадёт у всех, кто его ещё не закрыл.`, {
    title: 'Удалить рассылку?', okText: 'Удалить', danger: true,
  });
  if (!ok) return;
  b._deleting = true;
  try {
    const payload = b.is_legacy ? { id: b.id } : { broadcast_group: b.broadcast_group };
    const { data, error } = await db.rpc('delete_broadcast', payload);
    if (error || (data && !data.success)) { toast.error('Ошибка', error || data?.error || ''); return; }
    toast.success('Удалено', '');
    history.value = history.value.filter(x => (x.broadcast_group || x.id) !== (b.broadcast_group || b.id));
  } catch {
    toast.error('Ошибка', 'Не удалось удалить');
  } finally {
    b._deleting = false;
  }
}

async function loadChangelog() {
  changelogLoading.value = true;
  try {
    const { data } = await db.rpc('get_changelog');
    entries.value = data || [];
  } catch (e) {
    console.warn('[broadcast] changelog:', e);
  } finally {
    changelogLoading.value = false;
  }
}

function switchToChangelog() {
  mode.value = 'changelog';
  if (!entries.value.length) loadChangelog();
}

// Форма записи живёт в общей модалке страницы: она же открывается из шапки.
function openEntry(entry) {
  emit('open-changelog', entry);
}

async function removeEntry(e) {
  const ok = await appConfirm(`Запись «${e.title}» пропадёт из раздела «Уведомления».`, {
    title: 'Удалить запись?', okText: 'Удалить', danger: true,
  });
  if (!ok) return;
  try {
    const { data, error } = await db.rpc('delete_changelog', { id: e.id });
    if (error || (data && !data.success)) { toast.error('Ошибка', error || data?.error || ''); return; }
    toast.success('Удалено', '');
    entries.value = entries.value.filter(x => x.id !== e.id);
  } catch {
    toast.error('Ошибка', 'Не удалось удалить');
  }
}

// Страница зовёт после сохранения записи, чтобы список освежился.
defineExpose({ reloadChangelog: loadChangelog });

onMounted(() => {
  loadHistory();
  loadAudience();
});
</script>

<style scoped>
.bct { display: flex; flex-direction: column; gap: 12px; }

.bct-mode {
  display: inline-flex; gap: 2px; align-self: flex-start;
  background: var(--bg); border: 1px solid var(--border-light); border-radius: 10px; padding: 2px;
}
.bct-mode-btn {
  display: inline-flex; align-items: center; gap: 6px;
  border: none; background: none; cursor: pointer; font-family: inherit;
  font-size: 13px; font-weight: 600; color: var(--text-muted);
  padding: 7px 14px; border-radius: 8px; transition: all .15s;
}
.bct-mode-btn.active { background: var(--card); color: var(--text); box-shadow: 0 1px 3px rgba(0, 0, 0, .08); }

.bct-card {
  background: var(--card); border: 1px solid var(--border-light);
  border-radius: 12px; padding: 14px 16px;
}
.bct-card-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.bct-card-title { margin: 0; font-size: 13px; font-weight: 700; color: var(--text); }
.bct-card-hint { margin: 4px 0 12px; font-size: 12px; color: var(--text-muted); }

.bct-input {
  width: 100%; box-sizing: border-box; padding: 10px 12px;
  font-family: inherit; font-size: 13.5px; color: var(--text);
  border: 1.5px solid var(--border-light); border-radius: 8px; background: var(--card);
}
.bct-input:focus { outline: none; border-color: var(--bk-orange); }
.bct-textarea { margin-top: 8px; resize: vertical; }
.bct-counter { text-align: right; font-size: 11px; color: var(--text-muted); margin-top: 4px; }
.bct-counter.near { color: #B4432E; }

.bct-targets {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
  gap: 8px; margin-top: 12px;
}
.bct-target {
  display: flex; align-items: center; gap: 9px; cursor: pointer;
  padding: 9px 11px; border-radius: 9px;
  border: 1.5px solid var(--border-light); background: var(--bg); transition: all .15s;
}
.bct-target:hover { border-color: var(--bk-orange); }
.bct-target.on { background: #FFF8F0; border-color: var(--bk-orange); }
.bct-target input { display: none; }
.bct-target-check {
  width: 18px; height: 18px; border-radius: 5px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  border: 1.5px solid var(--border-light); background: var(--card);
}
.bct-target-check :deep(svg) { opacity: 0; transition: opacity .15s; }
.bct-target.on .bct-target-check { background: var(--bk-orange); border-color: var(--bk-orange); }
.bct-target.on .bct-target-check :deep(svg) { opacity: 1; color: #fff; stroke: #fff; }
.bct-target-body { display: flex; flex-direction: column; min-width: 0; }
.bct-target-name { font-size: 13px; color: var(--text); }
.bct-target-count { font-size: 11px; color: var(--text-muted); }

.bct-preview { margin-top: 14px; }
.bct-preview-label {
  font-size: 10.5px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .3px; color: var(--text-muted); margin-bottom: 6px;
}
.bct-preview-box { padding: 14px 16px; border-radius: 10px; background: var(--bg); border: 1px dashed var(--border); }
.bct-preview-title { font-size: 14px; font-weight: 700; color: var(--text); }
.bct-preview-text { margin-top: 4px; font-size: 13px; color: var(--text-secondary); line-height: 1.5; white-space: pre-line; }

.bct-send-row { display: flex; align-items: center; gap: 12px; margin-top: 14px; flex-wrap: wrap; }
.bct-reach { font-size: 12.5px; color: var(--text-secondary); }
.bct-reach.muted { color: var(--text-muted); }

.bct-mini {
  border: 1px solid var(--border-light); background: none; cursor: pointer;
  color: var(--text-muted); border-radius: 6px; padding: 4px 8px;
}
.bct-mini:hover { background: var(--bg); color: var(--text); }
.bct-add { font-size: 12px; padding: 6px 14px; }

.bct-state { text-align: center; padding: 24px; color: var(--text-muted); font-size: 13px; }
.bct-history { display: flex; flex-direction: column; gap: 8px; margin-top: 10px; }
.bct-item {
  display: flex; align-items: flex-start; gap: 10px;
  padding: 12px 14px; border-radius: 10px;
  background: var(--bg); border: 1px solid var(--border-light);
}
.bct-item-body { flex: 1; min-width: 0; }
.bct-item-title { font-size: 14px; font-weight: 700; color: var(--text); display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.bct-item-text { font-size: 13px; color: var(--text-secondary); line-height: 1.5; white-space: pre-line; margin-top: 2px; }
.bct-item-meta { font-size: 11px; color: var(--text-muted); margin-top: 6px; }
.bct-item-actions { display: flex; gap: 2px; flex-shrink: 0; }
.bct-version {
  font-size: 10.5px; font-weight: 700; padding: 2px 7px; border-radius: 5px;
  background: #FFF3E0; color: #E65100;
}
.bct-del {
  flex-shrink: 0; background: none; border: none; cursor: pointer;
  color: var(--text-muted); padding: 5px; border-radius: 6px; transition: all .15s;
}
.bct-del:hover { color: #D32F2F; background: rgba(211, 47, 47, .08); }
.bct-del:disabled { opacity: .4; pointer-events: none; }

@media (max-width: 600px) {
  .bct-mode { width: 100%; }
  .bct-mode-btn { flex: 1; justify-content: center; }
  .bct-targets { grid-template-columns: 1fr; }
  .bct-send-row .btn { flex: 1; justify-content: center; }
}
</style>
