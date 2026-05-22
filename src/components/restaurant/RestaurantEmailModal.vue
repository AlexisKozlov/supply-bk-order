<template>
  <div v-if="show" class="rem-overlay" :class="{ 'rem-overlay--mandatory': mandatory }" @click.self="onOverlayClick">
    <div class="rem-card">
      <button v-if="!mandatory" class="rem-close" @click="close" :disabled="loading" aria-label="Закрыть">×</button>

      <div class="rem-header">
        <div class="rem-icon">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#E76F51" stroke-width="2" stroke-linecap="round">
            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
            <polyline points="22,6 12,13 2,6"/>
          </svg>
        </div>
        <div>
          <h2>{{ mandatory ? 'Привяжите рабочий email' : 'Укажите email' }}</h2>
          <p v-if="mandatory">
            Чтобы пользоваться кабинетом, привяжите рабочую почту —
            <b>@burger-king.by</b> или <b>@dodopizza.by</b>.
            На неё будут приходить уведомления и ссылка для сброса пароля.
          </p>
          <p v-else>Нужен для восстановления пароля кабинета.<br>Заполнить можно позже.</p>
        </div>
      </div>

      <!-- Состояние: ввод email -->
      <div v-if="!sent">
        <div class="rem-field">
          <label>{{ mandatory ? 'Рабочий email' : 'Ваш email' }}</label>
          <input
            v-model="email"
            type="email"
            inputmode="email"
            autocomplete="email"
            :placeholder="mandatory ? 'name@burger-king.by' : 'your-name@example.com'"
            :disabled="loading"
            @keydown.enter="submit"
          />
        </div>

        <div v-if="error" class="rem-error">{{ error }}</div>

        <div class="rem-actions">
          <button v-if="!mandatory" class="rem-btn-ghost" @click="close" :disabled="loading">Позже</button>
          <button class="rem-btn-primary" :disabled="loading || !canSubmit" @click="submit">
            <span v-if="loading" class="rem-spinner"></span>
            <template v-else>Сохранить</template>
          </button>
        </div>
      </div>

      <!-- Состояние: письмо отправлено -->
      <div v-else>
        <div class="rem-success">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
          <div>
            <strong>Письмо отправлено</strong>
            <p>На <b>{{ sentTo }}</b> отправлена ссылка для подтверждения. Перейдите по ней в течение 24 часов. Не пришло — проверьте папку «Спам».</p>
          </div>
        </div>
        <div class="rem-actions">
          <button class="rem-btn-ghost" @click="reset" :disabled="loading">Изменить email</button>
          <button class="rem-btn-primary" @click="finish">Готово</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  // mandatory — без крестика, без «Позже», нельзя закрыть кликом снаружи.
  // Используется в кабинете, когда email отсутствует или не корпоративный.
  mandatory: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'saved']);

const show = ref(props.modelValue);
watch(() => props.modelValue, (v) => { show.value = v; });

const email = ref('');
const loading = ref(false);
const error = ref('');
const sent = ref(false);
const sentTo = ref('');

import { useRestaurantOrderStore } from '@/stores/restaurantOrderStore.js';
import { computed } from 'vue';
const roStore = useRestaurantOrderStore();

const CORP_DOMAINS = ['@burger-king.by', '@dodopizza.by'];

function isCorporateEmail(value) {
  if (!value) return false;
  const v = value.trim().toLowerCase();
  return CORP_DOMAINS.some(d => v.endsWith(d));
}

const canSubmit = computed(() => {
  const v = email.value.trim();
  if (!v) return false;
  // В обязательном режиме требуем корпоративный домен сразу — иначе кнопка disabled.
  if (props.mandatory) return isCorporateEmail(v);
  return true;
});

function close() {
  if (loading.value) return;
  if (props.mandatory) return; // нельзя закрыть, пока не сохранили
  show.value = false;
  emit('update:modelValue', false);
}

function onOverlayClick() {
  // В обязательном режиме клик мимо ничего не делает.
  if (props.mandatory) return;
  close();
}

function finish() {
  // После успешного «Готово» — закрываем, даже в mandatory: email сохранён, цель достигнута.
  show.value = false;
  emit('update:modelValue', false);
}

function reset() {
  sent.value = false;
  sentTo.value = '';
  error.value = '';
}

async function submit() {
  error.value = '';
  const value = email.value.trim();
  if (!value) { error.value = 'Введите email'; return; }
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value)) {
    error.value = 'Похоже, email указан с ошибкой';
    return;
  }
  if (!isCorporateEmail(value)) {
    error.value = 'Принимаем только рабочую почту: @burger-king.by или @dodopizza.by';
    return;
  }

  loading.value = true;
  try {
    const data = await roStore.setAccountEmail(value);
    sentTo.value = data?.sent_to || value;
    sent.value = true;
    emit('saved', { email: sentTo.value });
  } catch (e) {
    error.value = e?.message || 'Не удалось сохранить email';
  } finally {
    loading.value = false;
  }
}
</script>

<style scoped>
.rem-overlay {
  position: fixed;
  inset: 0;
  background: rgba(80, 35, 20, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
  padding: 16px;
  animation: rem-fade-in 0.2s ease;
}
.rem-overlay--mandatory {
  /* Жёстче перекрываем фон — чтобы было видно, что без email кабинет недоступен. */
  background: rgba(80, 35, 20, 0.78);
  backdrop-filter: blur(6px);
}

@keyframes rem-fade-in {
  from { opacity: 0; }
  to { opacity: 1; }
}

.rem-card {
  background: white;
  border-radius: 18px;
  padding: 28px;
  width: 100%;
  max-width: 440px;
  box-shadow: 0 20px 60px rgba(80, 35, 20, 0.35);
  position: relative;
  box-sizing: border-box;
  animation: rem-slide-up 0.3s ease;
}

@keyframes rem-slide-up {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}

.rem-close {
  position: absolute;
  top: 12px;
  right: 12px;
  width: 32px;
  height: 32px;
  border: none;
  background: transparent;
  font-size: 24px;
  color: #8b7355;
  cursor: pointer;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  line-height: 1;
}
.rem-close:hover:not(:disabled) { background: #f5f0e8; color: #502314; }
.rem-close:disabled { opacity: 0.4; cursor: not-allowed; }

.rem-header {
  display: flex;
  align-items: flex-start;
  gap: 14px;
  margin-bottom: 20px;
  padding-right: 28px;
}

.rem-icon {
  width: 48px;
  height: 48px;
  border-radius: 12px;
  background: #fff5f2;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.rem-header h2 {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: #502314;
}

.rem-header p {
  margin: 4px 0 0;
  color: #8b7355;
  font-size: 13px;
  line-height: 1.45;
}

.rem-field { margin-bottom: 16px; }

.rem-field label {
  display: block;
  font-size: 13px;
  font-weight: 600;
  color: #502314;
  margin-bottom: 6px;
}

.rem-field input {
  width: 100%;
  padding: 12px 14px;
  border: 2px solid #e8e0d6;
  border-radius: 10px;
  font-size: 15px;
  font-family: inherit;
  background: #faf8f5;
  box-sizing: border-box;
  transition: all 0.2s;
}

.rem-field input:focus {
  outline: none;
  border-color: #E76F51;
  background: white;
  box-shadow: 0 0 0 4px rgba(231, 111, 81, 0.08);
}

.rem-error {
  background: #fef2f2;
  color: #dc2626;
  padding: 10px 12px;
  border-radius: 8px;
  font-size: 13px;
  margin-bottom: 14px;
  border: 1px solid #fecaca;
}

.rem-success {
  display: flex;
  gap: 12px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 12px;
  padding: 14px;
  margin-bottom: 18px;
}
.rem-success svg { flex-shrink: 0; margin-top: 2px; }
.rem-success strong { display: block; color: #16a34a; font-size: 14px; margin-bottom: 4px; }
.rem-success p { margin: 0; font-size: 13px; color: #15803d; line-height: 1.45; }
.rem-success b { color: #064e3b; }

.rem-actions {
  display: flex;
  gap: 10px;
  justify-content: flex-end;
}

.rem-btn-ghost,
.rem-btn-primary {
  padding: 11px 18px;
  border-radius: 10px;
  font-size: 14px;
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
  transition: all 0.2s;
  border: none;
  min-width: 110px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.rem-btn-ghost {
  background: transparent;
  color: #8b7355;
  border: 1.5px solid #e8e0d6;
}
.rem-btn-ghost:hover:not(:disabled) { background: #f5f0e8; color: #502314; }
.rem-btn-ghost:disabled { opacity: 0.5; cursor: not-allowed; }

.rem-btn-primary {
  background: linear-gradient(135deg, #E76F51 0%, #e83a1a 100%);
  color: white;
  box-shadow: 0 2px 8px rgba(231, 111, 81, 0.3);
}
.rem-btn-primary:hover:not(:disabled) {
  background: linear-gradient(135deg, #b81e00 0%, #E76F51 100%);
  box-shadow: 0 4px 12px rgba(231, 111, 81, 0.4);
}
.rem-btn-primary:disabled { opacity: 0.5; cursor: not-allowed; box-shadow: none; }

.rem-spinner {
  width: 18px;
  height: 18px;
  border: 2.5px solid rgba(255,255,255,0.3);
  border-top-color: white;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }
</style>
