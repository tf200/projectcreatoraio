import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'
import { readRoute, interfaceUrl, normalizeTab } from './navigation.js'
const script = readFileSync(new URL('./NewApp.vue', import.meta.url), 'utf8').match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '').replace('export default', 'globalThis.component =')
function app(api) {
 const env = { api, readRoute, interfaceUrl, normalizeTab, generateUrl: x => x, location: { pathname: '/apps/projectcreatoraio/new', search: '' }, NcContent: {}, NcAppContent: {}, ProjectList: {}, ProjectHeader: {}, NewOverview: {}, ProjectModule: {}, errorMessage: () => 'error' }
 vm.runInNewContext(script, env)
 const component = env.component
 const instance = { ...component.data(), context: { userId: 'alice', organizationId: 1 } }
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
