<template>
	<section class="pc-view iz-app" aria-label="Project members">
		<header class="pc-view__head">
			<div class="pc-view__heading">
				<h2 class="pc-view__title">
					Members
				</h2>
				<p class="pc-view__lede">
					Who works on this project, their DRASCIVS responsibilities and their project role.
				</p>
			</div>
			<button v-if="canManage && !adding && !loading && !error"
				type="button"
				class="iz-btn iz-btn--primary"
				@click="openAdd">
				<Plus :size="16" />
				Add member
			</button>
		</header>

		<p v-if="notice" class="pc-view__notice pc-view__notice--ok" role="status">
			<CheckCircle :size="16" />
			{{ notice }}
		</p>

		<section v-if="adding" class="iz-panel pc-member-add" aria-labelledby="pc-member-add-title">
			<div class="pc-member-add__head">
				<h3 id="pc-member-add-title" class="pc-member-add__title">
					Add a member
				</h3>
				<button type="button"
					class="iz-btn iz-btn--ghost pc-icon-btn"
					aria-label="Close"
					@click="closeAdd">
					<Close :size="16" />
				</button>
			</div>

			<div class="pc-member-add__grid">
				<div class="pc-member-add__person">
					<label for="pc-member-search" class="iz-label pc-member-add__label">Person</label>
					<div v-if="candidate" class="pc-member-picked">
						<NcAvatar :user="candidate.id"
							:display-name="candidate.label"
							:size="28"
							:show-user-status="false" />
						<div class="pc-member-picked__text">
							<strong>{{ candidate.label }}</strong>
							<span>{{ candidate.subname }}</span>
						</div>
						<button type="button"
							class="iz-btn iz-btn--ghost pc-icon-btn"
							:aria-label="'Choose someone other than ' + candidate.label"
							@click="clearCandidate">
							<Close :size="14" />
						</button>
					</div>
					<template v-else>
						<input id="pc-member-search"
							v-model="query"
							class="iz-input"
							type="search"
							autocomplete="off"
							placeholder="Search organisation members"
							@input="search">
						<ul v-if="results.length" class="pc-member-results" aria-label="Search results">
							<li v-for="user in results" :key="user.id">
								<button type="button" class="pc-member-result" @click="pick(user)">
									<NcAvatar :user="user.id"
										:display-name="user.label"
										:size="28"
										:show-user-status="false" />
									<span class="pc-member-result__text">
										<strong>{{ user.label }}</strong>
										<span>{{ user.subname }}</span>
									</span>
								</button>
							</li>
						</ul>
						<p v-else-if="searching" class="pc-member-hint" role="status">
							Searching…
						</p>
						<p v-else-if="searchError" class="pc-member-hint pc-member-hint--error" role="alert">
							{{ searchError }}
						</p>
						<p v-else-if="searchedFor && searchedFor === query.trim()" class="pc-member-hint">
							No one found who is not already on the project.
						</p>
					</template>
					<p class="pc-member-hint">
						Only people in this organisation who are not on the project yet.
					</p>
				</div>
				<div class="pc-member-add__roles">
					<RoleChips label="DRASCIVS"
						label-id="pc-add-drascivs"
						:options="drascivsOptions"
						:selected="addDraft.drascivs"
						:disabled="addSaving"
						@toggle="addDraft.drascivs = toggled(addDraft.drascivs, $event)" />
					<RoleChips label="Project role"
						label-id="pc-add-functional"
						:options="roleOptions"
						:selected="addDraft.functional"
						:disabled="addSaving"
						@toggle="addDraft.functional = toggled(addDraft.functional, $event)" />
				</div>
			</div>

			<div class="pc-member-add__foot">
				<span v-if="addError" class="pc-member-problem pc-member-problem--error" role="alert">{{ addError }}</span>
				<span v-else-if="addProblem" class="pc-member-problem">
					<InformationOutline :size="15" />
					{{ addProblem }}
				</span>
				<button type="button"
					class="iz-btn iz-btn--ghost"
					:disabled="addSaving"
					@click="closeAdd">
					Cancel
				</button>
				<button type="button"
					class="iz-btn iz-btn--primary"
					:disabled="!!addProblem || addSaving"
					@click="add">
					{{ addSaving ? 'Adding…' : 'Add to project' }}
				</button>
			</div>
		</section>

		<div v-if="loading" class="iz-empty" role="status">
			Loading members…
		</div>
		<div v-else-if="error" class="iz-empty pc-view__failure" role="alert">
			<span>{{ error }}</span>
			<button type="button" class="iz-btn" @click="load">
				Try again
			</button>
		</div>
		<div v-else class="pc-members-layout">
			<section class="iz-panel iz-panel--list pc-members" aria-label="Project members">
				<div v-if="!members.length" class="pc-members__empty">
					No members yet.
				</div>
				<template v-else>
					<div class="pc-members__head pc-members__grid" aria-hidden="true">
						<span>Member</span><span>DRASCIVS</span><span>Project role</span><span class="pc-members__actions-head">Actions</span>
					</div>
					<ul class="pc-members__list">
						<li v-for="member in members"
							:key="member.id"
							class="pc-member"
							:class="{ 'pc-member--editing': editingId === member.id }">
							<div class="pc-member__row pc-members__grid">
								<div class="pc-member__who">
									<NcAvatar :user="member.id"
										:display-name="member.displayName || member.id"
										:size="32"
										:show-user-status="false" />
									<div class="pc-member__identity">
										<div class="pc-member__name-line">
											<span class="pc-member__name">{{ member.displayName || member.id }}</span>
											<span v-if="member.isOwner" class="iz-pill iz-pill--accent pc-member__owner">Owner</span>
										</div>
										<span class="pc-member__meta">{{ memberMeta(member) }}</span>
									</div>
								</div>
								<template v-if="editingId !== member.id">
									<div class="pc-member__pills" :aria-label="'DRASCIVS roles of ' + (member.displayName || member.id)">
										<span v-for="role in rolesOf(member)" :key="role" class="iz-pill pc-pill">{{ drascivsLabel(role) }}</span>
										<span v-if="!rolesOf(member).length" class="pc-member__none">None</span>
									</div>
									<div class="pc-member__pills" :aria-label="'Project roles of ' + (member.displayName || member.id)">
										<span v-for="role in projectRolesOf(member)"
											:key="role.value"
											class="iz-pill pc-pill"
											:class="'pc-tone--' + role.tone">{{ role.label }}</span>
										<span v-if="!projectRolesOf(member).length" class="pc-member__none">None</span>
									</div>
								</template>
								<template v-else>
									<span /><span />
								</template>
								<div class="pc-member__actions">
									<template v-if="editingId === member.id">
										<button type="button"
											class="iz-btn iz-btn--ghost iz-btn--sm"
											:disabled="editSaving"
											@click="cancelEdit">
											Cancel
										</button>
										<button type="button"
											class="iz-btn iz-btn--primary iz-btn--sm"
											:disabled="!!editProblem || editSaving"
											@click="saveEdit(member)">
											{{ editSaving ? 'Saving…' : 'Save' }}
										</button>
									</template>
									<template v-else>
										<button v-if="!isSelf(member)"
											type="button"
											class="iz-btn iz-btn--ghost iz-btn--sm"
											:aria-label="'Chat with ' + (member.displayName || member.id)"
											@click="$emit('open-direct-chat', member)">
											<ChatOutline :size="14" />
											Chat
										</button>
										<button v-if="canManage"
											type="button"
											class="iz-btn iz-btn--sm"
											:disabled="editingId !== null"
											:aria-label="'Edit roles of ' + (member.displayName || member.id)"
											@click="startEdit(member)">
											Edit
										</button>
									</template>
								</div>
							</div>
							<div v-if="editingId === member.id" class="pc-member__editor">
								<RoleChips label="DRASCIVS"
									:label-id="'pc-edit-drascivs-' + member.id"
									:options="drascivsOptions"
									:selected="editDraft.drascivs"
									:disabled="editSaving"
									@toggle="editDraft.drascivs = toggled(editDraft.drascivs, $event)" />
								<RoleChips label="Project role"
									:label-id="'pc-edit-functional-' + member.id"
									:options="roleOptions"
									:selected="editDraft.functional"
									:disabled="editSaving"
									@toggle="editDraft.functional = toggled(editDraft.functional, $event)" />
								<p v-if="editError" class="pc-member-problem pc-member-problem--error" role="alert">
									{{ editError }}
								</p>
								<p v-else-if="editProblem" class="pc-member-problem">
									<InformationOutline :size="15" />
									{{ editProblem }}
								</p>
							</div>
						</li>
					</ul>
					<p class="pc-members__count">
						{{ members.length === 1 ? '1 member' : members.length + ' members' }}
					</p>
				</template>
			</section>

			<aside class="iz-panel pc-coverage" aria-labelledby="pc-coverage-title">
				<h3 id="pc-coverage-title" class="pc-coverage__title">
					DRASCIVS coverage
				</h3>
				<p class="pc-coverage__lede">
					Who holds each responsibility.
				</p>
				<dl class="pc-coverage__list">
					<div v-for="row in coverageRows" :key="row.value" class="pc-coverage__row">
						<dt>{{ row.label }}</dt>
						<dd :class="{ 'pc-coverage__unset': !row.holders.length }">
							{{ row.holders.length ? row.holders.join(', ') : 'Not assigned' }}
						</dd>
					</div>
				</dl>
			</aside>
		</div>
	</section>
</template>

<script>
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import Plus from 'vue-material-design-icons/Plus.vue'
import Close from 'vue-material-design-icons/Close.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import ChatOutline from 'vue-material-design-icons/ChatOutline.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import RoleChips from './RoleChips.vue'
import { api, errorMessage, actionMessage } from './api.js'
import { DRASCIVS, rolesOf, drascivsLabel, projectRoleOptions, coverage, draftProblem, toggled } from './members.js'

// Members for the modern interface. It uses the endpoints the current
// interface calls — list, add, update roles, organisation search — through
// strict reads, so a failure is shown instead of an empty team.
export default {
	name: 'NewMembers',
	components: { NcAvatar, Plus, Close, CheckCircle, ChatOutline, InformationOutline, RoleChips },
	props: {
		projectId: { type: [String, Number], required: true },
		currentUserId: { type: String, default: '' },
		organizationId: { type: [String, Number], default: null },
		canManage: { type: Boolean, default: false },
	},
	data() {
		return {
			members: [],
			functionalRoles: [],
			loading: false,
			error: '',
			request: 0,
			notice: '',
			adding: false,
			query: '',
			searchedFor: '',
			results: [],
			searching: false,
			searchError: '',
			searchRequest: 0,
			searchTimer: null,
			candidate: null,
			addDraft: { drascivs: [], functional: [] },
			addSaving: false,
			addError: '',
			editingId: null,
			editDraft: { drascivs: [], functional: [] },
			editSaving: false,
			editError: '',
		}
	},
	computed: {
		drascivsOptions() { return DRASCIVS },
		roleOptions() { return projectRoleOptions(this.functionalRoles) },
		coverageRows() { return coverage(this.members) },
		addProblem() { return draftProblem({ needsPerson: true, person: this.candidate, ...this.addDraft }) },
		editProblem() { return draftProblem(this.editDraft) },
	},
	watch: {
		projectId: { immediate: true, handler() { this.load() } },
	},
	beforeDestroy() {
		this.request++
		this.searchRequest++
		clearTimeout(this.searchTimer)
	},
	methods: {
		rolesOf,
		drascivsLabel,
		toggled,
		isSelf(member) { return !!this.currentUserId && String(member.id) === this.currentUserId },
		memberMeta(member) {
			if (this.editingId === member.id) return member.id + ' · editing'
			return this.isSelf(member) ? member.id + ' · you' : member.id
		},
		projectRolesOf(member) {
			const keys = member.functionalRoleKeys || []
			return keys.map(key => this.roleOptions.find(option => option.value === key) || { value: key, label: key, tone: 2 })
		},
		async load() {
			const request = ++this.request
			this.loading = true
			this.error = ''
			try {
				const data = await api.members(this.projectId)
				if (request !== this.request) return
				if (!Array.isArray(data?.members)) throw new Error('Invalid members response')
				this.members = data.members
				this.functionalRoles = Array.isArray(data.functionalRoles) ? data.functionalRoles : []
			} catch (e) {
				if (request !== this.request) return
				this.members = []
				this.error = errorMessage(e)
			} finally {
				if (request === this.request) this.loading = false
			}
		},
		openAdd() {
			this.notice = ''
			this.adding = true
			this.cancelEdit()
		},
		closeAdd() {
			this.adding = false
			this.query = ''
			this.searchedFor = ''
			this.results = []
			this.searchError = ''
			this.searchRequest++
			clearTimeout(this.searchTimer)
			this.candidate = null
			this.addDraft = { drascivs: [], functional: [] }
			this.addError = ''
		},
		search() {
			clearTimeout(this.searchTimer)
			const query = this.query.trim()
			const request = ++this.searchRequest
			this.searchError = ''
			if (!query) {
				this.results = []
				this.searching = false
				this.searchedFor = ''
				return
			}
			this.searching = true
			this.searchTimer = setTimeout(async () => {
				try {
					const data = await api.searchUsers(query, Number(this.organizationId) > 0 ? Number(this.organizationId) : null)
					if (request !== this.searchRequest) return
					if (!Array.isArray(data?.users)) throw new Error('Invalid search response')
					const existing = new Set(this.members.map(member => String(member.id)))
					this.results = data.users
						.filter(user => !existing.has(String(user.id)))
						.map(user => ({ id: String(user.id), label: user.displayName || user.label || String(user.id), subname: user.subname || String(user.id) }))
					this.searchedFor = query
				} catch (e) {
					if (request !== this.searchRequest) return
					this.results = []
					this.searchError = actionMessage(e, 'Organisation members could not be searched.')
				} finally {
					if (request === this.searchRequest) this.searching = false
				}
			}, 250)
		},
		pick(user) {
			this.candidate = user
			this.results = []
			this.addError = ''
		},
		clearCandidate() {
			this.candidate = null
			this.query = ''
			this.searchedFor = ''
			this.$nextTick(() => this.$el.querySelector('#pc-member-search')?.focus())
		},
		async add() {
			if (this.addProblem || this.addSaving) return
			const candidate = this.candidate
			this.addSaving = true
			this.addError = ''
			try {
				const result = await api.addMember(this.projectId, candidate.id, this.addDraft.drascivs, this.addDraft.functional)
				this.closeAdd()
				this.notice = result?.alreadyMember ? `${candidate.label} is already on this project.` : `${candidate.label} was added to the project.`
				await this.load()
			} catch (e) {
				this.addError = actionMessage(e, 'The member could not be added.')
			} finally {
				this.addSaving = false
			}
		},
		startEdit(member) {
			this.notice = ''
			this.editingId = member.id
			this.editDraft = { drascivs: [...rolesOf(member)], functional: [...(member.functionalRoleKeys || [])] }
			this.editError = ''
		},
		cancelEdit() {
			this.editingId = null
			this.editDraft = { drascivs: [], functional: [] }
			this.editError = ''
		},
		async saveEdit(member) {
			if (this.editProblem || this.editSaving) return
			this.editSaving = true
			this.editError = ''
			try {
				const result = await api.updateMemberRoles(this.projectId, member.id, this.editDraft.drascivs, this.editDraft.functional)
				const updated = result?.member || { drascivsRoles: this.editDraft.drascivs, functionalRoleKeys: this.editDraft.functional }
				this.members = this.members.map(existing => String(existing.id) === String(member.id) ? { ...existing, ...updated } : existing)
				this.notice = `Roles updated for ${member.displayName || member.id}.`
				this.cancelEdit()
			} catch (e) {
				this.editError = actionMessage(e, 'The roles could not be saved.')
			} finally {
				this.editSaving = false
			}
		},
	},
}
</script>

<style scoped>
.pc-icon-btn { width: 32px; height: 32px; padding: 0; }
.iz-btn:disabled { cursor: default; opacity: 0.5; }

/* Add a member */
.pc-member-add { display: flex; flex-direction: column; gap: var(--iz-gap); }
.pc-member-add__head { display: flex; align-items: center; justify-content: space-between; gap: var(--iz-gap-tight); }
.pc-member-add__title { margin: 0; font-family: 'Space Grotesk', system-ui, -apple-system, sans-serif; font-size: var(--iz-fs-lg); font-weight: 600; color: var(--iz-text); }
.pc-member-add__grid { display: grid; grid-template-columns: minmax(240px, 300px) minmax(0, 1fr); gap: calc(var(--iz-gap) * 1.5); align-items: start; }
.pc-member-add__person { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.pc-member-add__label { margin: 0; }
.pc-member-add__roles { display: flex; flex-direction: column; gap: var(--iz-gap); min-width: 0; }
.pc-member-add__foot { display: flex; align-items: center; justify-content: flex-end; gap: var(--iz-gap-tight); flex-wrap: wrap; padding-top: var(--iz-gap); border-top: 1px solid var(--iz-border); }
.pc-member-add__foot .pc-member-problem { margin-inline-end: auto; }
.pc-member-picked { display: flex; align-items: center; gap: var(--iz-gap-tight); padding: 8px 10px; border: 1px solid var(--iz-accent); border-radius: var(--iz-radius); background: var(--iz-accent-bg); }
.pc-member-picked__text, .pc-member-result__text { display: flex; flex-direction: column; min-width: 0; flex-grow: 1; text-align: start; }
.pc-member-picked__text strong, .pc-member-result__text strong { font-size: var(--iz-fs-md); font-weight: 600; color: var(--iz-text); }
.pc-member-picked__text span, .pc-member-result__text span { font-size: var(--iz-fs-xs); color: var(--iz-text-secondary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pc-member-results { margin: 0; padding: 4px; list-style: none; border: 1px solid var(--iz-border-strong); border-radius: var(--iz-radius); background: var(--iz-surface); box-shadow: var(--iz-shadow); max-height: 240px; overflow-y: auto; }
.pc-member-result { display: flex; align-items: center; gap: var(--iz-gap-tight); width: 100%; min-height: 44px; padding: 6px 8px; margin: 0; border: 0; border-radius: var(--iz-radius-sm); background: transparent; cursor: pointer; }
.pc-member-result:hover, .pc-member-result:focus-visible { background: var(--iz-surface-subtle); }
.pc-member-hint { margin: 0; font-size: var(--iz-fs-xs); color: var(--iz-text-secondary); }
.pc-member-hint--error { color: var(--iz-danger-text); }
.pc-member-problem { display: inline-flex; align-items: center; gap: 6px; margin: 0; font-size: var(--iz-fs-sm); color: var(--iz-warning-text); }
.pc-member-problem--error { color: var(--iz-danger-text); }

/* The team */
.pc-members-layout { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: var(--iz-gap); align-items: start; }
.pc-members { min-width: 0; }
.pc-members__grid { display: grid; grid-template-columns: minmax(200px, 1.2fr) minmax(0, 1.4fr) minmax(0, 1fr) 136px; gap: 12px; align-items: center; }
.pc-members__head { padding: var(--iz-pad-cell); padding-inline: var(--iz-pad-panel); background: var(--iz-surface-subtle); border-bottom: 1px solid var(--iz-border); font-size: var(--iz-fs-micro); font-weight: 700; color: var(--iz-text-secondary); text-transform: uppercase; letter-spacing: 0.5px; }
.pc-members__actions-head { text-align: end; }
.pc-members__list { margin: 0; padding: 0; list-style: none; }
.pc-member { border-bottom: 1px solid var(--iz-border); }
.pc-member--editing { background: var(--iz-surface-subtle); }
.pc-member__row { padding: var(--iz-pad-row); padding-inline: var(--iz-pad-panel); }
.pc-member__who { display: flex; align-items: center; gap: 12px; min-width: 0; }
.pc-member__identity { min-width: 0; }
.pc-member__name-line { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.pc-member__name { font-size: var(--iz-fs-md); font-weight: 600; color: var(--iz-text); overflow-wrap: anywhere; }
.pc-member__owner { text-transform: none; }
.pc-member__meta { font-size: var(--iz-fs-xs); color: var(--iz-text-secondary); overflow-wrap: anywhere; }
.pc-member__pills { display: flex; flex-wrap: wrap; gap: 4px; min-width: 0; }
.pc-pill { text-transform: none; }
.pc-member__none { font-size: var(--iz-fs-xs); color: var(--iz-text-secondary); font-style: italic; }
.pc-member__actions { display: flex; justify-content: flex-end; gap: 6px; }
.pc-member__editor { display: flex; flex-direction: column; gap: 12px; padding: 0 var(--iz-pad-panel) var(--iz-pad-card) calc(var(--iz-pad-panel) + 44px); }
.pc-members__count { margin: 0; padding: var(--iz-pad-row); padding-inline: var(--iz-pad-panel); font-size: var(--iz-fs-sm); color: var(--iz-text-secondary); }
.pc-members__empty { padding: 40px var(--iz-pad-panel); text-align: center; font-size: var(--iz-fs-md); color: var(--iz-text-secondary); }

.pc-tone--1 { background: var(--iz-cat-1-bg); color: var(--iz-cat-1-text); }
.pc-tone--2 { background: var(--iz-cat-2-bg); color: var(--iz-cat-2-text); }
.pc-tone--3 { background: var(--iz-cat-3-bg); color: var(--iz-cat-3-text); }
.pc-tone--4 { background: var(--iz-cat-4-bg); color: var(--iz-cat-4-text); }
.pc-tone--5 { background: var(--iz-cat-5-bg); color: var(--iz-cat-5-text); }

/* Coverage */
.pc-coverage__title { margin: 0; font-family: 'Space Grotesk', system-ui, -apple-system, sans-serif; font-size: var(--iz-fs-lg); font-weight: 600; color: var(--iz-text); }
.pc-coverage__lede { margin: 4px 0 12px; font-size: var(--iz-fs-sm); color: var(--iz-text-secondary); }
.pc-coverage__list { margin: 0; }
.pc-coverage__row { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; padding: 9px 0; border-bottom: 1px solid var(--iz-border); }
.pc-coverage__row:last-child { border-bottom: 0; }
.pc-coverage__row dt { font-size: var(--iz-fs-sm); font-weight: 600; color: var(--iz-text); }
.pc-coverage__row dd { margin: 0; font-size: var(--iz-fs-sm); color: var(--iz-text-secondary); text-align: end; overflow-wrap: anywhere; }
.pc-coverage__unset { font-style: italic; }

@media (max-width: 1050px) {
	.pc-members-layout { grid-template-columns: minmax(0, 1fr); }
}

@media (max-width: 800px) {
	.pc-member-add__grid { grid-template-columns: minmax(0, 1fr); }
	.pc-members__head { display: none; }
	.pc-member__row { grid-template-columns: minmax(0, 1fr) auto; row-gap: 8px; padding-inline: var(--iz-pad-card); }
	.pc-member__who { grid-column: 1; grid-row: 1; }
	.pc-member__actions { grid-column: 2; grid-row: 1; }
	.pc-member__pills { grid-column: 1 / -1; padding-inline-start: 44px; }
	.pc-member__editor { padding-inline: var(--iz-pad-card); }
	.pc-member__actions .iz-btn { min-height: 36px; }
}
</style>
