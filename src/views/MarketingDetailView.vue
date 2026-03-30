<template>
  <div class="mktd-view">
    <!-- Шапка — как в тендерах -->
    <div class="td-header">
      <div class="td-header-left">
        <a class="td-back-link" @click.prevent="$router.push({ name: 'marketing' })"><BkIcon name="back" size="sm" /> Маркетинг</a>
        <h1 v-if="!editingName" class="td-title" @click="!isViewer && (editingName = true)">{{ activity.name || 'Без названия' }}</h1>
        <input v-else v-model="activity.name" class="td-title-input" @blur="editingName = false" @keydown.enter="editingName = false" ref="nameInput" />
        <span class="td-badge" :class="'type-' + activity.type">{{ typeLabel(activity.type) }}</span>
        <span class="td-badge" :class="activity.status === 'active' ? 'st-active' : 'st-completed'">{{ activity.status === 'active' ? 'Активная' : 'Завершённая' }}</span>
      </div>
      <div class="td-header-right">
        <button v-if="!isViewer && activity.id" class="td-btn td-btn-outline" @click="confirmDelete">Удалить</button>
        <button v-if="!isViewer" class="td-btn td-btn-primary" @click="save" :disabled="saving">{{ saving ? 'Сохранение...' : 'Сохранить' }}</button>
      </div>
    </div>

    <div v-if="loading" style="text-align:center;padding:40px;"><BurgerSpinner text="Загрузка..." /></div>

    <template v-else>
      <!-- Параметры — компактная полоса -->
      <div class="td-card">
        <div class="td-params-row">
          <div class="mktd-field">
            <label>Тип</label>
            <select v-model="activity.type" :disabled="isViewer" class="mktd-input">
              <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
          </div>
          <div class="mktd-field">
            <label>Статус</label>
            <select v-model="activity.status" :disabled="isViewer" class="mktd-input">
              <option value="active">Активная</option>
              <option value="completed">Завершённая</option>
            </select>
          </div>
          <div class="mktd-field">
            <label>Дата начала</label>
            <input type="date" v-model="activity.date_from" :disabled="isViewer" class="mktd-input" />
          </div>
          <div class="mktd-field">
            <label>Дата окончания</label>
            <input type="date" v-model="activity.date_to" :disabled="isViewer" class="mktd-input" />
          </div>
          <div class="mktd-field" style="flex:0 0 80px;">
            <label>Рестораны</label>
            <input type="number" v-model.number="activity.restaurant_count" :disabled="isViewer" class="mktd-input" placeholder="0" min="1" />
          </div>
          <div class="mktd-field" v-if="activityDays" style="flex:0 0 60px;">
            <label>Дней</label>
            <div class="mktd-info">{{ activityDays }}</div>
          </div>
          <div class="mktd-field" style="flex:2;">
            <label>Заметки</label>
            <input v-model="activity.note" :disabled="isViewer" class="mktd-input" placeholder="Комментарии..." />
          </div>
        </div>
      </div>

      <!-- Этапы подготовки -->
      <div class="td-card">
        <div class="mktd-card-title" style="justify-content:space-between;">
          <span>Этапы подготовки</span>
          <button v-if="!isViewer" class="td-btn td-btn-outline" style="font-size:11px;padding:4px 12px;" @click="addStage">+ Этап</button>
        </div>
        <div v-if="!activity.stages?.length" class="mktd-muted" style="text-align:center;padding:8px 0;font-size:12px;">
          Нет этапов.
          <a v-if="!isViewer" href="#" @click.prevent="initDefaultStages" style="color:var(--bk-orange);">Создать шаблон</a>
        </div>
        <div v-else class="mktd-stages">
          <div v-for="(stage, si) in activity.stages" :key="si" class="mktd-stage" :class="'st-' + stage.status">
            <div class="mktd-stage-status">
              <button v-if="!isViewer" class="mktd-stage-check" :class="{ done: stage.status === 'done', active: stage.status === 'in_progress' }"
                @click="cycleStageStatus(si)" :title="stageStatusLabel(stage.status)">
                <template v-if="stage.status === 'done'">✓</template>
                <template v-else-if="stage.status === 'in_progress'">●</template>
                <template v-else>○</template>
              </button>
              <span v-else class="mktd-stage-check" :class="{ done: stage.status === 'done', active: stage.status === 'in_progress' }">
                {{ stage.status === 'done' ? '✓' : stage.status === 'in_progress' ? '●' : '○' }}
              </span>
            </div>
            <div class="mktd-stage-body">
              <input v-if="!isViewer" class="mktd-stage-name" v-model="stage.name" placeholder="Название этапа" />
              <span v-else class="mktd-stage-name-ro">{{ stage.name }}</span>
            </div>
            <div class="mktd-stage-date">
              <input v-if="!isViewer" type="date" class="mktd-input mktd-input-sm" v-model="stage.deadline" style="width:130px;" />
              <span v-else style="font-size:12px;color:var(--text-muted);">{{ stage.deadline || '—' }}</span>
              <span v-if="stage.deadline && !stage.status !== 'done'" class="mktd-stage-days" :class="stageDaysClass(stage)">{{ stageDaysLabel(stage) }}</span>
            </div>
            <div class="mktd-stage-comment">
              <input v-if="!isViewer" class="mktd-input mktd-input-sm" v-model="stage.comment" placeholder="Комментарий..." style="flex:1;" />
              <span v-else style="font-size:11px;color:var(--text-muted);">{{ stage.comment || '' }}</span>
            </div>
            <button v-if="!isViewer" class="mktd-remove-btn" @click="activity.stages.splice(si, 1)" title="Удалить"><BkIcon name="close" size="xs" /></button>
          </div>
        </div>
      </div>

      <!-- Блюда / Ингредиенты -->
      <div class="mktd-card">
        <div class="mktd-card-title" style="justify-content:space-between;">
          <div style="display:flex;align-items:center;gap:8px;">
            <div class="mktd-tabs">
              <button class="mktd-tab" :class="{ active: itemsTab === 'dishes' }" @click="itemsTab = 'dishes'">Блюда <span v-if="activity.items.length" class="mktd-card-count">{{ activity.items.length }}</span></button>
              <button class="mktd-tab" :class="{ active: itemsTab === 'ingredients' }" @click="itemsTab = 'ingredients'; loadIngredients()">Ингредиенты <span v-if="ingredientsList.length" class="mktd-card-count">{{ ingredientsList.length }}</span></button>
            </div>
          </div>
          <div v-if="itemsTab === 'dishes'" style="display:flex;gap:6px;">
            <button v-if="!isViewer" class="btn small" @click="addItem">+ Блюдо</button>
          </div>
        </div>

        <!-- Таб: Блюда -->
        <div v-if="itemsTab === 'dishes'" class="mktd-items-wrap">
          <table class="mktd-items-table" v-if="activity.items.length">
            <thead>
              <tr>
                <th style="min-width:120px;text-align:left;">Блюдо</th>
                <th style="width:100px;">Метод</th>
                <th v-if="!hasMultipleMonths" style="width:90px;">AUV / кол-во</th>
                <th v-for="m in activityMonths" v-else :key="m.key" style="width:80px;" class="mktd-month-th">{{ m.label }}<div class="mktd-month-days">{{ m.days }} дн</div></th>
                <th style="width:70px;">Ед.</th>
                <th style="width:100px;">Итого</th>
                <th style="min-width:250px;">Заметка</th>
                <th style="width:36px;" v-if="!isViewer"></th>
              </tr>
            </thead>
            <tbody>
              <template v-for="(item, ii) in activity.items" :key="ii">
              <tr>
                <td style="text-align:left;position:relative;">
                  <input class="mktd-input mktd-item-name" v-model="item.name" :disabled="isViewer"
                    placeholder="Поиск блюда..."
                    @input="onItemSearch(ii, $event.target.value)"
                    @focus="onItemSearch(ii, item.name)"
                    @blur="closeSearch(ii)"
                    :ref="el => setItemRef(el, ii)" />
                  <span v-if="item.sku" class="mktd-item-sku">{{ item.sku }}</span>
                </td>
                <td>
                  <select v-model="item.calc_method" :disabled="isViewer" class="mktd-input mktd-input-sm">
                    <option value="auv">AUV</option>
                    <option value="category">Категория</option>
                    <option value="total_volume">Объём</option>
                    <option value="fixed_qty">Фикс.</option>
                  </select>
                </td>
                <!-- Один период или не AUV -->
                <td v-if="!hasMultipleMonths">
                  <input v-if="item.calc_method === 'auv' || item.calc_method === 'category'" type="number" v-model.number="item.auv" :disabled="isViewer" class="mktd-input mktd-input-sm" placeholder="шт/рест/день" step="0.01" />
                  <input v-else-if="item.calc_method === 'total_volume'" type="number" v-model.number="item.total_volume" :disabled="isViewer" class="mktd-input mktd-input-sm" placeholder="Объём" />
                  <input v-else type="number" v-model.number="item.fixed_qty" :disabled="isViewer" class="mktd-input mktd-input-sm" placeholder="Кол-во" />
                </td>
                <!-- Несколько месяцев — колонка на каждый (только для AUV) -->
                <template v-else>
                  <template v-if="item.calc_method === 'auv' || item.calc_method === 'category'">
                    <td v-for="m in activityMonths" :key="m.key">
                      <input type="number"
                        :value="getItemAuvForMonth(item, m.key)"
                        @change="setItemAuvForMonth(item, m.key, $event.target.value)"
                        :disabled="isViewer" class="mktd-input mktd-input-sm mktd-input-month" placeholder="AUV" step="0.01" />
                    </td>
                  </template>
                  <td v-else :colspan="activityMonths.length">
                    <input v-if="item.calc_method === 'total_volume'" type="number" v-model.number="item.total_volume" :disabled="isViewer" class="mktd-input mktd-input-sm" placeholder="Общий объём" />
                    <input v-else type="number" v-model.number="item.fixed_qty" :disabled="isViewer" class="mktd-input mktd-input-sm" placeholder="Фикс. кол-во" />
                  </td>
                </template>
                <td>
                  <select v-model="item.unit" :disabled="isViewer" class="mktd-input mktd-input-sm">
                    <option value="шт">шт</option>
                    <option value="кг">кг</option>
                    <option value="л">л</option>
                    <option value="кор">кор</option>
                    <option value="уп">уп</option>
                  </select>
                </td>
                <td class="mktd-total-cell">
                  <template v-if="itemTotal(item) > 0">
                    <strong>{{ formatNum(itemTotal(item)) }}</strong> {{ item.unit }}
                  </template>
                  <span v-else class="mktd-muted">—</span>
                </td>
                <td>
                  <input v-model="item.note" :disabled="isViewer" class="mktd-input mktd-input-sm" placeholder="" />
                </td>
                <td v-if="!isViewer" style="white-space:nowrap;">
                  <button v-if="ii > 0" class="mktd-move-btn" @click="moveItem(ii, -1)" title="Вверх">▲</button>
                  <button v-if="ii < activity.items.length - 1" class="mktd-move-btn" @click="moveItem(ii, 1)" title="Вниз">▼</button>
                  <button class="mktd-remove-btn" @click="removeItem(ii)" title="Удалить"><BkIcon name="close" size="xs" /></button>
                </td>
              </tr>
              <!-- Подблюда категории -->
              <tr v-if="item.calc_method === 'category'" class="mktd-sub-row">
                <td :colspan="colspanDishes" style="padding:6px 12px;">
                  <div class="mktd-sub-panel">
                    <div class="mktd-sub-header">
                      <div class="mktd-sub-chips">
                        <span v-for="(sub, si) in (item.sub_items || [])" :key="si" class="mktd-sub-chip">
                          <span class="mktd-sub-chip-name">{{ sub.name }}</span>
                          <span class="mktd-sub-chip-share">{{ Math.round((sub.share || 0) * 100) }}%</span>
                          <button v-if="!isViewer" class="mktd-sub-chip-x" @click="item.sub_items.splice(si, 1)">×</button>
                        </span>
                        <span v-if="!(item.sub_items || []).length" class="mktd-muted" style="font-size:11px;">Нет блюд в категории</span>
                      </div>
                      <div style="display:flex;gap:4px;flex-shrink:0;">
                        <button v-if="!isViewer" class="td-btn td-btn-outline" style="font-size:10px;padding:3px 10px;" @click="openSubModal(ii)">Выбрать блюда</button>
                        <button v-if="!isViewer && (item.sub_items || []).length >= 2" class="td-btn td-btn-outline" style="font-size:10px;padding:3px 10px;" @click="calcShares(ii)">Доли</button>
                      </div>
                    </div>
                  </div>
                </td>
              </tr>
              </template>
            </tbody>
          </table>
          <div class="mktd-add-btns">
            <button v-if="!isViewer" class="td-btn td-btn-outline" style="font-size:11px;padding:5px 14px;" @click="addItem">+ Блюдо</button>
            <button v-if="!isViewer" class="td-btn td-btn-outline" style="font-size:11px;padding:5px 14px;" @click="addCategoryItem">+ Категория</button>
          </div>
          <div v-if="!activity.items.length" class="mktd-muted" style="padding:16px 0;text-align:center;font-size:13px;">Добавьте блюда, по которым маркетинг планирует активность</div>
        </div>

        <!-- Таб: Ингредиенты (автоматический расклад) -->
        <div v-if="itemsTab === 'ingredients'">
          <div v-if="ingredientsLoading" style="text-align:center;padding:20px;"><BurgerSpinner text="Загрузка рецептур..." /></div>
          <div v-else-if="!activity.items.length" class="mktd-muted" style="padding:16px 0;text-align:center;font-size:13px;">Сначала добавьте блюда во вкладке «Блюда»</div>
          <div v-else-if="!ingredientsList.length" class="mktd-muted" style="padding:16px 0;text-align:center;font-size:13px;">Рецептуры не найдены. Импортируйте справочник рецептур.</div>
          <div v-else class="mktd-items-wrap">
            <div class="mktd-ing-toolbar">
              <div class="mktd-ing-info">
                Расклад: {{ matchedDishes }} из {{ activity.items.length }} блюд · {{ ingredientsList.length }} ингр.
                <span v-if="unmatchedDishes.length" class="mktd-ing-warn">· Не найдены: {{ unmatchedDishes.join(', ') }}</span>
              </div>
              <div class="mktd-ing-group-toggle">
                <button class="mktd-ing-gbtn" :class="{ active: ingGroupBy === 'supplier' }" @click="ingGroupBy = 'supplier'">По поставщикам</button>
                <button class="mktd-ing-gbtn" :class="{ active: ingGroupBy === 'dish' }" @click="ingGroupBy = 'dish'">По блюдам</button>
                <button class="mktd-ing-gbtn" :class="{ active: ingGroupBy === 'all' }" @click="ingGroupBy = 'all'">Все</button>
              </div>
            </div>
            <table class="mktd-items-table">
              <thead>
                <tr>
                  <th style="text-align:left;min-width:160px;">Ингредиент</th>
                  <th style="width:90px;">Артикул</th>
                  <th style="width:110px;">Поставщик</th>
                  <th style="width:80px;">Кг / Л</th>
                  <th style="width:70px;">Шт</th>
                  <th style="width:70px;">Кейсы</th>
                  <th style="width:120px;">Блюда</th>
                  <th style="min-width:180px;">Комментарий</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="([groupName, ings], gi) in ingredientsGrouped" :key="groupName">
                  <tr v-if="ingredientsGrouped.length > 1" class="mktd-ing-group-row">
                    <td colspan="8" class="mktd-ing-group-td">{{ groupName }} <span class="mktd-ing-group-cnt">{{ ings.length }}</span></td>
                  </tr>
                  <tr v-for="ing in ings" :key="ing.analogGroup || ing.name">
                    <td style="text-align:left;">
                      <span style="font-weight:600;">{{ ing.name }}</span>
                      <span v-if="ing.analogGroup && ing.skus.length > 1" class="mktd-muted" style="font-size:9px;margin-left:3px;">группа</span>
                    </td>
                    <td style="font-size:10px;">
                      <span v-for="s in ing.skus.slice(0,2)" :key="s" class="mktd-item-sku" style="position:static;display:inline-block;margin:1px;">{{ s }}</span>
                      <span v-if="ing.skus.length > 2" class="mktd-muted" style="font-size:9px;">+{{ ing.skus.length - 2 }}</span>
                    </td>
                    <td style="font-size:11px;">{{ ing.supplierOverride || ing.supplier || '—' }}</td>
                    <td class="mktd-total-cell">{{ ing.totalGrams > 0 ? formatNum(ing.totalGrams / 1000) : '—' }}</td>
                    <td class="mktd-total-cell">{{ ing.totalQty > 0 ? formatNum(ing.totalQty) : '—' }}</td>
                    <td class="mktd-total-cell">
                      <template v-if="ing.qtyPerBox === -1"><span class="mktd-muted" style="font-size:9px;">разн.</span></template>
                      <template v-else-if="ing.qtyPerBox > 0 && ing.totalQty > 0">{{ formatNum(Math.ceil(ing.totalQty / ing.qtyPerBox)) }}</template>
                      <template v-else-if="ing.qtyPerBox > 0 && ing.totalGrams > 0 && (ing.productUnit === 'кг' || ing.productUnit === 'л')">{{ formatNum(Math.ceil(ing.totalGrams / 1000 / ing.qtyPerBox)) }}</template>
                      <template v-else>—</template>
                    </td>
                    <td style="font-size:10px;color:var(--text-muted);">{{ ing.fromDishes.join(', ') }}</td>
                    <td @dblclick="startEditComment(ing)" style="cursor:pointer;">
                      <template v-if="editingComment === (ing.analogGroup || ing.name)">
                        <input class="mktd-input mktd-input-sm" v-model="ing.comment" @blur="editingComment = null" @keydown.enter="editingComment = null" style="width:100%;" />
                      </template>
                      <span v-else-if="ing.comment" style="font-size:11px;">{{ ing.comment }}</span>
                      <span v-else class="mktd-muted" style="font-size:10px;">—</span>
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Файлы — компактно -->
      <div class="mktd-card" style="padding:12px 20px;">
        <div class="mktd-files-row">
          <span style="font-weight:700;font-size:13px;color:var(--bk-brown);">Файлы</span>
          <div class="mktd-files-list">
            <span v-for="f in activity.files" :key="f.id" class="mktd-file-chip">
              <a :href="fileUrl(f)" target="_blank" class="mktd-file-link"><BkIcon name="export" size="xs" /> {{ f.file_name }}</a>
              <button v-if="!isViewer" class="mktd-remove-btn" @click.stop="deleteFile(f)" title="Удалить"><BkIcon name="close" size="xs" /></button>
            </span>
            <span v-if="!activity.files.length" class="mktd-muted" style="font-size:12px;">Нет вложений</span>
          </div>
          <label v-if="!isViewer && activity.id" class="btn small" style="flex-shrink:0;">
            <BkIcon name="import" size="sm" /> Загрузить
            <input type="file" style="display:none;" @change="uploadFile" accept=".pdf,.jpg,.jpeg,.png,.webp,.xlsx,.xls,.docx,.doc" />
            </label>
          <span v-if="uploading" style="font-size:11px;color:var(--text-muted);margin-left:4px;">Загрузка...</span>
        </div>
      </div>
    </template>

    <!-- Product search dropdown -->
    <Teleport to="body">
      <div v-if="search.index >= 0 && search.results.length" class="mktd-dropdown" :style="dropdownStyle" @mousedown.prevent>
        <div v-for="pr in search.results" :key="pr.id" class="mktd-dropdown-item" @mousedown.prevent="pickProduct(ii, pr)">
          <span class="mktd-dropdown-sku">{{ pr.sku }}</span> {{ pr.name }}
        </div>
      </div>
    </Teleport>

    <ConfirmModal v-if="confirmModal.show" :title="confirmModal.title" :message="confirmModal.message" @confirm="onConfirm" @cancel="onCancel" />

    <!-- Модалка выбора блюд для категории -->
    <Teleport to="body">
      <div v-if="subModal.show" class="mktd-modal-overlay" @click.self="subModal.show = false">
        <div class="mktd-modal">
          <div class="mktd-modal-header">
            <h3>Выбрать блюда в категорию</h3>
            <button class="mktd-remove-btn" @click="subModal.show = false" style="font-size:18px;">×</button>
          </div>
          <div class="mktd-modal-search">
            <input class="mktd-input" v-model="subModal.query" placeholder="Поиск блюда..." @input="onSubModalSearch" ref="subModalInput" />
          </div>
          <div class="mktd-modal-list">
            <label v-for="r in subModal.results" :key="r.id" class="mktd-modal-item" :class="{ selected: subModal.selected.has(r.id) }">
              <input type="checkbox" :checked="subModal.selected.has(r.id)" @change="toggleSubSelect(r)" />
              <span class="mktd-dropdown-sku">{{ r.code }}</span>
              <span>{{ r.name }}</span>
            </label>
            <div v-if="subModal.query.length >= 2 && !subModal.results.length && !subModal.loading" class="mktd-muted" style="padding:12px;text-align:center;">Ничего не найдено</div>
            <div v-if="subModal.loading" class="mktd-muted" style="padding:12px;text-align:center;">Поиск...</div>
            <div v-if="subModal.query.length < 2" class="mktd-muted" style="padding:12px;text-align:center;">Введите минимум 2 символа</div>
          </div>
          <div v-if="subModal.selected.size" class="mktd-modal-selected">
            Выбрано: {{ subModal.selected.size }}
            <span v-for="[id, r] in subModal.selected" :key="id" class="mktd-sub-chip" style="margin-left:4px;">
              {{ r.name }} <button class="mktd-sub-chip-x" @click="subModal.selected.delete(id)">×</button>
            </span>
          </div>
          <div class="mktd-modal-footer">
            <button class="td-btn td-btn-outline" @click="subModal.show = false">Отмена</button>
            <button class="td-btn td-btn-primary" @click="applySubModal" :disabled="!subModal.selected.size">Добавить ({{ subModal.selected.size }})</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { db } from '@/lib/apiClient.js';
import { applyEntityFilter } from '@/lib/utils.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';

const route = useRoute();
const router = useRouter();
const orderStore = useOrderStore();
const userStore = useUserStore();
const toast = useToastStore();

const isViewer = computed(() => !userStore.hasAccess('marketing', 'edit'));
const legalEntity = computed(() => orderStore.settings.legalEntity);

const loading = ref(false);
const saving = ref(false);
const uploading = ref(false);
const editingName = ref(false);
const nameInput = ref(null);
const itemsTab = ref('dishes');
const ingredientsLoading = ref(false);
const ingredientsData = ref([]); // raw recipe data from API
const editingSupplier = ref(null);
const editingComment = ref(null);
const ingGroupBy = ref('supplier'); // 'supplier' | 'dish' | 'all'

const types = [
  { value: 'promo', label: 'Промо' },
  { value: 'new_product', label: 'Новинка' },
  { value: 'discontinue', label: 'Вывод из меню' },
  { value: 'seasonal', label: 'Сезонное меню' },
  { value: 'coupon', label: 'Купон' },
];

function typeLabel(v) { return types.find(t => t.value === v)?.label || v; }

const activity = ref({
  id: null, name: '', type: 'promo', status: 'active',
  date_from: '', date_to: '', restaurant_count: null,
  legal_entity: '', note: '',
  items: [], files: [], stages: [],
});

const activityDays = computed(() => {
  if (!activity.value.date_from || !activity.value.date_to) return 0;
  const from = new Date(activity.value.date_from + 'T00:00:00');
  const to = new Date(activity.value.date_to + 'T00:00:00');
  return Math.max(Math.round((to - from) / 86400000) + 1, 0);
});

// Месяцы в рамках активности (для AUV по периодам)
const _monthNames = ['Янв','Фев','Мар','Апр','Май','Июн','Июл','Авг','Сен','Окт','Ноя','Дек'];
const activityMonths = computed(() => {
  if (!activity.value.date_from || !activity.value.date_to) return [];
  const from = new Date(activity.value.date_from + 'T00:00:00');
  const to = new Date(activity.value.date_to + 'T00:00:00');
  const months = [];
  const d = new Date(from.getFullYear(), from.getMonth(), 1);
  while (d <= to) {
    const mStart = new Date(Math.max(d, from));
    const mEndRaw = new Date(d.getFullYear(), d.getMonth() + 1, 0); // last day of month
    const mEnd = new Date(Math.min(mEndRaw, to));
    const days = Math.round((mEnd - mStart) / 86400000) + 1;
    const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    months.push({ key, label: `${_monthNames[d.getMonth()]} ${d.getFullYear()}`, days });
    d.setMonth(d.getMonth() + 1);
  }
  return months;
});
const hasMultipleMonths = computed(() => activityMonths.value.length > 1);

function getItemAuvForMonth(item, monthKey) {
  if (!item.auv_periods) return item.auv || 0;
  const found = item.auv_periods.find(p => p.month === monthKey);
  return found ? (found.auv || 0) : (item.auv || 0);
}

function setItemAuvForMonth(item, monthKey, val) {
  if (!item.auv_periods) item.auv_periods = activityMonths.value.map(m => ({ month: m.key, auv: item.auv || 0 }));
  const found = item.auv_periods.find(p => p.month === monthKey);
  if (found) found.auv = parseFloat(val) || 0;
  else item.auv_periods.push({ month: monthKey, auv: parseFloat(val) || 0 });
}

function itemTotal(item) {
  if (!item) return 0;
  const rests = activity.value.restaurant_count || 0;
  if (item.calc_method === 'auv' || item.calc_method === 'category') {
    if (hasMultipleMonths.value && item.auv_periods?.length) {
      // Сумма по месяцам: AUV_месяц × рестораны × дней_в_месяце
      return activityMonths.value.reduce((sum, m) => {
        const auv = getItemAuvForMonth(item, m.key);
        return sum + auv * rests * m.days;
      }, 0);
    }
    return (item.auv || 0) * rests * activityDays.value;
  }
  if (item.calc_method === 'total_volume') return item.total_volume || 0;
  return item.fixed_qty || 0;
}

const grandTotal = computed(() => activity.value.items.reduce((s, i) => i ? s + itemTotal(i) : s, 0));

function formatNum(v) {
  if (!v) return '—';
  return Number(v).toLocaleString('ru-RU', { maximumFractionDigits: 2 });
}

// ─── Этапы подготовки ────────────────────────────────────────────────────────
function addStage() {
  if (!activity.value.stages) activity.value.stages = [];
  activity.value.stages.push({ name: '', deadline: '', status: 'pending', comment: '' });
}

function initDefaultStages() {
  const startDate = activity.value.date_from ? new Date(activity.value.date_from + 'T00:00:00') : null;
  function offsetDate(days) {
    if (!startDate) return '';
    const d = new Date(startDate); d.setDate(d.getDate() - days);
    return d.toISOString().slice(0, 10);
  }
  activity.value.stages = [
    { name: 'Информация от маркетинга получена', deadline: '', status: 'done', comment: '' },
    { name: 'Поставщик определён / согласован', deadline: offsetDate(30), status: 'pending', comment: '' },
    { name: 'Заказ размещён у поставщика', deadline: offsetDate(21), status: 'pending', comment: '' },
    { name: 'Товар пришёл на склад', deadline: offsetDate(7), status: 'pending', comment: '' },
    { name: 'Распределено по ресторанам', deadline: offsetDate(3), status: 'pending', comment: '' },
    { name: 'Старт промо', deadline: activity.value.date_from || '', status: 'pending', comment: '' },
  ];
}

function cycleStageStatus(si) {
  const s = activity.value.stages[si];
  if (s.status === 'pending') s.status = 'in_progress';
  else if (s.status === 'in_progress') s.status = 'done';
  else s.status = 'pending';
}

function stageStatusLabel(st) {
  return st === 'done' ? 'Готово' : st === 'in_progress' ? 'В работе' : 'Не начат';
}

function stageDaysLabel(stage) {
  if (!stage.deadline || stage.status === 'done') return '';
  const d = new Date(stage.deadline + 'T00:00:00');
  const today = new Date(); today.setHours(0, 0, 0, 0);
  const diff = Math.round((d - today) / 86400000);
  if (diff < 0) return `${Math.abs(diff)} дн назад`;
  if (diff === 0) return 'сегодня';
  return `через ${diff} дн`;
}

function stageDaysClass(stage) {
  if (!stage.deadline || stage.status === 'done') return '';
  const d = new Date(stage.deadline + 'T00:00:00');
  const today = new Date(); today.setHours(0, 0, 0, 0);
  const diff = Math.round((d - today) / 86400000);
  if (diff < 0) return 'overdue';
  if (diff <= 3) return 'soon';
  return '';
}

// ─── Ингредиенты (расклад по рецептурам) ────────────────────────────────────
const matchedDishes = computed(() => {
  const names = activity.value.items.filter(Boolean).map(i => i.name);
  return ingredientsData.value.filter(r => names.includes(r.name)).length;
});
const unmatchedDishes = computed(() => {
  const matched = new Set(ingredientsData.value.map(r => r.name));
  return activity.value.items.filter(Boolean).map(i => i.name).filter(n => n && !matched.has(n));
});

const ingredientsList = computed(() => {
  const map = {}; // key → { name, skus, analogGroup, totalGrams, totalQty, qtyPerBox, fromDishes }
  const recipeMap = {};
  for (const r of ingredientsData.value) recipeMap[r.name] = r;

  for (const dish of activity.value.items) {
    if (!dish) continue;
    // Категория — раскладываем по sub_items
    if (dish.calc_method === 'category' && dish.sub_items?.length) {
      const totalPortions = itemTotal(dish);
      if (totalPortions <= 0) continue;
      for (const sub of dish.sub_items) {
        const recipe = recipeMap[sub.name];
        if (!recipe?.ingredients) continue;
        const subPortions = totalPortions * (sub.share || 0);
        if (subPortions <= 0) continue;
        for (const ing of recipe.ingredients) {
          const key = ing.analog_group || ing.sku || ing.name;
          if (!map[key]) { map[key] = { name: ing.analog_group || ing.name, analogGroup: ing.analog_group || null, skus: new Set(), originalSkus: new Set(), totalGrams: 0, totalQty: 0, qtyPerBox: ing.qty_per_box ? parseFloat(ing.qty_per_box) : null, productUnit: ing.product_unit || null, supplier: ing.product_supplier || null, supplierOverride: null, comment: '', fromDishes: [] }; }
          if (ing.sku) map[key].skus.add(ing.sku);
          if (ing.original_sku) map[key].originalSkus.add(ing.original_sku);
          if (ing.brutto) map[key].totalGrams += parseFloat(ing.brutto) * subPortions;
          if (ing.qty) map[key].totalQty += parseFloat(ing.qty) * subPortions;
          if (ing.product_supplier && !map[key].supplier) map[key].supplier = ing.product_supplier;
          if (ing.qty_per_box) { const qpb = parseFloat(ing.qty_per_box); if (map[key].qtyPerBox === null) map[key].qtyPerBox = qpb; else if (map[key].qtyPerBox !== qpb) map[key].qtyPerBox = -1; }
          if (!map[key].fromDishes.includes(dish.name + ' → ' + sub.name)) map[key].fromDishes.push(dish.name + ' → ' + sub.name);
        }
      }
      continue;
    }
    const recipe = recipeMap[dish.name];
    if (!recipe || !recipe.ingredients) continue;
    const portions = itemTotal(dish);
    if (portions <= 0) continue;

    for (const ing of recipe.ingredients) {
      // Группируем по analog_group, если есть; иначе по SKU/имени
      const key = ing.analog_group || ing.sku || ing.name;
      if (!map[key]) {
        map[key] = {
          name: ing.analog_group || ing.name,
          analogGroup: ing.analog_group || null,
          skus: new Set(),
          originalSkus: new Set(),
          totalGrams: 0, totalQty: 0,
          qtyPerBox: ing.qty_per_box ? parseFloat(ing.qty_per_box) : null,
          productUnit: ing.product_unit || null,
          supplier: ing.product_supplier || null,
          supplierOverride: null,
          comment: '',
          fromDishes: [],
        };
      }
      if (ing.product_supplier && !map[key].supplier) map[key].supplier = ing.product_supplier;
      if (ing.sku) map[key].skus.add(ing.sku);
      if (ing.original_sku) map[key].originalSkus.add(ing.original_sku);
      if (ing.brutto) map[key].totalGrams += parseFloat(ing.brutto) * portions;
      if (ing.qty) map[key].totalQty += parseFloat(ing.qty) * portions;
      // Запоминаем единицу измерения товара (кг, шт и т.д.)
      if (ing.product_unit && !map[key].productUnit) map[key].productUnit = ing.product_unit;
      // Если кейсовка отличается от уже записанной — обнуляем (неоднозначно)
      if (ing.qty_per_box) {
        const qpb = parseFloat(ing.qty_per_box);
        if (map[key].qtyPerBox === null) map[key].qtyPerBox = qpb;
        else if (map[key].qtyPerBox !== qpb) map[key].qtyPerBox = -1; // разная кейсовка
      }
      if (!map[key].fromDishes.includes(dish.name)) map[key].fromDishes.push(dish.name);
    }
  }

  return Object.values(map)
    .map(v => ({ ...v, skus: [...v.skus], originalSkus: [...v.originalSkus] }))
    .sort((a, b) => (b.totalGrams + b.totalQty) - (a.totalGrams + a.totalQty));
});

// Группировка ингредиентов по поставщикам
const ingredientsBySupplier = computed(() => {
  const groups = {};
  for (const ing of ingredientsList.value) {
    const sup = ing.supplierOverride || ing.supplier || 'Без поставщика';
    if (!groups[sup]) groups[sup] = [];
    groups[sup].push(ing);
  }
  // Сортируем: поставщики с названием первые, "Без поставщика" последний
  const sorted = Object.entries(groups).sort((a, b) => {
    if (a[0] === 'Без поставщика') return 1;
    if (b[0] === 'Без поставщика') return -1;
    return a[0].localeCompare(b[0], 'ru');
  });
  return sorted;
});

// Группировка ингредиентов по блюдам
const ingredientsByDish = computed(() => {
  const groups = {};
  for (const ing of ingredientsList.value) {
    for (const dish of ing.fromDishes) {
      if (!groups[dish]) groups[dish] = [];
      groups[dish].push(ing);
    }
  }
  return Object.entries(groups).sort((a, b) => a[0].localeCompare(b[0], 'ru'));
});

// Текущая группировка
const ingredientsGrouped = computed(() => {
  if (ingGroupBy.value === 'dish') return ingredientsByDish.value;
  if (ingGroupBy.value === 'supplier') return ingredientsBySupplier.value;
  return [['Все ингредиенты', ingredientsList.value]];
});

function startEditSupplier(ing) {
  if (isViewer.value) return;
  editingSupplier.value = ing.analogGroup || ing.name;
  if (!ing.supplierOverride) ing.supplierOverride = ing.supplier || '';
  nextTick(() => { const el = document.querySelector('.mktd-supplier-cell input'); if (el) { el.focus(); el.select(); } });
}
function startEditComment(ing) {
  if (isViewer.value) return;
  editingComment.value = ing.analogGroup || ing.name;
  nextTick(() => { const el = document.querySelector('.mktd-items-table td:last-child input'); if (el) { el.focus(); } });
}

async function loadIngredients() {
  // Собрать имена: обычные блюда + sub_items категорий
  const names = [];
  for (const item of activity.value.items) {
    if (!item) continue;
    if (item.calc_method === 'category' && item.sub_items?.length) {
      for (const sub of item.sub_items) { if (sub.name) names.push(sub.name); }
    } else { if (item.name) names.push(item.name); }
  }
  if (!names.length) return;
  // Only reload if dish names changed
  const cached = ingredientsData.value.map(r => r.name).sort().join(',');
  if (cached === names.sort().join(',') && ingredientsData.value.length) return;
  ingredientsLoading.value = true;
  try {
    const { data, error } = await db.rpc('get_recipe_ingredients', { dish_names: names });
    if (error) { toast.error('Ошибка', error); return; }
    ingredientsData.value = data?.recipes || [];
  } finally { ingredientsLoading.value = false; }
}

// ─── Items ──────────────────────────────────────────────────────────────────
const colspanDishes = computed(() => {
  const base = 7; // name + method + unit + total + note + move+remove
  if (hasMultipleMonths.value) return base + activityMonths.value.length;
  return base + 1; // + value column
});

function addItem() {
  activity.value.items.push({
    product_id: null, sku: null, name: '',
    calc_method: 'auv', auv: null, auv_periods: null, sub_items: null,
    total_volume: null, fixed_qty: null, unit: 'шт', note: '',
  });
}
function addCategoryItem() {
  activity.value.items.push({
    product_id: null, sku: null, name: '',
    calc_method: 'category', auv: null, auv_periods: null, sub_items: [],
    total_volume: null, fixed_qty: null, unit: 'шт', note: '',
  });
}
function removeItem(ii) { activity.value.items.splice(ii, 1); }
function moveItem(ii, dir) {
  const items = activity.value.items;
  const ni = ii + dir;
  if (ni < 0 || ni >= items.length) return;
  const moved = items.splice(ii, 1)[0];
  items.splice(ni, 0, moved);
}

function addSubItem(ii) {
  const item = activity.value.items[ii];
  if (!item.sub_items) item.sub_items = [];
  item.sub_items.push({ recipe_id: null, name: '', code: '', share: 0 });
}

async function calcShares(ii) {
  const item = activity.value.items[ii];
  if (!item.sub_items?.length) return;
  const recipeIds = item.sub_items.filter(s => s.recipe_id).map(s => s.recipe_id);
  if (recipeIds.length < 2) {
    // Если нет recipe_id — поровну
    const share = 1 / item.sub_items.length;
    item.sub_items.forEach(s => s.share = Math.round(share * 10000) / 10000);
    return;
  }
  const { data, error } = await db.rpc('calc_dish_shares', { recipe_ids: recipeIds });
  if (error) { toast.error('Ошибка', error); return; }
  const sharesMap = {};
  for (const s of (data?.shares || [])) sharesMap[s.recipe_id] = s.share;
  for (const sub of item.sub_items) {
    if (sub.recipe_id && sharesMap[sub.recipe_id] !== undefined) sub.share = sharesMap[sub.recipe_id];
  }
  toast.success('Доли рассчитаны', data?.total_sales > 0 ? 'По реализации' : 'Поровну (нет данных)');
}

// Модалка выбора блюд для категории
const subModal = reactive({ show: false, itemIdx: -1, query: '', results: [], selected: new Map(), loading: false, timer: null });
const subModalInput = ref(null);

function openSubModal(ii) {
  const item = activity.value.items[ii];
  subModal.itemIdx = ii;
  subModal.query = '';
  subModal.results = [];
  subModal.loading = false;
  // Pre-select existing sub_items
  subModal.selected = new Map();
  for (const sub of (item.sub_items || [])) {
    if (sub.recipe_id) subModal.selected.set(sub.recipe_id, { id: sub.recipe_id, name: sub.name, code: sub.code });
  }
  subModal.show = true;
  nextTick(() => { subModalInput.value?.focus(); });
}

function onSubModalSearch() {
  clearTimeout(subModal.timer);
  const q = subModal.query.trim();
  if (q.length < 2) { subModal.results = []; return; }
  subModal.loading = true;
  subModal.timer = setTimeout(async () => {
    const { data } = await db.from('recipes').select('id, code, name').ilike('name', `*${q}*`).order('name', { ascending: true }).limit(50);
    subModal.results = data || [];
    subModal.loading = false;
  }, 250);
}

function toggleSubSelect(recipe) {
  if (subModal.selected.has(recipe.id)) subModal.selected.delete(recipe.id);
  else subModal.selected.set(recipe.id, recipe);
}

function applySubModal() {
  const item = activity.value.items[subModal.itemIdx];
  if (!item) return;
  const existing = new Map((item.sub_items || []).map(s => [s.recipe_id, s]));
  const newSubs = [];
  for (const [id, r] of subModal.selected) {
    if (existing.has(id)) { newSubs.push(existing.get(id)); }
    else { newSubs.push({ recipe_id: id, name: r.name, code: r.code, share: 0 }); }
  }
  // Если доли не заданы — поровну
  if (newSubs.length && newSubs.every(s => !s.share)) {
    const share = Math.round(10000 / newSubs.length) / 10000;
    newSubs.forEach(s => s.share = share);
  }
  item.sub_items = newSubs;
  subModal.show = false;
}

// ─── Product search ─────────────────────────────────────────────────────────
const search = reactive({ index: -1, results: [], timer: null });
const itemInputRefs = {};
function setItemRef(el, i) { if (el) itemInputRefs[i] = el; }

const dropdownStyle = computed(() => {
  const el = itemInputRefs[search.index];
  if (!el) return { display: 'none' };
  const rect = el.getBoundingClientRect();
  return { position: 'fixed', top: rect.bottom + 'px', left: rect.left + 'px', width: Math.max(rect.width, 280) + 'px', zIndex: 99999 };
});

function onItemSearch(ii, val) {
  search.index = ii;
  clearTimeout(search.timer);
  const q = (val || '').trim();
  if (q.length < 2) { search.results = []; return; }
  search.timer = setTimeout(async () => {
    // Search recipes by name (use direct ilike filter on name column)
    const { data: recipes } = await db.from('recipes').select('id, code, name').ilike('name', `*${q}*`).order('name', { ascending: true }).limit(30);
    if (search.index === ii) search.results = (recipes || []).map(r => ({ id: r.id, sku: r.code, name: r.name, _type: 'recipe' }));
  }, 250);
}

function pickProduct(ii, pr) {
  const item = activity.value.items[search.index];
  if (!item) return;
  item.product_id = pr.id;
  item.sku = pr.sku;
  item.name = pr.name;
  if (pr.unit_of_measure) item.unit = pr.unit_of_measure;
  search.results = [];
  search.index = -1;
}

function closeSearch() {
  setTimeout(() => { search.results = []; search.index = -1; }, 200);
}

// ─── Files ──────────────────────────────────────────────────────────────────
const API_BASE = import.meta.env.VITE_API_BASE || '/api';

function fileUrl(f) {
  const token = localStorage.getItem('bk_session_token') || '';
  return `${API_BASE}/uploads/marketing/${f.file_path}?download=1&token=${token}`;
}

async function uploadFile(e) {
  const file = e.target.files?.[0];
  if (!file || !activity.value.id) return;
  uploading.value = true;
  try {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('activity_id', activity.value.id);
    const token = localStorage.getItem('bk_session_token') || '';
    const res = await fetch(`${API_BASE}/upload/marketing-file`, {
      method: 'POST', body: fd, headers: { 'X-Session-Token': token },
    });
    const data = await res.json();
    if (data.error) { toast.error('Ошибка', data.error); return; }
    activity.value.files.push({ id: data.id, file_name: data.file_name, file_path: data.file_path });
    toast.success('Файл загружен', data.file_name);
  } catch { toast.error('Ошибка загрузки', ''); }
  finally { uploading.value = false; e.target.value = ''; }
}

async function deleteFile(f) {
  const token = localStorage.getItem('bk_session_token') || '';
  const res = await fetch(`${API_BASE}/upload/marketing-file?file_id=${f.id}`, {
    method: 'DELETE', headers: { 'X-Session-Token': token },
  });
  const data = await res.json();
  if (data.success) {
    activity.value.files = activity.value.files.filter(x => x.id !== f.id);
    toast.info('Файл удалён', '');
  }
}

// ─── Save / Load / Delete ───────────────────────────────────────────────────
async function save() {
  if (!activity.value.name.trim()) { toast.error('Укажите название', ''); return; }
  saving.value = true;
  try {
    const payload = {
      id: activity.value.id || undefined,
      name: activity.value.name,
      type: activity.value.type,
      status: activity.value.status,
      date_from: activity.value.date_from || null,
      date_to: activity.value.date_to || null,
      legal_entity: activity.value.legal_entity || legalEntity.value,
      restaurant_count: activity.value.restaurant_count || null,
      note: activity.value.note || null,
      stages: activity.value.stages || null,
      items: activity.value.items.map((it, i) => ({
        product_id: it.product_id, sku: it.sku, name: it.name,
        calc_method: it.calc_method, auv: it.auv, auv_periods: it.auv_periods || null, sub_items: it.sub_items || null, total_volume: it.total_volume,
        fixed_qty: it.fixed_qty, unit: it.unit, note: it.note,
      })),
    };
    const { data, error } = await db.rpc('save_marketing_activity', payload);
    if (error) { toast.error('Ошибка', error); return; }
    if (!activity.value.id && data.id) {
      activity.value.id = data.id;
      activity.value.legal_entity = legalEntity.value;
      router.replace({ name: 'marketing-detail', params: { id: data.id } });
    }
    toast.success('Сохранено', '');
  } finally { saving.value = false; }
}

async function loadActivity(id) {
  loading.value = true;
  try {
    const { data, error } = await db.rpc('get_marketing_activity', { id: parseInt(id) });
    if (error || !data) { toast.error('Ошибка', error || 'Не найдена'); router.push({ name: 'marketing' }); return; }
    activity.value = {
      id: data.id, name: data.name, type: data.type, status: data.status,
      date_from: data.date_from || '', date_to: data.date_to || '',
      legal_entity: data.legal_entity, restaurant_count: data.restaurant_count,
      note: data.note || '',
      stages: data.stages ? (typeof data.stages === 'string' ? JSON.parse(data.stages) : data.stages) : [],
      items: (data.items || []).map(it => ({
        product_id: it.product_id, sku: it.sku, name: it.name,
        calc_method: it.calc_method || 'auv',
        auv: it.auv ? parseFloat(it.auv) : null,
        auv_periods: it.auv_periods ? (typeof it.auv_periods === 'string' ? JSON.parse(it.auv_periods) : it.auv_periods) : null,
        sub_items: it.sub_items ? (typeof it.sub_items === 'string' ? JSON.parse(it.sub_items) : it.sub_items) : null,
        total_volume: it.total_volume ? parseFloat(it.total_volume) : null,
        fixed_qty: it.fixed_qty ? parseFloat(it.fixed_qty) : null,
        unit: it.unit || 'шт', note: it.note || '',
      })),
      files: data.files || [],
    };
  } finally { loading.value = false; }
}

// ─── Confirm modal ──────────────────────────────────────────────────────────
const confirmModal = reactive({ show: false, title: '', message: '', action: null });
function confirmDelete() {
  confirmModal.show = true;
  confirmModal.title = 'Удалить активность?';
  confirmModal.message = `«${activity.value.name}» будет удалена вместе с файлами.`;
  confirmModal.action = 'delete';
}
async function onConfirm() {
  confirmModal.show = false;
  if (confirmModal.action === 'delete') {
    const { error } = await db.rpc('delete_marketing_activity', { id: activity.value.id });
    if (error) { toast.error('Ошибка', error); return; }
    toast.info('Удалено', '');
    router.push({ name: 'marketing' });
  }
}
function onCancel() { confirmModal.show = false; }

// ─── Ctrl+S ─────────────────────────────────────────────────────────────────
function onKeydown(e) {
  if ((e.ctrlKey || e.metaKey) && e.key === 's') {
    e.preventDefault();
    if (!isViewer.value && !saving.value) save();
  }
}

// ─── Mount ──────────────────────────────────────────────────────────────────
onMounted(() => {
  const id = route.params.id;
  if (id) {
    loadActivity(id);
  } else {
    activity.value.legal_entity = legalEntity.value;
  }
  document.addEventListener('keydown', onKeydown);
});
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown);
});
</script>

<style scoped>
.mktd-view { padding: 0; }

/* ─── Шапка — стиль тендеров ──────────────────────────────────────────── */
.td-header { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
.td-header-left { display:flex; align-items:center; gap:12px; flex:1; min-width:0; flex-wrap:wrap; }
.td-header-right { display:flex; gap:8px; flex-shrink:0; }
.td-back-link { display:inline-flex; align-items:center; gap:5px; font-size:13px; color:var(--text-muted); text-decoration:none; font-weight:500; cursor:pointer; transition:color .15s; }
.td-back-link:hover { color:var(--bk-brown); }
.td-title { font-size:22px; font-weight:800; color:var(--bk-brown); margin:0; cursor:pointer; transition:color .15s; }
.td-title:hover { color:var(--bk-orange); }
.td-title-input { font-size:22px; font-weight:800; color:var(--bk-brown); border:none; border-bottom:2px solid var(--bk-orange); outline:none; background:transparent; padding:0; font-family:inherit; width:300px; }
.td-badge { display:inline-block; padding:4px 12px; border-radius:20px; font-size:10px; font-weight:700; letter-spacing:0.3px; }
.td-badge.st-active { background:rgba(76,175,80,0.15); color:#2E7D32; }
.td-badge.st-completed { background:rgba(158,158,158,0.15); color:#757575; }
.td-badge.type-promo { background:#DBEAFE; color:#1D4ED8; }
.td-badge.type-new_product { background:#D1FAE5; color:#059669; }
.td-badge.type-discontinue { background:#FEE2E2; color:#DC2626; }
.td-badge.type-seasonal { background:#FEF3C7; color:#D97706; }
.td-badge.type-coupon { background:#EDE9FE; color:#7C3AED; }
.td-btn { padding:8px 20px; border-radius:8px; font-size:13px; font-weight:600; border:none; cursor:pointer; font-family:inherit; transition:all .15s; }
.td-btn-primary { background:#D62300; color:white; }
.td-btn-primary:hover { background:#B91D00; }
.td-btn-primary:disabled { opacity:0.5; cursor:default; }
.td-btn-outline { background:white; border:1.5px solid #D4C4B0; color:var(--bk-brown); }
.td-btn-outline:hover { border-color:#8B7355; background:#FEFBF7; }

/* ─── Карточки ────────────────────────────────────────────────────────── */
.td-card { background:white; border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,0.06); padding:16px 20px; margin-bottom:16px; }
.td-params-row { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
.mktd-card { background:white; border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,0.06); padding:20px 24px; margin-bottom:16px; }
.mktd-card-title { font-weight:700; font-size:14px; color:var(--bk-brown, #502314); margin-bottom:14px; display:flex; align-items:center; gap:8px; padding-bottom:10px; border-bottom:2px solid #E8E0D8; }
.mktd-card-count { font-size:11px; background:var(--bk-orange); color:#fff; padding:2px 8px; border-radius:10px; font-weight:700; }

/* ─── Форма ───────────────────────────────────────────────────────────── */
.mktd-field { flex:1; min-width:100px; }
.mktd-field label { display:block; font-size:10px; font-weight:700; color:var(--bk-brown, #502314); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.4px; opacity:0.5; }
.mktd-input { width:100%; padding:7px 10px; border:1.5px solid #D4C4B0; border-radius:8px; font-size:13px; font-family:inherit; background:white; color:var(--text); box-sizing:border-box; transition:border-color .15s; }
.mktd-input:focus { border-color:var(--bk-orange); outline:none; box-shadow:0 0 0 3px rgba(214,35,0,0.08); }
.mktd-input:disabled { opacity:0.6; background:#F5F0EB; }
.mktd-info { font-size:15px; font-weight:700; padding:7px 0; color:var(--bk-brown, #502314); }

/* Items table */
.mktd-items-wrap { overflow-x: auto; }
.mktd-items-table { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
.mktd-items-table th { font-size: 10px; text-transform: uppercase; letter-spacing: 0.3px; color: var(--bk-brown, #502314); font-weight: 700; padding: 8px 10px; border-bottom: 2px solid var(--bk-orange, #D62300); white-space: nowrap; background: #FFF8F0; }
.mktd-items-table td { padding: 7px 10px; border-bottom: 1px solid #EDE8E3; vertical-align: middle; overflow: hidden; text-overflow: ellipsis; }
.mktd-items-table tbody tr:hover { background: #FEFCF9; }
.mktd-items-table th:first-child, .mktd-items-table td:first-child { text-align: left; }
.mktd-items-table th:not(:first-child), .mktd-items-table td:not(:first-child) { text-align: center; }
.mktd-input-sm { padding: 6px 8px; font-size: 12px; min-height: 30px; }
select.mktd-input, select.mktd-input-sm { background: #fff; color: var(--text); appearance: auto; cursor: pointer; }
.mktd-item-name { padding-right: 55px !important; }
.mktd-item-sku { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); font-size: 10px; font-weight: 800; color: var(--bk-orange); background: rgba(214,35,0,0.06); padding: 2px 6px; border-radius: 4px; }
.mktd-total-cell { font-weight: 700; color: var(--bk-brown, #502314); font-size: 13px; }
.mktd-remove-btn { background: none; border: none; cursor: pointer; color: #ccc; padding: 4px; border-radius: 6px; transition: all 0.15s; }
.mktd-remove-btn:hover { color: #D62300; background: rgba(214,35,0,0.08); }
.mktd-muted { color: var(--text-muted); }

/* Files */
.mktd-files-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.mktd-files-list { display: flex; gap: 6px; flex-wrap: wrap; flex: 1; align-items: center; }
.mktd-file-chip { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; background: #FAFAF8; border: 1px solid #E8E0D8; border-radius: 6px; font-size: 12px; }
.mktd-file-chip:hover { border-color: var(--bk-orange); }
.mktd-file-link { color: var(--text); text-decoration: none; display: flex; align-items: center; gap: 3px; font-weight: 500; }
.mktd-file-link:hover { color: var(--bk-orange); }

/* Dropdown */
.mktd-dropdown { background: white; border: 1px solid #E8E0D8; border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,0.12); max-height: 220px; overflow-y: auto; }
.mktd-dropdown-item { padding: 10px 14px; cursor: pointer; font-size: 13px; border-bottom: 1px solid #F5F0EB; transition: background 0.1s; }
.mktd-dropdown-item:last-child { border-bottom: none; }
.mktd-dropdown-item:hover { background: #FFF3E0; }
.mktd-dropdown-sku { font-weight: 800; color: var(--bk-orange); margin-right: 6px; }

/* Tabs */
.mktd-tabs { display: flex; gap: 0; }
.mktd-tab { padding: 6px 16px; border: 1.5px solid #E8E0D8; background: #FAFAF8; font-size: 12px; font-weight: 700; font-family: inherit; color: var(--text-muted); cursor: pointer; transition: all 0.15s; display: flex; align-items: center; gap: 6px; }
.mktd-tab:first-child { border-radius: 8px 0 0 8px; }
.mktd-tab:last-child { border-radius: 0 8px 8px 0; margin-left: -1px; }
.mktd-tab.active { background: var(--bk-brown, #502314); color: #fff; border-color: var(--bk-brown, #502314); }
.mktd-tab.active .mktd-card-count { background: rgba(255,255,255,0.3); }

/* Ingredients info */
.mktd-month-th { font-size: 10px !important; line-height: 1.3; }
.mktd-month-days { font-size: 9px; font-weight: 500; opacity: 0.6; }
.mktd-input-month { width: 65px; text-align: center; font-weight: 600; }
/* ─── Этапы ───────────────────────────────────────────────────────────── */
.mktd-stages { display: flex; flex-direction: column; gap: 2px; }
.mktd-stage { display: flex; align-items: center; gap: 10px; padding: 8px 4px; border-radius: 8px; transition: background 0.1s; }
.mktd-stage:hover { background: rgba(0,0,0,0.02); }
.mktd-stage.st-done { opacity: 0.5; }
.mktd-stage-status { flex-shrink: 0; width: 28px; text-align: center; }
.mktd-stage-check { width: 24px; height: 24px; border-radius: 50%; border: 2px solid #D4C4B0; background: white; cursor: pointer; font-size: 13px; display: inline-flex; align-items: center; justify-content: center; transition: all 0.15s; color: #D4C4B0; }
.mktd-stage-check.done { background: #4CAF50; border-color: #4CAF50; color: white; }
.mktd-stage-check.active { background: #FFF3E0; border-color: var(--bk-orange); color: var(--bk-orange); }
button.mktd-stage-check:hover { transform: scale(1.1); }
.mktd-stage-body { flex: 1; min-width: 0; }
.mktd-stage-name { border: none; background: transparent; font-size: 13px; font-weight: 600; color: var(--text); font-family: inherit; padding: 2px 0; width: 100%; outline: none; }
.mktd-stage-name:focus { border-bottom: 1px solid var(--bk-orange); }
.mktd-stage-name-ro { font-size: 13px; font-weight: 600; color: var(--text); }
.mktd-stage-date { display: flex; align-items: center; gap: 6px; flex: 0 0 220px; }
.mktd-stage-days { font-size: 10px; font-weight: 600; white-space: nowrap; }
.mktd-stage-days.overdue { color: #D62300; }
.mktd-stage-days.soon { color: #D97706; }
.mktd-stage-comment { flex: 1; min-width: 100px; }

/* ─── Категории / sub-items ────────────────────────────────────────────── */
.mktd-sub-row td { background: #FAFAF8 !important; }
.mktd-sub-panel { padding: 2px 0; }
.mktd-sub-header { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
.mktd-sub-chips { display: flex; flex-wrap: wrap; gap: 4px; flex: 1; align-items: center; }
.mktd-sub-chip { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; background: #E8F5E9; border: 1px solid #A5D6A7; border-radius: 6px; font-size: 11px; font-weight: 500; color: #2E7D32; }
.mktd-sub-chip-name { max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mktd-sub-chip-share { font-weight: 700; color: #1B5E20; font-size: 10px; }
.mktd-sub-chip-x { background: none; border: none; cursor: pointer; color: #66BB6A; font-size: 14px; line-height: 1; padding: 0 2px; }
.mktd-sub-chip-x:hover { color: #D62300; }
.mktd-add-btns { display: flex; gap: 6px; margin-top: 8px; }
.mktd-move-btn { background: none; border: none; cursor: pointer; color: #ccc; font-size: 10px; padding: 1px 3px; line-height: 1; }
.mktd-move-btn:hover { color: var(--bk-brown); }

/* Modal */
.mktd-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 10000; display: flex; align-items: center; justify-content: center; }
.mktd-modal { background: white; border-radius: 14px; box-shadow: 0 8px 32px rgba(0,0,0,0.2); width: 560px; max-width: 95vw; max-height: 80vh; display: flex; flex-direction: column; }
.mktd-modal-header { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px 12px; border-bottom: 1px solid #E8E0D8; }
.mktd-modal-header h3 { margin: 0; font-size: 16px; font-weight: 700; color: var(--bk-brown); }
.mktd-modal-search { padding: 12px 20px 8px; }
.mktd-modal-list { flex: 1; overflow-y: auto; padding: 0 20px; max-height: 320px; }
.mktd-modal-item { display: flex; align-items: center; gap: 8px; padding: 8px 6px; border-bottom: 1px solid #F5F0EB; cursor: pointer; font-size: 13px; transition: background 0.1s; }
.mktd-modal-item:hover { background: #FFFBF5; }
.mktd-modal-item.selected { background: #E8F5E9; }
.mktd-modal-item input[type="checkbox"] { accent-color: #4CAF50; }
.mktd-modal-selected { padding: 8px 20px; border-top: 1px solid #E8E0D8; font-size: 12px; font-weight: 600; color: var(--text-muted); display: flex; flex-wrap: wrap; align-items: center; gap: 4px; }
.mktd-modal-footer { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 20px; border-top: 1px solid #E8E0D8; }

/* Ingredient toolbar & grouping */
.mktd-ing-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
.mktd-ing-group-toggle { display: flex; gap: 0; }
.mktd-ing-gbtn { padding: 4px 12px; border: 1.5px solid #D4C4B0; background: white; font-size: 10px; font-weight: 600; font-family: inherit; color: var(--text-muted); cursor: pointer; transition: all .15s; }
.mktd-ing-gbtn:first-child { border-radius: 6px 0 0 6px; }
.mktd-ing-gbtn:last-child { border-radius: 0 6px 6px 0; margin-left: -1px; }
.mktd-ing-gbtn:not(:first-child):not(:last-child) { margin-left: -1px; }
.mktd-ing-gbtn.active { background: var(--bk-brown, #502314); color: #fff; border-color: var(--bk-brown, #502314); }
.mktd-ing-group-row td { background: none !important; border-bottom: none !important; padding-top: 14px !important; }
.mktd-ing-group-td { font-weight: 700; font-size: 13px; color: var(--bk-brown, #502314); border-bottom: 2px solid var(--bk-orange) !important; padding-bottom: 4px !important; }
.mktd-ing-group-cnt { font-size: 10px; font-weight: 600; color: var(--text-muted); margin-left: 4px; }

.mktd-supplier-cell { cursor: pointer; }
.mktd-supplier-cell:hover { background: rgba(214,35,0,0.03); }
.mktd-ing-group td { background: #FFFBF5 !important; }
.mktd-ing-info { font-size: 12px; color: var(--text-muted); padding: 8px 0 12px; }
.mktd-ing-warn { color: #D97706; font-weight: 600; }

@media (max-width: 600px) {
  .mktd-card { padding: 16px; border-radius: 10px; }
  .mktd-row { flex-direction: column; gap: 10px; }
  .mktd-field { min-width: 100%; }
}
</style>
