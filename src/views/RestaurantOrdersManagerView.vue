<template>
  <div class="rom-page">
    <!-- ─── Шапка страницы ─── -->
    <div class="rom-header">
      <div class="rom-header-left">
        <h1>Заказы ресторанов</h1>
        <div class="rom-page-tabs">
          <button class="rom-page-tab" :class="{ active: pageTab === 'orders' }" @click="pageTab = 'orders'">
            Заявки
          </button>
          <button class="rom-page-tab" :class="{ active: pageTab === 'templates' }" @click="pageTab = 'templates'; loadFullTemplates()">
            Шаблон заказа
          </button>
          <button class="rom-page-tab" :class="{ active: pageTab === 'stock' }" @click="pageTab = 'stock'; initStockTab()">
            Остатки
          </button>
          <button class="rom-page-tab" :class="{ active: pageTab === 'audit' }" @click="pageTab = 'audit'; loadAuditLog()">
            Журнал
          </button>
          <router-link :to="{ name: 'restaurant-report' }" class="rom-page-tab rom-page-tab-link">
            Отчёт →
          </router-link>
        </div>
      </div>
      <div class="rom-header-right">
        <button class="rom-btn rom-btn-primary" @click="handleAutoSession">
          {{ session ? 'Сессия активна' : 'Создать сессию' }}
        </button>
        <div class="rom-menu-wrap" v-click-outside="() => moreMenuOpen = false">
          <button class="rom-btn rom-btn-icon" @click="moreMenuOpen = !moreMenuOpen" title="Ещё">⋯</button>
          <div v-if="moreMenuOpen" class="rom-menu">
            <button class="rom-menu-item" @click="moreMenuOpen = false; openUsersModal()">Учётки ресторанов</button>
            <button class="rom-menu-item" @click="moreMenuOpen = false; copyRoLink()">Скопировать ссылку /restaurant</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══ TAB: Orders ═══ -->
    <template v-if="pageTab === 'orders'">
      <!-- Командир: дата + дедлайн + управление приёмом -->
      <div class="rom-command">
        <div class="rom-command-date">
          <label class="rom-cmd-label">Дата доставки</label>
          <input type="date" v-model="selectedDate" @change="loadStatus" class="rom-cmd-date" />
        </div>
        <div class="rom-command-deadline" :class="'dl-' + (deadlineStatus?.status || 'none')">
          <div class="rom-cmd-label">Статус приёма</div>
          <div class="rom-cmd-deadline-text">{{ deadlineLabel || (session ? 'Приём не открыт' : 'Нет сессии') }}</div>
          <div v-if="deadlineStatus?.soft_deadline || deadlineStatus?.hard_deadline" class="rom-cmd-deadline-times">
            <span v-if="deadlineStatus.soft_deadline">мягкий: <strong>{{ deadlineStatus.soft_deadline.slice(0,5) }}</strong></span>
            <span v-if="deadlineStatus.hard_deadline">жёсткий: <strong>{{ deadlineStatus.hard_deadline.slice(0,5) }}</strong></span>
          </div>
        </div>
        <div class="rom-command-actions">
          <button v-if="session && !isDateOpen" class="rom-btn rom-btn-success" @click="handleToggleDate(true)">
            Открыть приём
          </button>
          <button v-if="session && isDateOpen" class="rom-btn rom-btn-danger" @click="handleToggleDate(false)">
            Закрыть приём
          </button>
          <button v-if="session && isDateOpen" class="rom-btn" @click="handleExtendDeadline">
            Продлить дедлайн
          </button>
          <button class="rom-btn rom-btn-export" @click="downloadCttJson" :disabled="cttJsonExporting || exportExporting || !session" title="Скачать JSON для CTT">
            {{ cttJsonExporting ? 'JSON...' : 'JSON' }}
          </button>
          <button class="rom-btn rom-btn-export" @click="openExportModal" :disabled="exportExporting">
            Excel
          </button>
          <button class="rom-btn rom-btn-icon" @click="loadStatus" :disabled="loading" title="Обновить">
            {{ loading ? '...' : '↻' }}
          </button>
        </div>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="rom-loading">Загрузка...</div>

      <!-- No session -->
      <div v-else-if="!session" class="rom-empty">
        Нет активной сессии. Нажмите «Создать сессию» для открытия приёма заявок.
      </div>

      <template v-else>
        <!-- Карточки-сводка -->
        <div class="rom-cards">
          <div class="rom-card">
            <div class="rom-card-num">{{ stats.submitted }}</div>
            <div class="rom-card-label">подано</div>
          </div>
          <div class="rom-card rom-card-warn">
            <div class="rom-card-num">{{ stats.pending }}</div>
            <div class="rom-card-label">не подано</div>
          </div>
          <div class="rom-card">
            <div class="rom-card-num">{{ stats.total }}</div>
            <div class="rom-card-label">всего</div>
          </div>
          <div class="rom-card rom-card-info">
            <div class="rom-card-num">{{ totalStats.boxes }}</div>
            <div class="rom-card-label">коробок</div>
          </div>
          <div class="rom-card rom-card-info">
            <div class="rom-card-num">{{ totalStats.weight }}</div>
            <div class="rom-card-label">кг</div>
          </div>
          <div class="rom-card rom-card-info">
            <div class="rom-card-num">{{ totalStats.pallets }}</div>
            <div class="rom-card-label">паллет</div>
          </div>
        </div>

        <!-- Поиск + фильтры -->
        <div class="rom-list-filters">
          <input v-model="restFilter" type="text" placeholder="Поиск по номеру, городу или адресу..." class="rom-input rom-list-search" />
          <div class="rom-list-status-filters">
            <button class="rom-chip" :class="{ active: restStatusFilter === '' }" @click="restStatusFilter = ''">Все</button>
            <button class="rom-chip" :class="{ active: restStatusFilter === 'submitted' }" @click="restStatusFilter = 'submitted'">Подано</button>
            <button class="rom-chip rom-chip-warn" :class="{ active: restStatusFilter === 'pending' }" @click="restStatusFilter = 'pending'">Не подано</button>
          </div>
        </div>

        <!-- Единая таблица всех заявок -->
        <div class="rom-table-card">
          <table class="rom-table rom-table-compact">
            <colgroup>
              <col style="width:50px">
              <col style="width:32%">
              <col style="width:110px">
              <col style="width:240px">
              <col style="width:110px">
              <col style="width:200px">
              <col style="width:46px">
            </colgroup>
            <thead>
              <tr>
                <th>№</th>
                <th class="rom-th-left">Ресторан</th>
                <th>Статус</th>
                <th>Объём</th>
                <th>Подано</th>
                <th>Изменён</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in filteredRestaurants" :key="r.number"
                  class="rom-row"
                  :class="{
                    'rom-row-submitted': r.order_status,
                    'rom-row-pending': !r.order_status,
                    'rom-row-clickable': r.order_id,
                  }"
                  @click="r.order_id && viewOrder(r.order_id)">
                <td class="rom-td-num">{{ r.number }}</td>
                <td class="rom-td-rest">
                  <span class="rom-cell-rest-city">{{ r.city }}</span><span v-if="r.address">, </span><span class="rom-cell-rest-addr">{{ r.address }}</span>
                </td>
                <td class="rom-td-status">
                  <span class="rom-status" :class="'st-' + (r.order_status || 'none')">
                    {{ statusLabel(r.order_status) }}
                  </span>
                  <span v-if="r.order_comment" class="rom-comment-icon" :title="r.order_comment">💬</span>
                </td>
                <td class="rom-td-volume">
                  <template v-if="r.order_status">
                    {{ r.item_count || 0 }} поз. · {{ r.total_qty ? (+r.total_qty).toFixed(0) : 0 }} кор. · {{ r.total_weight ? (r.total_weight / 1000).toFixed(1) : 0 }} кг · {{ r.pallets || 0 }} пал.
                  </template>
                  <span v-else class="rom-dim">—</span>
                </td>
                <td class="rom-td-time">
                  {{ r.submitted_at ? formatDateTime(r.submitted_at) : '—' }}
                </td>
                <td class="rom-td-edited" :title="r.updated_by ? formatDateTime(r.updated_at) + ' (' + r.updated_by + ')' : ''">
                  <template v-if="r.updated_by">
                    <span class="rom-td-edited-by">{{ r.updated_by }}</span> · {{ formatTime(r.updated_at) }}
                  </template>
                  <template v-else>—</template>
                </td>
                <td class="rom-td-actions" @click.stop>
                  <button v-if="r.order_id" class="rom-btn-sm rom-btn-export-sm" @click="quickExportOrder(r.order_id, r.number, r.legal_entity_group)" title="Скачать Excel">
                    ⬇
                  </button>
                </td>
              </tr>
              <tr v-if="!filteredRestaurants.length">
                <td colspan="7" class="rom-no-items">Ничего не найдено</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </template>

    <!-- ═══ TAB: Templates ═══ -->
    <template v-if="pageTab === 'templates'">
      <div class="rom-panel">
        <div class="rom-tpl-toolbar">
          <div class="rom-tpl-tabs-inline">
            <button v-for="cat in ['Сухой','Холод','Мороз']" :key="cat"
              class="rom-tpl-tab" :class="{ active: tplCategory === cat }"
              @click="tplCategory = cat">
              {{ cat }}
              <span class="rom-tpl-tab-count">{{ fullTemplateItems.filter(i => i.category === cat).length }}</span>
            </button>
          </div>
          <div class="rom-tpl-toolbar-right">
            <button class="rom-btn" @click="handleImportFromStock" title="Загрузить в шаблон только те товары, у которых есть остаток на складе">
              Импортировать из остатков склада
            </button>
            <button class="rom-btn rom-btn-primary" @click="saveFullTemplate" :disabled="tplSaving">
              {{ tplSaving ? 'Сохранение...' : 'Сохранить шаблон' }}
            </button>
          </div>
        </div>

        <!-- Filter -->
        <div class="rom-tpl-filter">
          <input v-model="tplFilter" type="text" placeholder="Фильтр по названию или артикулу..." class="rom-input rom-tpl-filter-input" />
          <button class="rom-btn" @click="showTplAddModal = true">+ Добавить товар</button>
        </div>

        <div v-if="tplMessage" class="rom-tpl-msg" :class="{ success: tplMessageOk }">{{ tplMessage }}</div>

        <!-- Template table -->
        <div class="rom-table-wrap">
          <table class="rom-table" v-if="filteredTemplateItems.length">
            <thead>
              <tr>
                <th style="width:40px">#</th>
                <th>Товар</th>
                <th style="width:80px">Кратность</th>
                <th style="width:50px"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(item, idx) in filteredTemplateItems" :key="item.sku">
                <td class="rom-td-num">{{ idx + 1 }}</td>
                <td><span class="rom-sku-label">{{ item.sku }}</span> {{ item.product_name }}</td>
                <td>
                  <input v-model.number="item.multiplicity" type="number" min="1" class="rom-tpl-mult-input" />
                </td>
                <td>
                  <button class="rom-btn-sm rom-btn-danger" @click="removeTplItemFull(item)">X</button>
                </td>
              </tr>
            </tbody>
          </table>
          <div v-else class="rom-empty">
            {{ tplFilter ? 'Ничего не найдено' : 'Шаблон пуст. Нажмите «Импортировать из сроков годности» или «+ Добавить товар».' }}
          </div>
        </div>
      </div>
    </template>

    <!-- ═══ TAB: Stock balances ═══ -->
    <template v-if="pageTab === 'stock'">
      <div class="rom-panel">
      <div class="rom-stock-toolbar">
        <div class="rom-stock-field">
          <label>Дата остатков:</label>
          <select v-model="stockBalanceDate" @change="loadStockData" class="rom-input">
            <option v-for="d in stockDates" :key="d" :value="d">{{ formatDate(d) }}</option>
          </select>
        </div>
        <div class="rom-stock-field">
          <label>На дату доставки:</label>
          <input type="date" v-model="stockDeliveryDate" @change="loadStockData" class="rom-input" />
        </div>
        <div class="rom-stock-field rom-stock-upload">
          <input type="file" ref="stockFileInput" accept=".xlsx,.xls" style="display:none" @change="handleStockFile" />
          <button class="rom-btn" @click="$refs.stockFileInput.click()" :disabled="stockUploading">
            {{ stockUploading ? 'Загрузка...' : 'Загрузить остатки из Excel' }}
          </button>
        </div>
      </div>

      <div v-if="stockMessage" class="rom-tpl-msg" :class="{ success: stockMessageOk }">{{ stockMessage }}</div>

      <div v-if="stockUnmatched.length" class="rom-unmatched">
        <div class="rom-unmatched-header" @click="stockUnmatchedExpanded = !stockUnmatchedExpanded">
          <span class="rom-unmatched-arrow">{{ stockUnmatchedExpanded ? '▼' : '▶' }}</span>
          <strong>Не найдено в справочнике: {{ stockUnmatched.length }}</strong>
          <span class="rom-unmatched-hint">— этих товаров нет в базе, добавьте их вручную</span>
          <button class="rom-unmatched-copy" @click.stop="copyUnmatched">Копировать</button>
        </div>
        <div v-if="stockUnmatchedExpanded" class="rom-unmatched-body">
          <table class="rom-unmatched-table">
            <thead>
              <tr>
                <th>Внешний код</th>
                <th>Артикул</th>
                <th>Название (из Excel)</th>
                <th>Кол-во</th>
                <th>Склад</th>
                <th>Юр. лицо</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(u, i) in stockUnmatched" :key="i">
                <td class="mono">{{ u.external_code }}</td>
                <td class="mono">{{ u.sku }}</td>
                <td>{{ u.name }}</td>
                <td class="num">{{ u.qty }}</td>
                <td>{{ u.warehouse }}</td>
                <td>{{ u.legal_entity }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="rom-tpl-tabs">
        <button class="rom-tpl-tab" :class="{ active: stockLegalEntity === stockLE_BK }"
          @click="stockLegalEntity = stockLE_BK; loadStockData()">Бургер БК</button>
        <button class="rom-tpl-tab" :class="{ active: stockLegalEntity === stockLE_VM }"
          @click="stockLegalEntity = stockLE_VM; loadStockData()">Воглия Матта</button>
        <button class="rom-tpl-tab" :class="{ active: stockLegalEntity === stockLE_PS }"
          @click="stockLegalEntity = stockLE_PS; loadStockData()">Пицца Стар</button>
      </div>

      <div class="rom-stock-filter-row">
        <input v-model="stockFilter" type="text" placeholder="Фильтр по названию или артикулу..." class="rom-input rom-tpl-filter-input" />
        <select v-model="stockSupplierFilter" class="rom-input" style="max-width:220px;">
          <option value="">Все поставщики</option>
          <option v-for="s in stockSuppliers" :key="s" :value="s">{{ s }}</option>
        </select>
        <label class="rom-stock-checkbox"><input type="checkbox" v-model="stockShowDeficit" /> Только дефицит</label>
        <span class="rom-stock-summary" v-if="stockItems.length">
          Всего: {{ stockItems.length }} · Дефицит: {{ stockItems.filter(i => i.remaining < 0).length }}
        </span>
      </div>

      <div class="rom-table-wrap" v-if="!stockLoading">
        <table class="rom-table" v-if="filteredStockItems.length">
          <thead>
            <tr>
              <th>Товар</th>
              <th>Поставщик</th>
              <th>Склад</th>
              <th class="rom-th-right">Остаток</th>
              <th class="rom-th-right">Заказано</th>
              <th class="rom-th-right">Останется</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(item, idx) in filteredStockItems" :key="item.sku + item.legal_entity"
              :class="{ 'rom-row-deficit': item.remaining < 0, 'rom-row-warning': item.warehouse_error }">
              <td><span class="rom-sku-label">{{ item.sku }}</span> {{ item.product_name }}</td>
              <td>{{ item.supplier || '—' }}</td>
              <td :title="item.warehouse_error_details || ''">
                <span v-if="item.warehouse_error" class="rom-stock-error">{{ item.warehouse }}</span>
                <span v-else>{{ item.warehouse }}</span>
              </td>
              <td class="rom-td-right">{{ item.stock_qty }}</td>
              <td class="rom-td-right">{{ item.ordered_qty || '' }}</td>
              <td class="rom-td-right rom-td-remaining" :class="{ 'rom-deficit': item.remaining < 0 }">
                {{ item.remaining }}
              </td>
            </tr>
          </tbody>
        </table>
        <div v-else class="rom-empty">
          {{ stockDates.length ? 'Нет данных. Выберите дату остатков и дату доставки.' : 'Остатки ещё не загружены. Нажмите «Загрузить остатки из Excel».' }}
        </div>
      </div>
      <div v-else class="rom-loading">Загрузка...</div>
      </div>
    </template>

    <!-- ═══ TAB: Audit log ═══ -->
    <template v-if="pageTab === 'audit'">
      <div class="rom-audit-wrap">
        <div class="rom-audit-filters">
          <label class="rom-audit-filter-label">Дата поставки:</label>
          <input type="date" v-model="auditFilters.dateFrom" @change="loadAuditLog" class="rom-input rom-input-sm" title="Дата поставки от" />
          <span>—</span>
          <input type="date" v-model="auditFilters.dateTo" @change="loadAuditLog" class="rom-input rom-input-sm" title="Дата поставки до" />
          <input type="text" v-model="auditFilters.restaurant" @input="debouncedAuditSearch" placeholder="№ рест." class="rom-input rom-input-sm" style="width:80px" />
          <input type="text" v-model="auditFilters.actor" @input="debouncedAuditSearch" placeholder="Кто изменил…" class="rom-input rom-input-sm" style="width:150px" />
          <select v-model="auditFilters.action" @change="loadAuditLog" class="rom-input rom-input-sm">
            <option value="">Все действия</option>
            <option value="order_created">Заказ создан</option>
            <option value="order_updated">Заказ обновлён</option>
            <option value="order_deleted">Заказ удалён</option>
            <option value="item_added">Позиция добавлена</option>
            <option value="item_changed">Количество изменено</option>
            <option value="item_deleted">Позиция удалена</option>
            <option value="status_changed">Смена статуса</option>
            <option value="delivery_date_changed">Смена даты</option>
          </select>
          <input type="text" v-model="auditFilters.search" @input="debouncedAuditSearch" placeholder="SKU или название товара…" class="rom-input rom-input-sm" style="flex:1;min-width:180px" />
          <button class="rom-btn rom-btn-sm" @click="loadAuditLog" :disabled="auditLoading">
            {{ auditLoading ? '…' : '⟳' }}
          </button>
          <button class="rom-btn rom-btn-sm" @click="resetAuditFilters">Сбросить</button>
          <label class="rom-audit-auto">
            <input type="checkbox" v-model="auditAutoRefresh" /> Авто
          </label>
        </div>

        <div class="rom-audit-stats">
          Всего событий: <strong>{{ auditTotal }}</strong>
          <span v-if="auditLastRefresh"> · Обновлено: {{ auditLastRefresh }}</span>
        </div>

        <div v-if="auditLoading && !auditEvents.length" class="rom-loading">Загрузка журнала…</div>
        <div v-else-if="!auditEvents.length" class="rom-audit-empty">Событий не найдено</div>
        <div v-else class="rom-audit-list">
          <div v-for="ev in auditEvents" :key="ev.id" class="rom-audit-row" :class="'act-' + ev.action">
            <div class="rom-audit-time">
              <div class="rom-audit-date">{{ fmtAuditDate(ev.created_at) }}</div>
              <div class="rom-audit-clock">{{ fmtAuditTime(ev.created_at) }}</div>
            </div>
            <div class="rom-audit-icon" :class="'ai-' + ev.action">{{ auditIcon(ev.action) }}</div>
            <div class="rom-audit-body">
              <div class="rom-audit-head">
                <span class="rom-audit-action">{{ auditActionLabel(ev.action) }}</span>
                <span class="rom-audit-actor" :class="'actor-' + ev.actor_type">{{ ev.actor_name || '—' }}</span>
                <span v-if="ev.restaurant_number" class="rom-audit-rest">Рест. {{ formatRestaurantNumber(ev.restaurant_number, ev.legal_entity_group) }}</span>
                <span v-if="ev.delivery_date" class="rom-audit-deliv">на {{ fmtAuditDate(ev.delivery_date) }}</span>
              </div>
              <div class="rom-audit-detail">
                <template v-if="ev.sku || ev.product_name">
                  <span class="rom-audit-sku">{{ ev.sku }}</span>
                  <span>{{ ev.product_name }}</span>
                  <template v-if="ev.action === 'item_changed'">
                    <span class="rom-audit-arrow">{{ fmtAuditNum(ev.old_value) }} → <b>{{ fmtAuditNum(ev.new_value) }}</b></span>
                  </template>
                  <template v-else-if="ev.action === 'item_deleted'">
                    <span class="rom-audit-del">было {{ fmtAuditNum(ev.old_value) }}</span>
                  </template>
                  <template v-else-if="ev.action === 'item_added'">
                    <span class="rom-audit-add">+{{ fmtAuditNum(ev.new_value) }}</span>
                  </template>
                </template>
                <template v-else-if="ev.action === 'status_changed' || ev.action === 'delivery_date_changed'">
                  <span>{{ ev.old_value || '—' }} → <b>{{ ev.new_value || '—' }}</b></span>
                </template>
                <template v-else-if="(ev.new_value || ev.old_value) && ev.action !== 'order_created'">
                  <span>{{ ev.new_value || ev.old_value }}</span>
                </template>
              </div>
              <!-- Старые записи order_updated: раскрытый diff из details -->
              <div v-if="auditDiffList(ev)" class="rom-audit-diff-list">
                <div v-for="(row, idx) in auditDiffList(ev)" :key="idx" class="rom-audit-diff-row" :class="'diff-' + row.kind">
                  <span class="rom-audit-diff-mark">
                    <template v-if="row.kind === 'added'">+</template>
                    <template v-else-if="row.kind === 'removed'">−</template>
                    <template v-else>↻</template>
                  </span>
                  <span class="rom-audit-sku">{{ row.sku }}</span>
                  <span>{{ row.name }}</span>
                  <template v-if="row.kind === 'changed'">
                    <span class="rom-audit-arrow">{{ fmtAuditNum(row.old) }} → <b>{{ fmtAuditNum(row.new) }}</b></span>
                  </template>
                  <template v-else-if="row.kind === 'added'">
                    <span class="rom-audit-add">+{{ fmtAuditNum(row.qty) }}</span>
                  </template>
                  <template v-else>
                    <span class="rom-audit-del">было {{ fmtAuditNum(row.qty) }}</span>
                  </template>
                </div>
              </div>
            </div>
            <button v-if="ev.order_id || ev.delivery_date" class="rom-audit-goto" @click="goToAuditOrder(ev)" title="Открыть заказ">→</button>
          </div>
        </div>

        <div v-if="auditEvents.length && auditEvents.length < auditTotal" class="rom-audit-more">
          <button class="rom-btn" @click="loadMoreAuditLog" :disabled="auditLoading">
            {{ auditLoading ? 'Загрузка…' : 'Показать ещё' }}
          </button>
          <span class="rom-audit-more-hint">Показано {{ auditEvents.length }} из {{ auditTotal }}</span>
        </div>
      </div>
    </template>

    <!-- Add product to template modal -->
    <div v-if="showTplAddModal" class="rom-modal-overlay" @click.self="closeTplAddModal">
      <div class="rom-modal">
        <div class="rom-modal-header">
          <h2>Добавить товар в шаблон</h2>
          <button class="rom-modal-close" @click="closeTplAddModal">X</button>
        </div>
        <div class="rom-modal-body">
          <input
            v-model="tplAddSearch"
            type="text"
            placeholder="Поиск по названию или артикулу..."
            class="rom-input"
            style="width:100%;margin-bottom:12px"
            @input="doTplAddSearch"
          />
          <div v-if="tplAddResults.length" style="max-height:400px;overflow-y:auto">
            <div v-for="p in tplAddResults" :key="p.sku" class="rom-tpl-add-row" @click="addToTemplate(p)">
              <span class="rom-td-sku-tpl">{{ p.sku }}</span>
              <span style="flex:1">{{ p.name }}</span>
              <span class="rom-add-cat">{{ p.category }}</span>
              <span v-if="p.multiplicity > 1" class="rom-mult-badge">x{{ p.multiplicity }}</span>
            </div>
          </div>
          <div v-else-if="tplAddSearch.length >= 2" class="rom-no-items">Ничего не найдено</div>
          <div v-else class="rom-no-items">Введите минимум 2 символа</div>
        </div>
      </div>
    </div>

    <!-- Order detail modal -->
    <div v-if="showOrderModal" class="rom-modal-overlay" @click.self="closeOrderModal">
      <div class="rom-modal rom-modal-lg rom-modal-fixed">
        <!-- Фиксированная шапка -->
        <div class="rom-modal-header">
          <h2>Заказ ресторана {{ formatRestaurantNumber(editingOrder?.restaurant_number, editingOrder?.legal_entity_group) }}</h2>
          <button class="rom-modal-close" @click="closeOrderModal">X</button>
        </div>

        <template v-if="editingOrder">
          <!-- Фиксированная мета-панель -->
          <div class="rom-order-bar">
            <div class="rom-order-meta">
              <span class="rom-meta-date">
                Доставка:
                <strong v-if="!editingDateMode" @click="editingDateMode = true" class="rom-date-editable" title="Нажмите, чтобы изменить дату">{{ formatDate(editingOrder.delivery_date) }} ✎</strong>
                <span v-else class="rom-date-edit">
                  <input type="date" v-model="editingNewDate" class="rom-input-date" />
                  <button class="rom-btn-sm rom-btn-primary" @click="changeDeliveryDate" :disabled="saving">OK</button>
                  <button class="rom-btn-sm" @click="editingDateMode = false">Отмена</button>
                </span>
              </span>
              <span>Статус: <strong>{{ statusLabel(editingOrder.status) }}</strong></span>
              <span v-if="editingOrder.updated_by" class="rom-meta-edited">
                Изменён: {{ formatDateTime(editingOrder.updated_at) }} ({{ editingOrder.updated_by }})
              </span>
            </div>
            <div class="rom-order-totals-bar">
              <span>Коробок: <strong>{{ orderTotals.boxes }}</strong></span>
              <span>Вес: <strong>{{ orderTotals.weight }} кг</strong></span>
              <span>Паллет: <strong>{{ orderTotals.pallets }}</strong></span>
              <span v-if="orderTotals.deposit">Залог: <strong>{{ orderTotals.deposit }}</strong></span>
            </div>
          </div>

          <!-- Табы режимов + поиск -->
          <div class="rom-cat-tabs">
            <button v-for="cat in ['Сухой', 'Холод', 'Мороз']" :key="cat"
              class="rom-cat-tab" :class="{ active: editCategory === cat }" @click="editCategory = cat">
              {{ cat }}
              <span v-if="getEditItems(cat).length" class="rom-cat-tab-count">{{ getEditItems(cat).length }}</span>
            </button>
            <input
              v-model="editSearch"
              type="text"
              placeholder="Поиск по артикулу или названию..."
              class="rom-input rom-edit-search"
            />
            <button v-if="editSearch" class="rom-btn-sm" @click="editSearch = ''" title="Очистить">✕</button>
          </div>

          <!-- Скроллируемое содержимое (активная категория) -->
          <div class="rom-modal-scroll">
            <table class="rom-table rom-table-edit" v-if="getDisplayEditItems(editCategory).length">
              <thead>
                <tr><th>Товар</th><th style="width:70px">Кол-во</th><th style="width:80px">Вес, кг</th><th style="width:90px" title="Сумма залога">Залог</th><th>Комментарий</th><th></th></tr>
              </thead>
              <tbody>
                <tr v-for="(item, idx) in getDisplayEditItems(editCategory)" :key="idx">
                  <td class="rom-edit-product" @click="openReplaceProduct(item)" title="Нажмите, чтобы заменить товар">
                    <span class="rom-edit-sku">{{ item.sku }}</span> {{ item.product_name }}
                  </td>
                  <td>
                    <input
                      v-model.number="item.quantity"
                      type="number"
                      min="0"
                      :step="parseFloat(item.multiplicity) > 1 ? item.multiplicity : 0.5"
                      class="rom-edit-qty"
                      :class="{ 'rom-edit-qty-warn': editItemHasMultError(item) }"
                    />
                    <div v-if="editItemHasMultError(item)" class="rom-edit-mult-hint">
                      Кратность {{ formatEditNumber(item.multiplicity) }}
                    </div>
                  </td>
                  <td class="rom-td-center rom-td-weight">{{ itemWeight(item) }}</td>
                  <td class="rom-td-center rom-td-weight">{{ itemDepositSum(item) }}</td>
                  <td><input v-model="item.comment" type="text" class="rom-edit-comment" /></td>
                  <td><button class="rom-btn-sm rom-btn-danger" @click="removeEditItem(item)">X</button></td>
                </tr>
              </tbody>
            </table>
            <div v-else-if="editSearch" class="rom-no-items">Ничего не найдено</div>
            <div v-else class="rom-no-items">Нет позиций</div>
            <button class="rom-btn-sm rom-btn-add-item" @click="openOrderAddProduct(editCategory)">+ Добавить товар</button>
            <div v-if="getEditItems(editCategory).length" class="rom-cat-summary">
              {{ catTotals(editCategory).boxes }} кор., {{ catTotals(editCategory).weight }} кг, {{ catTotals(editCategory).pallets }} палл.<span v-if="catTotals(editCategory).deposit">, залог {{ catTotals(editCategory).deposit }}</span>
            </div>
          </div>

          <div v-if="editHasMultErrors" class="rom-edit-warning-box">
            Предупреждение: количество не кратно шаблону. Сохранение разрешено. Например: {{ editMultiplicityErrorText }}
          </div>

          <!-- Фиксированный подвал с кнопками -->
          <div class="rom-modal-footer-fixed">
            <button class="rom-btn rom-btn-danger" @click="handleDeleteOrder(editingOrder)" :disabled="saving">
              Удалить заказ
            </button>
            <button class="rom-btn" @click="openOrderHistory(editingOrder)" title="История изменений этого заказа">
              История
            </button>
            <div style="flex:1"></div>
            <button class="rom-btn rom-btn-export" @click="exportSingleOrder(editingOrder)">
              Excel
            </button>
            <button class="rom-btn rom-btn-primary" @click="saveEditedOrder" :disabled="saving">
              {{ saving ? 'Сохранение...' : 'Сохранить изменения' }}
            </button>
          </div>
        </template>
      </div>
    </div>

    <!-- Order history modal -->
    <div v-if="showOrderHistoryModal" class="rom-modal-overlay" @click.self="showOrderHistoryModal = false">
      <div class="rom-modal rom-modal-lg">
        <div class="rom-modal-header">
          <h2>История заказа #{{ historyOrderId }}</h2>
          <button class="rom-modal-close" @click="showOrderHistoryModal = false">X</button>
        </div>
        <div class="rom-modal-body" style="max-height:70vh;overflow:auto">
          <div v-if="historyLoading" class="rom-loading">Загрузка…</div>
          <div v-else-if="!historyEvents.length" class="rom-no-items">Событий пока нет</div>
          <div v-else class="rom-audit-list">
            <div v-for="ev in historyEvents" :key="ev.id" class="rom-audit-row" :class="'act-' + ev.action">
              <div class="rom-audit-time">
                <div class="rom-audit-date">{{ fmtAuditDate(ev.created_at) }}</div>
                <div class="rom-audit-clock">{{ fmtAuditTime(ev.created_at) }}</div>
              </div>
              <div class="rom-audit-icon">{{ auditIcon(ev.action) }}</div>
              <div class="rom-audit-body">
                <div class="rom-audit-head">
                  <span class="rom-audit-action">{{ auditActionLabel(ev.action) }}</span>
                  <span class="rom-audit-actor" :class="'actor-' + ev.actor_type">{{ ev.actor_name || '—' }}</span>
                </div>
                <div class="rom-audit-detail">
                  <template v-if="ev.sku || ev.product_name">
                    <span class="rom-audit-sku">{{ ev.sku }}</span>
                    <span>{{ ev.product_name }}</span>
                    <template v-if="ev.action === 'item_changed'">
                      <span class="rom-audit-arrow">{{ fmtAuditNum(ev.old_value) }} → <b>{{ fmtAuditNum(ev.new_value) }}</b></span>
                    </template>
                    <template v-else-if="ev.action === 'item_deleted'">
                      <span class="rom-audit-del">было {{ fmtAuditNum(ev.old_value) }}</span>
                    </template>
                    <template v-else-if="ev.action === 'item_added'">
                      <span class="rom-audit-add">+{{ fmtAuditNum(ev.new_value) }}</span>
                    </template>
                  </template>
                  <template v-else-if="(ev.new_value || ev.old_value) && ev.action !== 'order_created'">
                    <span>{{ ev.old_value || '—' }} → <b>{{ ev.new_value || '—' }}</b></span>
                  </template>
                </div>
                <div v-if="auditDiffList(ev)" class="rom-audit-diff-list">
                  <div v-for="(row, idx) in auditDiffList(ev)" :key="idx" class="rom-audit-diff-row" :class="'diff-' + row.kind">
                    <span class="rom-audit-diff-mark">
                      <template v-if="row.kind === 'added'">+</template>
                      <template v-else-if="row.kind === 'removed'">−</template>
                      <template v-else>↻</template>
                    </span>
                    <span class="rom-audit-sku">{{ row.sku }}</span>
                    <span>{{ row.name }}</span>
                    <template v-if="row.kind === 'changed'">
                      <span class="rom-audit-arrow">{{ fmtAuditNum(row.old) }} → <b>{{ fmtAuditNum(row.new) }}</b></span>
                    </template>
                    <template v-else-if="row.kind === 'added'">
                      <span class="rom-audit-add">+{{ fmtAuditNum(row.qty) }}</span>
                    </template>
                    <template v-else>
                      <span class="rom-audit-del">было {{ fmtAuditNum(row.qty) }}</span>
                    </template>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Order add/replace product modal -->
    <div v-if="showOrderAddModal" class="rom-modal-overlay" @click.self="showOrderAddModal = false">
      <div class="rom-modal">
        <div class="rom-modal-header">
          <h2>{{ replacingItem ? 'Заменить товар' : 'Добавить товар' }}</h2>
          <button class="rom-modal-close" @click="showOrderAddModal = false">X</button>
        </div>
        <div class="rom-modal-body">
          <input
            v-model="orderAddSearch"
            type="text"
            placeholder="Поиск по названию или артикулу..."
            class="rom-input"
            style="width:100%;margin-bottom:12px"
            @input="doOrderAddSearch"
          />
          <div v-if="orderAddResults.length" style="max-height:400px;overflow-y:auto">
            <div v-for="p in orderAddResults" :key="p.sku" class="rom-tpl-add-row" @click="pickOrderProduct(p)">
              <span class="rom-td-sku-tpl">{{ p.sku }}</span>
              <span style="flex:1">{{ p.name }}</span>
              <span class="rom-add-cat">{{ p.category }}</span>
            </div>
          </div>
          <div v-else-if="orderAddSearch.length >= 2" class="rom-no-items">Ничего не найдено</div>
          <div v-else class="rom-no-items">Введите минимум 2 символа</div>
        </div>
      </div>
    </div>

    <!-- Users modal -->
    <div v-if="showUsersModal" class="rom-modal-overlay" @click.self="showUsersModal = false">
      <div class="rom-modal" style="max-width:820px">
        <div class="rom-modal-header">
          <h2>Учётные записи ресторанов</h2>
          <button class="rom-modal-close" @click="showUsersModal = false">&times;</button>
        </div>
        <div class="rom-modal-body">
          <div class="rom-users-hint" style="margin-bottom:10px">
            Список берётся из справочника ресторанов. Каждый активный ресторан = потенциальный пользователь.
            Чтобы ресторан смог войти, нужно задать ему пароль.
          </div>

          <!-- Сводка -->
          <div class="rom-users-summary">
            <div class="rom-users-summary-item">
              <span class="rom-users-summary-num">{{ usersList.length }}</span>
              <span class="rom-users-summary-label">всего</span>
            </div>
            <div class="rom-users-summary-item ok">
              <span class="rom-users-summary-num">{{ usersWithPasswordCount }}</span>
              <span class="rom-users-summary-label">с паролем</span>
            </div>
            <div class="rom-users-summary-item warn">
              <span class="rom-users-summary-num">{{ usersWithoutPasswordCount }}</span>
              <span class="rom-users-summary-label">без пароля</span>
            </div>
            <div class="rom-users-summary-item off">
              <span class="rom-users-summary-num">{{ usersDisabledCount }}</span>
              <span class="rom-users-summary-label">отключено</span>
            </div>
          </div>

          <!-- Массовая выдача пароля -->
          <div class="rom-users-section">
            <div class="rom-users-section-title">Массовая выдача пароля</div>
            <div class="rom-bulk-row">
              <input v-model="bulkPassword" type="text" placeholder="Пароль" class="rom-input" />
              <select v-model="bulkMode" class="rom-select">
                <option value="missing">Только тем, у кого нет пароля</option>
                <option value="all">Всем (затереть существующие)</option>
              </select>
              <button class="rom-btn rom-btn-primary" @click="handleBulkCreate" :disabled="!bulkPassword || usersBusy">
                Применить
              </button>
            </div>
            <div class="rom-users-info" v-if="usersCount !== null">
              Обновлено учёток: {{ usersCount }}
            </div>
          </div>

          <!-- Список -->
          <div class="rom-users-section">
            <div class="rom-users-section-title">
              Рестораны
              <button class="rom-btn-sm" style="margin-left:8px" @click="reloadUsers" :disabled="usersBusy">Обновить</button>
            </div>
            <div class="rom-users-filters">
              <input v-model="usersFilter" type="text" placeholder="Поиск по номеру, городу или адресу" class="rom-input" />
              <select v-model="usersFilterStatus" class="rom-select">
                <option value="">Все статусы</option>
                <option value="ready">С паролем, активные</option>
                <option value="nopwd">Без пароля</option>
                <option value="disabled">Отключённые</option>
              </select>
            </div>

            <div v-if="usersLoading" class="rom-no-items">Загрузка...</div>
            <div v-else-if="!filteredUsers.length" class="rom-no-items">Ничего не найдено</div>
            <div v-else class="rom-users-list">
              <div v-for="u in filteredUsers" :key="(u.legal_entity_group || 'BK_VM') + '-' + u.restaurant_number" class="rom-user-row">
                <span class="rom-user-num">№{{ formatRestaurantNumber(u.restaurant_number, u.legal_entity_group) }}</span>
                <span class="rom-user-addr">
                  <span class="rom-user-addr-main">{{ u.city || '—' }} {{ u.address || '' }}</span>
                  <span class="rom-user-addr-le">{{ shortLegalEntity(u.legal_entity) }}</span>
                </span>
                <span class="rom-user-pwd-status" :class="userStatusClass(u)">
                  {{ userStatusLabel(u) }}
                </span>
                <span class="rom-user-login">
                  {{ u.last_login_at ? 'Вход: ' + formatTime(u.last_login_at) : '—' }}
                </span>
                <button class="rom-btn-sm" @click="handleSetPassword(u)" :disabled="usersBusy" :title="u.has_password ? 'Сменить пароль' : 'Задать пароль'">
                  {{ u.has_password ? 'Сменить пароль' : 'Задать пароль' }}
                </button>
                <button v-if="u.has_password" class="rom-btn-sm" :class="u.is_active ? 'rom-btn-danger' : 'rom-btn-success'" @click="handleToggleUser(u)" :disabled="usersBusy">
                  {{ u.is_active ? 'Отключить' : 'Включить' }}
                </button>
                <span v-else class="rom-user-toggle-placeholder"></span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Old templates modal removed -->

    <!-- Deadline extend modal -->
    <div v-if="showDeadlineModal" class="rom-modal-overlay" @click.self="showDeadlineModal = false">
      <div class="rom-modal" style="max-width:380px">
        <div class="rom-modal-header">
          <h2>Продлить дедлайн</h2>
          <button class="rom-modal-close" @click="showDeadlineModal = false">&times;</button>
        </div>
        <div class="rom-modal-body">
          <p style="font-size:13px; color:#8b7355; margin-bottom:14px">
            Дата: <strong>{{ formatDate(selectedDate) }}</strong>
          </p>
          <div class="rom-deadline-fields">
            <label class="rom-deadline-label">
              <span>Мягкий дедлайн (предупреждение)</span>
              <input type="time" v-model="deadlineSoft" class="rom-input" />
            </label>
            <label class="rom-deadline-label">
              <span>Жёсткий дедлайн (закрытие)</span>
              <input type="time" v-model="deadlineHard" class="rom-input" />
            </label>
          </div>
          <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:16px">
            <button class="rom-btn" @click="showDeadlineModal = false">Отмена</button>
            <button class="rom-btn rom-btn-primary" @click="saveDeadlineExtend" :disabled="deadlineSaving">
              {{ deadlineSaving ? 'Сохранение...' : 'Продлить' }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Unified export modal -->
    <div v-if="showExportModal" class="rom-modal-overlay" @click.self="closeExportModal">
      <div class="rom-modal rom-exp-modal">
        <div class="rom-modal-header">
          <h2>Выгрузка в Excel</h2>
          <button class="rom-modal-close" @click="closeExportModal">&times;</button>
        </div>
        <div class="rom-modal-body rom-exp-body">
          <div v-if="exportLoading" style="text-align:center; padding:40px"><div class="rom-spinner"></div></div>
          <template v-else>
            <!-- Левая колонка: настройки -->
            <div class="rom-exp-col rom-exp-col-left">
              <!-- Группировка -->
              <div class="rom-exp-block">
                <div class="rom-exp-block-title">Как разделить на листы</div>
                <div class="rom-exp-grouping">
                  <button class="rom-exp-grouping-opt" :class="{ active: exportGrouping === 'list' }" @click="exportGrouping = 'list'">
                    <div class="rom-exp-grouping-name">Один общий + по ресторанам</div>
                    <div class="rom-exp-grouping-hint">Главный лист + лист на каждый ресторан</div>
                  </button>
                  <button class="rom-exp-grouping-opt" :class="{ active: exportGrouping === 'restaurants' }" @click="exportGrouping = 'restaurants'">
                    <div class="rom-exp-grouping-name">Только по ресторанам</div>
                    <div class="rom-exp-grouping-hint">Один лист на ресторан, без сводного</div>
                  </button>
                  <button class="rom-exp-grouping-opt" :class="{ active: exportGrouping === 'categories' }" @click="exportGrouping = 'categories'">
                    <div class="rom-exp-grouping-name">По категориям хранения</div>
                    <div class="rom-exp-grouping-hint">Сухой / Холод / Мороз — каждый лист отдельно</div>
                  </button>
                </div>
              </div>

              <!-- Итоги -->
              <div class="rom-exp-block">
                <label class="rom-exp-cb-label">
                  <input type="checkbox" v-model="exportShowTotals" />
                  <span><strong>Итоги по ресторану</strong> (вес и паллеты в первой строке)</span>
                </label>
              </div>

              <!-- Единица веса -->
              <div class="rom-exp-block">
                <div class="rom-exp-block-title">Единица веса</div>
                <div class="rom-exp-grouping">
                  <button class="rom-exp-grouping-opt" :class="{ active: exportWeightUnit === 'g' }" @click="exportWeightUnit = 'g'">Граммы</button>
                  <button class="rom-exp-grouping-opt" :class="{ active: exportWeightUnit === 'kg' }" @click="exportWeightUnit = 'kg'">Килограммы</button>
                  <button class="rom-exp-grouping-opt" :class="{ active: exportWeightUnit === 't' }" @click="exportWeightUnit = 't'">Тонны</button>
                </div>
              </div>

              <!-- Фильтры -->
              <div class="rom-exp-block">
                <div class="rom-exp-block-title rom-exp-clickable" @click="exportShowFilters = !exportShowFilters">
                  <span>Фильтры данных</span>
                  <span class="rom-exp-chevron" :class="{ open: exportShowFilters }">▾</span>
                </div>
                <div v-show="exportShowFilters" class="rom-exp-filters">
                  <div class="rom-exp-filter-group">
                    <div class="rom-exp-filter-label">Категория</div>
                    <div class="rom-exp-checkboxes">
                      <label v-for="cat in ['Сухой','Холод','Мороз']" :key="cat" class="rom-exp-cb-label">
                        <input type="checkbox" :checked="exportFilterCategories.has(cat)" @change="toggleExportFilter('categories', cat)" /> {{ cat }}
                      </label>
                    </div>
                  </div>
                  <div class="rom-exp-filter-group">
                    <div class="rom-exp-filter-label">Регион</div>
                    <div class="rom-exp-checkboxes">
                      <label v-for="reg in ['Минск','Регионы']" :key="reg" class="rom-exp-cb-label">
                        <input type="checkbox" :checked="exportFilterRegions.has(reg)" @change="toggleExportFilter('regions', reg)" /> {{ reg }}
                      </label>
                    </div>
                  </div>
                  <div class="rom-exp-filter-group">
                    <div class="rom-exp-filter-label">Рестораны</div>
                    <label class="rom-exp-cb-label" style="margin-bottom:6px">
                      <input type="checkbox" v-model="exportAllRestaurants" /> Все
                    </label>
                    <template v-if="!exportAllRestaurants">
                      <input v-model="exportRestaurantSearch" type="text" placeholder="Поиск ресторана..." class="rom-input" style="width:100%; margin-bottom:6px" />
                      <div class="rom-exp-select-list">
                        <label v-for="r in filteredExportRestaurants" :key="r.number"
                          class="rom-exp-select-item" :class="{ selected: exportFilterRestaurants.has(r.number) }">
                          <input type="checkbox" :checked="exportFilterRestaurants.has(r.number)" @change="toggleExportFilter('restaurants', r.number)" />
                          <span style="font-weight:700; min-width:30px">{{ r.number }}</span>
                          <span style="flex:1; color:#502314">{{ r.city }}{{ r.address ? ', ' + r.address : '' }}</span>
                          <span style="font-size:10px; color:#8b7355">{{ r.region }}</span>
                        </label>
                        <div v-if="!filteredExportRestaurants.length" class="rom-no-items">Ничего не найдено</div>
                      </div>
                    </template>
                  </div>
                  <div class="rom-exp-filter-group">
                    <div class="rom-exp-filter-label">Товары</div>
                    <label class="rom-exp-cb-label" style="margin-bottom:6px">
                      <input type="checkbox" v-model="exportAllProducts" /> Все
                    </label>
                    <template v-if="!exportAllProducts">
                      <div style="display:flex; gap:8px; margin-bottom:6px; align-items:center; flex-wrap:wrap">
                        <input v-model="exportProductSearch" type="text" placeholder="Поиск товара..." class="rom-input" style="flex:1; min-width:150px" />
                        <button class="rom-btn-sm" @click="expProductsSelectAll">Все</button>
                        <button class="rom-btn-sm" @click="exportFilterProducts = new Set()">Сброс</button>
                        <span style="font-size:12px; color:#8b7355">{{ exportFilterProducts.size }} из {{ exportAvailableProducts.length }}</span>
                      </div>
                      <div style="display:flex; gap:6px; margin-bottom:6px">
                        <button v-for="cat in ['Сухой','Холод','Мороз']" :key="cat" class="rom-btn-sm"
                          :style="exportProductCatFilter === cat ? 'background:#502314; color:white' : ''"
                          @click="exportProductCatFilter = exportProductCatFilter === cat ? '' : cat">{{ cat }}</button>
                      </div>
                      <div class="rom-exp-select-list rom-exp-select-list-tall">
                        <label v-for="p in filteredExportProducts" :key="p.sku"
                          class="rom-exp-select-item" :class="{ selected: exportFilterProducts.has(p.sku) }">
                          <input type="checkbox" :checked="exportFilterProducts.has(p.sku)" @change="toggleExportFilter('products', p.sku)" />
                          <span style="font-size:10px; color:#8b7355; min-width:45px">{{ p.sku }}</span>
                          <span style="flex:1; color:#502314">{{ p.product_name }}</span>
                          <span style="font-size:10px; padding:1px 5px; border-radius:4px; font-weight:600"
                            :style="p.category === 'Мороз' ? 'background:#ede9fe; color:#7c3aed' : p.category === 'Холод' ? 'background:#eff6ff; color:#2563eb' : 'background:#fef3c7; color:#92400e'">{{ p.category }}</span>
                        </label>
                        <div v-if="!filteredExportProducts.length" class="rom-no-items">Ничего не найдено</div>
                      </div>
                    </template>
                  </div>
                </div>
              </div>
            </div>

            <!-- Правая колонка: выбор колонок -->
            <div class="rom-exp-col rom-exp-col-right">
              <div class="rom-exp-block">
                <div class="rom-exp-block-title">Колонки в Excel</div>
                <div class="rom-exp-presets">
                  <button v-for="(p, k) in EXPORT_COLUMN_PRESETS" :key="k" class="rom-exp-preset-btn" @click="applyExportPreset(k)" :title="p.label">
                    {{ p.label }}
                  </button>
                </div>
                <div class="rom-exp-cols-hint">
                  Выбрано: <strong>{{ exportSelectedColumns.length }}</strong> из {{ EXPORT_COLUMNS_DEF.length }}.
                  Стрелки ↑↓ меняют порядок столбцов в файле.
                </div>
                <div class="rom-exp-cols-list">
                  <div v-for="col in EXPORT_COLUMNS_DEF" :key="col.key"
                       class="rom-exp-col-row"
                       :class="{ selected: exportSelectedColumns.includes(col.key) }">
                    <label class="rom-exp-col-cb">
                      <input type="checkbox" :checked="exportSelectedColumns.includes(col.key)" @change="toggleExportColumn(col.key)" />
                      <span class="rom-exp-col-name">{{ col.label }}</span>
                      <span v-if="exportSelectedColumns.includes(col.key)" class="rom-exp-col-pos">
                        {{ exportSelectedColumns.indexOf(col.key) + 1 }}
                      </span>
                    </label>
                    <div v-if="exportSelectedColumns.includes(col.key)" class="rom-exp-col-arrows">
                      <button class="rom-exp-arrow" @click="moveExportColumn(col.key, -1)" :disabled="exportSelectedColumns.indexOf(col.key) === 0" title="Выше">↑</button>
                      <button class="rom-exp-arrow" @click="moveExportColumn(col.key, 1)" :disabled="exportSelectedColumns.indexOf(col.key) === exportSelectedColumns.length - 1" title="Ниже">↓</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </template>
        </div>
        <div v-if="!exportLoading" class="rom-exp-footer">
          <span class="rom-exp-summary">
            Заказов: <strong>{{ exportSummary.orders }}</strong> ·
            Позиций: <strong>{{ exportSummary.items }}</strong> ·
            Колонок: <strong>{{ exportSelectedColumns.length }}</strong>
          </span>
          <button class="rom-btn rom-btn-export" @click="doUnifiedExport" :disabled="!exportSummary.items || !exportSelectedColumns.length || exportExporting">
            {{ exportExporting ? 'Выгрузка...' : 'Скачать Excel' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, onUnmounted, computed, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useRestaurantOrderStore } from '@/stores/restaurantOrderStore.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { formatDate, formatTime, formatDateTime, statusLabel, EXCEL_HEADER_STYLE, EXCEL_SUBTOTAL_STYLE, EXCEL_TOTAL_STYLE, EXCEL_TRACEABLE_STYLE } from '@/lib/roUtils.js';
import { formatRestaurantNumber } from '@/lib/legalEntities.js';
import * as XLSX from 'xlsx-js-style';

const store = useRestaurantOrderStore();
const orderStore = useOrderStore();
const toast = useToastStore();

const loading = ref(true);
const session = ref(null);
const selectedDate = ref('');
const restaurants = ref([]);
const stats = ref({ total: 0, submitted: 0, pending: 0 });
const deadlineStatus = ref(null);

// Order editing
const showOrderModal = ref(false);
const editingOrder = ref(null);
const editItems = ref([]);
const originalEditItems = ref(null); // snapshot for dirty check
const saving = ref(false);
const editingDateMode = ref(false);
const editingNewDate = ref('');
const editCategory = ref('Сухой');
const editSearch = ref('');

// Page state (orders tab)
const moreMenuOpen = ref(false);
const restFilter = ref('');
const restStatusFilter = ref('');

const filteredRestaurants = computed(() => {
  const q = restFilter.value.trim().toLowerCase();
  const st = restStatusFilter.value;
  return restaurants.value.filter(r => {
    if (q) {
      const hay = (String(r.number) + ' ' + (r.city || '') + ' ' + (r.address || '')).toLowerCase();
      if (!hay.includes(q)) return false;
    }
    if (st === 'submitted' && !r.order_status) return false;
    if (st === 'pending' && r.order_status) return false;
    return true;
  });
});

// Click-outside директива для меню «···»
const vClickOutside = {
  beforeMount(el, binding) {
    el._clickOutside = (e) => { if (!el.contains(e.target)) binding.value(e); };
    document.addEventListener('mousedown', el._clickOutside);
  },
  unmounted(el) {
    document.removeEventListener('mousedown', el._clickOutside);
  },
};

// Users
const showUsersModal = ref(false);
const bulkPassword = ref('');
const bulkMode = ref('missing');
const usersCount = ref(null);
const usersList = ref([]);
const usersLoading = ref(false);
const usersBusy = ref(false);
const usersFilter = ref('');
const usersFilterStatus = ref('');

const usersWithPasswordCount = computed(() => usersList.value.filter(u => u.has_password).length);
const usersWithoutPasswordCount = computed(() => usersList.value.filter(u => !u.has_password).length);
const usersDisabledCount = computed(() => usersList.value.filter(u => u.has_password && !u.is_active).length);

const filteredUsers = computed(() => {
  const q = usersFilter.value.trim().toLowerCase();
  const st = usersFilterStatus.value;
  return usersList.value.filter(u => {
    if (q) {
      const num = String(u.restaurant_number || '');
      // Ищем и по числу в БД, и по красивому имени вида 'PS01'
      const pretty = formatRestaurantNumber(u.restaurant_number, u.legal_entity_group).toLowerCase();
      const addr = ((u.city || '') + ' ' + (u.address || '')).toLowerCase();
      if (!num.includes(q) && !pretty.includes(q) && !addr.includes(q)) return false;
    }
    if (st === 'ready' && !(u.has_password && u.is_active)) return false;
    if (st === 'nopwd' && u.has_password) return false;
    if (st === 'disabled' && !(u.has_password && !u.is_active)) return false;
    return true;
  });
});

function userStatusClass(u) {
  if (!u.has_password) return 'nopwd';
  if (!u.is_active) return 'off';
  return 'ok';
}
function userStatusLabel(u) {
  if (!u.has_password) return 'Без пароля';
  if (!u.is_active) return 'Отключён';
  return 'Активен';
}
function shortLegalEntity(le) {
  if (!le) return '';
  if (le.includes('Воглия')) return 'Воглия Матта';
  if (le.includes('Бургер')) return 'Бургер БК';
  if (le.includes('Пицца')) return 'Пицца Стар';
  return le;
}

// Auto-refresh
let refreshInterval = null;

// Page tabs
const pageTab = ref('orders');

// ═══ История одного заказа (модалка) ═══
const showOrderHistoryModal = ref(false);
const historyOrderId = ref(null);
const historyEvents = ref([]);
const historyLoading = ref(false);

async function openOrderHistory(order) {
  if (!order?.id) return;
  historyOrderId.value = order.id;
  historyEvents.value = [];
  historyLoading.value = true;
  showOrderHistoryModal.value = true;
  try {
    historyEvents.value = await store.adminGetOrderHistory(order.id);
  } catch (e) {
    alert('Не удалось загрузить историю: ' + (e.message || ''));
  } finally {
    historyLoading.value = false;
  }
}

// ═══ Audit log (Журнал) ═══
const auditEvents = ref([]);
const auditTotal = ref(0);
const auditLoading = ref(false);
const auditLastRefresh = ref('');
const auditExpanded = reactive({});
const auditAutoRefresh = ref(false);
let auditRefreshTimer = null;
let auditSearchDebounce = null;
const auditFilters = reactive({
  dateFrom: '',
  dateTo: '',
  restaurant: '',
  actor: '',
  action: '',
  search: '',
});
const AUDIT_PAGE_SIZE = 200;
let auditOffset = 0;

async function loadAuditLog(append = false) {
  // append === true передаётся только из loadMoreAuditLog. При вызове из @change/@click
  // сюда прилетает Event-объект — его трактуем как обычную (полную) загрузку.
  const isAppend = append === true;
  if (!isAppend) {
    auditOffset = 0;
    auditEvents.value = [];
  }
  auditLoading.value = true;
  try {
    const data = await store.adminGetAuditLog({
      dateFrom: auditFilters.dateFrom || undefined,
      dateTo: auditFilters.dateTo || undefined,
      restaurant: auditFilters.restaurant || undefined,
      actor: auditFilters.actor || undefined,
      action: auditFilters.action || undefined,
      search: auditFilters.search || undefined,
      legalEntity: orderStore.settings.legalEntity || undefined,
      limit: AUDIT_PAGE_SIZE,
      offset: auditOffset,
    });
    if (isAppend) auditEvents.value.push(...(data.events || []));
    else auditEvents.value = data.events || [];
    auditTotal.value = data.total || 0;
    auditLastRefresh.value = new Date().toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  } catch (e) {
    console.error('audit load failed', e);
  } finally {
    auditLoading.value = false;
  }
}

async function loadMoreAuditLog() {
  auditOffset += AUDIT_PAGE_SIZE;
  await loadAuditLog(true);
}

function debouncedAuditSearch() {
  clearTimeout(auditSearchDebounce);
  auditSearchDebounce = setTimeout(() => loadAuditLog(), 300);
}

function resetAuditFilters() {
  auditFilters.dateFrom = '';
  auditFilters.dateTo = '';
  auditFilters.restaurant = '';
  auditFilters.actor = '';
  auditFilters.action = '';
  auditFilters.search = '';
  loadAuditLog();
}

function toggleAuditDetails(id) {
  auditExpanded[id] = !auditExpanded[id];
}

async function goToAuditOrder(ev) {
  // Закрываем модалку истории (если была открыта из редактора), переключаемся на вкладку заказов
  showOrderHistoryModal.value = false;
  pageTab.value = 'orders';
  if (ev?.delivery_date) {
    selectedDate.value = ev.delivery_date;
    await loadStatus();
  }
  // Открываем сам заказ, если он ещё существует
  if (ev?.order_id) {
    try {
      await viewOrder(ev.order_id);
    } catch (e) {
      // заказ мог быть удалён — остаёмся на списке
    }
  }
}

function auditIcon(action) {
  const map = {
    order_created: '✅',
    order_updated: '✏️',
    order_deleted: '❌',
    item_added: '➕',
    item_changed: '🔄',
    item_deleted: '➖',
    status_changed: '🏷',
    delivery_date_changed: '📅',
  };
  return map[action] || '•';
}

function auditActionLabel(action) {
  const map = {
    order_created: 'Заказ создан',
    order_updated: 'Заказ обновлён',
    order_deleted: 'Заказ удалён',
    item_added: 'Позиция добавлена',
    item_changed: 'Кол-во изменено',
    item_deleted: 'Позиция удалена',
    status_changed: 'Смена статуса',
    delivery_date_changed: 'Смена даты доставки',
  };
  return map[action] || action;
}

function fmtAuditDate(s) {
  if (!s) return '';
  // s либо 'YYYY-MM-DD', либо 'YYYY-MM-DD HH:MM:SS'
  const [d] = s.split(' ');
  const [y, m, dd] = d.split('-');
  return `${dd}.${m}`;
}
function fmtAuditTime(s) {
  if (!s || !s.includes(' ')) return '';
  return s.split(' ')[1].substring(0, 5);
}
function fmtAuditNum(v) {
  if (v === null || v === undefined || v === '') return '—';
  const n = parseFloat(v);
  if (isNaN(n)) return v;
  return n % 1 === 0 ? n.toFixed(0) : n.toString();
}
function fmtAuditDetails(d) {
  if (!d) return '';
  try {
    const parsed = typeof d === 'string' ? JSON.parse(d) : d;
    return JSON.stringify(parsed, null, 2);
  } catch {
    return String(d);
  }
}

// Парсим details и вытаскиваем читаемый diff для старых записей order_updated
function parseAuditDetails(d) {
  if (!d) return null;
  try {
    return typeof d === 'string' ? JSON.parse(d) : d;
  } catch {
    return null;
  }
}

function auditDiffList(ev) {
  // При создании заказа список позиций не показываем — только метку «Заказ создан»
  if (ev.action === 'order_created') return null;
  const parsed = parseAuditDetails(ev.details);
  if (!parsed || !parsed.diff) return null;
  const diff = parsed.diff;
  const rows = [];
  (diff.added || []).forEach(x => rows.push({ kind: 'added', sku: x.sku, name: x.name, qty: x.qty }));
  (diff.changed || []).forEach(x => rows.push({ kind: 'changed', sku: x.sku, name: x.name, old: x.old, new: x.new }));
  (diff.removed || []).forEach(x => rows.push({ kind: 'removed', sku: x.sku, name: x.name, qty: x.qty }));
  return rows.length ? rows : null;
}

// Автообновление каждые 30 сек, пока вкладка открыта и включён чекбокс
watch([pageTab, auditAutoRefresh], ([tab, auto]) => {
  clearInterval(auditRefreshTimer);
  if (tab === 'audit' && auto) {
    auditRefreshTimer = setInterval(() => {
      // Сбрасываем offset и перезагружаем с начала
      loadAuditLog();
    }, 30000);
  }
});

// Templates (full page)
const tplCategory = ref('Сухой');
const tplLegalEntity = computed(() => orderStore.settings.legalEntity || 'ООО "Бургер БК"');
const fullTemplateItems = ref([]);
const tplFilter = ref('');
const tplMessage = ref('');
const tplMessageOk = ref(false);
const tplSaving = ref(false);
const showTplAddModal = ref(false);
const tplAddSearch = ref('');
const tplAddResults = ref([]);
const tplAddTimer = ref(null);

// Stock balances
const stockBalanceDate = ref('');
const stockDeliveryDate = ref('');
const stockLE_BK = 'ООО "Бургер БК"';
const stockLE_VM = 'ООО "Воглия Матта"';
const stockLE_PS = 'ООО "Пицца Стар"';
const stockLegalEntity = ref(orderStore.settings.legalEntity || stockLE_BK);
const stockDates = ref([]);
const stockItems = ref([]);
const stockFilter = ref('');
const stockSupplierFilter = ref('');
const stockShowDeficit = ref(false);

const stockSuppliers = computed(() => {
  const set = new Set();
  for (const it of stockItems.value) {
    if (it.supplier) set.add(it.supplier);
  }
  return Array.from(set).sort((a, b) => a.localeCompare(b, 'ru'));
});
const stockUploading = ref(false);
const stockLoading = ref(false);
const stockMessage = ref('');
const stockMessageOk = ref(false);
const stockFileInput = ref(null);
const stockUnmatched = ref([]); // список товаров из Excel, которые не нашлись в БД
const stockUnmatchedExpanded = ref(true);

const filteredStockItems = computed(() => {
  let items = stockItems.value;
  if (stockShowDeficit.value) items = items.filter(i => i.remaining < 0);
  if (stockSupplierFilter.value) {
    items = items.filter(i => i.supplier === stockSupplierFilter.value);
  }
  if (stockFilter.value) {
    const q = stockFilter.value.toLowerCase();
    items = items.filter(i => i.product_name.toLowerCase().includes(q) || i.sku.includes(q));
  }
  return items;
});

async function initStockTab() {
  if (!stockDates.value.length) {
    stockDates.value = await store.adminGetStockDates();
    if (stockDates.value.length) stockBalanceDate.value = stockDates.value[0];
  }
  if (!stockDeliveryDate.value) stockDeliveryDate.value = selectedDate.value;
  await loadStockData();
}

async function loadStockData() {
  if (!stockBalanceDate.value || !stockDeliveryDate.value) return;
  stockLoading.value = true;
  try {
    const data = await store.adminGetStockBalances(stockBalanceDate.value, stockDeliveryDate.value, stockLegalEntity.value);
    stockItems.value = data.items || [];
  } catch (e) {
    toast.error('Ошибка загрузки остатков');
  } finally {
    stockLoading.value = false;
  }
}

function copyUnmatched() {
  if (!stockUnmatched.value.length) return;
  const lines = ['Внешний код\tАртикул\tНазвание\tКол-во\tСклад\tЮр. лицо'];
  stockUnmatched.value.forEach(u => {
    lines.push([u.external_code, u.sku, u.name, u.qty, u.warehouse, u.legal_entity].join('\t'));
  });
  navigator.clipboard.writeText(lines.join('\n')).then(() => {
    toast.success('Список скопирован');
  }).catch(() => {
    toast.error('Не удалось скопировать');
  });
}

async function handleStockFile(event) {
  const file = event.target.files[0];
  if (!file) return;
  const dateStr = prompt('Дата остатков (ГГГГ-ММ-ДД):', new Date().toISOString().slice(0, 10));
  if (!dateStr) { event.target.value = ''; return; }
  stockUploading.value = true;
  stockMessage.value = '';
  stockUnmatched.value = [];
  try {
    const result = await store.adminUploadStock(file, dateStr);
    stockMessage.value = `Загружено: ${result.matched} позиций, пропущено: ${result.skipped}`;
    stockMessageOk.value = true;
    stockUnmatched.value = result.unmatched || [];
    stockUnmatchedExpanded.value = true;
    stockDates.value = await store.adminGetStockDates();
    stockBalanceDate.value = dateStr;
    if (!stockDeliveryDate.value) stockDeliveryDate.value = selectedDate.value;
    await loadStockData();
  } catch (e) {
    stockMessage.value = e.message || 'Ошибка загрузки';
    stockMessageOk.value = false;
  } finally {
    stockUploading.value = false;
    event.target.value = '';
  }
}

// Order item add/replace
const showOrderAddModal = ref(false);
const orderAddSearch = ref('');
const orderAddResults = ref([]);
const orderAddTimer = ref(null);
const replacingItem = ref(null); // если не null — режим замены товара

// Deadline extend modal
const showDeadlineModal = ref(false);
const deadlineSoft = ref('14:00');
const deadlineHard = ref('17:00');
const deadlineSaving = ref(false);

const filteredTemplateItems = computed(() => {
  let items = fullTemplateItems.value.filter(i => i.category === tplCategory.value);
  if (tplFilter.value) {
    const q = tplFilter.value.toLowerCase();
    items = items.filter(i => i.product_name.toLowerCase().includes(q) || i.sku.toLowerCase().includes(q));
  }
  return items;
});

const totalStats = computed(() => {
  const submitted = restaurants.value.filter(r => r.order_status);
  return {
    items: submitted.reduce((s, r) => s + (parseInt(r.item_count) || 0), 0),
    boxes: submitted.reduce((s, r) => s + (parseFloat(r.total_qty) || 0), 0).toFixed(0),
    weight: (submitted.reduce((s, r) => s + (parseFloat(r.total_weight) || 0), 0) / 1000).toFixed(1),
    pallets: submitted.reduce((s, r) => s + (parseInt(r.pallets) || 0), 0),
  };
});

const isDateOpen = computed(() => {
  const s = deadlineStatus.value;
  return s && s.status !== 'not_open';
});

const deadlineLabel = computed(() => {
  const s = deadlineStatus.value;
  if (!s) return '';
  const labels = { open: 'Приём открыт', warning: 'Дедлайн прошёл (ещё можно подать)', closed: 'Приём закрыт', not_open: 'Приём не открыт', not_yet: 'Ещё не начат' };
  return labels[s.status] || '';
});

const route = useRoute();

async function handleRouteQuery() {
  if (route.query.date) {
    selectedDate.value = route.query.date;
    await loadStatus();
  }
  if (route.query.order) {
    await viewOrder(parseInt(route.query.order));
  }
}

onMounted(async () => {
  window.addEventListener('beforeunload', onBeforeUnload);
  if (route.query.date) {
    selectedDate.value = route.query.date;
  } else {
    setTomorrow();
  }
  await loadStatus();
  startAutoRefresh();
  if (route.query.order) {
    viewOrder(parseInt(route.query.order));
  }
});

// Если пользователь уже на странице и переходит из отчёта повторно
watch(() => route.query.t, async () => {
  if (route.query.order) {
    await handleRouteQuery();
  }
});

// Смена юрлица в сайдбаре — перезагрузка списка ресторанов и журнала
watch(() => orderStore.settings.legalEntity, () => {
  loadStatus();
  if (pageTab.value === 'audit') loadAuditLog();
  if (pageTab.value === 'templates') loadFullTemplates();
});

function onBeforeUnload(e) {
  if (hasUnsavedChanges()) {
    e.preventDefault();
    e.returnValue = '';
  }
}

onUnmounted(() => {
  stopAutoRefresh();
  window.removeEventListener('beforeunload', onBeforeUnload);
});

// ═══ Unified export modal ═══
// Единицы веса в Excel: 'g' (граммы), 'kg' (килограммы), 't' (тонны).
// Веса в БД хранятся в граммах — конвертация локальная.
const WEIGHT_UNIT_LABELS = { g: 'г', kg: 'кг', t: 'т' };
function convertWeight(grams, unit) {
  if (!grams) return '';
  if (unit === 'kg') return +(grams / 1000).toFixed(2);
  if (unit === 't') return +(grams / 1_000_000).toFixed(4);
  return Math.round(grams);
}
function weightLabel(unit) { return WEIGHT_UNIT_LABELS[unit] || 'г'; }

// Описание всех доступных колонок выгрузки.
// key — внутренний ключ; label — заголовок в Excel; width — ширина;
// subtotal — флаг, что в эту колонку пишется итог по ресторану в первой строке;
// value(ctx, item) — значение ячейки.
const EXPORT_COLUMNS_DEF = [
  { key: 'date', label: 'Дата доставки', width: 14, value: ctx => ctx.date },
  { key: 'order_num', label: '№ заказа', width: 12, value: ctx => ctx.ordNum },
  { key: 'rest_num', label: '№ ресторана', width: 10, value: ctx => formatRestaurantNumber(ctx.order.restaurant_number, ctx.order.legal_entity_group) },
  { key: 'rest_addr', label: 'Адрес ресторана', width: 40, value: ctx => ctx.ri.address || ctx.ri.city || '' },
  { key: 'rest_city', label: 'Город', width: 16, value: ctx => ctx.ri.city || '' },
  { key: 'rest_region', label: 'Регион', width: 12, value: ctx => ctx.ri.region || '' },
  { key: 'delivery_time', label: 'Время доставки', width: 14, value: ctx => ctx.ri.delivery_time || '' },
  { key: 'category', label: 'Хранение', width: 10, value: (ctx, item) => item.category },
  { key: 'external_code', label: 'Внешний код', width: 14, value: (ctx, item) => item.external_code || '' },
  { key: 'gtin', label: 'GTIN', width: 16, value: (ctx, item) => item.gtin || '' },
  { key: 'sku', label: 'Артикул', width: 12, value: (ctx, item) => item.sku || '' },
  { key: 'product', label: 'Товар', width: 50, value: (ctx, item) => item.sku ? `${item.sku} ${item.product_name}` : item.product_name },
  { key: 'product_name', label: 'Название товара', width: 50, value: (ctx, item) => item.product_name || '' },
  { key: 'quantity', label: 'Количество', width: 12, value: (ctx, item) => parseFloat(item.quantity) || 0 },
  { key: 'multiplicity', label: 'Кратность', width: 10, value: (ctx, item) => parseFloat(item.multiplicity) || 1 },
  { key: 'netto', label: 'Нетто', width: 12, value: (ctx, item) => {
      if (!item.weight_netto) return '';
      const grams = (parseFloat(item.quantity) || 0) * parseFloat(item.weight_netto);
      return convertWeight(grams, ctx.weightUnit);
    } },
  { key: 'brutto', label: 'Брутто', width: 12, value: (ctx, item) => {
      if (!item.weight_brutto) return '';
      const grams = (parseFloat(item.quantity) || 0) * parseFloat(item.weight_brutto);
      return convertWeight(grams, ctx.weightUnit);
    } },
  { key: 'item_pallets', label: 'Палл. товара', width: 12, value: (ctx, item) => {
      const qty = parseFloat(item.quantity) || 0;
      const bpp = parseFloat(item.boxes_per_pallet) || 0;
      if (!bpp) return '';
      const boxes = qtyToBoxes(qty, item.multiplicity);
      const p = boxes / bpp;
      return p > 0 ? +p.toFixed(2) : '';
    } },
  { key: 'comment', label: 'Комментарий', width: 30, value: (ctx, item) => item.comment || '' },
  { key: 'deposit_price', label: 'Залог. цена (за кор.)', width: 16, value: (ctx, item) => {
      const dp = parseFloat(item.deposit_price) || 0;
      return dp > 0 ? +dp.toFixed(2) : '';
    } },
  { key: 'rest_weight', label: 'Вес рест.', width: 14, subtotal: true, value: ctx => ctx.isFirst ? ctx.restWeightFormatted : '' },
  { key: 'rest_pallets', label: 'Палл. рест.', width: 12, subtotal: true, value: ctx => ctx.isFirst ? (ctx.restPallets || '') : '' },
  { key: 'rest_deposit_sum', label: 'Залог рест.', width: 14, subtotal: true, value: ctx => ctx.isFirst ? (ctx.restDepositSum || '') : '' },
];

// Колонки по умолчанию (как было до правки)
const DEFAULT_EXPORT_COLUMNS = ['date', 'order_num', 'rest_num', 'rest_addr', 'delivery_time', 'category', 'external_code', 'gtin', 'product', 'quantity', 'netto', 'brutto', 'item_pallets', 'deposit_price', 'rest_weight', 'rest_pallets', 'rest_deposit_sum'];

function getExportColumnDefs(keys) {
  const map = Object.fromEntries(EXPORT_COLUMNS_DEF.map(c => [c.key, c]));
  return keys.map(k => map[k]).filter(Boolean);
}

const showExportModal = ref(false);
const exportLoading = ref(false);
const exportExporting = ref(false);
const cttJsonExporting = ref(false);
const exportGrouping = ref('list');
const exportFilterCategories = ref(new Set(['Сухой', 'Холод', 'Мороз']));
const exportFilterRegions = ref(new Set(['Минск', 'Регионы']));
const exportAllRestaurants = ref(true);
const exportFilterRestaurants = ref(new Set());
const exportAllProducts = ref(true);
const exportFilterProducts = ref(new Set());
const exportRestaurantSearch = ref('');
const exportProductSearch = ref('');
const exportProductCatFilter = ref('');
const exportShowFilters = ref(false);
const exportShowTotals = ref(true);
const exportWeightUnit = ref('kg'); // g | kg | t
const exportAvailableRestaurants = ref([]);
const exportAvailableProducts = ref([]);
const exportSelectedColumns = ref([...DEFAULT_EXPORT_COLUMNS]);
const EXPORT_COLUMN_PRESETS = {
  default: { label: 'Стандартный', cols: [...DEFAULT_EXPORT_COLUMNS] },
  minimal: { label: 'Минимальный', cols: ['rest_num', 'rest_addr', 'category', 'product', 'quantity'] },
  full: { label: 'Полный (все колонки)', cols: EXPORT_COLUMNS_DEF.map(c => c.key) },
  warehouse: { label: 'Для склада', cols: ['rest_num', 'rest_addr', 'delivery_time', 'category', 'product', 'quantity', 'item_pallets', 'rest_weight', 'rest_pallets'] },
  traceable: { label: 'С прослеживаемостью', cols: ['rest_num', 'rest_addr', 'category', 'external_code', 'gtin', 'product', 'quantity'] },
  deposit: { label: 'С залоговыми ценами', cols: ['date', 'rest_num', 'rest_addr', 'category', 'external_code', 'gtin', 'product', 'quantity', 'deposit_price', 'rest_deposit_sum'] },
};
let exportData = null;

function applyExportPreset(presetKey) {
  const p = EXPORT_COLUMN_PRESETS[presetKey];
  if (p) exportSelectedColumns.value = [...p.cols];
}

function toggleExportColumn(key) {
  const idx = exportSelectedColumns.value.indexOf(key);
  if (idx >= 0) exportSelectedColumns.value.splice(idx, 1);
  else exportSelectedColumns.value.push(key);
}

function moveExportColumn(key, direction) {
  const arr = exportSelectedColumns.value;
  const idx = arr.indexOf(key);
  if (idx < 0) return;
  const target = idx + direction;
  if (target < 0 || target >= arr.length) return;
  [arr[idx], arr[target]] = [arr[target], arr[idx]];
}

// ═══ beforeunload protection ═══
function hasUnsavedChanges() {
  if (showOrderModal.value && originalEditItems.value !== null) {
    if (JSON.stringify(editItems.value) !== originalEditItems.value) return true;
  }
  if (showExportModal.value && exportExporting.value) {
    return true;
  }
  return false;
}

// ═══ Safe close functions ═══
function closeExportModal() {
  if (exportExporting.value) return;
  showExportModal.value = false;
}

function closeOrderModal() {
  if (saving.value) return; // block close during save
  if (originalEditItems.value !== null && JSON.stringify(editItems.value) !== originalEditItems.value) {
    if (!confirm('Закрыть? Несохранённые изменения будут потеряны')) return;
  }
  showOrderModal.value = false;
  originalEditItems.value = null;
  editSearch.value = '';
}

function closeTplAddModal() {
  // simple modal, just close — no data to lose
  showTplAddModal.value = false;
}

function startAutoRefresh() {
  stopAutoRefresh();
  refreshInterval = setInterval(() => {
    if (pageTab.value === 'orders' && !showOrderModal.value) {
      loadStatus();
    }
  }, 60000);
}

function stopAutoRefresh() {
  if (refreshInterval) { clearInterval(refreshInterval); refreshInterval = null; }
}

function setTomorrow() {
  const d = new Date();
  d.setDate(d.getDate() + 1);
  selectedDate.value = d.toISOString().slice(0, 10);
}

async function loadStatus() {
  loading.value = true;
  try {
    const data = await store.adminGetStatus(selectedDate.value, orderStore.settings.legalEntity);
    session.value = data.session;
    restaurants.value = data.restaurants || [];
    stats.value = data.stats || { total: 0, submitted: 0, pending: 0 };
    deadlineStatus.value = data.deadline_status;
  } catch (e) {
    session.value = null;
  } finally {
    loading.value = false;
  }
}

async function handleAutoSession() {
  try {
    const result = await store.adminAutoSession(orderStore.settings.legalEntity || undefined);
    if (result.success) {
      await loadStatus();
    }
  } catch (e) {
    toast.error('Ошибка', e.message);
  }
}

async function handleToggleDate(open) {
  if (!session.value) return;
  try {
    await store.adminToggleDate(session.value.id, selectedDate.value, open, orderStore.settings.legalEntity || undefined);
    toast.success(open ? 'Приём заявок открыт' : 'Приём заявок закрыт');
    await loadStatus();
  } catch (e) {
    toast.error('Ошибка', e.message);
  }
}

function handleExtendDeadline() {
  if (!session.value) return;
  deadlineSoft.value = '14:00';
  deadlineHard.value = '17:00';
  showDeadlineModal.value = true;
}

async function saveDeadlineExtend() {
  if (!session.value) return;
  deadlineSaving.value = true;
  try {
    await store.adminExtendDeadline(
      session.value.id,
      selectedDate.value,
      deadlineSoft.value + ':00',
      deadlineHard.value + ':00',
      orderStore.settings.legalEntity || undefined
    );
    showDeadlineModal.value = false;
    toast.success('Дедлайн продлён');
    await loadStatus();
  } catch (e) {
    toast.error('Ошибка', e.message);
  } finally {
    deadlineSaving.value = false;
  }
}

// statusLabel imported from roUtils.js

async function viewOrder(orderId) {
  try {
    const order = await store.adminGetOrder(orderId);
    editingOrder.value = order;
    editItems.value = (order.items || []).map(i => ({ ...i, quantity: parseFloat(i.quantity) || 0 }));
    originalEditItems.value = JSON.stringify(editItems.value);
    editingDateMode.value = false;
    editingNewDate.value = order.delivery_date || '';
    editSearch.value = '';
    // Открываем на первой непустой категории
    const cats = ['Сухой', 'Холод', 'Мороз'];
    const firstWithItems = cats.find(c => editItems.value.some(i => i.category === c)) || 'Сухой';
    editCategory.value = firstWithItems;
    showOrderModal.value = true;
  } catch (e) {
    toast.error('Ошибка', e.message);
  }
}

function getEditItems(cat) {
  return editItems.value.filter(i => i.category === cat);
}

function getDisplayEditItems(cat) {
  const items = getEditItems(cat);
  const q = editSearch.value.trim().toLowerCase();
  if (!q) return items;
  return items.filter(i =>
    (i.sku || '').toLowerCase().includes(q) ||
    (i.product_name || '').toLowerCase().includes(q)
  );
}

function removeEditItem(item) {
  editItems.value = editItems.value.filter(i => i !== item);
}

// Штучный товар: кол-во в штуках → коробки
function qtyToBoxes(qty, mult) {
  const m = parseFloat(mult) || 1;
  return m > 1 ? qty / m : qty;
}

function hasMultiplicityViolation(qty, mult) {
  const q = parseFloat(qty) || 0;
  const m = parseFloat(mult) || 1;
  if (q <= 0 || m <= 1) return false;
  return Math.abs(q / m - Math.round(q / m)) > 0.0001;
}

function editItemHasMultError(item) {
  return hasMultiplicityViolation(item.quantity, item.multiplicity);
}

function formatEditNumber(value) {
  const num = parseFloat(value);
  if (!Number.isFinite(num)) return String(value || '');
  return Math.abs(num - Math.round(num)) < 0.0001 ? String(Math.round(num)) : String(parseFloat(num.toFixed(3)));
}

const editMultiplicityErrors = computed(() => editItems.value.filter(item => editItemHasMultError(item)));
const editHasMultErrors = computed(() => editMultiplicityErrors.value.length > 0);
const editMultiplicityErrorText = computed(() => {
  if (!editMultiplicityErrors.value.length) return '';
  const item = editMultiplicityErrors.value[0];
  return `${item.sku} ${item.product_name}: количество ${formatEditNumber(item.quantity)} должно быть кратно ${formatEditNumber(item.multiplicity)}`;
});

// Округление паллет: дробная часть ≤ 0.2 → вниз, > 0.2 → вверх.
// Но если товар есть (raw > 0), минимум — 1 паллета.
function roundPallets(raw) {
  if (raw <= 0) return 0;
  const frac = raw - Math.floor(raw);
  const rounded = frac > 0.2 ? Math.ceil(raw) : Math.floor(raw);
  return rounded === 0 ? 1 : rounded;
}

function catTotals(cat) {
  const items = getEditItems(cat).filter(i => (parseFloat(i.quantity) || 0) > 0);
  const boxes = items.reduce((s, i) => s + (parseFloat(i.quantity) || 0), 0);
  const weight = items.reduce((s, i) => s + (parseFloat(i.quantity) || 0) * (parseFloat(i.weight_brutto) || 0), 0);
  let rawPallets = 0;
  let deposit = 0;
  for (const item of items) {
    const bpp = parseFloat(item.boxes_per_pallet) || 0;
    const qty = parseFloat(item.quantity) || 0;
    if (bpp > 0) rawPallets += qtyToBoxes(qty, item.multiplicity) / bpp;
    const dp = parseFloat(item.deposit_price) || 0;
    if (dp > 0) deposit += dp * qtyToBoxes(qty, item.multiplicity);
  }
  return {
    boxes: boxes.toFixed(0),
    weight: (weight / 1000).toFixed(1),
    pallets: roundPallets(rawPallets),
    deposit: deposit > 0 ? deposit.toFixed(2) : '',
  };
}

function itemWeight(item) {
  const qty = parseFloat(item.quantity) || 0;
  const brutto = parseFloat(item.weight_brutto) || 0;
  if (!qty || !brutto) return '—';
  return (qty * brutto / 1000).toFixed(1);
}

function itemDepositSum(item) {
  const qty = parseFloat(item.quantity) || 0;
  const dp = parseFloat(item.deposit_price) || 0;
  if (!qty || !dp) return '';
  const sum = dp * qtyToBoxes(qty, item.multiplicity);
  return sum > 0 ? sum.toFixed(2) : '';
}

const orderTotals = computed(() => {
  const items = editItems.value.filter(i => (parseFloat(i.quantity) || 0) > 0);
  const boxes = items.reduce((s, i) => s + (parseFloat(i.quantity) || 0), 0);
  const weight = items.reduce((s, i) => s + (parseFloat(i.quantity) || 0) * (parseFloat(i.weight_brutto) || 0), 0);
  let deposit = 0;
  const palletsByCategory = {};
  for (const item of items) {
    const bpp = parseFloat(item.boxes_per_pallet) || 0;
    const qty = parseFloat(item.quantity) || 0;
    if (bpp > 0) {
      const cat = item.category || 'Сухой';
      palletsByCategory[cat] = (palletsByCategory[cat] || 0) + qtyToBoxes(qty, item.multiplicity) / bpp;
    }
    const dp = parseFloat(item.deposit_price) || 0;
    if (dp > 0) deposit += dp * qtyToBoxes(qty, item.multiplicity);
  }
  const pallets = Object.values(palletsByCategory).reduce((s, v) => s + roundPallets(v), 0);
  return {
    boxes: boxes.toFixed(0),
    weight: (weight / 1000).toFixed(1),
    pallets,
    deposit: deposit > 0 ? deposit.toFixed(2) : '',
  };
});

function openOrderAddProduct(category) {
  replacingItem.value = null;
  orderAddSearch.value = '';
  orderAddResults.value = [];
  showOrderAddModal.value = true;
}

function openReplaceProduct(item) {
  replacingItem.value = item;
  orderAddSearch.value = '';
  orderAddResults.value = [];
  showOrderAddModal.value = true;
}

function doOrderAddSearch() {
  clearTimeout(orderAddTimer.value);
  if (!orderAddSearch.value || orderAddSearch.value.length < 2) { orderAddResults.value = []; return; }
  orderAddTimer.value = setTimeout(async () => {
    try {
      const le = editingOrder.value?.legal_entity || 'ООО "Бургер БК"';
      const products = await store.adminSearchProducts(le, orderAddSearch.value);
      const existing = new Set(editItems.value.map(i => i.sku));
      if (replacingItem.value) existing.delete(replacingItem.value.sku);
      orderAddResults.value = products.filter(p => !existing.has(p.sku));
    } catch { orderAddResults.value = []; }
  }, 300);
}

function pickOrderProduct(product) {
  const multiplicity = parseInt(product.multiplicity) || 1;
  if (replacingItem.value) {
    replacingItem.value.sku = product.sku;
    replacingItem.value.product_name = product.name || product.product_name;
    replacingItem.value.category = product.category || replacingItem.value.category;
    replacingItem.value.multiplicity = multiplicity;
    replacingItem.value = null;
  } else {
    editItems.value.push({
      sku: product.sku,
      product_name: product.name || product.product_name,
      category: product.category || 'Сухой',
      quantity: multiplicity > 1 ? multiplicity : 1,
      multiplicity,
      comment: '',
    });
  }
  showOrderAddModal.value = false;
}

async function saveEditedOrder() {
  if (!editingOrder.value) return;
  saving.value = true;
  try {
    await store.adminUpdateOrder(editingOrder.value.id, {
      items: editItems.value.filter(i => i.quantity > 0),
    });
    originalEditItems.value = null;
    showOrderModal.value = false;
    await loadStatus();
  } catch (e) {
    toast.error('Ошибка', e.message);
  } finally {
    saving.value = false;
  }
}

async function changeDeliveryDate() {
  if (!editingOrder.value || !editingNewDate.value) return;
  if (editingNewDate.value === editingOrder.value.delivery_date) { editingDateMode.value = false; return; }
  saving.value = true;
  try {
    await store.adminUpdateOrder(editingOrder.value.id, {
      delivery_date: editingNewDate.value,
    });
    editingOrder.value.delivery_date = editingNewDate.value;
    editingDateMode.value = false;
    toast.success('Дата доставки изменена');
    await loadStatus();
  } catch (e) {
    toast.error('Ошибка', e.message);
  } finally {
    saving.value = false;
  }
}

async function handleDeleteOrder(order) {
  if (!order?.id) return;
  if (!confirm(`Удалить заказ ресторана ${formatRestaurantNumber(order.restaurant_number, order.legal_entity_group)}?`)) return;
  saving.value = true;
  try {
    await store.adminDeleteOrder(order.id);
    originalEditItems.value = null;
    showOrderModal.value = false;
    await loadStatus();
  } catch (e) {
    toast.error('Ошибка', e.message);
  } finally {
    saving.value = false;
  }
}

// Users
async function openUsersModal() {
  showUsersModal.value = true;
  usersCount.value = null;
  await reloadUsers();
}

async function reloadUsers() {
  usersLoading.value = true;
  try {
    usersList.value = await store.adminGetUsers();
  } catch (e) {
    toast.error('Ошибка загрузки', e.message);
  } finally {
    usersLoading.value = false;
  }
}

async function handleBulkCreate() {
  const warn = bulkMode.value === 'all'
    ? 'Назначить пароль ВСЕМ активным ресторанам? У существующих пароль будет заменён.'
    : 'Назначить пароль только тем ресторанам, у которых пароля ещё нет?';
  if (!confirm(warn)) return;
  usersBusy.value = true;
  try {
    const result = await store.adminCreateBulkUsers(bulkPassword.value, bulkMode.value);
    usersCount.value = result.created;
    bulkPassword.value = '';
    await reloadUsers();
    toast.success('Готово', `Обновлено: ${result.created}`);
  } catch (e) {
    toast.error('Ошибка', e.message);
  } finally {
    usersBusy.value = false;
  }
}

async function handleSetPassword(u) {
  const verb = u.has_password ? 'Новый пароль' : 'Задайте пароль';
  const label = formatRestaurantNumber(u.restaurant_number, u.legal_entity_group);
  const pass = prompt(`${verb} для ресторана ${label}:`);
  if (!pass) return;
  usersBusy.value = true;
  try {
    // adminCreateUser делает INSERT … ON DUPLICATE KEY UPDATE — то же самое, что reset.
    await store.adminCreateUser(u.restaurant_number, u.legal_entity_group, pass);
    toast.success('Готово', `Пароль ресторана ${label} сохранён`);
    await reloadUsers();
  } catch (e) {
    toast.error('Ошибка', e.message);
  } finally {
    usersBusy.value = false;
  }
}

async function handleToggleUser(u) {
  const next = u.is_active ? 0 : 1;
  const label = formatRestaurantNumber(u.restaurant_number, u.legal_entity_group);
  if (next === 0 && !confirm(`Отключить учётку ресторана ${label}? Он не сможет войти.`)) return;
  usersBusy.value = true;
  try {
    await store.adminToggleUser(u.restaurant_number, u.legal_entity_group, next);
    u.is_active = next;
  } catch (e) {
    toast.error('Ошибка', e.message);
  } finally {
    usersBusy.value = false;
  }
}

// Templates (full page)
async function loadFullTemplates() {
  tplMessage.value = '';
  fullTemplateItems.value = [];
  for (const cat of ['Сухой', 'Холод', 'Мороз']) {
    try {
      const items = await store.adminGetTemplates(tplLegalEntity.value, cat);
      fullTemplateItems.value.push(...items.map(i => ({
        ...i,
        category: i.category || cat,
        multiplicity: parseInt(i.multiplicity) || 1,
      })));
    } catch {}
  }
}

function removeTplItemFull(item) {
  fullTemplateItems.value = fullTemplateItems.value.filter(i => i !== item);
}

async function saveFullTemplate() {
  tplSaving.value = true;
  tplMessage.value = '';
  try {
    for (const cat of ['Сухой', 'Холод', 'Мороз']) {
      const items = fullTemplateItems.value.filter(i => i.category === cat);
      await store.adminSaveTemplate(tplLegalEntity.value, cat, items);
    }
    tplMessage.value = `Шаблон сохранён (${fullTemplateItems.value.length} товаров)`;
    tplMessageOk.value = true;
    setTimeout(() => { tplMessage.value = ''; }, 3000);
  } catch (e) {
    tplMessage.value = 'Ошибка: ' + e.message;
    tplMessageOk.value = false;
  } finally {
    tplSaving.value = false;
  }
}

async function handleImportFromStock() {
  if (!confirm(`Заменить шаблон «${tplCategory.value}» товарами, у которых есть остаток на складе? Текущие позиции в этой категории будут удалены.`)) return;
  try {
    const result = await store.adminImportTemplateFromStock(tplLegalEntity.value, tplCategory.value);
    // Удаляем старые этой категории, добавляем новые
    fullTemplateItems.value = fullTemplateItems.value.filter(i => i.category !== tplCategory.value);
    const newItems = (result.items || []).map(i => ({
      sku: i.sku || i.product_name,
      product_name: i.product_name || i.name,
      category: tplCategory.value,
      multiplicity: parseInt(i.multiplicity) || 1,
    }));
    fullTemplateItems.value.push(...newItems);
    const dateNote = result.balance_date ? ` (остатки на ${result.balance_date})` : '';
    tplMessage.value = `Импортировано: ${result.count} товаров в «${tplCategory.value}»${dateNote}`;
    tplMessageOk.value = true;
    setTimeout(() => { tplMessage.value = ''; }, 4000);
  } catch (e) {
    tplMessage.value = e.message || 'Ошибка импорта';
    tplMessageOk.value = false;
  }
}

function doTplAddSearch() {
  clearTimeout(tplAddTimer.value);
  if (!tplAddSearch.value || tplAddSearch.value.length < 2) { tplAddResults.value = []; return; }
  tplAddTimer.value = setTimeout(async () => {
    try {
      const products = await store.adminSearchProducts(tplLegalEntity.value, tplAddSearch.value);
      const existing = new Set(fullTemplateItems.value.map(i => i.sku));
      tplAddResults.value = products.filter(p => !existing.has(p.sku));
    } catch { tplAddResults.value = []; }
  }, 300);
}

function addToTemplate(product) {
  fullTemplateItems.value.push({
    sku: product.sku,
    product_name: product.name || product.product_name,
    category: product.category || tplCategory.value,
    multiplicity: parseInt(product.multiplicity) || 1,
  });
  tplAddResults.value = tplAddResults.value.filter(p => p.sku !== product.sku);
  showTplAddModal.value = false;
}

// ═══ Export helpers ═══
function buildExportRows(orders, itemsByRest, restInfoMap, date, showTotals = false, columnKeys = DEFAULT_EXPORT_COLUMNS, weightUnit = 'kg') {
  const cols = getExportColumnDefs(columnKeys).map(c => {
    // Подставляем единицу веса в заголовки netto/brutto/rest_weight
    if (c.key === 'netto') return { ...c, label: `Нетто (${weightLabel(weightUnit)})` };
    if (c.key === 'brutto') return { ...c, label: `Брутто (${weightLabel(weightUnit)})` };
    if (c.key === 'rest_weight') return { ...c, label: `Вес рест. (${weightLabel(weightUnit)})` };
    return c;
  });
  const rows = [cols.map(c => c.label)];
  const subtotalRows = [];
  const sorted = [...orders].sort((a, b) => a.restaurant_number - b.restaurant_number);
  for (const order of sorted) {
    const oi = itemsByRest[order.restaurant_number] || [];
    if (!oi.length) continue;
    const ri = restInfoMap[order.restaurant_number] || {};
    const ordNum = `RO-${String(order.id).padStart(4, '0')}`;
    oi.sort((a, b) => (a.category || '').localeCompare(b.category || '') || (a.product_name || '').localeCompare(b.product_name || ''));

    // Предварительный расчёт итогов по ресторану
    let restBrutto = 0;
    let restDeposit = 0;
    const palletsByCategory = {};
    for (const item of oi) {
      const qty = parseFloat(item.quantity) || 0;
      const bpp = parseFloat(item.boxes_per_pallet) || 0;
      const brutto = item.weight_brutto ? qty * parseFloat(item.weight_brutto) : 0;
      restBrutto += brutto;
      const dp = parseFloat(item.deposit_price) || 0;
      if (dp > 0) restDeposit += dp * qtyToBoxes(qty, item.multiplicity);
      const cat = item.category || 'Сухой';
      if (bpp > 0) palletsByCategory[cat] = (palletsByCategory[cat] || 0) + qtyToBoxes(qty, item.multiplicity) / bpp;
    }
    const restPallets = Object.values(palletsByCategory).reduce((sum, v) => sum + roundPallets(v), 0);
    const restWeightFormatted = restBrutto ? convertWeight(restBrutto, weightUnit) : '';
    const restDepositSum = restDeposit > 0 ? +restDeposit.toFixed(2) : '';

    // Строки товаров — итоги ресторана записываются в первую строку
    let isFirst = true;
    for (const item of oi) {
      const ctx = { date, ordNum, order, ri, isFirst, restWeightFormatted, restPallets, restDepositSum, weightUnit, showTotals };
      const row = cols.map(c => {
        // Колонки итогов выводятся только если включены showTotals
        if (c.subtotal && !showTotals) return '';
        return c.value(ctx, item);
      });
      rows.push(row);
      if (item.is_traceable == 1) subtotalRows.push({ idx: rows.length - 1, type: 'traceable' });
      if (isFirst) {
        isFirst = false;
        if (showTotals) subtotalRows.push({ idx: rows.length - 1, type: 'subtotal' });
      }
    }
  }
  // Возвращаем индексы subtotal-колонок для стилизации
  const subtotalColIdx = cols
    .map((c, i) => c.subtotal ? i : -1)
    .filter(i => i >= 0);
  return { rows, subtotalRows, subtotalColIdx, colDefs: cols };
}

function styleExportSheet(ws, rowCount, subtotalRows, colCount, subtotalColIdx, colDefs) {
  for (let c = 0; c < colCount; c++) {
    const cell = ws[XLSX.utils.encode_cell({ r: 0, c })];
    if (cell) cell.s = EXCEL_HEADER_STYLE;
  }
  for (const sr of (subtotalRows || [])) {
    if (sr.type === 'traceable') {
      for (let c = 0; c < colCount; c++) {
        const cell = ws[XLSX.utils.encode_cell({ r: sr.idx, c })];
        if (cell) cell.s = EXCEL_TRACEABLE_STYLE;
      }
    } else {
      for (const c of (subtotalColIdx || [])) {
        const cell = ws[XLSX.utils.encode_cell({ r: sr.idx, c })];
        if (cell) cell.s = EXCEL_SUBTOTAL_STYLE;
      }
    }
  }
  ws['!cols'] = (colDefs || []).map(c => ({ wch: c.width }));
  ws['!autofilter'] = { ref: XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: rowCount - 1, c: colCount - 1 } }) };
}

function buildSingleOrderXlsx(order, items) {
  const wb = XLSX.utils.book_new();
  const restInfo = { [order.restaurant_number]: { city: order.city || '', address: order.address || '', region: order.region || '', delivery_time: '' } };
  const byRest = { [order.restaurant_number]: items.filter(i => (parseFloat(i.quantity) || 0) > 0) };
  const { rows, subtotalRows, subtotalColIdx, colDefs } = buildExportRows([order], byRest, restInfo, order.delivery_date || selectedDate.value, false, DEFAULT_EXPORT_COLUMNS, exportWeightUnit.value);
  const ws = XLSX.utils.aoa_to_sheet(rows);
  styleExportSheet(ws, rows.length, subtotalRows, colDefs.length, subtotalColIdx, colDefs);
  const prettyRest = formatRestaurantNumber(order.restaurant_number, order.legal_entity_group);
  XLSX.utils.book_append_sheet(wb, ws, `Рест ${prettyRest}`);
  return wb;
}

function exportSingleOrder(order) {
  if (!order || !editItems.value.length) return;
  const wb = buildSingleOrderXlsx(order, editItems.value);
  const prettyRest = formatRestaurantNumber(order.restaurant_number, order.legal_entity_group);
  XLSX.writeFile(wb, `Заказ_рест_${prettyRest}_${order.delivery_date}.xlsx`);
}

async function quickExportOrder(orderId, restaurantNumber, legalEntityGroup) {
  try {
    const order = await store.adminGetOrder(orderId);
    const items = (order.items || []).map(i => ({ ...i, quantity: parseFloat(i.quantity) || 0 }));
    if (!items.length) { toast.warning('Заказ пуст'); return; }
    const wb = buildSingleOrderXlsx(order, items);
    const prettyRest = formatRestaurantNumber(restaurantNumber, legalEntityGroup);
    XLSX.writeFile(wb, `Заказ_рест_${prettyRest}_${selectedDate.value}.xlsx`);
  } catch (e) {
    toast.error('Ошибка', e.message);
  }
}

function downloadJsonFile(filename, payload) {
  const blob = new Blob([JSON.stringify(payload)], { type: 'application/json;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

async function downloadCttJson() {
  cttJsonExporting.value = true;
  try {
    const data = await store.adminGetExportData('ctt-json', selectedDate.value, orderStore.settings.legalEntity || undefined);
    const items = Array.isArray(data.items) ? data.items : [];
    if (!items.length) {
      toast.warning('Нет данных', 'Для этой даты нет позиций для JSON-выгрузки');
      return;
    }
    downloadJsonFile(data.filename || `data-dodo-${selectedDate.value}.json`, items);
    toast.success('JSON скачан', `Позиций: ${items.length}`);
    if (data.skipped_missing_gtin) {
      toast.warning('Часть строк пропущена', `Без GTIN: ${data.skipped_missing_gtin}`);
    }
    if (data.missing_deposit_price) {
      toast.warning('Не везде есть залог', `С нулевой залоговой ценой: ${data.missing_deposit_price}`);
    }
  } catch (e) {
    toast.error('Ошибка выгрузки', e.message);
  } finally {
    cttJsonExporting.value = false;
  }
}

function copyRoLink() {
  const url = window.location.origin + '/restaurant';
  navigator.clipboard.writeText(url);
  toast.success('Ссылка скопирована', url);
}

// ═══ Unified export modal logic ═══
function toggleExportFilter(refName, value) {
  const refs = {
    categories: exportFilterCategories,
    regions: exportFilterRegions,
    restaurants: exportFilterRestaurants,
    products: exportFilterProducts,
  };
  const r = refs[refName];
  if (!r) return;
  const s = new Set(r.value);
  if (s.has(value)) s.delete(value); else s.add(value);
  r.value = s;
}

const filteredExportRestaurants = computed(() => {
  let list = exportAvailableRestaurants.value;
  if (exportRestaurantSearch.value) {
    const q = exportRestaurantSearch.value.toLowerCase();
    list = list.filter(r => String(r.number).includes(q) || (r.city || '').toLowerCase().includes(q) || (r.address || '').toLowerCase().includes(q));
  }
  // Also filter by region checkboxes
  if (exportFilterRegions.value.size < 2) {
    list = list.filter(r => {
      const isMinsk = r.region === 'Минск';
      return (isMinsk && exportFilterRegions.value.has('Минск')) || (!isMinsk && exportFilterRegions.value.has('Регионы'));
    });
  }
  return list;
});

const filteredExportProducts = computed(() => {
  let list = exportAvailableProducts.value;
  if (exportProductCatFilter.value) list = list.filter(p => p.category === exportProductCatFilter.value);
  if (exportProductSearch.value) {
    const q = exportProductSearch.value.toLowerCase();
    list = list.filter(p => p.product_name.toLowerCase().includes(q) || p.sku.toLowerCase().includes(q));
  }
  return list;
});

function expProductsSelectAll() {
  const s = new Set(exportFilterProducts.value);
  for (const p of filteredExportProducts.value) s.add(p.sku);
  exportFilterProducts.value = s;
}

function getFilteredExportData() {
  if (!exportData) return { orders: [], items: [] };
  // Filter orders by region
  let orders = exportData.orders;
  if (exportFilterRegions.value.size < 2) {
    orders = orders.filter(o => {
      const isMinsk = o.region === 'Минск';
      return (isMinsk && exportFilterRegions.value.has('Минск')) || (!isMinsk && exportFilterRegions.value.has('Регионы'));
    });
  }
  // Filter by selected restaurants
  if (!exportAllRestaurants.value && exportFilterRestaurants.value.size > 0) {
    orders = orders.filter(o => exportFilterRestaurants.value.has(o.restaurant_number));
  } else if (!exportAllRestaurants.value && exportFilterRestaurants.value.size === 0) {
    orders = [];
  }
  const restNums = new Set(orders.map(o => o.restaurant_number));

  // Filter items
  let items = exportData.items;
  // By category
  if (exportFilterCategories.value.size < 3) {
    items = items.filter(i => exportFilterCategories.value.has(i.category));
  }
  // By restaurant
  items = items.filter(i => restNums.has(i.restaurant_number));
  // By products
  if (!exportAllProducts.value && exportFilterProducts.value.size > 0) {
    items = items.filter(i => exportFilterProducts.value.has(i.sku));
  } else if (!exportAllProducts.value && exportFilterProducts.value.size === 0) {
    items = [];
  }

  return { orders, items };
}

const exportSummary = computed(() => {
  const { orders, items } = getFilteredExportData();
  // Count orders that have at least one item
  const restWithItems = new Set(items.map(i => i.restaurant_number));
  return {
    orders: orders.filter(o => restWithItems.has(o.restaurant_number)).length,
    items: items.length,
  };
});

async function openExportModal() {
  showExportModal.value = true;
  exportLoading.value = true;
  exportGrouping.value = 'list';
  exportFilterCategories.value = new Set(['Сухой', 'Холод', 'Мороз']);
  exportFilterRegions.value = new Set(['Минск', 'Регионы']);
  exportAllRestaurants.value = true;
  exportFilterRestaurants.value = new Set();
  exportAllProducts.value = true;
  exportFilterProducts.value = new Set();
  exportRestaurantSearch.value = '';
  exportProductSearch.value = '';
  exportProductCatFilter.value = '';
  exportShowFilters.value = false;
  exportShowTotals.value = true;
  exportWeightUnit.value = 'kg';
  exportSelectedColumns.value = [...DEFAULT_EXPORT_COLUMNS];
  try {
    const data = await store.adminGetExportData('all', selectedDate.value, orderStore.settings.legalEntity || undefined);
    exportData = data;
    // Build available restaurants
    const restMap = {};
    for (const o of data.orders) {
      restMap[o.restaurant_number] = { number: o.restaurant_number, region: o.region || '', city: o.city || '', address: o.address || '' };
    }
    exportAvailableRestaurants.value = Object.values(restMap).sort((a, b) => a.number - b.number);
    // Build available products
    const prodMap = {};
    for (const item of data.items) {
      if (!prodMap[item.sku]) prodMap[item.sku] = { sku: item.sku, product_name: item.product_name, category: item.category };
    }
    exportAvailableProducts.value = Object.values(prodMap).sort((a, b) => (a.category || '').localeCompare(b.category || '') || a.product_name.localeCompare(b.product_name));
  } catch (e) { toast.error('Ошибка', e.message); showExportModal.value = false; }
  finally { exportLoading.value = false; }
}

async function doUnifiedExport() {
  if (!exportData) return;
  exportExporting.value = true;
  try {
    const { orders, items } = getFilteredExportData();
    if (!items.length) { toast.warning('Нет данных для выгрузки'); return; }

    // Build restInfo map
    const restInfoMap = {};
    for (const o of exportData.orders) {
      restInfoMap[o.restaurant_number] = { city: o.city || '', address: o.address || '', region: o.region || '', delivery_time: o.delivery_time || '' };
    }
    // Build items by restaurant
    const byRest = {};
    for (const item of items) {
      if (!byRest[item.restaurant_number]) byRest[item.restaurant_number] = [];
      byRest[item.restaurant_number].push(item);
    }
    // Only orders that have items
    const restWithItems = new Set(items.map(i => i.restaurant_number));
    const filteredOrders = orders.filter(o => restWithItems.has(o.restaurant_number));

    const wb = XLSX.utils.book_new();

    const totals = exportShowTotals.value;
    // Если итоги выключены — убираем колонки итогов из выбора, чтобы не плодить пустые столбцы
    let columns = [...exportSelectedColumns.value];
    if (!totals) columns = columns.filter(k => k !== 'rest_weight' && k !== 'rest_pallets' && k !== 'rest_deposit_sum');
    if (!columns.length) { toast.warning('Не выбрана ни одна колонка'); return; }

    const writeSheet = (orders, byRestArg, name) => {
      const { rows, subtotalRows, subtotalColIdx, colDefs } = buildExportRows(orders, byRestArg, restInfoMap, selectedDate.value, totals, columns, exportWeightUnit.value);
      const ws = XLSX.utils.aoa_to_sheet(rows);
      styleExportSheet(ws, rows.length, subtotalRows, colDefs.length, subtotalColIdx, colDefs);
      XLSX.utils.book_append_sheet(wb, ws, name.slice(0, 31));
    };

    if (exportGrouping.value === 'list') {
      writeSheet(filteredOrders, byRest, 'Все заказы');
      // Additional sheet per restaurant
      const sorted = [...filteredOrders].sort((a, b) => a.restaurant_number - b.restaurant_number);
      for (const order of sorted) {
        const oi = byRest[order.restaurant_number] || [];
        if (!oi.length) continue;
        const prettyRest = formatRestaurantNumber(order.restaurant_number, order.legal_entity_group);
        writeSheet([order], { [order.restaurant_number]: oi }, `Рест ${prettyRest}`);
      }
    } else if (exportGrouping.value === 'restaurants') {
      const sorted = [...filteredOrders].sort((a, b) => a.restaurant_number - b.restaurant_number);
      for (const order of sorted) {
        const oi = byRest[order.restaurant_number] || [];
        if (!oi.length) continue;
        const prettyRest = formatRestaurantNumber(order.restaurant_number, order.legal_entity_group);
        writeSheet([order], { [order.restaurant_number]: oi }, `Рест ${prettyRest}`);
      }
    } else if (exportGrouping.value === 'categories') {
      for (const cat of ['Сухой', 'Холод', 'Мороз']) {
        const catItems = items.filter(i => i.category === cat);
        if (!catItems.length) continue;
        const catByRest = {};
        for (const item of catItems) {
          if (!catByRest[item.restaurant_number]) catByRest[item.restaurant_number] = [];
          catByRest[item.restaurant_number].push(item);
        }
        const catRestNums = new Set(catItems.map(i => i.restaurant_number));
        const catOrders = filteredOrders.filter(o => catRestNums.has(o.restaurant_number));
        writeSheet(catOrders, catByRest, cat);
      }
    }

    XLSX.writeFile(wb, `Заказы_ресторанов_${selectedDate.value}.xlsx`);
    showExportModal.value = false;
  } catch (e) {
    toast.error('Ошибка экспорта', e.message);
  } finally {
    exportExporting.value = false;
  }
}

// formatDate, formatTime imported from roUtils.js

</script>

<style scoped>
.rom-page { padding: 20px; }

/* Header */
.rom-header {
  display: flex; justify-content: space-between; align-items: flex-end;
  margin-bottom: 18px; flex-wrap: wrap; gap: 12px;
  border-bottom: 1px solid #e0d5c8; padding-bottom: 0;
}
.rom-header h1 {
  margin: 0 0 10px 0; font-size: 22px; color: #502314;
  font-weight: 700; letter-spacing: -0.2px;
}
.rom-header-left { display: flex; flex-direction: column; gap: 4px; }
.rom-header-right { display: flex; gap: 8px; align-items: center; padding-bottom: 10px; }
.rom-btn-icon {
  width: 36px; height: 36px; padding: 0; display: inline-flex;
  align-items: center; justify-content: center; font-size: 18px;
  border: 1px solid #e0d5c8; color: #502314; background: white;
}
.rom-btn-icon:hover { background: #faf0e6; }
.rom-menu-wrap { position: relative; }
.rom-menu {
  position: absolute; right: 0; top: calc(100% + 6px); min-width: 240px;
  background: white; border: 1px solid #e0d5c8; border-radius: 10px;
  box-shadow: 0 12px 32px rgba(80,35,20,0.14); padding: 6px; z-index: 50;
}
.rom-menu-item {
  display: block; width: 100%; padding: 10px 14px; border: none;
  background: transparent; text-align: left; font-size: 13px; font-family: inherit;
  color: #502314; cursor: pointer; border-radius: 6px; font-weight: 500;
}
.rom-menu-item:hover { background: #faf0e6; }

/* Командир: дата + дедлайн + действия */
.rom-command {
  display: grid; grid-template-columns: auto 1fr auto;
  gap: 16px; align-items: stretch;
  background: white;
  border: 1px solid #e8dccd;
  border-radius: 12px;
  padding: 14px 18px; margin-bottom: 18px;
  box-shadow: 0 4px 16px rgba(80,35,20,0.06);
}
.rom-command-date, .rom-command-deadline { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.rom-cmd-label {
  font-size: 10px; color: #8b7355; text-transform: uppercase;
  letter-spacing: 0.6px; font-weight: 700;
}
.rom-cmd-date {
  padding: 8px 12px; border: 1px solid #e0d5c8; border-radius: 8px;
  font-size: 14px; font-family: inherit; color: #502314; font-weight: 600;
  background: #faf7f4;
}
.rom-cmd-date:focus { outline: none; border-color: #D62300; background: white; }
.rom-command-deadline {
  padding: 8px 16px; border-radius: 10px;
  background: #faf7f4;
  border-left: 4px solid #d4c2a8;
  justify-content: center;
}
.rom-cmd-deadline-text { font-size: 16px; font-weight: 700; color: #502314; }
.rom-cmd-deadline-times { display: flex; gap: 16px; font-size: 11px; color: #8b7355; font-weight: 500; }
.rom-cmd-deadline-times strong { color: #502314; }
.rom-command-deadline.dl-open { background: #ecfdf5; border-left-color: #16a34a; }
.rom-command-deadline.dl-open .rom-cmd-deadline-text { color: #15803d; }
.rom-command-deadline.dl-warning { background: #fffbeb; border-left-color: #d97706; }
.rom-command-deadline.dl-warning .rom-cmd-deadline-text { color: #b45309; }
.rom-command-deadline.dl-closed { background: #fef2f2; border-left-color: #dc2626; }
.rom-command-deadline.dl-closed .rom-cmd-deadline-text { color: #b91c1c; }
.rom-command-deadline.dl-not_yet { background: #eff6ff; border-left-color: #2563eb; }
.rom-command-deadline.dl-not_yet .rom-cmd-deadline-text { color: #1d4ed8; }
.rom-command-deadline.dl-not_open, .rom-command-deadline.dl-none { background: #f5f5f4; border-left-color: #a8a29e; }
.rom-command-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

/* Cards (карточки-сводка) */
.rom-cards {
  display: grid; grid-template-columns: repeat(6, 1fr);
  gap: 12px; margin-bottom: 18px;
}
.rom-card {
  background: white;
  border: 1px solid #e8dccd;
  border-radius: 10px;
  padding: 14px 14px; text-align: center;
  box-shadow: 0 2px 8px rgba(80,35,20,0.05);
  position: relative; overflow: hidden;
}
.rom-card::before {
  content: ''; position: absolute; left: 0; top: 0; bottom: 0;
  width: 3px; background: #502314;
}
.rom-card-num { font-size: 26px; font-weight: 700; color: #502314; line-height: 1.05; }
.rom-card-label {
  font-size: 10px; color: #8b7355; text-transform: uppercase;
  margin-top: 4px; letter-spacing: 0.5px; font-weight: 700;
}
.rom-card-warn { background: #fffbeb; border-color: #f5e6a3; }
.rom-card-warn::before { background: #d97706; }
.rom-card-warn .rom-card-num { color: #b45309; }
.rom-card-info { background: #faf7f4; border-color: #e8dccd; }
.rom-card-info::before { background: #8b7355; }
.rom-card-info .rom-card-num { font-size: 22px; color: #6b3e2c; }

/* Filters */
.rom-list-filters {
  display: flex; gap: 12px; align-items: center; flex-wrap: wrap;
  margin-bottom: 14px;
}
.rom-list-search {
  max-width: 360px; padding: 9px 14px;
  border: 1px solid #e0d5c8; border-radius: 8px;
  font-size: 14px; font-family: inherit; color: #502314; background: white;
}
.rom-list-search:focus { outline: none; border-color: #D62300; }
.rom-list-search::placeholder { color: #a08570; }
.rom-list-status-filters { display: flex; gap: 6px; }
.rom-chip {
  padding: 7px 16px; border-radius: 20px;
  border: 1px solid #e0d5c8;
  background: white; cursor: pointer; font-size: 12px; font-family: inherit;
  color: #502314; transition: all 0.15s; font-weight: 600;
}
.rom-chip:hover { background: #faf0e6; }
.rom-chip.active { background: #502314; color: white; border-color: #502314; }
.rom-chip-warn.active { background: #d97706; border-color: #d97706; color: white; }

/* Table card */
.rom-table-card {
  background: white;
  border: 1px solid #e8dccd;
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 4px 16px rgba(80,35,20,0.06);
}

/* Compact table */
.rom-table.rom-table-compact { border-radius: 0; box-shadow: none; table-layout: fixed; }
.rom-table.rom-table-compact th {
  background: #faf7f4; color: #8b7355; padding: 9px 12px;
  font-size: 11px; font-weight: 700; text-transform: uppercase;
  letter-spacing: 0.4px; border-bottom: 1px solid #e8dccd;
  text-align: center;
}
.rom-table.rom-table-compact th.rom-th-left { text-align: left; }
.rom-table.rom-table-compact td {
  padding: 5px 12px; font-size: 12px; line-height: 1.3;
  white-space: nowrap; border-bottom: 1px solid #f3ebe0;
  text-align: center;
  overflow: hidden; text-overflow: ellipsis;
}
.rom-table-compact tr:last-child td { border-bottom: none; }
.rom-row-clickable { cursor: pointer; }
.rom-row-clickable:hover td { background: #fff5e8 !important; }
.rom-row-submitted td { background: #f6fef9; }
.rom-row-pending td { background: #fffbf0; }
.rom-row-pending.rom-row-clickable:hover td { background: #fff5e8 !important; }
.rom-td-num { font-weight: 700; color: #502314; }
.rom-td-rest {
  text-align: left !important;
}
.rom-cell-rest-city { color: #502314; font-weight: 600; }
.rom-cell-rest-addr { color: #8b7355; }
.rom-td-status { text-align: center; }
.rom-td-volume { color: #502314; font-weight: 500; }
.rom-td-time { color: #8b7355; }
.rom-td-edited { font-size: 11px; color: #8b7355; }
.rom-td-edited-by { color: #502314; font-weight: 600; }
.rom-dim { color: #c0a080; }
.rom-td-actions { text-align: center !important; padding: 2px 10px !important; }
.rom-td-actions .rom-btn-sm { padding: 3px 9px; }

/* Status pills */
.rom-status {
  padding: 3px 10px; border-radius: 12px; font-size: 11px;
  font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px;
  display: inline-block;
}

/* Buttons */
.rom-btn {
  padding: 8px 16px; border-radius: 8px;
  border: 1px solid #e0d5c8;
  background: white; cursor: pointer; font-size: 13px;
  font-family: inherit; color: #502314; font-weight: 600;
  transition: all 0.15s;
}
.rom-btn:hover { background: #faf0e6; border-color: #d4c2a8; }
.rom-btn-primary { background: #D62300; color: white; border-color: #D62300; }
.rom-btn-primary:hover { background: #b81e00; border-color: #b81e00; color: white; }
.rom-btn-outline { border-style: dashed; }
.rom-btn-export { background: white; color: #16a34a; border-color: #c5f0d8; }
.rom-btn-export:hover { background: #ecfdf5; border-color: #16a34a; }
.rom-btn-sm {
  padding: 4px 10px; border-radius: 6px;
  border: 1px solid #e0d5c8;
  background: white; cursor: pointer; font-size: 12px;
  font-family: inherit; color: #502314; font-weight: 500;
}
.rom-btn-sm:hover { background: #faf0e6; border-color: #d4c2a8; }
.rom-btn-danger { color: #dc2626 !important; border-color: #f5cccc !important; }
.rom-btn-danger:hover { background: #fef2f2 !important; border-color: #dc2626 !important; }
.rom-btn-success { color: #16a34a !important; border-color: #c5f0d8 !important; font-weight: 600; }
.rom-btn-success:hover { background: #ecfdf5 !important; border-color: #16a34a !important; }

/* Loading / Empty */
.rom-loading { padding: 40px; text-align: center; color: #8b7355; }
.rom-empty { padding: 40px; text-align: center; color: #8b7355; font-size: 15px; }

/* Table */
.rom-table-wrap { overflow-x: auto; }
.rom-table {
  width: 100%; border-collapse: collapse; background: white;
  border-radius: 10px; overflow: hidden;
}
.rom-table th {
  padding: 10px 12px; font-size: 12px; color: #8b7355;
  text-align: left; border-bottom: 2px solid #e0d5c8;
  background: #faf7f4; font-weight: 600;
}
.rom-table td {
  padding: 8px 12px; border-bottom: 1px solid #f0ebe4;
  font-size: 13px; color: #502314;
}
.rom-td-num { font-weight: 700; }
.rom-td-center { text-align: center; }
.rom-th-center { text-align: center; }
.rom-td-time { font-size: 12px; color: #8b7355; }
.rom-row-submitted { background: #f0fdf4; }
.rom-status {
  padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 600;
}
.rom-comment-icon { cursor: help; font-size: 14px; margin-left: 4px; }
.st-submitted { background: #ecfdf5; color: #16a34a; }
.st-edited { background: #eff6ff; color: #2563eb; }
.st-draft { background: #f5f5f5; color: #666; }
.st-none { background: #fef2f2; color: #dc2626; }

/* Modals */
.rom-modal-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,0.4);
  display: flex; align-items: center; justify-content: center;
  z-index: 1000; padding: 20px;
}
.rom-modal {
  background: white; border-radius: 16px; width: 100%;
  max-width: 500px; max-height: 85vh; overflow-y: auto;
  box-shadow: 0 8px 40px rgba(0,0,0,0.2);
}
.rom-modal-lg { max-width: 900px; }
.rom-modal-fixed {
  display: flex; flex-direction: column; overflow: hidden;
}
.rom-modal-header {
  display: flex; justify-content: space-between; align-items: center;
  padding: 16px 20px; border-bottom: 1px solid #e0d5c8;
  flex-shrink: 0;
}
.rom-modal-header h2 { margin: 0; font-size: 18px; color: #502314; }
.rom-modal-close {
  background: none; border: none; cursor: pointer;
  font-size: 18px; color: #999; padding: 4px;
}
.rom-modal-body { padding: 20px; }
.rom-modal-footer { padding: 16px 0 0; display: flex; align-items: center; gap: 10px; }

/* Фиксированная мета-панель заказа */
.rom-order-bar {
  padding: 14px 20px; border-bottom: 1px solid #e0d5c8;
  background: #faf8f5; flex-shrink: 0;
}
.rom-order-totals-bar {
  display: flex; gap: 20px; margin-top: 8px; font-size: 14px; color: #502314;
  background: #f0ebe4; padding: 8px 14px; border-radius: 8px;
}
.rom-order-totals-bar strong { color: #D62300; }

/* Табы режимов в модалке заказа */
.rom-cat-tabs {
  display: flex; gap: 0; border-bottom: 2px solid #e0d5c8;
  padding: 0 20px; flex-shrink: 0; background: white;
  align-items: center;
}
.rom-edit-search {
  margin-left: auto; margin-bottom: 0; max-width: 280px;
  height: 30px; font-size: 13px;
}
.rom-cat-tab {
  padding: 10px 20px; border: none; background: transparent;
  cursor: pointer; font-size: 14px; font-weight: 600;
  color: #8b7355; border-bottom: 3px solid transparent;
  transition: all 0.15s; font-family: inherit;
  display: flex; align-items: center; gap: 6px;
}
.rom-cat-tab.active { color: #D62300; border-bottom-color: #D62300; }
.rom-cat-tab:hover:not(.active) { color: #502314; }
.rom-cat-tab-count {
  background: #f0ebe4; padding: 1px 7px; border-radius: 10px; font-size: 11px; color: #502314;
}
.rom-cat-tab.active .rom-cat-tab-count { background: rgba(214, 35, 0, 0.1); color: #D62300; }

/* Скроллируемая часть модалки */
.rom-modal-scroll {
  flex: 1; overflow-y: auto; padding: 0 20px 10px;
  min-height: 0;
}

/* Фиксированный подвал */
.rom-modal-footer-fixed {
  padding: 14px 20px; display: flex; align-items: center; gap: 10px;
  border-top: 1px solid #e0d5c8; background: white; flex-shrink: 0;
}

/* Users modal */
.rom-bulk-row { display: flex; gap: 8px; margin-bottom: 8px; align-items: center; }
.rom-input {
  flex: 1; padding: 8px 12px; border: 2px solid #e0d5c8;
  border-radius: 8px; font-size: 14px; font-family: inherit;
}
.rom-users-section {
  padding: 12px 0; border-bottom: 1px solid #f0ebe4;
}
.rom-users-section:last-child { border-bottom: none; }
.rom-users-section-title {
  font-weight: 700; color: #502314; font-size: 14px; margin-bottom: 8px;
  display: flex; align-items: center;
}
.rom-users-hint { font-size: 12px; color: #8b7355; margin-bottom: 4px; }
.rom-users-info { color: #16a34a; font-size: 13px; margin-top: 6px; }
.rom-users-list { max-height: 360px; overflow-y: auto; }
.rom-user-row {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 0; border-bottom: 1px solid #f0ebe4; font-size: 13px;
}
.rom-user-num { font-weight: 700; min-width: 44px; color: #502314; }
.rom-user-addr { flex: 1; color: #8b7355; min-width: 0; display: flex; flex-direction: column; }
.rom-user-addr-main { font-size: 13px; color: #502314; }
.rom-user-addr-le { font-size: 11px; color: #a08570; }
.rom-user-status { font-size: 11px; padding: 2px 8px; border-radius: 4px; background: #fef2f2; color: #b91c1c; }
.rom-user-status.active { background: #ecfdf5; color: #16a34a; }
.rom-user-pwd-status { font-size: 11px; padding: 3px 10px; border-radius: 12px; font-weight: 600; min-width: 90px; text-align: center; }
.rom-user-pwd-status.ok { background: #ecfdf5; color: #16a34a; }
.rom-user-pwd-status.nopwd { background: #fef3c7; color: #b45309; }
.rom-user-pwd-status.off { background: #fef2f2; color: #b91c1c; }
.rom-user-login { font-size: 11px; color: #8b7355; min-width: 110px; text-align: right; }
.rom-user-toggle-placeholder { display: inline-block; min-width: 90px; }

.rom-users-summary { display: flex; gap: 10px; margin-bottom: 14px; }
.rom-users-summary-item {
  flex: 1; padding: 10px; border-radius: 10px; background: #f8f4ef;
  border: 1px solid #e8dccd; display: flex; flex-direction: column; align-items: center; gap: 2px;
}
.rom-users-summary-item.ok { background: #ecfdf5; border-color: #c5f0d8; }
.rom-users-summary-item.warn { background: #fef9e7; border-color: #f5e6a3; }
.rom-users-summary-item.off { background: #fef2f2; border-color: #f5cccc; }
.rom-users-summary-num { font-size: 22px; font-weight: 700; color: #502314; }
.rom-users-summary-label { font-size: 11px; color: #8b7355; text-transform: lowercase; }

.rom-users-filters { display: flex; gap: 8px; margin-bottom: 10px; }
.rom-users-filters .rom-input { flex: 1; }
.rom-users-filters .rom-select { min-width: 200px; }

/* Templates */
.rom-tpl-tabs { display: flex; gap: 8px; margin-bottom: 12px; }
.rom-tpl-tab {
  padding: 6px 14px; border-radius: 8px; border: 1px solid #e0d5c8;
  background: white; cursor: pointer; font-size: 13px; font-family: inherit;
}
.rom-tpl-tab.active { background: #D62300; color: white; border-color: #D62300; }
.rom-tpl-actions { display: flex; gap: 8px; margin-bottom: 12px; align-items: center; }
.rom-select { padding: 6px 10px; border: 1px solid #e0d5c8; border-radius: 6px; font-size: 13px; font-family: inherit; }
.rom-tpl-list { max-height: 400px; overflow-y: auto; }
.rom-tpl-item {
  display: flex; align-items: center; gap: 10px;
  padding: 6px 8px; border-bottom: 1px solid #f0ebe4; font-size: 13px;
}
.rom-tpl-sku { color: #8b7355; font-size: 11px; min-width: 70px; }
.rom-tpl-name { flex: 1; color: #502314; }
.rom-tpl-info { margin-top: 12px; color: #16a34a; font-size: 13px; }
.rom-no-items { padding: 20px; text-align: center; color: #8b7355; font-size: 13px; }

/* Order edit */
.rom-order-meta { display: flex; gap: 20px; margin-bottom: 16px; font-size: 14px; color: #502314; flex-wrap: wrap; align-items: center; }
.rom-meta-edited { font-size: 12px; color: #8b7355; }
.rom-date-editable { cursor: pointer; border-bottom: 1px dashed #8b7355; }
.rom-date-editable:hover { color: #D62300; border-color: #D62300; }
.rom-date-edit { display: inline-flex; align-items: center; gap: 6px; }
.rom-input-date { padding: 4px 8px; border: 1.5px solid #e0dbd5; border-radius: 6px; font-size: 13px; font-family: inherit; }
.rom-cat-title { font-size: 14px; color: #D62300; margin: 16px 0 8px; }
.rom-table-edit th { text-align: center; }
.rom-table-edit th:first-child { text-align: left; }
.rom-table-edit td { padding: 4px 8px; text-align: left; }
.rom-table-edit td:nth-child(2),
.rom-table-edit td:nth-child(3) { text-align: center; }
.rom-edit-qty {
  width: 70px; padding: 4px 6px; border: 1px solid #e0d5c8;
  border-radius: 6px; font-size: 13px; text-align: center;
}
.rom-edit-qty-warn { border-color: #d97706; background: #fff7ed; color: #9a3412; }
.rom-edit-mult-hint { margin-top: 4px; font-size: 11px; color: #d97706; text-align: center; }
.rom-edit-warning-box {
  margin: 10px 18px 0;
  padding: 10px 12px;
  border: 1px solid #fbd38d;
  border-radius: 10px;
  background: #fff7ed;
  color: #9a3412;
  font-size: 13px;
}
.rom-edit-comment {
  width: 100%; padding: 4px 6px; border: 1px solid #e0d5c8;
  border-radius: 6px; font-size: 12px;
}

/* Page tabs */
.rom-page-tabs {
  display: flex; gap: 0; margin-bottom: 16px;
  border-bottom: 2px solid #e0d5c8;
}
.rom-page-tab {
  padding: 10px 24px; border: none; background: transparent;
  cursor: pointer; font-size: 15px; font-weight: 600;
  color: #8b7355; border-bottom: 3px solid transparent;
  transition: all 0.2s; font-family: inherit;
}
.rom-page-tab.active { color: #D62300; border-bottom-color: #D62300; }
.rom-page-tab:hover { color: #502314; }
.rom-page-tab-link {
  text-decoration: none; margin-left: auto;
  display: inline-flex; align-items: center; gap: 4px;
}

/* Универсальная панель-карточка для вкладок */
.rom-panel {
  background: white;
  border: 1px solid #e8dccd;
  border-radius: 12px;
  padding: 16px 18px;
  box-shadow: 0 4px 16px rgba(80,35,20,0.06);
}

/* Template page */
.rom-tpl-page { }
.rom-tpl-toolbar {
  display: flex; justify-content: space-between; align-items: center;
  margin-bottom: 12px; flex-wrap: wrap; gap: 10px;
}
.rom-tpl-tabs-inline { display: flex; gap: 6px; }
.rom-tpl-tab {
  padding: 8px 16px; border-radius: 8px; border: 2px solid #e0d5c8;
  background: white; cursor: pointer; font-size: 13px; font-family: inherit;
  font-weight: 600; color: #502314; transition: all 0.2s;
  display: flex; align-items: center; gap: 6px;
}
.rom-tpl-tab.active { background: #D62300; color: white; border-color: #D62300; }
.rom-tpl-tab-count {
  background: rgba(0,0,0,0.1); padding: 1px 7px; border-radius: 10px; font-size: 11px;
}
.rom-tpl-tab.active .rom-tpl-tab-count { background: rgba(255,255,255,0.3); }
.rom-tpl-toolbar-right { display: flex; gap: 8px; flex-wrap: wrap; }
.rom-tpl-filter {
  display: flex; gap: 8px; margin-bottom: 12px;
}
.rom-tpl-filter-input { flex: 1; }
.rom-tpl-msg {
  padding: 8px 14px; border-radius: 8px; font-size: 13px;
  margin-bottom: 12px; background: #fef2f2; color: #dc2626;
}
.rom-tpl-msg.success { background: #ecfdf5; color: #16a34a; }
.rom-unmatched {
  border: 1px solid #fbbf24; background: #fffbeb; border-radius: 8px;
  margin-bottom: 12px; overflow: hidden;
}
.rom-unmatched-header {
  display: flex; align-items: center; gap: 8px; padding: 8px 14px;
  cursor: pointer; user-select: none; font-size: 13px; color: #92400e;
}
.rom-unmatched-header:hover { background: #fef3c7; }
.rom-unmatched-arrow { width: 12px; font-size: 10px; }
.rom-unmatched-hint { color: #a16207; font-size: 12px; margin-left: 4px; }
.rom-unmatched-copy {
  margin-left: auto; padding: 4px 10px; border: 1px solid #fbbf24;
  background: #fff; color: #92400e; border-radius: 6px; font-size: 12px;
  cursor: pointer;
}
.rom-unmatched-copy:hover { background: #fef3c7; }
.rom-unmatched-body { max-height: 320px; overflow-y: auto; border-top: 1px solid #fde68a; }
.rom-unmatched-table {
  width: 100%; border-collapse: collapse; font-size: 12px;
}
.rom-unmatched-table th, .rom-unmatched-table td {
  padding: 4px 10px; border-bottom: 1px solid #fde68a; text-align: left;
}
.rom-unmatched-table th {
  background: #fef3c7; color: #92400e; font-weight: 600;
  position: sticky; top: 0;
}
.rom-unmatched-table td.mono { font-family: monospace; }
.rom-unmatched-table td.num { text-align: right; }
.rom-sku-label { font-size: 11px; color: #8b7355; margin-right: 4px; }
.rom-tpl-mult-input {
  width: 60px; padding: 4px 6px; border: 1px solid #e0d5c8;
  border-radius: 6px; font-size: 13px; text-align: center;
}
.rom-tpl-mult-input:focus { outline: none; border-color: #D62300; }

/* Add to template */
.rom-tpl-add-row {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 12px; border-radius: 8px; cursor: pointer;
  border: 1px solid #f0ebe4; margin-bottom: 4px;
  font-size: 13px; transition: all 0.15s;
}
.rom-tpl-add-row:hover { background: #f5f0eb; border-color: #D62300; }
.rom-add-cat { font-size: 11px; color: #8b7355; background: #f5f0eb; padding: 2px 8px; border-radius: 4px; }
.rom-mult-badge { background: #eff6ff; color: #2563eb; font-size: 11px; padding: 2px 6px; border-radius: 4px; font-weight: 600; }

/* Order edit: clickable product name */
.rom-edit-product {
  cursor: pointer; transition: background 0.15s; border-radius: 4px; padding: 4px 6px;
}
.rom-edit-product:hover { background: #f5f0eb; }
.rom-edit-sku { color: #8b7355; font-size: 11px; font-family: monospace; margin-right: 4px; }

/* Add item button under category */
.rom-btn-add-item {
  margin-top: 6px; color: #16a34a; border-color: #16a34a;
}
.rom-btn-add-item:hover { background: #f0fdf4; }

/* Quick export button in table */
.rom-td-actions { display: flex; gap: 4px; }
.rom-btn-export-sm { color: #16a34a; border-color: #16a34a; }
.rom-btn-export-sm:hover { background: #f0fdf4; }

/* Unified export modal */
.rom-exp-modal { max-width: 900px; }
.rom-exp-body {
  display: flex; gap: 18px; padding: 18px;
  max-height: calc(85vh - 120px); overflow: hidden;
}
.rom-exp-col { flex: 1; min-width: 0; overflow-y: auto; padding-right: 4px; }
.rom-exp-col-left { flex: 0 0 360px; border-right: 1px solid #f3ebe0; padding-right: 18px; }
.rom-exp-col-right { flex: 1; }

.rom-exp-block { margin-bottom: 18px; }
.rom-exp-block:last-child { margin-bottom: 0; }
.rom-exp-block-title {
  font-size: 11px; font-weight: 700; color: #8b7355;
  text-transform: uppercase; letter-spacing: 0.5px;
  margin-bottom: 10px; display: flex; align-items: center; justify-content: space-between;
}
.rom-exp-clickable { cursor: pointer; user-select: none; }
.rom-exp-clickable:hover { color: #502314; }
.rom-exp-chevron { font-size: 12px; transition: transform 0.2s; }
.rom-exp-chevron.open { transform: rotate(180deg); }

.rom-exp-grouping { display: flex; flex-direction: column; gap: 8px; }
.rom-exp-grouping-opt {
  padding: 10px 14px; border-radius: 10px; border: 1px solid #e0d5c8;
  background: white; cursor: pointer; font-family: inherit;
  text-align: left; transition: all 0.15s;
}
.rom-exp-grouping-opt:hover { background: #faf0e6; border-color: #d4c2a8; }
.rom-exp-grouping-opt.active {
  background: #fff5e8; border-color: #D62300;
  box-shadow: 0 0 0 2px rgba(214,35,0,0.12);
}
.rom-exp-grouping-name { font-size: 13px; font-weight: 700; color: #502314; }
.rom-exp-grouping-hint { font-size: 11px; color: #8b7355; margin-top: 2px; }

.rom-exp-filters { padding-top: 6px; }
.rom-exp-filter-group { margin-bottom: 12px; padding: 10px; background: #faf7f4; border-radius: 8px; }
.rom-exp-filter-label { font-size: 11px; font-weight: 700; color: #8b7355; margin-bottom: 6px; display: block; text-transform: uppercase; letter-spacing: 0.4px; }
.rom-exp-checkboxes { display: flex; gap: 16px; flex-wrap: wrap; align-items: center; }
.rom-exp-cb-label {
  display: flex; align-items: center; gap: 6px;
  font-size: 13px; color: #502314; cursor: pointer; line-height: 1.4;
}
.rom-exp-select-list { max-height: 200px; overflow-y: auto; border: 1px solid #e8dccd; border-radius: 8px; background: white; }
.rom-exp-select-list-tall { max-height: 280px; }
.rom-exp-select-item {
  display: flex; align-items: center; gap: 8px;
  padding: 7px 10px; border-bottom: 1px solid #f3eeea; cursor: pointer; font-size: 12px;
}
.rom-exp-select-item:last-child { border-bottom: none; }
.rom-exp-select-item:hover { background: #faf7f4; }
.rom-exp-select-item.selected { background: #f0fdf4; }

/* Колонки */
.rom-exp-presets { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
.rom-exp-preset-btn {
  padding: 6px 12px; border-radius: 16px; border: 1px solid #e0d5c8;
  background: white; cursor: pointer; font-size: 11px; font-family: inherit;
  color: #502314; font-weight: 600; transition: all 0.15s;
}
.rom-exp-preset-btn:hover { background: #fff5e8; border-color: #D62300; color: #D62300; }
.rom-exp-cols-hint { font-size: 11px; color: #8b7355; margin-bottom: 8px; line-height: 1.4; }
.rom-exp-cols-list {
  border: 1px solid #e8dccd; border-radius: 10px;
  background: white; max-height: 420px; overflow-y: auto;
}
.rom-exp-col-row {
  display: flex; align-items: center; gap: 8px;
  padding: 7px 10px; border-bottom: 1px solid #f3eeea;
  font-size: 13px;
}
.rom-exp-col-row:last-child { border-bottom: none; }
.rom-exp-col-row:hover { background: #faf7f4; }
.rom-exp-col-row.selected { background: #f0fdf4; }
.rom-exp-col-cb {
  display: flex; align-items: center; gap: 8px; flex: 1; cursor: pointer; min-width: 0;
}
.rom-exp-col-name { color: #502314; font-weight: 500; }
.rom-exp-col-row.selected .rom-exp-col-name { font-weight: 600; }
.rom-exp-col-pos {
  background: #502314; color: white; font-size: 10px; font-weight: 700;
  width: 20px; height: 20px; border-radius: 10px;
  display: inline-flex; align-items: center; justify-content: center;
  margin-left: auto;
}
.rom-exp-col-arrows { display: flex; gap: 2px; }
.rom-exp-arrow {
  width: 22px; height: 22px; padding: 0;
  border: 1px solid #e0d5c8; border-radius: 4px;
  background: white; cursor: pointer; font-size: 11px;
  display: inline-flex; align-items: center; justify-content: center;
  color: #502314;
}
.rom-exp-arrow:hover:not(:disabled) { background: #fff5e8; border-color: #D62300; color: #D62300; }
.rom-exp-arrow:disabled { opacity: 0.3; cursor: not-allowed; }

.rom-exp-footer {
  display: flex; justify-content: space-between; align-items: center;
  padding: 14px 18px; border-top: 1px solid #e8dccd; background: #faf7f4;
  border-radius: 0 0 16px 16px;
}
.rom-exp-summary { font-size: 13px; color: #502314; }
.rom-exp-summary strong { color: #D62300; }

.rom-td-weight { font-size: 12px; color: #8b7355; }
.rom-cat-summary {
  display: flex; gap: 12px; align-items: center;
  padding: 6px 10px; margin-top: 4px;
  font-size: 12px; color: #8b7355; background: #faf7f4; border-radius: 6px;
}
.rom-cat-pallets {
  font-weight: 700; color: #502314;
  background: #ede8e3; padding: 2px 8px; border-radius: 4px;
}

/* Totals row */
.rom-totals-row td {
  padding: 10px 12px !important; background: #faf7f4;
  border-top: 2px solid #e0d5c8; font-size: 13px; color: #502314;
}

/* Deadline modal */
.rom-deadline-fields { display: flex; flex-direction: column; gap: 12px; }
.rom-deadline-label { display: flex; flex-direction: column; gap: 4px; font-size: 13px; color: #502314; font-weight: 600; }
.rom-deadline-label input[type="time"] { width: 140px; }

/* Stock balances tab */
.rom-stock-toolbar { display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 12px; }
.rom-stock-field label { display: block; font-size: 12px; font-weight: 600; color: #502314; margin-bottom: 4px; }
.rom-stock-field select, .rom-stock-field input[type="date"] { min-width: 160px; }
.rom-stock-upload { align-self: flex-end; }
.rom-stock-filter-row { display: flex; gap: 12px; align-items: center; margin-bottom: 12px; flex-wrap: wrap; }
.rom-stock-checkbox { font-size: 13px; color: #502314; white-space: nowrap; cursor: pointer; }
.rom-stock-checkbox input { margin-right: 4px; }
.rom-stock-summary { font-size: 12px; color: #8b7355; white-space: nowrap; }
.rom-row-warning { background: #fff8e7; }
.rom-stock-error { color: #b45309; font-weight: 700; }
.rom-th-right { text-align: right; }
.rom-td-right { text-align: right; font-variant-numeric: tabular-nums; }
.rom-row-deficit { background: #f3f4f6 !important; }
.rom-row-deficit td { color: #6b7280 !important; }
.rom-deficit { font-weight: 700; color: #dc2626 !important; }
.rom-td-remaining { font-weight: 600; }

/* ═══ Audit log (Журнал) ═══ */
.rom-audit-wrap { padding: 0; }
.rom-audit-filters {
  display: flex; flex-wrap: wrap; align-items: center; gap: 6px;
  padding: 10px 12px; background: #faf7f4; border: 1px solid #EDE8E3;
  border-radius: 10px; margin-bottom: 10px;
}
.rom-audit-filters .rom-input-sm { padding: 5px 8px; font-size: 12px; }
.rom-audit-filter-label { font-size: 12px; color: #6b5d4c; font-weight: 600; }
.rom-audit-auto { display: flex; align-items: center; gap: 4px; font-size: 12px; color: #6b5d4c; cursor: pointer; user-select: none; }

.rom-audit-stats { font-size: 12px; color: #8b7355; padding: 0 4px 8px; }

.rom-audit-empty { text-align: center; padding: 40px; color: #9ca3af; font-size: 13px; }

.rom-audit-list { display: flex; flex-direction: column; gap: 4px; }
.rom-audit-row {
  display: grid;
  grid-template-columns: 60px 30px 1fr 30px;
  gap: 10px; align-items: start;
  padding: 10px 12px; background: white;
  border: 1px solid #EDE8E3; border-left: 3px solid #d1d5db;
  border-radius: 8px; transition: background 0.1s;
}
.rom-audit-row:hover { background: #faf7f4; }
.rom-audit-row.act-order_created { border-left-color: #16a34a; }
.rom-audit-row.act-order_updated { border-left-color: #f59e0b; }
.rom-audit-row.act-order_deleted { border-left-color: #dc2626; }
.rom-audit-row.act-item_added { border-left-color: #16a34a; }
.rom-audit-row.act-item_changed { border-left-color: #f59e0b; }
.rom-audit-row.act-item_deleted { border-left-color: #dc2626; }
.rom-audit-row.act-status_changed { border-left-color: #3b82f6; }
.rom-audit-row.act-delivery_date_changed { border-left-color: #8b5cf6; }

.rom-audit-time { text-align: center; color: #8b7355; font-size: 11px; line-height: 1.2; padding-top: 1px; }
.rom-audit-date { font-weight: 700; color: #502314; font-size: 12px; }
.rom-audit-clock { font-variant-numeric: tabular-nums; }

.rom-audit-icon {
  width: 28px; height: 28px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 14px; background: #f3f4f6;
}

.rom-audit-body { min-width: 0; }
.rom-audit-head { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 3px; font-size: 12px; }
.rom-audit-action { font-weight: 700; color: #502314; }
.rom-audit-actor {
  padding: 1px 7px; border-radius: 10px; font-size: 11px; font-weight: 600;
  background: #f3f4f6; color: #374151;
}
.rom-audit-actor.actor-restaurant { background: #dbeafe; color: #1e40af; }
.rom-audit-actor.actor-admin { background: #fef3c7; color: #92400e; }
.rom-audit-actor.actor-bot { background: #ede9fe; color: #6d28d9; }
.rom-audit-rest { font-size: 11px; color: #6b7280; font-weight: 600; }
.rom-audit-deliv { font-size: 11px; color: #6b7280; }

.rom-audit-detail { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; font-size: 12px; color: #4b5563; }
.rom-audit-sku { font-family: monospace; font-size: 11px; color: #6b7280; background: #f3f4f6; padding: 1px 5px; border-radius: 3px; }
.rom-audit-arrow { font-variant-numeric: tabular-nums; }
.rom-audit-arrow b { color: #502314; }
.rom-audit-del { color: #dc2626; font-size: 11px; }
.rom-audit-add { color: #16a34a; font-weight: 700; font-variant-numeric: tabular-nums; }

.rom-audit-diff-list { margin-top: 4px; display: flex; flex-direction: column; gap: 2px; padding-left: 0; }
.rom-audit-diff-row {
  display: flex; flex-wrap: wrap; align-items: center; gap: 6px;
  font-size: 12px; color: #4b5563;
  padding: 2px 6px; border-radius: 4px;
  border-left: 2px solid transparent;
}
.rom-audit-diff-row.diff-added { background: #f0fdf4; border-left-color: #16a34a; }
.rom-audit-diff-row.diff-changed { background: #fffbeb; border-left-color: #f59e0b; }
.rom-audit-diff-row.diff-removed { background: #fef2f2; border-left-color: #dc2626; }
.rom-audit-diff-mark { font-weight: 700; width: 12px; text-align: center; }
.diff-added .rom-audit-diff-mark { color: #16a34a; }
.diff-changed .rom-audit-diff-mark { color: #f59e0b; }
.diff-removed .rom-audit-diff-mark { color: #dc2626; }

.rom-audit-details-btn {
  background: none; border: none; color: #8b7355; cursor: pointer;
  font-size: 11px; padding: 0 4px; text-decoration: underline;
}
.rom-audit-details-btn:hover { color: #D62300; }
.rom-audit-details {
  grid-column: 3 / 5; margin: 6px 0 0; padding: 8px 10px;
  background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px;
  font-size: 11px; color: #374151; font-family: monospace;
  white-space: pre-wrap; word-break: break-word; max-height: 240px; overflow: auto;
}

.rom-audit-goto {
  background: none; border: 1px solid #e5e7eb; border-radius: 6px;
  width: 28px; height: 28px; cursor: pointer; color: #6b7280; font-size: 14px;
  display: flex; align-items: center; justify-content: center;
}
.rom-audit-goto:hover { border-color: #D62300; color: #D62300; background: #fff8f0; }

.rom-audit-more { text-align: center; padding: 16px 0; display: flex; flex-direction: column; align-items: center; gap: 6px; }
.rom-audit-more-hint { font-size: 11px; color: #9ca3af; }

@media (max-width: 700px) {
  .rom-audit-row { grid-template-columns: 48px 26px 1fr; }
  .rom-audit-goto { grid-column: 3; justify-self: end; margin-top: 4px; }
}
</style>
