<template>
  <div class="bkp">
    <div class="bkp-head">
      <div class="bkp-head-icon"><BkIcon name="database" size="lg"/></div>
      <div>
        <h3 class="bkp-title">Резервная копия данных</h3>
        <p class="bkp-desc">
          Выберите таблицы — каждая станет отдельным листом Excel с заголовками и подобранной шириной колонок.
          Большие таблицы выгружаются дольше, объём виден заранее.
        </p>
      </div>
    </div>

    <div class="bkp-card">
      <div class="bkp-card-head">
        <h4 class="bkp-card-title">Юридическое лицо</h4>
        <span class="bkp-card-hint">действует только на таблицы, где юрлицо есть</span>
      </div>
      <select v-model="entity" class="bkp-select">
        <option value="">Все юрлица</option>
        <option v-for="le in entities" :key="le" :value="le">{{ le }}</option>
      </select>
    </div>

    <div class="bkp-card">
      <div class="bkp-card-head">
        <h4 class="bkp-card-title">Таблицы</h4>
        <span class="bkp-card-hint">
          выбрано {{ selected.length }} из {{ tables.length }}<template v-if="selectedRows !== null"> · примерно {{ formatInt(selectedRows) }} строк</template>
        </span>
      </div>

      <div class="bkp-tables">
        <label v-for="t in tables" :key="t.name" class="bkp-table" :class="{ on: selected.includes(t.name) }">
          <input type="checkbox" :value="t.name" v-model="selected" />
          <span class="bkp-check"><BkIcon name="success" size="sm"/></span>
          <span class="bkp-table-name">
            {{ t.label }}
            <span v-if="t.entityAware" class="bkp-tag">по юрлицу</span>
          </span>
          <span class="bkp-table-count">
            <template v-if="counts[t.name] === undefined">…</template>
            <template v-else-if="counts[t.name] === null">—</template>
            <template v-else>{{ formatInt(counts[t.name]) }}</template>
          </span>
        </label>
      </div>

      <div class="bkp-actions">
        <button class="btn" @click="selected = tables.map(t => t.name)">Выбрать все</button>
        <button class="btn" @click="selected = []" :disabled="!selected.length">Снять все</button>
        <button class="btn primary" @click="exportBackup" :disabled="!selected.length || exporting">
          <BkIcon name="excel" size="sm"/>
          {{ exporting ? (progress || 'Выгрузка…') : 'Выгрузить в Excel' }}
        </button>
      </div>

      <p v-if="exporting && progress" class="bkp-progress">{{ progress }}</p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, reactive } from 'vue';
import { db } from '@/lib/apiClient.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import { useToastStore } from '@/stores/toastStore.js';
import { toLocalDateStr, formatInt } from '@/lib/utils.js';
import { LEGAL_ENTITIES } from '@/lib/legalEntities.js';

const toast = useToastStore();

const entities = LEGAL_ENTITIES;
const entity = ref('');
const selected = ref([]);
const exporting = ref(false);
const progress = ref('');
// Количество строк по таблицам: undefined — ещё считаем, null — не смогли.
const counts = reactive({});

// entityAware — есть колонка legal_entity, значит фильтр по юрлицу работает.
const tables = [
  { name: 'products', label: 'Товары', entityAware: true },
  { name: 'suppliers', label: 'Поставщики', entityAware: true },
  { name: 'orders', label: 'Заказы', entityAware: true },
  { name: 'order_items', label: 'Позиции заказов', entityAware: false },
  { name: 'plans', label: 'Планы', entityAware: true },
  { name: 'settings', label: 'Настройки', entityAware: false },
  { name: 'audit_log', label: 'Журнал действий', entityAware: false },
  { name: 'stock_1c', label: 'Остатки 1С', entityAware: true },
  { name: 'analysis_data', label: 'Данные анализа', entityAware: true },
  { name: 'cards', label: 'Карточки', entityAware: true },
  { name: 'restaurants', label: 'Рестораны', entityAware: true },
  { name: 'delivery_schedule', label: 'График основной поставки', entityAware: false },
];

const selectedRows = computed(() => {
  let sum = 0;
  let known = false;
  for (const name of selected.value) {
    const n = counts[name];
    if (typeof n === 'number') { sum += n; known = true; }
  }
  return known ? sum : null;
});

// Сколько строк в каждой таблице — чтобы объём выгрузки был виден до нажатия.
// Считает сервер одним запросом: через обычную выборку пришлось бы скачать
// все строки целиком.
onMounted(async () => {
  try {
    const { data } = await db.rpc('get_table_counts', { tables: tables.map(t => t.name) });
    for (const t of tables) counts[t.name] = data && typeof data[t.name] === 'number' ? data[t.name] : null;
  } catch {
    for (const t of tables) counts[t.name] = null;
  }
});

const BORDER = {
  top: { style: 'thin', color: { rgb: 'E0D5C8' } },
  bottom: { style: 'thin', color: { rgb: 'E0D5C8' } },
  left: { style: 'thin', color: { rgb: 'E0D5C8' } },
  right: { style: 'thin', color: { rgb: 'E0D5C8' } },
};
const HEADER_STYLE = {
  font: { bold: true, sz: 11, name: 'Calibri', color: { rgb: 'FFFFFF' } },
  fill: { fgColor: { rgb: '502314' } },
  alignment: { horizontal: 'center', vertical: 'center', wrapText: true },
  border: BORDER,
};
const CELL_STYLE = {
  font: { sz: 10, name: 'Calibri' },
  alignment: { vertical: 'center' },
  border: BORDER,
};
const ZEBRA_STYLE = { ...CELL_STYLE, fill: { fgColor: { rgb: 'FBF7F2' } } };

/** Ширина колонок по самому длинному значению, но в разумных пределах. */
function columnWidths(rows, keys) {
  return keys.map(key => {
    let max = String(key).length;
    for (const row of rows) {
      const len = String(row[key] ?? '').length;
      if (len > max) max = len;
      if (max > 60) break;
    }
    return { wch: Math.min(Math.max(max + 2, 8), 60) };
  });
}

function sheetFromRows(XLSX, rows) {
  if (!rows.length) {
    const ws = XLSX.utils.aoa_to_sheet([['Нет данных']]);
    ws['A1'].s = { font: { italic: true, sz: 11, color: { rgb: '8A7F75' } } };
    ws['!cols'] = [{ wch: 20 }];
    return ws;
  }
  const keys = Object.keys(rows[0]);
  const ws = XLSX.utils.json_to_sheet(rows);
  const range = XLSX.utils.decode_range(ws['!ref']);
  for (let R = range.s.r; R <= range.e.r; R++) {
    for (let C = range.s.c; C <= range.e.c; C++) {
      const cell = ws[XLSX.utils.encode_cell({ r: R, c: C })];
      if (!cell) continue;
      cell.s = R === 0 ? HEADER_STYLE : (R % 2 === 0 ? ZEBRA_STYLE : CELL_STYLE);
    }
  }
  ws['!cols'] = columnWidths(rows, keys);
  ws['!rows'] = [{ hpx: 26 }];
  // Шапка остаётся на месте при прокрутке — иначе в таблице на тысячи строк
  // непонятно, что за колонка.
  ws['!freeze'] = { xSplit: 0, ySplit: 1 };
  ws['!autofilter'] = { ref: ws['!ref'] };
  return ws;
}

async function exportBackup() {
  exporting.value = true;
  progress.value = '';
  const failed = [];
  try {
    const XLSX = await import('xlsx-js-style');
    const wb = XLSX.utils.book_new();
    const used = new Set();

    let done = 0;
    for (const name of selected.value) {
      const meta = tables.find(t => t.name === name);
      done += 1;
      progress.value = `${done} из ${selected.value.length}: ${meta?.label || name}`;

      let rows = [];
      try {
        let query = db.from(name).select('*');
        if (entity.value && meta?.entityAware) query = query.eq('legal_entity', entity.value);
        const { data } = await query;
        rows = data || [];
      } catch {
        failed.push(meta?.label || name);
      }

      // Имя листа: максимум 31 символ и без повторов, иначе Excel не откроет файл.
      let title = (meta?.label || name).slice(0, 31);
      let n = 2;
      while (used.has(title)) title = `${(meta?.label || name).slice(0, 28)} ${n++}`;
      used.add(title);

      XLSX.utils.book_append_sheet(wb, sheetFromRows(XLSX, rows), title);
    }

    const date = toLocalDateStr(new Date());
    const suffix = entity.value ? '_' + entity.value.replace(/[^\wа-яА-Я]/g, '') : '';
    XLSX.writeFile(wb, `backup_${date}${suffix}.xlsx`);

    if (failed.length) toast.error('Часть таблиц не выгрузилась', failed.join(', '));
    else toast.success('Готово', 'Файл скачан');
  } catch (e) {
    toast.error('Ошибка', 'Не удалось создать файл');
  } finally {
    exporting.value = false;
    progress.value = '';
  }
}
</script>

<style scoped>
.bkp { display: flex; flex-direction: column; gap: 12px; }

.bkp-head {
  display: flex; align-items: center; gap: 16px;
  padding: 16px 18px; border-radius: 12px;
  background: var(--card); border: 1px solid var(--border-light);
}
.bkp-head-icon {
  width: 48px; height: 48px; border-radius: 14px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  background: rgba(33, 150, 243, .1); color: #1976D2;
}
.bkp-title { margin: 0; font-size: 16px; font-weight: 700; color: var(--text); }
.bkp-desc { margin: 4px 0 0; font-size: 13px; color: var(--text-muted); line-height: 1.5; }

.bkp-card {
  background: var(--card); border: 1px solid var(--border-light);
  border-radius: 12px; padding: 14px 16px;
}
.bkp-card-head {
  display: flex; align-items: baseline; justify-content: space-between;
  gap: 10px; flex-wrap: wrap; margin-bottom: 10px;
}
.bkp-card-title { margin: 0; font-size: 13px; font-weight: 700; color: var(--text); }
.bkp-card-hint { font-size: 11.5px; color: var(--text-muted); }

.bkp-select {
  width: 100%; padding: 9px 12px; font-family: inherit; font-size: 13px;
  border: 1.5px solid var(--border-light); border-radius: 8px;
  background: var(--card); color: var(--text);
}
.bkp-select:focus { outline: none; border-color: var(--bk-orange); }

.bkp-tables {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 6px;
}
.bkp-table {
  display: flex; align-items: center; gap: 8px; min-width: 0;
  padding: 8px 10px; border-radius: 9px; cursor: pointer;
  border: 1.5px solid var(--border-light); background: var(--bg);
  transition: all .15s;
}
.bkp-table:hover { border-color: var(--bk-orange); }
.bkp-table.on { background: #FFF8F0; border-color: var(--bk-orange); }
.bkp-table input { display: none; }
.bkp-check {
  width: 18px; height: 18px; border-radius: 5px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  border: 1.5px solid var(--border-light); background: var(--card);
  color: transparent;
}
.bkp-table.on .bkp-check { background: var(--bk-orange); border-color: var(--bk-orange); color: #fff; }
.bkp-table-name {
  flex: 1; min-width: 0; font-size: 13px; color: var(--text);
  display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
}
.bkp-tag {
  font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px;
  padding: 1px 5px; border-radius: 4px; background: #FFF3E0; color: #E65100;
}
.bkp-table-count { font-size: 11.5px; color: var(--text-muted); flex-shrink: 0; font-variant-numeric: tabular-nums; }

.bkp-actions { display: flex; gap: 8px; margin-top: 14px; flex-wrap: wrap; }
.bkp-progress { margin: 10px 0 0; font-size: 12px; color: var(--text-muted); }

@media (max-width: 600px) {
  .bkp-head { flex-direction: column; text-align: center; gap: 12px; }
  .bkp-tables { grid-template-columns: 1fr; }
  .bkp-actions .btn { flex: 1; justify-content: center; }
}
</style>
