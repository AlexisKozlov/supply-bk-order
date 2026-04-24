<template>
  <main class="rules-page">
    <section class="rules-panel">
      <p class="rules-kicker">Неофициальный рабочий портал</p>
      <h1>Правила использования и данные</h1>
      <p class="rules-lead">
        Портал используется как внутренний рабочий инструмент для закупок, заказов,
        остатков, поставок, уведомлений и связанных задач. Это не официальный сайт
        какой-либо компании.
      </p>

      <div class="rules-block">
        <h2>Какие данные используются</h2>
        <p>
          Для работы портала могут храниться имя или логин пользователя, email,
          роль и права доступа, настройки интерфейса, история действий, сессионные
          данные входа, технические данные браузера, а также данные по заказам,
          поставкам, остаткам и заявкам.
        </p>
        <p>
          Если пользователь добровольно подключает Telegram, портал сохраняет
          Telegram ID и использует его только для служебных уведомлений.
        </p>
      </div>

      <div class="rules-block">
        <h2>Зачем это нужно</h2>
        <p>
          Данные нужны для входа в портал, разграничения доступа, сохранения
          рабочих действий, восстановления истории изменений, отправки служебных
          уведомлений и нормальной работы модулей портала.
        </p>
      </div>

      <div class="rules-block">
        <h2>Хранение в браузере</h2>
        <p>
          Портал использует техническое хранение в браузере: сессию входа,
          настройки, черновики и отметки интерфейса. Это нужно для работы портала
          и не используется для рекламы или сторонней аналитики.
        </p>
      </div>

      <div class="rules-block">
        <h2>Доступ и удаление</h2>
        <p>
          Доступ к данным имеют только пользователи с соответствующими правами в
          портале. Если нужно изменить, удалить данные или отключить Telegram,
          напишите администратору портала.
        </p>
      </div>

      <div class="rules-contact">
        Администратор портала: <a href="https://t.me/alexiskozlov" target="_blank" rel="noopener">@alexiskozlov</a>
      </div>
    </section>
  </main>
</template>

<script setup>
</script>

<style scoped>
.rules-page {
  min-height: 100vh;
  padding: 32px 16px;
  background: #f7f0e7;
  color: #2b1a10;
  font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
}
.rules-panel {
  width: min(860px, 100%);
  margin: 0 auto;
  background: #fffaf3;
  border: 1px solid #eadccc;
  border-radius: 8px;
  padding: 32px;
  box-shadow: 0 18px 48px rgba(65, 35, 16, .12);
}
.rules-kicker {
  margin: 0 0 8px;
  color: #a25a2b;
  font-size: 12px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .08em;
}
h1 {
  margin: 0 0 14px;
  font-size: 30px;
  line-height: 1.15;
}
.rules-lead {
  margin: 0 0 24px;
  color: #6f5948;
  font-size: 16px;
  line-height: 1.55;
}
.rules-block {
  border-top: 1px solid #eadccc;
  padding-top: 18px;
  margin-top: 18px;
}
h2 {
  margin: 0 0 8px;
  font-size: 18px;
}
p {
  margin: 8px 0 0;
  color: #4d3a2d;
  line-height: 1.6;
}
.rules-contact {
  margin-top: 24px;
  padding: 14px 16px;
  border-radius: 8px;
  background: #f1e2d1;
  color: #3a2517;
  font-weight: 700;
}
a {
  color: #b52200;
  text-decoration: none;
}
a:hover {
  text-decoration: underline;
}
@media (max-width: 640px) {
  .rules-panel { padding: 22px; }
  h1 { font-size: 25px; }
}
</style>
