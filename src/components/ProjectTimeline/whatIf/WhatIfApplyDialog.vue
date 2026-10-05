<template>
	<NcModal size="normal" label-id="whatif-apply-title" @close="$emit('close')">
		<div class="whatif-apply">
			<h3 id="whatif-apply-title">
				Apply this scenario to the plan?
			</h3>
			<p>
				The scenario becomes the project plan. Everyone on the project sees the new dates.
			</p>

			<section>
				<h4>Changes</h4>
				<ul>
					<li v-for="(change, index) in changes" :key="index">
						{{ describe(change) }}
					</li>
				</ul>
			</section>

			<section v-if="cardUpdates.length">
				<h4>{{ cardUpdates.length }} Deck {{ cardUpdates.length === 1 ? 'card gets' : 'cards get' }} new dates</h4>
				<table class="whatif-apply__cards">
					<thead>
						<tr>
							<th scope="col">
								Card
							</th>
							<th scope="col">
								Now
							</th>
							<th scope="col">
								After
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="update in cardUpdates" :key="update.cardId">
							<td>{{ update.label }}</td>
							<td>{{ formatDate(update.fromStartDate) }} – {{ formatDate(update.fromEndDate) }}</td>
							<td>{{ formatDate(update.toStartDate) }} – {{ formatDate(update.toEndDate) }}</td>
						</tr>
					</tbody>
				</table>
			</section>
			<p v-else class="whatif-apply__muted">
				No Deck card dates change.
			</p>

			<footer>
				<NcButton type="tertiary" :disabled="applying" @click="$emit('close')">
					Cancel
				</NcButton>
				<NcButton type="primary" :disabled="applying" @click="$emit('confirm')">
					{{ applying ? 'Applying…' : 'Apply to plan' }}
				</NcButton>
			</footer>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal } from '@nextcloud/vue'

import { describeChange } from './whatIfChanges.js'

export default {
	name: 'WhatIfApplyDialog',
	components: {
		NcButton,
		NcModal,
	},
	props: {
		changes: {
			type: Array,
			required: true,
		},
		cardUpdates: {
			type: Array,
			required: true,
		},
		applying: {
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
	emits: ['confirm', 'close'],
	methods: {
		describe(change) {
			return describeChange(change, this.labelOf, this.formatDate)
		},
	},
}
</script>

<style scoped>
.whatif-apply {
	padding: 20px 24px 24px;
}

.whatif-apply h3 {
	margin: 0 0 6px;
	font-size: 18px;
	font-weight: 800;
}

.whatif-apply p {
	margin: 0;
	font-size: 14px;
}

.whatif-apply section {
	margin-top: 16px;
}

.whatif-apply h4 {
	margin: 0 0 6px;
	font-size: 12px;
	font-weight: 800;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	color: var(--color-text-maxcontrast);
}

.whatif-apply ul {
	margin: 0;
	padding-left: 18px;
	font-size: 14px;
}

.whatif-apply__cards {
	width: 100%;
	border-collapse: collapse;
	font-size: 13px;
}

.whatif-apply__cards th,
.whatif-apply__cards td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
}

.whatif-apply__cards th {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.whatif-apply__muted {
	margin-top: 16px !important;
	color: var(--color-text-maxcontrast);
}

.whatif-apply footer {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 20px;
}
</style>
