/**
 * Модуль управления черновиком заказа
 * Автосохранение, загрузка и очистка черновика
 */

import { orderState } from './state.js';
import { showToast } from './modals.js';

let saveDraftTimer = null;
export let isLoadingDraft = false;

export function saveDraft() {
  // Debounce — не чаще 1 раза в 500мс
  clearTimeout(saveDraftTimer);
  saveDraftTimer = setTimeout(() => {
    const draft = {
      settings: orderState.settings,
      items: orderState.items,
      timestamp: new Date().toISOString()
    };
    localStorage.setItem('bk_draft', JSON.stringify(draft));
  }, 500);
}

export function clearDraft() {
  localStorage.removeItem('bk_draft');
}

export async function loadDraft(deps) {
  const { loadSuppliers, safetyStockManager, restoreItemOrder, render, updateEntityBadge, orderSection } = deps;

  const draft = localStorage.getItem('bk_draft');
  if (!draft) return false;

  try {
    const data = JSON.parse(draft);

    // Устанавливаем флаг чтобы не срабатывало событие change поставщика
    isLoadingDraft = true;

    // Восстановление настроек
    if (data.settings.today) {
      orderState.settings.today = new Date(data.settings.today);
      document.getElementById('today').value = orderState.settings.today.toISOString().slice(0, 10);
    }
    if (data.settings.deliveryDate) {
      orderState.settings.deliveryDate = new Date(data.settings.deliveryDate);
      document.getElementById('deliveryDate').value = orderState.settings.deliveryDate.toISOString().slice(0, 10);
    }
    if (data.settings.safetyEndDate) {
      orderState.settings.safetyEndDate = new Date(data.settings.safetyEndDate);
    }
    orderState.settings.legalEntity = data.settings.legalEntity || 'Бургер БК';
    orderState.settings.supplier = data.settings.supplier || '';
    orderState.settings.periodDays = data.settings.periodDays || 30;
    orderState.settings.safetyDays = data.settings.safetyDays || 0;
    orderState.settings.unit = data.settings.unit || 'pieces';
    orderState.settings.hasTransit = data.settings.hasTransit || false;
    orderState.settings.showStockColumn = data.settings.showStockColumn || false;

    document.getElementById('legalEntity').value = orderState.settings.legalEntity;

    // Загружаем поставщиков для юр.лица, затем устанавливаем значение
    await loadSuppliers(orderState.settings.legalEntity);
    document.getElementById('supplierFilter').value = orderState.settings.supplier;

    document.getElementById('periodDays').value = orderState.settings.periodDays;

    // Устанавливаем товарный запас
    if (safetyStockManager) {
      // ВАЖНО: сначала передаём дату поставки, потом дни запаса
      if (orderState.settings.deliveryDate) {
        safetyStockManager.setDeliveryDate(orderState.settings.deliveryDate);
      }
      if (orderState.settings.safetyEndDate) {
        // Если сохранена конечная дата — восстанавливаем через неё
        safetyStockManager.endDate = orderState.settings.safetyEndDate;
        safetyStockManager.calculateDays();
        safetyStockManager.formatDisplay();
        orderState.settings.safetyDays = safetyStockManager.getDays();
      } else {
        safetyStockManager.setDays(orderState.settings.safetyDays);
      }
    }

    document.getElementById('unit').value = orderState.settings.unit;
    document.getElementById('hasTransit').value = orderState.settings.hasTransit ? 'true' : 'false';
    document.getElementById('showStockColumn').value = orderState.settings.showStockColumn ? 'true' : 'false';

    // Восстановление товаров
    orderState.items = data.items || [];

    // Сбрасываем флаг
    isLoadingDraft = false;
    updateEntityBadge();

    if (orderState.items.length > 0) {
      orderSection.classList.remove('hidden');

      // Восстанавливаем порядок из Supabase
      await restoreItemOrder();

      render();

      const draftDate = new Date(data.timestamp).toLocaleString('ru-RU');
      showToast('Черновик загружен', `Восстановлено из ${draftDate}`, 'info');
      return true;
    }

  } catch (e) {
    isLoadingDraft = false;
    console.error('Ошибка загрузки черновика:', e);
  }

  return false;
}
