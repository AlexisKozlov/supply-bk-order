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
 */
export function aduToConsumption(adu, periodDays, unit, qtyPerBox) {
  const totalPieces = adu * periodDays;
  if (unit === 'boxes') {
    return Math.round(totalPieces / (qtyPerBox || 1));
  }
  return Math.round(totalPieces);
}
