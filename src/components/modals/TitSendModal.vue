<template>
  <div class="tsm-overlay" @click.self="emit('close')">
    <div class="tsm-modal">
      <header>
        <h2>Превью и отправка охране</h2>
        <button @click="emit('close')">✕</button>
      </header>

      <div class="tsm-body" v-if="loaded">
        <h3>Вот что уйдёт охране (в xlsx)</h3>
        <div class="tsm-table-wrap">
          <table class="tsm-table">
            <thead>
              <tr>
                <th>plate_number</th><th>sms_number</th>
                <th>start_time</th><th>end_time</th>
                <th>status</th><th>allow_company</th><th>warehause</th><th>ramp</th>
                <th>Поставщик</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(r, i) in rows" :key="i">
                <td><code>{{ r.plate_number }}</code></td>
                <td><code>{{ r.sms_number }}</code></td>
                <td>{{ formatExcelDate(r.start_time) }}</td>
                <td>{{ formatExcelDate(r.end_time) }}</td>
                <td>{{ r.status }}</td>
                <td>{{ r.allow_company }}</td>
                <td>{{ r.warehause }}</td>
                <td></td>
                <td>{{ r.supplier }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <h3>Текст письма</h3>
        <div class="tsm-mail-edit">
          <label class="tsm-mail-row">
            <span>Тема</span>
            <input type="text" v-model="mailSubject" maxlength="200" />
          </label>
          <label class="tsm-mail-row">
            <span>Текст</span>
            <textarea v-model="mailBody" rows="8"></textarea>
          </label>
          <div class="tsm-mail-actions">
            <button class="tsm-mini" @click="resetMail">↺ Вернуть стандартный шаблон</button>
            <span class="tsm-mail-hint">Переносы строк сохранятся как в письме. Поставщик/дата уже будут в xlsx во вложении.</span>
          </div>
        </div>

        <h3>Кому отправить</h3>
        <div class="tsm-recipients">
          <label v-for="(addr, i) in securityList" :key="i" class="tsm-recipient">
            <input type="checkbox" v-model="checked" :value="addr" />
            <code>{{ addr }}</code>
          </label>
          <div v-if="!securityList.length" class="tsm-empty-recipients">Список адресов охраны пуст. Заполните в настройках модуля.</div>
        </div>

        <div class="tsm-cc">
          В копии: <code>{{ senderEmail || 'ваш email не указан в профиле' }}</code> (отправитель)
        </div>
      </div>

      <div v-else-if="error" class="tsm-error">⚠ {{ error }}</div>
      <div v-else class="tsm-loading">Загружаем превью…</div>

      <footer v-if="loaded">
        <button class="ghost" @click="emit('close')">Назад</button>
        <div style="flex:1"></div>
        <button class="ghost" @click="downloadXlsx">Скачать xlsx</button>
        <button class="ghost" @click="openInMyOutlook" title="Откроется ваш почтовый клиент с готовым черновиком. Файл xlsx скачается — прикрепите его и отправьте.">✉ Открыть в моей почте</button>
        <button class="primary" @click="send" :disabled="sending || !canSend">{{ sending ? 'Отправляем…' : 'Отправить охране' }}</button>
      </footer>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { db } from '@/lib/apiClient.js';
import { useUserStore } from '@/stores/userStore.js';

const props = defineProps({ id: { type: Number, required: true } });
const emit = defineEmits(['close', 'sent']);

const userStore = useUserStore();

const rows = ref([]);
const securityList = ref([]);
const checked = ref([]);
const senderEmail = ref('');
const loaded = ref(false);
const error = ref('');
const sending = ref(false);

// Редактируемые поля письма (с дефолтным шаблоном из данных заявки).
const mailSubject = ref('');
const mailBody = ref('');
const supplierName = ref('');
const deliveryDate = ref('');
const legalEntity = ref('');

function buildDefaultSubject() {
  return 'Заявка на пропуск — ' + (supplierName.value || '—') + ' — ' + (deliveryDate.value || '');
}

function buildDefaultBody() {
  const sup = supplierName.value || '—';
  const dt  = deliveryDate.value || '—';
  const cnt = rows.value.length;
  const le  = legalEntity.value || 'Отдел закупок';
  return [
    'Добрый день!',
    '',
    'Направляем заявку на пропуск транспорта на склад. Подробности во вложении.',
    '',
    'Поставщик: ' + sup,
    'Дата подачи: ' + dt,
    'Количество машин: ' + cnt,
    '',
    'Заранее спасибо! Хорошего дня.',
    '',
    'С уважением,',
    le,
  ].join('\n');
}

function resetMail() {
  mailSubject.value = buildDefaultSubject();
  mailBody.value = buildDefaultBody();
}

const canSend = computed(() => rows.value.length > 0 && checked.value.length > 0);

function formatExcelDate(serial) {
  if (typeof serial !== 'number') return '';
  // Excel epoch: 1899-12-30
  const epoch = new Date(Date.UTC(1899, 11, 30));
  const ms = serial * 86400000;
  const date = new Date(epoch.getTime() + ms);
  const pad = (n) => String(n).padStart(2, '0');
  return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())} ${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}`;
}

async function loadAll() {
  try {
    const [prev, settings, info] = await Promise.all([
      db.rpc('tit_preview_xlsx_rows', { id: props.id }),
      db.rpc('tit_settings_get', {}),
      db.rpc('tit_get', { id: props.id }),
    ]);
    if (prev.error) throw new Error(prev.error);
    rows.value = prev.data?.rows || [];
    const s = settings.data || {};
    securityList.value = Array.isArray(s.security_recipients) ? s.security_recipients : [];
    checked.value = [...securityList.value];
    senderEmail.value = String(userStore.currentUser?.email || '');
    // Данные заявки — для подстановки в шаблон письма
    const req = info.data?.request || {};
    supplierName.value = String(req.supplier_name || '');
    deliveryDate.value = String(req.delivery_date || '');
    legalEntity.value  = String(req.legal_entity || '');
    // Дефолтные тема и тело — пользователь может править прямо в модалке
    resetMail();
    loaded.value = true;
  } catch (e) { error.value = e.message || 'Ошибка'; }
}

async function send() {
  if (!canSend.value) return;
  if (!confirm('Отправить ' + rows.value.length + ' строк(и) на ' + checked.value.length + ' адрес(а) охраны?')) return;
  sending.value = true;
  try {
    const { data, error: e } = await db.rpc('tit_send_to_security', {
      id: props.id,
      recipients: checked.value,
      subject: mailSubject.value,
      body_text: mailBody.value,
    });
    if (e || data?.error) throw new Error(e || data.error);
    alert('Письмо отправлено охране (' + (data.recipients || []).join(', ') + ')');
    emit('sent');
  } catch (e) {
    alert('Не удалось отправить: ' + (e.message || e));
  } finally {
    sending.value = false;
  }
}

async function openInMyOutlook() {
  // 1) Скачиваем xlsx (закупщик прикрепит сам — mailto: вложения не поддерживает).
  // 2) Открываем «mailto:» с готовыми темой/телом/получателями. Письмо уйдёт
  //    с личного корпоративного ящика юзера (например, *@burger-king.by) —
  //    минуя наш info@ и его NiceBayes-блок.
  try {
    await downloadXlsx();
    const tos = checked.value;
    if (!tos.length) {
      alert('Не выбраны получатели. Отметьте адреса охраны в списке выше.');
      return;
    }
    const mailto = 'mailto:' + encodeURIComponent(tos.join(','))
      + '?subject=' + encodeURIComponent(mailSubject.value)
      + '&body=' + encodeURIComponent(mailBody.value);
    // window.location — открывает дефолтный почтовый клиент (Outlook на Windows).
    window.location.href = mailto;
    setTimeout(() => {
      alert('Файл xlsx скачан. В открывшемся письме прикрепите его и нажмите «Отправить».');
    }, 400);
  } catch (e) {
    alert('Не удалось открыть в почте: ' + (e.message || e));
  }
}

async function downloadXlsx() {
  try {
    const { data } = await db.rpc('tit_download_xlsx', { id: props.id });
    if (!data?.content_b64) return;
    const bin = atob(data.content_b64);
    const arr = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
    const blob = new Blob([arr], { type: data.mime });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = data.filename || 'tit.xlsx'; a.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  } catch (_) {}
}

onMounted(loadAll);
</script>

<style scoped>
.tsm-overlay { position: fixed; inset: 0; background: rgba(80,35,20,.5); z-index: 1100; display: flex; align-items: flex-start; justify-content: center; padding: 24px; overflow-y: auto; }
.tsm-modal { background: #FFF8ED; border-radius: 14px; width: 100%; max-width: 980px; box-shadow: 0 12px 40px rgba(0,0,0,.25); display: flex; flex-direction: column; max-height: calc(100vh - 48px); }
.tsm-modal header { padding: 16px 20px; border-bottom: 1px solid #EDE2D2; display: flex; justify-content: space-between; background: #fff; border-radius: 14px 14px 0 0; }
.tsm-modal header h2 { margin: 0; font-size: 16px; color: var(--bk-brown, #502314); }
.tsm-modal header button { background: transparent; border: none; font-size: 18px; cursor: pointer; color: #8C7B6E; }

.tsm-body { padding: 16px 20px; overflow-y: auto; flex: 1; }
.tsm-body h3 { margin: 18px 0 8px; font-size: 13px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--bk-brown, #502314); }
.tsm-body h3:first-child { margin-top: 0; }

.tsm-test-banner { background: #FFF8E1; border: 1.5px solid #F5C158; color: #6B4F00; border-radius: 10px; padding: 12px 16px; font-size: 13px; margin-bottom: 14px; }
.tsm-test-banner code { background: #FFE5A0; padding: 2px 6px; border-radius: 4px; font-family: ui-monospace, Menlo, monospace; }

.tsm-table-wrap { background: #fff; border-radius: 10px; overflow-x: auto; border: 1px solid #EDE2D2; }
.tsm-table { width: 100%; border-collapse: separate; border-spacing: 0; font-family: ui-monospace, Menlo, monospace; font-size: 12px; }
.tsm-table thead th { background: var(--bk-brown, #502314); color: #fff; padding: 8px 12px; text-align: left; font-weight: 600; font-size: 11px; white-space: nowrap; }
.tsm-table tbody td { padding: 8px 12px; border-top: 1px solid #F0E8DC; white-space: nowrap; color: var(--bk-brown, #502314); }
.tsm-table code { font-family: inherit; }

.tsm-recipients { display: flex; flex-direction: column; gap: 6px; }
.tsm-recipient { display: flex; align-items: center; gap: 10px; padding: 8px 12px; background: #fff; border: 1px solid #EDE2D2; border-radius: 8px; cursor: pointer; font-size: 13px; }
.tsm-recipient code { font-family: ui-monospace, Menlo, monospace; color: var(--bk-brown, #502314); }
.tsm-recipient-locked { background: #FFF8E1; cursor: default; }
.tsm-tick { color: #2E7D32; font-weight: 700; }
.tsm-recipient-note { color: #8C7B6E; font-size: 11px; margin-left: auto; }
.tsm-empty-recipients { color: #B91C1C; font-size: 13px; padding: 8px; }

.tsm-cc { color: #8C7B6E; font-size: 12px; margin-top: 12px; }
.tsm-cc code { font-family: ui-monospace, Menlo, monospace; }

.tsm-mail-edit { display: flex; flex-direction: column; gap: 10px; }
.tsm-mail-row { display: flex; flex-direction: column; font-size: 12px; color: #6B4F00; }
.tsm-mail-row > span { font-weight: 600; margin-bottom: 4px; }
.tsm-mail-row input, .tsm-mail-row textarea { width: 100%; border-radius: 8px; border: 1.5px solid #E5DDD3; padding: 8px 10px; font-family: inherit; font-size: 14px; background: #fff; color: var(--bk-brown, #502314); box-sizing: border-box; }
.tsm-mail-row input { height: 38px; }
.tsm-mail-row textarea { min-height: 160px; line-height: 1.5; resize: vertical; }
.tsm-mail-row input:focus, .tsm-mail-row textarea:focus { outline: 2px solid var(--bk-orange, #F4A261); outline-offset: -1px; }
.tsm-mail-actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.tsm-mini { background: transparent; border: 1px solid #E5DDD3; color: var(--bk-brown, #502314); padding: 6px 12px; border-radius: 8px; font-size: 12px; cursor: pointer; font-family: inherit; }
.tsm-mini:hover { background: #FFF8ED; }
.tsm-mail-hint { color: #8C7B6E; font-size: 12px; }

.tsm-loading, .tsm-error { padding: 40px 20px; text-align: center; color: #8C7B6E; }
.tsm-error { color: #B91C1C; }

.tsm-modal footer { padding: 14px 20px; border-top: 1px solid #EDE2D2; display: flex; gap: 8px; align-items: center; background: #fff; border-radius: 0 0 14px 14px; }
.tsm-modal footer button { padding: 10px 18px; border-radius: 10px; border: 1.5px solid transparent; font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit; }
.tsm-modal footer button:disabled { opacity: 0.4; cursor: not-allowed; }
.tsm-modal footer .primary { background: var(--bk-red, #E76F51); color: #fff; border-color: var(--bk-red, #E76F51); }
.tsm-modal footer .ghost { background: transparent; color: var(--bk-brown, #502314); border-color: #E5DDD3; }
</style>
