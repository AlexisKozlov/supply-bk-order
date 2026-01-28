import { daysBetween, roundUp, safeDivide } from './utils.js';

export function calculateItem(item, settings) {
  const { today, deliveryDate, periodDays, safetyDays, safetyPercent, unit } = settings;

  if (!today || !deliveryDate) {
    return { ...item, calculatedOrder: 0, coverageDate: null, palletsInfo: null };
  }

  const transitDays = daysBetween(today, deliveryDate);
  const daily = safeDivide(item.consumptionPeriod, periodDays);

  const need =
    daily * transitDays +
    daily * safetyDays;

  const totalNeed = need + need * (safetyPercent / 100);

  let calculatedOrder = totalNeed - item.stock;
  if (calculatedOrder < 0) calculatedOrder = 0;
  if (unit === 'boxes') calculatedOrder = roundUp(calculatedOrder);

  const available = item.stock + (item.finalOrder || 0);
  const days = safeDivide(available, daily);
  const coverageDate = daily ? new Date(today.getTime() + days * 86400000) : null;

  return {
    ...item,
    calculatedOrder,
    coverageDate,
    palletsInfo: calculatePallets(item, unit)
  };
}

function calculatePallets(item, unit) {
  if (!item.boxesPerPallet || !item.finalOrder) return null;

  let boxes = unit === 'boxes'
    ? item.finalOrder
    : item.finalOrder / item.qtyPerBox;

  return {
    pallets: Math.floor(boxes / item.boxesPerPallet),
    boxesLeft: Math.ceil(boxes % item.boxesPerPallet)
  };
}
