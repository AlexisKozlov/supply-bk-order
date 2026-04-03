// Экспорт протокола совещания в Excel и PDF

function fmtDate(d) {
  if (!d) return '';
  const dt = new Date(d + 'T00:00:00');
  return dt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function statusLabel(s) {
  return { pending: 'В работе', done: 'Выполнено', overdue: 'Просрочено' }[s] || s;
}

// ═══ EXCEL ═══
export async function exportProtocolExcel(proto) {
  const XLSX = await import('xlsx-js-style');

  const wb = XLSX.utils.book_new();
  const rows = [];
  const merges = [];
  let r = 0;

  const headerStyle = { font: { bold: true, sz: 14, color: { rgb: 'FFFFFF' } }, fill: { fgColor: { rgb: 'D62700' } }, alignment: { horizontal: 'center' } };
  const labelStyle = { font: { bold: true, sz: 11 }, fill: { fgColor: { rgb: 'F5F5F5' } } };
  const valueStyle = { font: { sz: 11 }, alignment: { wrapText: true } };
  const decHeaderStyle = { font: { bold: true, sz: 10, color: { rgb: 'FFFFFF' } }, fill: { fgColor: { rgb: '333333' } } };

  // Заголовок
  rows.push([{ v: `Протокол совещания — ${proto.topic}`, s: headerStyle }]);
  merges.push({ s: { r: 0, c: 0 }, e: { r: 0, c: 4 } });
  r++;

  // Мета
  rows.push([{ v: 'Дата:', s: labelStyle }, { v: fmtDate(proto.meeting_date), s: valueStyle }]);
  r++;
  const participants = Array.isArray(proto.participants) ? proto.participants : [];
  rows.push([{ v: 'Участники:', s: labelStyle }, { v: participants.join(', '), s: valueStyle }]);
  r++;
  rows.push([{ v: 'Составил:', s: labelStyle }, { v: proto.created_by || '', s: valueStyle }]);
  r++;
  rows.push([]);
  r++;

  // Вопросы
  if (proto.questions) {
    rows.push([{ v: 'Обсуждённые вопросы', s: labelStyle }]);
    merges.push({ s: { r, c: 0 }, e: { r, c: 4 } });
    r++;
    rows.push([{ v: proto.questions, s: valueStyle }]);
    merges.push({ s: { r, c: 0 }, e: { r, c: 4 } });
    r++;
    rows.push([]);
    r++;
  }

  // Решения
  if (proto.decisions?.length) {
    rows.push([
      { v: '№', s: decHeaderStyle },
      { v: 'Задача', s: decHeaderStyle },
      { v: 'Ответственный', s: decHeaderStyle },
      { v: 'Срок', s: decHeaderStyle },
      { v: 'Статус', s: decHeaderStyle },
    ]);
    r++;
    proto.decisions.forEach((dec, i) => {
      const statusColors = { done: 'E8F5E9', overdue: 'FCE4EC', pending: 'FFF3E0' };
      const ss = { font: { sz: 11 }, fill: { fgColor: { rgb: statusColors[dec.status] || 'FFFFFF' } }, alignment: { wrapText: true } };
      rows.push([
        { v: i + 1, s: ss },
        { v: dec.text || '', s: ss },
        { v: Array.isArray(dec.responsible_person) ? dec.responsible_person.join(', ') : (dec.responsible_person || ''), s: ss },
        { v: fmtDate(dec.deadline), s: ss },
        { v: statusLabel(dec.status), s: ss },
      ]);
      r++;
    });
  }

  // Заметки
  if (proto.notes) {
    rows.push([]);
    r++;
    rows.push([{ v: 'Заметки', s: labelStyle }]);
    merges.push({ s: { r, c: 0 }, e: { r, c: 4 } });
    r++;
    rows.push([{ v: proto.notes, s: valueStyle }]);
    merges.push({ s: { r, c: 0 }, e: { r, c: 4 } });
  }

  const ws = XLSX.utils.aoa_to_sheet(rows);
  ws['!merges'] = merges;
  ws['!cols'] = [{ wch: 5 }, { wch: 45 }, { wch: 20 }, { wch: 14 }, { wch: 14 }];
  XLSX.utils.book_append_sheet(wb, ws, 'Протокол');
  XLSX.writeFile(wb, `Протокол_${fmtDate(proto.meeting_date).replace(/\./g, '-')}.xlsx`);
}

// ═══ PDF (через окно печати браузера — полная поддержка кириллицы) ═══
export function exportProtocolPdf(proto) {
  const participants = Array.isArray(proto.participants) ? proto.participants : [];
  const esc = s => (s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

  let decisionsHtml = '';
  if (proto.decisions?.length) {
    const statusColors = { done: '#e8f5e9', overdue: '#fce4ec', pending: '#fff3e0' };
    decisionsHtml = `<h3>Задачи</h3><table><thead><tr><th style="width:30px">№</th><th>Задача</th><th style="width:140px">Ответственный</th><th style="width:90px">Срок</th><th style="width:100px">Статус</th></tr></thead><tbody>`;
    proto.decisions.forEach((dec, i) => {
      const bg = statusColors[dec.status] || '#fff';
      const respStr = Array.isArray(dec.responsible_person) ? dec.responsible_person.join(', ') : (dec.responsible_person || '');
      decisionsHtml += `<tr style="background:${bg}"><td>${i + 1}</td><td>${esc(dec.text)}</td><td>${esc(respStr)}</td><td>${fmtDate(dec.deadline)}</td><td>${statusLabel(dec.status)}</td></tr>`;
    });
    decisionsHtml += '</tbody></table>';
  }

  const questionsHtml = proto.questions ? `<h3>Обсуждённые вопросы</h3><div class="text-block">${esc(proto.questions).replace(/\n/g, '<br>')}</div>` : '';
  const notesHtml = proto.notes ? `<h3>Заметки</h3><div class="text-block">${esc(proto.notes).replace(/\n/g, '<br>')}</div>` : '';

  const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Протокол — ${esc(proto.topic)}</title>
<style>
  @page { size: A4; margin: 15mm; }
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; font-size: 12px; color: #222; margin: 0 auto; padding: 24px 40px; max-width: 900px; }
  .header { background: #D62700; color: #fff; padding: 14px 24px; margin: -24px -40px 16px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  @media print { body { padding: 0; max-width: none; } .header { margin: 0 0 16px; } h3 { background: #f5f5f5 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; } table th { background: #333 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; } tr[style] { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
  .header h1 { margin: 0; font-size: 18px; font-weight: 600; }
  .meta { margin-bottom: 16px; }
  .meta-row { display: flex; gap: 6px; margin-bottom: 4px; }
  .meta-label { font-weight: 600; min-width: 90px; color: #555; }
  h3 { font-size: 13px; margin: 16px 0 8px; padding: 4px 8px; background: #f5f5f5; border-radius: 4px; }
  .text-block { white-space: pre-wrap; padding: 4px 8px; line-height: 1.5; }
  table { width: 100%; border-collapse: collapse; font-size: 11px; }
  th { background: #333; color: #fff; padding: 6px 8px; text-align: left; font-weight: 500; }
  td { padding: 5px 8px; border-bottom: 1px solid #ddd; vertical-align: top; }
</style></head><body>
<div class="header"><h1>Протокол совещания</h1></div>
<div class="meta">
  <div class="meta-row"><span class="meta-label">Тема:</span><span>${esc(proto.topic)}</span></div>
  <div class="meta-row"><span class="meta-label">Дата:</span><span>${fmtDate(proto.meeting_date)}</span></div>
  <div class="meta-row"><span class="meta-label">Участники:</span><span>${esc(participants.join(', '))}</span></div>
  <div class="meta-row"><span class="meta-label">Составил:</span><span>${esc(proto.created_by)}</span></div>
</div>
${questionsHtml}
${decisionsHtml}
${notesHtml}
</body></html>`;

  const w = window.open('', '_blank');
  w.document.write(html);
  w.document.close();
  setTimeout(() => { w.print(); }, 400);
}
