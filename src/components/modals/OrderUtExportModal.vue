<template>
  <div class="ut-overlay" @click.self="$emit('close')">
    <div class="ut-modal">
      <div class="ut-head">
        <div>
          <h2>Заявка для 1С УТ</h2>
          <div class="ut-sub">{{ supplier }}<span v-if="deliveryDateText"> · поставка {{ deliveryDateText }}</span></div>
        </div>
        <button class="ut-x" @click="$emit('close')" aria-label="Закрыть">&times;</button>
      </div>

      <div class="ut-body">
        <div v-if="invalidCount" class="ut-alert">
          <strong>{{ invalidCount }}</strong>
          {{ plural(invalidCount, 'позиция','позиции','позиций') }}
          без корректного 9-значного внешнего кода — они не загрузятся в 1С УТ.
        </div>

        <div v-if="!groups.length" class="ut-empty">Нет позиций к выгрузке.</div>

        <div v-for="g in groups" :key="g.category" class="ut-group">
          <div class="ut-group-head">
            <span class="ut-group-title">Заявка — {{ g.category }}</span>
            <span class="ut-dim">{{ g.rows.length }} {{ plural(g.rows.length, 'позиция','позиции','позиций') }}</span>
            <button class="ut-btn ut-btn-primary ut-btn-sm" @click="copyGroup(g.category)">Копировать заявку</button>
          </div>
          <div class="ut-table">
            <div
              v-for="col in cols"
              :key="col.key"
              class="ut-col"
              :style="{ flex: col.flex }"
            >
              <div class="ut-colhead">
                <button class="ut-pick" @click="selectColumn(g.category, col.key)" :title="`Выделить столбец «${col.label}»`">{{ col.short }}</button>
                <button class="ut-copybtn" @click="copyColumn(g.category, col.key)" :aria-label="`Копировать «${col.label}»`" :title="`Копировать «${col.label}»`">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                </button>
              </div>
              <div class="ut-cells" :ref="el => setColRef(g.category, col.key, el)">
                <div
                  v-for="row in g.rows"
                  :key="row.sku"
                  class="ut-cell"
                  :class="{ 'is-invalid': !row.validCode }"
                  :title="!row.validCode ? 'Внешний код некорректен — позиция не загрузится в 1С УТ' : ''"
                >{{ col.value(row) }}</div>
              </div>
            </div>
          </div>
        </div>

        <div v-if="copyMessage" class="ut-toast">{{ copyMessage }}</div>
      </div>

      <div class="ut-foot">
        <button class="ut-btn ut-btn-ghost" @click="$emit('close')">Закрыть</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
  // rows: { sku, name, externalCode, category, accountingBoxes }
  rows: { type: Array, default: () => [] },
  supplier: { type: String, default: '' },
  deliveryDateText: { type: String, default: '' },
});
defineEmits(['close']);

const cols = [
  { key: 'code', label: 'Внешний код', short: 'Код', flex: '0 0 120px', value: r => r.externalCode || '' },
  { key: 'name', label: 'Наименование', short: 'Наименование', flex: '1 1 100px', value: r => r.sku ? `${r.sku} ${r.name || ''}` : (r.name || '') },
  { key: 'qty',  label: 'Кол-во',       short: 'Кор.', flex: '0 0 96px',  value: r => String(r.accountingBoxes ?? 0) },
];

function canonCategory(c) {
  const s = String(c || '').toLowerCase();
  if (s.includes('мороз') || s.includes('замор')) return 'Мороз';
  if (s.includes('холод') || s.includes('охлажд') || s === 'хол') return 'Холод';
  if (s.includes('сух')) return 'Сухой';
  return c || 'Прочее';
}

function plural(n, one, two, many) {
  const last = Math.abs(n) % 10;
  const last2 = Math.abs(n) % 100;
  if (last2 >= 11 && last2 <= 14) return many;
  if (last === 1) return one;
  if (last >= 2 && last <= 4) return two;
  return many;
}

const enriched = computed(() =>
  (props.rows || [])
    .filter(r => Number(r.accountingBoxes) > 0)
    .map(r => ({
      ...r,
      category: canonCategory(r.category),
      validCode: /^\d{9}$/.test(String(r.externalCode || '')),
    }))
);

const invalidCount = computed(() => enriched.value.filter(r => !r.validCode).length);

const groups = computed(() => {
  const order = ['Сухой', 'Холод', 'Мороз'];
  const byCat = {};
  for (const r of enriched.value) {
    (byCat[r.category] = byCat[r.category] || []).push(r);
  }
  const cats = order.filter(c => byCat[c]);
  for (const c of Object.keys(byCat)) if (!order.includes(c)) cats.push(c);
  return cats.map(c => ({ category: c, rows: byCat[c] }));
});

const colRefs = {};
function colKey(cat, key) { return cat + ' ' + key; }
function setColRef(cat, key, el) { if (el) colRefs[colKey(cat, key)] = el; }

const copyMessage = ref('');
let copyTimer = null;
function showCopied(msg) {
  copyMessage.value = msg;
  clearTimeout(copyTimer);
  copyTimer = setTimeout(() => { copyMessage.value = ''; }, 2500);
}

function selectColumn(cat, key) {
  const el = colRefs[colKey(cat, key)];
  if (!el) return;
  const sel = window.getSelection();
  const range = document.createRange();
  range.selectNodeContents(el);
  sel.removeAllRanges();
  sel.addRange(range);
}

async function copyColumn(cat, key) {
  const group = groups.value.find(g => g.category === cat);
  if (!group) return;
  const col = cols.find(c => c.key === key);
  const text = group.rows.map(r => col.value(r)).join('\n');
  try {
    await navigator.clipboard.writeText(text);
    selectColumn(cat, key);
    showCopied(`«${col.label}» (${cat}) скопирован`);
  } catch {
    showCopied('Не удалось скопировать');
  }
}

async function copyGroup(cat) {
  const group = groups.value.find(g => g.category === cat);
  if (!group) return;
  // Формат 1С УТ: внешний код, два пустых столбца, количество (в учётных коробках)
  const lines = group.rows.map(r => [r.externalCode || '', '', '', String(r.accountingBoxes ?? 0)].join('\t'));
  try {
    await navigator.clipboard.writeText(lines.join('\n'));
    showCopied(`Заявка «${cat}» скопирована`);
  } catch {
    showCopied('Не удалось скопировать');
  }
}
</script>

<style scoped>
.ut-overlay {
  position: fixed; inset: 0; z-index: 1000;
  background: rgba(15,23,42,0.5);
  display: flex; align-items: flex-start; justify-content: center;
  padding: 32px 16px; overflow-y: auto;
}
.ut-modal {
  background: #fff; border-radius: 16px;
  width: 100%; max-width: 940px;
  display: flex; flex-direction: column;
  box-shadow: 0 20px 60px rgba(0,0,0,0.25);
}
.ut-head {
  padding: 18px 22px;
  border-bottom: 1px solid #EDE8E3;
  display: flex; align-items: flex-start; justify-content: space-between; gap: 12px;
}
.ut-head h2 { margin: 0; font-size: 18px; font-weight: 800; color: #502314; }
.ut-sub { margin-top: 4px; font-size: 13px; color: #8b7355; }
.ut-x { background: none; border: 0; font-size: 26px; line-height: 1; color: #9ca3af; cursor: pointer; width: 32px; height: 32px; }
.ut-x:hover { color: #1f2937; }

.ut-body { padding: 18px 22px; display: flex; flex-direction: column; gap: 14px; }
.ut-hint { font-size: 13px; color: #555; line-height: 1.5; background: #FFF8EE; border: 1px solid #FBE2C4; padding: 10px 12px; border-radius: 10px; }
.ut-alert { background: #fff3cd; border: 1px solid #ffe082; color: #6b4d00; padding: 10px 12px; border-radius: 10px; font-size: 13px; }
.ut-empty { padding: 24px; text-align: center; color: #8b7355; font-size: 14px; }

.ut-group { border: 1px solid #EDE8E3; border-radius: 12px; overflow: hidden; background: #fff; }
.ut-group-head {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 14px; background: #FAF6EF; border-bottom: 1px solid #EDE8E3;
}
.ut-group-title { font-size: 14px; font-weight: 700; color: #502314; }
.ut-dim { font-size: 12px; color: #8b7355; }
.ut-group-head .ut-btn { margin-left: auto; }

.ut-table { display: flex; align-items: stretch; }
.ut-col { display: flex; flex-direction: column; min-width: 0; border-right: 1px solid #f5efe8; }
.ut-col:last-child { border-right: 0; }
.ut-colhead {
  display: flex; align-items: stretch;
  font-size: 11px; font-weight: 700; color: #502314;
  text-transform: uppercase; letter-spacing: 0.04em;
  background: #f7f1e6; border-bottom: 1px solid #EDE8E3;
}
.ut-pick {
  flex: 1; border: 0; background: transparent; padding: 8px 10px; text-align: left;
  font: inherit; color: inherit; cursor: pointer;
}
.ut-pick:hover { background: rgba(231,111,81,0.08); }
.ut-copybtn {
  width: 36px; border: 0; border-left: 1px solid #EDE8E3;
  background: transparent; color: #8b7355; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
}
.ut-copybtn:hover { color: #E76F51; background: rgba(231,111,81,0.06); }
.ut-copybtn svg { width: 16px; height: 16px; }

.ut-cells { display: flex; flex-direction: column; user-select: text; }
.ut-cell {
  padding: 7px 10px; border-bottom: 1px solid #f5efe8;
  font-size: 13px; color: #2C1A12; line-height: 1.35;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.ut-cell:last-child { border-bottom: 0; }
.ut-cell.is-invalid { background: #fef2f2; color: #b91c1c; }

.ut-toast {
  position: sticky; bottom: 0;
  background: #1f2937; color: #fff;
  padding: 8px 14px; border-radius: 10px;
  font-size: 13px; align-self: center;
  box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}

.ut-foot { padding: 14px 22px; border-top: 1px solid #EDE8E3; display: flex; justify-content: flex-end; }
.ut-btn { padding: 9px 16px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; border: 1px solid transparent; }
.ut-btn-sm { padding: 6px 12px; font-size: 13px; }
.ut-btn-ghost { background: #fff; border-color: #E8DCC9; color: #502314; }
.ut-btn-ghost:hover { background: #F7F1E6; }
.ut-btn-primary { background: #E76F51; color: #fff; }
.ut-btn-primary:hover { background: #d35a3b; }

@media (max-width: 720px) {
  .ut-overlay { padding: 0; }
  .ut-modal { border-radius: 0; max-width: none; min-height: 100vh; }
  .ut-col { flex: 1 1 auto !important; }
  .ut-cell { white-space: normal; }
}
</style>
