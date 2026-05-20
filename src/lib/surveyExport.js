// Экспорт результатов опроса в Excel.
// Три листа: «Ответы» (сводная таблица), «Аналитика» (статистика по вопросам),
// «Не ответили» (список ресторанов без ответа).

import { formatRestaurantNumber } from './legalEntities.js';

function answerCellValue(answer) {
  if (!answer) return '';
  if (answer.type === 'scale') return answer.numeric_value != null ? Number(answer.numeric_value) : '';
  if (answer.type === 'text') return answer.text_value || '';
  return answer.option_text || '';
}

function formatDateTime(value) {
  if (!value) return '';
  const d = new Date(value);
  if (isNaN(d.getTime())) return '';
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const yyyy = d.getFullYear();
  const hh = String(d.getHours()).padStart(2, '0');
  const mi = String(d.getMinutes()).padStart(2, '0');
  return `${dd}.${mm}.${yyyy} ${hh}:${mi}`;
}

export async function exportSurveyToExcel(detail, rows) {
  const XLSX = await import('xlsx-js-style');

  const brown = '502314';
  const cream = 'FFF8F0';
  const borderClr = 'E0D6CC';
  const border = { style: 'thin', color: { rgb: borderClr } };
  const borders = { top: border, bottom: border, left: border, right: border };

  const sTitle = { font: { bold: true, sz: 16, color: { rgb: brown }, name: 'Calibri' }, alignment: { vertical: 'center' } };
  const sInfo = { font: { sz: 11, color: { rgb: '666666' }, name: 'Calibri' }, alignment: { vertical: 'center' } };
  const sHeader = {
    font: { bold: true, sz: 11, color: { rgb: 'FFFFFF' }, name: 'Calibri' },
    fill: { fgColor: { rgb: brown } },
    alignment: { horizontal: 'center', vertical: 'center', wrapText: true },
    border: borders,
  };
  const sHeaderLeft = { ...sHeader, alignment: { ...sHeader.alignment, horizontal: 'left' } };
  function sCell(stripe, align = 'left') {
    return {
      font: { sz: 11, name: 'Calibri' },
      fill: stripe ? { fgColor: { rgb: cream } } : undefined,
      alignment: { vertical: 'center', horizontal: align, wrapText: true },
      border: borders,
    };
  }
  function setCell(ws, r, c, val, style) {
    const ref = XLSX.utils.encode_cell({ r, c });
    const isNum = typeof val === 'number' && !Number.isNaN(val);
    ws[ref] = { v: val ?? '', t: isNum ? 'n' : 's', s: style };
  }

  const questions = detail?.questions || [];
  const allRows = Array.isArray(rows) ? rows : (detail?.responses || []);

  // ── Лист 1: Ответы ──
  const ws1 = {};
  let r = 0;
  setCell(ws1, r++, 0, `Опрос: ${detail?.title || ''}`, sTitle);
  setCell(ws1, r++, 0, `Дата выгрузки: ${formatDateTime(new Date())}`, sInfo);
  setCell(ws1, r++, 0, `Ответов: ${allRows.length}`, sInfo);
  r++;

  const baseHeaders = ['№ ресторана', 'Город', 'Адрес', 'Дата ответа'];
  baseHeaders.forEach((h, i) => setCell(ws1, r, i, h, sHeaderLeft));
  questions.forEach((q, i) => setCell(ws1, r, baseHeaders.length + i, q.text || `Вопрос ${i + 1}`, sHeader));
  r++;

  allRows.forEach((resp, idx) => {
    const stripe = idx % 2 === 1;
    const restLabel = formatRestaurantNumber(resp.restaurant_number, resp.legal_entity_group);
    setCell(ws1, r, 0, restLabel, sCell(stripe));
    setCell(ws1, r, 1, resp.city || '', sCell(stripe));
    setCell(ws1, r, 2, resp.address || '', sCell(stripe));
    setCell(ws1, r, 3, formatDateTime(resp.submitted_at), sCell(stripe));

    const answersByQ = {};
    (resp.answers || []).forEach(a => { answersByQ[a.question_id] = a; });
    questions.forEach((q, qi) => {
      const a = answersByQ[q.id];
      const val = answerCellValue(a);
      const align = q.type === 'scale' ? 'center' : 'left';
      setCell(ws1, r, baseHeaders.length + qi, val, sCell(stripe, align));
    });
    r++;
  });

  // Если был комментарий — добавим отдельной строкой
  const withComments = allRows.filter(r => (r.comment || '').trim());
  if (withComments.length) {
    r++;
    setCell(ws1, r++, 0, 'Комментарии ресторанов', { ...sTitle, font: { ...sTitle.font, sz: 13 } });
    setCell(ws1, r, 0, '№ ресторана', sHeaderLeft);
    setCell(ws1, r, 1, 'Комментарий', sHeaderLeft);
    r++;
    withComments.forEach((resp, idx) => {
      const stripe = idx % 2 === 1;
      setCell(ws1, r, 0, formatRestaurantNumber(resp.restaurant_number, resp.legal_entity_group), sCell(stripe));
      setCell(ws1, r, 1, resp.comment || '', sCell(stripe));
      r++;
    });
  }

  const lastCol1 = Math.max(baseHeaders.length - 1, baseHeaders.length + questions.length - 1, 1);
  ws1['!ref'] = XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: Math.max(r - 1, 4), c: lastCol1 } });
  ws1['!cols'] = [
    { wch: 16 },
    { wch: 16 },
    { wch: 28 },
    { wch: 18 },
    ...questions.map(() => ({ wch: 24 })),
  ];
  ws1['!merges'] = [
    { s: { r: 0, c: 0 }, e: { r: 0, c: lastCol1 } },
    { s: { r: 1, c: 0 }, e: { r: 1, c: lastCol1 } },
    { s: { r: 2, c: 0 }, e: { r: 2, c: lastCol1 } },
  ];

  // ── Лист 2: Аналитика ──
  const ws2 = {};
  let r2 = 0;
  setCell(ws2, r2++, 0, `Аналитика — ${detail?.title || ''}`, sTitle);
  setCell(ws2, r2++, 0, `Всего ответов: ${(detail?.responses || []).length}`, sInfo);
  r2++;

  questions.forEach((q, qi) => {
    setCell(ws2, r2, 0, `${qi + 1}. ${q.text || ''}`, { ...sHeaderLeft, fill: undefined, font: { ...sHeader.font, color: { rgb: brown } } });
    setCell(ws2, r2, 1, '', { ...sCell(false), border: undefined });
    setCell(ws2, r2, 2, '', { ...sCell(false), border: undefined });
    r2++;

    if (q.type === 'text') {
      setCell(ws2, r2, 0, 'Ответ', sHeaderLeft);
      setCell(ws2, r2, 1, 'Ресторан', sHeaderLeft);
      setCell(ws2, r2, 2, '', sHeader);
      r2++;
      const textAnswers = [];
      (detail?.responses || []).forEach(resp => {
        (resp.answers || []).forEach(a => {
          if (a.question_id === q.id && (a.text_value || '').trim()) {
            textAnswers.push({
              text: a.text_value,
              rest: formatRestaurantNumber(resp.restaurant_number, resp.legal_entity_group),
            });
          }
        });
      });
      if (!textAnswers.length) {
        setCell(ws2, r2, 0, '— нет ответов —', sCell(false));
        setCell(ws2, r2, 1, '', sCell(false));
        setCell(ws2, r2, 2, '', sCell(false));
        r2++;
      } else {
        textAnswers.forEach((a, idx) => {
          const stripe = idx % 2 === 1;
          setCell(ws2, r2, 0, a.text, sCell(stripe));
          setCell(ws2, r2, 1, a.rest, sCell(stripe));
          setCell(ws2, r2, 2, '', sCell(stripe));
          r2++;
        });
      }
    } else {
      setCell(ws2, r2, 0, 'Вариант', sHeaderLeft);
      setCell(ws2, r2, 1, 'Ответов', sHeader);
      setCell(ws2, r2, 2, '%', sHeader);
      r2++;
      if (q.type === 'scale' && q.average_score != null) {
        setCell(ws2, r2, 0, 'Средняя оценка', sCell(false));
        setCell(ws2, r2, 1, Number(q.average_score), { ...sCell(false, 'center'), font: { ...sCell(false).font, bold: true } });
        setCell(ws2, r2, 2, '', sCell(false));
        r2++;
      }
      (q.options || []).forEach((opt, idx) => {
        const stripe = idx % 2 === 1;
        setCell(ws2, r2, 0, opt.text, sCell(stripe));
        setCell(ws2, r2, 1, Number(opt.responses_count || 0), sCell(stripe, 'center'));
        setCell(ws2, r2, 2, Number(opt.responses_percent || 0), { ...sCell(stripe, 'center'), numFmt: '0"%"' });
        r2++;
      });
    }
    r2++;
  });

  ws2['!ref'] = XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: Math.max(r2 - 1, 4), c: 2 } });
  ws2['!cols'] = [{ wch: 50 }, { wch: 14 }, { wch: 10 }];
  ws2['!merges'] = [
    { s: { r: 0, c: 0 }, e: { r: 0, c: 2 } },
    { s: { r: 1, c: 0 }, e: { r: 1, c: 2 } },
  ];

  // ── Лист 3: Не ответили ──
  const ws3 = {};
  let r3 = 0;
  const pending = detail?.pending_restaurants || [];
  setCell(ws3, r3++, 0, 'Не ответили', sTitle);
  setCell(ws3, r3++, 0, `Всего: ${pending.length}`, sInfo);
  r3++;

  setCell(ws3, r3, 0, '№ ресторана', sHeaderLeft);
  setCell(ws3, r3, 1, 'Город', sHeaderLeft);
  setCell(ws3, r3, 2, 'Адрес', sHeaderLeft);
  r3++;

  pending.forEach((p, idx) => {
    const stripe = idx % 2 === 1;
    setCell(ws3, r3, 0, formatRestaurantNumber(p.restaurant_number, p.legal_entity_group), sCell(stripe));
    setCell(ws3, r3, 1, p.city || '', sCell(stripe));
    setCell(ws3, r3, 2, p.address || '', sCell(stripe));
    r3++;
  });

  if (!pending.length) {
    setCell(ws3, r3, 0, 'Ответили все рестораны', sCell(false));
    r3++;
  }

  ws3['!ref'] = XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: Math.max(r3 - 1, 4), c: 2 } });
  ws3['!cols'] = [{ wch: 16 }, { wch: 18 }, { wch: 36 }];
  ws3['!merges'] = [
    { s: { r: 0, c: 0 }, e: { r: 0, c: 2 } },
    { s: { r: 1, c: 0 }, e: { r: 1, c: 2 } },
  ];

  // ── Сборка книги ──
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws1, 'Ответы');
  XLSX.utils.book_append_sheet(wb, ws2, 'Аналитика');
  XLSX.utils.book_append_sheet(wb, ws3, 'Не ответили');

  const now = new Date();
  const dateStr = `${String(now.getDate()).padStart(2, '0')}-${String(now.getMonth() + 1).padStart(2, '0')}-${now.getFullYear()}`;
  const safeTitle = (detail?.title || 'Опрос').replace(/[\\/:*?"<>|]+/g, '_').slice(0, 60);
  XLSX.writeFile(wb, `Опрос_${safeTitle}_${dateStr}.xlsx`);
}
