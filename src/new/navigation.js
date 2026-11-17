const legacyTabs = { overview: 'overview', tasks: 'deck', notes: 'notes', planning: 'timeline', documents: 'files', intake: 'cardVisibility', whiteboard: 'whiteboard', members: 'members', activity: 'activity', agenda: 'calendar' }
export const tabs = Object.keys(legacyTabs)
export function normalizeTab(value) {
 return tabs.includes(value) ? value : (tabs.find(key => legacyTabs[key] === value) || 'overview')
}
function projectId(value) {
 const raw = String(value ?? '')
 if (!/^[1-9]\d*$/.test(raw)) return null
 const id = Number(raw)
 return Number.isSafeInteger(id) ? id : null
}
export function readRoute(pathname, search) {
 if (/\/(new\/)?create\/?$/.test(pathname)) return { projectId: null, tab: 'overview', create: true }
 const match = pathname.match(/\/(\d+)\/?$/)
 return { projectId: projectId(match?.[1]), tab: normalizeTab(new URLSearchParams(search).get('tab')) }
}
export function interfaceUrl(base, route = {}, modern = true) {
 // The new interface has the app's own addresses; the old one is only at /legacy, for admins.
 const root = base.replace(/\/$/, '') + (modern ? '' : '/legacy')
 if (route.create) return root + (modern ? '/create' : '?create=1')
 const id = projectId(route.projectId)
 if (!id) return root + '/'
 const tab = normalizeTab(route.tab)
 return root + '/' + id + (tab !== 'overview' ? '?tab=' + (modern ? tab : legacyTabs[tab]) : '')
}
export function filterProjects(projects, { query = '', status = 'all', sort = 'name', client = '', scope = 'all', organization = 'all' } = {}, { isGlobalAdmin = false, isOrganizationAdmin = false, myProjectIds = [], recentProjectIds = [] } = {}) {
 const search = query.trim().toLocaleLowerCase()
 const recency = new Map(recentProjectIds.map((id, index) => [Number(id), index]))
 return projects.filter(project => [project.name, project.number, project.client_name, project.loc_city].join(' ').toLocaleLowerCase().includes(search)
  && (status === 'all' || String(project.status) === status)
  && (!client || project.client_name === client)
  && (!isOrganizationAdmin || scope !== 'my' || myProjectIds.includes(Number(project.id)))
  && (!isGlobalAdmin || organization === 'all' || String(project.organization_id) === organization))
  .sort((a, b) => {
   if (sort === 'recent') {
    const order = (recency.get(Number(a.id)) ?? Infinity) - (recency.get(Number(b.id)) ?? Infinity)
    if (order) return order
   }
   return String(a[sort === 'number' ? 'number' : 'name'] || '').localeCompare(String(b[sort === 'number' ? 'number' : 'name'] || ''), undefined, { numeric: true })
  })
}
