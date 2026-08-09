<template>
  <div class="ui-pw">
    <input
      v-bind="$attrs"
      :class="inputClass"
      :type="show ? 'text' : 'password'"
      :value="modelValue"
      @input="$emit('update:modelValue', $event.target.value)"
    />
    <button type="button" class="ui-pw-eye" tabindex="-1"
            :title="show ? 'Скрыть пароль' : 'Показать пароль'"
            @click="show = !show">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <template v-if="show">
          <path d="M3 3 21 21" />
          <path d="M10.6 6.2A9.6 9.6 0 0 1 12 6c6 0 9.5 6 9.5 6a16 16 0 0 1-3.2 3.8" />
          <path d="M6.6 8.3A16 16 0 0 0 2.5 12S6 18 12 18c1.5 0 2.8-.4 4-.9" />
          <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2" />
        </template>
        <template v-else>
          <path d="M2.5 12S6 6 12 6s9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" />
          <circle cx="12" cy="12" r="3" />
        </template>
      </svg>
    </button>
  </div>
</template>

<script setup>
/**
 * Поле пароля с кнопкой «показать». Без неё человек не понимает, опечатался
 * он или сервер отказал — особенно при смене пароля, где новый вводят дважды.
 *
 * Значок нарисован здесь, а не взят из общего набора icons.js: тамошний
 * `eye` синий (#1976D2) и выбивается из тёплой палитры портала. Здесь он
 * наследует цвет кнопки через currentColor.
 *
 * inputClass — чтобы поле осталось в стилях своего экрана: у кабинета
 * `.pf-input`, у поиска карточек `.field-input`, в админке класса нет.
 */
defineOptions({ inheritAttrs: false });

defineProps({
  modelValue: { type: String, default: '' },
  inputClass: { type: [String, Array, Object], default: '' },
});
defineEmits(['update:modelValue']);

import { ref } from 'vue';
const show = ref(false);
</script>

<style scoped>
.ui-pw { position: relative; display: block; }
.ui-pw input { width: 100%; padding-right: 44px; box-sizing: border-box; }
.ui-pw-eye {
  position: absolute; right: 6px; top: 50%; transform: translateY(-50%);
  width: 32px; height: 32px;
  display: flex; align-items: center; justify-content: center;
  border: none; border-radius: 50%;
  background: rgba(80, 35, 20, .07);
  color: var(--brand-brown, #502314);
  cursor: pointer;
  transition: background var(--tk-transition-fast, .12s ease), color var(--tk-transition-fast, .12s ease);
}
.ui-pw-eye:hover { background: rgba(214, 35, 0, .12); color: var(--brand-red, #D62300); }
.ui-pw-eye:focus-visible { outline: none; box-shadow: var(--tk-focus-ring); }
.ui-pw-eye svg { width: 18px; height: 18px; display: block; }
</style>
