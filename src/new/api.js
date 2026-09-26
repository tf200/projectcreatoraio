import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

// Keep errors visible in the new UI without changing legacy service semantics.
async function get(path, params) {
 const response = await axios.get(generateUrl('/apps/projectcreatoraio/api/v1/' + path), params ? { params } : undefined)
 return response.data
}
export const api = {
 context: () => get('projects/context'),
 list: () => get('projects/list'),
 myProjects: userId => get(`users/${encodeURIComponent(userId)}/projects`),
 project: id => get(`projects/${id}`),
 whiteboardActivity: (id, limit, offset) => get(`projects/${id}/whiteboard/activity?limit=${limit}&offset=${offset}`),
 activity: (id, params) => get(`projects/${id}/activity`, params),
}
export function errorMessage(error) {
 if (error?.response?.status === 403) return 'You do not have access to this data.'
 if (error?.response?.status === 404) return 'This project is unavailable or you do not have access.'
 return 'The data could not be loaded. Please try again.'
}
