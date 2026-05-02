<template>
  <Teleport to="body">
    <div class="task-sidebar-backdrop" @click="close"></div>
    <aside class="task-sidebar" @click.stop :class="{ 'task-sidebar-enter': true }">
      <div v-if="loading" class="task-sidebar-loader">Загрузка…</div>
      <template v-else-if="full">
        <!-- Шапка -->
        <header class="ts-header">
          <button v-if="canGoBack" class="ts-back" @click="$emit('go-back')" title="Назад к родителю">←</button>
          <div class="ts-header-titles">
            <button v-if="full.parent" class="ts-parent-link" @click="$emit('go-back')">
              ← {{ full.parent.title }}
            </button>
            <input v-model="full.card.title" class="ts-title-input"
                   @blur="patch({ title: full.card.title })" @keydown.enter.prevent="$event.target.blur()" />
            <div class="ts-subtitle">
              <template v-if="full.parent">подзадача в «{{ full.parent.title }}»</template>
              <template v-else>в колонке «{{ columnTitle }}»</template>
            </div>
          </div>
          <button class="ts-close" @click="close" title="Закрыть (Esc)">✕</button>
        </header>

        <!-- Свойства -->
        <div class="ts-props">
          <label class="ts-prop">
            <span class="ts-prop-label">Приоритет</span>
            <select :value="full.card.priority" @change="patch({ priority: $event.target.value })">
              <option value="low">Низкий</option>
              <option value="medium">Средний</option>
              <option value="high">Высокий</option>
              <option value="urgent">Срочно</option>
            </select>
          </label>
          <label class="ts-prop">
            <span class="ts-prop-label">Срок</span>
            <input type="datetime-local" :value="dueDateLocal" @change="onDueChange" />
          </label>
          <label class="ts-prop">
            <span class="ts-prop-label">Колонка</span>
            <select :value="full.card.column_id" @change="onColumnChange">
              <option v-for="col in columns" :key="col.id" :value="col.id">{{ col.title }}</option>
            </select>
          </label>
        </div>

        <!-- Вкладки -->
        <div class="ts-tabs">
          <button class="ts-tab" :class="{ active: tab === 'main' }" @click="tab = 'main'">Описание</button>
          <button class="ts-tab" :class="{ active: tab === 'chat' }" @click="tab = 'chat'">
            Чат <span v-if="full.comments?.length" class="ts-tab-badge">{{ full.comments.length }}</span>
          </button>
          <button class="ts-tab" :class="{ active: tab === 'history' }" @click="tab = 'history'">История</button>
        </div>

        <!-- ВКЛАДКА «ОПИСАНИЕ» -->
        <div v-if="tab === 'main'" class="ts-pane">

          <!-- Описание -->
          <section class="ts-section">
            <div class="ts-section-title">Описание</div>
            <textarea v-model="full.card.description" class="ts-textarea" rows="3"
                      placeholder="Добавить описание…"
                      @blur="patch({ description: full.card.description || '' })"></textarea>
          </section>

          <!-- Метки -->
          <section class="ts-section">
            <div class="ts-section-title">Метки</div>
            <div class="ts-labels">
              <button v-for="l in labels" :key="l.id" class="ts-label"
                      :class="{ active: full.label_ids.includes(l.id) }"
                      :style="{ background: full.label_ids.includes(l.id) ? l.color : 'transparent', borderColor: l.color, color: full.label_ids.includes(l.id) ? '#fff' : l.color }"
                      @click="toggleLabel(l)">{{ l.title }}</button>
              <button class="ts-label add-label" @click="addNewLabel">+ Метка</button>
            </div>
          </section>

          <!-- Чек-лист -->
          <section class="ts-section">
            <div class="ts-section-title">
              Чек-лист
              <span v-if="checklistTotal" class="ts-section-meta">{{ checklistDone }}/{{ checklistTotal }}</span>
            </div>
            <div v-if="checklistTotal" class="ts-progress">
              <div class="ts-progress-bar" :style="{ width: checklistPct + '%' }"></div>
            </div>
            <ul class="ts-checklist">
              <li v-for="item in full.checklist" :key="item.id" class="ts-chk-item">
                <input type="checkbox" class="ts-chk-box" :checked="!!item.is_done" @change="toggleChecklist(item)" />
                <span v-if="editingChecklistId !== item.id" class="ts-chk-text"
                      :class="{ done: item.is_done }"
                      @click="startEditChecklist(item)">{{ item.title || '(пусто)' }}</span>
                <input v-else type="text" class="ts-chk-input"
                       :ref="el => editingInputRef = el"
                       v-model="editingChecklistTitle"
                       @blur="saveEditChecklist(item)"
                       @keydown.enter.prevent="saveEditChecklist(item)"
                       @keydown.esc="cancelEditChecklist" />
                <button class="ts-icon-btn" @click="deleteChecklist(item)" title="Удалить">✕</button>
              </li>
            </ul>
            <div class="ts-chk-add">
              <input v-model="newChecklistTitle" type="text" placeholder="Новый пункт…"
                     @keydown.enter="addChecklistItem" />
              <button class="btn primary ts-btn-sm" @click="addChecklistItem" :disabled="!newChecklistTitle.trim()">+</button>
            </div>
          </section>

          <!-- Подзадачи (только у корневых карточек) -->
          <section v-if="!full.parent" class="ts-section">
            <div class="ts-section-title">
              Подзадачи
              <span v-if="subtasksTotal" class="ts-section-meta">{{ subtasksDone }}/{{ subtasksTotal }}</span>
            </div>
            <div v-if="subtasksTotal" class="ts-progress">
              <div class="ts-progress-bar" :style="{ width: subtasksPct + '%', background: '#42A5F5' }"></div>
            </div>
            <ul class="ts-subtasks">
              <li v-for="st in full.subtasks" :key="st.id" class="ts-sub-item">
                <input type="checkbox" class="ts-round-chk" :checked="!!st.is_done" @change="toggleSubtaskDone(st)" />
                <button class="ts-sub-text" :class="{ done: st.is_done }" @click="$emit('open-card', st.id)">
                  <span class="ts-sub-title">{{ st.title }}</span>
                  <span class="ts-sub-meta">
                    <span v-if="st.priority && st.priority !== 'medium'" class="ts-sub-prio" :class="'prio-bg-' + st.priority">{{ priorityShort(st.priority) }}</span>
                    <span v-if="st.due_date" class="ts-sub-due" :class="{ overdue: isSubOverdue(st) }">{{ formatSubDue(st.due_date) }}</span>
                    <span v-if="st.assignees?.length" class="ts-sub-assignees">
                      <span v-for="(n, i) in st.assignees.slice(0,2)" :key="i" class="ts-sub-bubble" :title="n">{{ initials(n) }}</span>
                    </span>
                  </span>
                </button>
                <button class="ts-icon-btn" @click="deleteSubtask(st)" title="Удалить">✕</button>
              </li>
            </ul>
            <div class="ts-chk-add">
              <input v-model="newSubtaskTitle" type="text" placeholder="Новая подзадача…"
                     @keydown.enter="addSubtask" />
              <button class="btn primary ts-btn-sm" @click="addSubtask" :disabled="!newSubtaskTitle.trim()">+</button>
            </div>
          </section>

          <!-- Соисполнители -->
          <section class="ts-section">
            <div class="ts-section-title">Соисполнители</div>
            <div class="ts-assignees">
              <span v-for="n in full.assignees" :key="n" class="ts-chip">
                {{ n }}
                <button v-if="canEditStructure" class="ts-icon-btn" @click="removeAssignee(n)">✕</button>
              </span>
              <span v-if="!full.assignees.length" class="ts-empty">Никого</span>
            </div>
            <select v-if="canEditStructure" v-model="newAssignee" @change="addAssignee" class="ts-assignee-add">
              <option value="">+ Добавить…</option>
              <option v-for="u in availableUsers" :key="u.name" :value="u.name">{{ u.name }}</option>
            </select>
          </section>

          <!-- Связи -->
          <section class="ts-section">
            <div class="ts-section-title">Связано с
              <button class="ts-section-add" @click="showRelationPicker = !showRelationPicker">+ Добавить</button>
            </div>
            <div v-if="full.relations.length" class="ts-relations">
              <div v-for="r in full.relations" :key="r.id" class="ts-relation">
                <span class="ts-relation-type">{{ relationTypeLabel(r.entity_type) }}</span>
                <span class="ts-relation-label">{{ r.entity_label || r.entity_id }}</span>
                <button class="ts-icon-btn" @click="removeRelation(r)" title="Убрать">✕</button>
              </div>
            </div>
            <div v-else class="ts-empty">Нет связей</div>
            <div v-if="showRelationPicker" class="ts-relation-picker">
              <select v-model="relationDraft.type">
                <option value="">— тип —</option>
                <option value="order">Заказ</option>
                <option value="supplier">Поставщик</option>
                <option value="product">Товар</option>
                <option value="pricing">ПСЦ</option>
                <option value="plan">План закупок</option>
                <option value="so_order">Заявка поставщику</option>
              </select>
              <input v-model="relationDraft.id" type="text" placeholder="ID или код" />
              <input v-model="relationDraft.label" type="text" placeholder="Подпись (необяз.)" />
              <button class="btn primary ts-btn-sm" @click="addRelation" :disabled="!relationDraft.type || !relationDraft.id">Добавить</button>
            </div>
          </section>

          <!-- Удаление -->
          <section v-if="canDelete" class="ts-section ts-section-danger">
            <button class="btn danger" @click="askDelete">Удалить карточку</button>
          </section>
        </div>

        <!-- ВКЛАДКА «ЧАТ» -->
        <div v-if="tab === 'chat'" class="ts-pane ts-chat-pane">
          <div class="ts-chat-list" ref="chatListRef">
            <div v-if="!full.comments.length" class="ts-empty ts-chat-empty">
              Чат пуст. Напишите первое сообщение.
            </div>
            <div v-for="c in full.comments" :key="c.id" class="ts-chat-msg"
                 :class="{ own: c.author_name === currentUserName }">
              <div class="ts-chat-bubble">
                <div class="ts-chat-meta">
                  <span class="ts-chat-author">{{ c.author_name }}</span>
                  <span class="ts-chat-date">{{ formatDate(c.created_at) }}<span v-if="c.edited_at"> · ред.</span></span>
                </div>
                <div class="ts-chat-body">{{ c.body }}</div>
                <div v-if="canEditComment(c)" class="ts-chat-actions">
                  <button class="ts-chat-action" @click="editComment(c)">✎</button>
                  <button class="ts-chat-action" @click="removeComment(c)">✕</button>
                </div>
              </div>
            </div>
          </div>
          <div class="ts-chat-input">
            <textarea v-model="newComment" rows="2" placeholder="Сообщение… (Ctrl+Enter — отправить)"
                      @keydown.ctrl.enter.prevent="submitComment"></textarea>
            <button class="btn primary" @click="submitComment" :disabled="!newComment.trim()">Отправить</button>
          </div>
        </div>

        <!-- ВКЛАДКА «ИСТОРИЯ» -->
        <div v-if="tab === 'history'" class="ts-pane">
          <div class="ts-history">
            <div v-for="h in full.history" :key="h.id" class="ts-history-item">
              <div class="ts-history-row">
                <span class="ts-history-author">{{ h.user_name }}</span>
                <span class="ts-history-action">{{ historyText(h) }}</span>
              </div>
              <div class="ts-history-date">{{ formatDate(h.created_at) }}</div>
            </div>
            <div v-if="!full.history.length" class="ts-empty">История пуста</div>
          </div>
        </div>
      </template>
    </aside>
  </Teleport>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue';
import { tasksApi } from '@/lib/tasksApi.js';
import { useTasksStore } from '@/stores/tasksStore.js';
import { useUserStore } from '@/stores/userStore.js';

const props = defineProps({
  cardId: { type: Number, required: true },
  canGoBack: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'updated', 'deleted', 'open-card', 'go-back']);

const store = useTasksStore();
const userStore = useUserStore();
const full = ref(null);
const loading = ref(true);
const tab = ref('main');
const newComment = ref('');
const newChecklistTitle = ref('');
const newSubtaskTitle = ref('');
const newAssignee = ref('');
const showRelationPicker = ref(false);
const relationDraft = ref({ type: '', id: '', label: '' });
const chatListRef = ref(null);
const editingChecklistId = ref(null);
const editingChecklistTitle = ref('');
const editingInputRef = ref(null);

const columns = computed(() => store.columns);
const labels = computed(() => store.labels);
const canEditStructure = computed(() => store.canEditStructure);
const currentUserName = computed(() => userStore.currentUser?.name || '');

const columnTitle = computed(() => {
  if (!full.value) return '';
  return columns.value.find(c => c.id === full.value.card.column_id)?.title || '';
});

const checklistTotal = computed(() => full.value?.checklist?.length || 0);
const checklistDone = computed(() => full.value?.checklist?.filter(i => i.is_done).length || 0);
const checklistPct = computed(() => checklistTotal.value ? Math.round(checklistDone.value / checklistTotal.value * 100) : 0);

const subtasksTotal = computed(() => full.value?.subtasks?.length || 0);
const subtasksDone = computed(() => full.value?.subtasks?.filter(s => s.is_done).length || 0);
const subtasksPct = computed(() => subtasksTotal.value ? Math.round(subtasksDone.value / subtasksTotal.value * 100) : 0);

const dueDateLocal = computed(() => {
  if (!full.value?.card.due_date) return '';
  const d = new Date(full.value.card.due_date);
  if (isNaN(d)) return '';
  const pad = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
});

const availableUsers = computed(() => {
  const taken = new Set(full.value?.assignees || []);
  taken.add(store.board?.owner_name);
  return store.users.filter(u => !taken.has(u.name));
});

const canDelete = computed(() => {
  if (!full.value) return false;
  if (userStore.currentUser?.role === 'admin') return true;
  if (full.value.card.owner_name === userStore.currentUser?.name) return true;
  return full.value.card.created_by === userStore.currentUser?.name;
});

async function load() {
  loading.value = true;
  try {
    full.value = await tasksApi.loadCard(props.cardId);
  } catch (e) {
    alert('Ошибка загрузки: ' + e.message);
    close();
    return;
  } finally {
    loading.value = false;
  }
  if (canEditStructure.value) store.fetchUsers();
}

onMounted(() => {
  load();
  document.addEventListener('keydown', onKeydown);
});
onUnmounted(() => {
  document.removeEventListener('keydown', onKeydown);
});
watch(() => props.cardId, load);

// Автопрокрутка чата вниз при загрузке/новом сообщении
watch([tab, () => full.value?.comments?.length], async () => {
  if (tab.value !== 'chat') return;
  await nextTick();
  if (chatListRef.value) chatListRef.value.scrollTop = chatListRef.value.scrollHeight;
});

function onKeydown(e) { if (e.key === 'Escape') close(); }

function close() { emit('close'); }

async function patch(payload) {
  try {
    await tasksApi.updateCard(props.cardId, payload);
    Object.assign(full.value.card, payload);
    emit('updated');
    const inList = store.cards.find(c => c.id === props.cardId);
    if (inList) Object.assign(inList, payload);
  } catch (e) { alert('Ошибка: ' + e.message); }
}

async function onColumnChange(e) {
  const newCol = parseInt(e.target.value);
  if (!newCol || newCol === full.value.card.column_id) return;
  await store.moveCard(props.cardId, newCol, 0);
  await load();
}

function onDueChange(e) {
  const v = e.target.value;
  if (!v) { patch({ due_date: null }); return; }
  patch({ due_date: v.replace('T', ' ') + ':00' });
}

// ─── Чек-лист ───
async function addChecklistItem() {
  const t = newChecklistTitle.value.trim();
  if (!t) return;
  try {
    const r = await tasksApi.addChecklist(props.cardId, t);
    full.value.checklist.push({ id: r.id, title: t, is_done: 0 });
    newChecklistTitle.value = '';
    refreshCardSummary();
  } catch (e) { alert(e.message); }
}
async function toggleChecklist(item) {
  const newVal = item.is_done ? 0 : 1;
  try {
    await tasksApi.updateChecklistItem(item.id, { is_done: newVal });
    item.is_done = newVal;
    refreshCardSummary();
  } catch (e) { alert(e.message); }
}
function startEditChecklist(item) {
  editingChecklistId.value = item.id;
  editingChecklistTitle.value = item.title || '';
  nextTick(() => editingInputRef.value?.focus?.());
}
async function saveEditChecklist(item) {
  const t = (editingChecklistTitle.value || '').trim();
  editingChecklistId.value = null;
  if (!t || t === item.title) { editingChecklistTitle.value = ''; return; }
  try {
    await tasksApi.updateChecklistItem(item.id, { title: t });
    item.title = t;
  } catch (e) { alert(e.message); }
  editingChecklistTitle.value = '';
}
function cancelEditChecklist() { editingChecklistId.value = null; editingChecklistTitle.value = ''; }
async function deleteChecklist(item) {
  try {
    await tasksApi.deleteChecklistItem(item.id);
    full.value.checklist = full.value.checklist.filter(i => i.id !== item.id);
    refreshCardSummary();
  } catch (e) { alert(e.message); }
}
function refreshCardSummary() {
  const inList = store.cards.find(c => c.id === props.cardId);
  if (inList) inList.checklist = { done: checklistDone.value, total: checklistTotal.value };
}

// ─── Подзадачи ───
async function addSubtask() {
  const t = newSubtaskTitle.value.trim();
  if (!t) return;
  try {
    const r = await tasksApi.createCard({
      parent_card_id: props.cardId,
      title: t,
    });
    full.value.subtasks.push({
      id: r.id, title: t, is_done: 0, priority: 'medium', due_date: null, sort_order: 999, assignees: [],
    });
    newSubtaskTitle.value = '';
  } catch (e) { alert(e.message); }
}
async function toggleSubtaskDone(st) {
  const newVal = st.is_done ? 0 : 1;
  try {
    await tasksApi.updateCard(st.id, { is_done: newVal });
    st.is_done = newVal;
  } catch (e) { alert(e.message); }
}
async function deleteSubtask(st) {
  if (!confirm('Удалить подзадачу «' + st.title + '»?')) return;
  try {
    await tasksApi.deleteCard(st.id);
    full.value.subtasks = full.value.subtasks.filter(x => x.id !== st.id);
  } catch (e) { alert(e.message); }
}
function priorityShort(p) { return ({ low: 'низ', medium: 'ср', high: 'выс', urgent: '!' })[p] || ''; }
function isSubOverdue(st) {
  if (!st.due_date || st.is_done) return false;
  return new Date(st.due_date) < new Date();
}
function formatSubDue(s) {
  const d = new Date(s);
  if (isNaN(d)) return '';
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
}
function initials(n) {
  return (n || '').split(/\s+/).filter(Boolean).map(w => w[0]).join('').slice(0, 2).toUpperCase();
}

// ─── Чат / комментарии ───
async function submitComment() {
  const t = newComment.value.trim();
  if (!t) return;
  try {
    const r = await tasksApi.addComment(props.cardId, t);
    full.value.comments.push({
      id: r.id, author_name: currentUserName.value,
      body: t, created_at: new Date().toISOString().slice(0,19).replace('T',' '),
    });
    newComment.value = '';
    const inList = store.cards.find(c => c.id === props.cardId);
    if (inList) inList.comments = (inList.comments || 0) + 1;
  } catch (e) { alert(e.message); }
}
function canEditComment(c) {
  return c.author_name === currentUserName.value || userStore.currentUser?.role === 'admin';
}
async function editComment(c) {
  const v = prompt('Изменить сообщение:', c.body);
  if (v === null || v.trim() === c.body) return;
  try { await tasksApi.updateComment(c.id, v.trim()); c.body = v.trim(); c.edited_at = new Date().toISOString(); }
  catch (e) { alert(e.message); }
}
async function removeComment(c) {
  if (!confirm('Удалить сообщение?')) return;
  try {
    await tasksApi.deleteComment(c.id);
    full.value.comments = full.value.comments.filter(x => x.id !== c.id);
    const inList = store.cards.find(c2 => c2.id === props.cardId);
    if (inList) inList.comments = Math.max(0, (inList.comments || 0) - 1);
  } catch (e) { alert(e.message); }
}

// ─── Метки ───
async function toggleLabel(l) {
  const has = full.value.label_ids.includes(l.id);
  const newIds = has ? full.value.label_ids.filter(id => id !== l.id) : [...full.value.label_ids, l.id];
  try {
    await tasksApi.setCardLabels(props.cardId, newIds);
    full.value.label_ids = newIds;
    const inList = store.cards.find(c => c.id === props.cardId);
    if (inList) inList.label_ids = newIds;
  } catch (e) { alert(e.message); }
}
async function addNewLabel() {
  const title = prompt('Название метки:');
  if (!title) return;
  const color = prompt('Цвет метки (HEX):', '#42A5F5') || '#42A5F5';
  if (!/^#[0-9a-fA-F]{6}$/.test(color)) { alert('Неверный формат цвета'); return; }
  try { await store.createLabel({ board_id: store.currentBoardId, title, color }); }
  catch (e) { alert(e.message); }
}

// ─── Соисполнители ───
async function addAssignee() {
  if (!newAssignee.value) return;
  const newList = [...full.value.assignees, newAssignee.value];
  try {
    await tasksApi.setAssignees(props.cardId, newList);
    full.value.assignees = newList;
    const inList = store.cards.find(c => c.id === props.cardId);
    if (inList) inList.assignees = newList;
    newAssignee.value = '';
  } catch (e) { alert(e.message); }
}
async function removeAssignee(name) {
  const newList = full.value.assignees.filter(n => n !== name);
  try {
    await tasksApi.setAssignees(props.cardId, newList);
    full.value.assignees = newList;
    const inList = store.cards.find(c => c.id === props.cardId);
    if (inList) inList.assignees = newList;
  } catch (e) { alert(e.message); }
}

// ─── Связи ───
function relationTypeLabel(t) {
  return ({ order: 'Заказ', supplier: 'Поставщик', product: 'Товар', pricing: 'ПСЦ', plan: 'План', so_order: 'Заявка пост.' })[t] || t;
}
async function addRelation() {
  if (!relationDraft.value.type || !relationDraft.value.id) return;
  const newList = [
    ...full.value.relations.map(r => ({ entity_type: r.entity_type, entity_id: r.entity_id, entity_label: r.entity_label })),
    { entity_type: relationDraft.value.type, entity_id: relationDraft.value.id, entity_label: relationDraft.value.label || null },
  ];
  try {
    await tasksApi.setRelations(props.cardId, newList);
    full.value = await tasksApi.loadCard(props.cardId);
    relationDraft.value = { type: '', id: '', label: '' };
    showRelationPicker.value = false;
  } catch (e) { alert(e.message); }
}
async function removeRelation(r) {
  try {
    await tasksApi.deleteRelation(r.id);
    full.value.relations = full.value.relations.filter(x => x.id !== r.id);
  } catch (e) { alert(e.message); }
}

async function askDelete() {
  if (!confirm('Удалить карточку «' + full.value.card.title + '»?')) return;
  try {
    await tasksApi.deleteCard(props.cardId);
    emit('deleted', props.cardId);
    close();
  } catch (e) { alert(e.message); }
}

function formatDate(s) {
  if (!s) return '';
  const d = new Date(s.includes('T') ? s : s.replace(' ', 'T'));
  if (isNaN(d)) return s;
  return d.toLocaleDateString('ru-RU', { day:'2-digit', month:'2-digit' }) + ' ' +
         d.toLocaleTimeString('ru-RU', { hour:'2-digit', minute:'2-digit' });
}

function historyText(h) {
  switch (h.action) {
    case 'created': return 'создал(а) карточку';
    case 'moved':   return 'переместил(а) карточку';
    case 'updated': {
      const keys = h.details ? Object.keys(h.details) : [];
      if (!keys.length) return 'изменил(а)';
      const map = { title: 'название', priority: 'приоритет', due_date: 'срок' };
      return 'изменил(а) ' + keys.map(k => map[k] || k).join(', ');
    }
    case 'comment': return 'написал(а) сообщение';
    case 'labels_changed': return 'изменил(а) метки';
    case 'assignees_changed': return 'изменил(а) соисполнителей';
    case 'relations_changed': return 'изменил(а) связи';
    default: return h.action;
  }
}
</script>

<style scoped>
/* ═══ Сайдбар справа ═══ */
.task-sidebar-backdrop {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.25);
  z-index: 998;
  animation: fadeIn .15s;
}
.task-sidebar {
  position: fixed; top: 0; right: 0; bottom: 0;
  width: 100%;
  max-width: 480px;
  background: #fff;
  z-index: 999;
  display: flex; flex-direction: column;
  box-shadow: -2px 0 16px rgba(0,0,0,0.15);
  animation: slideIn .22s cubic-bezier(.2,.8,.3,1);
}
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes slideIn { from { transform: translateX(100%); } to { transform: translateX(0); } }

.task-sidebar-loader { padding: 40px; text-align: center; color: var(--text-muted); }

/* ═══ Шапка ═══ */
.ts-header {
  display: flex; align-items: flex-start; gap: 8px;
  padding: 14px 16px 10px;
  border-bottom: 1px solid var(--border-light);
  flex-shrink: 0;
}
.ts-back {
  background: none; border: 1px solid var(--border-light);
  cursor: pointer; font-size: 16px; color: var(--text-muted);
  border-radius: 6px; padding: 4px 9px; line-height: 1;
  flex-shrink: 0;
}
.ts-back:hover { background: var(--bg-secondary, #f5f5f5); color: var(--text); }
.ts-parent-link {
  display: block; background: none; border: none;
  color: var(--text-muted); font-size: 11.5px; cursor: pointer;
  padding: 0 8px 2px; text-align: left; font-family: inherit;
}
.ts-parent-link:hover { color: var(--bk-orange, #E87A1E); text-decoration: underline; }
.ts-header-titles { flex: 1; min-width: 0; }
.ts-title-input {
  width: 100%; font-size: 17px; font-weight: 700;
  border: 1px solid transparent; border-radius: 6px;
  padding: 4px 8px; background: transparent; color: var(--text);
  font-family: inherit;
}
.ts-title-input:hover { border-color: var(--border-light); }
.ts-title-input:focus { border-color: var(--bk-orange, #E87A1E); outline: none; background: #fff; }
.ts-subtitle {
  font-size: 12px; color: var(--text-muted); padding-left: 8px; margin-top: 2px;
}
.ts-close {
  background: none; border: none; cursor: pointer;
  font-size: 18px; color: var(--text-muted); padding: 6px 10px;
  border-radius: 6px; line-height: 1;
}
.ts-close:hover { background: var(--bg-secondary, #f5f5f5); color: var(--text); }

/* ═══ Свойства ═══ */
.ts-props {
  display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px;
  padding: 8px 16px 12px;
  border-bottom: 1px solid var(--border-light);
  flex-shrink: 0;
}
.ts-prop { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.ts-prop-label {
  font-size: 10px; font-weight: 700; color: var(--text-muted);
  text-transform: uppercase; letter-spacing: .5px;
}
.ts-prop select, .ts-prop input {
  padding: 5px 8px; font-size: 12.5px;
  border: 1px solid var(--border-light); border-radius: 6px;
  background: #fff; color: var(--text); font-family: inherit;
  width: 100%;
}

/* ═══ Вкладки ═══ */
.ts-tabs {
  display: flex; gap: 2px; padding: 6px 8px 0;
  border-bottom: 1px solid var(--border-light);
  flex-shrink: 0;
}
.ts-tab {
  background: none; border: none; cursor: pointer;
  padding: 8px 14px; font-size: 13px; font-weight: 600;
  color: var(--text-muted); border-radius: 6px 6px 0 0;
  display: flex; align-items: center; gap: 6px;
  font-family: inherit;
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
}
.ts-tab:hover { color: var(--text); }
.ts-tab.active {
  color: var(--bk-orange, #E87A1E);
  border-bottom-color: var(--bk-orange, #E87A1E);
}
.ts-tab-badge {
  background: var(--bk-orange, #E87A1E); color: #fff;
  font-size: 10px; font-weight: 700;
  padding: 1px 6px; border-radius: 10px; min-width: 18px; text-align: center;
}

/* ═══ Панель содержимого ═══ */
.ts-pane {
  flex: 1; overflow-y: auto;
  padding: 14px 16px 20px;
}
.ts-section { margin-bottom: 18px; }
.ts-section-title {
  font-size: 12px; font-weight: 700; color: var(--text);
  text-transform: uppercase; letter-spacing: .4px;
  margin-bottom: 8px;
  display: flex; align-items: center; gap: 8px;
}
.ts-section-meta { font-size: 12px; font-weight: 500; color: var(--text-muted); margin-left: auto; text-transform: none; }
.ts-section-add {
  margin-left: auto; background: none; border: none; cursor: pointer;
  color: var(--bk-orange, #E87A1E); font-size: 12px; font-weight: 600;
  text-transform: none;
}
.ts-empty { color: var(--text-muted); font-size: 12.5px; font-style: italic; padding: 4px 0; }
.ts-section-danger { margin-top: 32px; padding-top: 16px; border-top: 1px solid var(--border-light); }
.ts-section-danger .btn.danger { width: 100%; background: #EF5350; color: #fff; border-color: #EF5350; }

.ts-textarea {
  width: 100%; box-sizing: border-box;
  border: 1px solid var(--border-light); border-radius: 6px;
  padding: 8px 10px; font-size: 13.5px;
  font-family: inherit; resize: vertical;
  background: #fff; color: var(--text);
}

/* ═══ Чек-лист ═══ */
.ts-progress {
  height: 4px; background: var(--border-light); border-radius: 2px; overflow: hidden;
  margin-bottom: 8px;
}
.ts-progress-bar { height: 100%; background: #66BB6A; transition: width .2s; }

.ts-checklist { list-style: none; padding: 0; margin: 0 0 6px; }
.ts-chk-item {
  display: flex; align-items: center; gap: 10px;
  padding: 6px 6px;
  border-radius: 6px;
  border-bottom: 1px solid var(--border-light);
  min-height: 32px;
}
.ts-chk-item:last-child { border-bottom: none; }
.ts-chk-item:hover { background: var(--bg-secondary, #fafafa); }

/* ═══ Квадратный чекбокс — для чек-листа ═══ */
.ts-chk-box {
  appearance: none; -webkit-appearance: none;
  width: 18px; height: 18px;
  border: 2px solid #B0BEC5; border-radius: 4px;
  background: #fff;
  cursor: pointer;
  flex-shrink: 0; margin: 0;
  display: inline-flex; align-items: center; justify-content: center;
  transition: all .15s;
}
.ts-chk-box:hover { border-color: #4FC3F7; }
.ts-chk-box:checked {
  background: #4FC3F7; border-color: #4FC3F7;
}
.ts-chk-box:checked::after {
  content: ''; display: block;
  width: 8px; height: 4px;
  border-left: 2px solid #fff; border-bottom: 2px solid #fff;
  transform: rotate(-45deg) translate(1px, -1px);
}

/* ═══ Круглый чекбокс — для подзадач ═══ */
.ts-round-chk {
  appearance: none; -webkit-appearance: none;
  width: 20px; height: 20px;
  border: 2px solid #B0BEC5; border-radius: 50%;
  background: #fff;
  cursor: pointer;
  flex-shrink: 0; margin: 0;
  display: inline-flex; align-items: center; justify-content: center;
  transition: all .15s;
}
.ts-round-chk:hover { border-color: #66BB6A; }
.ts-round-chk:checked {
  background: #66BB6A; border-color: #66BB6A;
}
.ts-round-chk:checked::after {
  content: ''; display: block;
  width: 9px; height: 5px;
  border-left: 2px solid #fff; border-bottom: 2px solid #fff;
  transform: rotate(-45deg) translate(1px, -1px);
}
.ts-chk-text {
  flex: 1 1 auto; min-width: 0;
  font-size: 13.5px; color: var(--text);
  line-height: 1.4;
  cursor: text;
  word-break: break-word;
  padding: 2px 4px;
  border-radius: 3px;
}
.ts-chk-text:hover { background: rgba(0,0,0,0.04); }
.ts-chk-text.done { color: var(--text-muted); text-decoration: line-through; }
.ts-chk-input {
  flex: 1 1 auto; min-width: 0;
  padding: 3px 6px; font-size: 13.5px;
  border: 1px solid var(--bk-orange, #E87A1E);
  border-radius: 4px; background: #fff; color: var(--text);
  font-family: inherit;
}
.ts-icon-btn {
  flex-shrink: 0;
  background: none; border: none; cursor: pointer;
  color: var(--text-muted); font-size: 12px; padding: 3px 7px; border-radius: 3px;
}
.ts-icon-btn:hover { color: #E53935; background: rgba(229,57,53,0.1); }

.ts-chk-add { display: flex; gap: 6px; margin-top: 8px; }
.ts-chk-add input {
  flex: 1; padding: 6px 8px; font-size: 13px;
  border: 1px solid var(--border-light); border-radius: 6px;
  background: #fff; color: var(--text); font-family: inherit;
}
.ts-btn-sm { padding: 4px 12px; font-size: 13px; }

/* ═══ Подзадачи ═══ */
.ts-subtasks { list-style: none; padding: 0; margin: 0 0 6px; }
.ts-sub-item {
  display: flex; align-items: center; gap: 8px;
  padding: 6px 6px;
  border-bottom: 1px solid var(--border-light);
  min-height: 32px;
}
.ts-sub-item:last-child { border-bottom: none; }
.ts-sub-item:hover { background: var(--bg-secondary, #fafafa); }

.ts-sub-text {
  flex: 1 1 auto; min-width: 0;
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
  background: none; border: none; cursor: pointer;
  text-align: left; padding: 2px 4px; border-radius: 4px;
  color: var(--text); font-family: inherit; font-size: 13.5px;
}
.ts-sub-text:hover { background: rgba(66,165,245,0.08); color: #1976D2; }
.ts-sub-text.done .ts-sub-title { text-decoration: line-through; color: var(--text-muted); }
.ts-sub-title { word-break: break-word; min-width: 0; }
.ts-sub-meta {
  display: inline-flex; align-items: center; gap: 4px;
  flex-shrink: 0;
}
.ts-sub-prio {
  font-size: 10px; font-weight: 700; padding: 1px 5px; border-radius: 8px;
  text-transform: lowercase;
}
.ts-sub-prio.prio-bg-low    { background: #ECEFF1; color: #455A64; }
.ts-sub-prio.prio-bg-high   { background: #FFF3E0; color: #E65100; }
.ts-sub-prio.prio-bg-urgent { background: #FFEBEE; color: #C62828; }
.ts-sub-due {
  font-size: 10.5px; font-weight: 600;
  padding: 1px 6px; border-radius: 8px;
  background: #E8F5E9; color: #2E7D32;
}
.ts-sub-due.overdue { background: #FFEBEE; color: #C62828; }
.ts-sub-assignees { display: inline-flex; gap: 0; }
.ts-sub-bubble {
  display: inline-flex; align-items: center; justify-content: center;
  width: 18px; height: 18px; border-radius: 50%;
  background: linear-gradient(135deg, #E76F51, #F4A261);
  color: #fff; font-size: 8px; font-weight: 700;
  border: 1.5px solid #fff;
  margin-left: -4px;
}
.ts-sub-bubble:first-child { margin-left: 0; }

/* ═══ Метки ═══ */
.ts-labels { display: flex; flex-wrap: wrap; gap: 5px; }
.ts-label {
  padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600;
  cursor: pointer; border: 1.5px solid; background: transparent;
  font-family: inherit;
}
.ts-label.add-label {
  border-color: var(--text-muted); color: var(--text-muted);
  border-style: dashed;
}

/* ═══ Соисполнители ═══ */
.ts-assignees { display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 6px; }
.ts-chip {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 10px; background: #E3F2FD; color: #0D47A1;
  border-radius: 14px; font-size: 12.5px; font-weight: 600;
}
.ts-assignee-add {
  width: 100%; padding: 5px 8px; font-size: 13px;
  border: 1px solid var(--border-light); border-radius: 6px;
  background: #fff; color: var(--text); font-family: inherit;
}

/* ═══ Связи ═══ */
.ts-relations { display: flex; flex-direction: column; gap: 4px; }
.ts-relation {
  display: flex; align-items: center; gap: 8px;
  padding: 5px 10px; border-radius: 6px; background: var(--bg-secondary, #f5f5f5);
  font-size: 12.5px;
}
.ts-relation-type { font-weight: 700; color: var(--bk-orange, #E87A1E); min-width: 90px; }
.ts-relation-label { flex: 1; color: var(--text); word-break: break-word; }
.ts-relation-picker {
  display: grid; grid-template-columns: 1fr 1fr auto; gap: 6px;
  margin-top: 8px; padding: 8px; background: #fff;
  border: 1px dashed var(--border-light); border-radius: 6px;
}
.ts-relation-picker > select { grid-column: 1 / -1; }
.ts-relation-picker input, .ts-relation-picker select {
  padding: 5px 8px; font-size: 12.5px;
  border: 1px solid var(--border-light); border-radius: 4px;
  background: #fff; color: var(--text); font-family: inherit;
}

/* ═══ ЧАТ ═══ */
.ts-chat-pane { display: flex; flex-direction: column; padding: 0; }
.ts-chat-list {
  flex: 1; overflow-y: auto;
  padding: 14px 16px;
  display: flex; flex-direction: column; gap: 8px;
  background: #FAFBFC;
}
.ts-chat-empty { text-align: center; padding: 32px 16px; }
.ts-chat-msg {
  display: flex;
  max-width: 85%;
}
.ts-chat-msg.own { align-self: flex-end; }
.ts-chat-bubble {
  background: #fff;
  border: 1px solid var(--border-light);
  border-radius: 12px;
  padding: 7px 10px;
  position: relative;
  box-shadow: 0 1px 1px rgba(0,0,0,0.03);
}
.ts-chat-msg.own .ts-chat-bubble {
  background: linear-gradient(135deg, #FFE0B2, #FFCC80);
  border-color: #FFB74D;
}
.ts-chat-meta {
  display: flex; gap: 8px; align-items: baseline;
  font-size: 11px; margin-bottom: 3px;
}
.ts-chat-author { font-weight: 700; color: var(--text); }
.ts-chat-date { color: var(--text-muted); }
.ts-chat-body {
  font-size: 13.5px; color: var(--text); line-height: 1.4;
  white-space: pre-wrap; word-break: break-word;
}
.ts-chat-actions {
  display: flex; gap: 2px; margin-top: 4px;
  opacity: 0; transition: opacity .15s;
  justify-content: flex-end;
}
.ts-chat-bubble:hover .ts-chat-actions { opacity: 1; }
.ts-chat-action {
  background: none; border: none; cursor: pointer;
  color: var(--text-muted); font-size: 11px; padding: 2px 6px; border-radius: 3px;
}
.ts-chat-action:hover { background: rgba(0,0,0,0.06); color: var(--text); }

.ts-chat-input {
  display: flex; gap: 8px; align-items: flex-end;
  padding: 10px 14px;
  border-top: 1px solid var(--border-light);
  background: #fff;
  flex-shrink: 0;
}
.ts-chat-input textarea {
  flex: 1;
  border: 1px solid var(--border-light); border-radius: 8px;
  padding: 8px 10px; font-size: 13.5px;
  font-family: inherit; resize: none;
  min-height: 38px; max-height: 140px;
  background: #fff; color: var(--text);
}
.ts-chat-input .btn { padding: 7px 14px; font-size: 13px; flex-shrink: 0; }

/* ═══ История ═══ */
.ts-history { display: flex; flex-direction: column; gap: 6px; }
.ts-history-item {
  padding: 8px 10px;
  background: var(--bg-secondary, #f7f7f8);
  border-radius: 6px;
  font-size: 12.5px;
}
.ts-history-row { display: flex; gap: 6px; }
.ts-history-author { font-weight: 700; color: var(--text); }
.ts-history-action { color: var(--text); }
.ts-history-date { color: var(--text-muted); font-size: 11px; margin-top: 2px; }

/* ═══ Адаптив ═══ */
@media (max-width: 540px) {
  .task-sidebar { max-width: 100%; }
  .ts-props { grid-template-columns: 1fr; }
}
</style>
