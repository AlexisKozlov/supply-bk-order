<template>
  <Teleport to="body">
    <div class="modal" @click.self="$emit('cancel')">
      <div class="modal-box send-email-modal">
        <div class="modal-header">
          <h2><BkIcon name="mail" size="sm"/> Отправка заявки по email</h2>
          <button class="modal-close" @click="$emit('cancel')"><BkIcon name="close" size="sm"/></button>
        </div>

        <div class="send-row">
          <div class="send-label">Кому</div>
          <div class="send-value">{{ to }}</div>
        </div>
        <div class="send-row">
          <div class="send-label">Тема</div>
          <div class="send-value subj">{{ subject }}</div>
        </div>

        <div class="cc-section">
          <div class="send-label cc-label">В копию</div>
          <div class="cc-chips">
            <span v-for="(e, i) in ccList" :key="e + '-' + i" class="cc-chip" :class="{ self: isSelfEmail(e) }">
              <span class="cc-chip-text">{{ isSelfEmail(e) ? 'Вы — ' + e : e }}</span>
              <button class="cc-chip-x" @click="removeCc(i)" title="Убрать"><BkIcon name="close" size="xs"/></button>
            </span>
            <span v-if="!ccList.length" class="cc-empty">Никого в копии</span>
          </div>
          <div class="cc-add-row">
            <input
              v-model="newEmail"
              type="email"
              placeholder="Добавить email и нажать Enter"
              @keydown.enter.prevent="addCc"
            />
            <button class="btn-add" @click="addCc" :disabled="!newEmail.trim()">Добавить</button>
          </div>
          <div v-if="addError" class="cc-error">{{ addError }}</div>
        </div>

        <div class="modal-actions">
          <button class="btn secondary" @click="$emit('cancel')">Отмена</button>
          <button class="btn primary" @click="submit" :disabled="sending">
            <BurgerSpinner v-if="sending" size="xs" />
            <span>{{ sending ? 'Отправка…' : 'Отправить' }}</span>
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed } from 'vue';
import BkIcon from '@/components/ui/BkIcon.vue';

const props = defineProps({
  to: { type: String, required: true },
  subject: { type: String, default: '' },
  initialCc: { type: Array, default: () => [] },
  selfEmail: { type: String, default: '' },
  sending: { type: Boolean, default: false },
});
const emit = defineEmits(['send', 'cancel']);

const ccList = ref([...(props.initialCc || [])]);
const newEmail = ref('');
const addError = ref('');

const selfLc = computed(() => (props.selfEmail || '').toLowerCase());
function isSelfEmail(e) {
  return selfLc.value && e.toLowerCase() === selfLc.value;
}

function isValidEmail(e) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e);
}

function addCc() {
  addError.value = '';
  const raw = newEmail.value.trim().replace(/[,;]+$/, '');
  if (!raw) return;
  if (!isValidEmail(raw)) {
    addError.value = 'Некорректный email';
    return;
  }
  const lc = raw.toLowerCase();
  if (ccList.value.some(e => e.toLowerCase() === lc)) {
    addError.value = 'Этот адрес уже в списке';
    return;
  }
  if (props.to.toLowerCase().split(/[,;\s]+/).filter(Boolean).includes(lc)) {
    addError.value = 'Этот адрес уже в «Кому»';
    return;
  }
  ccList.value.push(raw);
  newEmail.value = '';
}

function removeCc(i) {
  ccList.value.splice(i, 1);
}

function submit() {
  emit('send', [...ccList.value]);
}
</script>

<style scoped>
.send-email-modal { min-width: 480px; max-width: 600px; }
.send-row {
  display: flex; gap: 12px; align-items: flex-start;
  padding: 6px 0; border-bottom: 1px solid #f0f0f0;
}
.send-label {
  flex: 0 0 70px; font-size: 12px; color: #6b7280;
  text-transform: uppercase; letter-spacing: 0.4px;
  padding-top: 2px;
}
.send-value { flex: 1; font-size: 14px; color: #1f2937; word-break: break-word; }
.send-value.subj { color: #374151; }

.cc-section { margin-top: 14px; }
.cc-label { padding: 0 0 6px; flex: none; }
.cc-chips {
  display: flex; flex-wrap: wrap; gap: 6px;
  min-height: 32px; padding: 6px 4px;
  border-bottom: 1px solid #f0f0f0;
}
.cc-chip {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 4px 3px 10px;
  background: #eef4fb; border: 1px solid #d6e4f3; border-radius: 14px;
  font-size: 13px; color: #1d4e89;
}
.cc-chip.self { background: #fff4ec; border-color: #ffd2b3; color: #b14400; }
.cc-chip-text { line-height: 1; }
.cc-chip-x {
  background: transparent; border: none; cursor: pointer;
  padding: 2px; border-radius: 50%;
  display: inline-flex; align-items: center; justify-content: center;
  color: inherit; opacity: 0.65;
}
.cc-chip-x:hover { opacity: 1; background: rgba(0,0,0,0.06); }
.cc-empty { font-size: 13px; color: #9ca3af; padding: 4px 0; }

.cc-add-row {
  display: flex; gap: 8px; margin-top: 10px;
}
.cc-add-row input {
  flex: 1; padding: 7px 10px; font-size: 13px;
  border: 1px solid #DCDFE4; border-radius: 6px;
  background: #fff; color: #172B4D; font-family: inherit;
}
.cc-add-row input:focus {
  outline: none; border-color: #E87A1E;
  box-shadow: 0 0 0 2px rgba(232,122,30,0.30);
}
.btn-add {
  padding: 7px 14px; font-size: 13px;
  border: 1px solid #d6e4f3; background: #eef4fb; color: #1d4e89;
  border-radius: 6px; cursor: pointer;
}
.btn-add:disabled { opacity: 0.5; cursor: not-allowed; }
.cc-error { font-size: 12px; color: #c0392b; margin-top: 6px; }

.modal-actions {
  display: flex; gap: 8px; justify-content: flex-end;
  margin-top: 16px;
}
</style>
