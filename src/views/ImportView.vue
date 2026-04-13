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
import { parseFile } from '@/lib/importStock.js'
import { parseStockMalling } from '@/lib/shelfLifeImport.js'
import { parseSalesFile } from '@/lib/salesImport.js'
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
    desc: `Данные продаж из Qlik / 1С (${orderStore.settings.legalEntity || 'выберите юрлицо'})`,
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
  if ((type === 'analysis' || type === 'sales') && !orderStore.settings.legalEntity) {
    toast.error('Не выбрано юрлицо', 'Выберите юр. лицо в боковом меню')
    return
  }
  const input = document.createElement('input')
  input.type = 'file'
  input.accept = type === 'analysis' ? '.xlsx,.xls,.csv,.tsv' : '.xlsx,.xls'
  input.onchange = (e) => {
    const file = e.target.files?.[0]
    if (file) uploadFile(type, file)
  }
  input.click()
}

function localNow() {
  const d = new Date()
  const pad = n => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
}

async function uploadFile(type, file) {
  uploading.value = type
  try {
    if (type === 'analysis') {
      // Идентично AnalysisView: parseFile → replace_analysis_data
      const le = orderStore.settings.legalEntity
      const parsed = await parseFile(file, le)
      if (!parsed.length) { toast.error('Не распознано', 'Не найдены артикулы, остатки или расход в файле'); return }
      const userName = userStore.currentUser?.name || 'import'
      const now = localNow()
      const items = parsed.filter(r => r.sku).map(r => ({
        id: `${le}_${r.sku}`,
        legal_entity: le,
        sku: r.sku,
        stock: r.stock || 0,
        consumption: r.consumption || 0,
        period_days: 30,
        updated_by: userName,
        updated_at: now,
      }))
      if (!items.length) { toast.error('Не распознано', 'Не найдены товары с артикулами'); return }
      const { error } = await db.rpc('replace_analysis_data', { legal_entity: le, items })
      if (error) throw new Error(error)
      toast.success('Загружено', `${items.length} позиций для «${le}»`)

    } else if (type === 'sales') {
      const le = orderStore.settings.legalEntity
      // Загружаем карту артикул→группа аналогов
      const { data: prods } = await db.from('products').select('sku, analog_group').neq('analog_group', '')
      const skuToGroup = {}
      if (prods) prods.forEach(p => { if (p.sku && p.analog_group) skuToGroup[p.sku] = p.analog_group })
      const result = await parseSalesFile(file, skuToGroup)
      const items = result.items || result
      const skuMapped = result.skuMapped || 0
      if (!items.length) { toast.error('Не распознано', 'Не удалось распознать данные'); return }
      toast.info('Загрузка', `Отправляю ${items.length.toLocaleString('ru')} записей в «${le}»…`)
      for (let i = 0; i < items.length; i += 10000) {
        const isLast = i + 10000 >= items.length
        const { error } = await db.rpc('replace_restaurant_sales', { items: items.slice(i, i + 10000), notify: isLast, legal_entity: le })
        if (error) { toast.error('Ошибка', error); return }
      }
      toast.success('Загружено', `${items.length.toLocaleString('ru')} записей реализации в «${le}»` + (skuMapped ? `, ${skuMapped} по артикулу` : ''))

    } else if (type === 'shelf') {
      // Идентично ShelfLifeView: parseStockMalling → replace_stock_malling
      const items = await parseStockMalling(file)
      if (!items.length) { toast.error('Не распознано', 'Не удалось распознать данные в файле'); return }
      const userName = userStore.currentUser?.name || ''
      const now = localNow()
      const payload = items.map(item => ({ ...item, uploaded_at: now, uploaded_by: userName }))
      const { data, error } = await db.rpc('replace_stock_malling', { items: payload })
      if (error) throw new Error(error)
      toast.success('Загружено', `${data?.count || items.length} позиций сроков годности`)
    }

    await loadLastUpdates()
  } catch (e) {
    toast.error('Ошибка', e.message || 'Не удалось загрузить')
  } finally { uploading.value = null }
}

async function loadLastUpdates() {
  try {
    const le = orderStore.settings.legalEntity
    const analysisQuery = db.from('analysis_data').select('updated_at').order('updated_at', { ascending: false }).limit(1)
    const salesQuery = le
      ? db.from('restaurant_sales').select('created_at').eq('legal_entity', le).order('created_at', { ascending: false }).limit(1)
      : db.from('restaurant_sales').select('created_at').order('created_at', { ascending: false }).limit(1)
    const [a, s, sh] = await Promise.all([
      analysisQuery,
      salesQuery,
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
