import test from 'node:test'
import assert from 'node:assert/strict'
import { handoverDraft, joiningWithTeam, ownerOptions, externalEnd, externalEndText, handoverProblem, handoverPayload, handoverSummary, stepReport } from './handover.js'

// Project 39 on the dev server, trimmed: Team Zuid works on it, Klaas is external.
const preview = {
	handoverDate: null,
	plannedEndDate: '2027-01-29',
	ownerId: 'sanne',
	members: [
		{ id: 'sanne', displayName: 'Sanne', isOwner: true, isExternal: false, teamIds: [], addedByHand: true },
		{ id: 'ruben', displayName: 'Ruben', isOwner: false, isExternal: false, teamIds: [3], addedByHand: false },
		{ id: 'ext_klaas', displayName: 'Klaas', isOwner: false, isExternal: true, teamIds: [], addedByHand: true },
	],
	team: { id: 3, name: 'Team Zuid' },
	teams: [
		{ id: 3, name: 'Team Zuid', members: [{ id: 'ruben', displayName: 'Ruben' }] },
		{ id: 4, name: 'Team Noord', members: [{ id: 'ruben', displayName: 'Ruben' }, { id: 'dave', displayName: 'Dave' }] },
	],
	externals: [{ id: 'ext_klaas', displayName: 'Klaas', status: 'active', expiresAt: '2027-02-15T23:59:59+00:00' }],
	teamsAvailable: true,
}

test('the draft keeps everything and keeps externals until the end', () => {
	const draft = handoverDraft(preview, new Date(2026, 9, 28))
	assert.deepEqual(draft, { date: '2026-10-28', ownerUid: 'sanne', team: { mode: 'keep', teamId: 3 }, add: [], externals: { ext_klaas: { mode: 'untilEnd', date: '2027-02-15' } } })
	assert.equal(handoverProblem(preview, draft), '')
	assert.deepEqual(handoverPayload(preview, draft), { handoverDate: '2026-10-28', ownerUid: '', team: { mode: 'keep' }, addMembers: [], externals: [{ uid: 'ext_klaas', mode: 'untilEnd' }] })
	assert.deepEqual(handoverSummary(preview, draft), ['Handover on 28 Oct 2026', 'Team Zuid stays the team', 'Klaas: access until 29 Jan 2027', 'Nobody leaves the project'])
})

test('a new team brings its people, who may own the project, and the old team stays', () => {
	const draft = { ...handoverDraft(preview, new Date(2026, 9, 28)), team: { mode: 'set', teamId: 4 }, ownerUid: 'dave' }
	assert.deepEqual(joiningWithTeam(preview, draft).map(p => p.id), ['dave'])
	// Klaas is external, so he cannot own the project.
	assert.deepEqual(ownerOptions(preview, draft).map(p => p.id), ['sanne', 'ruben', 'dave'])
	assert.deepEqual(handoverPayload(preview, draft).team, { mode: 'set', teamId: 4 })
	assert.equal(handoverPayload(preview, draft).ownerUid, 'dave')
	assert.deepEqual(handoverSummary(preview, draft).slice(1, 4), ['Team Noord takes over · joining: Dave', 'Team Zuid’s people stay on the project', 'Owner becomes Dave · Sanne stays as a member'])
	// Choosing the current team again changes nothing.
	assert.deepEqual(handoverPayload(preview, { ...draft, team: { mode: 'set', teamId: 3 } }).team, { mode: 'keep' })
	assert.deepEqual(handoverPayload(preview, { ...draft, team: { mode: 'none', teamId: null } }).team, { mode: 'none' })
})

test('externals end at the handover, on a chosen day, or stay as they are without a planned end', () => {
	const draft = handoverDraft(preview, new Date(2026, 9, 28))
	draft.externals.ext_klaas = { mode: 'atHandover', date: '' }
	assert.equal(externalEnd(preview, draft, 'ext_klaas'), '2026-10-28')
	draft.externals.ext_klaas = { mode: 'date', date: '' }
	assert.equal(handoverProblem(preview, draft), 'Choose the day Klaas’s access ends.')
	draft.externals.ext_klaas = { mode: 'date', date: '2026-12-01' }
	assert.deepEqual(handoverPayload(preview, draft).externals, [{ uid: 'ext_klaas', mode: 'date', date: '2026-12-01' }])
	const unplanned = { ...preview, plannedEndDate: null }
	assert.equal(externalEndText(unplanned, handoverDraft(unplanned, new Date(2026, 9, 28)), preview.externals[0]), 'No end of work on site planned yet: stays until 15 Feb 2027')
})

test('added people need their roles, as on the Members tab', () => {
	const draft = { ...handoverDraft(preview, new Date(2026, 9, 28)), add: [{ id: 'carol', displayName: 'Carol', drascivs: [], functional: [] }] }
	assert.equal(handoverProblem(preview, draft), 'Carol: choose at least one DRASCIVS role.')
	draft.add[0].drascivs = ['responsible']
	assert.equal(handoverProblem(preview, draft), 'Carol: choose at least one project role.')
	draft.add[0].functional = ['werkvoorbereider']
	assert.equal(handoverProblem(preview, draft), '')
	assert.deepEqual(handoverPayload(preview, draft).addMembers, [{ uid: 'carol', drascivsRoles: ['responsible'], functionalRoleKeys: ['werkvoorbereider'] }])
	assert.ok(ownerOptions(preview, draft).some(p => p.id === 'carol'))
	assert.equal(handoverProblem(preview, { ...draft, date: '' }), 'Choose the handover date.')
	assert.equal(handoverProblem(preview, { ...draft, team: { mode: 'set', teamId: null } }), 'Choose the team that takes over.')
})

test('the server’s report names people and lists failures and notes apart', () => {
	const draft = { ...handoverDraft(preview), add: [{ id: 'carol', displayName: 'Carol', drascivs: [], functional: [] }] }
	const report = stepReport(preview, draft, [
		{ step: 'member:carol', status: 'done' },
		{ step: 'team', status: 'failed', message: 'Something went wrong.' },
		{ step: 'external:ext_klaas', status: 'done', message: 'No end of work on site is planned yet, so the end date is unchanged.' },
		{ step: 'handoverDate', status: 'done' },
	])
	assert.deepEqual(report.failed, [{ label: 'Team', failed: true, message: 'Something went wrong.' }])
	assert.deepEqual(report.notes.map(n => n.label), ['Klaas'])
})
