<template>
  <div class="bm-tab">
    <div class="bm-head">
      <div>
        <h2>Мониторинг Telegram-бота</h2>
        <p class="bm-sub" v-if="data?.generated_at">
          обновлено {{ formatTs(data.generated_at) }}
          <button class="bm-refresh" @click="load" :disabled="loading">
            <BkIcon name="refresh" size="sm"/> обновить
          </button>
        </p>
      </div>
    </div>

    <div v-if="loading && !data" class="bm-loading">
      <BurgerSpinner text="Загружаем статистику…" />
    </div>
    <div v-else-if="error" class="bm-error">⚠ {{ error }}</div>

    <template v-else-if="data">
      <!-- Сводные карточки -->
      <div class="bm-cards">
        <div class="bm-card">
          <div class="bm-card-label">Всего за 24 ч</div>
          <div class="bm-card-value">{{ formatNumber(data.totals?.total_24h) }}</div>
        </div>
        <div class="bm-card bm-card-ok">
          <div class="bm-card-label">Успешно</div>
          <div class="bm-card-value">{{ formatNumber(data.totals?.ok_24h) }}</div>
          <div class="bm-card-sub">{{ okPct }}%</div>
        </div>
        <div class="bm-card bm-card-fail">
          <div class="bm-card-label">С ошибкой</div>
          <div class="bm-card-value">{{ formatNumber(data.totals?.fail_24h) }}</div>
          <div class="bm-card-sub">{{ failPct }}%</div>
        </div>
        <div class="bm-card">
          <div class="bm-card-label">Заблокировали бота</div>
          <div class="bm-card-value">{{ (data.blocked?.users || 0) + (data.blocked?.ro_telegram || 0) }}</div>
          <div class="bm-card-sub">
            сотрудников: {{ data.blocked?.users || 0 }} · ресторанов: {{ data.blocked?.ro_telegram || 0 }}
          </div>
        </div>
      </div>

      <!-- Поминутный график за 24 ч -->
      <div v-if="data.timeline_24h?.length" class="bm-section">
        <h3>Активность по часам (за 24 ч)</h3>
        <div class="bm-timeline">
          <div v-for="t in timelineForChart" :key="t.bucket" class="bm-bar"
               :title="`${t.bucket}: всего ${t.total}, ошибок ${t.fail_count}`">
            <div class="bm-bar-fail" :style="{ height: t.failHeight + '%' }"></div>
            <div class="bm-bar-ok" :style="{ height: t.okHeight + '%' }"></div>
            <div class="bm-bar-label">{{ t.bucket.slice(11, 13) }}</div>
          </div>
        </div>
        <div class="bm-legend">
          <span class="bm-legend-ok"></span> успех
          <span class="bm-legend-fail"></span> ошибка
        </div>
      </div>

      <!-- AI-блокировки -->
      <div v-if="data.ai_blocked?.length" class="bm-section">
        <h3>AI-провайдеры в блокировке</h3>
        <table class="bm-table">
          <thead><tr><th>Провайдер</th><th>Модель</th><th>До</th><th>Причина</th></tr></thead>
          <tbody>
            <tr v-for="(a, i) in data.ai_blocked" :key="i">
              <td>{{ a.provider }}</td>
              <td><code>{{ a.model || '—' }}</code></td>
              <td>{{ formatTs(a.blocked_until) }}</td>
              <td>{{ a.reason || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="bm-row">
        <!-- По методу -->
        <div class="bm-section bm-half">
          <h3>По типам вызова (за 24 ч)</h3>
          <table class="bm-table">
            <thead><tr><th>Метод</th><th>Всего</th><th>Ошибок</th></tr></thead>
            <tbody>
              <tr v-for="(m, i) in (data.by_method || [])" :key="i" :class="{ 'bm-row-bad': m.fail_count > 0 }">
                <td><code>{{ m.method }}</code></td>
                <td>{{ formatNumber(m.total) }}</td>
                <td>{{ m.fail_count > 0 ? formatNumber(m.fail_count) : '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- По error_code -->
        <div class="bm-section bm-half">
          <h3>Коды ошибок (за 24 ч)</h3>
          <div v-if="!data.by_error_code?.length" class="bm-empty">✓ Ошибок не было</div>
          <table v-else class="bm-table">
            <thead><tr><th>Код</th><th>Сколько раз</th><th>Что значит</th></tr></thead>
            <tbody>
              <tr v-for="(e, i) in (data.by_error_code || [])" :key="i">
                <td><code>{{ e.error_code }}</code></td>
                <td>{{ formatNumber(e.cnt) }}</td>
                <td class="bm-error-hint">{{ errorCodeHint(e.error_code) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Топ заблокированных -->
      <div v-if="data.top_failing?.length" class="bm-section">
        <h3>Чаще всего ошибки (за 24 ч)</h3>
        <table class="bm-table">
          <thead><tr><th>Chat ID</th><th>Кол-во ошибок</th></tr></thead>
          <tbody>
            <tr v-for="(t, i) in data.top_failing" :key="i">
              <td><code>{{ t.chat_id }}</code></td>
              <td>{{ formatNumber(t.cnt) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Последние ошибки -->
      <div v-if="data.last_failures?.length" class="bm-section">
        <h3>Последние ошибки</h3>
        <table class="bm-table">
          <thead><tr><th>Время</th><th>Метод</th><th>Chat ID</th><th>HTTP</th><th>Код</th><th>Описание</th></tr></thead>
          <tbody>
            <tr v-for="(f, i) in data.last_failures" :key="i">
              <td>{{ formatTs(f.ts) }}</td>
              <td><code>{{ f.method }}</code></td>
              <td><code>{{ f.chat_id || '—' }}</code></td>
              <td>{{ f.http_code }}</td>
              <td>{{ f.error_code || '—' }}</td>
              <td class="bm-err-text">{{ f.error_text || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { db } from '@/lib/apiClient.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';

const data = ref(null);
const loading = ref(false);
const error = ref('');

async function load() {
  loading.value = true;
  error.value = '';
  try {
    // db.rpc возвращает { data, error } — распаковываем. Без этого
    // data.value = { data: {...}, error: null } и шаблон падал на
    // data.totals === undefined.
    const r = await db.rpc('tg_admin_monitor', {});
    if (r?.error) throw new Error(r.error);
    data.value = r?.data ?? null;
  } catch (e) {
    error.value = e?.message || 'Не удалось загрузить статистику';
  } finally {
    loading.value = false;
  }
}

defineExpose({ load });
onMounted(load);

const okPct = computed(() => {
  const t = data.value?.totals?.total_24h || 0;
  if (!t) return 0;
  return Math.round(((data.value?.totals?.ok_24h || 0) / t) * 100);
});
const failPct = computed(() => {
  const t = data.value?.totals?.total_24h || 0;
  if (!t) return 0;
  return Math.round(((data.value?.totals?.fail_24h || 0) / t) * 100);
});

const timelineForChart = computed(() => {
  if (!data.value?.timeline_24h?.length) return [];
  const max = Math.max(1, ...data.value.timeline_24h.map(t => t.total));
  return data.value.timeline_24h.map(t => {
    const okCnt = t.total - t.fail_count;
    return {
      ...t,
      okHeight: Math.round((okCnt / max) * 100),
      failHeight: Math.round((t.fail_count / max) * 100),
    };
  });
});

function formatNumber(n) {
  return new Intl.NumberFormat('ru-RU').format(n || 0);
}
function formatTs(ts) {
  if (!ts) return '—';
  // ts формат: 'YYYY-MM-DD HH:mm:ss' (MSK)
  const d = new Date(ts.replace(' ', 'T'));
  if (isNaN(d.getTime())) return ts;
  const today = new Date();
  const sameDay = d.toDateString() === today.toDateString();
  const time = d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  if (sameDay) return time;
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' }) + ' ' + time;
}
function errorCodeHint(code) {
  const hints = {
    400: 'Битый запрос (часто — сломанная HTML-разметка)',
    401: 'Неверный токен бота',
    403: 'Пользователь заблокировал бота / удалил аккаунт',
    404: 'Сообщение или чат не найдены',
    409: 'Конфликт (webhook уже занят?)',
    429: 'Слишком много запросов (rate limit)',
    500: 'Внутренняя ошибка Telegram',
    502: 'Bad Gateway у Telegram',
    503: 'Сервис недоступен',
    504: 'Timeout у Telegram',
  };
  return hints[code] || '—';
}
</script>

<style scoped>
.bm-tab { padding: 12px 4px; }
.bm-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; }
.bm-head h2 { margin: 0 0 4px; font-size: 22px; }
.bm-sub { margin: 0; color: var(--text-muted, #777); font-size: 13px; display: flex; align-items: center; gap: 12px; }
.bm-refresh {
  background: none; border: 1px solid #ddd; border-radius: 6px;
  padding: 4px 10px; cursor: pointer; font-size: 13px;
  display: inline-flex; align-items: center; gap: 4px;
}
.bm-refresh:hover { background: #f5f5f5; }
.bm-refresh:disabled { opacity: 0.5; cursor: not-allowed; }

.bm-loading { text-align: center; padding: 48px; }
.bm-error { background: #fee; border: 1px solid #fcc; padding: 12px; border-radius: 6px; }

.bm-cards {
  display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  margin-bottom: 24px;
}
.bm-card { background: #fff; border: 1px solid #e5e5e5; border-radius: 8px; padding: 14px; }
.bm-card-label { font-size: 12px; color: var(--text-muted, #777); margin-bottom: 4px; }
.bm-card-value { font-size: 26px; font-weight: 600; line-height: 1; }
.bm-card-sub { font-size: 12px; color: var(--text-muted, #777); margin-top: 4px; }
.bm-card-ok { border-color: #b6e3b8; background: #f4fbf4; }
.bm-card-ok .bm-card-value { color: #2c8a30; }
.bm-card-fail { border-color: #f0b9b9; background: #fbf3f3; }
.bm-card-fail .bm-card-value { color: #c63838; }

.bm-section { background: #fff; border: 1px solid #e5e5e5; border-radius: 8px; padding: 14px 16px; margin-bottom: 16px; }
.bm-section h3 { margin: 0 0 12px; font-size: 16px; }
.bm-row { display: grid; gap: 16px; grid-template-columns: 1fr 1fr; }
@media (max-width: 800px) { .bm-row { grid-template-columns: 1fr; } }
.bm-half { margin-bottom: 0; }

.bm-empty { color: var(--text-muted, #777); font-style: italic; padding: 8px 0; }

.bm-table { width: 100%; border-collapse: collapse; }
.bm-table th, .bm-table td { padding: 8px 6px; text-align: left; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
.bm-table th { color: var(--text-muted, #777); font-weight: 500; font-size: 12px; }
.bm-table tr.bm-row-bad td { background: #fdf3f3; }
.bm-table code { background: #f4f4f4; padding: 1px 5px; border-radius: 3px; font-size: 12px; }
.bm-error-hint { color: var(--text-muted, #777); font-size: 12px; }
.bm-err-text { max-width: 360px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.bm-timeline {
  display: flex; gap: 2px; align-items: flex-end; height: 80px;
  border-bottom: 1px solid #ddd; margin-bottom: 8px;
}
.bm-bar { flex: 1; min-width: 8px; height: 100%; position: relative; display: flex; flex-direction: column-reverse; }
.bm-bar-ok { background: #3aa540; width: 100%; }
.bm-bar-fail { background: #d44a4a; width: 100%; }
.bm-bar-label {
  position: absolute; bottom: -16px; left: 50%; transform: translateX(-50%);
  font-size: 9px; color: var(--text-muted, #777);
}
.bm-legend { display: flex; gap: 16px; padding-top: 16px; font-size: 12px; color: var(--text-muted, #777); }
.bm-legend-ok, .bm-legend-fail {
  display: inline-block; width: 10px; height: 10px; vertical-align: middle; margin-right: 4px;
}
.bm-legend-ok { background: #3aa540; }
.bm-legend-fail { background: #d44a4a; }
</style>
