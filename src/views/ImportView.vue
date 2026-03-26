<template>
  <div class="imp">
    <h1 class="page-title">Импорт данных</h1>

    <div class="imp-grid">
      <div class="imp-card" v-for="item in imports" :key="item.key">
        <div class="imp-card-icon">{{ item.icon }}</div>
        <div class="imp-card-info">
          <div class="imp-card-title">{{ item.title }}</div>
          <div class="imp-card-desc">{{ item.desc }}</div>
          <div v-if="item.lastUpdate" class="imp-card-updated">Обн. {{ item.lastUpdate }}</div>
        </div>
        <div class="imp-card-action">
          <button class="imp-btn" @click="item.action" :disabled="uploading === item.key">
            {{ uploading === item.key ? 'Загрузка...' : 'Загрузить' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { db } from '@/lib/apiClient.js'
import { useOrderStore } from '@/stores/orderStore.js'
import { useToastStore } from '@/stores/toastStore.js'
import { useUserStore } from '@/stores/userStore.js'

const orderStore = useOrderStore()
const toast = useToastStore()
const userStore = useUserStore()
const uploading = ref(null)
const lastUpdates = ref({})

const imports = computed(() => [
  {
    key: 'analysis', icon: '📊', title: 'Анализ запасов',
    desc: `Остатки и расход (${orderStore.settings.legalEntity || 'выберите юрлицо'})`,
    lastUpdate: lastUpdates.value.analysis,
    action: () => pickFile('analysis'),
  },
  {
    key: 'sales', icon: '🍽', title: 'Реализация ресторанов',
    desc: 'Данные продаж из Qlik / 1С',
    lastUpdate: lastUpdates.value.sales,
    action: () => pickFile('sales'),
  },
  {
    key: 'shelf', icon: '📅', title: 'Сроки годности',
    desc: 'Остатки со сроками из Маллинг',
    lastUpdate: lastUpdates.value.shelf,
    action: () => pickFile('shelf'),
  },
])

function pickFile(type) {
  const input = document.createElement('input')
  input.type = 'file'
  input.accept = '.xlsx,.xls'
  input.onchange = (e) => {
    const file = e.target.files?.[0]
    if (file) uploadFile(type, file)
  }
  input.click()
}

async function uploadFile(type, file) {
  uploading.value = type
  try {
    // Читаем файл и парсим на фронте, затем вызываем тот же RPC что и модули
    const XLSX = await import('xlsx-js-style')
    const data = await file.arrayBuffer()
    const wb = XLSX.read(data, { cellDates: true })
    const ws = wb.Sheets[wb.SheetNames[0]]
    const rows = XLSX.utils.sheet_to_json(ws, { header: 1 })

    if (type === 'analysis') {
      // Переходим на страницу анализа — там свой импорт
      toast.show('Используйте импорт на странице «Анализ запасов»')
    } else if (type === 'sales') {
      // Парсим и отправляем через RPC
      const items = parseSalesRows(rows)
      if (!items.length) { toast.error('Не распознано', 'Нужны колонки: Группа аналогов, Дата'); return }
      const batchSize = 10000
      for (let i = 0; i < items.length; i += batchSize) {
        await db.rpc('replace_restaurant_sales', { items: items.slice(i, i + batchSize), notify: i + batchSize >= items.length })
      }
      toast.success('Загружено', `${items.length} записей реализации`)
    } else if (type === 'shelf') {
      // Парсим и отправляем
      const items = parseShelfRows(rows)
      if (!items.length) { toast.error('Не распознано', 'Нужны колонки: Наименование, Годен до'); return }
      await db.rpc('replace_stock_malling', { items })
      toast.success('Загружено', `${items.length} записей сроков годности`)
    }
    await loadLastUpdates()
  } catch (e) {
    toast.error('Ошибка', e.message || 'Не удалось загрузить')
  } finally { uploading.value = null }
}

function parseSalesRows(rows) {
  // Ищем заголовок
  let headerIdx = -1, cols = {}
  for (let i = 0; i < Math.min(rows.length, 20); i++) {
    const cells = (rows[i] || []).map(c => String(c ?? '').toLowerCase())
    for (let ci = 0; ci < cells.length; ci++) {
      if (cells[ci].includes('группа') && cells[ci].includes('аналог')) cols.group = ci
      if (cells[ci] === 'дата' || cells[ci] === 'date') cols.date = ci
      if (cells[ci].includes('продажи') || cells[ci].includes('расход') || cells[ci].includes('количество')) cols.qty = ci
      if (cells[ci].includes('мест хранения') || cells[ci].includes('ресторан')) cols.rest = ci
    }
    if (cols.group !== undefined && cols.date !== undefined) { headerIdx = i; break }
  }
  if (headerIdx < 0) return []
  const items = []
  for (let i = headerIdx + 1; i < rows.length; i++) {
    const r = rows[i] || []
    const group = String(r[cols.group] ?? '').trim()
    const dateRaw = r[cols.date]
    const qty = parseFloat(r[cols.qty] ?? 0) || 0
    const rc = parseInt(r[cols.rest] ?? 0) || 0
    if (!group || !dateRaw) continue
    let date = ''
    if (dateRaw instanceof Date) date = dateRaw.toISOString().slice(0, 10)
    else { const s = String(dateRaw); const m = s.match(/(\d{2})\.(\d{2})\.(\d{4})/); date = m ? `${m[3]}-${m[2]}-${m[1]}` : s.slice(0, 10) }
    if (!date) continue
    items.push({ sale_date: date, analog_group: group, quantity: qty, restaurant_count: rc })
  }
  return items
}

function parseShelfRows(rows) {
  // Аналогично ShelfLifeView парсингу
  const keywords = ['заказчик', 'склад', 'наименование', 'годен', 'дата производства', 'блокировк', 'остаток', 'статус']
  let headerIdx = -1
  for (let i = 0; i < Math.min(rows.length, 15); i++) {
    const cells = (rows[i] || []).map(c => String(c ?? '').toLowerCase().trim())
    if (cells.filter(c => c && keywords.some(kw => c.includes(kw))).length >= 3) { headerIdx = i; break }
  }
  if (headerIdx < 0) return []
  const headers = (rows[headerIdx] || []).map(h => String(h ?? '').toLowerCase().trim())
  const find = (kws) => { for (const kw of kws) { const i = headers.findIndex(h => h.includes(kw)); if (i >= 0) return i } return -1 }
  const cm = {
    customer: find(['заказчик', 'покупатель']),
    warehouse: find(['название склада', 'склад хранения', 'склад']),
    product_name: find(['наименование товара', 'наименование номенклатуры', 'номенклатура']),
    production_date: find(['дата производства', 'дата выработки']),
    expiry_date: find(['годен до', 'срок годности']),
    block_reason: find(['причина блокировк', 'блокировк']),
    expiry_status: find(['статус годности', 'статус годн']),
    quantity: find(['остатки', 'остаток', 'количество', 'кол-во']),
  }
  if (cm.product_name < 0) return []
  const parseDate = (v) => {
    if (!v) return null
    if (v instanceof Date) return isNaN(v.getTime()) ? null : v.toISOString().slice(0, 10)
    const s = String(v).trim()
    const m = s.match(/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/)
    if (m) return `${m[3]}-${m[2].padStart(2,'0')}-${m[1].padStart(2,'0')}`
    const m2 = s.match(/^(\d{4})-(\d{2})-(\d{2})/)
    if (m2) return s.slice(0, 10)
    return null
  }
  const items = []
  const userName = userStore.currentUser?.name || 'import'
  const now = new Date().toISOString().slice(0, 19).replace('T', ' ')
  for (let i = headerIdx + 1; i < rows.length; i++) {
    const r = rows[i] || []
    const name = String(r[cm.product_name] ?? '').trim()
    if (!name) continue
    items.push({
      customer: cm.customer >= 0 ? String(r[cm.customer] ?? '').trim() : '',
      warehouse: cm.warehouse >= 0 ? String(r[cm.warehouse] ?? '').trim() : '',
      product_name: name,
      production_date: cm.production_date >= 0 ? parseDate(r[cm.production_date]) : null,
      expiry_date: cm.expiry_date >= 0 ? parseDate(r[cm.expiry_date]) : null,
      block_reason: cm.block_reason >= 0 ? (String(r[cm.block_reason] ?? '').trim() || null) : null,
      expiry_status: cm.expiry_status >= 0 ? (String(r[cm.expiry_status] ?? '').trim() || null) : null,
      quantity: cm.quantity >= 0 ? (parseFloat(r[cm.quantity]) || 0) : 0,
      uploaded_at: now,
      uploaded_by: userName,
    })
  }
  return items
}

async function loadLastUpdates() {
  try {
    const [a, s, sh] = await Promise.all([
      db.from('analysis_data').select('updated_at').order('updated_at', { ascending: false }).limit(1),
      db.from('restaurant_sales').select('created_at').order('created_at', { ascending: false }).limit(1),
      db.from('stock_malling').select('uploaded_at').order('uploaded_at', { ascending: false }).limit(1),
    ])
    const fmt = (d) => d ? new Date(d).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : null
    lastUpdates.value = {
      analysis: fmt(a.data?.[0]?.updated_at),
      sales: fmt(s.data?.[0]?.created_at),
      shelf: fmt(sh.data?.[0]?.uploaded_at),
    }
  } catch {}
}

onMounted(loadLastUpdates)
</script>

<style scoped>
.imp { padding: 24px 32px; }
.imp-grid { display: flex; flex-direction: column; gap: 12px; max-width: 700px; }
.imp-card { display: flex; align-items: center; gap: 16px; background: var(--card); border: 1px solid var(--border-light); border-radius: 12px; padding: 16px 20px; }
.imp-card-icon { font-size: 28px; flex-shrink: 0; }
.imp-card-info { flex: 1; }
.imp-card-title { font-size: 15px; font-weight: 700; }
.imp-card-desc { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.imp-card-updated { font-size: 11px; color: var(--text-muted); margin-top: 4px; }
.imp-btn { padding: 8px 20px; background: var(--bk-brown); color: #fff; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; }
.imp-btn:hover { opacity: 0.9; }
.imp-btn:disabled { opacity: 0.5; cursor: default; }
</style>
