export const orderState = {
  settings: {
    legalEntity: 'Бургер БК',
    supplier: '',
    today: null,
    deliveryDate: null,
    periodDays: 30,
    safetyDays: 0,
    safetyEndDate: null,
    unit: 'pieces',
    hasTransit: false
  },
  items: []
};

/** Текущий пользователь (загружается при входе) */
export let currentUser = null;

export function setCurrentUser(user) {
  currentUser = user;
  if (user) {
    localStorage.setItem('bk_user', JSON.stringify(user));
  } else {
    localStorage.removeItem('bk_user');
  }
}

export function loadCurrentUser() {
  try {
    const stored = localStorage.getItem('bk_user');
    if (stored) {
      currentUser = JSON.parse(stored);
      return currentUser;
    }
  } catch (e) { /* noop */ }
  return null;
}