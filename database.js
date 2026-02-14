/**
 * Модуль для работы с базой данных товаров и поставщиков
 * database.js
 */

import { supabase } from './supabase.js';
import { showToast, customConfirm } from './modals.js';

/* ═══════════════════════════════════════
   ИНИЦИАЛИЗАЦИЯ ТАБОВ
   ═══════════════════════════════════════ */

export function initDatabaseTabs() {
  const tabs = document.querySelectorAll('.db-tab');
  const newProductBtn = document.getElementById('dbNewProductBtn');
  const newSupplierBtn = document.getElementById('dbNewSupplierBtn');

  tabs.forEach(tab => {
    tab.addEventListener('click', () => {
      tabs.forEach(t => t.classList.remove('active'));
      tab.classList.add('active');

      const tabName = tab.dataset.tab;
      document.getElementById('dbTabProducts').classList.toggle('hidden', tabName !== 'products');
      document.getElementById('dbTabSuppliers').classList.toggle('hidden', tabName !== 'suppliers');

      // Показываем нужную кнопку в заголовке
      if (newProductBtn) newProductBtn.style.display = tabName === 'products' ? '' : 'none';
      if (newSupplierBtn) newSupplierBtn.style.display = tabName === 'suppliers' ? '' : 'none';

      if (tabName === 'suppliers') {
        const el = document.getElementById('dbSupplierLegalEntity');
        const list = document.getElementById('supplierList');
        loadSuppliers(el, list);
      } else {
        // Обновляем счётчик при возврате на товары
        refreshProducts();
      }
    });
  });

  // Инициально показываем кнопку товаров
  if (newProductBtn) newProductBtn.style.display = '';
  if (newSupplierBtn) newSupplierBtn.style.display = 'none';
}

/* ═══════════════════════════════════════
   ТОВАРЫ — CRUD
   ═══════════════════════════════════════ */

export async function loadDatabaseProducts(dbLegalEntitySelect, databaseList) {
  databaseList.innerHTML = '<div style="text-align:center;padding:20px;"><div class="loading-spinner"></div><div>Загрузка...</div></div>';

  const legalEntity = dbLegalEntitySelect.value;

  let query = supabase.from('products').select('*').order('name');
  if (legalEntity === 'Пицца Стар') {
    query = query.eq('legal_entity', 'Пицца Стар');
  } else {
    query = query.in('legal_entity', ['Бургер БК', 'Воглия Матта']);
  }

  const { data, error } = await query;

  if (error) {
    databaseList.innerHTML = '<div style="text-align:center;color:var(--error);">Ошибка загрузки</div>';
    console.error(error);
    return;
  }

  renderDatabaseList(data, databaseList);

  const countEl = document.getElementById('dbCardCount');
  if (countEl) countEl.textContent = `(${data.length})`;
}

function renderDatabaseList(products, databaseList) {
  if (!products.length) {
    databaseList.innerHTML = '<div style="text-align:center;padding:20px;color:var(--muted);">Карточки не найдены</div>';
    return;
  }

  databaseList.innerHTML = products.map(p => `
    <div class="db-card" data-product-id="${p.id}">
      <div class="db-card-info">
        <div class="db-card-sku">${p.sku || '—'}</div>
        <div class="db-card-name">${p.name}</div>
        <div class="db-card-supplier">${p.supplier || 'Без поставщика'}</div>
      </div>
      <div class="db-card-actions">
        <button class="btn small edit-card-btn" data-id="${p.id}"><img src="./icons/edit.png" width="14" height="14" alt=""> Изменить</button>
        <button class="btn small delete-card-btn" data-id="${p.id}" style="background:var(--error);color:white;"><img src="./icons/delete.png" width="14" height="14" alt=""></button>
      </div>
    </div>
  `).join('');

  document.querySelectorAll('.edit-card-btn').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      const id = e.target.closest('.edit-card-btn').dataset.id;
      await openEditCard(id);
    });
  });

  document.querySelectorAll('.delete-card-btn').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      const id = e.target.closest('.delete-card-btn').dataset.id;
      await deleteCard(id);
    });
  });
}

async function deleteCard(id) {
  const confirmed = await customConfirm('Удалить карточку?', 'Карточка будет удалена из базы данных и из текущего заказа');
  if (!confirmed) return;

  // Получаем SKU перед удалением — для удаления из заказа
  const { data: product } = await supabase.from('products').select('sku').eq('id', id).single();

  const { error } = await supabase.from('products').delete().eq('id', id);

  if (error) {
    showToast('Ошибка удаления', 'Не удалось удалить карточку', 'error');
    console.error(error);
    return;
  }

  // Уведомляем приложение об удалении — для очистки заказа
  document.dispatchEvent(new CustomEvent('product:deleted', { detail: { sku: product?.sku || '', id } }));

  showToast('Карточка удалена', '', 'success');
  refreshProducts();
}

/** Загрузить список поставщиков в <select> */
async function loadSupplierOptions(selectEl, currentValue) {
  const { data } = await supabase.from('suppliers').select('short_name').order('short_name');
  selectEl.innerHTML = '<option value="">— Выберите поставщика —</option>';
  if (data) {
    data.forEach(s => {
      const opt = document.createElement('option');
      opt.value = s.short_name;
      opt.textContent = s.short_name;
      selectEl.appendChild(opt);
    });
  }
  // Опция создания нового
  const newOpt = document.createElement('option');
  newOpt.value = '__new__';
  newOpt.textContent = '＋ Новый поставщик...';
  newOpt.style.color = '#c77800';
  selectEl.appendChild(newOpt);

  if (currentValue) selectEl.value = currentValue;
}

export async function openEditCard(id, onSaveCallback) {
  const { data, error } = await supabase.from('products').select('*').eq('id', id).single();

  if (error) {
    showToast('Ошибка', 'Не удалось загрузить карточку', 'error');
    console.error(error);
    return;
  }

  const editCardModal = document.getElementById('editCardModal');
  const titleEl = document.getElementById('editCardTitle');
  titleEl.innerHTML = '<img src="./icons/edit.png" width="20" height="20" style="vertical-align:-3px;" alt=""> Редактирование карточки';

  document.getElementById('e_name').value = data.name || '';
  document.getElementById('e_sku').value = data.sku || '';
  document.getElementById('e_legalEntity').value = data.legal_entity || 'Бургер БК';
  document.getElementById('e_box').value = data.qty_per_box || '';
  document.getElementById('e_pallet').value = data.boxes_per_pallet || '';
  document.getElementById('e_unit').value = data.unit_of_measure || 'шт';

  // Загружаем поставщиков в селектор
  await loadSupplierOptions(document.getElementById('e_supplier'), data.supplier);

  editCardModal.classList.remove('hidden');
  editCardModal.dataset.mode = 'edit';
  editCardModal.dataset.editId = id;

  setupEditCardHandlers(id, onSaveCallback);
}

/** Открыть модалку для НОВОГО товара */
export async function openNewProductCard() {
  const editCardModal = document.getElementById('editCardModal');
  const titleEl = document.getElementById('editCardTitle');
  titleEl.innerHTML = '<img src="./icons/edit.png" width="20" height="20" style="vertical-align:-3px;" alt=""> Новый товар';

  document.getElementById('e_name').value = '';
  document.getElementById('e_sku').value = '';
  document.getElementById('e_legalEntity').value = document.getElementById('dbLegalEntity')?.value || 'Бургер БК';
  document.getElementById('e_box').value = '';
  document.getElementById('e_pallet').value = '';
  document.getElementById('e_unit').value = 'шт';

  await loadSupplierOptions(document.getElementById('e_supplier'), '');

  editCardModal.classList.remove('hidden');
  editCardModal.dataset.mode = 'create';
  editCardModal.dataset.editId = '';

  setupEditCardHandlers(null, null);
}

function setupEditCardHandlers(editId, onSaveCallback) {
  const editCardModal = document.getElementById('editCardModal');
  const saveBtn = document.getElementById('e_save');
  const cancelBtn = document.getElementById('e_cancel');
  const closeBtn = document.getElementById('closeEditCard');
  const supplierSelect = document.getElementById('e_supplier');

  // Обработчик "Новый поставщик" в селекторе
  const handleSupplierChange = () => {
    if (supplierSelect.value === '__new__') {
      supplierSelect.value = ''; // сброс
      // Скрываем карточку товара, открываем создание поставщика
      // После сохранения — возвращаемся
      openNewSupplierAndReturn(editCardModal);
    }
  };
  supplierSelect.addEventListener('change', handleSupplierChange);

  const handleSave = async () => {
    const name = document.getElementById('e_name').value.trim();
    if (!name) {
      showToast('Введите наименование', 'Поле обязательно', 'error');
      return;
    }

    const supplierVal = supplierSelect.value;
    const productData = {
      name: name,
      sku: document.getElementById('e_sku').value || null,
      supplier: supplierVal && supplierVal !== '__new__' ? supplierVal : null,
      legal_entity: document.getElementById('e_legalEntity').value,
      qty_per_box: +document.getElementById('e_box').value || null,
      boxes_per_pallet: +document.getElementById('e_pallet').value || null,
      unit_of_measure: document.getElementById('e_unit').value
    };

    let error;
    const mode = editCardModal.dataset.mode;

    if (mode === 'create') {
      ({ error } = await supabase.from('products').insert([productData]));
    } else {
      ({ error } = await supabase.from('products').update(productData).eq('id', editId));
    }

    if (error) {
      showToast('Ошибка сохранения', error.message || 'Не удалось сохранить', 'error');
      console.error(error);
      return;
    }

    showToast(mode === 'create' ? 'Товар создан' : 'Карточка обновлена', '', 'success');
    editCardModal.classList.add('hidden');
    refreshProducts();
    if (onSaveCallback) onSaveCallback(productData);
    cleanup();
  };

  const handleClose = () => {
    editCardModal.classList.add('hidden');
    cleanup();
  };

  const cleanup = () => {
    supplierSelect.removeEventListener('change', handleSupplierChange);
    saveBtn.replaceWith(saveBtn.cloneNode(true));
    cancelBtn.replaceWith(cancelBtn.cloneNode(true));
    closeBtn.replaceWith(closeBtn.cloneNode(true));
  };

  document.getElementById('e_save').addEventListener('click', handleSave);
  document.getElementById('e_cancel').addEventListener('click', handleClose);
  document.getElementById('closeEditCard').addEventListener('click', handleClose);
}

export function setupDatabaseSearch(dbSearchInput, clearDbSearchBtn, databaseList) {
  let allProducts = [];

  dbSearchInput.addEventListener('input', () => {
    const query = dbSearchInput.value.toLowerCase();

    if (query) {
      clearDbSearchBtn.classList.remove('hidden');
      const filtered = allProducts.filter(p =>
        (p.name && p.name.toLowerCase().includes(query)) ||
        (p.sku && p.sku.toLowerCase().includes(query)) ||
        (p.supplier && p.supplier.toLowerCase().includes(query))
      );
      renderDatabaseList(filtered, databaseList);
    } else {
      clearDbSearchBtn.classList.add('hidden');
      renderDatabaseList(allProducts, databaseList);
    }
  });

  clearDbSearchBtn.addEventListener('click', () => {
    dbSearchInput.value = '';
    clearDbSearchBtn.classList.add('hidden');
    renderDatabaseList(allProducts, databaseList);
  });

  const observer = new MutationObserver(() => {
    const cards = databaseList.querySelectorAll('.db-card');
    if (cards.length > 0) {
      allProducts = Array.from(cards).map(card => ({
        id: card.dataset.productId,
        name: card.querySelector('.db-card-name')?.textContent,
        sku: card.querySelector('.db-card-sku')?.textContent,
        supplier: card.querySelector('.db-card-supplier')?.textContent
      }));
    }
  });

  observer.observe(databaseList, { childList: true });
}

export async function openEditCardBySku(sku, onSaveCallback) {
  if (!sku) return;

  const { data, error } = await supabase.from('products').select('id').eq('sku', sku).maybeSingle();

  if (error || !data) {
    console.warn('Товар с SKU не найден в базе:', sku);
    return;
  }

  openEditCard(data.id, onSaveCallback);
}

function refreshProducts() {
  const databaseList = document.getElementById('databaseList');
  const dbLegalEntitySelect = document.getElementById('dbLegalEntity');
  if (databaseList && dbLegalEntitySelect) {
    loadDatabaseProducts(dbLegalEntitySelect, databaseList);
  }
}

/* ═══════════════════════════════════════
   ПОСТАВЩИКИ — CRUD
   ═══════════════════════════════════════ */

async function loadSuppliers(legalEntitySelect, supplierList) {
  supplierList.innerHTML = '<div style="text-align:center;padding:20px;"><div class="loading-spinner"></div><div>Загрузка...</div></div>';

  const legalEntity = legalEntitySelect.value;
  let query = supabase.from('suppliers').select('*').order('short_name');
  if (legalEntity === 'Пицца Стар') {
    query = query.eq('legal_entity', 'Пицца Стар');
  } else {
    query = query.in('legal_entity', ['Бургер БК', 'Воглия Матта']);
  }

  const { data, error } = await query;
  if (error) {
    supplierList.innerHTML = '<div style="text-align:center;color:var(--error);">Ошибка загрузки</div>';
    console.error(error);
    return;
  }

  renderSupplierList(data, supplierList);

  const countEl = document.getElementById('dbCardCount');
  if (countEl) countEl.textContent = `(${data.length})`;
}

function renderSupplierList(suppliers, container) {
  if (!suppliers.length) {
    container.innerHTML = '<div style="text-align:center;padding:20px;color:var(--muted);">Поставщики не найдены</div>';
    return;
  }

  container.innerHTML = suppliers.map(s => {
    const badges = [
      { key: 'whatsapp', label: 'WA', val: s.whatsapp },
      { key: 'telegram', label: 'TG', val: s.telegram },
      { key: 'viber', label: 'Viber', val: s.viber },
      { key: 'email', label: 'Email', val: s.email },
    ].map(b => `<span class="supplier-contact-badge${b.val ? ` filled-${b.key}` : ''}">${b.label}</span>`).join('');

    return `
      <div class="supplier-card" data-supplier-id="${s.id}">
        <div class="supplier-card-info">
          <div class="supplier-card-name">${s.short_name}</div>
          ${s.full_name ? `<div class="supplier-card-fullname">${s.full_name}</div>` : ''}
          <div class="supplier-card-contacts">${badges}</div>
        </div>
        <div class="supplier-card-actions">
          <button class="btn small edit-supplier-btn" data-id="${s.id}"><img src="./icons/edit.png" width="14" height="14" alt=""> Изменить</button>
          <button class="btn small delete-supplier-btn" data-id="${s.id}" style="background:var(--error);color:white;"><img src="./icons/delete.png" width="14" height="14" alt=""></button>
        </div>
      </div>
    `;
  }).join('');

  container.querySelectorAll('.edit-supplier-btn').forEach(btn => {
    btn.addEventListener('click', () => openEditSupplier(btn.dataset.id));
  });

  container.querySelectorAll('.delete-supplier-btn').forEach(btn => {
    btn.addEventListener('click', () => deleteSupplier(btn.dataset.id));
  });
}

async function openEditSupplier(id) {
  const { data, error } = await supabase.from('suppliers').select('*').eq('id', id).single();
  if (error) {
    showToast('Ошибка', 'Не удалось загрузить поставщика', 'error');
    return;
  }

  fillSupplierModal(data, 'edit');
}

/**
 * Открыть создание поставщика из карточки товара
 * После сохранения — вернётся к карточке с новым поставщиком в селекторе
 */
function openNewSupplierAndReturn(editCardModal) {
  editCardModal.style.display = 'none';

  const legalEntity = document.getElementById('e_legalEntity')?.value || 'Бургер БК';
  fillSupplierModal({
    id: null, full_name: '', short_name: '',
    telegram: '', whatsapp: '', viber: '', email: '',
    legal_entity: legalEntity
  }, 'create');

  const supplierModal = document.getElementById('editSupplierModal');

  const observer = new MutationObserver(async () => {
    if (supplierModal.classList.contains('hidden')) {
      observer.disconnect();
      editCardModal.style.display = '';

      const supplierSelect = document.getElementById('e_supplier');
      const newName = document.getElementById('s_shortName').value.trim();
      await loadSupplierOptions(supplierSelect, newName || '');
    }
  });
  observer.observe(supplierModal, { attributes: true, attributeFilter: ['class'] });
}

export function openNewSupplier(legalEntity) {
  const le = legalEntity || document.getElementById('dbSupplierLegalEntity')?.value || 'Бургер БК';
  fillSupplierModal({
    id: null, full_name: '', short_name: '',
    telegram: '', whatsapp: '', viber: '', email: '',
    legal_entity: le
  }, 'create');
}

function fillSupplierModal(data, mode) {
  const modal = document.getElementById('editSupplierModal');
  const titleEl = document.getElementById('editSupplierTitle');
  titleEl.innerHTML = mode === 'create'
    ? '<img src="./icons/edit.png" width="20" height="20" style="vertical-align:-3px;" alt=""> Новый поставщик'
    : '<img src="./icons/edit.png" width="20" height="20" style="vertical-align:-3px;" alt=""> Редактирование поставщика';

  document.getElementById('s_fullName').value = data.full_name || '';
  document.getElementById('s_shortName').value = data.short_name || '';
  document.getElementById('s_legalEntity').value = data.legal_entity || 'Бургер БК';
  document.getElementById('s_whatsapp').value = data.whatsapp || '';
  document.getElementById('s_telegram').value = data.telegram || '';
  document.getElementById('s_viber').value = data.viber || '';
  document.getElementById('s_email').value = data.email || '';

  modal.classList.remove('hidden');
  modal.dataset.mode = mode;
  modal.dataset.editId = data.id || '';

  setupSupplierHandlers(data.id, mode);
}

function setupSupplierHandlers(editId, mode) {
  const modal = document.getElementById('editSupplierModal');
  const saveBtn = document.getElementById('s_save');
  const cancelBtn = document.getElementById('s_cancel');
  const closeBtn = document.getElementById('closeEditSupplier');

  const handleSave = async () => {
    const shortName = document.getElementById('s_shortName').value.trim();
    if (!shortName) {
      showToast('Введите краткое наименование', 'Это обязательное поле', 'error');
      return;
    }

    const supplierData = {
      full_name: document.getElementById('s_fullName').value.trim() || null,
      short_name: shortName,
      legal_entity: document.getElementById('s_legalEntity').value,
      whatsapp: document.getElementById('s_whatsapp').value.trim() || null,
      telegram: document.getElementById('s_telegram').value.trim() || null,
      viber: document.getElementById('s_viber').value.trim() || null,
      email: document.getElementById('s_email').value.trim() || null,
    };

    let error;
    if (mode === 'create') {
      ({ error } = await supabase.from('suppliers').insert([supplierData]));
    } else {
      // Если short_name изменился — обновим products.supplier тоже
      const oldName = modal.dataset.oldShortName;
      ({ error } = await supabase.from('suppliers').update(supplierData).eq('id', editId));

      if (!error && oldName && oldName !== shortName) {
        await supabase.from('products').update({ supplier: shortName }).eq('supplier', oldName);
        showToast('Связи обновлены', `Товары перепривязаны: ${oldName} → ${shortName}`, 'info');
      }
    }

    if (error) {
      const msg = error.message?.includes('unique') ? 'Поставщик с таким кратким именем уже существует' : (error.message || 'Ошибка');
      showToast('Ошибка сохранения', msg, 'error');
      console.error(error);
      return;
    }

    showToast(mode === 'create' ? 'Поставщик создан' : 'Поставщик обновлён', shortName, 'success');
    modal.classList.add('hidden');
    refreshSuppliers();
    // Уведомляем параметры заказа об обновлении поставщиков
    document.dispatchEvent(new CustomEvent('suppliers:updated'));
    cleanup();
  };

  const handleClose = () => {
    modal.classList.add('hidden');
    cleanup();
  };

  const cleanup = () => {
    // Порядок важен: сначала удаляем listeners через replaceWith
    const newSave = saveBtn.cloneNode(true);
    const newCancel = cancelBtn.cloneNode(true);
    const newClose = closeBtn.cloneNode(true);
    saveBtn.replaceWith(newSave);
    cancelBtn.replaceWith(newCancel);
    closeBtn.replaceWith(newClose);
  };

  // Сохраняем старое имя для обнаружения переименования
  modal.dataset.oldShortName = document.getElementById('s_shortName').value.trim();

  saveBtn.addEventListener('click', handleSave);
  cancelBtn.addEventListener('click', handleClose);
  closeBtn.addEventListener('click', handleClose);
}

async function deleteSupplier(id) {
  const { data: supplier } = await supabase.from('suppliers').select('short_name').eq('id', id).single();
  const name = supplier?.short_name || '';

  // Проверяем: есть ли товары у этого поставщика?
  const { count } = await supabase.from('products').select('id', { count: 'exact', head: true }).eq('supplier', name);

  const msg = count > 0
    ? `У поставщика «${name}» привязано ${count} товаров. Связь с товарами будет потеряна.`
    : `Удалить поставщика «${name}»?`;

  const confirmed = await customConfirm('Удалить поставщика?', msg);
  if (!confirmed) return;

  const { error } = await supabase.from('suppliers').delete().eq('id', id);
  if (error) {
    showToast('Ошибка', 'Не удалось удалить', 'error');
    return;
  }

  showToast('Поставщик удалён', name, 'success');
  refreshSuppliers();
  document.dispatchEvent(new CustomEvent('suppliers:updated'));
}

function refreshSuppliers() {
  const el = document.getElementById('dbSupplierLegalEntity');
  const list = document.getElementById('supplierList');
  if (el && list) loadSuppliers(el, list);
}

/* ═══════════════════════════════════════
   ПОИСК ПОСТАВЩИКОВ
   ═══════════════════════════════════════ */

export function setupSupplierSearch() {
  const input = document.getElementById('dbSupplierSearch');
  const clearBtn = document.getElementById('clearDbSupplierSearch');
  const list = document.getElementById('supplierList');
  if (!input || !list) return;

  let allSuppliers = [];

  input.addEventListener('input', () => {
    const q = input.value.toLowerCase();
    if (q) {
      clearBtn.classList.remove('hidden');
      const filtered = allSuppliers.filter(s =>
        s.short_name?.toLowerCase().includes(q) ||
        s.full_name?.toLowerCase().includes(q)
      );
      renderSupplierList(filtered, list);
    } else {
      clearBtn.classList.add('hidden');
      renderSupplierList(allSuppliers, list);
    }
  });

  clearBtn?.addEventListener('click', () => {
    input.value = '';
    clearBtn.classList.add('hidden');
    renderSupplierList(allSuppliers, list);
  });

  const observer = new MutationObserver(() => {
    const cards = list.querySelectorAll('.supplier-card');
    if (cards.length > 0) {
      allSuppliers = Array.from(cards).map(card => ({
        id: card.dataset.supplierId,
        short_name: card.querySelector('.supplier-card-name')?.textContent,
        full_name: card.querySelector('.supplier-card-fullname')?.textContent,
      }));
    }
  });
  observer.observe(list, { childList: true });
}

/* ═══════════════════════════════════════
   ИНИЦИАЛИЗАЦИЯ КНОПОК СОЗДАНИЯ
   ═══════════════════════════════════════ */

export function initDatabaseButtons() {
  document.getElementById('dbNewProductBtn')?.addEventListener('click', () => openNewProductCard());
  document.getElementById('dbNewSupplierBtn')?.addEventListener('click', () => openNewSupplier());

  document.getElementById('dbSupplierLegalEntity')?.addEventListener('change', () => {
    refreshSuppliers();
  });
}

/* ═══════════════════════════════════════
   ЗАГРУЗКА КОНТАКТОВ ПОСТАВЩИКА (для share-order)
   ═══════════════════════════════════════ */

export async function getSupplierContacts(supplierName) {
  if (!supplierName) return null;

  const { data, error } = await supabase
    .from('suppliers')
    .select('whatsapp, telegram, viber, email, full_name, short_name')
    .eq('short_name', supplierName)
    .maybeSingle();

  if (error || !data) return null;
  return data;
}