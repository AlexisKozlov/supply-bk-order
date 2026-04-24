<template>
  <div class="scn">
    <div class="scn-header">
      <h2 class="scn-title">
        Сканер товаров
        <span class="scn-beta">BETA</span>
      </h2>
      <p class="scn-sub">Наведите камеру на штрихкод товара — покажу артикул, остаток на складе и аналоги.</p>
    </div>

    <div v-if="!result" class="scn-scanner-wrap">
      <BarcodeScanner ref="scannerRef" @detected="onDetected" />
    </div>

    <div v-if="loading" class="scn-loading">Ищу товар…</div>

    <div v-if="result && !loading" class="scn-result">
      <div v-if="!result.found" class="scn-notfound">
        <div class="scn-notfound-icon">
          <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.3-4.3"/><path d="M8 11h6"/>
          </svg>
        </div>
        <div class="scn-notfound-title">Товар не найден</div>
        <div class="scn-notfound-code">Штрихкод: <b>{{ result.gtin }}</b></div>

        <div v-if="!reported" class="scn-report-form">
          <p class="scn-report-hint">
            Заполните, пожалуйста, что это за товар — это поможет администратору завести его в базу.
          </p>

          <label class="scn-field">
            <span class="scn-field-label">Название товара <span class="scn-req">*</span></span>
            <input
              type="text"
              v-model="reportName"
              class="scn-input"
              placeholder="Например: Молоко Пармалат 1л"
              maxlength="500"
            />
          </label>

          <label class="scn-field">
            <span class="scn-field-label">Комментарий (необязательно)</span>
            <textarea
              v-model="reportComment"
              class="scn-input scn-textarea"
              placeholder="Что-то ещё важное — поставщик, вес, марка"
              maxlength="1000"
              rows="2"
            ></textarea>
          </label>

          <div class="scn-field">
            <span class="scn-field-label">Фото товара</span>
            <div class="scn-photo-block">
              <label v-if="!reportPhotoPreview" class="scn-photo-btn">
                <input
                  type="file"
                  accept="image/*"
                  capture="environment"
                  @change="onPhotoSelected"
                  class="scn-photo-input"
                />
                📷 Добавить фото
              </label>
              <div v-else class="scn-photo-preview">
                <img :src="reportPhotoPreview" alt="фото товара" />
                <button class="scn-photo-remove" type="button" @click="removePhoto" title="Удалить фото">✕</button>
              </div>
              <div v-if="reportPhotoError" class="scn-photo-error">{{ reportPhotoError }}</div>
            </div>
          </div>

          <div class="scn-notfound-actions">
            <button
              class="scn-btn primary"
              @click="reportMissing"
              :disabled="reporting || !reportName.trim()"
            >
              {{ reporting ? 'Отправляем…' : 'Сообщить администратору' }}
            </button>
            <div v-if="reportError" class="scn-report-error">
              Не удалось отправить: {{ reportError }}
              <button class="scn-link" @click="reportMissing" :disabled="reporting">Повторить</button>
            </div>
            <button class="scn-btn ghost" @click="resetScanner">Сканировать снова</button>
          </div>
        </div>

        <div v-else class="scn-notfound-actions">
          <div class="scn-report-ok-big">Отправлено ✓</div>
          <div v-if="reportInfo" class="scn-report-ok">{{ reportInfo }}</div>
          <button class="scn-btn primary" @click="resetScanner">Сканировать снова</button>
        </div>
      </div>

      <div v-else class="scn-card">
        <div class="scn-card-head">
          <div class="scn-card-cat" v-if="result.product.category">{{ result.product.category }}</div>
          <div class="scn-card-name">{{ result.product.name }}</div>
          <div class="scn-card-meta">
            <span class="scn-meta-row"><span class="lbl">Артикул:</span> <b>{{ result.product.sku }}</b></span>
            <span class="scn-meta-row"><span class="lbl">Штрихкод:</span> {{ result.product.gtin }}</span>
            <span v-if="result.product.unit_of_measure" class="scn-meta-row"><span class="lbl">Ед. изм.:</span> {{ result.product.unit_of_measure }}</span>
            <span v-if="result.product.qty_per_box" class="scn-meta-row"><span class="lbl">В упаковке:</span> {{ result.product.qty_per_box }} {{ result.product.unit_of_measure || 'шт' }}</span>
            <span v-if="result.product.multiplicity" class="scn-meta-row"><span class="lbl">Кратность заказа:</span> {{ result.product.multiplicity }}</span>
            <span v-if="result.product.supplier" class="scn-meta-row"><span class="lbl">Поставщик:</span> {{ result.product.supplier }}</span>
          </div>
        </div>

        <div class="scn-stock">
          <div class="scn-stock-info">
            <div class="scn-stock-label">Остаток на складе закупки</div>
            <div v-if="stockDate(result.product.stock_warehouse)" class="scn-stock-date">
              на {{ stockDate(result.product.stock_warehouse) }}
              <span class="scn-stock-src">{{ stockSourceLabel(result.product.stock_warehouse) }}</span>
            </div>
          </div>
          <div class="scn-stock-value" :class="stockClass(result.product.stock_warehouse)">
            {{ formatStock(result.product.stock_warehouse, result.product.multiplicity) }}
          </div>
        </div>

        <div v-if="result.product.stock_warehouse?.nearest_expiry" class="scn-expiry">
          <div class="scn-expiry-row">
            <span class="scn-expiry-label">Ближайший срок годности:</span>
            <span class="scn-expiry-date" :class="expiryClass(result.product.stock_warehouse.expiry_status)">
              {{ formatDateRu(result.product.stock_warehouse.nearest_expiry) }}
              <span v-if="result.product.stock_warehouse.expiry_status" class="scn-expiry-status">· {{ result.product.stock_warehouse.expiry_status }}</span>
            </span>
          </div>
          <button v-if="result.product.stock_warehouse.batches && result.product.stock_warehouse.batches.length > 1"
                  class="scn-expiry-toggle" @click="batchesOpen = !batchesOpen">
            {{ batchesOpen ? 'Скрыть' : `Все партии (${result.product.stock_warehouse.batches.length})` }}
          </button>
          <div v-if="batchesOpen && result.product.stock_warehouse.batches" class="scn-batches">
            <div v-for="(b, idx) in result.product.stock_warehouse.batches" :key="idx" class="scn-batch">
              <div class="scn-batch-main">
                <span class="scn-batch-qty">{{ b.qty }} {{ unitLabel(result.product.multiplicity) }}</span>
                <span class="scn-batch-exp" :class="expiryClass(b.status)">до {{ formatDateRu(b.expiry) }}</span>
              </div>
              <div class="scn-batch-meta">
                <span v-if="b.warehouse">{{ b.warehouse }}</span>
                <span v-if="b.status" class="scn-batch-status" :class="expiryClass(b.status)">{{ b.status }}</span>
              </div>
            </div>
          </div>
        </div>

        <div v-if="result.analogs && result.analogs.length > 1" class="scn-analogs">
          <div class="scn-analogs-title">Аналоги ({{ result.analogs.length - 1 }})</div>
          <div class="scn-analogs-list">
            <div v-for="a in result.analogs" :key="a.sku" class="scn-analog" :class="{ main: a.is_main, inactive: a.is_active === 0 }">
              <div class="scn-analog-main">
                <span v-if="a.is_main" class="scn-tag">Этот товар</span>
                <span v-if="a.is_active === 0" class="scn-tag inactive">Снят с ассортимента</span>
                <span class="scn-analog-name">{{ a.name }}</span>
              </div>
              <div class="scn-analog-meta">
                <span class="scn-analog-sku">{{ a.sku }}</span>
                <span v-if="a.is_active === 0" class="scn-analog-stock unknown">недоступен для заказа</span>
                <span v-else class="scn-analog-stock" :class="stockClass(a.stock_warehouse)">
                  {{ formatStock(a.stock_warehouse, a.multiplicity) }}<span v-if="stockDate(a.stock_warehouse)" class="scn-analog-date"> · {{ stockDate(a.stock_warehouse) }}</span>
                </span>
              </div>
              <div v-if="a.is_active !== 0 && a.stock_warehouse?.nearest_expiry" class="scn-analog-exp" :class="expiryClass(a.stock_warehouse.expiry_status)">
                {{ nearestExpiryShort(a.stock_warehouse) }}
              </div>
            </div>
          </div>
        </div>
        <div v-else class="scn-noanalogs">Аналоги не указаны</div>

        <div v-if="result.multiple_matches && result.multiple_matches.length" class="scn-multi">
          Найдено несколько товаров с этим кодом — показан первый.
          <ul>
            <li v-for="m in result.multiple_matches" :key="m.sku">{{ m.sku }} — {{ m.name }} ({{ m.legal_entity }})</li>
          </ul>
        </div>

        <div class="scn-actions">
          <button class="scn-btn primary" @click="resetScanner">Сканировать ещё</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, nextTick } from 'vue';
import BarcodeScanner from '@/components/restaurant/BarcodeScanner.vue';
import { useRestaurantOrderStore } from '@/stores/restaurantOrderStore.js';

const roStore = useRestaurantOrderStore();

const scannerRef = ref(null);
const result = ref(null);
const loading = ref(false);
const reporting = ref(false);
const reported = ref(false);
const reportError = ref('');
const reportInfo = ref('');
const batchesOpen = ref(false);

// Форма сообщения о ненайденном товаре
const reportName = ref('');
const reportComment = ref('');
const reportPhoto = ref(null);
const reportPhotoPreview = ref('');
const reportPhotoError = ref('');

function onPhotoSelected(e) {
  reportPhotoError.value = '';
  const f = e.target.files && e.target.files[0];
  if (!f) return;
  const maxSize = 6 * 1024 * 1024;
  if (f.size > maxSize) {
    reportPhotoError.value = 'Файл слишком большой (максимум 6 МБ)';
    e.target.value = '';
    return;
  }
  const allowed = ['image/jpeg', 'image/png', 'image/webp'];
  if (!allowed.includes(f.type)) {
    reportPhotoError.value = 'Формат не поддерживается (JPEG, PNG или WebP)';
    e.target.value = '';
    return;
  }
  reportPhoto.value = f;
  reportPhotoPreview.value = URL.createObjectURL(f);
}

function removePhoto() {
  if (reportPhotoPreview.value) URL.revokeObjectURL(reportPhotoPreview.value);
  reportPhoto.value = null;
  reportPhotoPreview.value = '';
  reportPhotoError.value = '';
}

async function onDetected(code) {
  loading.value = true;
  try {
    const data = await roStore.scanProduct(code);
    result.value = data;
    // Останавливаем камеру после успешного распознавания
    scannerRef.value?.stopCamera?.();
  } catch (e) {
    result.value = { found: false, gtin: code, error: e.message || 'Ошибка поиска' };
  } finally {
    loading.value = false;
  }
}

function resetScanner() {
  result.value = null;
  reported.value = false;
  reporting.value = false;
  reportError.value = '';
  reportInfo.value = '';
  batchesOpen.value = false;
  reportName.value = '';
  reportComment.value = '';
  removePhoto();
  nextTick(() => {
    scannerRef.value?.resetLastCode?.();
    scannerRef.value?.startCamera?.();
  });
}

async function reportMissing() {
  if (!result.value || reporting.value || reported.value) return;
  if (!reportName.value.trim()) {
    reportError.value = 'Укажите название товара';
    return;
  }
  reporting.value = true;
  reportError.value = '';
  reportInfo.value = '';
  try {
    const data = await roStore.reportMissingGtin(result.value.gtin, {
      name: reportName.value.trim(),
      comment: reportComment.value.trim(),
      photo: reportPhoto.value,
    });
    reported.value = true;
    // Подскажем, как именно прошла отправка
    const parts = [];
    if (data?.db_saved) parts.push('сохранено в базе');
    if (data?.telegram_sent > 0) parts.push(`Telegram-уведомление: ${data.telegram_sent}/${data.telegram_total} админов`);
    else if (data?.telegram_total === 0) parts.push('у админов нет привязанного Telegram');
    reportInfo.value = parts.join(' · ');
  } catch (e) {
    reportError.value = e?.message || 'неизвестная ошибка';
  } finally {
    reporting.value = false;
  }
}

// Единица: «кор» для товаров с multiplicity = 1, иначе «шт»
function unitLabel(multiplicity) {
  return Number(multiplicity) === 1 ? 'кор' : 'шт';
}

function formatStock(value, multiplicity) {
  if (value === null || value === undefined) return 'нет данных';
  const qty = typeof value === 'object' ? value.qty : value;
  if (qty === null || qty === undefined) return 'нет данных';
  const num = Math.round(qty * 100) / 100;
  return `${num} ${unitLabel(multiplicity)}`;
}

function stockClass(value) {
  if (value === null || value === undefined) return 'unknown';
  const qty = typeof value === 'object' ? value.qty : value;
  if (qty === null || qty === undefined) return 'unknown';
  if (qty <= 0) return 'empty';
  if (qty < 10) return 'low';
  return 'ok';
}

function stockDate(value) {
  if (!value || typeof value !== 'object' || !value.date) return '';
  // YYYY-MM-DD → DD.MM
  const m = String(value.date).match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!m) return value.date;
  return `${m[3]}.${m[2]}`;
}

function stockSourceLabel(value) {
  if (!value || typeof value !== 'object') return '';
  if (value.source === 'shelf_life') return '· сроки годности';
  if (value.source === 'ro_balances') return '· остатки склада';
  return '';
}

function formatDateRu(d) {
  if (!d) return '';
  const m = String(d).match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!m) return d;
  return `${m[3]}.${m[2]}.${m[1]}`;
}

function expiryClass(status) {
  if (!status) return '';
  const s = String(status).toLowerCase();
  if (s.includes('истёк') || s.includes('истек') || s.includes('просроч')) return 'expired';
  if (s.includes('скоро') || s.includes('крит')) return 'soon';
  if (s.includes('годен')) return 'good';
  return '';
}

function nearestExpiryShort(value) {
  if (!value || typeof value !== 'object' || !value.nearest_expiry) return '';
  const m = String(value.nearest_expiry).match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!m) return value.nearest_expiry;
  return `до ${m[3]}.${m[2]}.${m[1].slice(2)}`;
}
</script>

<style scoped>
.scn { padding: 20px 16px 80px; max-width: 720px; margin: 0 auto; }
.scn-header { margin-bottom: 18px; }
.scn-title {
  font-size: 22px; font-weight: 700; margin: 0 0 6px; color: #2b1a0e;
  display: flex; align-items: center; gap: 10px;
}
.scn-beta {
  font-size: 10px; font-weight: 700; letter-spacing: 0.5px;
  background: linear-gradient(90deg, #FFD54F, #F4A261);
  color: #3d2400; padding: 3px 8px; border-radius: 6px;
}
.scn-sub { color: #6b5a4a; font-size: 13px; margin: 0; line-height: 1.5; }

.scn-scanner-wrap { margin-bottom: 16px; }

.scn-loading {
  text-align: center; padding: 40px; color: #6b5a4a; font-size: 14px;
}

.scn-result { animation: scn-fade 0.2s ease-out; }
@keyframes scn-fade { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }

.scn-card {
  background: #fff; border: 1px solid #e5dcd2; border-radius: 14px;
  padding: 18px; box-shadow: 0 2px 6px rgba(0,0,0,0.04);
}
.scn-card-head { padding-bottom: 14px; border-bottom: 1px solid #f0e8dd; }
.scn-card-cat {
  display: inline-block; font-size: 11px; padding: 3px 8px;
  background: #faf2eb; color: #6b4500; border-radius: 5px; margin-bottom: 8px;
  font-weight: 600;
}
.scn-card-name { font-size: 17px; font-weight: 700; color: #2b1a0e; margin-bottom: 10px; line-height: 1.35; }
.scn-card-meta { display: flex; flex-direction: column; gap: 4px; }
.scn-meta-row { font-size: 13px; color: #4a3a2a; }
.scn-meta-row .lbl { color: #8a7a6a; margin-right: 4px; }

.scn-stock {
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 0; border-bottom: 1px solid #f0e8dd;
}
.scn-stock-label { font-size: 13px; color: #6b5a4a; }
.scn-stock-date { font-size: 11px; color: #8a7a6a; margin-top: 2px; }
.scn-stock-src { color: #b8a890; }
.scn-analog-date { color: #8a7a6a; font-weight: 400; font-size: 11px; }
.scn-stock-value { font-size: 18px; font-weight: 700; }
.scn-stock-value.ok { color: #2e7d32; }
.scn-stock-value.low { color: #ef6c00; }
.scn-stock-value.empty { color: #c0392b; }
.scn-stock-value.unknown { color: #999; font-weight: 500; font-size: 14px; }

.scn-expiry {
  padding: 12px 0; border-bottom: 1px solid #f0e8dd;
}
.scn-expiry-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
.scn-expiry-label { font-size: 13px; color: #6b5a4a; }
.scn-expiry-date { font-size: 14px; font-weight: 600; color: #2b1a0e; }
.scn-expiry-date.good { color: #2e7d32; }
.scn-expiry-date.soon { color: #ef6c00; }
.scn-expiry-date.expired { color: #c0392b; }
.scn-expiry-status { font-weight: 500; font-size: 12px; opacity: 0.85; }

.scn-expiry-toggle {
  margin-top: 8px; background: transparent; border: none;
  color: #E76F51; font-size: 12px; cursor: pointer; padding: 4px 0;
  font-family: inherit; font-weight: 600;
}
.scn-expiry-toggle:hover { text-decoration: underline; }

.scn-batches { margin-top: 8px; display: flex; flex-direction: column; gap: 6px; }
.scn-batch {
  padding: 8px 10px; background: #faf6f0; border-radius: 6px;
  font-size: 12px;
}
.scn-batch-main { display: flex; justify-content: space-between; gap: 10px; }
.scn-batch-qty { font-weight: 600; color: #2b1a0e; }
.scn-batch-exp { font-weight: 600; }
.scn-batch-exp.good { color: #2e7d32; }
.scn-batch-exp.soon { color: #ef6c00; }
.scn-batch-exp.expired { color: #c0392b; }
.scn-batch-meta { display: flex; gap: 10px; margin-top: 3px; color: #8a7a6a; font-size: 11px; }
.scn-batch-status.good { color: #2e7d32; }
.scn-batch-status.soon { color: #ef6c00; }
.scn-batch-status.expired { color: #c0392b; }

.scn-analog-exp {
  font-size: 11px; margin-top: 3px; font-weight: 500;
}
.scn-analog-exp.good { color: #2e7d32; }
.scn-analog-exp.soon { color: #ef6c00; }
.scn-analog-exp.expired { color: #c0392b; }

.scn-analogs { padding-top: 14px; }
.scn-noanalogs { padding-top: 14px; color: #999; font-size: 13px; font-style: italic; }
.scn-analogs-title { font-size: 13px; font-weight: 600; color: #4a3a2a; margin-bottom: 10px; }
.scn-analogs-list { display: flex; flex-direction: column; gap: 8px; }
.scn-analog {
  padding: 10px 12px; background: #faf6f0; border-radius: 8px;
  display: flex; flex-direction: column; gap: 6px;
}
.scn-analog.main { background: #fff4e6; border: 1px solid #FFD54F; }
.scn-analog.inactive { opacity: 0.65; }
.scn-analog.inactive .scn-analog-name { text-decoration: line-through; color: #6b5a4a; }
.scn-analog-main { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.scn-analog-name { font-size: 14px; color: #2b1a0e; font-weight: 500; line-height: 1.3; }
.scn-tag {
  font-size: 10px; background: #E76F51; color: #fff;
  padding: 2px 6px; border-radius: 4px; font-weight: 600; letter-spacing: 0.3px;
}
.scn-tag.inactive { background: #9ca3af; }
.scn-analog-meta { display: flex; justify-content: space-between; font-size: 12px; }
.scn-analog-sku { color: #8a7a6a; font-family: 'Courier New', monospace; }
.scn-analog-stock { font-weight: 600; }
.scn-analog-stock.ok { color: #2e7d32; }
.scn-analog-stock.low { color: #ef6c00; }
.scn-analog-stock.empty { color: #c0392b; }
.scn-analog-stock.unknown { color: #999; font-weight: 400; }

.scn-multi {
  margin-top: 12px; padding: 10px 12px; font-size: 12px;
  background: #fff8e1; border-radius: 6px; color: #6b4500;
}
.scn-multi ul { margin: 6px 0 0; padding-left: 16px; }

.scn-actions { margin-top: 16px; display: flex; gap: 10px; }

.scn-notfound {
  background: #fff; border: 1px solid #e5dcd2; border-radius: 14px;
  padding: 28px 20px; text-align: center;
  display: flex; flex-direction: column; align-items: center; gap: 10px;
}
.scn-notfound-icon { color: #c0392b; opacity: 0.7; }
.scn-notfound-title { font-size: 17px; font-weight: 700; color: #2b1a0e; }
.scn-notfound-code { color: #6b5a4a; font-size: 14px; }
.scn-notfound-actions {
  display: flex; flex-direction: column; gap: 8px; margin-top: 8px; width: 100%; max-width: 320px;
}
.scn-report-error {
  font-size: 12px; color: #c0392b; background: #fee; padding: 8px 10px;
  border-radius: 6px; line-height: 1.4;
}
.scn-report-ok {
  font-size: 12px; color: #2e7d32; line-height: 1.4;
}
.scn-report-ok-big {
  font-size: 18px; color: #2e7d32; font-weight: 700;
  background: #e8f5e9; padding: 12px 16px; border-radius: 8px;
  text-align: center;
}

.scn-report-form {
  width: 100%; max-width: 360px; margin-top: 6px;
  display: flex; flex-direction: column; gap: 10px;
}
.scn-report-hint {
  font-size: 12px; color: #6b5a4a; line-height: 1.5; margin: 0 0 4px;
  text-align: left;
}
.scn-field { display: flex; flex-direction: column; gap: 4px; text-align: left; }
.scn-field-label { font-size: 12px; color: #4a3a2a; font-weight: 600; }
.scn-req { color: #c0392b; }
.scn-input {
  width: 100%; padding: 9px 12px; border: 1px solid #d5c8b8; border-radius: 8px;
  font-size: 14px; font-family: inherit; background: #fff; color: #2b1a0e;
  -webkit-appearance: none; box-sizing: border-box;
}
.scn-input:focus { outline: none; border-color: #E76F51; }
.scn-textarea { resize: vertical; min-height: 42px; }

.scn-photo-block { display: flex; flex-direction: column; gap: 6px; }
.scn-photo-btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  padding: 11px; border: 1px dashed #d5c8b8; border-radius: 8px;
  background: #fffaf3; color: #6b4500; font-size: 14px; font-weight: 600;
  cursor: pointer; transition: background 0.15s;
}
.scn-photo-btn:hover { background: #faf2eb; }
.scn-photo-input { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
.scn-photo-preview {
  position: relative; width: 100%; max-width: 240px; margin: 0 auto;
  border: 1px solid #e5dcd2; border-radius: 8px; overflow: hidden;
}
.scn-photo-preview img { width: 100%; display: block; }
.scn-photo-remove {
  position: absolute; top: 6px; right: 6px; width: 26px; height: 26px;
  border: none; border-radius: 50%; background: rgba(0,0,0,0.6); color: #fff;
  cursor: pointer; font-size: 13px; display: flex; align-items: center; justify-content: center;
}
.scn-photo-error { font-size: 12px; color: #c0392b; }
.scn-link {
  background: none; border: none; color: #c0392b; cursor: pointer;
  font-weight: 600; padding: 0; margin-left: 6px; font-family: inherit; font-size: 12px;
  text-decoration: underline;
}
.scn-link:disabled { opacity: 0.5; cursor: not-allowed; }

.scn-btn {
  border: none; padding: 11px 22px; border-radius: 8px; font-size: 14px;
  font-weight: 600; cursor: pointer;
  transition: opacity 0.15s, transform 0.05s;
}
.scn-btn:active { transform: translateY(1px); }
.scn-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.scn-btn.primary { background: #E76F51; color: #fff; }
.scn-btn.primary:hover:not(:disabled) { background: #d85d3f; }
.scn-btn.ghost { background: transparent; color: #502314; border: 1px solid #d5c8b8; }
.scn-btn.ghost:hover { background: #f5efe7; }

@media (max-width: 600px) {
  .scn { padding: 14px 12px 90px; }
  .scn-card { padding: 14px; }
  .scn-card-name { font-size: 16px; }
}
</style>

