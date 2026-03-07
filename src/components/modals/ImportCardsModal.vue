<template>
  <Teleport to="body">
    <div class="modal" @click.self="tryClose">
      <div class="modal-box" style="width:min(900px,96vw);max-height:90vh;">
        <div class="modal-header">
          <h2>Импорт карточек из Excel</h2>
          <button class="modal-close" @click="tryClose"><BkIcon name="close" size="sm"/></button>
        </div>

        <!-- Шаг 1: Выбор файла -->
        <div v-if="step === 'select'">
          <div class="import-drop-zone" @click="pickFile" @dragover.prevent @drop.prevent="onDrop">
            <div style="font-size:32px;margin-bottom:8px;">📥</div>
            <div style="font-size:14px;font-weight:600;color:var(--text);">Выберите файл .xlsx</div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">или перетащите его сюда</div>
          </div>
          <div style="margin-top:10px;font-size:11px;color:var(--text-muted);line-height:1.6;">
            <b>Ожидаемые колонки:</b> Артикул, Наименование, Поставщик, Коэффициент единицы для отчетов, Единица хранения, Количество кор. в паллете, Количество штук в блоке, Количество блоков в коробе, Активная, Группа аналогов, Хранение
          </div>
          <input ref="fileInput" type="file" accept=".xlsx,.xls" style="display:none;" @change="onFileSelected" />
        </div>

        <!-- Шаг 2: Превью -->
        <div v-if="step === 'preview'">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;flex-wrap:wrap;gap:6px;">
            <div style="font-size:13px;">
              <span style="font-weight:600;">{{ fileName }}</span>
              <span style="color:var(--text-muted);margin-left:8px;">{{ parsedRows.length }} товаров</span>
              <span v-if="skippedInactive > 0" style="color:var(--text-muted);margin-left:6px;">(отфильтровано неактивных: {{ skippedInactive }})</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
              <span style="font-size:12px;color:var(--text-muted);">Юр. лицо:</span>
              <span style="font-size:12px;font-weight:600;">{{ legalEntity }}</span>
            </div>
          </div>

          <div class="import-table-wrap">
            <table class="order-table import-table">
              <thead>
                <tr>
                  <th style="width:40px;">#</th>
                  <th>Артикул</th>
                  <th>Наименование</th>
                  <th>Поставщик</th>
                  <th>Шт/кор</th>
                  <th>Кор/пал</th>
                  <th>Ед.</th>
                  <th>Кратн.</th>
                  <th>Аналоги</th>
                  <th>Хранение</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, i) in displayRows" :key="i" :class="{ 'import-row-dup': row._status === 'duplicate', 'import-row-upd': row._status === 'update' }">
                  <td style="color:var(--text-muted);font-size:11px;">{{ i + 1 }}</td>
                  <td><span class="db-card-sku">{{ row.sku }}</span></td>
                  <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ row.name }}</td>
                  <td>{{ row.supplier || '—' }}</td>
                  <td>{{ row.qty_per_box || '—' }}</td>
                  <td>{{ row.boxes_per_pallet || '—' }}</td>
                  <td>{{ row.unit_of_measure }}</td>
                  <td>{{ row.multiplicity || '—' }}</td>
                  <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ row.analog_group || '—' }}</td>
                  <td>{{ row.category || '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div v-if="parsedRows.length > maxPreview" style="text-align:center;font-size:12px;color:var(--text-muted);margin-top:6px;">
            Показано {{ maxPreview }} из {{ parsedRows.length }}
          </div>

          <div v-if="duplicateCount > 0" style="margin-top:8px;padding:8px 12px;background:#FFF8E1;border:1px solid #FFE082;border-radius:6px;font-size:12px;color:#795548;">
            <b>{{ duplicateCount }}</b> {{ pluralize(duplicateCount, 'карточка без изменений', 'карточки без изменений', 'карточек без изменений') }} — будут пропущены.
          </div>
          <div v-if="updateCount > 0" style="margin-top:8px;padding:8px 12px;background:#E3F2FD;border:1px solid #90CAF9;border-radius:6px;font-size:12px;color:#1565C0;">
            <b>{{ updateCount }}</b> {{ pluralize(updateCount, 'карточка будет обновлена', 'карточки будут обновлены', 'карточек будут обновлены') }} (поставщик / группа аналогов / хранение).
          </div>
          <div v-if="missingSuppliersCount > 0" style="margin-top:8px;padding:8px 12px;background:#E8F5E9;border:1px solid #A5D6A7;border-radius:6px;font-size:12px;color:#2E7D32;">
            <b>{{ missingSuppliersCount }}</b> {{ pluralize(missingSuppliersCount, 'поставщик будет создан', 'поставщика будут созданы', 'поставщиков будут созданы') }} в базе.
          </div>

          <div class="actions" style="margin-top:14px;">
            <button class="btn primary" @click="doImport" :disabled="importing || (newRows.length === 0 && updateRows.length === 0 && missingSuppliersCount === 0)">
              {{ importing ? 'Импорт...' : (newRows.length + updateRows.length > 0 ? `Импортировать (${newRows.length + updateRows.length})` : `Создать поставщиков (${missingSuppliersCount})`) }}
            </button>
            <button class="btn secondary" @click="reset">Выбрать другой файл</button>
            <button class="btn secondary" @click="tryClose">Отмена</button>
          </div>
        </div>

        <!-- Шаг 3: Результат -->
        <div v-if="step === 'result'">
          <div style="text-align:center;padding:20px 0;">
            <div style="font-size:40px;margin-bottom:10px;">✅</div>
            <div style="font-size:16px;font-weight:700;color:var(--text);margin-bottom:12px;">Импорт завершён</div>
            <div class="import-report">
              <div class="import-report-row">
                <span>Добавлено</span>
                <span class="import-report-val" style="color:#2E7D32;">{{ result.added }}</span>
              </div>
              <div v-if="result.updated > 0" class="import-report-row">
                <span>Обновлено</span>
                <span class="import-report-val" style="color:#1565C0;">{{ result.updated }}</span>
              </div>
              <div v-if="result.suppliersCreated > 0" class="import-report-row">
                <span>Поставщиков создано</span>
                <span class="import-report-val" style="color:#2E7D32;">{{ result.suppliersCreated }}</span>
              </div>
              <div class="import-report-row">
                <span>Без изменений</span>
                <span class="import-report-val" style="color:#F57F17;">{{ result.duplicates }}</span>
              </div>
              <div class="import-report-row">
                <span>Отфильтровано (неактивные)</span>
                <span class="import-report-val" style="color:var(--text-muted);">{{ result.inactive }}</span>
              </div>
              <div v-if="result.errors > 0" class="import-report-row">
                <span>Ошибки</span>
                <span class="import-report-val" style="color:var(--error);">{{ result.errors }}</span>
              </div>
            </div>
          </div>
          <div class="actions" style="justify-content:center;">
            <button class="btn primary" @click="$emit('saved')">Готово</button>
          </div>
        </div>
      </div>
    </div>
    <ConfirmModal v-if="showConfirmClose" title="Закрыть без импорта?" message="Загруженные данные будут потеряны." @confirm="emit('close')" @cancel="showConfirmClose = false" />
  </Teleport>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { db } from '@/lib/apiClient.js';
import { useToastStore } from '@/stores/toastStore.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import ConfirmModal from './ConfirmModal.vue';

const props = defineProps({
  legalEntity: { type: String, required: true },
  existingProducts: { type: Array, default: () => [] },
});
const emit = defineEmits(['close', 'saved']);
const toast = useToastStore();

const step = ref('select');
const fileName = ref('');
const parsedRows = ref([]);
const skippedInactive = ref(0);
const importing = ref(false);
const maxPreview = 100;
const fileInput = ref(null);
const result = ref({ added: 0, duplicates: 0, inactive: 0, errors: 0 });

// ─── Маппинг колонок ────────────────────────────────────────────────────

const COLUMN_MAP = {
  'артикул':                           'sku',
  'наименование':                      'raw_name',
  'поставщик':                         'supplier',
  'коэффициент единицы для отчетов':   'qty_per_box',
  'коэффициент единицы для отчётов':   'qty_per_box',
  'шт/кор':                            'qty_per_box',
  'единица хранения':                  'unit_of_measure',
  'ед. измерения':                     'unit_of_measure',
  'количество кор. в паллете':         'boxes_per_pallet',
  'количество кор в паллете':          'boxes_per_pallet',
  'кор/пал':                           'boxes_per_pallet',
  'количество штук в блоке':           'block_qty',
  'количество блоков в коробе':        'case_blocks',
  'кратность':                         'multiplicity_direct',
  'активная':                          'active',
  'видимость':                         'active',
  'группа аналогов (new)':             'analog_group',
  'группа аналогов':                   'analog_group',
  'хранение':                          'category',
};

const UNIT_MAP = {
  'шт':       'шт',
  'штука':    'шт',
  'штуки':    'шт',
  'л':        'л',
  'л (дм3)':  'л',
  'литр':     'л',
  'кг':       'кг',
  'килограмм':'кг',
  'упаковка': 'уп',
  'уп':       'уп',
  'уп.':      'уп',
  'пачка':    'уп',
};

function normalizeUnit(raw) {
  if (!raw) return 'шт';
  const key = raw.toLowerCase().trim();
  return UNIT_MAP[key] || key;
}

function stripSkuPrefix(sku) {
  if (!sku) return sku;
  return sku.replace(/^(DDI|BK)\s*/i, '').trim();
}

function extractSkuFromName(raw) {
  if (!raw) return { sku: '', name: '' };
  const trimmed = raw.trim();
  // Артикул в начале строки: опциональный префикс DDI/BK, затем цифры (возможно с дефисом/точкой), затем пробел и название
  const m = trimmed.match(/^(?:(?:DDI|BK)\s*)?(\d[\d\-.]*\d)\s+(.+)$/i);
  if (m) return { sku: m[1], name: m[2].trim() };
  return { sku: '', name: trimmed };
}

function cleanName(raw, sku) {
  if (!raw) return '';
  let name = raw.trim();
  if (sku) {
    const skuStr = String(sku).trim();
    if (name.startsWith(skuStr)) {
      name = name.slice(skuStr.length).trim();
    }
  }
  return name;
}

function findColIndex(headers, keyword) {
  const kw = keyword.toLowerCase().trim();
  return headers.findIndex(h => h.toLowerCase().trim() === kw);
}

function mapColumnIndices(headers) {
  const indices = {};
  for (const [keyword, field] of Object.entries(COLUMN_MAP)) {
    const idx = findColIndex(headers, keyword);
    if (idx >= 0) {
      // Для полей, которые могут маппиться на одно поле — берём первое совпадение
      if (!indices[field] && indices[field] !== 0) {
        indices[field] = idx;
      }
    }
  }
  return indices;
}

// ─── Дедупликация ───────────────────────────────────────────────────────

// Карта существующих товаров по SKU (existingProducts уже отфильтрованы по группе юр. лица)
const existingMap = computed(() => {
  const map = new Map();
  for (const p of props.existingProducts) {
    const sku = (p.sku || '').toLowerCase().trim();
    if (!sku) continue;
    map.set(sku, p);
  }
  return map;
});

function classifyRow(row) {
  if (!row.sku) return 'new';
  const existing = existingMap.value.get(row.sku.toLowerCase().trim());
  if (!existing) return 'new';
  // Есть карточка — если пустой поставщик/группа аналогов/хранение, а в файле есть → обновить
  const needSupplier = (!existing.supplier || !existing.supplier.trim()) && row.supplier;
  const needAnalog = (!existing.analog_group || !existing.analog_group.trim()) && row.analog_group;
  const needCategory = (!existing.category || !existing.category.trim()) && row.category;
  if (needSupplier || needAnalog || needCategory) return 'update';
  return 'duplicate';
}

const duplicateCount = computed(() => parsedRows.value.filter(r => r._status === 'duplicate').length);
const updateCount = computed(() => parsedRows.value.filter(r => r._status === 'update').length);
const newRows = computed(() => parsedRows.value.filter(r => r._status === 'new'));
const updateRows = computed(() => parsedRows.value.filter(r => r._status === 'update'));
const displayRows = computed(() => parsedRows.value.slice(0, maxPreview));
const missingSuppliersCount = ref(0);

// ─── Парсинг Excel ──────────────────────────────────────────────────────

async function parseExcel(file) {
  let XLSX, buffer, wb;
  try {
    XLSX = await import('xlsx-js-style');
    buffer = await file.arrayBuffer();
    wb = XLSX.read(buffer, { type: 'array' });
  } catch (e) {
    toast.error('Ошибка чтения файла', 'Не удалось распознать формат файла');
    return;
  }
  const ws = wb.Sheets[wb.SheetNames[0]];
  const rawData = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' });

  if (rawData.length < 2) {
    toast.error('Файл пуст', 'Не найдены данные для импорта');
    return;
  }

  // Найти строку заголовков (первая строка с >= 3 совпадениями по COLUMN_MAP)
  let headerRowIdx = 0;
  const colKeywords = Object.keys(COLUMN_MAP);
  for (let i = 0; i < Math.min(rawData.length, 15); i++) {
    const row = rawData[i];
    const hits = row.filter(cell => {
      const c = String(cell).toLowerCase().trim();
      return colKeywords.some(kw => c === kw);
    }).length;
    if (hits >= 3) { headerRowIdx = i; break; }
  }

  const headers = rawData[headerRowIdx].map(h => String(h));
  const colIdx = mapColumnIndices(headers);

  if (colIdx.sku === undefined && colIdx.raw_name === undefined) {
    toast.error('Не найдены колонки', 'Ожидаются: Артикул, Наименование');
    return;
  }

  const rows = [];
  let inactive = 0;

  for (let i = headerRowIdx + 1; i < rawData.length; i++) {
    const r = rawData[i];
    if (!r || r.length < 2) continue;

    // Фильтр по активности
    if (colIdx.active !== undefined) {
      const activeVal = String(r[colIdx.active] || '').trim().toLowerCase();
      if (activeVal && activeVal !== 'да' && activeVal !== 'yes' && activeVal !== '1') {
        inactive++;
        continue;
      }
    }

    const rawSku = colIdx.sku !== undefined ? String(r[colIdx.sku] || '').trim() : '';
    const rawName = colIdx.raw_name !== undefined ? String(r[colIdx.raw_name] || '').trim() : '';

    // Если артикул есть в наименовании — берём оттуда, иначе из колонки «Артикул»; убираем префиксы DDI/BK
    const extracted = extractSkuFromName(rawName);
    const sku = extracted.sku || stripSkuPrefix(rawSku);
    const name = extracted.sku ? extracted.name : cleanName(rawName, rawSku);

    if (!sku && !name) continue;

    const supplier = colIdx.supplier !== undefined ? String(r[colIdx.supplier] || '').trim() : '';
    const qtyPerBox = colIdx.qty_per_box !== undefined ? parseFloat(String(r[colIdx.qty_per_box]).replace(',', '.')) || 0 : 0;
    const boxesPerPallet = colIdx.boxes_per_pallet !== undefined ? parseFloat(String(r[colIdx.boxes_per_pallet]).replace(',', '.')) || 0 : 0;
    const unitOfMeasure = colIdx.unit_of_measure !== undefined ? normalizeUnit(String(r[colIdx.unit_of_measure])) : 'шт';

    // Кратность: прямое значение или max(штук в блоке, блоков в коробе)
    const directMult = colIdx.multiplicity_direct !== undefined ? Math.round(parseFloat(String(r[colIdx.multiplicity_direct]).replace(',', '.')) || 0) : 0;
    const blockQty = colIdx.block_qty !== undefined ? Math.round(parseFloat(String(r[colIdx.block_qty]).replace(',', '.')) || 0) : 0;
    const caseBlocks = colIdx.case_blocks !== undefined ? Math.round(parseFloat(String(r[colIdx.case_blocks]).replace(',', '.')) || 0) : 0;
    const multiplicity = directMult || Math.max(blockQty, caseBlocks) || 1;

    const analogGroup = colIdx.analog_group !== undefined ? String(r[colIdx.analog_group] || '').trim() : '';
    const category = colIdx.category !== undefined ? String(r[colIdx.category] || '').trim() : '';

    const row = {
      sku,
      name,
      supplier,
      qty_per_box: Math.round(qtyPerBox) || null,
      boxes_per_pallet: Math.round(boxesPerPallet) || null,
      unit_of_measure: unitOfMeasure,
      multiplicity,
      analog_group: analogGroup || null,
      category: category || null,
      legal_entity: props.legalEntity,
    };

    row._status = classifyRow(row);
    rows.push(row);
  }

  skippedInactive.value = inactive;
  parsedRows.value = rows;

  // Подсчитать недостающих поставщиков
  const supplierNames = [...new Set(rows.map(r => r.supplier).filter(Boolean))];
  if (supplierNames.length) {
    const { data: existing } = await db.from('suppliers').select('short_name, legal_entity');
    const existingSet = new Set((existing || []).map(s => `${s.short_name}|${s.legal_entity}`));
    missingSuppliersCount.value = supplierNames.filter(name => !existingSet.has(`${name}|${props.legalEntity}`)).length;
  } else {
    missingSuppliersCount.value = 0;
  }

  step.value = 'preview';
}

// ─── Импорт в API ───────────────────────────────────────────────────────

async function doImport() {
  if (importing.value) return;
  importing.value = true;
  const rows = newRows.value;
  const updRows = updateRows.value;
  const batchSize = 50;
  let added = 0;
  let updated = 0;
  let suppliersCreated = 0;
  let errors = 0;

  try {
    // Автосоздание недостающих поставщиков (из ВСЕХ строк, включая дубли)
    const supplierNames = [...new Set(parsedRows.value.map(r => r.supplier).filter(Boolean))];
    if (supplierNames.length) {
      const { data: existing } = await db.from('suppliers').select('short_name, legal_entity');
      const existingSet = new Set((existing || []).map(s => `${s.short_name}|${s.legal_entity}`));
      const toCreate = supplierNames.filter(name => !existingSet.has(`${name}|${props.legalEntity}`));
      if (toCreate.length) {
        const suppBatch = toCreate.map(name => ({ short_name: name, legal_entity: props.legalEntity }));
        await db.from('suppliers').insert(suppBatch);
        suppliersCreated = toCreate.length;
      }
    }

    // Обновление существующих карточек (добавление поставщика / группы аналогов)
    for (const row of updRows) {
      const existing = existingMap.value.get(row.sku.toLowerCase().trim());
      if (!existing) continue;
      const patch = {};
      if ((!existing.supplier || !existing.supplier.trim()) && row.supplier) patch.supplier = row.supplier;
      if ((!existing.analog_group || !existing.analog_group.trim()) && row.analog_group) patch.analog_group = row.analog_group;
      if ((!existing.category || !existing.category.trim()) && row.category) patch.category = row.category;
      if (!Object.keys(patch).length) continue;
      const { error } = await db.from('products').update(patch).eq('id', existing.id);
      if (error) { errors++; } else { updated++; }
    }

    // Вставка новых карточек
    for (let i = 0; i < rows.length; i += batchSize) {
      const batch = rows.slice(i, i + batchSize).map(r => {
        const { _status, ...payload } = r;
        return payload;
      });

      const { error } = await db.from('products').insert(batch);
      if (error) {
        console.error('[ImportCards] Batch error:', error);
        errors += batch.length;
      } else {
        added += batch.length;
      }
    }

    result.value = {
      added,
      updated,
      suppliersCreated,
      duplicates: duplicateCount.value,
      inactive: skippedInactive.value,
      errors,
    };
    step.value = 'result';

    const parts = [];
    if (added > 0) parts.push(`добавлено ${added} карточек`);
    if (updated > 0) parts.push(`обновлено ${updated}`);
    if (suppliersCreated > 0) parts.push(`создано ${suppliersCreated} поставщиков`);
    if (parts.length) toast.success('Импорт завершён', parts.join(', '));
  } catch (err) {
    console.error('[ImportCards] Error:', err);
    toast.error('Ошибка импорта', err.message || '');
  } finally {
    importing.value = false;
  }
}

// ─── UI-хелперы ─────────────────────────────────────────────────────────

function pickFile() {
  fileInput.value?.click();
}

function onFileSelected(e) {
  const file = e.target.files[0];
  if (file) processFile(file);
}

function onDrop(e) {
  const file = e.dataTransfer?.files?.[0];
  if (file) processFile(file);
}

function processFile(file) {
  const ext = file.name.split('.').pop().toLowerCase();
  if (ext !== 'xlsx' && ext !== 'xls') {
    toast.error('Неверный формат', 'Поддерживаются только .xlsx и .xls файлы');
    return;
  }
  fileName.value = file.name;
  parseExcel(file);
}

function reset() {
  step.value = 'select';
  parsedRows.value = [];
  skippedInactive.value = 0;
  fileName.value = '';
  result.value = { added: 0, duplicates: 0, inactive: 0, errors: 0 };
  if (fileInput.value) fileInput.value.value = '';
}

const showConfirmClose = ref(false);

function tryClose() {
  if (step.value === 'preview' && parsedRows.value.length > 0) {
    showConfirmClose.value = true;
    return;
  }
  emit('close');
}

function pluralize(n, one, few, many) {
  const mod10 = n % 10;
  const mod100 = n % 100;
  if (mod100 >= 11 && mod100 <= 19) return many;
  if (mod10 === 1) return one;
  if (mod10 >= 2 && mod10 <= 4) return few;
  return many;
}

function onKey(e) {
  if (e.key === 'Escape' && !showConfirmClose.value) tryClose();
}
onMounted(() => document.addEventListener('keydown', onKey));
onUnmounted(() => document.removeEventListener('keydown', onKey));
</script>

<style scoped>
.import-drop-zone {
  border: 2px dashed var(--border);
  border-radius: 12px;
  padding: 36px 20px;
  text-align: center;
  cursor: pointer;
  transition: border-color 0.2s, background 0.2s;
}
.import-drop-zone:hover {
  border-color: var(--bk-orange);
  background: rgba(255, 135, 50, 0.04);
}

.import-table-wrap {
  max-height: 400px;
  overflow: auto;
  border: 1px solid var(--border-light);
  border-radius: 8px;
}
.import-table {
  font-size: 12px;
  width: 100%;
  border-collapse: collapse;
}
.import-table th {
  position: sticky;
  top: 0;
  background: var(--bg);
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.3px;
  color: var(--text-muted);
  padding: 6px 8px;
  text-align: left;
  border-bottom: 1.5px solid var(--border);
  z-index: 1;
}
.import-table td {
  padding: 5px 8px;
  border-bottom: 1px solid var(--border-light);
  white-space: nowrap;
}
.import-row-dup {
  opacity: 0.45;
  background: var(--bg);
}
.import-row-upd {
  background: #E3F2FD;
}

.import-report {
  display: flex;
  flex-direction: column;
  gap: 6px;
  max-width: 300px;
  margin: 0 auto;
}
.import-report-row {
  display: flex;
  justify-content: space-between;
  font-size: 14px;
  padding: 6px 12px;
  background: var(--bg);
  border-radius: 6px;
}
.import-report-val {
  font-weight: 700;
}
</style>
