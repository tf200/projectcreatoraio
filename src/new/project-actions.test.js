import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import * as actions from './project-actions.js'

const project = { id: 21, name: 'Firma de Testerij', description: 'Oud', ownerId: 'emma', organization_id: 3, client_name: 'ACME', client_role: ['project_owner'], client_phone: '', client_email: 'info@acme.nl', client_address: '', loc_street: 'Kruiskade 12', loc_city: 'Rotterdam', loc_zip: '' }

test('who may do what follows the server’s rules', () => {
	const member = actions.permissionsOf(project, { userId: 'thomas', organizationRole: 'member', organizationId: 3 })
	assert.deepEqual(member, { rename: false, describe: false, disciplines: false, status: false, delete: false, client: true })
	const owner = actions.permissionsOf(project, { userId: 'emma', organizationRole: 'member', organizationId: 3 })
	assert.deepEqual(owner, { rename: true, describe: false, disciplines: true, status: true, delete: true, client: true }, 'an owner may not change the description')
	const orgAdmin = actions.permissionsOf(project, { userId: 'x', organizationRole: 'admin', organizationId: 3 })
	assert.equal(orgAdmin.describe, true)
	assert.equal(orgAdmin.disciplines, true)
	assert.equal(actions.permissionsOf(project, { userId: 'x', organizationRole: 'admin', organizationId: 9 }).delete, false, 'an admin of another organisation')
	assert.equal(actions.permissionsOf(project, { userId: 'root', isGlobalAdmin: true }).describe, true)
})

test('only changed fields this person may send are sent, trimmed', () => {
	const owner = actions.permissionsOf(project, { userId: 'emma' })
	const body = actions.changesOf({ name: ' Firma de Testerij 2 ', description: 'Nieuw' }, project, actions.detailsFields(owner))
	assert.deepEqual(body, { name: 'Firma de Testerij 2' }, 'no description from an owner')
	assert.deepEqual(actions.changesOf(actions.detailsDraft(project), project, ['name', 'description']), {}, 'nothing changed, nothing sent')
	const client = { ...actions.clientDraft(project), loc_zip: ' 3012 EH ', client_role: ['project_owner', 'key_stakeholder', 'key_stakeholder'] }
	assert.deepEqual(actions.changesOf(client, project, actions.CLIENT_FIELDS), { client_role: ['project_owner', 'key_stakeholder'], loc_zip: '3012 EH' })
})

test('disciplines are sent only when they changed, in the server’s order', () => {
	const owner = actions.permissionsOf(project, { userId: 'emma' })
	assert.deepEqual(actions.detailsFields(owner), ['name', 'disciplines'])
	const withDisciplines = { ...project, disciplines: ['electricity', 'water'] }
	assert.deepEqual(actions.detailsDraft(withDisciplines).disciplines, ['electricity', 'water'])
	assert.deepEqual(actions.detailsDraft(project).disciplines, [], 'a project from before disciplines has none')
	assert.deepEqual(actions.changesOf({ ...actions.detailsDraft(withDisciplines), disciplines: ['water', 'electricity'] }, withDisciplines, ['disciplines']), {}, 'the same set in another order is no change')
	assert.deepEqual(actions.changesOf({ ...actions.detailsDraft(withDisciplines), disciplines: ['sewerage', 'water', 'electricity'] }, withDisciplines, ['disciplines']), { disciplines: ['electricity', 'water', 'sewerage'] })
	assert.deepEqual(actions.changesOf({ ...actions.detailsDraft(withDisciplines), disciplines: [] }, withDisciplines, ['disciplines']), { disciplines: [] }, 'clearing them is a change')
	const member = actions.permissionsOf(project, { userId: 'thomas', organizationRole: 'member', organizationId: 3 })
	assert.deepEqual(actions.changesOf({ ...actions.detailsDraft(project), disciplines: ['gas'] }, project, actions.detailsFields(member)), {}, 'a member sends none')
})

test('server messages are shown as they are', () => {
	assert.equal(actions.messageOf({ response: { data: { message: 'Project members can only update client and location details' } } }, 'x'), 'Project members can only update client and location details')
	assert.equal(actions.messageOf({ response: { data: { ocs: { meta: { message: 'Only organization admins or the project owner can delete this project' } } } } }, 'x'), 'Only organization admins or the project owner can delete this project')
	assert.equal(actions.messageOf({}, 'Fallback.'), 'Fallback.')
})

test('the header no longer sends people to the current interface to manage a project', () => {
	const header = readFileSync(new URL('./ProjectHeader.vue', import.meta.url), 'utf8')
	assert.doesNotMatch(header, /Manage project|Project updates/)
	assert.match(header, /<ProjectActions /)
	const view = readFileSync(new URL('./ProjectActions.vue', import.meta.url), 'utf8')
	for (const item of ['Edit project details', 'Edit client &amp; address', 'Export project', 'Delete project', 'Type the project name to confirm']) assert.ok(view.includes(item), item)
})
