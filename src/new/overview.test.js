import test from 'node:test'
import assert from 'node:assert/strict'
import { progressSummary, planningSummary, assignedTasks, openTasks, recentFiles, activityText } from './overview.js'
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
