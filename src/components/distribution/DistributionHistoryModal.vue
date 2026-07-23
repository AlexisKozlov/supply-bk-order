<template>
  <Teleport to="body">
    <div class="modal" @click.self="$emit('close')">
      <div class="modal-box dist-history-box">
        <div class="dh-header">
          <h2>🕘 История распределения</h2>
          <button class="dh-close" @click="$emit('close')" title="Закрыть">×</button>
        </div>
        <div class="dh-sub">Сессия: «{{ sessionName }}»</div>

        <div class="dh-body">
          <div v-if="loading && !rows.length" class="dh-empty">Загрузка…</div>
          <div v-else-if="!rows.length" class="dh-empty">Пока никаких действий не было.</div>
          <table v-else class="dh-table">
            <thead>
              <tr>
                <th>Когда</th>
                <th>Кто</th>
                <th>Действие</th>
                <th>Товар</th>
                <th>Ресторан</th>
                <th>Было → Стало</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in rows" :key="r.id">
                <td class="dh-when">{{ fmtWhen(r.created_at) }}</td>
                <td class="dh-who">{{ r.user_name }}</td>
                <td><span class="dh-act" :class="actClass(r.action)">{{ actLabel(r.action) }}</span></td>
                <td class="dh-prod">{{ prodText(r) }}</td>
                <td class="dh-rest">{{ r.restaurant_number ? formatRestaurantNumber(r.restaurant_number) : '—' }}</td>
                <td class="dh-change">{{ changeText(r) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="dh-footer">
          <span class="dh-count">Показано {{ rows.length }} из {{ total }}</span>
          <button v-if="rows.length < total" class="dist-btn ghost sm" :disabled="loading" @click="loadMore">
            {{ loading ? 'Загрузка…' : 'Показать ещё' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { formatRestaurantNumber } from '@/lib/legalEntities.js';
import { db } from '@/lib/apiClient.js';

const props = defineProps({
  sessionId: { type: [Number, String], required: true },
  sessionName: { type: String, default: '' },
});
defineEmits(['close']);

const PAGE = 100;
const rows = ref([]);
const total = ref(0);
const loading = ref(false);

const ACT_LABELS = {
  session_created:   'Создал сессию',
  session_deleted:   'Удалил сессию',
  session_closed:    'Закрыл сессию',
  session_reopened:  'Открыл сессию',
  product_added:     'Добавил товар',
  product_removed:   'Убрал товар',
  note_saved:        'Примечание',
  cell_shipped:      'Отметка отгрузки',
  cell_qty:          'Изменил количество',
  cell_bulk_shipped: 'Массовая отметка',
  cell_bulk_import:  'Импорт из Excel',
};
function actLabel(a) { return ACT_LABELS[a] || a; }
function actClass(a) {
  if (a === 'session_deleted' || a === 'product_removed') return 'danger';
  if (a.startsWith('cell_bulk') || a === 'cell_bulk_import') return 'bulk';
  if (a.startsWith('session_') || a.startsWith('product_')) return 'session';
  return 'cell';
}

function prodText(r) {
  if (r.product_name || r.article) {
    return [r.article, r.product_name].filter(Boolean).join(' ');
  }
  // Для крупных/массовых действий товар кладём в detail
  if (r.action === 'product_removed' || r.action === 'product_added') return r.detail || '—';
  if (r.session_product_id) return '(удалённый товар)';
  return '—';
}

function changeText(r) {
  if (r.old_value != null && r.new_value != null) return `${r.old_value} → ${r.new_value}`;
  if (r.new_value != null) return r.new_value + (r.detail ? ` · ${r.detail}` : '');
  return r.detail || '';
}

function fmtWhen(d) {
  if (!d) return '';
  const dt = new Date(d.replace(' ', 'T'));
  return dt.toLocaleString('ru-RU', {
    day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
  });
}

async function load(reset = false) {
  if (loading.value) return;
  loading.value = true;
  const offset = reset ? 0 : rows.value.length;
  const { data, error } = await db.rpc('dist_get_history', {
    session_id: props.sessionId, limit: PAGE, offset,
  });
  loading.value = false;
  if (error) return;
  if (reset) rows.value = data?.rows || [];
  else rows.value = rows.value.concat(data?.rows || []);
  total.value = data?.total ?? rows.value.length;
}
function loadMore() { load(false); }

onMounted(() => load(true));
</script>

<style scoped>
.modal {
  position: fixed; inset: 0; background: rgba(0,0,0,0.5);
  display: flex; align-items: center; justify-content: center; z-index: 2000;
  padding: 16px;
}
.dist-history-box {
  background: #fff; border-radius: 12px; width: 100%; max-width: 900px;
  max-height: 88vh; display: flex; flex-direction: column;
  box-shadow: 0 12px 40px rgba(0,0,0,0.25);
}
.dh-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 20px 4px;
}
.dh-header h2 { font-size: 18px; margin: 0; }
.dh-close {
  background: transparent; border: none; font-size: 26px; line-height: 1;
  cursor: pointer; color: #9ca3af; padding: 0 4px;
}
.dh-close:hover { color: #374151; }
.dh-sub { padding: 0 20px 12px; color: #6b7280; font-size: 13px; }
.dh-body { overflow: auto; padding: 0 20px; flex: 1; }
.dh-empty { padding: 40px 0; text-align: center; color: #9ca3af; }

.dh-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.dh-table th {
  position: sticky; top: 0; background: #f9fafb; z-index: 1;
  text-align: left; padding: 8px 10px; color: #6b7280; font-weight: 600;
  border-bottom: 1px solid #e5e7eb; white-space: nowrap;
}
.dh-table td {
  padding: 7px 10px; border-bottom: 1px solid #f1f2f4; vertical-align: top;
}
.dh-when { white-space: nowrap; color: #6b7280; }
.dh-who { white-space: nowrap; font-weight: 500; }
.dh-prod { color: #374151; }
.dh-rest { white-space: nowrap; text-align: center; }
.dh-change { color: #1f2937; }

.dh-act {
  display: inline-block; padding: 2px 8px; border-radius: 10px;
  font-size: 12px; white-space: nowrap;
  background: #eef2f7; color: #334155;
}
.dh-act.danger { background: #fdecea; color: #b42318; }
.dh-act.bulk { background: #fff4e6; color: #b45309; }
.dh-act.session { background: #eaf1fb; color: #1d4e89; }
.dh-act.cell { background: #eefaf0; color: #1a7f37; }

.dh-footer {
  display: flex; align-items: center; justify-content: space-between;
  padding: 12px 20px; border-top: 1px solid #eee;
}
.dh-count { color: #9ca3af; font-size: 12px; }

@media (max-width: 640px) {
  .dh-table { font-size: 12px; }
  .dh-table th, .dh-table td { padding: 6px 6px; }
}
</style>
