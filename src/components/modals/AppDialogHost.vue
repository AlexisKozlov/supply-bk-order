<template>
  <ConfirmModal v-if="state.confirm.show"
                :title="state.confirm.title"
                :message="state.confirm.message"
                :ok-text="state.confirm.okText"
                :cancel-text="state.confirm.cancelText"
                :danger="state.confirm.danger"
                @confirm="onConfirmOk"
                @cancel="onConfirmCancel"/>
  <InfoModal v-if="state.info.show"
             :title="state.info.title"
             :message="state.info.message"
             :type="state.info.type"
             @close="onInfoClose"/>
  <PromptModal v-if="state.prompt.show"
               :title="state.prompt.title"
               :message="state.prompt.message"
               :value="state.prompt.value"
               :placeholder="state.prompt.placeholder"
               :ok-text="state.prompt.okText"
               :cancel-text="state.prompt.cancelText"
               @ok="onPromptOk"
               @cancel="onPromptCancel"/>
</template>

<script setup>
import { defineAsyncComponent } from 'vue';
import { dialogState, _dialogInternals } from '@/lib/appDialogs.js';

const ConfirmModal = defineAsyncComponent(() => import('@/components/modals/ConfirmModal.vue'));
const InfoModal    = defineAsyncComponent(() => import('@/components/modals/InfoModal.vue'));
const PromptModal  = defineAsyncComponent(() => import('@/components/modals/PromptModal.vue'));

const state = dialogState;

const onConfirmOk     = () => _dialogInternals.settleConfirm(true);
const onConfirmCancel = () => _dialogInternals.settleConfirm(false);
const onInfoClose     = () => _dialogInternals.settleInfo();
const onPromptOk      = (v) => _dialogInternals.settlePrompt(v);
const onPromptCancel  = () => _dialogInternals.settlePrompt(null);
</script>
