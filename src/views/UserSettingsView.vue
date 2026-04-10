<template>
  <div class="uset">
    <!-- Профиль -->
    <div class="uset-profile">
      <div class="uset-avatar">{{ initials }}</div>
      <div class="uset-profile-info">
        <div class="uset-name">{{ userStore.currentUser?.name || 'Пользователь' }}</div>
        <div class="uset-role">{{ displayRole }}</div>
        <div class="uset-meta">
          <span v-if="userStore.currentUser?.email" class="uset-meta-item">{{ userStore.currentUser.email }}</span>
          <span v-if="userStore.currentUser?.telegram_connected" class="uset-meta-item uset-tg-badge">✈ Telegram подключён</span>
          <span v-else class="uset-meta-item uset-tg-badge uset-tg-off">
            <a href="https://t.me/supplyportal_bot" target="_blank">✈ Подключить бот</a>
          </span>
        </div>
      </div>
    </div>

    <div class="uset-grid">
      <!-- Левая колонка: меню -->
      <div class="uset-card">
        <div class="uset-card-header">
          <div class="uset-card-icon">📱</div>
          <div>
            <div class="uset-card-title">Боковое меню</div>
            <div class="uset-card-desc">Скройте ненужные модули. Они останутся доступны по ссылке.</div>
          </div>
        </div>
        <div class="uset-list">
          <div v-for="m in allModules" :key="m.route || m.module" class="uset-list-item" @click="toggleModule(m.route || m.module)">
            <div class="uset-switch" :class="{ on: !hiddenModules.includes(m.route || m.module) }">
              <div class="uset-switch-thumb"></div>
            </div>
            <BkIcon :name="m.icon" size="sm"/>
            <span class="uset-list-label">{{ m.label }}</span>
          </div>
        </div>
        <div class="uset-card-footer">
          <button v-if="hiddenModules.length" class="uset-btn-text" @click="resetModules">Показать все</button>
          <span class="uset-card-count" v-if="hiddenModules.length">Скрыто: {{ hiddenModules.length }}</span>
        </div>
      </div>

      <!-- Правая колонка: уведомления -->
      <div class="uset-card">
        <div class="uset-card-header">
          <div class="uset-card-icon">🔔</div>
          <div>
            <div class="uset-card-title">Уведомления в Telegram</div>
            <div class="uset-card-desc" v-if="userStore.currentUser?.telegram_connected">Выберите какие уведомления получать в бот.</div>
            <div class="uset-card-desc" v-else>Подключите <a href="https://t.me/supplyportal_bot" target="_blank">@supplyportal_bot</a> для настройки.</div>
          </div>
        </div>
        <div v-if="tgLoading" class="uset-loading">Загрузка...</div>
        <div v-else-if="userStore.currentUser?.telegram_connected" class="uset-list">
          <div v-for="(label, key) in tgLabels" :key="key" class="uset-list-item" @click="toggleTg(key)">
            <div class="uset-switch" :class="{ on: tgSettings[key] }">
              <div class="uset-switch-thumb"></div>
            </div>
            <span class="uset-list-label">{{ label }}</span>
          </div>
        </div>
        <div v-else class="uset-empty-tg">
          <div class="uset-empty-tg-icon">✈</div>
          <a href="https://t.me/supplyportal_bot" target="_blank" class="uset-btn-primary">Подключить Telegram-бот</a>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { db } from '@/lib/apiClient.js'
import { useUserStore } from '@/stores/userStore.js'
import { useToastStore } from '@/stores/toastStore.js'
import BkIcon from '@/components/ui/BkIcon.vue'

const userStore = useUserStore()
const toast = useToastStore()

const initials = computed(() => {
  const name = userStore.currentUser?.name || ''
  return name.split(/\s+/).map(w => w[0]).join('').toUpperCase().slice(0, 2) || '?'
})

const displayRole = computed(() => {
  const r = userStore.currentUser?.display_role || userStore.currentUser?.role
  return { admin: 'Администратор', user: 'Сотрудник', viewer: 'Просмотр' }[r] || r || ''
})

const allModulesRaw = [
  { module: 'analytics', icon: 'home', label: 'Дашборд', route: 'dashboard' },
  { module: 'order', icon: 'package', label: 'Новый заказ' },
  { module: 'planning', icon: 'planning', label: 'Планирование' },
  { module: 'plan-fact', icon: 'delivery', label: 'Поставки' },
  { module: 'history', icon: 'history', label: 'История' },
  { module: 'database', icon: 'database', label: 'База данных' },
  { module: 'pricing', icon: 'pricing', label: 'Цены и ПСЦ' },
  { module: 'calendar', icon: 'calendar', label: 'Календарь' },
  { module: 'analytics', icon: 'analytics', label: 'Аналитика' },
  { module: 'analysis', icon: 'ruler', label: 'Анализ запасов' },
  { module: 'shelf-life', icon: 'shelfLife', label: 'Сроки годности' },
  { module: 'delivery-schedule', icon: 'schedule', label: 'График доставки' },
  { module: 'stock-collection', icon: 'stockCollection', label: 'Сбор остатков' },
  { module: 'deficit', icon: 'deficit', label: 'Распределение дефицита' },
  { module: 'veg', icon: 'veg', label: 'Планета Ресторанов' },
  { module: 'distribution', icon: 'package', label: 'Распределение' },
  { module: 'tenders', icon: 'tender', label: 'Тендеры' },
  { module: 'pallet-calc', icon: 'pallet', label: 'Калькулятор паллет' },
  { module: 'plan-fact', icon: 'pricing', label: 'Оплаты поставщиков', route: 'payments' },
  { module: 'corrections', icon: 'edit', label: 'Корректировки' },
  { module: 'chat', icon: 'chat', label: 'Чат с ресторанами' },
]
const allModules = allModulesRaw.filter(m => userStore.hasAccess(m.module, 'view'))

const hiddenModules = computed(() => userStore.getHiddenModules())

function toggleModule(module) {
  const current = [...hiddenModules.value]
  const idx = current.indexOf(module)
  if (idx >= 0) current.splice(idx, 1)
  else current.push(module)
  userStore.setHiddenModules(current)
}

function resetModules() {
  userStore.setHiddenModules([])
}

// Telegram
const tgLabels = {
  daily_summary: '📊 Ежедневная сводка',
  psc_expiry: '📋 ПСЦ истекает',
  price_changed: '💰 Цены изменились',
  overdue_delivery: '📦 Просроченная поставка',
  data_updates: '📥 Загрузка данных',
  expiring_items: '⚠️ Истекающие сроки',
  restaurant_sales: '🍽 Реализация ресторанов',
  low_stock: '📉 Остатки заканчиваются',
  correction_notifications: '✏️ Корректировки заказов',
  chat_notifications: '💬 Сообщения из ресторанов',
  so_deadline_summary: '🧾 Сводка заявок поставщикам',
}
const tgSettings = ref({})
const tgLoading = ref(false)

async function loadTgSettings() {
  if (!userStore.currentUser?.telegram_connected) return
  tgLoading.value = true
  try {
    const { data } = await db.rpc('get_user_tg_settings', { user_name: userStore.currentUser.name })
    if (data) tgSettings.value = data
  } catch {}
  finally { tgLoading.value = false }
}

async function toggleTg(key) {
  try {
    await db.rpc('tg_admin_toggle_setting', { user_name: userStore.currentUser.name, field: key })
    tgSettings.value[key] = tgSettings.value[key] ? 0 : 1
  } catch { toast.error('Ошибка', '') }
}

onMounted(loadTgSettings)
</script>

<style scoped>
.uset { padding: 24px 32px; }

/* Профиль */
.uset-profile { display: flex; align-items: center; gap: 16px; margin-bottom: 28px; }
.uset-avatar { width: 56px; height: 56px; border-radius: 50%; background: linear-gradient(135deg, var(--bk-brown), #c4956a); color: #fff; font-weight: 700; font-size: 20px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.uset-name { font-size: 20px; font-weight: 700; }
.uset-role { font-size: 13px; color: var(--text-muted); margin-top: 1px; }
.uset-meta { display: flex; gap: 12px; margin-top: 4px; flex-wrap: wrap; }
.uset-meta-item { font-size: 12px; color: var(--text-muted); }
.uset-tg-badge { padding: 2px 8px; border-radius: 10px; background: #E8F5E9; color: #2E7D32; font-weight: 600; }
.uset-tg-off { background: #FFF3E0; color: #E65100; }
.uset-tg-off a { color: inherit; text-decoration: none; }

/* Grid */
.uset-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items: start; }
@media (max-width: 900px) { .uset-grid { grid-template-columns: 1fr; } }

/* Cards */
.uset-card { background: var(--card); border: 1px solid var(--border-light); border-radius: 12px; overflow: hidden; }
.uset-card-header { display: flex; gap: 12px; padding: 16px 20px; border-bottom: 1px solid var(--border-light); align-items: flex-start; }
.uset-card-icon { font-size: 24px; flex-shrink: 0; margin-top: 2px; }
.uset-card-title { font-size: 15px; font-weight: 700; }
.uset-card-desc { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.uset-card-desc a { color: var(--bk-brown); }
.uset-card-footer { padding: 10px 20px; border-top: 1px solid var(--border-light); display: flex; align-items: center; justify-content: space-between; }
.uset-card-count { font-size: 12px; color: var(--text-muted); }

/* List items */
.uset-list { padding: 4px 0; max-height: 480px; overflow-y: auto; }
.uset-list-item { display: flex; align-items: center; gap: 10px; padding: 9px 20px; cursor: pointer; transition: background 0.1s; }
.uset-list-item:hover { background: rgba(139,115,85,0.05); }
.uset-list-label { font-size: 14px; }

/* Switch */
.uset-switch { width: 36px; height: 20px; border-radius: 10px; background: #ccc; position: relative; transition: background 0.2s; flex-shrink: 0; }
.uset-switch.on { background: var(--bk-brown); }
.uset-switch-thumb { width: 16px; height: 16px; border-radius: 50%; background: #fff; position: absolute; top: 2px; left: 2px; transition: left 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
.uset-switch.on .uset-switch-thumb { left: 18px; }

/* Buttons */
.uset-btn-text { background: none; border: none; color: var(--bk-brown); font-size: 13px; font-weight: 600; cursor: pointer; padding: 0; }
.uset-btn-text:hover { text-decoration: underline; }
.uset-btn-primary { display: inline-block; padding: 8px 20px; background: var(--bk-brown); color: #fff; border-radius: 8px; font-size: 14px; font-weight: 600; text-decoration: none; }
.uset-btn-primary:hover { opacity: 0.9; }

/* Empty TG */
.uset-empty-tg { padding: 32px 20px; text-align: center; }
.uset-empty-tg-icon { font-size: 36px; margin-bottom: 12px; }

.uset-loading { padding: 20px; text-align: center; color: var(--text-muted); font-size: 13px; }
</style>
