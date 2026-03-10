import { defineStore } from 'pinia';
import { ref } from 'vue';
import { db } from '@/lib/apiClient.js';
import { getEntityGroup as _getGroup } from '@/lib/utils.js';

function getEntityGroup(legalEntity) {
  const entities = _getGroup(legalEntity);
  const cacheKey = entities.length === 1 ? entities[0] : 'BK_VM';
  return { cacheKey, entities };
}

// Кеш активных поставщиков (по данным товаров) — обновляется не чаще раза в 5 минут
const _activeSuppliersCache = {};
const ACTIVE_CACHE_TTL = 5 * 60 * 1000;

async function loadActiveSuppliers(entities, cacheKey) {
  const cached = _activeSuppliersCache[cacheKey];
  if (cached && (Date.now() - cached.ts < ACTIVE_CACHE_TTL)) return cached.data;

  let prodQuery = db.from('products').select('supplier').eq('is_active', 1);
  if (entities.length === 1) {
    prodQuery = prodQuery.eq('legal_entity', entities[0]);
  } else {
    prodQuery = prodQuery.or(entities.map(e => `legal_entity.eq.${e}`).join(','));
  }
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
    const { cacheKey, entities } = getEntityGroup(legalEntity);

    if (suppliersByEntity.value[cacheKey]) return suppliersByEntity.value[cacheKey];
    if (_loadPromises[cacheKey]) return _loadPromises[cacheKey];

    loading.value = true;
    const promise = (async () => {
      try {
        let query = db
          .from('suppliers')
          .select('short_name, full_name, whatsapp, telegram, viber, email, dlt, doc')
          .order('short_name');

        if (entities.length === 1) {
          query = query.eq('legal_entity', entities[0]);
        } else {
          query = query.or(entities.map(e => `legal_entity.eq.${e}`).join(','));
        }

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
          const activeSuppliers = await loadActiveSuppliers(entities, cacheKey);
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
    const { cacheKey } = getEntityGroup(legalEntity);
    return suppliersByEntity.value[cacheKey] || [];
  }

  function invalidate(legalEntity) {
    if (legalEntity) {
      const { cacheKey } = getEntityGroup(legalEntity);
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
