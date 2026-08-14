<template>
  <div class="tld">
    <div class="tld-head">
      <h2 class="tld-title">Направления</h2>
      <p class="tld-hint">
        Направление — это набор городов, которые обслуживает один рейс. Портал раскладывает заказы так,
        чтобы рестораны разных направлений не попадали в одну машину.
      </p>
    </div>

    <div v-if="loading" class="tld-state"><BurgerSpinner text="Загрузка..." /></div>

    <div v-else-if="!directions.length && !editing" class="tld-state">
      Направлений пока нет — заказы раскладываются только по вместимости машин.
      <button type="button" class="tld-btn tld-btn-primary" @click="startNew">Создать первое направление</button>
    </div>

    <template v-else>
      <div class="tld-list">
        <div v-for="d in directions" :key="d.id" class="tld-card">
          <div class="tld-card-top">
            <span class="tld-card-name">{{ d.name }}</span>
            <span class="tld-card-count">{{ d.restaurants_count }} {{ restWord(d.restaurants_count) }}</span>
            <button type="button" class="tld-btn tld-btn-sm" @click="startEdit(d)">Изменить</button>
            <button type="button" class="tld-btn tld-btn-sm tld-btn-ghost" @click="$emit('delete', d)">Удалить</button>
          </div>
          <div class="tld-card-body">
            <span v-for="c in d.cities" :key="c" class="tld-tag">{{ c }}</span>
            <span v-for="n in d.include_restaurants" :key="'i' + n" class="tld-tag tld-tag-add">+ {{ restLabel(n) }}</span>
            <span v-for="n in d.exclude_restaurants" :key="'e' + n" class="tld-tag tld-tag-ex">− {{ restLabel(n) }}</span>
            <span v-if="!d.cities.length && !d.include_restaurants.length" class="tld-muted">города не выбраны</span>
          </div>
        </div>
      </div>

      <button v-if="!editing" type="button" class="tld-add" @click="startNew">+ Добавить направление</button>
    </template>

    <!-- Форма направления -->
    <div v-if="editing" class="tld-form">
      <div class="tld-form-head">{{ form.id ? 'Изменить направление' : 'Новое направление' }}</div>

      <label class="tld-field">
        <span>Название</span>
        <input v-model="form.name" class="tld-input" placeholder="Например, Витебск — Полоцк" ref="nameRef">
      </label>

      <div class="tld-field">
        <span>Города</span>
        <div class="tld-tags">
          <span v-for="c in form.cities" :key="c" class="tld-tag">
            {{ c }}<button type="button" class="tld-tag-x" @click="removeCity(c)">&times;</button>
          </span>
          <select class="tld-input tld-input-add" :value="''" @change="addCity($event)">
            <option value="">+ город</option>
            <option v-for="c in freeCities" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>
        <p class="tld-note">Все рестораны этих городов попадут в направление, включая новые.</p>
      </div>

      <div class="tld-field">
        <span>Добавить рестораны из других городов</span>
        <div class="tld-tags">
          <span v-for="n in form.include_restaurants" :key="n" class="tld-tag tld-tag-add">
            {{ restLabel(n) }}<button type="button" class="tld-tag-x" @click="removeFrom('include_restaurants', n)">&times;</button>
          </span>
          <select class="tld-input tld-input-add" :value="''" @change="addRest('include_restaurants', $event)">
            <option value="">+ ресторан</option>
            <option v-for="r in restaurantOptions" :key="r.number" :value="r.number">{{ restLabel(r.number) }}</option>
          </select>
        </div>
      </div>

      <div class="tld-field">
        <span>Исключить рестораны</span>
        <div class="tld-tags">
          <span v-for="n in form.exclude_restaurants" :key="n" class="tld-tag tld-tag-ex">
            {{ restLabel(n) }}<button type="button" class="tld-tag-x" @click="removeFrom('exclude_restaurants', n)">&times;</button>
          </span>
          <select class="tld-input tld-input-add" :value="''" @change="addRest('exclude_restaurants', $event)">
            <option value="">+ ресторан</option>
            <option v-for="r in cityRestaurants" :key="r.number" :value="r.number">{{ restLabel(r.number) }}</option>
          </select>
        </div>
        <p class="tld-note">Ресторан из выбранных городов, который этим рейсом не возят.</p>
      </div>

      <div class="tld-form-foot">
        <span class="tld-preview">Сейчас подходит {{ matchedCount }} {{ restWord(matchedCount) }}</span>
        <button type="button" class="tld-btn" @click="editing = false">Отмена</button>
        <button type="button" class="tld-btn tld-btn-primary" :disabled="!form.name.trim()" @click="save">Сохранить</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, nextTick } from 'vue';
import { formatRestaurantNumber } from '@/lib/legalEntities.js';

const props = defineProps({
  directions: { type: Array, default: () => [] },
  cities: { type: Array, default: () => [] },
  restaurants: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
});

const emit = defineEmits(['save', 'delete']);

const editing = ref(false);
const nameRef = ref(null);
const form = ref(blank());

function blank() {
  return { id: null, name: '', cities: [], include_restaurants: [], exclude_restaurants: [] };
}

function startNew() {
  form.value = blank();
  editing.value = true;
  nextTick(() => nameRef.value?.focus());
}

function startEdit(d) {
  form.value = {
    id: d.id,
    name: d.name,
    cities: [...(d.cities || [])],
    include_restaurants: [...(d.include_restaurants || [])],
    exclude_restaurants: [...(d.exclude_restaurants || [])],
  };
  editing.value = true;
  nextTick(() => nameRef.value?.focus());
}

function save() {
  emit('save', { ...form.value });
  editing.value = false;
}

// Города, которые ещё не в этом направлении и не заняты другим
const freeCities = computed(() => {
  const taken = new Set();
  for (const d of props.directions) {
    if (d.id === form.value.id) continue;
    for (const c of (d.cities || [])) taken.add(c);
  }
  return props.cities.filter(c => !form.value.cities.includes(c) && !taken.has(c));
});

// Рестораны не из выбранных городов — их можно добавить точечно
const restaurantOptions = computed(() => {
  const cities = new Set(form.value.cities);
  return props.restaurants.filter(r => !cities.has(r.city) && !form.value.include_restaurants.includes(r.number));
});

// Рестораны выбранных городов — их можно исключить
const cityRestaurants = computed(() => {
  const cities = new Set(form.value.cities);
  return props.restaurants.filter(r => cities.has(r.city) && !form.value.exclude_restaurants.includes(r.number));
});

const matchedCount = computed(() => {
  const cities = new Set(form.value.cities);
  const excluded = new Set(form.value.exclude_restaurants.map(String));
  const included = new Set(form.value.include_restaurants.map(String));
  let n = 0;
  for (const r of props.restaurants) {
    if (excluded.has(String(r.number))) continue;
    if (included.has(String(r.number)) || cities.has(r.city)) n++;
  }
  return n;
});

function addCity(e) {
  const v = e.target.value;
  e.target.value = '';
  if (v && !form.value.cities.includes(v)) form.value.cities.push(v);
}
function removeCity(c) {
  form.value.cities = form.value.cities.filter(x => x !== c);
}
function addRest(field, e) {
  const v = e.target.value;
  e.target.value = '';
  if (!v) return;
  const num = Number(v);
  if (!form.value[field].includes(num)) form.value[field].push(num);
}
function removeFrom(field, n) {
  form.value[field] = form.value[field].filter(x => x !== n);
}

function restLabel(number) {
  const r = props.restaurants.find(x => String(x.number) === String(number));
  const label = formatRestaurantNumber(number, r?.legal_entity_group);
  return r?.city ? `${label} ${r.city}` : String(label);
}

function restWord(n) {
  const m10 = n % 10, m100 = n % 100;
  if (m10 === 1 && m100 !== 11) return 'ресторан';
  if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return 'ресторана';
  return 'ресторанов';
}
</script>

<style scoped>
.tld { max-width: 900px; }
.tld-head { margin-bottom: 12px; }
.tld-title { margin: 0 0 2px; font-size: 15px; color: #502314; }
.tld-hint { margin: 0; font-size: 12px; color: #8b7355; max-width: 640px; }

.tld-state { padding: 28px; text-align: center; color: #8b7355; font-size: 13px; display: flex; flex-direction: column; align-items: center; gap: 10px; }

.tld-list { display: flex; flex-direction: column; gap: 8px; }
.tld-card { border: 1px solid #e6ddd2; border-radius: 8px; background: #fff; padding: 10px 12px; }
.tld-card-top { display: flex; align-items: center; gap: 8px; }
.tld-card-name { font-weight: 700; font-size: 14px; color: #502314; }
.tld-card-count { font-size: 11px; color: #8b7355; background: #f5f0eb; border-radius: 8px; padding: 1px 8px; margin-right: auto; }
.tld-card-body { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 8px; }
.tld-muted { font-size: 12px; color: #b0a396; }

.tld-tag { display: inline-flex; align-items: center; gap: 3px; background: #f1eae2; color: #502314; font-size: 11px; padding: 2px 8px; border-radius: 10px; }
.tld-tag-add { background: #e8f5e9; color: #1b5e20; }
.tld-tag-ex { background: #fdecea; color: #b71c1c; }
.tld-tag-x { border: none; background: none; color: inherit; opacity: 0.6; cursor: pointer; font-size: 13px; line-height: 1; padding: 0 1px; }
.tld-tag-x:hover { opacity: 1; }

.tld-add { margin-top: 10px; padding: 8px 14px; border: 1px dashed #ddd0c2; border-radius: 8px; background: #fff; color: #8b7355; font-size: 13px; cursor: pointer; }
.tld-add:hover { border-color: #E76F51; color: #E76F51; }

.tld-form { margin-top: 14px; border: 1px solid #e0d5c8; border-radius: 10px; background: #fff; padding: 14px; display: flex; flex-direction: column; gap: 12px; }
.tld-form-head { font-weight: 700; font-size: 14px; color: #502314; }
.tld-field { display: flex; flex-direction: column; gap: 5px; }
.tld-field > span { font-size: 11px; text-transform: uppercase; letter-spacing: 0.03em; color: #8b7355; }
.tld-tags { display: flex; flex-wrap: wrap; gap: 5px; align-items: center; }
.tld-note { margin: 0; font-size: 11px; color: #a89684; }

.tld-input { padding: 6px 9px; border: 1px solid #e0d5c8; border-radius: 6px; font-size: 13px; font-family: inherit; color: #333; }
.tld-input:focus { outline: none; border-color: #E76F51; box-shadow: 0 0 0 2px rgba(231,111,81,0.15); }
.tld-input-add { font-size: 12px; padding: 4px 8px; color: #8b7355; max-width: 200px; }

.tld-form-foot { display: flex; align-items: center; gap: 8px; }
.tld-preview { margin-right: auto; font-size: 12px; color: #8b7355; }

.tld-btn { padding: 6px 12px; border: 1px solid #e0d5c8; border-radius: 6px; background: #fff; font-size: 13px; color: #502314; cursor: pointer; white-space: nowrap; }
.tld-btn:hover { background: #faf6f1; }
.tld-btn-sm { padding: 4px 10px; font-size: 12px; }
.tld-btn-primary { background: #E76F51; border-color: #E76F51; color: #fff; }
.tld-btn-primary:hover:not(:disabled) { background: #d45f42; }
.tld-btn-primary:disabled { opacity: 0.5; cursor: default; }
.tld-btn-ghost { color: #8b7355; }
.tld-btn-ghost:hover { color: #c62828; border-color: #f0c4c4; background: #fdf2f2; }

@media (max-width: 700px) {
  .tld-card-top { flex-wrap: wrap; }
  .tld-card-count { margin-right: 0; }
  /* 16px — иначе телефон зумит страницу при попадании в поле */
  .tld-input { font-size: 16px; }
  .tld-input-add { font-size: 16px; max-width: none; }
}
</style>
