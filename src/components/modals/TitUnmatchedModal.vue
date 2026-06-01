<template>
  <div class="tum-overlay" @click.self="emit('close')">
    <div class="tum-modal">
      <header>
        <h2>Письма без привязки</h2>
        <button @click="emit('close')">✕</button>
      </header>
      <div class="tum-body">
        <p class="tum-hint">Эти ответы поставщиков пришли по email, но система не смогла привязать их к заявкам автоматически. Привяжите вручную — данные подтянутся в нужную заявку.</p>
        <div v-if="loading" class="tum-loading">Загружаем…</div>
        <div v-else-if="!rows.length" class="tum-empty">Непривязанных писем нет ✓</div>
        <ul v-else class="tum-list">
          <li v-for="e in rows" :key="e.id">
            <div class="tum-email-head">
              <b>{{ e.from_name || e.from_email }}</b>
              <span>{{ formatDateTime(e.received_at) }}</span>
            </div>
            <div class="tum-subj">{{ e.subject }}</div>

            <div v-if="e.parsed_plate || e.parsed_phone" class="tum-parsed">
              <div class="tum-parsed-row">
                <span class="tum-parsed-label">Номер машины</span>
                <code class="tum-parsed-val">{{ e.parsed_plate || '—' }}</code>
              </div>
              <div class="tum-parsed-row">
                <span class="tum-parsed-label">Телефон</span>
                <code class="tum-parsed-val">{{ e.parsed_phone || '—' }}</code>
              </div>
              <div class="tum-parsed-src">источник: {{ sourceLabel(e.parsed_via) }}</div>
            </div>
            <div v-else class="tum-parsed tum-parsed-empty">
              Парсер пока ничего не распознал — но в письме есть вложение, проверьте сами.
            </div>

            <div v-if="e.body_excerpt" class="tum-body-preview">
              <details>
                <summary>Показать текст письма</summary>
                <pre>{{ e.body_excerpt }}</pre>
              </details>
            </div>

            <div v-if="e.attachments && e.attachments.length" class="tum-attach-list">
              <span class="tum-attach-label">Вложения:</span>
              <button v-for="(f, i) in e.attachments" :key="i" class="tum-attach-btn" @click="downloadAttachment(e.id, i, f.name)">
                📎 {{ f.name }} <small>({{ formatSize(f.size) }})</small>
              </button>
            </div>

            <div class="tum-link-row">
              <select v-model="linkChoice[e.id]">
                <option value="">— выберите заявку для привязки —</option>
                <option v-for="r in candidateRequests" :key="r.id" :value="r.id">
                  #{{ r.id }} · {{ r.supplier_name }} · {{ formatDate(r.delivery_date) }}
                </option>
              </select>
              <button class="primary" :disabled="!linkChoice[e.id]" @click="linkEmail(e.id)">Привязать</button>
              <button class="ghost" @click="ignoreEmail(e.id)" title="Скрыть это письмо — не показывать в баннере «непривязанные». Например, если поставщик сам отправил заявку охране.">Пропустить</button>
            </div>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { db } from '@/lib/apiClient.js';

const emit = defineEmits(['close']);

const rows = ref([]);
const candidateRequests = ref([]);
const loading = ref(false);
const linkChoice = reactive({});

const formatDate = (d) => d ? d.split('-').reverse().join('.') : '';
const formatDateTime = (dt) => {
  if (!dt) return '';
  const ts = new Date(dt.replace(' ', 'T')).getTime();
  if (!ts) return dt;
  const date = new Date(ts);
  const pad = (n) => String(n).padStart(2, '0');
  return `${pad(date.getDate())}.${pad(date.getMonth() + 1)} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
};

async function reload() {
  loading.value = true;
  try {
    const [unm, list] = await Promise.all([
      db.rpc('tit_email_log_unmatched', {}),
      db.rpc('tit_list', { status: '' }),
    ]);
    rows.value = unm.data?.rows || [];
    candidateRequests.value = (list.data?.rows || []).filter(r => r.status !== 'SENT' && r.status !== 'CANCELLED');
  } finally { loading.value = false; }
}

async function linkEmail(emailId) {
  const requestId = linkChoice[emailId];
  if (!requestId) return;
  try {
    await db.rpc('tit_email_link', { email_id: emailId, request_id: requestId });
    await reload();
  } catch (e) { alert('Ошибка: ' + (e.message || e)); }
}

async function ignoreEmail(emailId) {
  try {
    await db.rpc('tit_email_ignore', { email_id: emailId });
    await reload();
  } catch (e) { alert('Не удалось скрыть: ' + (e.message || e)); }
}

function sourceLabel(via) {
  return ({
    EMAIL_TEXT: 'из текста письма',
    EMAIL_OCR:  'из скана накладной',
    BOTH:       'и из текста, и из накладной',
    NONE:       '—',
  })[via] || (via || '—');
}

function formatSize(b) {
  if (!b) return '';
  if (b < 1024) return b + ' Б';
  if (b < 1024 * 1024) return Math.round(b / 1024) + ' КБ';
  return (b / 1024 / 1024).toFixed(1) + ' МБ';
}

async function downloadAttachment(emailId, index, fname) {
  try {
    const { data } = await db.rpc('tit_email_attachment', { email_id: emailId, index });
    if (!data?.content_b64) return;
    const bin = atob(data.content_b64);
    const arr = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
    const blob = new Blob([arr], { type: data.mime });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = data.filename || fname; a.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  } catch (e) { alert('Не удалось скачать: ' + (e.message || e)); }
}

onMounted(reload);
</script>

<style scoped>
.tum-overlay { position: fixed; inset: 0; background: rgba(80,35,20,.5); z-index: 1100; display: flex; align-items: center; justify-content: center; padding: 16px; }
.tum-modal { background: #FFF8ED; border-radius: 14px; width: 100%; max-width: 720px; max-height: calc(100vh - 32px); display: flex; flex-direction: column; box-shadow: 0 12px 40px rgba(0,0,0,.25); }
.tum-modal header { padding: 16px 20px; border-bottom: 1px solid #EDE2D2; display: flex; justify-content: space-between; background: #fff; border-radius: 14px 14px 0 0; }
.tum-modal header h2 { margin: 0; font-size: 16px; color: var(--bk-brown, #502314); }
.tum-modal header button { background: transparent; border: none; font-size: 18px; cursor: pointer; color: #8C7B6E; }

.tum-body { padding: 16px 20px; overflow-y: auto; flex: 1; }
.tum-hint { color: #6B4F00; font-size: 13px; margin: 0 0 12px; }
.tum-loading, .tum-empty { text-align: center; padding: 40px 20px; color: #8C7B6E; }

.tum-list { list-style: none; padding: 0; margin: 0; }
.tum-list li { background: #fff; border: 1px solid #EDE2D2; border-radius: 10px; padding: 12px 14px; margin-bottom: 10px; }
.tum-email-head { display: flex; justify-content: space-between; font-size: 13px; color: var(--bk-brown, #502314); }
.tum-email-head span { color: #8C7B6E; font-size: 12px; }
.tum-subj { font-size: 13px; color: var(--bk-brown, #502314); margin: 4px 0; font-weight: 600; }
.tum-parsed { background: #E8F5E9; border: 1.5px solid #66BB6A; border-radius: 8px; padding: 10px 14px; margin: 8px 0; }
.tum-parsed-row { display: flex; align-items: baseline; gap: 12px; padding: 2px 0; }
.tum-parsed-label { font-size: 12px; color: #2E7D32; font-weight: 600; min-width: 110px; }
.tum-parsed-val { font-size: 16px; font-weight: 700; color: #1B5E20; font-family: ui-monospace, Menlo, monospace; }
.tum-parsed-src { font-size: 11px; color: #4E7D52; font-style: italic; margin-top: 4px; }
.tum-parsed-empty { background: #FFF8E1; border-color: #F5C158; color: #6B4F00; font-style: italic; font-size: 13px; padding: 8px 12px; }

.tum-body-preview { margin: 8px 0; }
.tum-body-preview summary { cursor: pointer; font-size: 12px; color: var(--bk-red, #E76F51); font-weight: 600; }
.tum-body-preview pre { background: #F9F4EC; border: 1px solid #EDE2D2; border-radius: 6px; padding: 10px 12px; margin: 6px 0 0; font-size: 12px; color: #4a3a2a; max-height: 240px; overflow-y: auto; white-space: pre-wrap; font-family: ui-monospace, Menlo, monospace; }

.tum-attach-list { display: flex; flex-wrap: wrap; gap: 6px; margin: 8px 0; align-items: center; }
.tum-attach-label { font-size: 12px; color: #6B4F00; font-weight: 600; }
.tum-attach-btn { background: #FFF8ED; border: 1px solid #C9BBA8; color: var(--bk-brown, #502314); padding: 4px 10px; border-radius: 6px; font-size: 12px; cursor: pointer; font-family: inherit; }
.tum-attach-btn:hover { background: #FFEBC8; border-color: var(--bk-orange, #F4A261); }
.tum-attach-btn small { color: #8C7B6E; margin-left: 4px; }

.tum-link-row { display: flex; gap: 8px; margin-top: 6px; }
.tum-link-row select { flex: 1; height: 36px; border-radius: 8px; border: 1.5px solid #E5DDD3; padding: 0 8px; font-size: 13px; background: #fff; color: var(--bk-brown, #502314); }
.tum-link-row button { padding: 8px 14px; border-radius: 8px; border: none; font-size: 12px; font-weight: 600; cursor: pointer; font-family: inherit; }
.tum-link-row button:disabled { opacity: 0.4; cursor: not-allowed; }
.tum-link-row .primary { background: var(--bk-red, #E76F51); color: #fff; }
.tum-link-row .ghost { background: transparent; color: #8C7B6E; border: 1px solid #E5DDD3; }
.tum-link-row .ghost:hover { background: #FFF8ED; color: var(--bk-brown, #502314); }
</style>
