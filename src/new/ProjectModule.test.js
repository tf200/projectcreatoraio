import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'

const source = readFileSync(new URL('./ProjectModule.vue', import.meta.url), 'utf8')
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1]
	.replace(/^import .*$/gm, '')
	.replace('export default', 'globalThis.component =')
function host(get) {
	const sandbox = { axios: { get }, generateUrl: value => value, t: (_app, value) => value }
	vm.runInNewContext(script, sandbox)
	const options = sandbox.component
	const instance = { project: { id: 1, type: 0 }, context: { userId: 'alice', organizationId: 4 }, tab: 'documents', ...options.data() }
	for (const [key, method] of Object.entries(options.methods)) instance[key] = method.bind(instance)
	for (const [key, getter] of Object.entries(options.computed)) Object.defineProperty(instance, key, { get: getter.bind(instance) })
	return { instance, options }
}
const deferred = () => { let resolve; const promise = new Promise(done => { resolve = done }); return { promise, resolve } }

test('file failures stay errors and retry recovers both root scopes', async () => {
	let fails = true
	const { instance } = host(async () => {
		if (fails) throw new Error('Forbidden')
		return { data: { files: { shared: [{ id: 4 }], private: [{ id: 5 }] } } }
	})
	await instance.loadFiles()
	assert.ok(instance.filesError)
	assert.equal(instance.filesLoading, false)
	fails = false
	await instance.loadFiles()
	assert.equal(instance.filesError, '')
	assert.equal(instance.files.shared[0].id, 4)
	assert.equal(instance.files.private[0].id, 5)
})

test('stale files cannot overwrite a newer project or update after destroy', async () => {
	const pending = deferred()
	const { instance, options } = host(() => pending.promise)
	const request = instance.loadFiles()
	instance.project = { id: 2 }
	options.beforeDestroy.call(instance)
	pending.resolve({ data: { files: { shared: [{ id: 99 }], private: [] } } })
	await request
	assert.equal(instance.files.shared.length, 0)
})

test('member reads retain all roles and ignore obsolete project responses', async () => {
	const pending = deferred()
	const { instance } = host(() => pending.promise)
	instance.tab = 'notes'
	const request = instance.loadMembers()
	instance.project = { id: 2 }
	pending.resolve({ data: { members: [{ id: 'bob' }], functionalRoles: [] } })
	await request
	assert.equal(instance.members.length, 0)
})

test('feature flags and Combi gate modules without changing timeline access', () => {
	const { instance } = host()
	instance.tab = 'intake'
	assert.equal(instance.unavailable, '')
	instance.project.type = 1
	assert.ok(instance.unavailable)
	instance.tab = 'tasks'
	instance.context.features = { deck: false }
	assert.ok(instance.unavailable)
	instance.tab = 'planning'
	assert.equal(instance.moduleProps.isAdmin, true)
	instance.tab = 'notes'
	instance.project.talk_conversation_token = 'token'
	instance.context.features.talk = false
	assert.equal(instance.moduleProps.talkConversationToken, '')
})

test('malformed files never render as an empty success', async () => {
	const { instance } = host(async () => ({ data: { files: {} } }))
	await instance.loadFiles()
	assert.ok(instance.filesError)
})

test('a slower refresh cannot replace the latest file tree', async () => {
	const pending = deferred()
	let call = 0
	const { instance } = host(() => ++call === 1 ? pending.promise : Promise.resolve({ data: { shared: [{ id: 2 }], private: [] } }))
	const oldRequest = instance.loadFiles()
	await instance.loadFiles()
	pending.resolve({ data: { shared: [{ id: 1 }], private: [] } })
	await oldRequest
	assert.equal(instance.files.shared[0].id, 2)
})

test('notes loads the team for its direct chats; the members tab is its own view', async () => {
	const { instance } = host(async () => ({ data: { members: [{ id: 'bob', drascivsRoles: ['driver'] }], functionalRoles: [] } }))
	instance.tab = 'notes'
	await instance.loadMembers()
	assert.equal(instance.members.length, 1)
	assert.equal(instance.membersError, '')
	instance.tab = 'members'
	assert.equal(instance.needsMembers, false, 'the members view reads its own list')
	assert.equal(instance.unavailable, '', 'members is a real module, not a pointer to the current interface')
	assert.equal(instance.moduleProps.canManage, false, 'a plain member cannot manage the team')
	assert.equal(instance.moduleProps.currentUserId, 'alice')
	assert.equal(instance.moduleProps.organizationId, 4)
	instance.context.organizationRole = 'admin'
	assert.equal(instance.moduleProps.canManage, true)
})

test('a member picked for a chat reaches Notes as its target', () => {
	const { instance } = host()
	instance.tab = 'notes'
	instance.directChatUser = { id: 'bob', displayName: 'Bob' }
	assert.equal(instance.moduleProps.targetDirectUser.id, 'bob')
})
