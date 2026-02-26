<template>
  <div class="cards-page">

    <!-- Тех. работы -->
    <div v-if="maintenanceMode" class="mnt-overlay">
      <div class="mnt-bg">
        <div class="mnt-orb mnt-orb-1"></div>
        <div class="mnt-orb mnt-orb-2"></div>
      </div>
      <div class="mnt-card">
        <div class="mnt-icon">
          <svg viewBox="0 0 64 64" width="40" height="40" fill="none">
            <path d="M32 8L32 36" stroke="#FDBD10" stroke-width="5" stroke-linecap="round">
              <animate attributeName="opacity" values="1;.4;1" dur="2s" repeatCount="indefinite"/>
            </path>
            <circle cx="32" cy="48" r="4" fill="#FDBD10">
              <animate attributeName="opacity" values="1;.4;1" dur="2s" repeatCount="indefinite"/>
            </circle>
          </svg>
        </div>
        <h1 class="mnt-title">Технические работы</h1>
        <p class="mnt-msg" v-if="maintenanceMessage">{{ maintenanceMessage }}</p>
        <p class="mnt-msg" v-else>Система временно недоступна.<br>Мы проводим плановое обслуживание и скоро вернёмся.</p>
        <router-link to="/" class="mnt-home">← На главную</router-link>
      </div>
    </div>

    <!-- Hero-секция с поиском -->
    <header class="hero">
      <!-- Меню по центру -->
      <nav class="hero-nav">
        <div class="nav-links">
          <router-link to="/" class="nav-link">Главная</router-link>
          <a href="https://docs.google.com/spreadsheets/d/1120BAXbfgI6YK66DGk-e-Z_ocqXqp4M_Rxp6qHhibek/edit?gid=0#gid=0" target="_blank" class="nav-link">Овощи</a>
          <a href="https://docs.google.com/spreadsheets/d/1LymUxkYXmhx2sEta8qI9qj1IohgS2z79IBZ7EMlKeLc/edit?gid=378091923#gid=378091923" target="_blank" class="nav-link">Планета ресторанов</a>
          <a href="https://docs.google.com/spreadsheets/d/1dv-s5Rqe9Hgyg1fbPeCWEwh0MaKMNkj7JK_gFyxPDdU/edit?pli=1&gid=0#gid=0" target="_blank" class="nav-link">График поставок</a>
          <a href="https://docs.google.com/spreadsheets/d/1ToILNXjzvBwvyRm8687h-RJrUA3RuffMx3vJuCdF-xQ/edit?gid=0#gid=0" target="_blank" class="nav-link">Контакты поставщиков</a>
        </div>
      </nav>

      <!-- Заголовок -->
      <div class="hero-content">
        <h1 class="hero-title">Поиск карточек</h1>
        <p class="hero-subtitle">Введите старый артикул или название — найдём актуальную карточку</p>

        <!-- Поиск -->
        <div class="search-wrap" ref="searchWrapRef">
          <div v-if="loading" class="hero-status"><span class="hero-spinner"></span> Загрузка базы...</div>
          <p v-else-if="loadError" class="hero-status hero-error">{{ loadError }}</p>
          <div v-else class="search-box">
            <div class="search-field">
              <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
              <input
                ref="searchInputEl"
                v-model="query"
                type="text"
                class="search-input"
                placeholder="Артикул или название товара..."
                autocomplete="off"
                @input="onInput"
                @keydown.enter="doSearch"
                @keydown.escape="closeAutocomplete"
                @keydown.down.prevent="navigateAC(1)"
                @keydown.up.prevent="navigateAC(-1)"
                @focus="tryShowAC"
              />
              <button v-if="query" class="clear-btn" @click="clearSearch" title="Очистить">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
              </button>
            </div>
            <button class="search-btn" @click="doSearch">
              <span class="search-btn-text">Найти</span>
              <svg class="search-btn-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
            </button>

            <!-- Автокомплит -->
            <div v-if="showAC && acItems.length" class="ac-dropdown">
              <div
                v-for="(item, i) in acItems"
                :key="item.id"
                class="ac-item"
                :class="{ active: i === acIndex }"
                @mousedown.prevent="selectAC(item)"
              >
                <span class="ac-article">{{ item.id }}</span>
                <span class="ac-name">{{ item.name }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Декоративная волна -->
      <div class="hero-wave">
        <svg viewBox="0 0 1440 80" preserveAspectRatio="none"><path d="M0,40 C360,80 720,0 1080,40 C1260,60 1380,50 1440,40 L1440,80 L0,80 Z" fill="#f7f5f2"/></svg>
      </div>
    </header>

    <!-- Результаты -->
    <main class="main-content">
      <div class="results-area">
        <!-- Найдено -->
        <div v-if="searched && results.length" class="results-list">
          <div class="results-header">
            <span class="results-count">{{ results.length }} {{ results.length === 1 ? 'результат' : results.length < 5 ? 'результата' : 'результатов' }}</span>
          </div>
          <div
            v-for="card in results"
            :key="card.id + card.reason"
            class="result-card"
            :class="{ copied: copiedId === card.id + card.reason }"
            @click="copyCard(card, $event)"
          >
            <div class="result-main">
              <span class="result-article">{{ card.id }}</span>
              <span class="result-name">{{ card.name }}</span>
            </div>
            <div class="result-footer">
              <span class="result-reason">{{ card.reason }}</span>
              <span class="result-copy-hint">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                Скопировать
              </span>
            </div>
          </div>
        </div>

        <!-- Не найдено -->
        <div v-else-if="searched && !results.length" class="not-found">
          <div class="not-found-icon">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#c4b5a6" stroke-width="1.5" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/><path d="M8 11h6"/></svg>
          </div>
          <p class="not-found-title">Ничего не найдено</p>
          <p class="not-found-text">Карточка не найдена. Возможно, она не имеет аналогов или её ещё нет в базе.</p>
        </div>
      </div>
    </main>

    <!-- Кнопка «База данных» -->
    <div class="admin-access" v-if="!adminOpen">
      <button v-if="!isAdmin" class="fab-btn" @click="showAdminLogin = !showAdminLogin" title="База данных">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4.03 3 9 3s9-1.34 9-3V5"/><path d="M3 12c0 1.66 4.03 3 9 3s9-1.34 9-3"/></svg>
      </button>
      <button v-else class="fab-btn fab-active" @click="openAdmin" title="База данных">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4.03 3 9 3s9-1.34 9-3V5"/><path d="M3 12c0 1.66 4.03 3 9 3s9-1.34 9-3"/></svg>
      </button>
      <!-- Форма логина -->
      <Transition name="login-fade">
        <div v-if="showAdminLogin && !isAdmin" class="login-popup">
          <div class="login-popup-title">Вход в базу данных</div>
          <select v-model="adminUserName" class="field-input">
            <option value="" disabled>Пользователь</option>
            <option v-for="u in userList" :key="u.name" :value="u.name">{{ u.name }}</option>
          </select>
          <input
            v-model="adminPassword"
            type="password"
            class="field-input"
            placeholder="Пароль"
            @keydown.enter="loginAdmin"
          />
          <div class="login-actions">
            <button @click="loginAdmin" class="btn-primary btn-sm">Войти</button>
            <button @click="showAdminLogin = false" class="btn-ghost btn-sm">Отмена</button>
          </div>
        </div>
      </Transition>
    </div>

    <!-- Админ-панель -->
    <Transition name="panel-slide">
      <div v-if="adminOpen" class="admin-panel">
        <div class="admin-panel-header">
          <h2>База данных</h2>
          <button class="close-btn" @click="closeAdmin">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
          </button>
        </div>

        <!-- Вкладки -->
        <div class="admin-tabs">
          <button class="tab-btn" :class="{ active: adminTab === 'add' }" @click="adminTab = 'add'">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            Добавить
          </button>
          <button class="tab-btn" :class="{ active: adminTab === 'edit' }" @click="adminTab = 'edit'">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
            Редактировать
          </button>
          <button class="tab-btn" :class="{ active: adminTab === 'audit' }" @click="adminTab = 'audit'">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>
            Аудит
          </button>
        </div>

        <!-- Вкладка: Добавить -->
        <div v-if="adminTab === 'add'" class="tab-content">
          <form @submit.prevent="addCard" class="admin-form">
            <div class="form-group">
              <label>Артикул</label>
              <input v-model="newCard.id" type="text" required placeholder="123456" class="field-input" />
            </div>
            <div class="form-group">
              <label>Название товара</label>
              <input v-model="newCard.name" type="text" required placeholder="Куриные наггетсы" class="field-input" />
            </div>
            <div class="form-group">
              <label>Аналоги <span class="label-hint">через запятую</span></label>
              <input v-model="newCard.analogs" type="text" placeholder="наггетсы, курица, 654321" class="field-input" />
            </div>
            <button type="submit" class="btn-primary">Добавить карточку</button>
          </form>
        </div>

        <!-- Вкладка: Редактировать -->
        <div v-if="adminTab === 'edit'" class="tab-content">
          <div class="search-edit-row">
            <input
              v-model="editSearchQuery"
              type="text"
              placeholder="Поиск по артикулу или названию"
              class="field-input"
              @keydown.enter="searchForEdit"
            />
            <button @click="searchForEdit" class="btn-primary btn-sm">Найти</button>
          </div>

          <!-- Список найденных карточек -->
          <div v-if="editResults.length && !editingCard" class="edit-card-list">
            <div v-for="card in editResults" :key="card.id" class="edit-card-item">
              <div class="edit-card-info">
                <div class="edit-card-top">
                  <span class="edit-card-id">{{ card.id }}</span>
                  <span class="edit-card-name">{{ card.name }}</span>
                </div>
                <div class="edit-card-analogs" v-if="card.analogs.length">{{ card.analogs.join(', ') }}</div>
              </div>
              <button @click="startEdit(card)" class="btn-ghost btn-sm">Изменить</button>
            </div>
          </div>

          <!-- Форма редактирования -->
          <div v-if="editingCard" class="edit-form">
            <form @submit.prevent="updateCard" class="admin-form">
              <div class="form-group">
                <label>Артикул</label>
                <input v-model="editForm.id" type="text" required class="field-input" />
              </div>
              <div class="form-group">
                <label>Название товара</label>
                <input v-model="editForm.name" type="text" required class="field-input" />
              </div>
              <div class="form-group">
                <label>Аналоги <span class="label-hint">через запятую</span></label>
                <input v-model="editForm.analogs" type="text" class="field-input" />
              </div>
              <div class="edit-actions">
                <button type="submit" class="btn-primary">Сохранить</button>
                <button type="button" class="btn-danger" @click="deleteCard">Удалить</button>
                <button type="button" class="btn-ghost" @click="cancelEdit">Отмена</button>
              </div>
            </form>
          </div>
        </div>

        <!-- Вкладка: Аудит -->
        <div v-if="adminTab === 'audit'" class="tab-content">
          <div v-if="auditLoading && !auditLogs.length" class="audit-loading">
            <div class="audit-loading-spinner"></div>
            Загрузка статистики...
          </div>
          <template v-else-if="auditLoaded">
            <!-- Статистика в одну строку -->
            <div class="audit-summary">
              <div class="summary-item">
                <span class="summary-num">{{ auditStats.total }}</span>
                <span class="summary-text">запросов</span>
              </div>
              <div class="summary-divider"></div>
              <div class="summary-item summary-found">
                <span class="summary-num">{{ auditStats.found }}</span>
                <span class="summary-text">найдено</span>
              </div>
              <div class="summary-divider"></div>
              <div class="summary-item summary-notfound">
                <span class="summary-num">{{ auditStats.notFound }}</span>
                <span class="summary-text">не найдено</span>
              </div>
              <div class="summary-divider"></div>
              <div class="summary-item">
                <span class="summary-num">{{ auditStats.total ? Math.round(auditStats.found / auditStats.total * 100) : 0 }}%</span>
                <span class="summary-text">успех</span>
              </div>
            </div>

            <!-- Топ-5 запросов -->
            <div v-if="auditStats.topQueries.length" class="audit-top-card">
              <div class="top-card-header">
                <span class="top-card-title">Топ-5 запросов</span>
                <span class="top-card-hint">за последние {{ auditLimit }} записей</span>
              </div>
              <div class="top-card-list">
                <div v-for="(item, i) in auditStats.topQueries" :key="item.query" class="top-card-row">
                  <span class="top-card-medal" :class="'medal-' + (i + 1)">{{ i + 1 }}</span>
                  <span class="top-card-query">{{ item.query }}</span>
                  <div class="top-card-bar-wrap">
                    <div class="top-card-bar" :style="{ width: (item.count / auditStats.topQueries[0].count * 100) + '%' }"></div>
                  </div>
                  <span class="top-card-count">{{ item.count }}</span>
                </div>
              </div>
            </div>

            <div v-if="!auditStats.topQueries.length" class="audit-empty">Нет данных для отображения</div>

            <!-- Список всех запросов -->
            <div class="audit-log-section">
              <div class="audit-log-header">
                <span class="audit-log-title">Все запросы</span>
                <div class="audit-log-filters">
                  <select v-model="auditFilter.status" class="audit-mini-select">
                    <option value="">Все</option>
                    <option value="found">Найдено</option>
                    <option value="notfound">Не найдено</option>
                  </select>
                </div>
              </div>
              <div class="audit-log-list">
                <div v-for="log in filteredAuditLogs" :key="log.id" class="audit-log-row">
                  <span class="audit-log-dot" :class="log.found ? 'dot-found' : 'dot-notfound'"></span>
                  <span class="audit-log-query">{{ log.query }}</span>
                  <span v-if="log.match_type" class="audit-log-type">{{ formatMatchType(log.match_type) }}</span>
                  <span class="audit-log-date">{{ formatAuditDate(log.created_at) }}</span>
                </div>
                <div v-if="!filteredAuditLogs.length" class="audit-empty">Нет записей</div>
              </div>
              <button
                v-if="auditLogs.length >= auditLimit"
                class="audit-load-more"
                :disabled="auditLoading"
                @click="loadMoreAuditLogs"
              >
                {{ auditLoading ? 'Загрузка...' : 'Ещё' }}
              </button>
            </div>
          </template>
        </div>
      </div>
    </Transition>

    <!-- Overlay -->
    <Transition name="overlay-fade">
      <div v-if="adminOpen" class="admin-overlay" @click="closeAdmin"></div>
    </Transition>

    <!-- Футер -->
    <footer class="page-footer">
      <div v-if="guestCount > 0" class="footer-item footer-guests">
        <span class="guest-dot"></span>
        {{ guestCount }} {{ guestCount === 1 ? 'гость' : guestCount < 5 ? 'гостя' : 'гостей' }}
      </div>
      <span v-if="guestCount > 0 && lastUpdate" class="footer-dot">·</span>
      <div v-if="lastUpdate" class="footer-item">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        Обновлено: {{ lastUpdate }}
      </div>
      <span class="footer-dot">·</span>
      <a href="https://t.me/alexiskozlov" target="_blank" class="footer-item footer-link">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        Нашли ошибку?
      </a>
    </footer>

    <!-- Toast -->
    <Transition name="toast-slide">
      <div v-if="toastVisible" class="toast" :class="'toast-' + toastType">
        <span class="toast-msg">{{ toastMessage }}</span>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useUserStore } from '../stores/userStore'

const API_BASE = '/api'
const userStore = useUserStore()

// --- Состояние ---
const allCards = ref([])
const query = ref('')
const results = ref([])
const searched = ref(false)
const loading = ref(true)
const loadError = ref('')
const lastUpdate = ref('')
const copiedId = ref(null)
const guestCount = ref(0)

// Тех. работы
const maintenanceMode = ref(false)
const maintenanceMessage = ref('')

// Toast
const toastVisible = ref(false)
const toastMessage = ref('')
const toastType = ref('info')
let toastTimer = null
let heartbeatInterval = null

// Гостевая сессия
const guestSessionId = (() => {
  let id = sessionStorage.getItem('bk_guest_sid')
  if (!id) {
    id = Math.random().toString(36).slice(2) + Date.now().toString(36)
    sessionStorage.setItem('bk_guest_sid', id)
  }
  return id
})()

// Автокомплит
const showAC = ref(false)
const acIndex = ref(-1)
const searchInputEl = ref(null)
const searchWrapRef = ref(null)

// --- Проверка тех. работ ---
let maintenanceTimer = null
async function checkMaintenance() {
  try {
    const res = await fetch(`${API_BASE}/rpc/check_maintenance`, { method: 'POST' })
    if (!res.ok) return
    const data = await res.json()
    maintenanceMode.value = !!data?.maintenance_mode
    maintenanceMessage.value = data?.maintenance_message || ''
  } catch { /* ignore */ }
}

// --- Нормализация (точная копия оригинала) ---
function normalize(str) {
  return str
    .toLowerCase()
    .replace(/ё/g, 'е')
    .replace(/[^a-zа-я0-9]/gi, '')
}

// --- Загрузка карточек ---
async function loadCards() {
  loading.value = true
  loadError.value = ''
  try {
    const res = await fetch(`${API_BASE}/rpc/get_cards`)
    if (!res.ok) throw new Error(`HTTP ${res.status}`)
    const data = await res.json()
    allCards.value = data.map(c => {
      const analogs = typeof c.analogs === 'string' ? JSON.parse(c.analogs) : (c.analogs || [])
      return {
        id: c.id?.toString() || '',
        name: c.name || '',
        analogs,
        _normId: normalize(c.id?.toString() || ''),
        _normName: normalize(c.name || ''),
        _normAnalogs: analogs.map(a => normalize(a)),
        _normFull: normalize(`${c.id || ''} ${c.name || ''}`)
      }
    })
  } catch (e) {
    loadError.value = 'Ошибка загрузки карточек: ' + e.message
  } finally {
    loading.value = false
  }
}

// --- Загрузка даты обновления ---
async function loadLastUpdate() {
  try {
    const res = await fetch(`${API_BASE}/rpc/get_cards_last_update`)
    if (res.ok) {
      const data = await res.json()
      if (data.value) lastUpdate.value = data.value
    }
  } catch {
    // Не критично
  }
}

// --- Логирование поиска ---
async function logSearch(queryStr, found, matchType, matchedCardId) {
  try {
    await fetch(`${API_BASE}/rpc/log_card_search`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        query: queryStr,
        found,
        match_type: matchType,
        matched_card_id: matchedCardId || null
      })
    })
  } catch {
    // Не критично
  }
}

// --- Гостевой heartbeat ---
async function sendGuestHeartbeat() {
  try {
    await fetch(`${API_BASE}/rpc/guest_heartbeat`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ session_id: guestSessionId, page: 'search-cards' })
    })
    const res = await fetch(`${API_BASE}/rpc/get_guest_count`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: '{}'
    })
    if (res.ok) {
      const data = await res.json()
      guestCount.value = data.cnt || 0
    }
  } catch {
    // Не критично
  }
}

// --- Автокомплит ---
const acItems = ref([])

function computeAC() {
  const q = normalize(query.value)
  if (q.length < 2) { acItems.value = []; return }

  const matches = []
  for (const c of allCards.value) {
    if (
      c._normId.includes(q) ||
      c._normName.includes(q) ||
      c._normAnalogs.some(a => a.includes(q))
    ) {
      matches.push(c)
    }
    if (matches.length >= 5) break
  }
  acItems.value = matches
}

function onInput() {
  acIndex.value = -1
  computeAC()
  showAC.value = query.value.length >= 2 && acItems.value.length > 0
}

function tryShowAC() {
  computeAC()
  showAC.value = query.value.length >= 2 && acItems.value.length > 0
}

function navigateAC(dir) {
  if (!showAC.value || !acItems.value.length) return
  acIndex.value = (acIndex.value + dir + acItems.value.length) % acItems.value.length
}

function selectAC(item) {
  query.value = item.id
  showAC.value = false
  acIndex.value = -1
  doSearch()
}

function closeAutocomplete() {
  showAC.value = false
  acIndex.value = -1
}

// --- Основной поиск (повторяет логику оригинала) ---
function doSearch() {
  // Выбор из автокомплита
  if (showAC.value && acIndex.value >= 0) {
    selectAC(acItems.value[acIndex.value])
    return
  }
  showAC.value = false

  const queryRaw = query.value.trim()
  if (queryRaw.length < 3) {
    showToast('Введите минимум 3 символа', 'warning')
    return
  }

  // Скрыть клавиатуру на мобильных
  if (window.innerWidth <= 768) searchInputEl.value?.blur()

  searched.value = true
  const q = normalize(queryRaw)
  const articleMatch = queryRaw.match(/\d{5,}(?:-\d+)?/)

  if (articleMatch) {
    // --- Режим артикула ---
    const searchedArticle = articleMatch[0]
    let foundCard = null
    let reason = ''

    for (const c of allCards.value) {
      if (c.id === searchedArticle) {
        foundCard = c
        reason = 'найдено по артикулу'
        break
      }
      if (c.analogs.includes(searchedArticle)) {
        foundCard = c
        reason = 'найдено по аналогу артикула'
        break
      }
    }

    if (foundCard) {
      results.value = [{ ...foundCard, reason }]
      logSearch(queryRaw, true, 'article', foundCard.id)
      return
    }
    // Артикул не найден — продолжаем текстовый поиск (не выходим)
  }

  // --- Текстовый поиск ---
  const foundCards = []
  let matchType = null
  let matchedCardId = null

  for (const c of allCards.value) {
    const keyNorm = c._normId
    const nameNorm = c._normName
    const analogsNorm = c._normAnalogs
    const fullNorm = c._normFull

    // 1. Точное совпадение артикула
    if (keyNorm === q) {
      foundCards.push({ ...c, reason: 'точное совпадение' })
      matchType = 'direct'
      matchedCardId = c.id
      continue
    }

    // 2. Совпадение по «артикул + название»
    if (fullNorm.includes(q)) {
      foundCards.push({ ...c, reason: 'найдено по артикулу и названию' })
      if (!matchType) { matchType = 'full'; matchedCardId = c.id }
      continue
    }

    // 3. Частичное совпадение артикула
    if (keyNorm.includes(q)) {
      foundCards.push({ ...c, reason: 'часть артикула' })
      if (!matchType) { matchType = 'partial_id'; matchedCardId = c.id }
      continue
    }

    // 4. Совпадение по аналогу (точное)
    if (analogsNorm.includes(q)) {
      foundCards.push({ ...c, reason: 'найдено по аналогу' })
      if (!matchType) { matchType = 'analog'; matchedCardId = c.id }
      continue
    }

    // 5. Частичное совпадение по названию
    if (nameNorm.includes(q)) {
      foundCards.push({ ...c, reason: 'найдено по названию' })
      if (!matchType) { matchType = 'name'; matchedCardId = c.id }
      continue
    }
  }

  results.value = foundCards
  logSearch(queryRaw, foundCards.length > 0, matchType, matchedCardId)
}

// --- Копирование ---
async function copyCard(card, event) {
  const text = `${card.id} ${card.name}`
  try {
    await navigator.clipboard.writeText(text)
    // Визуальный эффект (как в оригинале — текст краснеет)
    copiedId.value = card.id + card.reason
    setTimeout(() => { copiedId.value = null }, 500)
    showToast(`Скопировано: ${text}`, 'success')
  } catch {
    showToast('Не удалось скопировать', 'error')
  }
}

function showToast(msg, type = 'info') {
  if (toastTimer) clearTimeout(toastTimer)
  toastMessage.value = msg
  toastType.value = type
  toastVisible.value = true
  toastTimer = setTimeout(() => { toastVisible.value = false }, 3000)
}

function clearSearch() {
  query.value = ''
  results.value = []
  searched.value = false
  showAC.value = false
  acIndex.value = -1
  searchInputEl.value?.focus()
}

// Закрытие автокомплита по клику снаружи
function handleClickOutside(e) {
  if (searchWrapRef.value && !searchWrapRef.value.contains(e.target)) {
    showAC.value = false
  }
}

// ═══════════════════════════════
// АДМИН-ПАНЕЛЬ (управление cards)
// ═══════════════════════════════

const isAdmin = ref(false)
const adminApiKey = ref('')
const showAdminLogin = ref(false)
const adminUserName = ref('')
const adminPassword = ref('')
const userList = ref([])
const adminOpen = ref(false)
const adminTab = ref('add')

// Добавление
const newCard = ref({ id: '', name: '', analogs: '' })

// Редактирование
const editSearchQuery = ref('')
const editResults = ref([])
const editingCard = ref(null)
const editForm = ref({ id: '', name: '', analogs: '' })

// Загрузка списка пользователей
async function loadUserList() {
  try {
    const res = await fetch(`${API_BASE}/rpc/get_user_list`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: '{}'
    })
    if (res.ok) userList.value = await res.json()
  } catch { /* ignore */ }
}

// Авторизация
async function loginAdmin() {
  if (!adminUserName.value || !adminPassword.value) {
    showToast('Заполните имя и пароль', 'warning')
    return
  }
  try {
    const res = await fetch(`${API_BASE}/rpc/check_user_password`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ user_name: adminUserName.value, user_password: adminPassword.value })
    })
    const data = await res.json()
    if (data.success) {
      isAdmin.value = true
      adminApiKey.value = data.api_key || ''
      showAdminLogin.value = false
      adminPassword.value = ''
      showToast('Добро пожаловать в базу данных!', 'success')
      openAdmin()
    } else {
      showToast('Неверный пароль', 'error')
    }
  } catch {
    showToast('Ошибка авторизации', 'error')
  }
}

function openAdmin() {
  adminOpen.value = true
}

function closeAdmin() {
  adminOpen.value = false
  editingCard.value = null
  editResults.value = []
}

// Заголовки для API-запросов с ключом
function adminHeaders() {
  return {
    'Content-Type': 'application/json',
    'X-API-Key': adminApiKey.value
  }
}

// Обновить дату last_update
async function updateLastUpdateDate() {
  const today = new Date().toLocaleDateString('ru-RU')
  lastUpdate.value = today
  try {
    await fetch(`${API_BASE}/settings?key=eq.last_update`, {
      method: 'PATCH',
      headers: adminHeaders(),
      body: JSON.stringify({ value: today })
    })
  } catch { /* ignore */ }
}

// Добавить карточку
async function addCard() {
  if (!newCard.value.id || !newCard.value.name) {
    showToast('Заполните артикул и название', 'error')
    return
  }
  const analogs = newCard.value.analogs
    ? newCard.value.analogs.split(',').map(a => a.trim()).filter(a => a)
    : []

  try {
    const res = await fetch(`${API_BASE}/cards`, {
      method: 'POST',
      headers: adminHeaders(),
      body: JSON.stringify({ id: newCard.value.id.trim(), name: newCard.value.name.trim(), analogs })
    })
    if (!res.ok) {
      const err = await res.json().catch(() => ({}))
      throw new Error(err.error || `HTTP ${res.status}`)
    }
    newCard.value = { id: '', name: '', analogs: '' }
    showToast('Карточка добавлена', 'success')
    await loadCards()
    await updateLastUpdateDate()
  } catch (e) {
    showToast('Ошибка: ' + e.message, 'error')
  }
}

// Поиск карточек для редактирования
function searchForEdit() {
  const q = editSearchQuery.value.toLowerCase().trim()
  if (!q) { editResults.value = []; return }
  editingCard.value = null

  editResults.value = allCards.value.filter(c =>
    c.id.toLowerCase().includes(q) ||
    c.name.toLowerCase().includes(q) ||
    c.analogs.some(a => a.toLowerCase().includes(q))
  )
}

// Начать редактирование
function startEdit(card) {
  editingCard.value = card
  editForm.value = {
    id: card.id,
    name: card.name,
    analogs: card.analogs.join(', ')
  }
}

function cancelEdit() {
  editingCard.value = null
}

// Сохранить изменения
async function updateCard() {
  if (!editForm.value.id || !editForm.value.name) {
    showToast('Заполните артикул и название', 'error')
    return
  }
  const oldId = editingCard.value.id
  const newId = editForm.value.id.trim()
  const analogs = editForm.value.analogs
    ? editForm.value.analogs.split(',').map(a => a.trim()).filter(a => a)
    : []

  try {
    // Если поменяли ID — удаляем старую, создаём новую
    if (oldId !== newId) {
      await fetch(`${API_BASE}/cards/${oldId}`, {
        method: 'DELETE',
        headers: adminHeaders()
      })
      await fetch(`${API_BASE}/cards`, {
        method: 'POST',
        headers: adminHeaders(),
        body: JSON.stringify({ id: newId, name: editForm.value.name.trim(), analogs })
      })
    } else {
      await fetch(`${API_BASE}/cards/${oldId}`, {
        method: 'PATCH',
        headers: adminHeaders(),
        body: JSON.stringify({ name: editForm.value.name.trim(), analogs })
      })
    }
    editingCard.value = null
    showToast('Карточка обновлена', 'success')
    await loadCards()
    await updateLastUpdateDate()
    searchForEdit()
  } catch (e) {
    showToast('Ошибка: ' + e.message, 'error')
  }
}

// Удалить карточку
async function deleteCard() {
  if (!editingCard.value) return
  if (!confirm(`Удалить карточку ${editingCard.value.id}?`)) return

  try {
    await fetch(`${API_BASE}/cards/${editingCard.value.id}`, {
      method: 'DELETE',
      headers: adminHeaders()
    })
    editingCard.value = null
    editSearchQuery.value = ''
    editResults.value = []
    showToast('Карточка удалена', 'success')
    await loadCards()
    await updateLastUpdateDate()
  } catch (e) {
    showToast('Ошибка: ' + e.message, 'error')
  }
}

// ═══════════════════════════════
// АУДИТ ЛОГОВ ПОИСКА
// ═══════════════════════════════

const auditLogs = ref([])
const auditLoading = ref(false)
const auditLoaded = ref(false)
const auditLimit = ref(50)
const auditFilter = ref({ text: '', status: '', dateFrom: '', dateTo: '' })
const auditStats = ref({ total: 0, found: 0, notFound: 0, topQueries: [] })

async function loadAuditLogs() {
  auditLoading.value = true
  try {
    let url = `${API_BASE}/search_logs?order=created_at.desc&limit=${auditLimit.value}`
    if (auditFilter.value.dateFrom) {
      url += `&created_at=gte.${auditFilter.value.dateFrom}T00:00:00`
    }
    if (auditFilter.value.dateTo) {
      url += `&created_at=lte.${auditFilter.value.dateTo}T23:59:59`
    }
    const res = await fetch(url, { headers: adminHeaders() })
    if (!res.ok) throw new Error(`HTTP ${res.status}`)
    const data = await res.json()
    // MySQL отдаёт found как "0"/"1" (строка), приводим к boolean
    for (const row of data) {
      row.found = !!Number(row.found)
    }
    auditLogs.value = data
    computeAuditStats(data)
    auditLoaded.value = true
  } catch (e) {
    showToast('Ошибка загрузки логов: ' + e.message, 'error')
  } finally {
    auditLoading.value = false
  }
}

async function loadMoreAuditLogs() {
  auditLimit.value += 50
  await loadAuditLogs()
}

function computeAuditStats(logs) {
  const total = logs.length
  const found = logs.filter(l => l.found).length
  const notFound = total - found

  // Топ запросов
  const freq = {}
  for (const l of logs) {
    const q = (l.query || '').toLowerCase().trim()
    if (q) freq[q] = (freq[q] || 0) + 1
  }
  const topQueries = Object.entries(freq)
    .sort((a, b) => b[1] - a[1])
    .slice(0, 5)
    .map(([query, count]) => ({ query, count }))

  auditStats.value = { total, found, notFound, topQueries }
}

const filteredAuditLogs = computed(() => {
  let logs = auditLogs.value
  const text = auditFilter.value.text.toLowerCase().trim()
  if (text) {
    logs = logs.filter(l => (l.query || '').toLowerCase().includes(text))
  }
  if (auditFilter.value.status === 'found') {
    logs = logs.filter(l => l.found)
  } else if (auditFilter.value.status === 'notfound') {
    logs = logs.filter(l => !l.found)
  }
  return logs
})

function formatMatchType(type) {
  const map = {
    article: 'по артикулу',
    analog: 'по аналогу',
    direct: 'точное совпадение',
    full: 'артикул + название',
    partial_id: 'часть артикула',
    name: 'по названию'
  }
  return map[type] || type
}

function formatAuditDate(str) {
  if (!str) return ''
  const d = new Date(str)
  const dd = String(d.getDate()).padStart(2, '0')
  const mm = String(d.getMonth() + 1).padStart(2, '0')
  const hh = String(d.getHours()).padStart(2, '0')
  const min = String(d.getMinutes()).padStart(2, '0')
  return `${dd}.${mm} ${hh}:${min}`
}

// Lazy-load аудита при первом переключении
watch(() => adminTab.value, (tab) => {
  if (tab === 'audit' && !auditLoaded.value) {
    loadAuditLogs()
  }
})

// Перезагрузка при изменении фильтров дат
watch(() => [auditFilter.value.dateFrom, auditFilter.value.dateTo], () => {
  if (auditLoaded.value) {
    auditLimit.value = 50
    loadAuditLogs()
  }
})

// Авто-логин из сессии основного сайта
function tryAutoLogin() {
  if (userStore.isAuthenticated) {
    const storedKey = localStorage.getItem('bk_api_key')
    if (storedKey) {
      isAdmin.value = true
      adminApiKey.value = storedKey
    }
  }
}

// ESC закрывает панель
function handleKeydown(e) {
  if (e.key === 'Escape') {
    if (adminOpen.value) closeAdmin()
    else if (showAdminLogin.value) showAdminLogin.value = false
  }
}

onMounted(() => {
  checkMaintenance()
  maintenanceTimer = setInterval(checkMaintenance, 60000)
  loadCards()
  loadLastUpdate()
  loadUserList()
  tryAutoLogin()
  sendGuestHeartbeat()
  heartbeatInterval = setInterval(sendGuestHeartbeat, 30000)
  document.addEventListener('click', handleClickOutside)
  document.addEventListener('keydown', handleKeydown)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleClickOutside)
  document.removeEventListener('keydown', handleKeydown)
  if (toastTimer) clearTimeout(toastTimer)
  if (heartbeatInterval) clearInterval(heartbeatInterval)
  if (maintenanceTimer) clearInterval(maintenanceTimer)
})
</script>

<style scoped>
/* ═══════════════════════════════════════════
   CARDS SEARCH — WOW REDESIGN
   Dark hero + fire gradient + glassmorphism
   ═══════════════════════════════════════════ */

.cards-page {
  min-height: 100vh;
  min-height: 100dvh;
  font-family: 'Plus Jakarta Sans', -apple-system, system-ui, sans-serif;
  color: #2C1810;
  display: flex;
  flex-direction: column;
  background: #f7f5f2;
  overflow-x: clip;
}

/* ═══ HERO ═══ */
.hero {
  position: relative;
  background: linear-gradient(135deg, #1A0E08 0%, #3D1F12 40%, #5C2D0E 70%, #D62300 100%);
  padding: 0 0 80px;
  overflow: visible;
}
.hero::before {
  content: '';
  position: absolute;
  inset: 0;
  background:
    radial-gradient(ellipse 600px 400px at 20% 50%, rgba(214,35,0,0.25), transparent),
    radial-gradient(ellipse 500px 350px at 80% 30%, rgba(255,135,50,0.2), transparent);
  pointer-events: none;
  -webkit-clip-path: inset(0);
  clip-path: inset(0);
}
.hero::after {
  content: '';
  position: absolute;
  top: -50%;
  right: -20%;
  width: 600px;
  height: 600px;
  background: radial-gradient(circle, rgba(255,135,50,0.08) 0%, transparent 70%);
  border-radius: 50%;
  pointer-events: none;
  animation: pulse-glow 6s ease-in-out infinite;
  -webkit-clip-path: inset(-100% -100% 0 0);
  clip-path: inset(-100% -100% 0 0);
}
@keyframes pulse-glow {
  0%, 100% { transform: scale(1); opacity: 0.5; }
  50% { transform: scale(1.15); opacity: 0.8; }
}

/* ═══ NAV ═══ */
.hero-nav {
  position: relative;
  z-index: 10;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 18px 24px;
}
.nav-links {
  display: flex;
  align-items: center;
  gap: 2px;
  padding: 4px;
  background: rgba(255,255,255,0.06);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 14px;
  overflow-x: auto;
  scrollbar-width: none;
  -webkit-overflow-scrolling: touch;
}
.nav-links::-webkit-scrollbar { display: none; }
.nav-link {
  padding: 8px 16px;
  border-radius: 10px;
  color: rgba(255,255,255,0.55);
  font-size: 0.8rem;
  font-weight: 600;
  text-decoration: none;
  white-space: nowrap;
  transition: all 0.15s;
}
.nav-link:hover {
  color: #fff;
  background: rgba(255,255,255,0.1);
}

/* ═══ HERO CONTENT ═══ */
.hero-content {
  position: relative;
  z-index: 10;
  max-width: 640px;
  margin: 0 auto;
  padding: 10vh 24px 0;
  text-align: center;
}
.hero-title {
  font-family: 'Flame', sans-serif;
  font-size: 2.4rem;
  font-weight: 700;
  color: #fff;
  margin: 0;
  letter-spacing: -0.5px;
  line-height: 1.1;
}
.hero-subtitle {
  color: rgba(255,255,255,0.5);
  font-size: 0.95rem;
  margin: 10px 0 0;
  font-weight: 400;
  line-height: 1.4;
}

/* ═══ SEARCH ═══ */
.search-wrap {
  margin-top: 32px;
  position: relative;
  z-index: 20;
}
.hero-status {
  color: rgba(255,255,255,0.6);
  font-size: 0.88rem;
  text-align: center;
  display: flex; align-items: center; justify-content: center; gap: 8px;
}
.hero-spinner {
  display: inline-block; width: 18px; height: 18px;
  border: 2.5px solid rgba(255,255,255,0.2);
  border-top-color: #FDBD10;
  border-radius: 50%;
  animation: hero-spin 0.7s linear infinite;
}
@keyframes hero-spin { to { transform: rotate(360deg); } }
.hero-error {
  color: #FF6B6B;
}
.search-box {
  position: relative;
}
.search-field {
  display: flex;
  align-items: center;
  background: rgba(255,255,255,0.12);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border: 1px solid rgba(255,255,255,0.15);
  border-radius: 16px;
  padding: 0 6px 0 16px;
  transition: all 0.25s;
}
.search-field:focus-within {
  background: rgba(255,255,255,0.18);
  border-color: rgba(255,255,255,0.3);
  box-shadow: 0 0 0 4px rgba(214,35,0,0.15), 0 8px 32px rgba(0,0,0,0.2);
}
.search-icon {
  color: rgba(255,255,255,0.4);
  flex-shrink: 0;
  transition: color 0.2s;
}
.search-field:focus-within .search-icon {
  color: rgba(255,255,255,0.7);
}
.search-input {
  flex: 1;
  padding: 14px 12px;
  background: none;
  border: none;
  outline: none;
  font-size: 15px;
  font-family: inherit;
  color: #fff;
  min-width: 0;
}
.search-input::placeholder {
  color: rgba(255,255,255,0.35);
}
.clear-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: 8px;
  background: rgba(255,255,255,0.08);
  border: none;
  color: rgba(255,255,255,0.5);
  cursor: pointer;
  transition: all 0.15s;
  flex-shrink: 0;
}
.clear-btn:hover {
  background: rgba(255,255,255,0.15);
  color: #fff;
}
.search-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 10px;
  margin-left: auto;
  margin-right: auto;
  padding: 11px 28px;
  background: linear-gradient(135deg, #D62300 0%, #FF5722 100%);
  color: #fff;
  border: none;
  border-radius: 12px;
  font-size: 14px;
  font-weight: 700;
  font-family: inherit;
  cursor: pointer;
  transition: all 0.2s;
  box-shadow: 0 4px 16px rgba(214,35,0,0.4);
}
.search-btn:hover {
  transform: translateY(-1px);
  box-shadow: 0 6px 24px rgba(214,35,0,0.5);
}
.search-btn:active {
  transform: translateY(0);
  box-shadow: 0 2px 8px rgba(214,35,0,0.3);
}
.search-btn-icon { display: none; }

/* ═══ AUTOCOMPLETE ═══ */
.ac-dropdown {
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  right: 0;
  background: rgba(30,15,8,0.95);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 14px;
  box-shadow: 0 12px 40px rgba(0,0,0,0.4);
  z-index: 100;
}
.ac-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 16px;
  cursor: pointer;
  transition: background 0.1s;
  text-align: left;
}
.ac-item:hover,
.ac-item.active {
  background: rgba(214,35,0,0.15);
}
.ac-article {
  font-weight: 700;
  color: #FF8732;
  font-size: 0.82rem;
  min-width: 76px;
  flex-shrink: 0;
  font-variant-numeric: tabular-nums;
}
.ac-name {
  color: rgba(255,255,255,0.7);
  font-size: 0.85rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* ═══ WAVE ═══ */
.hero-wave {
  position: absolute;
  bottom: -1px;
  left: 0;
  right: 0;
  z-index: 5;
  line-height: 0;
}
.hero-wave svg {
  width: 100%;
  height: 60px;
}

/* ═══ MAIN CONTENT ═══ */
.main-content {
  flex: 1;
  max-width: 640px;
  width: 100%;
  margin: 0 auto;
  padding: 12px 24px 80px;
}

/* ═══ RESULTS ═══ */
.results-header {
  margin-bottom: 12px;
}
.results-count {
  font-size: 0.8rem;
  font-weight: 600;
  color: #9B8B7E;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.results-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  animation: fadeUp 0.4s ease-out;
}
@keyframes fadeUp {
  from { opacity: 0; transform: translateY(20px); }
  to { opacity: 1; transform: translateY(0); }
}
.result-card {
  background: #fff;
  border: 1px solid #E8E0D6;
  border-radius: 14px;
  padding: 16px 18px;
  cursor: pointer;
  transition: all 0.2s;
  position: relative;
  overflow: hidden;
}
.result-card::before {
  content: '';
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  width: 4px;
  background: linear-gradient(180deg, #D62300, #FF8732);
  border-radius: 4px 0 0 4px;
  opacity: 0;
  transition: opacity 0.2s;
}
.result-card:hover {
  border-color: #D6230033;
  box-shadow: 0 4px 20px rgba(214,35,0,0.08);
  transform: translateY(-1px);
}
.result-card:hover::before {
  opacity: 1;
}
.result-card.copied {
  border-color: #D62300;
  box-shadow: 0 0 0 3px rgba(214,35,0,0.1);
}
.result-card.copied::before {
  opacity: 1;
}
.result-main {
  display: flex;
  align-items: baseline;
  gap: 10px;
  flex-wrap: wrap;
}
.result-article {
  font-weight: 800;
  font-size: 1rem;
  color: #D62300;
  font-variant-numeric: tabular-nums;
  letter-spacing: 0.3px;
}
.result-name {
  font-weight: 600;
  font-size: 0.95rem;
  color: #2C1810;
}
.result-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 6px;
}
.result-reason {
  font-size: 0.76rem;
  color: #9B8B7E;
  font-weight: 500;
}
.result-copy-hint {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 0.72rem;
  color: #c4b5a6;
  opacity: 0;
  transition: opacity 0.2s;
}
.result-card:hover .result-copy-hint {
  opacity: 1;
}

/* ═══ NOT FOUND ═══ */
.not-found {
  text-align: center;
  padding: 40px 20px;
  animation: fadeUp 0.4s ease-out;
}
.not-found-icon {
  margin-bottom: 16px;
  opacity: 0.6;
}
.not-found-title {
  font-family: 'Flame', sans-serif;
  font-size: 1.15rem;
  font-weight: 400;
  color: #502314;
  margin: 0 0 8px;
}
.not-found-text {
  font-size: 0.88rem;
  color: #9B8B7E;
  margin: 0;
  line-height: 1.5;
}

/* ═══ FAB (DB button) ═══ */
.admin-access {
  position: fixed;
  bottom: 56px;
  right: 20px;
  z-index: 90;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 10px;
}
.fab-btn {
  width: 48px;
  height: 48px;
  border-radius: 14px;
  background: linear-gradient(135deg, #2C1810, #502314);
  color: rgba(255,255,255,0.8);
  border: 1px solid rgba(255,255,255,0.1);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  box-shadow: 0 4px 20px rgba(0,0,0,0.25);
  transition: all 0.2s;
}
.fab-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 28px rgba(0,0,0,0.35);
  color: #fff;
}
.fab-btn.fab-active {
  background: linear-gradient(135deg, #D62300, #FF5722);
  color: #fff;
  box-shadow: 0 4px 20px rgba(214,35,0,0.4);
}
.fab-btn.fab-active:hover {
  box-shadow: 0 8px 28px rgba(214,35,0,0.5);
}

/* ═══ LOGIN POPUP ═══ */
.login-popup {
  background: #fff;
  padding: 18px;
  border-radius: 16px;
  box-shadow: 0 12px 40px rgba(44,24,16,0.15);
  display: flex;
  flex-direction: column;
  gap: 10px;
  min-width: 240px;
  border: 1px solid #E8E0D6;
}
.login-popup-title {
  font-family: 'Flame', sans-serif;
  font-size: 0.9rem;
  color: #502314;
  margin-bottom: 2px;
}
.login-actions {
  display: flex;
  gap: 6px;
}
.login-fade-enter-active, .login-fade-leave-active { transition: all 0.25s ease; }
.login-fade-enter-from, .login-fade-leave-to { opacity: 0; transform: translateY(8px) scale(0.95); }

/* ═══ SHARED FORM STYLES ═══ */
.field-input {
  width: 100%;
  padding: 10px 14px;
  border: 1.5px solid #E8E0D6;
  border-radius: 10px;
  font-size: 14px;
  font-family: inherit;
  color: #2C1810;
  outline: none;
  background: #fff;
  transition: border-color 0.15s, box-shadow 0.15s;
  box-sizing: border-box;
}
.field-input:focus {
  border-color: #D62300;
  box-shadow: 0 0 0 3px rgba(214,35,0,0.08);
}
select.field-input {
  cursor: pointer;
  -webkit-appearance: none;
  appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg width='12' height='8' viewBox='0 0 12 8' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1.5L6 6.5L11 1.5' stroke='%239B8B7E' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 12px center;
  padding-right: 36px;
}
.btn-primary {
  padding: 10px 20px;
  background: linear-gradient(135deg, #D62300 0%, #FF5722 100%);
  color: #fff;
  border: none;
  border-radius: 10px;
  font-size: 13px;
  font-weight: 700;
  font-family: inherit;
  cursor: pointer;
  transition: all 0.2s;
  box-shadow: 0 2px 8px rgba(214,35,0,0.2);
}
.btn-primary:hover {
  box-shadow: 0 4px 16px rgba(214,35,0,0.35);
  transform: translateY(-1px);
}
.btn-ghost {
  padding: 10px 16px;
  background: none;
  color: #6B5344;
  border: 1.5px solid #E8E0D6;
  border-radius: 10px;
  font-size: 13px;
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
  transition: all 0.15s;
}
.btn-ghost:hover {
  border-color: #9B8B7E;
  color: #2C1810;
}
.btn-danger {
  padding: 10px 16px;
  background: none;
  color: #D32F2F;
  border: 1.5px solid #D32F2F;
  border-radius: 10px;
  font-size: 13px;
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
  transition: all 0.15s;
}
.btn-danger:hover {
  background: #D32F2F;
  color: #fff;
}
.btn-sm {
  padding: 8px 14px;
  font-size: 12px;
}

/* ═══ ADMIN PANEL ═══ */
.admin-overlay {
  position: fixed;
  inset: 0;
  background: rgba(26,14,8,0.5);
  backdrop-filter: blur(4px);
  -webkit-backdrop-filter: blur(4px);
  z-index: 150;
}
.overlay-fade-enter-active, .overlay-fade-leave-active { transition: opacity 0.3s; }
.overlay-fade-enter-from, .overlay-fade-leave-to { opacity: 0; }

.admin-panel {
  position: fixed;
  top: 0;
  right: 0;
  width: 560px;
  max-width: 94vw;
  height: 100vh;
  height: 100dvh;
  background: #fff;
  z-index: 200;
  box-shadow: -8px 0 40px rgba(0,0,0,0.12);
  display: flex;
  flex-direction: column;
  overflow-y: auto;
}
.panel-slide-enter-active, .panel-slide-leave-active { transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1); }
.panel-slide-enter-from, .panel-slide-leave-to { transform: translateX(100%); }

.admin-panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 20px 22px 16px;
  border-bottom: 1px solid #E8E0D6;
}
.admin-panel-header h2 {
  font-family: 'Flame', sans-serif;
  font-size: 1.1rem;
  font-weight: 700;
  color: #2C1810;
  margin: 0;
}
.close-btn {
  width: 36px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f7f5f2;
  border: none;
  border-radius: 10px;
  color: #9B8B7E;
  cursor: pointer;
  transition: all 0.15s;
}
.close-btn:hover {
  background: #eee9e3;
  color: #D62300;
}

.admin-tabs {
  display: flex;
  padding: 0 22px;
  gap: 4px;
  border-bottom: 1px solid #E8E0D6;
}
.tab-btn {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 12px 8px;
  background: none;
  border: none;
  border-bottom: 2.5px solid transparent;
  font-size: 0.82rem;
  font-weight: 600;
  font-family: inherit;
  color: #9B8B7E;
  cursor: pointer;
  transition: all 0.15s;
}
.tab-btn.active {
  color: #D62300;
  border-bottom-color: #D62300;
}
.tab-btn:hover:not(.active) {
  color: #6B5344;
}

.tab-content {
  padding: 20px 22px;
  flex: 1;
}
.admin-form {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.form-group {
  text-align: left;
}
.form-group label {
  display: block;
  font-size: 0.76rem;
  font-weight: 700;
  color: #6B5344;
  margin-bottom: 5px;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.label-hint {
  font-weight: 400;
  text-transform: none;
  color: #9B8B7E;
  letter-spacing: 0;
}

/* ═══ ADMIN EDIT ═══ */
.search-edit-row {
  display: flex;
  gap: 8px;
  margin-bottom: 16px;
}
.edit-card-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  max-height: 340px;
  overflow-y: auto;
}
.edit-card-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 12px 14px;
  background: #faf8f5;
  border: 1px solid #F0EBE4;
  border-radius: 12px;
  transition: border-color 0.15s;
}
.edit-card-item:hover {
  border-color: #D6230033;
}
.edit-card-info {
  min-width: 0;
  flex: 1;
}
.edit-card-top {
  display: flex;
  align-items: baseline;
  gap: 8px;
  flex-wrap: wrap;
}
.edit-card-id {
  font-weight: 800;
  color: #D62300;
  font-size: 0.82rem;
  font-variant-numeric: tabular-nums;
}
.edit-card-name {
  font-size: 0.82rem;
  color: #2C1810;
  font-weight: 500;
}
.edit-card-analogs {
  font-size: 0.72rem;
  color: #9B8B7E;
  margin-top: 2px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.edit-form {
  margin-top: 14px;
}
.edit-actions {
  display: flex;
  gap: 8px;
  margin-top: 4px;
  flex-wrap: wrap;
}

/* ═══ AUDIT TAB ═══ */
.audit-loading {
  text-align: center;
  padding: 40px 0;
  color: #9B8B7E;
  font-size: 0.85rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
}
.audit-loading-spinner {
  width: 28px;
  height: 28px;
  border: 3px solid #F0EBE4;
  border-top-color: #D62300;
  border-radius: 50%;
  animation: hero-spin 0.7s linear infinite;
}

/* Сводка */
.audit-summary {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0;
  background: linear-gradient(135deg, #faf8f5, #f5f0e8);
  border: 1px solid #F0EBE4;
  border-radius: 14px;
  padding: 16px 8px;
  margin-bottom: 16px;
}
.summary-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  flex: 1;
  gap: 2px;
}
.summary-num {
  font-size: 1.3rem;
  font-weight: 800;
  color: #2C1810;
  line-height: 1.2;
  font-variant-numeric: tabular-nums;
}
.summary-text {
  font-size: 0.62rem;
  font-weight: 600;
  color: #9B8B7E;
  text-transform: uppercase;
  letter-spacing: 0.4px;
}
.summary-found .summary-num { color: #2E7D32; }
.summary-notfound .summary-num { color: #C62828; }
.summary-divider {
  width: 1px;
  height: 32px;
  background: #E8E0D6;
  flex-shrink: 0;
}

/* Топ-5 карточка */
.audit-top-card {
  background: #fff;
  border: 1px solid #F0EBE4;
  border-radius: 14px;
  overflow: hidden;
}
.top-card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  background: linear-gradient(135deg, #2C1810, #4A3228);
}
.top-card-title {
  font-size: 0.78rem;
  font-weight: 700;
  color: #fff;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.top-card-hint {
  font-size: 0.65rem;
  color: rgba(255,255,255,0.5);
  font-weight: 500;
}
.top-card-list {
  display: flex;
  flex-direction: column;
}
.top-card-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 11px 16px;
  border-bottom: 1px solid #F5F0EA;
  transition: background 0.15s;
}
.top-card-row:last-child {
  border-bottom: none;
}
.top-card-row:hover {
  background: #faf8f5;
}
.top-card-medal {
  width: 24px;
  height: 24px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  font-size: 0.72rem;
  font-weight: 800;
  flex-shrink: 0;
  background: #F0EBE4;
  color: #6B5344;
}
.medal-1 {
  background: linear-gradient(135deg, #FFD700, #FFC107);
  color: #5D4200;
  box-shadow: 0 2px 6px rgba(255,193,7,0.3);
}
.medal-2 {
  background: linear-gradient(135deg, #E0E0E0, #BDBDBD);
  color: #424242;
}
.medal-3 {
  background: linear-gradient(135deg, #FFAB76, #FF8A50);
  color: #5D3200;
}
.top-card-query {
  flex: 1;
  font-size: 0.84rem;
  font-weight: 600;
  color: #2C1810;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
}
.top-card-bar-wrap {
  width: 60px;
  height: 6px;
  background: #F0EBE4;
  border-radius: 3px;
  overflow: hidden;
  flex-shrink: 0;
}
.top-card-bar {
  height: 100%;
  background: linear-gradient(90deg, #D62300, #FF6B35);
  border-radius: 3px;
  transition: width 0.4s ease;
}
.top-card-count {
  font-size: 0.8rem;
  font-weight: 800;
  color: #D62300;
  font-variant-numeric: tabular-nums;
  min-width: 24px;
  text-align: right;
}

/* Список запросов */
.audit-log-section {
  margin-top: 16px;
}
.audit-log-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}
.audit-log-title {
  font-size: 0.72rem;
  font-weight: 700;
  color: #6B5344;
  text-transform: uppercase;
  letter-spacing: 0.4px;
}
.audit-mini-select {
  padding: 4px 8px;
  border: 1px solid #E8E0D6;
  border-radius: 6px;
  font-size: 0.72rem;
  color: #6B5344;
  background: #fff;
  cursor: pointer;
  outline: none;
}
.audit-mini-select:focus {
  border-color: #D62300;
}
.audit-log-list {
  display: flex;
  flex-direction: column;
  max-height: 360px;
  overflow-y: auto;
  border: 1px solid #F0EBE4;
  border-radius: 10px;
  background: #fff;
}
.audit-log-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 14px;
  border-bottom: 1px solid #F5F0EA;
  font-size: 0.82rem;
  transition: background 0.12s;
}
.audit-log-row:last-child {
  border-bottom: none;
}
.audit-log-row:hover {
  background: #faf8f5;
}
.audit-log-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  flex-shrink: 0;
}
.dot-found { background: #4CAF50; }
.dot-notfound { background: #EF5350; }
.audit-log-query {
  flex: 1;
  font-weight: 600;
  color: #2C1810;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
}
.audit-log-type {
  font-size: 0.68rem;
  color: #9B8B7E;
  font-weight: 500;
  flex-shrink: 0;
}
.audit-log-date {
  font-size: 0.68rem;
  color: #c4b5a6;
  font-variant-numeric: tabular-nums;
  flex-shrink: 0;
}
.audit-load-more {
  width: 100%;
  margin-top: 8px;
  padding: 8px;
  border: 1px dashed #E8E0D6;
  border-radius: 8px;
  background: transparent;
  color: #6B5344;
  font-size: 0.78rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s;
}
.audit-load-more:hover {
  border-color: #D62300;
  color: #D62300;
  background: rgba(214,35,0,0.03);
}
.audit-load-more:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.audit-empty {
  text-align: center;
  padding: 32px 0;
  color: #9B8B7E;
  font-size: 0.85rem;
}

/* ═══ FOOTER ═══ */
.page-footer {
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 10px 20px;
  background: rgba(247,245,242,0.88);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  border-top: 1px solid rgba(232,224,214,0.4);
  z-index: 50;
}
.footer-item {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 0.73rem;
  color: #9B8B7E;
  font-weight: 500;
}
.footer-dot {
  color: #c4b5a6;
  font-size: 0.8rem;
}
.footer-link {
  text-decoration: none;
  transition: color 0.15s;
}
.footer-link:hover {
  color: #D62300;
}
.footer-guests {
  color: #6B8E6B;
}
.guest-dot {
  display: inline-block;
  width: 7px;
  height: 7px;
  background: #4CAF50;
  border-radius: 50%;
  box-shadow: 0 0 6px rgba(76,175,80,0.5);
  animation: guest-pulse 2s ease-in-out infinite;
}
@keyframes guest-pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}

/* ═══ TOAST ═══ */
.toast {
  position: fixed;
  bottom: 56px;
  left: 50%;
  transform: translateX(-50%);
  padding: 10px 20px;
  border-radius: 12px;
  font-size: 0.85rem;
  font-weight: 600;
  z-index: 300;
  max-width: 90vw;
  white-space: nowrap;
  box-shadow: 0 8px 32px rgba(0,0,0,0.15);
}
.toast-success { background: #1A3A1A; color: #8FD694; }
.toast-error { background: #3A1A1A; color: #F07070; }
.toast-warning { background: #3A2E1A; color: #FFB74D; }
.toast-info { background: #1A2A3A; color: #64B5F6; }
.toast-slide-enter-active, .toast-slide-leave-active { transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1); }
.toast-slide-enter-from { opacity: 0; transform: translateX(-50%) translateY(20px); }
.toast-slide-leave-to { opacity: 0; transform: translateX(-50%) translateY(20px); }

/* ═══ MOBILE ═══ */
@media (max-width: 640px) {
  .hero-nav { padding: 12px 10px; }
  .nav-links { gap: 1px; padding: 3px; border-radius: 12px; }
  .nav-link { padding: 7px 10px; font-size: 0.72rem; border-radius: 9px; }
  .page-footer { padding: 8px 12px; }
  .footer-item { font-size: 0.68rem; }
  .hero-content { padding: 6vh 16px 0; }
  .hero-title { font-size: 1.8rem; }
  .hero-subtitle { font-size: 0.85rem; }
  .search-field { border-radius: 14px; }
  .search-input { font-size: 16px; padding: 13px 10px; }
  .search-btn { width: 100%; justify-content: center; border-radius: 12px; padding: 12px; }
  .main-content { padding: 8px 16px 72px; }
  .result-card { padding: 14px 16px; border-radius: 12px; }
  .result-article { font-size: 0.9rem; }
  .result-name { font-size: 0.88rem; }
  .result-copy-hint { display: none; }
  .admin-access { bottom: 50px; right: 14px; }
  .fab-btn { width: 44px; height: 44px; border-radius: 12px; }
  .admin-panel { width: 100vw; max-width: 100vw; }
  .toast { bottom: 50px; font-size: 0.8rem; }
}

@media (max-width: 380px) {
  .hero-title { font-size: 1.5rem; }
  .hero-subtitle { font-size: 0.8rem; }
  .hero-content { padding: 4vh 12px 0; }
}

@media (min-width: 641px) {
  .search-btn-text { display: inline; }
  .search-btn-icon { display: none; }
}

/* ═══ Тех. работы — оверлей ═══ */
.mnt-overlay {
  position: fixed; inset: 0; z-index: 99999;
  background: #1A0E09;
  display: flex; align-items: center; justify-content: center;
  overflow: hidden;
}
.mnt-bg { position: absolute; inset: 0; overflow: hidden; }
.mnt-orb {
  position: absolute; border-radius: 50%; filter: blur(80px);
  animation: mnt-float 8s ease-in-out infinite;
}
.mnt-orb-1 {
  width: 400px; height: 400px; top: -10%; left: -5%;
  background: radial-gradient(circle, rgba(214,39,0,.15) 0%, transparent 70%);
}
.mnt-orb-2 {
  width: 350px; height: 350px; bottom: -10%; right: -5%;
  background: radial-gradient(circle, rgba(245,166,35,.12) 0%, transparent 70%);
  animation-delay: -3s;
}
@keyframes mnt-float {
  0%, 100% { transform: translate(0, 0) scale(1); }
  50% { transform: translate(-20px, 30px) scale(0.95); }
}
.mnt-card {
  position: relative; z-index: 1;
  background: rgba(40, 22, 14, 0.8);
  -webkit-backdrop-filter: blur(24px);
  backdrop-filter: blur(24px);
  border: 1px solid rgba(253, 189, 16, 0.12);
  border-radius: 24px;
  padding: 48px 40px 36px;
  max-width: 440px; width: 90%;
  text-align: center;
  box-shadow: 0 24px 80px rgba(0,0,0,.5);
  animation: mnt-in .6s ease;
}
@keyframes mnt-in {
  from { opacity: 0; transform: translateY(20px) scale(.97); }
  to { opacity: 1; transform: none; }
}
.mnt-icon {
  display: inline-flex; align-items: center; justify-content: center;
  width: 80px; height: 80px; border-radius: 50%;
  background: rgba(253, 189, 16, 0.06);
  border: 2px solid rgba(253, 189, 16, 0.2);
  margin-bottom: 24px;
}
.mnt-title {
  font-family: 'Flame', sans-serif;
  font-size: 26px; font-weight: 700;
  color: #FDBD10; margin: 0 0 12px;
}
.mnt-msg {
  font-size: 15px; line-height: 1.6;
  color: rgba(245, 230, 208, 0.65);
  margin: 0 0 24px;
}
.mnt-home {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 10px 28px; border-radius: 12px;
  border: 1.5px solid rgba(245, 230, 208, 0.15);
  background: rgba(245, 230, 208, 0.04);
  color: rgba(245, 230, 208, 0.5);
  font-size: 13px; font-weight: 600;
  text-decoration: none; transition: all .2s;
}
.mnt-home:hover {
  border-color: rgba(253, 189, 16, 0.4);
  color: #FDBD10;
  background: rgba(253, 189, 16, 0.06);
}
</style>
