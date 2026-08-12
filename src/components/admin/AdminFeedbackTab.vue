<template>
<div class="fb-messenger">
      <!-- Левая панель: список -->
      <div class="fb-sidebar">
        <div class="fb-sidebar-top">
          <select v-model="bugFilterStatus" class="bug-filter-select">
            <option value="">Все ({{ bugReports.length }})</option>
            <option value="new">Новые</option>
            <option value="in_progress">В работе</option>
            <option value="resolved">Решённые</option>
            <option value="closed">Закрытые</option>
          </select>
          <button class="btn fb-refresh" @click="loadBugReports" :disabled="bugLoading"><BkIcon name="redo" size="sm"/></button>
        </div>
        <div class="fb-search">
          <BkIcon name="search" size="sm"/>
          <input v-model="bugSearch" type="search" placeholder="Автор, тема или текст" />
          <button v-if="bugSearch" class="fb-search-clear" @click="bugSearch = ''" title="Очистить">
            <BkIcon name="close" size="sm"/>
          </button>
        </div>
        <div class="fb-list">
          <div v-if="bugLoading && !bugReports.length" class="fb-state"><BurgerSpinner text="Загрузка…" /></div>
          <div v-else-if="!filteredBugReports.length" class="fb-state">{{ bugSearch ? 'Ничего не нашлось' : 'Нет обращений' }}</div>
          <div
            v-for="r in filteredBugReports" :key="r.id"
            class="fb-item" :class="{ active: bugDetail?.id === r.id, 'is-new': r.status === 'new' }"
            @click="openBugDetail(r)"
          >
            <div class="fb-item-top">
              <span class="fb-item-status" :class="'st-' + r.status"></span>
              <span class="fb-item-author">{{ r.created_by }}</span>
              <span class="fb-item-date">{{ formatBugDate(r.created_at) }}</span>
            </div>
            <div class="fb-item-title">{{ r.title }}</div>
            <div class="fb-item-bottom">
              <span class="fb-item-entity">{{ r.legal_entity || '' }}</span>
              <span v-if="r.reply_count" class="fb-item-replies"><BkIcon name="chat" size="sm" /> {{ r.reply_count }}</span>
              <span v-if="r.screenshots?.length" class="fb-item-attach"><BkIcon name="link" size="sm" /> {{ r.screenshots.length }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Правая панель: чат -->
      <div class="fb-chat">
        <template v-if="bugDetail">
          <!-- Шапка чата -->
          <div class="fb-chat-header">
            <div style="display:flex;align-items:center;gap:8px;flex:1;min-width:0;">
              <select v-model="bugDetail.status" @change="updateBugStatus(bugDetail)" class="bug-filter-select" style="font-weight:600;font-size:11px;padding:3px 6px;">
                <option value="new">Новое</option>
                <option value="in_progress">В работе</option>
                <option value="resolved">Решено</option>
                <option value="closed">⚫ Закрыто</option>
              </select>
              <span class="fb-chat-title">{{ bugDetail.title }}</span>
            </div>
            <button class="fb-del-btn" @click="deleteBugReport(bugDetail)" title="Удалить"><BkIcon name="delete" size="sm"/></button>
          </div>

          <!-- Описание (сворачиваемое) -->
          <details class="fb-chat-info">
            <summary>
              {{ bugDetail.created_by }} · {{ formatBugDate(bugDetail.created_at) }}
              <span v-if="bugDetail.screenshots?.length"> · <BkIcon name="link" size="sm" /> {{ bugDetail.screenshots.length }}</span>
            </summary>
            <div class="fb-chat-info-body">
              <p v-if="bugDetail.description" style="font-size:13px;color:var(--text-secondary);white-space:pre-wrap;margin:0 0 8px;line-height:1.5;">{{ bugDetail.description }}</p>
              <div v-if="bugDetail.screenshots?.length" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;">
                <a v-for="(s, i) in bugDetail.screenshots" :key="i" :href="bugImageUrl(s)" target="_blank">
                  <img :src="bugImageUrl(s)" style="width:72px;height:72px;object-fit:cover;border-radius:8px;border:1px solid var(--border);" />
                </a>
              </div>
              <div v-if="bugDetail.page_url" style="font-size:11px;color:var(--text-muted);word-break:break-all;"><b>Страница:</b> {{ bugDetail.page_url }}</div>
              <details v-if="bugDetail.action_log" style="margin-top:4px;">
                <summary style="font-size:11px;color:var(--text-muted);cursor:pointer;">Лог действий</summary>
                <pre style="font-size:10px;background:var(--bg);padding:6px 8px;border-radius:6px;margin-top:4px;white-space:pre-wrap;max-height:150px;overflow-y:auto;">{{ bugDetail.action_log }}</pre>
              </details>
            </div>
          </details>

          <!-- Сообщения -->
          <div class="fb-chat-messages" ref="bugChatScroll">
            <div v-if="!bugReplies.length" style="text-align:center;padding:40px;color:var(--text-muted);font-size:12px;">Нет сообщений — напишите ответ</div>
            <div v-for="r in bugReplies" :key="r.id" class="fb-msg" :class="{ admin: r.is_admin }">
              <div class="fb-msg-meta">
                <span :style="r.is_admin ? 'color:#2E7D32' : ''">{{ r.created_by }}{{ r.is_admin ? ' (вы)' : '' }}</span>
                <span>{{ formatBugDate(r.created_at) }}</span>
              </div>
              <div class="fb-msg-text" v-html="renderMsgContent(r.message, bugImageUrls)" @click="onBugMsgClick" :data-img-rev="Object.keys(bugImageUrls).length"></div>
            </div>
          </div>

          <!-- Превью вложений -->
          <div v-if="bugReplyImages.length" class="fb-attach-preview">
            <div v-for="(img, i) in bugReplyImages" :key="i" class="fb-attach-thumb">
              <img :src="img.preview" />
              <button @click="bugReplyImages.splice(i, 1)" class="fb-attach-remove">&times;</button>
              <div v-if="img.uploading" class="fb-attach-loading"></div>
            </div>
          </div>

          <!-- Ввод -->
          <div class="fb-chat-input">
            <label class="fb-attach-btn" title="Прикрепить фото">
              <input type="file" accept="image/*" multiple @change="onBugReplyFiles" style="display:none" />
              <BkIcon name="link" size="sm" />
            </label>
            <textarea v-model="bugReplyText" class="bug-reply-input" placeholder="Enter — отправить" rows="1" @keydown.enter.exact.prevent="sendBugReply" @input="autoResizeReply" @paste="onBugReplyPaste"></textarea>
            <button class="btn primary" :disabled="(!bugReplyText.trim() && !bugReplyImages.length) || bugReplySending" @click="sendBugReply" style="font-size:13px;padding:8px 16px;align-self:flex-end;">
              {{ bugReplySending ? '...' : '→' }}
            </button>
          </div>
        </template>

        <!-- Пустое состояние -->
        <div v-else class="fb-chat-empty">
          <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="var(--border)" stroke-width="1"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
          <p>Выберите обращение из списка</p>
        </div>
      </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { db } from '@/lib/apiClient.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import { useToastStore } from '@/stores/toastStore.js';
import { appConfirm } from '@/lib/appDialogs.js';
import { parseMoscowDate } from '@/lib/utils.js';

const emit = defineEmits(['count']);
const toast = useToastStore();

// ═══ Обращения (баг-репорты) ═══
const apiBase = import.meta.env.VITE_API_URL || '/api';
const sessionToken = localStorage.getItem('bk_session_token') || '';
const bugReports = ref([]);
const bugLoading = ref(false);
const bugFilterStatus = ref('');
const bugNewCount = ref(0);
const bugDetail = ref(null);
const bugReplies = ref([]);
const bugReplyText = ref('');
const bugReplySending = ref(false);

// Карта одноразовых URL для картинок багрепорта (path → URL).
// Заполняется при открытии bugDetail и при обновлении ответов.
const bugImageUrls = ref({});
function bugImageUrl(path) { return bugImageUrls.value[path] || ''; }
async function refreshBugImageUrls() {
  const paths = new Set();
  for (const s of (bugDetail.value?.screenshots || [])) paths.add(s);
  for (const r of (bugReplies.value || [])) {
    const re = /\[img:([^\]]+)\]/g;
    let m;
    while ((m = re.exec(r.message || '')) !== null) {
      const raw = m[1];
      if (/^uploads\/[a-zA-Z0-9_\-/.]+$/.test(raw) && !raw.includes('..')) paths.add(raw);
    }
  }
  const map = { ...bugImageUrls.value };
  for (const p of paths) {
    if (map[p]) continue;
    try {
      const { data } = await db.rpc('create_download_token', { file_path: p });
      if (data?.token) {
        const sep = p.includes('?') ? '&' : '?';
        map[p] = `${apiBase}/${p}${sep}dl=${encodeURIComponent(data.token)}`;
      }
    } catch { map[p] = ''; }
  }
  bugImageUrls.value = map;
}
watch([bugDetail, bugReplies], () => { refreshBugImageUrls(); }, { deep: false });

const bugSearch = ref('');

const filteredBugReports = computed(() => {
  const q = bugSearch.value.trim().toLowerCase();
  return bugReports.value.filter(r => {
    if (bugFilterStatus.value && r.status !== bugFilterStatus.value) return false;
    if (!q) return true;
    return `${r.created_by || ''} ${r.title || ''} ${r.description || ''} ${r.legal_entity || ''}`
      .toLowerCase().includes(q);
  });
});

async function loadBugReports() {
  bugLoading.value = true;
  try {
    const { data } = await db.rpc('get_bug_reports', {});
    bugReports.value = data?.reports || [];
    bugNewCount.value = bugReports.value.filter(r => r.status === 'new').length;
  } finally {
    bugLoading.value = false;
  }
}

async function openBugDetail(r) {
  const { data } = await db.rpc('get_bug_report', { id: r.id });
  if (data?.report) {
    bugDetail.value = data.report;
    bugReplies.value = data.replies || [];
    bugReplyText.value = '';
    scrollChatToBottom();
  }
}

async function updateBugStatus(r) {
  await db.rpc('update_bug_report_status', { id: r.id, status: r.status });
  toast.success('Статус обновлён');
  loadBugReports();
}

const bugChatScroll = ref(null);
const bugReplyImages = ref([]);

function onBugMsgClick(e) {
  const t = e.target;
  if (t && t.tagName === 'IMG' && t.dataset.bugImg === '1') {
    window.open(t.src, '_blank', 'noopener');
  }
}

// Второй аргумент urlsMap — { path: url }. Vue ловит изменения через
// :data-img-rev в template-узле, поэтому рендер пересчитывается при
// заполнении одноразовых URL картинок. Default НЕ пишем в сигнатуре,
// чтобы избежать TDZ-ошибки при ранних вызовах из watch immediate
// (минификатор иногда переставляет порядок объявлений).
function renderMsgContent(msg, urlsMap) {
  if (!urlsMap) urlsMap = bugImageUrls.value;
  if (!msg) return '';
  // Экранируем всё, включая кавычки — чтобы нельзя было вырваться из src="..."
  const escapeHtml = (s) => s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
  // Идём по оригинальному тексту, собираем результат из экранированных кусков
  const re = /\[img:([^\]]*?)\]/g;
  let result = '';
  let lastIdx = 0;
  let m;
  while ((m = re.exec(msg)) !== null) {
    result += escapeHtml(msg.slice(lastIdx, m.index));
    const raw = m[1] || '';
    // Белый список: только пути uploads/... с безопасными символами
    if (/^uploads\/[a-zA-Z0-9_\-/.]+$/.test(raw) && !raw.includes('..')) {
      // URL берём из urlsMap — заполняется заранее в refreshBugImageUrls.
      const url = (urlsMap || {})[raw] || '';
      const src = escapeHtml(url);
      result += '<img src="' + src + '" class="fb-msg-img" data-bug-img="1" />';
    }
    lastIdx = m.index + m[0].length;
  }
  result += escapeHtml(msg.slice(lastIdx));
  return result;
}

async function uploadBugImage(file) {
  if (!file.type.startsWith('image/')) return;
  const preview = URL.createObjectURL(file);
  const item = { preview, path: null, uploading: true };
  bugReplyImages.value.push(item);
  try {
    const fd = new FormData();
    fd.append('file', file);
    const res = await fetch(apiBase + '/upload/bug-screenshot', {
      method: 'POST', body: fd,
      headers: { 'X-Session-Token': sessionToken },
    });
    const data = await res.json();
    item.path = data.path || null;
    if (!item.path) bugReplyImages.value = bugReplyImages.value.filter(x => x !== item);
  } catch { bugReplyImages.value = bugReplyImages.value.filter(x => x !== item); }
  finally { item.uploading = false; }
}

function onBugReplyFiles(e) {
  for (const f of Array.from(e.target.files || [])) uploadBugImage(f);
  e.target.value = '';
}

function onBugReplyPaste(e) {
  const items = e.clipboardData?.items;
  if (!items) return;
  for (const item of items) {
    if (item.type.startsWith('image/')) {
      e.preventDefault();
      const file = item.getAsFile();
      if (file) uploadBugImage(file);
    }
  }
}

function scrollChatToBottom() {
  nextTick(() => {
    const el = bugChatScroll.value;
    if (el) el.scrollTop = el.scrollHeight;
  });
}

function autoResizeReply(e) {
  const el = e.target;
  el.style.height = 'auto';
  el.style.height = Math.min(el.scrollHeight, 100) + 'px';
}

async function sendBugReply() {
  if (!bugDetail.value?.id) return;
  const text = bugReplyText.value.trim();
  const images = bugReplyImages.value.filter(x => x.path).map(x => x.path);
  if (!text && !images.length) return;
  if (bugReplySending.value) return;
  bugReplySending.value = true;
  try {
    let msg = text;
    if (images.length) {
      const imgTags = images.map(p => '[img:' + p + ']').join(' ');
      msg = msg ? msg + '\n' + imgTags : imgTags;
    }
    const reportId = bugDetail.value.id;
    const { error } = await db.rpc('reply_bug_report', { report_id: reportId, message: msg });
    if (error) { toast.error('Не удалось отправить', error); return; }
    bugReplyText.value = '';
    bugReplyImages.value = [];
    const { data } = await db.rpc('get_bug_report', { id: reportId });
    if (data) {
      bugDetail.value = data.report;
      bugReplies.value = data.replies || [];
    }
    scrollChatToBottom();
    loadBugReports();
  } finally {
    bugReplySending.value = false;
  }
}

async function deleteBugReport(r) {
  if (!(await appConfirm('Удалить обращение #' + r.id + '?', { okText: 'Удалить', danger: true }))) return;
  await db.rpc('delete_bug_report', { id: r.id });
  bugDetail.value = null;
  toast.success('Обращение удалено');
  loadBugReports();
}

function bugStatusLabel(s) {
  return { new: 'Новое', in_progress: 'В работе', resolved: 'Решено', closed: 'Закрыто' }[s] || s;
}

// Время с сервера московское — своя копия читала его как местное.
function formatBugDate(str) {
  const d = parseMoscowDate(str);
  if (!d || isNaN(d.getTime())) return '';
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', timeZone: 'Europe/Moscow' }) + ' ' +
    d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Moscow' });
}

let bugPollTimer = null;

async function bugPoll() {
  if (typeof document !== 'undefined' && document.visibilityState !== 'visible') return;
  try {
    const { data } = await db.rpc('get_bug_reports', {});
    if (data?.reports) {
      bugReports.value = data.reports;
      bugNewCount.value = data.reports.filter(r => r.status === 'new').length;
    }
    // Если открыта детальная карточка — обновить ответы
    if (bugDetail.value) {
      const oldCount = bugReplies.value.length;
      const { data: d2 } = await db.rpc('get_bug_report', { id: bugDetail.value.id });
      if (d2) {
        bugDetail.value = d2.report;
        bugReplies.value = d2.replies || [];
        if (d2.replies?.length > oldCount) scrollChatToBottom();
      }
    }
  } catch (e) { console.warn('[admin] bugPoll error:', e); }
}

// Частота зависит от того, читают ли переписку прямо сейчас.
// Раньше список обращений дёргался раз в 10 секунд всё время, пока
// открыта вкладка «Обращения» — 13 156 запросов в сутки ради счётчика.
// Быстрый опрос нужен только когда открыта карточка и человек ждёт
// ответа собеседника; список сам по себе меняется редко.
const BUG_POLL_FAST = 10000;
const BUG_POLL_IDLE = 60000;
let bugPollRate = 0;

function startBugPoll() {
  const rate = bugDetail.value ? BUG_POLL_FAST : BUG_POLL_IDLE;
  if (bugPollTimer && bugPollRate === rate) return;
  if (bugPollTimer) clearInterval(bugPollTimer);
  bugPollRate = rate;
  bugPollTimer = setInterval(bugPoll, rate);
}

function stopBugPoll() {
  if (bugPollTimer) { clearInterval(bugPollTimer); bugPollTimer = null; bugPollRate = 0; }
}

// Открыли или закрыли карточку обращения — переключаем частоту опроса.
watch(bugDetail, () => { startBugPoll(); });

watch(bugReports, () => emit('count', bugReports.value.length));

onMounted(() => {
  loadBugReports();
  startBugPoll();
});
onBeforeUnmount(stopBugPoll);
</script>

<style scoped>
.bug-filter-select {
  padding: 5px 10px;
  border: 1px solid var(--border);
  border-radius: 8px;
  font-size: 12px;
  font-family: inherit;
  background: var(--card);
}
.bug-reply-input {
  width: 100%;
  padding: 8px 12px;
  border: 1.5px solid var(--border);
  border-radius: 8px;
  font-size: 13px;
  font-family: inherit;
  resize: vertical;
  min-height: 40px;
}
.bug-reply-input:focus {
  outline: none;
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(231,111,81,0.08);
}
.fb-messenger {
  display: flex;
  gap: 0;
  height: calc(100vh - 160px);
  min-height: 400px;
  border: 1px solid var(--border);
  border-radius: 12px;
  overflow: hidden;
  background: var(--card);
}
.fb-sidebar {
  width: 320px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  border-right: 1px solid var(--border-light);
  background: var(--bg);
}
.fb-sidebar-top {
  display: flex;
  gap: 6px;
  padding: 10px 12px;
  border-bottom: 1px solid var(--border-light);
  flex-shrink: 0;
}
.fb-list {
  flex: 1;
  overflow-y: auto;
}
.fb-item {
  padding: 10px 14px;
  cursor: pointer;
  border-bottom: 1px solid var(--border-light);
  transition: background 0.1s;
}
.fb-item:hover { background: rgba(0,0,0,0.03); }
.fb-item.active { background: var(--card); border-left: 3px solid var(--accent); }
.fb-item.is-new { border-left: 3px solid #E65100; }
.fb-item.active.is-new { border-left-color: var(--accent); }
.fb-item-top {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 3px;
}
.fb-item-status {
  width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
}
.fb-item-status.st-new { background: #E65100; }
.fb-item-status.st-in_progress { background: #1565C0; }
.fb-item-status.st-resolved { background: #2E7D32; }
.fb-item-status.st-closed { background: #9E9E9E; }
.fb-item-author { font-size: 11px; font-weight: 600; color: var(--text-secondary); }
.fb-item-date { font-size: 10px; color: var(--text-muted); margin-left: auto; }
.fb-item-title {
  font-size: 13px; font-weight: 600; color: var(--text);
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.fb-item-bottom {
  display: flex; gap: 8px; margin-top: 2px;
  font-size: 10px; color: var(--text-muted);
}
.fb-chat {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.fb-chat-empty {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  color: var(--text-muted);
  font-size: 13px;
}
.fb-chat-header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  border-bottom: 1px solid var(--border-light);
  flex-shrink: 0;
}
.fb-chat-title {
  font-size: 14px; font-weight: 600;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.fb-del-btn {
  background: none; border: none; cursor: pointer; color: var(--text-muted);
  padding: 4px; border-radius: 6px; transition: 0.15s;
}
.fb-del-btn:hover { color: var(--error); background: rgba(211,47,47,0.08); }
.fb-chat-info {
  padding: 0 16px;
  border-bottom: 1px solid var(--border-light);
  font-size: 12px;
  color: var(--text-muted);
  flex-shrink: 0;
}
.fb-chat-info summary { padding: 8px 0; cursor: pointer; font-weight: 600; }
.fb-chat-info-body { padding: 0 0 10px; }
.fb-chat-messages {
  flex: 1;
  overflow-y: auto;
  padding: 12px 16px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.fb-msg {
  padding: 8px 12px;
  border-radius: 12px;
  background: var(--bg);
  max-width: 80%;
  align-self: flex-start;
}
.fb-msg.admin {
  background: #e8f5e9;
  align-self: flex-end;
}
.fb-msg-meta {
  display: flex; justify-content: space-between; gap: 8px;
  margin-bottom: 2px; font-size: 10px; font-weight: 600;
  color: var(--text-secondary);
}
.fb-msg-meta span:last-child { color: var(--text-muted); font-weight: 400; }
.fb-msg-text { font-size: 13px; white-space: pre-wrap; line-height: 1.45; }
.fb-msg-img { max-width: 200px; border-radius: 8px; margin-top: 4px; cursor: pointer; }
.fb-chat-input {
  display: flex; gap: 8px;
  padding: 10px 16px;
  border-top: 1px solid var(--border-light);
  flex-shrink: 0;
  align-items: flex-end;
}
.fb-chat-input textarea { flex: 1; resize: none; min-height: 36px; max-height: 100px; }
  .fb-messenger { flex-direction: column; height: auto; min-height: unset; }
  .fb-sidebar { width: 100%; max-height: 300px; border-right: none; border-bottom: 1px solid var(--border-light); }
  .fb-chat { min-height: 400px; }
</style>
