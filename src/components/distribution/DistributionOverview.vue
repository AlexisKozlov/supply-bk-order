<template>
  <div class="dov-overlay" @click.self="$emit('close')">
    <div class="dov-modal">
      <!-- Шапка -->
      <div class="dov-head">
        <h2 class="dov-title">Сводка распределения</h2>
        <div class="dov-head-right">
          <span class="dov-le">{{ legalEntity }}</span>
          <button class="dov-close" @click="$emit('close')" title="Закрыть">✕</button>
        </div>
      </div>

      <div v-if="loading" class="dov-loader"><BurgerSpinner text="Загрузка..." /></div>

      <template v-else>
        <div v-if="!sessions.length" class="dov-empty">
          Нет активных сессий распределения для этой группы юр. лиц.
        </div>

        <template v-else>
          <!-- Дни недели -->
          <div class="dov-days">
            <button
              v-for="d in days" :key="d.num"
              class="dov-day-tab"
              :class="{ active: activeDay === d.num }"
              @click="selectDay(d.num)"
            >
              {{ d.short }}
              <span v-if="dayRestCount(d.num)" class="dov-day-cnt">{{ dayRestCount(d.num) }}</span>
            </button>
          </div>

          <!-- Табы условий хранения -->
          <div v-if="dayGroups.length" class="dov-storages">
            <button
              v-for="grp in dayGroups" :key="grp.code"
              class="dov-storage-tab"
              :class="['st-' + grp.code, { active: activeStorageCode === grp.code }]"
              @click="activeStorage = grp.code"
            >
              {{ grp.storage }}
              <span class="dov-storage-cnt">{{ grp.restaurants.length }}</span>
            </button>
          </div>

          <!-- Контент: выбранный день + выбранное условие хранения -->
          <div class="dov-body">
            <div v-if="!dayGroups.length" class="dov-empty-day">
              На этот день нет позиций к отгрузке.
            </div>

            <div v-else-if="currentStorageGroup" class="dov-storage">
              <div v-for="rest in currentStorageGroup.restaurants" :key="rest.number" class="dov-rest">
                <div class="dov-rest-head">
                  <div class="dov-rest-title">
                    <span class="dov-rest-num">{{ formatRestaurantNumber(rest.number) }}</span>
                    <span class="dov-rest-addr">{{ rest.address }}</span>
                  </div>
                  <div class="dov-rest-actions">
                    <span class="dov-rest-actions-label">Столбец:</span>
                    <button class="dov-col-btn" @click="copyColumn(rest.items, 'code', rest.number)" title="Скопировать столбец внешних кодов">Коды</button>
                    <button class="dov-col-btn" @click="copyColumn(rest.items, 'name', rest.number)" title="Скопировать столбец товаров">Товары</button>
                    <button class="dov-col-btn" @click="copyColumn(rest.items, 'qty', rest.number)" title="Скопировать столбец количеств">Кол-во</button>
                    <button class="dov-copy-btn" @click="copyRestaurant(rest)" title="Скопировать все 3 столбца через табуляцию">Всё</button>
                  </div>
                </div>
                <div class="dov-items">
                  <div v-for="it in rest.items" :key="it.key" class="dov-item">
                    <span class="dov-item-code">{{ it.external_code || '—' }}</span>
                    <span class="dov-item-name"><b v-if="it.article" class="dov-item-art">{{ it.article }}</b> {{ it.product_name }}</span>
                    <span class="dov-item-qty">{{ it.qtyDisplay }}<span v-if="it.qtyDisplay && it.unit" class="dov-item-unit"> {{ it.unit }}</span></span>
                    <button class="dov-item-copy" @click="copyItem(it)" title="Скопировать строку">📋</button>
                    <button class="dov-item-done" @click="markShipped(rest, it)" title="Отметить отгруженным">✓</button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </template>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { db } from '@/lib/apiClient.js';
import { formatRestaurantNumber } from '@/lib/legalEntities.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';

const emit = defineEmits(['close']);
const orderStore = useOrderStore();
const toastStore = useToastStore();
const legalEntity = computed(() => orderStore.settings.legalEntity);

const loading = ref(true);
const sessions = ref([]);
const products = ref([]);      // товары всех активных сессий (строки матрицы)
const entries = ref([]);       // отметки клеток: ✓/✗ и переопределения кол-ва
const restaurants = ref([]);   // {number, address, city, delivery_days:[]}
const activeDay = ref(1);
const activeStorage = ref(null); // выбранный код хранения (cold/frozen/dry/other)

// Карта отметок: session_product_id → (restaurant_number → {shipped, qty})
const entryMap = computed(() => {
  const m = new Map();
  for (const e of entries.value) {
    if (!m.has(e.session_product_id)) m.set(e.session_product_id, new Map());
    m.get(e.session_product_id).set(String(e.restaurant_number), { shipped: Number(e.shipped) || 0, qty: e.qty });
  }
  return m;
});

const days = [
  { num: 1, short: 'Пн' }, { num: 2, short: 'Вт' }, { num: 3, short: 'Ср' },
  { num: 4, short: 'Чт' }, { num: 5, short: 'Пт' }, { num: 6, short: 'Сб' },
];

// Порядок и оформление условий хранения
const STORAGE_DEFS = [
  { storage: 'Холод', code: 'cold' },
  { storage: 'Мороз', code: 'frozen' },
  { storage: 'Сухой', code: 'dry' },
  { storage: 'Прочее', code: 'other' },
];

function qtyNum(q) {
  const n = parseFloat(String(q ?? '').replace(',', '.'));
  return Number.isFinite(n) ? n : 0;
}

async function load() {
  loading.value = true;
  try {
    const { data, error } = await db.rpc('dist_get_overview', { legal_entity: legalEntity.value });
    if (error) throw new Error(error);
    sessions.value = data.sessions || [];
    products.value = data.products || [];
    entries.value = data.entries || [];
    restaurants.value = data.restaurants || [];
    // Выбираем первый день, где есть позиции
    const firstDay = days.find(d => dayRestCount(d.num) > 0);
    if (firstDay) activeDay.value = firstDay.num;
    activeStorage.value = null;
  } catch (e) {
    toastStore.error('Ошибка', e.message || 'Не удалось загрузить сводку');
  } finally {
    loading.value = false;
  }
}

// Позиции «надо отдать» ресторану: перебираем ВСЕ товары активных сессий.
// Клетка с ✓ (отгружено) или ✗ (не нужно) пропускается, остальные — надо отдать.
function itemsForRestaurant(number) {
  const map = new Map();
  const rn = String(number);
  for (const p of products.value) {
    const ent = entryMap.value.get(p.session_product_id)?.get(rn);
    const shipped = ent?.shipped || 0;
    if (shipped === 1 || shipped === 2) continue; // ✓ отгружено / ✗ не нужно
    // Кол-во клетки, а если своё не задано — стандартное из товара (default_qty)
    const own = qtyNum(ent?.qty);
    const q = own > 0 ? own : qtyNum(p.default_qty);
    // Ключ — по артикулу (или внешнему коду / названию), чтобы суммировать
    // один и тот же товар из разных сессий в одну строку.
    const key = p.article ? 'a:' + p.article : (p.external_code ? 'c:' + p.external_code : 'n:' + (p.product_name || '') + ':' + p.session_product_id);
    if (!map.has(key)) {
      map.set(key, {
        key,
        article: p.article || '',
        external_code: p.external_code || '',
        product_name: p.product_name || '',
        category: p.category || 'Прочее',
        unit: p.unit || '',
        qty: 0,
        spIds: new Set(),
      });
    }
    const it = map.get(key);
    it.qty += q;
    it.spIds.add(p.session_product_id);
  }
  // округляем суммарное кол-во до 3 знаков
  return [...map.values()].map(it => {
    const qty = Math.round(it.qty * 1000) / 1000;
    return { ...it, qty, qtyDisplay: qty > 0 ? String(qty) : '', spIds: [...it.spIds] };
  });
}

function restaurantsForDay(day) {
  return restaurants.value
    .filter(r => (r.delivery_days || []).includes(day))
    .map(r => ({ ...r, items: itemsForRestaurant(r.number) }))
    .filter(r => r.items.length);
}

function dayRestCount(day) {
  return restaurantsForDay(day).length;
}

// Группировка выбранного дня: хранение → рестораны → товары
const dayGroups = computed(() => {
  const rests = restaurantsForDay(activeDay.value);
  const result = [];
  for (const def of STORAGE_DEFS) {
    const inGroup = [];
    for (const rest of rests) {
      const items = rest.items
        .filter(it => (it.category || 'Прочее') === def.storage)
        .sort((a, b) => a.product_name.localeCompare(b.product_name, 'ru'));
      if (items.length) inGroup.push({ ...rest, items });
    }
    if (inGroup.length) result.push({ ...def, restaurants: inGroup });
  }
  return result;
});

// Активная группа хранения: выбранная или первая доступная
const currentStorageGroup = computed(() => {
  const groups = dayGroups.value;
  return groups.find(g => g.code === activeStorage.value) || groups[0] || null;
});
const activeStorageCode = computed(() => currentStorageGroup.value?.code || null);

// Смена дня — сбрасываем выбор хранения на первое доступное в этом дне
function selectDay(num) {
  activeDay.value = num;
  activeStorage.value = null;
}

// ─── Копирование ───
function itemLine(it) {
  // внешний_код \t артикул+название \t кол-во (как в 1С предзаказ)
  const nomenclature = [it.article, it.product_name].filter(Boolean).join(' ');
  return `${it.external_code || ''}\t${nomenclature}\t${it.qtyDisplay}`;
}
async function copyToClipboard(text, msg) {
  try {
    await navigator.clipboard.writeText(text);
    toastStore.success('Скопировано', msg);
  } catch {
    toastStore.error('Ошибка', 'Не удалось скопировать');
  }
}
function copyItem(it) {
  copyToClipboard(itemLine(it), `${it.product_name} — ${it.qty}`);
}
function copyRestaurant(rest) {
  // Все позиции ресторана (по всем условиям хранения), построчно
  const all = itemsForRestaurant(rest.number)
    .sort((a, b) => a.product_name.localeCompare(b.product_name, 'ru'));
  const text = all.map(itemLine).join('\n');
  copyToClipboard(text, `${formatRestaurantNumber(rest.number)}: ${all.length} позиц.`);
}

// Копирование отдельного столбца ПО ОДНОМУ ресторану: только внешние коды,
// только товары или только кол-во — каждое значение с новой строки, чтобы
// вставить в свою колонку 1С/Excel.
function copyColumn(items, field, restNum) {
  if (!items || !items.length) return;
  const map = {
    code: it => it.external_code || '',
    name: it => [it.article, it.product_name].filter(Boolean).join(' '),
    qty:  it => it.qtyDisplay,
  };
  const labels = { code: 'коды', name: 'товары', qty: 'кол-во' };
  const text = items.map(map[field]).join('\n');
  copyToClipboard(text, `${formatRestaurantNumber(restNum)} · ${labels[field]}: ${items.length} строк`);
}

// ─── Отметка отгрузки ───
async function markShipped(rest, it) {
  try {
    // Позиция могла прийти из нескольких сессий — отмечаем все session_product_id
    await Promise.all(it.spIds.map(spId =>
      db.rpc('dist_bulk_toggle', {
        session_product_id: spId,
        restaurant_numbers: [String(rest.number)],
        shipped: 1,
      })
    ));
    // Локально проставляем ✓ — позиция исчезнет из всех дней этого ресторана
    const rn = String(rest.number);
    const next = [...entries.value];
    for (const spId of it.spIds) {
      const idx = next.findIndex(e => e.session_product_id === spId && String(e.restaurant_number) === rn);
      if (idx >= 0) next[idx] = { ...next[idx], shipped: 1 };
      else next.push({ session_product_id: spId, restaurant_number: rn, shipped: 1, qty: null });
    }
    entries.value = next;
    toastStore.success('Отгружено', `${it.product_name} — ${formatRestaurantNumber(rest.number)}`);
  } catch (e) {
    toastStore.error('Ошибка', e.message || 'Не удалось отметить');
  }
}

onMounted(load);
</script>

<style scoped>
.dov-overlay {
  position: fixed; inset: 0; z-index: 1000;
  background: rgba(0,0,0,0.5);
  display: flex; align-items: flex-start; justify-content: center;
  padding: 20px; overflow-y: auto;
}
.dov-modal {
  background: var(--card, #fff); border-radius: 14px;
  width: 100%; max-width: 760px; margin: auto;
  display: flex; flex-direction: column; max-height: calc(100vh - 40px);
  box-shadow: 0 12px 40px rgba(0,0,0,0.25);
}
.dov-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 20px; border-bottom: 1px solid var(--border-light, #eee);
}
.dov-title { font-size: 17px; font-weight: 700; margin: 0; color: var(--text, #1f2937); }
.dov-head-right { display: flex; align-items: center; gap: 12px; }
.dov-le { font-size: 12px; color: var(--text-muted, #6b7280); }
.dov-close {
  border: none; background: none; font-size: 18px; cursor: pointer;
  color: var(--text-muted, #6b7280); line-height: 1; padding: 4px;
}
.dov-loader, .dov-empty, .dov-empty-day { padding: 40px 20px; text-align: center; color: var(--text-muted, #6b7280); font-size: 14px; }

.dov-days {
  display: flex; gap: 4px; padding: 12px 20px 0; flex-wrap: wrap;
  border-bottom: 1px solid var(--border-light, #eee);
}
.dov-day-tab {
  border: 1px solid var(--border, #ddd); background: var(--card, #fff);
  border-bottom: none; border-radius: 8px 8px 0 0;
  padding: 7px 14px; font-size: 13px; font-weight: 600; cursor: pointer;
  color: var(--text-secondary, #4b5563);
  display: flex; align-items: center; gap: 6px;
}
.dov-day-tab.active { background: var(--bk-brown, #6b4226); color: #fff; border-color: var(--bk-brown, #6b4226); }
.dov-day-cnt {
  background: rgba(0,0,0,0.1); border-radius: 10px; padding: 0 6px;
  font-size: 11px; font-weight: 700;
}
.dov-day-tab.active .dov-day-cnt { background: rgba(255,255,255,0.25); }

/* Табы условий хранения */
.dov-storages {
  display: flex; gap: 6px; padding: 12px 20px 0; flex-wrap: wrap;
}
.dov-storage-tab {
  border: 1px solid transparent; border-radius: 8px;
  padding: 6px 12px; font-size: 12px; font-weight: 700; cursor: pointer;
  display: flex; align-items: center; gap: 6px; opacity: 0.6;
  text-transform: uppercase; letter-spacing: 0.3px;
}
.dov-storage-tab.active { opacity: 1; box-shadow: inset 0 0 0 2px currentColor; }
.dov-storage-cnt {
  background: rgba(0,0,0,0.12); border-radius: 10px; padding: 0 6px;
  font-size: 11px; font-weight: 700;
}

.dov-body { padding: 14px 20px 16px; overflow-y: auto; }

.dov-storage { margin-bottom: 18px; }
.dov-rest-actions { display: flex; align-items: center; gap: 5px; flex-shrink: 0; flex-wrap: wrap; }
.dov-rest-actions-label { font-size: 11px; color: var(--text-muted, #9ca3af); }
.dov-col-btn {
  border: 1px solid var(--border, #ddd); background: #fff; border-radius: 6px;
  padding: 4px 8px; font-size: 12px; font-weight: 600; cursor: pointer;
  color: var(--text-secondary, #4b5563);
}
.dov-col-btn:hover { background: #f3f4f6; }
.st-cold { background: #E3F2FD; color: #1565C0; }
.st-frozen { background: #F3E5F5; color: #7B1FA2; }
.st-dry { background: #FFF3E0; color: #E65100; }
.st-other { background: #ECEFF1; color: #455A64; }

.dov-rest {
  border: 1px solid var(--border-light, #eee); border-radius: 10px;
  margin-bottom: 8px; overflow: hidden;
}
.dov-rest-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 8px 12px; background: var(--bg-soft, #f9fafb);
  border-bottom: 1px solid var(--border-light, #eee);
}
.dov-rest-title { display: flex; align-items: center; gap: 8px; min-width: 0; }
.dov-rest-num { font-weight: 800; color: var(--bk-red, #C8102E); font-size: 13px; flex-shrink: 0; }
.dov-rest-addr { font-size: 12px; color: var(--text-secondary, #4b5563); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dov-copy-btn {
  border: 1px solid var(--border, #ddd); background: #fff; border-radius: 6px;
  padding: 4px 10px; font-size: 12px; font-weight: 600; cursor: pointer;
  color: var(--text-secondary, #4b5563); flex-shrink: 0;
}
.dov-copy-btn:hover { background: #f3f4f6; }

.dov-items { padding: 4px 0; }
.dov-item {
  display: flex; align-items: center; gap: 8px;
  padding: 5px 12px; font-size: 13px;
  border-bottom: 1px solid var(--border-light, #f3f4f6);
}
.dov-item:last-child { border-bottom: none; }
.dov-item-code { font-family: monospace; color: var(--text-muted, #6b7280); min-width: 80px; flex-shrink: 0; }
.dov-item-name { flex: 1; min-width: 0; color: var(--text, #1f2937); }
.dov-item-art { font-family: monospace; color: var(--bk-brown, #6b4226); font-weight: 700; }
.dov-item-qty { font-weight: 700; color: var(--text, #1f2937); min-width: 48px; text-align: right; white-space: nowrap; }
.dov-item-unit { font-weight: 500; color: var(--text-muted, #6b7280); font-size: 11px; }
.dov-item-copy, .dov-item-done {
  border: none; background: none; cursor: pointer; font-size: 14px;
  padding: 2px 4px; flex-shrink: 0; color: var(--text-muted, #9ca3af);
}
.dov-item-copy:hover { color: var(--bk-brown, #6b4226); }
.dov-item-done:hover { color: #2e7d32; }

@media (max-width: 600px) {
  .dov-overlay { padding: 0; }
  .dov-modal { max-width: 100%; max-height: 100vh; border-radius: 0; }
  .dov-item-code { min-width: 64px; font-size: 12px; }
}
</style>
