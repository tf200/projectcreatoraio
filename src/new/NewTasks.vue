<template>
	<div class="deck-board">
		<div v-if="!boardId" class="deck-board__empty">
			No Deck board linked to this project.
		</div>
		<div v-else>
			<div class="deck-board__top">
				<div class="deck-board__meta">
					<div class="deck-board__title">
						<span class="deck-board__title-text">{{ boardTitle }}</span>
						<span class="deck-board__badge">#{{ boardId }}</span>
						<span v-if="!canEdit" class="deck-board__badge deck-board__badge--muted">Read only</span>
					</div>
					<div class="deck-board__subtitle">
						Deck tasks embedded in project workspace.
					</div>
				</div>
				<div class="deck-board__actions">
					<NcButton type="secondary" @click="openBoard">
						<template #icon>
							<OpenInNew :size="18" />
						</template>
						Open in Deck
					</NcButton>
					<NcButton type="secondary" :disabled="loading" @click="reload">
						<template #icon>
							<Refresh :size="18" />
						</template>
						Reload
					</NcButton>
				</div>
			</div>

			<div class="deck-board__tabs">
				<button
					class="deck-board__tab"
					:class="{ 'deck-board__tab--active': activeTab === 'board' }"
					@click="activeTab = 'board'">
					<ViewDashboard :size="18" class="deck-board__tab-icon" />
					<span>Task Board</span>
				</button>
				<button
					v-if="!loading && !error && canManage"
					class="deck-board__tab"
					:class="{ 'deck-board__tab--active': activeTab === 'permissions' }"
					@click="activeTab = 'permissions'">
					<ShieldAccount :size="18" class="deck-board__tab-icon" />
					<span>Card Permissions</span>
				</button>
			</div>

			<div v-show="activeTab === 'board'" class="deck-board__tab-content">
				<div v-if="loading" class="deck-board__muted">
					Loading board...
				</div>
				<div v-else-if="error" class="deck-board__muted">
					{{ error }}
				</div>
				<div v-else class="deck-board__embed">
					<TaskProgress :board-id="Number(boardId)" :refresh-key="progressKey" />
					<div v-if="embeddedError" class="deck-board__muted">
						{{ embeddedError }}
					</div>
					<div v-else-if="!embeddedReady" class="deck-board__muted">
						Loading Deck tasks UI...
					</div>
					<div ref="deckMount" class="deck-board__mount" />
				</div>
			</div>

			<div v-if="activeTab === 'permissions' && !loading && !error && canManage" class="deck-board__tab-content">
				<DeckCardPolicyManager
					:board-id="boardId"
					:members="projectMembers" />
			</div>
		</div>
	</div>
</template>

<script>
import DeckBoard from '../components/ProjectDeck/DeckBoard.vue'
import TaskProgress from './TaskProgress.vue'

// After the embedded board changes something, read the progress again once it
// has settled, so a moved card is counted where it now is.
const SETTLE_MS = 800

// The Tasks tab of the new layout: DeckBoard's header, embed and permissions,
// with the new layout's own progress above the board in place of Deck's
// dashboard row (hidden in tasks-theme.css). No styles here, so DeckBoard's
// scoped styles keep applying to this template.
export default {
	name: 'NewTasks',
	components: { TaskProgress },
	extends: DeckBoard,
	data() {
		return { progressKey: 0, progressTimer: null, unsubscribeBoard: null }
	},
	beforeDestroy() {
		this.stopFollowingBoard()
	},
	methods: {
		async mountEmbedded(options) {
			this.stopFollowingBoard()
			await DeckBoard.methods.mountEmbedded.call(this, options)
			this.followBoard()
		},
		unmountEmbedded() {
			this.stopFollowingBoard()
			DeckBoard.methods.unmountEmbedded.call(this)
		},
		async reload() {
			await DeckBoard.methods.reload.call(this)
			this.progressKey++
		},
		// The embed exposes no events; its Vuex store does, through subscribe.
		followBoard() {
			const store = this.$refs.deckMount?.firstElementChild?.__vue__?.$store
			if (typeof store?.subscribe !== 'function') return
			this.unsubscribeBoard = store.subscribe(() => {
				clearTimeout(this.progressTimer)
				this.progressTimer = setTimeout(() => { this.progressKey++ }, SETTLE_MS)
			})
		},
		stopFollowingBoard() {
			clearTimeout(this.progressTimer)
			if (typeof this.unsubscribeBoard === 'function') this.unsubscribeBoard()
			this.unsubscribeBoard = null
		},
	},
}
</script>
