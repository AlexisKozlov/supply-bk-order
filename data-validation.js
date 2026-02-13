/**
 * Модуль проверки данных расхода
 * Подсветка аномального расхода на основе истории заказов
 */

import { orderState } from './state.js';
import { supabase } from './supabase.js';

let consumptionCache = null;
const nf = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 });

export function resetConsumptionCache() {
  consumptionCache = null;
}

async function loadConsumptionHistory(supplier) {
  if (consumptionCache && consumptionCache.supplier === supplier && consumptionCache.unit === orderState.settings.unit) {
    return consumptionCache.data;
  }

  const legalEntity = orderState.settings.legalEntity || 'Бургер БК';
  const { data, error } = await supabase
    .from('orders')
    .select('unit, order_items(sku, consumption_period, qty_per_box)')
    .eq('legal_entity', legalEntity)
    .eq('supplier', supplier)
    .order('created_at', { ascending: false })
    .limit(2);

  const avgMap = new Map();
  const currentUnit = orderState.settings.unit;

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
          val = val / qtyPerBox; // штуки → коробки
        } else if (orderUnit === 'boxes' && currentUnit === 'pieces') {
          val = val * qtyPerBox; // коробки → штуки
        }

        if (!bySku[item.sku]) bySku[item.sku] = [];
        bySku[item.sku].push(val);
      });
    });
    Object.entries(bySku).forEach(([sku, vals]) => {
      avgMap.set(sku, vals.reduce((a, b) => a + b, 0) / vals.length);
    });
  }

  consumptionCache = { supplier, data: avgMap, unit: currentUnit };
  return avgMap;
}

export async function validateConsumptionData(tbody) {
  const supplier = orderState.settings.supplier;
  if (!supplier) return;
  if (document.getElementById('dataValidation')?.value !== 'true') return;

  const avgMap = await loadConsumptionHistory(supplier);
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
