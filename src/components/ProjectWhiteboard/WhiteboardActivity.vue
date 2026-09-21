<template>
	<div class="whiteboard-activity">
		<div class="whiteboard-activity__header">
			<History :size="18" />
			<span class="whiteboard-activity__title">Activity</span>
		</div>

		<div v-if="loading && events.length === 0" class="whiteboard-activity__loading">
			<NcLoadingIcon :size="20" />
			<span>Loading activity...</span>
		</div>

		<div v-else-if="error && events.length === 0" class="whiteboard-activity__error" role="alert">
			<span>{{ error }}</span>
			<NcButton type="secondary" @click="fetchEvents">
				Try again
			</NcButton>
		</div>

		<div v-else-if="events.length === 0" class="whiteboard-activity__empty">
			No whiteboard activity yet.
		</div>

		<div v-else class="whiteboard-activity__timeline">
			<div v-for="group in groupedEvents" :key="group.label" class="whiteboard-activity__group">
				<div class="whiteboard-activity__date-label">
					{{ group.label }}
				</div>
				<div
					v-for="event in group.events"
					:key="event.id"
					class="whiteboard-activity__entry">
					<NcAvatar
						:user="event.actorUid"
						:display-name="event.actorDisplayName"
						:size="32"
						class="whiteboard-activity__avatar" />
					<div class="whiteboard-activity__details">
						<span class="whiteboard-activity__actor">{{ event.actorDisplayName || 'Unknown user' }}</span>
						<span class="whiteboard-activity__description">updated the whiteboard</span>
						<span class="whiteboard-activity__time">{{ formatTime(event.occurredAt) }}</span>
					</div>
				</div>
			</div>
		</div>

		<div v-if="hasMore || (error && events.length > 0)" class="whiteboard-activity__load-more">
			<span v-if="error && events.length > 0" class="whiteboard-activity__error-inline" role="alert">{{ error }}</span>
			<NcButton
				v-if="hasMore"
				type="secondary"
				:disabled="loading"
				@click="loadMore">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="16" />
				</template>
				Load more
			</NcButton>
		</div>
	</div>
</template>

<script>
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import History from 'vue-material-design-icons/History.vue'
import { ProjectsService } from '../../Services/projects'

const projectsService = ProjectsService.getInstance()

export default {
	name: 'WhiteboardActivity',
	components: {
		NcAvatar,
		NcButton,
		NcLoadingIcon,
		History,
	},
	props: {
		projectId: {
			type: [String, Number],
			default: null,
		},
		// Without a reader the legacy service keeps swallowing its own errors.
		reader: {
			type: Function,
			default: null,
		},
	},
	data() {
		return {
			events: [],
			loading: false,
			hasMore: false,
			offset: 0,
			limit: 20,
			error: '',
		}
	},
	computed: {
		normalizedProjectId() {
			if (this.projectId === null || this.projectId === undefined || this.projectId === '') {
				return null
			}
			return String(this.projectId)
		},
		groupedEvents() {
			const groups = {}
			const today = new Date()
			today.setHours(0, 0, 0, 0)
			const yesterday = new Date(today)
			yesterday.setDate(yesterday.getDate() - 1)

			for (const event of this.events) {
				const eventDate = new Date(event.occurredAt)
				eventDate.setHours(0, 0, 0, 0)

				let label
				if (eventDate.getTime() === today.getTime()) {
					label = 'Today'
				} else if (eventDate.getTime() === yesterday.getTime()) {
					label = 'Yesterday'
				} else {
					label = eventDate.toLocaleDateString(undefined, {
						year: 'numeric',
						month: 'long',
						day: 'numeric',
					})
				}

				if (!groups[label]) {
					groups[label] = { label, events: [] }
				}
				groups[label].events.push(event)
			}

			return Object.values(groups)
		},
	},
	watch: {
		normalizedProjectId: {
			immediate: true,
			handler() {
				this.fetchEvents()
			},
		},
	},
	methods: {
		read(limit, offset) {
			if (this.reader) {
				return this.reader(this.normalizedProjectId, limit, offset)
			}
			return projectsService.getWhiteboardActivity(this.normalizedProjectId, limit, offset)
		},
		// Only a caller that supplied a reader can tell failure from an empty board.
		readFailed(message) {
			return this.reader ? message : ''
		},
		async fetchEvents() {
			if (!this.normalizedProjectId) {
				this.events = []
				return
			}
			this.loading = true
			this.error = ''
			this.offset = 0
			try {
				const result = await this.read(this.limit, this.offset)
				this.events = result.events || []
				this.hasMore = result.hasMore || false
				this.offset = this.events.length
			} catch (e) {
				console.error('Failed to load whiteboard activity:', e)
				this.events = []
				this.hasMore = false
				this.error = this.readFailed('Whiteboard activity could not be loaded. Check your access or try again.')
			} finally {
				this.loading = false
			}
		},
		async loadMore() {
			if (this.loading || !this.hasMore) {
				return
			}
			this.loading = true
			try {
				const result = await this.read(this.limit, this.offset)
				const newEvents = result.events || []
				this.events = [...this.events, ...newEvents]
				this.hasMore = result.hasMore || false
				this.offset += newEvents.length
			} catch (e) {
				console.error('Failed to load more whiteboard activity:', e)
				this.error = this.readFailed('More whiteboard activity could not be loaded. Try again.')
			} finally {
				this.loading = false
			}
		},
		formatTime(dateStr) {
			if (!dateStr) {
				return ''
			}
			const date = new Date(dateStr)
			const now = new Date()
			const diffMs = now - date
			const diffSec = Math.floor(diffMs / 1000)
			const diffMin = Math.floor(diffSec / 60)
			const diffHr = Math.floor(diffMin / 60)
			const diffDay = Math.floor(diffHr / 24)

			if (diffSec < 60) {
				return 'Just now'
			}
			if (diffMin < 60) {
				return `${diffMin}m ago`
			}
			if (diffHr < 24) {
				return `${diffHr}h ago`
			}
			if (diffDay === 1) {
				return 'Yesterday'
			}
			if (diffDay < 7) {
				return `${diffDay}d ago`
			}
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
.whiteboard-activity {
	margin-top: 16px;
	border: 1px solid var(--color-border);
	border-radius: 12px;
	background: var(--color-main-background);
	padding: 16px;
}

.whiteboard-activity__header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 12px;
	color: var(--color-main-text);
}

.whiteboard-activity__title {
	font-weight: 700;
	font-size: 14px;
}

.whiteboard-activity__loading,
.whiteboard-activity__empty,
.whiteboard-activity__error {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	padding: 24px 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.whiteboard-activity__error {
	flex-wrap: wrap;
}

.whiteboard-activity__error-inline {
	margin-inline-end: auto;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.whiteboard-activity__group {
	margin-bottom: 16px;
}

.whiteboard-activity__group:last-child {
	margin-bottom: 0;
}

.whiteboard-activity__date-label {
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
	margin-bottom: 8px;
	padding-bottom: 4px;
	border-bottom: 1px solid var(--color-border);
}

.whiteboard-activity__entry {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	padding: 8px 0;
}

.whiteboard-activity__avatar {
	flex-shrink: 0;
}

.whiteboard-activity__details {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 4px;
	min-width: 0;
}

.whiteboard-activity__actor {
	font-weight: 600;
	font-size: 13px;
	color: var(--color-main-text);
}

.whiteboard-activity__description {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.whiteboard-activity__time {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-left: 4px;
}

.whiteboard-activity__load-more {
	display: flex;
	justify-content: center;
	margin-top: 12px;
}
</style>
