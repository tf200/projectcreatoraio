import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'
import * as wb from './whiteboard-activity.js'

// Saves every ten seconds from `from` for `count` saves, as the autosave does.
let id = 0
function saves(actorUid, displayName, from, count, size = 1000, step = 10000) {
	const start = new Date(from).getTime()
	return Array.from({ length: count }, (_, i) => ({ id: ++id, actorUid, actorDisplayName: displayName, occurredAt: new Date(start + i * step).toISOString(), payload: { fileSize: size + i * 10 } }))
}

test('autosaves by one person become one session, split where they paused', () => {
	const events = [...saves('emma', 'Emma de Vries', '2026-09-28T14:02:00', 30), ...saves('emma', 'Emma de Vries', '2026-09-28T09:00:00', 5)]
	const sessions = wb.sessionsOf(events)
	assert.equal(sessions.length, 2)
	assert.equal(sessions[0].saves, 30, 'newest first')
	assert.equal(sessions[1].saves, 5)
	assert.equal(sessions[0].lanes.length, 1)
	assert.equal(wb.minutesOf(sessions[0]), 5)
})

test('a pause of up to twenty minutes stays in the session; longer starts a new one', () => {
	const at = t => ({ id: ++id, actorUid: 'emma', actorDisplayName: 'Emma', occurredAt: t, payload: {} })
	assert.equal(wb.sessionsOf([at('2026-09-28T10:00:00'), at('2026-09-28T10:20:00')]).length, 1)
	assert.equal(wb.sessionsOf([at('2026-09-28T10:00:00'), at('2026-09-28T10:20:01')]).length, 2)
})

test('overlapping sittings by different people are one session, with a lane each', () => {
	const events = [...saves('emma', 'Emma de Vries', '2026-09-27T16:10:00', 40, 5000, 60000), ...saves('thomas', 'Thomas Jansen', '2026-09-27T16:14:00', 20, 5000, 60000), ...saves('lotte', 'Lotte Bakker', '2026-09-27T18:30:00', 3)]
	const sessions = wb.sessionsOf(events)
	assert.equal(sessions.length, 2, 'Lotte came later, on her own')
	const together = sessions[1]
	assert.equal(together.lanes.map(l => l.actorUid).join(), 'emma,thomas')
	assert.equal(together.saves, 60)
	assert.equal(together.lanes[1].saves, 20)
	assert.equal(wb.names(together.lanes), 'Emma de Vries and Thomas Jansen')
	assert.equal(wb.names([{ displayName: 'A' }, { displayName: 'B' }, { displayName: 'C' }]), 'A, B and C')
})

test('the size change is measured from the board before the session', () => {
	const first = saves('emma', 'Emma', '2026-09-27T10:00:00', 3, 4000)   // ends at 4020
	const second = saves('thomas', 'Thomas', '2026-09-28T10:00:00', 2, 3000) // ends at 3010
	const [newest, oldest] = wb.sessionsOf([...first, ...second])
	assert.equal(oldest.sizeChange, 20, 'the first session known compares its own first save')
	assert.equal(newest.sizeChange, 3010 - 4020)
	assert.equal(wb.sizeChange(newest.sizeChange), 'Board shrank by 1010 bytes')
	assert.equal(wb.sizeChange(14 * 1024), 'Board grew by 14 KB')
	assert.equal(wb.sizeChange(0), 'Board size unchanged')
	assert.equal(wb.sizeChange(null), '')
	const unknown = wb.sessionsOf([{ id: 1, actorUid: 'a', occurredAt: '2026-09-28T10:00:00Z', payload: {} }])
	assert.equal(unknown[0].sizeChange, null)
})

test('sessions are grouped by day with totals and the people who worked', () => {
	const now = new Date('2026-09-28T15:00:00').getTime()
	const events = [
		...saves('emma', 'Emma', '2026-09-28T14:02:00', 10),
		...saves('thomas', 'Thomas', '2026-09-28T09:15:00', 10),
		...saves('emma', 'Emma', '2026-09-27T16:10:00', 10),
		...saves('lotte', 'Lotte', '2026-09-25T11:20:00', 10),
	]
	const days = wb.daysOf(wb.sessionsOf(events), now)
	assert.equal(days.map(d => d.label).join('|'), 'Today|Yesterday|Friday 25 September')
	assert.equal(days[0].sessions.length, 2)
	assert.equal(days[0].people.map(p => p.actorUid).join(), 'emma,thomas')
	assert.equal(wb.dayLabel(new Date('2025-12-31T10:00:00').getTime(), now), 'Wednesday, 31 December 2025')
})

test('durations and ranges read as people say them', () => {
	assert.equal(wb.duration(0), 'under a minute')
	assert.equal(wb.duration(39), '39 min')
	assert.equal(wb.duration(60), '1 h')
	assert.equal(wb.duration(65), '1 h 5 min')
	const at = new Date('2026-09-28T14:02:00').getTime()
	assert.equal(wb.range({ start: at, end: at }), '14:02')
	assert.equal(wb.range({ start: at, end: at + 39 * 60000 }), '14:02 – 14:41')
})

function view(reader) {
	const source = readFileSync(new URL('./NewWhiteboardActivity.vue', import.meta.url), 'utf8')
	const script = source.match(/<script>([\s\S]*?)<\/script>/)[1]
		.replace(/^import .*$/gm, '')
		.replace(/^\tcomponents: \{[^\n]*\},$/m, '')
		.replace('export default', 'globalThis.component =')
	const context = { ...wb, api: { whiteboardActivity: reader }, errorMessage: e => e?.response?.status === 403 ? 'You do not have access to this data.' : 'The data could not be loaded. Please try again.', Object, Array, Error, String }
	vm.runInNewContext(script, context)
	const options = context.component
	const instance = { ...options.data(), projectId: 21, reader: null }
	for (const [key, method] of Object.entries(options.methods)) instance[key] = method.bind(instance)
	for (const [key, getter] of Object.entries(options.computed)) Object.defineProperty(instance, key, { get: getter.bind(instance) })
	instance.$set = (target, key, value) => { target[key] = value }
	return instance
}

test('the view reads 300 saves in pages of 100 and holds back a session that may go on', async () => {
	const all = [...saves('emma', 'Emma', '2026-09-28T14:00:00', 150), ...saves('thomas', 'Thomas', '2026-09-20T09:00:00', 200)].reverse()
	const calls = []
	const v = view(async (projectId, limit, offset) => { calls.push([projectId, limit, offset]); const page = all.slice(offset, offset + limit); return { events: page, hasMore: offset + limit < all.length } })
	await v.load()
	assert.equal(calls.map(c => c[2]).join(), '0,100,200')
	assert.equal(calls[0][1], 100)
	assert.equal(v.events.length, 300)
	assert.equal(v.hasMore, true)
	assert.equal(v.sessions.length, 1, "Thomas's session is only partly loaded, so it waits")
	await v.loadOlder()
	assert.equal(v.events.length, 350)
	assert.equal(v.hasMore, false)
	assert.equal(v.sessions.length, 2)
	assert.equal(v.sessions[1].saves, 200, 'and then shows in full')
})

test('a failed read is a failure, not an empty board; a failed older read keeps the list', async () => {
	const denied = view(async () => { const e = new Error('no'); e.response = { status: 403 }; throw e })
	await denied.load()
	assert.equal(denied.error, 'You do not have access to this data.')
	assert.equal(denied.events.length, 0)

	let fail = false
	const v = view(async (id, limit, offset) => {
		if (fail) throw new Error('down')
		return { events: saves('emma', 'Emma', new Date(Date.UTC(2026, 8, 20) - offset * 86400000).toISOString(), limit), hasMore: true }
	})
	await v.load()
	assert.equal(v.hasMore, true)
	fail = true
	await v.loadOlder()
	assert.equal(v.events.length, 300, 'what was loaded stays')
	assert.equal(v.loading, false)
	assert.match(v.error, /Older activity could not be loaded/)
})

test('the newest day, today and yesterday start open; expand and collapse all', async () => {
	const now = Date.now()
	const iso = ms => new Date(ms).toISOString()
	const v = view(async () => ({ events: [
		...saves('emma', 'Emma', iso(now - 60000), 1),
		...saves('emma', 'Emma', iso(now - 5 * 86400000), 1),
	], hasMore: false }))
	await v.load()
	assert.equal(v.days.length, 2)
	assert.equal(v.isDayOpen(v.days[0]), true)
	assert.equal(v.isDayOpen(v.days[1]), false)
	v.toggleDay(v.days[1])
	assert.equal(v.isDayOpen(v.days[1]), true)
	v.collapseAll()
	assert.equal(v.days.every(d => !v.isDayOpen(d)), true)
	v.expandAll()
	assert.equal(v.days.every(d => v.isDayOpen(d)), true)
	assert.equal(v.sessions.every(s => v.isSessionOpen(s)), true)
})
