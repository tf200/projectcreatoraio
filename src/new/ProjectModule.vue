<template>
	<section class="project-module">
		<div v-if="unavailable" class="project-module__state" role="status">
			<p>{{ unavailable }}</p>
			<a :href="legacyUrl">{{ t('projectcreatoraio', 'Open current interface') }}</a>
		</div>
		<template v-else>
			<div v-if="needsMembers && membersLoading" class="project-module__state" role="status">
				{{ t('projectcreatoraio', 'Loading members…') }}
			</div>
			<div v-else-if="needsMembers && membersError" class="project-module__state" role="alert">
				<p>{{ membersError }}</p>
				<button type="button" @click="loadMembers">{{ t('projectcreatoraio', 'Try again') }}</button>
			</div>
			<template v-else>
				<p v-if="moduleLoading" class="project-module__state" role="status">{{ t('projectcreatoraio', 'Loading section…') }}</p>
				<div v-else-if="moduleError" class="project-module__state" role="alert">
					<p>{{ moduleError }}</p>
					<button type="button" @click="loadModule">{{ t('projectcreatoraio', 'Try again') }}</button>
					<a :href="legacyUrl">{{ t('projectcreatoraio', 'Open current interface') }}</a>
				</div>
				<template v-else-if="moduleComponent">
					<header v-if="tab === 'documents'" class="pc-view__head">
						<div class="pc-view__heading">
							<h2 class="pc-view__title">{{ t('projectcreatoraio', 'Documents') }}</h2>
							<p class="pc-view__lede">{{ t('projectcreatoraio', 'Shared and private project files, with OCR and signing.') }}</p>
						</div>
					</header>
					<component :is="moduleComponent" :key="scopeKey" :class="{ 'pc-tasks-theme': tab === 'tasks', 'pc-notes-theme': tab === 'notes', 'pc-whiteboard-theme': tab === 'whiteboard', 'pc-documents-theme': tab === 'documents' }" v-bind="moduleProps" @refresh="loadFiles" @open-direct-chat="$emit('direct-chat', $event)" @clear-target-direct-user="$emit('direct-chat', null)" />
				</template>
				<button v-if="tab === 'documents' && filesError" type="button" @click="loadFiles">{{ t('projectcreatoraio', 'Reload documents') }}</button>
			</template>
		</template>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import { api } from './api.js'

const loaders = {
	tasks: () => import('./NewTasks.vue'),
	notes: () => import('../components/ProjectNotesList.vue'),
	planning: () => import('../components/ProjectTimeline/GanttChart.vue'),
	documents: () => import('../components/ProjectFiles/ProjectFilesBrowser.vue'),
	intake: () => import('./NewIntake.vue'),
	whiteboard: () => import('../components/ProjectWhiteboard/WhiteboardBoard.vue'),
	activity: () => import('./NewActivity.vue'),
	members: () => import('./NewMembers.vue'),
	agenda: () => import('../components/ProjectCalendar.vue'),
}

export default {
	name: 'ProjectModule',
	props: {
		project: { type: Object, required: true },
		context: { type: Object, required: true },
		tab: { type: String, required: true },
		legacyUrl: { type: String, required: true },
		// A member picked for a direct chat from the Members tab, opened by Notes.
		directChatUser: { type: Object, default: null },
	},
	data() {
		return {
			alive: true,
			generation: 0,
			fileRequest: 0,
			memberRequest: 0,
			moduleRequest: 0,
			moduleComponent: null,
			moduleLoading: false,
			moduleError: '',
			files: { shared: [], private: [] },
			filesLoading: false,
			filesError: '',
			members: [],
			membersLoading: false,
			membersError: '',
		}
	},
	computed: {
		projectId() { return Number(this.project.id) },
		currentUserId() { return String(this.context.userId || '').trim() },
		// Notes needs the team for its direct chats; Members loads its own.
		needsMembers() { return this.tab === 'notes' },
		canManageProject() { return !!(this.context.isGlobalAdmin || this.context.organizationRole === 'admin' || (this.currentUserId && String(this.project.ownerId || '').trim() === this.currentUserId)) },
		unavailable() {
			if (!Number.isSafeInteger(this.projectId) || this.projectId <= 0) return t('projectcreatoraio', 'This project is unavailable.')
			const feature = { tasks: 'deck', intake: 'deck', agenda: 'calendar', whiteboard: 'whiteboard' }[this.tab]
			if (feature && this.context.features?.[feature] === false) return t('projectcreatoraio', 'This section is not enabled.')
			if (this.tab === 'intake' && Number(this.project.type) !== 0) return t('projectcreatoraio', 'The intake form is only available for Combi projects.')
			if (!loaders[this.tab]) return t('projectcreatoraio', 'This section is available in the current interface.')
			return ''
		},
		scopeKey() { return `${this.projectId}:${this.tab}:${this.unavailable}` },
		moduleProps() {
			const base = { projectId: this.projectId }
			switch (this.tab) {
				case 'tasks': return { ...base, boardId: this.project.boardId }
				case 'notes': return {
					...base,
					members: this.members,
					currentUserId: this.currentUserId,
					talkConversationToken: this.context.features?.talk === false ? '' : String(this.project.talk_conversation_token || ''),
					talkUrl: this.context.features?.talk === false ? '' : String(this.project.talk_url || ''),
					targetDirectUser: this.directChatUser,
				}
				// Legacy timeline editing is available to project viewers, not only admins.
				case 'planning': return { ...base, isAdmin: !!(this.context.isGlobalAdmin || this.context.organizationId != null) }
				case 'documents': return { ...base, sharedRoots: this.files.shared, privateRoots: this.files.private, loading: this.filesLoading, error: this.filesError }
				case 'intake': return { ...base, canEdit: this.canManageProject }
				case 'members': return { ...base, currentUserId: this.currentUserId, organizationId: Number(this.project.organization_id) > 0 ? Number(this.project.organization_id) : (this.context.organizationId ?? null), canManage: this.canManageProject }
				case 'whiteboard': return { ...base, userId: this.currentUserId, inlineEditing: true, openMode: 'overlay', activityReader: api.whiteboardActivity, activityComponent: () => import('./NewWhiteboardActivity.vue') }
				default: return base
			}
		},
	},
	watch: {
		scopeKey: { immediate: true, handler() { this.reset() } },
	},
	beforeDestroy() {
		this.alive = false
		this.generation++
	},
	methods: {
		t,
		reset() {
			this.generation++
			this.moduleComponent = null
			this.moduleError = ''
			this.moduleLoading = false
			this.files = { shared: [], private: [] }
			this.filesError = ''
			this.filesLoading = false
			this.members = []
			this.membersError = ''
			this.membersLoading = false
			if (this.unavailable) return
			if (this.needsMembers) this.loadMembers()
			if (this.tab === 'documents') this.loadFiles()
			this.loadModule()
		},
		isCurrent(generation, projectId, tab) {
			return this.alive && this.generation === generation && this.projectId === projectId && this.tab === tab
		},
		async loadModule() {
			const { generation, projectId, tab } = this
			const request = ++this.moduleRequest
			const current = () => this.isCurrent(generation, projectId, tab) && request === this.moduleRequest
			if (this.unavailable || !loaders[tab]) return
			this.moduleLoading = true
			this.moduleError = ''
			try {
				const module = await loaders[tab]()
				if (current()) this.moduleComponent = module.default
			} catch (error) {
				if (current()) this.moduleError = t('projectcreatoraio', 'The section could not be loaded.')
			} finally {
				if (current()) this.moduleLoading = false
			}
		},
		async loadFiles() {
			if (!this.alive || this.unavailable || this.tab !== 'documents') return
			const { generation, projectId, tab } = this
			const request = ++this.fileRequest
			const current = () => this.isCurrent(generation, projectId, tab) && request === this.fileRequest
			this.filesLoading = true
			this.filesError = ''
			try {
				const { data } = await axios.get(generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/files`), { headers: { 'OCS-APIRequest': 'true' } })
				const files = data?.files ?? data
				if (!files || !Array.isArray(files.shared) || !Array.isArray(files.private)) throw new Error('Invalid file response')
				if (current()) this.files = { shared: files.shared, private: files.private }
			} catch (error) {
				if (current()) {
					this.files = { shared: [], private: [] }
					this.filesError = t('projectcreatoraio', 'Documents could not be loaded. Check your access or try again.')
				}
			} finally {
				if (current()) this.filesLoading = false
			}
		},
		async loadMembers() {
			if (!this.alive || this.unavailable || !this.needsMembers) return
			const { generation, projectId, tab } = this
			const request = ++this.memberRequest
			const current = () => this.isCurrent(generation, projectId, tab) && request === this.memberRequest
			this.membersLoading = true
			this.membersError = ''
			try {
				const { data } = await axios.get(generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/members`), { headers: { 'OCS-APIRequest': 'true' } })
				if (!Array.isArray(data?.members)) throw new Error('Invalid members response')
				if (current()) this.members = data.members
			} catch (error) {
				if (current()) {
					this.members = []
					this.membersError = t('projectcreatoraio', 'Project members could not be loaded. Check your access or try again.')
				}
			} finally {
				if (current()) this.membersLoading = false
			}
		},
	},
}
</script>

<style scoped>
.project-module { min-width: 0; color: var(--color-main-text); }
.project-module__state { padding: 24px; background: var(--color-background-hover); border-radius: var(--border-radius-large); }
.project-module__state p { margin-bottom: 12px; }
.project-module__state a { display: inline-block; margin: 8px; }
.project-module a { color: var(--color-primary-element); text-decoration: underline; }
</style>
