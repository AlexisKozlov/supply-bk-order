<!--
  TaskQuickDropWidget — мини-доска в углу экрана для быстрого создания
  задач из любой сущности приложения (заказ, поставщик, ПСЦ, план).

  Как это работает:
  1. На странице сущности (например, OrderView) ставится draggable="true"
     на карточке заказа и в dataTransfer пишется
     application/x-bk-entity = JSON {type, id, label}.
  2. Пользователь начинает тащить → виджет автоматически разворачивается.
  3. Drop в одну из колонок активной доски задач → создаётся карточка
     с автопривязкой через tasks_relations + toast.

  Виджет:
  — Свёрнут: маленькая пилюля «Задачи» в правом нижнем углу с хоткеем T.
  — Развёрнут: панель ~320×420px с заголовком, селектором доски и
    списком колонок-дроп-зон.
  — Не показывается на странице /tasks (там доска уже на экране).
  — Состояние «открыт/свёрнут» в localStorage bk_tasks_widget_open.

  Контракт dragstart для родительских страниц:
    e.dataTransfer.setData('application/x-bk-entity',
                           JSON.stringify({ type: 'order', id: 123, label: 'Заказ от 14.05' }));
    e.dataTransfer.effectAllowed = 'copy';
-->

<template>
  <div v-if="!isOnTasksRoute" class="tqdw" :class="{ 'tqdw--open': open }">
    <!-- Свёрнутая кнопка-пилюля -->
    <button v-if="!open" class="tqdw__trigger" type="button"
            @click="setOpen(true)" title="Быстрая задача (T)">
      <TaskIcon name="plus" :size="14"/>
      <span>Задачи</span>
      <span class="tqdw__kbd">T</span>
    </button>

    <!-- Развёрнутая панель -->
    <section v-else class="tqdw__panel" @dragover.prevent @drop.prevent>
      <header class="tqdw__header">
        <div class="tqdw__title">Быстрая задача</div>
        <button type="button" class="tqdw__close" @click="setOpen(false)" title="Свернуть (Esc)">
          <TaskIcon name="close" :size="14"/>
        </button>
      </header>

      <div class="tqdw__hint">
        Перетащи карточку заказа или другой сущности в одну из колонок —
        создастся задача с автопривязкой.
      </div>

      <div v-if="loading" class="tqdw__loading">
        <UiSkeleton v-for="i in 3" :key="i" width="100%" :height="40"/>
      </div>

      <UiEmptyState v-else-if="!columns.length"
                    title="Нет доступной доски"
                    description="Открой /tasks и создай первую — потом вернись.">
        <template #icon><TaskIcon name="list" :size="48"/></template>
      </UiEmptyState>

      <div v-else class="tqdw__columns">
        <div v-for="col in dropTargetColumns" :key="col.id"
             class="tqdw__column"
             :class="{ 'tqdw__column--hover': hoverColId === col.id }"
             @dragenter.prevent="hoverColId = col.id"
             @dragover.prevent="hoverColId = col.id"
             @dragleave="hoverColId = null"
             @drop.prevent="onDrop($event, col)">
          <span class="tqdw__col-dot" :style="{ background: col.color || 'var(--tk-n-300)' }"></span>
          <span class="tqdw__col-title">{{ col.title }}</span>
          <span class="tqdw__col-count">{{ columnCardCount(col.id) }}</span>
        </div>
      </div>

      <div v-if="store.currentBoardId" class="tqdw__footer">
        Доска: <strong>{{ currentBoardTitle }}</strong>
      </div>
    </section>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useTasksStore } from '@/stores/tasksStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { tasksApi } from '@/lib/tasksApi.js';
import TaskIcon from './TaskIcon.vue';
import UiEmptyState from '@/components/ui/UiEmptyState.vue';
import UiSkeleton from '@/components/ui/UiSkeleton.vue';

const route = useRoute();
const store = useTasksStore();
const toast = useToastStore();

const STORAGE_KEY = 'bk_tasks_widget_open';

const open = ref(false);
const loading = ref(false);
const hoverColId = ref(null);

// Восстановление состояния «открыт/свёрнут» из localStorage.
try {
  if (localStorage.getItem(STORAGE_KEY) === '1') open.value = true;
} catch (e) { /* приватный режим — игнор */ }

const isOnTasksRoute = computed(() => route.name === 'tasks');

const currentBoardTitle = computed(() => {
  if (!store.currentBoardId) return '';
  return store.boards?.find(b => b.id === store.currentBoardId)?.title || '';
});

const columns = computed(() => store.columns || []);

// Архив-колонку убираем из дропа: бросать в архив = создать карточку,
// которую тут же скроют. Бессмысленно.
const dropTargetColumns = computed(() => columns.value.filter(c => !c.is_archive_column));

function columnCardCount(colId) {
  return (store.cards || []).filter(c => c.column_id === colId && !c.parent_card_id).length;
}

function setOpen(v) {
  open.value = v;
  try {
    if (v) localStorage.setItem(STORAGE_KEY, '1');
    else localStorage.removeItem(STORAGE_KEY);
  } catch (e) { /* игнор */ }
  if (v) ensureBoardLoaded();
}

async function ensureBoardLoaded() {
  if (store.currentBoardId && columns.value.length) return;
  loading.value = true;
  try {
    // На странице, отличной от /tasks, пользователь мог не открывать модуль —
    // boards могут быть пустыми. Грузим в правильном порядке: boards → первая → reload.
    if (!store.boards?.length) {
      await store.fetchBoards();
    }
    if (!store.currentBoardId) {
      const firstBoard = (store.boards || []).find(b => !b.is_archived)
                       || (store.boards || [])[0];
      if (firstBoard) await store.loadBoard(firstBoard.id);
    } else if (!columns.value.length) {
      await store.reload();
    }
  } catch (e) {
    toast.error('Не удалось загрузить доску задач');
  } finally {
    loading.value = false;
  }
}

// Глобальный хоткей T (только когда фокус не в input/textarea/contentEditable).
function onKeyDown(e) {
  if (isOnTasksRoute.value) return;
  const tag = (e.target?.tagName || '').toLowerCase();
  if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
  if (e.target?.isContentEditable) return;
  if (e.altKey || e.ctrlKey || e.metaKey) return;

  if (e.key === 't' || e.key === 'T' || e.key === 'е' || e.key === 'Е') {
    e.preventDefault();
    setOpen(!open.value);
  } else if (e.key === 'Escape' && open.value) {
    setOpen(false);
  }
}

// Когда пользователь начинает тащить любую сущность по странице —
// раскрываем виджет автоматически, чтобы было видно куда бросать.
function onGlobalDragStart(e) {
  if (isOnTasksRoute.value) return;
  if (!e.dataTransfer) return;
  const types = Array.from(e.dataTransfer.types || []);
  if (types.includes('application/x-bk-entity')) {
    if (!open.value) setOpen(true);
    ensureBoardLoaded();
  }
}

onMounted(() => {
  document.addEventListener('keydown', onKeyDown);
  document.addEventListener('dragstart', onGlobalDragStart, { capture: true });
});

onUnmounted(() => {
  document.removeEventListener('keydown', onKeyDown);
  document.removeEventListener('dragstart', onGlobalDragStart, { capture: true });
});

async function onDrop(e, col) {
  hoverColId.value = null;
  const raw = e.dataTransfer?.getData('application/x-bk-entity');
  if (!raw) return;
  let entity;
  try {
    entity = JSON.parse(raw);
  } catch {
    return;
  }
  if (!entity?.type || !entity?.id) return;

  try {
    const title = entity.label || `${labelByType(entity.type)} #${entity.id}`;
    const r = await tasksApi.createCard({
      board_id: store.currentBoardId,
      column_id: col.id,
      title,
    });
    await tasksApi.setRelations(r.id, [{
      entity_type: entity.type,
      entity_id: String(entity.id),
      entity_label: entity.label || title,
    }]);
    await store.reload();
    toast.success(`Задача создана в «${col.title}»`);
  } catch (err) {
    toast.error('Не удалось создать задачу: ' + (err?.message || err));
  }
}

function labelByType(t) {
  return ({ order: 'Заказ', supplier: 'Поставщик', product: 'Товар',
            pricing: 'ПСЦ', plan: 'План' })[t] || 'Карточка';
}

// Если пользователь перешёл на /tasks при открытом виджете — закрываем,
// чтобы он не маячил поверх настоящей доски.
watch(isOnTasksRoute, (v) => { if (v) open.value = false; });
</script>

<style scoped>
.tqdw {
  position: fixed;
  bottom: var(--tk-s-4);
  right: var(--tk-s-4);
  z-index: var(--tk-z-popover, 100);
  font-family: var(--tk-font);
}

/* Свёрнутая пилюля-триггер */
.tqdw__trigger {
  display: inline-flex;
  align-items: center;
  gap: var(--tk-s-2);
  padding: var(--tk-s-2) var(--tk-s-3);
  background: var(--tk-accent);
  color: #FFFFFF;
  border: none;
  border-radius: var(--tk-r-pill);
  font-size: var(--tk-fz-md);
  font-weight: var(--tk-fw-medium);
  font-family: inherit;
  cursor: pointer;
  box-shadow: var(--tk-shadow-card-hover);
  transition: background var(--tk-transition-fast), transform var(--tk-transition-fast);
  min-height: var(--tk-touch-min);
}
.tqdw__trigger:hover {
  background: var(--tk-accent-hover);
  transform: translateY(-1px);
}
.tqdw__trigger:focus-visible {
  outline: none;
  box-shadow: var(--tk-focus-ring), var(--tk-shadow-card-hover);
}
.tqdw__kbd {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: var(--tk-fz-xs);
  font-weight: var(--tk-fw-semibold);
  padding: 1px 6px;
  background: rgba(255, 255, 255, 0.20);
  border-radius: var(--tk-r-sm);
}

/* Развёрнутая панель */
.tqdw__panel {
  width: 320px;
  max-height: 60vh;
  background: var(--tk-bg-card);
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-lg);
  box-shadow: var(--tk-shadow-popover);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.tqdw__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--tk-s-3);
  border-bottom: 1px solid var(--tk-border-soft);
}
.tqdw__title {
  font-size: var(--tk-fz-lg);
  font-weight: var(--tk-fw-semibold);
  color: var(--tk-text);
}
.tqdw__close {
  background: none;
  border: none;
  cursor: pointer;
  color: var(--tk-text-muted);
  padding: var(--tk-s-1);
  border-radius: var(--tk-r-sm);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: background var(--tk-transition-fast), color var(--tk-transition-fast);
  min-width: var(--tk-touch-min);
  min-height: var(--tk-touch-min);
}
.tqdw__close:hover {
  background: var(--tk-n-100);
  color: var(--tk-text);
}

.tqdw__hint {
  padding: var(--tk-s-2) var(--tk-s-3);
  font-size: var(--tk-fz-xs);
  color: var(--tk-text-muted);
  line-height: var(--tk-lh-base);
  background: var(--tk-n-50);
  border-bottom: 1px solid var(--tk-border-soft);
}

.tqdw__loading {
  padding: var(--tk-s-3);
  display: flex;
  flex-direction: column;
  gap: var(--tk-s-2);
}

.tqdw__columns {
  flex: 1;
  overflow-y: auto;
  padding: var(--tk-s-2);
  display: flex;
  flex-direction: column;
  gap: var(--tk-s-1);
}

.tqdw__column {
  display: flex;
  align-items: center;
  gap: var(--tk-s-2);
  padding: var(--tk-s-2) var(--tk-s-3);
  border-radius: var(--tk-r-md);
  background: var(--tk-n-50);
  border: 1px dashed transparent;
  cursor: copy;
  transition: background var(--tk-transition-fast), border-color var(--tk-transition-fast);
  min-height: var(--tk-touch-min);
  box-sizing: border-box;
}
.tqdw__column--hover {
  background: var(--tk-accent-soft);
  border-color: var(--tk-accent);
}
.tqdw__col-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
}
.tqdw__col-title {
  flex: 1;
  font-size: var(--tk-fz-md);
  font-weight: var(--tk-fw-medium);
  color: var(--tk-text);
}
.tqdw__col-count {
  font-size: var(--tk-fz-xs);
  font-weight: var(--tk-fw-semibold);
  color: var(--tk-text-muted);
  background: var(--tk-n-100);
  padding: 2px var(--tk-s-2);
  border-radius: var(--tk-r-pill);
}

.tqdw__footer {
  padding: var(--tk-s-2) var(--tk-s-3);
  font-size: var(--tk-fz-xs);
  color: var(--tk-text-muted);
  border-top: 1px solid var(--tk-border-soft);
  background: var(--tk-n-50);
}

/* На мобильных — виджет растягивается ниже, чтобы не наезжать на бордюры
   и палец легко попадал по дроп-зонам. */
@media (max-width: 720px) {
  .tqdw {
    bottom: var(--tk-s-3);
    right: var(--tk-s-3);
    left: var(--tk-s-3);
  }
  .tqdw__panel {
    width: auto;
    max-height: 70vh;
  }
}
</style>
