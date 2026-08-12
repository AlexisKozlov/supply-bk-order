<template>
  <div class="mnt">
    <!-- Главный переключатель -->
    <div class="mnt-main" :class="{ on: isOn }">
      <div class="mnt-main-icon">
        <BkIcon :name="isOn ? 'warning' : 'success'" size="lg"/>
      </div>
      <div class="mnt-main-body">
        <h3 class="mnt-main-title">{{ isOn ? 'Техработы включены' : 'Портал работает' }}</h3>
        <p class="mnt-main-desc">
          <template v-if="isOn">
            Все, кроме администраторов, видят заглушку и работать не могут.
          </template>
          <template v-else>
            Когда включите, все, кроме администраторов, увидят заглушку и не смогут работать.
          </template>
        </p>
      </div>
      <button class="mnt-toggle" :class="{ on: isOn }" @click="toggle" :disabled="saving">
        <span class="mnt-track"><span class="mnt-thumb"></span></span>
        <span class="mnt-label">{{ isOn ? 'Включён' : 'Выключен' }}</span>
      </button>
    </div>

    <!-- Кого это затронет -->
    <div v-if="!isOn && (onlineUsers || onlineRestaurants)" class="mnt-who">
      <BkIcon name="user" size="sm"/>
      <span>
        Сейчас в системе
        <b v-if="onlineUsers">{{ onlineUsers }} {{ plural(onlineUsers, 'сотрудник', 'сотрудника', 'сотрудников') }}</b>
        <template v-if="onlineUsers && onlineRestaurants"> и </template>
        <b v-if="onlineRestaurants">{{ onlineRestaurants }} {{ plural(onlineRestaurants, 'ресторан', 'ресторана', 'ресторанов') }}</b>
        — их работу прервёт.
      </span>
    </div>
    <div v-else-if="isOn" class="mnt-alarm">
      <BkIcon name="warning" size="sm"/>
      <span>
        Портал <b>недоступен</b> прямо сейчас.
        <template v-if="countdown"> До автовыключения {{ countdown }}.</template>
      </span>
    </div>

    <!-- Автовыключение -->
    <div class="mnt-card">
      <h4 class="mnt-card-title">Автовыключение</h4>
      <p class="mnt-card-hint">Техработы выключатся сами в указанное время, а люди будут видеть обратный отсчёт.</p>

      <div class="mnt-quick">
        <button v-for="opt in quickOptions" :key="opt.min" class="mnt-quick-btn"
                @click="setQuickTimer(opt.min)" :disabled="timerSaving">
          {{ opt.label }}
        </button>
      </div>

      <div class="mnt-exact">
        <label class="mnt-exact-label">Или конкретное время:</label>
        <div class="mnt-exact-row">
          <input type="time" v-model="timeInput" class="mnt-time" />
          <button class="btn primary" @click="saveExactTime" :disabled="timerSaving || !timeInput">
            {{ timerSaving ? 'Сохранение…' : 'Установить' }}
          </button>
        </div>
      </div>

      <div v-if="endTimeDisplay" class="mnt-timer on">
        <span>Выключится в <b>{{ endTimeDisplay }}</b><template v-if="countdown"> · через {{ countdown }}</template></span>
        <button class="mnt-timer-clear" @click="clearTimer" :disabled="timerSaving">Сбросить</button>
      </div>
      <div v-else class="mnt-timer">
        Таймер не установлен — выключать придётся вручную.
      </div>
    </div>

    <!-- Сообщение -->
    <div class="mnt-card">
      <h4 class="mnt-card-title">Что увидят люди</h4>
      <p class="mnt-card-hint">Текст на экране заглушки. Если оставить пустым, покажется стандартный.</p>
      <textarea v-model="message" class="mnt-textarea" rows="3"
        placeholder="Например: Обновляем портал до 18:00. Извините за неудобства."></textarea>

      <div class="mnt-preview">
        <div class="mnt-preview-label">Предпросмотр</div>
        <div class="mnt-preview-box">
          <div class="mnt-preview-title">Обновляем портал</div>
          <div class="mnt-preview-text">{{ message.trim() || 'Портал скоро вернётся. Извините за неудобства.' }}</div>
        </div>
      </div>

      <button class="btn primary mnt-save" @click="saveMessage" :disabled="msgSaving">
        {{ msgSaving ? 'Сохранение…' : 'Сохранить сообщение' }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { db } from '@/lib/apiClient.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import { useToastStore } from '@/stores/toastStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { appConfirm } from '@/lib/appDialogs.js';
import { plural } from '@/lib/utils.js';

const emit = defineEmits(['state']);

const toast = useToastStore();
const userStore = useUserStore();

const isOn = ref(false);
const saving = ref(false);
const message = ref('');
const msgSaving = ref(false);
const timerSaving = ref(false);
const endTime = ref(null);
const timeInput = ref('');
const onlineUsers = ref(0);
const onlineRestaurants = ref(0);
const now = ref(Date.now());
let tick = null;

const quickOptions = [
  { min: 15, label: '15 минут' },
  { min: 30, label: '30 минут' },
  { min: 60, label: '1 час' },
  { min: 120, label: '2 часа' },
];

const endDate = computed(() => {
  if (!endTime.value) return null;
  const d = new Date(endTime.value);
  if (isNaN(d.getTime()) || d.getTime() <= now.value) return null;
  return d;
});
const endTimeDisplay = computed(() => {
  const d = endDate.value;
  if (!d) return '';
  return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
});
// Живой отсчёт: видно, сколько осталось, без пересчёта в уме.
const countdown = computed(() => {
  const d = endDate.value;
  if (!d) return '';
  const left = Math.round((d.getTime() - now.value) / 60000);
  if (left <= 0) return '';
  if (left < 60) return `${left} ${plural(left, 'минута', 'минуты', 'минут')}`;
  const h = Math.floor(left / 60);
  const m = left % 60;
  return `${h} ${plural(h, 'час', 'часа', 'часов')}${m ? ` ${m} мин` : ''}`;
});

async function load() {
  try {
    const { data } = await db.from('settings').select('*')
      .or('key.eq.maintenance_mode,key.eq.maintenance_message,key.eq.maintenance_end_time');
    for (const s of data || []) {
      if (s.key === 'maintenance_mode') isOn.value = s.value === 'true';
      if (s.key === 'maintenance_message') message.value = s.value || '';
      if (s.key === 'maintenance_end_time') endTime.value = s.value || null;
    }
    emit('state', isOn.value);
  } catch (e) {
    console.warn('[maintenance] load:', e);
  }
}

// Сколько людей прервёт включение — чтобы решение принималось со знанием дела.
async function loadOnline() {
  try {
    const [u, r] = await Promise.all([
      db.rpc('get_online_users'),
      db.rpc('get_online_restaurants'),
    ]);
    onlineUsers.value = (u.data || []).length;
    onlineRestaurants.value = (r.data || []).length;
  } catch { /* цифра справочная, без неё вкладка работает */ }
}

async function updateSetting(key, value) {
  const { error } = await db.from('settings').update({ value }).eq('key', key);
  if (error) toast.error('Ошибка', 'Не удалось сохранить настройку');
  return !error;
}

async function toggle() {
  const next = !isOn.value;
  if (next) {
    // Включение выкидывает всех разом — спрашиваем, тем более что видно, скольких.
    const who = [];
    if (onlineUsers.value) who.push(`${onlineUsers.value} ${plural(onlineUsers.value, 'сотрудник', 'сотрудника', 'сотрудников')}`);
    if (onlineRestaurants.value) who.push(`${onlineRestaurants.value} ${plural(onlineRestaurants.value, 'ресторан', 'ресторана', 'ресторанов')}`);
    const tail = who.length ? ` Сейчас в системе ${who.join(' и ')}.` : '';
    const ok = await appConfirm(`Портал станет недоступен для всех, кроме администраторов.${tail}`, {
      title: 'Включить техработы?', okText: 'Включить', danger: true,
    });
    if (!ok) return;
  }

  saving.value = true;
  try {
    const { error } = await db.from('settings').update({ value: String(next) }).eq('key', 'maintenance_mode');
    if (error) { toast.error('Ошибка', ''); return; }
    isOn.value = next;
    userStore.maintenanceMode = next;
    emit('state', next);
    if (!next) {
      // Выключили руками — таймер больше не нужен.
      await updateSetting('maintenance_end_time', '');
      endTime.value = null;
      userStore.maintenanceEndTime = null;
    }
    toast.success(next ? 'Техработы включены' : 'Техработы выключены', '');
  } finally {
    saving.value = false;
  }
}

function setQuickTimer(minutes) {
  const d = new Date(Date.now() + minutes * 60000);
  timeInput.value = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
  saveExactTime();
}

async function saveExactTime() {
  if (!timeInput.value) return;
  timerSaving.value = true;
  try {
    const [hh, mm] = timeInput.value.split(':').map(Number);
    if (!Number.isFinite(hh) || !Number.isFinite(mm) || hh < 0 || hh > 23 || mm < 0 || mm > 59) {
      toast.error('Неверное время', 'Формат ЧЧ:ММ');
      return;
    }
    const target = new Date();
    target.setHours(hh, mm, 0, 0);
    // Время уже прошло — значит имеется в виду завтра.
    if (target.getTime() <= Date.now()) target.setDate(target.getDate() + 1);
    const value = target.toISOString();
    if (!(await updateSetting('maintenance_end_time', value))) return;
    endTime.value = value;
    userStore.maintenanceEndTime = value;
    toast.success('Таймер установлен', `Выключится в ${timeInput.value}`);
  } catch {
    toast.error('Ошибка', '');
  } finally {
    timerSaving.value = false;
  }
}

async function clearTimer() {
  timerSaving.value = true;
  try {
    if (!(await updateSetting('maintenance_end_time', ''))) return;
    endTime.value = null;
    userStore.maintenanceEndTime = null;
    timeInput.value = '';
    toast.success('Таймер сброшен', '');
  } finally {
    timerSaving.value = false;
  }
}

async function saveMessage() {
  msgSaving.value = true;
  try {
    const { error } = await db.from('settings').update({ value: message.value }).eq('key', 'maintenance_message');
    if (error) { toast.error('Ошибка', ''); return; }
    toast.success('Сообщение сохранено', '');
  } finally {
    msgSaving.value = false;
  }
}

onMounted(() => {
  load();
  loadOnline();
  tick = setInterval(() => { now.value = Date.now(); }, 30000);
});
onBeforeUnmount(() => { if (tick) clearInterval(tick); });
</script>

<style scoped>
.mnt { display: flex; flex-direction: column; gap: 12px; }

.mnt-main {
  display: flex; align-items: center; gap: 18px;
  padding: 18px 20px; border-radius: 12px;
  background: var(--card); border: 1px solid var(--border-light);
}
.mnt-main.on { border-color: #EF9A9A; background: linear-gradient(180deg, #FFF5F5, var(--card)); }
.mnt-main-icon {
  width: 52px; height: 52px; border-radius: 16px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  background: rgba(46, 125, 50, .1); color: #2E7D32;
}
.mnt-main.on .mnt-main-icon { background: rgba(211, 47, 47, .1); color: #D32F2F; }
.mnt-main-body { flex: 1; min-width: 0; }
.mnt-main-title { margin: 0; font-size: 16px; font-weight: 700; color: var(--text); }
.mnt-main-desc { margin: 4px 0 0; font-size: 13px; color: var(--text-muted); line-height: 1.5; }

.mnt-toggle {
  display: flex; align-items: center; gap: 10px; flex-shrink: 0;
  border: none; background: none; cursor: pointer; font-family: inherit;
}
.mnt-toggle:disabled { opacity: .5; pointer-events: none; }
.mnt-track {
  width: 52px; height: 28px; border-radius: 14px; padding: 3px;
  background: var(--border); transition: background .2s; display: block;
}
.mnt-toggle.on .mnt-track { background: #D32F2F; }
.mnt-thumb {
  width: 22px; height: 22px; border-radius: 50%; background: #fff; display: block;
  transition: transform .2s; box-shadow: 0 1px 3px rgba(0, 0, 0, .25);
}
.mnt-toggle.on .mnt-thumb { transform: translateX(24px); }
.mnt-label { font-size: 13px; font-weight: 700; color: var(--text-secondary); }

.mnt-who, .mnt-alarm {
  display: flex; align-items: center; gap: 10px;
  padding: 11px 14px; border-radius: 10px; font-size: 13px;
}
.mnt-who { background: #FFF8E1; border: 1px solid #FFE0B2; color: #8D6E00; }
.mnt-alarm { background: #FFF0F0; border: 1px solid #FFCDD2; color: #C62828; }

.mnt-card {
  background: var(--card); border: 1px solid var(--border-light);
  border-radius: 12px; padding: 14px 16px;
}
.mnt-card-title { margin: 0; font-size: 13px; font-weight: 700; color: var(--text); }
.mnt-card-hint { margin: 4px 0 12px; font-size: 12px; color: var(--text-muted); line-height: 1.5; }

.mnt-quick { display: flex; gap: 8px; flex-wrap: wrap; }
.mnt-quick-btn {
  padding: 7px 14px; border-radius: 8px; cursor: pointer; font-family: inherit;
  font-size: 13px; font-weight: 600; color: var(--text-secondary);
  border: 1.5px solid var(--border-light); background: var(--bg); transition: all .15s;
}
.mnt-quick-btn:hover { border-color: var(--bk-orange); color: var(--text); }
.mnt-quick-btn:disabled { opacity: .5; pointer-events: none; }

.mnt-exact { margin-top: 14px; }
.mnt-exact-label { display: block; font-size: 12px; color: var(--text-muted); margin-bottom: 6px; }
.mnt-exact-row { display: flex; gap: 8px; flex-wrap: wrap; }
.mnt-time {
  padding: 8px 12px; font-family: inherit; font-size: 14px;
  border: 1.5px solid var(--border-light); border-radius: 8px;
  background: var(--card); color: var(--text);
}
.mnt-time:focus { outline: none; border-color: var(--bk-orange); }

.mnt-timer {
  margin-top: 14px; padding: 10px 14px; border-radius: 8px;
  font-size: 13px; color: var(--text-muted);
  background: var(--bg); border: 1px solid var(--border-light);
  display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;
}
.mnt-timer.on { background: #FFF8E1; border-color: #FFE0B2; color: #8D6E00; }
.mnt-timer-clear {
  border: 1px solid #E57373; background: none; color: #D32F2F;
  padding: 4px 12px; border-radius: 6px; font-size: 12px; font-weight: 600;
  font-family: inherit; cursor: pointer;
}
.mnt-timer-clear:hover { background: #FFF0F0; }

.mnt-textarea {
  width: 100%; padding: 10px 12px; font-family: inherit; font-size: 13.5px;
  border: 1.5px solid var(--border-light); border-radius: 8px; resize: vertical;
  background: var(--card); color: var(--text); box-sizing: border-box;
}
.mnt-textarea:focus { outline: none; border-color: var(--bk-orange); }

.mnt-preview { margin-top: 12px; }
.mnt-preview-label {
  font-size: 10.5px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .3px; color: var(--text-muted); margin-bottom: 6px;
}
.mnt-preview-box {
  padding: 16px; border-radius: 10px; text-align: center;
  background: var(--bg); border: 1px dashed var(--border);
}
.mnt-preview-title { font-size: 15px; font-weight: 700; color: var(--text); }
.mnt-preview-text { margin-top: 4px; font-size: 13px; color: var(--text-muted); line-height: 1.5; }

.mnt-save { margin-top: 12px; }

@media (max-width: 600px) {
  .mnt-main { flex-wrap: wrap; gap: 12px; }
  .mnt-main-body { flex: 1 1 100%; order: 3; }
  .mnt-toggle { margin-left: auto; }
  .mnt-quick-btn { flex: 1; }
  .mnt-exact-row .btn { flex: 1; justify-content: center; }
}
</style>
