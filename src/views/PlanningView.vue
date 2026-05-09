<template>
  <div class="planning-view" :class="{ 'fullscreen-table': isFullscreen }">
    <div v-if="!viewOnly || showFullPlan || !editingPlanId" class="page-header">
      <h1 class="page-title">Планирование</h1>
      <span v-if="viewOnly" class="editing-badge" style="cursor:pointer;background:#E3F2FD;color:#1565C0;border-color:#90CAF9;" @click="resetPlan"><BkIcon name="eye" size="sm"/> Просмотр</span>
      <button v-if="viewOnly && editingPlanId" class="btn small" style="margin-left:4px;" @click="openLogModal" title="История изменений"><BkIcon name="note" size="sm"/> История</button>
      <span v-else-if="editingPlanId" class="editing-badge" style="cursor:pointer" @click="resetPlan"><BkIcon name="edit" size="sm"/> Редактирование</span>
    </div>

    <!-- Viewer заглушка -->
    <ViewerBanner v-if="isViewer && !viewOnly && !editingPlanId" itemsLabel="планы" />

    <!-- Параметры: кликабельная строка-сводка + раскрывающаяся панель -->
    <div v-if="!(isViewer && !viewOnly && !editingPlanId) && (!viewOnly || showFullPlan || !editingPlanId)" class="params-block" :class="{ open: settingsExpanded }">
      <div class="params-summary" @click="toggleSettings">
        <BkIcon name="gear" size="sm" class="params-icon"/>
        <span class="ps-chip"><b>{{ supplier || 'Не выбран' }}</b></span>
        <span class="ps-chip">{{ periodLabel }}</span>
        <span class="ps-chip">с {{ startDateDisplay }}</span>
        <span v-if="planningDateStr" class="ps-chip">план с {{ planningDateDisplay }}</span>
        <span class="ps-chip">{{ inputUnit === 'boxes' ? 'коробки' : 'штуки' }}</span>
        <span class="ps-chip">расход/{{ consumptionPeriodDays }}дн</span>
        <span v-if="truckEnabled" class="ps-chip">машина {{ truckPallets }} пал</span>
        <span class="params-toggle-hint">
          <BkIcon :name="settingsExpanded ? 'chevronUp' : 'chevronDown'" size="xs"/>
        </span>
      </div>
      <div v-if="settingsExpanded" class="params-fields">
        <div class="pf-group">
          <label>Поставщик</label>
          <select v-model="supplier" @change="loadProducts" :disabled="suppLoading || viewOnly">
            <option value="">— Выберите —</option>
            <option v-for="s in suppliers" :key="s.short_name" :value="s.short_name">{{ s.short_name }}</option>
          </select>
        </div>
        <div class="pf-group">
          <label>Частота заказа</label>
          <select v-model="periodFrequency" @change="onFrequencyChange" :disabled="viewOnly">
            <option value="w1">Раз в неделю</option>
            <option value="w2">Раз в 2 недели</option>
            <option value="m1">Раз в месяц</option>
          </select>
        </div>
        <div class="pf-group">
          <label>Кол-во периодов</label>
          <input type="number" v-model.number="periodCountInput" min="1" max="12" :disabled="viewOnly" @change="onPeriodCountChange" style="width:60px;"/>
        </div>
        <div class="pf-group">
          <label>Дата начала</label>
          <input type="date" v-model="startDateStr" @change="onParamsChange" :disabled="viewOnly"/>
        </div>
        <div class="pf-group">
          <label>Дата планирования</label>
          <input type="date" v-model="planningDateStr" @change="onPlanningDateChange" :disabled="viewOnly" :min="startDateStr"/>
        </div>
        <div class="pf-group">
          <label>Единицы</label>
          <select :value="inputUnit" @change="onUnitChange" :disabled="viewOnly">
            <option value="pieces">Штуки</option>
            <option value="boxes">Коробки</option>
          </select>
        </div>
        <div class="pf-group">
          <label>Период расхода</label>
          <select v-model.number="consumptionPeriodDays" @change="onConsumptionPeriodChange" :disabled="viewOnly">
            <option :value="7">7 дней</option><option :value="14">14 дней</option><option :value="21">21 день</option>
            <option :value="30">30 дней</option>
          </select>
        </div>
        <div class="pf-group pf-group-truck">
          <label><input type="checkbox" v-model="truckEnabled" :disabled="viewOnly" style="margin-right:4px;vertical-align:middle;"/> Машина</label>
          <input v-if="truckEnabled" type="number" v-model.number="truckPallets" min="1" max="100" :disabled="viewOnly" style="width:60px;" placeholder="32"/>
          <span v-if="truckEnabled" style="font-size:11px;color:var(--text-muted);">паллет</span>
        </div>
        <div v-if="showCollapseHint" class="params-collapse-hint" @click="settingsExpanded = false; showCollapseHint = false;">
          Параметры заполнены — нажмите чтобы свернуть ▲
        </div>
      </div>
    </div>

    <!-- Тулбар: действия -->
    <div class="order-toolbar" v-if="items.length && !(isViewer && !viewOnly && !editingPlanId) && (!viewOnly || showFullPlan || !editingPlanId)">
      <div class="search-bar" v-if="!viewOnly" style="position:relative;display:flex;align-items:center;gap:8px;">
        <div style="position:relative;display:inline-block;">
          <input type="text" v-model="filterQuery" placeholder="Фильтр по названию / артикулу..."
            style="width:280px;max-width:100%;padding-right:28px;"/>
          <button v-if="filterQuery" @click="filterQuery = ''"
            style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#999;"><BkIcon name="close" size="xs"/></button>
        </div>
      </div>
      <div class="order-actions">
        <button class="btn small" :disabled="!canUndo || viewOnly" @click="undo" title="Отменить"><BkIcon name="undo" size="sm"/></button>
        <button class="btn small" :disabled="!canRedo || viewOnly" @click="redo" title="Повторить"><BkIcon name="redo" size="sm"/></button>
        <button class="btn small fullscreen-toggle-btn" @click="isFullscreen = !isFullscreen"><BkIcon :name="isFullscreen ? 'close' : 'eye'" size="sm"/> {{ isFullscreen ? 'Свернуть' : 'Развернуть' }}</button>
        <button class="compact-toggle" :class="{ active: compactPlan }" @click="toggleCompactPlan" title="Компактный режим"><BkIcon name="menu" size="sm"/> Компакт</button>
        <button class="compact-toggle" :class="{ active: showSales }" @click="showSales = !showSales" title="Показать реализацию ресторанов">📊 Реализация</button>
        <button v-if="excludedCount > 0" class="compact-toggle" :class="{ active: hideExcluded }" @click="hideExcluded = !hideExcluded" :title="hideExcluded ? 'Показать исключённые' : 'Скрыть исключённые'">
          <BkIcon name="eye" size="sm"/> {{ hideExcluded ? 'Показать' : 'Скрыть' }} искл. ({{ excludedCount }})
        </button>
        <button class="btn small" @click="fillConsumption" :disabled="fillLoading || viewOnly" title="Загрузить расход и остаток из анализа запасов"><BkIcon v-if="fillLoading" name="loading" size="sm"/><BkIcon v-else name="history" size="sm"/> Загрузить расх/ост</button>
        <button v-if="!existingOrders.length" class="btn small" @click="loadExistingOrders().then(recalcAll)" :disabled="existingOrdersLoading || !supplier" title="Учесть непринятые заказы у этого поставщика"><BkIcon v-if="existingOrdersLoading" name="loading" size="sm"/><BkIcon v-else name="order" size="sm"/> Заказы в пути</button>
        <button v-else class="btn small" @click="clearExistingOrders" title="Убрать заказы в пути из расчёта"><BkIcon name="order" size="sm"/> Заказы в пути ({{ existingOrders.length }}) ✕</button>
        <button class="btn small" @click="loadFrom1c" :disabled="load1cLoading || viewOnly" title="Загрузить из 1С"><BkIcon v-if="load1cLoading" name="loading" size="sm"/><BkIcon v-else name="oneC" size="sm"/> 1С</button>
        <button class="btn small" @click="doImport" :disabled="viewOnly" title="Импорт из файла"><BkIcon name="import" size="sm"/> Импорт</button>
        <button class="btn small danger" @click="clearAll" :disabled="viewOnly" title="Очистить данные">Очистить</button>
      </div>
    </div>

    <!-- Сводка плана (режим просмотра) -->
    <div v-if="viewOnly && editingPlanId && !showFullPlan && items.length" class="order-summary-wrap">
    <div class="order-summary-card plan-summary-card">
      <div class="osc-header">
        <div class="osc-supplier">{{ supplier }}</div>
        <div class="osc-meta">
          <span v-if="_loadedCreatedBy">{{ _loadedCreatedBy }}</span>
          <span>{{ periodLabel }}, с {{ startDateDisplay }}</span>
        </div>
      </div>
      <div class="osc-dates">
        <div class="osc-date-item">
          <span class="osc-date-label">Единицы</span>
          <span class="osc-date-val">{{ inputUnit === 'boxes' ? 'коробки' : 'штуки' }}</span>
        </div>
        <div class="osc-date-item">
          <span class="osc-date-label">Позиций</span>
          <span class="osc-date-val">{{ itemsWithPlan.length }}</span>
        </div>
        <div class="osc-date-item">
          <span class="osc-date-label">Всего коробок</span>
          <span class="osc-date-val">{{ nf.format(planSummaryTotalBoxes) }}</span>
        </div>
      </div>
      <div v-if="_loadedNote" class="osc-note">{{ _loadedNote }}</div>
      <!-- Разбивка по периодам -->
      <div class="psc-periods">
        <div v-for="(ph, pi) in periodHeaders" :key="pi" v-show="periodTotalBoxes(pi) > 0" class="psc-period-block">
          <div class="psc-period-header" @click="toggleSummaryPeriod(pi)">
            <span class="psc-period-title">{{ ph.label }} <small v-if="ph.sublabel" class="psc-period-sub">{{ ph.sublabel }}</small></span>
            <span class="psc-period-total">{{ nf.format(periodTotalBoxes(pi)) }} кор.</span>
            <BkIcon :name="expandedSummaryPeriods[pi] ? 'chevronUp' : 'chevronDown'" size="xs" class="psc-period-chevron"/>
          </div>
          <div v-if="expandedSummaryPeriods[pi]" class="psc-period-items">
            <template v-for="item in itemsWithPlan" :key="item.sku + '-' + pi">
              <div v-if="item.plan[pi]?.orderBoxes > 0" class="osc-item">
                <div class="osc-item-name">{{ item.name }}</div>
                <div class="osc-item-qty">{{ nf.format(item.plan[pi].orderBoxes) }} кор.<span v-if="item.plan[pi].orderUnits"> · {{ nf.format(item.plan[pi].orderUnits) }} {{ item.unitOfMeasure || 'шт' }}</span></div>
              </div>
            </template>
          </div>
        </div>
      </div>
      <div class="osc-actions">
        <button class="btn primary" @click="showFullPlan = true">Показать весь план</button>
        <button class="btn" @click="resetPlan">Закрыть</button>
      </div>
    </div>
    </div>

    <!-- Таблица -->
    <div v-if="viewOnly && editingPlanId && !showFullPlan && items.length"></div>
    <div v-else-if="isViewer && !viewOnly && !editingPlanId"></div>
    <div v-else-if="!supplier" style="text-align:center;padding:40px;color:var(--text-muted);">Выберите поставщика</div>
    <div v-else-if="suppLoading" style="text-align:center;padding:40px;"><BurgerSpinner text="Загрузка..." /></div>
    <div v-else-if="!items.length" style="text-align:center;padding:40px;color:var(--text-muted);">Нет товаров у «{{ supplier }}»</div>
    <div v-else class="order-table-wrapper" :class="{ 'plan-compact': compactPlan }">
      <table class="order-table plan-table">
        <thead>
          <tr>
            <th class="plan-th-name">Товар</th>
            <th>{{ compactPlan ? 'Расх.' : consumptionColumnLabel }} ({{ unitLabel }})</th>
            <th v-if="showSales" class="plan-th-sales">{{ compactPlan ? 'Реал.' : 'Реализация' }}<br v-if="!compactPlan"><small v-if="!compactPlan" style="font-weight:400;opacity:0.7;">({{ unitLabel }})</small></th>
            <th>{{ compactPlan ? 'Склад' : 'Склад' }} ({{ unitLabel }})</th>
            <th>{{ compactPlan ? 'Пост.' : 'У постав.' }} ({{ unitLabel }})</th>
            <th class="plan-th-trend">Тренд</th>
            <th v-if="!currentWeekHeaders.length" class="plan-th-reserve">Запас<br v-if="!compactPlan"><small v-if="!compactPlan" style="font-weight:400;opacity:0.7;">дней</small></th>
            <!-- Текущие недели -->
            <th v-for="h in currentWeekHeaders" :key="'cw-' + h.label" class="plan-th-current" :title="h.sublabel">
              {{ h.label }}<br v-if="!compactPlan"><small v-if="!compactPlan" style="font-weight:400;opacity:0.7;">{{ h.sublabel }}</small>
            </th>
            <!-- Будущие периоды -->
            <th v-for="h in periodHeaders" :key="'fp-' + h.label" class="plan-th-month" :title="compactPlan ? h.label + ' ' + h.sublabel : ''">
              {{ h.label }}<br v-if="!compactPlan"><small v-if="!compactPlan" style="font-weight:400;opacity:0.7;">{{ h.sublabel }}</small>
            </th>
            <th class="plan-th-total">Итого</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="{ item, idx } in filteredItems" :key="item.sku || idx" :class="{ 'has-order': itemHasOrder(item), 'item-hidden': item._hidden, 'item-excluded': item._excluded }">
            <td class="plan-td-name" style="text-align:left;" :title="compactPlan ? planMetaTooltip(item) : ''" @dblclick="openProductEdit(item)">
              <div style="font-weight:600;color:var(--text);display:flex;align-items:center;gap:4px;" :style="compactPlan ? 'font-size:12px' : 'font-size:13px'">
                <span><b v-if="item.sku" style="color:var(--bk-orange);margin-right:4px;">{{ item.sku }}</b>{{ item.name }}</span>
                <span v-if="item._hidden" class="hidden-badge">скрыта</span>
                <button v-if="!viewOnly" class="plan-exclude-btn" :class="{ 'is-excluded': item._excluded }" @click.stop="toggleExclude(idx)" :title="item._excluded ? 'Включить в расчёт' : 'Исключить из расчёта'">
                  <BkIcon :name="item._excluded ? 'eyeOff' : 'eye'" size="xs"/>
                </button>
              </div>
              <div v-if="!compactPlan" style="font-size:11px;color:var(--text-muted);font-weight:500;">{{ item.qtyPerBox }} {{ item.unitOfMeasure || 'шт' }}/кор{{ item.boxesPerPallet ? ' · ' + item.boxesPerPallet + ' кор/пал' : '' }}{{ item.multiplicity > 1 ? ' · кратн.' + item.multiplicity : '' }}</div>
            </td>
            <td class="plan-td-input"><input type="number" class="plan-calc-input" :value="item.monthlyConsumption || ''" :class="{ 'consumption-warning': item._cw }" :title="item._ct || ''" @change="e => onInput(idx, 'consumption', e.target.value)" @focus="e => onCalcFocus(e, idx, 'consumption')" @keydown="e => onCalcKeydown(e, idx, 'consumption')" :disabled="viewOnly" placeholder="0"/></td>
            <td v-if="showSales" class="plan-td-sales" :title="salesMap[item.sku] ? salesMap[item.sku].group + ': ' + nf.format(salesMap[item.sku].total) + ' шт за ' + salesMap[item.sku].days + ' дн' : ''">
              <span v-if="salesMap[item.sku]">{{ displaySales(item, salesMap[item.sku].total) }}</span>
              <span v-else class="plan-result-zero">—</span>
            </td>
            <td class="plan-td-input"><input type="number" class="plan-calc-input" :value="displayStock(item, 'stockOnHand')" @change="e => onInput(idx, 'stock', e.target.value)" @focus="e => onCalcFocus(e, idx, 'stock')" @keydown="e => onCalcKeydown(e, idx, 'stock')" :disabled="viewOnly" placeholder="0"/></td>
            <td class="plan-td-input"><input type="number" class="plan-calc-input" :value="displayStock(item, 'stockAtSupplier')" @change="e => onInput(idx, 'supplierStock', e.target.value)" @focus="e => onCalcFocus(e, idx, 'supplierStock')" @keydown="e => onCalcKeydown(e, idx, 'supplierStock')" :disabled="viewOnly" placeholder="0"/></td>
            <td class="plan-td-trend">
              <button v-if="trendMap[item.sku]" class="trend-btn" :class="trendMap[item.sku].pct > 0 ? 'trend-up' : 'trend-down'"
                :title="(trendMap[item.sku].group || '') + ': ' + trendMap[item.sku].cur + ' vs ' + trendMap[item.sku].prev + ' (14д к 14д)'"
                @click.stop="openTrendPopup(idx, item.sku, $event)">
                {{ trendMap[item.sku].pct > 0 ? '↑' : '↓' }}{{ Math.abs(trendMap[item.sku].pct) }}%
              </button>
              <span v-else class="plan-result-zero">—</span>
            </td>
            <td v-if="!currentWeekHeaders.length" class="plan-td-reserve" :class="reserveDaysClass(item)">{{ reserveDaysText(item) }}</td>
            <!-- Текущие недели: транзит + дни запаса -->
            <td v-for="(cw, wi) in (item._cwData || [])" :key="'cw-' + wi" class="plan-td-current" :class="cwDaysClass(cw.daysRemaining)">
              <input type="number" class="plan-calc-input cw-transit-input" :value="item.transit?.[wi]?.qty || ''" @change="e => onTransitInput(idx, wi, e.target.value)" :disabled="viewOnly" placeholder="0" title="Транзит"/>
              <div class="cw-days" v-if="cw.daysRemaining !== null">
                <template v-if="cw.stockAfter >= 0">{{ cw.daysRemaining }} дн</template>
                <template v-else>{{ cwDeficitDisplay(cw.stockAfter, item) }}</template>
              </div>
              <div class="cw-days" v-else>—</div>
            </td>
            <!-- Период 0 — readonly (только без даты планирования) -->
            <td v-if="item.plan.length && !planningDateStr" class="plan-td-result" :class="{ 'plan-has-value': item.plan[0]?.orderBoxes > 0 }" :title="compactPlan && item.plan[0]?.orderBoxes > 0 ? nf.format(item.plan[0].orderUnits) + ' ' + item.unitOfMeasure : ''">
              <template v-if="item.plan[0]?.orderBoxes > 0">
                <span class="plan-result-value">{{ item.plan[0].orderBoxes }} кор</span>
                <span v-if="!compactPlan" class="plan-result-sub">{{ (item.multiplicity || 1) > 1 ? Math.ceil(item.plan[0].orderBoxes / item.multiplicity) + ' физ · ' : '' }}{{ nf.format(item.plan[0].orderUnits) }} {{ item.unitOfMeasure }}</span>
              </template>
              <span v-else class="plan-result-zero">—</span>
              <div v-if="item.plan[0]?.daysRemaining > 1" class="cw-days plan-period-days" :class="cwDaysClass(item.plan[0].daysRemaining)">{{ item.plan[0].daysRemaining }} дн</div>
            </td>
            <!-- Редактируемые периоды: с даты планирования — все (0+), без неё — с 1-го -->
            <td v-for="m in editablePeriodIndices" :key="m" class="plan-td-result"
              :class="{ 'plan-has-value': item.plan[m]?.orderBoxes > 0, 'plan-cell-locked': item.plan[m]?.locked, 'plan-cell-trend': item.plan[m]?.trendPct }"
              :title="compactPlan && item.plan[m]?.orderBoxes > 0 ? nf.format(item.plan[m].orderUnits) + ' ' + item.unitOfMeasure : ''"
              @dblclick="startEdit(idx, m, $event)">
              <template v-if="!editingCell || editingCell.idx !== idx || editingCell.m !== m">
                <template v-if="item.plan[m]?.orderBoxes > 0">
                  <span class="plan-result-value" :class="{ 'plan-cell-locked': item.plan[m]?.locked }">
                    {{ item.plan[m].orderBoxes }} кор
                    <span v-if="!viewOnly && item.boxesPerPallet && item.plan[m].orderBoxes % (item.boxesPerPallet * (item.multiplicity || 1)) !== 0" class="plan-pallet-period"
                      :title="`До ${Math.ceil(item.plan[m].orderBoxes / (item.boxesPerPallet * (item.multiplicity || 1)))} пал (${Math.ceil(item.plan[m].orderBoxes / (item.boxesPerPallet * (item.multiplicity || 1))) * item.boxesPerPallet * (item.multiplicity || 1)} кор)`"
                      @click.stop="roundToPallet(idx, m)">⬆</span>
                    <span v-if="!viewOnly && (item.plan[m]?.locked || item.plan[m]?.trendPct)" class="plan-reset-cell" title="Сбросить" @click.stop="resetCell(idx, m)"><BkIcon name="close" size="sm"/></span>
                  </span>
                  <span v-if="!compactPlan" class="plan-result-sub">{{ (item.multiplicity || 1) > 1 ? Math.ceil(item.plan[m].orderBoxes / item.multiplicity) + ' физ · ' : '' }}{{ nf.format(item.plan[m].orderUnits) }} {{ item.unitOfMeasure }}</span>
                </template>
                <span v-else class="plan-result-zero">—</span>
              </template>
              <!-- Inline edit input (#6 fix) -->
              <input v-else type="text" class="plan-edit-input" :value="item.plan[m]?.orderBoxes || 0"
                @keydown="e => onPeriodKeydown(e, idx, m)"
                @focus="e => onPeriodFocus(e, idx, m)"
                @blur="planCalc.onBlur(); applyEdit(idx, m, $event.target.value)"
                ref="editInputRef"
                style="width:60px;text-align:center;font-size:13px;font-weight:700;padding:2px 4px;border:2px solid var(--bk-orange);border-radius:4px;"/>
              <div v-if="item.plan[m]?.existingBoxes > 0" class="plan-existing-order" :title="'Заказ в пути: ' + item.plan[m].existingBoxes + ' кор'">📦 {{ item.plan[m].existingBoxes }}</div>
              <div v-if="item.plan[m]?.daysRemaining > 1" class="cw-days plan-period-days" :class="cwDaysClass(item.plan[m].daysRemaining)">{{ item.plan[m].daysRemaining }} дн</div>
            </td>
            <td class="plan-td-total" :class="{ 'plan-has-value': itemTotalBoxes(item) > 0 }" :title="planItemPriceTooltip(item)">
              <template v-if="itemTotalBoxes(item) > 0">
                <span class="plan-total-boxes">{{ nf.format(itemTotalBoxes(item)) }} кор</span>
                <span v-if="!compactPlan" class="plan-total-units">{{ (item.multiplicity || 1) > 1 ? nf.format(Math.ceil(itemTotalBoxes(item) / item.multiplicity)) + ' физ · ' : '' }}{{ nf.format(itemTotalUnits(item)) }} {{ item.unitOfMeasure || 'шт' }}</span>
                <span v-if="!compactPlan && planItemSum(item) > 0" class="plan-total-price">{{ planItemSum(item).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }} BYN</span>
              </template>
              <span v-else class="plan-result-zero">—</span>
            </td>
          </tr>
          <!-- Строка добавления товара -->
          <tr v-if="showAddRow" class="add-product-row">
            <td :colspan="(currentWeekHeaders.length ? 5 : 6) + (showSales ? 1 : 0) + currentWeekHeaders.length + periodHeaders.length + 1" style="padding:2px 8px;text-align:left;">
              <select class="add-product-select" @change="addProduct">
                <option value="">+ Добавить товар…</option>
                <option v-for="p in availableToAdd" :key="p.sku" :value="p.sku">{{ p.sku }} — {{ p.name }}{{ p.is_active === 0 ? ' (скрыта)' : '' }}</option>
              </select>
            </td>
          </tr>
        </tbody>
        <tfoot v-if="truckEnabled && items.length">
          <!-- Итого паллет -->
          <tr class="plan-truck-row">
            <td :colspan="(currentWeekHeaders.length ? 5 : 6) + (showSales ? 1 : 0) + currentWeekHeaders.length" class="plan-truck-label">Паллеты</td>
            <td v-for="(h, pi) in periodHeaders" :key="'pal-' + pi" class="plan-truck-cell">
              <span v-if="periodPallets(pi) > 0" class="plan-truck-pallets">{{ periodPallets(pi) }} пал</span>
              <span v-else class="plan-result-zero">—</span>
            </td>
            <td></td>
          </tr>
          <!-- Загрузка машин -->
          <tr class="plan-truck-row plan-truck-row-detail">
            <td :colspan="(currentWeekHeaders.length ? 5 : 6) + (showSales ? 1 : 0) + currentWeekHeaders.length" class="plan-truck-label">
              Машина ({{ truckPallets }} пал)
            </td>
            <td v-for="(h, pi) in periodHeaders" :key="'truck-' + pi" class="plan-truck-cell">
              <template v-if="periodTruckInfo(pi)">
                <div v-for="t in periodTruckInfo(pi).trucks" :key="t.number" class="plan-truck-item"
                  :class="{ 'plan-truck-full': t.percent === 100, 'plan-truck-low': t.percent < 50 }">
                  <span class="plan-truck-num">{{ periodTruckInfo(pi).truckCount > 1 ? t.number + ':' : '' }}</span>
                  <span class="plan-truck-val">{{ t.pallets }}/{{ truckPallets }}</span>
                  <span class="plan-truck-pct">({{ t.percent }}%)</span>
                </div>
              </template>
              <span v-else class="plan-result-zero">—</span>
            </td>
            <td></td>
          </tr>
          <!-- Кнопка "Создать заказ" -->
          <tr class="plan-truck-row plan-order-btn-row">
            <td :colspan="(currentWeekHeaders.length ? 5 : 6) + (showSales ? 1 : 0) + currentWeekHeaders.length" class="plan-truck-label"></td>
            <td v-for="(h, pi) in periodHeaders" :key="'ord-' + pi" class="plan-truck-cell">
              <button v-if="(planningDateStr || pi > 0) && periodTotalBoxes(pi) > 0 && !viewOnly" class="btn small plan-create-order-btn" @click="createOrderFromPeriod(pi)" title="Создать заказ из этого периода">
                <BkIcon name="order" size="sm"/> Заказ
              </button>
            </td>
            <td></td>
          </tr>
        </tfoot>
        <tfoot v-else-if="items.length && !viewOnly">
          <tr class="plan-order-btn-row">
            <td :colspan="(currentWeekHeaders.length ? 5 : 6) + (showSales ? 1 : 0) + currentWeekHeaders.length" class="plan-truck-label"></td>
            <td v-for="(h, pi) in periodHeaders" :key="'ord2-' + pi" class="plan-truck-cell">
              <button v-if="(planningDateStr || pi > 0) && periodTotalBoxes(pi) > 0" class="btn small plan-create-order-btn" @click="createOrderFromPeriod(pi)" title="Создать заказ из этого периода">
                <BkIcon name="order" size="sm"/> Заказ
              </button>
            </td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <!-- Итого по ценам -->
    <div v-if="Object.keys(planPriceMap).length && planTotalSum > 0" class="plan-price-summary">
      Примерная стоимость заказов: <b>{{ planTotalSum.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }} BYN</b>
      <span style="color:var(--text-muted);font-size:11px;margin-left:8px;">({{ planPricedCount }} из {{ planTotalCount }} позиций с ценой)</span>
    </div>

    <!-- Мобильный карточный вид планирования -->
    <div v-if="items.length" class="plan-mobile-cards">
      <div v-for="{ item, idx } in filteredItems" :key="'mob-' + (item.sku || idx)" class="plan-mob-card" :class="{ 'plan-mob-has-order': itemHasOrder(item), 'item-hidden': item._hidden, 'item-excluded': item._excluded }">
        <div class="plan-mob-name" style="display:flex;align-items:center;gap:6px;">
          <span><b v-if="item.sku">{{ item.sku }}</b> {{ item.name }}</span>
          <span v-if="item._hidden" class="hidden-badge">скрыта</span>
          <button v-if="!viewOnly" class="plan-exclude-btn" :class="{ 'is-excluded': item._excluded }" @click.stop="toggleExclude(idx)" :title="item._excluded ? 'Включить в расчёт' : 'Исключить из расчёта'">
            <BkIcon :name="item._excluded ? 'eyeOff' : 'eye'" size="xs"/>
          </button>
        </div>
        <div class="plan-mob-inputs">
          <div class="plan-mob-field">
            <span class="plan-mob-label">Расход</span>
            <input type="number" class="plan-calc-input" :value="item.monthlyConsumption || ''" @change="e => onInput(idx, 'consumption', e.target.value)" @focus="e => onCalcFocus(e, idx, 'consumption')" @keydown="e => onCalcKeydown(e, idx, 'consumption')" :disabled="viewOnly" placeholder="0"/>
          </div>
          <div class="plan-mob-field">
            <span class="plan-mob-label">Склад</span>
            <input type="number" class="plan-calc-input" :value="displayStock(item, 'stockOnHand')" @change="e => onInput(idx, 'stock', e.target.value)" @focus="e => onCalcFocus(e, idx, 'stock')" @keydown="e => onCalcKeydown(e, idx, 'stock')" :disabled="viewOnly" placeholder="0"/>
          </div>
          <div class="plan-mob-field">
            <span class="plan-mob-label">У пост.</span>
            <input type="number" class="plan-calc-input" :value="displayStock(item, 'stockAtSupplier')" @change="e => onInput(idx, 'supplierStock', e.target.value)" @focus="e => onCalcFocus(e, idx, 'supplierStock')" @keydown="e => onCalcKeydown(e, idx, 'supplierStock')" :disabled="viewOnly" placeholder="0"/>
          </div>
          <div v-if="trendMap[item.sku]" class="plan-mob-field plan-mob-field-ro">
            <span class="plan-mob-label">Тренд</span>
            <span class="trend-btn" :class="trendMap[item.sku].pct > 0 ? 'trend-up' : 'trend-down'"
              :title="trendMap[item.sku].group + ': ' + trendMap[item.sku].cur + ' vs ' + trendMap[item.sku].prev">
              {{ trendMap[item.sku].pct > 0 ? '↑' : '↓' }}{{ Math.abs(trendMap[item.sku].pct) }}%
            </span>
          </div>
          <div class="plan-mob-field plan-mob-field-ro">
            <span class="plan-mob-label">Запас</span>
            <span class="plan-mob-reserve" :class="reserveDaysClass(item)">{{ reserveDaysText(item) }}</span>
          </div>
        </div>
        <div class="plan-mob-periods" v-if="item.plan && item.plan.length">
          <div class="plan-mob-period-title">Периоды заказа</div>
          <div class="plan-mob-period-grid">
            <div v-for="(p, pi) in item.plan" :key="pi" class="plan-mob-period"
              :class="{ 'plan-mob-period-has': p.orderBoxes > 0, 'plan-mob-period-editing': editingCell && editingCell.idx === idx && editingCell.m === pi }"
              @click="pi > 0 && !viewOnly && startMobEdit(idx, pi)">
              <span class="plan-mob-period-label">{{ periodHeaders[pi]?.label || '' }}</span>
              <template v-if="editingCell && editingCell.idx === idx && editingCell.m === pi">
                <input type="text" class="plan-mob-edit-input" :value="p.orderBoxes || 0"
                  @keydown="e => onPeriodKeydown(e, idx, pi)"
                  @focus="e => onPeriodFocus(e, idx, pi)"
                  @blur="planCalc.onBlur(); applyEdit(idx, pi, $event.target.value)"
                  @click.stop />
              </template>
              <template v-else>
                <span class="plan-mob-period-val" v-if="p.orderBoxes > 0">{{ p.orderBoxes }}</span>
                <span class="plan-mob-period-val plan-mob-period-zero" v-else>—</span>
              </template>
            </div>
          </div>
        </div>
        <div class="plan-mob-total" v-if="itemTotalBoxes(item) > 0">
          Итого: <b>{{ nf.format(itemTotalBoxes(item)) }} кор</b>
          <span class="plan-mob-total-units">({{ nf.format(itemTotalUnits(item)) }} {{ item.unitOfMeasure || 'шт' }})</span>
        </div>
      </div>
    </div>

    <!-- Кнопки завершения -->
    <div v-if="items.length && (!viewOnly || showFullPlan || !editingPlanId)" class="toolbar-row toolbar-finish" style="margin-top:12px;" v-show="!isFullscreen">
      <div class="toolbar-spacer"></div>
      <button v-if="!isViewer" class="btn primary" @click="savePlan" :disabled="!itemsWithPlan.length || viewOnly"><BkIcon name="save" size="sm"/> {{ editingPlanId ? 'Обновить план' : 'Сохранить план' }}</button>
      <button class="btn" @click="copyPlanToClipboard" :disabled="!itemsWithPlan.length"><BkIcon name="history" size="sm"/> Копировать</button>
      <button class="btn" @click="exportExcel" :disabled="!itemsWithPlan.length"><BkIcon name="excel" size="sm"/> Excel</button>
    </div>
    <div v-if="planDraftStatusText && items.length && !viewOnly && !editingPlanId" class="draft-status">{{ planDraftStatusText }}</div>

    <!-- Модалки -->
    <EditCardModal v-if="editCardModal.show" :product="editCardModal.product" @close="editCardModal.show = false" @saved="onCardSaved"/>
    <ConfirmModal v-if="confirmModal.show" :title="confirmModal.title" :message="confirmModal.message"
      @confirm="onConfirmOk"
      @cancel="onConfirmCancel"/>
    <AnalogMergeModal v-if="analogMergeModal.show" :merges="analogMergeModal.merges" @apply="onAnalogApply" @skip="onAnalogSkip"/>

    <!-- Модалка сохранения плана -->
    <Teleport to="body">
      <div v-if="showSaveModal" class="modal" @click.self="tryCloseSaveModal">
        <div class="modal-box" style="max-width:420px;">
          <div class="modal-header">
            <h2><BkIcon name="save" size="sm"/> {{ editingPlanId ? 'Обновить план' : 'Сохранить план' }}</h2>
            <button class="modal-close" @click="tryCloseSaveModal"><BkIcon name="close" size="sm"/></button>
          </div>
          <div style="margin-bottom:16px;color:#555;font-size:14px;">
            <div>Поставщик: <b>{{ supplier }}</b></div>
            <div>Позиций с заказом: <b>{{ itemsWithPlan.length }}</b></div>
            <div>Период: <b>{{ periodCount }} {{ periodType === 'weeks' ? 'нед.' : 'мес.' }}</b></div>
            <div>Расход за: <b>{{ consumptionPeriodDays }} дн.</b></div>
          </div>
          <div v-if="editingPlanId" style="margin-bottom:12px;padding:8px 12px;background:#FFF3E0;border-radius:6px;font-size:13px;color:#E65100;">
            <BkIcon name="warning" size="sm"/> Существующий план будет перезаписан
          </div>
          <label style="display:block;margin-bottom:8px;font-size:13px;font-weight:600;color:#555;">Примечание (необязательно)</label>
          <input v-model="saveNote" type="text" placeholder="Например: согласовано с поставщиком..." style="width:100%;margin-bottom:16px;" ref="saveNoteInput" @keydown.enter="confirmSave" @keydown.esc="tryCloseSaveModal"/>
          <div class="actions" style="display:flex;gap:8px;">
            <button class="btn primary" @click="confirmSave" :disabled="saving"><BurgerSpinner v-if="saving" size="xs" /><span>{{ saving ? 'Сохранение...' : (editingPlanId ? 'Обновить план' : 'Сохранить план') }}</span></button>
            <button class="btn secondary" @click="tryCloseSaveModal" :disabled="saving">Отмена</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Log Modal -->
    <Teleport to="body">
      <div v-if="logModal.show" class="modal">
        <div class="modal-box" style="max-width:560px;">
          <div class="modal-header">
            <h2><BkIcon name="note" size="sm"/> История изменений</h2>
            <button class="modal-close" @click="logModal.show = false"><BkIcon name="close" size="sm"/></button>
          </div>
          <div style="max-height:450px;overflow-y:auto;padding:0 20px 16px;">
            <div v-if="logModal.loading" style="text-align:center;padding:24px;color:var(--text-muted);"><BurgerSpinner text="Загрузка..." /></div>
            <div v-else-if="!logModal.entries.length" style="text-align:center;padding:24px;color:var(--text-muted);font-size:13px;">Нет записей</div>
            <div v-else class="log-entries">
              <div v-for="log in logModal.entries" :key="log.id" class="log-entry">
                <div class="log-entry-head">
                  <span class="log-badge" :class="logBadgeClass(log.action)">{{ logBadgeLabel(log.action) }}</span>
                  <span class="log-author">{{ log.user_name || '—' }}</span>
                  <span class="log-date">{{ formatLogDate(log.created_at) }}</span>
                </div>
                <div v-if="log.details?.note" class="log-note-line">{{ log.details.note }}</div>
                <div v-if="log.details?.items_count" class="log-meta">{{ log.details.items_count }} позиций</div>
                <div v-if="log.details?.changes?.length" class="log-changes">
                  <span v-for="(c, ci) in log.details.changes" :key="ci" class="log-ch-chip" :class="{ 'log-ch-add': c.type==='added', 'log-ch-del': c.type==='removed', 'log-ch-upd': c.type==='changed' }">
                    <template v-if="c.type === 'added'">+ {{ c.item }} {{ c.boxes }}кор</template>
                    <template v-else-if="c.type === 'removed'">− {{ c.item }} {{ c.boxes }}кор</template>
                    <template v-else>{{ c.item }}: {{ c.diffs?.join(', ') }}</template>
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
    <Teleport to="body">
      <div v-if="trendPopup && trendMap[items[trendPopup.idx]?.sku]" class="plan-trend-popup" :style="trendPopupStyle" @click.stop>
        <div class="plan-trend-popup-title">Применить тренд {{ trendMap[items[trendPopup.idx].sku].pct > 0 ? '+' : '' }}{{ trendMap[items[trendPopup.idx].sku].pct }}%</div>
        <div class="plan-trend-popup-info">{{ trendMap[items[trendPopup.idx].sku].group }}: {{ trendMap[items[trendPopup.idx].sku].cur }} vs {{ trendMap[items[trendPopup.idx].sku].prev }} (14д к 14д)</div>
        <div class="plan-trend-popup-btns">
          <button v-for="n in trendPeriodOptions" :key="n" class="plan-trend-apply-btn" @click="applyTrendToPeriods(trendPopup.idx, n)">
            {{ n === editablePeriodIndices.length ? 'Все' : n }} {{ n === 1 ? 'период' : n < 5 ? 'периода' : 'периодов' }}
          </button>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, defineAsyncComponent, onMounted, onBeforeUnmount, watch, nextTick, triggerRef, inject } from 'vue';
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router';
import { db } from '@/lib/apiClient.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useSupplierStore, loadProductsForSupplier } from '@/stores/supplierStore.js';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import { useToastStore } from '@/stores/toastStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { useDraftStore } from '@/stores/draftStore.js';
import { getQpb, getMultiplicity, copyToClipboard, getEntityGroup, applyEntityGroupFilter, toLocalDateStr } from '@/lib/utils.js';
import { DEFAULT_ENTITY, getEntityGroupCode } from '@/lib/legalEntities.js';
import { importFromFile, applyAnalogMerges, loadFromAnalysis } from '@/lib/importStock.js';
import { useCalculator } from '@/lib/useCalculator.js';
import { useConfirm } from '@/composables/useConfirm.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import ViewerBanner from '@/components/ViewerBanner.vue';

const EditCardModal = defineAsyncComponent(() => import('@/components/modals/EditCardModal.vue'));
const ConfirmModal = defineAsyncComponent(() => import('@/components/modals/ConfirmModal.vue'));
const AnalogMergeModal = defineAsyncComponent(() => import('@/components/modals/AnalogMergeModal.vue'));

const route = useRoute();
const router = useRouter();
const orderStore = useOrderStore();
const supplierStore = useSupplierStore();
const toast = useToastStore();
const userStore = useUserStore();
const draftStore = useDraftStore();

const PLAN_PRODUCT_FIELDS = 'id, sku, name, legal_entity, legal_entity_group, unit_of_measure, qty_per_box, boxes_per_pallet, multiplicity, analog_group, is_active';

const nf = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 });

const isViewer = computed(() => !userStore.hasAccess('planning', 'edit'));
const supplier = ref('');
const setTabTitle = inject('setTabTitle', () => {});
watch(supplier, (s) => setTabTitle(s ? 'План: ' + s : 'Планирование'));
const periodFrequency = ref('w1');
const periodCountInput = ref(4);
const periodValue = computed(() => periodFrequency.value.charAt(0) + periodCountInput.value);
const startDateStr = ref(toLocalDateStr(new Date()));
const planningDateStr = ref('');
const inputUnit = ref('boxes');
const consumptionPeriodDays = ref(30);
const items = ref([]);
const trendMap = ref({});
const salesMap = ref({}); // sku -> { total, daily, group }
const showSales = ref(false);
const suppLoading = ref(false);
const settingsExpanded = ref(false);
const load1cLoading = ref(false);
const fillLoading = ref(false);
const editingPlanId = ref(null);
const viewOnly = ref(false);
const showFullPlan = ref(false);
const expandedSummaryPeriods = ref({});
function toggleSummaryPeriod(pi) {
  expandedSummaryPeriods.value[pi] = !expandedSummaryPeriods.value[pi];
}
const logModal = ref({ show: false, loading: false, entries: [] });
const { confirmModal, confirm: confirmAction, onConfirm: onConfirmOk, onCancel: onConfirmCancel } = useConfirm();
const editCardModal = ref({ show: false, product: null });

// Статус автосохранения черновика плана
const planDraftTick = ref(0);
let planDraftTickTimer = null;
const planDraftStatusText = computed(() => {
  planDraftTick.value;
  const t = draftStore.lastPlanSaved;
  if (!t) return '';
  const diff = Math.floor((Date.now() - t.getTime()) / 1000);
  if (diff < 10) return 'Черновик сохранён';
  if (diff < 60) return 'Черновик сохранён только что';
  const mins = Math.floor(diff / 60);
  if (mins === 1) return 'Черновик сохранён 1 мин. назад';
  if (mins < 60) return `Черновик сохранён ${mins} мин. назад`;
  return '';
});
const analogMergeModal = ref({ show: false, merges: [] });
const editingCell = ref(null);
const showSaveModal = ref(false);
const saveNote = ref('');
const saveNoteInput = ref(null);
let _saveNoteInitial = '';

function tryCloseSaveModal() {
  if (saveNote.value.trim() !== _saveNoteInitial.trim()) {
    confirmAction('Закрыть без сохранения?', 'Введённые данные будут потеряны.').then(ok => {
      if (ok) showSaveModal.value = false;
    });
    return;
  }
  showSaveModal.value = false;
}
const saving = ref(false); // { idx, m } for inline edit (#6)
const isFullscreen = ref(false);
const compactPlan = ref(localStorage.getItem('bk_compact_plan') === '1');
const truckEnabled = ref(false);
const truckPallets = ref(32);
// Санитизируем ввод вместимости — иначе пользователь мог стереть поле
// или ввести 0, в шапке показывалось «(0 пал)», но в расчёте подменялось
// на 32 → дезинформация.
watch(truckPallets, (v) => {
  if (!Number.isFinite(v) || v < 1) { truckPallets.value = 1; return; }
  if (v > 100) { truckPallets.value = 100; return; }
});
const hideExcluded = ref(false);
const existingOrders = ref([]); // заказы в пути (без received_at) для этого поставщика
const existingOrdersLoading = ref(false);
let _prevPlanItems = null;
const _loadedCreatedBy = ref(null);
const _loadedNote = ref('');
const _loadedUpdatedAt = ref(null);

// ─── Фильтр и добавление ──────────────────────────────────────────────────
const filterQuery           = ref('');
const allSupplierProducts   = ref([]);

const excludedCount = computed(() => items.value.filter(i => i._excluded).length);

const filteredItems = computed(() => {
  const q = (filterQuery.value || '').trim().toLowerCase();
  const hide = hideExcluded.value;
  return items.value.reduce((acc, item, idx) => {
    if (hide && item._excluded) return acc;
    if (q) {
      const haystack = `${item.sku || ''} ${item.name || ''}`.toLowerCase();
      if (!haystack.includes(q)) return acc;
    }
    acc.push({ item, idx });
    return acc;
  }, []);
});

const availableToAdd = computed(() => {
  const existingSkus = new Set(items.value.map(i => i.sku).filter(Boolean));
  return allSupplierProducts.value.filter(p => !existingSkus.has(p.sku));
});

const showAddRow = computed(() => {
  if (!supplier.value || viewOnly.value) return false;
  return availableToAdd.value.length > 0;
});

async function loadSupplierProducts() {
  const sup = supplier.value;
  if (!sup) { allSupplierProducts.value = []; return; }
  try {
    allSupplierProducts.value = await loadProductsForSupplier(sup, orderStore.settings.legalEntity, PLAN_PRODUCT_FIELDS);
  } catch { allSupplierProducts.value = []; }
}

let _vTimer = null;
let _validationCache = null;
let _appliedAnalogs = new Map(); // SKU товара → Set<SKU применённых аналогов>

watch(supplier, () => { _validationCache = null; loadSupplierProducts(); }, { immediate: true });

// ─── Calculator for plan inputs (#3) ──────────────────────────────────────
let _activeCalcIdx = null;
let _activeCalcField = null;
const planCalc = useCalculator((val) => {
  if (_activeCalcIdx !== null && _activeCalcField) {
    applyCalcResult(_activeCalcIdx, _activeCalcField, val);
  }
});

function applyCalcResult(idx, field, val) {
  const item = items.value[idx]; if (!item) return;
  // Period cell calculator result
  if (typeof field === 'object' && field.type === 'period') {
    applyEdit(idx, field.m, val);
    return;
  }
  const qpb = getQpb(item);
  if (field === 'consumption') { item.monthlyConsumption = val; triggerValidation(); }
  else if (field === 'stock') { item.stockOnHand = inputUnit.value === 'boxes' ? val * qpb : val; }
  else if (field === 'supplierStock') { item.stockAtSupplier = inputUnit.value === 'boxes' ? val * qpb : val; }
  recalcItem(idx, 0); _savePlanDraft();
}

function onCalcFocus(e, idx, field) {
  _activeCalcIdx = idx; _activeCalcField = field;
  planCalc.onFocus(e);
}

function onCalcKeydown(e, idx, field) {
  _activeCalcIdx = idx; _activeCalcField = field;
  // If calculator has pending op, let it handle operator keys, digits, Enter, Escape
  if (planCalc.hasPendingOp()) {
    if (['+', '-', '*', '/'].includes(e.key) || /[0-9.]/.test(e.key) || e.key === 'Enter' || e.key === 'Escape') {
      planCalc.onKeydown(e);
      return;
    }
  } else if (['+', '-', '*', '/'].includes(e.key)) {
    planCalc.onKeydown(e);
    return;
  }
  // Arrow nav between plan inputs — навигация по видимым строкам
  if (['ArrowDown', 'ArrowUp'].includes(e.key)) {
    e.preventDefault();
    const colMap = { consumption: 0, stock: 1, supplierStock: 2 };
    const col = colMap[field] ?? 0;
    const currentRow = e.target.closest('tr');
    const targetRow = e.key === 'ArrowDown' ? currentRow?.nextElementSibling : currentRow?.previousElementSibling;
    if (targetRow) {
      const inputs = targetRow.querySelectorAll('.plan-calc-input');
      if (inputs?.[col]) { inputs[col].focus(); inputs[col].select(); }
    }
  }
  if (e.key === 'Enter') {
    e.preventDefault();
    onInput(idx, field, e.target.value);
    const colMap = { consumption: 0, stock: 1, supplierStock: 2 };
    const col = colMap[field] ?? 0;
    nextTick(() => {
      const currentRow = e.target.closest('tr');
      const targetRow = currentRow?.nextElementSibling;
      if (targetRow) {
        const inputs = targetRow.querySelectorAll('.plan-calc-input');
        if (inputs?.[col]) { inputs[col].focus(); inputs[col].select(); }
      }
    });
  }
}

// ─── Undo/Redo (#1) ──────────────────────────────────────────────────────
const undoStack = ref([]);
const redoStack = ref([]);
const canUndo = computed(() => undoStack.value.length > 0);
const canRedo = computed(() => redoStack.value.length > 0);

// Snapshot планирования: stringify ~200 строк × 12 периодов на каждое нажатие
// в калькуляторе раньше съедал кадр. Решения: дебаунс 500 мс +
// JSON.stringify оставляем (даёт независимый текстовый снимок и легко
// парсится обратно — структура item.plan содержит вложенные объекты).
let _snapshotTimer = null;
function _doSnapshot() {
  undoStack.value.push(JSON.stringify(items.value.map(i => ({ ...i, plan: [...i.plan] }))));
  if (undoStack.value.length > 30) undoStack.value.shift();
  redoStack.value = [];
}
function snapshot() {
  if (_snapshotTimer) clearTimeout(_snapshotTimer);
  _snapshotTimer = setTimeout(() => { _snapshotTimer = null; _doSnapshot(); }, 500);
}
function _flushSnapshot() {
  if (_snapshotTimer) {
    clearTimeout(_snapshotTimer);
    _snapshotTimer = null;
    _doSnapshot();
  }
}
function undo() {
  _flushSnapshot();
  if (!undoStack.value.length) return;
  redoStack.value.push(JSON.stringify(items.value.map(i => ({ ...i, plan: [...i.plan] }))));
  const data = JSON.parse(undoStack.value.pop());
  items.value = data;
  recalcAll();
}
function redo() {
  _flushSnapshot();
  if (!redoStack.value.length) return;
  undoStack.value.push(JSON.stringify(items.value.map(i => ({ ...i, plan: [...i.plan] }))));
  const data = JSON.parse(redoStack.value.pop());
  items.value = data;
  recalcAll();
}

const suppliers = computed(() => supplierStore.getSuppliersForEntity(orderStore.settings.legalEntity));
const unitLabel = computed(() => inputUnit.value === 'boxes' ? 'кор' : 'шт');
const periodLabel = computed(() => {
  const freqMap = { w1: '/нед', w2: '/2нед', m1: '/мес' };
  const freq = freqMap[periodFrequency.value] || '';
  return `${periodCountInput.value} пер. ${freq}`;
});
const startDateDisplay = computed(() => {
  const d = new Date(startDateStr.value + 'T00:00:00');
  return !isNaN(d) ? d.toLocaleDateString('ru-RU', { day:'2-digit', month:'2-digit' }) : '—';
});
function toggleSettings() {
  if (settingsExpanded.value && !supplier.value) return; // не закрывать без поставщика
  settingsExpanded.value = !settingsExpanded.value;
}

const consumptionColumnLabel = computed(() => {
  const d = consumptionPeriodDays.value;
  if (d === 30) return 'Расход/мес';
  return `Расход/${d}дн`;
});
const periodType = computed(() => periodFrequency.value.startsWith('w') ? 'weeks' : 'months');
const periodCount = computed(() => periodCountInput.value);
const startDate = computed(() => new Date(startDateStr.value + 'T00:00:00'));
const planningDate = computed(() => planningDateStr.value ? new Date(planningDateStr.value + 'T00:00:00') : null);

const _fmt = (d) => `${String(d.getDate()).padStart(2,'0')}.${String(d.getMonth()+1).padStart(2,'0')}`;
const _mn = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];

// Текущие недели: от startDate до planningDate, привязаны к пн–вс
const currentWeekHeaders = computed(() => {
  const headers = [];
  const pd = planningDate.value;
  if (!pd || pd <= startDate.value) return headers;
  let ws = new Date(startDate.value);
  while (ws < pd) {
    // Конец недели — ближайшее воскресенье (день 0)
    const dow = ws.getDay(); // 0=вс, 1=пн, ...
    let we = new Date(ws);
    we.setDate(we.getDate() + (dow === 0 ? 0 : 7 - dow)); // до воскресенья
    // Не выходим за дату планирования
    if (we >= pd) { we = new Date(pd); we.setDate(we.getDate() - 1); }
    if (we < ws) break;
    const days = Math.max(Math.round((we - ws) / 86400000) + 1, 1);
    headers.push({ label: `Тек ${headers.length + 1}`, sublabel: `${_fmt(ws)}–${_fmt(we)}`, days });
    ws = new Date(we); ws.setDate(ws.getDate() + 1);
  }
  return headers;
});

// Будущие периоды: от planningDate (или startDate если planningDate не задана)
const periodHeaders = computed(() => {
  const headers = [];
  const hasPlanningDate = planningDate.value && planningDate.value > startDate.value;
  const start = hasPlanningDate ? planningDate.value : startDate.value;
  if (periodType.value === 'weeks') {
    const dow = start.getDay(); // 0=вс, 1=пн, ...
    const isMonday = dow === 1;
    let firstFullMonday;
    if (!hasPlanningDate) {
      // Текущая неделя — всегда дополнительная колонка (не входит в periodCount)
      if (!isMonday) {
        const daysToSun = dow === 0 ? 0 : 7 - dow;
        const fwe = new Date(start); fwe.setDate(fwe.getDate() + daysToSun);
        const days = Math.max(Math.round((fwe - start) / 86400000) + 1, 1);
        headers.push({ label: 'Тек. нед', sublabel: `${_fmt(start)}–${_fmt(fwe)}`, ratio: days / 7, startDate: new Date(start) });
        firstFullMonday = new Date(fwe); firstFullMonday.setDate(firstFullMonday.getDate() + 1);
      } else {
        // Сегодня понедельник — текущая неделя полная
        const we = new Date(start); we.setDate(we.getDate() + 6);
        headers.push({ label: 'Тек. нед', sublabel: `${_fmt(start)}–${_fmt(we)}`, ratio: 1, startDate: new Date(start) });
        firstFullMonday = new Date(start); firstFullMonday.setDate(firstFullMonday.getDate() + 7);
      }
    } else {
      firstFullMonday = new Date(start);
      if (!isMonday) {
        const shift = dow === 0 ? 1 : 8 - dow;
        firstFullMonday.setDate(firstFullMonday.getDate() + shift);
      }
    }
    const freqWeeks = periodFrequency.value === 'w2' ? 2 : 1;
    const freqDays = freqWeeks * 7;
    for (let i = 0; i < periodCount.value; i++) {
      const ws = new Date(firstFullMonday); ws.setDate(ws.getDate() + i * freqDays);
      const we = new Date(ws); we.setDate(we.getDate() + freqDays - 1);
      const lbl = freqWeeks === 1 ? `Нед ${i+1}` : `Пер ${i+1}`;
      headers.push({ label: lbl, sublabel: `${_fmt(ws)}–${_fmt(we)}`, ratio: freqWeeks, startDate: new Date(ws) });
    }
  } else {
    if (!hasPlanningDate) {
      // Текущий месяц — всегда дополнительная колонка (не входит в periodCount)
      const dim = new Date(start.getFullYear(), start.getMonth() + 1, 0).getDate();
      const dl = dim - start.getDate() + 1;
      const isFirstDay = start.getDate() === 1;
      headers.push({ label: _mn[start.getMonth()], sublabel: isFirstDay ? String(start.getFullYear()) : `ост. ${dl} дн.`, ratio: dl / dim, startDate: new Date(start) });
      // periodCount полных месяцев ПОСЛЕ текущего
      for (let i = 0; i < periodCount.value; i++) {
        const d = new Date(start.getFullYear(), start.getMonth() + 1 + i, 1);
        headers.push({ label: _mn[d.getMonth()], sublabel: String(d.getFullYear()), ratio: 1, startDate: new Date(d) });
      }
    } else {
      // С датой планирования — только полные месяцы, без текущего
      const isFirstDay = start.getDate() === 1;
      const startMonth = isFirstDay ? start.getMonth() : start.getMonth() + 1;
      for (let i = 0; i < periodCount.value; i++) {
        const d = new Date(start.getFullYear(), startMonth + i, 1);
        headers.push({ label: _mn[d.getMonth()], sublabel: String(d.getFullYear()), ratio: 1, startDate: new Date(d) });
      }
    }
  }
  return headers;
});

const planningDateDisplay = computed(() => {
  if (!planningDateStr.value) return '—';
  const d = new Date(planningDateStr.value + 'T00:00:00');
  return !isNaN(d) ? d.toLocaleDateString('ru-RU', { day:'2-digit', month:'2-digit' }) : '—';
});

const editablePeriodIndices = computed(() => {
  const start = planningDateStr.value ? 0 : 1;
  const count = periodHeaders.value.length;
  const arr = [];
  for (let i = start; i < count; i++) arr.push(i);
  return arr;
});

function toggleCompactPlan() {
  compactPlan.value = !compactPlan.value;
  localStorage.setItem('bk_compact_plan', compactPlan.value ? '1' : '0');
}

function planMetaTooltip(item) {
  const parts = [];
  if (item.qtyPerBox) parts.push(item.qtyPerBox + ' ' + (item.unitOfMeasure || 'шт') + '/кор');
  if (item.boxesPerPallet) parts.push(item.boxesPerPallet + ' кор/пал');
  if (item.multiplicity > 1) parts.push('кратн.' + item.multiplicity);
  return parts.join(' · ') || '';
}

function displayStock(item, field) {
  const val = item[field]; if (val == null) return '';
  if (inputUnit.value !== 'boxes') return val;
  const qpb = getQpb(item);
  const boxes = val / qpb;
  return boxes % 1 === 0 ? boxes : +boxes.toFixed(1);
}

function displaySales(item, total) {
  if (inputUnit.value !== 'boxes') return nf.format(Math.round(total));
  const qpb = getQpb(item);
  const boxes = total / qpb;
  return boxes % 1 === 0 ? nf.format(boxes) : boxes.toFixed(1);
}

// Безопасный вычислитель арифметических выражений (без new Function)
function _safeCalc(expr) {
  const tokens = expr.match(/(\d+\.?\d*|[+\-*/()])/g);
  if (!tokens) return 0;
  // Рекурсивный парсер: expr → term (+/- term)* → factor (*/÷ factor)* → number | (expr)
  let pos = 0;
  function parseExpr() {
    let result = parseTerm();
    while (pos < tokens.length && (tokens[pos] === '+' || tokens[pos] === '-')) {
      const op = tokens[pos++];
      const right = parseTerm();
      result = op === '+' ? result + right : result - right;
    }
    return result;
  }
  function parseTerm() {
    let result = parseFactor();
    while (pos < tokens.length && (tokens[pos] === '*' || tokens[pos] === '/')) {
      const op = tokens[pos++];
      const right = parseFactor();
      result = op === '*' ? result * right : (right !== 0 ? result / right : 0);
    }
    return result;
  }
  function parseFactor() {
    if (tokens[pos] === '(') { pos++; const r = parseExpr(); if (tokens[pos] === ')') pos++; return r; }
    const n = parseFloat(tokens[pos++] || '0');
    return isNaN(n) ? 0 : n;
  }
  return parseExpr();
}

function onInput(idx, type, rawValue) {
  snapshot();
  let value = 0; const raw = rawValue.trim();
  if (/^[\d\s+\-*/().]+$/.test(raw) && raw) { try { value = _safeCalc(raw); } catch { value = parseFloat(raw) || 0; } if (!isFinite(value)) value = 0; value = Math.round(value * 100) / 100; }
  const item = items.value[idx]; if (!item) return;
  const qpb = getQpb(item);
  if (type === 'consumption') { item.monthlyConsumption = value; triggerValidation(); }
  else if (type === 'stock') { item.stockOnHand = inputUnit.value === 'boxes' ? value * qpb : value; }
  else if (type === 'supplierStock') { item.stockAtSupplier = inputUnit.value === 'boxes' ? value * qpb : value; }
  recalcItem(idx, 0); _savePlanDraft();
}

function recalcItem(idx, fromMonth = 0) {
  const item = items.value[idx];
  const cwHeaders = currentWeekHeaders.value;
  const headers = periodHeaders.value;
  // Исключённые позиции — обнуляем расчёт
  if (item._excluded) {
    if (!item.plan.length || item.plan.length !== headers.length) {
      item.plan = headers.map((_, m) => ({ month: m, need: 0, deficit: 0, orderBoxes: 0, orderUnits: 0, locked: false }));
    } else {
      item.plan.forEach(p => { if (!p.locked) { p.orderBoxes = 0; p.orderUnits = 0; p.need = 0; p.deficit = 0; } });
    }
    item._cwData = cwHeaders.map(() => ({ daysRemaining: null, stockAfter: 0 }));
    return;
  }
  const qpb = getQpb(item); const mult = getMultiplicity(item); const pbu = qpb;
  const toBase = (v) => inputUnit.value === 'boxes' ? v * qpb : v;
  const periodDays = consumptionPeriodDays.value || 30;
  const daily = toBase(item.monthlyConsumption) / periodDays;

  // ─── Фаза 1: текущие недели (от startDate до planningDate) ───
  if (!item.transit || item.transit.length !== cwHeaders.length) {
    const old = item.transit || [];
    item.transit = cwHeaders.map((_, w) => old[w] || { qty: 0 });
  }
  let co = (item.stockOnHand || 0) + (item.stockAtSupplier || 0);
  item._cwData = cwHeaders.map((h, w) => {
    const weekConsumption = daily * h.days;
    const transitUnits = toBase(item.transit[w]?.qty || 0);
    co = co - weekConsumption + transitUnits;
    const daysRemaining = daily > 0 ? Math.round(co / daily) : null;
    return { daysRemaining, stockAfter: Math.round(co) };
  });
  if (co < 0) co = 0;

  // ─── Фаза 2: будущие периоды (планирование заказов) ───
  if (!item.plan.length || item.plan.length !== headers.length) {
    const old = item.plan || [];
    item.plan = headers.map((_, m) => { const o = old[m]; if (o && (o.locked || o.trendPct)) return { ...o, month: m }; return { month: m, need: 0, deficit: 0, orderBoxes: 0, orderUnits: 0, locked: false, trendPct: null }; });
  }
  const mu = daily * 30; const wu = daily * 7;
  // Существующие заказы в пути для этого SKU
  const skuOrders = item.sku ? (ordersByPeriod.value[item.sku] || {}) : {};
  // Если нет текущих недель — начальный остаток как раньше
  if (!cwHeaders.length) co = (item.stockOnHand || 0) + (item.stockAtSupplier || 0);
  for (let m = 0; m < fromMonth && m < headers.length; m++) {
    const existingUnits = (skuOrders[m] || 0) * qpb;
    co = co - (periodType.value === 'weeks' ? wu : mu) * headers[m].ratio + (item.plan[m]?.orderUnits || 0) + existingUnits;
    if (co < 0) co = 0;
  }
  for (let m = fromMonth; m < headers.length; m++) {
    const existingUnits = (skuOrders[m] || 0) * qpb;
    const need = (periodType.value === 'weeks' ? wu : mu) * headers[m].ratio;
    const available = co + existingUnits;
    const deficit = need - Math.min(available, need);
    if (item.plan[m].locked) { item.plan[m].need = Math.round(need); item.plan[m].deficit = Math.round(deficit); item.plan[m].orderUnits = item.plan[m].orderBoxes * pbu; item.plan[m].existingBoxes = skuOrders[m] || 0; co = available - need + item.plan[m].orderUnits; }
    else {
      let ob = 0, ou = 0;
      if (deficit > 0 && pbu > 0) { ob = Math.ceil(deficit / pbu); if (mult > 1) ob = Math.ceil(ob / mult) * mult; ou = ob * pbu; }
      const savedTrend = item.plan[m].trendPct;
      if (savedTrend && ob > 0) {
        ob = Math.round(ob * (1 + savedTrend / 100));
        if (ob < 0) ob = 0;
        if (mult > 1 && ob > 0) ob = Math.ceil(ob / mult) * mult;
        ou = ob * pbu;
      }
      item.plan[m] = { month: m, need: Math.round(need), deficit: Math.round(deficit), orderBoxes: ob, orderUnits: ou, locked: false, trendPct: savedTrend || null, existingBoxes: skuOrders[m] || 0 };
      co = available - need + ou;
    }
    if (co < 0) co = 0;
    item.plan[m].daysRemaining = daily > 0 ? Math.round(co / daily) : null;
  }
}
function recalcAll() { items.value.forEach((_, i) => recalcItem(i, 0)); }

// ─── Inline edit (#6 fix) ─────────────────────────────────────────────────
function startEdit(idx, m, event) {
  if (viewOnly.value) return;
  snapshot();
  editingCell.value = { idx, m };
  nextTick(() => {
    const td = event.currentTarget;
    const inp = td?.querySelector('.plan-edit-input');
    if (inp) { inp.focus(); inp.select(); }
  });
}
function applyEdit(idx, m, val) {
  if (!editingCell.value || editingCell.value.idx !== idx || editingCell.value.m !== m) return;
  const raw = String(val).trim();
  const newVal = /^[\d\s+\-*/().]+$/.test(raw) && raw ? Math.round(_safeCalc(raw)) || 0 : parseInt(val) || 0;
  const item = items.value[idx]; const p = item.plan[m]; if (!p) { editingCell.value = null; return; }
  p.orderBoxes = newVal; p.orderUnits = newVal * getQpb(item); p.locked = true; p.trendPct = null;
  editingCell.value = null;
  recalcItem(idx, m + 1); _savePlanDraft();
}
function cancelEdit() { editingCell.value = null; }

function toggleExclude(idx) {
  snapshot();
  const item = items.value[idx]; if (!item) return;
  item._excluded = !item._excluded;
  if (item._excluded) {
    // Обнуляем все незалоченные периоды
    item.plan.forEach(p => { if (!p.locked) { p.orderBoxes = 0; p.orderUnits = 0; } });
  }
  recalcItem(idx, 0);
  _savePlanDraft();
}

function onPeriodKeydown(e, idx, m) {
  _activeCalcIdx = idx; _activeCalcField = { type: 'period', m };
  if (planCalc.hasPendingOp()) {
    if (['+', '-', '*', '/'].includes(e.key) || /[0-9.]/.test(e.key) || e.key === 'Enter' || e.key === 'Escape' || e.key === '%') {
      planCalc.onKeydown(e);
      return;
    }
  } else if (['+', '-', '*', '/'].includes(e.key)) {
    planCalc.onKeydown(e);
    return;
  }
  if (e.key === 'Enter') { e.preventDefault(); applyEdit(idx, m, e.target.value); }
  else if (e.key === 'Escape') { e.preventDefault(); cancelEdit(); }
}

function onPeriodFocus(e, idx, m) {
  _activeCalcIdx = idx; _activeCalcField = { type: 'period', m };
  planCalc.onFocus(e);
}

function onTransitInput(idx, weekIdx, rawValue) {
  snapshot();
  const value = parseFloat(rawValue) || 0;
  const item = items.value[idx];
  if (!item.transit) item.transit = [];
  if (!item.transit[weekIdx]) item.transit[weekIdx] = { qty: 0 };
  item.transit[weekIdx].qty = value;
  recalcItem(idx, 0);
  _savePlanDraft();
}

function cwDeficitDisplay(stockAfter, item) {
  const deficit = Math.abs(stockAfter);
  const qpb = getQpb(item);
  if (inputUnit.value === 'boxes') {
    return '−' + Math.ceil(deficit / qpb) + ' кор';
  }
  return '−' + Math.ceil(deficit) + ' шт';
}

function cwDaysClass(days) {
  if (days === null) return '';
  if (days <= 3) return 'cw-danger';
  if (days <= 7) return 'cw-warning';
  return 'cw-ok';
}

function startMobEdit(idx, m) {
  if (viewOnly.value) return;
  snapshot();
  editingCell.value = { idx, m };
  nextTick(() => {
    const inp = document.querySelector('.plan-mob-edit-input');
    if (inp) { inp.focus(); inp.select(); }
  });
}

function roundToPallet(idx, m) {
  snapshot();
  const item = items.value[idx]; const p = item.plan[m]; if (!p || !item.boxesPerPallet) return;
  // orderBoxes в учётных → паллета = boxesPerPallet физ. = boxesPerPallet * mult учётных
  const mult = getMultiplicity(item);
  const accountingPerPallet = item.boxesPerPallet * mult;
  p.orderBoxes = Math.ceil(p.orderBoxes / accountingPerPallet) * accountingPerPallet;
  p.orderUnits = p.orderBoxes * getQpb(item); p.locked = true;
  recalcItem(idx, m + 1); _savePlanDraft();
}
function resetCell(idx, m) { snapshot(); const item = items.value[idx]; if (!item?.plan[m]) return; item.plan[m].locked = false; item.plan[m].trendPct = null; recalcItem(idx, m); _savePlanDraft(); }

function reserveDays(item) {
  const qpb = getQpb(item);
  const toBase = (v) => inputUnit.value === 'boxes' ? v * qpb : v;
  const periodDays = consumptionPeriodDays.value || 30;
  const daily = toBase(item.monthlyConsumption) / periodDays;
  if (!daily || daily <= 0) return null;
  const totalStock = (item.stockOnHand || 0) + (item.stockAtSupplier || 0);
  if (totalStock <= 0) return 0;
  return Math.round(totalStock / daily);
}
function reserveDaysText(item) {
  const d = reserveDays(item);
  if (d === null) return '—';
  return d;
}
function reserveDaysClass(item) {
  const d = reserveDays(item);
  if (d === null) return '';
  if (d <= 3) return 'reserve-danger';
  if (d <= 7) return 'reserve-warning';
  return 'reserve-ok';
}

// ─── Загрузка машин ──────────────────────────────────────────────────────
function periodPallets(m) {
  let total = 0;
  for (const item of items.value) {
    if (item._excluded) continue;
    const ob = item.plan[m]?.orderBoxes || 0;
    if (ob <= 0 || !item.boxesPerPallet) continue;
    const mult = getMultiplicity(item);
    const physBoxes = Math.ceil(ob / mult);
    total += Math.ceil(physBoxes / item.boxesPerPallet);
  }
  return total;
}

function periodTruckInfo(m) {
  const tp = truckPallets.value || 32;
  const totalPallets = periodPallets(m);
  if (totalPallets === 0) return null;
  const truckCount = Math.ceil(totalPallets / tp);
  const trucks = [];
  let remaining = totalPallets;
  for (let i = 0; i < truckCount; i++) {
    const p = Math.min(remaining, tp);
    trucks.push({ number: i + 1, pallets: p, percent: Math.round(p / tp * 100) });
    remaining -= p;
  }
  return { totalPallets, truckCount, trucks };
}

// ─── Создать заказ из периода ─────────────────────────────────────────────
async function createOrderFromPeriod(pi) {
  const header = periodHeaders.value[pi];
  if (!header) return;
  const planItems = items.value.filter(i => !i._excluded && i.plan[pi]?.orderBoxes > 0);
  if (!planItems.length) { toast.error('Нет данных', 'В этом периоде нет позиций с заказом'); return; }

  // Проверяем, есть ли незавершённый заказ
  const hasExisting = orderStore.items.some(i => i.consumptionPeriod > 0 || i.stock > 0 || i.finalOrder > 0);
  if (hasExisting) {
    const ok = await confirmAction('Создать заказ из плана?', 'Текущий незавершённый заказ будет сброшен. Продолжить?');
    if (!ok) return;
  }

  // Загрузить полные карточки товаров из БД
  const skus = planItems.map(i => i.sku).filter(Boolean);
  let productMap = {};
  if (skus.length) {
    let prodQuery = db.from('products').select(PLAN_PRODUCT_FIELDS).in('sku', skus);
    prodQuery = applyEntityGroupFilter(prodQuery, orderStore.settings.legalEntity);
    const { data: products } = await prodQuery;
    if (products) productMap = Object.fromEntries(products.map(p => [p.sku, p]));
  }

  orderStore.resetOrder();
  orderStore.settings.supplier = supplier.value;
  orderStore.settings.periodDays = consumptionPeriodDays.value;
  orderStore.settings.unit = inputUnit.value;
  orderStore.settings.today = new Date();
  if (header.startDate) orderStore.settings.deliveryDate = header.startDate;

  let count = 0;
  for (const item of planItems) {
    const fullProduct = productMap[item.sku] || {
      sku: item.sku, name: item.name,
      qty_per_box: item.qtyPerBox,
      boxes_per_pallet: item.boxesPerPallet,
      multiplicity: item.multiplicity,
      unit_of_measure: item.unitOfMeasure,
    };
    const added = orderStore.addItem(fullProduct, true);
    if (!added) continue;
    added.consumptionPeriod = item.monthlyConsumption;
    // Stock: use stockOnHand (always stored in pieces internally)
    const qpb = getQpb(item);
    added.stock = inputUnit.value === 'boxes' ? Math.round(item.stockOnHand / qpb) : item.stockOnHand;
    added.finalOrder = item.plan[pi].orderBoxes;
    added._manualOrder = true;
    count++;
  }

  draftStore.saveNow();
  router.push({ name: 'order' });
  toast.success('Заказ создан из планирования', `${count} поз. — ${header.label}`);
}

function itemHasOrder(item) { return item.plan.some(p => p.orderBoxes > 0); }
function itemTotalBoxes(item) { return item.plan.reduce((s, p) => s + (p.orderBoxes || 0), 0); }
function itemTotalUnits(item) { return item.plan.reduce((s, p) => s + (p.orderUnits || 0), 0); }
function periodTotalBoxes(m) { return items.value.reduce((s, i) => i._excluded ? s : s + (i.plan[m]?.orderBoxes || 0), 0); }
const itemsWithPlan = computed(() => items.value.filter(i => !i._excluded && i.plan.some(p => p.orderBoxes > 0)));
const planSummaryTotalBoxes = computed(() => itemsWithPlan.value.reduce((s, i) => s + itemTotalBoxes(i), 0));

// ─── Цены ─────────────────────────────────────────────────────────────────────
const planPriceMap = ref({}); // sku -> {price, unit_type, currency, origPrice}

async function loadPlanPrices() {
  if (!userStore.hasAccess('pricing', 'view')) { planPriceMap.value = {}; return; }
  const le = orderStore.settings.legalEntity;
  const sup = supplier.value;
  if (!le || !sup) { planPriceMap.value = {}; return; }
  try {
    const { data } = await db.rpc('get_current_prices', { legal_entity: le, supplier: sup });
    const map = {};
    if (data) {
      const prices = data.prices || data;
      const rate = data.rub_to_byn_rate || 0.0375;
      for (const p of prices) {
        let price = parseFloat(p.price);
        if (isNaN(price) || price <= 0) continue;
        const origPrice = price;
        const currency = p.currency || 'BYN';
        if (currency === 'RUB') price = +(price * rate).toFixed(2);
        map[p.sku] = { price, unit_type: p.unit_type, currency, origPrice };
      }
    }
    planPriceMap.value = map;
  } catch { planPriceMap.value = {}; }
}

function planItemSum(item) {
  const pi = planPriceMap.value[item.sku];
  if (!pi) return 0;
  const totalBoxes = itemTotalBoxes(item);
  if (!totalBoxes) return 0;
  const mult = item.multiplicity || 1;
  const physBoxes = Math.ceil(totalBoxes / mult);
  if (pi.unit_type === 'box') return physBoxes * pi.price;
  if (pi.unit_type === 'thousand') return itemTotalUnits(item) * pi.price / 1000;
  return itemTotalUnits(item) * pi.price;
}

function planItemPriceTooltip(item) {
  const pi = planPriceMap.value[item.sku];
  if (!pi) return '';
  const sum = planItemSum(item);
  const units = { box: 'кор', piece: 'шт', thousand: 'тыс/шт', kg: 'кг', liter: 'л' };
  const unit = units[pi.unit_type] || 'шт';
  let priceStr = `${parseFloat(pi.price).toLocaleString('ru-RU', { minimumFractionDigits: 2 })} BYN / ${unit}`;
  if (pi.currency === 'RUB') priceStr = `${parseFloat(pi.origPrice).toLocaleString('ru-RU', { minimumFractionDigits: 2 })} ₽ / ${unit} (≈ ${parseFloat(pi.price).toLocaleString('ru-RU', { minimumFractionDigits: 2 })} BYN)`;
  return `Цена: ${priceStr}` + (sum > 0 ? ` · Сумма: ${sum.toLocaleString('ru-RU', { minimumFractionDigits: 2 })} BYN` : '');
}

const planTotalSum = computed(() => {
  if (!Object.keys(planPriceMap.value).length) return 0;
  return items.value.reduce((s, item) => s + planItemSum(item), 0);
});

const planPricedCount = computed(() => {
  return items.value.filter(i => planPriceMap.value[i.sku] && itemTotalBoxes(i) > 0).length;
});

const planTotalCount = computed(() => {
  return items.value.filter(i => itemTotalBoxes(i) > 0).length;
});

// ─── Unit change (#7 fix — clear cache before converting) ─────────────────
function onUnitChange(e) {
  const newUnit = e.target.value;
  if (newUnit === inputUnit.value) return;
  if (!items.value.length) { inputUnit.value = newUnit; return; }
  const oldUnit = inputUnit.value;
  snapshot();
  items.value.forEach(item => {
    const qpb = getQpb(item);
    if (oldUnit === 'pieces' && newUnit === 'boxes') { item.monthlyConsumption = item.monthlyConsumption ? Math.round(item.monthlyConsumption / qpb * 100) / 100 : 0; }
    else if (oldUnit === 'boxes' && newUnit === 'pieces') { item.monthlyConsumption = Math.round(item.monthlyConsumption * qpb); }
    item.plan.forEach(p => { p.locked = false; });
  });
  inputUnit.value = newUnit;
  _validationCache = null; // сбрасываем кэш ПЕРЕД validation
  recalcAll(); triggerValidation(); _savePlanDraft();
  toast.info('Единицы обновлены', `Пересчитано в ${newUnit === 'boxes' ? 'коробки' : 'штуки'}`);
}

// ─── Validation (#7 fix — uses inputUnit.value which is already updated) ──
// _validationCache объявлена раньше (используется в watch с immediate)
let _validationGen = 0;
function triggerValidation() { clearTimeout(_vTimer); _validationGen++; _vTimer = setTimeout(runValidation, 300); }
async function runValidation() {
  if (!supplier.value || !items.value.length) return;
  const gen = _validationGen;
  const avgMap = await _loadValidationData(gen);
  if (gen !== _validationGen) return; // данные устарели, пропускаем
  if (!avgMap.size) return;
  items.value.forEach(item => {
    if (!item.sku || !item.monthlyConsumption) { item._cw = false; item._ct = ''; return; }
    const avg = avgMap.get(item.sku);
    if (!avg) { item._cw = false; item._ct = ''; return; }
    const dev = Math.abs(item.monthlyConsumption - avg) / avg;
    if (dev > 0.30) { item._cw = true; item._ct = `⚠️ Отклонение от среднего (${nf.format(Math.round(avg))})`; }
    else { item._cw = false; item._ct = ''; }
  });
}
async function _loadValidationData(gen) {
  if (_validationCache && _validationCache.le === orderStore.settings.legalEntity && _validationCache.unit === inputUnit.value && _validationCache.periodDays === consumptionPeriodDays.value) return _validationCache.data;
  const targetPeriod = consumptionPeriodDays.value || 30;
  const avgMap = new Map();

  // 1. Загружаем расход из analysis_data
  const { data, error } = await db.from('analysis_data').select('sku, consumption, period_days')
    .eq('legal_entity', orderStore.settings.legalEntity);
  if (gen !== _validationGen) return avgMap;
  if (error || !data?.length) { _validationCache = { le: orderStore.settings.legalEntity, unit: inputUnit.value, periodDays: targetPeriod, data: avgMap }; return avgMap; }

  // Карта SKU → расход в штуках за период (с тем же округлением что при загрузке)
  const adMap = new Map();
  data.forEach(d => {
    if (!d.sku || !d.consumption) return;
    const srcPeriod = d.period_days || 30;
    const valPcs = srcPeriod === targetPeriod
      ? Math.round((d.consumption) * 10) / 10
      : Math.round(((d.consumption) / srcPeriod) * targetPeriod * 10) / 10;
    adMap.set(d.sku, valPcs);
  });

  // 2. Для каждого товара: свой расход + расход ПРИМЕНЁННЫХ аналогов
  items.value.forEach(item => {
    if (!item.sku) return;
    let valPcs = adMap.get(item.sku) || 0;
    const applied = _appliedAnalogs.get(item.sku);
    if (applied) {
      for (const aSku of applied) { valPcs += adMap.get(aSku) || 0; }
    }
    if (!valPcs) return;
    const val = inputUnit.value === 'boxes' ? Math.round(valPcs / getQpb(item) * 100) / 100 : valPcs;
    avgMap.set(item.sku, val);
  });

  if (gen !== _validationGen) return avgMap;
  _validationCache = { le: orderStore.settings.legalEntity, unit: inputUnit.value, periodDays: targetPeriod, data: avgMap };
  return avgMap;
}

// ─── Загрузить расход/остаток из анализа запасов ──────────────────────────
// ─── Существующие заказы (в пути) ─────────────────────────────────────────
// Карта: sku → periodIndex → qty_boxes из непринятых заказов
const ordersByPeriod = computed(() => {
  const map = {}; // { sku: { periodIdx: totalBoxes } }
  if (!existingOrders.value.length || !periodHeaders.value.length) return map;
  const headers = periodHeaders.value;
  for (const order of existingOrders.value) {
    const dd = new Date(order.delivery_date + 'T00:00:00');
    // Найти период, в который попадает delivery_date
    let pi = -1;
    for (let i = 0; i < headers.length; i++) {
      const pStart = headers[i].startDate;
      const pEnd = i + 1 < headers.length ? headers[i + 1].startDate : null;
      if (pEnd) {
        if (dd >= pStart && dd < pEnd) { pi = i; break; }
      } else {
        if (dd >= pStart) { pi = i; break; }
      }
    }
    if (pi < 0) continue;
    for (const oi of (order._items || [])) {
      if (!oi.sku) continue;
      if (!map[oi.sku]) map[oi.sku] = {};
      map[oi.sku][pi] = (map[oi.sku][pi] || 0) + (oi.qty_boxes || 0);
    }
  }
  return map;
});

// Общее кол-во заказов в пути по периоду (для отображения)
function periodExistingOrders(pi) {
  const headers = periodHeaders.value;
  if (!existingOrders.value.length || !headers.length) return [];
  const result = [];
  for (const order of existingOrders.value) {
    const dd = new Date(order.delivery_date + 'T00:00:00');
    const pStart = headers[pi]?.startDate;
    const pEnd = pi + 1 < headers.length ? headers[pi + 1].startDate : null;
    const inPeriod = pEnd ? (dd >= pStart && dd < pEnd) : (dd >= pStart);
    if (inPeriod) result.push(order);
  }
  return result;
}

async function loadExistingOrders() {
  if (!supplier.value) { existingOrders.value = []; return; }
  const headers = periodHeaders.value;
  if (!headers.length) { existingOrders.value = []; return; }
  existingOrdersLoading.value = true;
  try {
    const dateFrom = toLocalDateStr(headers[0].startDate);
    const lastH = headers[headers.length - 1];
    // Конец последнего периода: примерная дата
    const endDate = new Date(lastH.startDate);
    if (periodType.value === 'weeks') {
      const freqWeeks = periodFrequency.value === 'w2' ? 2 : 1;
      endDate.setDate(endDate.getDate() + freqWeeks * 7);
    } else {
      endDate.setMonth(endDate.getMonth() + 1);
    }
    const dateTo = toLocalDateStr(endDate);

    const { data: orders } = await db.from('orders').select('id, supplier, delivery_date')
      .eq('legal_entity', orderStore.settings.legalEntity)
      .eq('supplier', supplier.value)
      .gte('delivery_date', dateFrom)
      .lte('delivery_date', dateTo)
      .is('received_at', null);

    if (!orders?.length) { existingOrders.value = []; return; }

    // Загрузить позиции этих заказов
    const orderIds = orders.map(o => o.id);
    const { data: orderItems } = await db.from('order_items').select('order_id, sku, name, qty_boxes').in('order_id', orderIds);

    const itemMap = {};
    for (const oi of (orderItems || [])) {
      if (!itemMap[oi.order_id]) itemMap[oi.order_id] = [];
      itemMap[oi.order_id].push(oi);
    }

    existingOrders.value = orders.map(o => ({ ...o, _items: itemMap[o.id] || [] }));
  } catch (err) {
    console.error('[loadExistingOrders]', err);
    existingOrders.value = [];
  } finally {
    existingOrdersLoading.value = false;
  }
}

function clearExistingOrders() {
  existingOrders.value = [];
  recalcAll();
}

async function fillConsumption() {
  if (!items.value.length) return;
  snapshot();
  fillLoading.value = true;
  try {
    const result = await loadFromAnalysis('planning', items.value, orderStore.settings.legalEntity, inputUnit.value, consumptionPeriodDays.value || 30);

    if (result.matched === 0) {
      toast.info('Нет данных', 'Нет данных анализа для этих товаров');
      return;
    }

    _validationCache = null;
    _appliedAnalogs = new Map();
    triggerRef(items);
    recalcAll(); _savePlanDraft();

    const dateStr = result.updatedAt
      ? result.updatedAt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
      : '—';
    const byStr = result.updatedBy ? ` (${result.updatedBy})` : '';
    toast.success('Загружено', `${result.matched} из ${result.total}. Данные от ${dateStr}${byStr}`);

    if (result.analogMerges?.length) {
      // Проверку запустим после закрытия модалки аналогов
      analogMergeModal.value = { show: true, merges: result.analogMerges };
    } else {
      triggerValidation();
    }
  } catch (err) {
    console.error('[fillConsumption]', err);
    toast.error('Ошибка', 'Не удалось загрузить данные анализа');
  } finally { fillLoading.value = false; }
}

// ─── 1С (#1) ──────────────────────────────────────────────────────────────
async function loadFrom1c() {
  if (!items.value.length) return;
  const skus = items.value.map(i => i.sku).filter(Boolean);
  if (!skus.length) { toast.error('Нет артикулов', ''); return; }
  snapshot(); load1cLoading.value = true;
  try {
    const { data, error } = await db.from('stock_1c').select('sku, stock, consumption, period_days, updated_at')
      .eq('legal_entity', orderStore.settings.legalEntity).in('sku', skus);
    if (error) { toast.error('Ошибка', ''); return; }
    if (!data?.length) { toast.info('Нет данных', ''); return; }
    const stockMap = new Map(data.map(d => [d.sku, d]));
    let f = 0;
    items.value.forEach(item => {
      const d = item.sku ? stockMap.get(item.sku) : null; if (!d) return;
      const qpb = getQpb(item);
      // stock_1c всегда в штуках → stockOnHand хранится в штуках
      item.stockOnHand = Math.round((d.stock || 0) * 10) / 10;
      // consumption → расход за выбранный период в текущих единицах
      const dailyC = (d.period_days || 30) > 0 ? (d.consumption || 0) / (d.period_days || 30) : 0;
      const periodConsumption = dailyC * (consumptionPeriodDays.value || 30);
      item.monthlyConsumption = inputUnit.value === 'boxes' ? Math.round(periodConsumption / qpb * 100) / 100 : Math.round(periodConsumption * 10) / 10;
      f++;
    });
    _validationCache = null;
    triggerRef(items);
    recalcAll(); triggerValidation(); _savePlanDraft();
    toast.success('Из 1С загружено', `${f} из ${items.value.length} позиций`);
  } catch { toast.error('Ошибка', 'stock_1c не найдена'); }
  finally { load1cLoading.value = false; }
}

// ─── Очистить / Импорт ───────────────────────────────────────────────────
async function clearAll() {
  const ok = await confirmAction('Обнулить данные?', 'Расход, остатки и расчёты будут сброшены.');
  if (!ok) return;
  snapshot();
  items.value.forEach(i => { i.monthlyConsumption = 0; i.stockOnHand = 0; i.stockAtSupplier = 0; i.plan = []; });
  recalcAll(); _savePlanDraft(); toast.success('Обнулено', '');
}
async function doImport() {
  const result = await importFromFile('planning', items.value, orderStore.settings.legalEntity, inputUnit.value);
  if (!result) return;
  if (result.error) { toast.error('Ошибка', result.error); return; }
  if (result.matched === 0) { toast.info('0 совпадений', ''); return; }
  snapshot();
  result.items.forEach((u, idx) => {
    const item = items.value[idx]; if (!item) return;
    if (u.stockOnHand !== undefined) item.stockOnHand = inputUnit.value === 'boxes' ? u.stockOnHand * getQpb(item) : u.stockOnHand;
    if (u.stockAtSupplier !== undefined) item.stockAtSupplier = inputUnit.value === 'boxes' ? u.stockAtSupplier * getQpb(item) : u.stockAtSupplier;
    if (u.monthlyConsumption !== undefined) item.monthlyConsumption = u.monthlyConsumption;
  });
  _validationCache = null;
  recalcAll(); triggerValidation(); _savePlanDraft();
  toast.success('Импорт', `${result.matched} обновлены`);
  if (result.analogMerges?.length) {
    analogMergeModal.value = { show: true, merges: result.analogMerges };
  }
}
function onAnalogApply() {
  const { merges } = analogMergeModal.value;
  // Запоминаем какие аналоги применены (checked) для проверки расхода
  for (const merge of merges) {
    const set = _appliedAnalogs.get(merge.itemSku) || new Set();
    for (const a of merge.analogs) {
      if (a.checked) set.add(a.sku);
      else set.delete(a.sku);
    }
    if (set.size) _appliedAnalogs.set(merge.itemSku, set);
    else _appliedAnalogs.delete(merge.itemSku);
  }
  const applied = applyAnalogMerges(items.value, merges, 'planning');
  analogMergeModal.value.show = false;
  if (applied > 0) {
    triggerRef(items);
    recalcAll(); _savePlanDraft();
    toast.success('Аналоги применены', `${applied} аналогов добавлены`);
  }
  _validationCache = null;
  triggerValidation();
}
function onAnalogSkip() {
  // Аналоги не применены — убираем их из проверки
  const { merges } = analogMergeModal.value;
  for (const merge of merges) {
    _appliedAnalogs.delete(merge.itemSku);
  }
  analogMergeModal.value.show = false;
  _validationCache = null;
  triggerValidation();
}

// ─── Edit product card (#2) ───────────────────────────────────────────────
async function openProductEdit(item) {
  if (!item.sku && !item.name) return;
  // Need product.id for EditCardModal to load full data
  let productId = item.productId;
  if (!productId && item.sku) {
    const { data } = await db.from('products').select('id').eq('sku', item.sku).limit(1).single();
    if (data) productId = data.id;
  }
  if (!productId) { toast.info('Карточка не найдена', ''); return; }
  editCardModal.value = { show: true, product: { id: productId, sku: item.sku, name: item.name } };
}
async function onCardSaved() {
  const product = editCardModal.value.product; editCardModal.value.show = false;
  supplierStore.invalidate();
  supplierStore.loadSuppliers(orderStore.settings.legalEntity);
  if (!product?.sku) return;
  try {
    const { data } = await db.from('products').select('*').eq('sku', product.sku).single();
    if (!data) return;
    if (!data.is_active) {
      items.value = items.value.filter(i => i.sku !== product.sku);
      _savePlanDraft();
      return;
    }
    const item = items.value.find(i => i.sku === product.sku);
    if (item) {
      item._hidden = false;
      item.name = data.name || item.name; item.qtyPerBox = data.qty_per_box || item.qtyPerBox;
      item.boxesPerPallet = data.boxes_per_pallet || item.boxesPerPallet;
      item.multiplicity = data.multiplicity || item.multiplicity;
      item.unitOfMeasure = data.unit_of_measure || item.unitOfMeasure;
      recalcAll(); _savePlanDraft();
    }
  } catch (e) { console.error(e); }
}

// ─── Добавление товара ────────────────────────────────────────────────────
function addProduct(e) {
  const sku = e.target.value;
  if (!sku) return;
  const product = allSupplierProducts.value.find(p => p.sku === sku);
  if (!product || items.value.find(i => i.sku === product.sku)) return;
  const qpb = product.qty_per_box || 1;
  items.value.push({
    sku: product.sku, name: product.name, qtyPerBox: qpb,
    boxesPerPallet: product.boxes_per_pallet || 0,
    multiplicity: product.multiplicity || 1,
    unitOfMeasure: product.unit_of_measure || 'шт',
    monthlyConsumption: 0, stockOnHand: 0, stockAtSupplier: 0, transit: [],
    plan: periodHeaders.value.map(() => ({ orderBoxes: 0, orderUnits: 0, locked: false })),
    _cw: false, _ct: '',
    _hidden: product.is_active === 0,
  });
  e.target.value = '';
  _savePlanDraft();
}

async function exportExcel() {
  if (!itemsWithPlan.value.length) { toast.error('Нет позиций', 'Нет позиций с заказом для экспорта'); return; }
  const XLSX = await import('xlsx-js-style');
  const headers = periodHeaders.value;
  const le = orderStore.settings.legalEntity;
  const colTotal = 2 + headers.length;          // индекс колонки «Итого»
  const totalCols = colTotal + 1;               // всего колонок

  // Палитра
  const brown = '502314';
  const brownLight = 'F0EBE5';
  const cream = 'FFF8F0';
  const summaryBg = 'EDE7E3';     // фон для колонок Итого/Паллеты (чуть темнее)
  const summaryBgStripe = 'E4DDD7'; // полосатый фон для Итого/Паллеты
  const summaryHeader = '3A1A0E'; // тёмный заголовок для Итого/Паллеты
  const borderClr = 'E0D6CC';
  const border = { style: 'thin', color: { rgb: borderClr } };
  const borders = { top: border, bottom: border, left: border, right: border };

  const sTitle = { font: { bold: true, sz: 16, color: { rgb: brown }, name: 'Calibri' }, alignment: { vertical: 'center' } };
  const sInfo = { font: { sz: 11, color: { rgb: '666666' }, name: 'Calibri' } };
  const sHeader = {
    font: { bold: true, sz: 11, color: { rgb: 'FFFFFF' }, name: 'Calibri' },
    fill: { fgColor: { rgb: brown } },
    alignment: { horizontal: 'center', vertical: 'center', wrapText: true },
    border: borders,
  };
  const sHeaderLeft = { ...sHeader, alignment: { ...sHeader.alignment, horizontal: 'left' } };
  const sHeaderSummary = {
    font: { bold: true, sz: 11, color: { rgb: 'FFFFFF' }, name: 'Calibri' },
    fill: { fgColor: { rgb: summaryHeader } },
    alignment: { horizontal: 'center', vertical: 'center', wrapText: true },
    border: borders,
  };

  function sCell(stripe) {
    return {
      font: { sz: 11, name: 'Calibri' },
      fill: stripe ? { fgColor: { rgb: cream } } : undefined,
      alignment: { vertical: 'center' },
      border: borders,
    };
  }
  function sCellBold(stripe) {
    return {
      font: { bold: true, sz: 11, color: { rgb: brown }, name: 'Calibri' },
      fill: stripe ? { fgColor: { rgb: cream } } : undefined,
      alignment: { vertical: 'center' },
      border: borders,
    };
  }
  function sPeriodVal(stripe, hasValue) {
    return {
      font: { bold: hasValue, sz: 11, color: { rgb: hasValue ? brown : 'CCCCCC' }, name: 'Calibri' },
      fill: stripe ? { fgColor: { rgb: cream } } : undefined,
      alignment: { horizontal: 'center', vertical: 'center' },
      border: borders,
    };
  }
  function sSummaryVal(stripe, hasValue) {
    return {
      font: { bold: hasValue, sz: 11, color: { rgb: hasValue ? brown : 'CCCCCC' }, name: 'Calibri' },
      fill: { fgColor: { rgb: stripe ? summaryBgStripe : summaryBg } },
      alignment: { horizontal: 'center', vertical: 'center' },
      border: borders,
    };
  }
  const sTotalLabel = {
    font: { bold: true, sz: 12, color: { rgb: 'FFFFFF' }, name: 'Calibri' },
    fill: { fgColor: { rgb: brown } },
    alignment: { horizontal: 'right', vertical: 'center' },
    border: borders,
  };
  const sTotalEmpty = { fill: { fgColor: { rgb: brown } }, border: borders };
  const sTotalVal = {
    font: { bold: true, sz: 12, color: { rgb: 'FFFFFF' }, name: 'Calibri' },
    fill: { fgColor: { rgb: brown } },
    alignment: { horizontal: 'center', vertical: 'center' },
    border: borders,
  };
  const sTotalSummary = {
    font: { bold: true, sz: 12, color: { rgb: 'FFFFFF' }, name: 'Calibri' },
    fill: { fgColor: { rgb: summaryHeader } },
    alignment: { horizontal: 'center', vertical: 'center' },
    border: borders,
  };

  function setCell(ws, r, c, val, style) {
    const ref = XLSX.utils.encode_cell({ r, c });
    ws[ref] = { v: val, t: typeof val === 'number' ? 'n' : 's', s: style };
  }

  const ws = {};
  let r = 0;

  // Заголовок
  setCell(ws, r, 0, `План заказов — ${supplier.value}`, sTitle);
  r++;
  setCell(ws, r, 0, `Юр. лицо: ${le}`, sInfo);
  r++;
  setCell(ws, r, 0, `Период: ${periodCount.value} ${periodType.value === 'weeks' ? 'нед.' : 'мес.'} с ${startDateStr.value}`, sInfo);
  r += 2;

  // Шапка таблицы
  setCell(ws, r, 0, 'Артикул', sHeaderLeft);
  setCell(ws, r, 1, 'Наименование', sHeaderLeft);
  headers.forEach((h, c) => setCell(ws, r, c + 2, h.label, sHeader));
  setCell(ws, r, colTotal, 'Итого', sHeaderSummary);
  r++;

  // Данные
  const hasPlanPrices = Object.keys(planPriceMap.value).length > 0;
  itemsWithPlan.value.forEach((item, idx) => {
    const stripe = idx % 2 === 1;
    setCell(ws, r, 0, item.sku || '', sCell(stripe));
    setCell(ws, r, 1, item.name || '', sCellBold(stripe));
    item.plan.forEach((p, c) => {
      if (p.orderBoxes > 0) {
        const physB = Math.ceil(p.orderBoxes / (item.multiplicity || 1));
        setCell(ws, r, c + 2, `${physB} кор (${nf.format(p.orderUnits)} ${item.unitOfMeasure || 'шт'})`, sPeriodVal(stripe, true));
      } else {
        setCell(ws, r, c + 2, '—', sPeriodVal(stripe, false));
      }
    });
    // Итого по товару
    const tBoxes = itemTotalBoxes(item);
    const tUnits = itemTotalUnits(item);
    const itemMult = item.multiplicity || 1;
    if (tBoxes > 0) {
      const tPhys = Math.ceil(tBoxes / itemMult);
      setCell(ws, r, colTotal, `${nf.format(tPhys)} кор (${nf.format(tUnits)} ${item.unitOfMeasure || 'шт'})`, sSummaryVal(stripe, true));
    } else {
      setCell(ws, r, colTotal, '—', sSummaryVal(stripe, false));
    }
    r++;
  });

  // Итого коробок
  setCell(ws, r, 0, '', sTotalEmpty);
  setCell(ws, r, 1, 'ИТОГО кор:', sTotalLabel);
  headers.forEach((_, m) => {
    const t = periodTotalBoxes(m);
    setCell(ws, r, m + 2, t > 0 ? `${nf.format(t)} кор` : '—', sTotalVal);
  });
  const grandTotalBoxes = itemsWithPlan.value.reduce((s, i) => s + itemTotalBoxes(i), 0);
  const grandTotalUnits = itemsWithPlan.value.reduce((s, i) => s + itemTotalUnits(i), 0);
  setCell(ws, r, colTotal, grandTotalBoxes > 0 ? `${nf.format(grandTotalBoxes)} кор (${nf.format(grandTotalUnits)} шт)` : '—', sTotalSummary);
  r++;

  // Итого сумма BYN (если есть цены)
  if (hasPlanPrices) {
    setCell(ws, r, 0, '', sTotalEmpty);
    setCell(ws, r, 1, 'Сумма, BYN:', sTotalLabel);
    let grandSum = 0;
    headers.forEach((_, m) => {
      let periodSum = 0;
      itemsWithPlan.value.forEach(item => {
        const pi = planPriceMap.value[item.sku];
        if (!pi) return;
        const boxes = item.plan[m]?.orderBoxes || 0;
        if (!boxes) return;
        const mult = item.multiplicity || 1;
        const physBoxes = Math.ceil(boxes / mult);
        const units = item.plan[m]?.orderUnits || 0;
        if (pi.unit_type === 'box') periodSum += physBoxes * pi.price;
        else if (pi.unit_type === 'thousand') periodSum += units * pi.price / 1000;
        else periodSum += units * pi.price;
      });
      grandSum += periodSum;
      setCell(ws, r, m + 2, periodSum > 0 ? periodSum : '—', periodSum > 0 ? { ...sTotalVal, numFmt: '#,##0.00' } : sTotalVal);
    });
    setCell(ws, r, colTotal, grandSum > 0 ? grandSum : '', grandSum > 0 ? { ...sTotalSummary, numFmt: '#,##0.00' } : sTotalSummary);
    r++;
  }

  // Настройки листа
  ws['!ref'] = XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: r - 1, c: totalCols - 1 } });
  ws['!cols'] = [
    { wch: 14 }, { wch: 42 },
    ...headers.map(() => ({ wch: 22 })),
    { wch: 24 },
  ];
  ws['!rows'] = [{ hpt: 24 }];
  ws['!merges'] = [
    { s: { r: 0, c: 0 }, e: { r: 0, c: totalCols - 1 } },
    { s: { r: 1, c: 0 }, e: { r: 1, c: totalCols - 1 } },
    { s: { r: 2, c: 0 }, e: { r: 2, c: totalCols - 1 } },
  ];

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'План');

  const fileDate = startDateStr.value.replace(/-/g, '');
  XLSX.writeFile(wb, `План_${supplier.value}_${fileDate}.xlsx`);
  toast.success('Экспорт', 'Файл Excel сохранён');
}

function _savePlanDraft() {
  draftStore.savePlan({ supplier: supplier.value, periodValue: periodValue.value, periodFrequency: periodFrequency.value, periodCountInput: periodCountInput.value, startDateStr: startDateStr.value, planningDateStr: planningDateStr.value, inputUnit: inputUnit.value, consumptionPeriodDays: consumptionPeriodDays.value, truckEnabled: truckEnabled.value, truckPallets: truckPallets.value, items: items.value, viewOnly: viewOnly.value, editingPlanId: editingPlanId.value });
}

// ─── Загрузка товаров (#3 — порядок из item_order) ────────────────────────
let _loadProductsGen = 0;
async function loadProducts() {
  _appliedAnalogs = new Map();
  if (!supplier.value) { items.value = []; return; }
  const gen = ++_loadProductsGen;
  suppLoading.value = true;
  try {
    const { data, error } = await db.from('products').select(PLAN_PRODUCT_FIELDS).eq('supplier', supplier.value).eq('is_active', 1).order('name');
    if (gen !== _loadProductsGen) return; // устаревший запрос
    if (error) { toast.error('Ошибка', ''); return; }
    const group = getEntityGroup(orderStore.settings.legalEntity);
    items.value = (data || []).filter(p => group.includes(p.legal_entity)).map(p => ({
      productId: p.id, sku: p.sku || '', name: p.name, qtyPerBox: p.qty_per_box || 1,
      boxesPerPallet: p.boxes_per_pallet || null, unitOfMeasure: p.unit_of_measure || 'шт',
      multiplicity: p.multiplicity || 1, analogGroup: p.analog_group || '', monthlyConsumption: 0,
      stockOnHand: 0, stockAtSupplier: 0, transit: [], plan: [], _cw: false, _ct: '', _excluded: false,
    }));
    // (#3) Применяем порядок товаров из item_order (как в заказе)
    await restoreItemOrder();
    if (gen !== _loadProductsGen) return; // устаревший запрос после restoreItemOrder
    editingPlanId.value = null; viewOnly.value = false; _prevPlanItems = null;
    undoStack.value = []; redoStack.value = [];
    recalcAll(); triggerValidation(); _savePlanDraft();
    loadPlanPrices();
    loadTrends();
  } finally { suppLoading.value = false; }
}

// ─── Тренды реализации ресторанов (14к14) ──────────────────────────────────────
let _loadTrendsGen = 0;
async function loadTrends() {
  const skus = items.value.map(i => String(i.sku)).filter(Boolean);
  if (!skus.length) { trendMap.value = {}; salesMap.value = {}; return; }
  const gen = ++_loadTrendsGen;
  try {
    const skuToGroup = {};
    const groups = new Set();
    for (const item of items.value) {
      if (item.analogGroup) { skuToGroup[String(item.sku)] = item.analogGroup; groups.add(item.analogGroup); }
    }
    if (!groups.size) { trendMap.value = {}; salesMap.value = {}; return; }

    const cpd = consumptionPeriodDays.value || 30;
    const groupList = [...groups];
    const { data: summary } = await db.rpc('get_restaurant_sales_summary', {
      legal_entity: orderStore.settings.legalEntity,
      analog_groups: groupList,
      period_days: cpd,
    });
    if (gen !== _loadTrendsGen) return;

    // Тренд: 14 к 14 (из последних 28 дней)
    const groupStats = {};
    const groupTotals = {};
    for (const s of (summary?.rows || [])) {
      const g = s.analog_group;
      groupStats[g] = { cur: parseFloat(s.cur) || 0, prev: parseFloat(s.prev) || 0 };
      groupTotals[g] = parseFloat(s.total) || 0;
    }

    const map = {};
    const sMap = {};
    for (const sku of skus) {
      const g = skuToGroup[sku];
      if (!g) continue;
      // Тренд
      if (groupStats[g]) {
        const { cur, prev } = groupStats[g];
        if (prev > 0) {
          const pct = Math.round((cur - prev) / prev * 100);
          if (pct !== 0) map[sku] = { pct, cur: Math.round(cur), prev: Math.round(prev), group: g };
        }
      }
      // Реализация
      if (groupTotals[g] > 0) {
        sMap[sku] = { total: Math.round(groupTotals[g]), group: g, days: cpd };
      }
    }
    trendMap.value = map;
    salesMap.value = sMap;
  } catch (e) {
    console.error('loadTrends error:', e);
    if (gen === _loadTrendsGen) trendMap.value = {};
  }
}

// ─── Применение тренда к периодам ──────────────────────────────────────────
const trendPopup = ref(null);

const trendPeriodOptions = computed(() => {
  const total = editablePeriodIndices.value.length;
  if (total <= 1) return [1];
  const opts = [1];
  if (total >= 3) opts.push(Math.ceil(total / 2));
  opts.push(total);
  return [...new Set(opts)];
});

const trendPopupStyle = ref({});

function openTrendPopup(idx, sku, e) {
  if (viewOnly.value) return;
  if (trendPopup.value && trendPopup.value.idx === idx) { trendPopup.value = null; return; }
  const rect = e.currentTarget.getBoundingClientRect();
  trendPopupStyle.value = {
    position: 'fixed',
    top: (rect.bottom + 4) + 'px',
    left: (rect.left + rect.width / 2) + 'px',
    transform: 'translateX(-50%)',
  };
  trendPopup.value = { idx, sku };
}

function closeTrendPopup(e) {
  if (trendPopup.value && !e.target.closest('.plan-trend-wrap')) trendPopup.value = null;
}

function applyTrendToPeriods(idx, count) {
  const item = items.value[idx];
  const trend = trendMap.value[item.sku];
  if (!item || !trend) return;
  snapshot();
  const periods = editablePeriodIndices.value;
  const applyCount = Math.min(count, periods.length);
  for (let i = 0; i < applyCount; i++) {
    const m = periods[i];
    const p = item.plan[m];
    if (!p) continue;
    p.trendPct = trend.pct;
    p.locked = false; // не фиксируем — пусть пересчитывается с трендом
  }
  recalcItem(idx, 0);
  _savePlanDraft();
  trendPopup.value = null;
  toast.success('Тренд применён', `${trend.pct > 0 ? '+' : ''}${trend.pct}% к ${applyCount} ${applyCount === 1 ? 'периоду' : 'периодам'}`);
}

onMounted(() => { document.addEventListener('click', closeTrendPopup); });
onBeforeUnmount(() => { document.removeEventListener('click', closeTrendPopup); });

async function restoreItemOrder() {
  const le = orderStore.settings.legalEntity;
  const sup = supplier.value || 'all';
  const { data } = await db.from('item_order').select('*').eq('supplier', sup).eq('legal_entity', le).order('position');
  if (!data?.length) return;
  const posMap = {}; data.forEach(r => { posMap[r.item_id] = r.position; });
  items.value.sort((a, b) => (posMap[a.productId] ?? 9999) - (posMap[b.productId] ?? 9999));
}

function onParamsChange() { supplierStore.loadSuppliers(orderStore.settings.legalEntity); recalcAll(); _savePlanDraft(); }
function onPlanningDateChange() { items.value.forEach(i => { i.plan = []; i.transit = []; }); recalcAll(); _savePlanDraft(); }
function onPeriodChange() { items.value.forEach(i => { i.plan = []; }); recalcAll(); triggerValidation(); _savePlanDraft(); }
function onFrequencyChange() { onPeriodChange(); }
function onPeriodCountChange() { const v = periodCountInput.value; if (v < 1) periodCountInput.value = 1; if (v > 12) periodCountInput.value = 12; onPeriodChange(); }

// Restore old periodValue format (w4, m3) into new frequency + count
function _restorePeriodValue(pv, freq, count) {
  if (freq && count) {
    periodFrequency.value = freq;
    periodCountInput.value = count;
    return;
  }
  // Legacy format: w1, w2, w4, w6, w8, w12, m1, m2, m3
  const type = pv.charAt(0);
  const num = parseInt(pv.slice(1)) || 1;
  if (type === 'm') {
    periodFrequency.value = 'm1';
    periodCountInput.value = num;
  } else {
    // w1=1week, w2=2weeks, w4/w6/w8/w12 = N weeks at 1/week frequency
    if (num <= 2) { periodFrequency.value = 'w' + num; periodCountInput.value = 4; }
    else { periodFrequency.value = 'w1'; periodCountInput.value = num; }
  }
}
function onConsumptionPeriodChange() {
  _validationCache = null;
  // НЕ обнуляем item.plan — иначе теряются заблокированные пользователем
  // (locked) ячейки и сохранённые тренды (trendPct). recalcItem сам
  // пересчитает need/deficit/orderBoxes для не-locked, и сохранит
  // как есть для locked.
  recalcAll(); triggerValidation(); _savePlanDraft();
}

// ─── Сохранение ────────────────────────────────────────────────────────────
async function savePlan() {
  if (!itemsWithPlan.value.length) { toast.error('Нет данных', ''); return; }
  saveNote.value = editingPlanId.value ? _loadedNote.value : '';
  _saveNoteInitial = saveNote.value;
  showSaveModal.value = true;
  nextTick(() => setTimeout(() => saveNoteInput.value?.focus(), 50));
}

async function confirmSave() {
  if (saving.value) return;
  saving.value = true;
  try {
  const planData = {
    legal_entity: orderStore.settings.legalEntity, supplier: supplier.value,
    period_type: periodType.value, period_count: periodCount.value, period_frequency: periodFrequency.value,
    truck_pallets: truckEnabled.value ? truckPallets.value : null,
    start_date: startDateStr.value,
    planning_date: planningDateStr.value || null,
    consumption_period_days: consumptionPeriodDays.value || 30,
    input_unit: inputUnit.value,
    note: saveNote.value.trim() || null,
    items: itemsWithPlan.value.map(i => ({
      sku: i.sku, name: i.name, qty_per_box: i.qtyPerBox, boxes_per_pallet: i.boxesPerPallet,
      multiplicity: i.multiplicity || 1, unit_of_measure: i.unitOfMeasure,
      monthly_consumption: i.monthlyConsumption, stock_on_hand: i.stockOnHand, stock_at_supplier: i.stockAtSupplier,
      transit: (i.transit || []).map(t => ({ qty: t.qty || 0 })),
      plan: i.plan.map(p => ({ month: p.month, order_boxes: p.orderBoxes, order_units: p.orderUnits, locked: p.locked || false }))
    })),
  };
  let error;
  let newPlanId = null;
  if (editingPlanId.value) {
    planData.updated_by = userStore.currentUser?.name || null;
    let q = db.from('plans').update(planData).eq('id', editingPlanId.value).eq('legal_entity', orderStore.settings.legalEntity);
    if (_loadedUpdatedAt.value) q = q.header('X-Expected-Updated-At', _loadedUpdatedAt.value);
    const res = await q;
    error = res.error;
    if (res.status === 409) {
      saving.value = false;
      showSaveModal.value = false;
      const ok = await confirmAction(
        'План изменён другим пользователем',
        'Кто-то ещё сохранил этот план, пока вы редактировали. Загрузить актуальную версию? Ваши несохранённые изменения будут потеряны.',
        { okText: 'Загрузить актуальную', cancelText: 'Оставить как есть' }
      );
      if (ok) await loadPlanFromHistory(editingPlanId.value);
      return;
    }
    // Обновляем _loadedUpdatedAt из ответа сервера, чтобы следующее сохранение прошло без 409
    if (!error && Array.isArray(res.data) && res.data[0]?.updated_at) {
      _loadedUpdatedAt.value = res.data[0].updated_at;
    } else if (!error && res.data?.updated_at) {
      _loadedUpdatedAt.value = res.data.updated_at;
    }
  } else {
    planData.created_by = userStore.currentUser?.name || null;
    const res = await db.from('plans').insert([planData]);
    error = res.error;
    if (res.data && res.data.id) newPlanId = res.data.id;
  }
  if (error) { toast.error('Ошибка', ''); saving.value = false; return; }
  try {
    const ld = { supplier: supplier.value, items_count: itemsWithPlan.value.length, period: `${periodCount.value} ${periodType.value === 'weeks' ? 'нед.' : 'мес.'}`, note: saveNote.value.trim() || null };
    if (editingPlanId.value && _prevPlanItems) {
      const ch = []; const pm = {}; _prevPlanItems.forEach(i => { pm[i.sku || i.name] = i; });
      const ni = planData.items; const nm = {}; ni.forEach(i => { nm[i.sku || i.name] = i; });
      ni.forEach(i => { if (!pm[i.sku || i.name]) ch.push({ type: 'added', item: `${i.sku ? i.sku + ' ' : ''}${i.name}`, boxes: (i.plan || []).reduce((s,p) => s + (p.order_boxes||0), 0) }); });
      _prevPlanItems.forEach(i => { if (!nm[i.sku || i.name]) ch.push({ type: 'removed', item: `${i.sku ? i.sku + ' ' : ''}${i.name}`, boxes: (i.plan || []).reduce((s,p) => s + (p.order_boxes||0), 0) }); });
      const hd = periodHeaders.value;
      ni.forEach(i => { const pv = pm[i.sku || i.name]; if (!pv) return; const df = [];
        if ((pv.monthly_consumption||0) !== (i.monthly_consumption||0)) df.push(`расход: ${pv.monthly_consumption}→${i.monthly_consumption}`);
        for (let m = 0; m < Math.max((pv.plan||[]).length, (i.plan||[]).length); m++) { const pb = (pv.plan||[])[m]?.order_boxes||0; const nb = (i.plan||[])[m]?.order_boxes||0; if (pb !== nb) df.push(`${hd[m]?.label||`п.${m+1}`}: ${pb}→${nb} кор`); }
        if (df.length) ch.push({ type: 'changed', item: `${i.sku ? i.sku + ' ' : ''}${i.name}`, diffs: df });
      });
      if (ch.length) ld.changes = ch;
    }
    await db.from('audit_log').insert({ action: editingPlanId.value ? 'plan_updated' : 'plan_created', entity_type: 'plan', entity_id: editingPlanId.value || newPlanId || null, user_name: userStore.currentUser?.name || null, legal_entity: orderStore.settings?.legalEntity || null, details: ld });
  } catch (e) { console.warn('[planning] audit log:', e); }
  // Уведомление только при редактировании чужого плана
  if (editingPlanId.value && _loadedCreatedBy.value && _loadedCreatedBy.value !== userStore.currentUser?.name) {
    try {
      const changes = ld.changes || [];
      const lines = [];
      changes.forEach(c => {
        if (c.type === 'added') lines.push(`+ ${c.item} (${c.boxes} кор.)`);
        else if (c.type === 'removed') lines.push(`− ${c.item} (${c.boxes} кор.)`);
        else if (c.type === 'changed') lines.push(`${c.item}: ${c.diffs.join(', ')}`);
      });
      await db.from('notifications').insert({
        type: 'plan',
        title: `${userStore.currentUser?.name} изменил ваш план: ${supplier.value}`,
        message: lines.join('\n') || 'Изменения в плане',
        entity_type: 'plan',
        entity_id: editingPlanId.value,
        legal_entity: orderStore.settings.legalEntity,
        created_by: userStore.currentUser?.name || null,
        target_user: _loadedCreatedBy.value,
        read_by: JSON.stringify([userStore.currentUser?.name || '']),
      });
    } catch(e) { console.warn('[planning] notification:', e); }
  }
  toast.success(editingPlanId.value ? 'План обновлён' : 'План сохранён', `${itemsWithPlan.value.length} позиций`);
  showSaveModal.value = false;
  draftStore.clearPlanDraft(); resetPlan();
  } finally { saving.value = false; }
}

async function copyPlanToClipboard() {
  const hd = periodHeaders.value;
  let text = `План ${supplier.value} (${periodCount.value} ${periodType.value === 'weeks' ? 'нед.' : 'мес.'}):\n\n`;
  for (let mi = 0; mi < hd.length; mi++) { const mi2 = itemsWithPlan.value.filter(i => i.plan[mi]?.orderBoxes > 0); if (!mi2.length) continue; text += `📅 ${hd[mi].label}:\n`; mi2.forEach(i => { const p = i.plan[mi]; text += `${i.sku ? i.sku + ' ' : ''}${i.name} (${nf.format(p.orderUnits)} ${i.unitOfMeasure}) - ${p.orderBoxes} кор\n`; }); text += '\n'; }
  text += 'Спасибо!';
  await copyToClipboard(text); toast.success('Скопировано!', '');
}

// ─── История изменений ────────────────────────────────────────────────────────────
async function openLogModal() {
  const id = editingPlanId.value;
  if (!id) return;
  logModal.value = { show: true, loading: true, entries: [] };
  const { data } = await db.from('audit_log').select('*')
    .eq('entity_id', id).eq('entity_type', 'plan')
    .order('created_at', { ascending: false }).limit(50);
  if (!logModal.value.show) return;
  logModal.value.entries = data || [];
  logModal.value.loading = false;
}
function logBadgeLabel(action) {
  return { plan_created:'Создан', plan_updated:'Изменён', plan_deleted:'Удалён' }[action] || action;
}
function logBadgeClass(action) {
  if (action.includes('created')) return 'log-badge-created';
  if (action.includes('updated')) return 'log-badge-updated';
  if (action.includes('deleted')) return 'log-badge-deleted';
  return '';
}
function formatLogDate(str) {
  if (!str) return '';
  const d = new Date(str);
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' }) + ' ' +
         d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

function resetPlan() {
  editingPlanId.value = null; viewOnly.value = false; showFullPlan.value = false; expandedSummaryPeriods.value = {}; _prevPlanItems = null; _loadedCreatedBy.value = null; _loadedNote.value = ''; _loadedUpdatedAt.value = null;
  items.value.forEach(i => { i.plan = []; i.monthlyConsumption = 0; i.stockOnHand = 0; i.stockAtSupplier = 0; });
  undoStack.value = []; redoStack.value = [];
  recalcAll(); draftStore.clearPlanDraft();
}

let _loadPlanGen = 0;
async function loadPlanFromHistory(planId) {
  const gen = ++_loadPlanGen;
  const { data: plan, error } = await db.from('plans').select('*').eq('id', planId).single();
  if (gen !== _loadPlanGen) return; // устаревший запрос
  if (error || !plan) { toast.error('Ошибка', ''); return; }
  const planEntity = plan.legal_entity || DEFAULT_ENTITY;
  const allowed = userStore.getAllowedEntities();
  if (allowed && !allowed.includes(planEntity)) {
    toast.error('Нет доступа', 'У вас нет доступа к юрлицу этого плана');
    return;
  }
  orderStore.settings.legalEntity = planEntity;
  supplier.value = plan.supplier || '';
  // Restore frequency and count from saved plan
  const savedPV = (plan.period_type === 'weeks' ? 'w' : 'm') + plan.period_count;
  // Map old format to new frequency + count
  if (plan.period_type === 'weeks') {
    // Detect frequency: if period_count was from old w2/w4 format, the frequency was always w1
    // For new format, we store frequency separately
    periodFrequency.value = plan.period_frequency || 'w1';
    periodCountInput.value = plan.period_count || 4;
  } else {
    periodFrequency.value = plan.period_frequency || 'm1';
    periodCountInput.value = plan.period_count || 3;
  }
  startDateStr.value = plan.start_date || toLocalDateStr(new Date());
  planningDateStr.value = plan.planning_date || '';
  consumptionPeriodDays.value = plan.consumption_period_days || 30;
  showFullPlan.value = false;
  expandedSummaryPeriods.value = {};
  inputUnit.value = plan.input_unit || 'boxes';
  truckEnabled.value = !!plan.truck_pallets;
  truckPallets.value = plan.truck_pallets || 32;
  editingPlanId.value = plan.id;
  _loadedCreatedBy.value = plan.created_by || null;
  _loadedNote.value = plan.note || '';
  _loadedUpdatedAt.value = plan.updated_at || null;
  await supplierStore.loadSuppliers(orderStore.settings.legalEntity);
  _prevPlanItems = JSON.parse(JSON.stringify(plan.items || []));
  items.value = (plan.items || []).map(i => ({
    productId: null, sku: i.sku || '', name: i.name || '', qtyPerBox: i.qty_per_box || 1,
    boxesPerPallet: i.boxes_per_pallet || null, unitOfMeasure: i.unit_of_measure || 'шт',
    multiplicity: i.multiplicity || 1, analogGroup: '', monthlyConsumption: i.monthly_consumption || 0,
    stockOnHand: i.stock_on_hand || 0, stockAtSupplier: i.stock_at_supplier || 0, _cw: false, _ct: '',
    transit: (i.transit || []).map(t => ({ qty: t.qty || 0 })),
    plan: (i.plan || []).map(p => ({ month: p.month, need: 0, deficit: 0, orderBoxes: p.order_boxes || 0, orderUnits: p.order_units || 0, locked: p.locked || false }))
  }));
  // Подгружаем analog_group для трендов
  const planSkus = items.value.map(i => i.sku).filter(Boolean);
  if (planSkus.length) {
    const { data: prods } = await db.from('products').select('sku, analog_group').in('sku', planSkus);
    if (prods) {
      const agMap = {};
      for (const p of prods) if (p.analog_group) agMap[String(p.sku)] = p.analog_group;
      items.value.forEach(i => { if (agMap[i.sku]) i.analogGroup = agMap[i.sku]; });
    }
  }
  undoStack.value = []; redoStack.value = [];
  items.value.forEach((_, idx) => recalcItem(idx, 0));
  triggerValidation();
  loadPlanPrices();
  loadTrends();
  toast.success('План загружен', `${plan.supplier} — ${items.value.length} позиций`);
}

let _planLeChangeId = 0;
watch(() => orderStore.settings.legalEntity, async (le) => {
  const myId = ++_planLeChangeId;
  await supplierStore.loadSuppliers(le);
  if (myId !== _planLeChangeId) return;
  const prev = supplier.value;
  supplier.value = '';
  items.value = []; editingPlanId.value = null; viewOnly.value = false; _prevPlanItems = null;
  // Если был тот же поставщик — watch(supplier) не сработает, очистим вручную
  if (prev === '') { allSupplierProducts.value = []; }
});
const showCollapseHint = ref(false);
let _collapseHintTimer = null;
watch(supplier, (v) => { if (v && settingsExpanded.value) { showCollapseHint.value = true; clearTimeout(_collapseHintTimer); _collapseHintTimer = setTimeout(() => { showCollapseHint.value = false; }, 4000); } });

function hasPlanUnsavedData() {
  if (viewOnly.value || !items.value.length) return false;
  return items.value.some(i => i.monthlyConsumption > 0 || i.stockOnHand > 0 || i.stockAtSupplier > 0 || i.plan?.some(p => p.orderBoxes > 0 || p.orderUnits > 0));
}

function onPlanBeforeUnload(e) {
  if (hasPlanUnsavedData()) { e.preventDefault(); }
}

onMounted(async () => {
  planDraftTickTimer = setInterval(() => { planDraftTick.value++; }, 30000);
  window.addEventListener('beforeunload', onPlanBeforeUnload);
  if (!supplier.value) settingsExpanded.value = true;
  await supplierStore.loadSuppliers(orderStore.settings.legalEntity);
  if (route.query.planId) {
    await loadPlanFromHistory(route.query.planId);
    if (route.query.mode === 'view' || isViewer.value) { viewOnly.value = true; }
  } else {
    const draft = draftStore.hasPlanDraft();
    if (draft) {
      const ok = await confirmAction('Восстановить черновик?', `от ${draft.date} (${draft.supplier}, ${draft.itemsCount} поз.)`);
      if (ok) { const d = draftStore.loadPlanDraft(); if (d) { supplier.value = d.supplier || ''; _restorePeriodValue(d.periodValue || 'm3', d.periodFrequency, d.periodCountInput); startDateStr.value = d.startDateStr || toLocalDateStr(new Date()); planningDateStr.value = d.planningDateStr || ''; inputUnit.value = d.inputUnit || 'boxes'; consumptionPeriodDays.value = d.consumptionPeriodDays || 30; editingPlanId.value = d.editingPlanId || null; truckEnabled.value = d.truckEnabled || false; truckPallets.value = d.truckPallets || 32; items.value = (d.items || []).map(i => ({ ...i, _cw: false, _ct: '' })); recalcAll(); loadPlanPrices(); toast.info('Черновик загружен', ''); } }
      else { draftStore.clearPlanDraft(); }
    }
  }
});

onBeforeUnmount(() => {
  clearTimeout(_vTimer);
  clearTimeout(_collapseHintTimer);
  if (planDraftTickTimer) clearInterval(planDraftTickTimer);
  window.removeEventListener('beforeunload', onPlanBeforeUnload);
});

onBeforeRouteLeave(async () => {
  if (hasPlanUnsavedData()) {
    window.removeEventListener('beforeunload', onPlanBeforeUnload);
    draftStore.savePlan({ supplier: supplier.value, periodValue: periodValue.value, periodFrequency: periodFrequency.value, periodCountInput: periodCountInput.value, startDateStr: startDateStr.value, planningDateStr: planningDateStr.value, inputUnit: inputUnit.value, consumptionPeriodDays: consumptionPeriodDays.value, truckEnabled: truckEnabled.value, truckPallets: truckPallets.value, items: items.value, viewOnly: viewOnly.value, editingPlanId: editingPlanId.value });
    const ok = await confirmAction('Несохранённые данные', 'Вы не сохранили план. Уйти со страницы?');
    if (!ok) { window.addEventListener('beforeunload', onPlanBeforeUnload); return false; }
  }
});

// Реактивная навигация: если query изменился когда компонент уже смонтирован
watch(() => route.query.planId, async (newId) => {
  if (!newId) return;
  await loadPlanFromHistory(newId);
  if (route.query.mode === 'view' || isViewer.value) { viewOnly.value = true; }
});
</script>

<style scoped>
.draft-status {
  font-size: 11px; color: var(--text-muted); text-align: right;
  padding: 4px 8px 0; font-weight: 500;
}
/* Log modal */
.log-entries { display: flex; flex-direction: column; }
.log-entry { padding: 10px 0; border-bottom: 1px solid var(--border-light); }
.log-entry:last-child { border-bottom: none; }
.log-entry-head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.log-badge { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 10px; font-weight: 700; }
.log-badge-created { background: #E8F5E9; color: #2E7D32; }
.log-badge-updated { background: #FFF3E0; color: #E65100; }
.log-badge-deleted { background: #FFEBEE; color: #C62828; }
.log-author { font-weight: 600; font-size: 12px; color: var(--text); }
.log-date { font-size: 11px; color: var(--text-muted); }
.log-note-line { font-size: 11px; color: var(--text-secondary); font-style: italic; margin-top: 3px; }
.log-meta { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
.log-changes { display: flex; flex-wrap: wrap; gap: 3px; margin-top: 5px; }
.log-ch-chip { display: inline-block; padding: 1px 6px; border-radius: 4px; font-size: 10px; font-weight: 600; line-height: 1.5; }
.log-ch-add { background: #E8F5E9; color: #2E7D32; }
.log-ch-del { background: #FFEBEE; color: #C62828; }
.log-ch-upd { background: #FFF8E1; color: #5D4037; }

.plan-td-input input { -moz-appearance: textfield; width: 72px; text-align: center; }
.plan-td-input input::-webkit-outer-spin-button, .plan-td-input input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.plan-calc-input { -moz-appearance: textfield !important; }
.plan-calc-input::-webkit-outer-spin-button, .plan-calc-input::-webkit-inner-spin-button { -webkit-appearance: none !important; margin: 0 !important; }
.plan-td-result { text-align: center; padding: 4px; min-width: 100px; cursor: default; transition: background 0.15s; }
.plan-td-result:not(:first-of-type) { cursor: pointer; }
.plan-td-result:hover { background: #fdf9f3; }
.plan-has-value { background: #fff8f0; }
.plan-result-value { display: block; font-weight: 700; color: var(--text); font-size: 13px; }
.plan-result-sub { display: block; font-size: 10px; color: var(--text-muted); margin-top: 1px; }
.plan-result-zero { color: var(--text-muted); font-size: 10px; }
.plan-cell-locked { background: #fff8e1 !important; border: 1px dashed var(--bk-orange) !important; }
.plan-result-value.plan-cell-locked { color: #e65100; }
.plan-cell-trend { background: #FFF3E0 !important; border: 1px solid #FFB74D !important; }
.plan-pallet-period, .plan-reset-cell { display: inline-block; font-size: 10px; cursor: pointer; margin-left: 2px; opacity: 0.5; transition: opacity 0.15s; vertical-align: middle; }
.plan-pallet-period:hover, .plan-reset-cell:hover { opacity: 1; }
.plan-reset-cell { color: #d32f2f; font-weight: 700; }
.plan-th-total { min-width: 70px; background: rgba(244,162,97,0.15) !important; }
.plan-td-total { text-align: center; min-width: 80px; border-left: 2px solid var(--bk-orange); padding: 4px 6px; }
.plan-td-total.plan-has-value { background: rgba(244,162,97,0.08); }
.plan-total-boxes { display: block; font-weight: 700; font-size: 13px; color: var(--bk-brown); }
.plan-total-units { display: block; font-size: 10px; color: var(--text-muted); margin-top: 1px; }
.plan-total-price { display: block; font-size: 10px; color: #1B5E20; margin-top: 1px; }
.consumption-warning { color: #d32f2f !important; font-weight: 700 !important; border-color: #d32f2f !important; background: #ffebee !important; }

/* Колонка запаса (дней) */
.plan-th-sales { min-width: 55px; text-align: center; width: 65px; }
.plan-td-sales { text-align: center; padding: 2px 4px; font-size: 12px; color: #555; font-variant-numeric: tabular-nums; }
.plan-th-trend { min-width: 50px; text-align: center; width: 55px; }
.plan-td-trend { text-align: center; padding: 2px 4px; }
.plan-th-reserve { min-width: 50px; text-align: center; }
.plan-td-reserve { text-align: center; font-weight: 700; font-size: 13px; color: var(--text-muted); border-right: 2px solid var(--border-light); }
.plan-td-reserve.reserve-danger { color: #d32f2f; background: #ffebee; }
.plan-td-reserve.reserve-warning { color: #e65100; background: #fff3e0; }
.plan-td-reserve.reserve-ok { color: #2e7d32; }

/* (#4) Sticky name column — solid bg to prevent bleed-through */
.plan-th-name { text-align: left !important; padding-left: 10px !important; min-width: 200px; position: sticky; left: 0; z-index: 22; background: inherit; }
.plan-td-name { text-align: left !important; padding-left: 14px !important; position: sticky; left: 0; z-index: 2; background: #ffffff !important; border-right: 1px solid var(--border-light); min-width: 200px; }
.plan-table tbody tr:hover .plan-td-name { background: #fffbf0 !important; }
.plan-table tbody tr.has-order .plan-td-name { background: #f7faf5 !important; }
.plan-table tbody tr.has-order:hover .plan-td-name { background: #f2f7ee !important; }
.plan-totals .plan-td-name { background: #fdf9f2 !important; }

/* ─── Plan Compact Mode ─── */
.plan-compact :deep(.order-table td),
.plan-compact :deep(.plan-table td),
.plan-compact td { padding: 3px 5px; }
.plan-compact :deep(.order-table thead th),
.plan-compact :deep(.plan-table thead th),
.plan-compact th { padding: 5px 5px; font-size: 9px; letter-spacing: 0.3px; }
.plan-compact .plan-td-name { padding-left: 8px !important; padding-top: 2px !important; padding-bottom: 2px !important; cursor: help; }
.plan-compact .plan-td-input input { width: 60px; padding: 2px 3px; font-size: 12px; height: 24px; }
.plan-compact .plan-td-result { padding: 2px 3px; min-width: 70px; }
.plan-compact .plan-result-value { font-size: 12px; }
.plan-compact .plan-result-zero { font-size: 9px; }
.plan-compact .plan-pallet-period { display: none; }
.plan-compact .plan-td-result:hover .plan-pallet-period { display: inline-block; }
.plan-compact .plan-totals td { padding: 6px 5px; font-size: 12px; }
.plan-compact .plan-total-cell.plan-has-value { font-size: 13px; }
.plan-compact .plan-th-month { cursor: help; }
.plan-compact .plan-edit-input { width: 50px !important; font-size: 12px !important; padding: 1px 3px !important; }

/* Mobile styles moved to global style.css — .planning-view scope */

/* Hidden items */
.item-hidden { opacity: 0.7; }
.hidden-badge { background:#FFEBEE; color:#E57373; font-size:10px; font-weight:600; border:1px solid #E57373; border-radius:3px; padding:1px 4px; margin-left:6px; vertical-align:middle; }

/* ═══ Plan Summary Card — period blocks ═══ */
.psc-periods {
  max-height: 450px;
  overflow-y: auto;
}
.psc-period-block {
  border-bottom: 1px solid var(--border-light);
}
.psc-period-block:last-child { border-bottom: none; }
.psc-period-header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 20px;
  cursor: pointer;
  user-select: none;
  transition: background 0.15s;
}
.psc-period-header:hover { background: #fdf9f3; }
.psc-period-title {
  flex: 1;
  font-weight: 600;
  font-size: 14px;
  color: var(--text);
}
.psc-period-sub {
  font-weight: 400;
  font-size: 11px;
  color: var(--text-muted);
  margin-left: 4px;
}
.psc-period-total {
  font-weight: 700;
  font-size: 13px;
  color: var(--bk-brown);
  white-space: nowrap;
}
.psc-period-chevron {
  color: var(--text-muted);
  flex-shrink: 0;
}
.psc-period-items {
  border-top: 1px solid var(--border-light);
  background: var(--bg);
}
.psc-period-items .osc-item {
  padding: 5px 20px 5px 32px;
}

/* ═══ Plan Summary Card ═══ */
.order-summary-wrap {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: 1;
  min-height: 0;
  padding: 24px 16px;
}
.order-summary-card {
  background: var(--card);
  border-radius: 12px;
  border: 1.5px solid var(--border);
  padding: 0;
  max-width: 760px;
  width: 100%;
  overflow: hidden;
}
.osc-header {
  padding: 16px 20px 12px;
  border-bottom: 1px solid var(--border-light);
}
.osc-supplier {
  font-size: 18px;
  font-weight: 700;
  color: var(--text);
  margin-bottom: 4px;
}
.osc-meta {
  display: flex;
  gap: 12px;
  font-size: 12px;
  color: var(--text-muted);
}
.osc-dates {
  display: flex;
  flex-wrap: wrap;
  gap: 0;
  padding: 0;
  border-bottom: 1px solid var(--border-light);
}
.osc-date-item {
  flex: 1;
  min-width: 80px;
  padding: 10px 16px;
  text-align: center;
  border-right: 1px solid var(--border-light);
}
.osc-date-item:last-child { border-right: none; }
.osc-date-label {
  display: block;
  font-size: 10px;
  font-weight: 600;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.3px;
  margin-bottom: 2px;
}
.osc-date-val {
  font-size: 15px;
  font-weight: 700;
  color: var(--text);
}
.osc-note {
  padding: 8px 20px;
  font-size: 12px;
  color: var(--text-secondary);
  background: var(--bg);
  border-bottom: 1px solid var(--border-light);
}
.osc-items {
  max-height: 400px;
  overflow-y: auto;
}
.osc-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 7px 20px;
  border-bottom: 1px solid var(--border-light);
  font-size: 13px;
}
.osc-item:last-child { border-bottom: none; }
.osc-item:nth-child(even) { background: #FDFCFB; }
.osc-item-name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 500;
  color: var(--text);
}
.osc-item-qty {
  font-variant-numeric: tabular-nums;
  color: var(--text-secondary);
  white-space: nowrap;
  font-size: 12px;
}
.osc-actions {
  display: flex;
  gap: 8px;
  padding: 14px 20px;
  border-top: 1px solid var(--border-light);
  justify-content: center;
}
@media (max-width: 480px) {
  .order-summary-wrap { padding: 12px 0; }
  .order-summary-card { border-radius: 0; border-left: none; border-right: none; }
  .osc-header { padding: 12px 14px 10px; }
  .osc-date-item { padding: 8px 10px; }
  .osc-item { padding: 6px 14px; }
  .osc-actions { padding: 12px 14px; }
}

/* ─── Truck capacity ──────────────────────────────────────────────────────── */
.plan-truck-row td { padding: 4px 6px; font-size: 11px; text-align: center; border-top: 1px solid var(--border-light); }
.plan-truck-label { text-align: right !important; font-weight: 600; color: var(--text-muted); white-space: nowrap; padding-right: 10px !important; }
.plan-truck-cell { vertical-align: top; }
.plan-truck-pallets { font-weight: 700; color: var(--bk-brown); }
.plan-truck-item { display: flex; gap: 2px; align-items: center; justify-content: center; font-size: 11px; line-height: 1.3; }
.plan-truck-num { color: var(--text-muted); font-weight: 600; }
.plan-truck-val { font-weight: 700; }
.plan-truck-pct { color: var(--text-muted); font-size: 10px; }
.plan-truck-full .plan-truck-val { color: var(--bk-green, #2E7D32); }
.plan-truck-low .plan-truck-val { color: var(--bk-orange, #E65100); }
.plan-truck-row-detail td { border-top: none; }
.plan-order-btn-row td { border-top: none; padding: 2px 4px; }
.plan-create-order-btn { font-size: 10px !important; padding: 2px 6px !important; white-space: nowrap; }
.pf-group-truck { display: flex; align-items: center; gap: 6px; }
.pf-group-truck label { display: flex; align-items: center; gap: 2px; white-space: nowrap; }

/* ─── Exclude items ───────────────────────────────────────────────────────── */
.item-excluded { opacity: 0.45; }
.item-excluded td { background: var(--surface-alt, #f5f5f5) !important; }
.plan-exclude-btn { border: none; background: none; cursor: pointer; padding: 1px 3px; border-radius: 3px; color: var(--bk-green, #2E7D32); font-size: 12px; line-height: 1; }
.plan-exclude-btn:hover { background: rgba(0,0,0,0.06); }
.plan-exclude-btn.is-excluded { color: var(--text-muted, #999); }
.plan-existing-order { font-size: 10px; color: #1565C0; background: #E3F2FD; border-radius: 3px; padding: 0 4px; margin-top: 2px; white-space: nowrap; line-height: 1.4; }
.excluded-badge { font-size: 10px; color: var(--text-muted, #999); background: rgba(0,0,0,0.06); padding: 1px 5px; border-radius: 3px; margin-left: 4px; white-space: nowrap; }
</style>
<style>
.plan-trend-popup { position: fixed; background: #fff; border: 1px solid #ddd; border-radius: 8px; box-shadow: 0 6px 20px rgba(0,0,0,0.2); z-index: 9999; padding: 8px 10px; min-width: 140px; white-space: nowrap; }
.plan-trend-popup-title { font-size: 12px; font-weight: 700; margin-bottom: 2px; }
.plan-trend-popup-info { font-size: 10px; color: #888; margin-bottom: 6px; }
.plan-trend-popup-btns { display: flex; gap: 4px; flex-wrap: wrap; }
.plan-trend-apply-btn { padding: 4px 8px; font-size: 11px; font-weight: 600; border: 1px solid #ddd; border-radius: 4px; background: #f8f8f8; cursor: pointer; white-space: nowrap; }
.plan-trend-apply-btn:hover { background: #FFF3E0; border-color: #E65100; color: #E65100; }
</style>
