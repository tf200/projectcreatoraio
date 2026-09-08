<template>
	<div class="simulation-banner">
		<div class="simulation-banner__left">
			<div class="simulation-badge">
				<FlaskOutline :size="16" class="flask-icon" />
				<span class="badge-text">WHAT-IF / SCENARIO SIMULATION</span>
			</div>
			<div class="simulation-title">
				Scenario: <strong>{{ scenario?.description || 'Schedule impact scenario' }}</strong>
			</div>

			<!-- Strategy Switcher (Options A to F) -->
			<div class="simulation-strategies">
				<button
					v-for="opt in strategyOptions"
					:key="opt.id"
					type="button"
					class="strategy-pill"
					:class="{ 'strategy-pill--active': activeStrategy === opt.id, 'strategy-pill--rec': opt.recommended }"
					:title="opt.description"
					:disabled="applying"
					@click="$emit('switch-strategy', opt.id)">
					<span class="strategy-letter">{{ opt.letter }}</span>
					<span class="strategy-label">{{ opt.shortTitle || opt.title }}</span>
					<span v-if="opt.recommended" class="strategy-rec-dot" title="Recommended">★</span>
				</button>
			</div>

			<div class="simulation-flow">
				<div class="flow-step flow-step--current">
					<span class="step-label">Current impact:</span>
					<span class="step-val">{{ scenario?.currentImpact || 'Impact calculated' }}</span>
				</div>
				<div class="flow-arrow">
					<ArrowRight :size="16" />
				</div>
				<div class="flow-step flow-step--result">
					<span class="step-label">Scenario result:</span>
					<span class="step-val">{{ scenario?.scenarioResult || 'Result calculated' }}</span>
				</div>
				<div class="flow-arrow">
					<ArrowRight :size="16" />
				</div>
				<div class="flow-step flow-step--status" :class="scenarioStatusClass">
					<span class="step-label">Scenario status:</span>
					<span class="step-val">{{ scenario?.scenarioStatus || 'Simulated' }}</span>
				</div>
			</div>
			<div class="simulation-disclaimer">
				This is only an in-memory simulation. Nothing is changed in the official project plan until applied.
			</div>
		</div>

		<div class="simulation-banner__actions">
			<NcButton
				type="primary"
				class="apply-btn"
				:disabled="applying"
				@click="$emit('apply')">
				<template #icon>
					<Check :size="16" />
				</template>
				{{ applying ? 'Applying...' : 'Apply scenario' }}
			</NcButton>
			<NcButton
				type="secondary"
				class="cancel-btn"
				:disabled="applying"
				@click="$emit('cancel')">
				Cancel
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import Check from 'vue-material-design-icons/Check.vue'
import FlaskOutline from 'vue-material-design-icons/FlaskOutline.vue'

export default {
	name: 'TimelineSimulationBanner',
	components: {
		NcButton,
		ArrowRight,
		Check,
		FlaskOutline,
	},
	props: {
		scenario: {
			type: Object,
			default: () => ({}),
		},
		activeStrategy: {
			type: String,
			default: 'accelerate',
		},
		recoveryOptions: {
			type: Array,
			default: () => [],
		},
		applying: {
			type: Boolean,
			default: false,
		},
	},
	computed: {
		strategyOptions() {
			if (Array.isArray(this.recoveryOptions) && this.recoveryOptions.length > 0) {
				return this.recoveryOptions.map(opt => ({
					...opt,
					shortTitle: this.getShortTitle(opt.id, opt.title),
				}))
			}
			return [
				{ id: 'shift_everything', letter: 'A', title: 'Shift everything', shortTitle: 'Shift all', description: 'All dependent tasks move the same amount.' },
				{ id: 'use_float', letter: 'B', title: 'Use available float', shortTitle: 'Use float', description: 'Available float is used first before tasks move.' },
				{ id: 'execute_in_parallel', letter: 'C', title: 'Execute in parallel', shortTitle: 'Parallel overlap', description: 'Start subsequent tasks with partial overlap.' },
				{ id: 'accelerate', letter: 'D', title: 'Accelerate', shortTitle: 'Accelerate', recommended: true, description: 'Reduce duration by adding extra capacity.' },
				{ id: 'keep_date', letter: 'E', title: 'Keep date, accept risk', shortTitle: 'Keep date', description: 'Target date unchanged, elevated schedule risk flagged.' },
				{ id: 'new_baseline', letter: 'F', title: 'Create new baseline', shortTitle: 'New baseline', description: 'Current projected schedule becomes the new official baseline.' },
			]
		},
		scenarioStatusClass() {
			const status = (this.scenario?.scenarioStatus || '').toLowerCase()
			if (status.includes('on track') || status.includes('early')) {
				return 'status--green'
			}
			if (status.includes('risk') || status.includes('delayed')) {
				return 'status--amber'
			}
			return 'status--blue'
		},
	},
	methods: {
		getShortTitle(id, title) {
			const map = {
				shift_everything: 'Shift all',
				use_float: 'Use float',
				execute_in_parallel: 'Parallel overlap',
				accelerate: 'Accelerate',
				keep_date: 'Keep date',
				new_baseline: 'New baseline',
			}
			return map[id] || title
		},
	},
}
</script>

<style scoped>
.simulation-banner {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 20px;
	padding: 16px 20px;
	background: linear-gradient(135deg, rgba(59, 130, 246, 0.08) 0%, rgba(139, 92, 246, 0.08) 100%);
	border: 1.5px dashed #3b82f6;
	border-radius: 16px;
	margin-bottom: 8px;
	box-shadow: 0 4px 16px rgba(59, 130, 246, 0.08);
	animation: fadeIn 0.25s ease-out;
}

@keyframes fadeIn {
	from { opacity: 0; transform: translateY(-6px); }
	to { opacity: 1; transform: translateY(0); }
}

.simulation-banner__left {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.simulation-badge {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	background: #3b82f6;
	color: #ffffff;
	padding: 3px 10px;
	border-radius: 99px;
	font-size: 10px;
	font-weight: 800;
	letter-spacing: 0.05em;
	width: fit-content;
}

.flask-icon {
	color: #ffffff;
}

.simulation-title {
	font-size: 14px;
	color: var(--color-main-text);
}

.simulation-strategies {
	display: flex;
	align-items: center;
	gap: 6px;
	margin: 4px 0 6px 0;
	flex-wrap: wrap;
}

.strategy-pill {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 4px 10px;
	border-radius: 9999px;
	font-size: 11px;
	font-weight: 600;
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	cursor: pointer;
	transition: all 0.15s ease-in-out;
}

.strategy-pill:hover:not(:disabled) {
	border-color: #4f46e5;
	color: #4f46e5;
}

.strategy-pill--active {
	background: #4f46e5 !important;
	color: #ffffff !important;
	border-color: #4f46e5 !important;
	box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.25);
}

.strategy-letter {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 16px;
	height: 16px;
	border-radius: 50%;
	background: rgba(0, 0, 0, 0.08);
	font-size: 10px;
	font-weight: 700;
}

.strategy-pill--active .strategy-letter {
	background: rgba(255, 255, 255, 0.25);
	color: #ffffff;
}

.strategy-rec-dot {
	color: #f59e0b;
	font-size: 10px;
}

.simulation-flow {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	padding: 8px 12px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 10px;
}

.flow-step {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.step-label {
	font-size: 10px;
	font-weight: 700;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
}

.step-val {
	font-size: 13px;
	font-weight: 800;
	color: var(--color-main-text);
}

.flow-step--current .step-val {
	color: #dc2626;
}

.flow-step--result .step-val {
	color: #059669;
}

.status--green .step-val {
	color: #059669;
}

.status--amber .step-val {
	color: #d97706;
}

.status--blue .step-val {
	color: #3b82f6;
}

.flow-arrow {
	color: var(--color-text-lighter);
	display: flex;
	align-items: center;
}

.simulation-disclaimer {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.simulation-banner__actions {
	display: flex;
	align-items: center;
	gap: 10px;
	flex-shrink: 0;
}

.apply-btn {
	background-color: #10b981 !important;
	border-color: #10b981 !important;
	color: #ffffff !important;
	font-weight: 700;
}

.apply-btn:hover {
	background-color: #059669 !important;
	border-color: #059669 !important;
}

.cancel-btn {
	font-weight: 600;
}
</style>
