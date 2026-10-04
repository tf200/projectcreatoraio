<template>
	<div class="pc-actions iz-app">
		<div class="pc-actions__row">
			<a v-if="chatUrl" class="iz-btn iz-btn--primary pc-actions__btn" :href="chatUrl">
				<ChatOutline :size="16" />
				Chat
			</a>

			<div class="pc-actions__anchor">
				<button ref="contactsButton"
					type="button"
					class="iz-btn pc-actions__btn"
					:class="{ 'pc-actions__btn--open': open === 'contacts' }"
					aria-haspopup="true"
					:aria-expanded="String(open === 'contacts')"
					aria-controls="pc-actions-contacts"
					@click="toggle('contacts', $event)">
					<AccountGroupOutline :size="16" />
					Contacts
					<ChevronDown :size="14" class="pc-actions__caret" />
				</button>
				<div v-if="open === 'contacts'"
					id="pc-actions-contacts"
					class="pc-actions__pop"
					@keydown.esc="close('contactsButton')">
					<p class="pc-actions__label">
						Client
					</p>
					<div v-if="hasClient" class="pc-actions__client">
						<strong>{{ project.client_name || 'No client name' }}</strong>
						<span v-if="project.client_email" class="pc-actions__copyable">{{ project.client_email }}</span>
						<span v-if="project.client_phone" class="pc-actions__copyable">{{ project.client_phone }}</span>
						<span v-if="project.client_address">{{ project.client_address }}</span>
					</div>
					<p v-else class="pc-actions__muted">
						No client details yet.
					</p>
					<div class="pc-actions__sep" />
					<a v-if="project.client_email" class="pc-actions__item" :href="'mailto:' + project.client_email">
						<EmailOutline :size="16" />Email the client
					</a>
					<button type="button" class="pc-actions__item" @click="pick('members')">
						<AccountGroupOutline :size="16" />View project team
					</button>
					<button type="button" class="pc-actions__item" @click="openClient">
						<PencilOutline :size="16" />Edit client &amp; address
					</button>
				</div>
			</div>

			<div class="pc-actions__anchor">
				<button ref="moreButton"
					type="button"
					class="iz-btn pc-actions__btn"
					:class="{ 'pc-actions__btn--open': open === 'more' }"
					aria-haspopup="menu"
					:aria-expanded="String(open === 'more')"
					aria-controls="pc-actions-more"
					@click="toggle('more', $event)">
					<DotsHorizontal :size="16" />
					More
				</button>
				<div v-if="open === 'more'"
					id="pc-actions-more"
					class="pc-actions__pop pc-actions__menu"
					role="menu"
					aria-label="Project actions">
					<button v-if="can.rename || can.describe"
						type="button"
						role="menuitem"
						class="pc-actions__item"
						@click="openDetails">
						<PencilOutline :size="16" />
						<span>Edit project details<small>{{ can.describe ? 'Name and description' : 'Name' }}</small></span>
					</button>
					<button type="button"
						role="menuitem"
						class="pc-actions__item"
						@click="openClient">
						<MapMarkerOutline :size="16" />
						<span>Edit client &amp; address</span>
					</button>
					<template v-if="can.status">
						<div class="pc-actions__sep" />
						<p :id="'pc-actions-status-' + project.id" class="pc-actions__label">
							Status
						</p>
						<div role="group" :aria-labelledby="'pc-actions-status-' + project.id">
							<button v-for="option in statuses"
								:key="option.value"
								type="button"
								role="menuitemradio"
								class="pc-actions__item pc-actions__status"
								:aria-checked="String(option.value === currentStatus)"
								:disabled="busy"
								@click="setStatus(option.value)">
								<span class="pc-actions__dot" :class="'pc-actions__dot--' + option.filterValue" />
								<span>{{ option.label }}</span>
								<Check v-if="option.value === currentStatus" :size="16" class="pc-actions__check" />
							</button>
						</div>
					</template>
					<div class="pc-actions__sep" />
					<button type="button"
						role="menuitem"
						class="pc-actions__item"
						:disabled="busy"
						@click="exportProject">
						<TrayArrowDown :size="16" />
						<span>Export project<small>A ZIP of its files; you get a notification when it is ready</small></span>
					</button>
					<template v-if="can.delete">
						<div class="pc-actions__sep" />
						<button type="button"
							role="menuitem"
							class="pc-actions__item pc-actions__item--danger"
							@click="openDelete">
							<TrashCanOutline :size="16" />
							<span>Delete project</span>
						</button>
					</template>
				</div>
			</div>
		</div>

		<p v-if="notice"
			class="pc-actions__notice"
			:class="{ 'pc-actions__notice--error': noticeError }"
			role="status">
			{{ notice }}
		</p>

		<dialog ref="details"
			class="pc-dialog"
			aria-labelledby="pc-dialog-details"
			@close="dialog = ''">
			<form class="pc-dialog__body" @submit.prevent="saveDetails">
				<h3 id="pc-dialog-details" class="pc-dialog__title">
					Edit project details
				</h3>
				<label v-if="can.rename" class="pc-dialog__field">
					<span>Project name</span>
					<input id="pc-edit-name"
						v-model="draft.name"
						class="iz-input"
						type="text"
						required>
				</label>
				<label v-if="can.describe" class="pc-dialog__field">
					<span>Description</span>
					<textarea id="pc-edit-description"
						v-model="draft.description"
						class="iz-input"
						rows="4" />
				</label>
				<p class="pc-dialog__muted">
					The project number and type stay as they were created.
				</p>
				<p v-if="error" class="pc-dialog__error" role="alert">
					{{ error }}
				</p>
				<div class="pc-dialog__buttons">
					<button type="button"
						class="iz-btn"
						:disabled="busy"
						@click="closeDialog">
						Cancel
					</button>
					<button type="submit" class="iz-btn iz-btn--primary" :disabled="busy || (can.rename && !String(draft.name || '').trim())">
						{{ busy ? 'Saving…' : 'Save' }}
					</button>
				</div>
			</form>
		</dialog>

		<dialog ref="client"
			class="pc-dialog pc-dialog--wide"
			aria-labelledby="pc-dialog-client"
			@close="dialog = ''">
			<form class="pc-dialog__body" @submit.prevent="saveClient">
				<h3 id="pc-dialog-client" class="pc-dialog__title">
					Client &amp; address
				</h3>
				<div class="pc-dialog__grid">
					<label class="pc-dialog__field"><span>Client name</span><input id="pc-edit-client-name"
						v-model="draft.client_name"
						class="iz-input"
						type="text"></label>
					<label class="pc-dialog__field"><span>Phone</span><input id="pc-edit-client-phone"
						v-model="draft.client_phone"
						class="iz-input"
						type="tel"></label>
					<label class="pc-dialog__field"><span>Email</span><input id="pc-edit-client-email"
						v-model="draft.client_email"
						class="iz-input"
						type="email"></label>
					<label class="pc-dialog__field"><span>Client address</span><input id="pc-edit-client-address"
						v-model="draft.client_address"
						class="iz-input"
						type="text"></label>
				</div>
				<RoleChips v-if="draft.client_role"
					label="Client roles"
					label-id="pc-edit-client-roles"
					:options="roleOptions"
					:selected="draft.client_role"
					:disabled="busy"
					@toggle="draft.client_role = toggled(draft.client_role, $event)" />
				<p class="pc-dialog__section">
					Project location
				</p>
				<div class="pc-dialog__grid pc-dialog__grid--location">
					<label class="pc-dialog__field pc-dialog__field--wide"><span>Street</span><input id="pc-edit-street"
						v-model="draft.loc_street"
						class="iz-input"
						type="text"></label>
					<label class="pc-dialog__field"><span>City</span><input id="pc-edit-city"
						v-model="draft.loc_city"
						class="iz-input"
						type="text"></label>
					<label class="pc-dialog__field"><span>ZIP</span><input id="pc-edit-zip"
						v-model="draft.loc_zip"
						class="iz-input"
						type="text"></label>
				</div>
				<p v-if="error" class="pc-dialog__error" role="alert">
					{{ error }}
				</p>
				<div class="pc-dialog__buttons">
					<button type="button"
						class="iz-btn"
						:disabled="busy"
						@click="closeDialog">
						Cancel
					</button>
					<button type="submit" class="iz-btn iz-btn--primary" :disabled="busy">
						{{ busy ? 'Saving…' : 'Save' }}
					</button>
				</div>
			</form>
		</dialog>

		<dialog ref="delete"
			class="pc-dialog"
			aria-labelledby="pc-dialog-delete"
			@close="dialog = ''">
			<form class="pc-dialog__body" @submit.prevent="remove">
				<h3 id="pc-dialog-delete" class="pc-dialog__title">
					Delete {{ project.name }}?
				</h3>
				<p class="pc-dialog__text">
					This removes its shared files, private folders, board, notes, timeline and membership group. It cannot be undone.
				</p>
				<label class="pc-dialog__field">
					<span>Type the project name to confirm</span>
					<input id="pc-delete-confirm"
						v-model="confirmName"
						class="iz-input"
						type="text"
						autocomplete="off">
				</label>
				<p v-if="error" class="pc-dialog__error" role="alert">
					{{ error }}
				</p>
				<div class="pc-dialog__buttons">
					<button type="button"
						class="iz-btn"
						:disabled="busy"
						@click="closeDialog">
						Cancel
					</button>
					<button type="submit" class="iz-btn pc-dialog__danger" :disabled="busy || confirmName.trim() !== String(project.name || '').trim()">
						{{ busy ? 'Deleting…' : 'Delete project' }}
					</button>
				</div>
			</form>
		</dialog>
	</div>
</template>

<script>
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import ChatOutline from 'vue-material-design-icons/ChatOutline.vue'
import Check from 'vue-material-design-icons/Check.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import MapMarkerOutline from 'vue-material-design-icons/MapMarkerOutline.vue'
import PencilOutline from 'vue-material-design-icons/PencilOutline.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import TrayArrowDown from 'vue-material-design-icons/TrayArrowDown.vue'
import { CLIENT_ROLE_OPTIONS } from '../macros/client-roles.js'
import { PROJECT_STATUS_OPTIONS, normalizeProjectStatus } from '../constants/project-statuses.js'
import RoleChips from './RoleChips.vue'
import { api } from './api.js'
import { toggled } from './members.js'
import { permissionsOf, detailsDraft, clientDraft, changesOf, detailsFields, CLIENT_FIELDS, messageOf } from './project-actions.js'

// The project header's actions in the new layout: Chat, Contacts, and More,
// which holds what the current interface's project menu does (edit details,
// client and address, status, export, delete) so nobody has to leave.
export default {
	name: 'ProjectActions',
	components: { AccountGroupOutline, ChatOutline, Check, ChevronDown, DotsHorizontal, EmailOutline, MapMarkerOutline, PencilOutline, TrashCanOutline, TrayArrowDown, RoleChips },
	props: {
		project: { type: Object, required: true },
		context: { type: Object, required: true },
		chatUrl: { type: String, default: null },
	},
	data() {
		return {
			open: '',
			dialog: '',
			draft: {},
			confirmName: '',
			busy: false,
			error: '',
			notice: '',
			noticeError: false,
			statuses: PROJECT_STATUS_OPTIONS,
			roleOptions: CLIENT_ROLE_OPTIONS,
		}
	},
	computed: {
		can() { return permissionsOf(this.project, this.context) },
		currentStatus() { return normalizeProjectStatus(this.project.status) },
		hasClient() { return !!(this.project.client_name || this.project.client_email || this.project.client_phone || this.project.client_address) },
	},
	watch: {
		'project.id'() { this.open = ''; this.notice = ''; this.closeDialog() },
	},
	mounted() {
		document.addEventListener('click', this.onOutside, true)
		document.addEventListener('keydown', this.onKey)
	},
	beforeDestroy() {
		document.removeEventListener('click', this.onOutside, true)
		document.removeEventListener('keydown', this.onKey)
	},
	methods: {
		toggled,
		// Focus moves into the menu only when it was opened from the keyboard
		// (a click from a keyboard reports detail 0); a mouse user sees no item
		// singled out.
		toggle(name, event) {
			this.open = this.open === name ? '' : name
			if (this.open && event?.detail === 0) this.$nextTick(() => this.$el.querySelector('.pc-actions__pop .pc-actions__item:not(:disabled)')?.focus())
		},
		close(button) {
			this.open = ''
			if (button) this.$refs[button]?.focus()
		},
		// Escape closes an open menu wherever focus is, and returns it to the button.
		onKey(event) {
			if (event.key === 'Escape' && this.open && !this.dialog) this.close(this.open === 'more' ? 'moreButton' : 'contactsButton')
		},
		onOutside(event) {
			if (this.open && !this.$el.querySelector('.pc-actions__row').contains(event.target)) this.open = ''
		},
		pick(tab) {
			this.open = ''
			this.$emit('navigate', tab)
		},
		show(name, draft) {
			this.open = ''
			this.error = ''
			this.draft = draft
			this.dialog = name
			this.$nextTick(() => {
				const el = this.$refs[name]
				if (el && typeof el.showModal === 'function' && !el.open) el.showModal()
				el?.querySelector('input, textarea')?.focus()
			})
		},
		openDetails() { this.show('details', detailsDraft(this.project)) },
		openClient() { this.show('client', clientDraft(this.project)) },
		openDelete() { this.confirmName = ''; this.show('delete', {}) },
		closeDialog() {
			for (const name of ['details', 'client', 'delete']) {
				const el = this.$refs[name]
				if (el?.open) el.close()
			}
			this.dialog = ''
		},
		say(text, isError = false) {
			this.notice = text
			this.noticeError = isError
		},
		async save(body, done) {
			if (!Object.keys(body).length) { this.closeDialog(); return }
			this.busy = true
			this.error = ''
			try {
				await api.updateProject(this.project.id, body)
				this.closeDialog()
				this.say(done)
				this.$emit('updated')
			} catch (error) {
				this.error = messageOf(error, 'The changes could not be saved.')
			} finally {
				this.busy = false
			}
		},
		saveDetails() { return this.save(changesOf(this.draft, this.project, detailsFields(this.can)), 'Project details saved.') },
		saveClient() { return this.save(changesOf(this.draft, this.project, CLIENT_FIELDS), 'Client and address saved.') },
		async setStatus(status) {
			this.open = ''
			if (status === this.currentStatus) return
			this.busy = true
			try {
				await api.updateProject(this.project.id, { status })
				this.say('Status changed to ' + (this.statuses.find(s => s.value === status)?.label || status) + '.')
				this.$emit('updated')
			} catch (error) {
				this.say(messageOf(error, 'The status could not be changed.'), true)
			} finally {
				this.busy = false
			}
		},
		async exportProject() {
			this.open = ''
			this.busy = true
			try {
				const result = await api.requestExport(this.project.id)
				this.say(result?.message || 'Export is being prepared. You will be notified when it is ready.')
			} catch (error) {
				this.say(messageOf(error, 'The export could not be started.'), true)
			} finally {
				this.busy = false
			}
		},
		async remove() {
			if (this.confirmName.trim() !== String(this.project.name || '').trim()) return
			this.busy = true
			this.error = ''
			try {
				await api.deleteProject(this.project.id)
				this.closeDialog()
				this.$emit('deleted', { projectId: Number(this.project.id), name: this.project.name })
			} catch (error) {
				this.error = messageOf(error, 'The project could not be deleted.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.pc-actions { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }
.pc-actions__row { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; }
.pc-actions__anchor { position: relative; }
.pc-actions__btn { gap: 6px; min-height: 36px; text-decoration: none; }
.pc-actions__btn--open { border-color: var(--iz-accent); background: var(--iz-accent-bg); color: var(--iz-accent-bg-text); }
.pc-actions__caret { display: inline-flex; opacity: 0.7; }

.pc-actions__pop { position: absolute; z-index: 60; inset-block-start: calc(100% + 6px); inset-inline-end: 0; display: flex; flex-direction: column; width: 290px; max-width: calc(100vw - 32px); padding: 6px; border: 1px solid var(--iz-border); border-radius: var(--iz-radius-lg); background: var(--iz-surface); box-shadow: var(--iz-shadow-lift, 0 12px 32px rgba(0, 0, 0, 0.16)); }
.pc-actions__label { margin: 0; padding: 8px 10px 4px; font-size: var(--iz-fs-micro); font-weight: 700; color: var(--iz-text-secondary); text-transform: uppercase; letter-spacing: 0.5px; }
.pc-actions__client { display: flex; flex-direction: column; gap: 2px; padding: 4px 10px 8px; font-size: var(--iz-fs-sm); color: var(--iz-text-secondary); overflow-wrap: anywhere; }
.pc-actions__client strong { font-size: var(--iz-fs-md); color: var(--iz-text); }
.pc-actions__copyable { user-select: all; }
.pc-actions__muted { margin: 0; padding: 4px 10px 8px; font-size: var(--iz-fs-sm); color: var(--iz-text-secondary); }
.pc-actions__sep { height: 1px; margin: 6px 4px; background: var(--iz-border); }
.pc-actions__item { display: flex; align-items: center; gap: 10px; width: 100%; min-height: 38px; margin: 0; padding: 7px 10px; border: 0; border-radius: var(--iz-radius); background: transparent; color: var(--iz-text); font: inherit; font-size: var(--iz-fs-md); text-align: start; text-decoration: none; cursor: pointer; }
.pc-actions__item:hover:not(:disabled), .pc-actions__item:focus-visible { background: var(--iz-surface-subtle); outline: none; }
.pc-actions__item:focus-visible { box-shadow: inset 0 0 0 2px var(--iz-accent); }
/* Nextcloud paints any focused button; only hover and keyboard focus may mark an item. */
.pc-actions__item:focus:not(:focus-visible):not(:hover) { background: transparent; }
.pc-actions__item:disabled { cursor: default; opacity: 0.5; }
.pc-actions__item > .material-design-icon { color: var(--iz-text-secondary); }
.pc-actions__item small { display: block; font-size: var(--iz-fs-xs); color: var(--iz-text-secondary); }
.pc-actions__item--danger, .pc-actions__item--danger > .material-design-icon { color: var(--iz-danger-text); }
.pc-actions__status { min-height: 34px; }
.pc-actions__check { margin-inline-start: auto; color: var(--iz-accent); }
.pc-actions__dot { width: 9px; height: 9px; flex-shrink: 0; margin-inline: 3px; border-radius: 50%; background: var(--iz-text-secondary); }
.pc-actions__dot--active { background: var(--iz-success); }
.pc-actions__dot--waiting { background: var(--iz-warning-text); }
.pc-actions__dot--done { background: var(--iz-accent); }
.pc-actions__notice { margin: 0; padding: 6px 10px; border-radius: var(--iz-radius); background: var(--iz-success-bg); color: var(--iz-success-text); font-size: var(--iz-fs-sm); max-width: 360px; text-align: end; }
.pc-actions__notice--error { background: var(--iz-danger-bg); color: var(--iz-danger-text); }

/* Nextcloud's own styles show every dialog; only an open one may show. */
.pc-actions .pc-dialog:not([open]) { display: none; }
.pc-dialog { position: fixed; margin: auto; width: min(440px, calc(100vw - 32px)); max-height: calc(100vh - 48px); padding: 0; border: 0; border-radius: var(--iz-radius-lg); background: var(--iz-surface); color: var(--iz-text); box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3); }
.pc-dialog--wide { width: min(640px, calc(100vw - 32px)); }
.pc-dialog::backdrop { background: rgba(20, 12, 28, 0.45); }
.pc-dialog__body { display: flex; flex-direction: column; gap: 14px; margin: 0; padding: 22px 24px; }
.pc-dialog__title { margin: 0; font-family: 'Space Grotesk', system-ui, -apple-system, sans-serif; font-size: var(--iz-fs-lg); font-weight: 600; overflow-wrap: anywhere; }
.pc-dialog__text { margin: 0; font-size: var(--iz-fs-md); color: var(--iz-text-secondary); }
.pc-dialog__section { margin: 4px 0 0; font-size: var(--iz-fs-micro); font-weight: 700; color: var(--iz-text-secondary); text-transform: uppercase; letter-spacing: 0.5px; }
.pc-dialog__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.pc-dialog__grid--location { grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr); }
.pc-dialog__field--wide { grid-column: 1 / -1; }
.pc-dialog__field { display: flex; flex-direction: column; gap: 5px; min-width: 0; font-size: var(--iz-fs-sm); font-weight: 600; }
.pc-dialog__field .iz-input { width: 100%; min-height: 40px; margin: 0; box-sizing: border-box; font: inherit; font-weight: 400; }
.pc-dialog__field textarea.iz-input { resize: vertical; }
.pc-dialog__muted { margin: 0; font-size: var(--iz-fs-xs); color: var(--iz-text-secondary); }
.pc-dialog__error { margin: 0; padding: 8px 10px; border-radius: var(--iz-radius); background: var(--iz-danger-bg); color: var(--iz-danger-text); font-size: var(--iz-fs-sm); }
.pc-dialog__buttons { display: flex; justify-content: flex-end; gap: 8px; padding-top: 4px; }
.pc-dialog__buttons .iz-btn { min-height: 40px; }
.pc-dialog__danger { border-color: var(--iz-danger); background: var(--iz-danger); color: #fff; }
.iz-btn:disabled { cursor: default; opacity: 0.5; }

@media (max-width: 700px) {
	.pc-actions { align-items: stretch; }
	.pc-actions__row { justify-content: flex-start; }
	.pc-actions__pop { inset-inline-end: auto; inset-inline-start: 0; }
	.pc-actions__notice { text-align: start; max-width: none; }
	.pc-dialog__grid, .pc-dialog__grid--location { grid-template-columns: minmax(0, 1fr); }
}
</style>
