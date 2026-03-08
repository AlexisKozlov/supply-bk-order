<template>
  <div class="tender-detail">
    <!-- Шапка -->
    <div class="td-header">
      <div class="td-header-left">
        <a class="td-back-link" @click.prevent="$router.push({ name: 'tenders' })"><BkIcon name="back" size="sm" /> Тендеры</a>
        <h1 v-if="!editingName" class="td-title" @click="!isViewer && startEditName()">{{ tender.name || 'Без названия' }}</h1>
        <input v-else v-model="tender.name" class="td-title-input" @blur="editingName = false" @keydown.enter="editingName = false" ref="nameInput" />
        <span class="td-badge" :class="'st-' + tender.status">{{ statusLabel(tender.status) }}</span>
      </div>
      <div class="td-header-right">
        <button v-if="hasFullAccess" class="td-btn td-btn-outline" @click="deleteTender">Удалить</button>
        <button v-if="!isViewer" class="td-btn td-btn-primary" @click="save" :disabled="saving">{{ saving ? 'Сохранение...' : 'Сохранить' }}</button>
      </div>
    </div>

    <div v-if="loadingTender" style="text-align:center;padding:60px;"><BurgerSpinner text="Загрузка тендера..." /></div>
    <template v-else>
      <!-- Табы -->
      <div class="td-tabs-bar">
        <button class="td-tab" :class="{ active: tab === 'info' }" @click="tab = 'info'">Информация</button>
        <button class="td-tab" :class="{ active: tab === 'offers' }" @click="tab = 'offers'">Предложения <span v-if="tender.offers.length" class="td-tab-count">{{ tender.offers.length }}</span></button>
        <button class="td-tab" :class="{ active: tab === 'compare' }" @click="tab = 'compare'">Сравнение</button>
      </div>

      <!-- ═══ Таб: Информация ═══ -->
      <div v-if="tab === 'info'" class="td-content">
        <div class="td-grid">
          <div class="td-card">
            <div class="td-card-title">Основное</div>
            <div class="td-form-row">
              <div class="form-group" style="flex:2;">
                <label>Название</label>
                <input v-model="tender.name" class="form-input" :disabled="isViewer" />
              </div>
              <div class="form-group" style="flex:1;">
                <label>Статус</label>
                <select v-model="tender.status" class="form-input" :disabled="isViewer">
                  <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
              </div>
            </div>
            <div class="td-form-row">
              <div class="form-group" style="flex:1;">
                <label>Крайний срок</label>
                <input v-model="tender.deadline" type="date" class="form-input" :disabled="isViewer" />
              </div>
              <div class="form-group" style="flex:1;">
                <label>Победитель</label>
                <input v-model="tender.winner_supplier" class="form-input" placeholder="—" :disabled="isViewer" />
              </div>
            </div>
            <div class="form-group">
              <label>Описание / требования</label>
              <textarea v-model="tender.description" class="form-input" rows="2" style="resize:vertical;" :disabled="isViewer" placeholder="Условия, объёмы, требования к качеству..."></textarea>
            </div>
            <div class="form-group">
              <label>Примечания к тендеру</label>
              <textarea v-model="tender.note" class="form-input" rows="2" style="resize:vertical;" :disabled="isViewer" placeholder="Любые заметки..."></textarea>
            </div>
          </div>

          <!-- Позиции -->
          <div class="td-card">
            <div class="td-card-title">
              Позиции тендера
              <button v-if="!isViewer" class="btn small secondary" @click="addItem">+ Позиция</button>
            </div>
            <div v-if="!tender.items.length" class="td-empty">Добавьте товары или услуги</div>
            <div v-else class="td-items-table-wrap">
              <table class="td-items-table">
                <thead>
                  <tr>
                    <th style="width:40%;">Название</th>
                    <th style="width:15%;">Кол-во</th>
                    <th style="width:12%;">Ед.</th>
                    <th>Примечание</th>
                    <th v-if="!isViewer" style="width:36px;"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(item, i) in tender.items" :key="i">
                    <td><input v-model="item.name" class="td-cell-input" placeholder="Название" :disabled="isViewer" /></td>
                    <td><input v-model.number="item.quantity" type="number" min="0" class="td-cell-input" placeholder="—" :disabled="isViewer" /></td>
                    <td><input v-model="item.unit" class="td-cell-input" placeholder="шт" :disabled="isViewer" /></td>
                    <td><input v-model="item.note" class="td-cell-input" placeholder="—" :disabled="isViewer" /></td>
                    <td v-if="!isViewer"><button class="remove-btn" @click="removeItem(i)">&times;</button></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══ Таб: Предложения ═══ -->
      <div v-if="tab === 'offers'" class="td-content">
        <div class="td-offers-header">
          <button v-if="!isViewer" class="btn primary" @click="addOffer">+ Предложение поставщика</button>
        </div>
        <div v-if="!tender.offers.length" class="td-empty" style="padding:40px;">Добавьте предложения от поставщиков</div>
        <div v-else class="td-offers-list">
          <div v-for="(offer, oi) in tender.offers" :key="oi" class="td-offer-card">
            <div class="td-offer-header">
              <input v-model="offer.supplier" class="td-offer-supplier-input" placeholder="Название поставщика" :disabled="isViewer" />
              <button v-if="!isViewer" class="remove-btn" @click="tender.offers.splice(oi, 1)" title="Удалить">&times;</button>
            </div>
            <!-- Условия -->
            <div class="td-offer-conditions">
              <div class="form-group">
                <label>Срок поставки (дней)</label>
                <input v-model.number="offer.delivery_days" type="number" min="0" class="form-input" :disabled="isViewer" placeholder="—" />
              </div>
              <div class="form-group">
                <label>Условия оплаты</label>
                <input v-model="offer.payment_terms" class="form-input" :disabled="isViewer" placeholder="Предоплата, отсрочка 14 дн..." />
              </div>
              <div class="form-group" style="flex:2;">
                <label>Дополнительные условия</label>
                <input v-model="offer.conditions" class="form-input" :disabled="isViewer" placeholder="Минимальная партия, доставка..." />
              </div>
            </div>
            <!-- Цены по позициям -->
            <div v-if="tender.items.length" class="td-offer-prices">
              <div class="td-offer-prices-title">Цены по позициям</div>
              <table class="td-prices-table">
                <thead>
                  <tr>
                    <th>Позиция</th>
                    <th style="width:100px;">Цена</th>
                    <th v-if="tender.items.some(it => it.quantity)" style="width:100px;">Сумма</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(item, ii) in tender.items" :key="ii">
                    <td class="td-price-item-name">{{ item.name || '(без названия)' }}</td>
                    <td>
                      <input v-model.number="offer.prices[ii]" type="number" step="0.01" min="0"
                        class="td-cell-input price-cell" :class="{ 'cheapest': isCheapest(ii, offer.prices[ii]) }"
                        :disabled="isViewer" placeholder="—" />
                    </td>
                    <td v-if="tender.items.some(it => it.quantity)" class="td-sum-cell">
                      {{ item.quantity && offer.prices[ii] ? formatPrice(item.quantity * offer.prices[ii]) : '' }}
                    </td>
                  </tr>
                </tbody>
                <tfoot>
                  <tr class="td-prices-total">
                    <td style="font-weight:700;">Итого</td>
                    <td class="td-sum-cell" style="font-weight:700;">{{ formatPrice(offerTotal(offer)) }}</td>
                    <td v-if="tender.items.some(it => it.quantity)" class="td-sum-cell" style="font-weight:700;">{{ formatPrice(offerTotalWithQty(offer)) }}</td>
                  </tr>
                </tfoot>
              </table>
            </div>
            <!-- Файлы КП -->
            <div class="td-offer-files" style="margin-top:10px;">
              <div class="td-offer-prices-title">Файлы КП</div>
              <div v-if="getOfferFiles(offer.supplier).length" class="td-files-list">
                <div v-for="f in getOfferFiles(offer.supplier)" :key="f.id" class="td-file-item">
                  <a :href="'/api/uploads/tenders/' + f.file_path + '?download=1'" class="td-file-link" target="_blank">
                    <span class="td-file-icon">{{ fileIcon(f.file_name) }}</span>
                    {{ f.file_name }}
                  </a>
                  <button v-if="!isViewer" class="remove-btn" @click="deleteFile(f.id)" title="Удалить">&times;</button>
                </div>
              </div>
              <div v-else class="td-files-empty">Нет прикреплённых файлов</div>
              <label v-if="!isViewer && offer.supplier" class="td-file-upload-btn">
                <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.xlsx,.xls,.doc,.docx" @change="uploadFile($event, offer.supplier)" style="display:none;" />
                + Прикрепить файл
              </label>
              <div v-if="!isViewer && !offer.supplier" class="td-files-empty" style="font-style:italic;">Укажите название поставщика для загрузки файлов</div>
            </div>
            <!-- Примечание -->
            <div class="form-group" style="margin-top:8px;">
              <label>Примечание к поставщику</label>
              <input v-model="offer.note" class="form-input" :disabled="isViewer" placeholder="Комментарий по предложению..." />
            </div>
          </div>
        </div>
      </div>

      <!-- ═══ Таб: Сравнение ═══ -->
      <div v-if="tab === 'compare'" class="td-content">
        <div v-if="tender.offers.length < 2 || !tender.items.length" class="td-empty" style="padding:40px;">
          Для сравнения нужно минимум 2 поставщика и 1 позиция
        </div>
        <div v-else class="compare-layout">
          <!-- Левая часть — таблица -->
          <div class="compare-main">
            <div class="td-card">
              <div class="td-card-title">
                Сравнительная таблица
                <button class="td-btn td-btn-outline" style="font-size:11px;padding:5px 14px;" @click="exportComparison"><BkIcon name="export" size="sm" /> Excel</button>
              </div>
              <div class="comparison-wrap">
                <table class="comp-table">
                  <thead>
                    <tr>
                      <th class="comp-fixed-col">Позиция</th>
                      <th class="comp-qty-col">Кол-во</th>
                      <th v-for="(o, oi) in tender.offers" :key="oi" class="comp-sup-col"
                        :class="{ winner: tender.winner_supplier && o.supplier === tender.winner_supplier }">
                        {{ o.supplier || '(?)' }}
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(item, ii) in tender.items" :key="ii">
                      <td class="comp-fixed-col">{{ item.name }}</td>
                      <td class="comp-qty-col">{{ item.quantity ? `${item.quantity} ${item.unit || ''}`.trim() : '' }}</td>
                      <td v-for="(o, oi) in tender.offers" :key="oi"
                        :class="{
                          cheapest: isCheapest(ii, o.prices[ii]),
                          expensive: isMostExpensive(ii, o.prices[ii]),
                          winner: tender.winner_supplier && o.supplier === tender.winner_supplier,
                        }">
                        <div class="comp-price">{{ o.prices[ii] > 0 ? formatPrice(o.prices[ii]) : '—' }}</div>
                        <div v-if="item.quantity && o.prices[ii] > 0" class="comp-sum">{{ formatPrice(o.prices[ii] * item.quantity) }}</div>
                      </td>
                    </tr>
                  </tbody>
                  <tfoot>
                    <tr class="comp-total-row">
                      <td class="comp-fixed-col"><strong>Итого (цена)</strong></td>
                      <td class="comp-qty-col"></td>
                      <td v-for="(o, oi) in tender.offers" :key="oi"
                        :class="{ 'cheapest-total': isCheapestTotal(o), winner: tender.winner_supplier && o.supplier === tender.winner_supplier }">
                        <strong>{{ formatPrice(offerTotal(o)) }}</strong>
                      </td>
                    </tr>
                    <tr v-if="tender.items.some(it => it.quantity)" class="comp-total-row">
                      <td class="comp-fixed-col"><strong>Итого (сумма)</strong></td>
                      <td class="comp-qty-col"></td>
                      <td v-for="(o, oi) in tender.offers" :key="oi"
                        :class="{ 'cheapest-total': isCheapestTotalQty(o), winner: tender.winner_supplier && o.supplier === tender.winner_supplier }">
                        <strong>{{ formatPrice(offerTotalWithQty(o)) }}</strong>
                      </td>
                    </tr>
                    <tr class="comp-extra-row">
                      <td class="comp-fixed-col">Срок поставки</td>
                      <td class="comp-qty-col"></td>
                      <td v-for="(o, oi) in tender.offers" :key="oi" :class="{ 'best-term': isFastestDelivery(o) }">
                        {{ o.delivery_days ? `${o.delivery_days} дн.` : '—' }}
                      </td>
                    </tr>
                    <tr class="comp-extra-row">
                      <td class="comp-fixed-col">Условия оплаты</td>
                      <td class="comp-qty-col"></td>
                      <td v-for="(o, oi) in tender.offers" :key="oi">{{ o.payment_terms || '—' }}</td>
                    </tr>
                    <tr v-if="tender.offers.some(o => o.conditions)" class="comp-extra-row">
                      <td class="comp-fixed-col">Доп. условия</td>
                      <td class="comp-qty-col"></td>
                      <td v-for="(o, oi) in tender.offers" :key="oi">{{ o.conditions || '—' }}</td>
                    </tr>
                    <tr v-if="tender.offers.some(o => o.note)" class="comp-extra-row">
                      <td class="comp-fixed-col">Примечание</td>
                      <td class="comp-qty-col"></td>
                      <td v-for="(o, oi) in tender.offers" :key="oi" style="font-style:italic;">{{ o.note || '' }}</td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </div>
          </div>

          <!-- Правая боковая панель -->
          <div class="compare-sidebar">
            <!-- Победитель -->
            <div class="side-section">
              <div class="side-label">Победитель</div>
              <div class="winner-cards">
                <div v-for="(o, oi) in tender.offers" :key="oi"
                  class="wcard" :class="{ active: tender.winner_supplier === o.supplier }"
                  @click="!isViewer && (tender.winner_supplier = tender.winner_supplier === o.supplier ? '' : o.supplier)">
                  <div>
                    <div class="wcard-name">{{ o.supplier || '(?)' }}</div>
                    <div class="wcard-total">{{ formatPrice(offerTotalWithQty(o)) }}</div>
                  </div>
                  <div class="wcard-check">{{ tender.winner_supplier === o.supplier ? '✓' : '' }}</div>
                </div>
              </div>
            </div>

            <!-- Экономия -->
            <div v-if="tender.winner_supplier && savings" class="side-section">
              <div class="side-label">Экономия</div>
              <div class="saving-card">
                <div class="saving-amount">{{ formatPrice(savings) }}</div>
                <div class="saving-pct" v-if="savingsPercent">−{{ savingsPercent }}%</div>
                <div class="saving-sub">vs самое дорогое предложение</div>
              </div>
            </div>

            <!-- Условия победителя -->
            <div v-if="winnerOffer" class="side-section">
              <div class="side-label">Условия победителя</div>
              <div class="cond-list">
                <div v-if="winnerOffer.delivery_days" class="cond-item">
                  <div class="cond-icon">
                    <BkIcon name="calendar" size="sm" />
                  </div>
                  <div>
                    <div class="cond-text">Доставка {{ winnerOffer.delivery_days }} дн.</div>
                    <div v-if="isFastestDelivery(winnerOffer)" class="cond-note">Самая быстрая</div>
                  </div>
                </div>
                <div v-if="winnerOffer.payment_terms" class="cond-item">
                  <div class="cond-icon">
                    <BkIcon name="tender" size="sm" />
                  </div>
                  <div>
                    <div class="cond-text">{{ winnerOffer.payment_terms }}</div>
                  </div>
                </div>
                <div class="cond-item">
                  <div class="cond-icon">
                    <BkIcon name="check" size="sm" />
                  </div>
                  <div>
                    <div class="cond-text">{{ winnerCheapestCount }} из {{ tender.items.length }} позиций</div>
                    <div class="cond-note">Лучшая цена</div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Статус -->
            <div class="side-section">
              <div class="side-label">Статус</div>
              <select v-model="tender.status" class="side-select" :disabled="isViewer">
                <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
              </select>
            </div>

            <!-- Обоснование -->
            <div class="side-section">
              <div class="side-label">Обоснование</div>
              <textarea v-model="tender.summary" class="side-textarea"
                :disabled="isViewer" placeholder="Почему выбран этот поставщик..."></textarea>
            </div>

            <!-- Мета -->
            <div class="side-meta">
              <div v-if="tender.deadline">Дедлайн: {{ formatDate(tender.deadline) }}</div>
            </div>
          </div>
        </div>
      </div>
    </template>

    <ConfirmModal v-if="confirmModal.show" :title="confirmModal.title" :message="confirmModal.message"
      @confirm="onConfirm" @cancel="onCancel" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { db } from '@/lib/apiClient.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';
import { useConfirm } from '@/composables/useConfirm.js';

const { confirmModal, confirm, onConfirm, onCancel } = useConfirm();

const route = useRoute();
const router = useRouter();
const orderStore = useOrderStore();
const userStore = useUserStore();
const toast = useToastStore();

const isViewer = computed(() => !userStore.hasAccess('tenders', 'edit'));
const hasFullAccess = computed(() => userStore.hasAccess('tenders', 'full'));

const statuses = [
  { value: 'draft', label: 'Черновик' },
  { value: 'collecting', label: 'Сбор предложений' },
  { value: 'evaluation', label: 'Оценка' },
  { value: 'approval', label: 'Согласование' },
  { value: 'completed', label: 'Завершён' },
];
function statusLabel(s) { return statuses.find(x => x.value === s)?.label || s; }

const tab = ref('info');
const loadingTender = ref(false);
const saving = ref(false);
const editingName = ref(false);
const nameInput = ref(null);

const tender = ref({
  name: '', description: '', status: 'draft', deadline: '', winner_supplier: '', summary: '', note: '',
  items: [],
  offers: [],
  files: [],
});

function startEditName() {
  editingName.value = true;
  nextTick(() => nameInput.value?.focus());
}

// Загрузка
async function loadTender() {
  const id = route.params.id;
  if (!id) return;
  loadingTender.value = true;
  try {
    const { data, error } = await db.rpc('get_tender', { id: parseInt(id) });
    if (error) { toast.error('Ошибка', error); router.push({ name: 'tenders' }); return; }
    tender.value.name = data.name || '';
    tender.value.description = data.description || '';
    tender.value.status = data.status || 'draft';
    tender.value.deadline = data.deadline || '';
    tender.value.winner_supplier = data.winner_supplier || '';
    tender.value.summary = data.summary || '';
    tender.value.note = data.note || '';

    const items = (data.items || []).sort((a, b) => a.sort_order - b.sort_order);
    tender.value.items = items.map(it => ({
      name: it.name || '', quantity: it.quantity ? parseFloat(it.quantity) : null,
      unit: it.unit || '', note: it.note || '',
    }));

    tender.value.offers = (data.offers || []).map(o => {
      const prices = [];
      for (let i = 0; i < items.length; i++) {
        const op = (o.prices || []).find(p => p.item_id === items[i].id);
        prices[i] = op ? parseFloat(op.price) : null;
      }
      return {
        supplier: o.supplier || '', delivery_days: o.delivery_days || null,
        payment_terms: o.payment_terms || '', conditions: o.conditions || '',
        note: o.note || '', prices,
      };
    });

    tender.value.files = (data.files || []).map(f => ({
      id: f.id, supplier: f.supplier, file_name: f.file_name, file_path: f.file_path,
    }));
  } finally {
    loadingTender.value = false;
  }
}

// Позиции
function addItem() {
  tender.value.items.push({ name: '', quantity: null, unit: 'шт', note: '' });
  for (const o of tender.value.offers) o.prices.push(null);
}
function removeItem(i) {
  tender.value.items.splice(i, 1);
  for (const o of tender.value.offers) o.prices.splice(i, 1);
}

function addOffer() {
  tender.value.offers.push({
    supplier: '', delivery_days: null, payment_terms: '', conditions: '', note: '',
    prices: tender.value.items.map(() => null),
  });
}

// Файлы КП
const API_BASE = '/api';
function getOfferFiles(supplier) {
  if (!supplier) return [];
  return tender.value.files.filter(f => f.supplier === supplier);
}
function fileIcon(name) {
  if (!name) return '📄';
  const ext = name.split('.').pop().toLowerCase();
  if (ext === 'pdf') return '📕';
  if (['jpg','jpeg','png','webp'].includes(ext)) return '🖼️';
  if (['xlsx','xls'].includes(ext)) return '📊';
  if (['doc','docx'].includes(ext)) return '📝';
  return '📄';
}
async function uploadFile(event, supplier) {
  const file = event.target.files?.[0];
  if (!file) return;
  event.target.value = '';
  const tenderId = parseInt(route.params.id);
  if (!tenderId) { toast.error('Сначала сохраните тендер'); return; }

  const formData = new FormData();
  formData.append('file', file);
  formData.append('tender_id', tenderId);
  formData.append('supplier', supplier);

  try {
    const token = localStorage.getItem('bk_session_token') || '';
    const res = await fetch(`${API_BASE}/upload/tender-kp`, {
      method: 'POST',
      headers: { 'X-Session-Token': token },
      body: formData,
    });
    const data = await res.json();
    if (!res.ok || data.error) { toast.error('Ошибка загрузки', data.error || ''); return; }
    tender.value.files.push({
      id: data.id, supplier, file_name: data.file_name, file_path: data.file_path,
    });
    toast.success('Файл загружен');
  } catch (err) {
    toast.error('Ошибка загрузки', err.message);
  }
}
async function deleteFile(fileId) {
  if (!await confirm('Удалить файл?', 'Файл будет удалён без возможности восстановления.')) return;
  try {
    const token = localStorage.getItem('bk_session_token') || '';
    const res = await fetch(`${API_BASE}/upload/tender-kp?file_id=${fileId}`, {
      method: 'DELETE',
      headers: { 'X-Session-Token': token },
    });
    const data = await res.json();
    if (!res.ok || data.error) { toast.error('Ошибка', data.error || ''); return; }
    tender.value.files = tender.value.files.filter(f => f.id !== fileId);
    toast.success('Файл удалён');
  } catch (err) {
    toast.error('Ошибка', err.message);
  }
}

// Вычисления
function formatPrice(v) {
  const n = parseFloat(v);
  if (isNaN(n) || n === 0) return '—';
  return n.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function isCheapest(ii, price) {
  if (!price || price <= 0) return false;
  const all = tender.value.offers.map(o => o.prices[ii]).filter(p => p > 0);
  return all.length >= 2 && price <= Math.min(...all);
}
function isMostExpensive(ii, price) {
  if (!price || price <= 0) return false;
  const all = tender.value.offers.map(o => o.prices[ii]).filter(p => p > 0);
  return all.length >= 2 && price >= Math.max(...all);
}
function offerTotal(o) { return o.prices.reduce((s, p) => s + (parseFloat(p) || 0), 0); }
function offerTotalWithQty(o) {
  let s = 0;
  for (let i = 0; i < tender.value.items.length; i++) {
    s += (parseFloat(o.prices[i]) || 0) * (parseFloat(tender.value.items[i].quantity) || 1);
  }
  return s;
}
function isCheapestTotal(o) {
  if (tender.value.offers.length < 2) return false;
  const t = offerTotal(o);
  return t > 0 && tender.value.offers.every(x => x === o || offerTotal(x) <= 0 || t <= offerTotal(x));
}
function isCheapestTotalQty(o) {
  if (tender.value.offers.length < 2) return false;
  const t = offerTotalWithQty(o);
  return t > 0 && tender.value.offers.every(x => x === o || offerTotalWithQty(x) <= 0 || t <= offerTotalWithQty(x));
}
function isFastestDelivery(o) {
  if (!o.delivery_days) return false;
  const all = tender.value.offers.filter(x => x.delivery_days > 0).map(x => x.delivery_days);
  return all.length >= 2 && o.delivery_days <= Math.min(...all);
}

const savings = computed(() => {
  if (!tender.value.winner_supplier || tender.value.offers.length < 2) return 0;
  const winner = tender.value.offers.find(o => o.supplier === tender.value.winner_supplier);
  if (!winner) return 0;
  const winnerTotal = offerTotalWithQty(winner);
  const maxTotal = Math.max(...tender.value.offers.map(o => offerTotalWithQty(o)));
  return maxTotal > winnerTotal ? maxTotal - winnerTotal : 0;
});

const savingsPercent = computed(() => {
  if (!savings.value) return 0;
  const maxTotal = Math.max(...tender.value.offers.map(o => offerTotalWithQty(o)));
  return maxTotal > 0 ? Math.round(savings.value / maxTotal * 100) : 0;
});

const winnerOffer = computed(() => {
  if (!tender.value.winner_supplier) return null;
  return tender.value.offers.find(o => o.supplier === tender.value.winner_supplier) || null;
});

const winnerCheapestCount = computed(() => {
  if (!winnerOffer.value) return 0;
  let count = 0;
  for (let i = 0; i < tender.value.items.length; i++) {
    if (isCheapest(i, winnerOffer.value.prices[i])) count++;
  }
  return count;
});

function formatDate(d) {
  if (!d) return '';
  const ds = typeof d === 'string' && d.length === 10 ? d + 'T00:00:00' : d;
  const dt = new Date(ds);
  if (isNaN(dt)) return d;
  return dt.toLocaleDateString('ru-RU');
}

// Сохранение
async function save() {
  if (saving.value) return;
  if (!tender.value.name.trim()) { toast.error('Укажите название'); return; }
  saving.value = true;
  try {
    const { error } = await db.rpc('save_tender', {
      id: parseInt(route.params.id) || 0,
      name: tender.value.name.trim(),
      description: tender.value.description || null,
      legal_entity: orderStore.settings.legalEntity,
      status: tender.value.status,
      deadline: tender.value.deadline || null,
      winner_supplier: tender.value.winner_supplier || null,
      summary: tender.value.summary || null,
      note: tender.value.note || null,
      items: tender.value.items.map(it => ({ name: it.name, quantity: it.quantity, unit: it.unit, note: it.note || null })),
      offers: tender.value.offers.map(o => ({
        supplier: o.supplier, delivery_days: o.delivery_days || null,
        payment_terms: o.payment_terms || null, conditions: o.conditions || null,
        note: o.note || null,
        prices: o.prices.map(p => p != null ? parseFloat(p) : null),
      })),
    });
    if (error) { toast.error('Ошибка', error); return; }
    toast.success('Тендер сохранён');
  } finally { saving.value = false; }
}

async function deleteTender() {
  if (!await confirm('Удалить тендер?', `«${tender.value.name}» будет удалён со всеми данными.`)) return;
  const { error } = await db.rpc('delete_tender', { id: parseInt(route.params.id) });
  if (error) { toast.error('Ошибка', error); return; }
  toast.success('Удалено');
  router.push({ name: 'tenders' });
}

// Экспорт в Excel
async function exportComparison() {
  try {
    const XLSX = await import('xlsx-js-style');
    const brown = '502314';
    const border = { style: 'thin', color: { rgb: 'E0D6CC' } };
    const borders = { top: border, bottom: border, left: border, right: border };
    const sH = { font: { bold: true, sz: 11, color: { rgb: 'FFFFFF' }, name: 'Calibri' }, fill: { fgColor: { rgb: brown } }, alignment: { horizontal: 'center', vertical: 'center' }, border: borders };
    const sC = { font: { sz: 11, name: 'Calibri' }, border: borders, alignment: { vertical: 'center' } };
    const sR = { ...sC, alignment: { ...sC.alignment, horizontal: 'right' } };
    const sG = { ...sC, fill: { fgColor: { rgb: 'E8F5E9' } } };
    const sB = { font: { sz: 11, name: 'Calibri', bold: true }, border: borders, alignment: { vertical: 'center' } };

    const headers = ['Позиция', 'Кол-во'];
    tender.value.offers.forEach(o => headers.push(o.supplier || '(?)'));

    const rows = [];
    // Позиции
    for (let ii = 0; ii < tender.value.items.length; ii++) {
      const item = tender.value.items[ii];
      const row = [
        { v: item.name, s: sC },
        { v: item.quantity ? `${item.quantity} ${item.unit || ''}`.trim() : '', s: sC },
      ];
      for (const o of tender.value.offers) {
        const p = o.prices[ii];
        const cheap = isCheapest(ii, p);
        row.push({ v: p > 0 ? p : '', t: p > 0 ? 'n' : 's', s: cheap ? { ...sR, ...sG } : sR });
      }
      rows.push(row);
    }
    // Итого
    const totalRow = [{ v: 'ИТОГО', s: sB }, { v: '', s: sC }];
    for (const o of tender.value.offers) {
      const t = offerTotalWithQty(o);
      totalRow.push({ v: t > 0 ? t : '', t: t > 0 ? 'n' : 's', s: { ...sR, font: { ...sR.font, bold: true } } });
    }
    rows.push(totalRow);
    // Условия
    const dlvRow = [{ v: 'Срок поставки', s: sC }, { v: '', s: sC }];
    tender.value.offers.forEach(o => dlvRow.push({ v: o.delivery_days ? `${o.delivery_days} дн.` : '', s: sC }));
    rows.push(dlvRow);
    const payRow = [{ v: 'Условия оплаты', s: sC }, { v: '', s: sC }];
    tender.value.offers.forEach(o => payRow.push({ v: o.payment_terms || '', s: sC }));
    rows.push(payRow);

    const ws = XLSX.utils.aoa_to_sheet([headers.map(h => ({ v: h, s: sH })), ...rows]);
    ws['!cols'] = [{ wch: 25 }, { wch: 12 }];
    tender.value.offers.forEach(() => ws['!cols'].push({ wch: 16 }));

    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Сравнение');

    // Лист сводки
    if (tender.value.winner_supplier || tender.value.summary) {
      const summaryData = [
        [{ v: 'Сводка тендера', s: sH }],
        [{ v: `Тендер: ${tender.value.name}`, s: sC }],
        [{ v: `Победитель: ${tender.value.winner_supplier || '—'}`, s: sC }],
        [{ v: `Экономия: ${savings.value > 0 ? formatPrice(savings.value) : '—'}`, s: sC }],
        [{ v: '', s: sC }],
        [{ v: 'Обоснование:', s: sB }],
        [{ v: tender.value.summary || '—', s: sC }],
      ];
      const ws2 = XLSX.utils.aoa_to_sheet(summaryData);
      ws2['!cols'] = [{ wch: 60 }];
      XLSX.utils.book_append_sheet(wb, ws2, 'Сводка');
    }

    XLSX.writeFile(wb, `Тендер_${tender.value.name.replace(/[^\wа-яА-Я]/g, '_')}.xlsx`);
  } catch (err) {
    toast.error('Ошибка экспорта', err.message);
  }
}

onMounted(() => { loadTender(); });
</script>

<style scoped>
.tender-detail { padding: 0; }

/* ═══ Шапка ═══ */
.td-header { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
.td-header-left { display:flex; align-items:center; gap:12px; flex:1; min-width:0; flex-wrap:wrap; }
.td-header-right { display:flex; gap:8px; flex-shrink:0; }
.td-back-link { display:inline-flex; align-items:center; gap:5px; font-size:13px; color:var(--text-muted); text-decoration:none; font-weight:500; cursor:pointer; transition:color .15s; }
.td-back-link:hover { color:var(--bk-brown); }
.td-title { font-size:22px; font-weight:800; color:var(--bk-brown); margin:0; cursor:pointer; transition:color .15s; }
.td-title:hover { color:var(--bk-orange); }
.td-title-input { font-size:22px; font-weight:800; color:var(--bk-brown); border:none; border-bottom:2px solid var(--bk-orange); outline:none; background:transparent; padding:0; font-family:inherit; }
.td-badge { padding:4px 12px; border-radius:20px; font-size:11px; font-weight:700; letter-spacing:0.3px; flex-shrink:0; }
.td-badge.st-draft { background:rgba(158,158,158,0.15); color:#757575; }
.td-badge.st-collecting { background:#DBEAFE; color:#1D4ED8; }
.td-badge.st-evaluation { background:#FEF3C7; color:#B45309; }
.td-badge.st-approval { background:rgba(156,39,176,0.12); color:#7B1FA2; }
.td-badge.st-completed { background:rgba(76,175,80,0.15); color:#2E7D32; }

/* Кнопки */
.td-btn { padding:8px 20px; border-radius:8px; font-size:13px; font-weight:600; border:none; cursor:pointer; font-family:inherit; transition:all .15s; }
.td-btn-primary { background:#D62300; color:white; }
.td-btn-primary:hover { background:#B91D00; }
.td-btn-primary:disabled { opacity:0.5; cursor:default; }
.td-btn-outline { background:white; border:1.5px solid #D4C4B0; color:var(--bk-brown); }
.td-btn-outline:hover { border-color:#8B7355; background:#FEFBF7; }

/* ═══ Табы-таблетки ═══ */
.td-tabs-bar { display:flex; gap:0; background:white; border-radius:12px; padding:4px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,0.06); }
.td-tab { flex:1; padding:10px 16px; text-align:center; font-size:13px; font-weight:600; color:var(--text-muted); border:none; background:none; cursor:pointer; border-radius:8px; transition:all .15s; font-family:inherit; }
.td-tab.active { background:var(--bk-brown, #502314); color:white; }
.td-tab:hover:not(.active) { background:rgba(80,35,20,0.05); }
.td-tab-count { display:inline-block; padding:1px 6px; border-radius:6px; font-size:10px; margin-left:4px; background:rgba(0,0,0,0.08); }
.td-tab.active .td-tab-count { background:rgba(255,255,255,0.3); }

/* ═══ Контент ═══ */
.td-content { animation: fadeIn .15s ease; }
@keyframes fadeIn { from { opacity:0; transform:translateY(4px); } to { opacity:1; transform:translateY(0); } }
.td-grid { display:flex; flex-direction:column; gap:16px; }
.td-card { background:white; border-radius:14px; padding:20px; box-shadow:0 1px 4px rgba(0,0,0,0.06); }
.td-card-title { font-size:14px; font-weight:700; color:var(--bk-brown, #502314); margin-bottom:16px; display:flex; align-items:center; justify-content:space-between; gap:8px; }
.td-empty { text-align:center; padding:24px; color:var(--text-muted); font-size:13px; }

/* ═══ Формы ═══ */
.form-group { margin-bottom:10px; }
.form-group label { display:block; font-size:11px; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.3px; }
.form-input { width:100%; padding:8px 12px; border:1.5px solid #D4C4B0; border-radius:8px; font-size:13px; background:white; box-sizing:border-box; font-family:inherit; color:var(--text); }
.form-input:focus { border-color:#D62300; outline:none; box-shadow:0 0 0 3px rgba(214,35,0,0.1); }
.form-input:disabled { opacity:0.6; cursor:default; }
.td-form-row { display:flex; gap:12px; }
.td-form-row > .form-group { flex:1; min-width:0; }

/* ═══ Таблица позиций ═══ */
.td-items-table-wrap { overflow-x:auto; }
.td-items-table { width:100%; border-collapse:collapse; font-size:13px; }
.td-items-table th { font-size:10px; font-weight:700; color:var(--text-muted); text-align:left; padding:6px 8px; border-bottom:2px solid #E8E0D6; text-transform:uppercase; letter-spacing:0.3px; }
.td-items-table td { padding:4px 6px; border-bottom:1px solid #F0EBE4; }
.td-cell-input { width:100%; padding:6px 8px; border:1px solid transparent; border-radius:6px; font-size:12px; background:transparent; font-family:inherit; box-sizing:border-box; transition:all .15s; }
.td-cell-input:hover:not(:disabled) { border-color:#D4C4B0; }
.td-cell-input:focus { border-color:#D62300; outline:none; background:white; }
.td-cell-input:disabled { color:var(--text); }

.remove-btn { background:none; border:1px solid #E8E0D6; border-radius:6px; width:26px; height:26px; cursor:pointer; font-size:16px; color:var(--text-muted); display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:all .15s; }
.remove-btn:hover { background:#FFF0F0; border-color:#E57373; color:#C62828; }

/* ═══ Предложения ═══ */
.td-offers-header { margin-bottom:14px; }
.td-offers-list { display:flex; flex-direction:column; gap:14px; }
.td-offer-card { background:white; border-radius:14px; padding:20px; box-shadow:0 1px 4px rgba(0,0,0,0.06); }
.td-offer-header { display:flex; gap:8px; align-items:center; margin-bottom:12px; }
.td-offer-supplier-input { flex:1; font-size:16px; font-weight:700; color:var(--bk-brown, #502314); border:none; border-bottom:2px solid #E8E0D6; padding:4px 0; background:transparent; font-family:inherit; }
.td-offer-supplier-input:focus { border-bottom-color:#D62300; outline:none; }
.td-offer-supplier-input:disabled { border-bottom-color:transparent; color:var(--text); }
.td-offer-conditions { display:flex; gap:10px; flex-wrap:wrap; }
.td-offer-conditions .form-group { flex:1; min-width:140px; }
.td-offer-prices { margin-top:14px; }
.td-offer-prices-title { font-size:11px; font-weight:700; color:var(--text-muted); margin-bottom:6px; text-transform:uppercase; letter-spacing:0.3px; }
.td-prices-table { width:100%; border-collapse:collapse; font-size:12px; }
.td-prices-table th { font-size:10px; font-weight:700; color:var(--text-muted); text-align:left; padding:6px 8px; border-bottom:1.5px solid #D4C4B0; }
.td-prices-table td { padding:5px 8px; border-bottom:1px solid #F0EBE4; }
.td-price-item-name { font-size:12px; color:var(--text-muted); }
.price-cell { text-align:right !important; }
.price-cell.cheapest { border-color:#4CAF50 !important; background:rgba(76,175,80,0.06); }
.td-sum-cell { text-align:right; font-family:'JetBrains Mono',monospace; font-size:11px; color:var(--text-muted); }
.td-prices-total td { border-top:2px solid #D4C4B0; padding-top:8px; }

/* ═══ Файлы КП ═══ */
.td-files-list { display:flex; flex-direction:column; gap:4px; margin-bottom:8px; }
.td-file-item { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:6px 10px; background:#FEFBF7; border-radius:6px; }
.td-file-link { display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:500; color:var(--bk-brown, #502314); text-decoration:none; min-width:0; }
.td-file-link:hover { color:#D62300; }
.td-file-icon { flex-shrink:0; }
.td-files-empty { font-size:11px; color:var(--text-muted); margin-bottom:6px; }
.td-file-upload-btn { display:inline-flex; align-items:center; gap:4px; padding:5px 12px; border-radius:6px; border:1.5px dashed #D4C4B0; font-size:11px; font-weight:600; color:var(--text-muted); cursor:pointer; transition:all .15s; }
.td-file-upload-btn:hover { border-color:var(--bk-orange); color:var(--bk-brown); background:#FEFBF7; }

/* ═══ Layout сравнения: таблица + сайдбар ═══ */
.compare-layout { display:grid; grid-template-columns:1fr 300px; gap:20px; align-items:start; }
.compare-main { min-width:0; }

/* Таблица сравнения */
.comparison-wrap { overflow-x:auto; }
.comp-table { width:100%; border-collapse:separate; border-spacing:0; font-size:13px; }
.comp-table th { background:var(--bk-brown, #502314); color:white; padding:12px 14px; font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; }
.comp-table th:first-child { border-radius:10px 0 0 0; }
.comp-table th:last-child { border-radius:0 10px 0 0; }
.comp-table th.winner { background:#2E7D32; }
.comp-table td { padding:10px 14px; border-bottom:1px solid #F0EBE4; text-align:center; }
.comp-table tbody tr:hover { background:#FEFBF7; }
.comp-fixed-col { text-align:left !important; min-width:140px; font-weight:500; }
.comp-qty-col { width:80px; color:var(--text-muted); font-size:12px; }
.comp-sup-col { min-width:110px; }
.comp-price { font-family:'JetBrains Mono',monospace; font-weight:600; font-size:13px; }
.comp-sum { display:block; font-size:10px; color:var(--text-muted); font-weight:400; margin-top:2px; }
td.cheapest { background:#E8F5E9; }
td.cheapest .comp-price { color:#2E7D32; }
td.expensive { background:#FFF5F5; }
td.expensive .comp-price { color:#C62828; }
td.winner { background:rgba(76,175,80,0.04); }
td.cheapest-total { background:#C8E6C9; color:#1B5E20; }
td.cheapest-total strong { color:#1B5E20; }
td.best-term { color:#1565C0; font-weight:600; background:#E3F2FD; }
.comp-total-row td { border-top:2px solid var(--bk-brown, #502314); padding:12px 14px; font-weight:700; }
.comp-extra-row td { font-size:11px; color:var(--text-muted); border-bottom:1px dashed #E8E0D6; padding:8px 14px; }

/* ═══ Боковая панель ═══ */
.compare-sidebar { background:white; border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,0.06); border-left:1px solid #E8E0D6; padding:20px; position:sticky; top:16px; display:flex; flex-direction:column; gap:18px; max-height:calc(100vh - 120px); overflow-y:auto; }
.side-section { }
.side-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; color:var(--text-muted); margin-bottom:8px; }

/* Карточки выбора победителя */
.winner-cards { display:flex; flex-direction:column; gap:6px; }
.wcard { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border-radius:10px; border:2px solid #E8E0D6; cursor:pointer; transition:all .15s; }
.wcard:hover { border-color:#8B7355; }
.wcard.active { border-color:#4CAF50; background:#F0FDF4; }
.wcard-name { font-size:13px; font-weight:600; color:var(--bk-brown, #502314); }
.wcard-total { font-size:12px; font-weight:700; font-family:'JetBrains Mono',monospace; color:var(--text-muted); margin-top:2px; }
.wcard.active .wcard-total { color:#2E7D32; }
.wcard-check { width:22px; height:22px; border-radius:50%; border:2px solid #D4C4B0; display:flex; align-items:center; justify-content:center; font-size:12px; flex-shrink:0; transition:all .15s; }
.wcard.active .wcard-check { background:#4CAF50; border-color:#4CAF50; color:white; }

/* Экономия */
.saving-card { background:linear-gradient(135deg,#E8F5E9,#C8E6C9); border-radius:10px; padding:14px 16px; }
.saving-amount { font-size:22px; font-weight:800; color:#1B5E20; display:inline; }
.saving-pct { font-size:14px; font-weight:700; color:#2E7D32; margin-left:6px; }
.saving-sub { font-size:11px; color:#2E7D32; margin-top:4px; }

/* Условия победителя */
.cond-list { display:flex; flex-direction:column; gap:8px; }
.cond-item { display:flex; align-items:center; gap:10px; }
.cond-icon { width:30px; height:30px; border-radius:8px; background:#F0EBE4; display:flex; align-items:center; justify-content:center; flex-shrink:0; color:var(--text-muted); }
.cond-text { font-size:12px; font-weight:500; color:var(--bk-brown, #502314); }
.cond-note { font-size:10px; color:var(--text-muted); }

/* Статус и текст */
.side-select { width:100%; padding:8px 12px; border:1.5px solid #D4C4B0; border-radius:8px; font-size:12px; font-family:inherit; background:white; color:var(--text); }
.side-select:focus { border-color:#D62300; outline:none; }
.side-select:disabled { opacity:0.6; }
.side-textarea { width:100%; padding:10px 12px; border:1.5px solid #D4C4B0; border-radius:8px; font-size:12px; resize:vertical; font-family:inherit; min-height:80px; color:var(--text); }
.side-textarea:focus { border-color:#D62300; outline:none; box-shadow:0 0 0 3px rgba(214,35,0,0.1); }
.side-textarea:disabled { opacity:0.6; }
.side-meta { font-size:11px; color:var(--text-muted); padding-top:10px; border-top:1px solid #E8E0D6; }

/* ═══ Мобильная адаптация ═══ */
@media (max-width: 960px) {
  .compare-layout { grid-template-columns:1fr; }
  .compare-sidebar { position:static; max-height:none; border-left:none; border-top:1px solid #E8E0D6; }
}

@media (max-width: 768px) {
  .td-header { flex-direction:column; align-items:stretch; }
  .td-header-right { justify-content:flex-end; }
  .td-form-row { flex-direction:column; gap:0; }
  .td-offer-conditions { flex-direction:column; gap:0; }
  .td-offer-conditions .form-group { min-width:0; }
  .td-tab { font-size:12px; }
}

@media (max-width: 480px) {
  .td-title { font-size:18px; }
  .td-tabs-bar { overflow-x:auto; border-radius:10px; padding:3px; }
  .td-tab { padding:8px 12px; font-size:11px; flex-shrink:0; border-radius:6px; }
  .td-card, .td-offer-card { padding:14px; border-radius:10px; }
  .compare-sidebar { padding:14px; border-radius:10px; }
  .comp-fixed-col { min-width:100px; }
  .wcard { padding:8px 12px; }
}
</style>
