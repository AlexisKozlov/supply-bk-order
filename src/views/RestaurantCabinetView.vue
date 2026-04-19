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
        <span class="sb-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></span>
        Главная
      </button>

      <div class="sb-label">Заказы</div>
      <!-- Основная поставка -->
      <button class="sb-item" :class="{ active: activeTab === 'orders' && orderSubTab === 'delivery' }"
        @click="switchTab('orders', 'delivery')">
        <span class="sb-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 3v5a2 2 0 01-2 2h-1"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></span>
        Основная поставка
        <span v-if="deliveryBadge" class="sb-badge" :class="deliveryBadge.type">{{ deliveryBadge.text }}</span>
      </button>
      <!-- Поставщики (Камако и др.) -->
      <button v-for="sup in suppliers" :key="'sb-'+sup.id" class="sb-item"
        :class="{ active: activeTab === 'orders' && orderSubTab === 'sup_' + sup.id }"
        @click="switchTab('orders', 'sup_' + sup.id)">
        <span class="sb-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg></span>
        {{ sup.name }}
        <span v-if="supplierBadge(sup)" class="sb-badge" :class="supplierBadge(sup).type">{{ supplierBadge(sup).text }}</span>
      </button>
      <!-- История заказов -->
      <button class="sb-item"
        :class="{ active: activeTab === 'orders' && orderSubTab === 'history' }"
        @click="switchTab('orders', 'history')">
        <span class="sb-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 7v5l3 2"/></svg></span>
        История
      </button>

      <div class="sb-label">Другое</div>
      <template v-for="tab in mainTabs.filter(t => t.id !== 'dashboard' && t.id !== 'orders')" :key="tab.id">
        <button class="sb-item" :class="{ active: activeTab === tab.id }" @click="switchTab(tab.id)">
          <span class="sb-icon">
            <svg v-if="tab.id === 'surveys'" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
            <svg v-else-if="tab.id === 'stock'" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
          </span>
          {{ tab.label }}
          <span v-if="tab.badge" class="sb-badge" :class="tab.badgeType">{{ tab.badge }}</span>
        </button>
      </template>
      <router-link v-if="canUseCardSearch" :to="{ name: 'search-cards' }" target="_blank" class="sb-item sb-item-link">
        <span class="sb-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg></span>
        Поиск карточек
        <span class="sb-item-ext" title="Откроется в новой вкладке">↗</span>
      </router-link>

      <div class="sb-spacer"></div>
      <a href="https://t.me/alexiskozlov" target="_blank" rel="noopener noreferrer" class="sb-help">
        <span class="sb-help-icon">?</span>
        <span>Помощь</span>
        <span class="sb-item-ext" title="Откроется в Telegram">↗</span>
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
      <div class="cab-topbar">
        <div>
          <div class="cab-topbar-title">{{ activeTab === 'dashboard' ? 'Главная' : activeTab === 'orders' ? 'Заказы' : activeTab === 'surveys' ? 'Опросы' : activeTab === 'stock' ? 'Остатки' : 'Профиль' }}</div>
          <div class="cab-topbar-sub">Ресторан {{ formatRestaurantNumber(roStore.restaurant?.number, roStore.restaurant?.legal_entity_group) }} · {{ restaurantAddress }}</div>
        </div>
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
        <div class="dash-stat" v-if="stockCollection.active" @click="switchTab('stock')">
          <div class="dash-stat-num dash-stat-alert">!</div>
          <div class="dash-stat-label">Сбор остатков</div>
        </div>
      </div>

      <!-- Быстрые действия -->
      <div class="dash-actions">
        <h3 class="dash-section-title">Быстрые действия</h3>
        <div class="dash-action-grid">
          <button class="dash-action" @click="switchTab('orders')">
            <span class="dash-action-icon">&#128230;</span>
            <span>Заказы</span>
          </button>
          <a v-if="canUseCardSearch" class="dash-action" href="/search-cards" target="_blank">
            <span class="dash-action-icon">&#128269;</span>
            <span>Карточки</span>
          </a>
          <button class="dash-action" @click="switchTab('profile')">
            <span class="dash-action-icon">&#9881;</span>
            <span>Профиль</span>
          </button>
          <button v-if="stockCollection.active" class="dash-action dash-action--alert" @click="switchTab('stock')">
            <span class="dash-action-icon">&#128203;</span>
            <span>Остатки</span>
          </button>
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
    </section>

    <!-- ══════ TAB: Заказы ══════ -->
    <section v-if="activeTab === 'orders' && !globalLoading && !globalError" class="cab-section cab-section-orders">
      <!-- ── Основная поставка ── -->
      <div v-if="orderSubTab === 'delivery'">
        <div v-if="!roStore.sessionInfo" class="cab-empty-card">
          <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#B0A090" stroke-width="1.5" stroke-linecap="round" style="margin:0 auto 16px; display:block"><rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 3v5a2 2 0 01-2 2h-1"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
          <h2>Основная поставка</h2>
          <p>Сейчас приём заявок закрыт. Закупщик ещё не открыл сессию на эту неделю.</p>
          <p style="margin-top:10px; font-size:12px; color:#B0A090">Обратитесь в отдел закупок для уточнения</p>
        </div>

        <div v-else-if="delShowSuccess" class="cab-success">
          <div class="cab-success-inner">
            <div class="cab-success-check">&#10003;</div>
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
              <span v-if="day.order?.status === 'submitted' || day.order?.status === 'edited'" class="day-tab-mark done">&#10003;</span>
              <span v-else-if="day.deadline_status === 'closed' || day.deadline_status === 'not_open'" class="day-tab-mark closed">&#10005;</span>
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
              <button v-for="cat in delCategories" :key="cat" class="cat-tab" :class="{ active: delActiveCategory === cat }" @click="delActiveCategory = cat">
                {{ cat }}
                <span v-if="delGetCategoryItemCount(cat)" class="cat-count">{{ delGetCategoryItemCount(cat) }}</span>
              </button>
            </div>

            <div class="search-row">
              <input v-model="delSearchQuery" type="text" placeholder="Поиск по названию или артикулу..." class="input-search" />
              <button v-if="delSearchQuery" class="search-clear" @click="delSearchQuery = ''">&times;</button>
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
                    <th class="del-th-mult">Кратность</th>
                    <th class="del-th-qty">Кол-во</th>
                    <th class="del-th-act"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in delFilteredItems" :key="item.sku" :class="{ 'del-filled': item.quantity > 0, 'del-err': item._multError }">
                    <td class="del-td-name">
                      <span class="del-sku">{{ item.sku }}</span> {{ item.product_name }}
                    </td>
                    <td class="del-td-mult">
                      <span v-if="item.multiplicity > 1" class="del-mult">x{{ item.multiplicity }}</span>
                    </td>
                    <td class="del-td-qty">
                      <input v-model.number="item.quantity" type="number" inputmode="decimal" min="0"
                        :step="item.multiplicity > 1 ? item.multiplicity : 1"
                        class="del-qty" :class="{ 'del-qty-err': item._multError }"
                        :disabled="!delCanSubmit && !delCanEdit" placeholder="0"
                        @input="delCheckMultiplicity(item)" @focus="$event.target.select()" />
                      <div v-if="item._multError" class="del-mult-hint">Кратность {{ item.multiplicity }}</div>
                    </td>
                    <td class="del-td-act">
                      <button v-if="item._added" class="btn-icon-danger" @click="delRemoveItem(item)">&times;</button>
                    </td>
                  </tr>
                </tbody>
              </table>
              <div v-else-if="delSearchQuery && !delProductsLoading" class="empty-msg">Ничего не найдено</div>
              <div v-else-if="!delProductsLoading" class="empty-msg">Нет товаров в категории «{{ delActiveCategory }}»</div>
              <div v-if="delProductsLoading" class="mini-loader"><div class="cab-spin"></div></div>
            </div>

            <div class="submit-area">
              <div v-if="delHasMultErrors" class="error-msg">Исправьте количество — некоторые товары заказаны не кратно</div>
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
                <span v-if="d.order?.is_skip" class="day-tab-mark skipped" title="Поставка не нужна">&#128683;</span>
                <span v-else-if="d.order" class="day-tab-mark done">&#10003;</span>
                <span v-else-if="d.deadline_status === 'closed'" class="day-tab-mark closed">&#10005;</span>
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
                <div v-if="supPreviousOrders[sup.id] && (!supCurrentDateInfo(sup)?.order || supCurrentDateInfo(sup)?.order?.status === 'draft')" class="sup-prev-order-block">
                  <div class="sup-prev-order-head" @click="supShowPreviousOrder[sup.id] = !supShowPreviousOrder[sup.id]">
                    <span>📋 Ваша предыдущая заявка от {{ fmtDate(supPreviousOrders[sup.id].delivery_date) }} — {{ supPreviousOrders[sup.id].items?.length || 0 }} поз.</span>
                    <span class="sup-prev-order-toggle">{{ supShowPreviousOrder[sup.id] ? '▲ скрыть' : '▼ показать' }}</span>
                  </div>
                  <div v-if="supShowPreviousOrder[sup.id]" class="sup-prev-order-body">
                    <div v-for="it in supPreviousOrders[sup.id].items" :key="it.sku" class="sup-prev-order-row">
                      <span class="sup-prev-name">{{ it.product_name }}</span>
                      <span class="sup-prev-qty">{{ supFmtNum(it.quantity) }}</span>
                    </div>
                  </div>
                  <div v-if="supCurrentDateInfo(sup)?.deadline_status === 'open' && supPreviousOrders[sup.id].items?.length" class="sup-prev-order-actions">
                    <button type="button" class="sup-prev-repeat-btn" @click="supHandleRepeatPrevious(sup)">
                      ↺ Повторить предыдущую заявку
                    </button>
                  </div>
                </div>
                <div v-if="supIsSkipOrder[sup.id]" class="sup-skip-banner">
                  <span class="sup-skip-icon">🚫</span>
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
                        :title="`Изменено закупщиком: было ${supFmtNum(supAdminEditInfo(sup.id, p.sku).original)}, стало ${supFmtNum(supAdminEditInfo(sup.id, p.sku).edited)}`">
                        ✏ {{ supFmtNum(supAdminEditInfo(sup.id, p.sku).original) }} → {{ supFmtNum(supAdminEditInfo(sup.id, p.sku).edited) }}
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
          <div v-if="historyOrderModal.loading" class="cab-empty-card"><p>Загрузка…</p></div>
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
                    <span class="hist-edited-mark" title="Изменено закупщиком">✏</span>
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
        <p>Загрузка опросов...</p>
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
                <div class="cab-sv-bigopts">
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
                <div
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
    <section v-if="activeTab === 'stock' && !globalLoading && !globalError" class="cab-section">
      <div v-if="stockLoading" class="cab-empty-card">
        <p>Загрузка…</p>
      </div>
      <div v-else-if="!stockCollection.active" class="cab-empty-card">
        <h2>Нет активного сбора</h2>
        <p>Сейчас сбор остатков не проводится.</p>
      </div>
      <div v-else class="stock-inline">
        <div class="stock-inline-head">
          <h2>{{ stockCollection.collection?.name }}</h2>
          <p v-if="stockLastSubmittedAt" class="stock-inline-sub">
            Последнее сохранение: {{ fmtDateTime(stockLastSubmittedAt) }}
          </p>
          <p v-else class="stock-inline-sub">Заполните остатки по всем позициям и нажмите «Сохранить».</p>
        </div>
        <div v-if="!stockProducts.length" class="cab-empty-card">
          <p>В сборе пока нет товаров.</p>
        </div>
        <div v-else class="stock-inline-list">
          <div v-for="p in stockProducts" :key="p.id" class="stock-row">
            <div class="stock-row-main">
              <div class="stock-row-name">{{ p.product_name }}</div>
              <div v-if="p.note" class="stock-row-note">{{ p.note }}</div>
            </div>
            <div class="stock-row-input">
              <input
                type="number"
                inputmode="decimal"
                min="0"
                step="any"
                v-model="stockValues[p.id]"
                class="stock-input"
                placeholder="0"
              />
              <span class="stock-row-unit">{{ stockUnitShort(p.unit) }}</span>
            </div>
          </div>
        </div>
        <div v-if="stockError" class="error-msg">{{ stockError }}</div>
        <div class="stock-inline-actions">
          <button
            class="btn btn-primary btn-lg"
            :disabled="stockSaving || !stockProducts.length || !stockDirty"
            @click="submitStockInline"
          >
            <span v-if="stockSaving" class="cab-spin cab-spin-sm"></span>
            {{ stockLastSubmittedAt ? 'Сохранить изменения' : 'Сохранить' }}
          </button>
          <span v-if="stockSavedFlash" class="stock-saved-flash">Сохранено ✓</span>
        </div>
      </div>
    </section>

    <!-- ══════ TAB: Профиль ══════ -->
    <section v-if="activeTab === 'profile' && !globalLoading && !globalError" class="cab-section">
      <div class="profile-card">
        <div class="profile-header">
          <div class="profile-avatar">{{ formatRestaurantNumber(roStore.restaurant?.number, roStore.restaurant?.legal_entity_group) }}</div>
          <div>
            <h2>Ресторан {{ formatRestaurantNumber(roStore.restaurant?.number, roStore.restaurant?.legal_entity_group) }}</h2>
            <p>{{ restaurantAddress }}</p>
            <p class="profile-le">{{ roStore.restaurant?.legal_entity }}</p>
          </div>
        </div>
      </div>

      <!-- Telegram -->
      <div class="profile-block">
        <h3>Telegram</h3>
        <div v-if="tgStatus.linked" class="profile-tg-linked">
          <div class="profile-tg-ok">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            Telegram подключён
          </div>
          <button class="btn btn-sm btn-danger-outline" @click="tgUnlink">Отключить</button>
        </div>
        <div v-else class="profile-tg-unlinked">
          <p>Подключите Telegram для уведомлений о дедлайнах и быстрого входа.</p>
          <div v-if="tgError" class="error-msg">{{ tgError }}</div>
          <div v-if="tgLinkCode" class="tg-code-box">
            <p>Отправьте этот код боту <a href="https://t.me/supplyportal_bot" target="_blank">@supplyportal_bot</a>:</p>
            <div class="tg-code">{{ tgLinkCode }}</div>
            <p class="tg-code-hint">Код действует 10 минут</p>
          </div>
          <button v-else class="btn btn-primary" @click="tgGetCode" :disabled="tgLinkLoading">
            <span v-if="tgLinkLoading" class="cab-spin cab-spin-sm"></span>
            Получить код привязки
          </button>
        </div>
      </div>

      <!-- Смена пароля -->
      <div class="profile-block">
        <h3>Смена пароля</h3>
        <form @submit.prevent="changePassword" class="pw-form">
          <input v-model="pwOld" type="password" placeholder="Текущий пароль" class="input-field" autocomplete="current-password" />
          <input v-model="pwNew" type="password" placeholder="Новый пароль" class="input-field" autocomplete="new-password" />
          <input v-model="pwConfirm" type="password" placeholder="Повтор нового пароля" class="input-field" autocomplete="new-password" />
          <div v-if="pwError" class="error-msg">{{ pwError }}</div>
          <div v-if="pwSuccess" class="success-msg">Пароль изменён</div>
          <button type="submit" class="btn btn-primary" :disabled="pwLoading || !pwOld || !pwNew">
            <span v-if="pwLoading" class="cab-spin cab-spin-sm"></span>
            Сменить пароль
          </button>
        </form>
      </div>

      <!-- Контакты -->
      <div class="profile-block">
        <h3>Контакты отдела закупок</h3>
        <a href="https://t.me/supplyportal_bot" target="_blank" class="contact-link">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 3L1 11l8 2m12-10l-8 18-4-8m12-10l-12 10"/></svg>
          @supplyportal_bot
        </a>
      </div>

      <button class="btn btn-danger-outline btn-lg logout-full" @click="handleLogout">Выйти из аккаунта</button>
    </section>

    <div v-if="currentBroadcast" class="modal-overlay" @click.self="dismissCurrentBroadcast">
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
        <div class="cab-success-check" :class="{ 'cab-success-check-skip': supSuccessInfo.skipped }">
          <template v-if="supSuccessInfo.skipped">&#10005;</template>
          <template v-else>&#10003;</template>
        </div>
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
      <button v-for="tab in mainTabs" :key="tab.id" class="mob-tab" :class="{ active: activeTab === tab.id }" @click="switchTab(tab.id)">
        <span class="mob-tab-icon">{{ tab.id === 'dashboard' ? '\u{1F3E0}' : tab.id === 'orders' ? '\u{1F4E6}' : tab.id === 'surveys' ? '\u{2705}' : tab.id === 'stock' ? '\u{1F4CB}' : '\u{2699}' }}</span>
        <span class="mob-tab-label">{{ tab.label }}</span>
        <span v-if="tab.badge" class="mob-tab-badge">{{ tab.badge }}</span>
      </button>
      <button class="mob-tab" :class="{ active: activeTab === 'profile' }" @click="switchTab('profile')">
        <span class="mob-tab-icon">&#128100;</span>
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
import { ref, reactive, computed, onMounted, onUnmounted, nextTick, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useRestaurantOrderStore } from '@/stores/restaurantOrderStore.js';
import { useSupplierOrderStore } from '@/stores/supplierOrderStore.js';
import { deadlineTimeLeftString } from '@/composables/useDeadlineCountdown.js';
import { formatDate as fmtDate, formatDateShort as fmtDateShort, formatDateTime as fmtDateTime, statusLabel } from '@/lib/roUtils.js';
import { formatRestaurantNumber } from '@/lib/legalEntities.js';

const router = useRouter();
const route = useRoute();
const roStore = useRestaurantOrderStore();
const soStore = useSupplierOrderStore();

const globalLoading = ref(true);
const globalError = ref('');
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

// ═══ Stock collection ═══
const stockCollection = reactive({ active: false, collection: null });
const stockProducts = ref([]);
const stockValues = reactive({}); // product_id -> string/number
const stockLastSubmittedAt = ref(null);
const stockLoading = ref(false);
const stockSaving = ref(false);
const stockError = ref('');
const stockSavedFlash = ref(false);
const restaurantBroadcasts = ref([]);
let restaurantBroadcastTimer = null;
const currentBroadcast = computed(() => restaurantBroadcasts.value[0] || null);
const stockDirty = computed(() => {
  for (const p of stockProducts.value) {
    const saved = stockSavedSnapshot[p.id];
    const current = stockValues[p.id];
    if ((saved ?? '') !== (current ?? '')) return true;
  }
  return false;
});
const stockSavedSnapshot = reactive({}); // последние сохранённые значения

// ═══ Telegram ═══
const tgStatus = reactive({ linked: false, chat_id: null });
const tgLinkCode = ref('');
const tgLinkLoading = ref(false);
const tgError = ref('');

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
  for (const q of qs) { if (Number(surveyAnswers[q.id]) > 0) n++; }
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
    return !!q && Number(surveyAnswers[q.id]) > 0;
  }
  return true;
});
const wizardCanSubmit = computed(() => surveyAllAnswered.value);
const wizardSegments = computed(() => {
  const qs = surveyDetail.value?.questions || [];
  const segs = qs.map((q, i) => ({
    filled: Number(surveyAnswers[q.id]) > 0,
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
// (чтобы «Планета Ресторанов» из veg_orders и из so_orders давала один чип)
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
  // Основная поставка
  let total = roStore.deliveryDays.filter(d => d.order?.status === 'submitted' || d.order?.status === 'edited').length;
  // Поставщики (Камако и др.)
  for (const sup of suppliers.value) {
    total += (sup.available_dates || []).filter(d => !!d.order).length;
  }
  return total;
});
const dashOrdersPending = computed(() => {
  // Основная поставка: открытые дни без заявки
  let total = roStore.deliveryDays.filter(d => d.deadline_status !== 'closed' && d.deadline_status !== 'not_open' && !d.order).length;
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
  // Delivery deadlines
  const openDays = roStore.deliveryDays.filter(d => (d.deadline_status === 'open' || d.deadline_status === 'warning') && !d.order);
  if (openDays.length) {
    items.push({
      key: 'del', type: 'warn',
      icon: '&#128230;', title: `Основная поставка: ${openDays.length} дн. без заявки`,
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
        icon: '&#128230;', title: `${sup.name}: ${openDates.length} дн. без заявки`,
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
      icon: '&#128203;', title: 'Сбор остатков',
      subtitle: stockCollection.collection?.name || 'Нужно заполнить',
      deadline: '9999-12-31 23:59',
      action: () => switchTab('stock'),
    });
  }
  items.sort((a, b) => String(a.deadline).localeCompare(String(b.deadline)));
  return items;
});

// ═══ Tabs ═══
const mainTabs = computed(() => {
  const tabs = [
    { id: 'dashboard', label: 'Главная' },
    { id: 'orders', label: 'Заказы', badge: dashOrdersPending.value || null, badgeType: dashOrdersPending.value ? 'warn' : '' },
  ];
  if (surveyItems.value.length) {
    tabs.push({ id: 'surveys', label: 'Опросы', badge: surveyPendingCount.value || null, badgeType: surveyPendingCount.value ? 'warn' : '' });
  }
  if (stockCollection.active) {
    tabs.push({
      id: 'stock', label: 'Остатки',
      blink: !stockCollection.collection?.submitted,
      badge: '!', badgeType: 'alert',
    });
  }
  return tabs;
});
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
const delFilteredItems = computed(() => {
  let items = delOrderItems.value.filter(i => i.category === delActiveCategory.value);
  if (delSearchQuery.value) {
    const q = delSearchQuery.value.toLowerCase();
    items = items.filter(i => i.product_name.toLowerCase().includes(q) || i.sku.toLowerCase().includes(q));
  }
  return items;
});
const delTotalItems = computed(() => delOrderItems.value.filter(i => i.quantity > 0).length);
const delTotalQty = computed(() => delOrderItems.value.reduce((s, i) => s + (parseFloat(i.quantity) || 0), 0));
const delHasMultErrors = computed(() => delOrderItems.value.some(i => i._multError && i.quantity > 0));
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
function delRefreshMultiplicityErrors() { for (const item of delOrderItems.value) delCheckMultiplicity(item); }

async function delSelectDay(date) {
  const requestId = ++delSelectRequestId;
  delSelectedDate.value = date;
  delExistingOrder.value = null;
  delSubmitError.value = '';
  delSearchQuery.value = '';
  delActiveCategory.value = 'Сухой';
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
  return `bk_ro_draft_${rn}_${date}`;
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
  delSubmitting.value = true; delSubmitError.value = '';
  try {
    const items = delOrderItems.value.filter(i => i.quantity > 0).map(i => ({
      sku: i.sku,
      product_name: i.product_name,
      category: i.category,
      quantity: i.quantity,
      comment: i.comment || '',
    }));
    if (!items.length) { delSubmitError.value = 'Добавьте хотя бы одну позицию'; return; }
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

function delStartEditTimer() { clearInterval(delEditTimerInterval); delUpdateEditTimeLeft(); delEditTimerInterval = setInterval(delUpdateEditTimeLeft, 1000); }
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
  const now = new Date();
  if (now >= dlMinsk) { delEditTimeLeft.value = ''; clearInterval(delEditTimerInterval); return; }
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
const supAdminEdits = reactive({}); // { supId: { sku: { original, edited } } } — правки закупщика
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
          // Эффективное значение: правка закупщика, если есть, иначе исходное
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
  const requestedSub = (tab === 'orders') ? (subTab || orderSubTab.value) : null;
  const nextSub = requestedSub;

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
    orderSubTab.value = nextSub || 'delivery';
  } else if (subTab) {
    orderSubTab.value = subTab;
  }
  if (tab === 'orders') {
    const sub = nextSub || orderSubTab.value;
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
  if (tab === 'stock' && stockCollection.active) loadStockInline();
}
// ═══ Синхронизация табов с роутом (URL) ═══
function applyRouteToState() {
  const name = route.name;
  if (!name) return;
  if (name === 'restaurant-dashboard') {
    activeTab.value = 'dashboard';
  } else if (name === 'restaurant-orders-tab' || name === 'restaurant-orders-delivery') {
    activeTab.value = 'orders';
    orderSubTab.value = 'delivery';
  } else if (name === 'restaurant-orders-planeta') {
    activeTab.value = 'orders';
    orderSubTab.value = 'delivery';
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
  } else if (name === 'restaurant-surveys') {
    if (surveyItems.value.length) {
      activeTab.value = 'surveys';
    } else {
      activeTab.value = 'dashboard';
      if (!surveyListLoading.value) loadSurveyList();
    }
  } else if (name === 'restaurant-stock') {
    activeTab.value = 'stock';
  } else if (name === 'restaurant-profile') {
    activeTab.value = 'profile';
  }
}

function syncStateToRoute() {
  let target = null;
  if (activeTab.value === 'dashboard') {
    target = { name: 'restaurant-dashboard' };
  } else if (activeTab.value === 'surveys') {
    target = { name: 'restaurant-surveys' };
  } else if (activeTab.value === 'stock') {
    target = { name: 'restaurant-stock' };
  } else if (activeTab.value === 'profile') {
    target = { name: 'restaurant-profile' };
  } else if (activeTab.value === 'orders') {
    const sub = orderSubTab.value;
    if (sub === 'delivery') target = { name: 'restaurant-orders-delivery' };
    else if (sub === 'history') target = { name: 'restaurant-orders-history' };
    else if (sub && sub.startsWith('sup_')) {
      target = { name: 'restaurant-orders-supplier', params: { supplierId: sub.slice(4) } };
    } else {
      target = { name: 'restaurant-orders-delivery' };
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
  for (const [questionId, optionId] of Object.entries(answers)) {
    surveyAnswers[questionId] = Number(optionId);
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
    const selected = Number(surveyAnswers[question.id] || 0);
    if (!selected) {
      surveyError.value = 'Ответьте на все вопросы';
      return;
    }
    payload[question.id] = selected;
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
  if (pwNew.value.length < 4) { pwError.value = 'Минимум 4 символа'; return; }
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
    const data = await roStore.getTelegramStatus();
    tgStatus.linked = data.linked; tgStatus.chat_id = data.chat_id;
  } catch (e) {
    tgError.value = e.message || 'Не удалось получить статус Telegram';
  }
}

async function loadRestaurantBroadcasts() {
  try {
    restaurantBroadcasts.value = await roStore.loadBroadcasts();
  } catch (e) {
    console.warn('[restaurant cabinet] broadcasts:', e);
  }
}

async function dismissCurrentBroadcast() {
  const current = currentBroadcast.value;
  if (!current?.id) return;
  try {
    await roStore.markBroadcastRead([current.id]);
  } catch (e) {
    console.warn('[restaurant cabinet] broadcast-read:', e);
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
    if (data.already_linked) { tgStatus.linked = true; return; }
    if (data.code) tgLinkCode.value = data.code;
  } catch (e) {
    tgError.value = e.message || 'Не удалось получить код привязки';
  }
  finally { tgLinkLoading.value = false; }
}
async function tgUnlink() {
  const ok = await showConfirm('Telegram', 'Отключить Telegram?', { okText: 'Отключить', danger: true });
  if (!ok) return;
  try {
    await roStore.telegramUnlink();
    tgStatus.linked = false; tgStatus.chat_id = null; tgLinkCode.value = '';
    tgError.value = '';
  } catch (e) {
    tgError.value = e.message || 'Не удалось отключить Telegram';
  }
}

function stockUnitShort(u) {
  return { boxes: 'кор.', kg: 'кг', liters: 'л' }[u] || 'шт.';
}

// Stock collection check
async function checkStockCollection() {
  try {
    const data = await roStore.getStockCollectionStatus();
    stockCollection.active = data.active;
    stockCollection.collection = data.collection || null;
    // Если пользователь уже на вкладке остатков — подгружаем форму
    if (stockCollection.active && activeTab.value === 'stock' && !stockProducts.value.length) {
      loadStockInline();
    }
  } catch (e) {
    if (activeTab.value === 'stock') {
      stockError.value = e.message || 'Не удалось проверить сбор остатков';
    }
  }
}

async function loadStockInline() {
  stockLoading.value = true;
  stockError.value = '';
  try {
    const data = await roStore.getStockCollectionData();
    if (!data.active) {
      stockCollection.active = false;
      stockProducts.value = [];
      return;
    }
    stockCollection.active = true;
    stockCollection.collection = { ...(stockCollection.collection || {}), ...data.collection };
    stockProducts.value = data.products || [];
    // Заполняем поля ранее сохранёнными значениями
    for (const k of Object.keys(stockValues)) delete stockValues[k];
    for (const k of Object.keys(stockSavedSnapshot)) delete stockSavedSnapshot[k];
    for (const p of stockProducts.value) {
      const v = data.values?.[p.id];
      stockValues[p.id] = v != null ? String(v) : '';
      stockSavedSnapshot[p.id] = stockValues[p.id];
    }
    stockLastSubmittedAt.value = data.last_submitted_at || null;
  } catch (e) {
    stockError.value = e.message || 'Ошибка загрузки';
  } finally {
    stockLoading.value = false;
  }
}

async function submitStockInline() {
  if (!stockCollection.collection?.id) return;
  stockError.value = '';
  stockSaving.value = true;
  try {
    const items = stockProducts.value
      .map(p => ({ product_id: p.id, stock: parseFloat(stockValues[p.id] || 0) || 0 }))
      .filter(it => !isNaN(it.stock));
    await roStore.submitStockCollection(stockCollection.collection.id, items);
    // Обновляем снапшот и время сохранения
    for (const p of stockProducts.value) {
      stockSavedSnapshot[p.id] = stockValues[p.id];
    }
    stockLastSubmittedAt.value = new Date().toISOString().slice(0, 19).replace('T', ' ');
    stockSavedFlash.value = true;
    setTimeout(() => { stockSavedFlash.value = false; }, 2000);
    // Обновляем счётчик в дашборд-карточке
    checkStockCollection();
  } catch (e) {
    stockError.value = e.message || 'Не удалось сохранить';
  } finally {
    stockSaving.value = false;
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
  if (roStore.deliveryDays.length) {
    const today = delDateToLocalYmd(new Date());
    const nearest = roStore.deliveryDays.find(d => d.date >= today && d.deadline_status !== 'closed') || roStore.deliveryDays.find(d => d.date >= today) || roStore.deliveryDays[0];
    if (nearest) await delSelectDay(nearest.date);
  }
  delPreviousOrders.value = (await roStore.loadMyOrders(5)).filter(o => o.status === 'submitted' || o.status === 'edited');
  await loadHistory();
  await loadSurveyList();
  await checkStockCollection();
  await loadTgStatus();
  await loadRestaurantBroadcasts();
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
    // Сбрасываем старую сессию ТОЛЬКО локально. Если позвать обычный logout(),
    // он дернёт /api/ro/logout и убьёт session_token на сервере — а это та же запись,
    // которую используют открытые вкладки других устройств. Tg-auth сам перезапишет session_token ниже.
    roStore.logoutLocal();
    try {
      const result = await roStore.loginByTelegram(tgTokenParam);
      if (!result.success) {
        router.replace({ name: 'restaurant-order-login' });
        return;
      }
    } catch {
      router.replace({ name: 'restaurant-order-login' });
      return;
    }
    // Убираем tg_token из URL, уходим на целевой путь. Компонент при этом НЕ перемонтируется,
    // поэтому продолжаем инициализацию ниже — иначе данные не загрузятся и экран будет пустым.
    router.replace(redirectPath || { name: 'restaurant-cabinet' });
  }
  if (!roStore.isAuthenticated) {
    const valid = await roStore.validate();
    if (!valid) { router.replace({ name: 'restaurant-order-login' }); return; }
  }
  try {
    await loadCabinetData();
    startRestaurantBroadcastPolling();
    supUpdateDeadlineTimers();
    supDeadlineTimerInterval = setInterval(supUpdateDeadlineTimers, 1000);
  } catch (e) {
    globalError.value = e.message || 'Ошибка загрузки кабинета';
  } finally { globalLoading.value = false; }
});

onUnmounted(() => {
  clearInterval(delEditTimerInterval);
  clearInterval(supDeadlineTimerInterval);
  if (restaurantBroadcastTimer) clearInterval(restaurantBroadcastTimer);
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
.sb-item-ext { margin-left: auto; font-size: 11px; color: rgba(255,255,255,0.4); }
.sb-icon { font-size: 17px; width: 22px; text-align: center; flex-shrink: 0; }
.sb-badge { margin-left: auto; min-width: 20px; height: 20px; border-radius: 10px; background: #E76F51; color: white; font-size: 10px; font-weight: 800; display: flex; align-items: center; justify-content: center; padding: 0 6px; flex-shrink: 0; }
.sb-badge.warn { background: #f59e0b; }
.sb-badge.ok { background: #16a34a; }
.sb-badge.alert { background: #dc2626; }
.sb-badge.pause { background: #9ca3af; font-size: 9px; padding: 0 7px; text-transform: uppercase; letter-spacing: 0.5px; }
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
  font-size: 13px;
  font-weight: 900;
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
.dash-urgent { display: flex; flex-direction: column; gap: 10px; margin-bottom: 24px; }
.dash-card { display: flex; align-items: center; gap: 14px; background: white; border-radius: 16px; padding: 16px 18px; cursor: pointer; transition: all 0.18s; border: 1px solid #EDE8E3; border-left: 4px solid #f59e0b; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
.dash-card:hover { transform: translateX(4px); box-shadow: 0 4px 16px rgba(0,0,0,0.06); }
.dash-card--warn { border-left-color: #f59e0b; }
.dash-card--green { border-left-color: #16a34a; }
.dash-card--orange { border-left-color: #ea580c; }
.dash-card--alert { border-left-color: #dc2626; }
.dash-card-icon { font-size: 26px; flex-shrink: 0; }
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
.dash-action-icon { font-size: 28px; }
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

/* ═══ Orders ═══ */
.ord-tabs { display: flex; gap: 6px; padding: 0 0 12px; overflow-x: auto; flex-wrap: wrap; -webkit-overflow-scrolling: touch; }
.ord-tab { flex-shrink: 0; padding: 6px 14px; border-radius: 10px; border: 1.5px solid #EDE8E3; background: white; cursor: pointer; font-size: 12px; font-weight: 700; color: #8b7355; font-family: inherit; transition: all 0.18s; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
.ord-tab:hover:not(.active) { border-color: #502314; color: #502314; }
.ord-tab.active { background: #502314; color: white; border-color: #502314; }
.ord-tab-badge { font-size: 9px; font-weight: 800; padding: 2px 7px; border-radius: 8px; }
.ord-tab-badge.warn { background: #f59e0b; color: white; }
.ord-tab-badge.ok { background: #16a34a; color: white; }

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
.day-tab-mark { position: absolute; top: -5px; right: -5px; width: 18px; height: 18px; border-radius: 50%; font-size: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; border: 2px solid #F5F0EB; }
.day-tab-mark.done { background: #16a34a; color: white; }
.day-tab-mark.skipped { background: #9ca3af; color: white; font-size: 11px; }
.day-tab-mark.closed { background: #9ca3af; color: white; }

.sup-skip-banner {
  padding: 7px 14px; background: #fef3c7; border-bottom: 1px solid #fbbf24;
  color: #92400e; font-size: 12px; display: flex; align-items: center; flex-wrap: wrap; gap: 6px;
  line-height: 1.3;
}
.sup-skip-banner strong { font-size: 13px; }
.sup-skip-icon { font-size: 14px; }
.sup-skip-hint { font-size: 11px; opacity: 0.75; }

.sup-prev-order-block { background: #f1f5f9; border-bottom: 1px solid #cbd5e1; padding: 8px 14px; }
.sup-prev-order-head { display: flex; justify-content: space-between; align-items: center; cursor: pointer; font-weight: 500; color: #334155; font-size: 13px; }
.sup-prev-order-toggle { font-size: 11px; color: #64748b; }
.sup-prev-order-body { margin-top: 6px; border-top: 1px dashed #cbd5e1; padding-top: 6px; max-height: 240px; overflow-y: auto; }
.sup-prev-order-row { display: flex; justify-content: space-between; padding: 2px 0; font-size: 12px; }
.sup-prev-name { color: #334155; }
.sup-prev-qty { color: #64748b; font-variant-numeric: tabular-nums; }
.sup-prev-order-actions { margin-top: 8px; border-top: 1px dashed #cbd5e1; padding-top: 8px; display: flex; justify-content: center; }
.sup-prev-repeat-btn { background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 6px 12px; font-size: 12px; font-weight: 500; color: #0f766e; cursor: pointer; transition: background 0.15s; }
.sup-prev-repeat-btn:hover { background: #ecfdf5; }
.sup-prev-repeat-btn:active { background: #d1fae5; }

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
.del-th-mult { width: 80px; text-align: center; }
.del-th-qty { width: 90px; text-align: center; }
.del-th-act { width: 30px; }
.del-td-name { font-weight: 500; text-align: left; }
.del-td-mult { text-align: center; }
.del-sku { font-size: 10px; color: #B0A090; font-family: 'SF Mono', 'JetBrains Mono', monospace; margin-right: 4px; }
.del-mult { font-size: 10px; color: #2563eb; background: #EFF6FF; padding: 2px 7px; border-radius: 5px; font-weight: 700; margin-left: 6px; }
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
  color: white; font-size: 38px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 18px;
  box-shadow: 0 8px 24px rgba(22, 163, 74, 0.3);
}
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
  .sup-prev-order-block { padding: 8px 10px; }
  .sup-prev-order-head { flex-wrap: wrap; gap: 4px; }
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
.item-edit-mark { font-size: 10px; color: #b45309; background: #fef3c7; padding: 2px 6px; border-radius: 4px; font-weight: 700; cursor: help; white-space: nowrap; }
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
.hist-edited-mark { font-size: 11px; color: #d97706; }

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

.stock-inline { background: white; border-radius: 18px; padding: 20px; margin: 0 0 16px; border: 1px solid #EDE8E3; }
.stock-inline-head { padding-bottom: 12px; border-bottom: 1px solid #F2EDE8; margin-bottom: 12px; }
.stock-inline-head h2 { color: #502314; margin: 0 0 4px; font-size: 18px; }
.stock-inline-sub { color: #8b7355; font-size: 13px; margin: 0; }
.stock-inline-list { display: flex; flex-direction: column; gap: 0; }
.stock-row { display: flex; align-items: center; gap: 12px; padding: 10px 4px; border-bottom: 1px solid #F7F2EC; }
.stock-row:last-child { border-bottom: none; }
.stock-row-main { flex: 1; min-width: 0; }
.stock-row-name { color: #502314; font-size: 14px; font-weight: 600; line-height: 1.25; }
.stock-row-note { color: #8b7355; font-size: 11px; margin-top: 2px; }
.stock-row-input { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
.stock-input { width: 80px; padding: 8px 4px; border: 1.5px solid #e0dbd5; border-radius: 8px; font-size: 16px; text-align: center; font-family: inherit; background: white; transition: border-color 0.15s; }
.stock-input:focus { outline: none; border-color: #E76F51; box-shadow: 0 0 0 2px rgba(231,111,81,0.08); }
.stock-row-unit { font-size: 12px; color: #8b7355; font-weight: 500; min-width: 28px; }
.stock-inline-actions { display: flex; align-items: center; gap: 12px; margin-top: 16px; padding-top: 12px; border-top: 1px solid #F2EDE8; }
.stock-saved-flash { color: #16a34a; font-size: 13px; font-weight: 600; }

/* Profile */
.profile-card { background: white; border-radius: 18px; padding: 20px; margin-bottom: 12px; display: flex; border: 1px solid #EDE8E3; }
.profile-header { display: flex; align-items: center; gap: 12px; }
.profile-avatar { width: 40px; height: 40px; border-radius: 10px; background: #E76F51; color: white; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 800; flex-shrink: 0; }
.profile-header h2 { margin: 0; font-size: 15px; color: #502314; }
.profile-header p { margin: 1px 0 0; font-size: 12px; color: #8b7355; }
.profile-le { font-size: 11px; color: #b39b83; }

.profile-block { background: white; border-radius: 16px; padding: 18px 20px; margin-bottom: 10px; border: 1px solid #EDE8E3; }
.profile-block h3 { margin: 0 0 8px; font-size: 13px; color: #502314; }

.profile-tg-linked { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.profile-tg-ok { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: #16a34a; }
.profile-tg-unlinked p { margin: 0 0 12px; font-size: 13px; color: #8b7355; }
.tg-code-box { background: #f0f9ff; border-radius: 8px; padding: 12px; text-align: center; }
.tg-code-box p { margin: 0 0 6px; font-size: 12px; color: #502314; }
.tg-code-box a { color: #2563eb; font-weight: 600; }
.tg-code { font-size: 28px; font-weight: 800; color: #E76F51; letter-spacing: 5px; font-variant-numeric: tabular-nums; margin: 6px 0; }
.tg-code-hint { font-size: 10px; color: #8b7355; margin: 0; }

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
.cab-info-text { color: #502314; font-size: 14px; line-height: 1.5; margin: 0 0 16px; }
.cab-info-text.cab-info-error { color: #b91c1c; }
.cab-info-text-broadcast { white-space: pre-line; }
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

  /* Dashboard */
  .dash-grid { grid-template-columns: repeat(2, 1fr); }
  .dash-action-grid { grid-template-columns: repeat(2, 1fr); }

  /* Order sub-tabs */
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
  .del-td-mult { display: none; }
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
.mob-tab-icon { font-size: 20px; }
.mob-tab-label { font-size: 9px; font-weight: 700; }
.mob-tab-badge {
  position: absolute; top: 2px; right: calc(50% - 16px);
  min-width: 16px; height: 16px; border-radius: 8px;
  background: #E76F51; color: white; font-size: 9px; font-weight: 800;
  display: flex; align-items: center; justify-content: center;
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
