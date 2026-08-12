<template>
  <div class="mch">
    <div class="mch-head">
      <div class="mch-title">
        <h2>Как связаться с рестораном</h2>
        <span>Через какие каналы до ресторана дойдёт напоминание. Пусто во всех трёх — не дойдёт никак.</span>
      </div>
      <div class="mch-controls">
        <div class="mch-groups">
          <button :class="{ active: group === 'BK_VM' }" type="button" @click="group = 'BK_VM'">БК+ВМ</button>
          <button :class="{ active: group === 'PS' }" type="button" @click="group = 'PS'">Пицца Стар</button>
        </div>
        <button class="mch-btn" type="button" :disabled="loading" @click="load">
          {{ loading ? 'Обновление…' : 'Обновить' }}
        </button>
      </div>
    </div>

    <div v-if="summary" class="mch-summary">
      <button class="mch-sum" :class="{ active: filter === 'all' }" type="button" @click="filter = 'all'">
        <b>{{ summary.total }}</b><span>всего ресторанов</span>
      </button>
      <button class="mch-sum" :class="{ active: filter === 'unreachable' }" type="button" @click="filter = 'unreachable'">
        <b :class="{ bad: summary.unreachable > 0 }">{{ summary.unreachable }}</b><span>не получат напоминаний</span>
      </button>
      <button class="mch-sum" :class="{ active: filter === 'blocked' }" type="button" @click="filter = 'blocked'">
        <b :class="{ warn: summary.blocked > 0 }">{{ summary.blocked }}</b><span>заблокировали бота</span>
      </button>
      <button class="mch-sum" :class="{ active: filter === 'nomail' }" type="button" @click="filter = 'nomail'">
        <b>{{ summary.total - summary.mail }}</b><span>без подтверждённой почты</span>
      </button>
    </div>

    <div v-if="loading && !rows.length" class="mch-state">Загружаем…</div>
    <div v-else-if="error" class="mch-state mch-state-err">{{ error }}</div>
    <div v-else-if="!filtered.length" class="mch-state">Ничего не найдено.</div>

    <div v-else class="mch-table-wrap">
      <table class="mch-table">
        <thead>
          <tr>
            <th class="mch-c-num">Ресторан</th>
            <th class="mch-c-where">Адрес</th>
            <th class="mch-c-ch">Telegram</th>
            <th class="mch-c-ch">Браузер</th>
            <th class="mch-c-ch">Почта</th>
            <th class="mch-c-last">Заходили в кабинет</th>
            <th class="mch-c-do">Что делать</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in filtered" :key="r.number" :class="{ 'is-unreachable': r.unreachable }">
            <td class="mch-c-num"><b>{{ r.display }}</b></td>
            <td class="mch-c-where">{{ r.where || '—' }}</td>
            <td class="mch-c-ch">
              <span class="mch-dot" :class="tgClass(r)" :title="tgTitle(r)">{{ tgText(r) }}</span>
            </td>
            <td class="mch-c-ch">
              <span class="mch-dot" :class="r.push === 'ok' ? 'ok' : 'off'"
                    :title="r.push === 'ok' ? `Уведомления в браузере: ${r.push_count}` : 'Уведомления в браузере не включены'">
                {{ r.push === 'ok' ? r.push_count : '—' }}
              </span>
            </td>
            <td class="mch-c-ch">
              <span class="mch-dot" :class="mailClass(r)" :title="mailTitle(r)">{{ mailText(r) }}</span>
            </td>
            <td class="mch-c-last" :class="{ stale: staleDays(r) > 30 }">{{ lastLoginText(r) }}</td>
            <td class="mch-c-do">{{ advice(r) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
/**
 * Вкладка «Связь» в разделе «Кабинеты ресторанов».
 *
 * Дыры в каналах связи раньше не было видно нигде: ресторан №17 не получал
 * напоминаний с 12 июня, и узнать об этом можно было только из служебного
 * журнала. По Пицце Стар оказалось не подключено 49 точек из 51.
 *
 * Колонка «Что делать» намеренно разделяет два разных случая:
 * «заблокировали бота» лечится звонком, «не подключался» — настройкой.
 */
import { ref, computed, watch, onMounted } from 'vue';
import { db } from '@/lib/apiClient.js';
import { parseMoscowDate } from '@/lib/utils.js';

const group   = ref('BK_VM');
const filter  = ref('all');
const rows    = ref([]);
const summary = ref(null);
const loading = ref(false);
const error   = ref('');

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const { data, error: err } = await db.rpc('restaurant_channels', { group: group.value });
    if (err) throw new Error(err);
    rows.value = data?.restaurants || [];
    summary.value = data?.summary || null;
  } catch (e) {
    error.value = 'Не удалось загрузить: ' + (e.message || e);
    rows.value = [];
    summary.value = null;
  } finally {
    loading.value = false;
  }
}
watch(group, load);
onMounted(load);

const filtered = computed(() => {
  const list = rows.value;
  if (filter.value === 'unreachable') return list.filter(r => r.unreachable);
  if (filter.value === 'blocked')     return list.filter(r => r.telegram === 'blocked' || r.telegram === 'partial');
  if (filter.value === 'nomail')      return list.filter(r => r.mail !== 'ok');
  return list;
});

function tgClass(r) {
  if (r.telegram === 'ok')      return 'ok';
  if (r.telegram === 'partial') return 'warn';
  if (r.telegram === 'blocked') return 'bad';
  return 'off';
}
function tgText(r) {
  if (r.telegram === 'ok' || r.telegram === 'partial') return String(r.tg_alive);
  if (r.telegram === 'blocked') return 'блок';
  return '—';
}
function tgTitle(r) {
  if (r.telegram === 'ok')      return `Получают напоминания: ${r.tg_alive}`;
  if (r.telegram === 'partial') return `Получают ${r.tg_alive}, заблокировали бота ${r.tg_blocked}` + (r.tg_blocked_at ? ` (${fmt(r.tg_blocked_at)})` : '');
  if (r.telegram === 'blocked') return 'Бота заблокировали' + (r.tg_blocked_at ? ` ${fmt(r.tg_blocked_at)}` : '') + ' — напоминания не доходят';
  return 'Telegram не подключали';
}

function mailClass(r) {
  if (r.mail === 'ok') return 'ok';
  if (r.mail === 'unverified') return 'warn';
  return 'off';
}
function mailText(r) {
  if (r.mail === 'ok') return '✓';
  if (r.mail === 'unverified') return '!';
  return '—';
}
function mailTitle(r) {
  if (r.mail === 'ok') return 'Почта подтверждена: работают вход по почте и сброс пароля';
  if (r.mail === 'unverified') return 'Почта указана, но не подтверждена — вход по почте и сброс пароля не работают';
  return 'Почта не указана';
}

function fmt(d) {
  if (!d) return '';
  const s = String(d).slice(0, 10).split('-');
  return s.length === 3 ? `${s[2]}.${s[1]}.${s[0]}` : String(d);
}
function staleDays(r) {
  if (!r.last_login) return 9999;
  // Время с сервера московское — parseMoscowDate ставит смещение,
  // иначе «сегодня» превращалось во «вчера» у части пользователей.
  const d = parseMoscowDate(r.last_login);
  if (!d || isNaN(d.getTime())) return 9999;
  return Math.floor((Date.now() - d.getTime()) / 86400000);
}
function lastLoginText(r) {
  if (!r.last_login) return 'ни разу';
  const d = staleDays(r);
  if (d <= 0) return 'сегодня';
  if (d === 1) return 'вчера';
  return `${fmt(r.last_login)} (${d} дн. назад)`;
}

function advice(r) {
  if (r.telegram === 'blocked') return 'Позвонить: разблокировать бота';
  if (r.unreachable)            return 'Настроить Telegram или уведомления';
  if (r.telegram === 'partial') return `Один сотрудник заблокировал бота (${r.tg_blocked})`;
  if (r.mail === 'unverified')  return 'Попросить подтвердить почту';
  if (r.mail === 'none')        return 'Можно добавить почту — для сброса пароля';
  return '';
}
</script>

<style scoped>
.mch { display: flex; flex-direction: column; gap: 14px; }

.mch-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.mch-title h2 { margin: 0 0 4px; font-size: 16px; }
.mch-title span { font-size: 12.5px; color: var(--text-muted, #7a8794); }
.mch-controls { display: flex; align-items: center; gap: 10px; }

.mch-groups { display: inline-flex; border: 1px solid var(--border-color, #d7dce2); border-radius: 7px; overflow: hidden; }
.mch-groups button {
  background: none; border: none; cursor: pointer; padding: 6px 12px;
  font-size: 12.5px; color: inherit;
}
.mch-groups button.active { background: var(--accent-bg, #2a4a7f); color: #fff; }

.mch-btn {
  border: 1px solid var(--border-color, #d7dce2); background: none; color: inherit;
  border-radius: 7px; padding: 6px 12px; font-size: 12.5px; cursor: pointer;
}
.mch-btn:disabled { opacity: .55; cursor: default; }

.mch-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(9.5rem, 1fr)); gap: 8px; }
.mch-sum {
  background: var(--card-bg, #fff); border: 1px solid var(--border-color, #e3e6ea);
  border-radius: 8px; padding: 10px 12px; cursor: pointer; text-align: left; color: inherit;
}
.mch-sum.active { border-color: var(--accent-bg, #2a4a7f); }
.mch-sum b { display: block; font-size: 20px; font-variant-numeric: tabular-nums; }
.mch-sum b.bad { color: #b4432e; }
.mch-sum b.warn { color: #9a6b12; }
.mch-sum span { font-size: 11.5px; color: var(--text-muted, #7a8794); }

.mch-state { padding: 28px; text-align: center; color: var(--text-muted, #7a8794); font-size: 13px; }
.mch-state-err { color: #b4432e; }

/* Таблица широкая — прокручиваем её саму, а не всю страницу */
.mch-table-wrap { overflow-x: auto; border: 1px solid var(--border-color, #e3e6ea); border-radius: 8px; }
.mch-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.mch-table th, .mch-table td {
  padding: 7px 10px; border-bottom: 1px solid var(--border-color, #eceff2);
  text-align: left; white-space: nowrap;
}
.mch-table th { font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted, #7a8794); font-weight: 600; }
.mch-table tbody tr:last-child td { border-bottom: none; }
.mch-table tbody tr.is-unreachable { background: rgba(180, 67, 46, .07); }

.mch-c-where { white-space: normal; max-width: 22rem; }
.mch-c-ch { text-align: center; width: 6.5rem; }
.mch-c-last.stale { color: #9a6b12; }
.mch-c-do { color: var(--text-muted, #7a8794); }

.mch-dot {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 2.1rem; padding: 1px 6px; border-radius: 10px;
  font-size: 11.5px; font-weight: 600; font-variant-numeric: tabular-nums;
  cursor: help;
}
.mch-dot.ok   { background: rgba(46, 107, 79, .14);  color: #2e6b4f; }
.mch-dot.warn { background: rgba(154, 107, 18, .16); color: #9a6b12; }
.mch-dot.bad  { background: rgba(180, 67, 46, .14);  color: #b4432e; }
.mch-dot.off  { background: var(--surface-sunk, #eef1f4); color: var(--text-muted, #7a8794); }

@media (max-width: 640px) {
  .mch-head { flex-direction: column; }
  .mch-c-where { max-width: 12rem; }
}
</style>
