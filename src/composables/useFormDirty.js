import { ref, isRef, onMounted, onUnmounted } from 'vue';
import { appConfirm } from '@/lib/appDialogs.js';

/**
 * Отслеживает «грязность» формы через JSON-снимок.
 *
 * Принимает что угодно из трёх:
 *   useFormDirty(formRef)            — ref с объектом формы
 *   useFormDirty(reactiveForm)       — reactive-объект
 *   useFormDirty(() => ({ a, b }))   — геттер, если поля лежат отдельными ref'ами
 *
 * @returns {{ saveSnapshot, isDirty }}
 */
export function useFormDirty(form) {
  const _snapshot = ref(null);

  function serialize() {
    const src = typeof form === 'function' ? form() : (isRef(form) ? form.value : form);
    try {
      return JSON.stringify(src);
    } catch {
      return null; // циклические ссылки — считаем «не грязно», лишний вопрос хуже
    }
  }

  function saveSnapshot() {
    _snapshot.value = serialize();
  }

  function isDirty() {
    const now = serialize();
    if (now === null || _snapshot.value === null) return false;
    return now !== _snapshot.value;
  }

  return { saveSnapshot, isDirty };
}

/** Спросить подтверждение перед потерей введённого. Promise<boolean>. */
export function confirmDiscard() {
  return appConfirm('Введённые данные будут потеряны.', {
    title: 'Закрыть без сохранения?',
    okText: 'Закрыть',
    cancelText: 'Остаться',
    danger: true,
  });
}

/**
 * Для страниц, где несколько модалок живут в одном компоненте (views/*.vue) и
 * useCloseGuard не подходит — у него один Esc на всех.
 *
 *   const addGuard = useDirtySnapshot();
 *   function openAddModal() { ...заполнили...; addGuard.mark(addModal.value); }
 *   async function closeAddModal() {
 *     if (!(await addGuard.confirmClose(addModal.value))) return;
 *     addModal.value.show = false;
 *   }
 */
export function useDirtySnapshot() {
  let snap = null;
  const ser = (v) => { try { return JSON.stringify(v); } catch { return null; } };
  return {
    mark(value) { snap = ser(value); },
    /** true — можно закрывать (не менялось или пользователь подтвердил). */
    async confirmClose(value) {
      const now = ser(value);
      if (snap === null || now === null || now === snap) return true;
      return await confirmDiscard();
    },
  };
}

/**
 * Правило проекта: окно, где что-то вводят и сохраняют, не закрывается молча —
 * при несохранённых изменениях спрашиваем подтверждение. Причём на ВСЕХ путях
 * закрытия: крестик, «Отмена», клик по фону, Esc.
 *
 * Использование:
 *   const { saveSnapshot, isDirty, tryClose } = useCloseGuard(form, () => emit('close'));
 *   // ...после загрузки данных в форму:
 *   saveSnapshot();
 * и дальше в шаблоне везде вместо emit('close') — tryClose().
 *
 * @param form     ref | reactive | геттер — то же, что у useFormDirty
 * @param onClose  что вызвать, когда закрытие подтверждено
 * @param opts     { bindEscape = true } — вешать ли обработчик Esc
 */
export function useCloseGuard(form, onClose, opts = {}) {
  const { bindEscape = true } = opts;
  const { saveSnapshot, isDirty } = useFormDirty(form);
  let asking = false;

  async function tryClose() {
    if (asking) return;            // не стакаем подтверждения
    if (!isDirty()) { onClose(); return; }
    asking = true;
    try {
      if (await confirmDiscard()) onClose();
    } finally {
      asking = false;
    }
  }

  if (bindEscape) {
    const onKey = (e) => { if (e.key === 'Escape') tryClose(); };
    onMounted(() => document.addEventListener('keydown', onKey));
    onUnmounted(() => document.removeEventListener('keydown', onKey));
  }

  return { saveSnapshot, isDirty, tryClose };
}
