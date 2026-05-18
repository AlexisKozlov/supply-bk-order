<template>
  <Teleport to="body">
    <div class="task-sidebar-backdrop" @click="close"></div>
    <aside class="task-sidebar" @click.stop :class="{ 'task-sidebar-enter': true }">
      <!-- Loading-скелетон: повторяет визуальный ритм будущей модалки —
           шапка, ряд пилюль, секция описания. Не «крутится спиннер», а
           вырастает похожий каркас. -->
      <div v-if="loading" class="task-sidebar-loader">
        <div class="tsl-header">
          <UiSkeleton width="32px" :height="32" shape="circle"/>
          <div class="tsl-header-text">
            <UiSkeleton width="70%" :height="20"/>
            <UiSkeleton width="40%" :height="12"/>
          </div>
          <UiSkeleton width="32px" :height="32" shape="circle"/>
        </div>
        <div class="tsl-props">
          <UiSkeleton width="80px" :height="24" shape="pill"/>
          <UiSkeleton width="100px" :height="24" shape="pill"/>
          <UiSkeleton width="90px" :height="24" shape="pill"/>
          <UiSkeleton width="70px" :height="24" shape="pill"/>
        </div>
        <div class="tsl-pane">
          <UiSkeleton width="30%" :height="11"/>
          <UiSkeleton width="100%" :height="14"/>
          <UiSkeleton width="100%" :height="14"/>
          <UiSkeleton width="80%" :height="14"/>
        </div>
      </div>
      <template v-else-if="full">
        <!-- Шапка -->
        <header class="ts-header">
          <button v-if="canGoBack" class="ts-back" @click="$emit('go-back')" title="Назад к родителю">
            <TaskIcon name="chevronRight" :size="16" style="transform: rotate(180deg);"/>
          </button>
          <div class="ts-header-titles">
            <button v-if="full.parent" class="ts-parent-link" @click="$emit('go-back')">
              <TaskIcon name="chevronRight" :size="11" style="transform: rotate(180deg);"/>
              <span>{{ full.parent.title }}</span>
            </button>
            <textarea v-model="full.card.title" ref="titleEl" rows="1" class="ts-title-input"
                      @input="autoGrowTitle"
                      @blur="patch({ title: full.card.title })"
                      @keydown.enter.prevent="$event.target.blur()"></textarea>
            <div class="ts-subtitle">
              <template v-if="full.parent">подзадача в «{{ full.parent.title }}»</template>
              <template v-else>в колонке «{{ columnTitle }}»</template>
            </div>
            <div v-if="full.card.created_by" class="ts-created">
              Создал: {{ full.card.created_by }}<template v-if="full.card.owner_name"> · Доска: {{ full.card.owner_name }}</template><template v-if="createdAtText"> · {{ createdAtText }}</template>
            </div>
          </div>
          <div class="ts-header-actions">
            <div class="ts-menu-wrap">
              <button class="ts-menu-btn" :class="{ 'is-open': headerMenuOpen }"
                      @click.stop="headerMenuOpen = !headerMenuOpen" title="Действия">
                <span class="ts-menu-dot"></span>
                <span class="ts-menu-dot"></span>
                <span class="ts-menu-dot"></span>
              </button>
              <div v-if="headerMenuOpen" class="ts-pop ts-pop-menu" v-click-outside-pop="() => headerMenuOpen = false">
                <button type="button" class="ts-pop-menu-item" @click="duplicateCard">
                  <TaskIcon name="archive" :size="13" class="ts-pop-menu-icon"/>
                  <span>Дублировать</span>
                </button>
                <button type="button" class="ts-pop-menu-item" @click="saveAsTemplate">
                  <TaskIcon name="calendar" :size="13" class="ts-pop-menu-icon"/>
                  <span>Сохранить как шаблон</span>
                </button>
                <button type="button" class="ts-pop-menu-item" @click="toggleArchive">
                  <TaskIcon name="archive" :size="13" class="ts-pop-menu-icon"/>
                  <span>{{ isInArchive ? 'Восстановить из архива' : 'Архивировать' }}</span>
                </button>
                <div v-if="canDelete" class="ts-pop-menu-sep"></div>
                <button v-if="canDelete" type="button" class="ts-pop-menu-item is-danger" @click="askDeleteFromMenu">
                  <TaskIcon name="trash" :size="13" class="ts-pop-menu-icon"/>
                  <span>Удалить карточку</span>
                </button>
              </div>
            </div>
            <button class="ts-close" @click="close" title="Закрыть (Esc)">
              <TaskIcon name="close" :size="16"/>
            </button>
          </div>
        </header>

        <!-- Свойства карточки — пилюли с кастомными поповерами -->
        <div class="ts-props">
          <!-- Приоритет -->
          <div class="ts-pill-wrap">
            <button type="button"
                    class="ts-pill" :class="['ts-pill-prio-' + (full.card.priority || 'medium'), { 'is-open': propPopover === 'priority' }]"
                    :title="'Приоритет: ' + priorityLabel(full.card.priority)"
                    @click.stop="togglePropPopover('priority')">
              <span class="ts-pill-icon"><span class="ts-pill-dot"></span></span>
              <span class="ts-pill-text">{{ priorityLabel(full.card.priority) }}</span>
              <TaskIcon name="chevronDown" :size="11" class="ts-pill-chev"/>
            </button>
            <div v-if="propPopover === 'priority'" ref="popPriorityRef"
                 class="ts-pop" :class="{ 'ts-pop-flip-right': flipRight.priority }"
                 v-click-outside-pop="closePropPopover">
              <button v-for="p in PRIORITIES" :key="p.value"
                      type="button" class="ts-pop-item"
                      :class="{ 'is-active': (full.card.priority || 'medium') === p.value }"
                      @click="selectPriority(p.value)">
                <span class="ts-pop-item-dot" :class="'prio-bg-' + p.value"></span>
                <span class="ts-pop-item-label">{{ p.label }}</span>
                <TaskIcon v-if="(full.card.priority || 'medium') === p.value" name="check" :size="13" class="ts-pop-item-check"/>
              </button>
            </div>
          </div>

          <!-- Срок -->
          <div class="ts-pill-wrap">
            <button type="button"
                    class="ts-pill ts-pill-due" :class="[dueStateClass(full.card.due_date, full.card.is_done), { 'is-open': propPopover === 'due' }]"
                    :title="full.card.due_date ? 'Срок: ' + full.card.due_date : 'Установить срок'"
                    @click.stop="togglePropPopover('due')">
              <TaskIcon name="calendar" :size="13" class="ts-pill-icon"/>
              <span class="ts-pill-text">{{ full.card.due_date ? formatDueShort(full.card.due_date) : 'Установить срок' }}</span>
              <span v-if="full.card.due_date" class="ts-pill-clear"
                    role="button" tabindex="0"
                    @click.stop.prevent="patch({ due_date: null })" title="Убрать срок">
                <TaskIcon name="close" :size="11"/>
              </span>
            </button>
            <div v-if="propPopover === 'due'" ref="popDueRef"
                 class="ts-pop ts-pop-flat" :class="{ 'ts-pop-flip-right': flipRight.due }"
                 v-click-outside-pop="closePropPopover">
              <DatetimePicker :model-value="full.card.due_date || ''"
                              @update:model-value="onPickerChange"
                              @cancel="closePropPopover"/>
            </div>
          </div>

          <!-- Колонка -->
          <div class="ts-pill-wrap">
            <button type="button"
                    class="ts-pill ts-pill-col" :class="{ 'is-open': propPopover === 'column' }"
                    :title="'Колонка: ' + columnTitle"
                    @click.stop="togglePropPopover('column')">
              <span class="ts-pill-icon"><span class="ts-pill-col-dot" :style="{ background: currentColumnColor }"></span></span>
              <span class="ts-pill-text">{{ columnTitle }}</span>
              <TaskIcon name="chevronDown" :size="11" class="ts-pill-chev"/>
            </button>
            <div v-if="propPopover === 'column'" ref="popColumnRef"
                 class="ts-pop" :class="{ 'ts-pop-flip-right': flipRight.column }"
                 v-click-outside-pop="closePropPopover">
              <button v-for="col in columns" :key="col.id"
                      type="button" class="ts-pop-item"
                      :class="{ 'is-active': full.card.column_id === col.id }"
                      @click="selectColumn(col.id)">
                <span class="ts-pop-item-dot" :style="{ background: col.color || '#9E9E9E' }"></span>
                <span class="ts-pop-item-label">{{ col.title }}</span>
                <TaskIcon v-if="full.card.column_id === col.id" name="check" :size="13" class="ts-pop-item-check"/>
              </button>
            </div>
          </div>

          <!-- Цвет фона карточки -->
          <div class="ts-pill-wrap">
            <button type="button"
                    class="ts-pill ts-pill-color" :class="{ 'is-open': propPopover === 'color' }"
                    :title="full.card.color ? 'Цвет карточки' : 'Без цвета'"
                    @click.stop="togglePropPopover('color')">
              <span class="ts-pill-icon">
                <span class="ts-pill-color-dot" :style="{ background: full.card.color || 'transparent', borderColor: full.card.color || 'var(--tk-border, #E6E1D7)' }"></span>
              </span>
              <span class="ts-pill-text">{{ full.card.color ? 'Цвет' : 'Без цвета' }}</span>
              <span v-if="full.card.color" class="ts-pill-clear"
                    role="button" tabindex="0"
                    @click.stop.prevent="patch({ color: null })" title="Убрать цвет">
                <TaskIcon name="close" :size="11"/>
              </span>
            </button>
            <div v-if="propPopover === 'color'" ref="popColorRef"
                 class="ts-pop ts-pop-flat ts-pop-color" :class="{ 'ts-pop-flip-right': flipRight.color }"
                 v-click-outside-pop="closePropPopover">
              <div class="ts-color-grid">
                <button v-for="c in CARD_BG_COLORS" :key="c.hex"
                        type="button" class="ts-color-sw"
                        :class="{ 'is-active': (full.card.color || '').toLowerCase() === c.hex.toLowerCase() }"
                        :style="{ background: c.hex }"
                        :title="c.label"
                        @click="selectColor(c.hex)">
                  <TaskIcon v-if="(full.card.color || '').toLowerCase() === c.hex.toLowerCase()" name="check" :size="13"/>
                </button>
              </div>
              <button type="button" class="ts-color-clear" @click="selectColor(null)">
                <TaskIcon name="close" :size="12"/> Без цвета
              </button>
            </div>
          </div>
        </div>

        <!-- Вкладки (Yougile-стиль: тематически разделено) -->
        <div class="ts-tabs">
          <button class="ts-tab" :class="{ active: tab === 'main' }" @click="tab = 'main'">Описание</button>
          <button v-if="!full.parent" class="ts-tab" :class="{ active: tab === 'subs' }" @click="tab = 'subs'">
            Подзадачи
            <span v-if="subtasksTotal || checklistTotal" class="ts-tab-badge">
              {{ (subtasksDone + checklistDone) }}/{{ (subtasksTotal + checklistTotal) }}
            </span>
          </button>
          <button class="ts-tab" :class="{ active: tab === 'chat' }" @click="tab = 'chat'">
            Чат <span v-if="full.comments?.length" class="ts-tab-badge">{{ full.comments.length }}</span>
          </button>
          <button class="ts-tab" :class="{ active: tab === 'history' }" @click="tab = 'history'">История</button>
        </div>

        <!-- ВКЛАДКА «ОПИСАНИЕ» — только описание, метки, файлы, связи -->
        <div v-if="tab === 'main'" class="ts-pane">

          <!-- Описание -->
          <section class="ts-section">
            <div class="ts-section-title">Описание</div>
            <MarkdownEditor v-model="full.card.description"
                            placeholder="Добавить описание…"
                            :mentions="mentionUsers"
                            @blur="onDescBlur"/>
          </section>

          <!-- Метки -->
          <section class="ts-section">
            <div class="ts-section-title">
              Метки
              <span v-if="selectedLabels.length" class="ts-section-meta">{{ selectedLabels.length }}</span>
            </div>
            <div class="ts-labels-row">
              <span v-for="l in selectedLabels" :key="l.id" class="ts-label-pill"
                    :style="labelPillStyle(l)">
                <span class="ts-label-pill-dot" :style="{ background: l.color }"></span>
                <span class="ts-label-pill-text">{{ l.title }}</span>
                <button type="button" class="ts-label-pill-del" @click.stop="toggleLabel(l)" title="Убрать метку">
                  <TaskIcon name="close" :size="10"/>
                </button>
              </span>
              <div class="ts-label-add-wrap">
                <button type="button" class="ts-label-add-btn"
                        :class="{ 'is-open': showLabelPicker }"
                        @click.stop="showLabelPicker = !showLabelPicker">
                  <TaskIcon name="plus" :size="12"/>
                  <span>{{ selectedLabels.length ? 'Добавить ещё' : 'Добавить метку' }}</span>
                </button>
                <div v-if="showLabelPicker" class="ts-pop ts-pop-label"
                     v-click-outside-pop="() => showLabelPicker = false">
                  <input v-model="labelSearch" type="text" placeholder="Поиск меток"
                         class="ts-pop-search" autofocus/>
                  <div class="ts-pop-list ts-pop-label-list">
                    <button v-for="l in filteredLabels" :key="l.id" type="button"
                            class="ts-pop-label-item"
                            :class="{ 'is-active': full.label_ids.includes(l.id) }"
                            @click="toggleLabel(l)">
                      <span class="ts-pop-label-check" :class="{ 'is-checked': full.label_ids.includes(l.id) }">
                        <TaskIcon v-if="full.label_ids.includes(l.id)" name="check" :size="11"/>
                      </span>
                      <span class="ts-pop-label-stripe" :style="{ background: l.color }"></span>
                      <span class="ts-pop-label-text">{{ l.title }}</span>
                    </button>
                    <div v-if="!filteredLabels.length" class="ts-pop-empty">
                      <template v-if="labelSearch">Метка «{{ labelSearch }}» не найдена</template>
                      <template v-else>У доски пока нет меток</template>
                    </div>
                  </div>
                  <div class="ts-pop-label-foot">
                    <button type="button" class="ts-pop-label-create" @click="createLabelFromPicker">
                      <TaskIcon name="plus" :size="12"/>
                      <span>Создать{{ labelSearch ? ' «' + labelSearch + '»' : ' новую метку' }}</span>
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <!-- Файлы / вложения -->
          <section class="ts-section">
            <div class="ts-section-title">
              Файлы
              <span v-if="full.attachments?.length" class="ts-section-meta">{{ full.attachments.length }}</span>
            </div>
            <div class="ts-att-drop"
                 :class="{ 'is-drag': isDragOver, 'is-upload': uploadingCount > 0 }"
                 @dragover.prevent="onDragOver"
                 @dragleave.prevent="onDragLeave"
                 @drop.prevent="onDrop"
                 @click="pickFiles">
              <input ref="fileInputRef" type="file" multiple class="ts-att-input" @change="onFilePick"/>
              <TaskIcon name="archive" :size="20"/>
              <span class="ts-att-drop-text">
                <template v-if="uploadingCount > 0">Загрузка: {{ uploadingDone }}/{{ uploadingCount + uploadingDone }}…</template>
                <template v-else>Перетащите файлы сюда или нажмите для выбора (до 25 МБ)</template>
              </span>
            </div>
            <ul v-if="full.attachments?.length" class="ts-att-list">
              <li v-for="a in full.attachments" :key="a.id" class="ts-att-item">
                <div class="ts-att-thumb" :class="'ts-att-thumb-' + attTypeOf(a)">
                  <img v-if="attIsImage(a)" :src="tasksApi.attachmentUrl(a.file_path)" :alt="a.file_name"/>
                  <span v-else>{{ attIconOf(a) }}</span>
                </div>
                <div class="ts-att-info">
                  <a class="ts-att-name" :href="tasksApi.attachmentUrl(a.file_path)" target="_blank" rel="noopener noreferrer">{{ a.file_name }}</a>
                  <div class="ts-att-meta">
                    {{ attFormatSize(a.file_size) }} · {{ a.uploaded_by }} · {{ formatDate(a.uploaded_at) }}
                  </div>
                </div>
                <div class="ts-att-actions">
                  <a class="ts-icon-btn" :href="tasksApi.attachmentUrl(a.file_path, { download: true })"
                     :download="a.file_name" title="Скачать">
                    <TaskIcon name="download" :size="14"/>
                  </a>
                  <button v-if="canDeleteAttachment(a)" class="ts-icon-btn" @click="deleteAttachment(a)" title="Удалить">
                    <TaskIcon name="trash" :size="14"/>
                  </button>
                </div>
              </li>
            </ul>
          </section>

          <!-- Время (таймер карточки, C4) -->
          <section class="ts-section">
            <div class="ts-section-title">
              Время
              <span v-if="timerTotalDisplay" class="ts-section-meta">{{ timerTotalDisplay }}</span>
            </div>
            <div class="ts-timer-row">
              <button type="button" class="ts-timer-btn"
                      :class="{ 'is-running': !!myRunningStartedAt }"
                      @click="toggleTimer">
                <TaskIcon :name="myRunningStartedAt ? 'stop' : 'play'" :size="14"/>
                <span>{{ myRunningStartedAt ? 'Остановить' : 'Запустить' }}</span>
              </button>
              <div v-if="myRunningStartedAt" class="ts-timer-live" :title="'Идёт с ' + formatDate(myRunningStartedAt)">
                <span class="ts-timer-live-dot"></span>
                <span class="ts-timer-live-text">{{ myCurrentTickerDisplay }}</span>
              </div>
              <div v-else-if="!timerTotalDisplay" class="ts-timer-hint">
                Отслеживайте, сколько времени потратили на эту задачу
              </div>
            </div>
            <ul v-if="timerByUser.length" class="ts-timer-users">
              <li v-for="u in timerByUser" :key="u.user_name" class="ts-timer-user">
                <span class="ts-chip-bubble ts-timer-bubble">{{ initials(u.user_name) }}</span>
                <span class="ts-timer-user-name">{{ u.user_name }}</span>
                <span class="ts-timer-user-sec">{{ formatHMS(u.seconds + (u.user_name === currentUserName && myRunningStartedAt ? currentRunningSec : 0)) }}</span>
              </li>
            </ul>
          </section>

          <!-- Соисполнители -->
          <section class="ts-section">
            <div class="ts-section-title">Соисполнители</div>
            <div class="ts-assignees">
              <span v-for="n in full.assignees" :key="n" class="ts-chip"
                    :class="{ 'ts-chip-done': (full.assignees_done || []).includes(n) }"
                    :title="(full.assignees_done || []).includes(n) ? 'Выполнил свою часть' : ''">
                <span class="ts-chip-bubble">{{ initials(n) }}</span>
                <span class="ts-chip-name">{{ n }}</span>
                <span v-if="(full.assignees_done || []).includes(n)" class="ts-chip-done-tick" aria-hidden="true">✓</span>
                <button v-if="canEditStructure" class="ts-icon-btn" @click="removeAssignee(n)">
                  <TaskIcon name="close" :size="12"/>
                </button>
              </span>
              <span v-if="!full.assignees.length" class="ts-empty">Никого</span>
            </div>
            <div v-if="canEditStructure" class="ts-assignee-add-wrap">
              <button type="button" class="ts-assignee-add-btn"
                      :class="{ 'is-open': assigneePopoverOpen }"
                      @click.stop="toggleAssigneePopover">
                <TaskIcon name="plus" :size="13"/>
                <span>Добавить соисполнителя</span>
              </button>
              <div v-if="assigneePopoverOpen" class="ts-pop ts-pop-assignee" v-click-outside-pop="closeAssigneePopover">
                <input ref="assigneeSearchRef"
                       v-model="assigneeSearch"
                       class="ts-pop-search"
                       type="text" placeholder="Поиск по имени…"
                       @keydown.esc="closeAssigneePopover"/>
                <div class="ts-pop-list">
                  <button v-for="u in filteredAvailableUsers" :key="u.name"
                          type="button" class="ts-pop-item"
                          @click="pickAssignee(u.name)">
                    <span class="ts-pop-item-bubble">{{ initials(u.name) }}</span>
                    <span class="ts-pop-item-label">{{ u.name }}</span>
                  </button>
                  <div v-if="!filteredAvailableUsers.length" class="ts-pop-empty">
                    {{ assigneeSearch ? 'Никого не нашли' : 'Все уже добавлены' }}
                  </div>
                </div>
              </div>
            </div>
          </section>

          <!-- Связи: чипы со ссылкой на сущности; добавление через popover -->
          <section class="ts-section">
            <div class="ts-section-title">
              Связано с
              <span v-if="full.relations.length" class="ts-section-meta">{{ full.relations.length }}</span>
            </div>
            <div v-if="full.relations.length" class="ts-rel-chips">
              <component v-for="r in full.relations" :key="r.id"
                         :is="relationTo(r) ? 'router-link' : 'div'"
                         :to="relationTo(r) || undefined"
                         class="ts-rel-chip"
                         :class="'ts-rel-' + r.entity_type">
                <!-- router-link рендерится как <a>, и a.ts-rel-chip уже
                     стилизована как кликабельная (cursor + hover). -->
                <span class="ts-rel-chip-icon">{{ relationTypeIcon(r.entity_type) }}</span>
                <span v-if="relationShowType(r)" class="ts-rel-chip-type">{{ relationTypeLabel(r.entity_type) }}</span>
                <span class="ts-rel-chip-label">{{ r.entity_label || r.entity_id }}</span>
                <button type="button" class="ts-rel-chip-del"
                        @click.stop.prevent="removeRelation(r)" title="Убрать связь">
                  <TaskIcon name="close" :size="11"/>
                </button>
              </component>
            </div>

            <!-- Кнопка-чип «+ Добавить связь» и popover -->
            <div class="ts-rel-add-wrap">
              <button type="button" class="ts-rel-add-btn"
                      :class="{ 'is-open': showRelationPicker }"
                      @click.stop="showRelationPicker = !showRelationPicker">
                <TaskIcon name="plus" :size="12"/>
                <span>{{ full.relations.length ? 'Добавить ещё' : 'Добавить связь' }}</span>
              </button>
              <div v-if="showRelationPicker" class="ts-pop ts-pop-rel" v-click-outside-pop="() => showRelationPicker = false">
                <div class="ts-pop-section-label">Тип связи</div>
                <div class="ts-rel-types">
                  <button v-for="t in RELATION_TYPES" :key="t.value" type="button"
                          class="ts-rel-type"
                          :class="{ 'is-active': relationDraft.type === t.value }"
                          @click="relationDraft.type = t.value">
                    <span class="ts-rel-type-icon">{{ t.icon }}</span>
                    <span class="ts-rel-type-label">{{ t.label }}</span>
                  </button>
                </div>
                <input v-model="relationDraft.id" type="text" placeholder="ID или код" class="ts-pop-search"/>
                <input v-model="relationDraft.label" type="text" placeholder="Подпись (необяз.)" class="ts-pop-search"/>
                <div class="ts-pop-actions">
                  <button type="button" class="ts-pop-btn" @click="showRelationPicker = false">Отмена</button>
                  <button type="button" class="ts-pop-btn ts-pop-btn-primary"
                          :disabled="!relationDraft.type || !relationDraft.id"
                          @click="addRelation">Добавить</button>
                </div>
              </div>
            </div>
          </section>

          <!-- Зависимости: чем задача заблокирована и что блокирует она -->
          <section class="ts-section">
            <div class="ts-section-title">
              Зависимости
              <span v-if="depCount" class="ts-section-meta">{{ depCount }}</span>
            </div>

            <div v-if="full.dependencies && full.dependencies.blocked_by.length" class="ts-dep-group">
              <div class="ts-dep-label ts-dep-label-blocked">
                <TaskIcon name="lock" :size="11"/> Заблокирована
              </div>
              <div class="ts-dep-chips">
                <div v-for="d in full.dependencies.blocked_by" :key="d.id"
                     class="ts-dep-chip" :class="{ 'ts-dep-chip-done': d.is_done }"
                     @click="$emit('open-card', d.card_id)" title="Открыть задачу">
                  <span class="ts-dep-chip-title">{{ d.title }}</span>
                  <span v-if="d.is_done" class="ts-dep-chip-state">готово</span>
                  <button type="button" class="ts-dep-chip-del"
                          @click.stop="removeDependency(d)" title="Убрать зависимость">
                    <TaskIcon name="close" :size="11"/>
                  </button>
                </div>
              </div>
            </div>

            <div v-if="full.dependencies && full.dependencies.blocks.length" class="ts-dep-group">
              <div class="ts-dep-label">
                <TaskIcon name="arrowRight" :size="11"/> Блокирует
              </div>
              <div class="ts-dep-chips">
                <div v-for="d in full.dependencies.blocks" :key="d.id"
                     class="ts-dep-chip" :class="{ 'ts-dep-chip-done': d.is_done }"
                     @click="$emit('open-card', d.card_id)" title="Открыть задачу">
                  <span class="ts-dep-chip-title">{{ d.title }}</span>
                  <span v-if="d.is_done" class="ts-dep-chip-state">готово</span>
                  <button type="button" class="ts-dep-chip-del"
                          @click.stop="removeDependency(d)" title="Убрать зависимость">
                    <TaskIcon name="close" :size="11"/>
                  </button>
                </div>
              </div>
            </div>

            <div class="ts-rel-add-wrap">
              <button type="button" class="ts-rel-add-btn"
                      :class="{ 'is-open': showDepPicker }"
                      @click.stop="toggleDepPicker">
                <TaskIcon name="plus" :size="12"/>
                <span>{{ depCount ? 'Добавить ещё' : 'Добавить зависимость' }}</span>
              </button>
              <div v-if="showDepPicker" class="ts-pop ts-pop-rel" v-click-outside-pop="closeDepPicker">
                <div class="ts-pop-section-label">Тип зависимости</div>
                <div class="ts-rel-types">
                  <button type="button" class="ts-rel-type"
                          :class="{ 'is-active': depDraft.direction === 'blocked_by' }"
                          @click="depDraft.direction = 'blocked_by'">
                    <span class="ts-rel-type-label">Заблокирована</span>
                  </button>
                  <button type="button" class="ts-rel-type"
                          :class="{ 'is-active': depDraft.direction === 'blocks' }"
                          @click="depDraft.direction = 'blocks'">
                    <span class="ts-rel-type-label">Блокирует</span>
                  </button>
                </div>
                <input v-model="depSearch" type="text" placeholder="Поиск задачи на доске…" class="ts-pop-search"/>
                <div class="ts-dep-results">
                  <button v-for="c in depCandidates" :key="c.id" type="button"
                          class="ts-dep-result" @click="addDependency(c.id)">
                    {{ c.title }}
                  </button>
                  <div v-if="!depCandidates.length" class="ts-dep-noresult">Задачи не найдены</div>
                </div>
              </div>
            </div>
          </section>

        </div>

        <!-- ВКЛАДКА «ПОДЗАДАЧИ» — подзадачи + чек-лист. Только у корневых карточек. -->
        <div v-if="tab === 'subs' && !full.parent" class="ts-pane">

          <!-- Подзадачи -->
          <section class="ts-section">
            <div class="ts-section-title">
              Подзадачи
              <span v-if="subtasksTotal" class="ts-section-meta">{{ subtasksDone }}/{{ subtasksTotal }}</span>
            </div>
            <div v-if="subtasksTotal" class="ts-progress">
              <div class="ts-progress-bar" :style="{ width: subtasksPct + '%', background: '#635BFF' }"></div>
            </div>
            <ul class="ts-subtasks">
              <li v-for="st in full.subtasks" :key="st.id" class="ts-sub-item">
                <input type="checkbox" class="ts-round-chk" :checked="!!st.is_done" @change="toggleSubtaskDone(st)" />
                <button class="ts-sub-text" :class="{ done: st.is_done }" @click="$emit('open-card', st.id)">
                  <span class="ts-sub-title">{{ st.title }}</span>
                  <span class="ts-sub-meta">
                    <span v-if="st.priority && st.priority !== 'medium'" class="ts-sub-prio" :class="'prio-bg-' + st.priority">{{ priorityShort(st.priority) }}</span>
                    <span v-if="st.due_date" class="ts-sub-due" :class="{ overdue: isSubOverdue(st) }">{{ formatSubDue(st.due_date) }}</span>
                    <span v-if="st.assignees?.length" class="ts-sub-assignees">
                      <span v-for="(n, i) in st.assignees.slice(0,2)" :key="i" class="ts-sub-bubble" :title="n">{{ initials(n) }}</span>
                    </span>
                  </span>
                </button>
                <button class="ts-icon-btn" @click="deleteSubtask(st)" title="Удалить">
                  <TaskIcon name="close" :size="14"/>
                </button>
              </li>
            </ul>
            <div class="ts-chk-add">
              <input v-model="newSubtaskTitle" type="text" placeholder="Новая подзадача…"
                     @keydown.enter="addSubtask" />
              <button class="btn primary ts-btn-sm" @click="addSubtask" :disabled="!newSubtaskTitle.trim()">
                <TaskIcon name="plus" :size="14"/>
              </button>
            </div>
          </section>

          <!-- Чек-листы (несколько групп на карточку) -->
          <section v-for="g in full.checklists" :key="'g' + g.id" class="ts-section ts-chk-group">
            <div class="ts-section-title">
              <input v-if="editingGroupId === g.id" ref="groupTitleInputRef"
                     v-model="editingGroupTitle"
                     class="ts-chk-group-title-input"
                     @blur="saveGroupTitle(g)"
                     @keydown.enter.prevent="saveGroupTitle(g)"
                     @keydown.esc="cancelEditGroup" />
              <span v-else class="ts-chk-group-title"
                    @click="startEditGroup(g)" title="Переименовать">{{ g.title || 'Чек-лист' }}</span>
              <span v-if="groupItemsTotal(g)" class="ts-section-meta">{{ groupItemsDone(g) }}/{{ groupItemsTotal(g) }}</span>
              <button class="ts-icon-btn ts-chk-group-del" @click="deleteGroup(g)" title="Удалить чек-лист">
                <TaskIcon name="trash" :size="13"/>
              </button>
            </div>
            <div v-if="groupItemsTotal(g)" class="ts-progress">
              <div class="ts-progress-bar" :style="{ width: groupItemsPct(g) + '%' }"></div>
            </div>
            <ul class="ts-checklist">
              <li v-for="item in (g.items || [])" :key="item.id" class="ts-chk-item">
                <input type="checkbox" class="ts-chk-box" :checked="!!item.is_done" @change="toggleChecklist(item)" />
                <span v-if="editingChecklistId !== item.id" class="ts-chk-text"
                      :class="{ done: item.is_done }"
                      @click="startEditChecklist(item)">{{ item.title || '(пусто)' }}</span>
                <input v-else type="text" class="ts-chk-input"
                       :ref="el => editingInputRef = el"
                       v-model="editingChecklistTitle"
                       @blur="saveEditChecklist(item)"
                       @keydown.enter.prevent="saveEditChecklist(item)"
                       @keydown.esc="cancelEditChecklist" />
                <button class="ts-icon-btn" @click="deleteChecklist(item)" title="Удалить">
                  <TaskIcon name="close" :size="14"/>
                </button>
              </li>
            </ul>
            <div class="ts-chk-add">
              <input v-model="newItemByGroup[g.id]" type="text" placeholder="Новый пункт…"
                     @keydown.enter="addItemToGroup(g)" />
              <button class="btn primary ts-btn-sm" @click="addItemToGroup(g)" :disabled="!(newItemByGroup[g.id] || '').trim()">
                <TaskIcon name="plus" :size="14"/>
              </button>
            </div>
          </section>

          <!-- Кнопка добавить новый чек-лист -->
          <button class="ts-add-checklist-btn" @click="createChecklistGroup">
            <TaskIcon name="plus" :size="14"/>
            <span>Новый чек-лист</span>
          </button>
        </div>

        <!-- ВКЛАДКА «ЧАТ» -->
        <div v-if="tab === 'chat'" class="ts-pane ts-chat-pane">
          <div class="ts-chat-list" ref="chatListRef">
            <div v-if="!full.comments.length" class="ts-empty ts-chat-empty">
              <div class="ts-chat-empty-icon">
                <TaskIcon name="chat" :size="24"/>
              </div>
              <div class="ts-chat-empty-text">Чат пуст</div>
              <div class="ts-chat-empty-hint">Напишите первое сообщение участникам карточки</div>
            </div>
            <div v-for="c in full.comments" :key="c.id" class="ts-chat-row"
                 :class="{ own: c.author_name === currentUserName }">
              <div class="ts-chat-avatar" :title="c.author_name">{{ initials(c.author_name) }}</div>
              <div class="ts-chat-bubble">
                <div class="ts-chat-meta">
                  <span class="ts-chat-author">{{ c.author_name }}</span>
                  <span class="ts-chat-date">{{ formatDate(c.created_at) }}<span v-if="c.edited_at"> · ред.</span></span>
                </div>
                <div class="ts-chat-body ts-md-view" v-html="renderMarkdown(c.body)"></div>
                <div v-if="canEditComment(c)" class="ts-chat-actions">
                  <button class="ts-chat-action" @click="editComment(c)" title="Редактировать">
                    <TaskIcon name="edit" :size="12"/>
                  </button>
                  <button class="ts-chat-action" @click="removeComment(c)" title="Удалить">
                    <TaskIcon name="close" :size="12"/>
                  </button>
                </div>
              </div>
            </div>
          </div>
          <div class="ts-chat-input">
            <MarkdownEditor v-model="newComment" :compact="true"
                            placeholder="Сообщение… (Enter — отправить, Shift+Enter — перенос строки)"
                            :mentions="mentionUsers"
                            @ctrl-enter="submitComment"/>
            <button class="ts-chat-send" @click="submitComment"
                    :disabled="!newComment.trim()" title="Отправить (Enter)">
              <TaskIcon name="chevronRight" :size="16" class="ts-chat-send-icon"/>
            </button>
          </div>
        </div>

        <!-- ВКЛАДКА «ИСТОРИЯ» -->
        <div v-if="tab === 'history'" class="ts-pane">
          <div v-if="!full.history.length" class="ts-hist-empty">
            <div class="ts-hist-empty-icon">
              <TaskIcon name="archive" :size="22"/>
            </div>
            <div class="ts-hist-empty-text">История пуста</div>
            <div class="ts-hist-empty-hint">Здесь будут все изменения и события карточки</div>
          </div>
          <ul v-else class="ts-hist">
            <li v-for="h in full.history" :key="h.id" class="ts-hist-item">
              <span class="ts-hist-marker" :class="'ts-hist-marker-' + historyKind(h)">
                <TaskIcon :name="historyIcon(h)" :size="11"/>
              </span>
              <div class="ts-hist-content">
                <div class="ts-hist-row">
                  <span class="ts-hist-author">{{ h.user_name }}</span>
                  <span class="ts-hist-action">{{ historyText(h) }}</span>
                </div>
                <div class="ts-hist-date" :title="formatDate(h.created_at)">
                  {{ formatRelative(h.created_at) }}
                </div>
              </div>
            </li>
          </ul>
        </div>
      </template>
    </aside>
  </Teleport>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onUnmounted, nextTick, watch } from 'vue';
import { tasksApi } from '@/lib/tasksApi.js';
import { useTasksStore } from '@/stores/tasksStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { useTasksDialogs } from '@/composables/useTasksDialogs.js';
import { renderMarkdown } from '@/lib/markdown.js';
import TaskIcon from './TaskIcon.vue';
import MarkdownEditor from './MarkdownEditor.vue';
import DatetimePicker from './DatetimePicker.vue';
import UiSkeleton from '@/components/ui/UiSkeleton.vue';
const dlg = useTasksDialogs();
const showError = (e, prefix = 'Ошибка') => dlg.info(prefix, e?.message || String(e), 'error');

const props = defineProps({
  cardId: { type: Number, required: true },
  canGoBack: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'updated', 'deleted', 'open-card', 'go-back', 'refresh']);

const store = useTasksStore();
const userStore = useUserStore();
const full = ref(null);
const loading = ref(true);
const tab = ref('main');
const newComment = ref('');
const newChecklistTitle = ref('');
const newSubtaskTitle = ref('');
const newAssignee = ref('');
const showRelationPicker = ref(false);
const relationDraft = ref({ type: '', id: '', label: '' });
const showDepPicker = ref(false);
const depDraft = ref({ direction: 'blocked_by' });
const depSearch = ref('');
const headerMenuOpen = ref(false);
const showLabelPicker = ref(false);
const labelSearch = ref('');
const chatListRef = ref(null);
const editingChecklistId = ref(null);
const fileInputRef = ref(null);
const isDragOver = ref(false);
const uploadingCount = ref(0);
const uploadingDone = ref(0);

// Таймер (C4) — тикающий счётчик для своего активного интервала
const nowTick = ref(Date.now());
let tickHandle = null;

function onDescBlur() {
  patch({ description: full.value?.card?.description || '' });
}
const editingChecklistTitle = ref('');
const editingInputRef = ref(null);

const columns = computed(() => store.columns);
const labels = computed(() => store.labels);
const canEditStructure = computed(() => store.canEditStructure);
const currentUserName = computed(() => userStore.currentUser?.name || '');

const columnTitle = computed(() => {
  if (!full.value) return '';
  return columns.value.find(c => c.id === full.value.card.column_id)?.title || '';
});
const createdAtText = computed(() => {
  const v = full.value?.card?.created_at;
  if (!v) return '';
  const d = new Date(String(v).replace(' ', 'T'));
  if (isNaN(d.getTime())) return '';
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
});
const currentColumnColor = computed(() => {
  if (!full.value) return '#9E9E9E';
  return columns.value.find(c => c.id === full.value.card.column_id)?.color || '#9E9E9E';
});

// ── Таймер (C4) ──
const timerByUser = computed(() => full.value?.timer?.by_user || []);
const timerTotalSec = computed(() => full.value?.timer?.seconds_total || 0);
const myRunningStartedAt = computed(() => full.value?.timer?.my_running?.started_at || null);
const currentRunningSec = computed(() => {
  if (!myRunningStartedAt.value) return 0;
  const started = new Date(myRunningStartedAt.value.replace(' ', 'T')).getTime();
  if (Number.isNaN(started)) return 0;
  return Math.max(0, Math.floor((nowTick.value - started) / 1000));
});
const timerTotalDisplay = computed(() => {
  const total = timerTotalSec.value + currentRunningSec.value;
  return total > 0 ? formatHMS(total) : '';
});
const myCurrentTickerDisplay = computed(() => formatHMS(currentRunningSec.value));

function formatHMS(sec) {
  sec = Math.max(0, Math.floor(sec || 0));
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = sec % 60;
  if (h > 0) return `${h}ч ${m.toString().padStart(2, '0')}м`;
  if (m > 0) return `${m}м ${s.toString().padStart(2, '0')}с`;
  return `${s}с`;
}

async function toggleTimer() {
  if (!full.value) return;
  try {
    const res = myRunningStartedAt.value
      ? await tasksApi.stopTimer(props.cardId)
      : await tasksApi.startTimer(props.cardId);
    if (res?.timer) full.value.timer = res.timer;
    // Обновим карточку в общем списке, чтобы значок на канбане сразу подсветился
    // и live-тикер на канбане смог начать отсчёт от running_started_at.
    const inList = store.cards.find(c => c.id === props.cardId);
    if (inList) {
      inList.timer = {
        seconds_total: res.timer.seconds_total,
        any_running: res.timer.any_running,
        my_running: !!res.timer.my_running,
        running_started_at: res.timer.my_running?.started_at || null,
      };
    }
  } catch (e) { showError(e); }
}
const selectedLabels = computed(() => {
  if (!full.value) return [];
  const ids = full.value.label_ids || [];
  return labels.value.filter(l => ids.includes(l.id));
});
const filteredLabels = computed(() => {
  const q = labelSearch.value.trim().toLowerCase();
  if (!q) return labels.value;
  return labels.value.filter(l => (l.title || '').toLowerCase().includes(q));
});
function labelPillStyle(l) {
  return {
    background: `color-mix(in srgb, ${l.color} 14%, #fff)`,
    borderColor: `color-mix(in srgb, ${l.color} 35%, transparent)`,
    color: `color-mix(in srgb, ${l.color} 75%, #1A1814)`,
  };
}
const archiveColumn = computed(() => columns.value.find(c => c.is_archive_column));
const isInArchive = computed(() => {
  if (!full.value) return false;
  const col = columns.value.find(c => c.id === full.value.card.column_id);
  return !!(col && col.is_archive_column);
});

function priorityLabel(p) {
  return ({ low: 'Низкий', medium: 'Средний', high: 'Высокий', urgent: 'Срочно' })[p || 'medium'];
}
function formatDueShort(s) {
  if (!s) return '';
  const d = new Date(s);
  if (isNaN(d)) return s;
  const today = new Date(); today.setHours(0,0,0,0);
  const tomorrow = new Date(today); tomorrow.setDate(today.getDate() + 1);
  const day = new Date(d); day.setHours(0,0,0,0);
  const timeStr = d.getHours() || d.getMinutes()
    ? ' ' + d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
    : '';
  if (day.getTime() === today.getTime()) return 'Сегодня' + timeStr;
  if (day.getTime() === tomorrow.getTime()) return 'Завтра' + timeStr;
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: 'short' }) + timeStr;
}
// Управление поповерами свойств
const PRIORITIES = [
  { value: 'urgent', label: 'Срочно' },
  { value: 'high',   label: 'Высокий' },
  { value: 'medium', label: 'Средний' },
  { value: 'low',    label: 'Низкий' },
];
// Палитра цветов фона карточки — мягкие пастельные оттенки,
// чтобы не дрались с приоритетной полоской и метками.
const CARD_BG_COLORS = [
  { hex: '#FFE4B2', label: 'Песочный'  },
  { hex: '#FFD3B6', label: 'Персик'    },
  { hex: '#FFB7B7', label: 'Розовый'   },
  { hex: '#F4C2D7', label: 'Малиновый' },
  { hex: '#E0BBE4', label: 'Лавандовый'},
  { hex: '#C7D8FF', label: 'Голубой'   },
  { hex: '#B5EAD7', label: 'Мятный'    },
  { hex: '#D7EFC1', label: 'Салатовый' },
  { hex: '#FFF5BA', label: 'Кремовый'  },
  { hex: '#E2D7C9', label: 'Бежевый'   },
  { hex: '#CFD8DC', label: 'Серый'     },
  { hex: '#FFCCBC', label: 'Коралл'    },
];

const propPopover = ref(null); // 'priority' | 'due' | 'column' | 'color' | null
const popPriorityRef = ref(null);
const popDueRef      = ref(null);
const popColumnRef   = ref(null);
const popColorRef    = ref(null);
const flipRight = reactive({ priority: false, due: false, column: false, color: false });

async function togglePropPopover(name) {
  propPopover.value = propPopover.value === name ? null : name;
  if (propPopover.value !== name) return;
  // Сбрасываем флип, чтобы измерить «нативную» левую позицию
  flipRight[name] = false;
  await nextTick();
  const elMap = { priority: popPriorityRef, due: popDueRef, column: popColumnRef, color: popColorRef };
  const el = elMap[name]?.value;
  if (!el) return;
  const rect = el.getBoundingClientRect();
  // Если поповер вылез за правую границу — переключаем на выравнивание по правому краю кнопки
  if (rect.right > window.innerWidth - 8) flipRight[name] = true;
}
function closePropPopover() { propPopover.value = null; }
function selectPriority(p) {
  patch({ priority: p });
  closePropPopover();
}
function selectColor(hex) {
  patch({ color: hex || null });
  closePropPopover();
}
async function selectColumn(colId) {
  closePropPopover();
  if (!colId || colId === full.value.card.column_id) return;
  try {
    // У PATCH /cards/:id нет поля column_id — для смены колонки используем
    // отдельный move-эндпоинт (через стор).
    await store.moveCard(props.cardId, colId, 0);
    await load();
  } catch (e) { showError(e); }
}
function onPickerChange(iso) {
  patch({ due_date: iso || null });
  closePropPopover();
}

// Click-outside для поповеров свойств
const vClickOutsidePop = {
  mounted(el, binding) {
    el.__co = (e) => { if (!el.contains(e.target)) binding.value(e); };
    setTimeout(() => document.addEventListener('mousedown', el.__co), 0);
  },
  unmounted(el) { document.removeEventListener('mousedown', el.__co); },
};
function dueStateClass(due, isDone) {
  if (!due || isDone) return '';
  const d = new Date(due);
  const now = new Date();
  const hours = (d - now) / 3600000;
  if (hours < 0) return 'overdue';
  if (hours < 24) return 'fire';
  if (hours < 72) return 'warn';
  return '';
}

const checklistTotal = computed(() => {
  let n = 0;
  for (const g of (full.value?.checklists || [])) n += (g.items || []).length;
  return n || (full.value?.checklist?.length || 0);
});
const checklistDone = computed(() => {
  let n = 0;
  for (const g of (full.value?.checklists || [])) for (const i of (g.items || [])) if (i.is_done) n++;
  if (!full.value?.checklists?.length) return full.value?.checklist?.filter(i => i.is_done).length || 0;
  return n;
});
const checklistPct = computed(() => checklistTotal.value ? Math.round(checklistDone.value / checklistTotal.value * 100) : 0);

// Хелперы для отдельной группы чек-листа
function groupItemsTotal(g) { return (g?.items || []).length; }
function groupItemsDone(g)  { return (g?.items || []).filter(i => i.is_done).length; }
function groupItemsPct(g)   { const t = groupItemsTotal(g); return t ? Math.round(groupItemsDone(g) / t * 100) : 0; }

const subtasksTotal = computed(() => full.value?.subtasks?.length || 0);
const subtasksDone = computed(() => full.value?.subtasks?.filter(s => s.is_done).length || 0);
const subtasksPct = computed(() => subtasksTotal.value ? Math.round(subtasksDone.value / subtasksTotal.value * 100) : 0);

const dueDateLocal = computed(() => {
  if (!full.value?.card.due_date) return '';
  const d = new Date(full.value.card.due_date);
  if (isNaN(d)) return '';
  const pad = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
});

const availableUsers = computed(() => {
  const taken = new Set(full.value?.assignees || []);
  taken.add(store.board?.owner_name);
  return store.users.filter(u => !taken.has(u.name));
});

// Все пользователи для @-упоминаний (включая владельца и текущих соисполнителей)
const mentionUsers = computed(() => {
  const me = userStore.currentUser?.name || '';
  return (store.users || []).filter(u => u.name !== me);
});

// Поповер «+ Добавить соисполнителя»
const assigneePopoverOpen = ref(false);
const assigneeSearch = ref('');
const assigneeSearchRef = ref(null);
function toggleAssigneePopover() {
  assigneePopoverOpen.value = !assigneePopoverOpen.value;
  if (assigneePopoverOpen.value) {
    assigneeSearch.value = '';
    nextTick(() => assigneeSearchRef.value?.focus?.());
  }
}
function closeAssigneePopover() {
  assigneePopoverOpen.value = false;
  assigneeSearch.value = '';
}
const filteredAvailableUsers = computed(() => {
  const q = assigneeSearch.value.trim().toLowerCase();
  if (!q) return availableUsers.value;
  return availableUsers.value.filter(u => u.name.toLowerCase().includes(q));
});
async function pickAssignee(name) {
  newAssignee.value = name;
  await addAssignee();
  closeAssigneePopover();
}

const canDelete = computed(() => {
  if (!full.value) return false;
  if (userStore.currentUser?.role === 'admin') return true;
  if (full.value.card.owner_name === userStore.currentUser?.name) return true;
  return full.value.card.created_by === userStore.currentUser?.name;
});

async function load() {
  loading.value = true;
  try {
    full.value = await tasksApi.loadCard(props.cardId);
  } catch (e) {
    showError(e, 'Ошибка загрузки');
    close();
    return;
  } finally {
    loading.value = false;
  }
  if (canEditStructure.value) store.fetchUsers();
}

onMounted(() => {
  load();
  document.addEventListener('keydown', onKeydown);
  tickHandle = setInterval(() => { nowTick.value = Date.now(); }, 1000);
});
onUnmounted(() => {
  document.removeEventListener('keydown', onKeydown);
  if (tickHandle) { clearInterval(tickHandle); tickHandle = null; }
});
// Заголовок задачи — авто-растущий textarea: длинное название видно целиком.
const titleEl = ref(null);
function autoGrowTitle() {
  const el = titleEl.value;
  if (!el) return;
  el.style.height = 'auto';
  el.style.height = el.scrollHeight + 'px';
}
watch(() => full.value?.card?.id, () => nextTick(autoGrowTitle));

watch(() => props.cardId, load);

// Автопрокрутка чата вниз при загрузке/новом сообщении
watch([tab, () => full.value?.comments?.length], async () => {
  if (tab.value !== 'chat') return;
  await nextTick();
  if (chatListRef.value) chatListRef.value.scrollTop = chatListRef.value.scrollHeight;
});

function onKeydown(e) { if (e.key === 'Escape') close(); }

function close() { emit('close'); }

async function patch(payload) {
  try {
    await tasksApi.updateCard(props.cardId, payload);
    Object.assign(full.value.card, payload);
    emit('updated');
    const inList = store.cards.find(c => c.id === props.cardId);
    if (inList) Object.assign(inList, payload);
  } catch (e) { showError(e); }
}

async function onColumnChange(e) {
  const newCol = parseInt(e.target.value, 10);
  if (!newCol || newCol === full.value.card.column_id) return;
  // Перенос в колонку-завершение для заблокированной задачи — с подтверждением.
  const targetCol = columns.value.find(c => c.id === newCol);
  if (targetCol && (targetCol.is_archive_column || targetCol.is_done_column) && !full.value.card.is_done) {
    const openBlockers = (full.value.dependencies?.blocked_by || [])
      .filter(d => !d.is_done && !d.is_archived).length;
    if (!(await dlg.confirmCompleteBlocked(openBlockers))) {
      e.target.value = full.value.card.column_id;
      return;
    }
  }
  await store.moveCard(props.cardId, newCol, 0);
  await load();
}

function onDueChange(e) {
  const v = e.target.value;
  if (!v) { patch({ due_date: null }); return; }
  patch({ due_date: v.replace('T', ' ') + ':00' });
}

// ─── Чек-листы (несколько групп) ───
// Состояние ввода/редактирования на уровне групп
const newItemByGroup = reactive({});                  // { [groupId]: 'текст нового пункта' }
const editingGroupId = ref(null);
const editingGroupTitle = ref('');
const groupTitleInputRef = ref(null);

function findItemRef(itemId) {
  // Возвращает { group, item } для пункта чек-листа в текущих группах
  for (const g of (full.value?.checklists || [])) {
    const it = (g.items || []).find(i => i.id === itemId);
    if (it) return { group: g, item: it };
  }
  return null;
}

async function addItemToGroup(group) {
  const t = (newItemByGroup[group.id] || '').trim();
  if (!t) return;
  try {
    const r = await tasksApi.addChecklist(props.cardId, t, group.id);
    if (!group.items) group.items = [];
    group.items.push({ id: r.id, title: t, is_done: 0, checklist_id: group.id });
    // Поддерживаем плоский checklist для счётчика в таб-бейдже
    if (full.value.checklist) full.value.checklist.push({ id: r.id, title: t, is_done: 0, checklist_id: group.id });
    newItemByGroup[group.id] = '';
    refreshCardSummary();
  } catch (e) { showError(e); }
}
async function toggleChecklist(item) {
  const newVal = item.is_done ? 0 : 1;
  try {
    await tasksApi.updateChecklistItem(item.id, { is_done: newVal });
    item.is_done = newVal;
    // Зеркалим в плоский массив
    const flat = (full.value.checklist || []).find(i => i.id === item.id);
    if (flat) flat.is_done = newVal;
    refreshCardSummary();
  } catch (e) { showError(e); }
}
function startEditChecklist(item) {
  editingChecklistId.value = item.id;
  editingChecklistTitle.value = item.title || '';
  nextTick(() => editingInputRef.value?.focus?.());
}
async function saveEditChecklist(item) {
  const t = (editingChecklistTitle.value || '').trim();
  editingChecklistId.value = null;
  if (!t || t === item.title) { editingChecklistTitle.value = ''; return; }
  try {
    await tasksApi.updateChecklistItem(item.id, { title: t });
    item.title = t;
    const flat = (full.value.checklist || []).find(i => i.id === item.id);
    if (flat) flat.title = t;
  } catch (e) { showError(e); }
  editingChecklistTitle.value = '';
}
function cancelEditChecklist() { editingChecklistId.value = null; editingChecklistTitle.value = ''; }
async function deleteChecklist(item) {
  try {
    await tasksApi.deleteChecklistItem(item.id);
    const ref = findItemRef(item.id);
    if (ref) ref.group.items = (ref.group.items || []).filter(i => i.id !== item.id);
    if (full.value.checklist) full.value.checklist = full.value.checklist.filter(i => i.id !== item.id);
    refreshCardSummary();
  } catch (e) { showError(e); }
}

// ─── Группы чек-листа ───
async function createChecklistGroup() {
  try {
    const title = 'Чек-лист';
    const r = await tasksApi.addChecklistGroup(props.cardId, title);
    if (!full.value.checklists) full.value.checklists = [];
    full.value.checklists.push({ id: r.id, title: r.title || title, sort_order: r.sort_order || 0, items: [] });
    // Сразу вход в редактирование названия
    nextTick(() => startEditGroup(full.value.checklists[full.value.checklists.length - 1]));
  } catch (e) { showError(e); }
}
function startEditGroup(g) {
  editingGroupId.value = g.id;
  editingGroupTitle.value = g.title || '';
  nextTick(() => {
    const ref = groupTitleInputRef.value;
    const el = Array.isArray(ref) ? ref[ref.length - 1] : ref;
    el?.focus?.();
    el?.select?.();
  });
}
function cancelEditGroup() { editingGroupId.value = null; editingGroupTitle.value = ''; }
async function saveGroupTitle(g) {
  const t = (editingGroupTitle.value || '').trim() || 'Чек-лист';
  editingGroupId.value = null;
  if (t === g.title) { editingGroupTitle.value = ''; return; }
  try {
    await tasksApi.updateChecklistGroup(g.id, { title: t });
    g.title = t;
  } catch (e) { showError(e); }
  editingGroupTitle.value = '';
}
async function deleteGroup(g) {
  const total = groupItemsTotal(g);
  const ok = total === 0 ? true : await dlg.confirm(
    'Удалить чек-лист',
    `В этом чек-листе ${total} пункт(ов). Удалить вместе со всеми пунктами?`,
    { okText: 'Удалить', danger: true }
  );
  if (!ok) return;
  try {
    await tasksApi.deleteChecklistGroup(g.id);
    full.value.checklists = (full.value.checklists || []).filter(x => x.id !== g.id);
    // Чистим плоский checklist от пунктов этой группы
    if (full.value.checklist) full.value.checklist = full.value.checklist.filter(i => i.checklist_id !== g.id);
    refreshCardSummary();
  } catch (e) { showError(e); }
}
function refreshCardSummary() {
  const inList = store.cards.find(c => c.id === props.cardId);
  if (inList) inList.checklist = { done: checklistDone.value, total: checklistTotal.value };
}

// ─── Подзадачи ───
async function addSubtask() {
  const t = newSubtaskTitle.value.trim();
  if (!t) return;
  try {
    const r = await tasksApi.createCard({
      parent_card_id: props.cardId,
      title: t,
    });
    full.value.subtasks.push({
      id: r.id, title: t, is_done: 0, priority: 'medium', due_date: null, sort_order: 999, assignees: [],
    });
    newSubtaskTitle.value = '';
  } catch (e) { showError(e); }
}
async function toggleSubtaskDone(st) {
  const newVal = st.is_done ? 0 : 1;
  try {
    await tasksApi.updateCard(st.id, { is_done: newVal });
    st.is_done = newVal;
  } catch (e) { showError(e); }
}
async function deleteSubtask(st) {
  const ok = await dlg.confirm('Удалить подзадачу', 'Удалить подзадачу «' + st.title + '»?',
    { okText: 'Удалить', danger: true });
  if (!ok) return;
  try {
    await tasksApi.deleteCard(st.id);
    full.value.subtasks = full.value.subtasks.filter(x => x.id !== st.id);
  } catch (e) { showError(e); }
}
function priorityShort(p) { return ({ low: 'низ', medium: 'ср', high: 'выс', urgent: '!' })[p] || ''; }
function isSubOverdue(st) {
  if (!st.due_date || st.is_done) return false;
  return new Date(st.due_date) < new Date();
}
function formatSubDue(s) {
  const d = new Date(s);
  if (isNaN(d)) return '';
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
}
function initials(n) {
  return (n || '').split(/\s+/).filter(Boolean).map(w => w[0]).join('').slice(0, 2).toUpperCase();
}

// ─── Чат / комментарии ───
async function submitComment() {
  const t = newComment.value.trim();
  if (!t) return;
  try {
    const r = await tasksApi.addComment(props.cardId, t);
    full.value.comments.push({
      id: r.id, author_name: currentUserName.value,
      body: t, created_at: new Date().toISOString().slice(0,19).replace('T',' '),
    });
    newComment.value = '';
    const inList = store.cards.find(c => c.id === props.cardId);
    if (inList) inList.comments = (inList.comments || 0) + 1;
  } catch (e) { showError(e); }
}
function canEditComment(c) {
  return c.author_name === currentUserName.value || userStore.currentUser?.role === 'admin';
}
async function editComment(c) {
  const v = await dlg.prompt('Изменить сообщение', { defaultValue: c.body, placeholder: 'Текст сообщения' });
  if (v === null || v === undefined || v === c.body) return;
  try { await tasksApi.updateComment(c.id, v); c.body = v; c.edited_at = new Date().toISOString(); }
  catch (e) { showError(e); }
}
async function removeComment(c) {
  const ok = await dlg.confirm('Удалить сообщение', 'Удалить это сообщение?',
    { okText: 'Удалить', danger: true });
  if (!ok) return;
  try {
    await tasksApi.deleteComment(c.id);
    full.value.comments = full.value.comments.filter(x => x.id !== c.id);
    const inList = store.cards.find(c2 => c2.id === props.cardId);
    if (inList) inList.comments = Math.max(0, (inList.comments || 0) - 1);
  } catch (e) { showError(e); }
}

// ─── Метки ───
async function toggleLabel(l) {
  const has = full.value.label_ids.includes(l.id);
  const newIds = has ? full.value.label_ids.filter(id => id !== l.id) : [...full.value.label_ids, l.id];
  try {
    await tasksApi.setCardLabels(props.cardId, newIds);
    full.value.label_ids = newIds;
    const inList = store.cards.find(c => c.id === props.cardId);
    if (inList) inList.label_ids = newIds;
  } catch (e) { showError(e); }
}
async function addNewLabel() {
  const title = await dlg.prompt('Новая метка', { placeholder: 'Название метки', okText: 'Создать' });
  if (!title) return;
  try { await store.createLabel({ board_id: store.currentBoardId, title, color: '#42A5F5' }); }
  catch (e) { showError(e); }
  dlg.info('Подсказка', 'Цвет метки можно изменить в «Метки доски» (шестерёнка → Метки).', 'info');
}

const LABEL_PALETTE = ['#E87A1E','#10B981','#3B82F6','#F59E0B','#EF4444','#8B5CF6','#06B6D4','#EC4899'];
async function createLabelFromPicker() {
  let title = labelSearch.value.trim();
  if (!title) {
    title = await dlg.prompt('Новая метка', { placeholder: 'Название метки', okText: 'Создать' });
    if (!title) return;
  }
  const color = LABEL_PALETTE[labels.value.length % LABEL_PALETTE.length];
  try {
    await store.createLabel({ board_id: store.currentBoardId, title, color });
    const created = store.labels.find(l => l.title === title);
    if (created) await toggleLabel(created);
    labelSearch.value = '';
  } catch (e) { showError(e); }
}

// ─── Соисполнители ───
async function addAssignee() {
  if (!newAssignee.value) return;
  const newNames = [...full.value.assignees, newAssignee.value];
  try {
    await tasksApi.setAssignees(props.cardId, newNames);
    full.value.assignees = newNames;
    const inList = store.cards.find(c => c.id === props.cardId);
    if (inList) inList.assignees = newNames;
    newAssignee.value = '';
  } catch (e) { showError(e); }
}
async function removeAssignee(name) {
  const newNames = full.value.assignees.filter(n => n !== name);
  try {
    await tasksApi.setAssignees(props.cardId, newNames);
    full.value.assignees = newNames;
    const inList = store.cards.find(c => c.id === props.cardId);
    if (inList) inList.assignees = newNames;
  } catch (e) { showError(e); }
}

// ─── Связи ───
function relationTypeLabel(t) {
  return ({ order: 'Заказ', supplier: 'Поставщик', product: 'Товар', pricing: 'ПСЦ', plan: 'План', so_order: 'Заявка пост.', protocol: 'Протокол' })[t] || t;
}
function relationTypeIcon(t) {
  return ({ order: '📦', supplier: '🚚', product: '🍔', pricing: '💰', plan: '📋', so_order: '📝', protocol: '📑' })[t] || '🔗';
}
// Метку типа в плашке показываем, ТОЛЬКО если entity_label не начинается с
// названия типа. Карточки из drag-from-anywhere имеют label вида
// «Заказ Молочный мир от 20.05» — без этой проверки в плашке было бы
// «Заказ · Заказ Молочный мир...» (дважды). Тип всё равно виден по иконке.
function relationShowType(r) {
  const label = (r?.entity_label || '').trim().toLowerCase();
  if (!label) return true;
  const type = relationTypeLabel(r.entity_type).toLowerCase();
  return !label.startsWith(type);
}
// Возвращает router-link target ({name, query, params}) для типов связей,
// которые можно открыть кликом. Для типов, у которых пока нет deep-link'а —
// возвращает null, и тогда чип рендерится как обычный div (как было раньше).
// При расширении: добавь сюда новый case для supplier/pricing/plan, когда
// будут известны их роуты с параметрами для одной сущности.
function relationTo(r) {
  if (!r) return null;
  switch (r.entity_type) {
    case 'order':
      // /order?orderId=X&mode=view — паттерн из HistoryView.copyOrderLink.
      return { name: 'order', query: { orderId: r.entity_id, mode: 'view' } };
    case 'supplier':
      // /database?tab=suppliers&supplierId=X — DatabaseView читает supplierId
      // и скроллит к карточке поставщика + подсвечивает на 2.4с.
      return { name: 'database', query: { tab: 'suppliers', supplierId: r.entity_id } };
    case 'pricing':
      // /pricing?agreementId=X — PricingView читает agreementId и скроллит
      // к карточке ПСЦ + подсвечивает.
      return { name: 'pricing', query: { agreementId: r.entity_id } };
    case 'plan':
      // /planning?planId=X&mode=view — паттерн из HistoryView.copyPlanLink.
      return { name: 'planning', query: { planId: r.entity_id, mode: 'view' } };
    case 'protocol':
      return '/protocols/' + r.entity_id;
    default:
      return null;
  }
}
const RELATION_TYPES = [
  { value: 'order',     label: 'Заказ',         icon: '📦' },
  { value: 'supplier',  label: 'Поставщик',     icon: '🚚' },
  { value: 'product',   label: 'Товар',         icon: '🍔' },
  { value: 'pricing',   label: 'ПСЦ',           icon: '💰' },
  { value: 'plan',      label: 'План',          icon: '📋' },
  { value: 'so_order',  label: 'Заявка',        icon: '📝' },
  { value: 'protocol',  label: 'Протокол',      icon: '📑' },
];
async function addRelation() {
  if (!relationDraft.value.type || !relationDraft.value.id) return;
  const newList = [
    ...full.value.relations.map(r => ({ entity_type: r.entity_type, entity_id: r.entity_id, entity_label: r.entity_label })),
    { entity_type: relationDraft.value.type, entity_id: relationDraft.value.id, entity_label: relationDraft.value.label || null },
  ];
  try {
    await tasksApi.setRelations(props.cardId, newList);
    full.value = await tasksApi.loadCard(props.cardId);
    relationDraft.value = { type: '', id: '', label: '' };
    showRelationPicker.value = false;
  } catch (e) { showError(e); }
}
async function removeRelation(r) {
  try {
    await tasksApi.deleteRelation(r.id);
    full.value.relations = full.value.relations.filter(x => x.id !== r.id);
  } catch (e) { showError(e); }
}

// ─── Зависимости карточек (блокирует / заблокирована) ───
const depCount = computed(() => {
  const d = full.value?.dependencies;
  return d ? (d.blocks?.length || 0) + (d.blocked_by?.length || 0) : 0;
});
// Кандидаты для новой зависимости — карточки текущей доски, кроме самой
// карточки, архивных, подзадач и уже связанных.
const depCandidates = computed(() => {
  if (!full.value) return [];
  const linked = new Set();
  for (const d of (full.value.dependencies?.blocks || [])) linked.add(d.card_id);
  for (const d of (full.value.dependencies?.blocked_by || [])) linked.add(d.card_id);
  const q = depSearch.value.trim().toLowerCase();
  return store.cards
    .filter(c => c.id !== props.cardId
      && !c.parent_card_id
      && !c.is_archived
      && !linked.has(c.id)
      && (!q || (c.title || '').toLowerCase().includes(q)))
    .slice(0, 30);
});
function toggleDepPicker() {
  showDepPicker.value = !showDepPicker.value;
  if (showDepPicker.value) { depSearch.value = ''; depDraft.value.direction = 'blocked_by'; }
}
function closeDepPicker() { showDepPicker.value = false; }
async function addDependency(otherId) {
  try {
    const res = await tasksApi.addDependency(props.cardId, depDraft.value.direction, otherId);
    if (res?.dependencies) full.value.dependencies = res.dependencies;
    showDepPicker.value = false;
    depSearch.value = '';
    emit('refresh');
  } catch (e) { showError(e, 'Не удалось добавить зависимость'); }
}
async function removeDependency(d) {
  try {
    await tasksApi.deleteDependency(d.id);
    const dep = full.value.dependencies;
    if (dep) {
      dep.blocks = dep.blocks.filter(x => x.id !== d.id);
      dep.blocked_by = dep.blocked_by.filter(x => x.id !== d.id);
    }
    emit('refresh');
  } catch (e) { showError(e, 'Не удалось убрать зависимость'); }
}

async function askDelete() {
  const ok = await dlg.confirm('Удалить карточку',
    'Удалить карточку «' + full.value.card.title + '»? Действие нельзя отменить.',
    { okText: 'Удалить', danger: true });
  if (!ok) return;
  try {
    await tasksApi.deleteCard(props.cardId);
    emit('deleted', props.cardId);
    close();
  } catch (e) { showError(e); }
}

async function askDeleteFromMenu() {
  headerMenuOpen.value = false;
  await askDelete();
}

async function duplicateCard() {
  headerMenuOpen.value = false;
  if (!full.value) return;
  try {
    const src = full.value.card;
    await tasksApi.createCard({
      board_id: src.board_id,
      column_id: src.column_id,
      title: src.title + ' (копия)',
      description: src.description || '',
      priority: src.priority || 'medium',
      due_date: src.due_date || null,
    });
    emit('refresh');
    dlg.info('Готово', 'Создана копия карточки', 'success');
  } catch (e) { showError(e); }
}

async function saveAsTemplate() {
  headerMenuOpen.value = false;
  if (!full.value) return;
  try {
    await tasksApi.saveCardAsTemplate(full.value.card.id);
    dlg.info('Шаблон создан', 'Откройте «Мои шаблоны» в шапке задач, чтобы добавить расписание.', 'success');
  } catch (e) { showError(e); }
}

async function toggleArchive() {
  headerMenuOpen.value = false;
  if (!full.value) return;
  try {
    if (isInArchive.value) {
      const target = columns.value.find(c => !c.is_archive_column);
      if (!target) {
        dlg.info('Ошибка', 'Нет неархивной колонки', 'error');
        return;
      }
      await store.moveCard(props.cardId, target.id, 0);
    } else {
      if (!archiveColumn.value) {
        dlg.info('Ошибка', 'У этой доски нет архивной колонки', 'error');
        return;
      }
      const openBlockers = (full.value.dependencies?.blocked_by || [])
        .filter(d => !d.is_done && !d.is_archived).length;
      if (!(await dlg.confirmCompleteBlocked(openBlockers))) return;
      await store.moveCard(props.cardId, archiveColumn.value.id, 0);
    }
    emit('refresh');
    close();
  } catch (e) { showError(e); }
}

function formatDate(s) {
  if (!s) return '';
  const d = new Date(s.includes('T') ? s : s.replace(' ', 'T'));
  if (isNaN(d)) return s;
  return d.toLocaleDateString('ru-RU', { day:'2-digit', month:'2-digit' }) + ' ' +
         d.toLocaleTimeString('ru-RU', { hour:'2-digit', minute:'2-digit' });
}

// ─── Вложения ───
const ATT_IMG = ['image/jpeg','image/png','image/webp','image/gif'];
function attIsImage(a) { return ATT_IMG.includes(a.mime_type); }
function attTypeOf(a) {
  if (!a) return 'other';
  if (ATT_IMG.includes(a.mime_type)) return 'img';
  if (a.mime_type === 'application/pdf') return 'pdf';
  if (/spreadsheet|excel/.test(a.mime_type || '')) return 'xls';
  if (/word|document/.test(a.mime_type || '')) return 'doc';
  if (/zip/.test(a.mime_type || '')) return 'zip';
  if (a.mime_type === 'text/plain' || a.mime_type === 'text/csv' || a.mime_type === 'application/csv') return 'txt';
  return 'other';
}
function attIconOf(a) {
  return ({ pdf: 'PDF', xls: 'XLS', doc: 'DOC', zip: 'ZIP', txt: 'TXT' })[attTypeOf(a)] || 'FILE';
}
function attFormatSize(n) {
  n = Number(n) || 0;
  if (n < 1024) return n + ' Б';
  if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' КБ';
  return (n / 1024 / 1024).toFixed(1) + ' МБ';
}
function canDeleteAttachment(a) {
  if (userStore.currentUser?.role === 'admin') return true;
  if (a.uploaded_by === currentUserName.value) return true;
  if (full.value?.card?.owner_name === currentUserName.value) return true;
  return false;
}

function pickFiles() { fileInputRef.value?.click(); }
function onFilePick(e) {
  const files = Array.from(e.target.files || []);
  e.target.value = '';
  uploadFiles(files);
}
function onDragOver(e) {
  if (e.dataTransfer?.types?.includes('Files')) isDragOver.value = true;
}
function onDragLeave() { isDragOver.value = false; }
function onDrop(e) {
  isDragOver.value = false;
  const files = Array.from(e.dataTransfer?.files || []);
  uploadFiles(files);
}

async function uploadFiles(files) {
  if (!files.length) return;
  const MAX = 25 * 1024 * 1024;
  const tooBig = files.filter(f => f.size > MAX);
  if (tooBig.length) {
    showError({ message: `Слишком большой файл (макс 25 МБ): ${tooBig[0].name}` });
    files = files.filter(f => f.size <= MAX);
    if (!files.length) return;
  }
  uploadingCount.value += files.length;
  for (const f of files) {
    try {
      const r = await tasksApi.uploadAttachment(props.cardId, f);
      if (full.value?.attachments) full.value.attachments.unshift({
        id: r.id, file_name: r.file_name, file_path: r.file_path,
        file_size: r.file_size, mime_type: r.mime_type,
        uploaded_by: r.uploaded_by, uploaded_at: r.uploaded_at,
      });
      const inList = store.cards.find(c => c.id === props.cardId);
      if (inList) inList.attachments = (inList.attachments || 0) + 1;
    } catch (e) {
      showError(e, `Не удалось загрузить ${f.name}`);
    } finally {
      uploadingDone.value++;
    }
  }
  uploadingCount.value -= uploadingDone.value;
  uploadingDone.value = 0;
}

async function deleteAttachment(a) {
  const ok = await dlg.confirm('Удалить файл', `Удалить «${a.file_name}»?`, { okText: 'Удалить', danger: true });
  if (!ok) return;
  try {
    await tasksApi.deleteAttachment(a.id);
    full.value.attachments = full.value.attachments.filter(x => x.id !== a.id);
    const inList = store.cards.find(c => c.id === props.cardId);
    if (inList) inList.attachments = Math.max(0, (inList.attachments || 0) - 1);
  } catch (e) { showError(e); }
}

function historyText(h) {
  switch (h.action) {
    case 'created': return 'создал(а) карточку';
    case 'moved':   return 'переместил(а) карточку';
    case 'updated': {
      const keys = h.details ? Object.keys(h.details) : [];
      if (!keys.length) return 'изменил(а)';
      const map = { title: 'название', priority: 'приоритет', due_date: 'срок' };
      return 'изменил(а) ' + keys.map(k => map[k] || k).join(', ');
    }
    case 'comment': return 'написал(а) сообщение';
    case 'labels_changed': return 'изменил(а) метки';
    case 'assignees_changed': return 'изменил(а) соисполнителей';
    case 'relations_changed': return 'изменил(а) связи';
    case 'dependency_added':   return 'добавил(а) зависимость';
    case 'dependency_removed': return 'убрал(а) зависимость';
    case 'auto_closed':   return 'карточка закрыта (все соисполнители готовы)';
    case 'auto_reopened': return 'карточка возвращена в работу';
    default: return h.action;
  }
}
function historyKind(h) {
  switch (h.action) {
    case 'created':           return 'created';
    case 'moved':             return 'moved';
    case 'updated':           return 'updated';
    case 'comment':           return 'comment';
    case 'labels_changed':    return 'labels';
    case 'assignees_changed': return 'people';
    case 'relations_changed': return 'relations';
    case 'dependency_added':   return 'relations';
    case 'dependency_removed': return 'relations';
    case 'auto_closed':       return 'closed';
    case 'auto_reopened':     return 'reopened';
    default: return 'other';
  }
}
function historyIcon(h) {
  return ({
    created: 'plus',
    moved: 'columns',
    updated: 'edit',
    comment: 'chat',
    labels_changed: 'tag',
    assignees_changed: 'copy',
    relations_changed: 'paperclip',
    dependency_added: 'lock',
    dependency_removed: 'lock',
    auto_closed: 'check',
    auto_reopened: 'edit',
  })[h.action] || 'edit';
}
function formatRelative(s) {
  if (!s) return '';
  const d = new Date(s.includes('T') ? s : s.replace(' ', 'T'));
  if (isNaN(d)) return s;
  const diff = (Date.now() - d.getTime()) / 1000;
  if (diff < 30) return 'только что';
  if (diff < 60) return Math.floor(diff) + ' сек. назад';
  if (diff < 3600) {
    const m = Math.floor(diff / 60);
    return m + ' ' + plural(m, ['минуту', 'минуты', 'минут']) + ' назад';
  }
  if (diff < 86400) {
    const h = Math.floor(diff / 3600);
    return h + ' ' + plural(h, ['час', 'часа', 'часов']) + ' назад';
  }
  if (diff < 86400 * 7) {
    const days = Math.floor(diff / 86400);
    if (days === 1) return 'вчера';
    return days + ' ' + plural(days, ['день', 'дня', 'дней']) + ' назад';
  }
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: 'long', year: d.getFullYear() === new Date().getFullYear() ? undefined : 'numeric' });
}
function plural(n, forms) {
  const mod10 = n % 10, mod100 = n % 100;
  if (mod10 === 1 && mod100 !== 11) return forms[0];
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) return forms[1];
  return forms[2];
}
</script>

<style scoped>
/* Дизайн-токены подтягиваются глобально с :root через src/styles/tokens.css.
   Teleport в body больше не разрывает каскад — переменные доступны везде. */

/* ═══ Сайдбар справа ═══ */
.task-sidebar-backdrop {
  position: fixed; inset: 0;
  background: var(--tk-bg-overlay);
  z-index: 998;
  animation: fadeIn .15s;
}
.task-sidebar {
  position: fixed; top: 0; right: 0; bottom: 0;
  width: 100%;
  max-width: 600px;   /* было 540 — расширили под дизайн-док */
  background: var(--tk-bg-card);
  z-index: 999;
  display: flex; flex-direction: column;
  box-shadow: -2px 0 16px rgba(9,30,66,0.18);
  animation: slideIn .22s cubic-bezier(.2,.8,.3,1);
  color: var(--tk-text);
}
/* Шапка + свойства + табы прибиты к верху через flex-shrink: 0 (уже сделано
   на каждом из этих блоков ниже). .ts-pane занимает остаток и единственный
   прокручивается — это и есть «sticky-шапка над содержимым» из дизайн-дока. */
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes slideIn { from { transform: translateX(100%); } to { transform: translateX(0); } }

/* Loading-скелетон модалки: повторяет ритм будущего контента — шапка,
   пилюли свойств, секция описания. UiSkeleton с shimmer-анимацией. */
.task-sidebar-loader {
  display: flex; flex-direction: column;
  gap: var(--tk-s-4);
  padding: var(--tk-s-4);
  flex: 1;
}
.tsl-header {
  display: flex; align-items: center; gap: var(--tk-s-3);
  padding-bottom: var(--tk-s-3);
  border-bottom: 1px solid var(--tk-border-soft);
}
.tsl-header-text {
  flex: 1;
  display: flex; flex-direction: column;
  gap: var(--tk-s-1);
}
.tsl-props {
  display: flex; gap: var(--tk-s-2);
  flex-wrap: wrap;
  padding-bottom: var(--tk-s-3);
  border-bottom: 1px solid var(--tk-border-soft);
}
.tsl-pane {
  display: flex; flex-direction: column;
  gap: var(--tk-s-2);
}

/* ═══ Шапка ═══ */
.ts-header {
  display: flex; align-items: flex-start; gap: var(--tk-s-2);
  padding: var(--tk-s-3) var(--tk-s-4) var(--tk-s-2);
  border-bottom: 1px solid var(--tk-border-soft);
  flex-shrink: 0;
}
.ts-back, .ts-close {
  background: none; border: none;
  cursor: pointer;
  width: 32px; height: 32px;
  display: flex; align-items: center; justify-content: center;
  color: var(--tk-text-muted);
  border-radius: var(--tk-r-sm);
  flex-shrink: 0;
  transition: background var(--tk-transition), color var(--tk-transition);
}
.ts-back:hover, .ts-close:hover { background: var(--tk-n-100); color: var(--tk-text); }

/* Действия в шапке (меню «⋮» + закрыть) */
.ts-header-actions {
  display: flex; align-items: center; gap: 2px;
  flex-shrink: 0;
}

/* Меню «⋮» */
.ts-menu-wrap { position: relative; }
.ts-menu-btn {
  background: none; border: none;
  cursor: pointer;
  width: 32px; height: 32px;
  display: inline-flex; align-items: center; justify-content: center;
  gap: 2px;
  color: var(--tk-text-muted, #9C9384);
  border-radius: var(--tk-r-sm, 8px);
  transition: background 140ms ease, color 140ms ease;
}
.ts-menu-btn:hover,
.ts-menu-btn.is-open {
  background: var(--tk-n-100, #F3F0E8);
  color: var(--tk-text, #1A1814);
}
.ts-menu-dot {
  width: 3.5px; height: 3.5px;
  border-radius: 50%;
  background: currentColor;
}

/* Поповер-меню в шапке */
.ts-pop-menu {
  position: absolute;
  top: calc(100% + 6px); right: 0;
  min-width: 220px;
  padding: 4px;
  display: flex; flex-direction: column; gap: 0;
  z-index: 50;
}
.ts-pop-menu-item {
  display: flex; align-items: center; gap: 8px;
  width: 100%;
  padding: 8px 10px;
  background: transparent;
  border: none;
  border-radius: 7px;
  font-family: inherit; font-size: 12.5px; font-weight: 500;
  color: var(--tk-text, #1A1814);
  text-align: left;
  cursor: pointer;
  transition: background 140ms ease, color 140ms ease;
}
.ts-pop-menu-item:hover {
  background: var(--tk-n-100, #F3F0E8);
}
.ts-pop-menu-item.is-danger {
  color: var(--tk-danger, #B23B16);
}
.ts-pop-menu-item.is-danger:hover {
  background: var(--tk-danger-soft, rgba(178,59,22,0.10));
  color: var(--tk-danger, #B23B16);
}
.ts-pop-menu-icon {
  flex-shrink: 0;
  color: var(--tk-text-muted, #9C9384);
}
.ts-pop-menu-item:hover .ts-pop-menu-icon { color: inherit; }
.ts-pop-menu-item.is-danger .ts-pop-menu-icon { color: var(--tk-danger, #B23B16); }
.ts-pop-menu-sep {
  height: 1px;
  background: var(--tk-border-soft, #EFEAE0);
  margin: 4px 6px;
}

.ts-parent-link {
  display: inline-flex; align-items: center; gap: var(--tk-s-1);
  background: none; border: none;
  color: var(--tk-text-muted);
  font-size: var(--tk-fz-xs);
  cursor: pointer;
  padding: 0 var(--tk-s-2) 2px;
  text-align: left; font-family: inherit;
  font-weight: var(--tk-fw-medium);
}
.ts-parent-link:hover { color: var(--tk-accent-text); text-decoration: underline; }

.ts-header-titles { flex: 1; min-width: 0; }
.ts-title-input {
  width: 100%;
  box-sizing: border-box;
  display: block;
  font-size: var(--tk-fz-h1);
  font-weight: var(--tk-fw-bold);
  border: 1px solid transparent;
  border-radius: var(--tk-r-sm);
  padding: var(--tk-s-1) var(--tk-s-2);
  background: transparent;
  color: var(--tk-text);
  font-family: inherit;
  letter-spacing: -0.2px;
  /* Авто-растущий textarea: длинный заголовок переносится и виден целиком */
  resize: none;
  overflow: hidden;
  line-height: 1.3;
  transition: border-color var(--tk-transition), background var(--tk-transition), box-shadow var(--tk-transition);
}
.ts-title-input:hover { border-color: var(--tk-border); }
.ts-title-input:focus { border-color: var(--tk-accent); outline: none; background: var(--tk-n-0); box-shadow: var(--tk-focus-ring); }

.ts-subtitle {
  font-size: var(--tk-fz-sm);
  color: var(--tk-text-muted);
  padding-left: var(--tk-s-2);
  margin-top: 2px;
}
.ts-created {
  font-size: var(--tk-fz-sm);
  color: var(--tk-text-muted);
  padding-left: var(--tk-s-2);
  margin-top: 2px;
}

/* ═══ Свойства карточки — пилюли (Yougile-стиль) ═══ */
.ts-props {
  display: flex; flex-wrap: wrap; gap: 8px;
  padding: var(--tk-s-3) var(--tk-s-4);
  border-bottom: 1px solid var(--tk-border-soft);
  flex-shrink: 0;
}

/* Базовая пилюля: поверх неё прозрачный native input/select, ловит клики.
   Применяется и к <label>, и к <button> — сбрасываем дефолтные стили браузера. */
.ts-pill {
  position: relative;
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 12px;
  border-radius: 999px;
  background: var(--tk-n-100, #F3F0E8);
  color: var(--tk-text-secondary, #3D382E);
  font-size: 12.5px; font-weight: var(--tk-fw-semibold, 600);
  font-family: inherit;
  cursor: pointer;
  border: 1px solid transparent;
  transition: background var(--tk-transition, 140ms ease), border-color var(--tk-transition, 140ms ease);
  max-width: 100%; overflow: hidden;
  text-align: left;
}
button.ts-pill { appearance: none; -webkit-appearance: none; }
.ts-pill:hover { background: var(--tk-n-200, #E6E1D7); }
.ts-pill-text {
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  line-height: 1.2;
}
.ts-pill-icon {
  flex-shrink: 0; display: inline-flex; align-items: center;
  width: 14px; height: 14px;
}
.ts-pill-dot {
  width: 9px; height: 9px; border-radius: 50%;
  background: currentColor;
  margin: auto;
}
.ts-pill-col-dot {
  width: 10px; height: 10px; border-radius: 50%;
  margin: auto;
}
.ts-pill.is-open {
  border-color: var(--tk-accent, #E87A1E);
  box-shadow: var(--tk-focus-ring, 0 0 0 3px rgba(232,122,30,0.25));
}
.ts-pill-chev {
  flex-shrink: 0; margin-left: 2px;
  opacity: 0.55;
}

/* Обёртка пилюли (для абсолютного позиционирования поповера) */
.ts-pill-wrap { position: relative; display: inline-flex; }

/* Поповер: либо список (.ts-pop), либо flat-контейнер (.ts-pop-flat) под кастомный пикер */
.ts-pop {
  position: absolute;
  top: calc(100% + 6px); left: 0;
  z-index: 1100;
  min-width: 200px; max-width: 320px;
  background: var(--tk-bg-popover, #fff);
  border: 1px solid var(--tk-border, #E6E1D7);
  border-radius: 10px;
  box-shadow: var(--tk-shadow-popover, 0 12px 32px rgba(15,23,42,0.14), 0 2px 4px rgba(15,23,42,0.06));
  padding: 6px;
  display: flex; flex-direction: column; gap: 2px;
}
.ts-pop.ts-pop-flip-right {
  left: auto; right: 0;
}
.ts-pop-flat {
  padding: 0; border: none; background: transparent;
  box-shadow: none;
  min-width: 0; max-width: none;
}
.ts-pop-item {
  display: flex; align-items: center; gap: 8px;
  padding: 7px 10px;
  border: none; background: transparent;
  border-radius: 7px;
  font-family: inherit; font-size: 12.5px; font-weight: 500;
  color: var(--tk-text);
  text-align: left;
  cursor: pointer;
  transition: background 140ms ease;
}
.ts-pop-item:hover { background: var(--tk-n-100); }
.ts-pop-item.is-active { background: var(--tk-accent-soft); color: var(--tk-accent-text); font-weight: 600; }
.ts-pop-item-dot {
  flex-shrink: 0;
  width: 10px; height: 10px;
  border-radius: 50%;
  background: var(--tk-n-400);
}
.ts-pop-item-dot.prio-bg-urgent { background: var(--tk-prio-urgent-fg); }
.ts-pop-item-dot.prio-bg-high   { background: var(--tk-prio-high-fg); }
.ts-pop-item-dot.prio-bg-medium { background: var(--tk-prio-medium-fg); }
.ts-pop-item-dot.prio-bg-low    { background: var(--tk-prio-low-fg); }
.ts-pop-item-label { flex: 1; }
.ts-pop-item-check { color: var(--tk-accent, #E87A1E); }

/* Цвет фона карточки (F4) */
.ts-pill-color-dot {
  display: inline-block; width: 12px; height: 12px;
  border-radius: 50%;
  border: 2px solid var(--tk-border, #E6E1D7);
  background: transparent;
  box-sizing: border-box;
}
.ts-pop-color {
  background: var(--tk-surface, #fff);
  border: 1px solid var(--tk-border, #E6E1D7);
  border-radius: 10px;
  box-shadow: var(--tk-shadow-popover, 0 12px 32px rgba(15,23,42,0.14), 0 2px 4px rgba(15,23,42,0.06));
  padding: 10px;
  display: flex; flex-direction: column; gap: 8px;
}
.ts-color-grid {
  display: grid; grid-template-columns: repeat(6, 26px);
  gap: 6px;
}
.ts-color-sw {
  width: 26px; height: 26px;
  border-radius: 6px;
  border: 2px solid transparent;
  cursor: pointer;
  padding: 0;
  display: flex; align-items: center; justify-content: center;
  color: rgba(15,23,42,0.65);
  transition: transform 120ms ease, border-color 120ms ease, box-shadow 120ms ease;
}
.ts-color-sw:hover { transform: scale(1.08); border-color: rgba(15,23,42,0.18); }
.ts-color-sw.is-active {
  border-color: #172B4D;
  box-shadow: 0 0 0 1px #fff inset;
}
.ts-color-clear {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 10px;
  border: 1px solid var(--tk-border, #E6E1D7);
  background: var(--tk-n-50, #F7F4EE);
  border-radius: 6px;
  font: inherit; font-size: 12px; color: var(--tk-text-muted);
  cursor: pointer;
}
.ts-color-clear:hover { background: var(--tk-n-100); color: var(--tk-text); }

/* Таймер (C4) */
.ts-timer-row {
  display: flex; align-items: center; gap: 10px;
  flex-wrap: wrap;
}
.ts-timer-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 12px;
  border: 1px solid var(--tk-border, #E6E1D7);
  background: var(--tk-surface, #fff);
  border-radius: 999px;
  font: inherit; font-size: 12.5px; font-weight: 600;
  color: var(--tk-text);
  cursor: pointer;
  transition: background 140ms ease, border-color 140ms ease, color 140ms ease;
}
.ts-timer-btn:hover { background: var(--tk-n-100); }
.ts-timer-btn.is-running {
  background: color-mix(in srgb, var(--tk-danger, #D33A2C) 12%, #fff);
  border-color: color-mix(in srgb, var(--tk-danger, #D33A2C) 50%, transparent);
  color: var(--tk-danger, #D33A2C);
}
.ts-timer-btn.is-running:hover {
  background: color-mix(in srgb, var(--tk-danger, #D33A2C) 18%, #fff);
}
.ts-timer-live {
  display: inline-flex; align-items: center; gap: 8px;
  font-size: 13px; font-weight: 600;
  color: var(--tk-text);
}
.ts-timer-live-dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: var(--tk-danger, #D33A2C);
  animation: ts-timer-pulse 1.4s ease-in-out infinite;
}
@keyframes ts-timer-pulse {
  0%, 100% { opacity: 0.4; transform: scale(1); }
  50%      { opacity: 1;   transform: scale(1.25); }
}
.ts-timer-live-text { font-variant-numeric: tabular-nums; }
.ts-timer-hint {
  font-size: 12px; color: var(--tk-text-muted);
}
.ts-timer-users {
  list-style: none; margin: 8px 0 0 0; padding: 0;
  display: flex; flex-direction: column; gap: 4px;
}
.ts-timer-user {
  display: flex; align-items: center; gap: 8px;
  padding: 4px 0;
  font-size: 12.5px;
}
.ts-timer-bubble {
  width: 22px; height: 22px; font-size: 10px;
}
.ts-timer-user-name { flex: 1; color: var(--tk-text); }
.ts-timer-user-sec {
  font-variant-numeric: tabular-nums;
  font-weight: 600; color: var(--tk-text-muted);
}

/* Поповер выбора соисполнителя */
.ts-assignee-add-wrap { position: relative; display: inline-block; margin-top: 6px; }
.ts-assignee-add-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 12px;
  border: 1px dashed var(--tk-border);
  background: transparent;
  border-radius: 7px;
  font-family: inherit; font-size: 12.5px; font-weight: 600;
  color: var(--tk-text-muted);
  cursor: pointer;
  transition: background var(--tk-transition), border-color var(--tk-transition), color var(--tk-transition);
}
.ts-assignee-add-btn:hover {
  background: var(--tk-accent-soft);
  border-color: var(--tk-accent);
  border-style: solid;
  color: var(--tk-accent-text);
}
.ts-assignee-add-btn.is-open {
  border-style: solid;
  border-color: var(--tk-accent);
  color: var(--tk-accent-text);
  background: var(--tk-accent-soft);
}
.ts-pop-assignee {
  min-width: 240px; max-width: 320px;
  max-height: 320px;
  padding: 6px;
  display: flex; flex-direction: column; gap: 0;
}
.ts-pop-search {
  width: 100%; box-sizing: border-box;
  padding: 6px 10px;
  border: 1px solid var(--tk-border);
  border-radius: 6px;
  background: var(--tk-n-50, #FAF9F5);
  color: var(--tk-text);
  font-family: inherit; font-size: 12.5px;
  margin-bottom: 4px;
}
.ts-pop-search:focus {
  outline: none;
  border-color: var(--tk-accent);
  box-shadow: var(--tk-focus-ring);
  background: #fff;
}
.ts-pop-list {
  overflow-y: auto;
  max-height: 240px;
  display: flex; flex-direction: column; gap: 2px;
}
.ts-pop-empty {
  padding: 14px 10px; text-align: center;
  color: var(--tk-text-muted); font-size: 12px; font-style: italic;
}
.ts-pop-item-bubble {
  flex-shrink: 0;
  display: inline-flex; align-items: center; justify-content: center;
  width: 24px; height: 24px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--tk-accent), #F4A261);
  color: #fff;
  font-size: 10px; font-weight: 700;
  border: 1.5px solid var(--tk-bg-card, #fff);
}

/* === Связи: чипы и кнопка добавления === */
.ts-rel-chips {
  display: flex; flex-wrap: wrap; gap: 6px;
  margin-bottom: 8px;
}
.ts-rel-chip {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 4px 8px 4px 6px;
  background: var(--tk-n-50, #FAF9F5);
  border: 1px solid var(--tk-border, #E6E1D7);
  border-radius: 999px;
  font-size: 11.5px; line-height: 1;
  color: var(--tk-text);
  text-decoration: none;
  transition: background 140ms ease, border-color 140ms ease, transform 140ms ease;
  cursor: default;
  max-width: 100%;
}
a.ts-rel-chip { cursor: pointer; }
a.ts-rel-chip:hover {
  background: #fff;
  border-color: var(--tk-accent, #E87A1E);
  color: var(--tk-text);
  transform: translateY(-1px);
}
.ts-rel-chip-icon {
  font-size: 13px; line-height: 1;
  flex-shrink: 0;
}
.ts-rel-chip-type {
  font-size: 10.5px; font-weight: 600;
  color: var(--tk-text-muted, #9C9384);
  text-transform: uppercase;
  letter-spacing: 0.3px;
  flex-shrink: 0;
}
.ts-rel-chip-label {
  font-size: 12px; font-weight: 500;
  color: var(--tk-text);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  max-width: 220px;
}
.ts-rel-chip-del {
  display: inline-flex; align-items: center; justify-content: center;
  width: 16px; height: 16px; padding: 0;
  border: none; background: transparent;
  color: var(--tk-text-muted, #9C9384);
  border-radius: 50%;
  cursor: pointer;
  transition: background 140ms ease, color 140ms ease;
  margin-left: 2px;
}
.ts-rel-chip-del:hover {
  background: var(--tk-prio-urgent-bg, #FEE7E0);
  color: var(--tk-prio-urgent-fg, #B23B16);
}

/* Кнопка-чип «+ Добавить связь» */
.ts-rel-add-wrap { position: relative; display: inline-block; }
.ts-rel-add-btn {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 5px 10px 5px 8px;
  background: transparent;
  border: 1px dashed var(--tk-border, #E6E1D7);
  border-radius: 999px;
  font-family: inherit; font-size: 11.5px; font-weight: 600;
  color: var(--tk-text-muted, #9C9384);
  cursor: pointer;
  transition: background 140ms ease, border-color 140ms ease, color 140ms ease;
}
.ts-rel-add-btn:hover,
.ts-rel-add-btn.is-open {
  background: var(--tk-accent-soft, rgba(232,122,30,0.10));
  border-color: var(--tk-accent, #E87A1E);
  border-style: solid;
  color: var(--tk-accent-text, #B85A0E);
}

/* Зависимости карточки */
.ts-dep-group { margin-bottom: 8px; }
.ts-dep-label {
  display: flex; align-items: center; gap: 5px;
  font-size: 10.5px; font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--tk-text-muted, #9C9384);
  margin-bottom: 5px;
}
.ts-dep-label-blocked { color: #C0392B; }
.ts-dep-chips { display: flex; flex-direction: column; gap: 4px; }
.ts-dep-chip {
  display: flex; align-items: center; gap: 6px;
  padding: 5px 6px 5px 10px;
  background: var(--tk-n-50, #FAF9F5);
  border: 1px solid var(--tk-border-soft, #EFEAE0);
  border-radius: 8px;
  cursor: pointer;
  transition: background 140ms ease, border-color 140ms ease;
}
.ts-dep-chip:hover {
  background: #fff;
  border-color: var(--tk-accent, #E87A1E);
}
.ts-dep-chip-title {
  flex: 1; min-width: 0;
  font-size: 12.5px; font-weight: 600;
  color: var(--tk-text, #1A1814);
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.ts-dep-chip-done .ts-dep-chip-title {
  text-decoration: line-through;
  color: var(--tk-text-muted, #9C9384);
}
.ts-dep-chip-state {
  font-size: 9.5px; font-weight: 700;
  text-transform: uppercase;
  color: #2E7D32;
  background: rgba(76,175,80,0.14);
  padding: 1px 6px;
  border-radius: 4px;
  flex-shrink: 0;
}
.ts-dep-chip-del {
  display: inline-flex; align-items: center; justify-content: center;
  width: 20px; height: 20px;
  border: none; background: transparent;
  border-radius: 5px;
  color: var(--tk-text-muted, #9C9384);
  cursor: pointer;
  flex-shrink: 0;
  transition: background 140ms ease, color 140ms ease;
}
.ts-dep-chip-del:hover { background: rgba(212,70,56,0.12); color: #C0392B; }
.ts-dep-results {
  display: flex; flex-direction: column;
  max-height: 200px; overflow-y: auto;
  gap: 2px;
}
.ts-dep-result {
  text-align: left;
  padding: 6px 8px;
  background: transparent; border: none;
  border-radius: 6px;
  font-family: inherit; font-size: 12.5px;
  color: var(--tk-text, #1A1814);
  cursor: pointer;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  transition: background 120ms ease;
}
.ts-dep-result:hover { background: var(--tk-accent-soft, rgba(232,122,30,0.10)); }
.ts-dep-noresult {
  padding: 10px 8px;
  font-size: 12px;
  color: var(--tk-text-muted, #9C9384);
  text-align: center;
}

/* Поповер связи */
.ts-pop-rel {
  min-width: 280px; max-width: 320px;
  padding: 10px;
  display: flex; flex-direction: column; gap: 8px;
}
.ts-pop-section-label {
  font-size: 10.5px; font-weight: 700;
  color: var(--tk-text-muted, #9C9384);
  text-transform: uppercase;
  letter-spacing: 0.4px;
  margin-bottom: 2px;
}
.ts-rel-types {
  display: grid; grid-template-columns: repeat(2, 1fr); gap: 4px;
}
.ts-rel-type {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 8px;
  background: var(--tk-n-50, #FAF9F5);
  border: 1px solid var(--tk-border, #E6E1D7);
  border-radius: 7px;
  font-family: inherit; font-size: 11.5px; font-weight: 500;
  color: var(--tk-text);
  cursor: pointer;
  text-align: left;
  transition: background 140ms ease, border-color 140ms ease, color 140ms ease;
}
.ts-rel-type:hover {
  background: #fff;
  border-color: var(--tk-accent, #E87A1E);
}
.ts-rel-type.is-active {
  background: var(--tk-accent-soft, rgba(232,122,30,0.10));
  border-color: var(--tk-accent, #E87A1E);
  color: var(--tk-accent-text, #B85A0E);
  font-weight: 600;
}
.ts-rel-type-icon { font-size: 14px; line-height: 1; flex-shrink: 0; }
.ts-rel-type-label { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* Кнопки действий внутри поповера */
.ts-pop-actions {
  display: flex; gap: 6px; justify-content: flex-end;
  padding-top: 6px;
  border-top: 1px solid var(--tk-border-soft, #EFEAE0);
  margin-top: 2px;
}
.ts-pop-btn {
  padding: 6px 12px;
  border: 1px solid var(--tk-border, #E6E1D7);
  background: #fff;
  color: var(--tk-text-secondary, #534D40);
  border-radius: 7px;
  font-family: inherit; font-size: 12px; font-weight: 600;
  cursor: pointer;
  transition: background 140ms ease, border-color 140ms ease, color 140ms ease;
}
.ts-pop-btn:hover {
  background: var(--tk-n-100, #F3F0E8);
  color: var(--tk-text);
}
.ts-pop-btn-primary {
  background: var(--tk-accent, #E87A1E);
  border-color: var(--tk-accent, #E87A1E);
  color: #fff;
}
.ts-pop-btn-primary:hover:not(:disabled) {
  background: var(--tk-accent-hover, #D26B12);
  border-color: var(--tk-accent-hover, #D26B12);
  color: #fff;
}
.ts-pop-btn-primary:disabled {
  opacity: 0.55; cursor: default;
}

/* Приоритет — цвета по уровню */
.ts-pill-prio-urgent { background: var(--tk-prio-urgent-bg); color: var(--tk-prio-urgent-fg); }
.ts-pill-prio-high   { background: var(--tk-prio-high-bg);   color: var(--tk-prio-high-fg); }
.ts-pill-prio-medium { background: var(--tk-prio-medium-bg); color: var(--tk-prio-medium-fg); }
.ts-pill-prio-low    { background: var(--tk-prio-low-bg);    color: var(--tk-prio-low-fg); }

/* Срок — состояния */
.ts-pill-due { background: var(--tk-n-100, #F3F0E8); color: var(--tk-text-secondary, #3D382E); }
.ts-pill-due.warn    { background: var(--tk-prio-high-bg); color: var(--tk-prio-high-fg); }
.ts-pill-due.fire    { background: var(--tk-warning-soft, rgba(187,106,10,0.12)); color: var(--tk-warning, #BB6A0A); }
.ts-pill-due.overdue { background: var(--tk-prio-urgent-bg); color: var(--tk-prio-urgent-fg); }

/* Кнопка-крестик «Убрать срок» */
.ts-pill-clear {
  position: relative; z-index: 2;
  display: inline-flex; align-items: center; justify-content: center;
  width: 16px; height: 16px; padding: 0; margin-left: 2px;
  border: none; background: transparent; border-radius: 50%;
  color: currentColor; opacity: 0.55;
  cursor: pointer;
  transition: opacity var(--tk-transition), background var(--tk-transition);
}
.ts-pill-clear:hover { opacity: 1; background: rgba(0,0,0,0.10); }

/* ═══ Вкладки ═══ */
.ts-tabs {
  display: flex; gap: 2px; padding: var(--tk-s-1) var(--tk-s-2) 0;
  border-bottom: 1px solid var(--tk-border-soft);
  flex-shrink: 0;
}
.ts-tab {
  background: none; border: none; cursor: pointer;
  padding: var(--tk-s-2) var(--tk-s-3);
  font-size: var(--tk-fz-md);
  font-weight: var(--tk-fw-semibold);
  color: var(--tk-text-muted);
  border-radius: var(--tk-r-sm) var(--tk-r-sm) 0 0;
  display: flex; align-items: center; gap: 6px;
  font-family: inherit;
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
  transition: color var(--tk-transition), background var(--tk-transition), border-color var(--tk-transition);
}
.ts-tab:hover { color: var(--tk-text); background: var(--tk-n-100); }
.ts-tab.active {
  color: var(--tk-accent-text);
  border-bottom-color: var(--tk-accent);
  background: transparent;
}
.ts-tab-badge {
  background: var(--tk-accent);
  color: #fff;
  font-size: 10px;
  font-weight: var(--tk-fw-bold);
  padding: 1px 6px;
  border-radius: 10px;
  min-width: 18px; text-align: center;
}

/* ═══ Панель содержимого ═══ */
.ts-pane {
  flex: 1; overflow-y: auto;
  padding: var(--tk-s-4) var(--tk-s-4) var(--tk-s-5);
}
.ts-section { margin-bottom: var(--tk-s-5); }
.ts-section-title {
  font-size: var(--tk-fz-xs);
  font-weight: var(--tk-fw-bold);
  color: var(--tk-text);
  text-transform: uppercase;
  letter-spacing: .4px;
  margin-bottom: var(--tk-s-2);
  display: flex; align-items: center; gap: var(--tk-s-2);
}
.ts-section-meta {
  font-size: var(--tk-fz-sm);
  font-weight: var(--tk-fw-medium);
  color: var(--tk-text-muted);
  margin-left: auto;
  text-transform: none;
}
.ts-section-add {
  margin-left: auto;
  display: inline-flex; align-items: center; gap: 4px;
  background: none; border: none; cursor: pointer;
  color: var(--tk-accent-text);
  font-size: var(--tk-fz-sm);
  font-weight: var(--tk-fw-semibold);
  text-transform: none;
  padding: 2px var(--tk-s-2);
  border-radius: var(--tk-r-sm);
  font-family: inherit;
  transition: background var(--tk-transition);
}
.ts-section-add:hover { background: var(--tk-accent-soft); }

.ts-empty {
  color: var(--tk-text-muted);
  font-size: var(--tk-fz-sm);
  font-style: italic;
  padding: var(--tk-s-1) 0;
}
.ts-section-danger {
  margin-top: var(--tk-s-6);
  padding-top: var(--tk-s-4);
  border-top: 1px solid var(--tk-border-soft);
}
.ts-delete-btn {
  width: 100%;
  display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  background: var(--tk-danger);
  color: #fff;
  border: 1px solid var(--tk-danger);
  border-radius: var(--tk-r-sm);
  padding: var(--tk-s-2) var(--tk-s-3);
  font-size: var(--tk-fz-md);
  font-weight: var(--tk-fw-semibold);
  cursor: pointer;
  font-family: inherit;
  transition: background var(--tk-transition);
}
.ts-delete-btn:hover { background: #B22A1F; }

.ts-textarea {
  width: 100%; box-sizing: border-box;
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-sm);
  padding: var(--tk-s-2) var(--tk-s-3);
  font-size: var(--tk-fz-md);
  font-family: inherit; resize: vertical;
  background: var(--tk-n-0);
  color: var(--tk-text);
  transition: border-color var(--tk-transition), box-shadow var(--tk-transition);
}
.ts-textarea:hover { border-color: var(--tk-n-300); }
.ts-textarea:focus { outline: none; border-color: var(--tk-accent); box-shadow: var(--tk-focus-ring); }

/* Markdown — кнопка-действие в заголовке секции */
.ts-section-action {
  margin-left: auto;
  background: none; border: none; cursor: pointer;
  font-size: var(--tk-fz-xs); font-weight: var(--tk-fw-semibold);
  color: var(--tk-text-muted);
  text-transform: none; letter-spacing: 0;
  padding: 2px 6px; border-radius: var(--tk-r-sm);
  display: inline-flex; align-items: center; gap: 4px;
  font-family: inherit;
}
.ts-section-action:hover { background: var(--tk-n-100); color: var(--tk-text); }

/* Markdown — отображение body комментариев чата */
.ts-md-view {
  font-size: var(--tk-fz-md, 13px);
  color: var(--tk-text);
  line-height: 1.55;
  word-wrap: break-word;
  overflow-wrap: anywhere;
}
.ts-md-view p { margin: 0 0 6px; }
.ts-md-view p:last-child { margin-bottom: 0; }
.ts-md-view h3 { margin: 6px 0 4px; font-size: 15px; font-weight: 700; }
.ts-md-view h4 { margin: 6px 0 4px; font-size: 14px; font-weight: 700; }
.ts-md-view ul, .ts-md-view ol { margin: 0 0 6px; padding-left: 22px; }
.ts-md-view li { margin: 1px 0; }
.ts-md-view a { color: var(--tk-accent-text, #B85A0E); text-decoration: underline; }
.ts-md-view a:hover { color: var(--tk-accent, #E87A1E); }
.ts-md-view code {
  background: var(--tk-n-100, #F1F2F4);
  padding: 1px 5px;
  border-radius: 3px;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 0.92em;
}
.ts-md-view strong { font-weight: 700; }
.ts-md-view em { font-style: italic; }
.ts-md-view :deep(.md-mention),
.ts-md-view .md-mention {
  display: inline-flex; align-items: center;
  padding: 1px 7px;
  background: var(--tk-accent-soft, rgba(232,122,30,0.14));
  color: var(--tk-accent-text, #B85A0E);
  border-radius: 999px;
  font-weight: 600;
  font-size: 0.92em;
  line-height: 1.4;
}


/* ═══ Чек-лист ═══ */
.ts-progress {
  height: 6px;
  background: var(--tk-n-200);
  border-radius: 3px;
  overflow: hidden;
  margin-bottom: var(--tk-s-2);
}
.ts-progress-bar {
  height: 100%;
  background: var(--tk-success);
  transition: width .25s;
}

.ts-checklist { list-style: none; padding: 0; margin: 0 0 var(--tk-s-1); }
.ts-chk-item {
  display: flex; align-items: center; gap: var(--tk-s-3);
  padding: 6px var(--tk-s-1);
  border-radius: var(--tk-r-sm);
  border-bottom: 1px solid var(--tk-border-soft);
  min-height: 32px;
}
.ts-chk-item:last-child { border-bottom: none; }
.ts-chk-item:hover { background: var(--tk-n-50); }

/* ═══ Квадратный чекбокс — для чек-листа ═══ */
.ts-chk-box {
  appearance: none; -webkit-appearance: none;
  width: 18px; height: 18px;
  border: 2px solid var(--tk-n-300);
  border-radius: var(--tk-r-sm);
  background: var(--tk-n-0);
  cursor: pointer;
  flex-shrink: 0; margin: 0;
  display: inline-flex; align-items: center; justify-content: center;
  transition: all var(--tk-transition);
}
.ts-chk-box:hover { border-color: var(--tk-success); }
.ts-chk-box:checked {
  background: var(--tk-success);
  border-color: var(--tk-success);
}
.ts-chk-box:checked::after {
  content: ''; display: block;
  width: 8px; height: 4px;
  border-left: 2px solid #fff; border-bottom: 2px solid #fff;
  transform: rotate(-45deg) translate(1px, -1px);
}

/* ═══ Круглый чекбокс — для подзадач ═══ */
.ts-round-chk {
  appearance: none; -webkit-appearance: none;
  width: 20px; height: 20px;
  border: 2px solid var(--tk-n-300);
  border-radius: 50%;
  background: var(--tk-n-0);
  cursor: pointer;
  flex-shrink: 0; margin: 0;
  display: inline-flex; align-items: center; justify-content: center;
  transition: all var(--tk-transition);
}
.ts-round-chk:hover { border-color: var(--tk-success); }
.ts-round-chk:checked {
  background: var(--tk-success);
  border-color: var(--tk-success);
}
.ts-round-chk:checked::after {
  content: ''; display: block;
  width: 9px; height: 5px;
  border-left: 2px solid #fff; border-bottom: 2px solid #fff;
  transform: rotate(-45deg) translate(1px, -1px);
}

.ts-chk-text {
  flex: 1 1 auto; min-width: 0;
  font-size: var(--tk-fz-md);
  color: var(--tk-text);
  line-height: 1.4;
  cursor: text;
  word-break: break-word;
  padding: 2px var(--tk-s-1);
  border-radius: var(--tk-r-sm);
}
.ts-chk-text:hover { background: var(--tk-n-100); }
.ts-chk-text.done { color: var(--tk-text-muted); text-decoration: line-through; }
.ts-chk-input {
  flex: 1 1 auto; min-width: 0;
  padding: 3px 6px;
  font-size: var(--tk-fz-md);
  border: 1px solid var(--tk-accent);
  border-radius: var(--tk-r-sm);
  background: var(--tk-n-0);
  color: var(--tk-text);
  font-family: inherit;
}
.ts-chk-input:focus { outline: none; box-shadow: var(--tk-focus-ring); }

.ts-icon-btn {
  flex-shrink: 0;
  background: none; border: none; cursor: pointer;
  color: var(--tk-text-muted);
  width: 24px; height: 24px;
  display: inline-flex; align-items: center; justify-content: center;
  border-radius: var(--tk-r-sm);
  transition: background var(--tk-transition), color var(--tk-transition);
}
.ts-icon-btn:hover { color: var(--tk-danger); background: var(--tk-danger-soft); }

.ts-chk-add { display: flex; gap: var(--tk-s-2); margin-top: var(--tk-s-2); }
.ts-chk-add input {
  flex: 1;
  padding: 6px var(--tk-s-2);
  font-size: var(--tk-fz-md);
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-sm);
  background: var(--tk-n-0);
  color: var(--tk-text);
  font-family: inherit;
  transition: border-color var(--tk-transition), box-shadow var(--tk-transition);
}
.ts-chk-add input:focus { outline: none; border-color: var(--tk-accent); box-shadow: var(--tk-focus-ring); }
.ts-btn-sm {
  padding: 0 var(--tk-s-3); height: 32px;
  font-size: var(--tk-fz-md);
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 36px;
}

/* Группа чек-листа: заголовок редактируется, есть кнопка удаления группы */
.ts-chk-group { position: relative; }
.ts-chk-group-title {
  cursor: text;
  padding: 2px 4px;
  border-radius: 4px;
  transition: background var(--tk-transition);
}
.ts-chk-group-title:hover { background: var(--tk-n-100, #F3F0E8); }
.ts-chk-group-title-input {
  flex: 1; min-width: 0;
  padding: 3px 8px;
  border: 1px solid var(--tk-border);
  border-radius: 6px;
  background: var(--tk-n-0);
  color: var(--tk-text);
  font-family: inherit; font-size: inherit; font-weight: inherit;
  outline: none;
}
.ts-chk-group-title-input:focus { border-color: var(--tk-accent); box-shadow: var(--tk-focus-ring); }
.ts-chk-group-del {
  /* margin-left убрали: section-meta уже отжата вправо через margin-left:auto,
     корзина сидит сразу за счётчиком (тоже справа). */
  opacity: 0;
  transition: opacity var(--tk-transition);
}
.ts-chk-group:hover .ts-chk-group-del { opacity: 1; }

/* Кнопка «+ Новый чек-лист» */
.ts-add-checklist-btn {
  display: inline-flex; align-items: center; gap: 6px;
  margin-top: 4px;
  padding: 8px 14px;
  background: transparent;
  border: 1px dashed var(--tk-border);
  border-radius: 8px;
  color: var(--tk-text-muted);
  font-family: inherit; font-size: 13px; font-weight: 600;
  cursor: pointer;
  transition: background var(--tk-transition), border-color var(--tk-transition), color var(--tk-transition);
}
.ts-add-checklist-btn:hover {
  background: var(--tk-accent-soft);
  border-color: var(--tk-accent);
  border-style: solid;
  color: var(--tk-accent-text);
}

/* ═══ Подзадачи ═══ */
.ts-subtasks {
  list-style: none; padding: 0; margin: 0 0 var(--tk-s-2);
  display: flex; flex-direction: column; gap: 6px;
}
.ts-sub-item {
  display: flex; align-items: center; gap: var(--tk-s-2);
  padding: var(--tk-s-2) var(--tk-s-3);
  background: var(--tk-bg-card, #fff);
  border: 1px solid var(--tk-border-soft, #EEF0F4);
  border-radius: var(--tk-r-md, 10px);
  min-height: 40px;
  transition: border-color var(--tk-transition), box-shadow var(--tk-transition);
}
.ts-sub-item:hover {
  border-color: var(--tk-border, #E4E7EE);
  box-shadow: 0 2px 6px rgba(15,23,42,0.06);
}

.ts-sub-text {
  flex: 1 1 auto; min-width: 0;
  display: flex; align-items: center; justify-content: space-between; gap: var(--tk-s-2);
  background: none; border: none; cursor: pointer;
  text-align: left;
  padding: 2px 0;
  border-radius: var(--tk-r-sm);
  color: var(--tk-text);
  font-family: inherit;
  font-size: var(--tk-fz-md);
  transition: color var(--tk-transition);
}
.ts-sub-text:hover { color: var(--tk-accent-text); }
.ts-sub-text.done .ts-sub-title { text-decoration: line-through; color: var(--tk-text-muted); }
.ts-sub-title { word-break: break-word; min-width: 0; }
.ts-sub-meta {
  display: inline-flex; align-items: center; gap: var(--tk-s-1);
  flex-shrink: 0;
}
.ts-sub-prio {
  font-size: 10px;
  font-weight: var(--tk-fw-bold);
  padding: 1px 5px;
  border-radius: var(--tk-r-sm);
  text-transform: lowercase;
}
.ts-sub-prio.prio-bg-low    { background: var(--tk-prio-low-bg);    color: var(--tk-prio-low-fg); }
.ts-sub-prio.prio-bg-high   { background: var(--tk-prio-high-bg);   color: var(--tk-prio-high-fg); }
.ts-sub-prio.prio-bg-urgent { background: var(--tk-prio-urgent-bg); color: var(--tk-prio-urgent-fg); }
.ts-sub-due {
  font-size: 10.5px;
  font-weight: var(--tk-fw-semibold);
  padding: 1px 6px;
  border-radius: var(--tk-r-sm);
  background: var(--tk-success-soft);
  color: var(--tk-success);
}
.ts-sub-due.overdue { background: var(--tk-prio-urgent-bg); color: var(--tk-prio-urgent-fg); }
.ts-sub-assignees { display: inline-flex; gap: 0; }
.ts-sub-bubble {
  display: inline-flex; align-items: center; justify-content: center;
  width: 18px; height: 18px; border-radius: 50%;
  background: linear-gradient(135deg, var(--tk-accent), #F4A261);
  color: #fff; font-size: 8px;
  font-weight: var(--tk-fw-bold);
  border: 1.5px solid var(--tk-bg-card);
  margin-left: -4px;
}
.ts-sub-bubble:first-child { margin-left: 0; }

/* ═══ Вложения ═══ */
.ts-att-drop {
  display: flex; align-items: center; justify-content: center;
  gap: 8px;
  border: 1.5px dashed var(--tk-border, #E6E1D7);
  border-radius: 10px;
  padding: 14px 12px;
  color: var(--tk-text-muted, #9C9384);
  font-size: 12px; font-weight: 500;
  cursor: pointer;
  background: var(--tk-n-50, #FAF9F5);
  transition: background 160ms ease, border-color 160ms ease, color 160ms ease, transform 160ms ease;
  user-select: none;
}
.ts-att-drop:hover {
  border-color: var(--tk-accent, #E87A1E);
  background: #fff;
  color: var(--tk-text);
}
.ts-att-drop.is-drag {
  background: var(--tk-accent-soft, rgba(232,122,30,0.10));
  border-color: var(--tk-accent, #E87A1E);
  border-style: solid;
  color: var(--tk-accent-text, #B85A0E);
  transform: scale(1.01);
}
.ts-att-drop.is-upload { opacity: 0.7; pointer-events: none; }
.ts-att-input { display: none; }
.ts-att-drop-text { font-weight: 600; }

.ts-att-list {
  list-style: none; padding: 0;
  margin: 8px 0 0;
  display: flex; flex-direction: column; gap: 4px;
}
.ts-att-item {
  display: flex; align-items: center; gap: 10px;
  padding: 6px 8px;
  background: #fff;
  border: 1px solid var(--tk-border-soft, #EFEAE0);
  border-radius: 8px;
  transition: border-color 140ms ease, box-shadow 140ms ease, transform 140ms ease;
}
.ts-att-item:hover {
  border-color: var(--tk-border, #E6E1D7);
  box-shadow: 0 1px 3px rgba(15,23,42,0.06);
  transform: translateY(-1px);
}
.ts-att-thumb {
  width: 34px; height: 34px;
  border-radius: 7px;
  flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 10.5px; font-weight: 700;
  color: #fff;
  overflow: hidden;
  letter-spacing: 0.3px;
  text-transform: uppercase;
}
.ts-att-thumb img { width: 100%; height: 100%; object-fit: cover; }
.ts-att-thumb-img { background: linear-gradient(135deg, #94A3B8, #64748B); }
.ts-att-thumb-pdf { background: linear-gradient(135deg, #EF4444, #B91C1C); }
.ts-att-thumb-xls { background: linear-gradient(135deg, #22C55E, #166534); }
.ts-att-thumb-doc { background: linear-gradient(135deg, #3B82F6, #1E40AF); }
.ts-att-thumb-zip { background: linear-gradient(135deg, #A16207, #713F12); }
.ts-att-thumb-txt { background: linear-gradient(135deg, #94A3B8, #475569); }
.ts-att-thumb-other { background: linear-gradient(135deg, #9CA3AF, #4B5563); }
.ts-att-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.ts-att-name {
  display: block;
  color: var(--tk-text);
  text-decoration: none;
  font-weight: 600;
  font-size: 12.5px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  line-height: 1.3;
}
.ts-att-name:hover { color: var(--tk-accent-text, #B85A0E); text-decoration: underline; }
.ts-att-meta {
  font-size: 10.5px; color: var(--tk-text-muted, #9C9384);
  line-height: 1.2;
}
.ts-att-actions { display: flex; gap: 2px; flex-shrink: 0; opacity: 0; transition: opacity 140ms ease; }
.ts-att-item:hover .ts-att-actions { opacity: 1; }
.ts-att-actions .ts-icon-btn { color: var(--tk-text-muted, #9C9384); }
.ts-att-actions .ts-icon-btn:hover { color: var(--tk-text); background: var(--tk-n-100, #F3F0E8); }

/* ═══ Метки ═══ */
.ts-labels-row {
  display: flex; flex-wrap: wrap; gap: 6px;
  align-items: center;
}
.ts-label-pill {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 4px 6px 4px 8px;
  border: 1px solid;
  border-radius: 999px;
  font-size: 11.5px; font-weight: 600; line-height: 1;
  font-family: inherit;
}
.ts-label-pill-dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}
.ts-label-pill-text {
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  max-width: 180px;
}
.ts-label-pill-del {
  display: inline-flex; align-items: center; justify-content: center;
  width: 16px; height: 16px; padding: 0;
  border: none; background: transparent;
  color: inherit;
  border-radius: 50%;
  cursor: pointer;
  opacity: 0.65;
  transition: opacity 140ms ease, background 140ms ease;
}
.ts-label-pill-del:hover {
  opacity: 1;
  background: rgba(0,0,0,0.08);
}

/* Кнопка «+ Добавить метку» */
.ts-label-add-wrap { position: relative; display: inline-block; }
.ts-label-add-btn {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 5px 10px 5px 8px;
  background: transparent;
  border: 1px dashed var(--tk-border, #E6E1D7);
  border-radius: 999px;
  font-family: inherit; font-size: 11.5px; font-weight: 600;
  color: var(--tk-text-muted, #9C9384);
  cursor: pointer;
  transition: background 140ms ease, border-color 140ms ease, color 140ms ease;
}
.ts-label-add-btn:hover,
.ts-label-add-btn.is-open {
  background: var(--tk-accent-soft, rgba(232,122,30,0.10));
  border-color: var(--tk-accent, #E87A1E);
  border-style: solid;
  color: var(--tk-accent-text, #B85A0E);
}

/* Поповер пикера меток */
.ts-pop-label {
  min-width: 260px; max-width: 320px;
  padding: 8px;
  display: flex; flex-direction: column; gap: 6px;
}
.ts-pop-label-list {
  display: flex; flex-direction: column; gap: 1px;
  max-height: 260px;
  overflow-y: auto;
}
.ts-pop-label-item {
  display: flex; align-items: center; gap: 8px;
  padding: 6px 8px;
  background: transparent;
  border: none;
  border-radius: 6px;
  font-family: inherit; font-size: 12px; font-weight: 500;
  color: var(--tk-text);
  text-align: left;
  cursor: pointer;
  transition: background 140ms ease;
}
.ts-pop-label-item:hover { background: var(--tk-n-100, #F3F0E8); }
.ts-pop-label-item.is-active { background: var(--tk-accent-soft, rgba(232,122,30,0.10)); }

.ts-pop-label-check {
  flex-shrink: 0;
  width: 16px; height: 16px;
  border: 1.5px solid var(--tk-border, #E6E1D7);
  border-radius: 4px;
  background: #fff;
  display: inline-flex; align-items: center; justify-content: center;
  color: #fff;
  transition: background 140ms ease, border-color 140ms ease;
}
.ts-pop-label-check.is-checked {
  background: var(--tk-accent, #E87A1E);
  border-color: var(--tk-accent, #E87A1E);
}
.ts-pop-label-stripe {
  flex-shrink: 0;
  width: 4px;
  align-self: stretch;
  min-height: 14px;
  border-radius: 2px;
}
.ts-pop-label-text {
  flex: 1;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

.ts-pop-label-foot {
  padding-top: 6px;
  border-top: 1px solid var(--tk-border-soft, #EFEAE0);
}
.ts-pop-label-create {
  display: flex; align-items: center; gap: 6px;
  width: 100%;
  padding: 7px 10px;
  background: transparent;
  border: none;
  border-radius: 6px;
  font-family: inherit; font-size: 12px; font-weight: 600;
  color: var(--tk-accent-text, #B85A0E);
  text-align: left;
  cursor: pointer;
  transition: background 140ms ease;
}
.ts-pop-label-create:hover {
  background: var(--tk-accent-soft, rgba(232,122,30,0.10));
}

/* ═══ Соисполнители ═══ */
.ts-assignees { display: flex; flex-wrap: wrap; gap: var(--tk-s-1); margin-bottom: 6px; }
.ts-chip {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 3px var(--tk-s-2) 3px 3px;
  background: var(--tk-prio-medium-bg);
  color: var(--tk-prio-medium-fg);
  border-radius: 14px;
  font-size: var(--tk-fz-sm);
  font-weight: var(--tk-fw-semibold);
}
.ts-chip-bubble {
  display: inline-flex; align-items: center; justify-content: center;
  width: 22px; height: 22px; border-radius: 50%;
  background: linear-gradient(135deg, var(--tk-accent), #F4A261);
  color: #fff;
  font-size: 9px;
  font-weight: var(--tk-fw-bold);
}
.ts-chip-name { line-height: 1; }
.ts-chip .ts-icon-btn {
  width: 18px; height: 18px;
  margin-left: 2px;
}
.ts-chip .ts-icon-btn:hover { background: rgba(0,0,0,0.10); color: var(--tk-text); }
.ts-chip-done {
  background: #DDFAE9;
  border-color: #B6EAC9;
  color: #1F845A;
}
.ts-chip-done .ts-chip-bubble { background: linear-gradient(135deg, #1F845A, #4BCE97); }
.ts-chip-done-tick {
  color: #1F845A;
  font-weight: 900;
  font-size: 12px;
  margin-left: 2px;
}

/* Read-only «протокольные» соисполнители: у них своя копия задачи на своей доске */
.ts-chip-ghost {
  background: transparent;
  border: 1px dashed var(--tk-border);
  color: var(--tk-text-muted);
}
.ts-chip-ghost .ts-chip-bubble {
  background: var(--tk-n-200);
  color: var(--tk-text-secondary);
}
.ts-protocol-coass {
  margin-top: var(--tk-s-3);
  padding-top: var(--tk-s-2);
  border-top: 1px dashed var(--tk-border-soft);
}
.ts-protocol-coass-title {
  font-size: 11px; font-weight: var(--tk-fw-semibold);
  color: var(--tk-text-muted);
  margin-bottom: 6px;
  text-transform: none;
}

.ts-assignee-add {
  width: 100%;
  padding: 6px var(--tk-s-2);
  font-size: var(--tk-fz-md);
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-sm);
  background: var(--tk-n-0);
  color: var(--tk-text);
  font-family: inherit;
  margin-top: var(--tk-s-1);
  transition: border-color var(--tk-transition), box-shadow var(--tk-transition);
}
.ts-assignee-add:hover { border-color: var(--tk-n-300); }
.ts-assignee-add:focus { outline: none; border-color: var(--tk-accent); box-shadow: var(--tk-focus-ring); }

/* ═══ Связи ═══ */
.ts-relations { display: flex; flex-direction: column; gap: var(--tk-s-1); }
.ts-relation {
  display: flex; align-items: center; gap: var(--tk-s-2);
  padding: 6px var(--tk-s-3);
  border-radius: var(--tk-r-sm);
  background: var(--tk-n-50);
  border: 1px solid var(--tk-border-soft);
  font-size: var(--tk-fz-sm);
}
.ts-relation-type {
  font-weight: var(--tk-fw-bold);
  color: var(--tk-accent-text);
  min-width: 90px;
}
.ts-relation-label {
  flex: 1;
  color: var(--tk-text);
  word-break: break-word;
}
.ts-relation-picker {
  display: grid; grid-template-columns: 1fr 1fr auto; gap: 6px;
  margin-top: var(--tk-s-2);
  padding: var(--tk-s-2);
  background: var(--tk-n-0);
  border: 1px dashed var(--tk-border);
  border-radius: var(--tk-r-sm);
}
.ts-relation-picker > select { grid-column: 1 / -1; }
.ts-relation-picker input, .ts-relation-picker select {
  padding: 6px var(--tk-s-2);
  font-size: var(--tk-fz-sm);
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-sm);
  background: var(--tk-n-0);
  color: var(--tk-text);
  font-family: inherit;
}
.ts-relation-picker input:focus, .ts-relation-picker select:focus { outline: none; border-color: var(--tk-accent); box-shadow: var(--tk-focus-ring); }

/* ═══ ЧАТ ═══ */
.ts-chat-pane { display: flex; flex-direction: column; padding: 0; }
.ts-chat-list {
  flex: 1; overflow-y: auto;
  padding: 16px 14px;
  display: flex; flex-direction: column; gap: 10px;
  background: var(--tk-n-50, #FAF9F5);
}

/* Пустое состояние */
.ts-chat-empty {
  text-align: center;
  padding: 36px 20px;
  color: var(--tk-text-muted, #9C9384);
  display: flex; flex-direction: column; align-items: center; gap: 8px;
}
.ts-chat-empty-icon {
  width: 48px; height: 48px;
  border-radius: 50%;
  background: var(--tk-n-100, #F3F0E8);
  display: inline-flex; align-items: center; justify-content: center;
  color: var(--tk-text-muted, #9C9384);
  margin-bottom: 4px;
}
.ts-chat-empty-text {
  font-size: 13px; font-weight: 600;
  color: var(--tk-text-secondary, #534D40);
}
.ts-chat-empty-hint {
  font-size: 11.5px; color: var(--tk-text-muted, #9C9384);
}

/* Строка сообщения: аватар + пузырь */
.ts-chat-row {
  display: flex; align-items: flex-end; gap: 8px;
  max-width: 88%;
}
.ts-chat-row.own {
  align-self: flex-end;
  flex-direction: row-reverse;
}

/* Аватар-кружок */
.ts-chat-avatar {
  flex-shrink: 0;
  width: 28px; height: 28px;
  border-radius: 50%;
  background: linear-gradient(135deg, #B0AAA0, #6E6657);
  color: #fff;
  font-size: 10.5px; font-weight: 700;
  display: inline-flex; align-items: center; justify-content: center;
  letter-spacing: 0.2px;
  border: 1.5px solid var(--tk-n-50, #FAF9F5);
}
.ts-chat-row.own .ts-chat-avatar {
  background: linear-gradient(135deg, var(--tk-accent, #E87A1E), #F4A261);
}

/* Пузырь сообщения */
.ts-chat-bubble {
  background: #fff;
  border: 1px solid var(--tk-border-soft, #EFEAE0);
  border-radius: 12px;
  border-bottom-left-radius: 4px;
  padding: 7px 11px;
  position: relative;
  box-shadow: 0 1px 2px rgba(15,23,42,0.04);
  min-width: 0;
}
.ts-chat-row.own .ts-chat-bubble {
  background: var(--tk-accent-soft, rgba(232,122,30,0.10));
  border-color: color-mix(in srgb, var(--tk-accent, #E87A1E) 25%, transparent);
  border-bottom-left-radius: 12px;
  border-bottom-right-radius: 4px;
}

/* Шапка пузыря: имя · время */
.ts-chat-meta {
  display: flex; gap: 6px; align-items: baseline;
  font-size: 10.5px;
  margin-bottom: 3px;
  line-height: 1;
}
.ts-chat-author {
  font-weight: 700;
  color: var(--tk-text, #1A1814);
  font-size: 11.5px;
}
.ts-chat-row.own .ts-chat-author {
  color: var(--tk-accent-text, #B85A0E);
}
.ts-chat-date {
  color: var(--tk-text-muted, #9C9384);
  font-size: 10.5px;
}

/* Тело сообщения */
.ts-chat-body {
  font-size: 12.5px;
  color: var(--tk-text, #1A1814);
  line-height: 1.45;
  word-break: break-word;
}
.ts-chat-body.ts-md-view a { color: var(--tk-accent-text, #B85A0E); }
.ts-chat-body.ts-md-view code {
  background: rgba(0,0,0,0.06);
  padding: 1px 4px;
  border-radius: 4px;
  font-size: 11.5px;
}
.ts-chat-row.own .ts-chat-body.ts-md-view code { background: rgba(232,122,30,0.14); }

/* Действия редактирования */
.ts-chat-actions {
  display: flex; gap: 2px; margin-top: 4px;
  opacity: 0;
  transition: opacity 140ms ease;
  justify-content: flex-end;
}
.ts-chat-bubble:hover .ts-chat-actions { opacity: 1; }
.ts-chat-action {
  background: none; border: none; cursor: pointer;
  color: var(--tk-text-muted, #9C9384);
  width: 22px; height: 22px;
  display: inline-flex; align-items: center; justify-content: center;
  border-radius: 6px;
  transition: background 140ms ease, color 140ms ease;
}
.ts-chat-action:hover {
  background: rgba(0,0,0,0.06);
  color: var(--tk-text, #1A1814);
}
.ts-chat-row.own .ts-chat-action:hover {
  background: rgba(232,122,30,0.15);
  color: var(--tk-accent-text, #B85A0E);
}

/* Поле ввода */
.ts-chat-input {
  display: flex; gap: 8px; align-items: flex-end;
  padding: 10px 14px 12px;
  border-top: 1px solid var(--tk-border-soft, #EFEAE0);
  background: #fff;
  flex-shrink: 0;
}
.ts-chat-input > :deep(.me) {
  flex: 1;
  max-height: 180px;
  overflow: hidden;
  background: var(--tk-n-50, #FAF9F5);
  border: 1px solid var(--tk-border, #E6E1D7);
  border-radius: 18px;
}
.ts-chat-input > :deep(.me:focus-within) {
  border-color: var(--tk-accent, #E87A1E);
  background: #fff;
  box-shadow: var(--tk-focus-ring, 0 0 0 3px rgba(232,122,30,0.18));
}
.ts-chat-input > :deep(.me .me-content) {
  max-height: 120px;
  overflow-y: auto;
  padding: 8px 12px;
}

/* Круглая кнопка отправки */
.ts-chat-send {
  flex-shrink: 0;
  width: 36px; height: 36px;
  border-radius: 50%;
  border: none;
  background: var(--tk-accent, #E87A1E);
  color: #fff;
  cursor: pointer;
  display: inline-flex; align-items: center; justify-content: center;
  box-shadow: 0 2px 6px rgba(232,122,30,0.30);
  transition: background 140ms ease, transform 140ms ease, box-shadow 140ms ease;
}
.ts-chat-send:hover:not(:disabled) {
  background: var(--tk-accent-hover, #D26B12);
  transform: translateY(-1px);
  box-shadow: 0 4px 10px rgba(232,122,30,0.35);
}
.ts-chat-send:disabled {
  opacity: 0.4;
  cursor: default;
  box-shadow: none;
}
.ts-chat-send-icon {
  margin-left: 1px;
}

/* ═══ История (timeline) ═══ */
.ts-hist {
  list-style: none; margin: 0;
  padding: 4px 0 4px 8px;
  position: relative;
}
.ts-hist::before {
  content: '';
  position: absolute;
  top: 14px; bottom: 14px;
  left: 19px;
  width: 2px;
  background: linear-gradient(180deg,
    var(--tk-border-soft, #EFEAE0) 0%,
    var(--tk-border, #E6E1D7) 50%,
    var(--tk-border-soft, #EFEAE0) 100%);
  border-radius: 2px;
}
.ts-hist-item {
  display: flex; align-items: flex-start; gap: 12px;
  padding: 6px 4px 6px 0;
  position: relative;
}
.ts-hist-marker {
  flex-shrink: 0;
  width: 24px; height: 24px;
  border-radius: 50%;
  display: inline-flex; align-items: center; justify-content: center;
  color: #fff;
  background: var(--tk-text-muted, #9C9384);
  border: 2px solid #fff;
  box-shadow: 0 0 0 1px var(--tk-border-soft, #EFEAE0);
  position: relative;
  z-index: 1;
}
.ts-hist-marker-created   { background: var(--tk-accent, #E87A1E); }
.ts-hist-marker-moved     { background: #3B82F6; }
.ts-hist-marker-updated   { background: #6E6657; }
.ts-hist-marker-comment   { background: #10B981; }
.ts-hist-marker-labels    { background: #8B5CF6; }
.ts-hist-marker-people    { background: #0EA5E9; }
.ts-hist-marker-relations { background: #F59E0B; }
.ts-hist-marker-closed    { background: #16A34A; }
.ts-hist-marker-reopened  { background: #E87A1E; }
.ts-hist-marker-other     { background: #9C9384; }

.ts-hist-content {
  flex: 1; min-width: 0;
  padding-top: 3px;
  padding-bottom: 4px;
}
.ts-hist-row {
  display: flex; flex-wrap: wrap; gap: 5px;
  font-size: 12.5px; line-height: 1.4;
}
.ts-hist-author {
  font-weight: 700;
  color: var(--tk-text, #1A1814);
}
.ts-hist-action {
  color: var(--tk-text-secondary, #534D40);
}
.ts-hist-date {
  font-size: 10.5px;
  color: var(--tk-text-muted, #9C9384);
  margin-top: 2px;
  cursor: default;
}

/* Пустое состояние истории */
.ts-hist-empty {
  display: flex; flex-direction: column; align-items: center;
  gap: 8px;
  padding: 40px 20px;
  text-align: center;
  color: var(--tk-text-muted, #9C9384);
}
.ts-hist-empty-icon {
  width: 48px; height: 48px;
  border-radius: 50%;
  background: var(--tk-n-100, #F3F0E8);
  display: inline-flex; align-items: center; justify-content: center;
  color: var(--tk-text-muted, #9C9384);
  margin-bottom: 4px;
}
.ts-hist-empty-text {
  font-size: 13px; font-weight: 600;
  color: var(--tk-text-secondary, #534D40);
}
.ts-hist-empty-hint {
  font-size: 11.5px; color: var(--tk-text-muted, #9C9384);
}

/* ═══ Адаптив ═══
   На мобильных и планшетах модалка раскрывается на весь экран
   (full-screen). На десктопе остаётся правым сайдбаром 600px из этапа 4.
   Breakpoint поднят с 540 → 720, чтобы покрыть iPhone Plus / iPad mini. */
@media (max-width: 720px) {
  .task-sidebar {
    max-width: 100%;
    width: 100%;
  }
  /* Анимация выезда справа на мобильном смотрится странно — пусть просто появляется. */
  .task-sidebar { animation: fadeIn .18s; }
  .ts-props { grid-template-columns: 1fr; }
  /* Шапка чуть тоньше — на мобильном место дорого. */
  .ts-header { padding: var(--tk-s-2) var(--tk-s-3); }
  /* Содержимое — отступы поменьше. */
  .ts-pane { padding: var(--tk-s-3) var(--tk-s-3) var(--tk-s-4); }
}

.ts-relation-link { color: var(--accent, #F4A261); text-decoration: none; }
.ts-relation-link:hover { text-decoration: underline; }
</style>
