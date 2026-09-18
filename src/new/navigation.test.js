import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readRoute, interfaceUrl, filterProjects } from './navigation.js'
const base = '/nextcloud/index.php/apps/projectcreatoraio'
test('new deep links and legacy aliases select the corresponding module', () => {
 assert.deepEqual(readRoute(base + '/new/projects/21', '?tab=tasks'), { projectId: 21, tab: 'tasks' })
 assert.deepEqual(readRoute(base + '/21', '?tab=cardVisibility'), { projectId: 21, tab: 'intake' })
 assert.equal(readRoute(base + '/new', '').projectId, null)
})
test('switch preserves project and translates each tab without losing subdirectory', () => {
 for (const [modern, legacy] of [['tasks','deck'],['planning','timeline'],['documents','files'],['intake','cardVisibility'],['agenda','calendar'],['notes','notes']]) {
  assert.equal(interfaceUrl(base, { projectId: 21, tab: modern }, false), base + '/21?tab=' + legacy)
  assert.equal(interfaceUrl(base, { projectId: 21, tab: legacy }, true), base + '/new/projects/21?tab=' + modern)
 }
 assert.equal(interfaceUrl(base, {projectId:null}, true), base+'/new')
})
test('rejects malformed IDs and unknown tabs rather than constructing arbitrary routes', () => {
 for (const id of [-1, 0, '1/../../', '2e3', '21.1', Number.MAX_SAFE_INTEGER+1]) {
  assert.equal(interfaceUrl(base, {projectId:id,tab:'https://evil.test'}, true), base+'/new')
 }
 assert.deepEqual(readRoute(base+'/new/projects/21','?tab=unknown'), {projectId:21,tab:'overview'})
 assert.equal(readRoute(base+'/new/projects/21evil','').projectId,null)
})
test('list matches client/location and respects status with stable sorting', () => {
 const rows=[{name:'Zulu',number:'2',client_name:'Client',loc_city:'Amsterdam',status:1},{name:'Alpha',number:'1',loc_city:'Rotterdam',status:4}]
 assert.equal(filterProjects(rows,{query:'amster',status:'all',sort:'name'})[0].name,'Zulu')
 assert.equal(filterProjects(rows,{query:'client',status:'4',sort:'name'}).length,0)
 assert.deepEqual(filterProjects(rows,{query:'',status:'all',sort:'name'}).map(p=>p.name),['Alpha','Zulu'])
 assert.equal(rows[0].name,'Zulu')
})
test('shelf and picker combine membership, organization, client and search filters', () => {
 const rows = [
  { id: 1, name: 'Canal', client_name: 'Alice', organization_id: 7, status: 1 },
  { id: 2, name: 'Canal annex', client_name: 'Bob', organization_id: 7, status: 1 },
  { id: 3, name: 'Canal studio', client_name: 'Alice', organization_id: 8, status: 1 },
 ]
 assert.deepEqual(filterProjects(rows, { scope: 'my' }, { isOrganizationAdmin: true, myProjectIds: [2] }).map(p => p.id), [2])
 assert.deepEqual(filterProjects(rows, { organization: '7', client: 'Alice', query: 'canal' }, { isGlobalAdmin: true }).map(p => p.id), [1])
 assert.deepEqual(filterProjects(rows, { client: 'Missing' }).map(p => p.id), [])
})
test('recent sorting puts visited projects first without mutating the source', () => {
 const rows = [{ id: 1, name: 'Alpha' }, { id: 2, name: 'Beta' }, { id: 3, name: 'Gamma' }]
 assert.deepEqual(filterProjects(rows, { sort: 'recent' }, { recentProjectIds: [3, 1] }).map(p => p.id), [3, 1, 2])
 assert.deepEqual(rows.map(p => p.id), [1, 2, 3])
})
