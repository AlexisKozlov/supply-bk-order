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
        :href="link.url"
        target="_blank"
        rel="noopener noreferrer"
      >
        <span class="supplier-icon supplier-icon-sm" :class="link.iconClass" v-html="link.iconSvg"></span>
        {{ link.name }}
        <span class="sb-ext" v-html="cabIconSvg.external"></span>
      </a>
      <!-- История заказов -->
      <button class="sb-item"
        :class="{ active: activeTab === 'orders' && orderSubTab === 'history' }"
        @click="switchTab('orders', 'history')">
        <span class="sb-icon" v-html="cabIconSvg.history"></span>
        История заказов
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

      <div class="sb-spacer"></div>
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

    <!-- ══════ Loading ══════ -->
    <div v-if="globalLoading" class="cab-loader">
      <div class="cab-spin"></div>
    </div>
    <section v-else-if="globalError" class="cab-section">
      <div class="cab-empty-card">
        <h2>Не удалось открыть кабинет</h2>
        <p>{{ globalError }}</p>
        <button class="btn btn-primary" @click="retryCabinetLoad">Повторить</button>
      </div>
    </section>

    <!-- ══════ TAB: Дашборд ══════ -->
    <section v-if="activeTab === 'dashboard' && !globalLoading && !globalError" class="cab-section">
      <div class="dash-wrap">
        <div class="dash-col-main">
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
    <section v-if="activeTab === 'info' && !globalLoading && !globalError" class="cab-section info-section">
      <div v-if="importantLoading && !importantPosts.length" class="cab-empty-card">
        <BurgerSpinner text="Загрузка..." />
      </div>
      <div v-else-if="!importantPosts.length" class="info-empty">
        <span class="info-empty-icon">
          <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.5V14a2 2 0 0 1-2 2h-7l-5 4v-4H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h12"/><circle cx="20" cy="5" r="3"/></svg>
        </span>
        <h3>Пока всё спокойно</h3>
        <p>Когда отдел закупок опубликует важную информацию, она появится здесь.</p>
      </div>
      <template v-else>
        <!-- Чипы-фильтры (только если постов > 4) -->
        <div v-if="importantPosts.length > 4" class="info-filter-chips">
          <button class="info-fchip" :class="{ active: infoFilter === 'all' }" @click="infoFilter = 'all'">
            Все <span class="info-fchip-count">{{ importantPosts.length }}</span>
          </button>
          <button class="info-fchip" :class="{ active: infoFilter === 'unread' }" @click="infoFilter = 'unread'">
            Непрочитанные <span class="info-fchip-count">{{ importantPostsUnreadCount }}</span>
          </button>
        </div>

        <div v-if="!importantPostsFiltered.length" class="info-empty">
          <span class="info-empty-icon"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m9 12 2 2 4-4"/></svg></span>
          <h3>Всё прочитано</h3>
          <p>В этой категории больше нет сообщений.</p>
        </div>

        <div v-else class="info-list">
          <article
            v-for="post in importantPostsFiltered"
            :key="post.id"
            class="info-card"
            :class="{ unread: !post.is_read }"
          >
            <header class="info-card-head">
              <div class="info-card-author">
                <span class="info-card-avatar">{{ infoAvatar(post.created_by) }}</span>
                <div class="info-card-author-info">
                  <div class="info-card-author-name">{{ post.created_by || 'Отдел закупок' }}</div>
                  <time class="info-card-time">{{ fmtDateTime(post.published_at || post.created_at) }}</time>
                </div>
              </div>
              <span v-if="!post.is_read" class="info-card-dot" aria-label="Новое"></span>
            </header>
            <h3 v-if="post.title" class="info-card-title">{{ post.title }}</h3>
            <p class="info-card-message">{{ post.message }}</p>

            <div v-if="post.files?.length" class="info-card-files">
              <button
                v-for="file in post.files"
                :key="file.id"
                class="info-file"
                :class="{ image: isImportantImage(file) }"
                @click="isImportantImage(file) ? previewImportantFile(file) : downloadImportantFile(file)"
              >
                <img v-if="isImportantImage(file) && importantPreviewUrls[file.id]" :src="importantPreviewUrls[file.id]" :alt="file.file_name" class="info-file-img" />
                <span v-else class="info-file-ico" v-html="cabIconSvg.file"></span>
                <span class="info-file-meta">
                  <span class="info-file-name">{{ file.file_name }}</span>
                  <span class="info-file-size">{{ isImportantImage(file) ? 'Открыть' : formatImportantFileSize(file.file_size) }}</span>
                </span>
              </button>
            </div>

            <footer v-if="!post.is_read" class="info-card-foot">
              <button class="info-read" @click="markImportantRead(post)">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                Отметить прочитанным
              </button>
            </footer>
          </article>
        </div>
      </template>
    </section>

    <!-- ══════ TAB: Заказы ══════ -->
    <section v-if="activeTab === 'orders' && !globalLoading && !globalError" class="cab-section cab-section-orders">
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
          :href="link.url"
          target="_blank"
          rel="noopener noreferrer"
        >
          <span class="supplier-icon supplier-icon-xs" :class="link.iconClass" v-html="link.iconSvg"></span>
          {{ link.name }}
          <span class="ord-tab-ext" v-html="cabIconSvg.external"></span>
        </a>
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

      <!-- История в заказах -->
      <div v-if="orderSubTab === 'history'" class="history-list">
        <div v-if="historyLoading" class="mini-loader"><div class="cab-spin"></div></div>
        <div v-else-if="historyError" class="cab-empty-card"><p>{{ historyError }}</p></div>
        <div v-else-if="!historyOrders.length" class="cab-empty-card"><h2>Нет заказов</h2></div>
        <template v-else>
          <!-- Фильтр по источнику -->
          <div class="hist-filters">
            <button class="hist-filter-chip" :class="{ active: historyFilter === 'all' }" @click="historyFilter = 'all'">Все</button>
            <button v-for="src in historySourceOptions" :key="src.label"
              class="hist-filter-chip" :class="[{ active: historyFilter === src.label }, 'src-chip-' + src.source]"
              @click="historyFilter = src.label">
              {{ src.label }}
            </button>
          </div>
          <div v-if="!filteredHistoryOrders.length" class="cab-empty-card"><p>Нет заказов по этому фильтру</p></div>
          <div v-else class="hist-cards">
            <div v-for="order in filteredHistoryOrders" :key="order.id" class="hist-card" :class="'hist-src-' + order.source" @click="openHistoryOrder(order)">
              <div class="hist-card-left"></div>
              <div class="hist-card-body">
                <div class="hist-card-top">
                  <span class="hist-card-date">{{ fmtDate(order.delivery_date) }}</span>
                  <span class="hist-badge" :class="'src-' + order.source">{{ order.source_name }}</span>
                  <span class="hist-badge status-badge" :class="'st-' + order.status">{{ statusLabel(order.status) }}</span>
                </div>
                <div class="hist-card-meta">
                  <span v-if="Number(order.item_count) > 0" class="hist-meta-pill">
                    {{ order.item_count }} поз. · {{ order.total_qty }} {{ order.source === 'delivery' ? 'кор.' : 'шт.' }}
                  </span>
                  <span v-else class="hist-meta-skip">Поставка не нужна</span>
                  <span v-if="order.submitted_at" class="hist-card-time">{{ fmtDateTime(order.submitted_at) }}</span>
                </div>
              </div>
              <div class="hist-card-arrow">›</div>
            </div>
          </div>
        </template>
      </div>
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
    <section v-if="activeTab === 'surveys' && !globalLoading && !globalError" class="cab-section cab-sv-section">
      <div v-if="surveyError" class="error-msg" style="margin-bottom:16px">{{ surveyError }}</div>

      <!-- ─── Начальная загрузка ─── -->
      <div v-if="surveyListLoading && !surveyItems.length" class="cab-empty-card">
        <BurgerSpinner text="Загрузка опросов..." />
      </div>

      <!-- ─── Пусто ─── -->
      <div v-else-if="!surveyItems.length" class="cab-empty-card">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#D7B79A" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:8px">
          <rect x="3" y="4" width="18" height="17" rx="2"/>
          <path d="M8 2v4M16 2v4M3 10h18"/>
          <path d="M9 15l2 2 4-4"/>
        </svg>
        <h2>Пока нет опросов</h2>
        <p>Когда появится новый опрос, вы увидите его здесь и получите уведомление в боте.</p>
      </div>

      <!-- ═══ СПИСОК ═══ -->
      <div v-else-if="surveyMode === 'list'" class="cab-sv-home">
        <div v-if="pendingSurveys.length" class="cab-sv-group">
          <div class="cab-sv-group-head">
            <span class="cab-sv-group-title">Нужно ответить</span>
            <span class="cab-sv-group-count">{{ pendingSurveys.length }}</span>
          </div>
          <button
            v-for="survey in pendingSurveys"
            :key="survey.id"
            class="cab-sv-bigcard pending"
            @click="openSurveyCard(survey)"
          >
            <div class="cab-sv-bigcard-icon">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 2v4M16 2v4M3 10h18"/><path d="M9 15h6"/></svg>
            </div>
            <div class="cab-sv-bigcard-body">
              <div class="cab-sv-bigcard-title">{{ survey.title }}</div>
              <div class="cab-sv-bigcard-meta">
                <span>{{ survey.questions_count }} {{ surveyQuestionPlural(survey.questions_count) }}</span>
                <span>·</span>
                <span>{{ fmtDateTime(survey.sent_at || survey.created_at) }}</span>
              </div>
            </div>
            <div class="cab-sv-bigcard-arrow">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            </div>
          </button>
        </div>

        <div v-if="answeredSurveys.length" class="cab-sv-group">
          <div class="cab-sv-group-head">
            <span class="cab-sv-group-title">Отвеченные</span>
            <span class="cab-sv-group-count muted">{{ answeredSurveys.length }}</span>
          </div>
          <button
            v-for="survey in answeredSurveys"
            :key="survey.id"
            class="cab-sv-bigcard done"
            @click="openSurveyCard(survey)"
          >
            <div class="cab-sv-bigcard-icon">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <div class="cab-sv-bigcard-body">
              <div class="cab-sv-bigcard-title">{{ survey.title }}</div>
              <div class="cab-sv-bigcard-meta">
                <span>Ответ отправлен {{ fmtDateTime(survey.submitted_at) }}</span>
              </div>
            </div>
            <div class="cab-sv-bigcard-arrow">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            </div>
          </button>
        </div>
      </div>

      <!-- ─── Загрузка деталей ─── -->
      <div v-else-if="surveyDetailLoading" class="cab-empty-card"><p>Открываю опрос...</p></div>

      <!-- ═══ МАСТЕР (идёт заполнение) ═══ -->
      <div v-else-if="surveyMode === 'wizard' && surveyDetail" class="cab-sv-wiz">
        <button class="cab-sv-back" @click="backToList">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
          К опросам
        </button>

        <div class="cab-sv-wiz-card">
          <div class="cab-sv-wiz-head">
            <div class="cab-sv-wiz-pretitle">Опрос</div>
            <h2 class="cab-sv-wiz-title">{{ surveyDetail.title }}</h2>
            <p v-if="surveyDetail.description" class="cab-sv-wiz-desc">{{ surveyDetail.description }}</p>
          </div>

          <!-- Прогресс-сегменты -->
          <div class="cab-sv-chain">
            <button
              v-for="(seg, i) in wizardSegments"
              :key="i"
              class="cab-sv-chain-seg"
              :class="{ filled: seg.filled, active: i === wizardStep, locked: !seg.reachable }"
              :disabled="!seg.reachable"
              :title="seg.label"
              @click="gotoStep(i)"
            />
          </div>
          <div class="cab-sv-chain-label">{{ wizardStepLabel }}</div>

          <!-- Контент шага -->
          <transition :name="wizardSlideName" mode="out-in">
            <div :key="wizardStep" class="cab-sv-step">
              <!-- Вопрос -->
              <div v-if="wizardIsQuestion && currentQuestion" class="cab-sv-step-q">
                <h3 class="cab-sv-step-title">{{ currentQuestion.text }}</h3>
                <div v-if="surveyQuestionType(currentQuestion) === 'scale'" class="cab-sv-scale">
                  <button
                    v-for="score in 10"
                    :key="score"
                    class="cab-sv-scale-btn"
                    :class="{ selected: Number(surveyAnswers[currentQuestion.id]) === score }"
                    :disabled="surveySubmitting"
                    @click="chooseOption(currentQuestion.id, score)"
                  >
                    {{ score }}
                  </button>
                </div>
                <textarea
                  v-else-if="surveyQuestionType(currentQuestion) === 'text'"
                  v-model="surveyAnswers[currentQuestion.id]"
                  class="cab-sv-textarea"
                  rows="5"
                  placeholder="Ваш ответ..."
                  :disabled="surveySubmitting"
                  @keydown.ctrl.enter="wizardCanNext && nextStep()"
                />
                <div v-else class="cab-sv-bigopts">
                  <button
                    v-for="option in currentQuestion.options || []"
                    :key="option.id"
                    class="cab-sv-bigopt"
                    :class="{ selected: Number(surveyAnswers[currentQuestion.id]) === Number(option.id) }"
                    :disabled="surveySubmitting"
                    @click="chooseOption(currentQuestion.id, option.id)"
                  >
                    <span class="cab-sv-bigopt-mark">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    </span>
                    <span class="cab-sv-bigopt-text">{{ option.text }}</span>
                  </button>
                </div>
              </div>

              <!-- Комментарий -->
              <div v-else-if="wizardIsComment" class="cab-sv-step-c">
                <h3 class="cab-sv-step-title">Комментарий <span class="cab-sv-optional">необязательно</span></h3>
                <p class="cab-sv-step-hint">Если хотите, добавьте пояснение к своим ответам.</p>
                <textarea
                  v-model="surveyComment"
                  class="cab-sv-textarea"
                  rows="5"
                  placeholder="Ваш комментарий..."
                  :disabled="surveySubmitting"
                  @keydown.ctrl.enter="wizardCanSubmit && submitSurveyAnswer()"
                />
              </div>
            </div>
          </transition>

          <!-- Навигация -->
          <div class="cab-sv-wiz-nav">
            <button
              class="cab-sv-nav-btn back"
              :disabled="wizardStep === 0 || surveySubmitting"
              @click="prevStep"
            >
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
              Назад
            </button>

            <button
              v-if="!wizardIsLast"
              class="cab-sv-nav-btn next"
              :disabled="!wizardCanNext || surveySubmitting"
              @click="nextStep"
            >
              Далее
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            </button>

            <button
              v-else
              class="cab-sv-nav-btn submit"
              :disabled="!wizardCanSubmit || surveySubmitting"
              @click="submitSurveyAnswer"
            >
              <span v-if="surveySubmitting" class="cab-spin cab-spin-sm"></span>
              <svg v-else width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
              Отправить ответ
            </button>
          </div>
        </div>
      </div>

      <!-- ═══ READONLY (уже ответил) ═══ -->
      <div v-else-if="surveyMode === 'readonly' && surveyDetail" class="cab-sv-ro">
        <button class="cab-sv-back" @click="backToList">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
          К опросам
        </button>

        <div class="cab-sv-ro-card">
          <div class="cab-sv-ro-head">
            <div class="cab-sv-ro-badge">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
              Ответ отправлен
            </div>
            <h2 class="cab-sv-ro-title">{{ surveyDetail.title }}</h2>
            <p v-if="surveyDetail.description" class="cab-sv-ro-desc">{{ surveyDetail.description }}</p>
            <div v-if="surveyDetail.submitted_at" class="cab-sv-ro-meta">
              {{ fmtDateTime(surveyDetail.submitted_at) }}
            </div>
          </div>

          <div class="cab-sv-ro-body">
            <div v-for="(q, i) in surveyDetail.questions || []" :key="q.id" class="cab-sv-ro-q">
              <div class="cab-sv-ro-qhead">
                <span class="cab-sv-ro-qnum">{{ i + 1 }}</span>
                <span class="cab-sv-ro-qtext">{{ q.text }}</span>
              </div>
              <div class="cab-sv-ro-opts">
                <div v-if="surveyQuestionType(q) === 'scale'" class="cab-sv-ro-text-answer">
                  Оценка: {{ surveyAnswers[q.id] || '—' }}
                </div>
                <div v-else-if="surveyQuestionType(q) === 'text'" class="cab-sv-ro-text-answer">
                  {{ surveyAnswers[q.id] || '—' }}
                </div>
                <div
                  v-else
                  v-for="opt in q.options || []"
                  :key="opt.id"
                  class="cab-sv-ro-opt"
                  :class="{ selected: Number(surveyAnswers[q.id]) === Number(opt.id) }"
                >
                  <span class="cab-sv-ro-opt-mark">
                    <svg v-if="Number(surveyAnswers[q.id]) === Number(opt.id)" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                  </span>
                  <span>{{ opt.text }}</span>
                </div>
              </div>
            </div>

            <div v-if="surveyDetail.comment" class="cab-sv-ro-comment">
              <div class="cab-sv-ro-comment-label">Ваш комментарий</div>
              <div class="cab-sv-ro-comment-value">{{ surveyDetail.comment }}</div>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══ УСПЕХ ═══ -->
      <transition name="cab-sv-fade">
        <div v-if="surveyMode === 'success'" class="cab-sv-success-screen">
          <div class="cab-sv-success-ring">
            <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="cab-sv-success-check">
              <polyline points="20 6 9 17 4 12"/>
            </svg>
          </div>
          <h2 class="cab-sv-success-title">Спасибо!</h2>
          <p class="cab-sv-success-text">Ваш ответ сохранён</p>
          <button class="btn btn-primary btn-lg cab-sv-success-btn" @click="backToList">К опросам</button>
        </div>
      </transition>
    </section>

    <!-- ══════ TAB: Сбор остатков ══════ -->
    <section v-if="activeTab === 'stock' && !globalLoading && !globalError" class="cab-section sc-section">
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
    <section v-if="activeTab === 'warehouse-stock' && !globalLoading && !globalError" class="cab-section whs-section">
      <div class="whs-panel">
        <div class="whs-head">
          <div>
            <h2>Остатки склада</h2>
            <p>{{ warehouseStockCustomer || 'Ваше юрлицо' }}<template v-if="warehouseStockUploadedAt"> · обновлено {{ fmtDateTime(warehouseStockUploadedAt) }}</template></p>
          </div>
          <button class="btn btn-outline" :disabled="warehouseStockLoading || !warehouseFilteredItems.length" @click="exportWarehouseStock">
            Excel
          </button>
        </div>

        <div class="whs-controls">
          <div class="whs-search">
            <span v-html="cabIconSvg.search"></span>
            <input v-model="warehouseSearch" type="search" placeholder="Артикул, товар, GTIN или группа аналогов" />
          </div>
        </div>

        <div class="whs-tabs" aria-label="Режимы хранения">
          <button
            v-for="tab in warehouseStorageTabs"
            :key="tab.key"
            class="whs-tab"
            :class="{ active: warehouseStorageFilter === tab.key }"
            @click="warehouseStorageFilter = tab.key"
          >
            {{ tab.label }}
            <span>{{ tab.count }}</span>
          </button>
        </div>

        <div v-if="warehouseStockLoading" class="cab-empty-card">
          <BurgerSpinner text="Загрузка..." />
        </div>
        <div v-else-if="warehouseStockError" class="cab-empty-card">
          <h2>Не удалось загрузить остатки</h2>
          <p>{{ warehouseStockError }}</p>
          <button class="btn btn-primary" @click="loadWarehouseStock">Повторить</button>
        </div>
        <div v-else-if="!warehouseStockItems.length" class="cab-empty-card">
          <h2>Нет данных</h2>
          <p>В модуле «Сроки годности» пока нет остатков для вашего юрлица.</p>
        </div>
        <div v-else-if="!warehouseFilteredItems.length" class="cab-empty-card">
          <h2>Ничего не найдено</h2>
          <p>Измените поиск или фильтр режима хранения.</p>
        </div>

        <div v-else class="whs-list">
          <div class="whs-list-head">
            <span>Номенклатура</span>
            <span>Остаток</span>
            <span>Срок годности</span>
          </div>
          <div v-for="item in warehouseFilteredItems" :key="item.key" class="whs-row" :class="{ soon: item.days_left >= 0 && item.days_left <= 7 }">
            <div class="whs-row-main">
              <div class="whs-name">
                <button class="whs-copy whs-sku" type="button" title="Скопировать артикул и товар" @click="copyWarehouseTitle(item)">
                  {{ item.sku || item.external_code || '—' }}
                </button>
                <button class="whs-copy whs-title" type="button" title="Скопировать артикул и товар" @click="copyWarehouseTitle(item)">
                  {{ item.name }}
                </button>
              </div>
              <div class="whs-meta">
                <span>{{ item.storage_label }}</span>
                <span v-if="item.analog_group">Группа: {{ item.analog_group }}</span>
                <span v-if="item.gtin">GTIN: {{ item.gtin }}</span>
                <span v-if="warehouseCopiedKey === item.key" class="whs-copied">Скопировано</span>
              </div>
            </div>
            <div class="whs-qty">
              <strong>{{ formatWarehouseQty(item.quantity) }}</strong>
            </div>
            <div class="whs-exp">
              <span :class="warehouseExpiryClass(item)">{{ warehouseExpiryText(item) }}</span>
              <button v-if="item.batches?.length > 1" class="whs-batches-btn" @click="toggleWarehouseItem(item.key)">
                {{ warehouseOpenItems[item.key] ? 'Скрыть' : `Партии ${item.batches.length}` }}
              </button>
            </div>
            <div v-if="warehouseOpenItems[item.key]" class="whs-batches">
              <div class="whs-batch whs-batch-head">
                <span>Номенклатура</span>
                <span>Склад</span>
                <span>Остаток</span>
                <span>Срок годности</span>
                <span>Статус</span>
              </div>
              <div v-for="(b, idx) in item.batches" :key="idx" class="whs-batch">
                <span class="whs-batch-name">{{ warehouseNomenclature(item) }}</span>
                <span>{{ b.warehouse || item.storage_label }}</span>
                <b>{{ formatWarehouseQty(b.quantity) }}</b>
                <span>{{ formatWarehouseDate(b.expiry_date) || '—' }}</span>
                <span>{{ b.expiry_status || '—' }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ══════ TAB: Сканер товаров (BETA) ══════ -->
    <section v-if="activeTab === 'scanner' && !globalLoading && !globalError" class="cab-section">
      <ScannerView />
    </section>

    <!-- ══════ TAB: Возврат кег ══════ -->
    <section v-if="activeTab === 'keg-returns' && !globalLoading && !globalError" class="cab-section">
      <RestaurantKegReturnsTab />
    </section>

    <!-- ══════ TAB: Профиль ══════ -->
    <section v-if="activeTab === 'profile' && !globalLoading && !globalError" class="cab-section pf-section">
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
import { useTabRoute } from '@/composables/useTabRoute.js';
import { useRestaurantOrderStore } from '@/stores/restaurantOrderStore.js';
import { useSupplierOrderStore } from '@/stores/supplierOrderStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { deadlineTimeLeftString } from '@/composables/useDeadlineCountdown.js';
import { formatDate as fmtDate, formatDateShort as fmtDateShort, formatDateTime as fmtDateTime, statusLabel } from '@/lib/roUtils.js';
import { formatRestaurantNumber } from '@/lib/legalEntities.js';
import SupplierPreviousOrder from '@/components/SupplierPreviousOrder.vue';

const ScannerView = defineAsyncComponent(() => import('@/views/restaurant/ScannerView.vue'));
const RestaurantKegReturnsTab = defineAsyncComponent(() => import('@/components/restaurant/RestaurantKegReturnsTab.vue'));

const router = useRouter();
const route = useRoute();
const roStore = useRestaurantOrderStore();
const soStore = useSupplierOrderStore();
const toast = useToastStore();

const globalLoading = ref(true);
const globalError = ref('');
const activeTab = useTabRoute('dashboard', ['dashboard', 'orders', 'stock', 'warehouse-stock', 'keg-returns', 'surveys', 'scanner', 'info', 'profile']);
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
const cabIconSvg = {
  dashboard: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20h14V9.5"/><path d="M9.5 20v-6h5v6"/></svg>',
  orders: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8l8-4 8 4-8 4-8-4Z"/><path d="M4 8v8l8 4 8-4V8"/><path d="M12 12v8"/></svg>',
  history: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 1 0 2.4-5.7"/><path d="M4 4v5h5"/><path d="M12 8v4l3 2"/></svg>',
  info: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6"/><path d="M12 7.5h.01"/></svg>',
  surveys: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14v16H5z"/><path d="M9 9h6"/><path d="M9 13h6"/><path d="M8.5 17l1.5 1.5 3-3"/></svg>',
  stock: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4h10v16H7z"/><path d="M9 8h6"/><path d="M9 12h6"/><path d="M9 16h4"/></svg>',
  warehouse: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9l9-5 9 5"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/><path d="M8 12h8"/></svg>',
  scanner: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7V5a1 1 0 0 1 1-1h2"/><path d="M17 4h2a1 1 0 0 1 1 1v2"/><path d="M20 17v2a1 1 0 0 1-1 1h-2"/><path d="M7 20H5a1 1 0 0 1-1-1v-2"/><path d="M8 8v8"/><path d="M12 8v8"/><path d="M16 8v8"/></svg>',
  search: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/></svg>',
  profile: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M5 20a7 7 0 0 1 14 0"/></svg>',
  external: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 8h8v8"/><path d="M16 8 7 17"/></svg>',
  help: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.8 9a2.4 2.4 0 0 1 4.6 1.2c0 1.8-2.4 2-2.4 3.8"/><path d="M12 17.5h.01"/></svg>',
  file: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h7l3 3v15H7z"/><path d="M14 3v4h4"/><path d="M9.5 12h5"/><path d="M9.5 16h5"/></svg>',
  check: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5 10 17l9-10"/></svg>',
  x: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7l10 10"/><path d="M17 7 7 17"/></svg>',
  skip: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M7 17 17 7"/></svg>',
  edit: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 16.5-.5 4 4-.5L18.5 9 15 5.5 4 16.5Z"/><path d="m13.5 7 3.5 3.5"/></svg>',
  truck: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
  // Возврат кег: кега + дугообразная стрелка возврата сверху (стиль сайдбара)
  kegReturn: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8 Q 4 3 12 3 Q 20 3 20 8"/><polyline points="6.5 6 4 8 6.5 10.5"/><ellipse cx="12" cy="10.5" rx="5" ry="1.5"/><path d="M7 10.5V20c0 .8 2.2 1.5 5 1.5s5-.7 5-1.5v-9.5"/><path d="M7 14c0 .8 2.2 1.5 5 1.5s5-.7 5-1.5"/><path d="M7 17.5c0 .8 2.2 1.5 5 1.5s5-.7 5-1.5"/></svg>',
};

// Иконки для крупных плиток дашборда — более «жирные» и выразительные.
const tileIconSvg = {
  scanner: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="6" width="18" height="12" rx="2.5"/><line x1="7" y1="9" x2="7" y2="15"/><line x1="10.5" y1="9" x2="10.5" y2="15"/><line x1="13.5" y1="9" x2="13.5" y2="15"/><line x1="17" y1="9" x2="17" y2="15"/><line x1="3" y1="12" x2="21" y2="12" stroke-width="2.4" stroke-linecap="round" opacity="0.55"/></svg>',
  warehouse: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11V8.5L12 4l9 4.5V11"/><path d="M4 11h16v9.5H4z"/><path d="M9 14.5h6"/><path d="M9 17.5h6"/><circle cx="17.5" cy="6.5" r="3" fill="currentColor" opacity="0.18" stroke="none"/><path d="M17.5 5v1.5l1 .8" stroke-width="1.6"/></svg>',
  keg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8 Q 4 3 12 3 Q 20 3 20 8"/><polyline points="6.5 6 4 8 6.5 10.5"/><ellipse cx="12" cy="10.5" rx="5" ry="1.5"/><path d="M7 10.5V20c0 .8 2.2 1.5 5 1.5s5-.7 5-1.5v-9.5"/><path d="M7 14c0 .8 2.2 1.5 5 1.5s5-.7 5-1.5"/><path d="M7 17.5c0 .8 2.2 1.5 5 1.5s5-.7 5-1.5"/></svg>',
  search: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="13" height="16" rx="2.5"/><path d="M6.5 8h7"/><path d="M6.5 12h5"/><circle cx="17" cy="15.5" r="3.8" fill="currentColor" fill-opacity="0.1"/><path d="M19.7 18.2 22 20.5"/></svg>',
};
const supplierIconSvg = {
  drinks: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 3h4"/><path d="M9 3v3l-2 2v11a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2V8l-2-2V3"/><path d="M7 12h6"/><path d="M16 7h2l1 3v9a2 2 0 0 1-2 2h-1"/><path d="M16 11h3"/></svg>',
  vegetables: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 14c-2.2-2.8-.4-6.5 4.5-8 0 4-1.5 6.5-4.5 8Z"/><path d="M14 14c.2-3.8 3-6.2 6.5-5.8-1 3.5-3.4 5.6-6.5 5.8Z"/><path d="M5 15h14l-1.2 3.4A4 4 0 0 1 14 21h-4a4 4 0 0 1-3.8-2.6L5 15Z"/><path d="M10 16.5h4"/></svg>',
  sauce: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3h6"/><path d="M10 3v4l-2 3v8a3 3 0 0 0 3 3h2a3 3 0 0 0 3-3v-8l-2-3V3"/><path d="M8 12h8"/><path d="M9 16h6"/><path d="M12 8v1"/></svg>',
  package: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8l8-4 8 4-8 4-8-4Z"/><path d="M4 8v8l8 4 8-4V8"/><path d="M12 12v8"/><path d="M8 6.2 16 10"/></svg>',
};
const externalOrderLinks = [
  { id: 'lidskae', name: 'Лидское пиво', url: 'https://client.lidskae.by/catalog', iconSvg: supplierIconSvg.drinks, iconClass: 'supplier-icon-drinks' },
  { id: 'salatoria', name: 'Салатория', url: 'http://salatoria.liam.by/my_zakaz/ru_RU', iconSvg: supplierIconSvg.vegetables, iconClass: 'supplier-icon-vegetables' },
];
const externalSupplierLinks = computed(() => (
  roStore.restaurant?.legal_entity_group === 'BK_VM' ? externalOrderLinks : []
));

function supplierIcon(name) {
  const n = String(name || '').toLowerCase();
  if (n.includes('камако')) return { svg: supplierIconSvg.sauce, className: 'supplier-icon-sauce' };
  if (n.includes('лидск')) return { svg: supplierIconSvg.drinks, className: 'supplier-icon-drinks' };
  if (n.includes('салатор') || n.includes('планета')) return { svg: supplierIconSvg.vegetables, className: 'supplier-icon-vegetables' };
  return { svg: supplierIconSvg.package, className: 'supplier-icon-neutral' };
}

function tabIconSvg(tabId) {
  if (tabId === 'warehouse-stock') return cabIconSvg.warehouse;
  if (tabId === 'keg-returns') return cabIconSvg.kegReturn;
  return cabIconSvg[tabId] || cabIconSvg.profile;
}

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
const warehouseSearch = ref('');
const warehouseStorageFilter = ref('all');
const warehouseOpenItems = reactive({});
const warehouseCopiedKey = ref('');
const restaurantBroadcasts = ref([]);
let restaurantBroadcastTimer = null;
const currentBroadcast = computed(() => restaurantBroadcasts.value[0] || null);
const importantPosts = ref([]);
const importantLoading = ref(false);
const importantPreviewUrls = reactive({});
const importantImagePreview = reactive({ show: false, url: '', name: '' });
const latestImportantPost = computed(() => importantPosts.value[0] || null);
const currentImportantPost = computed(() => importantPosts.value.find(p => !p.is_read && Number(p.show_popup || 0) === 1) || null);
const infoFilter = ref('all'); // all | unread
const importantPostsUnreadCount = computed(() => importantPosts.value.filter(p => !p.is_read).length);
const importantPostsFiltered = computed(() => {
  if (infoFilter.value === 'unread') return importantPosts.value.filter(p => !p.is_read);
  return importantPosts.value;
});
function infoAvatar(authorName) {
  const name = (authorName || 'Отдел закупок').trim();
  const ch = name.charAt(0).toUpperCase();
  return ch || 'З';
}
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

const warehouseBaseItems = computed(() => {
  return warehouseStockItems.value.filter(item => !(Number(item.days_left) < 0));
});

const warehouseStorageTabs = computed(() => {
  const counts = new Map();
  for (const item of warehouseBaseItems.value) {
    const key = item.storage_key || 'other';
    if (!counts.has(key)) counts.set(key, { key, label: item.storage_label || 'Без режима', count: 0 });
    counts.get(key).count++;
  }
  const preferred = ['dry', 'cold', 'frozen', 'mixed', 'other'];
  const tabs = [...counts.values()].sort((a, b) => {
    const ia = preferred.indexOf(a.key);
    const ib = preferred.indexOf(b.key);
    return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib) || a.label.localeCompare(b.label, 'ru');
  });
  return [{ key: 'all', label: 'Все', count: warehouseBaseItems.value.length }, ...tabs];
});

const warehouseFilteredItems = computed(() => {
  const q = warehouseSearch.value.trim().toLowerCase();
  return warehouseBaseItems.value.filter(item => {
    if (warehouseStorageFilter.value !== 'all' && item.storage_key !== warehouseStorageFilter.value) return false;
    if (!q) return true;
    return [
      item.sku,
      item.external_code,
      item.gtin,
      item.name,
      item.raw_name,
      item.analog_group,
      item.category,
    ].some(v => String(v || '').toLowerCase().includes(q));
  });
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
const historyFilter = ref('all');
const historyOrderModal = reactive({
  show: false,
  loading: false,
  error: '',
  order: null,
});

// ═══ Surveys ═══
const surveyItems = ref([]);
const surveyListLoading = ref(false);
const surveyDetailLoading = ref(false);
const surveySubmitting = ref(false);
const surveyError = ref('');
const surveySuccess = ref('');
const surveyDetail = ref(null);
const selectedSurveyId = ref(null);
const surveyComment = ref('');
const surveyAnswers = reactive({});
const surveyPendingCount = computed(() => surveyItems.value.filter(item => !item.already_answered).length);
const surveyTotalQuestions = computed(() => (surveyDetail.value?.questions || []).length);
const surveyAnsweredCount = computed(() => {
  const qs = surveyDetail.value?.questions || [];
  let n = 0;
  for (const q of qs) { if (surveyQuestionAnswered(q)) n++; }
  return n;
});
const surveyProgressPct = computed(() => {
  const total = surveyTotalQuestions.value;
  if (!total) return 0;
  return Math.round(surveyAnsweredCount.value * 100 / total);
});
const surveyAllAnswered = computed(() => {
  return surveyTotalQuestions.value > 0 && surveyAnsweredCount.value === surveyTotalQuestions.value;
});
function surveyQuestionPlural(n) {
  const abs = Math.abs(Number(n) || 0);
  const mod10 = abs % 10;
  const mod100 = abs % 100;
  if (mod10 === 1 && mod100 !== 11) return 'вопрос';
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return 'вопроса';
  return 'вопросов';
}

// ═══ Wizard state ═══
const surveyMode = ref('list'); // 'list' | 'wizard' | 'readonly' | 'success'
const wizardStep = ref(0);
const wizardSlideName = ref('cab-sv-slide-forward');

const pendingSurveys = computed(() => surveyItems.value.filter(s => !s.already_answered));
const answeredSurveys = computed(() => surveyItems.value.filter(s => !!s.already_answered));

const wizardTotalSteps = computed(() => surveyTotalQuestions.value + 1); // questions + comment
const wizardIsQuestion = computed(() => wizardStep.value < surveyTotalQuestions.value);
const wizardIsComment = computed(() => wizardStep.value === surveyTotalQuestions.value);
const wizardIsLast = computed(() => wizardStep.value === wizardTotalSteps.value - 1);
const currentQuestion = computed(() => {
  const qs = surveyDetail.value?.questions || [];
  return wizardIsQuestion.value ? (qs[wizardStep.value] || null) : null;
});
const wizardCanNext = computed(() => {
  if (wizardIsQuestion.value) {
    const q = currentQuestion.value;
    return !!q && surveyQuestionAnswered(q);
  }
  return true;
});
const wizardCanSubmit = computed(() => surveyAllAnswered.value);
const wizardSegments = computed(() => {
  const qs = surveyDetail.value?.questions || [];
  const segs = qs.map((q, i) => ({
    filled: surveyQuestionAnswered(q),
    reachable: true,
    label: `Вопрос ${i + 1}`,
  }));
  segs.push({
    filled: !!surveyComment.value.trim(),
    reachable: surveyAllAnswered.value,
    label: 'Комментарий',
  });
  return segs;
});
const wizardStepLabel = computed(() => {
  if (wizardIsComment.value) return `Комментарий · шаг ${wizardStep.value + 1} из ${wizardTotalSteps.value}`;
  return `Вопрос ${wizardStep.value + 1} из ${surveyTotalQuestions.value}`;
});

function surveyQuestionType(question) {
  return ['choice', 'scale', 'text'].includes(question?.type) ? question.type : 'choice';
}

function surveyQuestionAnswered(question) {
  if (!question?.id) return false;
  const value = surveyAnswers[question.id];
  const type = surveyQuestionType(question);
  if (type === 'text') return String(value || '').trim() !== '';
  if (type === 'scale') {
    const n = Number(value);
    return n >= 1 && n <= 10;
  }
  return Number(value) > 0;
}

function openSurveyCard(survey) {
  if (!survey?.id) return;
  selectedSurveyId.value = Number(survey.id);
  wizardStep.value = 0;
  wizardSlideName.value = 'cab-sv-slide-forward';
  openSurvey(survey.id).then(() => {
    if (!surveyDetail.value) return;
    surveyMode.value = surveyDetail.value.already_answered ? 'readonly' : 'wizard';
  });
}

function backToList() {
  surveyMode.value = 'list';
  wizardStep.value = 0;
  surveyError.value = '';
}

function gotoStep(i) {
  const segs = wizardSegments.value;
  if (i < 0 || i >= segs.length) return;
  if (!segs[i].reachable) return;
  wizardSlideName.value = i > wizardStep.value ? 'cab-sv-slide-forward' : 'cab-sv-slide-back';
  wizardStep.value = i;
}

function nextStep() {
  if (wizardStep.value >= wizardTotalSteps.value - 1) return;
  if (!wizardCanNext.value) return;
  wizardSlideName.value = 'cab-sv-slide-forward';
  wizardStep.value += 1;
}

function prevStep() {
  if (wizardStep.value === 0) return;
  wizardSlideName.value = 'cab-sv-slide-back';
  wizardStep.value -= 1;
}

function chooseOption(questionId, optionId) {
  surveyAnswers[questionId] = Number(optionId);
  // Auto-advance after short delay
  setTimeout(() => {
    if (!wizardIsQuestion.value) return;
    const q = currentQuestion.value;
    if (!q || Number(surveyAnswers[q.id]) !== Number(optionId)) return;
    if (wizardStep.value < wizardTotalSteps.value - 1) {
      wizardSlideName.value = 'cab-sv-slide-forward';
      wizardStep.value += 1;
    }
  }, 260);
}

// Уникальные источники для фильтра, сгруппированные по названию
const historySourceOptions = computed(() => {
  const groups = new Map(); // label -> { keys: Set, source }
  for (const o of historyOrders.value) {
    const key = o.source === 'supplier' ? 'sup_' + o.supplier_id : o.source;
    if (!groups.has(o.source_name)) {
      groups.set(o.source_name, { keys: new Set(), source: o.source });
    }
    groups.get(o.source_name).keys.add(key);
  }
  return [...groups.entries()].map(([label, g]) => ({
    label,
    keys: [...g.keys],
    source: g.source,
  }));
});

const filteredHistoryOrders = computed(() => {
  if (historyFilter.value === 'all') return historyOrders.value;
  const opt = historySourceOptions.value.find(o => o.label === historyFilter.value);
  if (!opt) return historyOrders.value;
  return historyOrders.value.filter(o => {
    const key = o.source === 'supplier' ? 'sup_' + o.supplier_id : o.source;
    return opt.keys.includes(key);
  });
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

// Сколько товаров в активном сборе остатков ещё не заполнены.
// Используется для бейджа в нижнем таббаре. 0 = бейджа нет.
// Источник: если уже загружен список stockProducts — считаем по нему;
// иначе (ещё не открывали вкладку) — берём submitted_count/total_products из статуса.
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

const kegReturnsEnabled = ref(false);
async function loadKegReturnsAvailability() {
  try {
    const t = localStorage.getItem('ro_token') || '';
    const res = await fetch('/api/keg-returns/restaurant-info', { headers: t ? { 'X-RO-Token': t } : {} });
    if (!res.ok) { kegReturnsEnabled.value = false; return; }
    const data = await res.json();
    kegReturnsEnabled.value = !!parseInt(data.pickup_weekdays || 0);
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
    localStorage.setItem(delDraftKey(delSelectedDate.value), JSON.stringify({
      items: meaningful.map(i => ({ sku: i.sku, product_name: i.product_name, category: i.category, quantity: i.quantity, comment: i.comment, multiplicity: i.multiplicity })),
      comment: delOrderComment.value || '',
      savedAt: Date.now(),
    }));
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
    if (surveyItems.value.length) {
      activeTab.value = 'surveys';
    } else {
      activeTab.value = 'dashboard';
      if (!surveyListLoading.value) loadSurveyList();
    }
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
  }
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

async function loadHistory() {
  historyLoading.value = true;
  historyError.value = '';
  try { historyOrders.value = await roStore.loadAllHistory(50); }
  catch (e) {
    historyOrders.value = [];
    historyError.value = e.message || 'Не удалось загрузить историю заказов';
  }
  finally { historyLoading.value = false; }
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

function resetSurveyDraft(detail = surveyDetail.value) {
  for (const key of Object.keys(surveyAnswers)) delete surveyAnswers[key];
  if (!detail) {
    surveyComment.value = '';
    return;
  }
  const answers = detail.answers || {};
  for (const [questionId, answer] of Object.entries(answers)) {
    if (answer && typeof answer === 'object') {
      if (answer.type === 'text') surveyAnswers[questionId] = answer.text_value || '';
      else if (answer.type === 'scale') surveyAnswers[questionId] = Number(answer.numeric_value || 0);
      else surveyAnswers[questionId] = Number(answer.option_id || 0);
    } else {
      surveyAnswers[questionId] = Number(answer);
    }
  }
  surveyComment.value = detail.comment || '';
}

async function loadSurveyList(preferredId = null) {
  surveyListLoading.value = true;
  surveyError.value = '';
  try {
    surveyItems.value = await roStore.loadSurveys();
    if (preferredId && surveyItems.value.some(item => Number(item.id) === Number(preferredId))) {
      // После отправки ответа обновляем данные выбранного опроса,
      // но остаёмся в текущем режиме (успех/readonly/список)
      await openSurvey(preferredId);
    } else if (!surveyItems.value.length) {
      selectedSurveyId.value = null;
      surveyDetail.value = null;
      resetSurveyDraft(null);
      if (activeTab.value === 'surveys') {
        activeTab.value = 'dashboard';
      }
    }
  } catch (e) {
    surveyItems.value = [];
    surveyError.value = e.message || 'Не удалось загрузить опросы';
  } finally {
    surveyListLoading.value = false;
  }
}

async function openSurvey(surveyId) {
  if (!surveyId) return;
  surveyDetailLoading.value = true;
  surveyError.value = '';
  selectedSurveyId.value = Number(surveyId);
  try {
    surveyDetail.value = await roStore.loadSurvey(surveyId);
    resetSurveyDraft(surveyDetail.value);
  } catch (e) {
    surveyDetail.value = null;
    resetSurveyDraft(null);
    surveyError.value = e.message || 'Не удалось открыть опрос';
  } finally {
    surveyDetailLoading.value = false;
  }
}

async function submitSurveyAnswer() {
  if (!surveyDetail.value?.id || surveyDetail.value.already_answered) return;
  surveyError.value = '';
  surveySuccess.value = '';

  const payload = {};
  for (const question of (surveyDetail.value.questions || [])) {
    if (!surveyQuestionAnswered(question)) {
      surveyError.value = 'Ответьте на все вопросы';
      return;
    }
    const type = surveyQuestionType(question);
    if (type === 'text') {
      payload[question.id] = { question_id: Number(question.id), type, text_value: String(surveyAnswers[question.id] || '').trim() };
    } else if (type === 'scale') {
      payload[question.id] = { question_id: Number(question.id), type, numeric_value: Number(surveyAnswers[question.id]) };
    } else {
      payload[question.id] = Number(surveyAnswers[question.id]);
    }
  }

  surveySubmitting.value = true;
  try {
    await roStore.submitSurvey(surveyDetail.value.id, payload, surveyComment.value);
    surveyMode.value = 'success';
    await loadSurveyList(surveyDetail.value.id);
  } catch (e) {
    surveyError.value = e.message || 'Не удалось сохранить ответ';
  } finally {
    surveySubmitting.value = false;
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
    if (!warehouseStorageTabs.value.some(t => t.key === warehouseStorageFilter.value)) {
      warehouseStorageFilter.value = 'all';
    }
  } catch (e) {
    warehouseStockError.value = e.message || 'Ошибка загрузки остатков';
  } finally {
    warehouseStockLoading.value = false;
  }
}

function toggleWarehouseItem(key) {
  warehouseOpenItems[key] = !warehouseOpenItems[key];
}

function formatWarehouseQty(value) {
  const n = Number(value || 0);
  return n.toLocaleString('ru-RU', { maximumFractionDigits: 2 });
}

function warehouseNomenclature(item) {
  const code = item?.sku || item?.external_code || '';
  return [code, item?.name || item?.raw_name || ''].filter(Boolean).join(' ');
}

async function copyWarehouseTitle(item) {
  const text = warehouseNomenclature(item);
  if (!text) return;
  try {
    if (navigator?.clipboard?.writeText) {
      await navigator.clipboard.writeText(text);
    } else {
      const el = document.createElement('textarea');
      el.value = text;
      el.setAttribute('readonly', '');
      el.style.position = 'fixed';
      el.style.left = '-9999px';
      document.body.appendChild(el);
      el.select();
      document.execCommand('copy');
      document.body.removeChild(el);
    }
    warehouseCopiedKey.value = item.key;
    setTimeout(() => {
      if (warehouseCopiedKey.value === item.key) warehouseCopiedKey.value = '';
    }, 1400);
  } catch {
    warehouseCopiedKey.value = '';
  }
}

function warehouseExpiryText(item) {
  if (!item?.nearest_expiry) return 'Срок не указан';
  const days = Number(item.days_left);
  const date = formatWarehouseDate(item.nearest_expiry);
  if (Number.isNaN(days)) return date;
  if (days === 0) return `${date} · сегодня`;
  return `${date} · ${days} дн.`;
}

function formatWarehouseDate(value) {
  if (!value) return '';
  const m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!m) return value;
  return `${m[3]}.${m[2]}.${m[1]}`;
}

function warehouseExpiryClass(item) {
  const days = Number(item?.days_left);
  if (Number.isNaN(days)) return '';
  if (days < 0) return 'bad';
  if (days <= 7) return 'warn';
  return 'ok';
}

async function exportWarehouseStock() {
  const mod = await import('xlsx-js-style');
  const XLSX = mod.default || mod;
  const headers = ['Номенклатура', 'Склад', 'Остаток партии', 'Срок годности', 'Статус', 'Режим хранения', 'Группа аналогов', 'GTIN', 'Внешний код'];
  const rows = [];
  for (const item of warehouseFilteredItems.value) {
    const batches = Array.isArray(item.batches) && item.batches.length ? item.batches : [{
      warehouse: item.storage_label || '',
      quantity: item.quantity || 0,
      expiry_date: item.nearest_expiry || '',
      expiry_status: item.nearest_status || '',
    }];
    for (const batch of batches) {
      rows.push([
        warehouseNomenclature(item),
        batch.warehouse || item.storage_label || '',
        Number(batch.quantity || 0),
        batch.expiry_date ? formatWarehouseDate(batch.expiry_date) : '',
        batch.expiry_status || '',
        item.storage_label || '',
        item.analog_group || '',
        item.gtin || '',
        item.external_code || '',
      ]);
    }
  }
  const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);
  ws['!cols'] = [
    { wch: 58 },
    { wch: 24 },
    { wch: 12 },
    { wch: 16 },
    { wch: 18 },
    { wch: 18 },
    { wch: 24 },
    { wch: 18 },
    { wch: 16 },
  ];
  ws['!autofilter'] = { ref: XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: rows.length, c: headers.length - 1 } }) };
  const border = {
    top: { style: 'thin', color: { rgb: 'E7DED4' } },
    bottom: { style: 'thin', color: { rgb: 'E7DED4' } },
    left: { style: 'thin', color: { rgb: 'E7DED4' } },
    right: { style: 'thin', color: { rgb: 'E7DED4' } },
  };
  for (let c = 0; c < headers.length; c++) {
    const cell = ws[XLSX.utils.encode_cell({ r: 0, c })];
    cell.s = {
      font: { bold: true, color: { rgb: 'FFFFFF' } },
      fill: { fgColor: { rgb: '502314' } },
      alignment: { vertical: 'center', horizontal: 'center', wrapText: true },
      border,
    };
  }
  for (let r = 1; r <= rows.length; r++) {
    for (let c = 0; c < headers.length; c++) {
      const addr = XLSX.utils.encode_cell({ r, c });
      if (!ws[addr]) continue;
      ws[addr].s = {
        fill: { fgColor: { rgb: r % 2 ? 'FFF8EF' : 'FFFFFF' } },
        alignment: { vertical: 'top', horizontal: c === 2 ? 'right' : 'left', wrapText: true },
        border,
      };
      if (c === 2) ws[addr].z = '#,##0.00';
    }
  }
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Остатки склада');
  XLSX.writeFile(wb, `Остатки склада ${warehouseStockCustomer.value || ''}.xlsx`);
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
  const run = async (label, task) => {
    try {
      if (runId !== cabinetBackgroundRunId) return;
      await task();
    } catch (e) {
      if (import.meta.env.DEV) console.warn(`[restaurant cabinet] ${label}:`, e);
    }
  };
  setTimeout(() => {
    run('history', () => historyOrders.value.length ? null : loadHistory());
    run('surveys', () => surveyItems.value.length ? null : loadSurveyList());
    run('stock-status', checkStockCollection);
    run('telegram', loadTgStatus);
    run('important-posts', () => importantPosts.value.length ? loadImportantImagePreviews(1) : loadImportantPostsWithOptions({ previewAll: false }));
    run('broadcasts', loadRestaurantBroadcasts);
    run('previous-orders', loadPreviousDeliveryOrders);
  }, 0);
}

async function retryCabinetLoad() {
  globalLoading.value = true;
  try {
    await loadCabinetData();
    startRestaurantBroadcastPolling();
  } catch (e) {
    globalError.value = e.message || 'Ошибка загрузки кабинета';
  } finally {
    globalLoading.value = false;
  }
}

onMounted(async () => {
  window.addEventListener('beforeunload', onBeforeUnload);
  // Если в URL есть tg_token — это переход из бота, надо переавторизоваться
  // (важно когда кликают «Через сайт» для другого ресторана)
  const tgTokenParam = route.query.tg_token;
  if (tgTokenParam) {
    const redirectQ = route.query.redirect;
    const redirectPath = (typeof redirectQ === 'string' && /^\/restaurant(\/|$)/.test(redirectQ)) ? redirectQ : null;
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
    supUpdateDeadlineTimers();
    supDeadlineTimerInterval = setInterval(supUpdateDeadlineTimers, 1000);
  } catch (e) {
    globalError.value = e.message || 'Ошибка загрузки кабинета';
  } finally { globalLoading.value = false; }
});

onUnmounted(() => {
  cabinetBackgroundRunId++;
  clearInterval(delEditTimerInterval);
  clearInterval(supDeadlineTimerInterval);
  if (restaurantBroadcastTimer) clearInterval(restaurantBroadcastTimer);
  for (const url of Object.values(importantPreviewUrls)) URL.revokeObjectURL(url);
  window.removeEventListener('beforeunload', onBeforeUnload);
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
  width: 220px; min-height: 100vh; background: #502314;
  display: flex; flex-direction: column; padding: 20px 10px;
  position: fixed; top: 0; left: 0; bottom: 0; z-index: 100;
}
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
.sb-spacer { flex: 1; }
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
  .hist-card-time { margin-left: 0; }
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
.history-list { padding: 0; }

.hist-filters { display: flex; gap: 6px; flex-wrap: wrap; padding: 4px 0 12px; }
.hist-filter-chip { padding: 6px 14px; border-radius: 20px; border: 1.5px solid #e0d5c8; background: white; font-size: 12px; font-weight: 600; color: #6b4f3a; cursor: pointer; font-family: inherit; transition: all 0.15s; }
.hist-filter-chip:hover { border-color: #502314; color: #502314; }
.hist-filter-chip.active { background: #502314; color: white; border-color: #502314; }
.hist-filter-chip.src-chip-delivery.active { background: #E76F51; border-color: #E76F51; }
.hist-filter-chip.src-chip-supplier.active { background: #2563eb; border-color: #2563eb; }
.hist-filter-chip.src-chip-planeta.active { background: #16a34a; border-color: #16a34a; }

.hist-cards { display: flex; flex-direction: column; gap: 8px; }
.hist-card { display: flex; align-items: stretch; background: white; border-radius: 14px; border: 1px solid #EDE8E3; overflow: hidden; cursor: pointer; transition: box-shadow 0.15s, border-color 0.15s; }
.hist-card:hover { box-shadow: 0 2px 12px rgba(80,35,20,0.10); border-color: #d5c8bc; }
.hist-card-left { width: 5px; flex-shrink: 0; background: #e0d5c8; }
.hist-src-delivery .hist-card-left { background: #E76F51; }
.hist-src-supplier .hist-card-left { background: #2563eb; }
.hist-src-planeta .hist-card-left { background: #16a34a; }
.hist-card-body { flex: 1; padding: 12px 14px; min-width: 0; }
.hist-card-arrow { display: flex; align-items: center; padding: 0 12px 0 4px; font-size: 20px; color: #c5b8aa; }
.hist-card-top { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; margin-bottom: 6px; }
.hist-card-date { font-weight: 700; color: #502314; font-size: 14px; }
.hist-badge { font-size: 10px; padding: 2px 8px; border-radius: 10px; font-weight: 700; white-space: nowrap; }
.src-delivery { background: #FFF0EE; color: #E76F51; }
.src-supplier { background: #EFF6FF; color: #2563eb; }
.src-planeta { background: #ECFDF5; color: #16a34a; }
.status-badge.st-submitted { background: #ECFDF5; color: #16a34a; }
.status-badge.st-locked   { background: #FEF2F2; color: #dc2626; }
.status-badge.st-draft    { background: #F5F0EB; color: #8b7355; }
.hist-card-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.hist-meta-pill { font-size: 12px; color: #6b4f3a; background: #F7F2EC; border-radius: 8px; padding: 2px 8px; font-weight: 600; }
.hist-meta-skip { font-size: 12px; color: #d97706; font-style: italic; }
.hist-card-time { font-size: 11px; color: #b0a090; margin-left: auto; }

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

/* ═══ Опросы (wizard) ═══ */
.cab-sv-section { max-width: 720px; margin: 0 auto; }
.cab-sv-optional { font-weight: 500; color: #a89a87; margin-left: 6px; font-size: 13px; }

/* ─── Список опросов ─── */
.cab-sv-home { display: flex; flex-direction: column; gap: 24px; }
.cab-sv-group { display: flex; flex-direction: column; gap: 10px; }
.cab-sv-group-head {
  display: flex; align-items: baseline; gap: 10px;
  padding: 0 4px;
}
.cab-sv-group-title {
  font-size: 13px; font-weight: 800; color: #502314;
  text-transform: uppercase; letter-spacing: .06em;
}
.cab-sv-group-count {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 22px; height: 22px; padding: 0 7px; border-radius: 999px;
  background: #FFEACE; color: #B45309;
  font-size: 11px; font-weight: 800;
}
.cab-sv-group-count.muted { background: #EEEAE5; color: #8b7355; }

.cab-sv-bigcard {
  display: flex; align-items: center; gap: 14px;
  width: 100%; padding: 18px 18px; text-align: left;
  background: white; border: 1.5px solid #EDE8E3; border-radius: 18px;
  cursor: pointer; font: inherit; color: inherit;
  transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
}
.cab-sv-bigcard:hover {
  transform: translateY(-1px);
  box-shadow: 0 6px 20px rgba(80,35,20,0.08);
  border-color: #E2CFB0;
}
.cab-sv-bigcard.pending { border-left: 4px solid #F59E0B; }
.cab-sv-bigcard.done { border-left: 4px solid #10B981; opacity: .9; }
.cab-sv-bigcard-icon {
  width: 44px; height: 44px; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  background: linear-gradient(135deg, #FFF6E7, #FAE4BF);
  color: #B45309; flex-shrink: 0;
}
.cab-sv-bigcard.done .cab-sv-bigcard-icon {
  background: linear-gradient(135deg, #E6F9EE, #BCEACB);
  color: #15803D;
}
.cab-sv-bigcard-body { flex: 1; min-width: 0; }
.cab-sv-bigcard-title {
  font-size: 15px; font-weight: 800; color: #502314;
  line-height: 1.3; word-break: break-word;
}
.cab-sv-bigcard-meta {
  display: flex; gap: 8px; flex-wrap: wrap;
  margin-top: 6px; font-size: 12px; color: #8b7355; font-weight: 500;
}
.cab-sv-bigcard-arrow {
  flex-shrink: 0; color: #C5B8AA;
  display: flex; align-items: center;
  transition: color .15s ease, transform .15s ease;
}
.cab-sv-bigcard:hover .cab-sv-bigcard-arrow {
  color: #502314; transform: translateX(2px);
}

/* ─── Кнопка «назад» ─── */
.cab-sv-back {
  display: inline-flex; align-items: center; gap: 6px;
  background: none; border: none; cursor: pointer;
  font: inherit; font-size: 13px; font-weight: 700;
  color: #6b4f3a; padding: 6px 8px; margin: 0 0 12px -8px;
  border-radius: 8px; transition: background .15s ease, color .15s ease;
}
.cab-sv-back:hover { background: #FBF6F1; color: #502314; }

/* ─── Мастер (wizard) ─── */
.cab-sv-wiz { max-width: 640px; margin: 0 auto; }
.cab-sv-wiz-card {
  background: white; border: 1px solid #EDE8E3; border-radius: 22px;
  padding: 26px 28px 22px; box-shadow: 0 2px 12px rgba(80,35,20,0.04);
}
.cab-sv-wiz-head { margin-bottom: 18px; }
.cab-sv-wiz-pretitle {
  font-size: 11px; font-weight: 800; color: #B45309;
  text-transform: uppercase; letter-spacing: .1em; margin-bottom: 6px;
}
.cab-sv-wiz-title {
  margin: 0; font-size: 22px; font-weight: 800; color: #502314;
  line-height: 1.25; letter-spacing: -0.01em; word-break: break-word;
}
.cab-sv-wiz-desc {
  margin: 10px 0 0; color: #6b4f3a; font-size: 14px;
  line-height: 1.5; white-space: pre-line;
}

/* Цепочка сегментов */
.cab-sv-chain {
  display: flex; gap: 6px; margin: 4px 0 6px;
  padding: 2px 0;
}
.cab-sv-chain-seg {
  flex: 1; height: 8px; border-radius: 4px; border: none;
  background: #F0E8DE; padding: 0; cursor: pointer;
  transition: background .2s ease, transform .2s ease;
}
.cab-sv-chain-seg.filled { background: #D08B3A; }
.cab-sv-chain-seg.active {
  background: #502314;
  transform: scaleY(1.35);
}
.cab-sv-chain-seg.locked { cursor: not-allowed; opacity: .6; }
.cab-sv-chain-label {
  font-size: 11px; font-weight: 700; color: #8b7355;
  text-transform: uppercase; letter-spacing: .08em;
  margin: 4px 0 16px;
}

/* Шаг */
.cab-sv-step { min-height: 220px; }
.cab-sv-step-title {
  margin: 0 0 14px; font-size: 17px; font-weight: 800;
  color: #502314; line-height: 1.4; word-break: break-word;
}
.cab-sv-step-hint {
  margin: -8px 0 14px; color: #8b7355; font-size: 13px;
}

/* Большие варианты */
.cab-sv-bigopts {
  display: flex; flex-direction: column; gap: 10px;
}
.cab-sv-bigopt {
  display: flex; align-items: center; gap: 14px;
  width: 100%; padding: 16px 18px; text-align: left;
  background: white; border: 2px solid #EDE8E3; border-radius: 14px;
  cursor: pointer; font: inherit;
  transition: transform .12s ease, border-color .15s ease, background .15s ease, box-shadow .15s ease;
}
.cab-sv-bigopt:hover:not(:disabled) {
  border-color: #D7B79A; background: #FFFBF5;
  transform: translateY(-1px);
}
.cab-sv-bigopt:disabled { cursor: default; opacity: .75; }
.cab-sv-bigopt-mark {
  width: 26px; height: 26px; border-radius: 50%;
  border: 2px solid #D7C4AA; background: white;
  display: inline-flex; align-items: center; justify-content: center;
  color: white; flex-shrink: 0;
  transition: .18s ease;
}
.cab-sv-bigopt-mark svg { opacity: 0; transform: scale(0.5); transition: .18s ease; }
.cab-sv-bigopt-text {
  flex: 1; font-size: 15px; font-weight: 500; color: #502314;
  line-height: 1.35; word-break: break-word;
}
.cab-sv-bigopt.selected {
  border-color: #D08B3A;
  background: linear-gradient(135deg, #FFF8EB 0%, #FBF1E0 100%);
  box-shadow: 0 4px 14px rgba(208,139,58,0.18);
}
.cab-sv-bigopt.selected .cab-sv-bigopt-mark {
  background: #D08B3A; border-color: #D08B3A;
}
.cab-sv-bigopt.selected .cab-sv-bigopt-mark svg {
  opacity: 1; transform: scale(1);
}
.cab-sv-bigopt.selected .cab-sv-bigopt-text { color: #4A2C18; font-weight: 700; }
.cab-sv-scale {
  display: grid;
  grid-template-columns: repeat(10, minmax(42px, 1fr));
  gap: 8px;
}
.cab-sv-scale-btn {
  min-height: 46px;
  border: 2px solid #EDE8E3;
  border-radius: 10px;
  background: #fff;
  color: #502314;
  font: inherit;
  font-weight: 800;
  cursor: pointer;
}
.cab-sv-scale-btn.selected {
  border-color: #D08B3A;
  background: #FFF1D7;
  color: #4A2C18;
}

/* Textarea */
.cab-sv-textarea {
  width: 100%; min-height: 120px; padding: 14px 16px;
  border: 1.5px solid #E0DBD5; border-radius: 12px;
  font: inherit; font-size: 14px; color: #502314;
  background: white; resize: vertical;
  transition: .15s ease;
}
.cab-sv-textarea:focus {
  outline: none; border-color: #D08B3A;
  box-shadow: 0 0 0 3px rgba(208,139,58,0.14);
}

/* Навигация */
.cab-sv-wiz-nav {
  display: flex; justify-content: space-between; align-items: center;
  gap: 10px; margin-top: 22px; padding-top: 18px;
  border-top: 1px solid #F5F0EB;
}
.cab-sv-nav-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 12px 20px; border-radius: 12px; border: none;
  font: inherit; font-size: 14px; font-weight: 700;
  cursor: pointer; transition: .15s ease;
}
.cab-sv-nav-btn:disabled { opacity: .4; cursor: not-allowed; }
.cab-sv-nav-btn.back {
  background: #F5F0EB; color: #6b4f3a;
}
.cab-sv-nav-btn.back:hover:not(:disabled) { background: #EAE2D8; color: #502314; }
.cab-sv-nav-btn.next {
  background: #502314; color: white;
  padding: 12px 22px;
}
.cab-sv-nav-btn.next:hover:not(:disabled) { background: #3E1A0D; }
.cab-sv-nav-btn.submit {
  background: linear-gradient(135deg, #D08B3A, #B87528);
  color: white; padding: 12px 24px;
  box-shadow: 0 4px 14px rgba(208,139,58,0.35);
}
.cab-sv-nav-btn.submit:hover:not(:disabled) {
  box-shadow: 0 6px 18px rgba(208,139,58,0.45);
  transform: translateY(-1px);
}

/* Slide переходы */
.cab-sv-slide-forward-enter-active,
.cab-sv-slide-forward-leave-active,
.cab-sv-slide-back-enter-active,
.cab-sv-slide-back-leave-active {
  transition: transform .26s ease, opacity .22s ease;
}
.cab-sv-slide-forward-enter-from { opacity: 0; transform: translateX(24px); }
.cab-sv-slide-forward-leave-to   { opacity: 0; transform: translateX(-24px); }
.cab-sv-slide-back-enter-from    { opacity: 0; transform: translateX(-24px); }
.cab-sv-slide-back-leave-to      { opacity: 0; transform: translateX(24px); }

/* ─── Readonly просмотр ─── */
.cab-sv-ro { max-width: 640px; margin: 0 auto; }
.cab-sv-ro-card {
  background: white; border: 1px solid #EDE8E3; border-radius: 22px;
  overflow: hidden; box-shadow: 0 2px 12px rgba(80,35,20,0.04);
}
.cab-sv-ro-head {
  padding: 22px 26px 18px;
  background: linear-gradient(135deg, #F0FDF4 0%, #D6F5E0 100%);
  border-bottom: 1px solid #C6EACF;
}
.cab-sv-ro-badge {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 4px 10px; border-radius: 999px;
  background: white; color: #15803D;
  font-size: 11px; font-weight: 800;
  text-transform: uppercase; letter-spacing: .04em;
  box-shadow: 0 1px 3px rgba(16,122,64,0.10);
}
.cab-sv-ro-title {
  margin: 12px 0 0; font-size: 20px; font-weight: 800;
  color: #15401E; line-height: 1.25; word-break: break-word;
}
.cab-sv-ro-desc {
  margin: 8px 0 0; color: #3a6147; font-size: 14px;
  line-height: 1.5; white-space: pre-line;
}
.cab-sv-ro-meta {
  margin-top: 10px; font-size: 12px; color: #3a6147; font-weight: 600;
}
.cab-sv-ro-body { padding: 18px 26px 22px; }
.cab-sv-ro-q {
  padding: 14px 0; border-bottom: 1px solid #F5F0EB;
}
.cab-sv-ro-q:last-of-type { border-bottom: none; }
.cab-sv-ro-qhead {
  display: flex; align-items: flex-start; gap: 10px;
  margin-bottom: 10px;
}
.cab-sv-ro-qnum {
  display: inline-flex; align-items: center; justify-content: center;
  width: 24px; height: 24px; border-radius: 50%;
  background: #502314; color: white;
  font-size: 12px; font-weight: 800; flex-shrink: 0;
}
.cab-sv-ro-qtext {
  flex: 1; font-size: 15px; font-weight: 700; color: #502314;
  line-height: 1.35; padding-top: 2px;
}
.cab-sv-ro-opts { display: flex; flex-direction: column; gap: 6px; padding-left: 34px; }
.cab-sv-ro-opt {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 12px; border-radius: 10px;
  background: #FBF6EE; color: #8b7355;
  font-size: 13px; line-height: 1.35;
}
.cab-sv-ro-opt.selected {
  background: linear-gradient(135deg, #FFF8EB 0%, #FBF1E0 100%);
  color: #4A2C18; font-weight: 700;
}
.cab-sv-ro-opt-mark {
  width: 18px; height: 18px; border-radius: 50%;
  border: 2px solid #D7C4AA; background: white;
  display: inline-flex; align-items: center; justify-content: center;
  color: white; flex-shrink: 0;
}
.cab-sv-ro-opt.selected .cab-sv-ro-opt-mark {
  background: #D08B3A; border-color: #D08B3A;
}
.cab-sv-ro-text-answer {
  padding: 10px 12px;
  border-radius: 10px;
  background: #FBF6EE;
  color: #4A2C18;
  font-size: 14px;
  line-height: 1.45;
  white-space: pre-wrap;
}
.cab-sv-ro-comment {
  margin-top: 14px; padding: 14px 16px;
  background: #FBF6EE; border-radius: 12px;
}
.cab-sv-ro-comment-label {
  font-size: 11px; font-weight: 800; color: #8b7355;
  text-transform: uppercase; letter-spacing: .06em; margin-bottom: 6px;
}
.cab-sv-ro-comment-value {
  color: #502314; font-size: 14px; line-height: 1.5; white-space: pre-wrap;
}

/* ─── Success ─── */
.cab-sv-success-screen {
  display: flex; flex-direction: column; align-items: center;
  padding: 50px 20px 40px; text-align: center;
}
.cab-sv-success-ring {
  width: 96px; height: 96px; border-radius: 50%;
  background: linear-gradient(135deg, #10B981, #059669);
  display: flex; align-items: center; justify-content: center;
  color: white;
  box-shadow: 0 12px 32px rgba(16,185,129,0.35);
  animation: cabSvPop .45s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.cab-sv-success-check {
  stroke-dasharray: 34;
  stroke-dashoffset: 34;
  animation: cabSvDraw .5s ease-out .25s forwards;
}
@keyframes cabSvPop {
  0%   { transform: scale(.4); opacity: 0; }
  60%  { transform: scale(1.12); opacity: 1; }
  100% { transform: scale(1); }
}
@keyframes cabSvDraw {
  to { stroke-dashoffset: 0; }
}
.cab-sv-success-title {
  margin: 22px 0 6px; font-size: 26px; font-weight: 800; color: #502314;
}
.cab-sv-success-text {
  margin: 0 0 22px; color: #6b4f3a; font-size: 15px;
}
.cab-sv-success-btn { min-width: 180px; }
.cab-sv-fade-enter-active, .cab-sv-fade-leave-active { transition: opacity .25s ease; }
.cab-sv-fade-enter-from, .cab-sv-fade-leave-to { opacity: 0; }

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

.whs-section { max-width: 1180px; margin-left: auto; margin-right: auto; }
.whs-panel { background: #fff; border: 1px solid #EDE8E3; border-radius: 18px; padding: 18px; }
.whs-head { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; margin-bottom: 14px; }
.whs-head h2 { margin: 0 0 4px; color: #502314; font-size: 20px; }
.whs-head p { margin: 0; color: #8b7355; font-size: 13px; }
.whs-controls { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 12px; }
.whs-search { flex: 1; min-width: 260px; display: flex; align-items: center; gap: 8px; background: #FAFAF8; border: 1px solid #EDE8E3; border-radius: 10px; padding: 0 12px; min-height: 44px; transition: border-color .16s ease, box-shadow .16s ease, background .16s ease; }
.whs-search:focus-within { background: #fff; border-color: #502314; box-shadow: 0 0 0 3px rgba(80, 35, 20, .12); }
.whs-search svg { width: 18px; height: 18px; stroke: #8b7355; }
.whs-search input { appearance: none; -webkit-appearance: none; border: 0; outline: 0; box-shadow: none; border-radius: 0; background: transparent; width: 100%; font: inherit; color: #2b1a0e; min-height: 42px; }
.whs-search input:focus { outline: 0; box-shadow: none; }
.whs-check { min-height: 44px; display: flex; align-items: center; gap: 8px; color: #5f4b38; font-size: 13px; cursor: pointer; user-select: none; }
.whs-check input { width: 16px; height: 16px; accent-color: #E76F51; }
.whs-tabs { display: flex; gap: 6px; overflow-x: auto; padding-bottom: 10px; margin-bottom: 8px; -webkit-overflow-scrolling: touch; }
.whs-tab { border: 1px solid #EDE8E3; background: #FAFAF8; color: #5f4b38; min-height: 40px; padding: 8px 12px; border-radius: 10px; font-weight: 700; font-size: 12px; cursor: pointer; white-space: nowrap; display: flex; align-items: center; gap: 8px; }
.whs-tab span { color: #9b8064; font-weight: 700; }
.whs-tab.active { background: #502314; border-color: #502314; color: #fff; }
.whs-tab.active span { color: rgba(255,255,255,0.75); }
.whs-list { display: flex; flex-direction: column; gap: 8px; }
.whs-list-head { display: grid; grid-template-columns: minmax(0, 1fr) 120px 180px; gap: 12px; align-items: center; padding: 0 12px 2px; color: #8b7355; font-size: 11px; font-weight: 800; text-transform: uppercase; }
.whs-list-head span:nth-child(2), .whs-list-head span:nth-child(3) { text-align: right; }
.whs-row { display: grid; grid-template-columns: minmax(0, 1fr) 120px 180px; gap: 12px; align-items: center; border: 1px solid #F0E8DD; border-radius: 12px; padding: 12px; background: #fff; }
.whs-row.soon { border-color: #F4A261; background: #FFF8EF; }
.whs-row-main { min-width: 0; }
.whs-name { display: flex; gap: 8px; align-items: baseline; color: #2b1a0e; font-weight: 700; line-height: 1.35; }
.whs-copy { border: 0; background: transparent; padding: 0; margin: 0; font: inherit; color: inherit; text-align: left; cursor: pointer; }
.whs-copy:hover { color: #E76F51; text-decoration: none; }
.whs-copy:focus-visible { outline: 2px solid rgba(80, 35, 20, .35); outline-offset: 2px; border-radius: 4px; }
.whs-sku { color: #E76F51; font-size: 12px; font-weight: 800; white-space: nowrap; }
.whs-title { min-width: 0; font-weight: 700; }
.whs-meta { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 5px; color: #8b7355; font-size: 12px; }
.whs-copied { color: #2e7d32; font-weight: 700; }
.whs-qty { display: flex; flex-direction: column; align-items: flex-end; gap: 2px; text-align: right; color: #2b1a0e; font-variant-numeric: tabular-nums; }
.whs-qty strong { font-size: 16px; font-weight: 700; line-height: 1.2; }
.whs-exp { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; font-size: 12px; font-weight: 700; text-align: right; }
.whs-exp .ok { color: #2e7d32; }
.whs-exp .warn { color: #ef6c00; }
.whs-exp .bad { color: #c0392b; }
.whs-batches-btn { border: 0; background: transparent; color: #E76F51; padding: 2px 0; cursor: pointer; font: inherit; font-size: 12px; font-weight: 700; }
.whs-batches { grid-column: 1 / -1; border-top: 1px solid #F0E8DD; padding-top: 10px; display: grid; gap: 0; }
.whs-batch { display: grid; grid-template-columns: minmax(260px, 1fr) minmax(150px, 220px) 92px 118px minmax(100px, 140px); gap: 12px; align-items: center; color: #5f4b38; font-size: 12px; padding: 7px 8px; border-bottom: 1px solid #F6EFE7; }
.whs-batch:last-child { border-bottom: 0; }
.whs-batch-head { color: #8b7355; font-weight: 800; background: #FAFAF8; border-radius: 8px; border-bottom: 0; margin-bottom: 2px; }
.whs-batch-name { color: #2b1a0e; font-weight: 700; }
.whs-batch-head span:nth-child(n+3) { text-align: right; }
.whs-batch b { color: #2b1a0e; text-align: right; font-variant-numeric: tabular-nums; }
.whs-batch span:nth-child(n+3) { text-align: right; font-variant-numeric: tabular-nums; }

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

/* Информация — редизайн */
.info-section { max-width: 760px; margin: 0 auto; padding-bottom: 100px; }
.info-empty {
  background: #fff; border: 1px solid #ECE3D6; border-radius: 16px;
  padding: 36px 24px; text-align: center;
}
.info-empty-icon {
  display: inline-flex; align-items: center; justify-content: center;
  width: 60px; height: 60px; border-radius: 16px;
  background: #FFF1E0; color: #C16B4D;
  margin: 0 auto 14px;
}
.info-empty h3 { margin: 0 0 6px; font-size: 17px; color: #2C1A12; }
.info-empty p { margin: 0; color: #8B7355; font-size: 13.5px; line-height: 1.5; max-width: 320px; margin: 0 auto; }

.info-filter-chips {
  display: flex; gap: 8px; margin-bottom: 14px;
  overflow-x: auto; -webkit-overflow-scrolling: touch; padding-bottom: 2px;
}
.info-fchip {
  display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0;
  padding: 7px 12px; border: 1.5px solid #E8DCC8; background: #FFFBF6;
  color: #6B5344; border-radius: 999px; cursor: pointer; font: inherit;
  font-size: 13px; font-weight: 600; transition: all .15s;
}
.info-fchip:hover:not(.active) { border-color: #E76F51; }
.info-fchip.active { background: #E76F51; color: #fff; border-color: #E76F51; }
.info-fchip-count {
  display: inline-block; min-width: 18px; padding: 1px 7px;
  border-radius: 10px; background: rgba(0,0,0,.08);
  font-size: 11px; font-weight: 700;
}
.info-fchip.active .info-fchip-count { background: rgba(255,255,255,.25); }

.info-list { display: flex; flex-direction: column; gap: 12px; }
.info-card {
  background: #fff; border: 1.5px solid #ECE3D6; border-radius: 14px;
  padding: 16px 18px;
  transition: border-color .15s, box-shadow .15s;
}
.info-card.unread {
  border-color: #F4A261;
  box-shadow: 0 4px 14px rgba(244,162,97,.15);
  background: linear-gradient(to bottom right, #FFF8F0, #FFFFFF 60%);
}
.info-card-head {
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; margin-bottom: 10px;
}
.info-card-author { display: flex; align-items: center; gap: 12px; min-width: 0; }
.info-card-avatar {
  width: 38px; height: 38px; border-radius: 50%;
  background: linear-gradient(135deg, #502314, #6B321F);
  color: #fff; font-size: 15px; font-weight: 700;
  display: inline-flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.info-card.unread .info-card-avatar {
  background: linear-gradient(135deg, #E76F51, #F4A261);
}
.info-card-author-info { min-width: 0; }
.info-card-author-name { font-size: 13.5px; font-weight: 700; color: #2C1A12; line-height: 1.2; }
.info-card-time { font-size: 11.5px; color: #8B7355; line-height: 1.3; display: block; margin-top: 2px; }
.info-card-dot {
  flex-shrink: 0;
  width: 9px; height: 9px; border-radius: 50%;
  background: #E76F51;
  box-shadow: 0 0 0 4px rgba(231,111,81,.2);
}
.info-card-title {
  margin: 0 0 8px; font-size: 16px; font-weight: 700; color: #2C1A12;
  line-height: 1.35;
}
.info-card-message {
  margin: 0; color: #4B3527; font-size: 14.5px; line-height: 1.55;
  white-space: pre-line;
}

.info-card-files {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 8px; margin-top: 14px;
}
.info-file {
  display: flex; align-items: center; gap: 10px;
  background: #FAFAF8; border: 1px solid #EDE8E3; border-radius: 10px;
  padding: 8px 10px; cursor: pointer; font: inherit; color: #2C1A12;
  text-align: left; min-width: 0;
  transition: border-color .15s, background .15s;
}
.info-file:hover { border-color: #E76F51; background: #FFF8F0; }
.info-file.image {
  flex-direction: column; align-items: stretch; gap: 0; padding: 0;
  overflow: hidden;
}
.info-file-img {
  width: 100%; aspect-ratio: 4 / 3; object-fit: cover; display: block;
  background: #F4ECE4;
}
.info-file.image .info-file-meta { padding: 8px 10px; }
.info-file-ico {
  width: 36px; height: 36px; flex-shrink: 0;
  display: inline-flex; align-items: center; justify-content: center;
  border-radius: 8px; background: #FFF1E0; color: #C16B4D;
}
.info-file-ico svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
.info-file-meta { display: flex; flex-direction: column; min-width: 0; flex: 1; gap: 2px; }
.info-file-name {
  font-size: 13px; font-weight: 600; color: #2C1A12;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.info-file-size { font-size: 11.5px; color: #8B7355; }

.info-card-foot {
  margin-top: 14px; padding-top: 12px; border-top: 1px solid #F2EDE8;
}
.info-read {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 14px; border: 1.5px solid #E8DCC8; border-radius: 999px;
  background: #FFFBF6; color: #C16B4D; font: inherit;
  font-size: 13px; font-weight: 600; cursor: pointer;
  transition: all .15s;
}
.info-read:hover { background: #E76F51; color: #fff; border-color: #E76F51; }

@media (max-width: 640px) {
  .info-card { padding: 14px; border-radius: 12px; }
  .info-card-files { grid-template-columns: 1fr 1fr; gap: 6px; }
  .info-empty { padding: 28px 16px; }
}
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
