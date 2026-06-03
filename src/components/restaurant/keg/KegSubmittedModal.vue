<template>
  <div v-if="show" class="krt-submitted-overlay" @click.self="$emit('close')">
    <div class="krt-submitted">
      <div class="krt-submitted-icon" aria-hidden="true">
        <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
      <h3 class="krt-submitted-title">Заявка сформирована</h3>
      <p v-if="bsoStr" class="krt-submitted-bso">№ {{ bsoStr }}</p>

      <ol class="krt-submitted-steps">
        <li>
          <span class="krt-step-num">1</span>
          <div>
            <div class="krt-step-title">Заявка создана и отправлена в отдел закупок</div>
            <div class="krt-step-sub">
              <template v-if="deadlineFormatted">
                Состав ещё можно скорректировать <b>до {{ deadlineFormatted }}</b>.
              </template>
            </div>
          </div>
        </li>
        <li class="krt-step-key">
          <span class="krt-step-num">2</span>
          <div>
            <div class="krt-step-title">До дедлайна распечатайте ТТН на бланке</div>
            <div class="krt-step-sub">
              Это обязательно — чтобы убедиться, что бланк не испорчен и номер на бланке совпадает с фактическим.
              <b class="krt-step-warn">Если бланк испортите при печати — успейте заменить бланк (окно замены — до 15:00 того же дня).</b>
              После маршрутизации поменять номер уже не получится.
            </div>
          </div>
        </li>
        <li>
          <span class="krt-step-num">3</span>
          <div>
            <div class="krt-step-title">Дождитесь уведомления о маршрутизации</div>
            <div class="krt-step-sub">Придёт сообщение в Telegram-бот <b>@supplyportal_bot</b> — там будет водитель и машина.</div>
          </div>
        </li>
        <li>
          <span class="krt-step-num">4</span>
          <div>
            <div class="krt-step-title">Впишите ОТ РУКИ и передайте водителю</div>
            <div class="krt-step-sub">
              На уже распечатанном бланке заполните ручкой: <b>водителя, машину, «товар принял к перевозке»</b>.
              <b class="krt-step-warn">Возьмите подпись водителя на возвратной ТТН и передайте бланк ему вместе с кегами.</b>
            </div>
          </div>
        </li>
      </ol>

      <div class="krt-submitted-actions">
        <button class="krt-btn ghost" @click="$emit('close')">Закрыть</button>
        <button class="krt-btn primary" @click="$emit('print')">Распечатать сейчас</button>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  show: { type: Boolean, default: false },
  bsoStr: { type: String, default: '' },
  deadlineFormatted: { type: String, default: '' },
});
defineEmits(['close', 'print']);
</script>
