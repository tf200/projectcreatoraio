<template>
	<div class="member-access-summary">
		<div v-if="loading" class="member-access-summary__state" role="status">
			<NcLoadingIcon :size="28" />
			<span>Loading permissions overview...</span>
		</div>

		<div v-else-if="error" class="member-access-summary__state member-access-summary__state--error" role="alert">
			<strong>Permissions overview could not be loaded.</strong>
			<span>{{ error }}</span>
			<NcButton type="secondary" @click="loadSummary">
				Retry
			</NcButton>
		</div>

		<template v-else-if="summary">
			<!-- EXECUTIVE METRICS HEADER BAR -->
			<header class="member-access-summary__header">
				<div class="member-access-summary__header-info">
					<div class="member-access-summary__scope">
						{{ scopeLabel }}
					</div>
					<h4>{{ scopeTitle }}</h4>
					<p>{{ scopeDescription }}</p>
				</div>
				<div class="member-access-summary__metrics-grid">
					<div class="member-access-summary__metric">
						<strong>{{ totalMembersCount }}</strong>
						<span>Total Members</span>
					</div>
					<div class="member-access-summary__metric member-access-summary__metric--success">
						<strong>{{ fullAccessCount }}</strong>
						<span>Full Edit Access</span>
					</div>
					<div class="member-access-summary__metric member-access-summary__metric--warning">
						<strong>{{ readOnlyAccessCount }}</strong>
						<span>Read Only</span>
					</div>
					<div class="member-access-summary__metric member-access-summary__metric--danger">
						<strong>{{ deniedAccessCount }}</strong>
						<span>Access Denied</span>
					</div>
					<div class="member-access-summary__metric">
						<strong>{{ totalCards }}</strong>
						<span>Active Cards</span>
					</div>
				</div>
			</header>

			<!-- SEARCH & FILTER TOOLBAR -->
			<div class="member-access-summary__toolbar">
				<div class="member-access-summary__search">
					<Magnify :size="18" class="member-access-summary__search-icon" />
					<input
						v-model.trim="searchQuery"
						type="search"
						placeholder="Search members by name..."
						class="member-access-summary__search-input"
						aria-label="Search members by name">
				</div>
				<div class="member-access-summary__filters">
					<select v-model="accessFilter" class="member-access-summary__select" aria-label="Filter members by access level">
						<option value="all">All Access Levels ({{ totalMembersCount }})</option>
						<option value="edit">Full Edit Access ({{ fullAccessCount }})</option>
						<option value="read">Read Only Access ({{ readOnlyAccessCount }})</option>
						<option value="denied">Access Denied ({{ deniedAccessCount }})</option>
					</select>
					<span class="member-access-summary__count-badge">
						Showing {{ filteredMemberRows.length }} of {{ totalMembersCount }} members
					</span>
				</div>
			</div>

			<!-- EMPTY STATE -->
			<div v-if="filteredMemberRows.length === 0" class="member-access-summary__state">
				<strong>No members match your filter</strong>
				<span>Try searching for a different member name or changing the access filter.</span>
			</div>

			<!-- MEMBER CARDS GRID -->
			<div v-else class="member-access-summary__members">
				<article v-for="member in filteredMemberRows" :key="member.id" class="member-access-summary__member">
					<div class="member-access-summary__person">
						<NcAvatar
							:user="member.id"
							:display-name="member.displayName"
							:size="44" />
						<div class="member-access-summary__identity">
							<div class="member-access-summary__name-line">
								<h5>{{ member.displayName }}</h5>
								<span v-if="member.isOwner" class="member-access-summary__owner">Owner</span>
							</div>
							<div class="member-access-summary__roles">
								<span
									v-for="role in member.drasciRoles"
									:key="role.key"
									class="member-access-summary__role member-access-summary__role--drasci">
									DRASCIVS: {{ role.label }}
								</span>
								<span
									v-for="role in member.functionalRoles"
									:key="role.key"
									class="member-access-summary__role">
									{{ role.label }}
								</span>
								<span v-if="member.functionalRoles.length === 0" class="member-access-summary__role member-access-summary__role--empty">
									No functional role
								</span>
							</div>
						</div>
						<div class="member-access-summary__board-state" :class="{ 'member-access-summary__board-state--denied': !member.hasBoardAccess }">
							<span class="member-access-summary__status-dot" />
							{{ member.boardAccessLabel }}
						</div>
					</div>

					<div class="member-access-summary__actions">
						<div
							v-for="action in member.actionRows"
							:key="action.key"
							class="member-access-summary__action"
							:class="`member-access-summary__action--${action.state}`">
							<div class="member-access-summary__action-heading">
								<div class="member-access-summary__action-title-wrap">
									<component :is="actionIcon(action.key)" :size="16" class="member-access-summary__action-icon" />
									<strong>{{ action.label }}</strong>
								</div>
								<span class="member-access-summary__action-status">{{ action.statusLabel }}</span>
							</div>
							<button
								v-if="action.allowedCards.length > 0"
								type="button"
								class="member-access-summary__details-toggle"
								:aria-expanded="isExpanded(member.id, action.key) ? 'true' : 'false'"
								@click="toggleCards(member.id, action.key)">
								{{ isExpanded(member.id, action.key) ? 'Hide cards' : 'Show cards' }}
								<ChevronDown
									:size="16"
									:class="{ 'member-access-summary__chevron--open': isExpanded(member.id, action.key) }" />
							</button>
							<ul v-if="isExpanded(member.id, action.key)" class="member-access-summary__card-list">
								<li v-for="card in action.allowedCards" :key="card.id">
									{{ card.title }}
								</li>
							</ul>
						</div>
					</div>
				</article>
			</div>
		</template>
	</div>
</template>

<script>
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import Pen from 'vue-material-design-icons/Pen.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'

import { ProjectsService } from '../../Services/projects.js'

const projectsService = ProjectsService.getInstance()
const ACTIONS = [
	{ key: 'view', label: 'View' },
	{ key: 'move', label: 'Move' },
	{ key: 'verify', label: 'Verify' },
	{ key: 'sign', label: 'Sign' },
]

export default {
	name: 'MemberAccessSummary',
	components: {
		ChevronDown,
		NcAvatar,
		NcButton,
		NcLoadingIcon,
		Eye,
		SwapHorizontal,
		CheckCircle,
		Pen,
		Magnify,
	},
	props: {
		projectId: {
			type: [String, Number],
			default: null,
		},
	},
	data() {
		return {
			summary: null,
			loading: false,
			error: '',
			expandedActions: {},
			requestId: 0,
			searchQuery: '',
			accessFilter: 'all',
		}
	},
	computed: {
		normalizedProjectId() {
			const projectId = Number(this.projectId)
			return Number.isFinite(projectId) && projectId > 0 ? projectId : null
		},
		totalCards() {
			return Math.max(0, Number(this.summary?.totalCards) || 0)
		},
		totalMembersCount() {
			return this.memberRows.length
		},
		fullAccessCount() {
			return this.memberRows.filter(m => m.boardAccess === 'edit').length
		},
		readOnlyAccessCount() {
			return this.memberRows.filter(m => m.boardAccess === 'read').length
		},
		deniedAccessCount() {
			return this.memberRows.filter(m => !m.hasBoardAccess || m.boardAccess === 'none').length
		},
		scopeLabel() {
			return this.summary?.scope === 'team' ? 'Team scope' : 'Self scope'
		},
		scopeTitle() {
			return this.summary?.scope === 'team' ? 'Team access' : 'Your access'
		},
		scopeDescription() {
			if (this.summary?.scope !== 'team') {
				return 'Permissions available to your account on this board.'
			}
			const count = this.memberRows.length
			return `${count} ${count === 1 ? 'member' : 'members'} included in this overview.`
		},
		filteredMemberRows() {
			let list = this.memberRows
			if (this.searchQuery.trim()) {
				const q = this.searchQuery.toLowerCase().trim()
				list = list.filter(m => m.displayName.toLowerCase().includes(q) || m.id.toLowerCase().includes(q))
			}
			if (this.accessFilter !== 'all') {
				list = list.filter(m => {
					if (this.accessFilter === 'edit') return m.boardAccess === 'edit'
					if (this.accessFilter === 'read') return m.boardAccess === 'read'
					if (this.accessFilter === 'denied') return !m.hasBoardAccess || m.boardAccess === 'none'
					return true
				})
			}
			return list
		},
		memberRows() {
			const members = Array.isArray(this.summary?.members) ? this.summary.members : []
			return members.map(member => {
				const boardAccess = String(member.boardAccess || 'none')
				const hasBoardAccess = boardAccess !== 'none'
				const drasciRoleKeys = Array.isArray(member.drascivsRoles)
					? member.drascivsRoles
					: (Array.isArray(member.drasciRoles)
						? member.drasciRoles
						: (member.drasciRole ? [member.drasciRole] : []))
				const drasciRoleLabels = Array.isArray(member.drascivsRoleLabels)
					? member.drascivsRoleLabels
					: (Array.isArray(member.drasciRoleLabels)
						? member.drasciRoleLabels
						: (member.drasciRoleLabel ? [member.drasciRoleLabel] : drasciRoleKeys))
				const drasciRoles = drasciRoleLabels.map((label, index) => ({
					key: drasciRoleKeys[index] || `${label}:${index}`,
					label,
				}))
				const functionalRoleKeys = Array.isArray(member.functionalRoleKeys) ? member.functionalRoleKeys : []
				const functionalRoleLabels = Array.isArray(member.functionalRoleLabels) ? member.functionalRoleLabels : []
				const functionalRoles = functionalRoleLabels
					.map((label, index) => ({
						key: functionalRoleKeys[index] || `${label}:${index}`,
						label,
					}))
					.filter(role => role.label)

				return {
					...member,
					id: String(member.id || ''),
					displayName: member.displayName || member.id || 'Unknown member',
					drasciRoles,
					functionalRoles,
					hasBoardAccess,
					boardAccessLabel: boardAccess === 'edit'
						? 'Board edit access'
						: (boardAccess === 'read' ? 'Board read access' : 'Board access denied'),
					actionRows: ACTIONS.map(action => this.buildActionRow(member, action, hasBoardAccess)),
				}
			})
		},
	},
	watch: {
		normalizedProjectId: {
			immediate: true,
			handler() {
				this.loadSummary()
			},
		},
	},
	beforeDestroy() {
		this.requestId += 1
	},
	methods: {
		actionIcon(key) {
			switch (key) {
				case 'view': return 'Eye'
				case 'move': return 'SwapHorizontal'
				case 'verify': return 'CheckCircle'
				case 'sign': return 'Pen'
				default: return 'Eye'
			}
		},
		async loadSummary() {
			const requestId = ++this.requestId
			this.summary = null
			this.error = ''
			this.expandedActions = {}

			if (!this.normalizedProjectId) {
				this.loading = false
				this.error = 'No project is available for this board.'
				return
			}

			this.loading = true
			try {
				const summary = await projectsService.getDeckAccessSummary(this.normalizedProjectId)
				if (!summary || typeof summary !== 'object') {
					throw new Error('Invalid permissions overview response')
				}
				if (requestId === this.requestId) {
					this.summary = summary
				}
			} catch (error) {
				if (requestId === this.requestId) {
					this.error = 'Check your connection and try again.'
				}
			} finally {
				if (requestId === this.requestId) {
					this.loading = false
				}
			}
		},
		buildActionRow(member, actionDefinition, hasBoardAccess) {
			const action = member.actions?.[actionDefinition.key] || {}
			const status = String(action.status || 'none')
			const total = Math.max(0, Number(action.total) || 0)
			const allowed = Math.max(0, Number(action.allowed) || 0)
			const allowedCards = Array.isArray(action.allowedCards) ? action.allowedCards : []

			if (!hasBoardAccess) {
				return {
					...actionDefinition,
					state: 'denied',
					statusLabel: 'Board access denied',
					allowedCards: [],
				}
			}
			if (status === 'all') {
				return {
					...actionDefinition,
					state: 'all',
					statusLabel: 'All cards',
					allowedCards,
				}
			}
			if (status === 'some') {
				return {
					...actionDefinition,
					state: 'some',
					statusLabel: `Some cards (${allowed}/${total})`,
					allowedCards,
				}
			}
			return {
				...actionDefinition,
				state: 'none',
				statusLabel: 'No cards',
				allowedCards: [],
			}
		},
		expansionKey(memberId, action) {
			return `${memberId}:${action}`
		},
		isExpanded(memberId, action) {
			return this.expandedActions[this.expansionKey(memberId, action)] === true
		},
		toggleCards(memberId, action) {
			const key = this.expansionKey(memberId, action)
			this.$set(this.expandedActions, key, !this.expandedActions[key])
		},
	},
}
</script>

<style scoped>
.member-access-summary {
	display: grid;
	gap: 20px;
}

.member-access-summary__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 24px;
	padding: 20px 24px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background);
	box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);
}

.member-access-summary__header-info {
	flex: 1;
	min-width: 200px;
}

.member-access-summary__scope {
	margin-bottom: 3px;
	color: var(--color-primary-element);
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.08em;
	text-transform: uppercase;
}

.member-access-summary__header h4,
.member-access-summary__header p {
	margin: 0;
}

.member-access-summary__header h4 {
	font-size: 18px;
	font-weight: bold;
}

.member-access-summary__header p {
	margin-top: 3px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.member-access-summary__metrics-grid {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.member-access-summary__metric {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 10px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
	min-width: 90px;
	text-align: center;
}

.member-access-summary__metric strong {
	font-size: 20px;
	font-weight: bold;
	line-height: 1.1;
}

.member-access-summary__metric span {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
	white-space: nowrap;
}

.member-access-summary__metric--success strong { color: var(--color-success); }
.member-access-summary__metric--warning strong { color: var(--color-warning); }
.member-access-summary__metric--danger strong { color: var(--color-error); }

/* TOOLBAR */
.member-access-summary__toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background);
}

.member-access-summary__search {
	position: relative;
	flex: 1;
	max-width: 380px;
	display: flex;
	align-items: center;
}

.member-access-summary__search-icon {
	position: absolute;
	left: 12px;
	color: var(--color-text-maxcontrast);
	pointer-events: none;
}

.member-access-summary__search-input {
	width: 100%;
	padding: 8px 16px 8px 36px;
	border: 1px solid var(--color-border);
	border-radius: 20px;
	background: var(--color-background-hover);
	color: var(--color-main-text);
	font-size: 13px;
	transition: border-color 0.2s;
}

.member-access-summary__search-input:focus {
	border-color: var(--color-primary-element);
	outline: none;
}

.member-access-summary__filters {
	display: flex;
	align-items: center;
	gap: 12px;
}

.member-access-summary__select {
	padding: 8px 16px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-background-hover);
	color: var(--color-main-text);
	font-size: 13px;
}

.member-access-summary__count-badge {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.member-access-summary__state {
	display: flex;
	min-height: 160px;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 10px;
	padding: 24px;
	border: 1px dashed var(--color-border-dark);
	border-radius: var(--border-radius-large, 8px);
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.member-access-summary__state strong {
	color: var(--color-main-text);
}

.member-access-summary__state--error {
	border-color: var(--color-error);
}

.member-access-summary__members {
	display: grid;
	gap: 14px;
}

.member-access-summary__member {
	padding: 18px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background);
}

.member-access-summary__person {
	display: grid;
	grid-template-columns: auto minmax(0, 1fr) auto;
	align-items: center;
	gap: 12px;
}

.member-access-summary__identity {
	min-width: 0;
}

.member-access-summary__name-line {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.member-access-summary__name-line h5 {
	margin: 0;
	font-size: 16px;
	overflow-wrap: anywhere;
}

.member-access-summary__owner {
	padding: 2px 8px;
	border-radius: 999px;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
	font-size: 11px;
	font-weight: 700;
}

.member-access-summary__roles {
	display: flex;
	gap: 6px;
	margin-top: 7px;
	flex-wrap: wrap;
}

.member-access-summary__role {
	max-width: 100%;
	padding: 2px 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: 999px;
	font-size: 11px;
	overflow-wrap: anywhere;
}

.member-access-summary__role--drasci {
	border-color: var(--color-primary-element);
	color: var(--color-primary-element);
}

.member-access-summary__role--empty {
	border-style: dashed;
	color: var(--color-text-maxcontrast);
}

.member-access-summary__board-state {
	display: inline-flex;
	align-items: center;
	gap: 7px;
	color: var(--color-success);
	font-size: 12px;
	font-weight: 600;
	white-space: nowrap;
}

.member-access-summary__board-state--denied {
	color: var(--color-error);
}

.member-access-summary__status-dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	background: currentColor;
}

.member-access-summary__actions {
	display: grid;
	grid-template-columns: repeat(4, minmax(0, 1fr));
	gap: 10px;
	margin-top: 16px;
}

.member-access-summary__action {
	min-width: 0;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-top: 3px solid var(--color-text-maxcontrast);
	border-radius: var(--border-radius, 3px);
	background: var(--color-background-hover);
}

.member-access-summary__action--all {
	border-top-color: var(--color-success);
}

.member-access-summary__action--some {
	border-top-color: var(--color-warning);
}

.member-access-summary__action--denied {
	border-top-color: var(--color-error);
}

.member-access-summary__action-heading {
	display: grid;
	gap: 3px;
}

.member-access-summary__action-title-wrap {
	display: flex;
	align-items: center;
	gap: 6px;
}

.member-access-summary__action-icon {
	color: var(--color-primary-element);
	flex-shrink: 0;
}

.member-access-summary__action-heading strong {
	font-size: 13px;
}

.member-access-summary__action-status {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	line-height: 1.35;
}

.member-access-summary__details-toggle {
	display: inline-flex;
	align-items: center;
	gap: 2px;
	min-height: auto;
	padding: 5px 0 0;
	border: 0;
	background: transparent;
	color: var(--color-primary-element);
	font-size: 12px;
	font-weight: 600;
	cursor: pointer;
}

.member-access-summary__details-toggle svg {
	transition: transform 0.15s ease;
}

.member-access-summary__chevron--open {
	transform: rotate(180deg);
}

.member-access-summary__card-list {
	display: grid;
	gap: 4px;
	margin: 8px 0 0;
	padding-left: 18px;
	color: var(--color-main-text);
	font-size: 12px;
}

.member-access-summary__card-list li {
	overflow-wrap: anywhere;
}

@media (max-width: 900px) {
	.member-access-summary__actions {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}
}

@media (max-width: 600px) {
	.member-access-summary__header {
		align-items: flex-start;
		flex-direction: column;
		gap: 14px;
		padding: 16px;
	}

	.member-access-summary__card-count {
		padding: 0;
		border: 0;
	}

	.member-access-summary__member {
		padding: 14px;
	}

	.member-access-summary__person {
		grid-template-columns: auto minmax(0, 1fr);
		align-items: start;
	}

	.member-access-summary__board-state {
		grid-column: 1 / -1;
	}

	.member-access-summary__actions {
		grid-template-columns: 1fr;
	}
}
</style>
