<template>
  <div class="surveys-view">
    <div class="page-header surveys-header">
      <div>
        <h1 class="page-title">Опросы</h1>
        <p class="surveys-subtitle">Создание опросов для ресторанов, рассылка в бот, ответы в боте и в личном кабинете.</p>
      </div>
      <div class="surveys-header-actions">
        <button class="btn secondary" @click="loadSurveys" :disabled="listLoading || detailLoading">Обновить</button>
        <button class="btn primary" @click="startNewSurvey">Новый опрос</button>
      </div>
    </div>

    <div class="surveys-layout">
      <aside class="surveys-sidebar">
        <div class="surveys-sidebar-head">
          <h3>Список опросов</h3>
          <span class="surveys-count">{{ surveys.length }}</span>
        </div>

        <div v-if="listLoading" class="surveys-empty">Загрузка списка...</div>
        <div v-else-if="!surveys.length" class="surveys-empty">Опросов пока нет.</div>
        <button
          v-for="survey in surveys"
          :key="survey.id"
          class="survey-list-item"
          :class="{ active: selectedId === Number(survey.id) && !isCreating }"
          @click="openSurvey(survey.id)"
        >
          <div class="survey-list-title">{{ survey.title }}</div>
          <div class="survey-list-meta">
            <span class="survey-badge" :class="'survey-badge-' + survey.status">{{ surveyStatusLabel(survey.status) }}</span>
            <span>{{ survey.legal_entity_group === 'PS' ? 'ПС' : 'БК/ВМ' }}</span>
            <span>{{ survey.questions_count }} вопр.</span>
          </div>
          <div class="survey-list-foot">
            <span>Ответов: {{ survey.responses_count }} / {{ survey.target_restaurants_count || 0 }}</span>
            <span>{{ formatDate(survey.created_at) }}</span>
          </div>
        </button>
      </aside>

      <section class="surveys-main">
        <div v-if="detailLoading" class="surveys-card surveys-empty">Загрузка опроса...</div>

        <div v-else-if="!isCreating && !form.id" class="surveys-card surveys-empty">
          Выберите опрос слева или создайте новый.
        </div>

        <div v-else class="surveys-card">
          <div class="survey-top">
            <div>
              <h2>{{ isCreating ? 'Новый опрос' : form.title || 'Опрос без названия' }}</h2>
              <div class="survey-top-meta" v-if="!isCreating">
                <span class="survey-badge" :class="'survey-badge-' + form.status">{{ surveyStatusLabel(form.status) }}</span>
                <span>Создал: {{ detail?.created_by || '—' }}</span>
                <span>Создан: {{ formatDate(detail?.created_at) }}</span>
              </div>
            </div>
            <div class="survey-actions">
              <button class="btn secondary" @click="resetFormFromDetail" v-if="canEditDraft && !isCreating">Сбросить</button>
              <button class="btn primary" @click="saveSurvey" :disabled="saving || !canEditDraft">
                {{ saving ? 'Сохранение...' : 'Сохранить' }}
              </button>
              <button class="btn primary" @click="sendSurvey" :disabled="sending || !canSendSurvey">
                {{ sending ? 'Рассылка...' : 'Разослать' }}
              </button>
              <button class="btn secondary" @click="closeSurvey" :disabled="closing || !canCloseSurvey">Закрыть</button>
              <button class="btn secondary danger" @click="deleteSurvey" :disabled="deleting || !canDeleteSurvey">Удалить</button>
            </div>
          </div>

          <div v-if="message.text" class="survey-message" :class="message.ok ? 'ok' : 'err'">{{ message.text }}</div>

          <div class="survey-stats" v-if="selectedMeta && !isCreating">
            <div class="survey-stat">
              <div class="survey-stat-value">{{ selectedMeta.questions_count || form.questions.length }}</div>
              <div class="survey-stat-label">Вопросов</div>
            </div>
            <div class="survey-stat">
              <div class="survey-stat-value">{{ selectedMeta.responses_count || 0 }}</div>
              <div class="survey-stat-label">Ответов</div>
            </div>
            <div class="survey-stat">
              <div class="survey-stat-value">{{ selectedMeta.target_restaurants_count || 0 }}</div>
              <div class="survey-stat-label">Ресторанов в группе</div>
            </div>
            <div class="survey-stat">
              <div class="survey-stat-value">{{ form.remind_after_hours }}</div>
              <div class="survey-stat-label">Часов до напоминания</div>
            </div>
          </div>

          <div class="survey-form-grid">
            <label class="field">
              <span>Название опроса</span>
              <input v-model="form.title" class="input" :disabled="!canEditDraft" placeholder="Например: Сдали ли пивное оборудование?" />
            </label>

            <label class="field">
              <span>Группа юрлиц</span>
              <select v-model="form.legal_entity_group" class="input" :disabled="!canEditDraft">
                <option value="BK_VM">BK_VM</option>
                <option value="PS">PS</option>
              </select>
            </label>

            <label class="field field-full">
              <span>Описание в сообщении бота</span>
              <textarea
                v-model="form.description"
                class="input textarea"
                :disabled="!canEditDraft"
                rows="3"
                placeholder="Короткое пояснение для ресторанов"
              />
            </label>

            <label class="field field-inline">
              <span>Комментарий от ресторана</span>
              <label class="toggle">
                <input v-model="form.allow_comment" type="checkbox" :disabled="!canEditDraft" />
                <span>Разрешить комментарий</span>
              </label>
            </label>

            <label class="field">
              <span>Напоминать каждые N часов</span>
              <input v-model.number="form.remind_after_hours" class="input" type="number" min="1" :disabled="!canEditDraft" />
            </label>
          </div>

          <div class="questions-block">
            <div class="questions-head">
              <h3>Вопросы</h3>
              <button class="btn secondary" @click="addQuestion" :disabled="!canEditDraft">Добавить вопрос</button>
            </div>

            <div v-if="!form.questions.length" class="surveys-empty questions-empty">Добавьте хотя бы один вопрос.</div>

            <div v-for="(question, qIndex) in form.questions" :key="qIndex" class="question-card">
              <div class="question-head">
                <div class="question-number">Вопрос {{ qIndex + 1 }}</div>
                <button class="question-remove" @click="removeQuestion(qIndex)" :disabled="!canEditDraft || form.questions.length === 1">Удалить</button>
              </div>

              <input
                v-model="question.text"
                class="input"
                :disabled="!canEditDraft"
                placeholder="Текст вопроса"
              />

              <div class="options-list">
                <div v-for="(option, oIndex) in question.options" :key="oIndex" class="option-row">
                  <input
                    v-model="option.text"
                    class="input"
                    :disabled="!canEditDraft"
                    :placeholder="`Вариант ${oIndex + 1}`"
                  />
                  <button class="question-remove small" @click="removeOption(qIndex, oIndex)" :disabled="!canEditDraft || question.options.length <= 2">
                    ✕
                  </button>
                </div>
              </div>

              <button class="btn secondary small-btn" @click="addOption(qIndex)" :disabled="!canEditDraft">Добавить вариант</button>
            </div>
          </div>

          <div v-if="!isCreating" class="results-block">
            <div class="results-head">
              <h3>Ответы ресторанов</h3>
              <span>{{ detail?.responses?.length || 0 }} ответов</span>
            </div>

            <div v-if="!detail?.responses?.length" class="surveys-empty">Пока никто не ответил.</div>
            <div v-else class="results-table-wrap">
              <table class="results-table">
                <thead>
                  <tr>
                    <th>Ресторан</th>
                    <th>Ответы</th>
                    <th>Комментарий</th>
                    <th>Когда</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="response in detail.responses" :key="response.id">
                    <td>
                      <div class="result-title">{{ formatRestaurantNumber(response.restaurant_number, response.legal_entity_group) }}</div>
                      <div class="result-sub">{{ [response.city, response.address].filter(Boolean).join(', ') || '—' }}</div>
                    </td>
                    <td>
                      <div v-for="answer in response.answers" :key="answer.question_id" class="answer-line">
                        <b>{{ answer.question_text }}</b>: {{ answer.option_text }}
                      </div>
                    </td>
                    <td>{{ response.comment || '—' }}</td>
                    <td>{{ formatDate(response.submitted_at) }}</td>
                    <td class="result-actions">
                      <button
                        v-if="canManageResponses"
                        class="btn secondary danger small-btn"
                        :disabled="deletingResponseId === Number(response.id)"
                        @click="deleteResponse(response)"
                      >
                        {{ deletingResponseId === Number(response.id) ? 'Удаление...' : 'Удалить ответ' }}
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div class="pending-block">
              <h4>Не ответили</h4>
              <div v-if="!detail?.pending_restaurants?.length" class="surveys-empty small-empty">Все рестораны ответили.</div>
              <div v-else class="pending-list">
                <span v-for="item in detail.pending_restaurants" :key="`${item.legal_entity_group}-${item.restaurant_number}`" class="pending-chip">
                  {{ formatRestaurantNumber(item.restaurant_number, item.legal_entity_group) }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { db } from '@/lib/apiClient.js'
import { formatRestaurantNumber } from '@/lib/legalEntities.js'
import { useUserStore } from '@/stores/userStore.js'

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
      {
        text: '',
        options: [{ text: '' }, { text: '' }],
      },
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
      options: (q.options || []).map(o => ({ text: o.text || '' })),
    })),
  }
}

const surveys = ref([])
const selectedId = ref(null)
const detail = ref(null)
const form = ref(makeEmptySurvey())
const isCreating = ref(false)

const listLoading = ref(false)
const detailLoading = ref(false)
const saving = ref(false)
const sending = ref(false)
const closing = ref(false)
const deleting = ref(false)
const deletingResponseId = ref(null)

const message = ref({ text: '', ok: true })

const selectedMeta = computed(() => surveys.value.find(s => Number(s.id) === Number(selectedId.value)) || null)
const canEditDraft = computed(() => userStore.hasAccess('surveys', 'edit') && (isCreating.value || form.value.status === 'draft'))
const canSendSurvey = computed(() => userStore.hasAccess('surveys', 'edit') && !isCreating.value && form.value.status === 'draft')
const canCloseSurvey = computed(() => userStore.hasAccess('surveys', 'edit') && !isCreating.value && form.value.status === 'active')
const canDeleteSurvey = computed(() => userStore.hasAccess('surveys', 'full') && !isCreating.value)
const canManageResponses = computed(() => userStore.hasAccess('surveys', 'edit') && !isCreating.value)

function setMessage(text, ok = true) {
  message.value = { text, ok }
}

function clearMessage() {
  message.value = { text: '', ok: true }
}

async function loadSurveys(preferredId = null) {
  listLoading.value = true
  clearMessage()
  try {
    const { data } = await db.rpc('surveys_list')
    surveys.value = data || []

    const nextId = preferredId || selectedId.value
    if (nextId) {
      const exists = surveys.value.find(s => Number(s.id) === Number(nextId))
      if (exists) {
        await openSurvey(nextId)
        return
      }
    }

    if (!isCreating.value && surveys.value.length) {
      await openSurvey(surveys.value[0].id)
    }
  } catch (e) {
    setMessage('Не удалось загрузить список опросов: ' + (e.message || e), false)
  } finally {
    listLoading.value = false
  }
}

async function openSurvey(id) {
  detailLoading.value = true
  clearMessage()
  selectedId.value = Number(id)
  isCreating.value = false
  try {
    const { data } = await db.rpc('survey_get', { id })
    detail.value = data
    form.value = cloneSurveyToForm(data)
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
}

function resetFormFromDetail() {
  if (!detail.value) return
  clearMessage()
  form.value = cloneSurveyToForm(detail.value)
}

function addQuestion() {
  form.value.questions.push({ text: '', options: [{ text: '' }, { text: '' }] })
}

function removeQuestion(index) {
  if (form.value.questions.length <= 1) return
  form.value.questions.splice(index, 1)
}

function addOption(questionIndex) {
  form.value.questions[questionIndex].options.push({ text: '' })
}

function removeOption(questionIndex, optionIndex) {
  const options = form.value.questions[questionIndex].options
  if (options.length <= 2) return
  options.splice(optionIndex, 1)
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
      options: q.options.map(o => ({ text: o.text.trim() })),
    })),
  }
}

async function saveSurvey() {
  if (!canEditDraft.value) return
  saving.value = true
  clearMessage()
  try {
    const { data } = await db.rpc('survey_save', buildPayload())
    isCreating.value = false
    await loadSurveys(data.id)
    setMessage('Опрос сохранён.')
  } catch (e) {
    setMessage(e.message || 'Не удалось сохранить опрос', false)
  } finally {
    saving.value = false
  }
}

async function sendSurvey() {
  if (!canSendSurvey.value) return
  if (!confirm('Разослать этот опрос ресторанам в боте?')) return
  sending.value = true
  clearMessage()
  try {
    const { data } = await db.rpc('survey_send', { id: form.value.id })
    await loadSurveys(form.value.id)
    setMessage(`Опрос разослан: ${data.sent} из ${data.total}.`)
  } catch (e) {
    setMessage(e.message || 'Не удалось разослать опрос', false)
  } finally {
    sending.value = false
  }
}

async function closeSurvey() {
  if (!canCloseSurvey.value) return
  if (!confirm('Закрыть опрос? После этого рестораны не смогут ответить.')) return
  closing.value = true
  clearMessage()
  try {
    await db.rpc('survey_close', { id: form.value.id })
    await loadSurveys(form.value.id)
    setMessage('Опрос закрыт.')
  } catch (e) {
    setMessage(e.message || 'Не удалось закрыть опрос', false)
  } finally {
    closing.value = false
  }
}

async function deleteSurvey() {
  if (!canDeleteSurvey.value) return
  if (!confirm('Удалить опрос? Это действие нельзя отменить.')) return
  deleting.value = true
  clearMessage()
  try {
    await db.rpc('survey_delete', { id: form.value.id })
    detail.value = null
    form.value = makeEmptySurvey()
    selectedId.value = null
    isCreating.value = false
    await loadSurveys()
    setMessage('Опрос удалён.')
  } catch (e) {
    setMessage(e.message || 'Не удалось удалить опрос', false)
  } finally {
    deleting.value = false
  }
}

async function deleteResponse(response) {
  if (!canManageResponses.value || !response?.id || !form.value.id) return
  const restaurantLabel = formatRestaurantNumber(response.restaurant_number, response.legal_entity_group)
  if (!confirm(`Удалить ответ ресторана ${restaurantLabel}? После этого ресторан сможет ответить заново.`)) return

  deletingResponseId.value = Number(response.id)
  clearMessage()
  try {
    await db.rpc('survey_response_delete', { id: response.id, survey_id: form.value.id })
    await loadSurveys(form.value.id)
    setMessage(`Ответ ресторана ${restaurantLabel} удалён.`)
  } catch (e) {
    setMessage(e.message || 'Не удалось удалить ответ', false)
  } finally {
    deletingResponseId.value = null
  }
}

function surveyStatusLabel(status) {
  const map = {
    draft: 'Черновик',
    active: 'Активен',
    closed: 'Закрыт',
  }
  return map[status] || status
}

function formatDate(value) {
  if (!value) return '—'
  const date = new Date(value)
  return date.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' }) +
    ' ' + date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
}

onMounted(() => {
  loadSurveys()
})
</script>

<style scoped>
.surveys-view {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.surveys-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
}

.surveys-subtitle {
  margin: 6px 0 0;
  color: var(--text-muted, #7d746a);
}

.surveys-header-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.surveys-layout {
  display: grid;
  grid-template-columns: 320px 1fr;
  gap: 20px;
}

.surveys-sidebar,
.surveys-card {
  background: var(--card-bg, #fff);
  border: 1px solid var(--border-color, #e7e0d7);
  border-radius: 18px;
  box-shadow: 0 8px 24px rgba(62, 39, 23, 0.05);
}

.surveys-sidebar {
  padding: 16px;
  height: fit-content;
}

.surveys-sidebar-head,
.survey-top,
.questions-head,
.results-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}

.surveys-count {
  min-width: 28px;
  height: 28px;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: #efe5d8;
  color: #6b4f33;
  font-weight: 700;
}

.survey-list-item {
  width: 100%;
  text-align: left;
  border: 1px solid #efe5d8;
  background: #fffaf4;
  border-radius: 14px;
  padding: 14px;
  margin-top: 12px;
  cursor: pointer;
  transition: 0.15s ease;
}

.survey-list-item:hover,
.survey-list-item.active {
  border-color: #c9a97a;
  background: #fff;
  transform: translateY(-1px);
}

.survey-list-title,
.result-title {
  font-weight: 700;
  color: #2c231b;
}

.survey-list-meta,
.survey-list-foot,
.survey-top-meta,
.result-sub {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  font-size: 12px;
  color: var(--text-muted, #7d746a);
  margin-top: 8px;
}

.surveys-main {
  min-width: 0;
}

.surveys-card {
  padding: 20px;
}

.surveys-empty {
  padding: 24px;
  text-align: center;
  color: var(--text-muted, #7d746a);
}

.survey-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.danger {
  color: #c62828;
}

.survey-message {
  margin-top: 14px;
  padding: 12px 14px;
  border-radius: 12px;
  font-size: 14px;
}

.survey-message.ok {
  background: #eef8ef;
  color: #2c6b38;
  border: 1px solid #cde8d2;
}

.survey-message.err {
  background: #fff1f1;
  color: #b12a2a;
  border: 1px solid #f0cccc;
}

.survey-stats {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin-top: 18px;
}

.survey-stat {
  padding: 14px;
  border-radius: 14px;
  background: #faf4ed;
  border: 1px solid #efe5d8;
}

.survey-stat-value {
  font-size: 26px;
  font-weight: 700;
  color: #3f2e1f;
}

.survey-stat-label {
  margin-top: 4px;
  font-size: 12px;
  color: var(--text-muted, #7d746a);
}

.survey-form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
  margin-top: 20px;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.field span {
  font-size: 13px;
  font-weight: 600;
  color: #4c3d31;
}

.field-full {
  grid-column: 1 / -1;
}

.field-inline {
  justify-content: flex-end;
}

.toggle {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-weight: 400;
}

.input {
  width: 100%;
  border: 1px solid #d8c9b8;
  border-radius: 12px;
  padding: 11px 12px;
  background: #fff;
  font: inherit;
  color: inherit;
}

.input:disabled {
  background: #f8f5f1;
  color: #7d746a;
}

.textarea {
  resize: vertical;
}

.questions-block,
.results-block {
  margin-top: 28px;
}

.question-card {
  margin-top: 14px;
  padding: 16px;
  border: 1px solid #efe5d8;
  border-radius: 16px;
  background: #fffaf4;
}

.question-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
}

.question-number {
  font-weight: 700;
  color: #4c3d31;
}

.question-remove {
  border: none;
  background: transparent;
  color: #b12a2a;
  cursor: pointer;
  font: inherit;
}

.question-remove.small {
  width: 34px;
  height: 34px;
  border-radius: 10px;
  border: 1px solid #efd4d4;
  background: #fff;
}

.options-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-top: 12px;
}

.option-row {
  display: grid;
  grid-template-columns: 1fr 34px;
  gap: 10px;
}

.small-btn {
  margin-top: 12px;
}

.results-table-wrap {
  overflow-x: auto;
  margin-top: 14px;
}

.result-actions {
  white-space: nowrap;
  text-align: right;
}

.results-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

.results-table th,
.results-table td {
  padding: 12px;
  border-bottom: 1px solid #efe5d8;
  vertical-align: top;
  text-align: left;
}

.results-table th {
  color: var(--text-muted, #7d746a);
  font-size: 12px;
  background: #faf4ed;
}

.answer-line + .answer-line {
  margin-top: 8px;
}

.pending-block {
  margin-top: 20px;
}

.pending-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 10px;
}

.pending-chip,
.survey-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
}

.survey-badge-draft {
  background: #eee7dc;
  color: #6a563f;
}

.survey-badge-active {
  background: #e8f5eb;
  color: #2c6b38;
}

.survey-badge-closed {
  background: #f3f3f3;
  color: #616161;
}

.pending-chip {
  background: #fff4e5;
  color: #8a5a12;
}

.small-empty {
  padding: 12px 0 0;
  text-align: left;
}

@media (max-width: 1100px) {
  .surveys-layout {
    grid-template-columns: 1fr;
  }

  .survey-stats,
  .survey-form-grid {
    grid-template-columns: 1fr 1fr;
  }
}

@media (max-width: 720px) {
  .surveys-header,
  .survey-top,
  .questions-head,
  .results-head {
    flex-direction: column;
    align-items: stretch;
  }

  .survey-stats,
  .survey-form-grid,
  .option-row {
    grid-template-columns: 1fr;
  }

  .question-remove.small {
    width: 100%;
  }
}
</style>
