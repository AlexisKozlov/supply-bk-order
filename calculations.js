import { daysBetween, roundUp, safeDivide } from './utils.js';

export function calculateItem(item, settings) {
  const {
    today,
    deliveryDate,
    periodDays,
    safetyDays,
    unit
  } = settings;

  if (!today || !deliveryDate) {
    return {
      calculatedOrder: 0,
      coverageDate: null,
      palletsInfo: null
    };
  }

  const transitDays = daysBetween(today, deliveryDate);
  const daily = safeDivide(item.consumptionPeriod, periodDays);

  const baseNeed =
    daily * transitDays +
    daily * safetyDays;

  // Используем индивидуальный процент запаса для каждого товара
  const safetyPercent = item.safetyPercent || 0;
  const totalNeed =
    baseNeed + baseNeed * (safetyPercent / 100);

  // Учитываем транзит как дополнительный остаток
  const totalStock = item.stock + (item.transit || 0);
  let calculatedOrder = totalNeed - totalStock;
  if (calculatedOrder < 0) calculatedOrder = 0;

  /* ===== ОКРУГЛЕНИЕ ===== */
  if (unit === 'boxes') {
    calculatedOrder = roundUp(calculatedOrder);
  }

  if (unit === 'pieces' && item.qtyPerBox) {
    calculatedOrder =
      roundUp(calculatedOrder / item.qtyPerBox) * item.qtyPerBox;
  }

  const available = totalStock + (item.finalOrder || 0);
  const days = safeDivide(available, daily);

  const coverageDate =
    daily > 0
      ? new Date(today.getTime() + days * 86400000)
      : null;

  return {
    calculatedOrder,
    coverageDate,
    palletsInfo: calculatePallets(item, unit)
  };
}

function calculatePallets(item, unit) {
  if (!item.boxesPerPallet || !item.finalOrder) return null;

  const boxes =
    unit === 'boxes'
      ? item.finalOrder
      : item.finalOrder / item.qtyPerBox;

  return {
    pallets: Math.floor(boxes / item.boxesPerPallet),
    boxesLeft: Math.ceil(boxes % item.boxesPerPallet)
  };
}