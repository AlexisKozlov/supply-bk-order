<template>
  <div class="sc">
    <div class="sc-top">
      <h1 class="sc-title">Сбор остатков</h1>
      <button class="sc-btn fill" @click="openCreateModal">+ Новый сбор</button>
    </div>

    <!-- Retry banner -->
    <div v-if="loadError && !activeCollection" class="retry-banner">
      <span>Не удалось загрузить данные</span>
      <button class="btn secondary small" @click="loadCollections">Повторить</button>
    </div>

    <!-- Collections list -->
    <div v-if="!activeCollection" class="sc-list">
      <div v-if="loading" class="sc-empty"><BurgerSpinner text="Загрузка..." /></div>
      <div v-else-if="!collections.length && !loadError" class="sc-empty">Нет сессий сбора. Создайте первую.</div>
      <div
        v-for="c in collections" :key="c.id"
        class="sc-card"
        :class="{ closed: c.status === 'closed' }"
        @click="openCollection(c)"
      >
        <div class="sc-card-top">
          <div class="sc-card-name">{{ c.name }}</div>
          <span class="sc-tag" :class="c.status === 'active' ? 'green' : 'gray'">
            {{ c.status === 'active' ? 'Активен' : 'Закрыт' }}
          </span>
        </div>
        <div class="sc-card-meta">
          {{ c.created_by || '---' }} · {{ fmtDate(c.created_at) }}
        </div>
        <div v-if="c.deadline_at" class="sc-card-deadline" :class="{ late: c.status === 'active' && deadlinePassed(c.deadline_at) }">
          ⏰ до {{ fmtDeadline(c.deadline_at) }}
          <span v-if="c.status === 'active' && deadlinePassed(c.deadline_at)">· срок вышел</span>
        </div>
      </div>
    </div>

    <!-- Active collection detail -->
    <div v-if="activeCollection" class="sc-detail">
      <div class="sc-detail-bar">
        <button class="sc-btn outline" @click="activeCollection = null; collectionData = null; responseFilter = ''; responseStatus = 'all'; sortKey = 'restaurant'; sortDir = 'asc';">← Назад</button>
        <div class="sc-detail-info">
          <div class="sc-detail-name">{{ activeCollection.name }}</div>
          <span class="sc-tag" :class="activeCollection.status === 'active' ? 'green' : 'gray'">
            {{ activeCollection.status === 'active' ? 'Активен' : 'Закрыт' }}
          </span>
          <button
            class="sc-deadline-chip"
            :class="{ late: activeCollection.status === 'active' && deadlinePassed(activeCollection.deadline_at) }"
            @click="openDeadline"
            title="Изменить срок сдачи"
          >
            <template v-if="activeCollection.deadline_at">
              ⏰ до {{ fmtDeadline(activeCollection.deadline_at) }}
              <span v-if="activeCollection.status === 'active' && deadlinePassed(activeCollection.deadline_at)">· срок вышел</span>
            </template>
            <template v-else>⏰ срок не задан</template>
          </button>
        </div>
        <div class="sc-detail-actions">
          <button v-if="activeCollection.status === 'active'" class="sc-btn outline" @click="notifyRestaurants" :disabled="notifying" title="Отправит напоминание в Telegram только тем ресторанам, кто ещё не заполнил остатки">
            {{ notifying ? 'Отправка...' : '🔔 Напомнить не заполнившим' }}
          </button>
          <button v-if="activeCollection.status === 'active'" class="sc-btn outline" @click="openEditProducts">
            Изменить товары
          </button>
          <button class="sc-btn outline" @click="openRename">Переименовать</button>
          <button v-if="activeCollection.status === 'active'" class="sc-btn outline red-text" @click="askCloseCollection">
            Закрыть сбор
          </button>
          <button v-else class="sc-btn outline green-text" @click="askReopenCollection">
            Переоткрыть сбор
          </button>
          <button class="sc-btn outline" @click="duplicateCollection">Копировать сбор</button>
          <button class="sc-btn outline red-text" @click="askDeleteCollection">Удалить</button>
        </div>
      </div>

      <!-- Products & data -->
      <div v-if="collectionData" class="sc-data">
        <!-- Summary bar -->
        <div class="sc-summary">
          <div class="sc-summary-item">
            <div class="sc-summary-num">{{ collectionData.products?.length || 0 }}</div>
            <div class="sc-summary-lbl">Товаров</div>
          </div>
          <div class="sc-summary-item">
            <div class="sc-summary-num">{{ uniqueRestaurants }}</div>
            <div class="sc-summary-lbl">Ресторанов</div>
          </div>
          <div class="sc-summary-item">
            <div class="sc-summary-num">{{ collectionData.data?.length || 0 }}</div>
            <div class="sc-summary-lbl">Партий</div>
          </div>
          <div style="margin-left: auto; display: flex; gap: 6px;">
            <button v-if="activeCollection.status === 'active'" class="sc-btn sm outline" @click="openEditProducts">Изменить товары</button>
            <button class="sc-btn sm outline" @click="openPricesEditor">💰 Цены</button>
            <button class="sc-btn sm outline" @click="exportExcel">Excel</button>
            <button class="sc-btn sm outline" @click="refreshData">Обновить</button>
          </div>
        </div>

        <!-- Filter -->
        <div v-if="mergedRows.length" class="sc-filter-bar">
          <input
            v-model="responseFilter"
            type="text"
            class="sc-input sc-filter-input"
            placeholder="Поиск по номеру, городу или адресу..."
          />
          <div class="sc-status-chips">
            <button
              class="sc-status-chip"
              :class="{ active: responseStatus === 'all' }"
              @click="responseStatus = 'all'"
            >Все <span class="sc-chip-count">{{ mergedRows.length }}</span></button>
            <button
              class="sc-status-chip"
              :class="{ active: responseStatus === 'filled' }"
              @click="responseStatus = 'filled'"
            >Заполнили <span class="sc-chip-count">{{ filledCount }}</span></button>
            <button
              class="sc-status-chip"
              :class="{ active: responseStatus === 'missing' }"
              @click="responseStatus = 'missing'"
            >Не заполнили <span class="sc-chip-count">{{ missingRestaurants.length }}</span></button>
          </div>
          <span v-if="responseFilter || responseStatus !== 'all'" class="sc-filter-count">
            {{ filteredRows.length }} из {{ mergedRows.length }}
          </span>
        </div>

        <!-- Unified table: Restaurant | Product1 | Product2 | ... -->
        <div v-if="mergedRows.length" class="sc-tbl-wrap">
          <table class="sc-tbl">
            <thead>
              <tr>
                <th class="col-num sortable" :rowspan="anyExpanded ? 2 : 1" @click="toggleSort('restaurant')">Ресторан <span class="sort-arrow">{{ sortKey === 'restaurant' ? (sortDir === 'asc' ? '▲' : '▼') : '⇅' }}</span></th>
                <th class="col-city sortable" :rowspan="anyExpanded ? 2 : 1" @click="toggleSort('city')">Город <span class="sort-arrow">{{ sortKey === 'city' ? (sortDir === 'asc' ? '▲' : '▼') : '⇅' }}</span></th>
                <th class="col-addr sortable" :rowspan="anyExpanded ? 2 : 1" @click="toggleSort('address')">Адрес <span class="sort-arrow">{{ sortKey === 'address' ? (sortDir === 'asc' ? '▲' : '▼') : '⇅' }}</span></th>
                <th class="col-time sortable" :rowspan="anyExpanded ? 2 : 1" @click="toggleSort('time')">Заполнено <span class="sort-arrow">{{ sortKey === 'time' ? (sortDir === 'asc' ? '▲' : '▼') : '⇅' }}</span></th>
                <th
                  v-for="prod in collectionData.products"
                  :key="prod.id"
                  class="col-prod prod-group-head"
                  :class="{ expandable: prod.need_expiry, expanded: isExpanded(prod) }"
                  :colspan="isExpanded(prod) ? expiryDatesFor(prod.id).length + 1 : 1"
                  :rowspan="!isExpanded(prod) && anyExpanded ? 2 : 1"
                  @click="prod.need_expiry ? toggleExpand(prod) : toggleSort('prod_' + prod.id)"
                >
                  <div>
                    <span v-if="prod.product_sku" class="th-sku-inline">{{ prod.product_sku }}</span>
                    {{ prod.product_name }}
                    <span v-if="prod.need_expiry" class="prod-toggle-icon">{{ isExpanded(prod) ? '▾' : '▸' }}</span>
                    <span v-else class="sort-arrow">{{ sortKey === 'prod_' + prod.id ? (sortDir === 'asc' ? '▲' : '▼') : '⇅' }}</span>
                  </div>
                  <div class="th-unit">{{ unitLabel(prod.unit) }}</div>
                  <div v-if="prod.need_expiry && !isExpanded(prod)" class="th-flag">срок · нажмите для разбивки</div>
                  <div v-if="prod.note" class="th-note" :title="prod.note">{{ prod.note }}</div>
                  <div v-if="prod.price != null && parseFloat(prod.price) > 0" class="th-price">{{ formatMoney(prod.price) }} Br/{{ unitLabel(prod.unit) }}</div>
                </th>
                <th v-if="pricedStats.priced > 0" class="col-rest-sum" :rowspan="anyExpanded ? 2 : 1">Сумма, Br</th>
                <th v-if="activeCollection.status === 'active'" class="col-del" :rowspan="anyExpanded ? 2 : 1"></th>
              </tr>
              <tr v-if="anyExpanded">
                <template v-for="prod in collectionData.products" :key="'sub_' + prod.id">
                  <template v-if="isExpanded(prod)">
                    <th
                      v-for="date in expiryDatesFor(prod.id)"
                      :key="prod.id + '_' + date"
                      class="col-prod-date"
                    >
                      <div class="prod-date-label">до {{ formatBatchDate(date) }}</div>
                    </th>
                    <th class="col-prod-total">Итого</th>
                  </template>
                </template>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in filteredRows" :key="row.restaurant">
                <td class="col-num fw">Ресторан {{ row.restaurant }}</td>
                <td class="col-city muted">{{ row.city }}</td>
                <td class="col-addr muted">{{ row.address }}</td>
                <td class="col-time muted">{{ fmtShort(row.submittedAt) }}</td>
                <template v-for="prod in collectionData.products" :key="prod.id">
                  <template v-if="isExpanded(prod)">
                    <td
                      v-for="date in expiryDatesFor(prod.id)"
                      :key="prod.id + '_' + date"
                      class="col-prod col-prod-date-cell"
                      :class="{ editable: activeCollection.status === 'active' }"
                      @dblclick="activeCollection.status === 'active' && openCellEditor(row, prod.id)"
                    >
                      <div class="sc-cell-date-val">
                        {{ getCellByDate(row, prod.id, date) || '' }}
                      </div>
                    </td>
                    <td
                      class="col-prod col-prod-total-cell"
                      :class="{ editable: activeCollection.status === 'active' }"
                      @dblclick="activeCollection.status === 'active' && openCellEditor(row, prod.id)"
                    >
                      <div class="sc-cell-total">
                        {{ getCellBatches(row, prod.id).length ? getCellTotal(row, prod.id) : '—' }}
                      </div>
                    </td>
                  </template>
                  <td v-else class="col-prod">
                    <div
                      class="sc-cell"
                      :class="{ editable: activeCollection.status === 'active' }"
                      @dblclick="activeCollection.status === 'active' && openCellEditor(row, prod.id)"
                    >
                      <div class="sc-cell-total">
                        {{ getCellBatches(row, prod.id).length ? getCellTotal(row, prod.id) : '—' }}
                      </div>
                      <div v-if="prod.need_expiry && getCellBatches(row, prod.id).length" class="sc-cell-batches">
                        <div v-for="(b, idx) in getCellBatches(row, prod.id).slice(0, 2)" :key="idx" class="sc-cell-batch">
                          <span class="sc-cell-batch-date">{{ formatBatchDate(b.expiry_date) }}</span>
                          <span class="sc-cell-batch-stock">{{ formatBatchStock(b.stock) }}</span>
                        </div>
                        <div v-if="getCellBatches(row, prod.id).length > 2" class="sc-cell-more">
                          +{{ getCellBatches(row, prod.id).length - 2 }} партии
                        </div>
                      </div>
                    </div>
                  </td>
                </template>
                <td v-if="pricedStats.priced > 0" class="col-rest-sum fw">{{ pricedStats.restCost.get(row.restaurant) ? formatMoney(pricedStats.restCost.get(row.restaurant)) : '—' }}</td>
                <td v-if="activeCollection.status === 'active'" class="col-del">
                  <button class="sc-row-del" @click="deleteRestaurantRow(row)" title="Удалить ресторан">✕</button>
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="4" class="foot-label">Итого</td>
                <template v-for="prod in collectionData.products" :key="prod.id">
                  <template v-if="isExpanded(prod)">
                    <td
                      v-for="date in expiryDatesFor(prod.id)"
                      :key="prod.id + '_' + date"
                      class="col-prod foot-val"
                    >
                      {{ getProductDateTotal(prod.id, date) || '' }}
                    </td>
                    <td class="col-prod foot-val foot-val-strong">{{ getProductTotal(prod.id) }}</td>
                  </template>
                  <td v-else class="col-prod foot-val">
                    {{ getProductTotal(prod.id) }}
                  </td>
                </template>
                <td v-if="pricedStats.priced > 0" class="col-rest-sum foot-val foot-val-strong">{{ formatMoney(pricedStats.totalCost) }}</td>
                <td v-if="activeCollection.status === 'active'"></td>
              </tr>
              <tr v-if="pricedStats.priced > 0" class="sc-foot-cost">
                <td colspan="4" class="foot-label">Стоимость, Br</td>
                <template v-for="prod in collectionData.products" :key="'cost_' + prod.id">
                  <template v-if="isExpanded(prod)">
                    <td v-for="date in expiryDatesFor(prod.id)" :key="prod.id + '_cost_' + date" class="col-prod foot-val foot-val-cost"></td>
                    <td class="col-prod foot-val foot-val-cost">{{ pricedStats.productCost.get(prod.id) ? formatMoney(pricedStats.productCost.get(prod.id)) : '—' }}</td>
                  </template>
                  <td v-else class="col-prod foot-val foot-val-cost">
                    {{ pricedStats.productCost.get(prod.id) ? formatMoney(pricedStats.productCost.get(prod.id)) : '—' }}
                  </td>
                </template>
                <td class="col-rest-sum foot-val foot-val-strong">{{ formatMoney(pricedStats.totalCost) }}</td>
                <td v-if="activeCollection.status === 'active'"></td>
              </tr>
            </tfoot>
          </table>
        </div>
        <div v-else class="sc-empty">Нет данных</div>

        <!-- Total cost summary -->
        <div v-if="pricedStats.priced > 0" class="sc-cost-summary">
          <div class="sc-cost-summary-main">
            <div class="sc-cost-summary-label">Итого стоимость остатков</div>
            <div class="sc-cost-summary-value">{{ formatMoney(pricedStats.totalCost) }} Br</div>
          </div>
          <div class="sc-cost-summary-meta">
            <span>С ценой: <b>{{ pricedStats.priced }}</b> {{ pluralizeProducts(pricedStats.priced) }}</span>
            <span v-if="pricedStats.unpriced > 0" class="muted">·  Без цены: <b>{{ pricedStats.unpriced }}</b> {{ pluralizeProducts(pricedStats.unpriced) }} (в итог не идут)</span>
          </div>
        </div>

        <!-- Missing restaurants (bottom) -->
        <div v-if="missingRestaurants.length" class="sc-missing">
          <div class="sc-missing-head" @click="showMissing = !showMissing">
            <span class="sc-missing-icon">{{ showMissing ? '▾' : '▸' }}</span>
            <span>Не заполнили: <b>{{ missingRestaurants.length }}</b> из {{ restaurantStore.restaurants.length }}</span>
          </div>
          <div v-if="showMissing" class="sc-missing-list">
            <span v-for="r in missingRestaurants" :key="r.number" class="sc-missing-tag">
              {{ formatRestaurantNumber(r.number, r.legal_entity_group) }}<template v-if="r.city"> · {{ r.city }}</template>
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- Modals -->
    <Teleport to="body">
      <!-- Confirm modal -->
      <div v-if="confirmModal.show" class="modal modal-confirm">
        <div class="modal-box" style="max-width: 420px;">
          <div class="sc-modal-head">
            <h3>{{ confirmModal.title }}</h3>
            <button class="sc-x" @click="confirmModal.show = false">✕</button>
          </div>
          <p class="sc-confirm-text">{{ confirmModal.text }}</p>
          <div class="sc-modal-foot">
            <button class="sc-btn outline" @click="confirmModal.show = false">Отмена</button>
            <button class="sc-btn fill" :class="{ 'btn-danger': confirmModal.danger }" @click="confirmModal.action(); confirmModal.show = false;">
              {{ confirmModal.btnText }}
            </button>
          </div>
        </div>
      </div>

      <!-- Cell editor -->
      <div v-if="cellEditor.show" class="modal">
        <div class="modal-box" style="max-width: 640px;">
          <div class="sc-modal-head">
            <h3>{{ cellEditor.needExpiry ? 'Партии товара' : 'Количество товара' }}</h3>
            <button class="sc-x" @click="cellEditor.show = false">✕</button>
          </div>
          <div class="sc-cell-editor-head">
            <div class="sc-cell-editor-title">{{ cellEditor.productName }}</div>
            <div class="sc-cell-editor-meta">
              Ресторан {{ formatRestaurantNumber(cellEditor.restaurantNumber) }}
            </div>
          </div>
          <div v-if="cellEditor.needExpiry" class="sc-cell-editor-batches">
            <div v-for="(batch, idx) in cellEditor.batches" :key="idx" class="sc-cell-editor-row">
              <input v-model="batch.expiry_date" type="date" class="sc-input sc-cell-editor-date" />
              <input v-model="batch.stock" type="number" inputmode="decimal" min="0" step="any" class="sc-input sc-cell-editor-stock" placeholder="0" />
              <button class="sc-x sm" @click="removeCellBatch(idx)">✕</button>
            </div>
          </div>
          <div v-else class="sc-cell-editor-single">
            <input v-model="cellEditor.stock" type="number" inputmode="decimal" min="0" step="any" class="sc-input sc-cell-editor-stock full" placeholder="0" />
          </div>
          <button v-if="cellEditor.needExpiry" class="sc-btn outline full" @click="addCellBatch" style="margin-top: 8px;">+ Добавить партию</button>
          <div class="sc-modal-foot">
            <button class="sc-btn outline" @click="cellEditor.show = false">Отмена</button>
            <button class="sc-btn fill" @click="saveCellEdit" :disabled="cellEditor.loading">
              {{ cellEditor.loading ? '...' : 'Сохранить' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Prices modal -->
      <div v-if="pricesEditor.show" class="modal">
        <div class="modal-box" style="max-width: 640px;">
          <div class="sc-modal-head">
            <h3>Цены товаров</h3>
            <button class="sc-x" @click="pricesEditor.show = false">✕</button>
          </div>
          <div class="sc-prices-hint">
            Введите цену за единицу. Пустое поле — цена не учитывается в итогах.
          </div>
          <div class="sc-prices-list">
            <div v-for="p in pricesEditor.items" :key="p.product_id" class="sc-prices-row">
              <div class="sc-prices-name">
                <div>{{ p.product_name }}</div>
                <div class="sc-prices-sub">
                  <span v-if="p.product_sku">{{ p.product_sku }} · </span>
                  <span>за {{ unitLabel(p.unit) }}</span>
                </div>
              </div>
              <div class="sc-prices-input-wrap">
                <input
                  v-model="p.price"
                  type="text"
                  inputmode="decimal"
                  class="sc-input sc-prices-input"
                  placeholder="0"
                />
                <span class="sc-prices-currency">Br</span>
              </div>
            </div>
            <div v-if="!pricesEditor.items.length" class="sc-empty">В сборе нет товаров.</div>
          </div>
          <div class="sc-modal-foot">
            <button class="sc-btn outline" @click="pricesEditor.show = false">Отмена</button>
            <button class="sc-btn fill" @click="savePrices" :disabled="pricesEditor.saving">
              {{ pricesEditor.saving ? '...' : 'Сохранить' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Rename modal -->
      <div v-if="showRename" class="modal">
        <div class="modal-box" style="max-width: 420px;">
          <div class="sc-modal-head">
            <h3>Переименовать сбор</h3>
            <button class="sc-x" @click="showRename = false">✕</button>
          </div>
          <div class="sc-field">
            <label>Название</label>
            <input v-model="renameName" type="text" class="sc-input full" @keydown.enter="saveRename"/>
          </div>
          <div class="sc-modal-foot">
            <button class="sc-btn outline" @click="showRename = false">Отмена</button>
            <button class="sc-btn fill" @click="saveRename" :disabled="!renameName.trim()">Сохранить</button>
          </div>
        </div>
      </div>

      <!-- Deadline modal -->
      <div v-if="showDeadline" class="modal">
        <div class="modal-box" style="max-width: 420px;">
          <div class="sc-modal-head">
            <h3>Срок сдачи остатков</h3>
            <button class="sc-x" @click="showDeadline = false">✕</button>
          </div>
          <div class="sc-field">
            <label>Заполнить до</label>
            <input v-model="deadlineValue" type="datetime-local" class="sc-input full" />
            <div class="sc-field-hint">
              Оставьте поле пустым, чтобы убрать срок. Напоминания уходят за сутки
              и за 2 часа до срока — только тем, кто ещё не сдал.
            </div>
          </div>
          <div class="sc-modal-foot">
            <button class="sc-btn outline" @click="showDeadline = false">Отмена</button>
            <button class="sc-btn fill" @click="saveDeadline" :disabled="savingDeadline">
              {{ savingDeadline ? '...' : 'Сохранить' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Create modal -->
      <div v-if="showCreate" class="modal" @click.self="tryCloseCreate">
        <div class="modal-box" style="max-width: 600px;">
          <div class="sc-modal-head">
            <h3>Новый сбор остатков</h3>
            <button class="sc-x" @click="tryCloseCreate">✕</button>
          </div>

          <div class="sc-field">
            <label>Название</label>
            <input v-model="newName" type="text" class="sc-input full" :placeholder="'Сбор ' + todayStr"/>
          </div>

          <div class="sc-field">
            <label>Заполнить до</label>
            <input v-model="newDeadline" type="datetime-local" class="sc-input full" />
            <div class="sc-field-hint">
              Срок попадёт в письмо и в кабинет ресторана. За сутки и за 2 часа до срока
              тем, кто не сдал, уйдёт напоминание. Можно очистить — тогда срока не будет.
            </div>
          </div>

          <div class="sc-field">
            <label>Товары</label>
            <div v-for="(p, i) in newProducts" :key="i" class="sc-product-card">
              <button v-if="newProducts.length > 1" class="sc-card-remove" @click="newProducts.splice(i, 1)">✕</button>

              <!-- Search / selected state -->
              <div v-if="p.fromDb" class="sc-product-selected">
                <div class="sc-product-selected-info">
                  <div class="sc-product-selected-name">{{ p.name }}</div>
                  <div class="sc-product-selected-meta">
                    <span v-if="p.sku">{{ p.sku }}</span>
                    <span v-if="p.supplier">{{ p.supplier }}</span>
                  </div>
                </div>
                <button class="sc-btn sm outline" @click="clearProductRow(i)">Изменить</button>
              </div>
              <div v-else class="sc-product-search">
                <input
                  v-model="p.searchQuery"
                  type="text"
                  placeholder="Найти товар в базе или ввести вручную..."
                  class="sc-input full"
                  @input="onProductInput(i)"
                  @focus="p.showDrop = true"
                  @keydown.escape="p.showDrop = false"
                />
                <div v-if="p.showDrop && p.results.length" class="sc-drop" @mousedown.prevent>
                  <div
                    v-for="r in p.results" :key="r.id"
                    class="sc-drop-item"
                    @click="pickProduct(i, r)"
                  >
                    <div class="sc-drop-name">{{ r.name }}</div>
                    <div class="sc-drop-meta">
                      {{ r.sku }}
                      <template v-if="r.supplier"> · {{ r.supplier }}</template>
                      <template v-if="r.qty_per_box"> · {{ r.qty_per_box }} шт/кор</template>
                    </div>
                  </div>
                  <div class="sc-drop-item sc-drop-manual" @click="setManual(i)">
                    <div class="sc-drop-name">Ввести вручную</div>
                    <div class="sc-drop-meta">Товара нет в базе — ввести название и артикул</div>
                  </div>
                </div>

                <!-- Manual input (when nothing selected from DB) -->
                <div v-if="p.searchQuery && !p.results.length && !p.searching" class="sc-manual-hint">
                  Не найдено в базе — можно
                  <button class="sc-link-btn" @click="setManual(i)">ввести вручную</button>
                </div>
                <div v-if="p.manual" class="sc-manual-fields">
                  <input v-model="p.name" type="text" placeholder="Название товара" class="sc-input full"/>
                  <input v-model="p.sku" type="text" placeholder="Артикул (SKU)" class="sc-input full" style="margin-top: 6px;"/>
                </div>
              </div>

              <div class="sc-product-flags">
                <!-- Unit selector -->
                <div class="sc-product-unit-row">
                  <span class="sc-product-unit-label">Единица сбора:</span>
                  <template v-if="p.unitLocked">
                    <span class="sc-unit-locked">{{ unitLabel(p.unit) }}</span>
                  </template>
                  <template v-else>
                    <div class="sc-switcher">
                      <button :class="{ on: p.unit === 'boxes' }" @click="p.unit = 'boxes'">Коробки</button>
                      <button :class="{ on: p.unit === 'pieces' }" @click="p.unit = 'pieces'">Штуки</button>
                      <button :class="{ on: p.unit === 'kg' }" @click="p.unit = 'kg'">Кг</button>
                      <button :class="{ on: p.unit === 'liters' }" @click="p.unit = 'liters'">Литры</button>
                    </div>
                  </template>
                </div>

                <div class="sc-need-expiry">
                  <span class="sc-product-unit-label">Нужен срок годности:</span>
                  <div class="sc-switcher">
                    <button :class="{ on: !!p.need_expiry }" @click="p.need_expiry = true">Да</button>
                    <button :class="{ on: !p.need_expiry }" @click="p.need_expiry = false">Нет</button>
                  </div>
                </div>
              </div>

              <!-- Note -->
              <input v-model="p.note" type="text" placeholder="Примечание (видно сборщикам)" class="sc-input full sc-note-input" />
            </div>
            <button class="sc-btn outline full" @click="addProductRow" style="margin-top: 8px;">+ Добавить товар</button>
          </div>

          <div class="sc-modal-foot">
            <button class="sc-btn outline" @click="tryCloseCreate">Отмена</button>
            <button class="sc-btn fill" @click="createCollection" :disabled="!canCreate || creating">
              {{ creating ? '...' : 'Создать' }}
            </button>
          </div>
        </div>
      </div>
      <!-- Edit products modal -->
      <div v-if="showEditProducts" class="modal">
        <div class="modal-box" style="max-width: 600px;">
          <div class="sc-modal-head">
            <h3>Редактирование товаров</h3>
            <button class="sc-x" @click="showEditProducts = false">✕</button>
          </div>

          <div class="sc-field">
            <label>Товары в сборе</label>
            <div v-for="(p, i) in editProducts" :key="p.id || ('new_' + i)" class="sc-product-card">
              <button v-if="editProducts.length > 1" class="sc-card-remove" @click="removeEditProduct(i)">✕</button>

              <!-- Existing product: editable fields -->
              <div v-if="p.id && !p._searchMode" class="sc-product-edit-fields">
                <div class="sc-product-edit-row">
                  <input v-model="p.product_name" type="text" placeholder="Название товара" class="sc-input full"/>
                </div>
                <div class="sc-product-edit-row" style="margin-top: 6px;">
                  <input v-model="p.product_sku" type="text" placeholder="Артикул (SKU)" class="sc-input" style="width: 140px;"/>
                </div>
              </div>

              <!-- New product: search or manual entry -->
              <div v-else class="sc-product-search">
                <div v-if="p._fromDb" class="sc-product-selected">
                  <div class="sc-product-selected-info">
                    <div class="sc-product-selected-name">{{ p.product_name }}</div>
                    <div class="sc-product-selected-meta">
                      <span v-if="p.product_sku">{{ p.product_sku }}</span>
                    </div>
                  </div>
                  <button class="sc-btn sm outline" @click="p._fromDb = false; p._searchQuery = ''; p.product_name = ''; p.product_sku = '';">Изменить</button>
                </div>
                <template v-else>
                  <input
                    v-model="p._searchQuery"
                    type="text"
                    placeholder="Найти товар в базе или ввести вручную..."
                    class="sc-input full"
                    @input="onEditProductInput(i)"
                    @focus="p._showDrop = true"
                    @keydown.escape="p._showDrop = false"
                  />
                  <div v-if="p._showDrop && p._results?.length" class="sc-drop" @mousedown.prevent>
                    <div
                      v-for="r in p._results" :key="r.id"
                      class="sc-drop-item"
                      @click="pickEditProduct(i, r)"
                    >
                      <div class="sc-drop-name">{{ r.name }}</div>
                      <div class="sc-drop-meta">
                        {{ r.sku }}
                        <template v-if="r.supplier"> · {{ r.supplier }}</template>
                      </div>
                    </div>
                    <div class="sc-drop-item sc-drop-manual" @click="setEditManual(i)">
                      <div class="sc-drop-name">Ввести вручную</div>
                      <div class="sc-drop-meta">Товара нет в базе — ввести название и артикул</div>
                    </div>
                  </div>
                  <div v-if="p._searchQuery && !p._results?.length && !p._searching" class="sc-manual-hint">
                    Не найдено в базе — можно
                    <button class="sc-link-btn" @click="setEditManual(i)">ввести вручную</button>
                  </div>
                  <div v-if="p._manual" class="sc-manual-fields">
                    <input v-model="p.product_name" type="text" placeholder="Название товара" class="sc-input full"/>
                    <input v-model="p.product_sku" type="text" placeholder="Артикул (SKU)" class="sc-input full" style="margin-top: 6px;"/>
                  </div>
                </template>
              </div>

              <!-- Unit selector -->
              <div class="sc-product-unit-row">
                <span class="sc-product-unit-label">Единица сбора:</span>
                <div class="sc-switcher">
                  <button :class="{ on: p.unit === 'boxes' }" @click="p.unit = 'boxes'">Коробки</button>
                  <button :class="{ on: p.unit === 'pieces' }" @click="p.unit = 'pieces'">Штуки</button>
                  <button :class="{ on: p.unit === 'kg' }" @click="p.unit = 'kg'">Кг</button>
                  <button :class="{ on: p.unit === 'liters' }" @click="p.unit = 'liters'">Литры</button>
                </div>
              </div>

              <div class="sc-need-expiry">
                <span class="sc-product-unit-label">Нужен срок годности:</span>
                <div class="sc-switcher">
                  <button :class="{ on: !!p.need_expiry }" @click="p.need_expiry = true">Да</button>
                  <button :class="{ on: !p.need_expiry }" @click="p.need_expiry = false">Нет</button>
                </div>
              </div>

              <!-- Note -->
              <input v-model="p.note" type="text" placeholder="Примечание (видно сборщикам)" class="sc-input full sc-note-input" />

              <!-- Warning if product has data and being deleted -->
              <div v-if="p._markedForDelete" class="sc-product-delete-warn">
                Будет удалён при сохранении (вместе с собранными остатками)
                <button class="sc-link-btn" @click="p._markedForDelete = false; editProducts.splice(i, 0, editProducts.splice(i, 1)[0]);">Отменить</button>
              </div>
            </div>
            <button class="sc-btn outline full" @click="addEditProductRow" style="margin-top: 8px;">+ Добавить товар</button>
          </div>

          <div class="sc-modal-foot">
            <button class="sc-btn outline" @click="showEditProducts = false">Отмена</button>
            <button class="sc-btn fill" @click="saveEditProducts" :disabled="savingProducts || !canSaveProducts">
              {{ savingProducts ? '...' : 'Сохранить' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { db } from '@/lib/apiClient.js';
import { formatRestaurantNumber, getEntityGroupCode } from '@/lib/legalEntities.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { useRestaurantStore } from '@/stores/restaurantStore.js';

const orderStore = useOrderStore();
const userStore = useUserStore();
const toastStore = useToastStore();
const restaurantStore = useRestaurantStore();

const loading = ref(true);
const loadError = ref(false);
const collections = ref([]);
const activeCollection = ref(null);
const collectionData = ref(null);

// Create modal
const showCreate = ref(false);
const creating = ref(false);
const newName = ref('');
const newDeadline = ref('');
const newProducts = ref([makeProductRow()]);
const canCreate = computed(() => newName.value.trim() && newProducts.value.some(p => p.name.trim() || p.fromDb));

const notifying = ref(false);

// Confirm modal
const confirmModal = ref({ show: false, title: '', text: '', btnText: '', danger: false, action: () => {} });

// Rename
const showRename = ref(false);
const showDeadline = ref(false);
const deadlineValue = ref('');
const savingDeadline = ref(false);
const renameName = ref('');

// Filter & Sort
const responseFilter = ref('');
const responseStatus = ref('all'); // 'all' | 'filled' | 'missing'
const pricesEditor = ref({ show: false, saving: false, items: [] });
const sortKey = ref('restaurant');
const sortDir = ref('asc');

// Cell edit
const cellEditor = ref({ show: false, loading: false, collectionId: null, restaurantNumber: '', productId: null, productName: '', unit: 'pieces', needExpiry: false, stock: '', batches: [] });

// Missing restaurants
const showMissing = ref(true);

// Expanded product columns (раскрытые группы по датам)
const expandedProducts = ref(new Set());
function toggleExpand(prod) {
  if (!prod?.need_expiry) return;
  const s = new Set(expandedProducts.value);
  if (s.has(prod.id)) s.delete(prod.id);
  else s.add(prod.id);
  expandedProducts.value = s;
}
function isExpanded(prod) {
  return prod?.need_expiry && expandedProducts.value.has(prod.id);
}
const anyExpanded = computed(() => {
  for (const p of (collectionData.value?.products || [])) {
    if (isExpanded(p)) return true;
  }
  return false;
});

// Уникальные даты партий по товару — отсортированы по возрастанию
// Возвращает массив строк YYYY-MM-DD (без пустых/null)
const productExpiryDates = computed(() => {
  const map = new Map();
  for (const p of (collectionData.value?.products || [])) {
    if (!p.need_expiry) continue;
    const dates = new Set();
    for (const d of (collectionData.value?.data || [])) {
      if (d.product_id !== p.id) continue;
      const ed = d.expiry_date ? String(d.expiry_date).slice(0, 10) : '';
      if (ed) dates.add(ed);
    }
    map.set(p.id, [...dates].sort());
  }
  return map;
});
function expiryDatesFor(productId) {
  return productExpiryDates.value.get(productId) || [];
}
function getCellByDate(row, productId, date) {
  const cell = row.cells[productId];
  if (!cell) return 0;
  let s = 0;
  for (const b of cell.batches) {
    const ed = b.expiry_date ? String(b.expiry_date).slice(0, 10) : '';
    if (ed === date) s += parseFloat(b.stock) || 0;
  }
  return parseFloat(s.toFixed(2));
}
function getProductDateTotal(productId, date) {
  let s = 0;
  for (const d of (collectionData.value?.data || [])) {
    if (d.product_id !== productId) continue;
    const ed = d.expiry_date ? String(d.expiry_date).slice(0, 10) : '';
    if (ed === date) s += parseFloat(d.stock) || 0;
  }
  return parseFloat(s.toFixed(2));
}

const todayStr = new Date().toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });

// Close dropdowns on outside click
function handleDocClick(e) {
  if (!e.target.closest('.sc-product-search')) {
    for (const p of newProducts.value) p.showDrop = false;
  }
}
onMounted(() => { loadCollections(); restaurantStore.load(orderStore.settings.legalEntity); document.addEventListener('click', handleDocClick); });
onUnmounted(() => {
  document.removeEventListener('click', handleDocClick);
  Object.values(searchTimers).forEach(t => clearTimeout(t));
  searchTimers = {};
});
watch(() => orderStore.settings.legalEntity, () => { collectionData.value = null; loadCollections(); restaurantStore.invalidate(); restaurantStore.load(orderStore.settings.legalEntity); });

const uniqueRestaurants = computed(() => {
  if (!collectionData.value?.data) return 0;
  return new Set(collectionData.value.data.map(d => d.restaurant_number)).size;
});

const answeredRestaurants = computed(() => {
  if (!collectionData.value?.data) return new Set();
  return new Set(collectionData.value.data.map(d => String(d.restaurant_number)));
});

const missingRestaurants = computed(() => {
  if (!collectionData.value?.data || !restaurantStore.restaurants.length) return [];
  const answered = answeredRestaurants.value;
  return restaurantStore.restaurants
    .filter(r => !answered.has(String(r.number)))
    .sort((a, b) => String(a.number).localeCompare(String(b.number), undefined, { numeric: true }));
});

function makeProductRow() {
  return { name: '', sku: '', unit: 'pieces', unitLocked: false, fromDb: false, results: [], showDrop: false, searchQuery: '', manual: false, supplier: '', searching: false, note: '', need_expiry: false };
}
function addProductRow() {
  newProducts.value.push(makeProductRow());
}
function clearProductRow(i) {
  const p = newProducts.value[i];
  Object.assign(p, { name: '', sku: '', unit: 'pieces', unitLocked: false, fromDb: false, results: [], searchQuery: '', manual: false, supplier: '', searching: false, need_expiry: false });
}
function setManual(i) {
  const p = newProducts.value[i];
  p.manual = true;
  p.name = p.searchQuery;
  p.showDrop = false;
}

// Product search
let searchTimers = {};
function onProductInput(i) {
  const p = newProducts.value[i];
  p.fromDb = false;
  p.manual = false;
  p.showDrop = true;
  clearTimeout(searchTimers[i]);
  if (p.searchQuery.length < 2) { p.results = []; p.searching = false; return; }
  p.searching = true;
  searchTimers[i] = setTimeout(() => searchProduct(i), 250);
}
async function searchProduct(i) {
  const p = newProducts.value[i];
  try {
    const le = orderStore.settings.legalEntity;
    const params = new URLSearchParams({ q: p.searchQuery, legal_entity: le, limit: '10' });
    const r = await fetch(`/api/search_products?${params}`, {
      headers: { 'X-Session-Token': localStorage.getItem('bk_session_token') || '' },
    });
    if (r.ok) p.results = await r.json();
  } catch { p.results = []; } finally { p.searching = false; }
}
function pickProduct(i, product) {
  const p = newProducts.value[i];
  p.name = product.name;
  p.sku = product.sku || '';
  p.supplier = product.supplier || '';
  p.fromDb = true;
  p.manual = false;
  p.showDrop = false;
  p.results = [];
  p.searchQuery = '';
  // Единица измерения из карточки товара — блокируем выбор
  const uom = product.unit_of_measure;
  if (uom === 'кг') { p.unit = 'kg'; p.unitLocked = true; }
  else if (uom === 'л') { p.unit = 'liters'; p.unitLocked = true; }
  else { p.unit = 'pieces'; p.unitLocked = true; }
}

// Collections CRUD
async function loadCollections() {
  loading.value = true;
  loadError.value = false;
  try {
    const { data } = await db.from('stock_collections')
      .select('*')
      .eq('legal_entity_group', getEntityGroupCode(orderStore.settings.legalEntity))
      .order('created_at', { ascending: false })
      .limit(50);
    collections.value = data || [];
  } catch {
    loadError.value = true;
    toastStore.error('Ошибка', 'Не удалось загрузить сессии сбора');
  } finally { loading.value = false; }
}

// Срок по умолчанию — завтра 10:00. Формат как у input[type=datetime-local].
function defaultDeadlineValue() {
  const d = new Date();
  d.setDate(d.getDate() + 1);
  d.setHours(10, 0, 0, 0);
  const p = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T10:00`;
}

// '2026-07-21 10:00:00' (из БД) → '21.07.2026 в 10:00'
function fmtDeadline(raw) {
  if (!raw) return '';
  const m = String(raw).match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
  if (!m) return '';
  return `${m[3]}.${m[2]}.${m[1]} в ${m[4]}:${m[5]}`;
}

// Строка из БД → значение для input[type=datetime-local]
function deadlineToInput(raw) {
  if (!raw) return '';
  const m = String(raw).match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
  return m ? `${m[1]}-${m[2]}-${m[3]}T${m[4]}:${m[5]}` : '';
}

function deadlinePassed(raw) {
  const v = deadlineToInput(raw);
  return !!v && new Date(v.replace('T', ' ').replace(/-/g, '/')) < new Date();
}

function openCreateModal() {
  newName.value = '';
  newDeadline.value = defaultDeadlineValue();
  newProducts.value = [makeProductRow()];
  showCreate.value = true;
}

function duplicateCollection() {
  const products = collectionData.value?.products || [];
  if (!products.length) { toastStore.error('Нет товаров для копирования'); return; }
  newName.value = (activeCollection.value?.name || 'Сбор') + ' (копия)';
  newDeadline.value = defaultDeadlineValue();
  newProducts.value = products.map(p => ({
    ...makeProductRow(),
    name: p.product_name || '',
    sku: p.product_sku || '',
    unit: p.unit || 'pieces',
    note: p.note || '',
    need_expiry: !!p.need_expiry,
    fromDb: true,
  }));
  activeCollection.value = null;
  showCreate.value = true;
}

function isCreateDirty() {
  if (newName.value.trim()) return true;
  return newProducts.value.some(p => p.name.trim() || p.fromDb || p.searchQuery.trim());
}

function tryCloseCreate() {
  if (!isCreateDirty()) { showCreate.value = false; return; }
  confirmModal.value = {
    show: true, title: 'Закрыть форму?', danger: false,
    text: 'Вы уже начали заполнять сбор. Все введённые данные будут потеряны.',
    btnText: 'Закрыть',
    action: () => { showCreate.value = false; },
  };
}

async function createCollection() {
  creating.value = true;
  try {
    const products = newProducts.value.filter(p => p.name.trim()).map(p => ({
      name: p.name.trim(),
      sku: p.sku.trim() || null,
      unit: p.unit,
      need_expiry: !!p.need_expiry,
      note: p.note.trim() || null,
    }));
    const { data } = await db.rpc('sc_create_collection', {
      legal_entity: orderStore.settings.legalEntity,
      name: newName.value.trim() || `Сбор ${todayStr}`,
      deadline_at: newDeadline.value || null,
      products,
      user_name: userStore.currentUser?.name || '',
    });
    if (data?.id) {
      const mails = data.emails_planned || 0;
      toastStore.success('Создано', mails
        ? `Сессия сбора создана. Письма уходят ${mails} ${mails === 1 ? 'ресторану' : 'ресторанам'}`
        : 'Сессия сбора создана');
      showCreate.value = false;
      await loadCollections();
      const coll = collections.value.find(c => c.id === data.id);
      if (coll) await openCollection(coll);
    }
  } catch { toastStore.error('Ошибка', 'Не удалось создать'); } finally { creating.value = false; }
}

async function openCollection(c) {
  activeCollection.value = c;
  await refreshData();
}

async function refreshData() {
  if (!activeCollection.value) return;
  try {
    const { data } = await db.rpc('sc_get_collection_data', { collection_id: activeCollection.value.id });
    if (data?.products) {
      data.products = data.products.map(p => ({
        ...p,
        need_expiry: Number(p.need_expiry) === 1,
      }));
    }
    collectionData.value = data;
  } catch { toastStore.error('Ошибка', 'Не удалось загрузить данные'); }
}

function openPricesEditor() {
  if (!collectionData.value?.products) return;
  pricesEditor.value = {
    show: true,
    saving: false,
    items: collectionData.value.products.map(p => ({
      product_id: p.id,
      product_name: p.product_name,
      product_sku: p.product_sku || '',
      unit: p.unit,
      price: p.price !== null && p.price !== undefined ? String(p.price).replace(/\.?0+$/, '').replace('.', ',') : '',
    })),
  };
}

async function savePrices() {
  if (!activeCollection.value) return;
  pricesEditor.value.saving = true;
  try {
    const prices = pricesEditor.value.items.map(p => ({
      product_id: p.product_id,
      price: String(p.price ?? '').trim(),
    }));
    const { data, error } = await db.rpc('sc_save_prices', {
      collection_id: activeCollection.value.id,
      prices,
    });
    if (error) throw new Error(error);
    toastStore.show(`Цены сохранены (${data?.updated ?? prices.length})`);
    pricesEditor.value.show = false;
    // Перечитаем данные, чтобы итоги пересчитались.
    await refreshData();
  } catch (e) {
    toastStore.error('Ошибка', e.message || e);
  } finally {
    pricesEditor.value.saving = false;
  }
}

async function notifyRestaurants() {
  if (!activeCollection.value) return
  notifying.value = true
  try {
    const { data, error } = await db.rpc('sc_notify_restaurants', { collection_id: activeCollection.value.id })
    if (error) throw new Error(error)
    const tg = data?.sent || 0
    const mails = data?.emails_planned || 0
    if (!tg && !mails) {
      toastStore.show('Напоминать некому — все уже заполнили')
    } else {
      const parts = []
      if (tg) parts.push(`Telegram: ${tg}`)
      if (mails) parts.push(`писем: ${mails}`)
      toastStore.show('Напоминание отправлено — ' + parts.join(', '))
    }
  } catch (e) { toastStore.error('Ошибка', e.message || e) }
  finally { notifying.value = false }
}

// Close collection
function askCloseCollection() {
  confirmModal.value = {
    show: true, title: 'Закрыть сбор', danger: true,
    text: 'Рестораны больше не смогут отправить остатки. Данные сохранятся.',
    btnText: 'Закрыть сбор',
    action: doCloseCollection,
  };
}
async function doCloseCollection() {
  try {
    await db.rpc('sc_close_collection', { collection_id: activeCollection.value.id });
    activeCollection.value.status = 'closed';
    toastStore.success('Закрыт', 'Сбор закрыт');
  } catch { toastStore.error('Ошибка', 'Не удалось закрыть'); }
}

function askReopenCollection() {
  confirmModal.value = {
    show: true, title: 'Переоткрыть сбор', danger: false,
    text: 'Рестораны снова смогут открыть сбор и отправить остатки.',
    btnText: 'Переоткрыть',
    action: doReopenCollection,
  };
}

async function doReopenCollection() {
  try {
    const { error } = await db.rpc('sc_reopen_collection', { collection_id: activeCollection.value.id });
    if (error) throw new Error(error);
    activeCollection.value.status = 'active';
    toastStore.success('Открыт', 'Сбор снова доступен ресторанам');
  } catch (e) {
    toastStore.error('Ошибка', e.message || 'Не удалось переоткрыть');
  }
}

// Delete collection
function askDeleteCollection() {
  confirmModal.value = {
    show: true, title: 'Удалить сбор', danger: true,
    text: `Сбор «${activeCollection.value.name}» и все собранные данные будут удалены. Это нельзя отменить.`,
    btnText: 'Удалить',
    action: doDeleteCollection,
  };
}
async function doDeleteCollection() {
  try {
    const { data, error } = await db.rpc('sc_delete_collection', { collection_id: activeCollection.value.id });
    if (error) { toastStore.error('Ошибка', 'Не удалось удалить сбор'); return; }
    activeCollection.value = null;
    collectionData.value = null;
    toastStore.success('Удалено', 'Сбор удалён');
    await loadCollections();
  } catch { toastStore.error('Ошибка', 'Не удалось удалить'); }
}

// Rename
function openDeadline() {
  deadlineValue.value = deadlineToInput(activeCollection.value?.deadline_at);
  showDeadline.value = true;
}
async function saveDeadline() {
  savingDeadline.value = true;
  try {
    const { data, error } = await db.rpc('sc_set_deadline', {
      collection_id: activeCollection.value.id,
      deadline_at: deadlineValue.value || null,
    });
    if (error) throw new Error(error);
    activeCollection.value.deadline_at = data?.deadline_at || null;
    const c = collections.value.find(x => x.id === activeCollection.value.id);
    if (c) c.deadline_at = activeCollection.value.deadline_at;
    showDeadline.value = false;
    toastStore.success('Сохранено', activeCollection.value.deadline_at
      ? `Срок: ${fmtDeadline(activeCollection.value.deadline_at)}`
      : 'Срок убран');
  } catch (e) {
    toastStore.error('Ошибка', e.message || 'Не удалось сохранить срок');
  } finally { savingDeadline.value = false; }
}

function openRename() {
  renameName.value = activeCollection.value.name;
  showRename.value = true;
}
async function saveRename() {
  if (!renameName.value.trim()) return;
  try {
    const { error } = await db.from('stock_collections').update({ name: renameName.value.trim() }).eq('id', activeCollection.value.id).eq('legal_entity_group', getEntityGroupCode(orderStore.settings.legalEntity));
    if (error) { toastStore.error('Ошибка', 'Не удалось переименовать'); return; }
    activeCollection.value.name = renameName.value.trim();
    showRename.value = false;
    toastStore.success('Сохранено', '');
    const c = collections.value.find(x => x.id === activeCollection.value.id);
    if (c) c.name = renameName.value.trim();
  } catch { toastStore.error('Ошибка', 'Не удалось переименовать'); }
}

function getProductData(productId) {
  if (!collectionData.value?.data) return [];
  return collectionData.value.data.filter(d => d.product_id === productId);
}

function getProductTotal(productId) {
  const total = getProductData(productId).reduce((s, d) => s + (parseFloat(d.stock) || 0), 0);
  return parseFloat(total.toFixed(2));
}

function formatBatchDate(dateStr) {
  if (!dateStr) return 'без срока';
  const d = new Date(String(dateStr).length === 10 ? `${dateStr}T00:00:00` : dateStr);
  if (Number.isNaN(d.getTime())) return dateStr;
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function formatBatchStock(stock) {
  const n = parseFloat(stock) || 0;
  return parseFloat(n.toFixed(2)).toString();
}

function getCellBatches(row, productId) {
  return row.cells[productId]?.batches || [];
}

function getCellTotal(row, productId) {
  return row.cells[productId]?.total || 0;
}

// Merged table: one row per restaurant, columns = products
function getRestaurantInfo(num) {
  const r = restaurantStore.restaurants.find(r => String(r.number) === String(num));
  return r ? { city: r.city || '', address: r.address || '' } : { city: '', address: '' };
}

const mergedRows = computed(() => {
  if (!collectionData.value) return [];
  const allData = collectionData.value.data || [];
  const restMap = new Map();
  for (const r of restaurantStore.restaurants) {
    const key = String(r.number);
    restMap.set(key, {
      restaurant: key,
      city: r.city || '',
      address: r.address || '',
      cells: {},
      submittedAt: null,
      hasData: answeredRestaurants.value.has(key),
    });
  }
  for (const d of allData) {
    const key = String(d.restaurant_number);
    if (!restMap.has(key)) {
      const info = getRestaurantInfo(key);
      restMap.set(key, { restaurant: key, city: info.city, address: info.address, cells: {}, submittedAt: null, hasData: true });
    }
    const row = restMap.get(key);
    row.hasData = true;
    if (!row.cells[d.product_id]) {
      row.cells[d.product_id] = { product_id: d.product_id, restaurant_number: d.restaurant_number, batches: [], total: 0, submittedAt: null };
    }
    const cell = row.cells[d.product_id];
    cell.batches.push(d);
    cell.total += parseFloat(d.stock) || 0;
    if (d.submitted_at && (!row.submittedAt || d.submitted_at > row.submittedAt)) {
      row.submittedAt = d.submitted_at;
    }
    if (d.submitted_at && (!cell.submittedAt || d.submitted_at > cell.submittedAt)) {
      cell.submittedAt = d.submitted_at;
    }
  }
  return [...restMap.values()].sort((a, b) =>
    a.restaurant.localeCompare(b.restaurant, undefined, { numeric: true })
  );
});

function toggleSort(key) {
  if (sortKey.value === key) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
  } else {
    sortKey.value = key;
    sortDir.value = 'asc';
  }
}

const filledCount = computed(() => mergedRows.value.filter(r => r.hasData).length);

// Итоги стоимости остатков. Считаем стоимость = сумма(остатков) × цена для каждой
// пары (ресторан, товар) — берём только товары, у которых задана цена.
const pricedStats = computed(() => {
  const products = collectionData.value?.products || [];
  const priceById = new Map();
  let priced = 0;
  let unpriced = 0;
  for (const p of products) {
    const price = p.price != null ? parseFloat(p.price) : null;
    if (price !== null && !Number.isNaN(price) && price > 0) {
      priceById.set(p.id, price);
      priced++;
    } else {
      unpriced++;
    }
  }
  const productCost = new Map();   // productId → суммарная стоимость по всем ресторанам
  const restCost = new Map();      // restaurant → суммарная стоимость по всем товарам
  let totalCost = 0;
  for (const row of mergedRows.value) {
    let rTotal = 0;
    for (const [prodId, cell] of Object.entries(row.cells)) {
      const price = priceById.get(Number(prodId));
      if (!price) continue;
      const cost = (parseFloat(cell.total) || 0) * price;
      rTotal += cost;
      productCost.set(Number(prodId), (productCost.get(Number(prodId)) || 0) + cost);
    }
    if (rTotal > 0) restCost.set(row.restaurant, rTotal);
    totalCost += rTotal;
  }
  return { priceById, priced, unpriced, productCost, restCost, totalCost };
});

function formatMoney(n) {
  if (n == null || Number.isNaN(Number(n))) return '—';
  const v = Number(n);
  if (Math.abs(v) < 0.005) return '0,00';
  return v.toLocaleString('ru-BY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function pluralizeProducts(n) {
  const abs = Math.abs(Number(n) || 0);
  const m10 = abs % 10;
  const m100 = abs % 100;
  if (m10 === 1 && m100 !== 11) return 'товар';
  if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return 'товара';
  return 'товаров';
}

const filteredRows = computed(() => {
  const q = responseFilter.value.trim().toLowerCase();
  let rows = mergedRows.value;
  if (responseStatus.value === 'filled') rows = rows.filter(r => r.hasData);
  else if (responseStatus.value === 'missing') rows = rows.filter(r => !r.hasData);
  if (q) {
    rows = rows.filter(row =>
      row.restaurant.toLowerCase().includes(q) ||
      row.city.toLowerCase().includes(q) ||
      row.address.toLowerCase().includes(q)
    );
  }
  const key = sortKey.value;
  const dir = sortDir.value === 'asc' ? 1 : -1;
  return [...rows].sort((a, b) => {
    if (key === 'restaurant') {
      return dir * a.restaurant.localeCompare(b.restaurant, undefined, { numeric: true });
    }
    if (key === 'city') return dir * a.city.localeCompare(b.city, 'ru');
    if (key === 'address') return dir * a.address.localeCompare(b.address, 'ru');
    if (key === 'time') {
      return dir * ((a.submittedAt || '') < (b.submittedAt || '') ? -1 : (a.submittedAt || '') > (b.submittedAt || '') ? 1 : 0);
    }
    // Sort by product column
    if (key.startsWith('prod_')) {
      const prodId = parseInt(key.slice(5), 10);
      const va = parseFloat(a.cells[prodId]?.total) || 0;
      const vb = parseFloat(b.cells[prodId]?.total) || 0;
      return dir * (va - vb);
    }
    return 0;
  });
});

function makeCellBatchRow(expiry_date = '', stock = '') {
  return { expiry_date, stock };
}

function openCellEditor(row, prodId) {
  const prod = collectionData.value?.products?.find(p => p.id === prodId);
  const cell = row.cells[prodId];
  cellEditor.value = {
    show: true,
    loading: false,
    collectionId: activeCollection.value.id,
    restaurantNumber: row.restaurant,
    productId: prodId,
    productName: prod ? `${prod.product_name}${prod.product_sku ? ` (${prod.product_sku})` : ''}` : `Товар ${prodId}`,
    unit: prod?.unit || 'pieces',
    needExpiry: !!prod?.need_expiry,
    stock: cell?.total != null ? String(cell.total) : '',
    batches: (cell?.batches?.length ? cell.batches : [makeCellBatchRow()]).map(b => ({
      expiry_date: b.expiry_date ? String(b.expiry_date).slice(0, 10) : '',
      stock: b.stock != null ? String(b.stock) : '',
    })),
  };
}

function addCellBatch() {
  cellEditor.value.batches.push(makeCellBatchRow());
}

function removeCellBatch(idx) {
  cellEditor.value.batches.splice(idx, 1);
  if (!cellEditor.value.batches.length) cellEditor.value.batches.push(makeCellBatchRow());
}

function normalizeCellEditorBatches() {
  return cellEditor.value.batches
    .map(batch => ({
      expiry_date: String(batch.expiry_date || '').trim(),
      stock: String(batch.stock || '').trim(),
    }))
    .filter(batch => batch.stock !== '' && !Number.isNaN(Number(batch.stock)));
}

async function saveCellEdit() {
  cellEditor.value.loading = true;
  try {
    const payload = {
      collection_id: cellEditor.value.collectionId,
      product_id: cellEditor.value.productId,
      restaurant_number: cellEditor.value.restaurantNumber,
    };
    if (cellEditor.value.needExpiry) {
      const batches = normalizeCellEditorBatches();
      // Срок нужен только если остаток > 0
      const missingExpiry = batches.find(b => parseFloat(b.stock) > 0 && !b.expiry_date);
      if (missingExpiry) {
        toastStore.error('Ошибка', 'Укажите срок годности (или поставьте остаток 0)');
        return;
      }
      // Если ничего не введено — считаем, что остатков нет (0 без срока)
      payload.batches = batches.length
        ? batches.map(batch => ({
            expiry_date: batch.expiry_date,
            stock: parseFloat(batch.stock),
          }))
        : [{ expiry_date: '', stock: 0 }];
    } else {
      const raw = String(cellEditor.value.stock ?? '').trim().replace(',', '.');
      if (raw !== '' && Number.isNaN(Number(raw))) {
        toastStore.error('Ошибка', 'Указано некорректное количество');
        return;
      }
      // Пустое поле = 0 (нет остатков)
      payload.stock = raw === '' ? 0 : parseFloat(raw);
    }
    const { error } = await db.rpc('sc_save_collection_cell', payload);
    if (error) { toastStore.error('Ошибка', error); return; }
    cellEditor.value.show = false;
    await refreshData();
    toastStore.success('Сохранено', '');
  } catch {
    toastStore.error('Ошибка', 'Не удалось сохранить');
  } finally {
    cellEditor.value.loading = false;
  }
}

function deleteRestaurantRow(row) {
  confirmModal.value = {
    show: true, title: 'Удалить ресторан', danger: true,
    text: `Удалить все остатки ресторана ${row.restaurant}?`,
    btnText: 'Удалить',
    action: async () => {
      try {
        const { error } = await db.from('stock_collection_data')
          .delete()
          .eq('collection_id', activeCollection.value.id)
          .eq('restaurant_number', row.restaurant);
        if (error) { toastStore.error('Ошибка', 'Не удалось удалить'); return; }
        await refreshData();
        toastStore.success('Удалено', '');
      } catch { toastStore.error('Ошибка', 'Не удалось удалить'); }
    },
  };
}

function deleteEntry(d) {
  confirmModal.value = {
    show: true, title: 'Удалить запись', danger: true,
    text: `Удалить партию ресторана ${d.restaurant_number}?`,
    btnText: 'Удалить',
    action: async () => {
      try {
        await db.from('stock_collection_data').delete().eq('id', d.id);
        await refreshData();
        toastStore.success('Удалено', '');
      } catch { toastStore.error('Ошибка', 'Не удалось удалить'); }
    },
  };
}

// ═══ Edit products ═══
const showEditProducts = ref(false);
const editProducts = ref([]);
const savingProducts = ref(false);
let editSearchTimers = {};

const canSaveProducts = computed(() => {
  const active = editProducts.value.filter(p => !p._markedForDelete);
  return active.length > 0 && active.every(p => (p.product_name || '').trim());
});

function openEditProducts() {
  if (!collectionData.value?.products) return;
  editProducts.value = collectionData.value.products.map(p => ({
    ...p,
    note: p.note || '',
    need_expiry: !!p.need_expiry,
    _original: { ...p },
    _markedForDelete: false,
    _searchMode: false,
  }));
  showEditProducts.value = true;
}

function addEditProductRow() {
  editProducts.value.push({
    id: null,
    product_name: '',
    product_sku: '',
    unit: 'pieces',
    need_expiry: false,
    note: '',
    sort_order: editProducts.value.length,
    _searchMode: true,
    _fromDb: false,
    _searchQuery: '',
    _results: [],
    _showDrop: false,
    _searching: false,
    _manual: false,
    _markedForDelete: false,
  });
}

function removeEditProduct(i) {
  const p = editProducts.value[i];
  if (p.id) {
    // Existing product — check if it has collected data
    const hasData = collectionData.value?.data?.some(d => d.product_id === p.id);
    if (hasData) {
      confirmModal.value = {
        show: true, title: 'Удалить товар', danger: true,
        text: `Товар «${p.product_name}» уже содержит собранные остатки. При удалении все эти данные будут потеряны. Продолжить?`,
        btnText: 'Удалить',
        action: () => { editProducts.value.splice(i, 1); },
      };
    } else {
      editProducts.value.splice(i, 1);
    }
  } else {
    editProducts.value.splice(i, 1);
  }
}

function onEditProductInput(i) {
  const p = editProducts.value[i];
  p._fromDb = false;
  p._manual = false;
  p._showDrop = true;
  clearTimeout(editSearchTimers[i]);
  if ((p._searchQuery || '').length < 2) { p._results = []; p._searching = false; return; }
  p._searching = true;
  editSearchTimers[i] = setTimeout(() => searchEditProduct(i), 250);
}

async function searchEditProduct(i) {
  const p = editProducts.value[i];
  try {
    const le = orderStore.settings.legalEntity;
    const params = new URLSearchParams({ q: p._searchQuery, legal_entity: le, limit: '10' });
    const r = await fetch(`/api/search_products?${params}`, {
      headers: { 'X-Session-Token': localStorage.getItem('bk_session_token') || '' },
    });
    if (r.ok) p._results = await r.json();
  } catch { p._results = []; } finally { p._searching = false; }
}

function pickEditProduct(i, product) {
  const p = editProducts.value[i];
  p.product_name = product.name;
  p.product_sku = product.sku || '';
  p._fromDb = true;
  p._manual = false;
  p._showDrop = false;
  p._results = [];
  p._searchQuery = '';
  const uom = product.unit_of_measure;
  if (uom === 'кг') p.unit = 'kg';
  else if (uom === 'л') p.unit = 'liters';
  else p.unit = 'pieces';
}

function setEditManual(i) {
  const p = editProducts.value[i];
  p._manual = true;
  p.product_name = p._searchQuery;
  p._showDrop = false;
}

async function saveEditProducts() {
  savingProducts.value = true;
  try {
    const collId = activeCollection.value.id;
    const active = editProducts.value.filter(p => !p._markedForDelete);

    // 1. Delete removed products (existing ones that are no longer in the list)
    const originalIds = (collectionData.value?.products || []).map(p => p.id);
    const keepIds = active.filter(p => p.id).map(p => p.id);
    const toDelete = originalIds.filter(id => !keepIds.includes(id));

    for (const id of toDelete) {
      // Delete associated data first
      await db.from('stock_collection_data').delete().eq('collection_id', collId).eq('product_id', id);
      await db.from('stock_collection_products').delete().eq('id', id);
    }

    // 2. Update existing products
    for (const p of active.filter(p => p.id)) {
      const orig = p._original;
      if (orig && (p.product_name !== orig.product_name || p.product_sku !== orig.product_sku || p.unit !== orig.unit || p.need_expiry !== !!orig.need_expiry || p.note !== orig.note)) {
        await db.from('stock_collection_products').update({
          product_name: p.product_name.trim(),
          product_sku: (p.product_sku || '').trim() || null,
          unit: p.unit,
          need_expiry: !!p.need_expiry,
          note: (p.note || '').trim() || null,
        }).eq('id', p.id);
      }
    }

    // 3. Insert new products
    const newOnes = active.filter(p => !p.id && (p.product_name || '').trim());
    for (let i = 0; i < newOnes.length; i++) {
      const p = newOnes[i];
      await db.from('stock_collection_products').insert({
        collection_id: collId,
        product_name: p.product_name.trim(),
        product_sku: (p.product_sku || '').trim() || null,
        unit: p.unit,
        need_expiry: !!p.need_expiry,
        sort_order: keepIds.length + i,
        note: (p.note || '').trim() || null,
      });
    }

    // 4. Update sort_order for all
    for (let i = 0; i < active.length; i++) {
      const p = active[i];
      if (p.id && p.sort_order !== i) {
        await db.from('stock_collection_products').update({ sort_order: i }).eq('id', p.id);
      }
    }

    showEditProducts.value = false;
    toastStore.success('Сохранено', 'Список товаров обновлён');
    await refreshData();
  } catch (e) {
    toastStore.error('Ошибка', 'Не удалось сохранить изменения');
  } finally { savingProducts.value = false; }
}

// Export
async function exportExcel() {
  if (!collectionData.value) return;
  const XLSX = await import('xlsx-js-style');
  const products = collectionData.value.products || [];
  const allData = collectionData.value.data || [];

  // Все уникальные рестораны
  const restSet = new Set(allData.map(d => d.restaurant_number));
  const restNums = [...restSet].sort((a, b) => String(a).localeCompare(String(b), undefined, { numeric: true }));

  // Уникальные даты по каждому товару (только need_expiry)
  const datesByProd = new Map();
  for (const p of products) {
    if (!p.need_expiry) continue;
    const set = new Set();
    for (const d of allData) {
      if (d.product_id !== p.id) continue;
      const ed = d.expiry_date ? String(d.expiry_date).slice(0, 10) : '';
      if (ed) set.add(ed);
    }
    datesByProd.set(p.id, [...set].sort());
  }

  const brown = '502314';
  const accent = '6B321F';
  const subBg = 'FFF3E0';
  const totalBg = 'F5EBE0';
  const bdr = { style: 'thin', color: { rgb: 'E0D6CC' } };
  const borders = { top: bdr, bottom: bdr, left: bdr, right: bdr };
  const sH = { font: { bold: true, sz: 11, color: { rgb: 'FFFFFF' }, name: 'Calibri' }, fill: { fgColor: { rgb: brown } }, alignment: { horizontal: 'center', vertical: 'center', wrapText: true }, border: borders };
  const sH2 = { font: { bold: true, sz: 10, color: { rgb: 'FFFFFF' }, name: 'Calibri' }, fill: { fgColor: { rgb: accent } }, alignment: { horizontal: 'center', vertical: 'center', wrapText: true }, border: borders };
  const sC = (stripe) => ({ font: { sz: 11, name: 'Calibri' }, fill: stripe ? { fgColor: { rgb: 'FFF8F0' } } : undefined, alignment: { vertical: 'center' }, border: borders });
  const sB = (stripe) => ({ font: { bold: true, sz: 11, name: 'Calibri' }, fill: stripe ? { fgColor: { rgb: 'FFF8F0' } } : undefined, alignment: { vertical: 'center' }, border: borders });
  const sCDate = (stripe) => ({ font: { sz: 11, name: 'Calibri', color: { rgb: '6B5344' } }, fill: { fgColor: { rgb: stripe ? 'FFF1E0' : subBg } }, alignment: { vertical: 'center', horizontal: 'center' }, border: borders });
  const sCTotal = (stripe) => ({ font: { sz: 11, bold: true, name: 'Calibri', color: { rgb: brown } }, fill: { fgColor: { rgb: stripe ? 'F0E2D0' : totalBg } }, alignment: { vertical: 'center', horizontal: 'center' }, border: borders });

  const ws = {};
  const merges = [];
  let r = 0;

  // Title
  ws[XLSX.utils.encode_cell({ r, c: 0 })] = { v: activeCollection.value.name, t: 's', s: { font: { bold: true, sz: 14, color: { rgb: brown }, name: 'Calibri' } } };
  r += 2;

  // Шапка занимает 2 строки. Сначала разметим столбцы и подсчитаем смещения по товарам
  // Базовые столбцы: Ресторан | Город | Адрес
  ws[XLSX.utils.encode_cell({ r, c: 0 })] = { v: 'Ресторан', t: 's', s: sH };
  ws[XLSX.utils.encode_cell({ r, c: 1 })] = { v: 'Город', t: 's', s: sH };
  ws[XLSX.utils.encode_cell({ r, c: 2 })] = { v: 'Адрес', t: 's', s: sH };
  // Базовые столбцы перекрывают обе строки шапки
  merges.push({ s: { r, c: 0 }, e: { r: r + 1, c: 0 } });
  merges.push({ s: { r, c: 1 }, e: { r: r + 1, c: 1 } });
  merges.push({ s: { r, c: 2 }, e: { r: r + 1, c: 2 } });

  const cols = [{ wch: 12 }, { wch: 14 }, { wch: 24 }];
  const prodOffset = 3;
  // prodCol[productId] = { start, end, dates: [...] }
  const prodCol = new Map();
  let curC = prodOffset;
  // Цена на единицу товара → используется для расчёта стоимости.
  const priceById = new Map();
  for (const p of products) {
    const pr = p.price != null ? parseFloat(p.price) : NaN;
    if (!Number.isNaN(pr) && pr > 0) priceById.set(p.id, pr);
  }
  const hasAnyPrice = priceById.size > 0;

  for (const p of products) {
    const ul = unitLabel(p.unit);
    // Артикул впереди — без скобок. Потом название, единица в скобках, и
    // цена в правом верхнем углу (если задана).
    const skuPrefix = p.product_sku ? `${p.product_sku} ` : '';
    const pricePart = priceById.has(p.id) ? `\n${priceById.get(p.id).toFixed(2)} Br/${ul}` : '';
    const headerText = `${skuPrefix}${p.product_name} (${ul})${pricePart}`;
    if (p.need_expiry) {
      const dates = datesByProd.get(p.id) || [];
      const span = dates.length + 1; // даты + Итого
      // Верхняя строка — название товара, объединить span колонок
      ws[XLSX.utils.encode_cell({ r, c: curC })] = { v: headerText, t: 's', s: sH };
      if (span > 1) {
        merges.push({ s: { r, c: curC }, e: { r, c: curC + span - 1 } });
      }
      // Нижняя строка — даты + «Итого»
      dates.forEach((dt, i) => {
        ws[XLSX.utils.encode_cell({ r: r + 1, c: curC + i })] = { v: 'до ' + formatBatchDate(dt), t: 's', s: sH2 };
        cols.push({ wch: 11 });
      });
      ws[XLSX.utils.encode_cell({ r: r + 1, c: curC + dates.length })] = { v: 'Итого', t: 's', s: sH2 };
      cols.push({ wch: Math.max(10, p.product_name.length + 4) });
      prodCol.set(p.id, { start: curC, end: curC + span - 1, dates, hasExpiry: true });
      curC += span;
    } else {
      // Одна колонка с rowspan=2
      ws[XLSX.utils.encode_cell({ r, c: curC })] = { v: headerText, t: 's', s: sH };
      merges.push({ s: { r, c: curC }, e: { r: r + 1, c: curC } });
      cols.push({ wch: Math.max(16, headerText.length + 4) });
      prodCol.set(p.id, { start: curC, end: curC, dates: [], hasExpiry: false });
      curC += 1;
    }
  }
  // Столбец «Сумма по ресторану, Br» — последним, только если есть хоть одна цена.
  const sumCol = hasAnyPrice ? curC : null;
  if (hasAnyPrice) {
    ws[XLSX.utils.encode_cell({ r, c: sumCol })] = { v: 'Сумма, Br', t: 's', s: sH };
    merges.push({ s: { r, c: sumCol }, e: { r: r + 1, c: sumCol } });
    cols.push({ wch: 14 });
    curC += 1;
  }
  const lastC = curC - 1;
  r += 2;

  // Карта значений: (rest, product, date) → сумма; (rest, product) → общий итог
  const cellByDate = new Map();
  const cellTotal = new Map();
  for (const d of allData) {
    const totKey = `${d.restaurant_number}__${d.product_id}`;
    cellTotal.set(totKey, (cellTotal.get(totKey) || 0) + (parseFloat(d.stock) || 0));
    const ed = d.expiry_date ? String(d.expiry_date).slice(0, 10) : '';
    if (ed) {
      const dKey = `${d.restaurant_number}__${d.product_id}__${ed}`;
      cellByDate.set(dKey, (cellByDate.get(dKey) || 0) + (parseFloat(d.stock) || 0));
    }
  }

  // Стоимость по ресторану (для итогового столбца) и общий итог.
  let grandTotalCost = 0;
  const restTotalCost = new Map();

  // Строки данных
  restNums.forEach((num, ri) => {
    const stripe = ri % 2 === 1;
    const info = getRestaurantInfo(num);
    ws[XLSX.utils.encode_cell({ r, c: 0 })] = { v: `Ресторан ${num}`, t: 's', s: sB(stripe) };
    ws[XLSX.utils.encode_cell({ r, c: 1 })] = { v: info.city, t: 's', s: sC(stripe) };
    ws[XLSX.utils.encode_cell({ r, c: 2 })] = { v: info.address, t: 's', s: sC(stripe) };
    let rowCost = 0;
    for (const p of products) {
      const cfg = prodCol.get(p.id);
      if (cfg.hasExpiry) {
        cfg.dates.forEach((dt, i) => {
          const v = cellByDate.get(`${num}__${p.id}__${dt}`);
          const cell = v == null ? { v: '', t: 's' } : { v: parseFloat(v.toFixed(2)), t: 'n' };
          ws[XLSX.utils.encode_cell({ r, c: cfg.start + i })] = { ...cell, s: sCDate(stripe) };
        });
        const tot = cellTotal.get(`${num}__${p.id}`);
        ws[XLSX.utils.encode_cell({ r, c: cfg.start + cfg.dates.length })] =
          tot == null ? { v: '', t: 's', s: sCTotal(stripe) } : { v: parseFloat(tot.toFixed(2)), t: 'n', s: sCTotal(stripe) };
        if (tot != null && priceById.has(p.id)) rowCost += tot * priceById.get(p.id);
      } else {
        const tot = cellTotal.get(`${num}__${p.id}`);
        ws[XLSX.utils.encode_cell({ r, c: cfg.start })] =
          tot == null ? { v: '', t: 's', s: sC(stripe) } : { v: parseFloat(tot.toFixed(2)), t: 'n', s: sC(stripe) };
        if (tot != null && priceById.has(p.id)) rowCost += tot * priceById.get(p.id);
      }
    }
    if (hasAnyPrice) {
      ws[XLSX.utils.encode_cell({ r, c: sumCol })] = rowCost > 0
        ? { v: parseFloat(rowCost.toFixed(2)), t: 'n', s: sCTotal(stripe) }
        : { v: '', t: 's', s: sCTotal(stripe) };
    }
    if (rowCost > 0) {
      restTotalCost.set(num, rowCost);
      grandTotalCost += rowCost;
    }
    r++;
  });

  // Итого
  const sBold = { font: { bold: true, sz: 11, color: { rgb: brown }, name: 'Calibri' }, border: borders };
  const sBoldAcc = { font: { bold: true, sz: 11, color: { rgb: brown }, name: 'Calibri' }, fill: { fgColor: { rgb: totalBg } }, border: borders };
  ws[XLSX.utils.encode_cell({ r, c: 0 })] = { v: 'Итого', t: 's', s: sBold };
  ws[XLSX.utils.encode_cell({ r, c: 1 })] = { v: '', t: 's', s: sBold };
  ws[XLSX.utils.encode_cell({ r, c: 2 })] = { v: '', t: 's', s: sBold };
  const productGrandTotal = new Map(); // productId → суммарный объём (для строки «Стоимость»)
  for (const p of products) {
    const cfg = prodCol.get(p.id);
    if (cfg.hasExpiry) {
      cfg.dates.forEach((dt, i) => {
        let s = 0;
        for (const num of restNums) {
          s += cellByDate.get(`${num}__${p.id}__${dt}`) || 0;
        }
        ws[XLSX.utils.encode_cell({ r, c: cfg.start + i })] = { v: parseFloat(s.toFixed(2)), t: 'n', s: sBold };
      });
      let tot = 0;
      for (const num of restNums) tot += cellTotal.get(`${num}__${p.id}`) || 0;
      productGrandTotal.set(p.id, tot);
      ws[XLSX.utils.encode_cell({ r, c: cfg.start + cfg.dates.length })] = { v: parseFloat(tot.toFixed(2)), t: 'n', s: sBoldAcc };
    } else {
      let tot = 0;
      for (const num of restNums) tot += cellTotal.get(`${num}__${p.id}`) || 0;
      productGrandTotal.set(p.id, tot);
      ws[XLSX.utils.encode_cell({ r, c: cfg.start })] = { v: parseFloat(tot.toFixed(2)), t: 'n', s: sBold };
    }
  }
  if (hasAnyPrice) {
    ws[XLSX.utils.encode_cell({ r, c: sumCol })] = grandTotalCost > 0
      ? { v: parseFloat(grandTotalCost.toFixed(2)), t: 'n', s: sBoldAcc }
      : { v: '', t: 's', s: sBoldAcc };
  }

  // Строка «Стоимость, Br» — по каждому товару (объём × цена), и общий итог справа.
  if (hasAnyPrice) {
    r++;
    const sCostHead = { font: { bold: true, sz: 11, color: { rgb: brown }, name: 'Calibri' }, fill: { fgColor: { rgb: 'FBF1E0' } }, border: borders };
    const sCostCell = { font: { bold: true, sz: 11, color: { rgb: brown }, name: 'Calibri' }, fill: { fgColor: { rgb: 'FBF1E0' } }, alignment: { horizontal: 'right' }, border: borders };
    ws[XLSX.utils.encode_cell({ r, c: 0 })] = { v: 'Стоимость, Br', t: 's', s: sCostHead };
    ws[XLSX.utils.encode_cell({ r, c: 1 })] = { v: '', t: 's', s: sCostHead };
    ws[XLSX.utils.encode_cell({ r, c: 2 })] = { v: '', t: 's', s: sCostHead };
    for (const p of products) {
      const cfg = prodCol.get(p.id);
      // Подзаголовки «до даты» оставляем пустыми, цену пишем только в «Итого» товара.
      if (cfg.hasExpiry) {
        cfg.dates.forEach((dt, i) => {
          ws[XLSX.utils.encode_cell({ r, c: cfg.start + i })] = { v: '', t: 's', s: sCostHead };
        });
      }
      const targetC = cfg.hasExpiry ? cfg.start + cfg.dates.length : cfg.start;
      if (priceById.has(p.id)) {
        const cost = (productGrandTotal.get(p.id) || 0) * priceById.get(p.id);
        ws[XLSX.utils.encode_cell({ r, c: targetC })] = { v: parseFloat(cost.toFixed(2)), t: 'n', s: sCostCell };
      } else {
        ws[XLSX.utils.encode_cell({ r, c: targetC })] = { v: '', t: 's', s: sCostHead };
      }
    }
    ws[XLSX.utils.encode_cell({ r, c: sumCol })] = { v: parseFloat(grandTotalCost.toFixed(2)), t: 'n', s: sCostCell };
  }

  ws['!ref'] = XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r, c: lastC } });
  ws['!cols'] = cols;
  ws['!merges'] = merges;

  // ───── Лист «Партии» ─────
  const ws2 = {};
  const cols2 = [{ wch: 12 }, { wch: 14 }, { wch: 26 }, { wch: 12 }, { wch: 28 }, { wch: 12 }, { wch: 9 }, { wch: 8 }, { wch: 12 }, { wch: 16 }];
  const head2 = ['Ресторан', 'Город', 'Адрес', 'Артикул', 'Товар', 'Срок', 'Объём', 'Ед.', 'Источник', 'Дата ввода'];
  head2.forEach((h, i) => {
    ws2[XLSX.utils.encode_cell({ r: 0, c: i })] = { v: h, t: 's', s: sH };
  });

  const productById = new Map(products.map(p => [p.id, p]));
  // Сортировка: ресторан → товар (по имени) → срок
  const sortedData = [...allData].sort((a, b) => {
    const ra = String(a.restaurant_number || '');
    const rb = String(b.restaurant_number || '');
    const cmpR = ra.localeCompare(rb, undefined, { numeric: true });
    if (cmpR !== 0) return cmpR;
    const pa = productById.get(a.product_id)?.product_name || '';
    const pb = productById.get(b.product_id)?.product_name || '';
    const cmpP = pa.localeCompare(pb, 'ru');
    if (cmpP !== 0) return cmpP;
    const da = a.expiry_date ? String(a.expiry_date).slice(0, 10) : '';
    const db = b.expiry_date ? String(b.expiry_date).slice(0, 10) : '';
    return da.localeCompare(db);
  });

  let r2 = 1;
  sortedData.forEach((d, i) => {
    const stripe = i % 2 === 1;
    const info = getRestaurantInfo(d.restaurant_number);
    const p = productById.get(d.product_id);
    const ed = d.expiry_date ? String(d.expiry_date).slice(0, 10) : '';
    const stockNum = parseFloat(d.stock) || 0;
    const row = [
      { v: `Ресторан ${d.restaurant_number}`, t: 's', s: sB(stripe) },
      { v: info.city, t: 's', s: sC(stripe) },
      { v: info.address, t: 's', s: sC(stripe) },
      { v: p?.product_sku || '', t: 's', s: sC(stripe) },
      { v: p?.product_name || '', t: 's', s: sC(stripe) },
      { v: ed ? formatBatchDate(ed) : 'без срока', t: 's', s: sC(stripe) },
      { v: parseFloat(stockNum.toFixed(2)), t: 'n', s: sC(stripe) },
      { v: p ? unitLabel(p.unit) : '', t: 's', s: sC(stripe) },
      { v: sourceLabel(d.source), t: 's', s: sC(stripe) },
      { v: d.submitted_at ? fmtDate(d.submitted_at) : '', t: 's', s: sC(stripe) },
    ];
    row.forEach((cell, c) => { ws2[XLSX.utils.encode_cell({ r: r2, c })] = cell; });
    r2++;
  });
  ws2['!ref'] = XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: Math.max(r2 - 1, 0), c: head2.length - 1 } });
  ws2['!cols'] = cols2;

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Остатки');
  XLSX.utils.book_append_sheet(wb, ws2, 'Партии');
  const safeName = activeCollection.value.name.replace(/[^а-яА-ЯёЁa-zA-Z0-9\s]/g, '').trim();
  XLSX.writeFile(wb, `Остатки_${safeName}_${new Date().toLocaleDateString('ru-RU')}.xlsx`);
}

function sourceLabel(s) {
  if (s === 'form') return 'Форма';
  if (s === 'file') return 'Файл';
  return 'Вручную';
}

function unitLabel(u) {
  if (u === 'boxes') return 'кор.';
  if (u === 'kg') return 'кг';
  if (u === 'liters') return 'л';
  return 'шт.';
}

function fmtShort(s) {
  if (!s) return '';
  const d = new Date(s);
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' }) + ' ' + d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

function fmtDate(s) {
  if (!s) return '';
  const d = new Date(s);
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' }) + ' ' + d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}
function fmtTime(s) {
  if (!s) return '';
  return new Date(s).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}
</script>

<style scoped>
.retry-banner {
  display: flex; align-items: center; gap: 12px; padding: 12px 16px;
  background: #FFF3E0; border: 1px solid #FFE0B2; border-radius: 8px;
  color: #E65100; font-size: 13px; margin-bottom: 16px;
}
.retry-banner .btn { flex-shrink: 0; }
.sc { --brown: #502314; --orange: #F4A261; --red: #E76F51; --green: #2E7D32; --border: #EDE7DF; --muted: #8C7B6E; --bg2: #F9F6F2; }
.modal-confirm { z-index: 10001; }

/* Top */
.sc-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
.sc-title { font-family: 'Flame', sans-serif; font-size: 22px; font-weight: 700; color: var(--brown); margin: 0; }

/* List */
.sc-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; }
.sc-card {
  background: #fff; border: 1px solid var(--border); border-radius: 12px;
  padding: 16px; cursor: pointer; transition: all 0.12s;
}
.sc-card:hover { border-color: var(--orange); box-shadow: 0 2px 12px rgba(44,24,16,0.08); }
.sc-card.closed { opacity: 0.55; }
.sc-card-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.sc-card-name { font-size: 15px; font-weight: 700; color: var(--brown); }
.sc-card-meta { font-size: 12px; color: var(--muted); margin-top: 4px; }
.sc-tag { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 8px; white-space: nowrap; }
.sc-tag.green { background: #E8F5E9; color: #2E7D32; }
.sc-tag.gray { background: #f0ece6; color: #999; }
.sc-empty { text-align: center; color: var(--muted); padding: 40px; font-size: 14px; }

/* Missing restaurants */
.sc-missing {
  background: #FFF8E1; border: 1px solid #FFE082; border-radius: 10px;
  padding: 10px 14px; margin-top: 12px;
}
.sc-missing-head {
  font-size: 13px; color: #F57F17; cursor: pointer; user-select: none;
  display: flex; align-items: center; gap: 6px;
}
.sc-missing-head b { color: #E65100; }
.sc-missing-icon { font-size: 10px; width: 12px; }
.sc-missing-list { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.sc-missing-tag {
  font-size: 11px; padding: 3px 8px; background: #fff; border: 1px solid #FFE082;
  border-radius: 6px; color: #795548; white-space: nowrap;
}

/* Detail */
.sc-detail-bar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
.sc-detail-info { display: flex; align-items: center; gap: 8px; flex: 1; min-width: 0; }
.sc-detail-name { font-size: 18px; font-weight: 700; color: var(--brown); font-family: 'Flame', sans-serif; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sc-detail-actions { display: flex; gap: 8px; flex-wrap: wrap; }

/* Token */
.sc-token { background: #FFFBF0; border: 1px solid #FFE082; border-radius: 10px; padding: 12px; margin-bottom: 16px; }
.sc-token-label { font-size: 11px; font-weight: 600; color: #F57F17; margin-bottom: 6px; }
.sc-token-row { display: flex; gap: 6px; }
.sc-token-input { flex: 1; padding: 6px 8px; border: 1px solid #FFE082; border-radius: 6px; font-size: 11px; background: #fff; font-family: monospace; }

/* Summary */
.sc-summary {
  display: flex; align-items: center; gap: 16px; padding: 12px 16px;
  background: #fff; border: 1px solid var(--border); border-radius: 10px;
  margin-bottom: 16px;
}
.sc-summary-item { text-align: center; }
.sc-summary-num { font-size: 18px; font-weight: 700; color: var(--brown); font-family: 'Flame', sans-serif; }
.sc-summary-lbl { font-size: 10px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.3px; }

/* Filter */
.sc-filter-bar { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
.sc-filter-input { max-width: 320px; padding: 7px 12px; border: 1px solid var(--border); border-radius: 8px; font-size: 13px; color: var(--brown); background: #fff; }
.sc-filter-input:focus { outline: none; border-color: var(--orange); box-shadow: 0 0 0 2px rgba(255,135,50,0.15); }
.sc-filter-input::placeholder { color: #bbb; }
.sc-status-chips { display: inline-flex; gap: 6px; align-items: center; }
.sc-status-chip { padding: 6px 12px; border-radius: 999px; border: 1px solid var(--border); background: #fff; color: var(--brown); font-size: 12.5px; font-weight: 700; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 6px; transition: .12s ease; }
.sc-status-chip:hover { border-color: var(--orange); color: var(--orange); }
.sc-status-chip.active { background: var(--brown); border-color: var(--brown); color: #fff; }
.sc-status-chip .sc-chip-count { display: inline-flex; align-items: center; justify-content: center; min-width: 18px; padding: 0 6px; border-radius: 999px; background: rgba(0,0,0,.08); font-size: 11px; font-weight: 800; }
.sc-status-chip.active .sc-chip-count { background: rgba(255,255,255,.18); }
.sc-filter-count { font-size: 12px; color: var(--muted); white-space: nowrap; }
th.sortable { cursor: pointer; user-select: none; }
th.sortable:hover { background: rgba(80,35,20,0.08); }
.sort-arrow { font-size: 10px; opacity: 0.4; margin-left: 2px; }
th.sortable:hover .sort-arrow { opacity: 0.7; }

/* Table */
.sc-tbl-wrap {
  background: #fff; border: 1px solid var(--border); border-radius: 10px;
  overflow-x: auto;
}
.sc-tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.sc-tbl thead tr { background: #502314; }
.sc-tbl thead th {
  color: #fff; font-size: 11px; font-weight: 600;
  padding: 6px 5px; text-align: center;
}
.sc-tbl thead th.col-prod { white-space: normal; word-break: break-word; max-width: 80px; font-size: 10px; line-height: 1.3; }
.sc-tbl thead th .th-unit { font-weight: 400; font-size: 9px; opacity: 0.7; margin-top: 1px; }
.sc-tbl thead th .th-sku-inline { font-weight: 700; color: #FFD8B0; margin-right: 4px; letter-spacing: 0.02em; }
.sc-tbl thead th .th-price { font-weight: 700; font-size: 9.5px; color: #FFE0AA; margin-top: 2px; letter-spacing: 0.02em; }
.sc-tbl .col-rest-sum { text-align: right; padding-right: 10px; font-weight: 700; }
.sc-tbl tfoot .foot-val-cost { color: var(--brown); background: #FBF1E0; font-weight: 700; }
.sc-tbl tfoot tr.sc-foot-cost td { border-top: 2px solid #E0CB9F; }

.sc-cost-summary { display: flex; align-items: center; gap: 16px; margin: 14px 0; padding: 14px 18px; background: linear-gradient(135deg, #FFF8EB 0%, #FBF1E0 100%); border: 1px solid #E0CB9F; border-radius: 12px; flex-wrap: wrap; }
.sc-cost-summary-main { display: flex; flex-direction: column; gap: 2px; }
.sc-cost-summary-label { font-size: 12px; color: #8b7355; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; }
.sc-cost-summary-value { font-size: 24px; font-weight: 800; color: #502314; }
.sc-cost-summary-meta { font-size: 12.5px; color: #4b3527; display: flex; gap: 6px; flex-wrap: wrap; }
.sc-cost-summary-meta .muted { color: #8b7355; }

.sc-prices-hint { padding: 10px 14px; color: #6b4f3a; font-size: 13px; background: #FBF6EE; border-radius: 8px; margin: 10px 0; }
.sc-prices-list { max-height: 50vh; overflow: auto; padding-right: 4px; }
.sc-prices-row { display: flex; align-items: center; gap: 12px; padding: 8px 4px; border-bottom: 1px solid #EFE5D5; }
.sc-prices-row:last-child { border-bottom: none; }
.sc-prices-name { flex: 1; min-width: 0; }
.sc-prices-name > div:first-child { font-size: 13.5px; color: #502314; font-weight: 600; word-break: break-word; }
.sc-prices-sub { font-size: 11.5px; color: #8b7355; margin-top: 2px; }
.sc-prices-input-wrap { display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0; }
.sc-prices-input { width: 110px; text-align: right; }
.sc-prices-currency { color: #8b7355; font-weight: 700; font-size: 12px; }
.th-flag { font-weight: 700; font-size: 9px; color: #ffd8bf; margin-top: 1px; text-transform: uppercase; letter-spacing: 0.02em; }
.sc-tbl thead th .th-note { font-weight: 400; font-size: 9px; color: #c88; font-style: italic; margin-top: 1px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 120px; }
.sc-tbl tbody td {
  padding: 5px 5px; border-bottom: 1px solid #f0ece6; text-align: center;
}
.sc-tbl tbody tr:nth-child(even) { background: #FEFBF7; }
.sc-tbl tbody tr:hover { background: #FFF3E0; }
.sc-tbl tbody tr:last-child td { border-bottom: none; }

.col-num { text-align: left !important; white-space: nowrap; min-width: 80px; }
.col-city { text-align: left !important; white-space: nowrap; font-size: 12px; }
.col-addr { text-align: left !important; font-size: 12px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.col-prod { min-width: 50px; max-width: 90px; font-size: 12px; }
.col-time { white-space: nowrap; font-size: 11px; width: 80px; }
.col-del { width: 36px; text-align: center !important; }
.sc-row-del {
  width: 24px; height: 24px; border: none; background: none;
  cursor: pointer; font-size: 12px; border-radius: 4px;
  color: #ccc; display: inline-flex; align-items: center; justify-content: center;
  transition: all 0.1s;
}
.sc-row-del:hover { background: #FFEBEE; color: #E76F51; }
.muted { color: #8C7B6E; }
.fw { font-weight: 700; color: #502314; }

.sc-tbl tfoot td {
  padding: 8px 12px; border-top: 2px solid var(--border); text-align: center;
}
.foot-label { font-weight: 700; color: var(--muted); font-size: 12px; text-align: left !important; }
.foot-val { font-weight: 700; color: #502314; }

/* Cell values */
.sc-cell {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-height: 38px;
  justify-content: center;
  align-items: center;
  padding: 2px 0;
}
.sc-cell.editable { cursor: pointer; border-radius: 6px; }
.sc-cell.editable:hover { background: rgba(244, 162, 97, 0.08); }
.sc-cell-total { font-weight: 700; color: #502314; line-height: 1.15; }
.sc-cell-batches { display: flex; flex-direction: column; gap: 1px; align-items: center; }
.sc-cell-batch { display: flex; gap: 4px; align-items: center; justify-content: center; font-size: 10px; color: #8c7b6e; line-height: 1.1; }
.sc-cell-batch-date { white-space: nowrap; }
.sc-cell-batch-stock { font-weight: 600; color: #6b5344; white-space: nowrap; }
.sc-cell-more { font-size: 10px; color: #c16b4d; line-height: 1.1; }
.sc-cell-empty-mark { color: #ddd; }

/* Раскрывающиеся группы по датам */
.prod-group-head.expandable { cursor: pointer; }
.prod-group-head.expandable:hover { background: #6B321F; }
.prod-group-head.expanded { background: #6B321F; border-bottom: 2px solid #F4A261; }
.prod-toggle-icon { display: inline-block; margin-left: 3px; font-size: 10px; color: #F4A261; }
.col-prod-date { background: #6B321F !important; font-size: 9px !important; min-width: 56px; }
.prod-date-label { font-weight: 600; line-height: 1.2; white-space: nowrap; }
.col-prod-total { background: #6B321F !important; font-size: 10px !important; min-width: 50px; border-left: 1px solid rgba(255,255,255,0.15); }
.col-prod-date-cell { background: rgba(244, 162, 97, 0.04); font-size: 12px; min-width: 56px; }
.col-prod-date-cell .sc-cell-date-val { font-weight: 600; color: #6B5344; min-height: 24px; line-height: 24px; }
.col-prod-date-cell .sc-cell-date-val:empty::before { content: '—'; color: #ddd; font-weight: 400; }
.col-prod-total-cell { background: rgba(80, 35, 20, 0.04); border-left: 1px solid #efe6da; }
.col-prod-total-cell .sc-cell-total { font-weight: 700; }
.foot-val-strong { background: rgba(80, 35, 20, 0.06); }

/* Cell edit */
.sc-cell-editor-head { margin-bottom: 12px; }
.sc-cell-editor-title { font-size: 15px; font-weight: 700; color: #502314; }
.sc-cell-editor-meta { font-size: 12px; color: #8c7b6e; margin-top: 2px; }
.sc-cell-editor-batches { display: flex; flex-direction: column; gap: 8px; }
.sc-cell-editor-row { display: flex; align-items: center; gap: 8px; }
.sc-cell-editor-date { width: 180px; }
.sc-cell-editor-stock { width: 120px; text-align: center; }
.sc-cell-editor-single { display: flex; justify-content: center; }
.sc-cell-editor-single .sc-cell-editor-stock.full { width: 100%; max-width: 240px; }

/* Buttons */
.sc-btn {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 7px 14px; border-radius: 7px; font-size: 12px; font-weight: 600;
  font-family: inherit; border: 1.5px solid transparent; cursor: pointer;
  transition: all 0.12s; white-space: nowrap;
}
.sc-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.sc-btn.fill { background: #502314 !important; color: #fff !important; border-color: #502314 !important; }
.sc-btn.fill:hover:not(:disabled) { background: #3D1A0D !important; }
.sc-btn.outline { background: #fff !important; color: #6B5344 !important; border-color: #EDE7DF !important; }
.sc-btn.outline:hover:not(:disabled) { background: #F9F6F2 !important; }
.sc-btn.sm { padding: 4px 10px; font-size: 11px; }
.sc-btn.full { width: 100%; justify-content: center; }
.red-text { color: var(--red) !important; }
.green-text { color: #15803d !important; }
.sc-x { width: 28px; height: 28px; border: none; background: none; color: #bbb; cursor: pointer; font-size: 14px; border-radius: 4px; display: flex; align-items: center; justify-content: center; }
.sc-x:hover { background: #FFEBEE; color: var(--red); }
.sc-x.sm { width: 24px; height: 24px; font-size: 12px; }

/* Modal */
.sc-modal-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.sc-modal-head h3 { margin: 0; font-size: 16px; color: var(--brown); }
.sc-modal-foot { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; padding-top: 14px; border-top: 1px solid var(--border); }
.sc-confirm-text { font-size: 13px; color: #555; line-height: 1.5; margin: 0; }
.btn-danger { background: var(--red) !important; border-color: var(--red) !important; }
.btn-danger:hover:not(:disabled) { background: #B71C00 !important; }

/* Fields */
.sc-field { margin-bottom: 14px; }
.sc-field label { display: block; font-size: 11px; font-weight: 600; color: var(--muted); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.3px; }
.sc-input {
  padding: 8px 11px; border: 1.5px solid var(--border); border-radius: 7px;
  font-size: 13px; font-family: inherit; transition: border-color 0.12s;
}
.sc-input:focus { outline: none; border-color: var(--orange); }
.sc-input.full { width: 100%; box-sizing: border-box; }
.sc-input.selected { border-color: #A5D6A7; background: #FCFFF9; }
.sc-input:disabled { background: var(--bg2); color: var(--muted); }

/* Product card in create modal */
.sc-product-card {
  position: relative;
  background: var(--bg2); border: 1.5px solid var(--border); border-radius: 10px;
  padding: 14px; margin-bottom: 10px;
}
.sc-card-remove {
  position: absolute; top: 8px; right: 8px;
  width: 22px; height: 22px; border: none; background: none;
  color: #bbb; cursor: pointer; font-size: 12px; border-radius: 4px;
  display: flex; align-items: center; justify-content: center;
}
.sc-card-remove:hover { background: #FFEBEE; color: var(--red); }

/* Selected product display */
.sc-product-selected {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  background: #fff; border: 1.5px solid #A5D6A7; border-radius: 8px; padding: 10px 12px;
}
.sc-product-selected-name { font-size: 14px; font-weight: 700; color: var(--brown); }
.sc-product-selected-meta { display: flex; gap: 8px; font-size: 11px; color: var(--muted); margin-top: 2px; }
.sc-product-selected-meta span { white-space: nowrap; }

/* Search area */
.sc-product-search { position: relative; }
.sc-manual-hint { font-size: 11px; color: var(--muted); margin-top: 6px; }
.sc-link-btn { background: none; border: none; color: var(--orange); font-weight: 600; font-size: 11px; cursor: pointer; text-decoration: underline; font-family: inherit; padding: 0; }
.sc-manual-fields { margin-top: 8px; }

/* Unit row */
.sc-product-unit-row { display: flex; align-items: center; gap: 10px; margin-top: 10px; }
.sc-product-unit-label { font-size: 12px; color: var(--muted); font-weight: 500; }
.sc-unit-locked { font-size: 12px; font-weight: 700; color: #502314; padding: 5px 12px; background: #F0EBE4; border-radius: 6px; }
.sc-product-flags {
  display: flex;
  align-items: center;
  gap: 14px;
  flex-wrap: wrap;
  margin-top: 10px;
}
.sc-note-input { margin-top: 8px; font-size: 12px; }
.sc-note-input::placeholder { font-style: italic; }
.sc-need-expiry {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 0;
  width: 100%;
}
.sc-need-expiry .sc-product-unit-label {
  margin: 0;
  white-space: nowrap;
}

/* Dropdown */
.sc-drop {
  position: absolute; z-index: 60; left: 0; right: 0; margin-top: 3px;
  background: #fff; border: 1px solid var(--border); border-radius: 8px;
  box-shadow: 0 8px 28px rgba(44,24,16,0.12); max-height: 220px; overflow-y: auto;
}
.sc-drop-item {
  padding: 8px 12px; cursor: pointer;
  border-bottom: 1px solid var(--border); transition: background 0.08s;
}
.sc-drop-item:last-child { border-bottom: none; }
.sc-drop-item:hover { background: #FFF8F0; }
.sc-drop-manual { border-top: 2px solid var(--border); background: #FEFBF7; }
.sc-drop-manual .sc-drop-name { color: var(--orange); }
.sc-drop-name { font-size: 13px; font-weight: 600; color: var(--brown); }
.sc-drop-meta { font-size: 10px; color: var(--muted); }

/* Switcher */
.sc-switcher { display: inline-flex; border: 1.5px solid var(--border); border-radius: 6px; overflow: hidden; flex-shrink: 0; }
.sc-switcher button {
  padding: 6px 11px; font-size: 11px; font-weight: 600; font-family: inherit;
  border: none; cursor: pointer; background: transparent; color: #8C7B6E; transition: all 0.12s;
}
.sc-switcher button:not(:last-child) { border-right: 1.5px solid #EDE7DF; }
.sc-switcher .on { background: #502314 !important; color: #fff !important; }
.sc-switcher button:hover:not(.on) { background: #F9F6F2; }

/* Edit products */
.sc-product-edit-fields { }
.sc-product-edit-row { display: flex; gap: 8px; }
.sc-product-delete-warn {
  margin-top: 8px; padding: 6px 10px; background: #FFF3E0;
  border: 1px solid #FFE082; border-radius: 6px;
  font-size: 11px; color: #E65100;
  display: flex; align-items: center; gap: 8px;
}

@media (max-width: 640px) {
  .sc-product-card { padding: 10px; }
  .sc-detail-bar { flex-direction: column; align-items: flex-start; }
  .sc-detail-actions { flex-wrap: wrap; }
  .sc-summary { flex-wrap: wrap; gap: 12px; }
}
</style>
