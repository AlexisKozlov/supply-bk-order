import { ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

/**
 * Реф для активной вкладки, синхронизированный с query-параметром `?tab=...`.
 *
 * При маунте: если в URL есть `?tab=X` и X входит в validTabs — берём его.
 * При смене значения: тихо обновляем URL через router.replace (без записи в history).
 * При смене URL извне (ссылка из чата): подтягиваем новое значение в реф.
 *
 * @param {string} defaultTab - значение по умолчанию
 * @param {string[]} [validTabs] - допустимые значения; если не передано, любые принимаются
 * @param {string} [paramName='tab'] - имя query-параметра (на случай конфликтов)
 * @returns {import('vue').Ref<string>}
 */
export function useTabRoute(defaultTab, validTabs = null, paramName = 'tab') {
  const route = useRoute();
  const router = useRouter();

  // Если validTabs не передан или это пустой массив — принимаем любое значение.
  const isValid = (v) => v != null && (!Array.isArray(validTabs) || validTabs.length === 0 || validTabs.includes(v));
  const initial = isValid(route.query[paramName]) ? String(route.query[paramName]) : defaultTab;
  const tab = ref(initial);

  // Если в URL не было параметра — выставим его, чтобы ссылку можно было сразу шарить.
  // Делаем после маунта, чтобы не вмешиваться в навигацию.
  if (!route.query[paramName]) {
    queueMicrotask(() => {
      const q = { ...route.query, [paramName]: tab.value };
      router.replace({ query: q }).catch(() => {});
    });
  }

  // tab → URL
  watch(tab, (v) => {
    if (route.query[paramName] === v) return;
    const q = { ...route.query, [paramName]: v };
    router.replace({ query: q }).catch(() => {});
  });

  // URL → tab (например, пользователь открыл ссылку с другой вкладки в той же view)
  watch(() => route.query[paramName], (v) => {
    const next = isValid(v) ? String(v) : defaultTab;
    if (next !== tab.value) tab.value = next;
  });

  return tab;
}
