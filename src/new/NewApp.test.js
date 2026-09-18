import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'
import { readRoute, interfaceUrl, normalizeTab } from './navigation.js'
const script = readFileSync(new URL('./NewApp.vue', import.meta.url), 'utf8').match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '').replace('export default', 'globalThis.component =')
function app(api, browser = {}) {
 const env = { history: { pushState() {}, replaceState() {} }, overviewState: {}, api, readRoute, interfaceUrl, normalizeTab, generateUrl: x => x, location: { pathname: '/apps/projectcreatoraio/new', search: '' }, NcContent: {}, NcAppContent: {}, ProjectList: {}, ProjectShelf: {}, ProjectHeader: {}, NewOverview: {}, ProjectModule: {}, errorMessage: () => 'error' }
 Object.assign(env, browser)
 vm.runInNewContext(script, env)
 const component = env.component
 const instance = { loadOverview() {}, ...component.data(), context: { userId: 'alice', organizationId: 1 } }
 for (const [key, method] of Object.entries(component.methods)) instance[key] = method.bind(instance)
 for (const [key, getter] of Object.entries(component.computed)) Object.defineProperty(instance,key,{get:getter.bind(instance)})
 return instance
}
const deferred = () => { let resolve; const promise = new Promise(r=>{resolve=r}); return {promise,resolve} }
test('malformed list does not masquerade as empty success and retry recovers', async()=>{
 let response = '<html>Login</html>'
 const instance=app({list:async()=>response})
 await instance.loadList(); assert.equal(instance.listError,'error')
 response=[]; await instance.loadList(); assert.equal(instance.listError,''); assert.equal(instance.projects.length,0)
})
test('late project response cannot replace newer project', async()=>{
 const pending=deferred()
 const instance=app({project: id=>id===1?pending.promise:Promise.resolve({id:2})})
 instance.route={projectId:1,tab:'overview'};const first=instance.loadProject()
 instance.route={projectId:2,tab:'notes'};await instance.loadProject()
 pending.resolve({id:1});await first
 assert.equal(instance.project.id,2);assert.equal(instance.projectLoading,false)
})
test('project error clears previously visible data', async()=>{
 const instance=app({project:async()=>{throw Error('Forbidden')}})
 instance.project={id:3};instance.route={projectId:4};await instance.loadProject()
 assert.equal(instance.project,null);assert.equal(instance.projectError,'error')
})
test('organization-admin scope retains only membership IDs separately from accessible list', async()=>{
 const instance=app({list:async()=>[{id:1},{id:2}],myProjects:async()=>[{id:2},{id:99}]})
 instance.context.organizationRole='admin';await instance.loadList()
 assert.deepEqual(Array.from(instance.projects,x=>x.id),[1,2]);assert.deepEqual(Array.from(instance.myProjectIds),[2,99])
})

test('returning to overview refreshes summaries after using a module', async () => {
 const instance = app({})
 instance.project = { id: 21 }; instance.route = { projectId: 21, tab: 'notes' }
 instance.$nextTick = async () => {}; instance.scrollContainer = () => ({ scrollTop: 0 })
 let refreshed = 0; instance.loadOverview = () => { refreshed++ }
 await instance.navigate(21, 'overview')
 assert.equal(refreshed, 1)
})
test('deep links load the shelf as well as the requested project and retain its tab', async () => {
 const instance = app({ list: async () => [{ id: 1 }, { id: 2 }], project: async id => ({ id }) })
 instance.route = { projectId: 2, tab: 'documents' }
 await instance.loadRoute()
 assert.deepEqual(Array.from(instance.projects, p => p.id), [1, 2])
 assert.equal(instance.project.id, 2)
 assert.equal(instance.route.tab, 'documents')
})
test('workspace entry opens an accessible project instead of a list page', async () => {
 const instance = app({ list: async () => [{ id: 7 }], project: async id => ({ id }) })
 await instance.loadRoute()
 assert.equal(instance.route.projectId, 7)
 assert.equal(instance.project.id, 7)
})
test('empty workspace entry stays empty without requesting a project', async () => {
 const instance = app({ list: async () => [], project: async () => { throw Error('Must not request a project') } })
 await instance.loadRoute()
 assert.equal(instance.project, null)
 assert.equal(instance.route.projectId, null)
 assert.equal(instance.projectError, '')
})
test('a shelf failure does not prevent a valid deep-linked project from opening', async () => {
 const instance = app({ list: async () => { throw Error('Offline') }, project: async id => ({ id }) })
 instance.route = { projectId: 2, tab: 'notes' }
 await instance.loadRoute()
 assert.equal(instance.listError, 'error')
 assert.equal(instance.project.id, 2)
})
test('workspace restores the last accessible project for this user, ignoring stale access', async () => {
 for (const [remembered, expected] of [['8', 8], ['99', 7]]) {
  const storage = new Map([['/apps/projectcreatoraio:last-project:alice', remembered]])
  const instance = app({ list: async () => [{ id: 7 }, { id: 8 }], project: async id => ({ id }) }, {
   localStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value) },
  })
  await instance.loadRoute()
  assert.equal(instance.project.id, expected)
  assert.equal(storage.get('/apps/projectcreatoraio:last-project:alice'), String(expected))
 }
})
test('switching projects keeps the selected tab and loaded shelf', async () => {
 const instance = app({ project: async id => ({ id }), list: async () => { throw Error('Shelf should stay loaded') } })
 instance.listLoaded = true; instance.projects = [{ id: 1 }, { id: 2 }]
 instance.project = { id: 1 }; instance.route = { projectId: 1, tab: 'documents' }
 instance.$nextTick = async () => {}; instance.scrollContainer = () => ({ scrollTop: 0 })
 await instance.navigate(2, instance.route.tab)
 assert.equal(instance.project.id, 2)
 assert.equal(instance.route.tab, 'documents')
 assert.equal(instance.projects.length, 2)
 assert.equal(instance.listError, '')
})
test('browser history restores the requested project and section', async () => {
 const instance = app({ project: async id => ({ id }) }, { location: { pathname: '/apps/projectcreatoraio/new/projects/7', search: '?tab=notes' } })
 instance.listLoaded = true; instance.route = { projectId: 8, tab: 'documents' }
 instance.$nextTick = async () => {}; instance.scrollContainer = () => ({ scrollTop: 0 })
 await instance.onPopState()
 assert.equal(instance.project.id, 7)
 assert.equal(instance.route.tab, 'notes')
})
