/**
 * Модуль для работы с базой данных товаров
 */

import { supabase } from './supabase.js';
import { showToast, customConfirm } from './modals.js';

export async function loadDatabaseProducts(dbLegalEntitySelect, databaseList) {
  databaseList.innerHTML = '<div style="text-align:center;padding:20px;"><div class="loading-spinner"></div><div>Загрузка...</div></div>';
  
  const legalEntity = dbLegalEntitySelect.value;
  
  let query = supabase
    .from('products')
    .select('*')
    .order('name');
  
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
  
  // Обработчики
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
  const confirmed = await customConfirm('Удалить карточку?', 'Карточка будет удалена из базы данных');
  if (!confirmed) return;
  
  const { error } = await supabase
    .from('products')
    .delete()
    .eq('id', id);
  
  if (error) {
    showToast('Ошибка удаления', 'Не удалось удалить карточку', 'error');
    console.error(error);
    return;
  }
  
  showToast('Карточка удалена', '', 'success');
  const databaseList = document.getElementById('databaseList');
  const dbLegalEntitySelect = document.getElementById('dbLegalEntity');
  loadDatabaseProducts(dbLegalEntitySelect, databaseList);
}

export async function openEditCard(id, onSaveCallback) {
  const { data, error } = await supabase
    .from('products')
    .select('*')
    .eq('id', id)
    .single();
  
  if (error) {
    showToast('Ошибка', 'Не удалось загрузить карточку', 'error');
    console.error(error);
    return;
  }
  
  const editCardModal = document.getElementById('editCardModal');
  document.getElementById('e_name').value = data.name || '';
  document.getElementById('e_sku').value = data.sku || '';
  document.getElementById('e_supplier').value = data.supplier || '';
  document.getElementById('e_legalEntity').value = data.legal_entity || 'Бургер БК';
  document.getElementById('e_box').value = data.qty_per_box || '';
  document.getElementById('e_pallet').value = data.boxes_per_pallet || '';
  document.getElementById('e_unit').value = data.unit_of_measure || 'шт';
  
  editCardModal.classList.remove('hidden');
  
  const saveBtn = document.getElementById('e_save');
  const cancelBtn = document.getElementById('e_cancel');
  const closeBtn = document.getElementById('closeEditCard');
  
  const handleSave = async () => {
    const name = document.getElementById('e_name').value.trim();
    if (!name) {
      showToast('Введите наименование', 'Поле обязательно', 'error');
      return;
    }
    
    const updatedData = {
      name: name,
      sku: document.getElementById('e_sku').value || null,
      supplier: document.getElementById('e_supplier').value || null,
      legal_entity: document.getElementById('e_legalEntity').value,
      qty_per_box: +document.getElementById('e_box').value || null,
      boxes_per_pallet: +document.getElementById('e_pallet').value || null,
      unit_of_measure: document.getElementById('e_unit').value
    };
    
    const { error } = await supabase
      .from('products')
      .update(updatedData)
      .eq('id', id);
    
    if (error) {
      showToast('Ошибка сохранения', 'Не удалось обновить карточку', 'error');
      console.error(error);
      return;
    }
    
    showToast('Карточка обновлена', '', 'success');
    editCardModal.classList.add('hidden');
    const databaseList = document.getElementById('databaseList');
    const dbLegalEntitySelect = document.getElementById('dbLegalEntity');
    loadDatabaseProducts(dbLegalEntitySelect, databaseList);
    
    // Вызываем callback с обновлёнными данными
    if (onSaveCallback) onSaveCallback(updatedData);
    
    cleanup();
  };
  
  const handleClose = () => {
    editCardModal.classList.add('hidden');
    cleanup();
  };
  
  const cleanup = () => {
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
  
  // Сохраняем все продукты для фильтрации
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
/**
 * Открыть карточку редактирования по SKU товара
 * Используется при клике на наименование в блоке заказа
 */
export async function openEditCardBySku(sku, onSaveCallback) {
  if (!sku) return;
  
  const { data, error } = await supabase
    .from('products')
    .select('id')
    .eq('sku', sku)
    .maybeSingle();
  
  if (error || !data) {
    console.warn('Товар с SKU не найден в базе:', sku);
    return;
  }
  
  openEditCard(data.id, onSaveCallback);
}