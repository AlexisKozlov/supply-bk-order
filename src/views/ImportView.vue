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
            <BurgerSpinner v-if="uploading === item.key" size="xs" />
            <span>{{ uploading === item.key ? 'Загрузка...' : 'Загрузить' }}</span>
          </button>
        </div>
      </div>
    </div>

    <div v-if="cttResult" class="imp-result-card">
      <div class="imp-result-head">
        <div>
          <div class="imp-result-title">JSON для СТТ готов</div>
          <div class="imp-result-desc">{{ cttResult.fileName }} → {{ cttResult.downloadName }}</div>
        </div>
        <button class="imp-btn" @click="downloadCttJson">Скачать JSON</button>
      </div>

      <div class="imp-result-stats">
        <div class="imp-stat"><b>{{ cttResult.stats.parsed }}</b><span>строк в файле</span></div>
        <div class="imp-stat"><b>{{ cttResult.stats.converted }}</b><span>попало в JSON</span></div>
        <div class="imp-stat"><b>{{ cttResult.stats.unmatched }}</b><span>не распознано</span></div>
        <div class="imp-stat"><b>{{ cttResult.stats.missing_price }}</b><span>без цены</span></div>
      </div>

      <div v-if="cttResult.items.length" class="imp-preview-wrap">
        <div class="imp-preview-title">Первые строки JSON</div>
        <div class="imp-preview-table-wrap">
          <table class="imp-preview-table">
            <thead>
              <tr>
                <th>o</th>
                <th>r</th>
                <th>s</th>
                <th>g</th>
                <th>n</th>
                <th>q</th>
                <th>w</th>
                <th>p</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(item, idx) in cttPreviewItems" :key="idx">
                <td>{{ item.o }}</td>
                <td>{{ item.r }}</td>
                <td>{{ item.s }}</td>
                <td class="mono">{{ item.g }}</td>
                <td>{{ item.n }}</td>
                <td>{{ item.q }}</td>
                <td>{{ item.w }}</td>
                <td>{{ item.p }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="cttResult.unmatched.length" class="imp-unmatched">
        <div class="imp-preview-title">Не распознано</div>
        <div class="imp-unmatched-list">
          <div v-for="(row, idx) in cttUnmatchedPreview" :key="idx" class="imp-unmatched-row">
            <span class="mono">{{ row.sku || '—' }}</span>
            <span>{{ row.source_name || 'Без названия' }}</span>
            <span class="imp-unmatched-reason">{{ row.reason }}</span>
          </div>
        </div>
        <div v-if="cttResult.unmatched.length > cttUnmatchedPreview.length" class="imp-unmatched-more">
          Ещё {{ cttResult.unmatched.length - cttUnmatchedPreview.length }} строк не показано
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
import { parseCttPreorderXlsx, resolveCttPreorderRows, buildCttPreorderFilename, buildCttPreorderLabel } from '@/lib/cttJsonImport.js'
import { getEntityGroupCode } from '@/lib/legalEntities.js'
import { useOrderStore } from '@/stores/orderStore.js'
import { useToastStore } from '@/stores/toastStore.js'
import { useUserStore } from '@/stores/userStore.js'

const orderStore = useOrderStore()
const toast = useToastStore()
const userStore = useUserStore()
const uploading = ref(null)
const lastUpdates = ref({})
const cttResult = ref(null)

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
  {
    key: 'ctt-preorder', icon: '🧾', title: 'XLSX → JSON для СТТ',
    desc: `Преобразование предзаказа в data-*.json (${orderStore.settings.legalEntity || 'выберите юрлицо'})`,
    action: () => pickFile('ctt-preorder'),
  },
])

const cttPreviewItems = computed(() => (cttResult.value?.items || []).slice(0, 10))
const cttUnmatchedPreview = computed(() => (cttResult.value?.unmatched || []).slice(0, 10))

function pickFile(type) {
  if ((type === 'analysis' || type === 'sales' || type === 'ctt-preorder') && !orderStore.settings.legalEntity) {
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

function downloadJsonFile(filename, payload) {
  const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

function downloadCttJson() {
  if (!cttResult.value?.items?.length) return
  downloadJsonFile(cttResult.value.downloadName, cttResult.value.items)
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

    } else if (type === 'ctt-preorder') {
      const legalEntity = orderStore.settings.legalEntity
      const preorderLabel = buildCttPreorderLabel(file.name)
      const parsed = await parseCttPreorderXlsx(file)
      const resolved = await resolveCttPreorderRows({
        rows: parsed.rows,
        legalEntity,
        db,
        preorderLabel,
      })

      const result = {
        fileName: file.name,
        downloadName: buildCttPreorderFilename(file.name),
        preorderLabel,
        ...resolved,
      }
      cttResult.value = result

      if (!result.items.length) {
        toast.error('Не удалось собрать JSON', 'Ни одна строка не была распознана по справочнику')
        return
      }

      downloadCttJson()
      toast.success('JSON собран', `${result.items.length} строк для СТТ`)
      if (result.stats.unmatched) {
        toast.warning('Часть строк пропущена', `Не распознано: ${result.stats.unmatched}`)
      }
      if (result.stats.missing_price) {
        toast.warning('Часть строк без цены', `Без цены: ${result.stats.missing_price}`)
      }
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
      ? db.from('restaurant_sales').select('created_at').eq('legal_entity_group', getEntityGroupCode(le)).order('created_at', { ascending: false }).limit(1)
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

.imp-result-card {
  max-width: 980px;
  margin-top: 20px;
  background: var(--card);
  border: 1px solid var(--border-light);
  border-radius: 12px;
  padding: 20px;
}
.imp-result-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}
.imp-result-title { font-size: 16px; font-weight: 700; }
.imp-result-desc { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.imp-result-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 10px;
  margin-bottom: 18px;
}
.imp-stat {
  background: #f8f5f1;
  border: 1px solid #ece3d9;
  border-radius: 10px;
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.imp-stat b { font-size: 20px; color: var(--bk-brown); }
.imp-stat span { font-size: 12px; color: var(--text-muted); }
.imp-preview-title { font-size: 14px; font-weight: 700; margin-bottom: 10px; }
.imp-preview-wrap { margin-bottom: 18px; }
.imp-preview-table-wrap {
  overflow: auto;
  border: 1px solid var(--border-light);
  border-radius: 10px;
}
.imp-preview-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}
.imp-preview-table th,
.imp-preview-table td {
  padding: 8px 10px;
  border-bottom: 1px solid var(--border-light);
  text-align: left;
  white-space: nowrap;
}
.imp-preview-table th {
  background: #f8f5f1;
  font-weight: 700;
}
.imp-unmatched-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.imp-unmatched-row {
  display: grid;
  grid-template-columns: 140px 1fr 240px;
  gap: 10px;
  align-items: start;
  font-size: 12px;
  padding: 10px 12px;
  border: 1px solid var(--border-light);
  border-radius: 10px;
  background: #fffaf5;
}
.imp-unmatched-reason { color: #b45309; }
.imp-unmatched-more { margin-top: 8px; font-size: 12px; color: var(--text-muted); }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }

@media (max-width: 720px) {
  .imp { padding: 20px 16px; }
  .imp-card { align-items: flex-start; }
  .imp-unmatched-row {
    grid-template-columns: 1fr;
  }
}
</style>
