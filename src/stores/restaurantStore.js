import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { db } from '@/lib/apiClient.js';
import { useUserStore } from '@/stores/userStore.js';

export const useRestaurantStore = defineStore('restaurant', () => {
  const restaurants = ref([]);
  const schedule = ref([]);
  const loading = ref(false);
  const loaded = ref(false);
  const loadedGroup = ref('');

  function entityToGroup(legalEntity) {
    if (legalEntity && legalEntity.includes('Пицца Стар')) return 'PS';
    return 'BK_VM';
  }

  async function load(legalEntity) {
    const group = entityToGroup(legalEntity);
    if (loaded.value && loadedGroup.value === group) return;
    loading.value = true;
    try {
      const [rRes, sRes] = await Promise.all([
        db.from('restaurants').select('*').eq('legal_entity_group', group).order('sort_order'),
        db.from('delivery_schedule').select('*'),
      ]);
      if (rRes.error) throw new Error(rRes.error);
      restaurants.value = rRes.data || [];
      // Фильтруем расписание по загруженным ресторанам
      const ids = new Set(restaurants.value.map(r => String(r.id)));
      schedule.value = (sRes.data || []).filter(s => ids.has(String(s.restaurant_id)));
      loaded.value = true;
      loadedGroup.value = group;
    } catch (e) {
      console.error('restaurantStore load error:', e);
    } finally {
      loading.value = false;
    }
  }

  const scheduleByRestaurant = computed(() => {
    const map = new Map();
    for (const s of schedule.value) {
      const key = String(s.restaurant_id);
      if (!map.has(key)) map.set(key, new Map());
      map.get(key).set(Number(s.day_of_week), s);
    }
    return map;
  });

  const restaurantsByDay = computed(() => {
    const map = new Map();
    for (let d = 1; d <= 6; d++) map.set(d, []);
    for (const r of restaurants.value) {
      const rSched = scheduleByRestaurant.value.get(String(r.id));
      if (!rSched) continue;
      for (const [day, s] of rSched) {
        map.get(day)?.push({ ...r, delivery_time: s.delivery_time, schedule_notes: s.notes });
      }
    }
    return map;
  });

  const regions = computed(() => {
    const set = new Set();
    for (const r of restaurants.value) set.add(r.region);
    return [...set];
  });

  const lastUpdate = computed(() => {
    let latest = null;
    for (const s of schedule.value) {
      if (s.updated_at && (!latest || s.updated_at > latest.updated_at)) {
        latest = s;
      }
    }
    return latest ? { at: latest.updated_at, by: latest.updated_by } : null;
  });

  function _meta() {
    const userStore = useUserStore();
    const now = new Date();
    const pad = n => String(n).padStart(2, '0');
    return {
      updated_at: `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`,
      updated_by: userStore.currentUser?.name || 'unknown',
    };
  }

  async function saveScheduleCell(restaurantId, dayOfWeek, deliveryTime) {
    const rid = String(restaurantId);
    const existing = schedule.value.find(s => String(s.restaurant_id) === rid && Number(s.day_of_week) === dayOfWeek);
    const meta = _meta();

    if (!deliveryTime || deliveryTime.trim() === '') {
      if (existing) {
        const { error } = await db.from('delivery_schedule').delete().eq('id', existing.id);
        if (error) throw new Error(error);
        schedule.value = schedule.value.filter(s => s.id !== existing.id);
      }
      return;
    }

    if (existing) {
      const { data, error } = await db.from('delivery_schedule').update({
        delivery_time: deliveryTime.trim(), ...meta,
      }).eq('id', existing.id);
      if (error) throw new Error(error);
      Object.assign(existing, { delivery_time: deliveryTime.trim(), ...meta });
    } else {
      const { data, error } = await db.from('delivery_schedule').insert({
        restaurant_id: restaurantId,
        day_of_week: dayOfWeek,
        delivery_time: deliveryTime.trim(),
        ...meta,
      });
      if (error) throw new Error(error);
      if (data) schedule.value.push(data);
    }
  }

  async function saveRestaurant(restaurant) {
    const { id, ...fields } = restaurant;
    if (id) {
      const { data, error } = await db.from('restaurants').update(fields).eq('id', id);
      if (error) throw new Error(error);
      const idx = restaurants.value.findIndex(r => r.id === id);
      if (idx >= 0 && data?.[0]) restaurants.value[idx] = data[0];
      return data?.[0];
    } else {
      fields.sort_order = restaurants.value.length + 1;
      const { data, error } = await db.from('restaurants').insert(fields);
      if (error) throw new Error(error);
      if (data) restaurants.value.push(data);
      return data;
    }
  }

  async function deleteRestaurant(id) {
    const { error } = await db.from('restaurants').delete().eq('id', id);
    if (error) throw new Error(error);
    restaurants.value = restaurants.value.filter(r => r.id !== id);
    schedule.value = schedule.value.filter(s => String(s.restaurant_id) !== String(id));
  }

  function invalidate() {
    loaded.value = false;
    loadedGroup.value = '';
  }

  return {
    restaurants, schedule, loading, loaded,
    scheduleByRestaurant, restaurantsByDay, regions, lastUpdate,
    load, saveScheduleCell, saveRestaurant, deleteRestaurant, invalidate, entityToGroup,
  };
});
