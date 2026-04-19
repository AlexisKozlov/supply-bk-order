import { ref, onBeforeUnmount, watch, isRef } from 'vue';

const MINSK_OFFSET = '+03:00';

export function parseMinskDeadline(str) {
  if (!str) return null;
  const m = String(str).match(/^(\d{4})-(\d{2})-(\d{2})[T\s](\d{2}):(\d{2})(?::(\d{2}))?$/);
  if (!m) return null;
  const iso = `${m[1]}-${m[2]}-${m[3]}T${m[4]}:${m[5]}:${m[6] || '00'}${MINSK_OFFSET}`;
  const ts = Date.parse(iso);
  return Number.isFinite(ts) ? ts : null;
}

export function formatDuration(ms) {
  if (ms <= 0) return '00:00:00';
  const total = Math.floor(ms / 1000);
  const days = Math.floor(total / 86400);
  const hours = Math.floor((total % 86400) / 3600);
  const mins = Math.floor((total % 3600) / 60);
  const secs = total % 60;
  const hh = String(hours).padStart(2, '0');
  const mm = String(mins).padStart(2, '0');
  const ss = String(secs).padStart(2, '0');
  if (days > 0) return `${days} д ${hh}:${mm}:${ss}`;
  return `${hh}:${mm}:${ss}`;
}

/**
 * Возвращает строку «осталось до дедлайна» или '' если строка невалидна/уже прошла.
 */
export function deadlineTimeLeftString(str, nowMs = Date.now()) {
  const ts = parseMinskDeadline(str);
  if (ts === null) return '';
  const diff = ts - nowMs;
  if (diff <= 0) return '';
  return formatDuration(diff);
}

/**
 * Обратный отсчёт до дедлайна в минском времени (UTC+3).
 * Принимает ref/getter, возвращающий строку "YYYY-MM-DD HH:MM[:SS]".
 * Если строка пустая/невалидная — timeLeft пустой, isExpired=false.
 */
export function useDeadlineCountdown(deadlineSource) {
  const timeLeft = ref('');
  const isExpired = ref(false);
  let timer = null;

  function read() {
    if (typeof deadlineSource === 'function') return deadlineSource();
    if (isRef(deadlineSource)) return deadlineSource.value;
    return deadlineSource;
  }

  function tick() {
    const ts = parseMinskDeadline(read());
    if (ts === null) {
      timeLeft.value = '';
      isExpired.value = false;
      return;
    }
    const diff = ts - Date.now();
    if (diff <= 0) {
      timeLeft.value = '';
      isExpired.value = true;
      stop();
      return;
    }
    timeLeft.value = formatDuration(diff);
    isExpired.value = false;
  }

  function start() {
    stop();
    tick();
    timer = setInterval(tick, 1000);
  }

  function stop() {
    if (timer) { clearInterval(timer); timer = null; }
  }

  start();

  if (typeof deadlineSource !== 'function' && isRef(deadlineSource)) {
    watch(deadlineSource, () => start());
  }

  onBeforeUnmount(stop);

  return { timeLeft, isExpired };
}
