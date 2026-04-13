import { defineStore } from 'pinia';
import { ref, computed } from 'vue';

export const useTruckLoadingStore = defineStore('truckLoading', () => {
  // --- Helpers ---

  function buildHeaders() {
    const h = { 'Content-Type': 'application/json' };
    const token = localStorage.getItem('bk_session_token');
    if (token) h['X-Session-Token'] = token;
    return h;
  }

  async function api(path, options = {}) {
    const res = await fetch(`/api/tl/${path}`, {
      headers: buildHeaders(),
      ...options,
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка');
    return data;
  }

  // --- State ---

  const deliveryDate = ref('');
  const orders = ref([]);
  const vehicles = ref([]);
  const plan = ref(null);
  const trucks = ref([]);
  const allowMixedModes = ref(false);
  const groupBy = ref('restaurant');
  const loading = ref(false);
  const saving = ref(false);
  // Фильтры по юрлицам. Если массив пустой — показываем все.
  const entityFilter = ref([]);

  // --- Computed ---

  const assignedKeys = computed(() => {
    const keys = new Set();
    for (const truck of trucks.value) {
      for (const a of (truck.assignments || [])) {
        if (a.assign_type === 'order') keys.add(`order_${a.order_id}`);
        else if (a.assign_type === 'category') keys.add(`cat_${a.order_id}_${a.category}`);
        else if (a.assign_type === 'item') keys.add(`item_${a.order_item_id}`);
      }
    }
    return keys;
  });

  function passesEntityFilter(order) {
    if (!entityFilter.value.length) return true;
    return entityFilter.value.includes(order.legal_entity);
  }

  const unassignedItems = computed(() => {
    const result = [];
    for (const order of orders.value) {
      if (!passesEntityFilter(order)) continue;
      const commonOrderFields = {
        legal_entity: order.legal_entity,
        legal_entity_group: order.legal_entity_group,
      };
      if (groupBy.value === 'restaurant') {
        if (assignedKeys.value.has(`order_${order.order_id}`)) continue;
        result.push({
          key: `order_${order.order_id}`,
          assign_type: 'order',
          order_id: order.order_id,
          restaurant_number: order.restaurant_number,
          city: order.city,
          address: order.address,
          region: order.region,
          pallets: order.total_pallets,
          weight_kg: order.total_weight,
          categories: order.categories,
          label: `Рест. ${order.restaurant_number}`,
          ...commonOrderFields,
        });
      } else if (groupBy.value === 'category') {
        for (const [cat, data] of Object.entries(order.categories || {})) {
          const key = `cat_${order.order_id}_${cat}`;
          if (assignedKeys.value.has(key)) continue;
          result.push({
            key,
            assign_type: 'category',
            order_id: order.order_id,
            category: cat,
            restaurant_number: order.restaurant_number,
            city: order.city,
            pallets: data.pallets,
            weight_kg: data.weight,
            label: `Рест. ${order.restaurant_number} — ${cat}`,
            ...commonOrderFields,
          });
        }
      } else {
        for (const item of (order.items || [])) {
          const key = `item_${item.item_id}`;
          if (assignedKeys.value.has(key)) continue;
          result.push({
            key,
            assign_type: 'item',
            order_item_id: item.item_id,
            order_id: order.order_id,
            restaurant_number: order.restaurant_number,
            city: order.city,
            category: item.category,
            pallets: item.pallets,
            weight_kg: item.weight,
            label: `${item.sku} ${item.product_name}`,
            sku: item.sku,
            product_name: item.product_name,
            quantity: item.quantity,
            ...commonOrderFields,
          });
        }
      }
    }
    return result;
  });

  // Список всех юрлиц, по которым есть заказы на дату (для UI-фильтра)
  const availableEntities = computed(() => {
    const seen = new Map();
    for (const o of orders.value) {
      if (!o.legal_entity) continue;
      if (!seen.has(o.legal_entity)) {
        seen.set(o.legal_entity, {
          legal_entity: o.legal_entity,
          legal_entity_group: o.legal_entity_group || 'BK_VM',
          orders_count: 0,
        });
      }
      seen.get(o.legal_entity).orders_count++;
    }
    return [...seen.values()];
  });

  const filteredOrders = computed(() => orders.value.filter(passesEntityFilter));

  const totalStats = computed(() => ({
    orders: filteredOrders.value.length,
    pallets: filteredOrders.value.reduce((s, o) => s + (o.total_pallets || 0), 0),
    weight: filteredOrders.value.reduce((s, o) => s + (o.total_weight || 0), 0),
  }));

  // --- Утилиты режимов хранения ---

  function categoryToMode(cat) {
    if (!cat) return null;
    if (cat === 'Сухой') return 'dry';
    if (cat === 'Холод') return 'cold';
    if (cat === 'Мороз') return 'frozen';
    return null;
  }

  function modeToCategory(mode) {
    if (mode === 'dry') return 'Сухой';
    if (mode === 'cold') return 'Холод';
    if (mode === 'frozen') return 'Мороз';
    return null;
  }

  // --- Расчёт статистики машины ---

  function truckStats(truck) {
    const assignments = truck.assignments || [];
    const pallets = assignments.reduce((s, a) => s + (parseFloat(a.pallets) || 0), 0);
    const weight = assignments.reduce((s, a) => s + (parseFloat(a.weight_kg) || 0), 0);
    const cap = truck.capacity_pallets || 33;
    const capKg = parseFloat(truck.capacity_kg) || 20000;
    const modes = new Set(assignments.map(a => a.category).filter(Boolean));
    return {
      pallets: +pallets.toFixed(1),
      weight: +weight.toFixed(1),
      percentPallets: cap > 0 ? Math.round(pallets / cap * 100) : 0,
      percentWeight: capKg > 0 ? Math.round(weight / capKg * 100) : 0,
      modes,
    };
  }

  // --- Валидация ---

  function canAssign(truckIndex, item) {
    const truck = trucks.value[truckIndex];
    if (!truck) return { ok: false, reason: 'Машина не найдена' };
    const stats = truckStats(truck);
    const newPallets = stats.pallets + (parseFloat(item.pallets) || 0);
    const newWeight = stats.weight + (parseFloat(item.weight_kg) || 0);

    if (newPallets > truck.capacity_pallets) return { ok: false, reason: 'Не хватает места (паллеты)' };
    if (newWeight > parseFloat(truck.capacity_kg)) return { ok: false, reason: 'Превышена грузоподъёмность' };

    if (!allowMixedModes.value && truck.mode !== 'any') {
      const itemMode = categoryToMode(item.category);
      if (itemMode && truck.mode !== itemMode) return { ok: false, reason: 'Нельзя смешивать режимы хранения' };
    }

    return { ok: true };
  }

  // --- API методы ---

  async function loadVehicles() {
    const data = await api('vehicles');
    vehicles.value = data.vehicles || [];
  }

  async function saveVehicle(v) {
    const data = await api('vehicles', { method: 'POST', body: JSON.stringify(v) });
    await loadVehicles();
    return data;
  }

  async function deleteVehicle(id) {
    await api(`vehicles/${id}`, { method: 'DELETE' });
    await loadVehicles();
  }

  async function loadOrders(date) {
    loading.value = true;
    try {
      const data = await api(`orders?date=${date}`);
      orders.value = data.orders || [];
    } finally {
      loading.value = false;
    }
  }

  async function loadPlan(date) {
    const data = await api(`plan?date=${date}`);
    if (data.plan) {
      plan.value = data.plan;
      trucks.value = (data.plan.trucks || []).map(t => ({ ...t, assignments: t.assignments || [] }));
      allowMixedModes.value = !!data.plan.allow_mixed_modes;
    } else {
      plan.value = null;
      trucks.value = [];
    }
  }

  async function loadDate(date) {
    deliveryDate.value = date;
    loading.value = true;
    try {
      await Promise.all([loadOrders(date), loadPlan(date)]);
    } finally {
      loading.value = false;
    }
  }

  async function savePlan() {
    saving.value = true;
    try {
      const data = await api('plan', {
        method: 'POST',
        body: JSON.stringify({
          delivery_date: deliveryDate.value,
          allow_mixed_modes: allowMixedModes.value,
          trucks: trucks.value.map((t, i) => ({
            vehicle_id: t.vehicle_id || null,
            custom_name: t.custom_name || null,
            capacity_pallets: t.capacity_pallets,
            capacity_kg: t.capacity_kg,
            mode: t.mode || 'any',
            sort_order: i,
            assignments: (t.assignments || []).map((a, j) => ({
              assign_type: a.assign_type,
              order_id: a.order_id || null,
              category: a.category || null,
              order_item_id: a.order_item_id || null,
              restaurant_number: a.restaurant_number,
              pallets: a.pallets,
              weight_kg: a.weight_kg,
              sort_order: j,
            })),
          })),
        }),
      });
      if (data.plan_id) {
        plan.value = { ...plan.value, id: data.plan_id };
      }
      return data;
    } finally {
      saving.value = false;
    }
  }

  async function deletePlan() {
    if (!plan.value?.id) return;
    await api(`plan/${plan.value.id}`, { method: 'DELETE' });
    plan.value = null;
    trucks.value = [];
  }

  async function confirmPlan() {
    if (!plan.value?.id) return;
    await api(`plan/${plan.value.id}/status`, { method: 'PATCH', body: JSON.stringify({ status: 'confirmed' }) });
    plan.value.status = 'confirmed';
  }

  async function unconfirmPlan() {
    if (!plan.value?.id) return;
    await api(`plan/${plan.value.id}/status`, { method: 'PATCH', body: JSON.stringify({ status: 'draft' }) });
    plan.value.status = 'draft';
  }

  async function autoAssign() {
    const data = await api('auto-assign', {
      method: 'POST',
      body: JSON.stringify({
        date: deliveryDate.value,
        vehicles: vehicles.value.map(v => ({ id: v.id, count: 99 })),
        allow_mixed: allowMixedModes.value,
      }),
    });
    if (data.trucks) {
      trucks.value = data.trucks.map(t => ({ ...t, assignments: t.assignments || [] }));
    }
    return data;
  }

  // --- Локальные мутации ---

  function addTruck(vehicle) {
    trucks.value.push({
      vehicle_id: vehicle?.id || null,
      custom_name: vehicle?.id ? null : (vehicle?.name || 'Пользовательская'),
      capacity_pallets: vehicle?.capacity_pallets || 33,
      capacity_kg: vehicle?.capacity_kg || 20000,
      mode: 'any',
      sort_order: trucks.value.length,
      assignments: [],
    });
  }

  function removeTruck(index) {
    trucks.value.splice(index, 1);
  }

  function assignToTruck(truckIndex, item) {
    const truck = trucks.value[truckIndex];
    if (!truck) return;
    truck.assignments.push({
      assign_type: item.assign_type,
      order_id: item.order_id || null,
      category: item.category || null,
      order_item_id: item.order_item_id || null,
      restaurant_number: item.restaurant_number,
      pallets: parseFloat(item.pallets) || 0,
      weight_kg: parseFloat(item.weight_kg) || 0,
      sort_order: truck.assignments.length,
    });
    if (!allowMixedModes.value && truck.mode === 'any' && item.category) {
      truck.mode = categoryToMode(item.category) || 'any';
    }
  }

  function unassign(truckIndex, assignIndex) {
    const truck = trucks.value[truckIndex];
    if (!truck) return;
    truck.assignments.splice(assignIndex, 1);
    if (truck.assignments.length === 0) truck.mode = 'any';
  }

  function moveAssignment(fromTruckIdx, toTruckIdx, assignIdx) {
    const from = trucks.value[fromTruckIdx];
    const to = trucks.value[toTruckIdx];
    if (!from || !to) return;
    const [item] = from.assignments.splice(assignIdx, 1);
    to.assignments.push(item);
    if (from.assignments.length === 0) from.mode = 'any';
  }

  function resetAllAssignments() {
    for (const truck of trucks.value) {
      truck.assignments = [];
      truck.mode = 'any';
    }
  }

  // --- Return ---

  return {
    // State
    deliveryDate,
    orders,
    vehicles,
    plan,
    trucks,
    allowMixedModes,
    groupBy,
    loading,
    saving,
    entityFilter,

    // Computed
    assignedKeys,
    unassignedItems,
    totalStats,
    availableEntities,
    filteredOrders,

    // Утилиты
    categoryToMode,
    modeToCategory,
    truckStats,
    canAssign,

    // API
    loadVehicles,
    saveVehicle,
    deleteVehicle,
    loadOrders,
    loadPlan,
    loadDate,
    savePlan,
    deletePlan,
    confirmPlan,
    unconfirmPlan,
    autoAssign,

    // Локальные мутации
    addTruck,
    removeTruck,
    assignToTruck,
    unassign,
    moveAssignment,
    resetAllAssignments,
  };
});
