/**
 * Модуль проверки данных расхода
 * Подсветка аномального расхода на основе истории заказов
 * Работает и в блоке заказа, и в планировании
 */

import { orderState } from './state.js';
import { supabase } from './supabase.js';
import { getQpb } from './utils.js';

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
async function loadConsumptionHistory(supplier, legalEntity, currentUnit, multiplicityMap = null) {
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
    .select('*, order_items(sku, consumption_period, qty_per_box)')
    .eq('legal_entity', entity)
    .eq('supplier', supplier)
    .order('created_at', { ascending: false })
    .limit(2);

  const avgMap = new Map();

  if (error || !data || !data.length) {
    console.warn('data-validation: нет заказов', { supplier, entity, error });
    consumptionCache = { supplier, legalEntity: entity, data: avgMap, unit: currentUnit };
    return avgMap;
  }

  const bySku = {};
  data.forEach(order => {
    const orderUnit = order.unit || 'pieces';
    const periodDays = order.period_days || 30;
    
    (order.order_items || []).forEach(item => {
      if (!item.sku || !item.consumption_period) return;
      let val = item.consumption_period;
      // Используем multiplicity из текущих товаров (если есть), иначе qty_per_box из заказа
      const effectiveQpb = (multiplicityMap && multiplicityMap.get(item.sku)) || item.qty_per_box || 1;

      // Нормализуем к месячному расходу (30 дней)
      const dailyRate = val / periodDays;
      let monthlyVal = dailyRate * 30;

      // Конвертируем в текущие единицы
      if (orderUnit === 'pieces' && currentUnit === 'boxes') {
        monthlyVal = monthlyVal / effectiveQpb;
      } else if (orderUnit === 'boxes' && currentUnit === 'pieces') {
        monthlyVal = monthlyVal * effectiveQpb;
      }

      if (!bySku[item.sku]) bySku[item.sku] = [];
      bySku[item.sku].push(monthlyVal);
    });
  });
  Object.entries(bySku).forEach(([sku, vals]) => {
    avgMap.set(sku, vals.reduce((a, b) => a + b, 0) / vals.length);
  });

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

  // Строим карту qtyPerBox из текущих товаров заказа
  const qpbMap = new Map();
  orderState.items.forEach(item => {
    if (item.sku) qpbMap.set(item.sku, getQpb(item));
  });

  const avgMap = await loadConsumptionHistory(supplier, orderState.settings.legalEntity, orderState.settings.unit, qpbMap);
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

  const currentUnit = planState.inputUnit || 'pieces';
  
  // Строим карту multiplicity из текущих товаров планирования
  const multiplicityMap = new Map();
  planState.items.forEach(item => {
    if (item.sku) multiplicityMap.set(item.sku, getQpb(item));
  });
  
  const avgMap = await loadConsumptionHistory(planState.supplier, planState.legalEntity, currentUnit, multiplicityMap);
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