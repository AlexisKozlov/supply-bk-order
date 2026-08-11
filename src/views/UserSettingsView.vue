<template>
  <div class="uset">
    <!-- Профиль-шапка видна на всех вкладках: это «кто я», а не настройка. -->
    <div class="uset-profile">
      <div class="uset-avatar">{{ initials }}</div>
      <div class="uset-profile-info">
        <div class="uset-name">{{ userStore.currentUser?.name || 'Пользователь' }}</div>
        <div class="uset-role">{{ displayRole }}</div>
        <div class="uset-meta">
          <span v-if="userStore.currentUser?.email" class="uset-meta-item">{{ userStore.currentUser.email }}</span>
          <span v-if="userStore.currentUser?.telegram_connected" class="uset-meta-item uset-tg-badge"><BkIcon name="send" size="sm" /> Telegram подключён</span>
          <span v-else class="uset-meta-item uset-tg-badge uset-tg-off">
            <a href="https://t.me/supplyportal_bot" target="_blank"><BkIcon name="send" size="sm" /> Подключить бот</a>
          </span>
        </div>
      </div>
    </div>

    <div class="uset-layout">
      <!-- Вкладки слева. У каждой свой адрес: ссылку можно отправить коллеге. -->
      <nav class="uset-tabs">
        <button v-for="t in TABS" :key="t.key"
                class="uset-tab" :class="{ active: activeTab === t.key }"
                @click="activeTab = t.key">
          <BkIcon :name="t.icon" size="sm" />
          <span>{{ t.label }}</span>
        </button>
      </nav>

      <div class="uset-pane">
        <!-- ═══════════════ ПРОФИЛЬ ═══════════════ -->
        <template v-if="activeTab === 'profile'">
          <div class="uset-card">
            <div class="uset-card-header">
              <div class="uset-card-icon"><BkIcon name="home" size="lg" /></div>
              <div>
                <div class="uset-card-title">С чего начинать работу</div>
                <div class="uset-card-desc">Куда попадать после входа и какое юрлицо выбрано по умолчанию.</div>
              </div>
            </div>
            <div class="uset-form">
              <label class="uset-field">
                <span class="uset-field-label">Стартовая страница</span>
                <select v-model="startPage" @change="saveStartPage">
                  <option value="">Главная</option>
                  <option v-for="m in allModules" :key="m.route" :value="m.route">{{ m.label }}</option>
                </select>
              </label>
              <label v-if="myEntities.length > 1" class="uset-field">
                <span class="uset-field-label">Юрлицо по умолчанию</span>
                <select v-model="defaultEntity" @change="saveDefaultEntity">
                  <option v-for="e in myEntities" :key="e" :value="e">{{ e }}</option>
                </select>
              </label>
              <p v-else-if="myEntities.length === 1" class="uset-hint-inline">
                У вас одно юрлицо: {{ myEntities[0] }}
              </p>
            </div>
          </div>

          <div class="uset-card">
            <div class="uset-card-header">
              <div class="uset-card-icon"><BkIcon name="key" size="lg" /></div>
              <div>
                <div class="uset-card-title">Смена пароля</div>
                <div class="uset-card-desc">Новый пароль — не короче 8 знаков.</div>
              </div>
            </div>
            <div class="uset-form">
              <label class="uset-field">
                <span class="uset-field-label">Текущий пароль</span>
                <UiPasswordInput v-model="pwdOld" autocomplete="current-password" />
              </label>
              <label class="uset-field">
                <span class="uset-field-label">Новый пароль</span>
                <UiPasswordInput v-model="pwdNew" autocomplete="new-password" />
              </label>
              <label class="uset-field">
                <span class="uset-field-label">Новый пароль ещё раз</span>
                <UiPasswordInput v-model="pwdNew2" autocomplete="new-password" />
              </label>
              <div v-if="pwdError" class="uset-error">{{ pwdError }}</div>
              <div>
                <button class="uset-btn-primary" :disabled="pwdBusy" @click="changePassword">
                  {{ pwdBusy ? 'Меняем...' : 'Сменить пароль' }}
                </button>
              </div>
            </div>
          </div>
        </template>

        <!-- ═══════════════ УВЕДОМЛЕНИЯ ═══════════════ -->
        <template v-else-if="activeTab === 'notifications'">
          <div class="uset-card">
            <div class="uset-card-header">
              <div class="uset-card-icon"><BkIcon name="send" size="lg" /></div>
              <div>
                <div class="uset-card-title">Уведомления в Telegram</div>
                <div class="uset-card-desc" v-if="userStore.currentUser?.telegram_connected">Выберите, что получать в бот.</div>
                <div class="uset-card-desc" v-else>Подключите <a href="https://t.me/supplyportal_bot" target="_blank">@supplyportal_bot</a> для настройки.</div>
              </div>
            </div>
            <div v-if="tgLoading" class="uset-loading"><BurgerSpinner text="Загрузка..." /></div>
            <template v-else-if="userStore.currentUser?.telegram_connected">
              <div class="uset-list">
                <div v-for="(t, key) in tgLabels" :key="key" class="uset-list-item" @click="toggleTg(key)">
                  <div class="uset-switch" :class="{ on: tgSettings[key] }">
                    <div class="uset-switch-thumb"></div>
                  </div>
                  <BkIcon :name="t.icon" size="sm" />
                  <span class="uset-list-label">{{ t.label }}</span>
                </div>
              </div>
              <div class="uset-card-actions">
                <button class="uset-btn" :disabled="tgTesting" @click="sendTgTest">
                  {{ tgTesting ? 'Отправляем...' : 'Прислать тестовое' }}
                </button>
                <button class="uset-btn" :disabled="tgUnlinking" @click="unlinkTg">
                  {{ tgUnlinking ? 'Отвязываем...' : 'Отвязать бота' }}
                </button>
              </div>
            </template>
            <div v-else class="uset-empty-tg">
              <div class="uset-empty-tg-icon"><BkIcon name="send" size="lg" /></div>
              <a href="https://t.me/supplyportal_bot" target="_blank" class="uset-btn-primary">Подключить Telegram-бот</a>
            </div>
          </div>

          <!-- Push. Подписку раньше умел включать только кабинет ресторана. -->
          <div v-if="push.isSupported.value" class="uset-card">
            <div class="uset-card-header">
              <div class="uset-card-icon"><BkIcon name="bell" size="lg" /></div>
              <div>
                <div class="uset-card-title">Уведомления на телефон</div>
                <div class="uset-card-desc">
                  Приходят, даже когда портал закрыт. Включается отдельно на каждом устройстве.
                </div>
              </div>
            </div>
            <div v-if="push.permission.value === 'denied'" class="uset-push-denied">
              Уведомления запрещены в настройках браузера для этого сайта.
              Разрешите их там — переключатель заработает.
            </div>
            <template v-else>
              <div class="uset-list">
                <div class="uset-list-item" @click="togglePush">
                  <div class="uset-switch" :class="{ on: push.isSubscribed.value }">
                    <div class="uset-switch-thumb"></div>
                  </div>
                  <span class="uset-list-label">
                    {{ push.isSubscribed.value ? 'Включены на этом устройстве' : 'Включить на этом устройстве' }}
                  </span>
                </div>
              </div>
              <div v-if="push.error.value" class="uset-push-error">{{ push.error.value }}</div>
            </template>
          </div>
        </template>

        <!-- ═══════════════ ИНТЕРФЕЙС ═══════════════ -->
        <template v-else-if="activeTab === 'interface'">
          <div class="uset-card">
            <div class="uset-card-header">
              <div class="uset-card-icon"><BkIcon name="menu" size="lg" /></div>
              <div>
                <div class="uset-card-title">Боковое меню</div>
                <div class="uset-card-desc">Скройте ненужные модули. Они останутся доступны по ссылке.</div>
              </div>
              <button v-if="hiddenModules.length" class="uset-btn-text" @click="resetModules">Показать все</button>
            </div>
            <div class="uset-list">
              <div v-for="m in allModules" :key="m.label" class="uset-list-item" @click="toggleModule(m.module)">
                <div class="uset-switch" :class="{ on: !hiddenModules.includes(m.module) }">
                  <div class="uset-switch-thumb"></div>
                </div>
                <BkIcon :name="m.icon" size="sm" />
                <span class="uset-list-label">{{ m.label }}</span>
              </div>
            </div>
          </div>

          <div class="uset-card">
            <div class="uset-card-header">
              <div class="uset-card-icon"><BkIcon name="sparkle" size="lg" /></div>
              <div>
                <div class="uset-card-title">Закреплённые разделы</div>
                <div class="uset-card-desc">
                  Показываются вверху меню. Закрепить — правой кнопкой по разделу в меню.
                </div>
              </div>
            </div>
            <div v-if="!pinnedList.length" class="uset-hint">Пока ничего не закреплено.</div>
            <div v-else class="uset-list">
              <div v-for="p in pinnedList" :key="p.route" class="uset-list-item uset-list-static">
                <BkIcon :name="p.icon" size="sm" />
                <span class="uset-list-label">{{ p.label }}</span>
                <button class="uset-unpin" @click="unpin(p.route)">Открепить</button>
              </div>
            </div>
          </div>

          <div v-if="pwaInstall.canInstall.value" class="uset-card">
            <div class="uset-card-header">
              <div class="uset-card-icon"><BkIcon name="modules" size="lg" /></div>
              <div>
                <div class="uset-card-title">Портал как приложение</div>
                <div class="uset-card-desc">Своё окно без адресной строки и запуск с рабочего стола.</div>
              </div>
            </div>
            <div class="uset-form">
              <InstallAppButton />
            </div>
          </div>
        </template>

        <!-- ═══════════════ БЕЗОПАСНОСТЬ ═══════════════ -->
        <template v-else>
          <div class="uset-card">
            <div class="uset-card-header">
              <div class="uset-card-icon"><BkIcon name="key" size="lg" /></div>
              <div>
                <div class="uset-card-title">Устройства и сеансы</div>
                <div class="uset-card-desc">Где выполнен вход под вашей учётной записью.</div>
              </div>
              <button v-if="sessions.length > 1" class="uset-btn-text" @click="revokeOthers">Выйти на остальных</button>
            </div>
            <div v-if="sesLoading" class="uset-loading"><BurgerSpinner text="Загрузка..." /></div>
            <div v-else-if="!sessions.length" class="uset-hint">Активных сеансов нет.</div>
            <div v-else class="uset-list">
              <div v-for="s in sessions" :key="s.id" class="uset-list-item uset-list-static">
                <BkIcon :name="s.current ? 'success' : 'user'" size="sm" />
                <span class="uset-list-label">
                  {{ describeAgent(s.agent) }}
                  <small class="uset-sub">{{ s.ip }} · вход {{ fmtDate(s.created_at) }}</small>
                </span>
                <span v-if="s.current" class="uset-badge">это устройство</span>
                <button v-else class="uset-unpin" @click="revokeOne(s.id)">Закрыть</button>
              </div>
            </div>
          </div>

          <div class="uset-card">
            <div class="uset-card-header">
              <div class="uset-card-icon"><BkIcon name="history" size="lg" /></div>
              <div>
                <div class="uset-card-title">Мои последние действия</div>
                <div class="uset-card-desc">Что вы меняли в портале — из журнала.</div>
              </div>
            </div>
            <div v-if="actLoading" class="uset-loading"><BurgerSpinner text="Загрузка..." /></div>
            <div v-else-if="!activity.length" class="uset-hint">Записей пока нет.</div>
            <div v-else class="uset-list">
              <div v-for="(a, i) in activity" :key="i" class="uset-list-item uset-list-static">
                <span class="uset-list-label">
                  {{ activityLabel(a.action) }}
                  <small class="uset-sub">{{ entityLabel(a.entity_type) }}{{ a.legal_entity ? ' · ' + a.legal_entity : '' }} · {{ fmtDate(a.created_at) }}</small>
                </span>
              </div>
            </div>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { db } from '@/lib/apiClient.js'
import { useUserStore } from '@/stores/userStore.js'
import { useOrderStore } from '@/stores/orderStore.js'
import { useToastStore } from '@/stores/toastStore.js'
import { useTabRoute } from '@/composables/useTabRoute.js'
import { useInstallPrompt } from '@/composables/useInstallPrompt.js'
import { usePushNotifications } from '@/composables/usePushNotifications.js'
import { activityLabel, entityLabel } from '@/lib/auditActions.js'
import { ALL_NAV_ITEMS } from '@/lib/navSections.js'
import BkIcon from '@/components/ui/BkIcon.vue'
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue'
import UiPasswordInput from '@/components/ui/UiPasswordInput.vue'
import InstallAppButton from '@/components/InstallAppButton.vue'

const userStore = useUserStore()
const orderStore = useOrderStore()
const toast = useToastStore()
const pwaInstall = useInstallPrompt()
const push = usePushNotifications()

const TABS = [
  { key: 'profile',       icon: 'user',    label: 'Профиль' },
  { key: 'notifications', icon: 'bell',    label: 'Уведомления' },
  { key: 'interface',     icon: 'modules', label: 'Интерфейс' },
  { key: 'security',      icon: 'key',     label: 'Безопасность' },
]
// У каждой вкладки свой адрес — ссылку можно отправить коллеге.
const activeTab = useTabRoute('profile', TABS.map(t => t.key))

const initials = computed(() => {
  const name = userStore.currentUser?.name || ''
  return name.split(/\s+/).map(w => w[0]).join('').toUpperCase().slice(0, 2) || '?'
})

const displayRole = computed(() => {
  const r = userStore.currentUser?.display_role || userStore.currentUser?.role
  return { admin: 'Администратор', user: 'Сотрудник', viewer: 'Просмотр' }[r] || r || ''
})

function fmtDate(v) {
  if (!v) return ''
  const d = new Date(String(v).replace(' ', 'T'))
  return isNaN(d) ? String(v) : d.toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' })
}

// ─────────────────────────── Профиль ───────────────────────────
const myEntities = computed(() => {
  const le = userStore.currentUser?.legal_entities
  return Array.isArray(le) ? le : []
})

const startPage = ref('')
const defaultEntity = ref(orderStore.settings.legalEntity || '')

async function saveStartPage() {
  try {
    await db.rpc('set_user_preference', { key: 'start_page', value: startPage.value || null })
    toast.success('Сохранено', startPage.value ? 'Портал будет открываться здесь' : 'Портал будет открываться на главной')
  } catch { toast.error('Ошибка', 'Не удалось сохранить') }
}

function saveDefaultEntity() {
  // Юрлицо хранит orderStore: он кладёт его в localStorage и синхронизирует
  // между вкладками. Дублировать в preferences незачем.
  orderStore.settings.legalEntity = defaultEntity.value
  toast.success('Сохранено', defaultEntity.value)
}

const pwdOld = ref('')
const pwdNew = ref('')
const pwdNew2 = ref('')
const pwdBusy = ref(false)
const pwdError = ref('')

async function changePassword() {
  pwdError.value = ''
  if (pwdNew.value.length < 8) { pwdError.value = 'Новый пароль короче 8 знаков'; return }
  if (pwdNew.value !== pwdNew2.value) { pwdError.value = 'Пароли не совпадают'; return }
  pwdBusy.value = true
  try {
    const { data } = await db.rpc('change_user_password', {
      name: userStore.currentUser?.name,
      old_password: pwdOld.value,
      new_password: pwdNew.value,
    })
    if (data?.success) {
      pwdOld.value = ''; pwdNew.value = ''; pwdNew2.value = ''
      toast.success('Пароль изменён', 'В следующий раз входите с новым')
    } else {
      pwdError.value = data?.error === 'wrong_password' ? 'Текущий пароль неверный'
        : data?.error === 'password_too_short' ? 'Новый пароль короче 8 знаков'
        : 'Не удалось сменить пароль'
    }
  } catch { pwdError.value = 'Не удалось сменить пароль' }
  finally { pwdBusy.value = false }
}

// ─────────────────────── Модули и закрепления ───────────────────────
const allModulesRaw = [
  { module: 'analytics', icon: 'home', label: 'Дашборд', route: 'dashboard' },
  { module: 'order', icon: 'package', label: 'Новый заказ', route: 'order' },
  { module: 'planning', icon: 'planning', label: 'Планирование', route: 'planning' },
  { module: 'plan-fact', icon: 'delivery', label: 'Поставки', route: 'plan-fact' },
  { module: 'history', icon: 'history', label: 'История', route: 'history' },
  { module: 'database', icon: 'database', label: 'База данных', route: 'database' },
  { module: 'pricing', icon: 'pricing', label: 'Цены и ПСЦ', route: 'pricing' },
  { module: 'calendar', icon: 'calendar', label: 'Календарь', route: 'calendar' },
  { module: 'analytics', icon: 'analytics', label: 'Аналитика', route: 'analytics' },
  { module: 'analysis', icon: 'ruler', label: 'Анализ запасов', route: 'analysis' },
  { module: 'shelf-life', icon: 'shelfLife', label: 'Сроки годности', route: 'shelf-life' },
  { module: 'delivery-schedule', icon: 'schedule', label: 'График доставки', route: 'delivery-schedule' },
  { module: 'stock-collection', icon: 'stockCollection', label: 'Сбор остатков', route: 'stock-collection' },
  { module: 'deficit', icon: 'deficit', label: 'Распределение дефицита', route: 'deficit' },
  { module: 'distribution', icon: 'distribute', label: 'Распределение', route: 'distribution' },
  { module: 'tenders', icon: 'tender', label: 'Тендеры', route: 'tenders' },
  { module: 'pallet-calc', icon: 'pallet', label: 'Калькулятор паллет', route: 'pallet-calc' },
  { module: 'plan-fact', icon: 'payments', label: 'Оплаты поставщиков', route: 'payments' },
  { module: 'corrections', icon: 'edit', label: 'Корректировки', route: 'corrections' },
  { module: 'chat', icon: 'chat', label: 'Чат с ресторанами', route: 'chat' },
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

function resetModules() { userStore.setHiddenModules([]) }

// Закреплённые разделы живут в preferences.sidebar_pins — их ставит меню
// правой кнопкой, но увидеть список раньше было негде.
const pins = ref([])
const pinnedList = computed(() =>
  pins.value.map(route => {
    const item = ALL_NAV_ITEMS.find(i => i.route === route)
    return { route, label: item?.label || route, icon: item?.icon || 'note' }
  })
)

async function loadPrefs() {
  try {
    const { data } = await db.rpc('get_user_preferences', {})
    const p = data?.preferences || {}
    pins.value = Array.isArray(p.sidebar_pins) ? p.sidebar_pins : []
    startPage.value = typeof p.start_page === 'string' ? p.start_page : ''
  } catch { /* офлайн — покажем пусто */ }
}

async function unpin(route) {
  pins.value = pins.value.filter(r => r !== route)
  try {
    await db.rpc('set_user_preference', { key: 'sidebar_pins', value: pins.value })
    localStorage.setItem('bk_pinned_modules', JSON.stringify(pins.value))
    toast.info('Откреплено', 'Обновите страницу, чтобы меню перестроилось')
  } catch { toast.error('Ошибка', 'Не удалось сохранить') }
}

// ─────────────────────────── Telegram ───────────────────────────
// Значки вместо эмодзи: на части устройств эмодзи рисуются квадратами.
const tgLabels = {
  daily_summary:            { icon: 'analytics',        label: 'Ежедневная сводка' },
  psc_expiry:               { icon: 'document',         label: 'ПСЦ истекает' },
  price_changed:            { icon: 'pricing',          label: 'Цены изменились' },
  overdue_delivery:         { icon: 'delivery',         label: 'Просроченная поставка' },
  data_updates:             { icon: 'import',           label: 'Загрузка данных' },
  expiring_items:           { icon: 'shelfLife',        label: 'Истекающие сроки' },
  restaurant_sales:         { icon: 'restaurantOrders', label: 'Реализация ресторанов' },
  low_stock:                { icon: 'chartDown',        label: 'Остатки заканчиваются' },
  correction_notifications: { icon: 'edit',             label: 'Корректировки заказов' },
  chat_notifications:       { icon: 'chat',             label: 'Сообщения из ресторанов' },
}
const tgSettings = ref({})
const tgLoading = ref(false)
const tgTesting = ref(false)
const tgUnlinking = ref(false)

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
  } catch { toast.error('Ошибка', 'Не удалось сохранить') }
}

async function sendTgTest() {
  tgTesting.value = true
  try {
    const { data, error } = await db.rpc('telegram_test', {})
    if (data?.success) toast.success('Отправлено', 'Проверьте Telegram')
    else toast.error('Не дошло', data?.error || error?.message || 'Telegram не принял сообщение')
  } catch (e) { toast.error('Не дошло', e.message) }
  finally { tgTesting.value = false }
}

async function unlinkTg() {
  if (!confirm('Отвязать Telegram? Уведомления перестанут приходить, пока не подключите бота заново.')) return
  tgUnlinking.value = true
  try {
    await db.rpc('telegram_unlink', {})
    if (userStore.currentUser) userStore.currentUser.telegram_connected = false
    toast.info('Отвязано', 'Подключить снова можно через бота')
  } catch { toast.error('Ошибка', 'Не удалось отвязать') }
  finally { tgUnlinking.value = false }
}

// ───────────────────────────── Push ─────────────────────────────
async function togglePush() {
  if (push.busy.value) return
  if (push.isSubscribed.value) {
    const ok = await push.unsubscribe()
    if (ok) toast.info('Уведомления выключены', 'На этом устройстве больше не приходят')
    return
  }
  const ok = await push.subscribe()
  if (ok) toast.success('Уведомления включены', 'Сейчас придёт проверочное')
  else if (push.error.value) toast.error('Не получилось', push.error.value)
}

// ────────────────────── Сеансы и своя история ──────────────────────
const sessions = ref([])
const sesLoading = ref(false)
const activity = ref([])
const actLoading = ref(false)

function describeAgent(ua) {
  const s = String(ua || '')
  const browser = /Edg\//.test(s) ? 'Edge'
    : /Chrome\//.test(s) ? 'Chrome'
    : /Firefox\//.test(s) ? 'Firefox'
    : /Safari\//.test(s) ? 'Safari' : 'Браузер'
  const os = /Windows/.test(s) ? 'Windows'
    : /Android/.test(s) ? 'Android'
    : /iPhone|iPad/.test(s) ? 'iPhone'
    : /Mac OS/.test(s) ? 'Mac'
    : /Linux/.test(s) ? 'Linux' : ''
  return os ? `${browser} · ${os}` : browser
}

async function loadSessions() {
  sesLoading.value = true
  try {
    const { data } = await db.rpc('my_sessions', {})
    sessions.value = data?.sessions || []
  } catch { sessions.value = [] }
  finally { sesLoading.value = false }
}

async function revokeOne(id) {
  try {
    await db.rpc('revoke_my_session', { id })
    await loadSessions()
    toast.info('Сеанс закрыт', 'На том устройстве потребуется вход заново')
  } catch { toast.error('Ошибка', 'Не удалось закрыть') }
}

async function revokeOthers() {
  if (!confirm('Выйти на всех устройствах, кроме этого?')) return
  try {
    const { data } = await db.rpc('revoke_my_session', { all: true })
    await loadSessions()
    toast.success('Готово', `Закрыто сеансов: ${data?.closed ?? 0}`)
  } catch { toast.error('Ошибка', 'Не удалось закрыть') }
}

async function loadActivity() {
  actLoading.value = true
  try {
    const { data } = await db.rpc('my_activity', { limit: 50 })
    activity.value = data?.items || []
  } catch { activity.value = [] }
  finally { actLoading.value = false }
}

// Сеансы и журнал грузим при первом заходе на вкладку, а не сразу:
// нужны редко, а запросов на открытие страницы и так хватает.
watch(activeTab, (t) => {
  if (t === 'security' && !sessions.value.length && !sesLoading.value) {
    loadSessions()
    loadActivity()
  }
}, { immediate: true })

onMounted(() => {
  loadTgSettings()
  loadPrefs()
})
</script>

<style scoped>
.uset { padding: 24px 32px; }

/* Профиль */
.uset-profile { display: flex; align-items: center; gap: 16px; margin-bottom: 24px; }
.uset-avatar { width: 56px; height: 56px; border-radius: 50%; background: linear-gradient(135deg, var(--bk-brown), #c4956a); color: #fff; font-weight: 700; font-size: 20px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.uset-name { font-size: 20px; font-weight: 700; }
.uset-role { font-size: 13px; color: var(--text-muted); margin-top: 1px; }
.uset-meta { display: flex; gap: 12px; margin-top: 4px; flex-wrap: wrap; }
.uset-meta-item { font-size: 12px; color: var(--text-muted); }
.uset-tg-badge { padding: 2px 8px; border-radius: 10px; background: #E8F5E9; color: #2E7D32; font-weight: 600; }
.uset-tg-off { background: #FFF3E0; color: #E65100; }
.uset-tg-off a { color: inherit; text-decoration: none; }

/* Раскладка со вкладками */
.uset-layout { display: grid; grid-template-columns: 220px 1fr; gap: 24px; align-items: start; }
.uset-tabs { display: flex; flex-direction: column; gap: 2px; position: sticky; top: 16px; }
.uset-tab {
  display: flex; align-items: center; gap: 10px;
  padding: 11px 14px; border: 0; border-radius: 10px;
  background: none; color: var(--text-muted); cursor: pointer;
  font-family: inherit; font-size: 14px; font-weight: 600; text-align: left;
}
.uset-tab:hover { background: rgba(139,115,85,0.08); color: var(--text); }
.uset-tab.active { background: var(--card); color: var(--text); box-shadow: inset 3px 0 0 var(--bk-brown); }
.uset-pane { display: flex; flex-direction: column; gap: 20px; min-width: 0; }

/* Карточки */
.uset-card { background: var(--card); border: 1px solid var(--border-light); border-radius: 12px; overflow: hidden; }
.uset-card-header { display: flex; gap: 12px; padding: 16px 20px; border-bottom: 1px solid var(--border-light); align-items: flex-start; }
.uset-card-icon { flex-shrink: 0; margin-top: 2px; }
.uset-card-title { font-size: 15px; font-weight: 700; }
.uset-card-desc { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.uset-card-desc a { color: var(--bk-brown); }
.uset-card-actions { display: flex; gap: 8px; padding: 12px 20px 16px; flex-wrap: wrap; border-top: 1px solid var(--border-light); }

/* Формы */
.uset-form { padding: 16px 20px; display: flex; flex-direction: column; gap: 14px; }
.uset-field { display: flex; flex-direction: column; gap: 6px; }
.uset-field-label { font-size: 12px; font-weight: 700; color: var(--text-muted); }
.uset-field select { padding: 9px 12px; border: 1.5px solid var(--border); border-radius: 10px; font-family: inherit; font-size: 14px; background: var(--card); }
.uset-error { color: var(--error, #C1502E); font-size: 13px; }
.uset-hint { padding: 16px 20px; color: var(--text-muted); font-size: 13px; }
.uset-hint-inline { color: var(--text-muted); font-size: 13px; margin: 0; }
.uset-sub { display: block; font-size: 11.5px; color: var(--text-muted); font-weight: 500; margin-top: 2px; }
.uset-badge { font-size: 11px; font-weight: 700; color: #2e7d32; background: #e8f5e9; padding: 3px 10px; border-radius: 20px; white-space: nowrap; }

/* Списки */
.uset-list { padding: 4px 0; max-height: 520px; overflow-y: auto; }
.uset-list-item { display: flex; align-items: center; gap: 10px; padding: 9px 20px; cursor: pointer; transition: background 0.1s; }
.uset-list-item:hover { background: rgba(139,115,85,0.05); }
.uset-list-label { font-size: 14px; flex: 1; min-width: 0; }
.uset-list-static { cursor: default; }
.uset-list-static:hover { background: none; }

/* Переключатель */
.uset-switch { width: 36px; height: 20px; border-radius: 10px; background: #ccc; position: relative; transition: background 0.2s; flex-shrink: 0; }
.uset-switch.on { background: var(--bk-brown); }
.uset-switch-thumb { width: 16px; height: 16px; border-radius: 50%; background: #fff; position: absolute; top: 2px; left: 2px; transition: left 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
.uset-switch.on .uset-switch-thumb { left: 18px; }

/* Кнопки */
.uset-btn {
  padding: 9px 16px; border: 1.5px solid var(--border); border-radius: 999px;
  background: var(--card); color: var(--text);
  font-family: inherit; font-size: 13px; font-weight: 700; cursor: pointer;
}
.uset-btn:hover { border-color: var(--bk-brown); color: var(--bk-brown); }
.uset-btn:disabled { opacity: .6; cursor: default; }
.uset-btn-text { background: none; border: none; color: var(--bk-brown); font-size: 13px; font-weight: 600; cursor: pointer; padding: 0; margin-left: auto; white-space: nowrap; }
.uset-btn-text:hover { text-decoration: underline; }
.uset-btn-primary { display: inline-block; padding: 9px 20px; background: var(--bk-brown); color: #fff; border: 0; border-radius: 8px; font-family: inherit; font-size: 14px; font-weight: 600; text-decoration: none; cursor: pointer; }
.uset-btn-primary:hover { opacity: 0.9; }
.uset-btn-primary:disabled { opacity: .6; cursor: default; }
.uset-unpin {
  padding: 6px 12px; border: 1.5px solid var(--border); border-radius: 999px;
  background: none; color: var(--text-muted);
  font-family: inherit; font-size: 12px; font-weight: 700; cursor: pointer; white-space: nowrap;
}
.uset-unpin:hover { border-color: var(--bk-brown); color: var(--bk-brown); }

/* Telegram и push */
.uset-empty-tg { padding: 32px 20px; text-align: center; }
.uset-empty-tg-icon { display: flex; justify-content: center; margin-bottom: 12px; }
.uset-push-denied {
  margin: 12px 20px 16px; padding: 10px 12px; border-radius: 10px;
  background: rgba(255,170,0,.14); color: #7a5200; font-size: 13px; line-height: 1.45;
}
.uset-push-error { margin: 0 20px 16px; color: var(--error, #C1502E); font-size: 13px; }

.uset-loading { padding: 20px; text-align: center; color: var(--text-muted); font-size: 13px; }

@media (max-width: 900px) {
  .uset { padding: 16px; }
  .uset-layout { grid-template-columns: 1fr; }
  /* Вкладки едут строкой. flex: 0 0 auto обязателен: иначе кнопки
     сжимаются до нуля и подписи налезают друг на друга. */
  .uset-tabs { flex-direction: row; overflow-x: auto; position: static; padding-bottom: 6px; gap: 6px; }
  .uset-tab { flex: 0 0 auto; white-space: nowrap; padding: 9px 14px; border: 1px solid var(--border-light); }
  .uset-tab.active { box-shadow: none; border-color: var(--bk-brown); color: var(--bk-brown); }

  /* Кнопка справа в шапке карточки на узком экране не помещается —
     переносим её на свою строку, а не выпускаем за край. Значок и заголовок
     при этом должны остаться в одной строке, иначе значок висит сам по себе. */
  .uset-card-header { flex-wrap: wrap; }
  .uset-card-header > .uset-card-icon { flex: 0 0 auto; }
  .uset-card-header > div:not(.uset-card-icon) { flex: 1 1 0; min-width: 0; }
  .uset-btn-text { margin-left: 0; width: 100%; text-align: left; padding-top: 4px; }

  /* Длинные строки списков (сеансы, действия) не должны выдавливать кнопку. */
  .uset-list-item { flex-wrap: wrap; row-gap: 6px; }
  .uset-list-label { flex: 1 1 100%; }
  .uset-list-item .uset-switch { order: -1; }
  .uset-unpin, .uset-badge { margin-left: auto; }
}
</style>
