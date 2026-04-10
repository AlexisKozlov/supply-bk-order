<template>
  <div class="cab">
    <!-- ══════ Sidebar ══════ -->
    <aside class="cab-sidebar">
      <div class="sb-brand">
        <div class="sb-logo">
          <svg width="26" height="26" viewBox="5 5 38 38" xmlns="http://www.w3.org/2000/svg" fill="none">
            <circle cx="16" cy="16" r="10" fill="#D62300"/><circle cx="32" cy="16" r="10" fill="#F5A623"/>
            <circle cx="16" cy="32" r="10" fill="#FF8733"/><circle cx="32" cy="32" r="10" fill="#FFD54F"/>
            <circle cx="24" cy="24" r="8.5" fill="#502314"/>
            <text x="24" y="29" text-anchor="middle" fill="white" font-size="14" font-weight="900" font-family="Arial, sans-serif">S</text>
          </svg>
        </div>
        <div>
          <div class="sb-brand-text">Supply Portal</div>
          <div class="sb-brand-sub">Burger King</div>
        </div>
      </div>

      <button class="sb-item" :class="{ active: activeTab === 'dashboard' }" @click="switchTab('dashboard')">
        <span class="sb-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></span>
        Главная
      </button>

      <div class="sb-label">Заказы</div>
      <!-- Основная поставка -->
      <button class="sb-item" :class="{ active: activeTab === 'orders' && orderSubTab === 'delivery' }"
        @click="switchTab('orders'); orderSubTab = 'delivery'">
        <span class="sb-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 3v5a2 2 0 01-2 2h-1"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></span>
        Основная поставка
        <span v-if="deliveryBadge" class="sb-badge" :class="deliveryBadge.type">{{ deliveryBadge.text }}</span>
      </button>
      <!-- Планета Ресторанов -->
      <button class="sb-item" :class="{ active: activeTab === 'orders' && orderSubTab === 'planeta' }"
        @click="switchTab('orders'); switchOrderSub('planeta')">
        <span class="sb-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg></span>
        Планета Ресторанов
        <span v-if="planetaBadge" class="sb-badge" :class="planetaBadge.type">{{ planetaBadge.text }}</span>
      </button>
      <!-- Поставщики (Камако и др.) -->
      <button v-for="sup in suppliers" :key="'sb-'+sup.id" class="sb-item"
        :class="{ active: activeTab === 'orders' && orderSubTab === 'sup_' + sup.id }"
        @click="switchTab('orders'); switchOrderSub('sup_' + sup.id)">
        <span class="sb-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg></span>
        {{ sup.name }}
        <span v-if="supplierBadge(sup)" class="sb-badge" :class="supplierBadge(sup).type">{{ supplierBadge(sup).text }}</span>
      </button>
      <!-- История заказов -->
      <button class="sb-item"
        :class="{ active: activeTab === 'orders' && orderSubTab === 'history' }"
        @click="switchTab('orders'); switchOrderSub('history')">
        <span class="sb-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 7v5l3 2"/></svg></span>
        История
      </button>

      <div class="sb-label">Другое</div>
      <template v-for="tab in mainTabs.filter(t => t.id !== 'dashboard' && t.id !== 'orders')" :key="tab.id">
        <button class="sb-item" :class="{ active: activeTab === tab.id }" @click="switchTab(tab.id)">
          <span class="sb-icon"><svg v-if="tab.id === 'stock'" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span>
          {{ tab.label }}
          <span v-if="tab.badge" class="sb-badge" :class="tab.badgeType">{{ tab.badge }}</span>
        </button>
      </template>
      <router-link :to="{ name: 'search-cards' }" target="_blank" class="sb-item sb-item-link">
        <span class="sb-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg></span>
        Поиск карточек
        <span class="sb-item-ext" title="Откроется в новой вкладке">↗</span>
      </router-link>

      <div class="sb-spacer"></div>
      <div class="sb-rest" :class="{ active: activeTab === 'profile' }">
        <button class="sb-rest-main" @click="switchTab('profile')" title="Открыть профиль">
          <div class="sb-avatar">{{ roStore.restaurant?.number }}</div>
          <div class="sb-rest-info">
            <div class="sb-rest-name">Ресторан {{ roStore.restaurant?.number }}</div>
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
          <div class="cab-topbar-title">{{ activeTab === 'dashboard' ? 'Главная' : activeTab === 'orders' ? 'Заказы' : activeTab === 'stock' ? 'Остатки' : 'Профиль' }}</div>
          <div class="cab-topbar-sub">Ресторан {{ roStore.restaurant?.number }} · {{ restaurantAddress }}</div>
        </div>
      </div>

    <!-- ══════ Loading ══════ -->
    <div v-if="globalLoading" class="cab-loader">
      <div class="cab-spin"></div>
    </div>

    <!-- ══════ TAB: Дашборд ══════ -->
    <section v-if="activeTab === 'dashboard' && !globalLoading" class="cab-section">
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
          <a class="dash-action" href="/search-cards" target="_blank">
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
        <div v-for="order in historyOrders.slice(0, 5)" :key="order.id" class="dash-order">
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
    <section v-if="activeTab === 'orders' && !globalLoading" class="cab-section cab-section-orders">
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

      <!-- ── Планета Ресторанов ── -->
      <div v-if="orderSubTab === 'planeta'">
        <div v-if="vegLoading" class="mini-loader"><div class="cab-spin"></div></div>
        <div v-else-if="vegNoSession" class="cab-empty-card">
          <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#B0A090" stroke-width="1.5" stroke-linecap="round" style="margin:0 auto 16px; display:block"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg>
          <h2>Планета Ресторанов</h2>
          <p>Сейчас приём заявок не проводится. Возможные причины:</p>
          <ul style="text-align:left; max-width:320px; margin:10px auto 0; font-size:13px; color:#8b7355; line-height:1.8">
            <li>Для вашего ресторана не настроен график доставки овощей</li>
            <li>Нет активной сессии приёма заявок</li>
            <li>Дедлайн подачи истёк</li>
          </ul>
          <p style="margin-top:14px; font-size:12px; color:#B0A090">Обратитесь в отдел закупок для уточнения</p>
        </div>

        <div v-else-if="vegSubmitted && !vegEditing" class="cab-success">
          <div class="cab-success-inner">
            <div class="cab-success-check">&#10003;</div>
            <h2>Заявка отправлена!</h2>
            <div class="veg-success-list">
              <template v-for="del in vegDeliveries" :key="del.date">
                <div class="veg-success-day">{{ vegFmtDeliveryDate(del.date) }}</div>
                <template v-if="vegDayAllZeros(del.date)"><div class="veg-success-skip">Поставка не нужна</div></template>
                <template v-else-if="vegDayHasData(del.date)">
                  <div v-for="prod in vegInfo.products" :key="prod.id + '-' + del.date" class="veg-success-row">
                    <span>{{ prod.product_name }}</span>
                    <strong>{{ vegOrderValues[del.date + '_' + prod.id] || 0 }} {{ vegUnitShort(prod.unit) }}</strong>
                  </div>
                </template>
                <div v-else class="veg-success-skip">Не заказано</div>
              </template>
            </div>
            <div class="cab-success-btns">
              <button v-if="vegCanEdit" class="btn btn-primary" @click="vegEditing = true">Изменить заявку</button>
            </div>
          </div>
        </div>

        <template v-else-if="vegInfo">
          <div v-if="!vegDeliveries.length" class="cab-empty-card">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#B0A090" stroke-width="1.5" stroke-linecap="round" style="margin:0 auto 16px; display:block"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg>
            <h2>Планета Ресторанов</h2>
            <p>Для вашего ресторана не настроен график доставки овощей.</p>
            <p style="margin-top:10px; font-size:12px; color:#B0A090">Обратитесь в отдел закупок для настройки</p>
          </div>
          <template v-else>
          <div class="cab-info-bar">{{ vegInfo.session_name }}</div>
          <div class="day-tabs">
            <button v-for="(del, dIdx) in vegDeliveries" :key="del.date"
              class="day-tab" :class="{ active: vegActiveDay === dIdx, closed: del.expired }"
              @click="vegActiveDay = dIdx">
              <span class="day-tab-label">{{ vegFmtDayShort(del.date) }}</span>
              <span v-if="del.expired" class="day-tab-mark closed">!</span>
              <span v-else-if="vegDayHasData(del.date)" class="day-tab-mark done">&#10003;</span>
            </button>
          </div>

          <div class="order-form">
            <div v-for="(del, dIdx) in vegDeliveries" :key="'vd-'+del.date" v-show="vegActiveDay === dIdx">
              <div class="deadline-bar" :class="del.expired ? 'dl-closed' : 'dl-open'">
                <span class="deadline-icon" v-if="!del.expired">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </span>
                <span class="deadline-icon" v-else>
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                </span>
                <template v-if="del.expired">Дедлайн прошёл</template>
                <template v-else-if="del.deadline">Дедлайн: {{ vegFmtDeadline(del.deadline) }}</template>
              </div>
              <template v-if="!del.expired">
                <div v-if="vegHasPrevData(del.date) && !vegDayHasData(del.date)" class="quick-actions">
                  <button class="btn btn-sm btn-outline" @click="vegFillFromPrev(del.date)">Повторить предыдущий</button>
                </div>
                <div class="item-list">
                  <div v-for="prod in vegInfo.products" :key="prod.id + '-' + del.date"
                    class="item-row" :class="{ 'item-filled': parseFloat(vegOrderValues[del.date + '_' + prod.id]) > 0, 'item-error': vegMultError(del.date, prod) }">
                    <div class="item-info">
                      <span class="item-name">{{ prod.product_name }}</span>
                      <span v-if="prod.multiplicity" class="item-hint">кр. {{ prod.multiplicity }}</span>
                    </div>
                    <div class="item-input">
                      <input v-model="vegOrderValues[del.date + '_' + prod.id]" type="text" inputmode="decimal" placeholder="0"
                        class="item-qty" :class="{ 'item-qty-err': vegMultError(del.date, prod) }" @focus="$event.target.select()" />
                      <span class="item-unit">{{ vegUnitShort(prod.unit) }}</span>
                    </div>
                  </div>
                </div>
              </template>
              <div v-if="del.expired && vegHasPrevData(del.date)" class="prev-data">
                <div class="prev-data-title">Предыдущий заказ:</div>
                <div v-for="prod in vegInfo.products" :key="'prev-' + prod.id" class="prev-data-row">
                  <span>{{ prod.product_name }}</span>
                  <strong v-if="vegPrevInfo(del.date, prod)">{{ vegPrevInfo(del.date, prod).qty }} {{ vegUnitShort(prod.unit) }}</strong>
                  <span v-else>—</span>
                </div>
              </div>
            </div>

            <div class="submit-area" v-if="vegDeliveries.some(d => !d.expired)">
              <div v-if="vegHasMultErrors" class="error-msg">Исправьте кратность</div>
              <div class="submit-buttons-row">
                <button v-if="vegEditing" class="btn btn-outline btn-lg" @click="vegEditing = false; vegSubmitted = true">Отмена</button>
                <button class="btn btn-danger-outline btn-lg" :disabled="vegSubmitting" @click="vegSkipDelivery">
                  Поставка не нужна
                </button>
                <button class="btn btn-primary btn-lg" :disabled="!vegCanSubmit || vegSubmitting || vegHasMultErrors" @click="vegSubmit">
                  <span v-if="vegSubmitting" class="cab-spin cab-spin-sm"></span>
                  {{ vegEditing ? 'Сохранить' : 'Отправить' }}
                </button>
              </div>
              <div v-if="vegError" class="error-msg">{{ vegError }}</div>
            </div>
          </div>
          </template>
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
                <template v-if="supCurrentDateInfo(sup)?.deadline_status === 'open'">Дедлайн: {{ formatDeadline(supCurrentDateInfo(sup)?.deadline) }}</template>
                <template v-else>Приём заявок на эту дату закрыт</template>
              </div>
              <div v-if="supProductsLoading[sup.id]" class="mini-loader"><div class="cab-spin"></div></div>
              <template v-else>
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
        <div v-else-if="!historyOrders.length" class="cab-empty-card"><h2>Нет заказов</h2></div>
        <div v-else>
          <div v-for="order in historyOrders" :key="order.id" class="history-card">
            <div class="history-top">
              <span class="history-date">{{ fmtDate(order.delivery_date) }}</span>
              <span class="dash-order-source" :class="'src-' + order.source">{{ order.source_name }}</span>
              <span class="dash-order-status" :class="'st-' + order.status">{{ statusLabel(order.status) }}</span>
            </div>
            <div class="history-meta">
              <span>{{ order.item_count }} поз.</span>
              <span>{{ order.total_qty }} {{ order.source === 'delivery' ? 'кор.' : 'шт.' }}</span>
              <span v-if="order.total_deposit && parseFloat(order.total_deposit) > 0" title="Сумма залога">Залог: {{ parseFloat(order.total_deposit).toFixed(2) }}</span>
              <span v-if="order.submitted_at" class="history-time">{{ fmtDateTime(order.submitted_at) }}</span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ══════ TAB: Сбор остатков ══════ -->
    <section v-if="activeTab === 'stock' && !globalLoading" class="cab-section">
      <div v-if="!stockCollection.active" class="cab-empty-card">
        <h2>Нет активного сбора</h2>
        <p>Сейчас сбор остатков не проводится.</p>
      </div>
      <div v-else class="stock-card">
        <h2>{{ stockCollection.collection?.name }}</h2>
        <p v-if="stockCollection.collection?.submitted">
          Вы уже отправили данные ({{ stockCollection.collection.submitted_count }} из {{ stockCollection.collection.total_products }} позиций).
        </p>
        <p v-else>Необходимо заполнить {{ stockCollection.collection?.total_products }} позиций.</p>
        <a v-if="stockCollection.collection?.token"
          :href="'/stock-form/' + stockCollection.collection.token"
          target="_blank" class="btn btn-primary btn-lg stock-link">
          {{ stockCollection.collection?.submitted ? 'Изменить данные' : 'Заполнить остатки' }}
        </a>
      </div>
    </section>

    <!-- ══════ TAB: Профиль ══════ -->
    <section v-if="activeTab === 'profile' && !globalLoading" class="cab-section">
      <div class="profile-card">
        <div class="profile-header">
          <div class="profile-avatar">{{ roStore.restaurant?.number }}</div>
          <div>
            <h2>Ресторан {{ roStore.restaurant?.number }}</h2>
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
          Закупщик увидит, что на эту дату ваш ресторан ничего не заказывает.
        </div>
        <div class="cab-success-btns">
          <button class="btn btn-primary" @click="supShowSuccess = false">OK</button>
        </div>
      </div>
    </div>

    <!-- ══════ Mobile tab bar ══════ -->
    <div class="mob-tabbar">
      <button v-for="tab in mainTabs" :key="tab.id" class="mob-tab" :class="{ active: activeTab === tab.id }" @click="switchTab(tab.id)">
        <span class="mob-tab-icon">{{ tab.id === 'dashboard' ? '\u{1F3E0}' : tab.id === 'orders' ? '\u{1F4E6}' : tab.id === 'stock' ? '\u{1F4CB}' : '\u{2699}' }}</span>
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
import { db } from '@/lib/apiClient.js';
import { formatDate as fmtDate, formatDateShort as fmtDateShort, formatDateTime as fmtDateTime, statusLabel } from '@/lib/roUtils.js';

const router = useRouter();
const route = useRoute();
const roStore = useRestaurantOrderStore();
const soStore = useSupplierOrderStore();

const globalLoading = ref(true);
const activeTab = ref('dashboard');

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

// ═══ Telegram ═══
const tgStatus = reactive({ linked: false, chat_id: null });
const tgLinkCode = ref('');
const tgLinkLoading = ref(false);

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

// ═══ Dashboard ═══
const dashOrdersSubmitted = computed(() => {
  // Основная поставка
  let total = roStore.deliveryDays.filter(d => d.order?.status === 'submitted' || d.order?.status === 'edited').length;
  // Поставщики (Камако и др.)
  for (const sup of suppliers.value) {
    total += (sup.available_dates || []).filter(d => !!d.order).length;
  }
  // Планета — считаем как 1 поданную, если ресторан отправил
  if (vegInfo.value && vegSubmitted.value) total += 1;
  return total;
});
const dashOrdersPending = computed(() => {
  // Основная поставка: открытые дни без заявки
  let total = roStore.deliveryDays.filter(d => d.deadline_status !== 'closed' && d.deadline_status !== 'not_open' && !d.order).length;
  // Поставщики: открытые даты без заявки
  for (const sup of suppliers.value) {
    total += (sup.available_dates || []).filter(d => d.deadline_status === 'open' && !d.order).length;
  }
  // Планета: 1, если есть открытые дни и ничего не подано
  if (vegInfo.value && !vegSubmitted.value) {
    const openVeg = vegDeliveries.value.filter(d => !d.expired).length;
    if (openVeg > 0) total += 1;
  }
  return total;
});

const urgentItems = computed(() => {
  const items = [];
  // Delivery deadlines
  const openDays = roStore.deliveryDays.filter(d => (d.deadline_status === 'open' || d.deadline_status === 'warning') && !d.order);
  if (openDays.length) {
    items.push({
      key: 'del', type: 'warn',
      icon: '&#128230;', title: `Основная поставка: ${openDays.length} дн. без заявки`,
      subtitle: openDays.map(d => d.day_name).join(', '),
      action: () => { switchTab('orders'); orderSubTab.value = 'delivery'; if (openDays[0]) delSelectDay(openDays[0].date); },
    });
  }
  // Veg
  if (vegInfo.value && !vegSubmitted.value) {
    const openVeg = vegDeliveries.value.filter(d => !d.expired).length;
    if (openVeg) {
      items.push({
        key: 'veg', type: 'green',
        icon: '&#127811;', title: 'Планета Ресторанов: заявка не подана',
        subtitle: `${openVeg} дн. доставки`,
        action: () => { switchTab('orders'); switchOrderSub('planeta'); },
      });
    }
  }
  // Suppliers
  for (const sup of suppliers.value) {
    const openDates = sup.available_dates?.filter(d => d.deadline_status === 'open' && !d.order) || [];
    if (openDates.length) {
      items.push({
        key: 'sup_' + sup.id, type: 'orange',
        icon: '&#128230;', title: `${sup.name}: ${openDates.length} дн. без заявки`,
        subtitle: openDates.map(d => d.delivery_day_name).join(', '),
        action: () => { switchTab('orders'); orderSubTab.value = 'sup_' + sup.id; },
      });
    }
  }
  // Stock
  if (stockCollection.active && !stockCollection.collection?.submitted) {
    items.push({
      key: 'stock', type: 'alert',
      icon: '&#128203;', title: 'Сбор остатков',
      subtitle: stockCollection.collection?.name || 'Нужно заполнить',
      action: () => switchTab('stock'),
    });
  }
  return items;
});

// ═══ Tabs ═══
const mainTabs = computed(() => {
  const tabs = [
    { id: 'dashboard', label: 'Главная' },
    { id: 'orders', label: 'Заказы', badge: dashOrdersPending.value || null, badgeType: dashOrdersPending.value ? 'warn' : '' },
  ];
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
const delShowAddModal = ref(false);
const delAddSearch = ref('');
const delAddResults = ref([]);
const delAddLoading = ref(false);
const delAddSearchInput = ref(null);
let delAddTimer = null;
const delSavedSnapshot = ref('');

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
const delHasUnsavedChanges = computed(() => {
  if (!delSavedSnapshot.value) return false;
  return JSON.stringify(delOrderItems.value.map(i => ({ s: i.sku, q: i.quantity }))) !== delSavedSnapshot.value;
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
function delCheckMultiplicity(item) { const m = item.multiplicity || 1; const q = parseFloat(item.quantity) || 0; item._multError = m > 1 && q > 0 && q % m !== 0; }

async function delSelectDay(date) {
  delSelectedDate.value = date;
  delExistingOrder.value = null;
  delSubmitError.value = '';
  delSearchQuery.value = '';
  delActiveCategory.value = 'Сухой';
  delOrderComment.value = '';
  delDraftRestoreNotice.value = '';
  const order = await roStore.loadMyOrder(date);
  if (order) {
    delExistingOrder.value = order;
    delOrderComment.value = order.comment || '';
    delOrderItems.value = order.items.map(i => ({ sku: i.sku, product_name: i.product_name, category: i.category, quantity: parseFloat(i.quantity) || 0, comment: i.comment || '', multiplicity: 1, _added: false, _multError: false }));
  } else { delOrderItems.value = []; }
  for (const cat of delCategories) { if (!delOrderItems.value.some(i => i.category === cat)) await delLoadCategoryProducts(cat); }
  delOrderItems.value.sort((a, b) => { if (a.category !== b.category) return delCategories.indexOf(a.category) - delCategories.indexOf(b.category); return (a.quantity > 0 ? 0 : 1) - (b.quantity > 0 ? 0 : 1); });

  // Восстановление черновика, если он есть и заказ ещё можно редактировать
  const draft = delLoadDraft(date);
  if (draft && (delCanSubmit.value || delCanEdit.value)) {
    let restored = 0;
    for (const dItem of (draft.items || [])) {
      const existing = delOrderItems.value.find(i => i.sku === dItem.sku);
      if (existing) {
        if (dItem.quantity !== existing.quantity || (dItem.comment || '') !== (existing.comment || '')) {
          existing.quantity = dItem.quantity;
          existing.comment = dItem.comment || '';
          restored++;
        }
      } else if (dItem.quantity > 0) {
        delOrderItems.value.push({ sku: dItem.sku, product_name: dItem.product_name, category: dItem.category || 'Сухой', quantity: dItem.quantity, comment: dItem.comment || '', multiplicity: dItem.multiplicity || 1, _added: true, _multError: false });
        restored++;
      }
    }
    if (draft.comment && draft.comment !== delOrderComment.value) {
      delOrderComment.value = draft.comment;
      restored++;
    }
    if (restored > 0) {
      const ts = draft.savedAt ? new Date(draft.savedAt).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '';
      delDraftRestoreNotice.value = `Восстановлен черновик от ${ts}`;
      setTimeout(() => { delDraftRestoreNotice.value = ''; }, 8000);
    }
  }

  delSavedSnapshot.value = JSON.stringify(delOrderItems.value.map(i => ({ s: i.sku, q: i.quantity })));
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

async function delHandleSubmit() {
  delSubmitting.value = true; delSubmitError.value = '';
  try {
    const items = delOrderItems.value.filter(i => i.quantity > 0).map(i => ({ sku: i.sku, product_name: i.product_name, category: i.category, quantity: i.quantity }));
    if (!items.length) { delSubmitError.value = 'Добавьте хотя бы одну позицию'; return; }
    const result = await roStore.submitOrder(delSelectedDate.value, items, delOrderComment.value || null);
    if (result.success) {
      delClearDraft(delSelectedDate.value);
      delWasEdited.value = !!delExistingOrder.value;
      delExistingOrder.value = { id: result.order_id };
      roStore.loadMyInfo();
      delShowSuccess.value = true;
      delStartEditTimer();
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
  const orderDateStr = orderDate.toISOString().slice(0, 10);
  // Собираем дедлайн в минском времени (UTC+3)
  const dlMinsk = new Date(`${orderDateStr}T${editUntil}+03:00`);
  const now = new Date();
  if (now >= dlMinsk) { delEditTimeLeft.value = ''; clearInterval(delEditTimerInterval); return; }
  const d = dlMinsk - now; const h = Math.floor(d/3600000); const m = Math.floor((d%3600000)/60000); const s = Math.floor((d%60000)/1000);
  delEditTimeLeft.value = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
}
function delGoToNextDay() { delShowSuccess.value = false; clearInterval(delEditTimerInterval); const idx = roStore.deliveryDays.findIndex(d => d.date === delSelectedDate.value); const next = roStore.deliveryDays[idx + 1]; if (next) delSelectDay(next.date); }
function delClearOrder() { if (!confirm('Очистить все количества?')) return; for (const item of delOrderItems.value) { item.quantity = 0; item.comment = ''; item._multError = false; } }
function delRemoveItem(item) { const idx = delOrderItems.value.indexOf(item); if (idx >= 0) delOrderItems.value.splice(idx, 1); }

async function delHandleRepeat(sourceOrderId) {
  try {
    const result = await roStore.repeatOrder(sourceOrderId, delSelectedDate.value);
    if (result.items) { for (const item of result.items) { const existing = delOrderItems.value.find(i => i.sku === item.sku); if (existing) { existing.quantity = parseFloat(item.quantity) || 0; existing.comment = item.comment || ''; } else { delOrderItems.value.push({ sku: item.sku, product_name: item.product_name, category: item.category, quantity: parseFloat(item.quantity) || 0, comment: item.comment || '', multiplicity: 1, _added: true, _multError: false }); } } }
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
  XLSX.utils.book_append_sheet(wb, ws, `Заказ ${roStore.restaurant?.number}`.slice(0, 31));
  XLSX.writeFile(wb, `Заказ_${roStore.restaurant?.number}_${delSelectedDate.value}.xlsx`);
}

// ═══ Планета Ресторанов (veg) ═══
const vegLoading = ref(false);
const vegNoSession = ref(false);
const vegInfo = ref(null);
const vegDeliveries = ref([]);
const vegActiveDay = ref(0);
const vegOrderValues = reactive({});
const vegSubmitted = ref(false);
const vegSubmitting = ref(false);
const vegEditing = ref(false);
const vegError = ref('');
const vegAllExisting = ref([]);
const vegPrevSessionOrders = ref([]);

const DAY_NAMES = { 1: 'Понедельник', 2: 'Вторник', 3: 'Среда', 4: 'Четверг', 5: 'Пятница', 6: 'Суббота', 7: 'Воскресенье' };
const DAY_SHORT = { 1: 'Пн', 2: 'Вт', 3: 'Ср', 4: 'Чт', 5: 'Пт', 6: 'Сб', 7: 'Вс' };

function vegUnitShort(u) { return u === 'pcs' ? 'шт.' : 'кг'; }
function vegUnitLabel(u) { return u === 'pcs' ? 'штуки' : 'килограммы'; }
function vegFmtDeliveryDate(dateStr) { const d = new Date(dateStr + 'T00:00:00'); const dow = d.getDay() || 7; return `${DAY_NAMES[dow]}, ${d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' })}`; }
function vegFmtDeadline(str) { if (!str) return ''; const d = new Date(str.replace(' ', 'T')); const dow = d.getDay() || 7; return `${DAY_NAMES[dow]}, ${d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' })}, ${d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}`; }
function vegFmtDayShort(dateStr) { if (!dateStr) return ''; const d = new Date(dateStr + 'T00:00:00'); const dow = d.getDay() || 7; return `${DAY_SHORT[dow]}, ${d.getDate()} ${d.toLocaleDateString('ru-RU', { month: 'short' })}`; }

function vegMultError(date, prod) { if (!prod.multiplicity || prod.multiplicity <= 0) return false; const val = parseFloat(String(vegOrderValues[date + '_' + prod.id] || '0').replace(',', '.')) || 0; if (val === 0) return false; const rem = Math.abs(val % prod.multiplicity); return rem > 0.001 && Math.abs(rem - prod.multiplicity) > 0.001; }
const vegHasMultErrors = computed(() => { if (!vegInfo.value) return false; return vegDeliveries.value.some(del => !del.expired && vegInfo.value.products.some(prod => vegMultError(del.date, prod))); });
const vegCanSubmit = computed(() => { if (!vegInfo.value || !vegDeliveries.value.length) return false; if (!vegDeliveries.value.some(d => !d.expired)) return false; return vegDeliveries.value.some(d => !d.expired && vegDayHasData(d.date)); });
const vegCanEdit = computed(() => vegDeliveries.value.some(d => !d.expired));

function vegDayHasData(date) { if (!vegInfo.value) return false; return vegInfo.value.products.some(p => { const val = String(vegOrderValues[date + '_' + p.id] || '').replace(',', '.').trim(); return val !== ''; }); }
function vegDayAllZeros(date) { if (!vegInfo.value) return false; return vegInfo.value.products.every(p => { const val = String(vegOrderValues[date + '_' + p.id] || '').replace(',', '.').trim(); return val === '0'; }); }
function vegOrderQty(order) { const adminQ = order.admin_qty !== null && order.admin_qty !== undefined ? parseFloat(order.admin_qty) : NaN; return !isNaN(adminQ) ? adminQ : parseFloat(order.quantity); }
function vegPrevInfo(date, prod) {
  const allDates = [...new Set(vegAllExisting.value.map(o => o.delivery_date))].sort();
  const prevDates = allDates.filter(d => d < date);
  if (prevDates.length > 0) { const prevDate = prevDates[prevDates.length - 1]; const order = vegAllExisting.value.find(o => o.delivery_date === prevDate && o.product_id === prod.id); if (order) { const q = vegOrderQty(order); if (q > 0) return { date: prevDate, qty: q }; } }
  const prevOrders = vegPrevSessionOrders.value.filter(o => o.product_name === prod.product_name);
  if (!prevOrders.length) return null;
  const sorted = prevOrders.sort((a, b) => b.delivery_date.localeCompare(a.delivery_date));
  const q = vegOrderQty(sorted[0]);
  return q > 0 ? { date: sorted[0].delivery_date, qty: q } : null;
}
function vegHasPrevData(date) { if (!vegInfo.value) return false; return vegInfo.value.products.some(p => vegPrevInfo(date, p)); }
function vegFillFromPrev(date) { if (!vegInfo.value) return; for (const prod of vegInfo.value.products) { const prev = vegPrevInfo(date, prod); if (prev?.qty > 0) vegOrderValues[date + '_' + prod.id] = String(prev.qty); } }
function vegFillZeros(date) { if (!vegInfo.value) return; for (const prod of vegInfo.value.products) vegOrderValues[date + '_' + prod.id] = '0'; }

const planetaBadge = computed(() => {
  if (vegNoSession.value || !vegInfo.value) return null;
  if (vegSubmitted.value) return { text: '\u2713', type: 'ok' };
  const open = vegDeliveries.value.filter(d => !d.expired).length;
  if (open > 0) return { text: open, type: 'warn' };
  return null;
});

async function vegLoadData() {
  vegLoading.value = true; vegError.value = '';
  try {
    const { data } = await db.rpc('veg_validate_token', {});
    if (!data || data.error) { vegNoSession.value = true; return; }
    vegInfo.value = data;
    const restNum = roStore.restaurant?.number;
    const [schedRes, ordRes, prevRes] = await Promise.all([
      db.rpc('veg_get_schedule', { restaurant_number: restNum }),
      db.rpc('veg_get_existing_orders', { restaurant_number: restNum }),
      db.rpc('veg_get_previous_orders', { restaurant_number: restNum }),
    ]);
    vegDeliveries.value = schedRes.data?.deliveries || [];
    for (const del of vegDeliveries.value) { for (const prod of (vegInfo.value?.products || [])) { vegOrderValues[del.date + '_' + prod.id] = ''; } }
    const existing = ordRes.data?.orders || [];
    vegAllExisting.value = existing;
    vegPrevSessionOrders.value = prevRes.data?.orders || [];
    for (const o of existing) { const key = o.delivery_date + '_' + o.product_id; if (key in vegOrderValues) { const q = vegOrderQty(o); vegOrderValues[key] = !isNaN(q) ? String(q) : ''; } }
    // Считаем «отправлено» только если ВСЕ открытые дни имеют данные
    const openDays = vegDeliveries.value.filter(d => !d.expired);
    if (openDays.length > 0) {
      const allDaysFilled = openDays.every(d => vegDayHasData(d.date));
      if (allDaysFilled) vegSubmitted.value = true;
    }
  } catch { vegNoSession.value = true; }
  finally { vegLoading.value = false; }
}

async function vegSubmit() {
  vegError.value = ''; vegSubmitting.value = true;
  try {
    const items = [];
    for (const del of vegDeliveries.value) { if (del.expired) continue; for (const prod of (vegInfo.value?.products || [])) { const val = String(vegOrderValues[del.date + '_' + prod.id] || '').replace(',', '.').trim(); if (val === '') continue; items.push({ product_id: prod.id, delivery_date: del.date, quantity: parseFloat(val) || 0 }); } }
    const submittedDates = vegDeliveries.value.filter(d => !d.expired).map(d => d.date);
    const { data } = await db.rpc('veg_submit_order', { restaurant_number: roStore.restaurant?.number, items, submitted_dates: submittedDates });
    if (data?.error) { vegError.value = data.error === 'session_closed' ? 'Сессия закрыта' : data.error; }
    else { vegSubmitted.value = true; vegEditing.value = false; }
  } catch { vegError.value = 'Ошибка при отправке'; }
  finally { vegSubmitting.value = false; }
}

async function vegSkipDelivery() {
  if (!confirm('Подтвердить, что поставка от «Планета Ресторанов» не нужна на эти дни?')) return;
  // Заполняем нулями все активные дни
  for (const del of vegDeliveries.value) {
    if (del.expired) continue;
    for (const prod of (vegInfo.value?.products || [])) {
      vegOrderValues[del.date + '_' + prod.id] = '0';
    }
  }
  await vegSubmit();
}

// ═══ Supplier orders ═══
const supSelectedDates = reactive({});
const supProducts = reactive({});
const supQuantities = reactive({});
const supAdminEdits = reactive({}); // { supId: { sku: { original, edited } } } — правки закупщика
const supProductsLoading = reactive({});
const supIsSkipOrder = reactive({}); // { supId: true } — заявка с флагом «поставка не нужна»
const supSubmitting = reactive({});
const supShowSuccess = ref(false);
const supSuccessInfo = ref({});

function supplierBadge(sup) { if (!sup.is_accepting_orders) return null; const submitted = sup.available_dates?.filter(d => d.order).length || 0; const open = sup.available_dates?.filter(d => d.deadline_status === 'open' && !d.order).length || 0; if (open > 0) return { text: open, type: 'warn' }; if (submitted > 0) return { text: submitted, type: 'ok' }; return null; }
function supCurrentDateInfo(sup) { if (!supSelectedDates[sup.id]) return null; return sup.available_dates?.find(d => d.delivery_date === supSelectedDates[sup.id]); }
function formatDeadline(dl) { if (!dl) return ''; const [date, time] = dl.split(' '); const d = new Date(date + 'T00:00:00'); const label = d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', weekday: 'short' }); return (time || '') + ', ' + label; }

async function supSelectDate(sup, dateInfo) {
  supSelectedDates[sup.id] = dateInfo.delivery_date;
  supProductsLoading[sup.id] = true;
  supQuantities[sup.id] = {};
  supAdminEdits[sup.id] = {};
  supIsSkipOrder[sup.id] = false;
  try {
    supProducts[sup.id] = await soStore.loadProducts(sup.id);
    if (dateInfo.order) {
      const order = await soStore.loadMyOrder(sup.id, dateInfo.delivery_date);
      const itemCount = order?.items?.length || 0;
      if (itemCount > 0) {
        for (const item of order.items) {
          const orig = parseFloat(item.quantity) || 0;
          const adminQ = (item.admin_qty !== null && item.admin_qty !== undefined && item.admin_qty !== '')
            ? parseFloat(item.admin_qty) : null;
          // Эффективное значение: правка закупщика, если есть, иначе исходное
          supQuantities[sup.id][item.sku] = adminQ !== null ? adminQ : orig;
          // Помечаем правку, если значение реально изменилось
          if (adminQ !== null && Math.abs(adminQ - orig) > 0.001) {
            supAdminEdits[sup.id][item.sku] = { original: orig, edited: adminQ };
          }
        }
      } else {
        // Заявка есть, но позиций нет → «Поставка не нужна»: ставим нули во все поля
        supIsSkipOrder[sup.id] = true;
        for (const p of (supProducts[sup.id] || [])) {
          supQuantities[sup.id][p.sku] = 0;
        }
      }
    }
  } catch (e) { console.error('Ошибка загрузки:', e); }
  finally { supProductsLoading[sup.id] = false; }
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

async function supHandleSubmit(sup) {
  supSubmitting[sup.id] = true;
  try {
    const items = (supProducts[sup.id] || []).filter(p => supQuantities[sup.id][p.sku] > 0).map(p => ({ product_id: p.product_id || p.id || '', sku: p.sku, product_name: p.product_name || p.name || '', quantity: supQuantities[sup.id][p.sku] }));
    const dateInfo = supCurrentDateInfo(sup);
    const result = await soStore.submitOrder(sup.id, supSelectedDates[sup.id], dateInfo?.order_date || '', items);
    if (result.success) { supSuccessInfo.value = { supplier_name: sup.name, delivery_date: supSelectedDates[sup.id], total_items: items.length, total_qty: items.reduce((s, i) => s + i.quantity, 0) }; supShowSuccess.value = true; suppliers.value = await soStore.loadSuppliers(); }
  } catch (e) { alert(e.message || 'Ошибка отправки'); }
  finally { supSubmitting[sup.id] = false; }
}

async function supSkipDelivery(sup) {
  if (!confirm(`Подтвердить, что поставка от «${sup.name}» на эту дату не нужна?`)) return;
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
      suppliers.value = await soStore.loadSuppliers();
    }
  } catch (e) { alert(e.message || 'Ошибка отправки'); }
  finally { supSubmitting[sup.id] = false; }
}

// ═══ Общее ═══
function switchTab(tab) {
  // Защита от случайного переключения при несохранённых изменениях
  if (activeTab.value === 'orders' && tab !== 'orders') {
    if (delHasUnsavedChanges.value && !confirm('В заказе есть несохранённые изменения. Перейти на другую вкладку?')) return;
    if (vegEditing.value && !confirm('Заявка «Планета Ресторанов» не сохранена. Перейти на другую вкладку?')) return;
  }
  if (activeTab.value === 'profile' && tab !== 'profile') {
    if ((pwOld.value || pwNew.value) && !confirm('Вы начали менять пароль. Перейти на другую вкладку?')) return;
  }
  activeTab.value = tab;
  if (tab === 'orders' && orderSubTab.value === 'planeta' && !vegInfo.value && !vegLoading.value && !vegNoSession.value) vegLoadData();
  if (tab === 'orders' && !historyOrders.value.length && orderSubTab.value === 'history') loadHistory();
}
function switchOrderSub(sub) {
  orderSubTab.value = sub;
  if (sub === 'planeta' && !vegInfo.value && !vegLoading.value && !vegNoSession.value) vegLoadData();
  if (sub === 'history' && !historyOrders.value.length) loadHistory();
  // Автовыбор ближайшей даты при открытии вкладки поставщика
  if (sub.startsWith('sup_')) {
    const supId = sub.slice(4);
    const sup = suppliers.value.find(s => String(s.id) === String(supId));
    if (sup && !supSelectedDates[sup.id]) supAutoSelectDate(sup);
  }
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
    orderSubTab.value = 'planeta';
    if (!vegInfo.value && !vegLoading.value && !vegNoSession.value) vegLoadData();
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
  } else if (activeTab.value === 'stock') {
    target = { name: 'restaurant-stock' };
  } else if (activeTab.value === 'profile') {
    target = { name: 'restaurant-profile' };
  } else if (activeTab.value === 'orders') {
    const sub = orderSubTab.value;
    if (sub === 'delivery') target = { name: 'restaurant-orders-delivery' };
    else if (sub === 'planeta') target = { name: 'restaurant-orders-planeta' };
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
  try { historyOrders.value = await roStore.loadAllHistory(50); }
  catch { historyOrders.value = []; }
  finally { historyLoading.value = false; }
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
  try {
    const data = await roStore.getTelegramStatus();
    tgStatus.linked = data.linked; tgStatus.chat_id = data.chat_id;
  } catch {}
}
async function tgGetCode() {
  tgLinkLoading.value = true;
  try {
    const data = await roStore.telegramLink();
    if (data.already_linked) { tgStatus.linked = true; return; }
    if (data.code) tgLinkCode.value = data.code;
  } catch {}
  finally { tgLinkLoading.value = false; }
}
async function tgUnlink() {
  if (!confirm('Отключить Telegram?')) return;
  try {
    await roStore.telegramUnlink();
    tgStatus.linked = false; tgStatus.chat_id = null; tgLinkCode.value = '';
  } catch {}
}

// Stock collection check
async function checkStockCollection() {
  try {
    const data = await roStore.getStockCollectionStatus();
    stockCollection.active = data.active;
    stockCollection.collection = data.collection || null;
  } catch {}
}

function onBeforeUnload(e) {
  if (delHasUnsavedChanges.value || vegEditing.value || pwOld.value || pwNew.value) {
    e.preventDefault();
    e.returnValue = '';
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
    // Сбрасываем старую сессию (другого ресторана)
    roStore.logout();
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
    // Чистим query, идём на нужный путь
    if (redirectPath) {
      router.replace(redirectPath);
    } else {
      router.replace({ name: 'restaurant-cabinet' });
    }
    return; // onMounted перезапустится после router.replace
  }
  if (!roStore.isAuthenticated) {
    const valid = await roStore.validate();
    if (!valid) { router.replace({ name: 'restaurant-order-login' }); return; }
  }
  try {
    await roStore.loadMyInfo();
    try { suppliers.value = await soStore.loadSuppliers(); } catch (e) { console.warn('Поставщики:', e); }
    // Применяем активный роут (например, при заходе по прямой ссылке /restaurant/orders/supplier/1)
    applyRouteToState();
    // Auto-select first delivery day
    if (roStore.deliveryDays.length) {
      const today = new Date().toISOString().slice(0, 10);
      const nearest = roStore.deliveryDays.find(d => d.date >= today && d.deadline_status !== 'closed') || roStore.deliveryDays.find(d => d.date >= today) || roStore.deliveryDays[0];
      if (nearest) delSelectDay(nearest.date);
    }
    const orders = await roStore.loadMyOrders(5);
    delPreviousOrders.value = orders.filter(o => o.status === 'submitted' || o.status === 'edited');
    // Background loads
    loadHistory();
    checkStockCollection();
    loadTgStatus();
    vegLoadData();
  } finally { globalLoading.value = false; }
});

onUnmounted(() => { clearInterval(delEditTimerInterval); window.removeEventListener('beforeunload', onBeforeUnload); });
</script>

<style scoped>
/* ═══ Base ═══ */
.cab { min-height: 100vh; background: #F5F0EB; font-family: 'Inter', system-ui, -apple-system, sans-serif; box-sizing: border-box; display: flex; }
.cab *, .cab *::before, .cab *::after { box-sizing: border-box; }

/* ═══ Sidebar ═══ */
.cab-sidebar {
  width: 220px; min-height: 100vh; background: #502314;
  display: flex; flex-direction: column; padding: 20px 10px;
  position: fixed; top: 0; left: 0; bottom: 0; z-index: 100;
}
.sb-brand { display: flex; align-items: center; gap: 11px; padding: 6px 10px; margin-bottom: 24px; }
.sb-logo { width: 40px; height: 40px; border-radius: 12px; background: rgba(255,255,255,0.1); display: flex; align-items: center; justify-content: center; flex-shrink: 0; backdrop-filter: blur(4px); }
.sb-brand-text { font-size: 14px; font-weight: 800; color: white; letter-spacing: -0.3px; }
.sb-brand-sub { font-size: 9px; color: rgba(255,255,255,0.3); font-weight: 500; margin-top: 1px; letter-spacing: 0.5px; text-transform: uppercase; }
.sb-label { font-size: 9px; font-weight: 700; color: rgba(255,255,255,0.35); text-transform: uppercase; letter-spacing: 1.5px; padding: 0 12px; margin: 18px 0 6px; }
.sb-item { display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-radius: 11px; border: none; background: transparent; color: rgba(255,255,255,0.8); font-size: 12px; font-weight: 600; cursor: pointer; font-family: inherit; transition: all 0.18s; width: 100%; text-align: left; }
.sb-item:hover { background: rgba(255,255,255,0.12); color: white; }
.sb-item.active { background: rgba(214,35,0,0.3); color: #FF8733; }
.sb-item-link { text-decoration: none; }
.sb-item-ext { margin-left: auto; font-size: 11px; color: rgba(255,255,255,0.4); }
.sb-icon { font-size: 17px; width: 22px; text-align: center; flex-shrink: 0; }
.sb-badge { margin-left: auto; min-width: 20px; height: 20px; border-radius: 10px; background: #D62300; color: white; font-size: 10px; font-weight: 800; display: flex; align-items: center; justify-content: center; padding: 0 6px; flex-shrink: 0; }
.sb-badge.warn { background: #f59e0b; }
.sb-badge.ok { background: #16a34a; }
.sb-badge.alert { background: #dc2626; }
.sb-spacer { flex: 1; }
.sb-rest {
  background: rgba(255,255,255,0.06); border-radius: 13px;
  margin-top: 8px; border: 1px solid rgba(255,255,255,0.04);
  display: flex; align-items: stretch; overflow: hidden;
  transition: background 0.18s, border-color 0.18s;
}
.sb-rest:hover { background: rgba(255,255,255,0.1); border-color: rgba(255,255,255,0.08); }
.sb-rest.active { background: rgba(214,35,0,0.18); border-color: rgba(255,135,51,0.35); }
.sb-rest-main {
  flex: 1; min-width: 0;
  display: flex; align-items: center; gap: 10px;
  padding: 12px; background: transparent; border: none;
  cursor: pointer; font-family: inherit; text-align: left;
  color: inherit;
}
.sb-rest-info { flex: 1; min-width: 0; }
.sb-avatar { width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, #D62300, #FF8733); color: white; font-size: 13px; font-weight: 900; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.sb-rest-name { font-size: 12px; font-weight: 700; color: white; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sb-rest-addr { font-size: 10px; color: rgba(255,255,255,0.4); margin-top: 3px; line-height: 1.35; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.sb-rest-logout {
  flex-shrink: 0; width: 38px;
  display: flex; align-items: center; justify-content: center;
  background: transparent; border: none; border-left: 1px solid rgba(255,255,255,0.06);
  color: rgba(255,255,255,0.5); cursor: pointer;
  transition: color 0.15s, background 0.15s;
}
.sb-rest-logout:hover { color: #FF8733; background: rgba(255,255,255,0.04); }

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
.cab-spin { width: 28px; height: 28px; border: 3px solid #ede8e3; border-top-color: #D62300; border-radius: 50%; animation: spin 0.7s linear infinite; display: inline-block; }
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
.dash-card-time { font-size: 16px; font-weight: 700; color: #D62300; font-variant-numeric: tabular-nums; }
.dash-card-arrow { color: #D4C4B0; flex-shrink: 0; }

.dash-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 14px; margin-bottom: 24px; }
.dash-stat { background: white; border-radius: 16px; padding: 20px; text-align: center; cursor: pointer; border: 1px solid #EDE8E3; transition: all 0.18s; position: relative; overflow: hidden; }
.dash-stat::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 3px; background: #EDE8E3; transition: background 0.18s; }
.dash-stat:hover { box-shadow: 0 8px 32px rgba(80,35,20,0.1); transform: translateY(-2px); }
.dash-stat:hover::after { background: #D62300; }
.dash-stat-num { font-size: 28px; font-weight: 900; color: #502314; letter-spacing: -1px; }
.dash-stat-alert { color: #dc2626; }
.dash-stat-label { font-size: 10px; color: #8b7355; margin-top: 4px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }

.dash-actions { margin-bottom: 24px; }
.dash-section-title { font-size: 14px; font-weight: 800; color: #502314; margin: 0 0 12px; }
.dash-action-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 10px; }
.dash-action { display: flex; flex-direction: column; align-items: center; gap: 8px; background: white; border-radius: 16px; padding: 20px 12px; border: 1px solid #EDE8E3; cursor: pointer; font-family: inherit; font-size: 11px; font-weight: 700; color: #502314; text-decoration: none; transition: all 0.18s; }
.dash-action:hover { border-color: rgba(214,35,0,0.3); transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,0,0,0.06); }
.dash-action-icon { font-size: 28px; }
.dash-action--alert { border-color: rgba(214,35,0,0.2); }

.dash-recent { }
.dash-order { display: flex; justify-content: space-between; align-items: center; background: white; padding: 11px 18px; border-bottom: 1px solid #F5F2EE; transition: background 0.1s; cursor: pointer; }
.dash-order:hover { background: #FAF8F5; }
.dash-order:first-child { border-radius: 16px 16px 0 0; }
.dash-order:last-child { border-bottom: none; border-radius: 0 0 16px 16px; }
.dash-order-left { display: flex; align-items: center; gap: 10px; }
.dash-order-right { display: flex; align-items: center; gap: 10px; font-size: 12px; color: #8b7355; }
.dash-order-source { font-size: 9px; padding: 3px 8px; border-radius: 6px; font-weight: 700; }
.src-delivery { background: #FFF5F2; color: #D62300; }
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
.day-tab.active { background: #D62300; color: white; border-color: #D62300; box-shadow: 0 4px 16px rgba(214,35,0,0.25); }
.day-tab.active .day-tab-name, .day-tab.active .day-tab-date, .day-tab.active .day-tab-label { color: white; }
.day-tab.done { border-color: #16a34a; }
.day-tab.skipped { border-color: #9ca3af; background: #f5f5f5; }
.day-tab.active.skipped { background: #D62300; border-color: #D62300; }
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

.order-form { background: white; border-radius: 14px; margin-top: 6px; overflow: hidden; border: 1px solid #EDE8E3; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }

.deadline-bar { padding: 8px 14px; font-size: 12px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 6px; border-radius: 0; }
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
.input-search:focus { outline: none; border-color: #D62300; background: white; box-shadow: 0 0 0 3px rgba(214,35,0,0.06); }
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
.del-qty:focus { outline: none; border-color: #D62300; background: white; box-shadow: 0 0 0 3px rgba(214,35,0,0.06); }
.del-qty-err { border-color: #dc2626 !important; background: #fef2f2; }
.del-mult-hint { font-size: 10px; color: #dc2626; margin-top: 2px; }
.del-cmt { width: 100%; max-width: 180px; padding: 7px 10px; border: 1.5px solid #EDE8E3; border-radius: 8px; font-size: 12px; font-family: inherit; color: #502314; background: transparent; transition: border-color 0.15s; }
.del-cmt:focus { outline: none; border-color: #D62300; }
.del-cmt::placeholder { color: #D4C4B0; }
tr.del-filled { background: #FFFBF8; }
tr.del-err { background: #fef2f2; }

.btn-icon-danger { background: none; border: none; cursor: pointer; color: #dc2626; font-size: 18px; padding: 2px 4px; flex-shrink: 0; }
.empty-msg { padding: 32px; text-align: center; color: #8b7355; font-size: 13px; }

/* Delivery item-list extras */
.item-input-stack { display: flex; flex-direction: column; gap: 4px; align-items: flex-end; }
.item-cmt { width: 100px; padding: 4px 7px; border: 1.5px solid #e0dbd5; border-radius: 6px; font-size: 11px; font-family: inherit; color: #502314; }
.item-cmt:focus { outline: none; border-color: #D62300; box-shadow: 0 0 0 2px rgba(214,35,0,0.08); }
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
.btn-primary { background: #D62300; color: white; box-shadow: 0 2px 8px rgba(214,35,0,0.2); }
.btn-primary:hover:not(:disabled) { background: #b81e00; transform: translateY(-1px); box-shadow: 0 4px 16px rgba(214,35,0,0.25); }
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
.repeat-btn:hover { border-color: #D62300; color: #D62300; }

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
.item-qty:focus { outline: none; border-color: #D62300; box-shadow: 0 0 0 2px rgba(214,35,0,0.08); }
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
.history-card { background: white; padding: 14px 18px; border-bottom: 1px solid #F5F2EE; transition: background 0.1s; cursor: pointer; }
.history-card:hover { background: #FAF8F5; }
.history-card:first-child { border-radius: 16px 16px 0 0; }
.history-card:last-child { border-bottom: none; border-radius: 0 0 16px 16px; }
.history-top { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.history-date { font-weight: 700; color: #502314; font-size: 14px; }
.history-meta { display: flex; gap: 10px; font-size: 12px; color: #8b7355; margin-top: 4px; }
.history-time { margin-left: auto; }

/* Stock */
.stock-card { background: white; border-radius: 18px; padding: 32px 24px; margin: 0 0 16px; text-align: center; border: 1px solid #EDE8E3; }
.stock-card h2 { color: #502314; margin: 0 0 12px; }
.stock-card p { color: #8b7355; font-size: 14px; margin: 0; }
.stock-link { display: inline-flex; margin-top: 16px; }

/* Profile */
.profile-card { background: white; border-radius: 18px; padding: 20px; margin-bottom: 12px; display: flex; border: 1px solid #EDE8E3; }
.profile-header { display: flex; align-items: center; gap: 12px; }
.profile-avatar { width: 40px; height: 40px; border-radius: 10px; background: #D62300; color: white; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 800; flex-shrink: 0; }
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
.tg-code { font-size: 28px; font-weight: 800; color: #D62300; letter-spacing: 5px; font-variant-numeric: tabular-nums; margin: 6px 0; }
.tg-code-hint { font-size: 10px; color: #8b7355; margin: 0; }

.pw-form { display: flex; flex-direction: column; gap: 10px; }
.input-field { padding: 10px 14px; border: 1.5px solid #e0dbd5; border-radius: 8px; font-size: 14px; font-family: inherit; }
.input-field:focus { outline: none; border-color: #D62300; box-shadow: 0 0 0 2px rgba(214,35,0,0.08); }

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
.modal-search { width: 50%; min-width: 200px; margin-bottom: 12px; }
.add-list { display: flex; flex-direction: column; gap: 4px; }
.add-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; border-radius: 8px; cursor: pointer; border: 1px solid #f5f0eb; }
.add-item:hover { background: #f7f5f2; border-color: #D62300; }
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
.mob-tab.active { color: #D62300; }
.mob-tab-icon { font-size: 20px; }
.mob-tab-label { font-size: 9px; font-weight: 700; }
.mob-tab-badge {
  position: absolute; top: 2px; right: calc(50% - 16px);
  min-width: 16px; height: 16px; border-radius: 8px;
  background: #D62300; color: white; font-size: 9px; font-weight: 800;
  display: flex; align-items: center; justify-content: center;
}

/* ═══ Order comment ═══ */
.order-comment-row { margin-bottom: 12px; }
.order-comment-input {
  width: 100%; padding: 10px 14px; border: 1.5px solid #EDE8E3; border-radius: 10px;
  font-size: 13px; font-family: inherit; color: #502314; background: white;
  transition: border-color 0.15s;
}
.order-comment-input:focus { outline: none; border-color: #D62300; }
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
