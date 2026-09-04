import { generateUrl } from "@nextcloud/router";
import { Project } from "../Models/project";
import axios from "axios";

export class ProjectsService {

    static instance = null;
    constructor() {}

    /**
     * 
     * @returns {ProjectsService}
     */
    static getInstance() {
        if(this.instance) {
            return this.instance;
        }

        this.instance = new ProjectsService();
        return this.instance;
    }

    /**
     * 
     * @param {Project} project 
     * @returns {any}
     */
    async create(project) {
        const url = generateUrl('/apps/projectcreatoraio/api/v1/projects');
        const response = await axios.post(url, project.toJson(), {
            headers: {
                'OCS-APIRequest': 'true',
                'Content-Type': 'application/json'
            }
        });

        return response.data;
    }

    /**
     * 
     * @returns {Promise<any[]>}
     */
    async list() {
        try {
            const url = generateUrl('/apps/projectcreatoraio/api/v1/projects/list')
            const response = await axios.get(url, {
                headers: {
                    'OCS-APIRequest': 'true',
                    'Content-Type': 'application/json'
                }
            });

            return response.data ?? [];

        } catch (e) {
            console.error('Failed to fetch projects:', e);
            return [];
        }
    }

    /**
     *
     * @returns {Promise<{userId: string, isGlobalAdmin: boolean, organizationRole: string|null, organizationId: number|null}|null>}
     */
    async context() {
        const url = generateUrl('/apps/projectcreatoraio/api/v1/projects/context')
        const response = await axios.get(url, {
            headers: {
                'OCS-APIRequest': 'true',
                'Content-Type': 'application/json'
            }
        });

        return response.data ?? null;
    }

    /**
     *
     * @param {number} projectId
     * @returns {Promise<any|null>}
     */
    async get(projectId) {
        try {
            const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}`)
            const response = await axios.get(url, {
                headers: {
                    'OCS-APIRequest': 'true',
                    'Content-Type': 'application/json'
                }
            });

            return response.data ?? null;
        } catch (e) {
            console.error('Failed to fetch project details:', e);
            return null;
        }
    }

    /**
     *
     * @param {string} token
     * @returns {Promise<any|null>}
     */
    async getByTalkConversationToken(token) {
        try {
            const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/talk/${encodeURIComponent(token)}`)
            const response = await axios.get(url, {
                headers: {
                    'OCS-APIRequest': 'true',
                    'Content-Type': 'application/json'
                }
            })

            return response.data ?? null
        } catch (e) {
            console.error('Failed to fetch project by talk conversation token:', e)
            return null
        }
    }

    /**
     * Delete a project.
     *
     * @param {number} projectId
     * @returns {Promise<boolean>}
     */
    async delete(projectId) {
        const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}`)
        const response = await axios.delete(url, {
            headers: {
                'OCS-APIRequest': 'true',
                'Content-Type': 'application/json',
            },
        })

        return response?.data?.deleted === true
    }

    /**
     * Get Combi card-visibility questionnaire state for a project.
     *
     * @param {number} projectId
     * @returns {Promise<any|null>}
     */
    async getCardVisibility(projectId) {
        try {
            const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/card-visibility`)
            const response = await axios.get(url, {
                headers: {
                    'OCS-APIRequest': 'true',
                    'Content-Type': 'application/json',
                },
            })

            return response.data ?? null
        } catch (e) {
            console.error('Failed to fetch card visibility config:', e)
            throw e
        }
    }

    /**
     * Update Combi card-visibility questionnaire answers for a project.
     *
     * @param {number} projectId
     * @param {{cv_object_ownership?: number|null, cv_trace_ownership?: number|null, cv_building_type?: number|null, cv_avp_location?: number|null}} payload
     * @returns {Promise<any|null>}
     */
    async updateCardVisibility(projectId, payload) {
        try {
            const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/card-visibility`)
            const response = await axios.put(url, payload, {
                headers: {
                    'OCS-APIRequest': 'true',
                    'Content-Type': 'application/json',
                },
            })

            return response.data ?? null
        } catch (e) {
            console.error('Failed to update card visibility config:', e)
            throw e
        }
    }

    /**
     * List project members.
     *
     * @param {number} projectId
     * @returns {Promise<Array<object>>}
     */
    async listMembers(projectId) {
        const result = await this.listMembersWithRoles(projectId)
        return result.members
    }

    /**
     * List members together with the board's available functional roles.
     *
     * @param {number} projectId
     * @returns {Promise<{members:Array<object>,functionalRoles:Array<{key:string,name:string}>}>}
     */
    async listMembersWithRoles(projectId) {
        const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/members`)
        const response = await axios.get(url, {
            headers: {
                'OCS-APIRequest': 'true',
                'Content-Type': 'application/json',
            },
        })

        return {
            members: response?.data?.members ?? [],
            functionalRoles: response?.data?.functionalRoles ?? [],
        }
    }

	/**
	 * Get the effective Deck card access summary for project members.
	 *
	 * @param {number} projectId Project ID.
	 * @return {Promise<object|null>} Deck access summary.
	 */
	async getDeckAccessSummary(projectId) {
		const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/deck-access-summary`)
		const response = await axios.get(url, {
			headers: {
				'OCS-APIRequest': 'true',
				'Content-Type': 'application/json',
			},
		})

		return response?.data ?? null
	}

    /**
     * Add a member to a project.
     *
     * @param {number} projectId
     * @param {string} userId
     * @param {string[]} drasciRoles
     * @param {string[]} functionalRoleKeys
     * @returns {Promise<{added:boolean,alreadyMember:boolean,member:object}|null>}
     */
    async addMember(projectId, userId, drasciRoles, functionalRoleKeys) {
        const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/members`)
        const response = await axios.post(url, { userId, drascivsRoles: drasciRoles, functionalRoleKeys }, {
            headers: {
                'OCS-APIRequest': 'true',
                'Content-Type': 'application/json',
            },
        })

        return response?.data ?? null
    }

    /**
     * Update either role dimension for a project member.
     *
     * @param {number} projectId
     * @param {string} userId
     * @param {string[]|undefined} drasciRoles
     * @param {string[]|undefined} functionalRoleKeys
     * @returns {Promise<{member:object}|null>}
     */
    async updateMemberRole(projectId, userId, drasciRoles = undefined, functionalRoleKeys = undefined) {
        const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/members/${encodeURIComponent(userId)}/role`)
        const payload = {}
        if (drasciRoles !== undefined) {
            payload.drascivsRoles = drasciRoles
        }
        if (functionalRoleKeys !== undefined) {
            payload.functionalRoleKeys = functionalRoleKeys
        }
        const response = await axios.put(url, payload, {
            headers: {
                'OCS-APIRequest': 'true',
                'Content-Type': 'application/json',
            },
        })

        return response?.data ?? null
    }

    /**
     * Search users in the current organization (or specific org for global admins).
     *
     * @param {string} query
     * @param {number|null} organizationId
     * @returns {Promise<Array<{id:string,user:string,label:string,displayName:string,subname:string}>>}
     */
    async searchUsers(query, organizationId = null) {
        try {
            const url = generateUrl('/apps/projectcreatoraio/api/v1/users/search')
            const params = new URLSearchParams()
            params.append('search', query)
            if (organizationId !== null && Number.isFinite(Number(organizationId))) {
                params.append('organizationId', String(organizationId))
            }

            const response = await axios.get(`${url}?${params.toString()}`, {
                headers: {
                    'OCS-APIRequest': 'true',
                    'Content-Type': 'application/json',
                },
            })

            return response?.data?.users ?? []
        } catch (e) {
            console.error('Failed to search organization users:', e)
            return []
        }
    }

    /**
     *
     * @param {number} projectId
     * @returns {Promise<{shared: any[], private: any[]}>}
     */
    async getFiles(projectId) {
        try {
            const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/files`)
            const response = await axios.get(url, {
                headers: {
                    'OCS-APIRequest': 'true',
                    'Content-Type': 'application/json'
                }
            });

            // API may return either { shared, private } or { files: { shared, private } }
            const payload = response.data ?? null
            if (payload && typeof payload === 'object' && payload.files) {
                return payload.files
            }

            return payload ?? { shared: [], private: [] };
        } catch (e) {
            console.error('Failed to fetch project files:', e);
            return { shared: [], private: [] };
        }
    }

	/**
	 * List active OCR document types available to a project.
	 *
	 * @param {number} projectId
	 * @returns {Promise<Array<object>>}
	 */
	async listProjectDocumentTypes(projectId) {
		try {
			const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/ocr/document-types`)
			const response = await axios.get(url, {
				headers: {
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
			})

			return response?.data?.document_types ?? []
		} catch (e) {
			console.error('Failed to list project OCR document types:', e)
			throw e
		}
	}

	/**
	 * Assign an OCR document type to a project file.
	 *
	 * @param {number} projectId
	 * @param {number} fileId
	 * @param {number} documentTypeId
	 * @returns {Promise<object|null>}
	 */
	async assignFileDocumentType(projectId, fileId, documentTypeId) {
		const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/files/${fileId}/ocr/document-type`)
		const response = await axios.put(url, {
			document_type_id: documentTypeId,
		}, {
			headers: {
				'OCS-APIRequest': 'true',
				'Content-Type': 'application/json',
			},
		})

		return response?.data ?? null
	}

	/**
	 * Get OCR processing information for a project file.
	 *
	 * @param {number} projectId
	 * @param {number} fileId
	 * @returns {Promise<object|null>}
	 */
	async getFileProcessing(projectId, fileId) {
		try {
			const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/files/${fileId}/ocr`)
			const response = await axios.get(url, {
				headers: {
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
			})

			return response?.data ?? null
		} catch (e) {
			if (e?.response?.status === 404) {
				return null
			}
			console.error('Failed to fetch file OCR processing:', e)
			throw e
		}
	}

	/**
	 * Reprocess OCR for a project file with an existing document type assignment.
	 *
	 * @param {number} projectId
	 * @param {number} fileId
	 * @returns {Promise<object|null>}
	 */
	async reprocessFileProcessing(projectId, fileId) {
		const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/files/${fileId}/ocr/reprocess`)
		const response = await axios.post(url, {}, {
			headers: {
				'OCS-APIRequest': 'true',
				'Content-Type': 'application/json',
			},
		})

		return response?.data ?? null
	}

	/**
	 * Update extracted OCR field values manually for a project file.
	 *
	 * @param {number} projectId
	 * @param {number} fileId
	 * @param {Record<string, string|null>} fields
	 * @returns {Promise<object|null>}
	 */
	async updateFileExtractedFields(projectId, fileId, fields) {
		const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/files/${fileId}/ocr/extracted`)
		const response = await axios.put(url, {
			fields,
		}, {
			headers: {
				'OCS-APIRequest': 'true',
				'Content-Type': 'application/json',
			},
		})

		return response?.data ?? null
	}

	async getFileSigningRequest(projectId, fileId) {
		try {
			const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/files/${fileId}/signing`)
			const response = await axios.get(url, {
				headers: {
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
			})
			return response.data ?? null
		} catch (e) {
			console.error('Failed to fetch signing request:', e)
			return null
		}
	}

	async createFileSigningRequest(projectId, fileId, payload) {
		try {
			const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/files/${fileId}/signing/request`)
			const response = await axios.post(url, payload, {
				headers: {
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
			})
			return response.data ?? null
		} catch (e) {
			console.error('Failed to create signing request:', e)
			throw e
		}
	}

	/**
	 *
	 * @param {number} projectId
	 * @returns {Promise<{fileId:number,name:string,mimetype:string,size:number,mtime:number,path:string}|null>}
	 */
	async getWhiteboardInfo(projectId) {
		try {
			const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/whiteboard`)
			const response = await axios.get(url, {
				headers: {
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
			})
			return response.data ?? null
		} catch (e) {
			console.error('Failed to fetch project whiteboard info:', e)
			return null
		}
	}

	/**
	 *
	 * @param {number} projectId
	 * @param {number} limit
	 * @param {number} offset
	 * @param {string|null} source
	 * @param {string|null} cursor
	 * @returns {Promise<{events:Array,hasMore:boolean,nextCursor:string|null}>}
	 */
	async getActivity(projectId, limit = 20, offset = 0, source = null, cursor = null) {
		try {
			const params = { limit }
			if (cursor) {
				params.cursor = cursor
			} else if (offset > 0) {
				params.offset = offset
			}
			if (source) {
				params.source = source
			}
			const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/activity`)
			const response = await axios.get(url, {
				params,
				headers: {
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
			})
			return response.data ?? { events: [], hasMore: false, nextCursor: null }
		} catch (e) {
			console.error('Failed to fetch activity:', e)
			return { events: [], hasMore: false, nextCursor: null }
		}
	}

	/**
	 *
	 * @param {number} projectId
	 * @param {number} limit
	 * @param {number} offset
	 * @returns {Promise<{events:Array,hasMore:boolean}>}
	 */
	async getWhiteboardActivity(projectId, limit = 20, offset = 0) {
		try {
			const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/whiteboard/activity`, { limit, offset })
			const response = await axios.get(url, {
				headers: {
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
			})
			return response.data ?? { events: [], hasMore: false }
		} catch (e) {
			console.error('Failed to fetch whiteboard activity:', e)
			return { events: [], hasMore: false }
		}
	}

	/**
	 * Update a project (partial fields).
	 *
	 * @param {number} projectId
	 * @param {object} payload
	 * @returns {Promise<any|null>}
	 */
	async update(projectId, payload) {
		try {
			const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}`)
			const response = await axios.put(url, payload, {
				headers: {
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
			})
			return response.data ?? null
		} catch (e) {
			console.error('Failed to update project:', e)
			throw e
		}
	}

	/**
	 * Request a project export (queues background job).
	 *
	 * @param {number} projectId
	 * @returns {Promise<{status: string, message: string}|null>}
	 */
	async requestDownload(projectId) {
		try {
			const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/download`)
			const response = await axios.post(url, {}, {
				headers: {
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
			})
			return response.data ?? null
		} catch (e) {
			console.error('Failed to request project export:', e)
			throw e
		}
	}

	/**
	 * Download the generated export ZIP for a project.
	 *
	 * @param {number} projectId
	 */
	downloadExport(projectId) {
		const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/download`)
		window.location.href = url
	}

	/**
	 * Update project notes.
	 *
	 * @param {number} projectId
	 * @param {{public_note?: string, private_note?: string}} payload
	 * @returns {Promise<{public_note: string, private_note: string, private_note_available: boolean}|null>}
	 */
	async updateNotes(projectId, payload) {
		try {
			const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/notes`)
			const response = await axios.put(url, payload, {
				headers: {
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
			})
			return response.data ?? null
		} catch (e) {
			console.error('Failed to update project notes:', e)
			throw e
		}
	}

	/**
	 * List notes for a project with pagination.
	 *
	 * @param {number} projectId
	 * @param {{visibility?: string, noteType?: string, page?: number, limit?: number}} [options]
	 * @returns {Promise<{notes: array, total: number, page: number, limit: number, private_available: boolean}|null>}
	 */
	async listNotes(projectId, { visibility = 'public', noteType = '', page = 1, limit = 12 } = {}) {
		try {
			const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/notes/list`)
			const response = await axios.get(url, {
				params: { visibility, noteType, page, limit },
				headers: {
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
			})
			return response.data ?? null
		} catch (e) {
			console.error('Failed to list project notes:', e)
			return null
		}
	}

	/**
	 * List card comments for a project with pagination.
	 *
	 * @param {number} projectId
	 * @param {{page?: number, limit?: number}} [options]
	 * @returns {Promise<{comments: array, total: number, page: number, limit: number}|null>}
	 */
	async listCardComments(projectId, { page = 1, limit = 20 } = {}) {
		try {
			const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/card-comments`)
			const response = await axios.get(url, {
				params: { page, limit },
				headers: {
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
			})
			return response.data ?? null
		} catch (e) {
			console.error('Failed to list card comments:', e)
			return null
		}
	}

	/**
	 * Get chat messages for a project's Talk conversation.
	 *
	 * @param {number} projectId
	 * @param {{limit?: number, offset?: number}} [options]
	 * @returns {Promise<{messages: array, hasMore: boolean, nextOffset: number}|null>}
	 */
	async getChatMessages(projectId, { limit = 50, offset = 0 } = {}) {
		try {
			const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/chat-messages`)
			const response = await axios.get(url, {
				params: { limit, offset },
				headers: {
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
			})
			return response.data ?? null
		} catch (e) {
			console.error('Failed to fetch chat messages:', e)
			return null
		}
	}

	/**
	 * List all project direct chats for the current user.
	 *
	 * @param {number} projectId
	 * @returns {Promise<any[]>}
	 */
	async listDirectChats(projectId) {
		try {
			const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/direct-chats`)
			const response = await axios.get(url, {
				headers: {
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
			})
			return response.data ?? []
		} catch (e) {
			console.error('Failed to list direct chats:', e)
			return []
		}
	}

	/**
	 * Get or lazily create a direct chat with a project member.
	 *
	 * @param {number} projectId
	 * @param {string} targetUserId
	 * @returns {Promise<object|null>}
	 */
	async getOrCreateDirectChat(projectId, targetUserId) {
		try {
			const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/direct-chats/${encodeURIComponent(targetUserId)}`)
			const response = await axios.post(url, {}, {
				headers: {
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
			})
			return response.data ?? null
		} catch (e) {
			console.error('Failed to get or create direct chat:', e)
			throw e
		}
	}

	/**
	 * Fetch messages for a project direct chat.
	 *
	 * @param {number} projectId
	 * @param {string} targetUserId
	 * @param {{limit?: number, offset?: number}} [options]
	 * @returns {Promise<{messages: array, hasMore: boolean, nextOffset: number}|null>}
	 */
	async getDirectChatMessages(projectId, targetUserId, { limit = 50, offset = 0 } = {}) {
		try {
			const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/direct-chats/${encodeURIComponent(targetUserId)}/messages`)
			const response = await axios.get(url, {
				params: { limit, offset },
				headers: {
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
			})
			return response.data ?? null
		} catch (e) {
			console.error('Failed to fetch direct chat messages:', e)
			return null
		}
	}

	/**
	 * Get a single note.
	 *
	 * @param {number} projectId
	 * @param {number} noteId
	 * @returns {Promise<object|null>}
	 */
	async getNote(projectId, noteId) {
		try {
			const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/notes/${noteId}`)
			const response = await axios.get(url, {
				headers: {
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
			})
			return response.data ?? null
		} catch (e) {
			console.error('Failed to get note:', e)
			return null
		}
	}

	/**
	 * Create a new note.
	 *
	 * @param {number} projectId
	 * @param {{title: string, content: string, visibility: 'public'|'private', noteType?: string}} payload
	 * @returns {Promise<object|null>}
	 */
	async createNote(projectId, payload) {
		try {
			const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/notes`)
			const response = await axios.post(url, payload, {
				headers: {
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
			})
			return response.data ?? null
		} catch (e) {
			console.error('Failed to create note:', e)
			throw e
		}
	}

	/**
	 * Update a note.
	 *
	 * @param {number} projectId
	 * @param {number} noteId
	 * @param {{title?: string, content?: string, noteType?: string}} payload
	 * @returns {Promise<object|null>}
	 */
	async updateNote(projectId, noteId, payload) {
		try {
			const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/notes/${noteId}`)
			const response = await axios.put(url, payload, {
				headers: {
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
			})
			return response.data ?? null
		} catch (e) {
			console.error('Failed to update note:', e)
			throw e
		}
	}

	/**
	 * Delete a note.
	 *
	 * @param {number} projectId
	 * @param {number} noteId
	 * @returns {Promise<{deleted: boolean}|null>}
	 */
	async deleteNote(projectId, noteId) {
		try {
			const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${projectId}/notes/${noteId}`)
			const response = await axios.delete(url, {
				headers: {
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
			})
			return response.data ?? null
		} catch (e) {
			console.error('Failed to delete note:', e)
			throw e
		}
	}

    /**
     * 
     * @param {string} userId 
     * 
     * @returns {Promise<any[]>}
     */
    async fetchProjectsByUser(userId) {
        try {
            const url = generateUrl(`/apps/projectcreatoraio/api/v1/users/${userId}/projects`);
            const response = await axios.get(url, {
                headers: {
                    'OCS-APIRequest': 'true',
                    'Content-Type': 'application/json'
                }
            });
            return response.data ?? [];
        } catch(e) {
            console.error('Failed to fetch user projects', e);
            return [];
        }
    }

    /**
     * get projects by name
     * @param {string} query
     */
    async search(query) {
        try {
            const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/search`);
            
            const params = new URLSearchParams();
            params.append('search', query);
    
            const response = await axios.get(`${url}?${params.toString()}`, {
                headers: {
                    'OCS-APIRequest': 'true',
                    'Content-Type': 'application/json'
                }
            });
    
            return response.data ?? [];

        } catch(e) {
            console.error("Failed to search projects", e);
            return [];
        }
    }

    /**
     * Get organization default PDF info
     * @param {number} organizationId
     */
    async getOrganizationPdfInfo(organizationId) {
        const url = generateUrl(`/apps/projectcreatoraio/api/v1/organizations/${organizationId}/default-pdf`);
        const response = await axios.get(url, {
            headers: {
                'OCS-APIRequest': 'true',
                'Content-Type': 'application/json'
            }
        });
        return response.data;
    }

    /**
     * Upload organization default PDF
     * @param {number} organizationId
     * @param {File} file
     * @param {string} fileName
     */
    async uploadOrganizationPdf(organizationId, file, fileName) {
        const url = generateUrl(`/apps/projectcreatoraio/api/v1/organizations/${organizationId}/default-pdf`);
        const formData = new FormData();
        formData.append('pdf', file);
        formData.append('fileName', fileName);
        const response = await axios.post(url, formData, {
            headers: {
                'OCS-APIRequest': 'true',
                'Content-Type': 'multipart/form-data'
            }
        });
        return response.data;
    }

    /**
     * Delete organization default PDF (reset to fallback)
     * @param {number} organizationId
     */
    async deleteOrganizationPdf(organizationId) {
        const url = generateUrl(`/apps/projectcreatoraio/api/v1/organizations/${organizationId}/default-pdf`);
        const response = await axios.delete(url, {
            headers: {
                'OCS-APIRequest': 'true',
                'Content-Type': 'application/json'
            }
        });
        return response.data;
    }
}
