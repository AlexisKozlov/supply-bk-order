/**
 * Алгоритм распределения дефицитного товара между ресторанами.
 *
 * Для каждого ресторана:
 * 1. Берём график доставок
 * 2. Находим ближайший день доставки ПОСЛЕ даты поставки на склад
 * 3. daysToCover = дней от сегодня до этой доставки
 * 4. adjustedDaily = dailyConsumption * growthFactor
 * 5. need = max(0, adjustedDaily * daysToCover - currentStock) — точная потребность
 * 6. Если склада хватает всем — allocated = roundUp(need, multiplicity)
 * 7. Если не хватает — пропорциональное распределение, округлённое до кратности
 */

const DAY_MAP = { 0: 7, 1: 1, 2: 2, 3: 3, 4: 4, 5: 5, 6: 6 };

/**
 * Найти ближайший день доставки ресторану после указанной даты.
 */
export function findNextDeliveryDate(restaurantSchedule, afterDate) {
  if (!restaurantSchedule || restaurantSchedule.size === 0) return null;

  const deliveryDays = [...restaurantSchedule.keys()].sort((a, b) => a - b);
  const after = typeof afterDate === 'string' ? new Date(afterDate + 'T00:00:00') : new Date(afterDate);
  after.setHours(0, 0, 0, 0);

  for (let offset = 1; offset <= 14; offset++) {
    const candidate = new Date(after);
    candidate.setDate(after.getDate() + offset);
    const dow = DAY_MAP[candidate.getDay()];
    if (deliveryDays.includes(dow)) {
      return candidate;
    }
  }
  return null;
}

function daysBetween(from, to) {
  const f = typeof from === 'string' ? new Date(from + 'T00:00:00') : new Date(from); f.setHours(0, 0, 0, 0);
  const t = typeof to === 'string' ? new Date(to + 'T00:00:00') : new Date(to); t.setHours(0, 0, 0, 0);
  return Math.max(0, Math.round((t - f) / 86400000));
}

function roundUpToMultiplicity(value, mult) {
  if (!mult || mult <= 0) mult = 1;
  if (value <= 0) return 0;
  return Math.ceil(value / mult) * mult;
}

/**
 * Рассчитать распределение.
 * @param {Object} params
 * @param {number} params.warehouseStock — остаток на складе
 * @param {Date} params.nextDeliveryDate — дата поставки на склад
 * @param {number} params.growthFactor — коэффициент роста
 * @param {number} params.multiplicity — кратность отгрузки
 * @param {Array} params.restaurants — [{id, number, dailyConsumption, currentStock, schedule: Map}]
 * @returns {Object}
 */
export function allocateDeficit({ warehouseStock, nextDeliveryDate, growthFactor, multiplicity, restaurants }) {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const mult = multiplicity || 1;

  const results = restaurants.map(r => {
    const nextDel = findNextDeliveryDate(r.schedule, nextDeliveryDate);
    const daysToCover = nextDel ? daysBetween(today, nextDel) : 0;
    const adjustedDaily = (r.dailyConsumption || 0) * (growthFactor || 1);
    // need — точная потребность без округления
    const need = Math.max(0, adjustedDaily * daysToCover - (r.currentStock || 0));

    return {
      restaurantId: r.id,
      restaurantNumber: r.number,
      currentStock: r.currentStock || 0,
      dailyConsumption: r.dailyConsumption || 0,
      daysToCover,
      deliveryDay: nextDel ? formatDate(nextDel) : 'нет графика',
      need,
      allocated: 0,
    };
  });

  // Для распределения используем need, округлённую до кратности
  const roundedNeeds = results.map(r => roundUpToMultiplicity(r.need, mult));
  const totalRoundedNeed = roundedNeeds.reduce((s, n) => s + n, 0);
  const totalNeed = results.reduce((s, r) => s + r.need, 0);
  const sufficient = warehouseStock >= totalRoundedNeed;

  if (totalRoundedNeed === 0) {
    // Никому ничего не нужно
  } else if (sufficient) {
    // Склада хватает — каждый получает потребность, округлённую до кратности
    results.forEach((r, i) => {
      r.allocated = roundedNeeds[i];
    });
  } else {
    // Дефицит — пропорциональное распределение
    const ratio = warehouseStock / totalRoundedNeed;
    let remaining = warehouseStock;

    const sorted = results
      .map((r, i) => ({ r, rn: roundedNeeds[i] }))
      .filter(x => x.rn > 0)
      .sort((a, b) => b.r.need - a.r.need);

    // Первый проход: округляем вниз до кратности
    for (const { r, rn } of sorted) {
      const ideal = rn * ratio;
      const floored = Math.floor(ideal / mult) * mult;
      r.allocated = Math.max(0, floored);
      remaining -= r.allocated;
    }

    // Второй проход: распределяем остаток
    remaining = Math.round(remaining * 100) / 100;
    sorted.sort((a, b) => (b.rn - b.r.allocated) - (a.rn - a.r.allocated));

    let distributed = true;
    while (remaining >= mult && distributed) {
      distributed = false;
      for (const { r, rn } of sorted) {
        if (remaining < mult) break;
        const canAdd = rn - r.allocated;
        if (canAdd >= mult) {
          r.allocated += mult;
          remaining -= mult;
          distributed = true;
        }
      }
    }
  }

  const totalAllocated = results.reduce((s, r) => s + r.allocated, 0);

  return {
    results,
    totalNeed: Math.round(totalNeed * 100) / 100,
    totalAllocated: Math.round(totalAllocated * 100) / 100,
    sufficient,
  };
}

function formatDate(d) {
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  return `${dd}.${mm}`;
}
