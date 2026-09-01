<template>
	<NcModal
		v-if="card"
		:show="show"
		size="large"
		@close="close">
		<div class="card-detail-modal">
			<div class="card-detail-modal__header">
				<h2 class="card-detail-modal__title">
					<CardTextOutline :size="24" />
					{{ card.title }}
				</h2>
				<span class="card-detail-modal__meta">
					Card #{{ card.cardId }} &middot; Updated {{ formatDate(card.updatedAt) }}
				</span>
			</div>

			<div class="card-detail-modal__body">
				<div class="card-detail-modal__section">
					<h3 class="card-detail-modal__section-title">Description</h3>
					<p class="card-detail-modal__description">
						{{ card.content || 'No description' }}
					</p>
				</div>

				<div class="card-detail-modal__section">
					<h3 class="card-detail-modal__section-title">
						Your Private Notes
						<span v-if="card.cardNotes && card.cardNotes.length" class="card-detail-modal__badge">
							{{ card.cardNotes.length }}
						</span>
					</h3>
					<div v-if="card.cardNotes && card.cardNotes.length" class="card-detail-modal__notes-list">
						<div
							v-for="note in card.cardNotes"
							:key="note.id"
							class="card-detail-modal__note-item">
							<p class="card-detail-modal__note-text">{{ note.text }}</p>
							<span class="card-detail-modal__note-date">{{ formatDate(note.lastModified) }}</span>
						</div>
					</div>
					<p v-else class="card-detail-modal__empty">No private notes on this card.</p>
				</div>
			</div>
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/components/NcModal'
import CardTextOutline from 'vue-material-design-icons/CardTextOutline.vue'

export default {
	name: 'CardDetailModal',
	components: {
		NcModal,
		CardTextOutline,
	},
	props: {
		show: {
			type: Boolean,
			default: false,
		},
		card: {
			type: Object,
			default: null,
		},
	},
	methods: {
		close() {
			this.$emit('close')
		},
		formatDate(dateString) {
			if (!dateString) return ''
			const timestamp = typeof dateString === 'number' && dateString < 1e12
				? dateString * 1000
				: dateString
			const date = new Date(timestamp)
			const now = new Date()
			const diffDays = Math.floor((now - date) / (1000 * 60 * 60 * 24))
			if (diffDays === 0) {
				const diffHours = Math.floor((now - date) / (1000 * 60 * 60))
				if (diffHours === 0) {
					const diffMinutes = Math.floor((now - date) / (1000 * 60))
					return diffMinutes <= 1 ? 'Just now' : `${diffMinutes} minutes ago`
				}
				return `${diffHours} h ago`
			}
			if (diffDays === 1) return 'Yesterday'
			if (diffDays < 7) return `${diffDays} d ago`
			return date.toLocaleDateString(undefined, {
				month: 'short',
				day: 'numeric',
				year: date.getFullYear() !== now.getFullYear() ? 'numeric' : undefined,
			})
		},
	},
}
</script>

<style scoped>
.card-detail-modal {
	padding: 0;
}

.card-detail-modal__header {
	padding: 28px 32px 20px;
	border-bottom: 1px solid var(--color-border);
}

.card-detail-modal__title {
	margin: 0;
	display: flex;
	align-items: center;
	gap: 12px;
	font-size: 20px;
	font-weight: 700;
	color: var(--color-main-text);
}

.card-detail-modal__meta {
	display: block;
	margin-top: 8px;
	margin-left: 36px;
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.card-detail-modal__body {
	padding: 24px 32px 32px;
	display: flex;
	flex-direction: column;
	gap: 28px;
}

.card-detail-modal__section-title {
	margin: 0 0 12px;
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 14px;
	font-weight: 700;
	color: var(--color-text-lighter);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.card-detail-modal__badge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 20px;
	height: 20px;
	padding: 0 6px;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-size: 11px;
	font-weight: 800;
	border-radius: 999px;
}

.card-detail-modal__description {
	margin: 0;
	font-size: 15px;
	line-height: 1.7;
	color: var(--color-main-text);
	white-space: pre-wrap;
}

.card-detail-modal__notes-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.card-detail-modal__note-item {
	padding: 16px 18px;
	background: var(--color-background-hover);
	border-radius: 12px;
}

.card-detail-modal__note-text {
	margin: 0;
	font-size: 14px;
	line-height: 1.5;
	color: var(--color-main-text);
	white-space: pre-wrap;
}

.card-detail-modal__note-date {
	display: block;
	margin-top: 8px;
	font-size: 11px;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.card-detail-modal__empty {
	margin: 0;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

@media (max-width: 600px) {
	.card-detail-modal__header,
	.card-detail-modal__body {
		padding-left: 20px;
		padding-right: 20px;
	}
}
</style>
