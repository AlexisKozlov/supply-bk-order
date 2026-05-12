<template>
  <div class="krt-actions-bar">
    <div class="krt-actions-bar-inner">
      <template v-if="!formReadonly">
        <!-- Черновик / новая заявка: «Сохранить черновик» + «Сформировать ТТН» -->
        <template v-if="!editingId || status === 'DRAFT'">
          <button class="krt-btn ghost" @click="$emit('save-draft')" :disabled="saving">
            {{ saving ? 'Сохранение...' : 'Сохранить черновик' }}
          </button>
          <button
            class="krt-btn primary"
            @click="$emit('submit')"
            :disabled="saving || !submitReady"
            :title="submitReady ? '' : submitMissingHint"
          >
            Сформировать ТТН
          </button>
        </template>
        <!-- Уже отправленная заявка до дедлайна: одна кнопка «Сохранить изменения» -->
        <template v-else>
          <button class="krt-btn primary" @click="$emit('save-draft')" :disabled="saving">
            {{ saving ? 'Сохранение...' : 'Сохранить изменения' }}
          </button>
        </template>
      </template>
      <button
        v-if="editingId"
        class="krt-btn outline"
        @click="$emit('download-excel')"
        :disabled="saving || status === 'DRAFT'"
        :title="status === 'DRAFT' ? 'Доступно после формирования ТТН' : ''"
      >
        Скачать Excel
      </button>
      <button
        v-if="editingId"
        class="krt-btn outline"
        @click="$emit('print')"
        :disabled="saving || status === 'DRAFT'"
        :title="status === 'DRAFT' ? 'Доступно после формирования ТТН' : ''"
      >
        Печать
      </button>
    </div>
    <div v-if="!formReadonly && (editingId && (status === 'SUBMITTED' || status === 'DRAFT'))" class="krt-actions-bar-extra">
      <button v-if="status === 'SUBMITTED'" class="krt-link danger" @click="$emit('cancel')" :disabled="saving">
        Отменить заявку
      </button>
      <button v-if="status === 'DRAFT'" class="krt-link danger" @click="$emit('delete')" :disabled="saving">
        Удалить черновик
      </button>
    </div>
  </div>
</template>

<script setup>
defineProps({
  formReadonly: { type: Boolean, default: false },
  editingId: { type: [Number, null], default: null },
  status: { type: String, default: '' },
  saving: { type: Boolean, default: false },
  submitReady: { type: Boolean, default: false },
  submitMissingHint: { type: String, default: '' },
});
defineEmits(['save-draft', 'submit', 'download-excel', 'print', 'cancel', 'delete']);
</script>
