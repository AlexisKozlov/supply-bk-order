<template>
  <div class="me" :class="{ 'me-compact': compact, 'me-focused': focused }">
    <!-- Поповер @упоминаний -->
    <div v-if="mentionOpen && filteredMentions.length" class="me-mention-pop" @mousedown.prevent>
      <button v-for="(u, i) in filteredMentions" :key="u.name" type="button"
              class="me-mention-item"
              :class="{ 'is-active': i === mentionIndex }"
              @mouseenter="mentionIndex = i"
              @click="selectMention(u)">
        <span class="me-mention-avatar">{{ initialsOf(u.name) }}</span>
        <span class="me-mention-name">{{ u.name }}</span>
      </button>
    </div>
    <div class="me-toolbar" @mousedown.prevent>
      <button type="button" class="me-btn" :class="{ active: isActive('bold') }"
              title="Жирный (Ctrl+B)" @click="cmd('toggleBold')"><strong>B</strong></button>
      <button type="button" class="me-btn" :class="{ active: isActive('italic') }"
              title="Курсив (Ctrl+I)" @click="cmd('toggleItalic')"><em>I</em></button>
      <button type="button" class="me-btn" :class="{ active: isActive('strike') }"
              title="Зачёркнутый" @click="cmd('toggleStrike')"><s>S</s></button>
      <button type="button" class="me-btn me-btn-mono" :class="{ active: isActive('code') }"
              title="Инлайн-код" @click="cmd('toggleCode')">{}</button>
      <button type="button" class="me-btn" :class="{ active: isActive('link') }"
              title="Ссылка" @click="toggleLink">
        <TaskIcon name="arrowUpRight" :size="14"/>
      </button>
      <template v-if="!compact">
        <span class="me-sep"></span>
        <button type="button" class="me-btn" :class="{ active: isActive('heading', { level: 2 }) }"
                title="Заголовок" @click="cmd('toggleHeading', { level: 2 })"><strong>H</strong></button>
        <button type="button" class="me-btn" :class="{ active: isActive('bulletList') }"
                title="Маркированный список" @click="cmd('toggleBulletList')">
          <TaskIcon name="list" :size="14"/>
        </button>
        <button type="button" class="me-btn me-btn-mono" :class="{ active: isActive('orderedList') }"
                title="Нумерованный список" @click="cmd('toggleOrderedList')">1.</button>
        <button type="button" class="me-btn me-btn-mono" :class="{ active: isActive('blockquote') }"
                title="Цитата" @click="cmd('toggleBlockquote')">&ldquo;</button>
      </template>
      <span class="me-spacer"></span>
      <button type="button" class="me-btn me-btn-mono" title="Отменить (Ctrl+Z)"
              @click="cmd('undo')" :disabled="!canUndo">↶</button>
      <button type="button" class="me-btn me-btn-mono" title="Повторить (Ctrl+Y)"
              @click="cmd('redo')" :disabled="!canRedo">↷</button>
    </div>
    <editor-content :editor="editor" class="me-content"/>
  </div>
</template>

<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Placeholder from '@tiptap/extension-placeholder';
import { Markdown } from 'tiptap-markdown';
import TaskIcon from './TaskIcon.vue';

const props = defineProps({
  modelValue: { type: String, default: '' },
  compact: { type: Boolean, default: false },
  placeholder: { type: String, default: '' },
  mentions: { type: Array, default: () => [] }, // [{ name }]
});
const emit = defineEmits(['update:modelValue', 'blur', 'ctrl-enter']);

const focused = ref(false);
const canUndo = ref(false);
const canRedo = ref(false);

// ─── @упоминания ───
const mentionOpen = ref(false);
const mentionQuery = ref('');
const mentionStart = ref(0); // позиция в редакторе сразу после @
const mentionIndex = ref(0);

const filteredMentions = computed(() => {
  if (!mentionOpen.value) return [];
  const q = (mentionQuery.value || '').toLowerCase();
  const list = props.mentions || [];
  if (!q) return list.slice(0, 8);
  return list.filter(u => (u.name || '').toLowerCase().includes(q)).slice(0, 8);
});

function initialsOf(name) {
  if (!name) return '?';
  const parts = String(name).trim().split(/\s+/);
  return ((parts[0]?.[0] || '') + (parts[1]?.[0] || '')).toUpperCase() || '?';
}

function closeMention() {
  mentionOpen.value = false;
  mentionQuery.value = '';
  mentionIndex.value = 0;
}

function openMention(pos) {
  if (!props.mentions || !props.mentions.length) return;
  mentionStart.value = pos;
  mentionQuery.value = '';
  mentionIndex.value = 0;
  mentionOpen.value = true;
}

function selectMention(u) {
  const ed = editor.value;
  if (!ed) return;
  // Пробелы в имени заменяем на '_' — для надёжного парсинга на бэке
  // и подсветки на фронте (при выводе вернём пробелы обратно)
  const safe = (u.name || '').replace(/\s+/g, '_');
  const from = mentionStart.value - 1; // позиция @
  const to = mentionStart.value + mentionQuery.value.length;
  ed.chain().focus()
    .insertContentAt({ from, to }, '@' + safe + ' ')
    .run();
  closeMention();
}

function updateMentionQuery() {
  const ed = editor.value;
  if (!ed || !mentionOpen.value) return;
  const { from } = ed.state.selection;
  if (from < mentionStart.value) { closeMention(); return; }
  const text = ed.state.doc.textBetween(mentionStart.value, from, ' ');
  // Если в тексте появился пробел/перенос — закрываем
  if (/\s/.test(text)) { closeMention(); return; }
  mentionQuery.value = text;
  // Если ничего не подходит — закрываем
  if (!filteredMentions.value.length) closeMention();
  else if (mentionIndex.value >= filteredMentions.value.length) mentionIndex.value = 0;
}

const editor = useEditor({
  content: props.modelValue || '',
  extensions: [
    StarterKit.configure({
      heading: { levels: [2, 3] },
      codeBlock: false,
    }),
    Link.configure({
      openOnClick: false,
      autolink: true,
      HTMLAttributes: { target: '_blank', rel: 'noopener noreferrer' },
    }),
    Placeholder.configure({ placeholder: props.placeholder || '' }),
    Markdown.configure({
      html: false,
      tightLists: true,
      linkify: true,
      breaks: true,
      transformPastedText: true,
      transformCopiedText: true,
    }),
  ],
  editorProps: {
    handleKeyDown(view, event) {
      // Управление поповером @-упоминаний
      if (mentionOpen.value) {
        const list = filteredMentions.value;
        if (event.key === 'ArrowDown') {
          mentionIndex.value = (mentionIndex.value + 1) % Math.max(1, list.length);
          return true;
        }
        if (event.key === 'ArrowUp') {
          mentionIndex.value = (mentionIndex.value - 1 + list.length) % Math.max(1, list.length);
          return true;
        }
        if (event.key === 'Enter') {
          if (list[mentionIndex.value]) { selectMention(list[mentionIndex.value]); return true; }
          closeMention();
        }
        if (event.key === 'Escape') { closeMention(); return true; }
      }
      // Открытие поповера на @ (только если есть список пользователей)
      if (event.key === '@' && props.mentions && props.mentions.length) {
        // Поставим запуск после вставки @
        setTimeout(() => {
          const ed = editor.value;
          if (!ed) return;
          openMention(ed.state.selection.from);
        }, 0);
      }

      if (event.key === 'Enter') {
        // Ctrl+Enter — отправка (классическое сочетание)
        if (event.ctrlKey || event.metaKey) {
          emit('ctrl-enter');
          return true;
        }
        // В compact-режиме (чат) Enter без модификаторов тоже отправляет;
        // для переноса строки — Shift+Enter.
        if (props.compact && !event.shiftKey) {
          emit('ctrl-enter');
          return true;
        }
      }
      return false;
    },
  },
  onUpdate: ({ editor }) => {
    const md = editor.storage.markdown.getMarkdown();
    emit('update:modelValue', md);
    canUndo.value = editor.can().undo();
    canRedo.value = editor.can().redo();
    updateMentionQuery();
  },
  onSelectionUpdate: ({ editor }) => {
    canUndo.value = editor.can().undo();
    canRedo.value = editor.can().redo();
  },
  onFocus: () => { focused.value = true; },
  onBlur: () => { focused.value = false; emit('blur'); },
});

watch(() => props.modelValue, (val) => {
  const ed = editor.value;
  if (!ed) return;
  const current = ed.storage.markdown.getMarkdown();
  if ((val || '') !== current) {
    ed.commands.setContent(val || '', false);
  }
});

function cmd(name, ...args) {
  const ed = editor.value;
  if (!ed) return;
  ed.chain().focus()[name](...args).run();
}
function isActive(...args) {
  return editor.value?.isActive(...args) || false;
}
function toggleLink() {
  const ed = editor.value;
  if (!ed) return;
  if (ed.isActive('link')) {
    ed.chain().focus().unsetLink().run();
    return;
  }
  const prev = ed.getAttributes('link').href;
  const url = (window.prompt('URL ссылки:', prev || 'https://') || '').trim();
  if (!url) return;
  ed.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
}

defineExpose({
  focus: () => editor.value?.commands.focus(),
  clear: () => editor.value?.commands.clearContent(true),
});

onBeforeUnmount(() => { editor.value?.destroy(); });
</script>

<style scoped>
.me {
  display: flex; flex-direction: column;
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-sm);
  background: var(--tk-n-0);
  transition: border-color var(--tk-transition), box-shadow var(--tk-transition);
  position: relative;
}
.me:hover { border-color: var(--tk-n-300); }

/* Поповер @-упоминаний */
.me-mention-pop {
  position: absolute;
  bottom: calc(100% + 4px);
  left: 0;
  min-width: 220px;
  max-width: 320px;
  max-height: 240px;
  overflow-y: auto;
  background: #fff;
  border: 1px solid var(--tk-border, #E6E1D7);
  border-radius: 10px;
  box-shadow: 0 12px 28px rgba(15,23,42,0.14), 0 2px 4px rgba(15,23,42,0.06);
  padding: 4px;
  z-index: 50;
  display: flex; flex-direction: column; gap: 1px;
}
.me-mention-item {
  display: flex; align-items: center; gap: 8px;
  width: 100%;
  padding: 6px 8px;
  background: transparent;
  border: none;
  border-radius: 7px;
  font-family: inherit; font-size: 12.5px; font-weight: 500;
  color: var(--tk-text, #1A1814);
  text-align: left;
  cursor: pointer;
  transition: background 100ms ease;
}
.me-mention-item.is-active,
.me-mention-item:hover {
  background: var(--tk-accent-soft, rgba(232,122,30,0.10));
  color: var(--tk-accent-text, #B85A0E);
}
.me-mention-avatar {
  flex-shrink: 0;
  width: 24px; height: 24px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--tk-accent, #E87A1E), #F4A261);
  color: #fff;
  font-size: 10.5px; font-weight: 700;
  display: inline-flex; align-items: center; justify-content: center;
}
.me-mention-name { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.me.me-focused { border-color: var(--tk-accent); box-shadow: var(--tk-focus-ring); }

.me-toolbar {
  display: flex; align-items: center; gap: 2px;
  padding: 4px;
  background: var(--tk-n-50, #F7F8F9);
  border-bottom: 1px solid var(--tk-border);
  border-top-left-radius: var(--tk-r-sm);
  border-top-right-radius: var(--tk-r-sm);
  flex-wrap: wrap;
}
.me-btn {
  width: 28px; height: 28px;
  display: inline-flex; align-items: center; justify-content: center;
  background: transparent; border: 1px solid transparent;
  border-radius: var(--tk-r-sm);
  cursor: pointer;
  color: var(--tk-text);
  font-size: var(--tk-fz-md, 13px);
  font-family: inherit;
  padding: 0;
  transition: background 120ms ease, border-color 120ms ease, color 120ms ease;
}
.me-btn:hover { background: var(--tk-n-200, #DCDFE4); }
.me-btn:active { background: var(--tk-n-300, #B7BBC0); }
.me-btn.active {
  background: var(--tk-accent-soft, #FEEFE0);
  color: var(--tk-accent-text, #B85A0E);
}
.me-btn:disabled { opacity: 0.35; cursor: default; }
.me-btn:disabled:hover { background: transparent; }
.me-btn-mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 12px;
}
.me-sep {
  width: 1px; height: 18px;
  background: var(--tk-border);
  margin: 0 4px;
}
.me-spacer { flex: 1; }

.me-content {
  font-size: var(--tk-fz-md, 13px);
  color: var(--tk-text);
  line-height: 1.55;
  padding: 8px 10px;
  min-height: 60px;
}
.me-compact .me-content { min-height: 38px; padding: 6px 10px; }

/* TipTap ProseMirror editable area */
.me-content :deep(.ProseMirror) {
  outline: none;
  word-wrap: break-word;
  overflow-wrap: anywhere;
  min-height: inherit;
}
.me-content :deep(.ProseMirror p) { margin: 0 0 6px; }
.me-content :deep(.ProseMirror p:last-child) { margin-bottom: 0; }
.me-content :deep(.ProseMirror h2) { margin: 6px 0 4px; font-size: 15px; font-weight: 700; }
.me-content :deep(.ProseMirror h3) { margin: 6px 0 4px; font-size: 14px; font-weight: 700; }
.me-content :deep(.ProseMirror ul),
.me-content :deep(.ProseMirror ol) { margin: 0 0 6px; padding-left: 22px; }
.me-content :deep(.ProseMirror li) { margin: 1px 0; }
.me-content :deep(.ProseMirror li > p) { margin: 0; }
.me-content :deep(.ProseMirror a) {
  color: var(--tk-accent-text, #B85A0E);
  text-decoration: underline;
  cursor: pointer;
}
.me-content :deep(.ProseMirror a:hover) { color: var(--tk-accent, #E87A1E); }
.me-content :deep(.ProseMirror code) {
  background: var(--tk-n-100, #F1F2F4);
  padding: 1px 5px;
  border-radius: 3px;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 0.92em;
}
.me-content :deep(.ProseMirror strong) { font-weight: 700; }
.me-content :deep(.ProseMirror em) { font-style: italic; }
.me-content :deep(.ProseMirror s) { text-decoration: line-through; }
.me-content :deep(.ProseMirror blockquote) {
  border-left: 3px solid var(--tk-n-300, #B7BBC0);
  padding-left: 10px;
  margin: 4px 0;
  color: var(--tk-text-muted);
}

/* Placeholder через @tiptap/extension-placeholder */
.me-content :deep(.ProseMirror p.is-editor-empty:first-child::before) {
  content: attr(data-placeholder);
  float: left;
  color: var(--tk-text-muted, #6B778C);
  pointer-events: none;
  height: 0;
}
</style>
