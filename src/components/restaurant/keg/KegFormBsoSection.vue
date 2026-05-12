<template>
  <section class="krt-section krt-bso-section">
    <div class="krt-section-head">
      <h3>
        Бланк БСО
        <span v-if="(form.bso_history || []).length" class="krt-bso-replaced-tag">
          заменён {{ form.bso_history.length }}×
        </span>
      </h3>
    </div>

    <!-- Активный CTA замены: окно 10:00–15:00 -->
    <div v-if="form.can_replace_bso" class="krt-bso-replace-cta">
      <div class="krt-bso-replace-text">
        <div class="krt-bso-replace-title">Испортили БСО при печати?</div>
        <div class="krt-bso-replace-sub">
          Окно замены до <b>{{ cutoffFormatted }}</b>. Потом изменить нельзя — заявки уйдут лог-провайдеру финально.
        </div>
      </div>
      <button class="krt-btn primary" @click="$emit('replace')" :disabled="saving">
        Заменить БСО
      </button>
    </div>

    <!-- Окно закрылось -->
    <div v-else-if="cutoffPassed && (form.status === 'SUBMITTED' || form.status === 'ROUTED')" class="krt-bso-cutoff-msg">
      Окно замены БСО закрыто (после 15:00). Если бланк испорчен — свяжитесь с отделом закупок.
    </div>

    <!-- История замен -->
    <div v-if="(form.bso_history || []).length" class="krt-bso-history">
      <div class="krt-bso-history-title">История замен</div>
      <ul class="krt-bso-history-list">
        <li v-for="h in form.bso_history" :key="h.id" class="krt-bso-history-item">
          <div class="krt-bso-history-row">
            <span class="krt-bso-history-old">
              {{ h.old_series || '—' }} {{ h.old_number || '' }}
            </span>
            <span class="krt-bso-history-arrow">→</span>
            <span class="krt-bso-history-new">
              {{ h.new_series }} {{ h.new_number }}
            </span>
            <span class="krt-bso-history-time">{{ fmtDateTime(h.changed_at) }}</span>
          </div>
          <div class="krt-bso-history-reason">{{ h.reason }}</div>
        </li>
      </ul>
    </div>
  </section>
</template>

<script setup>
import { fmtDateTime } from './kegHelpers.js';

defineProps({
  form: { type: Object, required: true },
  cutoffFormatted: { type: String, default: '' },
  cutoffPassed: { type: Boolean, default: false },
  saving: { type: Boolean, default: false },
});
defineEmits(['replace']);
</script>
