<template>
  <section class="cab-section gd-wrap">
    <div class="gd-head">
      <h1 class="gd-title">Инструкции</h1>
      <p class="gd-sub">Как работать с кабинетом закупок. Выберите тему — внутри пошагово, с картинками.</p>
    </div>

    <div v-if="loading" class="gd-state"><BurgerSpinner text="Загрузка..." /></div>
    <div v-else-if="error" class="gd-state gd-error">{{ error }}</div>
    <div v-else-if="!guides.length" class="gd-state gd-soon">
      <div class="gd-soon-badge">Раздел в разработке</div>
      <p class="gd-soon-text">
        Здесь появятся пошаговые инструкции: как подать заявку поставщику, отправить корректировку,
        заполнить сбор остатков и оформить возврат кег.<br>
        Пока вопросы — в поддержку через кнопку «Помощь» внизу меню.
      </p>
    </div>

    <!-- Список тем -->
    <template v-else-if="!openGuide">
      <div class="gd-search">
        <input v-model="query" class="gd-search-input" placeholder="Поиск по инструкциям..." />
      </div>
      <div v-if="!filtered.length" class="gd-state">Ничего не найдено по «{{ query }}»</div>
      <button v-for="g in filtered" :key="g.id" class="gd-card" @click="open(g)">
        <span class="gd-card-icon"><BkIcon :name="g.icon_key || 'document'" size="md" /></span>
        <span class="gd-card-text">
          <span class="gd-card-title">{{ g.title }}</span>
          <span v-if="g.summary" class="gd-card-sum">{{ g.summary }}</span>
          <span class="gd-card-steps">{{ g.steps.length }} {{ stepWord(g.steps.length) }}</span>
        </span>
        <span class="gd-card-arrow">›</span>
      </button>
    </template>

    <!-- Одна инструкция -->
    <template v-else>
      <button class="gd-back" @click="closeGuide">‹ Все инструкции</button>
      <h2 class="gd-guide-title">{{ openGuide.title }}</h2>
      <p v-if="openGuide.summary" class="gd-guide-sum">{{ openGuide.summary }}</p>

      <div v-for="(s, i) in openGuide.steps" :key="s.id" class="gd-step">
        <div class="gd-step-head">
          <span class="gd-step-num">{{ i + 1 }}</span>
          <span v-if="s.title" class="gd-step-title">{{ s.title }}</span>
        </div>
        <div class="gd-step-body" v-html="renderBody(s.body)"></div>
        <img v-if="s.image_path" :src="'/api/' + s.image_path" class="gd-step-img"
             :alt="s.title || ('Шаг ' + (i + 1))" loading="lazy" @click="zoom = '/api/' + s.image_path" />
      </div>

      <div class="gd-foot">
        <button class="gd-back" @click="closeGuide">‹ Все инструкции</button>
      </div>
    </template>

    <!-- Картинка во весь экран -->
    <div v-if="zoom" class="gd-zoom" @click="zoom = null">
      <img :src="zoom" alt="" />
    </div>
  </section>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import BkIcon from '@/components/ui/BkIcon.vue';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';

// Какая тема открыта, решает родитель: от этого зависит адрес страницы,
// чтобы ссылкой на инструкцию можно было поделиться.
const props = defineProps({ openId: { type: [Number, null], default: null } });
const emit = defineEmits(['update:openId']);

const guides = ref([]);
const loading = ref(true);
const error = ref('');
const query = ref('');
const zoom = ref(null);

const openGuide = computed(() => guides.value.find(g => Number(g.id) === Number(props.openId)) || null);

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase();
  if (!q) return guides.value;
  return guides.value.filter(g =>
    (g.title + ' ' + (g.summary || '') + ' ' + g.steps.map(s => s.title + ' ' + s.body).join(' '))
      .toLowerCase().includes(q)
  );
});

function stepWord(n) {
  const n10 = n % 10, n100 = n % 100;
  if (n10 === 1 && n100 !== 11) return 'шаг';
  if (n10 >= 2 && n10 <= 4 && (n100 < 12 || n100 > 14)) return 'шага';
  return 'шагов';
}

function open(g) {
  emit('update:openId', Number(g.id));
  window.scrollTo({ top: 0, behavior: 'smooth' });
}
function closeGuide() { emit('update:openId', null); }

// Текст шага пишет закупщик обычным текстом. Разрешаем только перенос строк
// и **жирный** — вставить разметку со стороны нельзя, всё остальное экранируем.
function renderBody(text) {
  const esc = String(text || '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  return esc
    .replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>')
    .replace(/\n/g, '<br>');
}

onMounted(async () => {
  try {
    const r = await fetch('/api/ro/guides', { credentials: 'include' });
    const data = await r.json();
    if (!r.ok) throw new Error(data.error || 'Не удалось загрузить инструкции');
    guides.value = (data.guides || []).map(g => ({ ...g, steps: g.steps || [] }));
  } catch (e) {
    error.value = e.message || String(e);
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.gd-wrap { padding: 4px 0 40px; }
.gd-head { margin-bottom: 16px; }
.gd-title { margin: 0 0 4px; font-size: 26px; font-weight: 800; color: var(--ro-brown, #502314); }
.gd-sub { margin: 0; font-size: 14px; color: #7d6a5c; line-height: 1.45; }

.gd-state { padding: 32px 12px; text-align: center; color: #7d6a5c; }
.gd-soon { padding: 28px 18px; background: #fff; border: 1px solid #ece3d8; border-radius: 14px; }
.gd-soon-badge {
  display: inline-block; padding: 5px 12px; border-radius: 999px;
  background: #FFE7C7; color: #8a5322; font-size: 12px; font-weight: 800;
  letter-spacing: .5px; margin-bottom: 12px;
}
.gd-soon-text { margin: 0; font-size: 14px; line-height: 1.6; color: #6d5a4c; }
.gd-error { color: #c62828; }

.gd-search { margin-bottom: 12px; }
.gd-search-input {
  width: 100%; box-sizing: border-box; padding: 11px 14px;
  border: 1px solid #e3d9cc; border-radius: 12px; background: #fff;
  font: inherit; font-size: 15px;
}

/* Список тем */
.gd-card {
  display: flex; align-items: center; gap: 12px; width: 100%; text-align: left;
  background: #fff; border: 1px solid #ece3d8; border-radius: 14px;
  padding: 14px 16px; margin-bottom: 10px; cursor: pointer; font: inherit;
  transition: border-color .15s, transform .05s;
}
.gd-card:hover { border-color: #e0b48a; }
.gd-card:active { transform: scale(0.995); }
.gd-card-icon { flex: 0 0 auto; display: flex; }
.gd-card-text { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
.gd-card-title { font-size: 16px; font-weight: 700; color: var(--ro-brown, #502314); }
.gd-card-sum { font-size: 13px; color: #7d6a5c; line-height: 1.4; }
.gd-card-steps { font-size: 12px; color: #a1907f; margin-top: 2px; }
.gd-card-arrow { color: #c3b2a1; font-size: 22px; flex: 0 0 auto; }

/* Одна инструкция */
.gd-back {
  background: none; border: none; padding: 6px 0; margin-bottom: 8px;
  color: #b4693f; font: inherit; font-size: 14px; font-weight: 600; cursor: pointer;
}
.gd-guide-title { margin: 0 0 4px; font-size: 22px; font-weight: 800; color: var(--ro-brown, #502314); }
.gd-guide-sum { margin: 0 0 18px; font-size: 14px; color: #7d6a5c; line-height: 1.45; }

.gd-step {
  background: #fff; border: 1px solid #ece3d8; border-radius: 14px;
  padding: 14px 16px; margin-bottom: 12px;
}
.gd-step-head { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
.gd-step-num {
  flex: 0 0 auto; width: 26px; height: 26px; border-radius: 50%;
  background: var(--ro-brown, #502314); color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: 14px; font-weight: 700;
}
.gd-step-title { font-size: 16px; font-weight: 700; color: var(--ro-brown, #502314); }
.gd-step-body { font-size: 15px; line-height: 1.55; color: #3f3128; }
.gd-step-img {
  display: block; width: 100%; margin-top: 12px; border-radius: 10px;
  border: 1px solid #ece3d8; cursor: zoom-in;
}
.gd-foot { margin-top: 18px; }

/* Просмотр картинки целиком: на телефоне мелкие детали иначе не разглядеть */
.gd-zoom {
  position: fixed; inset: 0; z-index: 3000; background: rgba(20, 12, 8, 0.92);
  display: flex; align-items: center; justify-content: center; padding: 16px; cursor: zoom-out;
}
.gd-zoom img { max-width: 100%; max-height: 100%; border-radius: 8px; }

@media (max-width: 640px) {
  .gd-title { font-size: 22px; }
  .gd-card { padding: 12px 14px; }
  .gd-step { padding: 12px 14px; }
}
</style>
