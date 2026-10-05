import test from 'node:test'
import assert from 'node:assert/strict'
import { addChange, describeChange, formatDays, formatShiftBadge } from './whatIfChanges.js'

const labels = { 1: 'Permits', 2: 'Preparation' }
const describe = change => describeChange(change, id => labels[id], date => date.split('-').reverse().join('/'))

test('days read as weeks when they are whole weeks', () => {
	assert.equal(formatDays(1), '1 day')
	assert.equal(formatDays(-3), '3 days')
	assert.equal(formatDays(7), '1 week')
	assert.equal(formatDays(21), '3 weeks')
	assert.equal(formatDays(10), '10 days')
	assert.equal(formatShiftBadge(14), '+2w')
	assert.equal(formatShiftBadge(-3), '−3d')
	assert.equal(formatShiftBadge(0), '')
})

test('repeated delays of the same card add up and cancel out', () => {
	let changes = addChange([], { type: 'delay', taskId: 1, days: 7 })
	changes = addChange(changes, { type: 'delay', taskId: 1, days: 7 })
	assert.deepEqual(changes, [{ type: 'delay', taskId: 1, days: 14 }])

	changes = addChange(changes, { type: 'delay', taskId: 1, days: -14 })
	assert.deepEqual(changes, [])
})

test('a suggested fix stays next to the delay it offsets', () => {
	const changes = addChange([{ type: 'delay', taskId: 1, days: 14 }], { type: 'delay', taskId: 1, days: -14 }, { mergeDelays: false })
	assert.equal(changes.length, 2)
})

test('delays only merge with the change right before them', () => {
	let changes = addChange([], { type: 'delay', taskId: 1, days: 7 })
	changes = addChange(changes, { type: 'duration', taskId: 2, days: 5 })
	changes = addChange(changes, { type: 'delay', taskId: 1, days: 7 })
	assert.equal(changes.length, 3)
})

test('a new length, start limit or overlap replaces the previous one', () => {
	let changes = addChange([], { type: 'duration', taskId: 1, days: 20 })
	changes = addChange(changes, { type: 'endDate', taskId: 1, date: '2026-02-01' })
	changes = addChange(changes, { type: 'startNotBefore', taskId: 2, date: '2026-03-01' })
	changes = addChange(changes, { type: 'startNotBefore', taskId: 2, date: '2026-04-01' })
	changes = addChange(changes, { type: 'overlap', predecessorId: 1, successorId: 2, days: 5 })
	changes = addChange(changes, { type: 'overlap', predecessorId: 1, successorId: 2, days: 9 })
	assert.deepEqual(changes, [
		{ type: 'endDate', taskId: 1, date: '2026-02-01' },
		{ type: 'startNotBefore', taskId: 2, date: '2026-04-01' },
		{ type: 'overlap', predecessorId: 1, successorId: 2, days: 9 },
	])
})

test('planning changes merge into one', () => {
	let changes = addChange([{ type: 'delay', taskId: 1, days: 7 }], { type: 'planning', desiredStartDate: '2026-05-01' })
	changes = addChange(changes, { type: 'planning', requiredPreparationWeeks: 3 })
	assert.deepEqual(changes[1], { type: 'planning', desiredStartDate: '2026-05-01', requiredPreparationWeeks: 3 })
})

test('changes are described in plain words', () => {
	assert.equal(describe({ type: 'delay', taskId: 1, days: 14 }), 'Delay “Permits” by 2 weeks')
	assert.equal(describe({ type: 'delay', taskId: 1, days: -3 }), 'Finish “Permits” 3 days earlier')
	assert.equal(describe({ type: 'duration', taskId: 2, days: 10 }), '“Preparation” takes 10 days')
	assert.equal(describe({ type: 'endDate', taskId: 2, date: '2026-02-01' }), '“Preparation” ends on 01/02/2026')
	assert.equal(describe({ type: 'startNotBefore', taskId: 2, date: null }), '“Preparation” has no start limit')
	assert.equal(describe({ type: 'overlap', predecessorId: 1, successorId: 2, days: 5 }), '“Preparation” starts 5 days before “Permits” ends')
	assert.equal(describe({ type: 'overlap', predecessorId: 1, successorId: 2, days: 0 }), '“Preparation” waits for “Permits” to finish')
	assert.equal(describe({ type: 'planning', desiredStartDate: '2026-05-01', requiredPreparationWeeks: 2 }), 'Desired start on 01/05/2026 · Preparation takes 2 weeks')
})
