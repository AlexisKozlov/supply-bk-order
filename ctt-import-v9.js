// CTT EDI — Импорт товаров в ТТН v9 (Chrome Snippet)
// F12 → Sources → Snippets → Run (Ctrl+Enter)

(function () {
  if (document.getElementById("ctt-fab")) { document.getElementById("ctt-panel").style.display = "flex"; return; }

  var CFG = { delayShort: 150, delayMed: 300, delayLong: 600, maxWait: 10000 };
  var delay = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };
  var DATA = [];
  var panel, logArea, statusEl, progressEl;
  var running = false, stopRequested = false, paused = false;

  function waitFor(sel, parent, timeout) {
    parent = parent || document; timeout = timeout || CFG.maxWait;
    return new Promise(function (resolve, reject) {
      var el = parent.querySelector(sel);
      if (el) return resolve(el);
      var obs = new MutationObserver(function () {
        var el = parent.querySelector(sel);
        if (el) { obs.disconnect(); resolve(el); }
      });
      obs.observe(parent === document ? document.body : parent, { childList: true, subtree: true });
      setTimeout(function () { obs.disconnect(); reject(new Error("Таймаут: " + sel)); }, timeout);
    });
  }

  function findBtn(text, parent) {
    var btns = (parent || document).querySelectorAll("button");
    for (var i = 0; i < btns.length; i++) if (btns[i].textContent.trim().indexOf(text) !== -1) return btns[i];
    return null;
  }

  function setVal(input, value) {
    var setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, "value").set;
    input.focus();
    setter.call(input, String(value));
    input.dispatchEvent(new Event("input", { bubbles: true }));
    input.dispatchEvent(new Event("change", { bubbles: true }));
    input.dispatchEvent(new KeyboardEvent("keyup", { bubbles: true }));
    input.dispatchEvent(new Event("blur", { bubbles: true }));
  }

  async function pickOption(selectEl, text) {
    // Ждём пока все app-list закроются сами
    for (var c = 0; c < 15; c++) {
      var open = false;
      document.querySelectorAll("app-list").forEach(function(l){ if(l.offsetParent !== null) open = true; });
      if (!open) break;
      await delay(200);
    }

    // Кликаем по app-icon
    var icon = selectEl.querySelector("app-icon");
    if (icon) {
      icon.scrollIntoView({block:"center"});
      await delay(200);
      icon.click();
    } else {
      var inp = selectEl.querySelector("input");
      if (inp) inp.click();
    }

    // Ждём появления app-list с нужным текстом
    var appList = null;
    for (var attempt = 0; attempt < 15; attempt++) {
      var lists = document.querySelectorAll("app-list");
      for (var i = 0; i < lists.length; i++) {
        if (lists[i].offsetParent !== null && lists[i].textContent.indexOf(text) !== -1) {
          appList = lists[i]; break;
        }
      }
      if (appList) break;
      await delay(300);
    }

    if (!appList) return false;

    // Кликаем по опции — точное совпадение
    var children = appList.querySelectorAll("*");
    for (var k = 0; k < children.length; k++) {
      if (children[k].textContent.trim().toLowerCase() === text.toLowerCase()) {
        children[k].click();
        await delay(200);
        return true;
      }
    }
    // Фоллбэк — частичное совпадение по листовым
    for (var m = 0; m < children.length; m++) {
      if (children[m].children.length === 0 && children[m].textContent.trim().toLowerCase().indexOf(text.toLowerCase()) !== -1) {
        children[m].click();
        await delay(200);
        return true;
      }
    }
    return false;
  }

  function getRests() {
    var m = {}; DATA.forEach(function (d) { if (!m[d.r]) m[d.r] = d.o; });
    return Object.keys(m).map(function (r) { return { label: r, order: m[r] }; });
  }
  function getModes(rest) {
    var s = {}; DATA.forEach(function (d) { if (d.r === rest) s[d.s] = 1; }); return Object.keys(s);
  }
  function getProds(rest, stor) {
    return DATA.filter(function (d) { return d.r === rest && d.s === stor; });
  }

  function makeDraggable(header, el) {
    var drag = false, sx, sy, sl, st;
    header.style.cursor = "move";
    header.addEventListener("mousedown", function (e) {
      if (e.target.tagName === "BUTTON") return;
      drag = true;
      var r = el.getBoundingClientRect();
      sx = e.clientX; sy = e.clientY; sl = r.left; st = r.top;
      el.style.transform = "none"; el.style.left = r.left + "px"; el.style.top = r.top + "px";
      e.preventDefault();
    });
    document.addEventListener("mousemove", function (e) {
      if (!drag) return;
      el.style.left = (sl + e.clientX - sx) + "px"; el.style.top = (st + e.clientY - sy) + "px";
    });
    document.addEventListener("mouseup", function () { drag = false; });
  }

  function createUI() {
    var fab = document.createElement("div");
    fab.id = "ctt-fab";
    fab.textContent = "\u{1F4E6} Импорт ТТН";
    fab.style.cssText = "position:fixed;bottom:20px;right:20px;z-index:99999;background:#2563eb;color:#fff;padding:12px 20px;border-radius:8px;cursor:pointer;font-size:15px;font-weight:600;box-shadow:0 4px 12px rgba(0,0,0,0.3);font-family:system-ui,sans-serif;user-select:none;";
    fab.onclick = function () { panel.style.display = panel.style.display === "none" ? "flex" : "none"; };
    document.body.appendChild(fab);

    panel = document.createElement("div");
    panel.id = "ctt-panel";
    panel.style.cssText = "position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:100000;background:#fff;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,0.3);padding:0;display:flex;flex-direction:column;width:660px;max-height:85vh;font-family:system-ui,sans-serif;overflow:hidden;";

    panel.innerHTML =
      '<div id="ctt-header" style="display:flex;justify-content:space-between;align-items:center;padding:16px 24px;background:#f8fafc;border-bottom:1px solid #e5e7eb;user-select:none;">'
      + '<h3 style="margin:0;font-size:18px;">\u{1F4E6} Импорт товаров в ТТН</h3>'
      + '<div style="display:flex;gap:8px;">'
      + '<button id="ctt-min" style="background:none;border:none;font-size:16px;cursor:pointer;color:#999;padding:4px;">\u{1F53D}</button>'
      + '<button id="ctt-close" style="background:none;border:none;font-size:20px;cursor:pointer;color:#999;padding:4px;">\u2715</button>'
      + '</div></div>'
      + '<div id="ctt-body" style="padding:20px 24px;display:flex;flex-direction:column;gap:14px;overflow-y:auto;">'
      + '<div id="ctt-load-section" style="border:2px dashed #d1d5db;border-radius:8px;padding:16px;text-align:center;">'
      + '<p style="margin:0 0 8px;font-size:14px;color:#555;">Загрузите файл <b>data-*.json</b></p>'
      + '<input type="file" id="ctt-file" accept=".json" style="font-size:14px;">'
      + '<p id="ctt-data-status" style="margin:8px 0 0;font-size:13px;color:#999;"></p></div>'
      + '<div id="ctt-work" style="display:none;flex-direction:column;gap:14px;">'
      + '<div style="display:flex;gap:12px;">'
      + '<div style="flex:1;"><label style="font-size:13px;font-weight:600;color:#555;display:block;margin-bottom:4px;">Ресторан</label>'
      + '<select id="ctt-rest" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;"></select></div>'
      + '<div style="flex:0 0 160px;"><label style="font-size:13px;font-weight:600;color:#555;display:block;margin-bottom:4px;">Хранение</label>'
      + '<select id="ctt-stor" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;"><option value="">\u2014</option></select></div></div>'
      + '<div id="ctt-preview" style="max-height:180px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;background:#f9fafb;"></div>'
      + '<div style="display:flex;gap:8px;align-items:center;">'
      + '<button id="ctt-start" style="background:#2563eb;color:#fff;border:none;padding:10px 28px;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;" disabled>\u25B6 Заполнить</button>'
      + '<button id="ctt-pause" style="background:#f59e0b;color:#fff;border:none;padding:10px 18px;border-radius:6px;font-size:14px;cursor:pointer;display:none;">\u23F8 Пауза</button>'
      + '<button id="ctt-stop" style="background:#ef4444;color:#fff;border:none;padding:10px 18px;border-radius:6px;font-size:14px;cursor:pointer;display:none;">\u23F9 Стоп</button>'
      + '<span id="ctt-status" style="font-size:13px;color:#666;margin-left:auto;"></span></div>'
      + '<div style="height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden;">'
      + '<div id="ctt-bar" style="height:100%;width:0;background:#2563eb;transition:width 0.3s;"></div></div>'
      + '<div id="ctt-log" style="max-height:160px;overflow-y:auto;font-family:monospace;font-size:12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:8px;"></div>'
      + '</div></div>';

    document.body.appendChild(panel);
    logArea = panel.querySelector("#ctt-log");
    statusEl = panel.querySelector("#ctt-status");
    progressEl = panel.querySelector("#ctt-bar");

    makeDraggable(panel.querySelector("#ctt-header"), panel);
    var body = panel.querySelector("#ctt-body");
    panel.querySelector("#ctt-min").onclick = function () {
      var h = body.style.display === "none";
      body.style.display = h ? "flex" : "none";
      this.textContent = h ? "\u{1F53D}" : "\u{1F53C}";
    };
    panel.querySelector("#ctt-close").onclick = function () { panel.style.display = "none"; };
    panel.querySelector("#ctt-file").onchange = handleFile;
    panel.querySelector("#ctt-start").onclick = startImport;
    panel.querySelector("#ctt-pause").onclick = function () {
      paused = !paused;
      panel.querySelector("#ctt-pause").textContent = paused ? "\u25B6 Продолжить" : "\u23F8 Пауза";
    };
    panel.querySelector("#ctt-stop").onclick = function () { stopRequested = true; };

    try {
      var saved = localStorage.getItem("ctt_import_data");
      if (saved) { DATA = JSON.parse(saved); showWork(); panel.querySelector("#ctt-data-status").textContent = "\u2705 Из памяти: " + DATA.length + " товаров"; }
    } catch (e) {}
  }

  function handleFile(e) {
    var f = e.target.files[0]; if (!f) return;
    var r = new FileReader();
    r.onload = function (ev) {
      try {
        DATA = JSON.parse(ev.target.result);
        localStorage.setItem("ctt_import_data", ev.target.result);
        showWork();
        panel.querySelector("#ctt-data-status").textContent = "\u2705 Загружено: " + DATA.length + " товаров";
      } catch (err) { panel.querySelector("#ctt-data-status").textContent = "\u274C Ошибка: " + err.message; }
    };
    r.readAsText(f);
  }

  function showWork() {
    panel.querySelector("#ctt-work").style.display = "flex";
    var sel = panel.querySelector("#ctt-rest");
    sel.innerHTML = '<option value="">\u2014 ресторан \u2014</option>';
    getRests().forEach(function (r) { sel.innerHTML += '<option value="' + r.label + '">' + r.order + ' | ' + r.label + '</option>'; });
    sel.onchange = onRest;
    panel.querySelector("#ctt-stor").onchange = onStor;
  }

  function onRest() {
    var rest = panel.querySelector("#ctt-rest").value;
    var ss = panel.querySelector("#ctt-stor");
    ss.innerHTML = '<option value="">\u2014</option>';
    if (!rest) { updPrev([]); return; }
    var modes = getModes(rest);
    modes.forEach(function (m) { ss.innerHTML += '<option value="' + m + '">' + m + '</option>'; });
    if (modes.length === 1) { ss.value = modes[0]; onStor(); } else updPrev([]);
  }

  function onStor() {
    var rest = panel.querySelector("#ctt-rest").value;
    var stor = panel.querySelector("#ctt-stor").value;
    updPrev(rest && stor ? getProds(rest, stor) : []);
  }

  function updPrev(prods) {
    var pv = panel.querySelector("#ctt-preview");
    panel.querySelector("#ctt-start").disabled = !prods.length;
    if (!prods.length) { pv.innerHTML = '<div style="padding:12px;color:#999;text-align:center;">Выберите ресторан и режим</div>'; return; }
    var h = '<table style="width:100%;border-collapse:collapse;"><thead><tr style="background:#e5e7eb;">'
      + '<th style="padding:6px 8px;text-align:left;font-size:12px;">№</th>'
      + '<th style="padding:6px 8px;text-align:left;font-size:12px;">GTIN</th>'
      + '<th style="padding:6px 8px;text-align:left;font-size:12px;">Товар</th>'
      + '<th style="padding:6px 8px;text-align:right;font-size:12px;">Кол</th>'
      + '<th style="padding:6px 8px;text-align:right;font-size:12px;">Брутто</th>'
      + '<th style="padding:6px 8px;text-align:right;font-size:12px;">Цена</th>'
      + '</tr></thead><tbody>';
    prods.forEach(function (p, i) {
      h += '<tr style="background:' + (i % 2 ? "#f9fafb" : "#fff") + ';"><td style="padding:4px 8px;">' + (i+1) + '</td>'
        + '<td style="padding:4px 8px;font-family:monospace;">' + p.g + '</td>'
        + '<td style="padding:4px 8px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + p.n + '</td>'
        + '<td style="padding:4px 8px;text-align:right;">' + p.q + '</td>'
        + '<td style="padding:4px 8px;text-align:right;">' + p.w + '</td>'
        + '<td style="padding:4px 8px;text-align:right;">' + p.p + '</td></tr>';
    });
    pv.innerHTML = h + '</tbody></table>';
  }

  function log(msg, lv) {
    var c = {info:"#333",warn:"#f59e0b",error:"#ef4444",success:"#22c55e"};
    logArea.innerHTML += '<div style="color:' + (c[lv||"info"]) + '">[' + new Date().toLocaleTimeString("ru-RU") + '] ' + msg + '</div>';
    logArea.scrollTop = logArea.scrollHeight;
  }

  async function startImport() {
    var rest = panel.querySelector("#ctt-rest").value;
    var stor = panel.querySelector("#ctt-stor").value;
    var prods = getProds(rest, stor);
    if (!prods.length) return;

    running = true; stopRequested = false; paused = false;
    panel.querySelector("#ctt-start").style.display = "none";
    panel.querySelector("#ctt-rest").disabled = true;
    panel.querySelector("#ctt-stor").disabled = true;
    panel.querySelector("#ctt-pause").style.display = "inline-block";
    panel.querySelector("#ctt-stop").style.display = "inline-block";
    logArea.innerHTML = "";
    var skipped = [];
    log("Импорт: " + rest + " | " + stor + " | " + prods.length + " товаров");

    for (var i = 0; i < prods.length; i++) {
      if (stopRequested) { log("Остановлено", "warn"); break; }
      while (paused) { await delay(300); if (stopRequested) break; }
      progressEl.style.width = ((i+1)/prods.length*100) + "%";
      statusEl.textContent = (i+1) + " / " + prods.length;
      log("[" + (i+1) + "/" + prods.length + "] " + prods[i].g + " \u2014 " + prods[i].n);
      try {
        await addOne(prods[i]);
        log("  \u2713 Сохранён", "success");
      } catch (err) {
        log("  \u26A0 " + err.message + " \u2014 ПРОПУСКАЮ", "warn");
        skipped.push(prods[i].g + " " + prods[i].n);
        try {
          var ci = document.querySelector(".p-dialog-header-close-icon");
          if (ci) ci.closest("button").click();
          else { var cb = document.querySelector(".p-dialog-header-maximize"); if (cb) cb.click(); }
          await delay(CFG.delayMed);
          var cancelBtn = document.querySelector("button.simple-cancel");
          if (cancelBtn && cancelBtn.offsetParent !== null) { cancelBtn.click(); await delay(CFG.delayMed); }
        } catch(e){}
      }
      await delay(CFG.delayShort);
    }

    if (skipped.length) {
      log("\u26A0 Пропущено " + skipped.length + ":", "warn");
      skipped.forEach(function(s){ log("  \u2022 " + s, "warn"); });
    }

    running = false;
    panel.querySelector("#ctt-start").style.display = "inline-block";
    panel.querySelector("#ctt-rest").disabled = false;
    panel.querySelector("#ctt-stor").disabled = false;
    panel.querySelector("#ctt-pause").style.display = "none";
    panel.querySelector("#ctt-stop").style.display = "none";
    log("Готово!", "success");
  }

  async function addOne(p) {
    // 1. «Добавить товар»
    var addBtn = findBtn("Добавить товар");
    if (!addBtn) throw new Error("Кнопка «Добавить товар» не найдена");
    addBtn.click();

    // 2. Диалог поиска
    var dlg = await waitFor(".p-dialog");
    await delay(CFG.delayMed);

    // 3. GTIN
    var gi = dlg.querySelector('app-numberbox[formcontrolname="gtin"] input') || dlg.querySelector('input[placeholder*="GTIN"]');
    if (!gi) throw new Error("Поле GTIN не найдено");
    setVal(gi, p.g);
    await delay(CFG.delayMed);

    // 4. Поиск
    var sb = findBtn("Поиск", dlg);
    if (sb && !sb.disabled) { sb.click(); }
    else {
      gi.focus();
      gi.dispatchEvent(new KeyboardEvent("keydown", {key:"Enter",code:"Enter",keyCode:13,bubbles:true}));
      gi.dispatchEvent(new KeyboardEvent("keyup", {key:"Enter",code:"Enter",keyCode:13,bubbles:true}));
      await delay(CFG.delayShort);
      sb = findBtn("Поиск", dlg);
      if (sb && !sb.disabled) sb.click();
    }
    await delay(CFG.delayLong);

    // 5. Выбор результата
    var row;
    try { row = await waitFor("tr.p-selectable-row", dlg, 10000); }
    catch(e) { throw new Error("GTIN " + p.g + " не найден в EPASS"); }
    row.click();
    await delay(CFG.delayShort);

    // 6. Далее
    var nb = findBtn("Далее", dlg) || findBtn("Далее");
    if (!nb) throw new Error("Кнопка «Далее» не найдена");
    nb.click();

    // 7. Ждём появления кнопки Сохранить (= форма загрузилась)
    await delay(CFG.delayMed);
    for (var w = 0; w < 30; w++) {
      var sv = document.querySelector("button.action-save");
      if (sv && sv.offsetParent !== null) break;
      await delay(200);
    }
    await delay(CFG.delayShort);

    // 8. Находим форму — div.form-div
    var allForms = document.querySelectorAll("div.form-div");
    var frm = allForms[allForms.length - 1];
    if (!frm) throw new Error("Форма товара не найдена");

    // 9. Вид товарной позиции → «Товар»
    var itemSel = frm.querySelector('app-selectbox[formcontrolname="itemType"]');
    if (itemSel) {
      var ok1 = await pickOption(itemSel, "Товар");
      if (ok1) log("    Вид: Товар");
      else throw new Error("Не удалось выбрать Вид товарной позиции");
    }

    await delay(800);

    // 10. Единица измерения → «штук» (с повтором до 5 раз)
    var unitSel = frm.querySelector('app-auto-select-box');
    if (unitSel) {
      var ok2 = false;
      for (var retry = 0; retry < 5; retry++) {
        ok2 = await pickOption(unitSel, "штук");
        if (ok2) break;
        log("    Повтор ед.изм (" + (retry+2) + "/5)...");
        await delay(500);
      }
      if (ok2) log("    Ед.изм: штук");
      else throw new Error("Не удалось выбрать Единицу измерения (5 попыток)");
    }

    await delay(CFG.delayShort);

    // 11. Числовые поля
    var qi = frm.querySelector('app-numberbox[formcontrolname="quantityDespatch"] input');
    if (qi) { setVal(qi, p.q); log("    Кол-во: " + p.q); }

    var wi = frm.querySelector('app-numberbox[formcontrolname="grossWeight"] input');
    if (wi) { setVal(wi, p.w); log("    Масса: " + p.w); }

    var pi = frm.querySelector('app-numberbox[formcontrolname="priceNet"] input');
    if (pi) { setVal(pi, p.p); log("    Цена: " + p.p); }

    var vi = frm.querySelector('app-numberbox[formcontrolname="vatRate"] input');
    if (vi) { setVal(vi, "0"); log("    НДС: 0"); }

    await delay(CFG.delayMed);

    // 12. Сохранить товар
    var saveBtn = document.querySelector("button.action-save");
    if (saveBtn && saveBtn.offsetParent !== null) {
      saveBtn.click();
      log("    \u{1F4BE} Сохранение...");
      await delay(CFG.delayLong);
      await delay(CFG.delayMed);
    } else {
      throw new Error("Кнопка Сохранить не найдена");
    }
  }

  createUI();
  console.log("\u2705 CTT Импорт ТТН v9 загружен");
})();
