<template>
  <div>
    <div class="krt-form-wrap">
      <div class="krt-form-topbar">
        <button class="krt-btn ghost" @click="$emit('back')">← Назад</button>
        <div class="krt-form-title-block">
          <div class="krt-form-title">{{ localId ? 'Заявка №' + localId : 'Новая заявка' }}</div>
          <div v-if="form.status" class="krt-form-sub">
            <span :class="'krt-badge krt-badge-' + form.status">{{ statusLabel(form.status) }}</span>
            <span v-if="deadlineIso && (form.status === 'DRAFT' || form.status === 'SUBMITTED')" class="krt-deadline-inline">
              <span v-if="!deadlinePassed">Изменить можно <b>до {{ deadlineFormatted }}</b></span>
              <span v-else class="krt-deadline-passed">Дедлайн прошёл</span>
            </span>
          </div>
          <div v-if="form.status === 'NOT_RETURNED' && form.not_returned_reason" class="krt-nr-reason-note">
            Причина: {{ form.not_returned_reason }}
          </div>
        </div>
      </div>

      <div v-if="formLoading" class="krt-empty">Загрузка...</div>
      <div v-else-if="formError" class="krt-error">{{ formError }}</div>

      <template v-else>
        <KegFormMainInfo
          :form="form"
          :form-readonly="formReadonly"
          :deadline-passed="deadlinePassed"
          :restaurant-info-loaded="restaurantInfoLoaded"
          :editing-id="localId"
          :available-dates="availableDates"
          :field-err="fieldErr"
        />

        <KegFormBsoSection
          v-if="bsoSectionVisible"
          :form="form"
          :cutoff-formatted="cutoffFormatted"
          :cutoff-passed="cutoffPassed"
          :saving="saving"
          @replace="openBsoReplace"
        />

        <KegFormKegList
          :catalog="catalog"
          :catalog-loading="catalogLoading"
          :keg-qties="kegQties"
          :form-readonly="formReadonly"
          :total-kegs-count="totalKegsCount"
          :total-kegs-types="totalKegsTypes"
          @open-photo="openPhoto"
        />

        <!-- Напоминание распечатать ТТН после формирования и до дедлайна -->
        <div v-if="form.status === 'SUBMITTED' && !deadlinePassed" class="krt-print-reminder">
          <div class="krt-print-reminder-icon">🖨</div>
          <div class="krt-print-reminder-text">
            <div class="krt-print-reminder-title">Не забудьте распечатать ТТН на бланке до дедлайна</div>
            <div class="krt-print-reminder-sub">
              Это нужно, чтобы убедиться, что бланк не испорчен и номер на нём совпадает с фактическим.
              <template v-if="deadlineFormatted"> Срок: <b>{{ deadlineFormatted }}</b>.</template>
            </div>
          </div>
          <button class="krt-btn primary" @click="printTtn" :disabled="saving">Распечатать сейчас</button>
        </div>
      </template>
    </div>

    <KegFormActionsBar
      v-if="!formLoading && !formError"
      :form-readonly="formReadonly"
      :editing-id="localId"
      :status="form.status"
      :saving="saving"
      :submit-ready="submitReady"
      :submit-missing-hint="submitMissingHint"
      :can-mark-not-returned="canMarkNotReturned"
      @save-draft="saveDraft"
      @submit="submit"
      @download-excel="downloadExcel"
      @print="printTtn"
      @cancel="cancelReturn"
      @delete="deleteDraft"
      @not-returned="markNotReturned"
    />

    <!-- Модалки формы -->
    <KegPhotoModal
      :show="photoModal.show"
      :url="photoModal.url"
      :name="photoModal.name"
      @close="closePhoto"
    />
    <KegConfirmModal
      :show="confirmModal.show"
      :title="confirmModal.title"
      :message="confirmModal.message"
      :ok-text="confirmModal.okText"
      :cancel-text="confirmModal.cancelText"
      :danger="confirmModal.danger"
      @ok="confirmOk"
      @cancel="confirmCancel"
    />
    <KegReplaceBsoModal
      :show="bsoModal.show"
      :current-series="form.bso_series"
      :current-number="form.bso_number"
      :cutoff-formatted="cutoffFormatted"
      :saving="bsoModal.saving"
      @close="closeBsoReplace"
      @submit="replaceBsoSubmit"
    />
    <KegSubmittedModal
      :show="submittedModal.show"
      :bso-str="submittedModal.bsoStr"
      :deadline-formatted="deadlineFormatted"
      @close="closeSubmitted"
      @print="onSubmittedPrint"
    />
    <KegNotReturnedModal
      :show="notReturnedModal.show"
      @confirm="notReturnedConfirm"
      @cancel="notReturnedCancel"
    />
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { statusLabel } from './kegHelpers.js';
import { useKegForm } from './useKegForm.js';
import KegFormMainInfo from './KegFormMainInfo.vue';
import KegFormBsoSection from './KegFormBsoSection.vue';
import KegFormKegList from './KegFormKegList.vue';
import KegFormActionsBar from './KegFormActionsBar.vue';
import KegPhotoModal from './KegPhotoModal.vue';
import KegConfirmModal from './KegConfirmModal.vue';
import KegReplaceBsoModal from './KegReplaceBsoModal.vue';
import KegSubmittedModal from './KegSubmittedModal.vue';
import KegNotReturnedModal from './KegNotReturnedModal.vue';

const props = defineProps({
  // null/0 — новая заявка; число — открыть существующую
  initialId: { type: [Number, null], default: null },
});
const emit = defineEmits(['back', 'deleted']);

const initialIdRef = computed(() => props.initialId);

const {
  localId, form, kegQties, catalog,
  formLoading, formError, catalogLoading, saving, restaurantInfoLoaded,
  fieldErr,
  deadlineIso, deadlinePassed, cutoffPassed,
  deadlineFormatted, cutoffFormatted, bsoSectionVisible,
  availableDates, formReadonly,
  totalKegsCount, totalKegsTypes,
  submitReady, submitMissingHint,
  photoModal, submittedModal, confirmModal, bsoModal,
  openPhoto, closePhoto,
  closeSubmitted, onSubmittedPrint,
  confirmOk, confirmCancel,
  openBsoReplace, closeBsoReplace, replaceBsoSubmit,
  saveDraft, submit, cancelReturn, deleteDraft,
  downloadExcel, printTtn,
  canMarkNotReturned, markNotReturned,
  notReturnedModal, notReturnedConfirm, notReturnedCancel,
} = useKegForm(initialIdRef, emit);
</script>
