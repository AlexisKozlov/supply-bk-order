/**
 * Модуль проверки данных расхода
 * Подсветка аномального расхода на основе истории заказов
 * Работает и в блоке заказа, и в планировании
 */

import { orderState } from './state.js';
import { supabase } from './supabase.js';

let consumptionCache = null;
const nf = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 });

export function resetConsumptionCache() {
  consumptionCache = null;
}

/**
 * Загрузка средних значений расхода из истории заказов
 * @param {string} supplier — поставщик
 * @param {string} legalEntity — юр. лицо
 * @param {string} currentUnit — текущие единицы ('pieces' | 'boxes')
 */
async function loadConsumptionHistory(supplier, legalEntity, currentUnit) {
  // Кеш: supplier + unit + legalEntity
  if (consumptionCache 
      && consumptionCache.supplier === supplier 
      && consumptionCache.unit === currentUnit
      && consumptionCache.legalEntity === legalEntity) {
    return consumptionCache.data;
  }

  const entity = legalEntity || orderState.settings.legalEntity || 'Бургер БК';
  const { data, error } = await supabase
    .from('orders')
    .select('unit, order_items(sku, consumption_period, qty_per_box)')
    .eq('legal_entity', entity)
    .eq('supplier', supplier)
    .order('created_at', { ascending: false })
    .limit(2);

  const avgMap = new Map();

  if (!error && data) {
    const bySku = {};
    data.forEach(order => {
      const orderUnit = order.unit || 'pieces';
      (order.order_items || []).forEach(item => {
        if (!item.sku || !item.consumption_period) return;
        let val = item.consumption_period;
        const qtyPerBox = item.qty_per_box || 1;

        // Нормализуем к текущим единицам
        if (orderUnit === 'pieces' && currentUnit === 'boxes') {
          val = val / qtyPerBox;
        } else if (orderUnit === 'boxes' && currentUnit === 'pieces') {
          val = val * qtyPerBox;
        }

        if (!bySku[item.sku]) bySku[item.sku] = [];
        bySku[item.sku].push(val);
      });
    });
    Object.entries(bySku).forEach(([sku, vals]) => {
      avgMap.set(sku, vals.reduce((a, b) => a + b, 0) / vals.length);
    });
  }

  consumptionCache = { supplier, legalEntity: entity, data: avgMap, unit: currentUnit };
  return avgMap;
}

/**
 * Проверка расхода в блоке ЗАКАЗА
 */
export async function validateConsumptionData(tbody) {
  const supplier = orderState.settings.supplier;
  if (!supplier) return;
  if (document.getElementById('dataValidation')?.value !== 'true') return;

  const avgMap = await loadConsumptionHistory(supplier, orderState.settings.legalEntity, orderState.settings.unit);
  if (!avgMap.size) return;

  const rows = tbody.querySelectorAll('tr');
  orderState.items.forEach((item, idx) => {
    const row = rows[idx];
    if (!row) return;
    const consumptionInput = row.querySelector('input');
    if (!consumptionInput) return;

    if (!item.sku || !item.consumptionPeriod) {
      consumptionInput.classList.remove('consumption-warning');
      consumptionInput.title = '';
      return;
    }

    const avg = avgMap.get(item.sku);
    if (!avg) {
      consumptionInput.classList.remove('consumption-warning');
      consumptionInput.title = '';
      return;
    }

    const deviation = Math.abs(item.consumptionPeriod - avg) / avg;

    if (deviation > 0.30) {
      consumptionInput.classList.add('consumption-warning');
      consumptionInput.title = `⚠️ Расход сильно отличается от среднего (${nf.format(Math.round(avg))}), проверьте данные`;
    } else {
      consumptionInput.classList.remove('consumption-warning');
      consumptionInput.title = '';
    }
  });
}

/**
 * Проверка расхода в блоке ПЛАНИРОВАНИЯ
 * @param {Object} planState — состояние планирования { supplier, legalEntity, inputUnit, items[] }
 * @param {HTMLElement} container — контейнер таблицы планирования
 */
export async function validatePlanConsumption(planState, container) {
  if (!planState.supplier) return;
  
  const validationSelect = document.getElementById('planDataValidation');
  if (validationSelect && validationSelect.value !== 'true') return;

  // Для планирования расход вводится за месяц
  // В истории заказов — расход за период (обычно 30 дней)
  // Нормализуем: считаем среднемесячный из истории
  const currentUnit = planState.inputUnit || 'pieces';
  const avgMap = await loadConsumptionHistory(planState.supplier, planState.legalEntity, currentUnit);
  if (!avgMap.size) return;

  planState.items.forEach((item, idx) => {
    const input = container.querySelector(`.plan-consumption[data-idx="${idx}"]`);
    if (!input) return;

    if (!item.sku || !item.monthlyConsumption) {
      input.classList.remove('consumption-warning');
      input.title = '';
      return;
    }

    const avg = avgMap.get(item.sku);
    if (!avg) {
      input.classList.remove('consumption-warning');
      input.title = '';
      return;
    }

    const deviation = Math.abs(item.monthlyConsumption - avg) / avg;

    if (deviation > 0.30) {
      input.classList.add('consumption-warning');
      input.title = `⚠️ Расход отличается от среднего по заказам (${nf.format(Math.round(avg))}), проверьте данные`;
    } else {
      input.classList.remove('consumption-warning');
      input.title = '';
    }
  });
}