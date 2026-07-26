import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export class DeckService {

	static instance = null

	static getInstance() {
		if (this.instance) {
			return this.instance
		}
		this.instance = new DeckService()
		return this.instance
	}

	headers() {
		return {
			Accept: 'application/json',
			'OCS-APIRequest': 'true',
			'Content-Type': 'application/json',
		}
	}

	async getBoard(boardId) {
		// Use non-OCS endpoints to match the Deck web UI.
		const url = generateUrl(`/apps/deck/boards/${boardId}`)
		const response = await axios.get(url, { headers: this.headers() })
		return response.data
	}

	async getBoardPermissions(boardId) {
		const url = generateUrl(`/apps/deck/boards/${boardId}/permissions`)
		const response = await axios.get(url, { headers: this.headers() })
		return response.data
	}

	async listStacks(boardId) {
		const url = generateUrl(`/apps/deck/stacks/${boardId}`)
		const response = await axios.get(url, { headers: this.headers() })
		return response.data ?? []
	}

	async createStack(boardId, title, order = 999) {
		const url = generateUrl('/apps/deck/stacks')
		const response = await axios.post(url, { title, boardId: Number(boardId), order }, { headers: this.headers() })
		return response.data
	}

	async createCard(stackId, title, order = 999, description = '') {
		const url = generateUrl('/apps/deck/cards')
		const response = await axios.post(url, { title, stackId, type: 'plain', order, description }, { headers: this.headers() })
		return response.data
	}

	async reorderCard(cardId, stackId, order) {
		const url = generateUrl(`/apps/deck/cards/${cardId}/reorder`)
		const response = await axios.put(url, { stackId, order }, { headers: this.headers() })
		return response.data
	}

	async getCardPolicy(boardId) {
		const url = generateUrl(`/apps/projectcreatoraio/api/v1/boards/${boardId}/policy`)
		const response = await axios.get(url, { headers: this.headers() })
		return response.data
	}

	async enableCardPolicy(boardId) {
		const url = generateUrl(`/apps/projectcreatoraio/api/v1/boards/${boardId}/policy/enable`)
		const response = await axios.post(url, {}, { headers: this.headers() })
		return response.data
	}

	async updateCardPolicySettings(boardId, data) {
		const url = generateUrl(`/apps/projectcreatoraio/api/v1/boards/${boardId}/policy/settings`)
		const response = await axios.put(url, data, { headers: this.headers() })
		return response.data
	}

	async updateCardPolicyDefaults(boardId, data) {
		const url = generateUrl(`/apps/projectcreatoraio/api/v1/boards/${boardId}/policy/defaults`)
		const response = await axios.put(url, data, { headers: this.headers() })
		return response.data
	}

	async setCardPolicy(boardId, cardId, data) {
		const url = generateUrl(`/apps/projectcreatoraio/api/v1/boards/${boardId}/policy/cards/${cardId}`)
		const response = await axios.put(url, data, { headers: this.headers() })
		return response.data
	}

	async updateCardPolicyAction(boardId, cardId, action, data) {
		const url = generateUrl(`/apps/projectcreatoraio/api/v1/boards/${boardId}/policy/cards/${cardId}/actions/${encodeURIComponent(action)}`)
		const response = await axios.put(url, data, { headers: this.headers() })
		return response.data
	}

	async clearCardPolicy(boardId, cardId, data = {}) {
		const url = generateUrl(`/apps/projectcreatoraio/api/v1/boards/${boardId}/policy/cards/${cardId}`)
		const response = await axios.delete(url, { headers: this.headers(), data })
		return response.data
	}

	async addCardPolicyMembership(boardId, data) {
		const url = generateUrl(`/apps/projectcreatoraio/api/v1/boards/${boardId}/policy/members`)
		const response = await axios.post(url, data, { headers: this.headers() })
		return response.data
	}

	async deleteCardPolicyMembership(boardId, membershipId) {
		const url = generateUrl(`/apps/projectcreatoraio/api/v1/boards/${boardId}/policy/members/${membershipId}`)
		const response = await axios.delete(url, { headers: this.headers() })
		return response.data
	}

	async createCardPolicyRole(boardId, data) {
		const url = generateUrl(`/apps/projectcreatoraio/api/v1/boards/${boardId}/policy/roles`)
		const response = await axios.post(url, data, { headers: this.headers() })
		return response.data
	}

	async updateCardPolicyRole(boardId, roleId, data) {
		const url = generateUrl(`/apps/projectcreatoraio/api/v1/boards/${boardId}/policy/roles/${roleId}`)
		const response = await axios.put(url, data, { headers: this.headers() })
		return response.data
	}

	async deleteCardPolicyRole(boardId, roleId) {
		const url = generateUrl(`/apps/projectcreatoraio/api/v1/boards/${boardId}/policy/roles/${roleId}`)
		const response = await axios.delete(url, { headers: this.headers() })
		return response.data
	}

}
