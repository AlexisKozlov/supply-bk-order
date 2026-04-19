import { ref } from 'vue';

/**
 * Модалки подтверждения и информирования через Promise.
 * Возвращает реактивное состояние и функции для ConfirmModal / InfoModal.
 *
 * confirm(title, message, opts?) — opts: { okText, cancelText, danger }
 * info(title, message, type?)    — type: 'info' | 'success' | 'warning' | 'error'
 */
export function useConfirm() {
  const confirmModal = ref({
    show: false,
    title: '',
    message: '',
    okText: 'Подтвердить',
    cancelText: 'Отмена',
    danger: false,
    resolve: null,
  });

  function confirm(title, message, opts = {}) {
    if (confirmModal.value.show && confirmModal.value.resolve) {
      confirmModal.value.resolve(false);
    }
    return new Promise(resolve => {
      confirmModal.value = {
        show: true,
        title,
        message,
        okText: opts.okText || 'Подтвердить',
        cancelText: opts.cancelText || 'Отмена',
        danger: !!opts.danger,
        resolve,
      };
    });
  }

  function onConfirm() {
    confirmModal.value.resolve?.(true);
    confirmModal.value.show = false;
  }

  function onCancel() {
    confirmModal.value.resolve?.(false);
    confirmModal.value.show = false;
  }

  function onClose() {
    if (confirmModal.value.show && confirmModal.value.resolve) {
      confirmModal.value.resolve(false);
    }
    confirmModal.value.show = false;
  }

  const infoModal = ref({ show: false, title: '', message: '', type: 'info' });

  function info(title, message, type = 'info') {
    infoModal.value = { show: true, title, message, type };
  }

  function onInfoClose() {
    infoModal.value.show = false;
  }

  return {
    confirmModal, confirm, onConfirm, onCancel, onClose,
    infoModal, info, onInfoClose,
  };
}
