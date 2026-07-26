<template>
  <section class="cab-section rn-section">
    <div class="rn-head">
      <h2 class="rn-title"><span class="rn-newtag">NEW</span> Новинки</h2>
      <p class="rn-sub">Новые товары. Здесь можно заранее узнать, что скоро появится на складе.</p>
    </div>

    <div v-if="loading" class="rn-state"><BurgerSpinner text="Загрузка…" /></div>
    <div v-else-if="error" class="rn-state rn-error">
      {{ error }}
      <button class="rn-retry" @click="$emit('reload')">Повторить</button>
    </div>
    <div v-else-if="!items.length" class="rn-state">
      <div class="rn-empty-ico"><span class="rn-newtag rn-newtag-lg">NEW</span></div>
      <p>Пока новинок нет. Загляните позже.</p>
    </div>

    <div v-else class="rn-list">
      <article v-for="it in items" :key="it.product_id" class="rn-card">
        <div class="rn-photo" v-if="it.photo_url">
          <img :src="it.photo_url" :alt="it.name" loading="lazy" />
        </div>
        <div class="rn-photo rn-photo-ph" v-else><span>🍔</span></div>
        <div class="rn-body">
          <h3 class="rn-name">{{ title(it) }}</h3>
          <div class="rn-meta">
            <span v-if="it.sales_start_date" class="rn-start">Старт продаж: {{ fmtDate(it.sales_start_date) }}</span>
          </div>
          <p v-if="it.description" class="rn-desc">{{ it.description }}</p>
          <p v-else class="rn-desc rn-desc-empty">Подробности скоро добавят.</p>
        </div>
      </article>
    </div>
  </section>
</template>

<script setup>
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';

defineProps({
  items: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  error: { type: String, default: '' },
});
defineEmits(['reload']);

function fmtDate(d) {
  if (!d) return '';
  const dt = new Date(String(d).replace(' ', 'T'));
  if (isNaN(dt)) return '';
  return dt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}
// Артикул + название — единое целое, артикул всегда впереди.
function title(it) {
  const sku = (it.sku || '').trim();
  return sku ? sku + ' ' + it.name : it.name;
}
</script>

<style scoped>
.rn-section { max-width: 900px; }
.rn-head { margin-bottom: 16px; }
.rn-title { font-size: 20px; font-weight: 800; color: var(--text); margin: 0; display: flex; align-items: center; gap: 8px; }
.rn-newtag { display: inline-flex; align-items: center; font-size: 11px; font-weight: 800; letter-spacing: .6px; color: #fff; background: #E4572E; padding: 2px 7px; border-radius: 5px; line-height: 1.3; box-shadow: 0 1px 3px rgba(228,87,46,.35); }
.rn-newtag-lg { font-size: 15px; padding: 5px 12px; border-radius: 7px; }
.rn-sub { font-size: 13px; color: var(--text-muted); margin: 4px 0 0; }
.rn-state { text-align: center; padding: 48px 20px; color: var(--text-muted); }
.rn-error { color: #C0392B; }
.rn-retry { display: block; margin: 12px auto 0; padding: 7px 16px; border: none; border-radius: 8px; background: #502314; color: #fff; font-weight: 700; cursor: pointer; }
.rn-empty-ico { font-size: 44px; margin-bottom: 8px; }
.rn-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
.rn-card { background: var(--card); border: 1px solid var(--border-light); border-radius: 14px; overflow: hidden; display: flex; flex-direction: column; }
.rn-photo { width: 100%; aspect-ratio: 16 / 10; background: var(--bg); overflow: hidden; }
.rn-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
.rn-photo-ph { display: flex; align-items: center; justify-content: center; font-size: 48px; opacity: .35; }
.rn-body { padding: 12px 14px 16px; }
.rn-name { font-size: 15px; font-weight: 800; color: var(--text); margin: 0 0 6px; }
.rn-meta { display: flex; flex-wrap: wrap; gap: 6px 12px; margin-bottom: 8px; }
.rn-sku { font-size: 11.5px; font-weight: 700; color: #B26A00; }
.rn-start { font-size: 11.5px; font-weight: 700; color: #2E7D32; }
.rn-desc { font-size: 13px; color: var(--text); line-height: 1.5; margin: 0; white-space: pre-wrap; }
.rn-desc-empty { color: var(--text-muted); font-style: italic; }
</style>
