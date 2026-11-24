<template>
	<div class="project-activity">
		<div class="project-activity__header">
			<History :size="18" />
			<span class="project-activity__title">{{ t('Activity') }}</span>
			<div class="project-activity__spacer" />
			<select
				v-model="selectedSource"
				class="project-activity__source-filter"
				:aria-label="t('Filter by source')"
				@change="fetchEvents">
				<option value="">
					{{ t('All sources') }}
				</option>
				<option value="internal">
					{{ t('Project') }}
				</option>
				<option value="deck">
					{{ t('Deck') }}
				</option>
				<option value="files">
					{{ t('Files') }}
				</option>
				<option value="talk">
					{{ t('Talk') }}
				</option>
				<option value="whiteboard">
					{{ t('Whiteboard') }}
				</option>
			</select>
		</div>

		<div v-if="loading && events.length === 0" class="project-activity__loading">
			<NcLoadingIcon :size="20" />
			<span>{{ t('Loading activity...') }}</span>
		</div>

		<div v-else-if="events.length === 0" class="project-activity__empty">
			{{ t('No activity yet.') }}
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
							<span class="project-activity__actor">{{ event.actorDisplayName || t('Unknown user') }}</span>
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
				{{ t('Load more') }}
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
import { t, locale } from '../../new/l10n.mjs'

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
					label = t('Today')
				} else if (eventDate.getTime() === yesterday.getTime()) {
					label = t('Yesterday')
				} else {
					label = eventDate.toLocaleDateString(locale(), {
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
		t,
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
			const type = String(event.eventType || '')
			const payload = event.payload || {}
			if (payload.redacted === true) {
				// The server's redaction of someone else's private note.
				if (type === 'note_created') return t('created a private note')
				if (type === 'note_deleted') return t('deleted a private note')
				return t('updated a private note')
			}
			if (payload.nativeActivity === true) {
				return this.formatNativeDeckDescription(payload.nativeSubject, payload)
			}

			const untitled = () => t('Untitled')
			const someone = () => t('someone')
			const aFile = () => t('a file')
			switch (type) {
			// Internal
			case 'project_created':
				return t('created the project')
			case 'project_updated':
				return t('updated the project ({fields})', { fields: (payload.changedFields || []).join(', ') || t('details') })
			case 'project_archived':
				return t('archived the project')
			case 'project_restored':
				return t('restored the project')
			case 'member_added':
				return t('added {member}', { member: payload.memberDisplayName || payload.memberUid || t('a member') })
			case 'member_removed':
				return t('removed {member}', { member: payload.memberDisplayName || payload.memberUid || t('a member') })
			case 'note_created':
				return this.formatNoteSentence((vars) => t('created {type}note "{title}"', vars), payload)
			case 'note_updated':
				return this.formatNoteSentence((vars) => t('updated {type}note "{title}"', vars), payload)
			case 'note_deleted':
				return this.formatNoteSentence((vars) => t('deleted {type}note "{title}"', vars), payload)
			case 'project_notes_updated':
				return t('updated project notes')
			case 'timeline_item_created':
				return t('created timeline item "{label}"', { label: payload.label || untitled() })
			case 'timeline_item_updated':
				return t('updated timeline item "{label}"', { label: payload.label || untitled() })
			case 'timeline_item_deleted':
				return t('deleted timeline item "{label}"', { label: payload.label || untitled() })
			case 'timeline_reordered':
				return t('reordered timeline items')

			// Deck
			case 'deck_card_created':
				return t('created Deck card "{title}"', { title: payload.cardTitle || untitled() })
			case 'deck_card_updated':
				return t('updated Deck card "{title}"', { title: payload.cardTitle || untitled() })
			case 'deck_card_deleted':
				return t('deleted Deck card "{title}"', { title: payload.cardTitle || untitled() })
			case 'deck_acl_added':
				return t('added {participant} to the Deck board', { participant: payload.participant || someone() })
			case 'deck_acl_removed':
				return t('removed {participant} from the Deck board', { participant: payload.participant || someone() })
			case 'deck_acl_updated':
				return t('updated access for {participant} on the Deck board', { participant: payload.participant || someone() })
			case 'deck_board_created':
				return t('created the Deck board')
			case 'deck_board_updated':
				return t('updated the Deck board')
			case 'deck_board_deleted':
				return t('deleted the Deck board')

			// Files
			case 'file_created':
				return t('created "{file}"', { file: payload.fileName || aFile() })
			case 'file_updated':
				return t('updated "{file}"', { file: payload.fileName || aFile() })
			case 'file_deleted':
				return t('deleted "{file}"', { file: payload.fileName || aFile() })
			case 'file_renamed':
				return t('renamed "{old}" to "{new}"', { old: payload.oldName || aFile(), new: payload.newName || t('new name') })
			case 'file_moved':
				return t('moved "{file}"', { file: payload.fileName || aFile() })
			case 'file_copied':
				return t('copied "{file}"', { file: payload.fileName || aFile() })
			case 'folder_created':
				return t('created folder "{folder}"', { folder: payload.folderName || untitled() })
			case 'folder_deleted':
				return t('deleted folder "{folder}"', { folder: payload.folderName || t('a folder') })

			// Talk
			case 'talk_message_sent':
				return payload.messagePreview
					? t('sent a Talk message: "{preview}"', { preview: payload.messagePreview })
					: t('sent a Talk message')
			case 'talk_participant_added':
				return t('added {participant} to Talk', { participant: payload.participantDisplayName || payload.participantUid || someone() })
			case 'talk_participant_removed':
				return t('removed {participant} from Talk', { participant: payload.participantDisplayName || payload.participantUid || someone() })
			case 'talk_call_started':
				return t('started a call')
			case 'talk_call_ended':
				return t('ended a call')
			case 'talk_room_updated':
				return t('updated the Talk conversation')
			case 'talk_reaction_added':
				return t('reacted with {reaction} to a message', { reaction: payload.reaction || '' })
			case 'talk_reaction_removed':
				return t('removed reaction {reaction} from a message', { reaction: payload.reaction || '' })
			case 'talk_user_joined':
				return t('joined the Talk conversation')

			// Whiteboard
			case 'whiteboard_updated':
				return t('updated the whiteboard')

			default:
				// An event type without a sentence yet: its own code, in words.
				return t('{event type}', { 'event type': type.replace(/_/g, ' ') })
			}
		},
		formatNativeDeckDescription(subject, payload) {
			const card = t('Deck card "{title}"', { title: payload.cardTitle || t('Untitled') })
			const board = payload.boardTitle ? t('Deck board "{title}"', { title: payload.boardTitle }) : t('the Deck board')
			const stack = payload.stackTitle || t('Untitled')
			const user = payload.assignedUserDisplayName || payload.assignedUserUid || t('a user')
			const label = payload.labelTitle || t('Untitled')
			switch (subject) {
			case 'board_create': return t('created {board}', { board })
			case 'board_update':
			case 'board_update_title': return t('updated {board}', { board })
			case 'board_update_archived': return t('archived {board}', { board })
			case 'board_delete': return t('deleted {board}', { board })
			case 'board_restore': return t('restored {board}', { board })
			case 'board_share': return t('shared {board}', { board })
			case 'board_unshare': return t('unshared {board}', { board })
			case 'stack_create': return t('created Deck stack "{stack}"', { stack })
			case 'stack_update':
			case 'stack_update_title':
			case 'stack_update_order': return t('updated Deck stack "{stack}"', { stack })
			case 'stack_delete': return t('deleted Deck stack "{stack}"', { stack })
			case 'card_create': return t('created {card}', { card })
			case 'card_delete': return t('deleted {card}', { card })
			case 'card_restore': return t('restored {card}', { card })
			case 'card_update_title': return t('renamed {card}', { card })
			case 'card_update_description': return t('updated the description of {card}', { card })
			case 'card_update_duedate': return t('updated the due date of {card}', { card })
			case 'card_update_archive': return t('archived {card}', { card })
			case 'card_update_unarchive': return t('unarchived {card}', { card })
			case 'card_update_done': return t('marked {card} as done', { card })
			case 'card_update_undone': return t('marked {card} as not done', { card })
			case 'card_update_stackId': return t('moved {card} to "{stack}"', { card, stack: payload.stackTitle || t('another stack') })
			case 'card_user_assign': return t('assigned {user} to {card}', { user, card })
			case 'card_user_unassign': return t('unassigned {user} from {card}', { user, card })
			case 'label_assign': return t('added label "{label}" to {card}', { label, card })
			case 'label_unassign': return t('removed label "{label}" from {card}', { label, card })
			case 'attachment_create': return t('added an attachment to {card}', { card })
			case 'attachment_update': return t('updated an attachment on {card}', { card })
			case 'attachment_delete': return t('deleted an attachment from {card}', { card })
			case 'attachment_restore': return t('restored an attachment on {card}', { card })
			case 'card_comment_create': return t('commented on {card}', { card })
			default: return t('updated {card}', { card })
			}
		},
		/*
		 * A note sentence: 'created {type}note "{title}"' and its siblings.
		 *
		 * English glues the type onto "note" ("created decision note", plain
		 * "created note" for a general note); Dutch puts it in brackets ("heeft
		 * de notitie ‘X’ (besluit) aangemaakt"). {type} is filled with the label
		 * between two markers, then an empty bracket goes, a label glued to the
		 * next word gets its space, and the markers go. Only the markers are
		 * matched, so a note title is never touched. Same as employee_dashboard's
		 * activityFeed.mjs.
		 */
		formatNoteSentence(sentence, payload) {
			const label = this.formatNoteType(payload.noteType)
			return sentence({ type: '\u0001' + label + '\u0002', title: payload.title || t('Untitled') })
				.replace(/\s*\(\u0001\u0002\)/, '')
				.replace('\u0001\u0002', '')
				.replace(/\u0001([^\u0002]*)\u0002(?=[^\s)\]}.,;:!?])/, '$1 ')
				.replace(/\u0001([^\u0002]*)\u0002/, '$1')
		},
		// The note type as the sentence names it, lower case and without "note";
		// '' for a general note.
		formatNoteType(value) {
			switch (value) {
			case 'customer': return t('customer')
			case 'internal': return t('internal')
			case 'decision': return t('decision')
			case 'risk_blocker': return t('risk / blocker')
			case 'action_point': return t('action point')
			case 'technical': return t('technical')
			case 'audit': return t('audit')
			default: return ''
			}
		},
		formatSource(source) {
			switch (source) {
			case 'internal': return t('Project')
			case 'deck': return t('Deck')
			case 'files': return t('Files')
			case 'talk': return t('Talk')
			case 'whiteboard': return t('Whiteboard')
			default: return source || t('Project')
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
				return t('Just now')
			}
			if (diffMin < 60) {
				return t('{n}m ago', { n: diffMin })
			}
			if (diffHr < 24) {
				return t('{n}h ago', { n: diffHr })
			}
			if (diffDay === 1) {
				return t('Yesterday')
			}
			if (diffDay < 7) {
				return t('{n}d ago', { n: diffDay })
			}
			return date.toLocaleDateString(locale(), {
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
