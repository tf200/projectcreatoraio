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

	async list(boardId) {
		const response = await axios.get(this.profileUrl(boardId), { headers: this.headers() })
		return this.unwrap(response.data)
	}

	async createFromBoard(boardId, name) {
		const response = await axios.post(this.profileUrl(boardId), { name }, { headers: this.headers() })
		return this.unwrap(response.data)
	}

	async delete(profileId, boardId) {
		const response = await axios.delete(this.profileUrl(boardId, profileId), { headers: this.headers() })
		return this.unwrap(response.data)
	}

	async get(profileId, boardId) {
		const response = await axios.get(this.profileUrl(boardId, profileId), { headers: this.headers() })
		return this.unwrap(response.data)
	}

	async preview(profileId, boardId) {
		const response = await axios.post(`${this.profileUrl(boardId, profileId)}/preview`, {}, { headers: this.headers() })
		return this.unwrap(response.data)
	}

	async apply(profileId, boardId, data = {}) {
		const response = await axios.post(`${this.profileUrl(boardId, profileId)}/apply`, data, { headers: this.headers() })
		return this.unwrap(response.data)
	}

	profileUrl(boardId, profileId = null) {
		const base = `/apps/projectcreatoraio/api/v1/boards/${Number(boardId)}/profiles`
		return generateUrl(profileId === null ? base : `${base}/${encodeURIComponent(profileId)}`)
	}

}
