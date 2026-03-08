import { daysBetween, roundUp, safeDivide, getQpb, getMultiplicity } from './utils.js';

function validateDates(today, deliveryDate) {
  return today && deliveryDate && today instanceof Date && deliveryDate instanceof Date && !isNaN(today) && !isNaN(deliveryDate);
}

function getItemMultipliers(item) {
  const qpb = getQpb(item) || 1;
  const mult = getMultiplicity(item) || 1;
  return { qpb, mult, valid: qpb > 0 && mult > 0 };
}

function roundByMultiplicity(order, unit, qpb, mult) {
  if (unit === 'boxes') {
    return roundUp(order / mult) * mult;
  }
  const physBoxInPieces = qpb * mult;
  if (physBoxInPieces > 0) {
    return roundUp(order / physBoxInPieces) * physBoxInPieces;
  }
  return order;
}

function calcCoverageDate(deliveryDate, stockAfterDelivery, finalOrder, daily) {
  const availableAfterDelivery = stockAfterDelivery + (finalOrder || 0);
  const daysAfterDelivery = daily > 0 ? availableAfterDelivery / daily : 0;
  if (daily > 0 && daysAfterDelivery > 0) {
    const coverageDate = new Date(deliveryDate);
    coverageDate.setDate(coverageDate.getDate() + Math.floor(daysAfterDelivery));
    return coverageDate;
  }
  return null;
}

function calcStockAfterDelivery(item, settings) {
  const transitDays = daysBetween(settings.today, settings.deliveryDate);
  const daily = safeDivide(item.consumptionPeriod || 0, settings.periodDays || 30);
  const consumedBeforeDelivery = daily * transitDays;
  const totalStock = (item.stock || 0) + (item.transit || 0);
  return { daily, stockAfterDelivery: totalStock - consumedBeforeDelivery };
}

export function calculateItem(item, settings) {
  const { today, deliveryDate, safetyDays, unit } = settings;

  if (!validateDates(today, deliveryDate)) {
    return { calculatedOrder: 0, coverageDate: null, palletsInfo: null };
  }

  const { qpb, mult, valid } = getItemMultipliers(item);
  if (!valid) return { calculatedOrder: 0, coverageDate: null, palletsInfo: null };

  const { daily, stockAfterDelivery } = calcStockAfterDelivery(item, settings);
  const needAfterDelivery = daily * (safetyDays || 0);

  let calculatedOrder = needAfterDelivery - stockAfterDelivery;
  if (calculatedOrder < 0) calculatedOrder = 0;
  calculatedOrder = roundByMultiplicity(calculatedOrder, unit, qpb, mult);

  return {
    calculatedOrder,
    coverageDate: calcCoverageDate(deliveryDate, stockAfterDelivery, item.finalOrder, daily),
    palletsInfo: calculatePallets(item, unit),
  };
}

/**
 * Буферный расчёт CDA.
 * cdaParams = { adu, cv, dlt, doc, safetyCoef }
 */
export function calculateBufferItem(item, settings, cdaParams) {
  const { today, deliveryDate, unit } = settings;
  const { adu, cv, dlt, doc, safetyCoef = 1.0 } = cdaParams;

  if (!validateDates(today, deliveryDate)) {
    return { calculatedOrder: 0, coverageDate: null, palletsInfo: null, buffer: null };
  }

  const { qpb, mult, valid } = getItemMultipliers(item);
  if (!valid) return { calculatedOrder: 0, coverageDate: null, palletsInfo: null, buffer: null };

  // ADU в текущих единицах (шт или коробки)
  const aduUnit = unit === 'boxes' ? adu / qpb : adu;

  // Зоны буфера (в текущих единицах)
  const green = aduUnit * doc;
  const cappedCv = Math.min(cv, 1.0);
  const yellow = green * cappedCv;
  const red = aduUnit * dlt * safetyCoef;
  const bufferTotal = green + yellow + red;

  const { daily, stockAfterDelivery } = calcStockAfterDelivery(item, settings);

  // Заказ = буфер − остаток к приходу
  let calculatedOrder = bufferTotal - stockAfterDelivery;
  if (calculatedOrder < 0) calculatedOrder = 0;
  calculatedOrder = roundByMultiplicity(calculatedOrder, unit, qpb, mult);

  return {
    calculatedOrder,
    coverageDate: calcCoverageDate(deliveryDate, stockAfterDelivery, item.finalOrder, daily),
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
