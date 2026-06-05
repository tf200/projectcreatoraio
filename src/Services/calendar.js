import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export class CalendarService {

	static instance = null

	/**
	 * @return {CalendarService}
	 */
	static getInstance() {
		if (!CalendarService.instance) {
			CalendarService.instance = new CalendarService()
		}
		return CalendarService.instance
	}

	/**
	 * Get all calendar events (proposals and meetings) for a project
	 * @param {number|string} projectId The ID of the project
	 * @param {object} options Query options
	 * @param {number} [options.limit] The number of items to return
	 * @param {number} [options.offset] The number of items to skip
	 * @return {Promise<Array>}
	 */
	async getProjectEvents(projectId, { limit = 20, offset = 0 } = {}) {
		if (projectId === null || projectId === undefined || projectId === '') {
			return []
		}

		const url = generateUrl(`/apps/calendar/proposal/project/${projectId}`)

		const response = await axios.get(url, {
			params: {
				limit,
				offset,
			},
			headers: {
				'OCS-APIRequest': 'true',
			},
		})

		return response?.data ?? []
	}

}
