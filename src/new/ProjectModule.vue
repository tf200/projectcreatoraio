<template>
	<section class="project-module">
		<div v-if="unavailable" class="project-module__state" role="status">
			<p>{{ unavailable }}</p>
			<a :href="legacyUrl">{{ t('projectcreatoraio', 'Open de huidige interface') }}</a>
		</div>
		<template v-else>
			<div v-if="tab === 'members'" class="project-module__members">
				<p>{{ t('projectcreatoraio', 'Bekijk de projectleden en hun rollen. Beheer leden in de huidige interface.') }}</p>
				<a :href="legacyUrl">{{ t('projectcreatoraio', 'Leden beheren') }}</a>
			</div>
			<div v-if="needsMembers && membersLoading" class="project-module__state" role="status">
				{{ t('projectcreatoraio', 'Leden laden…') }}
			</div>
			<div v-else-if="needsMembers && membersError" class="project-module__state" role="alert">
				<p>{{ membersError }}</p>
				<button type="button" @click="loadMembers">{{ t('projectcreatoraio', 'Opnieuw proberen') }}</button>
			</div>
			<template v-else-if="tab === 'members'">
				<p v-if="!members.length" role="status">{{ t('projectcreatoraio', 'Geen projectleden gevonden.') }}</p>
				<ul v-else class="project-module__member-list">
					<li v-for="member in members" :key="member.id">
						<strong>{{ member.displayName || member.id }}</strong>
						<span>{{ memberRoles(member) }}</span>
					</li>
				</ul>
			</template>
			<template v-else>
				<p v-if="moduleLoading" class="project-module__state" role="status">{{ t('projectcreatoraio', 'Onderdeel laden…') }}</p>
				<div v-else-if="moduleError" class="project-module__state" role="alert">
					<p>{{ moduleError }}</p>
					<button type="button" @click="loadModule">{{ t('projectcreatoraio', 'Opnieuw proberen') }}</button>
					<a :href="legacyUrl">{{ t('projectcreatoraio', 'Open de huidige interface') }}</a>
				</div>
				<component :is="moduleComponent" v-else-if="moduleComponent" :key="scopeKey" v-bind="moduleProps" @refresh="loadFiles" />
				<button v-if="tab === 'documents' && filesError" type="button" @click="loadFiles">{{ t('projectcreatoraio', 'Documenten opnieuw laden') }}</button>
			</template>
		</template>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'

const loaders = {
	tasks: () => import('../components/ProjectDeck/DeckBoard.vue'),
	notes: () => import('../components/ProjectNotesList.vue'),
	planning: () => import('../components/ProjectTimeline/GanttChart.vue'),
	documents: () => import('../components/ProjectFiles/ProjectFilesBrowser.vue'),
	intake: () => import('../components/ProjectCardVisibilityTab.vue'),
	whiteboard: () => import('../components/ProjectWhiteboard/WhiteboardBoard.vue'),
	activity: () => import('../components/ProjectActivity/ProjectActivity.vue'),
	agenda: () => import('../components/ProjectCalendar.vue'),
}

export default {
	name: 'ProjectModule',
	props: {
		project: { type: Object, required: true },
		context: { type: Object, required: true },
		tab: { type: String, required: true },
		legacyUrl: { type: String, required: true },
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
			functionalRoles: [],
			membersLoading: false,
			membersError: '',
		}
	},
	computed: {
		projectId() { return Number(this.project.id) },
		currentUserId() { return String(this.context.userId || '').trim() },
		needsMembers() { return this.tab === 'members' || this.tab === 'notes' },
		unavailable() {
			if (!Number.isSafeInteger(this.projectId) || this.projectId <= 0) return t('projectcreatoraio', 'Dit project is niet beschikbaar.')
			const feature = { tasks: 'deck', intake: 'deck', agenda: 'calendar', whiteboard: 'whiteboard' }[this.tab]
			if (feature && this.context.features?.[feature] === false) return t('projectcreatoraio', 'Dit onderdeel is niet ingeschakeld.')
			if (this.tab === 'intake' && Number(this.project.type) !== 0) return t('projectcreatoraio', 'Het intakeformulier is alleen beschikbaar voor Combi-projecten.')
			if (this.tab !== 'members' && !loaders[this.tab]) return t('projectcreatoraio', 'Dit onderdeel is beschikbaar in de huidige interface.')
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
				}
				// Legacy timeline editing is available to project viewers, not only admins.
				case 'planning': return { ...base, isAdmin: !!(this.context.isGlobalAdmin || this.context.organizationId != null) }
				case 'documents': return { ...base, sharedRoots: this.files.shared, privateRoots: this.files.private, loading: this.filesLoading, error: this.filesError }
				case 'intake': return { ...base, canEdit: !!(this.context.isGlobalAdmin || this.context.organizationRole === 'admin' || (this.currentUserId && String(this.project.ownerId || '').trim() === this.currentUserId)) }
				case 'whiteboard': return { ...base, userId: this.currentUserId }
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
			this.functionalRoles = []
			this.membersError = ''
			this.membersLoading = false
			if (this.unavailable) return
			if (this.needsMembers) this.loadMembers()
			if (this.tab === 'documents') this.loadFiles()
			if (this.tab !== 'members') this.loadModule()
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
				if (current()) this.moduleError = t('projectcreatoraio', 'Het onderdeel kon niet worden geladen.')
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
					this.filesError = t('projectcreatoraio', 'Documenten konden niet worden geladen. Controleer je toegang of probeer het opnieuw.')
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
				if (current()) {
					this.members = data.members
					this.functionalRoles = Array.isArray(data.functionalRoles) ? data.functionalRoles : []
				}
			} catch (error) {
				if (current()) {
					this.members = []
					this.functionalRoles = []
					this.membersError = t('projectcreatoraio', 'Projectleden konden niet worden geladen. Controleer je toegang of probeer het opnieuw.')
				}
			} finally {
				if (current()) this.membersLoading = false
			}
		},
		memberRoles(member) {
			const roles = member.drascivsRoles || member.drasciRoles || (member.drasciRole ? [member.drasciRole] : [])
			const functional = (member.functionalRoleKeys || []).map(key => this.functionalRoles.find(role => role.key === key)?.name || key)
			return [...roles, ...functional].join(' · ') || t('projectcreatoraio', 'Geen rollen toegewezen')
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
.project-module__members { margin-bottom: 24px; }
.project-module__member-list { padding: 0; list-style: none; }
.project-module__member-list li { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; padding: 16px 0; border-bottom: 1px solid var(--color-border); }
.project-module__member-list span { color: var(--color-text-maxcontrast); }
</style>
