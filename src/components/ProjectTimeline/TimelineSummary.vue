<template>
	<div class="timeline-summary" :class="{ 'is-ready': summary.processCompleted.status === 'complete' }">
		<div class="timeline-summary__toolbar">
			<NcButton
				type="tertiary"
				size="small"
				:disabled="loading || saving"
				@click="load">
				{{ loading ? 'Refreshing…' : 'Refresh timeline' }}
			</NcButton>
		</div>
		<div v-if="loading" class="timeline-summary__loading">
			<NcLoadingIcon :size="20" />
			<span>Synchronizing readiness state…</span>
		</div>

		<div v-else class="timeline-summary__flow">
			<!-- Step 1: Request -->
			<div class="step step--active">
				<div class="step__icon step__icon--start">
					<Plus :size="16" />
				</div>
				<div class="step__content">
					<div class="step__label">Request Date</div>
					<div class="step__value">{{ formatDate(summary.requestDate) }}</div>
				</div>
				<div class="step__connector step__connector--active"></div>
			</div>

			<!-- Step 2: Coordination Pending -->
			<div class="step" :class="{ 'step--active': !!coordinationPendingText }">
				<div class="step__icon" :class="coordinationIconClass">
					<Clock :size="16" />
				</div>
				<div class="step__content">
					<div class="step__label">Coordination Pending</div>
					<div class="step__value">{{ coordinationPendingText || '—' }}</div>
					<div v-if="coordinationPendingRangeText" class="step__meta">
						{{ coordinationPendingRangeText }}
					</div>
				</div>
				<div class="step__connector" :class="{ 'step__connector--active': summary.processCompleted.status === 'complete', 'step__connector--dashed': summary.processCompleted.status !== 'complete' }"></div>
			</div>

			<!-- Step 3: Readiness (Temporarily hidden from UI) -->
			<div v-if="showProcessCompletedStep" class="step" :class="{ 'step--active': summary.processCompleted.status === 'complete', 'step--pending': summary.processCompleted.status === 'incomplete' }">
				<div class="step__icon" :class="statusIconClass">
					<Check v-if="summary.processCompleted.status === 'complete'" :size="16" />
					<Clock v-else-if="summary.processCompleted.status === 'incomplete'" :size="16" />
					<AlertCircle v-else :size="16" />
				</div>
				<div class="step__content">
					<div class="step__label">Process Completed</div>
					<div class="step__value-group">
						<span class="step__value step__status-pill" :class="statusClass">{{ statusText }}</span>
						<span v-if="hasProgress" class="step__badge">{{ summary.processCompleted.doneCount }}/{{ summary.processCompleted.totalRequired }}</span>
					</div>
					<div v-if="summary.processCompleted.date" class="step__meta">
						Completed on {{ formatDate(summary.processCompleted.date) }}
					</div>
					<div v-if="summary.processCompleted.status === 'incomplete'" class="step__subtext">
						Complete checklist items to unlock execution planning.
					</div>
					<div v-if="showMissingTitles" class="step__hint">
						<span
							class="step__missing-chip"
							:title="'Missing: ' + summary.processCompleted.missingTitles.join(', ')">
							Missing {{ summary.processCompleted.missingTitles.length }}
						</span>
					</div>
				</div>
				<div class="step__connector" :class="{ 'step__connector--active': summary.processCompleted.status === 'complete', 'step__connector--dashed': summary.processCompleted.status !== 'complete' }"></div>
			</div>

			<!-- Step 4: Preparation -->
			<div class="step" :class="{ 'step--active': summary.processCompleted.status === 'complete' }">
				<div class="step__icon step__icon--prep">
					<CalendarEdit :size="16" />
				</div>
				<div class="step__content">
					<div class="step__label">Required Preparation Time</div>
					<div class="prep-input">
						<label class="prep-input__label" :for="`prep-weeks-${projectId}`">Weeks</label>
						<input
							:id="`prep-weeks-${projectId}`"
							v-model="prepWeeksInput"
							class="prep-input__field"
							type="number"
							inputmode="numeric"
							min="0"
							step="1"
							:disabled="!canEdit || saving"
							@blur="normalizePrepInput"
							@keydown.enter.prevent="savePrepWeeks">
						<transition name="fade">
							<NcButton
								v-if="canEdit && isPrepWeeksDirty"
								type="primary"
								size="small"
								class="prep-save-btn"
								:disabled="saving"
								@click="savePrepWeeks">
								{{ saving ? '...' : 'Save' }}
							</NcButton>
						</transition>
					</div>
				</div>
				<div class="step__connector" :class="{ 'step__connector--active': summary.processCompleted.status === 'complete' && summary.earliestExecutionDate, 'step__connector--dashed': !summary.earliestExecutionDate }"></div>
			</div>

			<!-- Step 5: Execution -->
			<div class="step step--target" :class="{ 'step--active': summary.earliestExecutionDate }">
				<div class="step__icon step__icon--end">
					<FlagVariant :size="16" />
				</div>
				<div class="step__content">
					<div class="step__label">Earliest Execution Date</div>
					<div class="step__value step__value--target">{{ summary.earliestExecutionDate ? formatDate(summary.earliestExecutionDate) : 'TBD' }}</div>
					<div v-if="!summary.earliestExecutionDate && hypotheticalDateInfo" class="step__estimate">
						<span class="step__estimate-label">Completed today</span>
						<span class="step__estimate-sep">→</span>
						<span class="step__estimate-date">{{ hypotheticalDateInfo.formatted }}</span>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Clock from 'vue-material-design-icons/ClockOutline.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircleOutline.vue'
import CalendarEdit from 'vue-material-design-icons/CalendarEdit.vue'
import FlagVariant from 'vue-material-design-icons/FlagVariant.vue'

export default {
	name: 'TimelineSummary',
	components: {
		NcButton,
		NcLoadingIcon,
		Plus,
		Check,
		Clock,
		AlertCircle,
		CalendarEdit,
		FlagVariant,
	},
	props: {
		projectId: {
			type: Number,
			required: true,
		},
		canEdit: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			showProcessCompletedStep: false, // Temporarily hidden from UI
			loading: true,
			saving: false,
			summary: {
				requestDate: null,
				requiredPreparationWeeks: 0,
				processCompleted: { status: 'missing_cards', date: null, doneCount: 0, totalRequired: 0, missingTitles: [] },
				earliestExecutionDate: null,
				coordinationPendingPeriod: { weeks: 0, fromDate: null, toDate: null, isFinal: false },
			},
			prepWeeksInput: '0',
		}
	},
	computed: {
		hasProgress() {
			return (this.summary.processCompleted?.totalRequired || 0) > 0
		},
		showMissingTitles() {
			return Array.isArray(this.summary.processCompleted?.missingTitles) && this.summary.processCompleted.missingTitles.length > 0
		},
		statusText() {
			const status = this.summary.processCompleted?.status
			if (status === 'complete') return 'Ready'
			if (status === 'incomplete') return 'Pending'
			if (status === 'missing_cards') return 'Initial'
			if (status === 'not_configured') return 'N/A'
			if (status === 'error') return 'Error'
			return '—'
		},
		statusClass() {
			const status = this.summary.processCompleted?.status
			if (status === 'complete') return 'is-traffic-green'
			if (status === 'incomplete') return 'is-traffic-orange'
			return 'is-traffic-red'
		},
		statusIconClass() {
			const status = this.summary.processCompleted?.status
			if (status === 'complete') return 'step__icon--success'
			if (status === 'incomplete') return 'step__icon--warning'
			return 'step__icon--error'
		},
		isPrepWeeksDirty() {
			const current = Number(this.summary.requiredPreparationWeeks || 0)
			const next = Number(this.prepWeeksInput || 0)
			return Number.isFinite(next) && next !== current
		},
		coordinationPendingText() {
			const period = this.summary?.coordinationPendingPeriod || {}
			let weeks = Number(period.weeks)

			if (!Number.isFinite(weeks)) {
				const legacyDays = Number(period.days)
				if (Number.isFinite(legacyDays) && legacyDays >= 0) {
					weeks = legacyDays / 7
				}
			}

			if (!Number.isFinite(weeks) || weeks < 0) return ''

			const normalized = Math.round(weeks * 10) / 10
			const display = Number.isInteger(normalized) ? String(normalized) : normalized.toFixed(1)
			return `${display} ${normalized === 1 ? 'week' : 'weeks'}`
		},
		coordinationPendingRangeText() {
			const fromDate = this.summary?.coordinationPendingPeriod?.fromDate
			const toDate = this.summary?.coordinationPendingPeriod?.toDate
			if (!fromDate || !toDate) return ''
			return `${this.formatDate(fromDate)} → ${this.formatDate(toDate)}`
		},
		coordinationIconClass() {
			return this.summary?.coordinationPendingPeriod?.isFinal ? 'step__icon--success' : 'step__icon--warning'
		},
		hypotheticalDateInfo() {
			const allowed = ['incomplete', 'missing_cards']
			const status = this.summary.processCompleted?.status
			if (!allowed.includes(status)) return null

			const prepWeeks = Number(this.summary.requiredPreparationWeeks ?? 0)
			if (!Number.isFinite(prepWeeks)) return null

			const today = new Date()
			today.setHours(0, 0, 0, 0)
			const projected = new Date(today)
			projected.setDate(projected.getDate() + prepWeeks * 7)

			const day = String(projected.getDate()).padStart(2, '0')
			const month = String(projected.getMonth() + 1).padStart(2, '0')
			const year = projected.getFullYear()

			return { formatted: `${day}/${month}/${year}` }
		},
	},
	watch: {
		projectId: {
			handler(val) {
				if (val) this.load()
			},
			immediate: true,
		},
	},
	methods: {
		formatDate(dateStr) {
			if (!dateStr) return '—'
			const date = new Date(`${dateStr}T00:00:00`)
			const day = date.getDate().toString().padStart(2, '0')
			const month = (date.getMonth() + 1).toString().padStart(2, '0')
			const year = date.getFullYear()
			return `${day}/${month}/${year}`
		},
		async load() {
			this.loading = true
			try {
				const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${this.projectId}/timeline/summary`)
				const response = await axios.get(url)
				this.summary = response.data || this.summary
				this.prepWeeksInput = String(this.summary.requiredPreparationWeeks ?? 0)
			} catch (e) {
				console.error('Failed to load planning summary', e)
			} finally {
				this.loading = false
			}
		},
		async savePrepWeeks() {
			this.normalizePrepInput()
			const next = Math.max(0, Number(this.prepWeeksInput || 0))
			if (!Number.isFinite(next)) return
			this.saving = true
			try {
				const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${this.projectId}`)
				await axios.put(url, { required_preparation_weeks: next })
				await this.load()
			} catch (e) {
				console.error('Failed to save required preparation weeks', e)
			} finally {
				this.saving = false
			}
		},
		normalizePrepInput() {
			const value = Number(this.prepWeeksInput)
			this.prepWeeksInput = String(Number.isFinite(value) ? Math.max(0, Math.round(value)) : 0)
		},
	},
}
</script>

<style scoped>
.timeline-summary {
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: 12px;
	padding: 16px 20px;
	margin-bottom: 20px;
	transition: background 0.3s ease, border-color 0.3s ease;
}

.timeline-summary.is-ready {
	background: var(--color-main-background);
	border-color: var(--color-success);
	box-shadow: 0 0 0 1px var(--color-success);
}

.timeline-summary__loading {
	display: flex;
	gap: 12px;
	align-items: center;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	justify-content: center;
	padding: 4px 0;
}

.timeline-summary__toolbar {
	display: flex;
	justify-content: flex-end;
	margin-bottom: 10px;
}

.timeline-summary__flow {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.step {
	flex: 1;
	display: flex;
	align-items: center;
	gap: 12px;
	position: relative;
}

.step--target {
	flex: 0 0 auto;
}

.step__icon {
	width: 32px;
	height: 32px;
	border-radius: 50%;
	background: var(--color-background-darker);
	color: var(--color-text-maxcontrast);
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	z-index: 2;
	border: 2px solid var(--color-main-background);
	transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.step--active .step__icon {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	transform: scale(1.1);
}

.step__icon--start { background: var(--color-main-text) !important; color: var(--color-main-background) !important; }
.step__icon--success { background: var(--color-success) !important; color: var(--color-success-text) !important; }
.step__icon--warning { background: var(--color-warning) !important; color: var(--color-warning-text) !important; }
.step__icon--error { background: var(--color-error) !important; color: var(--color-error-text) !important; }
.step__icon--prep { background: var(--color-background-hover); color: var(--color-main-text); border-color: var(--color-border); }
.step--active .step__icon--prep { background: var(--color-primary-light); color: var(--color-primary-element); border-color: var(--color-primary-element); }
.step__icon--end { border-style: dashed; }
.step--active .step__icon--end { background: var(--color-main-text); color: var(--color-main-background); border-style: solid; }

.step__content {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.step__label {
	font-size: 10px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	color: var(--color-text-maxcontrast);
	line-height: 1;
	margin-bottom: 4px;
}

.step__value {
	font-size: 13px;
	font-weight: 600;
	color: var(--color-main-text);
	white-space: nowrap;
}

.step__value--target {
	color: var(--color-primary-element);
	font-weight: 700;
}

.step__value-group {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-wrap: wrap;
}

.step__badge {
	font-size: 10px;
	font-weight: 800;
	padding: 1px 6px;
	border-radius: 999px;
	background: #fff5d6;
	border: 1px solid #e6bf57;
	color: #77540a;
}

.step__status-pill {
	padding: 2px 8px;
	border-radius: 999px;
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	line-height: 1.3;
}

.step__subtext {
	margin-top: 4px;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	max-width: 260px;
	line-height: 1.35;
}

.step__meta {
	margin-top: 4px;
	font-size: 11px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.step__hint {
	position: static;
	margin-top: 6px;
}

.step__missing-chip {
	display: inline-flex;
	align-items: center;
	font-size: 10px;
	font-weight: 700;
	padding: 2px 7px;
	border-radius: 999px;
	background: #ffe4e4;
	border: 1px solid #e89a9a;
	color: #8f2222;
	cursor: help;
}

.step__missing-chip::before {
	content: '';
	width: 6px;
	height: 6px;
	border-radius: 50%;
	background: currentColor;
	margin-right: 5px;
}

/* Estimate hint for Earliest Execution Date */
.step__estimate {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	margin-top: 6px;
	height: 22px;
}

.step__estimate-label {
	font-size: 11px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	line-height: 1;
}

.step__estimate-sep {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	line-height: 1;
}

.step__estimate-date {
	font-size: 11px;
	font-weight: 700;
	color: var(--color-primary-element);
	background: var(--color-primary-element-light);
	padding: 2px 7px;
	border-radius: 5px;
	border: 1px solid var(--color-primary-element);
	line-height: 1;
	white-space: nowrap;
	letter-spacing: 0.02em;
}

.step__connector {
	flex: 1;
	height: 2px;
	background: var(--color-border);
	margin: 0 12px;
	min-width: 20px;
}

.step__connector--active { background: var(--color-primary-element); }
.step__connector--dashed { background: transparent; border-top: 2px dashed var(--color-border); }

/* Prep Input */
.prep-input {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.prep-input__label {
	font-size: 11px;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
}

.prep-input__field {
	width: 74px;
	height: 30px;
	padding: 4px 8px;
	font-size: 13px;
	font-weight: 700;
	text-align: right;
	border-radius: 7px;
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.prep-input__field:focus {
	outline: none;
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 2px rgba(0, 130, 201, 0.2);
}

.prep-save-btn {
	margin-left: 2px;
}

.fade-enter-active, .fade-leave-active { transition: opacity 0.2s; }
.fade-enter, .fade-leave-to { opacity: 0; }

.is-traffic-green { color: var(--color-success) !important; }
.is-traffic-green.step__status-pill {
	background: #e7f7ef;
	border-color: #87caa5;
	color: #0d6a37 !important;
}

.is-traffic-orange { color: #946200 !important; }
.is-traffic-orange.step__status-pill {
	background: #fff5d6;
	border-color: #e4bb4f;
	color: #805300 !important;
}

.is-traffic-red { color: #9b1f1f !important; }
.is-traffic-red.step__status-pill {
	background: #ffeaea;
	border-color: #df8a8a;
	color: #9b1f1f !important;
}

@media (max-width: 850px) {
	.timeline-summary__flow { flex-direction: column; align-items: flex-start; gap: 16px; }
	.step { width: 100%; }
	.step__connector {
		position: absolute;
		left: 15px;
		top: 32px;
		width: 2px;
		height: 16px;
		margin: 0;
	}
	.step__content { margin-left: 0; }
}
</style>
