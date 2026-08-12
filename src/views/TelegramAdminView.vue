<template>
  <div class="tga-view">
    <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;">
      <h1 class="page-title">
        <span class="tga-tg-icon"><BkIcon name="send" size="sm" /></span> Telegram-бот
      </h1>
      <button class="btn secondary" @click="loadData" :disabled="loading">Обновить</button>
    </div>

    <!-- Табы -->
    <div class="adm-tabs">
      <button class="adm-tab" :class="{ active: tab === 'bot' }" @click="tab = 'bot'; loadBotInfo()">
        <BkIcon name="send" size="sm" /> Бот
      </button>
      <button class="adm-tab" :class="{ active: tab === 'users' }" @click="tab = 'users'">
        <BkIcon name="user" size="sm" /> Подписчики <span class="adm-tab-count" :class="{ active: tab === 'users' }">{{ linkedUsers.length }}</span>
      </button>
      <button class="adm-tab" :class="{ active: tab === 'settings' }" @click="tab = 'settings'">
        <BkIcon name="gear" size="sm" /> Уведомления
      </button>
      <button class="adm-tab" :class="{ active: tab === 'restaurants' }" @click="tab = 'restaurants'">
        <BkIcon name="building" size="sm" /> Рестораны <span class="adm-tab-count" :class="{ active: tab === 'restaurants' }">{{ restaurantActiveSubCount }}</span>
      </button>
      <button class="adm-tab" :class="{ active: tab === 'questions' }" @click="tab = 'questions'; loadQuestions()">
        <BkIcon name="chat" size="sm" /> Вопросы AI
      </button>
      <button class="adm-tab" :class="{ active: tab === 'log' }" @click="tab = 'log'">
        <BkIcon name="clipboard" size="sm" /> Лог
      </button>
      <button class="adm-tab" :class="{ active: tab === 'broadcast' }" @click="tab = 'broadcast'; loadBroadcastHistory()">
        <BkIcon name="bell" size="sm" /> Рассылка
      </button>
    </div>

    <div v-if="loading" style="text-align:center;padding:48px;color:var(--text-muted);"><BurgerSpinner text="Загрузка..." /></div>

    <!-- ═══ Бот ═══ -->
    <div v-else-if="tab === 'bot'" class="adm-section">
      <div v-if="botLoading" class="tga-empty"><BurgerSpinner text="Загрузка информации о боте..." /></div>
      <template v-else-if="botInfo">
        <!-- Карточка бота -->
        <div class="tga-bot-card">
          <div class="tga-bot-avatar"><BkIcon name="send" size="sm" /></div>
          <div class="tga-bot-info">
            <div class="tga-bot-name">{{ botInfo.first_name }}</div>
            <div class="tga-bot-username">@{{ botInfo.username }}</div>
            <div class="tga-bot-id">ID: {{ botInfo.id }}</div>
          </div>
          <a :href="'https://t.me/' + botInfo.username" target="_blank" class="btn secondary" style="margin-left:auto;">
            Открыть в Telegram
          </a>
        </div>

        <!-- Здоровье: главное, что нужно знать о боте прямо сейчас -->
        <div class="tga-health" :class="healthClass">
          <div class="tga-health-main">
            <div class="tga-health-state">{{ healthTitle }}</div>
            <div class="tga-health-sub">{{ healthHint }}</div>
          </div>
          <div class="tga-health-nums">
            <div class="tga-health-num">
              <b>{{ formatInt(health.total_24h || 0) }}</b>
              <span>сообщений за сутки</span>
            </div>
            <div class="tga-health-num" :class="{ bad: (health.fail_24h || 0) > 0 }">
              <b>{{ formatInt(health.fail_24h || 0) }}</b>
              <span>с ошибкой</span>
            </div>
            <div class="tga-health-num" :class="{ bad: (health.blocked || 0) > 0 }">
              <b>{{ formatInt(health.blocked || 0) }}</b>
              <span>заблокировали бота</span>
            </div>
          </div>
        </div>

        <!-- Из-за чего сбои, если они есть -->
        <div v-if="health.top_errors?.length" class="tga-section-card">
          <h3 class="tga-subtitle">Из-за чего сбои за сутки</h3>
          <div class="tga-err-list">
            <div v-for="(e, i) in health.top_errors" :key="i" class="tga-err-row">
              <span class="tga-err-count">{{ e.cnt }}×</span>
              <span class="tga-err-text">{{ e.error_text || 'без описания' }}</span>
              <span class="tga-err-hint">{{ errorHint(e.error_code, e.error_text) }}</span>
            </div>
          </div>
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
              <span class="tga-action-icon"><BkIcon name="send" size="sm" /></span>
              <span>Тестовое сообщение (себе)</span>
            </button>
            <button class="tga-action-btn" @click="loadBotInfo">
              <span class="tga-action-icon"><BkIcon name="redo" size="sm" /></span>
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
              <div class="tga-stat-val">{{ restaurantUniqueChatIds.length }}</div>
              <div class="tga-stat-label">Подписчиков рест.</div>
            </div>
            <div class="tga-stat-card">
              <div class="tga-stat-val">{{ allUniqueChatIds.length }}</div>
              <div class="tga-stat-label">Всего подписчиков</div>
            </div>
            <div class="tga-stat-card">
              <div class="tga-stat-val">{{ formatInt(remindersToday) }}</div>
              <div class="tga-stat-label">Напоминаний сегодня</div>
            </div>
          </div>
        </div>

        <!-- Корректировки (7 дней) -->
        <div class="tga-section-card" v-if="corrStats">
          <h3 class="tga-subtitle">Корректировки (7 дней)</h3>
          <div class="tga-stats-row">
            <div class="tga-stat-card">
              <div class="tga-stat-val" :style="corrStats.pending > 0 ? 'color:#e65100' : ''">{{ corrStats.pending || 0 }}</div>
              <div class="tga-stat-label">Ожидают</div>
            </div>
            <div class="tga-stat-card">
              <div class="tga-stat-val" style="color:#1565c0">{{ corrStats.in_progress || 0 }}</div>
              <div class="tga-stat-label">В работе</div>
            </div>
            <div class="tga-stat-card">
              <div class="tga-stat-val" style="color:#2e7d32">{{ corrStats.approved || 0 }}</div>
              <div class="tga-stat-label">Одобрено</div>
            </div>
            <div class="tga-stat-card">
              <div class="tga-stat-val" style="color:#c62828">{{ corrStats.rejected || 0 }}</div>
              <div class="tga-stat-label">Отклонено</div>
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
      <p class="tga-hint">
        Кто из сотрудников какие уведомления получает. Нажмите на ячейку, чтобы включить или выключить.
        Заголовок колонки переключает её всем сразу.
      </p>
      <div class="tga-table-wrap">
        <table class="tga-table tga-notif-table">
          <thead>
            <tr>
              <th class="tga-notif-name">Сотрудник</th>
              <th v-for="n in NOTIF_TYPES" :key="n.key" class="tga-notif-col">
                <button class="tga-notif-head" :title="'Включить или выключить «' + n.label + '» всем'"
                        @click="toggleColumn(n.key)">
                  <BkIcon :name="n.icon" size="sm" />
                  <span>{{ n.label }}</span>
                  <small>{{ countOn(n.key) }} из {{ linkedUsers.length }}</small>
                </button>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in linkedUsers" :key="u.name">
              <td class="tga-notif-name"><b>{{ u.name }}</b></td>
              <td v-for="n in NOTIF_TYPES" :key="n.key"
                  :class="cellClass(u[n.key])" class="tga-cell-toggle"
                  :title="(u[n.key] ? 'Выключить' : 'Включить') + ' «' + n.label + '» для ' + u.name"
                  @click="toggleSetting(u, n.key)">{{ u[n.key] ? '✓' : '✕' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ═══ Рестораны: подписки ═══ -->
    <div v-else-if="tab === 'restaurants'" class="adm-section">
      <div class="tga-stats-row">
        <div class="tga-stat-card">
          <div class="tga-stat-val">{{ restaurantActiveSubCount }}</div>
          <div class="tga-stat-label">Активных привязок</div>
        </div>
        <div class="tga-stat-card">
          <div class="tga-stat-val">{{ restaurantTemporarySubCount }}</div>
          <div class="tga-stat-label">Ждут перепривязки</div>
        </div>
        <div class="tga-stat-card">
          <div class="tga-stat-val">{{ restaurantExpiredSubCount }}</div>
          <div class="tga-stat-label">Истёк срок</div>
        </div>
        <div class="tga-stat-card">
          <div class="tga-stat-val">{{ inactiveRests.length }}</div>
          <div class="tga-stat-label">Без активной привязки</div>
        </div>
      </div>

      <!-- Подтабы: Рестораны / Уведомления -->
      <div class="tga-subtabs">
        <button class="tga-subtab" :class="{ active: restaurantSubTab === 'rests' }" @click="restaurantSubTab = 'rests'">Рестораны</button>
        <button class="tga-subtab" :class="{ active: restaurantSubTab === 'notif' }" @click="restaurantSubTab = 'notif'">Уведомления подписчиков</button>
        <button v-if="restaurantExpiredSubCount > 0" class="btn secondary" style="margin-left:auto;color:#d32f2f;" @click="unlinkExpiredSubs" :disabled="unlinkExpiredLoading">
          {{ unlinkExpiredLoading ? 'Отвязываем…' : `Отвязать просроченные (${restaurantExpiredSubCount})` }}
        </button>
      </div>

      <!-- Рестораны -->
      <template v-if="restaurantSubTab === 'rests'">
        <div class="tga-filter-row">
          <select v-model="restaurantFilter" class="tga-select">
            <option value="all">Все</option>
            <option value="active">Есть активная</option>
            <option value="temporary">Ждут перепривязки</option>
            <option value="expired">Срок истёк</option>
            <option value="unsubscribed">Без активной</option>
          </select>
          <input v-model="restaurantSearch" class="tga-input" placeholder="Поиск по номеру или адресу..."/>
        </div>

        <div class="tga-table-wrap">
          <table class="tga-table">
            <thead>
              <tr>
                <th style="width:80px">Ресторан</th>
                <th>Адрес</th>
                <th>Город</th>
                <th>Статус</th>
                <th>Подписчики</th>
                <th>Дата подписки</th>
                <th style="width:50px"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in filteredVegRests" :key="r.key" :class="{ 'tga-row-muted': !r.activeSubCount }">
                <td><b>{{ formatRestaurantNumber(r.number, r.legal_entity_group) }}</b></td>
                <td>{{ r.address || '—' }}</td>
                <td>{{ r.city || '—' }}</td>
                <td>
                  <span class="tga-status-pill" :class="'tga-status-' + r.status">{{ restaurantStatusLabel(r) }}</span>
                </td>
                <td :class="r.activeSubCount ? 'tga-cell-ok' : 'tga-cell-warn'" style="text-align:center;">
                  <template v-if="r.subscribers.length">
                    <span class="tga-sub-count-link" @click="toggleSubsList(r.key)">{{ r.activeSubCount }} / {{ r.subCount }}</span>
                    <div v-if="expandedRest === r.key" class="tga-sub-list">
                      <div v-for="(sub, si) in r.subscribers" :key="si" class="tga-sub-person">
                        <span class="tga-sub-status-dot" :class="'tga-sub-status-' + sub.verify_status"></span>
                        {{ sub.first_name || 'Без имени' }}<span v-if="sub.username" class="tga-sub-username"> @{{ sub.username }}</span>
                        <div class="tga-sub-text">{{ subStatusLabel(sub) }}</div>
                      </div>
                    </div>
                  </template>
                  <template v-else>—</template>
                </td>
                <td>{{ r.firstSub ? formatDate(r.firstSub) : '—' }}</td>
                <td>
                  <button v-if="r.activeSubCount" class="tga-btn-sm" @click="sendVegReminder(r)" title="Отправить напоминание"><BkIcon name="send" size="sm" /></button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <!-- Уведомления подписчиков -->
      <template v-if="restaurantSubTab === 'notif'">
        <p class="tga-hint">Настройки уведомлений ресторанных подписчиков. Нажмите, чтобы переключить.</p>
        <div class="tga-table-wrap">
          <table class="tga-table tga-table-compact">
            <thead>
              <tr>
                <th style="text-align:left">Подписчик</th>
                <th style="text-align:left">Рестораны</th>
                <th title="Напоминания о заявках"><BkIcon name="bell" size="sm" /></th>
                <th title="Новые периоды приёма"><BkIcon name="bell" size="sm" /></th>
                <th title="Подтверждения заявок"><BkIcon name="success" size="sm" /></th>
                <th title="Напоминания об остатках"><BkIcon name="clipboard" size="sm" /></th>
                <th title="Новые сборы остатков"><BkIcon name="package" size="sm" /></th>
                <th title="Возврат кег"><BkIcon name="kegReturn" size="sm" /></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="sub in restaurantUniqueSubscribers" :key="sub.chat_id">
                <td style="text-align:left">
                  <b>{{ sub.first_name || 'Без имени' }}</b>
                  <span v-if="sub.username" class="tga-sub-username"> @{{ sub.username }}</span>
                </td>
                <td style="text-align:left" class="tga-sub-text">{{ sub.restaurants.join(', ') }}</td>
                <td :class="cellClass(sub.notify_so_reminders)" class="tga-cell-toggle" @click="toggleRestNotif(sub, 'notify_so_reminders')">{{ sub.notify_so_reminders ? '✓' : '✕' }}</td>
                <td :class="cellClass(sub.notify_so_sessions)" class="tga-cell-toggle" @click="toggleRestNotif(sub, 'notify_so_sessions')">{{ sub.notify_so_sessions ? '✓' : '✕' }}</td>
                <td :class="cellClass(sub.notify_confirmations)" class="tga-cell-toggle" @click="toggleRestNotif(sub, 'notify_confirmations')">{{ sub.notify_confirmations ? '✓' : '✕' }}</td>
                <td :class="cellClass(sub.notify_stock_reminders)" class="tga-cell-toggle" @click="toggleRestNotif(sub, 'notify_stock_reminders')">{{ sub.notify_stock_reminders ? '✓' : '✕' }}</td>
                <td :class="cellClass(sub.notify_stock_sessions)" class="tga-cell-toggle" @click="toggleRestNotif(sub, 'notify_stock_sessions')">{{ sub.notify_stock_sessions ? '✓' : '✕' }}</td>
                <td :class="cellClass(sub.notify_keg_returns)" class="tga-cell-toggle" @click="toggleRestNotif(sub, 'notify_keg_returns')">{{ sub.notify_keg_returns ? '✓' : '✕' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="tga-legend">
          <span><BkIcon name="bell" size="sm" /> Напоминания о заявках</span>
          <span><BkIcon name="bell" size="sm" /> Новые периоды приёма</span>
          <span><BkIcon name="success" size="sm" /> Подтверждения заявок</span>
          <span><BkIcon name="clipboard" size="sm" /> Напоминания об остатках</span>
          <span><BkIcon name="package" size="sm" /> Новые сборы остатков</span>
          <span><BkIcon name="kegReturn" size="sm" /> Возврат кег</span>
        </div>
      </template>
    </div>

    <!-- ═══ Журнал напоминаний ═══ -->
    <div v-else-if="tab === 'log'" class="adm-section">
      <p class="tga-hint">
        Последние {{ reminderLog.length }} напоминаний, которые бот и портал разослали ресторанам.
      </p>

      <!-- Фильтры -->
      <div class="tga-filter-row">
        <input type="date" v-model="logDateFrom" class="tga-input" style="min-width:150px;flex:0;" title="Дата с"/>
        <input type="date" v-model="logDateTo" class="tga-input" style="min-width:150px;flex:0;" title="Дата по"/>
        <input v-model="logRestSearch" class="tga-input" style="min-width:120px;max-width:220px;" placeholder="Номер ресторана"/>
        <select v-model="logTypeFilter" class="tga-select">
          <option value="all">Все напоминания</option>
          <option value="supplier">Заявки поставщикам</option>
          <option value="main_delivery">Основная поставка</option>
          <option value="keg_return">Возврат кег</option>
          <option value="keg_invoice">Накладные на кеги</option>
          <option value="keg_routing">Маршрут кег</option>
        </select>
        <select v-model="logChannelFilter" class="tga-select">
          <option value="all">Куда угодно</option>
          <option value="telegram">В Telegram</option>
          <option value="portal">В портал</option>
          <option value="push">Push на телефон</option>
        </select>
      </div>

      <div v-if="!filteredReminderLog.length" class="tga-empty">
        {{ reminderLog.length ? 'Под фильтр ничего не попало' : 'Записей пока нет' }}
      </div>
      <div v-else class="tga-table-wrap">
        <table class="tga-table">
          <thead>
            <tr>
              <th>Когда</th>
              <th>Что напомнили</th>
              <th>Кому</th>
              <th>Куда</th>
              <th>На какую дату</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(r, i) in filteredReminderLog" :key="i">
              <td>{{ formatDate(r.sent_at) }}</td>
              <td>
                <span class="tga-badge" :class="'tga-kind-' + r.reminder_kind">{{ reminderLabel(r.reminder_kind) }}</span>
                <span v-if="r.supplier_name" class="tga-log-sup">{{ r.supplier_name }}</span>
              </td>
              <td>
                <template v-if="r.restaurant_number">
                  <b>{{ formatRestaurantNumber(r.restaurant_number, r.legal_entity_group) }}</b>
                  <span class="tga-log-place">{{ r.city || r.address || '' }}</span>
                </template>
                <span v-else class="tga-log-place">всем сразу</span>
              </td>
              <td>{{ channelLabel(r.channel) }}</td>
              <td>{{ r.target_date ? formatDateShort(r.target_date) : '—' }}</td>
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
            <button class="tga-btn-chip" :class="{ active: broadcastTarget === 'all_restaurants' }" @click="broadcastTarget = 'all_restaurants'">
              Все рестораны ({{ restaurantUniqueChatIds.length }})
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
        <div v-if="broadcastHistoryLoading" class="tga-empty"><BurgerSpinner text="Загрузка..." /></div>
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
      <div v-if="questionsLoading" class="tga-empty"><BurgerSpinner text="Загрузка..." /></div>
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
import BkIcon from '@/components/ui/BkIcon.vue';
import { useTabRoute } from '@/composables/useTabRoute.js'
import { db } from '@/lib/apiClient.js'
import { formatRestaurantNumber } from '@/lib/legalEntities.js'
import { formatInt } from '@/lib/utils.js'
import { useUserStore } from '@/stores/userStore.js'
import { appConfirm, appAlert, appPrompt } from '@/lib/appDialogs.js'

const userStore = useUserStore()

const tab = useTabRoute('bot', ['bot', 'restaurants', 'users', 'questions', 'broadcast', 'log', 'settings'])
const loading = ref(true)

const linkedUsers = ref([])
const unlinkedUsers = ref([])
const restaurantSubs = ref([])
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
const restaurantFilter = ref('all')
const restaurantSearch = ref('')
const expandedRest = ref(null)
const restaurantSubTab = ref('rests')
const unlinkExpiredLoading = ref(false)

// Corrections
const corrStats = ref(null)

// Reminder log filters
const logDateFrom = ref('')
const logDateTo = ref('')
const logRestSearch = ref('')
const logTypeFilter = ref('all')
const logChannelFilter = ref('all')

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
    restaurantSubs.value = data.restaurant_subs || []
    allRestaurants.value = data.all_restaurants || []
    reminderLog.value = data.reminder_log || []
    health.value = data.health || {}
    corrStats.value = data.correction_stats || null
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
  if (!(await appConfirm('Удалить вебхук? Бот перестанет получать сообщения.', { okText: 'Удалить', danger: true }))) return
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

onMounted(() => {
  loadData();
  loadBotInfo();
  // Догружаем данные текущей вкладки, если она пришла из URL `?tab=...`
  if (tab.value === 'questions') loadQuestions();
})

// ═══ Computed ═══

function restKey(number, group = 'BK_VM') {
  return `${group || 'BK_VM'}:${number}`
}

function isActiveRestaurantSub(sub) {
  return sub.verify_status === 'verified' || sub.verify_status === 'temporary'
}

const restaurantActiveSubCount = computed(() => restaurantSubs.value.filter(isActiveRestaurantSub).length)
const restaurantTemporarySubCount = computed(() => restaurantSubs.value.filter(s => s.verify_status === 'temporary').length)
const restaurantExpiredSubCount = computed(() => restaurantSubs.value.filter(s => s.verify_status === 'expired' || s.verify_status === 'unverified').length)

const restaurantSubMap = computed(() => {
  const map = {}
  for (const s of restaurantSubs.value) {
    const key = restKey(s.restaurant_number, s.legal_entity_group)
    if (!map[key]) {
      map[key] = { count: 0, activeCount: 0, temporaryCount: 0, expiredCount: 0, firstSub: s.created_at, subscribers: [] }
    }
    map[key].count++
    if (isActiveRestaurantSub(s)) map[key].activeCount++
    if (s.verify_status === 'temporary') map[key].temporaryCount++
    if (s.verify_status === 'expired' || s.verify_status === 'unverified') map[key].expiredCount++
    map[key].subscribers.push({
      first_name: s.first_name,
      username: s.username,
      chat_id: s.chat_id,
      verify_status: s.verify_status,
      verified_at: s.verified_at,
      must_reverify_by: s.must_reverify_by,
      created_at: s.created_at,
    })
    if (s.created_at < map[key].firstSub) {
      map[key].firstSub = s.created_at
    }
  }
  return map
})

const inactiveRests = computed(() => allRestaurants.value.filter(r => !(restaurantSubMap.value[restKey(r.number, r.legal_entity_group)]?.activeCount || 0)))

const filteredVegRests = computed(() => {
  let list = allRestaurants.value.map(r => {
    const key = restKey(r.number, r.legal_entity_group)
    const subInfo = restaurantSubMap.value[key]
    const activeSubCount = subInfo?.activeCount || 0
    const temporarySubCount = subInfo?.temporaryCount || 0
    const expiredSubCount = subInfo?.expiredCount || 0
    let status = 'none'
    if (activeSubCount > 0) status = temporarySubCount > 0 ? 'temporary' : 'active'
    else if (expiredSubCount > 0) status = 'expired'
    return {
      key,
      number: r.number,
      legal_entity_group: r.legal_entity_group,
      address: r.address,
      city: r.city,
      region: r.region,
      status,
      subCount: subInfo?.count || 0,
      activeSubCount,
      temporarySubCount,
      expiredSubCount,
      subscribers: subInfo?.subscribers || [],
      firstSub: subInfo?.firstSub || null,
    }
  })

  if (restaurantFilter.value === 'active') list = list.filter(r => r.activeSubCount > 0)
  if (restaurantFilter.value === 'temporary') list = list.filter(r => r.temporarySubCount > 0)
  if (restaurantFilter.value === 'expired') list = list.filter(r => r.expiredSubCount > 0 && r.activeSubCount === 0)
  if (restaurantFilter.value === 'unsubscribed') list = list.filter(r => !r.activeSubCount)

  const q = restaurantSearch.value.toLowerCase().trim()
  if (q) {
    list = list.filter(r =>
      String(r.number).toLowerCase().includes(q) ||
      (r.address || '').toLowerCase().includes(q) ||
      (r.city || '').toLowerCase().includes(q)
    )
  }

  return list
})

const restaurantUniqueChatIds = computed(() => {
  return [...new Set(restaurantSubs.value.filter(isActiveRestaurantSub).map(s => s.chat_id))]
})

// Понятные названия вместо служебных ключей журнала.
const REMINDER_KIND_LABELS = {
  supplier: 'Заявка поставщику',
  main_delivery: 'Основная поставка',
  keg_return: 'Возврат кег',
  keg_invoice: 'Накладные на кеги',
  keg_routing: 'Маршрут кег',
}
function reminderLabel(kind) { return REMINDER_KIND_LABELS[kind] || kind || '—' }

const CHANNEL_LABELS = { telegram: 'Telegram', portal: 'Портал', push: 'Push' }
function channelLabel(ch) { return CHANNEL_LABELS[ch] || ch || '—' }

// Уведомления сотрудников: раньше десять колонок были прописаны руками, а
// подписи прятались в легенде под таблицей. Теперь список один — из него
// строится и шапка, и строки. Заодно добавился тип «Заявки: итог», который
// работал в боте, но в интерфейсе его не было.
const NOTIF_TYPES = [
  { key: 'daily_summary',            label: 'Сводка дня',      icon: 'analytics' },
  { key: 'so_deadline_summary',      label: 'Заявки: итог',    icon: 'clipboard' },
  { key: 'psc_expiry',               label: 'ПСЦ истекает',    icon: 'document' },
  { key: 'price_changed',            label: 'Цены',            icon: 'payments' },
  { key: 'overdue_delivery',         label: 'Просрочка',       icon: 'package' },
  { key: 'data_updates',             label: 'Загрузка данных', icon: 'import' },
  { key: 'expiring_items',           label: 'Сроки годности',  icon: 'warning' },
  { key: 'restaurant_sales',         label: 'Реализация',      icon: 'veg' },
  { key: 'low_stock',                label: 'Остатки',         icon: 'chartDown' },
  { key: 'correction_notifications', label: 'Корректировки',   icon: 'edit' },
  { key: 'chat_notifications',       label: 'Сообщения',       icon: 'chat' },
]

function countOn(key) {
  return linkedUsers.value.filter(u => u[key]).length
}

// Включить или выключить тип уведомления сразу всем: если он стоит меньше чем
// у половины — включаем всем, иначе выключаем.
async function toggleColumn(key) {
  const total = linkedUsers.value.length
  if (!total) return
  const turnOn = countOn(key) * 2 <= total
  const label = NOTIF_TYPES.find(n => n.key === key)?.label || key
  const ok = await appConfirm(
    `${turnOn ? 'Включить' : 'Выключить'} «${label}» всем — это ${total} сотрудников?`,
    { okText: turnOn ? 'Включить' : 'Выключить', danger: !turnOn }
  )
  if (!ok) return
  for (const u of linkedUsers.value) {
    if (!!u[key] === turnOn) continue
    await toggleSetting(u, key)
  }
}

// ─── Здоровье бота ───
const health = ref({})

// «Работает» определяем по последней успешной отправке: вебхук может
// отвечать, а бот при этом молчать.
const minutesSinceSend = computed(() => {
  const raw = health.value.last_send_at
  if (!raw) return null
  const d = new Date(String(raw).replace(' ', 'T') + '+03:00')
  if (isNaN(d)) return null
  return Math.floor((Date.now() - d.getTime()) / 60000)
})
const failPct = computed(() => {
  const t = health.value.total_24h || 0
  return t ? Math.round((health.value.fail_24h || 0) * 100 / t) : 0
})
const healthClass = computed(() => {
  const m = minutesSinceSend.value
  if (m === null || m > 360) return 'bad'
  if (failPct.value >= 30) return 'bad'
  if (failPct.value >= 10) return 'warn'
  return 'good'
})
const healthTitle = computed(() => {
  if (healthClass.value === 'bad') return 'Бот сбоит'
  if (healthClass.value === 'warn') return 'Бот работает с ошибками'
  return 'Бот работает'
})
const healthHint = computed(() => {
  const m = minutesSinceSend.value
  if (m === null) return 'за сутки не отправлено ни одного сообщения'
  const when = m < 60 ? `${m} мин назад` : `${Math.floor(m / 60)} ч назад`
  return `последнее сообщение ${when} · ошибок ${failPct.value}%`
})

// Короткое объяснение кода ошибки — коды Telegram сами по себе ничего не говорят.
function errorHint(code, text) {
  const t = String(text || '')
  if (t.includes('bot was blocked')) return 'человек заблокировал бота'
  if (t.includes('chat not found')) return 'чат удалён или неверный'
  if (t.includes('query is too old')) return 'нажали старую кнопку'
  if (t.includes('message is not modified')) return 'экран не изменился, это не страшно'
  if (t.includes('text is too long')) return 'сообщение длиннее предела Telegram'
  if (t.includes('BUTTON_DATA_INVALID')) return 'слишком длинный адрес у кнопки'
  if (Number(code) === 429) return 'Telegram просит слать реже'
  return ''
}

// Сколько напоминаний ушло сегодня — по живому журналу.
const remindersToday = computed(() => {
  const today = new Date().toISOString().slice(0, 10)
  return reminderLog.value.filter(r => String(r.sent_at || '').slice(0, 10) === today).length
})

const filteredReminderLog = computed(() => {
  let list = reminderLog.value
  if (logDateFrom.value) {
    const from = new Date(logDateFrom.value)
    list = list.filter(r => new Date(String(r.sent_at).replace(' ', 'T')) >= from)
  }
  if (logDateTo.value) {
    const to = new Date(logDateTo.value)
    to.setDate(to.getDate() + 1)
    list = list.filter(r => new Date(String(r.sent_at).replace(' ', 'T')) < to)
  }
  if (logRestSearch.value.trim()) {
    const q = logRestSearch.value.trim().toLowerCase()
    list = list.filter(r => String(r.restaurant_number || '').toLowerCase().includes(q))
  }
  if (logTypeFilter.value !== 'all') {
    list = list.filter(r => r.reminder_kind === logTypeFilter.value)
  }
  if (logChannelFilter.value !== 'all') {
    list = list.filter(r => r.channel === logChannelFilter.value)
  }
  return list
})

const restaurantUniqueSubscribers = computed(() => {
  const map = {}
  for (const s of restaurantSubs.value.filter(isActiveRestaurantSub)) {
    if (!map[s.chat_id]) {
      map[s.chat_id] = {
        chat_id: s.chat_id,
        first_name: s.first_name,
        username: s.username,
        restaurants: [],
        notify_so_reminders: s.notify_so_reminders,
        notify_so_sessions: s.notify_so_sessions,
        notify_confirmations: s.notify_confirmations,
        notify_stock_reminders: s.notify_stock_reminders,
        notify_keg_returns: s.notify_keg_returns,
        notify_stock_sessions: s.notify_stock_sessions,
      }
    }
    map[s.chat_id].restaurants.push(formatRestaurantNumber(s.restaurant_number, s.legal_entity_group))
  }
  return Object.values(map)
})

const allUniqueChatIds = computed(() => {
  const ids = new Set()
  linkedUsers.value.forEach(u => { if (u.telegram_chat_id) ids.add(String(u.telegram_chat_id)) })
  restaurantSubs.value.filter(isActiveRestaurantSub).forEach(s => ids.add(String(s.chat_id)))
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

function subStatusLabel(sub) {
  if (sub.verify_status === 'verified') return `Привязан ${formatDate(sub.verified_at)}`
  if (sub.verify_status === 'temporary') return `Работает временно до ${formatDate(sub.must_reverify_by)}`
  if (sub.verify_status === 'expired') return `Срок перепривязки истёк ${formatDate(sub.must_reverify_by)}`
  return 'Не подтверждён'
}

function restaurantStatusLabel(rest) {
  if (rest.status === 'active') return `Активно: ${rest.activeSubCount}`
  if (rest.status === 'temporary') return `Активно: ${rest.activeSubCount}, ждут: ${rest.temporarySubCount}`
  if (rest.status === 'expired') return 'Срок истёк'
  return 'Нет активной'
}

async function unlinkUser(u) {
  if (!(await appConfirm(`Отвязать Telegram от ${u.name}? Пользователю нужно будет привязать бот заново.`, { okText: 'Отвязать', danger: true }))) return
  try {
    await db.rpc('tg_admin_unlink_user', { user_name: u.name })
    loadData()
  } catch (e) {
    await appAlert('Ошибка: ' + (e.message || e), { type: 'error' })
  }
}

async function unlinkExpiredSubs() {
  if (unlinkExpiredLoading.value) return
  unlinkExpiredLoading.value = true
  try {
    const { data: pre } = await db.rpc('tg_admin_unlink_expired', { confirm: false })
    const count = pre?.count || 0
    if (!count) {
      await appAlert('Просроченных подписок нет.', { title: 'Чистка подписок' })
      return
    }
    const ok = await appConfirm(
      `Удалить ${count} просроченных Telegram-подписок?\n\n`
      + `Удалятся только записи без подтверждения, у которых истёк срок перепривязки. `
      + `Активные подписки и переходный период не затрагиваются. `
      + `Сотрудники смогут привязаться заново обычным способом — через код в личном кабинете.`,
      { title: 'Чистка подписок', okText: 'Удалить', danger: true }
    )
    if (!ok) return
    const { data } = await db.rpc('tg_admin_unlink_expired', { confirm: true })
    await appAlert(`Удалено: ${data?.deleted || 0}`, { title: 'Готово', type: 'success' })
    loadData()
  } catch (e) {
    await appAlert('Ошибка: ' + (e.message || e), { type: 'error' })
  } finally {
    unlinkExpiredLoading.value = false
  }
}

async function sendVegReminder(rest) {
  const defaultMsg = `Напоминание для ресторана ${rest.number}: пожалуйста, подайте заявку поставщику.`
  const msg = await appPrompt('Текст напоминания:', defaultMsg, { title: 'Отправить напоминание', okText: 'Отправить' })
  if (!msg) return
  try {
    const { data } = await db.rpc('tg_admin_send_restaurant_reminder', {
      restaurant_number: rest.number,
      legal_entity_group: rest.legal_entity_group || (parseInt(rest.number, 10) >= 1000 ? 'PS' : 'BK_VM'),
      message: msg,
    })
    await appAlert(`Отправлено: ${data.sent} из ${data.total}`, { title: 'Готово', type: 'success' })
  } catch (e) {
    await appAlert('Ошибка: ' + (e.message || e), { type: 'error' })
  }
}

async function toggleRestNotif(sub, field) {
  try {
    const { data } = await db.rpc('tg_admin_toggle_rest_notif', {
      chat_id: String(sub.chat_id),
      restaurant_number: String(sub.restaurant_number),
      field,
    })
    sub[field] = data.value ? 1 : 0
    // Обновить и в restaurantSubs (только запись того же chat_id+restaurant_number)
    for (const s of restaurantSubs.value) {
      if (String(s.chat_id) === String(sub.chat_id) && String(s.restaurant_number) === String(sub.restaurant_number)) s[field] = sub[field]
    }
  } catch (e) {
    appAlert('Ошибка: ' + (e.message || e), { type: 'error' })
  }
}

async function toggleSetting(user, field) {
  try {
    const { data } = await db.rpc('tg_admin_toggle_setting', { user_name: user.name, field })
    user[field] = data.value ? 1 : 0
  } catch (e) {
    appAlert('Ошибка: ' + (e.message || e), { type: 'error' })
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
  } else if (broadcastTarget.value === 'all_restaurants') {
    chatIds = restaurantUniqueChatIds.value.map(String)
  } else {
    chatIds = allUniqueChatIds.value
  }

  if (!chatIds.length) { broadcastResult.value = 'Нет получателей'; return }
  if (!(await appConfirm(`Отправить сообщение ${chatIds.length} получателям?`, { title: 'Рассылка', okText: 'Отправить' }))) return

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

.tga-status-pill {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 96px; padding: 3px 9px; border-radius: 999px;
  font-size: 11px; font-weight: 700; white-space: nowrap;
}
.tga-status-active { background: #e8f5e9; color: #2e7d32; }
.tga-status-temporary { background: #fff3e0; color: #e65100; }
.tga-status-expired { background: #ffebee; color: #c62828; }
.tga-status-none { background: #f5f5f5; color: #777; }

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
.tga-sub-person { position: relative; padding: 3px 0 3px 14px; color: var(--text, #333); font-weight: 400; }
.tga-sub-username { color: #2AABEE; font-size: 11px; }
.tga-sub-status-dot {
  position: absolute; left: 0; top: 8px; width: 7px; height: 7px;
  border-radius: 50%; background: #bbb;
}
.tga-sub-status-verified { background: #2e7d32; }
.tga-sub-status-temporary { background: #e65100; }
.tga-sub-status-expired, .tga-sub-status-unverified { background: #c62828; }

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

/* ═══ Подтабы ═══ */
.tga-subtabs { display: flex; gap: 0; margin-bottom: 16px; }
.tga-subtab {
  padding: 7px 18px; font-size: 13px; font-weight: 600; font-family: inherit;
  color: var(--text-muted, #999); background: var(--bg, #fafafa);
  border: 1px solid var(--border, #e0e0e0); cursor: pointer; transition: all .15s;
}
.tga-subtab:first-child { border-radius: 8px 0 0 8px; }
.tga-subtab:last-child { border-radius: 0 8px 8px 0; border-left: none; }
.tga-subtab.active { background: var(--bk-brown, #8B7355); color: #fff; border-color: var(--bk-brown, #8B7355); }
.tga-subtab:hover:not(.active) { background: rgba(139,115,85,.06); }

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
  /* flex-shrink: 0 — иначе флекс сжимал вкладки, и названия наезжали друг
     на друга вместо того, чтобы прокручиваться. */
  .adm-tab { padding: 8px 14px; font-size: 12px; gap: 4px; white-space: nowrap; flex-shrink: 0; }

  /* Текст ошибки рвался по одной букве: узкая колонка внутри флекса. */
  .tga-err-row { flex-direction: column; gap: 2px; }
  .tga-err-text { width: 100%; overflow-wrap: anywhere; }
  .tga-bot-card { flex-wrap: wrap; }
  .tga-bot-card .btn { margin-left: 0 !important; width: 100%; justify-content: center; }
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

/* ═══ Здоровье бота ═══ */
.tga-health {
  display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
  padding: 16px 18px; border-radius: 12px; margin-bottom: 12px;
  border: 1px solid var(--border-light); background: var(--card);
}
.tga-health.good { border-color: #C8E6C9; background: linear-gradient(180deg, #F4FBF4, var(--card)); }
.tga-health.warn { border-color: #FFE0B2; background: linear-gradient(180deg, #FFF8EC, var(--card)); }
.tga-health.bad  { border-color: #E57373; background: linear-gradient(180deg, #FFF5F5, var(--card)); }
.tga-health-state { font-size: 17px; font-weight: 800; color: var(--text); }
.tga-health-sub { font-size: 12.5px; color: var(--text-muted); margin-top: 2px; }
.tga-health-nums { display: flex; gap: 22px; flex-wrap: wrap; }
.tga-health-num { display: flex; flex-direction: column; }
.tga-health-num b { font-size: 20px; font-weight: 800; color: var(--text); line-height: 1.1; }
.tga-health-num span { font-size: 11px; color: var(--text-muted); }
.tga-health-num.bad b { color: #C62828; }

.tga-err-list { display: flex; flex-direction: column; gap: 6px; }
.tga-err-row {
  display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap;
  padding: 8px 12px; border-radius: 9px; background: var(--bg); border: 1px solid var(--border-light);
  font-size: 12.5px;
}
.tga-err-count { font-weight: 700; color: #C62828; flex-shrink: 0; }
.tga-err-text { color: var(--text); flex: 1; min-width: 0; word-break: break-word; }
.tga-err-hint { color: var(--text-muted); font-size: 11.5px; }

.tga-log-sup { margin-left: 8px; font-size: 11.5px; color: var(--text-muted); }
.tga-log-place { margin-left: 6px; font-size: 11.5px; color: var(--text-muted); }
.tga-kind-supplier { background: #FFF3E0; color: #E65100; }
.tga-kind-main_delivery { background: #E3F2FD; color: #1565C0; }
.tga-kind-keg_return, .tga-kind-keg_invoice, .tga-kind-keg_routing { background: #EDE7F6; color: #5E35B1; }

@media (max-width: 700px) {
  .tga-health { flex-direction: column; align-items: flex-start; gap: 10px; }
  .tga-health-nums { gap: 14px; }
}

/* ═══ Уведомления сотрудников ═══ */
.tga-notif-table th { vertical-align: bottom; }
.tga-notif-name { text-align: left; white-space: nowrap; }
.tga-notif-col { padding: 4px !important; }
.tga-notif-head {
  display: flex; flex-direction: column; align-items: center; gap: 2px;
  width: 100%; padding: 6px 4px; border: none; background: none; cursor: pointer;
  font-family: inherit; color: var(--text-muted); border-radius: 8px; transition: background .15s;
}
.tga-notif-head:hover { background: rgba(0, 0, 0, .04); color: var(--text); }
.tga-notif-head span { font-size: 10.5px; font-weight: 600; line-height: 1.15; text-align: center; }
.tga-notif-head small { font-size: 9.5px; opacity: .75; }

/* Одиннадцать колонок в экран не помещаются — таблица прокручивается вбок,
   а имя сотрудника остаётся на месте. Фон обязателен: без него содержимое
   просвечивает под липкой колонкой. */
.tga-notif-table .tga-notif-name {
  position: sticky; left: 0; z-index: 1;
  background: var(--card);
}
.tga-notif-table thead .tga-notif-name { z-index: 2; background: var(--bg); }
.tga-notif-table tbody tr:hover .tga-notif-name { background: #fcfaf6; }
</style>
