<template>
  <div class="rc">
    <!-- ═══════════════════════════════════════════════════════════
         ЭКРАН 1: список расхождений
         ═══════════════════════════════════════════════════════════ -->
    <template v-if="screen === 'list'">
      <div class="rc-header">
        <h1 class="rc-title">Сверка 1С / УТ</h1>
        <p class="rc-subtitle">Загрузите файл расхождений из УТ — увидите список товаров с разницей. Кликните по товару, чтобы разобрать перемещения.</p>
      </div>

      <!-- Зона загрузки файла расхождений -->
      <div class="rc-upload-zone" :class="{ 'rc-upload-zone--active': dragOver1 }"
           @dragover.prevent="dragOver1 = true"
           @dragleave="dragOver1 = false"
           @drop.prevent="onDropRashozh">
        <label class="rc-upload-label">
          <input type="file" accept=".xlsx,.xls,.xlsm" class="rc-file-input" @change="onPickRashozh" ref="inputRashozh" />
          <BkIcon name="import" size="md" />
          <span v-if="!rashozhFile">Загрузить файл расхождений (.xlsx / .xls)</span>
          <span v-else class="rc-upload-label--loaded">{{ rashozhFile.name }}</span>
        </label>
        <button v-if="rashozhFile" class="rc-upload-clear" @click.stop="clearRashozh" title="Очистить">
          <BkIcon name="close" size="sm" />
        </button>
      </div>

      <!-- Сверка без файла расхождений: сразу к разбору двух файлов -->
      <button class="rc-btn rc-btn--secondary rc-btn--sm rc-direct-btn" @click="goToDetail(null)">
        <BkIcon name="arrowLeftRight" size="sm" />
        Сверить два файла без списка расхождений
      </button>

      <!-- Ошибка парсинга -->
      <div v-if="rashozhError" class="rc-error">
        <BkIcon name="error" size="sm" />
        <span>{{ rashozhError }}</span>
      </div>

      <!-- Состояние загрузки -->
      <div v-if="rashozhLoading" class="rc-loading">
        <BkIcon name="loading" size="md" class="bk-i-spin" />
        <span>Читаю файл расхождений…</span>
      </div>

      <!-- Пустое состояние — нет файла -->
      <UiEmptyState v-else-if="!rashozhItems.length && !rashozhError && !rashozhLoading"
                    title="Загрузите файл расхождений"
                    description="Файл выгружается из УТ — список товаров, у которых количество не совпадает между системами. Либо сверьте два файла напрямую, без списка."
                    action-label="Сверить без списка расхождений"
                    @action="goToDetail(null)">
        <template #icon>
          <BkIcon name="oneC" size="lg" />
        </template>
      </UiEmptyState>

      <!-- Таблица расхождений -->
      <template v-else-if="rashozhItems.length">
        <div class="rc-table-meta">
          <span class="rc-table-count">{{ rashozhItems.length }} позиций с расхождением</span>
          <button class="rc-btn rc-btn--secondary rc-btn--sm" @click="goToDetail(null)">
            <BkIcon name="arrowLeftRight" size="sm" />
            Сверить без списка
          </button>
        </div>

        <div class="rc-table-wrap">
          <table class="rc-table">
            <thead>
              <tr>
                <th>Артикул</th>
                <th>Наименование</th>
                <th>Ед.</th>
                <th class="rc-col-num">Кол-во УТ</th>
                <th class="rc-col-num">Кол-во 1С</th>
                <th class="rc-col-num">Расхождение</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in rashozhItems" :key="item.sku"
                  class="rc-table-row--clickable"
                  @click="goToDetail(item)">
                <td class="rc-col-sku">{{ item.sku }}</td>
                <td>{{ item.name }}</td>
                <td class="rc-col-unit">{{ item.unit }}</td>
                <td class="rc-col-num">{{ fmtNum(item.qtyUt) }}</td>
                <td class="rc-col-num">{{ fmtNum(item.qtyBuh) }}</td>
                <td class="rc-col-num" :class="diffClass(item.diff)">
                  {{ fmtDiff(item.diff) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </template>

    <!-- ═══════════════════════════════════════════════════════════
         ЭКРАН 2: разбор конкретного товара
         ═══════════════════════════════════════════════════════════ -->
    <template v-else-if="screen === 'detail'">
      <div class="rc-header rc-header--detail">
        <button class="rc-back-btn" @click="goBack">
          <BkIcon name="chevronLeft" size="sm" />
          Назад к списку
        </button>
        <div>
          <h1 class="rc-title">{{ currentItem ? currentItem.name : 'Сверка перемещений' }}</h1>
          <p v-if="currentItem" class="rc-subtitle">
            Артикул {{ currentItem.sku }} · {{ currentItem.unit }} ·
            расхождение: <span :class="diffClass(currentItem.diff)">{{ fmtDiff(currentItem.diff) }}</span>
          </p>
          <p v-else class="rc-subtitle">Загрузите файлы перемещений из обеих систем для сверки</p>
        </div>
      </div>

      <!-- Два слота загрузки -->
      <div class="rc-files-row">
        <!-- Файл 1С -->
        <div class="rc-file-slot" :class="{ 'rc-file-slot--loaded': file1c, 'rc-file-slot--error': error1c }">
          <div class="rc-file-slot-header">
            <BkIcon name="oneC" size="sm" />
            <span class="rc-file-slot-label">Перемещения из 1С</span>
          </div>
          <label class="rc-upload-label rc-upload-label--compact">
            <input type="file" accept=".xlsx,.xls,.xlsm" class="rc-file-input" @change="onPick1c" ref="input1c" />
            <BkIcon name="import" size="sm" />
            <span v-if="!file1c">Выбрать файл</span>
            <span v-else class="rc-upload-label--loaded">{{ file1c.name }}</span>
          </label>
          <button v-if="file1c" class="rc-upload-clear rc-upload-clear--sm" @click.stop="clear1c" title="Удалить">
            <BkIcon name="close" size="sm" />
          </button>
          <div v-if="loading1c" class="rc-file-slot-status">
            <BkIcon name="loading" size="sm" class="bk-i-spin" /> Читаю…
          </div>
          <div v-if="error1c" class="rc-file-slot-err">{{ error1c }}</div>
        </div>

        <!-- Иконка между слотами -->
        <div class="rc-files-sep">
          <BkIcon name="arrowLeftRight" size="md" />
        </div>

        <!-- Файл УТ -->
        <div class="rc-file-slot" :class="{ 'rc-file-slot--loaded': fileUt, 'rc-file-slot--error': errorUt }">
          <div class="rc-file-slot-header">
            <BkIcon name="database" size="sm" />
            <span class="rc-file-slot-label">Перемещения из УТ</span>
          </div>
          <label class="rc-upload-label rc-upload-label--compact">
            <input type="file" accept=".xlsx,.xls,.xlsm" class="rc-file-input" @change="onPickUt" ref="inputUt" />
            <BkIcon name="import" size="sm" />
            <span v-if="!fileUt">Выбрать файл</span>
            <span v-else class="rc-upload-label--loaded">{{ fileUt.name }}</span>
          </label>
          <button v-if="fileUt" class="rc-upload-clear rc-upload-clear--sm" @click.stop="clearUt" title="Удалить">
            <BkIcon name="close" size="sm" />
          </button>
          <div v-if="loadingUt" class="rc-file-slot-status">
            <BkIcon name="loading" size="sm" class="bk-i-spin" /> Читаю…
          </div>
          <div v-if="errorUt" class="rc-file-slot-err">{{ errorUt }}</div>
        </div>
      </div>

      <!-- Пустое состояние — ещё нет обоих файлов -->
      <UiEmptyState v-if="!compareResult && !loading1c && !loadingUt && !(file1c && fileUt)"
                    title="Загрузите оба файла для сверки"
                    description="Нужны два файла: перемещения из 1С и перемещения из УТ по этому товару. Как только оба загружены — сверка появится автоматически.">
        <template #icon>
          <BkIcon name="arrowLeftRight" size="lg" />
        </template>
      </UiEmptyState>

      <!-- Идёт разбор -->
      <div v-else-if="(loading1c || loadingUt)" class="rc-loading">
        <BkIcon name="loading" size="md" class="bk-i-spin" />
        <span>Обрабатываю файлы…</span>
      </div>

      <!-- Результат сверки -->
      <template v-else-if="compareResult">
        <!-- Итог по товару: баланс прихода/расхода -->
        <div class="rc-summary">
          <table class="rc-sum-table">
            <thead>
              <tr><th></th><th class="rc-col-num">1С</th><th class="rc-col-num">УТ</th><th class="rc-col-num">Разница</th></tr>
            </thead>
            <tbody>
              <tr>
                <td>Начальный остаток</td>
                <td class="rc-col-num">{{ fmtNum(compareResult.totals.nachalo1c) }}</td>
                <td class="rc-col-num">{{ fmtNum(compareResult.totals.nachaloUt) }}</td>
                <td class="rc-col-num" :class="diffClass(compareResult.totals.nachaloDiff)">{{ fmtDiff(compareResult.totals.nachaloDiff) }}</td>
              </tr>
              <tr>
                <td>Приход</td>
                <td class="rc-col-num">{{ fmtNum(compareResult.totals.prihod1c) }}</td>
                <td class="rc-col-num">{{ fmtNum(compareResult.totals.prihodUt) }}</td>
                <td class="rc-col-num" :class="diffClass(compareResult.totals.diffPrihod)">{{ fmtDiff(compareResult.totals.diffPrihod) }}</td>
              </tr>
              <tr>
                <td>Расход</td>
                <td class="rc-col-num">{{ fmtNum(compareResult.totals.rashod1c) }}</td>
                <td class="rc-col-num">{{ fmtNum(compareResult.totals.rashodUt) }}</td>
                <td class="rc-col-num" :class="diffClass(compareResult.totals.diffRashod)">{{ fmtDiff(compareResult.totals.diffRashod) }}</td>
              </tr>
              <tr class="rc-sum-konec">
                <td>Конечный остаток</td>
                <td class="rc-col-num">{{ fmtNum(compareResult.totals.konec1c) }}</td>
                <td class="rc-col-num">{{ fmtNum(compareResult.totals.konecUt) }}</td>
                <td class="rc-col-num" :class="diffClass(round2(compareResult.totals.konec1c - compareResult.totals.konecUt))">{{ fmtDiff(round2(compareResult.totals.konec1c - compareResult.totals.konecUt)) }}</td>
              </tr>
            </tbody>
          </table>
          <div class="rc-ostatok" :class="compareResult.totals.ostatokDiff === 0 ? 'rc-ostatok--ok' : 'rc-ostatok--bad'">
            <BkIcon :name="compareResult.totals.ostatokDiff === 0 ? 'success' : 'warning'" size="sm" />
            <span>Расхождение остатка (УТ − 1С):</span>
            <strong>{{ fmtDiff(compareResult.totals.ostatokDiff) }}</strong>
          </div>
        </div>

        <!-- Счётчики перемещений -->
        <div class="rc-stats">
          <div class="rc-stat rc-stat--success">
            <div class="rc-stat-count">{{ compareResult.moves.counts.matched }}</div>
            <div class="rc-stat-label">Перемещений совпало</div>
          </div>
          <div class="rc-stat rc-stat--warning">
            <div class="rc-stat-count">{{ compareResult.moves.counts.qtyDiff }}</div>
            <div class="rc-stat-label">Разное количество</div>
          </div>
          <div class="rc-stat rc-stat--danger">
            <div class="rc-stat-count">{{ compareResult.moves.counts.onlyIn1c }}</div>
            <div class="rc-stat-label">Только в 1С</div>
          </div>
          <div class="rc-stat rc-stat--danger">
            <div class="rc-stat-count">{{ compareResult.moves.counts.onlyInUt }}</div>
            <div class="rc-stat-label">Только в УТ</div>
          </div>
          <div class="rc-stat rc-stat--warning">
            <div class="rc-stat-count">{{ compareResult.moves.counts.dateDiff }}</div>
            <div class="rc-stat-label">Разная дата</div>
          </div>
        </div>

        <!-- Поиск по номеру перемещения -->
        <div class="rc-search-row">
          <div class="rc-search-wrap">
            <BkIcon name="search" size="sm" class="rc-search-icon" />
            <input v-model="searchQuery"
                   type="text"
                   class="rc-search-input"
                   placeholder="Фильтр по номеру перемещения…" />
            <button v-if="searchQuery" class="rc-search-clear" @click="searchQuery = ''" title="Очистить">
              <BkIcon name="close" size="sm" />
            </button>
          </div>
          <button class="rc-btn rc-btn--primary" @click="doExport" :disabled="exporting">
            <BkIcon name="excel" size="sm" />
            {{ exporting ? 'Формирую…' : 'Скачать Excel' }}
          </button>
        </div>

        <!-- Секции результатов -->
        <div class="rc-sections">
          <!-- Совпало -->
          <div v-if="filteredMatched.length" class="rc-section">
            <div class="rc-section-head rc-section-head--success" @click="toggleSection('matched')">
              <BkIcon name="success" size="sm" />
              <span>Совпало — {{ filteredMatched.length }}</span>
              <BkIcon :name="openSections.matched ? 'chevronUp' : 'chevronDown'" size="sm" class="rc-section-toggle" />
            </div>
            <div v-if="openSections.matched" class="rc-section-body">
              <div class="rc-table-wrap">
                <table class="rc-table rc-table--compact">
                  <thead>
                    <tr>
                      <th>Номер перемещения</th>
                      <th class="rc-col-num">Приход</th>
                      <th class="rc-col-num">Расход</th>
                      <th>Дата (1С)</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="row in filteredMatched" :key="row.number" :class="{ 'rc-row--warn': row.dateDiff }">
                      <td class="rc-col-mono">{{ row.number }}</td>
                      <td class="rc-col-num">{{ fmtNum(row.prihod) }}</td>
                      <td class="rc-col-num">{{ fmtNum(row.rashod) }}</td>
                      <td>
                        {{ row.date1c || '—' }}
                        <span v-if="row.dateDiff" class="rc-hint">в УТ: {{ row.dateUt }}</span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Разное количество -->
          <div v-if="filteredQtyDiff.length" class="rc-section">
            <div class="rc-section-head rc-section-head--warning" @click="toggleSection('qtyDiff')">
              <BkIcon name="warning" size="sm" />
              <span>Разное количество — {{ filteredQtyDiff.length }}</span>
              <BkIcon :name="openSections.qtyDiff ? 'chevronUp' : 'chevronDown'" size="sm" class="rc-section-toggle" />
            </div>
            <div v-if="openSections.qtyDiff" class="rc-section-body">
              <div class="rc-table-wrap">
                <table class="rc-table rc-table--compact">
                  <thead>
                    <tr>
                      <th>Номер перемещения</th>
                      <th class="rc-col-num">Приход 1С</th>
                      <th class="rc-col-num">Расход 1С</th>
                      <th class="rc-col-num">Приход УТ</th>
                      <th class="rc-col-num">Расход УТ</th>
                      <th class="rc-col-num">Разница расх.</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="row in filteredQtyDiff" :key="row.number">
                      <td class="rc-col-mono">{{ row.number }}</td>
                      <td class="rc-col-num">{{ fmtNum(row.prihod1c) }}</td>
                      <td class="rc-col-num">{{ fmtNum(row.rashod1c) }}</td>
                      <td class="rc-col-num">{{ fmtNum(row.prihodUt) }}</td>
                      <td class="rc-col-num">{{ fmtNum(row.rashodUt) }}</td>
                      <td class="rc-col-num" :class="diffClass(row.diffRashod)">{{ fmtDiff(row.diffRashod) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Только в 1С -->
          <div v-if="filteredOnly1c.length" class="rc-section">
            <div class="rc-section-head rc-section-head--danger" @click="toggleSection('only1c')">
              <BkIcon name="error" size="sm" />
              <span>Только в 1С — {{ filteredOnly1c.length }}</span>
              <BkIcon :name="openSections.only1c ? 'chevronUp' : 'chevronDown'" size="sm" class="rc-section-toggle" />
            </div>
            <div v-if="openSections.only1c" class="rc-section-body">
              <div class="rc-table-wrap">
                <table class="rc-table rc-table--compact">
                  <thead>
                    <tr>
                      <th>Номер перемещения</th>
                      <th class="rc-col-num">Приход</th>
                      <th class="rc-col-num">Расход</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="row in filteredOnly1c" :key="row.number">
                      <td class="rc-col-mono">
                        {{ row.number }}
                        <span v-if="row.likelySame" class="rc-hint">вероятно тот же, другой №</span>
                      </td>
                      <td class="rc-col-num">{{ fmtNum(row.prihod) }}</td>
                      <td class="rc-col-num">{{ fmtNum(row.rashod) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Только в УТ -->
          <div v-if="filteredOnlyUt.length" class="rc-section">
            <div class="rc-section-head rc-section-head--danger" @click="toggleSection('onlyUt')">
              <BkIcon name="error" size="sm" />
              <span>Только в УТ — {{ filteredOnlyUt.length }}</span>
              <BkIcon :name="openSections.onlyUt ? 'chevronUp' : 'chevronDown'" size="sm" class="rc-section-toggle" />
            </div>
            <div v-if="openSections.onlyUt" class="rc-section-body">
              <div class="rc-table-wrap">
                <table class="rc-table rc-table--compact">
                  <thead>
                    <tr>
                      <th>Номер перемещения</th>
                      <th class="rc-col-num">Приход</th>
                      <th class="rc-col-num">Расход</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="row in filteredOnlyUt" :key="row.number">
                      <td class="rc-col-mono">
                        {{ row.number }}
                        <span v-if="row.likelySame" class="rc-hint">вероятно тот же, другой №</span>
                      </td>
                      <td class="rc-col-num">{{ fmtNum(row.prihod) }}</td>
                      <td class="rc-col-num">{{ fmtNum(row.rashod) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Совпало по количеству, но разная дата -->
          <div v-if="filteredDateMismatch.length" class="rc-section">
            <div class="rc-section-head rc-section-head--warning" @click="toggleSection('dateDiff')">
              <BkIcon name="warning" size="sm" />
              <span>Совпало по количеству, но разная дата — {{ filteredDateMismatch.length }}</span>
              <BkIcon :name="openSections.dateDiff ? 'chevronUp' : 'chevronDown'" size="sm" class="rc-section-toggle" />
            </div>
            <div v-if="openSections.dateDiff" class="rc-section-body">
              <p class="rc-others-note">Количество сходится, но дата документа в 1С (бухгалтерия) и УТ разная. Главная — дата из 1С.</p>
              <div class="rc-table-wrap">
                <table class="rc-table rc-table--compact">
                  <thead>
                    <tr>
                      <th>Номер перемещения</th>
                      <th class="rc-col-num">Расход</th>
                      <th>Дата 1С (бух)</th>
                      <th>Дата УТ</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="row in filteredDateMismatch" :key="row.number">
                      <td class="rc-col-mono">{{ row.number }}</td>
                      <td class="rc-col-num">{{ fmtNum(row.rashod) }}</td>
                      <td>{{ row.date1c || '—' }}</td>
                      <td class="rc-diff--negative">{{ row.dateUt || '—' }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Прочие документы (по итогам) -->
          <div v-if="hasOthers" class="rc-section">
            <div class="rc-section-head" :class="othersHasDiff ? 'rc-section-head--danger' : 'rc-section-head--success'" @click="toggleSection('others')">
              <BkIcon :name="othersHasDiff ? 'warning' : 'success'" size="sm" />
              <span>Прочие документы по итогам{{ othersHasDiff ? ' — есть расхождение' : ' — сходятся' }}</span>
              <BkIcon :name="openSections.others ? 'chevronUp' : 'chevronDown'" size="sm" class="rc-section-toggle" />
            </div>
            <div v-if="openSections.others" class="rc-section-body">
              <p class="rc-others-note">Поступления, возвраты, списания и т.п. — номера в 1С и УТ разные, поэтому сверяются по суммам. Типы приведены к общему (напр. «Поступление ТМЦ» в 1С = «Приобретение товаров и услуг» в УТ).</p>
              <div class="rc-table-wrap">
                <table class="rc-table rc-table--compact">
                  <thead>
                    <tr>
                      <th>Тип документа</th>
                      <th class="rc-col-num">Приход 1С</th>
                      <th class="rc-col-num">Расход 1С</th>
                      <th class="rc-col-num">Приход УТ</th>
                      <th class="rc-col-num">Расход УТ</th>
                      <th class="rc-col-num">Разн. расх.</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="c in compareResult.others.byCategory" :key="c.category"
                        :class="{ 'rc-row--bad': c.diffPrihod !== 0 || c.diffRashod !== 0 }">
                      <td>{{ c.category }}</td>
                      <td class="rc-col-num">{{ fmtNum(c.prihod1c) }}</td>
                      <td class="rc-col-num">{{ fmtNum(c.rashod1c) }}</td>
                      <td class="rc-col-num">{{ fmtNum(c.prihodUt) }}</td>
                      <td class="rc-col-num">{{ fmtNum(c.rashodUt) }}</td>
                      <td class="rc-col-num" :class="diffClass(c.diffRashod)">{{ fmtDiff(c.diffRashod) }}</td>
                    </tr>
                    <tr class="rc-others-diff-row">
                      <td>Итого (1С / УТ)</td>
                      <td class="rc-col-num">{{ fmtNum(compareResult.others.total1c.prihod) }}</td>
                      <td class="rc-col-num">{{ fmtNum(compareResult.others.total1c.rashod) }}</td>
                      <td class="rc-col-num">{{ fmtNum(compareResult.others.totalUt.prihod) }}</td>
                      <td class="rc-col-num">{{ fmtNum(compareResult.others.totalUt.rashod) }}</td>
                      <td class="rc-col-num" :class="diffClass(compareResult.others.diffRashod)">{{ fmtDiff(compareResult.others.diffRashod) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Поиск по перемещению ничего не нашёл -->
          <UiEmptyState v-if="searchQuery && !filteredMatched.length && !filteredQtyDiff.length && !filteredOnly1c.length && !filteredOnlyUt.length"
                        title="Ничего не найдено"
                        description="Нет перемещений с таким номером.">
            <template #icon>
              <BkIcon name="search" size="lg" />
            </template>
          </UiEmptyState>
        </div>
      </template>
    </template>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue';
import BkIcon from '@/components/ui/BkIcon.vue';
import UiEmptyState from '@/components/ui/UiEmptyState.vue';
import {
  parseRashozhdeniya,
  parse1cMovements,
  parseUtMovements,
  compareMovements,
  exportReconcileExcel,
} from '@/lib/reconcile1cUt.js';

// ─── Экраны ───────────────────────────────────────────────────────────────────
const screen = ref('list');   // 'list' | 'detail'
const currentItem = ref(null);

function goToDetail(item) {
  currentItem.value = item;
  screen.value = 'detail';
}

function goBack() {
  screen.value = 'list';
}

// ─── Экран 1: файл расхождений ────────────────────────────────────────────────
const inputRashozh = ref(null);
const rashozhFile  = ref(null);
const rashozhItems = ref([]);
const rashozhLoading = ref(false);
const rashozhError   = ref('');
const dragOver1      = ref(false);

async function loadRashozh(file) {
  rashozhFile.value  = file;
  rashozhError.value = '';
  rashozhItems.value = [];
  rashozhLoading.value = true;
  try {
    const result = await parseRashozhdeniya(file);
    if (result.ok) {
      rashozhItems.value = result.items;
    } else {
      rashozhError.value = result.error || 'Не удалось прочитать файл расхождений.';
    }
  } catch (e) {
    rashozhError.value = 'Ошибка при чтении файла: ' + (e?.message || e);
  } finally {
    rashozhLoading.value = false;
  }
}

function onPickRashozh(e) {
  const f = e.target.files[0];
  if (f) loadRashozh(f);
}

function onDropRashozh(e) {
  dragOver1.value = false;
  const f = e.dataTransfer.files[0];
  if (f) loadRashozh(f);
}

function clearRashozh() {
  rashozhFile.value = null;
  rashozhItems.value = [];
  rashozhError.value = '';
  if (inputRashozh.value) inputRashozh.value.value = '';
}

// ─── Экран 2: файлы перемещений ───────────────────────────────────────────────
const input1c   = ref(null);
const inputUt   = ref(null);
const file1c    = ref(null);
const fileUt    = ref(null);
const loading1c = ref(false);
const loadingUt = ref(false);
const error1c   = ref('');
const errorUt   = ref('');
const map1c     = ref(null);
const mapUt     = ref(null);

const compareResult = ref(null);

// Пересчитываем сверку когда оба файла готовы
watch([map1c, mapUt], ([m1, mU]) => {
  if (m1 && mU) {
    compareResult.value = compareMovements(m1, mU);
    // Раскрываем секции с расхождениями по умолчанию
    const cnt = compareResult.value.moves.counts;
    if (cnt.qtyDiff > 0) openSections.qtyDiff = true;
    if (cnt.onlyIn1c > 0) openSections.only1c = true;
    if (cnt.onlyInUt > 0) openSections.onlyUt = true;
    if (cnt.dateDiff > 0) openSections.dateDiff = true;
    const o = compareResult.value.others;
    if (o.diffPrihod !== 0 || o.diffRashod !== 0) openSections.others = true;
  } else {
    compareResult.value = null;
  }
});

async function onPick1c(e) {
  const f = e.target.files[0];
  if (!f) return;
  file1c.value  = f;
  error1c.value = '';
  map1c.value   = null;
  loading1c.value = true;
  try {
    const result = await parse1cMovements(f);
    if (result.ok) {
      map1c.value = result;
    } else {
      error1c.value = result.error || 'Не удалось прочитать файл 1С.';
    }
  } catch (e) {
    error1c.value = 'Ошибка: ' + (e?.message || e);
  } finally {
    loading1c.value = false;
  }
}

async function onPickUt(e) {
  const f = e.target.files[0];
  if (!f) return;
  fileUt.value  = f;
  errorUt.value = '';
  mapUt.value   = null;
  loadingUt.value = true;
  try {
    const result = await parseUtMovements(f);
    if (result.ok) {
      mapUt.value = result;
    } else {
      errorUt.value = result.error || 'Не удалось прочитать файл УТ.';
    }
  } catch (e) {
    errorUt.value = 'Ошибка: ' + (e?.message || e);
  } finally {
    loadingUt.value = false;
  }
}

function clear1c() {
  file1c.value = null;
  map1c.value  = null;
  error1c.value = '';
  if (input1c.value) input1c.value.value = '';
}

function clearUt() {
  fileUt.value = null;
  mapUt.value  = null;
  errorUt.value = '';
  if (inputUt.value) inputUt.value.value = '';
}

// ─── Поиск ────────────────────────────────────────────────────────────────────
const searchQuery = ref('');

function filterByQuery(arr) {
  if (!searchQuery.value.trim()) return arr;
  const q = searchQuery.value.trim().toLowerCase();
  return arr.filter(r => r.number.toLowerCase().includes(q));
}

const filteredMatched  = computed(() => compareResult.value ? filterByQuery(compareResult.value.moves.matched)  : []);
const filteredQtyDiff  = computed(() => compareResult.value ? filterByQuery(compareResult.value.moves.qtyDiff)  : []);
const filteredOnly1c   = computed(() => compareResult.value ? filterByQuery(compareResult.value.moves.onlyIn1c) : []);
const filteredOnlyUt   = computed(() => compareResult.value ? filterByQuery(compareResult.value.moves.onlyInUt) : []);
const filteredDateMismatch = computed(() => compareResult.value ? filterByQuery(compareResult.value.moves.dateMismatch) : []);

// Прочие документы (по итогам)
const hasOthers     = computed(() => compareResult.value && (compareResult.value.others.list1c.length || compareResult.value.others.listUt.length));
const othersHasDiff = computed(() => compareResult.value && (compareResult.value.others.diffPrihod !== 0 || compareResult.value.others.diffRashod !== 0));

// ─── Аккордеон секций ─────────────────────────────────────────────────────────
const openSections = reactive({
  matched:  false,
  qtyDiff:  false,
  only1c:   false,
  onlyUt:   false,
  dateDiff: false,
  others:   false,
});

function toggleSection(key) {
  openSections[key] = !openSections[key];
}

// ─── Экспорт ──────────────────────────────────────────────────────────────────
const exporting = ref(false);

async function doExport() {
  if (!compareResult.value) return;
  exporting.value = true;
  try {
    await exportReconcileExcel(
      currentItem.value ? `${currentItem.value.sku} ${currentItem.value.name}`.trim() : 'Сверка',
      compareResult.value,
    );
  } catch (e) {
    // Ошибку экспорта выводим в консоль (файл всё равно попытается скачаться)
    console.error('Ошибка экспорта:', e);
  } finally {
    exporting.value = false;
  }
}

// ─── Форматирование ───────────────────────────────────────────────────────────
function round2(n) {
  return Math.round(Number(n) * 100) / 100;
}
function fmtNum(v) {
  if (v == null) return '—';
  return Number(v).toLocaleString('ru-RU');
}

function fmtDiff(v) {
  if (v == null) return '—';
  const n = Number(v);
  if (n === 0) return '0';
  return (n > 0 ? '+' : '') + n.toLocaleString('ru-RU');
}

function diffClass(v) {
  const n = Number(v);
  if (n > 0) return 'rc-diff--positive';
  if (n < 0) return 'rc-diff--negative';
  return '';
}
</script>

<style scoped>
/* ─── Обёртка страницы ─────────────────────────────────────────────────────── */
.rc {
  padding: var(--tk-s-6) var(--tk-s-6) var(--tk-s-7);
  max-width: 1100px;
  font-family: var(--tk-font);
  color: var(--tk-text);
}

/* ─── Шапка ────────────────────────────────────────────────────────────────── */
.rc-header {
  margin-bottom: var(--tk-s-6);
}
.rc-header--detail {
  display: flex;
  align-items: flex-start;
  gap: var(--tk-s-4);
}
.rc-title {
  margin: 0 0 var(--tk-s-1);
  font-size: var(--tk-fz-h1);
  font-weight: var(--tk-fw-bold);
  color: var(--tk-text);
  line-height: var(--tk-lh-tight);
}
.rc-subtitle {
  margin: 0;
  font-size: var(--tk-fz-md);
  color: var(--tk-text-muted);
  line-height: var(--tk-lh-base);
}

/* ─── Кнопка «Назад» ──────────────────────────────────────────────────────── */
.rc-back-btn {
  display: inline-flex;
  align-items: center;
  gap: var(--tk-s-1);
  padding: var(--tk-s-2) var(--tk-s-3);
  background: transparent;
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-md);
  color: var(--tk-text-secondary);
  font-family: inherit;
  font-size: var(--tk-fz-md);
  cursor: pointer;
  white-space: nowrap;
  min-height: var(--tk-touch-min);
  transition: background var(--tk-anim-fast) ease, border-color var(--tk-anim-fast) ease;
}
.rc-back-btn:hover {
  background: var(--tk-n-100);
  border-color: var(--tk-n-300);
}

/* ─── Зона загрузки файла (экран 1) ────────────────────────────────────────── */
.rc-upload-zone {
  position: relative;
  display: flex;
  align-items: center;
  gap: var(--tk-s-3);
  border: 2px dashed var(--tk-border);
  border-radius: var(--tk-r-card);
  padding: var(--tk-s-5) var(--tk-s-6);
  margin-bottom: var(--tk-s-5);
  background: var(--tk-n-50);
  transition: border-color var(--tk-anim-fast) ease, background var(--tk-anim-fast) ease;
}
.rc-upload-zone--active {
  border-color: var(--tk-accent);
  background: var(--tk-accent-soft);
}
.rc-upload-label {
  display: inline-flex;
  align-items: center;
  gap: var(--tk-s-2);
  cursor: pointer;
  color: var(--tk-accent);
  font-size: var(--tk-fz-lg);
  font-weight: var(--tk-fw-medium);
  min-height: var(--tk-touch-min);
}
.rc-upload-label--loaded {
  color: var(--tk-text);
  font-weight: var(--tk-fw-regular);
}
.rc-upload-label--compact {
  font-size: var(--tk-fz-md);
}
.rc-file-input {
  position: absolute;
  width: 0;
  height: 0;
  opacity: 0;
  pointer-events: none;
}
.rc-upload-clear {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  padding: 0;
  background: transparent;
  border: none;
  border-radius: var(--tk-r-pill);
  cursor: pointer;
  color: var(--tk-text-muted);
  transition: background var(--tk-anim-fast) ease;
  flex-shrink: 0;
}
.rc-upload-clear:hover { background: var(--tk-n-100); }
.rc-upload-clear--sm { width: 24px; height: 24px; }

/* ─── Ошибка / загрузка ─────────────────────────────────────────────────────── */
.rc-error {
  display: flex;
  align-items: center;
  gap: var(--tk-s-2);
  padding: var(--tk-s-3) var(--tk-s-4);
  background: var(--tk-danger-soft);
  border: 1px solid var(--tk-danger);
  border-radius: var(--tk-r-md);
  color: var(--tk-danger);
  font-size: var(--tk-fz-md);
  margin-bottom: var(--tk-s-4);
}
.rc-loading {
  display: flex;
  align-items: center;
  gap: var(--tk-s-3);
  padding: var(--tk-s-5) 0;
  color: var(--tk-text-muted);
  font-size: var(--tk-fz-md);
}

/* ─── Мета над таблицей ──────────────────────────────────────────────────────── */
.rc-table-meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: var(--tk-s-3);
  flex-wrap: wrap;
  gap: var(--tk-s-2);
}
.rc-table-count {
  font-size: var(--tk-fz-md);
  color: var(--tk-text-muted);
}

/* ─── Кнопки ─────────────────────────────────────────────────────────────────── */
.rc-btn {
  display: inline-flex;
  align-items: center;
  gap: var(--tk-s-2);
  padding: var(--tk-s-2) var(--tk-s-4);
  border-radius: var(--tk-r-md);
  font-family: inherit;
  font-size: var(--tk-fz-md);
  font-weight: var(--tk-fw-medium);
  cursor: pointer;
  min-height: var(--tk-touch-min);
  box-sizing: border-box;
  transition: background var(--tk-anim-fast) ease, border-color var(--tk-anim-fast) ease;
  white-space: nowrap;
}
.rc-btn--primary {
  background: var(--tk-accent);
  color: #fff;
  border: none;
}
.rc-btn--primary:hover { background: var(--tk-accent-hover); }
.rc-btn--primary:disabled { opacity: .6; cursor: default; }
.rc-btn--secondary {
  background: transparent;
  color: var(--tk-text-secondary);
  border: 1px solid var(--tk-border);
}
.rc-btn--secondary:hover { background: var(--tk-n-100); border-color: var(--tk-n-300); }
.rc-btn--sm { min-height: 36px; padding: var(--tk-s-1) var(--tk-s-3); }

/* ─── Таблица ────────────────────────────────────────────────────────────────── */
.rc-table-wrap {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  border-radius: var(--tk-r-card);
  border: 1px solid var(--tk-border);
  box-shadow: var(--tk-shadow-card);
  margin-bottom: var(--tk-s-5);
}
.rc-table {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--tk-fz-lg);
  background: var(--tk-bg-card);
}
.rc-table thead tr {
  background: var(--tk-text);
}
.rc-table th {
  padding: var(--tk-s-3) var(--tk-s-4);
  text-align: left;
  font-size: var(--tk-fz-sm);
  font-weight: var(--tk-fw-semibold);
  color: #ffffff;
  border-bottom: 1px solid var(--tk-border);
  white-space: nowrap;
}
.rc-table td {
  padding: var(--tk-s-3) var(--tk-s-4);
  border-bottom: 1px solid var(--tk-border-soft);
  vertical-align: middle;
}
.rc-table tbody tr:last-child td {
  border-bottom: none;
}
.rc-table--compact td,
.rc-table--compact th {
  padding: var(--tk-s-2) var(--tk-s-3);
}
.rc-table-row--clickable {
  cursor: pointer;
  transition: background var(--tk-anim-fast) ease;
}
.rc-table-row--clickable:hover {
  background: var(--tk-accent-soft);
}
.rc-col-num {
  text-align: right;
  white-space: nowrap;
}
.rc-col-sku {
  font-size: var(--tk-fz-sm);
  color: var(--tk-text-muted);
  white-space: nowrap;
}
.rc-col-unit {
  color: var(--tk-text-muted);
  font-size: var(--tk-fz-sm);
}
.rc-col-mono {
  font-family: 'Courier New', monospace;
  font-size: var(--tk-fz-sm);
}

/* ─── Цвета расхождений ──────────────────────────────────────────────────────── */
.rc-diff--positive {
  color: var(--tk-success);
  font-weight: var(--tk-fw-semibold);
}
.rc-diff--negative {
  color: var(--tk-danger);
  font-weight: var(--tk-fw-semibold);
}

/* ─── Экран 2: слоты файлов ───────────────────────────────────────────────────── */
.rc-files-row {
  display: flex;
  align-items: flex-start;
  gap: var(--tk-s-4);
  margin-bottom: var(--tk-s-6);
}
.rc-files-sep {
  display: flex;
  align-items: center;
  padding-top: var(--tk-s-6);
  color: var(--tk-text-muted);
  flex-shrink: 0;
}
.rc-file-slot {
  position: relative;
  flex: 1;
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-card);
  padding: var(--tk-s-4);
  background: var(--tk-bg-card);
  box-shadow: var(--tk-shadow-card);
  transition: border-color var(--tk-anim-fast) ease;
}
.rc-file-slot--loaded {
  border-color: var(--tk-accent);
}
.rc-file-slot--error {
  border-color: var(--tk-danger);
}
.rc-file-slot-header {
  display: flex;
  align-items: center;
  gap: var(--tk-s-2);
  margin-bottom: var(--tk-s-3);
}
.rc-file-slot-label {
  font-size: var(--tk-fz-md);
  font-weight: var(--tk-fw-semibold);
  color: var(--tk-text-secondary);
}
.rc-file-slot-status {
  display: flex;
  align-items: center;
  gap: var(--tk-s-2);
  font-size: var(--tk-fz-sm);
  color: var(--tk-text-muted);
  margin-top: var(--tk-s-2);
}
.rc-file-slot-err {
  font-size: var(--tk-fz-sm);
  color: var(--tk-danger);
  margin-top: var(--tk-s-2);
}

/* ─── Счётчики-карточки ───────────────────────────────────────────────────────── */
.rc-stats {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: var(--tk-s-3);
  margin-bottom: var(--tk-s-5);
}
.rc-stat {
  border-radius: var(--tk-r-card);
  padding: var(--tk-s-4) var(--tk-s-4);
  text-align: center;
  box-shadow: var(--tk-shadow-card);
}
.rc-stat--success {
  background: var(--tk-success-soft);
  border: 1px solid var(--tk-success);
}
.rc-stat--warning {
  background: var(--tk-warning-soft);
  border: 1px solid var(--tk-warning);
}
.rc-stat--danger {
  background: var(--tk-danger-soft);
  border: 1px solid var(--tk-danger);
}
.rc-stat-count {
  font-size: var(--tk-fz-h2);
  font-weight: var(--tk-fw-bold);
  line-height: var(--tk-lh-tight);
}
.rc-stat--success .rc-stat-count { color: var(--tk-success); }
.rc-stat--warning .rc-stat-count { color: var(--tk-warning); }
.rc-stat--danger  .rc-stat-count { color: var(--tk-danger); }
.rc-stat-label {
  font-size: var(--tk-fz-sm);
  color: var(--tk-text-muted);
  margin-top: var(--tk-s-1);
}

/* ─── Поиск ───────────────────────────────────────────────────────────────────── */
.rc-search-row {
  display: flex;
  align-items: center;
  gap: var(--tk-s-3);
  margin-bottom: var(--tk-s-4);
  flex-wrap: wrap;
}
.rc-search-wrap {
  position: relative;
  flex: 1;
  min-width: 200px;
}
.rc-search-icon {
  position: absolute;
  left: var(--tk-s-3);
  top: 50%;
  transform: translateY(-50%);
  pointer-events: none;
  color: var(--tk-n-400);
}
.rc-search-input {
  width: 100%;
  box-sizing: border-box;
  padding: var(--tk-s-2) var(--tk-s-7) var(--tk-s-2) var(--tk-s-7);
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-md);
  font-family: inherit;
  font-size: var(--tk-fz-md);
  color: var(--tk-text);
  background: var(--tk-bg-card);
  min-height: var(--tk-touch-min);
  transition: border-color var(--tk-anim-fast) ease, box-shadow var(--tk-anim-fast) ease;
  outline: none;
}
.rc-search-input:focus {
  border-color: var(--tk-accent);
  box-shadow: var(--tk-focus-ring);
}
.rc-search-clear {
  position: absolute;
  right: var(--tk-s-2);
  top: 50%;
  transform: translateY(-50%);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  padding: 0;
  background: transparent;
  border: none;
  border-radius: var(--tk-r-pill);
  cursor: pointer;
  color: var(--tk-text-muted);
}
.rc-search-clear:hover { background: var(--tk-n-100); }

/* ─── Секции результатов ─────────────────────────────────────────────────────── */
.rc-sections {
  display: flex;
  flex-direction: column;
  gap: var(--tk-s-3);
}
.rc-section {
  border-radius: var(--tk-r-card);
  border: 1px solid var(--tk-border);
  overflow: hidden;
  box-shadow: var(--tk-shadow-card);
}
.rc-section-head {
  display: flex;
  align-items: center;
  gap: var(--tk-s-2);
  padding: var(--tk-s-3) var(--tk-s-4);
  font-size: var(--tk-fz-md);
  font-weight: var(--tk-fw-semibold);
  cursor: pointer;
  user-select: none;
  transition: background var(--tk-anim-fast) ease;
}
/* Заголовки секций — сплошной цвет категории + БЕЛЫЙ текст и иконка. */
.rc-section-head--success {
  background: var(--tk-success);
  color: #ffffff;
}
.rc-section-head--warning {
  background: var(--tk-warning);
  color: #ffffff;
}
.rc-section-head--danger {
  background: var(--tk-danger);
  color: #ffffff;
}
.rc-section-head--success:hover { filter: brightness(0.96); }
.rc-section-head--warning:hover { filter: brightness(0.96); }
.rc-section-head--danger:hover  { filter: brightness(0.96); }
.rc-section-toggle {
  margin-left: auto;
}
.rc-section-body .rc-table-wrap {
  margin-bottom: 0;
  border-radius: 0;
  border: none;
  border-top: 1px solid var(--tk-border);
  box-shadow: none;
}

/* ─── Мобильная адаптация ────────────────────────────────────────────────────── */
@media (max-width: 600px) {
  .rc {
    padding: var(--tk-s-4) var(--tk-s-3) var(--tk-s-6);
  }
  .rc-header--detail {
    flex-direction: column;
    gap: var(--tk-s-2);
  }
  .rc-files-row {
    flex-direction: column;
  }
  .rc-files-sep {
    padding-top: 0;
    align-self: center;
    transform: rotate(90deg);
  }
  .rc-stats {
    grid-template-columns: repeat(2, 1fr);
  }
  .rc-upload-zone {
    padding: var(--tk-s-4);
  }
  .rc-table-wrap {
    overflow-x: auto;
  }
  .rc-others-cols {
    grid-template-columns: 1fr;
  }
}

/* ─── Итог по товару ────────────────────────────────────────────────────────── */
.rc-summary {
  margin-bottom: var(--tk-s-5);
  padding: var(--tk-s-4);
  background: var(--tk-bg-board);
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-card);
}
.rc-sum-table {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--tk-fz-sm);
}
.rc-sum-table th,
.rc-sum-table td {
  padding: var(--tk-s-2) var(--tk-s-3);
  text-align: left;
  border-bottom: 1px solid var(--tk-border);
}
.rc-sum-table thead tr { background: var(--tk-text); }
.rc-sum-table th { color: #ffffff; font-weight: var(--tk-fw-semibold); }
.rc-sum-table th:first-child { border-top-left-radius: var(--tk-r-sm); }
.rc-sum-table th:last-child { border-top-right-radius: var(--tk-r-sm); }
.rc-sum-table td:first-child { font-weight: var(--tk-fw-semibold); }
.rc-ostatok {
  display: flex;
  align-items: center;
  gap: var(--tk-s-2);
  margin-top: var(--tk-s-3);
  padding: var(--tk-s-3);
  border-radius: var(--tk-r-md);
  font-size: var(--tk-fz-md);
}
.rc-ostatok strong { margin-left: auto; font-size: var(--tk-fz-lg); }
.rc-ostatok--ok  { background: var(--tk-success-soft); color: var(--tk-success); }
.rc-ostatok--bad { background: var(--tk-danger-soft);  color: var(--tk-danger); }

/* ─── Прочие документы ──────────────────────────────────────────────────────── */
.rc-others-note {
  margin: 0 0 var(--tk-s-3);
  font-size: var(--tk-fz-sm);
  color: var(--tk-text-muted);
}
.rc-others-totals { width: auto; margin-bottom: var(--tk-s-4); }
.rc-others-diff-row td { font-weight: var(--tk-fw-bold); border-top: 2px solid var(--tk-border); }
.rc-others-cols {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--tk-s-4);
}
.rc-others-col-head {
  font-size: var(--tk-fz-sm);
  font-weight: var(--tk-fw-semibold);
  color: var(--tk-text-secondary);
  margin-bottom: var(--tk-s-2);
}
.rc-others-empty { color: var(--tk-text-muted); text-align: center; }

.rc-direct-btn { margin: var(--tk-s-3) 0 var(--tk-s-2); }

.rc-hint {
  display: block;
  font-size: var(--tk-fz-xs);
  color: var(--tk-text-muted);
  font-style: italic;
  margin-top: 2px;
}
.rc-row--bad { background: var(--tk-danger-soft); }
.rc-row--warn { background: var(--tk-warning-soft); }
.rc-sum-konec td { font-weight: var(--tk-fw-bold); border-top: 2px solid var(--tk-border); }
</style>
