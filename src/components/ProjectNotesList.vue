<template>
	<div class="project-notes-list">
		<div class="project-notes-list__header">
			<div class="project-notes-list__main-tabs">
				<button
					type="button"
					class="project-notes-list__main-tab"
					:class="{ 'project-notes-list__main-tab--active': mainTab === 'project' }"
					@click="switchMainTab('project')">
					<FileDocumentOutline :size="18" />
					<span>Project Notes</span>
				</button>
				<button
					type="button"
					class="project-notes-list__main-tab"
					:class="{ 'project-notes-list__main-tab--active': mainTab === 'cards' }"
					@click="switchMainTab('cards')">
					<CardTextOutline :size="18" />
					<span>Card Notes</span>
				</button>
				<button
					v-if="talkConversationToken"
					type="button"
					class="project-notes-list__main-tab"
					:class="{ 'project-notes-list__main-tab--active': mainTab === 'chat' }"
					@click="switchMainTab('chat')">
					<ChatOutline :size="18" />
					<span>Chat</span>
				</button>
			</div>
			<NcButton
				v-if="mainTab === 'project'"
				type="secondary"
				:disabled="!canCreateNote"
				@click="openCreateModal">
				<template #icon>
					<Plus :size="20" />
				</template>
				Add note
			</NcButton>
			<NcButton
				v-if="mainTab === 'chat' && talkUrl"
				type="secondary"
				@click="openTalkChat">
				<template #icon>
					<OpenInNew :size="20" />
				</template>
				Open in Talk
			</NcButton>
		</div>
		<div v-if="mainTab === 'project'" class="project-notes-list__sub-tabs">
			<button
				type="button"
				class="project-notes-list__sub-tab"
				:class="{ 'project-notes-list__sub-tab--active': subTab === 'public' }"
				@click="switchSubTab('public')">
				<Earth :size="14" />
				<span>Public</span>
			</button>
			<button
				type="button"
				class="project-notes-list__sub-tab"
				:class="{ 'project-notes-list__sub-tab--active': subTab === 'private' }"
				@click="switchSubTab('private')">
				<Lock :size="14" />
				<span>Private</span>
			</button>
		</div>
		<div v-if="mainTab === 'cards'" class="project-notes-list__sub-tabs">
			<button
				type="button"
				class="project-notes-list__sub-tab"
				:class="{ 'project-notes-list__sub-tab--active': cardSubTab === 'notes' }"
				@click="switchCardSubTab('notes')">
				<CardTextOutline :size="14" />
				<span>Notes</span>
			</button>
			<button
				type="button"
				class="project-notes-list__sub-tab"
				:class="{ 'project-notes-list__sub-tab--active': cardSubTab === 'comments' }"
				@click="switchCardSubTab('comments')">
				<CommentOutline :size="14" />
				<span>Comments</span>
			</button>
		</div>

		<div v-if="loading" class="project-notes-list__loading">
			<NcLoadingIcon :size="48" />
			<span>Loading your notes...</span>
		</div>

		<div v-else-if="mainTab === 'cards' && cardSubTab === 'comments' && comments.length === 0" class="project-notes-list__empty">
			<div class="project-notes-list__empty-icon-wrapper">
				<CommentOutline :size="64" />
			</div>
			<p class="project-notes-list__empty-title">
				{{ emptyTitle }}
			</p>
			<p class="project-notes-list__empty-subtitle">
				{{ emptySubtitle }}
			</p>
		</div>

		<div v-else-if="mainTab === 'cards' && cardSubTab === 'comments'" class="project-notes-list__comments-list">
			<div
				v-for="comment in comments"
				:key="comment.id"
				class="project-notes-list__comment-item">
				<div class="project-notes-list__comment-header">
					<div class="project-notes-list__comment-author">
						<div class="project-notes-list__author-avatar" :title="comment.actorId">
							{{ comment.actorDisplayName ? comment.actorDisplayName.charAt(0).toUpperCase() : '?' }}
						</div>
						<span class="project-notes-list__author-name">{{ comment.actorDisplayName }}</span>
					</div>
					<span class="project-notes-list__comment-date">
						{{ formatDate(comment.createdAt) }}
					</span>
				</div>
				<div class="project-notes-list__comment-body">
					<p class="project-notes-list__comment-message">
						{{ comment.message }}
					</p>
				</div>
				<div class="project-notes-list__comment-footer">
					<span
						class="project-notes-list__comment-card-badge"
						@click.stop="openCardDetailByCardId(comment.cardId, comment.cardTitle)">
						<CardTextOutline :size="12" />
						{{ comment.cardTitle }}
					</span>
				</div>
			</div>
		</div>

		<div v-else-if="mainTab === 'chat' && chatMessages.length === 0 && !loading" class="project-notes-list__empty">
			<div class="project-notes-list__empty-icon-wrapper">
				<ChatOutline :size="64" />
			</div>
			<p class="project-notes-list__empty-title">
				No chat messages yet
			</p>
			<p class="project-notes-list__empty-subtitle">
				Messages from the project's group chat will appear here
			</p>
		</div>

		<div v-else-if="mainTab === 'chat'" class="project-notes-list__chat-list">
			<div
				v-for="msg in chatMessages"
				:key="msg.id"
				class="project-notes-list__chat-message">
				<div class="project-notes-list__chat-avatar">
					{{ msg.actorDisplayName ? msg.actorDisplayName.charAt(0).toUpperCase() : '?' }}
				</div>
				<div class="project-notes-list__chat-content">
					<div class="project-notes-list__chat-header">
						<span class="project-notes-list__chat-author">{{ msg.actorDisplayName }}</span>
						<span class="project-notes-list__chat-time">{{ formatDate(msg.timestamp * 1000) }}</span>
					</div>
					<p class="project-notes-list__chat-text">
						{{ msg.message }}
					</p>
				</div>
			</div>
			<div v-if="chatHasMore" class="project-notes-list__chat-load-more">
				<NcButton
					type="secondary"
					:disabled="loading"
					@click="loadMoreChatMessages">
					<template #icon>
						<ChevronDown :size="20" />
					</template>
					Load older messages
				</NcButton>
			</div>
		</div>

		<div v-else-if="notes.length === 0" class="project-notes-list__empty">
			<div class="project-notes-list__empty-icon-wrapper">
				<FileDocumentOutline :size="64" />
			</div>
			<p class="project-notes-list__empty-title">
				{{ emptyTitle }}
			</p>
			<p class="project-notes-list__empty-subtitle">
				{{ emptySubtitle }}
			</p>
		</div>

		<div v-else class="project-notes-list__grid">
			<div
				v-for="note in notes"
				:key="note.id"
				class="project-notes-list__note-card"
				@click="note.visibility === 'card' ? openCardDetail(note) : openEditModal(note)">
				<div class="project-notes-list__note-header">
					<div class="project-notes-list__note-title-group">
						<h4 class="project-notes-list__note-title">
							{{ note.title }}
						</h4>
						<span class="project-notes-list__note-date">
							{{ formatDate(note.updatedAt) }}
						</span>
					</div>
					<div v-if="note.visibility !== 'card'" class="project-notes-list__note-actions">
						<button
							type="button"
							class="project-notes-list__action-btn"
							title="Delete"
							@click.stop="confirmDelete(note)">
							<Delete :size="18" />
						</button>
					</div>
				</div>
				<div class="project-notes-list__note-content">
					<p class="project-notes-list__note-preview">
						{{ getPreview(note.content) }}
					</p>
				</div>
				<div class="project-notes-list__note-footer">
					<div class="project-notes-list__note-author">
						<div class="project-notes-list__author-avatar" :title="note.userId">
							{{ note.userId ? note.userId.charAt(0).toUpperCase() : '?' }}
						</div>
						<span class="project-notes-list__author-name">{{ note.visibility === 'card' ? `Card #${note.cardId}` : note.userId }}</span>
					</div>
					<div class="project-notes-list__note-type" :class="`project-notes-list__note-type--${note.visibility}`">
						<CardTextOutline v-if="note.visibility === 'card'" :size="14" />
						<Earth v-else-if="note.visibility === 'public'" :size="14" />
						<Lock v-else :size="14" />
						<span v-if="note.visibility === 'card'">{{ note.cardNoteCount }} note{{ note.cardNoteCount !== 1 ? 's' : '' }}</span>
						<span v-else>{{ note.visibility }}</span>
					</div>
				</div>
			</div>
		</div>

		<div v-if="totalPages > 1" class="project-notes-list__pagination">
			<NcButton
				type="secondary"
				:disabled="currentPage <= 1"
				@click="previousPage">
				<template #icon>
					<ChevronLeft :size="20" />
				</template>
				Previous
			</NcButton>
			<span class="project-notes-list__pagination-info">
				Page {{ currentPage }} of {{ totalPages }}
			</span>
			<NcButton
				type="secondary"
				:disabled="currentPage >= totalPages"
				@click="nextPage">
				Next
				<template #icon>
					<ChevronRight :size="20" />
				</template>
			</NcButton>
		</div>

		<CreateNoteModal
			:show="showCreateModal"
			:project-id="projectId"
			:visibility="subTab"
			@close="closeCreateModal"
			@created="onNoteCreated" />

		<CreateNoteModal
			v-if="editingNote"
			:show="showEditModal"
			:project-id="projectId"
			:note="editingNote"
			:visibility="editingNote.visibility"
			@close="closeEditModal"
			@updated="onNoteUpdated" />

		<CardDetailModal
			:show="showCardDetail"
			:card="viewingCard"
			@close="closeCardDetail" />
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import Earth from 'vue-material-design-icons/Earth.vue'
import Lock from 'vue-material-design-icons/Lock.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import CardTextOutline from 'vue-material-design-icons/CardTextOutline.vue'
import CommentOutline from 'vue-material-design-icons/CommentOutline.vue'
import ChatOutline from 'vue-material-design-icons/ChatOutline.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import CreateNoteModal from './CreateNoteModal.vue'
import CardDetailModal from './CardDetailModal.vue'
import { ProjectsService } from '../Services/projects.js'

const projectsService = ProjectsService.getInstance()

export default {
	name: 'ProjectNotesList',
	components: {
		NcButton,
		NcLoadingIcon,
		Earth,
		Lock,
		Plus,
		Delete,
		ChevronLeft,
		ChevronRight,
		ChevronDown,
		FileDocumentOutline,
		CardTextOutline,
		CommentOutline,
		ChatOutline,
		OpenInNew,
		CreateNoteModal,
		CardDetailModal,
	},
	props: {
		projectId: {
			type: Number,
			required: true,
		},
		talkConversationToken: {
			type: String,
			default: '',
		},
		talkUrl: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			loading: true,
			mainTab: 'project',
			subTab: 'public',
			cardSubTab: 'notes',
			notes: [],
			comments: [],
			chatMessages: [],
			chatHasMore: false,
			chatOffset: 0,
			totalCount: 0,
			currentPage: 1,
			perPage: 12,
			privateAvailable: false,
			showCreateModal: false,
			showEditModal: false,
			editingNote: null,
			showCardDetail: false,
			viewingCard: null,
		}
	},
	computed: {
		totalPages() {
			return Math.ceil(this.totalCount / this.perPage) || 1
		},
		canCreateNote() {
			if (this.mainTab === 'cards') {
				return false
			}
			if (this.subTab === 'public') {
				return true
			}
			return this.privateAvailable
		},
		emptyTitle() {
			if (this.mainTab === 'cards' && this.cardSubTab === 'comments') {
				return 'No comments found'
			}
			if (this.mainTab === 'cards') {
				return 'No cards found'
			}
			if (this.subTab === 'private' && !this.canCreateNote) {
				return 'Private notes are not available'
			}
			return `No ${this.subTab} notes yet`
		},
		emptySubtitle() {
			if (this.mainTab === 'cards' && this.cardSubTab === 'comments') {
				return 'No one has commented on any cards in this project yet'
			}
			if (this.mainTab === 'cards') {
				return 'This project does not have any visible cards in its deck board yet'
			}
			if (this.subTab === 'private' && !this.canCreateNote) {
				return 'Private notes are not available for this project'
			}
			return 'Create your first note to start documenting this project'
		},
	},
	watch: {
		projectId: {
			immediate: true,
			handler(newId) {
				if (newId) {
					this.chatOffset = 0
					this.chatMessages = []
					this.chatHasMore = false
					this.loadNotes(1)
				}
			},
		},
		mainTab() {
			if (this.mainTab === 'chat') {
				this.loadChatMessages()
			} else {
				this.loadNotes(1)
			}
		},
		subTab() {
			if (this.mainTab === 'project') {
				this.loadNotes(1)
			}
		},
		cardSubTab() {
			if (this.mainTab === 'cards') {
				this.loadNotes(1)
			}
		},
	},
	methods: {
		async loadNotes(page) {
			this.loading = true
			this.currentPage = page

			// Load card comments
			if (this.mainTab === 'cards' && this.cardSubTab === 'comments') {
				try {
					const result = await projectsService.listCardComments(this.projectId, {
						page,
						limit: this.perPage,
					})
					if (result) {
						this.comments = result.comments || []
						this.totalCount = result.total || 0
					}
				} catch (error) {
					console.error('Failed to load card comments:', error)
				} finally {
					this.loading = false
				}
				return
			}

			// Load notes (project or card)
			const visibility = this.mainTab === 'cards' ? 'cards' : this.subTab
			try {
				const result = await projectsService.listNotes(this.projectId, {
					visibility,
					page,
					limit: this.perPage,
				})
				if (result) {
					this.notes = result.notes || []
					this.totalCount = result.total || 0
					this.privateAvailable = result.private_available || false
				}
			} catch (error) {
				console.error('Failed to load notes:', error)
			} finally {
				this.loading = false
			}
		},
		switchMainTab(tab) {
			if (this.mainTab !== tab) {
				this.mainTab = tab
			}
		},
		switchSubTab(tab) {
			if (this.subTab !== tab) {
				this.subTab = tab
			}
		},
		switchCardSubTab(tab) {
			if (this.cardSubTab !== tab) {
				this.cardSubTab = tab
			}
		},
		async loadChatMessages() {
			this.loading = true
			try {
				const result = await projectsService.getChatMessages(this.projectId, {
					limit: this.perPage,
					offset: 0,
				})
				if (result) {
					this.chatMessages = result.messages || []
					this.chatHasMore = result.hasMore || false
					this.chatOffset = Number(result.nextOffset) || 0
				}
			} catch (error) {
				console.error('Failed to load chat messages:', error)
			} finally {
				this.loading = false
			}
		},
		async loadMoreChatMessages() {
			if (this.loading) return
			this.loading = true
			try {
				const result = await projectsService.getChatMessages(this.projectId, {
					limit: this.perPage,
					offset: this.chatOffset,
				})
				if (result) {
					this.chatMessages = [...this.chatMessages, ...(result.messages || [])]
					this.chatHasMore = result.hasMore || false
					this.chatOffset = Number(result.nextOffset) || this.chatOffset
				}
			} catch (error) {
				console.error('Failed to load more chat messages:', error)
			} finally {
				this.loading = false
			}
		},
		openTalkChat() {
			if (this.talkUrl) {
				window.open(this.talkUrl, '_blank', 'noopener')
			}
		},
		previousPage() {
			if (this.currentPage > 1) {
				this.loadNotes(this.currentPage - 1)
			}
		},
		nextPage() {
			if (this.currentPage < this.totalPages) {
				this.loadNotes(this.currentPage + 1)
			}
		},
		formatDate(dateString) {
			if (!dateString) return ''
			const date = new Date(dateString)
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
		getPreview(content) {
			if (!content) return 'No content'
			const plainText = content
				.replace(/<[^>]*>?/gm, ' ')
				.replace(/[#*_`[\]()]/g, '')
				.replace(/\s+/g, ' ')
				.trim()
			return plainText.length > 350 ? plainText.slice(0, 350) + '...' : plainText
		},
		openCreateModal() {
			this.showCreateModal = true
		},
		closeCreateModal() {
			this.showCreateModal = false
		},
		onNoteCreated() {
			this.closeCreateModal()
			this.loadNotes(1)
		},
		openEditModal(note) {
			this.editingNote = note
			this.showEditModal = true
		},
		closeEditModal() {
			this.editingNote = null
			this.showEditModal = false
		},
		onNoteUpdated(updatedNote) {
			const index = this.notes.findIndex(n => n.id === updatedNote.id)
			if (index !== -1) {
				this.$set(this.notes, index, updatedNote)
			}
			this.closeEditModal()
		},
		openCardDetail(note) {
			this.viewingCard = note
			this.showCardDetail = true
		},
		openCardDetailByCardId(cardId, cardTitle) {
			this.viewingCard = {
				id: 'card_' + cardId,
				cardId,
				title: cardTitle,
				content: '',
				visibility: 'card',
				cardNotes: [],
			}
			this.showCardDetail = true
		},
		closeCardDetail() {
			this.viewingCard = null
			this.showCardDetail = false
		},
		async confirmDelete(note) {
			if (!window.confirm(`Are you sure you want to delete "${note.title}"?`)) {
				return
			}

			try {
				await projectsService.deleteNote(this.projectId, note.id)
				const wasLastOnPage = this.notes.length === 1 && this.currentPage > 1
				this.loadNotes(wasLastOnPage ? this.currentPage - 1 : this.currentPage)
			} catch (error) {
				console.error('Failed to delete note:', error)
				alert('Failed to delete note. Please try again.')
			}
		},
	},
}
</script>

<style scoped>
.project-notes-list {
	display: flex;
	flex-direction: column;
	gap: 20px;
	padding-bottom: 12px;
}

.project-notes-list__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 32px;
}

.project-notes-list__main-tabs {
	display: flex;
	gap: 4px;
	background: var(--color-background-dark);
	border-radius: 14px;
	padding: 4px;
	flex-shrink: 0;
}

.project-notes-list__main-tab {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 12px 22px;
	border: none;
	background: transparent;
	color: var(--color-text-lighter);
	font-size: 14px;
	font-weight: 800;
	cursor: pointer;
	border-radius: 11px;
	transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

.project-notes-list__main-tab:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.project-notes-list__main-tab--active {
	background: var(--color-main-background);
	color: var(--color-main-text);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.project-notes-list__sub-tabs {
	display: flex;
	gap: 2px;
	background: var(--color-background-dark);
	border-radius: 10px;
	padding: 3px;
	align-self: flex-start;
}

.project-notes-list__sub-tab {
	display: flex;
	align-items: center;
	gap: 7px;
	padding: 7px 14px;
	border: none;
	background: transparent;
	color: var(--color-text-lighter);
	font-size: 12px;
	font-weight: 600;
	cursor: pointer;
	border-radius: 8px;
	transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

.project-notes-list__sub-tab:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.project-notes-list__sub-tab--active {
	background: var(--color-main-background);
	color: var(--color-main-text);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}

.project-notes-list__tab-badge {
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

.project-notes-list__loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 20px;
	padding: 80px 32px;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

.project-notes-list__empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 20px;
	padding: 80px 40px;
	background: var(--color-background-hover);
	border-radius: 24px;
	text-align: center;
}

.project-notes-list__empty-icon-wrapper {
	position: relative;
	color: var(--color-text-maxcontrast);
	opacity: 0.5;
	margin-bottom: 8px;
}

.project-notes-list__empty-title {
	margin: 0;
	font-size: 22px;
	font-weight: 800;
	color: var(--color-main-text);
}

.project-notes-list__empty-subtitle {
	margin: 0;
	font-size: 15px;
	color: var(--color-text-lighter);
	max-width: 380px;
	line-height: 1.6;
}

.project-notes-list__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
	gap: 24px;
}

.project-notes-list__note-card {
	display: flex;
	flex-direction: column;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 20px;
	cursor: pointer;
	transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
	overflow: hidden;
	height: 220px;
	position: relative;
}

.project-notes-list__note-card:hover {
	border-color: var(--color-primary-element);
	box-shadow: 0 12px 30px rgba(0, 0, 0, 0.08);
	transform: translateY(-6px);
}

.project-notes-list__note-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	padding: 20px 20px 14px;
	gap: 12px;
}

.project-notes-list__note-title-group {
	display: flex;
	flex-direction: column;
	gap: 6px;
	min-width: 0;
}

.project-notes-list__note-title {
	margin: 0;
	font-size: 17px;
	font-weight: 800;
	color: var(--color-main-text);
	line-height: 1.3;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.project-notes-list__note-date {
	font-size: 10px;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.06em;
}

.project-notes-list__note-content {
	padding: 0 20px;
	flex: 1;
	overflow: hidden;
	position: relative;
}

.project-notes-list__note-content::after {
	content: '';
	position: absolute;
	bottom: 0;
	left: 0;
	right: 0;
	height: 40px;
	background: linear-gradient(transparent, var(--color-main-background));
}

.project-notes-list__note-preview {
	margin: 0;
	font-size: 14px;
	color: var(--color-text-lighter);
	line-height: 1.6;
	display: -webkit-box;
	-webkit-line-clamp: 4;
	-webkit-box-orient: vertical;
	overflow: hidden;
}

.project-notes-list__note-footer {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 14px 20px;
	background: var(--color-background-hover);
	border-top: 1px solid var(--color-border);
}

.project-notes-list__note-author {
	display: flex;
	align-items: center;
	gap: 10px;
	min-width: 0;
}

.project-notes-list__author-avatar {
	width: 26px;
	height: 26px;
	border-radius: 50%;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 12px;
	font-weight: 800;
	flex-shrink: 0;
}

.project-notes-list__author-name {
	font-size: 13px;
	font-weight: 600;
	color: var(--color-text-lighter);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.project-notes-list__note-type {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 10px;
	font-weight: 800;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	padding: 4px 8px;
	border-radius: 6px;
}

.project-notes-list__note-type--public {
	color: var(--color-success);
	background: var(--color-success-light);
}

.project-notes-list__note-type--private {
	color: var(--color-warning);
	background: var(--color-warning-light);
}

.project-notes-list__note-type--card {
	color: var(--color-primary-element);
	background: var(--color-primary-element-light);
}

.project-notes-list__note-actions {
	display: flex;
	gap: 6px;
	opacity: 0;
	transition: opacity 0.2s ease;
}

.project-notes-list__note-card:hover .project-notes-list__note-actions {
	opacity: 1;
}

.project-notes-list__action-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
	border: 1px solid var(--color-border-dark);
	background: var(--color-main-background);
	color: var(--color-text-lighter);
	cursor: pointer;
	border-radius: 10px;
	transition: all 0.2s ease;
}

.project-notes-list__action-btn:hover {
	background: var(--color-error);
	color: white;
	border-color: var(--color-error);
	box-shadow: 0 4px 10px var(--color-error-light);
}

.project-notes-list__comments-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.project-notes-list__comment-item {
	display: flex;
	flex-direction: column;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 16px;
	overflow: hidden;
	transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

.project-notes-list__comment-item:hover {
	border-color: var(--color-primary-element);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
}

.project-notes-list__comment-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 16px 20px 12px;
	gap: 12px;
}

.project-notes-list__comment-author {
	display: flex;
	align-items: center;
	gap: 10px;
	min-width: 0;
}

.project-notes-list__comment-date {
	font-size: 11px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.project-notes-list__comment-body {
	padding: 0 20px 12px;
}

.project-notes-list__comment-message {
	margin: 0;
	font-size: 14px;
	color: var(--color-main-text);
	line-height: 1.6;
	white-space: pre-wrap;
}

.project-notes-list__comment-footer {
	display: flex;
	align-items: center;
	padding: 12px 20px;
	background: var(--color-background-hover);
	border-top: 1px solid var(--color-border);
}

.project-notes-list__comment-card-badge {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	font-size: 12px;
	font-weight: 700;
	color: var(--color-primary-element);
	background: var(--color-primary-element-light);
	padding: 4px 10px;
	border-radius: 8px;
	cursor: pointer;
	transition: all 0.2s ease;
}

.project-notes-list__comment-card-badge:hover {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.project-notes-list__pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 16px;
	padding: 24px 0 12px;
}

.project-notes-list__pagination-info {
	font-size: 14px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.project-notes-list__chat-list {
	display: flex;
	flex-direction: column;
	gap: 4px;
	background: var(--color-background-dark);
	border-radius: 20px;
	padding: 16px;
	max-height: 600px;
	overflow-y: auto;
}

.project-notes-list__chat-message {
	display: flex;
	gap: 12px;
	padding: 12px 16px;
	border-radius: 12px;
	transition: background 0.15s ease;
}

.project-notes-list__chat-message:hover {
	background: var(--color-background-hover);
}

.project-notes-list__chat-avatar {
	width: 36px;
	height: 36px;
	border-radius: 50%;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 14px;
	font-weight: 800;
	flex-shrink: 0;
}

.project-notes-list__chat-content {
	flex: 1;
	min-width: 0;
}

.project-notes-list__chat-header {
	display: flex;
	align-items: baseline;
	gap: 10px;
	margin-bottom: 4px;
}

.project-notes-list__chat-author {
	font-size: 13px;
	font-weight: 700;
	color: var(--color-main-text);
}

.project-notes-list__chat-time {
	font-size: 11px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.project-notes-list__chat-text {
	margin: 0;
	font-size: 14px;
	color: var(--color-main-text);
	line-height: 1.5;
	white-space: pre-wrap;
	word-break: break-word;
}

.project-notes-list__chat-load-more {
	display: flex;
	justify-content: center;
	padding: 12px 0 4px;
}

@media (max-width: 1000px) {
	.project-notes-list__header {
		flex-direction: column;
		align-items: stretch;
		gap: 20px;
	}

	.project-notes-list__main-tabs {
		width: 100%;
	}

	.project-notes-list__main-tab {
		flex: 1;
		justify-content: center;
	}

	.project-notes-list__sub-tabs {
		align-self: stretch;
	}

	.project-notes-list__sub-tab {
		flex: 1;
		justify-content: center;
	}
}

@media (max-width: 600px) {
	.project-notes-list__grid {
		grid-template-columns: 1fr;
	}
}
</style>
