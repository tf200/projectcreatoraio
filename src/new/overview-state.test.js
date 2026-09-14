import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'
const script = readFileSync(new URL('./overview-state.js', import.meta.url), 'utf8').replace(/^import .*$/gm, '').replace(/export (async )?function/g, '$1function').replace('export default', 'globalThis.mixin =')
function setup(get) {
 const env = { axios: { get }, generateUrl: x => x }
 vm.runInNewContext(script, env)
 const instance = { ...env.mixin.data(), project: { id: 21, boardId: 31 }, requestVersion: 1, context: { userId: 'alice', features: { deck: true } }, $set(object, key, value) { object[key] = value } }
 for (const [name, method] of Object.entries(env.mixin.methods)) instance[name] = method.bind(instance)
 return instance
}
const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r }); return { promise, resolve } }
test('overview isolates errors and retry replaces failed state', async () => {
 let bad = true
 const instance = setup(async () => ({ data: bad ? {} : { events: [] } }))
 await instance.reloadOverview('activity'); assert.ok(instance.overview.activity.error)
 assert.equal(instance.overview.files.error, '')
 bad = false; await instance.reloadOverview('activity'); assert.equal(instance.overview.activity.error, '')
})
test('overview ignores responses from old projects and obsolete refreshes', async () => {
 const pending = deferred(); let calls = 0
 const instance = setup(() => ++calls === 1 ? pending.promise : Promise.resolve({ data: { events: [{ id: 2 }] } }))
 const first = instance.reloadOverview('activity'); await instance.reloadOverview('activity')
 pending.resolve({ data: { events: [{ id: 1 }] } }); await first
 assert.equal(instance.overview.activity.data[0].id, 2)
 const other = deferred(); const next = setup(() => other.promise)
 const loading = next.reloadOverview('activity'); next.project = { id: 22 }; next.requestVersion++
 other.resolve({ data: { events: [{ id: 21 }] } }); await loading
 assert.equal(next.overview.activity.data, null)
})
