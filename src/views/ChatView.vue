<template>
  <div class="chat-page">
    <div class="chat-layout">
      <!-- Левая панель: список диалогов -->
      <div class="chat-sidebar">
        <div class="chat-sidebar-header">
          <h2>Чат с ресторанами</h2>
          <div class="chat-sidebar-tabs">
            <button :class="{ active: statusFilter === 'open' }" @click="statusFilter = 'open'; loadConversations()">Открытые</button>
            <button :class="{ active: statusFilter === 'closed' }" @click="statusFilter = 'closed'; loadConversations()">Закрытые</button>
          </div>
        </div>
        <div class="chat-conv-list">
          <div v-if="loadingConvs" class="chat-empty">Загрузка...</div>
          <div v-else-if="!conversations.length" class="chat-empty">Нет диалогов</div>
          <div
            v-for="c in conversations" :key="c.id"
            class="chat-conv-item"
            :class="{ selected: selectedId === c.id, unread: c.unread_count > 0 }"
            @click="selectConversation(c)"
          >
            <div class="chat-conv-top">
              <span class="chat-conv-rest">🏪 {{ c.restaurant_number }}</span>
              <span v-if="c.restaurant_name" class="chat-conv-name">{{ c.restaurant_name }}</span>
              <span v-if="c.unread_count > 0" class="chat-unread-badge">{{ c.unread_count }}</span>
            </div>
            <div class="chat-conv-preview">{{ truncate(c.last_message, 50) }}</div>
            <div class="chat-conv-time">{{ fmtTime(c.last_message_at) }}</div>
          </div>
        </div>
      </div>

      <!-- Правая панель: сообщения -->
      <div class="chat-main">
        <template v-if="selectedConv">
          <div class="chat-main-header">
            <div>
              <strong>🏪 Ресторан {{ selectedConv.restaurant_number }}</strong>
              <span v-if="selectedConv.restaurant_name" class="chat-header-name">· {{ selectedConv.restaurant_name }}</span>
              <span class="chat-status-badge" :class="selectedConv.status">{{ selectedConv.status === 'open' ? 'Открыт' : 'Закрыт' }}</span>
            </div>
            <div class="chat-header-actions">
              <button v-if="selectedConv.status === 'open'" class="chat-btn outline" @click="closeConv">Закрыть</button>
              <button v-else class="chat-btn outline" @click="reopenConv">Открыть</button>
            </div>
          </div>
          <div class="chat-messages" ref="messagesEl">
            <div v-for="m in messages" :key="m.id" class="chat-msg" :class="m.direction">
              <div class="chat-msg-bubble">
                <div class="chat-msg-sender">{{ m.sender_name }}</div>
                <div v-if="m.message_text" class="chat-msg-text">{{ m.message_text }}</div>
                <div v-if="m.photo_file_id" class="chat-msg-photo">
                  <img v-if="photoUrls[m.photo_file_id]" :src="photoUrls[m.photo_file_id]" @click="previewPhoto = photoUrls[m.photo_file_id]" />
                  <span v-else class="chat-photo-loading">📷 Загрузка...</span>
                </div>
                <div class="chat-msg-time">{{ fmtTime(m.created_at) }}</div>
              </div>
            </div>
          </div>
          <div v-if="selectedConv.status === 'open'" class="chat-input-area">
            <input v-model="inputText" class="chat-input" placeholder="Написать ответ..." @keydown.enter="sendMessage" />
            <label class="chat-photo-btn" title="Отправить фото">
              📷
              <input type="file" accept="image/*" style="display:none" @change="sendPhoto" />
            </label>
            <button class="chat-send-btn" :disabled="!inputText.trim() && !uploading" @click="sendMessage">{{ uploading ? '...' : '➤' }}</button>
          </div>
        </template>
        <div v-else class="chat-empty-main">
          <div class="chat-empty-icon">💬</div>
          <div>Выберите диалог слева</div>
        </div>
      </div>
    </div>

    <!-- Просмотр фото -->
    <Teleport to="body">
      <div v-if="previewPhoto" class="chat-photo-overlay" @click="previewPhoto = null">
        <img :src="previewPhoto" class="chat-photo-full" @click.stop />
        <button class="chat-photo-close" @click="previewPhoto = null">✕</button>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, nextTick, onMounted, onUnmounted, watch } from 'vue'
import { db } from '@/lib/apiClient.js'
import { useToastStore } from '@/stores/toastStore.js'

const toastStore = useToastStore()
const statusFilter = ref('open')
const conversations = ref([])
const loadingConvs = ref(false)
const selectedId = ref(null)
const selectedConv = ref(null)
const messages = ref([])
const inputText = ref('')
const messagesEl = ref(null)
const photoUrls = ref({})
const previewPhoto = ref(null)
const uploading = ref(false)
let pollTimer = null

async function loadConversations() {
  loadingConvs.value = true
  try {
    const { data } = await db.rpc('chat_get_conversations', { status: statusFilter.value })
    conversations.value = data || []
    // Обновляем selectedConv если выбран
    if (selectedId.value) {
      selectedConv.value = conversations.value.find(c => c.id === selectedId.value) || selectedConv.value
    }
  } catch { conversations.value = [] }
  finally { loadingConvs.value = false }
}

async function selectConversation(c) {
  selectedId.value = c.id
  selectedConv.value = c
  await loadMessages()
}

async function loadMessages() {
  if (!selectedId.value) return
  try {
    const { data } = await db.rpc('chat_get_messages', { conversation_id: selectedId.value })
    messages.value = data || []
    // Загружаем фото
    for (const m of messages.value) {
      if (m.photo_file_id && !photoUrls.value[m.photo_file_id]) {
        loadPhoto(m.photo_file_id)
      }
    }
    // Обнуляем badge
    const conv = conversations.value.find(c => c.id === selectedId.value)
    if (conv) conv.unread_count = 0
    await nextTick()
    scrollToBottom()
  } catch {}
}

async function loadPhoto(fileId) {
  try {
    const { data } = await db.rpc('chat_get_photo', { file_id: fileId })
    if (data?.url) photoUrls.value[fileId] = data.url
  } catch {}
}

async function sendMessage() {
  const text = inputText.value.trim()
  if (!text || !selectedId.value) return
  inputText.value = ''
  try {
    await db.rpc('chat_send_message', { conversation_id: selectedId.value, message_text: text })
    await loadMessages()
    await loadConversations()
  } catch (e) { toastStore.show('Ошибка: ' + (e.message || e), 'error') }
}

async function sendPhoto(e) {
  const file = e.target.files?.[0]
  if (!file || !selectedId.value) return
  e.target.value = '' // reset input
  uploading.value = true
  try {
    const formData = new FormData()
    formData.append('photo', file)
    formData.append('conversation_id', selectedId.value)
    const token = localStorage.getItem('bk_session_token') || ''
    const resp = await fetch('/api/rpc/chat_send_photo', {
      method: 'POST',
      headers: { 'X-Session-Token': token },
      body: formData,
    })
    const data = await resp.json()
    if (data.error) throw new Error(data.error)
    await loadMessages()
    await loadConversations()
  } catch (err) { toastStore.show('Ошибка: ' + (err.message || err), 'error') }
  finally { uploading.value = false }
}

async function closeConv() {
  if (!selectedId.value) return
  try {
    await db.rpc('chat_close_conversation', { conversation_id: selectedId.value })
    toastStore.show('Диалог закрыт')
    selectedId.value = null
    selectedConv.value = null
    await loadConversations()
  } catch (e) { toastStore.show('Ошибка', 'error') }
}

async function reopenConv() {
  if (!selectedId.value) return
  try {
    await db.rpc('chat_reopen_conversation', { conversation_id: selectedId.value })
    toastStore.show('Диалог открыт')
    await loadConversations()
    selectedConv.value = conversations.value.find(c => c.id === selectedId.value) || null
  } catch (e) { toastStore.show('Ошибка', 'error') }
}

function scrollToBottom() {
  if (messagesEl.value) messagesEl.value.scrollTop = messagesEl.value.scrollHeight
}

function truncate(s, len) { return s && s.length > len ? s.slice(0, len) + '…' : (s || '') }

function fmtTime(d) {
  if (!d) return ''
  const dt = new Date(d)
  const now = new Date()
  const isToday = dt.toDateString() === now.toDateString()
  if (isToday) return dt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
  return dt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' }) + ' ' + dt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
}

// Polling
function startPoll() {
  pollTimer = setInterval(async () => {
    if (document.visibilityState !== 'visible') return
    await loadConversations()
    if (selectedId.value) await loadMessages()
  }, 5000)
}

onMounted(() => {
  loadConversations()
  startPoll()
})

onUnmounted(() => { if (pollTimer) clearInterval(pollTimer) })
</script>

<style scoped>
.chat-page { height: calc(100vh - 60px); display: flex; flex-direction: column; }
.chat-layout { display: flex; flex: 1; min-height: 0; border: 1px solid var(--border-light); border-radius: 8px; overflow: hidden; }

/* Sidebar */
.chat-sidebar { width: 320px; min-width: 280px; border-right: 1px solid var(--border-light); display: flex; flex-direction: column; background: #fafaf8; }
.chat-sidebar-header { padding: 12px 16px; border-bottom: 1px solid var(--border-light); }
.chat-sidebar-header h2 { font-size: 16px; margin: 0 0 8px; }
.chat-sidebar-tabs { display: flex; gap: 0; }
.chat-sidebar-tabs button { flex: 1; padding: 6px; font-size: 12px; font-weight: 600; border: 1px solid var(--border); background: #fff; cursor: pointer; color: var(--text-muted); }
.chat-sidebar-tabs button:first-child { border-radius: 6px 0 0 6px; }
.chat-sidebar-tabs button:last-child { border-radius: 0 6px 6px 0; border-left: 0; }
.chat-sidebar-tabs button.active { background: var(--bk-brown); color: #fff; border-color: var(--bk-brown); }
.chat-conv-list { flex: 1; overflow-y: auto; }
.chat-conv-item { padding: 10px 16px; border-bottom: 1px solid var(--border-light); cursor: pointer; }
.chat-conv-item:hover { background: #f0ede8; }
.chat-conv-item.selected { background: #e8e2d8; }
.chat-conv-item.unread { border-left: 3px solid var(--bk-brown); }
.chat-conv-top { display: flex; align-items: center; gap: 6px; }
.chat-conv-rest { font-weight: 700; font-size: 13px; }
.chat-conv-name { font-size: 12px; color: var(--text-muted); }
.chat-unread-badge { background: var(--bk-brown); color: #fff; font-size: 11px; font-weight: 700; padding: 1px 6px; border-radius: 10px; margin-left: auto; }
.chat-conv-preview { font-size: 12px; color: var(--text-muted); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.chat-conv-time { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

/* Main */
.chat-main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.chat-main-header { padding: 12px 16px; border-bottom: 1px solid var(--border-light); display: flex; align-items: center; justify-content: space-between; background: #fafaf8; }
.chat-header-name { font-size: 13px; color: var(--text-muted); }
.chat-status-badge { font-size: 11px; padding: 2px 8px; border-radius: 10px; margin-left: 8px; }
.chat-status-badge.open { background: #E8F5E9; color: #2E7D32; }
.chat-status-badge.closed { background: #ECEFF1; color: #546E7A; }
.chat-header-actions { display: flex; gap: 6px; }
.chat-btn { padding: 5px 12px; font-size: 12px; border-radius: 6px; cursor: pointer; font-weight: 600; }
.chat-btn.outline { background: none; border: 1px solid var(--border); color: var(--text-muted); }
.chat-btn.outline:hover { background: var(--bk-cream); }

/* Messages */
.chat-messages { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 8px; }
.chat-msg { display: flex; }
.chat-msg.from_restaurant { justify-content: flex-start; }
.chat-msg.from_purchasing { justify-content: flex-end; }
.chat-msg-bubble { max-width: 70%; padding: 8px 12px; border-radius: 12px; }
.from_restaurant .chat-msg-bubble { background: #f0ede8; border-bottom-left-radius: 4px; }
.from_purchasing .chat-msg-bubble { background: var(--bk-brown); color: #fff; border-bottom-right-radius: 4px; }
.chat-msg-sender { font-size: 11px; font-weight: 600; margin-bottom: 2px; }
.from_restaurant .chat-msg-sender { color: var(--bk-brown); }
.from_purchasing .chat-msg-sender { color: rgba(255,255,255,0.8); }
.chat-msg-text { font-size: 13px; white-space: pre-wrap; word-break: break-word; }
.chat-msg-photo img { max-width: 300px; max-height: 300px; border-radius: 8px; cursor: pointer; margin-top: 4px; }
.chat-photo-loading { font-size: 12px; color: var(--text-muted); }
.chat-msg-time { font-size: 10px; margin-top: 4px; text-align: right; }
.from_restaurant .chat-msg-time { color: var(--text-muted); }
.from_purchasing .chat-msg-time { color: rgba(255,255,255,0.6); }

/* Input */
.chat-input-area { padding: 12px 16px; border-top: 1px solid var(--border-light); display: flex; gap: 8px; background: #fafaf8; }
.chat-input { flex: 1; padding: 8px 12px; border: 1px solid var(--border); border-radius: 20px; font-size: 13px; font-family: inherit; outline: none; }
.chat-input:focus { border-color: var(--bk-brown); }
.chat-send-btn { width: 36px; height: 36px; border: none; background: var(--bk-brown); color: #fff; border-radius: 50%; cursor: pointer; font-size: 16px; }
.chat-send-btn:disabled { opacity: 0.4; cursor: default; }
.chat-photo-btn { width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; cursor: pointer; border-radius: 50%; font-size: 18px; flex-shrink: 0; }
.chat-photo-btn:hover { background: var(--bk-cream); }
.chat-send-btn:hover:not(:disabled) { background: #6b5032; }

/* Empty states */
.chat-empty { padding: 40px 16px; text-align: center; color: var(--text-muted); font-size: 13px; }
.chat-empty-main { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--text-muted); }
.chat-empty-icon { font-size: 48px; margin-bottom: 12px; }

/* Просмотр фото */
.chat-photo-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.85); z-index: 9999; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.chat-photo-full { max-width: 90vw; max-height: 90vh; border-radius: 8px; cursor: default; }
.chat-photo-close { position: fixed; top: 16px; right: 16px; background: rgba(255,255,255,0.2); border: none; color: #fff; font-size: 24px; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; }
</style>
