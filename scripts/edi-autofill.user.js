// ==UserScript==
// @name         EDI CTT — автозаполнение накладной из Excel
// @namespace    bk-calc
// @version      0.1.0
// @description  Загружает Excel (gtin.xlsx) и автоматически заполняет 6 полей в диалоге "Добавление товара" на edi.ctt.by
// @match        https://edi.ctt.by/*
// @run-at       document-idle
// @grant        none
// @require      https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js
// ==/UserScript==

(function () {
  'use strict';

  // ---------- состояние ----------
  const state = {
    rows: [],          // все строки из Excel: {sheet, restNo, restAddr, gtin, name, qty, mass, price, status}
    filtered: [],      // отфильтрованные строки (по листу/ресторану)
    sheetFilter: '',
    restFilter: '',
    currentIdx: -1,    // индекс текущей строки в filtered
  };

  // ---------- утилиты ----------
  function log(...args) { console.log('[EDI-AUTOFILL]', ...args); }
  function warn(...args) { console.warn('[EDI-AUTOFILL]', ...args); }

  // Установка значения в input так, чтобы Angular reactive form увидела изменение
  function setNativeValue(el, value) {
    const proto = el.tagName === 'TEXTAREA'
      ? window.HTMLTextAreaElement.prototype
      : window.HTMLInputElement.prototype;
    const setter = Object.getOwnPropertyDescriptor(proto, 'value').set;
    setter.call(el, value);
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function fireBlur(el) {
    el.dispatchEvent(new Event('blur', { bubbles: true }));
    el.dispatchEvent(new FocusEvent('focusout', { bubbles: true }));
  }

  // Форматирование числа: точка как разделитель, без лишних нулей
  function fmtNum(n) {
    if (n == null || n === '') return '';
    return String(n).replace(',', '.');
  }

  // Ждать появления элемента (MutationObserver + polling)
  function waitFor(fn, timeout = 3000) {
    return new Promise((resolve, reject) => {
      const t0 = Date.now();
      const iv = setInterval(() => {
        let r;
        try { r = fn(); } catch (e) {}
        if (r) {
          clearInterval(iv);
          resolve(r);
        } else if (Date.now() - t0 > timeout) {
          clearInterval(iv);
          reject(new Error('waitFor timeout'));
        }
      }, 50);
    });
  }

  // Найти диалог "Добавление товара" на странице
  function findDialog() {
    const dialogs = document.querySelectorAll('.p-dialog');
    for (const d of dialogs) {
      const title = d.querySelector('.p-dialog-title');
      if (title && title.textContent.trim().startsWith('Добавление товара')) return d;
    }
    return null;
  }

  // Найти input внутри компонента по formcontrolname
  function findInputByFC(root, fcName) {
    const host = root.querySelector(`[formcontrolname="${fcName}"]`);
    if (!host) return null;
    // кастомные компоненты: app-numberbox/app-textbox/app-textarea → внутри обычный input/textarea
    return host.querySelector('input, textarea');
  }

  // Заполнить обычное поле по formcontrolname
  function fillField(dialog, fcName, value) {
    const el = findInputByFC(dialog, fcName);
    if (!el) { warn('поле не найдено:', fcName); return false; }
    if (el.disabled) { warn('поле disabled:', fcName); return false; }
    el.focus();
    setNativeValue(el, fmtNum(value));
    fireBlur(el);
    log('заполнено', fcName, '=', value);
    return true;
  }

  // Открыть кастомный селект (app-selectbox / app-auto-select-box) по formcontrolname
  // и кликнуть опцию с нужным текстом
  async function pickSelectOption(dialog, fcName, optionText) {
    const host = dialog.querySelector(`[formcontrolname="${fcName}"]`);
    if (!host) { warn('селект не найден:', fcName); return false; }
    // клик по видимому input (readonly) — должен раскрыть дропдаун
    const trigger = host.querySelector('input') || host;
    trigger.click();
    // PrimeNG обычно рендерит опции в p-overlay внизу body. Ищем элемент со совпадающим текстом.
    try {
      const opt = await waitFor(() => {
        // Варианты контейнеров: ul.p-dropdown-items li, .p-listbox-item, template с опциями
        const sels = [
          '.p-dropdown-items li',
          '.p-listbox-item',
          '.cdk-overlay-container li',
          '[role="option"]',
        ];
        for (const sel of sels) {
          const list = document.querySelectorAll(sel);
          for (const li of list) {
            if (li.textContent.trim().toLowerCase().includes(optionText.toLowerCase())) return li;
          }
        }
        return null;
      }, 1500);
      opt.click();
      log('селект', fcName, '→', optionText);
      return true;
    } catch (e) {
      warn('не удалось выбрать опцию', fcName, '→', optionText, '— выберите вручную');
      return false;
    }
  }

  // Кастомный селект без formcontrolname — ищем по name атрибуту
  async function pickSelectByName(dialog, nameAttr, optionText) {
    const host = dialog.querySelector(`[name="${nameAttr}"]`);
    if (!host) { warn('селект (name) не найден:', nameAttr); return false; }
    const trigger = host.querySelector('input') || host;
    trigger.click();
    try {
      const opt = await waitFor(() => {
        const sels = ['.p-dropdown-items li', '.p-listbox-item', '.cdk-overlay-container li', '[role="option"]'];
        for (const sel of sels) {
          const list = document.querySelectorAll(sel);
          for (const li of list) {
            if (li.textContent.trim().toLowerCase().includes(optionText.toLowerCase())) return li;
          }
        }
        return null;
      }, 1500);
      opt.click();
      log('селект(name)', nameAttr, '→', optionText);
      return true;
    } catch (e) {
      warn('не удалось выбрать опцию (name)', nameAttr, '→', optionText);
      return false;
    }
  }

  // Главная функция — заполнить все 6 полей в открытом диалоге
  async function fillDialog(row) {
    const dialog = findDialog();
    if (!dialog) { warn('диалог "Добавление товара" не открыт'); return; }

    log('начинаем заполнение для GTIN', row.gtin);

    // 1. Вид товарной позиции → "Товар"
    await pickSelectOption(dialog, 'itemType', 'Товар');
    await new Promise(r => setTimeout(r, 200));

    // 2. Единица измерения → "штук" (без formcontrolname, берём по name)
    await pickSelectByName(dialog, 'Единица измерения', 'штук');
    await new Promise(r => setTimeout(r, 200));

    // 3. Количество
    fillField(dialog, 'quantityDespatch', row.qty);

    // 4. Масса груза, тонны
    fillField(dialog, 'grossWeight', row.mass);

    // 5. Цена
    fillField(dialog, 'priceNet', row.price);

    // 6. Ставка НДС = 0
    fillField(dialog, 'vatRate', 0);

    log('готово. Проверьте и нажмите «Сохранить».');
    panel.setStatus(state.currentIdx, 'filled');
  }

  // ---------- парсинг Excel ----------
  function parseWorkbook(wb) {
    const rows = [];
    for (const sheetName of wb.SheetNames) {
      const ws = wb.Sheets[sheetName];
      const data = XLSX.utils.sheet_to_json(ws, { defval: '' });
      for (const r of data) {
        if (!r['GTIN']) continue;
        rows.push({
          sheet: sheetName,
          restNo: r['№ ресторана'] ?? '',
          restAddr: r['Адрес ресторана'] ?? '',
          gtin: String(r['GTIN']).trim(),
          name: r['Товар'] ?? '',
          qty: r['Количество'] ?? '',
          mass: r['Масса груза, тонн'] ?? '',
          price: r['Залоговая Цена'] ?? '',
          status: 'pending', // pending | filled | done
        });
      }
    }
    return rows;
  }

  // ---------- UI панель ----------
  const panel = (() => {
    const root = document.createElement('div');
    root.id = 'edi-autofill-panel';
    root.innerHTML = `
      <style>
        #edi-autofill-panel {
          position: fixed; right: 12px; bottom: 12px; width: 420px; max-height: 70vh;
          background: #fff; border: 2px solid #502314; border-radius: 10px;
          box-shadow: 0 4px 20px rgba(0,0,0,0.2); z-index: 99999;
          font: 12px/1.4 Arial, sans-serif; color: #222;
          display: flex; flex-direction: column; overflow: hidden;
        }
        #edi-autofill-panel .eh {
          background: #502314; color: #fff; padding: 8px 10px; font-weight: bold;
          display: flex; justify-content: space-between; align-items: center;
        }
        #edi-autofill-panel .eh button {
          background: none; border: none; color: #fff; cursor: pointer; font-size: 14px;
        }
        #edi-autofill-panel .eb { padding: 8px 10px; overflow-y: auto; flex: 1; }
        #edi-autofill-panel .row { display: flex; gap: 6px; margin-bottom: 6px; align-items: center; }
        #edi-autofill-panel select, #edi-autofill-panel input[type=file], #edi-autofill-panel button {
          font-size: 12px; padding: 4px 6px; border: 1px solid #aaa; border-radius: 4px; background: #fff;
        }
        #edi-autofill-panel button { cursor: pointer; }
        #edi-autofill-panel button.primary { background: #FF8732; color: #fff; border-color: #FF8732; font-weight: bold; }
        #edi-autofill-panel table { width: 100%; border-collapse: collapse; font-size: 11px; }
        #edi-autofill-panel th, #edi-autofill-panel td { border: 1px solid #ddd; padding: 3px 4px; text-align: left; }
        #edi-autofill-panel th { background: #f0ebe5; position: sticky; top: 0; }
        #edi-autofill-panel tr.current { background: #fff4e0; }
        #edi-autofill-panel tr.done { background: #e5ffe5; opacity: 0.7; }
        #edi-autofill-panel .muted { color: #888; font-size: 11px; }
        #edi-autofill-panel .gtin-code {
          background: #f0ebe5; padding: 2px 6px; border-radius: 3px;
          font-family: monospace; font-size: 12px; cursor: pointer;
        }
      </style>
      <div class="eh">
        <span>EDI авто-заполнение</span>
        <button id="ep-min">_</button>
      </div>
      <div class="eb">
        <div class="row">
          <input type="file" id="ep-file" accept=".xlsx,.xls">
        </div>
        <div class="row">
          <label>Лист:</label>
          <select id="ep-sheet"><option value="">все</option></select>
          <label>Ресторан:</label>
          <select id="ep-rest"><option value="">все</option></select>
        </div>
        <div class="row">
          <span class="muted">Текущий GTIN:</span>
          <span class="gtin-code" id="ep-current-gtin" title="клик — скопировать">—</span>
          <button id="ep-copy">копировать</button>
        </div>
        <div class="row">
          <button id="ep-fill" class="primary">Заполнить сейчас</button>
          <button id="ep-next">Следующая →</button>
          <span class="muted" id="ep-progress">0/0</span>
        </div>
        <table id="ep-table">
          <thead><tr><th>#</th><th>Ресторан</th><th>GTIN</th><th>Товар</th><th>Кол</th><th>Статус</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
    `;
    document.body.appendChild(root);

    const $ = sel => root.querySelector(sel);
    let minimized = false;

    $('#ep-min').onclick = () => {
      minimized = !minimized;
      $('.eb').style.display = minimized ? 'none' : 'block';
    };

    $('#ep-file').onchange = async (e) => {
      const f = e.target.files[0];
      if (!f) return;
      const buf = await f.arrayBuffer();
      const wb = XLSX.read(buf, { type: 'array' });
      state.rows = parseWorkbook(wb);
      log('загружено строк:', state.rows.length);
      // наполняем фильтры
      const sheets = [...new Set(state.rows.map(r => r.sheet))];
      $('#ep-sheet').innerHTML = '<option value="">все</option>' +
        sheets.map(s => `<option>${s}</option>`).join('');
      rebuildRestFilter();
      applyFilter();
    };

    function rebuildRestFilter() {
      const sheet = $('#ep-sheet').value;
      const rows = sheet ? state.rows.filter(r => r.sheet === sheet) : state.rows;
      const rests = [...new Set(rows.map(r => `${r.restNo} — ${r.restAddr}`))].sort();
      $('#ep-rest').innerHTML = '<option value="">все</option>' +
        rests.map(r => `<option>${r}</option>`).join('');
    }

    $('#ep-sheet').onchange = () => { rebuildRestFilter(); applyFilter(); };
    $('#ep-rest').onchange = applyFilter;

    function applyFilter() {
      const sheet = $('#ep-sheet').value;
      const rest = $('#ep-rest').value;
      state.filtered = state.rows.filter(r => {
        if (sheet && r.sheet !== sheet) return false;
        if (rest && `${r.restNo} — ${r.restAddr}` !== rest) return false;
        return true;
      });
      state.currentIdx = state.filtered.length ? 0 : -1;
      renderTable();
    }

    function renderTable() {
      const tbody = $('#ep-table tbody');
      tbody.innerHTML = state.filtered.map((r, i) => {
        const cls = i === state.currentIdx ? 'current' : (r.status === 'done' ? 'done' : '');
        return `<tr class="${cls}" data-i="${i}">
          <td>${i + 1}</td>
          <td>${r.restNo}</td>
          <td>${r.gtin}</td>
          <td title="${r.name}">${String(r.name).slice(0, 30)}</td>
          <td>${r.qty}</td>
          <td>${r.status}</td>
        </tr>`;
      }).join('');
      tbody.querySelectorAll('tr').forEach(tr => {
        tr.onclick = () => {
          state.currentIdx = +tr.dataset.i;
          updateCurrent();
          renderTable();
        };
      });
      updateCurrent();
    }

    function updateCurrent() {
      const r = state.filtered[state.currentIdx];
      $('#ep-current-gtin').textContent = r ? r.gtin : '—';
      const done = state.filtered.filter(x => x.status === 'done').length;
      $('#ep-progress').textContent = `${done}/${state.filtered.length}`;
    }

    $('#ep-copy').onclick = () => {
      const r = state.filtered[state.currentIdx];
      if (r) navigator.clipboard.writeText(String(r.gtin)).then(() => log('GTIN скопирован'));
    };
    $('#ep-current-gtin').onclick = () => $('#ep-copy').click();

    $('#ep-fill').onclick = () => {
      const r = state.filtered[state.currentIdx];
      if (!r) { warn('нет текущей строки'); return; }
      fillDialog(r);
    };

    $('#ep-next').onclick = () => {
      if (state.currentIdx >= 0) state.filtered[state.currentIdx].status = 'done';
      if (state.currentIdx < state.filtered.length - 1) state.currentIdx++;
      renderTable();
    };

    return {
      setStatus(idx, status) {
        if (state.filtered[idx]) {
          state.filtered[idx].status = status;
          renderTable();
        }
      },
    };
  })();

  // ---------- автонаблюдение за открытием диалога ----------
  // Когда открывается "Добавление товара" — ничего не делаем сразу (ждём, пока пользователь
  // нажмёт Поиск и выберет товар). Но подсвечиваем панель.
  // Когда диалог закрывается — помечаем текущую строку как done, сдвигаем курсор.
  let dialogWasOpen = false;
  setInterval(() => {
    const d = findDialog();
    const isOpen = !!d;
    if (isOpen && !dialogWasOpen) {
      log('диалог открыт');
    }
    if (!isOpen && dialogWasOpen) {
      log('диалог закрыт → помечаем текущую строку done');
      if (state.currentIdx >= 0 && state.filtered[state.currentIdx]) {
        state.filtered[state.currentIdx].status = 'done';
        if (state.currentIdx < state.filtered.length - 1) state.currentIdx++;
        // перерисуем через panel — но у нас нет прямого доступа. Имитируем через клик.
        const evt = new Event('change');
        // проще: ре-вызываем applyFilter через изменение селекта — хак. Вместо этого просто перерисуем.
        const tbody = document.querySelector('#edi-autofill-panel #ep-table tbody');
        if (tbody) {
          // триггерим ререндер вручную
          tbody.innerHTML = state.filtered.map((r, i) => {
            const cls = i === state.currentIdx ? 'current' : (r.status === 'done' ? 'done' : '');
            return `<tr class="${cls}" data-i="${i}">
              <td>${i + 1}</td><td>${r.restNo}</td><td>${r.gtin}</td>
              <td title="${r.name}">${String(r.name).slice(0, 30)}</td>
              <td>${r.qty}</td><td>${r.status}</td>
            </tr>`;
          }).join('');
          const gtinEl = document.querySelector('#edi-autofill-panel #ep-current-gtin');
          const progEl = document.querySelector('#edi-autofill-panel #ep-progress');
          const cur = state.filtered[state.currentIdx];
          if (gtinEl) gtinEl.textContent = cur ? cur.gtin : '—';
          const done = state.filtered.filter(x => x.status === 'done').length;
          if (progEl) progEl.textContent = `${done}/${state.filtered.length}`;
        }
      }
    }
    dialogWasOpen = isOpen;
  }, 500);

  log('userscript загружен. Панель справа внизу.');
})();
