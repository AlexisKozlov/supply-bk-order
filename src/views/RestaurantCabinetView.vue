<template>
  <div class="cab" :class="cabBrand.themeClass">
    <!-- ══════ Sidebar ══════ -->
    <aside class="cab-sidebar">
      <div class="sb-brand">
        <div class="sb-logo">
          <svg width="26" height="26" viewBox="5 5 38 38" xmlns="http://www.w3.org/2000/svg" fill="none">
            <circle cx="16" cy="16" r="10" fill="#E76F51"/><circle cx="32" cy="16" r="10" fill="#F4A261"/>
            <circle cx="16" cy="32" r="10" fill="#F4A261"/><circle cx="32" cy="32" r="10" fill="#FFD54F"/>
            <circle cx="24" cy="24" r="8.5" fill="#502314"/>
            <text x="24" y="29" text-anchor="middle" fill="white" font-size="14" font-weight="900" font-family="Arial, sans-serif">{{ cabBrand.logoLetter }}</text>
          </svg>
        </div>
        <div>
          <div class="sb-brand-text">{{ cabBrand.title }}</div>
          <div class="sb-brand-sub">{{ cabBrand.subtitle }}</div>
        </div>
      </div>

      <div class="cab-sb-scroll">
      <button class="sb-item" :class="{ active: activeTab === 'dashboard' }" @click="switchTab('dashboard')">
        <span class="sb-icon" v-html="cabIconSvg.dashboard"></span>
        Главная
      </button>

      <div class="sb-label">Заказы</div>
      <!-- Основная поставка -->
      <button v-if="roStore.restaurantOrdersEnabled" class="sb-item" :class="{ active: activeTab === 'orders' && orderSubTab === 'delivery' }"
        @click="switchTab('orders', 'delivery')">
        <span class="sb-icon" v-html="cabIconSvg.orders"></span>
        Основная поставка
        <span v-if="deliveryBadge" class="sb-badge" :class="deliveryBadge.type">{{ deliveryBadge.text }}</span>
      </button>
      <!-- Поставщики (Камако и др.) -->
      <button v-for="sup in suppliers" :key="'sb-'+sup.id" class="sb-item"
        :class="{ active: activeTab === 'orders' && orderSubTab === 'sup_' + sup.id }"
        @click="switchTab('orders', 'sup_' + sup.id)">
        <span class="supplier-icon supplier-icon-sm" :class="supplierIcon(sup.name).className" v-html="supplierIcon(sup.name).svg"></span>
        {{ sup.name }}
        <span v-if="supplierBadge(sup)" class="sb-badge" :class="supplierBadge(sup).type">{{ supplierBadge(sup).text }}</span>
      </button>
      <a
        v-for="link in externalSupplierLinks"
        :key="'sb-ext-' + link.id"
        class="sb-item sb-item-link"
        :href="safeExternalUrl(link.url)"
        target="_blank"
        rel="noopener noreferrer"
      >
        <span class="supplier-icon supplier-icon-sm" :class="link.iconClass" v-html="trustedSupplierIcon(link.iconKey)"></span>
        {{ link.name }}
        <span class="sb-ext" v-html="cabIconSvg.external"></span>
      </a>
      <!-- Корректировки основной поставки -->
      <button class="sb-item"
        :class="{ active: activeTab === 'orders' && orderSubTab === 'corrections' }"
        @click="switchTab('orders', 'corrections')">
        <span class="sb-icon" v-html="cabIconSvg.corrections"></span>
        Корректировки
        <span class="sb-beta">BETA</span>
      </button>
      <!-- Сбор заказа основной поставки (помощник). Для Пицца Стар не применяется — у них нет 1С УТ. -->
      <button v-if="!isPizzaStarCabinet" class="sb-item"
        :class="{ active: activeTab === 'orders' && orderSubTab === 'assistant' }"
        @click="switchTab('orders', 'assistant')">
        <span class="sb-icon" v-html="cabIconSvg.orders"></span>
        Сбор заказа
        <span class="sb-beta">BETA</span>
      </button>
      <!-- История заказов -->
      <button class="sb-item"
        :class="{ active: activeTab === 'orders' && orderSubTab === 'history' }"
        @click="switchTab('orders', 'history')">
        <span class="sb-icon" v-html="cabIconSvg.history"></span>
        История заказов
      </button>
      <!-- Напоминания о подаче заявок -->
      <button class="sb-item"
        :class="{ active: activeTab === 'orders' && orderSubTab === 'reminders' }"
        @click="switchTab('orders', 'reminders')">
        <span class="sb-icon" v-html="cabIconSvg.reminders"></span>
        Напоминания
        <span class="sb-beta">BETA</span>
      </button>

      <div class="sb-label">Другое</div>
      <template v-for="tab in mainTabs.filter(t => t.id !== 'dashboard' && t.id !== 'orders')" :key="tab.id">
        <button class="sb-item" :class="{ active: activeTab === tab.id }" @click="switchTab(tab.id)">
          <span class="sb-icon" v-html="tabIconSvg(tab.id)"></span>
          {{ tab.label }}
          <span v-if="tab.beta" class="sb-beta">BETA</span>
          <span v-if="tab.badge" class="sb-badge" :class="tab.badgeType">{{ tab.badge }}</span>
        </button>
      </template>
      <router-link v-if="canUseCardSearch" :to="{ name: 'search-cards' }" target="_blank" class="sb-item sb-item-link">
        <span class="sb-icon" v-html="cabIconSvg.search"></span>
        Поиск карточек
        <span class="sb-item-ext" title="Откроется в новой вкладке" v-html="cabIconSvg.external"></span>
      </router-link>

      </div>
      <div class="cab-sb-footer">
      <a href="https://t.me/alexiskozlov" target="_blank" rel="noopener noreferrer" class="sb-help">
        <span class="sb-help-icon" v-html="cabIconSvg.help"></span>
        <span>Помощь</span>
        <span class="sb-item-ext" title="Откроется в Telegram" v-html="cabIconSvg.external"></span>
      </a>
      <div class="sb-rest" :class="{ active: activeTab === 'profile' }">
        <button class="sb-rest-main" @click="switchTab('profile')" title="Открыть профиль">
          <div class="sb-avatar">{{ formatRestaurantNumber(roStore.restaurant?.number, roStore.restaurant?.legal_entity_group) }}</div>
          <div class="sb-rest-info">
            <div class="sb-rest-name">Ресторан {{ formatRestaurantNumber(roStore.restaurant?.number, roStore.restaurant?.legal_entity_group) }}</div>
            <div class="sb-rest-addr">{{ restaurantAddress }}</div>
          </div>
        </button>
        <button class="sb-rest-logout" @click="handleLogout" title="Выйти из аккаунта">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
            <polyline points="16 17 21 12 16 7"/>
            <line x1="21" y1="12" x2="9" y2="12"/>
          </svg>
        </button>
      </div>
      </div>
    </aside>

    <!-- ══════ Main ══════ -->
    <div class="cab-main">
      <!-- Десктоп-топбар -->
      <div class="cab-topbar">
        <div>
          <div class="cab-topbar-title">{{ activeTab === 'dashboard' ? 'Главная' : activeTab === 'orders' ? 'Заказы' : activeTab === 'info' ? 'Важная информация' : activeTab === 'surveys' ? 'Опросы' : activeTab === 'stock' ? 'Сбор остатков' : activeTab === 'warehouse-stock' ? 'Остатки склада' : activeTab === 'scanner' ? 'Сканер товаров' : activeTab === 'keg-returns' ? 'Возврат кег' : 'Профиль' }}</div>
          <div class="cab-topbar-sub">Ресторан {{ formatRestaurantNumber(roStore.restaurant?.number, roStore.restaurant?.legal_entity_group) }} · {{ restaurantAddress }}</div>
        </div>
        <button v-if="activeTab !== 'scanner'" class="cab-topbar-scan" @click="switchTab('scanner')" title="Сканер товаров (BETA)">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7V5a2 2 0 012-2h2"/><path d="M17 3h2a2 2 0 012 2v2"/><path d="M21 17v2a2 2 0 01-2 2h-2"/><path d="M7 21H5a2 2 0 01-2-2v-2"/><line x1="7" y1="8" x2="7" y2="16"/><line x1="11" y1="8" x2="11" y2="16"/><line x1="15" y1="8" x2="15" y2="16"/><line x1="17" y1="8" x2="17" y2="16"/></svg>
          <span class="cab-topbar-scan-beta">BETA</span>
        </button>
      </div>

      <!-- Мобильный топбар: компактный, sticky -->
      <div class="mob-topbar">
        <div class="mob-topbar-rest">
          <span class="mob-topbar-num">{{ formatRestaurantNumber(roStore.restaurant?.number, roStore.restaurant?.legal_entity_group) }}</span>
          <span class="mob-topbar-label">Ресторан</span>
        </div>
        <div class="mob-topbar-screen">{{ activeTab === 'dashboard' ? 'Главная' : activeTab === 'orders' ? 'Заказы' : activeTab === 'info' ? 'Важная информация' : activeTab === 'surveys' ? 'Опросы' : activeTab === 'stock' ? 'Сбор остатков' : activeTab === 'warehouse-stock' ? 'Остатки склада' : activeTab === 'scanner' ? 'Сканер товаров' : activeTab === 'keg-returns' ? 'Возврат кег' : 'Профиль' }}</div>
        <button
          v-if="activeTab !== 'scanner'"
          class="mob-topbar-scan"
          @click="switchTab('scanner')"
          title="Сканер товаров"
          aria-label="Открыть сканер"
        >
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7V5a2 2 0 012-2h2"/><path d="M17 3h2a2 2 0 012 2v2"/><path d="M21 17v2a2 2 0 01-2 2h-2"/><path d="M7 21H5a2 2 0 01-2-2v-2"/><line x1="7" y1="8" x2="7" y2="16"/><line x1="11" y1="8" x2="11" y2="16"/><line x1="15" y1="8" x2="15" y2="16"/><line x1="17" y1="8" x2="17" y2="16"/></svg>
        </button>
      </div>

    <!-- ══════ Global error ══════ -->
    <section v-if="globalError" class="cab-section">
      <div class="cab-empty-card">
        <h2>Не удалось открыть кабинет</h2>
        <p>{{ globalError }}</p>
        <button class="btn btn-primary" @click="retryCabinetLoad">Повторить</button>
      </div>
    </section>

    <!-- ══════ TAB: Дашборд ══════ -->
    <section v-if="activeTab === 'dashboard' && !globalError" class="cab-section">
      <!-- Skeleton, пока первичная загрузка не завершена -->
      <div v-if="globalLoading" class="dash-wrap dash-skeleton" aria-busy="true">
        <div class="dash-col-main">
          <div class="sk-block sk-shimmer sk-h-72"></div>
          <div class="sk-grid">
            <div class="sk-block sk-shimmer sk-h-88"></div>
            <div class="sk-block sk-shimmer sk-h-88"></div>
          </div>
          <div class="sk-tiles">
            <div class="sk-block sk-shimmer sk-h-72"></div>
            <div class="sk-block sk-shimmer sk-h-72"></div>
            <div class="sk-block sk-shimmer sk-h-72"></div>
          </div>
          <div class="sk-list">
            <div class="sk-block sk-shimmer sk-h-44"></div>
            <div class="sk-block sk-shimmer sk-h-44"></div>
            <div class="sk-block sk-shimmer sk-h-44"></div>
          </div>
        </div>
        <div class="dash-col-side">
          <div class="sk-block sk-shimmer sk-h-220"></div>
        </div>
      </div>
      <!-- Реальный дашборд (после загрузки) -->
      <div v-if="!globalLoading" class="dash-wrap">
        <div class="dash-col-main">
          <!-- PWA push онбординг — баннер при первом открытии как «приложение» -->
          <div v-if="showPushOnboarding" class="dash-push-onboard">
            <div class="dash-push-onboard-icon">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0 1 12 0v5l1.5 2.5h-15L6 14V9Z"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>
            </div>
            <div class="dash-push-onboard-text">
              <strong>Включить уведомления?</strong>
              <span>Будем напоминать о дедлайнах прямо на этом устройстве, даже когда сайт закрыт.</span>
            </div>
            <button class="dash-push-onboard-btn" :disabled="push.busy.value" @click="enablePushOnboarding">Включить</button>
            <button class="dash-push-onboard-skip" @click="dismissPushOnboarding" aria-label="Не сейчас">×</button>
          </div>

          <!-- Сводка «Сегодня нужно сделать» -->
          <div v-if="todaySignals.length" class="dash-today">
            <div class="dash-today-head">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0 1 12 0v5l1.5 2.5h-15L6 14V9Z"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>
              <h3>Сегодня нужно сделать</h3>
            </div>
            <ul class="dash-today-list">
              <li v-for="s in todaySignals" :key="s.key" class="dash-today-item" :class="'is-' + s.tone" @click="s.action">
                <span class="dash-today-num">{{ s.count }}</span>
                <span class="dash-today-text">{{ s.label }}</span>
                <svg class="dash-today-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
              </li>
            </ul>
          </div>

          <!-- Напоминания на сегодня -->
          <RestaurantTodayReminders />

          <!-- Срочные карточки -->
          <div v-if="urgentItems.length" class="dash-urgent">
            <div v-for="item in urgentItems" :key="item.key" class="dash-card" :class="'dash-card--' + item.type" @click="item.action">
              <div class="dash-card-icon" v-html="item.icon"></div>
              <div class="dash-card-body">
                <div class="dash-card-title">{{ item.title }}</div>
                <div class="dash-card-sub">{{ item.subtitle }}</div>
              </div>
              <div v-if="item.countdown" class="dash-card-time">{{ item.countdown }}</div>
              <svg class="dash-card-arrow" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
            </div>
          </div>

          <!-- Сводка -->
          <div class="dash-grid">
            <div class="dash-stat" @click="switchTab('orders')">
              <div class="dash-stat-num">{{ dashOrdersSubmitted }}</div>
              <div class="dash-stat-label">Заказов подано</div>
            </div>
            <div class="dash-stat" @click="switchTab('orders')">
              <div class="dash-stat-num">{{ dashOrdersPending }}</div>
              <div class="dash-stat-label">Ожидают заявку</div>
            </div>
            <div
              v-if="stockCollection.active && stockCollectionUnfilledCount > 0"
              class="dash-stat"
              @click="switchTab('stock')"
            >
              <div class="dash-stat-num dash-stat-alert">{{ stockCollectionUnfilledCount }}</div>
              <div class="dash-stat-label">Не заполнено остатков</div>
            </div>
          </div>

          <!-- Сервисы — крупные плитки -->
          <div class="dash-services">
            <h3 class="dash-section-title">Сервисы</h3>
            <div class="dash-tiles">
              <button class="dash-tile dash-tile--scanner" @click="switchTab('scanner')">
                <span class="dash-tile-icon" v-html="tileIconSvg.scanner"></span>
                <div class="dash-tile-text">
                  <div class="dash-tile-title">Сканер товаров <span class="dash-tile-beta">BETA</span></div>
                  <div class="dash-tile-sub">Сканировать штрих-код для поиска</div>
                </div>
                <span class="dash-tile-arrow">›</span>
              </button>
              <button class="dash-tile dash-tile--warehouse" @click="switchTab('warehouse-stock')">
                <span class="dash-tile-icon" v-html="tileIconSvg.warehouse"></span>
                <div class="dash-tile-text">
                  <div class="dash-tile-title">Остатки склада</div>
                  <div class="dash-tile-sub">Сроки годности по товарам</div>
                </div>
                <span class="dash-tile-arrow">›</span>
              </button>
              <button v-if="kegReturnsEnabled" class="dash-tile dash-tile--keg" @click="switchTab('keg-returns')">
                <span class="dash-tile-icon" v-html="tileIconSvg.keg"></span>
                <div class="dash-tile-text">
                  <div class="dash-tile-title">Возврат кег</div>
                  <div class="dash-tile-sub">Оформление ТТН на пустые кеги</div>
                </div>
                <span class="dash-tile-arrow">›</span>
              </button>
              <button class="dash-tile dash-tile--corrections" @click="switchTab('orders', 'corrections')">
                <span class="dash-tile-icon" v-html="tileIconSvg.corrections"></span>
                <div class="dash-tile-text">
                  <div class="dash-tile-title">Корректировки <span class="dash-tile-beta">BETA</span></div>
                  <div class="dash-tile-sub">Изменить заказ основной поставки</div>
                </div>
                <span class="dash-tile-arrow">›</span>
              </button>
              <button class="dash-tile dash-tile--reminders" @click="switchTab('orders', 'reminders')">
                <span class="dash-tile-icon" v-html="tileIconSvg.reminders"></span>
                <div class="dash-tile-text">
                  <div class="dash-tile-title">Напоминания <span class="dash-tile-beta">BETA</span></div>
                  <div class="dash-tile-sub">О подаче заявок поставщикам</div>
                </div>
                <span class="dash-tile-arrow">›</span>
              </button>
              <a v-if="canUseCardSearch" class="dash-tile dash-tile--cards" href="/search-cards" target="_blank">
                <span class="dash-tile-icon" v-html="tileIconSvg.search"></span>
                <div class="dash-tile-text">
                  <div class="dash-tile-title">Поиск карточек</div>
                  <div class="dash-tile-sub">Найти товар и его описание</div>
                </div>
                <span class="dash-tile-arrow">↗</span>
              </a>
            </div>
          </div>

          <!-- Последние заказы -->
          <div v-if="historyOrders.length" class="dash-recent">
            <h3 class="dash-section-title">Последние заказы</h3>
            <div v-for="order in historyOrders.slice(0, 5)" :key="order.id" class="dash-order" @click="openHistoryOrder(order)">
              <div class="dash-order-left">
                <span class="dash-order-source" :class="'src-' + order.source">{{ order.source_name }}</span>
                <span class="dash-order-date">{{ fmtDate(order.delivery_date) }}</span>
              </div>
              <div class="dash-order-right">
                <span>{{ order.item_count }} поз.</span>
                <span class="dash-order-status" :class="'st-' + order.status">{{ statusLabel(order.status) }}</span>
              </div>
            </div>
          </div>
        </div>

        <div class="dash-col-side">
          <article v-if="latestImportantPost" class="dash-important" :class="{ unread: !latestImportantPost.is_read }">
            <div class="dash-important-head">
              <div>
                <h2>{{ latestImportantPost.title || 'Важная информация' }}</h2>
                <div class="dash-important-meta">
                  {{ latestImportantPost.created_by || 'Отдел закупок' }} · {{ fmtDateTime(latestImportantPost.published_at || latestImportantPost.created_at) }}
                </div>
              </div>
              <span v-if="!latestImportantPost.is_read" class="info-unread">Новое</span>
            </div>
            <p>{{ latestImportantPost.message }}</p>
            <div v-if="latestImportantPost.files?.length" class="info-attachments">
              <button
                v-for="file in latestImportantPost.files"
                :key="file.id"
                class="info-attachment"
                :class="{ image: isImportantImage(file) }"
                @click="isImportantImage(file) ? previewImportantFile(file) : downloadImportantFile(file)"
              >
                <img v-if="isImportantImage(file) && importantPreviewUrls[file.id]" :src="importantPreviewUrls[file.id]" :alt="file.file_name" />
                <span v-else class="info-file-icon" v-html="cabIconSvg.file"></span>
                <span>{{ file.file_name }}</span>
                <small>{{ isImportantImage(file) ? 'Открыть' : formatImportantFileSize(file.file_size) }}</small>
              </button>
            </div>
            <button v-if="!latestImportantPost.is_read" class="info-read-btn" @click="markImportantRead(latestImportantPost)">Отметить прочитанным</button>
          </article>
        </div>
      </div>
    </section>

    <!-- ══════ TAB: Важная информация ══════ -->
    <RestaurantInfoTab
      v-if="activeTab === 'info' && !globalError"
      :posts="importantPosts"
      :loading="importantLoading"
      :preview-urls="importantPreviewUrls"
      @mark-read="markImportantRead"
    />

    <!-- ══════ TAB: Заказы ══════ -->
    <section v-if="activeTab === 'orders' && !globalError" class="cab-section cab-section-orders">
      <div class="ord-tabs mob-order-tabs" aria-label="Разделы заказов">
        <button v-if="roStore.restaurantOrdersEnabled" class="ord-tab" :class="{ active: orderSubTab === 'delivery' }" @click="switchTab('orders', 'delivery')">
          Основная поставка
          <span v-if="deliveryBadge" class="ord-tab-badge" :class="deliveryBadge.type">{{ deliveryBadge.text }}</span>
        </button>
        <button
          v-for="sup in suppliers"
          :key="'mob-ord-' + sup.id"
          class="ord-tab"
          :class="{ active: orderSubTab === 'sup_' + sup.id }"
          @click="switchTab('orders', 'sup_' + sup.id)"
        >
          <span class="supplier-icon supplier-icon-xs" :class="supplierIcon(sup.name).className" v-html="supplierIcon(sup.name).svg"></span>
          {{ sup.name }}
          <span v-if="supplierBadge(sup)" class="ord-tab-badge" :class="supplierBadge(sup).type">{{ supplierBadge(sup).text }}</span>
        </button>
        <a
          v-for="link in externalSupplierLinks"
          :key="'mob-ext-' + link.id"
          class="ord-tab ord-tab-link"
          :href="safeExternalUrl(link.url)"
          target="_blank"
          rel="noopener noreferrer"
        >
          <span class="supplier-icon supplier-icon-xs" :class="link.iconClass" v-html="trustedSupplierIcon(link.iconKey)"></span>
          {{ link.name }}
          <span class="ord-tab-ext" v-html="cabIconSvg.external"></span>
        </a>
        <button class="ord-tab" :class="{ active: orderSubTab === 'corrections' }" @click="switchTab('orders', 'corrections')">
          <span class="ord-tab-icon" v-html="cabIconSvg.corrections"></span>
          Корректировки
          <span class="ord-tab-beta">BETA</span>
        </button>
        <button v-if="!isPizzaStarCabinet" class="ord-tab" :class="{ active: orderSubTab === 'assistant' }" @click="switchTab('orders', 'assistant')">
          <span class="ord-tab-icon" v-html="cabIconSvg.orders"></span>
          Сбор заказа
          <span class="ord-tab-beta">BETA</span>
        </button>
        <button class="ord-tab" :class="{ active: orderSubTab === 'reminders' }" @click="switchTab('orders', 'reminders')">
          <span class="ord-tab-icon" v-html="cabIconSvg.reminders"></span>
          Напоминания
          <span class="ord-tab-beta">BETA</span>
        </button>
        <button class="ord-tab" :class="{ active: orderSubTab === 'history' }" @click="switchTab('orders', 'history')">
          История
        </button>
      </div>

      <!-- ── Основная поставка ── -->
      <div v-if="orderSubTab === 'delivery'">
        <div v-if="!roStore.sessionInfo" class="cab-empty-card">
          <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#B0A090" stroke-width="1.5" stroke-linecap="round" style="margin:0 auto 16px; display:block"><rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 3v5a2 2 0 01-2 2h-1"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
          <h2>Основная поставка</h2>
          <p>Сейчас приём заявок закрыт. Отдел закупок ещё не открыл сессию на эту неделю.</p>
          <p style="margin-top:10px; font-size:12px; color:#B0A090">Обратитесь в отдел закупок для уточнения</p>
        </div>

        <div v-else-if="delShowSuccess" class="cab-success">
          <div class="cab-success-inner">
            <div class="cab-success-check" v-html="cabIconSvg.check"></div>
            <h2>Заказ {{ delWasEdited ? 'обновлён' : 'отправлен' }}</h2>
            <div class="cab-success-date">Доставка {{ fmtDate(delSelectedDate) }}</div>

            <div class="cab-success-stats">
              <div class="cab-success-stat-item">
                <div class="cab-success-stat-num">{{ delTotalItems }}</div>
                <div class="cab-success-stat-lbl">позиций</div>
              </div>
              <div class="cab-success-stat-divider"></div>
              <div class="cab-success-stat-item">
                <div class="cab-success-stat-num">{{ delTotalQty }}</div>
                <div class="cab-success-stat-lbl">коробок</div>
              </div>
            </div>

            <div v-if="delEditTimeLeft" class="cab-success-timer">
              <div class="cab-success-timer-lbl">Можно изменить до {{ delEditDeadlineTime }}</div>
              <div class="cab-success-time">{{ delEditTimeLeft }}</div>
            </div>
            <div v-else-if="delEditTimerExpired" class="cab-success-timer expired">
              <div class="cab-success-timer-lbl">Время редактирования истекло</div>
              <div class="cab-success-timer-sub">Заказ зафиксирован. Изменения возможны только через отдел закупок.</div>
            </div>

            <div class="cab-success-btns">
              <button v-if="delEditTimeLeft" class="btn btn-outline" @click="delShowSuccess = false">Изменить</button>
              <button class="btn btn-primary" @click="delGoToNextDay">Следующий день</button>
            </div>
          </div>
        </div>

        <template v-else>
          <div class="cab-info-bar">
            Сессия: {{ fmtDate(roStore.sessionInfo.week_start) }} — {{ fmtDate(roStore.sessionInfo.week_end) }}
          </div>

          <div v-if="sortedDeliveryDays.length" class="day-tabs">
            <button v-for="day in sortedDeliveryDays" :key="day.date" class="day-tab"
              :class="{ active: delSelectedDate === day.date, done: day.order?.status === 'submitted' || day.order?.status === 'edited', closed: day.deadline_status === 'closed' || day.deadline_status === 'not_open', warn: day.deadline_status === 'warning' }"
              @click="delSelectDay(day.date)">
              <span class="day-tab-label">
                <span class="day-tab-name">{{ day.day_name }}</span>
                <span class="day-tab-date">{{ fmtDateShort(day.date) }}</span>
              </span>
              <span v-if="day.order?.status === 'submitted' || day.order?.status === 'edited'" class="day-tab-mark done" v-html="cabIconSvg.check"></span>
              <span v-else-if="day.deadline_status === 'closed' || day.deadline_status === 'not_open'" class="day-tab-mark closed" v-html="cabIconSvg.x"></span>
            </button>
          </div>
          <div v-else class="cab-empty-card">
            <h2>Нет дней доставки</h2>
            <p>В этой сессии не запланировано ни одного дня поставки для вашего ресторана.</p>
            <p style="margin-top:10px; font-size:12px; color:#B0A090">Если это ошибка — обратитесь в отдел закупок</p>
          </div>

          <div v-if="delSelectedDate" class="order-form">
            <div v-if="delDraftRestoreNotice" class="draft-restored">
              <span>↩ {{ delDraftRestoreNotice }}</span>
            </div>
            <div v-if="delDraftSavedLabel && !delShowSuccess" class="draft-saved-indicator" :title="'Черновик автоматически сохраняется на этом устройстве'">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
              Сохранено в {{ delDraftSavedLabel }}
            </div>
            <div class="deadline-bar" :class="'dl-' + delCurrentDeadlineStatus">
              <span class="deadline-icon" v-if="delCurrentDeadlineStatus === 'open' || delCurrentDeadlineStatus === 'warning'">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              </span>
              <span class="deadline-icon" v-else>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
              </span>
              <template v-if="delCurrentDeadlineStatus === 'open'">Приём заявок открыт до {{ delCurrentDeadlines?.soft?.substring(0,5) }}, {{ delOrderDateLabel }}</template>
              <template v-else-if="delCurrentDeadlineStatus === 'warning'">Дедлайн прошёл. Можно подать до {{ delCurrentDeadlines?.hard?.substring(0,5) }}, {{ delOrderDateLabel }}</template>
              <template v-else-if="delCurrentDeadlineStatus === 'closed'">Приём заявок на эту дату закрыт</template>
              <template v-else-if="delCurrentDeadlineStatus === 'not_open'">Приём заявок на эту дату закрыт</template>
              <template v-else-if="delCurrentDeadlineStatus === 'not_yet'">Приём заявок ещё не начался</template>
            </div>

            <div class="cat-tabs">
              <button v-for="cat in delCategories" :key="cat" class="cat-tab" :class="{ active: !delOnlyFilled && delActiveCategory === cat }" @click="delOnlyFilled = false; delActiveCategory = cat">
                {{ cat }}
                <span v-if="delGetCategoryItemCount(cat)" class="cat-count">{{ delGetCategoryItemCount(cat) }}</span>
              </button>
            </div>

            <div class="search-row">
              <input v-model="delSearchQuery" type="text" placeholder="Поиск по названию или артикулу..." class="input-search" />
              <button v-if="delSearchQuery" class="search-clear" @click="delSearchQuery = ''">&times;</button>
              <button
                class="btn btn-sm"
                :class="delOnlyFilled ? 'btn-primary' : 'btn-outline'"
                :disabled="delTotalItems === 0"
                :title="delTotalItems === 0 ? 'Сначала заполните хотя бы одну позицию' : 'Показать только товары с количеством'"
                @click="delOnlyFilled = !delOnlyFilled"
              >
                Только заполненные
                <span v-if="delTotalItems > 0" class="del-only-filled-count">{{ delTotalItems }}</span>
              </button>
              <button class="btn btn-sm btn-outline" @click="delShowAddModal = true">+ Добавить</button>
              <button class="btn btn-sm btn-outline" :disabled="delLoadingTemplate" @click="delLoadFullTemplate" title="Дозагрузить все товары из шаблона">
                <span v-if="delLoadingTemplate" class="cab-spin cab-spin-sm"></span>
                Загрузить шаблон
              </button>
              <button class="btn btn-sm btn-green" @click="delExportExcel">Excel</button>
            </div>

            <div class="products-list">
              <table v-if="delFilteredItems.length" class="del-table">
                <thead>
                  <tr>
                    <th class="del-th-name">Товар</th>
                    <th class="del-th-qty">Кол-во</th>
                    <th class="del-th-act"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in delFilteredItems" :key="item.sku" :class="{ 'del-filled': item.quantity > 0, 'del-err': item._multError }">
                    <td class="del-td-name">
                      <span class="del-sku">{{ item.sku }}</span> {{ item.product_name }}
                      <span v-if="item.multiplicity > 1" class="del-mult-inline" title="Заказывать только кратно этому числу коробок">×{{ item.multiplicity }}</span>
                    </td>
                    <td class="del-td-qty">
                      <input v-model.number="item.quantity" type="number" inputmode="decimal" min="0"
                        :step="item.multiplicity > 1 ? item.multiplicity : 1"
                        class="del-qty" :class="{ 'del-qty-err': item._multError }"
                        :disabled="!delCanSubmit && !delCanEdit" placeholder="0"
                        @input="delCheckMultiplicity(item)" @focus="$event.target.select()" />
                      <div v-if="item._multError" class="del-mult-hint">Кратно {{ item.multiplicity }}: {{ delMultSuggest(item) }}</div>
                    </td>
                    <td class="del-td-act">
                      <button v-if="item._added" class="btn-icon-danger" @click="delRemoveItem(item)">&times;</button>
                    </td>
                  </tr>
                </tbody>
              </table>
              <div v-else-if="delSearchQuery && !delProductsLoading" class="empty-msg">
                <div>Ничего не найдено в категории «{{ delActiveCategory }}»</div>
                <div v-if="delSearchInOtherCategories.length" class="del-search-other">
                  <span class="del-search-other-lbl">Найдено в других категориях:</span>
                  <button
                    v-for="o in delSearchInOtherCategories"
                    :key="o.category"
                    class="del-search-other-chip"
                    @click="delActiveCategory = o.category"
                  >{{ o.category }} <span class="del-search-other-count">{{ o.count }}</span></button>
                </div>
              </div>
              <div v-else-if="!delProductsLoading" class="empty-msg">Нет товаров в категории «{{ delActiveCategory }}»</div>
              <div v-if="delProductsLoading" class="mini-loader"><div class="cab-spin"></div></div>
            </div>

            <div class="submit-area">
              <div v-if="delMultErrorsCount" class="error-msg">{{ delMultErrorsCount }} {{ pluralPositions(delMultErrorsCount) }} с неверной кратностью — исправьте, чтобы отправить</div>
              <div v-if="delCanSubmit || delCanEdit" class="order-comment-row">
                <input v-model="delOrderComment" type="text" class="order-comment-input" placeholder="Комментарий к заказу (необязательно)" :disabled="!delCanSubmit && !delCanEdit" />
              </div>
              <div class="submit-bottom">
                <div v-if="delTotalItems > 0" class="submit-summary">
                  <span><strong>{{ delTotalItems }}</strong> поз.</span>
                  <span><strong>{{ delTotalQty }}</strong> кор.</span>
                  <button v-if="delCanSubmit || delCanEdit" class="btn btn-sm btn-danger-outline" @click="delClearOrder">Очистить</button>
                </div>
                <div v-else></div>
                <button v-if="delCanSubmit || delCanEdit" class="btn btn-primary btn-lg"
                  :disabled="delSubmitting || delTotalItems === 0 || delHasMultErrors" @click="delHandleSubmit">
                  <span v-if="delSubmitting" class="cab-spin cab-spin-sm"></span>
                  {{ delExistingOrder ? 'Обновить заказ' : 'Отправить заказ' }}
                </button>
              </div>
              <div v-if="!delCanSubmit && !delCanEdit && delCurrentDeadlineStatus === 'closed'" class="locked-msg">
                Заказ заблокирован. Для изменений обратитесь в отдел закупок.
              </div>
              <div v-if="delSubmitError" class="error-msg">{{ delSubmitError }}</div>
            </div>

            <div v-if="delPreviousOrders.length && !delExistingOrder && (delCanSubmit || delCanEdit)" class="repeat-section">
              <div class="repeat-title">Повторить предыдущий заказ:</div>
              <button v-for="po in delPreviousOrders" :key="po.id" class="repeat-btn" @click="delHandleRepeat(po.id)">
                {{ fmtDate(po.delivery_date) }} — {{ po.item_count }} поз., {{ po.total_qty }} кор.
              </button>
            </div>
          </div>
        </template>

      </div>

      <!-- ── Поставщик (Камако и др.) ── -->
      <template v-for="sup in suppliers" :key="'stab-' + sup.id">
        <div v-if="orderSubTab === 'sup_' + sup.id">
          <div v-if="!sup.is_accepting_orders" class="cab-empty-card">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#B0A090" stroke-width="1.5" stroke-linecap="round" style="margin:0 auto 16px; display:block"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
            <h2>{{ sup.name }}</h2>
            <p>{{ sup.pause_message || 'Приём заявок временно приостановлен.' }}</p>
            <p style="margin-top:14px; font-size:12px; color:#B0A090">Обратитесь в отдел закупок</p>
          </div>
          <div v-else-if="!sup.available_dates?.length" class="cab-empty-card">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#B0A090" stroke-width="1.5" stroke-linecap="round" style="margin:0 auto 16px; display:block"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
            <h2>{{ sup.name }}</h2>
            <p>Ближайшие поставки не запланированы.</p>
            <p style="margin-top:14px; font-size:12px; color:#B0A090">Обратитесь в отдел закупок</p>
          </div>
          <template v-else>
            <div class="cab-info-bar">{{ sup.name }}</div>
            <div class="day-tabs">
              <button v-for="d in sup.available_dates" :key="d.delivery_date" class="day-tab"
                :class="{ active: supSelectedDates[sup.id] === d.delivery_date, done: !!d.order && !d.order?.is_skip, skipped: !!d.order?.is_skip, closed: d.deadline_status === 'closed' && !d.order }"
                @click="supSelectDate(sup, d)">
                <span class="day-tab-label">
                  <span class="day-tab-name">{{ d.delivery_day_name }}</span>
                  <span class="day-tab-date">{{ fmtDateShort(d.delivery_date) }}</span>
                </span>
                <span v-if="d.order?.is_skip" class="day-tab-mark skipped" title="Поставка не нужна" v-html="cabIconSvg.skip"></span>
                <span v-else-if="d.order" class="day-tab-mark done" v-html="cabIconSvg.check"></span>
                <span v-else-if="d.deadline_status === 'closed'" class="day-tab-mark closed" v-html="cabIconSvg.x"></span>
              </button>
            </div>
            <div v-if="supSelectedDates[sup.id]" class="order-form">
              <div class="deadline-bar" :class="'dl-' + supCurrentDateInfo(sup)?.deadline_status">
                <span class="deadline-icon" v-if="supCurrentDateInfo(sup)?.deadline_status === 'open'">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </span>
                <span class="deadline-icon" v-else>
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                </span>
                <template v-if="supCurrentDateInfo(sup)?.deadline_status === 'open'">
                  Дедлайн: {{ formatDeadline(supCurrentDateInfo(sup)?.deadline) }}<span v-if="supDeadlineTimeLeft[sup.id]" class="deadline-timer"> · осталось {{ supDeadlineTimeLeft[sup.id] }}</span>
                </template>
                <template v-else>Приём заявок на эту дату закрыт</template>
              </div>
              <div v-if="supProductsLoading[sup.id]" class="mini-loader"><div class="cab-spin"></div></div>
              <template v-else>
                <SupplierPreviousOrder
                  v-if="supPreviousOrders[sup.id] && (!supCurrentDateInfo(sup)?.order || supCurrentDateInfo(sup)?.order?.status === 'draft')"
                  :previous-order="supPreviousOrders[sup.id]"
                  :expanded="!!supShowPreviousOrder[sup.id]"
                  @update:expanded="supShowPreviousOrder[sup.id] = $event"
                  :can-repeat="supCurrentDateInfo(sup)?.deadline_status === 'open'"
                  :format-date="fmtDate"
                  :fmt-num="supFmtNum"
                  variant="inline"
                  @repeat="supHandleRepeatPrevious(sup)"
                />
                <div v-if="supIsSkipOrder[sup.id]" class="sup-skip-banner">
                  <span class="sup-skip-icon" v-html="cabIconSvg.skip"></span>
                  <strong>Поставка не нужна.</strong>
                  <span class="sup-skip-hint">Впишите количества, чтобы отменить.</span>
                </div>
                <div v-if="supProducts[sup.id]?.length" class="item-list">
                  <div v-for="p in supProducts[sup.id]" :key="p.sku"
                    class="item-row" :class="{ 'item-filled': supQuantities[sup.id]?.[p.sku] > 0, 'item-error': supHasError(sup.id, p), 'item-admin-edited': supAdminEditInfo(sup.id, p.sku) }">
                    <div class="item-info">
                      <span class="item-name">{{ p.product_name || p.name }}</span>
                      <span v-if="p.multiplicity" class="item-hint">кр. {{ supFmtNum(p.multiplicity) }}</span>
                      <span v-if="p.min_qty" class="item-hint item-hint-warn">мин. {{ supFmtNum(p.min_qty) }}</span>
                      <span v-if="supAdminEditInfo(sup.id, p.sku)" class="item-edit-mark"
                        :title="`Изменено отделом закупок: было ${supFmtNum(supAdminEditInfo(sup.id, p.sku).original)}, стало ${supFmtNum(supAdminEditInfo(sup.id, p.sku).edited)}`">
                        <span class="inline-ui-icon" v-html="cabIconSvg.edit"></span>
                        {{ supFmtNum(supAdminEditInfo(sup.id, p.sku).original) }} → {{ supFmtNum(supAdminEditInfo(sup.id, p.sku).edited) }}
                      </span>
                    </div>
                    <div class="item-input">
                      <input type="number" class="item-qty" :class="{ 'item-qty-err': supHasError(sup.id, p) }"
                        v-model.number="supQuantities[sup.id][p.sku]"
                        :disabled="supCurrentDateInfo(sup)?.deadline_status === 'closed'"
                        min="0" :step="p.multiplicity || 1" inputmode="numeric" @focus="$event.target.select()" />
                    </div>
                  </div>
                </div>
                <div class="submit-area">
                  <div v-if="supHasErrors(sup.id)" class="error-msg">Исправьте количество</div>
                  <div v-if="supFilledCount(sup.id) > 0" class="submit-summary">
                    <span><strong>{{ supFilledCount(sup.id) }}</strong> поз.</span>
                    <span><strong>{{ supFilledTotal(sup.id) }}</strong> шт.</span>
                  </div>
                  <div class="submit-buttons-row">
                    <button v-if="supCurrentDateInfo(sup)?.deadline_status === 'open'" class="btn btn-danger-outline btn-lg"
                      :disabled="supSubmitting[sup.id]" @click="supSkipDelivery(sup)">
                      Поставка не нужна
                    </button>
                    <button v-if="supCurrentDateInfo(sup)?.deadline_status === 'open'" class="btn btn-primary btn-lg"
                      :disabled="supFilledCount(sup.id) === 0 || supSubmitting[sup.id] || supHasErrors(sup.id)" @click="supHandleSubmit(sup)">
                      <span v-if="supSubmitting[sup.id]" class="cab-spin cab-spin-sm"></span>
                      {{ supCurrentDateInfo(sup)?.order ? 'Обновить' : 'Отправить' }}
                    </button>
                  </div>
                </div>
              </template>
            </div>
          </template>
        </div>
      </template>

      <!-- Сбор заказа (помощник). Для Пицца Стар не показывается. -->
      <div v-if="orderSubTab === 'assistant' && !isPizzaStarCabinet">
        <RestaurantSupplyAssistantTab />
      </div>

      <!-- Корректировки основной поставки -->
      <div v-if="orderSubTab === 'corrections'">
        <RestaurantCorrectionsTab />
      </div>

      <!-- Напоминания о подаче заявок локальным поставщикам -->
      <div v-if="orderSubTab === 'reminders'">
        <RestaurantRemindersTab />
      </div>

      <!-- История в заказах -->
      <RestaurantOrderHistoryTab
        v-if="orderSubTab === 'history'"
        :orders="historyOrders"
        :loading="historyLoading"
        :error="historyError"
        :has-more="historyHasMore"
        :loading-more="historyLoadingMore"
        @load-more="loadMoreHistory"
        @open="openHistoryOrder"
      />
    </section>

    <div v-if="historyOrderModal.show" class="modal-overlay" @click.self="closeHistoryOrderModal">
      <div class="cab-modal hist-modal">
        <div class="cab-modal-head">
          <div class="hist-modal-title-block">
            <span class="hist-badge" :class="'src-' + historyOrderModal.order?.source">{{ historyOrderModal.order?.source_name || 'Заказ' }}</span>
            <span class="hist-modal-date">{{ historyOrderModal.order ? fmtDate(historyOrderModal.order.delivery_date) : '' }}</span>
          </div>
          <button class="cab-modal-close" @click="closeHistoryOrderModal">&times;</button>
        </div>
        <div class="cab-modal-body">
          <div v-if="historyOrderModal.loading" class="cab-empty-card"><BurgerSpinner text="Загрузка..." /></div>
          <div v-else-if="historyOrderModal.error" class="cab-empty-card"><p>{{ historyOrderModal.error }}</p></div>
          <template v-else-if="historyOrderModal.order">
            <div class="hist-modal-meta">
              <div class="hist-modal-meta-row">
                <span class="hist-badge status-badge" :class="'st-' + historyOrderModal.order.status">{{ statusLabel(historyOrderModal.order.status) }}</span>
                <span v-if="historyOrderModal.order.submitted_at" class="hist-modal-time">Подано {{ fmtDateTime(historyOrderModal.order.submitted_at) }}</span>
              </div>
              <div v-if="historyOrderModal.order.comment" class="hist-modal-comment">{{ historyOrderModal.order.comment }}</div>
            </div>
            <div v-if="historyOrderModal.order.items?.length" class="hist-modal-items">
              <div v-for="(item, idx) in historyOrderModal.order.items" :key="idx" class="hist-modal-row">
                <div class="hist-modal-name">
                  <span v-if="item.sku" class="rom-sku-label">{{ item.sku }}</span>
                  {{ item.product_name }}
                </div>
                <div class="hist-modal-qty-block">
                  <template v-if="item.admin_qty !== null && item.admin_qty !== undefined && Number(item.admin_qty) !== Number(item.quantity)">
                    <span class="hist-qty-orig">{{ item.quantity }}</span>
                    <span class="hist-qty-arrow">→</span>
                    <span class="hist-qty-admin">{{ item.admin_qty }}</span>
                    <span class="hist-edited-mark" title="Изменено отделом закупок" v-html="cabIconSvg.edit"></span>
                  </template>
                  <span v-else class="hist-qty-val">{{ item.effective_qty ?? item.quantity }}</span>
                </div>
              </div>
            </div>
            <div v-else class="cab-empty-card"><p>Нет позиций — поставка не нужна</p></div>
          </template>
        </div>
      </div>
    </div>

    <!-- ══════ TAB: Опросы ══════ -->
    <RestaurantSurveysTab
      v-if="activeTab === 'surveys' && !globalError"
      :items="surveyItems"
      :loading="surveyListLoading"
      @reload="loadSurveyList"
    />


    <!-- ══════ TAB: Сбор остатков ══════ -->
    <section v-if="activeTab === 'stock' && !globalError" class="cab-section sc-section">
      <div v-if="stockLoading" class="cab-empty-card">
        <BurgerSpinner text="Загрузка..." />
      </div>
      <div v-else-if="!stockCollection.active" class="cab-empty-card">
        <h2>Нет активного сбора</h2>
        <p>Сейчас сбор остатков не проводится.</p>
      </div>
      <div v-else class="sc-wrap">
        <!-- Переключатель коллекций -->
        <div v-if="stockCollection.collections.length > 1" class="sc-coll-switcher">
          <button
            v-for="c in stockCollection.collections"
            :key="c.id"
            class="sc-coll-chip"
            :class="{ active: String(stockCollection.collection?.id) === String(c.id) }"
            @click="selectStockCollection(c.id)"
          >
            <span>{{ c.name }}</span>
            <small>{{ c.submitted_count }}/{{ c.total_products }}</small>
          </button>
        </div>

        <!-- Шапка коллекции -->
        <div class="sc-head">
          <h2 class="sc-head-title">{{ stockCollection.collection?.name }}</h2>
          <p class="sc-head-sub">
            <span v-if="stockLastSubmittedAt">Последнее сохранение: {{ fmtDateTime(stockLastSubmittedAt) }}</span>
            <span v-else>Если товара нет — оставьте поле пустым или поставьте 0.</span>
          </p>
        </div>

        <div v-if="!stockProducts.length" class="cab-empty-card">
          <p>В сборе пока нет товаров.</p>
        </div>

        <template v-else>
          <!-- Поиск + фильтры (только при > 8 товарах) -->
          <div v-if="stockShowSearch" class="sc-toolbar">
            <input
              v-model="stockSearch"
              type="search"
              class="sc-search-input"
              placeholder="Поиск по артикулу или названию..."
            />
            <div class="sc-filter-chips">
              <button class="sc-fchip" :class="{ active: stockFilter === 'all' }" @click="stockFilter = 'all'">
                Все <span class="sc-fchip-count">{{ stockTotalCount }}</span>
              </button>
              <button class="sc-fchip" :class="{ active: stockFilter === 'unfilled' }" @click="stockFilter = 'unfilled'">
                Не заполнено <span class="sc-fchip-count">{{ stockTotalCount - stockFilledCount }}</span>
              </button>
              <button class="sc-fchip" :class="{ active: stockFilter === 'filled' }" @click="stockFilter = 'filled'">
                Заполнено <span class="sc-fchip-count">{{ stockFilledCount }}</span>
              </button>
            </div>
          </div>

          <!-- Группа: со сроком годности -->
          <div v-if="stockFilteredGrouped.withExpiry.length" class="sc-group">
            <div class="sc-group-head">
              <h3>Со сроком годности</h3>
              <span class="sc-group-count">{{ stockFilteredGrouped.withExpiry.length }}</span>
            </div>
            <div class="sc-list">
              <article
                v-for="p in stockFilteredGrouped.withExpiry"
                :key="p.id"
                class="sc-card"
                :class="{
                  'sc-card-filled': stockProductFilled(p.id),
                  'sc-card-invalid': stockProductInvalid(p),
                }"
              >
                <header class="sc-card-head">
                  <div class="sc-card-title">
                    <span v-if="p.product_sku" class="sc-card-sku">{{ p.product_sku }}</span>
                    <span class="sc-card-name">{{ p.product_name }}</span>
                  </div>
                  <div class="sc-card-total">
                    <span class="sc-card-total-num">{{ stockProductTotal(p.id) || '0' }}</span>
                    <span class="sc-card-total-unit">{{ stockUnitShort(p.unit) }}</span>
                  </div>
                </header>
                <div v-if="p.note" class="sc-card-note">{{ p.note }}</div>

                <div class="sc-batches">
                  <div v-for="(batch, idx) in stockDrafts[p.id] || []" :key="idx" class="sc-batch-row">
                    <div class="sc-batch-fld">
                      <label class="sc-batch-lbl">Срок годности</label>
                      <input
                        type="date"
                        v-model="batch.expiry_date"
                        class="sc-input"
                        :class="{ 'sc-input-err': Number(batch.stock) > 0 && !batch.expiry_date }"
                      />
                    </div>
                    <div class="sc-batch-fld">
                      <label class="sc-batch-lbl">Количество, {{ stockUnitShort(p.unit) }}</label>
                      <input
                        type="number"
                        inputmode="decimal"
                        min="0"
                        step="any"
                        v-model="batch.stock"
                        class="sc-input sc-input-num"
                        placeholder="0"
                      />
                    </div>
                    <button
                      v-if="(stockDrafts[p.id] || []).length > 1"
                      class="sc-batch-del"
                      @click="removeStockBatch(p.id, idx)"
                      title="Удалить партию"
                      aria-label="Удалить партию"
                    >✕</button>
                  </div>
                  <button class="sc-batch-add" @click="addStockBatch(p.id)" type="button">+ Добавить партию</button>
                </div>
              </article>
            </div>
          </div>

          <!-- Группа: без срока -->
          <div v-if="stockFilteredGrouped.withoutExpiry.length" class="sc-group">
            <div class="sc-group-head">
              <h3>Без срока годности</h3>
              <span class="sc-group-count">{{ stockFilteredGrouped.withoutExpiry.length }}</span>
            </div>
            <div class="sc-list">
              <article
                v-for="p in stockFilteredGrouped.withoutExpiry"
                :key="p.id"
                class="sc-card sc-card-simple"
                :class="{ 'sc-card-filled': stockProductFilled(p.id) }"
              >
                <header class="sc-card-head">
                  <div class="sc-card-title">
                    <span v-if="p.product_sku" class="sc-card-sku">{{ p.product_sku }}</span>
                    <span class="sc-card-name">{{ p.product_name }}</span>
                  </div>
                  <div class="sc-card-total">
                    <span class="sc-card-total-num">{{ stockProductTotal(p.id) || '0' }}</span>
                    <span class="sc-card-total-unit">{{ stockUnitShort(p.unit) }}</span>
                  </div>
                </header>
                <div v-if="p.note" class="sc-card-note">{{ p.note }}</div>
                <div class="sc-card-input-row">
                  <input
                    type="number"
                    inputmode="decimal"
                    min="0"
                    step="any"
                    v-model="stockDrafts[p.id][0].stock"
                    class="sc-input sc-input-num sc-card-input"
                    placeholder="0"
                  />
                  <span class="sc-card-input-unit">{{ stockUnitShort(p.unit) }}</span>
                </div>
              </article>
            </div>
          </div>

          <!-- Пустой результат фильтра -->
          <div
            v-if="!stockFilteredGrouped.withExpiry.length && !stockFilteredGrouped.withoutExpiry.length"
            class="cab-empty-card"
          >
            <p>Под текущий поиск/фильтр товаров не найдено.</p>
          </div>
        </template>

        <!-- Sticky-полоса сохранения -->
        <div v-if="stockProducts.length" class="sc-savebar">
          <div class="sc-savebar-inner">
            <div class="sc-savebar-progress">
              <div class="sc-savebar-progress-text">
                Заполнено <b>{{ stockFilledCount }}</b> из {{ stockTotalCount }}
              </div>
              <div class="sc-savebar-progress-bar">
                <div class="sc-savebar-progress-fill" :style="{ width: stockProgress + '%' }"></div>
              </div>
            </div>
            <button
              class="btn btn-primary btn-lg sc-savebar-btn"
              :disabled="stockSaving || (!!stockLastSubmittedAt && !stockDirty)"
              @click="submitStockInline"
            >
              <span v-if="stockSaving" class="cab-spin cab-spin-sm"></span>
              {{ stockLastSubmittedAt ? 'Сохранить изменения' : 'Сохранить' }}
            </button>
          </div>
        </div>
      </div>
    </section>

    <!-- ══════ TAB: Остатки склада ══════ -->
    <RestaurantWarehouseStockTab
      v-if="activeTab === 'warehouse-stock' && !globalError"
      :items="warehouseStockItems"
      :customer="warehouseStockCustomer"
      :uploaded-at="warehouseStockUploadedAt"
      :loading="warehouseStockLoading"
      :error="warehouseStockError"
      @reload="loadWarehouseStock"
    />

    <!-- ══════ TAB: Сканер товаров (BETA) ══════ -->
    <section v-if="activeTab === 'scanner' && !globalError" class="cab-section">
      <ScannerView />
    </section>

    <!-- ══════ TAB: Возврат кег ══════ -->
    <section v-if="activeTab === 'keg-returns' && !globalError" class="cab-section">
      <RestaurantKegReturnsTab />
    </section>

    <!-- ══════ TAB: Профиль ══════ -->
    <section v-if="activeTab === 'profile' && !globalError" class="cab-section pf-section">
      <!-- Шапка ресторана -->
      <div class="pf-hero">
        <div class="pf-hero-avatar">{{ formatRestaurantNumber(roStore.restaurant?.number, roStore.restaurant?.legal_entity_group) }}</div>
        <div class="pf-hero-info">
          <h2 class="pf-hero-title">Ресторан {{ formatRestaurantNumber(roStore.restaurant?.number, roStore.restaurant?.legal_entity_group) }}</h2>
          <p class="pf-hero-addr">{{ restaurantAddress }}</p>
          <p class="pf-hero-le">{{ roStore.restaurant?.legal_entity }}</p>
        </div>
      </div>

      <!-- Telegram -->
      <div class="pf-card">
        <div class="pf-card-head">
          <span class="pf-card-icon pf-icon-tg">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 3 1.5 11l7 2.5L11 21l3-6 7-12z"/><path d="M11 13.5 21 3"/></svg>
          </span>
          <div class="pf-card-title">
            <h3>Telegram</h3>
            <p>Уведомления и быстрые действия в боте</p>
          </div>
        </div>

        <div v-if="tgLinkCode" class="pf-tg-code">
          <p class="pf-tg-code-hint">Отправьте этот код боту <a href="https://t.me/supplyportal_bot" target="_blank">@supplyportal_bot</a></p>
          <div class="pf-tg-code-box">{{ tgLinkCode }}</div>
          <p class="pf-tg-code-meta">Код действует 10 минут</p>
        </div>
        <button v-else class="pf-btn primary block" @click="tgGetCode" :disabled="tgLinkLoading">
          <span v-if="tgLinkLoading" class="cab-spin cab-spin-sm"></span>
          Получить код привязки
        </button>

        <div v-if="tgLinksList.length" class="pf-tg-links">
          <div class="pf-tg-links-title">Привязанные сотрудники ({{ tgLinksList.length }})</div>
          <ul class="pf-tg-list">
            <li v-for="link in tgLinksList" :key="link.chat_id" class="pf-tg-item">
              <div class="pf-tg-item-info">
                <div class="pf-tg-item-name">
                  {{ link.first_name || 'Без имени' }}
                  <span v-if="link.username" class="pf-tg-item-username">@{{ link.username }}</span>
                </div>
                <div class="pf-tg-item-meta">
                  <span v-if="link.verified" class="pf-tg-item-ok">Привязан {{ formatTgDate(link.verified_at) }}</span>
                  <span v-else-if="link.must_reverify_by" class="pf-tg-item-warn">Нужно перепривязать до {{ formatTgDate(link.must_reverify_by) }}</span>
                  <span v-else class="pf-tg-item-warn">Не подтверждён</span>
                </div>
              </div>
              <button class="pf-btn ghost sm danger" @click="tgUnlink(link.chat_id)">Отвязать</button>
            </li>
          </ul>
        </div>
      </div>

      <!-- Смена пароля -->
      <div class="pf-card">
        <div class="pf-card-head">
          <span class="pf-card-icon pf-icon-lock">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
          </span>
          <div class="pf-card-title">
            <h3>Смена пароля</h3>
            <p>Безопасность входа в кабинет</p>
          </div>
        </div>
        <form @submit.prevent="changePassword" class="pf-form">
          <input v-model="pwOld" type="password" placeholder="Текущий пароль" class="pf-input" autocomplete="current-password" />
          <input v-model="pwNew" type="password" placeholder="Новый пароль" class="pf-input" autocomplete="new-password" />
          <input v-model="pwConfirm" type="password" placeholder="Повтор нового пароля" class="pf-input" autocomplete="new-password" />
          <div v-if="pwError" class="pf-msg pf-msg-err">{{ pwError }}</div>
          <div v-if="pwSuccess" class="pf-msg pf-msg-ok">Пароль изменён</div>
          <button type="submit" class="pf-btn primary block" :disabled="pwLoading || !pwOld || !pwNew">
            <span v-if="pwLoading" class="cab-spin cab-spin-sm"></span>
            Сменить пароль
          </button>
        </form>
      </div>

      <!-- Контакты -->
      <div class="pf-card">
        <div class="pf-card-head">
          <span class="pf-card-icon pf-icon-contact">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 2"/></svg>
          </span>
          <div class="pf-card-title">
            <h3>Отдел закупок</h3>
            <p>Связь с командой через бота</p>
          </div>
        </div>
        <a href="https://t.me/supplyportal_bot" target="_blank" class="pf-contact-link">
          <span class="pf-contact-icon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 3 1.5 11l7 2.5L11 21l3-6 7-12z"/><path d="M11 13.5 21 3"/></svg>
          </span>
          <span class="pf-contact-text">
            <span class="pf-contact-username">@supplyportal_bot</span>
            <span class="pf-contact-sub">Открыть в Telegram</span>
          </span>
          <span class="pf-contact-arrow">›</span>
        </a>
      </div>

      <div class="pf-logout-row">
        <button class="pf-btn danger lg pf-logout" @click="handleLogout">Выйти из аккаунта</button>
      </div>
    </section>

    <div v-if="currentImportantPost" class="modal-overlay" @click.self="dismissCurrentImportantPost">
      <div class="cab-modal cab-modal-important">
        <div class="cab-modal-head">
          <h2>{{ currentImportantPost.title || 'Важная информация' }}</h2>
          <button class="cab-modal-close" @click="dismissCurrentImportantPost">&times;</button>
        </div>
        <div class="cab-modal-body">
          <p class="cab-info-text cab-info-text-broadcast">{{ currentImportantPost.message }}</p>
          <div v-if="currentImportantPost.files?.length" class="info-attachments info-files-modal">
            <button
              v-for="file in currentImportantPost.files"
              :key="file.id"
              class="info-attachment"
              :class="{ image: isImportantImage(file) }"
              @click="isImportantImage(file) ? previewImportantFile(file) : downloadImportantFile(file)"
            >
              <img v-if="isImportantImage(file) && importantPreviewUrls[file.id]" :src="importantPreviewUrls[file.id]" :alt="file.file_name" />
              <span v-else class="info-file-icon" v-html="cabIconSvg.file"></span>
              <span>{{ file.file_name }}</span>
              <small>{{ isImportantImage(file) ? 'Открыть' : formatImportantFileSize(file.file_size) }}</small>
            </button>
          </div>
          <div class="cab-info-meta">
            {{ currentImportantPost.created_by || 'Отдел закупок' }} · {{ fmtDateTime(currentImportantPost.published_at || currentImportantPost.created_at) }}
          </div>
          <div class="cab-info-actions">
            <button class="btn btn-outline" @click="switchTab('info'); dismissCurrentImportantPost()">Открыть раздел</button>
            <button class="btn btn-primary" @click="dismissCurrentImportantPost">Понятно</button>
          </div>
        </div>
      </div>
    </div>

    <div v-else-if="currentBroadcast" class="modal-overlay" @click.self="dismissCurrentBroadcast">
      <div class="cab-modal cab-modal-info">
        <div class="cab-modal-head">
          <h2>{{ currentBroadcast.title || 'Сообщение от отдела закупок' }}</h2>
          <button class="cab-modal-close" @click="dismissCurrentBroadcast">&times;</button>
        </div>
        <div class="cab-modal-body">
          <p class="cab-info-text cab-info-text-broadcast">{{ currentBroadcast.message }}</p>
          <div class="cab-info-meta">
            {{ currentBroadcast.created_by || 'Отдел закупок' }} · {{ fmtDateTime(currentBroadcast.created_at) }}
          </div>
          <div class="cab-info-actions">
            <button class="btn btn-primary" @click="dismissCurrentBroadcast">Понятно</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="importantImagePreview.show" class="modal-overlay image-preview-overlay" @click.self="closeImportantPreview">
      <div class="important-image-modal">
        <div class="important-image-head">
          <span>{{ importantImagePreview.name }}</span>
          <button @click="closeImportantPreview">&times;</button>
        </div>
        <img :src="importantImagePreview.url" :alt="importantImagePreview.name" />
      </div>
    </div>

    <!-- Info modal (шаблон / прочие уведомления) -->
    <div v-if="infoModal.show" class="modal-overlay" @click.self="infoModal.show = false">
      <div class="cab-modal cab-modal-info">
        <div class="cab-modal-head">
          <h2>{{ infoModal.title }}</h2>
          <button class="cab-modal-close" @click="infoModal.show = false">&times;</button>
        </div>
        <div class="cab-modal-body">
          <p class="cab-info-text" :class="{ 'cab-info-error': infoModal.type === 'error' }">{{ infoModal.message }}</p>
          <div class="cab-info-actions">
            <button class="btn btn-primary" @click="infoModal.show = false">Хорошо</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Confirm modal -->
    <div v-if="confirmModal.show" class="modal-overlay" @click.self="confirmModalCancel">
      <div class="cab-modal cab-modal-info">
        <div class="cab-modal-head">
          <h2>{{ confirmModal.title }}</h2>
          <button class="cab-modal-close" @click="confirmModalCancel">&times;</button>
        </div>
        <div class="cab-modal-body">
          <p class="cab-info-text">{{ confirmModal.message }}</p>
          <div class="cab-info-actions cab-info-actions-two">
            <button class="btn btn-outline" @click="confirmModalCancel">{{ confirmModal.cancelText }}</button>
            <button class="btn" :class="confirmModal.danger ? 'btn-danger-outline' : 'btn-primary'" @click="confirmModalOk">{{ confirmModal.okText }}</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Add product modal -->
    <div v-if="delShowAddModal" class="modal-overlay" @click.self="delShowAddModal = false">
      <div class="cab-modal">
        <div class="cab-modal-head"><h2>Добавить товар</h2><button class="cab-modal-close" @click="delShowAddModal = false">&times;</button></div>
        <div class="cab-modal-body">
          <input v-model="delAddSearch" type="text" placeholder="Поиск..." class="input-search modal-search" @input="delDoAddSearch" ref="delAddSearchInput" />
          <div v-if="delAddLoading" class="mini-loader"><div class="cab-spin"></div></div>
          <div v-else-if="delAddResults.length" class="add-list">
            <div v-for="p in delAddResults" :key="p.sku" class="add-item" @click="delAddProduct(p)">
              <div><span class="sku">{{ p.sku }}</span> {{ p.name }}</div>
              <div class="add-item-meta">
                <span class="add-cat">{{ p.category }}</span>
                <span v-if="p.multiplicity > 1" class="mult">x{{ p.multiplicity }}</span>
              </div>
            </div>
          </div>
          <div v-else-if="delAddSearch.length >= 2" class="empty-msg">Ничего не найдено</div>
          <div v-else class="empty-msg">Введите минимум 2 символа</div>
        </div>
      </div>
    </div>

    <!-- Supplier success overlay -->
    <div v-if="supShowSuccess" class="modal-overlay" @click.self="supShowSuccess = false">
      <div class="cab-success-inner">
        <div
          class="cab-success-check"
          :class="{ 'cab-success-check-skip': supSuccessInfo.skipped }"
          v-html="supSuccessInfo.skipped ? cabIconSvg.x : cabIconSvg.check"
        ></div>
        <h2>{{ supSuccessInfo.skipped ? 'Поставка отмечена как ненужная' : 'Заявка отправлена' }}</h2>
        <div class="cab-success-date">{{ supSuccessInfo.supplier_name }} — {{ fmtDate(supSuccessInfo.delivery_date) }}</div>
        <div v-if="!supSuccessInfo.skipped" class="cab-success-stats">
          <div class="cab-success-stat-item">
            <div class="cab-success-stat-num">{{ supSuccessInfo.total_items }}</div>
            <div class="cab-success-stat-lbl">позиций</div>
          </div>
          <div class="cab-success-stat-divider"></div>
          <div class="cab-success-stat-item">
            <div class="cab-success-stat-num">{{ supSuccessInfo.total_qty }}</div>
            <div class="cab-success-stat-lbl">шт.</div>
          </div>
        </div>
        <div v-else class="cab-success-skip-note">
          Отдел закупок зафиксирует, что на эту дату заявка не подаётся.
        </div>
        <div class="cab-success-btns">
          <button class="btn btn-primary" @click="supShowSuccess = false">OK</button>
        </div>
      </div>
    </div>

    <!-- ══════ Mobile tab bar ══════ -->
    <div class="mob-tabbar">
      <button v-for="tab in mobileTabs" :key="tab.id" class="mob-tab" :class="{ active: activeTab === tab.id, alert: tab.badgeType === 'alert' }" @click="switchTab(tab.id)">
        <span class="mob-tab-icon" v-html="tabIconSvg(tab.id)"></span>
        <span class="mob-tab-label">{{ tab.label }}</span>
        <span v-if="tab.badge" class="mob-tab-badge" :class="tab.badgeType">{{ tab.badge }}</span>
      </button>
      <button class="mob-tab" :class="{ active: activeTab === 'profile' }" @click="switchTab('profile')">
        <span class="mob-tab-icon" v-html="cabIconSvg.profile"></span>
        <span class="mob-tab-label">Профиль</span>
      </button>
    </div>

    </div><!-- /cab-main -->
    <!-- Скрытый router-view: дочерние маршруты используют пустой компонент-заглушку,
         сам кабинет управляет содержимым через состояние табов -->
    <router-view v-slot="{ Component }"><component :is="Component" v-if="false" /></router-view>
  </div>
</template>

<script setup>
import { ref, reactive, computed, defineAsyncComponent, onMounted, onUnmounted, nextTick, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useRestaurantOrderStore } from '@/stores/restaurantOrderStore.js';
import { useSupplierOrderStore } from '@/stores/supplierOrderStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { deadlineTimeLeftString } from '@/composables/useDeadlineCountdown.js';
import { formatDate as fmtDate, formatDateShort as fmtDateShort, formatDateTime as fmtDateTime, statusLabel } from '@/lib/roUtils.js';
import { formatRestaurantNumber, ENTITY_GROUP_BK_VM } from '@/lib/legalEntities.js';
import { cabIconSvg, tileIconSvg, supplierIcon, trustedSupplierIcon, tabIconSvg } from '@/lib/cabinetIcons.js';
import { roFetch } from '@/lib/roUtils.js';
import { useCabinetDashboard } from '@/composables/useCabinetDashboard.js';
import SupplierPreviousOrder from '@/components/SupplierPreviousOrder.vue';

const ScannerView = defineAsyncComponent(() => import('@/views/restaurant/ScannerView.vue'));
const RestaurantKegReturnsTab = defineAsyncComponent(() => import('@/components/restaurant/RestaurantKegReturnsTab.vue'));
const RestaurantRemindersTab = defineAsyncComponent(() => import('@/components/restaurant/RestaurantRemindersTab.vue'));
const RestaurantCorrectionsTab = defineAsyncComponent(() => import('@/components/restaurant/RestaurantCorrectionsTab.vue'));
const RestaurantTodayReminders = defineAsyncComponent(() => import('@/components/restaurant/RestaurantTodayReminders.vue'));
const RestaurantOrderHistoryTab = defineAsyncComponent(() => import('@/components/restaurant/RestaurantOrderHistoryTab.vue'));
const RestaurantSurveysTab = defineAsyncComponent(() => import('@/components/restaurant/RestaurantSurveysTab.vue'));
const RestaurantInfoTab = defineAsyncComponent(() => import('@/components/restaurant/RestaurantInfoTab.vue'));
const RestaurantWarehouseStockTab = defineAsyncComponent(() => import('@/components/restaurant/RestaurantWarehouseStockTab.vue'));
const RestaurantSupplyAssistantTab = defineAsyncComponent(() => import('@/components/restaurant/RestaurantSupplyAssistantTab.vue'));

const router = useRouter();
const route = useRoute();
const roStore = useRestaurantOrderStore();
const soStore = useSupplierOrderStore();
const toast = useToastStore();

const globalLoading = ref(true);
const globalError = ref('');
// У кабинета ресторана собственный роутинг — каждый раздел это свой под-роут
// (/restaurant/dashboard, /restaurant/orders и т.д.). Синхронизацию с URL делают
// applyRouteToState/syncStateToRoute ниже, поэтому useTabRoute сюда не нужен —
// он бы добавлял дублирующий `?tab=...` параметр и конфликтовал с под-роутами.
const activeTab = ref('dashboard');
const cabBrand = computed(() => {
  const group = roStore.restaurant?.legal_entity_group;
  if (group === 'PS') {
    return {
      title: 'Supply Portal',
      subtitle: 'Pizza Star',
      logoLetter: 'P',
      themeClass: 'cab-theme-ps',
    };
  }
  return {
    title: 'Supply Portal',
    subtitle: 'Burger King',
    logoLetter: 'B',
    themeClass: 'cab-theme-bk',
  };
});
const isPizzaStarCabinet = computed(() => roStore.restaurant?.legal_entity_group === 'PS');
const canUseCardSearch = computed(() => !isPizzaStarCabinet.value);
// Иконки кабинета вынесены в src/lib/cabinetIcons.js
const externalOrderLinks = [
  { id: 'lidskae', name: 'Лидское пиво', url: 'https://client.lidskae.by/catalog', iconKey: 'drinks', iconClass: 'supplier-icon-drinks' },
  { id: 'salatoria', name: 'Салатория', url: 'http://salatoria.liam.by/my_zakaz/ru_RU', iconKey: 'vegetables', iconClass: 'supplier-icon-vegetables' },
];
const externalSupplierLinks = computed(() => (
  roStore.restaurant?.legal_entity_group === ENTITY_GROUP_BK_VM ? externalOrderLinks : []
));
// Защита от javascript:-ссылок: если url не http(s) — открываем как about:blank.
function safeExternalUrl(url) {
  return /^https?:\/\//i.test(String(url || '')) ? url : 'about:blank';
}
// trustedSupplierIcon, supplierIcon, tabIconSvg — импортированы из cabinetIcons.js

// Адрес без дублирования города
const restaurantAddress = computed(() => {
  const r = roStore.restaurant;
  if (!r) return '';
  const city = r.city || '';
  let addr = r.address || '';
  // Убираем «г. Город,» из адреса если город уже показан
  if (city && addr) {
    const cityPatterns = [`г. ${city},`, `г.${city},`, `${city},`];
    for (const p of cityPatterns) {
      if (addr.startsWith(p)) { addr = addr.slice(p.length).trim(); break; }
    }
  }
  return city + (addr ? ', ' + addr : '');
});
const orderSubTab = ref('delivery');
const suppliers = ref([]);
const defaultOrderSubTab = computed(() => {
  if (roStore.restaurantOrdersEnabled) return 'delivery';
  const firstSupplier = suppliers.value[0];
  return firstSupplier ? 'sup_' + firstSupplier.id : 'history';
});

// ═══ Stock collection ═══
const stockCollection = reactive({ active: false, collection: null, collections: [], selectedId: null });
const stockProducts = ref([]);
const stockDrafts = reactive({}); // product_id -> [{expiry_date, stock}]
const stockLastSubmittedAt = ref(null);
const stockLoading = ref(false);
const stockSaving = ref(false);
const stockSavedFlash = ref(false);
const stockSearch = ref('');
const stockFilter = ref('all'); // all | unfilled | filled
const warehouseStockItems = ref([]);
const warehouseStockCustomer = ref('');
const warehouseStockUploadedAt = ref('');
const warehouseStockLoading = ref(false);
const warehouseStockError = ref('');
const restaurantBroadcasts = ref([]);
let restaurantBroadcastTimer = null;
let heartbeatTimer = null;
const currentBroadcast = computed(() => restaurantBroadcasts.value[0] || null);
const importantPosts = ref([]);
const importantLoading = ref(false);
const importantPreviewUrls = reactive({});
const importantImagePreview = reactive({ show: false, url: '', name: '' });
const latestImportantPost = computed(() => importantPosts.value[0] || null);
const currentImportantPost = computed(() => importantPosts.value.find(p => !p.is_read && Number(p.show_popup || 0) === 1) || null);
let cabinetBackgroundRunId = 0;
const stockDirty = computed(() => {
  for (const p of stockProducts.value) {
    const saved = stockSavedSnapshot[p.id] || '[]';
    const current = JSON.stringify(normalizeStockDraft(p.id));
    if (saved !== current) return true;
  }
  return false;
});
const stockSavedSnapshot = reactive({}); // последние сохранённые значения

// Видим ли поиск/фильтры (показываем только если товаров много)
const stockShowSearch = computed(() => stockProducts.value.length > 8);

// Заполнен ли товар: есть валидная введённая цифра (включая 0).
function stockProductFilled(productId) {
  return normalizeStockDraft(productId).length > 0;
}
// Есть ли в карточке ошибка: партия с остатком > 0 без срока.
function stockProductInvalid(p) {
  if (!stockExpiryRequired(p)) return false;
  const batches = stockDrafts[p.id] || [];
  return batches.some(b => Number(b.stock) > 0 && !b.expiry_date);
}

const stockFilledCount = computed(() => stockProducts.value.filter(p => stockProductFilled(p.id)).length);
const stockTotalCount = computed(() => stockProducts.value.length);
const stockProgress = computed(() => {
  const tot = stockTotalCount.value;
  return tot ? Math.round((stockFilledCount.value / tot) * 100) : 0;
});

// Список товаров после поиска и фильтра, разбитый на две группы и
// отсортированный «не заполнено → заполнено».
const stockFilteredGrouped = computed(() => {
  const q = stockSearch.value.trim().toLowerCase();
  const filter = stockFilter.value;
  const matches = (p) => {
    if (q) {
      const hay = [p.product_sku, p.product_name].filter(Boolean).join(' ').toLowerCase();
      if (!hay.includes(q)) return false;
    }
    const filled = stockProductFilled(p.id);
    if (filter === 'unfilled' && filled) return false;
    if (filter === 'filled' && !filled) return false;
    return true;
  };
  // Сортировка: незаполненные первыми, среди равных — по исходному порядку.
  const sortFn = (a, b) => {
    const fa = stockProductFilled(a.id) ? 1 : 0;
    const fb = stockProductFilled(b.id) ? 1 : 0;
    return fa - fb;
  };
  const withExpiry = [];
  const withoutExpiry = [];
  for (const p of stockProducts.value) {
    if (!matches(p)) continue;
    if (stockExpiryRequired(p)) withExpiry.push(p);
    else withoutExpiry.push(p);
  }
  withExpiry.sort(sortFn);
  withoutExpiry.sort(sortFn);
  return { withExpiry, withoutExpiry };
});


// ═══ Telegram ═══
const tgLinkCode = ref('');
const tgLinkLoading = ref(false);
const tgError = ref('');
const tgLinksList = ref([]);

// ═══ Password ═══
const pwOld = ref('');
const pwNew = ref('');
const pwConfirm = ref('');
const pwError = ref('');
const pwSuccess = ref(false);
const pwLoading = ref(false);

// ═══ History ═══
const historyLoading = ref(false);
const historyOrders = ref([]);
const historyError = ref('');
const historyOrderModal = reactive({
  show: false,
  loading: false,
  error: '',
  order: null,
});

// ═══ Surveys ═══
const surveyItems = ref([]);
const surveyListLoading = ref(false);
const surveyPendingCount = computed(() => surveyItems.value.filter(item => !item.already_answered).length);

// Счётчик незаполненных позиций в сборе остатков. Объявлен ДО useCabinetDashboard,
// чтобы избежать TDZ — в композабл передаётся как ref.
// Тело computed выполняется лениво, поэтому stockProducts/stockProductFilled
// внутри могут быть объявлены ниже.
const stockCollectionUnfilledCount = computed(() => {
  if (!stockCollection.active) return 0;
  const products = stockProducts.value || [];
  if (products.length) {
    let n = 0;
    for (const p of products) {
      if (!stockProductFilled(p.id)) n++;
    }
    return n;
  }
  const c = stockCollection.collection;
  if (c && c.total_products != null) {
    const total = Number(c.total_products) || 0;
    const sub = Number(c.submitted_count) || 0;
    return Math.max(0, total - sub);
  }
  return 0;
});
// ═══ Dashboard ═══
const dashOrdersSubmitted = computed(() => {
  let total = roStore.restaurantOrdersEnabled
    ? roStore.deliveryDays.filter(d => d.order?.status === 'submitted' || d.order?.status === 'edited').length
    : 0;
  // Поставщики (Камако и др.)
  for (const sup of suppliers.value) {
    total += (sup.available_dates || []).filter(d => !!d.order).length;
  }
  return total;
});
const dashOrdersPending = computed(() => {
  let total = roStore.restaurantOrdersEnabled
    ? roStore.deliveryDays.filter(d => d.deadline_status !== 'closed' && d.deadline_status !== 'not_open' && !d.order).length
    : 0;
  // Поставщики: открытые даты без заявки
  for (const sup of suppliers.value) {
    total += (sup.available_dates || []).filter(d => d.deadline_status === 'open' && !d.order).length;
  }
  return total;
});

// ═══ PWA push онбординг + Сводка «Сегодня нужно сделать» (см. composable) ═══
const {
  push,
  showPushOnboarding,
  dismissPushOnboarding,
  enablePushOnboarding,
  todaySignals,
} = useCabinetDashboard({
  stockCollection,
  stockCollectionUnfilledCount,
  surveyPendingCount,
  switchTab: (tab, sub) => switchTab(tab, sub),
  toast,
});

const urgentItems = computed(() => {
  const items = [];
  const earliest = (arr, field = 'deadline') => {
    const stamps = arr.map(x => x?.[field]).filter(Boolean).sort();
    return stamps[0] || '9999-12-31 23:59';
  };
  const openDays = roStore.restaurantOrdersEnabled
    ? roStore.deliveryDays.filter(d => (d.deadline_status === 'open' || d.deadline_status === 'warning') && !d.order)
    : [];
  if (openDays.length) {
    items.push({
      key: 'del', type: 'warn',
      icon: cabIconSvg.orders, title: `Основная поставка: ${openDays.length} дн. без заявки`,
      subtitle: openDays.map(d => d.day_name).join(', '),
      deadline: earliest(openDays),
      action: async () => { await switchTab('orders', 'delivery'); if (openDays[0]) delSelectDay(openDays[0].date); },
    });
  }
  // Suppliers
  for (const sup of suppliers.value) {
    const openDates = sup.available_dates?.filter(d => d.deadline_status === 'open' && !d.order) || [];
    if (openDates.length) {
      items.push({
        key: 'sup_' + sup.id, type: 'orange',
        icon: supplierIcon(sup.name).svg, title: `${sup.name}: ${openDates.length} дн. без заявки`,
        subtitle: openDates.map(d => d.delivery_day_name).join(', '),
        deadline: earliest(openDates),
        action: () => switchTab('orders', 'sup_' + sup.id),
      });
    }
  }
  // Stock
  if (stockCollection.active && !stockCollection.collection?.submitted) {
    items.push({
      key: 'stock', type: 'alert',
      icon: cabIconSvg.stock, title: 'Сбор остатков',
      subtitle: stockCollection.collection?.name || 'Нужно заполнить',
      deadline: '9999-12-31 23:59',
      action: () => switchTab('stock'),
    });
  }
  items.sort((a, b) => String(a.deadline).localeCompare(String(b.deadline)));
  return items;
});

// ═══ Tabs ═══
// Полный список табов — используется на десктопе (вторичная навигация под топбаром)
// и для определения, какие экраны вообще доступны.
const mainTabs = computed(() => {
  const tabs = [
    { id: 'dashboard', label: 'Главная' },
    { id: 'info', label: 'Информация', badge: importantPosts.value.filter(p => !p.is_read).length || null, badgeType: 'warn' },
    { id: 'orders', label: 'Заказы', badge: dashOrdersPending.value || null, badgeType: dashOrdersPending.value ? 'warn' : '' },
  ];
  if (surveyItems.value.length) {
    tabs.push({ id: 'surveys', label: 'Опросы', badge: surveyPendingCount.value || null, badgeType: surveyPendingCount.value ? 'warn' : '' });
  }
  if (stockCollection.active) {
    const unfilled = stockCollectionUnfilledCount.value;
    tabs.push({
      id: 'stock',
      label: 'Сбор остатков',
      badge: unfilled > 0 ? unfilled : null,
      badgeType: unfilled > 0 ? 'alert' : '',
    });
  }
  tabs.push({ id: 'warehouse-stock', label: 'Остатки склада' });
  tabs.push({ id: 'scanner', label: 'Сканер', beta: true });
  if (kegReturnsEnabled.value) tabs.push({ id: 'keg-returns', label: 'Возврат кег' });
  return tabs;
});

// Нижний таббар на мобилке — компактный набор: только 4 фиксированные кнопки
// плюс «Сбор остатков» и «Опросы», когда они активны. Остальное (Сканер,
// Возврат кег, Остатки склада) — крупными плитками на дашборде.
const mobileTabs = computed(() => {
  const tabs = [
    { id: 'dashboard', label: 'Главная' },
    { id: 'orders', label: 'Заказы', badge: dashOrdersPending.value || null, badgeType: dashOrdersPending.value ? 'warn' : '' },
    { id: 'info', label: 'Информация', badge: importantPosts.value.filter(p => !p.is_read).length || null, badgeType: 'warn' },
  ];
  if (stockCollection.active) {
    // Счётчик незаполненных позиций. Если всё заполнено — бейджа нет.
    const unfilled = stockCollectionUnfilledCount.value;
    tabs.push({
      id: 'stock',
      label: 'Остатки',
      badge: unfilled > 0 ? unfilled : null,
      badgeType: unfilled > 0 ? 'alert' : '',
    });
  }
  if (surveyItems.value.length) {
    tabs.push({ id: 'surveys', label: 'Опросы', badge: surveyPendingCount.value || null, badgeType: surveyPendingCount.value ? 'warn' : '' });
  }
  return tabs;
});

const kegReturnsEnabled = ref(false);
async function loadKegReturnsAvailability() {
  try {
    const data = await roFetch('/api/keg-returns/restaurant-info');
    // Видимость модуля = глобальный тумблер «keg_returns_enabled» (включает закупка)
    // И наличие хотя бы одного дня вывоза «pickup_weekdays» у конкретного ресторана.
    const moduleOn = !!data.keg_returns_enabled;
    const restaurantOn = !!parseInt(data.pickup_weekdays || 0);
    kegReturnsEnabled.value = moduleOn && restaurantOn;
  } catch { kegReturnsEnabled.value = false; }
}
// ═══ Delivery (основная поставка) ═══
const delSelectedDate = ref('');
const delActiveCategory = ref('Сухой');
const delCategories = ['Сухой', 'Холод', 'Мороз'];
const delOrderItems = ref([]);
const delSearchQuery = ref('');
const delPreviousOrders = ref([]);
const delSubmitting = ref(false);
const delSubmitError = ref('');
const delExistingOrder = ref(null);
const delProductsLoading = ref(false);
const delShowSuccess = ref(false);
const delOrderComment = ref('');
const delWasEdited = ref(false);
const delEditTimeLeft = ref('');
const delEditTimerExpired = ref(false);
let delEditTimerInterval = null;
let delSelectRequestId = 0;
const delShowAddModal = ref(false);
const delLoadingTemplate = ref(false);
const infoModal = reactive({ show: false, title: '', message: '', type: 'info' });
function showInfo(title, message, type = 'info') {
  infoModal.title = title;
  infoModal.message = message;
  infoModal.type = type;
  infoModal.show = true;
}

const confirmModal = reactive({ show: false, title: '', message: '', okText: 'Подтвердить', cancelText: 'Отмена', danger: false, resolve: null });
function showConfirm(title, message, opts = {}) {
  // Если уже открыта — отменяем предыдущий промис
  if (confirmModal.show && confirmModal.resolve) confirmModal.resolve(false);
  return new Promise(resolve => {
    confirmModal.title = title;
    confirmModal.message = message;
    confirmModal.okText = opts.okText || 'Подтвердить';
    confirmModal.cancelText = opts.cancelText || 'Отмена';
    confirmModal.danger = !!opts.danger;
    confirmModal.resolve = resolve;
    confirmModal.show = true;
  });
}
function confirmModalOk() {
  const r = confirmModal.resolve;
  confirmModal.show = false;
  confirmModal.resolve = null;
  if (r) r(true);
}
function confirmModalCancel() {
  const r = confirmModal.resolve;
  confirmModal.show = false;
  confirmModal.resolve = null;
  if (r) r(false);
}
const delAddSearch = ref('');
const delAddResults = ref([]);
const delAddLoading = ref(false);
const delAddSearchInput = ref(null);
let delAddTimer = null;
const delSavedSnapshot = ref('');
const supLoadRequestId = reactive({});

const delCurrentDay = computed(() => roStore.deliveryDays.find(d => d.date === delSelectedDate.value));
// Дни: новая дата (поздняя) слева, старые (ранние) справа
const sortedDeliveryDays = computed(() => {
  const days = [...roStore.deliveryDays];
  days.sort((a, b) => b.date.localeCompare(a.date));
  return days;
});
const delCurrentDeadlineStatus = computed(() => delCurrentDay.value?.deadline_status || 'closed');
const delCurrentDeadlines = computed(() => delCurrentDay.value?.deadlines);
const delCanSubmit = computed(() => ['open', 'warning'].includes(delCurrentDeadlineStatus.value));
const delCanEdit = computed(() => delCurrentDay.value?.can_edit && delExistingOrder.value);
const delOrderDateLabel = computed(() => {
  if (!delSelectedDate.value) return '';
  const d = new Date(delSelectedDate.value + 'T00:00:00');
  d.setDate(d.getDate() - 1); // день подачи = день до доставки
  const days = ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'];
  return days[d.getDay()] + ' ' + d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
});
const delEditDeadlineTime = computed(() => {
  const deadlines = delCurrentDeadlines.value;
  const t = deadlines?.edit_until || deadlines?.hard || '13:00:00';
  return t.slice(0, 5);
});
const delOnlyFilled = ref(false);
// Если все позиции стёрли — выключаем фильтр, иначе таблица будет пустой
// без понятного объяснения.
watch(() => delOrderItems.value.some(i => (parseFloat(i.quantity) || 0) > 0), hasFilled => {
  if (!hasFilled) delOnlyFilled.value = false;
});
const delFilteredItems = computed(() => {
  // В режиме «Только заполненные» показываем все заполненные позиции
  // из всех категорий — это режим проверки заказа перед отправкой.
  let items = delOnlyFilled.value
    ? delOrderItems.value.filter(i => (parseFloat(i.quantity) || 0) > 0)
    : delOrderItems.value.filter(i => i.category === delActiveCategory.value);
  if (delSearchQuery.value) {
    const q = delSearchQuery.value.toLowerCase();
    items = items.filter(i => i.product_name.toLowerCase().includes(q) || i.sku.toLowerCase().includes(q));
  }
  return items;
});

// Если поиск не дал результатов в текущей категории, показываем,
// в каких других категориях товар нашёлся, и предлагаем туда перейти.
const delSearchInOtherCategories = computed(() => {
  const q = (delSearchQuery.value || '').toLowerCase();
  if (!q || delFilteredItems.value.length) return [];
  const counts = {};
  for (const i of delOrderItems.value) {
    if (i.category === delActiveCategory.value) continue;
    if (!(i.product_name || '').toLowerCase().includes(q) && !(i.sku || '').toLowerCase().includes(q)) continue;
    counts[i.category] = (counts[i.category] || 0) + 1;
  }
  return delCategories.filter(c => counts[c]).map(c => ({ category: c, count: counts[c] }));
});
const delTotalItems = computed(() => delOrderItems.value.filter(i => i.quantity > 0).length);
const delTotalQty = computed(() => delOrderItems.value.reduce((s, i) => s + (parseFloat(i.quantity) || 0), 0));
const delHasMultErrors = computed(() => delOrderItems.value.some(i => i._multError && i.quantity > 0));
const delMultErrorsCount = computed(() => delOrderItems.value.filter(i => i._multError && i.quantity > 0).length);
function delSerializeState() {
  return JSON.stringify({
    items: delOrderItems.value.map(i => ({
      s: i.sku,
      q: i.quantity,
      c: i.comment || '',
    })),
    comment: delOrderComment.value || '',
  });
}
function delSerializeSnapshot(items, comment) {
  return JSON.stringify({
    items: items.map(i => ({
      s: i.sku,
      q: i.quantity,
      c: i.comment || '',
    })),
    comment: comment || '',
  });
}
const delHasUnsavedChanges = computed(() => {
  if (!delSavedSnapshot.value) return false;
  return delSerializeState() !== delSavedSnapshot.value;
});

const deliveryBadge = computed(() => {
  if (!roStore.sessionInfo) return null;
  const submitted = roStore.deliveryDays.filter(d => d.order?.status === 'submitted' || d.order?.status === 'edited').length;
  const open = roStore.deliveryDays.filter(d => d.deadline_status !== 'closed' && d.deadline_status !== 'not_open' && !d.order).length;
  if (open > 0) return { text: open, type: 'warn' };
  if (submitted > 0) return { text: submitted, type: 'ok' };
  return null;
});

function delGetCategoryItemCount(cat) { return delOrderItems.value.filter(i => i.category === cat && i.quantity > 0).length; }
function delDateToLocalYmd(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}
function delCheckMultiplicity(item) {
  const m = parseFloat(item.multiplicity) || 1;
  const q = parseFloat(item.quantity) || 0;
  item.multiplicity = m;
  item._multError = m > 1 && q > 0 && Math.abs(q / m - Math.round(q / m)) > 0.0001;
}
// Подсказка с двумя ближайшими кратными значениями: меньше и больше введённого.
// Помогает пользователю не считать в уме при редкой кратности (например, 6 или 12).
function delMultSuggest(item) {
  const m = parseFloat(item.multiplicity) || 1;
  const q = parseFloat(item.quantity) || 0;
  if (m <= 1 || q <= 0) return '';
  const lower = Math.floor(q / m) * m;
  const upper = Math.ceil(q / m) * m;
  if (lower > 0 && upper !== lower) return `${lower} или ${upper}`;
  return String(upper || m);
}
function pluralRu(n, forms) {
  const m10 = n % 10, m100 = n % 100;
  if (m10 === 1 && m100 !== 11) return forms[0];
  if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return forms[1];
  return forms[2];
}
function pluralPositions(n) { return pluralRu(n, ['позиция', 'позиции', 'позиций']); }
function pluralBoxes(n) { return pluralRu(n, ['коробка', 'коробки', 'коробок']); }
function delRefreshMultiplicityErrors() { for (const item of delOrderItems.value) delCheckMultiplicity(item); }

async function delSelectDay(date) {
  const requestId = ++delSelectRequestId;
  // Гасим таймер «осталось до конца редактирования» от предыдущего дня —
  // иначе старый интервал продолжает работать в фоне до размонтирования.
  clearInterval(delEditTimerInterval);
  delEditTimeLeft.value = '';
  delEditTimerExpired.value = false;
  delSelectedDate.value = date;
  delExistingOrder.value = null;
  delSubmitError.value = '';
  delSearchQuery.value = '';
  delActiveCategory.value = 'Сухой';
  delOnlyFilled.value = false;
  delOrderComment.value = '';
  delDraftRestoreNotice.value = '';
  delDraftSavedAt.value = null;
  try {
    const order = await roStore.loadMyOrder(date);
    if (requestId !== delSelectRequestId || delSelectedDate.value !== date) return;

    const nextExistingOrder = order || null;
    let nextOrderComment = order?.comment || '';
    const nextItems = order
      ? order.items.map(i => ({ sku: i.sku, product_name: i.product_name, category: i.category, quantity: parseFloat(i.quantity) || 0, comment: i.comment || '', multiplicity: parseFloat(i.multiplicity) || 1, _added: false, _multError: false }))
      : [];

    for (const cat of delCategories) {
      if (nextItems.some(i => i.category === cat)) continue;
      const products = await roStore.loadProducts(cat);
      if (requestId !== delSelectRequestId || delSelectedDate.value !== date) return;
      const existing = new Set(nextItems.filter(i => i.category === cat).map(i => i.sku));
      nextItems.push(...products
        .filter(p => !existing.has(p.sku))
        .map(p => ({ sku: p.sku, product_name: p.name || p.product_name, category: p.category || cat, quantity: 0, comment: '', multiplicity: parseInt(p.multiplicity) || 1, _added: false, _multError: false })));
    }

    for (const item of nextItems) delCheckMultiplicity(item);
    nextItems.sort((a, b) => {
      if (a.category !== b.category) return delCategories.indexOf(a.category) - delCategories.indexOf(b.category);
      return (a.quantity > 0 ? 0 : 1) - (b.quantity > 0 ? 0 : 1);
    });

    const draft = delLoadDraft(date);
    const dayInfo = roStore.deliveryDays.find(d => d.date === date);
    const canSubmit = ['open', 'warning'].includes(dayInfo?.deadline_status);
    const canEdit = !!(dayInfo?.can_edit && nextExistingOrder);
    let restoreNotice = '';
    if (draft && (canSubmit || canEdit)) {
      let restored = 0;
      for (const dItem of (draft.items || [])) {
        const existing = nextItems.find(i => i.sku === dItem.sku);
        if (existing) {
          if (dItem.quantity !== existing.quantity || (dItem.comment || '') !== (existing.comment || '')) {
            existing.quantity = dItem.quantity;
            existing.comment = dItem.comment || '';
            if (dItem.multiplicity) existing.multiplicity = parseFloat(dItem.multiplicity) || existing.multiplicity || 1;
            delCheckMultiplicity(existing);
            restored++;
          }
        } else if (dItem.quantity > 0) {
          const newItem = { sku: dItem.sku, product_name: dItem.product_name, category: dItem.category || 'Сухой', quantity: dItem.quantity, comment: dItem.comment || '', multiplicity: parseFloat(dItem.multiplicity) || 1, _added: true, _multError: false };
          delCheckMultiplicity(newItem);
          nextItems.push(newItem);
          restored++;
        }
      }
      if (draft.comment && draft.comment !== nextOrderComment) {
        nextOrderComment = draft.comment;
        restored++;
      }
      if (restored > 0) {
        const ts = draft.savedAt ? new Date(draft.savedAt).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '';
        restoreNotice = `Восстановлен черновик от ${ts}`;
      }
    }

    if (requestId !== delSelectRequestId || delSelectedDate.value !== date) return;
    delExistingOrder.value = nextExistingOrder;
    delOrderComment.value = nextOrderComment;
    delOrderItems.value = nextItems;
    delDraftRestoreNotice.value = restoreNotice;
    if (restoreNotice) {
      setTimeout(() => {
        if (delSelectedDate.value === date && delDraftRestoreNotice.value === restoreNotice) delDraftRestoreNotice.value = '';
      }, 8000);
    }
    delSavedSnapshot.value = delSerializeSnapshot(nextItems, nextOrderComment);
  } catch (e) {
    if (requestId !== delSelectRequestId || delSelectedDate.value !== date) return;
    delOrderItems.value = [];
    delSubmitError.value = e.message || 'Не удалось загрузить заказ на выбранную дату';
  }
}

// ═══ Автосохранение черновика основной поставки ═══
const delDraftRestoreNotice = ref('');
const delDraftSavedAt = ref(null); // timestamp последнего автосохранения для индикатора
const delDraftSavedLabel = computed(() => {
  if (!delDraftSavedAt.value) return '';
  const dt = new Date(delDraftSavedAt.value);
  return dt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
});
let delDraftSaveTimer = null;

function delDraftKey(date) {
  const rn = roStore.restaurant?.number || 'unknown';
  // legal_entity_group в ключе обязателен: номера BK_VM и PS могут совпадать
  // (например, BK_VM #5 и PS #5 в diapason 1000+), и без префикса группы
  // черновик одного юрлица подцепится в кабинете другого на том же устройстве.
  const grp = roStore.restaurant?.legal_entity_group || 'BK_VM';
  return `bk_ro_draft_${grp}_${rn}_${date}`;
}

function delSaveDraft() {
  if (!delSelectedDate.value) return;
  // Не сохраняем, если ничего не введено
  const meaningful = delOrderItems.value.filter(i => i.quantity > 0);
  if (!meaningful.length && !delOrderComment.value) {
    try { localStorage.removeItem(delDraftKey(delSelectedDate.value)); } catch {}
    return;
  }
  try {
    const ts = Date.now();
    localStorage.setItem(delDraftKey(delSelectedDate.value), JSON.stringify({
      items: meaningful.map(i => ({ sku: i.sku, product_name: i.product_name, category: i.category, quantity: i.quantity, comment: i.comment, multiplicity: i.multiplicity })),
      comment: delOrderComment.value || '',
      savedAt: ts,
    }));
    delDraftSavedAt.value = ts;
  } catch {}
}

function delLoadDraft(date) {
  try {
    const raw = localStorage.getItem(delDraftKey(date));
    if (!raw) return null;
    return JSON.parse(raw);
  } catch { return null; }
}

function delClearDraft(date) {
  try { localStorage.removeItem(delDraftKey(date)); } catch {}
  delDraftSavedAt.value = null;
}

// Watch — дебаунс 800мс на сохранение
watch([() => delOrderItems.value.map(i => ({ s: i.sku, q: i.quantity, c: i.comment })), delOrderComment], () => {
  if (!delSelectedDate.value) return;
  if (delDraftSaveTimer) clearTimeout(delDraftSaveTimer);
  delDraftSaveTimer = setTimeout(delSaveDraft, 800);
}, { deep: true });

async function delLoadCategoryProducts(category) {
  delProductsLoading.value = true;
  try {
    const products = await roStore.loadProducts(category);
    const existing = new Set(delOrderItems.value.filter(i => i.category === category).map(i => i.sku));
    const newItems = products.filter(p => !existing.has(p.sku)).map(p => ({ sku: p.sku, product_name: p.name || p.product_name, category: p.category || category, quantity: 0, comment: '', multiplicity: parseInt(p.multiplicity) || 1, _added: false, _multError: false }));
    delOrderItems.value.push(...newItems);
  } finally { delProductsLoading.value = false; }
}

// Дозагрузка всех товаров шаблона (по всем категориям). Добавляются только те,
// которых ещё нет в заказе — с количеством 0.
async function delLoadFullTemplate() {
  delLoadingTemplate.value = true;
  try {
    let added = 0;
    for (const cat of delCategories) {
      const products = await roStore.loadProducts(cat);
      const existing = new Set(delOrderItems.value.filter(i => i.category === cat).map(i => i.sku));
      for (const p of products) {
        if (existing.has(p.sku)) continue;
        delOrderItems.value.push({
          sku: p.sku,
          product_name: p.name || p.product_name,
          category: p.category || cat,
          quantity: 0,
          comment: '',
          multiplicity: parseInt(p.multiplicity) || 1,
          _added: false,
          _multError: false,
        });
        existing.add(p.sku);
        added++;
      }
    }
    // Пересортируем: сначала с количеством, потом без, по категориям
    delOrderItems.value.sort((a, b) => {
      if (a.category !== b.category) return delCategories.indexOf(a.category) - delCategories.indexOf(b.category);
      return (a.quantity > 0 ? 0 : 1) - (b.quantity > 0 ? 0 : 1);
    });
    if (added === 0) {
      showInfo('Шаблон', 'Все товары из шаблона уже есть в заказе.');
    } else {
      showInfo('Шаблон загружен', `Добавлено товаров: ${added}. Осталось только проставить количества.`);
    }
  } catch (e) {
    showInfo('Ошибка', e.message || 'Не удалось загрузить шаблон', 'error');
  } finally {
    delLoadingTemplate.value = false;
  }
}

async function delHandleSubmit() {
  // Защита от двойного клика: на iOS Safari два быстрых нажатия успевают пройти
  // до того, как :disabled применится по reactivity. Без этой проверки уходит
  // два запроса подряд, и второй упирается в уникальный ключ либо создаёт дубль.
  if (delSubmitting.value) return;
  const items = delOrderItems.value.filter(i => i.quantity > 0).map(i => ({
    sku: i.sku,
    product_name: i.product_name,
    category: i.category,
    quantity: i.quantity,
    comment: i.comment || '',
  }));
  if (!items.length) { delSubmitError.value = 'Добавьте хотя бы одну позицию'; return; }
  // Подтверждение с конкретными цифрами — заказ уходит на склад,
  // отменить нельзя, можно только отредактировать до edit_until.
  const isUpdate = !!delExistingOrder.value;
  const totalQty = items.reduce((s, i) => s + (parseFloat(i.quantity) || 0), 0);
  const confirmMsg = `${items.length} ${pluralPositions(items.length)}, ${totalQty} ${pluralBoxes(totalQty)}. ` +
    `Доставка ${fmtDate(delSelectedDate.value)}. Изменить можно до ${delEditDeadlineTime.value}.`;
  const ok = await showConfirm(
    isUpdate ? 'Обновить заказ?' : 'Отправить заказ?',
    confirmMsg,
    { okText: isUpdate ? 'Обновить' : 'Отправить' }
  );
  if (!ok) return;
  delSubmitting.value = true; delSubmitError.value = '';
  try {
    // Принудительно сохраняем черновик ДО отправки. Если запрос упадёт (плохая
    // связь, метро) и юзер закроет вкладку — введённое не пропадёт.
    try { delSaveDraft(); } catch (e) { /* игнор */ }
    const result = await roStore.submitOrder(delSelectedDate.value, items, delOrderComment.value || null);
    if (result.success) {
      delClearDraft(delSelectedDate.value);
      delWasEdited.value = !!delExistingOrder.value;
      delExistingOrder.value = { id: result.order_id };
      delSavedSnapshot.value = delSerializeState();
      delShowSuccess.value = true;
      delStartEditTimer();
      try { await roStore.loadMyInfo(); } catch {}
      try { await loadHistory(); } catch {}
      try {
        delPreviousOrders.value = (await roStore.loadMyOrders(5)).filter(o => o.status === 'submitted' || o.status === 'edited');
      } catch {}
    }
  } catch (e) { delSubmitError.value = e.message || 'Ошибка'; }
  finally { delSubmitting.value = false; }
}

function delStartEditTimer() { clearInterval(delEditTimerInterval); delEditTimerExpired.value = false; delUpdateEditTimeLeft(); delEditTimerInterval = setInterval(delUpdateEditTimeLeft, 1000); }
function delUpdateEditTimeLeft() {
  const deadlines = delCurrentDeadlines.value;
  const editUntil = deadlines?.edit_until || deadlines?.hard || '13:00:00';
  const parts = editUntil.split(':');
  // Дедлайн относится к дню подачи = день доставки минус 1
  const deliveryDate = delSelectedDate.value;
  if (!deliveryDate) { delEditTimeLeft.value = ''; return; }
  const orderDate = new Date(deliveryDate + 'T00:00:00');
  orderDate.setDate(orderDate.getDate() - 1);
  const orderDateStr = delDateToLocalYmd(orderDate);
  // Собираем дедлайн в минском времени (UTC+3)
  const dlMinsk = new Date(`${orderDateStr}T${editUntil}+03:00`);
  // Серверное время через сохранённый offset — защита от сбитых часов устройства.
  const now = new Date(roStore.nowFromServer ? roStore.nowFromServer() : Date.now());
  if (now >= dlMinsk) { delEditTimeLeft.value = ''; delEditTimerExpired.value = true; clearInterval(delEditTimerInterval); return; }
  const d = dlMinsk - now; const h = Math.floor(d/3600000); const m = Math.floor((d%3600000)/60000); const s = Math.floor((d%60000)/1000);
  delEditTimeLeft.value = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
}
function delGoToNextDay() { delShowSuccess.value = false; clearInterval(delEditTimerInterval); const idx = roStore.deliveryDays.findIndex(d => d.date === delSelectedDate.value); const next = roStore.deliveryDays[idx + 1]; if (next) delSelectDay(next.date); }
async function delClearOrder() {
  const ok = await showConfirm('Очистить заказ', 'Очистить все количества?', { okText: 'Очистить', danger: true });
  if (!ok) return;
  for (const item of delOrderItems.value) { item.quantity = 0; item.comment = ''; item._multError = false; }
}
function delRemoveItem(item) { const idx = delOrderItems.value.indexOf(item); if (idx >= 0) delOrderItems.value.splice(idx, 1); }

async function delHandleRepeat(sourceOrderId) {
  try {
    const result = await roStore.repeatOrder(sourceOrderId, delSelectedDate.value);
    if (result.items) {
      for (const item of result.items) {
        const existing = delOrderItems.value.find(i => i.sku === item.sku);
        if (existing) {
          existing.quantity = parseFloat(item.quantity) || 0;
          existing.comment = item.comment || '';
          existing.multiplicity = parseFloat(item.multiplicity) || existing.multiplicity || 1;
          delCheckMultiplicity(existing);
        } else {
          const newItem = { sku: item.sku, product_name: item.product_name, category: item.category, quantity: parseFloat(item.quantity) || 0, comment: item.comment || '', multiplicity: parseFloat(item.multiplicity) || 1, _added: true, _multError: false };
          delCheckMultiplicity(newItem);
          delOrderItems.value.push(newItem);
        }
      }
      delRefreshMultiplicityErrors();
    }
  } catch (e) { delSubmitError.value = e.message || 'Ошибка'; }
}

watch(delShowAddModal, (v) => { if (v) { delAddSearch.value = ''; delAddResults.value = []; nextTick(() => delAddSearchInput.value?.focus()); } });
function delDoAddSearch() {
  clearTimeout(delAddTimer);
  if (!delAddSearch.value || delAddSearch.value.length < 2) { delAddResults.value = []; return; }
  delAddTimer = setTimeout(async () => { delAddLoading.value = true; try { const products = await roStore.loadProducts(null, delAddSearch.value); const existingSkus = new Set(delOrderItems.value.map(i => i.sku)); delAddResults.value = products.filter(p => !existingSkus.has(p.sku)); } catch { delAddResults.value = []; } finally { delAddLoading.value = false; } }, 300);
}
function delAddProduct(product) {
  const cat = product.category || delActiveCategory.value;
  delOrderItems.value.push({ sku: product.sku, product_name: product.name || product.product_name, category: cat, quantity: 0, comment: '', multiplicity: parseInt(product.multiplicity) || 1, _added: true, _multError: false });
  delAddResults.value = delAddResults.value.filter(p => p.sku !== product.sku);
  delActiveCategory.value = cat; delShowAddModal.value = false;
}

async function delExportExcel() {
  const XLSX = await import('xlsx-js-style');
  const wb = XLSX.utils.book_new();
  const header = ['Товар', 'Категория', 'Кратность', 'Кол-во (кор.)', 'Комментарий'];
  const rows = [header];
  for (const cat of delCategories) { for (const item of delOrderItems.value.filter(i => i.category === cat)) { rows.push([`${item.sku} ${item.product_name}`, item.category, item.multiplicity > 1 ? item.multiplicity : '', item.quantity > 0 ? item.quantity : '', item.comment || '']); } }
  const ws = XLSX.utils.aoa_to_sheet(rows);
  const sH = { font: { bold: true, sz: 11, color: { rgb: 'FFFFFF' } }, fill: { fgColor: { rgb: '502314' } }, alignment: { horizontal: 'center' } };
  for (let c = 0; c < header.length; c++) { const cell = ws[XLSX.utils.encode_cell({ r: 0, c })]; if (cell) cell.s = sH; }
  ws['!cols'] = [{ wch: 40 }, { wch: 10 }, { wch: 8 }, { wch: 12 }, { wch: 20 }];
  const prettyNum = formatRestaurantNumber(roStore.restaurant?.number, roStore.restaurant?.legal_entity_group);
  XLSX.utils.book_append_sheet(wb, ws, `Заказ ${prettyNum}`.slice(0, 31));
  XLSX.writeFile(wb, `Заказ_${prettyNum}_${delSelectedDate.value}.xlsx`);
}

// ═══ Supplier orders ═══
const supSelectedDates = reactive({});
const supProducts = reactive({});
const supQuantities = reactive({});
const supAdminEdits = reactive({}); // { supId: { sku: { original, edited } } } — правки отдела закупок
const supProductsLoading = reactive({});
const supIsSkipOrder = reactive({}); // { supId: true } — заявка с флагом «поставка не нужна»
const supPreviousOrders = reactive({}); // { supId: previousOrder } — предыдущая заявка для справки
const supShowPreviousOrder = reactive({}); // { supId: true } — раскрыт ли блок
const supSubmitting = reactive({});
const supShowSuccess = ref(false);
const supSuccessInfo = ref({});
const supDeadlineTimeLeft = reactive({}); // { supId: 'HH:MM:SS' } — обратный отсчёт до дедлайна
let supDeadlineTimerInterval = null;

function supDeadlineTimerNeeded() {
  return activeTab.value === 'orders' && typeof orderSubTab.value === 'string' && orderSubTab.value.startsWith('sup_');
}
function ensureSupDeadlineTimer() {
  if (!supDeadlineTimerNeeded()) { stopSupDeadlineTimer(); return; }
  if (supDeadlineTimerInterval) return;
  supUpdateDeadlineTimers();
  supDeadlineTimerInterval = setInterval(supUpdateDeadlineTimers, 1000);
}
function stopSupDeadlineTimer() {
  if (supDeadlineTimerInterval) { clearInterval(supDeadlineTimerInterval); supDeadlineTimerInterval = null; }
}

function supUpdateDeadlineTimers() {
  const nowMs = Date.now();
  for (const sup of suppliers.value || []) {
    const info = supCurrentDateInfo(sup);
    if (info?.deadline_status === 'open' && info?.deadline) {
      const left = deadlineTimeLeftString(info.deadline, nowMs);
      if (left) supDeadlineTimeLeft[sup.id] = left;
      else delete supDeadlineTimeLeft[sup.id];
    } else {
      delete supDeadlineTimeLeft[sup.id];
    }
  }
}

function supplierBadge(sup) { if (!sup.is_accepting_orders) return { text: 'пауза', type: 'pause' }; const submitted = sup.available_dates?.filter(d => d.order).length || 0; const open = sup.available_dates?.filter(d => d.deadline_status === 'open' && !d.order).length || 0; if (open > 0) return { text: open, type: 'warn' }; if (submitted > 0) return { text: submitted, type: 'ok' }; return null; }
function supCurrentDateInfo(sup) { if (!supSelectedDates[sup.id]) return null; return sup.available_dates?.find(d => d.delivery_date === supSelectedDates[sup.id]); }
function formatDeadline(dl) { if (!dl) return ''; const [date, time] = dl.split(' '); const d = new Date(date + 'T00:00:00'); const label = d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', weekday: 'short' }); return (time || '') + ', ' + label; }

async function supSelectDate(sup, dateInfo) {
  const nextRequestId = (supLoadRequestId[sup.id] || 0) + 1;
  supLoadRequestId[sup.id] = nextRequestId;
  supSelectedDates[sup.id] = dateInfo.delivery_date;
  supProductsLoading[sup.id] = true;
  const nextQuantities = {};
  const nextAdminEdits = {};
  let nextIsSkip = false;
  try {
    const products = await soStore.loadProducts(sup.id);
    if (supLoadRequestId[sup.id] !== nextRequestId || supSelectedDates[sup.id] !== dateInfo.delivery_date) return;
    let displayProducts = products;
    // Всегда грузим заявку (вернёт previous_order, даже если текущей нет)
    const { order, previousOrder } = await soStore.loadMyOrder(sup.id, dateInfo.delivery_date);
    if (supLoadRequestId[sup.id] !== nextRequestId || supSelectedDates[sup.id] !== dateInfo.delivery_date) return;
    supPreviousOrders[sup.id] = previousOrder;
    if (order) {
      const itemCount = order?.items?.length || 0;
      if (itemCount > 0) {
        for (const item of order.items) {
          const orig = parseFloat(item.quantity) || 0;
          const adminQ = (item.admin_qty !== null && item.admin_qty !== undefined && item.admin_qty !== '')
            ? parseFloat(item.admin_qty) : null;
          // Эффективное значение: правка отдела закупок, если есть, иначе исходное
          nextQuantities[item.sku] = adminQ !== null ? adminQ : orig;
          // Помечаем правку, если значение реально изменилось
          if (adminQ !== null && Math.abs(adminQ - orig) > 0.001) {
            nextAdminEdits[item.sku] = { original: orig, edited: adminQ };
          }
        }
        // Если дедлайн закрыт — показываем позиции из заявки, а не текущий шаблон,
        // чтобы старые SKU (до смены шаблона) отображались корректно
        if (dateInfo.deadline_status === 'closed') {
          displayProducts = order.items.map(item => ({
            sku: item.sku,
            product_name: item.product_name || item.name || item.sku,
            multiplicity: item.multiplicity ?? null,
            min_qty: item.min_qty ?? null,
          }));
        }
      } else {
        // Заявка есть, но позиций нет → «Поставка не нужна»: ставим нули во все поля
        nextIsSkip = true;
        for (const p of products) {
          nextQuantities[p.sku] = 0;
        }
      }
    }
    if (supLoadRequestId[sup.id] !== nextRequestId || supSelectedDates[sup.id] !== dateInfo.delivery_date) return;
    supProducts[sup.id] = displayProducts;
    supQuantities[sup.id] = nextQuantities;
    supAdminEdits[sup.id] = nextAdminEdits;
    supIsSkipOrder[sup.id] = nextIsSkip;
  } catch (e) {
    if (supLoadRequestId[sup.id] !== nextRequestId) return;
    supProducts[sup.id] = [];
    supQuantities[sup.id] = {};
    supAdminEdits[sup.id] = {};
    supIsSkipOrder[sup.id] = false;
    showInfo('Ошибка', e.message || 'Не удалось загрузить заявку поставщика', 'error');
  } finally {
    if (supLoadRequestId[sup.id] === nextRequestId) supProductsLoading[sup.id] = false;
  }
}

function supAdminEditInfo(supId, sku) {
  return supAdminEdits[supId]?.[sku] || null;
}

function supFmtNum(v) { const n = parseFloat(v); return n % 1 === 0 ? n.toFixed(0) : n.toString(); }
function supMultError(supId, p) { const m = parseFloat(p.multiplicity); if (!m || m <= 0) return false; const val = parseFloat(supQuantities[supId]?.[p.sku]) || 0; if (val === 0) return false; const rem = Math.abs(val % m); return rem > 0.001 && Math.abs(rem - m) > 0.001; }
function supMinError(supId, p) { const min = parseFloat(p.min_qty); if (!min || min <= 0) return false; const val = parseFloat(supQuantities[supId]?.[p.sku]) || 0; return val > 0 && val < min; }
function supHasError(supId, p) { return supMultError(supId, p) || supMinError(supId, p); }
function supHasErrors(supId) { return (supProducts[supId] || []).some(p => supHasError(supId, p)); }
function supFilledCount(supId) { return Object.values(supQuantities[supId] || {}).filter(v => v > 0).length; }
function supFilledTotal(supId) { return Object.values(supQuantities[supId] || {}).reduce((s, v) => s + (v > 0 ? v : 0), 0); }

async function supHandleRepeatPrevious(sup) {
  const prev = supPreviousOrders[sup.id];
  if (!prev?.items?.length) return;
  const ok = await showConfirm(
    'Повторить предыдущую заявку',
    `Заполнить позициями из заявки от ${fmtDate(prev.delivery_date)}?`,
    { okText: 'Повторить' },
  );
  if (!ok) return;
  const available = new Set((supProducts[sup.id] || []).map(p => p.sku));
  if (!supQuantities[sup.id]) supQuantities[sup.id] = {};
  let applied = 0;
  let skipped = 0;
  for (const it of prev.items) {
    if (available.has(it.sku)) {
      supQuantities[sup.id][it.sku] = parseFloat(it.quantity) || 0;
      applied++;
    } else {
      skipped++;
    }
  }
  supIsSkipOrder[sup.id] = false;
  if (skipped > 0) {
    showInfo('Готово', `Скопировано позиций: ${applied}. Пропущено (нет в шаблоне): ${skipped}.`, 'info');
  }
}

async function supHandleSubmit(sup) {
  supSubmitting[sup.id] = true;
  try {
    const items = (supProducts[sup.id] || []).filter(p => supQuantities[sup.id][p.sku] > 0).map(p => ({ product_id: p.product_id || p.id || '', sku: p.sku, product_name: p.product_name || p.name || '', quantity: supQuantities[sup.id][p.sku] }));
    const dateInfo = supCurrentDateInfo(sup);
    const result = await soStore.submitOrder(sup.id, supSelectedDates[sup.id], dateInfo?.order_date || '', items);
    if (result.success) {
      supSuccessInfo.value = { supplier_name: sup.name, delivery_date: supSelectedDates[sup.id], total_items: items.length, total_qty: items.reduce((s, i) => s + i.quantity, 0) };
      supShowSuccess.value = true;
      try { suppliers.value = await soStore.loadSuppliers(); } catch {}
      try { await loadHistory(); } catch {}
    }
  } catch (e) { showInfo('Ошибка', e.message || 'Ошибка отправки', 'error'); }
  finally { supSubmitting[sup.id] = false; }
}

async function supSkipDelivery(sup) {
  const ok = await showConfirm('Отказ от поставки', `Подтвердить, что поставка от «${sup.name}» на эту дату не нужна?`, { okText: 'Не нужна', danger: true });
  if (!ok) return;
  supSubmitting[sup.id] = true;
  try {
    // Сбрасываем все количества в форме
    if (supProducts[sup.id]) {
      for (const p of supProducts[sup.id]) {
        supQuantities[sup.id][p.sku] = 0;
      }
    }
    const dateInfo = supCurrentDateInfo(sup);
    const result = await soStore.submitOrder(
      sup.id,
      supSelectedDates[sup.id],
      dateInfo?.order_date || '',
      [],
      { skipDelivery: true }
    );
    if (result.success) {
      supSuccessInfo.value = { supplier_name: sup.name, delivery_date: supSelectedDates[sup.id], total_items: 0, total_qty: 0, skipped: true };
      supShowSuccess.value = true;
      try { suppliers.value = await soStore.loadSuppliers(); } catch {}
      try { await loadHistory(); } catch {}
    }
  } catch (e) { showInfo('Ошибка', e.message || 'Ошибка отправки', 'error'); }
  finally { supSubmitting[sup.id] = false; }
}

// ═══ Общее ═══
async function switchTab(tab, subTab) {
  const curTab = activeTab.value;
  const curSub = orderSubTab.value;
  const nextTab = tab;
  const requestedSub = (tab === 'orders') ? (subTab || orderSubTab.value || defaultOrderSubTab.value) : null;
  const normalizedSub = requestedSub === 'delivery' && !roStore.restaurantOrdersEnabled
    ? defaultOrderSubTab.value
    : requestedSub;
  const nextSub = normalizedSub;

  // Определяем, какую под-вкладку пользователь реально покидает
  const leavingDelivery = curTab === 'orders' && curSub === 'delivery' && !(nextTab === 'orders' && nextSub === 'delivery');
  const leavingProfile = curTab === 'profile' && nextTab !== 'profile';

  if (leavingDelivery && delHasUnsavedChanges.value) {
    const ok = await showConfirm('Несохранённые изменения', 'В заказе есть несохранённые изменения. Перейти на другую вкладку?', { okText: 'Перейти' });
    if (!ok) return;
  }
  if (leavingProfile && (pwOld.value || pwNew.value)) {
    const ok = await showConfirm('Смена пароля', 'Вы начали менять пароль. Перейти на другую вкладку?', { okText: 'Перейти' });
    if (!ok) return;
  }
  activeTab.value = tab;
  if (tab === 'orders') {
    orderSubTab.value = nextSub || defaultOrderSubTab.value;
  } else if (subTab) {
    orderSubTab.value = subTab;
  }
  // Сразу шлём heartbeat, чтобы в /admin страница обновилась без задержки таймера.
  if (heartbeatTimer) sendHeartbeat();
  if (tab === 'orders') {
    const sub = nextSub || orderSubTab.value;
    if (sub === 'delivery') {
      ensureDeliveryDaySelected();
      loadPreviousDeliveryOrders();
    }
    if (sub === 'history' && !historyOrders.value.length) loadHistory();
    if (sub && sub.startsWith('sup_')) {
      const supId = sub.slice(4);
      const sup = suppliers.value.find(s => String(s.id) === String(supId));
      if (sup) {
        const cur = supSelectedDates[sup.id];
        const stillValid = cur && sup.available_dates?.some(d => d.delivery_date === cur);
        if (!stillValid) supAutoSelectDate(sup);
      }
    }
  }
  if (tab === 'surveys' && !surveyItems.value.length && !surveyListLoading.value) {
    loadSurveyList();
  }
  if (tab === 'info') {
    if (!importantPosts.value.length && !importantLoading.value) {
      loadImportantPosts({ previewAll: true });
    } else {
      loadImportantImagePreviews(importantPosts.value.length);
    }
  }
  if (tab === 'stock' && stockCollection.active) loadStockInline(stockCollection.selectedId);
  if (tab === 'warehouse-stock' && !warehouseStockItems.value.length && !warehouseStockLoading.value) loadWarehouseStock();
  ensureSupDeadlineTimer();
}
// ═══ Синхронизация табов с роутом (URL) ═══
function applyRouteToState() {
  const name = route.name;
  if (!name) return;
  if (name === 'restaurant-dashboard') {
    activeTab.value = 'dashboard';
  } else if (name === 'restaurant-orders-tab' || name === 'restaurant-orders-delivery') {
    activeTab.value = 'orders';
    orderSubTab.value = roStore.restaurantOrdersEnabled ? 'delivery' : defaultOrderSubTab.value;
  } else if (name === 'restaurant-orders-planeta') {
    activeTab.value = 'orders';
    orderSubTab.value = roStore.restaurantOrdersEnabled ? 'delivery' : defaultOrderSubTab.value;
  } else if (name === 'restaurant-orders-history') {
    activeTab.value = 'orders';
    orderSubTab.value = 'history';
    if (!historyOrders.value.length) loadHistory();
  } else if (name === 'restaurant-orders-supplier') {
    activeTab.value = 'orders';
    const supId = String(route.params.supplierId || '');
    orderSubTab.value = 'sup_' + supId;
    const sup = suppliers.value.find(s => String(s.id) === supId);
    if (sup && !supSelectedDates[sup.id]) supAutoSelectDate(sup);
  } else if (name === 'restaurant-info') {
    activeTab.value = 'info';
    if (!importantPosts.value.length && !importantLoading.value) loadImportantPostsWithOptions({ previewAll: true });
  } else if (name === 'restaurant-surveys') {
    activeTab.value = 'surveys';
    if (!surveyItems.value.length && !surveyListLoading.value) loadSurveyList();
  } else if (name === 'restaurant-stock') {
    activeTab.value = 'stock';
  } else if (name === 'restaurant-warehouse-stock') {
    activeTab.value = 'warehouse-stock';
    if (!warehouseStockItems.value.length && !warehouseStockLoading.value) loadWarehouseStock();
  } else if (name === 'restaurant-scanner') {
    activeTab.value = 'scanner';
  } else if (name === 'restaurant-profile') {
    activeTab.value = 'profile';
  } else if (name === 'restaurant-keg-returns') {
    activeTab.value = 'keg-returns';
  } else if (name === 'restaurant-reminders') {
    activeTab.value = 'orders';
    orderSubTab.value = 'reminders';
  } else if (name === 'restaurant-orders-corrections') {
    activeTab.value = 'orders';
    orderSubTab.value = 'corrections';
  } else if (name === 'restaurant-orders-assistant') {
    activeTab.value = 'orders';
    orderSubTab.value = 'assistant';
  }
  ensureSupDeadlineTimer();
}

function syncStateToRoute() {
  let target = null;
  if (activeTab.value === 'dashboard') {
    target = { name: 'restaurant-dashboard' };
  } else if (activeTab.value === 'info') {
    target = { name: 'restaurant-info' };
  } else if (activeTab.value === 'surveys') {
    target = { name: 'restaurant-surveys' };
  } else if (activeTab.value === 'stock') {
    target = { name: 'restaurant-stock' };
  } else if (activeTab.value === 'warehouse-stock') {
    target = { name: 'restaurant-warehouse-stock' };
  } else if (activeTab.value === 'scanner') {
    target = { name: 'restaurant-scanner' };
  } else if (activeTab.value === 'profile') {
    target = { name: 'restaurant-profile' };
  } else if (activeTab.value === 'keg-returns') {
    target = { name: 'restaurant-keg-returns' };
  } else if (activeTab.value === 'orders' && orderSubTab.value === 'reminders') {
    target = { name: 'restaurant-reminders' };
  } else if (activeTab.value === 'orders' && orderSubTab.value === 'corrections') {
    target = { name: 'restaurant-orders-corrections' };
  } else if (activeTab.value === 'orders' && orderSubTab.value === 'assistant') {
    target = { name: 'restaurant-orders-assistant' };
  } else if (activeTab.value === 'orders') {
    const sub = orderSubTab.value;
    if (sub === 'delivery' && roStore.restaurantOrdersEnabled) target = { name: 'restaurant-orders-delivery' };
    else if (sub === 'history') target = { name: 'restaurant-orders-history' };
    else if (sub && sub.startsWith('sup_')) {
      target = { name: 'restaurant-orders-supplier', params: { supplierId: sub.slice(4) } };
    } else {
      target = roStore.restaurantOrdersEnabled
        ? { name: 'restaurant-orders-delivery' }
        : { name: 'restaurant-orders-history' };
    }
  }
  if (!target) return;
  const resolved = router.resolve(target);
  if (resolved.fullPath !== route.fullPath) {
    router.replace(target).catch(() => {});
  }
}

// Реакция на навигацию через браузер (back/forward) или переход по ссылке
watch(() => route.fullPath, applyRouteToState);
// Реакция на смену табов в любом месте кода — обновляем URL
watch([activeTab, orderSubTab], syncStateToRoute);

function supAutoSelectDate(sup) {
  const dates = sup.available_dates || [];
  if (!dates.length) return;
  // Из открытых выбираем самую раннюю (ближайшую к сегодня), чтобы не пропустить дедлайн
  const sortedAsc = [...dates].sort((a, b) => a.delivery_date.localeCompare(b.delivery_date));
  const openNoOrder = sortedAsc.find(d => d.deadline_status === 'open' && !d.order);
  const open = sortedAsc.find(d => d.deadline_status === 'open');
  const target = openNoOrder || open || sortedAsc[0];
  if (target) supSelectDate(sup, target);
}

function handleLogout() { roStore.logout(); router.replace({ name: 'restaurant-order-login' }); }

// Format helpers
// fmtDate, fmtDateShort, fmtDateTime, statusLabel imported from roUtils.js

const HISTORY_PAGE_SIZE = 20;
const historyHasMore = ref(false);
const historyLoadingMore = ref(false);

async function loadHistory() {
  historyLoading.value = true;
  historyError.value = '';
  try {
    const { orders, hasMore } = await roStore.loadAllHistory({ limit: HISTORY_PAGE_SIZE });
    historyOrders.value = orders;
    historyHasMore.value = hasMore;
  } catch (e) {
    historyOrders.value = [];
    historyHasMore.value = false;
    historyError.value = e.message || 'Не удалось загрузить историю заказов';
  }
  finally { historyLoading.value = false; }
}

async function loadMoreHistory() {
  if (historyLoadingMore.value || !historyHasMore.value) return;
  const last = historyOrders.value[historyOrders.value.length - 1];
  if (!last?.delivery_date) { historyHasMore.value = false; return; }
  historyLoadingMore.value = true;
  try {
    const { orders, hasMore } = await roStore.loadAllHistory({
      limit: HISTORY_PAGE_SIZE,
      beforeDate: last.delivery_date,
    });
    // На граничной дате могут прийти заказы с той же датой, что и last —
    // отфильтруем дубли по (source, id)
    const seen = new Set(historyOrders.value.map(o => o.source + ':' + o.id));
    const fresh = orders.filter(o => !seen.has(o.source + ':' + o.id));
    historyOrders.value = historyOrders.value.concat(fresh);
    historyHasMore.value = hasMore;
  } catch (e) {
    historyError.value = e.message || 'Не удалось подгрузить ещё';
  } finally { historyLoadingMore.value = false; }
}

async function openHistoryOrder(order) {
  if (!order?.id || !order?.source) return;
  historyOrderModal.show = true;
  historyOrderModal.loading = true;
  historyOrderModal.error = '';
  historyOrderModal.order = null;
  try {
    historyOrderModal.order = await roStore.loadHistoryOrder(order.source, order.id);
  } catch (e) {
    historyOrderModal.error = e.message || 'Не удалось открыть заказ';
  } finally {
    historyOrderModal.loading = false;
  }
}

function closeHistoryOrderModal() {
  historyOrderModal.show = false;
  historyOrderModal.loading = false;
  historyOrderModal.error = '';
  historyOrderModal.order = null;
}

async function loadSurveyList() {
  surveyListLoading.value = true;
  try {
    surveyItems.value = await roStore.loadSurveys();
    if (!surveyItems.value.length && activeTab.value === 'surveys') {
      activeTab.value = 'dashboard';
    }
  } catch (e) {
    surveyItems.value = [];
  } finally {
    surveyListLoading.value = false;
  }
}

// Password change
async function changePassword() {
  pwError.value = ''; pwSuccess.value = false;
  if (pwNew.value !== pwConfirm.value) { pwError.value = 'Пароли не совпадают'; return; }
  if (pwNew.value.length < 8) { pwError.value = 'Минимум 8 символов'; return; }
  pwLoading.value = true;
  try {
    const data = await roStore.changePassword(pwOld.value, pwNew.value);
    if (data.success) { pwSuccess.value = true; pwOld.value = ''; pwNew.value = ''; pwConfirm.value = ''; }
    else { pwError.value = data.error || 'Ошибка'; }
  } catch (e) { pwError.value = e.message || 'Ошибка соединения'; }
  finally { pwLoading.value = false; }
}

// Telegram
async function loadTgStatus() {
  tgError.value = '';
  try {
    await roStore.getTelegramStatus();
  } catch (e) {
    tgError.value = e.message || 'Не удалось получить статус Telegram';
  }
  await loadTgLinks();
}

async function loadTgLinks() {
  try {
    tgLinksList.value = await roStore.telegramLinks();
  } catch (e) {
    if (import.meta.env.DEV) console.warn('[restaurant cabinet] telegram-links:', e);
  }
}

function formatTgDate(s) {
  if (!s) return '';
  const d = new Date(s.replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return s;
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const hh = String(d.getHours()).padStart(2, '0');
  const mi = String(d.getMinutes()).padStart(2, '0');
  return `${dd}.${mm} ${hh}:${mi}`;
}

async function loadRestaurantBroadcasts() {
  try {
    restaurantBroadcasts.value = await roStore.loadBroadcasts();
  } catch (e) {
    if (import.meta.env.DEV) console.warn('[restaurant cabinet] broadcasts:', e);
  }
}

async function loadImportantPosts(options = {}) {
  return loadImportantPostsWithOptions(options);
}

async function loadImportantPostsWithOptions({ previewAll = false } = {}) {
  importantLoading.value = true;
  try {
    importantPosts.value = await roStore.loadCabinetPosts(50);
    await loadImportantImagePreviews(previewAll ? importantPosts.value.length : 1);
  } catch (e) {
    if (import.meta.env.DEV) console.warn('[restaurant cabinet] important-posts:', e);
  } finally {
    importantLoading.value = false;
  }
}

function isImportantImage(file) {
  return String(file?.mime_type || '').startsWith('image/');
}

async function loadImportantImagePreviews(maxPosts = 1) {
  const posts = (importantPosts.value || []).slice(0, Math.max(0, maxPosts));
  for (const post of posts) {
    for (const file of post.files || []) {
      if (!isImportantImage(file) || importantPreviewUrls[file.id]) continue;
      try {
        importantPreviewUrls[file.id] = await roStore.getCabinetFileObjectUrl(file);
      } catch (e) {
        if (import.meta.env.DEV) console.warn('[restaurant cabinet] image-preview:', e);
      }
    }
  }
}

async function markImportantRead(post) {
  if (!post?.id) return;
  try {
    await roStore.markCabinetPostsRead([post.id]);
  } catch (e) {
    if (import.meta.env.DEV) console.warn('[restaurant cabinet] important-read:', e);
  } finally {
    post.is_read = true;
  }
}

async function dismissCurrentImportantPost() {
  const current = currentImportantPost.value;
  if (!current?.id) return;
  await markImportantRead(current);
}

async function downloadImportantFile(file) {
  try {
    await roStore.downloadCabinetFile(file);
  } catch (e) {
    showInfo('Файл', e.message || 'Не удалось скачать файл', 'error');
  }
}

async function previewImportantFile(file) {
  try {
    if (!importantPreviewUrls[file.id]) {
      importantPreviewUrls[file.id] = await roStore.getCabinetFileObjectUrl(file);
    }
    importantImagePreview.url = importantPreviewUrls[file.id];
    importantImagePreview.name = file.file_name || 'Изображение';
    importantImagePreview.show = true;
  } catch (e) {
    showInfo('Изображение', e.message || 'Не удалось открыть изображение', 'error');
  }
}

function closeImportantPreview() {
  importantImagePreview.show = false;
  importantImagePreview.url = '';
  importantImagePreview.name = '';
}

function formatImportantFileSize(size) {
  const n = Number(size || 0);
  if (n >= 1024 * 1024) return `${(n / 1024 / 1024).toFixed(1)} МБ`;
  if (n >= 1024) return `${Math.round(n / 1024)} КБ`;
  return `${n} Б`;
}

async function dismissCurrentBroadcast() {
  const current = currentBroadcast.value;
  if (!current?.id) return;
  try {
    await roStore.markBroadcastRead([current.id]);
  } catch (e) {
    if (import.meta.env.DEV) console.warn('[restaurant cabinet] broadcast-read:', e);
  } finally {
    restaurantBroadcasts.value = restaurantBroadcasts.value.filter(b => b.id !== current.id);
  }
}

function startRestaurantBroadcastPolling() {
  if (restaurantBroadcastTimer) clearInterval(restaurantBroadcastTimer);
  loadRestaurantBroadcasts();
  restaurantBroadcastTimer = setInterval(() => {
    loadRestaurantBroadcasts();
  }, 45000);
}

// Имя страницы для heartbeat — то же, что показывается в шапке кабинета.
function currentPageLabel() {
  const tab = activeTab.value;
  if (tab === 'orders') {
    const sub = orderSubTab.value || '';
    if (sub === 'delivery') return 'Заказы — Основная поставка';
    if (sub === 'corrections') return 'Заказы — Корректировки';
    if (sub === 'history') return 'Заказы — История';
    if (sub === 'reminders') return 'Заказы — Напоминания';
    if (sub.startsWith('sup_')) {
      const supId = sub.slice(4);
      const sup = (roStore.suppliers || []).find(s => String(s.id) === String(supId));
      return sup ? `Заказы — ${sup.short_name || sup.name || ''}`.trim() : 'Заказы — поставщик';
    }
    return 'Заказы';
  }
  const titles = {
    dashboard: 'Главная',
    info: 'Важная информация',
    surveys: 'Опросы',
    stock: 'Сбор остатков',
    'warehouse-stock': 'Остатки склада',
    scanner: 'Сканер товаров',
    'keg-returns': 'Возврат кег',
    profile: 'Профиль',
  };
  return titles[tab] || tab || '';
}

function sendHeartbeat() {
  if (typeof document !== 'undefined' && document.visibilityState !== 'visible') return;
  roStore.heartbeat(currentPageLabel());
}

function startHeartbeat() {
  if (heartbeatTimer) clearInterval(heartbeatTimer);
  sendHeartbeat();
  heartbeatTimer = setInterval(sendHeartbeat, 15000);
}

async function tgGetCode() {
  tgError.value = '';
  tgLinkLoading.value = true;
  try {
    const data = await roStore.telegramLink();
    if (data.code) tgLinkCode.value = data.code;
  } catch (e) {
    tgError.value = e.message || 'Не удалось получить код привязки';
  }
  finally { tgLinkLoading.value = false; }
}
async function tgUnlink(chatId) {
  const title = 'Отвязать этот Telegram?';
  const ok = await showConfirm('Telegram', title, { okText: 'Отвязать', danger: true });
  if (!ok) return;
  try {
    await roStore.telegramUnlink(chatId);
    tgError.value = '';
    await loadTgLinks();
  } catch (e) {
    tgError.value = e.message || 'Не удалось отключить Telegram';
  }
}

function stockUnitShort(u) {
  return { boxes: 'кор.', kg: 'кг', liters: 'л' }[u] || 'шт.';
}

function makeStockBatchRow(expiry_date = '', stock = '') {
  return { expiry_date, stock };
}

function normalizeStockDraft(productId) {
  return (stockDrafts[productId] || [])
    .map(batch => ({
      expiry_date: String(batch.expiry_date ?? '').trim(),
      stock: String(batch.stock ?? '').trim().replace(',', '.'),
    }))
    .filter(batch => batch.stock !== '' && !Number.isNaN(Number(batch.stock)));
}

function stockProductTotal(productId) {
  return normalizeStockDraft(productId)
    .reduce((sum, batch) => sum + (parseFloat(batch.stock) || 0), 0)
    .toFixed(2)
    .replace(/\.00$/, '');
}

function stockBatchLabel(batch) {
  return batch.expiry_date ? formatWarehouseDate(batch.expiry_date) : 'без срока';
}

function stockExpiryRequired(product) {
  return Number(product?.need_expiry) === 1;
}

function addStockBatch(productId) {
  if (!stockDrafts[productId]) stockDrafts[productId] = [];
  stockDrafts[productId].push(makeStockBatchRow());
}

function removeStockBatch(productId, idx) {
  if (!stockDrafts[productId]) return;
  stockDrafts[productId].splice(idx, 1);
  if (!stockDrafts[productId].length) stockDrafts[productId].push(makeStockBatchRow());
}

// Stock collection check
async function checkStockCollection() {
  try {
    const data = await roStore.getStockCollectionStatus();
    stockCollection.active = data.active;
    stockCollection.collections = Array.isArray(data.collections) ? data.collections : (data.collection ? [data.collection] : []);
    const selectedId = stockCollection.selectedId && stockCollection.collections.some(c => String(c.id) === String(stockCollection.selectedId))
      ? stockCollection.selectedId
      : (data.collection?.id || stockCollection.collections[0]?.id || null);
    stockCollection.selectedId = selectedId;
    stockCollection.collection = stockCollection.collections.find(c => String(c.id) === String(selectedId)) || data.collection || null;
    // Если пользователь уже на вкладке остатков — подгружаем форму
    if (stockCollection.active && activeTab.value === 'stock') {
      await loadStockInline(stockCollection.selectedId);
    }
  } catch (e) {
    if (activeTab.value === 'stock') {
      toast.error('Не удалось проверить сбор остатков', e.message || '');
    }
  }
}

async function ensureDeliveryDaySelected() {
  if (!roStore.restaurantOrdersEnabled || !roStore.deliveryDays.length || delSelectedDate.value) return;
  const today = delDateToLocalYmd(new Date());
  const nearest = roStore.deliveryDays.find(d => d.date >= today && d.deadline_status !== 'closed')
    || roStore.deliveryDays.find(d => d.date >= today)
    || roStore.deliveryDays[0];
  if (nearest) await delSelectDay(nearest.date);
}

async function loadPreviousDeliveryOrders() {
  if (!roStore.restaurantOrdersEnabled) {
    delPreviousOrders.value = [];
    return;
  }
  try {
    delPreviousOrders.value = (await roStore.loadMyOrders(5)).filter(o => o.status === 'submitted' || o.status === 'edited');
  } catch (e) {
    if (import.meta.env.DEV) console.warn('[restaurant cabinet] previous-orders:', e);
  }
}

async function selectStockCollection(collectionId) {
  if (!collectionId || String(stockCollection.selectedId) === String(collectionId)) return;
  stockCollection.selectedId = collectionId;
  await loadStockInline(collectionId);
}

async function loadStockInline(collectionId = null) {
  stockLoading.value = true;
  try {
    const targetCollectionId = collectionId || stockCollection.selectedId || stockCollection.collection?.id || null;
    const data = await roStore.getStockCollectionData(targetCollectionId);
    if (!data.active) {
      stockCollection.active = false;
      stockCollection.collections = [];
      stockCollection.selectedId = null;
      stockCollection.collection = null;
      stockProducts.value = [];
      for (const k of Object.keys(stockDrafts)) delete stockDrafts[k];
      for (const k of Object.keys(stockSavedSnapshot)) delete stockSavedSnapshot[k];
      return;
    }
    stockCollection.active = true;
    stockCollection.selectedId = data.collection?.id || targetCollectionId;
    stockCollection.collection = { ...(stockCollection.collection || {}), ...data.collection };
    stockProducts.value = (data.products || []).map(p => ({
      ...p,
      need_expiry: Number(p.need_expiry) === 1,
    }));
    // Заполняем партии ранее сохранёнными значениями
    for (const k of Object.keys(stockDrafts)) delete stockDrafts[k];
    for (const k of Object.keys(stockSavedSnapshot)) delete stockSavedSnapshot[k];
    for (const p of stockProducts.value) {
      const batches = (data.batches?.[p.id] || []).map(b => ({
        expiry_date: b.expiry_date ? String(b.expiry_date).slice(0, 10) : '',
        stock: b.stock != null ? String(b.stock) : '',
      }));
      if (p.need_expiry) {
        stockDrafts[p.id] = batches.length ? batches : [makeStockBatchRow()];
      } else {
        stockDrafts[p.id] = [makeStockBatchRow('', data.values?.[p.id] != null ? String(data.values[p.id]) : (batches[0]?.stock ?? ''))];
      }
      stockSavedSnapshot[p.id] = JSON.stringify(normalizeStockDraft(p.id));
    }
    stockLastSubmittedAt.value = data.last_submitted_at || null;
  } catch (e) {
    toast.error('Ошибка загрузки', e.message || '');
  } finally {
    stockLoading.value = false;
  }
}

async function submitStockInline() {
  if (!stockCollection.collection?.id) return;
  stockSaving.value = true;
  try {
    const items = [];
    for (const p of stockProducts.value) {
      if (stockExpiryRequired(p)) {
        const rawBatches = (stockDrafts[p.id] || []).map(batch => ({
          expiry_date: String(batch.expiry_date ?? '').trim(),
          stock: String(batch.stock ?? '').trim().replace(',', '.'),
        }));
        if (rawBatches.some(batch => batch.stock !== '' && Number.isNaN(Number(batch.stock)))) {
          throw new Error(`У товара «${p.product_name}» указано некорректное количество`);
        }
        // Срок обязателен только если остаток > 0
        if (rawBatches.some(batch => batch.stock !== '' && Number(batch.stock) > 0 && batch.expiry_date === '')) {
          throw new Error(`Для товара «${p.product_name}» нужно указать срок годности (или поставьте остаток 0)`);
        }
        let batches = rawBatches
          .filter(batch => batch.stock !== '')
          .map(batch => ({
            expiry_date: batch.expiry_date,
            stock: parseFloat(batch.stock),
          }));
        // Если все партии пустые — считаем, что остатков нет (0 без срока)
        if (!batches.length) {
          batches = [{ expiry_date: '', stock: 0 }];
        }
        items.push({ product_id: p.id, batches });
      } else {
        const raw = String(stockDrafts[p.id]?.[0]?.stock ?? '').trim().replace(',', '.');
        if (raw !== '' && Number.isNaN(Number(raw))) {
          throw new Error(`У товара «${p.product_name}» указано некорректное количество`);
        }
        // Пустое поле = 0 (нет остатков по товару)
        const stock = raw === '' ? 0 : parseFloat(raw);
        items.push({ product_id: p.id, stock });
      }
    }
    await roStore.submitStockCollection(stockCollection.collection.id, items);
    // Обновляем снапшот и время сохранения
    for (const p of stockProducts.value) {
      stockSavedSnapshot[p.id] = JSON.stringify(normalizeStockDraft(p.id));
    }
    stockLastSubmittedAt.value = new Date().toISOString().slice(0, 19).replace('T', ' ');
    stockSavedFlash.value = true;
    setTimeout(() => { stockSavedFlash.value = false; }, 2000);
    toast.success('Сохранено', 'Остатки записаны');
    // Обновляем счётчик в дашборд-карточке
    checkStockCollection();
  } catch (e) {
    toast.error('Не удалось сохранить', e.message || '');
  } finally {
    stockSaving.value = false;
  }
}

async function loadWarehouseStock() {
  warehouseStockLoading.value = true;
  warehouseStockError.value = '';
  try {
    const data = await roStore.loadWarehouseStock();
    warehouseStockItems.value = data.items || [];
    warehouseStockCustomer.value = data.customer || data.legal_entity || '';
    warehouseStockUploadedAt.value = data.uploaded_at || '';
  } catch (e) {
    warehouseStockError.value = e.message || 'Ошибка загрузки остатков';
  } finally {
    warehouseStockLoading.value = false;
  }
}


function onBeforeUnload(e) {
  if (delHasUnsavedChanges.value || pwOld.value || pwNew.value) {
    e.preventDefault();
    e.returnValue = '';
  }
}

async function loadCabinetData() {
  globalError.value = '';
  await roStore.loadMyInfo();
  try {
    suppliers.value = await soStore.loadSuppliers();
  } catch (e) {
    suppliers.value = [];
    showInfo('Поставщики', e.message || 'Не удалось загрузить список поставщиков', 'error');
  }
  applyRouteToState();
  if (activeTab.value === 'orders' && orderSubTab.value === 'delivery') {
    await ensureDeliveryDaySelected();
    await loadPreviousDeliveryOrders();
  }
  if (activeTab.value === 'orders' && orderSubTab.value === 'history') await loadHistory();
  if (activeTab.value === 'info') await loadImportantPostsWithOptions({ previewAll: true });
  if (activeTab.value === 'surveys') await loadSurveyList();
  if (activeTab.value === 'stock') await checkStockCollection();
  if (activeTab.value === 'profile') await loadTgStatus();
  startCabinetBackgroundLoading();
}

function startCabinetBackgroundLoading() {
  const runId = ++cabinetBackgroundRunId;
  // На старте грузим только то, что нужно дашборду (бейджи, баннеры,
  // карточка «Сегодня»). Тяжёлое (история, telegram-привязка) — только
  // при переходе на соответствующую вкладку (в applyRouteToState).
  const tasks = [
    ['important-posts', () => importantPosts.value.length ? loadImportantImagePreviews(1) : loadImportantPostsWithOptions({ previewAll: false })],
    ['stock-status',    () => checkStockCollection()],
    ['broadcasts',      () => loadRestaurantBroadcasts()],
    ['surveys',         () => surveyItems.value.length ? null : loadSurveyList()],
    ['previous-orders', () => loadPreviousDeliveryOrders()],
  ];
  // Запускаем 2 параллельно — не спамим сеть, но и не тормозим
  const CONCURRENCY = 2;
  let idx = 0;
  const worker = async () => {
    while (idx < tasks.length) {
      const i = idx++;
      const [label, task] = tasks[i];
      if (runId !== cabinetBackgroundRunId) return;
      try {
        await task();
      } catch (e) {
        if (import.meta.env.DEV) console.warn(`[restaurant cabinet] ${label}:`, e);
      }
    }
  };
  setTimeout(() => {
    for (let i = 0; i < CONCURRENCY; i++) worker();
  }, 0);
}

async function retryCabinetLoad() {
  globalLoading.value = true;
  try {
    await loadCabinetData();
    startRestaurantBroadcastPolling();
    startHeartbeat();
  } catch (e) {
    globalError.value = e.message || 'Ошибка загрузки кабинета';
  } finally {
    globalLoading.value = false;
  }
}

// Сессия истекла во время заполнения заказа — немедленно сохраняем черновик,
// чтобы не потерять введённые данные (обычный watch с debounce 800мс может
// не успеть до того, как пользователь нажмёт «Войти заново» и страница уйдёт).
function onSessionExpiredFlushDraft() {
  if (delDraftSaveTimer) { clearTimeout(delDraftSaveTimer); delDraftSaveTimer = null; }
  delSaveDraft();
}

onMounted(async () => {
  window.addEventListener('beforeunload', onBeforeUnload);
  window.addEventListener('bk:ro-session-expired', onSessionExpiredFlushDraft);
  // Если в URL есть tg_token — это переход из бота, надо переавторизоваться
  // (важно когда кликают «Через сайт» для другого ресторана)
  const tgTokenParam = route.query.tg_token;
  if (tgTokenParam) {
    const redirectQ = route.query.redirect;
    let redirectPath = (typeof redirectQ === 'string' && /^\/restaurant(\/|$)/.test(redirectQ)) ? redirectQ : null;
    // Если явного redirect нет — сохраняем текущий путь (минус tg_token),
    // чтобы после переавторизации пользователь оказался ровно там, куда шёл из TG.
    if (!redirectPath && route.path && /^\/restaurant(\/|$)/.test(route.path) && route.path !== '/restaurant') {
      const cleanQuery = { ...route.query };
      delete cleanQuery.tg_token;
      delete cleanQuery.redirect;
      const qs = new URLSearchParams(cleanQuery).toString();
      redirectPath = route.path + (qs ? '?' + qs : '');
    }
    router.replace({
      name: 'restaurant-order-login',
      query: {
        tg_token: tgTokenParam,
        redirect: redirectPath || '/restaurant',
      },
    });
    return;
  }
  if (!roStore.isAuthenticated) {
    const valid = await roStore.validate();
    if (!valid) { router.replace({ name: 'restaurant-order-login', query: { redirect: route.fullPath } }); return; }
  }
  try {
    await loadCabinetData();
    loadKegReturnsAvailability();
    startRestaurantBroadcastPolling();
    startHeartbeat();
    ensureSupDeadlineTimer();
  } catch (e) {
    globalError.value = e.message || 'Ошибка загрузки кабинета';
  } finally { globalLoading.value = false; }
});

onUnmounted(() => {
  cabinetBackgroundRunId++;
  clearInterval(delEditTimerInterval);
  stopSupDeadlineTimer();
  if (restaurantBroadcastTimer) clearInterval(restaurantBroadcastTimer);
  if (heartbeatTimer) { clearInterval(heartbeatTimer); heartbeatTimer = null; }
  for (const url of Object.values(importantPreviewUrls)) URL.revokeObjectURL(url);
  window.removeEventListener('beforeunload', onBeforeUnload);
  window.removeEventListener('bk:ro-session-expired', onSessionExpiredFlushDraft);
});
</script>

<style scoped>
@font-face {
  font-family: 'Flame';
  src: url('/Flame-Regular.otf') format('opentype');
  font-weight: 400;
  font-display: swap;
}

@font-face {
  font-family: 'Flame';
  src: url('/Flame-Bold.otf') format('opentype');
  font-weight: 700;
  font-display: swap;
}

/* ═══ Base ═══ */
.cab { min-height: 100vh; background: #F5F0EB; font-family: 'Inter', system-ui, -apple-system, sans-serif; box-sizing: border-box; display: flex; }
.cab *, .cab *::before, .cab *::after { box-sizing: border-box; }
.cab.cab-theme-ps { background: #faf2eb; }

/* ═══ Sidebar ═══ */
.cab-sidebar {
  width: 220px; height: 100vh; background: #502314;
  display: flex; flex-direction: column; padding: 20px 10px;
  position: fixed; top: 0; left: 0; bottom: 0; z-index: 100;
  overflow: hidden;
}
.cab-sb-scroll {
  flex: 1 1 auto; min-height: 0;
  overflow-y: auto; overscroll-behavior: contain;
  display: flex; flex-direction: column;
  scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.18) transparent;
  margin: 0 -4px; padding: 0 4px;
}
.cab-sb-scroll::-webkit-scrollbar { width: 6px; }
.cab-sb-scroll::-webkit-scrollbar-track { background: transparent; }
.cab-sb-scroll::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.18); border-radius: 3px; }
.cab-sb-scroll::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.28); }
.cab-sb-footer { flex-shrink: 0; padding-top: 6px; }
.cab.cab-theme-ps .cab-sidebar { background: #6f2a14; }
.sb-brand { display: flex; align-items: center; gap: 11px; padding: 6px 10px; margin-bottom: 24px; }
.sb-logo { width: 40px; height: 40px; border-radius: 12px; background: rgba(255,255,255,0.1); display: flex; align-items: center; justify-content: center; flex-shrink: 0; backdrop-filter: blur(4px); }
.sb-brand-text { font-size: 14px; font-weight: 400; color: white; letter-spacing: 0.2px; font-family: 'Flame', 'Inter', sans-serif; line-height: 1.05; }
.sb-brand-sub { font-size: 9px; color: rgba(255,255,255,0.42); font-weight: 600; margin-top: 3px; letter-spacing: 1.1px; text-transform: uppercase; }
.sb-label { font-size: 9px; font-weight: 700; color: rgba(255,255,255,0.35); text-transform: uppercase; letter-spacing: 1.5px; padding: 0 12px; margin: 18px 0 6px; }
.sb-item { display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-radius: 11px; border: none; background: transparent; color: rgba(255,255,255,0.8); font-size: 12px; font-weight: 600; cursor: pointer; font-family: inherit; transition: all 0.18s; width: 100%; text-align: left; }
.sb-item:hover { background: rgba(255,255,255,0.12); color: white; }
.sb-item.active { background: rgba(231,111,81,0.3); color: #F4A261; }
.sb-item-link { text-decoration: none; }
.sb-item-ext { margin-left: auto; width: 16px; height: 16px; color: rgba(255,255,255,0.4); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.sb-icon { width: 22px; height: 22px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.sb-badge { margin-left: auto; min-width: 20px; height: 20px; border-radius: 10px; background: #E76F51; color: white; font-size: 10px; font-weight: 800; display: flex; align-items: center; justify-content: center; padding: 0 6px; flex-shrink: 0; }
.sb-badge.warn { background: #f59e0b; }
.sb-badge.ok { background: #16a34a; }
.sb-badge.alert { background: #dc2626; }
.sb-badge.pause { background: #9ca3af; font-size: 9px; padding: 0 7px; text-transform: uppercase; letter-spacing: 0.5px; }
.sb-beta { margin-left: auto; font-size: 9px; font-weight: 800; letter-spacing: 0.5px; padding: 2px 6px; border-radius: 4px; background: linear-gradient(90deg, #FFD54F, #F4A261); color: #3d2400; flex-shrink: 0; }
.sb-help {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 11px 12px;
  border-radius: 12px;
  margin-top: 8px;
  background: rgba(255,255,255,0.06);
  border: 1px solid rgba(255,255,255,0.05);
  color: #fff;
  text-decoration: none;
  font-size: 12px;
  font-weight: 700;
  transition: all 0.18s;
}
.sb-help:hover {
  background: rgba(42,171,238,0.18);
  border-color: rgba(42,171,238,0.35);
}
.sb-help-icon {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  background: rgba(42,171,238,0.22);
  color: #8fd8ff;
}
.sb-rest {
  background: rgba(255,255,255,0.06); border-radius: 13px;
  margin-top: 8px; border: 1px solid rgba(255,255,255,0.04);
  display: flex; align-items: stretch; overflow: hidden;
  transition: background 0.18s, border-color 0.18s;
}
.sb-rest:hover { background: rgba(255,255,255,0.1); border-color: rgba(255,255,255,0.08); }
.sb-rest.active { background: rgba(231,111,81,0.18); border-color: rgba(244,162,97,0.35); }
.sb-rest-main {
  flex: 1; min-width: 0;
  display: flex; align-items: center; gap: 10px;
  padding: 12px; background: transparent; border: none;
  cursor: pointer; font-family: inherit; text-align: left;
  color: inherit;
}
.sb-rest-info { flex: 1; min-width: 0; }
.sb-avatar { width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, #E76F51, #F4A261); color: white; font-size: 13px; font-weight: 900; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.sb-rest-name { font-size: 12px; font-weight: 700; color: white; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sb-rest-addr { font-size: 10px; color: rgba(255,255,255,0.4); margin-top: 3px; line-height: 1.35; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.sb-rest-logout {
  flex-shrink: 0; width: 38px;
  display: flex; align-items: center; justify-content: center;
  background: transparent; border: none; border-left: 1px solid rgba(255,255,255,0.06);
  color: rgba(255,255,255,0.5); cursor: pointer;
  transition: color 0.15s, background 0.15s;
}
.sb-rest-logout:hover { color: #F4A261; background: rgba(255,255,255,0.04); }

/* ═══ Main ═══ */
.cab-main { flex: 1; margin-left: 220px; min-height: 100vh; }

/* Topbar */
.cab-topbar { display: flex; align-items: center; justify-content: space-between; padding: 14px 28px; background: white; border-bottom: 1px solid #EDE8E3; position: sticky; top: 0; z-index: 50; }
.cab-topbar-title { font-size: 17px; font-weight: 800; color: #502314; letter-spacing: -0.3px; }
.cab-topbar-sub { font-size: 11px; color: #8B7355; margin-top: 1px; }
.cab-topbar-scan {
  display: flex; align-items: center; gap: 6px;
  padding: 8px 12px; background: #FFF4E6; color: #502314;
  border: 1px solid #F4A261; border-radius: 8px;
  cursor: pointer; transition: background 0.15s;
  font-family: inherit;
}
.cab-topbar-scan:hover { background: #FFE8C9; }
.cab-topbar-scan:active { transform: translateY(1px); }
.cab-topbar-scan-beta {
  font-size: 9px; font-weight: 800; letter-spacing: 0.5px;
  background: linear-gradient(90deg, #FFD54F, #F4A261);
  color: #3d2400; padding: 2px 6px; border-radius: 4px;
}

/* Section */
.cab-section { padding: 24px 28px; }
/* Вкладка заказов: ограничиваем максимальную ширину на больших мониторах,
   чтобы форма не растягивалась на пол-экрана и не выглядела пустой */
.cab-section.cab-section-orders { max-width: 1180px; margin-left: auto; margin-right: auto; padding: 20px 24px; }

/* Loader */
.cab-loader { display: flex; justify-content: center; padding: 60px; }
.cab-spin { width: 28px; height: 28px; border: 3px solid #ede8e3; border-top-color: #E76F51; border-radius: 50%; animation: spin 0.7s linear infinite; display: inline-block; }
.cab-spin-sm { width: 16px; height: 16px; border-width: 2px; }
@keyframes spin { to { transform: rotate(360deg); } }
.mini-loader { padding: 24px; text-align: center; }

/* ═══ Skeleton ═══ */
.dash-skeleton .sk-block { background: #ECE5DE; border-radius: 14px; }
.dash-skeleton .sk-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; }
.dash-skeleton .sk-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 14px; }
.dash-skeleton .sk-list { display: flex; flex-direction: column; gap: 10px; }
.dash-skeleton .sk-h-44 { height: 44px; }
.dash-skeleton .sk-h-72 { height: 72px; }
.dash-skeleton .sk-h-88 { height: 88px; }
.dash-skeleton .sk-h-220 { height: 220px; }
.sk-shimmer {
  position: relative; overflow: hidden;
  background: linear-gradient(100deg, #ECE5DE 30%, #F4EFE9 50%, #ECE5DE 70%);
  background-size: 200% 100%;
  animation: sk-shimmer 1.2s ease-in-out infinite;
}
@keyframes sk-shimmer { to { background-position: -200% 0; } }
@media (prefers-reduced-motion: reduce) {
  .sk-shimmer { animation: none; }
}

/* ═══ Dashboard ═══ */
/* Wrap: контейнер дашборда не растягивается на широких экранах */
.dash-wrap { display: flex; flex-direction: column; gap: 20px; max-width: 1180px; margin: 0 auto; }
.dash-col-main, .dash-col-side { display: contents; }
.dash-wrap .dash-urgent,
.dash-wrap .dash-grid,
.dash-wrap .dash-actions,
.dash-wrap .dash-important,
.dash-wrap .dash-recent { margin-bottom: 0; }
/* Порядок на мобилке: срочные → сводка → действия → важное → последние */
.dash-wrap .dash-urgent { order: 1; }
.dash-wrap .dash-grid { order: 2; }
.dash-wrap .dash-actions { order: 3; }
.dash-wrap .dash-important { order: 4; }
.dash-wrap .dash-recent { order: 5; }

/* PWA push онбординг */
.dash-push-onboard {
  position: relative;
  display: flex; align-items: center; gap: 12px;
  padding: 14px 16px;
  background: linear-gradient(135deg, #1976d2 0%, #1565c0 100%);
  color: #fff;
  border-radius: 14px;
  box-shadow: 0 4px 12px rgba(25,118,210,.25);
}
.dash-push-onboard-icon {
  width: 40px; height: 40px; flex-shrink: 0;
  background: rgba(255,255,255,.18);
  border-radius: 50%;
  display: inline-flex; align-items: center; justify-content: center;
}
.dash-push-onboard-text { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; line-height: 1.3; }
.dash-push-onboard-text strong { font-size: 14.5px; font-weight: 700; }
.dash-push-onboard-text span { font-size: 12.5px; opacity: 0.92; }
.dash-push-onboard-btn {
  flex-shrink: 0;
  padding: 8px 16px;
  background: #fff;
  color: #1565c0;
  border: none;
  border-radius: 10px;
  font: inherit; font-size: 13px; font-weight: 700;
  cursor: pointer;
  transition: opacity .15s;
}
.dash-push-onboard-btn:hover:not(:disabled) { opacity: 0.92; }
.dash-push-onboard-btn:disabled { opacity: 0.6; cursor: default; }
.dash-push-onboard-skip {
  position: absolute; top: 6px; right: 8px;
  width: 24px; height: 24px;
  background: transparent; border: none;
  color: rgba(255,255,255,.7);
  font-size: 20px; line-height: 1;
  cursor: pointer; border-radius: 50%;
}
.dash-push-onboard-skip:hover { background: rgba(255,255,255,.15); color: #fff; }
@media (max-width: 520px) {
  .dash-push-onboard { padding: 12px; gap: 10px; }
  .dash-push-onboard-text strong { font-size: 13.5px; }
  .dash-push-onboard-text span { font-size: 12px; }
}

/* «Сегодня нужно сделать» — сводка ключевых дел сверху дашборда */
.dash-today {
  background: linear-gradient(180deg, #FFF8F0 0%, #FFFFFF 100%);
  border: 1.5px solid #F4D8B8;
  border-radius: 14px;
  padding: 12px 16px;
  box-shadow: 0 2px 8px rgba(231,111,81,.08);
}
.dash-today-head {
  display: flex; align-items: center; gap: 8px;
  color: #b35900; font-size: 13px; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.04em;
  margin-bottom: 10px;
}
.dash-today-head h3 { margin: 0; font: inherit; }
.dash-today-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 6px; }
.dash-today-item {
  display: flex; align-items: center; gap: 12px;
  padding: 10px 12px;
  background: #fff;
  border: 1px solid #f0e0c8;
  border-radius: 10px;
  cursor: pointer;
  transition: border-color .15s, transform .12s;
}
.dash-today-item:hover { border-color: #E76F51; transform: translateX(2px); }
.dash-today-item.is-warn { border-color: #f6c878; background: #fff8ec; }
.dash-today-item.is-alert { border-color: #f6a8a8; background: #fde8e8; }
.dash-today-item.is-info { border-color: #c4d8e8; background: #f3f8fb; }
.dash-today-num {
  flex-shrink: 0;
  min-width: 28px; height: 28px; padding: 0 8px;
  background: #E76F51; color: #fff;
  border-radius: 14px;
  display: inline-flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 14px;
}
.dash-today-item.is-alert .dash-today-num { background: #c62828; }
.dash-today-item.is-info .dash-today-num { background: #1976d2; }
.dash-today-text { flex: 1; font-size: 14px; color: #2C1A12; line-height: 1.35; }
.dash-today-arrow { color: #B0A090; flex-shrink: 0; }

@media (min-width: 960px) {
  .dash-wrap { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); column-gap: 24px; row-gap: 0; align-items: start; }
  .dash-col-main, .dash-col-side { display: flex; flex-direction: column; gap: 20px; min-width: 0; }
  .dash-wrap .dash-urgent,
  .dash-wrap .dash-grid,
  .dash-wrap .dash-actions,
  .dash-wrap .dash-important,
  .dash-wrap .dash-recent { order: initial; }
  /* Сводка — карточки растягиваются по ширине левой колонки поровну */
  .dash-col-main .dash-grid { grid-template-columns: repeat(auto-fit, minmax(0, 1fr)); }
  /* Кнопки быстрых действий не растягиваются на десктопе */
  .dash-action-grid { grid-template-columns: repeat(auto-fill, 130px); }
}

.dash-urgent { display: flex; flex-direction: column; gap: 10px; margin-bottom: 24px; }
.dash-card { display: flex; align-items: center; gap: 14px; background: white; border-radius: 16px; padding: 16px 18px; cursor: pointer; transition: all 0.18s; border: 1px solid #EDE8E3; border-left: 4px solid #f59e0b; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
.dash-card:hover { transform: translateX(4px); box-shadow: 0 4px 16px rgba(0,0,0,0.06); }
.dash-card--warn { border-left-color: #f59e0b; }
.dash-card--green { border-left-color: #16a34a; }
.dash-card--orange { border-left-color: #ea580c; }
.dash-card--alert { border-left-color: #dc2626; }
.dash-card-icon {
  width: 34px; height: 34px; border-radius: 12px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  background: #FFF5F2; color: #E76F51; border: 1px solid rgba(231,111,81,.18);
}
.dash-card-icon svg,
.dash-action-icon svg,
.mob-tab-icon svg,
.sb-icon svg,
.sb-help-icon svg,
.sb-item-ext svg,
.sb-ext svg,
.ord-tab-ext svg,
.info-file-icon svg,
.sup-skip-icon svg,
.inline-ui-icon svg,
.hist-edited-mark svg {
  width: 20px; height: 20px; display: block; fill: none; stroke: currentColor; stroke-width: 2.1; stroke-linecap: round; stroke-linejoin: round;
}
:deep(.dash-card-icon svg),
:deep(.dash-action-icon svg),
:deep(.mob-tab-icon svg),
:deep(.sb-icon svg),
:deep(.sb-help-icon svg),
:deep(.sb-item-ext svg),
:deep(.sb-ext svg),
:deep(.ord-tab-ext svg),
:deep(.info-file-icon svg),
:deep(.sup-skip-icon svg),
:deep(.inline-ui-icon svg),
:deep(.hist-edited-mark svg),
:deep(.supplier-icon svg) {
  display: block;
  fill: none;
  stroke: currentColor;
  stroke-linecap: round;
  stroke-linejoin: round;
}
:deep(.dash-card-icon svg),
:deep(.dash-action-icon svg),
:deep(.mob-tab-icon svg),
:deep(.sb-icon svg),
:deep(.sb-help-icon svg),
:deep(.sb-item-ext svg),
:deep(.sb-ext svg),
:deep(.ord-tab-ext svg),
:deep(.info-file-icon svg),
:deep(.sup-skip-icon svg) {
  width: 20px;
  height: 20px;
  stroke-width: 2.1;
}
.dash-card-body { flex: 1; min-width: 0; }
.dash-card-title { font-size: 13px; font-weight: 700; color: #502314; }
.dash-card-sub { font-size: 11px; color: #8b7355; margin-top: 1px; }
.dash-card-time { font-size: 16px; font-weight: 700; color: #E76F51; font-variant-numeric: tabular-nums; }
.dash-card-arrow { color: #D4C4B0; flex-shrink: 0; }

.dash-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 14px; margin-bottom: 24px; }
.dash-stat { background: white; border-radius: 16px; padding: 20px; text-align: center; cursor: pointer; border: 1px solid #EDE8E3; transition: all 0.18s; position: relative; overflow: hidden; }
.dash-stat::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 3px; background: #EDE8E3; transition: background 0.18s; }
.dash-stat:hover { box-shadow: 0 8px 32px rgba(80,35,20,0.1); transform: translateY(-2px); }
.dash-stat:hover::after { background: #E76F51; }
.dash-stat-num { font-size: 28px; font-weight: 900; color: #502314; letter-spacing: -1px; }
.dash-stat-alert { color: #dc2626; }
.dash-stat-label { font-size: 10px; color: #8b7355; margin-top: 4px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }

.dash-actions { margin-bottom: 24px; }
.dash-section-title { font-size: 14px; font-weight: 800; color: #502314; margin: 0 0 12px; }
.dash-action-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 10px; }
.dash-action { display: flex; flex-direction: column; align-items: center; gap: 8px; background: white; border-radius: 16px; padding: 20px 12px; border: 1px solid #EDE8E3; cursor: pointer; font-family: inherit; font-size: 11px; font-weight: 700; color: #502314; text-decoration: none; transition: all 0.18s; }
.dash-action:hover { border-color: rgba(231,111,81,0.3); transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,0,0,0.06); }
.dash-action-icon {
  width: 34px; height: 34px; border-radius: 12px; display: flex; align-items: center; justify-content: center;
  background: #FFF5F2; color: #E76F51; border: 1px solid rgba(231,111,81,.18);
}
.dash-action:hover .dash-action-icon { background: #E76F51; color: white; }
.dash-action--alert { border-color: rgba(231,111,81,0.2); }

.dash-recent { }
.dash-order { display: flex; justify-content: space-between; align-items: center; background: white; padding: 11px 18px; border-bottom: 1px solid #F5F2EE; transition: background 0.1s; cursor: pointer; }
.dash-order:hover { background: #FAF8F5; }
.dash-order:first-child { border-radius: 16px 16px 0 0; }
.dash-order:last-child { border-bottom: none; border-radius: 0 0 16px 16px; }
.dash-order-left { display: flex; align-items: center; gap: 10px; }
.dash-order-right { display: flex; align-items: center; gap: 10px; font-size: 12px; color: #8b7355; }
.dash-order-source { font-size: 9px; padding: 3px 8px; border-radius: 6px; font-weight: 700; }
.src-delivery { background: #FFF5F2; color: #E76F51; }
.src-supplier { background: #EFF6FF; color: #2563eb; }
.src-planeta { background: #ecfdf5; color: #16a34a; }
.dash-order-date { font-size: 12px; font-weight: 600; color: #502314; }
.dash-order-status { font-size: 9px; padding: 3px 8px; border-radius: 6px; font-weight: 700; }
.st-submitted { background: #ecfdf5; color: #16a34a; }
.st-edited { background: #eff6ff; color: #2563eb; }
.st-draft { background: #f5f0eb; color: #8b7355; }
.st-locked { background: #fef2f2; color: #dc2626; }

.supplier-icon {
  flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center;
  border-radius: 8px; line-height: 1; background: #FAF7F4; border: 1px solid #EDE8E3;
}
.supplier-icon svg { width: 70%; height: 70%; display: block; fill: none; stroke: currentColor; stroke-width: 2.1; stroke-linecap: round; stroke-linejoin: round; }
:deep(.supplier-icon svg) { width: 70%; height: 70%; stroke-width: 2.1; }
.supplier-icon-sm { width: 24px; height: 24px; }
.supplier-icon-xs { width: 18px; height: 18px; border-radius: 6px; }
.supplier-icon-xs svg { width: 72%; height: 72%; stroke-width: 2.4; }
:deep(.supplier-icon-xs svg) { width: 72%; height: 72%; stroke-width: 2.4; }
.supplier-icon-drinks { color: #0f766e; background: #ecfeff; border-color: rgba(15,118,110,.18); }
.supplier-icon-vegetables { color: #15803d; background: #f0fdf4; border-color: rgba(21,128,61,.18); }
.supplier-icon-sauce { color: #c2410c; background: #fff7ed; border-color: rgba(194,65,12,.18); }
.supplier-icon-neutral { color: #6b3e2c; background: #FAF7F4; border-color: #EDE8E3; }

.sb-item-link { text-decoration: none; }
.sb-ext { margin-left: auto; width: 16px; height: 16px; color: #b08a70; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }

.dash-important {
  background: white; border: 1px solid #EDE8E3; border-radius: 16px; padding: 16px 18px; margin-bottom: 20px;
  box-shadow: 0 1px 4px rgba(0,0,0,0.04);
}
.dash-important.unread { border-color: #E76F51; box-shadow: 0 8px 24px rgba(231,111,81,.08); }
.dash-important-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.dash-important h2 { margin: 0 0 4px; color: #502314; font-size: 17px; }
.dash-important-meta { color: #8b7355; font-size: 12px; }
.dash-important p { margin: 12px 0; color: #4b3527; line-height: 1.45; white-space: pre-line; }

/* ═══ Orders ═══ */
.ord-tabs { display: flex; gap: 6px; padding: 0 0 12px; overflow-x: auto; flex-wrap: wrap; -webkit-overflow-scrolling: touch; }
.ord-tab { flex-shrink: 0; padding: 6px 14px; border-radius: 10px; border: 1.5px solid #EDE8E3; background: white; cursor: pointer; font-size: 12px; font-weight: 700; color: #8b7355; font-family: inherit; transition: all 0.18s; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
.ord-tab:hover:not(.active) { border-color: #502314; color: #502314; }
.ord-tab.active { background: #502314; color: white; border-color: #502314; }
.ord-tab-link { text-decoration: none; }
.ord-tab-ext { width: 15px; height: 15px; color: #b08a70; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.ord-tab-icon { width: 15px; height: 15px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.ord-tab-icon svg { width: 100%; height: 100%; stroke: currentColor; fill: none; stroke-width: 2; }
.ord-tab-beta { font-size: 8px; font-weight: 800; letter-spacing: 0.5px; padding: 2px 5px; border-radius: 4px; background: linear-gradient(90deg, #FFD54F, #F4A261); color: #3d2400; flex-shrink: 0; }
.ord-tab.active .ord-tab-beta { background: linear-gradient(90deg, #FFE082, #FFB74D); }
.ord-tab-badge { font-size: 9px; font-weight: 800; padding: 2px 7px; border-radius: 8px; }
.ord-tab-badge.warn { background: #f59e0b; color: white; }
.ord-tab-badge.ok { background: #16a34a; color: white; }
.ord-tab-badge.pause { background: #9ca3af; color: white; text-transform: uppercase; letter-spacing: 0.4px; }
.mob-order-tabs { display: none; }

/* Shared order components */
/* Центрированная колонка для форм заказа (заявка, планета, поставщики) */
.cab-info-bar,
.day-tabs,
.order-form,
.cab-empty-card {
  max-width: 1000px;
  margin-left: auto;
  margin-right: auto;
}
.cab-info-bar { background: #FAF7F4; color: #6b3e2c; text-align: center; padding: 6px 14px; font-size: 11px; font-weight: 600; border-radius: 8px; margin-bottom: 8px; border: 1px solid #EDE8E3; }
.cab-empty-card { background: white; border-radius: 14px; padding: 32px 24px; margin: 12px auto; text-align: center; border: 1px solid #EDE8E3; max-width: 480px; }
.cab-empty-card h2 { color: #502314; margin: 0 0 6px; font-size: 16px; }
.cab-empty-card p { color: #8b7355; margin: 0; font-size: 13px; }

.day-tabs { display: flex; gap: 6px; padding: 8px 8px 10px 0; overflow-x: auto; -webkit-overflow-scrolling: touch; }
.day-tab { flex-shrink: 0; padding: 8px 14px; border-radius: 11px; border: 1.5px solid #EDE8E3; background: white; cursor: pointer; text-align: center; font-family: inherit; transition: all 0.18s; position: relative; font-size: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); min-width: 64px; }
.day-tab:hover { border-color: #8b7355; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
.day-tab.active { background: #E76F51; color: white; border-color: #E76F51; box-shadow: 0 4px 16px rgba(231,111,81,0.25); }
.day-tab.active .day-tab-name, .day-tab.active .day-tab-date, .day-tab.active .day-tab-label { color: white; }
.day-tab.done { border-color: #16a34a; }
.day-tab.skipped { border-color: #9ca3af; background: #f5f5f5; }
.day-tab.active.skipped { background: #E76F51; border-color: #E76F51; }
.day-tab.active.skipped .day-tab-name, .day-tab.active.skipped .day-tab-date { color: white; }
.day-tab.closed { opacity: 0.5; }
.day-tab.closed .day-tab-name, .day-tab.closed .day-tab-label { text-decoration: line-through; }
.day-tab.warn { border-color: #f59e0b; }
.day-tab-label { display: flex; align-items: center; gap: 5px; }
.day-tab-name { display: block; font-size: 12px; font-weight: 700; color: #502314; }
.day-tab-date { display: block; font-size: 10px; color: #8b7355; margin-top: 1px; }
.day-tab-mark { position: absolute; top: -5px; right: -5px; width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid #F5F0EB; }
.day-tab-mark svg { width: 11px; height: 11px; fill: none; stroke: currentColor; stroke-width: 3; stroke-linecap: round; stroke-linejoin: round; }
:deep(.day-tab-mark svg) { width: 11px; height: 11px; fill: none; stroke: currentColor; stroke-width: 3; stroke-linecap: round; stroke-linejoin: round; }
.day-tab-mark.done { background: #16a34a; color: white; }
.day-tab-mark.skipped { background: #9ca3af; color: white; }
.day-tab-mark.closed { background: #9ca3af; color: white; }

.sup-skip-banner {
  padding: 8px 14px; background: #fef3c7; border-bottom: 1px solid #fbbf24;
  color: #92400e; font-size: 12px; display: flex; align-items: center; flex-wrap: wrap; gap: 6px;
  line-height: 1.3;
}
.sup-skip-banner strong { font-size: 13px; }
.sup-skip-icon { width: 16px; height: 16px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.sup-skip-hint { font-size: 11px; opacity: 0.75; }


.order-form { background: white; border-radius: 14px; margin-top: 6px; overflow: hidden; border: 1px solid #EDE8E3; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }

.deadline-bar { padding: 8px 14px; font-size: 12px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 6px; border-radius: 0; }
.deadline-timer { font-variant-numeric: tabular-nums; opacity: 0.85; }
.draft-restored { padding: 8px 18px; font-size: 12px; font-weight: 600; color: #b45309; background: #fffbeb; border-bottom: 1px solid #fde68a; text-align: center; display: flex; align-items: center; justify-content: center; gap: 6px; animation: draftFadeIn 0.3s ease; }
.draft-saved-indicator { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; font-size: 11px; font-weight: 500; color: #2e7d32; background: #f0f7ed; border: 1px solid #c4e6c8; border-radius: 12px; align-self: flex-start; margin: 6px 0 0 12px; transition: opacity 0.2s; }
.draft-saved-indicator svg { color: #2e7d32; flex-shrink: 0; }
@keyframes draftFadeIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }
.deadline-icon { display: inline-flex; align-items: center; flex-shrink: 0; }
.dl-open { background: #ECFDF5; color: #16a34a; }
.dl-warning { background: #FFFBEB; color: #d97706; }
.dl-closed { background: #FEF2F2; color: #dc2626; }
.dl-not_open { background: #FEF2F2; color: #dc2626; }
.dl-not_yet { background: #f0f9ff; color: #2563eb; }

.cat-tabs { display: flex; gap: 4px; padding: 8px 14px; border-bottom: 1px solid #EDE8E3; background: #FAFAF8; justify-content: center; }
.cat-tab { padding: 6px 14px; border-radius: 8px; border: none; background: transparent; cursor: pointer; font-size: 12px; font-weight: 700; color: #8b7355; font-family: inherit; transition: all 0.15s; display: flex; align-items: center; justify-content: center; gap: 6px; }
.cat-tab:hover { background: #EDE8E3; color: #502314; }
.cat-tab.active { background: #502314; color: white; }
.cat-count { background: rgba(0,0,0,0.08); padding: 1px 6px; border-radius: 5px; font-size: 10px; font-weight: 800; }
.cat-tab.active .cat-count { background: rgba(255,255,255,0.2); }

.search-row { display: flex; gap: 8px; padding: 8px 14px; border-bottom: 1px solid #EDE8E3; align-items: center; flex-wrap: wrap; }
.input-search { flex: 1; min-width: 120px; padding: 7px 12px; border: 1.5px solid #EDE8E3; border-radius: 8px; font-size: 13px; font-family: inherit; background: #FAFAF8; transition: border-color 0.15s; }
.input-search:focus { outline: none; border-color: #E76F51; background: white; box-shadow: 0 0 0 3px rgba(231,111,81,0.06); }
.search-clear { background: none; border: none; cursor: pointer; font-size: 16px; color: #999; padding: 0 4px; }

/* Delivery table — компактно */
.del-table { width: 100%; border-collapse: collapse; }
.del-table th { padding: 6px 14px; font-size: 10px; font-weight: 700; color: #8b7355; text-align: center; background: #FAFAF8; border-bottom: 1px solid #EDE8E3; text-transform: uppercase; letter-spacing: 0.6px; }
.del-table th:first-child { text-align: left; }
.del-table td { padding: 0 14px; height: 38px; border-bottom: 1px solid #F5F2EE; font-size: 13px; color: #502314; vertical-align: middle; text-align: left; }
.del-table tbody tr { transition: background 0.1s; }
.del-table tbody tr:hover { background: #FEFCFA; }
.del-th-name { text-align: left; }
.del-th-qty { width: 90px; text-align: center; }
.del-th-act { width: 30px; }
.del-td-name { font-weight: 500; text-align: left; }
.del-sku { font-size: 10px; color: #B0A090; font-family: 'SF Mono', 'JetBrains Mono', monospace; margin-right: 4px; }
.del-mult-inline {
  display: inline-block;
  font-size: 10px;
  color: #2563eb;
  background: #EFF6FF;
  padding: 2px 7px;
  border-radius: 5px;
  font-weight: 700;
  margin-left: 6px;
  white-space: nowrap;
  vertical-align: 1px;
}
.del-td-qty { text-align: center; }
.del-qty { width: 64px; height: 30px; padding: 0; border: 1.5px solid #EDE8E3; border-radius: 8px; font-size: 13px; text-align: center; font-family: inherit; font-weight: 700; background: #FAFAF8; color: #502314; transition: all 0.15s; -moz-appearance: textfield; }
.del-qty::-webkit-inner-spin-button { -webkit-appearance: none; }
.del-qty:focus { outline: none; border-color: #E76F51; background: white; box-shadow: 0 0 0 3px rgba(231,111,81,0.06); }
.del-qty-err { border-color: #dc2626 !important; background: #fef2f2; }
.del-mult-hint { font-size: 10px; color: #dc2626; margin-top: 2px; }
.del-cmt { width: 100%; max-width: 180px; padding: 7px 10px; border: 1.5px solid #EDE8E3; border-radius: 8px; font-size: 12px; font-family: inherit; color: #502314; background: transparent; transition: border-color 0.15s; }
.del-cmt:focus { outline: none; border-color: #E76F51; }
.del-cmt::placeholder { color: #D4C4B0; }
tr.del-filled { background: #FFFBF8; }
tr.del-err { background: #fef2f2; }

.btn-icon-danger { background: none; border: none; cursor: pointer; color: #dc2626; font-size: 18px; padding: 2px 4px; flex-shrink: 0; }
.empty-msg { padding: 32px; text-align: center; color: #8b7355; font-size: 13px; }
.del-search-other { margin-top: 14px; display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 8px; }
.del-search-other-lbl { font-size: 12px; color: #8b7355; }
.del-search-other-chip {
  display: inline-flex; align-items: center; gap: 6px;
  background: #FFFBF6; border: 1.5px solid #E8DCC8; color: #502314;
  padding: 6px 12px; border-radius: 999px; cursor: pointer;
  font-size: 12px; font-weight: 600; transition: all 0.15s;
}
.del-search-other-chip:hover { background: #FCEFE0; border-color: #E76F51; }
.del-search-other-count {
  display: inline-block; background: #E76F51; color: white;
  font-size: 10px; font-weight: 700; padding: 1px 7px; border-radius: 10px; min-width: 18px; text-align: center;
}
.del-only-filled-count {
  display: inline-block; margin-left: 6px;
  background: rgba(255,255,255,0.25);
  font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 10px; min-width: 18px; text-align: center;
}
.btn-outline .del-only-filled-count { background: #FCEFE0; color: #E76F51; }

/* Delivery item-list extras */
.item-input-stack { display: flex; flex-direction: column; gap: 4px; align-items: flex-end; }
.item-cmt { width: 100px; padding: 4px 7px; border: 1.5px solid #e0dbd5; border-radius: 6px; font-size: 11px; font-family: inherit; color: #502314; }
.item-cmt:focus { outline: none; border-color: #E76F51; box-shadow: 0 0 0 2px rgba(231,111,81,0.08); }
.item-cmt::placeholder { color: #c4b8a8; }
.item-mult-hint { font-size: 10px; color: #dc2626; text-align: right; padding: 0 12px 4px; width: 100%; }

/* Submit summary (integrated with button) */
.submit-summary { display: flex; align-items: center; justify-content: center; gap: 10px; font-size: 13px; font-weight: 600; color: #502314; margin-bottom: 8px; }

.submit-area { padding: 8px 14px; text-align: center; border-top: 1px solid #EDE8E3; background: #FAFAF8; }
.error-msg { padding: 6px 10px; border-radius: 6px; background: #fef2f2; color: #dc2626; font-size: 12px; font-weight: 600; text-align: center; margin-bottom: 8px; }
.success-msg { padding: 6px 10px; border-radius: 6px; background: #ecfdf5; color: #16a34a; font-size: 12px; font-weight: 600; text-align: center; margin-bottom: 8px; }
.locked-msg { color: #dc2626; font-size: 12px; font-weight: 600; }

/* Buttons */
.btn { padding: 8px 18px; border-radius: 10px; border: none; font-size: 13px; font-weight: 700; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 6px; transition: all 0.18s; }
.btn-primary { background: #E76F51; color: white; box-shadow: 0 2px 8px rgba(231,111,81,0.2); }
.btn-primary:hover:not(:disabled) { background: #b81e00; transform: translateY(-1px); box-shadow: 0 4px 16px rgba(231,111,81,0.25); }
.btn-primary:active:not(:disabled) { transform: scale(0.98); }
.btn-primary:disabled { opacity: 0.4; cursor: not-allowed; box-shadow: none; }
.btn-outline { border: 1.5px solid #EDE8E3; background: white; color: #502314; }
.btn-outline:hover { border-color: #502314; }
.btn-danger-outline { border: 1.5px solid #dc2626; background: transparent; color: #dc2626; }
.btn-danger-outline:hover { background: #fef2f2; }
.btn-green { border: 1.5px solid #16a34a; background: #f0fdf4; color: #16a34a; }
.btn-green:hover { background: #16a34a; color: white; }
.btn-sm { padding: 6px 14px; font-size: 12px; border-radius: 8px; }
.btn-lg { padding: 8px 22px; font-size: 13px; font-weight: 700; border-radius: 10px; }

.repeat-section { padding: 10px 14px; background: #FAFAF8; border-top: 1px solid #EDE8E3; }
.repeat-title { font-size: 11px; font-weight: 700; color: #8b7355; margin-bottom: 6px; }
.repeat-btn { display: inline-block; padding: 6px 12px; border: 1.5px solid #EDE8E3; border-radius: 8px; background: white; cursor: pointer; font-size: 11px; font-weight: 600; font-family: inherit; color: #502314; margin: 0 6px 4px 0; transition: all 0.15s; }
.repeat-btn:hover { border-color: #E76F51; color: #E76F51; }

/* Success */
.cab-success { display: flex; align-items: center; justify-content: center; min-height: 50vh; padding: 24px 16px; }
.cab-success-inner {
  background: white; border-radius: 24px; padding: 44px 36px; text-align: center;
  max-width: 480px; width: 100%;
  box-shadow: 0 12px 40px rgba(80, 35, 20, 0.08);
  border: 1px solid #EDE8E3;
  animation: cabSuccessIn 0.35s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes cabSuccessIn {
  from { opacity: 0; transform: translateY(12px) scale(0.97); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}
.cab-success-check {
  width: 76px; height: 76px; border-radius: 50%;
  background: linear-gradient(135deg, #22c55e, #16a34a);
  color: white;
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 18px;
  box-shadow: 0 8px 24px rgba(22, 163, 74, 0.3);
}
.cab-success-check svg { width: 38px; height: 38px; fill: none; stroke: currentColor; stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round; }
:deep(.cab-success-check svg) { width: 38px; height: 38px; fill: none; stroke: currentColor; stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round; }
.cab-success-check-skip {
  background: linear-gradient(135deg, #fb923c, #ea580c);
  box-shadow: 0 8px 24px rgba(234, 88, 12, 0.3);
}
.cab-success-skip-note {
  font-size: 13px; color: #8b7355; line-height: 1.5;
  background: #fff7ed; border: 1px solid #fed7aa;
  border-radius: 12px; padding: 12px 16px; margin: 12px 0 18px;
}
.cab-success-inner h2 { color: #502314; margin: 0 0 6px; font-size: 24px; font-weight: 800; letter-spacing: -0.3px; }
.cab-success-inner p { font-size: 14px; color: #8b7355; margin: 0 0 4px; }
.cab-success-date { font-size: 14px; color: #8b7355; margin-bottom: 22px; }
.cab-success-stat { font-size: 14px; font-weight: 600; color: #502314 !important; margin-top: 4px !important; }

.cab-success-stats {
  display: flex; align-items: center; justify-content: center; gap: 28px;
  background: #FAFAF8; border-radius: 16px; padding: 18px 24px; margin-bottom: 18px;
  border: 1px solid #F0EBE4;
}
.cab-success-stat-item { display: flex; flex-direction: column; align-items: center; gap: 2px; }
.cab-success-stat-num { font-size: 32px; font-weight: 800; color: #502314; line-height: 1; font-variant-numeric: tabular-nums; }
.cab-success-stat-lbl { font-size: 11px; color: #8b7355; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
.cab-success-stat-divider { width: 1px; height: 36px; background: #E0D5C8; }

.cab-success-timer {
  background: linear-gradient(135deg, #ecfdf5, #f0fdf4);
  border: 1px solid #bbf7d0; border-radius: 14px;
  padding: 14px 16px; margin-bottom: 18px;
}
.cab-success-timer-lbl { font-size: 11px; color: #15803d; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
.cab-success-time { font-size: 28px; font-weight: 800; color: #16a34a; font-variant-numeric: tabular-nums; letter-spacing: 2px; line-height: 1; }
.cab-success-timer.expired {
  background: linear-gradient(135deg, #fff7ed, #fef2f2);
  border-color: #fecaca;
}
.cab-success-timer.expired .cab-success-timer-lbl { color: #b45309; }
.cab-success-timer-sub { font-size: 13px; color: #78350f; margin-top: 4px; line-height: 1.4; }

.cab-success-btns { display: flex; gap: 10px; justify-content: center; margin-top: 4px; }
.cab-success-btns .btn { flex: 1; min-width: 0; max-width: 200px; padding: 12px 18px; font-size: 14px; }

@media (max-width: 480px) {
  .cab-success-inner { padding: 32px 22px; border-radius: 20px; }
  .cab-success-check { width: 64px; height: 64px; font-size: 32px; }
  .cab-success-inner h2 { font-size: 20px; }
  .cab-success-stats { gap: 20px; padding: 14px 18px; }
  .cab-success-stat-num { font-size: 26px; }
  .cab-success-time { font-size: 24px; }
  .cab-success-btns { flex-direction: column; }
  .cab-success-btns .btn { max-width: none; }
  .hist-modal-title-block { flex-wrap: wrap; }

  /* Item list — узкие экраны */
  .cab-section { padding: 12px; }
  .item-row { padding: 8px 10px; gap: 6px; }
  .item-info { margin-bottom: 4px; gap: 4px; }
  .item-name { font-size: 13px; line-height: 1.25; }
  .item-hint, .item-edit-mark { font-size: 9px; }
  .item-input { width: 100%; justify-content: flex-end; }
  .item-qty { width: 88px; height: 44px; font-size: 16px; }
}

/* Unified item list (Планета, Камако, etc.) */
.quick-actions { display: flex; gap: 6px; padding: 8px 12px; }
.item-list { padding: 0; }
.item-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 12px; border-bottom: 1px solid #f3eeea; transition: background 0.1s; }
.item-row:last-child { border-bottom: none; }
.item-row:hover { background: #faf8f5; }
.item-filled { background: #f0fdf4; }
.item-error { background: #fef2f2; }
.item-info { flex: 1; min-width: 0; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.item-name { font-size: 14px; font-weight: 500; color: #502314; }
.item-hint { font-size: 10px; color: #2563eb; background: #eff6ff; padding: 1px 5px; border-radius: 4px; font-weight: 600; }
.item-hint-warn { color: #92400e; background: #fef3c7; }
.item-edit-mark { font-size: 10px; color: #b45309; background: #fef3c7; padding: 2px 6px; border-radius: 4px; font-weight: 700; cursor: help; white-space: nowrap; display: inline-flex; align-items: center; gap: 3px; }
.inline-ui-icon { width: 12px; height: 12px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.inline-ui-icon svg { width: 12px; height: 12px; stroke-width: 2.4; }
:deep(.inline-ui-icon svg) { width: 12px; height: 12px; stroke-width: 2.4; }
.item-admin-edited { background: #fffbeb; }
.item-admin-edited:hover { background: #fef3c7; }
.item-input { display: flex; align-items: center; gap: 4px; flex-shrink: 0; }
.item-qty { width: 72px; padding: 8px 4px; border: 1.5px solid #e0dbd5; border-radius: 8px; font-size: 16px; text-align: center; font-family: inherit; background: white; transition: border-color 0.15s; }
.item-qty:focus { outline: none; border-color: #E76F51; box-shadow: 0 0 0 2px rgba(231,111,81,0.08); }
.item-qty-err { border-color: #dc2626 !important; background: #fef2f2; }
.item-unit { font-size: 12px; color: #8b7355; font-weight: 500; min-width: 20px; }

.prev-data { padding: 8px 12px; background: #faf8f5; border-top: 1px solid #ede8e3; }
.prev-data-title { font-size: 12px; font-weight: 600; color: #502314; margin-bottom: 4px; }
.prev-data-row { display: flex; justify-content: space-between; padding: 2px 0; font-size: 12px; color: #502314; }

/* Veg success */
.veg-success-list { text-align: left; margin: 10px 0; }
.veg-success-day { font-weight: 600; color: #502314; padding: 4px 0 2px; border-bottom: 1px solid #ede8e3; font-size: 12px; }
.veg-success-row { display: flex; justify-content: space-between; padding: 2px 0; font-size: 12px; }
.veg-success-skip { color: #d97706; font-size: 11px; padding: 2px 0; font-style: italic; }

/* History */
.hist-badge { font-size: 10px; padding: 2px 8px; border-radius: 10px; font-weight: 700; white-space: nowrap; }
.src-delivery { background: #FFF0EE; color: #E76F51; }
.src-supplier { background: #EFF6FF; color: #2563eb; }
.src-planeta { background: #ECFDF5; color: #16a34a; }
.status-badge.st-submitted { background: #ECFDF5; color: #16a34a; }
.status-badge.st-locked   { background: #FEF2F2; color: #dc2626; }
.status-badge.st-draft    { background: #F5F0EB; color: #8b7355; }
/* History modal */
.hist-modal .cab-modal-head { border-bottom: 1px solid #F2EDE8; }
.hist-modal-title-block { display: flex; align-items: center; gap: 10px; min-width: 0; }
.hist-modal-date { font-weight: 700; font-size: 16px; color: #502314; }
.hist-modal-meta { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
.hist-modal-meta-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.hist-modal-time { font-size: 12px; color: #8b7355; }
.hist-modal-comment { font-size: 13px; color: #6b4f3a; background: #FAF8F5; padding: 8px 12px; border-radius: 8px; }
.hist-modal-items { border-top: 1px solid #F2EDE8; }
.hist-modal-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 11px 0; border-bottom: 1px solid #F7F2EC; }
.hist-modal-row:last-child { border-bottom: none; }
.hist-modal-name { flex: 1; min-width: 0; color: #502314; font-size: 14px; display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }
.hist-modal-qty-block { display: flex; align-items: center; gap: 5px; flex-shrink: 0; }
.hist-qty-val { font-weight: 700; color: #502314; font-size: 15px; }
.hist-qty-orig { font-size: 13px; color: #9e8a7a; text-decoration: line-through; }
.hist-qty-arrow { font-size: 12px; color: #9e8a7a; }
.hist-qty-admin { font-weight: 700; color: #E76F51; font-size: 15px; }
.hist-edited-mark { width: 14px; height: 14px; color: #d97706; display: inline-flex; align-items: center; justify-content: center; }
.hist-edited-mark svg { width: 14px; height: 14px; stroke-width: 2.2; }
:deep(.hist-edited-mark svg) { width: 14px; height: 14px; stroke-width: 2.2; }

/* Stock */
.stock-card { background: white; border-radius: 18px; padding: 32px 24px; margin: 0 0 16px; text-align: center; border: 1px solid #EDE8E3; }
.stock-card h2 { color: #502314; margin: 0 0 12px; }
.stock-card p { color: #8b7355; font-size: 14px; margin: 0; }
.stock-link { display: inline-flex; margin-top: 16px; }


/* ═══ Сбор остатков (редизайн) ═══ */
.sc-section { padding-bottom: 120px; }
.sc-wrap { max-width: 760px; margin: 0 auto; display: flex; flex-direction: column; gap: 14px; }

/* Переключатель коллекций (если несколько) */
.sc-coll-switcher { display: flex; flex-wrap: wrap; gap: 8px; }
.sc-coll-chip {
  display: inline-flex; align-items: center; justify-content: space-between; gap: 10px;
  padding: 9px 14px; border-radius: 999px; border: 1.5px solid #EDE8E3;
  background: #FFFBF6; color: #502314; cursor: pointer; font: inherit;
  transition: all .15s ease;
}
.sc-coll-chip span { font-weight: 700; font-size: 13px; }
.sc-coll-chip small { color: #8b7355; font-size: 11px; font-weight: 700; }
.sc-coll-chip.active { background: #502314; border-color: #502314; color: #fff; }
.sc-coll-chip.active small { color: rgba(255,255,255,.85); }
.sc-coll-chip:hover:not(.active) { border-color: #E76F51; }

/* Шапка коллекции */
.sc-head { background: #fff; border: 1px solid #EDE8E3; border-radius: 14px; padding: 16px 18px; }
.sc-head-title { margin: 0 0 4px; color: #2C1A12; font-size: 18px; font-weight: 700; }
.sc-head-sub { margin: 0; color: #8b7355; font-size: 13px; }

/* Тулбар: поиск + фильтры */
.sc-toolbar { display: flex; flex-direction: column; gap: 10px; }
.sc-search-input {
  width: 100%; padding: 11px 14px; border-radius: 10px;
  border: 1.5px solid #E8DCC8; background: #fff; color: #2C1A12;
  font-size: 14px; font-family: inherit; transition: border-color .15s, box-shadow .15s;
}
.sc-search-input:focus { outline: none; border-color: #E76F51; box-shadow: 0 0 0 3px rgba(231,111,81,.15); }
.sc-filter-chips { display: flex; gap: 8px; overflow-x: auto; -webkit-overflow-scrolling: touch; padding-bottom: 2px; }
.sc-fchip {
  display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0;
  padding: 7px 12px; border: 1.5px solid #E8DCC8; background: #FFFBF6;
  color: #6B5344; border-radius: 999px; cursor: pointer; font: inherit;
  font-size: 13px; font-weight: 600; transition: all .15s;
}
.sc-fchip:hover:not(.active) { border-color: #E76F51; }
.sc-fchip.active { background: #E76F51; color: #fff; border-color: #E76F51; }
.sc-fchip-count {
  display: inline-block; min-width: 18px; padding: 1px 7px;
  border-radius: 10px; background: rgba(0,0,0,.08); font-size: 11px; font-weight: 700;
}
.sc-fchip.active .sc-fchip-count { background: rgba(255,255,255,.25); }

/* Группа товаров */
.sc-group { display: flex; flex-direction: column; gap: 10px; }
.sc-group-head {
  display: flex; align-items: baseline; gap: 8px;
  padding: 0 4px;
}
.sc-group-head h3 {
  margin: 0; font-size: 12.5px; font-weight: 700;
  color: #8b7355; text-transform: uppercase; letter-spacing: .04em;
}
.sc-group-count {
  display: inline-block; min-width: 22px; padding: 1px 8px;
  border-radius: 999px; background: #F0E5D6; color: #6B5344;
  font-size: 11.5px; font-weight: 700;
}
.sc-list { display: flex; flex-direction: column; gap: 8px; }

/* Карточка товара */
.sc-card {
  background: #fff; border: 1.5px solid #ECE3D6; border-radius: 12px;
  padding: 12px 14px; display: flex; flex-direction: column; gap: 10px;
  transition: border-color .15s, background .15s;
}
.sc-card-filled { border-color: #C8E6C9; background: #FAFDF9; }
.sc-card-invalid { border-color: #E76F51; background: #FFF5F0; }
.sc-card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; }
.sc-card-title { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; min-width: 0; flex: 1; }
.sc-card-sku {
  display: inline-block;
  font-family: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, monospace;
  font-size: 11px; font-weight: 600; color: #8C7B6E;
  background: #FFF8F0; border: 1px solid #ECE3D6; border-radius: 5px;
  padding: 1px 6px; letter-spacing: .02em;
  vertical-align: 1px;
}
.sc-card-name { color: #2C1A12; font-size: 14.5px; font-weight: 600; line-height: 1.3; }
.sc-card-total {
  display: inline-flex; align-items: baseline; gap: 4px;
  white-space: nowrap; padding: 4px 10px; border-radius: 8px;
  background: #FFF1E0; color: #C16B4D; font-weight: 700;
  flex-shrink: 0;
}
.sc-card-filled .sc-card-total { background: #E8F5E9; color: #2E7D32; }
.sc-card-total-num { font-size: 16px; }
.sc-card-total-unit { font-size: 12px; opacity: .8; }
.sc-card-note { color: #8b7355; font-size: 12px; margin: -4px 0 0; }

/* Партии (товар со сроком) */
.sc-batches { display: flex; flex-direction: column; gap: 8px; }
.sc-batch-row {
  display: grid;
  grid-template-columns: 1fr 1fr 32px;
  gap: 10px; align-items: end;
}
.sc-batch-fld { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.sc-batch-lbl {
  font-size: 11px; font-weight: 600; color: #8b7355;
  text-transform: uppercase; letter-spacing: .04em;
}
.sc-input {
  width: 100%; padding: 9px 11px; border-radius: 8px;
  border: 1.5px solid #E8DCC8; background: #fff; color: #2C1A12;
  font-size: 15px; font-family: inherit;
  transition: border-color .15s, box-shadow .15s;
  min-height: 40px; box-sizing: border-box;
}
.sc-input:focus { outline: none; border-color: #E76F51; box-shadow: 0 0 0 3px rgba(231,111,81,.15); }
.sc-input-num { text-align: right; }
.sc-input-err { border-color: #E76F51; background: #FFF5F0; }
.sc-batch-del {
  border: none; background: transparent; color: #c16b4d; cursor: pointer;
  width: 32px; height: 40px; border-radius: 8px; flex-shrink: 0;
  font-size: 14px; align-self: end;
}
.sc-batch-del:hover { background: #FFF1EC; }
.sc-batch-add {
  align-self: flex-start; border: 1.5px dashed #E8DCC8;
  background: transparent; color: #C16B4D;
  font-size: 13px; font-weight: 600; cursor: pointer;
  padding: 7px 12px; border-radius: 8px; font-family: inherit;
  transition: all .15s;
}
.sc-batch-add:hover { border-color: #E76F51; background: #FFF8F0; color: #E76F51; }

/* Карточка без срока — поле в одну строку */
.sc-card-input-row {
  display: grid; grid-template-columns: 1fr auto; gap: 8px;
  align-items: center;
}
.sc-card-input { max-width: 180px; justify-self: end; }
.sc-card-input-unit { font-size: 13px; color: #8b7355; font-weight: 600; min-width: 28px; }

/* Sticky-полоса сохранения */
.sc-savebar {
  position: sticky; bottom: 0;
  margin: 14px -4px 0;
  padding: 12px 4px 0;
  background: linear-gradient(to top, #FAF6EF 70%, rgba(250,246,239,0));
  z-index: 10;
}
.sc-savebar-inner {
  display: flex; align-items: center; gap: 14px;
  background: #fff; border: 1px solid #ECE3D6; border-radius: 14px;
  padding: 12px 16px;
  box-shadow: 0 -2px 8px rgba(80,35,20,.06);
}
.sc-savebar-progress { flex: 1; min-width: 0; }
.sc-savebar-progress-text { font-size: 13px; color: #6B5344; margin-bottom: 4px; }
.sc-savebar-progress-text b { color: #2C1A12; font-weight: 700; }
.sc-savebar-progress-bar {
  height: 6px; border-radius: 999px; background: #F0E5D6; overflow: hidden;
}
.sc-savebar-progress-fill {
  height: 100%; border-radius: 999px;
  background: linear-gradient(90deg, #F4A261, #E76F51);
  transition: width .25s ease;
}
.sc-savebar-btn { flex-shrink: 0; min-width: 180px; }

/* Profile */
.profile-card { background: white; border-radius: 18px; padding: 20px; margin-bottom: 12px; display: flex; border: 1px solid #EDE8E3; }
.profile-header { display: flex; align-items: center; gap: 12px; }
.profile-avatar { width: 40px; height: 40px; border-radius: 10px; background: #E76F51; color: white; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 800; flex-shrink: 0; }
.profile-header h2 { margin: 0; font-size: 15px; color: #502314; }
.profile-header p { margin: 1px 0 0; font-size: 12px; color: #8b7355; }
.profile-le { font-size: 11px; color: #b39b83; }

.profile-block { background: white; border-radius: 16px; padding: 18px 20px; margin-bottom: 10px; border: 1px solid #EDE8E3; }
.profile-block h3 { margin: 0 0 8px; font-size: 13px; color: #502314; }

.profile-tg-unlinked p { margin: 0 0 12px; font-size: 13px; color: #8b7355; }
.tg-code-box { background: #f0f9ff; border-radius: 8px; padding: 12px; text-align: center; }
.tg-code-box p { margin: 0 0 6px; font-size: 12px; color: #502314; }
.tg-code-box a { color: #2563eb; font-weight: 600; }
.tg-code { font-size: 28px; font-weight: 800; color: #E76F51; letter-spacing: 5px; font-variant-numeric: tabular-nums; margin: 6px 0; }
.tg-code-hint { font-size: 10px; color: #8b7355; margin: 0; }

.profile-tg-links { margin-top: 14px; padding-top: 12px; border-top: 1px solid #EDE8E3; }
.profile-tg-links-title { font-size: 12px; font-weight: 600; color: #502314; margin-bottom: 8px; }
.tg-links { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.tg-link-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 8px 10px; background: #FAF7F3; border-radius: 8px; }
.tg-link-info { min-width: 0; flex: 1; }
.tg-link-name { font-size: 13px; font-weight: 600; color: #502314; }
.tg-link-username { color: #8b7355; font-weight: 500; margin-left: 4px; }
.tg-link-meta { font-size: 11px; color: #8b7355; margin-top: 2px; }
.tg-link-warn { color: #dc2626; font-weight: 600; }

.pw-form { display: flex; flex-direction: column; gap: 10px; }
.input-field { padding: 10px 14px; border: 1.5px solid #e0dbd5; border-radius: 8px; font-size: 14px; font-family: inherit; }
.input-field:focus { outline: none; border-color: #E76F51; box-shadow: 0 0 0 2px rgba(231,111,81,0.08); }

.contact-link { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; background: linear-gradient(135deg, #0088cc, #229ED9); color: white; text-decoration: none; border-radius: 10px; font-size: 14px; font-weight: 600; transition: transform 0.15s; }
.contact-link:hover { transform: translateY(-1px); }

.logout-full { width: 100%; margin-top: 16px; }

/* Modal */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 10000; padding: 20px; }
.cab-modal { background: white; border-radius: 20px; width: 100%; max-width: 480px; max-height: 80vh; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 16px 48px rgba(0,0,0,0.15); }
.cab-modal-head { display: flex; justify-content: space-between; align-items: center; padding: 18px 22px; border-bottom: 1px solid #EDE8E3; }
.cab-modal-head h2 { margin: 0; font-size: 17px; font-weight: 800; color: #502314; }
.cab-modal-close { background: none; border: none; cursor: pointer; font-size: 20px; color: #B0A090; transition: color 0.15s; }
.cab-modal-close:hover { color: #502314; }
.cab-modal-body { padding: 18px 22px; overflow-y: auto; flex: 1; }
.cab-modal-info { max-width: 380px; }
.cab-modal-important { max-width: min(860px, 96vw); max-height: 88vh; }
.cab-modal-important .cab-modal-body { padding: 20px 24px; }
.cab-modal-important .info-files-modal { grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); }
.cab-info-text { color: #502314; font-size: 14px; line-height: 1.5; margin: 0 0 16px; }
.cab-info-text.cab-info-error { color: #b91c1c; }
.cab-info-text-broadcast { white-space: pre-line; }

/* Старые .info-toolbar/.info-posts/.info-post оставлены пустыми — стили
   полностью заменены на .info-section/.info-card ниже. */
.info-files { display: flex; flex-direction: column; gap: 7px; margin-top: 10px; }
.info-files-modal { margin-bottom: 12px; }

.image-preview-overlay { z-index: 11000; }
.important-image-modal {
  max-width: min(960px, 96vw); max-height: 92vh; background: white; border-radius: 12px; overflow: hidden;
  display: flex; flex-direction: column;
}
.important-image-head {
  display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 12px;
  border-bottom: 1px solid #EDE8E3; color: #502314; font-weight: 800; font-size: 14px;
}
.important-image-head button { border: 0; background: transparent; color: #502314; font-size: 26px; line-height: 1; cursor: pointer; }
.important-image-modal img { max-width: 100%; max-height: calc(92vh - 48px); object-fit: contain; display: block; }
.cab-info-meta { color: #8b7355; font-size: 12px; margin: -8px 0 16px; }
.cab-info-actions { display: flex; justify-content: flex-end; }
.cab-info-actions-two { gap: 8px; }
.modal-search { width: 50%; min-width: 200px; margin-bottom: 12px; }
.add-list { display: flex; flex-direction: column; gap: 4px; }
.add-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; border-radius: 8px; cursor: pointer; border: 1px solid #f5f0eb; }
.add-item:hover { background: #f7f5f2; border-color: #E76F51; }
.add-item-meta { display: flex; gap: 6px; align-items: center; }
.add-cat { font-size: 10px; color: #8b7355; background: #f7f5f2; padding: 2px 6px; border-radius: 4px; }

/* ═══ Mobile ═══ */
@media (max-width: 768px) {
  .cab { display: block; }
  .cab-sidebar { display: none; }
  .cab-main { margin-left: 0; }
  .cab-section { padding: 16px; }

  /* Сбор остатков — на мобильнике партии разворачиваются под наименование */
  /* Сбор остатков на мобилке */
  .sc-section { padding-bottom: 140px; }
  .sc-card { padding: 12px; border-radius: 12px; }
  .sc-card-head { flex-wrap: wrap; gap: 8px; }
  .sc-card-name { font-size: 14px; }
  .sc-card-total-num { font-size: 15px; }
  .sc-batch-row { grid-template-columns: 1fr 1fr; }
  .sc-batch-del {
    grid-column: 1 / -1;
    width: auto; height: auto;
    padding: 8px;
    background: #FFF1EC; color: #c16b4d;
    border-radius: 8px; font-size: 12px; font-weight: 600;
  }
  .sc-batch-del:hover { background: #FFE0D5; }
  .sc-card-input-row { grid-template-columns: 1fr auto; }
  .sc-card-input { max-width: none; width: 100%; }
  .sc-savebar { margin-left: -14px; margin-right: -14px; padding: 12px 14px 0; }
  .sc-savebar-inner { flex-direction: column; align-items: stretch; gap: 10px; padding: 12px; }
  .sc-savebar-btn { width: 100%; min-width: 0; padding: 12px; font-size: 15px; }
  .stock-date-input { width: auto !important; }
  .stock-qty-input { width: auto !important; }

  /* Dashboard */
  .dash-grid { grid-template-columns: repeat(2, 1fr); }
  .dash-action-grid { grid-template-columns: repeat(2, 1fr); }

  /* Order sub-tabs */
  .mob-order-tabs {
    display: flex;
    position: sticky;
    top: 0;
    z-index: 45;
    gap: 6px;
    flex-wrap: nowrap;
    overflow-x: auto;
    margin: -16px -12px 12px;
    padding: 10px 12px;
    background: #F5F0EB;
    border-bottom: 1px solid #EDE8E3;
    scrollbar-width: none;
  }
  .cab.cab-theme-ps .mob-order-tabs { background: #faf2eb; }
  .mob-order-tabs::-webkit-scrollbar { display: none; }
  .ord-tabs { gap: 4px; flex-wrap: nowrap; overflow-x: auto; }
  .ord-tab { padding: 6px 12px; font-size: 12px; }

  /* Day tabs */
  .day-tabs { gap: 6px; }
  .day-tab { padding: 10px 14px; min-width: 66px; }

  /* Search */
  .search-row { padding: 8px 12px; }
  .input-search { width: 100%; flex: none; font-size: 16px; padding: 10px 12px; }

  /* Delivery table mobile */
  .del-table { display: block; }
  .del-table thead { display: none; }
  .del-table tbody { display: block; }
  .del-table tr { display: flex; align-items: center; padding: 10px 12px; gap: 10px; border-bottom: 1px solid #F5F2EE; flex-wrap: nowrap; }
  .del-table td { padding: 0; border: none; height: auto; }
  .del-td-name { flex: 1; font-size: 13px; min-width: 0; overflow: hidden; text-overflow: ellipsis; }
  .del-td-qty { flex-shrink: 0; }
  .del-td-act { flex-shrink: 0; }
  .del-qty { width: 68px; height: 42px; font-size: 16px; }

  /* Item list (Planeta, Kamako) */
  .item-row { padding: 10px 12px; flex-wrap: wrap; }
  .item-info { flex: 1 1 100%; margin-bottom: 6px; }
  .item-name { font-size: 14px; }
  .item-qty { width: 72px; font-size: 16px; padding: 10px 4px; }

  /* Buttons */
  .btn-lg { width: 100%; justify-content: center; padding: 14px; font-size: 16px; }

  /* Category tabs */
  .cat-tabs { padding: 10px 12px; }
  .cat-tab { padding: 8px 12px; font-size: 12px; }

  /* Modal */
  .cab-modal { max-width: 100%; margin: 8px; border-radius: 14px; }

  /* Profile */
  .profile-card { margin-top: 8px; }
  .input-field { font-size: 16px; padding: 12px 14px; }
  .pw-form .btn { width: 100%; justify-content: center; }

  .whs-panel { padding: 14px; border-radius: 14px; }
  .whs-head { align-items: stretch; flex-direction: column; }
  .whs-controls { flex-direction: column; align-items: stretch; }
  .whs-search { min-width: 0; }
  .whs-list-head { display: none; }
  .whs-row { grid-template-columns: 1fr; gap: 8px; }
  .whs-qty, .whs-exp { text-align: left; align-items: flex-start; }
  .whs-name { flex-wrap: wrap; }
  .whs-title { flex-basis: 100%; }
  .whs-batch { grid-template-columns: 1fr; gap: 5px; padding: 10px 0; }
  .whs-batch-head { display: none; }
  .whs-batch-name::before { content: 'Номенклатура: '; color: #9b8064; font-weight: 700; }
  .whs-batch span:nth-child(2)::before { content: 'Склад: '; color: #9b8064; font-weight: 700; }
  .whs-batch b::before { content: 'Остаток: '; color: #9b8064; font-weight: 700; }
  .whs-batch span:nth-child(4)::before { content: 'Срок: '; color: #9b8064; font-weight: 700; }
  .whs-batch span:nth-child(5)::before { content: 'Статус: '; color: #9b8064; font-weight: 700; }
  .whs-batch span:nth-child(n), .whs-batch b { text-align: left; }
  .cab-sv-scale { grid-template-columns: repeat(5, 1fr); }

  /* Surveys */
  .cab-sv { grid-template-columns: 1fr; }
  .cab-sv-list {
    position: static;
    max-height: none;
    flex-direction: row;
    overflow-x: auto;
    padding: 8px;
    gap: 8px;
  }
  .cab-sv-item {
    min-width: 240px;
    flex-shrink: 0;
  }
  .cab-sv-hero { padding: 18px 18px 16px; }
  .cab-sv-hero-title { font-size: 18px; }
  .cab-sv-q { padding: 16px 18px; }
  .cab-sv-q-text { font-size: 15px; }
  .cab-sv-progress { padding: 12px 18px; }
  .cab-sv-comment, .cab-sv-comment-readonly { padding: 14px 18px 16px; }
  .cab-sv-actions { padding: 16px 18px 20px; flex-direction: column; align-items: stretch; }
  .cab-sv-actions .cab-sv-actions-hint { text-align: center; }
  .cab-sv-submit { width: 100%; min-width: 0; }

  /* Success */
  .cab-success { min-height: 25vh; }
  .cab-success-inner { padding: 24px 16px; }

  /* Order form */
  .order-form { border-radius: 14px; }

  /* Mobile tab bar */
  .mob-tabbar { display: flex !important; }
  .cab-topbar { display: none; }
  .cab-section { padding: 16px 12px 90px; }
}

/* ═══ Mobile tab bar ═══ */
.mob-tabbar {
  display: none;
  position: fixed; bottom: 0; left: 0; right: 0;
  background: white; border-top: 1px solid #EDE8E3;
  padding: 6px 0 env(safe-area-inset-bottom, 6px);
  z-index: 200; box-shadow: 0 -4px 16px rgba(0,0,0,0.06);
}
.mob-tab {
  flex: 1; display: flex; flex-direction: column; align-items: center; gap: 2px;
  cursor: pointer; padding: 6px 0; border: none; background: none; font-family: inherit;
  color: #B0A090; transition: color 0.15s; position: relative;
}
.mob-tab.active { color: #E76F51; }
.mob-tab-icon { height: 20px; display: flex; align-items: center; justify-content: center; }
.mob-tab-label { font-size: 9px; font-weight: 700; }
.mob-tab-badge {
  position: absolute; top: 2px; right: calc(50% - 18px);
  min-width: 16px; height: 16px; padding: 0 4px; border-radius: 8px;
  background: #E76F51; color: white; font-size: 9px; font-weight: 800;
  display: flex; align-items: center; justify-content: center;
}
.mob-tab-badge.warn { background: #F4A261; }
.mob-tab-badge.alert { background: #E53935; }
.mob-tab.alert { color: #E53935; }

/* ═══ Mobile topbar (compact) ═══ */
.mob-topbar { display: none; }
@media (max-width: 768px) {
  .mob-topbar {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 14px;
    background: #fff; border-bottom: 1px solid #EDE8E3;
    position: sticky; top: 0; z-index: 50;
  }
  .mob-topbar-rest {
    display: flex; flex-direction: column; align-items: center;
    min-width: 44px;
    background: linear-gradient(135deg, #502314, #6B321F);
    color: #fff; border-radius: 10px;
    padding: 4px 10px;
    line-height: 1;
  }
  .mob-topbar-num { font-size: 16px; font-weight: 800; letter-spacing: 0.02em; }
  .mob-topbar-label { font-size: 8px; opacity: 0.75; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 1px; }
  .mob-topbar-screen {
    flex: 1; min-width: 0;
    font-size: 15px; font-weight: 700; color: #2C1A12;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  .mob-topbar-scan {
    flex-shrink: 0; width: 40px; height: 40px;
    border: none; border-radius: 10px;
    background: #FFF1E0; color: #C16B4D; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
    transition: background .15s, color .15s;
  }
  .mob-topbar-scan:hover, .mob-topbar-scan:active { background: #E76F51; color: #fff; }
}

/* ═══ Dashboard tiles (Сервисы) ═══ */
.dash-services { margin-top: 18px; }
.dash-tiles {
  display: grid; grid-template-columns: 1fr 1fr; gap: 10px;
}
.dash-tile {
  display: grid; grid-template-columns: 44px 1fr 16px;
  align-items: center; gap: 12px;
  padding: 14px 14px; border-radius: 14px; cursor: pointer;
  border: 1.5px solid transparent;
  background: #fff;
  font-family: inherit; text-align: left; text-decoration: none;
  color: #2C1A12;
  transition: transform .08s, box-shadow .15s, border-color .15s;
  box-shadow: 0 1px 0 rgba(80,35,20,.04);
}
.dash-tile:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(80,35,20,.08); }
.dash-tile-icon {
  width: 44px; height: 44px; border-radius: 12px;
  display: inline-flex; align-items: center; justify-content: center;
  background: #FFF1E0; color: #E76F51;
  flex-shrink: 0;
}
.dash-tile-icon svg { width: 26px; height: 26px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
.dash-tile-text { min-width: 0; }
.dash-tile-title {
  font-size: 14px; font-weight: 700; color: #2C1A12;
  display: flex; align-items: center; gap: 6px;
  line-height: 1.25;
}
.dash-tile-beta {
  display: inline-block; padding: 1px 5px; border-radius: 4px;
  background: #FFE8C9; color: #B45309;
  font-size: 9px; font-weight: 800; letter-spacing: 0.04em;
}
.dash-tile-sub { font-size: 12px; color: #8B7355; margin-top: 2px; line-height: 1.3; }
.dash-tile-arrow { color: #C7B9A7; font-size: 18px; font-weight: 300; line-height: 1; }
/* Цветовые акценты */
.dash-tile--scanner .dash-tile-icon { background: #E5F3E5; color: #2E7D32; }
.dash-tile--warehouse .dash-tile-icon { background: #EFE9FF; color: #6B46C1; }
.dash-tile--keg .dash-tile-icon { background: #FFF1E0; color: #C16B4D; }
.dash-tile-icon-img img {
  width: 100%;
  height: 100%;
  object-fit: contain;
  display: block;
}
.dash-tile--cards .dash-tile-icon { background: #E0F2FE; color: #1D4ED8; }
.dash-tile--reminders .dash-tile-icon { background: #FEEFCB; color: #B45309; }
.dash-tile--corrections .dash-tile-icon { background: #FFE9D6; color: #8B4513; }

@media (max-width: 640px) {
  .dash-tiles { grid-template-columns: 1fr; gap: 8px; }
  .dash-tile { padding: 12px; }
}

/* ═══ Profile (редизайн) ═══ */
.pf-section { padding-bottom: 100px; max-width: 720px; margin: 0 auto; }
.pf-hero {
  display: flex; gap: 14px; align-items: center;
  background: linear-gradient(135deg, #FFF8F0, #FFFBF6);
  border: 1px solid #ECE3D6; border-radius: 16px;
  padding: 18px;
  margin-bottom: 14px;
}
.pf-hero-avatar {
  width: 64px; height: 64px; border-radius: 16px;
  background: linear-gradient(135deg, #E76F51, #F4A261);
  color: #fff; font-size: 22px; font-weight: 800;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  box-shadow: 0 4px 12px rgba(231,111,81,.25);
}
.pf-hero-info { min-width: 0; flex: 1; }
.pf-hero-title { margin: 0 0 4px; font-size: 18px; font-weight: 700; color: #2C1A12; }
.pf-hero-addr { margin: 0; font-size: 13px; color: #6B5344; line-height: 1.4; }
.pf-hero-le { margin: 4px 0 0; font-size: 12px; color: #8B7355; }

.pf-card {
  background: #fff; border: 1px solid #ECE3D6; border-radius: 14px;
  padding: 16px; margin-bottom: 12px;
}
.pf-card-head {
  display: flex; align-items: center; gap: 12px; margin-bottom: 14px;
}
.pf-card-icon {
  width: 40px; height: 40px; border-radius: 10px;
  display: inline-flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.pf-icon-tg { background: #E1F5FE; color: #0277BD; }
.pf-icon-lock { background: #F3E5F5; color: #6B46C1; }
.pf-icon-contact { background: #FFF1E0; color: #C16B4D; }
.pf-card-title { min-width: 0; flex: 1; }
.pf-card-title h3 { margin: 0; font-size: 15px; font-weight: 700; color: #2C1A12; }
.pf-card-title p { margin: 2px 0 0; font-size: 12px; color: #8B7355; }

/* Кнопки профиля */
.pf-btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  padding: 10px 16px; border: 1.5px solid transparent; border-radius: 10px;
  font: inherit; font-size: 14px; font-weight: 600;
  cursor: pointer; transition: background .15s, border-color .15s, transform .08s;
}
.pf-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.pf-btn.block { width: 100%; }
.pf-btn.lg { padding: 13px 18px; font-size: 15px; }
.pf-btn.sm { padding: 7px 12px; font-size: 13px; }
.pf-btn.primary { background: #E76F51; color: #fff; border-color: #E76F51; }
.pf-btn.primary:hover:not(:disabled) { background: #D9603F; border-color: #D9603F; }
.pf-btn.danger { background: #fff; color: #E53935; border-color: #FFCDD2; }
.pf-btn.danger:hover:not(:disabled) { background: #FFEBEE; border-color: #E53935; }
.pf-btn.ghost { background: transparent; color: #6B5344; border-color: #EDE7DF; }
.pf-btn.ghost.danger { color: #E53935; border-color: #FFCDD2; }
.pf-btn.ghost.danger:hover:not(:disabled) { background: #FFEBEE; }

/* Telegram-код */
.pf-tg-code {
  background: #F4FBF4; border: 1px solid #C8E6C9; border-radius: 10px;
  padding: 14px; text-align: center;
}
.pf-tg-code-hint { margin: 0 0 10px; font-size: 13px; color: #2E7D32; }
.pf-tg-code-hint a { color: #2E7D32; font-weight: 700; }
.pf-tg-code-box {
  font-family: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, monospace;
  font-size: 22px; font-weight: 800; letter-spacing: 0.18em;
  color: #2C1A12; padding: 12px; background: #fff;
  border-radius: 8px; border: 1.5px dashed #C8E6C9;
}
.pf-tg-code-meta { margin: 8px 0 0; font-size: 11px; color: #8B7355; }

/* Список привязок */
.pf-tg-links { margin-top: 14px; padding-top: 14px; border-top: 1px solid #F2EDE8; }
.pf-tg-links-title { font-size: 12px; color: #8B7355; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 8px; }
.pf-tg-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px; }
.pf-tg-item {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  background: #FAFAF8; border: 1px solid #EDE8E3; border-radius: 10px;
  padding: 10px 12px;
}
.pf-tg-item-info { min-width: 0; flex: 1; }
.pf-tg-item-name { font-size: 14px; font-weight: 700; color: #2C1A12; }
.pf-tg-item-username { font-weight: 400; color: #8B7355; margin-left: 6px; }
.pf-tg-item-meta { font-size: 12px; margin-top: 2px; }
.pf-tg-item-ok { color: #2E7D32; }
.pf-tg-item-warn { color: #C16B4D; }

/* Форма пароля */
.pf-form { display: flex; flex-direction: column; gap: 10px; }
.pf-input {
  padding: 11px 14px; border-radius: 10px;
  border: 1.5px solid #EDE8E3; background: #FAFAF8; color: #2C1A12;
  font: inherit; font-size: 15px;
  transition: border-color .15s, background .15s;
}
.pf-input:focus { outline: none; border-color: #E76F51; background: #fff; box-shadow: 0 0 0 3px rgba(231,111,81,.12); }
.pf-msg { padding: 10px 12px; border-radius: 8px; font-size: 13px; }
.pf-msg-err { background: #FFEBEE; color: #C62828; }
.pf-msg-ok { background: #E8F5E9; color: #2E7D32; }

/* Контакты */
.pf-contact-link {
  display: flex; align-items: center; gap: 12px;
  padding: 12px; border-radius: 10px; background: #FAFAF8;
  border: 1px solid #EDE8E3; text-decoration: none; color: #2C1A12;
  transition: background .15s, border-color .15s;
}
.pf-contact-link:hover { background: #FFF8F0; border-color: #D6C5AB; }
.pf-contact-icon {
  width: 38px; height: 38px; border-radius: 10px;
  background: #E1F5FE; color: #0277BD;
  display: inline-flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.pf-contact-text { display: flex; flex-direction: column; flex: 1; min-width: 0; }
.pf-contact-username { font-size: 14.5px; font-weight: 700; color: #2C1A12; }
.pf-contact-sub { font-size: 12px; color: #8B7355; }
.pf-contact-arrow { color: #C7B9A7; font-size: 18px; font-weight: 300; }

.pf-logout-row { display: flex; justify-content: flex-start; margin-top: 14px; }
.pf-logout { min-width: 220px; }
@media (max-width: 640px) {
  .pf-logout-row { justify-content: stretch; }
  .pf-logout { width: 100%; min-width: 0; }
}

@media (max-width: 640px) {
  .pf-hero { padding: 14px; gap: 12px; }
  .pf-hero-avatar { width: 56px; height: 56px; font-size: 19px; border-radius: 14px; }
  .pf-hero-title { font-size: 16px; }
  .pf-card { padding: 14px; }
  .pf-card-icon { width: 36px; height: 36px; }
  .pf-card-icon svg { width: 18px; height: 18px; }
  .pf-card-title h3 { font-size: 14px; }
  .pf-input { padding: 12px 14px; font-size: 16px; /* Чтобы iOS не зумил */ }
  .pf-tg-item { flex-direction: column; align-items: stretch; gap: 8px; }
  .pf-tg-code-box { font-size: 19px; letter-spacing: 0.14em; }
}

/* ═══ Order comment ═══ */
.order-comment-row { margin-bottom: 12px; }
.order-comment-input {
  width: 100%; padding: 10px 14px; border: 1.5px solid #EDE8E3; border-radius: 10px;
  font-size: 13px; font-family: inherit; color: #502314; background: white;
  transition: border-color 0.15s;
}
.order-comment-input:focus { outline: none; border-color: #E76F51; }
.order-comment-input::placeholder { color: #D4C4B0; }

/* ═══ Submit bottom ═══ */
.submit-bottom {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
}
.submit-buttons-row {
  display: flex; align-items: center; justify-content: center; gap: 10px; flex-wrap: wrap;
}
.submit-summary { display: flex; align-items: center; justify-content: center; gap: 14px; font-size: 13px; color: #8b7355; margin-bottom: 8px; }
.submit-summary strong { color: #502314; font-weight: 700; }
</style>
