<template>
  <section class="cab-section cab-sv-section">
    <div v-if="surveyError" class="error-msg" style="margin-bottom:16px">{{ surveyError }}</div>

    <!-- Начальная загрузка -->
    <div v-if="loading && !items.length" class="cab-empty-card">
      <BurgerSpinner text="Загрузка опросов..." />
    </div>

    <!-- Пусто -->
    <div v-else-if="!items.length" class="cab-empty-card">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#D7B79A" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:8px">
        <rect x="3" y="4" width="18" height="17" rx="2"/>
        <path d="M8 2v4M16 2v4M3 10h18"/>
        <path d="M9 15l2 2 4-4"/>
      </svg>
      <h2>Пока нет опросов</h2>
      <p>Когда появится новый опрос, вы увидите его здесь и получите уведомление в боте.</p>
    </div>

    <!-- Список -->
    <div v-else-if="surveyMode === 'list'" class="cab-sv-home">
      <div v-if="pendingSurveys.length" class="cab-sv-group">
        <div class="cab-sv-group-head">
          <span class="cab-sv-group-title">Нужно ответить</span>
          <span class="cab-sv-group-count">{{ pendingSurveys.length }}</span>
        </div>
        <button v-for="survey in pendingSurveys" :key="survey.id"
          class="cab-sv-bigcard pending" @click="openSurveyCard(survey)">
          <div class="cab-sv-bigcard-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 2v4M16 2v4M3 10h18"/><path d="M9 15h6"/></svg>
          </div>
          <div class="cab-sv-bigcard-body">
            <div class="cab-sv-bigcard-title">{{ survey.title }}</div>
            <div class="cab-sv-bigcard-meta">
              <span>{{ survey.questions_count }} {{ surveyQuestionPlural(survey.questions_count) }}</span>
              <span>·</span>
              <span>{{ fmtDateTime(survey.sent_at || survey.created_at) }}</span>
            </div>
          </div>
          <div class="cab-sv-bigcard-arrow">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
          </div>
        </button>
      </div>

      <div v-if="answeredSurveys.length" class="cab-sv-group">
        <div class="cab-sv-group-head">
          <span class="cab-sv-group-title">Отвеченные</span>
          <span class="cab-sv-group-count muted">{{ answeredSurveys.length }}</span>
        </div>
        <button v-for="survey in answeredSurveys" :key="survey.id"
          class="cab-sv-bigcard done" @click="openSurveyCard(survey)">
          <div class="cab-sv-bigcard-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          </div>
          <div class="cab-sv-bigcard-body">
            <div class="cab-sv-bigcard-title">{{ survey.title }}</div>
            <div class="cab-sv-bigcard-meta">
              <span>Ответ отправлен {{ fmtDateTime(survey.submitted_at) }}</span>
            </div>
          </div>
          <div class="cab-sv-bigcard-arrow">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
          </div>
        </button>
      </div>
    </div>

    <!-- Загрузка деталей -->
    <div v-else-if="surveyDetailLoading" class="cab-empty-card"><p>Открываю опрос...</p></div>

    <!-- Wizard -->
    <div v-else-if="surveyMode === 'wizard' && surveyDetail" class="cab-sv-wiz">
      <button class="cab-sv-back" @click="backToList">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        К опросам
      </button>

      <div class="cab-sv-wiz-card">
        <div class="cab-sv-wiz-head">
          <div class="cab-sv-wiz-pretitle">Опрос</div>
          <h2 class="cab-sv-wiz-title">{{ surveyDetail.title }}</h2>
          <p v-if="surveyDetail.description" class="cab-sv-wiz-desc">{{ surveyDetail.description }}</p>
        </div>

        <div class="cab-sv-chain">
          <button v-for="(seg, i) in wizardSegments" :key="i"
            class="cab-sv-chain-seg"
            :class="{ filled: seg.filled, active: i === wizardStep, locked: !seg.reachable }"
            :disabled="!seg.reachable" :title="seg.label" @click="gotoStep(i)" />
        </div>
        <div class="cab-sv-chain-label">{{ wizardStepLabel }}</div>

        <transition :name="wizardSlideName" mode="out-in">
          <div :key="wizardStep" class="cab-sv-step">
            <div v-if="wizardIsQuestion && currentQuestion" class="cab-sv-step-q">
              <h3 class="cab-sv-step-title">{{ currentQuestion.text }}</h3>
              <div v-if="surveyQuestionType(currentQuestion) === 'scale'" class="cab-sv-scale">
                <button v-for="score in 10" :key="score"
                  class="cab-sv-scale-btn"
                  :class="{ selected: Number(surveyAnswers[currentQuestion.id]) === score }"
                  :disabled="surveySubmitting"
                  @click="chooseOption(currentQuestion.id, score)">{{ score }}</button>
              </div>
              <textarea v-else-if="surveyQuestionType(currentQuestion) === 'text'"
                v-model="surveyAnswers[currentQuestion.id]"
                class="cab-sv-textarea" rows="5" placeholder="Ваш ответ..."
                :disabled="surveySubmitting"
                @keydown.ctrl.enter="wizardCanNext && nextStep()" />
              <div v-else-if="surveyQuestionType(currentQuestion) === 'files'" class="cab-sv-files">
                <p class="cab-sv-files-hint">
                  Можно загрузить до {{ FILES_MAX_PER_QUESTION }} файлов, до 25 МБ каждый.
                  Картинки, PDF, документы Word/Excel.
                  <span v-if="!currentQuestion.files_required" class="cab-sv-optional">прикладывать необязательно</span>
                </p>
                <input ref="fileInputRef"
                  type="file"
                  class="cab-sv-files-input"
                  multiple
                  :accept="FILES_ACCEPT"
                  :disabled="surveySubmitting || filesUploadingCount > 0 || (surveyFiles[currentQuestion.id]?.length || 0) >= FILES_MAX_PER_QUESTION"
                  @change="onFilesPicked($event, currentQuestion.id)" />
                <label class="cab-sv-files-btn"
                  :class="{ disabled: surveySubmitting || (surveyFiles[currentQuestion.id]?.length || 0) >= FILES_MAX_PER_QUESTION }"
                  @click="triggerFileInput">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                  Выбрать файлы
                  <span class="cab-sv-files-counter">{{ surveyFiles[currentQuestion.id]?.length || 0 }} / {{ FILES_MAX_PER_QUESTION }}</span>
                </label>
                <div v-if="filesError" class="cab-sv-files-error">{{ filesError }}</div>
                <div v-if="surveyFiles[currentQuestion.id]?.length || filesPending[currentQuestion.id]?.length" class="cab-sv-files-list">
                  <div v-for="f in (surveyFiles[currentQuestion.id] || [])" :key="'f' + f.id" class="cab-sv-file">
                    <span class="cab-sv-file-thumb">
                      <img v-if="isImageMime(f.mime_type)" :src="f.url" :alt="f.file_name" loading="lazy" />
                      <svg v-else width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </span>
                    <div class="cab-sv-file-info">
                      <a :href="f.url" target="_blank" rel="noopener" class="cab-sv-file-name">{{ f.file_name }}</a>
                      <span class="cab-sv-file-size">{{ formatFileSize(f.file_size) }}</span>
                    </div>
                    <button type="button" class="cab-sv-file-del" :disabled="surveySubmitting" @click="removeFile(f.id, currentQuestion.id)" title="Удалить">×</button>
                  </div>
                  <div v-for="p in (filesPending[currentQuestion.id] || [])" :key="'p' + p.uid" class="cab-sv-file pending">
                    <span class="cab-sv-file-thumb">
                      <span class="cab-spin cab-spin-sm"></span>
                    </span>
                    <div class="cab-sv-file-info">
                      <span class="cab-sv-file-name">{{ p.name }}</span>
                      <span class="cab-sv-file-size">{{ p.progress }}%</span>
                    </div>
                  </div>
                </div>
              </div>
              <div v-else class="cab-sv-bigopts">
                <button v-for="option in currentQuestion.options || []" :key="option.id"
                  class="cab-sv-bigopt"
                  :class="{ selected: Number(surveyAnswers[currentQuestion.id]) === Number(option.id) }"
                  :disabled="surveySubmitting"
                  @click="chooseOption(currentQuestion.id, option.id)">
                  <span class="cab-sv-bigopt-mark">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                  </span>
                  <span class="cab-sv-bigopt-text">{{ option.text }}</span>
                </button>
              </div>
            </div>

            <div v-else-if="wizardIsComment" class="cab-sv-step-c">
              <h3 class="cab-sv-step-title">Комментарий <span class="cab-sv-optional">необязательно</span></h3>
              <p class="cab-sv-step-hint">Если хотите, добавьте пояснение к своим ответам.</p>
              <textarea v-model="surveyComment" class="cab-sv-textarea" rows="5"
                placeholder="Ваш комментарий..." :disabled="surveySubmitting"
                @keydown.ctrl.enter="wizardCanSubmit && submitSurveyAnswer()" />
            </div>
          </div>
        </transition>

        <div class="cab-sv-wiz-nav">
          <button class="cab-sv-nav-btn back"
            :disabled="wizardStep === 0 || surveySubmitting" @click="prevStep">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
            Назад
          </button>
          <button v-if="!wizardIsLast" class="cab-sv-nav-btn next"
            :disabled="!wizardCanNext || surveySubmitting" @click="nextStep">
            Далее
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
          </button>
          <button v-else class="cab-sv-nav-btn submit"
            :disabled="!wizardCanSubmit || surveySubmitting" @click="submitSurveyAnswer">
            <span v-if="surveySubmitting" class="cab-spin cab-spin-sm"></span>
            <svg v-else width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
            Отправить ответ
          </button>
        </div>
      </div>
    </div>

    <!-- Readonly -->
    <div v-else-if="surveyMode === 'readonly' && surveyDetail" class="cab-sv-ro">
      <button class="cab-sv-back" @click="backToList">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        К опросам
      </button>
      <div class="cab-sv-ro-card">
        <div class="cab-sv-ro-head">
          <div class="cab-sv-ro-badge">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            Ответ отправлен
          </div>
          <h2 class="cab-sv-ro-title">{{ surveyDetail.title }}</h2>
          <p v-if="surveyDetail.description" class="cab-sv-ro-desc">{{ surveyDetail.description }}</p>
          <div v-if="surveyDetail.submitted_at" class="cab-sv-ro-meta">
            {{ fmtDateTime(surveyDetail.submitted_at) }}
          </div>
        </div>
        <div class="cab-sv-ro-body">
          <div v-for="(q, i) in surveyDetail.questions || []" :key="q.id" class="cab-sv-ro-q">
            <div class="cab-sv-ro-qhead">
              <span class="cab-sv-ro-qnum">{{ i + 1 }}</span>
              <span class="cab-sv-ro-qtext">{{ q.text }}</span>
            </div>
            <div class="cab-sv-ro-opts">
              <div v-if="surveyQuestionType(q) === 'scale'" class="cab-sv-ro-text-answer">
                Оценка: {{ surveyAnswers[q.id] || '—' }}
              </div>
              <div v-else-if="surveyQuestionType(q) === 'text'" class="cab-sv-ro-text-answer">
                {{ surveyAnswers[q.id] || '—' }}
              </div>
              <div v-else-if="surveyQuestionType(q) === 'files'" class="cab-sv-files-list cab-sv-files-readonly">
                <div v-if="!(surveyFiles[q.id]?.length)" class="cab-sv-ro-text-answer">Файлы не приложены</div>
                <div v-for="f in (surveyFiles[q.id] || [])" :key="f.id" class="cab-sv-file readonly">
                  <span class="cab-sv-file-thumb">
                    <img v-if="isImageMime(f.mime_type)" :src="f.url" :alt="f.file_name" loading="lazy" />
                    <svg v-else width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                  </span>
                  <div class="cab-sv-file-info">
                    <a :href="f.url" target="_blank" rel="noopener" class="cab-sv-file-name">{{ f.file_name }}</a>
                    <span class="cab-sv-file-size">{{ formatFileSize(f.file_size) }}</span>
                  </div>
                </div>
              </div>
              <div v-else v-for="opt in q.options || []" :key="opt.id"
                class="cab-sv-ro-opt"
                :class="{ selected: Number(surveyAnswers[q.id]) === Number(opt.id) }">
                <span class="cab-sv-ro-opt-mark">
                  <svg v-if="Number(surveyAnswers[q.id]) === Number(opt.id)" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                </span>
                <span>{{ opt.text }}</span>
              </div>
            </div>
          </div>
          <div v-if="surveyDetail.comment" class="cab-sv-ro-comment">
            <div class="cab-sv-ro-comment-label">Ваш комментарий</div>
            <div class="cab-sv-ro-comment-value">{{ surveyDetail.comment }}</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Success -->
    <transition name="cab-sv-fade">
      <div v-if="surveyMode === 'success'" class="cab-sv-success-screen">
        <div class="cab-sv-success-ring">
          <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="cab-sv-success-check">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
        </div>
        <h2 class="cab-sv-success-title">Спасибо!</h2>
        <p class="cab-sv-success-text">Ваш ответ сохранён</p>
        <button class="btn btn-primary btn-lg cab-sv-success-btn" @click="backToList">К опросам</button>
      </div>
    </transition>
  </section>
</template>

<script setup>
import { ref, reactive, computed } from 'vue';
import { useRestaurantOrderStore } from '@/stores/restaurantOrderStore.js';
import { formatDateTime as fmtDateTime } from '@/lib/roUtils.js';

const props = defineProps({
  items: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
});
const emit = defineEmits(['reload']);

const roStore = useRestaurantOrderStore();

const surveyDetailLoading = ref(false);
const surveySubmitting = ref(false);
const surveyError = ref('');
const surveyDetail = ref(null);
const surveyComment = ref('');
const surveyAnswers = reactive({});
const surveyFiles = reactive({});         // { questionId: [{id, file_name, mime_type, file_size, url}] } — уже загруженные
const filesPending = reactive({});        // { questionId: [{uid, name, progress}] } — в процессе загрузки
const filesError = ref('');
const fileInputRef = ref(null);
const surveyMode = ref('list'); // 'list' | 'wizard' | 'readonly' | 'success'
const wizardStep = ref(0);
const wizardSlideName = ref('cab-sv-slide-forward');

const FILES_MAX_PER_QUESTION = 20;
const FILES_MAX_BYTES = 25 * 1024 * 1024;
const FILES_ACCEPT = 'image/jpeg,image/png,image/heic,image/heif,image/webp,image/gif,application/pdf,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/msword,application/vnd.openxmlformats-officedocument.presentationml.presentation,application/vnd.ms-powerpoint,text/plain,text/csv';

const pendingSurveys = computed(() => props.items.filter(s => !s.already_answered));
const answeredSurveys = computed(() => props.items.filter(s => !!s.already_answered));

const surveyTotalQuestions = computed(() => (surveyDetail.value?.questions || []).length);
const surveyAnsweredCount = computed(() => {
  const qs = surveyDetail.value?.questions || [];
  let n = 0;
  for (const q of qs) { if (surveyQuestionAnswered(q)) n++; }
  return n;
});
const surveyAllAnswered = computed(() => surveyTotalQuestions.value > 0 && surveyAnsweredCount.value === surveyTotalQuestions.value);

const wizardTotalSteps = computed(() => surveyTotalQuestions.value + 1);
const wizardIsQuestion = computed(() => wizardStep.value < surveyTotalQuestions.value);
const wizardIsComment = computed(() => wizardStep.value === surveyTotalQuestions.value);
const wizardIsLast = computed(() => wizardStep.value === wizardTotalSteps.value - 1);
const currentQuestion = computed(() => {
  const qs = surveyDetail.value?.questions || [];
  return wizardIsQuestion.value ? (qs[wizardStep.value] || null) : null;
});
const wizardCanNext = computed(() => {
  if (wizardIsQuestion.value) {
    const q = currentQuestion.value;
    return !!q && surveyQuestionAnswered(q);
  }
  return true;
});
const wizardCanSubmit = computed(() => surveyAllAnswered.value);
const wizardSegments = computed(() => {
  const qs = surveyDetail.value?.questions || [];
  const segs = qs.map((q, i) => ({
    filled: surveyQuestionAnswered(q),
    reachable: true,
    label: `Вопрос ${i + 1}`,
  }));
  segs.push({
    filled: !!surveyComment.value.trim(),
    reachable: surveyAllAnswered.value,
    label: 'Комментарий',
  });
  return segs;
});
const wizardStepLabel = computed(() => {
  if (wizardIsComment.value) return `Комментарий · шаг ${wizardStep.value + 1} из ${wizardTotalSteps.value}`;
  return `Вопрос ${wizardStep.value + 1} из ${surveyTotalQuestions.value}`;
});

function surveyQuestionPlural(n) {
  const abs = Math.abs(Number(n) || 0);
  const mod10 = abs % 10;
  const mod100 = abs % 100;
  if (mod10 === 1 && mod100 !== 11) return 'вопрос';
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return 'вопроса';
  return 'вопросов';
}

function surveyQuestionAnswered(question) {
  if (!question?.id) return false;
  const type = surveyQuestionType(question);
  if (type === 'files') {
    const required = !!question.files_required;
    if (!required) return true; // опциональный — всегда «отвечено»
    const count = (surveyFiles[question.id] || []).length;
    return count > 0;
  }
  const value = surveyAnswers[question.id];
  if (type === 'text') return String(value || '').trim() !== '';
  if (type === 'scale') {
    const n = Number(value);
    return n >= 1 && n <= 10;
  }
  return Number(value) > 0;
}

function surveyQuestionType(question) {
  return ['choice', 'scale', 'text', 'files'].includes(question?.type) ? question.type : 'choice';
}

function resetSurveyDraft(detail = surveyDetail.value) {
  for (const key of Object.keys(surveyAnswers)) delete surveyAnswers[key];
  for (const key of Object.keys(surveyFiles)) delete surveyFiles[key];
  for (const key of Object.keys(filesPending)) delete filesPending[key];
  filesError.value = '';
  if (!detail) { surveyComment.value = ''; return; }
  const answers = detail.answers || {};
  for (const [questionId, answer] of Object.entries(answers)) {
    if (answer && typeof answer === 'object') {
      if (answer.type === 'text') surveyAnswers[questionId] = answer.text_value || '';
      else if (answer.type === 'scale') surveyAnswers[questionId] = Number(answer.numeric_value || 0);
      else surveyAnswers[questionId] = Number(answer.option_id || 0);
    } else {
      surveyAnswers[questionId] = Number(answer);
    }
  }
  // Файлы (черновики или уже отправленные) приходят сгруппированные по question_id.
  const files = detail.files || {};
  for (const [qid, list] of Object.entries(files)) {
    surveyFiles[qid] = Array.isArray(list) ? list.slice() : [];
  }
  surveyComment.value = detail.comment || '';
}

function isImageMime(mime) {
  return typeof mime === 'string' && mime.startsWith('image/');
}

function formatFileSize(bytes) {
  const n = Number(bytes) || 0;
  if (n < 1024) return n + ' Б';
  if (n < 1024 * 1024) return (n / 1024).toFixed(0) + ' КБ';
  return (n / 1024 / 1024).toFixed(1) + ' МБ';
}

const filesUploadingCount = computed(() => {
  let n = 0;
  for (const arr of Object.values(filesPending)) n += (arr?.length || 0);
  return n;
});

function triggerFileInput() {
  if (fileInputRef.value) fileInputRef.value.click();
}

async function onFilesPicked(event, questionId) {
  const input = event.target;
  const picked = Array.from(input.files || []);
  input.value = ''; // позволяем выбрать те же файлы снова при необходимости
  if (!picked.length) return;
  filesError.value = '';

  const already = (surveyFiles[questionId] || []).length;
  const slots = Math.max(0, FILES_MAX_PER_QUESTION - already - (filesPending[questionId]?.length || 0));
  if (picked.length > slots) {
    filesError.value = `Можно добавить ещё ${slots} ${slots === 1 ? 'файл' : 'файлов'}`;
    picked.splice(slots);
    if (!picked.length) return;
  }

  for (const file of picked) {
    if (file.size > FILES_MAX_BYTES) {
      filesError.value = `${file.name}: файл больше 25 МБ`;
      continue;
    }
    const uid = `up_${Date.now()}_${Math.random().toString(16).slice(2)}`;
    filesPending[questionId] = filesPending[questionId] || [];
    const pending = reactive({ uid, name: file.name, progress: 0 });
    filesPending[questionId].push(pending);
    try {
      const meta = await roStore.uploadSurveyFile(
        surveyDetail.value.id,
        questionId,
        file,
        (pct) => { pending.progress = pct; }
      );
      surveyFiles[questionId] = surveyFiles[questionId] || [];
      surveyFiles[questionId].push(meta);
    } catch (e) {
      filesError.value = e.message || `Не удалось загрузить «${file.name}»`;
    } finally {
      filesPending[questionId] = (filesPending[questionId] || []).filter(p => p.uid !== uid);
    }
  }
}

async function removeFile(fileId, questionId) {
  if (!fileId) return;
  filesError.value = '';
  try {
    await roStore.removeSurveyFile(fileId);
    surveyFiles[questionId] = (surveyFiles[questionId] || []).filter(f => f.id !== fileId);
  } catch (e) {
    filesError.value = e.message || 'Не удалось удалить файл';
  }
}

async function openSurvey(surveyId) {
  if (!surveyId) return;
  surveyDetailLoading.value = true;
  surveyError.value = '';
  try {
    surveyDetail.value = await roStore.loadSurvey(surveyId);
    resetSurveyDraft(surveyDetail.value);
  } catch (e) {
    surveyDetail.value = null;
    resetSurveyDraft(null);
    surveyError.value = e.message || 'Не удалось открыть опрос';
  } finally {
    surveyDetailLoading.value = false;
  }
}

function openSurveyCard(survey) {
  if (!survey?.id) return;
  wizardStep.value = 0;
  wizardSlideName.value = 'cab-sv-slide-forward';
  openSurvey(survey.id).then(() => {
    if (!surveyDetail.value) return;
    surveyMode.value = surveyDetail.value.already_answered ? 'readonly' : 'wizard';
  });
}

function backToList() {
  surveyMode.value = 'list';
  wizardStep.value = 0;
  surveyError.value = '';
}

function gotoStep(i) {
  const segs = wizardSegments.value;
  if (i < 0 || i >= segs.length) return;
  if (!segs[i].reachable) return;
  wizardSlideName.value = i > wizardStep.value ? 'cab-sv-slide-forward' : 'cab-sv-slide-back';
  wizardStep.value = i;
}

function nextStep() {
  if (wizardStep.value >= wizardTotalSteps.value - 1) return;
  if (!wizardCanNext.value) return;
  wizardSlideName.value = 'cab-sv-slide-forward';
  wizardStep.value += 1;
}

function prevStep() {
  if (wizardStep.value === 0) return;
  wizardSlideName.value = 'cab-sv-slide-back';
  wizardStep.value -= 1;
}

function chooseOption(questionId, optionId) {
  surveyAnswers[questionId] = Number(optionId);
  setTimeout(() => {
    if (!wizardIsQuestion.value) return;
    const q = currentQuestion.value;
    if (!q || Number(surveyAnswers[q.id]) !== Number(optionId)) return;
    if (wizardStep.value < wizardTotalSteps.value - 1) {
      wizardSlideName.value = 'cab-sv-slide-forward';
      wizardStep.value += 1;
    }
  }, 260);
}

async function submitSurveyAnswer() {
  if (!surveyDetail.value?.id || surveyDetail.value.already_answered) return;
  surveyError.value = '';

  const payload = {};
  for (const question of (surveyDetail.value.questions || [])) {
    if (!surveyQuestionAnswered(question)) {
      surveyError.value = 'Ответьте на все вопросы';
      return;
    }
    const type = surveyQuestionType(question);
    if (type === 'files') {
      // Файлы уже лежат в БД через survey-file-upload; на submit бэк сам привяжет
      // черновики к response_id, в answers их передавать не нужно.
      continue;
    }
    if (type === 'text') {
      payload[question.id] = { question_id: Number(question.id), type, text_value: String(surveyAnswers[question.id] || '').trim() };
    } else if (type === 'scale') {
      payload[question.id] = { question_id: Number(question.id), type, numeric_value: Number(surveyAnswers[question.id]) };
    } else {
      payload[question.id] = Number(surveyAnswers[question.id]);
    }
  }

  surveySubmitting.value = true;
  try {
    await roStore.submitSurvey(surveyDetail.value.id, payload, surveyComment.value);
    surveyMode.value = 'success';
    // Обновим детали (already_answered) и попросим кабинет перезагрузить список
    await openSurvey(surveyDetail.value.id);
    emit('reload');
  } catch (e) {
    surveyError.value = e.message || 'Не удалось сохранить ответ';
  } finally {
    surveySubmitting.value = false;
  }
}
</script>

<style>
.cab-sv-section { max-width: 720px; margin: 0 auto; }
.cab-sv-optional { font-weight: 500; color: #a89a87; margin-left: 6px; font-size: 13px; }
.cab-sv-home { display: flex; flex-direction: column; gap: 24px; }
.cab-sv-group { display: flex; flex-direction: column; gap: 10px; }
.cab-sv-group-head { display: flex; align-items: baseline; gap: 10px; padding: 0 4px; }
.cab-sv-group-title { font-size: 13px; font-weight: 800; color: #502314; text-transform: uppercase; letter-spacing: .06em; }
.cab-sv-group-count { display: inline-flex; align-items: center; justify-content: center; min-width: 22px; height: 22px; padding: 0 7px; border-radius: 999px; background: #FFEACE; color: #B45309; font-size: 11px; font-weight: 800; }
.cab-sv-group-count.muted { background: #EEEAE5; color: #8b7355; }
.cab-sv-bigcard { display: flex; align-items: center; gap: 14px; width: 100%; padding: 18px 18px; text-align: left; background: white; border: 1.5px solid #EDE8E3; border-radius: 18px; cursor: pointer; font: inherit; color: inherit; transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease; }
.cab-sv-bigcard:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(80,35,20,0.08); border-color: #E2CFB0; }
.cab-sv-bigcard.pending { border-left: 4px solid #F59E0B; }
.cab-sv-bigcard.done { border-left: 4px solid #10B981; opacity: .9; }
.cab-sv-bigcard-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #FFF6E7, #FAE4BF); color: #B45309; flex-shrink: 0; }
.cab-sv-bigcard.done .cab-sv-bigcard-icon { background: linear-gradient(135deg, #E6F9EE, #BCEACB); color: #15803D; }
.cab-sv-bigcard-body { flex: 1; min-width: 0; }
.cab-sv-bigcard-title { font-size: 15px; font-weight: 800; color: #502314; line-height: 1.3; word-break: break-word; }
.cab-sv-bigcard-meta { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 6px; font-size: 12px; color: #8b7355; font-weight: 500; }
.cab-sv-bigcard-arrow { flex-shrink: 0; color: #C5B8AA; display: flex; align-items: center; transition: color .15s ease, transform .15s ease; }
.cab-sv-bigcard:hover .cab-sv-bigcard-arrow { color: #502314; transform: translateX(2px); }
.cab-sv-back { display: inline-flex; align-items: center; gap: 6px; background: none; border: none; cursor: pointer; font: inherit; font-size: 13px; font-weight: 700; color: #6b4f3a; padding: 6px 8px; margin: 0 0 12px -8px; border-radius: 8px; transition: background .15s ease, color .15s ease; }
.cab-sv-back:hover { background: #FBF6F1; color: #502314; }
.cab-sv-wiz { max-width: 640px; margin: 0 auto; }
.cab-sv-wiz-card { background: white; border: 1px solid #EDE8E3; border-radius: 22px; padding: 26px 28px 22px; box-shadow: 0 2px 12px rgba(80,35,20,0.04); }
.cab-sv-wiz-head { margin-bottom: 18px; }
.cab-sv-wiz-pretitle { font-size: 11px; font-weight: 800; color: #B45309; text-transform: uppercase; letter-spacing: .1em; margin-bottom: 6px; }
.cab-sv-wiz-title { margin: 0; font-size: 22px; font-weight: 800; color: #502314; line-height: 1.25; letter-spacing: -0.01em; word-break: break-word; }
.cab-sv-wiz-desc { margin: 10px 0 0; color: #6b4f3a; font-size: 14px; line-height: 1.5; white-space: pre-line; }
.cab-sv-chain { display: flex; gap: 6px; margin: 4px 0 6px; padding: 2px 0; }
.cab-sv-chain-seg { flex: 1; height: 8px; border-radius: 4px; border: none; background: #F0E8DE; padding: 0; cursor: pointer; transition: background .2s ease, transform .2s ease; }
.cab-sv-chain-seg.filled { background: #D08B3A; }
.cab-sv-chain-seg.active { background: #502314; transform: scaleY(1.35); }
.cab-sv-chain-seg.locked { cursor: not-allowed; opacity: .6; }
.cab-sv-chain-label { font-size: 11px; font-weight: 700; color: #8b7355; text-transform: uppercase; letter-spacing: .08em; margin: 4px 0 16px; }
.cab-sv-step { min-height: 220px; }
.cab-sv-step-title { margin: 0 0 14px; font-size: 17px; font-weight: 800; color: #502314; line-height: 1.4; word-break: break-word; }
.cab-sv-step-hint { margin: -8px 0 14px; color: #8b7355; font-size: 13px; }
.cab-sv-bigopts { display: flex; flex-direction: column; gap: 10px; }
.cab-sv-bigopt { display: flex; align-items: center; gap: 14px; width: 100%; padding: 16px 18px; text-align: left; background: white; border: 2px solid #EDE8E3; border-radius: 14px; cursor: pointer; font: inherit; transition: transform .12s ease, border-color .15s ease, background .15s ease, box-shadow .15s ease; }
.cab-sv-bigopt:hover:not(:disabled) { border-color: #D7B79A; background: #FFFBF5; transform: translateY(-1px); }
.cab-sv-bigopt:disabled { cursor: default; opacity: .75; }
.cab-sv-bigopt-mark { width: 26px; height: 26px; border-radius: 50%; border: 2px solid #D7C4AA; background: white; display: inline-flex; align-items: center; justify-content: center; color: white; flex-shrink: 0; transition: .18s ease; }
.cab-sv-bigopt-mark svg { opacity: 0; transform: scale(0.5); transition: .18s ease; }
.cab-sv-bigopt-text { flex: 1; font-size: 15px; font-weight: 500; color: #502314; line-height: 1.35; word-break: break-word; }
.cab-sv-bigopt.selected { border-color: #D08B3A; background: linear-gradient(135deg, #FFF8EB 0%, #FBF1E0 100%); box-shadow: 0 4px 14px rgba(208,139,58,0.18); }
.cab-sv-bigopt.selected .cab-sv-bigopt-mark { background: #D08B3A; border-color: #D08B3A; }
.cab-sv-bigopt.selected .cab-sv-bigopt-mark svg { opacity: 1; transform: scale(1); }
.cab-sv-bigopt.selected .cab-sv-bigopt-text { color: #4A2C18; font-weight: 700; }
.cab-sv-scale { display: grid; grid-template-columns: repeat(10, minmax(42px, 1fr)); gap: 8px; }
.cab-sv-scale-btn { min-height: 46px; border: 2px solid #EDE8E3; border-radius: 10px; background: #fff; color: #502314; font: inherit; font-weight: 800; cursor: pointer; }
.cab-sv-scale-btn.selected { border-color: #D08B3A; background: #FFF1D7; color: #4A2C18; }
.cab-sv-textarea { width: 100%; min-height: 120px; padding: 14px 16px; border: 1.5px solid #E0DBD5; border-radius: 12px; font: inherit; font-size: 14px; color: #502314; background: white; resize: vertical; transition: .15s ease; }
.cab-sv-textarea:focus { outline: none; border-color: #D08B3A; box-shadow: 0 0 0 3px rgba(208,139,58,0.14); }

/* Загрузка файлов: кнопка-плашка + список превью. */
.cab-sv-files { display: flex; flex-direction: column; gap: 12px; }
.cab-sv-files-hint { margin: 0; font-size: 13px; color: #6b4f3a; line-height: 1.45; }
.cab-sv-files-hint .cab-sv-optional { display: inline-block; margin-left: 6px; padding: 1px 8px; border-radius: 999px; background: #F0E8DE; color: #8b7355; font-size: 11px; font-weight: 700; }
.cab-sv-files-input { display: none; }
.cab-sv-files-btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 18px; border: 2px dashed #D7B79A; border-radius: 12px; background: #FFFBF5; color: #B45309; font-size: 14px; font-weight: 700; cursor: pointer; transition: .15s ease; align-self: flex-start; }
.cab-sv-files-btn:hover:not(.disabled) { border-color: #D08B3A; background: #FFF6E7; }
.cab-sv-files-btn.disabled { opacity: .5; cursor: not-allowed; }
.cab-sv-files-counter { margin-left: 8px; padding: 2px 8px; border-radius: 999px; background: #FFEACE; color: #B45309; font-size: 12px; font-weight: 800; }
.cab-sv-files-error { padding: 10px 12px; border-radius: 10px; background: #FEF2F2; color: #b91c1c; font-size: 13px; border: 1px solid #FECACA; }
.cab-sv-files-list { display: flex; flex-direction: column; gap: 8px; margin-top: 4px; }
.cab-sv-files-readonly { gap: 6px; }
.cab-sv-file { display: flex; align-items: center; gap: 12px; padding: 10px 12px; background: #FBF6EE; border: 1px solid #ECE2D2; border-radius: 12px; }
.cab-sv-file.pending { opacity: .8; }
.cab-sv-file.readonly { background: #fff; border-color: #EDE8E3; }
.cab-sv-file-thumb { width: 44px; height: 44px; border-radius: 8px; background: #fff; display: flex; align-items: center; justify-content: center; color: #8b7355; flex-shrink: 0; overflow: hidden; border: 1px solid #ECE2D2; }
.cab-sv-file-thumb img { width: 100%; height: 100%; object-fit: cover; }
.cab-sv-file-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.cab-sv-file-name { color: #502314; font-size: 13.5px; font-weight: 700; text-decoration: none; word-break: break-word; }
.cab-sv-file-name:hover { text-decoration: underline; }
.cab-sv-file-size { color: #8b7355; font-size: 11.5px; font-weight: 600; }
.cab-sv-file-del { width: 28px; height: 28px; border-radius: 8px; border: none; background: #FFF; color: #b91c1c; font-size: 18px; line-height: 1; cursor: pointer; flex-shrink: 0; transition: .15s ease; }
.cab-sv-file-del:hover:not(:disabled) { background: #FEF2F2; }
.cab-sv-file-del:disabled { opacity: .4; cursor: not-allowed; }
.cab-sv-wiz-nav { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: 22px; padding-top: 18px; border-top: 1px solid #F5F0EB; }
.cab-sv-nav-btn { display: inline-flex; align-items: center; gap: 6px; padding: 12px 20px; border-radius: 12px; border: none; font: inherit; font-size: 14px; font-weight: 700; cursor: pointer; transition: .15s ease; }
.cab-sv-nav-btn:disabled { opacity: .4; cursor: not-allowed; }
.cab-sv-nav-btn.back { background: #F5F0EB; color: #6b4f3a; }
.cab-sv-nav-btn.back:hover:not(:disabled) { background: #EAE2D8; color: #502314; }
.cab-sv-nav-btn.next { background: #502314; color: white; padding: 12px 22px; }
.cab-sv-nav-btn.next:hover:not(:disabled) { background: #3E1A0D; }
.cab-sv-nav-btn.submit { background: linear-gradient(135deg, #D08B3A, #B87528); color: white; padding: 12px 24px; box-shadow: 0 4px 14px rgba(208,139,58,0.35); }
.cab-sv-nav-btn.submit:hover:not(:disabled) { box-shadow: 0 6px 18px rgba(208,139,58,0.45); transform: translateY(-1px); }
.cab-sv-slide-forward-enter-active,
.cab-sv-slide-forward-leave-active,
.cab-sv-slide-back-enter-active,
.cab-sv-slide-back-leave-active { transition: transform .26s ease, opacity .22s ease; }
.cab-sv-slide-forward-enter-from { opacity: 0; transform: translateX(24px); }
.cab-sv-slide-forward-leave-to   { opacity: 0; transform: translateX(-24px); }
.cab-sv-slide-back-enter-from    { opacity: 0; transform: translateX(-24px); }
.cab-sv-slide-back-leave-to      { opacity: 0; transform: translateX(24px); }
.cab-sv-ro { max-width: 640px; margin: 0 auto; }
.cab-sv-ro-card { background: white; border: 1px solid #EDE8E3; border-radius: 22px; overflow: hidden; box-shadow: 0 2px 12px rgba(80,35,20,0.04); }
.cab-sv-ro-head { padding: 22px 26px 18px; background: linear-gradient(135deg, #F0FDF4 0%, #D6F5E0 100%); border-bottom: 1px solid #C6EACF; }
.cab-sv-ro-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; background: white; color: #15803D; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; box-shadow: 0 1px 3px rgba(16,122,64,0.10); }
.cab-sv-ro-title { margin: 12px 0 0; font-size: 20px; font-weight: 800; color: #15401E; line-height: 1.25; word-break: break-word; }
.cab-sv-ro-desc { margin: 8px 0 0; color: #3a6147; font-size: 14px; line-height: 1.5; white-space: pre-line; }
.cab-sv-ro-meta { margin-top: 10px; font-size: 12px; color: #3a6147; font-weight: 600; }
.cab-sv-ro-body { padding: 18px 26px 22px; }
.cab-sv-ro-q { padding: 14px 0; border-bottom: 1px solid #F5F0EB; }
.cab-sv-ro-q:last-of-type { border-bottom: none; }
.cab-sv-ro-qhead { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 10px; }
.cab-sv-ro-qnum { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 50%; background: #502314; color: white; font-size: 12px; font-weight: 800; flex-shrink: 0; }
.cab-sv-ro-qtext { flex: 1; font-size: 15px; font-weight: 700; color: #502314; line-height: 1.35; padding-top: 2px; }
.cab-sv-ro-opts { display: flex; flex-direction: column; gap: 6px; padding-left: 34px; }
.cab-sv-ro-opt { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 10px; background: #FBF6EE; color: #8b7355; font-size: 13px; line-height: 1.35; }
.cab-sv-ro-opt.selected { background: linear-gradient(135deg, #FFF8EB 0%, #FBF1E0 100%); color: #4A2C18; font-weight: 700; }
.cab-sv-ro-opt-mark { width: 18px; height: 18px; border-radius: 50%; border: 2px solid #D7C4AA; background: white; display: inline-flex; align-items: center; justify-content: center; color: white; flex-shrink: 0; }
.cab-sv-ro-opt.selected .cab-sv-ro-opt-mark { background: #D08B3A; border-color: #D08B3A; }
.cab-sv-ro-text-answer { padding: 10px 12px; border-radius: 10px; background: #FBF6EE; color: #4A2C18; font-size: 14px; line-height: 1.45; white-space: pre-wrap; }
.cab-sv-ro-comment { margin-top: 14px; padding: 14px 16px; background: #FBF6EE; border-radius: 12px; }
.cab-sv-ro-comment-label { font-size: 11px; font-weight: 800; color: #8b7355; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 6px; }
.cab-sv-ro-comment-value { color: #502314; font-size: 14px; line-height: 1.5; white-space: pre-wrap; }
.cab-sv-success-screen { display: flex; flex-direction: column; align-items: center; padding: 50px 20px 40px; text-align: center; }
.cab-sv-success-ring { width: 96px; height: 96px; border-radius: 50%; background: linear-gradient(135deg, #10B981, #059669); display: flex; align-items: center; justify-content: center; color: white; box-shadow: 0 12px 32px rgba(16,185,129,0.35); animation: cabSvPop .45s cubic-bezier(0.34, 1.56, 0.64, 1); }
.cab-sv-success-check { stroke-dasharray: 34; stroke-dashoffset: 34; animation: cabSvDraw .5s ease-out .25s forwards; }
@keyframes cabSvPop { 0% { transform: scale(.4); opacity: 0; } 60% { transform: scale(1.12); opacity: 1; } 100% { transform: scale(1); } }
@keyframes cabSvDraw { to { stroke-dashoffset: 0; } }
.cab-sv-success-title { margin: 22px 0 6px; font-size: 26px; font-weight: 800; color: #502314; }
.cab-sv-success-text { margin: 0 0 22px; color: #6b4f3a; font-size: 15px; }
.cab-sv-success-btn { min-width: 180px; }
.cab-sv-fade-enter-active, .cab-sv-fade-leave-active { transition: opacity .25s ease; }
.cab-sv-fade-enter-from, .cab-sv-fade-leave-to { opacity: 0; }
</style>
