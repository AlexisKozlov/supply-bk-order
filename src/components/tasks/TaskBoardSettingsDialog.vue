<template>
  <Teleport to="body" v-if="modelValue">
    <div class="bs-overlay" @click.self="close">
      <div class="bs-modal" @click.stop>
        <header class="bs-head">
          <span class="bs-head-icon"><TaskIcon name="gear" :size="16"/></span>
          <h2 class="bs-title">Настройки доски</h2>
          <button class="bs-close" @click="close" title="Закрыть">
            <TaskIcon name="close" :size="16"/>
          </button>
        </header>

        <div class="bs-body">
          <!-- Авто-таймер -->
          <section class="bs-section">
            <h3 class="bs-section-title">Учёт времени</h3>
            <label class="bs-toggle">
              <input type="checkbox" v-model="draft.auto_timer"/>
              <span class="bs-toggle-track"><span class="bs-toggle-knob"></span></span>
              <span class="bs-toggle-text">
                <span class="bs-toggle-label">Авто-таймер при создании задачи</span>
                <span class="bs-toggle-hint">Когда создаёте новую задачу — таймер сразу запускается на вас. Таймер предыдущей задачи при этом ставится на паузу.</span>
              </span>
            </label>
          </section>

          <!-- Значения по умолчанию -->
          <section class="bs-section">
            <h3 class="bs-section-title">Значения по умолчанию для новых задач</h3>
            <label class="bs-field">
              <span class="bs-field-label">Приоритет</span>
              <select v-model="draft.default_priority" class="bs-select">
                <option value="">Не задан</option>
                <option value="low">Низкий</option>
                <option value="medium">Средний</option>
                <option value="high">Высокий</option>
                <option value="urgent">Срочный</option>
              </select>
            </label>
            <label class="bs-field">
              <span class="bs-field-label">Исполнитель</span>
              <select v-model="draft.default_assignee" class="bs-select">
                <option value="">Не задан</option>
                <option v-for="u in store.users" :key="u.name" :value="u.name">{{ u.name }}</option>
              </select>
            </label>
            <label class="bs-field">
              <span class="bs-field-label">Колонка</span>
              <select v-model="draft.default_column_id" class="bs-select">
                <option :value="null">Не задана</option>
                <option v-for="c in normalColumns" :key="c.id" :value="c.id">{{ c.title }}</option>
              </select>
            </label>
          </section>

          <!-- Оформление -->
          <section class="bs-section">
            <h3 class="bs-section-title">Оформление</h3>
            <div class="bs-field bs-field-col">
              <span class="bs-field-label">Цвет акцента доски</span>
              <ColorPalette :model-value="draft.accent_color || ''"
                            @update:modelValue="draft.accent_color = $event"/>
              <button v-if="draft.accent_color" type="button" class="bs-clear-color"
                      @click="draft.accent_color = null">
                Убрать цвет
              </button>
            </div>
            <label class="bs-toggle">
              <input type="checkbox" v-model="draft.compact_cards"/>
              <span class="bs-toggle-track"><span class="bs-toggle-knob"></span></span>
              <span class="bs-toggle-text">
                <span class="bs-toggle-label">Компактный режим карточек</span>
                <span class="bs-toggle-hint">Меньше деталей на карточке — больше задач помещается на экране.</span>
              </span>
            </label>
          </section>
        </div>

        <footer class="bs-foot">
          <button class="btn" @click="close">Отмена</button>
          <button class="btn primary" :disabled="saving" @click="save">
            {{ saving ? 'Сохранение…' : 'Сохранить' }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useTasksStore } from '../../stores/tasksStore';
import TaskIcon from './TaskIcon.vue';
import ColorPalette from './ColorPalette.vue';

const props = defineProps({
  modelValue: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const store = useTasksStore();
const saving = ref(false);

const draft = ref({
  auto_timer: false,
  default_priority: '',
  default_assignee: '',
  default_column_id: null,
  accent_color: null,
  compact_cards: false,
});

const normalColumns = computed(() =>
  store.columns.filter(c => !c.is_archive_column)
);

function syncFromBoard() {
  const b = store.board || {};
  draft.value = {
    auto_timer: !!b.auto_timer,
    default_priority: b.default_priority || '',
    default_assignee: b.default_assignee || '',
    default_column_id: b.default_column_id != null ? String(b.default_column_id) : null,
    accent_color: b.accent_color || null,
    compact_cards: !!b.compact_cards,
  };
}

watch(() => props.modelValue, (open) => {
  if (open) {
    syncFromBoard();
    store.fetchUsers();
  }
});

function close() {
  emit('update:modelValue', false);
}

async function save() {
  if (!store.board) { close(); return; }
  saving.value = true;
  try {
    await store.updateBoard(store.board.id, {
      auto_timer: draft.value.auto_timer ? 1 : 0,
      compact_cards: draft.value.compact_cards ? 1 : 0,
      default_priority: draft.value.default_priority || null,
      default_assignee: draft.value.default_assignee || null,
      default_column_id: draft.value.default_column_id || null,
      accent_color: draft.value.accent_color || null,
    });
    close();
  } catch (e) {
    alert('Не удалось сохранить настройки: ' + (e?.message || e));
  } finally {
    saving.value = false;
  }
}
</script>

<style scoped>
.bs-overlay {
  position: fixed; inset: 0;
  background: rgba(9,30,66,0.48);
  display: flex; align-items: center; justify-content: center;
  z-index: 1000;
  padding: 16px;
}
.bs-modal {
  background: #fff;
  border-radius: var(--tk-r-lg);
  width: 100%; max-width: 520px;
  max-height: 90vh;
  display: flex; flex-direction: column;
  box-shadow: 0 18px 48px rgba(9,30,66,0.32);
}
.bs-head {
  display: flex; align-items: center; gap: 10px;
  padding: 16px 18px;
  border-bottom: 1px solid var(--tk-n-200);
}
.bs-head-icon { display: flex; color: var(--tk-accent); }
.bs-title { flex: 1; margin: 0; font-size: 16px; font-weight: 700; color: var(--tk-text); }
.bs-close {
  background: none; border: none; cursor: pointer;
  display: flex; padding: 4px; border-radius: 6px; color: var(--tk-n-300);
}
.bs-close:hover { background: var(--tk-n-100); color: var(--tk-text); }

.bs-body {
  padding: 6px 18px 12px;
  overflow-y: auto;
}
.bs-section {
  padding: 14px 0;
  border-bottom: 1px solid var(--tk-n-100);
}
.bs-section:last-child { border-bottom: none; }
.bs-section-title {
  margin: 0 0 12px;
  font-size: 12px; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.04em;
  color: var(--tk-n-300);
}

.bs-field {
  display: flex; align-items: center; gap: 12px;
  margin-bottom: 10px;
}
.bs-field:last-child { margin-bottom: 0; }
.bs-field-col { flex-direction: column; align-items: flex-start; }
.bs-field-label {
  flex: 0 0 110px;
  font-size: 13px; color: var(--tk-text); font-weight: 500;
}
.bs-select {
  flex: 1;
  padding: 7px 10px;
  border: 1px solid var(--tk-n-200);
  border-radius: 8px;
  font-size: 13px; color: var(--tk-text);
  background: #fff;
}
.bs-select:focus { outline: none; border-color: var(--tk-accent); }

.bs-clear-color {
  margin-top: 8px;
  background: none; border: none; cursor: pointer;
  font-size: 12px; color: var(--tk-accent);
  padding: 0;
}
.bs-clear-color:hover { text-decoration: underline; }

.bs-toggle {
  display: flex; align-items: flex-start; gap: 12px;
  cursor: pointer;
}
.bs-toggle input { position: absolute; opacity: 0; pointer-events: none; }
.bs-toggle-track {
  flex: 0 0 auto;
  width: 38px; height: 22px;
  background: var(--tk-n-200);
  border-radius: 11px;
  position: relative;
  transition: background var(--tk-transition);
  margin-top: 1px;
}
.bs-toggle-knob {
  position: absolute; top: 2px; left: 2px;
  width: 18px; height: 18px;
  background: #fff; border-radius: 50%;
  box-shadow: 0 1px 3px rgba(9,30,66,0.3);
  transition: transform var(--tk-transition);
}
.bs-toggle input:checked + .bs-toggle-track { background: var(--tk-accent); }
.bs-toggle input:checked + .bs-toggle-track .bs-toggle-knob { transform: translateX(16px); }
.bs-toggle-text { display: flex; flex-direction: column; gap: 3px; }
.bs-toggle-label { font-size: 13px; font-weight: 600; color: var(--tk-text); }
.bs-toggle-hint { font-size: 12px; color: var(--tk-n-300); line-height: 1.4; }

.bs-foot {
  display: flex; justify-content: flex-end; gap: 8px;
  padding: 14px 18px;
  border-top: 1px solid var(--tk-n-200);
}
</style>
