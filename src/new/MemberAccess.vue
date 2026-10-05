<template>
	<section class="pc-access iz-app iz-panel" aria-labelledby="pc-access-title">
		<header class="pc-access__head">
			<h3 id="pc-access-title"
				ref="title"
				class="pc-access__title"
				tabindex="-1">
				Who can do what
			</h3>
			<p v-if="access && access.total" class="pc-access__sub">
				How many of the board’s {{ cardsText(access.total) }} {{ self ? 'you' : 'each member' }} may view, move, verify and sign.
			</p>
		</header>

		<div v-if="error && !access" class="iz-empty pc-view__failure" role="alert">
			<span>{{ error }}</span>
			<button type="button" class="iz-btn" @click="$emit('retry')">
				Try again
			</button>
		</div>
		<div v-else-if="!access" class="iz-empty" role="status">
			Loading who can do what…
		</div>
		<p v-else-if="!access.members.length" class="iz-empty">
			{{ self ? 'You are not a member of this project.' : 'This project has no members yet.' }}
		</p>
		<p v-else-if="!access.total" class="iz-empty">
			There are no cards on the board yet.
		</p>
		<div v-else class="iz-table-wrap pc-access__wrap">
			<table class="iz-table pc-access__table">
				<thead>
					<tr>
						<th scope="col">
							Member
						</th>
						<th v-for="action in actions"
							:key="action.key"
							scope="col"
							class="pc-access__num">
							{{ action.label }}
						</th>
						<th scope="col" class="pc-access__end">
							<span class="pc-sr-only">Cards</span>
						</th>
					</tr>
				</thead>
				<tbody>
					<template v-for="member in access.members">
						<tr :key="member.id" class="pc-access__row">
							<th scope="row" class="pc-access__member">
								<span class="pc-access__who">
									<NcAvatar :user="member.id"
										:display-name="member.displayName"
										:size="28"
										:show-user-status="false"
										:disable-menu="true" />
									<span class="pc-access__names">
										<span class="pc-access__name">
											{{ member.displayName }}
											<span v-if="member.isOwner" class="iz-pill iz-pill--accent pc-access__tag">Owner</span>
											<span v-if="member.boardAccess === 'read'" class="iz-pill iz-pill--muted pc-access__tag">Read only</span>
											<span v-else-if="member.boardAccess === 'none'" class="iz-pill iz-pill--danger pc-access__tag">No board access</span>
										</span>
										<span v-if="member.roles.length" class="pc-access__roles">{{ member.roles.join(' · ') }}</span>
									</span>
								</span>
							</th>
							<td v-for="action in actions"
								:key="action.key"
								class="pc-access__num"
								:data-label="action.label">
								<span class="iz-pill pc-access__cell" :class="'iz-pill--' + cellOf(member.actions[action.key]).tone">
									<span class="pc-sr-only">{{ action.label }}: </span>{{ cellOf(member.actions[action.key]).text }}
								</span>
							</td>
							<td class="pc-access__end">
								<button v-if="member.limited"
									type="button"
									class="iz-btn iz-btn--sm"
									:aria-expanded="String(isOpen(member))"
									:aria-controls="detailId(member)"
									@click="toggle(member)">
									{{ isOpen(member) ? 'Hide cards' : 'Show cards' }}
								</button>
							</td>
						</tr>
						<tr v-if="isOpen(member)"
							:id="detailId(member)"
							:key="member.id + ':cards'"
							class="pc-access__detail">
							<td colspan="6">
								<p v-if="member.none.length" class="pc-access__none">
									{{ noneText(member, self) }}
								</p>
								<p v-if="member.missing.length" class="pc-access__detail-title">
									{{ self ? 'Cards you can’t act on' : 'Cards ' + member.displayName + ' can’t act on' }}
								</p>
								<ul v-if="member.missing.length" class="pc-access__cards">
									<li v-for="card in member.missing" :key="card.id">
										<span class="pc-access__card">{{ card.title }}</span>
										<span class="pc-access__what">{{ missingText(card.actions) }}</span>
									</li>
								</ul>
								<p v-if="access.unnamed" class="pc-access__unnamed">
									{{ member.missing.length || member.none.length ? 'And ' : '' }}{{ cardsText(access.unnamed) }} {{ self ? 'you can’t see' : 'no one listed here can see' }}.
								</p>
							</td>
						</tr>
					</template>
				</tbody>
			</table>
		</div>
		<p v-if="error && access" class="pc-access__stale" role="alert">
			{{ error }}
			<button type="button" class="iz-btn iz-btn--sm" @click="$emit('retry')">
				Try again
			</button>
		</p>
	</section>
</template>

<script>
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import { ACTIONS, cardsText, cellOf, missingText, noneText } from './board-access.js'

// The table at the top of Card Permissions in the new layout: how many cards
// each member may view, move, verify and sign, and which ones they can't.
// A member who doesn't manage the project only gets their own row.
export default {
	name: 'MemberAccess',
	components: { NcAvatar },
	props: {
		access: { type: Object, default: null },
		error: { type: String, default: '' },
	},
	data() {
		return { open: [] }
	},
	computed: {
		actions() { return ACTIONS },
		self() { return this.access?.scope === 'self' },
	},
	methods: {
		cardsText,
		cellOf,
		missingText,
		noneText,
		isOpen(member) { return this.open.includes(member.id) },
		toggle(member) {
			this.open = this.isOpen(member) ? this.open.filter(id => id !== member.id) : [...this.open, member.id]
		},
		detailId(member) { return 'pc-access-cards-' + encodeURIComponent(member.id).replace(/[^A-Za-z0-9_-]/g, '_') },
		// The Task Board's "Who can do what" lands here.
		focus() {
			const title = this.$refs.title
			if (!title) return
			title.focus({ preventScroll: true })
			title.scrollIntoView({ block: 'start', inline: 'nearest', behavior: 'smooth' })
		},
	},
}
</script>

<style scoped>
.pc-access { display: flex; flex-direction: column; gap: 14px; margin: 0 0 var(--iz-gap); border: 1px solid var(--iz-border); container: pc-access / inline-size; }
.pc-access__head { display: flex; flex-direction: column; gap: 4px; }
.pc-access__title { margin: 0; font-family: 'Space Grotesk', system-ui, -apple-system, sans-serif; font-size: var(--iz-fs-lg); font-weight: 600; color: var(--iz-text); scroll-margin-top: 80px; }
.pc-access__title:focus { outline: none; }
.pc-access__title:focus-visible { outline: 2px solid var(--iz-accent); outline-offset: 4px; border-radius: var(--iz-radius-sm); }
.pc-access__sub { margin: 0; font-size: var(--iz-fs-sm); color: var(--iz-text-secondary); }
.pc-access__stale { display: flex; align-items: center; gap: var(--iz-gap-tight); margin: 0; font-size: var(--iz-fs-sm); color: var(--iz-danger-text); }

.pc-access__table th, .pc-access__table td { vertical-align: middle; }
.pc-access__table td { white-space: normal; }
.pc-access__table tbody tr:last-child > * { border-bottom: 0; }
.pc-access__table tbody th { background: none; font-size: inherit; font-weight: inherit; text-transform: none; letter-spacing: normal; color: var(--iz-text); white-space: normal; }
.pc-access__table .pc-access__num { width: 112px; text-align: center; }
.pc-access__table .pc-access__end { width: 128px; text-align: end; }
.pc-access__who { display: flex; align-items: center; gap: 10px; min-width: 0; }
.pc-access__names { display: flex; flex-direction: column; min-width: 0; }
.pc-access__name { display: flex; align-items: center; flex-wrap: wrap; gap: 4px 6px; font-weight: 600; overflow-wrap: anywhere; }
.pc-access__roles { font-size: var(--iz-fs-xs); color: var(--iz-text-secondary); }
.pc-access .iz-pill { text-transform: none; letter-spacing: normal; }
.pc-access__cell { min-width: 74px; justify-content: center; font-variant-numeric: tabular-nums; }

.pc-access__table tbody tr.pc-access__detail,
.pc-access__table tbody tr.pc-access__detail:hover { background: var(--iz-surface-subtle); }
.pc-access__none { margin: 0 0 8px; font-size: var(--iz-fs-sm); color: var(--iz-text); }
.pc-access__none + .pc-access__detail-title { margin-top: 12px; }
.pc-access__detail-title { margin: 0 0 8px; font-size: var(--iz-fs-sm); font-weight: 600; color: var(--iz-text); }
.pc-access__cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 280px), 1fr)); gap: 6px 12px; margin: 0; padding: 0; list-style: none; }
.pc-access__cards li { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; gap: 2px 12px; padding: 6px 10px; border-radius: var(--iz-radius-sm); background: var(--iz-surface); font-size: var(--iz-fs-sm); }
.pc-access__card { min-width: 0; color: var(--iz-text); overflow-wrap: break-word; }
.pc-access__what { flex-shrink: 0; color: var(--iz-text-secondary); }
.pc-access__unnamed { margin: 8px 0 0; font-size: var(--iz-fs-sm); color: var(--iz-text-secondary); }

/* A narrow screen gets a block per member: name and roles, then the four counts. */
@container pc-access (max-width: 640px) {
	.pc-access__table, .pc-access__table tbody { display: block; }
	.pc-access__table thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
	.pc-access__table tbody tr.pc-access__row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px 8px; padding: 12px; border-bottom: 1px solid var(--iz-border); }
	.pc-access__table tbody tr.pc-access__row > * { display: block; width: auto; padding: 0; border: 0; }
	.pc-access__table tbody .pc-access__member { grid-column: 1 / -1; }
	.pc-access__table tbody tr.pc-access__row > .pc-access__num { display: flex; flex-direction: column; align-items: stretch; gap: 4px; width: auto; text-align: start; }
	.pc-access__table .pc-access__num::before { content: attr(data-label); font-size: var(--iz-fs-micro); font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase; color: var(--iz-text-secondary); }
	.pc-access__cell { min-width: 0; }
	.pc-access__table .pc-access__end { grid-column: 1 / -1; width: auto; text-align: start; }
	.pc-access__table .pc-access__end:empty { display: none; }
	.pc-access__table tbody tr.pc-access__detail { display: block; border-bottom: 1px solid var(--iz-border); }
	.pc-access__table tbody tr.pc-access__detail > td { display: block; border: 0; }
}
/* Room for the four counts side by side. */
@container pc-access (min-width: 420px) and (max-width: 640px) {
	.pc-access__table tbody tr.pc-access__row { grid-template-columns: repeat(4, minmax(0, 1fr)); }
}
</style>
