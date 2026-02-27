import { ref } from 'vue';

/**
 * Отслеживает «грязность» формы через JSON-снимок.
 * @param {import('vue').Ref} form — ref с объектом формы
 * @returns {{ saveSnapshot, isDirty }}
 */
export function useFormDirty(form) {
  const _snapshot = ref(null);

  function saveSnapshot() {
    _snapshot.value = JSON.stringify(form.value);
  }

  function isDirty() {
    return JSON.stringify(form.value) !== _snapshot.value;
  }

  return { saveSnapshot, isDirty };
}
