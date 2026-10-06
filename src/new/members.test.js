import test from 'node:test'
import assert from 'node:assert/strict'
import { DRASCIVS, rolesOf, projectRoleOptions, coverage, draftProblem, toggled } from './members.js'

test('roles are read from the current field and from the older ones', () => {
	assert.deepEqual(rolesOf({ drascivsRoles: ['driver', 'signer'] }), ['driver', 'signer'])
	assert.deepEqual(rolesOf({ drasciRoles: ['informed'] }), ['informed'])
	assert.deepEqual(rolesOf({ drasciRole: 'consulted' }), ['consulted'])
	assert.deepEqual(rolesOf({}), [])
})

test('coverage lists every responsibility, with its holders or none', () => {
	const rows = coverage([
		{ id: 'emma', displayName: 'Emma de Vries', drascivsRoles: ['driver', 'accountable'] },
		{ id: 'lotte', displayName: 'Lotte Bakker', drascivsRoles: ['informed'] },
		{ id: 'sanne', drascivsRoles: ['informed'] },
	])
	assert.equal(rows.length, DRASCIVS.length)
	assert.deepEqual(rows.find(r => r.value === 'driver').holders, ['Emma de Vries'])
	assert.deepEqual(rows.find(r => r.value === 'informed').holders, ['Lotte Bakker', 'sanne'], 'no display name falls back to the id')
	assert.deepEqual(rows.find(r => r.value === 'signer').holders, [])
})

test('project roles keep a stable colour by position', () => {
	const options = projectRoleOptions([{ key: 'cpl', name: 'CPL' }, { key: 'client', name: 'Client/Developer' }, { key: 'grid', name: 'Grid operator (Elektra)' }])
	assert.deepEqual(options.map(o => o.tone), [3, 5, 4])
	assert.equal(options[1].label, 'Client/Developer')
	assert.equal(projectRoleOptions([{ key: 'x' }])[0].label, 'x')
})

test('the server’s role rules are explained before a request is sent', () => {
	assert.equal(draftProblem({ needsPerson: true, drascivs: ['driver'], functional: ['cpl'] }), 'Choose a person to add.')
	assert.equal(draftProblem({ drascivs: [], functional: ['cpl'] }), 'Choose at least one DRASCIVS role.')
	assert.equal(draftProblem({ drascivs: ['driver'], functional: [] }), 'Choose at least one project role.')
	assert.equal(draftProblem({ needsPerson: true, person: { id: 'jv' }, drascivs: ['driver'], functional: ['cpl'] }), '')
	assert.deepEqual(toggled(['a', 'b'], 'a'), ['b'])
	assert.deepEqual(toggled(['a'], 'b'), ['a', 'b'])
})

test('externals are labelled with their company and warned of a near end', async () => {
	const { externalLabel, endsSoon, isoDay } = await import('./members.js')
	assert.equal(externalLabel({ company: ' Klant BV ' }), 'External · Klant BV')
	assert.equal(externalLabel({ company: null }), 'External')
	const now = Date.parse('2026-10-06T12:00:00Z')
	assert.equal(endsSoon({ expiresAt: '2026-10-10T23:59:59Z' }, now), true)
	assert.equal(endsSoon({ expiresAt: '2026-12-31T23:59:59Z' }, now), false)
	assert.equal(endsSoon({ expiresAt: null }, now), false)
	assert.equal(isoDay(new Date(2026, 0, 5)), '2026-01-05')
})
