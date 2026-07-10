<template>
  <Teleport to="body">
    <Transition name="cp-fade">
      <div v-if="open" class="cp-overlay" @click="close">
        <div class="cp-modal" @click.stop>
          <div class="cp-input-wrap">
            <svg class="cp-search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
            <input ref="inputEl" v-model="query" class="cp-input" placeholder="Перейти, найти товар, поставщика..." @keydown.escape="close" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="select" />
            <div class="cp-badges">
              <kbd class="cp-kbd">/</kbd>
              <kbd class="cp-kbd">ESC</kbd>
            </div>
          </div>

          <div class="cp-body" v-if="loading">
            <div class="cp-loading"><BurgerSpinner text="Поиск..." /></div>
          </div>
          <div class="cp-body" v-else-if="allResults.length">
            <div v-for="group in groupedResults" :key="group.title" class="cp-group">
              <div class="cp-group-title">{{ group.title }}</div>
              <div v-for="item in group.items" :key="item._key" class="cp-item" :class="{ active: item._idx === activeIdx }" @click="go(item)" @mouseenter="activeIdx = item._idx">
                <span class="cp-item-icon">{{ item.icon }}</span>
                <div class="cp-item-text">
                  <div class="cp-item-title" v-html="highlight(item.title)"></div>
                  <div v-if="item.subtitle" class="cp-item-sub">{{ item.subtitle }}</div>
                </div>
                <span v-if="item.badge" class="cp-item-badge">{{ item.badge }}</span>
              </div>
            </div>
          </div>
          <div class="cp-body" v-else-if="query.length >= 2">
            <div class="cp-empty">Ничего не найдено по «{{ query }}»</div>
          </div>
          <div class="cp-body" v-else>
            <div class="cp-group">
              <div class="cp-group-title">Быстрые действия</div>
              <div v-for="item in quickActions" :key="item._key" class="cp-item" :class="{ active: item._idx === activeIdx }" @click="go(item)" @mouseenter="activeIdx = item._idx">
                <span class="cp-item-icon">{{ item.icon }}</span>
                <div class="cp-item-text"><div class="cp-item-title">{{ item.title }}</div></div>
              </div>
            </div>
          </div>

          <div class="cp-footer">
            <span>↑↓ навигация</span>
            <span>↵ выбрать</span>
            <span>esc закрыть</span>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useUserStore } from '@/stores/userStore.js'
import { db } from '@/lib/apiClient.js'
import { formatRestaurantNumber } from '@/lib/legalEntities.js'

const router = useRouter()
const userStore = useUserStore()
const open = ref(false)
const query = ref('')
const activeIdx = ref(0)
const inputEl = ref(null)
const loading = ref(false)
const searchResults = ref([])
let searchTimer = null

// Модули — актуальный список, синхронизирован с сайдбаром (AppLayout.vue) и роутером.
// При добавлении/переименовании раздела в сайдбаре — обновить и здесь.
const modules = [
  // Заказы
  { title: 'Новый заказ', icon: '📦', route: 'order', module: 'order', keywords: 'заказ new' },
  { title: 'Планирование', icon: '📋', route: 'planning', module: 'planning' },
  { title: 'Поставки', icon: '🚚', route: 'plan-fact', module: 'plan-fact', keywords: 'план факт' },
  { title: 'История', icon: '📜', route: 'history', module: 'history', keywords: 'история заказов' },
  { title: 'Задачи', icon: '🗒', route: 'tasks', module: 'tasks', keywords: 'таск доска канбан' },
  // Данные
  { title: 'База данных', icon: '🗄', route: 'database', module: 'database', keywords: 'товары поставщики рестораны справочник база товаров' },
  { title: 'Цены и ПСЦ', icon: '💰', route: 'pricing', module: 'pricing', keywords: 'прайс протокол' },
  { title: 'Календарь', icon: '📅', route: 'calendar', module: 'calendar' },
  // Аналитика
  { title: 'Дашборд', icon: '📊', route: 'dashboard', module: 'dashboard' },
  { title: 'Аналитика', icon: '📈', route: 'analytics', module: 'analytics' },
  { title: 'Анализ запасов', icon: '📉', route: 'analysis', module: 'analysis' },
  { title: 'Сверка 1С/УТ', icon: '🔁', route: 'reconciliation', module: 'reconciliation', keywords: 'сверка ут 1с расхождения' },
  { title: 'ИИ-помощник', icon: '💡', route: 'assistant', module: 'analysis', keywords: 'ai ассистент нейросеть' },
  { title: 'Маркетинг', icon: '🎯', route: 'marketing', module: 'marketing', keywords: 'акция промо' },
  { title: 'Протоколы совещаний', icon: '📄', route: 'protocols', module: 'protocols', keywords: 'совещание протокол' },
  // Склад и логистика
  { title: 'Сроки годности', icon: '⏰', route: 'shelf-life', module: 'shelf-life', keywords: 'срок просрочка ячейки' },
  { title: 'График доставки', icon: '🗓', route: 'delivery-schedule', module: 'delivery-schedule' },
  { title: 'Распределение дефицита', icon: '⚠️', route: 'deficit', module: 'deficit' },
  { title: 'Распределение', icon: '📦', route: 'distribution', module: 'distribution' },
  { title: 'Загрузка машин', icon: '🚛', route: 'truck-loading', module: 'truck-loading', keywords: 'фура грузовик паллеты' },
  { title: 'Заявка на пропуск', icon: '🎫', route: 'tit-requests', module: 'tit-requests', keywords: 'пропуск тит въезд машина' },
  { title: 'Калькулятор паллет', icon: '🧮', route: 'pallet-calc', module: 'pallet-calc' },
  { title: 'Паллетовка склада', icon: '🏭', route: 'pallet-storage', module: 'pallet-storage' },
  // Поставщики
  { title: 'Тендеры', icon: '📑', route: 'tenders', module: 'tenders' },
  { title: 'График поставок', icon: '🚚', route: 'supplier-schedule', module: 'supplier-schedule', keywords: 'расписание поставщиков дедлайн' },
  { title: 'Заявки поставщикам', icon: '🏭', route: 'supplier-orders', module: 'supplier-orders', keywords: 'камако овощи so планета' },
  { title: 'Оплаты поставщиков', icon: '💳', route: 'payments', module: 'plan-fact' },
  // Управление ресторанами
  { title: 'Кабинеты ресторанов', icon: 'ℹ️', route: 'restaurant-cabinet-manager', module: 'restaurant-orders', keywords: 'управление ресторанами важная информация кабинет' },
  { title: 'Заказы ресторанов', icon: '🍔', route: 'restaurant-orders', module: 'restaurant-orders', keywords: 'рестораны ро' },
  { title: 'Сбор заказа осн. поставки', icon: '🧺', route: 'supply-assistant', module: 'supply-assistant', keywords: 'помощник основная поставка sa' },
  { title: 'Штрихкоды', icon: '🏷', route: 'restaurant-unknown-barcodes', module: 'restaurant-orders', keywords: 'сканер неизвестные штрихкод barcode' },
  { title: 'Возврат кег', icon: '🛢', route: 'keg-returns', module: 'keg-returns', keywords: 'кеги ттн бсо' },
  { title: 'Сбор остатков', icon: '📝', route: 'stock-collection', module: 'stock-collection' },
  { title: 'Опросы', icon: '📋', route: 'surveys', module: 'surveys', keywords: 'анкета опросник ответы рестораны' },
  { title: 'Чат с ресторанами', icon: '💬', route: 'chat', module: 'chat' },
  { title: 'Корректировки', icon: '✏️', route: 'corrections', module: 'corrections', keywords: 'корректировки заказов' },
  // Прочие страницы
  { title: 'Реализация ресторанов', icon: '💹', route: 'restaurant-sales', module: 'restaurant-sales', keywords: 'продажи выручка' },
  { title: 'Отчёт по заказам ресторанов', icon: '📄', route: 'restaurant-report', module: 'restaurant-orders' },
  { title: 'Поиск карточек', icon: '🔍', route: 'search-cards', keywords: 'карточка sku' },
  { title: 'Импорт данных', icon: '⬆️', route: 'import', module: 'analysis' },
  { title: 'Telegram-бот', icon: '🤖', route: 'telegram-admin', module: 'telegram' },
  { title: 'Админ-панель', icon: '🛠', route: 'admin', requiresAdmin: true },
  { title: 'Настройки аккаунта', icon: '⚙️', route: 'user-settings' },
].filter(m => {
  if (m.requiresAdmin) return userStore.hasAccess && (userStore.user?.role === 'admin');
  return !m.module || userStore.hasAccess(m.module, 'view');
}).map((m, i) => ({ ...m, group: 'Модули', type: 'route', _key: 'mod_' + i }))

const quickActions = computed(() => {
  let idx = 0
  return modules.slice(0, 6).map(m => ({ ...m, _idx: idx++ }))
})

// Поиск
const allResults = computed(() => {
  const items = []
  const q = query.value.toLowerCase().trim()
  if (q.length < 1) return []

  // Модули — по заголовку и ключевым словам
  const mods = modules.filter(m => m.title.toLowerCase().includes(q) || (m.keywords || '').toLowerCase().includes(q))
  items.push(...mods.map(m => ({ ...m })))

  // Результаты поиска по БД
  items.push(...searchResults.value)

  return items
})

const groupedResults = computed(() => {
  const groups = {}
  let idx = 0
  for (const r of allResults.value) {
    const g = r.group || 'Результаты'
    if (!groups[g]) groups[g] = { title: g, items: [] }
    groups[g].items.push({ ...r, _idx: idx++ })
  }
  return Object.values(groups)
})

async function search(q) {
  if (q.length < 2) { searchResults.value = []; return }
  loading.value = true
  try {
    const escaped = q.replace(/[*%_]/g, '')

    // Параллельно ищем товары, поставщиков и рестораны
    const [productsRes, suppliersRes, restaurantsRes] = await Promise.allSettled([
      db.from('products')
        .select('sku, name, supplier, external_code')
        .or(`sku.ilike.*${escaped}*,name.ilike.*${escaped}*,external_code.ilike.*${escaped}*`)
        .eq('is_active', 1)
        .limit(6),
      db.from('suppliers')
        .select('short_name, full_name')
        .or(`short_name.ilike.*${escaped}*,full_name.ilike.*${escaped}*`)
        .limit(5),
      db.from('restaurants')
        .select('number, city, address, region, legal_entity_group')
        .or(`number.ilike.*${escaped}*,city.ilike.*${escaped}*,address.ilike.*${escaped}*`)
        .eq('active', 1)
        .limit(5),
    ])

    const results = []
    const products = productsRes.status === 'fulfilled' ? productsRes.value?.data : []
    const suppliers = suppliersRes.status === 'fulfilled' ? suppliersRes.value?.data : []
    const restaurants = restaurantsRes.status === 'fulfilled' ? restaurantsRes.value?.data : []

    if (products?.length) {
      results.push(...products.map((p, i) => ({
        title: `${p.sku} ${p.name}`,
        subtitle: p.supplier || '',
        icon: '📦',
        group: 'Товары',
        type: 'product',
        sku: p.sku,
        _key: 'prod_' + i,
      })))
    }

    if (suppliers?.length) {
      results.push(...suppliers.map((s, i) => ({
        title: s.short_name,
        subtitle: s.full_name || '',
        icon: '🏭',
        group: 'Поставщики',
        type: 'supplier',
        supplier: s.short_name,
        _key: 'sup_' + i,
      })))
    }

    if (restaurants?.length) {
      results.push(...restaurants.map((r, i) => ({
        title: `Ресторан ${formatRestaurantNumber(r.number, r.legal_entity_group)}`,
        subtitle: [r.city, r.address].filter(Boolean).join(', '),
        icon: '🍔',
        group: 'Рестораны',
        type: 'restaurant',
        restaurant_number: r.number,
        _key: 'rest_' + i,
      })))
    }

    searchResults.value = results
  } catch { searchResults.value = [] }
  finally { loading.value = false }
}

watch(query, (q) => {
  activeIdx.value = 0
  clearTimeout(searchTimer)
  if (q.length >= 2) searchTimer = setTimeout(() => search(q), 250)
  else searchResults.value = []
})

function highlight(text) {
  // Сначала экранируем сам текст — он может прийти из БД (имя товара/поставщика)
  // и содержать символы вроде < > & ", которые нельзя отдавать в v-html «как есть».
  const escaped = String(text ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
  if (!query.value || query.value.length < 2) return escaped
  const q = query.value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  return escaped.replace(new RegExp(`(${q})`, 'gi'), '<mark>$1</mark>')
}

function move(dir) {
  const total = allResults.value.length || quickActions.value.length
  if (!total) return
  activeIdx.value = (activeIdx.value + dir + total) % total
}

function select() {
  const items = allResults.value.length ? allResults.value : quickActions.value
  const item = items[activeIdx.value]
  if (item) go(item)
}

function go(item) {
  close()
  if (item.type === 'route' && item.route) {
    router.push({ name: item.route })
  } else if (item.type === 'product') {
    router.push({ name: 'database', query: { search: item.sku } })
  } else if (item.type === 'supplier') {
    router.push({ name: 'database', query: { tab: 'suppliers', search: item.supplier } })
  } else if (item.type === 'restaurant') {
    router.push({ name: 'database', query: { tab: 'restaurants', search: String(item.restaurant_number) } })
  }
}

function close() { open.value = false; query.value = ''; activeIdx.value = 0; searchResults.value = [] }

function onKeydown(e) {
  if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === '/')) {
    e.preventDefault(); e.stopPropagation()
    open.value = !open.value
    return
  }
  if (e.key === '/' && !open.value && !['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName)) {
    e.preventDefault()
    open.value = true
  }
}

watch(open, (v) => { if (v) nextTick(() => inputEl.value?.focus()) })

defineExpose({ open })

onMounted(() => document.addEventListener('keydown', onKeydown, true))
onUnmounted(() => document.removeEventListener('keydown', onKeydown, true))
</script>

<style scoped>
.cp-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; justify-content: center; padding-top: 12vh; backdrop-filter: blur(3px); }
.cp-modal { width: 600px; max-width: 92vw; background: var(--card, #fff); border-radius: 16px; box-shadow: 0 25px 60px rgba(0,0,0,0.3); overflow: hidden; max-height: 520px; display: flex; flex-direction: column; }
.cp-input-wrap { display: flex; align-items: center; gap: 10px; padding: 16px 20px; border-bottom: 1px solid var(--border-light, #eee); }
.cp-search-icon { flex-shrink: 0; color: var(--text-muted, #999); }
.cp-input { flex: 1; border: none; outline: none; font-size: 16px; background: transparent; font-family: inherit; color: var(--text, #333); }
.cp-input::placeholder { color: var(--text-muted, #bbb); }
.cp-badges { display: flex; gap: 4px; }
.cp-kbd { background: var(--border-light, #eee); color: var(--text-muted, #999); font-size: 10px; padding: 2px 5px; border-radius: 4px; font-family: monospace; }
.cp-body { overflow-y: auto; flex: 1; }
.cp-group-title { font-size: 10px; font-weight: 700; color: var(--text-muted, #999); padding: 10px 20px 4px; text-transform: uppercase; letter-spacing: 0.8px; }
.cp-item { display: flex; align-items: center; gap: 12px; padding: 10px 20px; cursor: pointer; transition: background 0.08s; }
.cp-item:hover, .cp-item.active { background: rgba(139,115,85,0.08); }
.cp-item-icon { font-size: 16px; flex-shrink: 0; width: 24px; text-align: center; }
.cp-item-text { flex: 1; min-width: 0; }
.cp-item-title { font-size: 14px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cp-item-title :deep(mark) { background: #FFE082; color: inherit; border-radius: 2px; padding: 0 1px; }
.cp-item-sub { font-size: 11px; color: var(--text-muted, #999); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cp-item-badge { font-size: 10px; color: var(--text-muted); background: var(--border-light, #eee); padding: 2px 6px; border-radius: 6px; }
.cp-empty { padding: 32px 20px; text-align: center; color: var(--text-muted, #999); font-size: 14px; }
.cp-loading { padding: 24px 20px; text-align: center; color: var(--text-muted); font-size: 13px; }
.cp-footer { display: flex; gap: 16px; padding: 8px 20px; border-top: 1px solid var(--border-light, #eee); font-size: 11px; color: var(--text-muted, #bbb); }
.cp-fade-enter-active, .cp-fade-leave-active { transition: opacity 0.12s; }
.cp-fade-enter-from, .cp-fade-leave-to { opacity: 0; }
</style>
