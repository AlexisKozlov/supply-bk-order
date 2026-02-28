-- Таблицы для графика доставки в рестораны

CREATE TABLE IF NOT EXISTS restaurants (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number          SMALLINT UNSIGNED NOT NULL,
  region          VARCHAR(40) NOT NULL DEFAULT 'Минск',
  city            VARCHAR(80) NOT NULL DEFAULT '',
  address         VARCHAR(255) NOT NULL DEFAULT '',
  legal_entity_group VARCHAR(20) NOT NULL DEFAULT 'BK_VM',
  active          TINYINT(1) NOT NULL DEFAULT 1,
  notes           VARCHAR(500) DEFAULT NULL,
  nearby          VARCHAR(255) DEFAULT NULL,
  sort_order      SMALLINT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_number_group (number, legal_entity_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS delivery_schedule (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurant_id   INT UNSIGNED NOT NULL,
  day_of_week     TINYINT NOT NULL COMMENT '1=ПН, 2=ВТ, 3=СР, 4=ЧТ, 5=ПТ, 6=СБ',
  delivery_time   VARCHAR(30) DEFAULT NULL,
  notes           VARCHAR(255) DEFAULT NULL,
  updated_at      DATETIME DEFAULT NULL,
  updated_by      VARCHAR(100) DEFAULT NULL,
  UNIQUE KEY uq_rest_day (restaurant_id, day_of_week),
  CONSTRAINT fk_ds_rest FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ALTER для существующей таблицы (если уже создана без этих колонок)
-- ALTER TABLE delivery_schedule ADD COLUMN updated_at DATETIME DEFAULT NULL;
-- ALTER TABLE delivery_schedule ADD COLUMN updated_by VARCHAR(100) DEFAULT NULL;

-- ═══ МИНСК (31 ресторан) ═══

INSERT INTO restaurants (number, region, city, address, legal_entity_group, nearby, sort_order) VALUES
(1,  'Минск', 'Минск', 'Притыцкого 154 (Каменная горка)',                'BK_VM', '1,17,2,55,61', 1),
(2,  'Минск', 'Минск', 'Притыцкого 19а / 28а (Пушкинская)',              'BK_VM', NULL,           2),
(3,  'Минск', 'Минск', 'Свободы 17 (Немига)',                             'BK_VM', NULL,           3),
(4,  'Минск', 'Минск', 'Привокзальная 7 (Вокзал)',                        'BK_VM', '49,14,3',      4),
(5,  'Минск', 'Минск', 'Национальный Аэропорт',                           'BK_VM', NULL,           5),
(7,  'Минск', 'Минск', 'Дзержинского 126 (Простор)',                      'BK_VM', NULL,           6),
(8,  'Минск', 'Минск', 'Независимости 179 (Уручье)',                      'BK_VM', '41,4,59',      7),
(12, 'Минск', 'Минск', 'Независимости 56 (ЦУМ)',                          'BK_VM', NULL,           8),
(14, 'Минск', 'Минск', 'Победителей 9 (Галерея)',                         'BK_VM', NULL,           9),
(16, 'Минск', 'Минск', 'Мстиславца, 11 Дана Молл',                       'BK_VM', '26,2,60',      10),
(17, 'Минск', 'Минск', 'Каменногорская 3 (Простор)',                      'BK_VM', NULL,           11),
(20, 'Минск', 'Минск', 'Партизанский 182 (Простор)',                      'BK_VM', NULL,           12),
(22, 'Минск', 'Минск', 'ул. Денисовская, 8 (Е-Сити)',                     'BK_VM', '27,7',         13),
(23, 'Минск', 'Минск', 'Пересечение Логойского тракта и МКАД (Экспобел)', 'BK_VM', NULL,           14),
(26, 'Минск', 'Минск', 'Тимирязева 74А (Палаццо)',                        'BK_VM', NULL,           15),
(27, 'Минск', 'Минск', 'Гурского, 56 (Риф)',                              'BK_VM', '23,34',        16),
(31, 'Минск', 'Минск', 'Якуба Коласа, 28-1 (Сузорье)',                   'BK_VM', NULL,           17),
(32, 'Минск', 'Минск', 'Сурганова 50/1 (Рига)',                           'BK_VM', NULL,           18),
(33, 'Минск', 'Минск', 'В. Хоружей 18Б (Охотник)',                        'BK_VM', '8,16',         19),
(34, 'Минск', 'Минск', 'Д.Боровая, ул. Кольцевая, дом 4 (А-100)',        'BK_VM', NULL,           20),
(41, 'Минск', 'Минск', 'ул. Кирова, 2 (Грин)',                            'BK_VM', NULL,           21),
(45, 'Минск', 'Минск', 'Рокоссовского, 2 Гиппо',                         'BK_VM', '31,32,12,33',  22),
(49, 'Минск', 'Минск', 'Кальварийская, 24',                               'BK_VM', NULL,           23),
(53, 'Минск', 'Минск', 'Щомыслицкий с/с, д 32, к.4 (Даймонд)',           'BK_VM', NULL,           24),
(54, 'Минск', 'Минск', 'Дзержинского, 104 (Титан)',                       'BK_VM', NULL,           25),
(55, 'Минск', 'Минск', 'Притыцкого, 156 (ГринСити)',                      'BK_VM', NULL,           26),
(56, 'Минск', 'Минск', 'пр. Партизанский, 79 (Призма)',                   'BK_VM', NULL,           27),
(57, 'Минск', 'Минск', 'Аэровокзальная, 19-1, 6 сектор (Аэропорт)',      'BK_VM', NULL,           28),
(59, 'Минск', 'Минск', 'Толстого, 1 (СитиМолл)',                          'BK_VM', NULL,           29),
(60, 'Минск', 'Минск', 'пр. Победителей, 102А',                           'BK_VM', NULL,           30),
(61, 'Минск', 'Минск', 'ул. Притыцкого 29 (Тивали)',                      'BK_VM', NULL,           31);

-- ═══ РЕГИОНЫ (25 ресторанов) ═══

INSERT INTO restaurants (number, region, city, address, legal_entity_group, nearby, sort_order) VALUES
(15, 'Регионы', 'Гродно',      'ул. Космонавтов, 81 (Алми)',          'BK_VM', '22,45,56', 32),
(28, 'Регионы', 'Гродно',      'пр-т Космонавтов, 11 (автовокзал)',   'BK_VM', NULL,       33),
(10, 'Регионы', 'Гродно',      'ул. Советская, 25',                   'BK_VM', NULL,       34),
(37, 'Регионы', 'Гродно',      'пр-т Я. Купалы, 87 (Тринити)',        'BK_VM', '20,45',    35),
(48, 'Регионы', 'Гродно',      'ул. Дубко, 17',                       'BK_VM', NULL,       36),
(36, 'Регионы', 'Витебск',     'ул. Чкалова, 35',                     'BK_VM', NULL,       37),
(44, 'Регионы', 'Витебск',     'пр-т Строителей, 15 В-Г',             'BK_VM', '57,5',     38),
(21, 'Регионы', 'Витебск',     'ул. Ленина, 28',                      'BK_VM', NULL,       39),
(39, 'Регионы', 'Полоцк',      'шоссе Вильнюсское, 1',                'BK_VM', NULL,       40),
(18, 'Регионы', 'Брест',       'ул. Махновича, 6',                    'BK_VM', NULL,       41),
(13, 'Регионы', 'Брест',       'ул. Советская, 71',                   'BK_VM', NULL,       42),
(58, 'Регионы', 'Пинск',       'ул. 60 лет Октября, 19',              'BK_VM', NULL,       43),
(40, 'Регионы', 'Солигорск',   'ул. Железнодорожная, 21А',            'BK_VM', NULL,       44),
(50, 'Регионы', 'Солигорск',   'ул. Кольцевая, 4',                    'BK_VM', NULL,       45),
(24, 'Регионы', 'Могилев',     'ул. Первомайская, 57 (Атриум)',       'BK_VM', NULL,       46),
(19, 'Регионы', 'Могилев',     'пр. Пушкинский, 41а',                 'BK_VM', NULL,       47),
(52, 'Регионы', 'Могилев',     'Минское шоссе, 31-1',                 'BK_VM', NULL,       48),
(35, 'Регионы', 'Мозырь',      'ул. Нефтестроителей, 26/1',           'BK_VM', NULL,       49),
(11, 'Регионы', 'Гомель',      'ул. Привокзальная, 1',                'BK_VM', NULL,       50),
(42, 'Регионы', 'Гомель',      'ул. Хатаевича, 9',                    'BK_VM', NULL,       51),
(46, 'Регионы', 'Гомель',      'пр-т Речицкий, 5В-71',               'BK_VM', NULL,       52),
(43, 'Регионы', 'Жлобин',      '20-й микрорайон, 30',                 'BK_VM', NULL,       53),
(47, 'Регионы', 'Лида',        'ул. Качана, 29',                      'BK_VM', NULL,       54),
(51, 'Регионы', 'Барановичи',  'ул. Советская, 74а',                  'BK_VM', NULL,       55),
(62, 'Регионы', 'Бобруйск',    'ул. 50 лет ВЛКСМ, 33-3',             'BK_VM', NULL,       56);

-- ═══ РАСПИСАНИЕ ДОСТАВОК ═══
-- day_of_week: 1=ПН, 2=ВТ, 3=СР, 4=ЧТ, 5=ПТ, 6=СБ

-- Ресторан 1 (Каменная горка): ВТ, ПТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=1 AND legal_entity_group='BK_VM'), 2, '10:30-14:00'),
((SELECT id FROM restaurants WHERE number=1 AND legal_entity_group='BK_VM'), 5, '10:30-14:00');

-- Ресторан 2 (Пушкинская): ВТ, ЧТ, СБ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=2 AND legal_entity_group='BK_VM'), 2, '12:30-14:00'),
((SELECT id FROM restaurants WHERE number=2 AND legal_entity_group='BK_VM'), 4, '12:30-14:00'),
((SELECT id FROM restaurants WHERE number=2 AND legal_entity_group='BK_VM'), 6, '7:30-9:00');

-- Ресторан 3 (Немига): ПТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=3 AND legal_entity_group='BK_VM'), 5, '8:00-13:00');

-- Ресторан 4 (Вокзал): ВТ, ЧТ, СБ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=4 AND legal_entity_group='BK_VM'), 2, '16:00-17:30'),
((SELECT id FROM restaurants WHERE number=4 AND legal_entity_group='BK_VM'), 4, '16:00-17:30'),
((SELECT id FROM restaurants WHERE number=4 AND legal_entity_group='BK_VM'), 6, '12:30-14:00');

-- Ресторан 5 (Аэропорт): ВТ, ПТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=5 AND legal_entity_group='BK_VM'), 2, '13:30-15:00'),
((SELECT id FROM restaurants WHERE number=5 AND legal_entity_group='BK_VM'), 5, '13:30-15:00');

-- Ресторан 7 (Дзержинского): ВТ, ПТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=7 AND legal_entity_group='BK_VM'), 2, '15:00-17:00'),
((SELECT id FROM restaurants WHERE number=7 AND legal_entity_group='BK_VM'), 5, '17:00-19:00');

-- Ресторан 8 (Уручье): ВТ, ПТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=8 AND legal_entity_group='BK_VM'), 2, '8:00-16:00'),
((SELECT id FROM restaurants WHERE number=8 AND legal_entity_group='BK_VM'), 5, '8:00-14:00');

-- Ресторан 12 (ЦУМ): ВТ, ПТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=12 AND legal_entity_group='BK_VM'), 2, '11:30-13:00'),
((SELECT id FROM restaurants WHERE number=12 AND legal_entity_group='BK_VM'), 5, '11:30-15:30');

-- Ресторан 14 (Галерея): ВТ, ПТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=14 AND legal_entity_group='BK_VM'), 2, 'к 8:00'),
((SELECT id FROM restaurants WHERE number=14 AND legal_entity_group='BK_VM'), 5, 'к 8:00');

-- Ресторан 16 (Дана Молл): ВТ, ЧТ, СБ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=16 AND legal_entity_group='BK_VM'), 2, '12:00-16:00'),
((SELECT id FROM restaurants WHERE number=16 AND legal_entity_group='BK_VM'), 4, '12:00-16:00'),
((SELECT id FROM restaurants WHERE number=16 AND legal_entity_group='BK_VM'), 6, '12:00-16:00');

-- Ресторан 17 (Каменногорская): ПН, СР, СБ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=17 AND legal_entity_group='BK_VM'), 1, '11:00-12:00'),
((SELECT id FROM restaurants WHERE number=17 AND legal_entity_group='BK_VM'), 3, '7:00-7:30'),
((SELECT id FROM restaurants WHERE number=17 AND legal_entity_group='BK_VM'), 6, '7:00-7:30');

-- Ресторан 20 (Партизанский): ПН, ЧТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=20 AND legal_entity_group='BK_VM'), 1, '9:00-14:00'),
((SELECT id FROM restaurants WHERE number=20 AND legal_entity_group='BK_VM'), 4, '9:00-14:00');

-- Ресторан 22 (Е-Сити): ПН, ЧТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=22 AND legal_entity_group='BK_VM'), 1, '12:00-16:00'),
((SELECT id FROM restaurants WHERE number=22 AND legal_entity_group='BK_VM'), 4, '12:00-16:00');

-- Ресторан 23 (Экспобел): ПН, ЧТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=23 AND legal_entity_group='BK_VM'), 1, 'к 7:30'),
((SELECT id FROM restaurants WHERE number=23 AND legal_entity_group='BK_VM'), 4, 'к 7:30');

-- Ресторан 26 (Палаццо): СР
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=26 AND legal_entity_group='BK_VM'), 3, '7:00-9:00');

-- Ресторан 27 (Риф): ПН, ЧТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=27 AND legal_entity_group='BK_VM'), 1, '9:00-12:00'),
((SELECT id FROM restaurants WHERE number=27 AND legal_entity_group='BK_VM'), 4, '9:00-12:00');

-- Ресторан 31 (Сузорье): СБ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=31 AND legal_entity_group='BK_VM'), 6, '10:00-17:00');

-- Ресторан 32 (Рига): СР, СБ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=32 AND legal_entity_group='BK_VM'), 3, '10:00-18:00'),
((SELECT id FROM restaurants WHERE number=32 AND legal_entity_group='BK_VM'), 6, '10:00-18:00');

-- Ресторан 33 (Охотник): СР
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=33 AND legal_entity_group='BK_VM'), 3, '9:30-14:00');

-- Ресторан 34 (А-100): ВТ, ПТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=34 AND legal_entity_group='BK_VM'), 2, '8:00-10:00'),
((SELECT id FROM restaurants WHERE number=34 AND legal_entity_group='BK_VM'), 5, '8:00-10:00');

-- Ресторан 41 (Грин): ВТ, ПТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=41 AND legal_entity_group='BK_VM'), 2, '12:00-16:00'),
((SELECT id FROM restaurants WHERE number=41 AND legal_entity_group='BK_VM'), 5, '12:00-16:00');

-- Ресторан 45 (Гиппо): ВТ, ПТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=45 AND legal_entity_group='BK_VM'), 2, '7:00-10:00'),
((SELECT id FROM restaurants WHERE number=45 AND legal_entity_group='BK_VM'), 5, '7:00-10:00');

-- Ресторан 49 (Кальварийская): ПТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=49 AND legal_entity_group='BK_VM'), 5, '7:00-7:30');

-- Ресторан 53 (Даймонд): СР
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=53 AND legal_entity_group='BK_VM'), 3, '9:00-13:00');

-- Ресторан 54 (Титан): ВТ, ПТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=54 AND legal_entity_group='BK_VM'), 2, '7:00-8:00'),
((SELECT id FROM restaurants WHERE number=54 AND legal_entity_group='BK_VM'), 5, '7:00-8:00');

-- Ресторан 55 (ГринСити): ПН, СР, ПТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=55 AND legal_entity_group='BK_VM'), 1, '11:00-15:00'),
((SELECT id FROM restaurants WHERE number=55 AND legal_entity_group='BK_VM'), 3, '11:00-15:00'),
((SELECT id FROM restaurants WHERE number=55 AND legal_entity_group='BK_VM'), 5, '11:00-15:00');

-- Ресторан 56 (Призма): ВТ, ПТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=56 AND legal_entity_group='BK_VM'), 2, '7:30-14:00'),
((SELECT id FROM restaurants WHERE number=56 AND legal_entity_group='BK_VM'), 5, '7:30-14:00');

-- Ресторан 57 (Аэровокзальная): ВТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=57 AND legal_entity_group='BK_VM'), 2, '13:30-15:00');

-- Ресторан 59 (СитиМолл): ВТ, ПТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=59 AND legal_entity_group='BK_VM'), 2, '7:00-10:00'),
((SELECT id FROM restaurants WHERE number=59 AND legal_entity_group='BK_VM'), 5, '7:00-10:00');

-- Ресторан 60 (Победителей): СБ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=60 AND legal_entity_group='BK_VM'), 6, '7:00-9:00');

-- Ресторан 61 (Тивали): ВТ, ПТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=61 AND legal_entity_group='BK_VM'), 2, '7:00-12:00'),
((SELECT id FROM restaurants WHERE number=61 AND legal_entity_group='BK_VM'), 5, '7:00-12:00');

-- ═══ РЕГИОНЫ ═══

-- Ресторан 15 (Гродно, Алми): СБ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=15 AND legal_entity_group='BK_VM'), 6, '8:00-16:00');

-- Ресторан 28 (Гродно, автовокзал): СР
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=28 AND legal_entity_group='BK_VM'), 3, '9:00-16:00');

-- Ресторан 10 (Гродно, Советская): СБ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=10 AND legal_entity_group='BK_VM'), 6, '9:00-16:00');

-- Ресторан 37 (Гродно, Тринити): СР, СБ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=37 AND legal_entity_group='BK_VM'), 3, '7:00-8:30'),
((SELECT id FROM restaurants WHERE number=37 AND legal_entity_group='BK_VM'), 6, '7:00-8:30');

-- Ресторан 48 (Гродно, Дубко): СР, СБ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=48 AND legal_entity_group='BK_VM'), 3, '7:00'),
((SELECT id FROM restaurants WHERE number=48 AND legal_entity_group='BK_VM'), 6, '7:00');

-- Ресторан 36 (Витебск, Чкалова): ВТ, ПТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=36 AND legal_entity_group='BK_VM'), 2, '10:00-14:00'),
((SELECT id FROM restaurants WHERE number=36 AND legal_entity_group='BK_VM'), 5, '10:00-14:00');

-- Ресторан 44 (Витебск, Строителей): ВТ, ПТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=44 AND legal_entity_group='BK_VM'), 2, 'к 7:00'),
((SELECT id FROM restaurants WHERE number=44 AND legal_entity_group='BK_VM'), 5, 'к 7:00');

-- Ресторан 21 (Витебск, Ленина): ВТ, ПТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=21 AND legal_entity_group='BK_VM'), 2, '7:30-10:00'),
((SELECT id FROM restaurants WHERE number=21 AND legal_entity_group='BK_VM'), 5, '7:30-10:00');

-- Ресторан 39 (Полоцк): ПТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=39 AND legal_entity_group='BK_VM'), 5, '10:00-18:00');

-- Ресторан 18 (Брест, Махновича): СР
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=18 AND legal_entity_group='BK_VM'), 3, '12:00-16:00');

-- Ресторан 13 (Брест, Советская): СР
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=13 AND legal_entity_group='BK_VM'), 3, '14:00-18:00');

-- Ресторан 58 (Пинск): СР
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=58 AND legal_entity_group='BK_VM'), 3, '10:00-15:00');

-- Ресторан 40 (Солигорск, Железнодорожная): ВТ, ПТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=40 AND legal_entity_group='BK_VM'), 2, '10:00-18:00'),
((SELECT id FROM restaurants WHERE number=40 AND legal_entity_group='BK_VM'), 5, '10:00-18:00');

-- Ресторан 50 (Солигорск, Кольцевая): ВТ, ПТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=50 AND legal_entity_group='BK_VM'), 2, '9:00-12:00'),
((SELECT id FROM restaurants WHERE number=50 AND legal_entity_group='BK_VM'), 5, '9:00-12:00');

-- Ресторан 24 (Могилев, Атриум): ПН, ЧТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=24 AND legal_entity_group='BK_VM'), 1, '12:00-18:00'),
((SELECT id FROM restaurants WHERE number=24 AND legal_entity_group='BK_VM'), 4, '10:00-18:00');

-- Ресторан 19 (Могилев, Пушкинский): ПН, ЧТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=19 AND legal_entity_group='BK_VM'), 1, '12:00-18:00'),
((SELECT id FROM restaurants WHERE number=19 AND legal_entity_group='BK_VM'), 4, '12:00-18:00');

-- Ресторан 52 (Могилев, Минское шоссе): ЧТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=52 AND legal_entity_group='BK_VM'), 4, 'к 8:00');

-- Ресторан 35 (Мозырь): ПТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=35 AND legal_entity_group='BK_VM'), 5, '10:00-18:00');

-- Ресторан 11 (Гомель, Привокзальная): ЧТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=11 AND legal_entity_group='BK_VM'), 4, '12:00-18:00');

-- Ресторан 42 (Гомель, Хатаевича): ЧТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=42 AND legal_entity_group='BK_VM'), 4, '12:00-18:00');

-- Ресторан 46 (Гомель, Речицкий): ВТ
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=46 AND legal_entity_group='BK_VM'), 2, '9:00-18:00');

-- Ресторан 43 (Жлобин): ПН
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=43 AND legal_entity_group='BK_VM'), 1, '10:00-15:00');

-- Ресторан 47 (Лида): СР
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=47 AND legal_entity_group='BK_VM'), 3, '9:00-12:00');

-- Ресторан 51 (Барановичи): СР
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=51 AND legal_entity_group='BK_VM'), 3, '7:00-12:00');

-- Ресторан 62 (Бобруйск): ПН
INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time) VALUES
((SELECT id FROM restaurants WHERE number=62 AND legal_entity_group='BK_VM'), 1, '6:30-10:30');
