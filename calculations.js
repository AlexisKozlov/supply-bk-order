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
  const mult = item.multiplicity || 0;
  
  if (unit === 'boxes') {
    if (mult > 0) {
      // В режиме коробок: кратность = мин. количество коробок
      calculatedOrder = Math.ceil(calculatedOrder / mult) * mult;
    } else {
      calculatedOrder = roundUp(calculatedOrder);
    }
  }

  if (unit === 'pieces') {
    if (mult > 0) {
      // Кратность задана: округляем до кратности (= шт в коробе для штучных)
      calculatedOrder = Math.ceil(calculatedOrder / mult) * mult;
    } else if (item.qtyPerBox) {
      // Нет кратности: по коробкам
      calculatedOrder =
        roundUp(calculatedOrder / item.qtyPerBox) * item.qtyPerBox;
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