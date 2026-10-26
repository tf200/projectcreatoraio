import test from 'node:test'
import assert from 'node:assert/strict'
import { plannedDateBounds, FIRST_DAY, LAST_DAY } from './timelineDates.js'

test('handover, actual start and end can only be picked in that order', () => {
	const bounds = plannedDateBounds({ actualHandoverDate: '2026-10-01', actualStartDate: '2026-10-05T00:00:00', actualEndDate: '2026-11-30' })
	assert.deepEqual(bounds.handover, { min: FIRST_DAY, max: '2026-10-05' })
	assert.deepEqual(bounds.actualStart, { min: '2026-10-01', max: '2026-11-30' })
	assert.deepEqual(bounds.end, { min: '2026-10-05', max: LAST_DAY })
	assert.equal(bounds.canClearStart, false)
})

test('the end waits for an actual start', () => {
	const empty = plannedDateBounds({})
	assert.equal(empty.canSetEnd, false)
	assert.equal(empty.canClearStart, true)
	assert.deepEqual(empty.handover, { min: FIRST_DAY, max: LAST_DAY })

	const handedOver = plannedDateBounds({ actualHandoverDate: '2026-10-01' })
	assert.equal(handedOver.canSetEnd, false)
	assert.equal(handedOver.actualStart.min, '2026-10-01')
	assert.equal(plannedDateBounds({ actualStartDate: '2026-10-05' }).canSetEnd, true)
})
