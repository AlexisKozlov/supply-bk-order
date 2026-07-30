<template>
  <div class="ho">
    <!-- ═══ Список документов ═══ -->
    <template v-if="!current">
      <div class="ho-top">
        <div>
          <h1 class="page-title">Передача дел</h1>
          <p class="ho-sub">
            Документ на время отпуска или больничного. Приходы и заявки портал соберёт сам —
            останется вписать, кому что передаёте.
          </p>
        </div>
        <button class="ho-btn" @click="openCreate">+ Новый документ</button>
      </div>

      <div v-if="loading" class="ho-empty">Загрузка…</div>

      <div v-else-if="!docs.length" class="ho-empty">
        <div class="ho-empty-title">Документов пока нет</div>
        <p>Создайте первый — укажите даты отсутствия, остальное портал подготовит.</p>
        <button class="ho-btn" @click="openCreate">+ Новый документ</button>
      </div>

      <div v-else class="ho-cards">
        <article v-for="d in docs" :key="d.id" class="ho-card" @click="openDoc(d.id)">
          <div class="ho-card-head">
            <h3>{{ d.title }}</h3>
            <span class="ho-badge" :class="d.status === 'final' ? 'is-final' : ''">
              {{ d.status === 'final' ? 'готов' : 'черновик' }}
            </span>
          </div>
          <div class="ho-card-period">{{ fmt(d.date_from) }} — {{ fmt(d.date_to) }}</div>
          <div class="ho-card-meta">
            <span>{{ d.author_name || '—' }}</span>
            <span>·</span>
            <span>{{ d.supplier_count }} {{ plural(d.supplier_count, 'поставщик', 'поставщика', 'поставщиков') }}</span>
            <span>·</span>
            <span>{{ d.people_count }} {{ plural(d.people_count, 'человек', 'человека', 'человек') }}</span>
          </div>
        </article>
      </div>

      <!-- Создание -->
      <div v-if="showCreate" class="ho-modal-back" @click.self="askCloseCreate">
        <div class="ho-modal">
          <div class="ho-modal-head">
            <h3>Новый документ передачи дел</h3>
            <button class="ho-modal-x" @click="askCloseCreate">×</button>
          </div>
          <label class="ho-field">
            <span>Название</span>
            <input v-model="form.title" class="ho-input" placeholder="Передача дел на время отпуска" />
          </label>
          <div class="ho-field-row">
            <label class="ho-field">
              <span>Отсутствую с <b class="ho-req">*</b></span>
              <input v-model="form.date_from" type="date" class="ho-input" />
            </label>
            <label class="ho-field">
              <span>по <b class="ho-req">*</b></span>
              <input v-model="form.date_to" type="date" class="ho-input" />
            </label>
            <label class="ho-field">
              <span>Первый рабочий день</span>
              <input v-model="form.return_date" type="date" class="ho-input" />
            </label>
          </div>
          <label class="ho-field">
            <span>Экстренная связь</span>
            <input v-model="form.emergency_note" class="ho-input"
                   placeholder="телеграм @ник, будни 10:00–12:00, только по срочному" />
          </label>
          <p class="ho-hint">
            За выбранный период портал соберёт приходы поставщиков с позициями заказов
            по вашим юрлицам.
          </p>
          <div class="ho-modal-actions">
            <button class="ho-btn ho-btn-ghost" @click="askCloseCreate">Отмена</button>
            <button class="ho-btn" :disabled="creating || !form.date_from || !form.date_to" @click="createDoc">
              {{ creating ? 'Собираю…' : 'Создать' }}
            </button>
          </div>
        </div>
      </div>
    </template>

    <!-- ═══ Редактор документа ═══ -->
    <template v-else>
      <div class="ho-top">
        <div class="ho-top-left">
          <button class="ho-back" @click="closeDoc">‹ К списку</button>
          <input v-if="canEdit" v-model="current.doc.title" class="ho-title-input"
                 @change="saveDoc({ title: current.doc.title })" />
          <h1 v-else class="page-title">{{ current.doc.title }}</h1>
        </div>
        <div class="ho-actions">
          <!-- Статус виден и переключается здесь: раньше он всегда показывал
               «черновик», и было непонятно, где его менять. -->
          <button v-if="canEdit" class="ho-status-btn"
                  :class="current.doc.status === 'final' ? 'is-final' : 'is-draft'"
                  :title="current.doc.status === 'final' ? 'Вернуть в черновик' : 'Пометить готовым — дела переданы'"
                  @click="toggleStatus">
            {{ current.doc.status === 'final' ? 'Готов' : 'Черновик' }}
          </button>
          <button class="ho-btn ho-btn-ghost" :disabled="rebuilding" @click="rebuild">
            {{ rebuilding ? 'Собираю…' : 'Обновить приходы' }}
          </button>
          <button class="ho-btn" @click="downloadDocx">Скачать Word</button>
        </div>
      </div>

      <p v-if="saveNote" class="ho-savenote">{{ saveNote }}</p>

      <!-- Шапка -->
      <section class="ho-block">
        <h2 class="ho-h2">Общее</h2>
        <div class="ho-grid-head">
          <label class="ho-field">
            <span>Кто передаёт</span>
            <input v-model="current.doc.author_name" class="ho-input" :disabled="!canEdit"
                   @change="saveDoc({ author_name: current.doc.author_name })" />
          </label>
          <label class="ho-field">
            <span>Должность</span>
            <input v-model="current.doc.author_role" class="ho-input" :disabled="!canEdit"
                   @change="saveDoc({ author_role: current.doc.author_role })" />
          </label>
          <label class="ho-field">
            <span>Отсутствую с</span>
            <input v-model="current.doc.date_from" type="date" class="ho-input" :disabled="!canEdit"
                   @change="saveDoc({ date_from: current.doc.date_from })" />
          </label>
          <label class="ho-field">
            <span>по</span>
            <input v-model="current.doc.date_to" type="date" class="ho-input" :disabled="!canEdit"
                   @change="saveDoc({ date_to: current.doc.date_to })" />
          </label>
          <label class="ho-field">
            <span>Первый рабочий день</span>
            <input v-model="current.doc.return_date" type="date" class="ho-input" :disabled="!canEdit"
                   @change="saveDoc({ return_date: current.doc.return_date })" />
          </label>
          <label class="ho-field ho-field-wide">
            <span>Экстренная связь</span>
            <input v-model="current.doc.emergency_note" class="ho-input" :disabled="!canEdit"
                   placeholder="как и когда со мной можно связаться"
                   @change="saveDoc({ emergency_note: current.doc.emergency_note })" />
          </label>
        </div>
        <p v-if="periodChanged" class="ho-warn">
          Период изменился. Нажмите «Обновить приходы», чтобы пересобрать список поставок.
        </p>
      </section>

      <!-- Кто что принимает -->
      <section class="ho-block">
        <div class="ho-block-head">
          <h2 class="ho-h2">Кто что принимает</h2>
          <button v-if="canEdit" class="ho-btn ho-btn-sm" @click="addPerson">+ Человек</button>
        </div>
        <p class="ho-block-hint">Общая картина: кто отвечает за направление, пока вас нет.</p>

        <div v-if="!current.people.length" class="ho-mini-empty">Пока никого не добавили</div>
        <div v-else class="ho-rows">
          <div v-for="(p, pi) in current.people" :key="p.id"
               class="ho-row ho-row-people"
               :class="{ 'is-drag': dragOver.list === 'people' && dragOver.index === pi }"
               :draggable="canEdit"
               @dragstart="onDragStart('people', pi, $event)"
               @dragover.prevent="onDragOver('people', pi)"
               @dragleave="onDragLeave('people', pi)"
               @drop.prevent="onDrop('people', pi)"
               @dragend="onDragEnd">
            <span v-if="canEdit" class="ho-grip" title="Перетащите, чтобы поменять порядок">⠿</span>
            <textarea v-model="p.name" v-autogrow class="ho-input ho-ta" rows="1" placeholder="Фамилия Имя" :disabled="!canEdit"
                      @input="autoGrow($event.target)" @change="savePerson(p)"></textarea>
            <textarea v-model="p.zone" v-autogrow class="ho-input ho-ta" rows="1" placeholder="что принимает" :disabled="!canEdit"
                      @input="autoGrow($event.target)" @change="savePerson(p)"></textarea>
            <textarea v-model="p.scope" v-autogrow class="ho-input ho-ta" rows="1" placeholder="поставщики и темы" :disabled="!canEdit"
                      @input="autoGrow($event.target)" @change="savePerson(p)"></textarea>
            <textarea v-model="p.contact" v-autogrow class="ho-input ho-ta" rows="1" placeholder="телефон / телеграм" :disabled="!canEdit"
                      @input="autoGrow($event.target)" @change="savePerson(p)"></textarea>
            <button v-if="canEdit" class="ho-del" title="Удалить" @click="removePerson(p)">×</button>
          </div>
        </div>
      </section>

      <!-- Поставщики -->
      <section class="ho-block">
        <div class="ho-block-head">
          <h2 class="ho-h2">Приходы и поставщики</h2>
          <span class="ho-count">{{ includedSuppliers.length }} из {{ current.suppliers.length }}</span>
        </div>
        <p class="ho-block-hint">
          Собрано из заказов за период. Допишите сроки корректировки и на что обратить внимание —
          это и есть самое ценное для того, кто вас заменяет.
        </p>

        <div v-if="!current.suppliers.length" class="ho-mini-empty">
          За выбранный период заказов не нашлось. Проверьте даты и нажмите «Обновить приходы».
        </div>

        <div v-for="s in current.suppliers" :key="s.id" class="ho-sup" :class="{ 'is-off': !s.included }">
          <header class="ho-sup-head" @click="toggleSupplier(s.id)">
            <div class="ho-sup-name">
              <span class="ho-sup-caret" :class="{ 'is-open': openSuppliers.has(s.id) }">›</span>
              <b>{{ s.supplier_name }}</b>
              <span class="ho-sup-dates">{{ supplierDates(s) }}</span>
            </div>
            <div class="ho-sup-right">
              <span class="ho-sup-count">{{ supplierItemsCount(s) }} поз.</span>
              <label class="ho-check" @click.stop>
                <input type="checkbox" :checked="s.included" :disabled="!canEdit"
                       @change="saveSupplier(s, { included: $event.target.checked })" />
                <span>в документ</span>
              </label>
            </div>
          </header>

          <div v-if="openSuppliers.has(s.id)" class="ho-sup-body">
            <div class="ho-sup-grid">
              <label class="ho-field">
                <span>Кто ведёт в отсутствие</span>
                <select v-model="s.person_id" class="ho-input" :disabled="!canEdit"
                        @change="saveSupplier(s, { person_id: s.person_id })">
                  <option :value="null">— не назначен —</option>
                  <option v-for="p in current.people" :key="p.id" :value="p.id">{{ p.name || 'без имени' }}</option>
                </select>
              </label>
              <label class="ho-field ho-field-wide">
                <span>Контакты поставщика</span>
                <input v-model="s.contacts" class="ho-input" :disabled="!canEdit"
                       placeholder="имя, телефон, почта" @change="saveSupplier(s, { contacts: s.contacts })" />
              </label>
              <label class="ho-field ho-field-wide">
                <span>Срок корректировки заявки</span>
                <input v-model="s.correction_rule" class="ho-input" :disabled="!canEdit"
                       placeholder="за 3 рабочих дня, в вайбер менеджеру"
                       @change="saveSupplier(s, { correction_rule: s.correction_rule })" />
              </label>
              <label class="ho-field ho-field-wide">
                <span>Документы перед поставкой</span>
                <input v-model="s.docs_rule" class="ho-input" :disabled="!canEdit"
                       placeholder="номер машины и ТТН высылают на почту накануне"
                       @change="saveSupplier(s, { docs_rule: s.docs_rule })" />
              </label>
            </div>

            <label class="ho-field">
              <span>На что обратить внимание</span>
              <textarea v-model="s.attention" class="ho-input ho-textarea" rows="2" :disabled="!canEdit"
                        placeholder="менеджер в отпуске, ожидается рост цены, была недопоставка…"
                        @change="saveSupplier(s, { attention: s.attention })"></textarea>
            </label>

            <div v-for="(o, oi) in s.orders" :key="oi" class="ho-order">
              <div class="ho-order-head">
                <b>{{ fmt(o.date) }}</b>
                <span>{{ o.legal_entity }}</span>
              </div>
              <table v-if="o.items.length" class="ho-items">
                <tr v-for="(it, ii) in o.items" :key="ii">
                  <td class="ho-items-sku">{{ it.sku }}</td>
                  <td>{{ it.name }}</td>
                  <td class="ho-items-qty">{{ it.qty }}</td>
                </tr>
              </table>
              <div v-else class="ho-mini-empty">позиции не заполнены</div>
            </div>
          </div>
        </div>
      </section>

      <!-- Табличные разделы -->
      <section v-for="b in BLOCKS" :key="b.kind" class="ho-block">
        <div class="ho-block-head">
          <h2 class="ho-h2">{{ b.title }}</h2>
          <button v-if="canEdit && b.kind !== 'weekly'" class="ho-btn ho-btn-sm" @click="addItem(b.kind)">
            + Строка
          </button>
        </div>
        <p class="ho-block-hint">{{ b.hint }}</p>

        <div v-if="!itemsOf(b.kind).length" class="ho-mini-empty">Пусто — в документ раздел не попадёт</div>

        <div v-else class="ho-rows">
          <div class="ho-row ho-row-head" :style="gridStyle(b)">
            <span v-if="canEdit && b.kind !== 'weekly'"></span>
            <span v-for="(h, i) in b.headers" :key="i">{{ h }}</span>
            <span v-if="canEdit"></span>
          </div>
          <div v-for="(it, ii) in itemsOf(b.kind)" :key="it.id" class="ho-row"
               :style="gridStyle(b)"
               :class="{ 'is-drag': dragOver.list === b.kind && dragOver.index === ii }"
               :draggable="canEdit && b.kind !== 'weekly'"
               @dragstart="onDragStart(b.kind, ii, $event)"
               @dragover.prevent="onDragOver(b.kind, ii)"
               @dragleave="onDragLeave(b.kind, ii)"
               @drop.prevent="onDrop(b.kind, ii)"
               @dragend="onDragEnd">
            <span v-if="canEdit && b.kind !== 'weekly'" class="ho-grip" title="Перетащите, чтобы поменять порядок">⠿</span>
            <template v-for="(h, i) in b.headers" :key="i">
              <textarea v-if="!(b.kind === 'weekly' && i === 0)"
                        v-model="it['c' + (i + 1)]" v-autogrow class="ho-input ho-ta" rows="1"
                        :placeholder="b.holders[i] || ''" :disabled="!canEdit"
                        @input="autoGrow($event.target)" @change="saveItem(it)"></textarea>
              <span v-else class="ho-day">{{ it.c1 }}</span>
            </template>
            <button v-if="canEdit && b.kind !== 'weekly'" class="ho-del" title="Удалить" @click="removeItem(it)">×</button>
            <span v-else-if="canEdit"></span>
          </div>
        </div>
      </section>

      <div class="ho-foot">
        <button v-if="canEdit" class="ho-btn ho-btn-del" @click="removeDoc">Удалить документ</button>
      </div>
    </template>

    <ConfirmModal v-if="confirmModal.show"
                  :title="confirmModal.title"
                  :message="confirmModal.message"
                  :ok-text="confirmModal.okText"
                  :cancel-text="confirmModal.cancelText"
                  :danger="confirmModal.danger"
                  @confirm="onConfirm"
                  @cancel="onCancel" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick, defineAsyncComponent } from 'vue';
import { db } from '@/lib/apiClient.js';
import { useUserStore } from '@/stores/userStore.js';
import { useConfirm } from '@/composables/useConfirm.js';

const ConfirmModal = defineAsyncComponent(() => import('@/components/modals/ConfirmModal.vue'));

const userStore = useUserStore();
const { confirmModal, confirm, onConfirm, onCancel } = useConfirm();

const docs = ref([]);
const current = ref(null);
const loading = ref(true);
const creating = ref(false);
const rebuilding = ref(false);
const showCreate = ref(false);
const saveNote = ref('');
const openSuppliers = ref(new Set());
const periodAtLoad = ref('');

const form = ref({ title: '', date_from: '', date_to: '', return_date: '', emergency_note: '' });

// Разделы-таблицы: одинаковые по устройству, отличаются только колонками.
const BLOCKS = [
  {
    kind: 'weekly', title: 'Регулярные дела по дням недели',
    hint: 'Что повторяется каждую неделю: сбор заявок, дедлайны, отправка файлов. Чаще всего теряется именно это.',
    headers: ['День', 'Что нужно сделать', 'До какого времени', 'Кто отвечает'],
    holders: ['', 'например: собрать заявки по овощам', 'до 12:00', 'фамилия'],
    cols: '1.2fr 3fr 1.4fr 1.6fr',
  },
  {
    kind: 'topic', title: 'Отдельные темы',
    hint: 'То, что не привязано к одному поставщику: овощи, новинки, замены товаров.',
    headers: ['Тема', 'Порядок работы', 'Кто ведёт'],
    holders: ['например: новинки', 'что и в каком порядке делать', 'фамилия'],
    cols: '1.6fr 3fr 1.4fr',
  },
  {
    kind: 'payment', title: 'Оплаты, документы, растаможка',
    hint: 'Где деньги и бумаги: счета, акты, статдекларации, импортные поставки.',
    headers: ['Поставка / документ', 'Что сделать', 'Срок', 'Кто отвечает'],
    holders: ['например: Скандипакк, приход 09.08', 'поставить счёт на оплату', 'дата', 'фамилия'],
    cols: '1.8fr 2.4fr 1fr 1.4fr',
  },
  {
    kind: 'control', title: 'На контроле — незакрытые вопросы',
    hint: 'Что висит в работе и может «выстрелить», пока вас нет.',
    headers: ['Вопрос', 'Состояние', 'Что должно произойти', 'Кто ведёт', 'Когда напомнить'],
    holders: ['например: дефицит пакетов', 'остатков до 23.08', 'поставщик отгрузит партию', 'фамилия', 'с 18.08'],
    cols: '1.6fr 1.6fr 1.8fr 1.2fr 1.2fr',
  },
  {
    kind: 'escalate', title: 'К кому идти с вопросами',
    hint: 'Куда бежать, если что-то пошло не так.',
    headers: ['Вопрос', 'К кому', 'Контакт'],
    holders: ['например: спорная ситуация с поставщиком', 'фамилия', 'телефон / телеграм'],
    cols: '2fr 1.4fr 1.4fr',
  },
  {
    kind: 'file', title: 'Вложения к документу',
    hint: 'Файлы, которые отправите вместе с документом.',
    headers: ['Файл', 'Зачем нужен'],
    holders: ['название файла', 'для чего он'],
    cols: '1.6fr 2.4fr',
  },
];

const canEdit = computed(() => current.value?.can_edit !== false);
const includedSuppliers = computed(() => (current.value?.suppliers || []).filter(s => s.included));
const periodChanged = computed(() => {
  if (!current.value) return false;
  return periodAtLoad.value !== `${current.value.doc.date_from}|${current.value.doc.date_to}`;
});

/**
 * apiClient возвращает { data, error }. Оборачиваем, чтобы в коде страницы
 * работать с телом ответа и ловить ошибку одним местом.
 */
async function api(method, path, body = null) {
  const { data, error } = await db.request(method, path, body);
  if (error) throw new Error(error);
  if (data && data.error) throw new Error(data.error);
  return data;
}

function plural(n, one, few, many) {
  const a = Math.abs(n) % 100;
  const b = a % 10;
  if (a > 10 && a < 20) return many;
  if (b > 1 && b < 5) return few;
  if (b === 1) return one;
  return many;
}

function fmt(d) {
  if (!d) return '';
  const [y, m, day] = String(d).slice(0, 10).split('-');
  return `${day}.${m}.${y}`;
}

function gridStyle(b) {
  // Слева колонка под ручку перетаскивания, справа — под кнопку удаления.
  // У дней недели порядок фиксирован, ручки нет.
  const grip = canEdit.value && b.kind !== 'weekly' ? '18px ' : '';
  return { gridTemplateColumns: grip + b.cols + (canEdit.value ? ' 34px' : '') };
}

function itemsOf(kind) {
  return current.value?.items?.[kind] || [];
}

function supplierDates(s) {
  const dates = [...new Set((s.orders || []).map(o => fmt(o.date)))];
  return dates.length ? dates.join(', ') : 'нет приходов';
}

function supplierItemsCount(s) {
  return (s.orders || []).reduce((sum, o) => sum + (o.items?.length || 0), 0);
}

function toggleSupplier(id) {
  const set = new Set(openSuppliers.value);
  set.has(id) ? set.delete(id) : set.add(id);
  openSuppliers.value = set;
}

function flash(text) {
  saveNote.value = text;
  setTimeout(() => { if (saveNote.value === text) saveNote.value = ''; }, 2000);
}

// ─── Поля растут под текст ───
// Однострочные input прятали набранное: курсор уезжал вправо, и человек
// не видел, что пишет. Теперь это textarea, которая растёт по содержимому,
// а при желании её можно растянуть мышкой (resize: vertical).
function autoGrow(el) {
  if (!el || el.tagName !== 'TEXTAREA') return;
  el.style.height = 'auto';
  el.style.height = Math.min(el.scrollHeight, 400) + 'px';
}
/**
 * Директива вместо ручного обхода полей: подгоняет высоту сразу при
 * появлении поля и при каждом обновлении значения. Обход по классу
 * не срабатывал для уже заполненных полей — часть их в этот момент
 * ещё не была в разметке (свёрнутые карточки, другая вкладка).
 */
const vAutogrow = {
  mounted(el) { autoGrow(el); nextTick(() => autoGrow(el)); },
  updated(el) { autoGrow(el); },
};
function growAll() {
  nextTick(() => document.querySelectorAll('.ho-ta').forEach(autoGrow));
}

// ─── Перетаскивание строк ───
// Порядок строк важен: регулярные дела читают сверху вниз, поставщиков
// расставляют по важности. Тащим за строку, отпускаем на нужном месте.
const dragFrom = ref({ list: null, index: null });
const dragOver = ref({ list: null, index: null });

function listByKind(kind) {
  if (kind === 'people') return current.value?.people || [];
  return current.value?.items?.[kind] || [];
}

function onDragStart(kind, index, e) {
  dragFrom.value = { list: kind, index };
  if (e?.dataTransfer) {
    e.dataTransfer.effectAllowed = 'move';
    // Без данных Firefox не начинает перенос.
    try { e.dataTransfer.setData('text/plain', String(index)); } catch { /* старый браузер */ }
  }
}
function onDragOver(kind, index) {
  if (dragFrom.value.list !== kind) return;
  dragOver.value = { list: kind, index };
}
function onDragLeave(kind, index) {
  if (dragOver.value.list === kind && dragOver.value.index === index) {
    dragOver.value = { list: null, index: null };
  }
}
function onDragEnd() {
  dragFrom.value = { list: null, index: null };
  dragOver.value = { list: null, index: null };
}

async function onDrop(kind, index) {
  const from = dragFrom.value;
  onDragEnd();
  if (from.list !== kind || from.index === null || from.index === index) return;

  const list = listByKind(kind);
  const moved = list.splice(from.index, 1)[0];
  if (!moved) return;
  list.splice(index, 0, moved);

  // Порядок держим числами с шагом 10: между строками остаётся место,
  // если потом понадобится вставить одну без перенумерации всех.
  try {
    await Promise.all(list.map((row, i) => {
      row.sort_order = i * 10;
      const path = kind === 'people' ? `handover/people/${row.id}` : `handover/items/${row.id}`;
      return api('PATCH', path, { sort_order: row.sort_order });
    }));
    flash('Порядок сохранён');
  } catch (e) {
    flash(e?.message || 'Не удалось сохранить порядок');
  }
}

async function loadDocs() {
  loading.value = true;
  try {
    const r = await api('GET', 'handover/docs');
    docs.value = r?.docs || [];
  } finally {
    loading.value = false;
  }
}

function openCreate() {
  const today = new Date().toISOString().slice(0, 10);
  form.value = {
    title: '', date_from: today, date_to: today, return_date: '',
    emergency_note: '',
  };
  showCreate.value = true;
}

async function askCloseCreate() {
  const filled = form.value.title || form.value.emergency_note || form.value.return_date;
  if (filled && !(await confirm('Закрыть без сохранения?', 'Введённые данные не сохранятся.',
      { okText: 'Закрыть', cancelText: 'Остаться' }))) return;
  showCreate.value = false;
}

async function createDoc() {
  creating.value = true;
  try {
    const r = await api('POST', 'handover/docs', {
      ...form.value,
      author_name: userStore.user?.name || '',
      author_role: userStore.user?.display_role || '',
    });
    showCreate.value = false;
    await loadDocs();
    if (r?.doc) setCurrent(r.doc);
  } catch (e) {
    flash(e?.message || 'Не удалось создать документ');
  } finally {
    creating.value = false;
  }
}

function setCurrent(full) {
  current.value = full;
  periodAtLoad.value = `${full.doc.date_from}|${full.doc.date_to}`;
  openSuppliers.value = new Set();
  growAll();
}

async function openDoc(id) {
  const r = await api('GET', `handover/docs/${id}`);
  setCurrent(r);
}

function closeDoc() {
  current.value = null;
  loadDocs();
}

async function saveDoc(patch) {
  if (!canEdit.value) return;
  await api('PATCH', `handover/docs/${current.value.doc.id}`, patch);
  flash('Сохранено');
}

async function toggleStatus() {
  const next = current.value.doc.status === 'final' ? 'draft' : 'final';
  current.value.doc.status = next;
  await saveDoc({ status: next });
  flash(next === 'final' ? 'Документ помечен готовым' : 'Документ снова черновик');
}

async function rebuild() {
  rebuilding.value = true;
  try {
    const r = await api('POST', `handover/docs/${current.value.doc.id}/rebuild`);
    setCurrent(r.doc);
    flash(`Приходы обновлены: ${r.suppliers_found} ${plural(r.suppliers_found, 'поставщик', 'поставщика', 'поставщиков')}`);
  } finally {
    rebuilding.value = false;
  }
}

async function downloadDocx() {
  const id = current.value.doc.id;
  const r = await fetch(`/api/handover/docs/${id}/export`, {
    credentials: 'include',
    headers: { 'X-Session-Token': localStorage.getItem('bk_session_token') || '' },
  });
  if (!r.ok) { flash('Не удалось собрать файл'); return; }
  const blob = await r.blob();
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `${current.value.doc.title}.docx`;
  a.click();
  URL.revokeObjectURL(url);
}

async function addPerson() {
  const r = await api('POST', 'handover/people', { doc_id: current.value.doc.id, name: '' });
  current.value.people.push({ id: r.id, name: '', zone: '', scope: '', contact: '' });
  growAll();
}

async function savePerson(p) {
  await api('PATCH', `handover/people/${p.id}`, {
    name: p.name, zone: p.zone, scope: p.scope, contact: p.contact,
  });
  flash('Сохранено');
}

async function removePerson(p) {
  if (!(await confirm('Удалить строку?', p.name || 'Строка без имени',
      { okText: 'Удалить', danger: true }))) return;
  await api('DELETE', `handover/people/${p.id}`);
  current.value.people = current.value.people.filter(x => x.id !== p.id);
}

async function saveSupplier(s, patch) {
  Object.assign(s, patch);
  await api('PATCH', `handover/suppliers/${s.id}`, patch);
  flash('Сохранено');
}

async function addItem(kind) {
  const r = await api('POST', 'handover/items', { doc_id: current.value.doc.id, kind });
  if (!current.value.items[kind]) current.value.items[kind] = [];
  current.value.items[kind].push({ id: r.id, kind, c1: '', c2: '', c3: '', c4: '', c5: '' });
  growAll();
}

async function saveItem(it) {
  await api('PATCH', `handover/items/${it.id}`, {
    c1: it.c1 || '', c2: it.c2 || '', c3: it.c3 || '', c4: it.c4 || '', c5: it.c5 || '',
  });
  flash('Сохранено');
}

async function removeItem(it) {
  if (!(await confirm('Удалить строку?', it.c1 || 'Пустая строка',
      { okText: 'Удалить', danger: true }))) return;
  await api('DELETE', `handover/items/${it.id}`);
  current.value.items[it.kind] = current.value.items[it.kind].filter(x => x.id !== it.id);
}

async function removeDoc() {
  if (!(await confirm('Удалить документ?',
      'Вместе с ним удалятся все заполненные разделы. Отменить не получится.',
      { okText: 'Удалить', danger: true }))) return;
  await api('DELETE', `handover/docs/${current.value.doc.id}`);
  current.value = null;
  loadDocs();
}

onMounted(loadDocs);
</script>

<style scoped>
.ho { padding: 4px 0 40px; color: #2E1C10; }

.ho-top {
  display: flex; align-items: flex-start; justify-content: space-between;
  gap: 16px; flex-wrap: wrap; margin-bottom: 18px;
}
.ho-top-left { display: flex; align-items: center; gap: 12px; flex: 1 1 320px; min-width: 0; }
.ho-sub { margin: 4px 0 0; font-size: 13px; color: #8A7F72; max-width: 620px; line-height: 1.45; }
.ho-actions { display: flex; gap: 8px; flex-wrap: wrap; }

.ho-btn {
  padding: 9px 16px; border: 0; border-radius: 10px;
  background: linear-gradient(135deg, #E87A1E 0%, #D9661A 100%); color: #fff;
  font-size: 13.5px; font-weight: 700; cursor: pointer;
  box-shadow: 0 4px 12px rgba(232, 122, 30, .24);
  transition: filter .16s ease, transform .1s ease;
}
.ho-btn:hover:not(:disabled) { filter: brightness(1.05); }
.ho-btn:active:not(:disabled) { transform: translateY(1px); }
.ho-btn:disabled { opacity: .55; cursor: default; box-shadow: none; }
.ho-btn-ghost {
  background: #fff; color: #5F4B38; border: 1.5px solid #E4D9CB; box-shadow: none;
}
.ho-btn-ghost:hover:not(:disabled) { border-color: #E87A1E; color: #C25E12; }
.ho-btn-sm { padding: 6px 12px; font-size: 12.5px; }
.ho-btn-del { background: #fff; color: #C0392B; border: 1.5px solid #E9B4AF; box-shadow: none; }

.ho-back {
  padding: 6px 10px; border: 1.5px solid #E4D9CB; border-radius: 9px;
  background: #fff; color: #5F4B38; font-size: 13px; font-weight: 700; cursor: pointer;
}
.ho-back:hover { border-color: #E87A1E; color: #C25E12; }

.ho-title-input {
  flex: 1 1 auto; min-width: 0;
  padding: 6px 10px; border: 1.5px solid transparent; border-radius: 10px;
  background: transparent; font-size: 22px; font-weight: 800; color: #3A2418;
}
.ho-title-input:hover { border-color: #E4D9CB; }
.ho-title-input:focus { outline: 0; border-color: #E87A1E; background: #fff; }

/* ── Список ── */
.ho-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
.ho-card {
  padding: 14px 16px; border: 1.5px solid #E4D9CB; border-radius: 14px;
  background: #fff; cursor: pointer;
  transition: border-color .16s ease, box-shadow .16s ease, transform .1s ease;
}
.ho-card:hover { border-color: #E87A1E; box-shadow: 0 6px 18px rgba(74, 32, 19, .08); transform: translateY(-1px); }
.ho-card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.ho-card-head h3 { margin: 0; font-size: 15px; font-weight: 800; color: #3A2418; line-height: 1.3; }
.ho-card-period { margin-top: 6px; font-size: 13px; font-weight: 700; color: #C25E12; }
.ho-card-meta { margin-top: 6px; display: flex; gap: 6px; flex-wrap: wrap; font-size: 12px; color: #8A7F72; }
.ho-badge {
  flex: 0 0 auto; padding: 3px 8px; border-radius: 20px;
  background: #FBF6F0; color: #8A7F72; font-size: 11px; font-weight: 800;
}
.ho-badge.is-final { background: rgba(46, 139, 87, .12); color: #2E8B57; }

.ho-empty {
  padding: 40px 20px; border: 1.5px dashed #E4D9CB; border-radius: 14px;
  background: #FDFAF6; text-align: center; color: #8A7F72; font-size: 14px;
}
.ho-empty-title { font-size: 16px; font-weight: 800; color: #3A2418; margin-bottom: 6px; }
.ho-empty .ho-btn { margin-top: 14px; }
.ho-mini-empty {
  padding: 10px 12px; border-radius: 10px; background: #FBF6F0;
  font-size: 12.5px; color: #8A7F72;
}

/* ── Блоки ── */
.ho-block {
  margin-bottom: 16px; padding: 16px 18px;
  border: 1.5px solid #EFE7DC; border-radius: 14px; background: #fff;
}
.ho-block-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.ho-h2 { margin: 0; font-size: 15.5px; font-weight: 800; color: #4A2013; }
.ho-block-hint { margin: 4px 0 12px; font-size: 12.5px; color: #8A7F72; line-height: 1.45; }
.ho-count { font-size: 12.5px; font-weight: 700; color: #8A7F72; }
.ho-warn {
  margin: 10px 0 0; padding: 8px 12px; border-radius: 10px;
  background: #FFF4E8; color: #C25E12; font-size: 12.5px; font-weight: 600;
}
.ho-savenote { margin: -8px 0 12px; font-size: 12.5px; font-weight: 700; color: #2E8B57; }

.ho-grid-head { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; }
.ho-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.ho-field > span { font-size: 12px; font-weight: 700; color: #6B5544; }
.ho-field-wide { grid-column: span 2; }
.ho-field-row { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
.ho-req { color: #E87A1E; }
.ho-hint { margin: 10px 0 0; font-size: 12.5px; color: #8A7F72; line-height: 1.45; }

.ho-input {
  width: 100%; min-height: 38px; padding: 7px 10px;
  border: 1.5px solid #E4D9CB; border-radius: 9px; background: #fff;
  font: inherit; font-size: 13.5px; color: #2E1C10;
}
.ho-input:focus { outline: 0; border-color: #E87A1E; box-shadow: 0 0 0 3px rgba(232, 122, 30, .14); }
.ho-input:disabled { background: #FBF6F0; color: #8A7F72; }
.ho-textarea { min-height: 56px; resize: vertical; }

.ho-rows { display: flex; flex-direction: column; gap: 8px; }
.ho-row { display: grid; gap: 8px; align-items: center; }
.ho-row-people { grid-template-columns: 18px 1.4fr 1.4fr 2fr 1.4fr 34px; }
.ho-row-head {
  font-size: 11px; font-weight: 800; letter-spacing: .03em;
  text-transform: uppercase; color: #8A7F72; padding: 0 2px;
}
.ho-day { font-size: 13px; font-weight: 700; color: #5F4B38; }
.ho-del {
  width: 34px; height: 34px; border: 1.5px solid #E4D9CB; border-radius: 9px;
  background: #fff; color: #8A7F72; font-size: 18px; line-height: 1; cursor: pointer;
}
.ho-del:hover { border-color: #E9B4AF; color: #C0392B; background: #FFF1F0; }

/* ── Поставщики ── */
.ho-sup {
  margin-bottom: 8px; border: 1.5px solid #EFE7DC; border-radius: 12px;
  background: #FDFAF6; overflow: hidden;
}
.ho-sup.is-off { opacity: .6; }
.ho-sup-head {
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
  padding: 10px 12px; cursor: pointer;
}
.ho-sup-head:hover { background: #FBF6F0; }
.ho-sup-name { display: flex; align-items: center; gap: 8px; min-width: 0; font-size: 14px; }
.ho-sup-caret { color: #C25E12; font-size: 18px; transition: transform .16s ease; }
.ho-sup-caret.is-open { transform: rotate(90deg); }
.ho-sup-dates { font-size: 12px; color: #8A7F72; }
.ho-sup-right { display: flex; align-items: center; gap: 12px; flex: 0 0 auto; }
.ho-sup-count { font-size: 12px; font-weight: 700; color: #8A7F72; }
.ho-check { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #6B5544; cursor: pointer; }
.ho-sup-body { padding: 4px 12px 14px; border-top: 1.5px solid #EFE7DC; background: #fff; }
.ho-sup-grid {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 10px; margin: 12px 0;
}

.ho-order { margin-top: 12px; }
.ho-order-head {
  display: flex; gap: 10px; align-items: baseline;
  font-size: 12.5px; color: #6B5544; margin-bottom: 4px;
}
.ho-items { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.ho-items td { padding: 5px 8px; border-bottom: 1px solid #F2EAE0; text-align: left; }
.ho-items-sku { color: #C25E12; font-weight: 700; width: 90px; white-space: nowrap; }
.ho-items-qty { text-align: right; white-space: nowrap; font-weight: 700; }

.ho-foot { margin-top: 20px; }

/* ── Модалка ── */
.ho-modal-back {
  position: fixed; inset: 0; z-index: 100;
  background: rgba(46, 28, 16, .5); backdrop-filter: blur(2px);
  display: flex; align-items: center; justify-content: center; padding: 16px;
}
.ho-modal {
  width: 100%; max-width: 560px; max-height: 90vh; overflow: auto;
  padding: 18px 20px 20px; border-radius: 16px; background: #fff;
  display: flex; flex-direction: column; gap: 12px;
}
.ho-modal-head { display: flex; align-items: center; justify-content: space-between; }
.ho-modal-head h3 { margin: 0; font-size: 17px; font-weight: 800; color: #3A2418; }
.ho-modal-x {
  width: 32px; height: 32px; border: 0; border-radius: 8px;
  background: #FBF6F0; color: #6B5544; font-size: 20px; cursor: pointer;
}
.ho-modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 4px; }

@media (max-width: 760px) {
  /* На телефоне строка таблицы превращается в карточку: поля идут
     столбиком на всю ширину, кнопка удаления — в правом верхнем углу.
     В две колонки поля становились нечитаемо узкими. */
  .ho-row,
  .ho-row-people {
    position: relative;
    grid-template-columns: minmax(0, 1fr) !important;
    padding: 10px 46px 10px 10px;
    border: 1.5px solid #EFE7DC; border-radius: 12px; background: #FDFAF6;
  }
  .ho-row-head { display: none; }
  .ho-del { position: absolute; top: 8px; right: 8px; }
  .ho-day { font-size: 14px; font-weight: 800; color: #4A2013; }
  .ho-field-row { grid-template-columns: minmax(0, 1fr); }
  .ho-field-wide { grid-column: span 1; }
  .ho-block { padding: 14px; }
  .ho-top { gap: 10px; }
  .ho-top-left { flex-wrap: wrap; }
  /* Название документа — отдельной строкой: рядом с кнопкой «К списку»
     оно не помещается и уезжает за край экрана. */
  .ho-title-input { flex: 1 1 100%; font-size: 18px; padding: 8px 10px; border-color: #E4D9CB; }
  .ho-actions { width: 100%; }
  .ho-actions .ho-btn { flex: 1 1 0; }
  .ho-sup-head { flex-wrap: wrap; }
  .ho-sup-right { width: 100%; justify-content: space-between; }
  .ho-items-sku { width: 70px; }
}

/* Поля растут под текст и тянутся мышкой */
.ho-ta {
  resize: vertical; overflow: hidden; line-height: 1.35;
  min-height: 38px; padding-top: 9px; padding-bottom: 9px;
  font-family: inherit;
}
.ho-ta:focus { overflow: auto; }

/* Ручка перетаскивания */
.ho-grip {
  display: flex; align-items: center; justify-content: center;
  width: 18px; align-self: stretch; cursor: grab;
  color: #C4B8A8; font-size: 15px; line-height: 1; user-select: none;
}
.ho-grip:active { cursor: grabbing; }
.ho-row[draggable="true"]:hover .ho-grip { color: #E87A1E; }
/* Куда встанет строка при отпускании */
.ho-row.is-drag {
  outline: 2px dashed #E87A1E; outline-offset: 2px;
  background: rgba(232, 122, 30, .06);
}

/* Статус документа — кнопка-переключатель в шапке */
.ho-status-btn {
  padding: 8px 14px; border-radius: 20px; border: 1.5px solid transparent;
  font: inherit; font-size: 12.5px; font-weight: 800; cursor: pointer;
}
.ho-status-btn.is-draft { background: #F4EDE4; color: #6B5544; border-color: #E4D9CB; }
.ho-status-btn.is-draft:hover { border-color: #E87A1E; color: #C25E12; }
.ho-status-btn.is-final { background: rgba(46, 139, 87, .14); color: #2E7D32; border-color: #A5D6A7; }
</style>
