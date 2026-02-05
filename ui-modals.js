import { orderState } from './state.js';
import { supabase } from './supabase.js';
import { render, updateEntityBadge } from './ui-main.js';

/* ================= DOM ================= */
const addManualBtn = document.getElementById('addManual');
const manualModal = document.getElementById('manualModal');
const closeManualBtn = document.getElementById('closeManual');
const manualAddBtn = document.getElementById('m_add');
const manualCancelBtn = document.getElementById('m_cancel');

const menuHistoryBtn = document.getElementById('menuHistory');
const historyModal = document.getElementById('historyModal');
const closeHistoryBtn = document.getElementById('closeHistory');
const historyContainer = document.getElementById('orderHistory');
const historySupplier = document.getElementById('historySupplier');

const menuDatabaseBtn = document.getElementById('menuDatabase');
const databaseModal = document.getElementById('databaseModal');
const closeDatabaseBtn = document.getElementById('closeDatabase');
const dbLegalEntitySelect = document.getElementById('dbLegalEntity');
const dbSearchInput = document.getElementById('dbSearch');
const clearDbSearchBtn = document.getElementById('clearDbSearch');
const databaseList = document.getElementById('databaseList');

const editCardModal = document.getElementById('editCardModal');
const closeEditCardBtn = document.getElementById('closeEditCard');
let currentEditingProduct = null;

const confirmModal = document.getElementById('confirmModal');

const nf = new Intl.NumberFormat('ru-RU', {
  maximumFractionDigits: 0
});

/* ================= TOAST NOTIFICATIONS ================= */
function createToastContainer() {
  if (!document.querySelector('.toast-container')) {
    const container = document.createElement('div');
    container.className = 'toast-container';
    document.body.appendChild(container);
  }
}

export function showToast(title, message, type = 'info') {
  createToastContainer();
  
  const icons = {
    success: '✅',
    error: '❌',
    info: 'ℹ️'
  };

  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;
  toast.innerHTML = `
    <div class="toast-icon">${icons[type]}</div>
    <div class="toast-content">
      <div class="toast-title">${title}</div>
      ${message ? `<div class="toast-message">${message}</div>` : ''}
    </div>
    <button class="toast-close">✖</button>
  `;

  const container = document.querySelector('.toast-container');
  container.appendChild(toast);

  toast.querySelector('.toast-close').addEventListener('click', () => {
    toast.remove();
  });

  setTimeout(() => {
    toast.remove();
  }, 4000);
}

/* ================= CUSTOM CONFIRM ================= */
export function customConfirm(title, message) {
  return new Promise((resolve) => {
    const modal = document.getElementById('confirmModal');
    const titleEl = document.getElementById('confirmTitle');
    const messageEl = document.getElementById('confirmMessage');
    const yesBtn = document.getElementById('confirmYes');
    const noBtn = document.getElementById('confirmNo');
    const closeBtn = document.getElementById('closeConfirm');

    titleEl.textContent = title;
    messageEl.textContent = message;
    modal.classList.remove('hidden');

    const cleanup = (result) => {
      modal.classList.add('hidden');
      yesBtn.replaceWith(yesBtn.cloneNode(true));
      noBtn.replaceWith(noBtn.cloneNode(true));
      closeBtn.replaceWith(closeBtn.cloneNode(true));
      resolve(result);
    };

    document.getElementById('confirmYes').addEventListener('click', () => cleanup(true));
    document.getElementById('confirmNo').addEventListener('click', () => cleanup(false));
    document.getElementById('closeConfirm').addEventListener('click', () => cleanup(false));
  });
}

/* ================= ИСТОРИЯ ЗАКАЗОВ ================= */
export async function loadOrderHistory() {
  historyContainer.innerHTML = '<div style="text-align:center;padding:20px;"><div class="loading-spinner"></div><div>Загрузка...</div></div>';

  let query = supabase
    .from('orders')
    .select(`
  id,
  delivery_date,
  supplier,
  legal_entity,
  safety_days,
  period_days,
  unit,
  order_items (
    sku,
    name,
    qty_boxes,
    qty_per_box,
    consumption_period,
    stock,
    transit
  )
`)
    .order('created_at', { ascending: false });

  const selectedSupplier = historySupplier.value;
  if (selectedSupplier) {
    query = query.eq('supplier', selectedSupplier);
  }

  const { data, error } = await query;

  if (error) {
    historyContainer.innerHTML = '<div style="color:var(--error);padding:20px;">Ошибка загрузки</div>';
    console.error(error);
    return;
  }

  if (!data || data.length === 0) {
    historyContainer.innerHTML = '<div style="text-align:center;padding:40px;color:var(--muted);">Нет сохранённых заказов</div>';
    return;
  }

  // Группировка по поставщикам
  const groupedBySupplier = {};
  data.forEach(order => {
    const sup = order.supplier || 'Без поставщика';
    if (!groupedBySupplier[sup]) {
      groupedBySupplier[sup] = [];
    }
    groupedBySupplier[sup].push(order);
  });

  let html = '';
  for (const [supplier, orders] of Object.entries(groupedBySupplier)) {
    html += `<h3 style="margin:24px 0 12px;padding:8px;background:var(--primary);color:white;border-radius:4px;">${supplier}</h3>`;

    orders.forEach(order => {
      const totalBoxes = order.order_items.reduce((sum, item) => sum + (item.qty_boxes || 0), 0);
      const deliveryDateStr = new Date(order.delivery_date).toLocaleDateString('ru-RU');

      html += `
        <div class="history-card" data-order-id="${order.id}">
          <div class="history-header">
            <strong>${deliveryDateStr}</strong>
            <span style="color:var(--muted);">${order.legal_entity || 'Бургер БК'}</span>
            <span style="color:var(--muted);">Позиций: ${order.order_items.length}</span>
            <span style="color:var(--muted);">Коробов: ${nf.format(totalBoxes)}</span>
          </div>
          <div class="history-actions">
            <button class="btn small load-order-btn">📥 Загрузить</button>
            <button class="btn small delete-order-btn" style="background:var(--error);color:white;">🗑️ Удалить</button>
          </div>
          <div class="history-items" style="display:none;">
            ${order.order_items.map(item => `
              <div style="padding:4px 0;font-size:14px;">
                ${item.name} — ${nf.format(item.qty_boxes)} кор
              </div>
            `).join('')}
          </div>
        </div>
      `;
    });
  }

  historyContainer.innerHTML = html;

  // Показ/скрытие товаров
  document.querySelectorAll('.history-card').forEach(card => {
    const header = card.querySelector('.history-header');
    const items = card.querySelector('.history-items');

    header.addEventListener('click', () => {
      if (items.style.display === 'none') {
        items.style.display = 'block';
      } else {
        items.style.display = 'none';
      }
    });
  });

  // Загрузка заказа
  document.querySelectorAll('.load-order-btn').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      e.stopPropagation();
      const card = e.target.closest('.history-card');
      const orderId = card.dataset.orderId;
      await loadOrderById(orderId);
    });
  });

  // Удаление заказа
  document.querySelectorAll('.delete-order-btn').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      e.stopPropagation();
      const card = e.target.closest('.history-card');
      const orderId = card.dataset.orderId;
      await deleteOrder(orderId);
    });
  });
}

async function loadOrderById(orderId) {
  const confirmed = await customConfirm('Загрузить заказ?', 'Текущий заказ будет заменён');
  if (!confirmed) return;

  const { data, error } = await supabase
    .from('orders')
    .select(`
      *,
      order_items (*)
    `)
    .eq('id', orderId)
    .single();

  if (error || !data) {
    showToast('Ошибка', 'Не удалось загрузить заказ', 'error');
    console.error(error);
    return;
  }

  // Загружаем настройки
  orderState.settings.legalEntity = data.legal_entity || 'Бургер БК';
  orderState.settings.supplier = data.supplier || '';
  orderState.settings.deliveryDate = data.delivery_date ? new Date(data.delivery_date) : null;
  orderState.settings.safetyDays = data.safety_days || 0;
  orderState.settings.periodDays = data.period_days || 30;
  orderState.settings.unit = data.unit || 'pieces';

  // Синхронизируем UI
  document.getElementById('legalEntity').value = orderState.settings.legalEntity;
  document.getElementById('supplierFilter').value = orderState.settings.supplier;
  document.getElementById('deliveryDate').value = orderState.settings.deliveryDate ? orderState.settings.deliveryDate.toISOString().split('T')[0] : '';
  document.getElementById('safetyDays').value = orderState.settings.safetyDays;
  document.getElementById('periodDays').value = orderState.settings.periodDays;
  document.getElementById('unit').value = orderState.settings.unit;

  // Загружаем товары из order_items
  orderState.items = [];

  for (const orderItem of data.order_items) {
    // Пытаемся найти товар в products по SKU
    let product = null;
    if (orderItem.sku) {
      const { data: productData } = await supabase
        .from('products')
        .select('*')
        .eq('sku', orderItem.sku)
        .eq('legal_entity', orderState.settings.legalEntity)
        .maybeSingle();

      product = productData;
    }

    const newItem = {
      id: Date.now() + Math.random(),
      supabaseId: product?.id || null,
      name: orderItem.name,
      sku: orderItem.sku,
      supplier: product?.supplier || null,
      consumptionPeriod: orderItem.consumption_period || 0,
      stock: orderItem.stock || 0,
      transit: orderItem.transit || 0,
      qtyPerBox: orderItem.qty_per_box || 1,
      boxesPerPallet: product?.boxes_per_pallet || null,
      unitOfMeasure: product?.unit_of_measure || 'шт',
      finalOrder: orderItem.qty_boxes * orderItem.qty_per_box // Конвертируем обратно в штуки
    };

    orderState.items.push(newItem);
  }

  updateEntityBadge();
  render();

  historyModal.classList.add('hidden');
  document.getElementById('orderSection').classList.remove('hidden');

  showToast('Заказ загружен', `Загружено позиций: ${orderState.items.length}`, 'success');
}

async function deleteOrder(orderId) {
  const confirmed = await customConfirm('Удалить заказ?', 'Заказ будет удалён из истории');
  if (!confirmed) return;

  // Сначала удаляем order_items
  const { error: itemsError } = await supabase
    .from('order_items')
    .delete()
    .eq('order_id', orderId);

  if (itemsError) {
    showToast('Ошибка', 'Не удалось удалить состав заказа', 'error');
    console.error(itemsError);
    return;
  }

  // Затем удаляем сам заказ
  const { error } = await supabase
    .from('orders')
    .delete()
    .eq('id', orderId);

  if (error) {
    showToast('Ошибка', 'Не удалось удалить заказ', 'error');
    console.error(error);
    return;
  }

  showToast('Заказ удалён', '', 'success');
  loadOrderHistory();
}

menuHistoryBtn.addEventListener('click', () => {
  historyModal.classList.remove('hidden');
  loadOrderHistory();
  loadHistorySuppliers();
});

closeHistoryBtn.addEventListener('click', () => {
  historyModal.classList.add('hidden');
});

async function loadHistorySuppliers() {
  const { data, error } = await supabase
    .from('orders')
    .select('supplier');

  if (error) {
    console.error(error);
    return;
  }

  const unique = [...new Set(data.map(d => d.supplier).filter(Boolean))];
  unique.sort();

  historySupplier.innerHTML = '<option value="">Все</option>';
  unique.forEach(s => {
    const opt = document.createElement('option');
    opt.value = s;
    opt.textContent = s;
    historySupplier.appendChild(opt);
  });
}

historySupplier.addEventListener('change', () => {
  loadOrderHistory();
});

/* ================= РУЧНОЙ ТОВАР ================= */
addManualBtn.addEventListener('click', () => {
  // Очищаем поля
  document.getElementById('m_name').value = '';
  document.getElementById('m_sku').value = '';
  document.getElementById('m_supplier').value = '';
  document.getElementById('m_legalEntity').value = orderState.settings.legalEntity;
  document.getElementById('m_box').value = '';
  document.getElementById('m_pallet').value = '';
  document.getElementById('m_unit').value = 'шт';
  document.getElementById('m_save').checked = false;

  manualModal.classList.remove('hidden');
});

closeManualBtn.addEventListener('click', () => {
  manualModal.classList.add('hidden');
});

manualCancelBtn.addEventListener('click', () => {
  manualModal.classList.add('hidden');
});

manualAddBtn.addEventListener('click', async () => {
  const name = document.getElementById('m_name').value.trim();
  if (!name) {
    showToast('Ошибка', 'Наименование обязательно', 'error');
    return;
  }

  const sku = document.getElementById('m_sku').value || null;
  const supplier = document.getElementById('m_supplier').value || null;
  const legalEntity = document.getElementById('m_legalEntity').value;
  const qtyPerBox = +document.getElementById('m_box').value || 1;
  const boxesPerPallet = +document.getElementById('m_pallet').value || null;
  const unitOfMeasure = document.getElementById('m_unit').value || 'шт';
  const shouldSave = document.getElementById('m_save').checked;

  let supabaseId = null;

  // Если нужно сохранить в базу
  if (shouldSave) {
    const { data, error } = await supabase
      .from('products')
      .insert({
        name,
        sku,
        supplier,
        legal_entity: legalEntity,
        qty_per_box: qtyPerBox,
        boxes_per_pallet: boxesPerPallet,
        unit_of_measure: unitOfMeasure,
        consumption_period: 0,
        stock: 0,
        transit: 0
      })
      .select()
      .single();

    if (error) {
      showToast('Ошибка', 'Не удалось сохранить в базу', 'error');
      console.error(error);
      return;
    }

    supabaseId = data.id;
    showToast('Товар сохранён в базу', '', 'success');
  }

  // Добавляем в заказ
  const newItem = {
    id: Date.now() + Math.random(),
    supabaseId,
    name,
    sku,
    supplier,
    consumptionPeriod: 0,
    stock: 0,
    transit: 0,
    qtyPerBox,
    boxesPerPallet,
    unitOfMeasure,
    finalOrder: 0
  };

  orderState.items.push(newItem);
  render();
  
  manualModal.classList.add('hidden');
  showToast('Товар добавлен', '', 'success');
});

/* ================= БАЗА ДАННЫХ ================= */
menuDatabaseBtn.addEventListener('click', () => {
  databaseModal.classList.remove('hidden');
  loadDatabaseProducts();
});

closeDatabaseBtn.addEventListener('click', () => {
  databaseModal.classList.add('hidden');
});

dbLegalEntitySelect.addEventListener('change', () => {
  loadDatabaseProducts();
});

export async function loadDatabaseProducts() {
  databaseList.innerHTML = '<div style="text-align:center;padding:20px;"><div class="loading-spinner"></div><div>Загрузка...</div></div>';

  const legalEntity = dbLegalEntitySelect.value;

  const { data, error } = await supabase
    .from('products')
    .select('*')
    .eq('legal_entity', legalEntity)
    .order('name', { ascending: true });

  if (error) {
    databaseList.innerHTML = '<div style="color:var(--error);padding:20px;">Ошибка загрузки</div>';
    console.error(error);
    return;
  }

  if (!data || data.length === 0) {
    databaseList.innerHTML = '<div style="text-align:center;padding:40px;color:var(--muted);">Нет товаров в базе</div>';
    return;
  }

  databaseList.innerHTML = data.map(p => `
    <div class="db-card">
      <div class="db-card-info">
        <div class="db-card-name">${p.name} ${p.unit_of_measure ? `(${p.unit_of_measure})` : ''}</div>
        <div class="db-card-sku">${p.sku || 'нет артикула'}</div>
        <div class="db-card-supplier">${p.supplier || 'без поставщика'}</div>
        <div class="db-card-details">
          ${p.qty_per_box ? `${p.qty_per_box} шт/кор` : ''}
          ${p.boxes_per_pallet ? ` • ${p.boxes_per_pallet} кор/п` : ''}
        </div>
      </div>
      <div class="db-card-actions">
        <button class="btn small edit-card-btn" data-id="${p.id}">✏️</button>
        <button class="btn small delete-card-btn" data-id="${p.id}" style="background:var(--error);color:white;">🗑️</button>
      </div>
    </div>
  `).join('');
  
  // Навешиваем обработчики
  document.querySelectorAll('.edit-card-btn').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      const id = e.target.dataset.id;
      await openEditCard(id);
    });
  });
  
  document.querySelectorAll('.delete-card-btn').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      const id = e.target.dataset.id;
      await deleteCard(id);
    });
  });
}

/* ================= РЕДАКТИРОВАНИЕ КАРТОЧКИ ================= */
async function openEditCard(productId) {
  const { data, error } = await supabase
    .from('products')
    .select('*')
    .eq('id', productId)
    .single();
  
  if (error || !data) {
    showToast('Ошибка', 'Не удалось загрузить карточку', 'error');
    return;
  }
  
  currentEditingProduct = data;
  
  document.getElementById('e_name').value = data.name || '';
  document.getElementById('e_sku').value = data.sku || '';
  document.getElementById('e_supplier').value = data.supplier || '';
  document.getElementById('e_legalEntity').value = data.legal_entity || 'Бургер БК';
  document.getElementById('e_box').value = data.qty_per_box || '';
  document.getElementById('e_pallet').value = data.boxes_per_pallet || '';
  document.getElementById('e_unit').value = data.unit_of_measure || 'шт';
  
  editCardModal.classList.remove('hidden');
}

closeEditCardBtn.addEventListener('click', () => {
  editCardModal.classList.add('hidden');
  currentEditingProduct = null;
});

document.getElementById('e_cancel').addEventListener('click', () => {
  editCardModal.classList.add('hidden');
  currentEditingProduct = null;
});

document.getElementById('e_save').addEventListener('click', async () => {
  if (!currentEditingProduct) {
    console.error('❌ currentEditingProduct is null!');
    return;
  }
  
  const name = document.getElementById('e_name').value.trim();
  if (!name) {
    showToast('Ошибка', 'Наименование обязательно', 'error');
    return;
  }
  
  const updated = {
    name,
    sku: document.getElementById('e_sku').value || null,
    supplier: document.getElementById('e_supplier').value || null,
    legal_entity: document.getElementById('e_legalEntity').value,
    qty_per_box: +document.getElementById('e_box').value || null,
    boxes_per_pallet: +document.getElementById('e_pallet').value || null,
    unit_of_measure: document.getElementById('e_unit').value || 'шт'
  };
  
  const { data, error } = await supabase
    .from('products')
    .update(updated)
    .eq('id', currentEditingProduct.id)
    .select();
  
  if (error) {
    console.error('❌ ОШИБКА SUPABASE:', error);
    showToast('Ошибка', error.message || 'Не удалось сохранить', 'error');
    return;
  }
  
  showToast('Сохранено', 'Карточка обновлена', 'success');
  
  // Обновляем товар в заказе если он там есть
  const itemInOrder = orderState.items.find(item => item.supabaseId === currentEditingProduct.id);
  if (itemInOrder) {
    itemInOrder.name = updated.name;
    itemInOrder.sku = updated.sku;
    itemInOrder.qtyPerBox = updated.qty_per_box;
    itemInOrder.boxesPerPallet = updated.boxes_per_pallet;
    itemInOrder.unitOfMeasure = updated.unit_of_measure;
    render();
  }
  
  editCardModal.classList.add('hidden');
  currentEditingProduct = null;
  loadDatabaseProducts();
});

async function deleteCard(productId) {
  const confirmed = await customConfirm('Удалить карточку?', 'Карточка будет удалена из базы данных. Если она есть в заказе, тоже будет удалена.');
  if (!confirmed) return;
  
  const { error } = await supabase
    .from('products')
    .delete()
    .eq('id', productId);
  
  if (error) {
    showToast('Ошибка', 'Не удалось удалить карточку', 'error');
    console.error(error);
    return;
  }
  
  showToast('Удалено', 'Карточка удалена из базы', 'success');
  
  // Удаляем из заказа если есть
  const itemIndex = orderState.items.findIndex(item => item.supabaseId === productId);
  if (itemIndex !== -1) {
    orderState.items.splice(itemIndex, 1);
    render();
  }
  
  loadDatabaseProducts();
}

/* ================= ПОИСК В БАЗЕ ДАННЫХ ================= */
if (dbSearchInput) {
  dbSearchInput.addEventListener('input', () => {
    const q = dbSearchInput.value.trim().toLowerCase();
    
    if (clearDbSearchBtn) {
      if (q.length > 0) {
        clearDbSearchBtn.classList.remove('hidden');
      } else {
        clearDbSearchBtn.classList.add('hidden');
      }
    }
    
    // Фильтруем карточки в списке
    const cards = databaseList.querySelectorAll('.db-card');
    let visibleCount = 0;
    
    cards.forEach(card => {
      const sku = card.querySelector('.db-card-sku')?.textContent.toLowerCase() || '';
      const name = card.querySelector('.db-card-name')?.textContent.toLowerCase() || '';
      const supplier = card.querySelector('.db-card-supplier')?.textContent.toLowerCase() || '';
      
      if (sku.includes(q) || name.includes(q) || supplier.includes(q)) {
        card.style.display = 'flex';
        visibleCount++;
      } else {
        card.style.display = 'none';
      }
    });
    
    // Показываем сообщение если ничего не найдено
    let noResultsMsg = databaseList.querySelector('.no-results-message');
    if (visibleCount === 0 && q.length > 0) {
      if (!noResultsMsg) {
        noResultsMsg = document.createElement('div');
        noResultsMsg.className = 'no-results-message';
        noResultsMsg.style.cssText = 'text-align:center;padding:40px;color:var(--muted);';
        noResultsMsg.textContent = 'Ничего не найдено';
        databaseList.appendChild(noResultsMsg);
      }
      noResultsMsg.style.display = 'block';
    } else if (noResultsMsg) {
      noResultsMsg.style.display = 'none';
    }
  });
}

if (clearDbSearchBtn) {
  clearDbSearchBtn.addEventListener('click', () => {
    dbSearchInput.value = '';
    if (clearDbSearchBtn) clearDbSearchBtn.classList.add('hidden');
    
    // Показываем все карточки
    const cards = databaseList.querySelectorAll('.db-card');
    cards.forEach(card => {
      card.style.display = 'flex';
    });
    
    const noResultsMsg = databaseList.querySelector('.no-results-message');
    if (noResultsMsg) noResultsMsg.style.display = 'none';
    
    dbSearchInput.focus();
  });
}

/* ================= КЛАВИШИ ENTER/ESC ================= */
document.addEventListener('keydown', (e) => {
  // ESC — закрытие модалок
  if (e.key === 'Escape') {
    if (!manualModal.classList.contains('hidden')) {
      manualModal.classList.add('hidden');
    } else if (!editCardModal.classList.contains('hidden')) {
      editCardModal.classList.add('hidden');
      currentEditingProduct = null;
    } else if (!databaseModal.classList.contains('hidden')) {
      databaseModal.classList.add('hidden');
    } else if (!historyModal.classList.contains('hidden')) {
      historyModal.classList.add('hidden');
    } else if (!confirmModal.classList.contains('hidden')) {
      confirmModal.classList.add('hidden');
    }
  }
  
  // ENTER — сохранение/подтверждение (только если фокус НЕ на input)
  if (e.key === 'Enter' && !e.shiftKey && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'SELECT') {
    if (!manualModal.classList.contains('hidden')) {
      e.preventDefault();
      document.getElementById('m_add').click();
    } else if (!editCardModal.classList.contains('hidden')) {
      e.preventDefault();
      document.getElementById('e_save').click();
    } else if (!confirmModal.classList.contains('hidden')) {
      e.preventDefault();
      document.getElementById('confirmYes').click();
    }
  }
});