import { ref } from 'vue';

// Singleton-состояние диалогов модуля «Задачи». Импортируется в любых компонентах,
// модалки рендерятся один раз — в TasksView.vue.

const confirmModal = ref({
  show: false, title: '', message: '',
  okText: 'Подтвердить', cancelText: 'Отмена', danger: false, resolve: null,
});

const infoModal = ref({ show: false, title: '', message: '', type: 'info' });

const promptModal = ref({
  show: false, title: '', message: '', value: '',
  placeholder: '', okText: 'Сохранить', cancelText: 'Отмена', resolve: null,
});

function confirm(title, message, opts = {}) {
  if (confirmModal.value.show && confirmModal.value.resolve) confirmModal.value.resolve(false);
  return new Promise(resolve => {
    confirmModal.value = {
      show: true, title, message,
      okText: opts.okText || 'Подтвердить',
      cancelText: opts.cancelText || 'Отмена',
      danger: !!opts.danger, resolve,
    };
  });
}
function onConfirm() { confirmModal.value.resolve?.(true); confirmModal.value.show = false; }
function onCancel()  { confirmModal.value.resolve?.(false); confirmModal.value.show = false; }

function info(title, message, type = 'info') {
  infoModal.value = { show: true, title, message, type };
}
function onInfoClose() { infoModal.value.show = false; }

function prompt(title, opts = {}) {
  if (promptModal.value.show && promptModal.value.resolve) promptModal.value.resolve(null);
  return new Promise(resolve => {
    promptModal.value = {
      show: true, title,
      message: opts.message || '',
      value: opts.defaultValue || '',
      placeholder: opts.placeholder || '',
      okText: opts.okText || 'Сохранить',
      cancelText: opts.cancelText || 'Отмена',
      resolve,
    };
  });
}
function onPromptOk(value) { promptModal.value.resolve?.(value); promptModal.value.show = false; }
function onPromptCancel() { promptModal.value.resolve?.(null); promptModal.value.show = false; }

export function useTasksDialogs() {
  return {
    confirmModal, confirm, onConfirm, onCancel,
    infoModal, info, onInfoClose,
    promptModal, prompt, onPromptOk, onPromptCancel,
  };
}
