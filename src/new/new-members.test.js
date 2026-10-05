import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'
import * as members from './members.js'

function view(api, overrides = {}) {
	const source = readFileSync(new URL('./NewMembers.vue', import.meta.url), 'utf8')
	const script = source.match(/<script>([\s\S]*?)<\/script>/)[1]
		.replace(/^import .*$/gm, '')
		.replace(/^\tcomponents: \{[^\n]*\},$/m, '')
		.replace('export default', 'globalThis.component =')
	const context = {
		...members,
		api,
		errorMessage: error => error?.response?.status === 403 ? 'You do not have access to this data.' : 'The data could not be loaded. Please try again.',
		actionMessage: (error, fallback) => error?.response?.data?.message || fallback,
		setTimeout,
		clearTimeout,
		Set,
	}
	vm.runInNewContext(script, context)
	const options = context.component
	const instance = { ...Object.fromEntries(Object.entries(options.props).map(([k, spec]) => [k, spec.default])), ...options.data(), projectId: 21, ...overrides }
	for (const [key, method] of Object.entries(options.methods)) instance[key] = method.bind(instance)
	for (const [key, getter] of Object.entries(options.computed)) Object.defineProperty(instance, key, { get: getter.bind(instance) })
	instance.$nextTick = fn => fn && fn()
	instance.$el = { querySelector: () => null }
	return instance
}
const team = { members: [{ id: 'emma', displayName: 'Emma de Vries', isOwner: true, drascivsRoles: ['driver'], functionalRoleKeys: ['cpl'] }, { id: 'thomas', displayName: 'Thomas Jansen', drascivsRoles: ['responsible'], functionalRoleKeys: ['cpl'] }], functionalRoles: [{ key: 'cpl', name: 'CPL' }, { key: 'client', name: 'Client/Developer' }] }
const wait = ms => new Promise(resolve => setTimeout(resolve, ms))

test('a failed members read is shown as a failure, not an empty team', async () => {
	const v = view({ members: async () => { const e = new Error('no'); e.response = { status: 403 }; throw e } })
	await v.load()
	assert.equal(v.members.length, 0)
	assert.equal(v.error, 'You do not have access to this data.')
	assert.equal(v.loading, false)
})

test('adding sends the chosen person and roles, reloads, and says what happened', async () => {
	const calls = []
	const v = view({
		members: async () => ({ ...team, members: [...team.members, { id: 'jv', displayName: 'Jeroen Visser' }] }),
		addMember: async (...args) => { calls.push(args); return { alreadyMember: false } },
	})
	v.openAdd()
	assert.equal(v.addProblem, 'Choose a person to add.')
	v.pick({ id: 'jv', label: 'Jeroen Visser', subname: 'jv' })
	v.addDraft.drascivs = v.toggled(v.addDraft.drascivs, 'responsible')
	assert.equal(v.addProblem, 'Choose at least one project role.')
	v.addDraft.functional = ['client']
	assert.equal(v.addProblem, '')
	await v.add()
	assert.equal(JSON.stringify(calls[0]), JSON.stringify([21, 'jv', ['responsible'], ['client']]))
	assert.equal(v.adding, false)
	assert.equal(v.notice, 'Jeroen Visser was added to the project.')
	assert.equal(v.members.length, 3)
})

test('the server’s own reason is shown when adding is refused', async () => {
	const v = view({ addMember: async () => { const e = new Error('no'); e.response = { data: { message: 'The subscription allows 5 members.' } }; throw e } })
	v.openAdd()
	v.pick({ id: 'jv', label: 'Jeroen Visser' })
	v.addDraft = { drascivs: ['driver'], functional: ['cpl'] }
	await v.add()
	assert.equal(v.addError, 'The subscription allows 5 members.')
	assert.equal(v.adding, true, 'the panel stays open with the choices intact')
	assert.equal(v.candidate.id, 'jv')
})

test('someone who is already a member is reported as such', async () => {
	const v = view({ members: async () => team, addMember: async () => ({ alreadyMember: true }) })
	v.openAdd()
	v.pick({ id: 'thomas', label: 'Thomas Jansen' })
	v.addDraft = { drascivs: ['driver'], functional: ['cpl'] }
	await v.add()
	assert.equal(v.notice, 'Thomas Jansen is already on this project.')
})

test('editing saves both role sets at once and keeps the rules', async () => {
	const calls = []
	const v = view({ updateMemberRoles: async (...args) => { calls.push(args); return { member: { drascivsRoles: args[2], functionalRoleKeys: args[3] } } } })
	v.members = team.members
	v.startEdit(v.members[1])
	assert.equal(v.editDraft.drascivs.join(), 'responsible')
	v.editDraft.drascivs = v.toggled(v.editDraft.drascivs, 'responsible')
	assert.equal(v.editProblem, 'Choose at least one DRASCIVS role.')
	await v.saveEdit(v.members[1])
	assert.equal(calls.length, 0, 'nothing is sent while a rule is broken')
	v.editDraft.drascivs = ['verifier', 'signer']
	await v.saveEdit(v.members[1])
	assert.equal(JSON.stringify(calls[0]), JSON.stringify([21, 'thomas', ['verifier', 'signer'], ['cpl']]))
	assert.equal(v.members[1].drascivsRoles.join(), 'verifier,signer')
	assert.equal(v.editingId, null)
	assert.equal(v.notice, 'Roles updated for Thomas Jansen.')
})

test('search leaves out members, and an older answer cannot replace a newer one', async () => {
	let call = 0
	const v = view({
		searchUsers: async query => {
			call++
			if (query === 'j') { await wait(200); return { users: [{ id: 'stale', displayName: 'Stale' }] } }
			return { users: [{ id: 'thomas', displayName: 'Thomas Jansen' }, { id: 'jv', displayName: 'Jeroen Visser', subname: 'jeroen.visser@firma.nl' }] }
		},
	}, { organizationId: 4 })
	v.members = team.members
	v.query = 'j'
	v.search()
	await wait(300)
	v.query = 'je'
	v.search()
	await wait(700)
	assert.equal(v.results.map(u => u.id).join(), 'jv', 'Thomas is already a member; the stale answer is dropped')
	assert.equal(v.results[0].subname, 'jeroen.visser@firma.nl')
	assert.equal(v.searching, false)
	assert.equal(call, 2)
})

test('the current user gets no chat button with themselves', () => {
	const v = view({}, { currentUserId: 'emma' })
	assert.equal(v.isSelf({ id: 'emma' }), true)
	assert.equal(v.isSelf({ id: 'thomas' }), false)
	assert.equal(v.memberMeta({ id: 'emma' }), 'emma · you')
})

test('removing a member asks first, then drops them from the list', async () => {
	const calls = []
	const v = view({ removeMember: async (...args) => { calls.push(args); return { removed: true } } })
	v.members = team.members.map(member => ({ ...member }))
	v.startRemove(v.members[1])
	assert.equal(v.removingId, 'thomas')
	await v.remove(v.members[1])
	assert.equal(JSON.stringify(calls[0]), JSON.stringify([21, 'thomas']))
	assert.deepEqual(v.members.map(member => member.id), ['emma'])
	assert.equal(v.removingId, null)
	assert.equal(v.notice, 'Thomas Jansen was removed from the project.')
})

test('a refused removal keeps the member and shows why', async () => {
	const v = view({ removeMember: async () => { const e = new Error('no'); e.response = { data: { message: 'The project owner cannot be removed.' } }; throw e } })
	v.members = team.members.map(member => ({ ...member }))
	v.startRemove(v.members[0])
	await v.remove(v.members[0])
	assert.equal(v.members.length, 2)
	assert.equal(v.removingId, 'emma')
	assert.equal(v.removeError, 'The project owner cannot be removed.')
})
