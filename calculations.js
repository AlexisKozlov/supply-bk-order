import { daysBetween, roundUp, safeDivide, getQpb, getMultiplicity } from './utils.js';

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

  // Расход до поставки — покрывается текущим остатком
  const consumedBeforeDelivery = daily * transitDays;
  
  // Учитываем транзит как дополнительный остаток
  const totalStock = (item.stock || 0) + (item.transit || 0);
  
  // Остаток к моменту поставки (не может быть отрицательным)
  const stockAfterDelivery = Math.max(0, totalStock - consumedBeforeDelivery);
  
  // Потребность ПОСЛЕ поставки (только период запаса)
  const needAfterDelivery = daily * safetyDays;

  let calculatedOrder = needAfterDelivery - stockAfterDelivery;
  if (calculatedOrder < 0) calculatedOrder = 0;

  /* ===== ОКРУГЛЕНИЕ ДО ФИЗИЧЕСКОЙ КОРОБКИ ===== */
  // В штуках: округляем до qty_per_box × multiplicity (= физ. коробка в штуках)
  // В учётных коробках: округляем до multiplicity (= физ. коробка в учётных кор)
  const mult = getMultiplicity(item);
  const qpb = getQpb(item);
  
  if (unit === 'boxes') {
    // Округляем учётные коробки до multiplicity (= 1 физическая коробка)
    calculatedOrder = roundUp(calculatedOrder / mult) * mult;
  } else {
    // Округляем штуки до физической коробки (qpb × mult)
    const physBoxInPieces = qpb * mult;
    if (physBoxInPieces > 0) {
      calculatedOrder = roundUp(calculatedOrder / physBoxInPieces) * physBoxInPieces;
    }
  }

  // === ДАТА «ХВАТИТ ДО» ===
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
    palletsInfo: calculatePallets(item, unit)
  };
}

function calculatePallets(item, unit) {
  if (!item.boxesPerPallet || !item.finalOrder) return null;

  const qpb = getQpb(item);
  const mult = getMultiplicity(item);
  
  // Приводим finalOrder к физическим коробкам
  // В штуках: finalOrder / (qpb * mult)
  // В учётных коробках: finalOrder / mult
  const physBoxes =
    unit === 'boxes'
      ? item.finalOrder / mult
      : item.finalOrder / (qpb * mult);

  return {
    pallets: Math.floor(physBoxes / item.boxesPerPallet),
    boxesLeft: Math.ceil(physBoxes % item.boxesPerPallet)
  };
}