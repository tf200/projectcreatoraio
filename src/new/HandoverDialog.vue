<template>
	<dialog ref="dialog"
		class="pc-handover iz-app"
		aria-labelledby="pc-handover-title"
		@cancel.prevent="close"
		@close="$emit('close')">
		<div class="pc-handover__body">
			<header class="pc-handover__head">
				<h3 id="pc-handover-title" class="pc-handover__title">
					Hand over the project
				</h3>
				<ol v-if="preview && !report" class="pc-handover__steps" aria-label="Steps">
					<li v-for="(name, index) in stepNames"
						:key="name"
						:class="{ 'pc-handover__step--now': index === step }"
						:aria-current="index === step ? 'step' : undefined">
						{{ index + 1 }}. {{ name }}
					</li>
				</ol>
			</header>

			<p v-if="loading" class="pc-handover__muted" role="status">
				Loading the project…
			</p>
			<p v-else-if="loadError" class="pc-handover__error" role="alert">
				{{ loadError }}
			</p>

			<!-- After applying: what happened -->
			<template v-else-if="report">
				<p class="pc-handover__text" role="status">
					{{ report.failed.length ? 'The handover is saved, but not everything went through.' : 'The project is handed over.' }}
				</p>
				<ul v-if="report.failed.length" class="pc-handover__list pc-handover__list--failed">
					<li v-for="row in report.failed" :key="row.label">
						<strong>{{ row.label }}</strong> · {{ row.message }}
					</li>
				</ul>
				<ul v-if="report.notes.length" class="pc-handover__list">
					<li v-for="row in report.notes" :key="row.label">
						<strong>{{ row.label }}</strong> · {{ row.message }}
					</li>
				</ul>
				<div class="pc-handover__buttons">
					<button type="button" class="iz-btn iz-btn--primary" @click="close">
						Close
					</button>
				</div>
			</template>

			<template v-else-if="preview">
				<!-- 1. Who takes over -->
				<div v-show="step === 0" class="pc-handover__section">
					<label class="pc-handover__field">
						<span>Handover date</span>
						<input v-model="draft.date"
							class="iz-input"
							type="date"
							required>
					</label>

					<label v-if="preview.teamsAvailable" class="pc-handover__field">
						<span>Team that carries out the project</span>
						<select class="iz-input" :value="teamChoice" @change="chooseTeam($event.target.value)">
							<option v-if="preview.team" value="keep">Keep {{ preview.team.name }}</option>
							<option v-else value="keep">No team</option>
							<option v-for="team in otherTeams" :key="team.id" :value="String(team.id)">
								{{ team.name }}
							</option>
							<option v-if="preview.team" value="none">No team</option>
						</select>
					</label>
					<p v-if="joining.length" class="pc-handover__muted">
						Joining with the team: {{ joining.map(p => p.displayName).join(', ') }}
					</p>
					<p v-if="preview.team && draft.team.mode !== 'keep'" class="pc-handover__muted">
						{{ preview.team.name }}’s people stay on the project.
					</p>

					<label class="pc-handover__field">
						<span>Owner</span>
						<select v-model="draft.ownerUid" class="iz-input">
							<option v-for="person in owners" :key="person.id" :value="person.id">
								{{ person.displayName }}{{ person.id === preview.ownerId ? ' (now)' : '' }}
							</option>
						</select>
					</label>

					<div class="pc-handover__field">
						<label for="pc-handover-search">Add people</label>
						<input id="pc-handover-search"
							v-model="query"
							class="iz-input"
							type="search"
							autocomplete="off"
							placeholder="Search organisation members"
							@input="search">
						<ul v-if="results.length" class="pc-handover__results" aria-label="Search results">
							<li v-for="user in results" :key="user.id">
								<button type="button" class="pc-handover__result" @click="pick(user)">
									<strong>{{ user.displayName }}</strong>
									<span>{{ user.subname }}</span>
								</button>
							</li>
						</ul>
						<p v-else-if="searching" class="pc-handover__muted" role="status">
							Searching…
						</p>
						<p v-else-if="searchError" class="pc-handover__error" role="alert">
							{{ searchError }}
						</p>
					</div>
					<div v-for="person in draft.add" :key="person.id" class="pc-handover__person">
						<div class="pc-handover__person-head">
							<strong>{{ person.displayName }}</strong>
							<button type="button"
								class="iz-btn iz-btn--ghost iz-btn--sm"
								:aria-label="'Do not add ' + person.displayName"
								@click="unpick(person)">
								Remove
							</button>
						</div>
						<RoleChips label="DRASCIVS"
							:label-id="'pc-handover-drascivs-' + person.id"
							:options="drascivsOptions"
							:selected="person.drascivs"
							@toggle="person.drascivs = toggled(person.drascivs, $event)" />
						<RoleChips label="Project role"
							:label-id="'pc-handover-role-' + person.id"
							:options="roleOptions"
							:selected="person.functional"
							@toggle="person.functional = toggled(person.functional, $event)" />
					</div>
				</div>

				<!-- 2. Externals -->
				<div v-show="step === 1" class="pc-handover__section">
					<p v-if="!preview.externals.length" class="pc-handover__muted">
						There are no external collaborators on this project.
					</p>
					<template v-else>
						<p class="pc-handover__muted">
							Externals keep access until the end of work on site, unless you choose otherwise.
						</p>
						<fieldset v-for="external in preview.externals" :key="external.id" class="pc-handover__external">
							<legend><strong>{{ external.displayName }}</strong><span v-if="external.company"> · {{ external.company }}</span></legend>
							<label class="pc-handover__radio">
								<input v-model="draft.externals[external.id].mode" type="radio" value="untilEnd">
								Until the end of work on site
							</label>
							<label class="pc-handover__radio">
								<input v-model="draft.externals[external.id].mode" type="radio" value="atHandover">
								Ends at the handover
							</label>
							<label class="pc-handover__radio">
								<input v-model="draft.externals[external.id].mode" type="radio" value="date">
								On a date
								<input v-if="draft.externals[external.id].mode === 'date'"
									v-model="draft.externals[external.id].date"
									class="iz-input pc-handover__date"
									type="date"
									:aria-label="'Day ' + external.displayName + '’s access ends'">
							</label>
							<small class="pc-handover__muted">{{ externalEndText(preview, draft, external) }}</small>
						</fieldset>
					</template>
				</div>

				<!-- 3. Review -->
				<div v-show="step === 2" class="pc-handover__section">
					<ul class="pc-handover__list">
						<li v-for="line in summary" :key="line">
							{{ line }}
						</li>
					</ul>
				</div>

				<p v-if="error" class="pc-handover__error" role="alert">
					{{ error }}
				</p>
				<div class="pc-handover__buttons">
					<button type="button"
						class="iz-btn"
						:disabled="saving"
						@click="step === 0 ? close() : step--">
						{{ step === 0 ? 'Cancel' : 'Back' }}
					</button>
					<button v-if="step < 2"
						type="button"
						class="iz-btn iz-btn--primary"
						@click="next">
						Next
					</button>
					<button v-else
						type="button"
						class="iz-btn iz-btn--primary"
						:disabled="saving || !!problem"
						@click="apply">
						{{ saving ? 'Handing over…' : 'Hand over' }}
					</button>
				</div>
			</template>
		</div>
	</dialog>
</template>

<script>
import RoleChips from './RoleChips.vue'
import { api, actionMessage, errorMessage } from './api.js'
import { DRASCIVS, projectRoleOptions, toggled } from './members.js'
import { handoverDraft, joiningWithTeam, ownerOptions, externalEndText, handoverProblem, whoProblem, externalsProblem, handoverPayload, handoverSummary, stepReport } from './handover.js'

// The handover step: the people who carry out the project take over from
// initiation. Opened from the Planning tab's Handover card and the Members tab.
export default {
	name: 'HandoverDialog',
	components: { RoleChips },
	props: {
		projectId: { type: Number, required: true },
		organizationId: { type: Number, default: null },
	},
	emits: ['close', 'done'],
	data() {
		return {
			loading: false,
			loadError: '',
			preview: null,
			draft: null,
			step: 0,
			stepNames: ['Who takes over', 'Externals', 'Review'],
			saving: false,
			error: '',
			report: null,
			query: '',
			results: [],
			searching: false,
			searchError: '',
			searchTimer: null,
			searchRequest: 0,
		}
	},
	computed: {
		drascivsOptions() { return DRASCIVS },
		roleOptions() { return projectRoleOptions(this.preview?.functionalRoles || []) },
		otherTeams() { return (this.preview?.teams || []).filter(team => team.id !== this.preview.team?.id) },
		teamChoice() { return this.draft.team.mode === 'set' ? String(this.draft.team.teamId) : this.draft.team.mode },
		joining() { return joiningWithTeam(this.preview, this.draft) },
		owners() { return ownerOptions(this.preview, this.draft) },
		problem() { return handoverProblem(this.preview, this.draft) },
		summary() { return handoverSummary(this.preview, this.draft) },
	},
	watch: {
		// A person who no longer joins cannot stay chosen as owner.
		owners(list) {
			if (this.draft && !list.some(person => person.id === this.draft.ownerUid)) this.draft.ownerUid = this.preview.ownerId
		},
	},
	mounted() {
		this.open()
	},
	beforeDestroy() {
		clearTimeout(this.searchTimer)
	},
	methods: {
		toggled,
		externalEndText,
		async open() {
			const el = this.$refs.dialog
			if (el && typeof el.showModal === 'function' && !el.open) el.showModal()
			this.loading = true
			this.loadError = ''
			try {
				const preview = await api.handoverPreview(this.projectId)
				if (!Array.isArray(preview?.members)) throw new Error('Invalid handover response')
				this.preview = preview
				this.draft = handoverDraft(preview)
			} catch (e) {
				this.loadError = e?.response?.data?.message || errorMessage(e)
			} finally {
				this.loading = false
			}
		},
		close() {
			if (this.saving) return
			const el = this.$refs.dialog
			if (el?.open) el.close()
			else this.$emit('close')
		},
		chooseTeam(value) {
			this.draft.team = value === 'keep' || value === 'none'
				? { mode: value, teamId: value === 'keep' ? (this.preview.team?.id ?? null) : null }
				: { mode: 'set', teamId: Number(value) }
		},
		next() {
			this.error = this.step === 0 ? whoProblem(this.draft) : externalsProblem(this.preview, this.draft)
			if (!this.error) this.step++
		},
		search() {
			clearTimeout(this.searchTimer)
			const query = this.query.trim()
			const request = ++this.searchRequest
			this.searchError = ''
			if (!query) {
				this.results = []
				this.searching = false
				return
			}
			this.searching = true
			this.searchTimer = setTimeout(async () => {
				try {
					const data = await api.searchUsers(query, this.organizationId)
					if (request !== this.searchRequest) return
					if (!Array.isArray(data?.users)) throw new Error('Invalid search response')
					const taken = new Set([...this.preview.members.map(m => m.id), ...this.draft.add.map(p => p.id), ...this.joining.map(p => p.id)])
					this.results = data.users
						.filter(user => !taken.has(String(user.id)))
						.map(user => ({ id: String(user.id), displayName: user.displayName || user.label || String(user.id), subname: user.subname || String(user.id) }))
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
			this.draft.add.push({ id: user.id, displayName: user.displayName, drascivs: [], functional: [] })
			this.results = []
			this.query = ''
		},
		unpick(person) {
			this.draft.add = this.draft.add.filter(p => p.id !== person.id)
		},
		async apply() {
			if (this.saving || this.problem) {
				this.error = this.problem
				return
			}
			this.saving = true
			this.error = ''
			try {
				const result = await api.handover(this.projectId, handoverPayload(this.preview, this.draft))
				this.report = stepReport(this.preview, this.draft, result?.steps)
				this.$emit('done', result)
			} catch (e) {
				this.error = actionMessage(e, 'The project could not be handed over. Nothing was changed.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
/* Nextcloud's own styles show every dialog; only an open one may show. */
.pc-handover:not([open]) { display: none; }
.pc-handover { position: fixed; margin: auto; width: min(600px, calc(100vw - 32px)); max-height: calc(100vh - 48px); padding: 0; border: 0; border-radius: var(--iz-radius-lg); background: var(--iz-surface); color: var(--iz-text); box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3); overflow-y: auto; }
.pc-handover::backdrop { background: rgba(20, 12, 28, 0.45); }
.pc-handover__body { display: flex; flex-direction: column; gap: 14px; padding: 22px 24px; }
.pc-handover__head { display: flex; flex-direction: column; gap: 8px; }
.pc-handover__title { margin: 0; font-family: 'Space Grotesk', system-ui, -apple-system, sans-serif; font-size: var(--iz-fs-lg); font-weight: 600; }
.pc-handover__steps { display: flex; flex-wrap: wrap; gap: 6px 16px; margin: 0; padding: 0; list-style: none; font-size: var(--iz-fs-sm); color: var(--iz-text-secondary); }
.pc-handover__step--now { color: var(--iz-text); font-weight: 600; }
.pc-handover__section { display: flex; flex-direction: column; gap: 12px; }
.pc-handover__field { display: flex; flex-direction: column; gap: 5px; min-width: 0; font-size: var(--iz-fs-sm); font-weight: 600; }
.pc-handover__field .iz-input { width: 100%; min-height: 40px; margin: 0; box-sizing: border-box; font: inherit; font-weight: 400; }
.pc-handover__text { margin: 0; font-size: var(--iz-fs-md); }
.pc-handover__muted { margin: 0; font-size: var(--iz-fs-xs); color: var(--iz-text-secondary); font-weight: 400; }
.pc-handover__error { margin: 0; padding: 8px 10px; border-radius: var(--iz-radius); background: var(--iz-danger-bg); color: var(--iz-danger-text); font-size: var(--iz-fs-sm); }
.pc-handover__results { margin: 0; padding: 4px; list-style: none; border: 1px solid var(--iz-border-strong); border-radius: var(--iz-radius); background: var(--iz-surface); max-height: 200px; overflow-y: auto; }
.pc-handover__result { display: flex; flex-direction: column; align-items: flex-start; width: 100%; padding: 6px 8px; border: 0; border-radius: var(--iz-radius); background: transparent; color: inherit; font: inherit; text-align: start; cursor: pointer; }
.pc-handover__result:hover, .pc-handover__result:focus-visible { background: var(--iz-surface-hover, rgba(0, 0, 0, 0.05)); }
.pc-handover__result span { font-size: var(--iz-fs-xs); color: var(--iz-text-secondary); }
.pc-handover__person { display: flex; flex-direction: column; gap: 8px; padding: 10px 12px; border: 1px solid var(--iz-border); border-radius: var(--iz-radius); }
.pc-handover__person-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.pc-handover__external { display: flex; flex-direction: column; gap: 6px; margin: 0; padding: 10px 12px; border: 1px solid var(--iz-border); border-radius: var(--iz-radius); }
.pc-handover__external legend { padding: 0 4px; font-size: var(--iz-fs-sm); }
.pc-handover__radio { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; font-size: var(--iz-fs-sm); }
.pc-handover__date { min-height: 32px; margin: 0; }
.pc-handover__list { display: flex; flex-direction: column; gap: 6px; margin: 0; padding-inline-start: 20px; font-size: var(--iz-fs-sm); }
.pc-handover__list--failed { color: var(--iz-danger-text); }
.pc-handover__buttons { display: flex; justify-content: flex-end; gap: 8px; padding-top: 4px; }
.pc-handover__buttons .iz-btn { min-height: 40px; }
.iz-btn:disabled { cursor: default; opacity: 0.5; }
</style>
