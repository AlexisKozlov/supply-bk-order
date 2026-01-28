export const orderState = {
  settings: {
    today: null,            // Date
    deliveryDate: null,     // Date
    periodDays: 30,         // период расхода
    safetyDays: 0,          // товарный запас (дни)
    safetyPercent: 0,       // товарный запас (%)
    unit: 'pieces'          // 'pieces' | 'boxes'
  },

  items: [
    /*
    {
      id: 'uuid-or-local',
      name: 'Булка бриошь',
      qtyPerBox: 48,
      boxesPerPallet: 30,

      consumptionPeriod: 0, // расход за период (в выбранных единицах)
      stock: 0,             // текущий остаток

      calculatedOrder: 0,   // расчетный заказ
      finalOrder: 0         // заказ итого (ручной)
    }
    */
  ]
};