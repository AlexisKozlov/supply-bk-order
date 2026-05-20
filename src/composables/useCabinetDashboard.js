/**
 * Композабл логики дашборда кабинета ресторана.
 *
 * Что внутри:
 *  - PWA push онбординг (определение «приложение» / запоминание «не сейчас»)
 *
 * Использование:
 *   const {
 *     push, isStandalonePwa, showPushOnboarding,
 *     dismissPushOnboarding, enablePushOnboarding,
 *   } = useCabinetDashboard({ toast });
 */

import { ref } from 'vue';
import { usePushNotifications } from '@/composables/usePushNotifications.js';

const PUSH_ONBOARDING_KEY = 'ro_push_onboarding_seen_v1';

export function useCabinetDashboard({ toast }) {
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

  return {
    push,
    isStandalonePwa,
    showPushOnboarding,
    dismissPushOnboarding,
    enablePushOnboarding,
  };
}
