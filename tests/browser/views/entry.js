import Vue from '/app/node_modules/vue/dist/vue.esm.js'
import Files from '/app/src/components/ProjectFiles/ProjectFilesBrowser.vue'
import NewIntake from '/app/src/new/NewIntake.vue'
import NewActivity from '/app/src/new/NewActivity.vue'
import NewOverview from '/app/src/new/NewOverview.vue'
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
			h('div', { attrs: { id: 'overview' } }, [h(NewOverview, { props: { project: { id: 21, description: '' }, context: { userId: 'emma' }, overview, legacyUrl: '#' } })]),
		]),
		h('div', { attrs: { id: 'legacy-host' }, style: 'padding:22px;background:#fff' }, [
			h(Files, { props: { projectId: 22, sharedRoots: roots, privateRoots: [], loading: false, error: '' } }),
		]),
	]),
})
