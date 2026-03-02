<template>
  <Teleport to="body">
    <div v-if="show" class="modal" @click.self="$emit('close')">
      <div class="modal-box audit-log-modal-box">
        <div class="modal-header">
          <h2><BkIcon name="note" size="sm"/> История изменений</h2>
          <button class="modal-close" @click="$emit('close')"><BkIcon name="close" size="sm"/></button>
        </div>
        <div class="audit-log-body">
          <div v-if="loading" style="text-align:center;padding:24px;color:var(--text-muted);">Загрузка...</div>
          <div v-else-if="!entries.length" style="text-align:center;padding:24px;color:var(--text-muted);font-size:13px;">Нет записей в истории</div>
          <div v-else class="audit-log-entries">
            <div v-for="log in entries" :key="log.id" class="audit-log-entry">
              <div class="audit-log-head">
                <span class="audit-badge" :class="badgeClass(log.action)">{{ badgeLabel(log.action) }}</span>
                <span class="audit-author">{{ log.user_name || '—' }}</span>
                <span class="audit-date">{{ formatDate(log.created_at) }}</span>
              </div>
              <!-- Restaurant number -->
              <div v-if="log.details?.restaurant_number" class="audit-restaurant-num">Ресторан {{ log.details.restaurant_number }}</div>
              <!-- Param changes -->
              <div v-if="log.details?.param_changes?.length" class="audit-params">
                <span v-for="(pc, pi) in log.details.param_changes" :key="pi" class="audit-param-chip">
                  {{ pc.label }}: {{ pc.from }} → {{ pc.to }}
                </span>
              </div>
              <!-- Full schedule snapshot -->
              <div v-if="log.details?.full_schedule" class="audit-schedule-row">
                <span
                  v-for="day in ['ПН','ВТ','СР','ЧТ','ПТ','СБ']"
                  :key="day"
                  class="audit-sched-cell"
                  :class="{ 'audit-sched-has': log.details.full_schedule[day] }"
                >
                  <span class="audit-sched-day">{{ day }}</span>
                  <span class="audit-sched-time">{{ log.details.full_schedule[day] || '—' }}</span>
                </span>
              </div>
              <!-- Delivery date changed -->
              <div v-if="log.action === 'delivery_date_changed' && log.details?.old_date" class="audit-delivery-info">
                {{ log.details.old_date }} → {{ log.details.new_date }}
              </div>
              <!-- Received -->
              <div v-if="log.action === 'received'" class="audit-received-info">
                <span>{{ log.details?.items_count || 0 }} позиций</span>
                <span v-if="log.details?.discrepancies" class="audit-discrepancy-badge">{{ log.details.discrepancies }} расхождений</span>
                <span v-else class="audit-no-discrepancy">без расхождений</span>
              </div>
              <div v-if="log.action === 'received' && log.details?.items_with_discrepancy?.length" class="audit-changes">
                <span v-for="(item, i) in log.details.items_with_discrepancy" :key="i" class="audit-ch-chip audit-ch-upd">
                  {{ item.name }}: {{ item.ordered }} → {{ item.received }}
                </span>
              </div>
              <!-- Reception reverted -->
              <div v-if="log.action === 'reception_reverted' && log.details?.reverted_from" class="audit-meta">
                Отменена приёмка от {{ log.details.reverted_from }}
              </div>
              <div v-if="log.details?.note" class="audit-note-line">{{ log.details.note }}</div>
              <div v-if="log.details?.items_count && log.action !== 'received'" class="audit-meta">{{ log.details.items_count }} позиций</div>
              <!-- Item changes -->
              <div v-if="log.details?.changes?.length" class="audit-changes">
                <span v-for="(c, ci) in log.details.changes" :key="ci" class="audit-ch-chip" :class="{ 'audit-ch-add': c.type==='added', 'audit-ch-del': c.type==='removed', 'audit-ch-upd': c.type==='changed' }">
                  <template v-if="c.type === 'added'">+ {{ c.item }} {{ c.boxes }}кор</template>
                  <template v-else-if="c.type === 'removed'">− {{ c.item }} {{ c.boxes }}кор</template>
                  <template v-else>{{ c.item }}: {{ c.diffs?.join(', ') }}</template>
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import BkIcon from '@/components/ui/BkIcon.vue';

defineProps({
  show: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  entries: { type: Array, default: () => [] },
});
defineEmits(['close']);

const ACTION_LABELS = {
  order_created: 'Создан',
  order_updated: 'Изменён',
  order_deleted: 'Удалён',
  plan_created: 'Создан',
  plan_updated: 'Изменён',
  plan_deleted: 'Удалён',
  delivery_date_changed: 'Дата доставки',
  received: 'Принят',
  reception_reverted: 'Приёмка отменена',
  schedule_updated: 'График',
  restaurant_updated: 'Ресторан',
};

function badgeLabel(action) {
  return ACTION_LABELS[action] || action;
}

function badgeClass(action) {
  if (action === 'received') return 'audit-badge-received';
  if (action === 'reception_reverted') return 'audit-badge-reverted';
  if (action === 'delivery_date_changed') return 'audit-badge-delivery';
  if (action === 'schedule_updated') return 'audit-badge-schedule';
  if (action === 'restaurant_updated') return 'audit-badge-restaurant';
  if (action.includes('created')) return 'audit-badge-created';
  if (action.includes('updated')) return 'audit-badge-updated';
  if (action.includes('deleted')) return 'audit-badge-deleted';
  return '';
}

function formatDate(str) {
  if (!str) return '';
  const d = new Date(str);
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' }) + ' ' +
         d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}
</script>

<style scoped>
.audit-log-modal-box { max-width: 560px; }
.audit-log-body { max-height: 450px; overflow-y: auto; padding: 0 20px 16px; }
.audit-log-entries { display: flex; flex-direction: column; }
.audit-log-entry { padding: 10px 0; border-bottom: 1px solid var(--border-light); }
.audit-log-entry:last-child { border-bottom: none; }
.audit-log-head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

.audit-badge { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 10px; font-weight: 700; }
.audit-badge-created { background: #E8F5E9; color: #2E7D32; }
.audit-badge-updated { background: #FFF3E0; color: #E65100; }
.audit-badge-deleted { background: #FFEBEE; color: #C62828; }
.audit-badge-received { background: #E0F2F1; color: #00695C; }
.audit-badge-reverted { background: #FFF3E0; color: #BF360C; }
.audit-badge-delivery { background: #E3F2FD; color: #1565C0; }
.audit-badge-schedule { background: #E8EAF6; color: #283593; }
.audit-badge-restaurant { background: #FFF3E0; color: #E65100; }
.audit-restaurant-num { font-size: 11px; font-weight: 700; color: var(--bk-brown, #502314); margin-top: 4px; }

.audit-schedule-row {
  display: flex; gap: 3px; margin-top: 6px; flex-wrap: wrap;
}
.audit-sched-cell {
  display: flex; flex-direction: column; align-items: center;
  min-width: 52px; padding: 3px 4px; border-radius: 4px;
  background: #F5F5F5; border: 1px solid #E0E0E0;
}
.audit-sched-cell.audit-sched-has {
  background: #E8F5E9; border-color: #A5D6A7;
}
.audit-sched-day {
  font-size: 9px; font-weight: 700; color: #888; letter-spacing: 0.3px;
}
.audit-sched-has .audit-sched-day { color: #2E7D32; }
.audit-sched-time {
  font-size: 10px; font-weight: 700; color: #BDBDBD; white-space: nowrap;
}
.audit-sched-has .audit-sched-time { color: #1B5E20; }

.audit-author { font-weight: 600; font-size: 12px; color: var(--text); }
.audit-date { font-size: 11px; color: var(--text-muted); }
.audit-note-line { font-size: 11px; color: var(--text-secondary); font-style: italic; margin-top: 3px; }
.audit-meta { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

.audit-params { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 5px; }
.audit-param-chip {
  display: inline-block; padding: 1px 7px; border-radius: 4px;
  font-size: 11px; background: #EDE7F6; color: #4A148C; font-weight: 500;
}

.audit-changes { display: flex; flex-wrap: wrap; gap: 3px; margin-top: 5px; }
.audit-ch-chip {
  display: inline-block; padding: 1px 6px; border-radius: 4px;
  font-size: 10px; font-weight: 600; line-height: 1.5;
}
.audit-ch-add { background: #E8F5E9; color: #2E7D32; }
.audit-ch-del { background: #FFEBEE; color: #C62828; }
.audit-ch-upd { background: #FFF8E1; color: #5D4037; }

.audit-delivery-info {
  display: inline-flex; align-items: center; gap: 4px;
  margin-top: 5px; padding: 2px 8px; border-radius: 4px;
  font-size: 11px; font-weight: 600;
  background: #E3F2FD; color: #1565C0;
}
.audit-received-info {
  display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
  margin-top: 5px; font-size: 11px;
}
.audit-discrepancy-badge {
  display: inline-block; padding: 1px 7px; border-radius: 4px;
  background: #FFF8E1; color: #E65100; font-weight: 600;
}
.audit-no-discrepancy {
  display: inline-block; padding: 1px 7px; border-radius: 4px;
  background: #E8F5E9; color: #2E7D32; font-weight: 500;
}
</style>
