<template>
  <div :class="supplierId ? '' : 'rom-page'">
    <!-- Toolbar — показываем только если не embedded (нет пропа supplierId) -->
    <div v-if="!supplierId" class="rom-toolbar">
      <h1>Заявки поставщикам</h1>
    </div>

    <!-- Разделы модуля. Скрываются, когда экран встроен в чужой модуль
         со своей навигацией: две полосы вкладок подряд читаются как ошибка. -->
    <div v-if="!hideTabs" class="so-seg so-seg-tabs">
      <button v-for="t in PAGE_TABS" :key="t.key"
              class="so-seg-btn" :class="{ active: pageTab === t.key }"
              @click="switchPageTab(t.key)">
        {{ t.label }}
      </button>
    </div>

    <!-- Supplier selector — только если supplierId не передан через проп -->
    <div v-if="!supplierId && pageTab !== 'overview'" class="rom-date-row">
      <label>Поставщик:</label>
      <select v-model="currentSupplierId" @change="onSupplierChange" class="rom-select">
        <option value="">— выберите —</option>
        <option v-for="s in pickerSuppliers" :key="s.id" :value="s.id">
          {{ s.short_name }} ({{ s.restaurant_count }} рест.)
        </option>
      </select>
    </div>

    <!-- ═══ TAB: Обзор ═══ -->
    <template v-if="pageTab === 'overview'">
      <div class="so-panel">
        <label class="so-panel-label">Дата доставки:</label>
        <input type="date" v-model="overviewDate" @change="loadOverview" class="rom-input-sm" style="width:160px" />
        <button class="so-icon-btn" :disabled="overviewLoading" @click="loadOverview" title="Обновить" aria-label="Обновить">
          <span :class="{ 'is-spin': overviewLoading }">⟳</span>
        </button>
      </div>

      <div v-if="overviewLoading" class="rom-loading"><BurgerSpinner text="Загрузка..." /></div>
      <div v-else class="so-card so-card-flush">
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
                  <span :class="{ 'so-ov-date-passed': overviewIsPassed(row) }">{{ fmtDeadlineHuman(row.deadline_str) || '—' }}</span>
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
                  <button class="so-chip-btn" @click="overviewSendEmail(row)"
                    :disabled="!row.has_email || isOverviewBusy(row)"
                    :title="!row.has_email ? 'У поставщика не указана почта' : 'Отправить сводку на почту поставщика'">Почта</button>
                  <button class="so-chip-btn" @click="overviewSendTelegram(row)"
                    :disabled="isOverviewBusy(row)" title="Отправить сводку в Telegram">Telegram</button>
                  <button class="so-chip-btn" @click="overviewExtend(row)"
                    :disabled="isOverviewBusy(row)" title="Продлить дедлайн">Дедлайн</button>
                  <button class="so-chip-btn" @click="overviewRemind(row)"
                    :disabled="isOverviewBusy(row) || !(row.has_schedule && row.submitted_count < row.expected_count && !row.forced_closed && !overviewIsPassed(row))"
                    title="Напомнить не подавшим заявку">Напомнить</button>
                  <button class="so-chip-btn" :class="row.forced_closed ? 'is-open-day' : 'is-close-day'"
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
          <button class="rom-btn rom-btn-outline" @click="copyLink">Ссылка</button>
        </div>
      </div>

      <!-- Даты поставки: лента, прокручивается вбок -->
      <div class="so-dates">
        <div class="so-dates-strip">
          <button v-for="wd in weekDates" :key="wd.date"
            class="so-date"
            :class="{
              'is-active': selectedDate === wd.date,
              'is-closed': wdClosed(wd),
              'is-forced': wdForced(wd),
              'is-adhoc': wd.is_adhoc,
            }"
            @click="selectedDate = wd.date; loadStatus()"
            :title="wdTitle(wd)">
            <span class="so-date-day">{{ wd.day_name }}</span>
            <span class="so-date-num">{{ formatDateShort(wd.date) }}</span>
            <span class="so-date-state" :class="wdClosed(wd) ? 'closed' : 'open'">
              {{ wdForced(wd) ? 'закрыт' : (wdClosed(wd) ? 'приём окончен' : 'приём идёт') }}
            </span>
            <span v-if="wd.is_adhoc" class="so-date-tag">довоз</span>
          </button>
        </div>
        <div class="so-dates-side">
          <input type="date" v-model="selectedDate" @change="loadStatus" class="rom-input-sm so-date-picker" />
          <button v-if="selectedDate" class="so-mini-btn" @click="handleExtendDeadline" title="Разовое продление дедлайна на эту дату">
            Продлить дедлайн
          </button>
          <!-- Кнопка соответствует состоянию: закрывать день, где приём уже
               окончился по дедлайну, незачем — там предлагаем продлить. -->
          <button v-if="selectedDate && (isDateForcedClosed(selectedDate) || !dayIsClosed)"
            class="so-mini-btn" :class="isDateForcedClosed(selectedDate) ? 'is-open-day' : 'is-close-day'"
            @click="handleToggleCloseDay(selectedDate)"
            :title="isDateForcedClosed(selectedDate) ? 'Открыть день для подачи заявок' : 'Закрыть день — рестораны не смогут подавать заявки'">
            {{ isDateForcedClosed(selectedDate) ? 'Открыть день' : 'Закрыть день' }}
          </button>
          <span v-else-if="selectedDate && dayIsClosed" class="so-day-note">
            приём окончен{{ selectedDayDeadlineFmt ? ' · ' + selectedDayDeadlineFmt : '' }}
          </span>
        </div>
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

          <!-- Действия. Всё на виду: пряталось в меню — приходилось искать.
               Редкое и второстепенное сделано короче и собрано по смыслу. -->
          <div class="so-actions">
            <button class="so-btn so-btn-primary" @click="sendSummary"
                    :disabled="sendingSummary || !selectedDate"
                    title="Сгенерировать Excel и отправить подписчикам в Telegram">
              <BurgerSpinner v-if="sendingSummary" size="xs" />
              <span>{{ sendingSummary ? 'Отправка…' : 'Отправить сводку' }}</span>
            </button>
            <button class="so-btn" @click="sendSummaryEmail" :disabled="sendingSummaryEmail || !selectedDate"
                    :title="supplierIsWeekly
                      ? 'Собрать Excel за всю неделю доставки (вкладка на день) и отправить одним письмом'
                      : 'Сгенерировать Excel и отправить на почту поставщика'">
              {{ sendingSummaryEmail ? 'Отправка…' : (supplierIsWeekly ? 'На почту за неделю' : 'На почту') }}
            </button>
            <span v-if="dayEmailLabel" class="so-mail-state" :class="dayEmailLabel.ok ? 'is-ok' : 'is-bad'"
                  :title="dayEmailStatus?.recipients || ''">
              {{ dayEmailLabel.text }}
            </span>

            <!-- У цеха своя выгрузка: неделя двумя листами (пн-вт-ср, чт-пт-сб),
                 выбирать дни там нечего. У остальных — Excel по выбранным дням. -->
            <button v-if="loadingSheetsAvailable" class="so-btn" @click="exportWorkshopWeek"
                    :disabled="exporting || !selectedDate"
                    title="Заказ теста на всю неделю: два листа по три дня">
              {{ exporting ? 'Собираю…' : 'Заказ на неделю' }}
              <span v-if="!exporting && weekRangeLabel" class="so-split-count">{{ weekRangeLabel }}</span>
            </button>
            <div v-else class="so-split">
              <button class="so-btn so-split-main" @click="exportExcel" :disabled="exporting || exportSelectedDates.size === 0">
                {{ exporting ? 'Выгрузка…' : 'Excel' }}
                <span v-if="!exporting" class="so-split-count">{{ exportLabel }}</span>
              </button>
              <button class="so-btn so-split-arrow" :class="{ active: exportDatePickerOpen }"
                      title="Выбрать дни для выгрузки"
                      @click="exportDatePickerOpen = !exportDatePickerOpen">▾</button>
            </div>

            <button v-if="loadingSheetsAvailable" class="so-btn" @click="downloadLoadingSheets"
                    :disabled="loadingSheetsBusy || !selectedDate"
                    title="Excel с загрузочными листами: по стопке на лист, первый лист — навигация">
              <BurgerSpinner v-if="loadingSheetsBusy" size="xs" />
              <span>{{ loadingSheetsBusy ? 'Готовлю…' : 'Загрузочные листы' }}</span>
            </button>
            <button class="so-btn so-btn-accent" @click="openAdhocModal"
                    title="Создать внеплановую заявку (довоз) для ресторана на любую дату вне графика">
              + Довоз
            </button>

            <!-- Всё про тех, кто не подал заявку, — одной группой. -->
            <div class="so-group">
              <span class="so-group-label">Не подавшим:</span>
              <button class="so-chip-btn" :disabled="!selectedDate" @click="copyMissingRestaurants"
                      title="Скопировать номера ресторанов, которые не подали заявку">копировать</button>
              <button class="so-chip-btn" :disabled="!selectedDate || remindingStatus" @click="remindUnsubmitted"
                      title="Напомнить ресторанам, которые не подали заявку">
                {{ remindingStatus ? 'отправка…' : 'напомнить' }}
              </button>
            </div>

            <div class="so-actions-right">
              <label class="so-filter-check">
                <input type="checkbox" v-model="showMissing" /> Только не подавшие
              </label>
              <input v-model="filterText" type="text" class="rom-input-sm so-filter-input" placeholder="Поиск ресторана" />
              <button class="so-icon-btn" :disabled="loading" @click="loadStatus" title="Обновить данные" aria-label="Обновить">
                <span :class="{ 'is-spin': loading }">⟳</span>
              </button>
            </div>
          </div>
          <div v-if="exportDatePickerOpen" class="so-export-date-picker">
            <span class="so-export-date-hint">Выберите дни для выгрузки:</span>
            <label v-for="wd in weekDates" :key="wd.date" class="so-export-date-check">
              <input type="checkbox" :checked="exportSelectedDates.has(wd.date)" @change="toggleExportDate(wd.date)" />
              {{ wd.day_name }} {{ formatDateShort(wd.date) }}
            </label>
            <button class="rom-btn-sm" @click="exportSelectCurrent">Только выбранный день</button>
            <button class="rom-btn-sm" @click="exportSelectAll">Все дни</button>
            <button class="rom-btn-sm" @click="exportSelectNone">Снять всё</button>
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
                  <!-- Печать загрузочных листов по ресторану: последняя колонка,
                       после всех позиций теста. Только у ПРЦ. -->
                  <th v-if="loadingSheetsAvailable" class="so-th-print">Лист</th>
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
                    <span v-else class="rom-status" :class="restStatusClass(r)" :title="restStatusTitle(r)">
                      {{ restStatusLabel(r) }}
                    </span>
                    <span v-if="isAutoSubmitted(r)" class="so-auto-badge" :title="autoSubmitTitle(r)">
                      АВТО-ПОДАЧА
                    </span>
                    <span v-if="r.is_adhoc" class="so-adhoc-tag" title="Внеплановая заявка (довоз)">довоз</span>
                  </td>
                  <td v-for="p in displayProducts" :key="p.display_key"
                    class="so-td-qty"
                    :class="{
                      'so-td-skip-cell': isSkipOrder(r),
                      'so-td-bad': !isSkipOrder(r) && cellViolates(r.number, p),
                    }"
                    :title="cellViolates(r.number, p)
                      ? `Не по правилам товара: ${qtyRuleHint(p, cellLegalEntity(r.number, p))}`
                      : (p.is_grouped ? `Объединено из SKU: ${p.source_skus.join(', ')}` : '')"
                    @dblclick="canEditProduct(p) && startEdit(r.number, p.sku)">
                    <template v-if="editCell === `${r.number}_${p.sku}`">
                      <!-- Заказ по партиям: своё поле на каждую, иначе правка
                           ушла бы целиком в первую партию. -->
                      <div v-if="editParts.length > 1" class="so-cell-parts-edit" @focusout="onPartsBlur">
                        <label v-for="pt in editParts" :key="pt.batch" class="so-cell-part">
                          <span class="so-cell-part-n">{{ pt.batch }}</span>
                          <input
                            v-model="pt.value"
                            type="text" inputmode="decimal"
                            class="so-cell-input so-cell-input-part"
                            @keydown.enter="saveEdit"
                            @keydown.escape="cancelEdit"
                          />
                        </label>
                      </div>
                      <input
                        v-else
                        v-model="editValue"
                        type="text" inputmode="decimal"
                        class="so-cell-input"
                        @keydown.enter="saveEdit"
                        @keydown.escape="cancelEdit"
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
                      <!-- Заказ по партиям (цех): под общей цифрой видно, сколько
                           в каждой — цех делает их в разные дни. -->
                      <span v-if="cellParts(r.number, p)" class="so-qty-parts">
                        <i v-for="pt in cellParts(r.number, p)" :key="pt.batch"
                           :title="pt.batch + '-я партия'">{{ formatQtyValue(pt.qty) }}</i>
                      </span>
                    </template>
                  </td>
                  <td v-if="loadingSheetsAvailable" class="so-td-print">
                    <button v-if="r.order_status && !isSkipOrder(r)" class="so-print-ls"
                      @click.stop="printLoadingSheets(r.number)"
                      :title="'Печать загрузочных листов — ' + formatRestaurantNumber(r.number, r.legal_entity_group)">
                      Печать
                    </button>
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
                  <td v-if="loadingSheetsAvailable"></td>
                </tr>
              </tfoot>
            </table>
          </div>
          <UiEmptyState v-else
                        title="Шаблон поставщика пуст"
                        description="Пока в шаблоне нет товаров, рестораны не смогут ничего заказать у этого поставщика. Наполните его во вкладке «Шаблон товаров».">
            <template #icon><BkIcon name="clipboard" size="lg" /></template>
          </UiEmptyState>
        </template>
    </template>

    <!-- ═══ TAB: Список заявок ═══ -->
    <template v-if="pageTab === 'list' && currentSupplierId">
      <!-- Фильтры одной панелью: раньше они шли двумя рядами с разными
           отступами, и было неясно, где заканчивается один и начинается другой. -->
      <div class="so-panel so-filters">
        <div class="so-field">
          <label class="so-field-label">Подано</label>
          <div class="so-field-pair">
            <input type="date" v-model="listSubmittedFrom" class="rom-input-sm" />
            <span class="so-field-dash">—</span>
            <input type="date" v-model="listSubmittedTo" class="rom-input-sm" />
          </div>
        </div>
        <div class="so-field">
          <label class="so-field-label">Доставка</label>
          <div class="so-field-pair">
            <input type="date" v-model="listDeliveryFrom" class="rom-input-sm" />
            <span class="so-field-dash">—</span>
            <input type="date" v-model="listDeliveryTo" class="rom-input-sm" />
          </div>
        </div>
        <div class="so-field">
          <label class="so-field-label">Статус</label>
          <select v-model="listStatus" class="rom-select">
            <option value="">Все</option>
            <option value="submitted">Подано</option>
            <option value="locked">Закрыто</option>
            <option value="draft">Черновик</option>
          </select>
        </div>
        <div class="so-field so-field-grow">
          <label class="so-field-label">Ресторан или адрес</label>
          <input type="text" v-model="listQuery" class="rom-input-sm" placeholder="Номер, город, адрес" />
        </div>
        <label class="so-filter-check so-field-inline">
          <input type="checkbox" v-model="listSkipOnly" /> Только «не нужна»
        </label>
        <div class="so-filters-actions">
          <button class="so-btn so-btn-accent" @click="loadOrdersList" :disabled="loadingList">
            {{ loadingList ? 'Загрузка…' : 'Показать' }}
          </button>
          <button class="so-chip-btn" @click="resetOrdersFilters">Сбросить</button>
        </div>
      </div>

      <div v-if="loadingList" class="rom-loading"><BurgerSpinner text="Загрузка..." /></div>
      <div v-else-if="ordersList.length === 0" class="so-card so-empty-block">
        <div class="so-empty-title">Заявок нет</div>
        <p>За выбранный период заявок не найдено. Попробуйте другие даты или сбросьте фильтры.</p>
      </div>
      <template v-else>
        <div class="so-list-count">Найдено заявок: <b>{{ ordersList.length }}</b></div>
        <div class="so-card so-card-flush">
          <table class="rom-table so-list-table">
            <thead>
              <tr>
                <th>Ресторан</th>
                <th>Подано</th>
                <th>Доставка</th>
                <th>Статус</th>
                <th class="so-list-num">Позиций</th>
                <th class="so-list-num">Кол-во</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="o in ordersList" :key="o.id">
                <td class="so-list-rest">
                  <span class="so-list-rest-num">{{ formatRestaurantNumber(o.restaurant_number, o.legal_entity_group) }}</span>
                  <span class="so-list-rest-addr">{{ o.address }}</span>
                </td>
                <td class="so-list-dim">{{ o.submitted_at ? formatDateTime(o.submitted_at) : '—' }}</td>
                <td>{{ formatDate(o.delivery_date) }}</td>
                <td>
                  <span v-if="Number(o.item_count || 0) === 0 && (o.status === 'submitted' || o.status === 'locked')" class="rom-status st-skip">Не нужна</span>
                  <span v-else class="rom-status" :class="'st-' + o.status">{{ statusLabel(o.status) }}</span>
                  <span v-if="isAutoSubmitted(o)" class="so-auto-badge" :title="autoSubmitTitle(o)">АВТО</span>
                </td>
                <td class="so-list-num">{{ o.item_count || '—' }}</td>
                <td class="so-list-num">{{ o.total_qty ? (Number.isInteger(+o.total_qty) ? +o.total_qty : (+o.total_qty).toFixed(2)) : '—' }}</td>
                <td class="rom-td-actions">
                  <button class="so-chip-btn" @click="viewOrder(o.id)">Открыть</button>
                  <button class="so-chip-btn is-close-day" @click="deleteOrder(o.id, o.status)">Удалить</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </template>

    <!-- ═══ TAB: Графики ═══ -->
    <template v-if="pageTab === 'schedules' && currentSupplierId">
      <div v-if="loadingSchedules" class="rom-loading"><BurgerSpinner text="Загрузка..." /></div>
      <div v-else>
        <!-- Дедлайны по дням недели -->
        <div class="so-card so-deadline-section">
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
          <button class="so-btn so-btn-accent" @click="saveDeadlineRules" :disabled="savingDeadlines" style="margin-top:12px">
            <BurgerSpinner v-if="savingDeadlines" size="xs" />
            <span>{{ savingDeadlines ? 'Сохранение...' : 'Сохранить дедлайны' }}</span>
          </button>
        </div>

        <!-- Графики по ресторанам -->
        <div class="so-card">
        <h3 class="so-section-title">Дни доставки по ресторанам</h3>
        <p class="so-section-hint">Отметьте дни недели, когда ресторан получает поставку.</p>
        <div v-if="scheduleGridLoading" class="rom-loading"><BurgerSpinner text="Загрузка..." /></div>
        <template v-else-if="scheduleRestaurants.length">
          <div class="so-sched-filter">
            <input v-model="scheduleFilter" type="text" class="rom-input-sm" placeholder="Поиск ресторана..." style="min-width:200px" />
            <button class="so-btn so-btn-accent" @click="saveScheduleGrid" :disabled="savingScheduleGrid">
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
                  <th class="so-grid-day" title="Поставщик привозит на склад, а ресторан получает с ближайшей основной поставкой. Ресторану показывается его дата получения, поставщику — складская.">Через склад</th>
                  <th class="so-grid-day" title="Напоминания о подаче заявки этому ресторану. Выкл — крон не шлёт напоминания «заявка не подана» по этому поставщику.">Напом.</th>
                  <th class="so-grid-day" title="Кто из привязанных Telegram-аккаунтов ресторана получает напоминания о подаче заявки этому поставщику.">Аккаунты</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="r in filteredScheduleRestaurants" :key="r.id">
                  <tr>
                    <td class="so-grid-rest-cell">
                      <span class="so-grid-num">{{ formatRestaurantNumber(r.number, r.legal_entity_group) }}</span>
                      <span class="so-grid-addr">{{ r.city }}{{ r.address ? ', ' + r.address : '' }}</span>
                    </td>
                    <td v-for="d in 7" :key="d" class="so-grid-check" @click="toggleScheduleDay(r, d)">
                      <input type="checkbox" :checked="!!scheduleGrid[r.id]?.[d]" @click.stop="toggleScheduleDay(r, d)" />
                    </td>
                    <td class="so-grid-check" @click="toggleScheduleWarehouse(r)">
                      <input type="checkbox" :checked="scheduleWarehouse.has(r.id)" @click.stop="toggleScheduleWarehouse(r)"
                             title="Ресторан получает этот товар со склада — сохраните расписание после изменения" />
                    </td>
                    <td class="so-grid-check">
                      <button
                        class="so-rem-toggle"
                        :class="scheduleMuted.has(r.id) ? 'off' : 'on'"
                        :disabled="remMuteSaving.has(r.id)"
                        @click="toggleReminderMute(r)"
                        :title="scheduleMuted.has(r.id) ? 'Напоминания выключены — включить' : 'Напоминания включены — выключить'"
                      >{{ scheduleMuted.has(r.id) ? 'выкл' : 'вкл' }}</button>
                    </td>
                    <td class="so-grid-check">
                      <button class="rom-btn-sm so-accounts-btn" @click="toggleRecipients(r)">
                        {{ expandedRecipients.has(r.id) ? 'Скрыть' : 'Аккаунты' }}
                      </button>
                    </td>
                  </tr>
                  <tr v-if="expandedRecipients.has(r.id)" class="so-recipients-row">
                    <td colspan="11" class="so-recipients-cell">
                      <div v-if="recipientsLoading.has(r.id)" class="so-recipients-loading">
                        <BurgerSpinner size="xs" text="Загрузка..." />
                      </div>
                      <div v-else-if="!recipientsData[r.id]?.accounts?.length" class="so-recipients-empty">
                        Нет привязанных Telegram-аккаунтов
                      </div>
                      <div v-else class="so-recipients-list">
                        <label v-for="acc in recipientsData[r.id].accounts" :key="acc.ro_tg_sub_id" class="so-recipient-item">
                          <input
                            type="checkbox"
                            :checked="acc.selected"
                            :disabled="recipientSaving.has(r.id + ':' + acc.ro_tg_sub_id)"
                            @change="toggleRecipient(r, acc)"
                          />
                          <span>{{ acc.name || acc.username || ('#' + acc.ro_tg_sub_id) }}</span>
                          <span v-if="acc.username" class="so-recipient-username">@{{ acc.username }}</span>
                        </label>
                        <p v-if="scheduleMuted.has(r.id)" class="so-recipients-hint">
                          Напоминания для этого ресторана выключены (переключатель «Напом.» слева) — выбор получателей пока не действует.
                        </p>
                        <p v-else-if="!recipientsData[r.id].accounts.some(a => a.selected)" class="so-recipients-hint">
                          Никто не отмечен — напоминания получают все привязанные аккаунты ресторана.
                        </p>
                      </div>
                    </td>
                  </tr>
                </template>
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
      </div>
    </template>

    <!-- ═══ TAB: Шаблон товаров ═══ -->
    <template v-if="pageTab === 'templates' && currentSupplierId">
      <div class="so-panel">
        <label class="so-panel-label">Юрлицо:</label>
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
        <button class="so-chip-btn" @click="addManualTemplateRow">+ Строка вручную</button>
        <button class="so-chip-btn" @click="importFromProducts">Импорт из справочника</button>
        <button class="so-btn so-btn-accent so-panel-save" @click="saveTemplates" :disabled="savingTemplates">
          <BurgerSpinner v-if="savingTemplates" size="xs" />
          <span>{{ savingTemplates ? 'Сохранение...' : 'Сохранить' }}</span>
        </button>
      </div>
      <div v-if="loadingTemplates" class="rom-loading"><BurgerSpinner text="Загрузка..." /></div>
      <div v-else>
        <div class="so-card so-card-flush">
          <table class="rom-table so-tpl-table">
            <thead>
              <tr>
                <th style="width:50px">Порядок</th>
                <th>Товар</th>
                <th style="width:200px">Каталог</th>
                <th style="width:80px">Кратность</th>
                <th style="width:80px">Мин. кол-во</th>
                <th style="width:120px">Доступ</th>
                <th style="width:40px"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(t, idx) in templates" :key="idx" :class="{ 'so-tpl-row-disabled': t.order_disabled }">
                <td><input type="number" v-model.number="t.sort_order" class="rom-input-sm" style="width:50px" /></td>
                <td>
                  <div class="so-template-product-cell">
                    <input v-model="t.sku" class="rom-input-sm so-template-sku-input" placeholder="SKU" />
                    <input v-model="t.product_name" class="rom-input-sm so-template-name-input" placeholder="Название товара" />
                  </div>
                  <div class="so-tpl-note-row">
                    <input v-model="t.note" class="rom-input-sm so-tpl-note-input" placeholder="Примечание (видят рестораны)" />
                    <!-- Примечание можно адресовать: всей сети, региону или
                         отдельным ресторанам — как доступность товара. -->
                    <!-- Кнопка видна всегда: адресата удобно выбрать сразу,
                         не дожидаясь, пока текст примечания будет написан. -->
                    <button class="so-tpl-note-aud"
                            :class="{ 'is-limited': (t.note_regions?.length || t.note_restaurants?.length) }"
                            :title="(t.note_regions?.length || t.note_restaurants?.length)
                              ? 'Примечание видят только выбранные'
                              : 'Примечание видят все, кому доступен товар'"
                            @click="openAccessModal(idx, 'note')">
                      {{ (t.note_regions?.length || t.note_restaurants?.length)
                        ? ('видят ' + ((t.note_regions?.length || 0) + (t.note_restaurants?.length || 0)))
                        : 'видят все' }}
                    </button>
                  </div>
                  <label class="so-tpl-disable-toggle" :title="t.order_disabled ? 'Товар полностью скрыт из заявок ресторанов и бота. Включите, чтобы снова разрешить заказ.' : 'Полностью убрать товар из заявок (рестораны и бот его не увидят). В шаблоне останется.'">
                    <input type="checkbox" v-model="t.order_disabled" :true-value="1" :false-value="0" />
                    <span>Отключён для заказа</span>
                  </label>
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
                <td>
                  <button class="rom-btn-sm" :class="{ 'so-tpl-access-on': (t.vis_regions?.length || t.vis_restaurants?.length) }" @click="openAccessModal(idx)">
                    {{ (t.vis_regions?.length || t.vis_restaurants?.length) ? ('Ограничен: ' + ((t.vis_regions?.length || 0) + (t.vis_restaurants?.length || 0))) : 'Все' }}
                  </button>
                </td>
                <td><button class="rom-btn-sm rom-btn-danger" @click="templates.splice(idx, 1)">✕</button></td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="so-schedule-count">Товаров: {{ templates.length }}</p>
      </div>

      <!-- Окно выбора доступа товара -->
      <div v-if="accessModal.open" class="rom-modal-overlay" @click.self="closeAccessModal">
        <div class="rom-modal so-access-modal">
          <div class="rom-modal-header">
            <h3>{{ accessModal.target === 'note' ? 'Кому видно примечание' : 'Кому виден товар' }}</h3>
            <button class="rom-modal-close" @click="closeAccessModal">✕</button>
          </div>
          <div class="rom-modal-body">
            <p class="so-section-hint" style="margin:0 0 10px">
              <template v-if="accessModal.target === 'note'">
                Ничего не выбрано — примечание видят <b>все</b>, кому доступен товар.
                Отметьте регионы или рестораны, чтобы показывать его <b>только им</b>.
              </template>
              <template v-else>
                Ничего не выбрано — товар видят <b>все</b>. Отметьте регионы или рестораны,
                чтобы показывать его <b>только им</b>.
              </template>
            </p>
            <div class="so-access-block">
              <div class="so-access-title">Регионы</div>
              <label v-for="rg in accessDirectory.regions" :key="'rg'+rg" class="so-settings-check">
                <input type="checkbox" :value="rg" v-model="accessModal.regions" />
                <span>{{ rg }}</span>
              </label>
            </div>
            <div class="so-access-block">
              <div class="so-access-title">Рестораны</div>
              <input v-model="accessRestSearch" class="rom-input-sm" style="width:100%;box-sizing:border-box;margin-bottom:6px" placeholder="Поиск по номеру или адресу" />
              <div class="so-access-rest-list">
                <label v-for="r in accessFilteredRestaurants" :key="'r'+r.number" class="so-settings-check">
                  <input type="checkbox" :value="String(r.number)" v-model="accessModal.restaurants" />
                  <span>{{ formatRestaurantNumber(r.number, r.legal_entity_group) }} · {{ r.region }}<span v-if="r.address" class="so-notify-muted"> · {{ r.address }}</span></span>
                </label>
              </div>
            </div>
          </div>
          <div class="rom-modal-foot">
            <button class="rom-btn-sm" @click="clearAccess">Сбросить (всем)</button>
            <button class="rom-btn-sm rom-btn-primary" @click="applyAccessModal">Готово</button>
          </div>
        </div>
      </div>
    </template>

    <!-- ═══ Окно: внеплановая заявка (довоз) ═══ -->
    <div v-if="adhoc.open" class="rom-modal-overlay" @click.self="adhoc.open = false">
      <div class="rom-modal so-adhoc-modal">
        <div class="rom-modal-header">
          <h3>Внеплановая заявка (довоз)</h3>
          <button class="rom-modal-close" @click="adhoc.open = false">✕</button>
        </div>
        <div class="rom-modal-body">
          <p class="so-section-hint" style="margin:0 0 10px">Заявка на дату <b>вне графика</b>. Попадёт поставщику в сводку, Excel и на почту; ресторан увидит её в кабинете и получит уведомление.</p>
          <div class="rom-date-row" style="flex-wrap:wrap;gap:12px">
            <label style="display:flex;align-items:center;gap:6px">Ресторан:
              <select v-model="adhoc.restaurant" class="rom-select">
                <option value="">— выберите —</option>
                <option v-for="r in adhoc.restaurants" :key="r.number" :value="r.number">{{ formatRestaurantNumber(r.number, r.legal_entity_group) }} — {{ r.city || r.region }}{{ r.address ? ', ' + r.address : '' }}</option>
              </select>
            </label>
            <label style="display:flex;align-items:center;gap:6px">Дата доставки:
              <input type="date" v-model="adhoc.date" class="rom-input-sm" />
            </label>
            <label style="display:flex;align-items:center;gap:6px">Дедлайн правки:
              <input type="datetime-local" v-model="adhoc.deadline" class="rom-input-sm" />
            </label>
          </div>
          <p class="so-section-hint" style="margin:6px 0">Дедлайн не задан — заявка сразу финальная (ресторан только видит). Задан — ресторан может править до него.</p>
          <div v-if="adhoc.loadingTpl" class="rom-loading"><BurgerSpinner text="Загрузка товаров..." /></div>
          <UiEmptyState v-else-if="!adhoc.products.length"
                        title="Заказывать нечего"
                        description="У этого поставщика пустой шаблон товаров — добавьте позиции, тогда можно будет создать внеплановую заявку.">
            <template #icon><BkIcon name="clipboard" size="lg" /></template>
          </UiEmptyState>
          <div v-else class="rom-table-wrap" style="max-height:340px;overflow:auto">
            <table class="rom-table">
              <thead><tr><th>Товар</th><th style="width:130px">Количество</th></tr></thead>
              <tbody>
                <tr v-for="p in adhoc.products" :key="p.sku">
                  <td style="text-align:left"><span style="color:var(--text-muted);font-size:11px;font-weight:700">{{ p.sku }}</span> {{ p.product_name }}</td>
                  <td><input type="number" v-model.number="adhoc.qty[p.sku]" class="rom-input-sm" min="0" step="0.001" placeholder="0" style="width:110px" /></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <div class="rom-modal-foot">
          <button class="rom-btn-sm" @click="adhoc.open = false">Отмена</button>
          <button class="rom-btn-sm rom-btn-primary" @click="submitAdhoc" :disabled="adhoc.saving || !adhoc.restaurant || !adhoc.date">
            {{ adhoc.saving ? 'Создание…' : 'Создать довоз' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ═══ TAB: Настройки ═══ -->
    <template v-if="pageTab === 'settings' && currentSupplierId">
      <div class="so-settings-wrap">
        <!-- Кнопок «Сохранить» больше нет: правки уходят сами, здесь виден статус -->
        <div class="so-autosave-bar so-autosave-chip" :class="{ busy: settingsSaving }">
          <template v-if="settingsSaving">Сохраняем…</template>
          <template v-else-if="settingsSavedTick">Изменения сохранены</template>
          <template v-else>Изменения сохраняются автоматически</template>
        </div>
        <!-- Приём заявок -->
        <div class="so-card so-settings-block">
          <div class="so-section-title so-section-title-flat">Приём заявок</div>
          <p class="so-section-hint so-section-hint-flat">Пока приём приостановлен, рестораны видят сообщение и не могут подать заявку.</p>
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
            <input type="text" v-model="pauseMessage" class="rom-input-sm" style="flex:1;min-width:250px" placeholder="Приём заявок временно приостановлен" />
          </div>
        </div>

        <!-- Иконка поставщика -->
        <div class="so-card so-settings-block">
          <div class="so-section-title so-section-title-flat">Иконка поставщика</div>
          <p class="so-section-hint so-section-hint-flat">Иконка показывается ресторану рядом с названием поставщика. «Авто» — подбор по названию.</p>
          <div class="so-icon-picker">
            <button type="button" class="so-icon-opt so-icon-auto" :class="{ active: !settings.icon_key }" @click="setSupplierIcon(null)" title="Авто (по названию)">Авто</button>
            <button v-for="ic in supplierIconKeys" :key="ic" type="button" class="so-icon-opt"
              :class="{ active: settings.icon_key === ic }" :style="supplierIconStyle(ic)"
              @click="setSupplierIcon(ic)" v-html="trustedSupplierIcon(ic)"></button>
          </div>
        </div>

        <!-- Автоматизация -->
        <div class="so-card so-settings-block">
          <div class="so-section-title so-section-title-flat">Автоматизация по дедлайну</div>
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
        <div class="so-card so-settings-block">
          <div class="so-section-title so-section-title-flat">Почта поставщика</div>
          <p v-if="currentSupplier?.email" class="so-section-hint" style="margin:4px 0 0 0">
            {{ currentSupplier.email }} <span class="so-notify-muted">(редактируется в карточке поставщика)</span>
          </p>
          <p v-else class="so-section-hint" style="margin:4px 0 0 0">Адрес почты задаётся в карточке поставщика.</p>

          <div style="margin-top:10px">
            <label class="so-inline-label" for="so-cc-emails">Постоянная копия</label>
            <input id="so-cc-emails" type="text" v-model="ccEmails" class="rom-input-sm"
              style="width:100%;box-sizing:border-box;margin-top:4px"
              placeholder="ivanov@company.by, petrov@company.by" />
            <p class="so-section-hint" style="margin:6px 0 0 0">
              Эти адреса получают копию каждого письма со сводкой по этому поставщику.
              Несколько адресов — через запятую. Копия ресторанам, если она включена, добавляется сверх этих.
            </p>
          </div>
        </div>

        <!-- Получатели итоговой сводки -->
        <div class="so-settings-block">
          <div class="so-notify-head">
            <div>
              <div class="so-section-title" style="margin:0">Получатели итоговой сводки</div>
              <div class="so-section-hint" style="margin:4px 0 0 0">После дедлайна бот отправит результат только отмеченным сотрудникам. В списке — те, у кого есть доступ к юрлицу этого поставщика и к модулю заявок.</div>
            </div>
          </div>
          <div v-if="loadingNotifyUsers" class="rom-loading" style="padding:8px 0"><BurgerSpinner size="sm" text="Загрузка пользователей..." /></div>
          <div v-else class="so-notify-users">
            <label v-for="u in allNotifyUsers" :key="u.name" class="so-notify-user">
              <input type="checkbox" :value="u.name" v-model="notifyUsers" />
              <span class="so-notify-user-text">
                <span class="so-notify-user-name">{{ u.name }}</span>
                <small v-if="u.display_role">{{ u.display_role }}</small>
                <small v-if="!u.has_telegram" class="so-notify-muted">нет Telegram</small>
              </span>
            </label>
          </div>
          <div v-if="!loadingNotifyUsers && notifyNobodyReachable" class="so-notify-warn">
            Сводка не уйдёт: ни у кого из отмеченных не привязан Telegram.
            Нужно, чтобы сотрудник открыл бота и привязал аккаунт, либо отметьте того, у кого Telegram уже есть.
          </div>
          <div v-else-if="!loadingNotifyUsers && notifyUsersWithoutTelegram.length" class="so-notify-warn">
            Без Telegram, сводку не получат: {{ notifyUsersWithoutTelegram.join(', ') }}.
          </div>
        </div>

        <!-- Напоминания о подаче заявок -->
        <div class="so-settings-block">
          <div class="so-notify-head">
            <div>
              <div class="so-section-title" style="margin:0">Напоминания о подаче заявок</div>
              <div class="so-section-hint" style="margin:4px 0 0 0">Бот напомнит ресторанам, не подавшим заявку, в выбранные моменты до дедлайна.</div>
            </div>
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
            <label style="display:flex;align-items:center;gap:6px">
              Показывать недель:
              <input type="number" v-model.number="weeklyWeeksAhead" class="rom-input-sm" style="width:70px" min="1" max="12" step="1" />
            </label>
          </div>
          <div v-if="weeklyEnabled" class="so-section-hint" style="margin:6px 0 0 0">
            Сколько ближайших недель доставки видит ресторан для заказа. По умолчанию 1 — только ближайшая открытая неделя; следующая появится, когда у текущей пройдёт дедлайн.
          </div>
        </div>

        <!-- Минимальный заказ -->
        <div class="so-settings-block">
          <div class="so-notify-head">
            <div>
              <div class="so-section-title" style="margin:0">Минимальный заказ</div>
              <div class="so-section-hint" style="margin:4px 0 0 0">Если задан — заявку меньше минимума нельзя отправить (жёсткий блок). Значение 0 или пусто = минимума нет.</div>
            </div>
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
          <p v-if="!(Number(minOrderValue) > 0)" class="so-section-hint" style="margin:8px 0 0 0">
            Минимум не задан — рестораны могут подавать заявку на любое количество. Единица сохранится и будет применена, как только вы впишете сумму минимума.
          </p>
          <p v-else-if="minOrderUnit === 'kg'" class="so-section-hint" style="margin:8px 0 0 0">
            Единица «килограммы» работает по весам из справочника: у товаров поставщика должны быть заполнены вес и учётная единица отгрузки.
          </p>
        </div>

        <!-- Отчёт Excel -->
        <div class="so-settings-block">
          <div class="so-notify-head">
            <div>
              <div class="so-section-title" style="margin:0">Отчёт Excel</div>
              <div class="so-section-hint" style="margin:4px 0 0 0">Как выглядит файл заявки, который скачивается и уходит поставщику письмом.</div>
            </div>
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
            <div v-if="showBoxSizeWarning" class="so-box-warn">
              <strong>Проверьте размер коробки в справочнике.</strong>
              У этих товаров указано «в коробке 1», хотя заказ идёт кратно большему числу —
              похоже, в справочник попала фасовка, а не коробка. Пока это так, столбцы
              «Коробок» и «Паллет» посчитаются неверно.
              <ul class="so-box-warn-list">
                <li v-for="w in boxSizeWarnings" :key="w.sku">
                  {{ w.sku }} — {{ w.product_name }}
                  <span class="so-notify-muted">(кратность {{ Number(w.multiplicity) }}<template v-if="w.unit_of_measure">, {{ w.unit_of_measure }}</template>)</span>
                </li>
              </ul>
            </div>
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
          <!-- Количества правятся прямо здесь: раньше ради изменения одной
               позиции приходилось идти во вкладку «Приём» и искать ячейку. -->
          <table class="rom-table so-modal-table">
            <thead>
              <tr>
                <th>Товар</th>
                <th class="so-modal-qty-col">Кол-во ресторана</th>
                <th class="so-modal-qty-col">Наше значение</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in viewedOrder.items" :key="item.id">
                <td>
                  <span class="so-tpl-sku">{{ item.sku }}</span> {{ item.product_name }}
                  <span v-if="orderItemRuleHint(item)" class="so-modal-rule">{{ orderItemRuleHint(item) }}</span>
                </td>
                <td class="so-modal-qty-col so-list-dim">{{ formatQtyValue(item.quantity) }}</td>
                <td class="so-modal-qty-col">
                  <input
                    class="so-modal-qty-input"
                    :class="{ 'is-bad': orderItemViolates(item) }"
                    type="text" inputmode="decimal"
                    :value="orderItemInput(item)"
                    :disabled="orderItemSaving === item.id"
                    :placeholder="formatQtyValue(item.quantity)"
                    @keydown.enter="$event.target.blur()"
                    @change="saveOrderItemQty(item, $event.target.value)"
                  />
                </td>
              </tr>
            </tbody>
          </table>
          <p class="so-modal-note">
            Пустое поле — количество ресторана без изменений. Введите своё число, чтобы поправить заявку;
            изменения сразу уходят в сводку поставщику.
          </p>
        </div>
      </div>
    </div>

    <div v-if="!currentSupplierId" class="rom-empty" style="margin-top: 40px">
      Выберите поставщика для просмотра заявок
    </div>

    <!-- Количество не по правилам товара: округлить или оставить как есть -->
    <div v-if="qtyModal.show" class="rom-modal-overlay" @click.self="qtyModal.show = false">
      <div class="rom-modal so-qty-modal">
        <h3 class="so-qty-modal-title">Количество не по правилам</h3>
        <p class="so-qty-modal-text">{{ qtyModal.text }}</p>
        <div class="so-qty-options">
          <button v-for="o in qtyModal.options" :key="o.value"
                  class="so-qty-opt" @click="applyQtyChoice(o.value)">
            Поставить <b>{{ o.label }}</b>
          </button>
        </div>
        <div class="so-qty-modal-actions">
          <button class="rom-btn" @click="qtyModal.show = false">Отмена</button>
          <button class="rom-btn so-qty-keep" @click="applyQtyChoice(qtyModal.value)">
            Оставить {{ fmtNum(qtyModal.value) }}
          </button>
        </div>
      </div>
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
import BkIcon from '@/components/ui/BkIcon.vue';
import UiEmptyState from '@/components/ui/UiEmptyState.vue';
import { useDirtySnapshot } from '@/composables/useFormDirty.js';
import { useRoute, useRouter } from 'vue-router';
import { useSupplierOrderStore } from '@/stores/supplierOrderStore.js';
import { appPrompt } from '@/lib/appDialogs.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { db } from '@/lib/apiClient.js';
import { formatRestaurantNumber, LEGAL_ENTITIES, ENTITY_SHORT_NAMES, getEntityGroup, getEntityGroupCode } from '@/lib/legalEntities.js';
import { toLocalDateStr } from '@/lib/utils.js';
import { buildSoOrderSheet } from '@/lib/soOrderXlsx.js';
import { supplierIconKeys, trustedSupplierIcon, supplierIconStyle } from '@/lib/cabinetIcons.js';
import { useToastStore } from '@/stores/toastStore.js';
import { useConfirm } from '@/composables/useConfirm.js';

const ConfirmModal = defineAsyncComponent(() => import('@/components/modals/ConfirmModal.vue'));

const route = useRoute();
const router = useRouter();
const { confirmModal, confirm: showConfirm, onConfirm, onCancel } = useConfirm();

const props = defineProps({
  supplierId: { type: String, default: '' },
  // Какие разделы показывать. Нужно другим модулям, которые встраивают этот
  // экран ради части функций: «Собственное производство» берёт приём заявок,
  // графики, шаблон и настройки, а обзор по всем поставщикам ему не нужен.
  tabs: { type: Array, default: null },
  // Прятать собственную полосу вкладок: у встраивающего модуля она своя.
  hideTabs: { type: Boolean, default: false },
  // Префикс ключей в адресе страницы. Без него встроенный экран и модуль
  // пишут в одни и те же ?tab= и ?date= и затирают друг друга.
  queryPrefix: { type: String, default: '' },
  // Юрлицо поставщика. Обычно берётся из сайдбара, но встроенный экран должен
  // работать по юрлицу своего поставщика: у «Собственного производства» это
  // всегда «Пицца Стар», каким бы юрлицом ни пользовался открывший ссылку.
  legalEntity: { type: String, default: '' },
});

const store = useSupplierOrderStore();
const orderStore = useOrderStore();
const toast = useToastStore();

const dayNames = { 1: 'ПН', 2: 'ВТ', 3: 'СР', 4: 'ЧТ', 5: 'ПТ', 6: 'СБ', 7: 'ВС' };
const dayNamesFull = { 1: 'Понедельник', 2: 'Вторник', 3: 'Среда', 4: 'Четверг', 5: 'Пятница', 6: 'Суббота', 7: 'Воскресенье' };
const daysShort = { 1: 'Пн', 2: 'Вт', 3: 'Ср', 4: 'Чт', 5: 'Пт', 6: 'Сб', 7: 'Вс' };

// Разделы модуля: один список — и для кнопок, и для загрузки данных вкладки.
const ALL_PAGE_TABS = [
  { key: 'overview', label: 'Обзор' },
  { key: 'status', label: 'Приём' },
  { key: 'list', label: 'Список заявок' },
  { key: 'schedules', label: 'Графики' },
  { key: 'templates', label: 'Шаблон товаров' },
  { key: 'settings', label: 'Настройки' },
];
const PAGE_TABS = computed(() => (props.tabs?.length
  ? ALL_PAGE_TABS.filter(t => props.tabs.includes(t.key))
  : ALL_PAGE_TABS));

// Стартовая вкладка: «Обзор» по умолчанию, но при входе по прямой ссылке
// на конкретного поставщика (props.supplierId) — сразу «Приём».
const pageTab = ref(
  props.tabs?.length ? PAGE_TABS.value[0]?.key || 'status'
                     : (props.supplierId ? 'status' : 'overview'));
const TAB_LOADERS = {
  overview: () => loadOverview(),
  status: () => loadStatus(),
  list: () => loadOrdersList(),
  schedules: () => loadSchedules(),
  templates: () => loadTemplates(),
  settings: () => loadSettings(),
};
function switchPageTab(key) {
  pageTab.value = key;
  TAB_LOADERS[key]?.();
}
const loading = ref(false);
const allSuppliers = ref([]);
const currentSupplierId = ref(props.supplierId || '');
const selectedDate = ref('');
const selectedDeadline = ref('');
// ISO-время дедлайна выбранной даты — для «живого» пересчёта статуса (как в обзоре).
const selectedDeadlineAt = ref('');
// Состояние приёма на выбранную дату: 'open' | 'closed' (сервер считает по
// дедлайну с учётом переносов и принудительного закрытия дня).
const selectedDeadlineStatus = ref('');
// Чем закончилась последняя отправка письма поставщику за выбранный день.
const dayEmailStatus = ref(null);
const dayEmailLabel = computed(() => {
  const st = dayEmailStatus.value;
  if (!st) return null;
  const time = st.at ? String(st.at).slice(11, 16) : '';
  if (st.success) return { ok: true, text: `Письмо отправлено${time ? ' в ' + time : ''}` };
  const reason = st.error === 'Нет валидных получателей'
    ? 'неверный адрес в карточке поставщика'
    : (st.error || 'сбой отправки');
  return { ok: false, text: `Письмо не ушло${time ? ' (' + time + ')' : ''}: ${reason}` };
});
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
const boxSizeWarnings = ref([]);
const ccEmails = ref('');
// Предупреждение показываем только когда столбцы коробок/паллет реально включены —
// на вес нетто/брутто размер коробки не влияет.
const showBoxSizeWarning = computed(() => boxSizeWarnings.value.length > 0
  && xlsxPalletMetrics.value.some(m => m === 'boxes' || m === 'pallets'));
const settings = ref({ is_accepting_orders: 1, auto_submit_previous: 0, auto_email_summary: 0, email_cc_restaurants: 0, default_deadline_time: '14:00:00', pause_message: null });
// Для какого поставщика реально загружены настройки (id). null — настройки не свои/не загружены.
const settingsLoadedFor = ref(null);
const defaultDeadline = ref('14:00');
const pauseMessage = ref('');
const deadlineOverrides = ref([]);
const allNotifyUsers = ref([]);
const loadingNotifyUsers = ref(false);
const notifyUsers = ref([]);

// Сводка после дедлайна уходит только в Telegram. Если у всех отмеченных
// сотрудников Telegram не привязан, бот промолчит и никто об этом не узнает —
// поэтому предупреждаем прямо в настройках.
const notifyUsersWithoutTelegram = computed(() => {
  const chosen = new Set(notifyUsers.value || []);
  return (allNotifyUsers.value || []).filter(u => chosen.has(u.name) && !u.has_telegram).map(u => u.name);
});
const notifyNobodyReachable = computed(() =>
  (notifyUsers.value || []).length > 0 &&
  notifyUsersWithoutTelegram.value.length === (notifyUsers.value || []).length
);

// Напоминания о подаче заявок (массивы выбранных таймингов и каналов)
const reminderOffsets = ref([]);
const reminderChannels = ref([]);

// Недельный режим подачи (вкл/выкл, день недели 1..7, время HH:MM)
const weeklyEnabled = ref(false);
const weeklyDow = ref(3);
const weeklyTime = ref('14:00');
// Недельный режим: сколько ближайших недель доставки показывать ресторану
const weeklyWeeksAhead = ref(1);

// Минимальный заказ у поставщика (значение и единица кг/штуки)
const minOrderValue = ref(0);
const minOrderUnit = ref('kg');

// Опции Excel-отчёта поставщика: убирать пустые строки и какие показатели
// паллет/веса выводить (boxes / pallets / netto / brutto).
const xlsxDropEmpty = ref(false);
const xlsxPalletMetrics = ref([]);

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
const templateLe = ref(props.legalEntity || orderStore.settings.legalEntity || 'ООО "Бургер БК"');
const templateProductSearch = ref('');
const templateProductResults = ref([]);
// Индекс строки шаблона в режиме привязки к карточке каталога (null — режим добавления новой строки)
const linkingRowIdx = ref(null);
let templateSearchTimer = null;

// Группа юрлиц текущего поставщика (BK_VM | PS). Определяется из списка
// поставщиков: он уже отфильтрован backend'ом по группе юрлица сайдбара.
const currentSupplierGroup = computed(() => {
  // Здесь нужен КОД группы ('PS' | 'BK_VM'), а не список юрлиц: по нему
  // фильтруются рестораны. getEntityGroup вернул бы массив названий, и
  // встроенный экран (цех ПРЦ) искал рестораны по несуществующей группе —
  // список приходил пустым, график поставок выглядел ненастроенным.
  if (props.legalEntity) return getEntityGroupCode(props.legalEntity);
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
// В выборе поставщика цех собственного производства не показываем: его
// настраивают в модуле «Собственное производство». Сам список поставщиков
// оставляем полным — по нему находится название и почта встроенного экрана.
const pickerSuppliers = computed(() => allSuppliers.value.filter(s => !s.is_workshop));

// ── Загрузочные листы (ПРЦ, тесто) ──
// ПРЦ собирает заказ стопками по 22 лотка и клеит на них печатные листы.
// Кнопка показывается только этому поставщику: остальным раскладка не нужна.
const loadingSheetsBusy = ref(false);
const loadingSheetsAvailable = computed(() =>
  /ПРЦ/i.test(String(currentSupplier.value?.short_name || ''))
);

/**
 * Печать загрузочных листов одного ресторана прямо из браузера.
 * Открываем отдельное окно: одна стопка — одна страница, как в Excel.
 */
function stickerWord(line) {
  // Печать чёрно-белая: кодировку пишем словом, цвет ничего не даст.
  const m = String(line.name || '').match(/(\d{2})\s*см/);
  const bySize = { '25': 'ЖЁЛТЫЙ', '30': 'ЗЕЛЁНЫЙ', '35': 'КРАСНЫЙ' };
  return (m && bySize[m[1]]) || '—';
}

function trayWord(n) {
  const n10 = n % 10, n100 = n % 100;
  if (n10 === 1 && n100 !== 11) return 'лоток';
  if (n10 >= 2 && n10 <= 4 && (n100 < 12 || n100 > 14)) return 'лотка';
  return 'лотков';
}

async function printLoadingSheets(restaurantNumber) {
  if (!currentSupplierId.value || !selectedDate.value) return;
  try {
    const url = `/api/so/admin/loading-sheets-data?supplier_id=${encodeURIComponent(currentSupplierId.value)}&date=${encodeURIComponent(selectedDate.value)}`;
    const resp = await fetch(url, { headers: { 'X-Session-Token': localStorage.getItem('bk_session_token') || '' } });
    const data = await resp.json();
    if (!resp.ok || !data.enabled) { toast.error('Не получилось', data.error || 'Загрузочные листы недоступны'); return; }
    const rest = (data.restaurants || []).find(r => String(r.restaurant_number) === String(restaurantNumber));
    if (!rest) { toast.warning('Пусто', 'На этот день у ресторана нет заявки с тестом'); return; }

    const esc = (v) => String(v ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    // Формат как в Excel-версии листа: 04.08.2026.
    const dateFmt = selectedDate.value.split('-').reverse().join('.');
    const total = rest.stacks.length;
    const orderLines = rest.items.map(i => `<div>${esc(i.sku)} ${esc(i.name)} — ${i.trays} лотков</div>`).join('');
    const stickerLines = rest.items.filter(i => i.sticker)
      .map(i => `<div>${esc((i.name.match(/(\d{2})\s*см/) || [])[0] ? 'Тесто для пиццы ' + i.name.match(/(\d{2})\s*см/)[0] : i.name)} — ${esc(i.sticker)}</div>`).join('');

    const pages = rest.stacks.map((st, idx) => {
      const lines = st.lines.map(l => `<div class="ls-row">
            <div class="ls-name">${esc(l.name)}</div>
            <div class="ls-trays">${l.trays} ${trayWord(l.trays)}</div>
            <div class="ls-sticker">${esc(stickerWord(l))}</div>
          </div>`).join('');
      return `<section class="ls-page">
        <div class="ls-title">${esc(rest.address)}</div>
        <div class="ls-addr">${esc(rest.title)}</div>
        <div class="ls-date">Отгрузка ${esc(dateFmt)}</div>
        <div class="ls-stack">
          <div class="ls-stack-head">${st.mixed ? 'СБОРНАЯ СТОПКА' : 'СТОПКА'} &nbsp;${idx + 1} / ${total}</div>
          <div class="ls-cols"><div>Наименование</div><div>Количество</div><div>Стикер</div></div>
          ${lines}
          ${st.mixed ? `<div class="ls-total">ИТОГО В СТОПКЕ: ${st.total} ${trayWord(st.total)}</div>` : ''}
        </div>
        <div class="ls-all">
          <div class="ls-all-head">ВСЯ ЗАЯВКА</div>
          ${rest.items.map(i => {
            const m = i.name.match(/(\d{2})\s*см/);
            return `<div class="ls-all-row"><b>${esc(m ? 'Тесто для пиццы ' + m[1] + ' см' : i.name)}</b>
              <span>${i.trays} ${trayWord(i.trays)}${i.sticker ? ' · ' + esc(i.sticker) : ''}</span></div>`;
          }).join('')}
        </div>
        <div class="ls-foot">Лист ${idx + 1} из ${total}</div>
      </section>`;
    }).join('');

    const w = window.open('', '_blank');
    if (!w) { toast.error('Не получилось', 'Браузер заблокировал окно печати'); return; }
    // Печать чёрно-белая: акценты только размером, жирностью и рамками.
    w.document.write(`<!doctype html><html lang="ru"><head><meta charset="utf-8">
      <title>Загрузочный лист — ${esc(rest.title)} — ${esc(dateFmt)}</title>
      <style>
        @page { size: A4 portrait; margin: 12mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #000; margin: 0; }
        /* Лист вешают на верхний край лотка, верх загибается — поэтому
           содержимое стоит по середине страницы, а не сверху. */
        .ls-page { page-break-after: always; display: flex; flex-direction: column;
                   justify-content: center; min-height: 265mm; }
        .ls-page:last-child { page-break-after: auto; }
        .ls-title { font-size: 34px; font-weight: 800; text-align: center; letter-spacing: 1px;
                    border: 2px solid #000; background: #ececec; padding: 8px 4px; }
        .ls-addr { text-align: center; font-size: 18px; padding: 4px; border-left: 2px solid #000; border-right: 2px solid #000; background: #ececec; }
        .ls-date { text-align: center; font-size: 18px; font-weight: 700; border: 2px solid #000; background: #ececec; padding: 5px; }
        .ls-stack { margin-top: 14px; border: 3px solid #000; }
        .ls-stack-head { background: #000; color: #fff; font-size: 25px; font-weight: 800; text-align: center; padding: 7px; letter-spacing: 1px; }
        .ls-cols { display: grid; grid-template-columns: 2fr 1.2fr 1fr; background: #f2f2f2;
                   border-bottom: 1px solid #000; font-size: 14px; font-weight: 700; color: #444; }
        .ls-cols > div { text-align: center; padding: 4px 2px; }
        .ls-row { display: grid; grid-template-columns: 2fr 1.2fr 1fr; align-items: center;
                  padding: 14px 8px; min-height: 96px; border-bottom: 1px solid #000; }
        .ls-stack .ls-row:last-child { border-bottom: none; }
        .ls-name { font-size: 22px; font-weight: 700; text-align: center; line-height: 1.25; }
        .ls-trays { font-size: 46px; font-weight: 800; text-align: center; line-height: 1.05; }
        .ls-sticker { font-size: 24px; font-weight: 800; text-align: center; }
        .ls-sub { text-align: center; font-size: 12px; color: #444; border-bottom: 1px solid #000; padding-bottom: 5px; }
        .ls-stack .ls-sub:last-child { border-bottom: none; }
        .ls-total { font-size: 22px; font-weight: 800; text-align: center; padding: 8px; background: #ececec; border-top: 2px solid #000; }
        .ls-all { margin-top: 20px; font-size: 19px; }
        .ls-all-head { font-size: 14px; font-weight: 700; color: #444; letter-spacing: 1px; margin-bottom: 4px; }
        .ls-all-row { display: flex; gap: 16px; border-bottom: 1px solid #ccc; padding: 5px 0; }
        .ls-all-row b { min-width: 240px; }
        .ls-foot { margin-top: 14px; text-align: center; font-size: 17px; font-weight: 700; border-top: 1px solid #000; padding-top: 8px; }
      </style></head><body>${pages}</body></html>`);
    w.document.close();
    w.focus();
    setTimeout(() => w.print(), 300);
  } catch (e) {
    toast.error('Не получилось', e.message || String(e));
  }
}

/** «03.08–08.08» — неделя (пн–сб), в которую попадает выбранная дата. */
const weekRangeLabel = computed(() => {
  if (!selectedDate.value) return '';
  const d = new Date(selectedDate.value + 'T00:00:00');
  if (isNaN(d)) return '';
  const mon = new Date(d); mon.setDate(d.getDate() - ((d.getDay() + 6) % 7));
  const sat = new Date(mon); sat.setDate(mon.getDate() + 5);
  const f = (x) => String(x.getDate()).padStart(2, '0') + '.' + String(x.getMonth() + 1).padStart(2, '0');
  return `${f(mon)}–${f(sat)}`;
});

/**
 * Заказ теста на неделю выбранной даты: два листа по три дня.
 * Цеху нужна вся неделя целиком, поэтому день здесь только определяет неделю.
 */
async function exportWorkshopWeek() {
  if (!currentSupplierId.value || !selectedDate.value || exporting.value) return;
  exporting.value = true;
  try {
    const url = `/api/own-production/week-export?supplier_id=${encodeURIComponent(currentSupplierId.value)}&date=${encodeURIComponent(selectedDate.value)}`;
    const resp = await fetch(url, { headers: { 'X-Session-Token': localStorage.getItem('bk_session_token') || '' } });
    if (!resp.ok) {
      const msg = await resp.json().catch(() => ({}));
      toast.error('Не получилось', msg.error || `Ошибка ${resp.status}`);
      return;
    }
    const blob = await resp.blob();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    // Имя даёт сервер — в нём диапазон недели, а не выбранный день.
    a.download = fileNameFromResponse(resp) || `Тесто ${weekRangeLabel.value}.xlsx`;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 5000);
  } catch (e) {
    toast.error('Не получилось', e.message || String(e));
  } finally {
    exporting.value = false;
  }
}

/** Имя файла из заголовка Content-Disposition (сервер знает период точнее). */
function fileNameFromResponse(resp) {
  const cd = resp.headers.get('Content-Disposition') || '';
  const star = /filename\*=UTF-8''([^;]+)/i.exec(cd);
  if (star) { try { return decodeURIComponent(star[1]); } catch (e) { /* битая кодировка */ } }
  const plain = /filename="?([^";]+)"?/i.exec(cd);
  return plain ? plain[1] : '';
}

async function downloadLoadingSheets() {
  if (!currentSupplierId.value || !selectedDate.value || loadingSheetsBusy.value) return;
  loadingSheetsBusy.value = true;
  try {
    const url = `/api/so/admin/loading-sheets?supplier_id=${encodeURIComponent(currentSupplierId.value)}&date=${encodeURIComponent(selectedDate.value)}`;
    const resp = await fetch(url, { headers: { 'X-Session-Token': localStorage.getItem('bk_session_token') || '' } });
    if (!resp.ok) {
      const msg = await resp.json().catch(() => ({}));
      toast.error('Не получилось', msg.error || `Ошибка ${resp.status}`);
      return;
    }
    const blob = await resp.blob();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `Загрузочный лист — ${formatDate(selectedDate.value)}.xlsx`;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 5000);
  } catch (e) {
    toast.error('Не получилось', e.message || String(e));
  } finally {
    loadingSheetsBusy.value = false;
  }
}

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

// По умолчанию выгружаем ОДИН выбранный день. Раньше отмечались все даты
// графика — кнопка предлагала «17 дней», и это почти никогда не нужно.
watch([weekDates, selectedDate], ([dates, date]) => {
  if (!dates?.length) { exportSelectedDates.value = new Set(); return; }
  const has = date && dates.some(d => d.date === date);
  exportSelectedDates.value = new Set(has ? [date] : [dates[0].date]);
}, { deep: true, immediate: true });

function toggleExportDate(date) {
  const s = new Set(exportSelectedDates.value);
  if (s.has(date)) s.delete(date);
  else s.add(date);
  exportSelectedDates.value = s;
}
function exportSelectAll() { exportSelectedDates.value = new Set(weekDates.value.map(d => d.date)); }
function exportSelectNone() { exportSelectedDates.value = new Set(); }
function exportSelectCurrent() {
  exportSelectedDates.value = new Set(selectedDate.value ? [selectedDate.value] : []);
}
/** Подпись на кнопке: один день — датой, несколько — числом. */
const exportLabel = computed(() => {
  const n = exportSelectedDates.value.size;
  if (n === 0) return 'выберите день';
  if (n === 1) return formatDateShort([...exportSelectedDates.value][0]);
  return `${n} ${dayWord(n)}`;
});

// Pivot table data
const products = ref([]);
const orderItems = ref([]);
const filterText = ref('');
const showMissing = ref(true);
const editCell = ref('');
const editValue = ref('');
const editInputRef = ref(null);
// Заказ, разложенный по партиям (цех ПРЦ): в ячейке правим каждую партию
// отдельным полем — общая цифра там сумма, и править её нечем.
const editParts = ref([]);

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
  // Количество приходит из базы строкой («2.00»), поэтому приводим к числу:
  // раньше при строке функция возвращала пустоту и колонка выглядела пустой.
  const num = typeof value === 'number' ? value : parseFloat(String(value ?? '').replace(',', '.'));
  if (!Number.isFinite(num)) return '';
  return num === Math.floor(num) ? Math.floor(num) : +num.toFixed(2);
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
    // Встроенный экран грузит поставщиков своего юрлица, а не выбранного в
    // сайдбаре: иначе название, почта и признак цеха не находятся.
    allSuppliers.value = await store.adminGetSuppliers(props.legalEntity || orderStore.settings.legalEntity);
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

// ═══ Автосохранение настроек поставщика ═══
// Кнопок «Сохранить» в настройках было шесть, и каждая правка требовала
// отдельного клика. Теперь любое изменение уходит на сервер само: чекбоксы —
// почти сразу, поля ввода — через паузу после последнего нажатия клавиши.
//
// applyingRemote защищает от петли: раскладывая ответ сервера (или настройки
// другого поставщика), мы меняем те же самые поля, и сторож иначе тут же
// отправил бы их обратно.
let applyingRemote = false;
const autoSaveTimers = {};
const settingsSaving = ref(false);
const settingsSavedTick = ref(0);

async function applyRemote(fn) {
  applyingRemote = true;
  try { fn(); } finally { await nextTick(); applyingRemote = false; }
}

// Сохранять можно только когда на экране настройки ИМЕННО текущего поставщика:
// иначе переключение поставщика (оно тоже меняет поля) улетело бы как правка.
function autoSaveReady() {
  return !applyingRemote
    && !!currentSupplierId.value
    && settingsLoadedFor.value === currentSupplierId.value;
}

function autoSave(key, fn, delay = 400) {
  if (!autoSaveReady()) return;
  clearTimeout(autoSaveTimers[key]);
  autoSaveTimers[key] = setTimeout(async () => {
    if (!autoSaveReady()) return;
    settingsSaving.value = true;
    try {
      await fn();
      settingsSavedTick.value = Date.now();
    } catch (e) {
      toast.error('Не сохранено', e.message);
    } finally {
      settingsSaving.value = false;
    }
  }, delay);
}

async function loadSettings() {
  if (!currentSupplierId.value) return;
  // Запоминаем, за чьими настройками пошли — поставщик мог смениться, пока шёл запрос.
  const sid = currentSupplierId.value;
  // Получатели сводки приезжают этим же запросом, поэтому их индикатор
  // загрузки живёт здесь.
  loadingNotifyUsers.value = true;
  try {
    const data = await store.adminGetSettings(sid);
    // Раскладываем всё разом под защитой applyRemote: эти же поля слушает
    // автосохранение, и без защиты загрузка выглядела бы как правка.
    await applyRemote(() => {
      settings.value = data.settings || { is_accepting_orders: 1, auto_submit_previous: 0, auto_email_summary: 0, email_cc_restaurants: 0, default_deadline_time: '14:00:00', pause_message: null, xlsx_drop_empty: 0, xlsx_pallet_metrics: [] };
      // Отмечаем владельца настроек только если сервер вернул настоящие настройки.
      // Дефолт-заглушка и ошибка запроса владельца не дают.
      settingsLoadedFor.value = data.settings ? sid : null;
      defaultDeadline.value = (settings.value.default_deadline_time || '14:00:00').substring(0, 5);
      pauseMessage.value = settings.value.pause_message || '';
      deadlineOverrides.value = data.overrides || [];
      notifyUsers.value = Array.isArray(data.notify_users) ? data.notify_users : [];
      // Кандидаты приходят вместе с настройками: список зависит от поставщика
      // (доступ к его юрлицу), поэтому общий справочник пользователей не годится.
      allNotifyUsers.value = Array.isArray(data.summary_candidates) ? data.summary_candidates : [];
      boxSizeWarnings.value = Array.isArray(data.box_size_warnings) ? data.box_size_warnings : [];
      ccEmails.value = data.cc_emails || '';
      reminderOffsets.value = Array.isArray(settings.value.reminder_offsets) ? [...settings.value.reminder_offsets] : [];
      reminderChannels.value = Array.isArray(settings.value.reminder_channels) ? [...settings.value.reminder_channels] : [];
      syncWeeklyFromSettings();
      syncMinOrderFromSettings();
      syncXlsxFromSettings();
    });
  } catch (e) {
    // Запрос упал — в settings могли остаться настройки прошлого поставщика.
    // Снимаем метку владельца, чтобы экспорт не собрал файл с чужими опциями.
    settingsLoadedFor.value = null;
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

async function setSupplierIcon(key) {
  const prev = settings.value.icon_key || null;
  const next = key || null;
  if (prev === next) return;
  settings.value = { ...settings.value, icon_key: next }; // мгновенный отклик
  try {
    await store.adminSaveSettings(currentSupplierId.value, currentSettingsPayload({ icon_key: next }));
  } catch (e) {
    settings.value = { ...settings.value, icon_key: prev }; // откат при ошибке
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
  await store.adminSaveSettings(currentSupplierId.value, currentSettingsPayload());
}

// Постоянная копия писем поставщику. Сервер сам чистит список, поэтому
// возвращённое значение кладём обратно в поле — видно, что именно сохранилось.
async function saveCcEmails() {
  const data = await store.adminSaveSettings(currentSupplierId.value, { cc_emails: ccEmails.value });
  if (data && typeof data.cc_emails === 'string') {
    await applyRemote(() => { ccEmails.value = data.cc_emails; });
  }
}

async function savePauseMessage() {
  await store.adminSaveSettings(currentSupplierId.value, currentSettingsPayload());
}

async function saveNotifyUsers() {
  const data = await store.adminSaveSettings(currentSupplierId.value, currentSettingsPayload({ notify_users: notifyUsers.value }));
  await applyRemote(() => {
    notifyUsers.value = Array.isArray(data.notify_users) ? data.notify_users : [];
  });
}

// Сохранение напоминаний — ОТДЕЛЬНЫЙ запрос только с ключами reminder_*,
// чтобы бэкенд обновил именно их и не тронул прочие настройки.
async function saveReminders() {
  const data = await store.adminSaveSettings(currentSupplierId.value, {
    reminder_offsets: [...reminderOffsets.value],
    reminder_channels: [...reminderChannels.value],
  });
  if (data && data.settings) {
    await applyRemote(() => {
      settings.value = data.settings;
      reminderOffsets.value = Array.isArray(data.settings.reminder_offsets) ? [...data.settings.reminder_offsets] : [];
      reminderChannels.value = Array.isArray(data.settings.reminder_channels) ? [...data.settings.reminder_channels] : [];
    });
  }
}

// Недельный приём по сохранённым настройкам (не по полям формы): при таком
// режиме дедлайн один на всю неделю, поэтому и письмо поставщику уходит одно —
// сразу за все дни доставки этой недели.
const supplierIsWeekly = computed(() => {
  const dow = settings.value?.weekly_deadline_dow;
  return dow != null && dow !== '' && Number(dow) >= 1 && Number(dow) <= 7;
});

// Синхронизация локальных полей недельного режима из settings.value.
// PDO может вернуть dow строкой — приводим к Number.
function syncWeeklyFromSettings() {
  const dow = settings.value.weekly_deadline_dow;
  weeklyWeeksAhead.value = Math.max(1, Number(settings.value.weekly_weeks_ahead || 1));
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
  const payload = weeklyEnabled.value
    ? { weekly_deadline_dow: Number(weeklyDow.value), weekly_deadline_time: weeklyTime.value, weekly_weeks_ahead: Math.max(1, Number(weeklyWeeksAhead.value || 1)) }
    : { weekly_deadline_dow: null };
  const data = await store.adminSaveSettings(currentSupplierId.value, payload);
  if (data && data.settings) {
    await applyRemote(() => {
      settings.value = data.settings;
      syncWeeklyFromSettings();
    });
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
  const val = Number(minOrderValue.value);
  const data = await store.adminSaveSettings(currentSupplierId.value, {
    min_order_value: val > 0 ? val : null,
    min_order_unit: minOrderUnit.value,
  });
  if (data && data.settings) {
    await applyRemote(() => {
      settings.value = data.settings;
      syncMinOrderFromSettings();
    });
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
  const data = await store.adminSaveSettings(currentSupplierId.value, {
    xlsx_drop_empty: xlsxDropEmpty.value ? 1 : 0,
    xlsx_pallet_metrics: [...xlsxPalletMetrics.value],
  });
  if (data && data.settings) {
    await applyRemote(() => {
      settings.value = data.settings;
      syncXlsxFromSettings();
    });
  }
}

// ═══ Сторожа автосохранения ═══
// Чекбоксы и списки сохраняем быстро, поля ввода — с паузой, чтобы не слать
// запрос на каждую набранную цифру. Каждый блок шлёт свой запрос (как и
// раньше по кнопке), поэтому одна правка не затирает соседние настройки.
watch(notifyUsers, () => autoSave('notify', saveNotifyUsers), { deep: true });
watch([reminderOffsets, reminderChannels], () => autoSave('reminders', saveReminders), { deep: true });
watch([xlsxDropEmpty, xlsxPalletMetrics], () => autoSave('xlsx', saveXlsx), { deep: true });
watch([weeklyEnabled, weeklyDow, weeklyTime, weeklyWeeksAhead], () => autoSave('weekly', saveWeekly, 800));
watch([minOrderValue, minOrderUnit], () => autoSave('minorder', saveMinOrder, 800));
watch(defaultDeadline, () => autoSave('deadline', saveDefaultDeadline, 800));
watch(pauseMessage, () => autoSave('pause', savePauseMessage, 1000));
watch(ccEmails, () => autoSave('cc', saveCcEmails, 1200));

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
    selectedDeadlineAt.value = data.deadline_at || '';
    selectedDeadlineStatus.value = data.deadline_status || '';
    dayEmailStatus.value = data.email_status || null;
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

// Состояние КАЖДОГО дня в ленте дат. Признаки приходят с сервера вместе с
// датами: is_closed — приём окончен (дедлайн прошёл или день закрыт),
// forced_closed — день закрыли вручную.
// Не путать с dayIsClosed ниже — там только про выбранный день, по живому времени.
function wdForced(wd) {
  return !!wd.forced_closed || isDateForcedClosed(wd.date);
}
function wdClosed(wd) {
  return wdForced(wd) || !!wd.is_closed;
}
function wdTitle(wd) {
  const parts = [];
  if (wd.is_adhoc) parts.push('Внеплановая дата (довоз)');
  if (wdForced(wd)) parts.push('День закрыт вручную');
  else if (wd.is_closed) parts.push('Приём заявок окончен');
  else if (wd.deadline_str) parts.push('Приём до ' + wd.deadline_str);
  return parts.join(' · ');
}
const selectedDayInfo = computed(() => weekDates.value.find(w => w.date === selectedDate.value) || null);
const selectedDayDeadline = computed(() => selectedDayInfo.value?.deadline_str || '');
/** «2026-07-28 14:00» → «28.07 в 14:00» — читать в спешке проще. */
function fmtDeadlineHuman(raw) {
  if (!raw) return '';
  const m = String(raw).match(/(\d{4})-(\d{2})-(\d{2})[ T](\d{2}:\d{2})/);
  return m ? `${m[3]}.${m[2]} в ${m[4]}` : String(raw);
}
const selectedDayDeadlineFmt = computed(() => fmtDeadlineHuman(selectedDayDeadline.value));

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
    scheduleMuted.clear();
    for (const id of (result.mutedRestaurantIds || [])) scheduleMuted.add(Number(id));
    // Данные по аккаунтам-получателям относятся к конкретному поставщику —
    // при смене поставщика сбрасываем, чтобы не показать чужие данные.
    expandedRecipients.clear();
    for (const key of Object.keys(recipientsData)) delete recipientsData[key];
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
// id ресторанов с выключенными напоминаниями по текущему поставщику
const scheduleMuted = reactive(new Set());
// id ресторанов, которые снабжаются через склад (галочка в колонке «Через склад»)
const scheduleWarehouse = reactive(new Set());
const remMuteSaving = reactive(new Set());
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

// «Через склад» — признак пары поставщик+ресторан: поставщик привозит на склад,
// ресторан получает с ближайшей основной поставкой и видит свою дату.
function fillWarehouseFromItems(restaurants, items = []) {
  scheduleWarehouse.clear();
  for (const s of items || []) {
    if (Number(s.via_warehouse) !== 1) continue;
    const rest = restaurants.find(r => r.number == s.restaurant_number);
    if (rest) scheduleWarehouse.add(rest.id);
  }
}

function toggleScheduleWarehouse(r) {
  if (scheduleWarehouse.has(r.id)) scheduleWarehouse.delete(r.id);
  else scheduleWarehouse.add(r.id);
}

function buildSchedulesFromGrid(grid) {
  const items = [];
  for (const r of scheduleRestaurants.value) {
    for (let d = 1; d <= 7; d++) {
      if (grid[r.id]?.[d]) {
        const rule = deadlineRulesMap[d];
        const orderDay = rule?.active ? rule.deadline_dow : (d > 1 ? d - 1 : 7);
        items.push({ restaurant_id: r.id, order_day: orderDay, delivery_day: d, is_active: 1, via_warehouse: scheduleWarehouse.has(r.id) ? 1 : 0 });
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
    fillWarehouseFromItems(scheduleRestaurants.value, schedules.value);
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

// Вкл/выкл напоминаний по заявкам для ресторана — сохраняется сразу.
async function toggleReminderMute(r) {
  if (remMuteSaving.has(r.id)) return;
  const nextMuted = !scheduleMuted.has(r.id);
  remMuteSaving.add(r.id);
  try {
    await store.adminSetReminderMute(currentSupplierId.value, r.id, nextMuted);
    if (nextMuted) scheduleMuted.add(r.id); else scheduleMuted.delete(r.id);
  } catch (e) {
    toast.error('Ошибка', e.message);
  } finally {
    remMuteSaving.delete(r.id);
  }
}

// ═══ Получатели напоминаний по конкретным Telegram-аккаунтам ═══
// id ресторанов, у которых сейчас раскрыт список аккаунтов
const expandedRecipients = reactive(new Set());
const recipientsLoading = reactive(new Set());
// restaurantId -> { accounts: [...] }
const recipientsData = reactive({});
// ключи "restaurantId:roTgSubId" — какой чекбокс сейчас сохраняется
const recipientSaving = reactive(new Set());

async function toggleRecipients(r) {
  if (expandedRecipients.has(r.id)) {
    expandedRecipients.delete(r.id);
    return;
  }
  expandedRecipients.add(r.id);
  if (recipientsData[r.id]) return;
  recipientsLoading.add(r.id);
  try {
    const result = await store.adminGetReminderRecipients(currentSupplierId.value, r.id);
    recipientsData[r.id] = { accounts: result.accounts };
  } catch (e) {
    toast.error('Ошибка', e.message);
    expandedRecipients.delete(r.id);
  } finally {
    recipientsLoading.delete(r.id);
  }
}

async function toggleRecipient(r, acc) {
  const key = `${r.id}:${acc.ro_tg_sub_id}`;
  if (recipientSaving.has(key)) return;
  const nextSelected = !acc.selected;
  recipientSaving.add(key);
  try {
    await store.adminSetReminderRecipient(currentSupplierId.value, r.id, acc.ro_tg_sub_id, nextSelected);
    acc.selected = nextSelected;
  } catch (e) {
    toast.error('Ошибка', e.message);
  } finally {
    recipientSaving.delete(key);
  }
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

// ═══ Окно доступности товара по регионам/ресторанам ═══
const accessModal = ref({ open: false, idx: null, regions: [], restaurants: [], target: 'template' });
const accessDirectory = ref({ restaurants: [], regions: [] });
const accessRestSearch = ref('');
const accessFilteredRestaurants = computed(() => {
  const q = accessRestSearch.value.trim().toLowerCase();
  const list = accessDirectory.value.restaurants;
  if (!q) return list;
  return list.filter(r => String(r.number).includes(q) || String(r.address || '').toLowerCase().includes(q) || String(r.region || '').toLowerCase().includes(q));
});
// Выбор регионов/ресторанов не теряем молча — правило по всем окнам проекта.
const accessGuard = useDirtySnapshot();

/**
 * Одно окно на два случая: кому доступен товар (target='access') и кому
 * показывать примечание (target='note'). Наборы адресатов независимы —
 * товар может заказывать вся сеть, а пояснение видеть только Минск.
 */
async function openAccessModal(idx, target = 'access') {
  const t = templates.value[idx];
  accessRestSearch.value = '';
  const regions = target === 'note' ? t.note_regions : t.vis_regions;
  const rests = target === 'note' ? t.note_restaurants : t.vis_restaurants;
  accessModal.value = {
    open: true, idx, target,
    regions: [...(regions || [])],
    restaurants: [...(rests || [])].map(String),
  };
  accessGuard.mark(accessModal.value);
  if (!accessDirectory.value.restaurants.length) {
    try { accessDirectory.value = await store.adminGetRestaurantsDirectory(currentSupplierId.value); }
    catch (e) { toast.error('Ошибка', e.message); }
  }
}
function applyAccessModal() {
  const t = templates.value[accessModal.value.idx];
  if (t) {
    if (accessModal.value.target === 'note') {
      t.note_regions = [...accessModal.value.regions];
      t.note_restaurants = [...accessModal.value.restaurants];
    } else {
      t.vis_regions = [...accessModal.value.regions];
      t.vis_restaurants = [...accessModal.value.restaurants];
    }
  }
  accessModal.value.open = false;
}
function clearAccess() { accessModal.value.regions = []; accessModal.value.restaurants = []; }
async function closeAccessModal() {
  if (!(await accessGuard.confirmClose(accessModal.value))) return;
  accessModal.value.open = false;
}

// ═══ Внеплановая заявка (довоз) ═══
const adhoc = reactive({ open: false, restaurants: [], products: [], qty: {}, restaurant: '', date: '', deadline: '', loadingTpl: false, saving: false });
async function openAdhocModal() {
  if (!currentSupplierId.value) return;
  adhoc.open = true;
  adhoc.restaurant = '';
  adhoc.date = selectedDate.value || '';
  adhoc.deadline = '';
  adhoc.qty = {};
  adhoc.products = [];
  adhoc.loadingTpl = true;
  try {
    if (!adhoc.restaurants.length) {
      const dir = await store.adminGetRestaurantsDirectory(currentSupplierId.value);
      adhoc.restaurants = dir.restaurants || [];
    }
    const le = orderStore.settings.legalEntity;
    const tpl = await store.adminGetTemplates(currentSupplierId.value, le);
    // Только активные, не отключённые для заказа
    adhoc.products = (tpl || []).filter(t => !t.order_disabled && String(t.sku || '').trim());
  } catch (e) {
    toast.error('Ошибка', e.message);
  } finally {
    adhoc.loadingTpl = false;
  }
}
async function submitAdhoc() {
  if (!adhoc.restaurant || !adhoc.date) return;
  const items = adhoc.products
    .map(p => ({ sku: p.sku, product_id: p.product_id || null, product_name: p.product_name, qty: Number(adhoc.qty[p.sku]) || 0 }))
    .filter(it => it.qty > 0);
  if (!items.length) { toast.warning('Пусто', 'Впишите количество хотя бы одному товару'); return; }
  adhoc.saving = true;
  try {
    const res = await store.adminCreateAdhocOrder({
      supplier_id: currentSupplierId.value,
      restaurant_number: adhoc.restaurant,
      delivery_date: adhoc.date,
      deadline: adhoc.deadline || null,
      items,
    });
    if (res && res.error) { toast.error('Ошибка', res.error); return; }
    toast.success('Довоз создан', res.editable ? 'Ресторан может скорректировать до дедлайна' : 'Заявка финальная, ресторан её видит');
    const createdDate = adhoc.date;
    adhoc.open = false;
    // Переключаемся на дату довоза и обновляем — чтобы он сразу был виден в «Приёме».
    if (pageTab.value !== 'status') pageTab.value = 'status';
    selectedDate.value = createdDate;
    await loadStatus();
  } catch (e) {
    toast.error('Ошибка', e.message);
  } finally {
    adhoc.saving = false;
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
    note: '',
    order_disabled: 0,
    note_regions: [],
    note_restaurants: [],
    vis_regions: [],
    vis_restaurants: [],
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
    note: '',
    order_disabled: 0,
    note_regions: [],
    note_restaurants: [],
    vis_regions: [],
    vis_restaurants: [],
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
    // Справочник карточек общий для группы БК+ВМ: карточки Камако лежат под
    // «Бургер БК», и фильтр по одному юрлицу (ВМ) не находил ничего. Берём по
    // ГРУППЕ юрлиц и убираем дубли по SKU (один SKU может быть в двух юрлицах).
    const { data, error } = await db.from('products')
      .select('id,sku,name,multiplicity,legal_entity')
      .eq('supplier', supplierName)
      .in('legal_entity', getEntityGroup(templateLe.value))
      .eq('is_active', 1)
      .order('name')
      .limit(1000);
    if (error) throw new Error(error);
    const rows = data || [];
    if (!rows.length) {
      toast.warning('Нет товаров', 'У этого поставщика нет товаров в справочнике');
      return;
    }
    // Дедуп по SKU, предпочитая карточку выбранного юрлица.
    const bySku = new Map();
    for (const p of rows) {
      const sku = String(p.sku || '').trim();
      if (!sku) continue;
      if (!bySku.has(sku) || p.legal_entity === templateLe.value) bySku.set(sku, p);
    }
    const products = [...bySku.values()];
    templates.value = products.map((p, i) => ({
      product_id: p.product_id || p.id || '',
      sku: p.sku,
      product_name: p.product_name || p.name || '',
      sort_order: i * 10,
      multiplicity: p.multiplicity || null,
      min_qty: p.min_qty || null,
      note: '',
      note_regions: [],
    note_restaurants: [],
    vis_regions: [],
      vis_restaurants: [],
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
    const what = r?.weekly
      ? `Одно письмо за всю неделю: дней ${r.days ?? '—'}, позиций ${r.items_count ?? '—'}`
      : `Ресторанов: ${r.restaurants_count ?? '—'}`;
    toast.success('Отправлено', `Сводка ушла на почту поставщика. ${what}`);
    await loadStatus();
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
    // В заголовке листа — полное название поставщика и наше юрлицо-заказчик:
    // поставщик открывает файл отдельно от письма, и по листу должно быть
    // понятно, кому заявка и от кого.
    const supRow = allSuppliers.value.find(s => String(s.id) === String(currentSupplierId.value)) || null;
    const supplierName = (supRow?.full_name || '').trim() || supRow?.short_name || 'Поставщик';
    const fromLegalEntity = (supRow?.legal_entity || '').trim();
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
        fromLegalEntity,
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

// Lookup: { "restNum_sku" => [ { quantity, admin_qty, item_id, order_id, batch_no } ] }
//
// Список, а не одна позиция: у цеха ПРЦ ресторан с одной поставкой в неделю
// заказывает двумя партиями, и это две строки по одному SKU. Раньше вторая
// затирала первую — в матрице показывалось количество только одной партии.
const itemLookup = computed(() => {
  const map = {};
  for (const item of orderItems.value) {
    const key = `${item.restaurant_number}_${item.sku}`;
    (map[key] ||= []).push(item);
  }
  return map;
});
/** Первая позиция ячейки — для правки и юрлица. */
function firstItem(restNum, sku) { return itemLookup.value[`${restNum}_${sku}`]?.[0] || null; }

const displayProducts = computed(() => buildDisplayProducts(products.value));

function getDisplayItem(restNum, product) {
  const skus = product?.source_skus?.length ? product.source_skus : [product?.sku];
  let found = false;
  let originalQty = 0;
  let effectiveQty = 0;
  let hasAdmin = false;

  const parts = [];   // количества по партиям — для подписи под цифрой
  for (const sku of skus) {
    for (const item of (itemLookup.value[`${restNum}_${sku}`] || [])) {
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
      parts.push({
        batch: Number(item.batch_no) || 0,
        qty: isNaN(rawAdmin) ? (isNaN(rawQty) ? 0 : rawQty) : rawAdmin,
      });
    }
  }

  if (!found) return null;
  // Разбивка нужна, только когда позиции реально разложены по партиям.
  const batched = parts.filter(p => p.batch > 0);
  const showParts = batched.length > 1 && batched.some(p => p.batch > 1);
  return {
    quantity: originalQty,
    admin_qty: hasAdmin ? effectiveQty : null,
    parts: showParts ? batched.sort((a, b) => a.batch - b.batch) : null,
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

/** Разбивка ячейки по партиям — null, когда заказ не делится. */
function cellParts(restNum, product) {
  return getDisplayItem(restNum, product)?.parts || null;
}

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


// ─── Кратность и минимальное количество ───
// У товара в шаблоне могут стоять кратность отгрузки и минимум. Ресторану
// эти правила проверяет форма заявки, а правки закупщика раньше шли мимо
// проверок — так в заявку поставщику попадали числа, которые он не отгрузит.

/**
 * Правила товара из шаблона: { mult, min } (0 — правила нет).
 *
 * Кратность и минимум задаются по юрлицам: у «Бургер БК» и «Воглия Матта»
 * один товар может идти на разных условиях. Если юрлицо известно — берём
 * его правило. Если нет и условия у юрлиц разные, правило не применяем:
 * лучше не проверить, чем поругаться на нормальное количество.
 */
function qtyRules(product, legalEntity = null) {
  if (!product) return { mult: 0, min: 0 };
  const norm = (raw) => {
    const mult = parseFloat(raw?.multiplicity) || 0;
    const min = parseFloat(raw?.min_qty) || 0;
    return { mult: mult > 1 ? mult : 0, min: min > 0 ? min : 0 };
  };
  const rules = product.rules;
  if (rules && typeof rules === 'object') {
    if (legalEntity && rules[legalEntity]) return norm(rules[legalEntity]);
    const list = Object.values(rules);
    if (list.length > 1) {
      const first = norm(list[0]);
      const same = list.every(r => {
        const n = norm(r);
        return n.mult === first.mult && n.min === first.min;
      });
      if (!same) return { mult: 0, min: 0 };
      return first;
    }
    if (list.length === 1) return norm(list[0]);
  }
  return norm(product);
}

/**
 * Что не так с количеством. Ноль и пустое — это отказ от позиции,
 * их не трогаем.
 * @returns null | { text, options: [{ value, label }] }
 */
function qtyIssue(product, value, legalEntity = null) {
  const qty = parseFloat(value);
  if (!isFinite(qty) || qty <= 0) return null;
  const { mult, min } = qtyRules(product, legalEntity);
  if (!mult && !min) return null;

  const offGrid = mult > 0 && Math.abs(qty % mult) > 1e-9;
  const belowMin = min > 0 && qty < min;
  if (!offGrid && !belowMin) return null;

  const parts = [];
  if (offGrid) parts.push(`кратность ${fmtNum(mult)}`);
  if (belowMin) parts.push(`минимум ${fmtNum(min)}`);

  // Варианты: ближайшее вниз, ближайшее вверх и минимум (тоже кратный).
  const candidates = [];
  if (offGrid) {
    const down = Math.floor(qty / mult) * mult;
    const up = Math.ceil(qty / mult) * mult;
    if (down > 0 && (!min || down >= min)) candidates.push(down);
    if (!min || up >= min) candidates.push(up);
  }
  if (belowMin) {
    const target = mult ? Math.ceil(min / mult) * mult : min;
    candidates.push(target);
  }
  const seen = new Set();
  const options = [];
  for (const v of candidates.sort((a, b) => a - b)) {
    const key = v.toFixed(4);
    if (seen.has(key)) continue;
    seen.add(key);
    options.push({ value: v, label: fmtNum(v) });
  }
  return {
    text: `Товар отгружают по правилам: ${parts.join(', ')}. Введено ${fmtNum(qty)}.`,
    options,
  };
}

function fmtNum(v) {
  const n = Number(v);
  return Number.isInteger(n) ? String(n) : String(parseFloat(n.toFixed(2)));
}

/** Нарушает ли значение в ячейке правила товара — для подсветки. */
function cellViolates(restNum, product) {
  const item = getDisplayItem(restNum, product);
  if (!item) return false;
  const qty = item.admin_qty !== null && item.admin_qty !== undefined ? item.admin_qty : item.quantity;
  return qtyIssue(product, qty, cellLegalEntity(restNum, product)) !== null;
}

/** Юрлицо заявки ресторана — по нему выбирается правило товара. */
function cellLegalEntity(restNum, product) {
  const skus = product?.source_skus?.length ? product.source_skus : [product?.sku];
  for (const sku of skus) {
    const item = firstItem(restNum, sku);
    if (item?.legal_entity) return item.legal_entity;
  }
  return null;
}

function qtyRuleHint(product, legalEntity = null) {
  const { mult, min } = qtyRules(product, legalEntity);
  const parts = [];
  if (mult) parts.push(`кратно ${fmtNum(mult)}`);
  if (min) parts.push(`минимум ${fmtNum(min)}`);
  return parts.join(', ');
}

const qtyModal = ref({ show: false, text: '', options: [], value: 0, ctx: null });

function askQtyFix(ctx, issue) {
  qtyModal.value = { show: true, text: issue.text, options: issue.options, value: ctx.val, ctx };
}

async function applyQtyChoice(value) {
  const ctx = qtyModal.value.ctx;
  qtyModal.value.show = false;
  if (!ctx) return;
  // Окно одно на два места: ячейка сводки и позиция в карточке заявки.
  if (ctx.kind === 'order-item') {
    await commitOrderItemQty(ctx.item, value);
    return;
  }
  await commitQty(ctx.restNum, ctx.sku, value, ctx.item);
}


// ─── Правка заявки из списка ───
// Закупщик меняет количество прямо в карточке заявки. Правила товара
// (кратность, минимум) берём из шаблона поставщика — те же, что в сводке.
const orderItemSaving = ref(null);

function orderItemProduct(item) {
  return products.value.find(p => String(p.sku) === String(item.sku)) || null;
}
function orderItemRuleHint(item) {
  const hint = qtyRuleHint(orderItemProduct(item), viewedOrder.value?.legal_entity || null);
  return hint ? `(${hint})` : '';
}
function orderItemValue(item) {
  return item.admin_qty !== null && item.admin_qty !== undefined ? item.admin_qty : item.quantity;
}
function orderItemInput(item) {
  return item.admin_qty !== null && item.admin_qty !== undefined ? formatQtyValue(item.admin_qty) : '';
}
function orderItemViolates(item) {
  return qtyIssue(orderItemProduct(item), orderItemValue(item), viewedOrder.value?.legal_entity || null) !== null;
}

async function saveOrderItemQty(item, raw) {
  const text = String(raw ?? '').trim().replace(',', '.');
  // Пустое поле — снимаем нашу правку, остаётся то, что подал ресторан.
  const val = text === '' ? null : parseFloat(text);
  if (text !== '' && !isFinite(val)) { toast.error('Не число', 'Введите количество цифрами'); return; }

  const issue = val !== null
    ? qtyIssue(orderItemProduct(item), val, viewedOrder.value?.legal_entity || null)
    : null;
  if (issue) {
    askQtyFix({ kind: 'order-item', item, val }, issue);
    return;
  }
  await commitOrderItemQty(item, val);
}

async function commitOrderItemQty(item, val) {
  orderItemSaving.value = item.id;
  try {
    await store.adminUpdateQty({ item_id: item.id, admin_qty: val });
    item.admin_qty = val;
    toast.success('Сохранено', val === null ? 'Вернули количество ресторана' : `${item.sku}: ${formatQtyValue(val)}`);
    // Сводка приёма и список заявок должны показать то же самое.
    if (pageTab.value === 'status') loadStatus();
  } catch (e) {
    toast.error('Не сохранилось', e.message);
  } finally {
    orderItemSaving.value = null;
  }
}

function startEdit(restNum, sku) {
  const key = `${restNum}_${sku}`;
  const list = itemLookup.value[key] || [];
  // Сколько партий у ресторана — по графику, а не по заявке: у точки без
  // заявки позиций ещё нет, но полей всё равно должно быть два.
  const wantBatches = Math.max(
    Number(restaurants.value.find(r => String(r.number) === String(restNum))?.batches) || 1,
    list.length,
  );
  // Позиция разложена по партиям — по полю на партию, чтобы правка не ушла
  // целиком в первую из них.
  editParts.value = wantBatches > 1
    ? Array.from({ length: wantBatches }, (_, i) => {
        const batch = i + 1;
        const it = list.find(x => (Number(x.batch_no) || 1) === batch) || null;
        return {
          item: it,
          batch,
          // Без хвоста «.00»: в базе decimal, а правят целые лотки и штуки.
          value: it
            ? formatQtyValue(it.admin_qty !== null && it.admin_qty !== undefined ? it.admin_qty : it.quantity)
            : '',
        };
      })
    : [];
  const item = list[0] || null;
  editCell.value = key;
  editValue.value = item?.admin_qty !== null && item?.admin_qty !== undefined
    ? item.admin_qty
    : (item?.quantity || '');
  nextTick(() => {
    const el = document.querySelector('.so-cell-input');
    if (el) { el.focus(); el.select(); }
  });
}

/** Закрыть редактор без записи. */
function cancelEdit() {
  editCell.value = '';
  editParts.value = [];
}

/** Уход фокуса из блока партий — это конец правки, а не переход между полями. */
function onPartsBlur(e) {
  if (e.currentTarget.contains(e.relatedTarget)) return;
  saveEdit();
}

async function saveEdit() {
  if (!editCell.value) return;
  const match = editCell.value.match(/^(\d+)_(.+)$/);
  if (!match) { cancelEdit(); return; }
  const [, restNum, sku] = match;
  if (editParts.value.length > 1) return savePartsEdit(restNum, sku);
  const item = firstItem(restNum, sku);
  const val = parseFloat(String(editValue.value).replace(',', '.'));
  editCell.value = '';

  // Кратность и минимум: предупреждаем сразу, но последнее слово за закупщиком —
  // бывают договорённости с поставщиком в обход обычных правил.
  const product = products.value.find(p => p.sku === sku);
  const issue = qtyIssue(product, val, item?.legal_entity || null);
  if (issue) {
    askQtyFix({ restNum, sku, val, item }, issue);
    return;
  }
  await commitQty(restNum, sku, val, item);
}

/**
 * Запись количеств по партиям. Пишем только изменённые строки: у каждой партии
 * своя позиция заявки, и трогать соседнюю нельзя.
 * Правила товара (кратность, минимум) проверяем по сумме — предупреждаем,
 * но не мешаем: последнее слово за закупщиком.
 */
async function savePartsEdit(restNum, sku) {
  const parts = editParts.value;
  cancelEdit();
  let sum = 0;
  const changes = [];
  for (const pt of parts) {
    const raw = String(pt.value).replace(',', '.').trim();
    const val = raw === '' ? NaN : parseFloat(raw);
    if (!isNaN(val)) sum += val;
    if (!pt.item) {
      // Партии ещё нет в базе — создаём, только если ввели число.
      if (!isNaN(val)) changes.push({ pt, val });
      continue;
    }
    const now = pt.item.admin_qty !== null && pt.item.admin_qty !== undefined
      ? parseFloat(pt.item.admin_qty)
      : parseFloat(pt.item.quantity);
    if ((isNaN(val) && !isNaN(now)) || (!isNaN(val) && val !== now)) changes.push({ pt, val });
  }
  if (!changes.length) return;
  let needReload = false;
  try {
    const prod = products.value.find(p => p.sku === sku);
    for (const { pt, val } of changes) {
      if (pt.item?.item_id) {
        await store.adminUpdateQty({ item_id: pt.item.item_id, admin_qty: isNaN(val) ? null : val });
        pt.item.admin_qty = isNaN(val) ? null : val;
      } else {
        // Новая позиция за ресторан: заявки может не быть вовсе — сервер её создаст.
        const result = await store.adminUpdateQty({
          restaurant_number: restNum,
          delivery_date: selectedDate.value,
          sku,
          product_name: prod?.product_name || '',
          product_id: prod?.product_id || '',
          supplier_id: currentSupplierId.value,
          batch_no: pt.batch,
          admin_qty: isNaN(val) ? null : val,
        });
        needReload = needReload || !!result?.reload;
      }
    }
    const issue = qtyIssue(prod, sum, parts.find(p => p.item)?.item?.legal_entity || null);
    if (issue) toast.info('Сохранено, но не по правилам', issue);
    if (needReload) await loadStatus();
  } catch (e) {
    toast.error('Ошибка сохранения', e.message);
  }
}

/** Собственно запись количества (после проверок или выбора в окне). */
async function commitQty(restNum, sku, val, item) {
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

// Приём на выбранную дату закрыт: дедлайн прошёл или день закрыт вручную.
// Статус с сервера — снимок на момент загрузки. Дополнительно пересчитываем по
// «живому» времени (now тикает раз в минуту), чтобы после прохождения дедлайна
// строки без заявки сразу читались как «Закрыто», без перезагрузки страницы.
const dayIsClosed = computed(() => {
  if (isDateForcedClosed(selectedDate.value)) return true;
  if (selectedDeadlineStatus.value === 'closed') return true;
  if (selectedDeadlineAt.value) {
    const dl = Date.parse(selectedDeadlineAt.value);
    if (!isNaN(dl) && now.value >= dl) return true;
  }
  return false;
});

// Статус ресторана в таблице приёма. Пока приём открыт — «Не подано» (ещё ждём).
// После закрытия дня ждать уже нечего: показываем «Закрыто», как и у тех, кто
// подал, — чтобы статусы у закупок и у ресторана читались одинаково.
function restStatusLabel(r) {
  if (!r.order_status && dayIsClosed.value) return 'Закрыто';
  return statusLabel(r.order_status);
}

function restStatusClass(r) {
  if (!r.order_status && dayIsClosed.value) return 'st-locked so-st-closed-empty';
  return 'st-' + (r.order_status || 'none');
}

function restStatusTitle(r) {
  if (!r.order_status && dayIsClosed.value) return 'Приём закрыт — заявка так и не была подана';
  return '';
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
// qk('date') → 'date' обычно и 'so_date' у встроенного экрана.
const qk = (key) => (props.queryPrefix ? `${props.queryPrefix}_${key}` : key);

// Восстанавливаем из query при монтировании
{
  const q = route.query || {};
  const tabFromUrl = q[qk('tab')] ? String(q[qk('tab')]) : '';
  // Чужой ключ вкладки (остался от соседнего экрана) игнорируем: иначе
  // раздел откроется пустым — такой вкладки у него нет.
  if (tabFromUrl && PAGE_TABS.value.some(t => t.key === tabFromUrl)) pageTab.value = tabFromUrl;
  if (q[qk('date')]) selectedDate.value = String(q[qk('date')]);
  if (q[qk('status')]) listStatus.value = String(q[qk('status')]);
  if (q[qk('query')]) listQuery.value = String(q[qk('query')]);
  if (q[qk('skipOnly')] === '1') listSkipOnly.value = true;
  if (q[qk('from')]) listDeliveryFrom.value = String(q[qk('from')]);
  if (q[qk('to')]) listDeliveryTo.value = String(q[qk('to')]);
  if (q[qk('scheduleFilter')]) scheduleFilter.value = String(q[qk('scheduleFilter')]);
}

let urlSyncInit = false;
watch(
  [pageTab, selectedDate, listStatus, listQuery, listSkipOnly, listDeliveryFrom, listDeliveryTo, scheduleFilter],
  () => {
    if (!urlSyncInit) { urlSyncInit = true; return; }
    const q = { ...route.query };
    const set = (key, val) => { if (val) q[qk(key)] = val; else delete q[qk(key)]; };
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
/* Действия в строке — с зазором и по правому краю, иначе кнопки слипаются. */
.so-list-table .rom-td-actions {
  display: flex; gap: 6px; justify-content: flex-end; align-items: center;
}
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
.so-th-print { width: 78px; text-align: center; }
.so-td-print { text-align: center; }
.so-print-ls {
  padding: 3px 10px; border: 1px solid var(--tk-border); border-radius: 6px;
  background: var(--tk-bg-card); cursor: pointer; font-size: var(--tk-fz-sm);
  font-family: inherit; color: var(--tk-text); white-space: nowrap;
}
.so-print-ls:hover { border-color: var(--tk-accent); background: var(--tk-n-50); }
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
/* «Закрыто», но заявки так и не было — приглушённый вариант, чтобы в таблице
   было видно, кто подал до закрытия, а кто нет. */
.so-st-closed-empty { background: var(--tk-n-100); color: var(--tk-text-muted); }
.st-skip { background: var(--tk-n-100); color: var(--tk-text-muted); }
.so-auto-badge {
  display: inline-flex; align-items: center; margin-left: var(--tk-s-2); padding: 2px 6px;
  border-radius: var(--tk-r-sm); background: var(--tk-warning-soft); color: var(--tk-warning);
  font-size: var(--tk-fz-xs); font-weight: var(--tk-fw-semibold);
}
.so-adhoc-tag {
  display: inline-block; margin-left: 6px; padding: 1px 7px; border-radius: 10px;
  background: #EDE7F6; color: #5E35B1; font-size: 10px; font-weight: 700;
  letter-spacing: 0.3px; vertical-align: middle;
}
.so-date-adhoc { border-color: #B39DDB !important; }
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
/* Примечание товара и кнопка доступа в редакторе шаблона */
.so-tpl-note-input { width: 100%; box-sizing: border-box; margin-top: 4px; font-size: 12px; }
.so-tpl-disable-toggle { display: inline-flex; align-items: center; gap: 6px; margin-top: 6px; font-size: 12px; color: #8A5320; cursor: pointer; user-select: none; }
.so-tpl-disable-toggle input { cursor: pointer; }
.so-tpl-row-disabled td { opacity: 0.55; }
.so-tpl-row-disabled td:first-child,
.so-tpl-row-disabled .so-tpl-disable-toggle { opacity: 1; }
.so-tpl-row-disabled .so-tpl-disable-toggle { color: #B3261E; font-weight: 600; }
.so-tpl-access-on { background: #FEF6EC !important; border-color: #F2C9A0 !important; color: #8A5320 !important; }
.so-access-modal .rom-modal-body { display: flex; flex-direction: column; gap: 14px; }
.so-access-block { border: 1px solid var(--tk-border); border-radius: 8px; padding: 10px 12px; }
.so-access-title { font-weight: 700; font-size: 13px; margin-bottom: 6px; }
.so-access-rest-list { max-height: 260px; overflow-y: auto; display: flex; flex-direction: column; gap: 2px; }
.rom-modal-foot { display: flex; justify-content: space-between; gap: 10px; padding: 12px var(--tk-s-5) var(--tk-s-5); }
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
.so-rem-toggle { border: none; border-radius: 6px; padding: 3px 10px; font-size: 12px; font-weight: 700; cursor: pointer; }
.so-rem-toggle.on { background: var(--tk-success-soft); color: var(--tk-success); }
.so-rem-toggle.off { background: var(--tk-n-100); color: var(--tk-text-muted); }
.so-rem-toggle:disabled { opacity: 0.5; cursor: default; }
.so-accounts-btn { font-size: var(--tk-fz-xs); padding: 3px 10px; }

/* ═══ Получатели напоминаний по аккаунтам (подстрока в сетке графиков) ═══ */
.so-recipients-row td { padding: 0 !important; }
.so-recipients-cell {
  background: var(--tk-n-50); padding: var(--tk-s-3) var(--tk-s-4) !important;
  text-align: left !important; border-top: 1px dashed var(--tk-border);
}
.so-recipients-loading { display: flex; justify-content: center; padding: var(--tk-s-2); }
.so-recipients-empty { font-size: var(--tk-fz-sm); color: var(--tk-text-muted); }
.so-recipients-list { display: flex; flex-wrap: wrap; gap: var(--tk-s-2) var(--tk-s-4); align-items: center; }
.so-recipient-item {
  display: flex; align-items: center; gap: 6px; font-size: var(--tk-fz-sm);
  color: var(--tk-text); cursor: pointer; user-select: none;
}
.so-recipient-item input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; accent-color: var(--tk-accent); }
.so-recipient-username { color: var(--tk-text-muted); font-size: var(--tk-fz-xs); }
.so-recipients-hint {
  flex: 1 0 100%; margin: var(--tk-s-1) 0 0; font-size: var(--tk-fz-xs); color: var(--tk-warning);
}

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
/* Зелёная подсветка подавших заявку обязана быть сильнее зебры:
   иначе серая полоска чётных строк перекрывает её, и зелёными выглядят
   только нечётные строки — со стороны это читается как случайный набор. */
.so-pivot-table tbody tr.rom-row-submitted { background: var(--tk-success-soft); }
/* Наведение НЕ меняет фон строки, а кладёт поверх лёгкий слой: иначе цвет
   статуса (зелёный «подал», серый «не нужна») пропадал под курсором и
   таблица теряла читаемость ровно там, куда смотрит человек. */
.so-pivot-table tbody tr:hover td {
  box-shadow: inset 0 0 0 9999px rgba(232, 122, 30, .07);
}
.so-pivot-table tbody tr:hover td.so-td-bad {
  box-shadow: inset 0 0 0 9999px rgba(232, 122, 30, .07), inset 0 0 0 1.5px rgba(217, 102, 26, .55);
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
/* Партии под общей цифрой: «160 · 130» мелко и серым. */
.so-qty-parts {
  display: block; margin-top: 1px; font-size: 10.5px; font-weight: var(--tk-fw-semibold);
  color: var(--tk-text-muted); white-space: nowrap;
}
.so-qty-parts i { font-style: normal; }
.so-qty-parts i + i::before { content: ' · '; color: var(--tk-n-300); }
/* Правка по партиям: столбик коротких полей с номером партии слева. */
.so-cell-parts-edit { display: flex; flex-direction: column; gap: 3px; align-items: center; }
.so-cell-part { display: inline-flex; align-items: center; gap: 4px; }
.so-cell-part-n {
  min-width: 12px; font-size: 10px; font-weight: var(--tk-fw-bold); color: var(--tk-text-muted);
}
.so-cell-input-part { width: 48px; padding: 1px var(--tk-s-1); }

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
/* Итог последней отправки письма за день — рядом с кнопкой «На почту». */
.so-mail-state {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 0 10px; height: 32px; border-radius: 8px;
  font-size: var(--tk-fz-xs); font-weight: var(--tk-fw-medium); white-space: nowrap;
}
.so-mail-state.is-ok { background: rgba(22, 163, 74, .10); color: #15803d; border: 1px solid rgba(22, 163, 74, .28); }
.so-mail-state.is-bad { background: rgba(220, 38, 38, .10); color: #b91c1c; border: 1px solid rgba(220, 38, 38, .30); }

/* А вот когда сводке некуда уйти — это уже предупреждение, его видно сразу. */
.so-notify-warn {
  margin-top: 10px; padding: 8px 12px; border-radius: 8px;
  border: 1px solid rgba(217, 119, 6, .35); background: rgba(245, 158, 11, .10);
  color: #92400e; font-size: var(--tk-fz-sm); line-height: 1.45;
}

/* Предупреждение о неверном размере коробки в справочнике */
.so-autosave-bar {
  font-size: var(--tk-fz-sm); color: var(--tk-text-muted);
  padding: 6px 10px; margin-bottom: var(--tk-s-3);
  background: var(--tk-bg-subtle, #F7F5F2); border-radius: 6px;
}
.so-autosave-bar.busy { color: #2E7D32; }

.so-box-warn {
  margin-top: var(--tk-s-3); padding: 10px 12px; border-radius: 8px;
  background: #FEF6EC; border: 1px solid #F2C9A0; color: #8A5320;
  font-size: var(--tk-fz-sm); line-height: var(--tk-lh-base);
}
.so-box-warn-list { margin: 6px 0 0; padding-left: 18px; }
.so-box-warn-list li { margin-bottom: 2px; }

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
.so-icon-picker { display: flex; flex-wrap: wrap; gap: 8px; }
.so-icon-opt {
  width: 40px; height: 40px; flex-shrink: 0; padding: 0;
  display: inline-flex; align-items: center; justify-content: center;
  border: 1.5px solid var(--tk-border); border-radius: 10px; cursor: pointer;
  background: var(--tk-bg-card); line-height: 1; transition: transform .1s, box-shadow .1s, border-color .1s;
}
.so-icon-opt:hover { transform: translateY(-1px); box-shadow: 0 3px 8px rgba(0,0,0,.12); }
.so-icon-opt.active { border-color: var(--tk-accent); box-shadow: 0 0 0 2px var(--tk-accent); }
.so-icon-opt :deep(svg) { width: 24px; height: 24px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.so-icon-auto {
  width: auto; padding: 0 12px; font-family: inherit; font-size: 12px; font-weight: 700;
  color: var(--tk-text-muted); background: var(--tk-bg-subtle, #f5f5f5);
}
.so-icon-auto.active { color: var(--tk-accent); }
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

/* ── Количество не по правилам товара ── */
.so-td-bad {
  background: rgba(232, 122, 30, .12);
  box-shadow: inset 0 0 0 1.5px rgba(217, 102, 26, .55);
}
.so-qty-modal { max-width: 460px; padding: 20px 22px; }
.so-qty-modal-title { margin: 0 0 8px; font-size: 17px; font-weight: 800; color: #3A2418; }
.so-qty-modal-text { margin: 0 0 14px; font-size: 13.5px; line-height: 1.5; color: #5F4B38; }
.so-qty-options { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
.so-qty-opt {
  flex: 1 1 130px; padding: 11px 14px; border: 0; border-radius: 11px;
  background: linear-gradient(135deg, #E87A1E 0%, #D9661A 100%); color: #fff;
  font: inherit; font-size: 13.5px; font-weight: 700; cursor: pointer;
  box-shadow: 0 4px 12px rgba(232, 122, 30, .22);
}
.so-qty-opt:hover { filter: brightness(1.06); }
.so-qty-opt b { font-size: 15px; }
.so-qty-modal-actions { display: flex; justify-content: flex-end; gap: 8px; }
.so-qty-keep { border-color: #E4D9CB; }

/* ═══ Оформление модуля: разделы, даты, действия ═══ */
.so-seg {
  display: inline-flex; padding: 3px; gap: 2px;
  background: #F4EDE4; border-radius: 12px; max-width: 100%;
  overflow-x: auto; flex-wrap: nowrap;
}
.so-seg-tabs { margin-bottom: 14px; }
.so-seg-btn {
  flex: 0 0 auto; padding: 7px 15px; border: 0; border-radius: 9px;
  background: transparent; font: inherit; font-size: 13px; font-weight: 700;
  color: #6B5544; cursor: pointer; white-space: nowrap;
}
.so-seg-btn:hover { color: #C25E12; }
.so-seg-btn.active { background: #fff; color: #3A2418; box-shadow: 0 1px 4px rgba(74, 32, 19, .12); }

/* Даты: одна лента с прокруткой вместо двух рядов кнопок */
.so-dates {
  display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
  margin: 12px 0 14px;
}
/* Лента занимает всю ширину и прокручивается, а кнопки управления днём
   уходят на свою строку: рядом они «обрезали» последнюю дату. */
.so-dates-strip {
  display: flex; gap: 6px; overflow-x: auto; padding-bottom: 4px;
  flex: 1 1 100%; min-width: 0;
  scrollbar-width: thin;
}
.so-dates-strip::-webkit-scrollbar { height: 6px; }
.so-dates-strip::-webkit-scrollbar-thumb { background: #E4D9CB; border-radius: 3px; }
.so-date {
  flex: 0 0 auto; display: flex; flex-direction: column; align-items: center; gap: 1px;
  min-width: 74px; padding: 7px 11px;
  border: 1.5px solid #E4D9CB; border-radius: 11px; background: #fff;
  font: inherit; cursor: pointer; position: relative;
  transition: border-color .14s ease, box-shadow .14s ease;
}
.so-date:hover { border-color: #C4B8A8; }
.so-date.is-active:hover { border-color: transparent; filter: brightness(1.04); }
.so-date.is-adhoc:hover { border-color: #E8A765; }
.so-date-day { font-size: 12px; font-weight: 800; color: #3A2418; text-transform: uppercase; }
.so-date-num { font-size: 11.5px; font-weight: 600; color: #9A8F80; }
.so-date.is-closed { background: #F7F3ED; }
.so-date.is-closed .so-date-day { color: #8A7F72; }
/* Выбранный день сильнее любого состояния: иначе закрытый день, на который
   смотрит закупщик, сливался с остальными и было непонятно, что выбрано. */
.so-date.is-active {
  border-color: transparent; background: linear-gradient(135deg, #E87A1E 0%, #D9661A 100%);
  box-shadow: 0 5px 14px rgba(232, 122, 30, .26);
}
.so-date.is-active .so-date-day,
.so-date.is-active .so-date-num { color: #fff; }
/* День, закрытый вручную, отличаем пунктиром — это решение человека,
   а не наступивший дедлайн. */
.so-date.is-forced { border-style: dashed; border-color: #C4B8A8; }
.so-date-state { font-size: 9.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .02em; }
.so-date-state.open { color: #2E7D32; }
.so-date-state.closed { color: #A8988A; }
.so-date.is-active .so-date-state { color: rgba(255, 255, 255, .85); }
.so-day-note { font-size: 12.5px; font-weight: 700; color: #8A7F72; }
.so-date.is-adhoc { border-color: #F0C89A; }
.so-date-tag {
  margin-top: 2px; padding: 1px 6px; border-radius: 8px;
  background: rgba(232, 122, 30, .16); color: #C25E12;
  font-size: 9.5px; font-weight: 800; text-transform: uppercase;
}
.so-date.is-active .so-date-tag { background: rgba(255, 255, 255, .25); color: #fff; }
.so-dates-side { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; flex: 1 1 100%; }
.so-date-picker { width: 150px; }
.so-mini-btn {
  padding: 7px 12px; border: 1.5px solid #E4D9CB; border-radius: 9px; background: #fff;
  font: inherit; font-size: 12.5px; font-weight: 700; color: #5F4B38; cursor: pointer;
}
.so-mini-btn:hover { border-color: #C4B8A8; }
.so-mini-btn.is-close-day { color: #C0392B; border-color: #E9B4AF; }
.so-mini-btn.is-close-day:hover { background: #FFF1F0; }
.so-mini-btn.is-open-day { color: #2E7D32; border-color: #A5D6A7; }

/* Действия: главные кнопки на виду, редкие — в меню «Ещё» */
.so-actions {
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
  margin-bottom: 12px;
}
.so-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 9px 15px; border: 1.5px solid #E4D9CB; border-radius: 10px;
  background: #fff; font: inherit; font-size: 13px; font-weight: 700;
  color: #5F4B38; cursor: pointer; white-space: nowrap;
  transition: border-color .14s ease, color .14s ease, filter .14s ease;
}
.so-btn:hover:not(:disabled) { border-color: #C4B8A8; }
.so-btn:disabled { opacity: .5; cursor: default; }
.so-btn-primary {
  background: linear-gradient(135deg, #4A2013 0%, #7A3D22 100%);
  border-color: transparent; color: #fff;
}
.so-btn-primary:hover:not(:disabled) { filter: brightness(1.1); }
.so-btn-accent {
  background: linear-gradient(135deg, #E87A1E 0%, #D9661A 100%);
  border-color: transparent; color: #fff;
  box-shadow: 0 4px 12px rgba(232, 122, 30, .22);
}
.so-btn-accent:hover:not(:disabled) { filter: brightness(1.06); }

/* Excel + выбор дней: одна кнопка со стрелкой */
.so-split { display: inline-flex; }
.so-split-main { border-radius: 10px 0 0 10px; border-right-width: 0; }
.so-split-arrow {
  border-radius: 0 10px 10px 0; padding: 9px 10px; font-size: 12px; color: #8A7F72;
}
.so-split-arrow.active { background: #FBF6F0; color: #C25E12; border-color: #E0C9AE; }
.so-split-count {
  min-width: 20px; height: 19px; padding: 0 6px; border-radius: 10px;
  background: rgba(232, 122, 30, .16); color: #C25E12;
  font-size: 11.5px; font-weight: 800;
  display: inline-flex; align-items: center; justify-content: center;
}

/* Действия про не подавших — вместе, с общей подписью */
.so-group {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 5px 10px 5px 12px; border: 1.5px dashed #E4D9CB; border-radius: 10px;
}
.so-group-label { font-size: 12px; font-weight: 700; color: #8A7F72; }
.so-chip-btn {
  padding: 5px 10px; border: 1.5px solid #E4D9CB; border-radius: 8px; background: #fff;
  font: inherit; font-size: 12.5px; font-weight: 700; color: #5F4B38; cursor: pointer;
}
.so-chip-btn:hover:not(:disabled) { border-color: #E87A1E; color: #C25E12; }
.so-chip-btn:disabled { opacity: .45; cursor: default; }

/* Обновление — значок рядом с поиском, чтобы не занимать место кнопкой */
.so-icon-btn {
  width: 38px; height: 38px; border: 1.5px solid #E4D9CB; border-radius: 10px;
  background: #fff; color: #6B5544; font-size: 18px; line-height: 1; cursor: pointer;
  display: inline-flex; align-items: center; justify-content: center;
}
.so-icon-btn:hover:not(:disabled) { border-color: #E87A1E; color: #C25E12; }
.so-icon-btn:disabled { opacity: .55; cursor: default; }
.so-icon-btn .is-spin { display: inline-block; animation: so-spin 1s linear infinite; }
@keyframes so-spin { to { transform: rotate(360deg); } }

.so-more { position: relative; }
.so-more-menu {
  position: absolute; top: calc(100% + 6px); left: 0; z-index: 30;
  min-width: 230px; padding: 6px;
  border: 1.5px solid #EFE7DC; border-radius: 12px; background: #fff;
  box-shadow: 0 10px 28px rgba(74, 32, 19, .14);
  display: flex; flex-direction: column; gap: 2px;
}
.so-more-menu button {
  padding: 9px 11px; border: 0; border-radius: 8px; background: transparent;
  font: inherit; font-size: 13px; font-weight: 600; color: #3A2418;
  text-align: left; cursor: pointer;
}
.so-more-menu button:hover:not(:disabled) { background: #FBF6F0; color: #C25E12; }
.so-more-menu button:disabled { opacity: .45; cursor: default; }

.so-actions-right { display: flex; align-items: center; gap: 8px; margin-left: auto; flex-wrap: wrap; }

@media (max-width: 760px) {
  .so-actions-right { margin-left: 0; width: 100%; }
  .so-filter-input { flex: 1 1 auto; }
  .so-dates-side { width: 100%; }
}

/* Плитки «подано / не подано / всего» — тем же языком, что карточки */
.rom-stats { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
.rom-stat {
  display: flex; align-items: baseline; gap: 7px;
  padding: 9px 15px; border: 1.5px solid #EFE7DC; border-radius: 12px; background: #fff;
}
.rom-stat-value { font-size: 20px; font-weight: 800; color: #3A2418; font-variant-numeric: tabular-nums; }
.rom-stat-label { font-size: 12.5px; font-weight: 600; color: #8A7F72; }
.rom-stat-pending { color: #C25E12; }

/* ── Общий каркас вкладок: карточки, секции, панели ── */
.so-card {
  padding: 16px 18px; margin-bottom: 14px;
  border: 1.5px solid #EFE7DC; border-radius: 14px; background: #fff;
}
/* Для таблиц: карточка без внутренних полей, но с прокруткой */
.so-card-flush { padding: 0; overflow-x: auto; }

.so-section-title {
  font-size: 15.5px; font-weight: 800; color: #4A2013; margin: 0 0 4px;
}
.so-section-title-flat { margin: 0; }
.so-section-title-top { margin: 20px 0 4px; }
.so-section-hint { font-size: 12.5px; color: #8A7F72; line-height: 1.45; margin: 0 0 12px; }
.so-section-hint-flat { margin: 4px 0 10px; }

/* Панель над таблицей: фильтры слева, сохранение справа */
.so-panel {
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
  padding: 12px 14px; margin-bottom: 12px;
  border: 1.5px solid #EFE7DC; border-radius: 14px; background: #fff;
}
.so-panel-label { font-size: 12.5px; font-weight: 700; color: #6B5544; }
.so-panel-save { margin-left: auto; }

.so-settings-block { margin-bottom: 14px; }
.so-ov-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.so-chip-btn.is-close-day { color: #C0392B; border-color: #E9B4AF; }
.so-chip-btn.is-close-day:hover:not(:disabled) { background: #FFF1F0; }
.so-chip-btn.is-open-day { color: #2E7D32; border-color: #A5D6A7; }

.so-autosave-chip {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 12px; margin-bottom: 12px;
  border-radius: 20px; background: #F4EDE4; color: #6B5544;
  font-size: 12.5px; font-weight: 600;
}
.so-autosave-chip.busy { background: rgba(232, 122, 30, .14); color: #C25E12; }

/* ── Список заявок ── */
.so-filters { align-items: flex-end; row-gap: 10px; }
.so-field { display: flex; flex-direction: column; gap: 4px; }
.so-field-grow { flex: 1 1 220px; min-width: 180px; }
.so-field-label { font-size: 11.5px; font-weight: 700; color: #8A7F72; }
.so-field-pair { display: flex; align-items: center; gap: 5px; }
.so-field-dash { color: #C4B8A8; }
.so-field-inline { align-self: center; }
.so-filters-actions { display: flex; align-items: center; gap: 8px; margin-left: auto; }
.so-list-count { font-size: 13px; color: #6B5544; margin-bottom: 8px; }
.so-list-count b { color: #3A2418; }
.so-list-rest { white-space: normal; }
.so-list-rest-num {
  display: inline-block; margin-right: 8px; padding: 2px 8px; border-radius: 8px;
  background: #F4EDE4; color: #3A2418; font-size: 12px; font-weight: 800;
}
.so-list-rest-addr { color: #5F4B38; }
.so-list-dim { color: #8A7F72; }
.so-list-num { text-align: right !important; font-variant-numeric: tabular-nums; white-space: nowrap; }
.so-empty-block { text-align: center; color: #8A7F72; font-size: 13.5px; padding: 30px 20px; }
.so-empty-title { font-size: 16px; font-weight: 800; color: #3A2418; margin-bottom: 6px; }

/* ── Правка количеств в карточке заявки ── */
.so-modal-qty-col { width: 150px; text-align: right !important; }
.so-modal-qty-input {
  width: 110px; padding: 7px 9px; border: 1.5px solid #E4D9CB; border-radius: 9px;
  font: inherit; font-size: 13.5px; font-weight: 700; text-align: right; color: #2E1C10;
}
.so-modal-qty-input:focus { outline: 0; border-color: #E87A1E; box-shadow: 0 0 0 3px rgba(232, 122, 30, .14); }
.so-modal-qty-input.is-bad { border-color: #D9661A; background: rgba(232, 122, 30, .09); }
.so-modal-rule { margin-left: 6px; font-size: 11.5px; color: #8A7F72; }
.so-modal-note { margin: 10px 0 0; font-size: 12px; color: #8A7F72; line-height: 1.45; }

.so-tpl-note-row { display: flex; align-items: center; gap: 6px; }
.so-tpl-note-row .so-tpl-note-input { flex: 1 1 auto; min-width: 0; }
.so-tpl-note-aud {
  flex: 0 0 auto; padding: 5px 9px; border: 1.5px solid #E4D9CB; border-radius: 8px;
  background: #fff; font: inherit; font-size: 11.5px; font-weight: 700;
  color: #8A7F72; cursor: pointer; white-space: nowrap;
}
.so-tpl-note-aud:hover { border-color: #E87A1E; color: #C25E12; }
.so-tpl-note-aud.is-limited { background: rgba(232, 122, 30, .12); color: #C25E12; border-color: #F0C89A; }
</style>
