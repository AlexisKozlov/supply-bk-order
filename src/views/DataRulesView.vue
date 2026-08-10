<template>
  <main class="rules-page">
    <div class="rules-wrap">
      <!-- Страницу открывают из окна входа, подвала и уведомления о cookie —
           и раньше с неё некуда было деться. -->
      <button class="rules-back" @click="goBack">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M19 12H5" /><path d="M12 19l-7-7 7-7" />
        </svg>
        Назад
      </button>
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
          <span class="rules-ico"><BkIcon name="database" size="lg" /></span>
          <h2>Какие данные используются</h2>
        </div>
        <p>
          Портал хранит данные, необходимые для работы отдела закупок и связанных
          процессов. В зависимости от роли и действий это может быть:
        </p>
        <div class="rules-chips">
          <span v-for="c in dataChips" :key="c" class="rules-chip">{{ c }}</span>
        </div>
        <p class="rules-muted">
          Часть данных вносится сотрудниками вручную (заказы, остатки, цены, справочники),
          часть создаётся автоматически при работе (история действий, сессии, технические
          данные). Telegram ID и рабочий email сохраняются только если пользователь сам
          их подключает, и используются исключительно для служебных уведомлений и входа.
        </p>
      </section>

      <!-- Сетка секций -->
      <div class="rules-grid">
        <section v-for="s in sections" :key="s.title" class="rules-card">
          <div class="rules-card-head">
            <span class="rules-ico"><BkIcon :name="s.icon" size="lg" /></span>
            <h2>{{ s.title }}</h2>
          </div>
          <p>{{ s.text }}</p>
        </section>
      </div>

      <!-- Контакт -->
      <section class="rules-contact">
        <div class="rules-contact-ico"><BkIcon name="chat" size="lg" /></div>
        <div>
          <div class="rules-contact-title">Вопросы, изменение или удаление данных</div>
          <div class="rules-contact-sub">
            Напишите администратору портала:
            <a :href="`https://t.me/${supportTg}`" target="_blank" rel="noopener">@{{ supportTg }}</a>
          </div>
        </div>
      </section>

      <div class="rules-end">
        <button class="rules-back rules-back-wide" @click="goBack">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 12H5" /><path d="M12 19l-7-7 7-7" />
          </svg>
          Вернуться в портал
        </button>
      </div>

      <p class="rules-foot">Портал закупок · внутренний инструмент · доступ по правам</p>
    </div>
  </main>
</template>

<script setup>
import { useRouter } from 'vue-router';
import BkIcon from '@/components/ui/BkIcon.vue';
import { useSupportContact } from '@/lib/supportContact.js';

const router = useRouter();
const supportTg = useSupportContact();

// Страницу часто открывают в новой вкладке из окна входа — истории там нет,
// и «назад» некуда. В этом случае уводим на главную.
function goBack() {
  if (window.history.length > 1) router.back();
  else router.push('/');
}

const dataChips = [
  'Имя / логин', 'Email', 'Роль и права доступа', 'Настройки интерфейса',
  'История действий (аудит)', 'Сессии и устройства входа', 'IP и данные браузера',
  'Заказы, поставки, остатки', 'Заявки поставщикам и ресторанам', 'Цены и оплаты',
  'Загруженные файлы (Excel, накладные, фото)', 'Telegram ID (по желанию)',
  'Email ресторана (для кабинета)',
];

const sections = [
  { icon: 'package', title: 'Зачем это нужно', text: 'Данные нужны для входа в портал, разграничения доступа по ролям и юрлицам, сохранения и восстановления рабочих действий, ведения истории изменений (кто, что и когда менял), отправки служебных уведомлений и корректной работы всех модулей: заказов, поставок, остатков, заявок, цен, аналитики и задач. Портал не использует данные для рекламы, профилирования или продажи третьим лицам.' },
  { icon: 'building', title: 'Разграничение по юрлицам', text: 'Рабочие данные разделены по юрлицам — Бургер БК, Воглия Матта и Пицца Стар. Заказы, остатки, расход, цены и заявки каждого юрлица хранятся и показываются отдельно. Пользователь видит только те юрлица и модули, к которым у него есть доступ; часть модулей (например, связанные с 1С) доступна только для БК и ВМ.' },
  { icon: 'user', title: 'Кто имеет доступ', text: 'Доступ к данным есть только у авторизованных сотрудников. Права выдаются по ролям и по каждому модулю отдельно (просмотр, редактирование, полный доступ). Например, отдел качества и бухгалтерия могут только просматривать справочники, а закупки — редактировать. Кабинеты ресторанов видят только свои данные.' },
  { icon: 'edit', title: 'История и аудит', text: 'Ключевые действия записываются в журнал: создание и изменение заказов, заявок, цен, справочников, вход в систему. По многим объектам доступна отмена/повтор действий и просмотр, кто внёс изменение. Это нужно для прозрачности и восстановления данных при ошибках.' },
  { icon: 'save', title: 'Хранение в браузере', text: 'Портал хранит в браузере токен сессии входа, настройки интерфейса, черновики заказов и отметки (например, избранные разделы). Как приложение (PWA) портал может кэшировать интерфейс для быстрой загрузки. Это техническое хранение для работы портала, оно не используется для рекламы или сторонней аналитики.' },
  { icon: 'bell', title: 'Уведомления', text: 'Служебные уведомления приходят на рабочую почту и, по желанию пользователя, в Telegram-бота или как push в приложении. Уведомления касаются только рабочих процессов: заявок, поставок, напоминаний о дедлайнах, изменения цен и статусов. Подписку на Telegram можно подключить и отключить в любой момент.' },
  { icon: 'key', title: 'Безопасность', text: 'Пароли хранятся в зашифрованном виде (хеширование), вход защищён ограничением числа попыток за короткий период. Сессии имеют срок действия, их можно завершить на всех устройствах разом. Передача данных идёт по защищённому соединению (HTTPS). Доступ к данным — только у пользователей с соответствующими правами.' },
  { icon: 'link', title: 'Внешние сервисы', text: 'Для отдельных функций портал обращается к внешним сервисам: Telegram (доставка уведомлений и привязка аккаунта), почтовый сервер (отправка писем). Наружу уходит только то, что нужно для конкретной функции, — например, текст уведомления и Telegram ID. Сторонняя реклама и трекеры не используются.' },
  { icon: 'home', title: 'Кабинеты ресторанов', text: 'Рестораны заходят в свой кабинет по номеру и паролю или по ссылке из Telegram. Для кабинета может храниться рабочий email (для входа и уведомлений) и привязки Telegram сотрудников ресторана. Ресторан видит только свои заявки, остатки и уведомления.' },
  { icon: 'delete', title: 'Доступ и удаление', text: 'Чтобы уточнить, изменить или удалить данные, отключить Telegram или закрыть доступ — напишите администратору портала. Отключённый ресторан или сотрудник теряет доступ, а связанные уведомления прекращаются.' },
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
.rules-ico :deep(svg) { display: block; }
.rules-ico {
  font-size: 18px; width: 38px; height: 38px; flex-shrink: 0;
  display: grid; place-items: center; border-radius: 10px;
  background: #f5e7d7; border: 1px solid #ecdcc9;
}
.rules-card h2 { margin: 0; font-size: 17px; color: #4a2716; }
.rules-card p { margin: 6px 0 0; color: #4d3a2d; line-height: 1.6; font-size: 14.5px; }
.rules-muted { color: #7a6353 !important; font-size: 13.5px !important; }

.rules-back {
  display: inline-flex; align-items: center; gap: 8px;
  min-height: 40px; padding: 9px 18px 9px 14px;
  margin-bottom: 18px;
  border: 1.5px solid #e0cdb6; border-radius: 999px;
  background: #fff6ec; color: #6b3f22;
  font-family: inherit; font-size: 14px; font-weight: 700; cursor: pointer;
  transition: background .15s, border-color .15s;
}
.rules-back:hover { background: #f3e6d6; border-color: #c9ad8e; }
.rules-back:focus-visible { outline: 2px solid #C1502E; outline-offset: 2px; }
.rules-end { display: flex; justify-content: center; margin: 22px 0 6px; }
.rules-back-wide { margin-bottom: 0; padding: 11px 26px 11px 20px; }

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
