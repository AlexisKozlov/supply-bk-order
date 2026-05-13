<template>
  <Teleport to="body">
    <div class="task-sidebar-backdrop" @click="close"></div>
    <aside class="task-sidebar" @click.stop :class="{ 'task-sidebar-enter': true }">
      <div v-if="loading" class="task-sidebar-loader">Загрузка…</div>
      <template v-else-if="full">
        <!-- Шапка -->
        <header class="ts-header">
          <button v-if="canGoBack" class="ts-back" @click="$emit('go-back')" title="Назад к родителю">
            <TaskIcon name="chevronRight" :size="16" style="transform: rotate(180deg);"/>
          </button>
          <div class="ts-header-titles">
            <button v-if="full.parent" class="ts-parent-link" @click="$emit('go-back')">
              <TaskIcon name="chevronRight" :size="11" style="transform: rotate(180deg);"/>
              <span>{{ full.parent.title }}</span>
            </button>
            <input v-model="full.card.title" class="ts-title-input"
                   @blur="patch({ title: full.card.title })" @keydown.enter.prevent="$event.target.blur()" />
            <div class="ts-subtitle">
              <template v-if="full.parent">подзадача в «{{ full.parent.title }}»</template>
              <template v-else>в колонке «{{ columnTitle }}»</template>
            </div>
          </div>
          <button class="ts-close" @click="close" title="Закрыть (Esc)">
            <TaskIcon name="close" :size="16"/>
          </button>
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

        <!-- Вкладки (Yougile-стиль: тематически разделено) -->
        <div class="ts-tabs">
          <button class="ts-tab" :class="{ active: tab === 'main' }" @click="tab = 'main'">Описание</button>
          <button v-if="!full.parent" class="ts-tab" :class="{ active: tab === 'subs' }" @click="tab = 'subs'">
            Подзадачи
            <span v-if="subtasksTotal || checklistTotal" class="ts-tab-badge">
              {{ (subtasksDone + checklistDone) }}/{{ (subtasksTotal + checklistTotal) }}
            </span>
          </button>
          <button class="ts-tab" :class="{ active: tab === 'chat' }" @click="tab = 'chat'">
            Чат <span v-if="full.comments?.length" class="ts-tab-badge">{{ full.comments.length }}</span>
          </button>
          <button class="ts-tab" :class="{ active: tab === 'history' }" @click="tab = 'history'">История</button>
        </div>

        <!-- ВКЛАДКА «ОПИСАНИЕ» — только описание, метки, файлы, связи -->
        <div v-if="tab === 'main'" class="ts-pane">

          <!-- Описание -->
          <section class="ts-section">
            <div class="ts-section-title">Описание</div>
            <MarkdownEditor v-model="full.card.description"
                            placeholder="Добавить описание…"
                            @blur="onDescBlur"/>
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

          <!-- Файлы / вложения -->
          <section class="ts-section">
            <div class="ts-section-title">
              Файлы
              <span v-if="full.attachments?.length" class="ts-section-meta">{{ full.attachments.length }}</span>
            </div>
            <div class="ts-att-drop"
                 :class="{ 'is-drag': isDragOver, 'is-upload': uploadingCount > 0 }"
                 @dragover.prevent="onDragOver"
                 @dragleave.prevent="onDragLeave"
                 @drop.prevent="onDrop"
                 @click="pickFiles">
              <input ref="fileInputRef" type="file" multiple class="ts-att-input" @change="onFilePick"/>
              <TaskIcon name="archive" :size="20"/>
              <span class="ts-att-drop-text">
                <template v-if="uploadingCount > 0">Загрузка: {{ uploadingDone }}/{{ uploadingCount + uploadingDone }}…</template>
                <template v-else>Перетащите файлы сюда или нажмите для выбора (до 25 МБ)</template>
              </span>
            </div>
            <ul v-if="full.attachments?.length" class="ts-att-list">
              <li v-for="a in full.attachments" :key="a.id" class="ts-att-item">
                <div class="ts-att-thumb" :class="'ts-att-thumb-' + attTypeOf(a)">
                  <img v-if="attIsImage(a)" :src="tasksApi.attachmentUrl(a.file_path)" :alt="a.file_name"/>
                  <span v-else>{{ attIconOf(a) }}</span>
                </div>
                <div class="ts-att-info">
                  <a class="ts-att-name" :href="tasksApi.attachmentUrl(a.file_path)" target="_blank" rel="noopener noreferrer">{{ a.file_name }}</a>
                  <div class="ts-att-meta">
                    {{ attFormatSize(a.file_size) }} · {{ a.uploaded_by }} · {{ formatDate(a.uploaded_at) }}
                  </div>
                </div>
                <div class="ts-att-actions">
                  <a class="ts-icon-btn" :href="tasksApi.attachmentUrl(a.file_path, { download: true })"
                     :download="a.file_name" title="Скачать">
                    <TaskIcon name="download" :size="14"/>
                  </a>
                  <button v-if="canDeleteAttachment(a)" class="ts-icon-btn" @click="deleteAttachment(a)" title="Удалить">
                    <TaskIcon name="trash" :size="14"/>
                  </button>
                </div>
              </li>
            </ul>
          </section>

          <!-- Соисполнители -->
          <section class="ts-section">
            <div class="ts-section-title">Соисполнители</div>
            <div class="ts-assignees">
              <span v-for="n in full.assignees" :key="n" class="ts-chip"
                    :class="{ 'ts-chip-done': (full.assignees_done || []).includes(n) }"
                    :title="(full.assignees_done || []).includes(n) ? 'Выполнил свою часть' : ''">
                <span class="ts-chip-bubble">{{ initials(n) }}</span>
                <span class="ts-chip-name">{{ n }}</span>
                <span v-if="(full.assignees_done || []).includes(n)" class="ts-chip-done-tick" aria-hidden="true">✓</span>
                <button v-if="canEditStructure" class="ts-icon-btn" @click="removeAssignee(n)">
                  <TaskIcon name="close" :size="12"/>
                </button>
              </span>
              <span v-if="!full.assignees.length && !(full.protocol_co_assignees?.length)" class="ts-empty">Никого</span>
            </div>
            <select v-if="canEditStructure" v-model="newAssignee" @change="addAssignee" class="ts-assignee-add">
              <option value="">+ Добавить…</option>
              <option v-for="u in availableUsers" :key="u.name" :value="u.name">{{ u.name }}</option>
            </select>

            <!-- Соисполнители из протокола (read-only) — у каждого своя копия задачи на своей доске. -->
            <div v-if="full.protocol_co_assignees?.length" class="ts-protocol-coass">
              <div class="ts-protocol-coass-title">Также ответственны по протоколу:</div>
              <div class="ts-assignees">
                <span v-for="n in full.protocol_co_assignees" :key="'pco-' + n" class="ts-chip ts-chip-ghost"
                      :title="'У ' + n + ' своя копия задачи на их доске'">
                  <span class="ts-chip-bubble">{{ initials(n) }}</span>
                  <span class="ts-chip-name">{{ n }}</span>
                </span>
              </div>
            </div>
          </section>

          <!-- Связи -->
          <section class="ts-section">
            <div class="ts-section-title">Связано с
              <button class="ts-section-add" @click="showRelationPicker = !showRelationPicker">
                <TaskIcon name="plus" :size="12"/> Добавить
              </button>
            </div>
            <div v-if="full.relations.length" class="ts-relations">
              <div v-for="r in full.relations" :key="r.id" class="ts-relation">
                <span class="ts-relation-type">{{ relationTypeLabel(r.entity_type) }}</span>
                <router-link v-if="r.entity_type === 'protocol'" :to="'/protocols/' + r.entity_id" class="ts-relation-label ts-relation-link">
                  {{ r.entity_label || ('Протокол №' + r.entity_id) }}
                </router-link>
                <span v-else class="ts-relation-label">{{ r.entity_label || r.entity_id }}</span>
                <button class="ts-icon-btn" @click="removeRelation(r)" title="Убрать">
                  <TaskIcon name="close" :size="12"/>
                </button>
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
                <option value="protocol">Протокол</option>
              </select>
              <input v-model="relationDraft.id" type="text" placeholder="ID или код" />
              <input v-model="relationDraft.label" type="text" placeholder="Подпись (необяз.)" />
              <button class="btn primary ts-btn-sm" @click="addRelation" :disabled="!relationDraft.type || !relationDraft.id">Добавить</button>
            </div>
          </section>

          <!-- Удаление -->
          <section v-if="canDelete" class="ts-section ts-section-danger">
            <button class="btn danger ts-delete-btn" @click="askDelete">
              <TaskIcon name="trash" :size="14"/>
              <span>Удалить карточку</span>
            </button>
          </section>
        </div>

        <!-- ВКЛАДКА «ПОДЗАДАЧИ» — подзадачи + чек-лист. Только у корневых карточек. -->
        <div v-if="tab === 'subs' && !full.parent" class="ts-pane">

          <!-- Подзадачи -->
          <section class="ts-section">
            <div class="ts-section-title">
              Подзадачи
              <span v-if="subtasksTotal" class="ts-section-meta">{{ subtasksDone }}/{{ subtasksTotal }}</span>
            </div>
            <div v-if="subtasksTotal" class="ts-progress">
              <div class="ts-progress-bar" :style="{ width: subtasksPct + '%', background: '#635BFF' }"></div>
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
                <button class="ts-icon-btn" @click="deleteSubtask(st)" title="Удалить">
                  <TaskIcon name="close" :size="14"/>
                </button>
              </li>
            </ul>
            <div class="ts-chk-add">
              <input v-model="newSubtaskTitle" type="text" placeholder="Новая подзадача…"
                     @keydown.enter="addSubtask" />
              <button class="btn primary ts-btn-sm" @click="addSubtask" :disabled="!newSubtaskTitle.trim()">
                <TaskIcon name="plus" :size="14"/>
              </button>
            </div>
          </section>

          <!-- Чек-листы (несколько групп на карточку) -->
          <section v-for="g in full.checklists" :key="'g' + g.id" class="ts-section ts-chk-group">
            <div class="ts-section-title">
              <input v-if="editingGroupId === g.id" ref="groupTitleInputRef"
                     v-model="editingGroupTitle"
                     class="ts-chk-group-title-input"
                     @blur="saveGroupTitle(g)"
                     @keydown.enter.prevent="saveGroupTitle(g)"
                     @keydown.esc="cancelEditGroup" />
              <span v-else class="ts-chk-group-title"
                    @click="startEditGroup(g)" title="Переименовать">{{ g.title || 'Чек-лист' }}</span>
              <span v-if="groupItemsTotal(g)" class="ts-section-meta">{{ groupItemsDone(g) }}/{{ groupItemsTotal(g) }}</span>
              <button class="ts-icon-btn ts-chk-group-del" @click="deleteGroup(g)" title="Удалить чек-лист">
                <TaskIcon name="trash" :size="13"/>
              </button>
            </div>
            <div v-if="groupItemsTotal(g)" class="ts-progress">
              <div class="ts-progress-bar" :style="{ width: groupItemsPct(g) + '%' }"></div>
            </div>
            <ul class="ts-checklist">
              <li v-for="item in (g.items || [])" :key="item.id" class="ts-chk-item">
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
                <button class="ts-icon-btn" @click="deleteChecklist(item)" title="Удалить">
                  <TaskIcon name="close" :size="14"/>
                </button>
              </li>
            </ul>
            <div class="ts-chk-add">
              <input v-model="newItemByGroup[g.id]" type="text" placeholder="Новый пункт…"
                     @keydown.enter="addItemToGroup(g)" />
              <button class="btn primary ts-btn-sm" @click="addItemToGroup(g)" :disabled="!(newItemByGroup[g.id] || '').trim()">
                <TaskIcon name="plus" :size="14"/>
              </button>
            </div>
          </section>

          <!-- Кнопка добавить новый чек-лист -->
          <button class="ts-add-checklist-btn" @click="createChecklistGroup">
            <TaskIcon name="plus" :size="14"/>
            <span>Новый чек-лист</span>
          </button>
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
                <div class="ts-chat-body ts-md-view" v-html="renderMarkdown(c.body)"></div>
                <div v-if="canEditComment(c)" class="ts-chat-actions">
                  <button class="ts-chat-action" @click="editComment(c)" title="Редактировать">
                    <TaskIcon name="edit" :size="12"/>
                  </button>
                  <button class="ts-chat-action" @click="removeComment(c)" title="Удалить">
                    <TaskIcon name="close" :size="12"/>
                  </button>
                </div>
              </div>
            </div>
          </div>
          <div class="ts-chat-input">
            <MarkdownEditor v-model="newComment" :compact="true"
                            placeholder="Сообщение… (Enter — отправить, Shift+Enter — перенос строки)"
                            @ctrl-enter="submitComment"/>
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
import { ref, reactive, computed, onMounted, onUnmounted, nextTick, watch } from 'vue';
import { tasksApi } from '@/lib/tasksApi.js';
import { useTasksStore } from '@/stores/tasksStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { useTasksDialogs } from '@/composables/useTasksDialogs.js';
import { renderMarkdown } from '@/lib/markdown.js';
import TaskIcon from './TaskIcon.vue';
import MarkdownEditor from './MarkdownEditor.vue';
const dlg = useTasksDialogs();
const showError = (e, prefix = 'Ошибка') => dlg.info(prefix, e?.message || String(e), 'error');

const props = defineProps({
  cardId: { type: Number, required: true },
  canGoBack: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'updated', 'deleted', 'open-card', 'go-back', 'refresh']);

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
const fileInputRef = ref(null);
const isDragOver = ref(false);
const uploadingCount = ref(0);
const uploadingDone = ref(0);

function onDescBlur() {
  patch({ description: full.value?.card?.description || '' });
}
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

const checklistTotal = computed(() => {
  let n = 0;
  for (const g of (full.value?.checklists || [])) n += (g.items || []).length;
  return n || (full.value?.checklist?.length || 0);
});
const checklistDone = computed(() => {
  let n = 0;
  for (const g of (full.value?.checklists || [])) for (const i of (g.items || [])) if (i.is_done) n++;
  if (!full.value?.checklists?.length) return full.value?.checklist?.filter(i => i.is_done).length || 0;
  return n;
});
const checklistPct = computed(() => checklistTotal.value ? Math.round(checklistDone.value / checklistTotal.value * 100) : 0);

// Хелперы для отдельной группы чек-листа
function groupItemsTotal(g) { return (g?.items || []).length; }
function groupItemsDone(g)  { return (g?.items || []).filter(i => i.is_done).length; }
function groupItemsPct(g)   { const t = groupItemsTotal(g); return t ? Math.round(groupItemsDone(g) / t * 100) : 0; }

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
    showError(e, 'Ошибка загрузки');
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
  } catch (e) { showError(e); }
}

async function onColumnChange(e) {
  const newCol = parseInt(e.target.value, 10);
  if (!newCol || newCol === full.value.card.column_id) return;
  await store.moveCard(props.cardId, newCol, 0);
  await load();
}

function onDueChange(e) {
  const v = e.target.value;
  if (!v) { patch({ due_date: null }); return; }
  patch({ due_date: v.replace('T', ' ') + ':00' });
}

// ─── Чек-листы (несколько групп) ───
// Состояние ввода/редактирования на уровне групп
const newItemByGroup = reactive({});                  // { [groupId]: 'текст нового пункта' }
const editingGroupId = ref(null);
const editingGroupTitle = ref('');
const groupTitleInputRef = ref(null);

function findItemRef(itemId) {
  // Возвращает { group, item } для пункта чек-листа в текущих группах
  for (const g of (full.value?.checklists || [])) {
    const it = (g.items || []).find(i => i.id === itemId);
    if (it) return { group: g, item: it };
  }
  return null;
}

async function addItemToGroup(group) {
  const t = (newItemByGroup[group.id] || '').trim();
  if (!t) return;
  try {
    const r = await tasksApi.addChecklist(props.cardId, t, group.id);
    if (!group.items) group.items = [];
    group.items.push({ id: r.id, title: t, is_done: 0, checklist_id: group.id });
    // Поддерживаем плоский checklist для счётчика в таб-бейдже
    if (full.value.checklist) full.value.checklist.push({ id: r.id, title: t, is_done: 0, checklist_id: group.id });
    newItemByGroup[group.id] = '';
    refreshCardSummary();
  } catch (e) { showError(e); }
}
async function toggleChecklist(item) {
  const newVal = item.is_done ? 0 : 1;
  try {
    await tasksApi.updateChecklistItem(item.id, { is_done: newVal });
    item.is_done = newVal;
    // Зеркалим в плоский массив
    const flat = (full.value.checklist || []).find(i => i.id === item.id);
    if (flat) flat.is_done = newVal;
    refreshCardSummary();
  } catch (e) { showError(e); }
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
    const flat = (full.value.checklist || []).find(i => i.id === item.id);
    if (flat) flat.title = t;
  } catch (e) { showError(e); }
  editingChecklistTitle.value = '';
}
function cancelEditChecklist() { editingChecklistId.value = null; editingChecklistTitle.value = ''; }
async function deleteChecklist(item) {
  try {
    await tasksApi.deleteChecklistItem(item.id);
    const ref = findItemRef(item.id);
    if (ref) ref.group.items = (ref.group.items || []).filter(i => i.id !== item.id);
    if (full.value.checklist) full.value.checklist = full.value.checklist.filter(i => i.id !== item.id);
    refreshCardSummary();
  } catch (e) { showError(e); }
}

// ─── Группы чек-листа ───
async function createChecklistGroup() {
  try {
    const title = 'Чек-лист';
    const r = await tasksApi.addChecklistGroup(props.cardId, title);
    if (!full.value.checklists) full.value.checklists = [];
    full.value.checklists.push({ id: r.id, title: r.title || title, sort_order: r.sort_order || 0, items: [] });
    // Сразу вход в редактирование названия
    nextTick(() => startEditGroup(full.value.checklists[full.value.checklists.length - 1]));
  } catch (e) { showError(e); }
}
function startEditGroup(g) {
  editingGroupId.value = g.id;
  editingGroupTitle.value = g.title || '';
  nextTick(() => {
    const ref = groupTitleInputRef.value;
    const el = Array.isArray(ref) ? ref[ref.length - 1] : ref;
    el?.focus?.();
    el?.select?.();
  });
}
function cancelEditGroup() { editingGroupId.value = null; editingGroupTitle.value = ''; }
async function saveGroupTitle(g) {
  const t = (editingGroupTitle.value || '').trim() || 'Чек-лист';
  editingGroupId.value = null;
  if (t === g.title) { editingGroupTitle.value = ''; return; }
  try {
    await tasksApi.updateChecklistGroup(g.id, { title: t });
    g.title = t;
  } catch (e) { showError(e); }
  editingGroupTitle.value = '';
}
async function deleteGroup(g) {
  const total = groupItemsTotal(g);
  const ok = total === 0 ? true : await dlg.confirm(
    'Удалить чек-лист',
    `В этом чек-листе ${total} пункт(ов). Удалить вместе со всеми пунктами?`,
    { okText: 'Удалить', danger: true }
  );
  if (!ok) return;
  try {
    await tasksApi.deleteChecklistGroup(g.id);
    full.value.checklists = (full.value.checklists || []).filter(x => x.id !== g.id);
    // Чистим плоский checklist от пунктов этой группы
    if (full.value.checklist) full.value.checklist = full.value.checklist.filter(i => i.checklist_id !== g.id);
    refreshCardSummary();
  } catch (e) { showError(e); }
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
  } catch (e) { showError(e); }
}
async function toggleSubtaskDone(st) {
  const newVal = st.is_done ? 0 : 1;
  try {
    await tasksApi.updateCard(st.id, { is_done: newVal });
    st.is_done = newVal;
  } catch (e) { showError(e); }
}
async function deleteSubtask(st) {
  const ok = await dlg.confirm('Удалить подзадачу', 'Удалить подзадачу «' + st.title + '»?',
    { okText: 'Удалить', danger: true });
  if (!ok) return;
  try {
    await tasksApi.deleteCard(st.id);
    full.value.subtasks = full.value.subtasks.filter(x => x.id !== st.id);
  } catch (e) { showError(e); }
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
  } catch (e) { showError(e); }
}
function canEditComment(c) {
  return c.author_name === currentUserName.value || userStore.currentUser?.role === 'admin';
}
async function editComment(c) {
  const v = await dlg.prompt('Изменить сообщение', { defaultValue: c.body, placeholder: 'Текст сообщения' });
  if (v === null || v === undefined || v === c.body) return;
  try { await tasksApi.updateComment(c.id, v); c.body = v; c.edited_at = new Date().toISOString(); }
  catch (e) { showError(e); }
}
async function removeComment(c) {
  const ok = await dlg.confirm('Удалить сообщение', 'Удалить это сообщение?',
    { okText: 'Удалить', danger: true });
  if (!ok) return;
  try {
    await tasksApi.deleteComment(c.id);
    full.value.comments = full.value.comments.filter(x => x.id !== c.id);
    const inList = store.cards.find(c2 => c2.id === props.cardId);
    if (inList) inList.comments = Math.max(0, (inList.comments || 0) - 1);
  } catch (e) { showError(e); }
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
  } catch (e) { showError(e); }
}
async function addNewLabel() {
  const title = await dlg.prompt('Новая метка', { placeholder: 'Название метки', okText: 'Создать' });
  if (!title) return;
  try { await store.createLabel({ board_id: store.currentBoardId, title, color: '#42A5F5' }); }
  catch (e) { showError(e); }
  dlg.info('Подсказка', 'Цвет метки можно изменить в «Метки доски» (шестерёнка → Метки).', 'info');
}

// ─── Соисполнители ───
async function addAssignee() {
  if (!newAssignee.value) return;
  const newNames = [...full.value.assignees, newAssignee.value];
  try {
    await tasksApi.setAssignees(props.cardId, newNames);
    full.value.assignees = newNames;
    const inList = store.cards.find(c => c.id === props.cardId);
    if (inList) inList.assignees = newNames;
    newAssignee.value = '';
  } catch (e) { showError(e); }
}
async function removeAssignee(name) {
  const newNames = full.value.assignees.filter(n => n !== name);
  try {
    await tasksApi.setAssignees(props.cardId, newNames);
    full.value.assignees = newNames;
    const inList = store.cards.find(c => c.id === props.cardId);
    if (inList) inList.assignees = newNames;
  } catch (e) { showError(e); }
}

// ─── Связи ───
function relationTypeLabel(t) {
  return ({ order: 'Заказ', supplier: 'Поставщик', product: 'Товар', pricing: 'ПСЦ', plan: 'План', so_order: 'Заявка пост.', protocol: 'Протокол' })[t] || t;
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
  } catch (e) { showError(e); }
}
async function removeRelation(r) {
  try {
    await tasksApi.deleteRelation(r.id);
    full.value.relations = full.value.relations.filter(x => x.id !== r.id);
  } catch (e) { showError(e); }
}

async function askDelete() {
  const ok = await dlg.confirm('Удалить карточку',
    'Удалить карточку «' + full.value.card.title + '»? Действие нельзя отменить.',
    { okText: 'Удалить', danger: true });
  if (!ok) return;
  try {
    await tasksApi.deleteCard(props.cardId);
    emit('deleted', props.cardId);
    close();
  } catch (e) { showError(e); }
}

function formatDate(s) {
  if (!s) return '';
  const d = new Date(s.includes('T') ? s : s.replace(' ', 'T'));
  if (isNaN(d)) return s;
  return d.toLocaleDateString('ru-RU', { day:'2-digit', month:'2-digit' }) + ' ' +
         d.toLocaleTimeString('ru-RU', { hour:'2-digit', minute:'2-digit' });
}

// ─── Вложения ───
const ATT_IMG = ['image/jpeg','image/png','image/webp','image/gif'];
function attIsImage(a) { return ATT_IMG.includes(a.mime_type); }
function attTypeOf(a) {
  if (!a) return 'other';
  if (ATT_IMG.includes(a.mime_type)) return 'img';
  if (a.mime_type === 'application/pdf') return 'pdf';
  if (/spreadsheet|excel/.test(a.mime_type || '')) return 'xls';
  if (/word|document/.test(a.mime_type || '')) return 'doc';
  if (/zip/.test(a.mime_type || '')) return 'zip';
  if (a.mime_type === 'text/plain' || a.mime_type === 'text/csv' || a.mime_type === 'application/csv') return 'txt';
  return 'other';
}
function attIconOf(a) {
  return ({ pdf: 'PDF', xls: 'XLS', doc: 'DOC', zip: 'ZIP', txt: 'TXT' })[attTypeOf(a)] || 'FILE';
}
function attFormatSize(n) {
  n = Number(n) || 0;
  if (n < 1024) return n + ' Б';
  if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' КБ';
  return (n / 1024 / 1024).toFixed(1) + ' МБ';
}
function canDeleteAttachment(a) {
  if (userStore.currentUser?.role === 'admin') return true;
  if (a.uploaded_by === currentUserName.value) return true;
  if (full.value?.card?.owner_name === currentUserName.value) return true;
  return false;
}

function pickFiles() { fileInputRef.value?.click(); }
function onFilePick(e) {
  const files = Array.from(e.target.files || []);
  e.target.value = '';
  uploadFiles(files);
}
function onDragOver(e) {
  if (e.dataTransfer?.types?.includes('Files')) isDragOver.value = true;
}
function onDragLeave() { isDragOver.value = false; }
function onDrop(e) {
  isDragOver.value = false;
  const files = Array.from(e.dataTransfer?.files || []);
  uploadFiles(files);
}

async function uploadFiles(files) {
  if (!files.length) return;
  const MAX = 25 * 1024 * 1024;
  const tooBig = files.filter(f => f.size > MAX);
  if (tooBig.length) {
    showError({ message: `Слишком большой файл (макс 25 МБ): ${tooBig[0].name}` });
    files = files.filter(f => f.size <= MAX);
    if (!files.length) return;
  }
  uploadingCount.value += files.length;
  for (const f of files) {
    try {
      const r = await tasksApi.uploadAttachment(props.cardId, f);
      if (full.value?.attachments) full.value.attachments.unshift({
        id: r.id, file_name: r.file_name, file_path: r.file_path,
        file_size: r.file_size, mime_type: r.mime_type,
        uploaded_by: r.uploaded_by, uploaded_at: r.uploaded_at,
      });
      const inList = store.cards.find(c => c.id === props.cardId);
      if (inList) inList.attachments = (inList.attachments || 0) + 1;
    } catch (e) {
      showError(e, `Не удалось загрузить ${f.name}`);
    } finally {
      uploadingDone.value++;
    }
  }
  uploadingCount.value -= uploadingDone.value;
  uploadingDone.value = 0;
}

async function deleteAttachment(a) {
  const ok = await dlg.confirm('Удалить файл', `Удалить «${a.file_name}»?`, { okText: 'Удалить', danger: true });
  if (!ok) return;
  try {
    await tasksApi.deleteAttachment(a.id);
    full.value.attachments = full.value.attachments.filter(x => x.id !== a.id);
    const inList = store.cards.find(c => c.id === props.cardId);
    if (inList) inList.attachments = Math.max(0, (inList.attachments || 0) - 1);
  } catch (e) { showError(e); }
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
    case 'auto_closed':   return 'карточка закрыта (все соисполнители готовы)';
    case 'auto_reopened': return 'карточка возвращена в работу';
    default: return h.action;
  }
}
</script>

<style scoped>
/* Локальные fallback'и токенов: если modal teleportится в body, токены с .tasks-view не наследуются.
   Поэтому держим минимальную копию ключевых переменных. */
.task-sidebar {
  --tk-r-sm: 4px;
  --tk-r-md: 8px;
  --tk-r-lg: 12px;
  --tk-s-1: 4px;
  --tk-s-2: 8px;
  --tk-s-3: 12px;
  --tk-s-4: 16px;
  --tk-s-5: 20px;
  --tk-s-6: 24px;
  --tk-fz-xs: 11px;
  --tk-fz-sm: 12px;
  --tk-fz-md: 13px;
  --tk-fz-lg: 14px;
  --tk-fz-xl: 16px;
  --tk-fz-h1: 18px;
  --tk-fw-medium: 500;
  --tk-fw-semibold: 600;
  --tk-fw-bold: 700;
  --tk-n-0: #FFFFFF;
  --tk-n-50: #F7F8F9;
  --tk-n-100: #F1F2F4;
  --tk-n-200: #DCDFE4;
  --tk-n-300: #B3B9C4;
  --tk-n-400: #8590A2;
  --tk-n-500: #758195;
  --tk-n-600: #626F86;
  --tk-n-700: #44546F;
  --tk-n-800: #2C3E5D;
  --tk-n-900: #172B4D;
  --tk-bg-card: #FFFFFF;
  --tk-bg-popover: #FFFFFF;
  --tk-border: #DCDFE4;
  --tk-border-soft: #E1E4E8;
  --tk-accent: #E87A1E;
  --tk-accent-hover: #D26B12;
  --tk-accent-soft: rgba(232,122,30,0.10);
  --tk-accent-soft-strong: rgba(232,122,30,0.18);
  --tk-accent-text: #B85A0E;
  --tk-prio-urgent-bg: #FFEBE6;
  --tk-prio-urgent-fg: #BF2600;
  --tk-prio-high-bg: #FFF7D6;
  --tk-prio-high-fg: #974F0C;
  --tk-prio-medium-bg: #DEEBFF;
  --tk-prio-medium-fg: #0747A6;
  --tk-prio-low-bg: #EBECF0;
  --tk-prio-low-fg: #44546F;
  --tk-success: #1F8F4E;
  --tk-success-soft: rgba(31,143,78,0.10);
  --tk-warning: #B65E03;
  --tk-warning-soft: rgba(182,94,3,0.10);
  --tk-danger: #C9372C;
  --tk-danger-soft: rgba(201,55,44,0.08);
  --tk-text: var(--tk-n-900);
  --tk-text-secondary: var(--tk-n-700);
  --tk-text-muted: var(--tk-n-500);
  --tk-transition: 120ms ease;
  --tk-focus-ring: 0 0 0 2px rgba(232,122,30,0.35);
  --tk-shadow-popover: 0 8px 24px rgba(9,30,66,0.18), 0 1px 2px rgba(9,30,66,0.10);
}

/* ═══ Сайдбар справа ═══ */
.task-sidebar-backdrop {
  position: fixed; inset: 0;
  background: rgba(9,30,66,0.42);
  z-index: 998;
  animation: fadeIn .15s;
}
.task-sidebar {
  position: fixed; top: 0; right: 0; bottom: 0;
  width: 100%;
  max-width: 540px;
  background: var(--tk-bg-card);
  z-index: 999;
  display: flex; flex-direction: column;
  box-shadow: -2px 0 16px rgba(9,30,66,0.18);
  animation: slideIn .22s cubic-bezier(.2,.8,.3,1);
  color: var(--tk-text);
}
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes slideIn { from { transform: translateX(100%); } to { transform: translateX(0); } }

.task-sidebar-loader { padding: 40px; text-align: center; color: var(--tk-text-muted); font-size: var(--tk-fz-md); }

/* ═══ Шапка ═══ */
.ts-header {
  display: flex; align-items: flex-start; gap: var(--tk-s-2);
  padding: var(--tk-s-3) var(--tk-s-4) var(--tk-s-2);
  border-bottom: 1px solid var(--tk-border-soft);
  flex-shrink: 0;
}
.ts-back, .ts-close {
  background: none; border: none;
  cursor: pointer;
  width: 32px; height: 32px;
  display: flex; align-items: center; justify-content: center;
  color: var(--tk-text-muted);
  border-radius: var(--tk-r-sm);
  flex-shrink: 0;
  transition: background var(--tk-transition), color var(--tk-transition);
}
.ts-back:hover, .ts-close:hover { background: var(--tk-n-100); color: var(--tk-text); }

.ts-parent-link {
  display: inline-flex; align-items: center; gap: var(--tk-s-1);
  background: none; border: none;
  color: var(--tk-text-muted);
  font-size: var(--tk-fz-xs);
  cursor: pointer;
  padding: 0 var(--tk-s-2) 2px;
  text-align: left; font-family: inherit;
  font-weight: var(--tk-fw-medium);
}
.ts-parent-link:hover { color: var(--tk-accent-text); text-decoration: underline; }

.ts-header-titles { flex: 1; min-width: 0; }
.ts-title-input {
  width: 100%;
  font-size: var(--tk-fz-h1);
  font-weight: var(--tk-fw-bold);
  border: 1px solid transparent;
  border-radius: var(--tk-r-sm);
  padding: var(--tk-s-1) var(--tk-s-2);
  background: transparent;
  color: var(--tk-text);
  font-family: inherit;
  letter-spacing: -0.2px;
  transition: border-color var(--tk-transition), background var(--tk-transition), box-shadow var(--tk-transition);
}
.ts-title-input:hover { border-color: var(--tk-border); }
.ts-title-input:focus { border-color: var(--tk-accent); outline: none; background: var(--tk-n-0); box-shadow: var(--tk-focus-ring); }

.ts-subtitle {
  font-size: var(--tk-fz-sm);
  color: var(--tk-text-muted);
  padding-left: var(--tk-s-2);
  margin-top: 2px;
}

/* ═══ Свойства ═══ */
.ts-props {
  display: grid; grid-template-columns: 1fr 1fr 1fr; gap: var(--tk-s-2);
  padding: var(--tk-s-2) var(--tk-s-4) var(--tk-s-3);
  border-bottom: 1px solid var(--tk-border-soft);
  flex-shrink: 0;
}
.ts-prop { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.ts-prop-label {
  font-size: var(--tk-fz-xs);
  font-weight: var(--tk-fw-bold);
  color: var(--tk-text-muted);
  text-transform: uppercase;
  letter-spacing: .5px;
}
.ts-prop select, .ts-prop input {
  padding: 6px var(--tk-s-2);
  font-size: var(--tk-fz-sm);
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-sm);
  background: var(--tk-n-0);
  color: var(--tk-text);
  font-family: inherit;
  width: 100%;
  transition: border-color var(--tk-transition), box-shadow var(--tk-transition);
}
.ts-prop select:hover, .ts-prop input:hover { border-color: var(--tk-n-300); }
.ts-prop select:focus, .ts-prop input:focus { outline: none; border-color: var(--tk-accent); box-shadow: var(--tk-focus-ring); }

/* ═══ Вкладки ═══ */
.ts-tabs {
  display: flex; gap: 2px; padding: var(--tk-s-1) var(--tk-s-2) 0;
  border-bottom: 1px solid var(--tk-border-soft);
  flex-shrink: 0;
}
.ts-tab {
  background: none; border: none; cursor: pointer;
  padding: var(--tk-s-2) var(--tk-s-3);
  font-size: var(--tk-fz-md);
  font-weight: var(--tk-fw-semibold);
  color: var(--tk-text-muted);
  border-radius: var(--tk-r-sm) var(--tk-r-sm) 0 0;
  display: flex; align-items: center; gap: 6px;
  font-family: inherit;
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
  transition: color var(--tk-transition), background var(--tk-transition), border-color var(--tk-transition);
}
.ts-tab:hover { color: var(--tk-text); background: var(--tk-n-100); }
.ts-tab.active {
  color: var(--tk-accent-text);
  border-bottom-color: var(--tk-accent);
  background: transparent;
}
.ts-tab-badge {
  background: var(--tk-accent);
  color: #fff;
  font-size: 10px;
  font-weight: var(--tk-fw-bold);
  padding: 1px 6px;
  border-radius: 10px;
  min-width: 18px; text-align: center;
}

/* ═══ Панель содержимого ═══ */
.ts-pane {
  flex: 1; overflow-y: auto;
  padding: var(--tk-s-4) var(--tk-s-4) var(--tk-s-5);
}
.ts-section { margin-bottom: var(--tk-s-5); }
.ts-section-title {
  font-size: var(--tk-fz-xs);
  font-weight: var(--tk-fw-bold);
  color: var(--tk-text);
  text-transform: uppercase;
  letter-spacing: .4px;
  margin-bottom: var(--tk-s-2);
  display: flex; align-items: center; gap: var(--tk-s-2);
}
.ts-section-meta {
  font-size: var(--tk-fz-sm);
  font-weight: var(--tk-fw-medium);
  color: var(--tk-text-muted);
  margin-left: auto;
  text-transform: none;
}
.ts-section-add {
  margin-left: auto;
  display: inline-flex; align-items: center; gap: 4px;
  background: none; border: none; cursor: pointer;
  color: var(--tk-accent-text);
  font-size: var(--tk-fz-sm);
  font-weight: var(--tk-fw-semibold);
  text-transform: none;
  padding: 2px var(--tk-s-2);
  border-radius: var(--tk-r-sm);
  font-family: inherit;
  transition: background var(--tk-transition);
}
.ts-section-add:hover { background: var(--tk-accent-soft); }

.ts-empty {
  color: var(--tk-text-muted);
  font-size: var(--tk-fz-sm);
  font-style: italic;
  padding: var(--tk-s-1) 0;
}
.ts-section-danger {
  margin-top: var(--tk-s-6);
  padding-top: var(--tk-s-4);
  border-top: 1px solid var(--tk-border-soft);
}
.ts-delete-btn {
  width: 100%;
  display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  background: var(--tk-danger);
  color: #fff;
  border: 1px solid var(--tk-danger);
  border-radius: var(--tk-r-sm);
  padding: var(--tk-s-2) var(--tk-s-3);
  font-size: var(--tk-fz-md);
  font-weight: var(--tk-fw-semibold);
  cursor: pointer;
  font-family: inherit;
  transition: background var(--tk-transition);
}
.ts-delete-btn:hover { background: #B22A1F; }

.ts-textarea {
  width: 100%; box-sizing: border-box;
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-sm);
  padding: var(--tk-s-2) var(--tk-s-3);
  font-size: var(--tk-fz-md);
  font-family: inherit; resize: vertical;
  background: var(--tk-n-0);
  color: var(--tk-text);
  transition: border-color var(--tk-transition), box-shadow var(--tk-transition);
}
.ts-textarea:hover { border-color: var(--tk-n-300); }
.ts-textarea:focus { outline: none; border-color: var(--tk-accent); box-shadow: var(--tk-focus-ring); }

/* Markdown — кнопка-действие в заголовке секции */
.ts-section-action {
  margin-left: auto;
  background: none; border: none; cursor: pointer;
  font-size: var(--tk-fz-xs); font-weight: var(--tk-fw-semibold);
  color: var(--tk-text-muted);
  text-transform: none; letter-spacing: 0;
  padding: 2px 6px; border-radius: var(--tk-r-sm);
  display: inline-flex; align-items: center; gap: 4px;
  font-family: inherit;
}
.ts-section-action:hover { background: var(--tk-n-100); color: var(--tk-text); }

/* Markdown — отображение body комментариев чата */
.ts-md-view {
  font-size: var(--tk-fz-md, 13px);
  color: var(--tk-text);
  line-height: 1.55;
  word-wrap: break-word;
  overflow-wrap: anywhere;
}
.ts-md-view p { margin: 0 0 6px; }
.ts-md-view p:last-child { margin-bottom: 0; }
.ts-md-view h3 { margin: 6px 0 4px; font-size: 15px; font-weight: 700; }
.ts-md-view h4 { margin: 6px 0 4px; font-size: 14px; font-weight: 700; }
.ts-md-view ul, .ts-md-view ol { margin: 0 0 6px; padding-left: 22px; }
.ts-md-view li { margin: 1px 0; }
.ts-md-view a { color: var(--tk-accent-text, #B85A0E); text-decoration: underline; }
.ts-md-view a:hover { color: var(--tk-accent, #E87A1E); }
.ts-md-view code {
  background: var(--tk-n-100, #F1F2F4);
  padding: 1px 5px;
  border-radius: 3px;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 0.92em;
}
.ts-md-view strong { font-weight: 700; }
.ts-md-view em { font-style: italic; }


/* ═══ Чек-лист ═══ */
.ts-progress {
  height: 6px;
  background: var(--tk-n-200);
  border-radius: 3px;
  overflow: hidden;
  margin-bottom: var(--tk-s-2);
}
.ts-progress-bar {
  height: 100%;
  background: var(--tk-success);
  transition: width .25s;
}

.ts-checklist { list-style: none; padding: 0; margin: 0 0 var(--tk-s-1); }
.ts-chk-item {
  display: flex; align-items: center; gap: var(--tk-s-3);
  padding: 6px var(--tk-s-1);
  border-radius: var(--tk-r-sm);
  border-bottom: 1px solid var(--tk-border-soft);
  min-height: 32px;
}
.ts-chk-item:last-child { border-bottom: none; }
.ts-chk-item:hover { background: var(--tk-n-50); }

/* ═══ Квадратный чекбокс — для чек-листа ═══ */
.ts-chk-box {
  appearance: none; -webkit-appearance: none;
  width: 18px; height: 18px;
  border: 2px solid var(--tk-n-300);
  border-radius: var(--tk-r-sm);
  background: var(--tk-n-0);
  cursor: pointer;
  flex-shrink: 0; margin: 0;
  display: inline-flex; align-items: center; justify-content: center;
  transition: all var(--tk-transition);
}
.ts-chk-box:hover { border-color: var(--tk-success); }
.ts-chk-box:checked {
  background: var(--tk-success);
  border-color: var(--tk-success);
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
  border: 2px solid var(--tk-n-300);
  border-radius: 50%;
  background: var(--tk-n-0);
  cursor: pointer;
  flex-shrink: 0; margin: 0;
  display: inline-flex; align-items: center; justify-content: center;
  transition: all var(--tk-transition);
}
.ts-round-chk:hover { border-color: var(--tk-success); }
.ts-round-chk:checked {
  background: var(--tk-success);
  border-color: var(--tk-success);
}
.ts-round-chk:checked::after {
  content: ''; display: block;
  width: 9px; height: 5px;
  border-left: 2px solid #fff; border-bottom: 2px solid #fff;
  transform: rotate(-45deg) translate(1px, -1px);
}

.ts-chk-text {
  flex: 1 1 auto; min-width: 0;
  font-size: var(--tk-fz-md);
  color: var(--tk-text);
  line-height: 1.4;
  cursor: text;
  word-break: break-word;
  padding: 2px var(--tk-s-1);
  border-radius: var(--tk-r-sm);
}
.ts-chk-text:hover { background: var(--tk-n-100); }
.ts-chk-text.done { color: var(--tk-text-muted); text-decoration: line-through; }
.ts-chk-input {
  flex: 1 1 auto; min-width: 0;
  padding: 3px 6px;
  font-size: var(--tk-fz-md);
  border: 1px solid var(--tk-accent);
  border-radius: var(--tk-r-sm);
  background: var(--tk-n-0);
  color: var(--tk-text);
  font-family: inherit;
}
.ts-chk-input:focus { outline: none; box-shadow: var(--tk-focus-ring); }

.ts-icon-btn {
  flex-shrink: 0;
  background: none; border: none; cursor: pointer;
  color: var(--tk-text-muted);
  width: 24px; height: 24px;
  display: inline-flex; align-items: center; justify-content: center;
  border-radius: var(--tk-r-sm);
  transition: background var(--tk-transition), color var(--tk-transition);
}
.ts-icon-btn:hover { color: var(--tk-danger); background: var(--tk-danger-soft); }

.ts-chk-add { display: flex; gap: var(--tk-s-2); margin-top: var(--tk-s-2); }
.ts-chk-add input {
  flex: 1;
  padding: 6px var(--tk-s-2);
  font-size: var(--tk-fz-md);
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-sm);
  background: var(--tk-n-0);
  color: var(--tk-text);
  font-family: inherit;
  transition: border-color var(--tk-transition), box-shadow var(--tk-transition);
}
.ts-chk-add input:focus { outline: none; border-color: var(--tk-accent); box-shadow: var(--tk-focus-ring); }
.ts-btn-sm {
  padding: 0 var(--tk-s-3); height: 32px;
  font-size: var(--tk-fz-md);
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 36px;
}

/* Группа чек-листа: заголовок редактируется, есть кнопка удаления группы */
.ts-chk-group { position: relative; }
.ts-chk-group-title {
  cursor: text;
  padding: 2px 4px;
  border-radius: 4px;
  transition: background var(--tk-transition);
}
.ts-chk-group-title:hover { background: var(--tk-n-100, #F3F0E8); }
.ts-chk-group-title-input {
  flex: 1; min-width: 0;
  padding: 3px 8px;
  border: 1px solid var(--tk-border);
  border-radius: 6px;
  background: var(--tk-n-0);
  color: var(--tk-text);
  font-family: inherit; font-size: inherit; font-weight: inherit;
  outline: none;
}
.ts-chk-group-title-input:focus { border-color: var(--tk-accent); box-shadow: var(--tk-focus-ring); }
.ts-chk-group-del {
  /* margin-left убрали: section-meta уже отжата вправо через margin-left:auto,
     корзина сидит сразу за счётчиком (тоже справа). */
  opacity: 0;
  transition: opacity var(--tk-transition);
}
.ts-chk-group:hover .ts-chk-group-del { opacity: 1; }

/* Кнопка «+ Новый чек-лист» */
.ts-add-checklist-btn {
  display: inline-flex; align-items: center; gap: 6px;
  margin-top: 4px;
  padding: 8px 14px;
  background: transparent;
  border: 1px dashed var(--tk-border);
  border-radius: 8px;
  color: var(--tk-text-muted);
  font-family: inherit; font-size: 13px; font-weight: 600;
  cursor: pointer;
  transition: background var(--tk-transition), border-color var(--tk-transition), color var(--tk-transition);
}
.ts-add-checklist-btn:hover {
  background: var(--tk-accent-soft);
  border-color: var(--tk-accent);
  border-style: solid;
  color: var(--tk-accent-text);
}

/* ═══ Подзадачи ═══ */
.ts-subtasks {
  list-style: none; padding: 0; margin: 0 0 var(--tk-s-2);
  display: flex; flex-direction: column; gap: 6px;
}
.ts-sub-item {
  display: flex; align-items: center; gap: var(--tk-s-2);
  padding: var(--tk-s-2) var(--tk-s-3);
  background: var(--tk-bg-card, #fff);
  border: 1px solid var(--tk-border-soft, #EEF0F4);
  border-radius: var(--tk-r-md, 10px);
  min-height: 40px;
  transition: border-color var(--tk-transition), box-shadow var(--tk-transition);
}
.ts-sub-item:hover {
  border-color: var(--tk-border, #E4E7EE);
  box-shadow: 0 2px 6px rgba(15,23,42,0.06);
}

.ts-sub-text {
  flex: 1 1 auto; min-width: 0;
  display: flex; align-items: center; justify-content: space-between; gap: var(--tk-s-2);
  background: none; border: none; cursor: pointer;
  text-align: left;
  padding: 2px 0;
  border-radius: var(--tk-r-sm);
  color: var(--tk-text);
  font-family: inherit;
  font-size: var(--tk-fz-md);
  transition: color var(--tk-transition);
}
.ts-sub-text:hover { color: var(--tk-accent-text); }
.ts-sub-text.done .ts-sub-title { text-decoration: line-through; color: var(--tk-text-muted); }
.ts-sub-title { word-break: break-word; min-width: 0; }
.ts-sub-meta {
  display: inline-flex; align-items: center; gap: var(--tk-s-1);
  flex-shrink: 0;
}
.ts-sub-prio {
  font-size: 10px;
  font-weight: var(--tk-fw-bold);
  padding: 1px 5px;
  border-radius: var(--tk-r-sm);
  text-transform: lowercase;
}
.ts-sub-prio.prio-bg-low    { background: var(--tk-prio-low-bg);    color: var(--tk-prio-low-fg); }
.ts-sub-prio.prio-bg-high   { background: var(--tk-prio-high-bg);   color: var(--tk-prio-high-fg); }
.ts-sub-prio.prio-bg-urgent { background: var(--tk-prio-urgent-bg); color: var(--tk-prio-urgent-fg); }
.ts-sub-due {
  font-size: 10.5px;
  font-weight: var(--tk-fw-semibold);
  padding: 1px 6px;
  border-radius: var(--tk-r-sm);
  background: var(--tk-success-soft);
  color: var(--tk-success);
}
.ts-sub-due.overdue { background: var(--tk-prio-urgent-bg); color: var(--tk-prio-urgent-fg); }
.ts-sub-assignees { display: inline-flex; gap: 0; }
.ts-sub-bubble {
  display: inline-flex; align-items: center; justify-content: center;
  width: 18px; height: 18px; border-radius: 50%;
  background: linear-gradient(135deg, var(--tk-accent), #F4A261);
  color: #fff; font-size: 8px;
  font-weight: var(--tk-fw-bold);
  border: 1.5px solid var(--tk-bg-card);
  margin-left: -4px;
}
.ts-sub-bubble:first-child { margin-left: 0; }

/* ═══ Вложения ═══ */
.ts-att-drop {
  display: flex; align-items: center; justify-content: center;
  gap: var(--tk-s-2);
  border: 1.5px dashed var(--tk-border);
  border-radius: var(--tk-r-sm);
  padding: var(--tk-s-3);
  color: var(--tk-text-muted);
  font-size: var(--tk-fz-sm, 12px);
  cursor: pointer;
  background: var(--tk-n-50, #F7F8F9);
  transition: background var(--tk-transition), border-color var(--tk-transition), color var(--tk-transition);
  user-select: none;
}
.ts-att-drop:hover { border-color: var(--tk-accent); color: var(--tk-text); }
.ts-att-drop.is-drag { background: var(--tk-accent-soft, #FEEFE0); border-color: var(--tk-accent); color: var(--tk-accent-text, #B85A0E); }
.ts-att-drop.is-upload { opacity: 0.7; pointer-events: none; }
.ts-att-input { display: none; }
.ts-att-drop-text { font-weight: var(--tk-fw-semibold); }

.ts-att-list { list-style: none; padding: 0; margin: var(--tk-s-2) 0 0; display: flex; flex-direction: column; gap: var(--tk-s-1); }
.ts-att-item {
  display: flex; align-items: center; gap: var(--tk-s-2);
  padding: var(--tk-s-2);
  background: var(--tk-n-0);
  border: 1px solid var(--tk-border-soft);
  border-radius: var(--tk-r-sm);
}
.ts-att-item:hover { border-color: var(--tk-border); }
.ts-att-thumb {
  width: 36px; height: 36px;
  border-radius: var(--tk-r-sm);
  flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 10px; font-weight: var(--tk-fw-bold);
  color: #fff;
  overflow: hidden;
}
.ts-att-thumb img { width: 100%; height: 100%; object-fit: cover; }
.ts-att-thumb-img { background: var(--tk-n-200); }
.ts-att-thumb-pdf { background: #D44638; }
.ts-att-thumb-xls { background: #1F8A4C; }
.ts-att-thumb-doc { background: #2B5797; }
.ts-att-thumb-zip { background: #7B4F2A; }
.ts-att-thumb-txt { background: #6B778C; }
.ts-att-thumb-other { background: #8E9AB0; }
.ts-att-info { flex: 1; min-width: 0; }
.ts-att-name {
  display: block;
  color: var(--tk-text);
  text-decoration: none;
  font-weight: var(--tk-fw-semibold);
  font-size: var(--tk-fz-md);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.ts-att-name:hover { color: var(--tk-accent-text, #B85A0E); text-decoration: underline; }
.ts-att-meta { font-size: var(--tk-fz-xs, 11px); color: var(--tk-text-muted); }
.ts-att-actions { display: flex; gap: 2px; flex-shrink: 0; }
.ts-att-actions .ts-icon-btn { color: var(--tk-text-muted); }
.ts-att-actions .ts-icon-btn:hover { color: var(--tk-text); }

/* ═══ Метки ═══ */
.ts-labels { display: flex; flex-wrap: wrap; gap: var(--tk-s-1); }
.ts-label {
  padding: 4px var(--tk-s-3);
  border-radius: var(--tk-r-sm);
  font-size: var(--tk-fz-sm);
  font-weight: var(--tk-fw-semibold);
  cursor: pointer;
  border: 1.5px solid;
  background: transparent;
  font-family: inherit;
  transition: opacity var(--tk-transition);
}
.ts-label:hover { opacity: 0.85; }
.ts-label.add-label {
  border-color: var(--tk-text-muted);
  color: var(--tk-text-muted);
  border-style: dashed;
}
.ts-label.add-label:hover {
  border-color: var(--tk-accent);
  color: var(--tk-accent-text);
  opacity: 1;
}

/* ═══ Соисполнители ═══ */
.ts-assignees { display: flex; flex-wrap: wrap; gap: var(--tk-s-1); margin-bottom: 6px; }
.ts-chip {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 3px var(--tk-s-2) 3px 3px;
  background: var(--tk-prio-medium-bg);
  color: var(--tk-prio-medium-fg);
  border-radius: 14px;
  font-size: var(--tk-fz-sm);
  font-weight: var(--tk-fw-semibold);
}
.ts-chip-bubble {
  display: inline-flex; align-items: center; justify-content: center;
  width: 22px; height: 22px; border-radius: 50%;
  background: linear-gradient(135deg, var(--tk-accent), #F4A261);
  color: #fff;
  font-size: 9px;
  font-weight: var(--tk-fw-bold);
}
.ts-chip-name { line-height: 1; }
.ts-chip .ts-icon-btn {
  width: 18px; height: 18px;
  margin-left: 2px;
}
.ts-chip .ts-icon-btn:hover { background: rgba(0,0,0,0.10); color: var(--tk-text); }
.ts-chip-done {
  background: #DDFAE9;
  border-color: #B6EAC9;
  color: #1F845A;
}
.ts-chip-done .ts-chip-bubble { background: linear-gradient(135deg, #1F845A, #4BCE97); }
.ts-chip-done-tick {
  color: #1F845A;
  font-weight: 900;
  font-size: 12px;
  margin-left: 2px;
}

/* Read-only «протокольные» соисполнители: у них своя копия задачи на своей доске */
.ts-chip-ghost {
  background: transparent;
  border: 1px dashed var(--tk-border);
  color: var(--tk-text-muted);
}
.ts-chip-ghost .ts-chip-bubble {
  background: var(--tk-n-200);
  color: var(--tk-text-secondary);
}
.ts-protocol-coass {
  margin-top: var(--tk-s-3);
  padding-top: var(--tk-s-2);
  border-top: 1px dashed var(--tk-border-soft);
}
.ts-protocol-coass-title {
  font-size: 11px; font-weight: var(--tk-fw-semibold);
  color: var(--tk-text-muted);
  margin-bottom: 6px;
  text-transform: none;
}

.ts-assignee-add {
  width: 100%;
  padding: 6px var(--tk-s-2);
  font-size: var(--tk-fz-md);
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-sm);
  background: var(--tk-n-0);
  color: var(--tk-text);
  font-family: inherit;
  margin-top: var(--tk-s-1);
  transition: border-color var(--tk-transition), box-shadow var(--tk-transition);
}
.ts-assignee-add:hover { border-color: var(--tk-n-300); }
.ts-assignee-add:focus { outline: none; border-color: var(--tk-accent); box-shadow: var(--tk-focus-ring); }

/* ═══ Связи ═══ */
.ts-relations { display: flex; flex-direction: column; gap: var(--tk-s-1); }
.ts-relation {
  display: flex; align-items: center; gap: var(--tk-s-2);
  padding: 6px var(--tk-s-3);
  border-radius: var(--tk-r-sm);
  background: var(--tk-n-50);
  border: 1px solid var(--tk-border-soft);
  font-size: var(--tk-fz-sm);
}
.ts-relation-type {
  font-weight: var(--tk-fw-bold);
  color: var(--tk-accent-text);
  min-width: 90px;
}
.ts-relation-label {
  flex: 1;
  color: var(--tk-text);
  word-break: break-word;
}
.ts-relation-picker {
  display: grid; grid-template-columns: 1fr 1fr auto; gap: 6px;
  margin-top: var(--tk-s-2);
  padding: var(--tk-s-2);
  background: var(--tk-n-0);
  border: 1px dashed var(--tk-border);
  border-radius: var(--tk-r-sm);
}
.ts-relation-picker > select { grid-column: 1 / -1; }
.ts-relation-picker input, .ts-relation-picker select {
  padding: 6px var(--tk-s-2);
  font-size: var(--tk-fz-sm);
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-sm);
  background: var(--tk-n-0);
  color: var(--tk-text);
  font-family: inherit;
}
.ts-relation-picker input:focus, .ts-relation-picker select:focus { outline: none; border-color: var(--tk-accent); box-shadow: var(--tk-focus-ring); }

/* ═══ ЧАТ ═══ */
.ts-chat-pane { display: flex; flex-direction: column; padding: 0; }
.ts-chat-list {
  flex: 1; overflow-y: auto;
  padding: var(--tk-s-4);
  display: flex; flex-direction: column; gap: var(--tk-s-2);
  background: var(--tk-n-50);
}
.ts-chat-empty { text-align: center; padding: var(--tk-s-6) var(--tk-s-4); }
.ts-chat-msg {
  display: flex;
  max-width: 88%;
}
.ts-chat-msg.own { align-self: flex-end; }
.ts-chat-bubble {
  background: var(--tk-n-0);
  border: 1px solid var(--tk-border-soft);
  border-radius: var(--tk-r-md);
  border-top-left-radius: 4px;
  padding: var(--tk-s-2) var(--tk-s-3);
  position: relative;
  box-shadow: 0 1px 1px rgba(9,30,66,0.04);
}
.ts-chat-msg.own .ts-chat-bubble {
  background: var(--tk-prio-medium-bg);
  border-color: #C8DBFF;
  border-top-left-radius: var(--tk-r-md);
  border-top-right-radius: 4px;
  color: var(--tk-prio-medium-fg);
}
.ts-chat-meta {
  display: flex; gap: var(--tk-s-2); align-items: baseline;
  font-size: var(--tk-fz-xs);
  margin-bottom: 3px;
}
.ts-chat-author { font-weight: var(--tk-fw-bold); color: inherit; }
.ts-chat-date { color: var(--tk-text-muted); }
.ts-chat-msg.own .ts-chat-author { color: var(--tk-prio-medium-fg); }
.ts-chat-msg.own .ts-chat-date { color: rgba(7,71,166,0.6); }
.ts-chat-body {
  font-size: var(--tk-fz-md);
  color: inherit;
  line-height: 1.45;
  word-break: break-word;
}
.ts-chat-body.ts-md-view a { color: var(--tk-accent-text, #B85A0E); }
.ts-chat-msg.own .ts-chat-body.ts-md-view a { color: var(--tk-prio-medium-fg); }
.ts-chat-body.ts-md-view code { background: rgba(9,30,66,0.08); }
.ts-chat-msg.own .ts-chat-body.ts-md-view code { background: rgba(7,71,166,0.12); }
.ts-chat-actions {
  display: flex; gap: 2px; margin-top: 4px;
  opacity: 0;
  transition: opacity var(--tk-transition);
  justify-content: flex-end;
}
.ts-chat-bubble:hover .ts-chat-actions { opacity: 1; }
.ts-chat-action {
  background: none; border: none; cursor: pointer;
  color: var(--tk-text-muted);
  width: 22px; height: 22px;
  display: inline-flex; align-items: center; justify-content: center;
  border-radius: var(--tk-r-sm);
  transition: background var(--tk-transition), color var(--tk-transition);
}
.ts-chat-action:hover { background: rgba(9,30,66,0.10); color: var(--tk-text); }
.ts-chat-msg.own .ts-chat-action { color: rgba(7,71,166,0.55); }
.ts-chat-msg.own .ts-chat-action:hover { background: rgba(7,71,166,0.15); color: var(--tk-prio-medium-fg); }

.ts-chat-input {
  display: flex; gap: var(--tk-s-2); align-items: flex-end;
  padding: var(--tk-s-3) var(--tk-s-4);
  border-top: 1px solid var(--tk-border-soft);
  background: var(--tk-n-0);
  flex-shrink: 0;
}
.ts-chat-input > :deep(.me) { flex: 1; max-height: 180px; overflow: hidden; }
.ts-chat-input > :deep(.me .me-content) { max-height: 120px; overflow-y: auto; }
.ts-chat-input .btn { padding: 0 var(--tk-s-3); height: 36px; font-size: var(--tk-fz-md); flex-shrink: 0; }

/* ═══ История ═══ */
.ts-history { display: flex; flex-direction: column; gap: 6px; }
.ts-history-item {
  padding: var(--tk-s-2) var(--tk-s-3);
  background: var(--tk-n-50);
  border: 1px solid var(--tk-border-soft);
  border-radius: var(--tk-r-sm);
  font-size: var(--tk-fz-sm);
}
.ts-history-row { display: flex; gap: 6px; }
.ts-history-author { font-weight: var(--tk-fw-bold); color: var(--tk-text); }
.ts-history-action { color: var(--tk-text-secondary); }
.ts-history-date {
  color: var(--tk-text-muted);
  font-size: var(--tk-fz-xs);
  margin-top: 2px;
}

/* ═══ Адаптив ═══ */
@media (max-width: 540px) {
  .task-sidebar { max-width: 100%; }
  .ts-props { grid-template-columns: 1fr; }
}

.ts-relation-link { color: var(--accent, #F4A261); text-decoration: none; }
.ts-relation-link:hover { text-decoration: underline; }
</style>
