// js/utils.js

export function daysBetween(date1, date2) {
  const ms = date2 - date1;
  return Math.max(Math.ceil(ms / (1000 * 60 * 60 * 24)), 0);
}

export function roundUp(value) {
  return Math.ceil(value);
}

export function safeDivide(a, b) {
  if (!b || b === 0) return 0;
  return a / b;
}