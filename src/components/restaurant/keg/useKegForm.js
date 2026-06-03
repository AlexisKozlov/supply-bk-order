// Композабл с логикой формы «Возврат кег».
// Держит:
//   - состояние формы (form, kegQties, fieldErr, catalog, флаги загрузки/сохранения)
//   - дедлайн-таймер и связанные computed (formReadonly, bsoSectionVisible, …)
//   - все API-вызовы (loadFormData, loadCatalog, loadRestaurantInfo, saveDraft, submit, cancel, delete)
//   - валидаторы
//   - модалки формы (подтверждения, просмотр фото, замена БСО, «Что дальше»)
//
// KegReturnForm.vue становится тонким — берёт всё отсюда и просто рендерит.

import { ref, reactive, computed, onMounted, onUnmounted, watch } from 'vue';
import { useToastStore } from '@/stores/toastStore.js';
import { roFetch } from '@/lib/roUtils.js';
import {
  fmtDate, fmtIsoLocal,
  WEEKDAY_NAMES, buildAvailableDates,
  pluralKegs,
} from './kegHelpers.js';

// Авторизация ресторана идёт через HttpOnly-cookie ro_session —
// для всех запросов (включая печать ТТН и скачивание Excel) браузер
// автоматически прикладывает её.

export function useKegForm(initialIdRef, emit) {
  const toast = useToastStore();

  // ─── Локальный ID заявки (после первого сохранения новой — становится числом)
  const localId = ref(initialIdRef.value || null);

  // ─── Состояние формы
  const form = reactive({});
  const kegQties = reactive({});
  const catalog = ref([]);
  const formLoading = ref(false);
  const formError = ref('');
  const catalogLoading = ref(false);
  const saving = ref(false);
  const restaurantInfoLoaded = ref(false);

  // Поля с ошибками для подсветки рамкой
  const fieldErr = reactive({ return_date: false, bso: false });

  // ─── Просмотр фото
  const photoModal = reactive({ show: false, url: '', name: '' });
  function openPhoto(keg) {
    if (!keg?.photo_url) return;
    photoModal.url = keg.photo_url;
    photoModal.name = keg.name;
    photoModal.show = true;
  }
  function closePhoto() { photoModal.show = false; }

  // ─── Модал «Что дальше» после успешного формирования ТТН
  const submittedModal = reactive({ show: false, bsoStr: '' });
  function closeSubmitted() { submittedModal.show = false; }
  function onSubmittedPrint() {
    submittedModal.show = false;
    printTtn();
  }

  // ─── Подтверждения
  const confirmModal = reactive({
    show: false,
    title: '',
    message: '',
    okText: 'OK',
    cancelText: 'Отмена',
    danger: false,
    resolve: null,
  });
  function askConfirm({ title, message, okText = 'OK', cancelText = 'Отмена', danger = false }) {
    return new Promise(resolve => {
      confirmModal.title = title;
      confirmModal.message = message;
      confirmModal.okText = okText;
      confirmModal.cancelText = cancelText;
      confirmModal.danger = danger;
      confirmModal.resolve = resolve;
      confirmModal.show = true;
    });
  }
  function confirmOk() {
    confirmModal.show = false;
    confirmModal.resolve?.(true);
    confirmModal.resolve = null;
  }
  function confirmCancel() {
    confirmModal.show = false;
    confirmModal.resolve?.(false);
    confirmModal.resolve = null;
  }

  // ─── Дедлайн и cutoff
  const deadlineIso = ref(null);
  const cutoffIso = ref(null);
  const deadlinePassed = ref(false);
  const cutoffPassed = ref(false);
  let deadlineTimer = null;

  const deadlineFormatted = computed(() => fmtIsoLocal(deadlineIso.value));
  const cutoffFormatted = computed(() => fmtIsoLocal(cutoffIso.value));

  function startDeadlineWatch() {
    stopDeadlineWatch();
    if (!deadlineIso.value && !cutoffIso.value) return;
    const check = () => {
      if (deadlineIso.value) {
        deadlinePassed.value = Date.now() >= new Date(deadlineIso.value).getTime();
      }
      if (cutoffIso.value) {
        cutoffPassed.value = Date.now() >= new Date(cutoffIso.value).getTime();
      }
    };
    check();
    // Раз в минуту обновляем флаги — точность секунд не нужна.
    deadlineTimer = setInterval(check, 60000);
  }
  function stopDeadlineWatch() {
    if (deadlineTimer) { clearInterval(deadlineTimer); deadlineTimer = null; }
  }

  // Секция БСО видна, если есть, что показать: либо доступна замена, либо есть
  // история, либо окно замены уже закрылось (чтобы пояснить ресторану).
  const bsoSectionVisible = computed(() => {
    if (!form.status) return false;
    if (form.status === 'DRAFT' || form.status === 'CANCELLED') return false;
    if (form.can_replace_bso) return true;
    if ((form.bso_history || []).length) return true;
    if (cutoffPassed.value) return true;
    return false;
  });

  // ─── Замена БСО
  const bsoModal = reactive({ show: false, saving: false });
  function openBsoReplace() {
    bsoModal.saving = false;
    bsoModal.show = true;
  }
  function closeBsoReplace() {
    if (bsoModal.saving) return;
    bsoModal.show = false;
  }
  async function replaceBsoSubmit(payload) {
    bsoModal.saving = true;
    try {
      await roFetch(`/api/keg-returns/${localId.value}/replace-bso`, {
        method: 'POST',
        body: payload,
      });
      bsoModal.show = false;
      await loadFormData(localId.value);
      toast.success('Бланк заменён', `Новый номер: ${payload.new_series} ${payload.new_number}`);
    } catch (e) {
      toast.error('Не удалось заменить бланк', e.message || '');
    } finally {
      bsoModal.saving = false;
    }
  }

  // ─── Доступные даты возврата (зависят от маски будней + сохранённой даты)
  const availableDates = computed(() =>
    buildAvailableDates(form.restaurant_pickup_weekdays, form.return_date)
  );

  // ─── Валидаторы
  function validateWeekday() {
    const date = form.return_date;
    const mask = form.restaurant_pickup_weekdays;
    if (!date || !mask) return true;
    const d = new Date(date + 'T12:00:00');
    const jsDay = d.getDay();
    const bit = jsDay === 0 ? 6 : jsDay - 1;
    if (!(mask & (1 << bit))) {
      const allowed = WEEKDAY_NAMES.filter((_, i) => mask & (1 << i));
      fieldErr.return_date = true;
      toast.error('Неверный день недели', 'Возврат возможен в дни: ' + allowed.join(', '));
      return false;
    }
    fieldErr.return_date = false;
    return true;
  }

  function validateBso() {
    const s = (form.bso_series || '').trim();
    const n = (form.bso_number || '').trim();
    if (!s && !n) { fieldErr.bso = false; return true; }
    if (!/^[А-ЯЁ]{2}$/u.test(s)) {
      fieldErr.bso = true;
      toast.error('Серия ТТН', 'Две заглавные кириллические буквы, например «АА».');
      return false;
    }
    if (!/^\d{7}$/.test(n)) {
      fieldErr.bso = true;
      toast.error('Номер ТТН', 'Ровно 7 цифр.');
      return false;
    }
    fieldErr.bso = false;
    return true;
  }

  // ─── Computed-флаги
  const formReadonly = computed(() => {
    const s = form.status;
    if (s === 'ROUTED' || s === 'CANCELLED') return true;
    if (deadlinePassed.value && s === 'SUBMITTED') return true;
    return false;
  });

  const totalKegsCount = computed(() => {
    let s = 0;
    for (const v of Object.values(kegQties)) s += parseInt(v, 10) || 0;
    return s;
  });
  const totalKegsTypes = computed(() =>
    Object.values(kegQties).filter(v => (parseInt(v, 10) || 0) > 0).length
  );

  const submitReady = computed(() => {
    if (!form.return_date) return false;
    const s = (form.bso_series || '').trim();
    const n = (form.bso_number || '').trim();
    if (!/^[А-ЯЁ]{2}$/u.test(s)) return false;
    if (!/^\d{7}$/.test(n)) return false;
    if (!(form.sender_position_name || '').trim()) return false;
    if (totalKegsCount.value === 0) return false;
    return true;
  });

  const submitMissingHint = computed(() => {
    const missing = [];
    if (!form.return_date) missing.push('дату возврата');
    const s = (form.bso_series || '').trim();
    const n = (form.bso_number || '').trim();
    if (!/^[А-ЯЁ]{2}$/u.test(s) || !/^\d{7}$/.test(n)) missing.push('серию и номер ТТН');
    if (!(form.sender_position_name || '').trim()) missing.push('кто сдал');
    if (totalKegsCount.value === 0) missing.push('количество кег');
    if (!missing.length) return '';
    return 'Заполните: ' + missing.join(', ');
  });

  // ─── Загрузка данных
  function resetReactiveObject(obj) {
    for (const k of Object.keys(obj)) delete obj[k];
  }

  async function loadCatalog() {
    if (catalog.value.length) return;
    catalogLoading.value = true;
    try {
      const data = await roFetch('/api/keg-catalog');
      catalog.value = Array.isArray(data) ? data : [];
    } catch {}
    catalogLoading.value = false;
  }

  async function loadFormData(id) {
    formLoading.value = true;
    formError.value = '';
    try {
      const data = await roFetch(`/api/keg-returns/${id}`);
      resetReactiveObject(form);
      Object.assign(form, data);
      resetReactiveObject(kegQties);
      for (const item of data.items || []) {
        kegQties[item.keg_code] = item.quantity;
      }
      deadlineIso.value = data.deadline_iso || null;
      cutoffIso.value = data.cutoff_iso || null;
      deadlinePassed.value = false;
      cutoffPassed.value = false;
      fieldErr.return_date = false;
      fieldErr.bso = false;
      // Тикаем таймер, пока заявка ещё подвижная (DRAFT/SUBMITTED для дедлайна
      // или SUBMITTED/ROUTED для cutoff — чтобы во время сессии вовремя
      // переключилась видимость секции замены БСО).
      const movable = ['DRAFT', 'SUBMITTED', 'ROUTED'].includes(data.status);
      if (movable && (deadlineIso.value || cutoffIso.value)) {
        startDeadlineWatch();
      } else {
        stopDeadlineWatch();
      }
    } catch (e) {
      formError.value = e.message;
    } finally {
      formLoading.value = false;
    }
  }

  async function loadRestaurantInfo() {
    restaurantInfoLoaded.value = false;
    try {
      const data = await roFetch('/api/keg-returns/restaurant-info');
      if (data.pickup_weekdays) {
        form.restaurant_pickup_weekdays = data.pickup_weekdays;
      }
      if (data.pickup_address && !form.pickup_address) {
        form.pickup_address = data.pickup_address;
      }
    } catch {}
    restaurantInfoLoaded.value = true;
  }

  function initNewDraft() {
    resetReactiveObject(form);
    Object.assign(form, { return_date: '', bso_series: '', bso_number: '', sender_position_name: '' });
    resetReactiveObject(kegQties);
    fieldErr.return_date = false;
    fieldErr.bso = false;
    restaurantInfoLoaded.value = false;
    loadCatalog();
    loadRestaurantInfo();
  }

  // ─── Сборка тела запроса
  function buildBody() {
    const items = Object.entries(kegQties)
      .filter(([, qty]) => qty > 0)
      .map(([keg_code, quantity]) => ({ keg_code, quantity: Number(quantity) }));
    return {
      return_date: form.return_date,
      bso_series: form.bso_series,
      bso_number: form.bso_number,
      sender_position_name: form.sender_position_name,
      items,
    };
  }

  // ─── Действия
  async function saveDraft() {
    if (!validateBso()) return;
    if (!validateWeekday()) return;
    saving.value = true;
    try {
      if (localId.value) {
        await roFetch(`/api/keg-returns/${localId.value}`, { method: 'PATCH', body: buildBody() });
      } else {
        const data = await roFetch('/api/keg-returns', { method: 'POST', body: buildBody() });
        localId.value = data.id;
        await loadFormData(data.id);
      }
      toast.success('Сохранено', form.status === 'SUBMITTED' ? 'Изменения применены' : 'Черновик сохранён');
    } catch (e) {
      toast.error('Ошибка сохранения', e.message || '');
    } finally {
      saving.value = false;
    }
  }

  async function submit() {
    if (!validateBso()) return;
    if (!validateWeekday()) return;
    if (totalKegsCount.value === 0) {
      toast.error('Кеги не указаны', 'Укажите количество хотя бы по одной строке.');
      return;
    }
    const dateStr = form.return_date ? fmtDate(form.return_date) : '—';
    const ok = await askConfirm({
      title: 'Сформировать ТТН?',
      message: `Заявка на ${totalKegsCount.value} ${pluralKegs(totalKegsCount.value)} от ${dateStr}. Изменить состав можно только до дедлайна.`,
      okText: 'Сформировать',
      cancelText: 'Отмена',
    });
    if (!ok) return;
    saving.value = true;
    try {
      if (localId.value) {
        await roFetch(`/api/keg-returns/${localId.value}`, { method: 'PATCH', body: buildBody() });
      } else {
        const d = await roFetch('/api/keg-returns', { method: 'POST', body: buildBody() });
        localId.value = d.id;
      }
      await roFetch(`/api/keg-returns/${localId.value}/submit`, { method: 'POST' });
      await loadFormData(localId.value);
      submittedModal.bsoStr = ((form.bso_series || '') + ' ' + (form.bso_number || '')).trim();
      submittedModal.show = true;
    } catch (e) {
      toast.error('Ошибка отправки', e.message || '');
    } finally {
      saving.value = false;
    }
  }

  async function cancelReturn() {
    const ok = await askConfirm({
      title: 'Отменить заявку?',
      message: 'Заявка перейдёт в статус «Отменена» — её больше нельзя будет отправить.',
      okText: 'Отменить заявку',
      cancelText: 'Не отменять',
      danger: true,
    });
    if (!ok) return;
    saving.value = true;
    try {
      await roFetch(`/api/keg-returns/${localId.value}/cancel`, { method: 'POST' });
      await loadFormData(localId.value);
      toast.success('Заявка отменена', '');
    } catch (e) {
      toast.error('Не удалось отменить', e.message || '');
    } finally {
      saving.value = false;
    }
  }

  async function deleteDraft() {
    const ok = await askConfirm({
      title: 'Удалить черновик?',
      message: 'Это нельзя отменить.',
      okText: 'Удалить',
      cancelText: 'Не удалять',
      danger: true,
    });
    if (!ok) return;
    saving.value = true;
    try {
      await roFetch(`/api/keg-returns/${localId.value}`, { method: 'DELETE' });
      toast.success('Черновик удалён', '');
      emit?.('deleted');
    } catch (e) {
      toast.error('Не удалось удалить', e.message || '');
    } finally {
      saving.value = false;
    }
  }

  async function checkRoutedWarning() {
    const status = form.status;
    // SUBMITTED — нормальная ситуация: печатаем именно сейчас, чтобы проверить
    // бланк БСО до дедлайна. После маршрутизации заново не печатаем — водителя
    // и машину впишем от руки на этом же бланке.
    if (status === 'SUBMITTED') {
      return await askConfirm({
        title: 'Распечатать ТТН на бланке',
        message: 'Печатайте сейчас, до дедлайна — чтобы убедиться, что бланк не испорчен и номер совпадает с фактом. После маршрутизации водителя и машину впишите ОТ РУКИ на этот же бланк (заново не печатаем).',
        okText: 'Распечатать',
        cancelText: 'Отмена',
      });
    }
    if (status !== 'ROUTED') {
      return await askConfirm({
        title: 'Заявка не сформирована',
        message: 'Заявка ещё в статусе черновика. Сначала нажмите «Сформировать ТТН».',
        okText: 'Хорошо',
        cancelText: 'Отмена',
      });
    }
    return true;
  }

  async function downloadExcel() {
    if (!await checkRoutedWarning()) return;
    try {
      const res = await fetch(`/api/keg-returns/${localId.value}/excel`);
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.error || 'Ошибка скачивания');
      }
      const blob = await res.blob();
      const cd = res.headers.get('Content-Disposition') || '';
      const m = cd.match(/filename="?([^"]+)"?/);
      const filename = m ? m[1] : `TTN_${localId.value}.xlsx`;
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url; a.download = filename;
      document.body.appendChild(a); a.click(); a.remove();
      URL.revokeObjectURL(url);
    } catch (e) {
      toast.error('Ошибка скачивания', e.message || '');
    }
  }

  async function printTtn() {
    if (!await checkRoutedWarning()) return;
    // Cookie ro_session уходит в новую вкладку автоматически (same-origin).
    window.open(`/api/keg-returns/${localId.value}/print`, '_blank');
  }

  // ─── Жизненный цикл
  onMounted(() => {
    if (localId.value) {
      loadFormData(localId.value);
      loadCatalog();
    } else {
      initNewDraft();
    }
  });

  // Если родитель сменил initialId (например, из списка открыли другую заявку
  // без закрытия формы) — перезагружаем данные.
  watch(initialIdRef, (newId) => {
    localId.value = newId || null;
    if (newId) {
      loadFormData(newId);
      loadCatalog();
    } else {
      initNewDraft();
    }
  });

  onUnmounted(stopDeadlineWatch);

  return {
    // state
    localId, form, kegQties, catalog,
    formLoading, formError, catalogLoading, saving, restaurantInfoLoaded,
    fieldErr,
    deadlineIso, cutoffIso, deadlinePassed, cutoffPassed,
    // computed
    deadlineFormatted, cutoffFormatted, bsoSectionVisible,
    availableDates, formReadonly,
    totalKegsCount, totalKegsTypes,
    submitReady, submitMissingHint,
    // modals state
    photoModal, submittedModal, confirmModal, bsoModal,
    // modal handlers
    openPhoto, closePhoto,
    closeSubmitted, onSubmittedPrint,
    confirmOk, confirmCancel,
    openBsoReplace, closeBsoReplace, replaceBsoSubmit,
    // actions
    saveDraft, submit, cancelReturn, deleteDraft,
    downloadExcel, printTtn,
  };
}
