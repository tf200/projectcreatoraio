import Vue from '/app/node_modules/vue/dist/vue.esm.js'
import Files from '/app/src/components/ProjectFiles/ProjectFilesBrowser.vue'
import NewIntake from '/app/src/new/NewIntake.vue'
import NewActivity from '/app/src/new/NewActivity.vue'
import NewOverview from '/app/src/new/NewOverview.vue'
import NewMembers from '/app/src/new/NewMembers.vue'
import NewWhiteboardActivity from '/app/src/new/NewWhiteboardActivity.vue'
import TaskProgress from '/app/src/new/TaskProgress.vue'
import NewCalendar from '/app/src/new/NewCalendar.vue'
import NewCreate from '/app/src/new/NewCreate.vue'
import ProjectActions from '/app/src/new/ProjectActions.vue'
import '/app/src/new/new-ui.css'
import '/app/src/new/documents-theme.css'

window._oc_webroot = ''

const roots = [{
	id: 100,
	name: '01 Intake',
	type: 'folder',
	path: '/Projects/Testerij/01 Intake',
	children: [
		{ id: 10, name: 'Tekeningen', type: 'folder', path: '/Projects/Testerij/01 Intake/Tekeningen', children: [] },
		{ id: 11, name: 'Intakeformulier-getekend.pdf', type: 'file', size: 2411724, mimetype: 'application/pdf', path: '/Projects/Testerij/01 Intake/Intakeformulier-getekend.pdf' },
		{ id: 12, name: 'Offerte-Testerij-v3.pdf', type: 'file', size: 860160, mimetype: 'application/pdf', path: '/Projects/Testerij/01 Intake/Offerte-Testerij-v3.pdf' },
		{ id: 13, name: 'Kadaster-uittreksel.pdf', type: 'file', size: 319488, mimetype: 'application/pdf', path: '/Projects/Testerij/01 Intake/Kadaster-uittreksel.pdf' },
	],
}, { id: 200, name: '02 Ontwerp', type: 'folder', path: '/Projects/Testerij/02 Ontwerp', children: [] }]

const mine = [{ type: 0, participant: { uid: 'emma' } }]
const overview = {
	notes: { loading: false, error: '', data: { notes: [], risks: 0 } },
	activity: { loading: false, error: '', data: [] },
	tasks: { loading: false, error: '', data: [
		{ title: 'To do', cards: [{ id: 1, title: 'Intake controleren', assignedUsers: mine }, { id: 2, title: 'Offerte opvragen' }, { id: 3, title: 'Oud werk', done: '2026-09-01' }] },
		{ title: 'Review', cards: [{ id: 4, title: 'Situatieschets maken', assignedUsers: [{ type: 0, participant: 'thomas' }] }, { id: 5, title: 'Gearchiveerd', archived: true }] },
	] },
	planning: { loading: false, error: '', data: { processCompleted: { status: 'ok', doneCount: 1, totalRequired: 4 }, phases: [] } },
	files: { loading: false, error: '', data: { shared: [], private: [] } },
}

Vue.mixin({ methods: { t: window.t, n: window.n } })

const section = (h, id, tab, child) => h('section', { class: ['pc-module', 'pc-module--' + tab], attrs: { id } }, [child])

window.fixture = new Vue({
	el: '#app',
	render: h => h('div', [
		h('main', { class: 'pc-new' }, [
			section(h, 'documents', 'documents', h(Files, { class: 'pc-documents-theme', props: { projectId: 21, sharedRoots: roots, privateRoots: [], loading: false, error: '' } })),
			section(h, 'intake', 'intake', h(NewIntake, { props: { projectId: 21, canEdit: true } })),
			section(h, 'activity', 'activity', h(NewActivity, { props: { projectId: 21 } })),
			section(h, 'activity-denied', 'activity', h(NewActivity, { props: { projectId: 23 } })),
			h('div', { attrs: { id: 'actions' }, style: 'display:flex;justify-content:space-between;gap:16px;padding:18px 22px;min-height:560px;background:var(--iz-surface);border-radius:14px' }, [
				h('h1', { style: 'margin:0;font-size:22px' }, 'Firma de Testerij'),
				h(ProjectActions, { props: { project: { id: 21, name: 'Firma de Testerij', description: 'Oud', ownerId: 'emma', organization_id: 3, status: 1, client_name: 'ACME Corp', client_role: ['project_owner'], client_email: 'info@acme.nl', client_phone: '+31 10 123 4567', client_address: '', loc_street: 'Kruiskade 12', loc_city: 'Rotterdam', loc_zip: '' }, context: { userId: 'emma', organizationRole: 'member', organizationId: 3 }, chatUrl: '/call/abc' }, on: { navigate: tab => { window.navigatedTo = tab }, updated: () => { window.updates = (window.updates || 0) + 1 }, deleted: e => { window.deleted = e } } }),
			]),
			h('div', { attrs: { id: 'actions-member' }, style: 'display:flex;justify-content:flex-end;padding:18px 22px' }, [
				h(ProjectActions, { props: { project: { id: 21, name: 'Firma de Testerij', ownerId: 'emma', organization_id: 3, status: 1 }, context: { userId: 'thomas', organizationRole: 'member', organizationId: 3 }, chatUrl: null } }),
			]),
			h('div', { attrs: { id: 'create' } }, [h(NewCreate, { props: { context: { userId: 'emma', isGlobalAdmin: false, organizationId: 3 }, backLabel: 'Firma de Testerij', backHref: '#back' }, on: { created: e => { window.created = e }, cancel: () => { window.cancelled = true } } })]),
			section(h, 'calendar', 'agenda', h(NewCalendar, { props: { projectId: 22 } })),
			section(h, 'calendar-empty', 'agenda', h(NewCalendar, { props: { projectId: 21 } })),
			section(h, 'calendar-failed', 'agenda', h(NewCalendar, { props: { projectId: 24 } })),
			section(h, 'tasks-progress', 'tasks', h(TaskProgress, { props: { boardId: 31 } })),
			section(h, 'wb-activity', 'whiteboard', h(NewWhiteboardActivity, { props: { projectId: 21 } })),
			section(h, 'members', 'members', h(NewMembers, { props: { projectId: 21, currentUserId: 'emma', organizationId: 4, canManage: true }, on: { 'open-direct-chat': member => { window.chatWith = member.id } } })),
			section(h, 'members-readonly', 'members', h(NewMembers, { props: { projectId: 21, currentUserId: 'thomas', canManage: false } })),
			section(h, 'members-denied', 'members', h(NewMembers, { props: { projectId: 23, currentUserId: 'emma', canManage: true } })),
			h('div', { attrs: { id: 'overview' } }, [h(NewOverview, { props: { project: { id: 21, description: '' }, context: { userId: 'emma' }, overview, legacyUrl: '#' } })]),
		]),
		h('div', { attrs: { id: 'legacy-host' }, style: 'padding:22px;background:#fff' }, [
			h(Files, { props: { projectId: 22, sharedRoots: roots, privateRoots: [], loading: false, error: '' } }),
		]),
	]),
})
