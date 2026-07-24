<template>
  <div class="alg">
    <div class="alg-top">
      <div>
        <h1 class="page-title">Аналоги</h1>
        <p class="alg-sub">Группы аналогов товаров. Отдел качества и бухгалтерия сверяются здесь, закупки редактируют.</p>
      </div>
      <div class="alg-actions">
        <div class="alg-search-wrap">
          <input v-model="search" class="alg-input" placeholder="Поиск: код, наименование, группа…" />
        </div>
        <button class="alg-btn" @click="exportAll" :disabled="!cards.length">⭳ Excel</button>
      </div>
    </div>

    <div class="alg-stats">
      <span><b>{{ cards.length }}</b> карточек</span>
      <span><b>{{ groupNames.length }}</b> групп</span>
      <span v-if="ungrouped.length" class="alg-stat-warn"><b>{{ ungrouped.length }}</b> без группы</span>
      <span><b>{{ inCatalogCount }}</b> в справочнике</span>
    </div>

    <div v-if="loading" style="text-align:center;padding:50px;"><BurgerSpinner text="Загрузка…" /></div>
    <div v-else-if="!cards.length" style="text-align:center;padding:50px;color:var(--text-muted);">Карточек аналогов нет</div>

    <template v-else>
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
            <select v-if="canEdit" class="alg-gsel" :value="c.analog_group || ''" @change="onGroupChange(c, $event)">
              <option value="">— без группы —</option>
              <option v-for="gn in groupNames" :key="gn" :value="gn">{{ gn }}</option>
              <option value="__new__">＋ Новая группа…</option>
            </select>
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
          <div v-for="c in g.items" :key="c.id" class="alg-row">
            <span class="alg-code" :class="{ 'alg-code-in': c.in_catalog }">{{ c.code }}</span>
            <span class="alg-name">{{ c.full_name || '—' }}</span>
            <span class="alg-measure">{{ c.measure || '' }}</span>
            <span v-if="c.in_catalog" class="alg-inbase" title="Есть в справочнике портала">✓ в базе</span>
            <select v-if="canEdit" class="alg-gsel" :value="c.analog_group || ''" @change="onGroupChange(c, $event)">
              <option value="">— без группы —</option>
              <option v-for="gn in groupNames" :key="gn" :value="gn">{{ gn }}</option>
              <option value="__new__">＋ Новая группа…</option>
            </select>
          </div>
        </div>
      </section>
      <div v-if="!filteredGroups.length && !filteredUngrouped.length" class="alg-empty">Ничего не найдено</div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
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
const search = ref('');
const expanded = ref(new Set());

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

function matchCard(c, q) {
  return (c.code || '').toLowerCase().includes(q) ||
    (c.full_name || '').toLowerCase().includes(q) ||
    (c.analog_group || '').toLowerCase().includes(q);
}
const filteredUngrouped = computed(() => {
  const q = search.value.trim().toLowerCase();
  return q ? ungrouped.value.filter(c => matchCard(c, q)) : ungrouped.value;
});
const filteredGroups = computed(() => {
  const q = search.value.trim().toLowerCase();
  if (!q) return groups.value;
  return groups.value
    .map(g => g.name.toLowerCase().includes(q) ? g : { name: g.name, items: g.items.filter(c => matchCard(c, q)) })
    .filter(g => g.items.length);
});

function toggle(name) {
  if (expanded.value.has(name)) expanded.value.delete(name);
  else expanded.value.add(name);
  expanded.value = new Set(expanded.value);
}

// При поиске — авто-раскрытие найденных групп
watch(search, (q) => {
  if (q.trim()) expanded.value = new Set(filteredGroups.value.map(g => g.name));
});

async function onGroupChange(card, e) {
  const val = e.target.value;
  if (val === '__new__') {
    e.target.value = card.analog_group || '';
    const name = await appPrompt('Название новой группы аналогов:', '');
    if (!name || !name.trim()) return;
    await updateGroup(card, name.trim());
  } else {
    await updateGroup(card, val || null);
  }
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
.alg-input { padding: 7px 12px; border: 1.5px solid var(--border); border-radius: 8px; font-size: 13px; background: var(--card); color: var(--text); min-width: 240px; }
.alg-btn { padding: 7px 14px; border: none; border-radius: 8px; background: #502314; color: #fff; font-weight: 700; font-size: 13px; cursor: pointer; white-space: nowrap; }
.alg-btn:hover:not(:disabled) { background: #3d1a0f; }
.alg-btn:disabled { opacity: .5; cursor: default; }
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
.alg-code { font-weight: 700; color: #B26A00; min-width: 96px; font-variant-numeric: tabular-nums; }
.alg-code-in { color: #2E7D32; }
.alg-name { flex: 1; color: var(--text); min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.alg-measure { color: var(--text-muted); min-width: 60px; text-align: right; }
.alg-inbase { font-size: 10px; font-weight: 700; color: #2E7D32; background: #E8F5E9; border-radius: 4px; padding: 1px 6px; white-space: nowrap; }
.alg-gsel { max-width: 220px; padding: 4px 8px; border: 1.5px solid var(--border); border-radius: 6px; font-size: 12px; background: var(--card); color: var(--text); }
.alg-empty { text-align: center; padding: 30px; color: var(--text-muted); }
@media (max-width: 700px) {
  .alg-input { min-width: 0; width: 100%; }
  .alg-actions { width: 100%; }
  .alg-name { white-space: normal; }
  .alg-gsel { max-width: 140px; }
}
</style>
