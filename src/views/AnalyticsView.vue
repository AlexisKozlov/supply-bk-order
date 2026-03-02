<template>
  <div class="analytics-view">
    <!-- Header -->
    <div class="an-header">
      <h1 class="page-title">Аналитика</h1>
      <select v-model.number="days" @change="load" class="an-period">
        <option :value="7">7 дней</option>
        <option :value="14">14 дней</option>
        <option :value="30">30 дней</option>
        <option :value="60">60 дней</option>
        <option :value="90">90 дней</option>
      </select>
    </div>

    <!-- Anomaly banner (if critical, always visible) -->
    <div v-if="!loading && data && criticalAnomalies.length && activeTab !== 'anomalies'" class="an-alert-banner" @click="activeTab = 'anomalies'">
      <BkIcon name="warning" size="sm"/> {{ criticalAnomalies.length }} критич. аномалий
      <span class="an-alert-link">Смотреть →</span>
    </div>

    <!-- Tabs -->
    <div v-if="!loading && data" class="an-tabs">
      <button v-for="t in tabs" :key="t.id" class="an-tab" :class="{ active: activeTab === t.id }" @click="activeTab = t.id">
        {{ t.label }}
        <span v-if="t.id === 'anomalies' && data.anomalies.length" class="an-tab-badge">{{ data.anomalies.length }}</span>
      </button>
    </div>

    <div v-if="loading" style="text-align:center;padding:60px;">
      <BurgerSpinner text="Загрузка..." />
    </div>
    <div v-else-if="!data" style="text-align:center;padding:60px;color:var(--text-muted);">Нет данных за выбранный период</div>

    <!-- Tab content -->
    <div v-else class="an-content">

      <!-- ===== OVERVIEW ===== -->
      <template v-if="activeTab === 'overview'">
        <!-- KPI cards -->
        <div class="an-kpi-grid">
          <div class="an-kpi">
            <div class="an-kpi-head"><span class="an-kpi-icon"><BkIcon name="history" size="sm"/></span> Заказов</div>
            <div class="an-kpi-row">
              <span class="an-kpi-val">{{ nf(data.totals.orders) }}</span>
              <span v-if="data.deltaOrders !== null" class="an-badge" :class="data.deltaOrders >= 0 ? 'up' : 'down'">
                {{ data.deltaOrders >= 0 ? '▲' : '▼' }} {{ Math.abs(data.deltaOrders) }}%
              </span>
            </div>
            <div class="an-kpi-sub">прошлый: {{ nf(data.prev.orders) }}</div>
          </div>
          <div class="an-kpi">
            <div class="an-kpi-head"><span class="an-kpi-icon"><BkIcon name="order" size="sm"/></span> Коробок</div>
            <div class="an-kpi-row">
              <span class="an-kpi-val">{{ nf(data.totals.boxes) }}</span>
              <span v-if="data.deltaBoxes !== null" class="an-badge" :class="data.deltaBoxes >= 0 ? 'up' : 'down'">
                {{ data.deltaBoxes >= 0 ? '▲' : '▼' }} {{ Math.abs(data.deltaBoxes) }}%
              </span>
            </div>
            <div class="an-kpi-sub">прошлый: {{ nf(data.prev.boxes) }}</div>
          </div>
          <div class="an-kpi">
            <div class="an-kpi-head"><span class="an-kpi-icon"><BkIcon name="ruler" size="sm"/></span> Ср. кор/заказ</div>
            <div class="an-kpi-row">
              <span class="an-kpi-val">{{ data.totals.orders ? Math.round(data.totals.boxes / data.totals.orders) : 0 }}</span>
            </div>
            <div class="an-kpi-sub">за период</div>
          </div>
          <div class="an-kpi" v-if="data.planFact.receivedOrders > 0">
            <div class="an-kpi-head"><span class="an-kpi-icon"><BkIcon name="success" size="sm"/></span> Выполнение</div>
            <div class="an-kpi-row">
              <span class="an-kpi-val" :style="{ color: data.planFact.fulfillmentPct >= 95 ? '#2E7D32' : data.planFact.fulfillmentPct >= 80 ? '#E65100' : '#D32F2F' }">{{ data.planFact.fulfillmentPct }}%</span>
            </div>
            <div class="an-kpi-sub">{{ data.planFact.discrepancyItems }} расхожд. из {{ data.planFact.totalReceivedItems }}</div>
          </div>
        </div>

        <!-- Chart -->
        <div class="an-card">
          <div class="an-card-header">
            <span class="an-card-title"><BkIcon name="calendar" size="sm"/> Коробок по дням</span>
            <div class="an-legend">
              <span v-for="s in data.suppliers" :key="s.supplier" class="an-legend-item">
                <span class="an-legend-dot" :style="{ background: s.color }"></span>{{ s.supplier }}
              </span>
            </div>
          </div>
          <div class="an-chart">
            <div v-for="(day, i) in chartDays" :key="day.dayKey" class="an-bar-col"
              :title="day.dayLabel + ': ' + nf(day.total) + ' кор.'">
              <div class="an-bar-num">{{ nf(day.total) }}</div>
              <div class="an-bar-stack">
                <template v-for="s in data.suppliers" :key="s.supplier">
                  <div v-if="day.bySupplier[s.supplier]" class="an-bar-seg"
                    :style="{ height: barH(day.bySupplier[s.supplier]) + 'px', background: s.color,
                      borderRadius: isTop(day, s.supplier) ? '3px 3px 0 0' : '0' }">
                  </div>
                </template>
              </div>
              <div class="an-bar-label">{{ day.dayLabel }}</div>
            </div>
          </div>
        </div>

        <!-- Suppliers quick -->
        <div class="an-card">
          <div class="an-card-title"><BkIcon name="building" size="sm"/> По поставщикам</div>
          <div class="an-sup-table">
            <div v-for="s in data.suppliers" :key="s.supplier" class="an-sup-row">
              <div class="an-sup-left">
                <span class="an-sup-dot" :style="{ background: s.color }"></span>
                <span class="an-sup-name">{{ s.supplier }}</span>
              </div>
              <div class="an-sup-right">
                <div class="an-sup-bar-wrap">
                  <div class="an-sup-bar" :style="{ width: sPct(s) + '%', background: s.color }"></div>
                </div>
                <span class="an-sup-val">{{ nf(s.boxes) }}</span>
                <span class="an-sup-meta">{{ s.orders }} зак.</span>
              </div>
            </div>
          </div>
        </div>
      </template>

      <!-- ===== PLAN-FACT ===== -->
      <template v-if="activeTab === 'planfact'">
        <div v-if="!data.planFact.receivedOrders" style="text-align:center;padding:40px;color:var(--text-muted);">
          <BkIcon name="success" size="sm"/> Нет принятых доставок за выбранный период
        </div>
        <template v-else>
          <!-- KPI -->
          <div class="an-kpi-grid" style="grid-template-columns: repeat(4, 1fr);">
            <div class="an-kpi">
              <div class="an-kpi-head"><span class="an-kpi-icon"><BkIcon name="success" size="sm"/></span> Принято</div>
              <div class="an-kpi-row"><span class="an-kpi-val">{{ data.planFact.receivedOrders }}</span></div>
              <div class="an-kpi-sub">заказов (ожидают: {{ data.planFact.pendingOrders }})</div>
            </div>
            <div class="an-kpi">
              <div class="an-kpi-head"><span class="an-kpi-icon"><BkIcon name="order" size="sm"/></span> План</div>
              <div class="an-kpi-row"><span class="an-kpi-val">{{ nf(data.planFact.planBoxes) }}</span></div>
              <div class="an-kpi-sub">коробок заказано</div>
            </div>
            <div class="an-kpi">
              <div class="an-kpi-head"><span class="an-kpi-icon"><BkIcon name="order" size="sm"/></span> Факт</div>
              <div class="an-kpi-row"><span class="an-kpi-val">{{ nf(data.planFact.factBoxes) }}</span></div>
              <div class="an-kpi-sub">коробок получено</div>
            </div>
            <div class="an-kpi">
              <div class="an-kpi-head"><span class="an-kpi-icon"><BkIcon name="ruler" size="sm"/></span> Выполнение</div>
              <div class="an-kpi-row">
                <span class="an-kpi-val" :style="{ color: data.planFact.fulfillmentPct >= 95 ? '#2E7D32' : data.planFact.fulfillmentPct >= 80 ? '#E65100' : '#D32F2F' }">{{ data.planFact.fulfillmentPct }}%</span>
              </div>
              <div class="an-kpi-sub">факт / план</div>
            </div>
          </div>

          <!-- Расхождения: прогресс-бар -->
          <div class="an-card">
            <div class="an-card-title"><BkIcon name="warning" size="sm"/> Расхождения</div>
            <div class="an-pf-bar-wrap">
              <div class="an-pf-bar-ok" :style="{ width: (100 - data.planFact.discrepancyPct) + '%' }">
                <span v-if="100 - data.planFact.discrepancyPct > 15">{{ 100 - data.planFact.discrepancyPct }}% ОК</span>
              </div>
              <div class="an-pf-bar-disc" :style="{ width: data.planFact.discrepancyPct + '%' }">
                <span v-if="data.planFact.discrepancyPct > 5">{{ data.planFact.discrepancyPct }}%</span>
              </div>
            </div>
            <div class="an-pf-stats">
              <span>{{ data.planFact.totalReceivedItems - data.planFact.discrepancyItems }} позиций совпали</span>
              <span style="color:#D32F2F;">{{ data.planFact.discrepancyItems }} расхождений</span>
            </div>
            <div v-if="data.planFact.factBoxes < data.planFact.planBoxes" class="an-pf-deficit">
              Недовоз: <b>{{ nf(data.planFact.planBoxes - data.planFact.factBoxes) }} кор.</b>
            </div>
            <div v-else-if="data.planFact.factBoxes > data.planFact.planBoxes" class="an-pf-surplus">
              Перевоз: <b>{{ nf(data.planFact.factBoxes - data.planFact.planBoxes) }} кор.</b>
            </div>
          </div>

          <!-- Тренд выполнения по дням -->
          <div v-if="data.planFact.dayTrend.length > 1" class="an-card">
            <div class="an-card-title"><BkIcon name="calendar" size="sm"/> Тренд выполнения по дням</div>
            <div class="an-pf-trend">
              <div v-for="d in data.planFact.dayTrend" :key="d.date" class="an-pf-trend-col" :title="`${d.label}: план ${d.plan}, факт ${d.fact} (${d.pct}%)`">
                <div class="an-pf-trend-pct" :style="{ color: d.pct >= 95 ? '#2E7D32' : d.pct >= 80 ? '#E65100' : '#D32F2F' }">{{ d.pct }}%</div>
                <div class="an-pf-trend-bars">
                  <div class="an-pf-trend-plan" :style="{ height: pfTrendBarH(d.plan) + 'px' }"></div>
                  <div class="an-pf-trend-fact" :style="{ height: pfTrendBarH(d.fact) + 'px' }"></div>
                </div>
                <div class="an-pf-trend-label">{{ d.label }}</div>
              </div>
            </div>
            <div class="an-pf-trend-legend">
              <span><span class="an-pf-legend-plan"></span> План</span>
              <span><span class="an-pf-legend-fact"></span> Факт</span>
            </div>
          </div>

          <!-- По поставщикам -->
          <div v-if="data.planFact.suppliers.length" class="an-card">
            <div class="an-card-title"><BkIcon name="building" size="sm"/> Выполнение по поставщикам</div>
            <div v-for="s in data.planFact.suppliers" :key="s.supplier" class="an-pf-sup-row">
              <div class="an-pf-sup-left">
                <span class="an-sup-dot" :style="{ background: s.color }"></span>
                <span class="an-pf-sup-name">{{ s.supplier }}</span>
              </div>
              <div class="an-pf-sup-mid">
                <div class="an-pf-sup-bar-bg">
                  <div class="an-pf-sup-bar-fill" :style="{ width: Math.min(s.fulfillmentPct, 100) + '%', background: s.fulfillmentPct >= 95 ? '#4CAF50' : s.fulfillmentPct >= 80 ? '#FF9800' : '#F44336' }"></div>
                </div>
              </div>
              <div class="an-pf-sup-right">
                <span class="an-pf-sup-pct" :style="{ color: s.fulfillmentPct >= 95 ? '#2E7D32' : s.fulfillmentPct >= 80 ? '#E65100' : '#D32F2F' }">{{ s.fulfillmentPct }}%</span>
                <span class="an-pf-sup-detail">{{ nf(s.fact) }}/{{ nf(s.plan) }} кор.</span>
                <span v-if="s.discrepancies > 0" class="an-pf-sup-disc">{{ s.discrepancies }} расх.</span>
              </div>
            </div>
          </div>

          <!-- Топ товаров с расхождениями -->
          <div v-if="data.planFact.discrepancyProducts.length" class="an-card">
            <div class="an-card-title"><BkIcon name="fire" size="sm"/> Товары с расхождениями</div>
            <div v-for="(p, i) in data.planFact.discrepancyProducts" :key="p.sku || p.name" class="an-pf-prod-row">
              <div class="an-pf-prod-rank">{{ i + 1 }}</div>
              <div class="an-pf-prod-info">
                <div class="an-pf-prod-name">{{ p.name || p.sku }}</div>
                <div class="an-pf-prod-meta">{{ p.count }}x расхожд.</div>
              </div>
              <div class="an-pf-prod-nums">
                <span>план: {{ nf(p.plan) }}</span>
                <span>факт: {{ nf(p.fact) }}</span>
              </div>
              <div class="an-pf-prod-delta" :class="p.delta < 0 ? 'neg' : 'pos'">
                {{ p.delta > 0 ? '+' : '' }}{{ nf(p.delta) }} кор.
              </div>
            </div>
          </div>
        </template>
      </template>

      <!-- ===== SUPPLIERS ===== -->
      <template v-if="activeTab === 'suppliers'">
        <div v-for="s in data.suppliers" :key="s.supplier" class="an-card an-sup-card">
          <div class="an-sup-card-head">
            <span class="an-sup-dot-lg" :style="{ background: s.color }"></span>
            <span class="an-sup-card-name">{{ s.supplier }}</span>
            <span v-if="s.daysAgo !== null" class="an-sup-card-ago">{{ s.daysAgo }} дн. назад</span>
          </div>
          <div class="an-sup-metrics">
            <div class="an-sup-metric">
              <div class="an-sup-metric-val">{{ s.orders }}</div>
              <div class="an-sup-metric-label">Заказов</div>
            </div>
            <div class="an-sup-metric">
              <div class="an-sup-metric-val">{{ nf(s.boxes) }}</div>
              <div class="an-sup-metric-label">Коробок</div>
            </div>
            <div class="an-sup-metric">
              <div class="an-sup-metric-val">{{ s.orders ? Math.round(s.boxes / s.orders) : 0 }}</div>
              <div class="an-sup-metric-label">Ср./заказ</div>
            </div>
            <div class="an-sup-metric">
              <div class="an-sup-metric-val" :class="deltaCls(supDelta(s))">
                <template v-if="supDelta(s) !== null">{{ supDelta(s) >= 0 ? '▲' : '▼' }}{{ Math.abs(supDelta(s)) }}%</template>
                <template v-else>—</template>
              </div>
              <div class="an-sup-metric-label">vs прошл.</div>
            </div>
          </div>
        </div>
      </template>

      <!-- ===== PRODUCTS + FORECAST ===== -->
      <template v-if="activeTab === 'products'">
        <div class="an-card" style="padding:0;">
          <div class="an-prod-header">
            <span><BkIcon name="fire" size="sm"/> Топ товаров + прогноз</span>
          </div>
          <div v-for="(p, i) in data.topProducts" :key="p.sku || p.name" class="an-prod-row">
            <div class="an-prod-rank" :class="{ top: i < 3 }">{{ i + 1 }}</div>
            <div class="an-prod-info">
              <div class="an-prod-line1">
                <span class="an-prod-sku">{{ p.sku || '' }}</span>
                <span class="an-prod-name">{{ p.name || '—' }}</span>
              </div>
              <div class="an-prod-progress">
                <div class="an-prod-progress-bar" :style="{ width: pPct(p) + '%' }"></div>
              </div>
            </div>
            <div class="an-prod-stats">
              <div class="an-prod-boxes">{{ nf(p.boxes) }} кор</div>
              <div v-if="p.deltaBoxes !== null" class="an-prod-delta" :class="p.deltaBoxes >= 0 ? 'up' : 'down'">
                {{ p.deltaBoxes >= 0 ? '▲' : '▼' }} {{ Math.abs(p.deltaBoxes) }}%
              </div>
            </div>
            <div class="an-prod-forecast">
              <div class="an-prod-forecast-label">прогноз</div>
              <div class="an-prod-forecast-val">~{{ nf(p.forecast) }}</div>
            </div>
          </div>
        </div>
        <div class="an-forecast-note">
          <BkIcon name="bulb" size="sm"/> Прогноз = средний расход в день × {{ days }} дней
        </div>
      </template>

      <!-- ===== FORECAST ===== -->
      <template v-if="activeTab === 'forecast'">
        <div v-if="forecastLoading" style="text-align:center;padding:60px;">
          <BurgerSpinner text="Загрузка прогноза..." />
        </div>
        <div v-else-if="!forecast" style="text-align:center;padding:60px;color:var(--text-muted);">Нет данных для прогноза</div>
        <template v-else>
          <!-- Контролы -->
          <div class="fc-controls">
            <div class="fc-controls-left">
              <div class="fc-period-btns">
                <button v-for="p in [7, 14, 30]" :key="p" class="fc-period-btn" :class="{ active: forecastPeriod === p }" @click="forecastPeriod = p">{{ p }} дн.</button>
              </div>
              <select v-model="forecastSupplier" class="fc-supplier-select">
                <option value="">Все поставщики</option>
                <option v-for="s in forecast.suppliers" :key="s" :value="s">{{ s }}</option>
              </select>
            </div>
            <button v-if="forecastSupplier" class="btn primary fc-order-btn" @click="createOrderFromForecast" :disabled="!filteredForecast.length">
              <BkIcon name="order" size="sm"/> Заказ для {{ forecastSupplier }}
            </button>
          </div>

          <!-- KPI -->
          <div class="an-kpi-grid" style="grid-template-columns: repeat(4, 1fr);">
            <div class="an-kpi">
              <div class="an-kpi-head"><span class="an-kpi-icon"><BkIcon name="order" size="sm"/></span> Товаров в расчёте</div>
              <div class="an-kpi-row"><span class="an-kpi-val">{{ forecastKpi.totalProducts }}</span></div>
              <div class="an-kpi-sub">которые заказывали за 60 дней</div>
            </div>
            <div class="an-kpi">
              <div class="an-kpi-head"><span class="an-kpi-icon"><BkIcon name="chartUp" size="sm"/></span> Понадобится коробок</div>
              <div class="an-kpi-row"><span class="an-kpi-val">{{ nf(forecastKpi.totalForecast) }}</span><span class="an-kpi-unit">кор.</span></div>
              <div class="an-kpi-sub">ожидаемый расход за {{ forecastPeriod }} дней</div>
            </div>
            <div class="an-kpi">
              <div class="an-kpi-head"><span class="an-kpi-icon"><BkIcon name="warning" size="sm"/></span> Товаров с дефицитом</div>
              <div class="an-kpi-row"><span class="an-kpi-val" style="color:#D32F2F;">{{ forecastKpi.deficitCount }}</span><span class="an-kpi-unit">из {{ forecastKpi.withStockCount }} с остатками</span></div>
              <div class="an-kpi-sub">{{ forecastKpi.criticalCount ? 'критично (на 3 дня и менее): ' + forecastKpi.criticalCount : 'критичных нет' }}{{ forecastKpi.noStockCount ? ' · без данных: ' + forecastKpi.noStockCount : '' }}</div>
            </div>
            <div class="an-kpi">
              <div class="an-kpi-head"><span class="an-kpi-icon"><BkIcon name="chartUp" size="sm"/></span> Тренд заказов</div>
              <div class="an-kpi-row"><span class="an-kpi-val">{{ forecastKpi.trendUp }}</span><span class="an-kpi-unit">растут</span></div>
              <div class="an-kpi-sub">падают: {{ forecastKpi.trendDown }} · стабильно: {{ forecastKpi.trendStable }}</div>
            </div>
          </div>

          <!-- Легенда-пояснение -->
          <div class="fc-legend">
            <div class="fc-legend-item"><span class="fc-legend-dot" style="background:#E8F5E9;border-color:#4CAF50;"></span> <b>Ок</b> — запаса больше чем на 7 дней</div>
            <div class="fc-legend-item"><span class="fc-legend-dot" style="background:#FFF3E0;border-color:#FF9800;"></span> <b>Мало</b> — запаса на 4–7 дней</div>
            <div class="fc-legend-item"><span class="fc-legend-dot" style="background:#FFEBEE;border-color:#F44336;"></span> <b>Критично</b> — запаса на 3 дня и менее</div>
            <div class="fc-legend-item"><span class="fc-legend-dot" style="background:#F5F5F5;border-color:#BDBDBD;"></span> <b>Нет данных</b> — остатки не внесены (на стр. Анализ)</div>
          </div>

          <!-- Таблица -->
          <div class="an-card fc-table-card">
            <div class="fc-table-wrap">
              <table class="fc-table">
                <thead>
                  <tr>
                    <th class="fc-th fc-th-name" @click="toggleForecastSort('name')">Товар{{ sortIcon('name') }}</th>
                    <th class="fc-th fc-th-spark fc-hide-mobile">Динамика за 14 дн.</th>
                    <th class="fc-th fc-th-num" @click="toggleForecastSort('avgPerDay')" title="Среднее потребление коробок в день">Расход/день, кор.{{ sortIcon('avgPerDay') }}</th>
                    <th class="fc-th fc-th-num" @click="toggleForecastSort('forecast')" title="Сколько коробок потребуется за выбранный период">Нужно на {{ forecastPeriod }} дн., кор.{{ sortIcon('forecast') }}</th>
                    <th class="fc-th fc-th-num" @click="toggleForecastSort('stock')" title="Текущий остаток на складе в коробках">На складе, кор.{{ sortIcon('stock') }}</th>
                    <th class="fc-th fc-th-num" @click="toggleForecastSort('daysOfStock')" title="На сколько дней хватит текущего остатка">Хватит на, дн.{{ sortIcon('daysOfStock') }}</th>
                    <th class="fc-th fc-th-status">Запас</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in filteredForecast" :key="item.sku || item.name" class="fc-row" :class="'fc-status-' + item.stockStatus">
                    <td class="fc-td fc-td-name">
                      <div class="fc-item-name">{{ item.name || item.sku }}</div>
                      <div v-if="item.sku" class="fc-item-sku">{{ item.sku }}</div>
                      <div v-if="item.supplier && !forecastSupplier" class="fc-item-supplier">{{ item.supplier }}</div>
                    </td>
                    <td class="fc-td fc-td-spark fc-hide-mobile">
                      <template v-if="item.sparkline.some(v => v > 0)">
                        <svg class="fc-sparkline" viewBox="0 0 60 20" preserveAspectRatio="none">
                          <path :d="sparklinePath(item.sparkline, 60, 20)" fill="none" :stroke="sparklineColor(item.trend)" stroke-width="1.5"/>
                        </svg>
                      </template>
                      <span class="fc-trend-label" :class="'fc-trend-' + item.trend">{{ trendLabel(item.trend) }}</span>
                    </td>
                    <td class="fc-td fc-td-num" :class="{ 'fc-no-data': !item.hasConsumptionData }">
                      {{ item.hasConsumptionData ? item.avgPerDay.toFixed(1) : '—' }}
                    </td>
                    <td class="fc-td fc-td-num fc-td-forecast" :class="{ 'fc-no-data': !item.hasConsumptionData }">
                      {{ item.hasConsumptionData ? Math.round(forecastVal(item)) : '—' }}
                    </td>
                    <td class="fc-td fc-td-num" :class="{ 'fc-no-data': item.stock === null }">
                      {{ item.stock !== null ? Math.round(item.stock) : '—' }}
                    </td>
                    <td class="fc-td fc-td-num" :class="item.daysOfStock !== null && item.daysOfStock <= 3 ? 'fc-days-critical' : item.daysOfStock !== null && item.daysOfStock <= 7 ? 'fc-days-warning' : item.daysOfStock === null ? 'fc-no-data' : ''">
                      {{ item.daysOfStock === null ? '—' : item.daysOfStock >= 999 ? '—' : item.daysOfStock }}
                    </td>
                    <td class="fc-td fc-td-status">
                      <span class="fc-status-badge" :class="'fc-badge-' + item.stockStatus">{{ stockStatusLabel(item.stockStatus) }}</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div v-if="!filteredForecast.length" style="text-align:center;padding:30px;color:var(--text-muted);font-size:13px;">
              Нет товаров по выбранному фильтру
            </div>
          </div>

          <div class="fc-note">
            <BkIcon name="bulb" size="sm"/> <b>Откуда данные:</b> расход и остатки — со страницы Анализ.
            Прогноз = дневной расход × количество дней. Динамика и тренд — из истории заказов за 60 дней.
          </div>
        </template>
      </template>

      <!-- ===== ANOMALIES ===== -->
      <template v-if="activeTab === 'anomalies'">
        <div v-if="!data.anomalies.length" style="text-align:center;padding:40px;color:var(--text-muted);">
          <BkIcon name="success" size="sm"/> Аномалий не обнаружено за выбранный период
        </div>
        <div v-for="(a, i) in data.anomalies" :key="i" class="an-anomaly" :class="['sev-' + a.severity, { clickable: a.orderId }]"
          @click="a.orderId ? loadOrder(a.orderId) : null">
          <span class="an-anomaly-icon">{{ a.icon }}</span>
          <div class="an-anomaly-body">
            <div class="an-anomaly-title">{{ a.title }}</div>
            <div class="an-anomaly-text">{{ a.text }}</div>
            <div class="an-anomaly-detail">{{ a.detail }}</div>
          </div>
          <span class="an-anomaly-tag">{{ typeLabel(a.type) }}</span>
          <span v-if="a.orderId" class="an-anomaly-go" title="Открыть заказ">Открыть →</span>
        </div>
      </template>

      <!-- ===== REPORTS ===== -->
      <template v-if="activeTab === 'reports'">
        <div class="rpt-toolbar">
          <button class="btn primary" @click="exportAnalytics" :disabled="!data"><BkIcon name="excel" size="sm"/> Экспорт в Excel</button>
        </div>

        <!-- Топ-10 товаров -->
        <div v-if="data" class="an-card">
          <div class="an-card-title"><BkIcon name="fire" size="sm"/> Топ-10 товаров</div>
          <div v-for="(p, i) in data.topProducts" :key="p.sku || p.name" class="rpt-prod-row">
            <div class="rpt-prod-medal">{{ i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : (i + 1) }}</div>
            <div class="rpt-prod-info">
              <div class="rpt-prod-name">{{ p.name || p.sku }}</div>
              <div class="rpt-prod-bar-wrap">
                <div class="rpt-prod-bar" :style="{ width: pPct(p) + '%' }"></div>
              </div>
            </div>
            <div class="rpt-prod-stats">
              <span class="rpt-prod-boxes">{{ nf(p.boxes) }} кор</span>
              <span v-if="p.deltaBoxes !== null" class="rpt-prod-delta" :class="p.deltaBoxes >= 0 ? 'up' : 'down'">
                {{ p.deltaBoxes >= 0 ? '▲' : '▼' }} {{ Math.abs(p.deltaBoxes) }}%
              </span>
            </div>
            <div class="rpt-prod-forecast">~{{ nf(p.forecast) }}</div>
          </div>
        </div>

        <!-- Топ-5 поставщиков -->
        <div v-if="data" class="an-card">
          <div class="an-card-title"><BkIcon name="building" size="sm"/> Топ-5 поставщиков</div>
          <div v-for="(s, i) in data.suppliers.slice(0, 5)" :key="s.supplier" class="rpt-sup-row">
            <div class="rpt-sup-medal">{{ i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : (i + 1) }}</div>
            <div class="rpt-sup-info">
              <span class="rpt-sup-name">{{ s.supplier }}</span>
              <div class="rpt-sup-bar-wrap">
                <div class="rpt-sup-bar" :style="{ width: sPct(s) + '%', background: s.color }"></div>
              </div>
            </div>
            <div class="rpt-sup-stats">
              <span>{{ nf(s.boxes) }} кор</span>
              <span class="rpt-sup-orders">{{ s.orders }} зак.</span>
            </div>
          </div>
        </div>

        <!-- Сезонность -->
        <div class="an-card">
          <div class="an-card-title"><BkIcon name="calendar" size="sm"/> Сезонность (12 мес.)</div>
          <div v-if="seasonalityLoading" style="text-align:center;padding:30px;color:var(--text-muted);">
            <BurgerSpinner text="Загрузка..." />
          </div>
          <div v-else-if="!seasonality" style="text-align:center;padding:20px;color:var(--text-muted);font-size:13px;">Нет данных</div>
          <template v-else>
            <div class="rpt-season-chart">
              <div v-for="m in seasonality.monthData" :key="m.key" class="rpt-season-col">
                <div class="rpt-season-val">{{ nf(m.boxes) }}</div>
                <div class="rpt-season-bar-area">
                  <div class="rpt-season-bar" :style="{ height: seasonBarH(m.boxes, seasonality.maxBoxes) + 'px' }"></div>
                  <div v-if="m.movingAvg !== null" class="rpt-season-avg-dot" :style="{ bottom: seasonBarH(m.movingAvg, seasonality.maxBoxes) + 'px' }"></div>
                </div>
                <div class="rpt-season-label">{{ m.label }}</div>
              </div>
            </div>
            <div class="rpt-season-legend">
              <span class="rpt-season-legend-item"><span class="rpt-season-legend-bar"></span> Коробок</span>
              <span class="rpt-season-legend-item"><span class="rpt-season-legend-dot"></span> Скольз. среднее (3 мес.)</span>
            </div>
            <!-- YoY table -->
            <div v-if="seasonality.monthData.some(m => m.yoyDelta !== null)" class="rpt-yoy-table">
              <div class="rpt-yoy-title">Год к году</div>
              <div class="rpt-yoy-grid">
                <div v-for="m in seasonality.monthData" :key="m.key + '-yoy'" class="rpt-yoy-cell">
                  <div class="rpt-yoy-label">{{ m.label }}</div>
                  <div v-if="m.yoyDelta !== null" class="rpt-yoy-val" :class="m.yoyDelta >= 0 ? 'up' : 'down'">
                    {{ m.yoyDelta >= 0 ? '+' : '' }}{{ m.yoyDelta }}%
                  </div>
                  <div v-else class="rpt-yoy-val">—</div>
                </div>
              </div>
            </div>
          </template>
        </div>

        <!-- Прогноз расхода -->
        <div v-if="data" class="an-card">
          <div class="an-card-title"><BkIcon name="chartUp" size="sm"/> Прогноз расхода</div>
          <div v-for="p in data.topProducts.slice(0, 5)" :key="'fc-' + (p.sku || p.name)" class="rpt-fc-row">
            <div class="rpt-fc-name">{{ p.name || p.sku }}</div>
            <div class="rpt-fc-bars">
              <div class="rpt-fc-bar-wrap">
                <div class="rpt-fc-bar rpt-fc-fact" :style="{ width: (p.boxes / Math.max(p.boxes, p.forecast, 1)) * 100 + '%' }"></div>
              </div>
              <div class="rpt-fc-bar-wrap">
                <div class="rpt-fc-bar rpt-fc-forecast" :style="{ width: (p.forecast / Math.max(p.boxes, p.forecast, 1)) * 100 + '%' }"></div>
              </div>
            </div>
            <div class="rpt-fc-nums">
              <div>Факт: {{ nf(p.boxes) }}</div>
              <div>Прогноз: {{ nf(p.forecast) }}</div>
            </div>
          </div>
        </div>
      </template>

    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { getOrdersAnalytics, getSeasonalityData, getForecastData } from '@/lib/analytics.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useDraftStore } from '@/stores/draftStore.js';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import { useToastStore } from '@/stores/toastStore.js';
import { db } from '@/lib/apiClient.js';
import BkIcon from '@/components/ui/BkIcon.vue';


const router = useRouter();
const orderStore = useOrderStore();
const draftStore = useDraftStore();
const toast = useToastStore();

const days = ref(30);
const loading = ref(false);
const data = ref(null);
const activeTab = ref('overview');
const seasonality = ref(null);
const seasonalityLoading = ref(false);
const forecast = ref(null);
const forecastLoading = ref(false);
const forecastPeriod = ref(7);
const forecastSupplier = ref('');
const forecastSort = ref({ col: 'default', asc: true });

const formatter = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 });
function nf(v) { return formatter.format(v || 0); }

// Chart: only days with orders (skip zero days)
const chartDays = computed(() => {
  if (!data.value) return [];
  return data.value.days.filter(d => d.total > 0);
});
const maxTotal = computed(() => chartDays.value.length ? Math.max(...chartDays.value.map(d => d.total), 1) : 1);

const criticalAnomalies = computed(() => data.value ? data.value.anomalies.filter(a => a.severity === 'danger') : []);

const tabs = [
  { id: 'overview', label: 'Обзор' },
  { id: 'planfact', label: 'Поставки' },
  { id: 'suppliers', label: 'Поставщики' },
  { id: 'products', label: 'Товары' },
  { id: 'forecast', label: 'Прогноз' },
  { id: 'anomalies', label: 'Аномалии' },
  { id: 'reports', label: 'Отчёты' },
];

function barH(boxes) { return Math.max(Math.round((boxes / maxTotal.value) * 110), 4); }
function isTop(day, sup) {
  let last = null;
  for (const s of data.value.suppliers) { if (day.bySupplier[s.supplier]) last = s.supplier; }
  return last === sup;
}
function sPct(s) { return data.value.totals.boxes > 0 ? (s.boxes / data.value.totals.boxes * 100) : 0; }
function pPct(p) { return data.value.topProducts[0]?.boxes ? (p.boxes / data.value.topProducts[0].boxes * 100) : 0; }
function supDelta(s) { return s.prevBoxes > 0 ? Math.round((s.boxes - s.prevBoxes) / s.prevBoxes * 100) : null; }
function deltaCls(d) { return d === null ? '' : d >= 0 ? 'val-up' : 'val-down'; }
function typeLabel(t) {
  return { spike: 'Рост', drop: 'Падение', supplier: 'Поставщик', outlier: 'Выброс' }[t] || t;
}

async function loadOrder(orderId) {
  const { data: order, error } = await db
    .from('orders').select('*, order_items(*)').eq('id', orderId).single();
  if (error || !order) { toast.error('Ошибка', 'Не удалось загрузить заказ'); return; }
  await orderStore.loadOrderIntoForm(order, orderStore.settings.legalEntity, false, true);
  draftStore.saveNow();
  router.push({ name: 'order' });
  toast.success('Заказ загружен', 'Режим просмотра');
}

async function load() {
  loading.value = true;
  try {
    data.value = await getOrdersAnalytics(orderStore.settings.legalEntity, days.value);
  } catch (e) {
    toast.error('Ошибка', 'Не удалось загрузить аналитику');
    data.value = null;
  } finally {
    loading.value = false;
  }
}

// Lazy-load сезонности и прогноза при переключении на табы
watch(activeTab, async (tab) => {
  if (tab === 'reports' && !seasonality.value && !seasonalityLoading.value) {
    seasonalityLoading.value = true;
    try {
      seasonality.value = await getSeasonalityData(orderStore.settings.legalEntity);
    } catch (e) {
      seasonality.value = null;
    } finally {
      seasonalityLoading.value = false;
    }
  }
  if (tab === 'forecast' && !forecast.value && !forecastLoading.value) {
    await loadForecast();
  }
});

async function loadForecast() {
  forecastLoading.value = true;
  try {
    forecast.value = await getForecastData(orderStore.settings.legalEntity);
  } catch (e) {
    toast.error('Ошибка', 'Не удалось загрузить прогноз');
    forecast.value = null;
  } finally {
    forecastLoading.value = false;
  }
}

// Фильтрованный и отсортированный список прогноза
const filteredForecast = computed(() => {
  if (!forecast.value) return [];
  let items = forecast.value.items;
  if (forecastSupplier.value) {
    items = items.filter(i => i.supplier === forecastSupplier.value);
  }
  const sort = forecastSort.value;
  if (sort.col !== 'default') {
    items = [...items].sort((a, b) => {
      let va, vb;
      if (sort.col === 'name') { va = (a.name || a.sku || '').toLowerCase(); vb = (b.name || b.sku || '').toLowerCase(); return sort.asc ? va.localeCompare(vb) : vb.localeCompare(va); }
      if (sort.col === 'forecast') { va = forecastVal(a); vb = forecastVal(b); }
      else if (sort.col === 'avgPerDay') { va = a.avgPerDay; vb = b.avgPerDay; }
      else if (sort.col === 'stock') { va = a.stock ?? -1; vb = b.stock ?? -1; }
      else if (sort.col === 'daysOfStock') { va = a.daysOfStock ?? 9999; vb = b.daysOfStock ?? 9999; }
      else { va = 0; vb = 0; }
      return sort.asc ? va - vb : vb - va;
    });
  }
  return items;
});

function forecastVal(item) {
  if (forecastPeriod.value === 14) return item.forecast14;
  if (forecastPeriod.value === 30) return item.forecast30;
  return item.forecast7;
}

// KPI пересчитываются по отфильтрованному списку (с учётом выбранного поставщика)
const forecastKpi = computed(() => {
  const items = filteredForecast.value;
  const withStock = items.filter(i => i.stockStatus !== 'unknown');
  const deficit = withStock.filter(i => i.stockStatus === 'critical' || i.stockStatus === 'warning');
  const totalForecast = items.reduce((s, i) => s + forecastVal(i), 0);
  return {
    totalProducts: items.length,
    withStockCount: withStock.length,
    noStockCount: items.length - withStock.length,
    deficitCount: deficit.length,
    criticalCount: items.filter(i => i.stockStatus === 'critical').length,
    totalForecast: Math.round(totalForecast),
    trendUp: items.filter(i => i.trend === 'up').length,
    trendDown: items.filter(i => i.trend === 'down').length,
    trendStable: items.filter(i => i.trend === 'stable').length,
  };
});

function toggleForecastSort(col) {
  if (forecastSort.value.col === col) {
    forecastSort.value.asc = !forecastSort.value.asc;
  } else {
    forecastSort.value = { col, asc: col === 'name' };
  }
}

function sortIcon(col) {
  if (forecastSort.value.col !== col) return '';
  return forecastSort.value.asc ? ' ▲' : ' ▼';
}

function sparklinePath(data, w, h) {
  if (!data || data.length < 2) return '';
  const max = Math.max(...data, 0.1);
  const step = w / (data.length - 1);
  return data.map((v, i) => {
    const x = Math.round(i * step * 10) / 10;
    const y = Math.round((h - (v / max) * h) * 10) / 10;
    return (i === 0 ? 'M' : 'L') + x + ',' + y;
  }).join(' ');
}

function sparklineColor(trend) {
  if (trend === 'up') return '#4CAF50';
  if (trend === 'down') return '#F44336';
  return '#9E9E9E';
}

function stockStatusLabel(status) {
  if (status === 'critical') return 'Критично';
  if (status === 'warning') return 'Мало';
  if (status === 'unknown') return 'Нет данных';
  return 'Ок';
}

function trendLabel(trend) {
  if (trend === 'up') return '▲ Растёт';
  if (trend === 'down') return '▼ Падает';
  return '— Стабильно';
}

async function createOrderFromForecast() {
  if (!forecast.value || !forecastSupplier.value) return;
  const items = filteredForecast.value;
  if (!items.length) return;
  orderStore.resetOrder();
  orderStore.settings.supplier = forecastSupplier.value;
  let count = 0;
  for (const item of items) {
    const added = orderStore.addItem({
      sku: item.sku,
      name: item.name,
      qty_per_box: item.qtyPerBox,
    });
    if (added) count++;
  }
  draftStore.saveNow();
  router.push({ name: 'order' });
  toast.success('Заказ создан', `${count} поз. для ${forecastSupplier.value}`);
}

function seasonBarH(boxes, maxBoxes) {
  return Math.max(Math.round((boxes / maxBoxes) * 120), 4);
}

const pfTrendMax = computed(() => {
  if (!data.value?.planFact?.dayTrend?.length) return 1;
  return Math.max(...data.value.planFact.dayTrend.map(d => Math.max(d.plan, d.fact)), 1);
});
function pfTrendBarH(v) { return Math.max(Math.round((v / pfTrendMax.value) * 80), 2); }

async function exportAnalytics() {
  const { exportAnalyticsToExcel } = await import('@/lib/excelExport.js');
  exportAnalyticsToExcel(data.value, seasonality.value);
}

watch(() => orderStore.settings.legalEntity, () => {
  seasonality.value = null;
  forecast.value = null;
  load();
});
onMounted(() => load());
</script>

<style scoped>
.analytics-view { padding: 0; display: flex; flex-direction: column; }

/* Header */
.an-header {
  display: flex; align-items: center; justify-content: space-between;
  flex-shrink: 0; margin-bottom: 8px;
}
.an-period {
  padding: 5px 10px; border-radius: 6px; border: 1px solid var(--border);
  font-size: 12px; font-weight: 600; background: var(--card); color: var(--text);
}

/* Alert banner */
.an-alert-banner {
  padding: 7px 14px; background: #FFF3E0; border: 1px solid #FFCC80;
  border-radius: 8px; font-size: 12px; color: #E65100; cursor: pointer;
  display: flex; align-items: center; gap: 8px; flex-shrink: 0; margin-bottom: 8px;
}
.an-alert-link { margin-left: auto; font-weight: 600; }

/* Tabs */
.an-tabs {
  display: flex; gap: 0; border-bottom: 2px solid var(--border-light);
  margin-bottom: 12px; flex-shrink: 0;
}
.an-tab {
  padding: 7px 14px; font-size: 12px; font-weight: 600; border: none; cursor: pointer;
  border-bottom: 2px solid transparent; margin-bottom: -2px; background: none;
  color: var(--text-muted); transition: all 0.1s; display: flex; align-items: center; gap: 4px;
}
.an-tab.active { color: var(--text); border-bottom-color: var(--text); }
.an-tab:hover { color: var(--text); }
.an-tab-badge {
  font-size: 10px; font-weight: 700; background: #F44336; color: #fff;
  padding: 0 5px; border-radius: 8px; line-height: 16px;
}

/* Content scroll area */
.an-content { flex: 1; overflow-y: auto; min-height: 0; }

/* KPI grid */
.an-kpi-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 12px; }
.an-kpi {
  background: var(--card); border: 1px solid var(--border-light); border-radius: 10px; padding: 12px 14px;
}
.an-kpi-head { font-size: 11px; color: var(--text-muted); font-weight: 600; }
.an-kpi-icon { margin-right: 2px; }
.an-kpi-row { display: flex; align-items: baseline; gap: 6px; margin-top: 2px; }
.an-kpi-val { font-size: 24px; font-weight: 800; color: var(--text); }
.an-kpi-sub { font-size: 10px; color: var(--text-muted); margin-top: 1px; }

.an-badge {
  font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 8px;
}
.an-badge.up { background: #E8F5E9; color: #2E7D32; }
.an-badge.down { background: #FFEBEE; color: #C62828; }

/* Cards */
.an-card {
  background: var(--card); border: 1px solid var(--border-light); border-radius: 10px;
  padding: 14px; margin-bottom: 12px;
}
.an-card-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 10px; flex-wrap: wrap; gap: 6px;
}
.an-card-title { font-size: 14px; font-weight: 700; color: var(--text); }

/* Legend */
.an-legend { display: flex; flex-wrap: wrap; gap: 8px; }
.an-legend-item { display: flex; align-items: center; gap: 3px; font-size: 10px; color: var(--text); }
.an-legend-dot { width: 8px; height: 8px; border-radius: 2px; flex-shrink: 0; }

/* Chart */
.an-chart {
  display: flex; align-items: flex-end; gap: 4px; height: 160px;
  overflow-x: auto; overflow-y: visible; padding-bottom: 24px;
}
.an-bar-col {
  flex: 1; min-width: 28px; max-width: 70px;
  display: flex; flex-direction: column; align-items: center;
}
.an-bar-num { font-size: 9px; font-weight: 700; color: var(--text); margin-bottom: 2px; white-space: nowrap; }
.an-bar-stack {
  width: 85%; display: flex; flex-direction: column; justify-content: flex-end; height: 120px;
}
.an-bar-seg { width: 100%; flex-shrink: 0; }
.an-bar-label {
  font-size: 9px; color: var(--text-muted); margin-top: 3px; border-top: 1px solid var(--border-light); padding-top: 2px;
  white-space: nowrap; text-align: center;
}

/* Supplier rows (overview) — bar aligned from right */
.an-sup-table { display: flex; flex-direction: column; }
.an-sup-row {
  display: flex; align-items: center; gap: 10px; padding: 5px 0;
  border-bottom: 1px solid var(--border-light);
}
.an-sup-row:last-child { border-bottom: none; }
.an-sup-left { display: flex; align-items: center; gap: 6px; flex: 1; min-width: 0; }
.an-sup-dot { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
.an-sup-name { font-size: 12px; font-weight: 600; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.an-sup-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; width: 70%; }
.an-sup-bar-wrap { flex: 1; height: 12px; background: var(--border-light); border-radius: 6px; overflow: hidden; }
.an-sup-bar { height: 100%; border-radius: 6px; transition: width 0.4s; }
.an-sup-val { font-size: 11px; font-weight: 700; color: var(--text); min-width: 50px; text-align: right; }
.an-sup-meta { font-size: 10px; color: var(--text-muted); min-width: 45px; text-align: right; }

/* Supplier cards (tab) */
.an-sup-card { padding: 14px 16px; }
.an-sup-card-head { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.an-sup-dot-lg { width: 14px; height: 14px; border-radius: 4px; flex-shrink: 0; }
.an-sup-card-name { font-size: 15px; font-weight: 700; color: var(--text); flex: 1; }
.an-sup-card-ago { font-size: 11px; color: var(--text-muted); }

.an-sup-metrics { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
.an-sup-metric { background: var(--bg); padding: 8px 6px; border-radius: 6px; text-align: center; }
.an-sup-metric-val { font-size: 18px; font-weight: 800; color: var(--text); }
.an-sup-metric-val.val-up { color: #2E7D32; font-size: 14px; }
.an-sup-metric-val.val-down { color: #C62828; font-size: 14px; }
.an-sup-metric-label { font-size: 9px; color: var(--text-muted); font-weight: 600; }

/* Products */
.an-prod-header {
  padding: 10px 14px; border-bottom: 1px solid var(--border-light);
  font-size: 14px; font-weight: 700; color: var(--text);
}
.an-prod-row {
  display: flex; align-items: center; gap: 10px; padding: 9px 14px;
  border-bottom: 1px solid var(--border-light);
}
.an-prod-row:last-child { border-bottom: none; }
.an-prod-rank {
  width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
  font-size: 10px; font-weight: 700; flex-shrink: 0; background: var(--border); color: var(--text);
}
.an-prod-rank.top { background: #F5A623; color: #fff; font-size: 12px; }
.an-prod-info { flex: 1; min-width: 0; }
.an-prod-line1 { display: flex; align-items: baseline; gap: 5px; }
.an-prod-sku { font-size: 10px; font-weight: 700; color: #F5A623; }
.an-prod-name { font-size: 12px; font-weight: 600; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.an-prod-progress { height: 4px; background: var(--border-light); border-radius: 2px; overflow: hidden; margin-top: 3px; }
.an-prod-progress-bar { height: 100%; background: linear-gradient(90deg, #4CAF50, #81C784); border-radius: 2px; transition: width 0.4s; }
.an-prod-stats { text-align: right; min-width: 65px; flex-shrink: 0; }
.an-prod-boxes { font-size: 13px; font-weight: 700; color: var(--text); }
.an-prod-delta { font-size: 10px; font-weight: 700; }
.an-prod-delta.up { color: #2E7D32; }
.an-prod-delta.down { color: #C62828; }
.an-prod-forecast { border-left: 1px solid var(--border-light); padding-left: 10px; min-width: 60px; text-align: right; flex-shrink: 0; }
.an-prod-forecast-label { font-size: 9px; color: var(--text-muted); }
.an-prod-forecast-val { font-size: 14px; font-weight: 700; color: #2196F3; }

.an-forecast-note {
  margin-top: 4px; padding: 8px 12px; background: #E3F2FD; border-radius: 8px;
  border: 1px solid #90CAF9; font-size: 12px; color: #1565C0;
}

/* Anomalies */
.an-anomaly {
  display: flex; align-items: flex-start; gap: 10px; padding: 10px 14px;
  margin-bottom: 6px; border-radius: 8px;
}
.an-anomaly.sev-danger { background: #FFF5F5; border: 1px solid #FFCDD2; }
.an-anomaly.sev-warning { background: #FFFCF0; border: 1px solid #FFE082; }
.an-anomaly.sev-info { background: #FAFAFA; border: 1px solid #E0E0E0; }
.an-anomaly-icon { font-size: 18px; flex-shrink: 0; margin-top: 1px; }
.an-anomaly-body { flex: 1; }
.an-anomaly-title { font-size: 13px; font-weight: 700; color: var(--text); }
.an-anomaly-text { font-size: 12px; color: var(--text); margin-top: 1px; }
.an-anomaly-detail { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
.an-anomaly-tag {
  font-size: 9px; font-weight: 700; padding: 2px 8px; border-radius: 4px;
  flex-shrink: 0; white-space: nowrap;
}
.sev-danger .an-anomaly-tag { background: #FFCDD2; color: #B71C1C; }
.sev-warning .an-anomaly-tag { background: #FFE082; color: #BF360C; }
.sev-info .an-anomaly-tag { background: #E0E0E0; color: #424242; }

.an-anomaly.clickable { cursor: pointer; }
.an-anomaly.clickable:hover { opacity: 0.85; }
.an-anomaly-go {
  font-size: 11px; font-weight: 700; color: #1565C0; flex-shrink: 0; align-self: center;
  white-space: nowrap; padding: 3px 8px; background: #E3F2FD; border-radius: 4px;
}

/* ===== REPORTS TAB ===== */
.rpt-toolbar {
  display: flex; justify-content: flex-end; margin-bottom: 12px;
}

/* Top products in reports */
.rpt-prod-row {
  display: flex; align-items: center; gap: 10px; padding: 8px 0;
  border-bottom: 1px solid var(--border-light);
}
.rpt-prod-row:last-child { border-bottom: none; }
.rpt-prod-medal { width: 28px; text-align: center; font-size: 16px; flex-shrink: 0; }
.rpt-prod-info { flex: 1; min-width: 0; }
.rpt-prod-name { font-size: 12px; font-weight: 600; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rpt-prod-bar-wrap { height: 4px; background: var(--border-light); border-radius: 2px; overflow: hidden; margin-top: 4px; }
.rpt-prod-bar { height: 100%; background: linear-gradient(90deg, #4CAF50, #81C784); border-radius: 2px; transition: width 0.4s; }
.rpt-prod-stats { text-align: right; min-width: 80px; flex-shrink: 0; }
.rpt-prod-boxes { font-size: 13px; font-weight: 700; color: var(--text); }
.rpt-prod-delta { font-size: 10px; font-weight: 700; margin-left: 4px; }
.rpt-prod-delta.up { color: #2E7D32; }
.rpt-prod-delta.down { color: #C62828; }
.rpt-prod-forecast { font-size: 12px; font-weight: 700; color: #2196F3; min-width: 50px; text-align: right; flex-shrink: 0; }

/* Top suppliers in reports */
.rpt-sup-row {
  display: flex; align-items: center; gap: 10px; padding: 8px 0;
  border-bottom: 1px solid var(--border-light);
}
.rpt-sup-row:last-child { border-bottom: none; }
.rpt-sup-medal { width: 28px; text-align: center; font-size: 16px; flex-shrink: 0; }
.rpt-sup-info { flex: 1; min-width: 0; }
.rpt-sup-name { font-size: 13px; font-weight: 600; color: var(--text); }
.rpt-sup-bar-wrap { height: 10px; background: var(--border-light); border-radius: 5px; overflow: hidden; margin-top: 4px; }
.rpt-sup-bar { height: 100%; border-radius: 5px; transition: width 0.4s; }
.rpt-sup-stats { text-align: right; min-width: 90px; flex-shrink: 0; font-size: 12px; font-weight: 600; color: var(--text); }
.rpt-sup-orders { color: var(--text-muted); margin-left: 6px; }

/* Seasonality chart */
.rpt-season-chart {
  display: flex; align-items: flex-end; gap: 6px; height: 180px;
  overflow-x: auto; padding-bottom: 28px; margin-top: 12px;
}
.rpt-season-col {
  flex: 1; min-width: 40px; max-width: 80px;
  display: flex; flex-direction: column; align-items: center;
}
.rpt-season-val { font-size: 9px; font-weight: 700; color: var(--text); margin-bottom: 2px; white-space: nowrap; }
.rpt-season-bar-area { width: 70%; height: 130px; display: flex; flex-direction: column; justify-content: flex-end; position: relative; }
.rpt-season-bar { width: 100%; background: linear-gradient(to top, #F5A623, #ffb366); border-radius: 3px 3px 0 0; transition: height 0.4s; }
.rpt-season-avg-dot {
  position: absolute; left: 50%; transform: translateX(-50%);
  width: 8px; height: 8px; border-radius: 50%;
  background: #D62300; border: 2px solid var(--card);
  z-index: 2;
}
.rpt-season-label {
  font-size: 9px; color: var(--text-muted); margin-top: 3px;
  border-top: 1px solid var(--border-light); padding-top: 2px;
  white-space: nowrap; text-align: center;
}
.rpt-season-legend {
  display: flex; gap: 16px; margin-top: 8px; font-size: 11px; color: var(--text-muted);
}
.rpt-season-legend-item { display: flex; align-items: center; gap: 4px; }
.rpt-season-legend-bar { width: 16px; height: 6px; border-radius: 2px; background: linear-gradient(90deg, #F5A623, #ffb366); }
.rpt-season-legend-dot { width: 8px; height: 8px; border-radius: 50%; background: #D62300; }

/* YoY table */
.rpt-yoy-table { margin-top: 14px; }
.rpt-yoy-title { font-size: 12px; font-weight: 700; color: var(--text); margin-bottom: 6px; }
.rpt-yoy-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(70px, 1fr)); gap: 4px;
}
.rpt-yoy-cell {
  padding: 6px; background: var(--bg); border-radius: 6px; text-align: center;
}
.rpt-yoy-label { font-size: 9px; color: var(--text-muted); font-weight: 600; }
.rpt-yoy-val { font-size: 12px; font-weight: 700; color: var(--text-muted); margin-top: 2px; }
.rpt-yoy-val.up { color: #2E7D32; }
.rpt-yoy-val.down { color: #C62828; }

/* Forecast */
.rpt-fc-row {
  display: flex; align-items: center; gap: 12px; padding: 8px 0;
  border-bottom: 1px solid var(--border-light);
}
.rpt-fc-row:last-child { border-bottom: none; }
.rpt-fc-name { font-size: 12px; font-weight: 600; color: var(--text); min-width: 120px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rpt-fc-bars { flex: 1; display: flex; flex-direction: column; gap: 3px; }
.rpt-fc-bar-wrap { height: 8px; background: var(--border-light); border-radius: 4px; overflow: hidden; }
.rpt-fc-bar { height: 100%; border-radius: 4px; transition: width 0.4s; }
.rpt-fc-fact { background: #4CAF50; }
.rpt-fc-forecast { background: #2196F3; opacity: 0.6; }
.rpt-fc-nums { font-size: 10px; color: var(--text-muted); min-width: 80px; text-align: right; flex-shrink: 0; line-height: 1.6; }

@media (max-width: 768px) {
  .an-kpi-grid { grid-template-columns: repeat(2, 1fr); }
  .an-tabs { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  .an-tab { padding: 7px 10px; font-size: 11px; white-space: nowrap; }
}
@media (max-width: 480px) {
  .an-tab { padding: 6px 8px; font-size: 10px; }
  .an-kpi-grid { grid-template-columns: 1fr; }
  .an-sup-metrics { grid-template-columns: repeat(2, 1fr); }
  .rpt-yoy-grid { grid-template-columns: repeat(4, 1fr); }
  .rpt-fc-name { min-width: 80px; }
}

/* ═══ Plan-Fact analytics ═══ */
.an-pf-bar-wrap {
  display: flex; height: 28px; border-radius: 6px; overflow: hidden;
  border: 1px solid var(--border-light, #eee); margin-bottom: 10px;
}
.an-pf-bar-ok {
  background: #E8F5E9; display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 700; color: #2E7D32; transition: width 0.3s;
}
.an-pf-bar-disc {
  background: #FFEBEE; display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 700; color: #D32F2F; transition: width 0.3s;
}
.an-pf-stats {
  display: flex; justify-content: space-between; font-size: 12px; color: var(--text-secondary);
}
.an-pf-deficit {
  margin-top: 10px; padding: 8px 12px; background: #FFF3E0; border-radius: 6px;
  font-size: 13px; color: #E65100;
}
.an-pf-surplus {
  margin-top: 10px; padding: 8px 12px; background: #E3F2FD; border-radius: 6px;
  font-size: 13px; color: #1565C0;
}

/* ═══ Plan-Fact: Trend by day ═══ */
.an-pf-trend {
  display: flex; align-items: flex-end; gap: 6px; height: 140px;
  overflow-x: auto; padding-bottom: 24px; margin-top: 8px;
}
.an-pf-trend-col {
  flex: 1; min-width: 44px; max-width: 70px;
  display: flex; flex-direction: column; align-items: center;
}
.an-pf-trend-pct { font-size: 9px; font-weight: 700; margin-bottom: 2px; }
.an-pf-trend-bars {
  display: flex; gap: 2px; width: 80%; height: 90px;
  align-items: flex-end; justify-content: center;
}
.an-pf-trend-plan {
  width: 45%; background: #E0E0E0; border-radius: 2px 2px 0 0; transition: height 0.3s;
}
.an-pf-trend-fact {
  width: 45%; background: #4CAF50; border-radius: 2px 2px 0 0; transition: height 0.3s;
}
.an-pf-trend-label {
  font-size: 9px; color: var(--text-muted); margin-top: 3px;
  border-top: 1px solid var(--border-light); padding-top: 2px;
  white-space: nowrap; text-align: center;
}
.an-pf-trend-legend {
  display: flex; gap: 16px; margin-top: 6px; font-size: 11px; color: var(--text-muted);
}
.an-pf-trend-legend span { display: flex; align-items: center; gap: 4px; }
.an-pf-legend-plan { width: 12px; height: 6px; border-radius: 2px; background: #E0E0E0; }
.an-pf-legend-fact { width: 12px; height: 6px; border-radius: 2px; background: #4CAF50; }

/* ═══ Plan-Fact: Suppliers ═══ */
.an-pf-sup-row {
  display: flex; align-items: center; gap: 10px; padding: 7px 0;
  border-bottom: 1px solid var(--border-light);
}
.an-pf-sup-row:last-child { border-bottom: none; }
.an-pf-sup-left { display: flex; align-items: center; gap: 6px; min-width: 120px; flex-shrink: 0; }
.an-pf-sup-name { font-size: 12px; font-weight: 600; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 120px; }
.an-pf-sup-mid { flex: 1; }
.an-pf-sup-bar-bg { height: 14px; background: var(--border-light, #eee); border-radius: 7px; overflow: hidden; }
.an-pf-sup-bar-fill { height: 100%; border-radius: 7px; transition: width 0.4s; }
.an-pf-sup-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.an-pf-sup-pct { font-size: 13px; font-weight: 800; min-width: 38px; text-align: right; }
.an-pf-sup-detail { font-size: 10px; color: var(--text-muted); white-space: nowrap; }
.an-pf-sup-disc { font-size: 10px; font-weight: 700; color: #E65100; background: #FFF3E0; padding: 1px 5px; border-radius: 4px; }

/* ═══ Plan-Fact: Products with discrepancies ═══ */
.an-pf-prod-row {
  display: flex; align-items: center; gap: 10px; padding: 8px 0;
  border-bottom: 1px solid var(--border-light);
}
.an-pf-prod-row:last-child { border-bottom: none; }
.an-pf-prod-rank {
  width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
  font-size: 10px; font-weight: 700; flex-shrink: 0; background: #FFF3E0; color: #E65100;
}
.an-pf-prod-info { flex: 1; min-width: 0; }
.an-pf-prod-name { font-size: 12px; font-weight: 600; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.an-pf-prod-meta { font-size: 10px; color: var(--text-muted); }
.an-pf-prod-nums { font-size: 10px; color: var(--text-muted); text-align: right; line-height: 1.5; flex-shrink: 0; }
.an-pf-prod-delta { font-size: 13px; font-weight: 800; min-width: 70px; text-align: right; flex-shrink: 0; }
.an-pf-prod-delta.neg { color: #D32F2F; }
.an-pf-prod-delta.pos { color: #E65100; }

/* ═══ FORECAST TAB ═══ */
.fc-controls {
  display: flex; align-items: center; justify-content: space-between;
  gap: 10px; margin-bottom: 12px; flex-wrap: wrap;
}
.fc-controls-left { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.fc-period-btns { display: flex; gap: 0; }
.fc-period-btn {
  padding: 5px 12px; font-size: 12px; font-weight: 600; border: 1px solid var(--border);
  background: var(--card); color: var(--text-muted); cursor: pointer; transition: all 0.15s;
}
.fc-period-btn:first-child { border-radius: 6px 0 0 6px; }
.fc-period-btn:last-child { border-radius: 0 6px 6px 0; }
.fc-period-btn:not(:first-child) { border-left: none; }
.fc-period-btn.active { background: var(--text); color: var(--card); border-color: var(--text); }
.fc-supplier-select {
  padding: 5px 10px; border-radius: 6px; border: 1px solid var(--border);
  font-size: 12px; font-weight: 600; background: var(--card); color: var(--text);
  max-width: 200px;
}
.fc-order-btn { white-space: nowrap; font-size: 12px; }

.fc-legend {
  display: flex; gap: 16px; margin-bottom: 12px; flex-wrap: wrap;
  padding: 8px 12px; background: var(--card); border: 1px solid var(--border-light);
  border-radius: 8px; font-size: 12px; color: var(--text);
}
.fc-legend-item { display: flex; align-items: center; gap: 6px; }
.fc-legend-dot {
  width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0;
  border: 1.5px solid;
}
.an-kpi-unit { font-size: 12px; color: var(--text-muted); font-weight: 600; }

.fc-table-card { padding: 0; overflow: hidden; }
.fc-table-wrap { overflow-x: auto; }
.fc-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.fc-th {
  padding: 8px 10px; font-weight: 700; font-size: 11px; color: var(--text-muted);
  border-bottom: 2px solid var(--border-light); text-align: left; cursor: pointer;
  white-space: nowrap; user-select: none;
}
.fc-th:hover { color: var(--text); }
.fc-th-name { min-width: 140px; }
.fc-th-spark { min-width: 100px; }
.fc-th-num { text-align: right; min-width: 70px; }
.fc-th-status { text-align: center; min-width: 70px; cursor: default; }

.fc-row { border-bottom: 1px solid var(--border-light); transition: background 0.1s; }
.fc-row:hover { background: rgba(0,0,0,0.02); }
.fc-row:last-child { border-bottom: none; }
.fc-status-critical { background: #FFF5F5; }
.fc-status-critical:hover { background: #FFEBEE; }
.fc-status-warning { background: #FFFCF0; }
.fc-status-warning:hover { background: #FFF8E1; }

.fc-td { padding: 8px 10px; vertical-align: middle; }
.fc-td-name { }
.fc-item-name { font-weight: 600; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 200px; }
.fc-item-sku { font-size: 10px; color: #F5A623; font-weight: 700; }
.fc-item-supplier { font-size: 10px; color: var(--text-muted); }
.fc-td-spark { }
.fc-sparkline { width: 60px; height: 20px; display: block; }
.fc-trend-label { font-size: 10px; font-weight: 600; white-space: nowrap; }
.fc-trend-up { color: #4CAF50; }
.fc-trend-down { color: #F44336; }
.fc-trend-stable { color: #9E9E9E; }
.fc-td-num { text-align: right; font-weight: 600; color: var(--text); font-variant-numeric: tabular-nums; }
.fc-td-forecast { color: #2196F3; font-weight: 700; }
.fc-days-critical { color: #D32F2F; font-weight: 800; }
.fc-days-warning { color: #E65100; font-weight: 700; }
.fc-td-status { text-align: center; }
.fc-status-badge {
  display: inline-block; padding: 2px 8px; border-radius: 4px;
  font-size: 10px; font-weight: 700;
}
.fc-badge-ok { background: #E8F5E9; color: #2E7D32; }
.fc-badge-warning { background: #FFF3E0; color: #E65100; }
.fc-badge-critical { background: #FFEBEE; color: #D32F2F; }
.fc-badge-unknown { background: #F5F5F5; color: #9E9E9E; }
.fc-no-data { color: var(--text-muted); font-weight: 400; }

.fc-note {
  margin-top: 8px; padding: 8px 12px; background: #E3F2FD; border-radius: 8px;
  border: 1px solid #90CAF9; font-size: 12px; color: #1565C0;
}

@media (max-width: 768px) {
  .fc-controls { flex-direction: column; align-items: stretch; }
  .fc-controls-left { flex-direction: column; }
  .fc-supplier-select { max-width: none; }
  .fc-hide-mobile { display: none; }
  .fc-item-name { max-width: 120px; }
}
</style>
