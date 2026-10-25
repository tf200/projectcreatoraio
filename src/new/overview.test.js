import test from 'node:test'
import assert from 'node:assert/strict'
import { progressSummary, planningSummary, timelineOf, assignedTasks, openTasks, recentFiles, activityText } from './overview.js'
test('checklist completion preserves unavailable and missing-card states', () => {
 assert.equal(progressSummary(null).percent, null)
 assert.equal(progressSummary({ processCompleted: { status: 'error', doneCount: 1, totalRequired: 2 } }).percent, null)
 assert.equal(progressSummary({ processCompleted: { status: 'missing_cards', doneCount: 1, totalRequired: 4, missingTitles: ['A'] } }).percent, 25)
})
test('planning does not claim no conflict without a desired date and chooses pending milestones', () => {
 assert.equal(planningSummary({ planningStatus: 'on_track' }).conflict, null)
 const p = planningSummary({ desiredStartDate: '2026-10-01', overallFloatDays: -3, phases: [
 { tasks: [{ isDone: true }], milestone: { date: '2026-09-01', label: 'Done' } },
 { tasks: [{ isDone: false }], milestone: { date: '2026-09-05', label: 'Overdue' } },
 { tasks: [{ isDone: false }], milestone: { date: '2026-10-05', label: 'Later' } },
 ] })
 assert.equal(p.conflict, true); assert.equal(p.milestone.label, 'Overdue')
})
test('my tasks means direct assignment, excludes finished archived deleted cards and group IDs', () => {
 const assignment = [{ type: 0, participant: { uid: 'alice' } }]
 const cards = [{ id: 1, assignedUsers: assignment }, { id: 2, owner: 'alice' }, { id: 3, done: '2026-01-01', assignedUsers: assignment }, { id: 4, assignedUsers: [{ type: 1, participant: 'alice' }] }, { id: 5, archived: true, assignedUsers: assignment }]
 assert.deepEqual(assignedTasks([{ title: 'To do', cards }], 'alice').map(c => c.id), [1])
})
test('recent files flatten authorized roots, exclude generated notes/whiteboards and sort by mtime', () => {
 assert.deepEqual(recentFiles({ shared: [{ type: 'folder', name: 'Public Notes', children: [{ type: 'file', id: 1, name: 'note.md', mtime: 50 }] }, { type: 'file', id: 2, name: 'a.pdf', mtime: 2 }, { type: 'file', id: 3, name: 'board.whiteboard', mtime: 99 }], private: [{ type: 'file', id: 4, name: 'b.pdf', mtime: 4 }] }).map(f => f.id), [4, 2])
})
test('redacted activities never expose payload titles', () => {
 assert.equal(activityText({ eventType: 'note_updated', payload: { redacted: true, title: 'secret' } }).includes('secret'), false)
})
test('open tasks come oldest first, wherever they are on the board', () => {
 const stacks = [
  { title: 'Done', cards: [{ id: 30, createdAt: 1700000300 }] },
  { title: 'To do', cards: [{ id: 12, createdAt: 1700000900 }, { id: 11, createdAt: 1700000100 }, { id: 14, createdAt: 1700000100 }] },
 ]
 assert.deepEqual(openTasks(stacks).map(c => c.id), [11, 14, 30, 12], 'by creation, then by id')
 assert.deepEqual(assignedTasks([{ title: 'To do', cards: [{ id: 2, createdAt: 9, assignedUsers: [{ type: 0, participant: 'a' }] }, { id: 1, createdAt: 5, assignedUsers: [{ type: 0, participant: 'a' }] }] }], 'a').map(c => c.id), [1, 2], 'my tasks too')
})
test('all tasks is every open card on the board, and my tasks is always a subset of it', () => {
 const mine = [{ type: 0, participant: { uid: 'alice' } }]
 const stacks = [
  { title: 'To do', cards: [{ id: 1, assignedUsers: mine }, { id: 2 }, { id: 3, done: '2026-01-01' }] },
  { title: 'Review', cards: [{ id: 4, archived: true }, { id: 5, deletedAt: 1 }, { id: 6, assignedUsers: [{ type: 0, participant: 'bob' }] }] },
  { title: 'Gone', deletedAt: 1, cards: [{ id: 7 }] },
 ]
 assert.deepEqual(openTasks(stacks).map(c => c.id), [1, 2, 6])
 assert.deepEqual(openTasks(stacks).map(c => c.stackTitle), ['To do', 'To do', 'Review'])
 const all = new Set(openTasks(stacks).map(c => c.id))
 assert.ok(assignedTasks(stacks, 'alice').every(c => all.has(c.id)))
 assert.deepEqual(openTasks([]), [])
})

// The summary of the local PROJECT_TEST01 on 12 Oct 2026, trimmed to what the panel reads.
const fresh = { requestDate: '2026-08-24', requiredPreparationWeeks: 0, processCompleted: { status: 'incomplete', date: null, doneCount: 0, totalRequired: 10, missingTitles: [] }, coordinationPendingPeriod: { weeks: 7 }, minimumStartDate: '2028-05-29', desiredStartDate: null, actualStartDate: null, overallFloatWeeks: null, overallFloatDays: null, planningStatus: 'on_track', actualHandoverDate: null, actualEndDate: null, executionWeeks: null, plannedEndDate: null, handoverWarnings: [] }
test('a fresh project sits in Initiation, on track without a target', () => {
 const t = timelineOf(fresh)
 assert.deepEqual(t.status, { tone: 'success', label: 'On track (no target)', text: 'No desired start yet, so there is no float to check.' })
 assert.equal(t.stage, 0)
 assert.deepEqual(t.initiation, { requestDate: '2026-08-24', done: 0, total: 10, configured: true, percent: 0, note: '7 weeks since the request', warn: false, handover: null, handoverNote: 'After the process steps', handoverWarn: false })
 assert.equal(t.execution.note, 'Right after the process steps')
 assert.deepEqual(t.delivery, { end: null, planned: false, note: 'Set the actual start in Planning to plan it', weeks: null })
})
test('the status and float are the server’s, in words', () => {
 assert.deepEqual(timelineOf({ ...fresh, desiredStartDate: '2026-10-01', overallFloatDays: -4, overallFloatWeeks: -0.6, planningStatus: 'behind_at_risk' }).status, { tone: 'danger', label: 'Behind / at risk', text: 'Desired start is 4 days before the earliest possible start · float -0.6 weeks.' })
 assert.equal(timelineOf({ ...fresh, desiredStartDate: '2026-10-05', overallFloatDays: 0, overallFloatWeeks: 0, planningStatus: 'attention_needed' }).status.label, 'Attention needed')
 assert.equal(timelineOf({ ...fresh, desiredStartDate: '2026-11-02', overallFloatDays: 28, overallFloatWeeks: 4, planningStatus: 'on_track' }).status.text, 'Float of +4 weeks before the desired start.')
 assert.equal(timelineOf({ ...fresh, desiredStartDate: '2026-10-12', overallFloatDays: -1, overallFloatWeeks: -0.1, planningStatus: 'behind_at_risk' }).status.text, 'Desired start is 1 day before the earliest possible start · float -0.1 weeks.')
})
test('the stage follows handover, actual start and end, in that order', () => {
 const steps = { ...fresh, processCompleted: { status: 'complete', date: '2026-09-14', doneCount: 10, totalRequired: 10 }, requiredPreparationWeeks: 3 }
 assert.equal(timelineOf(steps).stage, 0)
 assert.match(timelineOf(steps).initiation.note, /^Completed 14 Sept? 2026$/)
 assert.equal(timelineOf(steps).execution.note, '3 weeks preparation after the process steps')
 const handedOver = { ...steps, actualHandoverDate: '2026-09-28' }
 assert.equal(timelineOf(handedOver).stage, 1)
 assert.equal(timelineOf(handedOver).initiation.handover, '2026-09-28')
 assert.equal(timelineOf(handedOver).initiation.handoverNote, 'Handed to the execution team')
 const started = { ...handedOver, actualStartDate: '2026-10-12' }
 assert.equal(timelineOf(started).stage, 2)
 assert.equal(timelineOf(started).delivery.note, 'Set weeks on site in Planning to plan it')
 const planned = { ...started, executionWeeks: 6, plannedEndDate: '2026-11-23' }
 assert.deepEqual(timelineOf(planned).delivery, { end: '2026-11-23', planned: true, note: '6 weeks on site from the actual start', weeks: 6 })
 const over = { ...planned, actualEndDate: '2026-11-20', plannedEndDate: '2026-11-20' }
 assert.equal(timelineOf(over).stage, 3)
 assert.deepEqual(timelineOf(over).delivery, { end: '2026-11-20', planned: false, note: 'Work on site finished', weeks: 6 })
})
test('handover warnings are the server’s, shown on the handover', () => {
 const t = timelineOf({ ...fresh, actualHandoverDate: '2026-10-19', actualStartDate: '2026-10-12', handoverWarnings: ['Handover after the actual start'] })
 assert.equal(t.initiation.handoverNote, 'Handover after the actual start')
 assert.equal(t.initiation.handoverWarn, true)
})
test('missing or unconfigured process steps are said, not counted as progress', () => {
 const missing = timelineOf({ ...fresh, processCompleted: { status: 'missing_cards', doneCount: 1, totalRequired: 4, missingTitles: ['AVP', 'VO'] } })
 assert.equal(missing.initiation.note, '2 required cards missing from the board')
 assert.equal(missing.initiation.warn, true)
 const none = timelineOf({ ...fresh, processCompleted: { status: 'not_configured', doneCount: 0, totalRequired: 0 } })
 assert.equal(none.initiation.configured, false)
 assert.equal(none.initiation.note, 'No process steps configured')
 assert.equal(timelineOf(null).stage, 0)
})
test('the whole timeline’s percentage is the time passed from request to end', () => {
 const site = { ...fresh, requestDate: '2026-06-01', actualStartDate: '2026-10-12', executionWeeks: 6, plannedEndDate: '2026-11-23' }
 // 1 Jun to 23 Nov is 175 days; 19 Oct is day 140.
 assert.deepEqual(timelineOf({ ...site, kpis: { today: '2026-10-19' } }).overall, { percent: 80, estimate: false, text: 'of the timeline · 1 Jun 2026 → planned end 23 Nov 2026', note: '' })
 assert.equal(timelineOf({ ...site, kpis: { today: '2026-05-20' } }).overall.percent, 0, 'not below 0 before the request')
 assert.equal(timelineOf({ ...site, kpis: { today: '2026-12-10' } }).overall.percent, 100, 'not above 100 past a planned end')
 assert.equal(timelineOf(site, Date.UTC(2026, 9, 19, 23, 30)).overall.percent, 80, 'without the server’s today, the clock’s date')
 const ended = timelineOf({ ...site, actualEndDate: '2026-11-20', kpis: { today: '2026-11-01' } }).overall
 assert.deepEqual(ended, { percent: 100, estimate: false, text: 'of the timeline · 1 Jun 2026 → ended 20 Nov 2026', note: '' }, 'a recorded end is the whole way')
})
test('without an end date the end is estimated from the desired, else minimum, start and weeks on site', () => {
 const early = { ...fresh, requestDate: '2026-06-01', minimumStartDate: '2026-12-07', kpis: { today: '2026-10-25' } }
 // 1 Jun to 15 Mar 2027 (1 Feb + 6 weeks) is 287 days; 25 Oct is day 146.
 assert.deepEqual(timelineOf({ ...early, desiredStartDate: '2027-02-01', executionWeeks: 6 }).overall, { percent: 51, estimate: true, text: 'of the timeline · 1 Jun 2026 → estimated end 15 Mar 2027', note: 'Estimated from the desired start, 1 Feb 2027, + 6 weeks on site. It becomes the planned end once the actual start and weeks on site are set.' })
 // No desired start: 7 Dec + 6 weeks is 18 Jan 2027, 231 days from the request.
 const minimum = timelineOf({ ...early, executionWeeks: 6 }).overall
 assert.equal(minimum.percent, 63)
 assert.equal(minimum.text, 'of the timeline · 1 Jun 2026 → estimated end 18 Jan 2027')
 assert.match(minimum.note, /^Estimated from the minimum start, 7 Dec 2026, \+ 6 weeks on site\./)
 assert.equal(timelineOf({ ...early, executionWeeks: 1 }).overall.note.split('.')[0], 'Estimated from the minimum start, 7 Dec 2026, + 1 week on site')
})
test('with no weeks on site the bar runs to the start, and says so', () => {
 // PROJECT_TEST01 on 25 Oct 2026: 24 Aug 2026 to the minimum start 29 May 2028 is 644 days; day 62.
 assert.deepEqual(timelineOf({ ...fresh, kpis: { today: '2026-10-25' } }).overall, { percent: 10, estimate: true, text: 'of the timeline · 24 Aug 2026 → minimum start 29 May 2028', note: 'Measured to the minimum start, as weeks on site aren’t set yet. It becomes the planned end once the actual start and weeks on site are set.'.replace('’', "'") })
 assert.equal(timelineOf({ ...fresh, desiredStartDate: '2027-01-04', kpis: { today: '2026-10-25' } }).overall.text, 'of the timeline · 24 Aug 2026 → desired start 4 Jan 2027')
 assert.equal(timelineOf({ ...fresh, minimumStartDate: '2026-10-01', kpis: { today: '2026-10-25' } }).overall.percent, 100, 'a start that has passed is the whole way to it')
})
test('the planned and recorded ends win over any estimate', () => {
 const t = timelineOf({ ...fresh, requestDate: '2026-06-01', desiredStartDate: '2027-02-01', executionWeeks: 6, actualStartDate: '2026-10-12', plannedEndDate: '2026-11-23', kpis: { today: '2026-10-25' } }).overall
 assert.equal(t.estimate, false)
 assert.equal(t.percent, 83)
})
test('without any date to measure to the timeline has no percentage', () => {
 assert.deepEqual(timelineOf({ ...fresh, minimumStartDate: null, kpis: { today: '2026-10-19' } }).overall, { percent: null, estimate: false, text: 'of the timeline · no end date planned yet, so there is nothing to measure against', note: '' })
 assert.equal(timelineOf({ ...fresh, requestDate: null, plannedEndDate: '2026-11-23' }).overall.text, 'of the timeline · no request date yet')
 assert.equal(timelineOf(null).overall.percent, null)
})
