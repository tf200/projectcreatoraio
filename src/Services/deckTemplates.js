import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export class DeckTemplatesService {

	static instance = null

	static getInstance() {
		if (this.instance) return this.instance
		this.instance = new DeckTemplatesService()
		return this.instance
	}

	headers() {
		return {
			Accept: 'application/json',
			'OCS-APIRequest': 'true',
			'Content-Type': 'application/json',
		}
	}

	unwrap(data) {
		return data?.ocs?.data || data
	}

	async list(boardId = null) {
		return []
	}

	async createFromBoard(boardId, name) {
		return { id: 1, name }
	}

	async delete(templateId, boardId = null) {
		return { success: true }
	}

	async get(templateId, boardId) {
		return { id: templateId, boardId, name: 'Default Template', rules: [] }
	}

}
