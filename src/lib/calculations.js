import { daysBetween, roundUp, safeDivide, getQpb, getMultiplicity } from './utils.js';

export function calculateItem(item, settings) {
  const { today, deliveryDate, periodDays, safetyDays, unit } = settings;

  if (!today || !deliveryDate || !(today instanceof Date) || !(deliveryDate instanceof Date) || isNaN(today) || isNaN(deliveryDate)) {
    return { calculatedOrder: 0, coverageDate: null, palletsInfo: null };
  }

  const transitDays = daysBetween(today, deliveryDate);
  const daily = safeDivide(item.consumptionPeriod || 0, periodDays || 30);

  const consumedBeforeDelivery = daily * transitDays;
  const totalStock = (item.stock || 0) + (item.transit || 0);
  const stockAfterDelivery = Math.max(0, totalStock - consumedBeforeDelivery);
  const needAfterDelivery = daily * safetyDays;

  let calculatedOrder = needAfterDelivery - stockAfterDelivery;
  if (calculatedOrder < 0) calculatedOrder = 0;

  const mult = getMultiplicity(item);
  const qpb = getQpb(item);

  if (unit === 'boxes') {
    calculatedOrder = roundUp(calculatedOrder / mult) * mult;
  } else {
    const physBoxInPieces = qpb * mult;
    if (physBoxInPieces > 0) {
      calculatedOrder = roundUp(calculatedOrder / physBoxInPieces) * physBoxInPieces;
    }
  }

  const availableAfterDelivery = stockAfterDelivery + (item.finalOrder || 0);
  const daysAfterDelivery = safeDivide(availableAfterDelivery, daily);

  let coverageDate = null;
  if (daily > 0 && daysAfterDelivery > 0) {
    coverageDate = new Date(deliveryDate);
    coverageDate.setDate(coverageDate.getDate() + daysAfterDelivery);
  }

  return {
    calculatedOrder,
    coverageDate,
    palletsInfo: calculatePallets(item, unit),
  };
}

function calculatePallets(item, unit) {
  if (!item.boxesPerPallet || !item.finalOrder) return null;

  const qpb = getQpb(item);
  const mult = getMultiplicity(item);

  const physBoxes =
    unit === 'boxes'
      ? item.finalOrder / mult
      : item.finalOrder / (qpb * mult);

  return {
    pallets: Math.floor(physBoxes / item.boxesPerPallet),
    boxesLeft: Math.ceil(physBoxes % item.boxesPerPallet),
  };
}
