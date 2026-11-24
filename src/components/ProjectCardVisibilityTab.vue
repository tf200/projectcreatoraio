<template>
	<section class="project-form">
		<div v-if="loading" class="project-form__loading">
			<NcLoadingIcon :size="24" />
			<span>{{ t('Loading settings...') }}</span>
		</div>
		<div v-else-if="error" class="project-form__error-banner">
			<AlertCircle :size="20" />
			{{ error }}
		</div>
		<div v-else class="project-form__content">
			<div v-if="!canEdit" class="project-form__info-banner">
				<InformationOutline :size="20" />
				{{ t('Read-only: only project managers can update these settings.') }}
			</div>

			<div class="project-form__questions">
				<article
					v-for="question in questions"
					:key="question.field"
					class="form-card"
					:class="{ 'form-card--dirty': isFieldDirty(question.field) }">
					<div class="form-card__info">
						<span class="form-card__category">{{ question.category }}</span>
						<h4 class="form-card__question">
							{{ question.question }}
						</h4>
					</div>

					<div class="form-card__selection">
						<div class="option-list" role="radiogroup" :aria-label="question.question">
							<label
								v-for="option in question.options"
								:key="option.value"
								class="option-item"
								:class="{ 'option-item--active': answers[question.field] === Number(option.value) }">
								<input
									class="option-item__input"
									type="radio"
									:name="question.field"
									:value="option.value"
									:checked="answers[question.field] === Number(option.value)"
									:disabled="!canEdit || saving"
									@change="setAnswer(question.field, option.value)">
								<span class="option-item__text">{{ option.label }}</span>
							</label>
						</div>
					</div>
				</article>
			</div>

			<footer class="project-form__footer">
				<div class="project-form__summary">
					<div v-if="isDirty" class="project-form__dirty-indicator">
						{{ t('You have unsaved changes') }}
					</div>
				</div>

				<div class="project-form__actions">
					<p v-if="successMessage" class="project-form__success">
						<CheckCircle :size="16" />
						{{ successMessage }}
					</p>
					<p v-if="saveError" class="project-form__error">
						<AlertCircle :size="16" />
						{{ saveError }}
					</p>

					<NcButton
						type="primary"
						:disabled="!canEdit || saving || !isDirty"
						@click="save">
						<template #icon>
							<ContentSave v-if="!saving" :size="18" />
							<NcLoadingIcon v-else :size="18" />
						</template>
						{{ saving ? t('Saving...') : t('Save configuration') }}
					</NcButton>
				</div>
			</footer>
		</div>
	</section>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import { ProjectsService } from '../Services/projects.js'
import { t } from '../new/l10n.mjs'

const projectsService = ProjectsService.getInstance()

export default {
	name: 'ProjectCardVisibilityTab',
	components: {
		NcButton,
		NcLoadingIcon,
		AlertCircle,
		CheckCircle,
		InformationOutline,
		ContentSave,
	},
	props: {
		projectId: {
			type: [String, Number],
			required: true,
		},
		canEdit: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			loading: false,
			saving: false,
			error: '',
			saveError: '',
			successMessage: '',
			questions: [],
			answers: {
				cv_object_ownership: null,
				cv_trace_ownership: null,
				cv_building_type: null,
				cv_avp_location: null,
			},
			initialAnswers: {
				cv_object_ownership: null,
				cv_trace_ownership: null,
				cv_building_type: null,
				cv_avp_location: null,
			},
		}
	},
	computed: {
		isDirty() {
			return JSON.stringify(this.answers) !== JSON.stringify(this.initialAnswers)
		},
	},
	watch: {
		projectId: {
			immediate: true,
			handler() {
				this.load()
			},
		},
	},
	methods: {
		t,
		isFieldDirty(field) {
			return this.answers[field] !== this.initialAnswers[field]
		},
		setAnswer(field, value) {
			if (!this.canEdit || this.saving) return
			this.saveError = ''
			this.successMessage = ''
			const normalizedValue = value === null || value === undefined || value === '' ? null : Number(value)
			this.answers = {
				...this.answers,
				[field]: normalizedValue,
			}
		},
		normalizeAnswer(value) {
			if (value === null || value === undefined || value === '') {
				return null
			}
			const numeric = Number(value)
			if (!Number.isFinite(numeric)) {
				return null
			}
			if (!Number.isInteger(numeric)) {
				return null
			}
			return numeric
		},
		normalizeAnswers(raw) {
			return {
				cv_object_ownership: this.normalizeAnswer(raw?.cv_object_ownership),
				cv_trace_ownership: this.normalizeAnswer(raw?.cv_trace_ownership),
				cv_building_type: this.normalizeAnswer(raw?.cv_building_type),
				cv_avp_location: this.normalizeAnswer(raw?.cv_avp_location),
			}
		},
		async load() {
			const id = Number(this.projectId)
			if (!Number.isFinite(id) || id <= 0) {
				return
			}

			this.loading = true
			this.error = ''
			this.saveError = ''
			this.successMessage = ''

			try {
				const payload = await projectsService.getCardVisibility(id)
				this.questions = Array.isArray(payload?.questions) ? payload.questions : []
				const normalizedAnswers = this.normalizeAnswers(payload?.answers)
				this.answers = normalizedAnswers
				this.initialAnswers = { ...normalizedAnswers }
			} catch (e) {
				this.error = e?.response?.data?.message || t('Could not load configuration settings.')
			} finally {
				this.loading = false
			}
		},
		async save() {
			const id = Number(this.projectId)
			if (!Number.isFinite(id) || id <= 0 || !this.canEdit || this.saving) {
				return
			}

			this.saving = true
			this.saveError = ''
			this.successMessage = ''

			try {
				const payload = await projectsService.updateCardVisibility(id, this.answers)
				const normalizedAnswers = this.normalizeAnswers(payload?.answers)
				this.answers = normalizedAnswers
				this.initialAnswers = { ...normalizedAnswers }

				const updatedCount = Number(payload?.deck_cards_updated || 0)
				this.successMessage = t('Configuration saved. {n} deck card(s) updated.', { n: updatedCount })
			} catch (e) {
				this.saveError = e?.response?.data?.message || t('Could not save configuration settings.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.project-form {
	max-width: 900px;
	margin: 32px auto;
	padding: 48px;
	display: flex;
	flex-direction: column;
	gap: 24px;
	background: var(--color-main-background);
	border-radius: 24px;
	border: 1px solid var(--color-border);
	box-shadow: 0 20px 40px rgba(0, 0, 0, 0.04);
}

.project-form__loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 16px;
	padding: 80px;
	color: var(--color-text-maxcontrast);
}

.project-form__error-banner {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 20px;
	background-color: var(--color-error-light);
	color: var(--color-error);
	border-radius: 16px;
	border: 1px solid var(--color-error);
	font-weight: 600;
}

.project-form__info-banner {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 16px;
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	border-radius: 12px;
	margin-bottom: 16px;
	font-size: 14px;
	font-weight: 500;
}

.project-form__questions {
	display: flex;
	flex-direction: column;
	gap: 24px;
}

/* Card Style */
.form-card {
	display: grid;
	grid-template-columns: 1fr;
	grid-template-areas:
		"info"
		"selection";
	gap: 24px;
	padding: 32px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: 20px;
	transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.form-card:hover {
	border-color: var(--color-primary-element);
	background: var(--color-main-background);
	box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
	transform: translateY(-2px);
}

.form-card--dirty {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element-light);
}

.form-card__info {
	grid-area: info;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.form-card__category {
	font-size: 11px;
	font-weight: 800;
	text-transform: uppercase;
	letter-spacing: 0.1em;
	color: var(--color-primary-element);
}

.form-card__question {
	margin: 0;
	font-size: 18px;
	font-weight: 700;
	line-height: 1.4;
	color: var(--color-main-text);
}

.form-card__hint {
	margin: 0;
	font-size: 14px;
	line-height: 1.5;
	color: var(--color-text-maxcontrast);
	opacity: 0.85;
}

.form-card__selection {
	grid-area: selection;
	align-self: start;
}

/* Options list (single-choice) */
.option-list {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.option-item {
	display: grid;
	grid-template-columns: 18px 1fr;
	gap: 12px;
	align-items: start;
	padding: 12px 14px;
	border-radius: 14px;
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	cursor: pointer;
	transition: all 0.2s ease;
}

.option-item:hover:not(.option-item--active) {
	border-color: var(--color-primary-element);
	background: var(--color-background-hover);
}

.option-item--active {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element-light);
}

.option-item__input {
	margin: 2px 0 0;
}

.option-item__text {
	font-size: 14px;
	line-height: 1.4;
	color: var(--color-main-text);
	font-weight: 600;
}

/* Footer & Actions */
.project-form__footer {
	margin-top: 16px;
	padding: 32px;
	background: var(--color-background-dark);
	border-radius: 20px;
	border: 1px solid var(--color-border);
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 24px;
	flex-wrap: wrap;
}

.project-form__summary {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.project-form__dirty-indicator {
	font-size: 13px;
	color: var(--color-primary-element);
	font-weight: 700;
	display: flex;
	align-items: center;
	gap: 6px;
}

.project-form__dirty-indicator::before {
	content: '';
	display: block;
	width: 8px;
	height: 8px;
	background: var(--color-primary-element);
	border-radius: 50%;
}

.project-form__actions {
	display: flex;
	align-items: center;
	gap: 20px;
	flex-wrap: wrap;
}

.project-form__success {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-success);
	font-size: 15px;
	font-weight: 700;
	margin: 0;
}

.project-form__error {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-error);
	font-size: 15px;
	font-weight: 700;
	margin: 0;
}

@media (max-width: 768px) {
	.project-form {
		margin: 0;
		padding: 24px;
		border-radius: 0;
		border: none;
		max-width: 100%;
	}

	.form-card {
		grid-template-columns: 1fr;
		grid-template-areas:
			"info"
			"selection";
		padding: 24px;
	}

	.form-card__selection {
		justify-self: start;
		min-width: 0;
	}

	.project-form__footer {
		flex-direction: column;
		align-items: stretch;
		padding: 24px;
	}

	.project-form__actions {
		justify-content: flex-end;
	}
}
</style>
