<template>
	<section class="pc-view iz-app pc-create" aria-labelledby="pc-create-title">
		<header class="pc-view__head pc-create__head">
			<div class="pc-view__heading">
				<a :href="backHref" class="pc-create__back" @click.prevent="$emit('cancel')">← {{ backLabel ? 'Back to ' + backLabel : 'Back to projects' }}</a>
				<h2 id="pc-create-title" class="pc-view__title">
					New project
				</h2>
				<p class="pc-view__lede">
					Name, number and type are enough to start; the rest can be filled in later.
				</p>
			</div>
		</header>

		<form class="pc-create__layout" novalidate @submit.prevent="create">
			<div class="pc-create__form">
				<section class="iz-panel pc-create__panel" aria-labelledby="pc-create-basics">
					<h3 id="pc-create-basics" class="pc-create__panel-title">
						Basics
					</h3>
					<div class="pc-create__row pc-create__row--name">
						<label class="pc-create__field">
							<span class="pc-create__label">Project name <span class="pc-create__req">Required</span></span>
							<input v-model="form.name"
								class="iz-input"
								type="text"
								autocomplete="off"
								required
								:disabled="creating"
								placeholder="e.g. Firma de Testerij">
						</label>
						<label class="pc-create__field">
							<span class="pc-create__label">Project number <span class="pc-create__req">Required</span></span>
							<input v-model="form.number"
								class="iz-input"
								type="text"
								autocomplete="off"
								required
								:disabled="creating"
								placeholder="e.g. P-2026-014">
						</label>
					</div>
					<fieldset class="pc-create__types" :disabled="creating">
						<legend class="pc-create__label">
							Project type <span class="pc-create__req">Required</span>
						</legend>
						<label v-for="type in types"
							:key="type.id"
							class="pc-create__type"
							:class="{ 'pc-create__type--active': form.type === type.id }">
							<input v-model="form.type"
								type="radio"
								name="pc-create-type"
								:value="type.id">
							<span class="pc-create__type-name">{{ type.label }}</span>
							<span class="pc-create__type-text">{{ type.text }}</span>
						</label>
					</fieldset>
					<div v-if="isAdmin" class="pc-create__field">
						<OrganizationsFetcher input-label="Organisation"
							placeholder="Search for an organisation…"
							:model-value="form.organizationId"
							@update:modelValue="form.organizationId = $event"
							@error="organizationError = 'Organisations could not be searched.'" />
						<span class="pc-create__req pc-create__note">Required. Administrators choose the organisation the project goes to.</span>
						<span v-if="organizationError" class="pc-create__error-text" role="alert">{{ organizationError }}</span>
					</div>
				</section>

				<section class="iz-panel pc-create__panel" aria-labelledby="pc-create-client">
					<h3 id="pc-create-client" class="pc-create__panel-title">
						Client <span class="pc-create__optional">Optional</span>
					</h3>
					<div class="pc-create__row pc-create__row--four">
						<label class="pc-create__field">
							<span class="pc-create__label">Client name</span>
							<input v-model="form.clientName"
								class="iz-input"
								type="text"
								:disabled="creating"
								placeholder="e.g. ACME Corp">
						</label>
						<label class="pc-create__field">
							<span class="pc-create__label">Phone</span>
							<input v-model="form.clientPhone"
								class="iz-input"
								type="tel"
								:disabled="creating"
								placeholder="e.g. +31 10 123 4567">
						</label>
						<label class="pc-create__field">
							<span class="pc-create__label">Email</span>
							<input v-model="form.clientEmail"
								class="iz-input"
								type="email"
								:disabled="creating"
								placeholder="e.g. info@acme.nl">
						</label>
						<label class="pc-create__field">
							<span class="pc-create__label">Address</span>
							<input v-model="form.clientAddress"
								class="iz-input"
								type="text"
								:disabled="creating"
								placeholder="e.g. Coolsingel 40, Rotterdam">
						</label>
					</div>
					<RoleChips label="Client roles"
						label-id="pc-create-roles"
						:options="roleOptions"
						:selected="form.clientRoles"
						:disabled="creating"
						@toggle="form.clientRoles = toggled(form.clientRoles, $event)" />
				</section>

				<section class="iz-panel pc-create__panel pc-create__panel--location" aria-labelledby="pc-create-location">
					<div class="pc-create__location-fields">
						<h3 id="pc-create-location" class="pc-create__panel-title">
							Location <span class="pc-create__optional">Optional</span>
						</h3>
						<label class="pc-create__field">
							<span class="pc-create__label">Street</span>
							<input v-model="form.street"
								class="iz-input"
								type="text"
								:disabled="creating"
								placeholder="e.g. Kruiskade 12">
						</label>
						<div class="pc-create__row pc-create__row--city">
							<label class="pc-create__field">
								<span class="pc-create__label">City</span>
								<input v-model="form.city"
									class="iz-input"
									type="text"
									:disabled="creating"
									placeholder="e.g. Rotterdam">
							</label>
							<label class="pc-create__field">
								<span class="pc-create__label">ZIP</span>
								<input v-model="form.zip"
									class="iz-input"
									type="text"
									:disabled="creating"
									placeholder="e.g. 3012 EH">
							</label>
						</div>
					</div>
					<ProjectLocationMap class="pc-create__map"
						:street="form.street"
						:city="form.city"
						:zip="form.zip" />
				</section>

				<section class="iz-panel pc-create__panel" aria-labelledby="pc-create-description">
					<h3 id="pc-create-description" class="pc-create__panel-title">
						Description <span class="pc-create__optional">Optional</span>
					</h3>
					<textarea v-model="form.description"
						class="iz-input pc-create__textarea"
						rows="3"
						aria-labelledby="pc-create-description"
						:disabled="creating"
						placeholder="What the project is about, for everyone who joins it." />
				</section>
			</div>

			<aside class="iz-panel pc-create__aside" aria-labelledby="pc-create-setup">
				<h3 id="pc-create-setup" class="pc-create__panel-title">
					Create sets up
				</h3>
				<ul class="pc-create__setup">
					<li v-for="item in setup" :key="item.key">
						<span class="pc-create__setup-title">{{ item.title }}</span>
						<span class="pc-create__setup-text">{{ item.text }}</span>
					</li>
				</ul>

				<div class="pc-create__plan">
					<template v-if="plan">
						<p class="pc-create__plan-line">
							<span class="pc-create__plan-name">Plan{{ plan.organization ? ' for ' + plan.organization : '' }}</span>
							<span :class="{ 'pc-create__plan-full': plan.full }">{{ plan.used }} of {{ plan.max }} projects used</span>
						</p>
						<span class="iz-meter pc-create__meter"
							role="progressbar"
							aria-label="Projects used"
							aria-valuemin="0"
							:aria-valuemax="plan.max"
							:aria-valuenow="plan.used">
							<span class="iz-meter__fill" :class="{ 'iz-meter__fill--danger': plan.full }" :style="{ width: plan.percent + '%' }" />
						</span>
						<span class="pc-create__plan-note">{{ plan.full ? 'Every project on this plan is in use.' : 'This one makes ' + (plan.used + 1) + ' of ' + plan.max + '.' }}</span>
					</template>
					<span v-else-if="allowanceLoading" class="pc-create__plan-note" role="status">Checking the plan…</span>
					<span v-else-if="allowanceError" class="pc-create__plan-note">{{ allowanceError }}</span>
					<span v-else-if="isAdmin && !form.organizationId" class="pc-create__plan-note">Choose an organisation to see its plan.</span>
				</div>

				<div v-if="creating" class="pc-create__working" role="status">
					<span class="pc-create__spinner" aria-hidden="true" />
					Setting up {{ form.name.trim() }}. This takes a few seconds.
				</div>
				<div v-else-if="failure" class="pc-create__failure" role="alert">
					<strong>The project was not created</strong>
					<span>{{ failure }}</span>
				</div>

				<div class="pc-create__actions">
					<p class="pc-create__hint" :class="{ 'pc-create__hint--ready': ready }">
						{{ hint }}
					</p>
					<div class="pc-create__buttons">
						<button type="button"
							class="iz-btn"
							:disabled="creating"
							@click="$emit('cancel')">
							Cancel
						</button>
						<button type="submit" class="iz-btn iz-btn--primary" :disabled="!ready || creating">
							{{ creating ? 'Creating…' : 'Create project' }}
						</button>
					</div>
				</div>
			</aside>
		</form>
	</section>
</template>

<script>
import OrganizationsFetcher from '../components/OrganizationsFetcher.vue'
import ProjectLocationMap from '../components/ProjectLocationMap.vue'
import { CLIENT_ROLE_OPTIONS } from '../macros/client-roles.js'
import RoleChips from './RoleChips.vue'
import { api } from './api.js'
import { toggled } from './members.js'
import { TYPES, emptyForm, missingOf, sentence, payloadOf, setupOf, planOf, failureOf } from './create.js'

// The New project page of the new layout. The same fields, rules and request
// as the current interface's create form; it also says what Create will set
// up and how many projects the plan has left, before anyone presses it.
export default {
	name: 'NewCreate',
	components: { OrganizationsFetcher, ProjectLocationMap, RoleChips },
	props: {
		context: { type: Object, required: true },
		// The project the person came from, for the way back.
		backLabel: { type: String, default: '' },
		backHref: { type: String, default: '#' },
	},
	data() {
		return {
			form: emptyForm(),
			types: TYPES,
			roleOptions: CLIENT_ROLE_OPTIONS,
			allowance: null,
			allowanceLoading: false,
			allowanceError: '',
			allowanceRequest: 0,
			organizationError: '',
			creating: false,
			failure: '',
		}
	},
	computed: {
		isAdmin() { return !!this.context.isGlobalAdmin },
		missing() { return missingOf(this.form, this.isAdmin) },
		plan() { return planOf(this.allowance) },
		ready() { return !this.missing.length && !this.plan?.full },
		hint() {
			if (this.missing.length) return 'Still needed: ' + sentence(this.missing) + '.'
			if (this.plan?.full) return 'The plan has no room for another project.'
			return 'Ready to create.'
		},
		setup() { return setupOf(this.form, this.allowance) },
	},
	watch: {
		'form.organizationId'() { if (this.isAdmin) this.loadAllowance() },
	},
	created() {
		if (!this.isAdmin) this.loadAllowance()
	},
	beforeDestroy() {
		this.allowanceRequest++
	},
	methods: {
		toggled,
		async loadAllowance() {
			const request = ++this.allowanceRequest
			this.allowance = null
			this.allowanceError = ''
			if (this.isAdmin && !(Number(this.form.organizationId) > 0)) return
			this.allowanceLoading = true
			try {
				const allowance = await api.allowance(this.isAdmin ? Number(this.form.organizationId) : null)
				if (request === this.allowanceRequest) this.allowance = allowance
			} catch (error) {
				// The plan line is a courtesy: Create still checks the limit itself.
				if (request === this.allowanceRequest) this.allowanceError = error?.response?.data?.message || 'The plan could not be checked.'
			} finally {
				if (request === this.allowanceRequest) this.allowanceLoading = false
			}
		},
		async create() {
			if (!this.ready || this.creating) return
			this.creating = true
			this.failure = ''
			try {
				const result = await api.createProject(payloadOf(this.form))
				const projectId = Number(result?.projectId)
				if (!(projectId > 0)) throw new Error('The project was created, but its page could not be opened.')
				this.$emit('created', { projectId, name: this.form.name.trim() })
			} catch (error) {
				this.failure = failureOf(error)
				this.loadAllowance()
			} finally {
				this.creating = false
			}
		},
	},
}
</script>

<style scoped>
.pc-create__back { display: inline-block; margin-bottom: 4px; font-size: var(--iz-fs-sm); color: var(--iz-accent-bg-text); text-decoration: none; }
.pc-create__back:hover { text-decoration: underline; }

.pc-create__layout { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: var(--iz-gap); align-items: start; }
.pc-create__form { display: flex; flex-direction: column; gap: var(--iz-gap); min-width: 0; }
.pc-create__panel { display: flex; flex-direction: column; gap: 14px; min-width: 0; }
.pc-create__panel-title { display: flex; align-items: baseline; gap: 8px; margin: 0; font-family: 'Space Grotesk', system-ui, -apple-system, sans-serif; font-size: var(--iz-fs-lg); font-weight: 600; color: var(--iz-text); }
.pc-create__optional { font-family: var(--font-face, system-ui), sans-serif; font-size: var(--iz-fs-xs); font-weight: 500; color: var(--iz-text-secondary); }

.pc-create__row { display: grid; gap: 12px; }
.pc-create__row--name { grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr); }
.pc-create__row--four { grid-template-columns: repeat(4, minmax(0, 1fr)); }
.pc-create__row--city { grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr); }
.pc-create__field { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
.pc-create__label { font-size: var(--iz-fs-sm); font-weight: 600; color: var(--iz-text); }
.pc-create__req { font-weight: 400; color: var(--iz-text-secondary); }
.pc-create__field .iz-input { width: 100%; min-height: 40px; margin: 0; box-sizing: border-box; }
.pc-create__textarea { width: 100%; min-height: 84px; margin: 0; box-sizing: border-box; resize: vertical; font: inherit; }
/* Client roles read like the other field labels here, not as a small-caps heading. */
.pc-create ::v-deep .pc-role-chips__label { font-size: var(--iz-fs-sm); font-weight: 600; color: var(--iz-text); text-transform: none; letter-spacing: normal; }
.pc-create__note { font-size: var(--iz-fs-xs); }
.pc-create__error-text { font-size: var(--iz-fs-sm); color: var(--iz-danger-text); }

.pc-create__types { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; margin: 0; padding: 0; border: 0; min-width: 0; }
.pc-create__types legend { grid-column: 1 / -1; margin-bottom: 6px; padding: 0; }
.pc-create__type { position: relative; display: flex; flex-direction: column; gap: 4px; min-height: 96px; padding: 12px; border: 1px solid var(--iz-border-strong); border-radius: var(--iz-radius); background: var(--iz-surface); cursor: pointer; }
.pc-create__type:hover { background: var(--iz-surface-subtle); }
.pc-create__type--active { border-color: var(--iz-accent); box-shadow: inset 0 0 0 1px var(--iz-accent); background: var(--iz-accent-bg); }
.pc-create__type input { position: absolute; inset-block-start: 12px; inset-inline-end: 12px; margin: 0; }
.pc-create__type:focus-within { outline: 2px solid var(--iz-accent); outline-offset: 2px; }
.pc-create__type-name { padding-inline-end: 22px; font-size: var(--iz-fs-md); font-weight: 700; color: var(--iz-text); }
.pc-create__type-text { font-size: var(--iz-fs-xs); line-height: 1.35; color: var(--iz-text-secondary); }
.pc-create__types:disabled .pc-create__type { cursor: default; opacity: 0.6; }

.pc-create__panel--location { display: grid; grid-template-columns: minmax(0, 1fr) 280px; gap: var(--iz-gap); }
.pc-create__location-fields { display: flex; flex-direction: column; gap: 12px; min-width: 0; }
.pc-create__map { min-width: 0; }

.pc-create__aside { position: sticky; top: var(--iz-gap); display: flex; flex-direction: column; gap: 14px; }
.pc-create__setup { display: flex; flex-direction: column; gap: 10px; margin: 0; padding: 0; list-style: none; }
.pc-create__setup li { display: flex; flex-direction: column; gap: 1px; }
.pc-create__setup-title { font-size: var(--iz-fs-md); font-weight: 600; color: var(--iz-text); overflow-wrap: anywhere; }
.pc-create__setup-text { font-size: var(--iz-fs-xs); color: var(--iz-text-secondary); }
.pc-create__plan { display: flex; flex-direction: column; gap: 6px; padding-top: 12px; border-top: 1px solid var(--iz-border); }
.pc-create__plan:empty { display: none; }
.pc-create__plan-line { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 2px 8px; margin: 0; font-size: var(--iz-fs-sm); color: var(--iz-text-secondary); }
.pc-create__plan-line > span:last-child { white-space: nowrap; }
.pc-create__plan-name { font-weight: 600; color: var(--iz-text); }
.pc-create__plan-full { font-weight: 700; color: var(--iz-danger-text); }
.pc-create__meter, .pc-create__meter .iz-meter__fill { display: block; }
.pc-create__plan-note { font-size: var(--iz-fs-xs); color: var(--iz-text-secondary); }

.pc-create__working { display: flex; align-items: center; gap: 10px; font-size: var(--iz-fs-sm); color: var(--iz-text); }
.pc-create__spinner { width: 16px; height: 16px; flex-shrink: 0; box-sizing: border-box; border-radius: 50%; border: 2.5px solid var(--iz-accent-bg); border-top-color: var(--iz-accent); animation: pc-create-spin 0.8s linear infinite; }
@keyframes pc-create-spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .pc-create__spinner { animation: none; } }
.pc-create__failure { display: flex; flex-direction: column; gap: 4px; padding: 12px 14px; border-radius: var(--iz-radius); background: var(--iz-danger-bg); color: var(--iz-danger-text); font-size: var(--iz-fs-sm); }

.pc-create__actions { display: flex; flex-direction: column; gap: 8px; padding-top: 12px; border-top: 1px solid var(--iz-border); }
.pc-create__hint { margin: 0; font-size: var(--iz-fs-sm); color: var(--iz-text-secondary); }
.pc-create__hint--ready { color: var(--iz-success-text); }
.pc-create__buttons { display: grid; grid-template-columns: 1fr 2fr; gap: 8px; }
.pc-create__buttons .iz-btn { min-height: 44px; justify-content: center; }
.iz-btn:disabled { cursor: default; opacity: 0.5; }

@media (max-width: 1100px) {
	.pc-create__layout { grid-template-columns: minmax(0, 1fr); }
	.pc-create__aside { position: static; }
	.pc-create__types, .pc-create__row--four { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 700px) {
	.pc-create__row--name, .pc-create__row--city, .pc-create__row--four, .pc-create__types { grid-template-columns: minmax(0, 1fr); }
	.pc-create__panel--location { grid-template-columns: minmax(0, 1fr); }
}
</style>
