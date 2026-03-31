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
      <!-- Параметры — теги-пилюли -->
      <div class="mktd-c-params">
        <select v-model="activity.type" :disabled="isViewer" class="mktd-c-tag"><option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option></select>
        <select v-model="activity.status" :disabled="isViewer" class="mktd-c-tag"><option value="active">Активная</option><option value="completed">Завершённая</option></select>
        <input type="date" v-model="activity.date_from" :disabled="isViewer" class="mktd-c-tag" />
        <span class="mktd-c-sep">—</span>
        <input type="date" v-model="activity.date_to" :disabled="isViewer" class="mktd-c-tag" />
        <input type="number" v-model.number="activity.restaurant_count" :disabled="isViewer" class="mktd-c-tag" style="width:55px;" :placeholder="defaultRestCount" min="1" :title="'По умолчанию: ' + defaultRestCount" />
        <span v-if="activityDays" class="mktd-c-tag mktd-c-tag-ro">{{ activityDays }} дн</span>
        <input v-model="activity.note" :disabled="isViewer" class="mktd-c-tag" style="flex:1;min-width:120px;" placeholder="Заметки..." />
      </div>

      <!-- Блюда — карточки с раскрытием -->
      <div class="mktd-c-dishes">
        <div v-for="(item, ii) in activity.items" :key="ii" class="mktd-c-dish" :class="{ open: expandedDishC === ii }">
          <div class="mktd-c-dish-head" @click="expandedDishC = expandedDishC === ii ? -1 : ii; if (expandedDishC === ii) loadIngredients()">
            <span class="mktd-c-dish-name">{{ item.name || 'Блюдо ' + (ii+1) }}</span>
            <span class="mktd-c-dish-meta">{{ item.calc_method === 'category' ? 'Категория' : item.calc_method === 'auv' ? 'AUV ' + dishAuvDisplay(item) : item.calc_method === 'total_volume' ? 'Объём' : 'Фикс.' }}</span>
            <span v-if="itemTotal(item) > 0" class="mktd-c-dish-total">{{ formatNum(itemTotal(item)) }} {{ item.unit }}</span>
            <span v-if="item.note" class="mktd-c-dish-note">{{ item.note }}</span>
            <BkIcon :name="expandedDishC === ii ? 'chevronUp' : 'chevronDown'" size="xs" style="color:var(--text-muted);flex-shrink:0;" />
          </div>
          <!-- Раскрытие: параметры + ингредиенты -->
          <div v-if="expandedDishC === ii" class="mktd-c-dish-body" @click.stop>
            <div class="td-params-row" style="margin-bottom:12px;">
              <div class="mktd-field" style="flex:2;">
                <label>Название</label>
                <input class="mktd-input mktd-input-sm" v-model="item.name" :disabled="isViewer"
                  @input="onItemSearch(ii, $event.target.value)" @blur="closeSearch()" :ref="el => setItemRef(el, ii)" />
              </div>
              <div class="mktd-field" style="flex:0 0 100px;">
                <label>Метод</label>
                <select v-model="item.calc_method" :disabled="isViewer" class="mktd-input mktd-input-sm">
                  <option value="auv">AUV</option><option value="category">Категория</option><option value="total_volume">Объём</option><option value="fixed_qty">Фикс.</option>
                </select>
              </div>
              <template v-if="item.calc_method === 'auv' || item.calc_method === 'category'">
                <div v-if="!hasMultipleMonths" class="mktd-field" style="flex:0 0 90px;">
                  <label>AUV</label>
                  <input type="number" v-model.number="item.auv" :disabled="isViewer" class="mktd-input mktd-input-sm" step="0.01" placeholder="шт/рест/день" />
                </div>
                <div v-else v-for="m in activityMonths" :key="m.key" class="mktd-field" style="flex:0 0 80px;">
                  <label>{{ m.label }}</label>
                  <input type="number" :value="getItemAuvForMonth(item, m.key)" @change="setItemAuvForMonth(item, m.key, $event.target.value)" :disabled="isViewer" class="mktd-input mktd-input-sm" step="0.01" placeholder="AUV" />
                </div>
              </template>
              <div v-else-if="item.calc_method === 'total_volume'" class="mktd-field" style="flex:0 0 90px;">
                <label>Объём</label>
                <input type="number" v-model.number="item.total_volume" :disabled="isViewer" class="mktd-input mktd-input-sm" />
              </div>
              <div v-else class="mktd-field" style="flex:0 0 90px;">
                <label>Кол-во</label>
                <input type="number" v-model.number="item.fixed_qty" :disabled="isViewer" class="mktd-input mktd-input-sm" />
              </div>
              <div class="mktd-field" style="flex:0 0 60px;">
                <label>Ед.</label>
                <select v-model="item.unit" :disabled="isViewer" class="mktd-input mktd-input-sm"><option value="шт">шт</option><option value="кг">кг</option><option value="л">л</option><option value="кор">кор</option></select>
              </div>
              <div class="mktd-field" style="flex:1;">
                <label>Заметка</label>
                <input v-model="item.note" :disabled="isViewer" class="mktd-input mktd-input-sm" />
              </div>
              <div class="mktd-field" style="flex:0 0 auto;align-self:flex-end;">
                <div style="display:flex;gap:3px;">
                  <button v-if="ii > 0" class="td-btn td-btn-outline" style="font-size:9px;padding:3px 6px;" @click="moveItem(ii,-1)">▲</button>
                  <button v-if="ii < activity.items.length-1" class="td-btn td-btn-outline" style="font-size:9px;padding:3px 6px;" @click="moveItem(ii,1)">▼</button>
                  <button class="td-btn td-btn-outline" style="font-size:9px;padding:3px 6px;color:#D62300;" @click="removeItem(ii); expandedDishC=-1">Удалить</button>
                </div>
              </div>
            </div>
            <!-- Категория: подблюда -->
            <div v-if="item.calc_method === 'category'" style="margin-bottom:12px;">
              <div class="mktd-sub-header">
                <span style="font-size:11px;font-weight:600;">Блюда в категории</span>
                <div style="display:flex;gap:4px;">
                  <button v-if="!isViewer" class="td-btn td-btn-outline" style="font-size:9px;padding:2px 8px;" @click="openSubModal(ii)">Выбрать</button>
                  <button v-if="!isViewer && (item.sub_items || []).length >= 2" class="td-btn td-btn-outline" style="font-size:9px;padding:2px 8px;" @click="calcShares(ii)">Доли</button>
                </div>
              </div>
              <div class="mktd-sub-chips" style="margin-top:4px;">
                <span v-for="(sub, si) in (item.sub_items || [])" :key="si" class="mktd-sub-chip">
                  <span class="mktd-sub-chip-name">{{ sub.name }}</span>
                  <span class="mktd-sub-chip-share">{{ sub.qty > 1 ? '×' + sub.qty : Math.round((sub.share||0)*100) + '%' }}</span>
                  <button v-if="!isViewer" class="mktd-sub-chip-x" @click="item.sub_items.splice(si,1)">×</button>
                </span>
              </div>
            </div>
            <!-- Ингредиенты блюда -->
            <table class="mktd-dish-ing-table" v-if="dishIngredients(ii).length">
              <thead>
                <tr>
                  <th style="text-align:left;">Ингредиент</th>
                  <th style="width:70px;">Арт.</th>
                  <th style="width:80px;">Кг/Л</th>
                  <th style="width:70px;">Шт</th>
                  <th style="width:60px;">Кейсы</th>
                  <th style="width:100px;">Поставщик</th>
                  <th style="width:120px;">Заметка</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="ing in dishIngredients(ii)" :key="ing.name">
                  <td style="text-align:left;font-weight:500;">
                    {{ ing.name }}
                    <span v-if="ing.originalSku" class="mktd-c-dish-ing-old">{{ ing.originalSku }}</span>
                  </td>
                  <td><span v-if="ing.skus?.length" class="mktd-c-dish-ing-sku">{{ ing.skus[0] }}</span></td>
                  <td class="mktd-total-cell">{{ ing.totalGrams > 0 ? formatNum(ing.totalGrams / 1000) : '—' }}</td>
                  <td class="mktd-total-cell">{{ ing.totalQty > 0 ? formatNum(ing.totalQty) : '—' }}</td>
                  <td class="mktd-total-cell">{{ ingCases(ing) || '—' }}</td>
                  <td style="font-size:10px;color:var(--text-muted);">{{ ing.supplier || '—' }}</td>
                  <td @dblclick="startIngComment(ing)">
                    <template v-if="editIngComment === (ing.analogGroup || ing.name)">
                      <input class="mktd-input mktd-input-sm" v-model="ing._comment" @blur="editIngComment = null" @keydown.enter="editIngComment = null" style="width:100%;" />
                    </template>
                    <span v-else style="font-size:10px;color:var(--text-muted);cursor:pointer;">{{ ing._comment || '—' }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
            <div v-else class="mktd-muted" style="font-size:11px;padding:6px;">Рецептура не найдена</div>
          </div>
        </div>
        <!-- Кнопки добавления -->
        <div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap;">
          <button v-if="!isViewer" class="td-btn td-btn-outline" style="font-size:11px;padding:6px 16px;" @click="addItem">+ Блюдо</button>
          <button v-if="!isViewer" class="td-btn td-btn-outline" style="font-size:11px;padding:6px 16px;" @click="addCategoryItem">+ Категория</button>
          <label v-if="!isViewer" class="td-btn td-btn-outline" style="font-size:11px;padding:6px 16px;cursor:pointer;">
            <BkIcon name="import" size="sm" /> Импорт из Excel
            <input type="file" style="display:none;" accept=".xlsx,.xls" @change="importDishesFromFile" />
          </label>
          <button v-if="activity.items.length" class="td-btn td-btn-outline" style="font-size:11px;padding:6px 16px;margin-left:auto;" @click="showIngSummary = true; loadIngredients()">Сводка ингредиентов</button>
        </div>
      </div>

      <!-- Этапы подготовки (внизу) -->
      <div class="td-card" style="margin-top:16px;">
        <div class="mktd-card-title" style="justify-content:space-between;">
          <span>Этапы подготовки</span>
          <button v-if="!isViewer" class="td-btn td-btn-outline" style="font-size:11px;padding:4px 12px;" @click="addStage">+ Этап</button>
        </div>
        <div v-if="!activity.stages?.length" class="mktd-muted" style="text-align:center;padding:8px 0;font-size:12px;">
          Нет этапов. <a v-if="!isViewer" href="#" @click.prevent="initDefaultStages" style="color:var(--bk-orange);">Создать шаблон</a>
        </div>
        <div v-else class="mktd-stages">
          <div v-for="(stage, si) in activity.stages" :key="si" class="mktd-stage" :class="'st-' + stage.status">
            <div class="mktd-stage-status">
              <button v-if="!isViewer" class="mktd-stage-check" :class="{ done: stage.status === 'done', active: stage.status === 'in_progress' }" @click="cycleStageStatus(si)">
                {{ stage.status === 'done' ? '✓' : stage.status === 'in_progress' ? '●' : '○' }}
              </button>
              <span v-else class="mktd-stage-check" :class="{ done: stage.status === 'done', active: stage.status === 'in_progress' }">{{ stage.status === 'done' ? '✓' : stage.status === 'in_progress' ? '●' : '○' }}</span>
            </div>
            <div class="mktd-stage-body"><input v-if="!isViewer" class="mktd-stage-name" v-model="stage.name" placeholder="Этап" /><span v-else class="mktd-stage-name-ro">{{ stage.name }}</span></div>
            <div class="mktd-stage-date">
              <input v-if="!isViewer" type="date" class="mktd-input mktd-input-sm" v-model="stage.deadline" style="width:130px;" />
              <span v-else style="font-size:12px;color:var(--text-muted);">{{ stage.deadline || '—' }}</span>
              <span v-if="stage.deadline" class="mktd-stage-days" :class="stageDaysClass(stage)">{{ stageDaysLabel(stage) }}</span>
            </div>
            <div class="mktd-stage-comment"><input v-if="!isViewer" class="mktd-input mktd-input-sm" v-model="stage.comment" placeholder="Комментарий..." style="flex:1;" /><span v-else style="font-size:11px;color:var(--text-muted);">{{ stage.comment }}</span></div>
            <button v-if="!isViewer" class="mktd-remove-btn" @click="activity.stages.splice(si, 1)"><BkIcon name="close" size="xs" /></button>
          </div>
        </div>
      </div>

      <!-- Файлы -->
      <div class="td-card" style="margin-top:16px;padding:12px 20px;">
        <div class="mktd-files-row">
          <span style="font-weight:700;font-size:13px;color:var(--bk-brown);">Файлы</span>
          <div class="mktd-files-list">
            <span v-for="f in activity.files" :key="f.id" class="mktd-file-chip">
              <a :href="fileUrl(f)" target="_blank" class="mktd-file-link"><BkIcon name="export" size="xs" /> {{ f.file_name }}</a>
              <button v-if="!isViewer" class="mktd-remove-btn" @click.stop="deleteFile(f)"><BkIcon name="close" size="xs" /></button>
            </span>
            <span v-if="!activity.files.length" class="mktd-muted" style="font-size:12px;">Нет вложений</span>
          </div>
          <label v-if="!isViewer && activity.id" class="btn small" style="flex-shrink:0;"><BkIcon name="import" size="sm" /> Загрузить<input type="file" style="display:none;" @change="uploadFile" accept=".pdf,.jpg,.jpeg,.png,.webp,.xlsx,.xls,.docx,.doc" /></label>
          <span v-if="uploading" style="font-size:11px;color:var(--text-muted);">Загрузка...</span>
        </div>
      </div>

    </template>

    <!-- Модалка сводки ингредиентов -->
    <Teleport to="body">
      <div v-if="showIngSummary" class="mktd-modal-overlay" @click.self="showIngSummary = false">
        <div class="mktd-modal" style="width:700px;max-height:85vh;">
          <div class="mktd-modal-header">
            <h3>Сводка ингредиентов ({{ ingredientsList.length }})</h3>
            <button class="mktd-remove-btn" @click="showIngSummary = false" style="font-size:18px;">×</button>
          </div>
          <div class="mktd-modal-search">
            <input class="mktd-input" v-model="ingFilter" placeholder="Фильтр..." />
          </div>
          <div style="flex:1;overflow-y:auto;padding:0 20px 16px;">
            <div class="mktd-ing-list">
              <div v-for="ing in filterIngs(ingredientsList)" :key="ing.analogGroup || ing.name"
                class="mktd-ing-row" :class="{ expanded: expandedIng === ingKey(ing) }"
                @click="expandedIng = expandedIng === ingKey(ing) ? null : ingKey(ing)">
                <div class="mktd-ing-main">
                  <div class="mktd-ing-name">{{ ing.name }}</div>
                  <div class="mktd-ing-nums">
                    <span v-if="ing.totalGrams > 0" class="mktd-ing-val">{{ formatNum(ing.totalGrams / 1000) }} <small>кг</small></span>
                    <span v-if="ing.totalQty > 0" class="mktd-ing-val">{{ formatNum(ing.totalQty) }} <small>шт</small></span>
                    <span v-if="ingCases(ing)" class="mktd-ing-cases">{{ ingCases(ing) }} <small>кейс.</small></span>
                  </div>
                  <div class="mktd-ing-sup" v-if="ing.supplier">{{ ing.supplier }}</div>
                </div>
                <div v-if="expandedIng === ingKey(ing)" class="mktd-ing-detail" @click.stop>
                  <div class="mktd-ing-detail-grid">
                    <div><span class="mktd-ing-dlabel">Артикулы:</span> {{ ing.skus.join(', ') || '—' }}</div>
                    <div><span class="mktd-ing-dlabel">Поставщик:</span> {{ ing.supplier || '—' }}</div>
                    <div><span class="mktd-ing-dlabel">Из блюд:</span> {{ ing.fromDishes.join(', ') }}</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Модалка привязки после импорта -->
    <Teleport to="body">
      <div v-if="importMatchModal" class="mktd-modal-overlay" @click.self>
        <div class="mktd-modal" style="width:650px;">
          <div class="mktd-modal-header">
            <h3>Привязка блюд к рецептурам</h3>
            <span style="font-size:12px;color:var(--text-muted);">{{ importUnmatched.filter(u => !u.matched && !u.skipped).length }} не найдено</span>
          </div>
          <div style="flex:1;overflow-y:auto;padding:12px 20px;max-height:400px;">
            <div v-for="(um, ui) in importUnmatched" :key="ui" class="mktd-match-row" :class="{ done: um.matched, skip: um.skipped }">
              <div class="mktd-match-name">
                <span style="font-weight:600;">{{ um.originalName }}</span>
                <span v-if="um.isChoice && !um.matched && !um.skipped" style="background:#FFF3E0;color:#E65100;font-size:10px;padding:1px 6px;border-radius:8px;margin-left:6px;">на выбор — выберите все варианты</span>
                <span v-if="um.matched && um.picks?.length === 1" style="color:#4CAF50;font-size:11px;margin-left:6px;">→ {{ um.picks[0].name }}</span>
                <span v-if="um.matched && um.picks?.length > 1" style="color:#4CAF50;font-size:11px;margin-left:6px;">→ {{ um.picks.length }} вариантов</span>
                <span v-if="um.skipped" style="color:var(--text-muted);font-size:11px;margin-left:6px;">пропущено</span>
              </div>
              <!-- Выбранные рецептуры -->
              <div v-if="um.picks?.length && !um.matched" style="display:flex;flex-wrap:wrap;gap:4px;margin:4px 0;">
                <span v-for="(p, pi) in um.picks" :key="p.id" style="display:inline-flex;align-items:center;gap:3px;background:#E8F5E9;color:#2E7D32;padding:2px 8px;border-radius:10px;font-size:11px;">
                  {{ p.name }}
                  <span style="cursor:pointer;font-size:14px;line-height:1;" @click="removeImportPick(um, pi)">×</span>
                </span>
              </div>
              <div v-if="!um.skipped && !um.matched" class="mktd-match-actions">
                <input class="mktd-input mktd-input-sm" style="width:200px;" placeholder="Поиск рецептуры..."
                  @input="searchImportRecipe($event.target.value, ui)" @focus="searchImportRecipe(um.originalName, ui)" />
                <button v-if="um.picks?.length" class="td-btn td-btn-primary" style="font-size:10px;padding:3px 8px;" @click="confirmImportPick(um)">✓</button>
                <button class="td-btn td-btn-outline" style="font-size:10px;padding:3px 8px;" @click="skipImportMatch(um)">Пропустить</button>
              </div>
              <div v-if="!um.matched && !um.skipped && importSearchResults.length && activeMatchRow === ui" class="mktd-match-results">
                <div v-for="r in importSearchResults" :key="r.id" class="mktd-dropdown-item" :class="{ selected: um.picks?.some(p => p.id === r.id) }" @click="pickImportMatch(um, r)">
                  <span class="mktd-dropdown-sku">{{ r.code }}</span> {{ r.name }}
                </div>
              </div>
            </div>
          </div>
          <div class="mktd-modal-footer">
            <button class="td-btn td-btn-outline" @click="importMatchModal = false; importPendingItems = [];">Отмена</button>
            <button class="td-btn td-btn-primary" @click="applyImportMatches">Применить</button>
          </div>
        </div>
      </div>
    </Teleport>

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
const layoutMode = ref('A');
const selectedDishB = ref(-1);
const expandedDishC = ref(-1);
const showIngSummary = ref(false);
const importMatchModal = ref(false);
const importUnmatched = ref([]);
const importPendingItems = ref([]);
const importSearchQuery = ref('');
const importSearchResults = ref([]);
let _importSearchTimer = null;
const defaultRestCount = ref(56); // обновляется из БД на mount
const itemsTab = ref('dishes');
const ingDishFilter = ref('all');
const ingredientsLoading = ref(false);
const ingredientsData = ref([]); // raw recipe data from API
const editingSupplier = ref(null);
const editingComment = ref(null);
const editIngComment = ref(null);
function startIngComment(ing) { editIngComment.value = ing.analogGroup || ing.name; if (!ing._comment) ing._comment = ''; }
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

function dishAuvDisplay(item) {
  if (item.auv) return item.auv;
  if (item.auv_periods?.length) {
    const vals = item.auv_periods.map(p => p.auv || 0).filter(v => v > 0);
    if (vals.length) return vals.length === 1 ? vals[0] : vals.join('/');
  }
  return 0;
}

function itemTotal(item) {
  if (!item) return 0;
  const rests = activity.value.restaurant_count || defaultRestCount.value;
  if (item.calc_method === 'auv' || item.calc_method === 'category') {
    if (hasMultipleMonths.value && item.auv_periods?.length) {
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

// ─── Модалка привязки после импорта ──────────────────────────────────────────
function searchImportRecipe(q, rowIndex) {
  clearTimeout(_importSearchTimer);
  importSearchQuery.value = q;
  activeMatchRow.value = rowIndex ?? -1;
  if (q.length < 2) { importSearchResults.value = []; return; }
  _importSearchTimer = setTimeout(async () => {
    const { data } = await db.from('recipes').select('id, code, name').ilike('name', `*${q}*`).order('name', { ascending: true }).limit(20);
    importSearchResults.value = data || [];
  }, 200);
}
const activeMatchRow = ref(-1);
function pickImportMatch(unmatchedItem, recipe) {
  if (!unmatchedItem.picks) unmatchedItem.picks = [];
  // Если уже выбран — убрать
  const idx = unmatchedItem.picks.findIndex(p => p.id === recipe.id);
  if (idx >= 0) { unmatchedItem.picks.splice(idx, 1); return; }
  unmatchedItem.picks.push({ id: recipe.id, code: recipe.code, name: recipe.name });
}
function confirmImportPick(unmatchedItem) {
  if (!unmatchedItem.picks?.length) return;
  const p = unmatchedItem.picks[0];
  if (unmatchedItem.type === 'sub') {
    unmatchedItem.ref.recipe_id = p.id;
    unmatchedItem.ref.code = p.code;
    unmatchedItem.ref.name = p.name;
  } else {
    unmatchedItem.ref.sku = p.code;
    unmatchedItem.ref.name = p.name;
  }
  unmatchedItem.matched = true;
  importSearchResults.value = [];
  activeMatchRow.value = -1;
}
function removeImportPick(unmatchedItem, pickIndex) {
  unmatchedItem.picks.splice(pickIndex, 1);
  unmatchedItem.matched = false;
  if (unmatchedItem.picks.length === 1) {
    const p = unmatchedItem.picks[0];
    if (unmatchedItem.type === 'sub') { unmatchedItem.ref.recipe_id = p.id; unmatchedItem.ref.code = p.code; unmatchedItem.ref.name = p.name; }
    else { unmatchedItem.ref.sku = p.code; unmatchedItem.ref.name = p.name; }
    unmatchedItem.matched = true;
  } else if (!unmatchedItem.picks.length) {
    if (unmatchedItem.type === 'sub') { unmatchedItem.ref.recipe_id = null; unmatchedItem.ref.code = ''; unmatchedItem.ref.name = unmatchedItem.originalName; }
    else { unmatchedItem.ref.sku = null; unmatchedItem.ref.name = unmatchedItem.originalName; }
  }
}
function skipImportMatch(unmatchedItem) { unmatchedItem.skipped = true; importSearchResults.value = []; activeMatchRow.value = -1; }
function applyImportMatches() {
  // Раскрыть множественные выборы в отдельные sub_items/items
  for (const um of importUnmatched.value) {
    if (!um.picks || um.picks.length <= 1) continue;
    if (um.type === 'sub') {
      // Найти item, содержащий этот sub_item, и заменить на несколько
      const parentItem = importPendingItems.value.find(it => it.sub_items?.includes(um.ref));
      if (parentItem) {
        const si = parentItem.sub_items;
        const idx = si.indexOf(um.ref);
        const origQty = um.ref.qty;
        // «На выбор»: каждый вариант получает долю qty, делённую на кол-во вариантов
        // (гость выбирает 1 из N → расход каждого = 1/N)
        const qtyPerVariant = um.isChoice ? origQty / um.picks.length : origQty;
        const newSubs = um.picks.map(p => ({ recipe_id: p.id, name: p.name, code: p.code, share: 0, qty: qtyPerVariant }));
        si.splice(idx, 1, ...newSubs);
        // Пересчитать доли
        const totalQty = si.reduce((s, sub) => s + sub.qty, 0);
        si.forEach(sub => { sub.share = totalQty > 0 ? Math.round(sub.qty / totalQty * 10000) / 10000 : 0; });
      }
    } else {
      // Для обычных items — дублировать item для каждого выбранного
      const itemIdx = importPendingItems.value.indexOf(um.ref);
      if (itemIdx >= 0) {
        const orig = um.ref;
        const newItems = um.picks.map(p => ({ ...orig, sku: p.code, name: p.name }));
        importPendingItems.value.splice(itemIdx, 1, ...newItems);
      }
    }
  }
  activity.value.items.push(...importPendingItems.value);
  importMatchModal.value = false;
  importUnmatched.value = [];
  importPendingItems.value = [];
  toast.success('Импортировано', '');
  loadIngredients();
}

// ─── Импорт блюд из Excel ────────────────────────────────────────────────────
async function importDishesFromFile(e) {
  const file = e.target.files?.[0];
  if (!file) return;
  try {
    const XLSX = (await import('xlsx-js-style')).default;
    const buf = await file.arrayBuffer();
    const wb = XLSX.read(buf, { type: 'array' });
    const ws = wb.Sheets[wb.SheetNames[0]];
    const data = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' });
    if (data.length < 2) { toast.error('Пустой файл', ''); return; }

    const headers0 = data[0].map(h => String(h).toLowerCase().trim());
    const headers1 = data.length > 1 ? data[1].map(h => String(h).toLowerCase().trim()) : [];
    const newItems = [];

    // Определяем формат: купоны (Номер|Состав|AUV) или промо (Блюдо|Цена|...|AUV месяцы)
    // Купонный файл может иметь строку периода в row 0 и заголовки в row 1
    const couponInRow0 = headers0.some(h => h.includes('состав'));
    const couponInRow1 = headers1.some(h => h.includes('состав'));
    const isCouponFormat = couponInRow0 || couponInRow1;
    const couponDataStart = couponInRow0 ? 1 : couponInRow1 ? 2 : 1;
    const headers = couponInRow1 && !couponInRow0 ? headers1 : headers0;
    const isPromoFormat = !isCouponFormat && headers0.some(h => h.includes('блюдо'));

    if (isCouponFormat) {
      // Купоны: Номер | Состав (блюда через запятую) | AUV
      for (let i = couponDataStart; i < data.length; i++) {
        const row = data[i];
        const couponId = String(row[0] || '').trim();
        const composition = String(row[1] || '').trim();
        const auv = parseFloat(row[2]) || 0;
        if (!composition) continue;
        // Разделяем по запятой, но не внутри чисел (0,5 л → не разделять)
        const parts = composition.split(/,\s*(?!\d)/).map(s => s.trim()).filter(Boolean);
        const subItems = [];
        for (let part of parts) {
          part = part.replace(/\(.*?\)/g, '').trim();
          if (!part) continue;
          // «на выбор» — позиция, где гость выбирает один вариант из нескольких
          const isChoice = part.toLowerCase().includes('на выбор');
          const cleanPart = part.replace(/на выбор/gi, '').trim();
          if (!cleanPart) continue;
          const qm = cleanPart.match(/^(\d+)\s+(.+)/);
          const qty = qm ? parseInt(qm[1]) : 1;
          const dishName = (qm ? qm[2] : cleanPart).replace(/мал\.$/, 'малый').replace(/газ\.\s*/, 'газ. ').trim();
          subItems.push({ recipe_id: null, name: dishName, code: '', share: 0, qty, _choice: isChoice });
        }
        const totalQty = subItems.reduce((s, si) => s + si.qty, 0);
        subItems.forEach(si => { si.share = totalQty > 0 ? Math.round(si.qty / totalQty * 10000) / 10000 : 0; });
        newItems.push({
          product_id: null, sku: couponId || null, name: couponId ? `${couponId}: ${composition}` : composition,
          calc_method: 'category', auv, auv_periods: null, sub_items: subItems,
          total_volume: null, fixed_qty: null, unit: 'шт', note: '',
        });
      }
    } else if (isPromoFormat) {
      // Промо 3-4-5: Блюдо | Цена | ... | AUV по месяцам
      // Найти колонки AUV (содержат "auv" или "план")
      const auvCols = [];
      const monthLabels = [];
      headers.forEach((h, ci) => {
        if (h.includes('auv') || h.includes('план')) { auvCols.push(ci); monthLabels.push(String(data[0][ci]).trim()); }
      });
      for (let i = 1; i < data.length; i++) {
        const row = data[i];
        const dishName = String(row[0] || '').trim();
        if (!dishName || dishName.toUpperCase() === 'TOTAL') continue;
        // AUV: если несколько колонок — по месяцам
        let auv = 0;
        let auvPeriods = null;
        if (auvCols.length > 1) {
          auvPeriods = [];
          const months = activityMonths.value;
          auvCols.forEach((ci, mi) => {
            const val = parseFloat(row[ci]) || 0;
            const mKey = months[mi]?.key || `m${mi}`;
            auvPeriods.push({ month: mKey, auv: val });
            if (!auv) auv = val;
          });
        } else if (auvCols.length === 1) {
          auv = parseFloat(row[auvCols[0]]) || 0;
        }
        const price = row[1] ? String(row[1]).trim() : '';
        newItems.push({
          product_id: null, sku: null, name: dishName,
          calc_method: 'auv', auv, auv_periods: auvPeriods, sub_items: null,
          total_volume: null, fixed_qty: null, unit: 'шт', note: price ? `Цена: ${price}` : '',
        });
      }
    } else {
      toast.error('Неизвестный формат', 'Ожидается: Блюдо|...|AUV или Номер|Состав|AUV');
      return;
    }

    if (!newItems.length) { toast.error('Не найдено блюд', ''); return; }

    // Привязка к рецептурам
    const allNames = [...new Set(newItems.flatMap(it => {
      if (it.sub_items?.length) return it.sub_items.map(s => s.name);
      return [it.name];
    }))];
    let recipeMap = {};
    if (allNames.length) {
      const { data: recipeData } = await db.rpc('find_recipes_by_names', { names: allNames });
      recipeMap = recipeData?.recipes || {};
    }

    // Собрать ненайденные для модалки
    // Позиции «на выбор» всегда попадают в модалку для множественного выбора
    const unmatched = [];
    for (const item of newItems) {
      if (item.sub_items?.length) {
        for (const sub of item.sub_items) {
          if (sub._choice) {
            // «На выбор» — всегда в модалку, даже если нашлась одна рецептура
            unmatched.push({ ref: sub, originalName: sub.name, type: 'sub', isChoice: true });
          } else {
            const found = recipeMap[sub.name];
            if (found) { sub.recipe_id = found.id; sub.code = found.code; sub.name = found.name; }
            else { unmatched.push({ ref: sub, originalName: sub.name, type: 'sub' }); }
          }
          delete sub._choice;
        }
      } else {
        const found = recipeMap[item.name];
        if (found) { item.sku = found.code; item.name = found.name; }
        else { unmatched.push({ ref: item, originalName: item.name, type: 'item' }); }
      }
    }

    importPendingItems.value = newItems;

    if (unmatched.length) {
      // Открыть модалку для ручной привязки
      importUnmatched.value = unmatched;
      importMatchModal.value = true;
    } else {
      activity.value.items.push(...newItems);
      toast.success('Импортировано', `${newItems.length} блюд`);
      loadIngredients();
    }
  } catch (err) {
    console.error(err);
    toast.error('Ошибка', 'Не удалось обработать файл');
  } finally { e.target.value = ''; }
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
        const subPortions = sub.qty ? totalPortions * sub.qty : totalPortions * (sub.share || 0);
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

const currentDishIdx = computed(() => {
  if (!itemsTab.value.startsWith('dish-')) return -1;
  return parseInt(itemsTab.value.slice(5));
});
const currentDish = computed(() => activity.value.items[currentDishIdx.value]);

// Ингредиенты конкретного блюда
function dishIngredients(ii) {
  const dish = activity.value.items[ii];
  if (!dish) return [];
  const recipeMap = {};
  for (const r of ingredientsData.value) recipeMap[r.name] = r;
  const result = [];
  if (dish.calc_method === 'category' && dish.sub_items?.length) {
    const totalPortions = itemTotal(dish);
    for (const sub of dish.sub_items) {
      const recipe = recipeMap[sub.name];
      if (!recipe?.ingredients) continue;
      const subPortions = sub.qty ? totalPortions * sub.qty : totalPortions * (sub.share || 0);
      for (const ing of recipe.ingredients) {
        const key = ing.analog_group || ing.sku || ing.name;
        let existing = result.find(r => (r.analogGroup || r.name) === key);
        if (!existing) { existing = { name: ing.analog_group || ing.name, analogGroup: ing.analog_group, skus: [], originalSku: ing.original_sku || null, totalGrams: 0, totalQty: 0, qtyPerBox: ing.qty_per_box ? parseFloat(ing.qty_per_box) : null, productUnit: ing.product_unit, supplier: ing.product_supplier, _comment: '' }; result.push(existing); }
        if (ing.sku && !existing.skus.includes(ing.sku)) existing.skus.push(ing.sku);
        if (ing.brutto) existing.totalGrams += parseFloat(ing.brutto) * subPortions;
        if (ing.qty) existing.totalQty += parseFloat(ing.qty) * subPortions;
      }
    }
  } else {
    const recipe = recipeMap[dish.name];
    if (!recipe?.ingredients) return [];
    const portions = itemTotal(dish);
    for (const ing of recipe.ingredients) {
      result.push({ name: ing.analog_group || ing.name, analogGroup: ing.analog_group, skus: ing.sku ? [ing.sku] : [], originalSku: ing.original_sku || null, totalGrams: ing.brutto ? parseFloat(ing.brutto) * portions : 0, totalQty: ing.qty ? parseFloat(ing.qty) * portions : 0, qtyPerBox: ing.qty_per_box ? parseFloat(ing.qty_per_box) : null, productUnit: ing.product_unit, supplier: ing.product_supplier, _comment: '' });
    }
  }
  return result;
}

const ingFilter = ref('');
const expandedIng = ref(null);

function ingKey(ing) { return ing.analogGroup || ing.name; }

function filterIngs(ings) {
  const q = ingFilter.value.trim().toLowerCase();
  if (!q) return ings;
  return ings.filter(i => i.name.toLowerCase().includes(q) || (i.supplier || '').toLowerCase().includes(q) || i.skus.some(s => s.includes(q)));
}

function ingCases(ing) {
  if (ing.qtyPerBox === -1) return null;
  if (ing.qtyPerBox > 0 && ing.totalQty > 0) return formatNum(Math.ceil(ing.totalQty / ing.qtyPerBox));
  if (ing.qtyPerBox > 0 && ing.totalGrams > 0 && (ing.productUnit === 'кг' || ing.productUnit === 'л')) return formatNum(Math.ceil(ing.totalGrams / 1000 / ing.qtyPerBox));
  return null;
}

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
  // Загрузить кол-во ресторанов по умолчанию
  db.from('restaurants').select('number').then(({ data }) => { if (data?.length) defaultRestCount.value = new Set(data.map(r => r.number)).size; });
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
.mktd-dropdown-item.selected { background: #E8F5E9; color: #2E7D32; }
.mktd-dropdown-sku { font-weight: 800; color: var(--bk-orange); margin-right: 6px; }

/* Dish tabs */
.mktd-dish-tabs { display: flex; align-items: center; gap: 10px; padding-bottom: 14px; border-bottom: 2px solid #E8E0D8; margin-bottom: 16px; }
.mktd-dish-tabs-scroll { display: flex; gap: 4px; flex: 1; overflow-x: auto; padding-bottom: 2px; }
.mktd-dish-tab { padding: 6px 14px; border-radius: 8px; border: 1.5px solid #E8E0D8; background: white; font-size: 12px; font-weight: 600; font-family: inherit; color: var(--text-muted); cursor: pointer; transition: all .15s; white-space: nowrap; flex-shrink: 0; }
.mktd-dish-tab:hover { border-color: var(--bk-orange); color: var(--text); }
.mktd-dish-tab.active { background: var(--bk-brown, #502314); color: #fff; border-color: var(--bk-brown); }
.mktd-dish-tab.active .mktd-card-count { background: rgba(255,255,255,0.3); }
.mktd-dish-edit { padding: 4px 0; }
.mktd-dish-form { display: flex; flex-direction: column; gap: 10px; }

/* Tabs (old) */
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
/* Ingredient list — card-based */
.mktd-ing-list { display: flex; flex-direction: column; gap: 3px; }
.mktd-ing-grp-title { font-weight: 700; font-size: 12px; color: var(--bk-brown); padding: 10px 0 4px; border-bottom: 2px solid var(--bk-orange); margin-top: 8px; }
.mktd-ing-grp-title:first-child { margin-top: 0; }
.mktd-ing-grp-title span { font-weight: 500; color: var(--text-muted); font-size: 11px; margin-left: 4px; }
.mktd-ing-row { border-radius: 8px; cursor: pointer; transition: background 0.1s; }
.mktd-ing-row:hover { background: #FEFCF9; }
.mktd-ing-row.expanded { background: #FEFCF9; box-shadow: inset 0 0 0 1px #E8E0D8; }
.mktd-ing-main { display: flex; align-items: center; gap: 12px; padding: 8px 10px; }
.mktd-ing-name { font-weight: 600; font-size: 13px; color: var(--text); flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mktd-ing-nums { display: flex; gap: 10px; flex-shrink: 0; }
.mktd-ing-val { font-size: 13px; font-weight: 600; color: var(--bk-brown); }
.mktd-ing-val small { font-weight: 500; font-size: 10px; color: var(--text-muted); }
.mktd-ing-cases { font-size: 13px; font-weight: 700; color: #1565C0; background: #E3F2FD; padding: 1px 8px; border-radius: 4px; }
.mktd-ing-cases small { font-weight: 600; font-size: 10px; color: #1976D2; }
.mktd-ing-sup { font-size: 11px; color: var(--text-muted); flex-shrink: 0; max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mktd-ing-detail { padding: 0 10px 10px; }
.mktd-ing-detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 20px; font-size: 12px; color: var(--text); }
.mktd-ing-dlabel { font-weight: 600; color: var(--text-muted); font-size: 11px; }

.mktd-supplier-cell { cursor: pointer; }
.mktd-supplier-cell:hover { background: rgba(214,35,0,0.03); }
.mktd-ing-group td { background: #FFFBF5 !important; }
.mktd-ing-info { font-size: 12px; color: var(--text-muted); padding: 8px 0 12px; }
.mktd-ing-warn { color: #D97706; font-weight: 600; }

/* Layout selector */
.mktd-layout-select { padding: 4px 8px; border: 1.5px solid #D4C4B0; border-radius: 6px; font-size: 11px; font-weight: 600; font-family: inherit; background: white; color: var(--bk-brown); cursor: pointer; }

/* ═══ Вариант Б: Две колонки ═══ */
.mktd-two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 16px; }
.mktd-col { background: white; border-radius: 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); overflow: hidden; }
.mktd-col-header { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: var(--bk-brown, #502314); color: white; font-weight: 700; font-size: 13px; }
.mktd-col-header .mktd-input-sm { background: rgba(255,255,255,0.15); border-color: rgba(255,255,255,0.2); color: white; }
.mktd-col-header .mktd-input-sm::placeholder { color: rgba(255,255,255,0.5); }
.mktd-dish-list-b { padding: 8px; display: flex; flex-direction: column; gap: 4px; max-height: 500px; overflow-y: auto; }
.mktd-dish-card-b { padding: 10px 12px; border-radius: 8px; cursor: pointer; transition: all 0.1s; border: 1.5px solid transparent; }
.mktd-dish-card-b:hover { background: #FEFCF9; border-color: #E8E0D8; }
.mktd-dish-card-b.active { background: #FFF8F0; border-color: var(--bk-orange); }
.mktd-dish-card-b-top { display: flex; justify-content: space-between; align-items: center; }
.mktd-dish-card-b-name { font-weight: 600; font-size: 13px; }
.mktd-dish-card-b-total { font-weight: 700; font-size: 13px; color: var(--bk-brown); }
.mktd-dish-card-b-meta { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
.mktd-dish-card-b-edit { border-top: 1px solid #E8E0D8; padding-top: 8px; margin-top: 8px; }

/* ═══ Вариант В: Карточки ═══ */
.mktd-c-params { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; margin-bottom: 16px; }
.mktd-c-tag { padding: 5px 10px; border: 1.5px solid #D4C4B0; border-radius: 20px; font-size: 12px; font-family: inherit; background: white; color: var(--text); }
.mktd-c-tag:focus { border-color: var(--bk-orange); outline: none; }
.mktd-c-tag-ro { background: #F5F0EB; cursor: default; }
.mktd-c-sep { color: var(--text-muted); font-size: 12px; }
.mktd-c-dishes { display: flex; flex-direction: column; gap: 8px; }
.mktd-c-dish { background: white; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); cursor: pointer; transition: all 0.15s; overflow: hidden; }
.mktd-c-dish:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
.mktd-c-dish-head { display: flex; align-items: center; gap: 12px; padding: 10px 16px; cursor: pointer; }
.mktd-c-dish-name { font-weight: 700; font-size: 13px; color: var(--bk-brown); flex: 1; min-width: 0; }
.mktd-c-dish-meta { font-size: 11px; color: var(--text-muted); white-space: nowrap; }
.mktd-c-dish-total { font-size: 13px; font-weight: 700; color: var(--bk-brown); white-space: nowrap; }
.mktd-c-dish-body { padding: 0 18px 16px; border-top: 1px solid #F0EBE5; }
.mktd-c-dish-ings { display: flex; flex-direction: column; gap: 2px; }
.mktd-c-dish-ing { display: flex; justify-content: space-between; align-items: center; padding: 5px 0; border-bottom: 1px solid #F5F0EB; font-size: 12px; }
.mktd-c-dish-ing:last-child { border-bottom: none; }
.mktd-c-dish-note { font-size: 11px; color: var(--text-muted); font-style: italic; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex-shrink: 0; }
.mktd-dish-ing-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.mktd-dish-ing-table th { font-size: 9px; text-transform: uppercase; letter-spacing: 0.3px; color: var(--text-muted); font-weight: 600; padding: 4px 6px; border-bottom: 1px solid #E8E0D8; text-align: center; }
.mktd-dish-ing-table td { padding: 4px 6px; border-bottom: 1px solid #F5F0EB; text-align: center; }
.mktd-dish-ing-table tbody tr:hover { background: #FEFCF9; }

/* Import match modal */
.mktd-match-row { padding: 8px 0; border-bottom: 1px solid #F0EBE5; }
.mktd-match-row.done { opacity: 0.5; }
.mktd-match-row.skip { opacity: 0.3; }
.mktd-match-name { margin-bottom: 4px; }
.mktd-match-actions { display: flex; gap: 6px; align-items: center; }
.mktd-match-results { margin-top: 4px; background: #FAFAF8; border: 1px solid #E8E0D8; border-radius: 6px; max-height: 120px; overflow-y: auto; }
.mktd-c-dish-ing-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 500; display: flex; align-items: center; gap: 4px; }
.mktd-c-dish-ing-sku { font-size: 9px; font-weight: 700; color: var(--bk-orange); background: rgba(214,35,0,0.06); padding: 1px 4px; border-radius: 3px; flex-shrink: 0; }
.mktd-c-dish-ing-old { font-size: 8px; color: var(--text-muted); text-decoration: line-through; flex-shrink: 0; }
.mktd-c-dish.open { box-shadow: 0 2px 12px rgba(0,0,0,0.1); border-left: 3px solid var(--bk-orange); }

@media (max-width: 600px) {
  .mktd-card { padding: 16px; border-radius: 10px; }
  .mktd-row { flex-direction: column; gap: 10px; }
  .mktd-field { min-width: 100%; }
}
</style>
