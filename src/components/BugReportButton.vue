<template>
  <!-- Плавающая кнопка -->
  <div class="bug-fab-wrap" :class="{ open: showForm }">
    <button class="bug-fab" :class="{ active: showForm, 'has-replies': hasNewReplies }" @click="toggleForm" :title="showForm ? 'Закрыть' : 'Нашли ошибку?'">
      <span class="bug-fab-icon" :class="{ spin: showForm }">
        <svg v-if="!showForm" viewBox="0 0 24 24" fill="none" width="18" height="18">
          <path d="M8 2l1.5 2.5M16 2l-1.5 2.5" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
          <ellipse cx="12" cy="13" rx="5.5" ry="7" fill="rgba(255,255,255,0.15)" stroke="#fff" stroke-width="1.5"/>
          <path d="M9 10h6M9 13h6M9 16h6" stroke="rgba(255,255,255,0.7)" stroke-width="1.2" stroke-linecap="round"/>
          <path d="M3 10l2.5 1M21 10l-2.5 1M3 16l2.5-0.5M21 16l-2.5-0.5" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
        <svg v-else viewBox="0 0 24 24" fill="none" width="16" height="16">
          <path d="M18 6L6 18M6 6l12 12" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/>
        </svg>
      </span>
      <span v-if="hasNewReplies && !showForm" class="bug-fab-badge">!</span>
    </button>
  </div>

  <!-- Модалка -->
  <Teleport to="body">
    <Transition name="bug-modal">
      <div v-if="showForm" class="bug-overlay" @click.self="showForm = false">
        <div class="bug-modal">
          <!-- Режим чата (когда открыто конкретное обращение) -->
          <template v-if="viewingReport">
            <div class="chat-header">
              <button class="chat-back" @click="viewingReport = null">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M15 18l-6-6 6-6"/></svg>
              </button>
              <div class="chat-header-info">
                <div class="chat-header-title">{{ viewingReport.title }}</div>
                <div class="chat-header-status">
                  <span class="bug-status" :class="'st-' + viewingReport.status">{{ statusLabel(viewingReport.status) }}</span>
                </div>
              </div>
            </div>

            <div class="chat-messages" ref="chatMessagesEl">
              <!-- Первое сообщение — само обращение -->
              <div class="chat-bubble me">
                <div class="chat-bubble-body">
                  <div v-if="viewingReport.description" class="chat-bubble-text">{{ viewingReport.description }}</div>
                  <div v-else class="chat-bubble-text" style="opacity:0.6">{{ viewingReport.title }}</div>
                  <div v-if="viewingReport.screenshots?.length" class="chat-bubble-imgs">
                    <img v-for="(s, i) in viewingReport.screenshots" :key="i"
                      :src="apiBase + '/' + s + '?token=' + sessionToken"
                      @click="previewImage = apiBase + '/' + s + '?token=' + sessionToken" />
                  </div>
                </div>
                <div class="chat-bubble-time">{{ formatDate(viewingReport.created_at) }}</div>
              </div>

              <!-- Ответы -->
              <div v-for="r in viewingReplies" :key="r.id" class="chat-bubble" :class="r.is_admin ? 'them' : 'me'">
                <div v-if="r.is_admin" class="chat-bubble-name">Поддержка</div>
                <div class="chat-bubble-body">
                  <div class="chat-bubble-text" v-html="renderMsgContent(r.message)"></div>
                </div>
                <div class="chat-bubble-time">{{ formatDate(r.created_at) }}</div>
              </div>
            </div>

            <!-- Поле ввода -->
            <div v-if="viewingReport.status === 'closed'" class="chat-closed">Обращение закрыто</div>
            <div v-else class="chat-input-area">
              <div v-if="replyImages.length" class="chat-input-previews">
                <div v-for="(img, i) in replyImages" :key="i" class="chat-input-thumb">
                  <img :src="img.preview" />
                  <button @click="replyImages.splice(i, 1)" class="chat-input-thumb-rm">&times;</button>
                  <div v-if="img.uploading" class="chat-input-thumb-load"></div>
                </div>
              </div>
              <div class="chat-input-row">
                <label class="chat-attach" title="Прикрепить фото">
                  <input type="file" accept="image/*" multiple @change="onReplyFiles" style="display:none" />
                  <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"/></svg>
                </label>
                <textarea v-model="replyText" class="chat-textarea" placeholder="Сообщение..." rows="1" @keydown.enter.exact.prevent="sendReply" @paste="onReplyPaste" @input="autoResize"></textarea>
                <button class="chat-send" :disabled="(!replyText.trim() && !replyImages.length) || sendingReply" @click="sendReply">
                  <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                </button>
              </div>
            </div>
          </template>

          <!-- Обычный режим: табы -->
          <template v-else>
            <div class="bug-tabs">
              <button class="bug-tab" :class="{ active: tab === 'new' }" @click="tab = 'new'">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                Написать
              </button>
              <button class="bug-tab" :class="{ active: tab === 'my' }" @click="tab = 'my'; loadMyReports()">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
                Мои обращения
                <span v-if="myReports.length" class="bug-tab-count">{{ myReports.length }}</span>
              </button>
            </div>

            <!-- Таб: Новое обращение -->
            <div v-if="tab === 'new'" class="bug-form">
              <div class="bug-form-group">
                <label>Тема</label>
                <input v-model="form.title" class="bug-input" placeholder="Кратко опишите проблему..." maxlength="200" />
              </div>
              <div class="bug-form-group">
                <label>Описание</label>
                <textarea v-model="form.description" class="bug-input bug-textarea" placeholder="Подробности: что делали, что пошло не так, что ожидали..." rows="4"></textarea>
              </div>
              <!-- Скриншоты -->
              <div class="bug-form-group">
                <label>Скриншоты</label>
                <div class="bug-screenshots">
                  <div v-for="(s, i) in screenshots" :key="i" class="bug-screenshot-item">
                    <img :src="s.preview" @click="previewImage = s.preview" />
                    <button class="bug-screenshot-remove" @click="removeScreenshot(i)" title="Удалить">&times;</button>
                    <div v-if="s.uploading" class="bug-screenshot-uploading">
                      <div class="bug-upload-spinner"></div>
                    </div>
                  </div>
                  <label v-if="screenshots.length < 5" class="bug-screenshot-add" title="Добавить скриншот">
                    <input type="file" accept="image/*" multiple @change="onFilesSelected" style="display:none" />
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                  </label>
                </div>
                <div class="bug-paste-hint">Ctrl+V — вставить скриншот из буфера</div>
              </div>
              <div class="bug-form-footer">
                <div class="bug-page-info">
                  <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a10 10 0 100 20 10 10 0 000-20z"/><path d="M12 16v-4M12 8h0"/></svg>
                  Страница и лог действий прикрепятся автоматически
                </div>
                <button class="bug-submit" :disabled="!form.title.trim() || sending" @click="submit">
                  <template v-if="sending">
                    <span class="bug-submit-spinner"></span> Отправка...
                  </template>
                  <template v-else>
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"/></svg>
                    Отправить
                  </template>
                </button>
              </div>
            </div>

            <!-- Таб: Мои обращения -->
            <div v-if="tab === 'my'" class="bug-my-reports">
              <div v-if="loadingReports" class="bug-loading">Загрузка...</div>
              <div v-else-if="!myReports.length" class="bug-empty">
                <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.2" opacity="0.3"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
                <p>Вы ещё не отправляли обращений</p>
              </div>
              <template v-else>
                <div v-for="r in myReports" :key="r.id" class="bug-report-card" @click="openReport(r)">
                  <div class="bug-report-card-top">
                    <span class="bug-status" :class="'st-' + r.status">{{ statusLabel(r.status) }}</span>
                    <span class="bug-date">{{ formatDate(r.created_at) }}</span>
                  </div>
                  <div class="bug-report-card-title">{{ r.title }}</div>
                  <div v-if="r.reply_count" class="bug-report-card-replies">
                    <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
                    {{ r.reply_count }}
                  </div>
                </div>
              </template>
            </div>
          </template>
        </div>
      </div>
    </Transition>

    <!-- Превью изображения -->
    <div v-if="previewImage" class="bug-preview-overlay" @click="previewImage = null">
      <img :src="previewImage" class="bug-preview-img" />
    </div>

    <!-- Успех -->
    <Transition name="bug-success">
      <div v-if="showSuccess" class="bug-success-toast">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#fff" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
        Обращение отправлено!
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, watch, nextTick, onMounted, onUnmounted } from 'vue';
import { useRoute } from 'vue-router';
import { useUserStore } from '@/stores/userStore.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { db } from '@/lib/apiClient.js';

const route = useRoute();
const userStore = useUserStore();
const orderStore = useOrderStore();

const apiBase = import.meta.env.VITE_API_URL || '/api';
const sessionToken = localStorage.getItem('bk_session_token') || '';

const showForm = ref(false);
const tab = ref('new');
const sending = ref(false);
const showSuccess = ref(false);
const hasNewReplies = ref(false);

const form = ref({ title: '', description: '' });
const screenshots = ref([]);
const previewImage = ref(null);

// Лог действий пользователя
const actionLog = [];
const MAX_LOG = 30;

function logAction(action) {
  const ts = new Date().toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  actionLog.push(`[${ts}] ${action}`);
  if (actionLog.length > MAX_LOG) actionLog.shift();
}

// Перехват переходов по страницам
let stopRouteWatch = null;
onMounted(() => {
  logAction('Открыта: ' + route.path);
  stopRouteWatch = route;

  // Клики
  document.addEventListener('click', onDocClick, true);
  // Вставка из буфера (Ctrl+V)
  document.addEventListener('paste', onPaste);
  // Ошибки
  window.addEventListener('error', onGlobalError);

  // Проверить новые ответы
  checkNewReplies();
});

// Polling: обновлять чат каждые 5 сек, пока открыто окно
let chatPollTimer = null;

function startChatPoll() {
  stopChatPoll();
  chatPollTimer = setInterval(async () => {
    // Если смотрим конкретное обращение — обновить ответы
    if (viewingReport.value) {
      try {
        const { data } = await db.rpc('get_bug_report', { id: viewingReport.value.id });
        if (data) {
          const hadCount = viewingReplies.value.length;
          viewingReport.value = data.report;
          viewingReplies.value = data.replies || [];
          if ((data.replies || []).length > hadCount) scrollToBottom();
        }
      } catch {}
    }
    // Обновить список обращений
    if (tab.value === 'my') {
      try {
        const { data } = await db.rpc('get_bug_reports', {});
        if (data?.reports) myReports.value = data.reports;
      } catch {}
    }
  }, 5000);
}

function stopChatPoll() {
  if (chatPollTimer) { clearInterval(chatPollTimer); chatPollTimer = null; }
}

watch(showForm, (open) => {
  if (open) startChatPoll();
  else stopChatPoll();
});

onUnmounted(() => {
  stopChatPoll();
  document.removeEventListener('click', onDocClick, true);
  document.removeEventListener('paste', onPaste);
  window.removeEventListener('error', onGlobalError);
});

function onDocClick(e) {
  const el = e.target.closest('button, a, .sidebar-item, .db-tab, .btn');
  if (el) {
    const text = (el.textContent || '').trim().substring(0, 50);
    logAction('Клик: ' + text);
  }
}

function onGlobalError(e) {
  logAction('Ошибка JS: ' + (e.message || '').substring(0, 100));
}

function toggleForm() {
  showForm.value = !showForm.value;
  if (showForm.value) {
    tab.value = 'new';
    form.value = { title: '', description: '' };
    screenshots.value = [];
  }
}

// --- Скриншоты ---
async function uploadFile(file) {
  if (screenshots.value.length >= 5) return;
  if (!file.type.startsWith('image/')) return;
  const preview = URL.createObjectURL(file);
  const item = { preview, path: null, uploading: true };
  screenshots.value.push(item);
  try {
    const fd = new FormData();
    fd.append('file', file);
    const token = localStorage.getItem('bk_session_token') || '';
    const res = await fetch(apiBase + '/upload/bug-screenshot', {
      method: 'POST',
      body: fd,
      headers: { 'X-Session-Token': token },
    });
    const data = await res.json();
    if (data.path) {
      item.path = data.path;
    } else {
      screenshots.value = screenshots.value.filter(s => s !== item);
    }
  } catch {
    screenshots.value = screenshots.value.filter(s => s !== item);
  } finally {
    item.uploading = false;
  }
}

async function onFilesSelected(e) {
  const files = Array.from(e.target.files || []);
  e.target.value = '';
  for (const file of files) {
    await uploadFile(file);
  }
}

function onPaste(e) {
  if (!showForm.value || tab.value !== 'new') return;
  const items = e.clipboardData?.items;
  if (!items) return;
  for (const item of items) {
    if (item.type.startsWith('image/')) {
      e.preventDefault();
      const file = item.getAsFile();
      if (file) uploadFile(file);
    }
  }
}

function removeScreenshot(i) {
  screenshots.value.splice(i, 1);
}

// --- Отправка ---
async function submit() {
  if (!form.value.title.trim() || sending.value) return;
  sending.value = true;
  try {
    logAction('Отправка обращения: ' + form.value.title);
    const { error } = await db.rpc('create_bug_report', {
      title: form.value.title.trim(),
      description: form.value.description.trim(),
      screenshots: screenshots.value.filter(s => s.path).map(s => s.path),
      action_log: actionLog.join('\n'),
      page_url: window.location.href,
      legal_entity: orderStore.settings.legalEntity,
      browser_info: navigator.userAgent.substring(0, 500),
    });
    if (error) return;
    form.value = { title: '', description: '' };
    screenshots.value = [];
    // Переключить на «Мои обращения» и показать новое
    await loadMyReports();
    tab.value = 'my';
    showSuccess.value = true;
    setTimeout(() => { showSuccess.value = false; }, 3000);
  } finally {
    sending.value = false;
  }
}

// --- Мои обращения ---
const myReports = ref([]);
const loadingReports = ref(false);
const viewingReport = ref(null);
const viewingReplies = ref([]);
const replyText = ref('');
const sendingReply = ref(false);
const replyImages = ref([]);
const chatMessagesEl = ref(null);

function scrollToBottom() {
  nextTick(() => {
    setTimeout(() => {
      if (chatMessagesEl.value) chatMessagesEl.value.scrollTop = chatMessagesEl.value.scrollHeight;
    }, 50);
  });
}

function autoResize(e) {
  const el = e.target;
  el.style.height = 'auto';
  el.style.height = Math.min(el.scrollHeight, 120) + 'px';
}

function renderMsgContent(msg) {
  if (!msg) return '';
  const escaped = msg.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  return escaped.replace(/\[img:(.*?)\]/g, (_, path) => {
    const src = apiBase + '/' + path + '?token=' + sessionToken;
    return '<img src="' + src + '" class="bug-reply-img" onclick="window.open(this.src)" />';
  });
}

async function uploadReplyImage(file) {
  if (!file.type.startsWith('image/')) return;
  const preview = URL.createObjectURL(file);
  const item = { preview, path: null, uploading: true };
  replyImages.value.push(item);
  try {
    const fd = new FormData();
    fd.append('file', file);
    const res = await fetch(apiBase + '/upload/bug-screenshot', {
      method: 'POST',
      body: fd,
      headers: { 'X-Session-Token': sessionToken },
    });
    const data = await res.json();
    if (data.path) item.path = data.path;
    else replyImages.value = replyImages.value.filter(x => x !== item);
  } catch {
    replyImages.value = replyImages.value.filter(x => x !== item);
  } finally {
    item.uploading = false;
  }
}

function onReplyFiles(e) {
  for (const f of Array.from(e.target.files || [])) uploadReplyImage(f);
  e.target.value = '';
}

function onReplyPaste(e) {
  const items = e.clipboardData?.items;
  if (!items) return;
  for (const item of items) {
    const file = item.kind === 'file' ? item.getAsFile() : null;
    if (file && file.type.startsWith('image/')) {
      e.preventDefault();
      uploadReplyImage(file);
      return;
    }
  }
}

async function loadMyReports() {
  loadingReports.value = true;
  try {
    const { data } = await db.rpc('get_bug_reports', {});
    myReports.value = data?.reports || [];
  } finally {
    loadingReports.value = false;
  }
}

async function openReport(r) {
  const { data } = await db.rpc('get_bug_report', { id: r.id });
  if (data?.report) {
    viewingReport.value = data.report;
    viewingReplies.value = data.replies || [];
    replyText.value = '';
    scrollToBottom();
  }
}

async function sendReply() {
  const text = replyText.value.trim();
  const images = replyImages.value.filter(x => x.path).map(x => x.path);
  if (!text && !images.length) return;
  if (sendingReply.value) return;
  sendingReply.value = true;
  try {
    let msg = text;
    if (images.length) {
      const imgTags = images.map(p => '[img:' + p + ']').join(' ');
      msg = msg ? msg + '\n' + imgTags : imgTags;
    }
    await db.rpc('reply_bug_report', { report_id: viewingReport.value.id, message: msg });
    replyText.value = '';
    replyImages.value = [];
    const { data } = await db.rpc('get_bug_report', { id: viewingReport.value.id });
    if (data) {
      viewingReport.value = data.report;
      viewingReplies.value = data.replies || [];
      scrollToBottom();
    }
  } finally {
    sendingReply.value = false;
  }
}

async function checkNewReplies() {
  try {
    const { data } = await db.rpc('get_bug_reports_count', {});
    if (data?.new_count > 0 && !userStore.isAdmin) {
      hasNewReplies.value = true;
    }
  } catch {}
}

function statusLabel(s) {
  return { new: 'Новое', in_progress: 'В работе', resolved: 'Решено', closed: 'Закрыто' }[s] || s;
}

function formatDate(str) {
  if (!str) return '';
  const d = new Date(str);
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' }) + ' ' +
    d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}
</script>

<style scoped>
/* ═══ Floating Action Button ═══ */
.bug-fab-wrap {
  position: fixed;
  bottom: 20px;
  right: 20px;
  z-index: 9990;
}
.bug-fab {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  padding: 0;
  background: linear-gradient(135deg, #D62300 0%, #FF5722 100%);
  color: #fff;
  border: none;
  border-radius: 50%;
  cursor: pointer;
  font-family: inherit;
  box-shadow: 0 3px 14px rgba(214,35,0,0.35), 0 1px 6px rgba(0,0,0,0.12);
  transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
  position: relative;
  overflow: visible;
}
.bug-fab:hover {
  transform: translateY(-2px) scale(1.08);
  box-shadow: 0 5px 22px rgba(214,35,0,0.45), 0 2px 10px rgba(0,0,0,0.18);
}
.bug-fab.active {
  width: 36px;
  height: 36px;
  background: linear-gradient(135deg, #555 0%, #333 100%);
  box-shadow: 0 3px 12px rgba(0,0,0,0.3);
}
.bug-fab.active:hover {
  background: linear-gradient(135deg, #666 0%, #444 100%);
}
.bug-fab-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  transition: transform 0.3s;
}
.bug-fab-icon.spin {
  transform: rotate(90deg);
}
.bug-fab-badge {
  position: absolute;
  top: -4px;
  right: -4px;
  width: 18px;
  height: 18px;
  background: #FFD700;
  color: #333;
  border-radius: 50%;
  font-size: 11px;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
  animation: bug-pulse 2s infinite;
}
.bug-fab.has-replies {
  animation: bug-glow 2s infinite;
}
@keyframes bug-pulse {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.2); }
}
@keyframes bug-glow {
  0%, 100% { box-shadow: 0 4px 20px rgba(214,35,0,0.35); }
  50% { box-shadow: 0 4px 30px rgba(255,215,0,0.5), 0 0 20px rgba(255,215,0,0.3); }
}

/* ═══ Modal ═══ */
.bug-overlay {
  position: fixed;
  inset: 0;
  z-index: 9995;
  background: rgba(44,24,16,0.4);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: flex-end;
  justify-content: flex-end;
  padding: 24px;
}
.bug-modal {
  background: #fff;
  border-radius: 16px;
  width: 420px;
  max-width: calc(100vw - 32px);
  max-height: calc(100vh - 48px);
  min-height: 480px;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  box-shadow: 0 20px 60px rgba(0,0,0,0.2), 0 0 0 1px rgba(0,0,0,0.05);
  animation: bug-slide-up 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}
@keyframes bug-slide-up {
  from { opacity: 0; transform: translateY(20px) scale(0.95); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}

/* Tabs */
.bug-tabs {
  display: flex;
  border-bottom: 1px solid #f0ebe4;
  padding: 0 4px;
}
.bug-tab {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 14px 12px;
  background: none;
  border: none;
  border-bottom: 2px solid transparent;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  color: #7a6b5f;
  font-family: inherit;
  transition: 0.15s;
}
.bug-tab:hover { color: #2c1810; }
.bug-tab.active {
  color: #D62300;
  border-bottom-color: #D62300;
}
.bug-tab-count {
  background: #f0ebe4;
  color: #7a6b5f;
  font-size: 10px;
  padding: 1px 6px;
  border-radius: 10px;
}

/* Form */
.bug-form {
  padding: 16px 20px 20px;
  overflow-y: auto;
  flex: 1;
}
.bug-form-group {
  margin-bottom: 14px;
}
.bug-form-group label {
  display: block;
  font-size: 12px;
  font-weight: 600;
  color: #7a6b5f;
  margin-bottom: 5px;
}
.bug-input {
  width: 100%;
  padding: 9px 12px;
  border: 1.5px solid #e8e0d6;
  border-radius: 10px;
  font-size: 13px;
  font-family: inherit;
  background: #fff;
  transition: 0.15s;
  box-sizing: border-box;
}
.bug-input:focus {
  outline: none;
  border-color: #D62300;
  box-shadow: 0 0 0 3px rgba(214,35,0,0.08);
}
.bug-textarea {
  resize: vertical;
  min-height: 80px;
}

/* Screenshots */
.bug-screenshots {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.bug-screenshot-item {
  position: relative;
  width: 64px;
  height: 64px;
  border-radius: 10px;
  overflow: hidden;
  border: 1.5px solid #e8e0d6;
}
.bug-screenshot-item img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  cursor: pointer;
}
.bug-screenshot-remove {
  position: absolute;
  top: 2px;
  right: 2px;
  width: 18px;
  height: 18px;
  background: rgba(0,0,0,0.6);
  color: #fff;
  border: none;
  border-radius: 50%;
  cursor: pointer;
  font-size: 12px;
  line-height: 1;
  display: flex;
  align-items: center;
  justify-content: center;
}
.bug-screenshot-uploading {
  position: absolute;
  inset: 0;
  background: rgba(255,255,255,0.7);
  display: flex;
  align-items: center;
  justify-content: center;
}
.bug-upload-spinner {
  width: 20px;
  height: 20px;
  border: 2px solid #e8e0d6;
  border-top-color: #D62300;
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
.bug-screenshot-add {
  width: 64px;
  height: 64px;
  border: 1.5px dashed #d0c8be;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  color: #a09488;
  transition: 0.15s;
  flex-shrink: 0;
}
.bug-screenshot-add:hover {
  border-color: #D62300;
  color: #D62300;
  background: rgba(214,35,0,0.03);
}
.bug-paste-hint {
  font-size: 10px;
  color: #b0a498;
  margin-top: 4px;
}

/* Footer */
.bug-form-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 6px;
}
.bug-page-info {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 10px;
  color: #a09488;
}
.bug-submit {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 9px 20px;
  background: linear-gradient(135deg, #D62300 0%, #FF5722 100%);
  color: #fff;
  border: none;
  border-radius: 10px;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  font-family: inherit;
  transition: 0.2s;
  white-space: nowrap;
}
.bug-submit:hover:not(:disabled) {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(214,35,0,0.3);
}
.bug-submit:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.bug-submit-spinner {
  width: 14px;
  height: 14px;
  border: 2px solid rgba(255,255,255,0.3);
  border-top-color: #fff;
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
  display: inline-block;
}

/* ═══ My reports ═══ */
.bug-my-reports {
  padding: 12px 16px 16px;
  min-height: 200px;
  overflow-y: auto;
  flex: 1;
}
.bug-loading, .bug-empty {
  text-align: center;
  padding: 30px 0;
  color: #a09488;
  font-size: 13px;
}
.bug-empty p { margin-top: 8px; }
.bug-report-card {
  padding: 12px;
  border: 1px solid #f0ebe4;
  border-radius: 10px;
  margin-bottom: 8px;
  cursor: pointer;
  transition: 0.15s;
}
.bug-report-card:hover {
  border-color: #D62300;
  background: rgba(214,35,0,0.02);
}
.bug-report-card-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 4px;
}
.bug-report-card-title {
  font-size: 13px;
  font-weight: 600;
  color: #2c1810;
}
.bug-report-card-replies {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  color: #7a6b5f;
  margin-top: 4px;
}
.bug-status {
  font-size: 10px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 8px;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}
.bug-status.st-new { background: #FFF3E0; color: #E65100; }
.bug-status.st-in_progress { background: #E3F2FD; color: #1565C0; }
.bug-status.st-resolved { background: #E8F5E9; color: #2E7D32; }
.bug-status.st-closed { background: #F5F5F5; color: #757575; }
.bug-date {
  font-size: 11px;
  color: #a09488;
}

/* ═══ Chat View ═══ */
.chat-header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  border-bottom: 1px solid #f0ebe4;
  flex-shrink: 0;
}
.chat-back {
  background: none;
  border: none;
  cursor: pointer;
  color: #7a6b5f;
  padding: 4px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.chat-back:hover { background: #f5f2ee; color: #2c1810; }
.chat-header-info { flex: 1; min-width: 0; }
.chat-header-title {
  font-size: 13px;
  font-weight: 700;
  color: #2c1810;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.chat-header-status { margin-top: 2px; }

/* Лента сообщений */
.chat-messages {
  flex: 1;
  overflow-y: auto;
  padding: 14px 14px 8px;
  display: flex;
  flex-direction: column;
  gap: 6px;
  background: #f9f7f4;
}
.chat-bubble {
  max-width: 82%;
  display: flex;
  flex-direction: column;
}
.chat-bubble.me {
  align-self: flex-end;
}
.chat-bubble.them {
  align-self: flex-start;
}
.chat-bubble-name {
  font-size: 10px;
  font-weight: 700;
  color: #2E7D32;
  margin-bottom: 2px;
  padding-left: 10px;
}
.chat-bubble-body {
  padding: 8px 12px;
  border-radius: 14px;
  font-size: 13px;
  line-height: 1.45;
  word-break: break-word;
}
.chat-bubble.me .chat-bubble-body {
  background: #D62300;
  color: #fff;
  border-bottom-right-radius: 4px;
}
.chat-bubble.them .chat-bubble-body {
  background: #fff;
  color: #2c1810;
  border-bottom-left-radius: 4px;
  box-shadow: 0 1px 2px rgba(0,0,0,0.06);
}
.chat-bubble-text {
  white-space: pre-wrap;
}
.chat-bubble-text :deep(img) {
  max-width: 180px;
  max-height: 140px;
  border-radius: 8px;
  margin-top: 4px;
  cursor: pointer;
  display: block;
}
.chat-bubble-imgs {
  display: flex;
  gap: 4px;
  flex-wrap: wrap;
  margin-top: 6px;
}
.chat-bubble-imgs img {
  width: 72px;
  height: 72px;
  object-fit: cover;
  border-radius: 8px;
  cursor: pointer;
  border: 1px solid rgba(255,255,255,0.3);
}
.chat-bubble-imgs img:hover { opacity: 0.85; }
.chat-bubble-time {
  font-size: 10px;
  color: #a09488;
  margin-top: 2px;
  padding: 0 4px;
}
.chat-bubble.me .chat-bubble-time { text-align: right; }

/* Закрыто */
.chat-closed {
  padding: 10px 14px;
  text-align: center;
  font-size: 12px;
  color: #757575;
  font-weight: 600;
  background: #f5f5f5;
  flex-shrink: 0;
}

/* Поле ввода */
.chat-input-area {
  border-top: 1px solid #f0ebe4;
  padding: 8px 10px;
  background: #fff;
  flex-shrink: 0;
}
.chat-input-previews {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  margin-bottom: 8px;
  padding: 0 4px;
}
.chat-input-thumb {
  position: relative;
  width: 48px;
  height: 48px;
}
.chat-input-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  border-radius: 8px;
  border: 1px solid #e0d6cc;
}
.chat-input-thumb-rm {
  position: absolute;
  top: -5px;
  right: -5px;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  background: #D62300;
  color: #fff;
  border: none;
  font-size: 11px;
  line-height: 1;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}
.chat-input-thumb-load {
  position: absolute;
  inset: 0;
  background: rgba(255,255,255,0.7);
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.chat-input-thumb-load::after {
  content: '';
  width: 14px;
  height: 14px;
  border: 2px solid #D62300;
  border-top-color: transparent;
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
}
.chat-input-row {
  display: flex;
  align-items: flex-end;
  gap: 6px;
}
.chat-attach {
  cursor: pointer;
  color: #7a6b5f;
  padding: 6px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  flex-shrink: 0;
  transition: 0.15s;
}
.chat-attach:hover { color: #D62300; background: rgba(214,35,0,0.05); }
.chat-textarea {
  flex: 1;
  padding: 8px 12px;
  border: 1.5px solid #e8e0d6;
  border-radius: 18px;
  font-size: 13px;
  font-family: inherit;
  background: #f9f7f4;
  resize: none;
  min-height: 36px;
  max-height: 120px;
  line-height: 1.4;
  box-sizing: border-box;
  transition: 0.15s;
  overflow-y: auto;
}
.chat-textarea:focus {
  outline: none;
  border-color: #D62300;
  background: #fff;
}
.chat-send {
  width: 36px;
  height: 36px;
  padding: 0;
  background: #D62300;
  color: #fff;
  border: none;
  border-radius: 50%;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  transition: 0.2s;
}
.chat-send:hover:not(:disabled) {
  background: #b81e00;
  transform: scale(1.05);
}
.chat-send:disabled { opacity: 0.35; cursor: not-allowed; }

/* ═══ Preview ═══ */
.bug-preview-overlay {
  position: fixed;
  inset: 0;
  z-index: 99999;
  background: rgba(0,0,0,0.85);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: zoom-out;
}
.bug-preview-img {
  max-width: 90vw;
  max-height: 90vh;
  border-radius: 8px;
  box-shadow: 0 8px 40px rgba(0,0,0,0.5);
}

/* ═══ Success toast ═══ */
.bug-success-toast {
  position: fixed;
  bottom: 90px;
  right: 24px;
  z-index: 99998;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 20px;
  background: linear-gradient(135deg, #2E7D32, #43A047);
  color: #fff;
  border-radius: 12px;
  font-size: 14px;
  font-weight: 600;
  box-shadow: 0 6px 24px rgba(46,125,50,0.35);
}

/* ═══ Transitions ═══ */
.bug-modal-enter-active { transition: all 0.3s ease; }
.bug-modal-leave-active { transition: all 0.2s ease; }
.bug-modal-enter-from { opacity: 0; }
.bug-modal-leave-to { opacity: 0; }

.bug-success-enter-active { transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); }
.bug-success-leave-active { transition: all 0.3s ease; }
.bug-success-enter-from { opacity: 0; transform: translateY(20px); }
.bug-success-leave-to { opacity: 0; transform: translateY(-10px); }

/* ═══ Mobile ═══ */
@media (max-width: 640px) {
  .bug-fab-wrap { bottom: 14px; right: 14px; }
  .bug-fab { width: 36px; height: 36px; }
  .bug-overlay { padding: 0; align-items: flex-end; justify-content: center; }
  .bug-modal {
    width: 100%;
    max-width: 100%;
    border-radius: 16px 16px 0 0;
    max-height: 85vh;
  }
  .bug-success-toast { right: 16px; bottom: 70px; }
}
</style>
