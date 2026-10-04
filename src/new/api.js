import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

// Keep errors visible in the new UI without changing legacy service semantics.
async function get(path, params) {
	const response = await axios.get(generateUrl('/apps/projectcreatoraio/api/v1/' + path), params ? { params } : undefined)
	return response.data
}
async function send(method, path, data) {
	const response = await axios[method](generateUrl('/apps/projectcreatoraio/api/v1/' + path), data)
	return response.data
}
export const api = {
	context: () => get('projects/context'),
	list: () => get('projects/list'),
	myProjects: userId => get(`users/${encodeURIComponent(userId)}/projects`),
	project: id => get(`projects/${id}`),
	whiteboardActivity: (id, limit, offset) => get(`projects/${id}/whiteboard/activity?limit=${limit}&offset=${offset}`),
	activity: (id, params) => get(`projects/${id}/activity`, params),
	members: id => get(`projects/${id}/members`),
	addMember: (id, userId, drascivsRoles, functionalRoleKeys) => send('post', `projects/${id}/members`, { userId, drascivsRoles, functionalRoleKeys }),
	updateMemberRoles: (id, userId, drascivsRoles, functionalRoleKeys) => send('put', `projects/${id}/members/${encodeURIComponent(userId)}/role`, { drascivsRoles, functionalRoleKeys }),
	searchUsers: (search, organizationId) => get('users/search', organizationId ? { search, organizationId } : { search }),
	// The plan limit a new project counts against, and the create request itself.
	allowance: organizationId => get('projects/allowance', organizationId ? { organizationId } : undefined),
	createProject: payload => send('post', 'projects', payload),
	// The header's More menu: edit, export and delete, as the current interface does them.
	updateProject: (id, payload) => send('put', `projects/${id}`, payload),
	requestExport: id => send('post', `projects/${id}/download`, {}),
	deleteProject: async id => (await axios.delete(generateUrl(`/apps/projectcreatoraio/api/v1/projects/${id}`))).data,
	// Deck's own read of a board's columns with their cards (labels, dates, dependencies).
	deckStacks: async boardId => (await axios.get(generateUrl(`/apps/deck/stacks/${boardId}`))).data,
}
// A mutation's own message (a role rule, a membership limit) beats a generic one.
export function actionMessage(error, fallback) {
	const message = error?.response?.data?.message
	return typeof message === 'string' && message.trim() ? message : fallback
}
export function errorMessage(error) {
	if (error?.response?.status === 403) return 'You do not have access to this data.'
	if (error?.response?.status === 404) return 'This project is unavailable or you do not have access.'
	return 'The data could not be loaded. Please try again.'
}
