<template>
	<div class="deck-analytics">
		<div v-if="!boardId" class="deck-analytics__empty">
			No Deck board linked to this project.
		</div>

		<div v-else class="deck-analytics__embed">
			<div v-if="embeddedError" class="deck-analytics__muted">
				{{ embeddedError }}
			</div>
			<div v-else-if="!embeddedReady" class="deck-analytics__muted">
				Loading Deck analytics UI...
			</div>
			<div ref="deckAnalyticsMount" class="deck-analytics__mount" />
		</div>
	</div>
</template>

<script>
import { deckEmbedScriptUrl } from '../../Services/deckEmbedScript.js'

export default {
	name: 'DeckAnalytics',
	props: {
		boardId: {
			type: [String, Number],
			default: null,
		},
	},
	data() {
		return {
			embeddedReady: false,
			embeddedError: '',
			embeddedHandle: null,
		}
	},
	watch: {
		boardId: {
			immediate: true,
			async handler() {
				this.unmountEmbedded()
				await this.mountEmbedded({ forceRemount: true })
			},
		},
	},
	beforeDestroy() {
		this.unmountEmbedded()
	},
	methods: {
		async ensureEmbeddedApiLoaded() {
			if (window?.OCA?.Deck?.EmbeddedAnalytics?.mount) {
				return true
			}

			// Same bundle as tasks, but analytics is mounted through a different API.
			const src = deckEmbedScriptUrl()
			window.__projectcreatoraioDeckEmbedLoading = window.__projectcreatoraioDeckEmbedLoading || new Promise((resolve, reject) => {
				const existing = document.querySelector(`script[data-projectcreatoraio-deck-embed="1"][src="${src}"]`)
				if (existing) {
					existing.addEventListener('load', resolve, { once: true })
					existing.addEventListener('error', reject, { once: true })
					return
				}

				const script = document.createElement('script')
				script.dataset.projectcreatoraioDeckEmbed = '1'
				script.src = src
				script.async = true
				script.addEventListener('load', resolve, { once: true })
				script.addEventListener('error', reject, { once: true })
				document.head.appendChild(script)
			})

			try {
				await window.__projectcreatoraioDeckEmbedLoading
			} catch (e) {
				return false
			}

			return !!window?.OCA?.Deck?.EmbeddedAnalytics?.mount
		},
		unmountEmbedded() {
			if (this.embeddedHandle && typeof this.embeddedHandle.destroy === 'function') {
				try {
					this.embeddedHandle.destroy()
				} catch (e) {
					// ignore
				}
			}
			this.embeddedHandle = null
			this.embeddedReady = false
		},
		async mountEmbedded({ forceRemount = false } = {}) {
			this.embeddedError = ''
			this.embeddedReady = false

			const boardId = Number(this.boardId)
			if (!Number.isFinite(boardId) || boardId <= 0) {
				this.unmountEmbedded()
				return
			}

			const ok = await this.ensureEmbeddedApiLoaded()
			if (!ok) {
				this.unmountEmbedded()
				this.embeddedError = 'Deck embedded analytics UI is not available. Build and deploy the Deck app update, then reload.'
				return
			}

			if (this.embeddedHandle && !forceRemount && typeof this.embeddedHandle.setBoardId === 'function') {
				this.embeddedHandle.setBoardId(boardId)
				this.embeddedReady = true
				return
			}

			this.unmountEmbedded()

			const el = this.$refs.deckAnalyticsMount
			if (!el) {
				this.embeddedError = 'Could not mount Deck analytics UI.'
				return
			}
			el.innerHTML = ''

			try {
				this.embeddedHandle = window.OCA.Deck.EmbeddedAnalytics.mount({ el, boardId })
				this.embeddedReady = true
			} catch (e) {
				this.unmountEmbedded()
				this.embeddedError = 'Could not mount Deck analytics UI.'
			}
		},
	},
}
</script>

<style scoped>
.deck-analytics {
	display: grid;
	gap: 8px;
}

.deck-analytics__empty {
	color: var(--color-text-maxcontrast);
	padding: 10px 2px;
}

.deck-analytics__muted {
	color: var(--color-text-maxcontrast);
}

.deck-analytics__mount {
	width: 100%;
	min-height: 420px;
	height: 560px;
	border: 1px solid var(--color-border);
	border-radius: 12px;
	background: var(--color-main-background);
	overflow: hidden;
}

@media (max-width: 900px) {
	.deck-analytics__mount {
		min-height: 360px;
		height: 500px;
	}
}
</style>

