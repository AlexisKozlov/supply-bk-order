<template>
  <section class="cab-section info-section">
    <div v-if="loading && !posts.length" class="cab-empty-card">
      <BurgerSpinner text="Загрузка..." />
    </div>
    <div v-else-if="!posts.length" class="info-empty">
      <span class="info-empty-icon">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.5V14a2 2 0 0 1-2 2h-7l-5 4v-4H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h12"/><circle cx="20" cy="5" r="3"/></svg>
      </span>
      <h3>Пока всё спокойно</h3>
      <p>Когда отдел закупок опубликует важную информацию, она появится здесь.</p>
    </div>
    <template v-else>
      <div v-if="posts.length > 2" class="info-controls">
        <div class="info-search">
          <svg class="info-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
          <input v-model="searchQuery" type="search" placeholder="Поиск по тексту..." class="info-search-input" />
          <button v-if="searchQuery" class="info-search-clear" @click="searchQuery = ''" aria-label="Очистить">×</button>
        </div>
        <div class="info-filter-chips">
          <button class="info-fchip" :class="{ active: filter === 'all' }" @click="filter = 'all'">
            Все <span class="info-fchip-count">{{ posts.length }}</span>
          </button>
          <button class="info-fchip" :class="{ active: filter === 'unread' }" @click="filter = 'unread'">
            Непрочитанные <span class="info-fchip-count">{{ unreadCount }}</span>
          </button>
        </div>
      </div>

      <div v-if="!filteredPosts.length" class="info-empty">
        <span class="info-empty-icon"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m9 12 2 2 4-4"/></svg></span>
        <h3>{{ searchQuery ? 'Ничего не найдено' : 'Всё прочитано' }}</h3>
        <p>{{ searchQuery ? 'Попробуйте другие слова или очистите поиск.' : 'В этой категории больше нет сообщений.' }}</p>
      </div>

      <div v-else class="info-list">
        <article v-for="post in filteredPosts" :key="post.id"
          class="info-card" :class="{ unread: !post.is_read }">
          <header class="info-card-head">
            <div class="info-card-author">
              <span class="info-card-avatar">{{ infoAvatar(post.created_by) }}</span>
              <div class="info-card-author-info">
                <div class="info-card-author-name">{{ post.created_by || 'Отдел закупок' }}</div>
                <time class="info-card-time">{{ fmtDateTime(post.published_at || post.created_at) }}</time>
              </div>
            </div>
            <span v-if="!post.is_read" class="info-card-dot" aria-label="Новое"></span>
          </header>
          <h3 v-if="post.title" class="info-card-title">{{ post.title }}</h3>
          <div class="info-card-message ro-post-body" v-html="renderPostMessage(post.message)"></div>

          <div v-if="post.files?.length" class="info-card-files">
            <button v-for="file in post.files" :key="file.id"
              class="info-file"
              :class="{ image: isImportantImage(file) }"
              @click="isImportantImage(file) ? previewFile(file) : downloadFile(file)">
              <img v-if="isImportantImage(file) && previewUrls[file.id]" :src="previewUrls[file.id]" :alt="file.file_name" class="info-file-img" />
              <span v-else class="info-file-ico" v-html="fileIconSvg"></span>
              <span class="info-file-meta">
                <span class="info-file-name">{{ file.file_name }}</span>
                <span class="info-file-size">{{ isImportantImage(file) ? 'Открыть' : formatFileSize(file.file_size) }}</span>
              </span>
            </button>
          </div>

          <footer v-if="!post.is_read" class="info-card-foot">
            <button class="info-read" @click="$emit('mark-read', post)">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
              Отметить прочитанным
            </button>
          </footer>
        </article>
      </div>
    </template>

    <!-- Превью картинки -->
    <div v-if="imagePreview.show" class="modal-overlay" @click.self="closePreview">
      <div class="img-preview-box">
        <button class="cab-modal-close img-preview-close" @click="closePreview">&times;</button>
        <img :src="imagePreview.url" :alt="imagePreview.name" />
        <div class="img-preview-name">{{ imagePreview.name }}</div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { ref, reactive, computed } from 'vue';
import { useRestaurantOrderStore } from '@/stores/restaurantOrderStore.js';
import { formatDateTime as fmtDateTime } from '@/lib/roUtils.js';
import { renderMarkdown } from '@/lib/markdown.js';

function renderPostMessage(text) {
  return renderMarkdown(text || '');
}
import { useToastStore } from '@/stores/toastStore.js';

const props = defineProps({
  posts: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  previewUrls: { type: Object, default: () => ({}) },
});
defineEmits(['mark-read']);

const roStore = useRestaurantOrderStore();
const toast = useToastStore();

const filter = ref('all');
const searchQuery = ref('');
const imagePreview = reactive({ show: false, url: '', name: '' });

const fileIconSvg = '<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>';

const unreadCount = computed(() => props.posts.filter(p => !p.is_read).length);
const filteredPosts = computed(() => {
  let list = props.posts;
  if (filter.value === 'unread') list = list.filter(p => !p.is_read);
  const q = searchQuery.value.trim().toLowerCase();
  if (q) {
    list = list.filter(p =>
      (p.title || '').toLowerCase().includes(q) ||
      (p.message || '').toLowerCase().includes(q) ||
      (p.created_by || '').toLowerCase().includes(q)
    );
  }
  return list;
});

function infoAvatar(authorName) {
  const name = (authorName || 'Отдел закупок').trim();
  return (name.charAt(0).toUpperCase()) || 'З';
}

function isImportantImage(file) {
  return String(file?.mime_type || '').startsWith('image/');
}

function formatFileSize(size) {
  const n = Number(size || 0);
  if (n >= 1024 * 1024) return `${(n / 1024 / 1024).toFixed(1)} МБ`;
  if (n >= 1024) return `${Math.round(n / 1024)} КБ`;
  return `${n} Б`;
}

async function downloadFile(file) {
  try {
    await roStore.downloadCabinetFile(file);
  } catch (e) {
    toast.error(e.message || 'Не удалось скачать файл');
  }
}

async function previewFile(file) {
  try {
    if (!props.previewUrls[file.id]) {
      props.previewUrls[file.id] = await roStore.getCabinetFileObjectUrl(file);
    }
    imagePreview.url = props.previewUrls[file.id];
    imagePreview.name = file.file_name || 'Изображение';
    imagePreview.show = true;
  } catch (e) {
    toast.error(e.message || 'Не удалось открыть изображение');
  }
}

function closePreview() {
  imagePreview.show = false;
  imagePreview.url = '';
  imagePreview.name = '';
}
</script>

<style scoped>
.img-preview-box {
  position: relative; max-width: 90vw; max-height: 90vh;
  background: #fff; border-radius: 14px; overflow: hidden;
  display: flex; flex-direction: column;
}
.img-preview-box img { max-width: 100%; max-height: 80vh; display: block; }
.img-preview-name { padding: 10px 14px; font-size: 13px; color: #4B3527; border-top: 1px solid #F2EDE8; }
.img-preview-close { position: absolute; top: 8px; right: 8px; background: rgba(0,0,0,0.5); color: #fff; border: none; width: 32px; height: 32px; border-radius: 50%; font-size: 20px; line-height: 1; cursor: pointer; z-index: 1; }
</style>

<style>
/* Стили info-* — глобальные, чтобы работали и в кабинете
   (дашборд + broadcast-модалка), и в этом компоненте */
.info-section { max-width: 760px; margin: 0 auto; padding-bottom: 100px; }
.info-empty {
  background: #fff; border: 1px solid #ECE3D6; border-radius: 16px;
  padding: 36px 24px; text-align: center;
}
.info-empty-icon {
  display: inline-flex; align-items: center; justify-content: center;
  width: 60px; height: 60px; border-radius: 16px;
  background: #FFF1E0; color: #C16B4D;
  margin: 0 auto 14px;
}
.info-empty h3 { margin: 0 0 6px; font-size: 17px; color: #2C1A12; }
.info-empty p { margin: 0; color: #8B7355; font-size: 13.5px; line-height: 1.5; max-width: 320px; margin: 0 auto; }
.info-controls { display: flex; flex-direction: column; gap: 10px; margin-bottom: 14px; }
.info-search {
  position: relative; display: flex; align-items: center;
  background: #fff; border: 1.5px solid #ECE3D6; border-radius: 12px;
  padding: 0 10px;
  transition: border-color .15s, box-shadow .15s;
}
.info-search:focus-within { border-color: #E76F51; box-shadow: 0 0 0 3px rgba(231,111,81,.12); }
.info-search-icon { color: #B0A090; flex-shrink: 0; }
.info-search-input {
  flex: 1; border: none; background: transparent; outline: none;
  padding: 10px 8px; font: inherit; font-size: 14px; color: #2C1A12;
}
.info-search-input::-webkit-search-cancel-button { display: none; }
.info-search-clear {
  border: none; background: transparent; color: #B0A090; font-size: 22px;
  line-height: 1; cursor: pointer; padding: 0 4px;
}
.info-search-clear:hover { color: #E76F51; }
.info-filter-chips {
  display: flex; gap: 8px;
  overflow-x: auto; -webkit-overflow-scrolling: touch; padding-bottom: 2px;
}
.info-fchip {
  display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0;
  padding: 7px 12px; border: 1.5px solid #E8DCC8; background: #FFFBF6;
  color: #6B5344; border-radius: 999px; cursor: pointer; font: inherit;
  font-size: 13px; font-weight: 600; transition: all .15s;
}
.info-fchip:hover:not(.active) { border-color: #E76F51; }
.info-fchip.active { background: #E76F51; color: #fff; border-color: #E76F51; }
.info-fchip-count {
  display: inline-block; min-width: 18px; padding: 1px 7px;
  border-radius: 10px; background: rgba(0,0,0,.08);
  font-size: 11px; font-weight: 700;
}
.info-fchip.active .info-fchip-count { background: rgba(255,255,255,.25); }
.info-list { display: flex; flex-direction: column; gap: 12px; }
.info-card {
  background: #fff; border: 1.5px solid #ECE3D6; border-radius: 14px;
  padding: 16px 18px;
  transition: border-color .15s, box-shadow .15s;
}
.info-card.unread {
  border-color: #F4A261;
  box-shadow: 0 4px 14px rgba(244,162,97,.15);
  background: linear-gradient(to bottom right, #FFF8F0, #FFFFFF 60%);
}
.info-card-head {
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; margin-bottom: 10px;
}
.info-card-author { display: flex; align-items: center; gap: 12px; min-width: 0; }
.info-card-avatar {
  width: 38px; height: 38px; border-radius: 50%;
  background: linear-gradient(135deg, #502314, #6B321F);
  color: #fff; font-size: 15px; font-weight: 700;
  display: inline-flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.info-card.unread .info-card-avatar {
  background: linear-gradient(135deg, #E76F51, #F4A261);
}
.info-card-author-info { min-width: 0; }
.info-card-author-name { font-size: 13.5px; font-weight: 700; color: #2C1A12; line-height: 1.2; }
.info-card-time { font-size: 11.5px; color: #8B7355; line-height: 1.3; display: block; margin-top: 2px; }
.info-card-dot {
  flex-shrink: 0;
  width: 9px; height: 9px; border-radius: 50%;
  background: #E76F51;
  box-shadow: 0 0 0 4px rgba(231,111,81,.2);
}
.info-card-title {
  margin: 0 0 8px; font-size: 16px; font-weight: 700; color: #2C1A12;
  line-height: 1.35;
}
.info-card-message {
  margin: 0; color: #4B3527; font-size: 14.5px; line-height: 1.55;
}
.info-card-message.ro-post-body { white-space: normal; }
.info-card-message.ro-post-body p { margin: 0 0 8px; }
.info-card-message.ro-post-body p:last-child { margin-bottom: 0; }
.info-card-message.ro-post-body ul, .info-card-message.ro-post-body ol { margin: 6px 0 10px 20px; padding: 0; }
.info-card-message.ro-post-body li { margin: 2px 0; }
.info-card-message.ro-post-body a { color: #b81e00; text-decoration: underline; word-break: break-word; }
.info-card-message.ro-post-body a:hover { color: #E76F51; }
.info-card-message.ro-post-body code { background: #f6efe8; padding: 1px 5px; border-radius: 4px; font-size: 12.5px; }
.info-card-files {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 8px; margin-top: 14px;
}
.info-file {
  display: flex; align-items: center; gap: 10px;
  background: #FAFAF8; border: 1px solid #EDE8E3; border-radius: 10px;
  padding: 8px 10px; cursor: pointer; font: inherit; color: #2C1A12;
  text-align: left; min-width: 0;
  transition: border-color .15s, background .15s;
}
.info-file:hover { border-color: #E76F51; background: #FFF8F0; }
.info-file.image {
  flex-direction: column; align-items: stretch; gap: 0; padding: 0;
  overflow: hidden;
}
.info-file-img {
  width: 100%; aspect-ratio: 4 / 3; object-fit: cover; display: block;
  background: #F4ECE4;
}
.info-file.image .info-file-meta { padding: 8px 10px; }
.info-file-ico {
  width: 36px; height: 36px; flex-shrink: 0;
  display: inline-flex; align-items: center; justify-content: center;
  border-radius: 8px; background: #FFF1E0; color: #C16B4D;
}
.info-file-ico svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
.info-file-meta { display: flex; flex-direction: column; min-width: 0; flex: 1; gap: 2px; }
.info-file-name {
  font-size: 13px; font-weight: 600; color: #2C1A12;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.info-file-size { font-size: 11.5px; color: #8B7355; }
.info-card-foot {
  margin-top: 14px; padding-top: 12px; border-top: 1px solid #F2EDE8;
}
.info-read {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 14px; border: 1.5px solid #E8DCC8; border-radius: 999px;
  background: #FFFBF6; color: #C16B4D; font: inherit;
  font-size: 13px; font-weight: 600; cursor: pointer;
  transition: all .15s;
}
.info-read:hover { background: #E76F51; color: #fff; border-color: #E76F51; }
@media (max-width: 640px) {
  .info-card { padding: 14px; border-radius: 12px; }
  .info-card-files { grid-template-columns: 1fr 1fr; gap: 6px; }
  .info-empty { padding: 28px 16px; }
}
</style>
