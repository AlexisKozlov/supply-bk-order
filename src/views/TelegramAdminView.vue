<template>
  <div class="tga-view">
    <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;">
      <h1 class="page-title">
        <span class="tga-tg-icon">✈</span> Telegram-бот
      </h1>
      <button class="btn secondary" @click="loadData" :disabled="loading">Обновить</button>
    </div>

    <!-- Табы -->
    <div class="adm-tabs">
      <button class="adm-tab" :class="{ active: tab === 'bot' }" @click="tab = 'bot'; loadBotInfo()">
        🤖 Бот
      </button>
      <button class="adm-tab" :class="{ active: tab === 'users' }" @click="tab = 'users'">
        👥 Подписчики <span class="adm-tab-count" :class="{ active: tab === 'users' }">{{ linkedUsers.length }}</span>
      </button>
      <button class="adm-tab" :class="{ active: tab === 'settings' }" @click="tab = 'settings'">
        ⚙️ Уведомления
      </button>
      <button class="adm-tab" :class="{ active: tab === 'veg' }" @click="tab = 'veg'">
        🥬 Овощи <span class="adm-tab-count" :class="{ active: tab === 'veg' }">{{ vegSubCount }}</span>
      </button>
      <button class="adm-tab" :class="{ active: tab === 'questions' }" @click="tab = 'questions'; loadQuestions()">
        💬 Вопросы AI
      </button>
      <button class="adm-tab" :class="{ active: tab === 'log' }" @click="tab = 'log'">
        📋 Лог
      </button>
      <button class="adm-tab" :class="{ active: tab === 'broadcast' }" @click="tab = 'broadcast'; loadBroadcastHistory()">
        📢 Рассылка
      </button>
    </div>

    <div v-if="loading" style="text-align:center;padding:48px;color:var(--text-muted);">Загрузка...</div>

    <!-- ═══ Бот ═══ -->
    <div v-else-if="tab === 'bot'" class="adm-section">
      <div v-if="botLoading" class="tga-empty">Загрузка информации о боте...</div>
      <template v-else-if="botInfo">
        <!-- Карточка бота -->
        <div class="tga-bot-card">
          <div class="tga-bot-avatar">🤖</div>
          <div class="tga-bot-info">
            <div class="tga-bot-name">{{ botInfo.first_name }}</div>
            <div class="tga-bot-username">@{{ botInfo.username }}</div>
            <div class="tga-bot-id">ID: {{ botInfo.id }}</div>
          </div>
          <a :href="'https://t.me/' + botInfo.username" target="_blank" class="btn secondary" style="margin-left:auto;">
            Открыть в Telegram
          </a>
        </div>

        <!-- Вебхук -->
        <div class="tga-section-card">
          <h3 class="tga-subtitle">Вебхук</h3>
          <div class="tga-kv-list">
            <div class="tga-kv">
              <span class="tga-kv-label">URL:</span>
              <span class="tga-kv-val tga-mono">{{ webhookInfo?.url || '— не установлен —' }}</span>
            </div>
            <div class="tga-kv" v-if="webhookInfo?.url">
              <span class="tga-kv-label">Ожидающих:</span>
              <span class="tga-kv-val" :class="{ 'tga-cell-warn': webhookInfo.pending_update_count > 10 }">
                {{ webhookInfo.pending_update_count ?? 0 }}
              </span>
            </div>
            <div class="tga-kv" v-if="webhookInfo?.last_error_date">
              <span class="tga-kv-label">Последняя ошибка:</span>
              <span class="tga-kv-val" style="color:#d32f2f;">
                {{ webhookInfo.last_error_message }} ({{ formatDate(new Date(webhookInfo.last_error_date * 1000).toISOString()) }})
              </span>
            </div>
            <div class="tga-kv" v-if="webhookInfo?.url && !webhookInfo?.last_error_date">
              <span class="tga-kv-label">Статус:</span>
              <span class="tga-kv-val tga-cell-ok">Работает</span>
            </div>
            <div class="tga-kv" v-if="webhookInfo?.has_custom_certificate">
              <span class="tga-kv-label">Свой сертификат:</span>
              <span class="tga-kv-val">Да</span>
            </div>
            <div class="tga-kv" v-if="webhookInfo?.max_connections">
              <span class="tga-kv-label">Макс. подключений:</span>
              <span class="tga-kv-val">{{ webhookInfo.max_connections }}</span>
            </div>
          </div>

          <!-- Управление вебхуком -->
          <details class="tga-details">
            <summary>Управление вебхуком</summary>
            <div class="tga-details-body">
              <div class="tga-form-group">
                <label>URL вебхука:</label>
                <input v-model="webhookUrl" class="tga-input" style="width:100%;max-width:500px;"
                  placeholder="https://supply-department.online/api/telegram_bot.php"/>
              </div>
              <div class="tga-form-group">
                <label>Secret token (необязательно):</label>
                <input v-model="webhookSecret" class="tga-input" style="width:100%;max-width:500px;"
                  placeholder="Секретный токен для проверки запросов"/>
              </div>
              <div style="display:flex;gap:8px;">
                <button class="btn primary" @click="setWebhook" :disabled="webhookSaving">Установить</button>
                <button class="btn secondary" @click="deleteWebhook" :disabled="webhookSaving" style="color:#d32f2f;">Удалить вебхук</button>
              </div>
              <div v-if="webhookMsg" class="tga-msg" :class="webhookMsgOk ? 'tga-msg-ok' : 'tga-msg-err'" style="margin-top:8px;">{{ webhookMsg }}</div>
            </div>
          </details>
        </div>

        <!-- Быстрые действия -->
        <div class="tga-section-card">
          <h3 class="tga-subtitle">Быстрые действия</h3>
          <div class="tga-actions-row">
            <button class="tga-action-btn" @click="sendTestMessage" :disabled="testSending">
              <span class="tga-action-icon">📨</span>
              <span>Тестовое сообщение (себе)</span>
            </button>
            <button class="tga-action-btn" @click="loadBotInfo">
              <span class="tga-action-icon">🔄</span>
              <span>Обновить статус</span>
            </button>
          </div>
          <div v-if="testMsg" class="tga-msg tga-msg-ok" style="margin-top:8px;">{{ testMsg }}</div>
        </div>

        <!-- Статистика -->
        <div class="tga-section-card">
          <h3 class="tga-subtitle">Общая статистика</h3>
          <div class="tga-stats-row">
            <div class="tga-stat-card">
              <div class="tga-stat-val">{{ linkedUsers.length }}</div>
              <div class="tga-stat-label">Сотрудников</div>
            </div>
            <div class="tga-stat-card">
              <div class="tga-stat-val">{{ vegUniqueChatIds.length }}</div>
              <div class="tga-stat-label">Ресторанов</div>
            </div>
            <div class="tga-stat-card">
              <div class="tga-stat-val">{{ allUniqueChatIds.length }}</div>
              <div class="tga-stat-label">Всего подписчиков</div>
            </div>
            <div class="tga-stat-card">
              <div class="tga-stat-val">{{ reminderLog.length }}</div>
              <div class="tga-stat-label">Напоминаний</div>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- ═══ Подписчики ═══ -->
    <div v-else-if="tab === 'users'" class="adm-section">
      <div class="tga-stats-row">
        <div class="tga-stat-card">
          <div class="tga-stat-val">{{ linkedUsers.length }}</div>
          <div class="tga-stat-label">Подключено</div>
        </div>
        <div class="tga-stat-card">
          <div class="tga-stat-val">{{ unlinkedUsers.length }}</div>
          <div class="tga-stat-label">Не подключено</div>
        </div>
        <div class="tga-stat-card">
          <div class="tga-stat-val">{{ linkedUsers.length + unlinkedUsers.length }}</div>
          <div class="tga-stat-label">Всего</div>
        </div>
      </div>

      <h3 class="tga-subtitle">Подключённые пользователи</h3>
      <div class="tga-table-wrap">
        <table class="tga-table">
          <thead>
            <tr>
              <th>Имя</th>
              <th>Роль</th>
              <th>Chat ID</th>
              <th>Последний вопрос</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in linkedUsers" :key="u.name">
              <td>
                <b>{{ u.name }}</b>
                <div v-if="u.email" class="tga-sub-text">{{ u.email }}</div>
              </td>
              <td>{{ u.display_role || (u.role === 'admin' ? 'Администратор' : 'Сотрудник') }}</td>
              <td class="tga-mono">{{ u.telegram_chat_id }}</td>
              <td>{{ u.last_question_at ? formatDate(u.last_question_at) : '—' }}</td>
              <td>
                <button class="tga-btn-sm tga-btn-danger" @click="unlinkUser(u)" title="Отвязать Telegram">✕</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <h3 class="tga-subtitle" style="margin-top:24px;">Не подключены</h3>
      <div v-if="!unlinkedUsers.length" class="tga-empty">Все пользователи подключены!</div>
      <div v-else class="tga-table-wrap">
        <table class="tga-table">
          <thead>
            <tr><th>Имя</th><th>Роль</th><th>E-mail</th></tr>
          </thead>
          <tbody>
            <tr v-for="u in unlinkedUsers" :key="u.name" class="tga-row-muted">
              <td>{{ u.name }}</td>
              <td>{{ u.display_role || (u.role === 'admin' ? 'Администратор' : 'Сотрудник') }}</td>
              <td>{{ u.email || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ═══ Настройки уведомлений ═══ -->
    <div v-else-if="tab === 'settings'" class="adm-section">
      <p class="tga-hint">Какие уведомления получает каждый подписчик. Нажмите на галочку/крестик, чтобы переключить.</p>
      <div class="tga-table-wrap">
        <table class="tga-table tga-table-compact">
          <thead>
            <tr>
              <th>Пользователь</th>
              <th title="Ежедневная сводка">📊</th>
              <th title="ПСЦ истекает">📋</th>
              <th title="Цены изменились">💰</th>
              <th title="Просроченная поставка">📦</th>
              <th title="Загрузка данных">📥</th>
              <th title="Истекающие сроки">⚠️</th>
              <th title="Реализация ресторанов">🍽</th>
              <th title="Остатки заканчиваются">📉</th>
              <th title="Корректировки заказов">✏️</th>
              <th title="Сообщения из ресторанов">💬</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in linkedUsers" :key="u.name">
              <td><b>{{ u.name }}</b></td>
              <td :class="cellClass(u.daily_summary)" class="tga-cell-toggle" @click="toggleSetting(u, 'daily_summary')">{{ u.daily_summary ? '✓' : '✕' }}</td>
              <td :class="cellClass(u.psc_expiry)" class="tga-cell-toggle" @click="toggleSetting(u, 'psc_expiry')">{{ u.psc_expiry ? '✓' : '✕' }}</td>
              <td :class="cellClass(u.price_changed)" class="tga-cell-toggle" @click="toggleSetting(u, 'price_changed')">{{ u.price_changed ? '✓' : '✕' }}</td>
              <td :class="cellClass(u.overdue_delivery)" class="tga-cell-toggle" @click="toggleSetting(u, 'overdue_delivery')">{{ u.overdue_delivery ? '✓' : '✕' }}</td>
              <td :class="cellClass(u.data_updates)" class="tga-cell-toggle" @click="toggleSetting(u, 'data_updates')">{{ u.data_updates ? '✓' : '✕' }}</td>
              <td :class="cellClass(u.expiring_items)" class="tga-cell-toggle" @click="toggleSetting(u, 'expiring_items')">{{ u.expiring_items ? '✓' : '✕' }}</td>
              <td :class="cellClass(u.restaurant_sales)" class="tga-cell-toggle" @click="toggleSetting(u, 'restaurant_sales')">{{ u.restaurant_sales ? '✓' : '✕' }}</td>
              <td :class="cellClass(u.low_stock)" class="tga-cell-toggle" @click="toggleSetting(u, 'low_stock')">{{ u.low_stock ? '✓' : '✕' }}</td>
              <td :class="cellClass(u.correction_notifications)" class="tga-cell-toggle" @click="toggleSetting(u, 'correction_notifications')">{{ u.correction_notifications ? '✓' : '✕' }}</td>
              <td :class="cellClass(u.chat_notifications)" class="tga-cell-toggle" @click="toggleSetting(u, 'chat_notifications')">{{ u.chat_notifications ? '✓' : '✕' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="tga-legend">
        <span>📊 Ежедневная сводка</span>
        <span>📋 ПСЦ истекает</span>
        <span>💰 Цены изменились</span>
        <span>📦 Просроченная поставка</span>
        <span>📥 Загрузка данных</span>
        <span>⚠️ Истекающие сроки</span>
        <span>🍽 Реализация ресторанов</span>
        <span>📉 Остатки заканчиваются</span>
        <span>✏️ Корректировки заказов</span>
        <span>💬 Сообщения из ресторанов</span>
      </div>
    </div>

    <!-- ═══ Овощи: подписки ═══ -->
    <div v-else-if="tab === 'veg'" class="adm-section">
      <div class="tga-stats-row">
        <div class="tga-stat-card">
          <div class="tga-stat-val">{{ vegSubCount }}</div>
          <div class="tga-stat-label">Подписчиков</div>
        </div>
        <div class="tga-stat-card">
          <div class="tga-stat-val">{{ subscribedRests.length }}</div>
          <div class="tga-stat-label">Ресторанов с подпиской</div>
        </div>
        <div class="tga-stat-card">
          <div class="tga-stat-val">{{ unsubscribedRests.length }}</div>
          <div class="tga-stat-label">Без подписки</div>
        </div>
      </div>

      <!-- Фильтр -->
      <div class="tga-filter-row">
        <select v-model="vegFilter" class="tga-select">
          <option value="all">Все</option>
          <option value="subscribed">С подпиской</option>
          <option value="unsubscribed">Без подписки</option>
        </select>
        <input v-model="vegSearch" class="tga-input" placeholder="Поиск по номеру или адресу..."/>
      </div>

      <div class="tga-table-wrap">
        <table class="tga-table">
          <thead>
            <tr>
              <th style="width:80px">Ресторан</th>
              <th>Адрес</th>
              <th>Город</th>
              <th>Подписчики</th>
              <th>Дата подписки</th>
              <th style="width:50px"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in filteredVegRests" :key="r.number" :class="{ 'tga-row-muted': !r.subCount }">
              <td><b>{{ r.number }}</b></td>
              <td>{{ r.address || '—' }}</td>
              <td>{{ r.city || '—' }}</td>
              <td :class="r.subCount ? 'tga-cell-ok' : 'tga-cell-warn'" style="text-align:center;">
                <template v-if="r.subscribers.length">
                  <span class="tga-sub-count-link" @click="toggleSubsList(r.number)">{{ r.subCount }}</span>
                  <div v-if="expandedRest === r.number" class="tga-sub-list">
                    <div v-for="(sub, si) in r.subscribers" :key="si" class="tga-sub-person">
                      {{ sub.first_name || 'Без имени' }}<span v-if="sub.username" class="tga-sub-username"> @{{ sub.username }}</span>
                    </div>
                  </div>
                </template>
                <template v-else>—</template>
              </td>
              <td>{{ r.firstSub ? formatDate(r.firstSub) : '—' }}</td>
              <td>
                <button v-if="r.subCount" class="tga-btn-sm" @click="sendVegReminder(r)" title="Отправить напоминание">📨</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ═══ Лог напоминаний ═══ -->
    <div v-else-if="tab === 'log'" class="adm-section">
      <p class="tga-hint">Последние 100 отправленных напоминаний о заявках на овощи.</p>

      <!-- Фильтры -->
      <div class="tga-filter-row">
        <input type="date" v-model="logDateFrom" class="tga-input" style="min-width:140px;flex:0;" placeholder="Дата от"/>
        <input type="date" v-model="logDateTo" class="tga-input" style="min-width:140px;flex:0;" placeholder="Дата до"/>
        <input v-model="logRestSearch" class="tga-input" style="min-width:120px;max-width:200px;" placeholder="Номер ресторана"/>
        <select v-model="logTypeFilter" class="tga-select">
          <option value="all">Все типы</option>
          <option value="evening">Вечер</option>
          <option value="3h">3 часа</option>
          <option value="2h">2 часа</option>
          <option value="1h">1 час</option>
          <option value="30m">30 минут</option>
          <option value="expired">Истёк</option>
        </select>
      </div>

      <div v-if="!filteredReminderLog.length" class="tga-empty">Нет записей</div>
      <div v-else class="tga-table-wrap">
        <table class="tga-table">
          <thead>
            <tr>
              <th>Время</th>
              <th>Ресторан</th>
              <th>Адрес</th>
              <th>Доставка</th>
              <th>Тип</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(r, i) in filteredReminderLog" :key="i">
              <td>{{ formatDate(r.sent_at) }}</td>
              <td><b>{{ r.restaurant_number }}</b></td>
              <td>{{ r.address || r.city || '—' }}</td>
              <td>{{ formatDateShort(r.delivery_date) }}</td>
              <td>
                <span class="tga-badge" :class="'tga-badge-' + r.reminder_type">{{ reminderLabel(r.reminder_type) }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ═══ Рассылка ═══ -->
    <div v-else-if="tab === 'broadcast'" class="adm-section">
      <div class="tga-broadcast">
        <h3 class="tga-subtitle">Отправить сообщение</h3>

        <div class="tga-form-group">
          <label>Получатели:</label>
          <div class="tga-recipient-btns">
            <button class="tga-btn-chip" :class="{ active: broadcastTarget === 'all_users' }" @click="broadcastTarget = 'all_users'">
              Все сотрудники ({{ linkedUsers.length }})
            </button>
            <button class="tga-btn-chip" :class="{ active: broadcastTarget === 'all_veg' }" @click="broadcastTarget = 'all_veg'">
              Все рестораны-овощи ({{ vegUniqueChatIds.length }})
            </button>
            <button class="tga-btn-chip" :class="{ active: broadcastTarget === 'everyone' }" @click="broadcastTarget = 'everyone'">
              Все ({{ allUniqueChatIds.length }})
            </button>
          </div>
        </div>

        <div class="tga-form-group">
          <label>Сообщение (HTML):</label>
          <textarea v-model="broadcastText" class="tga-textarea" rows="5" placeholder="Текст сообщения... Поддерживается <b>жирный</b>, <i>курсив</i>"></textarea>
        </div>

        <div class="tga-form-group" style="display:flex;align-items:center;gap:12px;">
          <button class="btn primary" @click="sendBroadcast" :disabled="!broadcastText.trim() || broadcastSending">
            {{ broadcastSending ? 'Отправка...' : 'Отправить' }}
          </button>
          <span v-if="broadcastResult" class="tga-broadcast-result">{{ broadcastResult }}</span>
        </div>
      </div>

      <!-- История рассылок -->
      <div style="margin-top:32px;">
        <h3 class="tga-subtitle">История рассылок</h3>
        <div v-if="broadcastHistoryLoading" class="tga-empty">Загрузка...</div>
        <div v-else-if="!broadcastHistory.length" class="tga-empty">Нет рассылок</div>
        <div v-else class="tga-table-wrap">
          <table class="tga-table">
            <thead>
              <tr>
                <th>Время</th>
                <th>Отправитель</th>
                <th>Сообщение</th>
                <th>Получателей</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="b in broadcastHistory" :key="b.id">
                <td>{{ formatDate(b.sent_at) }}</td>
                <td><b>{{ b.sender }}</b></td>
                <td style="max-width:400px;word-break:break-word;">{{ b.message }}</td>
                <td class="tga-cell-ok">{{ b.recipient_count }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ═══ Вопросы AI ═══ -->
    <div v-else-if="tab === 'questions'" class="adm-section">
      <p class="tga-hint">Последние вопросы, которые пользователи задавали боту через AI-ассистента.</p>
      <div v-if="questionsLoading" class="tga-empty">Загрузка...</div>
      <div v-else-if="!questions.length" class="tga-empty">Нет вопросов</div>
      <div v-else class="tga-table-wrap">
        <table class="tga-table">
          <thead>
            <tr>
              <th style="width:130px">Время</th>
              <th style="width:120px">Пользователь</th>
              <th style="width:100px">Юрлицо</th>
              <th>Вопрос</th>
              <th>Ответ AI</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(q, i) in questions" :key="i" @click="q._expanded = !q._expanded" style="cursor:pointer;">
              <td>{{ formatDate(q.last_question_at) }}</td>
              <td><b>{{ q.user_name }}</b></td>
              <td class="tga-sub-text">{{ q.last_entity || '—' }}</td>
              <td style="max-width:400px;word-break:break-word;">{{ q.last_question }}</td>
              <td style="max-width:400px;word-break:break-word;">
                <template v-if="q.answer">
                  <span v-if="!q._expanded" class="tga-sub-text">{{ q.answer.slice(0, 80) }}{{ q.answer.length > 80 ? '...' : '' }}</span>
                  <span v-else style="white-space:pre-wrap;font-size:12px;">{{ q.answer }}</span>
                </template>
                <span v-else class="tga-sub-text">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { db } from '@/lib/apiClient.js'
import { useUserStore } from '@/stores/userStore.js'

const userStore = useUserStore()

const tab = ref('bot')
const loading = ref(true)

const linkedUsers = ref([])
const unlinkedUsers = ref([])
const vegSubs = ref([])
const allRestaurants = ref([])
const reminderLog = ref([])

// Bot info
const botInfo = ref(null)
const webhookInfo = ref(null)
const botLoading = ref(false)
const webhookUrl = ref('')
const webhookSecret = ref('')
const webhookSaving = ref(false)
const webhookMsg = ref('')
const webhookMsgOk = ref(true)
const testSending = ref(false)
const testMsg = ref('')

// AI questions
const questions = ref([])
const questionsLoading = ref(false)

// Veg filters
const vegFilter = ref('all')
const vegSearch = ref('')
const expandedRest = ref(null)

// Reminder log filters
const logDateFrom = ref('')
const logDateTo = ref('')
const logRestSearch = ref('')
const logTypeFilter = ref('all')

// Broadcast
const broadcastTarget = ref('all_users')
const broadcastText = ref('')
const broadcastSending = ref(false)
const broadcastResult = ref('')
const broadcastHistory = ref([])
const broadcastHistoryLoading = ref(false)

async function loadData() {
  loading.value = true
  try {
    const { data } = await db.rpc('tg_admin_stats')
    linkedUsers.value = data.linked_users || []
    unlinkedUsers.value = data.unlinked_users || []
    vegSubs.value = data.veg_subs || []
    allRestaurants.value = data.all_restaurants || []
    reminderLog.value = data.reminder_log || []
  } catch (e) {
    console.error('tg_admin_stats error:', e)
  } finally {
    loading.value = false
  }
}

async function loadBotInfo() {
  botLoading.value = true
  try {
    const { data } = await db.rpc('tg_admin_bot_info')
    botInfo.value = data.bot
    webhookInfo.value = data.webhook
    if (data.webhook?.url) webhookUrl.value = data.webhook.url
  } catch (e) {
    console.error('bot info error:', e)
  } finally {
    botLoading.value = false
  }
}

async function loadQuestions() {
  questionsLoading.value = true
  try {
    const { data } = await db.rpc('tg_admin_recent_questions')
    questions.value = data.questions || []
  } catch (e) {
    console.error('questions error:', e)
  } finally {
    questionsLoading.value = false
  }
}

async function setWebhook() {
  if (!webhookUrl.value.trim()) { webhookMsg.value = 'Укажите URL'; webhookMsgOk.value = false; return }
  webhookSaving.value = true; webhookMsg.value = ''
  try {
    const { data } = await db.rpc('tg_admin_set_webhook', { url: webhookUrl.value, secret: webhookSecret.value })
    webhookMsg.value = data.ok ? 'Вебхук установлен' : (data.description || 'Ошибка')
    webhookMsgOk.value = !!data.ok
    if (data.ok) loadBotInfo()
  } catch (e) {
    webhookMsg.value = 'Ошибка: ' + (e.message || e); webhookMsgOk.value = false
  } finally { webhookSaving.value = false }
}

async function deleteWebhook() {
  if (!confirm('Удалить вебхук? Бот перестанет получать сообщения.')) return
  webhookSaving.value = true; webhookMsg.value = ''
  try {
    const { data } = await db.rpc('tg_admin_delete_webhook')
    webhookMsg.value = data.ok ? 'Вебхук удалён' : (data.description || 'Ошибка')
    webhookMsgOk.value = !!data.ok
    if (data.ok) loadBotInfo()
  } catch (e) {
    webhookMsg.value = 'Ошибка: ' + (e.message || e); webhookMsgOk.value = false
  } finally { webhookSaving.value = false }
}

async function sendTestMessage() {
  testSending.value = true; testMsg.value = ''
  try {
    // Найти свой chat_id по имени текущего пользователя
    const myUser = linkedUsers.value.find(u => u.name === userStore.currentUser?.name)
    if (!myUser || !myUser.telegram_chat_id) { testMsg.value = 'Ваш Telegram не привязан'; return }
    const { data } = await db.rpc('tg_admin_send_message', {
      chat_ids: [String(myUser.telegram_chat_id)],
      message: '🔔 <b>Тестовое сообщение</b>\n\nЭто тестовое сообщение из админки Telegram-бота.\n\n✅ Бот работает!'
    })
    testMsg.value = data.sent > 0 ? `Отправлено ${myUser.name}` : 'Не удалось отправить'
  } catch (e) {
    testMsg.value = 'Ошибка: ' + (e.message || e)
  } finally { testSending.value = false }
}

onMounted(() => { loadData(); loadBotInfo() })

// ═══ Computed ═══

const vegSubCount = computed(() => vegSubs.value.length)

const vegRestMap = computed(() => {
  const map = {}
  for (const s of vegSubs.value) {
    if (!map[s.restaurant_number]) {
      map[s.restaurant_number] = { count: 0, firstSub: s.created_at, subscribers: [] }
    }
    map[s.restaurant_number].count++
    map[s.restaurant_number].subscribers.push({
      first_name: s.first_name,
      username: s.username,
      chat_id: s.chat_id
    })
    if (s.created_at < map[s.restaurant_number].firstSub) {
      map[s.restaurant_number].firstSub = s.created_at
    }
  }
  return map
})

const subscribedRests = computed(() => allRestaurants.value.filter(r => vegRestMap.value[r.number]))
const unsubscribedRests = computed(() => allRestaurants.value.filter(r => !vegRestMap.value[r.number]))

const filteredVegRests = computed(() => {
  let list = allRestaurants.value.map(r => ({
    number: r.number,
    address: r.address,
    city: r.city,
    region: r.region,
    subCount: vegRestMap.value[r.number]?.count || 0,
    subscribers: vegRestMap.value[r.number]?.subscribers || [],
    firstSub: vegRestMap.value[r.number]?.firstSub || null,
  }))

  if (vegFilter.value === 'subscribed') list = list.filter(r => r.subCount > 0)
  if (vegFilter.value === 'unsubscribed') list = list.filter(r => !r.subCount)

  const q = vegSearch.value.toLowerCase().trim()
  if (q) {
    list = list.filter(r =>
      String(r.number).toLowerCase().includes(q) ||
      (r.address || '').toLowerCase().includes(q) ||
      (r.city || '').toLowerCase().includes(q)
    )
  }

  return list
})

const vegUniqueChatIds = computed(() => {
  return [...new Set(vegSubs.value.map(s => s.chat_id))]
})

const filteredReminderLog = computed(() => {
  let list = reminderLog.value
  if (logDateFrom.value) {
    const from = new Date(logDateFrom.value)
    list = list.filter(r => new Date(r.sent_at) >= from)
  }
  if (logDateTo.value) {
    const to = new Date(logDateTo.value)
    to.setDate(to.getDate() + 1)
    list = list.filter(r => new Date(r.sent_at) < to)
  }
  if (logRestSearch.value.trim()) {
    const q = logRestSearch.value.trim().toLowerCase()
    list = list.filter(r => String(r.restaurant_number).toLowerCase().includes(q))
  }
  if (logTypeFilter.value !== 'all') {
    list = list.filter(r => r.reminder_type === logTypeFilter.value)
  }
  return list
})

const allUniqueChatIds = computed(() => {
  const ids = new Set()
  linkedUsers.value.forEach(u => { if (u.telegram_chat_id) ids.add(String(u.telegram_chat_id)) })
  vegSubs.value.forEach(s => ids.add(String(s.chat_id)))
  return [...ids]
})

// ═══ Methods ═══

function toggleSubsList(restNumber) {
  expandedRest.value = expandedRest.value === restNumber ? null : restNumber
}

function cellClass(val) {
  return val ? 'tga-cell-ok' : 'tga-cell-off'
}

function formatDate(d) {
  if (!d) return ''
  const dt = new Date(d)
  return dt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' }) +
    ' ' + dt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
}

function formatDateShort(d) {
  if (!d) return ''
  const dt = new Date(d)
  return dt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' })
}

function reminderLabel(type) {
  const labels = { evening: '🌙 Вечер', '3h': '3ч', '2h': '2ч', '1h': '1ч', '30m': '30м', expired: '⚠️ Истёк', submitted: '✅ Подана' }
  return labels[type] || type
}

async function unlinkUser(u) {
  if (!confirm(`Отвязать Telegram от ${u.name}? Пользователю нужно будет привязать бот заново.`)) return
  try {
    await db.rpc('tg_admin_unlink_user', { user_name: u.name })
    loadData()
  } catch (e) {
    alert('Ошибка: ' + (e.message || e))
  }
}

async function sendVegReminder(rest) {
  const defaultMsg = `Напоминание для ресторана ${rest.number}: пожалуйста, подайте заявку на овощи.`
  const msg = prompt('Текст напоминания:', defaultMsg)
  if (!msg) return
  try {
    const { data } = await db.rpc('tg_admin_send_veg_reminder', { restaurant_number: rest.number, message: msg })
    alert(`Отправлено: ${data.sent} из ${data.total}`)
  } catch (e) {
    alert('Ошибка: ' + (e.message || e))
  }
}

async function toggleSetting(user, field) {
  try {
    const { data } = await db.rpc('tg_admin_toggle_setting', { user_name: user.name, field })
    user[field] = data.value ? 1 : 0
  } catch (e) {
    alert('Ошибка: ' + (e.message || e))
  }
}

async function loadBroadcastHistory() {
  broadcastHistoryLoading.value = true
  try {
    const { data } = await db.rpc('tg_admin_broadcast_history')
    broadcastHistory.value = data.broadcasts || []
  } catch (e) {
    console.error('broadcast history error:', e)
  } finally {
    broadcastHistoryLoading.value = false
  }
}

async function sendBroadcast() {
  if (!broadcastText.value.trim()) return

  let chatIds = []
  if (broadcastTarget.value === 'all_users') {
    chatIds = linkedUsers.value.map(u => String(u.telegram_chat_id))
  } else if (broadcastTarget.value === 'all_veg') {
    chatIds = vegUniqueChatIds.value.map(String)
  } else {
    chatIds = allUniqueChatIds.value
  }

  if (!chatIds.length) { broadcastResult.value = 'Нет получателей'; return }
  if (!confirm(`Отправить сообщение ${chatIds.length} получателям?`)) return

  broadcastSending.value = true
  broadcastResult.value = ''
  try {
    const { data } = await db.rpc('tg_admin_send_message', { chat_ids: chatIds, message: broadcastText.value, sender: userStore.currentUser?.name || 'admin' })
    broadcastResult.value = `Отправлено: ${data.sent} из ${data.total}`
    broadcastText.value = ''
    loadBroadcastHistory()
  } catch (e) {
    broadcastResult.value = 'Ошибка: ' + (e.message || e)
  } finally {
    broadcastSending.value = false
  }
}
</script>

<style scoped>
/* ═══ Layout ═══ */
.tga-view { max-width: 1200px; padding: 0; }

.tga-tg-icon {
  display: inline-flex; align-items: center; justify-content: center;
  width: 28px; height: 28px; border-radius: 50%;
  background: linear-gradient(135deg, #2AABEE, #229ED9);
  color: white; font-size: 14px; margin-right: 4px;
}

/* ═══ Tabs (копия из AdminView) ═══ */
.adm-tabs {
  display: flex; flex-wrap: wrap; gap: 0; margin-bottom: 20px;
  border-bottom: 2px solid var(--border-light, #f0f0f0);
}
.adm-tab {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 10px 22px; font-size: 14px; font-weight: 600; font-family: inherit;
  color: var(--text-muted, #999); background: none; border: none;
  border-bottom: 2.5px solid transparent; margin-bottom: -2px;
  cursor: pointer; transition: all .15s; position: relative;
}
.adm-tab.active { color: var(--bk-brown, #8B7355); border-bottom-color: var(--bk-brown, #8B7355); }
.adm-tab:hover:not(.active) { color: var(--text, #333); background: rgba(139,115,85,.04); }
.adm-tab-count {
  font-size: 11px; font-weight: 700; padding: 1px 7px;
  border-radius: 10px; background: var(--border-light, #f0f0f0); color: var(--text-muted, #999);
}
.adm-tab-count.active { background: var(--bk-brown, #8B7355); color: #fff; }

/* ═══ Section ═══ */
.adm-section { animation: tgaFade .2s ease; }
@keyframes tgaFade { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }

/* ═══ Статистика ═══ */
.tga-stats-row {
  display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap;
}
.tga-stat-card {
  flex: 1; min-width: 120px;
  background: var(--card, #fff); border: 1px solid var(--border, #e0e0e0);
  border-radius: 10px; padding: 16px 20px; text-align: center;
}
.tga-stat-val { font-size: 28px; font-weight: 700; color: var(--text, #333); }
.tga-stat-label { font-size: 13px; color: var(--text-muted, #999); margin-top: 4px; }

/* ═══ Подзаголовок ═══ */
.tga-subtitle { font-size: 15px; font-weight: 600; margin: 0 0 12px; color: var(--text, #333); }

/* ═══ Таблицы ═══ */
.tga-table-wrap { overflow-x: auto; margin-bottom: 16px; }
.tga-table {
  width: 100%; border-collapse: collapse; font-size: 13px;
}
.tga-table th {
  text-align: left; padding: 8px 12px; font-weight: 600; font-size: 12px;
  color: var(--text-muted, #999); border-bottom: 2px solid var(--border, #e0e0e0);
  white-space: nowrap; background: var(--bg, #fafafa);
}
.tga-table td {
  padding: 8px 12px; border-bottom: 1px solid var(--border-light, #f0f0f0);
  vertical-align: middle;
}
.tga-table tbody tr:hover { background: rgba(139,115,85,.03); }
.tga-table-compact th, .tga-table-compact td { padding: 6px 10px; text-align: center; }
.tga-table-compact th:first-child, .tga-table-compact td:first-child { text-align: left; }

.tga-mono { font-family: 'SF Mono', 'Consolas', monospace; font-size: 12px; color: var(--text-muted, #999); }
.tga-sub-text { font-size: 12px; color: var(--text-muted, #999); }
.tga-row-muted td { opacity: 0.45; }

.tga-cell-ok { color: #2e7d32; font-weight: 600; text-align: center; }
.tga-cell-off { color: #ccc; text-align: center; }
.tga-cell-warn { color: #e65100; font-weight: 600; text-align: center; }
.tga-cell-toggle { cursor: pointer; user-select: none; transition: background .15s; }
.tga-cell-toggle:hover { background: rgba(139,115,85,.08); }

/* ═══ Подписчики (раскрывающийся список) ═══ */
.tga-sub-count-link {
  cursor: pointer; font-weight: 600; text-decoration: underline;
  text-decoration-style: dotted; text-underline-offset: 2px;
}
.tga-sub-count-link:hover { text-decoration-style: solid; }
.tga-sub-list {
  margin-top: 6px; text-align: left; font-size: 12px;
  background: var(--bg, #fafafa); border-radius: 6px;
  padding: 6px 8px; border: 1px solid var(--border-light, #f0f0f0);
}
.tga-sub-person { padding: 2px 0; color: var(--text, #333); font-weight: 400; }
.tga-sub-username { color: #2AABEE; font-size: 11px; }

.tga-empty {
  padding: 48px; text-align: center; color: var(--text-muted, #999);
  font-style: italic; font-size: 14px;
}
.tga-hint { font-size: 13px; color: var(--text-muted, #999); margin: 0 0 16px; line-height: 1.5; }

/* ═══ Кнопки ═══ */
.tga-btn-sm {
  width: 28px; height: 28px; border: none; border-radius: 6px;
  cursor: pointer; font-size: 14px; display: inline-flex;
  align-items: center; justify-content: center; background: transparent;
  color: var(--text-muted, #999); transition: all .15s;
}
.tga-btn-sm:hover { background: rgba(0,0,0,0.05); }
.tga-btn-danger:hover { background: rgba(211,47,47,0.1); color: #d32f2f; }

/* ═══ Фильтр ═══ */
.tga-filter-row { display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
.tga-select, .tga-input {
  padding: 8px 12px; border: 1px solid var(--border, #e0e0e0);
  border-radius: 8px; font-size: 13px; background: var(--card, #fff);
  color: var(--text, #333); font-family: inherit;
  transition: border-color .15s;
}
.tga-select:focus, .tga-input:focus { outline: none; border-color: var(--bk-brown, #8B7355); }
.tga-input { flex: 1; min-width: 200px; }
.tga-select { min-width: 140px; }

/* ═══ Бейджи ═══ */
.tga-badge {
  display: inline-block; padding: 2px 8px; border-radius: 10px;
  font-size: 12px; font-weight: 600; white-space: nowrap;
}
.tga-badge-evening { background: #e3f2fd; color: #1565c0; }
.tga-badge-3h, .tga-badge-2h { background: #fff3e0; color: #e65100; }
.tga-badge-1h, .tga-badge-30m { background: #fce4ec; color: #c62828; }
.tga-badge-expired { background: #ffebee; color: #b71c1c; }
.tga-badge-submitted { background: #e8f5e9; color: #2e7d32; }

/* ═══ Рассылка ═══ */
.tga-broadcast { max-width: 600px; }
.tga-form-group { margin-bottom: 16px; }
.tga-form-group label {
  display: block; font-size: 13px; font-weight: 600;
  margin-bottom: 6px; color: var(--text, #333);
}
.tga-textarea {
  width: 100%; padding: 10px 12px; border: 1px solid var(--border, #e0e0e0);
  border-radius: 8px; font-size: 13px; font-family: inherit;
  resize: vertical; background: var(--card, #fff); color: var(--text, #333);
  transition: border-color .15s; box-sizing: border-box;
}
.tga-textarea:focus { outline: none; border-color: var(--bk-brown, #8B7355); }

.tga-recipient-btns { display: flex; gap: 8px; flex-wrap: wrap; }
.tga-btn-chip {
  padding: 6px 14px; border: 1px solid var(--border, #e0e0e0);
  border-radius: 20px; font-size: 13px; cursor: pointer;
  background: var(--card, #fff); color: var(--text, #333);
  font-family: inherit; transition: all 0.15s;
}
.tga-btn-chip:hover { border-color: #2AABEE; color: #2AABEE; }
.tga-btn-chip.active { background: #2AABEE; color: white; border-color: #2AABEE; }

.tga-broadcast-result { font-size: 13px; color: #2e7d32; font-weight: 500; }

/* ═══ Легенда ═══ */
.tga-legend {
  display: flex; flex-wrap: wrap; gap: 12px 20px; padding: 12px 0;
  font-size: 12px; color: var(--text-muted, #999); border-top: 1px solid var(--border-light, #f0f0f0);
  margin-top: 8px;
}

/* ═══ Карточка бота ═══ */
.tga-bot-card {
  display: flex; align-items: center; gap: 16px;
  padding: 20px 24px; border-radius: 12px;
  background: var(--card, #fff); border: 1px solid var(--border, #e0e0e0);
  margin-bottom: 20px;
}
.tga-bot-avatar {
  width: 56px; height: 56px; border-radius: 16px;
  background: linear-gradient(135deg, #2AABEE, #229ED9);
  display: flex; align-items: center; justify-content: center;
  font-size: 28px; flex-shrink: 0;
}
.tga-bot-info { flex: 1; }
.tga-bot-name { font-size: 18px; font-weight: 700; color: var(--text, #333); }
.tga-bot-username { font-size: 14px; color: #2AABEE; font-weight: 500; }
.tga-bot-id { font-size: 12px; color: var(--text-muted, #999); margin-top: 2px; }

/* ═══ Секция-карточка ═══ */
.tga-section-card {
  padding: 20px 24px; border-radius: 12px;
  background: var(--card, #fff); border: 1px solid var(--border, #e0e0e0);
  margin-bottom: 16px;
}

/* ═══ Key-Value список ═══ */
.tga-kv-list { display: flex; flex-direction: column; gap: 8px; }
.tga-kv { display: flex; align-items: baseline; gap: 8px; font-size: 13px; }
.tga-kv-label { color: var(--text-muted, #999); min-width: 140px; flex-shrink: 0; }
.tga-kv-val { color: var(--text, #333); word-break: break-all; }

/* ═══ Details (складная секция) ═══ */
.tga-details {
  margin-top: 16px; border-top: 1px solid var(--border-light, #f0f0f0);
  padding-top: 12px;
}
.tga-details summary {
  cursor: pointer; font-size: 13px; font-weight: 600;
  color: var(--text-muted, #999); padding: 4px 0;
  user-select: none;
}
.tga-details summary:hover { color: var(--text, #333); }
.tga-details-body { padding: 12px 0 0; }

/* ═══ Быстрые действия ═══ */
.tga-actions-row { display: flex; gap: 12px; flex-wrap: wrap; }
.tga-action-btn {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 18px; border: 1px solid var(--border, #e0e0e0);
  border-radius: 10px; background: var(--bg, #fafafa);
  cursor: pointer; font-size: 13px; font-family: inherit;
  color: var(--text, #333); transition: all .15s;
}
.tga-action-btn:hover { border-color: var(--bk-brown, #8B7355); background: rgba(139,115,85,.04); }
.tga-action-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.tga-action-icon { font-size: 18px; }

/* ═══ Сообщения ═══ */
.tga-msg { font-size: 13px; padding: 6px 10px; border-radius: 6px; }
.tga-msg-ok { color: #2e7d32; background: #e8f5e9; }
.tga-msg-err { color: #c62828; background: #ffebee; }

/* ═══ Адаптив ═══ */
@media (max-width: 768px) {
  .adm-tabs { gap: 0; overflow-x: auto; flex-wrap: nowrap; -webkit-overflow-scrolling: touch; }
  .adm-tab { padding: 8px 14px; font-size: 12px; gap: 4px; white-space: nowrap; }
  .adm-tab-count { font-size: 10px; padding: 1px 5px; }
  .tga-stats-row { gap: 8px; }
  .tga-stat-card { min-width: 90px; padding: 12px; }
  .tga-stat-val { font-size: 22px; }
  .tga-stat-label { font-size: 11px; }
  .tga-filter-row { flex-direction: column; }
  .tga-input { min-width: 0; }
  .tga-table { font-size: 12px; }
  .tga-table th, .tga-table td { padding: 6px 8px; }
  .tga-recipient-btns { flex-direction: column; }
  .tga-btn-chip { text-align: center; }
  .tga-broadcast { max-width: 100%; }
}
</style>
