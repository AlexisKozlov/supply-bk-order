export function daysBetween(date1, date2) {
  if (!date1 || !date2) return 0;
  const ms = date2 - date1;
  return Math.max(Math.ceil(ms / 86400000), 0);
}

export function roundUp(value) {
  return Math.ceil(value);
}

export function safeDivide(a, b) {
  if (!b) return 0;
  return a / b;
}
