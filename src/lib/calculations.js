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
  const stockAfterDelivery = totalStock - consumedBeforeDelivery;
  // stockAfterDelivery < 0 означает дефицит — его нужно компенсировать заказом
  const needAfterDelivery = daily * safetyDays;

  let calculatedOrder = needAfterDelivery - stockAfterDelivery;
  if (calculatedOrder < 0) calculatedOrder = 0;

  const mult = getMultiplicity(item) || 1;
  const qpb = getQpb(item) || 1;

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
    coverageDate.setDate(coverageDate.getDate() + Math.floor(daysAfterDelivery));
  }

  return {
    calculatedOrder,
    coverageDate,
    palletsInfo: calculatePallets(item, unit),
  };
}

/**
 * Буферный расчёт CDA.
 * cdaParams = { adu, cv, dlt, doc, safetyCoef }
 * adu — суточный расход (шт/день)
 * cv — коэффициент вариации (0..1+)
 * dlt — срок доставки (дней)
 * doc — частота заказа (дней)
 * safetyCoef — коэффициент безопасности (по умолчанию 1.0)
 */
export function calculateBufferItem(item, settings, cdaParams) {
  const { today, deliveryDate, unit } = settings;
  const { adu, cv, dlt, doc, safetyCoef = 1.0 } = cdaParams;

  if (!today || !deliveryDate || !(today instanceof Date) || !(deliveryDate instanceof Date) || isNaN(today) || isNaN(deliveryDate)) {
    return { calculatedOrder: 0, coverageDate: null, palletsInfo: null, buffer: null };
  }

  const qpb = getQpb(item) || 1;
  const mult = getMultiplicity(item) || 1;

  // ADU в текущих единицах (шт или коробки)
  const aduUnit = unit === 'boxes' ? adu / qpb : adu;

  // Зоны буфера (в текущих единицах)
  const green = aduUnit * doc;
  const cappedCv = Math.min(cv, 1.0);
  const yellow = green * cappedCv;
  const red = aduUnit * dlt * safetyCoef;
  const bufferTotal = green + yellow + red;

  // Остаток к приходу
  const transitDays = daysBetween(today, deliveryDate);
  const daily = safeDivide(item.consumptionPeriod || 0, (settings.periodDays || 30));
  const consumedBeforeDelivery = daily * transitDays;
  const totalStock = (item.stock || 0) + (item.transit || 0);
  const stockAfterDelivery = totalStock - consumedBeforeDelivery;

  // Заказ = буфер − остаток к приходу
  let calculatedOrder = bufferTotal - stockAfterDelivery;
  if (calculatedOrder < 0) calculatedOrder = 0;

  // Округление по кратности
  if (unit === 'boxes') {
    calculatedOrder = roundUp(calculatedOrder / mult) * mult;
  } else {
    const physBoxInPieces = qpb * mult;
    if (physBoxInPieces > 0) {
      calculatedOrder = roundUp(calculatedOrder / physBoxInPieces) * physBoxInPieces;
    }
  }

  // Дата покрытия
  const availableAfterDelivery = stockAfterDelivery + (item.finalOrder || 0);
  const daysAfterDelivery = daily > 0 ? availableAfterDelivery / daily : 0;

  let coverageDate = null;
  if (daily > 0 && daysAfterDelivery > 0) {
    coverageDate = new Date(deliveryDate);
    coverageDate.setDate(coverageDate.getDate() + Math.floor(daysAfterDelivery));
  }

  return {
    calculatedOrder,
    coverageDate,
    palletsInfo: calculatePallets(item, unit),
    buffer: {
      green: Math.round(green * 10) / 10,
      yellow: Math.round(yellow * 10) / 10,
      red: Math.round(red * 10) / 10,
      total: Math.round(bufferTotal * 10) / 10,
    },
  };
}

function calculatePallets(item, unit) {
  if (!item.boxesPerPallet || !item.finalOrder) return null;

  const qpb = getQpb(item) || 1;
  const mult = getMultiplicity(item) || 1;

  // Сначала учётные коробки, затем физические (= учётные / кратность)
  const accountingBoxes = Math.round(
    unit === 'boxes'
      ? item.finalOrder
      : item.finalOrder / qpb
  );
  const physBoxes = Math.round(accountingBoxes / mult);

  return {
    pallets: Math.floor(physBoxes / item.boxesPerPallet),
    boxesLeft: physBoxes % item.boxesPerPallet,
  };
}
