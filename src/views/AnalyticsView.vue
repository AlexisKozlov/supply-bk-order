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

    <!-- Changes banner (if critical, always visible) -->
    <div v-if="!loading && data && criticalChanges.length && activeTab !== 'changes'" class="an-alert-banner" @click="activeTab = 'changes'">
      <BkIcon name="warning" size="sm"/> {{ criticalChanges.length }} важных изменений
      <span class="an-alert-link">Смотреть</span>
    </div>

    <!-- Tabs -->
    <div v-if="!loading || activeTab === 'abc-xyz'" class="an-tabs">
      <button v-for="t in tabs" :key="t.id" class="an-tab" :class="{ active: activeTab === t.id }" @click="activeTab = t.id">
        <BkIcon :name="t.icon" size="xs"/>
        <span class="an-tab-text">{{ t.label }}</span>
        <span v-if="t.id === 'changes' && data && data.changes.length" class="an-tab-badge">{{ data.changes.length }}</span>
      </button>
    </div>

    <template v-if="activeTab === 'abc-xyz'">
      <div class="an-content">
        <AbcXyzPanel />
      </div>
    </template>
    <template v-else>
    <div v-if="loading" style="text-align:center;padding:60px;">
      <BurgerSpinner text="Загрузка..." />
    </div>
    <div v-else-if="!data" style="text-align:center;padding:60px;color:var(--text-muted);">Нет данных за выбранный период</div>

    <!-- Tab content -->
    <div v-else class="an-content">

      <!-- ===== DASHBOARD (OVERVIEW) ===== -->
      <template v-if="activeTab === 'overview'">
        <!-- Main KPI cards -->
        <div class="dash-kpi-grid">
          <div class="dash-kpi dash-kpi--brown">
            <div class="dash-kpi__left-border"></div>
            <div class="dash-kpi__body">
              <div class="dash-kpi__header">
                <span class="dash-kpi__icon"><BkIcon name="history" size="sm"/></span>
                <span class="dash-kpi__label">Заказов</span>
              </div>
              <div class="dash-kpi__value-row">
                <span class="dash-kpi__value">{{ nf(data.totals.orders) }}</span>
                <span v-if="data.deltaOrders !== null" class="dash-badge" :class="data.deltaOrders >= 0 ? 'dash-badge--up' : 'dash-badge--down'">
                  {{ data.deltaOrders >= 0 ? '+' : '' }}{{ data.deltaOrders }}%
                </span>
              </div>
              <div class="dash-kpi__sub">прошлый период: {{ nf(data.prev.orders) }}</div>
            </div>
          </div>
          <div class="dash-kpi dash-kpi--orange">
            <div class="dash-kpi__left-border"></div>
            <div class="dash-kpi__body">
              <div class="dash-kpi__header">
                <span class="dash-kpi__icon"><BkIcon name="order" size="sm"/></span>
                <span class="dash-kpi__label">Коробок</span>
              </div>
              <div class="dash-kpi__value-row">
                <span class="dash-kpi__value">{{ nf(data.totals.boxes) }}</span>
                <span v-if="data.deltaBoxes !== null" class="dash-badge" :class="data.deltaBoxes >= 0 ? 'dash-badge--up' : 'dash-badge--down'">
                  {{ data.deltaBoxes >= 0 ? '+' : '' }}{{ data.deltaBoxes }}%
                </span>
              </div>
              <div class="dash-kpi__sub">прошлый период: {{ nf(data.prev.boxes) }}</div>
            </div>
          </div>
          <div class="dash-kpi dash-kpi--blue">
            <div class="dash-kpi__left-border"></div>
            <div class="dash-kpi__body">
              <div class="dash-kpi__header">
                <span class="dash-kpi__icon"><BkIcon name="pricing" size="sm"/></span>
                <span class="dash-kpi__label">Закупки, BYN</span>
              </div>
              <div class="dash-kpi__value-row">
                <span class="dash-kpi__value">{{ moneyStats.totalSpend > 0 ? nfMoney(moneyStats.totalSpend) : '---' }}</span>
              </div>
              <div class="dash-kpi__sub">{{ moneyStats.totalSpend > 0 ? 'ср. заказ: ~' + nfMoney(moneyStats.avgOrderCost) + ' BYN' : 'нет данных о ценах' }}</div>
            </div>
          </div>
          <div class="dash-kpi dash-kpi--green" v-if="data.planFact.receivedOrders > 0">
            <div class="dash-kpi__left-border"></div>
            <div class="dash-kpi__body">
              <div class="dash-kpi__header">
                <span class="dash-kpi__icon"><BkIcon name="success" size="sm"/></span>
                <span class="dash-kpi__label">Выполнение</span>
              </div>
              <div class="dash-kpi__value-row">
                <span class="dash-kpi__value" :class="fulfillmentCls(data.planFact.fulfillmentPct)">{{ data.planFact.fulfillmentPct }}%</span>
              </div>
              <div class="dash-kpi__sub">{{ data.planFact.discrepancyItems }} расхожд. из {{ data.planFact.totalReceivedItems }}</div>
            </div>
          </div>
          <!-- Fallback KPI when no fulfillment data -->
          <div class="dash-kpi dash-kpi--green" v-if="!data.planFact.receivedOrders">
            <div class="dash-kpi__left-border"></div>
            <div class="dash-kpi__body">
              <div class="dash-kpi__header">
                <span class="dash-kpi__icon"><BkIcon name="ruler" size="sm"/></span>
                <span class="dash-kpi__label">Ср. кор/заказ</span>
              </div>
              <div class="dash-kpi__value-row">
                <span class="dash-kpi__value">{{ data.totals.orders ? Math.round(data.totals.boxes / data.totals.orders) : 0 }}</span>
              </div>
              <div class="dash-kpi__sub">за период {{ days }} дней</div>
            </div>
          </div>
        </div>

        <!-- Secondary KPI row -->
        <div class="dash-secondary-row">
          <div class="dash-mini-stat">
            <span class="dash-mini-stat__val">{{ uniqueProductsCount }}</span>
            <span class="dash-mini-stat__label">Товаров</span>
          </div>
          <div class="dash-mini-stat">
            <span class="dash-mini-stat__val">{{ data.suppliers.length }}</span>
            <span class="dash-mini-stat__label">Поставщиков</span>
          </div>
          <div class="dash-mini-stat">
            <span class="dash-mini-stat__val">{{ avgBoxesPerSupplier }}</span>
            <span class="dash-mini-stat__label">Ср. кор/пост.</span>
          </div>
          <div class="dash-mini-stat">
            <span class="dash-mini-stat__val">{{ topSupplierConcentration }}%</span>
            <span class="dash-mini-stat__label">Концентрация</span>
          </div>
        </div>

        <!-- Insights panel -->
        <div v-if="insights.length" class="dash-insights">
          <div class="dash-insights__title"><BkIcon name="bulb" size="sm"/> Выводы</div>
          <div class="dash-insights__list">
            <div v-for="(ins, i) in insights" :key="i" class="dash-insight" :class="'dash-insight--' + ins.type">
              <span class="dash-insight__dot"></span>
              <span class="dash-insight__text">{{ ins.text }}</span>
            </div>
          </div>
        </div>

        <!-- Area chart -->
        <div class="dash-card">
          <div class="dash-card__header">
            <span class="dash-card__title"><BkIcon name="calendar" size="sm"/> Коробок по дням</span>
            <div class="dash-legend">
              <span v-for="s in data.suppliers" :key="s.supplier" class="dash-legend__item">
                <span class="dash-legend__dot" :style="{ background: s.color }"></span>{{ s.supplier }}
              </span>
            </div>
          </div>
          <div class="dash-area-chart" v-if="chartDays.length">
            <svg :viewBox="'0 0 ' + areaChartW + ' ' + areaChartH" class="dash-area-svg" preserveAspectRatio="none">
              <!-- Grid lines -->
              <line v-for="g in 4" :key="'grid-'+g" :x1="0" :y1="areaChartH * g / 4" :x2="areaChartW" :y2="areaChartH * g / 4" stroke="var(--border-light)" stroke-width="0.5" stroke-dasharray="4,4"/>
              <!-- Area fill -->
              <path :d="areaPath" fill="url(#areaGrad)" opacity="0.3"/>
              <!-- Line -->
              <path :d="linePath" fill="none" stroke="#FF8732" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
              <!-- Dots -->
              <circle v-for="(pt, i) in areaPoints" :key="'dot-'+i" :cx="pt.x" :cy="pt.y" r="3" fill="#FF8732" stroke="#fff" stroke-width="1.5"/>
              <defs>
                <linearGradient id="areaGrad" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stop-color="#FF8732" stop-opacity="0.5"/>
                  <stop offset="100%" stop-color="#FF8732" stop-opacity="0.02"/>
                </linearGradient>
              </defs>
            </svg>
            <div class="dash-area-labels">
              <span v-for="(day, i) in areaLabelDays" :key="'lbl-'+i" class="dash-area-label">{{ day.dayLabel }}</span>
            </div>
          </div>
          <div v-else style="text-align:center;padding:30px;color:var(--text-muted);font-size:13px;">Нет дней с заказами</div>
        </div>

        <!-- Two-column: Donut + Mini tables -->
        <div class="dash-two-col">
          <!-- Donut chart -->
          <div class="dash-card dash-card--half">
            <div class="dash-card__title"><BkIcon name="building" size="sm"/> Распределение по поставщикам</div>
            <div class="dash-donut-wrap">
              <svg viewBox="0 0 120 120" class="dash-donut-svg">
                <circle v-for="(seg, i) in donutSegments" :key="'donut-'+i"
                  cx="60" cy="60" r="46" fill="none"
                  :stroke="seg.color" stroke-width="18"
                  :stroke-dasharray="seg.dash" :stroke-dashoffset="seg.offset"
                  :transform="'rotate(-90 60 60)'"
                />
                <text x="60" y="56" text-anchor="middle" class="dash-donut-center-val">{{ nf(data.totals.boxes) }}</text>
                <text x="60" y="70" text-anchor="middle" class="dash-donut-center-label">коробок</text>
              </svg>
              <div class="dash-donut-legend">
                <div v-for="s in data.suppliers.slice(0, 5)" :key="'dl-'+s.supplier" class="dash-donut-legend__item">
                  <span class="dash-donut-legend__dot" :style="{ background: s.color }"></span>
                  <span class="dash-donut-legend__name">{{ s.supplier }}</span>
                  <span class="dash-donut-legend__pct">{{ Math.round(sPct(s)) }}%</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Mini tables -->
          <div class="dash-card dash-card--half">
            <div class="dash-card__title"><BkIcon name="fire" size="sm"/> Топ-3 товара</div>
            <div v-for="(p, i) in data.topProducts.slice(0, 3)" :key="'tp-'+i" class="dash-mini-row">
              <span class="dash-mini-row__rank" :class="{ top: i === 0 }">{{ i + 1 }}</span>
              <span class="dash-mini-row__name">{{ p.name || p.sku || '---' }}</span>
              <span class="dash-mini-row__val">{{ nf(p.boxes) }}</span>
              <span v-if="p.deltaBoxes !== null" class="dash-mini-row__delta" :class="p.deltaBoxes >= 0 ? 'up' : 'down'">
                {{ p.deltaBoxes >= 0 ? '+' : '' }}{{ p.deltaBoxes }}%
              </span>
            </div>

            <div class="dash-card__title" style="margin-top:16px;"><BkIcon name="building" size="sm"/> Топ-3 поставщика</div>
            <div v-for="(s, i) in data.suppliers.slice(0, 3)" :key="'ts-'+i" class="dash-mini-row">
              <span class="dash-mini-row__rank" :class="{ top: i === 0 }">{{ i + 1 }}</span>
              <span class="dash-mini-row__name">{{ s.supplier }}</span>
              <span class="dash-mini-row__val">{{ nf(s.boxes) }}</span>
              <span class="dash-mini-row__meta">{{ s.orders }} зак.</span>
            </div>
          </div>
        </div>

        <!-- Suppliers bar chart -->
        <div class="dash-card">
          <div class="dash-card__title"><BkIcon name="building" size="sm"/> По поставщикам</div>
          <div class="dash-sup-table">
            <div v-for="s in data.suppliers" :key="s.supplier" class="dash-sup-row">
              <div class="dash-sup-row__left">
                <span class="dash-sup-row__dot" :style="{ background: s.color }"></span>
                <span class="dash-sup-row__name">{{ s.supplier }}</span>
              </div>
              <div class="dash-sup-row__right">
                <div class="dash-sup-row__bar-wrap">
                  <div class="dash-sup-row__bar" :style="{ width: sPct(s) + '%', background: s.color }"></div>
                </div>
                <span class="dash-sup-row__val">{{ nf(s.boxes) }}</span>
                <span class="dash-sup-row__meta">{{ s.orders }} зак.</span>
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
          <div class="dash-kpi-grid">
            <div class="dash-kpi dash-kpi--blue">
              <div class="dash-kpi__left-border"></div>
              <div class="dash-kpi__body">
                <div class="dash-kpi__header"><span class="dash-kpi__icon"><BkIcon name="success" size="sm"/></span><span class="dash-kpi__label">Принято</span></div>
                <div class="dash-kpi__value-row"><span class="dash-kpi__value">{{ data.planFact.receivedOrders }}</span></div>
                <div class="dash-kpi__sub">заказов (ожидают: {{ data.planFact.pendingOrders }})</div>
              </div>
            </div>
            <div class="dash-kpi dash-kpi--orange">
              <div class="dash-kpi__left-border"></div>
              <div class="dash-kpi__body">
                <div class="dash-kpi__header"><span class="dash-kpi__icon"><BkIcon name="order" size="sm"/></span><span class="dash-kpi__label">План</span></div>
                <div class="dash-kpi__value-row"><span class="dash-kpi__value">{{ nf(data.planFact.planBoxes) }}</span></div>
                <div class="dash-kpi__sub">коробок заказано</div>
              </div>
            </div>
            <div class="dash-kpi dash-kpi--brown">
              <div class="dash-kpi__left-border"></div>
              <div class="dash-kpi__body">
                <div class="dash-kpi__header"><span class="dash-kpi__icon"><BkIcon name="order" size="sm"/></span><span class="dash-kpi__label">Факт</span></div>
                <div class="dash-kpi__value-row"><span class="dash-kpi__value">{{ nf(data.planFact.factBoxes) }}</span></div>
                <div class="dash-kpi__sub">коробок получено</div>
              </div>
            </div>
            <div class="dash-kpi dash-kpi--green">
              <div class="dash-kpi__left-border"></div>
              <div class="dash-kpi__body">
                <div class="dash-kpi__header"><span class="dash-kpi__icon"><BkIcon name="ruler" size="sm"/></span><span class="dash-kpi__label">Выполнение</span></div>
                <div class="dash-kpi__value-row">
                  <span class="dash-kpi__value" :class="fulfillmentCls(data.planFact.fulfillmentPct)">{{ data.planFact.fulfillmentPct }}%</span>
                </div>
                <div class="dash-kpi__sub">факт / план</div>
              </div>
            </div>
          </div>

          <!-- Расхождения -->
          <div class="dash-card">
            <div class="dash-card__title"><BkIcon name="warning" size="sm"/> Расхождения</div>
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
          <div v-if="data.planFact.dayTrend.length > 1" class="dash-card">
            <div class="dash-card__title"><BkIcon name="calendar" size="sm"/> Тренд выполнения по дням</div>
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
          <div v-if="data.planFact.suppliers.length" class="dash-card">
            <div class="dash-card__title"><BkIcon name="building" size="sm"/> Выполнение по поставщикам</div>
            <div v-for="s in data.planFact.suppliers" :key="s.supplier" class="an-pf-sup-row">
              <div class="an-pf-sup-left">
                <span class="dash-sup-row__dot" :style="{ background: s.color }"></span>
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

          <!-- Товары с расхождениями -->
          <div v-if="data.planFact.discrepancyProducts.length" class="dash-card">
            <div class="dash-card__title"><BkIcon name="fire" size="sm"/> Товары с расхождениями</div>
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
        <div v-for="s in data.suppliers" :key="s.supplier" class="dash-card dash-sup-card">
          <div class="dash-sup-card__head">
            <span class="dash-sup-card__dot" :style="{ background: s.color }"></span>
            <span class="dash-sup-card__name">{{ s.supplier }}</span>
            <span v-if="s.daysAgo !== null" class="dash-sup-card__ago">{{ s.daysAgo }} дн. назад</span>
          </div>
          <div class="dash-sup-card__metrics">
            <div class="dash-sup-card__metric">
              <div class="dash-sup-card__metric-val">{{ s.orders }}</div>
              <div class="dash-sup-card__metric-label">Заказов</div>
            </div>
            <div class="dash-sup-card__metric">
              <div class="dash-sup-card__metric-val">{{ nf(s.boxes) }}</div>
              <div class="dash-sup-card__metric-label">Коробок</div>
            </div>
            <div class="dash-sup-card__metric">
              <div class="dash-sup-card__metric-val">{{ s.orders ? Math.round(s.boxes / s.orders) : 0 }}</div>
              <div class="dash-sup-card__metric-label">Ср./заказ</div>
            </div>
            <div class="dash-sup-card__metric">
              <div class="dash-sup-card__metric-val" :class="deltaCls(supDelta(s))">
                <template v-if="supDelta(s) !== null">{{ supDelta(s) >= 0 ? '+' : '' }}{{ supDelta(s) }}%</template>
                <template v-else>---</template>
              </div>
              <div class="dash-sup-card__metric-label">vs прошл.</div>
            </div>
          </div>
        </div>
      </template>

      <!-- ===== PRODUCTS + REPORTS (MERGED) ===== -->
      <template v-if="activeTab === 'products'">
        <div class="rpt-toolbar">
          <button class="btn primary" @click="exportAnalytics" :disabled="!data"><BkIcon name="excel" size="sm"/> Экспорт в Excel</button>
        </div>

        <!-- Топ товаров с прогрессом, спарклайнами и дельтами -->
        <div class="dash-card" style="padding:0;">
          <div class="an-prod-header">
            <span><BkIcon name="fire" size="sm"/> Топ товаров за {{ days }} дней</span>
            <span v-if="moneyStats.totalSpend > 0" class="an-prod-header-money">
              <BkIcon name="pricing" size="xs"/> ~{{ nfMoney(moneyStats.totalSpend) }} BYN
            </span>
          </div>
          <div v-for="(p, i) in data.topProducts" :key="p.sku || p.name" class="an-prod-row">
            <div class="an-prod-rank" :class="{ top: i < 3 }">{{ i + 1 }}</div>
            <div class="an-prod-info">
              <div class="an-prod-line1">
                <span class="an-prod-sku">{{ p.sku || '' }}</span>
                <span class="an-prod-name">{{ p.name || '---' }}</span>
              </div>
              <div class="an-prod-progress">
                <div class="an-prod-progress-bar" :style="{ width: pPct(p) + '%' }"></div>
              </div>
            </div>
            <div class="an-prod-stats">
              <div class="an-prod-boxes">{{ nf(p.boxes) }} кор</div>
              <div v-if="p.deltaBoxes !== null" class="an-prod-delta" :class="p.deltaBoxes >= 0 ? 'up' : 'down'">
                {{ p.deltaBoxes >= 0 ? '+' : '' }}{{ p.deltaBoxes }}%
              </div>
              <div v-if="prices && prices[p.sku]" class="an-prod-cost">~{{ nfMoney(p.boxes * (prices[p.sku].price || 0) * (1 + (prices[p.sku].vat_rate || 0) / 100)) }} BYN</div>
            </div>
            <div class="an-prod-forecast">
              <div class="an-prod-forecast-label">прогноз</div>
              <div class="an-prod-forecast-val">~{{ nf(p.forecast) }}</div>
            </div>
          </div>
        </div>

        <!-- Сезонность -->
        <div class="dash-card">
          <div class="dash-card__title"><BkIcon name="calendar" size="sm"/> Сезонность (12 мес.)</div>
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
                  <div v-else class="rpt-yoy-val">---</div>
                </div>
              </div>
            </div>
          </template>
        </div>

        <div class="an-forecast-note">
          <BkIcon name="bulb" size="sm"/> Прогноз = средний расход в день x {{ days }} дней. Для детального прогноза запасов перейдите на вкладку Прогноз.
        </div>
      </template>

      <!-- ===== FORECAST ===== -->
      <template v-if="activeTab === 'forecast'">
        <div v-if="forecastLoading" style="text-align:center;padding:60px;">
          <BurgerSpinner text="Загрузка прогноза..." />
        </div>
        <div v-else-if="!forecast" style="text-align:center;padding:60px;color:var(--text-muted);">Нет данных для прогноза</div>
        <template v-else>
          <!-- Панель управления -->
          <div class="fc2-toolbar">
            <div class="fc2-toolbar-left">
              <div class="fc2-period-group">
                <button v-for="p in [7, 14, 30]" :key="p" class="fc2-period-btn" :class="{ active: forecastPeriod === p }" @click="forecastPeriod = p">{{ p }} дн.</button>
              </div>
              <div class="fc2-search-wrap">
                <svg class="fc2-search-icon" viewBox="0 0 16 16" width="13" height="13"><circle cx="6.5" cy="6.5" r="5" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M10 10l4 4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                <input v-model="forecastSearch" class="fc2-search" placeholder="Поиск..." />
              </div>
              <select v-model="forecastSupplier" class="fc2-select">
                <option value="">Все поставщики</option>
                <option v-for="s in forecast.suppliers" :key="s" :value="s">{{ s }}</option>
              </select>
            </div>
            <button v-if="forecastSupplier && canCreateOrder" class="btn primary small" @click="createOrderFromForecast" :disabled="!filteredForecast.length">
              <BkIcon name="order" size="sm"/> Заказ
            </button>
          </div>

          <!-- Сводка -->
          <div class="fc2-summary">
            <div class="fc2-stat">
              <span class="fc2-stat-val">{{ forecastKpi.totalGroups }}</span>
              <span class="fc2-stat-label">групп</span>
            </div>
            <div class="fc2-stat-sep"></div>
            <div class="fc2-stat">
              <span class="fc2-stat-val fc2-stat-accent">{{ nf(forecastKpi.totalForecast) }}</span>
              <span class="fc2-stat-label">ед. на {{ forecastPeriod }} дн.</span>
            </div>
            <div class="fc2-stat-sep"></div>
            <div class="fc2-stat" v-if="forecastKpi.deficitCount > 0">
              <span class="fc2-stat-val fc2-stat-danger">{{ forecastKpi.deficitCount }}</span>
              <span class="fc2-stat-label">дефицит</span>
            </div>
            <div class="fc2-stat" v-else>
              <span class="fc2-stat-val fc2-stat-ok">0</span>
              <span class="fc2-stat-label">дефицит</span>
            </div>
            <div class="fc2-stat-sep"></div>
            <div class="fc2-stat">
              <span class="fc2-stat-val">{{ forecastKpi.trendUp }}</span>
              <span class="fc2-stat-label">растут</span>
            </div>
            <div class="fc2-stat">
              <span class="fc2-stat-val">{{ forecastKpi.trendDown }}</span>
              <span class="fc2-stat-label">падают</span>
            </div>
          </div>

          <!-- Таблица -->
          <div class="fc2-table-wrap">
            <table class="fc2-table">
              <thead>
                <tr>
                  <th class="fc2-th fc2-th-name" @click="toggleForecastSort('name')">Группа{{ sortIcon('name') }}</th>
                  <th class="fc2-th fc2-th-num" @click="toggleForecastSort('avgPerDay')">В день{{ sortIcon('avgPerDay') }}</th>
                  <th class="fc2-th fc2-th-num" @click="toggleForecastSort('forecast')">На {{ forecastPeriod }} дн.{{ sortIcon('forecast') }}</th>
                  <th class="fc2-th fc2-th-num" @click="toggleForecastSort('stock')">Остаток{{ sortIcon('stock') }}</th>
                  <th class="fc2-th fc2-th-num" @click="toggleForecastSort('daysOfStock')">Дней{{ sortIcon('daysOfStock') }}</th>
                  <th v-if="hasYoyData" class="fc2-th fc2-th-num fc2-hide-sm" @click="toggleForecastSort('yoy')">vs год{{ sortIcon('yoy') }}</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="g in filteredForecastGroups" :key="g.name">
                  <tr class="fc2-row" :class="['fc2-s-' + g.stockStatus, { 'fc2-row-open': fcExpanded.has(g.name) }]" @click="toggleFcExpand(g.name)">
                    <!-- Название -->
                    <td class="fc2-td fc2-td-name">
                      <div class="fc2-name-wrap">
                        <svg v-if="g.isGroup" class="fc2-chev" :class="{ open: fcExpanded.has(g.name) }" viewBox="0 0 16 16" width="10" height="10"><path d="M5 3l5 5-5 5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <div class="fc2-name-col">
                          <span class="fc2-name">{{ g.name }}</span>
                          <span v-if="g.isGroup" class="fc2-cnt">{{ g.items.length }}</span>
                          <span v-if="g.dataSource === 'restaurant_sales'" class="fc2-src" title="Данные реализации ресторанов">Р</span>
                          <span class="fc2-unit">{{ g.unit || 'шт' }}</span>
                          <span class="fc2-trend-icon" :class="'fc2-t-' + g.trend" :title="g.trend === 'up' ? 'Растёт' : g.trend === 'down' ? 'Падает' : 'Стабильно'">{{ g.trend === 'up' ? '\u25B2' : g.trend === 'down' ? '\u25BC' : '' }}</span>
                        </div>
                        <div v-if="g.supplier && !forecastSupplier" class="fc2-supplier">{{ g.supplier }}</div>
                      </div>
                    </td>
                    <!-- Расход/день -->
                    <td class="fc2-td fc2-td-num" :class="{ 'fc2-muted': !g.hasConsumptionData }">
                      {{ g.hasConsumptionData ? g.avgPerDay.toFixed(1) : '---' }}
                    </td>
                    <!-- Прогноз -->
                    <td class="fc2-td fc2-td-num fc2-td-forecast" :class="{ 'fc2-muted': !g.hasConsumptionData }">
                      <span>{{ g.hasConsumptionData ? Math.round(forecastVal(g)) : '---' }}</span>
                      <span v-if="g.seasonCoeff && Math.abs(g.seasonCoeff - 1) > 0.08" class="fc2-season" :class="g.seasonCoeff > 1 ? 'fc2-season-up' : 'fc2-season-dn'" :title="'Сезонность: ' + (g.seasonCoeff > 1 ? '+' : '') + Math.round((g.seasonCoeff - 1) * 100) + '%'">
                        {{ g.seasonCoeff > 1 ? '\u25B2' : '\u25BC' }}
                      </span>
                    </td>
                    <!-- Остаток -->
                    <td class="fc2-td fc2-td-num" :class="{ 'fc2-muted': g.stock === null }">
                      {{ g.stock !== null ? Math.round(g.stock) : '---' }}
                    </td>
                    <!-- Дней запаса -->
                    <td class="fc2-td fc2-td-num fc2-td-days" :class="g.daysOfStock !== null && g.daysOfStock <= 3 ? 'fc2-days-crit' : g.daysOfStock !== null && g.daysOfStock <= 7 ? 'fc2-days-warn' : g.daysOfStock === null ? 'fc2-muted' : 'fc2-days-ok'">
                      <template v-if="g.daysOfStock === null || g.daysOfStock >= 999">---</template>
                      <template v-else>{{ g.daysOfStock }}</template>
                    </td>
                    <!-- YoY -->
                    <td v-if="hasYoyData" class="fc2-td fc2-td-num fc2-hide-sm" :class="{ 'fc2-muted': g.yoyChange === null }">
                      <template v-if="g.yoyChange !== null">
                        <span :class="g.yoyChange > 5 ? 'fc2-yoy-up' : g.yoyChange < -5 ? 'fc2-yoy-dn' : ''">{{ g.yoyChange > 0 ? '+' : '' }}{{ g.yoyChange }}%</span>
                      </template>
                      <template v-else>---</template>
                    </td>
                  </tr>
                  <!-- Развёрнутые товары -->
                  <template v-if="g.isGroup && fcExpanded.has(g.name)">
                    <tr v-for="item in g.items" :key="item.sku" class="fc2-row fc2-sub" :class="'fc2-s-' + item.stockStatus">
                      <td class="fc2-td fc2-td-name fc2-sub-name">
                        <span class="fc2-sub-sku">{{ item.sku }}</span>
                        <span class="fc2-sub-label">{{ item.name }}</span>
                      </td>
                      <td class="fc2-td fc2-td-num" :class="{ 'fc2-muted': !item.hasConsumptionData }">{{ item.hasConsumptionData ? item.avgPerDay.toFixed(1) : '---' }}</td>
                      <td class="fc2-td fc2-td-num" :class="{ 'fc2-muted': !item.hasConsumptionData }">{{ item.hasConsumptionData ? Math.round(forecastVal(item)) : '---' }}</td>
                      <td class="fc2-td fc2-td-num" :class="{ 'fc2-muted': item.stock === null }">{{ item.stock !== null ? Math.round(item.stock) : '---' }}</td>
                      <td class="fc2-td fc2-td-num fc2-td-days" :class="item.daysOfStock !== null && item.daysOfStock <= 3 ? 'fc2-days-crit' : item.daysOfStock !== null && item.daysOfStock <= 7 ? 'fc2-days-warn' : item.daysOfStock === null ? 'fc2-muted' : 'fc2-days-ok'">
                        <template v-if="item.daysOfStock === null || item.daysOfStock >= 999">---</template>
                        <template v-else>{{ item.daysOfStock }}</template>
                      </td>
                      <td v-if="hasYoyData" class="fc2-td fc2-td-num fc2-hide-sm" :class="{ 'fc2-muted': item.yoyChange === null }">
                        <template v-if="item.yoyChange !== null"><span :class="item.yoyChange > 5 ? 'fc2-yoy-up' : item.yoyChange < -5 ? 'fc2-yoy-dn' : ''">{{ item.yoyChange > 0 ? '+' : '' }}{{ item.yoyChange }}%</span></template>
                        <template v-else>---</template>
                      </td>
                    </tr>
                  </template>
                </template>
              </tbody>
            </table>
            <div v-if="!filteredForecastGroups.length" class="fc2-empty">Нет данных по выбранному фильтру</div>
          </div>
        </template>
      </template>

      <!-- ===== CHANGES ===== -->
      <template v-if="activeTab === 'changes'">
        <div v-if="!data.changes.length" style="text-align:center;padding:40px;color:var(--text-muted);">
          <BkIcon name="success" size="sm"/> Нет значимых изменений за выбранный период
        </div>
        <div v-for="(c, i) in data.changes" :key="i" class="an-change" :class="'sev-' + c.severity">
          <div class="an-change-sev-badge" :class="'an-sev-' + c.severity">{{ c.severity === 'danger' ? 'КРИТИЧНО' : 'ВНИМАНИЕ' }}</div>
          <div class="an-change-body">
            <div class="an-change-title">{{ c.title }}</div>
            <div class="an-change-text">{{ c.text }}</div>
            <div class="an-change-detail">{{ c.detail }}</div>
          </div>
          <span class="an-change-tag">{{ changeTypeLabel(c.type) }}</span>
          <button v-if="c.sku" class="an-change-action" @click="goToProduct(c.sku)">Посмотреть</button>
        </div>
      </template>

    </div>
    </template>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { getOrdersAnalytics, getSeasonalityData, getForecastData } from '@/lib/analytics.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useDraftStore } from '@/stores/draftStore.js';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import { useToastStore } from '@/stores/toastStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { db } from '@/lib/apiClient.js';
import { applyEntityFilter } from '@/lib/utils.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import AbcXyzPanel from '@/views/AbcXyzView.vue';


const router = useRouter();
const orderStore = useOrderStore();
const draftStore = useDraftStore();
const toast = useToastStore();
const userStore = useUserStore();
const canCreateOrder = computed(() => userStore.hasAccess('analytics', 'edit'));

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
const forecastSearch = ref('');
const fcExpanded = reactive(new Set());

// Prices for monetary metrics
const prices = ref(null);

let _analyticsLoadId = 0;

const formatter = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 });
function nf(v) { return formatter.format(v || 0); }

const moneyFormatter = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 });
function nfMoney(v) { return moneyFormatter.format(Math.round(v || 0)); }

// Chart: only days with orders (skip zero days)
const chartDays = computed(() => {
  if (!data.value) return [];
  return data.value.days.filter(d => d.total > 0);
});
const maxTotal = computed(() => chartDays.value.length ? Math.max(...chartDays.value.map(d => d.total), 1) : 1);

const criticalChanges = computed(() => data.value ? data.value.changes.filter(c => c.severity === 'danger') : []);

const tabs = [
  { id: 'overview', label: 'Дашборд', icon: 'analytics' },
  { id: 'planfact', label: 'План/Факт', icon: 'success' },
  { id: 'suppliers', label: 'Поставщики', icon: 'building' },
  { id: 'products', label: 'Товары и отчёты', icon: 'fire' },
  { id: 'forecast', label: 'Прогноз', icon: 'chartUp' },
  { id: 'changes', label: 'Изменения', icon: 'warning' },
  { id: 'abc-xyz', label: 'ABC/XYZ', icon: 'planning' },
];

// ---- Prices loading ----
async function loadPrices() {
  if (!userStore.hasAccess('pricing', 'view')) { prices.value = null; return; }
  const entity = orderStore.settings.legalEntity;
  let q = db.from('product_prices').select('sku,price,vat_rate,unit_type,legal_entity');
  if (entity) q = q.eq('legal_entity', entity);
  const { data: d } = await q;
  if (d) {
    prices.value = {};
    for (const p of d) prices.value[p.sku] = p;
  }
}

// ---- Money stats ----
const moneyStats = computed(() => {
  const result = { totalSpend: 0, avgOrderCost: 0, costPerBox: 0 };
  if (!data.value || !prices.value) return result;
  let total = 0;
  for (const p of data.value.topProducts) {
    const priceInfo = prices.value[p.sku];
    if (priceInfo && priceInfo.price) {
      const unitPrice = priceInfo.price * (1 + (priceInfo.vat_rate || 0) / 100);
      total += p.boxes * unitPrice;
    }
  }
  result.totalSpend = total;
  result.avgOrderCost = data.value.totals.orders > 0 ? total / data.value.totals.orders : 0;
  result.costPerBox = data.value.totals.boxes > 0 ? total / data.value.totals.boxes : 0;
  return result;
});

// ---- Overview computed ----
const uniqueProductsCount = computed(() => {
  if (!data.value || !data.value.topProducts) return 0;
  return data.value.topProducts.length;
});

const avgBoxesPerSupplier = computed(() => {
  if (!data.value || !data.value.suppliers.length) return 0;
  return Math.round(data.value.totals.boxes / data.value.suppliers.length);
});

const topSupplierConcentration = computed(() => {
  if (!data.value || !data.value.suppliers.length || !data.value.totals.boxes) return 0;
  return Math.round(data.value.suppliers[0].boxes / data.value.totals.boxes * 100);
});

const busiestSupplierName = computed(() => {
  if (!data.value || !data.value.suppliers.length) return '---';
  return data.value.suppliers[0].supplier;
});

// Insights
const insights = computed(() => {
  if (!data.value) return [];
  const list = [];
  // Volume change
  if (data.value.deltaBoxes !== null) {
    if (data.value.deltaBoxes > 10) {
      list.push({ type: 'good', text: 'Объём вырос на ' + data.value.deltaBoxes + '% по сравнению с прошлым периодом' });
    } else if (data.value.deltaBoxes < -10) {
      list.push({ type: 'bad', text: 'Объём снизился на ' + Math.abs(data.value.deltaBoxes) + '% по сравнению с прошлым периодом' });
    } else {
      list.push({ type: 'neutral', text: 'Объём стабилен: изменение ' + (data.value.deltaBoxes >= 0 ? '+' : '') + data.value.deltaBoxes + '%' });
    }
  }
  // Busiest supplier
  if (data.value.suppliers.length > 1) {
    list.push({ type: 'neutral', text: 'Самый загруженный поставщик: ' + busiestSupplierName.value + ' (' + topSupplierConcentration.value + '% объёма)' });
  }
  // Critical changes
  if (criticalChanges.value.length) {
    list.push({ type: 'bad', text: criticalChanges.value.length + ' товаров с критичными изменениями' });
  }
  // Fulfillment
  if (data.value.planFact.receivedOrders > 0) {
    if (data.value.planFact.fulfillmentPct >= 95) {
      list.push({ type: 'good', text: 'Выполнение поставок: ' + data.value.planFact.fulfillmentPct + '%' });
    } else if (data.value.planFact.fulfillmentPct < 80) {
      list.push({ type: 'bad', text: 'Низкое выполнение поставок: ' + data.value.planFact.fulfillmentPct + '%' });
    } else {
      list.push({ type: 'neutral', text: 'Выполнение поставок: ' + data.value.planFact.fulfillmentPct + '%' });
    }
  }
  // Deficit from forecast
  if (forecast.value && forecast.value.kpi.deficitCount > 0) {
    list.push({ type: 'bad', text: forecast.value.kpi.deficitCount + ' товаров с дефицитом запасов' });
  }
  // Money
  if (moneyStats.value.totalSpend > 0) {
    list.push({ type: 'neutral', text: 'Объём закупок за период: ~' + nfMoney(moneyStats.value.totalSpend) + ' BYN' });
  }
  return list;
});

// Area chart
const areaChartW = 600;
const areaChartH = 160;

const areaPoints = computed(() => {
  if (!chartDays.value.length) return [];
  const pts = [];
  const len = chartDays.value.length;
  const padX = 20;
  const padY = 10;
  const usableW = areaChartW - padX * 2;
  const usableH = areaChartH - padY * 2;
  for (let i = 0; i < len; i++) {
    const x = len === 1 ? areaChartW / 2 : padX + (i / (len - 1)) * usableW;
    const y = padY + usableH - (chartDays.value[i].total / maxTotal.value) * usableH;
    pts.push({ x: Math.round(x * 10) / 10, y: Math.round(y * 10) / 10 });
  }
  return pts;
});

const linePath = computed(() => {
  if (!areaPoints.value.length) return '';
  return areaPoints.value.map((p, i) => (i === 0 ? 'M' : 'L') + p.x + ',' + p.y).join(' ');
});

const areaPath = computed(() => {
  if (!areaPoints.value.length) return '';
  const pts = areaPoints.value;
  let d = 'M' + pts[0].x + ',' + pts[0].y;
  for (let i = 1; i < pts.length; i++) {
    d += ' L' + pts[i].x + ',' + pts[i].y;
  }
  d += ' L' + pts[pts.length - 1].x + ',' + areaChartH;
  d += ' L' + pts[0].x + ',' + areaChartH + ' Z';
  return d;
});

const areaLabelDays = computed(() => {
  if (!chartDays.value.length) return [];
  const d = chartDays.value;
  if (d.length <= 10) return d;
  // Show every nth label
  const step = Math.ceil(d.length / 8);
  return d.filter((_, i) => i % step === 0 || i === d.length - 1);
});

// Donut chart
const donutSegments = computed(() => {
  if (!data.value || !data.value.suppliers.length) return [];
  const total = data.value.totals.boxes || 1;
  const circumference = 2 * Math.PI * 46; // r=46
  const segments = [];
  let offset = 0;
  for (const s of data.value.suppliers) {
    const pct = s.boxes / total;
    const len = pct * circumference;
    const gap = 2;
    segments.push({
      color: s.color,
      dash: (len - gap) + ' ' + (circumference - len + gap),
      offset: -offset,
    });
    offset += len;
  }
  return segments;
});

function fulfillmentCls(pct) {
  if (pct >= 95) return 'val-good';
  if (pct >= 80) return 'val-warn';
  return 'val-bad';
}

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
function changeTypeLabel(t) {
  return { disappeared: 'Пропал товар', low_stock: 'Заканчивается' }[t] || t;
}

function goToProduct(sku) {
  router.push({ name: 'database', query: { search: sku } });
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
  const myLoadId = ++_analyticsLoadId;
  loading.value = true;
  try {
    const result = await getOrdersAnalytics(orderStore.settings.legalEntity, days.value);
    if (myLoadId !== _analyticsLoadId) return;
    data.value = result;
  } catch (e) {
    if (myLoadId !== _analyticsLoadId) return;
    toast.error('Ошибка', 'Не удалось загрузить аналитику');
    data.value = null;
  } finally {
    if (myLoadId === _analyticsLoadId) loading.value = false;
  }
}

// Lazy-load сезонности и прогноза при переключении на табы
watch(activeTab, async (tab) => {
  if (tab === 'products' && !seasonality.value && !seasonalityLoading.value) {
    const myId = ++_seasonLoadId;
    seasonalityLoading.value = true;
    try {
      const d = await getSeasonalityData(orderStore.settings.legalEntity);
      if (myId === _seasonLoadId) seasonality.value = d;
    } catch (e) {
      if (myId === _seasonLoadId) seasonality.value = null;
    } finally {
      if (myId === _seasonLoadId) seasonalityLoading.value = false;
    }
  }
  if (tab === 'forecast' && !forecast.value && !forecastLoading.value) {
    await loadForecast();
  }
});

async function loadForecast() {
  const myId = ++_forecastLoadId;
  forecastLoading.value = true;
  try {
    const d = await getForecastData(orderStore.settings.legalEntity);
    if (myId !== _forecastLoadId) return;
    forecast.value = d;
    fcExpanded.clear();
  } catch (e) {
    if (myId !== _forecastLoadId) return;
    console.error('Forecast load error:', e);
    toast.error('Ошибка', 'Не удалось загрузить прогноз: ' + (e.message || e));
    forecast.value = null;
  } finally {
    if (myId === _forecastLoadId) forecastLoading.value = false;
  }
}

// Фильтрованный и отсортированный список прогноза (по товарам — для кнопки «создать заказ»)
const filteredForecast = computed(() => {
  if (!forecast.value) return [];
  let items = forecast.value.items;
  if (forecastSupplier.value) {
    items = items.filter(i => i.supplier === forecastSupplier.value);
  }
  return items;
});

// Фильтрованный и отсортированный список групп
const filteredForecastGroups = computed(() => {
  if (!forecast.value) return [];
  let groups = forecast.value.groups || [];
  // Фильтр по поставщику
  if (forecastSupplier.value) {
    groups = groups.filter(g => g.suppliers.includes(forecastSupplier.value));
  }
  // Поиск
  if (forecastSearch.value) {
    const q = forecastSearch.value.toLowerCase();
    groups = groups.filter(g =>
      g.name.toLowerCase().includes(q) ||
      g.items.some(i => (i.name || '').toLowerCase().includes(q) || (i.sku || '').toLowerCase().includes(q))
    );
  }
  // Сортировка
  const sort = forecastSort.value;
  if (sort.col !== 'default') {
    groups = [...groups].sort((a, b) => {
      let va, vb;
      if (sort.col === 'name') { va = a.name.toLowerCase(); vb = b.name.toLowerCase(); return sort.asc ? va.localeCompare(vb) : vb.localeCompare(va); }
      if (sort.col === 'forecast') { va = forecastVal(a); vb = forecastVal(b); }
      else if (sort.col === 'avgPerDay') { va = a.avgPerDay; vb = b.avgPerDay; }
      else if (sort.col === 'yoy') { va = a.yoyChange ?? -9999; vb = b.yoyChange ?? -9999; }
      else if (sort.col === 'stock') { va = a.stock ?? -1; vb = b.stock ?? -1; }
      else if (sort.col === 'daysOfStock') { va = a.daysOfStock ?? 9999; vb = b.daysOfStock ?? 9999; }
      else { va = 0; vb = 0; }
      return sort.asc ? va - vb : vb - va;
    });
  }
  return groups;
});

const hasYoyData = computed(() => {
  if (!forecast.value?.groups) return false;
  return forecast.value.groups.some(g => g.yoyChange !== null);
});

function toggleFcExpand(name) {
  if (fcExpanded.has(name)) fcExpanded.delete(name);
  else fcExpanded.add(name);
}

function forecastVal(item) {
  if (forecastPeriod.value === 14) return item.forecast14;
  if (forecastPeriod.value === 30) return item.forecast30;
  return item.forecast7;
}

// KPI пересчитываются по отфильтрованным группам
const forecastKpi = computed(() => {
  const groups = filteredForecastGroups.value;
  // Раскрываем до отдельных товаров для точных подсчётов
  const items = groups.flatMap(g => g.items);
  const withStock = items.filter(i => i.stockStatus !== 'unknown');
  const deficit = withStock.filter(i => i.stockStatus === 'critical' || i.stockStatus === 'warning');
  const totalForecast = items.reduce((s, i) => s + forecastVal(i), 0);
  return {
    totalGroups: groups.length,
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
  return forecastSort.value.asc ? ' \u25B2' : ' \u25BC';
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
  if (trend === 'up') return '\u25B2 Растёт';
  if (trend === 'down') return '\u25BC Падает';
  return '--- Стабильно';
}

async function createOrderFromForecast() {
  if (!forecast.value || !forecastSupplier.value) return;
  const fcItems = filteredForecast.value;
  if (!fcItems.length) return;
  // Загрузить полные карточки товаров из БД
  const skus = fcItems.map(i => i.sku).filter(Boolean);
  let productMap = {};
  if (skus.length) {
    let prodQuery = db.from('products').select('*').in('sku', skus);
    prodQuery = applyEntityFilter(prodQuery, orderStore.settings.legalEntity);
    const { data: products } = await prodQuery;
    if (products) productMap = Object.fromEntries(products.map(p => [p.sku, p]));
  }
  orderStore.resetOrder();
  orderStore.settings.supplier = forecastSupplier.value;
  let count = 0;
  for (const item of fcItems) {
    const fullProduct = productMap[item.sku] || {
      sku: item.sku,
      name: item.name,
      qty_per_box: item.qtyPerBox,
    };
    const added = orderStore.addItem(fullProduct);
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

let _seasonLoadId = 0;
let _forecastLoadId = 0;
watch(() => orderStore.settings.legalEntity, () => {
  seasonality.value = null;
  forecast.value = null;
  seasonalityLoading.value = false;
  forecastLoading.value = false;
  forecastSupplier.value = '';
  prices.value = null;
  load();
  loadPrices();
  // Перезагрузить данные таба, если пользователь уже на нём
  if (activeTab.value === 'products') {
    const myId = ++_seasonLoadId;
    seasonalityLoading.value = true;
    getSeasonalityData(orderStore.settings.legalEntity)
      .then(d => { if (myId === _seasonLoadId) seasonality.value = d; })
      .catch(() => { if (myId === _seasonLoadId) seasonality.value = null; })
      .finally(() => { if (myId === _seasonLoadId) seasonalityLoading.value = false; });
  }
  if (activeTab.value === 'forecast') loadForecast();
});
onMounted(() => {
  load();
  loadPrices();
});
</script>

<style scoped>
.analytics-view { padding: 0; display: flex; flex-direction: column; }

/* ===== Header ===== */
.an-header {
  display: flex; align-items: center; justify-content: space-between;
  flex-shrink: 0; margin-bottom: 8px;
}
.an-period {
  padding: 6px 12px; border-radius: 8px; border: 1px solid var(--border);
  font-size: 12px; font-weight: 600; background: var(--card); color: var(--text);
  cursor: pointer;
}

/* ===== Alert banner ===== */
.an-alert-banner {
  padding: 8px 14px; background: linear-gradient(135deg, #FFF3E0, #FFE0B2); border: 1px solid #FFCC80;
  border-radius: 10px; font-size: 12px; color: #E65100; cursor: pointer;
  display: flex; align-items: center; gap: 8px; flex-shrink: 0; margin-bottom: 8px;
  transition: box-shadow 0.2s;
}
.an-alert-banner:hover { box-shadow: 0 2px 8px rgba(230, 81, 0, 0.15); }
.an-alert-link { margin-left: auto; font-weight: 700; }

/* ===== Tabs (pill style) ===== */
.an-tabs {
  display: flex; gap: 4px; margin-bottom: 14px; flex-shrink: 0;
  padding: 4px; background: var(--bg); border-radius: 12px;
  overflow-x: auto;
}
.an-tab {
  padding: 7px 14px; font-size: 12px; font-weight: 600; border: none; cursor: pointer;
  border-radius: 8px; background: none;
  color: var(--text-muted); transition: all 0.15s; display: flex; align-items: center; gap: 5px;
  white-space: nowrap;
}
.an-tab.active {
  color: #fff; background: #502314;
  box-shadow: 0 2px 6px rgba(80, 35, 20, 0.25);
}
.an-tab:not(.active):hover { color: var(--text); background: rgba(80, 35, 20, 0.06); }
.an-tab-text { }
.an-tab-badge {
  font-size: 10px; font-weight: 700; background: #F44336; color: #fff;
  padding: 0 5px; border-radius: 8px; line-height: 16px;
}

/* ===== Content ===== */
.an-content { flex: 1; overflow-y: auto; min-height: 0; }

/* ===== Dashboard KPI Cards ===== */
.dash-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 14px; }

.dash-kpi {
  background: var(--card); border-radius: 14px; overflow: hidden;
  display: flex; position: relative;
  box-shadow: 0 2px 12px rgba(0,0,0,0.06);
  transition: box-shadow 0.2s, transform 0.15s;
}
.dash-kpi:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.1); transform: translateY(-2px); }
.dash-kpi__left-border { width: 4px; flex-shrink: 0; }
.dash-kpi--brown .dash-kpi__left-border { background: linear-gradient(to bottom, #502314, #7a4a3a); }
.dash-kpi--orange .dash-kpi__left-border { background: linear-gradient(to bottom, #FF8732, #FFB366); }
.dash-kpi--blue .dash-kpi__left-border { background: linear-gradient(to bottom, #1976D2, #64B5F6); }
.dash-kpi--green .dash-kpi__left-border { background: linear-gradient(to bottom, #2E7D32, #81C784); }
.dash-kpi--red .dash-kpi__left-border { background: linear-gradient(to bottom, #D32F2F, #EF9A9A); }

.dash-kpi__body { padding: 12px 14px; flex: 1; min-width: 0; }
.dash-kpi__header { display: flex; align-items: center; gap: 4px; margin-bottom: 4px; }
.dash-kpi__icon { flex-shrink: 0; }
.dash-kpi__label { font-size: 11px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; }
.dash-kpi__value-row { display: flex; align-items: baseline; gap: 8px; }
.dash-kpi__value { font-size: 26px; font-weight: 800; color: var(--text); line-height: 1.1; }
.dash-kpi__value.val-good { color: #2E7D32; }
.dash-kpi__value.val-warn { color: #E65100; }
.dash-kpi__value.val-bad { color: #D32F2F; }
.dash-kpi__sub { font-size: 10px; color: var(--text-muted); margin-top: 2px; }
.dash-kpi__unit { font-size: 12px; color: var(--text-muted); font-weight: 600; }

/* Delta badges */
.dash-badge {
  font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 10px;
  display: inline-flex; align-items: center;
}
.dash-badge--up { background: #E8F5E9; color: #2E7D32; }
.dash-badge--down { background: #FFEBEE; color: #C62828; }

/* Secondary stats row */
.dash-secondary-row {
  display: flex; gap: 10px; margin-bottom: 14px;
}
.dash-mini-stat {
  flex: 1; background: var(--card); border-radius: 10px; padding: 10px 12px;
  text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05);
  border: 1px solid var(--border-light);
}
.dash-mini-stat__val { font-size: 18px; font-weight: 800; color: var(--text); display: block; }
.dash-mini-stat__label { font-size: 10px; color: var(--text-muted); font-weight: 600; display: block; margin-top: 2px; }

/* Insights panel */
.dash-insights {
  background: var(--card); border-radius: 14px; padding: 12px 16px; margin-bottom: 14px;
  border: 1px solid var(--border-light); box-shadow: 0 2px 12px rgba(0,0,0,0.04);
}
.dash-insights__title { font-size: 13px; font-weight: 800; color: var(--text); margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
.dash-insights__list { display: flex; flex-direction: column; gap: 6px; }
.dash-insight { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--text); padding: 4px 0; }
.dash-insight__dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.dash-insight--good .dash-insight__dot { background: #4CAF50; }
.dash-insight--bad .dash-insight__dot { background: #F44336; }
.dash-insight--neutral .dash-insight__dot { background: #FF9800; }
.dash-insight__text { flex: 1; }

/* ===== Dashboard Cards ===== */
.dash-card {
  background: var(--card); border-radius: 14px; padding: 16px;
  margin-bottom: 14px; border: 1px solid var(--border-light);
  box-shadow: 0 2px 12px rgba(0,0,0,0.06);
  transition: box-shadow 0.2s;
}
.dash-card:hover { box-shadow: 0 3px 14px rgba(0,0,0,0.09); }
.dash-card__header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 12px; flex-wrap: wrap; gap: 6px;
}
.dash-card__title { font-size: 14px; font-weight: 800; color: var(--text); display: flex; align-items: center; gap: 6px; margin-bottom: 10px; }

/* Legend */
.dash-legend { display: flex; flex-wrap: wrap; gap: 10px; }
.dash-legend__item { display: flex; align-items: center; gap: 4px; font-size: 10px; color: var(--text); }
.dash-legend__dot { width: 8px; height: 8px; border-radius: 2px; flex-shrink: 0; }

/* ===== Area chart ===== */
.dash-area-chart { margin-top: 4px; }
.dash-area-svg { width: 100%; height: 160px; display: block; }
.dash-area-labels {
  display: flex; justify-content: space-between; padding: 4px 20px 0;
}
.dash-area-label { font-size: 9px; color: var(--text-muted); }

/* ===== Two-column layout ===== */
.dash-two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 0; }
.dash-card--half { margin-bottom: 14px; }

/* Donut */
.dash-donut-wrap { display: flex; align-items: center; gap: 16px; }
.dash-donut-svg { width: 120px; height: 120px; flex-shrink: 0; }
.dash-donut-center-val { font-size: 16px; font-weight: 800; fill: var(--text); }
.dash-donut-center-label { font-size: 9px; fill: var(--text-muted); font-weight: 600; }
.dash-donut-legend { flex: 1; display: flex; flex-direction: column; gap: 5px; }
.dash-donut-legend__item { display: flex; align-items: center; gap: 6px; font-size: 11px; }
.dash-donut-legend__dot { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
.dash-donut-legend__name { flex: 1; color: var(--text); font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dash-donut-legend__pct { font-weight: 700; color: var(--text); min-width: 32px; text-align: right; }

/* Mini rows in overview */
.dash-mini-row {
  display: flex; align-items: center; gap: 8px; padding: 5px 0;
  border-bottom: 1px solid var(--border-light); font-size: 12px;
}
.dash-mini-row:last-child { border-bottom: none; }
.dash-mini-row__rank {
  width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
  font-size: 10px; font-weight: 700; flex-shrink: 0; background: var(--border-light); color: var(--text);
}
.dash-mini-row__rank.top { background: #FF8732; color: #fff; }
.dash-mini-row__name { flex: 1; font-weight: 600; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dash-mini-row__val { font-weight: 700; color: var(--text); min-width: 40px; text-align: right; }
.dash-mini-row__delta { font-size: 10px; font-weight: 700; min-width: 40px; text-align: right; }
.dash-mini-row__delta.up { color: #2E7D32; }
.dash-mini-row__delta.down { color: #C62828; }
.dash-mini-row__meta { font-size: 10px; color: var(--text-muted); min-width: 40px; text-align: right; }

/* Supplier bars on overview */
.dash-sup-table { display: flex; flex-direction: column; }
.dash-sup-row {
  display: flex; align-items: center; gap: 10px; padding: 6px 0;
  border-bottom: 1px solid var(--border-light);
}
.dash-sup-row:last-child { border-bottom: none; }
.dash-sup-row__left { display: flex; align-items: center; gap: 6px; flex: 1; min-width: 0; }
.dash-sup-row__dot { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
.dash-sup-row__name { font-size: 12px; font-weight: 600; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dash-sup-row__right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; width: 65%; }
.dash-sup-row__bar-wrap { flex: 1; height: 10px; background: var(--border-light); border-radius: 5px; overflow: hidden; }
.dash-sup-row__bar { height: 100%; border-radius: 5px; transition: width 0.4s; }
.dash-sup-row__val { font-size: 11px; font-weight: 700; color: var(--text); min-width: 50px; text-align: right; }
.dash-sup-row__meta { font-size: 10px; color: var(--text-muted); min-width: 45px; text-align: right; }

/* ===== Supplier cards (tab) ===== */
.dash-sup-card { padding: 14px 16px; }
.dash-sup-card__head { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.dash-sup-card__dot { width: 14px; height: 14px; border-radius: 4px; flex-shrink: 0; }
.dash-sup-card__name { font-size: 15px; font-weight: 800; color: var(--text); flex: 1; }
.dash-sup-card__ago { font-size: 11px; color: var(--text-muted); }
.dash-sup-card__metrics { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
.dash-sup-card__metric { background: var(--bg); padding: 10px 6px; border-radius: 8px; text-align: center; }
.dash-sup-card__metric-val { font-size: 18px; font-weight: 800; color: var(--text); }
.dash-sup-card__metric-val.val-up { color: #2E7D32; font-size: 14px; }
.dash-sup-card__metric-val.val-down { color: #C62828; font-size: 14px; }
.dash-sup-card__metric-label { font-size: 9px; color: var(--text-muted); font-weight: 600; margin-top: 2px; }

/* ===== Products + Reports (merged) ===== */
.an-prod-header {
  padding: 10px 14px; border-bottom: 1px solid var(--border-light);
  font-size: 14px; font-weight: 800; color: var(--text);
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
}
.an-prod-header-money {
  font-size: 12px; font-weight: 700; color: #1976D2;
  display: flex; align-items: center; gap: 4px;
}
.an-prod-row {
  display: flex; align-items: center; gap: 10px; padding: 9px 14px;
  border-bottom: 1px solid var(--border-light);
  transition: background 0.1s;
}
.an-prod-row:hover { background: rgba(0,0,0,0.015); }
.an-prod-row:last-child { border-bottom: none; }
.an-prod-rank {
  width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
  font-size: 10px; font-weight: 700; flex-shrink: 0; background: var(--border); color: var(--text);
}
.an-prod-rank.top { background: #FF8732; color: #fff; font-size: 12px; }
.an-prod-info { flex: 1; min-width: 0; }
.an-prod-line1 { display: flex; align-items: baseline; gap: 5px; }
.an-prod-sku { font-size: 10px; font-weight: 700; color: #FF8732; }
.an-prod-name { font-size: 12px; font-weight: 600; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.an-prod-progress { height: 4px; background: var(--border-light); border-radius: 2px; overflow: hidden; margin-top: 3px; }
.an-prod-progress-bar { height: 100%; background: linear-gradient(90deg, #4CAF50, #81C784); border-radius: 2px; transition: width 0.4s; }
.an-prod-stats { text-align: right; min-width: 65px; flex-shrink: 0; }
.an-prod-boxes { font-size: 13px; font-weight: 700; color: var(--text); }
.an-prod-delta { font-size: 10px; font-weight: 700; }
.an-prod-delta.up { color: #2E7D32; }
.an-prod-delta.down { color: #C62828; }
.an-prod-cost { font-size: 9px; color: #1976D2; font-weight: 600; margin-top: 1px; }
.an-prod-forecast { border-left: 1px solid var(--border-light); padding-left: 10px; min-width: 60px; text-align: right; flex-shrink: 0; }
.an-prod-forecast-label { font-size: 9px; color: var(--text-muted); }
.an-prod-forecast-val { font-size: 14px; font-weight: 700; color: #1976D2; }

.an-forecast-note {
  margin-top: 4px; padding: 8px 12px; background: #E3F2FD; border-radius: 8px;
  border: 1px solid #90CAF9; font-size: 12px; color: #1565C0;
}

/* ===== Reports toolbar ===== */
.rpt-toolbar {
  display: flex; justify-content: flex-end; margin-bottom: 12px;
}

/* Seasonality */
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
.rpt-season-bar { width: 100%; background: linear-gradient(to top, #FF8732, #ffb366); border-radius: 3px 3px 0 0; transition: height 0.4s; }
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
.rpt-season-legend-bar { width: 16px; height: 6px; border-radius: 2px; background: linear-gradient(90deg, #FF8732, #ffb366); }
.rpt-season-legend-dot { width: 8px; height: 8px; border-radius: 50%; background: #D62300; }

.rpt-yoy-table { margin-top: 14px; }
.rpt-yoy-title { font-size: 12px; font-weight: 800; color: var(--text); margin-bottom: 6px; }
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

/* ===== Changes ===== */
.an-change {
  display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px;
  margin-bottom: 6px; border-radius: 10px;
}
.an-change.sev-danger { background: #FFF5F5; border: 1px solid #FFCDD2; }
.an-change.sev-warning { background: #FFFCF0; border: 1px solid #FFE082; }
.an-change-sev-badge {
  font-size: 9px; font-weight: 800; padding: 3px 8px; border-radius: 4px;
  flex-shrink: 0; white-space: nowrap; letter-spacing: 0.5px;
}
.an-sev-danger { background: #FFCDD2; color: #B71C1C; }
.an-sev-warning { background: #FFE082; color: #BF360C; }
.an-change-body { flex: 1; }
.an-change-title { font-size: 13px; font-weight: 700; color: var(--text); }
.an-change-text { font-size: 12px; color: var(--text); margin-top: 2px; }
.an-change-detail { font-size: 11px; color: var(--text-muted); margin-top: 3px; }
.an-change-tag {
  font-size: 9px; font-weight: 700; padding: 2px 8px; border-radius: 4px;
  flex-shrink: 0; white-space: nowrap; align-self: center;
}
.sev-danger .an-change-tag { background: #FFCDD2; color: #B71C1C; }
.sev-warning .an-change-tag { background: #FFE082; color: #BF360C; }
.an-change-action {
  font-size: 11px; font-weight: 700; color: #1565C0; flex-shrink: 0; align-self: center;
  white-space: nowrap; padding: 5px 12px; background: #E3F2FD; border-radius: 6px;
  border: none; cursor: pointer; font-family: inherit; transition: background .15s;
}
.an-change-action:hover { background: #BBDEFB; }

/* ===== Plan-Fact ===== */
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

/* ===== Forecast tab v2 ===== */
.fc2-toolbar {
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
  margin-bottom: 10px; flex-wrap: wrap;
}
.fc2-toolbar-left { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.fc2-period-group { display: flex; }
.fc2-period-btn {
  padding: 6px 14px; font-size: 12px; font-weight: 700; border: 1.5px solid var(--border);
  background: var(--card); color: var(--text-muted); cursor: pointer; transition: all 0.12s;
}
.fc2-period-btn:first-child { border-radius: 8px 0 0 8px; }
.fc2-period-btn:last-child { border-radius: 0 8px 8px 0; }
.fc2-period-btn:not(:first-child) { border-left: none; }
.fc2-period-btn.active { background: #502314; color: #fff; border-color: #502314; }
.fc2-search-wrap {
  position: relative; display: flex; align-items: center;
}
.fc2-search-icon { position: absolute; left: 8px; color: var(--text-muted); pointer-events: none; }
.fc2-search {
  padding: 6px 10px 6px 26px; border: 1.5px solid var(--border); border-radius: 8px;
  background: var(--card); color: var(--text); font-size: 12px; width: 170px;
}
.fc2-select {
  padding: 6px 10px; border-radius: 8px; border: 1.5px solid var(--border);
  font-size: 12px; background: var(--card); color: var(--text); max-width: 200px;
}

/* Summary bar */
.fc2-summary {
  display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
  padding: 10px 14px; margin-bottom: 10px;
  background: var(--card); border: 1px solid var(--border-light); border-radius: 10px;
}
.fc2-stat { display: flex; align-items: baseline; gap: 4px; }
.fc2-stat-val { font-size: 20px; font-weight: 800; color: var(--text); line-height: 1; }
.fc2-stat-accent { color: #502314; }
.fc2-stat-danger { color: #D32F2F; }
.fc2-stat-ok { color: #2E7D32; }
.fc2-stat-label { font-size: 11px; color: var(--text-muted); white-space: nowrap; }
.fc2-stat-sep { width: 1px; height: 24px; background: var(--border-light); flex-shrink: 0; }

/* Table */
.fc2-table-wrap {
  border: 1px solid var(--border-light); border-radius: 10px; overflow: hidden;
  background: var(--card);
}
.fc2-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.fc2-th {
  padding: 9px 12px; font-weight: 700; font-size: 10px; color: var(--text-muted);
  text-transform: uppercase; letter-spacing: 0.4px;
  border-bottom: 2px solid var(--border); text-align: left; cursor: pointer;
  white-space: nowrap; user-select: none; background: var(--bg);
}
.fc2-th:hover { color: var(--text); }
.fc2-th-name { min-width: 180px; }
.fc2-th-num { text-align: right; min-width: 60px; }

/* Rows */
.fc2-row {
  border-bottom: 1px solid var(--border-light); cursor: pointer;
  transition: background 0.1s; border-left: 3px solid transparent;
}
.fc2-row:last-child { border-bottom: none; }
.fc2-row:hover { background: rgba(0,0,0,0.015); }
.fc2-row-open { background: rgba(0,0,0,0.02); }

/* Status left border */
.fc2-s-critical { border-left-color: #F44336; background: #FFF8F8; }
.fc2-s-critical:hover { background: #FFF0F0; }
.fc2-s-warning { border-left-color: #FF9800; background: #FFFDF5; }
.fc2-s-warning:hover { background: #FFF8E1; }
.fc2-s-ok { border-left-color: #4CAF50; }
.fc2-s-unknown { border-left-color: #E0E0E0; }

/* Cells */
.fc2-td { padding: 8px 12px; vertical-align: middle; }
.fc2-td-num { text-align: right; font-weight: 600; font-variant-numeric: tabular-nums; }
.fc2-td-forecast { font-weight: 700; color: #502314; }
.fc2-muted { color: var(--text-muted); font-weight: 400; }

/* Name cell */
.fc2-name-wrap { display: flex; flex-direction: column; gap: 1px; }
.fc2-name-col { display: flex; align-items: center; gap: 5px; }
.fc2-name { font-weight: 700; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px; }
.fc2-cnt { font-size: 9px; font-weight: 700; background: var(--bg); color: var(--text-muted); border-radius: 4px; padding: 0 4px; line-height: 1.5; }
.fc2-src { font-size: 8px; font-weight: 800; background: #E8F5E9; color: #2E7D32; border-radius: 3px; padding: 0 3px; line-height: 1.5; }
.fc2-unit { font-size: 9px; color: var(--text-muted); font-weight: 600; }
.fc2-supplier { font-size: 10px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 200px; }
.fc2-chev { transition: transform 0.15s; color: var(--text-muted); flex-shrink: 0; margin-right: 2px; }
.fc2-chev.open { transform: rotate(90deg); }

/* Trend icon inline */
.fc2-trend-icon { font-size: 8px; line-height: 1; }
.fc2-t-up { color: #4CAF50; }
.fc2-t-down { color: #F44336; }
.fc2-t-stable { color: transparent; }

/* Season arrow */
.fc2-season { font-size: 8px; margin-left: 3px; }
.fc2-season-up { color: #E65100; }
.fc2-season-dn { color: #1565C0; }

/* Days column */
.fc2-td-days { font-weight: 700; }
.fc2-days-crit { color: #D32F2F; font-weight: 800; }
.fc2-days-warn { color: #E65100; }
.fc2-days-ok { color: #2E7D32; }

/* YoY */
.fc2-yoy-up { color: #2E7D32; font-weight: 700; }
.fc2-yoy-dn { color: #C62828; font-weight: 700; }

/* Sub rows */
.fc2-sub { cursor: default; background: var(--bg); border-left-width: 3px; }
.fc2-sub:hover { background: #f0ece6; }
.fc2-sub-name { padding-left: 30px !important; display: flex; align-items: center; gap: 8px; }
.fc2-sub-sku { font-size: 10px; font-weight: 700; color: #FF8732; min-width: 50px; }
.fc2-sub-label { font-size: 12px; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.fc2-empty { text-align: center; padding: 30px; color: var(--text-muted); font-size: 13px; }

.fc2-hide-sm { }

/* Mobile */
@media (max-width: 768px) {
  .fc2-hide-sm { display: none; }
  .fc2-name { max-width: 140px; }
  .fc2-toolbar { gap: 6px; }
  .fc2-search { width: 120px; }
  .fc2-summary { gap: 8px; padding: 8px 10px; }
  .fc2-stat-val { font-size: 16px; }
  .fc2-th { padding: 7px 8px; }
  .fc2-td { padding: 7px 8px; font-size: 12px; }
}

/* ===== Responsive ===== */
@media (max-width: 900px) {
  .dash-kpi-grid { grid-template-columns: repeat(2, 1fr); }
  .dash-two-col { grid-template-columns: 1fr; }
  .dash-secondary-row { flex-wrap: wrap; }
  .dash-mini-stat { min-width: calc(50% - 6px); }
}

@media (max-width: 768px) {
  .an-tabs { overflow-x: auto; flex-wrap: nowrap; }
  .an-tab { padding: 6px 10px; font-size: 11px; }
  .dash-kpi-grid { grid-template-columns: repeat(2, 1fr); }
  .dash-donut-wrap { flex-direction: column; align-items: center; }
  .fc-controls { flex-direction: column; align-items: stretch; }
  .fc-controls-left { flex-direction: column; }
  .fc-supplier-select { max-width: none; }
  .fc-hide-mobile { display: none; }
  .fc-item-name { max-width: 120px; }
  .an-prod-header { flex-direction: column; align-items: flex-start; gap: 4px; }
}

@media (max-width: 480px) {
  .an-tab { padding: 5px 8px; font-size: 10px; gap: 3px; }
  .an-tab-text { display: none; }
  .dash-kpi-grid { grid-template-columns: 1fr; }
  .dash-secondary-row { flex-direction: column; }
  .dash-sup-card__metrics { grid-template-columns: repeat(2, 1fr); }
  .rpt-yoy-grid { grid-template-columns: repeat(4, 1fr); }
  .dash-sup-row__right { width: 55%; }
}
</style>
