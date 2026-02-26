<template>
  <div class="cards-page">

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
          <p v-if="loading" class="hero-status">Загрузка базы...</p>
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
import { ref, onMounted, onBeforeUnmount } from 'vue'

const API_BASE = '/api'

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
  const articleMatch = queryRaw.match(/\d{5,}/)

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
    } else {
      results.value = []
      logSearch(queryRaw, false, 'article', null)
    }
    return
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

// ESC закрывает панель
function handleKeydown(e) {
  if (e.key === 'Escape') {
    if (adminOpen.value) closeAdmin()
    else if (showAdminLogin.value) showAdminLogin.value = false
  }
}

onMounted(() => {
  loadCards()
  loadLastUpdate()
  loadUserList()
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
}
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
</style>
