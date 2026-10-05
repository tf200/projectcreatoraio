<template>
	<aside class="whatif-panel" aria-label="What-If scenario">
		<header class="whatif-panel__header">
			<div class="whatif-panel__title">
				<FlaskOutline :size="18" />
				<h4>What-If</h4>
				<NcLoadingIcon v-if="loading" :size="16" />
			</div>
			<p class="whatif-panel__hint">
				Try changes on a copy of the plan. Nothing changes in Deck until you apply.
			</p>
		</header>

		<div class="whatif-panel__body">
			<p v-if="error" class="whatif-panel__error" role="alert">
				{{ error }}
			</p>

			<!-- Impact -->
			<section v-if="planning" class="whatif-section">
				<h5>Impact</h5>
				<dl class="impact-list">
					<div class="impact-row">
						<dt>Earliest construction start</dt>
						<dd>
							<span>{{ formatDate(planning.minimumStartDate) }}</span>
							<span v-if="planning.minimumStartShiftDays" class="shift-chip" :class="shiftClass(planning.minimumStartShiftDays)">
								{{ formatShift(planning.minimumStartShiftDays) }}
							</span>
							<small v-if="planning.minimumStartShiftDays">was {{ formatDate(planning.baselineMinimumStartDate) }}</small>
						</dd>
					</div>
					<div class="impact-row">
						<dt>Desired start</dt>
						<dd v-if="planning.desiredStartDate">
							<span>{{ formatDate(planning.desiredStartDate) }}</span>
							<span class="state-chip" :class="planning.desiredStartAchievable ? 'state-chip--ok' : 'state-chip--bad'">
								{{ planning.desiredStartAchievable ? `Reachable, ${formatDays(planning.floatDays)} to spare` : `Missed by ${formatDays(planning.floatDays)}` }}
							</span>
							<small v-if="planning.baselineFloatDays !== null && planning.baselineFloatDays !== planning.floatDays">
								{{ planning.baselineDesiredStartAchievable ? `had ${formatDays(planning.baselineFloatDays)} to spare` : `was missed by ${formatDays(planning.baselineFloatDays)}` }}
							</small>
						</dd>
						<dd v-else class="impact-muted">
							Not set
						</dd>
					</div>
					<div class="impact-row">
						<dt>Cards that move</dt>
						<dd>{{ impact.movedTaskCount }}<small v-if="impact.deckCardUpdates.length"> · {{ impact.deckCardUpdates.length }} Deck {{ impact.deckCardUpdates.length === 1 ? 'card' : 'cards' }} to update</small></dd>
					</div>
					<div v-for="milestone in movedMilestones" :key="milestone.phaseCategory" class="impact-row">
						<dt>{{ milestone.label }}</dt>
						<dd>
							<span>{{ formatDate(milestone.date) }}</span>
							<span class="shift-chip" :class="shiftClass(milestone.shiftDays)">{{ formatShift(milestone.shiftDays) }}</span>
						</dd>
					</div>
				</dl>
				<ul class="legend">
					<li><span class="legend__swatch legend__swatch--ghost" /> Where a card sits in the live plan</li>
					<li><span class="legend__swatch legend__swatch--critical" /> Critical path: a delay here moves the start</li>
					<li><span class="legend__swatch legend__swatch--changed" /> Card you changed</li>
				</ul>
			</section>

			<!-- Changes -->
			<section class="whatif-section">
				<h5>Changes</h5>
				<p v-if="!changes.length" class="impact-muted">
					Click a card on the timeline to delay it, shorten it or overlap it with the card before it.
				</p>
				<ul v-else class="change-list">
					<li v-for="(change, index) in changes" :key="index" class="change-item">
						<span>{{ describe(change) }}</span>
						<button
							type="button"
							class="change-item__remove"
							:aria-label="`Undo: ${describe(change)}`"
							:title="'Undo this change'"
							@click="$emit('remove-change', index)">
							<Close :size="16" />
						</button>
					</li>
				</ul>

				<details class="planning-try">
					<summary>Try a different desired start or preparation time</summary>
					<div class="planning-try__fields">
						<label>
							<span>Desired start</span>
							<input v-model="planningDraft.desiredStartDate" type="date">
						</label>
						<label>
							<span>Preparation (weeks)</span>
							<input v-model.number="planningDraft.requiredPreparationWeeks"
								type="number"
								min="0"
								max="520">
						</label>
						<NcButton type="secondary" :disabled="!planningDraftChanged" @click="onTryPlanning">
							Try it
						</NcButton>
					</div>
				</details>
			</section>

			<slot name="saved" />

			<!-- Fixes -->
			<section v-if="slipDays > 0" class="whatif-section">
				<h5>Ways to win back {{ formatDays(slipDays) }}</h5>
				<div v-if="suggestionsLoading" class="impact-muted">
					<NcLoadingIcon :size="16" /> Looking for fixes…
				</div>
				<p v-else-if="!suggestions.length" class="impact-muted">
					No fix found. Shortening and overlapping are limited to half a card’s length; you can go further by hand.
				</p>
				<ul v-else class="fix-list">
					<li v-for="(fix, index) in suggestions" :key="index" class="fix-item">
						<div class="fix-item__text">
							<strong>{{ fix.changes.map(describe).join(' + ') }}</strong>
							<span :class="fix.recoversFully ? 'fix-item__result--full' : 'fix-item__result--partial'">
								{{ fix.recoversFully ? `Wins back all ${formatDays(fix.recoveredDays)}` : `Wins back ${formatDays(fix.recoveredDays)} of ${formatDays(slipDays)}` }}
							</span>
						</div>
						<NcButton class="fix-item__try" type="secondary" @click="$emit('add-fix', fix)">
							Try it
						</NcButton>
					</li>
				</ul>
			</section>
		</div>

		<footer class="whatif-panel__footer">
			<NcButton
				type="primary"
				:disabled="!changes.length || loading || !!error || !canApply"
				:title="canApply ? '' : 'Only timeline managers can apply a scenario'"
				@click="$emit('apply')">
				<template #icon>
					<Check :size="16" />
				</template>
				Apply to plan
			</NcButton>
			<NcButton type="tertiary" @click="$emit('discard')">
				Discard
			</NcButton>
		</footer>
	</aside>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import FlaskOutline from 'vue-material-design-icons/FlaskOutline.vue'

import { describeChange, formatDays } from './whatIfChanges.js'

export default {
	name: 'WhatIfPanel',
	components: {
		NcButton,
		NcLoadingIcon,
		Check,
		Close,
		FlaskOutline,
	},
	props: {
		/** Scenario response from the server */
		result: {
			type: Object,
			default: null,
		},
		changes: {
			type: Array,
			required: true,
		},
		fixes: {
			type: Object,
			default: null,
		},
		loading: {
			type: Boolean,
			default: false,
		},
		suggestionsLoading: {
			type: Boolean,
			default: false,
		},
		error: {
			type: String,
			default: '',
		},
		canApply: {
			type: Boolean,
			default: false,
		},
		labelOf: {
			type: Function,
			required: true,
		},
		formatDate: {
			type: Function,
			required: true,
		},
	},
	emits: ['remove-change', 'add-change', 'add-fix', 'apply', 'discard'],
	data() {
		return {
			planningDraft: { desiredStartDate: '', requiredPreparationWeeks: 0 },
		}
	},
	computed: {
		impact() {
			return this.result?.impact || { movedTaskCount: 0, deckCardUpdates: [], milestones: [] }
		},
		planning() {
			return this.result?.impact?.planning || null
		},
		movedMilestones() {
			return (this.impact.milestones || []).filter(m => m.shiftDays !== 0)
		},
		slipDays() {
			return this.fixes?.slipDays || 0
		},
		suggestions() {
			return this.fixes?.suggestions || []
		},
		planningDraftChanged() {
			if (!this.planning) return false
			return (this.planningDraft.desiredStartDate || null) !== (this.planning.desiredStartDate || null)
				|| Number(this.planningDraft.requiredPreparationWeeks) !== Number(this.planning.preparationWeeks)
		},
	},
	watch: {
		planning: {
			handler(planning) {
				if (planning) {
					this.planningDraft = {
						desiredStartDate: planning.desiredStartDate || '',
						requiredPreparationWeeks: planning.preparationWeeks,
					}
				}
			},
			immediate: true,
		},
	},
	methods: {
		formatDays,
		describe(change) {
			return describeChange(change, this.labelOf, this.formatDate)
		},
		formatShift(days) {
			return `${days > 0 ? '+' : '−'}${formatDays(days)}`
		},
		shiftClass(days) {
			return days > 0 ? 'shift-chip--later' : 'shift-chip--earlier'
		},
		onTryPlanning() {
			const change = { type: 'planning' }
			if ((this.planningDraft.desiredStartDate || null) !== (this.planning.desiredStartDate || null)) {
				change.desiredStartDate = this.planningDraft.desiredStartDate || null
			}
			const weeks = Number(this.planningDraft.requiredPreparationWeeks)
			if (Number.isInteger(weeks) && weeks >= 0 && weeks !== Number(this.planning.preparationWeeks)) {
				change.requiredPreparationWeeks = weeks
			}
			if (Object.keys(change).length > 1) {
				this.$emit('add-change', change)
			}
		},
	},
}
</script>

<style scoped>
.whatif-panel {
	display: flex;
	flex-direction: column;
	width: 360px;
	flex-shrink: 0;
	max-height: calc(100vh - var(--header-height, 50px) - 24px);
	border-left: 1px solid var(--color-border);
	background: var(--color-main-background);
}

.whatif-panel__header {
	padding: 14px 16px 10px;
	border-bottom: 1px solid var(--color-border);
	background: rgba(59, 130, 246, 0.06);
}

.whatif-panel__title {
	display: flex;
	align-items: center;
	gap: 8px;
	color: #2563eb;
}

.whatif-panel__title h4 {
	margin: 0;
	font-size: 16px;
	font-weight: 800;
}

.whatif-panel__hint {
	margin: 4px 0 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.whatif-panel__body {
	flex: 1;
	overflow-y: auto;
	padding: 4px 16px 12px;
}

.whatif-panel__error {
	margin: 12px 0 0;
	padding: 8px 10px;
	border-radius: 8px;
	background: rgba(239, 68, 68, 0.1);
	color: var(--color-error-text, #b91c1c);
	font-size: 13px;
}

.whatif-section {
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);
}

.whatif-section:last-child {
	border-bottom: none;
}

.whatif-section h5 {
	margin: 0 0 8px;
	font-size: 11px;
	font-weight: 800;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	color: var(--color-text-maxcontrast);
}

.impact-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0;
}

.impact-row dt {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.impact-row dd {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
	margin: 2px 0 0;
	font-size: 14px;
	font-weight: 700;
}

.impact-row small {
	font-size: 12px;
	font-weight: 400;
	color: var(--color-text-maxcontrast);
}

.impact-muted {
	display: flex;
	align-items: center;
	gap: 6px;
	margin: 0;
	font-size: 13px;
	font-weight: 400;
	color: var(--color-text-maxcontrast);
}

.shift-chip,
.state-chip {
	padding: 1px 8px;
	border-radius: 99px;
	font-size: 11px;
	font-weight: 800;
}

.shift-chip--later,
.state-chip--bad {
	background: rgba(239, 68, 68, 0.12);
	color: #dc2626;
}

.shift-chip--earlier,
.state-chip--ok {
	background: rgba(16, 185, 129, 0.12);
	color: #047857;
}

.legend {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 12px 0 0;
	padding: 0;
	list-style: none;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.legend li {
	display: flex;
	align-items: center;
	gap: 8px;
}

.legend__swatch {
	width: 22px;
	height: 10px;
	flex-shrink: 0;
	border-radius: 3px;
	background: var(--color-primary-element);
}

.legend__swatch--ghost {
	border: 1.5px dashed #94a3b8;
	background: rgba(148, 163, 184, 0.12);
}

.legend__swatch--critical {
	box-shadow: 0 0 0 2px #f59e0b;
}

.legend__swatch--changed {
	outline: 2px dashed #2563eb;
	outline-offset: 1px;
}

.change-list,
.fix-list {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.change-item {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 8px;
	padding: 8px 10px;
	border-radius: 8px;
	background: var(--color-background-dark);
	font-size: 13px;
	line-height: 1.4;
}

.change-item__remove {
	display: flex;
	flex-shrink: 0;
	padding: 2px;
	border: none;
	border-radius: 6px;
	background: none;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}

.change-item__remove:hover,
.change-item__remove:focus-visible {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.planning-try {
	margin-top: 10px;
	font-size: 13px;
}

.planning-try summary {
	cursor: pointer;
	color: var(--color-text-maxcontrast);
}

.planning-try__fields {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 8px;
	margin-top: 8px;
}

.planning-try__fields label {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.planning-try__fields input[type='number'] {
	width: 90px;
}

.fix-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 10px;
	padding: 10px;
	border: 1px solid var(--color-border);
	border-radius: 10px;
}

.fix-item__try {
	flex-shrink: 0;
}

.fix-item__text {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 13px;
	line-height: 1.35;
}

.fix-item__result--full {
	font-size: 12px;
	color: #047857;
}

.fix-item__result--partial {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.whatif-panel__footer {
	display: flex;
	gap: 8px;
	padding: 12px 16px;
	border-top: 1px solid var(--color-border);
}

@media (max-width: 900px) {
	.whatif-panel {
		width: auto;
		max-height: none;
		border-left: none;
		border-top: 1px solid var(--color-border);
	}
}
</style>
