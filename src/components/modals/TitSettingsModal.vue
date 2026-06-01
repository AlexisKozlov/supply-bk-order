<template>
  <div class="tss-overlay" @click.self="$emit('close')">
    <div class="tss-modal">
      <header class="tss-head">
        <h2>Получатели охраны</h2>
        <button class="tss-close" @click="$emit('close')" title="Закрыть">✕</button>
      </header>

      <div class="tss-body">
        <p class="tss-hint">Список адресов, на которые уходит заявка на пропуск. Отметить нужных можно при отправке.</p>

        <div v-if="loading" class="tss-loading">Загрузка…</div>
        <template v-else>
          <div v-if="!list.length" class="tss-empty">Список пуст — добавьте хотя бы один адрес.</div>
          <ul class="tss-list">
            <li v-for="(addr, i) in list" :key="i" class="tss-item">
              <code>{{ addr }}</code>
              <button class="tss-del" @click="remove(i)" title="Удалить">✕</button>
            </li>
          </ul>

          <div class="tss-add">
            <input
              v-model="input"
              type="email"
              placeholder="email@ttl.by"
              @keyup.enter="add"
              class="tss-input"
            />
            <button class="tss-btn ghost" @click="add" :disabled="!input.trim()">＋ Добавить</button>
          </div>
        </template>
      </div>

      <footer class="tss-foot">
        <button class="tss-btn ghost" @click="$emit('close')">Отмена</button>
        <div style="flex:1"></div>
        <button class="tss-btn primary" @click="save" :disabled="saving || loading">{{ saving ? 'Сохраняем…' : 'Сохранить' }}</button>
      </footer>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { db } from '@/lib/apiClient.js';
import { appAlert } from '@/lib/appDialogs.js';

const emit = defineEmits(['close']);
const list = ref([]);
const input = ref('');
const loading = ref(true);
const saving = ref(false);

const isEmail = (e) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e);

async function load() {
  loading.value = true;
  try {
    const { data, error } = await db.rpc('tit_settings_get', {});
    if (error) throw new Error(error);
    list.value = Array.isArray(data?.security_recipients) ? [...data.security_recipients] : [];
  } catch (e) {
    await appAlert('Не удалось загрузить настройки: ' + (e.message || e), { type: 'error' });
  } finally {
    loading.value = false;
  }
}

function add() {
  const e = input.value.trim();
  if (!e) return;
  if (!isEmail(e)) { appAlert('Некорректный email', { type: 'warning' }); return; }
  if (!list.value.includes(e)) list.value.push(e);
  input.value = '';
}
function remove(i) { list.value.splice(i, 1); }

async function save() {
  saving.value = true;
  try {
    const { data, error } = await db.rpc('tit_settings_update', {
      settings: { security_recipients: list.value },
    });
    if (error || data?.error) throw new Error(error || data.error);
    await appAlert('Список получателей сохранён', { title: 'Готово', type: 'success' });
    emit('close');
  } catch (e) {
    await appAlert('Не удалось сохранить: ' + (e.message || e), { type: 'error' });
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.tss-overlay { position: fixed; inset: 0; background: rgba(80,35,20,.5); z-index: 1100; display: flex; align-items: flex-start; justify-content: center; padding: 24px; overflow-y: auto; }
.tss-modal { background: #fff; border-radius: 14px; width: 100%; max-width: 480px; margin: auto; display: flex; flex-direction: column; box-shadow: 0 12px 40px rgba(80,35,20,.25); }
.tss-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid #EDE2D2; }
.tss-head h2 { margin: 0; font-size: 17px; font-weight: 700; color: var(--bk-brown, #502314); }
.tss-close { border: none; background: none; font-size: 18px; cursor: pointer; color: #8C7B6E; }
.tss-body { padding: 16px 20px; }
.tss-hint { margin: 0 0 12px; font-size: 13px; color: #8C7B6E; }
.tss-loading, .tss-empty { padding: 16px 0; color: #8C7B6E; font-size: 13px; text-align: center; }
.tss-list { list-style: none; margin: 0 0 12px; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.tss-item { display: flex; align-items: center; gap: 10px; padding: 8px 12px; background: #FFF8ED; border: 1px solid #EDE2D2; border-radius: 8px; }
.tss-item code { flex: 1; font-family: ui-monospace, Menlo, monospace; font-size: 13px; color: var(--bk-brown, #502314); }
.tss-del { border: none; background: none; color: #B91C1C; cursor: pointer; font-size: 14px; padding: 2px 6px; }
.tss-add { display: flex; gap: 8px; }
.tss-input { flex: 1; height: 38px; border-radius: 8px; border: 1.5px solid #E5DDD3; padding: 0 12px; font-family: inherit; font-size: 13px; background: #fff; color: var(--bk-brown, #502314); }
.tss-foot { display: flex; align-items: center; gap: 8px; padding: 14px 20px; border-top: 1px solid #EDE2D2; }
.tss-btn { padding: 9px 18px; border-radius: 10px; border: 1.5px solid transparent; font-size: 14px; font-weight: 600; cursor: pointer; font-family: inherit; }
.tss-btn:disabled { opacity: .5; cursor: not-allowed; }
.tss-btn.primary { background: var(--bk-red, #E76F51); color: #fff; }
.tss-btn.ghost { background: transparent; color: var(--bk-brown, #502314); border-color: #E5DDD3; }
</style>
