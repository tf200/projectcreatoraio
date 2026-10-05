import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'
import * as ba from './board-access.js'

const CARDS = ['AVP', 'Blokkenschema', 'Quickscan', 'VO'].map((title, index) => ({ id: index + 1, title }))
const allowed = (...titles) => ({ allowed: titles.length, total: CARDS.length, allowedCards: CARDS.filter(card => titles.includes(card.title)) })
const ALL = CARDS.map(card => card.title)
const member = (id, actions, extra = {}) => ({
	id,
	displayName: id,
	isOwner: false,
	drascivsRoleLabels: ['Driver'],
	functionalRoleLabels: ['CPL'],
	boardAccess: 'edit',
	actions: { view: allowed(...ALL), move: allowed(...ALL), verify: allowed(...ALL), sign: allowed(...ALL), ...actions },
	...extra,
})
const team = members => ({ boardId: 30, totalCards: CARDS.length, scope: 'team', members })

test('a member is limited when an action reaches fewer than all cards', () => {
	const access = ba.accessOf(team([
		member('ADMIN_ORG01', {}, { isOwner: true }),
		member('EMPLOYEE_ORG01', { move: allowed('Blokkenschema', 'Quickscan', 'VO'), verify: allowed('Quickscan'), sign: allowed('Quickscan') }),
	]))
	assert.equal(access.total, 4)
	assert.deepEqual(access.members.map(m => m.limited), [false, true])
	assert.deepEqual(access.members[0].roles, ['Driver', 'CPL'])
	assert.equal(access.members[0].isOwner, true)
	assert.deepEqual(access.members[1].missing.map(card => [card.title, card.actions.join()]), [
		['AVP', 'move,verify,sign'],
		['Blokkenschema', 'verify,sign'],
		['VO', 'verify,sign'],
	])
	assert.equal(access.unnamed, 0)
})

test('an action on no card is said once instead of on every card', () => {
	const access = ba.accessOf(team([member('lotte', { move: allowed(), verify: allowed(), sign: allowed('AVP') }, { boardAccess: 'read' })]))
	const lotte = access.members[0]
	assert.deepEqual(lotte.none, ['move', 'verify'])
	assert.deepEqual(lotte.missing.map(card => [card.title, card.actions.join()]), [['Blokkenschema', 'sign'], ['Quickscan', 'sign'], ['VO', 'sign']])
	assert.equal(ba.noneText(lotte), 'Can’t move or verify any card (read only on this board).')
	assert.equal(ba.noneText({ ...lotte, boardAccess: 'none', none: ['view', 'move', 'verify', 'sign'] }, true), 'You can’t see, move, verify or sign any card (no access to this board).')
	assert.equal(ba.noneText(ba.accessOf(team([member('a')])).members[0]), '')
	assert.deepEqual(ba.accessOf({ totalCards: 0, members: [member('a')] }).members[0].none, [], 'an empty board leaves nothing undone')
})

test('cards nobody listed can see are counted, not named', () => {
	const access = ba.accessOf({ totalCards: 6, scope: 'self', members: [member('test1', { view: { allowed: 4, total: 6, allowedCards: CARDS } })] })
	assert.equal(access.scope, 'self')
	assert.equal(access.unnamed, 2)
	assert.equal(access.members[0].missing.length, 0)
	assert.equal(access.members[0].limited, true)
})

test('a malformed summary is refused rather than drawn empty', () => {
	assert.throws(() => ba.accessOf(null))
	assert.throws(() => ba.accessOf({ members: 'x' }))
	const access = ba.accessOf({ totalCards: 2, members: [{ id: 'x' }] })
	assert.deepEqual(access.members[0].actions.view, { allowed: 0, total: 2 })
	assert.equal(access.members[0].boardAccess, 'none')
	assert.equal(access.scope, 'team')
})

test('cells read All, a count or None', () => {
	assert.deepEqual(ba.cellOf({ allowed: 20, total: 20 }), { text: 'All 20', tone: 'success' })
	assert.deepEqual(ba.cellOf({ allowed: 19, total: 20 }), { text: '19 of 20', tone: 'warning' })
	assert.deepEqual(ba.cellOf({ allowed: 0, total: 20 }), { text: 'None', tone: 'muted' })
	assert.equal(ba.cellOf({ allowed: 0, total: 0 }).tone, 'muted')
})

test('the board line names who is limited and what they cannot do', () => {
	const line = ba.lineOf(ba.accessOf(team([
		member('ADMIN_ORG01'),
		member('EMPLOYEE_ORG01', { move: allowed('AVP'), verify: allowed('AVP') }),
		member('test1', { sign: allowed() }),
	])))
	assert.equal(line.lead, 'Everyone can see all 4 cards. ')
	assert.equal(line.who, 'EMPLOYEE_ORG01 and test1')
	assert.equal(line.tail, " can't move, verify or sign every card.")
})

test('the board line includes seeing when someone cannot see every card', () => {
	const line = ba.lineOf(ba.accessOf(team([member('a'), member('b', { view: allowed('AVP') })])))
	assert.equal(line.lead, '')
	assert.equal(line.tail, " can't see every card.")
})

test('the board line says so when everyone can do everything', () => {
	assert.equal(Object.values(ba.lineOf(ba.accessOf(team([member('a'), member('b'), member('c')])))).join(''), 'All 3 members can view, move, verify and sign all 4 cards.')
	assert.equal(Object.values(ba.lineOf(ba.accessOf(team([member('EMPLOYEE_ORG01')])))).join(''), 'EMPLOYEE_ORG01 can view, move, verify and sign all 4 cards.')
})

test('a member who only sees their own access reads it about themselves', () => {
	const own = ba.accessOf({ totalCards: 4, scope: 'self', members: [member('test1', { move: allowed('AVP', 'VO'), sign: allowed() })] })
	assert.equal(ba.lineOf(own).lead, 'You can see all 4 cards, move 2, verify 4 and sign none.')
	assert.equal(ba.lineOf(ba.accessOf({ totalCards: 4, scope: 'self', members: [member('test1')] })).lead, 'You can view, move, verify and sign all 4 cards.')
})

test('no line without cards or members', () => {
	assert.equal(ba.lineOf(ba.accessOf({ totalCards: 0, members: [member('a')] })), null)
	assert.equal(ba.lineOf(ba.accessOf({ totalCards: 4, members: [] })), null)
})

test('many limited members are summed up', () => {
	assert.equal(ba.namesOf(['a', 'b', 'c']), 'a, b and c')
	assert.equal(ba.namesOf(['a', 'b', 'c', 'd', 'e']), 'a, b and 3 others')
	assert.equal(ba.missingText(['verify', 'sign']), 'verify, sign')
	assert.equal(ba.missingText(['view', 'move', 'verify', 'sign']), 'any action')
})

// NewTasks with DeckBoard stubbed, the way Vue's `extends` merges them.
function newTasks(boardAccess) {
	const source = readFileSync(new URL('./NewTasks.vue', import.meta.url), 'utf8')
	const script = source.match(/<script>([\s\S]*?)<\/script>/)[1]
		.replace(/^import .*$/gm, '')
		.replace('export default', 'globalThis.component =')
	const context = {
		DeckBoard: { methods: { reload: async () => {} } },
		api: { boardAccess },
		errorMessage: () => 'The data could not be loaded. Please try again.',
		accessOf: ba.accessOf,
		TaskProgress: {},
		BoardAccessLine: {},
		MemberAccess: {},
		setTimeout,
		clearTimeout,
	}
	vm.runInNewContext(script, context)
	const component = context.component
	const view = { ...component.data(), projectId: 20, activeTab: 'board', $nextTick: async () => {}, $refs: {} }
	for (const [key, method] of Object.entries(component.methods)) view[key] = method.bind(view)
	return { component, view, source }
}

test('the Tasks tab keeps the newest read of who can do what', async () => {
	const answers = []
	const { view } = newTasks(() => new Promise(resolve => answers.push(resolve)))
	const first = view.loadAccess()
	const second = view.loadAccess()
	answers[1](team([member('new')]))
	answers[0](team([member('old')]))
	await Promise.all([first, second])
	assert.equal(view.access.members[0].id, 'new', 'an older answer arriving late is dropped')
})

test('a failed refresh keeps the table and says so', async () => {
	let fail = false
	const { view } = newTasks(async () => { if (fail) throw new Error('down'); return team([member('a')]) })
	await view.loadAccess()
	assert.equal(view.access.members.length, 1)
	fail = true
	await view.loadAccess()
	assert.equal(view.access.members.length, 1)
	assert.equal(view.accessError, 'Who can do what could not be refreshed.')
	const empty = newTasks(async () => { throw new Error('down') }).view
	await empty.loadAccess()
	assert.equal(empty.access, null)
	assert.equal(empty.accessError, 'The data could not be loaded. Please try again.')
})

test('Who can do what opens Card Permissions at the table', async () => {
	let focused = 0
	const { view } = newTasks(async () => team([]))
	view.$refs.access = { focus: () => focused++ }
	await view.openAccess()
	assert.equal(view.activeTab, 'permissions')
	assert.equal(focused, 1)
})

test('every member gets Card Permissions; only managers get the card rules', () => {
	const { source } = newTasks(async () => null)
	const tab = source.match(/<button\s+v-if="([^"]*)"\s+class="deck-board__tab"\s+:class="\{ 'deck-board__tab--active': activeTab === 'permissions' \}"/)
	assert.equal(tab[1], '!loading && !error')
	assert.match(source, /<DeckCardPolicyManager\s+v-if="canManage"/)
	assert.match(source, /<MemberAccess ref="access"/)
	const theme = readFileSync(new URL('./tasks-theme.css', import.meta.url), 'utf8')
	assert.match(theme, /\.pc-new \.pc-tasks-theme\.deck-board \.project-member-access \{ display: none !important; \}/)
})
