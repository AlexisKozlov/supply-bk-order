import { defineStore } from 'pinia';
import { ref } from 'vue';
import { db } from '@/lib/apiClient.js';

/**
 * ООО "Бургер БК" и ООО "Воглия Матта" — общие поставщики и товары.
 * ООО "Пицца Стар" — отдельные.
 */
function getEntityGroup(legalEntity) {
  if (legalEntity === 'ООО "Пицца Стар"') {
    return { cacheKey: 'ООО "Пицца Стар"', entities: ['ООО "Пицца Стар"'] };
  }
  return { cacheKey: 'BK_VM', entities: ['ООО "Бургер БК"', 'ООО "Воглия Матта"'] };
}

export const useSupplierStore = defineStore('supplier', () => {
  const suppliersByEntity = ref({});
  const loading = ref(false);

  async function loadSuppliers(legalEntity) {
    if (!legalEntity) return [];
    const { cacheKey, entities } = getEntityGroup(legalEntity);

    if (suppliersByEntity.value[cacheKey]) return suppliersByEntity.value[cacheKey];

    loading.value = true;
    try {
      let query = db
        .from('suppliers')
        .select('short_name, full_name, whatsapp, telegram, viber, email')
        .order('short_name');

      // Если одна сущность — простой eq, если несколько — or()
      if (entities.length === 1) {
        query = query.eq('legal_entity', entities[0]);
      } else {
        // or формат: legal_entity.eq.ООО "Бургер БК",legal_entity.eq.ООО "Воглия Матта"
        query = query.or(entities.map(e => `legal_entity.eq.${e}`).join(','));
      }

      const { data, error } = await query;

      if (!error && data) {
        // Дедупликация по short_name
        const unique = [];
        const seen = new Set();
        for (const s of data) {
          if (!seen.has(s.short_name)) {
            seen.add(s.short_name);
            unique.push(s);
          }
        }

        // Убрать поставщиков, у которых все товары неактивны
        let prodQuery = db.from('products').select('supplier').eq('is_active', 1);
        if (entities.length === 1) {
          prodQuery = prodQuery.eq('legal_entity', entities[0]);
        } else {
          prodQuery = prodQuery.or(entities.map(e => `legal_entity.eq.${e}`).join(','));
        }
        const { data: activeProducts } = await prodQuery;
        const activeSuppliers = new Set((activeProducts || []).map(p => p.supplier).filter(Boolean));
        const filtered = unique.filter(s => activeSuppliers.has(s.short_name));

        suppliersByEntity.value[cacheKey] = filtered;
        return filtered;
      }
      return [];
    } finally {
      loading.value = false;
    }
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
