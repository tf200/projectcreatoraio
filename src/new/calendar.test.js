import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import * as cal from './calendar.js'

process.env.TZ = 'UTC'
const now = Date.parse('2026-10-02T12:00:00Z')

// The shape the Calendar app returns: the local #22 proposal, and meetings.
const proposal = {
	'@type': 'MeetingProposal', id: 1, projectId: 22, title: 'test', description: 'test', location: 'Talk conversation', duration: 30,
	participants: [
		{ name: 'admin2', address: 'fasd@gmail.com', status: 'needs-action' },
		{ name: 'Admin3', address: 'admin3@gmail.com', status: 'needs-action' },
		{ name: 'taha@yba.ai', address: 'taha@yba.ai', status: 'responded' },
	],
	dates: ['2026-08-24T09:00:00+00:00', '2026-08-25T08:30:00+00:00', '2026-09-04T07:15:00+00:00'].map((date, i) => ({ '@type': 'MeetingProposalDate', id: i + 1, date })),
}
const meeting = (id, start, end, people = []) => ({ '@type': 'Meeting', id, title: 'M' + id, location: 'Talk conversation', duration: 60, startDate: start, endDate: end, participants: people })
const kickoff = meeting('k', '2026-10-07T10:00:00+00:00', '2026-10-07T11:00:00+00:00', [
	{ name: 'Emma', address: 'emma@firma.nl', status: 'accepted' }, { name: 'Thomas', address: 'thomas@firma.nl', status: 'ACCEPTED' },
	{ name: 'Lotte', address: 'lotte@firma.nl', status: 'tentative' }, { name: 'Mark', address: 'mark@elektra.nl', status: 'declined' },
])
const later = meeting('l', '2026-10-15T14:00:00+00:00', '2026-10-15T15:00:00+00:00')
const past = meeting('p', '2026-09-24T09:00:00+00:00', '2026-09-24T10:30:00+00:00')
// Running right now: still upcoming until it ends.
const running = meeting('r', '2026-10-02T11:30:00+00:00', '2026-10-02T12:30:00+00:00')

test('proposals come first, then meetings to come soonest first, then past meetings', () => {
	const groups = cal.groupsOf([later, past, proposal, kickoff, running], 'all', now)
	assert.equal(groups.map(g => g.key + ':' + g.items.map(e => e.id).join(',')).join(' | '), 'proposals:1 | upcoming:r,k,l | past:p')
	assert.equal(groups[0].label, 'Needs a date')
	assert.equal(cal.groupsOf([later, proposal], 'proposals', now).map(g => g.key).join(), 'proposals')
	assert.equal(cal.groupsOf([later, proposal], 'meetings', now).map(g => g.key).join(), 'upcoming')
	assert.equal(cal.groupsOf([], 'all', now).length, 0)
})

test('a proposal whose dates have all passed says so', () => {
	const options = cal.optionsOf(proposal, now)
	assert.equal(options.map(o => o.label).join(' | '), 'Mon 24 Aug · 09:00 | Tue 25 Aug · 08:30 | Fri 4 Sept · 07:15')
	assert.equal(options.every(o => o.passed), true)
	assert.equal(cal.allPassed(proposal, now), true)
	const open = { ...proposal, dates: [...proposal.dates, { id: 9, date: '2026-10-20T09:00:00+00:00' }] }
	assert.equal(cal.allPassed(open, now), false)
	assert.equal(cal.allPassed({ ...proposal, dates: [] }, now), false, 'no dates is not "all passed"')
})

test('answers, statuses and people read as plain words', () => {
	assert.deepEqual(cal.answeredOf(proposal), { answered: 1, total: 3 })
	const people = cal.peopleOf(proposal)
	assert.equal(people.map(p => p.name + '|' + p.address + '|' + p.label + '|' + p.tone).join(' ; '), 'admin2|fasd@gmail.com|Waiting|muted ; Admin3|admin3@gmail.com|Waiting|muted ; taha@yba.ai||Answered|success')
	assert.equal(cal.attendeeSummary(kickoff), '2 accepted · 1 maybe · 1 declined')
	assert.equal(cal.attendeeSummary(later), '')
	assert.deepEqual(cal.statusOf('something-new'), { label: 'something-new', tone: 'muted' })
})

test('dates, times and the one-line summary', () => {
	assert.deepEqual(cal.dateBlock(kickoff.startDate), { month: 'Oct', day: '7', weekday: 'Wed' })
	assert.equal(cal.timeRange(kickoff), '10:00 – 11:00')
	assert.equal(cal.timeRange({ startDate: kickoff.startDate }), '10:00')
	assert.equal(cal.longDay(kickoff.startDate, now), 'Wednesday 7 October')
	assert.equal(cal.longDay('2027-01-05T10:00:00Z', now), 'Tuesday 5 January 2027')
	assert.equal(cal.dayTime('2027-01-05T10:00:00Z', now), 'Tue 5 Jan 2027 · 10:00')
	assert.equal(cal.metaOf(proposal), '3 date options · 30 min · Talk conversation')
	assert.equal(cal.metaOf({ ...proposal, dates: [proposal.dates[0]], location: '' }), '1 date option · 30 min')
	assert.equal(cal.metaOf(kickoff), '10:00 – 11:00 · Talk conversation')
	assert.notEqual(cal.keyOf(proposal), cal.keyOf({ ...kickoff, id: 1 }), 'a proposal and a meeting can share an id')
})

test('the new Calendar tab extends ProjectCalendar, keeping its loading and paging', () => {
	const view = readFileSync(new URL('./NewCalendar.vue', import.meta.url), 'utf8')
	assert.match(view, /extends: ProjectCalendar/)
	for (const kept of ['@click="loadMore"', '@click="loadEvents"', 'filterType = tab.key', 'proposalCount', 'confirmedCount']) assert.ok(view.includes(kept), kept)
	const module = readFileSync(new URL('./ProjectModule.vue', import.meta.url), 'utf8')
	assert.match(module, /agenda: \(\) => import\('\.\/NewCalendar\.vue'\)/)
})
