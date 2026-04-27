<template>
  <div class="rcm-page">
    <div class="rcm-header">
      <div>
        <h1>Кабинеты ресторанов</h1>
        <p>Управление тем, что видят рестораны в личном кабинете.</p>
      </div>
      <button class="rcm-btn rcm-btn-primary" @click="reload" :disabled="loading">
        {{ loading ? 'Обновление...' : 'Обновить' }}
      </button>
    </div>

    <div class="rcm-tabs">
      <button class="rcm-tab active">Важная информация</button>
      <button class="rcm-tab" disabled>Настройки кабинета</button>
    </div>

    <div class="rcm-grid">
      <section class="rcm-panel">
        <div class="rcm-panel-head">
          <h2>Новое сообщение</h2>
          <span>Появится в разделе “Важная информация” у ресторанов.</span>
        </div>

        <label class="rcm-field">
          <span>Заголовок</span>
          <input v-model="form.title" type="text" placeholder="Например: Изменение графика поставок" />
        </label>

        <label class="rcm-field">
          <span>Текст</span>
          <textarea v-model="form.message" rows="6" placeholder="Введите сообщение для ресторанов"></textarea>
        </label>

        <div class="rcm-field">
          <span>Получатели</span>
          <div class="rcm-segments">
            <button :class="{ active: form.targetMode === 'all' }" @click="form.targetMode = 'all'">Все</button>
            <button :class="{ active: form.targetMode === 'group' }" @click="form.targetMode = 'group'">Юрлицо</button>
            <button :class="{ active: form.targetMode === 'restaurants' }" @click="form.targetMode = 'restaurants'">Рестораны</button>
          </div>
        </div>

        <div v-if="form.targetMode === 'group'" class="rcm-field">
          <span>Группа юрлиц</span>
          <select v-model="form.targetGroup">
            <option value="BK_VM">БК/ВМ</option>
            <option value="PS">Пицца Стар</option>
          </select>
        </div>

        <label v-if="form.targetMode === 'restaurants'" class="rcm-field">
          <span>Номера ресторанов</span>
          <input v-model="form.restaurantsText" type="text" placeholder="Например: 1, 2, PS01, PS02" />
        </label>

        <label class="rcm-check">
          <input type="checkbox" v-model="form.showPopup" />
          <span>Показать всплывающим окном один раз</span>
        </label>

        <label class="rcm-check">
          <input type="checkbox" v-model="form.isPublished" />
          <span>Опубликовать сразу</span>
        </label>

        <label class="rcm-check" :class="{ disabled: !form.isPublished }">
          <input type="checkbox" v-model="form.notifyTelegram" :disabled="!form.isPublished" />
          <span>Отправить через Telegram-бот подписчикам ресторанов</span>
        </label>

        <div class="rcm-field">
          <span>Файлы и изображения</span>
          <input ref="fileInput" type="file" multiple @change="onFilesChange" />
          <div v-if="files.length" class="rcm-files">
            <div v-for="file in files" :key="file.name + file.size" class="rcm-file">
              {{ file.name }} <span>{{ formatFileSize(file.size) }}</span>
            </div>
          </div>
        </div>

        <div v-if="error" class="rcm-alert rcm-alert-error">{{ error }}</div>
        <div v-if="success" class="rcm-alert rcm-alert-success">{{ success }}</div>

        <button class="rcm-btn rcm-btn-primary rcm-submit" @click="createPost" :disabled="saving || !form.message.trim()">
          {{ saving ? 'Сохранение...' : 'Опубликовать сообщение' }}
        </button>
      </section>

      <section class="rcm-panel">
        <div class="rcm-panel-head rcm-list-head">
          <div>
            <h2>Опубликованные сообщения</h2>
            <span>Последние 100 сообщений для кабинетов ресторанов.</span>
          </div>
          <select v-model="filterGroup" @change="loadPosts">
            <option value="">Все</option>
            <option value="BK_VM">БК/ВМ</option>
            <option value="PS">Пицца Стар</option>
          </select>
        </div>

        <div v-if="loading" class="rcm-empty">Загрузка...</div>
        <div v-else-if="!posts.length" class="rcm-empty">Сообщений пока нет</div>
        <div v-else class="rcm-posts">
          <article v-for="post in posts" :key="post.id" class="rcm-post" :class="{ muted: !Number(post.is_published) }">
            <div class="rcm-post-top">
              <div>
                <h3>{{ post.title || 'Важная информация' }}</h3>
                <div class="rcm-post-meta">
                  {{ targetLabel(post) }} · {{ post.created_by || 'Отдел закупок' }} · {{ formatDateTime(post.published_at || post.created_at) }}
                </div>
              </div>
              <span class="rcm-status" :class="{ off: !Number(post.is_published) }">
                {{ Number(post.is_published) ? 'Опубликовано' : 'Скрыто' }}
              </span>
            </div>
            <p>{{ post.message }}</p>
            <div v-if="post.files?.length" class="rcm-attachments">
              <button
                v-for="file in post.files"
                :key="file.id"
                class="rcm-attachment"
                :class="{ image: isImageFile(file) }"
                @click="isImageFile(file) ? previewFile(file) : downloadFile(file)"
              >
                <img v-if="isImageFile(file) && previewUrls[file.id]" :src="previewUrls[file.id]" :alt="file.file_name" />
                <span v-else class="rcm-file-icon">📄</span>
                <span class="rcm-attachment-name">{{ file.file_name }}</span>
                <small>{{ isImageFile(file) ? 'Открыть' : formatFileSize(file.file_size) }}</small>
              </button>
            </div>
            <div class="rcm-post-actions">
              <span>{{ Number(post.read_count || 0) }} прочтений</span>
              <button class="rcm-link-btn" @click="togglePublished(post)" :disabled="post._busy">
                {{ Number(post.is_published) ? 'Скрыть' : 'Опубликовать' }}
              </button>
              <button class="rcm-link-btn danger" @click="deletePost(post)" :disabled="post._busy">Удалить</button>
            </div>
          </article>
        </div>
      </section>
    </div>

    <div v-if="imagePreview.show" class="rcm-preview-overlay" @click.self="closePreview">
      <div class="rcm-preview">
        <div class="rcm-preview-head">
          <span>{{ imagePreview.name }}</span>
          <button @click="closePreview">&times;</button>
        </div>
        <img :src="imagePreview.url" :alt="imagePreview.name" />
      </div>
    </div>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useRestaurantOrderStore } from '@/stores/restaurantOrderStore.js';

const store = useRestaurantOrderStore();
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const success = ref('');
const posts = ref([]);
const files = ref([]);
const fileInput = ref(null);
const filterGroup = ref('');
const previewUrls = reactive({});
const imagePreview = reactive({ show: false, url: '', name: '' });

const form = reactive({
  title: '',
  message: '',
  targetMode: 'all',
  targetGroup: 'BK_VM',
  restaurantsText: '',
  showPopup: true,
  isPublished: true,
  notifyTelegram: false,
});

function onFilesChange(event) {
  files.value = Array.from(event.target.files || []);
}

function parseRestaurantsText(text) {
  return String(text || '')
    .split(/[\s,;]+/)
    .map(s => s.trim())
    .filter(Boolean);
}

async function loadPosts() {
  loading.value = true;
  try {
    posts.value = await store.adminGetCabinetPosts(filterGroup.value);
    await loadImagePreviews(posts.value);
  } catch (e) {
    error.value = e.message || 'Не удалось загрузить сообщения';
  } finally {
    loading.value = false;
  }
}

function isImageFile(file) {
  return String(file?.mime_type || '').startsWith('image/');
}

async function loadImagePreviews(items) {
  for (const post of items || []) {
    for (const file of post.files || []) {
      if (!isImageFile(file) || previewUrls[file.id]) continue;
      try {
        previewUrls[file.id] = await store.getCabinetFileObjectUrl(file);
      } catch (e) {
        console.warn('[restaurant cabinet manager] image preview:', e);
      }
    }
  }
}

async function reload() {
  error.value = '';
  success.value = '';
  await loadPosts();
}

async function createPost() {
  error.value = '';
  success.value = '';
  saving.value = true;
  try {
    const result = await store.adminCreateCabinetPost({
      title: form.title.trim() || 'Важная информация',
      message: form.message.trim(),
      target_mode: form.targetMode,
      target_group: form.targetGroup,
      restaurants: parseRestaurantsText(form.restaurantsText),
      show_popup: form.showPopup ? 1 : 0,
      is_published: form.isPublished ? 1 : 0,
      notify_telegram: form.isPublished && form.notifyTelegram ? 1 : 0,
    }, files.value);
    form.title = '';
    form.message = '';
    form.restaurantsText = '';
    form.notifyTelegram = false;
    files.value = [];
    if (fileInput.value) fileInput.value.value = '';
    const sent = Number(result?.telegram_sent || 0);
    success.value = sent > 0 ? `Сообщение сохранено. В Telegram отправлено: ${sent}` : 'Сообщение сохранено';
    await loadPosts();
  } catch (e) {
    error.value = e.message || 'Не удалось сохранить сообщение';
  } finally {
    saving.value = false;
  }
}

async function togglePublished(post) {
  post._busy = true;
  try {
    await store.adminUpdateCabinetPost(post.id, { is_published: !Number(post.is_published) });
    await loadPosts();
  } catch (e) {
    error.value = e.message || 'Не удалось изменить статус';
  } finally {
    post._busy = false;
  }
}

async function deletePost(post) {
  if (!window.confirm('Удалить сообщение из кабинетов ресторанов?')) return;
  post._busy = true;
  try {
    await store.adminDeleteCabinetPost(post.id);
    posts.value = posts.value.filter(p => p.id !== post.id);
  } catch (e) {
    error.value = e.message || 'Не удалось удалить сообщение';
  } finally {
    post._busy = false;
  }
}

async function downloadFile(file) {
  try {
    await store.downloadCabinetFile(file);
  } catch (e) {
    error.value = e.message || 'Не удалось скачать файл';
  }
}

async function previewFile(file) {
  try {
    if (!previewUrls[file.id]) {
      previewUrls[file.id] = await store.getCabinetFileObjectUrl(file);
    }
    imagePreview.url = previewUrls[file.id];
    imagePreview.name = file.file_name || 'Изображение';
    imagePreview.show = true;
  } catch (e) {
    error.value = e.message || 'Не удалось открыть изображение';
  }
}

function closePreview() {
  imagePreview.show = false;
  imagePreview.url = '';
  imagePreview.name = '';
}

function targetLabel(post) {
  if (post.target_mode === 'all') return 'Все рестораны';
  if (post.target_mode === 'group') return post.target_group === 'PS' ? 'Пицца Стар' : 'БК/ВМ';
  const count = post.restaurants?.length || 0;
  return count ? `${count} ресторанов` : 'Выбранные рестораны';
}

function formatDateTime(value) {
  if (!value) return '';
  const d = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function formatFileSize(size) {
  const n = Number(size || 0);
  if (n >= 1024 * 1024) return `${(n / 1024 / 1024).toFixed(1)} МБ`;
  if (n >= 1024) return `${Math.round(n / 1024)} КБ`;
  return `${n} Б`;
}

onMounted(loadPosts);
onBeforeUnmount(() => {
  for (const url of Object.values(previewUrls)) URL.revokeObjectURL(url);
});
</script>

<style scoped>
.rcm-page { padding: 24px; max-width: 1500px; margin: 0 auto; color: var(--text, #2d2420); }
.rcm-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 18px; }
.rcm-header h1 { margin: 0 0 4px; font-size: 28px; color: #502314; }
.rcm-header p { margin: 0; color: #7a6a5e; font-size: 14px; }
.rcm-tabs { display: flex; gap: 8px; margin-bottom: 16px; border-bottom: 1px solid #e7ddd5; }
.rcm-tab { border: 0; background: transparent; padding: 12px 14px; color: #7a6a5e; font-weight: 700; cursor: pointer; border-bottom: 2px solid transparent; }
.rcm-tab.active { color: #502314; border-bottom-color: #e76f51; }
.rcm-tab:disabled { cursor: not-allowed; opacity: .45; }
.rcm-grid { display: grid; grid-template-columns: minmax(360px, 440px) minmax(0, 1fr); gap: 18px; align-items: start; }
.rcm-panel { background: #fff; border: 1px solid #eee5dc; border-radius: 8px; padding: 18px; }
.rcm-panel-head { margin-bottom: 16px; }
.rcm-panel-head h2 { margin: 0 0 4px; font-size: 18px; color: #502314; }
.rcm-panel-head span { color: #8a7a70; font-size: 13px; }
.rcm-list-head { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
.rcm-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 13px; font-size: 13px; font-weight: 700; color: #502314; }
.rcm-field input, .rcm-field textarea, .rcm-field select, .rcm-list-head select { border: 1px solid #ddd2c8; border-radius: 6px; padding: 10px 11px; font: inherit; background: #fff; color: #2d2420; }
.rcm-field textarea { resize: vertical; min-height: 130px; }
.rcm-segments { display: flex; gap: 6px; background: #f7f1eb; border-radius: 8px; padding: 4px; }
.rcm-segments button { flex: 1; border: 0; border-radius: 6px; padding: 8px; background: transparent; cursor: pointer; font-weight: 700; color: #6f5f55; }
.rcm-segments button.active { background: #fff; color: #502314; box-shadow: 0 1px 4px rgba(80,35,20,.12); }
.rcm-check { display: flex; gap: 8px; align-items: center; margin: 10px 0; color: #4b3d35; font-size: 13px; cursor: pointer; }
.rcm-check input { width: 16px; height: 16px; }
.rcm-check.disabled { opacity: .55; cursor: not-allowed; }
.rcm-btn { border: 1px solid #d9c9bd; border-radius: 6px; padding: 10px 14px; background: #fff; color: #502314; font-weight: 700; cursor: pointer; }
.rcm-btn:disabled { opacity: .6; cursor: not-allowed; }
.rcm-btn-primary { background: #e76f51; border-color: #e76f51; color: white; }
.rcm-submit { width: 100%; margin-top: 6px; }
.rcm-alert { padding: 10px 12px; border-radius: 6px; font-size: 13px; margin: 10px 0; }
.rcm-alert-error { background: #fff1f0; color: #b42318; border: 1px solid #ffd4d0; }
.rcm-alert-success { background: #ecfdf3; color: #087443; border: 1px solid #bbf7d0; }
.rcm-empty { padding: 32px; text-align: center; color: #8a7a70; background: #faf7f4; border-radius: 8px; }
.rcm-posts { display: flex; flex-direction: column; gap: 12px; }
.rcm-post { border: 1px solid #eee5dc; border-radius: 8px; padding: 14px; background: #fffdfb; }
.rcm-post.muted { opacity: .68; }
.rcm-post-top { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
.rcm-post h3 { margin: 0 0 4px; font-size: 16px; color: #502314; }
.rcm-post p { white-space: pre-line; margin: 12px 0; color: #3b302a; line-height: 1.45; }
.rcm-post-meta { color: #8a7a70; font-size: 12px; }
.rcm-status { flex-shrink: 0; border-radius: 999px; padding: 4px 8px; font-size: 12px; font-weight: 700; color: #087443; background: #dcfce7; }
.rcm-status.off { color: #92400e; background: #fef3c7; }
.rcm-files { display: flex; flex-direction: column; gap: 6px; margin-top: 8px; }
.rcm-file { display: flex; justify-content: space-between; gap: 10px; padding: 8px 10px; background: #f8f3ef; border-radius: 6px; color: #502314; font-size: 13px; }
.rcm-file span { color: #8a7a70; }
.rcm-attachments { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; margin: 12px 0; }
.rcm-attachment { min-width: 0; border: 1px solid #eee5dc; border-radius: 8px; background: #f8f3ef; padding: 8px; cursor: pointer; text-align: left; color: #502314; font: inherit; }
.rcm-attachment.image { padding: 0; overflow: hidden; background: #fff; }
.rcm-attachment img { width: 100%; aspect-ratio: 4 / 3; object-fit: cover; display: block; background: #f4ece4; }
.rcm-file-icon { display: block; font-size: 24px; margin-bottom: 8px; }
.rcm-attachment-name { display: block; padding: 7px 8px 2px; font-size: 13px; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rcm-attachment small { display: block; padding: 0 8px 7px; color: #8a7a70; font-size: 12px; }
.rcm-attachment:hover { border-color: #e76f51; }
.rcm-preview-overlay { position: fixed; inset: 0; z-index: 5000; background: rgba(20,12,8,.72); display: flex; align-items: center; justify-content: center; padding: 24px; }
.rcm-preview { max-width: min(960px, 96vw); max-height: 92vh; background: #fff; border-radius: 8px; overflow: hidden; display: flex; flex-direction: column; }
.rcm-preview-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 10px 12px; border-bottom: 1px solid #eee5dc; color: #502314; font-weight: 700; }
.rcm-preview-head button { border: 0; background: transparent; font-size: 26px; line-height: 1; cursor: pointer; color: #502314; }
.rcm-preview img { max-width: 100%; max-height: calc(92vh - 48px); object-fit: contain; display: block; }
.rcm-post-actions { display: flex; align-items: center; gap: 12px; justify-content: flex-end; color: #8a7a70; font-size: 12px; border-top: 1px solid #f0e7df; padding-top: 10px; }
.rcm-link-btn { border: 0; background: transparent; color: #c05621; font-weight: 700; cursor: pointer; }
.rcm-link-btn.danger { color: #b42318; }
@media (max-width: 980px) {
  .rcm-page { padding: 14px; }
  .rcm-grid { grid-template-columns: 1fr; }
  .rcm-header { flex-direction: column; }
}
</style>
