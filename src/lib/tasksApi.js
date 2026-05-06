// REST-клиент для модуля «Задачи». Эндпоинты лежат под /api/tasks/*

const API = '/api/tasks';

function headers() {
  const h = { 'Content-Type': 'application/json' };
  const t = localStorage.getItem('bk_session_token') || '';
  if (t) h['X-Session-Token'] = t;
  return h;
}

async function call(method, path, body) {
  const opts = { method, headers: headers() };
  if (body !== undefined) opts.body = JSON.stringify(body);
  const r = await fetch(`${API}${path}`, opts);
  let data = null;
  try { data = await r.json(); } catch (_) { /* пустой ответ */ }
  if (!r.ok) {
    const err = (data && data.error) ? data.error : `HTTP ${r.status}`;
    throw new Error(err);
  }
  return data;
}

export const tasksApi = {
  // Доски
  listBoards()                  { return call('GET',    '/boards'); },
  createBoard(payload)          { return call('POST',   '/boards', payload); },
  updateBoard(id, payload)      { return call('PATCH',  `/boards/${id}`, payload); },
  deleteBoard(id)               { return call('DELETE', `/boards/${id}`); },
  loadBoard(id)                 { return call('GET',    `/board/${id}`); },

  // Колонки
  createColumn(payload)         { return call('POST',   '/columns', payload); },
  updateColumn(id, payload)     { return call('PATCH',  `/columns/${id}`, payload); },
  deleteColumn(id)              { return call('DELETE', `/columns/${id}`); },
  reorderColumns(boardId, ids)  { return call('POST',   '/columns/reorder', { board_id: boardId, ids }); },

  // Карточки
  createCard(payload)           { return call('POST',   '/cards', payload); },
  updateCard(id, payload)       { return call('PATCH',  `/cards/${id}`, payload); },
  deleteCard(id)                { return call('DELETE', `/cards/${id}`); },
  moveCard(payload)             { return call('POST',   '/cards/move', payload); },
  loadCard(id)                  { return call('GET',    `/cards/${id}`); },

  // Метки
  createLabel(payload)          { return call('POST',   '/labels', payload); },
  updateLabel(id, payload)      { return call('PATCH',  `/labels/${id}`, payload); },
  deleteLabel(id)               { return call('DELETE', `/labels/${id}`); },
  setCardLabels(cardId, labelIds) { return call('POST', `/cards/${cardId}/labels`, { label_ids: labelIds }); },

  // Чек-лист
  addChecklist(cardId, title)        { return call('POST',  `/cards/${cardId}/checklist`, { title }); },
  updateChecklistItem(id, payload)   { return call('PATCH', `/checklist/${id}`, payload); },
  deleteChecklistItem(id)            { return call('DELETE',`/checklist/${id}`); },

  // Комментарии
  addComment(cardId, body)           { return call('POST',  `/cards/${cardId}/comments`, { body }); },
  updateComment(id, body)            { return call('PATCH', `/comments/${id}`, { body }); },
  deleteComment(id)                  { return call('DELETE',`/comments/${id}`); },

  // Соисполнители
  setAssignees(cardId, names)        { return call('POST',  `/cards/${cardId}/assignees`, { user_names: names }); },
  setAssigneeDone(cardId, userName, isDone) { return call('POST', `/cards/${cardId}/assignees/done`, { user_name: userName, is_done: isDone ? 1 : 0 }); },

  // Связи с сущностями
  setRelations(cardId, relations)    { return call('POST',  `/cards/${cardId}/relations`, { relations }); },
  deleteRelation(id)                 { return call('DELETE',`/relations/${id}`); },

  // Список пользователей
  listUsers()                        { return call('GET',   '/users'); },

  // Поиск и «мои задачи»
  search(q)                          { return call('GET',   '/search?q=' + encodeURIComponent(q)); },
  myCards()                          { return call('GET',   '/my-cards'); },
};
