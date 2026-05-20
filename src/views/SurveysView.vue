<template>
  <div class="sv-view">
    <!-- ═══ Заголовок страницы ═══ -->
    <div class="sv-page-head">
      <div>
        <h1 class="sv-title">Опросы</h1>
        <p class="sv-subtitle">Отправляй опросы в бот и в личный кабинет. Здесь же — результаты и аналитика.</p>
      </div>
      <div class="sv-page-head-actions">
        <button class="sv-btn ghost" @click="loadSurveys" :disabled="listLoading || detailLoading" title="Обновить">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10"/><path d="M20.49 15A9 9 0 015.64 18.36L1 14"/></svg>
          <span>Обновить</span>
        </button>
        <button class="sv-btn primary" @click="startNewSurvey">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          <span>Новый опрос</span>
        </button>
      </div>
    </div>

    <div class="sv-layout">
      <!-- ═══ Сайдбар со списком ═══ -->
      <aside class="sv-sidebar">
        <div class="sv-search">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
          <input v-model="searchText" placeholder="Поиск по названию..." />
        </div>

        <div class="sv-filter-tabs">
          <button
            v-for="tab in filterTabs"
            :key="tab.key"
            class="sv-filter-tab"
            :class="{ active: statusFilter === tab.key }"
            @click="statusFilter = tab.key"
          >
            {{ tab.label }}
            <span class="sv-filter-count">{{ counts[tab.key] }}</span>
          </button>
        </div>

        <div class="sv-list-scroll">
          <div v-if="listLoading" class="sv-empty tight"><BurgerSpinner text="Загрузка..." /></div>
          <div v-else-if="!filteredSurveys.length" class="sv-empty tight">
            {{ surveys.length ? 'Ничего не найдено' : 'Опросов пока нет' }}
          </div>
          <button
            v-for="survey in filteredSurveys"
            :key="survey.id"
            class="sv-list-item"
            :class="{ active: selectedId === Number(survey.id) && !isCreating }"
            @click="openSurvey(survey.id)"
          >
            <div class="sv-list-item-head">
              <span class="sv-badge" :class="'s-' + survey.status">{{ surveyStatusLabel(survey.status) }}</span>
              <span class="sv-list-item-group">{{ survey.legal_entity_group === 'PS' ? 'ПС' : 'БК/ВМ' }}</span>
            </div>
            <div class="sv-list-item-title">{{ survey.title }}</div>
            <div v-if="survey.status !== 'draft' && survey.target_restaurants_count > 0" class="sv-list-item-bar">
              <div class="sv-mini-bar">
                <div class="sv-mini-bar-fill" :style="{ width: responsePct(survey) + '%' }"></div>
              </div>
              <span class="sv-list-item-stat">{{ survey.responses_count }}/{{ survey.target_restaurants_count }}</span>
            </div>
            <div class="sv-list-item-meta">
              <span>{{ survey.questions_count }} вопр.</span>
              <span>·</span>
              <span>{{ formatDate(survey.created_at) }}</span>
            </div>
          </button>
        </div>
      </aside>

      <!-- ═══ Основная область ═══ -->
      <section class="sv-main">
        <div v-if="detailLoading" class="sv-card sv-empty"><BurgerSpinner text="Загрузка опроса..." /></div>

        <div v-else-if="!isCreating && !form.id" class="sv-card sv-hero-empty">
          <svg width="72" height="72" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="4" width="18" height="17" rx="2"/>
            <path d="M8 2v4M16 2v4M3 10h18"/>
            <path d="M9 15l2 2 4-4"/>
          </svg>
          <h3>Выберите опрос слева</h3>
          <p>Или создайте новый — он появится в списке как черновик, а после рассылки перейдёт в статус «Активен».</p>
          <button class="sv-btn primary" @click="startNewSurvey">Новый опрос</button>
        </div>

        <div v-else class="sv-card sv-detail">
          <!-- ═══ Шапка карточки ═══ -->
          <div class="sv-detail-head">
            <div class="sv-detail-title-wrap">
              <h2 class="sv-detail-title">{{ isCreating ? 'Новый опрос' : form.title || 'Опрос без названия' }}</h2>
              <div class="sv-detail-meta" v-if="!isCreating">
                <span class="sv-badge" :class="'s-' + form.status">{{ surveyStatusLabel(form.status) }}</span>
                <span class="sv-meta-item">{{ form.legal_entity_group === 'PS' ? 'Пицца Стар' : 'Бургер БК / Воглия Матта' }}</span>
                <span v-if="detail?.created_by" class="sv-meta-item">{{ detail.created_by }}</span>
                <span class="sv-meta-item">{{ formatDate(detail?.created_at) }}</span>
              </div>
            </div>

            <div class="sv-detail-actions">
              <template v-if="canEditDraft">
                <button class="sv-btn ghost" @click="resetFormFromDetail" v-if="!isCreating && hasUnsavedChanges">Сбросить</button>
                <button class="sv-btn primary" @click="saveSurvey" :disabled="saving">
                  <BurgerSpinner v-if="saving" size="xs" />
                  <span>{{ saving ? 'Сохранение...' : (isCreating ? 'Создать черновик' : 'Сохранить') }}</span>
                </button>
              </template>
              <button v-if="canSendSurvey" class="sv-btn accent" @click="sendSurvey" :disabled="sending">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
                <span>{{ sending ? 'Рассылка...' : 'Разослать' }}</span>
              </button>
              <div v-if="!isCreating" class="sv-menu-wrap" @click.stop>
                <button class="sv-btn ghost icon" @click="menuOpen = !menuOpen" title="Ещё">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/></svg>
                </button>
                <div v-if="menuOpen" class="sv-menu">
                  <button v-if="canCloseSurvey" @click="closeSurvey" :disabled="closing">Закрыть опрос</button>
                  <button v-if="canDeleteSurvey" class="danger" @click="deleteSurvey" :disabled="deleting">Удалить опрос</button>
                </div>
              </div>
            </div>
          </div>

          <div v-if="message.text" class="sv-alert" :class="message.ok ? 'ok' : 'err'">{{ message.text }}</div>

          <!-- ═══ Прогресс ответов ═══ -->
          <div v-if="!isCreating && form.status !== 'draft' && (detail?.target_restaurants_count || 0) > 0" class="sv-progress-card">
            <div class="sv-progress-head">
              <div>
                <div class="sv-progress-title">Ответили {{ detail.responses.length }} из {{ detail.target_restaurants_count }}</div>
                <div class="sv-progress-sub">{{ responsePct(detail) }}% ресторанов</div>
              </div>
              <div class="sv-progress-pct">{{ responsePct(detail) }}%</div>
            </div>
            <div class="sv-progress-bar">
              <div class="sv-progress-fill" :style="{ width: responsePct(detail) + '%' }"></div>
            </div>
          </div>

          <!-- ═══ Вкладки ═══ -->
          <div v-if="!isCreating" class="sv-tabs">
            <button class="sv-tab" :class="{ active: activeTab === 'settings' }" @click="activeTab = 'settings'">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 11-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 110-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 114 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 110 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
              <span>Настройки</span>
            </button>
            <button class="sv-tab" :class="{ active: activeTab === 'results' }" @click="activeTab = 'results'">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="4" width="3" height="14"/></svg>
              <span>Результаты</span>
              <span v-if="detail?.responses?.length" class="sv-tab-count">{{ detail.responses.length }}</span>
            </button>
            <button class="sv-tab" :class="{ active: activeTab === 'table' }" @click="activeTab = 'table'">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
              <span>Таблица</span>
              <span v-if="detail?.responses?.length" class="sv-tab-count">{{ detail.responses.length }}</span>
            </button>
          </div>

          <!-- ═══ ВКЛАДКА: Настройки ═══ -->
          <div v-if="isCreating || activeTab === 'settings'" class="sv-tab-body">
            <div class="sv-grid">
              <label class="sv-field">
                <span class="sv-label">Название опроса</span>
                <input v-model="form.title" class="sv-input" :disabled="!canEditDraft" placeholder="Например: Сдали ли пивное оборудование?" />
              </label>

              <label class="sv-field">
                <span class="sv-label">Группа юрлиц</span>
                <select v-model="form.legal_entity_group" class="sv-input" :disabled="!canEditDraft">
                  <option value="BK_VM">Бургер БК / Воглия Матта</option>
                  <option value="PS">Пицца Стар</option>
                </select>
              </label>

              <label class="sv-field full">
                <span class="sv-label">Описание для ресторана</span>
                <textarea
                  v-model="form.description"
                  class="sv-input sv-textarea"
                  :disabled="!canEditDraft"
                  rows="3"
                  placeholder="Короткий текст, который увидит ресторан в боте и в кабинете"
                />
              </label>

              <label class="sv-field">
                <span class="sv-label">Напоминать через, часов</span>
                <input v-model.number="form.remind_after_hours" class="sv-input" type="number" min="1" :disabled="!canEditDraft" />
              </label>

              <div class="sv-field">
                <span class="sv-label">Комментарий ресторана</span>
                <label class="sv-switch">
                  <input v-model="form.allow_comment" type="checkbox" :disabled="!canEditDraft" />
                  <span class="sv-switch-track"><span class="sv-switch-thumb"></span></span>
                  <span class="sv-switch-text">Разрешить</span>
                </label>
              </div>
            </div>

            <!-- Вопросы -->
            <div class="sv-questions">
              <div class="sv-questions-head">
                <div>
                  <h3>Вопросы</h3>
                  <p class="sv-questions-hint">Перетаскивайте вопросы и варианты для изменения порядка</p>
                </div>
                <button class="sv-btn ghost" @click="addQuestion" :disabled="!canEditDraft">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  <span>Добавить вопрос</span>
                </button>
              </div>

              <div v-if="!form.questions.length" class="sv-empty tight">Добавьте хотя бы один вопрос</div>

              <div
                v-for="(question, qIndex) in form.questions"
                :key="qIndex"
                class="sv-question"
                :draggable="canEditDraft"
                @dragstart="onQDragStart($event, qIndex)"
                @dragover.prevent
                @drop="onQDrop($event, qIndex)"
              >
                <div class="sv-question-head">
                  <span class="sv-drag-handle" v-if="canEditDraft" title="Перетащить">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="5" r="1.5"/><circle cx="15" cy="5" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="19" r="1.5"/><circle cx="15" cy="19" r="1.5"/></svg>
                  </span>
                  <span class="sv-question-num">Вопрос {{ qIndex + 1 }}</span>
                  <button class="sv-q-remove" @click="removeQuestion(qIndex)" :disabled="!canEditDraft || form.questions.length === 1" title="Удалить вопрос">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 01-2 2H9a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                  </button>
                </div>

                <input
                  v-model="question.text"
                  class="sv-input sv-question-input"
                  :disabled="!canEditDraft"
                  placeholder="Текст вопроса"
                />

                <div class="sv-question-type">
                  <button
                    v-for="type in questionTypes"
                    :key="type.key"
                    class="sv-type-btn"
                    :class="{ active: question.type === type.key }"
                    :disabled="!canEditDraft"
                    @click="setQuestionType(qIndex, type.key)"
                  >
                    {{ type.label }}
                  </button>
                </div>

                <div v-if="question.type === 'scale'" class="sv-type-note">
                  Ресторан выберет оценку от 1 до 10.
                </div>
                <div v-else-if="question.type === 'text'" class="sv-type-note">
                  Ресторан напишет ответ текстом.
                </div>
                <div v-else-if="question.type === 'files'" class="sv-type-note">
                  Ресторан приложит до 20 файлов (картинки, PDF, документы Word/Excel), до 25 МБ каждый.
                  <label class="sv-files-req">
                    <input type="checkbox" :checked="question.files_required !== false" :disabled="!canEditDraft"
                      @change="setQuestionFilesRequired(qIndex, $event.target.checked)" />
                    Обязательно приложить хотя бы один файл
                  </label>
                </div>

                <div v-if="question.type === 'choice'" class="sv-options">
                  <div
                    v-for="(option, oIndex) in question.options"
                    :key="oIndex"
                    class="sv-option-row"
                    :draggable="canEditDraft"
                    @dragstart.stop="onODragStart($event, qIndex, oIndex)"
                    @dragover.prevent
                    @drop.stop="onODrop($event, qIndex, oIndex)"
                  >
                    <span class="sv-option-dot"></span>
                    <input
                      v-model="option.text"
                      class="sv-input"
                      :disabled="!canEditDraft"
                      :placeholder="`Вариант ${oIndex + 1}`"
                    />
                    <button class="sv-o-remove" @click="removeOption(qIndex, oIndex)" :disabled="!canEditDraft || question.options.length <= 2" title="Удалить вариант">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                  </div>
                </div>

                <button v-if="question.type === 'choice'" class="sv-add-option" @click="addOption(qIndex)" :disabled="!canEditDraft">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  <span>Добавить вариант</span>
                </button>
              </div>
            </div>
          </div>

          <!-- ═══ ВКЛАДКА: Результаты ═══ -->
          <div v-if="!isCreating && activeTab === 'results'" class="sv-tab-body">
            <!-- Аналитика по вопросам -->
            <div v-if="!detail?.responses?.length" class="sv-empty">Пока никто не ответил.</div>
            <div v-else class="sv-analytics">
              <div v-for="(q, qi) in detail.questions" :key="q.id" class="sv-analytics-q">
                <div class="sv-analytics-q-head">
                  <span class="sv-analytics-q-num">{{ qi + 1 }}</span>
                  <div class="sv-analytics-q-text">{{ q.text }}</div>
                  <span class="sv-analytics-q-total">{{ q.responses_total }} отв.</span>
                </div>
                <div v-if="q.type === 'text'" class="sv-analytics-text-note">
                  Текстовые ответы смотрите в блоке «Ответы по ресторанам».
                </div>
                <div v-else class="sv-analytics-options">
                  <div v-if="q.type === 'scale'" class="sv-scale-summary">
                    Средняя оценка: <b>{{ q.average_score || '—' }}</b>
                  </div>
                  <div v-for="opt in q.options" :key="opt.id || opt.text" class="sv-analytics-option">
                    <div class="sv-analytics-option-head">
                      <span class="sv-analytics-option-text">{{ opt.text }}</span>
                      <span class="sv-analytics-option-count">{{ opt.responses_count }} · {{ opt.responses_percent }}%</span>
                    </div>
                    <div class="sv-analytics-bar">
                      <div class="sv-analytics-bar-fill" :style="{ width: opt.responses_percent + '%' }"></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Комментарии -->
            <div v-if="commentsList.length" class="sv-comments">
              <h3 class="sv-block-title">Комментарии <span class="sv-muted">{{ commentsList.length }}</span></h3>
              <div class="sv-comment" v-for="c in commentsList" :key="c.id">
                <div class="sv-comment-head">
                  <span class="sv-comment-rest">{{ formatRestaurantNumber(c.restaurant_number, c.legal_entity_group) }}</span>
                  <span class="sv-muted small">{{ formatDate(c.submitted_at) }}</span>
                </div>
                <div class="sv-comment-text">{{ c.comment }}</div>
              </div>
            </div>

            <!-- Подробные ответы -->
            <div v-if="detail?.responses?.length" class="sv-responses">
              <div class="sv-responses-head">
                <h3 class="sv-block-title">Ответы по ресторанам <span class="sv-muted">{{ detail.responses.length }}</span></h3>
                <button class="sv-btn ghost small" @click="responsesExpanded = !responsesExpanded">
                  {{ responsesExpanded ? 'Свернуть' : 'Развернуть все' }}
                </button>
              </div>
              <div class="sv-response-list">
                <div v-for="resp in detail.responses" :key="resp.id" class="sv-response">
                  <div class="sv-response-head" @click="toggleResponse(resp.id)">
                    <div>
                      <div class="sv-response-rest">{{ formatRestaurantNumber(resp.restaurant_number, resp.legal_entity_group) }}</div>
                      <div class="sv-response-addr">{{ [resp.city, resp.address].filter(Boolean).join(', ') || '—' }}</div>
                    </div>
                    <div class="sv-response-right">
                      <span class="sv-muted small">{{ formatDate(resp.submitted_at) }}</span>
                      <button
                        v-if="canManageResponses"
                        class="sv-btn ghost small danger"
                        :disabled="deletingResponseId === Number(resp.id)"
                        @click.stop="deleteResponse(resp)"
                        title="Удалить ответ"
                      >
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 01-2 2H9a2 2 0 01-2-2L5 6"/></svg>
                      </button>
                      <svg class="sv-response-chev" :class="{ open: isResponseOpen(resp.id) }" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                    </div>
                  </div>
                  <div v-if="isResponseOpen(resp.id)" class="sv-response-body">
                    <div v-for="a in resp.answers" :key="a.question_id" class="sv-response-answer">
                      <span class="sv-response-q">{{ a.question_text }}</span>
                      <span class="sv-response-a">{{ surveyAnswerText(a) }}</span>
                    </div>
                    <div v-for="(group, qid) in responseFilesByQuestion(resp)" :key="'f' + qid" class="sv-response-answer">
                      <span class="sv-response-q">{{ questionTextById(Number(qid)) }}</span>
                      <span class="sv-response-a sv-response-files">
                        <a v-for="f in group" :key="f.id" :href="f.url" target="_blank" rel="noopener" class="sv-response-file">
                          <span class="sv-response-file-thumb">
                            <img v-if="isImageMime(f.mime_type)" :src="f.url" :alt="f.file_name" loading="lazy" />
                            <svg v-else width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                          </span>
                          <span class="sv-response-file-name">{{ f.file_name }}</span>
                          <span class="sv-response-file-size">{{ humanFileSize(f.file_size) }}</span>
                        </a>
                      </span>
                    </div>
                    <div v-if="resp.comment" class="sv-response-comment">
                      <span class="sv-muted small">Комментарий:</span>
                      {{ resp.comment }}
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Не ответили -->
            <div v-if="detail?.pending_restaurants?.length" class="sv-pending">
              <h3 class="sv-block-title">Не ответили <span class="sv-muted">{{ detail.pending_restaurants.length }}</span></h3>
              <div class="sv-pending-chips">
                <span v-for="item in detail.pending_restaurants" :key="`${item.legal_entity_group}-${item.restaurant_number}`" class="sv-pending-chip">
                  {{ formatRestaurantNumber(item.restaurant_number, item.legal_entity_group) }}
                </span>
              </div>
            </div>
            <div v-else-if="detail?.responses?.length" class="sv-all-done">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
              Ответили все рестораны
            </div>
          </div>

          <!-- ═══ ВКЛАДКА: Таблица ═══ -->
          <div v-if="!isCreating && activeTab === 'table'" class="sv-tab-body">
            <div v-if="!detail?.responses?.length" class="sv-empty">Пока никто не ответил.</div>
            <template v-else>
              <div class="sv-table-toolbar">
                <div class="sv-search sv-table-search">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                  <input v-model="tableSearch" type="text" placeholder="Поиск по ресторану, городу, адресу…" />
                </div>
                <div class="sv-table-toolbar-actions">
                  <span class="sv-table-count">{{ tableRows.length }} из {{ detail.responses.length }}</span>
                  <button v-if="hasActiveTableFilters" class="sv-btn ghost small" @click="resetTableFilters">Сбросить фильтры</button>
                  <button class="sv-btn primary small" @click="downloadExcel" :disabled="exportingExcel">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    <span>{{ exportingExcel ? 'Готовлю…' : 'Скачать Excel' }}</span>
                  </button>
                </div>
              </div>

              <div class="sv-table-wrap" ref="tableWrapEl" @mousedown="onTableDragStart">
                <table class="sv-table">
                  <thead>
                    <tr>
                      <th class="sv-th sv-th-rest" @click="toggleSort('__rest')">
                        <span>№ / Город / Адрес</span>
                        <span class="sv-th-sort" :class="sortClass('__rest')"></span>
                      </th>
                      <th class="sv-th sv-th-date" @click="toggleSort('__date')">
                        <span>Дата ответа</span>
                        <span class="sv-th-sort" :class="sortClass('__date')"></span>
                      </th>
                      <th
                        v-for="q in detail.questions"
                        :key="q.id"
                        class="sv-th sv-th-q"
                        :class="{ active: hasColumnFilter(q.id) }"
                      >
                        <div class="sv-th-q-row">
                          <span class="sv-th-q-text" :title="q.text" @click="toggleSort(q.id)">{{ q.text }}</span>
                          <button
                            v-if="q.type !== 'text' || textHasValues(q.id)"
                            class="sv-th-filter-btn"
                            :class="{ active: hasColumnFilter(q.id) }"
                            @click.stop="openFilter(q.id, $event)"
                            title="Фильтр"
                          >
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                          </button>
                          <span class="sv-th-sort" @click="toggleSort(q.id)" :class="sortClass(q.id)"></span>
                        </div>
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="resp in tableRows" :key="resp.id" class="sv-tr">
                      <td class="sv-td sv-td-rest">
                        <div class="sv-td-rest-num">{{ formatRestaurantNumber(resp.restaurant_number, resp.legal_entity_group) }}</div>
                        <div v-if="resp.city || resp.address" class="sv-td-rest-addr">{{ [resp.city, resp.address].filter(Boolean).join(', ') }}</div>
                      </td>
                      <td class="sv-td sv-td-date">{{ formatDate(resp.submitted_at) }}</td>
                      <td
                        v-for="q in detail.questions"
                        :key="q.id"
                        class="sv-td sv-td-a"
                        :class="{ 'sv-td-center': q.type === 'scale' }"
                      >
                        <span :title="cellTextFor(resp, q.id)">{{ cellTextFor(resp, q.id) || '—' }}</span>
                      </td>
                    </tr>
                    <tr v-if="!tableRows.length">
                      <td :colspan="2 + detail.questions.length" class="sv-td sv-td-empty">Ничего не найдено по фильтрам</td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div v-if="commentsList.length" class="sv-comments sv-table-comments">
                <h3 class="sv-block-title">Комментарии <span class="sv-muted">{{ commentsList.length }}</span></h3>
                <div class="sv-comment" v-for="c in commentsList" :key="c.id">
                  <div class="sv-comment-head">
                    <span class="sv-comment-rest">{{ formatRestaurantNumber(c.restaurant_number, c.legal_entity_group) }}</span>
                    <span class="sv-muted small">{{ formatDate(c.submitted_at) }}</span>
                  </div>
                  <div class="sv-comment-text">{{ c.comment }}</div>
                </div>
              </div>

              <div v-if="detail?.pending_restaurants?.length" class="sv-pending sv-table-pending">
                <h3 class="sv-block-title">Не ответили <span class="sv-muted">{{ detail.pending_restaurants.length }}</span></h3>
                <div class="sv-pending-chips">
                  <span v-for="item in detail.pending_restaurants" :key="`${item.legal_entity_group}-${item.restaurant_number}`" class="sv-pending-chip">
                    {{ formatRestaurantNumber(item.restaurant_number, item.legal_entity_group) }}
                  </span>
                </div>
              </div>
            </template>
          </div>

          <!-- Поповер фильтра колонки -->
          <div
            v-if="filterPopover.questionId !== null"
            class="sv-filter-popover"
            :style="filterPopoverStyle"
            @click.stop
          >
            <div class="sv-filter-popover-head">
              <span>Фильтр</span>
              <button class="sv-filter-popover-close" @click="closeFilter" title="Закрыть">×</button>
            </div>

            <template v-if="filterPopoverQuestion?.type === 'text'">
              <input
                v-model="filterPopover.textQuery"
                type="text"
                class="sv-input"
                placeholder="Содержит…"
                @keyup.enter="applyTextFilter"
              />
              <div class="sv-filter-popover-actions">
                <button class="sv-btn ghost small" @click="clearColumnFilter(filterPopover.questionId); closeFilter()">Сбросить</button>
                <button class="sv-btn primary small" @click="applyTextFilter">Применить</button>
              </div>
            </template>

            <template v-else>
              <div class="sv-filter-popover-list">
                <label v-for="val in filterPopoverValues" :key="val.key" class="sv-filter-row">
                  <input
                    type="checkbox"
                    :checked="filterPopover.selected.has(val.key)"
                    @change="toggleFilterValue(val.key)"
                  />
                  <span class="sv-filter-row-label">{{ val.label }}</span>
                  <span class="sv-filter-row-count">{{ val.count }}</span>
                </label>
                <div v-if="!filterPopoverValues.length" class="sv-filter-empty">Нет ответов</div>
              </div>
              <div class="sv-filter-popover-actions">
                <button class="sv-btn ghost small" @click="selectAllFilterValues">Все</button>
                <button class="sv-btn ghost small" @click="clearFilterSelection">Очистить</button>
                <button class="sv-btn primary small" @click="applyChoiceFilter">Применить</button>
              </div>
            </template>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useTabRoute } from '@/composables/useTabRoute.js'
import { db } from '@/lib/apiClient.js'
import { formatRestaurantNumber } from '@/lib/legalEntities.js'
import { useUserStore } from '@/stores/userStore.js'
import { exportSurveyToExcel } from '@/lib/surveyExport.js'

const userStore = useUserStore()

function makeEmptySurvey() {
  return {
    id: null,
    title: '',
    description: '',
    legal_entity_group: 'BK_VM',
    status: 'draft',
    allow_comment: true,
    remind_after_hours: 24,
    questions: [
      { text: '', type: 'choice', options: [{ text: '' }, { text: '' }] },
    ],
  }
}

function cloneSurveyToForm(survey) {
  return {
    id: survey.id,
    title: survey.title || '',
    description: survey.description || '',
    legal_entity_group: survey.legal_entity_group || 'BK_VM',
    status: survey.status || 'draft',
    allow_comment: !!Number(survey.allow_comment),
    remind_after_hours: Number(survey.remind_after_hours || 24),
    questions: (survey.questions || []).map(q => ({
      text: q.text || '',
      type: q.type || 'choice',
      files_required: q.type === 'files' ? (q.files_required !== false) : true,
      options: (q.options || []).map(o => ({ text: o.text || '' })),
    })),
  }
}

// ══════ State ══════
const surveys = ref([])
const selectedId = ref(null)
const detail = ref(null)
const form = ref(makeEmptySurvey())
const formSnapshot = ref('')
const isCreating = ref(false)
const activeTab = useTabRoute('settings', ['settings', 'results', 'table'])
const searchText = ref('')
const statusFilter = ref('all')
const menuOpen = ref(false)
const openedResponses = reactive({})
const responsesExpanded = ref(false)

const listLoading = ref(false)
const detailLoading = ref(false)
const saving = ref(false)
const sending = ref(false)
const closing = ref(false)
const deleting = ref(false)
const deletingResponseId = ref(null)

const message = ref({ text: '', ok: true })

const filterTabs = [
  { key: 'all', label: 'Все' },
  { key: 'draft', label: 'Черновики' },
  { key: 'active', label: 'Активные' },
  { key: 'closed', label: 'Закрытые' },
]
const questionTypes = [
  { key: 'choice', label: 'Варианты' },
  { key: 'scale', label: 'Шкала 1-10' },
  { key: 'text', label: 'Текст' },
  { key: 'files', label: 'Файлы' },
]

const counts = computed(() => ({
  all: surveys.value.length,
  draft: surveys.value.filter(s => s.status === 'draft').length,
  active: surveys.value.filter(s => s.status === 'active').length,
  closed: surveys.value.filter(s => s.status === 'closed').length,
}))

const filteredSurveys = computed(() => {
  const q = searchText.value.trim().toLowerCase()
  return surveys.value.filter(s => {
    if (statusFilter.value !== 'all' && s.status !== statusFilter.value) return false
    if (q && !(s.title || '').toLowerCase().includes(q)) return false
    return true
  })
})

const canEditDraft = computed(() =>
  userStore.hasAccess('surveys', 'edit') && (isCreating.value || form.value.status === 'draft'),
)
const canSendSurvey = computed(() =>
  userStore.hasAccess('surveys', 'edit') && !isCreating.value && form.value.status === 'draft',
)
const canCloseSurvey = computed(() =>
  userStore.hasAccess('surveys', 'edit') && !isCreating.value && form.value.status === 'active',
)
const canDeleteSurvey = computed(() =>
  userStore.hasAccess('surveys', 'full') && !isCreating.value,
)
const canManageResponses = computed(() =>
  userStore.hasAccess('surveys', 'edit') && !isCreating.value,
)

const hasUnsavedChanges = computed(() => JSON.stringify(form.value) !== formSnapshot.value)

const commentsList = computed(() =>
  (detail.value?.responses || []).filter(r => (r.comment || '').trim()),
)

// ══════ Табличный вид: поиск / сортировка / фильтры ══════
const tableSearch = ref('')
const tableSort = ref({ key: '__rest', dir: 'asc' }) // dir: 'asc' | 'desc' | null
const columnFilters = reactive({}) // questionId -> { type, selected?: Set, query?: string }

const exportingExcel = ref(false)

const filterPopover = reactive({
  questionId: null,
  selected: new Set(),
  textQuery: '',
  top: 0,
  left: 0,
})
const filterPopoverStyle = computed(() => ({ top: filterPopover.top + 'px', left: filterPopover.left + 'px' }))
const filterPopoverQuestion = computed(() => {
  if (filterPopover.questionId === null) return null
  return (detail.value?.questions || []).find(q => Number(q.id) === Number(filterPopover.questionId)) || null
})

// Текст значения ответа для отображения / поиска / сортировки
function cellTextFor(resp, questionId) {
  const a = (resp.answers || []).find(x => Number(x.question_id) === Number(questionId))
  if (!a) return ''
  if (a.type === 'scale') return a.numeric_value != null ? String(a.numeric_value) : ''
  if (a.type === 'text') return a.text_value || ''
  return a.option_text || ''
}

function cellSortValue(resp, questionId, type) {
  const a = (resp.answers || []).find(x => Number(x.question_id) === Number(questionId))
  if (!a) return { empty: true, num: null, str: '' }
  if (type === 'scale') {
    const n = a.numeric_value
    return { empty: n == null, num: n != null ? Number(n) : null, str: n != null ? String(n) : '' }
  }
  const str = type === 'text' ? (a.text_value || '') : (a.option_text || '')
  return { empty: !str, num: null, str }
}

// Все встречающиеся значения в колонке (для фильтра с галочками)
function valuesInColumn(questionId) {
  const q = (detail.value?.questions || []).find(x => Number(x.id) === Number(questionId))
  if (!q) return []
  const map = new Map()
  ;(detail.value?.responses || []).forEach(resp => {
    const a = (resp.answers || []).find(x => Number(x.question_id) === Number(questionId))
    let key, label
    if (!a) { key = '__empty__'; label = '— нет ответа —' }
    else if (q.type === 'scale') {
      if (a.numeric_value == null) { key = '__empty__'; label = '— нет ответа —' }
      else { key = String(a.numeric_value); label = String(a.numeric_value) }
    } else if (q.type === 'choice') {
      if (!a.option_text) { key = '__empty__'; label = '— нет ответа —' }
      else { key = String(a.option_id ?? a.option_text); label = a.option_text }
    } else {
      // text — не сюда (фильтр текстовый), но на всякий случай
      key = a.text_value || '__empty__'
      label = a.text_value || '— пусто —'
    }
    if (!map.has(key)) map.set(key, { key, label, count: 0 })
    map.get(key).count++
  })
  const arr = Array.from(map.values())
  arr.sort((a, b) => {
    if (a.key === '__empty__') return 1
    if (b.key === '__empty__') return -1
    const an = Number(a.label), bn = Number(b.label)
    if (!Number.isNaN(an) && !Number.isNaN(bn)) return an - bn
    return String(a.label).localeCompare(String(b.label), 'ru')
  })
  return arr
}

const filterPopoverValues = computed(() => {
  if (filterPopover.questionId === null) return []
  return valuesInColumn(filterPopover.questionId)
})

function textHasValues(questionId) {
  return (detail.value?.responses || []).some(resp => {
    const a = (resp.answers || []).find(x => Number(x.question_id) === Number(questionId))
    return a && (a.text_value || '').trim() !== ''
  })
}

function hasColumnFilter(questionId) {
  const f = columnFilters[questionId]
  if (!f) return false
  if (f.type === 'text') return !!(f.query || '').trim()
  return f.selected instanceof Set && f.selected.size > 0
}
const hasActiveTableFilters = computed(() => {
  if (tableSearch.value.trim()) return true
  return Object.keys(columnFilters).some(id => hasColumnFilter(id))
})

function rowMatchesValueFilter(resp, questionId, type, selected) {
  const a = (resp.answers || []).find(x => Number(x.question_id) === Number(questionId))
  let key
  if (!a) key = '__empty__'
  else if (type === 'scale') key = a.numeric_value != null ? String(a.numeric_value) : '__empty__'
  else if (type === 'choice') key = a.option_text ? String(a.option_id ?? a.option_text) : '__empty__'
  else key = a.text_value || '__empty__'
  return selected.has(key)
}

function rowMatchesTextFilter(resp, questionId, query) {
  const a = (resp.answers || []).find(x => Number(x.question_id) === Number(questionId))
  const txt = a?.text_value || ''
  return txt.toLowerCase().includes(query.toLowerCase())
}

const tableRows = computed(() => {
  const rows = (detail.value?.responses || []).slice()
  const q = tableSearch.value.trim().toLowerCase()
  const questions = detail.value?.questions || []

  const filtered = rows.filter(resp => {
    if (q) {
      const haystack = [
        formatRestaurantNumber(resp.restaurant_number, resp.legal_entity_group),
        resp.city || '',
        resp.address || '',
      ].join(' ').toLowerCase()
      if (!haystack.includes(q)) return false
    }
    for (const [qid, f] of Object.entries(columnFilters)) {
      if (!hasColumnFilter(qid)) continue
      const qDef = questions.find(x => Number(x.id) === Number(qid))
      if (!qDef) continue
      if (f.type === 'text') {
        if (!rowMatchesTextFilter(resp, qid, f.query)) return false
      } else {
        if (!rowMatchesValueFilter(resp, qid, qDef.type, f.selected)) return false
      }
    }
    return true
  })

  const { key, dir } = tableSort.value
  if (!dir) return filtered

  const sign = dir === 'asc' ? 1 : -1
  filtered.sort((a, b) => {
    if (key === '__rest') {
      const an = Number(a.restaurant_number) || 0
      const bn = Number(b.restaurant_number) || 0
      return (an - bn) * sign
    }
    if (key === '__date') {
      const at = a.submitted_at ? new Date(a.submitted_at).getTime() : 0
      const bt = b.submitted_at ? new Date(b.submitted_at).getTime() : 0
      return (at - bt) * sign
    }
    const qDef = questions.find(x => Number(x.id) === Number(key))
    if (!qDef) return 0
    const av = cellSortValue(a, key, qDef.type)
    const bv = cellSortValue(b, key, qDef.type)
    if (av.empty && bv.empty) return 0
    if (av.empty) return 1
    if (bv.empty) return -1
    if (av.num != null && bv.num != null) return (av.num - bv.num) * sign
    return av.str.localeCompare(bv.str, 'ru') * sign
  })

  return filtered
})

function toggleSort(key) {
  const cur = tableSort.value
  if (cur.key !== key) {
    tableSort.value = { key, dir: 'asc' }
    return
  }
  if (cur.dir === 'asc') tableSort.value = { key, dir: 'desc' }
  else if (cur.dir === 'desc') tableSort.value = { key: '__rest', dir: 'asc' }
  else tableSort.value = { key, dir: 'asc' }
}
function sortClass(key) {
  if (tableSort.value.key !== key) return ''
  return tableSort.value.dir === 'asc' ? 'asc' : 'desc'
}

const POPOVER_W = 260
const POPOVER_H_EST = 320

function openFilter(questionId, ev) {
  const q = (detail.value?.questions || []).find(x => Number(x.id) === Number(questionId))
  if (!q) return
  const rect = ev.currentTarget.getBoundingClientRect()
  const vw = window.innerWidth
  const vh = window.innerHeight
  const margin = 8

  // По умолчанию открываем вправо от кнопки. Если не влезает — сдвигаем влево.
  let left = rect.left
  if (left + POPOVER_W + margin > vw) left = vw - POPOVER_W - margin
  if (left < margin) left = margin

  // По умолчанию ниже кнопки. Если не влезает — выше.
  let top = rect.bottom + 6
  if (top + POPOVER_H_EST + margin > vh) {
    const above = rect.top - POPOVER_H_EST - 6
    top = above >= margin ? above : Math.max(margin, vh - POPOVER_H_EST - margin)
  }

  filterPopover.questionId = questionId
  filterPopover.top = top
  filterPopover.left = left
  const existing = columnFilters[questionId]
  if (q.type === 'text') {
    filterPopover.textQuery = existing?.query || ''
    filterPopover.selected = new Set()
  } else {
    filterPopover.textQuery = ''
    filterPopover.selected = new Set(existing?.selected instanceof Set ? Array.from(existing.selected) : [])
  }
}
function closeFilter() { filterPopover.questionId = null }
function toggleFilterValue(key) {
  if (filterPopover.selected.has(key)) filterPopover.selected.delete(key)
  else filterPopover.selected.add(key)
  filterPopover.selected = new Set(filterPopover.selected)
}
function selectAllFilterValues() {
  filterPopover.selected = new Set(filterPopoverValues.value.map(v => v.key))
}
function clearFilterSelection() { filterPopover.selected = new Set() }
function applyChoiceFilter() {
  const qid = filterPopover.questionId
  if (qid === null) return
  if (filterPopover.selected.size === 0) delete columnFilters[qid]
  else columnFilters[qid] = { type: 'choice', selected: new Set(filterPopover.selected) }
  closeFilter()
}
function applyTextFilter() {
  const qid = filterPopover.questionId
  if (qid === null) return
  const q = (filterPopover.textQuery || '').trim()
  if (!q) delete columnFilters[qid]
  else columnFilters[qid] = { type: 'text', query: q }
  closeFilter()
}
function clearColumnFilter(qid) { delete columnFilters[qid] }
function resetTableFilters() {
  tableSearch.value = ''
  for (const k of Object.keys(columnFilters)) delete columnFilters[k]
}

// Закрываем поповер по Esc и при смене опроса/вкладки
watch([selectedId, activeTab], () => {
  closeFilter()
  resetTableFilters()
  tableSort.value = { key: '__rest', dir: 'asc' }
})

// ══════ Grab-to-pan по таблице (горизонтально) ══════
const tableWrapEl = ref(null)
const tableDrag = {
  active: false,
  startX: 0,
  startScrollLeft: 0,
  moved: false,
}
const DRAG_THRESHOLD = 5

function onTableDragStart(ev) {
  if (ev.button !== 0) return
  // Не начинаем драг на интерактивных элементах — клик по сортировке/фильтру должен работать
  const target = ev.target
  if (target.closest('button, input, select, textarea, a')) return
  const wrap = tableWrapEl.value
  if (!wrap) return
  tableDrag.active = true
  tableDrag.moved = false
  tableDrag.startX = ev.clientX
  tableDrag.startScrollLeft = wrap.scrollLeft
  document.addEventListener('mousemove', onTableDragMove)
  document.addEventListener('mouseup', onTableDragEnd)
}
function onTableDragMove(ev) {
  if (!tableDrag.active) return
  const wrap = tableWrapEl.value
  if (!wrap) return
  const dx = ev.clientX - tableDrag.startX
  if (!tableDrag.moved && Math.abs(dx) >= DRAG_THRESHOLD) {
    tableDrag.moved = true
    wrap.classList.add('dragging')
    // Чтобы тащить можно было даже за пределами таблицы
    document.body.style.userSelect = 'none'
  }
  if (tableDrag.moved) {
    wrap.scrollLeft = tableDrag.startScrollLeft - dx
    ev.preventDefault()
  }
}
function onTableDragEnd(ev) {
  if (!tableDrag.active) return
  document.removeEventListener('mousemove', onTableDragMove)
  document.removeEventListener('mouseup', onTableDragEnd)
  const wasMoved = tableDrag.moved
  tableDrag.active = false
  tableDrag.moved = false
  const wrap = tableWrapEl.value
  if (wrap) wrap.classList.remove('dragging')
  document.body.style.userSelect = ''
  if (wasMoved) {
    // Гасим ближайший click, чтобы не сработала сортировка/фильтр после драга
    const suppress = (e) => { e.stopPropagation(); e.preventDefault() }
    window.addEventListener('click', suppress, { capture: true, once: true })
    setTimeout(() => window.removeEventListener('click', suppress, { capture: true }), 100)
  }
}

async function downloadExcel() {
  if (!detail.value) return
  exportingExcel.value = true
  try {
    await exportSurveyToExcel(detail.value, tableRows.value)
  } catch (e) {
    setMessage('Не удалось скачать Excel: ' + (e.message || e), false)
  } finally {
    exportingExcel.value = false
  }
}

// ══════ API ══════
function setMessage(text, ok = true) {
  message.value = { text, ok }
  if (ok) setTimeout(() => { if (message.value.text === text) message.value = { text: '', ok: true } }, 3500)
}
function clearMessage() { message.value = { text: '', ok: true } }

async function loadSurveys(preferredId = null) {
  listLoading.value = true
  clearMessage()
  try {
    const { data } = await db.rpc('surveys_list')
    surveys.value = data || []

    const nextId = preferredId || selectedId.value
    if (nextId) {
      const exists = surveys.value.find(s => Number(s.id) === Number(nextId))
      if (exists) { await openSurvey(nextId); return }
    }
    if (!isCreating.value && surveys.value.length) {
      await openSurvey(surveys.value[0].id)
    }
  } catch (e) {
    setMessage('Не удалось загрузить список: ' + (e.message || e), false)
  } finally {
    listLoading.value = false
  }
}

async function openSurvey(id) {
  detailLoading.value = true
  clearMessage()
  selectedId.value = Number(id)
  isCreating.value = false
  activeTab.value = 'settings'
  try {
    const { data } = await db.rpc('survey_get', { id })
    detail.value = data
    form.value = cloneSurveyToForm(data)
    formSnapshot.value = JSON.stringify(form.value)
    if (data?.status !== 'draft' && (data?.responses?.length || 0) > 0) activeTab.value = 'results'
  } catch (e) {
    setMessage('Не удалось открыть опрос: ' + (e.message || e), false)
  } finally {
    detailLoading.value = false
  }
}

function startNewSurvey() {
  clearMessage()
  isCreating.value = true
  selectedId.value = null
  detail.value = null
  form.value = makeEmptySurvey()
  formSnapshot.value = JSON.stringify(form.value)
  activeTab.value = 'settings'
}

function resetFormFromDetail() {
  if (!detail.value) return
  clearMessage()
  form.value = cloneSurveyToForm(detail.value)
  formSnapshot.value = JSON.stringify(form.value)
}

// ══════ Вопросы / варианты ══════
function addQuestion() {
  form.value.questions.push({ text: '', type: 'choice', options: [{ text: '' }, { text: '' }] })
}
function removeQuestion(index) {
  if (form.value.questions.length <= 1) return
  form.value.questions.splice(index, 1)
}
function addOption(qi) { form.value.questions[qi].options.push({ text: '' }) }
function removeOption(qi, oi) {
  const opts = form.value.questions[qi].options
  if (opts.length <= 2) return
  opts.splice(oi, 1)
}
function setQuestionType(qi, type) {
  const q = form.value.questions[qi]
  if (!q) return
  q.type = ['choice', 'scale', 'text', 'files'].includes(type) ? type : 'choice'
  if (q.type === 'choice' && (!Array.isArray(q.options) || q.options.length < 2)) {
    q.options = [{ text: '' }, { text: '' }]
  }
  if (q.type === 'files' && typeof q.files_required !== 'boolean') {
    q.files_required = true
  }
}

function setQuestionFilesRequired(qi, value) {
  const q = form.value.questions[qi]
  if (!q) return
  q.files_required = !!value
}

function isImageMime(mime) {
  return typeof mime === 'string' && mime.startsWith('image/')
}
function humanFileSize(n) {
  const b = Number(n) || 0
  if (b < 1024) return b + ' Б'
  if (b < 1024 * 1024) return (b / 1024).toFixed(0) + ' КБ'
  return (b / 1024 / 1024).toFixed(1) + ' МБ'
}
function responseFilesByQuestion(resp) {
  const out = {}
  for (const f of (resp?.files || [])) {
    const qid = Number(f.question_id)
    if (!out[qid]) out[qid] = []
    out[qid].push(f)
  }
  return out
}
function questionTextById(qid) {
  const q = (detail.value?.questions || []).find(q => Number(q.id) === qid)
  return q?.text || ''
}

// Drag-and-drop вопросов
let dragQIndex = null
let dragOQ = null
let dragOIndex = null

function onQDragStart(ev, idx) {
  dragQIndex = idx
  ev.dataTransfer.effectAllowed = 'move'
}
function onQDrop(ev, idx) {
  if (dragQIndex === null || dragQIndex === idx) return
  const list = form.value.questions
  const [moved] = list.splice(dragQIndex, 1)
  list.splice(idx, 0, moved)
  dragQIndex = null
}
function onODragStart(ev, qi, oi) {
  dragOQ = qi; dragOIndex = oi
  ev.dataTransfer.effectAllowed = 'move'
}
function onODrop(ev, qi, oi) {
  if (dragOQ !== qi || dragOIndex === null || dragOIndex === oi) { dragOQ = null; dragOIndex = null; return }
  const list = form.value.questions[qi].options
  const [moved] = list.splice(dragOIndex, 1)
  list.splice(oi, 0, moved)
  dragOQ = null; dragOIndex = null
}

function buildPayload() {
  return {
    id: form.value.id || undefined,
    title: form.value.title.trim(),
    description: form.value.description.trim(),
    legal_entity_group: form.value.legal_entity_group,
    allow_comment: form.value.allow_comment ? 1 : 0,
    remind_after_hours: Math.max(1, Number(form.value.remind_after_hours || 24)),
    questions: form.value.questions.map(q => ({
      text: q.text.trim(),
      type: q.type || 'choice',
      files_required: q.type === 'files' ? (q.files_required !== false) : true,
      options: q.type === 'choice' ? q.options.map(o => ({ text: o.text.trim() })) : [],
    })),
  }
}

async function saveSurvey() {
  if (!canEditDraft.value) return
  saving.value = true; clearMessage()
  try {
    const { data } = await db.rpc('survey_save', buildPayload())
    isCreating.value = false
    await loadSurveys(data.id)
    setMessage('Опрос сохранён')
  } catch (e) {
    setMessage(e.message || 'Не удалось сохранить опрос', false)
  } finally { saving.value = false }
}

async function sendSurvey() {
  if (!canSendSurvey.value) return
  if (!confirm('Разослать этот опрос ресторанам в боте и кабинете?')) return
  sending.value = true; clearMessage()
  try {
    const { data } = await db.rpc('survey_send', { id: form.value.id })
    await loadSurveys(form.value.id)
    setMessage(`Опрос разослан: ${data.sent} из ${data.total}`)
  } catch (e) {
    setMessage(e.message || 'Не удалось разослать опрос', false)
  } finally { sending.value = false }
}

async function closeSurvey() {
  if (!canCloseSurvey.value) return
  if (!confirm('Закрыть опрос? После этого рестораны не смогут ответить.')) return
  closing.value = true; clearMessage(); menuOpen.value = false
  try {
    await db.rpc('survey_close', { id: form.value.id })
    await loadSurveys(form.value.id)
    setMessage('Опрос закрыт')
  } catch (e) {
    setMessage(e.message || 'Не удалось закрыть опрос', false)
  } finally { closing.value = false }
}

async function deleteSurvey() {
  if (!canDeleteSurvey.value) return
  if (!confirm('Удалить опрос? Это действие нельзя отменить.')) return
  deleting.value = true; clearMessage(); menuOpen.value = false
  try {
    await db.rpc('survey_delete', { id: form.value.id })
    detail.value = null
    form.value = makeEmptySurvey()
    selectedId.value = null
    isCreating.value = false
    await loadSurveys()
    setMessage('Опрос удалён')
  } catch (e) {
    setMessage(e.message || 'Не удалось удалить опрос', false)
  } finally { deleting.value = false }
}

async function deleteResponse(response) {
  if (!canManageResponses.value || !response?.id || !form.value.id) return
  const label = formatRestaurantNumber(response.restaurant_number, response.legal_entity_group)
  if (!confirm(`Удалить ответ ресторана ${label}?`)) return
  deletingResponseId.value = Number(response.id); clearMessage()
  try {
    await db.rpc('survey_response_delete', { id: response.id, survey_id: form.value.id })
    await loadSurveys(form.value.id)
    setMessage(`Ответ ресторана ${label} удалён`)
  } catch (e) {
    setMessage(e.message || 'Не удалось удалить ответ', false)
  } finally { deletingResponseId.value = null }
}

// ══════ Утилиты ══════
function surveyStatusLabel(status) {
  return { draft: 'Черновик', active: 'Активен', closed: 'Закрыт' }[status] || status
}

function formatDate(value) {
  if (!value) return '—'
  const d = new Date(value)
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' }) +
    ' ' + d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
}

function responsePct(s) {
  const total = Number(s?.target_restaurants_count || 0)
  const resp = Array.isArray(s?.responses) ? s.responses.length : Number(s?.responses_count || 0)
  if (!total) return 0
  return Math.min(100, Math.round(resp * 100 / total))
}

function toggleResponse(id) { openedResponses[id] = !openedResponses[id] }
function isResponseOpen(id) { return !!openedResponses[id] || responsesExpanded.value }
function surveyAnswerText(answer) {
  if (!answer) return '—'
  if (answer.type === 'scale') return answer.numeric_value ? String(answer.numeric_value) : '—'
  if (answer.type === 'text') return answer.text_value || '—'
  return answer.option_text || '—'
}

// Закрытие выпадающего меню
function onGlobalClick() {
  menuOpen.value = false
  if (filterPopover.questionId !== null) closeFilter()
}

// Сбрасываем открытые ответы при смене опроса
watch(selectedId, () => {
  for (const k of Object.keys(openedResponses)) delete openedResponses[k]
  responsesExpanded.value = false
})

function onGlobalScroll() { if (filterPopover.questionId !== null) closeFilter() }
function onGlobalKeydown(ev) { if (ev.key === 'Escape' && filterPopover.questionId !== null) closeFilter() }

onMounted(() => {
  loadSurveys()
  document.addEventListener('click', onGlobalClick)
  window.addEventListener('scroll', onGlobalScroll, true)
  document.addEventListener('keydown', onGlobalKeydown)
})
onUnmounted(() => {
  document.removeEventListener('click', onGlobalClick)
  window.removeEventListener('scroll', onGlobalScroll, true)
  document.removeEventListener('keydown', onGlobalKeydown)
})
</script>

<style scoped>
/* ══════ Базовые переменные и сброс ══════ */
.sv-view {
  --sv-text: #2c231b;
  --sv-muted: #8a7a6b;
  --sv-border: #ece2d4;
  --sv-border-soft: #f4ebe0;
  --sv-bg-soft: #faf5ee;
  --sv-accent: #d08b3a;
  --sv-accent-soft: #fbf1e0;
  --sv-primary: #4a2c18;
  --sv-success: #2c6b38;
  --sv-success-soft: #e8f5eb;
  --sv-warn: #b45309;
  --sv-warn-soft: #fff3e7;
  --sv-danger: #b12a2a;
  --sv-danger-soft: #fdecec;

  display: flex;
  flex-direction: column;
  gap: 20px;
}

/* ══════ Шапка страницы ══════ */
.sv-page-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
}
.sv-title {
  margin: 0;
  font-size: 26px;
  font-weight: 800;
  color: var(--sv-primary);
  letter-spacing: -0.01em;
}
.sv-subtitle {
  margin: 6px 0 0;
  color: var(--sv-muted);
  font-size: 14px;
}
.sv-page-head-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

/* ══════ Кнопки ══════ */
.sv-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  padding: 9px 14px;
  border-radius: 10px;
  font: inherit;
  font-weight: 600;
  font-size: 13px;
  cursor: pointer;
  border: 1px solid transparent;
  transition: .15s ease;
  white-space: nowrap;
}
.sv-btn:disabled { opacity: .55; cursor: not-allowed; }
.sv-btn.primary {
  background: var(--sv-primary);
  color: #fff;
}
.sv-btn.primary:not(:disabled):hover { background: #5e3a22; }
.sv-btn.accent {
  background: #1f8a4c;
  color: #fff;
}
.sv-btn.accent:not(:disabled):hover { background: #196f3d; }
.sv-btn.ghost {
  background: #fff;
  color: var(--sv-text);
  border-color: var(--sv-border);
}
.sv-btn.ghost:not(:disabled):hover {
  background: var(--sv-bg-soft);
  border-color: #dccab1;
}
.sv-btn.ghost.danger { color: var(--sv-danger); }
.sv-btn.ghost.danger:not(:disabled):hover { background: var(--sv-danger-soft); border-color: #f0cccc; }
.sv-btn.icon { padding: 9px 10px; }
.sv-btn.small { padding: 6px 10px; font-size: 12px; }

/* ══════ Layout ══════ */
.sv-layout {
  display: grid;
  grid-template-columns: 320px 1fr;
  gap: 20px;
  align-items: start;
}

/* ══════ Сайдбар ══════ */
.sv-sidebar {
  background: #fff;
  border: 1px solid var(--sv-border);
  border-radius: 16px;
  padding: 14px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  position: sticky;
  top: 16px;
  max-height: calc(100vh - 40px);
}
.sv-search {
  position: relative;
  display: flex;
  align-items: center;
}
.sv-search svg {
  position: absolute;
  left: 12px;
  color: var(--sv-muted);
  pointer-events: none;
}
.sv-search input {
  width: 100%;
  border: 1px solid var(--sv-border);
  background: var(--sv-bg-soft);
  border-radius: 10px;
  padding: 9px 12px 9px 34px;
  font: inherit;
  color: inherit;
}
.sv-search input:focus {
  outline: none;
  border-color: var(--sv-accent);
  background: #fff;
}

.sv-filter-tabs {
  display: flex;
  gap: 4px;
  padding: 4px;
  background: var(--sv-bg-soft);
  border-radius: 10px;
  overflow-x: auto;
}
.sv-filter-tab {
  flex: 1;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 7px 10px;
  border: none;
  background: transparent;
  border-radius: 8px;
  font: inherit;
  font-size: 12px;
  font-weight: 600;
  color: var(--sv-muted);
  cursor: pointer;
  transition: .15s ease;
  white-space: nowrap;
}
.sv-filter-tab:hover { color: var(--sv-text); }
.sv-filter-tab.active {
  background: #fff;
  color: var(--sv-primary);
  box-shadow: 0 1px 3px rgba(74, 44, 24, .08);
}
.sv-filter-count {
  padding: 1px 7px;
  border-radius: 999px;
  background: #efe5d5;
  color: var(--sv-muted);
  font-size: 11px;
  font-weight: 700;
}
.sv-filter-tab.active .sv-filter-count {
  background: var(--sv-accent-soft);
  color: var(--sv-accent);
}

.sv-list-scroll {
  display: flex;
  flex-direction: column;
  gap: 8px;
  overflow-y: auto;
  margin: -4px;
  padding: 4px;
  scrollbar-width: thin;
}

.sv-list-item {
  width: 100%;
  text-align: left;
  border: 1px solid var(--sv-border-soft);
  background: #fff;
  border-radius: 12px;
  padding: 12px;
  cursor: pointer;
  transition: .15s ease;
  display: flex;
  flex-direction: column;
  gap: 7px;
  font: inherit;
  color: inherit;
}
.sv-list-item:hover {
  border-color: #dccab1;
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(74, 44, 24, .05);
}
.sv-list-item.active {
  border-color: var(--sv-accent);
  background: linear-gradient(135deg, #fff 0%, var(--sv-accent-soft) 100%);
  box-shadow: 0 4px 12px rgba(208, 139, 58, .12);
}

.sv-list-item-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}
.sv-list-item-title {
  font-size: 13px;
  font-weight: 700;
  color: var(--sv-text);
  line-height: 1.3;
  overflow: hidden;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
}
.sv-list-item-group {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .03em;
  color: var(--sv-muted);
  padding: 2px 7px;
  background: var(--sv-bg-soft);
  border-radius: 4px;
}
.sv-list-item-bar {
  display: flex;
  align-items: center;
  gap: 8px;
}
.sv-mini-bar {
  flex: 1;
  height: 4px;
  background: var(--sv-border-soft);
  border-radius: 2px;
  overflow: hidden;
}
.sv-mini-bar-fill {
  height: 100%;
  background: linear-gradient(90deg, var(--sv-accent), #e2a85c);
  border-radius: 2px;
  transition: width .3s ease;
}
.sv-list-item-stat {
  font-size: 11px;
  font-weight: 700;
  color: var(--sv-muted);
  white-space: nowrap;
}
.sv-list-item-meta {
  display: flex;
  gap: 6px;
  font-size: 11px;
  color: var(--sv-muted);
}

/* ══════ Бейджи статусов ══════ */
.sv-badge {
  display: inline-flex;
  align-items: center;
  padding: 3px 8px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .02em;
}
.sv-badge.s-draft { background: #efe5d5; color: #6a563f; }
.sv-badge.s-active { background: var(--sv-success-soft); color: var(--sv-success); }
.sv-badge.s-closed { background: #ededed; color: #616161; }

/* ══════ Главная карточка ══════ */
.sv-main { min-width: 0; }
.sv-card {
  background: #fff;
  border: 1px solid var(--sv-border);
  border-radius: 16px;
}
.sv-detail {
  padding: 22px 24px;
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.sv-empty {
  padding: 28px;
  text-align: center;
  color: var(--sv-muted);
  font-size: 14px;
}
.sv-empty.tight { padding: 16px; font-size: 13px; }

.sv-hero-empty {
  padding: 48px 24px;
  text-align: center;
  color: var(--sv-muted);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 14px;
}
.sv-hero-empty svg {
  color: #d9c6ac;
}
.sv-hero-empty h3 {
  margin: 0;
  font-size: 18px;
  color: var(--sv-primary);
  font-weight: 700;
}
.sv-hero-empty p {
  margin: 0;
  max-width: 420px;
  line-height: 1.45;
}

/* Шапка карточки */
.sv-detail-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  padding-bottom: 16px;
  border-bottom: 1px solid var(--sv-border-soft);
}
.sv-detail-title-wrap { min-width: 0; flex: 1; }
.sv-detail-title {
  margin: 0;
  font-size: 22px;
  font-weight: 800;
  color: var(--sv-primary);
  letter-spacing: -0.01em;
  line-height: 1.2;
  word-break: break-word;
}
.sv-detail-meta {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-top: 10px;
  align-items: center;
}
.sv-meta-item {
  font-size: 12px;
  color: var(--sv-muted);
}
.sv-detail-actions {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
}

/* Выпадающее меню ⋯ */
.sv-menu-wrap { position: relative; }
.sv-menu {
  position: absolute;
  top: calc(100% + 4px);
  right: 0;
  background: #fff;
  border: 1px solid var(--sv-border);
  border-radius: 10px;
  box-shadow: 0 8px 24px rgba(74, 44, 24, .12);
  min-width: 180px;
  padding: 4px;
  z-index: 10;
}
.sv-menu button {
  display: block;
  width: 100%;
  text-align: left;
  border: none;
  background: transparent;
  padding: 9px 12px;
  border-radius: 8px;
  cursor: pointer;
  font: inherit;
  font-size: 13px;
  color: var(--sv-text);
}
.sv-menu button:hover { background: var(--sv-bg-soft); }
.sv-menu button.danger { color: var(--sv-danger); }
.sv-menu button.danger:hover { background: var(--sv-danger-soft); }
.sv-menu button:disabled { opacity: .5; cursor: not-allowed; }

/* Alert */
.sv-alert {
  padding: 10px 14px;
  border-radius: 10px;
  font-size: 13px;
}
.sv-alert.ok { background: var(--sv-success-soft); color: var(--sv-success); border: 1px solid #cde8d2; }
.sv-alert.err { background: var(--sv-danger-soft); color: var(--sv-danger); border: 1px solid #f0cccc; }

/* ══════ Прогресс ответов ══════ */
.sv-progress-card {
  padding: 16px 18px;
  background: linear-gradient(135deg, #fdf7ee 0%, #fbf1e0 100%);
  border: 1px solid var(--sv-border-soft);
  border-radius: 14px;
}
.sv-progress-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
  gap: 12px;
}
.sv-progress-title {
  font-size: 14px;
  font-weight: 700;
  color: var(--sv-primary);
}
.sv-progress-sub {
  font-size: 12px;
  color: var(--sv-muted);
  margin-top: 2px;
}
.sv-progress-pct {
  font-size: 26px;
  font-weight: 800;
  color: var(--sv-accent);
  letter-spacing: -0.02em;
}
.sv-progress-bar {
  height: 8px;
  background: #fff;
  border-radius: 4px;
  overflow: hidden;
  border: 1px solid #f0e3cf;
}
.sv-progress-fill {
  height: 100%;
  background: linear-gradient(90deg, var(--sv-accent), #e2a85c);
  border-radius: 4px;
  transition: width .4s ease;
}

/* ══════ Вкладки ══════ */
.sv-tabs {
  display: flex;
  gap: 4px;
  border-bottom: 1px solid var(--sv-border-soft);
  margin-bottom: 4px;
}
.sv-tab {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  border: none;
  background: transparent;
  font: inherit;
  font-size: 13px;
  font-weight: 600;
  color: var(--sv-muted);
  cursor: pointer;
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
  transition: .15s ease;
}
.sv-tab:hover { color: var(--sv-text); }
.sv-tab.active {
  color: var(--sv-primary);
  border-bottom-color: var(--sv-accent);
}
.sv-tab-count {
  padding: 1px 7px;
  background: var(--sv-accent-soft);
  color: var(--sv-accent);
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
}

.sv-tab-body {
  display: flex;
  flex-direction: column;
  gap: 24px;
}

/* ══════ Форма ══════ */
.sv-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px;
}
.sv-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.sv-field.full { grid-column: 1 / -1; }
.sv-label {
  font-size: 12px;
  font-weight: 600;
  color: #5c4a3a;
  letter-spacing: .01em;
}
.sv-input {
  width: 100%;
  border: 1px solid var(--sv-border);
  background: #fff;
  border-radius: 10px;
  padding: 10px 12px;
  font: inherit;
  color: inherit;
  transition: border-color .15s ease;
}
.sv-input:focus {
  outline: none;
  border-color: var(--sv-accent);
  box-shadow: 0 0 0 3px var(--sv-accent-soft);
}
.sv-input:disabled {
  background: var(--sv-bg-soft);
  color: #8a7a6b;
}
.sv-textarea { resize: vertical; min-height: 70px; }

/* Switch */
.sv-switch {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
  padding: 8px 0;
}
.sv-switch input { display: none; }
.sv-switch-track {
  position: relative;
  width: 38px;
  height: 22px;
  background: #e0d4c2;
  border-radius: 11px;
  transition: background .15s ease;
  flex-shrink: 0;
}
.sv-switch-thumb {
  position: absolute;
  top: 3px;
  left: 3px;
  width: 16px;
  height: 16px;
  background: #fff;
  border-radius: 50%;
  transition: transform .15s ease;
  box-shadow: 0 1px 3px rgba(0, 0, 0, .2);
}
.sv-switch input:checked + .sv-switch-track {
  background: var(--sv-accent);
}
.sv-switch input:checked + .sv-switch-track .sv-switch-thumb {
  transform: translateX(16px);
}
.sv-switch input:disabled + .sv-switch-track { opacity: .55; }
.sv-switch-text {
  font-size: 13px;
  color: var(--sv-text);
}

/* ══════ Вопросы ══════ */
.sv-questions {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.sv-questions-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
}
.sv-questions-head h3 {
  margin: 0;
  font-size: 16px;
  font-weight: 700;
  color: var(--sv-primary);
}
.sv-questions-hint {
  margin: 3px 0 0;
  font-size: 12px;
  color: var(--sv-muted);
}
.sv-question {
  padding: 14px 14px 14px 14px;
  border: 1px solid var(--sv-border-soft);
  border-radius: 12px;
  background: #fff;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.sv-question:hover { border-color: var(--sv-border); }
.sv-question[draggable="true"] { cursor: grab; }
.sv-question[draggable="true"]:active { cursor: grabbing; }

.sv-question-head {
  display: flex;
  align-items: center;
  gap: 8px;
}
.sv-drag-handle {
  color: #c7b69b;
  display: inline-flex;
  cursor: grab;
  padding: 2px;
  border-radius: 4px;
}
.sv-drag-handle:hover { color: var(--sv-muted); background: var(--sv-bg-soft); }
.sv-question-num {
  font-size: 12px;
  font-weight: 700;
  color: var(--sv-primary);
  flex: 1;
}
.sv-q-remove, .sv-o-remove {
  background: transparent;
  border: none;
  color: #c27070;
  padding: 6px;
  border-radius: 6px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: .15s ease;
}
.sv-q-remove:not(:disabled):hover, .sv-o-remove:not(:disabled):hover {
  background: var(--sv-danger-soft);
  color: var(--sv-danger);
}
.sv-q-remove:disabled, .sv-o-remove:disabled { opacity: .3; cursor: not-allowed; }

.sv-question-input {
  font-weight: 600;
}
.sv-question-type {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}
.sv-type-btn {
  border: 1px solid var(--sv-border-soft);
  background: var(--sv-bg-soft);
  color: var(--sv-text);
  border-radius: 8px;
  min-height: 34px;
  padding: 7px 11px;
  font: inherit;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
}
.sv-type-btn.active {
  background: var(--sv-primary);
  border-color: var(--sv-primary);
  color: #fff;
}
.sv-type-btn:disabled {
  cursor: not-allowed;
  opacity: .65;
}
.sv-type-note {
  color: var(--sv-muted);
  background: var(--sv-bg-soft);
  border: 1px solid var(--sv-border-soft);
  border-radius: 8px;
  padding: 9px 11px;
  font-size: 13px;
}
.sv-files-req { display: inline-flex; align-items: center; gap: 6px; margin-top: 8px; font-size: 12.5px; color: var(--sv-fg); }
.sv-files-req input { margin: 0; }

/* Файлы в просмотре ответа: компактные «чипсы» с миниатюрой/иконкой и ссылкой. */
.sv-response-files { display: flex; flex-wrap: wrap; gap: 6px; }
.sv-response-file { display: inline-flex; align-items: center; gap: 6px; padding: 4px 8px 4px 4px; background: #FBF6EE; border: 1px solid #ECE2D2; border-radius: 8px; text-decoration: none; color: #502314; font-size: 12.5px; max-width: 280px; }
.sv-response-file:hover { background: #F5EAD8; }
.sv-response-file-thumb { width: 24px; height: 24px; border-radius: 4px; background: #fff; border: 1px solid #ECE2D2; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; overflow: hidden; color: #8b7355; }
.sv-response-file-thumb img { width: 100%; height: 100%; object-fit: cover; }
.sv-response-file-name { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px; }
.sv-response-file-size { color: #8b7355; font-size: 11px; flex-shrink: 0; }

.sv-options {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.sv-option-row {
  display: flex;
  align-items: center;
  gap: 8px;
}
.sv-option-row[draggable="true"] { cursor: grab; }
.sv-option-dot {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  border: 2px solid #d9c6ac;
  flex-shrink: 0;
}
.sv-add-option {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  align-self: flex-start;
  padding: 5px 10px;
  background: transparent;
  border: 1px dashed var(--sv-border);
  border-radius: 8px;
  color: var(--sv-muted);
  font: inherit;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: .15s ease;
}
.sv-add-option:not(:disabled):hover {
  background: var(--sv-bg-soft);
  border-color: var(--sv-accent);
  color: var(--sv-accent);
}
.sv-add-option:disabled { opacity: .5; cursor: not-allowed; }
.sv-scale-summary, .sv-analytics-text-note {
  padding: 10px 12px;
  border-radius: 10px;
  background: var(--sv-bg-soft);
  color: var(--sv-text);
  font-size: 13px;
}
.sv-scale-summary b {
  color: var(--sv-primary);
  font-size: 18px;
}

/* ══════ Результаты / Аналитика ══════ */
.sv-block-title {
  font-size: 15px;
  font-weight: 700;
  color: var(--sv-primary);
  margin: 0;
  display: flex;
  align-items: center;
  gap: 8px;
}
.sv-muted { color: var(--sv-muted); font-weight: 500; font-size: 13px; }
.sv-muted.small { font-size: 11px; }

.sv-analytics {
  display: flex;
  flex-direction: column;
  gap: 18px;
}
.sv-analytics-q {
  padding: 16px 18px;
  border: 1px solid var(--sv-border-soft);
  border-radius: 12px;
  background: var(--sv-bg-soft);
}
.sv-analytics-q-head {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 12px;
}
.sv-analytics-q-num {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: var(--sv-primary);
  color: #fff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 700;
  flex-shrink: 0;
}
.sv-analytics-q-text {
  flex: 1;
  font-size: 14px;
  font-weight: 600;
  color: var(--sv-text);
  line-height: 1.4;
}
.sv-analytics-q-total {
  font-size: 11px;
  font-weight: 700;
  color: var(--sv-muted);
  white-space: nowrap;
  padding: 2px 8px;
  background: #fff;
  border-radius: 4px;
}
.sv-analytics-options {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.sv-analytics-option { display: flex; flex-direction: column; gap: 4px; }
.sv-analytics-option-head {
  display: flex;
  justify-content: space-between;
  gap: 10px;
  font-size: 13px;
}
.sv-analytics-option-text {
  color: var(--sv-text);
  font-weight: 500;
}
.sv-analytics-option-count {
  color: var(--sv-muted);
  font-weight: 700;
  font-size: 12px;
  white-space: nowrap;
}
.sv-analytics-bar {
  height: 6px;
  background: #fff;
  border-radius: 3px;
  overflow: hidden;
  border: 1px solid #f0e3cf;
}
.sv-analytics-bar-fill {
  height: 100%;
  background: linear-gradient(90deg, var(--sv-accent), #e2a85c);
  border-radius: 3px;
  transition: width .4s ease;
}

/* Комментарии */
.sv-comments {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.sv-comment {
  padding: 12px 14px;
  background: var(--sv-bg-soft);
  border: 1px solid var(--sv-border-soft);
  border-radius: 10px;
}
.sv-comment-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 6px;
}
.sv-comment-rest {
  font-size: 12px;
  font-weight: 700;
  color: var(--sv-primary);
}
.sv-comment-text {
  font-size: 13px;
  color: var(--sv-text);
  line-height: 1.5;
  white-space: pre-wrap;
}

/* Ответы по ресторанам */
.sv-responses { display: flex; flex-direction: column; gap: 10px; }
.sv-responses-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}
.sv-response-list { display: flex; flex-direction: column; gap: 6px; }
.sv-response {
  border: 1px solid var(--sv-border-soft);
  border-radius: 10px;
  background: #fff;
  overflow: hidden;
}
.sv-response-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  padding: 11px 14px;
  cursor: pointer;
  transition: background .15s ease;
}
.sv-response-head:hover { background: var(--sv-bg-soft); }
.sv-response-rest {
  font-size: 13px;
  font-weight: 700;
  color: var(--sv-primary);
}
.sv-response-addr {
  font-size: 11px;
  color: var(--sv-muted);
  margin-top: 2px;
}
.sv-response-right {
  display: flex;
  align-items: center;
  gap: 10px;
}
.sv-response-chev {
  color: var(--sv-muted);
  transition: transform .2s ease;
}
.sv-response-chev.open { transform: rotate(180deg); }
.sv-response-body {
  padding: 12px 14px 14px;
  border-top: 1px solid var(--sv-border-soft);
  background: var(--sv-bg-soft);
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.sv-response-answer {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.sv-response-q {
  font-size: 11px;
  color: var(--sv-muted);
  text-transform: uppercase;
  letter-spacing: .04em;
  font-weight: 600;
}
.sv-response-a {
  font-size: 13px;
  color: var(--sv-text);
  font-weight: 500;
}
.sv-response-comment {
  margin-top: 4px;
  padding: 8px 10px;
  background: #fff;
  border-radius: 8px;
  font-size: 13px;
  color: var(--sv-text);
  line-height: 1.4;
  white-space: pre-wrap;
}

/* Не ответили */
.sv-pending { display: flex; flex-direction: column; gap: 10px; }
.sv-pending-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.sv-pending-chip {
  padding: 4px 10px;
  background: var(--sv-warn-soft);
  color: var(--sv-warn);
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
}
.sv-all-done {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  background: var(--sv-success-soft);
  color: var(--sv-success);
  border-radius: 10px;
  font-size: 13px;
  font-weight: 600;
  align-self: flex-start;
}

/* ══════ Табличный вид ══════ */
.sv-table-toolbar {
  display: flex;
  gap: 12px;
  align-items: center;
  flex-wrap: wrap;
  margin-bottom: 12px;
}
.sv-table-search {
  flex: 1;
  min-width: 240px;
  max-width: 420px;
}
.sv-table-toolbar-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-left: auto;
}
.sv-table-count {
  font-size: 12px;
  color: var(--sv-muted);
  font-weight: 600;
}
.sv-table-wrap {
  overflow-x: auto;
  border: 1px solid var(--sv-border-soft);
  border-radius: 12px;
  background: #fff;
  cursor: grab;
}
.sv-table-wrap.dragging {
  cursor: grabbing;
  user-select: none;
}
.sv-table-wrap.dragging * {
  cursor: grabbing !important;
}
.sv-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 13px;
}
.sv-table thead th {
  position: sticky;
  top: 0;
  z-index: 2;
  background: var(--sv-primary);
  color: #fff;
  text-align: left;
  font-weight: 600;
  padding: 10px 10px;
  border-right: 1px solid rgba(255, 255, 255, .12);
  user-select: none;
  white-space: nowrap;
}
.sv-table thead th:last-child { border-right: none; }
.sv-th {
  cursor: pointer;
  transition: background .15s ease;
}
.sv-th:hover { background: #5e3a22; }
.sv-th.active { background: #6b4225; }
.sv-th-q-row {
  display: flex;
  align-items: center;
  gap: 6px;
  max-width: 220px;
}
.sv-th-q-text {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 12px;
  font-weight: 600;
}
.sv-th-filter-btn {
  border: none;
  background: transparent;
  color: #fff;
  opacity: .65;
  cursor: pointer;
  padding: 3px;
  border-radius: 4px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.sv-th-filter-btn:hover { opacity: 1; background: rgba(255, 255, 255, .12); }
.sv-th-filter-btn.active { opacity: 1; background: var(--sv-accent); color: #fff; }
.sv-th-sort {
  width: 10px;
  height: 14px;
  display: inline-block;
  position: relative;
  opacity: .5;
}
.sv-th-sort::before,
.sv-th-sort::after {
  content: '';
  position: absolute;
  left: 50%;
  transform: translateX(-50%);
  border-left: 4px solid transparent;
  border-right: 4px solid transparent;
}
.sv-th-sort::before {
  top: 1px;
  border-bottom: 4px solid currentColor;
}
.sv-th-sort::after {
  bottom: 1px;
  border-top: 4px solid currentColor;
}
.sv-th-sort.asc { opacity: 1; }
.sv-th-sort.asc::after { opacity: .25; }
.sv-th-sort.desc { opacity: 1; }
.sv-th-sort.desc::before { opacity: .25; }

.sv-table tbody td {
  padding: 9px 10px;
  border-bottom: 1px solid var(--sv-border-soft);
  border-right: 1px solid var(--sv-border-soft);
  vertical-align: top;
  color: var(--sv-text);
}
.sv-table tbody td:last-child { border-right: none; }
.sv-table tbody tr:last-child td { border-bottom: none; }
.sv-table tbody tr:hover td { background: var(--sv-bg-soft); }
.sv-td-rest-num {
  font-weight: 700;
  color: var(--sv-primary);
  white-space: nowrap;
}
.sv-td-rest-addr {
  font-size: 11px;
  color: var(--sv-muted);
  margin-top: 2px;
  max-width: 240px;
}
.sv-td-date {
  white-space: nowrap;
  color: var(--sv-muted);
  font-size: 12px;
}
.sv-td-a {
  max-width: 260px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.sv-td-center { text-align: center; font-weight: 600; }
.sv-td-empty {
  text-align: center;
  padding: 24px !important;
  color: var(--sv-muted);
}
.sv-table-comments { margin-top: 20px; }
.sv-table-pending { margin-top: 20px; }

/* Поповер фильтра колонки */
.sv-filter-popover {
  position: fixed;
  z-index: 100;
  width: 260px;
  max-width: calc(100vw - 16px);
  max-height: calc(100vh - 16px);
  overflow: auto;
  background: #fff;
  border: 1px solid var(--sv-border);
  border-radius: 12px;
  box-shadow: 0 12px 28px rgba(60, 30, 0, .18);
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.sv-filter-popover-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-weight: 700;
  font-size: 13px;
  color: var(--sv-primary);
}
.sv-filter-popover-close {
  border: none;
  background: transparent;
  font-size: 20px;
  line-height: 1;
  cursor: pointer;
  color: var(--sv-muted);
  padding: 0 4px;
}
.sv-filter-popover-close:hover { color: var(--sv-text); }
.sv-filter-popover-list {
  max-height: 240px;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 2px;
  border: 1px solid var(--sv-border-soft);
  border-radius: 8px;
  padding: 4px;
}
.sv-filter-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 8px;
  border-radius: 6px;
  cursor: pointer;
  font-size: 13px;
}
.sv-filter-row:hover { background: var(--sv-bg-soft); }
.sv-filter-row input { cursor: pointer; }
.sv-filter-row-label {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--sv-text);
}
.sv-filter-row-count {
  font-size: 11px;
  font-weight: 700;
  color: var(--sv-muted);
}
.sv-filter-popover-actions {
  display: flex;
  gap: 6px;
  justify-content: flex-end;
}
.sv-filter-empty {
  text-align: center;
  font-size: 12px;
  color: var(--sv-muted);
  padding: 12px 0;
}

/* ══════ Адаптив ══════ */
@media (max-width: 1100px) {
  .sv-layout { grid-template-columns: 1fr; }
  .sv-sidebar {
    position: static;
    max-height: 420px;
  }
}
@media (max-width: 720px) {
  .sv-page-head { flex-direction: column; align-items: stretch; }
  .sv-detail-head { flex-direction: column; }
  .sv-grid { grid-template-columns: 1fr; }
  .sv-detail-actions { width: 100%; }
  .sv-detail-actions .sv-btn { flex: 1; }
  .sv-detail { padding: 16px; }
  .sv-detail-title { font-size: 18px; }
  .sv-analytics-q { padding: 12px; }
}
</style>
