<template>
  <main class="rules-page">
    <div class="rules-wrap">
      <!-- Герой -->
      <header class="rules-hero">
        <div class="rules-hero-inner">
          <p class="rules-kicker">Внутренний рабочий портал закупок</p>
          <h1 class="rules-title">Правила использования и данные</h1>
          <p class="rules-lead">
            Портал — внутренний рабочий инструмент отдела закупок: заказы, поставки,
            остатки, заявки поставщикам и ресторанам, аналитика и служебные
            уведомления. Это не официальный сайт какой-либо компании.
          </p>
          <div class="rules-hero-meta">
            <span class="rules-pill">Обновлено: июль 2026</span>
            <span class="rules-pill rules-pill-soft">БК · ВМ · Пицца Стар</span>
          </div>
        </div>
      </header>

      <!-- Какие данные -->
      <section class="rules-card rules-card-wide">
        <div class="rules-card-head">
          <span class="rules-ico">🗂️</span>
          <h2>Какие данные используются</h2>
        </div>
        <p>Для работы портала могут храниться:</p>
        <div class="rules-chips">
          <span v-for="c in dataChips" :key="c" class="rules-chip">{{ c }}</span>
        </div>
        <p class="rules-muted">
          Если пользователь добровольно подключает Telegram, портал сохраняет Telegram ID
          и использует его только для служебных уведомлений.
        </p>
      </section>

      <!-- Сетка секций -->
      <div class="rules-grid">
        <section v-for="s in sections" :key="s.title" class="rules-card">
          <div class="rules-card-head">
            <span class="rules-ico">{{ s.icon }}</span>
            <h2>{{ s.title }}</h2>
          </div>
          <p>{{ s.text }}</p>
        </section>
      </div>

      <!-- Контакт -->
      <section class="rules-contact">
        <div class="rules-contact-ico">💬</div>
        <div>
          <div class="rules-contact-title">Вопросы, изменение или удаление данных</div>
          <div class="rules-contact-sub">
            Напишите администратору портала:
            <a :href="`https://t.me/${supportTg}`" target="_blank" rel="noopener">@{{ supportTg }}</a>
          </div>
        </div>
      </section>

      <p class="rules-foot">Портал закупок · внутренний инструмент · доступ по правам</p>
    </div>
  </main>
</template>

<script setup>
import { useSupportContact } from '@/lib/supportContact.js';

const supportTg = useSupportContact();

const dataChips = [
  'Имя / логин', 'Email', 'Роль и права доступа', 'Настройки интерфейса',
  'История действий (аудит)', 'Сессии входа', 'Технические данные браузера',
  'Заказы и поставки', 'Остатки и заявки', 'Telegram ID (по желанию)',
];

const sections = [
  { icon: '🎯', title: 'Зачем это нужно', text: 'Для входа в портал, разграничения доступа по ролям и юрлицам, сохранения рабочих действий, восстановления истории изменений, отправки служебных уведомлений и нормальной работы модулей.' },
  { icon: '🏢', title: 'Разграничение по юрлицам', text: 'Данные разделены по юрлицам (Бургер БК, Воглия Матта, Пицца Стар). Пользователь видит только те юрлица и модули, к которым у него есть доступ.' },
  { icon: '💾', title: 'Хранение в браузере', text: 'Портал хранит в браузере сессию входа, настройки, черновики и отметки интерфейса. Это нужно для работы портала и не используется для рекламы или сторонней аналитики.' },
  { icon: '🔔', title: 'Уведомления', text: 'Служебные уведомления приходят на рабочую почту и, по желанию, в Telegram-бота. Уведомления касаются только рабочих процессов: заявок, поставок, напоминаний.' },
  { icon: '🔐', title: 'Безопасность', text: 'Вход защищён паролем и ограничением числа попыток. Сессии можно завершать на всех устройствах. Доступ к данным — только у пользователей с соответствующими правами.' },
  { icon: '🗑️', title: 'Доступ и удаление', text: 'Чтобы изменить, удалить данные или отключить Telegram — напишите администратору портала. Доступ имеют только сотрудники с нужными правами.' },
];
</script>

<style scoped>
.rules-page {
  min-height: 100vh;
  padding: 28px 18px 48px;
  background: radial-gradient(1200px 500px at 50% -8%, #fbeede 0%, #f5ece0 42%, #f2e7d8 100%);
  color: #2b1a10;
  font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
}
.rules-wrap { width: min(1080px, 100%); margin: 0 auto; }

/* Герой */
.rules-hero {
  border-radius: 18px;
  background: linear-gradient(135deg, #5b2c17 0%, #3f1d10 100%);
  color: #fff;
  padding: 34px 34px 30px;
  box-shadow: 0 20px 50px rgba(65, 35, 16, .28);
  position: relative;
  overflow: hidden;
}
.rules-hero::after {
  content: '';
  position: absolute; right: -60px; top: -60px;
  width: 220px; height: 220px; border-radius: 50%;
  background: radial-gradient(circle, rgba(231,111,81,.35), transparent 70%);
}
.rules-hero-inner { position: relative; z-index: 1; max-width: 760px; }
.rules-kicker {
  margin: 0 0 10px; color: #f4b183; font-size: 12px; font-weight: 800;
  text-transform: uppercase; letter-spacing: .1em;
}
.rules-title { margin: 0 0 12px; font-size: 34px; line-height: 1.12; font-weight: 800; }
.rules-lead { margin: 0 0 18px; color: #f2e2d4; font-size: 16px; line-height: 1.6; }
.rules-hero-meta { display: flex; gap: 8px; flex-wrap: wrap; }
.rules-pill {
  font-size: 12px; font-weight: 700; padding: 5px 12px; border-radius: 20px;
  background: rgba(255,255,255,.16); color: #fff;
}
.rules-pill-soft { background: rgba(244,177,131,.22); color: #f6c9a6; }

/* Карточки */
.rules-card {
  background: #fffaf3;
  border: 1px solid #ecdcc9;
  border-radius: 14px;
  padding: 22px 24px;
  box-shadow: 0 8px 24px rgba(65, 35, 16, .07);
}
.rules-card-wide { margin-top: 18px; }
.rules-card-head { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
.rules-ico {
  font-size: 18px; width: 38px; height: 38px; flex-shrink: 0;
  display: grid; place-items: center; border-radius: 10px;
  background: #f5e7d7; border: 1px solid #ecdcc9;
}
.rules-card h2 { margin: 0; font-size: 17px; color: #4a2716; }
.rules-card p { margin: 6px 0 0; color: #4d3a2d; line-height: 1.6; font-size: 14.5px; }
.rules-muted { color: #7a6353 !important; font-size: 13.5px !important; }

.rules-chips { display: flex; flex-wrap: wrap; gap: 7px; margin: 12px 0 6px; }
.rules-chip {
  font-size: 12.5px; font-weight: 600; color: #6b3f22;
  background: #f3e6d6; border: 1px solid #e6d2bd;
  padding: 5px 11px; border-radius: 18px;
}

/* Сетка */
.rules-grid {
  display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; margin-top: 14px;
}

/* Контакт */
.rules-contact {
  display: flex; align-items: center; gap: 14px; margin-top: 18px;
  padding: 18px 22px; border-radius: 14px;
  background: linear-gradient(135deg, #f6ead9, #f0dcc6);
  border: 1px solid #e6d2bd;
}
.rules-contact-ico {
  font-size: 22px; width: 46px; height: 46px; flex-shrink: 0;
  display: grid; place-items: center; border-radius: 12px;
  background: #fff6ec; border: 1px solid #e6d2bd;
}
.rules-contact-title { font-weight: 800; color: #3a2517; font-size: 15px; }
.rules-contact-sub { color: #5b4436; font-size: 14px; margin-top: 2px; }
.rules-contact a { color: #b52200; font-weight: 700; text-decoration: none; }
.rules-contact a:hover { text-decoration: underline; }

.rules-foot {
  text-align: center; color: #9c8571; font-size: 12.5px; margin: 22px 0 0;
}

@media (max-width: 760px) {
  .rules-grid { grid-template-columns: 1fr; }
  .rules-hero { padding: 26px 22px; }
  .rules-title { font-size: 27px; }
  .rules-card { padding: 18px; }
}
</style>
