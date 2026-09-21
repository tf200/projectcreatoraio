import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'

// The whiteboard components are shared with the current interface, so every
// modern behaviour is a prop the legacy callers never pass.
function load(file, sandbox) {
	const source = readFileSync(new URL(file, import.meta.url), 'utf8')
	const script = source.match(/<script>([\s\S]*?)<\/script>/)[1]
		.replace(/^import .*$/gm, '')
		// The registry names the stripped imports; only options are under test.
		.replace(/^\tcomponents: \{[\s\S]*?^\t\},$/m, '')
		.replace('export default', 'globalThis.component =')
	const context = { console: { error() {} }, ...sandbox }
	vm.runInNewContext(script, context)
	return context.component
}
function build(options, overrides) {
	const instance = { ...options.props ? defaults(options.props) : {}, ...options.data(), ...overrides }
	for (const [key, method] of Object.entries(options.methods)) instance[key] = method.bind(instance)
	for (const [key, getter] of Object.entries(options.computed || {})) Object.defineProperty(instance, key, { get: getter.bind(instance) })
	return instance
}
function defaults(props) {
	return Object.fromEntries(Object.entries(props).map(([key, spec]) => [key, typeof spec.default === 'function' && spec.type !== Function ? spec.default() : spec.default]))
}
const activity = (service, overrides) => build(
	load('../components/ProjectWhiteboard/WhiteboardActivity.vue', { ProjectsService: { getInstance: () => service } }),
	{ projectId: 21, limit: 20, ...overrides },
)

test('without a reader the legacy empty-list behaviour is untouched', async () => {
	const instance = activity({ getWhiteboardActivity: async () => ({ events: [], hasMore: false }) })
	await instance.fetchEvents()
	assert.equal(instance.events.length, 0)
	assert.equal(instance.error, '', 'legacy callers must not start seeing error text')
})

test('a reader that throws is reported instead of looking like an empty board', async () => {
	const instance = activity({ getWhiteboardActivity: async () => ({ events: [], hasMore: false }) }, {
		reader: async () => { throw new Error('Forbidden') },
	})
	await instance.fetchEvents()
	assert.ok(instance.error)
	assert.equal(instance.events.length, 0)
	assert.equal(instance.hasMore, false)
})

test('a reader is preferred over the swallowing service and paginates by offset', async () => {
	const calls = []
	const instance = activity({ getWhiteboardActivity: async () => { throw new Error('service must not be used') } }, {
		reader: async (id, limit, offset) => {
			calls.push({ id, limit, offset })
			return { events: [{ id: offset + 1 }], hasMore: offset === 0 }
		},
	})
	await instance.fetchEvents()
	await instance.loadMore()
	assert.deepEqual(calls, [{ id: '21', limit: 20, offset: 0 }, { id: '21', limit: 20, offset: 1 }])
	assert.equal(instance.events.length, 2)
	assert.equal(instance.hasMore, false)
})

test('a failed second page keeps the events already on screen', async () => {
	let first = true
	const instance = activity({}, {
		reader: async () => {
			if (!first) throw new Error('Gone')
			first = false
			return { events: [{ id: 1 }], hasMore: true }
		},
	})
	await instance.fetchEvents()
	await instance.loadMore()
	assert.equal(instance.events.length, 1, 'the list must survive so the error stays inline')
	assert.ok(instance.error)
})

const boardOptions = (win = {}) => load('../components/ProjectWhiteboard/WhiteboardBoard.vue', {
	ProjectsService: { getInstance: () => ({}) },
	generateUrl: value => value,
	generateRemoteUrl: value => value,
	window: win,
})
const board = overrides => build(boardOptions(), { projectId: 21, userId: 'emma', ...overrides })

test('the primary action still opens a window unless overlay mode is requested', () => {
	let opened = null
	const options = boardOptions({ open: url => { opened = url; return {} } })
	assert.equal(options.props.openMode.default, 'popout')
	assert.equal(options.props.inlineEditing.default, false)
	assert.equal(options.props.activityReader.default, null)

	const legacy = build(options, { projectId: 21, userId: 'emma' })
	legacy.openOverlay = () => { opened = 'overlay' }
	legacy.openPopout()
	assert.match(opened, /popout=whiteboard/)

	const modern = build(options, { projectId: 21, userId: 'emma', openMode: 'overlay' })
	modern.openOverlay = () => { opened = 'overlay' }
	modern.openPopout()
	assert.equal(opened, 'overlay')
})

test('the inline board stands down while the overlay holds the same file', () => {
	const legacy = board({ overlayOpen: true })
	assert.equal(legacy.inlineSuspended, false, 'the popout window keeps rendering its preview')

	const modern = board({ inlineEditing: true })
	assert.equal(modern.inlineSuspended, false)
	modern.overlayOpen = true
	assert.equal(modern.inlineSuspended, true)
})

test('autosave only follows an interaction the modern tab opted into', () => {
	let started = 0
	const legacy = board()
	legacy.startAutosave = () => { started++ }
	legacy.onInlineInteraction()
	assert.equal(started, 0)

	const modern = board({ inlineEditing: true })
	modern.startAutosave = () => { started++ }
	modern.onInlineInteraction()
	assert.equal(started, 1)
	modern.overlayOpen = true
	modern.onInlineInteraction()
	assert.equal(started, 1, 'the overlay runs its own autosave')
})

test('only the modern tab tells the whiteboard app it is embedded', () => {
	const source = readFileSync(new URL('../components/ProjectWhiteboard/WhiteboardBoard.vue', import.meta.url), 'utf8')
	// isEmbedded makes the whiteboard app render ReadOnlyViewer instead of App,
	// which is what removed the palette and the menu.
	assert.match(source, /:is-embedded="!inlineEditing"/)
})
