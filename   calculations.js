// js/calculations.js

import { daysBetween, roundUp, safeDivide } from './utils.js';

/**
 * Основной перерасчёт одного товара
 */
export function calculateItem(item, settings) {
  const {
    today,
    deliveryDate,
    periodDays,
    safetyDays,
    safetyPercent,
    unit
  } = settings;

  if (!today || !deliveryDate) return item;

  // 1️⃣ Дни до поставки
  const transitDays = daysBetween(today, deliveryDate);

  // 2️⃣ Дневной расход
  const dailyConsumption =
    safeDivide(item.consumptionPeriod, periodDays);

  // 3️⃣ Расход до поставки
  const consumptionBeforeDelivery =
    dailyConsumption * transitDays;

  // 4️⃣ Страховой запас (дни)
  const safetyByDays =
    dailyConsumption * safetyDays;

  // 5️⃣ Базовая потребность
  const baseNeed =
    consumptionBeforeDelivery + safetyByDays;

  // 6️⃣ Страховой запас (%)
  const safetyByPercent =
    baseNeed * (safetyPercent / 100);

  // 7️⃣ Итоговая потребность
  const totalNeed =
    baseNeed + safetyByPercent;

  // 8️⃣ Расчётный заказ
  let calculatedOrder =
    totalNeed - item.stock;

  if (calculatedOrder < 0) calculatedOrder = 0;

  if (unit === 'boxes') {
    calculatedOrder = roundUp(calculatedOrder);
  }

  // 9️⃣ До какого числа хватит (по ЗАКАЗ ИТОГО)
  const totalAvailable =
    item.stock + (item.finalOrder || 0);

  const daysCoverage =
    safeDivide(totalAvailable, dailyConsumption);

  const coverageDate = dailyConsumption > 0
    ? new Date(today.getTime() + daysCoverage * 86400000)
    : null;

  return {
    ...item,
    calculatedOrder,
    coverageDate,
    palletsInfo: calculatePallets(item, unit)
  };
}

/**
 * Паллеты (справочно)
 */
function calculatePallets(item, unit) {
  if (
    unit !== 'boxes' ||
    !item.boxesPerPallet ||
    !item.finalOrder
  ) {
    return null;
  }

  const pallets = Math.floor(
    item.finalOrder / item.boxesPerPallet
  );

  const boxesLeft =
    item.finalOrder % item.boxesPerPallet;

  return { pallets, boxesLeft };
}