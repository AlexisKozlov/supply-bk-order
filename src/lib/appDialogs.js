import { reactive } from 'vue';

// Глобальный сервис диалогов на стилизованных модалках проекта.
// Заменяет нативные window.confirm/alert/prompt — у тех нельзя
// поменять стиль и они выглядят неподходяще под интерфейс.
//
// Хост-компонент <AppDialogHost> рендерится один раз в AppLayout
// и подхватывает изменения этого состояния.

export const dialogState = reactive({
  confirm: { show: false, title: '', message: '', okText: 'Подтвердить', cancelText: 'Отмена', danger: false, resolve: null },
  info:    { show: false, title: '', message: '', type: 'info', resolve: null },
  prompt:  { show: false, title: '', message: '', value: '', placeholder: '', okText: 'OK', cancelText: 'Отмена', resolve: null },
});

function settleConfirm(value) {
  const r = dialogState.confirm.resolve;
  dialogState.confirm.resolve = null;
  dialogState.confirm.show = false;
  if (r) r(value);
}

function settleInfo() {
  const r = dialogState.info.resolve;
  dialogState.info.resolve = null;
  dialogState.info.show = false;
  if (r) r();
}

function settlePrompt(value) {
  const r = dialogState.prompt.resolve;
  dialogState.prompt.resolve = null;
  dialogState.prompt.show = false;
  if (r) r(value);
}

// Подтверждение «Да/Нет». Возвращает Promise<boolean>.
// opts: { okText, cancelText, danger, title }
export function appConfirm(message, opts = {}) {
  if (dialogState.confirm.show) settleConfirm(false);
  return new Promise(resolve => {
    Object.assign(dialogState.confirm, {
      show: true,
      title: opts.title || 'Подтвердите действие',
      message: String(message ?? ''),
      okText: opts.okText || 'Подтвердить',
      cancelText: opts.cancelText || 'Отмена',
      danger: !!opts.danger,
      resolve,
    });
  });
}

// Информационная модалка с кнопкой OK. Возвращает Promise<void>.
// type: 'info' | 'success' | 'warning' | 'error'
export function appAlert(message, opts = {}) {
  if (dialogState.info.show) settleInfo();
  return new Promise(resolve => {
    Object.assign(dialogState.info, {
      show: true,
      title: opts.title || 'Сообщение',
      message: String(message ?? ''),
      type: opts.type || 'info',
      resolve,
    });
  });
}

// Запрос строки. Возвращает Promise<string|null>: null при отмене.
export function appPrompt(message, defaultValue = '', opts = {}) {
  if (dialogState.prompt.show) settlePrompt(null);
  return new Promise(resolve => {
    Object.assign(dialogState.prompt, {
      show: true,
      title: opts.title || 'Введите значение',
      message: String(message ?? ''),
      value: String(defaultValue ?? ''),
      placeholder: opts.placeholder || '',
      okText: opts.okText || 'OK',
      cancelText: opts.cancelText || 'Отмена',
      resolve,
    });
  });
}

// Внутренние колбэки для AppDialogHost
export const _dialogInternals = { settleConfirm, settleInfo, settlePrompt };
