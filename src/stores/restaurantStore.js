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
  let loadPromise = null;

  function entityToGroup(legalEntity) {
    if (legalEntity && legalEntity.includes('Пицца Стар')) return 'PS';
    return 'BK_VM';
  }

  async function load(legalEntity) {
    const group = entityToGroup(legalEntity);
    if (loaded.value && loadedGroup.value === group) return;
    // Защита от параллельных вызовов
    if (loading.value && loadPromise) return loadPromise;
    loading.value = true;
    loadPromise = _doLoad(group);
    try {
      await loadPromise;
    } finally {
      loadPromise = null;
    }
  }

  async function _doLoad(group) {
    try {
      const [rRes, sRes] = await Promise.all([
        db.from('restaurants').select('*').eq('legal_entity_group', group).order('sort_order'),
        db.from('delivery_schedule').select('*'),
      ]);
      if (rRes.error) throw new Error(rRes.error);
      if (sRes.error) throw new Error(sRes.error);
      restaurants.value = rRes.data || [];
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
        if (day >= 1 && day <= 6) {
          map.get(day)?.push({ ...r, delivery_time: s.delivery_time, schedule_notes: s.notes });
        }
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
    if (dayOfWeek < 1 || dayOfWeek > 6) throw new Error('Invalid day_of_week');
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
      // data может быть объектом или массивом — нормализуем
      const record = Array.isArray(data) ? data[0] : data;
      if (record && record.id) {
        schedule.value.push(record);
      } else {
        // Если API не вернул данные, перезагружаем
        invalidate();
        await load();
      }
    }
  }

  // Только поля таблицы restaurants (без лишних из restaurantsByDay)
  const restaurantFields = ['id', 'number', 'address', 'city', 'region', 'notes', 'sort_order', 'legal_entity_group'];

  async function saveRestaurant(restaurant) {
    // Убираем лишние поля (delivery_time, schedule_notes и т.д.)
    const clean = {};
    for (const key of restaurantFields) {
      if (key in restaurant) clean[key] = restaurant[key];
    }
    const { id, ...fields } = clean;
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
      const record = Array.isArray(data) ? data[0] : data;
      if (record && record.id) restaurants.value.push(record);
      return record;
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
