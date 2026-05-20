<template>
  <div class="sla-view">
    <!-- ═══ Шапка ═══ -->
    <div class="sla-header">
      <div>
        <router-link :to="{ name: 'shelf-life' }" class="sla-back">← К срокам годности</router-link>
        <h1 class="sla-title">Аналитика ячеек</h1>
        <p class="sla-sub">
          <span v-if="filterEntity">Юрлицо: <b>{{ filterEntity }}</b> · </span>
          <span v-else-if="mergeEntities && availableEntities.length > 1">Все юрлица сведены в одно · </span>
          <span v-else-if="availableEntities.length > 1">Все юрлица раздельно · </span>
          Сводка для презентации руководителю — графики, KPI и экспорт.
        </p>
      </div>
    </div>

    <!-- ═══ Контролы периода и группировки ═══ -->
    <div class="sla-toolbar">
      <div class="sla-toolbar-row" v-if="availableEntities.length > 1">
        <span class="sla-toolbar-label">Юрлицо:</span>
        <button
          class="sla-chip"
          :class="{ active: !filterEntity }"
          @click="filterEntity = ''; syncToUrl()"
        >Все ({{ availableEntities.length }})</button>
        <button
          v-for="e in availableEntities"
          :key="e"
          class="sla-chip"
          :class="{ active: filterEntity === e }"
          @click="filterEntity = e; syncToUrl()"
        >{{ shortEntityName(e) }}</button>
      </div>

      <div class="sla-toolbar-row">
        <span class="sla-toolbar-label">Период:</span>
        <button
          v-for="p in PRESETS"
          :key="p.key"
          class="sla-chip"
          :class="{ active: presetKey === p.key }"
          @click="applyPreset(p.key)"
        >{{ p.label }}</button>
        <span v-if="presetKey === 'custom'" class="sla-toolbar-range">
          <input type="date" v-model="customStart" @change="applyCustomRange" class="sla-date" />
          <span class="sla-date-sep">—</span>
          <input type="date" v-model="customEnd" @change="applyCustomRange" class="sla-date" />
        </span>
      </div>

      <div class="sla-toolbar-row">
        <span class="sla-toolbar-label" title="Размер столбика на графике: каждый столбик — это день, неделя или месяц">Шаг времени:</span>
        <button
          v-for="g in GRANULARITY"
          :key="g.key"
          class="sla-chip"
          :class="{ active: granularity === g.key }"
          @click="granularity = g.key"
          :title="`Группировать данные по ${g.label.toLowerCase()}`"
        >{{ g.label }}</button>

        <span class="sla-toolbar-divider"></span>

        <span class="sla-toolbar-label" title="Что показывают цветные группы на графике">Цвета по:</span>
        <button
          v-for="g in GROUPING"
          :key="g.key"
          class="sla-chip"
          :class="{ active: groupBy === g.key }"
          @click="groupBy = g.key"
          :title="g.key === 'entity' ? 'Каждое юрлицо своим цветом' : 'Каждый тип хранения (сухой/холод/мороз) своим цветом'"
        >{{ g.label }}</button>

        <span class="sla-toolbar-divider"></span>

        <label class="sla-toggle" title="Показывает на графике пунктирную линию с данными за такой же по длине предыдущий период">
          <input type="checkbox" v-model="comparePrev" />
          Сравнить с прошлым периодом
        </label>

        <label v-if="!filterEntity && availableEntities.length > 1" class="sla-toggle" title="Сложить значения всех юрлиц в один общий показатель">
          <input type="checkbox" v-model="mergeEntities" @change="syncToUrl" />
          Сложить склады в один
        </label>

        <label class="sla-toggle" title="Подписать каждый столбик графика цифрой">
          <input type="checkbox" v-model="showValueLabels" />
          Подписи на графике
        </label>
      </div>
    </div>

    <!-- ═══ Состояние ═══ -->
    <div v-if="loading" class="sla-empty"><BurgerSpinner text="Загрузка аналитики..." /></div>
    <div v-else-if="error" class="sla-empty sla-error">{{ error }}</div>
    <div v-else-if="!rawRows.length" class="sla-empty">
      Нет данных за выбранный период. Загрузите файл остатков склада во вкладке «Ячейки».
    </div>

    <template v-else>
      <!-- ═══ Блоки по юрлицам (KPI + инсайт) ═══ -->
      <section v-for="block in entityBlocks" :key="'eb-' + block.key" class="sla-entity-block">
        <header v-if="entityBlocks.length > 1 || mergeEntities || filterEntity" class="sla-entity-header">
          <span class="sla-entity-dot" :style="{ background: ENTITY_COLORS[block.label] || '#8B7355' }"></span>
          <h2 class="sla-entity-title">{{ block.label }}</h2>
        </header>

        <div class="sla-kpi-grid">
          <article v-for="kpi in block.kpis" :key="kpi.key" class="sla-kpi" :class="'sla-kpi--' + kpi.tone">
            <div class="sla-kpi-head">
              <div class="sla-kpi-label">{{ kpi.label }}</div>
              <div v-if="kpi.deltaPct !== null" class="sla-kpi-delta" :class="kpi.deltaPct >= 0 ? 'up' : 'down'">
                {{ kpi.deltaPct >= 0 ? '↑' : '↓' }} {{ Math.abs(kpi.deltaPct).toFixed(1) }}%
              </div>
            </div>
            <div class="sla-kpi-value">
              {{ kpi.value }}
              <span v-if="kpi.unit" class="sla-kpi-unit">{{ kpi.unit }}</span>
            </div>
            <div v-if="kpi.subtitle" class="sla-kpi-sub">{{ kpi.subtitle }}</div>
            <svg v-if="kpi.sparkline?.length" class="sla-kpi-spark" viewBox="0 0 100 24" preserveAspectRatio="none">
              <polyline
                :points="sparklinePoints(kpi.sparkline)"
                fill="none"
                :stroke="sparkColor(kpi.tone)"
                stroke-width="1.6"
                stroke-linecap="round"
                stroke-linejoin="round"
              />
            </svg>
          </article>
        </div>

        <div class="sla-insight">
          <div class="sla-insight-icon">💡</div>
          <p>{{ block.insight }}</p>
        </div>
      </section>

      <!-- ═══ Экспорт (единственная секция скачиваний) ═══ -->
      <section class="sla-export-section">
        <div class="sla-export-section-text">
          <h3>Готово к презентации?</h3>
          <p>Скачайте сводку в нужном формате — все KPI, графики и таблицы вшиты внутрь.</p>
        </div>
        <div class="sla-export-section-buttons">
          <div class="sla-export-wrap" ref="exportWrapEl">
            <button class="sla-export-btn" :disabled="!rawRows.length" @click.stop="chartExportMenu = !chartExportMenu">
              <span class="sla-export-btn-ico">🖼</span>
              <span>График PNG / SVG ▾</span>
            </button>
            <div v-if="chartExportMenu" class="sla-export-menu">
              <button class="sla-export-item" @click="chartExportMenu = false; downloadChartPng(false)">PNG · светлый</button>
              <button class="sla-export-item" @click="chartExportMenu = false; downloadChartPng(true)">PNG · тёмный</button>
              <button class="sla-export-item" @click="chartExportMenu = false; downloadChartSvg(false)">SVG · светлый</button>
              <button class="sla-export-item" @click="chartExportMenu = false; downloadChartSvg(true)">SVG · тёмный</button>
            </div>
          </div>
          <button class="sla-export-btn" :disabled="!rawRows.length || !!exportBusy" @click="runExport(downloadExcel, 'excel')">
            <span class="sla-export-btn-ico">📊</span>
            <span>{{ exportBusy === 'excel' ? 'Готовится...' : 'Excel' }}</span>
          </button>
          <button class="sla-export-btn" :disabled="!rawRows.length || !!exportBusy" @click="runExport(downloadPdf, 'pdf')">
            <span class="sla-export-btn-ico">📄</span>
            <span>{{ exportBusy === 'pdf' ? 'Готовится...' : 'PDF' }}</span>
          </button>
          <button class="sla-export-btn primary" :disabled="!rawRows.length || !!exportBusy" @click="runExport(downloadPptx, 'pptx')">
            <span class="sla-export-btn-ico">📽</span>
            <span>{{ exportBusy === 'pptx' ? 'Готовится...' : 'PowerPoint' }}</span>
          </button>
        </div>
      </section>

      <!-- ═══ Главный график ═══ -->
      <section class="sla-chart-card">
        <header class="sla-chart-head">
          <h3>Динамика загрузки</h3>
          <div class="sla-chart-legend">
            <span
              v-for="s in [...chartSeries, ...comparisonSeries]"
              :key="s.key"
              class="sla-legend-item"
              :class="{ off: hiddenSeries.has(s.key), dashed: s.dashed }"
              @click="toggleSeries(s.key)"
            >
              <span class="sla-legend-dot" :style="{ background: s.color }"></span>
              {{ s.label }}
            </span>
          </div>
        </header>

        <div class="sla-chart-wrap">
          <svg :viewBox="`0 0 ${CHART_W} ${CHART_H}`" class="sla-chart-svg" preserveAspectRatio="none" @mouseleave="hideTooltip">
            <!-- Сетка Y -->
            <line v-for="g in yGrid" :key="g.value"
                  :x1="PAD_L" :x2="CHART_W - PAD_R" :y1="g.y" :y2="g.y"
                  stroke="#ECE3D6" stroke-width="0.6"/>
            <text v-for="g in yGrid" :key="'l'+g.value"
                  :x="PAD_L - 6" :y="g.y + 3" font-size="10" fill="#8B7355" text-anchor="end">{{ g.value }}</text>

            <!-- Подписи X -->
            <text v-for="(k, i) in xLabels" :key="'x'+k"
                  v-show="i % Math.max(1, Math.ceil(xLabels.length / 12)) === 0 || i === xLabels.length - 1"
                  :x="xPositions.get(k)" :y="CHART_H - 10"
                  font-size="10" fill="#8B7355" text-anchor="middle">
              {{ bucketLabel(k, granularity).split('-')[0] }}
            </text>

            <!-- Линия сравнения (бэкграунд) -->
            <template v-for="s in comparisonSeries" :key="s.key">
              <path v-if="!hiddenSeries.has(s.key)"
                    :d="pathFor(s.points)" :stroke="s.color"
                    stroke-width="2" stroke-dasharray="4 4" fill="none"
                    opacity="0.45"/>
            </template>

            <!-- Аннотации событий -->
            <template v-for="a in chartAnnotations" :key="'a'+a.id">
              <line :x1="a.x" :x2="a.x" :y1="PAD_T" :y2="CHART_H - PAD_B"
                    :stroke="a.color" stroke-width="1.4" stroke-dasharray="4 3" opacity="0.7"/>
              <g :transform="'translate(' + a.x + ', ' + (PAD_T + 4) + ')'">
                <rect x="-5" y="-3" width="10" height="10" rx="2" :fill="a.color"/>
              </g>
              <text :x="a.x + 8" :y="PAD_T + 12" font-size="10" :fill="a.color" font-weight="700">{{ a.label }}</text>
            </template>

            <!-- Основные линии и точки -->
            <template v-for="s in chartSeries" :key="s.key">
              <path v-if="!hiddenSeries.has(s.key)"
                    :d="pathFor(s.points)" :stroke="s.color"
                    stroke-width="2.4" fill="none" stroke-linejoin="round" stroke-linecap="round"/>
              <circle v-for="(p, i) in (hiddenSeries.has(s.key) ? [] : s.points)" :key="'p'+i"
                      :cx="xPositions.get(p.x)" :cy="chartY(p.y)" r="3.5"
                      :fill="s.color" stroke="#fff" stroke-width="1.5"
                      style="cursor:pointer"
                      @mouseenter="showTooltip(s, p, $event)"
                      @click="onPointClick(p, $event)"/>
              <!-- Цифры значений над точками (для презентации) -->
              <template v-if="showValueLabels && !hiddenSeries.has(s.key)">
                <text v-for="(p, i) in s.points" :key="'pl'+i"
                      :x="xPositions.get(p.x)"
                      :y="chartY(p.y) - 7"
                      font-size="10"
                      font-weight="700"
                      :fill="s.color"
                      text-anchor="middle"
                      paint-order="stroke"
                      stroke="#fff"
                      stroke-width="2.5"
                      style="pointer-events:none">{{ p.y }}</text>
              </template>
            </template>
          </svg>

          <div v-if="chartTooltip"
               class="sla-chart-tooltip"
               :style="{ left: chartTooltip.x + 'px', top: chartTooltip.y + 'px' }">
            <div class="sla-chart-tooltip-title">{{ chartTooltip.title }}</div>
            <div>
              <span class="sla-tooltip-dot" :style="{ background: chartTooltip.color }"></span>
              {{ chartTooltip.label }}: <b>{{ chartTooltip.value }}</b>
            </div>
          </div>
        </div>
        <p class="sla-chart-hint" v-if="granularity === 'day'">
          Подсказка: <b>Shift+клик</b> по точке — добавить или изменить метку события на графике.
        </p>
      </section>

      <!-- ═══ Среднемесячные значения по типам хранения ═══ -->
      <section v-if="monthlyAverages" class="sla-table-card">
        <header class="sla-chart-head">
          <h3>Среднемесячные значения по складам</h3>
          <span class="sla-table-hint">Среднее по дням внутри месяца, по каждому типу хранения</span>
        </header>
        <div v-for="g in monthlyAverages.groups" :key="g.entity" class="sla-table-wrap">
          <div v-if="!filterEntity" class="sla-table-entity-name">{{ g.entity }}</div>
          <div class="sla-table-scroll">
            <table class="sla-table">
              <thead>
                <tr>
                  <th class="sla-table-th-month">Месяц</th>
                  <th v-for="t in monthlyAverages.types" :key="t">{{ STOCK_TYPE_LABELS[t] || t }}</th>
                  <th class="sla-table-th-total">Итого</th>
                  <th class="sla-table-th-days">Дней</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="m in g.months" :key="m.key">
                  <td class="sla-table-month">{{ m.label }}</td>
                  <td v-for="t in monthlyAverages.types" :key="t" class="sla-table-num">
                    <div class="sla-cell-num">{{ m.byType[t] || '—' }}</div>
                    <div v-if="m.deltaPctByType && m.deltaPctByType[t] !== undefined" class="sla-cell-delta" :class="deltaTone(m.deltaPctByType[t])">
                      {{ deltaArrow(m.deltaPctByType[t]) }} {{ formatDeltaAbs(m.deltaByType[t]) }} ({{ formatDelta(m.deltaPctByType[t]) }})
                    </div>
                  </td>
                  <td class="sla-table-num sla-table-total">
                    <div class="sla-cell-num">{{ m.total || '—' }}</div>
                    <div v-if="m.deltaPctTotal !== null && m.deltaPctTotal !== undefined" class="sla-cell-delta" :class="deltaTone(m.deltaPctTotal)">
                      {{ deltaArrow(m.deltaPctTotal) }} {{ formatDeltaAbs(m.deltaTotal) }} ({{ formatDelta(m.deltaPctTotal) }})
                    </div>
                  </td>
                  <td class="sla-table-days">{{ m.daysCount }}</td>
                </tr>
              </tbody>
              <tfoot>
                <tr>
                  <td class="sla-table-month">Среднее за период</td>
                  <td v-for="t in monthlyAverages.types" :key="t" class="sla-table-num">{{ g.avgByType[t] || '—' }}</td>
                  <td class="sla-table-num sla-table-total">{{ g.avgTotal || '—' }}</td>
                  <td class="sla-table-days">—</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </section>

      <!-- ═══ Heatmap-календари (по одному на юрлицо) ═══ -->
      <section v-if="entityBlocks.some(b => b.heatmap)" class="sla-heatmap-card">
        <header class="sla-chart-head">
          <h3>Календарь загрузки</h3>
          <div v-if="entityBlocks[0]?.heatmap" class="sla-heat-legend">
            <span class="sla-heat-legend-text">Меньше</span>
            <span class="sla-heat-cell" v-for="i in 5" :key="i"
                  :style="{ background: heatColor((i / 5) * entityBlocks[0].heatmap.max, entityBlocks[0].heatmap.max) }"></span>
            <span class="sla-heat-legend-text">Больше</span>
          </div>
        </header>

        <div class="sla-heatmap-row">
          <div v-for="block in entityBlocks" :key="'hm-' + block.key" class="sla-heatmap-cell-block">
            <div v-if="entityBlocks.length > 1 || mergeEntities || filterEntity" class="sla-heatmap-block-title">
              <span class="sla-entity-dot" :style="{ background: ENTITY_COLORS[block.label] || '#8B7355' }"></span>
              {{ block.label }}
            </div>
            <div v-if="block.heatmap" class="sla-heatmap">
              <div v-for="m in block.heatmap.months" :key="block.key + m.key" class="sla-heat-month">
                <div class="sla-heat-month-name">{{ fmtMonthHeader(m.key) }}</div>
                <div class="sla-heat-grid">
                  <div v-for="dayNum in daysInMonth(m.key)" :key="dayNum"
                       class="sla-heat-day"
                       :class="{ empty: !m.days[dayNum] }"
                       :style="m.days[dayNum] ? { background: heatColor(m.days[dayNum].total, block.heatmap.max) } : null"
                       :title="m.days[dayNum] ? `${dayNum} ${fmtMonthHeader(m.key).split(' ')[0]} · ${m.days[dayNum].total} ячеек` : ''"
                       @click="m.days[dayNum] && openDrill(m.days[dayNum])">
                    <span v-if="m.days[dayNum]" class="sla-heat-day-num">{{ dayNum }}</span>
                  </div>
                </div>
              </div>
            </div>
            <div v-else class="sla-empty" style="padding:18px;text-align:center;font-size:12px;">Нет данных</div>
          </div>
        </div>
      </section>
    </template>

    <!-- Модал аннотации события -->
    <div v-if="annotationModal" class="sla-drill-overlay" @click.self="annotationModal = null">
      <div class="sla-drill" style="max-width:420px">
        <header class="sla-drill-head">
          <h3>{{ annotationModal.id ? 'Изменить метку' : 'Новая метка события' }}</h3>
          <button class="sla-drill-close" @click="annotationModal = null">×</button>
        </header>
        <p style="font-size:13px;color:#8B7355;margin:0 0 10px;">Дата: <b>{{ fmtDateLong(annotationModal.date) }}</b></p>
        <input v-model="annotationModal.label" type="text" placeholder="Например: Запуск 5 рестора" class="sla-date" style="width:100%;padding:10px 12px;font-size:14px;margin-bottom:8px;" maxlength="255" />
        <div style="display:flex;gap:8px;align-items:center;margin-bottom:14px;">
          <label style="font-size:12px;color:#8B7355;">Цвет:</label>
          <input v-model="annotationModal.color" type="color" style="width:40px;height:32px;border:none;background:none;cursor:pointer;" />
          <span style="font-size:11.5px;color:#8B7355;">кликни по графику Shift+клик чтобы добавить ещё</span>
        </div>
        <div style="display:flex;gap:8px;justify-content:space-between;">
          <button v-if="annotationModal.id" class="sla-btn outline" style="border-color:#FFCDD2;color:#C62828" @click="deleteAnnotation">Удалить</button>
          <span v-else></span>
          <div style="display:flex;gap:8px;">
            <button class="sla-btn outline" @click="annotationModal = null">Отмена</button>
            <button class="sla-btn primary" @click="saveAnnotation">Сохранить</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Drill-down popover -->
    <div v-if="drillDay" class="sla-drill-overlay" @click.self="closeDrill">
      <div class="sla-drill">
        <header class="sla-drill-head">
          <h3>{{ fmtDateLong(drillDay.date) }}</h3>
          <button class="sla-drill-close" @click="closeDrill">×</button>
        </header>
        <div class="sla-drill-stat">
          <div class="sla-drill-total">{{ drillDay.total }} <span>ячеек всего</span></div>
        </div>
        <div class="sla-drill-grid">
          <div class="sla-drill-col">
            <div class="sla-drill-col-title">По юрлицам</div>
            <div v-for="(v, k) in drillDay.byEntity" :key="k" class="sla-drill-row">
              <span class="sla-drill-dot" :style="{ background: ENTITY_COLORS[k] || '#8B7355' }"></span>
              <span class="sla-drill-label">{{ k }}</span>
              <span class="sla-drill-val">{{ v }}</span>
            </div>
          </div>
          <div class="sla-drill-col">
            <div class="sla-drill-col-title">По типам хранения</div>
            <div v-for="(v, k) in drillDay.byType" :key="k" class="sla-drill-row">
              <span class="sla-drill-dot" :style="{ background: TYPE_COLORS[k] || '#8B7355' }"></span>
              <span class="sla-drill-label">{{ STOCK_TYPE_LABELS[k] || k }}</span>
              <span class="sla-drill-val">{{ v }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { db } from '@/lib/apiClient.js';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import { useToastStore } from '@/stores/toastStore.js';
import { useOrderStore } from '@/stores/orderStore.js';

const router = useRouter();
const route = useRoute();
const toast = useToastStore();
const orderStore = useOrderStore();

// Шорт-нейм юрлица (та же логика, что в ShelfLifeView)
function shortEntityName(name) {
  if (!name) return '';
  if (/Бургер БК/i.test(name)) return 'БК';
  if (/Воглия Матта/i.test(name)) return 'ВМ';
  if (/Пицца Стар/i.test(name)) return 'ПС';
  return name;
}
function entityToWarehouseName(legalEntity) {
  // В warehouse_cells.legal_entity хранятся короткие имена («Бургер БК» и т.п.)
  if (!legalEntity) return '';
  if (/Бургер БК/i.test(legalEntity)) return 'Бургер БК';
  if (/Воглия Матта/i.test(legalEntity)) return 'Воглия Матта';
  if (/Пицца Стар/i.test(legalEntity)) return 'Пицца Стар';
  return legalEntity;
}

// ─── Константы ───
const PRESETS = [
  { key: 'this-month', label: 'Этот месяц' },
  { key: 'prev-month', label: 'Прошлый месяц' },
  { key: '3m', label: '3 месяца' },
  { key: '6m', label: '6 месяцев' },
  { key: '12m', label: '12 месяцев' },
  { key: 'custom', label: 'Произвольно' },
];

const GRANULARITY = [
  { key: 'day', label: 'День' },
  { key: 'week', label: 'Неделя' },
  { key: 'month', label: 'Месяц' },
];

const GROUPING = [
  { key: 'total', label: 'Свод' },
  { key: 'entity', label: 'По юрлицам' },
  { key: 'type', label: 'По типам' },
];

const STOCK_TYPE_LABELS = { dry: 'Сухой', cold: 'Холод', frozen: 'Мороз', shabany: 'Шабаны' };

// ─── Стейт ───
const presetKey = ref('3m');
const customStart = ref('');
const customEnd = ref('');
const granularity = ref('month');
const groupBy = ref('entity');
const comparePrev = ref(false);
// Фильтр по юрлицу. Пустая строка = все юрлица.
// По умолчанию — все, и они показываются раздельными блоками.
// Чтобы получить «свод», есть отдельный тоггл mergeEntities ниже.
const filterEntity = ref('');
// Свести юрлица в одно (сумма). Применяется только когда filterEntity = ''.
const mergeEntities = ref(false);
// Показывать значения над точками графика (для презентации).
const showValueLabels = ref(true);

const loading = ref(false);
const error = ref('');
const rawRows = ref([]);

// Список доступных юрлиц (на основе пришедших данных)
const availableEntities = computed(() => {
  return [...new Set(rawRows.value.map(r => r.legal_entity))].sort();
});

// Достраиваем недостающие строки за выходные на уровне (date+entity+type):
// если на Сб/Вс по конкретной паре «юрлицо × тип» нет записи — берём её
// с ближайшего следующего понедельника (или ближайшего рабочего дня до выходных,
// если впереди понедельника нет). Та же логика, что в разделе «Ячейки».
// Делается на уровне строк, поэтому работает и для частично заполненных
// выходных (когда в БД есть cold/frozen, но нет dry).
function fillWeekendRowGaps(rows) {
  if (!rows.length) return rows;
  const ymd = (d) => {
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
  };
  // Индекс строк: entity|type -> Map<date, row>
  const byEntityType = new Map();
  for (const r of rows) {
    const k = r.legal_entity + '|' + r.stock_type;
    if (!byEntityType.has(k)) byEntityType.set(k, new Map());
    byEntityType.get(k).set(r.report_date, r);
  }
  const allDates = [...new Set(rows.map(r => r.report_date))].sort();
  const minDate = allDates[0];
  const maxDate = allDates[allDates.length - 1];
  const synthetic = [];
  for (let d = new Date(minDate + 'T00:00:00'); d <= new Date(maxDate + 'T00:00:00'); d.setDate(d.getDate() + 1)) {
    const dow = d.getDay();
    if (dow !== 0 && dow !== 6) continue;
    const ds = ymd(d);
    // следующий понедельник
    const fwd = new Date(d);
    fwd.setDate(fwd.getDate() + (dow === 0 ? 1 : 2));
    const mondayStr = ymd(fwd);
    for (const [key, dateMap] of byEntityType) {
      if (dateMap.has(ds)) continue;
      let src = dateMap.get(mondayStr);
      if (!src) {
        // последний рабочий день до выходных (макс 7 дней назад)
        const back = new Date(d);
        for (let i = 0; i < 7; i++) {
          back.setDate(back.getDate() - 1);
          const bd = back.getDay();
          if (bd === 0 || bd === 6) continue;
          const cand = dateMap.get(ymd(back));
          if (cand) { src = cand; break; }
        }
      }
      if (!src) continue;
      const [legalEntity, stockType] = key.split('|');
      synthetic.push({
        report_date: ds,
        legal_entity: legalEntity,
        stock_type: stockType,
        cell_count: src.cell_count,
        is_manual: 0,
        fromFallback: true,
      });
    }
  }
  if (!synthetic.length) return rows;
  return rows.concat(synthetic);
}

// Сырые строки + достроенные выходные. Используется везде вместо rawRows.
const enrichedRows = computed(() => fillWeekendRowGaps(rawRows.value));

// Данные с применённым фильтром по юрлицу
const filteredRows = computed(() => {
  if (!filterEntity.value) return enrichedRows.value;
  return enrichedRows.value.filter(r => r.legal_entity === filterEntity.value);
});

// ─── URL share ───
function syncFromUrl() {
  const q = route.query;
  if (q.preset && PRESETS.some(p => p.key === q.preset)) presetKey.value = q.preset;
  if (q.start) customStart.value = String(q.start);
  if (q.end) customEnd.value = String(q.end);
  if (q.gran && GRANULARITY.some(g => g.key === q.gran)) granularity.value = q.gran;
  if (q.group && GROUPING.some(g => g.key === q.group)) groupBy.value = q.group;
  if (q.compare === '1') comparePrev.value = true;
  if (q.entity !== undefined) filterEntity.value = String(q.entity);
  if (q.merge === '1') mergeEntities.value = true;
}

function syncToUrl() {
  const q = {
    preset: presetKey.value,
    gran: granularity.value,
    group: groupBy.value,
  };
  if (presetKey.value === 'custom') {
    q.start = customStart.value;
    q.end = customEnd.value;
  }
  if (comparePrev.value) q.compare = '1';
  if (filterEntity.value) q.entity = filterEntity.value;
  if (mergeEntities.value) q.merge = '1';
  router.replace({ query: q }).catch(() => {});
}

// ─── Расчёт диапазона дат по пресету ───
function pad(n) { return String(n).padStart(2, '0'); }
function fmtDate(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

function rangeFromPreset(key) {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  if (key === 'this-month') {
    const s = new Date(today.getFullYear(), today.getMonth(), 1);
    return { start: fmtDate(s), end: fmtDate(today) };
  }
  if (key === 'prev-month') {
    const s = new Date(today.getFullYear(), today.getMonth() - 1, 1);
    const e = new Date(today.getFullYear(), today.getMonth(), 0);
    return { start: fmtDate(s), end: fmtDate(e) };
  }
  if (key === '3m' || key === '6m' || key === '12m') {
    const months = key === '3m' ? 3 : key === '6m' ? 6 : 12;
    const s = new Date(today.getFullYear(), today.getMonth() - months + 1, 1);
    return { start: fmtDate(s), end: fmtDate(today) };
  }
  if (key === 'custom') {
    return { start: customStart.value, end: customEnd.value };
  }
  return { start: fmtDate(today), end: fmtDate(today) };
}

const dateRange = computed(() => rangeFromPreset(presetKey.value));

function applyPreset(key) {
  presetKey.value = key;
  if (key === 'custom' && (!customStart.value || !customEnd.value)) {
    const r = rangeFromPreset('3m');
    customStart.value = r.start;
    customEnd.value = r.end;
  }
  syncToUrl();
  loadData();
}

function applyCustomRange() {
  if (!customStart.value || !customEnd.value) return;
  if (customStart.value > customEnd.value) {
    toast.error('Неверный диапазон', 'Начало позже конца');
    return;
  }
  presetKey.value = 'custom';
  syncToUrl();
  loadData();
}

watch([granularity, groupBy], syncToUrl);
watch(comparePrev, () => { syncToUrl(); /* перерасчёт сравнения происходит в computed */ });
// При выборе конкретного юрлица — автоматически снимаем «Свести»: оно теряет смысл.
watch(filterEntity, (val) => {
  if (val) mergeEntities.value = false;
  syncToUrl();
});

// ─── Загрузка данных ───
async function loadData() {
  const { start, end } = dateRange.value;
  if (!start || !end) return;
  loading.value = true;
  error.value = '';
  try {
    // Расширяем запрос двумя способами:
    //  1) Если включено сравнение — берём столько же длины назад, чтобы
    //     посчитать «прошлый период».
    //  2) Всегда тянем минимум 12 месяцев — диаграмма (при шаге «Месяц») и
    //     таблица «Среднемесячные значения» должны показывать историю,
    //     даже если пользователь выбрал короткий период вроде «Этот месяц».
    let queryStart = start;
    if (comparePrev.value) {
      const days = (new Date(end) - new Date(start)) / 86400000 + 1;
      const ext = new Date(start);
      ext.setDate(ext.getDate() - days);
      queryStart = fmtDate(ext);
    }
    // Минимум 12 полных месяцев. Идём от конца выбранного периода назад.
    const minStartDate = new Date(end);
    minStartDate.setMonth(minStartDate.getMonth() - 11);
    minStartDate.setDate(1);
    const minStart = fmtDate(minStartDate);
    if (minStart < queryStart) queryStart = minStart;

    const { data, error: err } = await db.rpc('cell_analytics_get', {
      start: queryStart,
      end,
    });
    if (err) throw new Error(err.message || 'Ошибка загрузки');
    rawRows.value = (data && data.rows) ? data.rows : [];
    await loadAnnotations();
  } catch (e) {
    error.value = e.message || 'Не удалось загрузить данные';
    rawRows.value = [];
  } finally {
    loading.value = false;
  }
}

// ─── Чистые функции расчёта (используются как для общего, так и для per-entity) ───
// Выходные уже достроены на уровне строк (fillWeekendRowGaps), поэтому здесь
// просто агрегируем по дате.
function buildDailyTotals(rows) {
  const byDate = new Map();
  for (const r of rows) {
    if (!byDate.has(r.report_date)) {
      byDate.set(r.report_date, { date: r.report_date, total: 0, byEntity: {}, byType: {} });
    }
    const d = byDate.get(r.report_date);
    const cnt = parseInt(r.cell_count, 10) || 0;
    d.total += cnt;
    d.byEntity[r.legal_entity] = (d.byEntity[r.legal_entity] || 0) + cnt;
    d.byType[r.stock_type] = (d.byType[r.stock_type] || 0) + cnt;
  }
  return [...byDate.values()].sort((a, b) => a.date.localeCompare(b.date));
}

// ─── Подготовка серий ───
const dailyTotals = computed(() => buildDailyTotals(filteredRows.value));

// Дни внутри текущего диапазона (отбрасываем «расширение» для сравнения)
const currentDays = computed(() => {
  const { start, end } = dateRange.value;
  return dailyTotals.value.filter(d => d.date >= start && d.date <= end);
});
const prevDays = computed(() => {
  if (!comparePrev.value) return [];
  const { start } = dateRange.value;
  return dailyTotals.value.filter(d => d.date < start);
});

// ─── KPI ───
function avg(arr) { return arr.length ? arr.reduce((a, b) => a + b, 0) / arr.length : 0; }

function buildKpis(cur, prev) {
  if (!cur.length) return [];
  const last = cur[cur.length - 1];
  const peak = cur.reduce((m, d) => d.total > m.total ? d : m, cur[0]);
  const avgVal = avg(cur.map(d => d.total));
  let deltaPct = null;
  if (prev.length) {
    const prevAvg = avg(prev.map(d => d.total));
    if (prevAvg > 0) deltaPct = ((avgVal - prevAvg) / prevAvg) * 100;
  }
  const sparkline = cur.map(d => d.total);
  return [
    { key: 'current', label: 'Текущая загрузка', value: last.total, unit: 'ячеек',
      subtitle: 'на ' + fmtDateShort(last.date), tone: 'orange', sparkline, deltaPct: null },
    { key: 'change', label: 'Изменение к пред. периоду',
      value: deltaPct !== null ? (deltaPct >= 0 ? '+' : '') + deltaPct.toFixed(1) + '%' : '—',
      unit: '', subtitle: prev.length ? 'среднее vs среднее' : 'нет данных за пред. период',
      tone: deltaPct === null ? 'neutral' : (deltaPct >= 0 ? 'green' : 'red'),
      sparkline, deltaPct },
    { key: 'peak', label: 'Пиковая загрузка', value: peak.total, unit: 'ячеек',
      subtitle: fmtDateShort(peak.date), tone: 'red', sparkline, deltaPct: null },
    { key: 'avg', label: 'Среднее за период', value: Math.round(avgVal), unit: 'ячеек',
      subtitle: 'дней в периоде: ' + cur.length, tone: 'blue', sparkline, deltaPct: null },
  ];
}

const kpis = computed(() => buildKpis(currentDays.value, prevDays.value));

// ─── Sparkline ───
function sparklinePoints(values) {
  if (!values || !values.length) return '';
  const min = Math.min(...values);
  const max = Math.max(...values);
  const range = max - min || 1;
  const w = 100;
  const h = 24;
  return values
    .map((v, i) => {
      const x = (i / Math.max(values.length - 1, 1)) * w;
      const y = h - ((v - min) / range) * h;
      return x.toFixed(1) + ',' + y.toFixed(1);
    })
    .join(' ');
}
function sparkColor(tone) {
  return {
    orange: '#E76F51',
    red: '#C62828',
    green: '#2E7D32',
    blue: '#1976D2',
    neutral: '#8B7355',
  }[tone] || '#8B7355';
}

// ─── Авто-инсайт ───
function buildInsight(cur, prev) {
  if (!cur.length) return 'Нет данных для анализа.';
  const peak = cur.reduce((m, d) => d.total > m.total ? d : m, cur[0]);
  const avgVal = avg(cur.map(d => d.total));
  const last = cur[cur.length - 1];
  const first = cur[0];
  const parts = [];
  if (last.total > first.total) {
    const dPct = ((last.total - first.total) / Math.max(first.total, 1)) * 100;
    parts.push(`За период загрузка выросла с ${first.total} до ${last.total} ячеек (+${dPct.toFixed(0)}%).`);
  } else if (last.total < first.total) {
    const dPct = ((first.total - last.total) / Math.max(first.total, 1)) * 100;
    parts.push(`За период загрузка снизилась с ${first.total} до ${last.total} ячеек (−${dPct.toFixed(0)}%).`);
  } else {
    parts.push(`За период загрузка стабильна: ${last.total} ячеек.`);
  }
  parts.push(`Пик ${fmtDateShort(peak.date)} — ${peak.total} ячеек.`);
  if (prev.length) {
    const prevAvg = avg(prev.map(d => d.total));
    if (prevAvg > 0) {
      const diff = ((avgVal - prevAvg) / prevAvg) * 100;
      const verb = diff >= 0 ? 'выше' : 'ниже';
      parts.push(`Среднее за период (${Math.round(avgVal)}) на ${Math.abs(diff).toFixed(0)}% ${verb} прошлого периода.`);
    }
  }
  if (last.byType) {
    const types = Object.entries(last.byType).sort((a, b) => b[1] - a[1]);
    if (types.length) {
      const [topType, topVal] = types[0];
      const totalNonZero = last.total || 1;
      const pct = (topVal / totalNonZero) * 100;
      parts.push(`Больше всего занято в режиме «${STOCK_TYPE_LABELS[topType] || topType}» — ${topVal} ячеек (${pct.toFixed(0)}% от всей загрузки на ${fmtDateShort(last.date)}).`);
    }
  }
  return parts.join(' ');
}

const autoInsight = computed(() => buildInsight(currentDays.value, prevDays.value));

function fmtDateShort(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d)) return iso;
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: 'short' });
}
function fmtDateLong(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d)) return iso;
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: 'long', year: 'numeric' });
}

// ─── Агрегация по гранулярности ───
function bucketKey(date, gran) {
  const d = new Date(date);
  if (gran === 'day') return date;
  if (gran === 'week') {
    // ISO week: Mon..Sun
    const t = new Date(d);
    const day = t.getDay() || 7;
    t.setDate(t.getDate() - day + 1);
    return fmtDate(t);
  }
  // month
  return d.getFullYear() + '-' + pad(d.getMonth() + 1);
}
function bucketLabel(key, gran) {
  if (gran === 'day') return fmtDateShort(key);
  if (gran === 'week') {
    const d = new Date(key);
    const e = new Date(d); e.setDate(e.getDate() + 6);
    return fmtDateShort(key) + '–' + fmtDateShort(fmtDate(e));
  }
  // month: "2026-04" → "Апр 2026"
  const [y, m] = key.split('-');
  const months = ['Янв','Фев','Мар','Апр','Май','Июн','Июл','Авг','Сен','Окт','Ноя','Дек'];
  return months[parseInt(m) - 1] + ' ' + y;
}

// ─── Линии графика ───
const ENTITY_COLORS = {
  'Бургер БК': '#E76F51',
  'Воглия Матта': '#7B5BA8',
  'Пицца Стар': '#1976D2',
};
const TYPE_COLORS = {
  dry: '#FFCC80',
  cold: '#4FC3F7',
  frozen: '#7B9DD6',
  shabany: '#A5D6A7',
};

function aggregateBuckets(days, gran, getValue) {
  // days: [{date, total, byEntity, byType}], getValue: (day) => number
  const buckets = new Map();
  for (const day of days) {
    const k = bucketKey(day.date, gran);
    if (!buckets.has(k)) buckets.set(k, { key: k, sum: 0, count: 0, last: 0, lastDate: day.date });
    const b = buckets.get(k);
    const v = getValue(day);
    b.sum += v;
    b.count++;
    // Запоминаем самое свежее значение (для месячного среза snapshot-on-end)
    if (day.date >= b.lastDate) {
      b.last = v;
      b.lastDate = day.date;
    }
  }
  return [...buckets.values()].sort((a, b) => a.key.localeCompare(b.key));
}

// Для диаграммы при шаге «Месяц» берём ВСЕ доступные дни (loadData грузит
// минимум 12 месяцев). Иначе короткий период вроде «Этот месяц» рисовал бы
// один столбик — пользователю некуда смотреть.
const chartDays = computed(() => {
  if (granularity.value === 'month') return dailyTotals.value;
  return currentDays.value;
});

const chartSeries = computed(() => {
  const cur = chartDays.value;
  if (!cur.length) return [];
  const gran = granularity.value;
  // Для дня берём как есть; для недели/месяца — среднее (более «гладкое» для презентации).
  // Можно переключить на «снимок на конец периода» (b.last), оставлю avg.
  const reduce = (b) => b.count ? Math.round(b.sum / b.count) : 0;

  if (groupBy.value === 'total') {
    const buckets = aggregateBuckets(cur, gran, d => d.total);
    return [{
      key: 'total',
      label: 'Всего',
      color: '#E76F51',
      points: buckets.map(b => ({ x: b.key, y: reduce(b), label: bucketLabel(b.key, gran) })),
    }];
  }
  if (groupBy.value === 'entity') {
    const entities = [...new Set(filteredRows.value.map(r => r.legal_entity))].sort();
    return entities.map(e => {
      const buckets = aggregateBuckets(cur, gran, d => d.byEntity[e] || 0);
      return {
        key: e,
        label: e,
        color: ENTITY_COLORS[e] || '#8B7355',
        points: buckets.map(b => ({ x: b.key, y: reduce(b), label: bucketLabel(b.key, gran) })),
      };
    });
  }
  // type
  const types = [...new Set(filteredRows.value.map(r => r.stock_type))].sort();
  return types.map(t => {
    const buckets = aggregateBuckets(cur, gran, d => d.byType[t] || 0);
    return {
      key: t,
      label: STOCK_TYPE_LABELS[t] || t,
      color: TYPE_COLORS[t] || '#8B7355',
      points: buckets.map(b => ({ x: b.key, y: reduce(b), label: bucketLabel(b.key, gran) })),
    };
  });
});

// Сравнение с предыдущим периодом — массив серий, по одной на каждую серию из текущего
const comparisonSeries = computed(() => {
  if (!comparePrev.value || !prevDays.value.length) return [];
  const gran = granularity.value;
  const reduce = (b) => b.count ? Math.round(b.sum / b.count) : 0;
  // Чтобы наложить на одну ось X — пересчитаем точки прошлого периода
  // как если бы они были в текущем: сдвинем по индексу бакетов.
  const curBuckets = (() => {
    if (groupBy.value === 'total') return aggregateBuckets(currentDays.value, gran, d => d.total);
    if (groupBy.value === 'entity') return aggregateBuckets(currentDays.value, gran, d => d.total);
    return aggregateBuckets(currentDays.value, gran, d => d.total);
  })();

  if (groupBy.value === 'total') {
    const buckets = aggregateBuckets(prevDays.value, gran, d => d.total);
    return [{
      key: 'total-prev',
      label: 'Всего (пред.)',
      color: '#E76F51',
      dashed: true,
      points: buckets.slice(-curBuckets.length).map((b, i) => ({
        x: curBuckets[i]?.key || b.key,
        y: reduce(b),
        label: 'пред: ' + bucketLabel(b.key, gran),
      })),
    }];
  }
  // Для упрощения сравниваем только Свод (если включён режим entity/type — сравнение тоже по тоталу)
  const buckets = aggregateBuckets(prevDays.value, gran, d => d.total);
  return [{
    key: 'total-prev',
    label: 'Всего (пред.)',
    color: '#8B7355',
    dashed: true,
    points: buckets.slice(-curBuckets.length).map((b, i) => ({
      x: curBuckets[i]?.key || b.key,
      y: reduce(b),
      label: 'пред: ' + bucketLabel(b.key, gran),
    })),
  }];
});

// ─── Прогноз (скользящее среднее) ───
const FORECAST_POINTS = 3; // длина прогноза в бакетах
const FORECAST_WINDOW = 3; // окно скользящего среднего
const forecastSeries = computed(() => {
  return chartSeries.value
    .filter(s => s.points.length >= FORECAST_WINDOW)
    .map(s => {
      const tail = s.points.slice(-FORECAST_WINDOW).map(p => p.y);
      const avg = tail.reduce((a, b) => a + b, 0) / tail.length;
      const lastY = s.points[s.points.length - 1].y;
      // Простое скользящее: следующая точка = среднее из последних N
      // Чтобы линия выглядела как продолжение, делаем плавный переход.
      const forecastPts = [];
      let prev = lastY;
      const target = avg;
      for (let i = 1; i <= FORECAST_POINTS; i++) {
        // Двигаемся к скользящему среднему
        prev = Math.round((prev + target) / 2);
        forecastPts.push({ x: 'forecast-' + i, y: prev, label: 'прогноз +' + i });
      }
      return {
        key: s.key + '-forecast',
        label: s.label + ' (прогноз)',
        color: s.color,
        dashed: true,
        forecast: true,
        points: [s.points[s.points.length - 1], ...forecastPts],
      };
    });
});

// ─── SVG-расчёты графика ───
const CHART_W = 900;
const CHART_H = 340;
const PAD_L = 50;
const PAD_R = 14;
const PAD_T = 14;
const PAD_B = 30;

const allChartPoints = computed(() => {
  const all = [];
  for (const s of chartSeries.value) all.push(...s.points);
  for (const s of comparisonSeries.value) all.push(...s.points);
  for (const s of forecastSeries.value) all.push(...s.points);
  return all;
});

const xLabels = computed(() => {
  // Берём все уникальные X из основных серий + прогноза, чтобы выровнять оси
  const set = new Set();
  for (const s of chartSeries.value) for (const p of s.points) set.add(p.x);
  for (const s of forecastSeries.value) for (const p of s.points) set.add(p.x);
  return [...set];
});

const xPositions = computed(() => {
  const labels = xLabels.value;
  const w = CHART_W - PAD_L - PAD_R;
  const map = new Map();
  if (labels.length === 1) { map.set(labels[0], PAD_L + w / 2); return map; }
  labels.forEach((k, i) => {
    map.set(k, PAD_L + (i / (labels.length - 1)) * w);
  });
  return map;
});

const yMax = computed(() => {
  const maxV = allChartPoints.value.reduce((m, p) => Math.max(m, p.y), 0);
  // Округляем вверх до удобного значения
  const niceSteps = [10, 25, 50, 100, 200, 500, 1000, 2000, 5000];
  const step = niceSteps.find(s => maxV / s < 6) || maxV;
  return Math.max(step * Math.ceil(maxV / step), step);
});

function chartY(value) {
  const h = CHART_H - PAD_T - PAD_B;
  return PAD_T + h - (value / Math.max(yMax.value, 1)) * h;
}

const yGrid = computed(() => {
  const lines = [];
  const max = yMax.value;
  const ticks = 5;
  for (let i = 0; i <= ticks; i++) {
    const v = Math.round((max / ticks) * i);
    lines.push({ value: v, y: chartY(v) });
  }
  return lines;
});

function pathFor(points) {
  if (!points.length) return '';
  const parts = [];
  points.forEach((p, i) => {
    const x = xPositions.value.get(p.x);
    const y = chartY(p.y);
    if (x === undefined) return;
    parts.push((i === 0 ? 'M' : 'L') + x.toFixed(1) + ',' + y.toFixed(1));
  });
  return parts.join(' ');
}

// Hidden series toggle
const hiddenSeries = ref(new Set());
function toggleSeries(key) {
  const s = new Set(hiddenSeries.value);
  if (s.has(key)) s.delete(key); else s.add(key);
  hiddenSeries.value = s;
}

// Tooltip
const chartTooltip = ref(null);
function showTooltip(series, point, evt) {
  const rect = evt.currentTarget.closest('svg').getBoundingClientRect();
  chartTooltip.value = {
    label: series.label,
    color: series.color,
    title: point.label,
    value: point.y,
    x: evt.clientX - rect.left + 10,
    y: evt.clientY - rect.top - 30,
  };
}
function hideTooltip() { chartTooltip.value = null; }

// ─── Heatmap: дни × месяцы ───
function buildHeatmap(days) {
  if (!days.length) return null;
  const byMonth = new Map();
  for (const d of days) {
    const dt = new Date(d.date);
    const mk = dt.getFullYear() + '-' + pad(dt.getMonth() + 1);
    if (!byMonth.has(mk)) byMonth.set(mk, { key: mk, days: {} });
    byMonth.get(mk).days[dt.getDate()] = d;
  }
  const months = [...byMonth.values()].sort((a, b) => a.key.localeCompare(b.key));
  const max = days.reduce((m, d) => Math.max(m, d.total), 0) || 1;
  return { months, max };
}
const heatmapData = computed(() => buildHeatmap(currentDays.value));

// ─── Per-entity блоки (для дефолтного режима «Все юрлица, раздельно») ───
// Возвращает массив блоков, каждый со своим набором KPI/инсайт/heatmap.
// Если выбрано конкретное юрлицо или включён mergeEntities — один блок.
const entityBlocks = computed(() => {
  if (!rawRows.value.length) return [];
  const { start, end } = dateRange.value;
  const blocks = [];

  // Хелпер: построить блок из массива rows
  const makeBlock = (label, key, rows) => {
    const totals = buildDailyTotals(rows);
    const cur = totals.filter(d => d.date >= start && d.date <= end);
    const prev = comparePrev.value ? totals.filter(d => d.date < start) : [];
    return {
      key,
      label,
      currentDays: cur,
      prevDays: prev,
      kpis: buildKpis(cur, prev),
      insight: buildInsight(cur, prev),
      heatmap: buildHeatmap(cur),
    };
  };

  // Один блок: выбран фильтр или включено сведение.
  // Используем enrichedRows, чтобы по каждому юрлицу выходные были достроены
  // с понедельника (включая отдельные типы хранения).
  const src = enrichedRows.value;
  if (filterEntity.value) {
    const rows = src.filter(r => r.legal_entity === filterEntity.value);
    blocks.push(makeBlock(filterEntity.value, filterEntity.value, rows));
  } else if (mergeEntities.value) {
    blocks.push(makeBlock('Все юрлица (свод)', '__merged__', src));
  } else {
    // Раздельно по каждому юрлицу
    for (const e of availableEntities.value) {
      const rows = src.filter(r => r.legal_entity === e);
      blocks.push(makeBlock(e, e, rows));
    }
  }
  return blocks;
});

function heatColor(value, max) {
  if (!value) return '#F5F1EB';
  const t = Math.min(1, value / max);
  // Шкала: бежевый → оранжевый → красный
  if (t < 0.5) {
    // beige -> orange
    const k = t * 2;
    const r = Math.round(245 - (245 - 244) * k);
    const g = Math.round(241 - (241 - 162) * k);
    const b = Math.round(235 - (235 - 97) * k);
    return `rgb(${r},${g},${b})`;
  }
  // orange -> red
  const k = (t - 0.5) * 2;
  const r = Math.round(244 - (244 - 198) * k);
  const g = Math.round(162 - (162 - 40) * k);
  const b = Math.round(97 - (97 - 40) * k);
  return `rgb(${r},${g},${b})`;
}

function fmtMonthHeader(key) {
  const [y, m] = key.split('-');
  const months = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
  return months[parseInt(m) - 1] + ' ' + y;
}
function daysInMonth(key) {
  const [y, m] = key.split('-').map(Number);
  return new Date(y, m, 0).getDate();
}

// ─── Drill-down popover ───
const drillDay = ref(null);
function openDrill(day) {
  drillDay.value = day;
}
function closeDrill() { drillDay.value = null; }
function onPointClick(point, evt) {
  // Shift+click — добавить/изменить аннотацию (для дневной гранулярности)
  if (evt && evt.shiftKey && granularity.value === 'day') {
    promptAnnotation(point.x);
    return;
  }
  // Если гранулярность дневная — открываем подробности дня.
  // Иначе ищем последний день в бакете и показываем его.
  const cur = currentDays.value;
  if (granularity.value === 'day') {
    const day = cur.find(d => d.date === point.x);
    if (day) openDrill(day);
    return;
  }
  // week/month — найдём последний день внутри бакета
  const matching = cur.filter(d => bucketKey(d.date, granularity.value) === point.x);
  if (matching.length) openDrill(matching[matching.length - 1]);
}

// ─── Аннотации (события) ───
const annotations = ref([]);
const annotationModal = ref(null); // { id?, date, label, color }

async function loadAnnotations() {
  const { start, end } = dateRange.value;
  if (!start || !end) return;
  try {
    const { data, error: err } = await db.rpc('cell_annotations_list', { start, end });
    if (err) throw new Error(err.message);
    annotations.value = data?.rows || [];
  } catch (e) { /* не блокируем основной поток */ }
}

function promptAnnotation(date) {
  const existing = annotations.value.find(a => a.event_date === date);
  annotationModal.value = existing
    ? { id: existing.id, date, label: existing.label, color: existing.color }
    : { id: null, date, label: '', color: '#E76F51' };
}

async function saveAnnotation() {
  const m = annotationModal.value;
  if (!m || !m.label.trim()) {
    toast.error('Введите метку');
    return;
  }
  try {
    const { error: err } = await db.rpc('cell_annotation_save', {
      id: m.id || 0,
      event_date: m.date,
      label: m.label.trim(),
      color: m.color,
    });
    if (err) throw new Error(err.message);
    annotationModal.value = null;
    await loadAnnotations();
    toast.success('Метка сохранена', '');
  } catch (e) {
    toast.error('Не удалось сохранить', e.message || '');
  }
}

async function deleteAnnotation() {
  const m = annotationModal.value;
  if (!m || !m.id) { annotationModal.value = null; return; }
  try {
    const { error: err } = await db.rpc('cell_annotation_delete', { id: m.id });
    if (err) throw new Error(err.message);
    annotationModal.value = null;
    await loadAnnotations();
    toast.success('Метка удалена', '');
  } catch (e) {
    toast.error('Не удалось удалить', e.message || '');
  }
}

// ─── Экспорт PNG/SVG графика ───
const chartSvgRef = ref(null);
function downloadBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = filename;
  document.body.appendChild(a); a.click(); a.remove();
  URL.revokeObjectURL(url);
}
function buildChartSvgString(bg = '#ffffff') {
  const svg = document.querySelector('.sla-chart-svg');
  if (!svg) return '';
  const clone = svg.cloneNode(true);
  // Превращаем в самостоятельный SVG с фоном и фикс. размером
  clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
  clone.setAttribute('width', String(CHART_W));
  clone.setAttribute('height', String(CHART_H));
  // Фон
  const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
  rect.setAttribute('x', '0'); rect.setAttribute('y', '0');
  rect.setAttribute('width', '100%'); rect.setAttribute('height', '100%');
  rect.setAttribute('fill', bg);
  clone.insertBefore(rect, clone.firstChild);
  // Если тёмный — перекрашиваем подписи
  if (bg !== '#ffffff') {
    clone.querySelectorAll('text').forEach(t => t.setAttribute('fill', '#FFE082'));
    clone.querySelectorAll('line[stroke="#ECE3D6"]').forEach(l => l.setAttribute('stroke', '#3D1A0D'));
  }
  return new XMLSerializer().serializeToString(clone);
}
async function downloadChartSvg(dark = false) {
  const svgStr = buildChartSvgString(dark ? '#2C1A12' : '#ffffff');
  const blob = new Blob([svgStr], { type: 'image/svg+xml' });
  downloadBlob(blob, 'cell-load-chart' + (dark ? '-dark' : '') + '.svg');
}
/**
 * Прокачанный рендер графика для PowerPoint-слайда: заголовок сверху, подписи
 * осей, крупная легенда снизу. Возвращает PNG нужного размера.
 *
 * Базовый SVG (.sla-chart-svg) встраивается внутрь обёрточного через <use>-
 * подобную технику: клон, помещаемый в <g transform="translate...">.
 */
async function renderRichChartPng({ title = 'Динамика загрузки ячеек склада', scale = 2 } = {}) {
  const baseSvg = document.querySelector('.sla-chart-svg');
  if (!baseSvg) return null;

  // Размер слайдовой картинки 16:9 (под LAYOUT_WIDE PptxGenJS 13.33×7.5).
  const W = 1600;
  const H = 900;
  // Поля
  const padTop = 70;     // под заголовок
  const padSubtitle = 30; // под подзаголовок (диапазон)
  const padLegend = 120;  // снизу под легенду + ось X label
  const padLeftLabel = 60; // место для подписи оси Y
  // Размер графика внутри обёртки
  const innerW = W - padLeftLabel - 20;
  const innerH = H - padTop - padSubtitle - padLegend;

  // Клонируем SVG, чистим интерактив.
  const inner = baseSvg.cloneNode(true);
  inner.removeAttribute('class');
  inner.removeAttribute('preserveAspectRatio');
  inner.setAttribute('viewBox', `0 0 ${CHART_W} ${CHART_H}`);
  inner.setAttribute('width', String(innerW));
  inner.setAttribute('height', String(innerH));
  // Увеличим шрифт текстов внутри, чтобы при масштабировании оставались читаемые.
  inner.querySelectorAll('text').forEach(t => {
    const fs = parseFloat(t.getAttribute('font-size') || '10');
    t.setAttribute('font-size', String(fs * (innerH / CHART_H) * 0.85));
  });

  const subtitleText = `Период: ${dateRange.value.start} — ${dateRange.value.end}`;
  const allSeries = [...chartSeries.value, ...comparisonSeries.value];
  // Сериализуем inner-SVG в строку и подставляем в обёртку.
  const innerStr = new XMLSerializer().serializeToString(inner);

  // Легенда: чипы N в ряду, авто-перенос.
  const chipFs = 18;
  const chipPadX = 18;
  const dotR = 6;
  // Оцениваем ширину текста через скрытый canvas.
  const canvasMeasure = document.createElement('canvas').getContext('2d');
  canvasMeasure.font = `${chipFs}px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif`;
  const chips = allSeries.map(s => {
    const text = s.label + (s.dashed ? ' (сравнение)' : '');
    const tw = canvasMeasure.measureText(text).width;
    return { text, color: s.color, width: dotR * 2 + 10 + tw + chipPadX };
  });
  // Расставляем чипы по строкам.
  const legendY0 = padTop + padSubtitle + innerH + 30;
  const legendMaxW = W - 40;
  let rowX = 20, rowY = legendY0, rowH = 30;
  const placedChips = [];
  for (const ch of chips) {
    if (rowX + ch.width > legendMaxW) {
      rowY += rowH;
      rowX = 20;
    }
    placedChips.push({ ...ch, x: rowX, y: rowY });
    rowX += ch.width;
  }

  const titleColor = '502314';
  const subColor = '8B7355';
  const axisLabelColor = '4B3527';

  let svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}">`;
  svg += `<rect width="100%" height="100%" fill="#ffffff"/>`;
  // Заголовок
  svg += `<text x="${W / 2}" y="40" text-anchor="middle" font-size="28" font-weight="700" font-family="-apple-system,Segoe UI,Roboto,Arial,sans-serif" fill="#${titleColor}">${escapeXml(title)}</text>`;
  // Подзаголовок
  svg += `<text x="${W / 2}" y="${40 + 28}" text-anchor="middle" font-size="16" font-family="-apple-system,Segoe UI,Roboto,Arial,sans-serif" fill="#${subColor}">${escapeXml(subtitleText)}</text>`;
  // Подпись оси Y (вертикальная)
  svg += `<text x="${20}" y="${padTop + padSubtitle + innerH / 2}" text-anchor="middle" transform="rotate(-90 20 ${padTop + padSubtitle + innerH / 2})" font-size="16" font-family="-apple-system,Segoe UI,Roboto,Arial,sans-serif" fill="#${axisLabelColor}">Ячеек, шт</text>`;
  // Подпись оси X
  svg += `<text x="${padLeftLabel + innerW / 2}" y="${legendY0 - 6}" text-anchor="middle" font-size="16" font-family="-apple-system,Segoe UI,Roboto,Arial,sans-serif" fill="#${axisLabelColor}">${escapeXml(granularity.value === 'day' ? 'День' : granularity.value === 'week' ? 'Неделя' : 'Месяц')}</text>`;
  // Внутренний график (с переносом координат через group)
  svg += `<g transform="translate(${padLeftLabel}, ${padTop + padSubtitle})">${innerStr}</g>`;
  // Легенда
  for (const c of placedChips) {
    svg += `<circle cx="${c.x + dotR}" cy="${c.y + 12}" r="${dotR}" fill="${c.color}"/>`;
    svg += `<text x="${c.x + dotR * 2 + 8}" y="${c.y + 17}" font-size="${chipFs}" font-family="-apple-system,Segoe UI,Roboto,Arial,sans-serif" fill="#${titleColor}">${escapeXml(c.text)}</text>`;
  }
  svg += `</svg>`;

  // SVG → PNG через canvas.
  const blob = new Blob([svg], { type: 'image/svg+xml' });
  const url = URL.createObjectURL(blob);
  try {
    const img = new Image();
    img.crossOrigin = 'anonymous';
    await new Promise((res, rej) => { img.onload = res; img.onerror = rej; img.src = url; });
    const canvas = document.createElement('canvas');
    canvas.width = W * scale;
    canvas.height = H * scale;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
    return { dataUrl: canvas.toDataURL('image/png'), width: canvas.width, height: canvas.height };
  } finally {
    URL.revokeObjectURL(url);
  }
}

function escapeXml(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

// Рендерит SVG графика в blob/data-url PNG с retina-масштабом
async function renderChartAsPng(dark = false, scale = 2) {
  const svgStr = buildChartSvgString(dark ? '#2C1A12' : '#ffffff');
  const blob = new Blob([svgStr], { type: 'image/svg+xml' });
  const url = URL.createObjectURL(blob);
  try {
    const img = new Image();
    img.crossOrigin = 'anonymous';
    await new Promise((res, rej) => { img.onload = res; img.onerror = rej; img.src = url; });
    const canvas = document.createElement('canvas');
    canvas.width = CHART_W * scale;
    canvas.height = CHART_H * scale;
    const ctx = canvas.getContext('2d');
    ctx.scale(scale, scale);
    ctx.fillStyle = dark ? '#2C1A12' : '#ffffff';
    ctx.fillRect(0, 0, CHART_W, CHART_H);
    ctx.drawImage(img, 0, 0, CHART_W, CHART_H);
    return {
      blob: await new Promise(r => canvas.toBlob(r, 'image/png')),
      dataUrl: canvas.toDataURL('image/png'),
      width: canvas.width,
      height: canvas.height,
    };
  } finally {
    URL.revokeObjectURL(url);
  }
}

async function downloadChartPng(dark = false) {
  const png = await renderChartAsPng(dark);
  if (png.blob) downloadBlob(png.blob, 'cell-load-chart' + (dark ? '-dark' : '') + '.png');
}

// Меню «Скачать график» с выбором формата/фона
const chartExportMenu = ref(false);
// Текущий запущенный экспорт (для блокировки кнопок и индикации «...»)
const exportBusy = ref('');
async function runExport(fn, key) {
  exportBusy.value = key;
  try {
    await fn();
  } catch (e) {
    toast.error('Не удалось экспортировать', e.message || '');
  } finally {
    exportBusy.value = '';
  }
}
const exportWrapEl = ref(null);
function handleDocClick(e) {
  if (chartExportMenu.value && exportWrapEl.value && !exportWrapEl.value.contains(e.target)) {
    chartExportMenu.value = false;
  }
}
onMounted(() => document.addEventListener('click', handleDocClick));
onUnmounted(() => document.removeEventListener('click', handleDocClick));

// ─── Экспорт Excel ───
async function downloadExcel() {
  const XLSX = await import('xlsx-js-style');
  const wb = XLSX.utils.book_new();

  const brown = '502314';
  const bdr = { style: 'thin', color: { rgb: 'E0D6CC' } };
  const borders = { top: bdr, bottom: bdr, left: bdr, right: bdr };
  const sH = { font: { bold: true, sz: 11, color: { rgb: 'FFFFFF' }, name: 'Calibri' }, fill: { fgColor: { rgb: brown } }, alignment: { horizontal: 'center', vertical: 'center' }, border: borders };
  const sB = { font: { bold: true, sz: 11, name: 'Calibri' }, alignment: { vertical: 'center' }, border: borders };
  const sN = { font: { sz: 11, name: 'Calibri' }, alignment: { vertical: 'center', horizontal: 'right' }, border: borders };
  const sC = { font: { sz: 11, name: 'Calibri' }, alignment: { vertical: 'center' }, border: borders };

  // Лист 1: «Сводка» (KPI и инсайт)
  const summary = [];
  summary.push([{ v: 'Аналитика ячеек склада', t: 's', s: { font: { bold: true, sz: 14, color: { rgb: brown } } } }]);
  summary.push([{ v: 'Период: ' + dateRange.value.start + ' — ' + dateRange.value.end, t: 's' }]);
  summary.push([]);
  summary.push([{ v: 'KPI', t: 's', s: sH }, { v: 'Значение', t: 's', s: sH }, { v: 'Подзаголовок', t: 's', s: sH }]);
  for (const k of kpis.value) {
    summary.push([
      { v: k.label, t: 's', s: sB },
      { v: k.value + (k.unit ? ' ' + k.unit : ''), t: 's', s: sN },
      { v: k.subtitle || '', t: 's', s: sC },
    ]);
  }
  summary.push([]);
  summary.push([{ v: 'Авто-инсайт', t: 's', s: sH }]);
  summary.push([{ v: autoInsight.value, t: 's', s: { ...sC, alignment: { wrapText: true, vertical: 'top' } } }]);
  const ws1 = XLSX.utils.aoa_to_sheet(summary);
  ws1['!cols'] = [{ wch: 32 }, { wch: 24 }, { wch: 60 }];
  // Объединить «Авто-инсайт»-абзац на 3 колонки
  ws1['!merges'] = (ws1['!merges'] || []).concat([
    { s: { r: 0, c: 0 }, e: { r: 0, c: 2 } },
    { s: { r: 1, c: 0 }, e: { r: 1, c: 2 } },
    { s: { r: summary.length - 2, c: 0 }, e: { r: summary.length - 2, c: 2 } },
    { s: { r: summary.length - 1, c: 0 }, e: { r: summary.length - 1, c: 2 } },
  ]);
  XLSX.utils.book_append_sheet(wb, ws1, 'Сводка');

  // Лист 2: «Дни» (полные дневные значения)
  const days = [];
  days.push([
    { v: 'Дата', t: 's', s: sH },
    { v: 'Всего', t: 's', s: sH },
    ...[...new Set(filteredRows.value.map(r => r.legal_entity))].sort().map(e => ({ v: e, t: 's', s: sH })),
    ...[...new Set(filteredRows.value.map(r => r.stock_type))].sort().map(t => ({ v: STOCK_TYPE_LABELS[t] || t, t: 's', s: sH })),
  ]);
  const entities = [...new Set(filteredRows.value.map(r => r.legal_entity))].sort();
  const types = [...new Set(filteredRows.value.map(r => r.stock_type))].sort();
  for (const d of currentDays.value) {
    days.push([
      { v: d.date, t: 's', s: sC },
      { v: d.total, t: 'n', s: sN },
      ...entities.map(e => ({ v: d.byEntity[e] || 0, t: 'n', s: sN })),
      ...types.map(t => ({ v: d.byType[t] || 0, t: 'n', s: sN })),
    ]);
  }
  const ws2 = XLSX.utils.aoa_to_sheet(days);
  ws2['!cols'] = [{ wch: 12 }, { wch: 10 }, ...entities.map(() => ({ wch: 14 })), ...types.map(() => ({ wch: 12 }))];
  XLSX.utils.book_append_sheet(wb, ws2, 'Дни');

  // Лист 3: «Среднемесячные значения» по типам хранения (по юрлицам)
  if (monthlyAverages.value && monthlyAverages.value.groups.length) {
    const ma = monthlyAverages.value;
    const aoa = [];
    const headerRow = [
      { v: 'Юрлицо', t: 's', s: sH },
      { v: 'Месяц', t: 's', s: sH },
      ...ma.types.map(t => ({ v: STOCK_TYPE_LABELS[t] || t, t: 's', s: sH })),
      { v: 'Итого', t: 's', s: sH },
      { v: 'Дней в месяце', t: 's', s: sH },
    ];
    aoa.push(headerRow);
    for (const g of ma.groups) {
      for (const m of g.months) {
        aoa.push([
          { v: g.entity, t: 's', s: sC },
          { v: m.label, t: 's', s: sB },
          ...ma.types.map(t => ({ v: m.byType[t] || 0, t: 'n', s: sN })),
          { v: m.total || 0, t: 'n', s: { ...sN, font: { ...sN.font, bold: true } } },
          { v: m.daysCount, t: 'n', s: sN },
        ]);
      }
      // Среднее по юрлицу
      aoa.push([
        { v: g.entity, t: 's', s: { ...sC, fill: { fgColor: { rgb: 'FFF1E0' } } } },
        { v: 'Среднее за период', t: 's', s: { ...sB, fill: { fgColor: { rgb: 'FFF1E0' } } } },
        ...ma.types.map(t => ({ v: g.avgByType[t] || 0, t: 'n', s: { ...sN, font: { ...sN.font, bold: true }, fill: { fgColor: { rgb: 'FFF1E0' } } } })),
        { v: g.avgTotal || 0, t: 'n', s: { ...sN, font: { ...sN.font, bold: true, color: { rgb: 'C16B4D' } }, fill: { fgColor: { rgb: 'FFF1E0' } } } },
        { v: '', t: 's', s: { ...sN, fill: { fgColor: { rgb: 'FFF1E0' } } } },
      ]);
      aoa.push([]); // пустая строка между юрлицами
    }
    const ws3 = XLSX.utils.aoa_to_sheet(aoa);
    ws3['!cols'] = [
      { wch: 16 }, { wch: 14 },
      ...ma.types.map(() => ({ wch: 12 })),
      { wch: 12 }, { wch: 12 },
    ];
    XLSX.utils.book_append_sheet(wb, ws3, 'Среднемесячные');
  }

  XLSX.writeFile(wb, 'cell-analytics-' + dateRange.value.start + '_' + dateRange.value.end + '.xlsx');
}

// ─── Экспорт PDF ───
async function downloadPdf() {
  const { default: jsPDF } = await import('jspdf');
  // Подмешаем русский шрифт на лету — иначе jsPDF теряет кириллицу.
  // Используем встроенный helvetica + UTF-8: jsPDF поддерживает unicode только
  // через подключение шрифтов. Простой обходной путь — сохранить как PNG-страницу,
  // т.е. отрендерить весь блок в canvas. Для нашей задачи проще использовать
  // нативный jsPDF text + helvetica latin для чисел и UTF-8 строки через addFileToVFS.
  const pdf = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
  const pageW = pdf.internal.pageSize.getWidth();
  const pageH = pdf.internal.pageSize.getHeight();

  // jsPDF не поддерживает кириллицу из коробки. Подключим Roboto через CDN-base64 один раз.
  // Чтобы не тащить файл в бандл — используем text как изображение: рендерим заголовок/KPI/инсайт
  // на canvas с системным шрифтом, потом вставляем в PDF.
  const renderTextBlock = (text, opts = {}) => {
    const { width = 800, fontSize = 14, fontWeight = 'normal', color = '#2C1A12', lineHeight = 1.4 } = opts;
    const c = document.createElement('canvas');
    const ctx = c.getContext('2d');
    ctx.font = `${fontWeight} ${fontSize * 2}px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif`;
    // Перенос по словам
    const words = text.split(/\s+/);
    const lines = [];
    let cur = '';
    for (const w of words) {
      const test = cur ? cur + ' ' + w : w;
      if (ctx.measureText(test).width > width * 2) {
        if (cur) lines.push(cur);
        cur = w;
      } else cur = test;
    }
    if (cur) lines.push(cur);
    const h = Math.ceil(lines.length * fontSize * lineHeight);
    c.width = width * 2;
    c.height = h * 2;
    ctx.font = `${fontWeight} ${fontSize * 2}px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif`;
    ctx.fillStyle = color;
    ctx.textBaseline = 'top';
    lines.forEach((line, i) => ctx.fillText(line, 0, i * fontSize * lineHeight * 2));
    return { dataUrl: c.toDataURL('image/png'), width, height: h };
  };

  let y = 32;

  // Заголовок
  const title = renderTextBlock('Аналитика ячеек склада', { width: pageW - 64, fontSize: 22, fontWeight: '700' });
  pdf.addImage(title.dataUrl, 'PNG', 32, y, title.width, title.height);
  y += title.height + 4;

  const subtitle = renderTextBlock(`Период: ${dateRange.value.start} — ${dateRange.value.end}`, { width: pageW - 64, fontSize: 12, color: '#8B7355' });
  pdf.addImage(subtitle.dataUrl, 'PNG', 32, y, subtitle.width, subtitle.height);
  y += subtitle.height + 16;

  // KPI как текст-блок
  const kpiLines = kpis.value.map(k => `${k.label}: ${k.value}${k.unit ? ' ' + k.unit : ''}${k.subtitle ? ' — ' + k.subtitle : ''}`);
  const kpiBlock = renderTextBlock(kpiLines.join('   ·   '), { width: pageW - 64, fontSize: 12, fontWeight: '600' });
  pdf.addImage(kpiBlock.dataUrl, 'PNG', 32, y, kpiBlock.width, kpiBlock.height);
  y += kpiBlock.height + 14;

  // Инсайт
  const insight = renderTextBlock('💡  ' + autoInsight.value, { width: pageW - 64, fontSize: 11, color: '#4B3527' });
  pdf.addImage(insight.dataUrl, 'PNG', 32, y, insight.width, insight.height);
  y += insight.height + 14;

  // График
  const png = await renderChartAsPng(false, 2);
  const chartW = pageW - 64;
  const chartH = chartW * (CHART_H / CHART_W);
  if (y + chartH > pageH - 32) {
    pdf.addPage();
    y = 32;
  }
  pdf.addImage(png.dataUrl, 'PNG', 32, y, chartW, chartH);

  pdf.save('cell-analytics-' + dateRange.value.start + '_' + dateRange.value.end + '.pdf');
}

// ─── Экспорт PPTX ───

// Разбивка по типам хранения для одного блока (юрлица):
// среднее за период + дельта к предыдущему периоду (если comparePrev включён).
function buildWarehouseBreakdown(block) {
  const cur = block.currentDays || [];
  const prev = block.prevDays || [];
  if (!cur.length) return [];
  const types = new Set();
  cur.forEach(d => Object.keys(d.byType || {}).forEach(t => types.add(t)));
  prev.forEach(d => Object.keys(d.byType || {}).forEach(t => types.add(t)));
  const order = { dry: 1, cold: 2, frozen: 3, shabany: 4 };
  return [...types].sort((a, b) => (order[a] || 99) - (order[b] || 99)).map(t => {
    const sumCur = cur.reduce((s, d) => s + (d.byType[t] || 0), 0);
    const avgCur = cur.length ? Math.round(sumCur / cur.length) : 0;
    let avgPrev = null, deltaAbs = null, deltaPct = null;
    if (prev.length) {
      const sumPrev = prev.reduce((s, d) => s + (d.byType[t] || 0), 0);
      avgPrev = Math.round(sumPrev / prev.length);
      deltaAbs = avgCur - avgPrev;
      deltaPct = avgPrev > 0 ? Math.round(((avgCur - avgPrev) / avgPrev) * 1000) / 10 : (avgCur > 0 ? 100 : 0);
    }
    return {
      type: t,
      label: STOCK_TYPE_LABELS[t] || t,
      color: (TYPE_COLORS[t] || '#8B7355').replace('#', ''),
      avgCur,
      avgPrev,
      deltaAbs,
      deltaPct,
    };
  });
}

// Рендерит heatmap-календарь блока как PNG для слайда. Возвращает null если данных нет.
async function renderHeatmapPng(block) {
  const hm = block.heatmap;
  if (!hm || !hm.months?.length) return null;
  const months = hm.months;
  const W = 1600;
  const H = 900;
  const padTop = 90;
  const padBottom = 100;
  const monthW = Math.min(360, (W - 80) / months.length);
  const cellSize = Math.min(36, (monthW - 50) / 7);
  const cellGap = 3;

  let svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}">`;
  svg += `<rect width="100%" height="100%" fill="#ffffff"/>`;
  svg += `<text x="${W / 2}" y="40" text-anchor="middle" font-size="28" font-weight="700" font-family="-apple-system,Segoe UI,Roboto,Arial,sans-serif" fill="#502314">Календарь загрузки — ${escapeXml(block.label)}</text>`;
  svg += `<text x="${W / 2}" y="${40 + 28}" text-anchor="middle" font-size="16" font-family="-apple-system,Segoe UI,Roboto,Arial,sans-serif" fill="#8B7355">${escapeXml(`Период: ${dateRange.value.start} — ${dateRange.value.end}. Чем темнее, тем больше ячеек занято в этот день`)}</text>`;

  const startX = (W - months.length * monthW) / 2;
  months.forEach((m, mi) => {
    const x0 = startX + mi * monthW + 20;
    const y0 = padTop + 10;
    svg += `<text x="${x0}" y="${y0}" font-size="16" font-weight="700" fill="#502314" font-family="-apple-system,Segoe UI,Roboto,Arial,sans-serif">${escapeXml(fmtMonthHeader(m.key))}</text>`;
    const daysInM = daysInMonth(m.key);
    const [yearStr, monStr] = m.key.split('-');
    const firstDay = new Date(parseInt(yearStr, 10), parseInt(monStr, 10) - 1, 1).getDay();
    // 0=Sun → 6, 1..6 → -1; чтобы пн был 0.
    const firstOffset = (firstDay + 6) % 7;
    // Подписи дней недели
    const dayNames = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
    dayNames.forEach((dn, i) => {
      svg += `<text x="${x0 + i * (cellSize + cellGap) + cellSize / 2}" y="${y0 + 24}" text-anchor="middle" font-size="10" fill="#8B7355" font-family="-apple-system,Segoe UI,Roboto,Arial,sans-serif">${dn}</text>`;
    });
    for (let d = 1; d <= daysInM; d++) {
      const slot = firstOffset + d - 1;
      const col = slot % 7;
      const row = Math.floor(slot / 7);
      const x = x0 + col * (cellSize + cellGap);
      const y = y0 + 32 + row * (cellSize + cellGap);
      const dayInfo = m.days[d];
      let fill = '#F4ECE0';
      if (dayInfo) {
        const intensity = Math.min(1, dayInfo.total / Math.max(1, hm.max));
        // Бренд-градиент: от светло-бежевого к насыщенному оранжевому.
        const r = Math.round(255 - (255 - 231) * intensity);
        const g = Math.round(243 - (243 - 111) * intensity);
        const b = Math.round(220 - (220 - 81) * intensity);
        fill = `rgb(${r},${g},${b})`;
      }
      svg += `<rect x="${x}" y="${y}" width="${cellSize}" height="${cellSize}" rx="4" fill="${fill}" stroke="#ECE3D6" stroke-width="0.5"/>`;
      svg += `<text x="${x + cellSize / 2}" y="${y + cellSize / 2 + 4}" text-anchor="middle" font-size="9.5" fill="#2C1A12" font-family="-apple-system,Segoe UI,Roboto,Arial,sans-serif">${d}</text>`;
    }
  });

  // Легенда
  const legendY = H - 60;
  svg += `<text x="${W / 2 - 200}" y="${legendY}" font-size="13" fill="#8B7355" font-family="-apple-system,Segoe UI,Roboto,Arial,sans-serif">Меньше загрузка</text>`;
  for (let i = 0; i < 6; i++) {
    const intensity = i / 5;
    const r = Math.round(255 - (255 - 231) * intensity);
    const g = Math.round(243 - (243 - 111) * intensity);
    const b = Math.round(220 - (220 - 81) * intensity);
    svg += `<rect x="${W / 2 - 50 + i * 26}" y="${legendY - 14}" width="22" height="18" fill="rgb(${r},${g},${b})" stroke="#ECE3D6"/>`;
  }
  svg += `<text x="${W / 2 + 120}" y="${legendY}" font-size="13" fill="#8B7355" font-family="-apple-system,Segoe UI,Roboto,Arial,sans-serif">Больше загрузка</text>`;
  svg += `</svg>`;

  const blob = new Blob([svg], { type: 'image/svg+xml' });
  const url = URL.createObjectURL(blob);
  try {
    const img = new Image();
    img.crossOrigin = 'anonymous';
    await new Promise((res, rej) => { img.onload = res; img.onerror = rej; img.src = url; });
    const canvas = document.createElement('canvas');
    canvas.width = W * 2; canvas.height = H * 2;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
    return { dataUrl: canvas.toDataURL('image/png') };
  } finally {
    URL.revokeObjectURL(url);
  }
}

async function downloadPptx() {
  const { default: PptxGenJS } = await import('pptxgenjs');
  const pptx = new PptxGenJS();
  pptx.title = 'Аналитика ячеек склада';
  pptx.layout = 'LAYOUT_WIDE'; // 13.33 × 7.5

  const toneColor = { orange: 'E76F51', red: 'C62828', green: '2E7D32', blue: '1976D2', neutral: '8B7355' };
  const todayStr = new Date().toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });

  // Брендирующий футер на каждом слайде. Slide footer: тонкая оранжевая линия,
  // слева «Supply Portal · Аналитика ячеек · {дата}», справа номер слайда.
  function drawFooter(slide, pageNum, totalPages) {
    slide.addShape(pptx.ShapeType.rect, { x: 0, y: 7.30, w: 13.33, h: 0.04, fill: { color: 'E76F51' }, line: { color: 'E76F51' } });
    slide.addText(`Supply Portal · Аналитика ячеек склада · ${todayStr}`, {
      x: 0.4, y: 7.35, w: 9.5, h: 0.18, fontSize: 9, color: '8B7355', fontFace: 'Calibri',
    });
    if (pageNum) {
      slide.addText(`${pageNum}${totalPages ? ' / ' + totalPages : ''}`, {
        x: 12.4, y: 7.35, w: 0.9, h: 0.18, fontSize: 9, color: '8B7355', fontFace: 'Calibri', align: 'right',
      });
    }
  }

  // KPI-плашка с поддержкой дельты. Сейчас в KPI используется deltaPct (число %).
  function drawKpiCard(slide, x, y, w, h, k) {
    slide.addShape(pptx.ShapeType.rect, { x, y, w, h, fill: { color: 'FFFFFF' }, line: { color: 'ECE3D6', width: 1 } });
    slide.addShape(pptx.ShapeType.rect, { x, y, w, h: 0.08, fill: { color: toneColor[k.tone] || '8B7355' }, line: { color: toneColor[k.tone] || '8B7355' } });
    slide.addText(k.label, { x: x + 0.15, y: y + 0.18, w: w - 0.3, h: 0.3, fontSize: 12, color: '8B7355', fontFace: 'Calibri' });
    slide.addText(String(k.value) + (k.unit ? ' ' + k.unit : ''), { x: x + 0.15, y: y + 0.55, w: w - 0.3, h: 0.7, fontSize: 28, bold: true, color: '2C1A12', fontFace: 'Calibri' });
    // Дельта к прошлому периоду (если есть comparePrev и считается).
    if (k.deltaPct !== null && k.deltaPct !== undefined) {
      const isFlat = Math.abs(k.deltaPct) < 0.05;
      const arrow = isFlat ? '→' : (k.deltaPct >= 0 ? '↑' : '↓');
      const col = isFlat ? '8B7355' : (k.deltaPct >= 0 ? '1E7E34' : 'C62828');
      const pctStr = Math.abs(k.deltaPct) >= 10 ? Math.round(Math.abs(k.deltaPct)) : Math.abs(k.deltaPct).toFixed(1);
      slide.addText(`${arrow} ${pctStr}% к прошлому периоду`, {
        x: x + 0.15, y: y + h - 0.55, w: w - 0.3, h: 0.3, fontSize: 11, bold: true, color: col, fontFace: 'Calibri',
      });
    } else if (k.subtitle) {
      slide.addText(k.subtitle, { x: x + 0.15, y: y + h - 0.45, w: w - 0.3, h: 0.4, fontSize: 11, color: '8B7355', fontFace: 'Calibri' });
    }
  }

  // Плашка «По складам»: цветная полоса + два числа (среднее + дельта).
  function drawWarehouseCard(slide, x, y, w, h, b) {
    slide.addShape(pptx.ShapeType.rect, { x, y, w, h, fill: { color: 'FFFFFF' }, line: { color: 'ECE3D6', width: 1 } });
    slide.addShape(pptx.ShapeType.rect, { x, y, w: 0.10, h, fill: { color: b.color }, line: { color: b.color } });
    slide.addText(b.label, { x: x + 0.25, y: y + 0.10, w: w - 0.35, h: 0.3, fontSize: 12, color: '8B7355', fontFace: 'Calibri', bold: true });
    slide.addText(String(b.avgCur), { x: x + 0.25, y: y + 0.40, w: w - 0.35, h: 0.7, fontSize: 26, bold: true, color: '2C1A12', fontFace: 'Calibri' });
    slide.addText('ячеек в среднем', { x: x + 0.25, y: y + 1.10, w: w - 0.35, h: 0.25, fontSize: 10, color: '8B7355', fontFace: 'Calibri' });
    if (b.deltaPct !== null && b.deltaPct !== undefined) {
      const isFlat = Math.abs(b.deltaPct) < 0.05;
      const arrow = isFlat ? '→' : (b.deltaPct >= 0 ? '↑' : '↓');
      const col = isFlat ? '8B7355' : (b.deltaPct >= 0 ? '1E7E34' : 'C62828');
      const pctStr = Math.abs(b.deltaPct) >= 10 ? Math.round(Math.abs(b.deltaPct)) : Math.abs(b.deltaPct).toFixed(1);
      const absStr = (b.deltaAbs > 0 ? '+' : (b.deltaAbs < 0 ? '−' : '')) + Math.abs(b.deltaAbs);
      slide.addText(`${arrow} ${absStr}  (${pctStr}%)`, {
        x: x + 0.25, y: y + h - 0.45, w: w - 0.35, h: 0.3, fontSize: 12, bold: true, color: col, fontFace: 'Calibri',
      });
    }
  }

  // Считаем общее число слайдов заранее для нумерации (титул + по N юрлиц × 4 + 1 график).
  const blocks = entityBlocks.value;
  const ma = monthlyAverages.value;
  const totalPages = 1 + blocks.length * 2 + 1 + (ma && ma.groups.length ? ma.groups.length : 0);
  let page = 1;

  // Слайд 1: титул
  const s1 = pptx.addSlide();
  s1.background = { color: 'FAF6EF' };
  s1.addShape(pptx.ShapeType.rect, { x: 0, y: 0, w: 13.33, h: 0.25, fill: { color: 'E76F51' }, line: { color: 'E76F51' } });
  s1.addText('Аналитика ячеек склада', { x: 0.5, y: 2.0, w: 12, h: 1, fontSize: 44, bold: true, color: '502314', fontFace: 'Calibri' });
  s1.addText(`Период: ${dateRange.value.start} — ${dateRange.value.end}`, { x: 0.5, y: 3.2, w: 12, h: 0.5, fontSize: 18, color: '8B7355', fontFace: 'Calibri' });
  s1.addText('Supply Portal · Отдел закупок', { x: 0.5, y: 6.7, w: 12, h: 0.4, fontSize: 12, color: 'C16B4D', italic: true, fontFace: 'Calibri' });
  drawFooter(s1, page, totalPages); page++;

  // Для каждого юрлица — три слайда: KPI, Топ изменений, Heatmap.
  for (const block of blocks) {
    // ── Слайд KPI ─────────────────────────────────────────────────────
    const sk = pptx.addSlide();
    sk.background = { color: 'FFFFFF' };
    sk.addText(`Ключевые метрики — ${block.label}`, { x: 0.4, y: 0.25, w: 12, h: 0.5, fontSize: 22, bold: true, color: '502314', fontFace: 'Calibri' });
    sk.addText(`Период ${dateRange.value.start} — ${dateRange.value.end}`, { x: 0.4, y: 0.72, w: 12, h: 0.3, fontSize: 11, color: '8B7355', fontFace: 'Calibri', italic: true });

    // 4 общих KPI в ряд
    const kpiW = 3.0, kpiH = 1.7, gap = 0.15;
    const startX = 0.4;
    const startY = 1.15;
    block.kpis.slice(0, 4).forEach((k, i) => {
      const x = startX + i * (kpiW + gap);
      drawKpiCard(sk, x, startY, kpiW, kpiH, k);
    });

    // Разбивка по складам (типам хранения) — ряд цветных плашек
    const breakdown = buildWarehouseBreakdown(block);
    if (breakdown.length) {
      sk.addText('По типам хранения (среднее за период)', { x: 0.4, y: 3.0, w: 12, h: 0.35, fontSize: 13, bold: true, color: '502314', fontFace: 'Calibri' });
      const bH = 1.6, bGap = 0.15;
      const bN = breakdown.length;
      const bW = (12.5 - bGap * (bN - 1)) / bN;
      breakdown.forEach((b, i) => {
        const x = 0.4 + i * (bW + bGap);
        drawWarehouseCard(sk, x, 3.4, bW, bH, b);
      });
    }

    // Авто-инсайт внизу
    sk.addShape(pptx.ShapeType.rect, { x: 0.4, y: 5.2, w: 12.5, h: 1.85, fill: { color: 'FFF8F0' }, line: { color: 'ECE3D6', width: 1 } });
    sk.addText('💡 ' + (block.insight || ''), { x: 0.6, y: 5.3, w: 12.1, h: 1.65, fontSize: 13, color: '2C1A12', fontFace: 'Calibri', wrap: true, valign: 'top' });
    drawFooter(sk, page, totalPages); page++;

    // ── Слайд «Топ изменений по складам» ─────────────────────────────
    const sTop = pptx.addSlide();
    sTop.background = { color: 'FFFFFF' };
    sTop.addText(`Топ изменений по складам — ${block.label}`, { x: 0.4, y: 0.25, w: 12, h: 0.5, fontSize: 22, bold: true, color: '502314', fontFace: 'Calibri' });
    sTop.addText('Где изменилась загрузка сильнее всего относительно предыдущего периода', { x: 0.4, y: 0.72, w: 12, h: 0.3, fontSize: 11, color: '8B7355', fontFace: 'Calibri', italic: true });

    const changes = breakdown
      .filter(b => b.deltaPct !== null && b.deltaPct !== undefined)
      .sort((a, b) => Math.abs(b.deltaAbs) - Math.abs(a.deltaAbs));
    if (changes.length) {
      // Большие плашки: до 3 шт в ряд по 4.0×3.2 — главные изменения
      const topN = Math.min(3, changes.length);
      const cardW = 4.0, cardH = 3.2, cardGap = 0.3;
      const totalW = topN * cardW + (topN - 1) * cardGap;
      const cardStartX = (13.33 - totalW) / 2;
      const cardStartY = 1.5;
      changes.slice(0, topN).forEach((c, i) => {
        const x = cardStartX + i * (cardW + cardGap);
        const isFlat = Math.abs(c.deltaPct) < 0.05;
        const isUp = c.deltaPct >= 0 && !isFlat;
        const bg = isFlat ? 'F5EFE6' : (isUp ? 'E8F5EC' : 'FCEAE9');
        const bar = isFlat ? '8B7355' : (isUp ? '1E7E34' : 'C62828');
        sTop.addShape(pptx.ShapeType.rect, { x, y: cardStartY, w: cardW, h: cardH, fill: { color: bg }, line: { color: 'ECE3D6', width: 1 } });
        sTop.addShape(pptx.ShapeType.rect, { x, y: cardStartY, w: cardW, h: 0.12, fill: { color: bar }, line: { color: bar } });
        sTop.addText(c.label, { x: x + 0.2, y: cardStartY + 0.3, w: cardW - 0.4, h: 0.4, fontSize: 16, bold: true, color: '502314', fontFace: 'Calibri' });
        const absStr = (c.deltaAbs > 0 ? '+' : (c.deltaAbs < 0 ? '−' : '')) + Math.abs(c.deltaAbs);
        const pctStr = Math.abs(c.deltaPct) >= 10 ? Math.round(Math.abs(c.deltaPct)) : Math.abs(c.deltaPct).toFixed(1);
        const arrow = isFlat ? '→' : (isUp ? '↑' : '↓');
        sTop.addText(`${arrow} ${absStr}`, { x: x + 0.2, y: cardStartY + 0.75, w: cardW - 0.4, h: 1.1, fontSize: 44, bold: true, color: bar, fontFace: 'Calibri' });
        sTop.addText(`${pctStr}% к прошлому периоду`, { x: x + 0.2, y: cardStartY + 1.9, w: cardW - 0.4, h: 0.4, fontSize: 14, bold: true, color: bar, fontFace: 'Calibri' });
        sTop.addText(`Сейчас ${c.avgCur} · было ${c.avgPrev}`, { x: x + 0.2, y: cardStartY + 2.35, w: cardW - 0.4, h: 0.4, fontSize: 12, color: '8B7355', fontFace: 'Calibri' });
        sTop.addText('ячеек в среднем в день', { x: x + 0.2, y: cardStartY + 2.7, w: cardW - 0.4, h: 0.4, fontSize: 10, color: '8B7355', fontFace: 'Calibri', italic: true });
      });
    } else {
      sTop.addShape(pptx.ShapeType.rect, { x: 0.4, y: 1.5, w: 12.5, h: 4.5, fill: { color: 'FAF6EF' }, line: { color: 'ECE3D6', width: 1 } });
      sTop.addText('Сравнение с предыдущим периодом отключено — включите галку «Сравнить с прошлым периодом», чтобы увидеть динамику.',
        { x: 1.0, y: 3.0, w: 11.3, h: 1.5, fontSize: 16, color: '8B7355', fontFace: 'Calibri', align: 'center' });
    }
    sTop.addShape(pptx.ShapeType.rect, { x: 0.4, y: 5.4, w: 12.5, h: 1.65, fill: { color: 'FFF8F0' }, line: { color: 'ECE3D6', width: 1 } });
    sTop.addText('💡 ' + buildBreakdownInsight(breakdown), { x: 0.6, y: 5.5, w: 12.1, h: 1.45, fontSize: 13, color: '2C1A12', fontFace: 'Calibri', wrap: true, valign: 'top' });
    drawFooter(sTop, page, totalPages); page++;

  }

  // Слайд: общий график с заголовком, осями, легендой.
  const sChart = pptx.addSlide();
  sChart.background = { color: 'FFFFFF' };
  const rich = await renderRichChartPng({ title: 'Динамика загрузки ячеек склада', scale: 2 });
  if (rich) {
    sChart.addImage({ data: rich.dataUrl, x: 0.15, y: 0.15, w: 13.0, h: 7.05 });
  } else {
    sChart.addText('Динамика загрузки', { x: 0.5, y: 0.3, w: 12, h: 0.6, fontSize: 24, bold: true, color: '502314', fontFace: 'Calibri' });
    const png = await renderChartAsPng(false, 2);
    sChart.addImage({ data: png.dataUrl, x: 0.5, y: 1.1, w: 12.3, h: 5.6 });
  }
  drawFooter(sChart, page, totalPages); page++;

  // Слайды «Среднемесячные значения по складам» — по одному на каждое юрлицо.
  if (ma && ma.groups.length) {
    for (const g of ma.groups) {
      const sm = pptx.addSlide();
      sm.background = { color: 'FFFFFF' };
      sm.addText(`Среднемесячные значения — ${g.entity}`, { x: 0.4, y: 0.25, w: 12, h: 0.5, fontSize: 22, bold: true, color: '502314', fontFace: 'Calibri' });
      sm.addText('Под числом — изменение к прошлому месяцу (стрелка, абсолют и %)', { x: 0.4, y: 0.72, w: 12, h: 0.3, fontSize: 11, color: '8B7355', fontFace: 'Calibri', italic: true });

      const headerRow = [
        { text: 'Месяц', options: { bold: true, color: 'FFFFFF', fill: { color: '502314' }, align: 'left', valign: 'middle', fontSize: 12, fontFace: 'Calibri' } },
        ...ma.types.map(t => ({ text: STOCK_TYPE_LABELS[t] || t, options: { bold: true, color: 'FFFFFF', fill: { color: '502314' }, align: 'center', valign: 'middle', fontSize: 12, fontFace: 'Calibri' } })),
        { text: 'Дней', options: { bold: true, color: 'FFFFFF', fill: { color: '502314' }, align: 'center', valign: 'middle', fontSize: 12, fontFace: 'Calibri' } },
      ];
      const bodyRows = g.months.map((m, i) => {
        const stripe = i % 2 === 1 ? 'FFF8F0' : 'FFFFFF';
        const monthCell = { text: m.label, options: { bold: true, color: '502314', fill: { color: stripe }, align: 'left', valign: 'middle', fontSize: 12, fontFace: 'Calibri' } };
        const typeCells = ma.types.map(t => {
          const val = m.byType[t];
          const valText = (val === undefined || val === null) ? '—' : String(val);
          const pct = m.deltaPctByType ? m.deltaPctByType[t] : undefined;
          const abs = m.deltaByType ? m.deltaByType[t] : undefined;
          let deltaText = '';
          let deltaColor = '8B7355';
          if (pct !== undefined && pct !== null) {
            if (Math.abs(pct) < 0.05) { deltaText = '→ 0'; deltaColor = '8B7355'; }
            else {
              const arrow = pct > 0 ? '↑' : '↓';
              const absStr = (abs > 0 ? '+' : (abs < 0 ? '−' : '')) + Math.abs(abs || 0);
              const pctStr = Math.abs(pct) >= 10 ? Math.round(Math.abs(pct)) : Math.abs(pct).toFixed(1);
              deltaText = `${arrow} ${absStr} (${pctStr}%)`;
              deltaColor = pct > 0 ? '1E7E34' : 'C62828';
            }
          }
          return {
            text: [
              { text: valText, options: { fontSize: 13, bold: true, color: '2C1A12' } },
              ...(deltaText ? [{ text: '\n' + deltaText, options: { fontSize: 10, color: deltaColor, bold: true } }] : []),
            ],
            options: { fill: { color: stripe }, align: 'right', valign: 'middle', fontFace: 'Calibri' },
          };
        });
        const daysCell = { text: String(m.daysCount), options: { color: '8B7355', fill: { color: stripe }, align: 'center', valign: 'middle', fontSize: 11, fontFace: 'Calibri' } };
        return [monthCell, ...typeCells, daysCell];
      });
      const tableWidth = 12.5;
      const monthW = 1.6;
      const daysW = 0.8;
      const typeW = (tableWidth - monthW - daysW) / Math.max(1, ma.types.length);
      sm.addTable([headerRow, ...bodyRows], {
        x: 0.4,
        y: 1.2,
        w: tableWidth,
        colW: [monthW, ...ma.types.map(() => typeW), daysW],
        border: { type: 'solid', color: 'ECE3D6', pt: 0.5 },
        autoPage: false,
        rowH: 0.55,
      });
      drawFooter(sm, page, totalPages); page++;
    }
  }

  await pptx.writeFile({ fileName: 'cell-analytics-' + dateRange.value.start + '_' + dateRange.value.end + '.pptx' });
}

// Короткий авто-вывод по разбивке складов (для слайда «Топ изменений»).
function buildBreakdownInsight(breakdown) {
  const changes = breakdown.filter(b => b.deltaPct !== null && b.deltaPct !== undefined && Math.abs(b.deltaPct) >= 0.05);
  if (!changes.length) return 'За период изменения по складам несущественные — загрузка стабильна.';
  const up = changes.filter(c => c.deltaPct > 0).sort((a, b) => b.deltaAbs - a.deltaAbs);
  const down = changes.filter(c => c.deltaPct < 0).sort((a, b) => a.deltaAbs - b.deltaAbs);
  const parts = [];
  if (up.length) {
    const u = up[0];
    parts.push(`Заметнее всего вырос «${u.label}» — на ${Math.abs(u.deltaAbs)} ячеек (${Math.abs(u.deltaPct) >= 10 ? Math.round(Math.abs(u.deltaPct)) : Math.abs(u.deltaPct).toFixed(1)}%) к прошлому периоду.`);
  }
  if (down.length) {
    const d = down[0];
    parts.push(`Сильнее всего снизился «${d.label}» — на ${Math.abs(d.deltaAbs)} ячеек (${Math.abs(d.deltaPct) >= 10 ? Math.round(Math.abs(d.deltaPct)) : Math.abs(d.deltaPct).toFixed(1)}%).`);
  }
  return parts.join(' ');
}

// ─── Среднемесячные значения по типу хранения (и по юрлицу, если выбрано «Все») ───
const monthlyAverages = computed(() => {
  // Возвращает структуру:
  // - groupsByEntity: Map<entity, { months: [{ key, label, byType: {dry, cold, frozen, shabany}, total }], avgByType, total }>
  //   Если фильтр выбран — только одно юрлицо в map.
  // - types: упорядоченный массив типов хранения, встречающихся в данных
  if (!filteredRows.value.length) return null;

  // Соберём по (entity, month) → массив дневных rawRows для усреднения
  const acc = new Map(); // key: entity::month, value: { entity, monthKey, days: Map<date, {byType:{}}> }
  for (const r of filteredRows.value) {
    const dt = new Date(r.report_date);
    const monthKey = dt.getFullYear() + '-' + pad(dt.getMonth() + 1);
    const k = r.legal_entity + '::' + monthKey;
    if (!acc.has(k)) acc.set(k, { entity: r.legal_entity, monthKey, days: new Map() });
    const slot = acc.get(k);
    if (!slot.days.has(r.report_date)) slot.days.set(r.report_date, { byType: {} });
    const day = slot.days.get(r.report_date);
    day.byType[r.stock_type] = (day.byType[r.stock_type] || 0) + (parseInt(r.cell_count, 10) || 0);
  }

  // Превратим acc в группы по юрлицам
  const byEntity = new Map();
  for (const slot of acc.values()) {
    const days = [...slot.days.values()];
    const monthAvg = { byType: {}, total: 0, daysCount: days.length };
    const typeKeys = new Set();
    for (const d of days) {
      for (const [t, v] of Object.entries(d.byType)) {
        typeKeys.add(t);
        monthAvg.byType[t] = (monthAvg.byType[t] || 0) + v;
      }
    }
    for (const t of typeKeys) {
      monthAvg.byType[t] = Math.round(monthAvg.byType[t] / days.length);
      monthAvg.total += monthAvg.byType[t];
    }

    if (!byEntity.has(slot.entity)) byEntity.set(slot.entity, []);
    byEntity.get(slot.entity).push({
      key: slot.monthKey,
      label: bucketLabel(slot.monthKey, 'month'),
      byType: monthAvg.byType,
      total: monthAvg.total,
      daysCount: monthAvg.daysCount,
    });
  }

  // Сортировка месяцев + считаем дельты к предыдущему месяцу прямо здесь,
  // чтобы шаблон таблицы и PPT-генератор не дублировали логику.
  for (const arr of byEntity.values()) {
    arr.sort((a, b) => a.key.localeCompare(b.key));
    for (let i = 0; i < arr.length; i++) {
      const cur = arr[i];
      const prev = i > 0 ? arr[i - 1] : null;
      cur.deltaByType = {};
      cur.deltaPctByType = {};
      if (prev) {
        for (const t of Object.keys(cur.byType)) {
          const a = cur.byType[t] || 0;
          const b = prev.byType[t] || 0;
          cur.deltaByType[t] = a - b;
          cur.deltaPctByType[t] = b > 0 ? Math.round(((a - b) / b) * 1000) / 10 : (a > 0 ? 100 : 0);
        }
        cur.deltaTotal = (cur.total || 0) - (prev.total || 0);
        cur.deltaPctTotal = (prev.total || 0) > 0
          ? Math.round((((cur.total || 0) - prev.total) / prev.total) * 1000) / 10
          : ((cur.total || 0) > 0 ? 100 : 0);
      } else {
        cur.deltaTotal = null;
        cur.deltaPctTotal = null;
      }
    }
  }

  // Подсчёт средних/итого по юрлицу
  const result = [];
  const allTypes = new Set();
  for (const r of filteredRows.value) allTypes.add(r.stock_type);
  const types = [...allTypes].sort((a, b) => {
    const order = { dry: 1, cold: 2, frozen: 3, shabany: 4 };
    return (order[a] || 99) - (order[b] || 99);
  });

  // Список юрлиц для отображения (если фильтр — только оно)
  const entities = filterEntity.value ? [filterEntity.value] : [...byEntity.keys()].sort();
  for (const e of entities) {
    const months = byEntity.get(e) || [];
    const avgByType = {};
    let avgTotal = 0;
    for (const t of types) {
      const arr = months.map(m => m.byType[t] || 0);
      avgByType[t] = arr.length ? Math.round(arr.reduce((a, b) => a + b, 0) / arr.length) : 0;
    }
    avgTotal = types.reduce((s, t) => s + (avgByType[t] || 0), 0);
    result.push({ entity: e, months, avgByType, avgTotal });
  }
  return { groups: result, types };
});

// Помощники для отображения дельты в таблице среднемесячных.
function deltaArrow(pct) {
  if (pct === null || pct === undefined) return '';
  if (Math.abs(pct) < 0.05) return '→';
  return pct > 0 ? '↑' : '↓';
}
function deltaTone(pct) {
  if (pct === null || pct === undefined) return '';
  if (Math.abs(pct) < 0.05) return 'flat';
  return pct > 0 ? 'up' : 'down';
}
function formatDelta(pct) {
  if (pct === null || pct === undefined) return '';
  const v = Math.abs(pct);
  if (v < 0.05) return '0%';
  // ≥10% — без дробной; меньше — одна цифра после запятой.
  return (v >= 10 ? Math.round(v) : v.toFixed(1)) + '%';
}
function formatDeltaAbs(n) {
  if (n === null || n === undefined) return '';
  const v = Math.abs(Math.round(Number(n) || 0));
  return v.toLocaleString('ru-RU');
}

// Возвращает координаты Y-области для рендера вертикальной линии аннотации
const chartAnnotations = computed(() => {
  if (!annotations.value.length) return [];
  return annotations.value
    .map(a => {
      // Найдём ближайший X на оси (по дате, неделе или месяцу)
      const k = bucketKey(a.event_date, granularity.value);
      const x = xPositions.value.get(k);
      if (x === undefined) return null;
      return { ...a, x };
    })
    .filter(Boolean);
});

// ─── Старт ───
onMounted(() => {
  syncFromUrl();
  if (presetKey.value === 'custom' && (!customStart.value || !customEnd.value)) {
    const r = rangeFromPreset('3m');
    customStart.value = r.start;
    customEnd.value = r.end;
  }
  loadData();
});
</script>

<style scoped>
.sla-view {
  padding: 20px; max-width: 1280px; margin: 0 auto; color: #2C1A12;
  /* Не даём внутренним блокам тянуть страницу горизонтально:
     внутри .sla-chart-wrap / .sla-table-scroll есть свой собственный скролл. */
  overflow-x: hidden;
  width: 100%;
  box-sizing: border-box;
}
/* На всякий — все секции/контейнеры внутри тоже не должны переполнять. */
.sla-view > * { max-width: 100%; }

/* Шапка */
.sla-header {
  display: flex; align-items: flex-start; justify-content: space-between;
  gap: 18px; margin-bottom: 16px; flex-wrap: wrap;
  max-width: 100%; min-width: 0;
}
.sla-header > div { min-width: 0; }
.sla-back {
  display: inline-block; margin-bottom: 4px;
  font-size: 13px; color: #6B5344; text-decoration: none;
}
.sla-back:hover { color: #E76F51; }
.sla-title { margin: 0 0 4px; font-size: 22px; font-weight: 700; color: #2C1A12; }
.sla-sub { margin: 0; color: #8B7355; font-size: 13px; }
.sla-header-actions { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
.sla-export-wrap { position: relative; }
.sla-export-menu {
  position: absolute; right: 0; top: calc(100% + 6px);
  background: #fff; border: 1px solid #ECE3D6; border-radius: 10px;
  box-shadow: 0 8px 22px rgba(80,35,20,.18);
  min-width: 180px; padding: 4px;
  z-index: 30;
  display: flex; flex-direction: column;
}
.sla-export-item {
  background: transparent; border: none; padding: 8px 12px;
  text-align: left; font: inherit; font-size: 13px; color: #2C1A12;
  cursor: pointer; border-radius: 6px;
}
.sla-export-item:hover { background: #FFF1E0; color: #E76F51; }

.sla-btn {
  display: inline-flex; align-items: center; justify-content: center;
  padding: 8px 14px; border-radius: 8px; border: 1.5px solid transparent;
  font: inherit; font-size: 13px; font-weight: 600;
  cursor: pointer; transition: all .15s;
}
.sla-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.sla-btn.outline { background: #fff; color: #6B5344; border-color: #E8DCC8; }
.sla-btn.outline:hover:not(:disabled) { border-color: #E76F51; color: #E76F51; }
.sla-btn.primary { background: #E76F51; color: #fff; border-color: #E76F51; }
.sla-btn.primary:hover:not(:disabled) { background: #D9603F; border-color: #D9603F; }

/* Тулбар */
.sla-toolbar {
  background: #fff; border: 1px solid #ECE3D6; border-radius: 14px;
  padding: 12px 16px; margin-bottom: 16px;
  display: flex; flex-direction: column; gap: 10px;
}
.sla-toolbar-row {
  display: flex; align-items: center; flex-wrap: wrap; gap: 8px;
  max-width: 100%; min-width: 0;
}
.sla-toolbar-row > * { min-width: 0; max-width: 100%; }
.sla-toolbar-label {
  font-size: 12px; font-weight: 700; color: #8B7355;
  text-transform: uppercase; letter-spacing: 0.04em;
  margin-right: 4px;
}
.sla-toolbar-divider {
  width: 1px; height: 22px; background: #ECE3D6;
  margin: 0 8px; flex-shrink: 0;
}
.sla-chip {
  padding: 6px 12px; border-radius: 999px; border: 1.5px solid #E8DCC8;
  background: #FFFBF6; color: #6B5344;
  font: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer;
  transition: all .15s;
}
.sla-chip:hover { border-color: #E76F51; }
.sla-chip.active { background: #E76F51; border-color: #E76F51; color: #fff; }

.sla-toolbar-range { display: inline-flex; align-items: center; gap: 6px; margin-left: 6px; }
.sla-date {
  padding: 6px 10px; border: 1.5px solid #E8DCC8; border-radius: 8px;
  background: #fff; font: inherit; font-size: 13px; color: #2C1A12;
}
.sla-date-sep { color: #8B7355; }

.sla-toggle {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 13px; color: #2C1A12; cursor: pointer; user-select: none;
}
.sla-toggle input { accent-color: #E76F51; }

/* Состояния */
.sla-empty {
  background: #fff; border: 1px solid #ECE3D6; border-radius: 14px;
  padding: 40px; text-align: center; color: #8B7355;
}
.sla-error { color: #C62828; }

/* Per-entity блоки */
.sla-entity-block { margin-bottom: 14px; }
.sla-entity-header {
  display: flex; align-items: center; gap: 10px;
  padding: 6px 4px 8px; margin-bottom: 8px;
  border-bottom: 2px solid #E76F51;
}
.sla-entity-dot { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; }
.sla-entity-title { margin: 0; font-size: 17px; font-weight: 700; color: #2C1A12; }

/* Heatmap multi-entity row */
.sla-heatmap-row {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 18px;
}
.sla-heatmap-cell-block { min-width: 0; }
.sla-heatmap-block-title {
  display: flex; align-items: center; gap: 8px;
  font-size: 13px; font-weight: 700; color: #2C1A12;
  padding: 6px 0 8px; margin-bottom: 6px;
  border-bottom: 1px dashed #E8DCC8;
}

/* KPI */
.sla-kpi-grid {
  display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;
  margin-bottom: 16px;
}
.sla-kpi {
  background: #fff; border: 1px solid #ECE3D6; border-radius: 14px;
  padding: 16px; position: relative; overflow: hidden;
  display: flex; flex-direction: column; gap: 6px;
}
.sla-kpi-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.sla-kpi-label { font-size: 12px; font-weight: 600; color: #8B7355; }
.sla-kpi-delta {
  display: inline-flex; align-items: center; gap: 2px;
  padding: 2px 8px; border-radius: 999px; font-size: 11.5px; font-weight: 700;
}
.sla-kpi-delta.up { background: #E8F5E9; color: #2E7D32; }
.sla-kpi-delta.down { background: #FFEBEE; color: #C62828; }
.sla-kpi-value { font-size: 28px; font-weight: 700; color: #2C1A12; line-height: 1.1; }
.sla-kpi-unit { font-size: 14px; color: #8B7355; font-weight: 600; margin-left: 4px; }
.sla-kpi-sub { font-size: 12px; color: #8B7355; }
.sla-kpi-spark { width: 100%; height: 24px; margin-top: 4px; }
.sla-kpi--orange { border-top: 3px solid #E76F51; }
.sla-kpi--red { border-top: 3px solid #C62828; }
.sla-kpi--green { border-top: 3px solid #2E7D32; }
.sla-kpi--blue { border-top: 3px solid #1976D2; }
.sla-kpi--neutral { border-top: 3px solid #8B7355; }

/* Авто-инсайт */
.sla-insight {
  background: linear-gradient(135deg, #FFF8F0, #FFFBF6);
  border: 1px solid #ECE3D6; border-radius: 14px;
  padding: 14px 18px; margin-bottom: 16px;
  display: flex; gap: 12px; align-items: flex-start;
}
.sla-insight-icon { font-size: 22px; flex-shrink: 0; }
.sla-insight p { margin: 0; color: #2C1A12; font-size: 14px; line-height: 1.5; }

/* Карточка графика */
.sla-chart-card,
.sla-heatmap-card {
  background: #fff; border: 1px solid #ECE3D6; border-radius: 14px;
  padding: 16px 18px; margin-bottom: 12px;
}
.sla-chart-head {
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; flex-wrap: wrap; margin-bottom: 10px;
}
.sla-chart-head h3 { margin: 0; font-size: 15px; font-weight: 700; color: #2C1A12; }
.sla-chart-legend { display: flex; flex-wrap: wrap; gap: 8px; }
.sla-legend-item {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 12.5px; cursor: pointer; padding: 3px 8px; border-radius: 999px;
  background: #FAFAF8; user-select: none;
}
.sla-legend-item:hover { background: #FFF1E0; }
.sla-legend-item.off { opacity: 0.4; }
.sla-legend-item.dashed { font-style: italic; }
.sla-legend-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }

.sla-chart-wrap {
  position: relative;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  max-width: 100%;
}
.sla-chart-svg { width: 100%; height: 360px; display: block; }
.sla-chart-tooltip {
  position: absolute; pointer-events: none; z-index: 10;
  background: #2C1A12; color: #fff; border-radius: 8px;
  padding: 8px 10px; font-size: 12.5px; line-height: 1.4;
  box-shadow: 0 6px 14px rgba(0,0,0,.18);
  white-space: nowrap;
}
.sla-chart-tooltip-title { color: #FFD54F; font-weight: 700; margin-bottom: 2px; }
.sla-tooltip-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 4px; vertical-align: 1px; }
.sla-chart-hint { font-size: 11.5px; color: #8B7355; margin: 8px 0 0; text-align: center; }

/* Заметная секция экспорта в основном потоке */
.sla-export-section {
  background: linear-gradient(135deg, #FFF8F0, #FFFBF6);
  border: 1.5px solid #FFD9B6; border-radius: 14px;
  padding: 18px 22px; margin-bottom: 14px;
  display: flex; align-items: center; justify-content: space-between;
  gap: 18px; flex-wrap: wrap;
}
.sla-export-section-text h3 {
  margin: 0 0 4px; font-size: 17px; color: #2C1A12; font-weight: 700;
}
.sla-export-section-text p {
  margin: 0; font-size: 13px; color: #6B5344;
}
.sla-export-section-buttons {
  display: flex; gap: 8px; flex-wrap: wrap;
}
.sla-export-btn {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 10px 14px; border-radius: 10px;
  border: 1.5px solid #FFD9B6; background: #fff;
  color: #2C1A12; font: inherit; font-size: 13.5px; font-weight: 600;
  cursor: pointer; transition: all .15s;
}
.sla-export-btn:hover:not(:disabled) {
  border-color: #E76F51; transform: translateY(-1px);
  box-shadow: 0 4px 10px rgba(231,111,81,.18);
}
.sla-export-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.sla-export-btn.primary {
  background: #E76F51; color: #fff; border-color: #E76F51;
}
.sla-export-btn.primary:hover:not(:disabled) {
  background: #D9603F; border-color: #D9603F;
}
.sla-export-btn-ico { font-size: 18px; }
@media (max-width: 640px) {
  .sla-export-section-buttons { width: 100%; }
  .sla-export-btn { flex: 1; justify-content: center; }
}

/* Сводная таблица среднемесячных */
.sla-table-card {
  background: #fff; border: 1px solid #ECE3D6; border-radius: 14px;
  padding: 16px 18px; margin-bottom: 12px;
}
.sla-table-hint { font-size: 11.5px; color: #8B7355; }
.sla-table-wrap { margin-top: 10px; }
.sla-table-entity-name {
  font-size: 13px; font-weight: 700; color: #2C1A12;
  padding: 8px 4px 6px; border-bottom: 2px solid #E76F51; margin-bottom: 6px;
  display: inline-block;
}
.sla-table-scroll { overflow-x: auto; }
.sla-table {
  width: 100%; border-collapse: collapse; font-size: 13px;
  min-width: 540px;
}
.sla-table thead th {
  background: #FFF8F0; color: #6B5344; font-weight: 700;
  text-align: right; padding: 8px 10px; font-size: 12px;
  border-bottom: 1.5px solid #E8DCC8;
}
.sla-table-th-month { text-align: left !important; }
.sla-table-th-total { background: #FFF1E0 !important; color: #C16B4D !important; }
.sla-table-th-days { width: 60px; text-align: center !important; }
.sla-table tbody td { padding: 8px 10px; border-bottom: 1px solid #F2EDE8; }
.sla-table tbody tr:hover { background: #FAFAF8; }
.sla-table-month { color: #2C1A12; font-weight: 600; }
.sla-table-num { text-align: right; font-variant-numeric: tabular-nums; color: #2C1A12; }
.sla-table-num .sla-cell-num { font-weight: 600; }
.sla-table-num .sla-cell-delta { font-size: 10.5px; font-weight: 700; margin-top: 1px; line-height: 1.1; }
.sla-cell-delta.up { color: #1E7E34; }
.sla-cell-delta.down { color: #C62828; }
.sla-cell-delta.flat { color: #8B7355; }
.sla-table-total { background: rgba(231,111,81,0.06); color: #C16B4D; font-weight: 700; }
.sla-table-days { text-align: center; color: #8B7355; font-size: 11.5px; }
.sla-table tfoot td {
  padding: 10px; background: #FFF8F0; font-weight: 700;
  border-top: 1.5px solid #E8DCC8; border-bottom: none;
}

/* Heatmap */
.sla-heat-legend { display: inline-flex; align-items: center; gap: 4px; }
.sla-heat-legend-text { font-size: 11.5px; color: #8B7355; }
.sla-heat-legend .sla-heat-cell { width: 12px; height: 12px; border-radius: 3px; }
.sla-heatmap {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 16px;
}
.sla-heat-month { }
.sla-heat-month-name {
  font-size: 12.5px; font-weight: 700; color: #2C1A12;
  margin-bottom: 6px; text-transform: capitalize;
}
.sla-heat-grid {
  display: grid; grid-template-columns: repeat(7, 1fr); gap: 3px;
}
.sla-heat-day {
  aspect-ratio: 1 / 1;
  border-radius: 4px; background: #F5F1EB;
  display: flex; align-items: center; justify-content: center;
  font-size: 9.5px; color: rgba(44,26,18,0.6);
  cursor: pointer; transition: transform .12s, box-shadow .12s;
  position: relative;
}
.sla-heat-day:hover { transform: scale(1.18); box-shadow: 0 2px 8px rgba(0,0,0,.18); z-index: 2; }
.sla-heat-day.empty { cursor: default; opacity: 0.45; }
.sla-heat-day.empty:hover { transform: none; box-shadow: none; }
.sla-heat-day-num { font-weight: 600; }

/* Drill-down */
.sla-drill-overlay {
  position: fixed; inset: 0; z-index: 1100;
  background: rgba(20,10,5,.5);
  display: flex; align-items: center; justify-content: center; padding: 16px;
}
.sla-drill {
  background: #fff; border-radius: 16px; padding: 22px 22px 18px;
  max-width: 520px; width: 100%;
  box-shadow: 0 14px 40px rgba(0,0,0,.22);
}
.sla-drill-head {
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; margin-bottom: 14px;
}
.sla-drill-head h3 { margin: 0; font-size: 17px; color: #2C1A12; }
.sla-drill-close {
  background: transparent; border: none; cursor: pointer;
  font-size: 22px; line-height: 1; color: #8B7355; padding: 4px 8px;
  font-family: inherit;
}
.sla-drill-close:hover { color: #C62828; }
.sla-drill-stat {
  background: #FFF1E0; border-radius: 10px; padding: 12px 16px;
  text-align: center; margin-bottom: 14px;
}
.sla-drill-total {
  font-size: 30px; font-weight: 800; color: #C16B4D; line-height: 1;
}
.sla-drill-total span {
  display: block; font-size: 12px; font-weight: 600;
  color: #8B7355; margin-top: 4px; letter-spacing: 0.04em;
}
.sla-drill-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.sla-drill-col-title {
  font-size: 11.5px; font-weight: 700; color: #8B7355;
  text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 6px;
}
.sla-drill-row {
  display: flex; align-items: center; gap: 8px;
  padding: 6px 0; border-bottom: 1px solid #F2EDE8; font-size: 13px;
}
.sla-drill-row:last-child { border-bottom: none; }
.sla-drill-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.sla-drill-label { flex: 1; color: #2C1A12; }
.sla-drill-val { font-weight: 700; color: #2C1A12; }
@media (max-width: 540px) {
  .sla-drill-grid { grid-template-columns: 1fr; }
}

/* ═══ Адаптация для планшетов и мобилок ═══ */
@media (max-width: 900px) {
  .sla-kpi-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
  .sla-view { padding: 14px; }
}

@media (max-width: 640px) {
  .sla-view { padding: 12px; padding-bottom: 80px; }

  /* Шапка */
  .sla-title { font-size: 19px; }
  .sla-sub { font-size: 12px; line-height: 1.4; }
  .sla-back { font-size: 12px; }

  /* Тулбар */
  .sla-toolbar { padding: 10px 12px; gap: 8px; }
  .sla-toolbar-row { gap: 6px; }
  .sla-toolbar-label { font-size: 11px; margin-right: 2px; flex-basis: 100%; margin-bottom: -2px; }
  .sla-toolbar-divider { display: none; }
  .sla-chip { padding: 5px 10px; font-size: 11.5px; }
  .sla-toggle { font-size: 12px; padding: 4px 6px; }
  .sla-date { padding: 5px 8px; font-size: 12px; }

  /* Per-entity блоки */
  .sla-entity-block { margin-bottom: 18px; }
  .sla-entity-header { padding: 4px 2px 6px; gap: 8px; }
  .sla-entity-title { font-size: 15px; }

  /* KPI */
  .sla-kpi-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
  .sla-kpi { padding: 12px 12px 10px; }
  .sla-kpi-label { font-size: 11px; }
  .sla-kpi-value { font-size: 22px; }
  .sla-kpi-unit { font-size: 12px; }
  .sla-kpi-sub { font-size: 11px; }
  .sla-kpi-spark { height: 20px; }
  .sla-kpi-delta { font-size: 10.5px; padding: 2px 6px; }

  /* Авто-инсайт */
  .sla-insight { padding: 12px 14px; gap: 10px; }
  .sla-insight-icon { font-size: 18px; }
  .sla-insight p { font-size: 12.5px; line-height: 1.45; }

  /* Экспорт */
  .sla-export-section {
    padding: 14px; flex-direction: column; align-items: stretch;
    gap: 12px;
  }
  .sla-export-section-text h3 { font-size: 15px; }
  .sla-export-section-text p { font-size: 12px; }
  .sla-export-section-buttons { width: 100%; gap: 6px; }
  .sla-export-btn { flex: 1 1 calc(50% - 4px); justify-content: center; padding: 10px 8px; font-size: 12.5px; }
  .sla-export-btn-ico { font-size: 16px; }
  .sla-export-wrap { flex: 1 1 100%; }
  .sla-export-wrap .sla-export-btn { width: 100%; }
  .sla-export-menu { left: 0; right: 0; min-width: 0; }

  /* График */
  .sla-chart-card { padding: 12px 12px 14px; }
  .sla-chart-head h3 { font-size: 14px; }
  .sla-chart-legend { gap: 6px; }
  .sla-legend-item { font-size: 11px; padding: 3px 7px; }
  .sla-chart-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  .sla-chart-svg { min-width: 640px; height: 280px; }
  .sla-chart-hint { font-size: 11px; }

  /* Heatmap */
  .sla-heatmap-card { padding: 12px; }
  .sla-heatmap-row { grid-template-columns: 1fr; gap: 14px; }
  .sla-heat-month-name { font-size: 11.5px; }
  .sla-heat-day-num { font-size: 8.5px; }
  .sla-heat-legend-text { font-size: 10.5px; }

  /* Сводная таблица */
  .sla-table-card { padding: 12px; }
  .sla-table-hint { display: none; }
  .sla-table-entity-name { font-size: 12px; }
  .sla-table { font-size: 11.5px; min-width: 460px; }
  .sla-table thead th { padding: 6px 7px; font-size: 11px; }
  .sla-table tbody td { padding: 6px 7px; }
  .sla-table tfoot td { padding: 7px; }

  /* Drill-down модал */
  .sla-drill { padding: 18px 16px 14px; max-width: 100%; }
  .sla-drill-head h3 { font-size: 15px; }
  .sla-drill-total { font-size: 26px; }
  .sla-drill-grid { grid-template-columns: 1fr; gap: 10px; }
}

@media (max-width: 380px) {
  .sla-kpi-grid { grid-template-columns: 1fr; }
  .sla-export-btn { flex: 1 1 100%; }
}
</style>
