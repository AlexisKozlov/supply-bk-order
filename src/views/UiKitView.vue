<template>
  <div class="ui-kit">
    <header class="ui-kit-header">
      <h1>UI Kit — каталог дизайн-системы</h1>
      <p class="ui-kit-lede">
        Все компоненты и токены в одном месте. Доступ для admin'ов.
        Используется при разработке (визуальный контроль состояний) и для регрессионного QA
        после изменений в дизайн-системе. Если что-то на этой странице сломалось — значит
        в `tokens.css` или в одной из `Ui*` оболочек регрессия.
      </p>
      <nav class="ui-kit-nav">
        <a href="#palette">Палитра</a>
        <a href="#typography">Типографика</a>
        <a href="#spacing">Отступы и радиусы</a>
        <a href="#shadows">Тени</a>
        <a href="#empty">UiEmptyState</a>
        <a href="#skeleton">UiSkeleton</a>
        <a href="#popover">UiPopover</a>
        <a href="#menu">UiMenu</a>
      </nav>
    </header>

    <section id="palette" class="ui-kit-section">
      <h2>Палитра</h2>

      <h3>Нейтральная серая шкала (--tk-n-0..900)</h3>
      <div class="swatch-grid">
        <div v-for="n in NEUTRAL_SCALE" :key="n" class="swatch">
          <div class="swatch-color" :style="{ background: `var(--tk-n-${n})` }"></div>
          <div class="swatch-label">n-{{ n }}</div>
        </div>
      </div>

      <h3>Бренд + акцент</h3>
      <div class="swatch-grid">
        <div class="swatch">
          <div class="swatch-color" style="background: var(--tk-accent)"></div>
          <div class="swatch-label">accent</div>
        </div>
        <div class="swatch">
          <div class="swatch-color" style="background: var(--tk-accent-hover)"></div>
          <div class="swatch-label">accent-hover</div>
        </div>
        <div class="swatch">
          <div class="swatch-color" style="background: var(--tk-violet)"></div>
          <div class="swatch-label">violet</div>
        </div>
      </div>

      <h3>Палитра меток (8 фиксированных)</h3>
      <div class="swatch-grid">
        <div v-for="i in 8" :key="i" class="swatch">
          <div class="swatch-color" :style="{ background: `var(--tk-label-${i})` }"></div>
          <div class="swatch-label">label-{{ i }}</div>
        </div>
      </div>

      <h3>Приоритеты (фон + текст)</h3>
      <div class="prio-grid">
        <div v-for="p in PRIORITIES" :key="p" class="prio-chip"
             :style="{ background: `var(--tk-prio-${p}-bg)`, color: `var(--tk-prio-${p}-fg)` }">
          prio-{{ p }}
        </div>
      </div>

      <h3>Семантика (success / warning / danger / info)</h3>
      <div class="prio-grid">
        <div v-for="s in SEMANTICS" :key="s" class="prio-chip"
             :style="{ background: `var(--tk-${s}-soft)`, color: `var(--tk-${s})` }">
          {{ s }}
        </div>
      </div>
    </section>

    <section id="typography" class="ui-kit-section">
      <h2>Типографика</h2>
      <p class="ui-kit-section-lede">Шрифт Inter с системным fallback. Размеры по ступеням 11/12/13/14/16/18/20.</p>
      <div class="typo-grid">
        <div v-for="t in TYPO_SCALE" :key="t.token" class="typo-row">
          <div class="typo-sample" :style="{ fontSize: `var(--tk-fz-${t.token})`, fontWeight: t.weight || 'var(--tk-fw-regular)' }">
            Текст {{ t.label }} — собирать заказы быстрее
          </div>
          <div class="typo-meta">--tk-fz-{{ t.token }} · {{ t.label }}</div>
        </div>
      </div>
    </section>

    <section id="spacing" class="ui-kit-section">
      <h2>Отступы и радиусы</h2>
      <h3>Отступы (--tk-s-1..7)</h3>
      <div class="spacing-row">
        <div v-for="i in 7" :key="i" class="spacing-item">
          <div class="spacing-block" :style="{ width: `var(--tk-s-${i})`, height: `var(--tk-s-${i})` }"></div>
          <div class="spacing-label">s-{{ i }}</div>
        </div>
      </div>
      <h3>Радиусы</h3>
      <div class="radii-row">
        <div v-for="r in ['sm', 'md', 'lg', 'card', 'pill']" :key="r" class="radii-item">
          <div class="radii-block" :style="{ borderRadius: `var(--tk-r-${r})` }"></div>
          <div class="spacing-label">r-{{ r }}</div>
        </div>
      </div>
    </section>

    <section id="shadows" class="ui-kit-section">
      <h2>Тени</h2>
      <div class="shadow-row">
        <div v-for="s in ['card', 'card-hover', 'card-drag', 'column', 'popover', 'modal']" :key="s" class="shadow-item">
          <div class="shadow-block" :style="{ boxShadow: `var(--tk-shadow-${s})` }">shadow-{{ s }}</div>
        </div>
      </div>
    </section>

    <section id="empty" class="ui-kit-section">
      <h2>UiEmptyState</h2>
      <p class="ui-kit-section-lede">Единый шелл пустого состояния. Иконка через слот, действие через action-label или слот #action.</p>

      <div class="component-grid">
        <div class="component-frame">
          <div class="component-label">Базовый — только заголовок</div>
          <UiEmptyState title="Карточек пока нет"/>
        </div>

        <div class="component-frame">
          <div class="component-label">С описанием</div>
          <UiEmptyState title="Архив пуст" description="Сюда попадают завершённые карточки."/>
        </div>

        <div class="component-frame">
          <div class="component-label">С иконкой + действием</div>
          <UiEmptyState
            title="Нет задач"
            description="Создай первую — это займёт пару секунд."
            action-label="+ Создать карточку"
            @action="dummyClick">
            <template #icon><TaskIcon name="list" :size="48"/></template>
          </UiEmptyState>
        </div>

        <div class="component-frame">
          <div class="component-label">Ошибка с retry</div>
          <UiEmptyState
            title="Не удалось загрузить"
            description="Сервер не отвечает. Попробуй ещё раз."
            action-label="Повторить"
            @action="dummyClick">
            <template #icon><TaskIcon name="close" :size="48"/></template>
          </UiEmptyState>
        </div>
      </div>
    </section>

    <section id="skeleton" class="ui-kit-section">
      <h2>UiSkeleton</h2>
      <p class="ui-kit-section-lede">Скелетон-плейсхолдер с shimmer-анимацией. Уважает prefers-reduced-motion.</p>

      <div class="component-grid">
        <div class="component-frame">
          <div class="component-label">Прямоугольный — заголовок</div>
          <UiSkeleton width="60%" :height="20"/>
        </div>

        <div class="component-frame">
          <div class="component-label">Прямоугольный — параграф</div>
          <div style="display: flex; flex-direction: column; gap: 8px;">
            <UiSkeleton width="100%" :height="14"/>
            <UiSkeleton width="92%" :height="14"/>
            <UiSkeleton width="70%" :height="14"/>
          </div>
        </div>

        <div class="component-frame">
          <div class="component-label">Круглый — аватар</div>
          <UiSkeleton :width="48" :height="48" shape="circle"/>
        </div>

        <div class="component-frame">
          <div class="component-label">Пилюля — чип</div>
          <div style="display: flex; gap: 8px;">
            <UiSkeleton :width="80" :height="24" shape="pill"/>
            <UiSkeleton :width="100" :height="24" shape="pill"/>
          </div>
        </div>
      </div>
    </section>

    <section id="popover" class="ui-kit-section">
      <h2>UiPopover</h2>
      <p class="ui-kit-section-lede">Универсальная оболочка поповера. Родитель отвечает за позиционирование.</p>

      <div class="component-grid">
        <div class="component-frame popover-frame">
          <div class="component-label">Базовый (только контент)</div>
          <UiPopover>
            <div>Содержимое поповера — любой Vue-фрагмент.</div>
          </UiPopover>
        </div>

        <div class="component-frame popover-frame">
          <div class="component-label">С заголовком</div>
          <UiPopover title="Срок задачи">
            <div>Выбери дату из календаря или впиши вручную.</div>
          </UiPopover>
        </div>

        <div class="component-frame popover-frame">
          <div class="component-label">Полный: header + content + footer</div>
          <UiPopover title="Фильтр">
            <div>Условия фильтрации тут.</div>
            <template #footer>
              <button class="demo-btn-ghost">Сброс</button>
              <button class="demo-btn-primary">Применить</button>
            </template>
          </UiPopover>
        </div>
      </div>
    </section>

    <section id="menu" class="ui-kit-section">
      <h2>UiMenu</h2>
      <p class="ui-kit-section-lede">Выпадающее меню. Пункты через классы <code>.ui-menu__item</code>, <code>.ui-menu__divider</code>.</p>

      <div class="component-grid">
        <div class="component-frame popover-frame">
          <div class="component-label">Меню действий карточки</div>
          <UiMenu>
            <button class="ui-menu__item" @click="dummyClick">
              <TaskIcon name="plus" :size="14"/> Создать подзадачу
            </button>
            <button class="ui-menu__item" @click="dummyClick">
              <TaskIcon name="arrowUpRight" :size="14"/> Открыть задачу
            </button>
            <div class="ui-menu__divider"></div>
            <button class="ui-menu__item" @click="dummyClick">
              <TaskIcon name="copy" :size="14"/> Дублировать
            </button>
            <button class="ui-menu__item" @click="dummyClick">
              <TaskIcon name="archive" :size="14"/> Архивировать
            </button>
            <div class="ui-menu__divider"></div>
            <button class="ui-menu__item ui-menu__item--danger" @click="dummyClick">
              <TaskIcon name="trash" :size="14"/> Удалить карточку
            </button>
          </UiMenu>
        </div>

        <div class="component-frame popover-frame">
          <div class="component-label">С disabled-пунктом</div>
          <UiMenu>
            <button class="ui-menu__item" @click="dummyClick">
              <TaskIcon name="edit" :size="14"/> Редактировать
            </button>
            <button class="ui-menu__item" disabled>
              <TaskIcon name="archive" :size="14"/> Архивировать (нельзя)
            </button>
          </UiMenu>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import UiEmptyState from '@/components/ui/UiEmptyState.vue';
import UiSkeleton   from '@/components/ui/UiSkeleton.vue';
import UiPopover    from '@/components/ui/UiPopover.vue';
import UiMenu       from '@/components/ui/UiMenu.vue';
import TaskIcon     from '@/components/tasks/TaskIcon.vue';

const NEUTRAL_SCALE = [0, 50, 100, 200, 300, 400, 500, 600, 700, 800, 900];
const PRIORITIES = ['low', 'medium', 'high', 'urgent'];
const SEMANTICS  = ['success', 'warning', 'danger', 'info'];
const TYPO_SCALE = [
  { token: 'xs', label: '11px' },
  { token: 'sm', label: '12px' },
  { token: 'md', label: '13px' },
  { token: 'lg', label: '14px' },
  { token: 'xl', label: '16px' },
  { token: 'h1', label: '18px' },
  { token: 'h2', label: '20px', weight: 'var(--tk-fw-semibold)' },
];

function dummyClick() {
  console.log('UiKit demo action');
}
</script>

<style scoped>
.ui-kit {
  font-family: var(--tk-font);
  color: var(--tk-text);
  padding: var(--tk-s-5);
  max-width: 1100px;
  margin: 0 auto;
}

.ui-kit-header { margin-bottom: var(--tk-s-7); }
.ui-kit-header h1 {
  margin: 0 0 var(--tk-s-2);
  font-size: var(--tk-fz-h2);
  font-weight: var(--tk-fw-semibold);
}
.ui-kit-lede {
  margin: 0 0 var(--tk-s-3);
  color: var(--tk-text-secondary);
  font-size: var(--tk-fz-md);
  line-height: var(--tk-lh-base);
}
.ui-kit-nav {
  display: flex;
  flex-wrap: wrap;
  gap: var(--tk-s-2);
  padding: var(--tk-s-2) 0;
  border-top: 1px solid var(--tk-border-soft);
  border-bottom: 1px solid var(--tk-border-soft);
}
.ui-kit-nav a {
  font-size: var(--tk-fz-sm);
  color: var(--tk-accent-text);
  text-decoration: none;
  padding: var(--tk-s-1) var(--tk-s-2);
  border-radius: var(--tk-r-sm);
  transition: background var(--tk-transition-fast);
}
.ui-kit-nav a:hover { background: var(--tk-accent-soft); }

.ui-kit-section {
  margin-bottom: var(--tk-s-7);
  padding-bottom: var(--tk-s-5);
  border-bottom: 1px solid var(--tk-border-soft);
}
.ui-kit-section h2 {
  margin: 0 0 var(--tk-s-3);
  font-size: var(--tk-fz-h1);
  font-weight: var(--tk-fw-semibold);
}
.ui-kit-section h3 {
  margin: var(--tk-s-4) 0 var(--tk-s-2);
  font-size: var(--tk-fz-lg);
  font-weight: var(--tk-fw-semibold);
  color: var(--tk-text-secondary);
}
.ui-kit-section-lede {
  margin: 0 0 var(--tk-s-3);
  color: var(--tk-text-muted);
  font-size: var(--tk-fz-sm);
}

/* Палитра — quad swatches */
.swatch-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
  gap: var(--tk-s-2);
}
.swatch { text-align: center; }
.swatch-color {
  width: 100%;
  aspect-ratio: 1;
  border-radius: var(--tk-r-md);
  border: 1px solid var(--tk-border-soft);
}
.swatch-label {
  margin-top: var(--tk-s-1);
  font-size: var(--tk-fz-xs);
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  color: var(--tk-text-muted);
}

.prio-grid {
  display: flex;
  flex-wrap: wrap;
  gap: var(--tk-s-2);
}
.prio-chip {
  padding: var(--tk-s-1) var(--tk-s-3);
  border-radius: var(--tk-r-pill);
  font-size: var(--tk-fz-sm);
  font-weight: var(--tk-fw-semibold);
}

/* Типографика */
.typo-grid {
  display: flex;
  flex-direction: column;
  gap: var(--tk-s-3);
}
.typo-row {
  padding: var(--tk-s-2) var(--tk-s-3);
  background: var(--tk-n-50);
  border-radius: var(--tk-r-md);
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: var(--tk-s-4);
}
.typo-sample { color: var(--tk-text); }
.typo-meta {
  font-size: var(--tk-fz-xs);
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  color: var(--tk-text-muted);
  white-space: nowrap;
}

/* Отступы и радиусы */
.spacing-row, .radii-row {
  display: flex;
  align-items: flex-end;
  gap: var(--tk-s-4);
  flex-wrap: wrap;
}
.spacing-item, .radii-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--tk-s-1);
}
.spacing-block {
  background: var(--tk-accent);
  border-radius: var(--tk-r-sm);
}
.radii-block {
  width: 80px;
  height: 80px;
  background: var(--tk-accent-soft);
  border: 1px solid var(--tk-accent);
}
.spacing-label {
  font-size: var(--tk-fz-xs);
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  color: var(--tk-text-muted);
}

/* Тени */
.shadow-row {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: var(--tk-s-5);
  padding: var(--tk-s-3);
}
.shadow-item {
  background: var(--tk-bg-card);
  padding: var(--tk-s-4);
  border-radius: var(--tk-r-md);
}
.shadow-block {
  background: var(--tk-bg-card);
  padding: var(--tk-s-4);
  border-radius: var(--tk-r-md);
  text-align: center;
  font-size: var(--tk-fz-sm);
  color: var(--tk-text-muted);
}

/* Компонентные демо */
.component-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: var(--tk-s-4);
}
.component-frame {
  background: var(--tk-n-50);
  border: 1px solid var(--tk-border-soft);
  border-radius: var(--tk-r-md);
  padding: var(--tk-s-3);
  position: relative;
}
.component-label {
  font-size: var(--tk-fz-xs);
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  color: var(--tk-text-muted);
  margin-bottom: var(--tk-s-2);
}
.popover-frame {
  min-height: 200px;
  display: flex;
  flex-direction: column;
  gap: var(--tk-s-2);
}

/* Демо-кнопки в footer поповера */
.demo-btn-ghost, .demo-btn-primary {
  font-family: inherit;
  font-size: var(--tk-fz-sm);
  padding: var(--tk-s-1) var(--tk-s-3);
  border-radius: var(--tk-r-md);
  cursor: pointer;
  border: 1px solid var(--tk-border);
  background: var(--tk-n-0);
  color: var(--tk-text);
}
.demo-btn-primary {
  background: var(--tk-accent);
  border-color: var(--tk-accent);
  color: #FFFFFF;
}

code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 0.92em;
  background: var(--tk-n-100);
  padding: 1px 6px;
  border-radius: var(--tk-r-sm);
}
</style>
