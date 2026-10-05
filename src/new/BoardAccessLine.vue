<template>
	<div v-if="error && !access" class="pc-access-line pc-access-line--failed iz-app" role="alert">
		<ShieldAccount :size="16" class="pc-access-line__icon" />
		<p class="pc-access-line__text">
			{{ error }}
		</p>
		<button type="button" class="iz-btn iz-btn--sm" @click="$emit('retry')">
			Try again
		</button>
	</div>
	<div v-else-if="line" class="pc-access-line iz-app">
		<ShieldAccount :size="16" class="pc-access-line__icon" />
		<!-- One line, so the spaces inside the parts are kept. -->
		<p class="pc-access-line__text">
			{{ line.lead }}<strong v-if="line.who">{{ line.who }}</strong>{{ line.tail }}
		</p>
		<button type="button" class="pc-access-line__link" @click="$emit('open')">
			Who can do what<span aria-hidden="true"> ›</span>
		</button>
	</div>
</template>

<script>
import ShieldAccount from 'vue-material-design-icons/ShieldAccount.vue'
import { lineOf } from './board-access.js'

// One line above the embedded board in place of Deck's Permissions Overview:
// who is limited on some cards, and a way to the table in Card Permissions.
export default {
	name: 'BoardAccessLine',
	components: { ShieldAccount },
	props: {
		access: { type: Object, default: null },
		error: { type: String, default: '' },
	},
	computed: {
		line() { return this.access ? lineOf(this.access) : null },
	},
}
</script>

<style scoped>
.pc-access-line { display: flex; align-items: center; flex-wrap: wrap; gap: 6px 10px; margin: 0 0 14px; padding: 9px 14px; border-radius: var(--iz-radius); background: var(--iz-surface-subtle); font-size: var(--iz-fs-sm); color: var(--iz-text-secondary); }
.pc-access-line__icon { display: inline-flex; flex-shrink: 0; color: var(--iz-accent); }
.pc-access-line__text { flex: 1 1 320px; min-width: 0; margin: 0; }
.pc-access-line__text strong { font-weight: 600; color: var(--iz-text); }
.pc-access-line__link { min-height: 32px; margin: 0; padding: 0 4px; border: 0; border-radius: var(--iz-radius-sm); background: none; font: inherit; font-weight: 600; color: var(--iz-accent-bg-text); white-space: nowrap; cursor: pointer; }
.pc-access-line__link:hover { text-decoration: underline; }
.pc-access-line__link:focus-visible { outline: 2px solid var(--iz-accent); outline-offset: 2px; }
.pc-access-line--failed .pc-access-line__text { color: var(--iz-danger-text); }
</style>
