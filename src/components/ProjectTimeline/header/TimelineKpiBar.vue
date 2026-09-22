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
			<!-- Phase 1: Initiation & Readiness Group -->
			<div class="kpi-group kpi-group--initiation">
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
							<template v-if="processStatus === 'complete'">
								All steps complete
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

				<!-- 3. Process Completion Date -->
				<div class="kpi-card kpi-card--completion" :class="{ 'kpi-card--complete': processStatus === 'complete' }">
					<div class="kpi-card__step">3</div>
					<div class="kpi-card__icon" :class="processStatus === 'complete' ? 'kpi-card__icon--success' : 'kpi-card__icon--calendar'">
						<CheckboxMarkedCircleOutline v-if="processStatus === 'complete'" :size="18" />
						<Calendar v-else :size="18" />
					</div>
					<div class="kpi-card__content">
						<div class="kpi-card__label">PROCESS COMPLETION DATE</div>
						<div class="kpi-card__value">{{ formatDisplayDate(processCompletedDate) || 'Pending' }}</div>
						<div class="kpi-card__subtext">
							{{ processStatus === 'complete' ? 'All required steps done' : 'Awaiting completion' }}
						</div>
					</div>
				</div>
			</div>

			<!-- Mid-Phase Link Connector (hidden on 2-row layout) -->
			<div
				class="kpi-connector kpi-connector--group-link"
				:class="{ 'kpi-connector--active': processStatus === 'complete' }"></div>

			<!-- Phase 2: Preparation & Execution Group -->
			<div class="kpi-group kpi-group--execution">
				<!-- 4. Preparation Time & Calculated Duration -->
				<div class="kpi-card kpi-card--prep">
					<div class="kpi-card__step">4</div>
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

						<div
							class="kpi-card__subtext kpi-card__subtext--breakdown"
							:title="'Minimum Duration: ' + (kpis.minimumDurationWeeks ?? 0) + 'w (Initiation: ' + (kpis.deckTasksWeeks ?? 0) + 'w + Prep: ' + (kpis.preparationWeeks ?? 0) + 'w)'">
							Min. Duration: <strong>{{ kpis.minimumDurationWeeks ?? 0 }}w</strong> (Initiation: {{ kpis.deckTasksWeeks ?? 0 }}w + Prep: {{ kpis.preparationWeeks ?? 0 }}w)
						</div>
					</div>
				</div>

				<div class="kpi-connector"></div>

				<!-- 5. Minimum Start Date -->
				<div class="kpi-card">
					<div class="kpi-card__step">5</div>
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

				<!-- 6. Start Dates (Interactive Direct Calendar Picker) -->
				<div class="kpi-card kpi-card--desired">
					<div class="kpi-card__step">6</div>
					<div class="kpi-card__icon kpi-card__icon--play">
						<Play :size="18" />
					</div>
					<div class="kpi-card__content">
						<div class="kpi-card__header-line">
							<span class="kpi-card__label">START DATES</span>
						</div>

						<div class="start-date-rows">
							<!-- Desired Start Date Sub-row -->
							<div class="start-date-inline-row">
								<div class="start-date-inline-left">
									<span class="subrow-title">DESIRED</span>
								</div>

								<div class="start-date-inline-right">
									<!-- Hidden native date picker triggered directly by click -->
									<input
										ref="desiredDatePicker"
										type="date"
										class="kpi-native-date-hidden"
										:value="isoDateOnly(kpis.desiredStartDate)"
										:disabled="!canEdit || saving"
										aria-label="Select desired start date"
										@change="onDesiredDateChange" />

									<div v-if="kpis.desiredStartDate" class="desired-date-row">
										<span
											class="kpi-card__value kpi-card__value--compact"
											:class="{ 'kpi-card__value--clickable': canEdit && !saving }"
											:title="canEdit ? 'Click to change desired start date' : ''"
											@click="openDesiredCalendar">
											{{ formatDisplayDate(kpis.desiredStartDate) }}
										</span>
										<NcButton
											v-if="canEdit"
											type="tertiary"
											size="small"
											class="kpi-card__mini-btn"
											title="Change desired start date"
											:disabled="saving"
											@click="openDesiredCalendar">
											<template #icon>
												<Pencil :size="11" />
											</template>
										</NcButton>
										<NcButton
											v-if="canEdit"
											type="tertiary"
											size="small"
											class="kpi-card__mini-btn kpi-card__mini-btn--clear"
											title="Clear desired start date"
											:disabled="saving"
											@click.stop="clearDesiredDate">
											<template #icon>
												<Close :size="11" />
											</template>
										</NcButton>
										<span
											v-if="floatBadgeText"
											class="kpi-card__float-chip"
											:class="floatChipClass"
											:title="floatTooltip">
											{{ floatBadgeText }}
										</span>
									</div>
									<div v-else class="desired-date-row desired-date-row--empty">
										<span
											class="kpi-card__placeholder"
											:class="{ 'kpi-card__value--clickable': canEdit && !saving }"
											:title="canEdit ? 'Click to set desired start date' : ''"
											@click="openDesiredCalendar">
											Not set
										</span>
										<NcButton
											v-if="canEdit"
											type="tertiary"
											size="small"
											class="set-date-btn"
											title="Set desired start date"
											:disabled="saving"
											@click="openDesiredCalendar">
											<template #icon>
												<Plus :size="12" />
											</template>
											Set
										</NcButton>
									</div>
								</div>
							</div>

							<!-- Actual Start Date Sub-row -->
							<div class="start-date-inline-row">
								<div class="start-date-inline-left">
									<span class="subrow-title">ACTUAL</span>
								</div>

								<div class="start-date-inline-right">
									<!-- Hidden native date picker triggered directly by click -->
									<input
										ref="actualDatePicker"
										type="date"
										class="kpi-native-date-hidden"
										:value="isoDateOnly(kpis.actualStartDate)"
										:disabled="!canEdit || saving"
										aria-label="Select actual start date"
										@change="onActualDateChange" />

									<div v-if="kpis.actualStartDate" class="desired-date-row">
										<span
											class="kpi-card__value kpi-card__value--compact"
											:class="{ 'kpi-card__value--clickable': canEdit && !saving }"
											:title="canEdit ? 'Click to change actual start date' : ''"
											@click="openActualCalendar">
											{{ formatDisplayDate(kpis.actualStartDate) }}
										</span>
										<NcButton
											v-if="canEdit"
											type="tertiary"
											size="small"
											class="kpi-card__mini-btn"
											title="Change actual start date"
											:disabled="saving"
											@click="openActualCalendar">
											<template #icon>
												<Pencil :size="11" />
											</template>
										</NcButton>
										<NcButton
											v-if="canEdit"
											type="tertiary"
											size="small"
											class="kpi-card__mini-btn kpi-card__mini-btn--clear"
											title="Clear actual start date"
											:disabled="saving"
											@click.stop="clearActualDate">
											<template #icon>
												<Close :size="11" />
											</template>
										</NcButton>
									</div>
									<div v-else class="desired-date-row desired-date-row--empty">
										<span
											class="kpi-card__placeholder"
											:class="{ 'kpi-card__value--clickable': canEdit && !saving }"
											:title="canEdit ? 'Click to set actual start date' : ''"
											@click="openActualCalendar">
											Not set
										</span>
										<NcButton
											v-if="canEdit"
											type="tertiary"
											size="small"
											class="set-date-btn"
											title="Set actual start date"
											:disabled="saving"
											@click="openActualCalendar">
											<template #icon>
												<Plus :size="12" />
											</template>
											Set
										</NcButton>
									</div>
								</div>
							</div>
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
import Plus from 'vue-material-design-icons/Plus.vue'
import Close from 'vue-material-design-icons/Close.vue'
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
		Plus,
		Close,
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
				actualStartDate: null,
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
			const weeks = Number(period.weeks)
			if (!Number.isFinite(weeks) || weeks <= 0) return ''
			const rounded = Math.round(weeks * 10) / 10
			return `${rounded} ${rounded === 1 ? 'week' : 'weeks'}`
		},
		processSubtextTitle() {
			if (this.processStatus === 'complete') {
				return 'All required process steps completed'
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
				return `+${float}w`
			}
			if (float === 0) {
				return '0w'
			}
			return `${float}w`
		},
		floatTooltip() {
			const float = this.kpis.overallFloatWeeks
			if (float === null || float === undefined) {
				return ''
			}
			if (float > 0) {
				return `+${float} week${Math.abs(float) === 1 ? '' : 's'} float`
			}
			if (float === 0) {
				return '0 float'
			}
			return `${Math.abs(float)} week${Math.abs(float) === 1 ? '' : 's'} behind schedule`
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
		openDesiredCalendar() {
			if (!this.canEdit || this.saving) return
			const input = this.$refs.desiredDatePicker
			if (!input) return
			if (typeof input.showPicker === 'function') {
				try {
					input.showPicker()
					return
				} catch (e) {
					console.warn('showPicker failed', e)
				}
			}
			input.focus()
			input.click()
		},
		onDesiredDateChange(e) {
			const val = e?.target?.value ? e.target.value.trim() : null
			if (val) {
				this.$emit('save-desired-date', val)
			}
		},
		clearDesiredDate() {
			if (!this.canEdit || this.saving) return
			this.$emit('save-desired-date', null)
			if (this.$refs.desiredDatePicker) {
				this.$refs.desiredDatePicker.value = ''
			}
		},
		openActualCalendar() {
			if (!this.canEdit || this.saving) return
			const input = this.$refs.actualDatePicker
			if (!input) return
			if (typeof input.showPicker === 'function') {
				try {
					input.showPicker()
					return
				} catch (e) {
					console.warn('showPicker failed', e)
				}
			}
			input.focus()
			input.click()
		},
		onActualDateChange(e) {
			const val = e?.target?.value ? e.target.value.trim() : null
			if (val) {
				this.$emit('save-actual-date', val)
			}
		},
		clearActualDate() {
			if (!this.canEdit || this.saving) return
			this.$emit('save-actual-date', null)
			if (this.$refs.actualDatePicker) {
				this.$refs.actualDatePicker.value = ''
			}
		},
		isoDateOnly(isoDate) {
			if (!isoDate || typeof isoDate !== 'string') return ''
			return isoDate.split('T')[0]
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
	padding: 12px 16px;
	margin-bottom: 16px;
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
	container-type: inline-size;
	container-name: kpi-bar;
}

.timeline-kpi-bar__status-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
	padding-bottom: 8px;
	border-bottom: 1px solid var(--color-border-dark);
	flex-wrap: wrap;
	gap: 8px;
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
	gap: 8px;
	flex-wrap: wrap;
}

.status-pill {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 3px 10px;
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
	padding: 3px 10px;
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
	align-items: stretch;
	justify-content: space-between;
	gap: 6px;
}

.kpi-group {
	display: flex;
	align-items: stretch;
	flex: 1 1 0;
	min-width: 0;
	gap: 6px;
}

.kpi-card {
	display: flex;
	align-items: center;
	gap: 8px;
	flex: 1 1 0;
	min-width: 0;
	background: var(--color-background-hover);
	padding: 8px 10px;
	border-radius: 8px;
	border: 1px solid var(--color-border);
	box-sizing: border-box;
	transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.kpi-card:hover {
	border-color: var(--color-border-dark);
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
	flex-shrink: 0;
}

.kpi-card__icon {
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
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
	justify-content: center;
	min-width: 0;
	flex: 1;
}

.kpi-card__header-line {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 4px;
}

.kpi-card__label {
	font-size: 10px;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
	letter-spacing: 0.5px;
	text-transform: uppercase;
	margin-bottom: 2px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.kpi-card__value {
	font-size: 13px;
	font-weight: 700;
	color: var(--color-main-text);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.kpi-card__value--compact {
	font-size: 11px !important;
	font-weight: 700;
	line-height: 16px;
}

.kpi-card__value--clickable {
	cursor: pointer;
}

.kpi-card__value--clickable:hover {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.kpi-card__subtext {
	font-size: 10px;
	color: var(--color-text-maxcontrast);
	margin-top: 1px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.kpi-connector {
	flex: 0 1 14px;
	min-width: 6px;
	height: 2px;
	background: var(--color-border-dark);
	align-self: center;
}

.kpi-connector--dashed {
	border-top: 2px dashed var(--color-border-dark);
	background: none;
	height: 0;
}

.kpi-connector--active {
	background: #10b981;
}

.kpi-connector--group-link {
	flex: 0 1 14px;
	min-width: 6px;
}

/* Process Card specifics */
.kpi-card--process {
	border-color: rgba(59, 130, 246, 0.25);
}

.kpi-card--process.kpi-card--complete {
	border-color: rgba(16, 185, 129, 0.35);
}

.kpi-card--completion.kpi-card--complete {
	border-color: rgba(16, 185, 129, 0.35);
}

.kpi-process-pill {
	display: inline-flex;
	align-items: center;
	padding: 1px 6px;
	border-radius: 9999px;
	font-size: 9px;
	font-weight: 700;
	letter-spacing: 0.3px;
	line-height: 14px;
	flex-shrink: 0;
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
	gap: 4px;
	margin: 1px 0;
	flex-wrap: wrap;
}

.process-counter-badge {
	display: inline-flex;
	align-items: center;
	gap: 2px;
	padding: 1px 6px;
	border-radius: 5px;
	font-weight: 700;
	font-size: 11px;
	line-height: 15px;
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
	font-size: 12px;
	font-weight: 800;
}

.process-counter-divider {
	font-size: 10px;
	opacity: 0.6;
	margin: 0 1px;
}

.process-counter-total {
	font-size: 11px;
	font-weight: 700;
}

.process-counter-suffix {
	font-size: 9px;
	font-weight: 600;
	margin-left: 2px;
	opacity: 0.85;
}

.process-missing-chip {
	font-size: 9px;
	font-weight: 700;
	padding: 1px 5px;
	border-radius: 4px;
	background: rgba(239, 68, 68, 0.12);
	color: #dc2626;
	border: 1px solid rgba(239, 68, 68, 0.25);
	cursor: help;
	line-height: 13px;
}

.process-subtext {
	max-width: 100%;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* Preparation Time Card specifics */
.prep-input-row {
	display: flex;
	align-items: center;
	gap: 6px;
	margin: 1px 0;
}

.prep-input-wrap {
	display: inline-flex;
	align-items: center;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: 5px;
	padding: 1px 6px 1px 3px;
	transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.prep-input-wrap:focus-within {
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 2px var(--color-primary-element-light);
}

.prep-number-field {
	width: 38px;
	background: transparent;
	border: none;
	outline: none;
	font-size: 13px;
	font-weight: 700;
	color: var(--color-main-text);
	text-align: right;
	padding: 1px 2px;
}

.prep-unit-suffix {
	font-size: 11px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	margin-left: 2px;
	cursor: pointer;
}

.prep-save-btn {
	height: 22px !important;
	padding: 0 8px !important;
	font-weight: 600;
	font-size: 11px !important;
}

.kpi-card__subtext--breakdown {
	color: var(--color-primary-element);
	font-weight: 600;
	margin-top: 2px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* Start Dates Card specifics */
.kpi-card--desired {
	border-color: rgba(79, 70, 229, 0.3);
}

.start-date-rows {
	display: flex;
	flex-direction: column;
	gap: 2px;
	margin-top: 1px;
}

.start-date-inline-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 6px;
	min-height: 20px;
}

.start-date-inline-left {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	flex-shrink: 0;
	min-width: 46px;
}

.subrow-title {
	font-size: 9px;
	font-weight: 700;
	letter-spacing: 0.4px;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
}

.start-date-inline-right {
	position: relative;
	display: inline-flex;
	align-items: center;
	justify-content: flex-end;
	gap: 4px;
	min-width: 0;
	flex: 1;
}

.kpi-native-date-hidden {
	position: absolute;
	right: 0;
	bottom: 0;
	width: 1px;
	height: 1px;
	padding: 0;
	margin: 0;
	border: 0;
	opacity: 0;
	pointer-events: none;
}

.desired-date-row {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	min-width: 0;
	white-space: nowrap;
}

.desired-date-row--empty {
	display: inline-flex;
	align-items: center;
	gap: 6px;
}

.kpi-card__placeholder {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
	white-space: nowrap;
}

.set-date-btn {
	padding: 0 6px !important;
	height: 18px !important;
	font-size: 10px !important;
	line-height: 16px !important;
	border-radius: 4px;
	font-weight: 600;
	display: inline-flex !important;
	align-items: center !important;
	gap: 2px !important;
}

.kpi-card__mini-btn {
	padding: 0 !important;
	height: 18px !important;
	width: 18px !important;
	min-width: 18px !important;
	display: inline-flex !important;
	align-items: center !important;
	justify-content: center !important;
	border-radius: 3px;
}

.kpi-card__mini-btn--clear:hover {
	color: var(--color-error);
}

.kpi-card__float-chip {
	font-size: 9px;
	font-weight: 700;
	padding: 1px 4px;
	border-radius: 4px;
	line-height: 13px;
	white-space: nowrap;
	flex-shrink: 0;
}

.kpi-card__float-chip--success {
	background: rgba(16, 185, 129, 0.15);
	color: #059669;
}

.kpi-card__float-chip--danger {
	background: rgba(239, 68, 68, 0.15);
	color: #dc2626;
}

/* Container Queries for responsive adaptation */
@container kpi-bar (max-width: 1179px) {
	.timeline-kpi-bar__cards {
		flex-direction: column;
		gap: 8px;
	}
	.kpi-group {
		width: 100%;
	}
	.kpi-connector--group-link {
		display: none;
	}
}

@container kpi-bar (max-width: 719px) {
	.timeline-kpi-bar__cards {
		flex-direction: column;
		gap: 8px;
	}
	.kpi-group {
		flex-direction: column;
		width: 100%;
		gap: 8px;
	}
	.kpi-connector {
		display: none;
	}
	.timeline-kpi-bar__status-row {
		flex-direction: column;
		align-items: flex-start;
		gap: 8px;
	}
	.timeline-kpi-bar__badges {
		width: 100%;
		justify-content: flex-start;
	}
}

/* Fallback media queries for browsers that do not support container queries */
@supports not (container-type: inline-size) {
	@media (max-width: 1179px) and (min-width: 720px) {
		.timeline-kpi-bar__cards {
			flex-direction: column;
			gap: 8px;
		}
		.kpi-group {
			width: 100%;
		}
		.kpi-connector--group-link {
			display: none;
		}
	}

	@media (max-width: 719px) {
		.timeline-kpi-bar__cards {
			flex-direction: column;
			gap: 8px;
		}
		.kpi-group {
			flex-direction: column;
			width: 100%;
			gap: 8px;
		}
		.kpi-connector {
			display: none;
		}
		.timeline-kpi-bar__status-row {
			flex-direction: column;
			align-items: flex-start;
			gap: 8px;
		}
		.timeline-kpi-bar__badges {
			width: 100%;
			justify-content: flex-start;
		}
	}
}
</style>
