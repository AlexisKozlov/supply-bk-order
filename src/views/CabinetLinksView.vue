<template>
  <div class="cl-page">
    <div class="cl-head">
      <h1 class="cl-title">Ссылки кабинета ресторана</h1>
      <p class="cl-sub">Кнопки-ссылки в кабинете ресторана (например, внешние порталы заказа поставщиков). Видны ресторанам выбранного юрлица; можно ограничить по регионам или конкретным ресторанам.</p>
    </div>

    <div class="cl-toolbar">
      <label>Юрлицо:
        <select v-model="legalEntity" @change="load" class="cl-select">
          <option v-for="e in entities" :key="e" :value="e">{{ ENTITY_SHORT_NAMES[e] || e }}</option>
        </select>
      </label>
      <button class="cl-btn cl-btn-primary" @click="openModal()">+ Добавить ссылку</button>
    </div>

    <div v-if="loading" class="cl-loading"><BurgerSpinner text="Загрузка..." /></div>
    <UiEmptyState v-else-if="!links.length"
                  title="Ссылок пока нет"
                  description="Здесь задаются кнопки, которые ресторан видит у себя в кабинете — например заказ воды или салатов. Добавьте первую.">
      <template #icon><BkIcon name="link" size="lg" /></template>
    </UiEmptyState>
    <div v-else class="cl-list">
      <div v-for="l in links" :key="l.id" class="cl-row" :class="{ 'cl-row-off': !l.is_active }">
        <span class="cl-row-icon" :style="supplierIconStyle(l.icon_key)" v-html="trustedSupplierIcon(l.icon_key)"></span>
        <div class="cl-row-main">
          <div class="cl-row-name">{{ l.name }} <span v-if="!l.is_active" class="cl-off-tag">выключена</span></div>
          <a :href="l.url" target="_blank" rel="noopener" class="cl-row-url">{{ l.url }}</a>
          <div class="cl-row-vis">{{ (l.vis_regions.length || l.vis_restaurants.length) ? ('Видно: ' + visLabel(l)) : 'Видно всем ресторанам юрлица' }}</div>
        </div>
        <div class="cl-row-actions">
          <button class="cl-btn cl-btn-sm" @click="openModal(l)">Изменить</button>
          <button class="cl-btn cl-btn-sm cl-btn-del" @click="removeLink(l)">Удалить</button>
        </div>
      </div>
    </div>

    <!-- Окно редактирования ссылки -->
    <div v-if="modal.open" class="cl-overlay" @click.self="modal.open = false">
      <div class="cl-modal">
        <div class="cl-modal-head">
          <h3>{{ modal.id ? 'Редактировать ссылку' : 'Новая ссылка' }}</h3>
          <button class="cl-close" @click="modal.open = false">×</button>
        </div>
        <div class="cl-modal-body">
          <label class="cl-field">Название
            <input v-model="modal.name" class="cl-input" placeholder="Напр. Лидское пиво" />
          </label>
          <label class="cl-field">Ссылка (http:// или https://)
            <input v-model="modal.url" class="cl-input" placeholder="https://..." />
          </label>
          <div class="cl-field">Иконка
            <div class="cl-icon-picker">
              <button v-for="ic in iconKeys" :key="ic" type="button" class="cl-icon-opt" :class="{ active: modal.icon_key === ic }" :style="supplierIconStyle(ic)" @click="modal.icon_key = ic" v-html="trustedSupplierIcon(ic)"></button>
            </div>
          </div>
          <div class="cl-field">Кому видно
            <button class="cl-btn cl-btn-sm" :class="{ 'cl-vis-on': (modal.vis_regions.length || modal.vis_restaurants.length) }" @click="openAccess">
              {{ (modal.vis_regions.length || modal.vis_restaurants.length) ? ('Ограничено: ' + (modal.vis_regions.length + modal.vis_restaurants.length)) : 'Все рестораны юрлица' }}
            </button>
          </div>
          <label class="cl-check"><input type="checkbox" v-model="modal.is_active" /> Активна (показывать ресторанам)</label>
        </div>
        <div class="cl-modal-foot">
          <button class="cl-btn" @click="modal.open = false">Отмена</button>
          <button class="cl-btn cl-btn-primary" @click="save" :disabled="modal.saving || !modal.name.trim() || !modal.url.trim()">
            {{ modal.saving ? 'Сохранение...' : 'Сохранить' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Окно видимости (регионы/рестораны) -->
    <div v-if="access.open" class="cl-overlay" @click.self="access.open = false">
      <div class="cl-modal">
        <div class="cl-modal-head">
          <h3>Кому видна ссылка</h3>
          <button class="cl-close" @click="access.open = false">×</button>
        </div>
        <div class="cl-modal-body">
          <p class="cl-hint">Ничего не выбрано — ссылку видят <b>все</b> рестораны юрлица. Отметьте регионы или рестораны, чтобы показывать её <b>только им</b>.</p>
          <div v-if="access.loading" class="cl-loading"><BurgerSpinner text="Загрузка..." /></div>
          <template v-else>
            <div class="cl-access-block" v-if="directory.regions.length">
              <div class="cl-access-title">Регионы</div>
              <label v-for="rg in directory.regions" :key="'rg'+rg" class="cl-check">
                <input type="checkbox" :value="rg" v-model="access.regions" /> {{ rg }}
              </label>
            </div>
            <div class="cl-access-block">
              <div class="cl-access-title">Рестораны</div>
              <input v-model="access.search" class="cl-input" placeholder="Поиск по номеру/адресу" />
              <div class="cl-rest-list">
                <label v-for="r in filteredRests" :key="r.number" class="cl-check">
                  <input type="checkbox" :value="String(r.number)" v-model="access.restaurants" />
                  {{ formatRestaurantNumber(r.number) }} — {{ r.city || r.region }}{{ r.address ? ', ' + r.address : '' }}
                </label>
              </div>
            </div>
          </template>
        </div>
        <div class="cl-modal-foot">
          <button class="cl-btn" @click="access.regions = []; access.restaurants = []">Сбросить (всем)</button>
          <button class="cl-btn cl-btn-primary" @click="applyAccess">Готово</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import BkIcon from '@/components/ui/BkIcon.vue';
import UiEmptyState from '@/components/ui/UiEmptyState.vue';
import { useSupplierOrderStore } from '@/stores/supplierOrderStore.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { useConfirm } from '@/composables/useConfirm.js';
import { LEGAL_ENTITIES, ENTITY_SHORT_NAMES, formatRestaurantNumber } from '@/lib/legalEntities.js';
import { trustedSupplierIcon, cabinetLinkIconKeys, supplierIconStyle } from '@/lib/cabinetIcons.js';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';

const store = useSupplierOrderStore();
const orderStore = useOrderStore();
const toast = useToastStore();
const { confirm: showConfirm } = useConfirm();

const iconKeys = cabinetLinkIconKeys;
const entities = computed(() => LEGAL_ENTITIES);
const legalEntity = ref(orderStore.settings.legalEntity || LEGAL_ENTITIES[0]);
const links = ref([]);
const loading = ref(false);

async function load() {
  loading.value = true;
  try { links.value = await store.adminGetCabinetLinks(legalEntity.value); }
  catch (e) { toast.error('Ошибка', e.message); links.value = []; }
  finally { loading.value = false; }
}

function visLabel(l) {
  const parts = [];
  if (l.vis_regions.length) parts.push('регионы (' + l.vis_regions.length + ')');
  if (l.vis_restaurants.length) parts.push('рестораны (' + l.vis_restaurants.length + ')');
  return parts.join(', ');
}

const modal = reactive({ open: false, id: 0, name: '', url: '', icon_key: 'link', is_active: true, vis_regions: [], vis_restaurants: [], saving: false });
function openModal(l = null) {
  modal.open = true;
  modal.id = l?.id || 0;
  modal.name = l?.name || '';
  modal.url = l?.url || '';
  modal.icon_key = l?.icon_key || 'link';
  modal.is_active = l ? !!l.is_active : true;
  modal.vis_regions = [...(l?.vis_regions || [])];
  modal.vis_restaurants = [...(l?.vis_restaurants || [])].map(String);
  modal.saving = false;
}
async function save() {
  if (!modal.name.trim() || !modal.url.trim()) return;
  modal.saving = true;
  try {
    const res = await store.adminSaveCabinetLink({
      id: modal.id || undefined,
      legal_entity: legalEntity.value,
      name: modal.name.trim(),
      url: modal.url.trim(),
      icon_key: modal.icon_key,
      is_active: modal.is_active ? 1 : 0,
      vis_regions: modal.vis_regions,
      vis_restaurants: modal.vis_restaurants,
    });
    if (res && res.error) { toast.error('Ошибка', res.error); return; }
    toast.success('Сохранено', 'Ссылка обновлена');
    modal.open = false;
    await load();
  } catch (e) { toast.error('Ошибка', e.message); }
  finally { modal.saving = false; }
}
async function removeLink(l) {
  if (!await showConfirm('Удалить ссылку?', `«${l.name}» будет удалена из кабинета ресторанов.`)) return;
  try { await store.adminDeleteCabinetLink(l.id); toast.success('Удалено'); await load(); }
  catch (e) { toast.error('Ошибка', e.message); }
}

// Видимость
const directory = ref({ restaurants: [], regions: [] });
const access = reactive({ open: false, loading: false, regions: [], restaurants: [], search: '' });
const filteredRests = computed(() => {
  const q = access.search.trim().toLowerCase();
  const list = directory.value.restaurants;
  if (!q) return list;
  return list.filter(r => String(r.number).includes(q) || String(r.address || '').toLowerCase().includes(q) || String(r.city || '').toLowerCase().includes(q));
});
async function openAccess() {
  access.open = true;
  access.search = '';
  access.regions = [...modal.vis_regions];
  access.restaurants = [...modal.vis_restaurants].map(String);
  access.loading = true;
  try { directory.value = await store.adminGetRestaurantsDirectory('', legalEntity.value); }
  catch (e) { toast.error('Ошибка', e.message); }
  finally { access.loading = false; }
}
function applyAccess() {
  modal.vis_regions = [...access.regions];
  modal.vis_restaurants = [...access.restaurants];
  access.open = false;
}

onMounted(load);
</script>

<style scoped>
.cl-page { max-width: 900px; margin: 0 auto; padding: 16px; }
.cl-title { font-size: 20px; font-weight: 800; color: #502314; margin: 0 0 4px; }
.cl-sub { color: #6b4f3a; font-size: 13px; line-height: 1.5; margin: 0 0 16px; }
.cl-toolbar { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 14px; }
.cl-select { padding: 7px 10px; border: 1.5px solid #e0d5c8; border-radius: 8px; font-size: 14px; background: #fff; margin-left: 6px; }
.cl-btn { padding: 8px 14px; border: 1.5px solid #e0d5c8; border-radius: 8px; background: #fff; color: #502314; font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit; }
.cl-btn:hover { border-color: #E76F51; }
.cl-btn-primary { background: #E76F51; color: #fff; border-color: #E76F51; }
.cl-btn-primary:hover:not(:disabled) { background: #b51e00; }
.cl-btn-primary:disabled { opacity: .5; cursor: not-allowed; }
.cl-btn-sm { padding: 5px 10px; font-size: 12px; }
.cl-btn-del { color: #c62828; border-color: #f1c9c6; }
.cl-btn-del:hover { background: #ffebee; border-color: #c62828; }
.cl-vis-on { background: #FEF6EC; border-color: #F2C9A0; color: #8A5320; }
.cl-loading, .cl-empty { padding: 28px; text-align: center; color: #8b7355; font-size: 14px; }

.cl-list { display: flex; flex-direction: column; gap: 8px; }
.cl-row { display: flex; align-items: center; gap: 14px; padding: 12px 14px; border: 1.5px solid #e0d5c8; border-radius: 10px; background: #fff; }
.cl-row-off { opacity: .6; }
.cl-row-icon { flex: 0 0 auto; width: 30px; height: 30px; color: #5D4037; display: inline-flex; }
.cl-row-icon :deep(svg) { width: 30px; height: 30px; fill: none; stroke: currentColor; stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round; }
.cl-row-main { flex: 1; min-width: 0; }
.cl-row-name { font-weight: 700; color: #2b1a0e; }
.cl-off-tag { font-size: 10px; font-weight: 700; color: #8b7355; background: #f0e8de; border-radius: 8px; padding: 1px 7px; margin-left: 6px; vertical-align: middle; }
.cl-row-url { color: #1565c0; font-size: 12px; word-break: break-all; }
.cl-row-vis { font-size: 11px; color: #8b7355; margin-top: 2px; }
.cl-row-actions { flex: 0 0 auto; display: flex; gap: 8px; }

.cl-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 20px; }
.cl-modal { background: #fff; border-radius: 12px; width: min(560px, 100%); max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 10px 40px rgba(0,0,0,.3); }
.cl-modal-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid #eee; }
.cl-modal-head h3 { margin: 0; font-size: 16px; color: #502314; }
.cl-close { background: none; border: none; font-size: 26px; cursor: pointer; color: #8b7355; line-height: 1; }
.cl-modal-body { padding: 18px 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 14px; }
.cl-modal-foot { display: flex; gap: 10px; justify-content: flex-end; padding: 14px 20px; border-top: 1px solid #eee; }
.cl-field { display: flex; flex-direction: column; gap: 6px; font-size: 12px; font-weight: 700; color: #6b4f3a; text-transform: uppercase; letter-spacing: .3px; }
.cl-input { padding: 8px 10px; border: 1.5px solid #e0d5c8; border-radius: 8px; font-size: 14px; font-weight: 400; text-transform: none; color: #2b1a0e; }
.cl-input:focus { border-color: #E76F51; outline: none; }
.cl-check { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 400; text-transform: none; color: #2b1a0e; cursor: pointer; }
.cl-hint { font-size: 12px; color: #6b4f3a; margin: 0; font-weight: 400; text-transform: none; }
.cl-icon-picker { display: flex; flex-wrap: wrap; gap: 8px; }
.cl-icon-opt { width: 42px; height: 42px; border: 2px solid #e0d5c8; border-radius: 8px; background: #fff; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; color: #5D4037; }
.cl-icon-opt :deep(svg) { width: 24px; height: 24px; fill: none; stroke: currentColor; stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round; }
.cl-icon-opt.active { border-color: #E76F51; background: #fff2e0; }
.cl-access-block { display: flex; flex-direction: column; gap: 6px; }
.cl-access-title { font-size: 12px; font-weight: 700; color: #6b4f3a; text-transform: uppercase; }
.cl-rest-list { max-height: 260px; overflow-y: auto; display: flex; flex-direction: column; gap: 4px; border: 1px solid #e0d5c8; border-radius: 8px; padding: 8px; }

@media (max-width: 560px) {
  /* «Юрлицо» и «Добавить ссылку» в одну строку не помещались — кнопка
     обрезалась справа. Кнопку на всю ширину, ссылки переносим по словам. */
  .cl-page { padding: 12px; width: 100%; max-width: 100%; }
  .cl-toolbar { gap: 10px; }
  .cl-toolbar > button { width: 100%; }
  .cl-page a { overflow-wrap: anywhere; }
}
</style>
