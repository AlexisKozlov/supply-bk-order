<template>
  <div :class="supplierId ? '' : 'rom-page'">
    <!-- Toolbar — показываем только если не embedded (нет пропа supplierId) -->
    <div v-if="!supplierId" class="rom-toolbar">
      <h1>Заявки поставщикам</h1>
    </div>

    <!-- Page tabs -->
    <div class="rom-page-tabs">
      <button class="rom-page-tab" :class="{ active: pageTab === 'overview' }" @click="pageTab = 'overview'; loadOverview()">
        Обзор
      </button>
      <button class="rom-page-tab" :class="{ active: pageTab === 'status' }" @click="pageTab = 'status'; loadStatus()">
        Приём
      </button>
      <button class="rom-page-tab" :class="{ active: pageTab === 'list' }" @click="pageTab = 'list'; loadOrdersList()">
        Список заявок
      </button>
      <button class="rom-page-tab" :class="{ active: pageTab === 'schedules' }" @click="pageTab = 'schedules'; loadSchedules()">
        Графики
      </button>
      <button class="rom-page-tab" :class="{ active: pageTab === 'templates' }" @click="pageTab = 'templates'; loadTemplates()">
        Шаблон товаров
      </button>
      <button class="rom-page-tab" :class="{ active: pageTab === 'settings' }" @click="pageTab = 'settings'; loadSettings()">
        Настройки
      </button>
    </div>

    <!-- Supplier selector — только если supplierId не передан через проп -->
    <div v-if="!supplierId && pageTab !== 'overview'" class="rom-date-row">
      <label>Поставщик:</label>
      <select v-model="currentSupplierId" @change="onSupplierChange" class="rom-select">
        <option value="">— выберите —</option>
        <option v-for="s in allSuppliers" :key="s.id" :value="s.id">
          {{ s.short_name }} ({{ s.restaurant_count }} рест.)
        </option>
      </select>
    </div>

    <!-- ═══ TAB: Обзор ═══ -->
    <template v-if="pageTab === 'overview'">
      <div class="rom-date-row">
        <label>Дата доставки:</label>
        <input type="date" v-model="overviewDate" @change="loadOverview" class="rom-input-sm" style="width:160px" />
        <button class="rom-btn-sm" @click="loadOverview">Обновить</button>
      </div>

      <div v-if="overviewLoading" class="rom-loading"><BurgerSpinner text="Загрузка..." /></div>
      <div v-else class="rom-table-wrap">
        <table class="rom-table so-ov-table">
          <thead>
            <tr>
              <th>Поставщик</th>
              <th style="width:200px">Дедлайн</th>
              <th style="width:130px">Подано</th>
              <th style="width:330px">Действия</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!overviewRows.length">
              <td colspan="4" class="so-ov-empty">Нет поставщиков</td>
            </tr>
            <tr v-for="row in overviewRows" :key="row.id">
              <td>
                <button class="so-ov-supplier" @click="openSupplierStatus(row)">
                  {{ row.short_name || row.name }}
                </button>
                <span v-if="!row.is_accepting" class="so-ov-paused">на паузе</span>
              </td>
              <td>
                <template v-if="row.forced_closed">
                  <span class="rom-status st-locked">День закрыт</span>
                </template>
                <template v-else>
                  <!-- Дата остаётся нейтральной: красным подсвечиваем только статус
                       под ней, иначе в одной ячейке два одинаковых сигнала. -->
                  <span :class="{ 'so-ov-date-passed': overviewIsPassed(row) }">{{ row.deadline_str || '—' }}</span>
                  <span v-if="row.deadline_at" class="so-ov-countdown" :class="{ 'so-ov-bad': overviewIsPassed(row) }">
                    {{ overviewCountdown(row) }}
                  </span>
                </template>
              </td>
              <td>
                <span v-if="row.has_schedule" :class="overviewSubmittedClass(row)">
                  {{ row.submitted_count }} из {{ row.expected_count }}
                </span>
                <span v-else class="so-ov-nodelivery">— нет поставки</span>
              </td>
              <td>
                <div class="so-ov-actions">
                  <button class="rom-btn-sm" @click="overviewSendEmail(row)"
                    :disabled="!row.has_email || isOverviewBusy(row)"
                    :title="!row.has_email ? 'У поставщика не указана почта' : 'Отправить сводку на почту поставщика'">Почта</button>
                  <button class="rom-btn-sm" @click="overviewSendTelegram(row)"
                    :disabled="isOverviewBusy(row)" title="Отправить сводку в Telegram">Telegram</button>
                  <button class="rom-btn-sm" @click="overviewExtend(row)"
                    :disabled="isOverviewBusy(row)" title="Продлить дедлайн">Дедлайн</button>
                  <button class="rom-btn-sm" @click="overviewRemind(row)"
                    :disabled="isOverviewBusy(row) || !(row.has_schedule && row.submitted_count < row.expected_count && !row.forced_closed && !overviewIsPassed(row))"
                    title="Напомнить не подавшим заявку">Напомнить</button>
                  <button class="rom-btn-sm" :class="row.forced_closed ? 'so-btn-open-day' : 'so-btn-close-day'"
                    @click="overviewToggleClose(row)" :disabled="isOverviewBusy(row)"
                    :title="row.forced_closed ? 'Открыть день для подачи заявок' : 'Закрыть день — рестораны не смогут подавать заявки'">
                    {{ row.forced_closed ? 'Открыть' : 'Закрыть' }}</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- ═══ TAB: Приём ═══ -->
    <template v-if="pageTab === 'status' && currentSupplierId">
      <!-- Bar: дедлайн по умолчанию + ссылка (приём/пауза/авто-* переехали в «Настройки») -->
      <div class="so-detail-bar">
        <span class="so-session-status" :class="settings.is_accepting_orders ? 'st-sess-active' : 'st-sess-closed'"
          :title="'Управление приёмом — во вкладке «Настройки»'">
          {{ settings.is_accepting_orders ? 'Приём включён' : 'Приём приостановлен' }}
        </span>
        <div class="so-detail-actions">
          <label class="so-inline-label">Дедлайн по умолчанию:</label>
          <input type="time" v-model="defaultDeadline" class="rom-input-sm" style="width:100px" />
          <button class="rom-btn-sm" @click="saveDefaultDeadline" :disabled="!defaultDeadline">Сохранить</button>
          <button class="rom-btn rom-btn-outline" @click="copyLink">Ссылка</button>
        </div>
      </div>

      <!-- Date nav -->
      <div class="rom-date-row">
        <label>Дата поставки:</label>
        <div class="so-date-nav">
          <button v-for="wd in weekDates" :key="wd.date"
            class="rom-btn-sm"
            :class="{ 'so-date-active': selectedDate === wd.date, 'so-day-closed-btn': isDateForcedClosed(wd.date) }"
            @click="selectedDate = wd.date; loadStatus()"
            :title="isDateForcedClosed(wd.date) ? 'День закрыт' : ''">
            {{ wd.day_name }} {{ formatDateShort(wd.date) }}
          </button>
        </div>
        <input type="date" v-model="selectedDate" @change="loadStatus" style="margin-left:8px" />
        <button v-if="selectedDate" class="rom-btn-sm" @click="handleExtendDeadline" title="Разовое продление дедлайна на эту дату">
          Продлить дедлайн
        </button>
        <button v-if="selectedDate" class="rom-btn-sm" :class="isDateForcedClosed(selectedDate) ? 'so-btn-open-day' : 'so-btn-close-day'"
          @click="handleToggleCloseDay(selectedDate)" :title="isDateForcedClosed(selectedDate) ? 'Открыть день для подачи заявок' : 'Закрыть день — рестораны не смогут подавать заявки'">
          {{ isDateForcedClosed(selectedDate) ? 'Открыть день' : 'Закрыть день' }}
        </button>
      </div>

      <!-- Существующие переопределения дедлайна -->
      <div v-if="deadlineOverrides.length" class="rom-date-row" style="flex-wrap:wrap;gap:6px;">
        <span class="so-inline-label">Разовые продления:</span>
        <span v-for="o in deadlineOverrides.filter(o => !o.is_closed)" :key="o.delivery_date" class="so-override-chip">
          {{ formatDateShort(o.delivery_date) }} — до {{ o.deadline_time?.substring(0,5) }}
          <button class="so-override-del" @click="removeOverride(o.delivery_date)" title="Удалить">×</button>
        </span>
      </div>
      <div v-if="deadlineOverrides.some(o => o.is_closed)" class="rom-date-row" style="flex-wrap:wrap;gap:6px;">
        <span class="so-inline-label">Закрытые дни:</span>
        <span v-for="o in deadlineOverrides.filter(o => o.is_closed)" :key="'cl-'+o.delivery_date" class="so-override-chip so-override-chip-closed">
          {{ formatDateShort(o.delivery_date) }}
          <button class="so-override-del" @click="handleToggleCloseDay(o.delivery_date)" title="Открыть день">×</button>
        </span>
      </div>

        <div v-if="loading" class="rom-loading"><BurgerSpinner text="Загрузка..." /></div>
        <template v-else>
          <!-- Stats -->
          <div class="rom-stats">
            <div class="rom-stat">
              <span class="rom-stat-value">{{ stats.submitted }}</span>
              <span class="rom-stat-label">подано</span>
            </div>
            <div class="rom-stat">
              <span class="rom-stat-value rom-stat-pending">{{ stats.pending }}</span>
              <span class="rom-stat-label">не подано</span>
            </div>
            <div class="rom-stat">
              <span class="rom-stat-value">{{ stats.total }}</span>
              <span class="rom-stat-label">всего</span>
            </div>
          </div>

          <!-- Export + controls -->
          <div class="rom-export-row">
            <button class="rom-btn rom-btn-export" @click="exportExcel" :disabled="exporting || exportSelectedDates.size === 0">
              {{ exporting ? 'Выгрузка...' : exportSelectedDates.size > 1 ? `Выгрузить ${exportSelectedDates.size} ${dayWord(exportSelectedDates.size)} в Excel` : 'Выгрузить в Excel' }}
            </button>
            <button class="rom-btn"
              @click="exportDatePickerOpen = !exportDatePickerOpen" title="Выбрать дни для выгрузки">
              {{ exportDatePickerOpen ? 'Дни ▲' : 'Дни ▼' }}
            </button>
            <button class="rom-btn rom-btn-primary"
              @click="sendSummary" :disabled="sendingSummary || !selectedDate" title="Сгенерировать Excel и отправить подписчикам в Telegram">
              <BurgerSpinner v-if="sendingSummary" size="xs" />
              <span>{{ sendingSummary ? 'Отправка...' : 'Отправить сводку' }}</span>
            </button>
            <button class="rom-btn" @click="sendSummaryEmail" :disabled="sendingSummaryEmail || !selectedDate"
              title="Сгенерировать Excel и отправить на почту поставщика">
              {{ sendingSummaryEmail ? 'Отправка…' : 'На почту поставщику' }}
            </button>
            <button class="rom-btn" @click="loadStatus" :disabled="loading">Обновить</button>
            <button class="rom-btn" @click="copyMissingRestaurants" :disabled="!selectedDate" title="Скопировать номера ресторанов, которые не подали заявку на эту дату">
              Копировать не подавших
            </button>
            <button class="rom-btn" @click="remindUnsubmitted" :disabled="!selectedDate || remindingStatus" title="Напомнить ресторанам, которые не подали заявку на эту дату">
              {{ remindingStatus ? 'Отправка…' : 'Напомнить не подавшим' }}
            </button>
            <label class="so-filter-check">
              <input type="checkbox" v-model="showMissing" /> Не подавшие
            </label>
            <input v-model="filterText" type="text" class="rom-input-sm so-filter-input" placeholder="Поиск..." />
          </div>
          <div v-if="exportDatePickerOpen" class="so-export-date-picker">
            <span class="so-export-date-hint">Выберите дни для выгрузки:</span>
            <label v-for="wd in weekDates" :key="wd.date" class="so-export-date-check">
              <input type="checkbox" :checked="exportSelectedDates.has(wd.date)" @change="toggleExportDate(wd.date)" />
              {{ wd.day_name }} {{ formatDateShort(wd.date) }}
            </label>
            <button class="rom-btn-sm" @click="exportSelectAll">Все</button>
            <button class="rom-btn-sm" @click="exportSelectNone">Ни одного</button>
          </div>

          <!-- Pivot table: restaurants × products -->
          <div class="rom-table-wrap" v-if="displayProducts.length">
            <table class="rom-table so-pivot-table">
              <thead>
                <tr>
                  <th class="so-th-rest">Ресторан</th>
                  <th class="so-th-status">Статус</th>
                  <th v-for="p in displayProducts" :key="p.display_key" class="so-th-qty">
                    <div class="so-th-prod">{{ p.is_grouped ? `SKU ×${p.source_skus.length}` : p.sku }}</div>
                    <div class="so-th-prod">{{ p.product_name }}</div>
                    <div v-if="p.multiplicity" class="so-th-mult">×{{ p.multiplicity }}</div>
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in filteredRestaurants" :key="r.number" :class="{ 'rom-row-submitted': r.order_status, 'so-row-skip': isSkipOrder(r) }">
                  <td class="so-td-rest">
                    <span class="rom-td-num">{{ formatRestaurantNumber(r.number, r.legal_entity_group) }}</span>
                    <span class="so-rest-addr">{{ r.city || r.region }}{{ r.address ? ', ' + r.address : '' }}</span>
                  </td>
                  <td>
                    <span v-if="isSkipOrder(r)" class="rom-status st-skip" title="Ресторан отметил, что поставка не нужна">
                      Не нужна
                    </span>
                    <span v-else class="rom-status" :class="'st-' + (r.order_status || 'none')">
                      {{ statusLabel(r.order_status) }}
                    </span>
                    <span v-if="isAutoSubmitted(r)" class="so-auto-badge" :title="autoSubmitTitle(r)">
                      АВТО-ПОДАЧА
                    </span>
                  </td>
                  <td v-for="p in displayProducts" :key="p.display_key"
                    class="so-td-qty"
                    :class="{ 'so-td-skip-cell': isSkipOrder(r) }"
                    :title="p.is_grouped ? `Объединено из SKU: ${p.source_skus.join(', ')}` : ''"
                    @dblclick="canEditProduct(p) && startEdit(r.number, p.sku)">
                    <template v-if="editCell === `${r.number}_${p.sku}`">
                      <input
                        v-model="editValue"
                        type="text" inputmode="decimal"
                        class="so-cell-input"
                        @keydown.enter="saveEdit"
                        @keydown.escape="editCell = ''"
                        @blur="saveEdit"
                        ref="editInputRef"
                      />
                    </template>
                    <template v-else-if="isSkipOrder(r)">
                      <span class="so-qty-zero" title="Поставка не нужна">0</span>
                    </template>
                    <template v-else>
                      <span v-if="getCellAdmin(r.number, p) !== null" class="so-qty-admin" :title="'Исходное: ' + getCellQty(r.number, p)">
                        {{ getCellAdmin(r.number, p) }}
                      </span>
                      <span v-else-if="getCellQty(r.number, p) !== ''" class="so-qty">
                        {{ getCellQty(r.number, p) }}
                      </span>
                      <span v-else class="so-qty-empty">—</span>
                    </template>
                  </td>
                </tr>
              </tbody>
              <tfoot v-if="filteredRestaurants.length">
                <tr class="so-totals-row">
                  <td class="so-td-rest"><strong>Итого</strong></td>
                  <td></td>
                  <td v-for="p in displayProducts" :key="p.display_key" class="so-td-qty so-td-total">
                    <strong>{{ getProductTotal(p) || '' }}</strong>
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
          <div v-else class="rom-empty">Нет товаров в шаблоне. Добавьте товары во вкладке «Шаблон товаров».</div>
        </template>
    </template>

    <!-- ═══ TAB: Список заявок ═══ -->
    <template v-if="pageTab === 'list' && currentSupplierId">
      <div class="rom-date-row">
        <label>Подано:</label>
        <input type="date" v-model="listSubmittedFrom" />
        <span>—</span>
        <input type="date" v-model="listSubmittedTo" />
        <button class="rom-btn-sm" @click="loadOrdersList">Загрузить</button>
      </div>
      <div class="rom-date-row" style="flex-wrap:wrap;gap:8px;align-items:flex-end">
        <div>
          <label class="so-field-label">Доставка от</label>
          <input type="date" v-model="listDeliveryFrom" />
        </div>
        <div>
          <label class="so-field-label">Доставка до</label>
          <input type="date" v-model="listDeliveryTo" />
        </div>
        <div>
          <label class="so-field-label">Статус</label>
          <select v-model="listStatus" class="rom-select">
            <option value="">Все</option>
            <option value="submitted">Подано</option>
            <option value="locked">Закрыто</option>
            <option value="draft">Черновик</option>
          </select>
        </div>
        <div style="min-width:240px">
          <label class="so-field-label">Ресторан / адрес</label>
          <input type="text" v-model="listQuery" class="rom-input-sm" placeholder="Номер, город, адрес" style="min-width:240px" />
        </div>
        <label class="so-filter-check" style="margin-bottom:6px">
          <input type="checkbox" v-model="listSkipOnly" /> Только "не нужна"
        </label>
        <button class="rom-btn-sm" @click="resetOrdersFilters">Сбросить</button>
      </div>
      <div v-if="loadingList" class="rom-loading"><BurgerSpinner text="Загрузка..." /></div>
      <div v-else-if="ordersList.length === 0" class="rom-empty">Заявок за выбранный период нет.</div>
      <div v-else class="rom-table-wrap">
        <table class="rom-table so-list-table">
          <thead>
            <tr>
              <th>Рест.</th>
              <th>Адрес</th>
              <th>Подано</th>
              <th>Дата доставки</th>
              <th>Статус</th>
              <th>Позиций</th>
              <th>Кол-во</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="o in ordersList" :key="o.id">
              <td class="rom-td-num">{{ formatRestaurantNumber(o.restaurant_number, o.legal_entity_group) }}</td>
              <td>{{ o.address }}</td>
              <td>{{ o.submitted_at ? formatDateTime(o.submitted_at) : '—' }}</td>
              <td>{{ formatDate(o.delivery_date) }}</td>
              <td>
                <span v-if="Number(o.item_count || 0) === 0 && (o.status === 'submitted' || o.status === 'locked')" class="rom-status st-skip">Не нужна</span>
                <span v-else class="rom-status" :class="'st-' + o.status">{{ statusLabel(o.status) }}</span>
                <span v-if="isAutoSubmitted(o)" class="so-auto-badge" :title="autoSubmitTitle(o)">
                  АВТО-ПОДАЧА
                </span>
              </td>
              <td>{{ o.item_count || '—' }}</td>
              <td>{{ o.total_qty ? (Number.isInteger(+o.total_qty) ? +o.total_qty : (+o.total_qty).toFixed(2)) : '—' }}</td>
              <td class="rom-td-actions">
                <button class="rom-btn-sm" @click="viewOrder(o.id)">Открыть</button>
                <button class="rom-btn-sm rom-btn-danger" @click="deleteOrder(o.id, o.status)">Удалить</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- ═══ TAB: Графики ═══ -->
    <template v-if="pageTab === 'schedules' && currentSupplierId">
      <div v-if="loadingSchedules" class="rom-loading"><BurgerSpinner text="Загрузка..." /></div>
      <div v-else>
        <!-- Дедлайны по дням недели -->
        <div class="so-deadline-section">
          <h3 class="so-section-title">Дедлайны по дням доставки</h3>
          <p class="so-section-hint">Для каждого дня доставки укажите день и время дедлайна подачи заявки</p>
          <div class="so-deadline-grid">
            <div v-for="dow in [1,2,3,4,5,6,7]" :key="dow" class="so-deadline-row">
              <div class="so-deadline-label">
                <span class="so-dl-day">{{ dayNamesFull[dow] }}</span>
                <span class="so-dl-hint">доставка</span>
              </div>
              <div class="so-deadline-arrow">→</div>
              <select v-model="deadlineRulesMap[dow].deadline_dow" class="rom-input-sm">
                <option v-for="d in [1,2,3,4,5,6,7]" :key="d" :value="d">{{ daysShort[d] }}</option>
              </select>
              <input type="time" v-model="deadlineRulesMap[dow].deadline_time" class="rom-input-sm" />
              <button v-if="!deadlineRulesMap[dow].active" class="so-dl-toggle so-dl-off" @click="deadlineRulesMap[dow].active = true" title="Включить">выкл</button>
              <button v-else class="so-dl-toggle so-dl-on" @click="deadlineRulesMap[dow].active = false" title="Выключить">вкл</button>
            </div>
          </div>
          <button class="rom-btn rom-btn-export" @click="saveDeadlineRules" :disabled="savingDeadlines" style="margin-top:10px">
            <BurgerSpinner v-if="savingDeadlines" size="xs" />
            <span>{{ savingDeadlines ? 'Сохранение...' : 'Сохранить дедлайны' }}</span>
          </button>
        </div>

        <!-- Графики по ресторанам -->
        <h3 class="so-section-title" style="margin-top:20px">Дни доставки по ресторанам</h3>
        <p class="so-section-hint">Отметьте дни недели, когда ресторан получает поставку.</p>
        <div v-if="scheduleGridLoading" class="rom-loading"><BurgerSpinner text="Загрузка..." /></div>
        <template v-else-if="scheduleRestaurants.length">
          <div class="so-sched-filter">
            <input v-model="scheduleFilter" type="text" class="rom-input-sm" placeholder="Поиск ресторана..." style="min-width:200px" />
            <button class="rom-btn rom-btn-export" @click="saveScheduleGrid" :disabled="savingScheduleGrid">
              <BurgerSpinner v-if="savingScheduleGrid" size="xs" />
              <span>{{ savingScheduleGrid ? 'Сохранение...' : 'Сохранить' }}</span>
            </button>
            <span class="so-schedule-count" style="margin:0">{{ scheduleActiveRests }} рест., {{ scheduleActiveDays }} дней</span>
          </div>
          <div class="rom-table-wrap so-grid-wrap">
            <table class="rom-table so-grid-table">
              <thead>
                <tr>
                  <th class="so-grid-rest">Ресторан</th>
                  <th v-for="d in 7" :key="d" class="so-grid-day">{{ daysShort[d] }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in filteredScheduleRestaurants" :key="r.id">
                  <td class="so-grid-rest-cell">
                    <span class="so-grid-num">{{ formatRestaurantNumber(r.number, r.legal_entity_group) }}</span>
                    <span class="so-grid-addr">{{ r.city }}{{ r.address ? ', ' + r.address : '' }}</span>
                  </td>
                  <td v-for="d in 7" :key="d" class="so-grid-check" @click="toggleScheduleDay(r, d)">
                    <input type="checkbox" :checked="!!scheduleGrid[r.id]?.[d]" @click.stop="toggleScheduleDay(r, d)" />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
        <div v-else>
          <button class="rom-btn" @click="loadRestaurantsForSchedule">Загрузить рестораны</button>
        </div>

        <div class="so-deadline-section" style="margin-top:20px">
          <div class="so-notify-head">
            <div>
              <h3 class="so-section-title" style="margin:0">Временный график</h3>
              <p class="so-section-hint" style="margin:4px 0 0 0">На выбранный период этот график полностью заменяет основной. После окончания периода система сама вернётся к обычному графику.</p>
            </div>
            <div class="so-temp-actions">
              <button class="rom-btn-sm" @click="copyMainScheduleToTemporary">Скопировать из основного</button>
              <button class="rom-btn-sm" @click="clearTemporarySchedule">Очистить</button>
              <button class="rom-btn rom-btn-export" @click="saveTemporarySchedule" :disabled="savingTemporarySchedule">
                <BurgerSpinner v-if="savingTemporarySchedule" size="xs" />
                <span>{{ savingTemporarySchedule ? 'Сохранение...' : 'Сохранить временный график' }}</span>
              </button>
            </div>
          </div>
          <div class="so-temp-period">
            <label>
              <span>С даты</span>
              <input v-model="temporaryDateFrom" type="date" class="rom-input-sm" />
            </label>
            <label>
              <span>По дату</span>
              <input v-model="temporaryDateTo" type="date" class="rom-input-sm" />
            </label>
            <span class="so-schedule-count" style="margin:0">{{ temporaryScheduleActiveRests }} рест., {{ temporaryScheduleActiveDays }} дней</span>
          </div>
          <div v-if="scheduleRestaurants.length" class="rom-table-wrap so-grid-wrap" style="margin-top:12px">
            <table class="rom-table so-grid-table">
              <thead>
                <tr>
                  <th class="so-grid-rest">Ресторан</th>
                  <th v-for="d in 7" :key="'tmp-'+d" class="so-grid-day">{{ daysShort[d] }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in filteredScheduleRestaurants" :key="'tmp-rest-' + r.id">
                  <td class="so-grid-rest-cell">
                    <span class="so-grid-num">{{ formatRestaurantNumber(r.number, r.legal_entity_group) }}</span>
                    <span class="so-grid-addr">{{ r.city }}{{ r.address ? ', ' + r.address : '' }}</span>
                  </td>
                  <td v-for="d in 7" :key="'tmp-cell-' + r.id + '-' + d" class="so-grid-check" @click="toggleTemporaryScheduleDay(r, d)">
                    <input type="checkbox" :checked="!!temporaryScheduleGrid[r.id]?.[d]" @click.stop="toggleTemporaryScheduleDay(r, d)" />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </template>

    <!-- ═══ TAB: Шаблон товаров ═══ -->
    <template v-if="pageTab === 'templates' && currentSupplierId">
      <div class="rom-date-row">
        <label>Юрлицо:</label>
        <select v-model="templateLe" @change="loadTemplates" class="rom-select">
          <option v-for="e in templateEntities" :key="e" :value="e">{{ ENTITY_SHORT_NAMES[e] || e }}</option>
        </select>
        <div class="so-template-search">
          <input
            v-model="templateProductSearch"
            class="rom-input"
            type="text"
            placeholder="Найти товар в справочнике"
            @input="searchTemplateProducts"
          />
          <div v-if="templateProductResults.length && linkingRowIdx === null" class="so-template-dropdown">
            <button
              v-for="p in templateProductResults"
              :key="p.id || p.sku"
              type="button"
              class="so-template-option"
              @click="addTemplateProduct(p)"
            >
              <b>{{ p.sku }}</b>
              <span>{{ p.name || p.product_name }}</span>
            </button>
          </div>
        </div>
        <button class="rom-btn-sm" @click="addManualTemplateRow">+ Строка вручную</button>
        <button class="rom-btn-sm" @click="importFromProducts">Импорт из справочника</button>
        <button class="rom-btn-sm rom-btn-primary" @click="saveTemplates" :disabled="savingTemplates">
          <BurgerSpinner v-if="savingTemplates" size="xs" />
          <span>{{ savingTemplates ? 'Сохранение...' : 'Сохранить' }}</span>
        </button>
      </div>
      <div v-if="loadingTemplates" class="rom-loading"><BurgerSpinner text="Загрузка..." /></div>
      <div v-else>
        <div class="rom-table-wrap">
          <table class="rom-table so-tpl-table">
            <thead>
              <tr>
                <th style="width:50px">Порядок</th>
                <th>Товар</th>
                <th style="width:220px">Каталог</th>
                <th style="width:80px">Кратность</th>
                <th style="width:80px">Мин. кол-во</th>
                <th style="width:40px"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(t, idx) in templates" :key="idx">
                <td><input type="number" v-model.number="t.sort_order" class="rom-input-sm" style="width:50px" /></td>
                <td>
                  <div class="so-template-product-cell">
                    <input v-model="t.sku" class="rom-input-sm so-template-sku-input" placeholder="SKU" />
                    <input v-model="t.product_name" class="rom-input-sm so-template-name-input" placeholder="Название товара" />
                  </div>
                </td>
                <td class="so-tpl-cat">
                  <!-- Статус связи с карточкой каталога -->
                  <div v-if="linkingRowIdx === idx" class="so-tpl-link-search">
                    <input
                      v-model="templateProductSearch"
                      class="rom-input-sm"
                      type="text"
                      placeholder="Найти карточку"
                      @input="searchTemplateProducts"
                    />
                    <button type="button" class="rom-btn-sm" @click="cancelLinkRow">Отмена</button>
                    <div v-if="templateProductResults.length" class="so-template-dropdown">
                      <button
                        v-for="p in templateProductResults"
                        :key="p.id || p.sku"
                        type="button"
                        class="so-template-option"
                        @click="linkTemplateRow(idx, p)"
                      >
                        <b>{{ p.sku }}</b>
                        <span>{{ p.name || p.product_name }}</span>
                      </button>
                    </div>
                  </div>
                  <div v-else-if="t.linked" class="so-tpl-linked" :title="catalogHint(t)">
                    <span class="so-tpl-linked-mark" aria-hidden="true">•</span>
                    <span class="so-tpl-linked-text">{{ t.catalog_name || 'привязан' }}<template v-if="catalogAttrs(t)"> · {{ catalogAttrs(t) }}</template></span>
                  </div>
                  <div v-else class="so-tpl-unlinked">
                    <span class="so-tpl-unlinked-mark">нет карточки</span>
                    <button type="button" class="rom-btn-sm" @click="startLinkRow(idx)">Привязать</button>
                  </div>
                </td>
                <td><input type="number" v-model.number="t.multiplicity" class="rom-input-sm" style="width:70px" min="0" step="0.01" placeholder="—" /></td>
                <td><input type="number" v-model.number="t.min_qty" class="rom-input-sm" style="width:70px" min="0" step="0.01" placeholder="—" /></td>
                <td><button class="rom-btn-sm rom-btn-danger" @click="templates.splice(idx, 1)">✕</button></td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="so-schedule-count">Товаров: {{ templates.length }}</p>
      </div>
    </template>

    <!-- ═══ TAB: Настройки ═══ -->
    <template v-if="pageTab === 'settings' && currentSupplierId">
      <div class="so-settings-wrap">
        <!-- Приём заявок -->
        <div class="so-settings-block">
          <div class="so-section-title" style="margin:0">Приём заявок</div>
          <p class="so-section-hint" style="margin:4px 0 10px 0">Пока приём приостановлен, рестораны видят сообщение и не могут подать заявку.</p>
          <div class="so-detail-bar">
            <!-- Возобновление — обычное действие: заливку акцентом на странице
                 держат только «Подключить поставщика» и «Отправить сводку». -->
            <button class="rom-btn-sm" @click="toggleAccepting" :class="settings.is_accepting_orders ? 'rom-btn-danger' : ''">
              {{ settings.is_accepting_orders ? 'Приостановить приём' : 'Возобновить приём' }}
            </button>
            <span class="so-session-status" :class="settings.is_accepting_orders ? 'st-sess-active' : 'st-sess-closed'">
              {{ settings.is_accepting_orders ? 'Приём включён' : 'Приём приостановлен' }}
            </span>
          </div>
          <div v-if="!settings.is_accepting_orders" class="rom-date-row so-paused-note">
            <label>Сообщение для ресторанов:</label>
            <input type="text" v-model="pauseMessage" @change="savePauseMessage" class="rom-input-sm" style="flex:1;min-width:250px" placeholder="Приём заявок временно приостановлен" />
          </div>
        </div>

        <!-- Автоматизация -->
        <div class="so-settings-block">
          <div class="so-section-title" style="margin:0">Автоматизация по дедлайну</div>
          <label class="so-settings-check" title="Если ресторан не подал заявку до дедлайна — автоматически подать предыдущую заявку этого ресторана">
            <input type="checkbox" :checked="!!settings.auto_submit_previous" @change="toggleAutoSubmit" />
            <span>Авто-подача предыдущей заявки по дедлайну</span>
          </label>
          <label class="so-settings-check" title="Если включено — после дедлайна система сама отправит сводку заявок на почту поставщика">
            <input type="checkbox" :checked="!!settings.auto_email_summary" @change="toggleAutoEmail" />
            <span>Авто-письмо со сводкой поставщику в дедлайн</span>
          </label>
          <label class="so-settings-check" title="Рестораны, которые в этот день реально что-то заказали, получат видимую копию письма поставщику">
            <input type="checkbox" :checked="!!settings.email_cc_restaurants" @change="toggleCcRestaurants" />
            <span>Ставить рестораны с заявками в копию письма</span>
          </label>
        </div>

        <!-- Почта поставщика (справочно) -->
        <div class="so-settings-block">
          <div class="so-section-title" style="margin:0">Почта поставщика</div>
          <p v-if="currentSupplier?.email" class="so-section-hint" style="margin:4px 0 0 0">
            {{ currentSupplier.email }} <span class="so-notify-muted">(редактируется в карточке поставщика)</span>
          </p>
          <p v-else class="so-section-hint" style="margin:4px 0 0 0">Адрес почты задаётся в карточке поставщика.</p>
        </div>

        <!-- Получатели итоговой сводки -->
        <div class="so-settings-block">
          <div class="so-notify-head">
            <div>
              <div class="so-section-title" style="margin:0">Получатели итоговой сводки</div>
              <div class="so-section-hint" style="margin:4px 0 0 0">После дедлайна бот отправит результат только отмеченным сотрудникам этого поставщика.</div>
            </div>
            <button class="rom-btn-sm" @click="saveNotifyUsers" :disabled="savingNotifyUsers || loadingNotifyUsers">
              <BurgerSpinner v-if="savingNotifyUsers" size="xs" />
              <span>{{ savingNotifyUsers ? 'Сохранение...' : 'Сохранить' }}</span>
            </button>
          </div>
          <div v-if="loadingNotifyUsers" class="rom-loading" style="padding:8px 0"><BurgerSpinner size="sm" text="Загрузка пользователей..." /></div>
          <div v-else class="so-notify-users">
            <label v-for="u in allNotifyUsers" :key="u.name" class="so-notify-user">
              <input type="checkbox" :value="u.name" v-model="notifyUsers" />
              <span class="so-notify-user-text">
                <span class="so-notify-user-name">{{ u.name }}</span>
                <small v-if="u.display_role">{{ u.display_role }}</small>
                <small v-if="!u.telegram_chat_id" class="so-notify-muted">нет Telegram</small>
              </span>
            </label>
          </div>
        </div>

        <!-- Напоминания о подаче заявок -->
        <div class="so-settings-block">
          <div class="so-notify-head">
            <div>
              <div class="so-section-title" style="margin:0">Напоминания о подаче заявок</div>
              <div class="so-section-hint" style="margin:4px 0 0 0">Бот напомнит ресторанам, не подавшим заявку, в выбранные моменты до дедлайна.</div>
            </div>
            <button class="rom-btn-sm" @click="saveReminders" :disabled="savingReminders">
              <BurgerSpinner v-if="savingReminders" size="xs" />
              <span>{{ savingReminders ? 'Сохранение...' : 'Сохранить' }}</span>
            </button>
          </div>

          <div class="so-reminder-group">
            <div class="so-reminder-title">Когда напоминать</div>
            <div class="so-reminder-checks">
              <label class="so-settings-check"><input type="checkbox" value="evening" v-model="reminderOffsets" /><span>Вечернее (накануне)</span></label>
              <label class="so-settings-check"><input type="checkbox" value="3h" v-model="reminderOffsets" /><span>За 3 часа</span></label>
              <label class="so-settings-check"><input type="checkbox" value="2h" v-model="reminderOffsets" /><span>За 2 часа</span></label>
              <label class="so-settings-check"><input type="checkbox" value="1h" v-model="reminderOffsets" /><span>За 1 час</span></label>
              <label class="so-settings-check"><input type="checkbox" value="30m" v-model="reminderOffsets" /><span>За 30 минут</span></label>
              <label class="so-settings-check"><input type="checkbox" value="expired" v-model="reminderOffsets" /><span>Когда дедлайн истёк</span></label>
            </div>
            <p class="so-section-hint" style="margin:6px 0 0 0">Если ничего не выбрано — напоминания не отправляются.</p>
          </div>

          <div class="so-reminder-group">
            <div class="so-reminder-title">Куда отправлять</div>
            <div class="so-reminder-checks">
              <label class="so-settings-check"><input type="checkbox" value="tg" v-model="reminderChannels" /><span>Telegram</span></label>
              <label class="so-settings-check"><input type="checkbox" value="push" v-model="reminderChannels" /><span>Пуш</span></label>
            </div>
            <p class="so-section-hint" style="margin:6px 0 0 0">Если ни один канал не выбран — напоминания не отправляются.</p>
          </div>
        </div>

        <!-- Недельный режим подачи -->
        <div class="so-settings-block">
          <div class="so-notify-head">
            <div>
              <div class="so-section-title" style="margin:0">Недельный режим подачи</div>
              <div class="so-section-hint" style="margin:4px 0 0 0">В недельном режиме дедлайны по дням не применяются: вся неделя доставки (пн–вс) закрывается в выбранный день предыдущей недели в указанное время. Ресторан видит всю открытую неделю сразу.</div>
            </div>
            <button class="rom-btn-sm" @click="saveWeekly" :disabled="savingWeekly">
              <BurgerSpinner v-if="savingWeekly" size="xs" />
              <span>{{ savingWeekly ? 'Сохранение...' : 'Сохранить' }}</span>
            </button>
          </div>

          <label class="so-settings-check" style="margin-top:6px">
            <input type="checkbox" v-model="weeklyEnabled" />
            <span>Включить недельный режим подачи</span>
          </label>

          <div v-if="weeklyEnabled" class="rom-date-row" style="margin-top:10px;flex-wrap:wrap;gap:12px">
            <label style="display:flex;align-items:center;gap:6px">
              День закрытия недели:
              <select v-model.number="weeklyDow" class="rom-input-sm">
                <option v-for="d in weekdayOptions" :key="d.value" :value="d.value">{{ d.label }}</option>
              </select>
            </label>
            <label style="display:flex;align-items:center;gap:6px">
              Время:
              <input type="time" v-model="weeklyTime" class="rom-input-sm" />
            </label>
          </div>
        </div>

        <!-- Минимальный заказ -->
        <div class="so-settings-block">
          <div class="so-notify-head">
            <div>
              <div class="so-section-title" style="margin:0">Минимальный заказ</div>
              <div class="so-section-hint" style="margin:4px 0 0 0">Если задан — заявку меньше минимума нельзя отправить (жёсткий блок). Значение 0 или пусто = минимума нет.</div>
            </div>
            <button class="rom-btn-sm" @click="saveMinOrder" :disabled="savingMinOrder">
              <BurgerSpinner v-if="savingMinOrder" size="xs" />
              <span>{{ savingMinOrder ? 'Сохранение...' : 'Сохранить' }}</span>
            </button>
          </div>

          <div class="rom-date-row" style="margin-top:10px;flex-wrap:wrap;gap:12px">
            <label style="display:flex;align-items:center;gap:6px">
              Минимум:
              <input type="number" v-model.number="minOrderValue" class="rom-input-sm" style="width:110px" min="0" step="0.01" placeholder="нет" />
            </label>
            <label style="display:flex;align-items:center;gap:6px">
              Единица:
              <select v-model="minOrderUnit" class="rom-input-sm">
                <option value="kg">килограммы</option>
                <option value="pieces">штуки</option>
              </select>
            </label>
          </div>
          <p v-if="minOrderUnit === 'kg'" class="so-section-hint" style="margin:8px 0 0 0">
            Единица «килограммы» работает по весам из справочника: у товаров поставщика должны быть заполнены вес и штук-в-коробке.
          </p>
        </div>

        <!-- Отчёт Excel -->
        <div class="so-settings-block">
          <div class="so-notify-head">
            <div>
              <div class="so-section-title" style="margin:0">Отчёт Excel</div>
              <div class="so-section-hint" style="margin:4px 0 0 0">Как выглядит файл заявки, который скачивается и уходит поставщику письмом.</div>
            </div>
            <button class="rom-btn-sm" @click="saveXlsx" :disabled="savingXlsx">
              <BurgerSpinner v-if="savingXlsx" size="xs" />
              <span>{{ savingXlsx ? 'Сохранение...' : 'Сохранить' }}</span>
            </button>
          </div>

          <label class="so-settings-check" style="margin-top:6px">
            <input type="checkbox" v-model="xlsxDropEmpty" />
            <span>Убрать пустые строки</span>
          </label>
          <p class="so-section-hint" style="margin:6px 0 0 0">Рестораны без заказа не попадут в отчёт.</p>

          <div class="so-reminder-group">
            <div class="so-reminder-title">Показатели паллет и веса</div>
            <div class="so-reminder-checks">
              <label class="so-settings-check"><input type="checkbox" value="boxes" v-model="xlsxPalletMetrics" /><span>Коробки</span></label>
              <label class="so-settings-check"><input type="checkbox" value="pallets" v-model="xlsxPalletMetrics" /><span>Паллеты</span></label>
              <label class="so-settings-check"><input type="checkbox" value="netto" v-model="xlsxPalletMetrics" /><span>Вес нетто</span></label>
              <label class="so-settings-check"><input type="checkbox" value="brutto" v-model="xlsxPalletMetrics" /><span>Вес брутто</span></label>
            </div>
            <p class="so-section-hint" style="margin:6px 0 0 0">
              Выбранные показатели выводятся столбцами у каждого ресторана и сводкой по товарам внизу отчёта.
              Порядок столбцов всегда одинаковый (коробки, паллеты, нетто, брутто) — от порядка выбора не зависит.
              Если ничего не выбрано — паллеты и вес не показываются.
            </p>
            <p class="so-section-hint" style="margin:6px 0 0 0">
              Считается по весам из справочника: у товаров должны быть заполнены вес и штук-в-коробке.
            </p>
          </div>
        </div>
      </div>
    </template>

    <!-- ═══ Modal: Order detail ═══ -->
    <div v-if="showOrderModal" class="rom-modal-overlay" @click.self="showOrderModal = false">
      <div class="rom-modal">
        <div class="rom-modal-header">
          <h3>Заявка #{{ viewedOrder?.id }} — Рест. {{ formatRestaurantNumber(viewedOrder?.restaurant_number, viewedOrder?.legal_entity_group) }}</h3>
          <button class="rom-modal-close" @click="showOrderModal = false">✕</button>
        </div>
        <div class="rom-modal-body" v-if="viewedOrder">
          <dl class="so-modal-facts">
            <dt>Поставщик</dt><dd>{{ viewedOrder.supplier_name }}</dd>
            <dt>Доставка</dt><dd>{{ formatDate(viewedOrder.delivery_date) }}</dd>
            <dt>Подано</dt><dd>{{ viewedOrder.submitted_at ? formatTime(viewedOrder.submitted_at) : '—' }}</dd>
          </dl>
          <p v-if="isAutoSubmitted(viewedOrder)" class="so-auto-detail">
            АВТО-ПОДАЧА: скопировано из заявки #{{ viewedOrder.auto_source_order_id }}<template v-if="viewedOrder.auto_source_delivery_date"> от {{ formatDate(viewedOrder.auto_source_delivery_date) }}</template>
          </p>
          <table class="rom-table so-modal-table">
            <thead><tr><th>Товар</th><th>Кол-во</th></tr></thead>
            <tbody>
              <tr v-for="item in viewedOrder.items" :key="item.id">
                <td><span class="so-tpl-sku">{{ item.sku }}</span> {{ item.product_name }}</td>
                <td>
                  <span v-if="item.admin_qty !== null && item.admin_qty !== undefined" class="so-qty-admin" :title="'Исходное: ' + item.quantity">{{ item.admin_qty }}</span>
                  <span v-else>{{ item.quantity }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div v-if="!currentSupplierId" class="rom-empty" style="margin-top: 40px">
      Выберите поставщика для просмотра заявок
    </div>

    <ConfirmModal
      v-if="confirmModal.show"
      :title="confirmModal.title"
      :message="confirmModal.message"
      :ok-text="confirmModal.okText"
      :cancel-text="confirmModal.cancelText"
      :danger="confirmModal.danger"
      @confirm="onConfirm"
      @cancel="onCancel"
    />
  </div>
</template>

<script setup>
import { ref, reactive, computed, defineAsyncComponent, watch, onMounted, onUnmounted, nextTick } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useSupplierOrderStore } from '@/stores/supplierOrderStore.js';
import { appPrompt } from '@/lib/appDialogs.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { db } from '@/lib/apiClient.js';
import { formatRestaurantNumber, LEGAL_ENTITIES, ENTITY_SHORT_NAMES } from '@/lib/legalEntities.js';
import { toLocalDateStr } from '@/lib/utils.js';
import { buildSoOrderSheet } from '@/lib/soOrderXlsx.js';
import { useToastStore } from '@/stores/toastStore.js';
import { useConfirm } from '@/composables/useConfirm.js';

const ConfirmModal = defineAsyncComponent(() => import('@/components/modals/ConfirmModal.vue'));

const route = useRoute();
const router = useRouter();
const { confirmModal, confirm: showConfirm, onConfirm, onCancel } = useConfirm();

const props = defineProps({
  supplierId: { type: String, default: '' },
});

const store = useSupplierOrderStore();
const orderStore = useOrderStore();
const toast = useToastStore();

const dayNames = { 1: 'ПН', 2: 'ВТ', 3: 'СР', 4: 'ЧТ', 5: 'ПТ', 6: 'СБ', 7: 'ВС' };
const dayNamesFull = { 1: 'Понедельник', 2: 'Вторник', 3: 'Среда', 4: 'Четверг', 5: 'Пятница', 6: 'Суббота', 7: 'Воскресенье' };
const daysShort = { 1: 'Пн', 2: 'Вт', 3: 'Ср', 4: 'Чт', 5: 'Пт', 6: 'Сб', 7: 'Вс' };

// Стартовая вкладка: «Обзор» по умолчанию, но при входе по прямой ссылке
// на конкретного поставщика (props.supplierId) — сразу «Приём».
const pageTab = ref(props.supplierId ? 'status' : 'overview');
const loading = ref(false);
const allSuppliers = ref([]);
const currentSupplierId = ref(props.supplierId || '');
const selectedDate = ref('');
const selectedDeadline = ref('');
const stats = ref({ total: 0, submitted: 0, pending: 0 });
const restaurants = ref([]);
const weekDates = ref([]);

// Обзор по всем поставщикам
const overviewRows = ref([]);
const overviewLoading = ref(false);
const overviewDate = ref(toLocalDateStr(new Date()));
// Per-row «в процессе»: занятость по id поставщика, чтобы крутилка/дизейбл были только у нажатой строки
const overviewBusy = ref(new Set());
function isOverviewBusy(row) { return overviewBusy.value.has(row.id); }
// Тикающее «сейчас» для живого отсчёта до дедлайна (обновляется раз в минуту)
const now = ref(Date.now());
let overviewTimer = null;

// Settings (постоянный режим приёма)
const settings = ref({ is_accepting_orders: 1, auto_submit_previous: 0, auto_email_summary: 0, email_cc_restaurants: 0, default_deadline_time: '14:00:00', pause_message: null });
// Для какого поставщика реально загружены настройки (id). null — настройки не свои/не загружены.
const settingsLoadedFor = ref(null);
const defaultDeadline = ref('14:00');
const pauseMessage = ref('');
const deadlineOverrides = ref([]);
const allNotifyUsers = ref([]);
const loadingNotifyUsers = ref(false);
const savingNotifyUsers = ref(false);
const notifyUsers = ref([]);

// Напоминания о подаче заявок (массивы выбранных таймингов и каналов)
const reminderOffsets = ref([]);
const reminderChannels = ref([]);
const savingReminders = ref(false);

// Недельный режим подачи (вкл/выкл, день недели 1..7, время HH:MM)
const weeklyEnabled = ref(false);
const weeklyDow = ref(3);
const weeklyTime = ref('14:00');
const savingWeekly = ref(false);

// Минимальный заказ у поставщика (значение и единица кг/штуки)
const minOrderValue = ref(0);
const minOrderUnit = ref('kg');
const savingMinOrder = ref(false);

// Опции Excel-отчёта поставщика: убирать пустые строки и какие показатели
// паллет/веса выводить (boxes / pallets / netto / brutto).
const xlsxDropEmpty = ref(false);
const xlsxPalletMetrics = ref([]);
const savingXlsx = ref(false);

const weekdayOptions = [
  { value: 1, label: 'Понедельник' },
  { value: 2, label: 'Вторник' },
  { value: 3, label: 'Среда' },
  { value: 4, label: 'Четверг' },
  { value: 5, label: 'Пятница' },
  { value: 6, label: 'Суббота' },
  { value: 7, label: 'Воскресенье' },
];

// List tab
const loadingList = ref(false);
const ordersList = ref([]);
const listSubmittedFrom = ref(todayStr(-7));
const listSubmittedTo = ref(todayStr(0));
const listDeliveryFrom = ref('');
const listDeliveryTo = ref('');
const listStatus = ref('');
const listQuery = ref('');
const listSkipOnly = ref(false);

// Schedules
const loadingSchedules = ref(false);
const schedules = ref([]);
const temporarySchedule = ref(null);
const deadlineRulesMap = reactive({});
const savingDeadlines = ref(false);
// Инициализируем пустые правила для всех дней
for (let d = 1; d <= 7; d++) {
  deadlineRulesMap[d] = { deadline_dow: d > 1 ? d - 1 : 7, deadline_time: '14:00', active: false };
}

// Templates
const loadingTemplates = ref(false);
const savingTemplates = ref(false);
const templates = ref([]);
const templateLe = ref(orderStore.settings.legalEntity || 'ООО "Бургер БК"');
const templateProductSearch = ref('');
const templateProductResults = ref([]);
// Индекс строки шаблона в режиме привязки к карточке каталога (null — режим добавления новой строки)
const linkingRowIdx = ref(null);
let templateSearchTimer = null;

// Группа юрлиц текущего поставщика (BK_VM | PS). Определяется из списка
// поставщиков: он уже отфильтрован backend'ом по группе юрлица сайдбара.
const currentSupplierGroup = computed(() => {
  const sup = allSuppliers.value.find(s => String(s.id) === String(currentSupplierId.value));
  if (sup?.legal_entity_group) return sup.legal_entity_group;
  // Fallback: берём группу из сайдбара, т.к. список поставщиков уже сужен
  return orderStore.settings.legalEntity?.includes('Пицца Стар') ? 'PS' : 'BK_VM';
});

// Юрлица, доступные в переключателе шаблонов: только те, что входят в
// группу поставщика. Для BK_VM — БК+ВМ, для PS — только Пицца Стар.
const templateEntities = computed(() => {
  const group = currentSupplierGroup.value;
  if (group === 'PS') return LEGAL_ENTITIES.filter(e => e.includes('Пицца Стар'));
  return LEGAL_ENTITIES.filter(e => !e.includes('Пицца Стар'));
});
const currentSupplier = computed(() => allSuppliers.value.find(s => String(s.id) === String(currentSupplierId.value)) || null);

// Order modal
const showOrderModal = ref(false);
const viewedOrder = ref(null);
const exporting = ref(false);
const sendingSummary = ref(false);
const sendingSummaryEmail = ref(false);
const remindingStatus = ref(false);

// Multi-date export
const exportDatePickerOpen = ref(false);
const exportSelectedDates = ref(new Set());

// «2 дня» / «5 дней» — иначе на кнопке выгрузки получалось «15 дня».
function dayWord(n) {
  const mod100 = n % 100;
  if (mod100 >= 11 && mod100 <= 14) return 'дней';
  const mod10 = n % 10;
  if (mod10 === 1) return 'день';
  if (mod10 >= 2 && mod10 <= 4) return 'дня';
  return 'дней';
}

// Опции формирования Excel (скачивание и отправка) живут в настройках поставщика —
// см. settings.xlsx_drop_empty / settings.xlsx_pallet_metrics.

// Когда weekDates подгружаются — инициализируем все даты как выбранные
watch(weekDates, (dates) => {
  exportSelectedDates.value = new Set(dates.map(d => d.date));
}, { deep: true });

function toggleExportDate(date) {
  const s = new Set(exportSelectedDates.value);
  if (s.has(date)) s.delete(date);
  else s.add(date);
  exportSelectedDates.value = s;
}
function exportSelectAll() { exportSelectedDates.value = new Set(weekDates.value.map(d => d.date)); }
function exportSelectNone() { exportSelectedDates.value = new Set(); }

// Pivot table data
const products = ref([]);
const orderItems = ref([]);
const filterText = ref('');
const showMissing = ref(true);
const editCell = ref('');
const editValue = ref('');
const editInputRef = ref(null);

function normalizeProductName(name) {
  return String(name || '').trim().toLowerCase();
}

function buildDisplayProducts(list) {
  const groups = new Map();
  for (const product of list || []) {
    const groupKey = normalizeProductName(product.product_name) || String(product.sku || '').trim();
    if (!groups.has(groupKey)) groups.set(groupKey, []);
    groups.get(groupKey).push(product);
  }

  const result = [];
  for (const group of groups.values()) {
    const first = group[0] || {};
    if (group.length === 1) {
      result.push({
        ...first,
        display_key: first.sku,
        source_skus: [first.sku],
        is_grouped: false,
      });
      continue;
    }

    const multiplicities = [...new Set(group.map(p => p.multiplicity).filter(v => v !== null && v !== undefined && v !== ''))];

    // Атрибуты упаковки берём у группы только если они совпадают у всех
    // аналогов. Количества в объединённой строке уже сложены, разложить их
    // обратно по SKU нельзя — поэтому при разной упаковке честнее не
    // показывать паллеты и вес совсем, чем посчитать их по первому SKU
    // и разойтись с файлом, который считает сервер (он считает по каждому SKU).
    const sameAcross = (field) => {
      const vals = [...new Set(group.map(p => p[field]).filter(v => v !== null && v !== undefined && v !== ''))];
      return vals.length === 1 ? vals[0] : null;
    };

    result.push({
      ...first,
      display_key: `group:${normalizeProductName(first.product_name)}`,
      source_skus: group.map(p => p.sku).filter(Boolean),
      is_grouped: true,
      multiplicity: multiplicities.length === 1 ? multiplicities[0] : null,
      qty_per_box: sameAcross('qty_per_box'),
      boxes_per_pallet: sameAcross('boxes_per_pallet'),
      weight_netto: sameAcross('weight_netto'),
      weight_brutto: sameAcross('weight_brutto'),
      product_id: null,
    });
  }

  return result;
}

function formatQtyValue(value) {
  if (!Number.isFinite(value)) return '';
  return value === Math.floor(value) ? Math.floor(value) : +value.toFixed(2);
}

function todayStr(offsetDays = 0) {
  const d = new Date();
  d.setDate(d.getDate() + offsetDays);
  return d.toISOString().slice(0, 10);
}

// Если supplierId пришёл как проп — сразу загружаем данные
watch(() => props.supplierId, (val) => {
  if (val) {
    currentSupplierId.value = val;
    refreshActiveTab();
  }
}, { immediate: true });

onMounted(async () => {
  // Живой отсчёт до дедлайнов в «Обзоре» — тикаем раз в минуту
  overviewTimer = setInterval(() => { now.value = Date.now(); }, 60000);
  try {
    allSuppliers.value = await store.adminGetSuppliers(orderStore.settings.legalEntity);
    if (!props.supplierId && allSuppliers.value.length === 1) {
      currentSupplierId.value = allSuppliers.value[0].id;
      await refreshActiveTab();
    }
  } catch (e) {
    console.error(e);
  }
  // Если стартовая вкладка — «Обзор», грузим её данные
  if (pageTab.value === 'overview') {
    await loadOverview();
  }
});

onUnmounted(() => {
  if (overviewTimer) {
    clearInterval(overviewTimer);
    overviewTimer = null;
  }
});

// При смене юрлица — сбрасываем выбранного поставщика и перезагружаем
watch(() => orderStore.settings.legalEntity, async () => {
  if (props.supplierId) return; // если передан явно — не трогаем
  currentSupplierId.value = '';
  templates.value = [];
  try {
    allSuppliers.value = await store.adminGetSuppliers(orderStore.settings.legalEntity);
    if (allSuppliers.value.length === 1) {
      currentSupplierId.value = allSuppliers.value[0].id;
      await refreshActiveTab();
    }
    // «Обзор» не завязан на выбранного поставщика — перезагружаем его
    // отдельно, чтобы после смены юрлица таблица показала новых поставщиков.
    if (pageTab.value === 'overview') {
      await loadOverview();
    }
  } catch (e) {
    console.error(e);
  }
});

async function onSupplierChange() {
  if (!currentSupplierId.value) return;
  // Подгоняем выбор юрлица в шаблонах под группу поставщика — чтобы
  // при переключении между БК/ПС поставщиками не осталось чужого юрлица.
  if (!templateEntities.value.includes(templateLe.value)) {
    templateLe.value = templateEntities.value[0] || templateLe.value;
  }
  await refreshActiveTab();
}

async function refreshActiveTab() {
  if (!currentSupplierId.value) return;
  await loadSettings();
  if (pageTab.value === 'schedules') {
    await loadSchedules();
    return;
  }
  if (pageTab.value === 'templates') {
    await loadTemplates();
    return;
  }
  if (pageTab.value === 'list') {
    await loadOrdersList();
    return;
  }
  if (pageTab.value === 'settings') {
    // loadSettings уже вызван выше; получателей подтягивает он же
    return;
  }
  await loadStatus();
}

async function loadSettings() {
  if (!currentSupplierId.value) return;
  // Запоминаем, за чьими настройками пошли — поставщик мог смениться, пока шёл запрос.
  const sid = currentSupplierId.value;
  try {
    const data = await store.adminGetSettings(sid);
    settings.value = data.settings || { is_accepting_orders: 1, auto_submit_previous: 0, auto_email_summary: 0, email_cc_restaurants: 0, default_deadline_time: '14:00:00', pause_message: null, xlsx_drop_empty: 0, xlsx_pallet_metrics: [] };
    // Отмечаем владельца настроек только если сервер вернул настоящие настройки.
    // Дефолт-заглушка и ошибка запроса владельца не дают.
    settingsLoadedFor.value = data.settings ? sid : null;
    defaultDeadline.value = (settings.value.default_deadline_time || '14:00:00').substring(0, 5);
    pauseMessage.value = settings.value.pause_message || '';
    deadlineOverrides.value = data.overrides || [];
    notifyUsers.value = Array.isArray(data.notify_users) ? data.notify_users : [];
    reminderOffsets.value = Array.isArray(settings.value.reminder_offsets) ? [...settings.value.reminder_offsets] : [];
    reminderChannels.value = Array.isArray(settings.value.reminder_channels) ? [...settings.value.reminder_channels] : [];
    syncWeeklyFromSettings();
    syncMinOrderFromSettings();
    syncXlsxFromSettings();
    if (!allNotifyUsers.value.length) await loadNotifyUsers();
  } catch (e) {
    // Запрос упал — в settings могли остаться настройки прошлого поставщика.
    // Снимаем метку владельца, чтобы экспорт не собрал файл с чужими опциями.
    settingsLoadedFor.value = null;
    console.error(e);
  }
}

async function loadNotifyUsers() {
  loadingNotifyUsers.value = true;
  try {
    const { data } = await db.rpc('get_users_list_short');
    allNotifyUsers.value = data || [];
  } catch (e) {
    console.error(e);
  } finally {
    loadingNotifyUsers.value = false;
  }
}

function currentSettingsPayload(overrides = {}) {
  return {
    is_accepting_orders: settings.value.is_accepting_orders,
    auto_submit_previous: settings.value.auto_submit_previous ? 1 : 0,
    auto_email_summary: settings.value.auto_email_summary ? 1 : 0,
    email_cc_restaurants: settings.value.email_cc_restaurants ? 1 : 0,
    default_deadline_time: defaultDeadline.value + ':00',
    pause_message: pauseMessage.value || null,
    ...overrides,
  };
}

async function toggleAccepting() {
  const next = settings.value.is_accepting_orders ? 0 : 1;
  if (next === 0) {
    const ok = await showConfirm('Приостановить приём заявок?', 'Рестораны увидят сообщение о паузе.');
    if (!ok) return;
  }
  try {
    await store.adminSaveSettings(currentSupplierId.value, currentSettingsPayload({ is_accepting_orders: next }));
    await loadSettings();
  } catch (e) {
    toast.error('Ошибка', e.message);
  }
}

async function toggleAutoSubmit(ev) {
  const next = ev.target.checked ? 1 : 0;
  try {
    await store.adminSaveSettings(currentSupplierId.value, currentSettingsPayload({ auto_submit_previous: next }));
    await loadSettings();
  } catch (e) {
    toast.error('Ошибка', e.message);
  }
}

async function toggleAutoEmail(ev) {
  const next = ev.target.checked ? 1 : 0;
  try {
    await store.adminSaveSettings(currentSupplierId.value, currentSettingsPayload({ auto_email_summary: next }));
    await loadSettings();
  } catch (e) {
    toast.error('Ошибка', e.message);
  }
}

async function toggleCcRestaurants(ev) {
  const next = ev.target.checked ? 1 : 0;
  try {
    await store.adminSaveSettings(currentSupplierId.value, currentSettingsPayload({ email_cc_restaurants: next }));
    await loadSettings();
  } catch (e) {
    toast.error('Ошибка', e.message);
  }
}

async function saveDefaultDeadline() {
  try {
    await store.adminSaveSettings(currentSupplierId.value, currentSettingsPayload());
    toast.success('Сохранено', 'Дедлайн по умолчанию обновлён');
    await loadSettings();
  } catch (e) {
    toast.error('Ошибка', e.message);
  }
}

async function savePauseMessage() {
  try {
    await store.adminSaveSettings(currentSupplierId.value, currentSettingsPayload());
  } catch (e) {
    toast.error('Ошибка', e.message);
  }
}

async function saveNotifyUsers() {
  savingNotifyUsers.value = true;
  try {
    const data = await store.adminSaveSettings(currentSupplierId.value, currentSettingsPayload({ notify_users: notifyUsers.value }));
    notifyUsers.value = Array.isArray(data.notify_users) ? data.notify_users : [];
    toast.success('Сохранено', 'Получатели обновлены');
  } catch (e) {
    toast.error('Ошибка', e.message);
  } finally {
    savingNotifyUsers.value = false;
  }
}

// Сохранение напоминаний — ОТДЕЛЬНЫЙ запрос только с ключами reminder_*,
// чтобы бэкенд обновил именно их и не тронул прочие настройки.
async function saveReminders() {
  savingReminders.value = true;
  try {
    const data = await store.adminSaveSettings(currentSupplierId.value, {
      reminder_offsets: [...reminderOffsets.value],
      reminder_channels: [...reminderChannels.value],
    });
    if (data && data.settings) {
      settings.value = data.settings;
      reminderOffsets.value = Array.isArray(data.settings.reminder_offsets) ? [...data.settings.reminder_offsets] : [];
      reminderChannels.value = Array.isArray(data.settings.reminder_channels) ? [...data.settings.reminder_channels] : [];
    }
    toast.success('Сохранено', 'Настройки напоминаний обновлены');
  } catch (e) {
    toast.error('Ошибка', e.message);
  } finally {
    savingReminders.value = false;
  }
}

// Синхронизация локальных полей недельного режима из settings.value.
// PDO может вернуть dow строкой — приводим к Number.
function syncWeeklyFromSettings() {
  const dow = settings.value.weekly_deadline_dow;
  if (dow != null && dow !== '') {
    weeklyEnabled.value = true;
    weeklyDow.value = Number(dow);
    weeklyTime.value = (settings.value.weekly_deadline_time || '14:00').substring(0, 5);
  } else {
    weeklyEnabled.value = false;
    weeklyDow.value = 3;
    weeklyTime.value = '14:00';
  }
}

// Сохранение недельного режима — ОТДЕЛЬНЫЙ запрос только с ключами weekly_*,
// чтобы бэкенд обновил именно их и не тронул прочие настройки.
async function saveWeekly() {
  savingWeekly.value = true;
  try {
    const payload = weeklyEnabled.value
      ? { weekly_deadline_dow: Number(weeklyDow.value), weekly_deadline_time: weeklyTime.value }
      : { weekly_deadline_dow: null };
    const data = await store.adminSaveSettings(currentSupplierId.value, payload);
    if (data && data.settings) {
      settings.value = data.settings;
      syncWeeklyFromSettings();
    }
    toast.success('Сохранено', 'Недельный режим обновлён');
  } catch (e) {
    toast.error('Ошибка', e.message);
  } finally {
    savingWeekly.value = false;
  }
}

// Синхронизация локальных полей минимального заказа из settings.value.
// min_order_value может прийти строкой (PDO) — приводим к Number; пусто/0 = минимума нет.
function syncMinOrderFromSettings() {
  const v = Number(settings.value.min_order_value);
  minOrderValue.value = v > 0 ? v : 0;
  minOrderUnit.value = settings.value.min_order_unit === 'pieces' ? 'pieces' : 'kg';
}

// Сохранение минимального заказа — ОТДЕЛЬНЫЙ запрос только с ключами min_order_*,
// чтобы бэкенд обновил именно их и не тронул прочие настройки.
async function saveMinOrder() {
  savingMinOrder.value = true;
  try {
    const val = Number(minOrderValue.value);
    const payload = {
      min_order_value: val > 0 ? val : null,
      min_order_unit: minOrderUnit.value,
    };
    const data = await store.adminSaveSettings(currentSupplierId.value, payload);
    if (data && data.settings) {
      settings.value = data.settings;
      syncMinOrderFromSettings();
    }
    toast.success('Сохранено', 'Минимальный заказ обновлён');
  } catch (e) {
    toast.error('Ошибка', e.message);
  } finally {
    savingMinOrder.value = false;
  }
}

// Синхронизация локальных полей опций Excel-отчёта из settings.value.
// xlsx_drop_empty может прийти строкой (PDO) — приводим к числу, затем к bool.
function syncXlsxFromSettings() {
  xlsxDropEmpty.value = !!Number(settings.value.xlsx_drop_empty);
  xlsxPalletMetrics.value = Array.isArray(settings.value.xlsx_pallet_metrics)
    ? [...settings.value.xlsx_pallet_metrics]
    : [];
}

// Сохранение опций Excel-отчёта — ОТДЕЛЬНЫЙ запрос только с ключами xlsx_*,
// чтобы бэкенд обновил именно их и не тронул прочие настройки.
async function saveXlsx() {
  savingXlsx.value = true;
  try {
    const data = await store.adminSaveSettings(currentSupplierId.value, {
      xlsx_drop_empty: xlsxDropEmpty.value ? 1 : 0,
      xlsx_pallet_metrics: [...xlsxPalletMetrics.value],
    });
    if (data && data.settings) {
      settings.value = data.settings;
      syncXlsxFromSettings();
    }
    toast.success('Сохранено', 'Настройки отчёта обновлены');
  } catch (e) {
    toast.error('Ошибка', e.message);
  } finally {
    savingXlsx.value = false;
  }
}

async function loadStatus() {
  if (!currentSupplierId.value) return;
  loading.value = true;
  try {
    const data = await store.adminGetStatus(currentSupplierId.value, selectedDate.value || undefined);
    stats.value = data.stats || { total: 0, submitted: 0, pending: 0 };
    restaurants.value = data.restaurants || [];
    products.value = data.products || [];
    orderItems.value = data.order_items || [];
    weekDates.value = data.week_dates || [];
    if (data.settings) settings.value = data.settings;
    if (data.date) selectedDate.value = data.date;
    selectedDeadline.value = data.deadline || '';
  } catch (e) {
    console.error(e);
  } finally {
    loading.value = false;
  }
}

async function loadOverview() {
  overviewLoading.value = true;
  try {
    const r = await store.adminGetOverview(overviewDate.value || undefined, orderStore.settings.legalEntity);
    overviewRows.value = r.suppliers || [];
  } catch (e) {
    console.error(e);
    toast.error('Ошибка', e.message || String(e));
  } finally {
    overviewLoading.value = false;
  }
}

// Проваливаемся в «Приём» выбранного поставщика
function openSupplierStatus(row) {
  if (!row || !row.id) return;
  currentSupplierId.value = row.id;
  pageTab.value = 'status';
  // Через refreshActiveTab — он для вкладки «Приём» грузит настройки нового поставщика
  // и затем статус. Иначе в settings оставались бы настройки прошлого поставщика.
  refreshActiveTab();
}

// Текст живого отсчёта до дедлайна (тикает через ref now)
function overviewCountdown(row) {
  if (!row || !row.deadline_at) return '';
  const diff = new Date(row.deadline_at).getTime() - now.value;
  if (diff <= 0) return 'Закрыт';
  const totalMin = Math.floor(diff / 60000);
  const days = Math.floor(totalMin / 1440);
  const hours = Math.floor((totalMin % 1440) / 60);
  const mins = totalMin % 60;
  if (days > 0) return `через ${days} дн ${hours} ч`;
  if (hours > 0) return `через ${hours} ч ${mins} мин`;
  return `через ${mins} мин`;
}

// Прошёл ли дедлайн (для приглушения/окраски)
function overviewIsPassed(row) {
  if (!row || !row.deadline_at) return false;
  return new Date(row.deadline_at).getTime() - now.value <= 0;
}

// Класс окраски колонки «Подано»
function overviewSubmittedClass(row) {
  const sub = Number(row.submitted_count) || 0;
  const exp = Number(row.expected_count) || 0;
  if (exp <= 0) return '';
  if (sub >= exp) return 'so-ov-ok';
  if (sub > 0) return 'so-ov-warn';
  return 'so-ov-bad';
}

function isDateForcedClosed(date) {
  return deadlineOverrides.value.some(o => o.delivery_date === date && o.is_closed);
}

async function handleToggleCloseDay(date) {
  if (!date) return;
  const closing = !isDateForcedClosed(date);
  const d = weekDates.value.find(w => w.date === date);
  const label = d ? `${d.day_name} ${formatDateShort(date)}` : formatDateShort(date);
  if (closing) {
    const ok = await showConfirm(`Закрыть день ${label}?`, 'Рестораны не смогут отправить заявку на эту дату.', { danger: true });
    if (!ok) return;
  }
  try {
    await store.adminCloseDay(currentSupplierId.value, date, closing);
    await loadSettings();
  } catch (e) {
    toast.error('Ошибка', e.message || String(e));
  }
}

// Общая механика продления дедлайна: два запроса (дата, время) + валидация + вызов стора.
// Возвращает true при успехе — вызывающая сторона сама решает, что обновить.
async function runExtendDeadline(supplierId, date, currentDeadlineDate, currentDeadlineTime) {
  const deadlineDate = await appPrompt('Формат YYYY-MM-DD', currentDeadlineDate || '', { title: 'Дата дедлайна', okText: 'Далее' });
  if (!deadlineDate) return false;
  if (!/^\d{4}-\d{2}-\d{2}$/.test(deadlineDate)) {
    toast.warning('Неверная дата', 'Введите дату в формате YYYY-MM-DD');
    return false;
  }
  const time = await appPrompt('Формат HH:MM (например 15:00)', currentDeadlineTime || '15:00', { title: 'Новое время дедлайна', okText: 'Сохранить' });
  if (!time) return false;
  if (!/^\d{1,2}:\d{2}$/.test(time)) {
    toast.warning('Неверный формат', 'Введите время в формате HH:MM (например 15:00)');
    return false;
  }
  try {
    await store.adminExtendDeadline(supplierId, date, time, deadlineDate);
    toast.success('Дедлайн продлён', `Новый дедлайн: ${deadlineDate} ${time}`);
    return true;
  } catch (e) {
    toast.error('Ошибка', e.message || 'Не удалось продлить дедлайн');
    return false;
  }
}

async function handleExtendDeadline() {
  if (!selectedDate.value) return;
  const currentDeadlineDate = selectedDeadline.value?.split(' ')?.[0] || '';
  const currentDeadlineTime = selectedDeadline.value?.split(' ')?.[1]?.substring(0, 5) || '15:00';
  const ok = await runExtendDeadline(currentSupplierId.value, selectedDate.value, currentDeadlineDate, currentDeadlineTime);
  if (ok) {
    await loadSettings();
    await loadStatus();
  }
}

// ═══ Действия из вкладки «Обзор» (per-row) ═══
async function overviewSendEmail(row) {
  if (!row.has_email || overviewBusy.value.has(row.id)) return;
  overviewBusy.value.add(row.id);
  try {
    const r = await store.adminSendSummaryEmail(row.id, overviewDate.value);
    toast.success('Отправлено', `Сводка ушла на почту поставщика (ресторанов: ${r.restaurants_count ?? '—'})`);
  } catch (e) {
    toast.error('Ошибка', e?.message || 'Не удалось отправить письмо');
  } finally {
    overviewBusy.value.delete(row.id);
  }
}

async function overviewSendTelegram(row) {
  if (overviewBusy.value.has(row.id)) return;
  overviewBusy.value.add(row.id);
  try {
    const res = await store.adminSendSummary(row.id, overviewDate.value);
    toast.success('Сводка отправлена', `${Number(res.sent || 0)} из ${Number(res.total_subs || 0)} отправок`);
  } catch (e) {
    toast.error('Ошибка отправки', e.message || String(e));
  } finally {
    overviewBusy.value.delete(row.id);
  }
}

async function overviewRemind(row) {
  if (overviewBusy.value.has(row.id)) return;
  overviewBusy.value.add(row.id);
  try {
    const r = await store.adminRemindUnsubmitted(row.id, overviewDate.value);
    if (r?.closed) {
      toast.info('Приём закрыт', r.message || 'Приём заявок на эту дату уже закрыт');
    } else {
      toast.success('Напоминание отправлено', `Напомнили ${r.reminded} из ${r.total_unsubmitted}`);
    }
  } catch (e) {
    toast.error('Ошибка', e.message || 'Не удалось отправить напоминание');
  } finally {
    overviewBusy.value.delete(row.id);
  }
}

async function overviewExtend(row) {
  if (overviewBusy.value.has(row.id)) return;
  let curDate = overviewDate.value;
  let curTime = '15:00';
  if (row.deadline_at) {
    const parts = String(row.deadline_at).replace('T', ' ').split(' ');
    if (parts[0]) curDate = parts[0];
    if (parts[1]) curTime = parts[1].substring(0, 5);
  }
  overviewBusy.value.add(row.id);
  try {
    const ok = await runExtendDeadline(row.id, overviewDate.value, curDate, curTime);
    if (ok) await loadOverview();
  } finally {
    overviewBusy.value.delete(row.id);
  }
}

async function overviewToggleClose(row) {
  if (overviewBusy.value.has(row.id)) return;
  const closing = !row.forced_closed;
  if (closing) {
    const ok = await showConfirm(`Закрыть день ${overviewDate.value}?`, 'Рестораны не смогут отправить заявку на эту дату.', { danger: true });
    if (!ok) return;
  }
  overviewBusy.value.add(row.id);
  try {
    await store.adminCloseDay(row.id, overviewDate.value, closing);
    await loadOverview();
  } catch (e) {
    toast.error('Ошибка', e.message || String(e));
  } finally {
    overviewBusy.value.delete(row.id);
  }
}

async function removeOverride(deliveryDate) {
  const ok = await showConfirm('Удалить продление?', `Убрать разовое продление дедлайна на ${deliveryDate}?`, { danger: true });
  if (!ok) return;
  try {
    await store.adminRemoveDeadlineOverride(currentSupplierId.value, deliveryDate);
    await loadSettings();
    await loadStatus();
  } catch (e) {
    toast.error('Ошибка', e.message);
  }
}

async function loadOrdersList() {
  if (!currentSupplierId.value) return;
  loadingList.value = true;
  try {
    ordersList.value = await store.adminGetOrders(currentSupplierId.value, {
      submitted_from: listSubmittedFrom.value,
      submitted_to: listSubmittedTo.value,
      delivery_from: listDeliveryFrom.value,
      delivery_to: listDeliveryTo.value,
      status: listStatus.value,
      query: listQuery.value,
      skip_only: listSkipOnly.value,
    });
  } catch (e) {
    console.error(e);
  } finally {
    loadingList.value = false;
  }
}

function resetOrdersFilters() {
  listSubmittedFrom.value = todayStr(-7);
  listSubmittedTo.value = todayStr(0);
  listDeliveryFrom.value = '';
  listDeliveryTo.value = '';
  listStatus.value = '';
  listQuery.value = '';
  listSkipOnly.value = false;
  loadOrdersList();
}

async function loadSchedules() {
  if (!currentSupplierId.value) return;
  loadingSchedules.value = true;
  try {
    const result = await store.adminGetSchedules(currentSupplierId.value);
    schedules.value = result.schedules;
    temporarySchedule.value = result.temporarySchedule || null;
    temporaryDateFrom.value = result.temporarySchedule?.date_from || '';
    temporaryDateTo.value = result.temporarySchedule?.date_to || '';
    // Заполняем дедлайны
    for (let d = 1; d <= 7; d++) deadlineRulesMap[d].active = false;
    for (const r of result.deadlineRules) {
      const dow = parseInt(r.delivery_dow);
      if (dow >= 1 && dow <= 7) {
        deadlineRulesMap[dow].deadline_dow = parseInt(r.deadline_dow);
        deadlineRulesMap[dow].deadline_time = (r.deadline_time || '14:00:00').substring(0, 5);
        deadlineRulesMap[dow].active = true;
      }
    }
    // Для нового поставщика сетку нужно пересобирать всегда, иначе в ней
    // остаются дни предыдущего поставщика.
    await loadRestaurantsForSchedule();
  } catch (e) {
    console.error(e);
  } finally {
    loadingSchedules.value = false;
  }
}

// ═══ Schedule grid ═══
const scheduleRestaurants = ref([]);
const scheduleGrid = reactive({});
const temporaryScheduleGrid = reactive({});
const savingScheduleGrid = ref(false);
const savingTemporarySchedule = ref(false);
const scheduleGridLoading = ref(false);
const scheduleFilter = ref('');
const temporaryDateFrom = ref('');
const temporaryDateTo = ref('');

const filteredScheduleRestaurants = computed(() => {
  if (!scheduleFilter.value) return scheduleRestaurants.value;
  const q = scheduleFilter.value.toLowerCase();
  return scheduleRestaurants.value.filter(r =>
    String(r.number).includes(q) || (r.city || '').toLowerCase().includes(q) || (r.address || '').toLowerCase().includes(q)
  );
});

const scheduleActiveDays = computed(() => {
  let count = 0;
  for (const rId of Object.keys(scheduleGrid)) { for (let d = 1; d <= 7; d++) { if (scheduleGrid[rId]?.[d]) count++; } }
  return count;
});
const scheduleActiveRests = computed(() => {
  let count = 0;
  for (const rId of Object.keys(scheduleGrid)) { for (let d = 1; d <= 7; d++) { if (scheduleGrid[rId]?.[d]) { count++; break; } } }
  return count;
});
const temporaryScheduleActiveDays = computed(() => {
  let count = 0;
  for (const rId of Object.keys(temporaryScheduleGrid)) { for (let d = 1; d <= 7; d++) { if (temporaryScheduleGrid[rId]?.[d]) count++; } }
  return count;
});
const temporaryScheduleActiveRests = computed(() => {
  let count = 0;
  for (const rId of Object.keys(temporaryScheduleGrid)) { for (let d = 1; d <= 7; d++) { if (temporaryScheduleGrid[rId]?.[d]) { count++; break; } } }
  return count;
});

function resetGrid(grid) {
  for (const key of Object.keys(grid)) delete grid[key];
}

function fillGridFromItems(grid, restaurants, items = []) {
  resetGrid(grid);
  for (const r of restaurants) grid[r.id] = {};
  for (const s of items || []) {
    const rest = restaurants.find(r => r.number == s.restaurant_number);
    if (!rest || s.is_active != 1) continue;
    if (!grid[rest.id]) grid[rest.id] = {};
    grid[rest.id][s.delivery_day] = true;
  }
}

function buildSchedulesFromGrid(grid) {
  const items = [];
  for (const r of scheduleRestaurants.value) {
    for (let d = 1; d <= 7; d++) {
      if (grid[r.id]?.[d]) {
        const rule = deadlineRulesMap[d];
        const orderDay = rule?.active ? rule.deadline_dow : (d > 1 ? d - 1 : 7);
        items.push({ restaurant_id: r.id, order_day: orderDay, delivery_day: d, is_active: 1 });
      }
    }
  }
  return items;
}

function buildTemporarySchedulePayload() {
  const items = buildSchedulesFromGrid(temporaryScheduleGrid);
  const dateFrom = temporaryDateFrom.value || '';
  const dateTo = temporaryDateTo.value || '';
  if (!dateFrom && !dateTo && !items.length) return null;
  return {
    date_from: dateFrom,
    date_to: dateTo,
    items,
  };
}

async function loadRestaurantsForSchedule() {
  scheduleGridLoading.value = true;
  try {
    const token = localStorage.getItem('bk_session_token') || '';
    // Рестораны только той же группы юрлиц, что и поставщик —
    // ПС-поставщик видит только ПС-рестораны, БК-поставщик — БК+ВМ.
    const group = currentSupplierGroup.value;
    const res = await fetch(`/api/restaurants?select=id,number,city,address,region,legal_entity_group&active=eq.1&legal_entity_group=eq.${group}&order=number.asc&limit=500`, {
      headers: { 'X-Session-Token': token, 'X-API-Key': token },
    });
    const data = await res.json();
    const allRests = (data.data || data || []).sort((a, b) => parseInt(a.number) - parseInt(b.number));
    scheduleRestaurants.value = allRests;
    fillGridFromItems(scheduleGrid, scheduleRestaurants.value, schedules.value);
    fillGridFromItems(temporaryScheduleGrid, scheduleRestaurants.value, temporarySchedule.value?.items || []);
  } catch (e) { console.error(e); }
  finally { scheduleGridLoading.value = false; }
}

function toggleScheduleDay(restaurant, dow) {
  if (!scheduleGrid[restaurant.id]) scheduleGrid[restaurant.id] = {};
  scheduleGrid[restaurant.id][dow] = !scheduleGrid[restaurant.id][dow];
}

function toggleTemporaryScheduleDay(restaurant, dow) {
  if (!temporaryScheduleGrid[restaurant.id]) temporaryScheduleGrid[restaurant.id] = {};
  temporaryScheduleGrid[restaurant.id][dow] = !temporaryScheduleGrid[restaurant.id][dow];
}

async function saveScheduleGrid() {
  const dayNames = { 1: 'Пн', 2: 'Вт', 3: 'Ср', 4: 'Чт', 5: 'Пт', 6: 'Сб', 7: 'Вс' };

  // Текущее состояние в БД
  const currentState = {};
  for (const s of schedules.value) {
    if (s.is_active != 1) continue;
    const rest = scheduleRestaurants.value.find(r => r.number == s.restaurant_number);
    if (!rest) continue;
    if (!currentState[rest.id]) currentState[rest.id] = new Set();
    currentState[rest.id].add(Number(s.delivery_day));
  }

  // Новое состояние из сетки
  const removedByDay = {}, addedByDay = {};
  for (const r of scheduleRestaurants.value) {
    const cur = currentState[r.id] || new Set();
    for (let d = 1; d <= 7; d++) {
      const wasActive = cur.has(d);
      const willBeActive = !!scheduleGrid[r.id]?.[d];
      if (wasActive && !willBeActive) removedByDay[d] = (removedByDay[d] || 0) + 1;
      if (!wasActive && willBeActive) addedByDay[d]  = (addedByDay[d]  || 0) + 1;
    }
  }

  // Предупреждение если есть удаления
  const removedDays = Object.keys(removedByDay).map(Number).sort();
  const addedDays   = Object.keys(addedByDay).map(Number).sort();

  if (removedDays.length || addedDays.length) {
    const lines = [];
    if (removedDays.length) {
      lines.push('Будет удалено:');
      for (const d of removedDays) lines.push(`  ${dayNames[d]}: −${removedByDay[d]} рест.`);
    }
    if (addedDays.length) {
      if (lines.length) lines.push('');
      lines.push('Будет добавлено:');
      for (const d of addedDays) lines.push(`  ${dayNames[d]}: +${addedByDay[d]} рест.`);
    }
    const ok = await showConfirm('Изменения в графике', lines.join('\n'), { okText: 'Продолжить', danger: removedDays.length > 0 });
    if (!ok) return;
  }

  savingScheduleGrid.value = true;
  try {
    await store.adminSaveSchedules(
      currentSupplierId.value,
      buildSchedulesFromGrid(scheduleGrid),
      buildTemporarySchedulePayload()
    );
    toast.success('Сохранено', 'График обновлён');
    await loadSchedules();
  } catch (e) { toast.error('Ошибка', e.message); }
  finally { savingScheduleGrid.value = false; }
}

async function saveTemporarySchedule() {
  if ((temporaryDateFrom.value && !temporaryDateTo.value) || (!temporaryDateFrom.value && temporaryDateTo.value)) {
    toast.warning('Нужно две даты', 'Укажите и начало, и окончание временного периода');
    return;
  }
  if (temporaryDateFrom.value && temporaryDateTo.value && temporaryDateFrom.value > temporaryDateTo.value) {
    toast.warning('Проверьте даты', 'Дата окончания не может быть раньше даты начала');
    return;
  }
  savingTemporarySchedule.value = true;
  try {
    await store.adminSaveSchedules(
      currentSupplierId.value,
      buildSchedulesFromGrid(scheduleGrid),
      buildTemporarySchedulePayload()
    );
    toast.success('Сохранено', 'Временный график обновлён');
    await loadSchedules();
  } catch (e) {
    toast.error('Ошибка', e.message);
  } finally {
    savingTemporarySchedule.value = false;
  }
}

function clearTemporarySchedule() {
  temporaryDateFrom.value = '';
  temporaryDateTo.value = '';
  fillGridFromItems(temporaryScheduleGrid, scheduleRestaurants.value, []);
}

async function copyMainScheduleToTemporary() {
  let hasTemporaryData = !!temporaryDateFrom.value || !!temporaryDateTo.value;
  if (!hasTemporaryData) {
    for (const rId of Object.keys(temporaryScheduleGrid)) {
      for (let d = 1; d <= 7; d++) {
        if (temporaryScheduleGrid[rId]?.[d]) {
          hasTemporaryData = true;
          break;
        }
      }
      if (hasTemporaryData) break;
    }
  }

  if (hasTemporaryData) {
    const ok = await showConfirm(
      'Перезаписать временный график?',
      'Текущие отметки во временном графике будут заменены копией основного графика.'
    );
    if (!ok) return;
  }

  resetGrid(temporaryScheduleGrid);
  for (const r of scheduleRestaurants.value) {
    temporaryScheduleGrid[r.id] = {};
    for (let d = 1; d <= 7; d++) {
      if (scheduleGrid[r.id]?.[d]) {
        temporaryScheduleGrid[r.id][d] = true;
      }
    }
  }
  toast.success('Скопировано', 'Основной график перенесён во временный');
}

async function saveDeadlineRules() {
  savingDeadlines.value = true;
  try {
    const rules = [];
    for (let d = 1; d <= 7; d++) {
      if (deadlineRulesMap[d].active) {
        rules.push({ delivery_dow: d, deadline_dow: deadlineRulesMap[d].deadline_dow, deadline_time: deadlineRulesMap[d].deadline_time + ':00' });
      }
    }
    await store.adminSaveDeadlineRules(currentSupplierId.value, rules);
    toast.success('Сохранено', 'Дедлайны обновлены');
  } catch (e) { toast.error('Ошибка', e.message); }
  finally { savingDeadlines.value = false; }
}

async function loadTemplates() {
  if (!currentSupplierId.value) return;
  loadingTemplates.value = true;
  templateProductSearch.value = '';
  templateProductResults.value = [];
  linkingRowIdx.value = null;
  try {
    templates.value = await store.adminGetTemplates(currentSupplierId.value, templateLe.value);
  } catch (e) {
    console.error(e);
  } finally {
    loadingTemplates.value = false;
  }
}

function addManualTemplateRow() {
  templates.value.push({
    product_id: null,
    sku: '',
    product_name: '',
    sort_order: templates.value.length * 10,
    multiplicity: null,
    min_qty: null,
  });
}

function addTemplateProduct(p) {
  const sku = String(p.sku || '').trim();
  if (!sku) return;
  if (templates.value.some(t => String(t.sku || '').trim() === sku)) {
    toast.info('Уже в шаблоне', sku);
    return;
  }
  templates.value.push({
    product_id: p.id || p.product_id || null,
    sku,
    product_name: p.name || p.product_name || '',
    sort_order: templates.value.length * 10,
    multiplicity: p.multiplicity || null,
    min_qty: p.min_qty || null,
  });
  templateProductSearch.value = '';
  templateProductResults.value = [];
}

// Короткая подсказка атрибутов каталога (ед. + вес нетто за коробку) для привязанной строки
function catalogAttrs(t) {
  const parts = [];
  if (t.unit_of_measure) parts.push(String(t.unit_of_measure));
  if (t.weight_netto != null && t.weight_netto !== '') parts.push(`${t.weight_netto} г/кор`);
  return parts.join(' · ');
}

// Полная подсказка для title (tooltip) привязанной строки
function catalogHint(t) {
  const parts = [];
  if (t.catalog_name) parts.push(t.catalog_name);
  if (t.unit_of_measure) parts.push(`ед: ${t.unit_of_measure}`);
  if (t.weight_netto != null && t.weight_netto !== '') parts.push(`нетто: ${t.weight_netto} г/кор`);
  if (t.weight_brutto != null && t.weight_brutto !== '') parts.push(`брутто: ${t.weight_brutto} г/кор`);
  if (t.qty_per_box != null && t.qty_per_box !== '') parts.push(`в коробке: ${t.qty_per_box} шт`);
  if (t.boxes_per_pallet != null && t.boxes_per_pallet !== '') parts.push(`на паллете: ${t.boxes_per_pallet} кор`);
  return parts.join('\n');
}

// Войти в режим привязки карточки для конкретной строки
function startLinkRow(idx) {
  linkingRowIdx.value = idx;
  templateProductSearch.value = '';
  templateProductResults.value = [];
}

// Выйти из режима привязки без выбора
function cancelLinkRow() {
  linkingRowIdx.value = null;
  templateProductSearch.value = '';
  templateProductResults.value = [];
}

// Привязать карточку каталога к СУЩЕСТВУЮЩЕЙ строке (в отличие от addTemplateProduct — не добавляет новую)
function linkTemplateRow(idx, p) {
  const t = templates.value[idx];
  if (!t) return;
  t.product_id = p.id || p.product_id || null;
  // SKU/название заполняем только если строка пустая — введённое закупщиком не затираем
  if (!String(t.sku || '').trim()) t.sku = String(p.sku || '').trim();
  if (!String(t.product_name || '').trim()) t.product_name = p.name || p.product_name || '';
  // Локально отражаем статус и атрибуты, чтобы ✅ появился сразу
  t.linked = 1;
  t.catalog_name = p.name || p.product_name || t.catalog_name || '';
  if (p.unit_of_measure != null) t.unit_of_measure = p.unit_of_measure;
  if (p.weight_netto != null) t.weight_netto = p.weight_netto;
  if (p.weight_brutto != null) t.weight_brutto = p.weight_brutto;
  if (p.qty_per_box != null) t.qty_per_box = p.qty_per_box;
  if (p.boxes_per_pallet != null) t.boxes_per_pallet = p.boxes_per_pallet;
  cancelLinkRow();
}

function searchTemplateProducts() {
  clearTimeout(templateSearchTimer);
  const q = templateProductSearch.value.trim();
  if (q.length < 2) {
    templateProductResults.value = [];
    return;
  }
  templateSearchTimer = setTimeout(async () => {
    try {
      const params = new URLSearchParams({ q, legal_entity: templateLe.value, limit: '20' });
      if (currentSupplier.value?.short_name) params.set('supplier', currentSupplier.value.short_name);
      const r = await fetch(`/api/search_products?${params}`, {
        headers: { 'X-Session-Token': localStorage.getItem('bk_session_token') || '' },
      });
      templateProductResults.value = r.ok ? await r.json() : [];
    } catch {
      templateProductResults.value = [];
    }
  }, 250);
}

async function saveTemplates() {
  savingTemplates.value = true;
  try {
    const items = templates.value
      .filter(t => String(t.sku || '').trim() && String(t.product_name || '').trim())
      .map(t => ({ ...t, sku: String(t.sku).trim(), product_name: String(t.product_name).trim() }));
    if (items.length !== templates.value.length) {
      toast.warning('Пустые строки пропущены', 'Для сохранения нужны SKU и название товара');
    }
    await store.adminSaveTemplates(currentSupplierId.value, templateLe.value, items);
    templates.value = items;
    toast.success('Сохранено', 'Шаблон обновлён');
    // Перезагружаем, чтобы статус связи и атрибуты каталога обновились авторитетно с бэкенда
    await loadTemplates();
  } catch (e) {
    toast.error('Ошибка', e.message);
  } finally {
    savingTemplates.value = false;
  }
}

async function importFromProducts() {
  if (!currentSupplierId.value) return;
  try {
    const supplierName = currentSupplier.value?.short_name || '';
    if (!supplierName) {
      toast.warning('Поставщик не выбран', 'Не удалось определить поставщика');
      return;
    }
    const { data, error } = await db.from('products')
      .select('id,sku,name,multiplicity')
      .eq('supplier', supplierName)
      .eq('legal_entity', templateLe.value)
      .eq('is_active', 1)
      .order('name')
      .limit(500);
    if (error) throw new Error(error);
    const products = data || [];
    if (!products.length) {
      toast.warning('Нет товаров', 'У этого поставщика нет товаров в справочнике');
      return;
    }
    templates.value = products.map((p, i) => ({
      product_id: p.product_id || p.id || '',
      sku: p.sku,
      product_name: p.product_name || p.name || '',
      sort_order: i * 10,
      multiplicity: p.multiplicity || null,
      min_qty: p.min_qty || null,
    }));
  } catch (e) {
    toast.error('Ошибка', e.message);
  }
}

async function viewOrder(orderId) {
  try {
    viewedOrder.value = await store.adminGetOrder(orderId);
    showOrderModal.value = true;
  } catch (e) {
    toast.error('Ошибка', e.message);
  }
}

async function deleteOrder(orderId, status = '') {
  // День уже закрыт — заявка, скорее всего, ушла поставщику, предупреждаем прямо.
  const text = status === 'locked'
    ? 'День уже закрыт, и эта заявка могла попасть в сводку поставщику. Действие нельзя отменить.'
    : 'Действие нельзя отменить.';
  const ok = await showConfirm('Удалить заявку?', text, { danger: true, okText: 'Удалить' });
  if (!ok) return;
  try {
    await store.adminDeleteOrder(orderId);
    await loadOrdersList();
  } catch (e) {
    toast.error('Ошибка', e.message);
  }
}

function fmtDateDDMM(dateStr) {
  const d = new Date(dateStr);
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  return `${dd}.${mm}.${d.getFullYear()}`;
}

async function sendSummary() {
  if (!currentSupplierId.value || !selectedDate.value) return;
  const datesToSend = exportSelectedDates.value.size > 0
    ? [...exportSelectedDates.value].sort()
    : [selectedDate.value];
  const fmt = datesToSend
    .map(date => new Date(date).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' }))
    .join(', ');
  const ok = await showConfirm(
    'Отправить сводку?',
    datesToSend.length > 1
      ? `Сводки по датам ${fmt} будут отправлены подписчикам в Telegram отдельными сообщениями.`
      : `Сводка по заявкам на ${fmt} будет отправлена подписчикам в Telegram.`
  );
  if (!ok) return;
  sendingSummary.value = true;
  try {
    let sent = 0;
    let total = 0;
    for (const date of datesToSend) {
      // Опции Excel сервер берёт из настроек поставщика
      const res = await store.adminSendSummary(currentSupplierId.value, date);
      sent += Number(res.sent || 0);
      total += Number(res.total_subs || 0);
    }
    toast.success(
      datesToSend.length > 1 ? 'Сводки отправлены' : 'Сводка отправлена',
      `${sent} из ${total} отправок`
    );
  } catch (e) {
    toast.error('Ошибка отправки', e.message || String(e));
  } finally {
    sendingSummary.value = false;
  }
}

async function sendSummaryEmail() {
  if (!selectedDate.value || !currentSupplierId.value) return;
  sendingSummaryEmail.value = true;
  try {
    // Опции Excel сервер берёт из настроек поставщика
    const r = await store.adminSendSummaryEmail(currentSupplierId.value, selectedDate.value);
    toast.success('Отправлено', `Сводка ушла на почту поставщика (ресторанов: ${r.restaurants_count ?? '—'})`);
  } catch (e) {
    toast.error('Ошибка', e?.message || 'Не удалось отправить письмо');
  } finally {
    sendingSummaryEmail.value = false;
  }
}

async function remindUnsubmitted() {
  if (!selectedDate.value || !currentSupplierId.value) return;
  remindingStatus.value = true;
  try {
    const r = await store.adminRemindUnsubmitted(currentSupplierId.value, selectedDate.value);
    if (r?.closed) {
      toast.info('Приём закрыт', r.message || 'Приём заявок на эту дату уже закрыт');
    } else {
      toast.success('Напоминание отправлено', `Напомнили ${r.reminded} из ${r.total_unsubmitted}`);
    }
  } catch (e) {
    toast.error('Ошибка', e.message || 'Не удалось отправить напоминание');
  } finally {
    remindingStatus.value = false;
  }
}

async function exportExcel() {
  if (!currentSupplierId.value) return;
  exporting.value = true;

  const datesToExport = exportSelectedDates.value.size > 0
    ? [...exportSelectedDates.value].sort()
    : (selectedDate.value ? [selectedDate.value] : []);

  if (!datesToExport.length) { exporting.value = false; toast.warning('Не выбрано', 'Выберите хотя бы один день'); return; }

  try {
    // Опции отчёта — только из настроек поставщика (тот же источник, что и на сервере).
    // На вкладке «Статус» настройки уже загружены, но если в settings лежат настройки
    // ДРУГОГО поставщика (переход из «Обзора») или их вовсе нет — подгружаем перед сборкой,
    // иначе файл молча уедет с чужими или выключенными опциями.
    if (
      settingsLoadedFor.value !== currentSupplierId.value
      || !settings.value
      || !Object.prototype.hasOwnProperty.call(settings.value, 'xlsx_pallet_metrics')
    ) {
      await loadSettings();
    }
    // Если настройки так и не загрузились (сбой сети) — лучше не отдавать файл вовсе,
    // чем отдать с чужими или молча выключенными опциями.
    if (settingsLoadedFor.value !== currentSupplierId.value) {
      toast.error('Не удалось скачать', 'Настройки отчёта не загрузились. Обновите страницу и попробуйте снова.');
      return;
    }
    const sheetOptions = {
      dropEmptyRows: !!settings.value?.xlsx_drop_empty,
      palletMetrics: Array.isArray(settings.value?.xlsx_pallet_metrics) ? settings.value.xlsx_pallet_metrics : [],
    };

    const XLSX = await import('xlsx-js-style');
    const supplierName = allSuppliers.value.find(s => String(s.id) === String(currentSupplierId.value))?.short_name || 'Поставщик';
    const wb = XLSX.utils.book_new();

    // ═══ По одному листу на каждую дату ═══
    for (const date of datesToExport) {
      let prods, rests, items;
      if (date === selectedDate.value && products.value.length) {
        prods = buildDisplayProducts(products.value); rests = restaurants.value; items = orderItems.value;
      } else {
        const data = await store.adminGetStatus(currentSupplierId.value, date);
        prods = buildDisplayProducts(data.products || []); rests = data.restaurants || []; items = data.order_items || [];
      }
      if (!prods.length || !rests.length) continue;

      const dateFmt = fmtDateDDMM(date);
      const ws = buildSoOrderSheet(XLSX, {
        supplierName,
        dateFmt,
        products: prods,
        restaurants: rests,
        items,
        isAutoSubmitted,
        options: sheetOptions,
      });

      const wd = weekDates.value.find(d => d.date === date);
      const sheetName = (wd ? `${wd.day_name} ${dateFmt}` : dateFmt).slice(0, 31);
      XLSX.utils.book_append_sheet(wb, ws, sheetName);
    }

    if (wb.SheetNames.length === 0) { toast.warning('Нет данных', 'Нет данных для выгрузки'); return; }
    const firstDate = fmtDateDDMM(datesToExport[0]);
    const lastDate = datesToExport.length > 1 ? `-${fmtDateDDMM(datesToExport[datesToExport.length - 1])}` : '';
    XLSX.writeFile(wb, `Заявка ${supplierName} ${firstDate}${lastDate}.xlsx`);
  } catch (e) {
    toast.error('Ошибка экспорта', e.message);
  } finally {
    exporting.value = false;
  }
}

// ═══ Pivot table helpers ═══

// Lookup: { "restNum_sku" => { quantity, admin_qty, item_id, order_id } }
const itemLookup = computed(() => {
  const map = {};
  for (const item of orderItems.value) {
    const key = `${item.restaurant_number}_${item.sku}`;
    map[key] = item;
  }
  return map;
});

const displayProducts = computed(() => buildDisplayProducts(products.value));

function getDisplayItem(restNum, product) {
  const skus = product?.source_skus?.length ? product.source_skus : [product?.sku];
  let found = false;
  let originalQty = 0;
  let effectiveQty = 0;
  let hasAdmin = false;

  for (const sku of skus) {
    const item = itemLookup.value[`${restNum}_${sku}`];
    if (!item) continue;
    found = true;
    const rawQty = parseFloat(item.quantity);
    const rawAdmin = item.admin_qty !== null && item.admin_qty !== undefined ? parseFloat(item.admin_qty) : NaN;
    if (!isNaN(rawQty)) originalQty += rawQty;
    if (!isNaN(rawAdmin)) {
      effectiveQty += rawAdmin;
      hasAdmin = true;
    } else if (!isNaN(rawQty)) {
      effectiveQty += rawQty;
    }
  }

  if (!found) return null;
  return {
    quantity: originalQty,
    admin_qty: hasAdmin ? effectiveQty : null,
  };
}

async function copyMissingRestaurants() {
  const missing = restaurants.value.filter(r => !r.order_status || r.order_status === 'draft');
  if (!missing.length) {
    toast.info('Все подали', 'Нет ресторанов без заявки на эту дату');
    return;
  }
  const sup = allSuppliers.value.find(s => String(s.id) === String(currentSupplierId.value));
  const supName = sup?.short_name || 'поставщик';
  const list = missing.map(r => formatRestaurantNumber(r.number, r.legal_entity_group)).join(', ');
  const text = `Нет заявок на "${supName}" от ресторанов: ${list}`;
  try {
    await navigator.clipboard.writeText(text);
    toast.success('Скопировано', `${missing.length} ${missing.length === 1 ? 'ресторан' : 'ресторанов'} в буфере обмена`);
  } catch (e) {
    toast.error('Ошибка копирования', e.message);
  }
}

const filteredRestaurants = computed(() => {
  let list = restaurants.value;
  if (!showMissing.value) {
    list = list.filter(r => r.order_status);
  }
  if (filterText.value) {
    const q = filterText.value.toLowerCase();
    list = list.filter(r =>
      String(r.number).includes(q) ||
      (r.region || '').toLowerCase().includes(q) ||
      (r.address || '').toLowerCase().includes(q) ||
      (r.city || '').toLowerCase().includes(q)
    );
  }
  return list;
});

function getCellQty(restNum, product) {
  const item = getDisplayItem(restNum, product);
  if (!item) return '';
  return formatQtyValue(item.quantity);
}

function getCellAdmin(restNum, product) {
  const item = getDisplayItem(restNum, product);
  if (!item || item.admin_qty === null || item.admin_qty === undefined) return null;
  return formatQtyValue(item.admin_qty);
}

function getProductTotal(product) {
  let total = 0;
  for (const r of filteredRestaurants.value) {
    const item = getDisplayItem(r.number, product);
    if (!item) continue;
    const qty = item.admin_qty !== null && item.admin_qty !== undefined ? item.admin_qty : item.quantity;
    if (Number.isFinite(qty)) total += qty;
  }
  return formatQtyValue(total);
}

function shortName(name) {
  return name && name.length > 15 ? name.slice(0, 15) + '…' : name;
}

function canEditProduct(product) {
  return !product?.is_grouped;
}

function startEdit(restNum, sku) {
  const key = `${restNum}_${sku}`;
  const item = itemLookup.value[key];
  editCell.value = key;
  editValue.value = item?.admin_qty !== null && item?.admin_qty !== undefined
    ? item.admin_qty
    : (item?.quantity || '');
  nextTick(() => {
    const el = document.querySelector('.so-cell-input');
    if (el) { el.focus(); el.select(); }
  });
}

async function saveEdit() {
  if (!editCell.value) return;
  const match = editCell.value.match(/^(\d+)_(.+)$/);
  if (!match) { editCell.value = ''; return; }
  const [, restNum, sku] = match;
  const item = itemLookup.value[`${restNum}_${sku}`];
  const val = parseFloat(String(editValue.value).replace(',', '.'));
  editCell.value = '';

  try {
    if (item?.item_id) {
      // Обновляем существующую позицию
      await store.adminUpdateQty({
        item_id: item.item_id,
        admin_qty: isNaN(val) ? null : val,
      });
      item.admin_qty = isNaN(val) ? null : val;
    } else {
      // Создаём новую запись (админ заполняет за ресторан)
      const prod = products.value.find(p => p.sku === sku);
      const result = await store.adminUpdateQty({
        restaurant_number: restNum,
        delivery_date: selectedDate.value,
        sku,
        product_name: prod?.product_name || '',
        product_id: prod?.product_id || '',
        supplier_id: currentSupplierId.value,
        admin_qty: isNaN(val) ? null : val,
      });
      if (result.reload) {
        await loadStatus();
      }
    }
  } catch (e) {
    toast.error('Ошибка сохранения', e.message);
  }
}

function copyLink() {
  const path = currentSupplierId.value
    ? `/restaurant/orders/supplier/${encodeURIComponent(currentSupplierId.value)}`
    : '/restaurant/orders';
  const url = window.location.origin + path;
  navigator.clipboard.writeText(url);
  toast.success('Скопировано', url);
}

function statusLabel(s) {
  if (s === 'submitted') return 'Подано';
  if (s === 'edited') return 'Изменён';
  if (s === 'locked') return 'Закрыто';
  if (s === 'draft') return 'Черновик';
  return 'Не подано';
}

function isAutoSubmitted(row) {
  return Number(row?.is_auto_submitted || 0) === 1;
}

function autoSubmitTitle(row) {
  if (!isAutoSubmitted(row)) return '';
  const source = row.auto_source_order_id ? `#${row.auto_source_order_id}` : 'предыдущей заявки';
  const date = row.auto_source_delivery_date ? ` от ${formatDate(row.auto_source_delivery_date)}` : '';
  return `Автоматически скопировано из заявки ${source}${date}`;
}

// Заявка-отказ: подана, но без позиций — ресторан отметил «Поставка не нужна»
function isSkipOrder(r) {
  if (!r || !r.order_id) return false;
  if (r.order_status !== 'submitted' && r.order_status !== 'locked') return false;
  return Number(r.item_count || 0) === 0;
}

function formatDate(d) {
  if (!d) return '';
  return new Date(d + 'T00:00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' });
}
function formatDateShort(d) {
  if (!d) return '';
  return new Date(d + 'T00:00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
}
function formatDateRange(start, end) {
  if (!start || !end) return '—';
  return formatDate(start) + ' — ' + formatDate(end);
}
function formatTime(dt) {
  if (!dt) return '';
  const d = new Date(dt);
  return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}
function formatDateTime(dt) {
  if (!dt) return '';
  const d = new Date(dt);
  return d.toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

// ═══ Сохранение фильтров в URL ═══
// Восстанавливаем из query при монтировании
{
  const q = route.query || {};
  if (q.tab) pageTab.value = String(q.tab);
  if (q.date) selectedDate.value = String(q.date);
  if (q.status) listStatus.value = String(q.status);
  if (q.query) listQuery.value = String(q.query);
  if (q.skipOnly === '1') listSkipOnly.value = true;
  if (q.from) listDeliveryFrom.value = String(q.from);
  if (q.to) listDeliveryTo.value = String(q.to);
  if (q.scheduleFilter) scheduleFilter.value = String(q.scheduleFilter);
}

let urlSyncInit = false;
watch(
  [pageTab, selectedDate, listStatus, listQuery, listSkipOnly, listDeliveryFrom, listDeliveryTo, scheduleFilter],
  () => {
    if (!urlSyncInit) { urlSyncInit = true; return; }
    const q = { ...route.query };
    const set = (key, val) => { if (val) q[key] = val; else delete q[key]; };
    set('tab', pageTab.value !== 'status' ? pageTab.value : '');
    set('date', selectedDate.value);
    set('status', listStatus.value);
    set('query', listQuery.value);
    set('skipOnly', listSkipOnly.value ? '1' : '');
    set('from', listDeliveryFrom.value);
    set('to', listDeliveryTo.value);
    set('scheduleFilter', scheduleFilter.value);
    router.replace({ query: q }).catch(() => {});
  },
);
</script>

<style scoped>
/*
 * Оформление построено на дизайн-системе проекта (src/styles/tokens.css, DESIGN.md).
 * Правило: никаких сырых цветов/отступов/радиусов — только var(--tk-*).
 * Акцент один (оранжевый) и только для главного действия и активных состояний;
 * зелёный/красный несут смысл (подано / ошибка), а не «это кнопка».
 */

.rom-page { padding: var(--tk-s-5); }

.rom-toolbar {
  display: flex; justify-content: space-between; align-items: center;
  margin-bottom: var(--tk-s-5); flex-wrap: wrap; gap: var(--tk-s-3);
}
.rom-toolbar h1 {
  margin: 0; font-size: var(--tk-fz-h2); font-weight: var(--tk-fw-bold);
  color: var(--tk-text); letter-spacing: -0.01em;
}
.rom-toolbar-actions { display: flex; gap: var(--tk-s-2); flex-wrap: wrap; }

/* ═══ Кнопки ═══ */
.rom-btn {
  padding: 7px var(--tk-s-4); border-radius: var(--tk-r-md);
  border: 1px solid var(--tk-border); background: var(--tk-bg-card);
  cursor: pointer; font-size: var(--tk-fz-md); font-weight: var(--tk-fw-medium);
  font-family: inherit; color: var(--tk-text);
  transition: background var(--tk-anim-fast), border-color var(--tk-anim-fast), color var(--tk-anim-fast);
}
.rom-btn:hover { background: var(--tk-n-50); border-color: var(--tk-n-300); }
.rom-btn:focus-visible { outline: none; box-shadow: var(--tk-focus-ring); }
.rom-btn:disabled { opacity: 0.45; cursor: not-allowed; }
.rom-btn:disabled:hover { background: var(--tk-bg-card); border-color: var(--tk-border); }
.rom-btn-primary {
  background: var(--tk-accent); color: var(--tk-n-0); border-color: var(--tk-accent);
  font-weight: var(--tk-fw-semibold);
}
.rom-btn-primary:hover { background: var(--tk-accent-hover); border-color: var(--tk-accent-hover); }
.rom-btn-outline { border-style: dashed; }
/* Выгрузка — обычное второстепенное действие, без заливки. */
.rom-btn-export { background: var(--tk-bg-card); color: var(--tk-text); border-color: var(--tk-border); }
.rom-btn-export:hover { background: var(--tk-n-50); border-color: var(--tk-n-300); }
.rom-btn-sm {
  padding: 4px var(--tk-s-3); border-radius: var(--tk-r-sm);
  border: 1px solid var(--tk-border); background: var(--tk-bg-card);
  cursor: pointer; font-size: var(--tk-fz-sm); font-weight: var(--tk-fw-medium);
  font-family: inherit; color: var(--tk-text);
  transition: background var(--tk-anim-fast), border-color var(--tk-anim-fast), color var(--tk-anim-fast);
}
.rom-btn-sm:hover { background: var(--tk-n-50); border-color: var(--tk-n-300); }
.rom-btn-sm:focus-visible { outline: none; box-shadow: var(--tk-focus-ring); }
.rom-btn-sm:disabled { opacity: 0.45; cursor: not-allowed; }
.rom-btn-sm:disabled:hover { background: var(--tk-bg-card); border-color: var(--tk-border); }
/* Пять действий в строке. Раскладываем сеткой, иначе кнопки разной ширины
   переносятся рвано и колонка выглядит неопрятно. */
.so-ov-actions { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--tk-s-1); }
.so-ov-actions .rom-btn-sm { padding: 4px var(--tk-s-2); width: 100%; }
.rom-btn-sm.rom-btn-primary {
  background: var(--tk-accent); color: var(--tk-n-0); border-color: var(--tk-accent);
}
.rom-btn-sm.rom-btn-primary:hover { background: var(--tk-accent-hover); border-color: var(--tk-accent-hover); }
/* Опасное действие: цвет — в тексте, рамка остаётся спокойной до наведения. */
.rom-btn-sm.rom-btn-danger { background: var(--tk-bg-card); color: var(--tk-danger); border-color: var(--tk-border); }
.rom-btn-sm.rom-btn-danger:hover { background: var(--tk-danger-soft); border-color: var(--tk-danger); }
.rom-btn-danger { color: var(--tk-danger); border-color: var(--tk-border); }
.rom-btn-danger:hover { background: var(--tk-danger-soft); border-color: var(--tk-danger); }

/* ═══ Вкладки страницы ═══ */
.rom-page-tabs {
  display: flex; gap: 0; margin-bottom: var(--tk-s-4);
  border-bottom: 1px solid var(--tk-border);
}
.rom-page-tab {
  padding: var(--tk-s-3) var(--tk-s-5); border: none; background: transparent;
  cursor: pointer; font-size: var(--tk-fz-lg); font-weight: var(--tk-fw-semibold);
  color: var(--tk-text-muted); border-bottom: 2px solid transparent;
  margin-bottom: -1px;
  transition: color var(--tk-anim-fast), border-color var(--tk-anim-fast);
  font-family: inherit;
}
.rom-page-tab.active { color: var(--tk-accent-text); border-bottom-color: var(--tk-accent); }
.rom-page-tab:hover { color: var(--tk-text); }
.rom-page-tab:focus-visible { outline: none; box-shadow: var(--tk-focus-ring); }

/* ═══ Строки с полями ═══ */
.rom-date-row {
  display: flex; align-items: center; gap: var(--tk-s-2);
  margin-bottom: var(--tk-s-4); flex-wrap: wrap;
}
.rom-date-row label {
  font-size: var(--tk-fz-md); font-weight: var(--tk-fw-semibold); color: var(--tk-text-secondary);
}
.rom-date-row input[type="date"] {
  padding: 6px var(--tk-s-3); border: 1px solid var(--tk-border); border-radius: var(--tk-r-md);
  font-size: var(--tk-fz-md); font-family: inherit; color: var(--tk-text); background: var(--tk-bg-card);
}
.rom-select {
  padding: 6px var(--tk-s-3); border: 1px solid var(--tk-border); border-radius: var(--tk-r-md);
  font-size: var(--tk-fz-md); font-family: inherit; min-width: 200px;
  color: var(--tk-text); background: var(--tk-bg-card);
}
.rom-input {
  padding: 6px var(--tk-s-3); border: 1px solid var(--tk-border); border-radius: var(--tk-r-md);
  font-size: var(--tk-fz-md); font-family: inherit; color: var(--tk-text); background: var(--tk-bg-card);
}
.rom-select:focus, .rom-input:focus, .rom-date-row input[type="date"]:focus {
  outline: none; border-color: var(--tk-accent); box-shadow: var(--tk-focus-ring);
}

/* ═══ Счётчики ═══ */
.rom-stats {
  display: flex; gap: var(--tk-s-2); margin-bottom: var(--tk-s-4);
  align-items: center; flex-wrap: wrap;
}
.rom-stat {
  background: var(--tk-bg-card); padding: var(--tk-s-2) var(--tk-s-4);
  border: 1px solid var(--tk-border); border-radius: var(--tk-r-md);
  display: flex; align-items: baseline; gap: var(--tk-s-2);
}
.rom-stat-value {
  font-size: var(--tk-fz-h2); font-weight: var(--tk-fw-bold);
  color: var(--tk-text); line-height: var(--tk-lh-tight);
}
.rom-stat-pending { color: var(--tk-text-muted); }
.rom-stat-label { font-size: var(--tk-fz-sm); color: var(--tk-text-muted); }

.rom-export-row { display: flex; gap: var(--tk-s-2); margin-bottom: var(--tk-s-1); flex-wrap: wrap; align-items: center; }
.so-export-date-picker {
  display: flex; flex-wrap: wrap; gap: var(--tk-s-2); align-items: center;
  padding: var(--tk-s-2) var(--tk-s-3); margin-bottom: var(--tk-s-3);
  background: var(--tk-n-50); border: 1px solid var(--tk-border); border-radius: var(--tk-r-md);
  font-size: var(--tk-fz-md);
}
.so-export-date-hint { color: var(--tk-text-secondary); font-weight: var(--tk-fw-semibold); margin-right: var(--tk-s-1); }
.so-export-date-check { display: flex; align-items: center; gap: var(--tk-s-1); cursor: pointer; color: var(--tk-text); }
.so-export-date-check input { cursor: pointer; accent-color: var(--tk-accent); }

.rom-loading { padding: var(--tk-s-7); text-align: center; color: var(--tk-text-muted); }
.rom-empty { padding: var(--tk-s-7); text-align: center; color: var(--tk-text-muted); font-size: var(--tk-fz-lg); }

/* ═══ Обычные таблицы ═══ */
.rom-table-wrap { overflow-x: auto; }
.rom-table {
  width: 100%; border-collapse: collapse; background: var(--tk-bg-card);
  border-radius: var(--tk-r-md); overflow: hidden;
}
.rom-table th {
  padding: var(--tk-s-2) var(--tk-s-3); font-size: var(--tk-fz-sm); color: var(--tk-text-muted);
  text-align: left; border-bottom: 1px solid var(--tk-border);
  background: var(--tk-n-50); font-weight: var(--tk-fw-semibold);
}
.rom-table td {
  padding: var(--tk-s-2) var(--tk-s-3); border-bottom: 1px solid var(--tk-border-soft);
  font-size: var(--tk-fz-md); color: var(--tk-text);
}
.rom-td-num { font-weight: var(--tk-fw-bold); }
.rom-td-time { font-size: var(--tk-fz-sm); color: var(--tk-text-muted); }
/* Ячейка с кнопками. display:flex на <td> ломает расчёт ширины колонок
   (шапка и строки расходятся), поэтому оставляем ячейку ячейкой. */
.rom-td-actions { white-space: nowrap; }
.rom-td-actions .rom-btn-sm + .rom-btn-sm { margin-left: var(--tk-s-1); }
/* В таблицах-списках содержимое читается слева: глобальное правило
   `td { text-align: center }` из старых стилей здесь неуместно. */
/* !important — в старом глобальном style.css есть
   `thead th:nth-child(2) { text-align: center !important }`, перебить его
   обычным правилом нельзя. Действует только на эти три таблицы. */
.so-ov-table td, .so-ov-table th,
.so-list-table td, .so-list-table th,
.so-tpl-table td, .so-tpl-table th,
.so-modal-table td, .so-modal-table th { text-align: left !important; }

/* Шапка модалки заявки: пары «поле — значение» в две колонки вместо
   абзацев с жирным началом. */
.so-modal-facts {
  display: grid; grid-template-columns: max-content 1fr;
  gap: var(--tk-s-1) var(--tk-s-3); margin: 0 0 var(--tk-s-4);
}
.so-modal-facts dt { font-size: var(--tk-fz-sm); color: var(--tk-text-muted); }
.so-modal-facts dd { margin: 0; font-size: var(--tk-fz-md); color: var(--tk-text); }
.rom-row-submitted { background: var(--tk-success-soft); }
.rom-status {
  padding: 2px var(--tk-s-2); border-radius: var(--tk-r-sm);
  font-size: var(--tk-fz-xs); font-weight: var(--tk-fw-semibold);
}
.st-submitted { background: var(--tk-success-soft); color: var(--tk-success); }
.st-edited { background: var(--tk-info-soft); color: var(--tk-info); }
.st-draft { background: var(--tk-n-100); color: var(--tk-text-muted); }
/* «Не подано» — обычное состояние дня, а не ошибка: нейтральный чип. */
.st-none { background: var(--tk-n-100); color: var(--tk-text-secondary); }
.st-locked { background: var(--tk-warning-soft); color: var(--tk-warning); }
.st-skip { background: var(--tk-n-100); color: var(--tk-text-muted); }
.so-auto-badge {
  display: inline-flex; align-items: center; margin-left: var(--tk-s-2); padding: 2px 6px;
  border-radius: var(--tk-r-sm); background: var(--tk-warning-soft); color: var(--tk-warning);
  font-size: var(--tk-fz-xs); font-weight: var(--tk-fw-semibold);
}
.so-auto-detail {
  display: inline-block; margin: var(--tk-s-1) 0 var(--tk-s-2); padding: 6px var(--tk-s-3);
  border-radius: var(--tk-r-sm); background: var(--tk-warning-soft); color: var(--tk-warning);
  font-size: var(--tk-fz-md); font-weight: var(--tk-fw-semibold);
}
/* «Поставка не нужна» — тоже поданная заявка, но нулевая. Раньше эти строки
   красились в --tk-n-50, тот же цвет, что и полоски зебры, и на глаз были
   неотличимы от «Не подано». Берём оттенок темнее. */
.so-row-skip { background: var(--tk-n-100) !important; }
.so-row-skip:hover { background: var(--tk-n-200) !important; }
.so-td-skip-cell { background: var(--tk-n-100); }
.so-qty-zero { color: var(--tk-text-muted); font-weight: var(--tk-fw-semibold); }

/* ═══ Модалка ═══ */
.rom-modal-overlay {
  position: fixed; inset: 0; background: var(--tk-bg-overlay);
  display: flex; align-items: center; justify-content: center;
  z-index: var(--tk-z-modal); padding: var(--tk-s-5);
}
.rom-modal {
  background: var(--tk-bg-popover); border-radius: var(--tk-r-lg); width: 100%;
  max-width: 500px; max-height: 85vh; overflow-y: auto;
  box-shadow: var(--tk-shadow-modal);
}
.rom-modal-header {
  display: flex; justify-content: space-between; align-items: center;
  padding: var(--tk-s-4) var(--tk-s-5); border-bottom: 1px solid var(--tk-border);
}
.rom-modal-header h3 { margin: 0; font-size: var(--tk-fz-h1); color: var(--tk-text); }
.rom-modal-close {
  background: none; border: none; cursor: pointer;
  font-size: var(--tk-fz-h1); color: var(--tk-text-muted); padding: var(--tk-s-1);
}
.rom-modal-close:hover { color: var(--tk-text); }
.rom-modal-body { padding: var(--tk-s-5); }

.rom-input-sm {
  padding: 4px 6px; border: 1px solid var(--tk-border); border-radius: var(--tk-r-sm);
  font-size: var(--tk-fz-md); font-family: inherit; color: var(--tk-text); background: var(--tk-bg-card);
}
.rom-input-sm:focus { outline: none; border-color: var(--tk-accent); box-shadow: var(--tk-focus-ring); }
.so-date-nav { display: flex; gap: var(--tk-s-1); flex-wrap: wrap; }
/* Выбранный день — подсветка, а не заливка: заливку акцентом на странице
   держит только главное действие, иначе взгляду не за что зацепиться. */
.so-date-active {
  background: var(--tk-accent-soft); color: var(--tk-accent-text);
  border-color: var(--tk-accent); font-weight: var(--tk-fw-semibold);
}
.so-date-active:hover { background: var(--tk-accent-soft-strong); border-color: var(--tk-accent); }
.so-schedule-count { font-size: var(--tk-fz-md); color: var(--tk-text-muted); margin: var(--tk-s-2) var(--tk-s-4); }

/* ═══ Обзор по поставщикам ═══ */
/* Имя поставщика — кнопка перехода. Постоянное подчёркивание в таблице
   создаёт визуальный шум, поэтому показываем его только при наведении. */
.so-ov-supplier {
  background: none; border: none; padding: 0;
  color: var(--tk-text); font: inherit; font-weight: var(--tk-fw-semibold);
  cursor: pointer; text-decoration: none; text-underline-offset: 2px;
  text-align: left;
}
.so-ov-supplier:hover { color: var(--tk-accent-text); text-decoration: underline; }
.so-ov-supplier:focus-visible { outline: none; box-shadow: var(--tk-focus-ring); border-radius: var(--tk-r-sm); }
.so-ov-paused {
  margin-left: var(--tk-s-2); font-size: var(--tk-fz-sm);
  color: var(--tk-warning); background: var(--tk-warning-soft);
  border-radius: var(--tk-r-sm); padding: 1px 6px;
}
.so-ov-countdown { display: block; font-size: var(--tk-fz-sm); color: var(--tk-text-muted); margin-top: 2px; }
.so-ov-date-passed { color: var(--tk-text-muted); }
.so-ov-nodelivery { color: var(--tk-text-muted); }
.so-ov-table td.so-ov-empty { text-align: center !important; color: var(--tk-text-muted); padding: var(--tk-s-4); }
.so-ov-ok { color: var(--tk-success); font-weight: var(--tk-fw-semibold); }
.so-ov-warn { color: var(--tk-warning); font-weight: var(--tk-fw-semibold); }
.so-ov-bad { color: var(--tk-danger); font-weight: var(--tk-fw-semibold); }

/* ═══ Шаблон товаров ═══ */
.so-template-search { position: relative; min-width: 260px; }
.so-template-search .rom-input { width: 100%; }
.so-template-dropdown {
  position: absolute; top: calc(100% + var(--tk-s-1)); left: 0; right: 0; z-index: var(--tk-z-dropdown);
  background: var(--tk-bg-popover); border: 1px solid var(--tk-border); border-radius: var(--tk-r-md);
  box-shadow: var(--tk-shadow-popover); max-height: 260px; overflow-y: auto;
}
.so-template-option {
  width: 100%; display: flex; gap: var(--tk-s-2); align-items: center;
  padding: var(--tk-s-2) var(--tk-s-3); border: 0; border-bottom: 1px solid var(--tk-border-soft);
  background: var(--tk-bg-popover); color: var(--tk-text); text-align: left;
  cursor: pointer; font-family: inherit;
}
.so-template-option:hover { background: var(--tk-n-50); }
.so-template-option b { color: var(--tk-text-muted); min-width: 72px; font-size: var(--tk-fz-sm); }
.so-template-product-cell { display: grid; grid-template-columns: minmax(80px, 110px) minmax(160px, 1fr); gap: var(--tk-s-2); }
/* Колонка каталога: длинное название с характеристиками обрезаем многоточием,
   иначе таблица уезжает за правый край и последние колонки не видно. */
.so-tpl-cat { max-width: 240px; }
.so-template-sku-input, .so-template-name-input { width: 100%; }

/* Статус связи строки шаблона с карточкой каталога */
.so-tpl-linked { display: flex; align-items: center; gap: 6px; min-width: 0; }
.so-tpl-linked-mark { flex: 0 0 auto; }
.so-tpl-linked-text { font-size: var(--tk-fz-sm); color: var(--tk-success); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.so-tpl-unlinked { display: flex; align-items: center; gap: var(--tk-s-2); }
.so-tpl-unlinked-mark { font-size: var(--tk-fz-sm); color: var(--tk-warning); white-space: nowrap; }
.so-tpl-link-search { position: relative; display: flex; align-items: center; gap: 6px; }
.so-tpl-link-search .rom-input-sm { flex: 1 1 auto; min-width: 120px; }

/* ═══ Дедлайны по дням ═══ */
.so-deadline-section {
  background: var(--tk-bg-card); border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-card); padding: var(--tk-s-4);
}
.so-section-title { font-size: var(--tk-fz-lg); font-weight: var(--tk-fw-bold); color: var(--tk-text); margin: 0 0 var(--tk-s-1); }
.so-section-hint { font-size: var(--tk-fz-sm); color: var(--tk-text-muted); margin: 0 0 var(--tk-s-3); line-height: var(--tk-lh-base); }
.so-deadline-grid { display: flex; flex-direction: column; gap: var(--tk-s-1); }
/* Сетка, а не flex: у дней недели разная длина названия, и на flex-раскладке
   поля ввода в каждой строке начинались с разного места. */
.so-deadline-row {
  display: grid;
  /* Последняя колонка — пустой «хвост», чтобы поля не растягивались на всю карточку. */
  grid-template-columns: minmax(150px, 190px) 16px 72px 140px max-content 1fr;
  align-items: center; gap: var(--tk-s-2);
  padding: 6px var(--tk-s-3); background: var(--tk-n-50); border-radius: var(--tk-r-md);
}
.so-deadline-row > select, .so-deadline-row > input { min-width: 0; }
.so-deadline-label { min-width: 0; }
.so-dl-day { font-size: var(--tk-fz-md); font-weight: var(--tk-fw-semibold); color: var(--tk-text); }
.so-dl-hint { font-size: var(--tk-fz-xs); color: var(--tk-text-muted); margin-left: var(--tk-s-1); }
.so-deadline-arrow { color: var(--tk-text-muted); font-size: var(--tk-fz-lg); }
.so-dl-toggle {
  padding: 3px var(--tk-s-2); border-radius: var(--tk-r-sm); border: none;
  font-size: var(--tk-fz-xs); font-weight: var(--tk-fw-semibold); cursor: pointer; font-family: inherit;
}
.so-dl-on { background: var(--tk-success-soft); color: var(--tk-success); }
.so-dl-off { background: var(--tk-n-100); color: var(--tk-text-muted); }

/* ═══ Сетка дней доставки ═══ */
.so-sched-filter { display: flex; gap: var(--tk-s-2); align-items: center; margin-bottom: var(--tk-s-2); flex-wrap: wrap; }
.so-grid-table { border-collapse: separate; border-spacing: 0; }
.so-grid-table th, .so-grid-table td { text-align: center; padding: 6px var(--tk-s-1); }
/* Ресторанов под 60 — без липкой шапки к середине списка уже не понять,
   где какой день недели. Чтобы sticky сработал, у обёртки и самой таблицы
   не должно быть своего overflow: иначе шапка липнет к невидимому краю
   обёртки, а не к верху страницы. Таблица узкая, горизонтальный скролл
   ей не нужен. border-collapse: separate — иначе при прокрутке теряется
   нижняя граница шапки. */
.so-grid-wrap { overflow: visible; }
.so-grid-wrap .so-grid-table { overflow: visible; }
.so-grid-table thead th {
  position: sticky; top: 0; z-index: var(--tk-z-sticky);
  background: var(--tk-n-50); box-shadow: inset 0 -1px 0 var(--tk-border);
}
.so-grid-rest { text-align: left !important; min-width: 220px; padding-left: var(--tk-s-2) !important; }
.so-grid-day { width: 44px; font-size: var(--tk-fz-sm); font-weight: var(--tk-fw-bold); color: var(--tk-text-muted); }
.so-grid-rest-cell { text-align: left !important; padding: 5px var(--tk-s-2) !important; white-space: nowrap; }
.so-grid-num {
  font-size: var(--tk-fz-lg); font-weight: var(--tk-fw-bold); color: var(--tk-text);
  background: var(--tk-n-100); padding: 1px 6px; border-radius: var(--tk-r-sm); margin-right: 6px;
}
.so-grid-addr { font-size: var(--tk-fz-xs); color: var(--tk-text-muted); }
.so-grid-check { cursor: pointer; transition: background var(--tk-anim-fast); user-select: none; }
.so-grid-check:hover { background: var(--tk-accent-soft); }
.so-grid-check input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; accent-color: var(--tk-accent); }

/* ═══ Сессии ═══ */
.so-sessions-list { display: flex; flex-direction: column; gap: var(--tk-s-2); }
.so-session-card {
  background: var(--tk-bg-card); border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-card); padding: var(--tk-s-3) var(--tk-s-4);
  cursor: pointer; border-left: 3px solid var(--tk-success);
  transition: border-color var(--tk-anim-fast), box-shadow var(--tk-anim-fast);
}
.so-session-card:hover { box-shadow: var(--tk-shadow-card-hover); }
.so-session-card.closed { border-left-color: var(--tk-n-300); opacity: 0.7; }
.so-session-header { display: flex; align-items: center; gap: var(--tk-s-2); margin-bottom: var(--tk-s-1); }
.so-session-name { font-size: var(--tk-fz-lg); font-weight: var(--tk-fw-bold); color: var(--tk-text); }
.so-session-status {
  font-size: var(--tk-fz-xs); padding: 2px var(--tk-s-2);
  border-radius: var(--tk-r-sm); font-weight: var(--tk-fw-semibold);
}
.st-sess-active { background: var(--tk-success-soft); color: var(--tk-success); }
.st-sess-closed { background: var(--tk-n-100); color: var(--tk-text-muted); }
.so-session-meta { font-size: var(--tk-fz-sm); color: var(--tk-text-muted); }

/* ═══ Шапка поставщика ═══ */
.so-detail-bar {
  display: flex; align-items: center; gap: var(--tk-s-2); margin-bottom: var(--tk-s-4);
  flex-wrap: wrap; padding: var(--tk-s-2) 0;
}
.so-detail-name { font-size: var(--tk-fz-xl); font-weight: var(--tk-fw-bold); color: var(--tk-text); }
.so-detail-actions { display: flex; gap: 6px; margin-left: auto; flex-wrap: wrap; }

/* ═══ Форма сессии ═══ */
.so-form-row { display: flex; align-items: center; gap: var(--tk-s-2); margin-bottom: var(--tk-s-3); }
.so-form-row label {
  font-size: var(--tk-fz-md); font-weight: var(--tk-fw-semibold);
  color: var(--tk-text-secondary); min-width: 130px;
}
.so-form-row input {
  flex: 1; padding: 7px var(--tk-s-3); border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-md); font-size: var(--tk-fz-lg); font-family: inherit;
  color: var(--tk-text); background: var(--tk-bg-card);
}
.so-form-row input:focus { outline: none; border-color: var(--tk-accent); box-shadow: var(--tk-focus-ring); }
.so-form-actions { display: flex; gap: var(--tk-s-2); justify-content: flex-end; margin-top: var(--tk-s-4); }

/* ═══ Сводная таблица заявок ═══ */
.so-filter-check {
  display: flex; align-items: center; gap: var(--tk-s-1);
  font-size: var(--tk-fz-md); color: var(--tk-text); cursor: pointer; white-space: nowrap;
}
.so-filter-check input { accent-color: var(--tk-accent); }
.so-filter-input {
  width: 180px; padding: 6px var(--tk-s-3); border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-md); font-size: var(--tk-fz-md); font-family: inherit;
  background: var(--tk-bg-card); color: var(--tk-text);
}
.so-filter-input:focus { outline: none; border-color: var(--tk-accent); box-shadow: var(--tk-focus-ring); }

.rom-table-wrap:has(.so-pivot-table) {
  border: 1px solid var(--tk-border); border-radius: var(--tk-r-card);
}

.so-pivot-table { border-collapse: separate; border-spacing: 0; min-width: 500px; }

/* Шапка светлая: тёмная заливка перетягивала на себя всё внимание. */
.so-pivot-table th {
  background: var(--tk-n-50); color: var(--tk-text-secondary);
  padding: var(--tk-s-2) var(--tk-s-2); font-size: var(--tk-fz-xs); font-weight: var(--tk-fw-semibold);
  text-align: left; white-space: nowrap;
  position: sticky; top: 0; z-index: var(--tk-z-sticky);
  border-bottom: 1px solid var(--tk-border);
  border-right: 1px solid var(--tk-border-soft);
}
.so-pivot-table th:last-child { border-right: none; }

.so-pivot-table td {
  padding: 7px var(--tk-s-2); border-bottom: 1px solid var(--tk-border-soft);
  border-right: 1px solid var(--tk-border-soft); font-size: var(--tk-fz-md); color: var(--tk-text);
  vertical-align: middle;
}
.so-pivot-table td:last-child { border-right: none; }

.so-pivot-table tbody tr:nth-child(even) { background: var(--tk-n-50); }
.so-pivot-table tbody tr:hover { background: var(--tk-accent-soft); }
/* Зелёная подсветка подавших заявку обязана быть сильнее зебры и наведения:
   иначе серая полоска чётных строк перекрывает её, и зелёными выглядят
   только нечётные строки — со стороны это читается как случайный набор. */
.so-pivot-table tbody tr.rom-row-submitted { background: var(--tk-success-soft); }
.so-pivot-table tbody tr.rom-row-submitted:hover {
  background: linear-gradient(var(--tk-success-soft), var(--tk-success-soft)), var(--tk-success-soft);
}

.so-th-rest { min-width: 200px; }
.so-th-status { min-width: 70px; text-align: center; }
.so-th-qty { text-align: center !important; min-width: 120px; }
.so-th-prod {
  font-size: var(--tk-fz-xs); font-weight: var(--tk-fw-semibold); color: var(--tk-text);
  line-height: var(--tk-lh-base); white-space: normal; text-transform: none;
}
.so-th-mult { font-size: var(--tk-fz-xs); color: var(--tk-text-muted); font-weight: var(--tk-fw-regular); }

.so-td-rest {
  white-space: nowrap; max-width: 280px;
  border-right: 1px solid var(--tk-border) !important;
}
.so-rest-addr { font-size: var(--tk-fz-xs); color: var(--tk-text-muted); margin-left: 6px; }
.rom-td-num { font-weight: var(--tk-fw-bold); color: var(--tk-text-secondary); display: inline-block; min-width: 24px; }

.so-td-qty {
  text-align: center; cursor: pointer; min-width: 65px;
  transition: background var(--tk-anim-fast);
}
.so-td-qty:hover { background: var(--tk-accent-soft-strong); }

.so-qty { font-weight: var(--tk-fw-semibold); color: var(--tk-text); }
.so-qty-admin { font-weight: var(--tk-fw-bold); color: var(--tk-accent-text); }
.so-qty-empty { color: var(--tk-n-300); font-size: var(--tk-fz-lg); }

.so-cell-input {
  width: 56px; padding: 3px var(--tk-s-1); border: 1px solid var(--tk-accent);
  border-radius: var(--tk-r-sm); text-align: center; font-size: var(--tk-fz-md);
  font-weight: var(--tk-fw-semibold); font-family: inherit; color: var(--tk-text);
  background: var(--tk-bg-card); outline: none; box-shadow: var(--tk-focus-ring);
}

.so-td-total { background: var(--tk-n-50) !important; font-size: var(--tk-fz-lg); }
.so-totals-row td {
  border-top: 1px solid var(--tk-border);
  padding: var(--tk-s-2);
  color: var(--tk-text);
  font-weight: var(--tk-fw-semibold);
}

.so-tpl-sku { font-size: var(--tk-fz-xs); color: var(--tk-text-muted); margin-right: var(--tk-s-1); font-weight: var(--tk-fw-semibold); }

/* ═══ Продления и закрытые дни ═══ */
.so-override-chip {
  display: inline-flex; align-items: center; gap: var(--tk-s-1);
  padding: 3px var(--tk-s-2); background: var(--tk-warning-soft); color: var(--tk-warning);
  border: 1px solid transparent; border-radius: var(--tk-r-pill);
  font-size: var(--tk-fz-xs); font-weight: var(--tk-fw-semibold);
}
.so-override-del {
  background: none; border: none; color: inherit; cursor: pointer;
  font-size: var(--tk-fz-lg); line-height: 1; padding: 0 2px; opacity: 0.7;
}
.so-override-del:hover { opacity: 1; }
.so-override-chip-closed { background: var(--tk-danger-soft); color: var(--tk-danger); }
.so-override-chip-closed .so-override-del { color: inherit; }
.so-day-closed-btn { background: var(--tk-danger-soft) !important; color: var(--tk-danger) !important; border-color: var(--tk-border) !important; }
.so-btn-close-day { background: var(--tk-bg-card); color: var(--tk-danger); border-color: var(--tk-border); }
.so-btn-close-day:hover { background: var(--tk-danger-soft); border-color: var(--tk-danger); }
.so-btn-open-day { background: var(--tk-bg-card); color: var(--tk-success); border-color: var(--tk-border); }
.so-btn-open-day:hover { background: var(--tk-success-soft); border-color: var(--tk-success); }

/* ═══ Блоки-карточки (уведомления, настройки) ═══ */
.so-notify-box {
  margin-top: var(--tk-s-3);
  padding: var(--tk-s-4);
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-card);
  background: var(--tk-bg-card);
}
.so-notify-head {
  display: flex; align-items: flex-start; justify-content: space-between;
  gap: var(--tk-s-3); margin-bottom: var(--tk-s-3);
}
/* Кнопка сохранения в шапке карточки не переносится: что именно сохраняем,
   написано в заголовке слева. */
.so-notify-head > .rom-btn-sm { flex: 0 0 auto; white-space: nowrap; }
.so-notify-users {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: var(--tk-s-2) var(--tk-s-3);
}
.so-notify-user {
  display: flex; align-items: start; gap: var(--tk-s-2);
  padding: var(--tk-s-2) var(--tk-s-2); border: 1px solid var(--tk-border-soft);
  border-radius: var(--tk-r-md); background: var(--tk-n-50);
  font-size: var(--tk-fz-md); color: var(--tk-text);
}
.so-notify-user input { margin-top: 3px; flex: 0 0 auto; }
/* Имя — строкой, должность и пометки — под ним. Раньше всё шло вподбор
   и в карточке получалось три рваных переноса. */
.so-notify-user-text { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.so-notify-user-name { font-weight: var(--tk-fw-medium); }
.so-notify-user small { color: var(--tk-text-muted); font-size: var(--tk-fz-xs); }
/* «нет Telegram» — справочная пометка, а не предупреждение: жёлтым она
   повторялась в каждой второй карточке и превращалась в шум. */
.so-notify-muted { color: var(--tk-text-muted) !important; }

/* Вкладка «Настройки» */
.so-settings-wrap { display: flex; flex-direction: column; gap: var(--tk-s-4); max-width: 860px; }
.so-settings-block {
  padding: var(--tk-s-4);
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-card);
  background: var(--tk-bg-card);
}
.so-settings-check {
  display: flex; align-items: center; gap: var(--tk-s-2);
  padding: 6px 0; font-size: var(--tk-fz-md); color: var(--tk-text); cursor: pointer;
}
.so-settings-check input { accent-color: var(--tk-accent); }
.so-reminder-group { margin-top: var(--tk-s-3); }
.so-reminder-title {
  font-size: var(--tk-fz-md); font-weight: var(--tk-fw-bold);
  color: var(--tk-text); margin-bottom: var(--tk-s-1);
}
.so-reminder-checks {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 2px var(--tk-s-3);
}
/* Мелкие подписи в строках управления (были инлайн-стилями в разметке). */
.so-inline-label { font-size: var(--tk-fz-sm); color: var(--tk-text-muted); }
.so-field-label {
  display: block; font-size: var(--tk-fz-sm); color: var(--tk-text-muted);
  margin-bottom: var(--tk-s-1);
}
/* Плашка «приём приостановлен» над формой. */
.so-paused-note {
  background: var(--tk-warning-soft); padding: var(--tk-s-2) var(--tk-s-3);
  border-radius: var(--tk-r-md); margin-top: var(--tk-s-2);
}
/* Маркер привязки строки шаблона к карточке каталога. */
.so-tpl-linked-mark { color: var(--tk-success); font-weight: var(--tk-fw-bold); }

.so-temp-actions { display: flex; gap: var(--tk-s-2); align-items: center; flex-wrap: wrap; }
.so-temp-period { display: flex; gap: var(--tk-s-3); align-items: end; flex-wrap: wrap; margin-top: var(--tk-s-3); }
.so-temp-period label {
  display: flex; flex-direction: column; gap: var(--tk-s-1);
  font-size: var(--tk-fz-sm); color: var(--tk-text-muted);
}

/* ═══ Телефон ═══
   На узком экране раскладка ломалась: вкладки налезали друг на друга и
   «Настройки» уходили за край, кнопки шли по одной в строку на шесть
   экранов прокрутки, а сводную таблицу занимала колонка с адресом — ни
   статуса, ни товаров видно не было. */
@media (max-width: 640px) {
  /* Вкладки прокручиваются вбок, а не сжимаются до наложения. */
  .rom-page-tabs {
    flex-wrap: nowrap; overflow-x: auto; scrollbar-width: none;
    -webkit-overflow-scrolling: touch;
  }
  .rom-page-tabs::-webkit-scrollbar { display: none; }
  .rom-page-tab {
    flex: 0 0 auto; white-space: nowrap;
    padding: var(--tk-s-3) var(--tk-s-3); font-size: var(--tk-fz-md);
  }

  /* Панель действий — две колонки вместо простыни в один столбец. */
  .rom-export-row { display: grid; grid-template-columns: 1fr 1fr; align-items: stretch; }
  .rom-export-row .rom-btn {
    width: 100%; justify-content: center; text-align: center;
    padding-left: var(--tk-s-2); padding-right: var(--tk-s-2);
  }
  /* Поиск и фильтр — во всю ширину, они длиннее кнопок. */
  .rom-export-row .so-filter-check,
  .rom-export-row .so-filter-input { grid-column: 1 / -1; width: 100%; }

  /* Счётчики в ряд по трети экрана. */
  .rom-stats { display: grid; grid-template-columns: repeat(3, 1fr); }
  .rom-stat { justify-content: center; padding: var(--tk-s-2); }

  /* Сводная таблица: адрес прячем — номер ресторана и так опознаётся,
     а места хватает на статус и заказанные товары. */
  .so-rest-addr { display: none; }
  /* overflow: hidden у таблицы (он нужен для скругления углов) обрезает
     позиционирование sticky у её ячеек — на телефоне скругление не жалко. */
  .so-pivot-table { overflow: visible; }
  /* Колонка с номером ресторана держится у левого края при прокрутке вбок,
     иначе, добравшись до товара, уже не понять, чей это заказ.
     Фон обязательно непрозрачный — подсветка строки полупрозрачная, и
     содержимое таблицы просвечивало бы сквозь залипшую колонку. */
  .so-pivot-table .so-th-rest,
  .so-pivot-table .so-td-rest {
    position: sticky; left: 0; z-index: var(--tk-z-sticky);
    min-width: 0; white-space: nowrap;
    background: var(--tk-bg-card);
    box-shadow: inset -1px 0 0 var(--tk-border);
  }
  .so-pivot-table .so-th-rest { background: var(--tk-n-50); }
  .so-pivot-table .rom-row-submitted .so-td-rest {
    background: linear-gradient(var(--tk-success-soft), var(--tk-success-soft)), var(--tk-bg-card);
  }
  .so-pivot-table .so-row-skip .so-td-rest { background: var(--tk-n-100) !important; }

  /* Настройки: карточки во всю ширину, кнопка сохранения под заголовком. */
  .so-settings-wrap { max-width: none; }
  .so-notify-head { flex-direction: column; align-items: stretch; gap: var(--tk-s-2); }
  .so-notify-head > .rom-btn-sm { align-self: flex-start; }

  /* Дедлайны: сетка в шесть колонок не помещается — название дня уходит
     отдельной строкой, под ним день, время и переключатель. */
  .so-deadline-row { display: flex; flex-wrap: wrap; align-items: center; }
  .so-deadline-label { flex: 1 0 100%; }
  .so-deadline-arrow { display: none; }

  /* Обзор: пять действий в два столбца — иначе колонка шире экрана.
     Имя поставщика липнет к левому краю при прокрутке вбок. */
  .so-ov-actions { grid-template-columns: 1fr 1fr; }
  /* В два столбца подписи вроде «Дедлайн» обрезались — уменьшаем шрифт. */
  .so-ov-actions .rom-btn-sm { font-size: var(--tk-fz-xs); padding: 4px 6px; }
  .so-ov-table { overflow: visible; }
  .so-ov-table tbody td:first-child,
  .so-ov-table thead th:first-child {
    position: sticky; left: 0; z-index: var(--tk-z-sticky);
    background: var(--tk-bg-card);
    box-shadow: inset -1px 0 0 var(--tk-border);
  }
  .so-ov-table thead th:first-child { background: var(--tk-n-50); }
  /* «на паузе» рядом с именем, а не переносом посреди названия. */
  .so-ov-paused { display: inline-block; white-space: nowrap; }

  /* Список заявок: адрес занимал треть экрана и рвался на три строки —
     ресторан опознаётся по номеру. Номер липнет к левому краю. */
  .so-list-table thead th:nth-child(2),
  .so-list-table tbody td:nth-child(2) { display: none; }
  .so-list-table { overflow: visible; }
  .so-list-table thead th:first-child,
  .so-list-table tbody td:first-child {
    position: sticky; left: 0; z-index: var(--tk-z-sticky);
    background: var(--tk-bg-card);
    box-shadow: inset -1px 0 0 var(--tk-border);
  }
  .so-list-table thead th:first-child { background: var(--tk-n-50); }
}

@media (prefers-reduced-motion: reduce) {
  .rom-btn, .rom-btn-sm, .rom-page-tab, .so-td-qty, .so-grid-check, .so-session-card {
    transition: none;
  }
}
</style>
