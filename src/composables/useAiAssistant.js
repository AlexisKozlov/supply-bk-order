/**
 * useAiAssistant — общий стейт ИИ-ассистента закупок.
 * Один разговор на всё приложение (singleton): плавающий виджет и страница
 * /assistant показывают одну и ту же переписку. Бэкенд — RPC `ai_assistant`
 * (DeepSeek + инструменты бота).
 */
import { reactive, readonly } from 'vue';
import { db } from '@/lib/apiClient.js';
import { useOrderStore } from '@/stores/orderStore.js';

const state = reactive({
  messages: [],   // { role: 'user' | 'assistant', content, error?: bool }
  loading: false,
});

// Санитизация ответа ИИ: оставляем только безопасные теги (<b>, списки, <a>).
// Защита от XSS: HTML разбирается штатным парсером браузера (DOMParser в
// инертном документе — скрипты и onerror при разборе не срабатывают), затем
// дерево пересобирается заново. В результат попадают ТОЛЬКО разрешённые теги
// без единого атрибута (кроме проверенного href у ссылок), поэтому любые
// обработчики событий (onclick, onerror и т.п.) и неизвестные теги отсекаются.
// Регулярный санитайзер тут ненадёжен: браузер парсит HTML не так, как regexp,
// и трюки вроде «<b/onclick=…>» его обходят — DOMParser таких лазеек не даёт.
const ALLOWED_TAGS = new Set(['B', 'STRONG', 'I', 'EM', 'BR', 'UL', 'OL', 'LI', 'P', 'CODE']);

// Копирует безопасное содержимое узла source в target, создавая новые узлы.
function sanitizeChildren(source, target, doc) {
  for (const node of Array.from(source.childNodes)) {
    if (node.nodeType === 3) { // текст — как есть (появится экранированным в innerHTML)
      target.appendChild(doc.createTextNode(node.nodeValue));
      continue;
    }
    if (node.nodeType !== 1) continue; // комментарии и прочее отбрасываем
    const tag = node.tagName;
    if (tag === 'SCRIPT' || tag === 'STYLE') continue; // содержимое опасных блоков не переносим
    if (tag === 'A') {
      const href = node.getAttribute('href') || '';
      if (/^(https?:|\/)/i.test(href)) {
        const a = doc.createElement('a');
        a.setAttribute('href', href);
        a.setAttribute('target', '_blank');
        a.setAttribute('rel', 'noopener noreferrer');
        sanitizeChildren(node, a, doc);
        target.appendChild(a);
      } else {
        // Недопустимая ссылка (напр. javascript:) — оставляем только текст.
        sanitizeChildren(node, target, doc);
      }
      continue;
    }
    if (ALLOWED_TAGS.has(tag)) {
      const el = doc.createElement(tag.toLowerCase());
      sanitizeChildren(node, el, doc);
      target.appendChild(el);
    } else {
      // Неразрешённый тег — разворачиваем в его текстовое содержимое.
      sanitizeChildren(node, target, doc);
    }
  }
}

export function renderAnswer(html) {
  if (!html) return '';
  let s = String(html);
  // Модель иногда отвечает markdown — переводим в те же безопасные теги.
  // Результат всё равно проходит через DOM-санитайзер ниже.
  s = s.replace(/```([\s\S]*?)```/g, (m, c) => `<code>${c}</code>`);
  s = s.replace(/`([^`\n]+)`/g, (m, c) => `<code>${c}</code>`);
  s = s.replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>');
  s = s.replace(/__([^_]+)__/g, '<b>$1</b>');
  s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2">$1</a>');
  s = s.replace(/^#{1,6}\s*(.+)$/gm, '<b>$1</b>');

  const doc = new DOMParser().parseFromString(s, 'text/html');
  const out = doc.createElement('div');
  sanitizeChildren(doc.body, out, doc);
  return out.innerHTML;
}

export function useAiAssistant() {
  async function ask(text) {
    const q = String(text || '').trim();
    if (!q || state.loading) return;

    // История диалога (без ошибочных реплик) — последние 8 сообщений ДО текущего.
    const history = state.messages
      .filter(m => !m.error)
      .slice(-8)
      .map(m => ({ role: m.role, content: m.content }));

    state.messages.push({ role: 'user', content: q });
    state.loading = true;

    let entity = '';
    try { entity = useOrderStore().settings.legalEntity || ''; } catch (e) { /* noop */ }

    const { data, error } = await db.rpc(
      'ai_assistant',
      { question: q, entity, history },
      { timeoutMs: 60000, maxRetries: 0 },
    );
    state.loading = false;

    if (error || !data || !data.answer) {
      state.messages.push({
        role: 'assistant',
        content: error ? `Не удалось получить ответ: ${error}` : 'Пустой ответ от ИИ.',
        error: true,
      });
    } else {
      state.messages.push({ role: 'assistant', content: data.answer });
    }
  }

  function clear() {
    state.messages = [];
  }

  return { state: readonly(state), ask, clear };
}
