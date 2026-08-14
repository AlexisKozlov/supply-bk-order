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

  // Локальные ключи: v-for по индексу переиспользовал не те строки при
  // удалении машины из середины и при перетаскивании
  let uidCounter = 0;
  function nextUid() { return 'u' + (++uidCounter); }

  const deliveryDate = ref('');
  const orders = ref([]);
  const vehicles = ref([]);
  // Направления доставки: «Витебск — Полоцк» и т.п.
  const directions = ref([]);
  const plan = ref(null);
  const trucks = ref([]);
  // Машины обычно везут ресторану всю продукцию сразу, а не отдельно мороз и
  // сухой, поэтому смешивание режимов включено по умолчанию
  const allowMixedModes = ref(true);
  const groupBy = ref('restaurant');
  const loading = ref(false);
  const saving = ref(false);
  // Несохранённая раскладка: смена даты и уход со страницы раньше стирали её молча
  const dirty = ref(false);
  function markDirty() { dirty.value = true; }
  function markClean() { dirty.value = false; }
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

  // Сколько от заказа ещё не уехало. Автораспределение кладёт заказ по частям
  // (мороз отдельно, сухой отдельно), поэтому в режиме «целиком по ресторанам»
  // такой заказ раньше всё равно висел как нераспределённый целиком.
  function remainingParts(order) {
    const parts = [];
    for (const [cat, data] of Object.entries(order.categories || {})) {
      if (assignedKeys.value.has(`cat_${order.order_id}_${cat}`)) continue;
      let pallets = +data.pallets || 0;
      let weight = +data.weight || 0;
      // Позиции этой категории, уехавшие по отдельности, тоже вычитаем
      for (const item of (order.items || [])) {
        if (item.category !== cat) continue;
        if (!assignedKeys.value.has(`item_${item.item_id}`)) continue;
        pallets -= +item.pallets || 0;
        weight -= +item.weight || 0;
      }
      if (pallets <= 0.001 && weight <= 0.001) continue;
      parts.push({ category: cat, pallets: +pallets.toFixed(3), weight: +weight.toFixed(3) });
    }
    return parts;
  }

  const unassignedItems = computed(() => {
    const result = [];
    for (const order of orders.value) {
      if (!passesEntityFilter(order)) continue;
      const commonOrderFields = {
        legal_entity: order.legal_entity,
        legal_entity_group: order.legal_entity_group,
        direction_id: order.direction_id ?? null,
        direction_name: order.direction_name || '',
      };
      if (groupBy.value === 'restaurant') {
        if (assignedKeys.value.has(`order_${order.order_id}`)) continue;
        const parts = remainingParts(order);
        if (!parts.length) continue;
        const categories = {};
        let pallets = 0;
        let weight = 0;
        for (const p of parts) {
          categories[p.category] = { pallets: p.pallets, weight: p.weight };
          pallets += p.pallets;
          weight += p.weight;
        }
        const partial = parts.length !== Object.keys(order.categories || {}).length
          || pallets + 0.001 < (+order.total_pallets || 0);
        result.push({
          key: `order_${order.order_id}`,
          assign_type: 'order',
          order_id: order.order_id,
          restaurant_number: order.restaurant_number,
          city: order.city,
          address: order.address,
          region: order.region,
          pallets: +pallets.toFixed(3),
          weight_kg: +weight.toFixed(3),
          categories,
          // Часть заказа уже в машине: назначать надо оставшиеся куски по
          // отдельности, иначе уехавшее уедет второй раз
          partial,
          parts,
          label: `Рест. ${order.restaurant_number}`,
          ...commonOrderFields,
        });
      } else if (groupBy.value === 'category') {
        for (const [cat, data] of Object.entries(order.categories || {})) {
          const key = `cat_${order.order_id}_${cat}`;
          if (assignedKeys.value.has(key)) continue;
          if (assignedKeys.value.has(`order_${order.order_id}`)) continue;
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
          if (assignedKeys.value.has(`order_${order.order_id}`)) continue;
          if (assignedKeys.value.has(`cat_${order.order_id}_${item.category}`)) continue;
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
      freePallets: +(cap - pallets).toFixed(1),
      freeWeight: +(capKg - weight).toFixed(1),
      percentPallets: cap > 0 ? Math.round(pallets / cap * 100) : 0,
      percentWeight: capKg > 0 ? Math.round(weight / capKg * 100) : 0,
      modes,
    };
  }

  // Готовая статистика по всем машинам: шаблон берёт из карты, а не считает заново
  const statsByTruck = computed(() => {
    const map = {};
    for (const t of trucks.value) map[t.uid] = truckStats(t);
    return map;
  });

  // --- Валидация ---

  function canAssign(truckUid, item) {
    const truck = trucks.value.find(t => t.uid === truckUid);
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

  async function loadDirections() {
    const data = await api('directions');
    directions.value = data.directions || [];
    return data;
  }

  async function saveDirection(d) {
    const data = await api('directions', { method: 'POST', body: JSON.stringify(d) });
    await loadDirections();
    return data;
  }

  async function deleteDirection(id) {
    await api(`directions/${id}`, { method: 'DELETE' });
    await loadDirections();
  }

  // Направление ресторана считает сервер, здесь только подпись для интерфейса
  function directionName(id) {
    if (!id) return '';
    return directions.value.find(d => String(d.id) === String(id))?.name || '';
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

  // Раскладываем пришедший план в локальную форму: каждой машине и каждому
  // назначению даём свой ключ, иначе строки в списке путаются местами
  function adoptTrucks(list) {
    return (list || []).map(t => ({
      ...t,
      uid: nextUid(),
      assignments: (t.assignments || []).map(a => ({ ...a, uid: nextUid() })),
    }));
  }

  async function loadPlan(date) {
    const data = await api(`plan?date=${date}`);
    if (data.plan) {
      plan.value = data.plan;
      trucks.value = adoptTrucks(data.plan.trucks);
      allowMixedModes.value = !!data.plan.allow_mixed_modes;
    } else {
      plan.value = null;
      trucks.value = [];
    }
    markClean();
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
          // Слепок версии плана: сервер откажет, если его успели изменить
          updated_at: plan.value?.updated_at || null,
          trucks: trucks.value.map((t, i) => ({
            vehicle_id: t.vehicle_id || null,
            direction_id: t.direction_id || null,
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
              legal_entity_group: a.legal_entity_group || null,
              pallets: a.pallets,
              weight_kg: a.weight_kg,
              sort_order: j,
            })),
          })),
        }),
      });
      if (data.plan_id) {
        plan.value = { ...(plan.value || {}), id: data.plan_id, status: plan.value?.status || 'draft' };
      }
      if (data.updated_at) plan.value.updated_at = data.updated_at;
      markClean();
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
      trucks.value = adoptTrucks(data.trucks);
      markDirty();
    }
    return data;
  }

  // --- Локальные мутации ---

  function addTruck(vehicle, directionId = null) {
    trucks.value.push({
      uid: nextUid(),
      direction_id: directionId,
      // Подпись направления рисует карточка машины: без неё новый рейс
      // выглядел бы «без направления» до перезагрузки страницы
      direction_name: directionName(directionId),
      vehicle_id: vehicle?.id || null,
      custom_name: vehicle?.id ? null : (vehicle?.name || 'Пользовательская'),
      capacity_pallets: vehicle?.capacity_pallets || 33,
      capacity_kg: vehicle?.capacity_kg || 20000,
      mode: 'any',
      sort_order: trucks.value.length,
      assignments: [],
    });
    markDirty();
  }

  function removeTruck(truckUid) {
    const idx = trucks.value.findIndex(t => t.uid === truckUid);
    if (idx >= 0) trucks.value.splice(idx, 1);
    markDirty();
  }

  function assignToTruck(truckUid, item) {
    const truck = trucks.value.find(t => t.uid === truckUid);
    if (!truck) return;
    // Часть заказа уже уехала — кладём только оставшиеся режимы, каждый своей
    // строкой, иначе уехавшее задвоится
    if (item.assign_type === 'order' && item.partial && (item.parts || []).length) {
      for (const p of item.parts) {
        pushAssignment(truck, {
          ...item,
          assign_type: 'category',
          category: p.category,
          pallets: p.pallets,
          weight_kg: p.weight,
        });
      }
      markDirty();
      return;
    }
    pushAssignment(truck, item);
    markDirty();
  }

  function pushAssignment(truck, item) {
    truck.assignments.push({
      uid: nextUid(),
      direction_id: item.direction_id ?? null,
      // Пользователь может положить в рейс ресторан другого направления —
      // это разрешено, но должно быть видно. Ресторан без направления в рейсе
      // с направлением тоже «не по пути»: машина едет не туда
      foreign_direction: !!(truck.direction_id && String(truck.direction_id) !== String(item.direction_id ?? '')),
      assign_type: item.assign_type,
      order_id: item.order_id || null,
      category: item.category || null,
      order_item_id: item.order_item_id || null,
      restaurant_number: item.restaurant_number,
      legal_entity: item.legal_entity || null,
      legal_entity_group: item.legal_entity_group || null,
      sku: item.sku || null,
      product_name: item.product_name || null,
      pallets: parseFloat(item.pallets) || 0,
      weight_kg: parseFloat(item.weight_kg) || 0,
      sort_order: truck.assignments.length,
    });
    if (!allowMixedModes.value && truck.mode === 'any' && item.category) {
      truck.mode = categoryToMode(item.category) || 'any';
    }
  }

  function unassign(truckUid, assignmentUid) {
    const truck = trucks.value.find(t => t.uid === truckUid);
    if (!truck) return;
    const idx = truck.assignments.findIndex(a => a.uid === assignmentUid);
    if (idx < 0) return;
    truck.assignments.splice(idx, 1);
    if (truck.assignments.length === 0) truck.mode = 'any';
    markDirty();
  }

  function moveAssignment(fromUid, toUid, assignmentUid) {
    const from = trucks.value.find(t => t.uid === fromUid);
    const to = trucks.value.find(t => t.uid === toUid);
    if (!from || !to || from === to) return;
    const idx = from.assignments.findIndex(a => a.uid === assignmentUid);
    if (idx < 0) return;
    const [item] = from.assignments.splice(idx, 1);
    to.assignments.push(item);
    if (from.assignments.length === 0) from.mode = 'any';
    if (!allowMixedModes.value && to.mode === 'any' && item.category) {
      to.mode = categoryToMode(item.category) || 'any';
    }
    markDirty();
  }

  function resetAllAssignments() {
    for (const truck of trucks.value) {
      truck.assignments = [];
      truck.mode = 'any';
    }
    markDirty();
  }

  // Куда можно положить эту позицию: список машин с остатком места.
  // Нужен для назначения кликом — раньше работало только перетаскивание.
  function assignTargets(item) {
    return trucks.value.map((t, i) => {
      const check = canAssign(t.uid, item);
      const st = statsByTruck.value[t.uid] || truckStats(t);
      return {
        uid: t.uid,
        index: i,
        name: t.custom_name || ('Машина ' + (i + 1)),
        mode: t.mode,
        directionName: t.direction_name || '',
        // Рейс идёт в другую сторону — положить можно, но человек должен видеть
        foreign: !!(t.direction_id && String(t.direction_id) !== String(item.direction_id ?? '')),
        freePallets: st.freePallets,
        freeWeight: st.freeWeight,
        ok: check.ok,
        reason: check.reason || '',
      };
    });
  }

  // --- Return ---

  return {
    // State
    deliveryDate,
    orders,
    vehicles,
    directions,
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

    dirty,
    markClean,

    // Утилиты
    categoryToMode,
    modeToCategory,
    truckStats,
    statsByTruck,
    canAssign,
    assignTargets,

    // API
    loadVehicles,
    saveVehicle,
    deleteVehicle,
    loadDirections,
    saveDirection,
    deleteDirection,
    directionName,
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
