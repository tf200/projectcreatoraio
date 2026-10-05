<template>
	<NcModal size="large" label-id="whatif-compare-title" @close="$emit('close')">
		<div class="whatif-compare">
			<h3 id="whatif-compare-title">
				Compare scenarios
			</h3>
			<p class="whatif-compare__muted">
				Each scenario is recalculated on today’s plan.
			</p>

			<div class="whatif-compare__scroll">
				<table>
					<thead>
						<tr>
							<th scope="col" />
							<th v-for="column in columns"
								:key="column.key"
								scope="col"
								:class="{ 'is-best': column.key === bestKey }">
								{{ column.name }}
								<small v-if="column.subtitle">{{ column.subtitle }}</small>
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in rows" :key="row.key">
							<th scope="row">
								{{ row.label }}
							</th>
							<td v-for="column in columns" :key="column.key" :class="{ 'is-best': column.key === bestKey }">
								<span v-if="column.error && row.key === rows[0].key" class="whatif-compare__error">{{ column.error }}</span>
								<template v-else-if="column.impact">
									{{ row.value(column.impact) }}
								</template>
							</td>
						</tr>
					</tbody>
					<tfoot v-if="columns.some(column => column.scenario)">
						<tr>
							<th scope="row" />
							<td v-for="column in columns" :key="column.key">
								<NcButton v-if="column.scenario && !column.error" type="secondary" @click="$emit('load', column.scenario)">
									Open
								</NcButton>
							</td>
						</tr>
					</tfoot>
				</table>
			</div>
			<p v-if="bestKey" class="whatif-compare__muted">
				Highlighted: the earliest construction start.
			</p>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal } from '@nextcloud/vue'

import { formatDays, formatShiftBadge } from './whatIfChanges.js'

export default {
	name: 'WhatIfCompareDialog',
	components: {
		NcButton,
		NcModal,
	},
	props: {
		livePlan: {
			type: Object,
			default: null,
		},
		/** Headline numbers of the scenario open now, when it has changes */
		current: {
			type: Object,
			default: null,
		},
		scenarios: {
			type: Array,
			default: () => [],
		},
		formatDate: {
			type: Function,
			required: true,
		},
	},
	emits: ['load', 'close'],
	computed: {
		columns() {
			const columns = []
			if (this.livePlan) {
				columns.push({ key: 'live', name: 'Live plan', impact: this.livePlan })
			}
			if (this.current) {
				columns.push({ key: 'current', name: 'Open now', subtitle: 'not saved', impact: this.current })
			}
			for (const scenario of this.scenarios) {
				columns.push({ key: `saved-${scenario.id}`, name: scenario.name, subtitle: `by ${scenario.createdByDisplayName}`, impact: scenario.impact, error: scenario.error, scenario })
			}
			return columns
		},
		/** The column with the earliest construction start, when it beats the live plan */
		bestKey() {
			let best = null
			for (const column of this.columns) {
				if (column.impact && (best === null || column.impact.minimumStartDate < best.impact.minimumStartDate)) {
					best = column
				}
			}
			return best && best.key !== 'live' && best.impact.minimumStartDate < (this.livePlan?.minimumStartDate || '9999') ? best.key : null
		},
		rows() {
			const shift = days => days ? ` (${formatShiftBadge(days)})` : ''
			return [
				{ key: 'start', label: 'Earliest construction start', value: i => `${this.formatDate(i.minimumStartDate)}${shift(i.minimumStartShiftDays)}` },
				{ key: 'desired', label: 'Desired start', value: i => i.desiredStartDate ? this.formatDate(i.desiredStartDate) : 'Not set' },
				{
					key: 'reachable',
					label: 'Desired start reachable',
					value: i => {
						if (i.desiredStartAchievable === null) return '–'
						return i.desiredStartAchievable ? `Yes, ${formatDays(i.floatDays)} to spare` : `No, missed by ${formatDays(i.floatDays)}`
					},
				},
				{ key: 'prep', label: 'Preparation', value: i => formatDays(i.preparationWeeks * 7) },
				{ key: 'moved', label: 'Cards that move', value: i => String(i.movedTaskCount) },
				{ key: 'deck', label: 'Deck cards to update', value: i => String(i.deckCardUpdateCount) },
				{ key: 'changes', label: 'Changes', value: i => String(i.changeCount) },
			]
		},
	},
}
</script>

<style scoped>
.whatif-compare {
	padding: 20px 24px 24px;
}

.whatif-compare h3 {
	margin: 0 0 4px;
	font-size: 18px;
	font-weight: 800;
}

.whatif-compare__muted {
	margin: 0 0 12px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.whatif-compare__scroll {
	overflow-x: auto;
}

table {
	width: 100%;
	border-collapse: collapse;
	font-size: 13px;
}

th,
td {
	padding: 8px 10px;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
	vertical-align: top;
}

thead th {
	min-width: 140px;
	font-weight: 800;
}

thead th small {
	display: block;
	font-weight: 400;
	color: var(--color-text-maxcontrast);
}

tbody th {
	font-weight: 400;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.is-best {
	background: rgba(16, 185, 129, 0.08);
}

tfoot td,
tfoot th {
	border-bottom: none;
}

.whatif-compare__error {
	color: var(--color-error-text, #b91c1c);
}
</style>
