import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
const sections = ['notes', 'activity', 'tasks', 'planning', 'files']
export function emptyOverview() { return Object.fromEntries(sections.map(key => [key, { loading: true, error: '', data: null }])) }
async function get(url, params = {}) { return (await axios.get(generateUrl(url), { params })).data }
function array(value) { if (!Array.isArray(value)) throw new Error('Invalid overview response'); return value }
export async function readOverviewSection(key, project, context) {
 const base = `/apps/projectcreatoraio/api/v1/projects/${project.id}`
 switch (key) {
 case 'notes': {
  const pages = await Promise.all(['decision', 'risk_blocker', 'action_point'].map(noteType => get(base + '/notes/list', { visibility: 'public', noteType, limit: 4, page: 1 })))
  const notes = pages.flatMap(page => array(page.notes)).sort((a, b) => new Date(b.updatedAt) - new Date(a.updatedAt))
  return { notes: notes.slice(0, 4), risks: Number(pages[1].total) }
 }
 case 'activity': return array((await get(base + '/activity', { limit: 3 })).events)
 case 'tasks':
  if (context.features?.deck === false || !project.boardId) throw new Error('Tasks are not available for this project.')
  return array(await get(`/apps/deck/stacks/${project.boardId}`))
 case 'planning': {
  if (context.features?.deck === false) throw new Error('Planning summary requires Deck.')
  const data = await get(base + '/timeline/summary')
  if (!data?.processCompleted || !Array.isArray(data.phases) || data.processCompleted.status === 'error') throw new Error('Planning summary could not be loaded.')
  return data
 }
 case 'files': {
  const data = await get(base + '/files'), tree = data?.files ?? data
  array(tree?.shared); array(tree?.private); return tree
 }
 }
}
export default {
 data: () => ({ overview: emptyOverview(), overviewRequests: {} }),
 methods: {
  loadOverview() { this.overview = emptyOverview(); sections.forEach(key => this.reloadOverview(key)) },
  async reloadOverview(key) {
   if (!sections.includes(key) || !this.project) return
   const project = this.project, version = this.requestVersion, token = (this.overviewRequests[key] || 0) + 1
   this.overviewRequests[key] = token
   this.$set(this.overview, key, { loading: true, error: '', data: null })
   const current = () => version === this.requestVersion && this.project?.id === project.id && this.overviewRequests[key] === token
   try {
    const data = await readOverviewSection(key, project, this.context)
    if (current()) this.$set(this.overview, key, { loading: false, error: '', data })
   } catch (error) {
    if (current()) this.$set(this.overview, key, { loading: false, error: error.response?.status === 403 ? 'You do not have access to this information.' : 'This summary is unavailable. Try again or open the full section.', data: null })
   }
  },
 },
}
