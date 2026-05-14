/*
  useTouchDrag — long-press drag-and-drop для тач-устройств.

  На десктопе DnD работает нативно через HTML5 (draggable="true" +
  dragstart/dragover/drop). На тач-устройствах нативный DnD не запускается
  по long-press, поэтому собираем его руками через pointer events.

  Контракт:
  1. Пользователь зажимает карточку на ~450мс — стартует drag-режим.
     Если за это время палец сдвинулся >8px — считаем что это прокрутка, drag не стартует.
  2. В drag-режиме клонируем карточку и таскаем клон за пальцем (как iOS):
     оригинал приглушаем, клон follows finger через position: fixed + translate.
  3. На каждом pointermove ищем колонку под пальцем через elementsFromPoint
     и пересекаем с элементом, имеющим data-column-id. Подсвечиваем её.
  4. На pointerup: если палец над колонкой — вызываем onDrop(targetColumnId).
     Иначе onCancel().
  5. Click сразу после long-press подавляем (иначе после long-press открылась
     бы модалка карточки).

  Использование:
    const card = ref(null);
    const dragHandlers = useTouchDrag({
      cardRef: card,
      onDragStart: () => { ... },
      onDrop:      (targetColumnId) => store.moveCard(cardId, targetColumnId, 0),
      onCancel:    () => { ... },
    });
    // Композабл сам подцепляется и отцепляется через onUnmounted.

  Параметры:
  - cardRef:    Vue ref на DOM-элемент карточки.
  - onDragStart: ()=>void  — вызывается при старте drag.
  - onDrop:      (id)=>void — вызывается при release над колонкой.
  - onCancel:    ()=>void  — вызывается при release вне колонок (или pointercancel).

  Селектор drop-target: '[data-column-id]'.
  TaskColumn.vue должен иметь это атрибут на корневом элементе.
*/

import { onUnmounted, watchEffect } from 'vue';

const LONG_PRESS_MS = 450;
const MOVE_TOLERANCE_PX = 8;

export function useTouchDrag({ cardRef, onDragStart, onDrop, onCancel }) {
  let timerId = null;
  let isDragging = false;
  let suppressClick = false;
  let startX = 0;
  let startY = 0;
  let ghostEl = null;
  let lastTargetCol = null;
  let attachedEl = null;

  function onPointerDown(e) {
    // Только тач. На мыши и пере полагаемся на нативный HTML5 DnD.
    if (e.pointerType !== 'touch') return;
    if (e.isPrimary === false) return;
    startX = e.clientX;
    startY = e.clientY;
    timerId = setTimeout(() => beginDrag(), LONG_PRESS_MS);
    attachedEl.addEventListener('pointermove', onPreDragMove);
    attachedEl.addEventListener('pointerup',     onPreDragEnd);
    attachedEl.addEventListener('pointercancel', onPreDragEnd);
  }

  function onPreDragMove(e) {
    const dx = Math.abs(e.clientX - startX);
    const dy = Math.abs(e.clientY - startY);
    if (dx > MOVE_TOLERANCE_PX || dy > MOVE_TOLERANCE_PX) cancelPreDrag();
  }

  function onPreDragEnd() {
    cancelPreDrag();
  }

  function cancelPreDrag() {
    if (timerId) { clearTimeout(timerId); timerId = null; }
    if (attachedEl) {
      attachedEl.removeEventListener('pointermove', onPreDragMove);
      attachedEl.removeEventListener('pointerup',     onPreDragEnd);
      attachedEl.removeEventListener('pointercancel', onPreDragEnd);
    }
  }

  function beginDrag() {
    timerId = null;
    cancelPreDrag();
    if (!attachedEl) return;
    isDragging = true;
    suppressClick = true;
    onDragStart?.();

    // Тактильная отдача (если устройство поддерживает).
    try { if (navigator.vibrate) navigator.vibrate(10); } catch {}

    // Клон карточки follows finger.
    const rect = attachedEl.getBoundingClientRect();
    ghostEl = attachedEl.cloneNode(true);
    ghostEl.classList.add('tdrag-ghost');
    ghostEl.style.position = 'fixed';
    ghostEl.style.top = rect.top + 'px';
    ghostEl.style.left = rect.left + 'px';
    ghostEl.style.width = rect.width + 'px';
    ghostEl.style.zIndex = '9999';
    ghostEl.style.pointerEvents = 'none';
    ghostEl.style.opacity = '0.92';
    ghostEl.style.transform = 'scale(1.04)';
    ghostEl.style.boxShadow = 'var(--tk-shadow-card-drag, 0 8px 20px rgba(15,23,42,0.18))';
    ghostEl.style.transition = 'none';
    document.body.appendChild(ghostEl);

    // Оригинал приглушаем.
    attachedEl.classList.add('tdrag-source');

    // Запрещаем тач-скролл документа на время drag, иначе палец одновременно
    // тащит карточку и прокручивает страницу.
    document.body.style.touchAction = 'none';

    document.addEventListener('pointermove',   onDragMove);
    document.addEventListener('pointerup',     onDragEnd);
    document.addEventListener('pointercancel', onDragEnd);
  }

  function onDragMove(e) {
    if (!isDragging || !ghostEl) return;
    const dx = e.clientX - startX;
    const dy = e.clientY - startY;
    ghostEl.style.transform = `translate(${dx}px, ${dy}px) scale(1.04)`;

    // Ищем колонку под пальцем.
    const targetCol = columnUnderPoint(e.clientX, e.clientY);
    if (targetCol !== lastTargetCol) {
      if (lastTargetCol) lastTargetCol.classList.remove('tdrag-over');
      if (targetCol)      targetCol.classList.add('tdrag-over');
      lastTargetCol = targetCol;
    }
  }

  function columnUnderPoint(x, y) {
    // Скрываем ghost, чтобы elementsFromPoint вернул то, что под ним,
    // а не сам ghost. После — возвращаем visibility.
    if (!ghostEl) return null;
    const prevVis = ghostEl.style.visibility;
    ghostEl.style.visibility = 'hidden';
    const els = document.elementsFromPoint(x, y);
    ghostEl.style.visibility = prevVis;
    for (const el of els) {
      const col = el.closest && el.closest('[data-column-id]');
      if (col) return col;
    }
    return null;
  }

  function onDragEnd(e) {
    document.removeEventListener('pointermove',   onDragMove);
    document.removeEventListener('pointerup',     onDragEnd);
    document.removeEventListener('pointercancel', onDragEnd);
    document.body.style.touchAction = '';

    let targetColumnId = null;
    if (e && e.clientX != null && e.clientY != null) {
      const col = columnUnderPoint(e.clientX, e.clientY);
      if (col) targetColumnId = parseInt(col.dataset.columnId, 10);
    }

    if (lastTargetCol) {
      lastTargetCol.classList.remove('tdrag-over');
      lastTargetCol = null;
    }
    if (ghostEl) { ghostEl.remove(); ghostEl = null; }
    if (attachedEl) attachedEl.classList.remove('tdrag-source');

    if (targetColumnId) onDrop?.(targetColumnId);
    else                onCancel?.();

    isDragging = false;
    // Подавляем СЛЕДУЮЩИЙ click (синтетический от тача), чтобы не открылась
    // модалка карточки.
    setTimeout(() => { suppressClick = false; }, 50);
  }

  function onClickCapture(e) {
    if (suppressClick) {
      e.stopPropagation();
      e.preventDefault();
    }
  }

  function attach(el) {
    if (!el || el === attachedEl) return;
    detach();
    attachedEl = el;
    el.addEventListener('pointerdown', onPointerDown);
    el.addEventListener('click', onClickCapture, true);
  }

  function detach() {
    if (!attachedEl) return;
    attachedEl.removeEventListener('pointerdown', onPointerDown);
    attachedEl.removeEventListener('click', onClickCapture, true);
    attachedEl = null;
    cancelPreDrag();
  }

  // Реактивно подцепляемся к cardRef когда DOM-элемент становится доступен.
  watchEffect(() => {
    const el = cardRef?.value;
    if (el) attach(el);
  });

  onUnmounted(() => {
    detach();
    if (ghostEl) { ghostEl.remove(); ghostEl = null; }
  });
}
