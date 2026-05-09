import { defineStore } from 'pinia';
import { ref } from 'vue';
import { db } from '@/lib/apiClient.js';
import { getEntityGroupCode, applyEntityGroupFilter } from '@/lib/utils.js';

// Кеш активных поставщиков (по данным товаров) — обновляется не чаще раза в 5 минут
const _activeSuppliersCache = {};
const ACTIVE_CACHE_TTL = 5 * 60 * 1000;

// Кеш списка товаров поставщика. Раньше OrderTable, OrderView и PlanningView
// при переключении поставщика делали независимые запросы за тем же набором
// (особенно у пользователей, кто часто скачет между вкладками). TTL 5 минут
// и дедуп параллельных промисов экономят много RTT и трафика.
const _supplierProductsCache = {};
const _supplierProductsPromises = {};
const SUPPLIER_PRODUCTS_TTL = 5 * 60 * 1000;

export async function loadProductsForSupplier(supplier, legalEntity, fields = '*') {
  if (!supplier) return [];
  const cacheKey = `${getEntityGroupCode(legalEntity)}::${supplier}::${fields}`;
  const cached = _supplierProductsCache[cacheKey];
  if (cached && (Date.now() - cached.ts < SUPPLIER_PRODUCTS_TTL)) return cached.data;
  if (_supplierProductsPromises[cacheKey]) return _supplierProductsPromises[cacheKey];
  const promise = (async () => {
    try {
      let q = db.from('products').select(fields).eq('supplier', supplier);
      q = applyEntityGroupFilter(q, legalEntity);
      const { data } = await q;
      const result = data || [];
      _supplierProductsCache[cacheKey] = { data: result, ts: Date.now() };
      return result;
    } finally {
      delete _supplierProductsPromises[cacheKey];
    }
  })();
  _supplierProductsPromises[cacheKey] = promise;
  return promise;
}

export function invalidateSupplierProducts(legalEntity = null) {
  if (legalEntity) {
    const prefix = getEntityGroupCode(legalEntity) + '::';
    for (const k of Object.keys(_supplierProductsCache)) {
      if (k.startsWith(prefix)) delete _supplierProductsCache[k];
    }
  } else {
    for (const k of Object.keys(_supplierProductsCache)) delete _supplierProductsCache[k];
  }
}

async function loadActiveSuppliers(legalEntity) {
  const cacheKey = getEntityGroupCode(legalEntity);
  const cached = _activeSuppliersCache[cacheKey];
  if (cached && (Date.now() - cached.ts < ACTIVE_CACHE_TTL)) return cached.data;

  let prodQuery = db.from('products').select('supplier').eq('is_active', 1);
  prodQuery = applyEntityGroupFilter(prodQuery, legalEntity);
  const { data: activeProducts } = await prodQuery;
  const set = new Set((activeProducts || []).map(p => p.supplier).filter(Boolean));
  _activeSuppliersCache[cacheKey] = { data: set, ts: Date.now() };
  return set;
}

export const useSupplierStore = defineStore('supplier', () => {
  const suppliersByEntity = ref({});
  const loading = ref(false);
  const _loadPromises = {};

  async function loadSuppliers(legalEntity) {
    if (!legalEntity) return [];
    const cacheKey = getEntityGroupCode(legalEntity);

    if (suppliersByEntity.value[cacheKey]) return suppliersByEntity.value[cacheKey];
    if (_loadPromises[cacheKey]) return _loadPromises[cacheKey];

    loading.value = true;
    const promise = (async () => {
      try {
        let query = db
          .from('suppliers')
          .select('short_name, full_name, whatsapp, telegram, viber, email, dlt, doc')
          .order('short_name');

        query = applyEntityGroupFilter(query, legalEntity);

        const { data, error } = await query;

        if (!error && data && data.length > 0) {
          // Дедупликация по short_name
          const unique = [];
          const seen = new Set();
          for (const s of data) {
            if (!seen.has(s.short_name)) {
              seen.add(s.short_name);
              unique.push(s);
            }
          }

          // Фильтрация по активным товарам (с кешем)
          const activeSuppliers = await loadActiveSuppliers(legalEntity);
          const filtered = unique.filter(s => activeSuppliers.has(s.short_name));

          suppliersByEntity.value[cacheKey] = filtered;
          return filtered;
        }
        return [];
      } finally {
        loading.value = false;
        delete _loadPromises[cacheKey];
      }
    })();
    _loadPromises[cacheKey] = promise;
    return promise;
  }

  function getSuppliersForEntity(legalEntity) {
    const cacheKey = getEntityGroupCode(legalEntity);
    return suppliersByEntity.value[cacheKey] || [];
  }

  function invalidate(legalEntity) {
    if (legalEntity) {
      const cacheKey = getEntityGroupCode(legalEntity);
      delete suppliersByEntity.value[cacheKey];
    } else {
      suppliersByEntity.value = {};
    }
  }

  async function getSupplierContacts(supplierName, legalEntity) {
    const list = await loadSuppliers(legalEntity);
    return list.find(s => s.short_name === supplierName) || null;
  }

  return {
    suppliersByEntity,
    loading,
    loadSuppliers,
    getSuppliersForEntity,
    invalidate,
    getSupplierContacts,
  };
});
