<template>
  <div class="ro-page">
    <div class="ro-header">
      <div class="ro-brand">
        <svg class="ro-logo" width="28" height="28" viewBox="5 5 38 38" xmlns="http://www.w3.org/2000/svg" fill="none">
          <circle cx="16" cy="16" r="10" fill="#D62300"/><circle cx="32" cy="16" r="10" fill="#F5A623"/>
          <circle cx="16" cy="32" r="10" fill="#FF8733"/><circle cx="32" cy="32" r="10" fill="#FFD54F"/>
          <circle cx="24" cy="24" r="8.5" fill="#502314"/>
          <text x="24" y="29" text-anchor="middle" fill="white" font-size="14" font-weight="900" font-family="Arial, sans-serif">S</text>
        </svg>
        <div>
          <div class="ro-header-title">Мои заказы — Ресторан {{ store.restaurant?.number }}</div>
        </div>
      </div>
      <div class="ro-header-actions">
        <router-link :to="{ name: 'restaurant-order-form' }" class="ro-link-btn">Новый заказ</router-link>
        <button class="ro-link-btn ro-logout-btn" @click="handleLogout">Выйти</button>
      </div>
    </div>

    <div v-if="loading" class="ro-loading">
      <div class="ro-spinner"></div>
      <span>Загрузка...</span>
    </div>

    <div v-else-if="!orders.length" class="ro-card ro-empty">
      <h2>Нет заказов</h2>
      <p>Вы ещё не подавали заявок.</p>
    </div>

    <div v-else class="ro-history-list">
      <div v-for="order in orders" :key="order.id" class="ro-history-card" @click="toggleOrder(order.id)">
        <div class="ro-history-header">
          <div>
            <div class="ro-history-date">{{ formatDate(order.delivery_date) }}</div>
            <div class="ro-history-meta">
              {{ order.item_count }} поз. / {{ order.total_qty }} кор.
              <span v-if="order.submitted_at" class="ro-history-time">— подано {{ formatTime(order.submitted_at) }}</span>
            </div>
          </div>
          <span class="ro-history-status" :class="'st-' + order.status">
            {{ statusLabels[order.status] || order.status }}
          </span>
        </div>

        <!-- Expanded: items -->
        <div v-if="expandedOrder === order.id" class="ro-history-items">
          <div v-if="loadingItems" class="ro-loading-small"><div class="ro-spinner"></div></div>
          <table v-else-if="orderItems.length" class="ro-table ro-table-compact">
            <thead>
              <tr>
                <th>Категория</th>
                <th>Название</th>
                <th>Кол-во</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in orderItems" :key="item.sku">
                <td class="ro-td-cat">{{ item.category }}</td>
                <td>{{ item.product_name }}</td>
                <td class="ro-td-qty-ro">{{ item.quantity }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useRestaurantOrderStore } from '@/stores/restaurantOrderStore.js';

const router = useRouter();
const store = useRestaurantOrderStore();

const loading = ref(true);
const orders = ref([]);
const expandedOrder = ref(null);
const orderItems = ref([]);
const loadingItems = ref(false);

const statusLabels = { draft: 'Черновик', submitted: 'Подано', edited: 'Изменён', locked: 'Заблокирован' };

onMounted(async () => {
  if (!store.isAuthenticated) {
    const valid = await store.validate();
    if (!valid) { router.replace({ name: 'restaurant-order-login' }); return; }
  }
  try {
    orders.value = await store.loadMyOrders(50);
  } finally {
    loading.value = false;
  }
});

async function toggleOrder(id) {
  if (expandedOrder.value === id) { expandedOrder.value = null; return; }
  expandedOrder.value = id;
  loadingItems.value = true;
  try {
    const order = orders.value.find(o => o.id === id);
    if (order) {
      const full = await store.loadMyOrder(order.delivery_date);
      orderItems.value = full?.items || [];
    }
  } finally {
    loadingItems.value = false;
  }
}

function handleLogout() {
  store.logout();
  router.replace({ name: 'restaurant-order-login' });
}

function formatDate(d) {
  if (!d) return '';
  return new Date(d + 'T00:00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', weekday: 'short' });
}
function formatTime(dt) {
  if (!dt) return '';
  return new Date(dt).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}
</script>

<style scoped>
.ro-page { min-height: 100vh; background: #f5f0eb; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; }
.ro-header {
  background: #502314; color: white; padding: 12px 20px;
  display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
}
.ro-brand { display: flex; align-items: center; gap: 10px; }
.ro-header-title { font-size: 16px; font-weight: 700; }
.ro-header-actions { display: flex; gap: 8px; }
.ro-link-btn {
  padding: 6px 14px; border-radius: 8px;
  border: 1px solid rgba(255,255,255,0.3);
  background: transparent; color: white;
  font-size: 13px; cursor: pointer; text-decoration: none; font-family: inherit;
}
.ro-link-btn:hover { background: rgba(255,255,255,0.1); }
.ro-logout-btn { border-color: rgba(255,100,100,0.4); }
.ro-loading { display: flex; align-items: center; justify-content: center; gap: 12px; padding: 60px; color: #8b7355; }
.ro-loading-small { padding: 20px; text-align: center; }
.ro-spinner { width: 24px; height: 24px; border: 3px solid #e0d5c8; border-top-color: #D62300; border-radius: 50%; animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.ro-card { background: white; border-radius: 12px; padding: 32px; margin: 20px; text-align: center; }
.ro-empty h2 { color: #502314; margin: 0 0 8px; }
.ro-empty p { color: #8b7355; margin: 0; }

.ro-history-list { padding: 16px; display: flex; flex-direction: column; gap: 8px; }
.ro-history-card {
  background: white; border-radius: 12px; padding: 14px 18px;
  cursor: pointer; transition: box-shadow 0.2s;
}
.ro-history-card:hover { box-shadow: 0 2px 12px rgba(80,35,20,0.08); }
.ro-history-header { display: flex; justify-content: space-between; align-items: center; }
.ro-history-date { font-size: 15px; font-weight: 600; color: #502314; }
.ro-history-meta { font-size: 12px; color: #8b7355; margin-top: 2px; }
.ro-history-time { opacity: 0.7; }
.ro-history-status {
  padding: 4px 10px; border-radius: 8px; font-size: 12px; font-weight: 600;
}
.st-submitted { background: #ecfdf5; color: #16a34a; }
.st-edited { background: #eff6ff; color: #2563eb; }
.st-draft { background: #f5f5f5; color: #666; }
.st-locked { background: #fef2f2; color: #dc2626; }

.ro-history-items { margin-top: 12px; border-top: 1px solid #f0ebe4; padding-top: 12px; }
.ro-table-compact { width: 100%; border-collapse: collapse; }
.ro-table-compact th { font-size: 11px; color: #8b7355; text-align: left; padding: 4px 8px; border-bottom: 1px solid #e0d5c8; }
.ro-table-compact td { font-size: 13px; padding: 4px 8px; border-bottom: 1px solid #f0ebe4; color: #502314; }
.ro-td-cat { font-size: 11px; color: #8b7355; }
.ro-td-qty-ro { font-weight: 600; }
</style>
