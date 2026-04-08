<template>
  <div class="rom-page">
    <div class="rom-toolbar">
      <h1>Заказы ресторанов</h1>
      <div class="rom-toolbar-actions">
        <button class="rom-btn rom-btn-outline" @click="copyRoLink" title="Ссылка для ресторанов">
          Ссылка /restaurant
        </button>
        <button class="rom-btn" @click="showUsersModal = true">Учётки</button>
        <button class="rom-btn rom-btn-primary" @click="handleAutoSession">
          {{ session ? 'Сессия активна' : 'Создать сессию' }}
        </button>
      </div>
    </div>

    <!-- Page tabs -->
    <div class="rom-page-tabs">
      <button class="rom-page-tab" :class="{ active: pageTab === 'orders' }" @click="pageTab = 'orders'">
        Заявки
      </button>
      <button class="rom-page-tab" :class="{ active: pageTab === 'templates' }" @click="pageTab = 'templates'; loadFullTemplates()">
        Шаблон заказа
      </button>
    </div>

    <!-- ═══ TAB: Orders ═══ -->
    <template v-if="pageTab === 'orders'">
      <!-- Date selector -->
      <div class="rom-date-row">
        <label>Дата доставки:</label>
        <input type="date" v-model="selectedDate" @change="loadStatus" />
        <button class="rom-btn-sm" @click="setTomorrow">Завтра</button>
        <button v-if="session" class="rom-btn-sm rom-btn-danger" @click="handleExtendDeadline">
          Продлить дедлайн
        </button>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="rom-loading">Загрузка...</div>

      <!-- No session -->
      <div v-else-if="!session" class="rom-empty">
        Нет активной сессии. Нажмите «Создать сессию» для открытия приёма заявок.
      </div>

    <template v-else>
      <!-- Stats bar -->
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
        <div class="rom-stat-deadline" :class="'dl-' + deadlineStatus?.status">
          {{ deadlineLabel }}
        </div>
      </div>

      <!-- Export + refresh -->
      <div class="rom-export-row">
        <button class="rom-btn rom-btn-export" @click="openExportModal" :disabled="exportExporting">
          Выгрузить в Excel
        </button>
        <router-link :to="{ name: 'restaurant-report' }" class="rom-btn" style="text-decoration:none">Отчёт</router-link>
        <button class="rom-btn" @click="loadStatus" :disabled="loading">
          {{ loading ? 'Обновление...' : 'Обновить' }}
        </button>
      </div>

      <!-- Restaurants table -->
      <div class="rom-table-wrap">
        <table class="rom-table">
          <thead>
            <tr>
              <th style="width:70px">Ресторан</th>
              <th>Город</th>
              <th>Адрес</th>
              <th style="width:90px">Статус</th>
              <th style="width:70px" class="rom-th-center">Позиций</th>
              <th style="width:70px" class="rom-th-center">Коробок</th>
              <th style="width:70px" class="rom-th-center">Вес, кг</th>
              <th style="width:70px" class="rom-th-center">Паллет</th>
              <th style="width:90px" class="rom-th-center">Подано</th>
              <th style="min-width:140px">Изменён</th>
              <th style="width:120px"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in restaurants" :key="r.number" :class="{ 'rom-row-submitted': r.order_status }">
              <td class="rom-td-num">{{ r.number }}</td>
              <td>{{ r.city }}</td>
              <td>{{ r.address }}</td>
              <td>
                <span class="rom-status" :class="'st-' + (r.order_status || 'none')">
                  {{ statusLabel(r.order_status) }}
                </span>
              </td>
              <td class="rom-td-center">{{ r.item_count || '—' }}</td>
              <td class="rom-td-center">{{ r.total_qty ? (+r.total_qty).toFixed(0) : '—' }}</td>
              <td class="rom-td-center">{{ r.total_weight ? (r.total_weight / 1000).toFixed(1) : '—' }}</td>
              <td class="rom-td-center">{{ r.pallets || '—' }}</td>
              <td class="rom-td-center rom-td-time">{{ r.submitted_at ? formatTime(r.submitted_at) : '—' }}</td>
              <td class="rom-td-time">
                <template v-if="r.updated_by">
                  {{ formatTime(r.updated_at) }} ({{ r.updated_by }})
                </template>
                <template v-else>—</template>
              </td>
              <td class="rom-td-actions">
                <button v-if="r.order_id" class="rom-btn-sm" @click="viewOrder(r.order_id)">
                  Открыть
                </button>
                <button v-if="r.order_id" class="rom-btn-sm rom-btn-export-sm" @click="quickExportOrder(r.order_id, r.number)">
                  Excel
                </button>
              </td>
            </tr>
          </tbody>
          <tfoot v-if="restaurants.some(r => r.order_status)">
            <tr class="rom-totals-row">
              <td colspan="4"><strong>Итого</strong></td>
              <td class="rom-td-center"><strong>{{ totalStats.items }}</strong></td>
              <td class="rom-td-center"><strong>{{ totalStats.boxes }}</strong></td>
              <td class="rom-td-center"><strong>{{ totalStats.weight }}</strong></td>
              <td class="rom-td-center"><strong>{{ totalStats.pallets }}</strong></td>
              <td colspan="3"></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </template>
    </template>

    <!-- ═══ TAB: Templates ═══ -->
    <template v-if="pageTab === 'templates'">
      <div class="rom-tpl-page">
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
            <button class="rom-btn" @click="handleImportFromStock">
              Импортировать из сроков годности
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
      <div class="rom-modal rom-modal-lg">
        <div class="rom-modal-header">
          <h2>Заказ ресторана {{ editingOrder?.restaurant_number }}</h2>
          <button class="rom-modal-close" @click="closeOrderModal">X</button>
        </div>
        <div class="rom-modal-body" v-if="editingOrder">
          <div class="rom-order-meta">
            <span>Дата доставки: <strong>{{ formatDate(editingOrder.delivery_date) }}</strong></span>
            <span>Статус: <strong>{{ statusLabel(editingOrder.status) }}</strong></span>
            <span v-if="editingOrder.updated_by" class="rom-meta-edited">
              Изменён: {{ formatTime(editingOrder.updated_at) }} ({{ editingOrder.updated_by }})
            </span>
          </div>

          <div v-for="cat in ['Сухой', 'Холод', 'Мороз']" :key="cat">
            <h3 class="rom-cat-title">{{ cat }}</h3>
            <table class="rom-table rom-table-edit" v-if="getEditItems(cat).length">
              <thead>
                <tr><th>Товар</th><th style="width:70px">Кол-во</th><th style="width:80px">Вес, кг</th><th>Комментарий</th><th></th></tr>
              </thead>
              <tbody>
                <tr v-for="(item, idx) in getEditItems(cat)" :key="idx">
                  <td class="rom-edit-product" @click="openReplaceProduct(item)" title="Нажмите, чтобы заменить товар">
                    <span class="rom-edit-sku">{{ item.sku }}</span> {{ item.product_name }}
                  </td>
                  <td><input v-model.number="item.quantity" type="number" min="0" step="0.5" class="rom-edit-qty" /></td>
                  <td class="rom-td-center rom-td-weight">{{ itemWeight(item) }}</td>
                  <td><input v-model="item.comment" type="text" class="rom-edit-comment" /></td>
                  <td><button class="rom-btn-sm rom-btn-danger" @click="removeEditItem(item)">X</button></td>
                </tr>
              </tbody>
            </table>
            <div v-else class="rom-no-items">Нет позиций</div>
            <button class="rom-btn-sm rom-btn-add-item" @click="openOrderAddProduct(cat)">+ Добавить товар</button>
          </div>

          <!-- Order totals -->
          <div class="rom-order-totals">
            <span>Коробок: <strong>{{ orderTotals.boxes }}</strong></span>
            <span>Вес: <strong>{{ orderTotals.weight }} кг</strong></span>
            <span>Паллет: <strong>{{ orderTotals.pallets }}</strong></span>
          </div>

          <div class="rom-modal-footer">
            <button class="rom-btn rom-btn-danger" @click="handleDeleteOrder(editingOrder)" :disabled="saving">
              Удалить заказ
            </button>
            <div style="flex:1"></div>
            <button class="rom-btn rom-btn-export" @click="exportSingleOrder(editingOrder)">
              Excel
            </button>
            <button class="rom-btn rom-btn-primary" @click="saveEditedOrder" :disabled="saving">
              {{ saving ? 'Сохранение...' : 'Сохранить изменения' }}
            </button>
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
      <div class="rom-modal">
        <div class="rom-modal-header">
          <h2>Учётные записи ресторанов</h2>
          <button class="rom-modal-close" @click="showUsersModal = false">X</button>
        </div>
        <div class="rom-modal-body">
          <div class="rom-bulk-row">
            <input v-model="bulkPassword" type="text" placeholder="Пароль для всех" class="rom-input" />
            <button class="rom-btn rom-btn-primary" @click="handleBulkCreate" :disabled="!bulkPassword">
              Создать для всех ресторанов
            </button>
          </div>
          <div class="rom-users-info" v-if="usersCount !== null">
            Создано учёток: {{ usersCount }}
          </div>

          <div v-if="usersList.length" class="rom-users-list">
            <div v-for="u in usersList" :key="u.restaurant_number" class="rom-user-row">
              <span class="rom-user-num">{{ u.restaurant_number }}</span>
              <span class="rom-user-addr">{{ u.city }} {{ u.address }}</span>
              <span class="rom-user-status" :class="{ active: u.is_active }">
                {{ u.is_active ? 'Активен' : 'Отключен' }}
              </span>
              <span v-if="u.last_login_at" class="rom-user-login">
                Вход: {{ formatTime(u.last_login_at) }}
              </span>
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
      <div class="rom-modal" style="max-width:650px">
        <div class="rom-modal-header">
          <h2>Настройки выгрузки в Excel</h2>
          <button class="rom-modal-close" @click="closeExportModal">&times;</button>
        </div>
        <div class="rom-modal-body">
          <div v-if="exportLoading" style="text-align:center; padding:20px"><div class="rom-spinner"></div></div>
          <template v-else>
            <!-- Grouping -->
            <div class="rom-exp-section-title">Группировка</div>
            <div class="rom-exp-grouping">
              <button class="rom-exp-grouping-opt" :class="{ active: exportGrouping === 'list' }" @click="exportGrouping = 'list'">Все списком</button>
              <button class="rom-exp-grouping-opt" :class="{ active: exportGrouping === 'restaurants' }" @click="exportGrouping = 'restaurants'">По ресторанам</button>
              <button class="rom-exp-grouping-opt" :class="{ active: exportGrouping === 'categories' }" @click="exportGrouping = 'categories'">По категориям</button>
            </div>

            <!-- Totals option -->
            <label class="rom-exp-cb-label" style="margin-bottom:12px">
              <input type="checkbox" v-model="exportShowTotals" />
              <span style="font-weight:600; color:#502314">Итоги по весу и паллетоместам</span>
            </label>

            <!-- Filters toggle -->
            <div class="rom-exp-section-title rom-exp-clickable" @click="exportShowFilters = !exportShowFilters">
              Фильтры
              <span class="rom-exp-chevron" :class="{ open: exportShowFilters }">&#9660;</span>
            </div>

            <div v-show="exportShowFilters">
              <!-- Category filter -->
              <div class="rom-exp-filter-group">
                <div class="rom-exp-filter-label">Категория</div>
                <div class="rom-exp-checkboxes">
                  <label v-for="cat in ['Сухой','Холод','Мороз']" :key="cat" class="rom-exp-cb-label">
                    <input type="checkbox" :checked="exportFilterCategories.has(cat)" @change="toggleSet(exportFilterCategories, cat)" /> {{ cat }}
                  </label>
                </div>
              </div>

              <!-- Region filter -->
              <div class="rom-exp-filter-group">
                <div class="rom-exp-filter-label">Регион</div>
                <div class="rom-exp-checkboxes">
                  <label v-for="reg in ['Минск','Регионы']" :key="reg" class="rom-exp-cb-label">
                    <input type="checkbox" :checked="exportFilterRegions.has(reg)" @change="toggleSet(exportFilterRegions, reg)" /> {{ reg }}
                  </label>
                </div>
              </div>

              <!-- Restaurant filter -->
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
                      <input type="checkbox" :checked="exportFilterRestaurants.has(r.number)" @change="toggleSet(exportFilterRestaurants, r.number)" />
                      <span style="font-weight:700; min-width:30px">{{ r.number }}</span>
                      <span style="flex:1; color:#502314">{{ r.city }}{{ r.address ? ', ' + r.address : '' }}</span>
                      <span style="font-size:10px; color:#8b7355">{{ r.region }}</span>
                    </label>
                    <div v-if="!filteredExportRestaurants.length" class="rom-no-items">Ничего не найдено</div>
                  </div>
                </template>
              </div>

              <!-- Product filter -->
              <div class="rom-exp-filter-group">
                <div class="rom-exp-filter-label">Товары</div>
                <label class="rom-exp-cb-label" style="margin-bottom:6px">
                  <input type="checkbox" v-model="exportAllProducts" /> Все
                </label>
                <template v-if="!exportAllProducts">
                  <div style="display:flex; gap:8px; margin-bottom:6px; align-items:center; flex-wrap:wrap">
                    <input v-model="exportProductSearch" type="text" placeholder="Поиск товара..." class="rom-input" style="flex:1; min-width:150px" />
                    <button class="rom-btn-sm" @click="expProductsSelectAll">Все</button>
                    <button class="rom-btn-sm" @click="exportFilterProducts = new Set()">Сбросить</button>
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
                      <input type="checkbox" :checked="exportFilterProducts.has(p.sku)" @change="toggleSet(exportFilterProducts, p.sku)" />
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
          </template>
        </div>
        <div v-if="!exportLoading" class="rom-exp-footer">
          <span class="rom-exp-summary">Заказов: <strong>{{ exportSummary.orders }}</strong>, Позиций: <strong>{{ exportSummary.items }}</strong></span>
          <button class="rom-btn rom-btn-export" @click="doUnifiedExport" :disabled="!exportSummary.items || exportExporting">
            {{ exportExporting ? 'Выгрузка...' : 'Скачать Excel' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, computed, watch, watchEffect } from 'vue';
import { useRestaurantOrderStore } from '@/stores/restaurantOrderStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { formatDate, formatTime, statusLabel, EXCEL_HEADER_STYLE, EXCEL_SUBTOTAL_STYLE, EXCEL_TOTAL_STYLE } from '@/lib/roUtils.js';
import * as XLSX from 'xlsx-js-style';

const store = useRestaurantOrderStore();
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

// Users
const showUsersModal = ref(false);
const bulkPassword = ref('');
const usersCount = ref(null);
const usersList = ref([]);

// Auto-refresh
let refreshInterval = null;

// Page tabs
const pageTab = ref('orders');

// Templates (full page)
const tplCategory = ref('Сухой');
const tplLegalEntity = ref('ООО "Бургер БК"');
const fullTemplateItems = ref([]);
const tplFilter = ref('');
const tplMessage = ref('');
const tplMessageOk = ref(false);
const tplSaving = ref(false);
const showTplAddModal = ref(false);
const tplAddSearch = ref('');
const tplAddResults = ref([]);
const tplAddTimer = ref(null);

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

const deadlineLabel = computed(() => {
  const s = deadlineStatus.value;
  if (!s) return '';
  const labels = { open: 'Приём открыт', warning: 'Дедлайн прошёл (ещё можно подать)', closed: 'Приём закрыт', not_yet: 'Ещё не начат' };
  return labels[s.status] || '';
});

onMounted(async () => {
  setTomorrow();
  await loadStatus();
  startAutoRefresh();
});

onUnmounted(() => {
  stopAutoRefresh();
  window.onbeforeunload = null;
});

// ═══ Unified export modal ═══
const showExportModal = ref(false);
const exportLoading = ref(false);
const exportExporting = ref(false);
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
const exportAvailableRestaurants = ref([]);
const exportAvailableProducts = ref([]);
let exportData = null;

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
watchEffect(() => {
  if (hasUnsavedChanges()) {
    window.onbeforeunload = (e) => { e.preventDefault(); return ''; };
  } else {
    window.onbeforeunload = null;
  }
});

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
    const data = await store.adminGetStatus(selectedDate.value);
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
    const result = await store.adminAutoSession();
    if (result.success) {
      await loadStatus();
    }
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
    await store.adminExtendDeadline(session.value.id, selectedDate.value, deadlineSoft.value + ':00', deadlineHard.value + ':00');
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
    showOrderModal.value = true;
  } catch (e) {
    toast.error('Ошибка', e.message);
  }
}

function getEditItems(cat) {
  return editItems.value.filter(i => i.category === cat);
}

function removeEditItem(item) {
  editItems.value = editItems.value.filter(i => i !== item);
}

function itemWeight(item) {
  const qty = parseFloat(item.quantity) || 0;
  const brutto = parseFloat(item.weight_brutto) || 0;
  if (!qty || !brutto) return '—';
  return (qty * brutto / 1000).toFixed(1);
}

const orderTotals = computed(() => {
  const items = editItems.value.filter(i => (parseFloat(i.quantity) || 0) > 0);
  const boxes = items.reduce((s, i) => s + (parseFloat(i.quantity) || 0), 0);
  const weight = items.reduce((s, i) => s + (parseFloat(i.quantity) || 0) * (parseFloat(i.weight_brutto) || 0), 0);
  const palletsByCategory = {};
  for (const item of items) {
    const bpp = parseFloat(item.boxes_per_pallet) || 0;
    const qty = parseFloat(item.quantity) || 0;
    if (bpp > 0) {
      const cat = item.category || 'Сухой';
      palletsByCategory[cat] = (palletsByCategory[cat] || 0) + qty / bpp;
    }
  }
  const pallets = Object.values(palletsByCategory).reduce((s, v) => s + (v > 0 ? Math.ceil(v) : 0), 0);
  return {
    boxes: boxes.toFixed(0),
    weight: (weight / 1000).toFixed(1),
    pallets,
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
      const products = await store.adminSearchProducts('ООО "Бургер БК"', orderAddSearch.value);
      const existing = new Set(editItems.value.map(i => i.sku));
      if (replacingItem.value) existing.delete(replacingItem.value.sku);
      orderAddResults.value = products.filter(p => !existing.has(p.sku));
    } catch { orderAddResults.value = []; }
  }, 300);
}

function pickOrderProduct(product) {
  if (replacingItem.value) {
    replacingItem.value.sku = product.sku;
    replacingItem.value.product_name = product.name || product.product_name;
    replacingItem.value.category = product.category || replacingItem.value.category;
    replacingItem.value = null;
  } else {
    editItems.value.push({
      sku: product.sku,
      product_name: product.name || product.product_name,
      category: product.category || 'Сухой',
      quantity: 1,
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

async function handleDeleteOrder(order) {
  if (!order?.id) return;
  if (!confirm(`Удалить заказ ресторана ${order.restaurant_number}?`)) return;
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
async function handleBulkCreate() {
  try {
    const result = await store.adminCreateBulkUsers(bulkPassword.value);
    usersCount.value = result.created;
    usersList.value = await store.adminGetUsers();
  } catch (e) {
    toast.error('Ошибка', e.message);
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
    tplMessage.value = `Импортировано: ${result.count} товаров в "${tplCategory.value}"`;
    tplMessageOk.value = true;
    setTimeout(() => { tplMessage.value = ''; }, 3000);
  } catch (e) {
    tplMessage.value = e.message;
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
const EXPORT_HEADER = ['Дата доставки', '№ заказа', '№ ресторана', 'Адрес ресторана', 'Время доставки', 'Хранение', 'Внешний код', 'Товар', 'Количество', 'Нетто (г)', 'Брутто (г)', 'Паллетоместа'];
const EXPORT_COLS = [{ wch: 14 }, { wch: 12 }, { wch: 10 }, { wch: 40 }, { wch: 14 }, { wch: 10 }, { wch: 14 }, { wch: 50 }, { wch: 12 }, { wch: 12 }, { wch: 12 }, { wch: 13 }];

function buildExportRows(orders, itemsByRest, restInfoMap, date, showTotals = false) {
  const rows = [EXPORT_HEADER];
  const subtotalRows = [];
  const sorted = [...orders].sort((a, b) => a.restaurant_number - b.restaurant_number);
  let grandBrutto = 0, grandPallets = 0;
  for (const order of sorted) {
    const oi = itemsByRest[order.restaurant_number] || [];
    if (!oi.length) continue;
    const ri = restInfoMap[order.restaurant_number] || {};
    const addr = ri.address || ri.city || '';
    const ordNum = `RO-${String(order.id).padStart(4, '0')}`;
    oi.sort((a, b) => (a.category || '').localeCompare(b.category || '') || (a.product_name || '').localeCompare(b.product_name || ''));
    let restBrutto = 0;
    const palletsByCategory = {};
    for (const item of oi) {
      const productCol = item.sku ? `${item.sku} ${item.product_name}` : item.product_name;
      const qty = parseFloat(item.quantity) || 0;
      const bpp = parseFloat(item.boxes_per_pallet) || 0;
      const brutto = item.weight_brutto ? qty * parseFloat(item.weight_brutto) : 0;
      const pallets = bpp > 0 ? qty / bpp : 0;
      restBrutto += brutto;
      const cat = item.category || 'Сухой';
      palletsByCategory[cat] = (palletsByCategory[cat] || 0) + pallets;
      rows.push([
        date, ordNum, order.restaurant_number, addr,
        ri.delivery_time || '', item.category,
        item.external_code || '', productCol, qty,
        item.weight_netto ? qty * parseFloat(item.weight_netto) : '',
        brutto || '',
        pallets > 0 ? +pallets.toFixed(2) : '',
      ]);
    }
    // Each category rounds up separately (different pallets per storage mode)
    const restPallets = Object.values(palletsByCategory).reduce((sum, v) => sum + (v > 0 ? Math.ceil(v) : 0), 0);
    if (showTotals) {
      const subRow = new Array(EXPORT_HEADER.length).fill('');
      subRow[7] = `Итого рест. ${order.restaurant_number}`;
      subRow[10] = restBrutto ? +restBrutto.toFixed(0) : '';
      subRow[11] = restPallets || '';
      rows.push(subRow);
      subtotalRows.push({ idx: rows.length - 1, type: 'subtotal' });
    }
    grandBrutto += restBrutto;
    grandPallets += restPallets;
  }
  if (showTotals && sorted.length > 1) {
    const totalRow = new Array(EXPORT_HEADER.length).fill('');
    totalRow[7] = 'ИТОГО';
    totalRow[10] = grandBrutto ? +grandBrutto.toFixed(0) : '';
    totalRow[11] = grandPallets ? +grandPallets.toFixed(2) : '';
    rows.push(totalRow);
    subtotalRows.push({ idx: rows.length - 1, type: 'total' });
  }
  return { rows, subtotalRows };
}

function styleExportSheet(ws, rowCount, subtotalRows) {
  for (let c = 0; c < EXPORT_HEADER.length; c++) {
    const cell = ws[XLSX.utils.encode_cell({ r: 0, c })];
    if (cell) cell.s = EXCEL_HEADER_STYLE;
  }
  for (const sr of (subtotalRows || [])) {
    const st = sr.type === 'total' ? EXCEL_TOTAL_STYLE : EXCEL_SUBTOTAL_STYLE;
    for (let c = 0; c < EXPORT_HEADER.length; c++) {
      const cell = ws[XLSX.utils.encode_cell({ r: sr.idx, c })];
      if (cell) cell.s = st;
    }
  }
  ws['!cols'] = EXPORT_COLS;
  ws['!autofilter'] = { ref: XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: rowCount - 1, c: EXPORT_HEADER.length - 1 } }) };
}

function buildSingleOrderXlsx(order, items) {
  const wb = XLSX.utils.book_new();
  const restInfo = { [order.restaurant_number]: { city: order.city || '', address: order.address || '', region: order.region || '', delivery_time: '' } };
  const byRest = { [order.restaurant_number]: items.filter(i => (parseFloat(i.quantity) || 0) > 0) };
  const { rows, subtotalRows } = buildExportRows([order], byRest, restInfo, order.delivery_date || selectedDate.value);
  const ws = XLSX.utils.aoa_to_sheet(rows);
  styleExportSheet(ws, rows.length, subtotalRows);
  XLSX.utils.book_append_sheet(wb, ws, `Рест ${order.restaurant_number}`);
  return wb;
}

function exportSingleOrder(order) {
  if (!order || !editItems.value.length) return;
  const wb = buildSingleOrderXlsx(order, editItems.value);
  XLSX.writeFile(wb, `Заказ_рест_${order.restaurant_number}_${order.delivery_date}.xlsx`);
}

async function quickExportOrder(orderId, restaurantNumber) {
  try {
    const order = await store.adminGetOrder(orderId);
    const items = (order.items || []).map(i => ({ ...i, quantity: parseFloat(i.quantity) || 0 }));
    if (!items.length) { toast.warning('Заказ пуст'); return; }
    const wb = buildSingleOrderXlsx(order, items);
    XLSX.writeFile(wb, `Заказ_рест_${restaurantNumber}_${selectedDate.value}.xlsx`);
  } catch (e) {
    toast.error('Ошибка', e.message);
  }
}

function copyRoLink() {
  const url = window.location.origin + '/restaurant';
  navigator.clipboard.writeText(url);
  toast.success('Ссылка скопирована', url);
}

// ═══ Unified export modal logic ═══
function toggleSet(setRef, value) {
  const s = new Set(setRef.value);
  if (s.has(value)) s.delete(value); else s.add(value);
  setRef.value = s;
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
  try {
    const data = await store.adminGetExportData('all', selectedDate.value);
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

    if (exportGrouping.value === 'list') {
      // Main sheet with all orders
      const { rows, subtotalRows } = buildExportRows(filteredOrders, byRest, restInfoMap, selectedDate.value, totals);
      const ws = XLSX.utils.aoa_to_sheet(rows);
      styleExportSheet(ws, rows.length, subtotalRows);
      XLSX.utils.book_append_sheet(wb, ws, 'Все заказы');
      // Additional sheet per restaurant
      const sorted = [...filteredOrders].sort((a, b) => a.restaurant_number - b.restaurant_number);
      for (const order of sorted) {
        const oi = byRest[order.restaurant_number] || [];
        if (!oi.length) continue;
        const r = buildExportRows([order], { [order.restaurant_number]: oi }, restInfoMap, selectedDate.value, true);
        const wsR = XLSX.utils.aoa_to_sheet(r.rows);
        styleExportSheet(wsR, r.rows.length, r.subtotalRows);
        XLSX.utils.book_append_sheet(wb, wsR, `Рест ${order.restaurant_number}`.slice(0, 31));
      }
    } else if (exportGrouping.value === 'restaurants') {
      const sorted = [...filteredOrders].sort((a, b) => a.restaurant_number - b.restaurant_number);
      for (const order of sorted) {
        const oi = byRest[order.restaurant_number] || [];
        if (!oi.length) continue;
        const { rows, subtotalRows } = buildExportRows([order], { [order.restaurant_number]: oi }, restInfoMap, selectedDate.value, totals);
        const ws = XLSX.utils.aoa_to_sheet(rows);
        styleExportSheet(ws, rows.length, subtotalRows);
        XLSX.utils.book_append_sheet(wb, ws, `Рест ${order.restaurant_number}`.slice(0, 31));
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
        const { rows, subtotalRows } = buildExportRows(catOrders, catByRest, restInfoMap, selectedDate.value, totals);
        const ws = XLSX.utils.aoa_to_sheet(rows);
        styleExportSheet(ws, rows.length, subtotalRows);
        XLSX.utils.book_append_sheet(wb, ws, cat);
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

/* Toolbar */
.rom-toolbar {
  display: flex; justify-content: space-between; align-items: center;
  margin-bottom: 20px; flex-wrap: wrap; gap: 12px;
}
.rom-toolbar h1 { margin: 0; font-size: 22px; color: #502314; }
.rom-toolbar-actions { display: flex; gap: 8px; flex-wrap: wrap; }

/* Buttons */
.rom-btn {
  padding: 8px 16px; border-radius: 8px; border: 1px solid #e0d5c8;
  background: white; cursor: pointer; font-size: 13px;
  font-family: inherit; color: #502314; transition: all 0.2s;
}
.rom-btn:hover { background: #f5f0eb; }
.rom-btn-primary { background: #D62300; color: white; border-color: #D62300; }
.rom-btn-primary:hover { background: #b81e00; }
.rom-btn-outline { border-style: dashed; }
.rom-btn-export { background: #f0fdf4; color: #16a34a; border-color: #16a34a; }
.rom-btn-export:hover { background: #dcfce7; }
.rom-btn-sm {
  padding: 4px 10px; border-radius: 6px; border: 1px solid #e0d5c8;
  background: white; cursor: pointer; font-size: 12px; font-family: inherit;
}
.rom-btn-danger { color: #dc2626; border-color: #dc2626; }

/* Date row */
.rom-date-row {
  display: flex; align-items: center; gap: 10px;
  margin-bottom: 16px; flex-wrap: wrap;
}
.rom-date-row label { font-size: 14px; font-weight: 600; color: #502314; }
.rom-date-row input[type="date"] {
  padding: 8px 12px; border: 2px solid #e0d5c8; border-radius: 8px;
  font-size: 14px; font-family: inherit;
}

/* Stats */
.rom-stats {
  display: flex; gap: 16px; margin-bottom: 16px;
  align-items: center; flex-wrap: wrap;
}
.rom-stat {
  background: white; padding: 12px 20px; border-radius: 10px;
  text-align: center; box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}
.rom-stat-value { display: block; font-size: 28px; font-weight: 700; color: #16a34a; }
.rom-stat-pending { color: #D62300; }
.rom-stat-label { font-size: 12px; color: #8b7355; }
.rom-stat-deadline {
  padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600;
}
.dl-open { background: #ecfdf5; color: #16a34a; }
.dl-warning { background: #fffbeb; color: #d97706; }
.dl-closed { background: #fef2f2; color: #dc2626; }
.dl-not_yet { background: #f0f9ff; color: #2563eb; }

/* Export row */
.rom-export-row { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }

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
.rom-modal-header {
  display: flex; justify-content: space-between; align-items: center;
  padding: 16px 20px; border-bottom: 1px solid #e0d5c8;
}
.rom-modal-header h2 { margin: 0; font-size: 18px; color: #502314; }
.rom-modal-close {
  background: none; border: none; cursor: pointer;
  font-size: 18px; color: #999; padding: 4px;
}
.rom-modal-body { padding: 20px; }
.rom-modal-footer { padding: 16px 0 0; display: flex; align-items: center; gap: 10px; }

/* Users modal */
.rom-bulk-row { display: flex; gap: 8px; margin-bottom: 12px; }
.rom-input {
  flex: 1; padding: 8px 12px; border: 2px solid #e0d5c8;
  border-radius: 8px; font-size: 14px; font-family: inherit;
}
.rom-users-info { color: #16a34a; font-size: 13px; margin-bottom: 12px; }
.rom-users-list { max-height: 300px; overflow-y: auto; }
.rom-user-row {
  display: flex; align-items: center; gap: 10px;
  padding: 6px 0; border-bottom: 1px solid #f0ebe4; font-size: 13px;
}
.rom-user-num { font-weight: 700; min-width: 30px; color: #502314; }
.rom-user-addr { flex: 1; color: #8b7355; }
.rom-user-status { font-size: 11px; padding: 2px 8px; border-radius: 4px; }
.rom-user-status.active { background: #ecfdf5; color: #16a34a; }
.rom-user-login { font-size: 11px; color: #8b7355; }

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
.rom-order-meta { display: flex; gap: 20px; margin-bottom: 16px; font-size: 14px; color: #502314; flex-wrap: wrap; }
.rom-meta-edited { font-size: 12px; color: #8b7355; }
.rom-cat-title { font-size: 14px; color: #D62300; margin: 16px 0 8px; }
.rom-table-edit td { padding: 4px 8px; }
.rom-edit-qty {
  width: 70px; padding: 4px 6px; border: 1px solid #e0d5c8;
  border-radius: 6px; font-size: 13px; text-align: center;
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
.rom-exp-section-title {
  font-size: 14px; font-weight: 700; color: #502314;
  margin: 16px 0 10px; padding: 0;
}
.rom-exp-section-title:first-child { margin-top: 0; }
.rom-exp-clickable { cursor: pointer; display: flex; align-items: center; gap: 8px; }
.rom-exp-chevron { font-size: 10px; transition: transform 0.2s; }
.rom-exp-chevron.open { transform: rotate(180deg); }
.rom-exp-grouping { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; }
.rom-exp-grouping-opt {
  padding: 8px 16px; border-radius: 8px; border: 2px solid #e0d5c8;
  background: white; cursor: pointer; font-size: 13px; font-family: inherit;
  font-weight: 600; color: #502314; transition: all 0.2s;
}
.rom-exp-grouping-opt.active { background: #502314; color: white; border-color: #502314; }
.rom-exp-grouping-opt:hover:not(.active) { background: #f5f0eb; }
.rom-exp-filter-group { margin-bottom: 14px; }
.rom-exp-filter-label { font-size: 13px; font-weight: 600; color: #8b7355; margin-bottom: 6px; display: block; }
.rom-exp-checkboxes { display: flex; gap: 16px; flex-wrap: wrap; align-items: center; }
.rom-exp-cb-label { display: flex; align-items: center; gap: 5px; font-size: 13px; color: #502314; cursor: pointer; }
.rom-exp-select-list { max-height: 200px; overflow-y: auto; border: 1px solid #ede8e3; border-radius: 8px; }
.rom-exp-select-list-tall { max-height: 300px; }
.rom-exp-select-item {
  display: flex; align-items: center; gap: 8px;
  padding: 7px 10px; border-bottom: 1px solid #f3eeea; cursor: pointer; font-size: 13px;
}
.rom-exp-select-item:hover { background: #faf7f4; }
.rom-exp-select-item.selected { background: #f0fdf4; }
.rom-exp-footer {
  display: flex; justify-content: space-between; align-items: center;
  padding: 12px 16px; border-top: 1px solid #ede8e3;
}
.rom-exp-summary { font-size: 13px; color: #502314; }

/* Order totals */
.rom-order-totals {
  display: flex; gap: 20px; padding: 12px 0; margin-top: 12px;
  border-top: 2px solid #e0d5c8; font-size: 14px; color: #502314;
}
.rom-td-weight { font-size: 12px; color: #8b7355; }

/* Totals row */
.rom-totals-row td {
  padding: 10px 12px !important; background: #faf7f4;
  border-top: 2px solid #e0d5c8; font-size: 13px; color: #502314;
}

/* Deadline modal */
.rom-deadline-fields { display: flex; flex-direction: column; gap: 12px; }
.rom-deadline-label { display: flex; flex-direction: column; gap: 4px; font-size: 13px; color: #502314; font-weight: 600; }
.rom-deadline-label input[type="time"] { width: 140px; }
</style>
