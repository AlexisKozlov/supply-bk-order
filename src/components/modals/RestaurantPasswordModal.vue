<template>
  <Teleport to="body">
    <div class="modal" @click.self="close">
      <div class="modal-box rpm">
        <h3>{{ title }}</h3>
        <p v-if="message" class="rpm-message">{{ message }}</p>

        <!-- Кому выдаём (только массовая выдача) -->
        <select v-if="bulk" v-model="mode" class="rpm-select">
          <!-- Пункт «только без пароля» выключаем, когда таких нет: иначе
               выбираешь его, жмёшь «Выдать» и получаешь отказ. -->
          <option value="missing" :disabled="missingCount === 0">
            Только тем, у кого нет пароля{{ missingCount !== null ? (missingCount ? ` — ${missingCount}` : ' — таких нет') : '' }}
          </option>
          <option value="all">Всем — старые пароли перестанут работать</option>
        </select>
        <p v-if="bulk && mode === 'all'" class="rpm-warn">
          Пароль сменится у всех {{ totalCount }} кабинетов. Тем, кто уже вошёл, придётся войти заново с новым паролем.
        </p>

        <!-- Поле пароля -->
        <div class="rpm-field">
          <input
            ref="inp"
            v-model="pass"
            :type="visible ? 'text' : 'password'"
            class="rpm-input"
            placeholder="Минимум 8 символов"
            autocomplete="new-password"
            spellcheck="false"
            @keydown.enter.prevent="submit"
            @keydown.esc.prevent="close"
          />
          <button class="rpm-icon-btn" type="button" @click="visible = !visible"
                  :title="visible ? 'Скрыть' : 'Показать'">
            <BkIcon :name="visible ? 'eyeOff' : 'eye'" size="sm" />
          </button>
        </div>

        <div class="rpm-tools">
          <button class="btn rpm-tool" type="button" @click="generate">
            <BkIcon name="sparkle" size="sm" /> Придумать пароль
          </button>
          <button class="btn rpm-tool" type="button" @click="copy" :disabled="!pass">
            <BkIcon name="copy" size="sm" /> {{ copied ? 'Скопировано' : 'Скопировать' }}
          </button>
        </div>

        <p v-if="pass && pass.length < 8" class="rpm-hint warn">Ещё {{ 8 - pass.length }} символов</p>
        <p v-else class="rpm-hint">Пароль виден только сейчас — потом его нельзя будет посмотреть, только задать заново.</p>

        <div class="modal-actions">
          <button class="btn" @click="close">Отмена</button>
          <button class="btn primary" @click="submit" :disabled="pass.length < 8">
            {{ bulk ? 'Выдать' : 'Сохранить' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, onMounted, nextTick } from 'vue';
import BkIcon from '@/components/ui/BkIcon.vue';
import { copyToClipboard } from '@/lib/utils.js';

const props = defineProps({
  title: { type: String, default: 'Пароль' },
  message: { type: String, default: '' },
  bulk: { type: Boolean, default: false },
  missingCount: { type: Number, default: null },
  totalCount: { type: Number, default: 0 },
});
const emit = defineEmits(['ok', 'cancel']);

const pass = ref('');
const visible = ref(false);
const copied = ref(false);
const mode = ref(props.missingCount === 0 ? 'all' : 'missing');
const inp = ref(null);

onMounted(async () => { await nextTick(); inp.value?.focus?.(); });

// Алфавит без похожих символов: ни 0/O, ни 1/l/I — их путают, когда
// пароль диктуют ресторану по телефону.
const LETTERS = 'abcdefghijkmnpqrstuvwxyz';
const LETTERS_UP = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
const DIGITS = '23456789';

function pick(chars, n) {
  const out = [];
  const rnd = new Uint32Array(n);
  crypto.getRandomValues(rnd);
  for (let i = 0; i < n; i++) out.push(chars[rnd[i] % chars.length]);
  return out.join('');
}

function generate() {
  // Вид «kfrmz-Gtq47»: буквы, дефис, одна заглавная и цифры в конце — так
  // пароль проще продиктовать ресторану по телефону и не спутать символы.
  pass.value = `${pick(LETTERS, 5)}-${pick(LETTERS_UP, 1)}${pick(LETTERS, 2)}${pick(DIGITS, 2)}`;
  visible.value = true;
  copied.value = false;
}

async function copy() {
  await copyToClipboard(pass.value);
  copied.value = true;
  setTimeout(() => { copied.value = false; }, 2000);
}

function submit() {
  if (pass.value.length < 8) return;
  emit('ok', { password: pass.value, mode: mode.value });
}

function close() { emit('cancel'); }
</script>

<style scoped>
.rpm { min-width: 380px; max-width: 460px; }
.rpm-message { margin: 0 0 12px; color: var(--text-muted); font-size: 13px; line-height: 1.5; }
.rpm-select {
  width: 100%; box-sizing: border-box; margin-bottom: 10px;
  padding: 8px 12px; font-size: 13px; font-family: inherit;
  border: 1.5px solid var(--border); border-radius: 8px;
  background: var(--card); color: var(--text);
}
.rpm-warn {
  margin: 0 0 12px; padding: 8px 12px; border-radius: 8px;
  background: #FFF3E0; border: 1px solid #FFE0B2; color: #E65100;
  font-size: 12px; line-height: 1.45;
}
.rpm-field { position: relative; }
.rpm-input {
  width: 100%; box-sizing: border-box;
  padding: 10px 40px 10px 12px; font-size: 15px; letter-spacing: .02em;
  border: 1.5px solid var(--border); border-radius: 8px;
  background: var(--card); color: var(--text); font-family: inherit;
}
.rpm-input:focus { outline: none; border-color: var(--bk-orange); box-shadow: 0 0 0 3px rgba(244,162,97,.15); }
.rpm-icon-btn {
  position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
  background: none; border: none; cursor: pointer; color: var(--text-muted); line-height: 0; padding: 4px;
}
.rpm-tools { display: flex; gap: 8px; margin-top: 10px; }
.rpm-tool { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-size: 12px; padding: 7px 10px; }
.rpm-hint { margin: 10px 0 16px; font-size: 12px; color: var(--text-muted); line-height: 1.45; }
.rpm-hint.warn { color: #E65100; }

@media (max-width: 480px) {
  .rpm { min-width: 0; width: 100%; }
  .rpm-tools { flex-direction: column; }
}
</style>
