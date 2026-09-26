import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'

// NewIntake and NewActivity extend the components the current interface uses,
// so the tests load both scripts and merge them the way Vue's `extends` does.
function load(file, sandbox) {
	const source = readFileSync(new URL(file, import.meta.url), 'utf8')
	const script = source.match(/<script>([\s\S]*?)<\/script>/)[1]
		.replace(/^import .*$/gm, '')
		.replace(/^\tcomponents: \{[\s\S]*?^\t\},$/m, '')
		.replace('export default', 'globalThis.component =')
	const context = { console: { error() {} }, ...sandbox }
	vm.runInNewContext(script, context)
	return context.component
}
function build(base, child, overrides = {}) {
	const props = { ...base.props, ...child.props }
	const instance = {
		...Object.fromEntries(Object.entries(props).map(([key, spec]) => [key, spec.default])),
		...(base.data ? base.data() : {}),
		...(child.data ? child.data() : {}),
		...overrides,
	}
	for (const [key, method] of Object.entries({ ...base.methods, ...child.methods })) instance[key] = method.bind(instance)
	for (const [key, getter] of Object.entries({ ...base.computed, ...child.computed })) Object.defineProperty(instance, key, { get: getter.bind(instance) })
	return instance
}
const deferred = () => { let resolve; const promise = new Promise(_resolve => { resolve = _resolve }); return { promise, resolve } }

const intakeBase = load('../components/ProjectCardVisibilityTab.vue', { ProjectsService: { getInstance: () => ({}) } })
const intake = overrides => build(intakeBase, load('./NewIntake.vue', { ProjectCardVisibilityTab: intakeBase }), { projectId: 21, ...overrides })
const questions = ['cv_object_ownership', 'cv_trace_ownership', 'cv_building_type', 'cv_avp_location'].map(field => ({ field, question: field, options: [{ value: '0', label: 'A' }, { value: '1', label: 'B' }] }))

test('intake counts answered questions from the answers the form already holds', () => {
	const view = intake({ questions })
	view.answers = { cv_object_ownership: 0, cv_trace_ownership: 1, cv_building_type: null, cv_avp_location: null }
	assert.equal(view.answeredCount, 2)
	assert.equal(view.answeredPercent, 50)
	view.answers = { ...view.answers, cv_building_type: 0 }
	assert.equal(view.answeredCount, 3, 'answer 0 is an answer, not an empty value')
	assert.equal(intake({ questions: [] }).answeredPercent, 0)
})

test('intake counts one unsaved change per edited question', () => {
	const view = intake({ questions, canEdit: true })
	const saved = { cv_object_ownership: 0, cv_trace_ownership: null, cv_building_type: 1, cv_avp_location: null }
	view.answers = { ...saved }
	view.initialAnswers = { ...saved }
	assert.equal(view.dirtyCount, 0)
	view.setAnswer('cv_trace_ownership', '1')
	view.setAnswer('cv_building_type', '0')
	assert.equal(view.dirtyCount, 2)
	view.setAnswer('cv_building_type', '1')
	assert.equal(view.dirtyCount, 1, 'answering back to the saved value is no longer a change')
})

test('a read-only intake cannot change answers', () => {
	const view = intake({ questions, canEdit: false })
	view.answers = { cv_object_ownership: 0, cv_trace_ownership: null, cv_building_type: null, cv_avp_location: null }
	view.setAnswer('cv_object_ownership', '1')
	assert.equal(view.answers.cv_object_ownership, 0)
})

function activity(reader, overrides = {}) {
	const base = load('../components/ProjectActivity/ProjectActivity.vue', {
		ProjectsService: { getInstance: () => ({ getActivity: async () => { throw new Error('the swallowing service must not be used') } }) },
		DEFAULT_NOTE_TYPE: '',
		NOTE_TYPES: [],
		noteTypeLabel: value => value,
	})
	const child = load('./NewActivity.vue', {
		ProjectActivity: base,
		api: { activity: reader },
		errorMessage: error => error?.response?.status === 403 ? 'You do not have access to this data.' : 'The data could not be loaded. Please try again.',
	})
	return build(base, child, { projectId: 21, ...overrides })
}

test('activity reports a failed read instead of showing an empty project', async () => {
	const view = activity(async () => { const error = new Error('Forbidden'); error.response = { status: 403 }; throw error })
	await view.fetchEvents()
	assert.equal(view.events.length, 0)
	assert.equal(view.error, 'You do not have access to this data.')
	assert.equal(view.loading, false)
})

test('activity ignores a slower answer for a filter the user already left', async () => {
	const slow = deferred()
	let call = 0
	const view = activity(() => ++call === 1 ? slow.promise : Promise.resolve({ events: [{ id: 'deck-1' }], hasMore: false }))
	const first = view.fetchEvents()
	view.selectedSource = 'deck'
	await view.fetchEvents()
	slow.resolve({ events: [{ id: 'stale' }], hasMore: true })
	await first
	assert.equal(view.events.map(e => e.id).join(','), 'deck-1')
	assert.equal(view.hasMore, false)
})

test('activity pages by cursor when the server gives one, and by offset otherwise', async () => {
	const calls = []
	const pages = [{ events: [{ id: 1 }, { id: 2 }], hasMore: true, nextCursor: 'c-2' }, { events: [{ id: 3 }], hasMore: true, nextCursor: null }, { events: [{ id: 4 }], hasMore: false }]
	const view = activity(async (id, params) => { calls.push({ id, ...params }); return pages[calls.length - 1] })
	view.selectedSource = 'files'
	await view.fetchEvents()
	await view.loadMore()
	await view.loadMore()
	assert.equal(calls[0].source, 'files')
	assert.equal(calls[1].cursor, 'c-2')
	assert.equal(calls[2].offset, 3)
	assert.equal(calls[2].cursor, undefined)
	assert.equal(view.events.length, 4)
	assert.equal(view.hasMore, false)
})

test('a failed later page keeps what is already on screen', async () => {
	let call = 0
	const view = activity(async () => { if (++call > 1) throw new Error('Gone'); return { events: [{ id: 1 }], hasMore: true } })
	await view.fetchEvents()
	await view.loadMore()
	assert.equal(view.events.length, 1)
	assert.ok(view.error)
	assert.equal(view.hasMore, true, 'Load more stays available to retry')
})

test('choosing a source refetches once, and the time reads as a clock', () => {
	let fetches = 0
	const view = activity(async () => ({ events: [], hasMore: false }))
	view.fetchEvents = () => { fetches++ }
	view.selectSource('talk')
	view.selectSource('talk')
	assert.equal(fetches, 1)
	assert.equal(view.clockTime(new Date(2026, 8, 25, 9, 42).toISOString()), '09:42')
	assert.equal(view.clockTime('not a date'), '')
})

test('intake shows the category once, with the shared instruction as a hint', () => {
	const view = intake()
	const real = view.questionParts({ category: 'Eigendoms situatie te realiseren object', question: 'Eigendoms situatie te realiseren object (antwoord met ja op de situatie die van toepassing is).' })
	assert.equal(real.title, 'Eigendoms situatie te realiseren object')
	assert.equal(real.hint, 'Antwoord met ja op de situatie die van toepassing is.')
	assert.equal(real.label, '')
	const caseDiffers = view.questionParts({ category: 'AVP Locatie', question: 'AVP locatie (antwoord met ja op de situatie die van toepassing is).' })
	assert.equal(caseDiffers.title, 'AVP Locatie')
	const bare = view.questionParts({ category: 'Scope', question: 'Scope' })
	assert.equal(bare.hint, '')
	const other = view.questionParts({ category: 'Object', question: 'Who owns the object?' })
	assert.equal(other.label, 'Object')
	assert.equal(other.title, 'Who owns the object?')
})
