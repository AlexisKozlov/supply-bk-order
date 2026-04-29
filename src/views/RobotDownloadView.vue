<template>
  <main class="robot-page">
    <section class="robot-shell">
      <div class="robot-header">
        <p class="robot-kicker">1C Robot Pro</p>
        <h1>Скачать программу для загрузки файлов в 1С</h1>
        <p class="robot-lead">
          Установите программу на Windows-компьютер, скачайте с сайта файл queue_ok.xlsx и запустите загрузку в открытой 1С.
        </p>
      </div>

      <div class="robot-panel">
        <div class="robot-version">
          <span>Текущая версия</span>
          <strong>{{ versionInfo.version || '1.0.0' }}</strong>
        </div>
        <p class="robot-notes">{{ versionInfo.notes || 'Описание версии пока не указано.' }}</p>

        <div v-if="installerAvailable" class="robot-actions">
          <a class="robot-button primary" :href="installerUrl">Скачать установщик</a>
          <a class="robot-button" href="/version.json">Открыть version.json</a>
        </div>

        <div v-else class="robot-warning">
          Установщик ещё не загружен на сайт. После публикации обновления кнопка скачивания появится автоматически.
        </div>
      </div>

      <div class="robot-steps">
        <div>
          <strong>1. Скачайте установщик</strong>
          <span>Файл называется 1C_Robot_Setup.exe.</span>
        </div>
        <div>
          <strong>2. Установите программу</strong>
          <span>Права администратора не нужны.</span>
        </div>
        <div>
          <strong>3. Загружайте queue_ok.xlsx</strong>
          <span>Программа сама предложит обновление, когда выйдет новая версия.</span>
        </div>
      </div>
    </section>
  </main>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';

const versionInfo = ref({});
const installerAvailable = ref(false);

const installerUrl = computed(() => versionInfo.value.installer_url || '/releases/1C_Robot_Setup.exe');

onMounted(async () => {
  try {
    const response = await fetch('/version.json', { cache: 'no-store' });
    if (response.ok) {
      versionInfo.value = await response.json();
    }
  } catch (error) {
    console.warn('Не удалось загрузить version.json', error);
  }

  try {
    const response = await fetch(installerUrl.value, { method: 'HEAD', cache: 'no-store' });
    installerAvailable.value = response.ok;
  } catch (error) {
    installerAvailable.value = false;
  }
});
</script>

<style scoped>
.robot-page {
  min-height: 100vh;
  background: #f4f6f8;
  color: #172033;
  padding: 48px 20px;
}

.robot-shell {
  max-width: 920px;
  margin: 0 auto;
}

.robot-header {
  margin-bottom: 24px;
}

.robot-kicker {
  margin: 0 0 8px;
  color: #0f766e;
  font-weight: 700;
}

h1 {
  margin: 0;
  font-size: 34px;
  line-height: 1.15;
}

.robot-lead {
  max-width: 720px;
  margin: 14px 0 0;
  color: #526070;
  font-size: 17px;
  line-height: 1.55;
}

.robot-panel,
.robot-steps > div {
  background: #fff;
  border: 1px solid #dde3ea;
  border-radius: 8px;
}

.robot-panel {
  padding: 24px;
}

.robot-version {
  display: flex;
  align-items: baseline;
  gap: 12px;
}

.robot-version span {
  color: #64748b;
}

.robot-version strong {
  font-size: 28px;
}

.robot-notes {
  margin: 12px 0 22px;
  color: #475569;
}

.robot-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.robot-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 42px;
  padding: 0 16px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  color: #172033;
  text-decoration: none;
  font-weight: 700;
}

.robot-button.primary {
  background: #0f766e;
  border-color: #0f766e;
  color: #fff;
}

.robot-warning {
  border: 1px solid #f4c96b;
  background: #fff8e6;
  border-radius: 6px;
  padding: 14px;
  color: #705600;
}

.robot-steps {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  margin-top: 16px;
}

.robot-steps > div {
  padding: 16px;
}

.robot-steps strong,
.robot-steps span {
  display: block;
}

.robot-steps span {
  margin-top: 8px;
  color: #64748b;
  line-height: 1.45;
}

@media (max-width: 760px) {
  .robot-page {
    padding: 28px 14px;
  }

  h1 {
    font-size: 27px;
  }

  .robot-steps {
    grid-template-columns: 1fr;
  }
}
</style>
