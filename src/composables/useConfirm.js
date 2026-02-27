import { ref } from 'vue';

/**
 * Вызов модалки подтверждения через Promise.
 * Возвращает реактивное состояние для ConfirmModal и функцию confirm().
 */
export function useConfirm() {
  const confirmModal = ref({ show: false, title: '', message: '', resolve: null });

  function confirm(title, message) {
    return new Promise(resolve => {
      confirmModal.value = { show: true, title, message, resolve };
    });
  }

  function onConfirm() {
    confirmModal.value.resolve(true);
    confirmModal.value.show = false;
  }

  function onCancel() {
    confirmModal.value.resolve(false);
    confirmModal.value.show = false;
  }

  return { confirmModal, confirm, onConfirm, onCancel };
}
