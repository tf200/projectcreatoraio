<template>
	<section class="whatif-saved">
		<div class="whatif-saved__heading">
			<h5>{{ t('Saved scenarios') }}</h5>
			<NcButton v-if="scenarios.length" type="tertiary" @click="$emit('compare')">
				<template #icon>
					<CompareHorizontal :size="16" />
				</template>
				{{ t('Compare') }}
			</NcButton>
		</div>

		<form v-if="hasChanges" class="whatif-saved__save" @submit.prevent="onSave">
			<label class="hidden-visually" for="whatif-scenario-name">{{ t('Scenario name') }}</label>
			<input
				id="whatif-scenario-name"
				v-model="name"
				type="text"
				maxlength="120"
				:placeholder="t('Name this scenario')">
			<NcButton native-type="submit" type="secondary" :disabled="!name.trim() || saving">
				{{ t('Save') }}
			</NcButton>
		</form>
		<NcButton
			v-if="hasChanges && activeModified && activeScenario && activeScenario.canEdit"
			type="tertiary"
			:title="t('Overwrite “{name}” with the changes open now', { name: activeScenario.name })"
			:disabled="saving"
			@click="$emit('update', activeScenario)">
			{{ t('Save changes to the open scenario') }}
		</NcButton>

		<p v-if="loading && !scenarios.length" class="whatif-saved__muted">
			<NcLoadingIcon :size="16" /> {{ t('Loading saved scenarios…') }}
		</p>
		<p v-else-if="!scenarios.length" class="whatif-saved__muted">
			{{ t('Save a scenario to come back to it later or compare it with others.') }}
		</p>
		<ul v-else class="whatif-saved__list">
			<li
				v-for="scenario in scenarios"
				:key="scenario.id"
				class="saved-item"
				:class="{ 'saved-item--active': scenario.id === activeId }">
				<div class="saved-item__text">
					<strong>{{ scenario.name }}</strong>
					<small>{{ t('by {name}', { name: scenario.createdByDisplayName }) }}<template v-if="scenario.id === activeId"> · {{ activeModified ? t('open, with changes') : t('open now') }}</template></small>
					<span v-if="scenario.error" class="saved-item__error">{{ scenario.error }}</span>
					<span v-else-if="scenario.impact" class="saved-item__impact">
						{{ t('Start {date}', { date: formatDate(scenario.impact.minimumStartDate) }) }}
						<template v-if="scenario.impact.minimumStartShiftDays">({{ formatShift(scenario.impact.minimumStartShiftDays) }})</template>
						<template v-if="scenario.impact.desiredStartAchievable !== null">
							· {{ scenario.impact.desiredStartAchievable ? t('desired start reachable') : t('desired start missed') }}
						</template>
					</span>
				</div>
				<div class="saved-item__actions">
					<template v-if="pendingDeleteId === scenario.id">
						<NcButton type="error" @click="confirmDelete(scenario)">
							{{ t('Delete') }}
						</NcButton>
						<NcButton type="tertiary" @click="pendingDeleteId = null">
							{{ t('Keep') }}
						</NcButton>
					</template>
					<template v-else>
						<NcButton type="tertiary" :disabled="scenario.id === activeId" @click="$emit('load', scenario)">
							{{ t('Open') }}
						</NcButton>
						<NcButton
							v-if="scenario.canEdit"
							type="tertiary"
							:aria-label="t('Delete {name}', { name: scenario.name })"
							:title="t('Delete')"
							@click="pendingDeleteId = scenario.id">
							<template #icon>
								<Delete :size="16" />
							</template>
						</NcButton>
					</template>
				</div>
			</li>
		</ul>
	</section>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import CompareHorizontal from 'vue-material-design-icons/CompareHorizontal.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

import { t, n, tc } from '../../../new/l10n.mjs'
import { formatShiftBadge } from './whatIfChanges.js'

export default {
	name: 'WhatIfSavedScenarios',
	components: {
		NcButton,
		NcLoadingIcon,
		CompareHorizontal,
		Delete,
	},
	props: {
		scenarios: {
			type: Array,
			default: () => [],
		},
		activeId: {
			type: Number,
			default: null,
		},
		hasChanges: {
			type: Boolean,
			default: false,
		},
		/** The open changes differ from the open saved scenario */
		activeModified: {
			type: Boolean,
			default: true,
		},
		loading: {
			type: Boolean,
			default: false,
		},
		saving: {
			type: Boolean,
			default: false,
		},
		formatDate: {
			type: Function,
			required: true,
		},
	},
	emits: ['save', 'update', 'load', 'delete', 'compare'],
	data() {
		return {
			name: '',
			pendingDeleteId: null,
		}
	},
	computed: {
		activeScenario() {
			return this.scenarios.find(scenario => scenario.id === this.activeId) || null
		},
	},
	methods: {
		// Own t/n/tc so the template works under both entry points (the legacy mixin has another signature).
		t,
		n,
		tc,
		formatShift: formatShiftBadge,
		onSave() {
			this.$emit('save', this.name.trim())
			this.name = ''
		},
		confirmDelete(scenario) {
			this.pendingDeleteId = null
			this.$emit('delete', scenario)
		},
	},
}
</script>

<style scoped>
.whatif-saved {
	padding: 12px 0;
}

.whatif-saved__heading {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.whatif-saved h5 {
	margin: 0;
	font-size: 11px;
	font-weight: 800;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	color: var(--color-text-maxcontrast);
}

.whatif-saved__save {
	display: flex;
	gap: 8px;
	margin: 8px 0 4px;
}

.whatif-saved__save input {
	flex: 1;
	min-width: 0;
}

.whatif-saved__muted {
	display: flex;
	align-items: center;
	gap: 6px;
	margin: 8px 0 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.whatif-saved__list {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin: 8px 0 0;
	padding: 0;
	list-style: none;
}

.saved-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: 10px;
}

.saved-item--active {
	border-color: #2563eb;
	background: rgba(37, 99, 235, 0.06);
}

.saved-item__text {
	display: flex;
	flex-direction: column;
	gap: 1px;
	min-width: 0;
	font-size: 13px;
}

.saved-item__text strong {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.saved-item__text small {
	color: var(--color-text-maxcontrast);
}

.saved-item__impact {
	font-size: 12px;
}

.saved-item__error {
	font-size: 12px;
	color: var(--color-error-text, #b91c1c);
}

.saved-item__actions {
	display: flex;
	flex-shrink: 0;
	gap: 2px;
}
</style>
