<template>
	<div class="timeline-kpi-bar">
		<!-- Top Status Badges Row -->
		<div class="timeline-kpi-bar__status-row">
			<div class="timeline-kpi-bar__title-tag">
				<span class="tag-label">PROJECT TIMELINE</span>
			</div>

			<div class="timeline-kpi-bar__badges">
				<div class="status-pill" :class="statusPillClass">
					<span class="status-dot"></span>
					<span class="status-text">PLANNING STATUS: {{ planningStatusLabel }}</span>
				</div>

				<div v-if="floatLabel" class="float-pill" :class="floatPillClass">
					<span class="float-text">OVERALL FLOAT: {{ floatLabel }}</span>
				</div>
			</div>
		</div>

		<!-- KPI Metric Cards Row -->
		<div class="timeline-kpi-bar__cards">
			<!-- 1. Request Date -->
			<div class="kpi-card">
				<div class="kpi-card__step">1</div>
				<div class="kpi-card__icon kpi-card__icon--calendar">
					<Calendar :size="18" />
				</div>
				<div class="kpi-card__content">
					<div class="kpi-card__label">REQUEST DATE</div>
					<div class="kpi-card__value">{{ formatDisplayDate(kpis.requestDate) || '—' }}</div>
					<div class="kpi-card__subtext">Project kickoff</div>
				</div>
			</div>

			<div class="kpi-connector" :class="{ 'kpi-connector--active': processStatus === 'complete' }"></div>

			<!-- 2. Process Steps Completion -->
			<div class="kpi-card kpi-card--process" :class="{ 'kpi-card--complete': processStatus === 'complete' }">
				<div class="kpi-card__step">2</div>
				<div class="kpi-card__icon" :class="processIconClass">
					<CheckboxMarkedCircleOutline v-if="processStatus === 'complete'" :size="18" />
					<ClockOutline v-else-if="processStatus === 'incomplete'" :size="18" />
					<AlertCircleOutline v-else :size="18" />
				</div>
				<div class="kpi-card__content">
					<div class="kpi-card__header-line">
						<span class="kpi-card__label">PROCESS STEPS</span>
						<span class="kpi-process-pill" :class="processPillClass">
							{{ processStatusLabel }}
						</span>
					</div>

					<div class="process-metrics-row">
						<div class="process-counter-badge" :class="processBadgeClass">
							<span class="process-counter-count">{{ processDoneCount }}</span>
							<span class="process-counter-divider">/</span>
							<span class="process-counter-total">{{ processTotalRequired }}</span>
							<span class="process-counter-suffix">done</span>
						</div>
						<span
							v-if="hasMissingTitles"
							class="process-missing-chip"
							:title="'Missing required checklist cards:\n• ' + missingTitlesList.join('\n• ')">
							Missing {{ missingTitlesList.length }}
						</span>
					</div>

					<div class="kpi-card__subtext process-subtext" :title="processSubtextTitle">
						<template v-if="processStatus === 'complete' && processCompletedDate">
							Completed on {{ formatDisplayDate(processCompletedDate) }}
						</template>
						<template v-else-if="coordinationDurationText">
							{{ coordinationDurationText }} pending
						</template>
						<template v-else-if="processRemainingCount > 0">
							{{ processRemainingCount }} remaining to complete
						</template>
						<template v-else>
							Readiness checklist
						</template>
					</div>
				</div>
			</div>

			<div class="kpi-connector" :class="{ 'kpi-connector--active': processStatus === 'complete' }"></div>

			<!-- 3. Preparation Time & Calculated Duration -->
			<div class="kpi-card kpi-card--prep">
				<div class="kpi-card__step">3</div>
				<div class="kpi-card__icon kpi-card__icon--clock">
					<CalendarEdit :size="18" />
				</div>
				<div class="kpi-card__content">
					<div class="kpi-card__label">REQUIRED PREPARATION TIME</div>

					<div class="prep-input-row">
						<div class="prep-input-wrap">
							<input
								id="prep-weeks-kpi-input"
								v-model.number="localPrepWeeks"
								type="number"
								min="0"
								max="104"
								class="prep-number-field"
								:disabled="!canEdit || saving"
								@keydown.enter.prevent="savePrepWeeks"
								@keydown.esc.prevent="resetPrepWeeks" />
							<label for="prep-weeks-kpi-input" class="prep-unit-suffix">Weeks</label>
						</div>

						<transition name="fade">
							<NcButton
								v-if="canEdit && isPrepWeeksDirty"
								type="primary"
								size="small"
								class="prep-save-btn"
								title="Save preparation weeks"
								:disabled="saving || localPrepWeeks === null || localPrepWeeks < 0"
								@click="savePrepWeeks">
								{{ saving ? '...' : 'Save' }}
							</NcButton>
						</transition>
					</div>

					<div class="kpi-card__subtext kpi-card__subtext--breakdown">
						Min. Duration: <strong>{{ kpis.minimumDurationWeeks ?? 0 }}w</strong> (Deck: {{ kpis.deckTasksWeeks ?? 0 }}w + Prep: {{ kpis.preparationWeeks ?? 0 }}w)
					</div>
				</div>
			</div>

			<div class="kpi-connector"></div>

			<!-- 4. Minimum Start Date -->
			<div class="kpi-card">
				<div class="kpi-card__step">4</div>
				<div class="kpi-card__icon kpi-card__icon--diamond">
					<RhombusMedium :size="18" />
				</div>
				<div class="kpi-card__content">
					<div class="kpi-card__label">MINIMUM START DATE</div>
					<div class="kpi-card__value">{{ formatDisplayDate(kpis.minimumStartDate) || '—' }}</div>
					<div class="kpi-card__subtext">Earliest execution</div>
				</div>
			</div>

			<div class="kpi-connector kpi-connector--dashed"></div>

			<!-- 5. Desired Start Date (Interactive Edit Card) -->
			<div class="kpi-card kpi-card--desired" :class="{ 'kpi-card--editing': isEditingDesiredDate }">
				<div class="kpi-card__step">5</div>
				<div class="kpi-card__icon kpi-card__icon--play">
					<Play :size="18" />
				</div>
				<div class="kpi-card__content">
					<div class="kpi-card__header-line">
						<span class="kpi-card__label">DESIRED START DATE</span>
						<NcButton
							v-if="canEdit && !isEditingDesiredDate"
							type="tertiary"
							size="small"
							class="kpi-card__edit-btn"
							title="Edit desired start date"
							@click="startEditing">
							<template #icon>
								<Pencil :size="14" />
							</template>
						</NcButton>
					</div>

					<!-- View Mode -->
					<div v-if="!isEditingDesiredDate" class="kpi-card__desired-display">
						<div v-if="kpis.desiredStartDate" class="desired-date-row">
							<span class="kpi-card__value">{{ formatDisplayDate(kpis.desiredStartDate) }}</span>
							<span v-if="floatBadgeText" class="kpi-card__float-chip" :class="floatChipClass">
								{{ floatBadgeText }}
							</span>
						</div>
						<div v-else class="desired-date-row desired-date-row--empty">
							<span class="kpi-card__placeholder">Not set</span>
							<NcButton
								v-if="canEdit"
								type="tertiary"
								size="small"
								class="set-date-btn"
								@click="startEditing">
								+ Set date
							</NcButton>
						</div>
					</div>

					<!-- Edit Mode Inline Input -->
					<div v-else class="kpi-card__desired-edit">
						<input
							ref="dateInput"
							v-model="draftDesiredDate"
							type="date"
							class="kpi-date-input"
							:disabled="saving"
							@keydown.enter.prevent="saveDesiredDate"
							@keydown.esc.prevent="cancelEditing" />
						<div class="kpi-date-actions">
							<NcButton
								type="primary"
								size="small"
								class="kpi-action-btn"
								title="Save date"
								:disabled="saving"
								@click="saveDesiredDate">
								<template #icon>
									<Check :size="14" />
								</template>
							</NcButton>
							<NcButton
								v-if="kpis.desiredStartDate"
								type="tertiary"
								size="small"
								class="kpi-action-btn kpi-action-btn--delete"
								title="Clear target date"
								:disabled="saving"
								@click="clearDesiredDate">
								<template #icon>
									<Delete :size="14" />
								</template>
							</NcButton>
							<NcButton
								type="tertiary"
								size="small"
								class="kpi-action-btn"
								title="Cancel"
								:disabled="saving"
								@click="cancelEditing">
								<template #icon>
									<Close :size="14" />
								</template>
							</NcButton>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import CalendarEdit from 'vue-material-design-icons/CalendarEdit.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import RhombusMedium from 'vue-material-design-icons/RhombusMedium.vue'
import Play from 'vue-material-design-icons/Play.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import CheckboxMarkedCircleOutline from 'vue-material-design-icons/CheckboxMarkedCircleOutline.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'

export default {
	name: 'TimelineKpiBar',
	components: {
		NcButton,
		Calendar,
		CalendarEdit,
		ClockOutline,
		RhombusMedium,
		Play,
		Pencil,
		Check,
		Close,
		Delete,
		CheckboxMarkedCircleOutline,
		AlertCircleOutline,
	},
	props: {
		kpis: {
			type: Object,
			required: true,
			default: () => ({
				requestDate: '',
				processCompleted: {
					status: 'incomplete',
					date: null,
					doneCount: 0,
					totalRequired: 0,
					missingTitles: [],
				},
				coordinationPendingPeriod: null,
				minimumDurationWeeks: 0,
				minimumDurationRange: '',
				minimumStartDate: '',
				desiredStartDate: null,
				deckTasksWeeks: 0,
				preparationWeeks: 0,
				overallFloatWeeks: null,
				overallFloatDays: null,
				planningStatus: 'on_track',
			}),
		},
		canEdit: {
			type: Boolean,
			default: true,
		},
		saving: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			isEditingDesiredDate: false,
			draftDesiredDate: '',
			localPrepWeeks: Number(this.kpis?.preparationWeeks ?? 0),
		}
	},
	watch: {
		'kpis.preparationWeeks': {
			immediate: true,
			handler(newVal) {
				if (!this.isPrepWeeksDirty) {
					this.localPrepWeeks = Number(newVal ?? 0)
				}
			},
		},
	},
	computed: {
		isPrepWeeksDirty() {
			const current = Number(this.kpis?.preparationWeeks ?? 0)
			return this.localPrepWeeks !== null && this.localPrepWeeks !== undefined && Number(this.localPrepWeeks) !== current
		},
		processCompletedData() {
			return this.kpis?.processCompleted || {}
		},
		processStatus() {
			return this.processCompletedData?.status || 'incomplete'
		},
		processDoneCount() {
			return Number(this.processCompletedData?.doneCount ?? 0)
		},
		processTotalRequired() {
			return Number(this.processCompletedData?.totalRequired ?? 0)
		},
		processRemainingCount() {
			return Math.max(0, this.processTotalRequired - this.processDoneCount)
		},
		missingTitlesList() {
			return Array.isArray(this.processCompletedData?.missingTitles)
				? this.processCompletedData.missingTitles
				: []
		},
		hasMissingTitles() {
			return this.missingTitlesList.length > 0
		},
		processCompletedDate() {
			return this.processCompletedData?.date || null
		},
		processStatusLabel() {
			const status = this.processStatus
			if (status === 'complete') return 'READY'
			if (status === 'incomplete') return 'IN PROGRESS'
			if (status === 'missing_cards') return 'INITIAL'
			if (status === 'not_configured') return 'N/A'
			return 'PENDING'
		},
		processPillClass() {
			const status = this.processStatus
			if (status === 'complete') return 'kpi-process-pill--success'
			if (status === 'incomplete') return 'kpi-process-pill--warning'
			return 'kpi-process-pill--neutral'
		},
		processBadgeClass() {
			if (this.processStatus === 'complete') return 'process-counter-badge--complete'
			if (this.processDoneCount > 0) return 'process-counter-badge--progress'
			return 'process-counter-badge--idle'
		},
		processIconClass() {
			if (this.processStatus === 'complete') return 'kpi-card__icon--success'
			if (this.processStatus === 'incomplete') return 'kpi-card__icon--clock'
			return 'kpi-card__icon--warning'
		},
		coordinationDurationText() {
			const period = this.kpis?.coordinationPendingPeriod
			if (!period) return ''
			let weeks = Number(period.weeks)
			if (!Number.isFinite(weeks) || weeks <= 0) return ''
			const rounded = Math.round(weeks * 10) / 10
			return `${rounded} ${rounded === 1 ? 'week' : 'weeks'}`
		},
		processSubtextTitle() {
			if (this.processStatus === 'complete' && this.processCompletedDate) {
				return `All required process steps completed on ${this.formatDisplayDate(this.processCompletedDate)}`
			}
			if (this.hasMissingTitles) {
				return 'Missing required cards:\n• ' + this.missingTitlesList.join('\n• ')
			}
			return `${this.processDoneCount} of ${this.processTotalRequired} checklist cards completed`
		},
		planningStatusLabel() {
			const status = this.kpis.planningStatus
			if (status === 'behind_at_risk') {
				return 'BEHIND / AT RISK'
			}
			if (status === 'attention_needed') {
				return 'ATTENTION NEEDED'
			}
			if (!this.kpis.desiredStartDate) {
				return 'ON TRACK (NO TARGET)'
			}
			return 'ON TRACK'
		},
		statusPillClass() {
			const status = this.kpis.planningStatus
			if (status === 'behind_at_risk') {
				return 'status-pill--danger'
			}
			if (status === 'attention_needed') {
				return 'status-pill--warning'
			}
			return 'status-pill--success'
		},
		floatLabel() {
			const float = this.kpis.overallFloatWeeks
			if (float === null || float === undefined) {
				return null
			}
			const prefix = float > 0 ? '+' : ''
			const unit = Math.abs(float) === 1 ? 'WEEK' : 'WEEKS'
			return `${prefix}${float} ${unit}`
		},
		floatPillClass() {
			const float = this.kpis.overallFloatWeeks
			if (float === null || float === undefined) {
				return ''
			}
			if (float < 0) {
				return 'float-pill--danger'
			}
			return 'float-pill--neutral'
		},
		floatBadgeText() {
			const float = this.kpis.overallFloatWeeks
			if (float === null || float === undefined) {
				return ''
			}
			if (float > 0) {
				return `(+${float} WEEK FLOAT)`
			}
			if (float === 0) {
				return '(0 FLOAT)'
			}
			return `(${float} WEEKS BEHIND)`
		},
		floatChipClass() {
			const float = this.kpis.overallFloatWeeks
			if (float < 0) {
				return 'kpi-card__float-chip--danger'
			}
			return 'kpi-card__float-chip--success'
		},
	},
	methods: {
		startEditing() {
			this.draftDesiredDate = this.kpis.desiredStartDate || ''
			this.isEditingDesiredDate = true
			this.$nextTick(() => {
				if (this.$refs.dateInput) {
					this.$refs.dateInput.focus()
				}
			})
		},
		cancelEditing() {
			this.isEditingDesiredDate = false
			this.draftDesiredDate = ''
		},
		saveDesiredDate() {
			const dateStr = this.draftDesiredDate ? this.draftDesiredDate.trim() : null
			this.$emit('save-desired-date', dateStr)
			this.isEditingDesiredDate = false
		},
		clearDesiredDate() {
			this.$emit('save-desired-date', null)
			this.isEditingDesiredDate = false
		},
		savePrepWeeks() {
			if (this.localPrepWeeks === null || this.localPrepWeeks === undefined || this.localPrepWeeks < 0) return
			this.$emit('save-prep-weeks', Math.round(this.localPrepWeeks))
		},
		resetPrepWeeks() {
			this.localPrepWeeks = Number(this.kpis?.preparationWeeks ?? 0)
		},
		formatDisplayDate(isoDate) {
			if (!isoDate || typeof isoDate !== 'string') {
				return ''
			}
			const parts = isoDate.split('T')[0].split('-')
			if (parts.length === 3) {
				return `${parts[2]}/${parts[1]}/${parts[0]}`
			}
			return isoDate
		},
	},
}
</script>

<style scoped>
.timeline-kpi-bar {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 12px;
	padding: 14px 18px;
	margin-bottom: 16px;
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}

.timeline-kpi-bar__status-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 14px;
	padding-bottom: 10px;
	border-bottom: 1px solid var(--color-border-dark);
}

.timeline-kpi-bar__title-tag {
	font-weight: 700;
	font-size: 13px;
	letter-spacing: 0.5px;
	color: var(--color-text-maxcontrast);
}

.timeline-kpi-bar__badges {
	display: flex;
	align-items: center;
	gap: 10px;
}

.status-pill {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 4px 12px;
	border-radius: 9999px;
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.4px;
}

.status-pill--success {
	background: rgba(16, 185, 129, 0.12);
	color: #059669;
	border: 1px solid rgba(16, 185, 129, 0.3);
}

.status-pill--warning {
	background: rgba(245, 158, 11, 0.12);
	color: #d97706;
	border: 1px solid rgba(245, 158, 11, 0.3);
}

.status-pill--danger {
	background: rgba(239, 68, 68, 0.12);
	color: #dc2626;
	border: 1px solid rgba(239, 68, 68, 0.3);
}

.status-dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	background: currentColor;
}

.float-pill {
	display: inline-flex;
	align-items: center;
	padding: 4px 12px;
	border-radius: 9999px;
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.4px;
	background: #0f172a;
	color: #f8fafc;
}

.float-pill--danger {
	background: #dc2626;
	color: #fff;
}

/* Cards Row */
.timeline-kpi-bar__cards {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.kpi-card {
	display: flex;
	align-items: center;
	gap: 10px;
	flex: 1;
	min-width: 0;
	background: var(--color-background-hover);
	padding: 8px 12px;
	border-radius: 8px;
	border: 1px solid var(--color-border);
}

.kpi-card__step {
	width: 20px;
	height: 20px;
	min-width: 20px;
	border-radius: 50%;
	background: #4f46e5;
	color: #ffffff;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 11px;
	font-weight: 700;
}

.kpi-card__icon {
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--color-text-maxcontrast);
}

.kpi-card__icon--calendar {
	color: #3b82f6;
}

.kpi-card__icon--clock {
	color: #f59e0b;
}

.kpi-card__icon--diamond {
	color: #8b5cf6;
}

.kpi-card__icon--play {
	color: #4f46e5;
}

.kpi-card__icon--success {
	color: #10b981;
}

.kpi-card__icon--warning {
	color: #f59e0b;
}

.kpi-card__content {
	display: flex;
	flex-direction: column;
	min-width: 0;
	flex: 1;
}

.kpi-card__header-line {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.kpi-card__label {
	font-size: 10px;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
	letter-spacing: 0.5px;
	text-transform: uppercase;
	margin-bottom: 2px;
}

.kpi-card__value {
	font-size: 13px;
	font-weight: 700;
	color: var(--color-main-text);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.kpi-card__subtext {
	font-size: 10px;
	color: var(--color-text-maxcontrast);
	margin-top: 1px;
	white-space: nowrap;
}

.kpi-connector {
	width: 18px;
	height: 2px;
	background: var(--color-border-dark);
	flex-shrink: 0;
}

.kpi-connector--dashed {
	border-top: 2px dashed var(--color-border-dark);
	background: none;
	height: 0;
}

.kpi-connector--active {
	background: #10b981;
}

/* Process Card specifics */
.kpi-card--process {
	border-color: rgba(59, 130, 246, 0.25);
}

.kpi-card--process.kpi-card--complete {
	border-color: rgba(16, 185, 129, 0.35);
}

.kpi-process-pill {
	display: inline-flex;
	align-items: center;
	padding: 1px 7px;
	border-radius: 9999px;
	font-size: 9px;
	font-weight: 700;
	letter-spacing: 0.3px;
	line-height: 14px;
}

.kpi-process-pill--success {
	background: rgba(16, 185, 129, 0.14);
	color: #059669;
}

.kpi-process-pill--warning {
	background: rgba(245, 158, 11, 0.14);
	color: #d97706;
}

.kpi-process-pill--neutral {
	background: rgba(100, 116, 139, 0.14);
	color: var(--color-text-maxcontrast);
}

.process-metrics-row {
	display: flex;
	align-items: center;
	gap: 6px;
	margin: 2px 0;
	flex-wrap: wrap;
}

.process-counter-badge {
	display: inline-flex;
	align-items: center;
	gap: 2px;
	padding: 2px 7px;
	border-radius: 6px;
	font-weight: 700;
	font-size: 12px;
	line-height: 16px;
}

.process-counter-badge--complete {
	background: rgba(16, 185, 129, 0.12);
	color: #059669;
	border: 1px solid rgba(16, 185, 129, 0.3);
}

.process-counter-badge--progress {
	background: rgba(79, 70, 229, 0.10);
	color: #4f46e5;
	border: 1px solid rgba(79, 70, 229, 0.25);
}

.process-counter-badge--idle {
	background: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
	border: 1px solid var(--color-border);
}

.process-counter-count {
	font-size: 13px;
	font-weight: 800;
}

.process-counter-divider {
	font-size: 11px;
	opacity: 0.6;
	margin: 0 1px;
}

.process-counter-total {
	font-size: 12px;
	font-weight: 700;
}

.process-counter-suffix {
	font-size: 10px;
	font-weight: 600;
	margin-left: 2px;
	opacity: 0.85;
}

.process-missing-chip {
	font-size: 10px;
	font-weight: 700;
	padding: 1px 6px;
	border-radius: 4px;
	background: rgba(239, 68, 68, 0.12);
	color: #dc2626;
	border: 1px solid rgba(239, 68, 68, 0.25);
	cursor: help;
}

.process-subtext {
	max-width: 100%;
}

/* Desired Date Card specifics */
.kpi-card--desired {
	border-color: rgba(79, 70, 229, 0.3);
}

.desired-date-row {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-wrap: wrap;
}

.desired-date-row--empty {
	display: flex;
	align-items: center;
	gap: 8px;
}

.kpi-card__placeholder {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.set-date-btn {
	padding: 0 6px !important;
	height: 22px !important;
	font-size: 11px !important;
}

.kpi-card__float-chip {
	font-size: 10px;
	font-weight: 700;
	padding: 1px 6px;
	border-radius: 4px;
}

.kpi-card__float-chip--success {
	background: rgba(16, 185, 129, 0.15);
	color: #059669;
}

.kpi-card__float-chip--danger {
	background: rgba(239, 68, 68, 0.15);
	color: #dc2626;
}

.kpi-card__edit-btn {
	padding: 0 4px !important;
	height: 20px !important;
}

.kpi-card__subtext--breakdown {
	color: var(--color-primary-element);
	font-weight: 600;
	margin-top: 2px;
}

/* Preparation Input Group */
.prep-input-row {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 2px 0;
}

.prep-input-wrap {
	display: inline-flex;
	align-items: center;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: 6px;
	padding: 2px 8px 2px 4px;
	transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.prep-input-wrap:focus-within {
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 2px var(--color-primary-element-light);
}

.prep-number-field {
	width: 46px;
	background: transparent;
	border: none;
	outline: none;
	font-size: 14px;
	font-weight: 700;
	color: var(--color-main-text);
	text-align: right;
	padding: 2px 4px;
}

.prep-unit-suffix {
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	margin-left: 2px;
	cursor: pointer;
}

.prep-save-btn {
	height: 26px !important;
	padding: 0 10px !important;
	font-weight: 600;
	font-size: 12px !important;
}

.kpi-number-input-wrap {
	display: inline-flex;
	align-items: center;
	gap: 6px;
}

.kpi-date-input--number {
	width: 56px;
	text-align: center;
}

.kpi-unit-label {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

/* Inline Edit Controls */
.kpi-card__desired-edit {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-top: 2px;
}

.kpi-date-input {
	height: 26px;
	font-size: 11px;
	padding: 2px 6px;
	border-radius: 4px;
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	width: 125px;
}

.kpi-date-actions {
	display: flex;
	align-items: center;
	gap: 2px;
}

.kpi-action-btn {
	height: 24px !important;
	width: 24px !important;
	padding: 0 !important;
	min-width: 24px !important;
	display: flex !important;
	align-items: center !important;
	justify-content: center !important;
}

.kpi-action-btn--delete:hover {
	color: var(--color-error);
}

@media (max-width: 900px) {
	.timeline-kpi-bar__cards {
		flex-direction: column;
		align-items: stretch;
	}
	.kpi-connector {
		display: none;
	}
}
</style>
