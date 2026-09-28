<template>
	<div class="pc-role-chips">
		<span :id="labelId" class="iz-label pc-role-chips__label">{{ label }}</span>
		<div class="pc-role-chips__list" role="group" :aria-labelledby="labelId">
			<button v-for="option in options"
				:key="option.value"
				type="button"
				class="iz-chip"
				:class="{ 'iz-chip--active': selected.includes(option.value) }"
				:aria-pressed="String(selected.includes(option.value))"
				:disabled="disabled"
				@click="$emit('toggle', option.value)">
				<span v-if="option.tone" class="iz-dot" :class="'pc-tone-dot--' + option.tone" />
				{{ option.label }}
			</button>
		</div>
	</div>
</template>

<script>
// One toggle group of roles, used for DRASCIVS and for project roles, both when
// adding a member and when editing one.
export default {
	name: 'RoleChips',
	props: {
		label: { type: String, required: true },
		labelId: { type: String, required: true },
		options: { type: Array, required: true },
		selected: { type: Array, required: true },
		disabled: { type: Boolean, default: false },
	},
}
</script>

<style scoped>
.pc-role-chips { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.pc-role-chips__label { margin: 0; }
.pc-role-chips__list { display: flex; flex-wrap: wrap; gap: 6px; }
.pc-role-chips__list .iz-chip:disabled { cursor: default; opacity: 0.6; }
.pc-tone-dot--1 { color: var(--iz-cat-1); }
.pc-tone-dot--2 { color: var(--iz-cat-2); }
.pc-tone-dot--3 { color: var(--iz-cat-3); }
.pc-tone-dot--4 { color: var(--iz-cat-4); }
.pc-tone-dot--5 { color: var(--iz-cat-5); }
</style>
