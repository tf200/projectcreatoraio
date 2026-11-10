import test from 'node:test'
import assert from 'node:assert/strict'
import { dateRefusal, plannedDateBounds, FIRST_DAY, LAST_DAY } from './timelineDates.js'

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

test('nothing is picked before the request date', () => {
	const bounds = plannedDateBounds({ requestDate: '2026-10-05' })
	assert.equal(bounds.desiredStart.min, '2026-10-05')
	assert.equal(bounds.handover.min, '2026-10-05')
	assert.equal(bounds.actualStart.min, '2026-10-05')
	assert.equal(plannedDateBounds({ requestDate: '2026-10-05', actualHandoverDate: '2026-10-12' }).actualStart.min, '2026-10-12')
})

test('the desired start is a plan, so only the request date holds it', () => {
	const kpis = { requestDate: '2026-10-05', actualHandoverDate: '2026-11-02', minimumStartDate: '2026-12-07' }
	assert.deepEqual(plannedDateBounds(kpis).desiredStart, { min: '2026-10-05', max: LAST_DAY })
	assert.equal(dateRefusal(kpis, 'desiredStart', '2026-10-19'), '')
	assert.equal(dateRefusal(kpis, 'desiredStart', '2026-09-28'), 'The desired start (28 Sept 2026) can’t be before the request date (5 Oct 2026).')
})

test('a picked day is refused the way the server words it', () => {
	const kpis = { requestDate: '2026-10-05', actualHandoverDate: '2026-10-12', actualStartDate: '2026-10-19', actualEndDate: '2026-11-30' }
	assert.equal(dateRefusal(kpis, 'handover', '2026-10-19'), '')
	assert.equal(dateRefusal(kpis, 'handover', '2026-10-26'), 'The handover (26 Oct 2026) can’t be after the actual start (19 Oct 2026).')
	assert.equal(dateRefusal(kpis, 'actualStart', '2026-10-08'), 'The actual start (8 Oct 2026) can’t be before the handover (12 Oct 2026).')
	assert.equal(dateRefusal(kpis, 'end', '2026-10-15'), 'The end date (15 Oct 2026) can’t be before the actual start (19 Oct 2026).')
	assert.equal(dateRefusal({ requestDate: '2026-10-05' }, 'end', '2026-10-15'), 'Set the actual start before the end date.')
	assert.equal(dateRefusal(kpis, 'handover', '2226-10-15'), 'The handover must fall between 2000 and 2100.')
})
