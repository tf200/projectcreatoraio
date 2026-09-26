<template>
	<section class="pc-view iz-app" aria-label="Intake form">
		<header class="pc-view__head">
			<div class="pc-view__heading">
				<h2 class="pc-view__title">
					Intake form
				</h2>
				<p class="pc-view__lede">
					Your answers decide which cards appear on the task board.
				</p>
			</div>
			<div v-if="!loading && !error && questions.length" class="pc-view__tools">
				<div class="pc-intake-progress">
					<div class="pc-intake-progress__label">
						<span>Answered</span>
						<strong>{{ answeredCount }} of {{ questions.length }}</strong>
					</div>
					<div class="iz-meter iz-meter--thin"
						role="progressbar"
						aria-label="Questions answered"
						aria-valuemin="0"
						:aria-valuemax="questions.length"
						:aria-valuenow="answeredCount">
						<div class="iz-meter__fill" :style="{ width: answeredPercent + '%' }" />
					</div>
				</div>
				<span v-if="dirtyCount" class="pc-view__state">
					<span class="iz-dot pc-view__state-dot" />
					{{ dirtyCount === 1 ? '1 unsaved change' : dirtyCount + ' unsaved changes' }}
				</span>
				<button v-if="canEdit"
					type="button"
					class="iz-btn iz-btn--primary"
					:disabled="saving || !isDirty"
					@click="save">
					<NcLoadingIcon v-if="saving" :size="16" />
					<ContentSave v-else :size="16" />
					{{ saving ? 'Saving…' : 'Save configuration' }}
				</button>
			</div>
		</header>

		<div v-if="loading" class="iz-empty" role="status">
			Loading settings…
		</div>
		<div v-else-if="error" class="iz-empty pc-view__failure" role="alert">
			<span>{{ error }}</span>
			<button type="button" class="iz-btn" @click="load">
				Try again
			</button>
		</div>
		<template v-else>
			<p v-if="!canEdit" class="pc-view__notice">
				<InformationOutline :size="16" />
				Read-only: only project managers can update these settings.
			</p>
			<p v-if="successMessage" class="pc-view__notice pc-view__notice--ok" role="status">
				<CheckCircle :size="16" />
				{{ successMessage }}
			</p>
			<p v-if="saveError" class="pc-view__notice pc-view__notice--error" role="alert">
				<AlertCircle :size="16" />
				{{ saveError }}
			</p>
			<div v-if="!questions.length" class="iz-empty">
				No intake questions are configured for this project.
			</div>
			<ul v-else class="iz-panel iz-panel--list pc-intake-list">
				<li v-for="question in questions" :key="question.field" class="iz-row pc-intake-row">
					<div class="pc-intake-row__text">
						<span class="iz-label pc-intake-row__category">{{ question.category }}</span>
						<span :id="'pc-intake-' + question.field" class="pc-intake-row__question">{{ question.question }}</span>
						<span v-if="isFieldDirty(question.field)" class="iz-dot pc-intake-row__dirty" title="Changed, not saved">
							<span class="pc-sr-only">Changed, not saved</span>
						</span>
						<span v-else-if="answers[question.field] === null" class="iz-pill iz-pill--warning pc-intake-row__pill">Not answered</span>
					</div>
					<div class="iz-segment" role="radiogroup" :aria-labelledby="'pc-intake-' + question.field">
						<label v-for="option in question.options"
							:key="option.value"
							class="iz-btn"
							:class="{ 'iz-btn--active': answers[question.field] === Number(option.value) }">
							<input type="radio"
								:name="'pc-intake-' + question.field"
								:value="option.value"
								:checked="answers[question.field] === Number(option.value)"
								:disabled="!canEdit || saving"
								@change="setAnswer(question.field, option.value)">
							{{ option.label }}
						</label>
					</div>
				</li>
			</ul>
		</template>
	</section>
</template>

<script>
import ProjectCardVisibilityTab from '../components/ProjectCardVisibilityTab.vue'

// The questionnaire, its answers, dirty tracking and the save cycle all come
// from the component the current interface uses; only the markup is new, so
// that component and its styles stay exactly as they are.
export default {
	name: 'NewIntake',
	extends: ProjectCardVisibilityTab,
	computed: {
		answeredCount() {
			return this.questions.filter(question => this.answers[question.field] !== null && this.answers[question.field] !== undefined).length
		},
		answeredPercent() {
			return this.questions.length ? Math.round(this.answeredCount / this.questions.length * 100) : 0
		},
		dirtyCount() {
			return this.questions.filter(question => this.isFieldDirty(question.field)).length
		},
	},
}
</script>

<style scoped>
.pc-intake-list { margin: 0; list-style: none; }
.pc-intake-row { justify-content: space-between; gap: var(--iz-gap); }
.pc-intake-row:last-child { border-bottom: 0; }
.pc-intake-row__text { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 8px; min-width: 0; }
.pc-intake-row__category { flex-basis: 100%; margin: 0; }
.pc-intake-row__question { font-size: var(--iz-fs-md); font-weight: 600; color: var(--iz-text); }
.pc-intake-row__dirty { color: var(--iz-accent); }
/* iz-pill capitalises every word; this is a sentence. */
.pc-intake-row__pill { text-transform: none; }
.pc-intake-row .iz-segment { flex-shrink: 0; flex-wrap: wrap; }
.pc-intake-row .iz-segment input:disabled { cursor: default; }
.pc-intake-progress { display: flex; flex-direction: column; gap: 6px; width: 170px; }
.pc-intake-progress__label { display: flex; justify-content: space-between; font-size: var(--iz-fs-xs); color: var(--iz-text-secondary); }
.pc-intake-progress__label strong { color: var(--iz-text); font-weight: 600; }

@media (max-width: 800px) {
	.pc-intake-row { flex-direction: column; align-items: stretch; }
	.pc-intake-row .iz-segment { align-self: flex-start; }
}
</style>
