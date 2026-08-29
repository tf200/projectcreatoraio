<template>
	<div class="project-activity">
		<div class="project-activity__header">
			<History :size="18" />
			<span class="project-activity__title">Activity</span>
			<div class="project-activity__spacer" />
			<select
				v-model="selectedSource"
				class="project-activity__source-filter"
				aria-label="Filter by source"
				@change="fetchEvents">
				<option value="">
					All sources
				</option>
				<option value="internal">
					Project
				</option>
				<option value="deck">
					Deck
				</option>
				<option value="files">
					Files
				</option>
				<option value="talk">
					Talk
				</option>
				<option value="whiteboard">
					Whiteboard
				</option>
			</select>
		</div>

		<div v-if="loading && events.length === 0" class="project-activity__loading">
			<NcLoadingIcon :size="20" />
			<span>Loading activity...</span>
		</div>

		<div v-else-if="events.length === 0" class="project-activity__empty">
			No activity yet.
		</div>

		<div v-else class="project-activity__timeline">
			<div v-for="group in groupedEvents" :key="group.label" class="project-activity__group">
				<div class="project-activity__date-label">
					{{ group.label }}
				</div>
				<div
					v-for="event in group.events"
					:key="event.id"
					class="project-activity__entry">
					<NcAvatar
						:user="event.actorUid"
						:display-name="event.actorDisplayName"
						:size="32"
						class="project-activity__avatar" />
					<div class="project-activity__details">
						<div class="project-activity__details-top">
							<span class="project-activity__actor">{{ event.actorDisplayName || 'Unknown user' }}</span>
							<span class="project-activity__description">{{ formatDescription(event) }}</span>
						</div>
						<div class="project-activity__meta">
							<span class="project-activity__source-badge" :class="'project-activity__source-badge--' + (event.source || 'internal')">
								{{ formatSource(event.source) }}
							</span>
							<span class="project-activity__time">{{ formatTime(event.occurredAt) }}</span>
						</div>
					</div>
				</div>
			</div>
		</div>

		<div v-if="hasMore" class="project-activity__load-more">
			<NcButton
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
import { ProjectsService } from '../../Services/projects.js'
import { DEFAULT_NOTE_TYPE, NOTE_TYPES, noteTypeLabel } from '../../constants/note-types.js'

const projectsService = ProjectsService.getInstance()

export default {
	name: 'ProjectActivity',
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
	},
	data() {
		return {
			events: [],
			loading: false,
			hasMore: false,
			offset: 0,
			limit: 20,
			nextCursor: null,
			selectedSource: '',
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
		async fetchEvents() {
			if (!this.normalizedProjectId) {
				this.events = []
				return
			}
			this.loading = true
			this.offset = 0
			this.nextCursor = null
			try {
				const source = this.selectedSource || null
				const result = await projectsService.getActivity(this.normalizedProjectId, this.limit, this.offset, source)
				this.events = result.events || []
				this.hasMore = result.hasMore || false
				this.nextCursor = result.nextCursor || null
				this.offset = this.events.length
			} catch (e) {
				console.error('Failed to load activity:', e)
				this.events = []
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
				const source = this.selectedSource || null
				const result = await projectsService.getActivity(this.normalizedProjectId, this.limit, this.offset, source, this.nextCursor)
				const newEvents = result.events || []
				this.events = [...this.events, ...newEvents]
				this.hasMore = result.hasMore || false
				this.nextCursor = result.nextCursor || null
				this.offset += newEvents.length
			} catch (e) {
				console.error('Failed to load more activity:', e)
			} finally {
				this.loading = false
			}
		},
		formatDescription(event) {
			const type = event.eventType
			const payload = event.payload || {}
			if (payload.redacted === true) {
				return `${type.replace('note_', '')} a private note`
			}
			if (payload.nativeActivity === true) {
				return this.formatNativeDeckDescription(payload.nativeSubject, payload)
			}

			switch (type) {
			// Internal
			case 'project_created':
				return 'created the project'
			case 'project_updated':
				return `updated the project (${(payload.changedFields || []).join(', ') || 'details'})`
			case 'project_archived':
				return 'archived the project'
			case 'project_restored':
				return 'restored the project'
			case 'member_added':
				return `added ${payload.memberDisplayName || payload.memberUid || 'a member'}`
			case 'member_removed':
				return `removed ${payload.memberDisplayName || payload.memberUid || 'a member'}`
			case 'note_created':
				return `created ${this.formatNoteType(payload.noteType)}note "${payload.title || 'Untitled'}"`
			case 'note_updated':
				return `updated ${this.formatNoteType(payload.noteType)}note "${payload.title || 'Untitled'}"`
			case 'note_deleted':
				return `deleted ${this.formatNoteType(payload.noteType)}note "${payload.title || 'Untitled'}"`
			case 'project_notes_updated':
				return 'updated project notes'
			case 'timeline_item_created':
				return `created timeline item "${payload.label || 'Untitled'}"`
			case 'timeline_item_updated':
				return `updated timeline item "${payload.label || 'Untitled'}"`
			case 'timeline_item_deleted':
				return `deleted timeline item "${payload.label || 'Untitled'}"`
			case 'timeline_reordered':
				return 'reordered timeline items'

			// Deck
			case 'deck_card_created':
				return `created Deck card "${payload.cardTitle || 'Untitled'}"`
			case 'deck_card_updated':
				return `updated Deck card "${payload.cardTitle || 'Untitled'}"`
			case 'deck_card_deleted':
				return `deleted Deck card "${payload.cardTitle || 'Untitled'}"`
			case 'deck_acl_added':
				return `added ${payload.participant || 'someone'} to the Deck board`
			case 'deck_acl_removed':
				return `removed ${payload.participant || 'someone'} from the Deck board`
			case 'deck_acl_updated':
				return `updated access for ${payload.participant || 'someone'} on the Deck board`
			case 'deck_board_created':
				return 'created the Deck board'
			case 'deck_board_updated':
				return 'updated the Deck board'
			case 'deck_board_deleted':
				return 'deleted the Deck board'

			// Files
			case 'file_created':
				return `created "${payload.fileName || 'a file'}"`
			case 'file_updated':
				return `updated "${payload.fileName || 'a file'}"`
			case 'file_deleted':
				return `deleted "${payload.fileName || 'a file'}"`
			case 'file_renamed':
				return `renamed "${payload.oldName || 'a file'}" to "${payload.newName || 'new name'}"`
			case 'file_moved':
				return `moved "${payload.fileName || 'a file'}"`
			case 'file_copied':
				return `copied "${payload.fileName || 'a file'}"`
			case 'folder_created':
				return `created folder "${payload.folderName || 'Untitled'}"`
			case 'folder_deleted':
				return `deleted folder "${payload.folderName || 'a folder'}"`

			// Talk
			case 'talk_message_sent':
				return `sent a Talk message${payload.messagePreview ? ': "' + payload.messagePreview + '"' : ''}`
			case 'talk_participant_added':
				return `added ${payload.participantDisplayName || payload.participantUid || 'someone'} to Talk`
			case 'talk_participant_removed':
				return `removed ${payload.participantDisplayName || payload.participantUid || 'someone'} from Talk`
			case 'talk_call_started':
				return 'started a call'
			case 'talk_call_ended':
				return 'ended a call'
			case 'talk_room_updated':
				return 'updated the Talk conversation'
			case 'talk_reaction_added':
				return `reacted with ${payload.reaction || ''} to a message`
			case 'talk_reaction_removed':
				return `removed reaction ${payload.reaction || ''} from a message`
			case 'talk_user_joined':
				return 'joined the Talk conversation'

			// Whiteboard
			case 'whiteboard_updated':
				return 'updated the whiteboard'

			default:
				return type.replace(/_/g, ' ')
			}
		},
		formatNativeDeckDescription(subject, payload) {
			const card = `Deck card "${payload.cardTitle || 'Untitled'}"`
			const board = payload.boardTitle ? `Deck board "${payload.boardTitle}"` : 'the Deck board'
			switch (subject) {
			case 'board_create': return `created ${board}`
			case 'board_update':
			case 'board_update_title': return `updated ${board}`
			case 'board_update_archived': return `archived ${board}`
			case 'board_delete': return `deleted ${board}`
			case 'board_restore': return `restored ${board}`
			case 'board_share': return `shared ${board}`
			case 'board_unshare': return `unshared ${board}`
			case 'stack_create': return `created Deck stack "${payload.stackTitle || 'Untitled'}"`
			case 'stack_update':
			case 'stack_update_title':
			case 'stack_update_order': return `updated Deck stack "${payload.stackTitle || 'Untitled'}"`
			case 'stack_delete': return `deleted Deck stack "${payload.stackTitle || 'Untitled'}"`
			case 'card_create': return `created ${card}`
			case 'card_delete': return `deleted ${card}`
			case 'card_restore': return `restored ${card}`
			case 'card_update_title': return `renamed ${card}`
			case 'card_update_description': return `updated the description of ${card}`
			case 'card_update_duedate': return `updated the due date of ${card}`
			case 'card_update_archive': return `archived ${card}`
			case 'card_update_unarchive': return `unarchived ${card}`
			case 'card_update_done': return `marked ${card} as done`
			case 'card_update_undone': return `marked ${card} as not done`
			case 'card_update_stackId': return `moved ${card} to "${payload.stackTitle || 'another stack'}"`
			case 'card_user_assign': return `assigned ${payload.assignedUserDisplayName || payload.assignedUserUid || 'a user'} to ${card}`
			case 'card_user_unassign': return `unassigned ${payload.assignedUserDisplayName || payload.assignedUserUid || 'a user'} from ${card}`
			case 'label_assign': return `added label "${payload.labelTitle || 'Untitled'}" to ${card}`
			case 'label_unassign': return `removed label "${payload.labelTitle || 'Untitled'}" from ${card}`
			case 'attachment_create': return `added an attachment to ${card}`
			case 'attachment_update': return `updated an attachment on ${card}`
			case 'attachment_delete': return `deleted an attachment from ${card}`
			case 'attachment_restore': return `restored an attachment on ${card}`
			case 'card_comment_create': return `commented on ${card}`
			default: return `updated ${card}`
			}
		},
		formatNoteType(value) {
			const normalized = NOTE_TYPES.some((type) => type.value === value) ? value : DEFAULT_NOTE_TYPE
			if (normalized === DEFAULT_NOTE_TYPE) {
				return ''
			}
			const label = noteTypeLabel(normalized).toLowerCase().replace(/ note$/, '')
			return `${label} `
		},
		formatSource(source) {
			const labels = {
				internal: 'Project',
				deck: 'Deck',
				files: 'Files',
				talk: 'Talk',
				whiteboard: 'Whiteboard',
			}
			return labels[source] || source || 'Project'
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
.project-activity {
	margin-top: 16px;
	border: 1px solid var(--color-border);
	border-radius: 12px;
	background: var(--color-main-background);
	padding: 16px;
}

.project-activity__header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 12px;
	color: var(--color-main-text);
}

.project-activity__title {
	font-weight: 700;
	font-size: 14px;
}

.project-activity__spacer {
	flex: 1;
}

.project-activity__source-filter {
	font-size: 12px;
	padding: 4px 8px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.project-activity__loading,
.project-activity__empty {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	padding: 24px 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.project-activity__group {
	margin-bottom: 16px;
}

.project-activity__group:last-child {
	margin-bottom: 0;
}

.project-activity__date-label {
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
	margin-bottom: 8px;
	padding-bottom: 4px;
	border-bottom: 1px solid var(--color-border);
}

.project-activity__entry {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	padding: 8px 0;
}

.project-activity__avatar {
	flex-shrink: 0;
}

.project-activity__details {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	flex: 1;
}

.project-activity__details-top {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 4px;
}

.project-activity__actor {
	font-weight: 600;
	font-size: 13px;
	color: var(--color-main-text);
}

.project-activity__description {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.project-activity__meta {
	display: flex;
	align-items: center;
	gap: 8px;
}

.project-activity__source-badge {
	font-size: 11px;
	font-weight: 600;
	padding: 1px 6px;
	border-radius: 4px;
	text-transform: uppercase;
	letter-spacing: 0.03em;
}

.project-activity__source-badge--internal {
	background: var(--color-primary-light);
	color: var(--color-primary-element);
}

.project-activity__source-badge--deck {
	background: #e8f5e9;
	color: #2e7d32;
}

.project-activity__source-badge--files {
	background: #e3f2fd;
	color: #1565c0;
}

.project-activity__source-badge--talk {
	background: #fff3e0;
	color: #e65100;
}

.project-activity__source-badge--whiteboard {
	background: #f3e5f5;
	color: #7b1fa2;
}

.project-activity__time {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.project-activity__load-more {
	display: flex;
	justify-content: center;
	margin-top: 12px;
}
</style>
