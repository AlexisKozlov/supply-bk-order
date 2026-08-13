<template>
  <div class="ptl" :class="{ 'ptl-picker-open': pickerOpen }" ref="rootRef">
    <!-- Переключатель: по умолчанию видно только незакрытое, выполненное убрано под свёртку -->
    <div v-if="showFilters" class="ptl-filters">
      <button type="button" class="ptl-filter" :class="{ 'is-active': filter === 'open' }" @click="filter = 'open'">
        Открытые <span class="ptl-filter-num">{{ openTasks.length }}</span>
      </button>
      <button type="button" class="ptl-filter" :class="{ 'is-active': filter === 'mine' }" @click="filter = 'mine'">
        Мои <span class="ptl-filter-num">{{ myTasks.length }}</span>
      </button>
      <button type="button" class="ptl-filter" :class="{ 'is-active': filter === 'all' }" @click="filter = 'all'">
        Все <span class="ptl-filter-num">{{ tasks.length }}</span>
      </button>
    </div>

    <div class="ptl-scroll">
      <!-- Шапка колонок: на телефоне не нужна, там строки превращаются в карточки -->
      <div class="ptl-head" :style="gridStyle">
        <span class="ptl-c-expand"></span>
        <span class="ptl-c-num">№</span>
        <span class="ptl-c-text">Задача</span>
        <span class="ptl-c-progress">Прогресс</span>
        <span class="ptl-c-resp">Ответственный</span>
        <span class="ptl-c-date">Срок</span>
        <span class="ptl-c-status">Статус</span>
        <span v-if="hasTail" class="ptl-c-tail">{{ mode === 'carryover' ? 'Из протокола' : '' }}</span>
      </div>

      <template v-for="row in rows" :key="row.k === 'bar' ? 'done-bar' : rowKey(row.t)">
        <!-- Полоса «Выполнено: N» — свёртка закрытых задач -->
        <button v-if="row.k === 'bar'" type="button" class="ptl-done-bar" :aria-expanded="showDone ? 'true' : 'false'" @click="showDone = !showDone">
          <svg class="ptl-done-chevron" :class="{ 'is-open': showDone }" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
          <span>Выполнено: {{ doneTasks.length }}</span>
          <span class="ptl-done-hint">{{ showDone ? 'скрыть' : 'показать' }}</span>
        </button>

        <div v-else class="ptl-row" :class="rowClass(row.t)" :style="gridStyle" @click="onRowClick($event, row.t)">
          <span class="ptl-c ptl-c-expand">
            <button v-if="row.t.id" type="button" class="ptl-expand" :class="{ 'is-open': expanded[row.t.id] }" :aria-expanded="expanded[row.t.id] ? 'true' : 'false'" title="Подробнее" @click.stop="toggleExpand(row.t.id)">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
          </span>

          <span class="ptl-c ptl-c-num">{{ numberOf(row.t) }}</span>

          <span class="ptl-c ptl-c-text" @click.stop="onTextClick(row.t)">
            <textarea
              v-if="editingTask === row.t"
              v-model="row.t.text"
              class="ptl-text-edit is-active"
              rows="1"
              placeholder="Текст задачи"
              @input="autoResize"
              @blur="editingTask = null"
              @click.stop
            ></textarea>
            <template v-else>
              <span
                class="ptl-text"
                :class="{ 'is-empty': !row.t.text, 'is-editable': canEditText, 'is-empty-warn': emptyWarning && !String(row.t.text || '').trim(), 'is-clamped': isClamped(row.t) }"
              >{{ row.t.text || (canEditText ? 'Нажмите, чтобы ввести текст' : '—') }}</span>
              <!-- Длинные инструкции раньше растягивали строку на пол-экрана -->
              <button v-if="isLong(row.t)" type="button" class="ptl-text-more" @click.stop="toggleFullText(row.t)">
                {{ fullText[textKey(row.t)] ? 'свернуть' : 'показать целиком' }}
              </button>
            </template>
          </span>

          <span class="ptl-c ptl-c-progress">
            <span v-if="(row.t.assignees_progress || []).length" class="ptl-progress">
              <span class="ptl-progress-num">{{ progressDone(row.t) }} <span class="ptl-progress-of">из</span> {{ progressTotal(row.t) }}</span>
              <span class="ptl-progress-bar"><span :style="{ width: progressPercent(row.t) + '%' }"></span></span>
            </span>
            <span v-else class="ptl-empty">—</span>
          </span>

          <span class="ptl-c ptl-c-resp">
            <span v-if="canEditText && mode === 'own'" class="ptl-multi">
              <span class="ptl-multi-tags" @click.stop="toggleRespPicker(row.t)">
                <span v-for="name in row.t.responsible_person" :key="name" class="ptl-resp-tag">
                  {{ name }}
                  <span class="ptl-resp-x" title="Убрать" @click.stop="removeResponsible(row.t, name)">&times;</span>
                </span>
                <span v-if="!row.t.responsible_person.length" class="ptl-resp-placeholder">Выбрать...</span>
              </span>
              <span v-if="openRespPicker === row.t" class="ptl-resp-menu" @click.stop>
                <span v-for="name in participants" :key="name" class="ptl-resp-option" @click="toggleResponsible(row.t, name)">
                  <span class="ptl-resp-check">{{ row.t.responsible_person.includes(name) ? '☑' : '☐' }}</span> {{ name }}
                </span>
                <span v-if="!participants.length" class="ptl-resp-empty">Нет участников</span>
              </span>
            </span>
            <span v-else class="ptl-resp-readonly">{{ formatResponsible(row.t.responsible_person) || '—' }}</span>
          </span>

          <span class="ptl-c ptl-c-date">
            <button type="button" class="ptl-chip ptl-chip-date" :class="[dueChipClass(row.t), { 'is-disabled': !canChange(row.t) }]" :disabled="!canChange(row.t)" @click.stop="openDate($event)">
              <svg class="ptl-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 9h18"/></svg>
              <span>{{ row.t.deadline ? fmtFullDate(row.t.deadline) : 'без срока' }}</span>
            </button>
            <input type="date" v-model="row.t.deadline" :disabled="!canChange(row.t)" class="ptl-hidden-date" @change="emit('deadline', row.t)" @click.stop>
          </span>

          <span class="ptl-c ptl-c-status">
            <span class="ptl-chip-wrap">
              <button type="button" class="ptl-chip" :class="['ptl-chip-st-' + row.t.status, { 'is-disabled': !canChange(row.t) }]" :disabled="!canChange(row.t)" @click.stop="toggleStatusPicker(row.t)">
                <span class="ptl-chip-dot"></span><span>{{ statusLabel(row.t.status) }}</span>
              </button>
              <span v-if="openStatusPicker === row.t" class="ptl-chip-menu" @click.stop>
                <span v-for="s in statusOptions" :key="s" class="ptl-chip-menu-item" :class="'ptl-chip-st-' + s" @click="pickStatus(row.t, s)">
                  <span class="ptl-chip-dot"></span><span>{{ statusLabel(s) }}</span>
                </span>
              </span>
            </span>
          </span>

          <span v-if="hasTail" class="ptl-c ptl-c-tail">
            <button v-if="mode === 'own' && canEditText" type="button" class="ptl-del" title="Удалить задачу" @click.stop="emit('remove', row.t)">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
            </button>
            <span v-else-if="mode === 'carryover'" class="ptl-source">{{ fmtShortDate(row.t.meeting_date) }}</span>
          </span>

          <!-- Раскрытая часть: кто из соисполнителей что сделал -->
          <span v-if="row.t.id && expanded[row.t.id]" class="ptl-details" @click.stop>
            <span v-if="(row.t.assignees_progress || []).length" class="ptl-assignees">
              <span v-for="a in row.t.assignees_progress" :key="a.user_name" class="ptl-assignee" :class="{ 'is-done': a.is_done }">
                <span class="ptl-assignee-name">
                  <span class="ptl-assignee-mark">{{ a.is_done ? '✓' : '○' }}</span>{{ a.user_name }}
                </span>
                <span class="ptl-assignee-result" :class="{ 'is-editable': canEditDescription(a) }" @click.stop="startEditDescription(a)">
                  <textarea
                    v-if="editingDescCardId === a.card_id"
                    v-model="a.description"
                    class="ptl-assignee-edit is-active"
                    rows="1"
                    placeholder="Описание / результат…"
                    @input="autoResize"
                    @blur="finishEditDescription(a)"
                    @click.stop
                  ></textarea>
                  <template v-else>
                    <span v-if="a.description">{{ a.description }}</span>
                    <span v-else-if="canEditDescription(a)" class="ptl-empty">— нажмите, чтобы добавить —</span>
                    <span v-else class="ptl-empty">—</span>
                  </template>
                </span>
              </span>
            </span>
            <span v-else class="ptl-empty ptl-details-empty">нет ответственных с карточками</span>
          </span>
        </div>
      </template>

      <div v-if="!rows.length" class="ptl-empty-state">{{ emptyText }}</div>
    </div>

    <button v-if="mode === 'own' && canEditText" type="button" class="ptl-add" @click="emit('add')">+ Добавить задачу</button>
  </div>
</template>

<script setup>
import { ref, computed, watch, onActivated, onDeactivated, onBeforeUnmount } from 'vue';

const props = defineProps({
  tasks: { type: Array, default: () => [] },
  // 'own' — задачи этого протокола (можно править), 'carryover' — долги с прошлых совещаний
  mode: { type: String, default: 'own' },
  participants: { type: Array, default: () => [] },
  // Право править текст задачи, ответственных и удалять строки
  canEditText: { type: Boolean, default: false },
  // (task) => bool — можно ли менять статус и срок этой задачи
  canChange: { type: Function, required: true },
  // (task) => bool — задача на мне
  isMine: { type: Function, required: true },
  // (task) => number — номер задачи в протоколе (не в отфильтрованном списке!)
  numberOf: { type: Function, required: true },
  emptyWarning: { type: Boolean, default: false },
  emptyStateText: { type: String, default: 'Задач пока нет' },
  showFilters: { type: Boolean, default: true },
  // (assignee) => bool — чужие отчёты о работе сервер править не даёт,
  // поэтому и приглашать к правке не надо
  canEditDescription: { type: Function, default: () => false },
});

const emit = defineEmits(['status', 'deadline', 'remove', 'add', 'save-description']);

const rootRef = ref(null);
const filter = ref('open');
const showDone = ref(false);

// Найти поле ввода внутри своего списка: на странице два таких списка,
// и поиск по всему документу мог увести фокус в чужой
function findInOwn(selector) {
  return rootRef.value?.querySelector(selector) || null;
}

const openTasks = computed(() => props.tasks.filter(t => t.status !== 'done'));
const doneTasks = computed(() => props.tasks.filter(t => t.status === 'done'));
const myTasks = computed(() => props.tasks.filter(t => props.isMine(t)));

const visibleTasks = computed(() => {
  if (!props.showFilters) return props.tasks;
  if (filter.value === 'mine') return myTasks.value;
  if (filter.value === 'all') return props.tasks;
  return openTasks.value;
});

// Список строк вперемешку с полосой «Выполнено: N» — так разметка строки
// описана один раз, а не отдельно для открытых и отдельно для закрытых
const rows = computed(() => {
  const out = visibleTasks.value.map(t => ({ k: 'row', t }));
  if (props.showFilters && filter.value === 'open' && doneTasks.value.length) {
    out.push({ k: 'bar' });
    if (showDone.value) out.push(...doneTasks.value.map(t => ({ k: 'row', t })));
  }
  return out;
});

function rowKey(t) { return t.id ? 'd-' + t.id : 'n-' + props.numberOf(t); }

// Родитель добавил задачу — показываем её, даже если включён фильтр,
// под который она не подходит (новая задача ещё без ответственных)
function showTask(t) {
  if (!t) return;
  if (filter.value === 'mine' && !props.isMine(t)) filter.value = 'open';
  if (filter.value === 'open' && t.status === 'done') showDone.value = true;
}
defineExpose({ showTask });

// Пустой список значит разное: «задач нет вообще», «на мне ничего нет»,
// «всё закрыто» — иначе человек решит, что задачи потерялись
const emptyText = computed(() => {
  if (!props.tasks.length) return props.emptyStateText;
  if (filter.value === 'mine') return 'На вас задач в этом протоколе нет';
  return props.emptyStateText;
});

// Пустые задачи, которые человек отказался удалять, должны быть видны:
// иначе подсветка сработает вхолостую на скрытой строке
watch(() => props.emptyWarning, (on) => { if (on) filter.value = 'all'; });

const hasTail = computed(() => props.mode === 'carryover' || props.canEditText);

const gridStyle = computed(() => {
  const tail = props.mode === 'carryover' ? '86px' : (props.canEditText ? '34px' : '');
  return { gridTemplateColumns: `26px 30px minmax(0, 1fr) 116px 186px 122px 126px ${tail}`.trim() };
});

const expanded = ref({});
function toggleExpand(id) {
  if (!id) return;
  expanded.value = { ...expanded.value, [id]: !expanded.value[id] };
}

const statusOptions = ['pending', 'done', 'overdue'];
function statusLabel(s) {
  if (s === 'pending') return 'В работе';
  if (s === 'done') return 'Выполнено';
  if (s === 'overdue') return 'Просрочено';
  return s;
}

const openStatusPicker = ref(null);
const openRespPicker = ref(null);
// На телефоне список прокручивается — открытому меню нужно место снизу,
// иначе край блока его обрежет
const pickerOpen = computed(() => openStatusPicker.value !== null || openRespPicker.value !== null);

function toggleStatusPicker(t) {
  openStatusPicker.value = openStatusPicker.value === t ? null : t;
}
function pickStatus(t, s) {
  openStatusPicker.value = null;
  if (t.status === s) return;
  emit('status', t, s);
}
function toggleRespPicker(t) {
  if (!props.canEditText) return;
  openRespPicker.value = openRespPicker.value === t ? null : t;
}
// Ответственные правятся прямо в объекте задачи: родитель хранит тот же
// массив и отправляет его целиком по кнопке «Сохранить»
function toggleResponsible(t, name) {
  const idx = t.responsible_person.indexOf(name);
  if (idx >= 0) t.responsible_person.splice(idx, 1);
  else t.responsible_person.push(name);
}
function removeResponsible(t, name) {
  t.responsible_person = t.responsible_person.filter(n => n !== name);
}
function formatResponsible(rp) {
  return Array.isArray(rp) ? rp.join(', ') : (rp || '');
}

function rowClass(t) {
  return {
    'is-mine': props.isMine(t),
    'is-pending': t.status === 'pending',
    'is-overdue': t.status === 'overdue',
    'is-done': t.status === 'done',
    'is-expanded': !!expanded.value[t.id],
  };
}

function fmtShortDate(d) {
  if (!d) return '';
  return new Date(d + 'T00:00:00').toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
}
function fmtFullDate(d) {
  if (!d) return '';
  return new Date(d + 'T00:00:00').toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' });
}

function dueChipClass(t) {
  if (!t.deadline) return 'ptl-chip-date-none';
  if (t.status === 'done') return 'ptl-chip-date-done';
  const today = new Date(); today.setHours(0, 0, 0, 0);
  const diffH = (new Date(t.deadline + 'T00:00:00') - today) / 36e5;
  if (diffH < 0) return 'ptl-chip-date-overdue';
  if (diffH <= 24) return 'ptl-chip-date-soon24';
  if (diffH <= 72) return 'ptl-chip-date-soon72';
  return '';
}

function openDate(event) {
  const input = event.currentTarget?.parentElement?.querySelector('.ptl-hidden-date');
  if (!input || input.disabled) return;
  if (typeof input.showPicker === 'function') input.showPicker();
  else input.click();
}

function progressTotal(t) { return (t.assignees_progress || []).length; }
function progressDone(t) { return (t.assignees_progress || []).filter(a => a.is_done).length; }
function progressPercent(t) {
  const tot = progressTotal(t);
  return tot ? Math.round((progressDone(t) / tot) * 100) : 0;
}

// Длинный текст задачи сворачиваем: одна инструкция на десять строк
// раньше растягивала таблицу и прятала остальные задачи
const LONG_TEXT = 180;
const fullText = ref({});
function textKey(t) { return t.id ? 'd-' + t.id : 'n-' + props.numberOf(t); }
function isLong(t) { return String(t.text || '').length > LONG_TEXT; }
function isClamped(t) { return isLong(t) && !fullText.value[textKey(t)]; }
function toggleFullText(t) {
  const k = textKey(t);
  fullText.value = { ...fullText.value, [k]: !fullText.value[k] };
}

const editingTask = ref(null);
function startEdit(t) {
  if (!props.canEditText || props.mode !== 'own') return;
  editingTask.value = t;
  requestAnimationFrame(() => {
    const el = findInOwn('.ptl-text-edit.is-active');
    if (el) {
      el.focus();
      el.style.height = 'auto';
      el.style.height = el.scrollHeight + 'px';
      el.setSelectionRange(el.value.length, el.value.length);
    }
  });
}

function onRowClick(e, t) {
  if (!t || !t.id) return;
  if (e.target.closest('.ptl-chip-wrap, .ptl-multi, .ptl-del, .ptl-expand, .ptl-hidden-date, .ptl-text-edit, .ptl-text-more, .ptl-details')) return;
  toggleExpand(t.id);
}

// Клик по тексту: где можно править — сразу правка, где нельзя —
// раскрываем подробности, как и по остальной строке
function onTextClick(t) {
  if (props.canEditText && props.mode === 'own') { startEdit(t); return; }
  if (t.id) toggleExpand(t.id);
}

const editingDescCardId = ref(null);
const editingDescOriginal = ref('');
function startEditDescription(a) {
  if (!a || !a.card_id || !props.canEditDescription(a)) return;
  editingDescOriginal.value = a.description || '';
  editingDescCardId.value = a.card_id;
  requestAnimationFrame(() => {
    const el = findInOwn('.ptl-assignee-edit.is-active');
    if (el) {
      el.focus();
      el.style.height = 'auto';
      el.style.height = el.scrollHeight + 'px';
      el.setSelectionRange(el.value.length, el.value.length);
    }
  });
}
function finishEditDescription(a) {
  const original = editingDescOriginal.value;
  editingDescCardId.value = null;
  if ((a.description || '') === original) return;
  emit('save-description', a, original);
}

function autoResize(e) {
  const el = e.target;
  el.style.height = 'auto';
  el.style.height = el.scrollHeight + 'px';
}

function closePickers(e) {
  if (openRespPicker.value && !e.target.closest('.ptl-multi')) openRespPicker.value = null;
  if (openStatusPicker.value && !e.target.closest('.ptl-chip-wrap')) openStatusPicker.value = null;
}

// Страница живёт в кэше вкладок: слушатель работает, только пока раздел открыт
onActivated(() => document.addEventListener('click', closePickers));
onDeactivated(() => document.removeEventListener('click', closePickers));
onBeforeUnmount(() => document.removeEventListener('click', closePickers));
</script>

<style scoped>
.ptl { display: flex; flex-direction: column; gap: 8px; }

/* Переключатель списка */
.ptl-filters { display: flex; gap: 6px; flex-wrap: wrap; }
.ptl-filter { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border: 1px solid #e2e2e2; background: #fff; border-radius: 14px; font-size: 12px; color: #555; cursor: pointer; transition: background 0.12s, border-color 0.12s, color 0.12s; }
.ptl-filter:hover { border-color: #d0d0d0; background: #fafafa; }
.ptl-filter.is-active { background: #E76F51; border-color: #E76F51; color: #fff; font-weight: 600; }
.ptl-filter-num { font-weight: 700; font-size: 11px; opacity: 0.8; }
.ptl-filter.is-active .ptl-filter-num { opacity: 0.95; }

.ptl-scroll { max-width: 100%; }

/* Шапка и строки — одна и та же сетка колонок */
.ptl-head { display: grid; align-items: center; gap: 8px; padding: 6px 8px; border-bottom: 1px solid #ececec; font-size: 10px; color: #8a8a8a; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
.ptl-head .ptl-c-num { text-align: center; }

.ptl-row { display: grid; align-items: start; gap: 8px; padding: 7px 8px; border-bottom: 1px solid #f2f2f2; position: relative; cursor: pointer; }
.ptl-row.is-pending { background: #fffbf2; }
.ptl-row.is-done { background: #f4faf5; }
.ptl-row.is-overdue { background: #fdf2f2; }
.ptl-row.is-mine { box-shadow: inset 3px 0 0 #E76F51; }
.ptl-row.is-overdue { box-shadow: inset 3px 0 0 #c62828; }
.ptl-row.is-overdue.is-mine { box-shadow: inset 3px 0 0 #c62828, inset 6px 0 0 #E76F51; }

.ptl-c { min-width: 0; display: flex; align-items: center; }
.ptl-c-num { justify-content: center; font-size: 12px; color: #aaa; font-weight: 600; padding-top: 4px; }
.ptl-c-expand { padding-top: 2px; }
.ptl-c-text { flex-direction: column; align-items: flex-start; gap: 2px; }
.ptl-c-progress, .ptl-c-date, .ptl-c-status { padding-top: 3px; }
.ptl-c-tail { justify-content: center; padding-top: 3px; }

.ptl-expand { width: 22px; height: 22px; border: none; background: transparent; color: #999; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; border-radius: 4px; transition: transform 0.15s, color 0.15s, background 0.15s; }
.ptl-expand:hover { background: #f0f0f0; color: #333; }
.ptl-expand.is-open { transform: rotate(90deg); color: #E76F51; }

/* Текст задачи */
.ptl-text { font-size: 13px; line-height: 1.45; color: #2b2b2b; white-space: pre-wrap; word-break: break-word; }
.ptl-row.is-done .ptl-text { color: #6e8170; }
.ptl-text.is-empty { color: #bbb; font-style: italic; }
.ptl-text.is-empty-warn { color: #c62828; background: #fdf2f2; box-shadow: inset 0 0 0 1px #f3bcbc; font-style: normal; padding: 2px 6px; border-radius: 5px; }
.ptl-text.is-clamped { display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
.ptl-text-more { border: none; background: none; padding: 0; font-size: 11px; color: #E76F51; cursor: pointer; }
.ptl-text-more:hover { text-decoration: underline; }
.ptl-text-edit { width: 100%; box-sizing: border-box; padding: 6px 8px; border: 1px solid #E76F51; border-radius: 5px; font-size: 13px; line-height: 1.45; font-family: inherit; color: #2b2b2b; background: #fff; resize: none; overflow: hidden; min-height: 30px; }
.ptl-text-edit:focus { outline: none; box-shadow: 0 0 0 2px rgba(231, 111, 81, 0.18); }

/* Прогресс */
.ptl-progress { display: flex; flex-direction: column; gap: 3px; width: 100%; }
.ptl-progress-num { font-size: 12px; font-weight: 600; color: #4a4a4a; }
.ptl-progress-of { font-weight: 400; color: #999; }
.ptl-progress-bar { height: 4px; background: #ececec; border-radius: 2px; overflow: hidden; }
.ptl-progress-bar > span { display: block; height: 100%; background: #4caf50; border-radius: 2px; transition: width 0.2s; }
.ptl-empty { color: #bbb; font-size: 12px; }

/* Ответственные */
.ptl-multi { position: relative; width: 100%; }
.ptl-multi-tags { display: flex; flex-wrap: wrap; gap: 3px; padding: 3px 5px; min-height: 26px; cursor: pointer; border: 1px solid transparent; border-radius: 5px; align-items: center; }
.ptl-multi-tags:hover { border-color: #e2e2e2; background: #fafafa; }
.ptl-resp-tag { display: inline-flex; align-items: center; gap: 2px; background: #eef2f5; color: #4a5b6a; font-size: 11px; padding: 2px 7px; border-radius: 10px; white-space: nowrap; }
.ptl-resp-x { cursor: pointer; font-size: 13px; color: #90a4ae; margin-left: 2px; }
.ptl-resp-x:hover { color: #c62828; }
.ptl-resp-placeholder { font-size: 12px; color: #aaa; }
.ptl-resp-readonly { font-size: 12px; color: #555; word-break: break-word; }
.ptl-resp-menu { position: absolute; top: 100%; left: 0; right: 0; display: flex; flex-direction: column; background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; box-shadow: 0 6px 18px rgba(0,0,0,0.08); z-index: 30; max-height: 180px; overflow-y: auto; min-width: 180px; }
.ptl-resp-option { padding: 6px 10px; cursor: pointer; font-size: 12px; display: flex; align-items: center; gap: 6px; }
.ptl-resp-option:hover { background: #f5f5f5; }
.ptl-resp-check { font-size: 14px; flex-shrink: 0; }
.ptl-resp-empty { padding: 10px; font-size: 12px; color: #999; text-align: center; }

/* Чипы срока и статуса */
.ptl-chip { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 12px; font-size: 11px; font-weight: 600; line-height: 1.4; border: 1px solid transparent; background: #f1f3f5; color: #4a4a4a; cursor: pointer; white-space: nowrap; transition: filter 0.12s, transform 0.12s; }
.ptl-chip:hover:not(:disabled):not(.is-disabled) { filter: brightness(0.96); }
.ptl-chip:active:not(:disabled) { transform: translateY(1px); }
.ptl-chip.is-disabled, .ptl-chip:disabled { cursor: default; opacity: 0.75; }
.ptl-chip-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; flex-shrink: 0; opacity: 0.8; }
.ptl-chip-icon { width: 12px; height: 12px; flex-shrink: 0; }
.ptl-chip-st-pending { background: #fff4e0; color: #b35900; }
.ptl-chip-st-done { background: #e3f6e6; color: #1b5e20; }
.ptl-chip-st-overdue { background: #fde2e2; color: #b71c1c; }
.ptl-chip-date { background: #f1f3f5; color: #555; font-weight: 500; }
.ptl-chip-date-none { color: #aaa; }
.ptl-chip-date-soon72 { background: #fff8e1; color: #8c6d00; }
.ptl-chip-date-soon24 { background: #fff0e0; color: #b35900; }
.ptl-chip-date-overdue { background: #fde2e2; color: #b71c1c; }
.ptl-chip-date-done { color: #888; }
.ptl-hidden-date { position: absolute; width: 0; height: 0; opacity: 0; pointer-events: none; border: 0; padding: 0; }
.ptl-chip-wrap { position: relative; display: inline-block; }
.ptl-chip-menu { position: absolute; top: calc(100% + 4px); left: 0; display: flex; flex-direction: column; gap: 2px; background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; box-shadow: 0 6px 18px rgba(0,0,0,0.08); padding: 4px; min-width: 130px; z-index: 40; }
.ptl-chip-menu-item { display: flex; align-items: center; gap: 6px; padding: 5px 9px; border-radius: 6px; font-size: 12px; font-weight: 500; cursor: pointer; }
.ptl-chip-menu-item:hover { filter: brightness(0.96); }

.ptl-del { border: none; background: transparent; color: #c0c0c0; cursor: pointer; padding: 4px; border-radius: 4px; display: inline-flex; }
.ptl-del:hover { color: #c62828; background: #fde2e2; }
.ptl-source { font-size: 11px; color: #999; white-space: nowrap; }

/* Раскрытая часть — на всю ширину строки */
.ptl-details { grid-column: 1 / -1; display: flex; flex-direction: column; gap: 0; border-top: 1px dashed #e6e6e6; margin-top: 4px; padding-top: 4px; cursor: default; }
.ptl-assignees { display: flex; flex-direction: column; }
.ptl-assignee { display: grid; grid-template-columns: minmax(120px, max-content) minmax(0, 1fr); gap: 18px; padding: 7px 0 7px 8px; border-top: 1px solid #efefef; font-size: 13px; line-height: 1.5; }
.ptl-assignee:first-child { border-top: none; }
.ptl-assignee-name { color: #2b2b2b; font-weight: 500; white-space: nowrap; }
.ptl-assignee-mark { display: inline-block; width: 18px; text-align: center; font-weight: 700; font-size: 14px; color: #b8b8b8; margin-right: 6px; }
.ptl-assignee.is-done .ptl-assignee-name, .ptl-assignee.is-done .ptl-assignee-mark { color: #2e7d32; }
.ptl-assignee-result { color: #444; white-space: pre-wrap; word-break: break-word; }
.ptl-assignee-result.is-editable { cursor: text; }
.ptl-assignee-edit { width: 100%; box-sizing: border-box; padding: 6px 8px; border: 1px solid #E76F51; border-radius: 5px; font-size: 13px; line-height: 1.5; font-family: inherit; color: #2b2b2b; background: #fff; resize: none; overflow: hidden; min-height: 30px; }
.ptl-assignee-edit:focus { outline: none; box-shadow: 0 0 0 2px rgba(231, 111, 81, 0.18); }
.ptl-details-empty { padding: 8px; }

/* Полоса «Выполнено: N» */
.ptl-done-bar { display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 10px; margin-top: 4px; border: 1px dashed #dfe3e6; border-radius: 8px; background: #fafbfc; color: #6b7a86; font-size: 12px; font-weight: 600; cursor: pointer; }
.ptl-done-bar:hover { border-color: #cfd6db; background: #f4f6f8; color: #45525c; }
.ptl-done-hint { font-weight: 400; color: #9aa7b0; }
.ptl-done-chevron { transition: transform 0.15s; flex-shrink: 0; }
.ptl-done-chevron.is-open { transform: rotate(90deg); }

.ptl-empty-state { padding: 18px 8px; text-align: center; color: #aaa; font-size: 13px; }

.ptl-add { border: 1px dashed #ddd; border-radius: 6px; background: #fff; color: #888; width: 100%; padding: 8px; font-size: 13px; cursor: pointer; }
.ptl-add:hover { border-color: #E76F51; color: #E76F51; }

/* Телефон и узкие экраны: строка превращается в карточку, вбок ничего не едет */
@media (max-width: 900px) {
  .ptl-head { display: none; }
  /* Карточка вместо строки таблицы: ширины колонок здесь только мешают,
     поэтому раскладываем содержимое потоком */
  .ptl-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 8px;
    padding: 10px 8px;
    border: 1px solid #ededed;
    border-radius: 10px;
    margin-bottom: 8px;
  }
  .ptl-row.is-pending { border-color: #f2e2c4; }
  .ptl-row.is-done { border-color: #dceadd; }
  .ptl-row.is-overdue { border-color: #f3c9c9; }
  .ptl-c-expand { order: 1; flex: 0 0 auto; padding-top: 0; }
  .ptl-c-num { order: 2; flex: 0 0 auto; padding-top: 0; }
  .ptl-c-text { order: 3; flex: 1 1 60%; }
  .ptl-c-tail { order: 4; flex: 0 0 auto; margin-left: auto; padding-top: 0; }
  .ptl-c-resp { order: 5; flex: 1 1 100%; }
  .ptl-c-date { order: 6; flex: 0 0 auto; padding-top: 0; }
  .ptl-c-status { order: 7; flex: 0 0 auto; padding-top: 0; }
  .ptl-c-progress { order: 8; flex: 1 1 100%; padding-top: 0; }
  /* Пустой прогресс на телефоне только занимает место */
  .ptl-c-progress:has(.ptl-empty) { display: none; }
  .ptl-c-resp:empty, .ptl-c-tail:empty { display: none; }
  .ptl-details { order: 9; flex: 1 1 100%; }
  .ptl-assignee { grid-template-columns: minmax(0, 1fr); gap: 2px; }
  .ptl-assignee-name { white-space: normal; }
  .ptl-resp-readonly { font-size: 12px; }
  /* 16px — иначе телефон зумит страницу при попадании в поле */
  .ptl-text-edit, .ptl-assignee-edit { font-size: 16px; }
  /* Открытому меню нужно место снизу, иначе край блока его обрежет */
  .ptl.ptl-picker-open .ptl-scroll { padding-bottom: 150px; }
}
</style>
