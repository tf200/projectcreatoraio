import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

// Keep errors visible in the new UI without changing legacy service semantics.
async function get(path) {
 const response = await axios.get(generateUrl('/apps/projectcreatoraio/api/v1/' + path))
 return response.data
}
export const api = {
 context: () => get('projects/context'),
 list: () => get('projects/list'),
 myProjects: userId => get(`users/${encodeURIComponent(userId)}/projects`),
 project: id => get(`projects/${id}`),
}
export function errorMessage(error) {
 if (error?.response?.status === 403) return 'Je hebt geen toegang tot deze gegevens.'
 if (error?.response?.status === 404) return 'Dit project is niet beschikbaar of je hebt geen toegang.'
 return 'De gegevens konden niet worden geladen. Probeer het opnieuw.'
}
