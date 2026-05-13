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

  // Чек-лист (пункты)
  addChecklist(cardId, title, checklistId = null) {
    const body = { title };
    if (checklistId) body.checklist_id = checklistId;
    return call('POST', `/cards/${cardId}/checklist`, body);
  },
  updateChecklistItem(id, payload)   { return call('PATCH', `/checklist/${id}`, payload); },
  deleteChecklistItem(id)            { return call('DELETE',`/checklist/${id}`); },

  // Чек-листы как группы (несколько на карточку)
  addChecklistGroup(cardId, title)   { return call('POST',  `/cards/${cardId}/checklists`, { title }); },
  updateChecklistGroup(id, payload)  { return call('PATCH', `/checklists/${id}`, payload); },
  deleteChecklistGroup(id)           { return call('DELETE',`/checklists/${id}`); },

  // Комментарии
  addComment(cardId, body)           { return call('POST',  `/cards/${cardId}/comments`, { body }); },
  updateComment(id, body)            { return call('PATCH', `/comments/${id}`, { body }); },
  deleteComment(id)                  { return call('DELETE',`/comments/${id}`); },

  // Соисполнители
  setAssignees(cardId, names)        { return call('POST',  `/cards/${cardId}/assignees`, { user_names: names }); },

  // Связи с сущностями
  setRelations(cardId, relations)    { return call('POST',  `/cards/${cardId}/relations`, { relations }); },
  deleteRelation(id)                 { return call('DELETE',`/relations/${id}`); },

  // Список пользователей
  listUsers()                        { return call('GET',   '/users'); },

  // Поиск и «мои задачи»
  search(q)                          { return call('GET',   '/search?q=' + encodeURIComponent(q)); },
  myCards()                          { return call('GET',   '/my-cards'); },

  // Шаблоны повторяющихся карточек
  listTemplates()                    { return call('GET',    '/templates'); },
  createTemplate(payload)            { return call('POST',   '/templates', payload); },
  loadTemplate(id)                   { return call('GET',    `/templates/${id}`); },
  updateTemplate(id, payload)        { return call('PATCH',  `/templates/${id}`, payload); },
  deleteTemplate(id)                 { return call('DELETE', `/templates/${id}`); },
  setTemplateAssignees(id, names)    { return call('POST',   `/templates/${id}/assignees`, { user_names: names }); },
  setTemplateChecklist(id, items)    { return call('POST',   `/templates/${id}/checklist`, { items }); },

  // Расписания (1-N на шаблон)
  createSchedule(tplId, payload)     { return call('POST',   `/templates/${tplId}/schedules`, payload); },
  updateSchedule(id, payload)        { return call('PATCH',  `/template-schedules/${id}`, payload); },
  deleteSchedule(id)                 { return call('DELETE', `/template-schedules/${id}`); },
  previewSchedule(id)                { return call('GET',    `/template-schedules/${id}/preview`); },
  runScheduleNow(id)                 { return call('POST',   `/template-schedules/${id}/run-now`); },

  // Сохранить открытую карточку как шаблон
  saveCardAsTemplate(cardId)         { return call('POST',   `/cards/${cardId}/save-as-template`); },

  // Уведомления
  listNotifications(limit = 30)      { return call('GET',   '/notifications?limit=' + limit); },
  markNotificationsRead(ids)         { return call('POST',  '/notifications/mark-read', { ids }); },
  markAllNotificationsRead()         { return call('POST',  '/notifications/mark-read', { all: true }); },

  // Вложения (живут под /api/upload/task-attachment и /api/uploads/task-attachments/)
  uploadAttachment(cardId, file, onProgress) {
    return new Promise((resolve, reject) => {
      const fd = new FormData();
      fd.append('card_id', String(cardId));
      fd.append('file', file);
      const xhr = new XMLHttpRequest();
      xhr.open('POST', '/api/upload/task-attachment');
      const t = localStorage.getItem('bk_session_token') || '';
      if (t) xhr.setRequestHeader('X-Session-Token', t);
      if (typeof onProgress === 'function' && xhr.upload) {
        xhr.upload.onprogress = (e) => { if (e.lengthComputable) onProgress(Math.round((e.loaded / e.total) * 100)); };
      }
      xhr.onload = () => {
        let data = null;
        try { data = JSON.parse(xhr.responseText); } catch (_) {}
        if (xhr.status >= 200 && xhr.status < 300) resolve(data);
        else reject(new Error((data && data.error) ? data.error : `HTTP ${xhr.status}`));
      };
      xhr.onerror = () => reject(new Error('Сеть недоступна'));
      xhr.send(fd);
    });
  },
  deleteAttachment(fileId) {
    return new Promise((resolve, reject) => {
      const t = localStorage.getItem('bk_session_token') || '';
      fetch('/api/upload/task-attachment?file_id=' + encodeURIComponent(fileId), {
        method: 'DELETE',
        headers: t ? { 'X-Session-Token': t } : {},
      }).then(async (r) => {
        let d = null; try { d = await r.json(); } catch (_) {}
        if (!r.ok) reject(new Error((d && d.error) ? d.error : `HTTP ${r.status}`));
        else resolve(d);
      }).catch(reject);
    });
  },
  attachmentUrl(filePath, { download = false } = {}) {
    const t = localStorage.getItem('bk_session_token') || '';
    const params = new URLSearchParams();
    if (download) params.set('download', '1');
    if (t) params.set('token', t);
    const q = params.toString();
    return '/api/uploads/task-attachments/' + encodeURIComponent(filePath) + (q ? ('?' + q) : '');
  },
};
