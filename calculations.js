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

  // Расход до поставки — покрывается текущим остатком
  const consumedBeforeDelivery = daily * transitDays;
  
  // Учитываем транзит как дополнительный остаток
  const totalStock = item.stock + (item.transit || 0);
  
  // Остаток к моменту поставки (не может быть отрицательным)
  const stockAfterDelivery = Math.max(0, totalStock - consumedBeforeDelivery);
  
  // Потребность ПОСЛЕ поставки (только период запаса)
  const needAfterDelivery = daily * safetyDays;

  let calculatedOrder = needAfterDelivery - stockAfterDelivery;
  if (calculatedOrder < 0) calculatedOrder = 0;

  /* ===== ОКРУГЛЕНИЕ С УЧЁТОМ КРАТНОСТИ ===== */
  // multiplicity = кол-во штук в одной «упаковке» (коробке)
  // Для штучных товаров с кратностью:
  //   В штуках: заказ округляется вверх до кратного multiplicity
  //   В коробках: заказ уже идёт в коробках, просто ceil
  const mult = item.multiplicity || 0;
  const effectiveBox = mult > 0 ? mult : (item.qtyPerBox || 0);
  
  if (unit === 'boxes') {
    // В режиме коробок — всегда целые коробки
    calculatedOrder = roundUp(calculatedOrder);
  }

  if (unit === 'pieces') {
    if (effectiveBox > 0) {
      // Округляем до целых коробок (кратность или qtyPerBox)
      calculatedOrder =
        roundUp(calculatedOrder / effectiveBox) * effectiveBox;
    }
  }

  // === ДАТА «ХВАТИТ ДО» ===
  // После поставки доступно: остаток к поставке + заказ
  const availableAfterDelivery = stockAfterDelivery + (item.finalOrder || 0);
  const daysAfterDelivery = safeDivide(availableAfterDelivery, daily);

  // День поставки НЕ считается днём потребления (товар только пришёл)
  // Первый день потребления — следующий день после поставки
  // Поэтому: deliveryDate + daysAfterDelivery (полных дней)
  const coverageDate =
    daily > 0 && daysAfterDelivery > 0
      ? new Date(deliveryDate.getTime() + daysAfterDelivery * 86400000)
      : null;

  return {
    calculatedOrder,
    coverageDate,
    palletsInfo: calculatePallets(item, unit)
  };
}

function calculatePallets(item, unit) {
  if (!item.boxesPerPallet || !item.finalOrder) return null;

  const effectiveQpb = item.multiplicity || item.qtyPerBox || 1;
  const boxes =
    unit === 'boxes'
      ? item.finalOrder
      : item.finalOrder / effectiveQpb;

  return {
    pallets: Math.floor(boxes / item.boxesPerPallet),
    boxesLeft: Math.ceil(boxes % item.boxesPerPallet)
  };
}