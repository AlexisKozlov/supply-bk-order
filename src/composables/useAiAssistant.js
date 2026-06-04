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
// Защита от XSS — атрибуты вырезаются, у ссылок проверяется href.
const ALLOWED = /^(b|strong|i|em|br|ul|ol|li|p|code)$/i;
export function renderAnswer(html) {
  if (!html) return '';
  let s = String(html);
  // Модель иногда отвечает markdown — переводим в безопасные теги.
  s = s.replace(/```([\s\S]*?)```/g, (m, c) => `<code>${c.replace(/[<>]/g, '')}</code>`);
  s = s.replace(/`([^`\n]+)`/g, (m, c) => `<code>${c.replace(/[<>]/g, '')}</code>`);
  s = s.replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>');
  s = s.replace(/__([^_]+)__/g, '<b>$1</b>');
  s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2">$1</a>');
  s = s.replace(/^#{1,6}\s*(.+)$/gm, '<b>$1</b>');
  // Удаляем опасные блоки и санитизируем теги.
  s = s.replace(/<(script|style)[\s\S]*?<\/\1>/gi, '');
  s = s.replace(/<\/?([a-zA-Z][a-zA-Z0-9]*)((?:\s[^>]*)?)\/?>/g, (m, tag, attrs) => {
    tag = tag.toLowerCase();
    const closing = m.startsWith('</');
    if (tag === 'a') {
      if (closing) return '</a>';
      const hm = (attrs || '').match(/href\s*=\s*["']([^"']*)["']/i);
      const url = hm ? hm[1] : '';
      return /^(https?:|\/)/i.test(url) ? `<a href="${url}" target="_blank" rel="noopener noreferrer">` : '';
    }
    if (ALLOWED.test(tag)) return closing ? `</${tag}>` : `<${tag}>`;
    return '';
  });
  return s;
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
