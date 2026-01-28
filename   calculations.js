import { daysBetween, roundUp, safeDivide } from './utils.js';

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

  const transitDays = daysBetween(today, deliveryDate);

  const dailyConsumption =
    safeDivide(item.consumptionPeriod, periodDays);

  const consumptionBeforeDelivery =
    dailyConsumption * transitDays;

  const safetyByDays =
    dailyConsumption * safetyDays;

  const baseNeed =
    consumptionBeforeDelivery + safetyByDays;

  const safetyByPercent =
    baseNeed * (safetyPercent / 100);

  let calculatedOrder =
    baseNeed + safetyByPercent - item.stock;

  if (calculatedOrder < 0) calculatedOrder = 0;
  if (unit === 'boxes') calculatedOrder = roundUp(calculatedOrder);

  const totalAvailable =
    item.stock + (item.finalOrder || 0);

  const daysCoverage =
    safeDivide(totalAvailable, dailyConsumption);

  const coverageDate =
    dailyConsumption > 0
      ? new Date(today.getTime() + daysCoverage * 86400000)
      : null;

  return {
    ...item,
    calculatedOrder,
    coverageDate,
    palletsInfo: calculatePallets(item, unit)
  };
}

function calculatePallets(item, unit) {
  if (
    unit !== 'boxes' ||
    !item.boxesPerPallet ||
    !item.finalOrder
  ) return null;

  return {
    pallets: Math.floor(item.finalOrder / item.boxesPerPallet),
    boxesLeft: item.finalOrder % item.boxesPerPallet
  };
}
