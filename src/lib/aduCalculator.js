import { db } from './apiClient.js';

/**
 * Загрузить данные ADU из product_adu для списка SKU.
 * Возвращает Map<sku, { adu, cv, sample_count, last_order_date }>
 */
export async function loadAduData(skus, legalEntity) {
  if (!skus.length || !legalEntity) return new Map();
  const { data, error } = await db
    .from('product_adu')
    .select('sku, adu, cv, sample_count, last_order_date')
    .eq('legal_entity', legalEntity)
    .in('sku', skus);
  if (error || !data) return new Map();
  return new Map(data.map(d => [d.sku, {
    adu: parseFloat(d.adu) || 0,
    cv: parseFloat(d.cv) || 0,
    sampleCount: parseInt(d.sample_count) || 0,
    lastOrderDate: d.last_order_date,
  }]));
}

/**
 * Запустить пересчёт ADU на сервере.
 */
export async function recalculateAdu(legalEntity, supplier, lookbackDays = 90) {
  const params = { legal_entity: legalEntity, lookback_days: lookbackDays };
  if (supplier) params.supplier = supplier;
  const { data, error } = await db.rpc('calculate_adu', params);
  if (error) throw new Error(error.message || error);
  return data;
}

/**
 * Конвертировать ADU (шт/день) в расход за период в нужных единицах.
 * ADU всегда хранится в штуках/день.
 * Для медленнооборачиваемых товаров (ADU=0.1 шт/день) расход за месяц
 * в коробках бывает <1 — округление до целого обнулит позицию. Поэтому
 * для unit='boxes' храним один знак после запятой.
 */
export function aduToConsumption(adu, periodDays, unit, qtyPerBox) {
  const totalPieces = adu * periodDays;
  if (unit === 'boxes') {
    const boxes = totalPieces / (qtyPerBox || 1);
    return Math.round(boxes * 10) / 10;
  }
  return Math.round(totalPieces);
}
