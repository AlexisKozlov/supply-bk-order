<template>
  <div class="rsct">
    <div class="rsct-head">
      <div>
        <h2 class="rsct-title">Контакты поставщиков</h2>
        <div class="rsct-sub">Заполняет отдел закупок. Если контакт не работает или нужно его обновить — сообщите в отдел закупок.</div>
      </div>
      <button class="rsct-refresh" type="button" @click="reload" :disabled="loading">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M20.49 15A9 9 0 1 1 5.64 5.64L23 10"/></svg>
        <span>Обновить</span>
      </button>
    </div>

    <div v-if="loading && !groupsVisible.length" class="rsct-state">
      <div class="rsct-spinner"></div>
      <div>Загружаем контакты…</div>
    </div>

    <div v-else-if="error" class="rsct-state rsct-state--error">
      <div>{{ error }}</div>
      <button class="rsct-retry" type="button" @click="reload">Повторить</button>
    </div>

    <div v-else-if="!groupsVisible.length" class="rsct-state">
      <div class="rsct-empty-ico">📇</div>
      <div class="rsct-empty-title">Контакты ещё не добавлены</div>
      <div class="rsct-empty-sub">Когда отдел закупок добавит контакты поставщиков, они появятся тут.</div>
    </div>

    <div v-else class="rsct-groups">
      <details v-for="g in groupsVisible" :key="g.key" class="rsct-group" open>
        <summary class="rsct-group-head">
          <div class="rsct-group-title">
            <span class="rsct-group-name">{{ g.title }}</span>
            <span class="rsct-group-count">{{ g.contacts.length }}</span>
          </div>
          <div v-if="g.subtitle" class="rsct-group-sub">{{ g.subtitle }}</div>
        </summary>
        <div class="rsct-cards">
          <SupplierContactCard
            v-for="c in g.contacts"
            :key="c.id"
            :contact="c"
            :show-actions="false"
          />
        </div>
      </details>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import SupplierContactCard from '@/components/restaurant/SupplierContactCard.vue';
import { roFetch } from '@/lib/roUtils.js';

const loading = ref(false);
const error = ref('');
const groups = ref([]);

const groupsVisible = computed(() => groups.value.filter(g => (g.contacts || []).length > 0));

async function reload() {
  loading.value = true;
  error.value = '';
  try {
    const data = await roFetch('/api/restaurant-supplier-contacts/list');
    groups.value = Array.isArray(data?.groups) ? data.groups : [];
  } catch (e) {
    error.value = e.message || 'Не удалось загрузить контакты';
  } finally {
    loading.value = false;
  }
}

onMounted(reload);
</script>

<style scoped>
.rsct {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.rsct-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}
.rsct-title {
  font-size: 22px;
  font-weight: 700;
  color: #1f2937;
  margin: 0;
  line-height: 1.2;
}
.rsct-sub {
  margin-top: 6px;
  font-size: 13px;
  color: #6b7280;
  max-width: 600px;
}
.rsct-sub a { color: #2563eb; }
.rsct-refresh {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 38px;
  padding: 8px 14px;
  border-radius: 8px;
  border: 1px solid #d1d5db;
  background: #fff;
  color: #1f2937;
  cursor: pointer;
  font-size: 14px;
}
.rsct-refresh:hover:not(:disabled) { background: #f9fafb; }
.rsct-refresh:disabled { opacity: 0.5; cursor: progress; }

.rsct-state {
  padding: 48px 24px;
  text-align: center;
  background: #fff;
  border-radius: 12px;
  border: 1px solid #e5e7eb;
  color: #6b7280;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
}
.rsct-state--error { color: #b91c1c; background: #fef2f2; border-color: #fecaca; }
.rsct-spinner {
  width: 32px; height: 32px;
  border: 3px solid #e5e7eb; border-top-color: #6b7280;
  border-radius: 50%;
  animation: rsct-spin 0.7s linear infinite;
}
@keyframes rsct-spin { to { transform: rotate(360deg); } }
.rsct-empty-ico { font-size: 40px; }
.rsct-empty-title { font-size: 16px; font-weight: 600; color: #374151; }
.rsct-empty-sub { font-size: 14px; line-height: 1.5; }
.rsct-empty-sub a { color: #2563eb; }
.rsct-retry {
  margin-top: 4px;
  padding: 8px 16px;
  border-radius: 8px;
  background: #1f2937;
  color: #fff;
  border: none;
  cursor: pointer;
}

.rsct-groups {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
  gap: 12px;
  align-items: start;
}
.rsct-group {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  overflow: hidden;
}
.rsct-group-head {
  list-style: none;
  cursor: pointer;
  padding: 14px 16px;
  display: flex;
  flex-direction: column;
  gap: 4px;
  background: #f9fafb;
  border-bottom: 1px solid #e5e7eb;
}
.rsct-group-head::-webkit-details-marker { display: none; }
.rsct-group[open] .rsct-group-head { background: #f3f4f6; }
.rsct-group-title {
  display: flex;
  align-items: center;
  gap: 10px;
}
.rsct-group-name {
  font-size: 16px;
  font-weight: 600;
  color: #1f2937;
}
.rsct-group-count {
  font-size: 12px;
  padding: 2px 8px;
  border-radius: 999px;
  background: #e5e7eb;
  color: #4b5563;
}
.rsct-group-sub {
  font-size: 12px;
  color: #6b7280;
}
.rsct-cards {
  padding: 12px;
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
.rsct-cards > * {
  flex: 1 1 100%;
  max-width: 100%;
}

@media (max-width: 720px) {
  .rsct-title { font-size: 20px; }
  .rsct-groups { grid-template-columns: 1fr; }
  .rsct-cards { padding: 10px; gap: 10px; }
  .rsct-refresh { padding: 6px 12px; }
}
</style>
