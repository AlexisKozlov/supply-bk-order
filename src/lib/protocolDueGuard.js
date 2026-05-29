// Подтверждение перед сменой дедлайна карточки, привязанной к решению
// протокола. Срок в таких задачах общий: правка распространится на само
// решение и на карточки всех соисполнителей. Поэтому переспрашиваем
// через стилизованную модалку (useConfirm).
//
// confirmFn — функция из useConfirm() того компонента, что вызывает.
// Возвращает true — можно менять, false — пользователь отменил.
export async function confirmProtocolDueChange(card, newDue, confirmFn) {
  if (!card?.protocol_decision_id) return true;
  const cur = card.due_date || null;
  if ((newDue || null) === cur) return true;
  return await confirmFn(
    'Срок задачи из протокола',
    'Это задача из решения протокола совещания. Изменение срока сдвинет дедлайн в решении и у всех соисполнителей по нему. Продолжить?',
    { okText: 'Сдвинуть срок', cancelText: 'Отмена' }
  );
}
