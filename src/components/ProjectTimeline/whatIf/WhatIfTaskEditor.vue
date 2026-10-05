<template>
	<NcModal size="small" label-id="whatif-editor-title" @close="$emit('close')">
		<div class="whatif-editor">
			<header class="whatif-editor__header">
				<h3 id="whatif-editor-title">
					{{ task.label }}
				</h3>
				<p>
					{{ formatDate(task.startDate) }} – {{ formatDate(task.endDate) }} · {{ formatDays(task.durationDays) }}
				</p>
				<p v-if="moved" class="whatif-editor__muted">
					Live plan: {{ formatDate(info.baselineStartDate) }} – {{ formatDate(info.baselineEndDate) }}
				</p>
				<p v-if="slackText" class="whatif-editor__slack" :class="{ 'whatif-editor__slack--critical': info.isCritical }">
					{{ slackText }}
				</p>
			</header>

			<p v-if="task.isDone" class="whatif-editor__muted">
				This card is completed, so its dates can’t change.
			</p>

			<template v-else>
				<section class="whatif-editor__section">
					<h4>Delay or finish earlier</h4>
					<div class="quick-buttons">
						<button v-for="days in quickDelays"
							:key="days"
							type="button"
							class="quick-button"
							@click="add({ type: 'delay', taskId: task.id, days })">
							{{ days > 0 ? '+' : '−' }}{{ formatDays(days) }}
						</button>
					</div>
					<form class="field-row" @submit.prevent="addDelay">
						<label>
							<span>Days (negative finishes earlier)</span>
							<input v-model.number="delayDays" type="number" step="1">
						</label>
						<NcButton native-type="submit" type="secondary" :disabled="!Number.isInteger(delayDays) || delayDays === 0">
							Add
						</NcButton>
					</form>
				</section>

				<section class="whatif-editor__section">
					<h4>Length or end date</h4>
					<form class="field-row" @submit.prevent="add({ type: 'duration', taskId: task.id, days: durationDays })">
						<label>
							<span>Length in days</span>
							<input v-model.number="durationDays"
								type="number"
								min="1"
								step="1">
						</label>
						<NcButton native-type="submit" type="secondary" :disabled="!Number.isInteger(durationDays) || durationDays < 1 || durationDays === task.durationDays">
							Set
						</NcButton>
					</form>
					<form class="field-row" @submit.prevent="add({ type: 'endDate', taskId: task.id, date: endDate })">
						<label>
							<span>Ends on</span>
							<input v-model="endDate" type="date">
						</label>
						<NcButton native-type="submit" type="secondary" :disabled="!endDate || endDate === task.endDate">
							Set
						</NcButton>
					</form>
				</section>

				<section class="whatif-editor__section">
					<h4>Can’t start before</h4>
					<form class="field-row" @submit.prevent="add({ type: 'startNotBefore', taskId: task.id, date: startLimit })">
						<label>
							<span>Earliest start</span>
							<input v-model="startLimit" type="date">
						</label>
						<NcButton native-type="submit" type="secondary" :disabled="!startLimit">
							Set
						</NcButton>
						<NcButton type="tertiary" @click="add({ type: 'startNotBefore', taskId: task.id, date: null })">
							Remove limit
						</NcButton>
					</form>
				</section>

				<section v-if="predecessors.length" class="whatif-editor__section">
					<h4>Start before the previous card ends</h4>
					<form
						v-for="predecessor in predecessors"
						:key="predecessor.id"
						class="field-row"
						@submit.prevent="addOverlap(predecessor)">
						<label>
							<span>Days before “{{ predecessor.label }}” ends</span>
							<input v-model.number="overlaps[predecessor.id]"
								type="number"
								min="0"
								step="1">
						</label>
						<NcButton native-type="submit" type="secondary" :disabled="!Number.isInteger(overlaps[predecessor.id]) || overlaps[predecessor.id] < 0 || overlaps[predecessor.id] === predecessor.overlapDays">
							Set
						</NcButton>
					</form>
				</section>
			</template>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal } from '@nextcloud/vue'

import { formatDays } from './whatIfChanges.js'

export default {
	name: 'WhatIfTaskEditor',
	components: {
		NcButton,
		NcModal,
	},
	props: {
		/** A scenario task, with its `whatIf` comparison */
		task: {
			type: Object,
			required: true,
		},
		/** [{ id, label, overlapDays }] for the cards this one waits for */
		predecessors: {
			type: Array,
			default: () => [],
		},
		formatDate: {
			type: Function,
			required: true,
		},
	},
	emits: ['add-change', 'close'],
	data() {
		return {
			quickDelays: [-7, 7, 14, 28],
			delayDays: 7,
			durationDays: this.task.durationDays,
			endDate: this.task.endDate,
			startLimit: '',
			overlaps: Object.fromEntries(this.predecessors.map(p => [p.id, p.overlapDays || 0])),
		}
	},
	computed: {
		info() {
			return this.task.whatIf || {}
		},
		moved() {
			return !!(this.info.startShiftDays || this.info.endShiftDays)
		},
		slackText() {
			if (this.task.isDone || this.info.floatDays === null || this.info.floatDays === undefined) {
				return ''
			}
			if (this.info.isCritical) {
				return 'On the critical path: any delay here moves the construction start.'
			}
			return `Can slip ${formatDays(this.info.floatDays)} before the construction start moves.`
		},
	},
	methods: {
		formatDays,
		add(change) {
			this.$emit('add-change', change)
			this.$emit('close')
		},
		addDelay() {
			this.add({ type: 'delay', taskId: this.task.id, days: this.delayDays })
		},
		addOverlap(predecessor) {
			this.add({ type: 'overlap', predecessorId: predecessor.id, successorId: this.task.id, days: this.overlaps[predecessor.id] })
		},
	},
}
</script>

<style scoped>
.whatif-editor {
	padding: 20px 24px 24px;
}

.whatif-editor__header h3 {
	margin: 0 0 4px;
	font-size: 18px;
	font-weight: 800;
}

.whatif-editor__header p {
	margin: 0;
	font-size: 13px;
}

.whatif-editor__muted {
	color: var(--color-text-maxcontrast);
}

.whatif-editor__slack {
	margin-top: 8px !important;
	padding: 6px 10px;
	border-radius: 8px;
	background: rgba(16, 185, 129, 0.1);
	color: #047857;
}

.whatif-editor__slack--critical {
	background: rgba(245, 158, 11, 0.12);
	color: #b45309;
}

.whatif-editor__section {
	margin-top: 16px;
}

.whatif-editor__section h4 {
	margin: 0 0 8px;
	font-size: 11px;
	font-weight: 800;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	color: var(--color-text-maxcontrast);
}

.quick-buttons {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-bottom: 8px;
}

.quick-button {
	padding: 4px 10px;
	border: 1px solid var(--color-border-dark);
	border-radius: 99px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-weight: 700;
	cursor: pointer;
}

.quick-button:hover,
.quick-button:focus-visible {
	border-color: var(--color-primary-element);
}

.field-row {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 8px;
	margin-bottom: 8px;
}

.field-row label {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.field-row input[type='number'] {
	width: 110px;
}
</style>
