/**
 * Композабл логики дашборда кабинета ресторана.
 *
 * Что внутри:
 *  - PWA push онбординг (определение «приложение» / запоминание «не сейчас»)
 *  - Сводная карточка «Сегодня нужно сделать»: незаполненный сбор остатков, новые опросы
 *
 * Из RestaurantCabinetView.vue это вынесено, чтобы:
 *  - облегчить большой файл кабинета
 *  - сделать дашборд-логику тестируемой/повторно используемой
 *
 * Использование:
 *   const {
 *     push, isStandalonePwa, showPushOnboarding,
 *     dismissPushOnboarding, enablePushOnboarding,
 *     todaySignals,
 *   } = useCabinetDashboard({ stockCollection, stockCollectionUnfilledCount, surveyPendingCount, switchTab, toast });
 */

import { ref, computed } from 'vue';
import { usePushNotifications } from '@/composables/usePushNotifications.js';

const PUSH_ONBOARDING_KEY = 'ro_push_onboarding_seen_v1';

export function useCabinetDashboard({ stockCollection, stockCollectionUnfilledCount, surveyPendingCount, switchTab, toast }) {
  // ─── Push онбординг ───
  const push = usePushNotifications();
  const pushOnboardingDismissed = ref(false);
  const isStandalonePwa = ref(false);
  try {
    isStandalonePwa.value = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
      || window.navigator?.standalone === true;
    pushOnboardingDismissed.value = localStorage.getItem(PUSH_ONBOARDING_KEY) === '1';
  } catch (e) { /* ignore */ }

  const showPushOnboarding = computed(() => {
    if (!isStandalonePwa.value) return false;
    if (pushOnboardingDismissed.value) return false;
    if (!push.isSupported.value) return false;
    if (push.permission.value === 'denied') return false;
    if (push.isSubscribed.value) return false;
    return true;
  });

  function dismissPushOnboarding() {
    pushOnboardingDismissed.value = true;
    try { localStorage.setItem(PUSH_ONBOARDING_KEY, '1'); } catch (e) { /* ignore */ }
  }

  async function enablePushOnboarding() {
    const ok = await push.subscribe();
    if (ok) {
      toast?.success?.('Уведомления включены');
      dismissPushOnboarding();
    } else if (push.error.value) {
      toast?.error?.(push.error.value);
    }
  }

  // ─── Карточка «Сегодня нужно сделать» ───
  // Сами напоминания о заявках поставщикам показываются отдельным блоком
  // (RestaurantTodayReminders) с кнопками «Подал», поэтому в сводке их не дублируем.
  const todaySignals = computed(() => {
    const out = [];
    if (stockCollection?.active && stockCollectionUnfilledCount?.value > 0) {
      out.push({
        key: 'stock',
        count: stockCollectionUnfilledCount.value,
        label: 'позиций не заполнено в сборе остатков',
        tone: 'alert',
        action: () => switchTab('stock'),
      });
    }
    if (surveyPendingCount?.value > 0) {
      out.push({
        key: 'surveys',
        count: surveyPendingCount.value,
        label: surveyPendingCount.value === 1 ? 'новый опрос ждёт ответа' : `новых опросов (${surveyPendingCount.value})`,
        tone: 'info',
        action: () => switchTab('surveys'),
      });
    }
    return out;
  });

  return {
    push,
    isStandalonePwa,
    showPushOnboarding,
    dismissPushOnboarding,
    enablePushOnboarding,
    todaySignals,
  };
}
