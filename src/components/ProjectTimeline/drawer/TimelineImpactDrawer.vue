<template>
	<div class="impact-drawer-backdrop" @click.self="$emit('close')">
		<div class="impact-drawer">
			<!-- Header -->
			<header class="impact-drawer__header">
				<div class="header-title-group">
					<div class="header-badge">
						<AlertCircleOutline :size="16" class="header-badge-icon" />
						<span>IMPACT & RECOVERY ADVISOR</span>
					</div>
					<h3 class="header-title">
						Schedule Impact Analysis
					</h3>
				</div>
				<button class="close-btn" aria-label="Close" @click="$emit('close')">
					<Close :size="20" />
				</button>
			</header>

			<div class="impact-drawer__content">
				<!-- Context Guide matching Mockup -->
				<div class="guide-box">
					<div class="guide-box__title">
						Where & when do you see this?
					</div>
					<div class="guide-steps">
						<div class="guide-step">
							<span class="guide-num">1</span>
							<div class="guide-text">
								<strong>Directly in the Gantt:</strong> Bar colors reflect delay and downstream lines turn red.
							</div>
						</div>
						<div class="guide-step">
							<span class="guide-num">2</span>
							<div class="guide-text">
								<strong>Impact dialog:</strong> Shows consequences and tailored recovery options.
							</div>
						</div>
						<div class="guide-step">
							<span class="guide-num">3</span>
							<div class="guide-text">
								<strong>What-if mode:</strong> Test scenarios non-destructively before committing.
							</div>
						</div>
					</div>
				</div>

				<!-- Delayed Task Summary Card matching Mockup -->
				<div class="task-card" :class="'task-card--' + severityClass">
					<div class="task-card__icon-wrap">
						<AlertCircle :size="24" class="task-alert-icon" />
					</div>
					<div class="task-card__body">
						<div class="task-card__title">
							Task "{{ taskLabel }}" is delayed by {{ delayWeeks }} weeks
						</div>
						<div class="task-card__sub">
							New expected end date: {{ newEndDateFormatted }}
						</div>
					</div>
				</div>

				<!-- Impact without measures matching Mockup -->
				<div class="impact-section">
					<div class="section-heading">
						<span>Impact without measures</span>
						<div class="severity-pill" :class="'severity-pill--' + severityClass">
							<span class="severity-dot" />
							<span>{{ severityLabel }}</span>
						</div>
					</div>

					<ul class="impact-list">
						<li v-for="(bullet, index) in unmitigatedImpacts" :key="'bullet-' + index">
							{{ bullet }}
						</li>
					</ul>
				</div>

				<!-- Choose a recovery option (A to F) matching Mockup -->
				<div class="recovery-section">
					<div class="section-heading">
						<span>Choose a recovery option:</span>
					</div>

					<div class="options-list">
						<button
							v-for="opt in recoveryOptions"
							:key="opt.id"
							type="button"
							class="option-card"
							:class="{ active: selectedOptionId === opt.id, recommended: opt.recommended }"
							@click="onSelectOption(opt)">
							<div class="option-letter">
								{{ opt.letter }}
							</div>
							<div class="option-body">
								<div class="option-title-row">
									<span class="option-title">{{ opt.title }}</span>
									<span v-if="opt.recommended" class="opt-tag opt-tag--rec">Recommended</span>
									<span v-else-if="opt.tag" class="opt-tag">{{ opt.tag }}</span>
								</div>
								<p class="option-desc">
									{{ opt.description }}
								</p>
								<div v-if="opt.summary" class="option-summary">
									{{ opt.summary }}
								</div>
							</div>
							<div class="option-chevron">
								<ChevronRight :size="20" />
							</div>
						</button>
					</div>
				</div>
			</div>

			<!-- Footer matching Mockup -->
			<footer class="impact-drawer__footer">
				<NcButton
					type="primary"
					class="whatif-action-btn"
					@click="onLaunchWhatIf">
					<template #icon>
						<FlaskOutline :size="18" />
					</template>
					Open What-if mode
				</NcButton>
			</footer>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import Close from 'vue-material-design-icons/Close.vue'
import FlaskOutline from 'vue-material-design-icons/FlaskOutline.vue'

export default {
	name: 'TimelineImpactDrawer',
	components: {
		NcButton,
		AlertCircle,
		AlertCircleOutline,
		ChevronRight,
		Close,
		FlaskOutline,
	},
	props: {
		analysis: {
			type: Object,
			default: () => ({}),
		},
	},
	data() {
		return {
			selectedOptionId: 'accelerate',
		}
	},
	computed: {
		task() {
			return this.analysis?.task || {}
		},
		taskLabel() {
			return this.task.label || 'Permits'
		},
		delayWeeks() {
			return this.task.delayWeeks || 4
		},
		newEndDateFormatted() {
			if (!this.task.newExpectedEndDate) return '-'
			const d = new Date(`${this.task.newExpectedEndDate}T00:00:00`)
			const day = d.getDate().toString().padStart(2, '0')
			const month = (d.getMonth() + 1).toString().padStart(2, '0')
			const year = d.getFullYear()
			return `${day}/${month}/${year}`
		},
		severityClass() {
			return this.analysis?.severity || 'high'
		},
		severityLabel() {
			switch (this.severityClass) {
			case 'high':
				return 'High Risk'
			case 'medium':
				return 'Medium Risk'
			default:
				return 'Low Risk'
			}
		},
		unmitigatedImpacts() {
			return this.analysis?.unmitigatedImpacts || [
				'Work preparation: +4 weeks',
				'Start construction: +4 weeks',
				'Desired start (14/12/2026): no longer achievable',
				'Available float: 1 week (consumed by delay)',
			]
		},
		recoveryOptions() {
			return this.analysis?.recoveryOptions || []
		},
	},
	methods: {
		onSelectOption(opt) {
			this.selectedOptionId = opt.id
			this.$emit('select-option', opt)
		},
		onLaunchWhatIf() {
			const opt = this.recoveryOptions.find(o => o.id === this.selectedOptionId) || this.recoveryOptions[0]
			this.$emit('open-whatif', opt)
		},
	},
}
</script>

<style scoped>
.impact-drawer-backdrop {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(15, 23, 42, 0.4);
	backdrop-filter: blur(2px);
	z-index: 1000;
	display: flex;
	justify-content: flex-end;
	animation: fadeIn 0.2s ease-out;
}

.impact-drawer {
	width: 520px;
	max-width: 90vw;
	height: 100%;
	background: var(--color-main-background);
	display: flex;
	flex-direction: column;
	box-shadow: -8px 0 32px rgba(0, 0, 0, 0.2);
	animation: slideIn 0.25s cubic-bezier(0.16, 1, 0.3, 1);
	overflow: hidden;
}

@keyframes fadeIn {
	from { opacity: 0; }
	to { opacity: 1; }
}

@keyframes slideIn {
	from { transform: translateX(100%); }
	to { transform: translateX(0); }
}

.impact-drawer__header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	padding: 24px 28px 16px;
	border-bottom: 1px solid var(--color-border);
}

.header-badge {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	background: rgba(239, 68, 68, 0.1);
	color: #dc2626;
	padding: 3px 8px;
	border-radius: 99px;
	font-size: 10px;
	font-weight: 800;
	letter-spacing: 0.05em;
	margin-bottom: 6px;
}

.header-title {
	margin: 0;
	font-size: 20px;
	font-weight: 800;
}

.close-btn {
	background: none;
	border: none;
	padding: 6px;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	border-radius: 8px;
	display: flex;
	align-items: center;
	justify-content: center;
	transition: background 0.15s ease;
}

.close-btn:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.impact-drawer__content {
	flex: 1;
	overflow-y: auto;
	padding: 24px 28px;
	display: flex;
	flex-direction: column;
	gap: 20px;
}

/* Guide Box matching Mockup */
.guide-box {
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: 12px;
	padding: 14px 16px;
}

.guide-box__title {
	font-size: 11px;
	font-weight: 800;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	color: var(--color-text-maxcontrast);
	margin-bottom: 10px;
}

.guide-steps {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.guide-step {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	font-size: 12px;
	color: var(--color-main-text);
}

.guide-num {
	width: 18px;
	height: 18px;
	border-radius: 50%;
	background: #3b82f6;
	color: #fff;
	font-size: 10px;
	font-weight: 800;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	margin-top: 1px;
}

/* Task Summary Card matching Mockup */
.task-card {
	display: flex;
	align-items: center;
	gap: 14px;
	padding: 16px;
	border-radius: 14px;
	background: rgba(239, 68, 68, 0.08);
	border: 1.5px solid rgba(239, 68, 68, 0.3);
}

.task-card--medium {
	background: rgba(245, 158, 11, 0.08);
	border-color: rgba(245, 158, 11, 0.3);
}

.task-card--low {
	background: rgba(16, 185, 129, 0.08);
	border-color: rgba(16, 185, 129, 0.3);
}

.task-card__icon-wrap {
	color: #ef4444;
	display: flex;
	align-items: center;
}

.task-card--medium .task-card__icon-wrap { color: #f59e0b; }
.task-card--low .task-card__icon-wrap { color: #10b981; }

.task-card__title {
	font-size: 14px;
	font-weight: 800;
	color: var(--color-main-text);
}

.task-card__sub {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
}

/* Impact Section */
.impact-section, .recovery-section {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.section-heading {
	display: flex;
	justify-content: space-between;
	align-items: center;
	font-size: 13px;
	font-weight: 800;
	color: var(--color-main-text);
}

.severity-pill {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 3px 10px;
	border-radius: 99px;
	font-size: 11px;
	font-weight: 700;
}

.severity-pill--high {
	background: rgba(239, 68, 68, 0.12);
	color: #dc2626;
}
.severity-pill--high .severity-dot { background: #ef4444; }

.severity-pill--medium {
	background: rgba(245, 158, 11, 0.12);
	color: #d97706;
}
.severity-pill--medium .severity-dot { background: #f59e0b; }

.severity-pill--low {
	background: rgba(16, 185, 129, 0.12);
	color: #059669;
}
.severity-pill--low .severity-dot { background: #10b981; }

.severity-dot {
	width: 7px;
	height: 7px;
	border-radius: 50%;
}

.impact-list {
	margin: 0;
	padding-left: 20px;
	display: flex;
	flex-direction: column;
	gap: 6px;
	font-size: 13px;
	color: var(--color-main-text);
}

.impact-list li {
	line-height: 1.4;
}

/* Options List matching Mockup */
.options-list {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.option-card {
	display: flex;
	align-items: center;
	gap: 14px;
	padding: 14px 16px;
	border-radius: 12px;
	border: 1.5px solid var(--color-border);
	background: var(--color-main-background);
	cursor: pointer;
	text-align: left;
	transition: all 0.2s ease;
}

.option-card:hover {
	border-color: #3b82f6;
	background: var(--color-background-hover);
}

.option-card.active {
	border-color: #3b82f6;
	background: rgba(59, 130, 246, 0.05);
	box-shadow: 0 2px 10px rgba(59, 130, 246, 0.15);
}

.option-letter {
	width: 26px;
	height: 26px;
	border-radius: 50%;
	background: #3b82f6;
	color: #ffffff;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 12px;
	font-weight: 800;
	flex-shrink: 0;
}

.option-body {
	flex: 1;
	min-width: 0;
}

.option-title-row {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.option-title {
	font-size: 13px;
	font-weight: 800;
	color: var(--color-main-text);
}

.opt-tag {
	font-size: 10px;
	font-weight: 700;
	padding: 1px 6px;
	border-radius: 4px;
	background: var(--color-background-darker);
	color: var(--color-text-maxcontrast);
}

.opt-tag--rec {
	background: rgba(16, 185, 129, 0.15);
	color: #059669;
}

.option-desc {
	margin: 3px 0 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	line-height: 1.35;
}

.option-summary {
	margin-top: 4px;
	font-size: 11px;
	font-weight: 700;
	color: #3b82f6;
}

.option-chevron {
	color: var(--color-text-lighter);
	display: flex;
	align-items: center;
}

/* Footer */
.impact-drawer__footer {
	padding: 18px 28px;
	border-top: 1px solid var(--color-border);
	background: var(--color-background-dark);
}

.whatif-action-btn {
	width: 100%;
	height: 44px;
	font-weight: 700;
	font-size: 14px;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
}
</style>
