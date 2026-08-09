<template>
  <div class="alg">
    <div class="alg-top">
      <div>
        <h1 class="page-title">Аналоги</h1>
        <p class="alg-sub">Группы аналогов товаров. Отдел качества и бухгалтерия сверяются здесь, закупки редактируют.</p>
      </div>
      <div class="alg-actions">
        <div class="alg-search-wrap">
          <span class="alg-search-ico">🔍</span>
          <input v-model="searchInput" class="alg-input alg-search-input" placeholder="Поиск: код, наименование или группа…" />
          <button v-if="searchInput" class="alg-clear" @click="searchInput = ''; search = ''" title="Очистить">×</button>
        </div>
        <button v-if="canEdit" class="alg-btn" @click="openAdd">＋ Карточка</button>
        <button class="alg-btn alg-btn-ghost" @click="exportAll" :disabled="!cards.length">⭳ Excel</button>
        <button v-if="canEdit" class="alg-btn alg-btn-ghost" @click="pickImport" :disabled="importing">{{ importing ? 'Импорт…' : '⭱ Импорт' }}</button>
        <input ref="importInput" type="file" accept=".xlsx,.xls,.XLSX" style="display:none" @change="onImportFile" />
      </div>
    </div>

    <!-- Модалка добавления карточки -->
    <div v-if="showAdd" class="alg-modal-back" @click.self="closeAdd">
      <div class="alg-modal">
        <div class="alg-modal-head">
          <h3>{{ editingId ? 'Редактировать карточку' : 'Новая карточка аналога' }}</h3>
          <button class="alg-modal-x" @click="closeAdd">×</button>
        </div>
        <label class="alg-field">
          <span>Код / артикул <b class="alg-req">*</b></span>
          <input v-model="newCard.code" class="alg-input" placeholder="напр. 68697 или BK_68697" />
        </label>
        <label class="alg-field">
          <span>Полное наименование</span>
          <input v-model="newCard.full_name" class="alg-input" placeholder="Полное название товара" />
        </label>
        <label class="alg-field">
          <span>Мера (учётная единица)</span>
          <input v-model="newCard.measure" class="alg-input" placeholder="напр. 12 или 5.5" />
        </label>
        <label class="alg-field">
          <span>Группа аналогов</span>
          <input v-model="newCard.analog_group" class="alg-input" list="alg-group-list" placeholder="выберите или введите новую" />
          <small class="alg-field-hint">Можно ввести новое название — группа создастся автоматически.</small>
        </label>
        <div class="alg-modal-actions">
          <button v-if="editingId" class="alg-btn alg-btn-del" @click="deleteCard" :disabled="savingNew">Удалить</button>
          <span class="alg-modal-spacer"></span>
          <button class="alg-btn alg-btn-ghost" @click="closeAdd">Отмена</button>
          <button class="alg-btn" @click="saveNewCard" :disabled="savingNew || !newCard.code.trim()">{{ savingNew ? 'Сохранение…' : (editingId ? 'Сохранить' : 'Добавить') }}</button>
        </div>
      </div>
    </div>

    <div class="alg-stats">
      <span><b>{{ cards.length }}</b> карточек</span>
      <span><b>{{ groupNames.length }}</b> групп</span>
      <span v-if="ungrouped.length" class="alg-stat-warn"><b>{{ ungrouped.length }}</b> без группы</span>
      <span><b>{{ inCatalogCount }}</b> в справочнике</span>
    </div>

    <!-- Общий список групп (для инлайн-ввода и модалки) — один на всю страницу -->
    <datalist id="alg-group-list">
      <option v-for="gn in groupNames" :key="gn" :value="gn" />
    </datalist>

    <div v-if="loading" style="text-align:center;padding:50px;"><BurgerSpinner text="Загрузка…" /></div>
    <div v-else-if="!cards.length" style="text-align:center;padding:50px;color:var(--text-muted);">Карточек аналогов нет</div>

    <template v-else>
      <div v-if="search" class="alg-found">Найдено: <b>{{ foundCount }}</b></div>
      <!-- Заголовки колонок -->
      <div class="alg-headbar">
        <span class="alg-hb-code">Код</span>
        <span class="alg-hb-name">Наименование</span>
        <span class="alg-hb-measure">Мера</span>
        <span class="alg-hb-group">Группа аналогов</span>
      </div>

      <!-- Без группы -->
      <section v-if="filteredUngrouped.length" class="alg-card alg-nogroup">
        <div class="alg-group-head">
          <span class="alg-group-name">Без группы</span>
          <span class="alg-count alg-count-warn">{{ filteredUngrouped.length }}</span>
          <span class="alg-hint">— распределите новинки по группам</span>
        </div>
        <div class="alg-rows">
          <div v-for="c in filteredUngrouped" :key="c.id" class="alg-row">
            <span class="alg-code" :class="{ 'alg-code-in': c.in_catalog }">{{ c.code }}</span>
            <span class="alg-name">{{ c.full_name || '—' }}</span>
            <span class="alg-measure">{{ c.measure || '' }}</span>
            <span v-if="c.in_catalog" class="alg-inbase" title="Есть в справочнике портала">✓ в базе</span>
            <input v-if="canEdit" class="alg-gsel" :value="c.analog_group || ''" list="alg-group-list"
                   placeholder="группа…" @change="onGroupInput(c, $event)" />
            <button v-if="canEdit" class="alg-edit" @click="openEdit(c)" title="Редактировать карточку">✎</button>
          </div>
        </div>
      </section>

      <!-- Группы -->
      <section v-for="g in filteredGroups" :key="g.name" class="alg-card">
        <div class="alg-group-head" @click="toggle(g.name)">
          <BkIcon :name="expanded.has(g.name) ? 'chevronDown' : 'chevronRight'" size="sm" />
          <span class="alg-group-name">{{ g.name }}</span>
          <span class="alg-count">{{ g.items.length }}</span>
          <button v-if="canEdit" class="alg-mini-btn" @click.stop="renameGroup(g)" title="Переименовать группу">✎</button>
        </div>
        <div v-if="expanded.has(g.name)" class="alg-rows">
          <div v-for="c in g.items" :key="c.id" class="alg-row" :class="{ 'alg-row-hit': isHit(c) }">
            <span class="alg-code" :class="{ 'alg-code-in': c.in_catalog }">{{ c.code }}</span>
            <span class="alg-name">{{ c.full_name || '—' }}</span>
            <span class="alg-measure">{{ c.measure || '' }}</span>
            <span v-if="c.in_catalog" class="alg-inbase" title="Есть в справочнике портала">✓ в базе</span>
            <input v-if="canEdit" class="alg-gsel" :value="c.analog_group || ''" list="alg-group-list"
                   placeholder="группа…" @change="onGroupInput(c, $event)" />
            <button v-if="canEdit" class="alg-edit" @click="openEdit(c)" title="Редактировать карточку">✎</button>
          </div>
        </div>
      </section>
      <UiEmptyState v-if="!filteredGroups.length && !filteredUngrouped.length"
                    title="Ничего не нашлось"
                    description="Аналоги — это товары, которыми можно заменить друг друга. Проверьте фильтры или заведите группу в карточке товара.">
        <template #icon><BkIcon name="copy" size="lg" /></template>
      </UiEmptyState>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import UiEmptyState from '@/components/ui/UiEmptyState.vue';
import { db } from '@/lib/apiClient.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { getEntityGroupCode } from '@/lib/legalEntities.js';
import { appPrompt } from '@/lib/appDialogs.js';
import { exportAnalogsXlsx } from '@/lib/excelExport.js';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import BkIcon from '@/components/ui/BkIcon.vue';

const orderStore = useOrderStore();
const userStore = useUserStore();
const toast = useToastStore();

const canEdit = computed(() => userStore.hasAccess('analogs', 'edit'));
const cards = ref([]);
const loading = ref(false);
const searchInput = ref('');   // то, что печатает пользователь
const search = ref('');         // дебаунс-значение для фильтров
let _searchTimer = null;
watch(searchInput, (v) => {
  clearTimeout(_searchTimer);
  _searchTimer = setTimeout(() => { search.value = v; }, 250);
});
const expanded = ref(new Set());
const importInput = ref(null);
const importing = ref(false);
const showAdd = ref(false);
const savingNew = ref(false);
const editingId = ref(null);
const newCard = ref({ code: '', full_name: '', measure: '', analog_group: '' });
let _cardSnapshot = '';

function snap() { return JSON.stringify(newCard.value); }
function openAdd() {
  editingId.value = null;
  newCard.value = { code: '', full_name: '', measure: '', analog_group: '' };
  _cardSnapshot = snap();
  showAdd.value = true;
}
function openEdit(c) {
  editingId.value = c.id;
  newCard.value = { code: c.code || '', full_name: c.full_name || '', measure: c.measure || '', analog_group: c.analog_group || '' };
  _cardSnapshot = snap();
  showAdd.value = true;
}
function closeAdd() {
  if (snap() !== _cardSnapshot && !confirm('Закрыть без сохранения? Изменения пропадут.')) return;
  showAdd.value = false;
}
async function saveNewCard() {
  const code = newCard.value.code.trim();
  if (!code) return;
  savingNew.value = true;
  try {
    const fields = {
      code,
      sku: code.replace(/^(BK_|ВК_)/, ''),
      full_name: newCard.value.full_name.trim() || null,
      measure: newCard.value.measure.trim() || null,
      analog_group: newCard.value.analog_group.trim() || null,
    };
    if (editingId.value) {
      const { error } = await db.from('analog_cards').update(fields).eq('id', editingId.value);
      if (error) throw new Error(error);
      toast.success('Сохранено', code);
    } else {
      fields.legal_entity_group = getEntityGroupCode(orderStore.settings.legalEntity);
      fields.in_catalog = 0;
      const { error } = await db.from('analog_cards').insert(fields);
      if (error) throw new Error(error);
      toast.success('Карточка добавлена', code);
    }
    _cardSnapshot = snap();
    showAdd.value = false;
    await load();
  } catch (e) {
    toast.error('Ошибка', editingId.value ? 'Не удалось сохранить' : 'Не удалось добавить карточку');
  } finally {
    savingNew.value = false;
  }
}
async function deleteCard() {
  if (!editingId.value) return;
  if (!confirm(`Удалить карточку «${newCard.value.code}» из таблицы аналогов?`)) return;
  savingNew.value = true;
  try {
    const { error } = await db.from('analog_cards').delete().eq('id', editingId.value);
    if (error) throw new Error(error);
    toast.success('Удалено', newCard.value.code);
    _cardSnapshot = snap();
    showAdd.value = false;
    await load();
  } catch (e) {
    toast.error('Ошибка', 'Не удалось удалить');
  } finally {
    savingNew.value = false;
  }
}

async function load() {
  loading.value = true;
  try {
    const group = getEntityGroupCode(orderStore.settings.legalEntity);
    const { data } = await db.from('analog_cards').select('*')
      .eq('legal_entity_group', group).order('analog_group').limit(10000);
    cards.value = data || [];
  } catch { cards.value = []; }
  finally { loading.value = false; }
}

const groupNames = computed(() => {
  const set = new Set();
  for (const c of cards.value) if (c.analog_group) set.add(c.analog_group);
  return [...set].sort((a, b) => a.localeCompare(b, 'ru'));
});
const inCatalogCount = computed(() => cards.value.filter(c => c.in_catalog).length);
const ungrouped = computed(() => cards.value.filter(c => !c.analog_group));

const groups = computed(() => {
  const map = {};
  for (const c of cards.value) {
    if (!c.analog_group) continue;
    (map[c.analog_group] ||= []).push(c);
  }
  return Object.keys(map).sort((a, b) => a.localeCompare(b, 'ru')).map(name => ({ name, items: map[name] }));
});

function stripPfx(s) { return String(s || '').toLowerCase().replace(/^(bk_|вк_)/, ''); }
function matchCard(c, q) {
  const nq = stripPfx(q);
  return (c.code || '').toLowerCase().includes(q) ||
    stripPfx(c.code).includes(nq) ||               // «68697» найдёт «BK_68697»
    (c.full_name || '').toLowerCase().includes(q) ||
    (c.measure || '').toLowerCase().includes(q) ||
    (c.analog_group || '').toLowerCase().includes(q);
}
const foundCount = computed(() => {
  const q = search.value.trim().toLowerCase();
  if (!q) return cards.value.length;
  return cards.value.filter(c => matchCard(c, q)).length;
});
const filteredUngrouped = computed(() => {
  const q = search.value.trim().toLowerCase();
  return q ? ungrouped.value.filter(c => matchCard(c, q)) : ungrouped.value;
});
const filteredGroups = computed(() => {
  const q = search.value.trim().toLowerCase();
  if (!q) return groups.value;
  // Если совпало имя группы ИЛИ хоть один товар — показываем группу ЦЕЛИКОМ,
  // чтобы рядом с найденной карточкой были видны все её аналоги.
  return groups.value.filter(g => g.name.toLowerCase().includes(q) || g.items.some(c => matchCard(c, q)));
});
function isHit(c) {
  const q = search.value.trim().toLowerCase();
  return !!q && matchCard(c, q);
}

function toggle(name) {
  if (expanded.value.has(name)) expanded.value.delete(name);
  else expanded.value.add(name);
  expanded.value = new Set(expanded.value);
}

// При поиске (от 2 символов) раскрываем найденные группы; при очистке — сворачиваем,
// чтобы не держать в DOM сотни строк.
watch(search, (q) => {
  const t = q.trim();
  expanded.value = t.length >= 2 ? new Set(filteredGroups.value.map(g => g.name)) : new Set();
});

async function onGroupInput(card, e) {
  const val = (e.target.value || '').trim();
  if (val === (card.analog_group || '')) return;
  await updateGroup(card, val || null);
}

async function updateGroup(card, newGroup) {
  const prev = card.analog_group;
  try {
    const { error } = await db.from('analog_cards').update({ analog_group: newGroup }).eq('id', card.id);
    if (error) throw new Error(error);
    card.analog_group = newGroup;
    cards.value = [...cards.value];
    toast.success('Готово', newGroup ? `Группа: ${newGroup}` : 'Убрано из группы');
  } catch {
    card.analog_group = prev;
    toast.error('Ошибка', 'Не удалось изменить группу');
  }
}

async function renameGroup(g) {
  const name = await appPrompt('Новое название группы:', g.name);
  if (!name || !name.trim() || name.trim() === g.name) return;
  const target = name.trim();
  try {
    const ids = g.items.map(c => c.id);
    // Обновляем по одной (crud обновляет по id); группа небольшая
    for (const c of g.items) {
      await db.from('analog_cards').update({ analog_group: target }).eq('id', c.id);
      c.analog_group = target;
    }
    cards.value = [...cards.value];
    if (expanded.value.has(g.name)) { expanded.value.delete(g.name); expanded.value.add(target); expanded.value = new Set(expanded.value); }
    toast.success('Группа переименована', `${g.name} → ${target}`);
  } catch {
    toast.error('Ошибка', 'Не удалось переименовать');
    await load();
  }
}

function pickImport() { importInput.value?.click(); }
async function onImportFile(e) {
  const file = e.target.files?.[0];
  e.target.value = '';
  if (!file) return;
  if (!confirm(`Импортировать аналоги из «${file.name}»?\nСуществующие карточки обновятся по коду, новые — добавятся.`)) return;
  importing.value = true;
  try {
    const fd = new FormData();
    fd.append('file', file);
    const token = localStorage.getItem('bk_session_token') || '';
    const res = await fetch('/api/analogs/import', { method: 'POST', headers: token ? { 'X-Session-Token': token } : {}, body: fd });
    const data = await res.json();
    if (!res.ok || data.error) throw new Error(data.error || 'Ошибка');
    toast.success('Импорт завершён', `Всего: ${data.imported}, новых: ${data.new}, обновлено: ${data.updated}, совпало со справочником: ${data.matched}`);
    await load();
  } catch (err) {
    toast.error('Ошибка импорта', err.message || 'Не удалось импортировать');
  } finally {
    importing.value = false;
  }
}

async function exportAll() {
  await exportAnalogsXlsx(cards.value.map(c => ({
    sku: c.code, name: c.full_name, measure: c.measure, supplier: c.supplier, group: c.analog_group,
  })));
}

watch(() => orderStore.settings.legalEntity, load);
onMounted(load);
</script>

<style scoped>
.alg { padding: 4px 2px 60px; }
.page-title { font-size: 22px; font-weight: 800; color: var(--text); margin: 0; }
.alg-sub { font-size: 12px; color: var(--text-muted); margin: 2px 0 0; }
.alg-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
.alg-actions { display: flex; gap: 8px; align-items: center; }
.alg-input { padding: 7px 12px; border: 1.5px solid var(--border); border-radius: 8px; font-size: 13px; background: var(--card); color: var(--text); }
.alg-search-wrap { position: relative; display: flex; align-items: center; }
.alg-search-ico { position: absolute; left: 10px; font-size: 12px; opacity: .6; pointer-events: none; }
.alg-search-input { padding-left: 30px; padding-right: 28px; min-width: 300px; }
.alg-clear { position: absolute; right: 6px; border: none; background: var(--border-light); color: var(--text-muted); border-radius: 50%; width: 18px; height: 18px; line-height: 16px; cursor: pointer; font-size: 14px; padding: 0; }
.alg-clear:hover { background: #E76F51; color: #fff; }
.alg-btn { padding: 7px 14px; border: none; border-radius: 8px; background: #502314; color: #fff; font-weight: 700; font-size: 13px; cursor: pointer; white-space: nowrap; }
.alg-btn:hover:not(:disabled) { background: #3d1a0f; }
.alg-btn:disabled { opacity: .5; cursor: default; }
.alg-btn-ghost { background: var(--card); color: #502314; border: 1.5px solid var(--border); }
.alg-btn-ghost:hover:not(:disabled) { background: var(--bg); }
.alg-found { font-size: 12px; color: var(--text-muted); margin-bottom: 6px; }
.alg-found b { color: #502314; }
.alg-headbar { display: flex; align-items: center; gap: 10px; padding: 4px 14px; font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .4px; color: var(--text-muted); }
.alg-hb-code { min-width: 96px; }
.alg-hb-name { flex: 1; }
.alg-hb-measure { min-width: 60px; text-align: right; }
.alg-hb-group { min-width: 230px; }
.alg-stats { display: flex; gap: 16px; flex-wrap: wrap; font-size: 12px; color: var(--text-muted); margin-bottom: 12px; }
.alg-stats b { color: var(--text); font-size: 14px; }
.alg-stat-warn b { color: #E65100; }
.alg-card { background: var(--card); border: 1px solid var(--border-light); border-radius: 10px; margin-bottom: 8px; overflow: hidden; }
.alg-nogroup { border-color: #FFCC80; }
.alg-group-head { display: flex; align-items: center; gap: 8px; padding: 11px 14px; cursor: pointer; user-select: none; }
.alg-group-head:hover { background: rgba(80,35,20,.03); }
.alg-nogroup .alg-group-head { cursor: default; background: #FFF8EE; }
.alg-group-name { font-weight: 700; color: var(--text); font-size: 14px; }
.alg-count { font-size: 11px; font-weight: 700; background: var(--bg); color: var(--text-muted); border-radius: 10px; padding: 1px 8px; }
.alg-count-warn { background: #FFE0B2; color: #E65100; }
.alg-hint { font-size: 11px; color: var(--text-muted); }
.alg-mini-btn { margin-left: auto; border: none; background: transparent; cursor: pointer; font-size: 14px; color: var(--text-muted); }
.alg-mini-btn:hover { color: #502314; }
.alg-rows { border-top: 1px solid var(--border-light); }
.alg-row { display: flex; align-items: center; gap: 10px; padding: 7px 14px; border-bottom: 1px solid var(--border-light); font-size: 13px; }
.alg-row:last-child { border-bottom: none; }
.alg-row:hover { background: rgba(0,0,0,.012); }
.alg-row-hit { background: #FFF7E0; box-shadow: inset 3px 0 0 #E76F51; }
.alg-row-hit:hover { background: #FFF3D6; }
.alg-code { font-weight: 700; color: #B26A00; min-width: 96px; font-variant-numeric: tabular-nums; }
.alg-code-in { color: #2E7D32; }
.alg-name { flex: 1; color: var(--text); min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.alg-measure { color: var(--text-muted); min-width: 60px; text-align: right; }
.alg-inbase { font-size: 10px; font-weight: 700; color: #2E7D32; background: #E8F5E9; border-radius: 4px; padding: 1px 6px; white-space: nowrap; }
.alg-gsel { max-width: 220px; padding: 4px 8px; border: 1.5px solid var(--border); border-radius: 6px; font-size: 12px; background: var(--card); color: var(--text); }
.alg-empty { text-align: center; padding: 30px; color: var(--text-muted); }
/* Модалка добавления */
.alg-modal-back { position: fixed; inset: 0; background: rgba(0,0,0,.5); backdrop-filter: blur(3px); display: flex; align-items: center; justify-content: center; z-index: 3000; padding: 20px; }
.alg-modal { background: var(--card); border-radius: 14px; width: 100%; max-width: 460px; padding: 20px; box-shadow: 0 10px 40px rgba(0,0,0,.25); }
.alg-modal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.alg-modal-head h3 { margin: 0; font-size: 17px; color: #502314; }
.alg-modal-x { border: none; background: transparent; font-size: 24px; line-height: 1; color: var(--text-muted); cursor: pointer; }
.alg-field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 12px; font-size: 12px; font-weight: 700; color: #502314; }
.alg-field .alg-input { min-width: 0; width: 100%; box-sizing: border-box; padding-left: 11px; padding-right: 11px; }
.alg-req { color: #D62300; }
.alg-field-hint { font-weight: 400; font-size: 11px; color: var(--text-muted); }
.alg-modal-actions { display: flex; align-items: center; gap: 8px; margin-top: 8px; }
.alg-modal-spacer { flex: 1; }
.alg-btn-del { background: #C0392B; }
.alg-btn-del:hover:not(:disabled) { background: #a93226; }
.alg-edit { border: none; background: transparent; cursor: pointer; font-size: 14px; color: var(--text-muted); padding: 2px 4px; border-radius: 4px; }
.alg-edit:hover { color: #502314; background: var(--bg); }
@media (max-width: 700px) {
  .alg-input { min-width: 0; width: 100%; }
  .alg-actions { width: 100%; }
  .alg-name { white-space: normal; }
  .alg-gsel { max-width: 140px; }
}
</style>
