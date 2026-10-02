import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'
import * as tp from './tasks-progress.js'

const critical = [{ title: 'Kritieke Processtap' }]
let nextId = 100
const card = (title, extra = {}) => ({ id: ++nextId, title, labels: critical, ...extra })
const COLUMNS = ['Process steps', 'Next priority', 'In progress', 'To review', 'Approved', 'Done']
const stacks = cardsByColumn => COLUMNS.map((title, order) => ({ id: order + 1, title, order, cards: cardsByColumn[order] || [] }))

test('the board is read in column order, without archived or deleted cards', () => {
	const board = tp.boardOf([
		{ id: 9, title: 'Done', order: 5, cards: [card('Quickscan')] },
		{ id: 1, title: 'Process steps', order: 0, cards: [card('VO'), card('Oud', { archived: true }), card('Weg', { deletedAt: 5 })] },
		{ id: 7, title: 'Gone', order: 2, deletedAt: 3, cards: [card('Hidden')] },
	])
	assert.equal(board.columns.map(c => c.title).join(), 'Process steps,Done')
	assert.equal(board.cards.map(c => c.title).join(), 'VO,Quickscan')
	assert.equal(board.cards[1].stage, 1)
})

test('completed and critical follow Deck’s own dashboard', () => {
	const board = tp.boardOf([
		{ id: 1, title: 'In progress', order: 0, cards: [card('A', { done: '2026-09-12T10:00:00Z' }), card('B'), { id: 1, title: 'Other', labels: [{ title: 'Belangrijk' }] }, { id: 2, title: 'Plain', labels: [] }] },
		{ id: 2, title: 'Afgerond', order: 1, cards: [card('C')] },
		{ id: 3, title: 'Shipped', order: 2, isDoneColumn: true, cards: [card('D')] },
	])
	const by = Object.fromEntries(board.cards.map(c => [c.title, c]))
	assert.equal(by.A.completed, true, 'a done date counts')
	assert.equal(by.B.completed, false)
	assert.equal(by.C.completed, true, 'a column named Afgerond counts')
	assert.equal(by.D.completed, true, 'a done column counts')
	assert.equal(by.Other.critical, true, 'the label’s former name still counts')
	assert.equal(by.Plain.critical, false)
	const counts = tp.countsOf(board.cards, Date.parse('2026-10-02'))
	assert.equal(JSON.stringify(counts), JSON.stringify({ total: 6, completed: 3, percent: 50, overdue: 0, criticalOpen: 2, criticalTotal: 5, criticalDone: 3, otherOpen: 1, otherTotal: 1 }))
})

test('overdue counts open cards past their due date only', () => {
	const now = Date.parse('2026-10-02T12:00:00Z')
	const { cards } = tp.boardOf(stacks({ 0: [card('Late', { duedate: '2026-09-29T00:00:00Z' }), card('Soon', { duedate: '2026-10-09T00:00:00Z' })], 5: [card('Late but done', { duedate: '2026-09-01T00:00:00Z' })] }))
	assert.equal(tp.countsOf(cards, now).overdue, 1)
	assert.equal(tp.countsOf([], now).percent, 0)
})

test('steps follow the board’s dependencies, also through cards that are not critical', () => {
	const intake = card('Intakeformulier')
	const plain = { id: ++nextId, title: 'Intake inplannen & hosten', labels: [], dependentCards: [] }
	const report = { id: ++nextId, title: 'Intakeverslag', labels: [], dependentCards: [plain.id] }
	// DO depends on the report, which depends on a plain card, which depends on Huisnummerbesluit.
	const house = card('Huisnummerbesluit')
	plain.dependentCards = [house.id]
	const doCard = card('DO', { dependentCards: [report.id] })
	const steps = tp.stepsOf(tp.boardOf(stacks({ 0: [doCard, plain, report], 1: [house, intake] })).cards)
	assert.equal(steps.map(s => s.title).join(' > '), 'Intakeformulier > Huisnummerbesluit > DO', 'Huisnummerbesluit comes first because DO waits on it')
})

test('without dependencies the Combi timeline decides, then the due date, then the title', () => {
	const steps = tp.stepsOf(tp.boardOf(stacks({ 0: [
		card('Zelfbedacht', { duedate: '2026-12-01' }), card('Aaa eigen stap', { duedate: '2026-12-01' }), card('Eerder', { duedate: '2026-11-01' }),
		card('Huisnummerbesluit'), card('VO'), card('Intakeformulier'),
	] })).cards)
	assert.equal(steps.map(s => s.title).join(' > '), 'Intakeformulier > VO > Huisnummerbesluit > Eerder > Aaa eigen stap > Zelfbedacht')
})

test('open steps come first; a dependency cycle cannot hang the list', () => {
	const a = card('Quickscan'), b = card('AVP')
	a.dependentCards = [b.id]
	b.dependentCards = [a.id]
	const done = card('Intakeformulier')
	const steps = tp.stepsOf(tp.boardOf(stacks({ 0: [a, b], 5: [done] })).cards)
	assert.equal(steps.length, 3)
	assert.equal(steps[2].title, 'Intakeformulier', 'done steps go last even when they come first in the process')
})

test('the Due column says done, late, planned or not planned', () => {
	const now = Date.parse('2026-10-02T12:00:00Z')
	assert.deepEqual(tp.dueOf({ completed: true, done: '2026-09-12T10:00:00Z' }, now), { text: 'Done 12 Sept', tone: 'done' })
	assert.deepEqual(tp.dueOf({ completed: true, done: null }, now), { text: 'Done', tone: 'done' })
	assert.deepEqual(tp.dueOf({ completed: false, duedate: '2026-09-29T08:00:00Z' }, now), { text: '3 days late', tone: 'late' })
	assert.deepEqual(tp.dueOf({ completed: false, duedate: '2026-10-01T08:00:00Z' }, now), { text: '1 day late', tone: 'late' })
	assert.deepEqual(tp.dueOf({ completed: false, duedate: '2026-10-09T08:00:00Z' }, now), { text: '9 Oct', tone: 'normal' })
	assert.deepEqual(tp.dueOf({ completed: false, duedate: '2027-02-19T08:00:00Z' }, now), { text: '19 Feb 2027', tone: 'normal' })
	assert.deepEqual(tp.dueOf({ completed: false, duedate: null }, now), { text: 'Not planned', tone: 'muted' })
})

test('the timeline order is the one the server seeds and plans with', () => {
	const php = readFileSync(new URL('../../lib/Service/ProjectTypeDeckDefaults.php', import.meta.url), 'utf8')
	const titleByKey = Object.fromEntries([...php.matchAll(/'key' => '([^']+)', 'title' => '([^']+)'/g)].map(m => [m[1], m[2]]))
	// The first return is the empty list for other project types.
	const body = php.match(/function getDefaultCardKeysInTimelineOrder[\s\S]*?return \[\];[\s\S]*?return \[([\s\S]*?)\];/)[1]
	const keys = [...body.matchAll(/'([^']+)'/g)].map(m => m[1])
	assert.equal(tp.TIMELINE_ORDER.join('|'), keys.map(key => titleByKey[key]).join('|'))
})

function view(deckStacks) {
	const source = readFileSync(new URL('./TaskProgress.vue', import.meta.url), 'utf8')
	const script = source.match(/<script>([\s\S]*?)<\/script>/)[1]
		.replace(/^import .*$/gm, '')
		.replace('export default', 'globalThis.component =')
	const context = { ...tp, api: { deckStacks }, errorMessage: e => e?.response?.status === 403 ? 'You do not have access to this data.' : 'The data could not be loaded. Please try again.', Date, Array, Error, Number, Math }
	vm.runInNewContext(script, context)
	const options = context.component
	const instance = { ...options.data(), boardId: 31, refreshKey: 0, $refs: {}, $nextTick: fn => fn && fn() }
	for (const [key, method] of Object.entries(options.methods)) instance[key] = method.bind(instance)
	for (const [key, getter] of Object.entries(options.computed)) Object.defineProperty(instance, key, { get: getter.bind(instance) })
	return instance
}

test('a failed first read is a failure; a failed refresh keeps what is shown', async () => {
	const denied = view(async () => { const e = new Error('no'); e.response = { status: 403 }; throw e })
	await denied.load()
	assert.equal(denied.board, null)
	assert.equal(denied.error, 'You do not have access to this data.')

	let fail = false
	const v = view(async () => { if (fail) throw new Error('down'); return stacks({ 0: [card('VO')], 5: [card('Intakeformulier')] }) })
	await v.load()
	assert.equal(v.counts.criticalDone, 1)
	assert.equal(v.steps[0].title, 'VO')
	fail = true
	await v.load()
	assert.equal(v.board.cards.length, 2, 'the board on screen stays')
	assert.equal(v.error, 'Progress could not be refreshed.')
})

test('the track colour deepens along the open columns and turns green when done', () => {
	const v = view(async () => [])
	v.board = tp.boardOf(stacks({}))
	assert.match(v.colorOf({ stage: 0, completed: false }), /var\(--iz-accent\) 35%/)
	assert.match(v.colorOf({ stage: 4, completed: false }), /var\(--iz-accent\) 100%/)
	assert.equal(v.colorOf({ stage: 5, completed: false }), 'var(--iz-success)', 'the Done column')
	assert.equal(v.colorOf({ stage: 2, completed: true }), 'var(--iz-success)', 'a card marked done anywhere')
	assert.equal(v.reachOf({ stage: 5 }), '100%')
	assert.equal(v.stopClass({ stage: 2 }, 2), 'pc-progress__stop--here')
	assert.equal(v.stopClass({ stage: 2 }, 1), 'pc-progress__stop--passed')
})

test('the new Tasks tab extends DeckBoard and replaces only Deck’s dashboard row', () => {
	const tasks = readFileSync(new URL('./NewTasks.vue', import.meta.url), 'utf8')
	assert.match(tasks, /extends: DeckBoard/)
	assert.doesNotMatch(tasks, /<style/, 'no own styles, so DeckBoard’s scoped styles keep applying')
	const module = readFileSync(new URL('./ProjectModule.vue', import.meta.url), 'utf8')
	assert.match(module, /tasks: \(\) => import\('\.\/NewTasks\.vue'\)/)
	const theme = readFileSync(new URL('./tasks-theme.css', import.meta.url), 'utf8')
	assert.match(theme, /\.pc-new \.pc-tasks-theme\.deck-board \.reporting-dashboard \{ display: none; \}/)
})
