import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import * as create from './create.js'
import { readRoute, interfaceUrl } from './navigation.js'

const filled = () => ({ ...create.emptyForm(), name: '  Firma de Testerij ', number: ' P-2026-014', type: 0 })

test('Create waits for a name, a number, a type and, for administrators, an organisation', () => {
	assert.equal(create.missingOf(create.emptyForm(), false).join(), 'a name,a number,a type')
	assert.equal(create.missingOf(create.emptyForm(), true).join(), 'a name,a number,a type,an organisation')
	assert.equal(create.missingOf(filled(), false).length, 0)
	assert.equal(create.missingOf(filled(), true).join(), 'an organisation')
	assert.equal(create.missingOf({ ...filled(), organizationId: 1 }, true).length, 0)
	assert.equal(create.missingOf({ ...filled(), name: '   ' }, false).join(), 'a name', 'spaces are not a name')
	assert.equal(create.missingOf({ ...filled(), type: null }, false).join(), 'a type', 'a cleared type blocks Create (the current form lets it through)')
	assert.equal(create.missingOf({ ...filled(), type: 7 }, false).join(), 'a type')
	assert.equal(create.sentence(['a name', 'a number', 'a type']), 'a name, a number and a type')
})

test('the request carries the fields the current interface sends, trimmed', () => {
	const body = create.payloadOf({ ...filled(), clientRoles: ['project_owner', 'project_owner', 'key_stakeholder'], city: ' Rotterdam ', organizationId: '3' })
	assert.equal(JSON.stringify(Object.keys(body)), JSON.stringify(['name', 'number', 'description', 'client_name', 'client_role', 'client_phone', 'client_email', 'client_address', 'loc_street', 'loc_city', 'loc_zip', 'type', 'organizationId', 'members']))
	assert.equal(body.name, 'Firma de Testerij')
	assert.equal(body.number, 'P-2026-014')
	assert.equal(body.loc_city, 'Rotterdam')
	assert.equal(body.client_role.join(), 'project_owner,key_stakeholder')
	assert.equal(body.organizationId, 3)
	assert.equal(body.members.length, 0)
	assert.equal(create.payloadOf(filled()).organizationId, null)
	// The legacy model sends the same keys; keep the two in step.
	const model = readFileSync(new URL('../Models/project.js', import.meta.url), 'utf8')
	for (const key of Object.keys(body)) assert.match(model, new RegExp('\\b' + key + '\\b'), key)
})

test('the set-up list follows the name, the type and the plan', () => {
	const setup = create.setupOf({ ...filled(), name: 'Firma de Testerij' }, { sharedStoragePerProject: 10737418240 })
	assert.equal(setup.map(s => s.title).join(' | '), 'Firma de Testerij - Main Board | Firma de Testerij - Shared Files | Firma de Testerij - Private Files | Firma de Testerij.whiteboard | Firma de Testerij - Chat')
	assert.match(setup[0].text, /20 Combi process tasks/)
	assert.match(setup[1].text, /10 GB$/)
	assert.doesNotMatch(create.setupOf({ ...filled(), type: 3 }, null)[0].text, /Combi/)
	assert.equal(create.setupOf(create.emptyForm(), null)[0].title, 'Project name - Main Board')
	assert.equal(create.formatBytes(1610612736), '1.5 GB')
	assert.equal(create.formatBytes(524288000), '500 MB')
	assert.equal(create.formatBytes(0), '')
})

test('the plan line says how many projects are used and whether one more fits', () => {
	assert.deepEqual(create.planOf({ organizationName: 'Test', maxProjects: 5, projectsCount: 2 }), { organization: 'Test', used: 2, max: 5, full: false, percent: 40 })
	assert.equal(create.planOf({ organizationName: 'Test', maxProjects: 5, projectsCount: 5 }).full, true)
	assert.equal(create.planOf({ maxProjects: 1, projectsCount: 3 }).percent, 100)
	assert.equal(create.planOf({ maxProjects: null, projectsCount: null }), null, 'no plan to report')
	assert.equal(create.planOf(null), null)
})

test('a refusal reads as the server’s own sentence, never a trace', () => {
	const refused = message => ({ response: { data: { message } } })
	assert.equal(create.failureOf(refused('Failed to create project: The maximum number of projects allowed for this plan (5) has been reached. You currently have 5 projects. Please upgrade your plan to create additional projects.')), 'The maximum number of projects allowed for this plan (5) has been reached. You currently have 5 projects. Please upgrade your plan to create additional projects.')
	assert.equal(create.failureOf(refused('Failed to create project: OCP\\AppFramework\\OCS\\OCSException: Whiteboard file creation failed. in /var/www/nextcloud/lib/x.php:12\nStack trace:\n#0 ...')), 'Whiteboard file creation failed.')
	assert.equal(create.failureOf(refused('Failed to create project: Something\nStack trace:\n#0 x')), 'Something')
	assert.equal(create.failureOf({ message: 'Request failed with status code 500' }), 'The project could not be created. Please try again.')
	assert.equal(create.failureOf({}), 'The project could not be created. Please try again.')
})

test('the New project page has its own address in both interfaces', () => {
	assert.deepEqual(readRoute('/index.php/apps/projectcreatoraio/new/create', ''), { projectId: null, tab: 'overview', create: true })
	assert.equal(readRoute('/apps/projectcreatoraio/new/projects/21', '').create, undefined)
	const base = '/index.php/apps/projectcreatoraio'
	assert.equal(interfaceUrl(base, { create: true }, true), base + '/new/create')
	assert.equal(interfaceUrl(base, { create: true }, false), base + '?create=1', 'Back to current interface opens its own create form')
	for (const file of ['./ProjectShelf.vue', './ProjectList.vue', './NewApp.vue']) {
		const source = readFileSync(new URL(file, import.meta.url), 'utf8')
		assert.doesNotMatch(source, /'\?create=1'/, file + ' no longer sends people to the old interface')
	}
	const routes = readFileSync(new URL('../../appinfo/routes.php', import.meta.url), 'utf8')
	assert.match(routes, /'page#newCreate', 'url' => '\/new\/create'/)
	assert.ok(routes.indexOf('/api/v1/projects/allowance') < routes.indexOf('"/api/v1/projects/{projectId}"'), 'the allowance route comes before projects/{projectId}')
})
