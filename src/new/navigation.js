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
 const match = pathname.match(/\/(\d+)\/?$/)
 return { projectId: projectId(match?.[1]), tab: normalizeTab(new URLSearchParams(search).get('tab')) }
}
export function interfaceUrl(base, route = {}, modern = true) {
 const id = projectId(route.projectId)
 const tab = normalizeTab(route.tab)
 let url = base.replace(/\/$/, '') + (modern ? '/new' : '')
 if (id) url += (modern ? '/projects/' : '/') + id
 if (id && tab !== 'overview') url += '?tab=' + (modern ? tab : legacyTabs[tab])
 return url
}
export function filterProjects(projects, { query = '', status = 'all', sort = 'name' } = {}) {
 const search = query.trim().toLocaleLowerCase()
 return projects.filter(project => [project.name, project.number, project.client_name, project.loc_city].join(' ').toLocaleLowerCase().includes(search)
  && (status === 'all' || String(project.status) === status))
  .sort((a, b) => String(a[sort === 'number' ? 'number' : 'name'] || '').localeCompare(String(b[sort === 'number' ? 'number' : 'name'] || ''), undefined, { numeric: true }))
}
