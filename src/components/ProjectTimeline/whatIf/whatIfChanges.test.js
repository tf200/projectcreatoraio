import test from 'node:test'
import assert from 'node:assert/strict'
import { addChange, changeForResize, changesForMove, daysBetween, describeChange, formatDays, formatShiftBadge, shiftDate } from './whatIfChanges.js'

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

test('dates shift by whole days across daylight saving changes', () => {
	assert.equal(shiftDate('2026-03-28', 2), '2026-03-30')
	assert.equal(shiftDate('2026-10-24', 2), '2026-10-26')
	assert.equal(shiftDate('2026-01-01', -1), '2025-12-31')
	assert.equal(daysBetween('2026-03-28', '2026-03-30'), 2)
	assert.equal(daysBetween('2026-03-30', '2026-03-28'), -2)
})

const card = { id: 3, label: 'Preparation', startDate: '2026-02-01', durationDays: 10, startNotBefore: null }
const afterPermits = [{ predecessorId: 1, predecessorEndDate: '2026-01-31', overlapDays: 0 }]

test('dragging a card later sets the day it can start', () => {
	assert.deepEqual(changesForMove(card, 5, afterPermits).changes, [{ type: 'startNotBefore', taskId: 3, date: '2026-02-06' }])
	assert.deepEqual(changesForMove(card, 0, afterPermits), { changes: [], reason: '' })
})

test('dragging a card earlier overlaps it with the card before it', () => {
	assert.deepEqual(changesForMove(card, -4, afterPermits).changes, [{ type: 'overlap', predecessorId: 1, successorId: 3, days: 4 }])
	// Already overlapping 6 days: dragging 4 days earlier asks for 10
	const overlapping = { ...card, startDate: '2026-01-26' }
	assert.deepEqual(changesForMove(overlapping, -4, [{ ...afterPermits[0], overlapDays: 6 }]).changes, [{ type: 'overlap', predecessorId: 1, successorId: 3, days: 10 }])
})

test('dragging earlier lowers a start limit that holds the card back', () => {
	const limited = { ...card, startDate: '2026-02-20', startNotBefore: '2026-02-20' }
	assert.deepEqual(changesForMove(limited, -10, afterPermits).changes, [{ type: 'startNotBefore', taskId: 3, date: '2026-02-10' }])
	assert.deepEqual(changesForMove(limited, -25, afterPermits).changes, [
		{ type: 'startNotBefore', taskId: 3, date: '2026-01-26' },
		{ type: 'overlap', predecessorId: 1, successorId: 3, days: 6 },
	])
})

test('a card that waits for nothing cannot be dragged earlier', () => {
	const result = changesForMove({ ...card, label: 'Intake' }, -3, [])
	assert.deepEqual(result.changes, [])
	assert.match(result.reason, /doesn’t wait for another card/)
})

test('dragging the end changes the length, never below one day', () => {
	assert.deepEqual(changeForResize(card, 4), { type: 'duration', taskId: 3, days: 14 })
	assert.deepEqual(changeForResize(card, -30), { type: 'duration', taskId: 3, days: 1 })
	assert.equal(changeForResize(card, 0), null)
})
