<template>
  <div class="ge">
    <div class="ge-top">
      <div>
        <h3 class="ge-h">Инструкции для ресторанов</h3>
        <p class="ge-hint">
          Показываются в кабинете ресторана в разделе «Инструкции».
          Пока ни одна тема не опубликована, ресторан видит пометку «Раздел в разработке».
        </p>
      </div>
      <button class="ge-btn ge-btn-primary" @click="startNew" :disabled="loading">+ Новая инструкция</button>
    </div>

    <div v-if="loading" class="ge-state"><BurgerSpinner text="Загрузка..." /></div>
    <div v-else-if="!guides.length && !editing" class="ge-state">
      Инструкций пока нет. Нажмите «Новая инструкция».
    </div>

    <!-- Список тем -->
    <div v-if="!editing && guides.length" class="ge-list">
      <div v-for="g in guides" :key="g.id" class="ge-item">
        <span class="ge-item-icon"><BkIcon :name="g.icon_key || 'document'" size="sm" /></span>
        <div class="ge-item-text">
          <div class="ge-item-title">
            {{ g.title }}
            <span v-if="!g.is_published" class="ge-chip">черновик</span>
            <span v-if="g.target_group" class="ge-chip ge-chip-group">{{ groupLabel(g.target_group) }}</span>
          </div>
          <div class="ge-item-sub">{{ g.summary || 'без описания' }} · {{ g.steps.length }} шаг(ов)</div>
        </div>
        <div class="ge-item-actions">
          <button class="ge-btn" @click="moveGuide(g, -1)" :disabled="saving" title="Выше">↑</button>
          <button class="ge-btn" @click="moveGuide(g, 1)" :disabled="saving" title="Ниже">↓</button>
          <button class="ge-btn" @click="edit(g)">Изменить</button>
          <button class="ge-btn ge-btn-danger" @click="remove(g)" :disabled="saving">Удалить</button>
        </div>
      </div>
    </div>

    <!-- Редактор темы -->
    <div v-if="editing" class="ge-editor">
      <div class="ge-row">
        <label class="ge-field ge-field-grow">
          <span class="ge-label">Название</span>
          <input v-model="form.title" class="ge-input" placeholder="Например: Как подать заявку поставщику" />
        </label>
        <label class="ge-field">
          <span class="ge-label">Иконка</span>
          <select v-model="form.icon_key" class="ge-input">
            <option v-for="ic in iconChoices" :key="ic.key" :value="ic.key">{{ ic.label }}</option>
          </select>
        </label>
      </div>

      <label class="ge-field">
        <span class="ge-label">Короткое описание</span>
        <input v-model="form.summary" class="ge-input" placeholder="Одна строка — видна в списке тем" />
      </label>

      <div class="ge-row">
        <label class="ge-field">
          <span class="ge-label">Кому показывать</span>
          <select v-model="form.target_group" class="ge-input">
            <option :value="null">Всем ресторанам</option>
            <option value="BK_VM">Только Бургер БК / Воглия Матта</option>
            <option value="PS">Только Пицца Стар</option>
          </select>
        </label>
        <label class="ge-check">
          <input type="checkbox" v-model="form.is_published" />
          <span>Опубликовать (рестораны увидят тему)</span>
        </label>
      </div>

      <div class="ge-steps-head">
        <h4 class="ge-h4">Шаги</h4>
        <button class="ge-btn" @click="addStep">+ Шаг</button>
      </div>

      <div v-for="(st, i) in form.steps" :key="i" class="ge-step">
        <div class="ge-step-top">
          <span class="ge-step-num">{{ i + 1 }}</span>
          <input v-model="st.title" class="ge-input ge-step-title" placeholder="Заголовок шага (можно оставить пустым)" />
          <button class="ge-btn" @click="moveStep(i, -1)" :disabled="i === 0" title="Выше">↑</button>
          <button class="ge-btn" @click="moveStep(i, 1)" :disabled="i === form.steps.length - 1" title="Ниже">↓</button>
          <button class="ge-btn ge-btn-danger" @click="removeStep(i)" title="Удалить шаг">×</button>
        </div>
        <textarea v-model="st.body" class="ge-input ge-textarea" rows="3"
          placeholder="Что нужно сделать. Двойные звёздочки делают текст жирным: **важно**"></textarea>

        <div class="ge-step-img">
          <img v-if="st.image_path" :src="'/api/' + st.image_path" class="ge-thumb" alt="" />
          <label class="ge-btn ge-upload">
            {{ st.image_path ? 'Заменить картинку' : 'Добавить картинку' }}
            <input type="file" accept="image/png,image/jpeg,image/webp" hidden @change="uploadImage($event, st)" />
          </label>
          <button v-if="st.image_path" class="ge-btn ge-btn-danger" @click="st.image_path = ''">Убрать картинку</button>
          <span v-if="st.uploading" class="ge-uploading">Загрузка...</span>
        </div>
      </div>

      <div v-if="!form.steps.length" class="ge-state">Добавьте первый шаг.</div>

      <div class="ge-actions">
        <button class="ge-btn ge-btn-primary" @click="save" :disabled="saving || !form.title.trim()">
          {{ saving ? 'Сохранение...' : 'Сохранить' }}
        </button>
        <button class="ge-btn" @click="cancel" :disabled="saving">Отмена</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import BkIcon from '@/components/ui/BkIcon.vue';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import { useToastStore } from '@/stores/toastStore.js';
import { appConfirm } from '@/lib/appDialogs.js';

const toast = useToastStore();
const guides = ref([]);
const loading = ref(true);
const saving = ref(false);
const editing = ref(false);

// Набор иконок под темы инструкций — те же, что рисует кабинет.
const iconChoices = [
  { key: 'document', label: 'Документ' },
  { key: 'package', label: 'Заказ' },
  { key: 'edit', label: 'Правка' },
  { key: 'stockCollection', label: 'Остатки' },
  { key: 'kegReturn', label: 'Кеги' },
  { key: 'schedule', label: 'График' },
  { key: 'chat', label: 'Связь' },
  { key: 'user', label: 'Профиль' },
  { key: 'search', label: 'Поиск' },
  { key: 'warning', label: 'Внимание' },
];

const emptyForm = () => ({
  id: 0, title: '', summary: '', icon_key: 'document',
  target_group: null, is_published: false, sort_order: 0, steps: [],
});
const form = reactive(emptyForm());

function groupLabel(g) {
  return g === 'PS' ? 'Пицца Стар' : g === 'BK_VM' ? 'БК / ВМ' : '';
}

function headers() {
  return {
    'Content-Type': 'application/json',
    'X-Session-Token': localStorage.getItem('bk_session_token') || '',
  };
}

async function load() {
  loading.value = true;
  try {
    const r = await fetch('/api/ro/admin/guides', { headers: headers() });
    const data = await r.json();
    if (!r.ok) throw new Error(data.error || 'Не удалось загрузить');
    guides.value = (data.guides || []).map(g => ({ ...g, steps: g.steps || [] }));
  } catch (e) {
    toast.error('Ошибка', e.message || String(e));
  } finally {
    loading.value = false;
  }
}

function startNew() {
  Object.assign(form, emptyForm());
  form.sort_order = guides.value.length;
  editing.value = true;
}

function edit(g) {
  Object.assign(form, {
    id: g.id,
    title: g.title,
    summary: g.summary || '',
    icon_key: g.icon_key || 'document',
    target_group: g.target_group || null,
    is_published: !!Number(g.is_published),
    sort_order: Number(g.sort_order) || 0,
    steps: (g.steps || []).map(s => ({
      title: s.title || '', body: s.body || '', image_path: s.image_path || '', uploading: false,
    })),
  });
  editing.value = true;
}

function cancel() { editing.value = false; }

function addStep() { form.steps.push({ title: '', body: '', image_path: '', uploading: false }); }
function removeStep(i) { form.steps.splice(i, 1); }
function moveStep(i, dir) {
  const j = i + dir;
  if (j < 0 || j >= form.steps.length) return;
  const [s] = form.steps.splice(i, 1);
  form.steps.splice(j, 0, s);
}

async function uploadImage(ev, step) {
  const file = ev.target.files?.[0];
  ev.target.value = '';
  if (!file) return;
  step.uploading = true;
  try {
    const fd = new FormData();
    fd.append('file', file);
    const r = await fetch('/api/ro/admin/guide-image', {
      method: 'POST',
      headers: { 'X-Session-Token': localStorage.getItem('bk_session_token') || '' },
      body: fd,
    });
    const data = await r.json();
    if (!r.ok) throw new Error(data.error || 'Не удалось загрузить картинку');
    step.image_path = data.image_path;
  } catch (e) {
    toast.error('Ошибка', e.message || String(e));
  } finally {
    step.uploading = false;
  }
}

async function save() {
  if (!form.title.trim()) return;
  saving.value = true;
  try {
    const payload = {
      id: form.id || undefined,
      title: form.title.trim(),
      summary: form.summary.trim(),
      icon_key: form.icon_key,
      target_group: form.target_group,
      is_published: form.is_published,
      sort_order: form.sort_order,
      steps: form.steps.map(s => ({ title: s.title, body: s.body, image_path: s.image_path })),
    };
    const r = await fetch('/api/ro/admin/guides', {
      method: form.id ? 'PATCH' : 'POST',
      headers: headers(),
      body: JSON.stringify(payload),
    });
    const data = await r.json();
    if (!r.ok) throw new Error(data.error || 'Не удалось сохранить');
    toast.success('Сохранено', form.title.trim());
    editing.value = false;
    await load();
  } catch (e) {
    toast.error('Ошибка', e.message || String(e));
  } finally {
    saving.value = false;
  }
}

async function remove(g) {
  if (!await appConfirm(`Удалить инструкцию «${g.title}»? Шаги и картинки тоже удалятся.`)) return;
  saving.value = true;
  try {
    const r = await fetch(`/api/ro/admin/guides?id=${g.id}`, { method: 'DELETE', headers: headers() });
    const data = await r.json();
    if (!r.ok) throw new Error(data.error || 'Не удалось удалить');
    toast.success('Удалено', g.title);
    await load();
  } catch (e) {
    toast.error('Ошибка', e.message || String(e));
  } finally {
    saving.value = false;
  }
}

// Порядок задаётся стрелками: соседние темы меняются местами и сразу
// сохраняются, чтобы в кабинете список совпадал с тем, что видит закупщик.
async function moveGuide(g, dir) {
  const i = guides.value.findIndex(x => x.id === g.id);
  const j = i + dir;
  if (i < 0 || j < 0 || j >= guides.value.length) return;
  saving.value = true;
  try {
    const other = guides.value[j];
    for (const [item, order] of [[g, j], [other, i]]) {
      await fetch('/api/ro/admin/guides', {
        method: 'PATCH',
        headers: headers(),
        body: JSON.stringify({
          id: item.id, title: item.title, summary: item.summary, icon_key: item.icon_key,
          target_group: item.target_group, is_published: !!Number(item.is_published), sort_order: order,
        }),
      });
    }
    await load();
  } catch (e) {
    toast.error('Ошибка', e.message || String(e));
  } finally {
    saving.value = false;
  }
}

load();
</script>

<style scoped>
.ge { padding: 4px 0; }
.ge-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 16px; flex-wrap: wrap; }
.ge-h { margin: 0 0 4px; font-size: 18px; font-weight: 700; color: var(--bk-brown, #502314); }
.ge-h4 { margin: 0; font-size: 15px; font-weight: 700; color: var(--bk-brown, #502314); }
.ge-hint { margin: 0; font-size: 13px; color: #8C7B6E; max-width: 640px; line-height: 1.5; }
.ge-state { padding: 24px; text-align: center; color: #8C7B6E; }

.ge-btn {
  padding: 7px 12px; border: 1px solid #E5DDD3; border-radius: 8px; background: #fff;
  font: inherit; font-size: 13px; cursor: pointer; color: var(--bk-brown, #502314); white-space: nowrap;
}
.ge-btn:hover:not(:disabled) { border-color: #C9BBA8; background: #FFF8ED; }
.ge-btn:disabled { opacity: .5; cursor: default; }
.ge-btn-primary { background: var(--bk-red, #E76F51); border-color: var(--bk-red, #E76F51); color: #fff; font-weight: 600; }
.ge-btn-primary:hover:not(:disabled) { background: #C85A3E; }
.ge-btn-danger { color: #c62828; }
.ge-btn-danger:hover:not(:disabled) { background: #ffebee; border-color: #e57373; }

/* Список тем */
.ge-list { display: flex; flex-direction: column; gap: 8px; }
.ge-item {
  display: flex; align-items: center; gap: 12px;
  border: 1px solid #EEE6DC; border-radius: 10px; padding: 10px 14px; background: #fff;
}
.ge-item-icon { flex: 0 0 auto; display: flex; }
.ge-item-text { flex: 1; min-width: 0; }
.ge-item-title { font-size: 15px; font-weight: 600; color: var(--bk-brown, #502314); display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.ge-item-sub { font-size: 12.5px; color: #8C7B6E; margin-top: 2px; }
.ge-item-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.ge-chip { font-size: 11px; font-weight: 700; padding: 2px 7px; border-radius: 999px; background: #F0E6D8; color: #8a6a45; }
.ge-chip-group { background: #E3F1FB; color: #2b6c96; }

/* Редактор */
.ge-editor { border: 1px solid #EEE6DC; border-radius: 12px; padding: 16px; background: #fff; }
.ge-row { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
.ge-field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; }
.ge-field-grow { flex: 1; min-width: 260px; }
.ge-label { font-size: 12px; font-weight: 600; color: #8C7B6E; }
.ge-input {
  padding: 8px 10px; border: 1px solid #E5DDD3; border-radius: 8px;
  font: inherit; font-size: 14px; background: #fff; color: inherit; box-sizing: border-box;
}
.ge-field:not(.ge-field-grow) .ge-input { min-width: 220px; }
.ge-textarea { width: 100%; resize: vertical; margin-top: 8px; }
.ge-check { display: flex; align-items: center; gap: 8px; font-size: 13.5px; margin-bottom: 16px; }

.ge-steps-head { display: flex; justify-content: space-between; align-items: center; margin: 18px 0 10px; }
.ge-step { border: 1px solid #EEE6DC; border-radius: 10px; padding: 12px; margin-bottom: 10px; background: #FFFDFA; }
.ge-step-top { display: flex; align-items: center; gap: 8px; }
.ge-step-num {
  flex: 0 0 auto; width: 24px; height: 24px; border-radius: 50%;
  background: var(--bk-brown, #502314); color: #fff;
  display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700;
}
.ge-step-title { flex: 1; min-width: 0; }
.ge-step-img { display: flex; align-items: center; gap: 10px; margin-top: 10px; flex-wrap: wrap; }
.ge-thumb { max-width: 220px; max-height: 130px; border-radius: 8px; border: 1px solid #E5DDD3; }
.ge-upload { display: inline-flex; align-items: center; }
.ge-uploading { font-size: 12.5px; color: #8C7B6E; }
.ge-actions { display: flex; gap: 8px; margin-top: 16px; }

@media (max-width: 640px) {
  .ge-top { flex-direction: column; }
  .ge-item { flex-wrap: wrap; }
  .ge-item-actions { width: 100%; }
  .ge-field-grow { min-width: 0; width: 100%; }
  .ge-field:not(.ge-field-grow) .ge-input { min-width: 0; width: 100%; }
}
</style>
