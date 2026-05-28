<template>
  <div class="admin-view">
    <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;">
      <h1 class="page-title"><BkIcon name="gear" size="sm"/> Администрирование</h1>
    </div>

    <!-- Табы -->
    <div class="adm-tabs">
      <button class="adm-tab" :class="{ active: activeTab === 'users' }" @click="activeTab = 'users'">
        <BkIcon name="user" size="sm"/> Сотрудники <span class="adm-tab-count" :class="{ active: activeTab === 'users' }">{{ users.length }}</span>
      </button>
      <button class="adm-tab" :class="{ active: activeTab === 'restaurant-accounts' }" @click="activeTab = 'restaurant-accounts'">
        <BkIcon name="user" size="sm"/> Кабинеты ресторанов
      </button>
      <button class="adm-tab" :class="{ active: activeTab === 'email-imports' }" @click="activeTab = 'email-imports'">
        <BkIcon name="mail" size="sm"/> Импорт по email
      </button>
      <button class="adm-tab" :class="{ active: activeTab === 'maintenance' }" @click="activeTab = 'maintenance'">
        <BkIcon name="warning" size="sm"/> Тех. работы
        <span v-if="maintenanceOn" class="adm-tab-dot"></span>
      </button>
      <button class="adm-tab" :class="{ active: activeTab === 'broadcast' }" @click="activeTab = 'broadcast'">
        <BkIcon name="bell" size="sm"/> Рассылка
      </button>
      <button class="adm-tab" :class="{ active: activeTab === 'audit' }" @click="activeTab = 'audit'">
        <BkIcon name="note" size="sm"/> Журнал
        <span class="adm-tab-count" :class="{ active: activeTab === 'audit' }">{{ auditTotal || '' }}</span>
      </button>
      <button class="adm-tab" :class="{ active: activeTab === 'stats' }" @click="activeTab = 'stats'">
        <BkIcon name="analytics" size="sm"/> Статистика
      </button>
      <button class="adm-tab" :class="{ active: activeTab === 'backup' }" @click="activeTab = 'backup'">
        <BkIcon name="database" size="sm"/> Бэкап
      </button>
      <button class="adm-tab" :class="{ active: activeTab === 'sessions' }" @click="activeTab = 'sessions'">
        <BkIcon name="key" size="sm"/> Сессии
        <span class="adm-tab-count" :class="{ active: activeTab === 'sessions' }">{{ onlineUsers.length }}</span>
      </button>
      <button class="adm-tab" :class="{ active: activeTab === 'feedback' }" @click="activeTab = 'feedback'; loadBugReports()">
        <BkIcon name="feedback" size="sm"/> Обращения
        <span v-if="bugNewCount" class="adm-tab-dot"></span>
        <span class="adm-tab-count" :class="{ active: activeTab === 'feedback' }">{{ bugReports.length || '' }}</span>
      </button>
      <button class="adm-tab" :class="{ active: activeTab === 'cron-reminders' }" @click="activeTab = 'cron-reminders'; loadCronReminders()">
        <BkIcon name="bell" size="sm"/> Крон напоминаний
        <span v-if="cronErrCount" class="adm-tab-dot"></span>
      </button>
      <button class="adm-tab" :class="{ active: activeTab === 'bot-monitor' }" @click="activeTab = 'bot-monitor'">
        <BkIcon name="analytics" size="sm"/> Бот-монитор
      </button>
    </div>

    <!-- ═══ Пользователи ═══ -->
    <div v-if="activeTab === 'users'" class="adm-section">
      <div class="adm-toolbar">
        <div class="adm-toolbar-info">{{ users.length }} {{ usersWord }}</div>
        <button class="btn primary" @click="openUserModal(null)">
          <BkIcon name="add" size="sm"/> Новый пользователь
        </button>
      </div>

      <div v-if="loading" style="text-align:center;padding:48px;"><BurgerSpinner text="Загрузка..." /></div>
      <div v-else-if="!users.length" class="adm-empty">Нет пользователей</div>

      <div v-else class="adm-user-list">
        <div v-for="u in users" :key="u.id" class="adm-user-row" @click="openUserModal(u)">
          <div class="adm-user-avatar" :class="{ admin: u.role === 'admin' }">{{ initials(u.name) }}</div>

          <div class="adm-user-info">
            <div class="adm-user-name">
              {{ u.name }}
              <span v-if="u.role === 'admin'" class="adm-badge adm-badge-admin">admin</span>
              <span v-else-if="u.role === 'viewer'" class="adm-badge adm-badge-viewer">читатель</span>
              <span v-if="u.name === userStore.currentUser?.name" class="adm-badge adm-badge-you">вы</span>
            </div>
            <div v-if="u.email" class="adm-user-email">{{ u.email }}</div>
            <div class="adm-user-meta">
              {{ u.display_role || ({ admin: 'Администратор', manager: 'Руководитель', viewer: 'Читатель' }[u.role] || 'Сотрудник') }}
            </div>
          </div>

          <div class="adm-user-entities">
            <span v-for="le in parseLe(u.legal_entities)" :key="le" class="adm-entity">{{ shortEntity(le) }}</span>
            <span v-if="!parseLe(u.legal_entities).length" class="adm-entity adm-entity-all">Все</span>
          </div>

          <div class="adm-user-actions">
            <button class="adm-act-btn" @click.stop="openUserModal(u)" title="Редактировать"><BkIcon name="edit" size="sm"/></button>
            <button class="adm-act-btn adm-act-del" @click.stop="deleteUser(u)" title="Удалить"
              :disabled="u.name === userStore.currentUser?.name"><BkIcon name="delete" size="sm"/></button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══ Кабинеты ресторанов ═══ -->
    <div v-if="activeTab === 'restaurant-accounts'" class="adm-section">
      <AdminRestaurantAccountsTab />
    </div>

    <div v-if="activeTab === 'email-imports'" class="adm-section">
      <AdminEmailImportsTab />
    </div>

    <!-- ═══ Тех. работы ═══ -->
    <div v-if="activeTab === 'maintenance'" class="adm-section">
      <div class="adm-maint-card" :class="{ on: maintenanceOn }">
        <div class="adm-maint-icon">
          <svg viewBox="0 0 48 48" width="48" height="48" fill="none">
            <circle cx="24" cy="24" r="22" :fill="maintenanceOn ? 'rgba(211,47,47,0.08)' : 'rgba(0,0,0,0.03)'" :stroke="maintenanceOn ? '#D32F2F' : 'var(--border)'" stroke-width="2"/>
            <path d="M24 14v12" :stroke="maintenanceOn ? '#D32F2F' : 'var(--text-muted)'" stroke-width="3.5" stroke-linecap="round"/>
            <circle cx="24" cy="32" r="2.5" :fill="maintenanceOn ? '#D32F2F' : 'var(--text-muted)'"/>
          </svg>
        </div>

        <div class="adm-maint-body">
          <h3 class="adm-maint-title">Режим технических работ</h3>
          <p class="adm-maint-desc">
            Когда режим включён, все пользователи кроме администраторов видят заглушку и не могут работать в системе.
          </p>
        </div>

        <button class="adm-maint-toggle" :class="{ on: maintenanceOn }" @click="toggleMaintenance" :disabled="maintenanceSaving">
          <span class="adm-maint-track"><span class="adm-maint-thumb"></span></span>
          <span class="adm-maint-label">{{ maintenanceOn ? 'Включён' : 'Выключен' }}</span>
        </button>
      </div>

      <div v-if="maintenanceOn" class="adm-maint-warning">
        <BkIcon name="warning" size="sm"/>
        <span>Сайт <b>недоступен</b> для обычных пользователей прямо сейчас</span>
      </div>

      <!-- Таймер -->
      <div class="adm-maint-msg-card">
        <h4 class="adm-maint-msg-title">Автовыключение</h4>
        <p class="adm-maint-msg-hint">Тех. работы автоматически выключатся в указанное время. Пользователи увидят обратный отсчёт.</p>

        <div class="adm-timer-row">
          <button v-for="opt in quickTimerOptions" :key="opt.min" class="adm-timer-btn"
            @click="setQuickTimer(opt.min)">
            {{ opt.label }}
          </button>
        </div>

        <div class="adm-timer-custom">
          <label class="adm-timer-custom-label">Или укажите конкретное время:</label>
          <div class="adm-timer-input-row">
            <input type="time" v-model="maintenanceTimeInput" class="adm-timer-input" />
            <button class="btn primary" style="font-size:13px;padding:7px 16px;" @click="saveExactTime" :disabled="maintenanceTimerSaving || !maintenanceTimeInput">
              <BurgerSpinner v-if="maintenanceTimerSaving" size="xs" />
              <span>{{ maintenanceTimerSaving ? 'Сохранение...' : 'Установить' }}</span>
            </button>
          </div>
        </div>

        <div v-if="maintenanceEndTimeDisplay" class="adm-timer-info">
          <span>Выключится в: <b>{{ maintenanceEndTimeDisplay }}</b></span>
          <button class="adm-timer-clear" @click="clearTimer">Сбросить</button>
        </div>
        <div v-else class="adm-timer-info adm-timer-info-off">
          Таймер не установлен — техработы нужно будет выключить вручную
        </div>
      </div>

      <div class="adm-maint-msg-card">
        <h4 class="adm-maint-msg-title">Сообщение для пользователей</h4>
        <p class="adm-maint-msg-hint">Отображается на экране технических работ. Если пусто — показывается стандартный текст.</p>
        <textarea v-model="maintenanceMsg" class="adm-maint-textarea" rows="3" placeholder="Например: Обновление системы до 18:00. Приносим извинения за неудобства."></textarea>
        <button class="btn primary" style="margin-top:8px;font-size:13px;padding:7px 16px;" @click="saveMaintenanceMsg" :disabled="maintenanceMsgSaving">
          <BurgerSpinner v-if="maintenanceMsgSaving" size="xs" />
          <span>{{ maintenanceMsgSaving ? 'Сохранение...' : 'Сохранить сообщение' }}</span>
        </button>
      </div>
    </div>

    <!-- ═══ Рассылка ═══ -->
    <div v-if="activeTab === 'broadcast'" class="adm-section">
      <!-- Переключатель: Уведомления / Обновления -->
      <div class="adm-audit-mode">
        <button class="adm-audit-mode-btn" :class="{ active: broadcastMode === 'broadcast' }" @click="broadcastMode = 'broadcast'">
          <BkIcon name="bell" size="sm"/> Уведомления
        </button>
        <button class="adm-audit-mode-btn" :class="{ active: broadcastMode === 'changelog' }" @click="broadcastMode = 'changelog'; loadChangelogIfNeeded()">
          <BkIcon name="bulb" size="sm"/> Обновления
        </button>
      </div>

      <!-- Уведомления -->
      <template v-if="broadcastMode === 'broadcast'">
        <div class="adm-maint-card">
          <div class="adm-maint-icon">
            <svg viewBox="0 0 48 48" width="48" height="48" fill="none">
              <circle cx="24" cy="24" r="22" fill="rgba(253,189,16,0.08)" stroke="#FDBD10" stroke-width="2"/>
              <path d="M24 12C24 12 12 18 12 28c0 3 0 5 1.5 6.5h21c1.5-1.5 1.5-3.5 1.5-6.5 0-10-12-16-12-16z" stroke="#FDBD10" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
              <rect x="20" y="34.5" width="8" height="3" rx="1.5" fill="#FDBD10" opacity=".5"/>
              <path d="M21 37.5a3 3 0 006 0" stroke="#FDBD10" stroke-width="2" stroke-linecap="round"/>
            </svg>
          </div>
          <div class="adm-maint-body">
            <h3 class="adm-maint-title">Рассылка уведомлений</h3>
            <p class="adm-maint-desc">
              Отправьте важное сообщение всем сотрудникам. Оно появится как всплывающее окно, которое нельзя пропустить.
            </p>
          </div>
        </div>

        <div class="adm-maint-msg-card" style="margin-top:16px;">
          <h4 class="adm-maint-msg-title">Новое сообщение</h4>
          <p class="adm-maint-msg-hint">Один и тот же текст уйдёт во все выбранные направления.</p>
          <div style="display:flex;flex-direction:column;gap:10px;">
            <input v-model="bcTitle" class="adm-maint-textarea" style="resize:none;height:auto;padding:10px 14px;" placeholder="Заголовок (необязательно)" />
            <textarea v-model="bcMessage" class="adm-maint-textarea" rows="4" placeholder="Текст сообщения..."></textarea>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:8px;margin-top:12px;">
            <label class="adm-checkbox" style="display:flex;align-items:flex-start;gap:8px;font-size:13px;cursor:pointer;">
              <input type="checkbox" v-model="bcTargets.staffCabinet" style="width:16px;height:16px;cursor:pointer;margin-top:2px;" />
              <span>В кабинет отдела закупок</span>
            </label>
            <label class="adm-checkbox" style="display:flex;align-items:flex-start;gap:8px;font-size:13px;cursor:pointer;">
              <input type="checkbox" v-model="bcTargets.restaurantCabinet" style="width:16px;height:16px;cursor:pointer;margin-top:2px;" />
              <span>В кабинет ресторанам</span>
            </label>
            <label class="adm-checkbox" style="display:flex;align-items:flex-start;gap:8px;font-size:13px;cursor:pointer;">
              <input type="checkbox" v-model="bcTargets.staffTelegram" style="width:16px;height:16px;cursor:pointer;margin-top:2px;" />
              <span>В Telegram отдела закупок</span>
            </label>
            <label class="adm-checkbox" style="display:flex;align-items:flex-start;gap:8px;font-size:13px;cursor:pointer;">
              <input type="checkbox" v-model="bcTargets.restaurantTelegram" style="width:16px;height:16px;cursor:pointer;margin-top:2px;" />
              <span>В Telegram ресторанам</span>
            </label>
          </div>
          <button class="btn primary" style="margin-top:10px;font-size:13px;padding:9px 20px;" @click="sendBroadcast" :disabled="bcSending || !bcMessage.trim() || !bcHasAnyTarget">
            {{ bcSending ? 'Отправка...' : 'Отправить' }}
          </button>
        </div>

        <div class="adm-maint-msg-card" style="margin-top:16px;">
          <h4 class="adm-maint-msg-title">История рассылок</h4>
          <div v-if="bcHistoryLoading" style="text-align:center;padding:24px;"><BurgerSpinner text="Загрузка..." /></div>
          <div v-else-if="!bcHistory.length" style="text-align:center;padding:24px;color:var(--text-muted);font-size:13px;">Ещё не было рассылок</div>
          <div v-else style="display:flex;flex-direction:column;gap:8px;">
            <div v-for="b in bcHistory" :key="b.broadcast_group || b.id" class="bc-history-item">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                <div style="flex:1;min-width:0;">
                  <div class="bc-history-title">{{ b.title || 'Важное сообщение' }}</div>
                  <div class="bc-history-msg">{{ b.message }}</div>
                  <div class="bc-history-meta">
                    {{ b.sender || b.created_by }} &middot; {{ formatBcDate(b.created_at) }}
                    <span v-if="formatBroadcastTargets(b)"> &middot; {{ formatBroadcastTargets(b) }}</span>
                    <span v-if="broadcastTelegramStats(b)"> &middot; {{ broadcastTelegramStats(b) }}</span>
                  </div>
                </div>
                <button class="bc-delete-btn" @click="deleteBroadcast(b)" :disabled="b._deleting" title="Удалить рассылку">
                  <svg viewBox="0 0 20 20" width="16" height="16" fill="currentColor"><path d="M6 2a1 1 0 00-1 1v1H3a1 1 0 000 2h1v10a2 2 0 002 2h8a2 2 0 002-2V6h1a1 1 0 100-2h-2V3a1 1 0 00-1-1H6zm2 2h4v1H8V4zm-2 4a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z"/></svg>
                </button>
              </div>
            </div>
          </div>
        </div>
      </template>

      <!-- Обновления (Что нового) -->
      <template v-if="broadcastMode === 'changelog'">
        <div class="adm-maint-msg-card">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
            <h4 class="adm-maint-msg-title" style="margin:0;">Что нового</h4>
            <button class="btn primary" style="font-size:12px;padding:5px 14px;" @click="openChangelogModal(null)">
              <BkIcon name="add" size="sm"/> Добавить
            </button>
          </div>
          <p class="adm-maint-msg-hint">Записи об обновлениях системы. Все пользователи видят их в разделе «Уведомления».</p>

          <div v-if="changelogLoading" style="text-align:center;padding:24px;"><BurgerSpinner text="Загрузка..." /></div>
          <div v-else-if="!changelogEntries.length" style="text-align:center;padding:24px;color:var(--text-muted);font-size:13px;">Нет записей об обновлениях</div>
          <div v-else style="display:flex;flex-direction:column;gap:6px;">
            <div v-for="entry in changelogEntries" :key="entry.id" class="bc-history-item">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                <div style="flex:1;min-width:0;">
                  <div class="bc-history-title">
                    <span class="adm-changelog-version">v{{ entry.version }}</span>
                    {{ entry.title }}
                  </div>
                  <div v-if="entry.description" class="bc-history-msg">{{ entry.description }}</div>
                  <div class="bc-history-meta">{{ entry.created_by }} &middot; {{ formatBcDate(entry.created_at) }}</div>
                </div>
                <div style="display:flex;gap:2px;flex-shrink:0;">
                  <button class="bc-delete-btn" @click="openChangelogModal(entry)" title="Редактировать">
                    <BkIcon name="edit" size="sm"/>
                  </button>
                  <button class="bc-delete-btn" @click="deleteChangelog(entry)" title="Удалить">
                    <BkIcon name="delete" size="sm"/>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- ═══ Журнал ═══ -->
    <div v-if="activeTab === 'audit'" class="adm-section">
      <!-- Переключатель: Аудит / Ошибки -->
      <div class="adm-audit-mode">
        <button class="adm-audit-mode-btn" :class="{ active: auditMode === 'audit' }" @click="auditMode = 'audit'">
          <BkIcon name="note" size="sm"/> Аудит
        </button>
        <button class="adm-audit-mode-btn" :class="{ active: auditMode === 'errors' }" @click="auditMode = 'errors'; loadErrorsIfNeeded()">
          <BkIcon name="error" size="sm"/> Ошибки
        </button>
      </div>

      <!-- Аудит -->
      <template v-if="auditMode === 'audit'">
        <div class="adm-audit-filters">
          <div class="adm-audit-filter-row">
            <div class="adm-audit-chips">
              <button v-for="cat in auditCategories" :key="cat.value" class="adm-audit-chip"
                :class="{ active: auditFilter.category === cat.value }" @click="auditFilter.category = cat.value; loadAudit(true)">
                {{ cat.label }}
              </button>
            </div>
            <div class="adm-audit-right-filters">
              <select v-model="auditFilter.user" @change="loadAudit(true)" class="adm-audit-select">
                <option value="">Все пользователи</option>
                <option v-for="u in auditUsers" :key="u" :value="u">{{ u }}</option>
              </select>
              <input type="date" v-model="auditFilter.dateFrom" @change="loadAudit(true)" class="adm-audit-date" />
              <input type="date" v-model="auditFilter.dateTo" @change="loadAudit(true)" class="adm-audit-date" />
            </div>
          </div>
        </div>

        <div v-if="auditLoading && !auditEntries.length" style="text-align:center;padding:48px;"><BurgerSpinner text="Загрузка журнала..." /></div>
        <div v-else-if="!auditEntries.length" class="adm-empty">Нет записей</div>

        <div v-else class="adm-audit-list">
          <div v-for="log in auditEntries" :key="log.id" class="adm-audit-entry">
            <div class="adm-audit-head">
              <span class="adm-audit-badge" :class="auditBadgeClass(log.action)">{{ auditBadgeLabel(log.action) }}</span>
              <span class="adm-audit-entity-badge" :class="'adm-audit-et-' + log.entity_type">{{ auditEntityLabel(log.entity_type) }}</span>
              <span class="adm-audit-author">{{ log.user_name || '—' }}</span>
              <span class="adm-audit-date-text">{{ formatAuditDate(log.created_at) }}</span>
            </div>

            <div v-if="log.details?.supplier" class="adm-audit-ctx">{{ log.details.supplier }}</div>
            <div v-if="log.details?.restaurant_number" class="adm-audit-ctx">Ресторан {{ formatRestaurantNumber(log.details.restaurant_number) }}</div>

            <div v-if="log.details?.param_changes?.length" class="adm-audit-params">
              <span v-for="(pc, pi) in log.details.param_changes" :key="pi" class="adm-audit-param-chip">
                {{ pc.label }}: {{ pc.from }} → {{ pc.to }}
              </span>
            </div>

            <div v-if="log.action === 'delivery_date_changed' && log.details?.old_date" class="adm-audit-delivery">
              {{ log.details.old_date }} → {{ log.details.new_date }}
            </div>

            <div v-if="log.action === 'received'" class="adm-audit-received">
              <span>{{ log.details?.items_count || 0 }} позиций</span>
              <span v-if="log.details?.discrepancies" class="adm-audit-disc">{{ log.details.discrepancies }} расхождений</span>
              <span v-else class="adm-audit-no-disc">без расхождений</span>
            </div>
            <div v-if="log.action === 'received' && log.details?.items_with_discrepancy?.length" class="adm-audit-changes">
              <span v-for="(item, i) in log.details.items_with_discrepancy" :key="i" class="adm-audit-ch adm-audit-ch-upd">
                {{ item.name }}: {{ item.ordered }} → {{ item.received }}
              </span>
            </div>

            <div v-if="log.action === 'reception_reverted' && log.details?.reverted_from" class="adm-audit-ctx" style="font-style:italic;">
              Отменена приёмка от {{ log.details.reverted_from }}
            </div>

            <div v-if="log.details?.full_schedule" class="adm-audit-sched-row">
              <span v-for="day in ['ПН','ВТ','СР','ЧТ','ПТ','СБ']" :key="day" class="adm-audit-sched-cell" :class="{ has: log.details.full_schedule[day] }">
                <span class="adm-audit-sched-day">{{ day }}</span>
                <span class="adm-audit-sched-time">{{ log.details.full_schedule[day] || '—' }}</span>
              </span>
            </div>

            <div v-if="log.details?.changes?.length" class="adm-audit-changes">
              <span v-for="(c, ci) in log.details.changes.slice(0, expandedAudit.has(log.id) ? 999 : 5)" :key="ci" class="adm-audit-ch" :class="{ 'adm-audit-ch-add': c.type==='added', 'adm-audit-ch-del': c.type==='removed', 'adm-audit-ch-upd': c.type==='changed' }">
                <template v-if="c.type === 'added'">+ {{ c.item }} {{ c.boxes }}кор</template>
                <template v-else-if="c.type === 'removed'">− {{ c.item }} {{ c.boxes }}кор</template>
                <template v-else>{{ c.item }}: {{ c.diffs?.join(', ') }}</template>
              </span>
              <button v-if="log.details.changes.length > 5 && !expandedAudit.has(log.id)" class="adm-audit-more" @click="expandedAudit.add(log.id)">
                ещё {{ log.details.changes.length - 5 }}...
              </button>
            </div>

            <div v-if="log.details?.items_count && log.action !== 'received' && !log.details?.changes?.length" class="adm-audit-meta">{{ log.details.items_count }} позиций</div>
            <div v-if="log.details?.name && log.entity_type === 'product'" class="adm-audit-ctx">{{ log.details.name }} <span v-if="log.details?.sku" style="opacity:.6;">({{ log.details.sku }})</span></div>
          </div>

          <div v-if="auditHasMore" style="text-align:center;padding:16px;">
            <button class="btn" @click="loadAudit(false)" :disabled="auditLoading">
              <BurgerSpinner v-if="auditLoading" size="xs" />
              <span>{{ auditLoading ? 'Загрузка...' : 'Показать ещё' }}</span>
            </button>
          </div>
        </div>
      </template>

      <!-- Ошибки -->
      <template v-if="auditMode === 'errors'">
        <div class="adm-audit-filters">
          <div class="adm-audit-filter-row">
            <div class="adm-audit-chips">
              <button v-for="l in errorLevelOptions" :key="l.value" class="adm-audit-chip"
                :class="{ active: errorFilter.level === l.value }" @click="errorFilter.level = l.value; loadErrors(true)">
                {{ l.label }}
              </button>
            </div>
            <div class="adm-audit-right-filters">
              <select v-model="errorFilter.source" @change="loadErrors(true)" class="adm-audit-select">
                <option value="">Все источники</option>
                <option value="frontend">Фронтенд</option>
                <option value="backend">Бэкенд</option>
              </select>
              <button class="btn" style="font-size:12px;padding:5px 12px;" @click="clearErrors" :disabled="errorsClearing">
                <BkIcon name="delete" size="sm"/> Очистить
              </button>
            </div>
          </div>
        </div>

        <div v-if="errorsLoading && !errorEntries.length" style="text-align:center;padding:48px;"><BurgerSpinner text="Загрузка..." /></div>
        <div v-else-if="!errorEntries.length" class="adm-empty">Ошибок не обнаружено</div>

        <div v-else class="adm-audit-list">
          <div v-for="log in errorEntries" :key="log.id" class="adm-audit-entry adm-error-entry" @click="toggleErrorStack(log.id)">
            <div class="adm-audit-head">
              <span class="adm-audit-badge" :class="errorBadgeClass(log.level)">{{ log.level }}</span>
              <span class="adm-audit-entity-badge">{{ log.source }}</span>
              <span v-if="log.user_name" class="adm-audit-author">{{ log.user_name }}</span>
              <span class="adm-audit-date-text">{{ formatAuditDate(log.created_at) }}</span>
            </div>
            <div class="adm-error-message">{{ log.message }}</div>
            <div v-if="log.url" class="adm-error-url">{{ log.url }}</div>
            <div v-if="expandedErrors.has(log.id) && log.stack" class="adm-error-stack">{{ log.stack }}</div>
          </div>
          <div v-if="errorsHasMore" style="text-align:center;padding:16px;">
            <button class="btn" @click="loadErrors(false)" :disabled="errorsLoading">
              <BurgerSpinner v-if="errorsLoading" size="xs" />
              <span>{{ errorsLoading ? 'Загрузка...' : 'Показать ещё' }}</span>
            </button>
          </div>
        </div>
      </template>
    </div>

    <!-- ═══ Статистика ═══ -->
    <div v-if="activeTab === 'stats'" class="adm-section">
      <div class="adm-toolbar">
        <div class="adm-toolbar-info">Общая статистика системы</div>
        <div class="adm-stats-period">
          <button v-for="p in statsPeriods" :key="p.value" class="adm-audit-chip"
            :class="{ active: statsPeriod === p.value }" @click="statsPeriod = p.value; loadStats()">
            {{ p.label }}
          </button>
        </div>
      </div>

      <div v-if="statsLoading" style="text-align:center;padding:48px;"><BurgerSpinner text="Загрузка..." /></div>
      <template v-else>
        <div class="adm-stats-cards">
          <div class="adm-stat-card">
            <div class="adm-stat-value">{{ statsData.orders_today ?? 0 }}</div>
            <div class="adm-stat-label">Заказов сегодня</div>
          </div>
          <div class="adm-stat-card">
            <div class="adm-stat-value">{{ statsData.orders_total ?? 0 }}</div>
            <div class="adm-stat-label">Всего заказов</div>
          </div>
          <div class="adm-stat-card">
            <div class="adm-stat-value">{{ statsData.plans_total ?? 0 }}</div>
            <div class="adm-stat-label">Планов</div>
          </div>
          <div class="adm-stat-card">
            <div class="adm-stat-value">{{ statsData.active_sessions ?? 0 }}</div>
            <div class="adm-stat-label">Активных сессий</div>
          </div>
          <div class="adm-stat-card">
            <div class="adm-stat-value">{{ statsData.products_count ?? 0 }}</div>
            <div class="adm-stat-label">Товаров</div>
          </div>
          <div class="adm-stat-card">
            <div class="adm-stat-value">{{ statsData.suppliers_count ?? 0 }}</div>
            <div class="adm-stat-label">Поставщиков</div>
          </div>
          <div class="adm-stat-card">
            <div class="adm-stat-value">{{ statsData.users_count ?? 0 }}</div>
            <div class="adm-stat-label">Пользователей</div>
          </div>
          <div class="adm-stat-card">
            <div class="adm-stat-value">{{ statsData.ro_orders_total ?? 0 }}</div>
            <div class="adm-stat-label">Заказов ресторанов</div>
          </div>
          <div class="adm-stat-card">
            <div class="adm-stat-value">{{ statsData.so_orders_total ?? 0 }}</div>
            <div class="adm-stat-label">Заявок поставщикам</div>
          </div>
          <div class="adm-stat-card">
            <div class="adm-stat-value">{{ statsData.price_agreements_total ?? 0 }}</div>
            <div class="adm-stat-label">Протоколов цен</div>
          </div>
        </div>

        <div class="adm-stats-blocks">
          <div class="adm-maint-msg-card">
            <h4 class="adm-maint-msg-title">Заказы по юрлицам</h4>
            <div v-if="!statsData.orders_by_entity?.length" class="adm-stats-empty">Нет данных</div>
            <div v-else class="adm-stats-bars">
              <div v-for="e in statsData.orders_by_entity" :key="e.legal_entity" class="adm-stats-bar-row">
                <div class="adm-stats-bar-label">{{ e.legal_entity || '—' }}</div>
                <div class="adm-stats-bar-track">
                  <div class="adm-stats-bar-fill" :style="{ width: statsBarWidth(e.cnt) }"></div>
                </div>
                <div class="adm-stats-bar-val">{{ e.cnt }}</div>
              </div>
            </div>
          </div>

          <div class="adm-maint-msg-card">
            <h4 class="adm-maint-msg-title">Самые активные</h4>
            <div v-if="!statsData.top_users?.length" class="adm-stats-empty">Нет данных</div>
            <div v-else class="adm-stats-top-list">
              <div v-for="(u, i) in statsData.top_users" :key="u.user_name" class="adm-stats-top-row">
                <span class="adm-stats-top-num">{{ i + 1 }}</span>
                <span class="adm-stats-top-name">{{ u.user_name || '—' }}</span>
                <span class="adm-stats-top-cnt">{{ u.cnt }} заказов</span>
              </div>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- ═══ Резервное копирование ═══ -->
    <div v-if="activeTab === 'backup'" class="adm-section">
      <div class="adm-maint-card">
        <div class="adm-maint-icon">
          <svg viewBox="0 0 48 48" width="48" height="48" fill="none">
            <circle cx="24" cy="24" r="22" fill="rgba(33,150,243,0.08)" stroke="#2196F3" stroke-width="2"/>
            <rect x="14" y="18" width="20" height="14" rx="3" stroke="#2196F3" stroke-width="2.5" fill="none"/>
            <path d="M18 18v-4a6 6 0 0112 0v4" stroke="#2196F3" stroke-width="2.5" stroke-linecap="round"/>
            <circle cx="24" cy="25" r="2" fill="#2196F3"/>
          </svg>
        </div>
        <div class="adm-maint-body">
          <h3 class="adm-maint-title">Резервное копирование</h3>
          <p class="adm-maint-desc">Выберите таблицы и юрлицо для выгрузки данных в Excel-файл. Каждая таблица станет отдельным листом.</p>
        </div>
      </div>

      <div class="adm-maint-msg-card" style="margin-top:16px;">
        <h4 class="adm-maint-msg-title">Юридическое лицо</h4>
        <select v-model="backupEntity" class="adm-audit-select" style="width:100%;margin-top:6px;padding:8px 12px;">
          <option value="">Все юрлица</option>
          <option v-for="le in allEntities" :key="le" :value="le">{{ le }}</option>
        </select>
      </div>

      <div class="adm-maint-msg-card" style="margin-top:12px;">
        <h4 class="adm-maint-msg-title">Таблицы для выгрузки</h4>
        <div class="adm-backup-tables">
          <label v-for="t in backupTables" :key="t.name" class="adm-le-option">
            <input type="checkbox" :value="t.name" v-model="backupSelected" />
            <span class="adm-le-box"><BkIcon name="success" size="sm"/></span>
            <span>{{ t.label }}</span>
          </label>
        </div>
        <div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap;">
          <button class="btn" @click="backupSelected = backupTables.map(t => t.name)">Выбрать все</button>
          <button class="btn" @click="backupSelected = []">Снять все</button>
          <button class="btn primary" @click="exportBackup" :disabled="!backupSelected.length || backupExporting">
            <BkIcon name="excel" size="sm"/> {{ backupExporting ? 'Выгрузка...' : 'Выгрузить в Excel' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ═══ Сессии ═══ -->
    <div v-if="activeTab === 'sessions'" class="adm-section">
      <!-- Онлайн -->
      <div class="adm-maint-msg-card" style="margin-bottom:16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
          <h4 class="adm-maint-msg-title" style="margin:0;">Сейчас онлайн — {{ onlineUsers.length }} {{ onlineWord }}</h4>
          <button class="btn" style="font-size:12px;padding:4px 10px;" @click="loadOnlineUsers" :disabled="onlineLoading">
            <BkIcon name="redo" size="sm"/>
          </button>
        </div>
        <div v-if="onlineLoading && !onlineUsers.length" style="text-align:center;padding:16px;"><BurgerSpinner text="Загрузка..." /></div>
        <div v-else-if="!onlineUsers.length" style="text-align:center;padding:16px;color:var(--text-muted);font-size:13px;">Нет пользователей онлайн</div>
        <div v-else class="adm-user-list">
          <div v-for="u in onlineUsers" :key="u.user_name" class="adm-user-row" style="cursor:default;">
            <div class="adm-user-avatar adm-avatar-online">
              {{ initials(u.user_name) }}
              <span class="adm-online-dot"></span>
            </div>
            <div class="adm-user-info">
              <div class="adm-user-name">
                {{ u.user_name }}
                <span v-if="u.user_name === userStore.currentUser?.name" class="adm-badge adm-badge-you">вы</span>
              </div>
              <div class="adm-user-meta">{{ u.page || '—' }}</div>
            </div>
            <div class="adm-online-time">{{ formatOnlineTime(u.last_seen) }}</div>
          </div>
        </div>
      </div>

      <!-- Рестораны онлайн -->
      <div class="adm-maint-msg-card" style="margin-bottom:16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
          <h4 class="adm-maint-msg-title" style="margin:0;">Рестораны онлайн — {{ onlineRestaurants.length }} {{ onlineRestaurantsWord }}</h4>
          <button class="btn" style="font-size:12px;padding:4px 10px;" @click="loadOnlineRestaurants" :disabled="onlineRestaurantsLoading">
            <BkIcon name="redo" size="sm"/>
          </button>
        </div>
        <div v-if="onlineRestaurantsLoading && !onlineRestaurants.length" style="text-align:center;padding:16px;"><BurgerSpinner text="Загрузка..." /></div>
        <div v-else-if="!onlineRestaurants.length" style="text-align:center;padding:16px;color:var(--text-muted);font-size:13px;">Нет ресторанов онлайн</div>
        <div v-else class="adm-user-list">
          <div v-for="r in onlineRestaurants" :key="(r.legal_entity_group || 'BK_VM') + '-' + r.restaurant_number" class="adm-user-row" style="cursor:default;">
            <div class="adm-user-avatar adm-avatar-online">
              {{ formatRestaurantNumberShort(r.restaurant_number, r.legal_entity_group) }}
              <span class="adm-online-dot"></span>
            </div>
            <div class="adm-user-info">
              <div class="adm-user-name">
                {{ r.city || '—' }}<span v-if="r.address"> · {{ r.address }}</span>
              </div>
              <div class="adm-user-meta">
                {{ r.last_page || '—' }}
                <span style="opacity:.6;"> · {{ shortLegalEntityAdm(r.legal_entity) }}</span>
              </div>
            </div>
            <div class="adm-online-time">{{ formatOnlineTime(r.last_activity) }}</div>
          </div>
        </div>
      </div>

      <!-- Активные сессии -->
      <div class="adm-toolbar">
        <div class="adm-toolbar-info">{{ sessionsList.length }} активных сессий</div>
        <button class="btn" @click="loadSessions" :disabled="sessionsLoading">
          <BkIcon name="redo" size="sm"/> Обновить
        </button>
      </div>

      <div v-if="sessionsLoading && !sessionsList.length" style="text-align:center;padding:48px;"><BurgerSpinner text="Загрузка..." /></div>
      <div v-else-if="!sessionsList.length" class="adm-empty">Нет активных сессий</div>

      <div v-else class="adm-user-list">
        <div v-for="s in sessionsList" :key="s.id" class="adm-user-row" style="cursor:default;">
          <div class="adm-user-avatar" :class="{ 'adm-avatar-online': isCurrentSession(s) }">
            {{ initials(s.user_name) }}
            <span v-if="isCurrentSession(s)" class="adm-online-dot"></span>
          </div>
          <div class="adm-user-info">
            <div class="adm-user-name">
              {{ s.user_name }}
              <span v-if="isCurrentSession(s)" class="adm-badge adm-badge-you">текущая</span>
            </div>
            <div class="adm-user-meta">{{ parseUserAgent(s.user_agent) }}</div>
            <div class="adm-user-email">IP: {{ s.ip_address || '—' }}</div>
          </div>
          <div style="text-align:right;flex-shrink:0;">
            <div style="font-size:11px;color:var(--text-muted);">Вход: {{ formatSessionDate(s.created_at) }}</div>
            <div style="font-size:11px;color:var(--text-muted);">Истекает: {{ formatSessionDate(s.expires_at) }}</div>
          </div>
          <div class="adm-user-actions" style="opacity:1;">
            <button class="adm-act-btn adm-act-del" @click="terminateSession(s)" :disabled="isCurrentSession(s)" title="Завершить сессию">
              <BkIcon name="close" size="sm"/>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══ Обращения — мессенджер ═══ -->
    <div v-if="activeTab === 'feedback'" class="adm-section fb-messenger">
      <!-- Левая панель: список -->
      <div class="fb-sidebar">
        <div class="fb-sidebar-top">
          <select v-model="bugFilterStatus" class="bug-filter-select" style="flex:1;">
            <option value="">Все ({{ bugReports.length }})</option>
            <option value="new">Новые</option>
            <option value="in_progress">В работе</option>
            <option value="resolved">Решённые</option>
            <option value="closed">Закрытые</option>
          </select>
          <button class="btn" @click="loadBugReports" style="font-size:11px;padding:4px 8px;"><BkIcon name="redo" size="sm"/></button>
        </div>
        <div class="fb-list">
          <div v-if="bugLoading" style="text-align:center;padding:24px;"><BurgerSpinner text="Загрузка..." /></div>
          <div v-else-if="!filteredBugReports.length" style="text-align:center;padding:24px;color:var(--text-muted);font-size:12px;">Нет обращений</div>
          <div
            v-for="r in filteredBugReports" :key="r.id"
            class="fb-item" :class="{ active: bugDetail?.id === r.id, 'is-new': r.status === 'new' }"
            @click="openBugDetail(r)"
          >
            <div class="fb-item-top">
              <span class="fb-item-status" :class="'st-' + r.status"></span>
              <span class="fb-item-author">{{ r.created_by }}</span>
              <span class="fb-item-date">{{ formatBugDate(r.created_at) }}</span>
            </div>
            <div class="fb-item-title">{{ r.title }}</div>
            <div class="fb-item-bottom">
              <span class="fb-item-entity">{{ r.legal_entity || '' }}</span>
              <span v-if="r.reply_count" class="fb-item-replies">💬 {{ r.reply_count }}</span>
              <span v-if="r.screenshots?.length" class="fb-item-attach">📎 {{ r.screenshots.length }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Правая панель: чат -->
      <div class="fb-chat">
        <template v-if="bugDetail">
          <!-- Шапка чата -->
          <div class="fb-chat-header">
            <div style="display:flex;align-items:center;gap:8px;flex:1;min-width:0;">
              <select v-model="bugDetail.status" @change="updateBugStatus(bugDetail)" class="bug-filter-select" style="font-weight:600;font-size:11px;padding:3px 6px;">
                <option value="new">🟠 Новое</option>
                <option value="in_progress">🔵 В работе</option>
                <option value="resolved">🟢 Решено</option>
                <option value="closed">⚫ Закрыто</option>
              </select>
              <span class="fb-chat-title">{{ bugDetail.title }}</span>
            </div>
            <button class="fb-del-btn" @click="deleteBugReport(bugDetail)" title="Удалить"><BkIcon name="delete" size="sm"/></button>
          </div>

          <!-- Описание (сворачиваемое) -->
          <details class="fb-chat-info">
            <summary>
              {{ bugDetail.created_by }} · {{ formatBugDate(bugDetail.created_at) }}
              <span v-if="bugDetail.screenshots?.length"> · 📎 {{ bugDetail.screenshots.length }}</span>
            </summary>
            <div class="fb-chat-info-body">
              <p v-if="bugDetail.description" style="font-size:13px;color:var(--text-secondary);white-space:pre-wrap;margin:0 0 8px;line-height:1.5;">{{ bugDetail.description }}</p>
              <div v-if="bugDetail.screenshots?.length" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;">
                <a v-for="(s, i) in bugDetail.screenshots" :key="i" :href="bugImageUrl(s)" target="_blank">
                  <img :src="bugImageUrl(s)" style="width:72px;height:72px;object-fit:cover;border-radius:8px;border:1px solid var(--border);" />
                </a>
              </div>
              <div v-if="bugDetail.page_url" style="font-size:11px;color:var(--text-muted);word-break:break-all;"><b>Страница:</b> {{ bugDetail.page_url }}</div>
              <details v-if="bugDetail.action_log" style="margin-top:4px;">
                <summary style="font-size:11px;color:var(--text-muted);cursor:pointer;">Лог действий</summary>
                <pre style="font-size:10px;background:var(--bg);padding:6px 8px;border-radius:6px;margin-top:4px;white-space:pre-wrap;max-height:150px;overflow-y:auto;">{{ bugDetail.action_log }}</pre>
              </details>
            </div>
          </details>

          <!-- Сообщения -->
          <div class="fb-chat-messages" ref="bugChatScroll">
            <div v-if="!bugReplies.length" style="text-align:center;padding:40px;color:var(--text-muted);font-size:12px;">Нет сообщений — напишите ответ</div>
            <div v-for="r in bugReplies" :key="r.id" class="fb-msg" :class="{ admin: r.is_admin }">
              <div class="fb-msg-meta">
                <span :style="r.is_admin ? 'color:#2E7D32' : ''">{{ r.created_by }}{{ r.is_admin ? ' (вы)' : '' }}</span>
                <span>{{ formatBugDate(r.created_at) }}</span>
              </div>
              <div class="fb-msg-text" v-html="renderMsgContent(r.message, bugImageUrls)" @click="onBugMsgClick" :data-img-rev="Object.keys(bugImageUrls).length"></div>
            </div>
          </div>

          <!-- Превью вложений -->
          <div v-if="bugReplyImages.length" class="fb-attach-preview">
            <div v-for="(img, i) in bugReplyImages" :key="i" class="fb-attach-thumb">
              <img :src="img.preview" />
              <button @click="bugReplyImages.splice(i, 1)" class="fb-attach-remove">&times;</button>
              <div v-if="img.uploading" class="fb-attach-loading"></div>
            </div>
          </div>

          <!-- Ввод -->
          <div class="fb-chat-input">
            <label class="fb-attach-btn" title="Прикрепить фото">
              <input type="file" accept="image/*" multiple @change="onBugReplyFiles" style="display:none" />
              📎
            </label>
            <textarea v-model="bugReplyText" class="bug-reply-input" placeholder="Enter — отправить" rows="1" @keydown.enter.exact.prevent="sendBugReply" @input="autoResizeReply" @paste="onBugReplyPaste"></textarea>
            <button class="btn primary" :disabled="(!bugReplyText.trim() && !bugReplyImages.length) || bugReplySending" @click="sendBugReply" style="font-size:13px;padding:8px 16px;align-self:flex-end;">
              {{ bugReplySending ? '...' : '→' }}
            </button>
          </div>
        </template>

        <!-- Пустое состояние -->
        <div v-else class="fb-chat-empty">
          <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="var(--border)" stroke-width="1"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
          <p>Выберите обращение из списка</p>
        </div>
      </div>
    </div>

    <!-- ═══ Крон напоминаний — журнал запусков ═══ -->
    <div v-if="activeTab === 'cron-reminders'" class="adm-section">
      <div class="adm-toolbar">
        <div class="adm-toolbar-info">
          Последние {{ cronReminders.length }} запусков, обновляется каждые 5 минут
          <span v-if="cronErrCount" class="adm-cron-err">· ошибок за сутки: {{ cronErrCount }}</span>
        </div>
        <button class="btn" @click="loadCronReminders" style="font-size:12px;">
          <BkIcon name="redo" size="sm"/> Обновить
        </button>
      </div>

      <div v-if="cronLoading" style="text-align:center;padding:24px;"><BurgerSpinner text="Загрузка..." /></div>
      <div v-else-if="!cronReminders.length" class="adm-empty">Журнал пуст. Крон ещё не запускался?</div>
      <table v-else class="adm-cron-table">
        <thead>
          <tr>
            <th>Запуск</th>
            <th>Длит.</th>
            <th colspan="3">Поставщики</th>
            <th colspan="3">Осн. поставка</th>
            <th>Статус</th>
          </tr>
          <tr class="adm-cron-subhead">
            <th></th><th></th>
            <th title="portal">📰</th><th title="telegram">💬</th><th title="пропущено">⊘</th>
            <th title="portal">📰</th><th title="telegram">💬</th><th title="пропущено">⊘</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in cronReminders" :key="row.id" :class="{ 'cron-err': row.status === 'error' }">
            <td class="adm-cron-ts">{{ fmtCronTime(row.started_at) }}</td>
            <td>{{ cronDuration(row) }}</td>
            <td class="cron-num">{{ row.sup_portal }}</td>
            <td class="cron-num">{{ row.sup_tg }}</td>
            <td class="cron-num cron-skip">{{ row.sup_skip }}</td>
            <td class="cron-num">{{ row.main_portal }}</td>
            <td class="cron-num">{{ row.main_tg }}</td>
            <td class="cron-num cron-skip">{{ row.main_skip }}</td>
            <td>
              <span v-if="row.status === 'ok'" class="cron-status-ok">✓</span>
              <span v-else class="cron-status-err" :title="row.error_text">⚠ {{ truncateError(row.error_text) }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ═══ Бот-монитор ═══ -->
    <div v-if="activeTab === 'bot-monitor'" class="adm-section">
      <AdminBotMonitorTab />
    </div>

    <!-- ═══ Модалка обновления (changelog) ═══ -->
    <Teleport to="body">
      <div v-if="changelogModal.show" class="modal" @click.self="tryCloseChangelog">
        <div class="modal-box" style="width:460px;">
          <div class="modal-header">
            <h2>{{ changelogModal.entry ? 'Редактировать' : 'Новое обновление' }}</h2>
            <button class="modal-close" @click="tryCloseChangelog"><BkIcon name="close" size="sm"/></button>
          </div>
          <div class="adm-form">
            <div class="modal-field">
              <span class="modal-field-label">Версия</span>
              <input v-model="changelogForm.version" placeholder="1.0.0" />
            </div>
            <div class="modal-field">
              <span class="modal-field-label">Заголовок</span>
              <input v-model="changelogForm.title" placeholder="Что нового" />
            </div>
            <div class="modal-field">
              <span class="modal-field-label">Описание</span>
              <textarea v-model="changelogForm.description" class="adm-maint-textarea" rows="5" placeholder="Подробное описание изменений..."></textarea>
            </div>
          </div>
          <div style="display:flex;gap:8px;margin-top:20px;">
            <button class="btn primary" @click="saveChangelog" :disabled="changelogSaving || !changelogForm.version.trim() || !changelogForm.title.trim()">
              <BurgerSpinner v-if="changelogSaving" size="xs" />
              <span>{{ changelogSaving ? 'Сохранение...' : (changelogModal.entry ? 'Сохранить' : 'Создать') }}</span>
            </button>
            <button class="btn secondary" @click="tryCloseChangelog">Отмена</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ═══ Модалка пользователя ═══ -->
    <Teleport to="body">
      <div v-if="userModal.show" class="modal" @click.self="tryCloseUserModal">
        <div class="modal-box" style="width:460px;">
          <div class="modal-header">
            <h2>{{ userModal.user ? 'Редактирование' : 'Новый пользователь' }}</h2>
            <button class="modal-close" @click="tryCloseUserModal"><BkIcon name="close" size="sm"/></button>
          </div>

          <div class="adm-form">
            <div class="modal-field">
              <span class="modal-field-label">Имя</span>
              <input v-model="form.name" placeholder="ФИО пользователя" />
            </div>

            <div class="modal-field">
              <span class="modal-field-label">Email</span>
              <input v-model="form.email" type="email" placeholder="Email для входа в систему" />
            </div>

            <div class="modal-row-2">
              <div class="modal-field" style="flex:1;">
                <span class="modal-field-label">Пароль</span>
                <input type="password" v-model="form.password" :placeholder="userModal.user ? 'Не менять — оставить пустым' : 'Пароль'" />
              </div>
              <div class="modal-field" style="width:155px;flex-shrink:0;">
                <span class="modal-field-label">Роль</span>
                <select v-model="form.role">
                  <option value="user">Пользователь</option>
                  <option value="manager">Руководитель</option>
                  <option value="viewer">Читатель</option>
                  <option value="admin">Администратор</option>
                </select>
              </div>
            </div>

            <div class="modal-field">
              <span class="modal-field-label">Должность</span>
              <input v-model="form.display_role" placeholder="Менеджер, Руководитель и т.д." />
            </div>

            <div class="modal-field">
              <span class="modal-field-label">Доступные юр. лица</span>
              <div class="adm-le-grid">
                <label v-for="le in allEntities" :key="le" class="adm-le-option">
                  <input type="checkbox" :value="le" v-model="form.legal_entities" />
                  <span class="adm-le-box">
                    <BkIcon name="success" size="sm"/>
                  </span>
                  <span>{{ le }}</span>
                </label>
              </div>
              <div class="adm-le-hint">Если ничего не выбрано — доступны все</div>
            </div>

            <!-- Доступ к модулям -->
            <div class="modal-field">
              <span class="modal-field-label">Доступ к модулям</span>
              <div v-if="form.role === 'admin'" class="adm-perm-admin-note">
                Администратор имеет полный доступ ко всем модулям
              </div>
              <div v-else class="adm-perm-grid">
                <div class="adm-perm-header">
                  <div class="adm-perm-module-col">Модуль</div>
                  <div class="adm-perm-level-col" v-for="lvl in ['full','edit','view','none']" :key="lvl">{{ ACCESS_LEVEL_LABELS[lvl] }}</div>
                </div>
                <div v-for="mod in MODULES" :key="mod" class="adm-perm-row">
                  <div class="adm-perm-module-col">{{ MODULE_LABELS[mod] || mod }}</div>
                  <div class="adm-perm-level-col" v-for="lvl in ['full','edit','view','none']" :key="lvl">
                    <label class="adm-perm-radio">
                      <input type="radio" :name="'perm-' + mod" :checked="getFormModuleAccess(mod) === lvl" @change="setFormModuleAccess(mod, lvl)" />
                      <span class="adm-perm-dot" :class="'adm-perm-' + lvl"></span>
                    </label>
                  </div>
                </div>
                <button v-if="Object.keys(form.permissions || {}).length" class="btn small adm-perm-reset" @click="resetPermissionsToTemplate">Сбросить к шаблону роли</button>
              </div>
            </div>
          </div>

          <div style="display:flex;gap:8px;margin-top:20px;">
            <button class="btn primary" @click="saveUser" :disabled="saving">
              <BurgerSpinner v-if="saving" size="xs" />
              <span>{{ saving ? 'Сохранение...' : (userModal.user ? 'Сохранить' : 'Создать') }}</span>
            </button>
            <button class="btn secondary" @click="tryCloseUserModal">Отмена</button>
          </div>
        </div>
      </div>
    </Teleport>


    <ConfirmModal v-if="confirmModal.show" :title="confirmModal.title" :message="confirmModal.message"
      @confirm="onConfirmOk"
      @cancel="onConfirmCancel" />
  </div>
</template>

<script setup>
import { ref, reactive, computed, defineAsyncComponent, nextTick, onMounted, onUnmounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useTabRoute } from '@/composables/useTabRoute.js';
import { db } from '@/lib/apiClient.js';
import { formatMoscowDateTime, formatMoscowRelative, toLocalDateStr } from '@/lib/utils.js';
import { useUserStore, ROLE_TEMPLATES, MODULES, MODULE_LABELS, loadRbacConfig } from '@/stores/userStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { LEGAL_ENTITIES, ENTITY_SHORT_NAMES, formatRestaurantNumber } from '@/lib/legalEntities.js';
import BkIcon from '@/components/ui/BkIcon.vue';

const router = useRouter();
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import AdminRestaurantAccountsTab from '@/components/admin/AdminRestaurantAccountsTab.vue';
import AdminEmailImportsTab from '@/components/admin/AdminEmailImportsTab.vue';
import AdminBotMonitorTab from '@/components/admin/AdminBotMonitorTab.vue';
import { useConfirm } from '@/composables/useConfirm.js';

const ConfirmModal = defineAsyncComponent(() => import('@/components/modals/ConfirmModal.vue'));

const userStore = useUserStore();
const toast = useToastStore();

const activeTab = useTabRoute('users', ['users', 'restaurant-accounts', 'email-imports', 'sessions', 'audit', 'feedback', 'broadcast', 'stats', 'backup', 'maintenance', 'cron-reminders', 'bot-monitor']);
const loading = ref(false);
const saving = ref(false);
const users = ref([]);

const allEntities = LEGAL_ENTITIES;

const userModal = ref({ show: false, user: null });
const form = ref({ name: '', email: '', password: '', role: 'user', display_role: '', legal_entities: [], permissions: {} });
let _userFormSnapshot = '';

function tryCloseUserModal() {
  if (JSON.stringify(form.value) !== _userFormSnapshot) {
    confirmAction('Закрыть без сохранения?', 'Введённые данные пользователя будут потеряны.').then(ok => {
      if (ok) userModal.value.show = false;
    });
    return;
  }
  userModal.value.show = false;
}

const ACCESS_LEVEL_LABELS = { full: 'Полный', edit: 'Редакт.', view: 'Просмотр', none: 'Нет' };

function getFormModuleAccess(module) {
  if (form.value.permissions && form.value.permissions[module] !== undefined) {
    return form.value.permissions[module];
  }
  const tpl = ROLE_TEMPLATES[form.value.role] || ROLE_TEMPLATES.user;
  return tpl[module] || 'none';
}

function setFormModuleAccess(module, level) {
  const tpl = ROLE_TEMPLATES[form.value.role] || ROLE_TEMPLATES.user;
  if (!form.value.permissions) form.value.permissions = {};
  if (tpl[module] === level) {
    delete form.value.permissions[module];
  } else {
    form.value.permissions[module] = level;
  }
}

// При смене роли НЕ сбрасываем индивидуальные права —
// они пересчитаются как diff от нового шаблона при сохранении

function resetPermissionsToTemplate() {
  form.value.permissions = {};
}

function getPermissionsDiff() {
  if (!form.value.permissions || Object.keys(form.value.permissions).length === 0) return null;
  return { ...form.value.permissions };
}

// ═══ Модалка прав доступа ═══
const showPermModal = ref(false);
const permUser = ref(null);
const permModules = ref([]);
const savingPerms = ref(false);

function permLevelLabel(level) {
  return { none: '\u2014', view: 'Просмотр', edit: 'Редактир.', full: 'Полный' }[level] || '\u2014';
}

function openPermissions(user) {
  permUser.value = user;
  const role = user.role || 'user';
  const base = ROLE_TEMPLATES[role] || ROLE_TEMPLATES.user;
  let overrides = {};
  try { overrides = typeof user.permissions === 'string' ? JSON.parse(user.permissions || '{}') : (user.permissions || {}); } catch { overrides = {}; }

  permModules.value = Object.keys(MODULE_LABELS).map(key => ({
    key,
    label: MODULE_LABELS[key],
    base: base[key] || 'none',
    current: overrides[key] || base[key] || 'none',
    override: overrides[key] || '',
  }));
  showPermModal.value = true;
}

async function savePermissions() {
  savingPerms.value = true;
  try {
    const overrides = {};
    const role = permUser.value.role || 'user';
    const base = ROLE_TEMPLATES[role] || ROLE_TEMPLATES.user;
    for (const m of permModules.value) {
      if (m.override && m.override !== base[m.key]) {
        overrides[m.key] = m.override;
      }
    }
    const permsToSend = Object.keys(overrides).length ? overrides : null;

    const { data, error } = await db.rpc('update_user', {
      caller_name: userStore.currentUser?.name || '',
      user_id: permUser.value.id,
      permissions: permsToSend,
    });
    if (error || (data && !data.success)) { toast.error('Ошибка', error || data?.error || ''); return; }

    // Обновить локально
    const idx = users.value.findIndex(u => u.id === permUser.value.id);
    if (idx >= 0) {
      users.value[idx].permissions = permsToSend ? JSON.stringify(permsToSend) : null;
    }

    toast.success('Сохранено', 'Права обновлены');
    showPermModal.value = false;
  } catch (e) {
    toast.error('Ошибка', e.message);
  } finally {
    savingPerms.value = false;
  }
}

const { confirmModal, confirm: confirmAction, onConfirm: onConfirmOk, onCancel: onConfirmCancel } = useConfirm();

// ═══ Аудит-лог ═══
const auditMode = ref('audit');
const AUDIT_PAGE_SIZE = 50;
const auditEntries = ref([]);
const auditLoading = ref(false);
const auditHasMore = ref(false);
const auditTotal = ref(0);
const expandedAudit = reactive(new Set());
const auditUsers = ref([]);
const auditFilter = reactive({ category: '', user: '', dateFrom: '', dateTo: '' });

function loadErrorsIfNeeded() {
  if (!errorEntries.value.length) loadErrors(true);
}
function loadChangelogIfNeeded() {
  if (!changelogEntries.value.length) loadChangelog();
}

const auditCategories = [
  { value: '', label: 'Все' },
  { value: 'order', label: 'Заказы' },
  { value: 'plan', label: 'Планы' },
  { value: 'product', label: 'Товары' },
  { value: 'delivery_schedule', label: 'Расписание' },
  { value: 'user', label: 'Пользователи' },
  { value: 'price_agreement', label: 'Цены и ПСЦ' },
  { value: 'tender', label: 'Тендеры' },
  { value: 'marketing', label: 'Маркетинг' },
  { value: 'correction', label: 'Корректировки' },
  { value: 'import', label: 'Импорт данных' },
  { value: 'veg', label: 'Планета Ресторанов' },
  { value: 'supplier_order', label: 'Заявки поставщикам' },
  { value: 'stock_collection', label: 'Сбор остатков' },
  { value: 'distribution', label: 'Распределение' },
  { value: 'system', label: 'Система' },
];

const AUDIT_ACTION_LABELS = {
  // Заказы
  order_created: 'Создан', order_updated: 'Изменён', order_deleted: 'Удалён', orders_deleted: 'Удалён',
  delivery_date_changed: 'Дата доставки', received: 'Принят', reception_reverted: 'Отмена приёмки',
  // Планы
  plan_created: 'Создан', plan_updated: 'Изменён', plan_deleted: 'Удалён', plans_deleted: 'Удалён',
  // Товары
  product_created: 'Создана', product_updated: 'Изменена', products_deleted: 'Удалена',
  // Расписание
  schedule_updated: 'График', restaurant_updated: 'Ресторан',
  // Пользователи
  user_created: 'Создан', user_updated: 'Изменён', user_deleted: 'Удалён', password_changed: 'Пароль изменён',
  // Цены и ПСЦ
  price_agreement_created: 'Создан', price_agreement_updated: 'Изменён',
  agreement_approved: 'Согласован', agreement_archived: 'Архивирован', agreement_restored: 'Восстановлен',
  agreement_deleted: 'Удалён', price_imported: 'Импорт цен', price_deleted: 'Цена удалена',
  exchange_rate_updated: 'Курс обновлён',
  // Тендеры
  tender_created: 'Создан', tender_updated: 'Изменён', tender_deleted: 'Удалён',
  // Маркетинг
  marketing_created: 'Создана', marketing_updated: 'Изменена', marketing_deleted: 'Удалена',
  // Корректировки
  correction_created: 'Создана', correction_approved: 'Подтверждена', correction_rejected: 'Отклонена',
  correction_reviewed: 'Рассмотрена',
  // Импорт
  data_imported: 'Импорт', recipe_imported: 'Импорт рецептур',
  // Овощи
  veg_session_created: 'Сессия создана', veg_order_updated: 'Заявка изменена', veg_order_submitted: 'Заявка подана',
  // Заявки поставщикам (so_*)
  so_order_submitted: 'Заявка подана', so_order_updated: 'Заявка обновлена',
  so_order_skipped: 'Поставка не нужна', so_order_edited: 'Изменена отделом закупок',
  so_order_deleted: 'Удалена', so_qty_adjusted: 'Правка количества',
  // Сбор остатков
  stock_collection_created: 'Создан', collection_created: 'Создан', collection_closed: 'Закрыт',
  // Распределение
  distribution_created: 'Создано',
  // Система
  broadcast_sent: 'Рассылка', session_terminated: 'Сессия завершена', maintenance_toggled: 'Тех. работы',
};
const AUDIT_ENTITY_LABELS = {
  order: 'Заказ', plan: 'План', product: 'Товар', delivery_schedule: 'Расписание',
  user: 'Пользователь', price_agreement: 'Протокол цен',
  marketing: 'Маркетинг', tender: 'Тендер',
  correction: 'Корректировка', distribution: 'Распределение', stock_collection: 'Сбор остатков',
  import: 'Импорт', supplier_order: 'Заявка поставщику', system: 'Система',
};

function auditBadgeLabel(action) { return AUDIT_ACTION_LABELS[action] || action; }
function auditEntityLabel(et) { return AUDIT_ENTITY_LABELS[et] || et; }
function auditBadgeClass(action) {
  if (action === 'received') return 'adm-audit-b-received';
  if (action === 'reception_reverted') return 'adm-audit-b-reverted';
  if (action === 'delivery_date_changed') return 'adm-audit-b-delivery';
  if (action === 'schedule_updated' || action === 'restaurant_updated') return 'adm-audit-b-schedule';
  if (action.includes('imported') || action === 'data_imported') return 'adm-audit-b-schedule';
  if (action.includes('approved') || action.includes('restored') || action === 'broadcast_sent') return 'adm-audit-b-received';
  if (action.includes('archived') || action === 'session_terminated' || action === 'password_changed') return 'adm-audit-b-reverted';
  if (action.includes('rejected')) return 'adm-audit-b-deleted';
  if (action.includes('created')) return 'adm-audit-b-created';
  if (action.includes('updated') || action.includes('changed') || action.includes('reviewed')) return 'adm-audit-b-updated';
  if (action.includes('deleted') || action.includes('closed')) return 'adm-audit-b-deleted';
  return '';
}

function formatAuditDate(str) {
  if (!str) return '';
  const d = new Date(str);
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' }) + ' ' +
         d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

async function loadAudit(reset = true) {
  if (reset) {
    auditEntries.value = [];
    auditLoading.value = true;
  } else {
    auditLoading.value = true;
  }
  try {
    const offset = reset ? 0 : auditEntries.value.length;
    let query = db.from('audit_log').select('*').order('created_at', { ascending: false }).limit(AUDIT_PAGE_SIZE).offset(offset);
    if (auditFilter.category) query = query.eq('entity_type', auditFilter.category);
    if (auditFilter.user) query = query.eq('user_name', auditFilter.user);
    if (auditFilter.dateFrom) query = query.gte('created_at', auditFilter.dateFrom);
    if (auditFilter.dateTo) query = query.lte('created_at', auditFilter.dateTo + ' 23:59:59');

    const { data } = await query;
    const parsed = (data || []).map(e => {
      if (e.details && typeof e.details === 'string') {
        try { e.details = JSON.parse(e.details); } catch { e.details = null; }
      }
      return e;
    });

    if (reset) {
      auditEntries.value = parsed;
    } else {
      auditEntries.value.push(...parsed);
    }
    auditHasMore.value = parsed.length >= AUDIT_PAGE_SIZE;
    if (reset) auditTotal.value = auditHasMore.value ? parsed.length + '+' : parsed.length;
  } catch (e) {
    toast.error('Ошибка', 'Не удалось загрузить журнал');
  } finally {
    auditLoading.value = false;
  }
}

async function loadAuditUsers() {
  try {
    const { data } = await db.from('users').select('name').order('name');
    auditUsers.value = (data || []).map(u => u.name).filter(Boolean);
  } catch { /* ok */ }
}

const maintenanceOn = ref(false);
const maintenanceSaving = ref(false);
const maintenanceMsg = ref('');
const maintenanceMsgSaving = ref(false);
const maintenanceTimerSaving = ref(false);
const maintenanceEndTimeCurrent = ref(null);
const maintenanceTimeInput = ref('');

const quickTimerOptions = [
  { min: 15, label: '15 мин' },
  { min: 30, label: '30 мин' },
  { min: 60, label: '1 час' },
  { min: 120, label: '2 часа' },
];

const maintenanceEndTimeDisplay = computed(() => {
  if (!maintenanceEndTimeCurrent.value) return '';
  const d = new Date(maintenanceEndTimeCurrent.value);
  if (isNaN(d.getTime()) || d.getTime() <= Date.now()) return '';
  return d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0');
});

// ═══ Broadcast ═══
const broadcastMode = ref('broadcast');
const bcTitle = ref('');
const bcMessage = ref('');
const bcSending = ref(false);
const bcHistory = ref([]);
const bcHistoryLoading = ref(false);
const bcTargets = ref({
  staffCabinet: true,
  restaurantCabinet: false,
  staffTelegram: false,
  restaurantTelegram: false,
});
const bcHasAnyTarget = computed(() => Object.values(bcTargets.value).some(Boolean));

// ═══ Онлайн-пользователи ═══
const onlineUsers = ref([]);
const onlineLoading = ref(false);
let onlineTimer = null;
// bugPollTimer объявлен здесь, а не рядом с bugPoll/startBugPoll,
// потому что immediate watch на activeTab может сработать с tab='feedback'
// до того, как setup дойдёт до конца файла. let-переменные имеют TDZ —
// startBugPoll() при чтении bugPollTimer падал «Cannot access X before initialization».
let bugPollTimer = null;

const onlineWord = computed(() => {
  const n = onlineUsers.value.length;
  if (n % 10 === 1 && n % 100 !== 11) return 'пользователь';
  if ([2,3,4].includes(n % 10) && ![12,13,14].includes(n % 100)) return 'пользователя';
  return 'пользователей';
});

async function loadOnlineUsers() {
  onlineLoading.value = true;
  try {
    const { data } = await db.rpc('get_online_users');
    onlineUsers.value = data || [];
  } catch (e) { console.warn('[admin] loadOnlineUsers:', e); }
  finally { onlineLoading.value = false; }
}

const onlineRestaurants = ref([]);
const onlineRestaurantsLoading = ref(false);

const onlineRestaurantsWord = computed(() => {
  const n = onlineRestaurants.value.length;
  if (n % 10 === 1 && n % 100 !== 11) return 'ресторан';
  if ([2,3,4].includes(n % 10) && ![12,13,14].includes(n % 100)) return 'ресторана';
  return 'ресторанов';
});

async function loadOnlineRestaurants() {
  onlineRestaurantsLoading.value = true;
  try {
    const { data } = await db.rpc('get_online_restaurants');
    onlineRestaurants.value = data || [];
  } catch (e) { console.warn('[admin] loadOnlineRestaurants:', e); }
  finally { onlineRestaurantsLoading.value = false; }
}

function formatRestaurantNumberShort(num, group) {
  if (group === 'PS') return 'PS' + String(num).padStart(2, '0');
  return '№' + num;
}

const LE_SHORT = { 'ООО "Бургер БК"': 'БК', 'ООО "Воглия Матта"': 'ВМ', 'ООО "Пицца Стар"': 'ПС' };
function shortLegalEntityAdm(le) {
  if (!le) return '';
  if (LE_SHORT[le]) return LE_SHORT[le];
  const m = le.match(/«([^»]+)»|"([^"]+)"/);
  return m ? (m[1] || m[2]).trim() : (le.length > 16 ? le.slice(0, 14) + '…' : le);
}

const formatOnlineTime = formatMoscowRelative;

async function sendBroadcast() {
  if (!bcMessage.value.trim()) return;
  if (!bcHasAnyTarget.value) { toast.error('Получатели не выбраны', ''); return; }
  bcSending.value = true;
  try {
    const { data } = await db.rpc('send_broadcast', {
      user_name: userStore.currentUser.name,
      title: bcTitle.value.trim() || 'Важное сообщение',
      message: bcMessage.value.trim(),
      to_staff_cabinet: bcTargets.value.staffCabinet,
      to_restaurants_cabinet: bcTargets.value.restaurantCabinet,
      to_staff_telegram: bcTargets.value.staffTelegram,
      to_restaurants_telegram: bcTargets.value.restaurantTelegram,
    });
    if (data?.success) {
      const tgParts = [];
      if (data.staff_telegram_sent > 0) tgParts.push(`отдел закупок Telegram: ${data.staff_telegram_sent}`);
      if (data.restaurant_telegram_sent > 0) tgParts.push(`рестораны Telegram: ${data.restaurant_telegram_sent}`);
      toast.success('Отправлено', tgParts.length ? tgParts.join(', ') : 'Рассылка отправлена');
      bcTitle.value = '';
      bcMessage.value = '';
      loadBcHistory();
    } else {
      toast.error('Ошибка', data?.error || 'Не удалось отправить');
    }
  } catch {
    toast.error('Ошибка', 'Не удалось отправить сообщение');
  } finally {
    bcSending.value = false;
  }
}

async function loadBcHistory() {
  bcHistoryLoading.value = true;
  try {
    const { data } = await db.rpc('get_broadcast_history', { limit: 20 });
    bcHistory.value = data || [];
  } catch (e) { console.warn('[admin] loadBcHistory:', e); }
  finally { bcHistoryLoading.value = false; }
}

async function deleteBroadcast(b) {
  const ok = await confirmAction('Удалить рассылку?', `Сообщение «${b.title || 'Важное сообщение'}» будет удалено для всех пользователей.`);
  if (!ok) return;
  b._deleting = true;
  try {
    const payload = b.is_legacy ? { id: b.id } : { broadcast_group: b.broadcast_group };
    const { data, error } = await db.rpc('delete_broadcast', payload);
    if (error || (data && !data.success)) { toast.error('Ошибка', error || data?.error || ''); return; }
    toast.success('Удалено', 'Рассылка удалена');
    bcHistory.value = bcHistory.value.filter(x => (x.broadcast_group || x.id) !== (b.broadcast_group || b.id));
  } catch {
    toast.error('Ошибка', 'Не удалось удалить');
  } finally {
    b._deleting = false;
  }
}

const formatBcDate = formatMoscowDateTime;

function formatBroadcastTargets(b) {
  const parts = [];
  if (b.target_staff_cabinet) parts.push('кабинет отдела закупок');
  if (b.target_restaurant_cabinet) parts.push('кабинет ресторанов');
  if (b.target_staff_telegram) parts.push('Telegram отдела закупок');
  if (b.target_restaurant_telegram) parts.push('Telegram ресторанов');
  return parts.join(', ');
}

function broadcastTelegramStats(b) {
  const parts = [];
  if (Number(b.staff_telegram_sent || 0) > 0) parts.push(`отдел закупок TG: ${b.staff_telegram_sent}`);
  if (Number(b.restaurant_telegram_sent || 0) > 0) parts.push(`рестораны TG: ${b.restaurant_telegram_sent}`);
  return parts.join(', ');
}

// ═══ Статистика ═══
const statsPeriod = ref('all');
const statsData = ref({});
const statsLoading = ref(false);
const statsPeriods = [
  { value: 'week', label: 'Неделя' },
  { value: 'month', label: 'Месяц' },
  { value: 'all', label: 'Всё время' },
];

function statsBarWidth(cnt) {
  const max = Math.max(...(statsData.value.orders_by_entity || []).map(e => e.cnt), 1);
  return Math.round((cnt / max) * 100) + '%';
}

async function loadStats() {
  statsLoading.value = true;
  try {
    const { data } = await db.rpc('get_admin_stats', { period: statsPeriod.value });
    statsData.value = data || {};
  } catch (e) { toast.error('Ошибка', 'Не удалось загрузить статистику'); }
  finally { statsLoading.value = false; }
}

// ═══ Настройки системы ═══
const sysSettings = ref([]);
const sysSettingsLoading = ref(false);

const SETTINGS_CATEGORIES = {
  'maintenance_mode': 'Система', 'maintenance_message': 'Система', 'maintenance_end_time': 'Система',
  'last_update': 'Данные',
};

const sysSettingsGrouped = computed(() => {
  const groups = {};
  for (const s of sysSettings.value) {
    const cat = SETTINGS_CATEGORIES[s.key] || 'Прочее';
    if (!groups[cat]) groups[cat] = [];
    groups[cat].push(s);
  }
  return Object.entries(groups).map(([name, items]) => ({ name, items }));
});

async function loadSysSettings() {
  sysSettingsLoading.value = true;
  try {
    const { data } = await db.from('settings').select('*').order('key');
    sysSettings.value = (data || []).map(s => ({ ...s, _editValue: s.value || '', _changed: false, _saving: false }));
  } catch { toast.error('Ошибка', 'Не удалось загрузить настройки'); }
  finally { sysSettingsLoading.value = false; }
}

async function saveSysSetting(s) {
  s._saving = true;
  try {
    const { error } = await db.from('settings').update({ value: s._editValue }).eq('key', s.key);
    if (error) { toast.error('Ошибка', ''); return; }
    s.value = s._editValue;
    s._changed = false;
    toast.success('Сохранено', s.key);
  } catch { toast.error('Ошибка', 'Не удалось сохранить'); }
  finally { s._saving = false; }
}

// ═══ Резервное копирование ═══
const backupEntity = ref('');
const backupSelected = ref([]);
const backupExporting = ref(false);

const backupTables = [
  { name: 'products', label: 'Товары' },
  { name: 'suppliers', label: 'Поставщики' },
  { name: 'orders', label: 'Заказы' },
  { name: 'order_items', label: 'Позиции заказов' },
  { name: 'plans', label: 'Планы' },
  { name: 'settings', label: 'Настройки' },
  { name: 'audit_log', label: 'Аудит-лог' },
  { name: 'stock_1c', label: 'Остатки 1С' },
  { name: 'analysis_data', label: 'Данные анализа' },
  { name: 'cards', label: 'Карточки' },
  { name: 'restaurants', label: 'Рестораны' },
  { name: 'delivery_schedule', label: 'График доставки' },
];

async function exportBackup() {
  backupExporting.value = true;
  try {
    const XLSX = await import('xlsx-js-style');
    const wb = XLSX.utils.book_new();

    for (const tableName of backupSelected.value) {
      let query = db.from(tableName).select('*');
      // Фильтр по юрлицу для таблиц с полем legal_entity
      if (backupEntity.value) {
        const tablesWithEntity = ['products', 'orders', 'plans', 'stock_1c', 'analysis_data', 'cards', 'suppliers', 'item_order'];
        if (tablesWithEntity.includes(tableName)) {
          query = query.eq('legal_entity', backupEntity.value);
        }
      }
      try {
        const { data } = await query;
        const rows = data || [];
        const ws = XLSX.utils.json_to_sheet(rows.length ? rows : [{ info: 'Нет данных' }]);
        const label = backupTables.find(t => t.name === tableName)?.label || tableName;
        XLSX.utils.book_append_sheet(wb, ws, label.slice(0, 31));
      } catch (e) {
        const ws = XLSX.utils.json_to_sheet([{ error: 'Не удалось загрузить' }]);
        XLSX.utils.book_append_sheet(wb, ws, tableName.slice(0, 31));
      }
    }

    const date = toLocalDateStr(new Date());
    const suffix = backupEntity.value ? '_' + backupEntity.value.replace(/[^\wа-яА-Я]/g, '') : '';
    XLSX.writeFile(wb, `backup_${date}${suffix}.xlsx`);
    toast.success('Готово', 'Файл скачан');
  } catch (e) {
    toast.error('Ошибка', 'Не удалось создать файл');
  } finally {
    backupExporting.value = false;
  }
}

// ═══ Сессии ═══
const sessionsList = ref([]);
const sessionsLoading = ref(false);

function isCurrentSession(s) {
  const currentToken = localStorage.getItem('bk_session_token') || '';
  return s.token === currentToken;
}

function parseUserAgent(ua) {
  if (!ua) return '—';
  if (ua.includes('Chrome') && !ua.includes('Edge')) return 'Chrome';
  if (ua.includes('Firefox')) return 'Firefox';
  if (ua.includes('Safari') && !ua.includes('Chrome')) return 'Safari';
  if (ua.includes('Edge')) return 'Edge';
  return ua.slice(0, 50);
}

function formatSessionDate(str) {
  if (!str) return '—';
  const d = new Date(str);
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' }) + ' ' +
         d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

async function loadSessions() {
  sessionsLoading.value = true;
  try {
    const { data } = await db.rpc('get_sessions');
    sessionsList.value = data || [];
  } catch (e) { toast.error('Ошибка', 'Не удалось загрузить сессии'); }
  finally { sessionsLoading.value = false; }
}

async function terminateSession(s) {
  if (isCurrentSession(s)) return;
  const ok = await confirmAction('Завершить сессию?', `Сессия пользователя «${s.user_name}» будет завершена.`);
  if (!ok) return;
  try {
    const { data } = await db.rpc('terminate_session', { session_id: s.id });
    if (data?.success) {
      toast.success('Сессия завершена', s.user_name);
      sessionsList.value = sessionsList.value.filter(x => x.id !== s.id);
    } else {
      toast.error('Ошибка', data?.error || '');
    }
  } catch { toast.error('Ошибка', 'Не удалось завершить сессию'); }
}

// ═══ Логи ошибок ═══
const ERROR_PAGE_SIZE = 50;
const errorEntries = ref([]);
const errorsLoading = ref(false);
const errorsHasMore = ref(false);
const errorsClearing = ref(false);
const expandedErrors = reactive(new Set());
const errorFilter = reactive({ level: '', source: '' });

const errorLevelOptions = [
  { value: '', label: 'Все' },
  { value: 'error', label: 'Ошибки' },
  { value: 'warning', label: 'Предупреждения' },
  { value: 'info', label: 'Информация' },
];

function errorBadgeClass(level) {
  if (level === 'error') return 'adm-audit-b-deleted';
  if (level === 'warning') return 'adm-audit-b-updated';
  return 'adm-audit-b-schedule';
}

function toggleErrorStack(id) {
  if (expandedErrors.has(id)) expandedErrors.delete(id);
  else expandedErrors.add(id);
}

async function loadErrors(reset = true) {
  if (reset) {
    errorEntries.value = [];
  }
  errorsLoading.value = true;
  try {
    const offset = reset ? 0 : errorEntries.value.length;
    let query = db.from('error_logs').select('*').order('created_at', { ascending: false }).limit(ERROR_PAGE_SIZE).offset(offset);
    if (errorFilter.level) query = query.eq('level', errorFilter.level);
    if (errorFilter.source) query = query.eq('source', errorFilter.source);
    const { data } = await query;
    const rows = data || [];
    if (reset) {
      errorEntries.value = rows;
    } else {
      errorEntries.value.push(...rows);
    }
    errorsHasMore.value = rows.length >= ERROR_PAGE_SIZE;
  } catch { toast.error('Ошибка', 'Не удалось загрузить логи'); }
  finally { errorsLoading.value = false; }
}

async function clearErrors() {
  const ok = await confirmAction('Очистить все ошибки?', 'Все записи логов ошибок будут удалены безвозвратно.');
  if (!ok) return;
  errorsClearing.value = true;
  try {
    const { data } = await db.rpc('clear_error_logs');
    if (data?.success) {
      errorEntries.value = [];
      toast.success('Очищено', 'Логи ошибок удалены');
    }
  } catch { toast.error('Ошибка', 'Не удалось очистить логи'); }
  finally { errorsClearing.value = false; }
}

// ═══ Обновления (Changelog) ═══
const changelogEntries = ref([]);
const changelogLoading = ref(false);
const changelogSaving = ref(false);
const changelogModal = ref({ show: false, entry: null });
const changelogForm = ref({ version: '', title: '', description: '' });
let _changelogFormSnapshot = '';

function tryCloseChangelog() {
  if (JSON.stringify(changelogForm.value) !== _changelogFormSnapshot) {
    confirmAction('Закрыть без сохранения?', 'Введённые данные будут потеряны.').then(ok => {
      if (ok) changelogModal.value.show = false;
    });
    return;
  }
  changelogModal.value.show = false;
}

async function loadChangelog() {
  changelogLoading.value = true;
  try {
    const { data } = await db.rpc('get_changelog');
    changelogEntries.value = data || [];
  } catch { toast.error('Ошибка', 'Не удалось загрузить обновления'); }
  finally { changelogLoading.value = false; }
}

function openChangelogModal(entry) {
  changelogModal.value.entry = entry;
  if (entry) {
    changelogForm.value = { version: entry.version, title: entry.title, description: entry.description || '' };
  } else {
    changelogForm.value = { version: '', title: '', description: '' };
  }
  changelogModal.value.show = true;
  _changelogFormSnapshot = JSON.stringify(changelogForm.value);
}

async function saveChangelog() {
  if (!changelogForm.value.version.trim() || !changelogForm.value.title.trim()) return;
  changelogSaving.value = true;
  try {
    const payload = {
      version: changelogForm.value.version.trim(),
      title: changelogForm.value.title.trim(),
      description: changelogForm.value.description.trim() || null,
    };
    if (changelogModal.value.entry) {
      const { error } = await db.from('changelog').update(payload).eq('id', changelogModal.value.entry.id);
      if (error) { toast.error('Ошибка', ''); return; }
      toast.success('Обновлено', payload.title);
    } else {
      payload.created_by = userStore.currentUser?.name || '';
      const { error } = await db.from('changelog').insert(payload);
      if (error) { toast.error('Ошибка', ''); return; }
      toast.success('Создано', payload.title);
    }
    changelogModal.value.show = false;
    await loadChangelog();
  } catch { toast.error('Ошибка', 'Не удалось сохранить'); }
  finally { changelogSaving.value = false; }
}

async function deleteChangelog(entry) {
  const ok = await confirmAction('Удалить запись?', `Обновление «${entry.title}» будет удалено.`);
  if (!ok) return;
  try {
    const { error } = await db.from('changelog').delete().eq('id', entry.id);
    if (error) { toast.error('Ошибка', ''); return; }
    toast.success('Удалено', entry.title);
    changelogEntries.value = changelogEntries.value.filter(e => e.id !== entry.id);
  } catch { toast.error('Ошибка', 'Не удалось удалить'); }
}

// immediate: true — чтобы данные загружались и при заходе на вкладку через URL `?tab=...`
watch(activeTab, (tab) => {
  if (tab === 'sessions') {
    loadOnlineUsers();
    loadOnlineRestaurants();
    loadSessions();
    if (onlineTimer) clearInterval(onlineTimer);
    // Не дёргаем сервер на скрытой вкладке — экономит трафик и нагрузку.
    onlineTimer = setInterval(() => {
      if (typeof document === 'undefined' || document.visibilityState === 'visible') {
        loadOnlineUsers();
        loadOnlineRestaurants();
      }
    }, 15000);
  } else {
    if (onlineTimer) { clearInterval(onlineTimer); onlineTimer = null; }
  }
  if (tab === 'broadcast') {
    loadBcHistory();
    if (!changelogEntries.value.length) loadChangelog();
  }
  if (tab === 'audit') {
    if (!auditEntries.value.length) loadAudit(true);
    if (!auditUsers.value.length) loadAuditUsers();
  }
  if (tab === 'stats') {
    if (!Object.keys(statsData.value).length) loadStats();
  }
  if (tab === 'feedback') {
    loadBugReports();
    startBugPoll();
  } else {
    stopBugPoll();
  }
}, { immediate: true });

const usersWord = computed(() => {
  const n = users.value.length;
  if (n % 10 === 1 && n % 100 !== 11) return 'пользователь';
  if ([2,3,4].includes(n % 10) && ![12,13,14].includes(n % 100)) return 'пользователя';
  return 'пользователей';
});

function parseLe(val) {
  if (!val) return [];
  if (Array.isArray(val)) return val;
  try { return JSON.parse(val) || []; } catch { return []; }
}

function shortEntity(le) {
  const map = ENTITY_SHORT_NAMES;
  return map[le] || le;
}

function initials(name) {
  if (!name) return '?';
  return name.split(/\s+/).map(w => w[0]).join('').toUpperCase().slice(0, 2);
}

async function loadUsers() {
  loading.value = true;
  try {
    const { data } = await db.from('users').select('*').order('name');
    users.value = (data || []).map(u => {
      if (u.permissions && typeof u.permissions === 'string') {
        try { u.permissions = JSON.parse(u.permissions); } catch { u.permissions = null; }
      }
      return u;
    });
  } catch { toast.error('Ошибка', 'Не удалось загрузить пользователей'); }
  finally { loading.value = false; }
}

async function loadSettings() {
  try {
    const { data } = await db.from('settings').select('*').or('key.eq.maintenance_mode,key.eq.maintenance_message,key.eq.maintenance_end_time');
    if (!data) return;
    for (const s of data) {
      if (s.key === 'maintenance_mode') maintenanceOn.value = s.value === 'true';
      if (s.key === 'maintenance_message') maintenanceMsg.value = s.value || '';
      if (s.key === 'maintenance_end_time') maintenanceEndTimeCurrent.value = s.value || null;
    }
  } catch (e) { console.warn('[admin] loadSettings:', e); }
}

async function saveMaintenanceMsg() {
  maintenanceMsgSaving.value = true;
  try {
    const { error } = await db.from('settings').update({ value: maintenanceMsg.value }).eq('key', 'maintenance_message');
    if (error) { toast.error('Ошибка', ''); return; }
    toast.success('Сообщение сохранено', '');
  } finally { maintenanceMsgSaving.value = false; }
}

function openUserModal(user) {
  userModal.value.user = user;
  if (user) {
    const perms = user.permissions;
    form.value = {
      name: user.name || '',
      email: user.email || '',
      password: '',
      role: user.role || 'user',
      display_role: user.display_role || '',
      legal_entities: parseLe(user.legal_entities),
      permissions: (perms && typeof perms === 'object') ? { ...perms } : {},
    };
  } else {
    form.value = { name: '', email: '', password: '', role: 'user', display_role: '', legal_entities: [], permissions: {} };
  }
  userModal.value.show = true;
  _userFormSnapshot = JSON.stringify(form.value);
}

async function saveUser() {
  if (saving.value) return;
  if (!form.value.name.trim()) { toast.error('Введите имя', ''); return; }
  if (!userModal.value.user && !form.value.password) { toast.error('Введите пароль', ''); return; }
  if (form.value.password && form.value.password.length < 8) { toast.error('Короткий пароль', 'Минимум 8 символов'); return; }
  saving.value = true;
  try {
    const payload = {
      name: form.value.name.trim(),
      email: form.value.email.trim(),
      role: form.value.role,
      display_role: form.value.display_role.trim() || null,
      legal_entities: JSON.stringify(form.value.legal_entities),
      permissions: getPermissionsDiff(),
    };
    if (form.value.password) payload.password = form.value.password;

    if (userModal.value.user) {
      const { data, error } = await db.rpc('update_user', {
        caller_name: userStore.currentUser?.name || '',
        user_id: userModal.value.user.id,
        ...payload,
      });
      if (error || (data && !data.success)) { toast.error('Ошибка', error || data?.error || ''); return; }
      toast.success('Обновлено', payload.name);
    } else {
      if (!form.value.password) { toast.error('Введите пароль', ''); return; }
      const { data, error } = await db.rpc('create_user', {
        caller_name: userStore.currentUser?.name || '',
        ...payload,
        password: form.value.password,
      });
      if (error || (data && !data.success)) { toast.error('Ошибка', error || data?.error || ''); return; }
      toast.success('Создано', payload.name);
    }
    userModal.value.show = false;
    await loadUsers();
  } finally { saving.value = false; }
}

async function deleteUser(u) {
  if (u.name === userStore.currentUser?.name) { toast.error('Нельзя удалить себя', ''); return; }
  const ok = await confirmAction('Удалить пользователя?', `Пользователь «${u.name}» будет удалён безвозвратно.`);
  if (!ok) return;
  const { data, error } = await db.rpc('delete_user', { caller_name: userStore.currentUser?.name || '', user_id: u.id });
  if (error || (data && !data.success)) { toast.error('Ошибка', error || data?.error || ''); return; }
  toast.success('Удалено', u.name);
  await loadUsers();
}

async function toggleMaintenance() {
  maintenanceSaving.value = true;
  const newVal = !maintenanceOn.value;
  try {
    const { error } = await db.from('settings').update({ value: String(newVal) }).eq('key', 'maintenance_mode');
    if (error) { toast.error('Ошибка', ''); return; }
    maintenanceOn.value = newVal;
    userStore.maintenanceMode = newVal;
    // При выключении очищаем таймер
    if (!newVal) {
      await updateSetting('maintenance_end_time', '');
      maintenanceEndTimeCurrent.value = null;
      userStore.maintenanceEndTime = null;
    }
    toast.success(newVal ? 'Тех. работы включены' : 'Тех. работы выключены', '');
  } finally { maintenanceSaving.value = false; }
}

async function updateSetting(key, value) {
  const { error } = await db.from('settings').update({ value }).eq('key', key);
  if (error) toast.error('Ошибка', 'Не удалось сохранить настройку');
}

function setQuickTimer(minutes) {
  const endDate = new Date(Date.now() + minutes * 60 * 1000);
  maintenanceTimeInput.value = endDate.getHours().toString().padStart(2, '0') + ':' + endDate.getMinutes().toString().padStart(2, '0');
  saveExactTime();
}

async function saveExactTime() {
  if (!maintenanceTimeInput.value) return;
  maintenanceTimerSaving.value = true;
  try {
    const parts = maintenanceTimeInput.value.split(':');
    if (parts.length < 2) { toast.error('Неверный формат', 'Используйте ЧЧ:ММ'); maintenanceTimerSaving.value = false; return; }
    const [hh, mm] = parts.map(Number);
    if (isNaN(hh) || isNaN(mm) || hh < 0 || hh > 23 || mm < 0 || mm > 59) { toast.error('Неверное время', ''); maintenanceTimerSaving.value = false; return; }
    const target = new Date();
    target.setHours(hh, mm, 0, 0);
    // Если время уже прошло — считаем, что это завтра
    if (target.getTime() <= Date.now()) {
      target.setDate(target.getDate() + 1);
    }
    const endTimeVal = target.toISOString();
    await updateSetting('maintenance_end_time', endTimeVal);
    maintenanceEndTimeCurrent.value = endTimeVal;
    userStore.maintenanceEndTime = endTimeVal;
    toast.success('Таймер установлен', `Выключится в ${maintenanceTimeInput.value}`);
  } catch (e) { toast.error('Ошибка', ''); }
  finally { maintenanceTimerSaving.value = false; }
}

async function clearTimer() {
  maintenanceTimerSaving.value = true;
  try {
    await updateSetting('maintenance_end_time', '');
    maintenanceEndTimeCurrent.value = null;
    userStore.maintenanceEndTime = null;
    maintenanceTimeInput.value = '';
    toast.success('Таймер сброшен', '');
  } catch (e) { toast.error('Ошибка', ''); }
  finally { maintenanceTimerSaving.value = false; }
}

onMounted(() => {
  // Повторная проверка роли (защита от подмены в localStorage до ответа сервера)
  if (userStore.currentUser?.role !== 'admin') return;
  loadRbacConfig(); // загрузить актуальные роли с сервера
  loadUsers(); loadSettings();
});
onUnmounted(() => { if (onlineTimer) clearInterval(onlineTimer); });

// Если сервер подтвердит другую роль — перенаправить
watch(() => userStore.currentUser?.role, (role) => {
  if (role && role !== 'admin') router.replace({ name: 'order' });
});

// ═══ Крон напоминаний (журнал запусков) ═══
const cronReminders = ref([]);
const cronLoading = ref(false);
const cronErrCount = ref(0);

async function loadCronReminders() {
  cronLoading.value = true;
  try {
    const res = await db.from('reminder_cron_log')
      .select('*')
      .order('started_at', { ascending: false })
      .limit(100);
    if (res.error) {
      cronReminders.value = [];
      return;
    }
    cronReminders.value = res.data || [];
    // Подсчёт ошибок за сутки
    const dayAgo = Date.now() - 24 * 60 * 60 * 1000;
    cronErrCount.value = cronReminders.value.filter(r => r.status === 'error' && new Date(r.started_at.replace(' ', 'T')).getTime() > dayAgo).length;
  } catch (e) {
    cronReminders.value = [];
  } finally {
    cronLoading.value = false;
  }
}

function fmtCronTime(ts) {
  if (!ts) return '';
  try {
    const dt = new Date(ts.replace(' ', 'T'));
    const today = new Date();
    const sameDay = dt.toDateString() === today.toDateString();
    if (sameDay) return dt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    return dt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' }) + ' ' +
           dt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  } catch { return ts; }
}

function cronDuration(row) {
  if (!row.started_at || !row.finished_at) return '—';
  try {
    const s = new Date(row.started_at.replace(' ', 'T')).getTime();
    const e = new Date(row.finished_at.replace(' ', 'T')).getTime();
    const ms = e - s;
    if (ms < 1000) return ms + ' мс';
    return (ms / 1000).toFixed(1) + ' с';
  } catch { return '—'; }
}

function truncateError(text) {
  if (!text) return 'Ошибка';
  return text.length > 50 ? text.slice(0, 47) + '…' : text;
}

// Загружаем сразу при первом открытии вкладки (см. v-on:click в шаблоне).
// Дополнительно — стартовая проверка ошибок при монтировании для бейджа.
onMounted(async () => {
  try {
    const res = await db.from('reminder_cron_log')
      .select('status,started_at')
      .order('started_at', { ascending: false })
      .limit(50);
    if (res.data) {
      const dayAgo = Date.now() - 24 * 60 * 60 * 1000;
      cronErrCount.value = res.data.filter(r => r.status === 'error' && new Date(r.started_at.replace(' ', 'T')).getTime() > dayAgo).length;
    }
  } catch (e) { /* ignore */ }
});

// ═══ Обращения (баг-репорты) ═══
const apiBase = import.meta.env.VITE_API_URL || '/api';
const sessionToken = localStorage.getItem('bk_session_token') || '';
const bugReports = ref([]);
const bugLoading = ref(false);
const bugFilterStatus = ref('');
const bugNewCount = ref(0);
const bugDetail = ref(null);
const bugReplies = ref([]);
const bugReplyText = ref('');
const bugReplySending = ref(false);

// Карта одноразовых URL для картинок багрепорта (path → URL).
// Заполняется при открытии bugDetail и при обновлении ответов.
const bugImageUrls = ref({});
function bugImageUrl(path) { return bugImageUrls.value[path] || ''; }
async function refreshBugImageUrls() {
  const paths = new Set();
  for (const s of (bugDetail.value?.screenshots || [])) paths.add(s);
  for (const r of (bugReplies.value || [])) {
    const re = /\[img:([^\]]+)\]/g;
    let m;
    while ((m = re.exec(r.message || '')) !== null) {
      const raw = m[1];
      if (/^uploads\/[a-zA-Z0-9_\-/.]+$/.test(raw) && !raw.includes('..')) paths.add(raw);
    }
  }
  const map = { ...bugImageUrls.value };
  for (const p of paths) {
    if (map[p]) continue;
    try {
      const { data } = await db.rpc('create_download_token', { file_path: p });
      if (data?.token) {
        const sep = p.includes('?') ? '&' : '?';
        map[p] = `${apiBase}/${p}${sep}dl=${encodeURIComponent(data.token)}`;
      }
    } catch { map[p] = ''; }
  }
  bugImageUrls.value = map;
}
watch([bugDetail, bugReplies], () => { refreshBugImageUrls(); }, { deep: false });

const filteredBugReports = computed(() => {
  if (!bugFilterStatus.value) return bugReports.value;
  return bugReports.value.filter(r => r.status === bugFilterStatus.value);
});

async function loadBugReports() {
  bugLoading.value = true;
  try {
    const { data } = await db.rpc('get_bug_reports', {});
    bugReports.value = data?.reports || [];
    bugNewCount.value = bugReports.value.filter(r => r.status === 'new').length;
  } finally {
    bugLoading.value = false;
  }
}

async function openBugDetail(r) {
  const { data } = await db.rpc('get_bug_report', { id: r.id });
  if (data?.report) {
    bugDetail.value = data.report;
    bugReplies.value = data.replies || [];
    bugReplyText.value = '';
    scrollChatToBottom();
  }
}

async function updateBugStatus(r) {
  await db.rpc('update_bug_report_status', { id: r.id, status: r.status });
  toast.success('Статус обновлён');
  loadBugReports();
}

const bugChatScroll = ref(null);
const bugReplyImages = ref([]);

function onBugMsgClick(e) {
  const t = e.target;
  if (t && t.tagName === 'IMG' && t.dataset.bugImg === '1') {
    window.open(t.src, '_blank', 'noopener');
  }
}

// Второй аргумент urlsMap — { path: url }. Vue ловит изменения через
// :data-img-rev в template-узле, поэтому рендер пересчитывается при
// заполнении одноразовых URL картинок. Default НЕ пишем в сигнатуре,
// чтобы избежать TDZ-ошибки при ранних вызовах из watch immediate
// (минификатор иногда переставляет порядок объявлений).
function renderMsgContent(msg, urlsMap) {
  if (!urlsMap) urlsMap = bugImageUrls.value;
  if (!msg) return '';
  // Экранируем всё, включая кавычки — чтобы нельзя было вырваться из src="..."
  const escapeHtml = (s) => s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
  // Идём по оригинальному тексту, собираем результат из экранированных кусков
  const re = /\[img:([^\]]*?)\]/g;
  let result = '';
  let lastIdx = 0;
  let m;
  while ((m = re.exec(msg)) !== null) {
    result += escapeHtml(msg.slice(lastIdx, m.index));
    const raw = m[1] || '';
    // Белый список: только пути uploads/... с безопасными символами
    if (/^uploads\/[a-zA-Z0-9_\-/.]+$/.test(raw) && !raw.includes('..')) {
      // URL берём из urlsMap — заполняется заранее в refreshBugImageUrls.
      const url = (urlsMap || {})[raw] || '';
      const src = escapeHtml(url);
      result += '<img src="' + src + '" class="fb-msg-img" data-bug-img="1" />';
    }
    lastIdx = m.index + m[0].length;
  }
  result += escapeHtml(msg.slice(lastIdx));
  return result;
}

async function uploadBugImage(file) {
  if (!file.type.startsWith('image/')) return;
  const preview = URL.createObjectURL(file);
  const item = { preview, path: null, uploading: true };
  bugReplyImages.value.push(item);
  try {
    const fd = new FormData();
    fd.append('file', file);
    const res = await fetch(apiBase + '/upload/bug-screenshot', {
      method: 'POST', body: fd,
      headers: { 'X-Session-Token': sessionToken },
    });
    const data = await res.json();
    item.path = data.path || null;
    if (!item.path) bugReplyImages.value = bugReplyImages.value.filter(x => x !== item);
  } catch { bugReplyImages.value = bugReplyImages.value.filter(x => x !== item); }
  finally { item.uploading = false; }
}

function onBugReplyFiles(e) {
  for (const f of Array.from(e.target.files || [])) uploadBugImage(f);
  e.target.value = '';
}

function onBugReplyPaste(e) {
  const items = e.clipboardData?.items;
  if (!items) return;
  for (const item of items) {
    if (item.type.startsWith('image/')) {
      e.preventDefault();
      const file = item.getAsFile();
      if (file) uploadBugImage(file);
    }
  }
}

function scrollChatToBottom() {
  nextTick(() => {
    const el = bugChatScroll.value;
    if (el) el.scrollTop = el.scrollHeight;
  });
}

function autoResizeReply(e) {
  const el = e.target;
  el.style.height = 'auto';
  el.style.height = Math.min(el.scrollHeight, 100) + 'px';
}

async function sendBugReply() {
  if (!bugDetail.value?.id) return;
  const text = bugReplyText.value.trim();
  const images = bugReplyImages.value.filter(x => x.path).map(x => x.path);
  if (!text && !images.length) return;
  if (bugReplySending.value) return;
  bugReplySending.value = true;
  try {
    let msg = text;
    if (images.length) {
      const imgTags = images.map(p => '[img:' + p + ']').join(' ');
      msg = msg ? msg + '\n' + imgTags : imgTags;
    }
    const reportId = bugDetail.value.id;
    const { error } = await db.rpc('reply_bug_report', { report_id: reportId, message: msg });
    if (error) { toast.error('Не удалось отправить', error); return; }
    bugReplyText.value = '';
    bugReplyImages.value = [];
    const { data } = await db.rpc('get_bug_report', { id: reportId });
    if (data) {
      bugDetail.value = data.report;
      bugReplies.value = data.replies || [];
    }
    scrollChatToBottom();
    loadBugReports();
  } finally {
    bugReplySending.value = false;
  }
}

async function deleteBugReport(r) {
  if (!confirm('Удалить обращение #' + r.id + '?')) return;
  await db.rpc('delete_bug_report', { id: r.id });
  bugDetail.value = null;
  toast.success('Обращение удалено');
  loadBugReports();
}

function bugStatusLabel(s) {
  return { new: '🟠 Новое', in_progress: '🔵 В работе', resolved: '🟢 Решено', closed: '⚫ Закрыто' }[s] || s;
}

function formatBugDate(str) {
  if (!str) return '';
  const d = new Date(str);
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' }) + ' ' +
    d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

// bugPollTimer объявлен выше (рядом с onlineTimer) — иначе TDZ при
// immediate watch на activeTab, если таб 'feedback' открывается сразу.

async function bugPoll() {
  if (activeTab.value !== 'feedback') return;
  if (typeof document !== 'undefined' && document.visibilityState !== 'visible') return;
  try {
    const { data } = await db.rpc('get_bug_reports', {});
    if (data?.reports) {
      bugReports.value = data.reports;
      bugNewCount.value = data.reports.filter(r => r.status === 'new').length;
    }
    // Если открыта детальная карточка — обновить ответы
    if (bugDetail.value) {
      const oldCount = bugReplies.value.length;
      const { data: d2 } = await db.rpc('get_bug_report', { id: bugDetail.value.id });
      if (d2) {
        bugDetail.value = d2.report;
        bugReplies.value = d2.replies || [];
        if (d2.replies?.length > oldCount) scrollChatToBottom();
      }
    }
  } catch (e) { console.warn('[admin] bugPoll error:', e); }
}

function startBugPoll() {
  if (bugPollTimer) clearInterval(bugPollTimer);
  bugPollTimer = setInterval(bugPoll, 10000);
}

function stopBugPoll() {
  if (bugPollTimer) { clearInterval(bugPollTimer); bugPollTimer = null; }
}

onMounted(() => {
  db.rpc('get_bug_reports_count', {}).then(({ data }) => {
    if (data) bugNewCount.value = data.new_count || 0;
  }).catch(() => {});
  if (activeTab.value === 'feedback') startBugPoll();
});

onUnmounted(() => {
  stopBugPoll();
});
</script>

<style scoped>
.adm-cron-table { width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 10px; }
.adm-cron-table th, .adm-cron-table td { padding: 6px 8px; text-align: center; border-bottom: 1px solid #eee; }
.adm-cron-table th { background: #fafafa; font-size: 11px; color: #555; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 600; }
.adm-cron-table .adm-cron-subhead th { font-size: 14px; font-weight: 400; padding: 2px 4px; background: #fff; border-bottom: 1px solid #eee; }
.adm-cron-ts { text-align: left; font-family: monospace; font-size: 11px; color: #666; }
.cron-num { font-family: monospace; }
.cron-skip { color: #888; }
.cron-err { background: #fff0f0; }
.cron-err td { color: #b71c1c; }
.cron-status-ok { color: #2e7d32; font-weight: 700; }
.cron-status-err { color: #c62828; font-weight: 600; font-size: 11px; }
.adm-cron-err { color: #c62828; font-weight: 500; margin-left: 6px; }

/* ═══ Layout ═══ */
.admin-view { padding: 0; }
.adm-section { animation: admFade .2s ease; }
@keyframes admFade { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }

/* ═══ Tabs ═══ */
.adm-tabs {
  display: flex; flex-wrap: wrap; gap: 0; margin-bottom: 20px;
  border-bottom: 2px solid var(--border-light);
}
.adm-tab {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 10px 22px; font-size: 14px; font-weight: 600; font-family: inherit;
  color: var(--text-muted); background: none; border: none;
  border-bottom: 2.5px solid transparent; margin-bottom: -2px;
  cursor: pointer; transition: all .15s; position: relative;
}
.adm-tab.active { color: var(--bk-brown); border-bottom-color: var(--bk-brown); }
.adm-tab:hover:not(.active) { color: var(--text); background: rgba(139,115,85,.04); }
.adm-tab-count {
  font-size: 11px; font-weight: 700; padding: 1px 7px;
  border-radius: 10px; background: var(--border-light); color: var(--text-muted);
}
.adm-tab-count.active { background: var(--bk-brown); color: #fff; }
.adm-tab-dot {
  width: 7px; height: 7px; border-radius: 50%; background: #D32F2F;
  position: absolute; top: 8px; right: 10px;
  animation: admPulse 2s infinite;
}
@keyframes admPulse { 0%, 100% { opacity: 1; } 50% { opacity: .4; } }

/* ═══ Toolbar ═══ */
.adm-toolbar {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 16px;
}
.adm-toolbar-info { font-size: 13px; color: var(--text-muted); font-weight: 500; }
.adm-empty { text-align: center; padding: 48px; color: var(--text-muted); font-size: 14px; }

/* ═══ User List ═══ */
.adm-user-list { display: flex; flex-direction: column; gap: 2px; }
.adm-user-row {
  display: flex; align-items: center; gap: 14px;
  padding: 10px 14px; border-radius: 10px;
  background: var(--card); border: 1.5px solid transparent;
  cursor: pointer; transition: all .15s;
}
.adm-user-row:hover { border-color: var(--bk-orange); box-shadow: 0 2px 8px rgba(244,162,97,.08); }

.adm-user-avatar {
  width: 40px; height: 40px; border-radius: 12px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 14px; font-weight: 700; color: #fff;
  background: linear-gradient(135deg, #F4A261, #E8941A);
}
.adm-user-avatar.admin { background: linear-gradient(135deg, #E53935, #C62828); }

.adm-user-info { flex: 1; min-width: 0; }
.adm-user-name {
  font-size: 14px; font-weight: 600; color: var(--text);
  display: flex; align-items: center; gap: 6px;
}
.adm-user-email { font-size: 11px; color: var(--text-muted); margin-top: 1px; opacity: .7; }
.adm-user-meta { font-size: 12px; color: var(--text-muted); margin-top: 1px; }

.adm-badge {
  display: inline-block; padding: 1px 7px; border-radius: 4px;
  font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px;
}
.adm-badge-admin { background: #FFEBEE; color: #C62828; }
.adm-badge-viewer { background: #E3F2FD; color: #1565C0; }
.adm-badge-you { background: #E8F5E9; color: #2E7D32; }

.adm-user-entities { display: flex; gap: 4px; flex-shrink: 0; }
.adm-entity {
  padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 600;
  background: #FFF8E1; color: #E65100; border: 1px solid #FFE0B2;
}
.adm-entity-all { background: var(--bg); color: var(--text-muted); border-color: var(--border-light); }

.adm-user-actions { display: flex; gap: 4px; opacity: 0; transition: opacity .15s; flex-shrink: 0; }
.adm-user-row:hover .adm-user-actions { opacity: 1; }
.adm-act-btn {
  padding: 5px 7px; border-radius: 6px; border: 1px solid var(--border-light);
  background: none; cursor: pointer; transition: all .15s; color: var(--text-muted);
}
.adm-act-btn:hover { background: var(--bg); border-color: var(--border); color: var(--text); }
.adm-act-del:hover { background: #FFF0F0; border-color: #E57373; color: #D32F2F; }
.adm-act-btn:disabled { opacity: .3; pointer-events: none; }

/* ═══ Maintenance ═══ */
.adm-maint-card {
  display: flex; align-items: center; gap: 20px;
  padding: 24px; border-radius: 14px;
  background: var(--card); border: 2px solid var(--border-light);
  transition: all .3s;
}
.adm-maint-card.on { border-color: #FFCDD2; background: #FFFAFA; }

.adm-maint-icon { flex-shrink: 0; }
.adm-maint-body { flex: 1; }
.adm-maint-title { margin: 0 0 4px; font-size: 16px; font-weight: 700; color: var(--text); }
.adm-maint-desc { margin: 0; font-size: 13px; color: var(--text-muted); line-height: 1.5; }

.adm-maint-toggle {
  display: flex; flex-direction: column; align-items: center; gap: 6px;
  background: none; border: none; cursor: pointer; padding: 8px; flex-shrink: 0;
  font-family: inherit;
}
.adm-maint-track {
  position: relative; width: 52px; height: 28px; border-radius: 14px;
  background: var(--border); transition: background .25s;
}
.adm-maint-toggle.on .adm-maint-track { background: #D32F2F; }
.adm-maint-thumb {
  position: absolute; top: 3px; left: 3px;
  width: 22px; height: 22px; border-radius: 50%;
  background: #fff; box-shadow: 0 1px 4px rgba(0,0,0,.18);
  transition: left .25s;
}
.adm-maint-toggle.on .adm-maint-thumb { left: 27px; }
.adm-maint-label {
  font-size: 11px; font-weight: 600;
  color: var(--text-muted); transition: color .2s;
}
.adm-maint-toggle.on .adm-maint-label { color: #D32F2F; }

.adm-maint-warning {
  display: flex; align-items: center; gap: 8px;
  margin-top: 12px; padding: 12px 16px; border-radius: 10px;
  background: #FFF3F3; border: 1.5px solid #FFCDD2;
  font-size: 13px; color: #C62828; font-weight: 500;
  animation: admFade .3s ease;
}

/* ═══ Form (modal) ═══ */
.adm-form { display: flex; flex-direction: column; gap: 10px; }

.adm-le-grid { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
.adm-le-option {
  display: flex; align-items: center; gap: 8px; cursor: pointer;
  padding: 8px 14px; border: 1.5px solid var(--border); border-radius: 8px;
  font-size: 13px; font-weight: 500; color: var(--text-muted);
  transition: all .15s; user-select: none;
}
.adm-le-option:hover { border-color: var(--bk-orange); }
.adm-le-option:has(input:checked) {
  border-color: var(--bk-orange); background: #FFFBF5; color: var(--bk-brown);
}
.adm-le-option input { display: none; }
.adm-le-box {
  width: 18px; height: 18px; border-radius: 5px;
  border: 2px solid var(--border); display: flex; align-items: center; justify-content: center;
  transition: all .15s; color: transparent;
}
.adm-le-option:has(input:checked) .adm-le-box {
  background: var(--bk-orange); border-color: var(--bk-orange); color: #fff;
}
.adm-le-hint { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

/* ═══ Maintenance Message ═══ */
.adm-maint-msg-card {
  margin-top: 16px; padding: 20px; border-radius: 14px;
  background: var(--card); border: 1.5px solid var(--border-light);
}
.adm-maint-msg-title { margin: 0 0 4px; font-size: 14px; font-weight: 700; color: var(--text); }
.adm-maint-msg-hint { margin: 0 0 10px; font-size: 12px; color: var(--text-muted); }
.adm-maint-textarea {
  width: 100%; padding: 10px 14px; border: 1.5px solid var(--border); border-radius: 8px;
  font-size: 13px; font-family: inherit; resize: vertical;
  transition: border-color .15s; box-sizing: border-box;
  background: var(--bg);
}
.adm-maint-textarea:focus { border-color: var(--bk-orange); outline: none; box-shadow: 0 0 0 3px rgba(244,162,97,.1); }

/* Timer */
.adm-timer-row { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px; }
.adm-timer-btn {
  padding: 7px 14px; border-radius: 8px; font-size: 13px; font-weight: 600;
  font-family: inherit; cursor: pointer; transition: all .15s;
  border: 1.5px solid var(--border); background: var(--bg); color: var(--text-muted);
}
.adm-timer-btn:hover { border-color: var(--bk-orange); color: var(--text); }
.adm-timer-btn.active { border-color: var(--bk-orange); background: #FFFBF5; color: var(--bk-brown); }
.adm-timer-custom { margin-top: 12px; }
.adm-timer-custom-label { font-size: 12px; color: var(--text-muted); font-weight: 500; display: block; margin-bottom: 6px; }
.adm-timer-input-row { display: flex; gap: 8px; align-items: center; }
.adm-timer-input {
  padding: 7px 12px; border: 1.5px solid var(--border); border-radius: 8px;
  font-size: 15px; font-family: inherit; font-weight: 600;
  background: var(--bg); color: var(--text); width: 120px;
}
.adm-timer-input:focus { border-color: var(--bk-orange); outline: none; box-shadow: 0 0 0 3px rgba(244,162,97,.1); }
.adm-timer-info {
  margin-top: 12px; font-size: 13px; color: var(--text-secondary);
  padding: 10px 14px; border-radius: 8px; background: #FFF8E1; border: 1px solid #FFE0B2;
  display: flex; align-items: center; justify-content: space-between;
}
.adm-timer-info-off { background: var(--bg); border-color: var(--border-light); color: var(--text-muted); }
.adm-timer-clear {
  background: none; border: 1px solid #E57373; color: #D32F2F;
  padding: 4px 12px; border-radius: 6px; font-size: 12px; font-weight: 600;
  font-family: inherit; cursor: pointer; transition: all .15s;
}
.adm-timer-clear:hover { background: #FFF0F0; }

/* ═══ Online ═══ */
.adm-avatar-online {
  position: relative;
}
.adm-online-dot {
  position: absolute; bottom: -1px; right: -1px;
  width: 11px; height: 11px; border-radius: 50%;
  background: #4CAF50; border: 2px solid var(--card);
}
.adm-online-time {
  font-size: 12px; color: var(--text-muted); flex-shrink: 0; white-space: nowrap;
}

/* ═══ Broadcast History ═══ */
.bc-history-item {
  padding: 12px 14px; border-radius: 10px;
  background: var(--bg); border: 1px solid var(--border-light);
}
.bc-history-title { font-size: 14px; font-weight: 700; color: var(--text); margin-bottom: 4px; }
.bc-history-msg { font-size: 13px; color: var(--text-secondary); line-height: 1.5; white-space: pre-line; }
.bc-history-meta { font-size: 11px; color: var(--text-muted); margin-top: 6px; }
.bc-delete-btn {
  flex-shrink: 0; background: none; border: none; cursor: pointer;
  color: var(--text-muted); padding: 4px; border-radius: 6px; transition: all .15s;
}
.bc-delete-btn:hover { color: #e53e3e; background: rgba(229,62,62,.08); }
.bc-delete-btn:disabled { opacity: .4; cursor: not-allowed; }

/* ═══ Responsive ═══ */
@media (max-width: 600px) {
  .adm-user-entities { display: none; }
  .adm-user-actions { opacity: 1; }
  .adm-maint-card { flex-direction: column; text-align: center; gap: 12px; padding: 16px; }

  /* Tabs — compact */
  .adm-tabs { gap: 0; }
  .adm-tab { padding: 8px 12px; font-size: 12px; gap: 4px; }
  .adm-tab-count { font-size: 10px; padding: 1px 5px; }

  /* User row — tighter */
  .adm-user-row { gap: 10px; padding: 8px 10px; }
  .adm-user-avatar { width: 34px; height: 34px; font-size: 12px; border-radius: 10px; }
  .adm-user-name { font-size: 13px; flex-wrap: wrap; }

  /* Toolbar wrap */
  .adm-toolbar { flex-wrap: wrap; gap: 8px; }

  /* Broadcast card */
  .adm-maint-msg-card { padding: 14px; }
  .adm-maint-msg-title { font-size: 13px; }

  /* Online time */
  .adm-online-time { font-size: 11px; }
}

/* ═══ Permissions Grid ═══ */
.adm-perm-admin-note {
  padding: 10px 14px; border-radius: 8px; background: #E3F2FD;
  color: #1565C0; font-size: 13px; border: 1px solid #BBDEFB;
}
.adm-perm-grid {
  border: 1px solid var(--border-light); border-radius: 10px; overflow: hidden;
}
.adm-perm-header {
  display: grid; grid-template-columns: 1fr 60px 60px 64px 44px;
  background: var(--bg); padding: 6px 12px; font-size: 11px; font-weight: 600;
  color: var(--text-muted); text-transform: uppercase; letter-spacing: .3px;
  border-bottom: 1px solid var(--border-light);
}
.adm-perm-row {
  display: grid; grid-template-columns: 1fr 60px 60px 64px 44px;
  padding: 5px 12px; align-items: center; border-bottom: 1px solid var(--border-light);
  transition: background .15s;
}
.adm-perm-row:last-of-type { border-bottom: none; }
.adm-perm-row:hover { background: var(--bg); }
.adm-perm-module-col { font-size: 13px; color: var(--text); }
.adm-perm-level-col { text-align: center; }
.adm-perm-radio { display: inline-flex; cursor: pointer; }
.adm-perm-radio input { display: none; }
.adm-perm-dot {
  width: 18px; height: 18px; border-radius: 50%; border: 2px solid var(--border);
  transition: all .15s; position: relative;
}
.adm-perm-radio input:checked + .adm-perm-dot { border-color: var(--bk-orange); }
.adm-perm-radio input:checked + .adm-perm-full { background: #4CAF50; border-color: #4CAF50; }
.adm-perm-radio input:checked + .adm-perm-edit { background: var(--bk-orange); border-color: var(--bk-orange); }
.adm-perm-radio input:checked + .adm-perm-view { background: #2196F3; border-color: #2196F3; }
.adm-perm-radio input:checked + .adm-perm-none { background: #9E9E9E; border-color: #9E9E9E; }
.adm-perm-reset { margin: 8px 12px; font-size: 11px; }

/* ═══ Audit Log ═══ */
.adm-audit-filters { margin-bottom: 16px; }
.adm-audit-filter-row {
  display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
}
.adm-audit-chips { display: flex; gap: 4px; flex-wrap: wrap; }
.adm-audit-chip {
  padding: 5px 14px; border-radius: 20px; font-size: 13px; font-weight: 600;
  font-family: inherit; cursor: pointer; transition: all .15s;
  border: 1.5px solid var(--border); background: var(--card); color: var(--text-muted);
}
.adm-audit-chip:hover { border-color: var(--bk-orange); color: var(--text); }
.adm-audit-chip.active { border-color: var(--bk-orange); background: #FFFBF5; color: var(--bk-brown); }

.adm-audit-right-filters { display: flex; gap: 6px; align-items: center; }
.adm-audit-select, .adm-audit-date {
  padding: 5px 10px; border: 1.5px solid var(--border); border-radius: 8px;
  font-size: 12px; font-family: inherit; background: var(--card); color: var(--text);
}
.adm-audit-select:focus, .adm-audit-date:focus { border-color: var(--bk-orange); outline: none; }
.adm-audit-date { width: 120px; }

.adm-audit-list { display: flex; flex-direction: column; gap: 2px; }
.adm-audit-entry {
  padding: 10px 14px; border-radius: 10px;
  background: var(--card); border: 1.5px solid transparent;
  transition: all .15s;
}
.adm-audit-entry:hover { border-color: var(--border-light); }

.adm-audit-head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.adm-audit-badge {
  display: inline-block; padding: 1px 8px; border-radius: 10px;
  font-size: 10px; font-weight: 700;
}
.adm-audit-b-created { background: #E8F5E9; color: #2E7D32; }
.adm-audit-b-updated { background: #FFF3E0; color: #E65100; }
.adm-audit-b-deleted { background: #FFEBEE; color: #C62828; }
.adm-audit-b-received { background: #E0F2F1; color: #00695C; }
.adm-audit-b-reverted { background: #FFF3E0; color: #BF360C; }
.adm-audit-b-delivery { background: #E3F2FD; color: #1565C0; }
.adm-audit-b-schedule { background: #E8EAF6; color: #283593; }

.adm-audit-entity-badge {
  display: inline-block; padding: 1px 7px; border-radius: 4px;
  font-size: 10px; font-weight: 600; background: var(--bg); color: var(--text-muted);
}
.adm-audit-et-order { background: #FFF8E1; color: #E65100; }
.adm-audit-et-plan { background: #E8F5E9; color: #2E7D32; }
.adm-audit-et-product { background: #E3F2FD; color: #1565C0; }
.adm-audit-et-delivery_schedule { background: #E8EAF6; color: #283593; }

.adm-audit-author { font-weight: 600; font-size: 12px; color: var(--text); }
.adm-audit-date-text { font-size: 11px; color: var(--text-muted); margin-left: auto; white-space: nowrap; }

.adm-audit-ctx { font-size: 12px; color: var(--text-secondary); margin-top: 3px; }
.adm-audit-meta { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

.adm-audit-params { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 5px; }
.adm-audit-param-chip {
  display: inline-block; padding: 1px 7px; border-radius: 4px;
  font-size: 11px; background: #EDE7F6; color: #4A148C; font-weight: 500;
}

.adm-audit-delivery {
  display: inline-flex; margin-top: 5px; padding: 2px 8px; border-radius: 4px;
  font-size: 11px; font-weight: 600; background: #E3F2FD; color: #1565C0;
}
.adm-audit-received { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 5px; font-size: 11px; }
.adm-audit-disc { padding: 1px 7px; border-radius: 4px; background: #FFF8E1; color: #E65100; font-weight: 600; }
.adm-audit-no-disc { padding: 1px 7px; border-radius: 4px; background: #E8F5E9; color: #2E7D32; font-weight: 500; }

.adm-audit-changes { display: flex; flex-wrap: wrap; gap: 3px; margin-top: 5px; }
.adm-audit-ch {
  display: inline-block; padding: 1px 6px; border-radius: 4px;
  font-size: 10px; font-weight: 600; line-height: 1.5;
}
.adm-audit-ch-add { background: #E8F5E9; color: #2E7D32; }
.adm-audit-ch-del { background: #FFEBEE; color: #C62828; }
.adm-audit-ch-upd { background: #FFF8E1; color: #5D4037; }
.adm-audit-more {
  padding: 1px 8px; border-radius: 4px; font-size: 10px; font-weight: 600;
  background: var(--bg); color: var(--text-muted); border: 1px solid var(--border-light);
  cursor: pointer; font-family: inherit;
}
.adm-audit-more:hover { border-color: var(--bk-orange); color: var(--text); }

.adm-audit-sched-row { display: flex; gap: 3px; margin-top: 6px; flex-wrap: wrap; }
.adm-audit-sched-cell {
  display: flex; flex-direction: column; align-items: center;
  min-width: 48px; padding: 3px 4px; border-radius: 4px;
  background: #F5F5F5; border: 1px solid #E0E0E0;
}
.adm-audit-sched-cell.has { background: #E8F5E9; border-color: #A5D6A7; }
.adm-audit-sched-day { font-size: 9px; font-weight: 700; color: #888; }
.adm-audit-sched-cell.has .adm-audit-sched-day { color: #2E7D32; }
.adm-audit-sched-time { font-size: 10px; font-weight: 700; color: #BDBDBD; }
.adm-audit-sched-cell.has .adm-audit-sched-time { color: #1B5E20; }

@media (max-width: 600px) {
  .adm-audit-filter-row { flex-direction: column; align-items: stretch; }
  .adm-audit-right-filters { flex-wrap: wrap; }
  .adm-audit-date { width: 100%; flex: 1; }
  .adm-audit-select { width: 100%; }
}

/* ═══ Stats Cards ═══ */
.adm-stats-period { display: flex; gap: 4px; }
.adm-stats-cards {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 10px; margin-bottom: 16px;
}
.adm-stat-card {
  padding: 16px; border-radius: 12px; text-align: center;
  background: var(--card); border: 1.5px solid var(--border-light);
}
.adm-stat-value { font-size: 28px; font-weight: 800; color: var(--bk-brown); line-height: 1.2; }
.adm-stat-label { font-size: 12px; color: var(--text-muted); margin-top: 4px; font-weight: 500; }

.adm-stats-blocks { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 700px) { .adm-stats-blocks { grid-template-columns: 1fr; } }

.adm-stats-bars { display: flex; flex-direction: column; gap: 8px; }
.adm-stats-bar-row { display: flex; align-items: center; gap: 8px; }
.adm-stats-bar-label { font-size: 12px; color: var(--text); width: 160px; flex-shrink: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.adm-stats-bar-track { flex: 1; height: 20px; background: var(--bg); border-radius: 10px; overflow: hidden; }
.adm-stats-bar-fill { height: 100%; background: linear-gradient(90deg, var(--bk-orange), #E8941A); border-radius: 10px; transition: width .3s; min-width: 4px; }
.adm-stats-bar-val { font-size: 13px; font-weight: 700; color: var(--text); width: 36px; text-align: right; }
.adm-stats-empty { text-align: center; padding: 24px; color: var(--text-muted); font-size: 13px; }

.adm-stats-top-list { display: flex; flex-direction: column; gap: 4px; }
.adm-stats-top-row {
  display: flex; align-items: center; gap: 10px;
  padding: 6px 10px; border-radius: 8px; font-size: 13px;
}
.adm-stats-top-row:hover { background: var(--bg); }
.adm-stats-top-num { width: 22px; height: 22px; border-radius: 50%; background: var(--border-light); text-align: center; line-height: 22px; font-size: 11px; font-weight: 700; color: var(--text-muted); flex-shrink: 0; }
.adm-stats-top-name { flex: 1; font-weight: 600; color: var(--text); }
.adm-stats-top-cnt { font-size: 12px; color: var(--text-muted); flex-shrink: 0; }

/* ═══ System Settings ═══ */
.adm-settings-list { display: flex; flex-direction: column; gap: 6px; }
.adm-setting-row {
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
  padding: 6px 0; border-bottom: 1px solid var(--border-light);
}
.adm-setting-row:last-child { border-bottom: none; }
.adm-setting-key { font-size: 13px; font-weight: 600; color: var(--text); min-width: 180px; }
.adm-setting-input-wrap { display: flex; gap: 6px; flex: 1; align-items: center; }
.adm-setting-input {
  flex: 1; padding: 6px 10px; border: 1.5px solid var(--border); border-radius: 6px;
  font-size: 13px; font-family: inherit; background: var(--bg); min-width: 0;
}
.adm-setting-input:focus { border-color: var(--bk-orange); outline: none; }
.adm-setting-save-btn { font-size: 12px !important; padding: 5px 12px !important; flex-shrink: 0; }

/* ═══ Backup ═══ */
.adm-backup-tables {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 6px; margin-top: 8px;
}

/* ═══ Audit Mode Toggle ═══ */
.adm-audit-mode {
  display: flex; gap: 0; margin-bottom: 14px;
  background: var(--bg); border-radius: 10px; padding: 3px;
  border: 1.5px solid var(--border-light); width: fit-content;
}
.adm-audit-mode-btn {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 6px 16px; border-radius: 8px; font-size: 13px; font-weight: 600;
  font-family: inherit; cursor: pointer; transition: all .15s;
  border: none; background: none; color: var(--text-muted);
}
.adm-audit-mode-btn.active {
  background: var(--card); color: var(--bk-brown);
  box-shadow: 0 1px 3px rgba(0,0,0,.08);
}
.adm-audit-mode-btn:hover:not(.active) { color: var(--text); }

/* ═══ Error Logs ═══ */
.adm-error-entry { cursor: pointer; }
.adm-error-entry:hover { border-color: var(--border); }
.adm-error-message { font-size: 13px; color: var(--text); margin-top: 4px; word-break: break-word; }
.adm-error-url { font-size: 11px; color: var(--text-muted); margin-top: 2px; word-break: break-all; }
.adm-error-stack {
  margin-top: 6px; padding: 8px 10px; border-radius: 6px;
  background: #F5F5F5; font-size: 11px; font-family: monospace;
  white-space: pre-wrap; word-break: break-all; color: #333;
  max-height: 200px; overflow-y: auto;
}

/* ═══ Changelog ═══ */
.adm-changelog-version {
  display: inline-block; padding: 1px 8px; border-radius: 10px;
  font-size: 11px; font-weight: 700;
  background: #E8F5E9; color: #2E7D32;
}
.adm-changelog-title { font-size: 14px; font-weight: 600; color: var(--text); }
.adm-changelog-desc {
  font-size: 13px; color: var(--text-secondary); margin-top: 4px;
  line-height: 1.5; white-space: pre-line;
}
.adm-changelog-meta {
  display: flex; align-items: center; justify-content: space-between;
  margin-top: 6px;
}

/* ═══ Permissions Matrix ═══ */
.perm-matrix { border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
.perm-row { display: grid; grid-template-columns: 1fr 90px 90px 120px; align-items: center; padding: 6px 12px; border-bottom: 1px solid var(--border); font-size: 12px; }
.perm-row:last-child { border-bottom: none; }
.perm-header { background: var(--card); font-weight: 700; font-size: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
.perm-module { font-weight: 600; }
.perm-level { text-align: center; font-size: 11px; }
.perm-base { color: var(--text-muted); }
.perm-lvl-full { color: #D32F2F; font-weight: 600; }
.perm-lvl-edit { color: #F57C00; font-weight: 600; }
.perm-lvl-view { color: #1976D2; }
.perm-lvl-none { color: var(--text-muted); opacity: 0.5; }
.perm-select { padding: 3px 6px; border: 1px solid var(--border); border-radius: 6px; font-size: 11px; font-family: inherit; background: var(--card); }

/* ═══ Bug Reports ═══ */
.bug-filter-select {
  padding: 5px 10px;
  border: 1px solid var(--border);
  border-radius: 8px;
  font-size: 12px;
  font-family: inherit;
  background: var(--card);
}
.adm-bug-row { cursor: pointer; }
.adm-bug-row:hover { background: rgba(231,111,81,0.02); }
.adm-bug-status-col { flex-shrink: 0; width: 90px; }
.adm-bug-status {
  font-size: 10px;
  font-weight: 600;
  padding: 3px 8px;
  border-radius: 8px;
  display: inline-block;
  white-space: nowrap;
}
.adm-bug-status.st-new { background: #FFF3E0; color: #E65100; }
.adm-bug-status.st-in_progress { background: #E3F2FD; color: #1565C0; }
.adm-bug-status.st-resolved { background: #E8F5E9; color: #2E7D32; }
.adm-bug-status.st-closed { background: #F5F5F5; color: #757575; }
.adm-bug-thumbs {
  display: flex;
  gap: 4px;
  align-items: center;
  flex-shrink: 0;
}
.adm-bug-thumb {
  width: 36px;
  height: 36px;
  object-fit: cover;
  border-radius: 6px;
  border: 1px solid var(--border);
}
.adm-bug-more {
  font-size: 10px;
  color: var(--text-muted);
  font-weight: 600;
}
.bug-reply-input {
  width: 100%;
  padding: 8px 12px;
  border: 1.5px solid var(--border);
  border-radius: 8px;
  font-size: 13px;
  font-family: inherit;
  resize: vertical;
  min-height: 40px;
}
.bug-reply-input:focus {
  outline: none;
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(231,111,81,0.08);
}

/* ═══ Feedback messenger ═══ */
.fb-messenger {
  display: flex;
  gap: 0;
  height: calc(100vh - 160px);
  min-height: 400px;
  border: 1px solid var(--border);
  border-radius: 12px;
  overflow: hidden;
  background: var(--card);
}
.fb-sidebar {
  width: 320px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  border-right: 1px solid var(--border-light);
  background: var(--bg);
}
.fb-sidebar-top {
  display: flex;
  gap: 6px;
  padding: 10px 12px;
  border-bottom: 1px solid var(--border-light);
  flex-shrink: 0;
}
.fb-list {
  flex: 1;
  overflow-y: auto;
}
.fb-item {
  padding: 10px 14px;
  cursor: pointer;
  border-bottom: 1px solid var(--border-light);
  transition: background 0.1s;
}
.fb-item:hover { background: rgba(0,0,0,0.03); }
.fb-item.active { background: var(--card); border-left: 3px solid var(--accent); }
.fb-item.is-new { border-left: 3px solid #E65100; }
.fb-item.active.is-new { border-left-color: var(--accent); }
.fb-item-top {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 3px;
}
.fb-item-status {
  width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
}
.fb-item-status.st-new { background: #E65100; }
.fb-item-status.st-in_progress { background: #1565C0; }
.fb-item-status.st-resolved { background: #2E7D32; }
.fb-item-status.st-closed { background: #9E9E9E; }
.fb-item-author { font-size: 11px; font-weight: 600; color: var(--text-secondary); }
.fb-item-date { font-size: 10px; color: var(--text-muted); margin-left: auto; }
.fb-item-title {
  font-size: 13px; font-weight: 600; color: var(--text);
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.fb-item-bottom {
  display: flex; gap: 8px; margin-top: 2px;
  font-size: 10px; color: var(--text-muted);
}

/* Chat panel */
.fb-chat {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.fb-chat-empty {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  color: var(--text-muted);
  font-size: 13px;
}
.fb-chat-header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  border-bottom: 1px solid var(--border-light);
  flex-shrink: 0;
}
.fb-chat-title {
  font-size: 14px; font-weight: 600;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.fb-del-btn {
  background: none; border: none; cursor: pointer; color: var(--text-muted);
  padding: 4px; border-radius: 6px; transition: 0.15s;
}
.fb-del-btn:hover { color: var(--error); background: rgba(211,47,47,0.08); }
.fb-chat-info {
  padding: 0 16px;
  border-bottom: 1px solid var(--border-light);
  font-size: 12px;
  color: var(--text-muted);
  flex-shrink: 0;
}
.fb-chat-info summary { padding: 8px 0; cursor: pointer; font-weight: 600; }
.fb-chat-info-body { padding: 0 0 10px; }
.fb-chat-messages {
  flex: 1;
  overflow-y: auto;
  padding: 12px 16px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.fb-msg {
  padding: 8px 12px;
  border-radius: 12px;
  background: var(--bg);
  max-width: 80%;
  align-self: flex-start;
}
.fb-msg.admin {
  background: #e8f5e9;
  align-self: flex-end;
}
.fb-msg-meta {
  display: flex; justify-content: space-between; gap: 8px;
  margin-bottom: 2px; font-size: 10px; font-weight: 600;
  color: var(--text-secondary);
}
.fb-msg-meta span:last-child { color: var(--text-muted); font-weight: 400; }
.fb-msg-text { font-size: 13px; white-space: pre-wrap; line-height: 1.45; }
.fb-msg-img { max-width: 200px; border-radius: 8px; margin-top: 4px; cursor: pointer; }
.fb-chat-input {
  display: flex; gap: 8px;
  padding: 10px 16px;
  border-top: 1px solid var(--border-light);
  flex-shrink: 0;
  align-items: flex-end;
}
.fb-chat-input textarea { flex: 1; resize: none; min-height: 36px; max-height: 100px; }

@media (max-width: 768px) {
  .fb-messenger { flex-direction: column; height: auto; min-height: unset; }
  .fb-sidebar { width: 100%; max-height: 300px; border-right: none; border-bottom: 1px solid var(--border-light); }
  .fb-chat { min-height: 400px; }
}
</style>
