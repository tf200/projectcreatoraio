<template>
	<div class="whiteboard-board">
		<div class="whiteboard-board__top">
			<div class="whiteboard-board__meta">
				<div class="whiteboard-board__title">
					<span class="whiteboard-board__title-text">Whiteboard</span>
					<span v-if="whiteboardInfo" class="whiteboard-board__badge">#{{ whiteboardInfo.fileId }}</span>
				</div>
				<div class="whiteboard-board__subtitle">
					{{ opensInPlace ? 'Autosaves while you work.' : 'Opens in a new window and autosaves while you work.' }}
				</div>
			</div>
			<div class="whiteboard-board__actions">
				<NcButton type="primary" :disabled="!canOpenWhiteboard" @click="openPopout">
					<template #icon>
						<ArrowExpand v-if="openMode === 'overlay'" :size="18" />
						<EyeOutline v-else :size="18" />
					</template>
					{{ openMode === 'overlay' ? 'Focus mode' : 'Open whiteboard' }}
				</NcButton>
				<NcButton type="secondary" :disabled="!whiteboardInfo" @click="openInFiles">
					<template #icon>
						<OpenInNew :size="18" />
					</template>
					Open in Files
				</NcButton>
				<NcButton type="secondary" :disabled="loading" @click="reload">
					<template #icon>
						<Refresh :size="18" />
					</template>
					Reload
				</NcButton>
			</div>
		</div>

		<div class="whiteboard-board__frame">
			<div v-if="error" class="whiteboard-board__error">
				{{ error }}
			</div>
			<div v-else-if="loading" class="whiteboard-board__loading">
				<NcLoadingIcon :size="32" />
				<span>Loading whiteboard...</span>
			</div>
			<div v-else-if="!handler" class="whiteboard-board__hint">
				Preparing whiteboard preview...
			</div>
			<div v-else class="whiteboard-board__preview-wrap" @pointerdown="onInlineInteraction">
				<component
					:is="handler.component"
					v-if="!inlineSuspended"
					:key="previewKey + ':' + String(whiteboardInfo.fileId)"
					class="whiteboard-board__embedded"
					:filename="whiteboardInfo.path"
					:fileid="whiteboardInfo.fileId"
					:basename="whiteboardInfo.name"
					:source="previewSource"
					:is-embedded="!inlineEditing"
				/>
				<button
					type="button"
					class="whiteboard-board__preview-overlay"
					:disabled="!canOpenWhiteboard"
					@click="openPopout">
					<span class="whiteboard-board__preview-overlay-label">Open whiteboard</span>
				</button>
			</div>
		</div>

		<WhiteboardActivity
			v-if="normalizedProjectId"
			:project-id="normalizedProjectId"
			:reader="activityReader" />
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

import ArrowExpand from 'vue-material-design-icons/ArrowExpand.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

import { generateRemoteUrl, generateUrl } from '@nextcloud/router'
import { ProjectsService } from '../../Services/projects'
import WhiteboardActivity from './WhiteboardActivity.vue'

const projectsService = ProjectsService.getInstance()

export default {
	name: 'WhiteboardBoard',
	components: {
		NcButton,
		NcLoadingIcon,
		ArrowExpand,
		EyeOutline,
		OpenInNew,
		Refresh,
		WhiteboardActivity,
	},
	props: {
		projectId: {
			type: [String, Number],
			default: null,
		},
		userId: {
			type: String,
			default: '',
		},
		// Opt-in for the modern interface. The defaults keep the current
		// interface on its preview-plus-popout behaviour.
		inlineEditing: {
			type: Boolean,
			default: false,
		},
		openMode: {
			type: String,
			default: 'popout',
			validator: value => ['popout', 'overlay'].includes(value),
		},
		activityReader: {
			type: Function,
			default: null,
		},
	},
	data() {
		return {
			loading: false,
			syncing: false,
			error: '',
			whiteboardInfo: null,
			handler: null,
			viewerReady: false,
			previewKey: 0,
			autosaveTimer: null,
			lastSyncAttemptAt: 0,
			isPopoutWindow: false,
			overlayOpen: false,
		}
	},
	computed: {
		canOpenWhiteboard() {
			return !!this.normalizedProjectId && !this.syncing
		},
		opensInPlace() {
			return this.isPopoutWindow || this.openMode === 'overlay'
		},
		// One live board per file: the overlay and an editable inline board
		// would otherwise share the same IndexedDB snapshot.
		inlineSuspended() {
			return this.inlineEditing && this.overlayOpen
		},
		normalizedProjectId() {
			if (this.projectId === null || this.projectId === undefined || this.projectId === '') {
				return null
			}
			return String(this.projectId)
		},
		davSource() {
			if (!this.whiteboardInfo || !this.userId) {
				return null
			}
			const raw = String(this.whiteboardInfo.path || '')
			const relative = raw.startsWith('/') ? raw.slice(1) : raw
			const encodedPath = relative
				.split('/')
				.filter(Boolean)
				.map(encodeURIComponent)
				.join('/')
			return generateRemoteUrl(`dav/files/${encodeURIComponent(this.userId)}/${encodedPath}`)
		},
		previewSource() {
			if (!this.davSource) {
				return null
			}
			const version = encodeURIComponent(String(this.whiteboardInfo?.mtime || 0))
			return `${this.davSource}?v=${version}`
		},
	},
	watch: {
		normalizedProjectId: {
			immediate: true,
			handler() {
				this.initialize()
			},
		},
	},
	created() {
		try {
			const params = new URLSearchParams(window.location.search || '')
			this.isPopoutWindow = params.get('popout') === 'whiteboard'
		} catch (e) {
			this.isPopoutWindow = false
		}
	},
	beforeDestroy() {
		// Inline edits can be newer than the last ten-second autosave.
		if (this.inlineEditing) {
			this.syncLocalSnapshotToServer({ force: true }).catch(() => {})
		}
		this.stopAutosave()
	},
	methods: {
		onInlineInteraction() {
			if (!this.inlineEditing || this.overlayOpen) {
				return
			}
			this.startAutosave()
		},
		startAutosave() {
			if (this.autosaveTimer) {
				return
			}
			this.autosaveTimer = window.setInterval(() => {
				this.syncLocalSnapshotToServer({ force: false })
			}, 10000)
		},
		stopAutosave() {
			if (!this.autosaveTimer) {
				return
			}
			window.clearInterval(this.autosaveTimer)
			this.autosaveTimer = null
		},
		async initialize() {
			this.error = ''
			this.whiteboardInfo = null
			this.handler = null
			this.loading = true
			this.viewerReady = false

			try {
				if (!this.normalizedProjectId) {
					this.error = 'No project selected.'
					return
				}
				if (!this.userId) {
					this.error = 'Could not resolve current user.'
					return
				}

				const info = await projectsService.getWhiteboardInfo(this.normalizedProjectId)
				if (!info || !info.fileId) {
					this.error = 'No whiteboard linked to this project.'
					return
				}
				this.whiteboardInfo = info
				this.handler = await this.waitForHandler(info.mimetype, 10000)
				// Best-effort signal for UI; overlay open will still wait when clicked.
				this.viewerReady = this.isViewerOverlayReady()
			} catch (e) {
				this.error = 'Failed to load whiteboard.'
			} finally {
				this.loading = false
			}
		},
		async refreshInfo() {
			if (!this.normalizedProjectId) {
				return
			}
			try {
				const info = await projectsService.getWhiteboardInfo(this.normalizedProjectId)
				if (info && info.fileId) {
					this.whiteboardInfo = info
					if (!this.handler) {
						this.handler = await this.waitForHandler(info.mimetype, 3000)
					}
					this.previewKey++
				}
			} catch (e) {
				// ignore
			}
		},
		openWhiteboardDb() {
			return new Promise((resolve, reject) => {
				if (!window.indexedDB) {
					reject(new Error('IndexedDB unavailable'))
					return
				}
				const request = window.indexedDB.open('WhiteboardDatabase')
				request.onerror = () => reject(request.error || new Error('Failed to open WhiteboardDatabase'))
				request.onsuccess = () => resolve(request.result)
			})
		},
		readLocalSnapshot(fileId) {
			return new Promise(async (resolve, reject) => {
				let db
				try {
					db = await this.openWhiteboardDb()
				} catch (e) {
					reject(e)
					return
				}

				try {
					const tx = db.transaction(['whiteboards'], 'readonly')
					const store = tx.objectStore('whiteboards')
					const req = store.get(Number(fileId))
					req.onerror = () => reject(req.error || new Error('Failed reading local whiteboard snapshot'))
					req.onsuccess = () => resolve(req.result || null)
				} catch (e) {
					reject(e)
				}
			})
		},
		writeLocalSnapshotMeta(fileId, patch) {
			return new Promise(async (resolve, reject) => {
				let db
				try {
					db = await this.openWhiteboardDb()
				} catch (e) {
					reject(e)
					return
				}

				try {
					const tx = db.transaction(['whiteboards'], 'readwrite')
					const store = tx.objectStore('whiteboards')
					const getReq = store.get(Number(fileId))
					getReq.onerror = () => reject(getReq.error || new Error('Failed reading local whiteboard snapshot'))
					getReq.onsuccess = () => {
						const existing = getReq.result
						if (!existing) {
							resolve(false)
							return
						}
						const next = {
							...existing,
							...patch,
						}
						const putReq = store.put(next)
						putReq.onerror = () => reject(putReq.error || new Error('Failed updating local whiteboard snapshot'))
						putReq.onsuccess = () => resolve(true)
					}
				} catch (e) {
					reject(e)
				}
			})
		},
		async syncLocalSnapshotToServer(options = {}) {
			const force = options?.force === true
			if (!this.whiteboardInfo?.fileId) {
				return
			}
			if (this.syncing) {
				return
			}
			if (!force && this.lastSyncAttemptAt && Date.now() - this.lastSyncAttemptAt < 8000) {
				return
			}

			let snapshot = null
			try {
				snapshot = await this.readLocalSnapshot(this.whiteboardInfo.fileId)
			} catch (e) {
				return
			}

			if (!snapshot || !Array.isArray(snapshot.elements)) {
				return
			}

			const hasPendingFlag = typeof snapshot.hasPendingLocalChanges === 'boolean'
			const hasPendingLocalChanges = hasPendingFlag ? snapshot.hasPendingLocalChanges : null
			if (hasPendingFlag && hasPendingLocalChanges === false) {
				return
			}
			if (!hasPendingFlag && !force) {
				const hasSomethingToSave = (snapshot.elements.length > 0)
					|| (snapshot.files && typeof snapshot.files === 'object' && Object.keys(snapshot.files).length > 0)
				if (!hasSomethingToSave) {
					return
				}
			}

			this.lastSyncAttemptAt = Date.now()

			this.syncing = true
			try {
				const tokenResp = await fetch(generateUrl(`/apps/whiteboard/${encodeURIComponent(String(this.whiteboardInfo.fileId))}/token`), {
					method: 'GET',
					credentials: 'include',
					headers: {
						Accept: 'application/json',
					},
				})
				if (!tokenResp.ok) {
					throw new Error(`Token request failed: ${tokenResp.status}`)
				}
				const tokenJson = await tokenResp.json()
				const token = tokenJson?.token
				if (!token) {
					throw new Error('Missing whiteboard token')
				}

				const putResp = await fetch(generateUrl(`/apps/whiteboard/${encodeURIComponent(String(this.whiteboardInfo.fileId))}`), {
					method: 'PUT',
					credentials: 'include',
					headers: {
						'Content-Type': 'application/json',
						Authorization: `Bearer ${token}`,
						'X-Requested-With': 'XMLHttpRequest',
					},
					body: JSON.stringify({
						data: {
							elements: snapshot.elements || [],
							files: snapshot.files || {},
							appState: snapshot.appState || undefined,
							scrollToContent: true,
						},
					}),
				})
				if (!putResp.ok) {
					throw new Error(`Whiteboard sync failed: ${putResp.status}`)
				}

				try {
					await this.writeLocalSnapshotMeta(this.whiteboardInfo.fileId, {
						hasPendingLocalChanges: false,
						savedAt: Date.now(),
					})
				} catch (e) {
					// non-blocking
				}
			} finally {
				this.syncing = false
			}
		},
		waitForHandler(mimetype, timeoutMs = 10000) {
			const start = Date.now()
			return new Promise((resolve) => {
				const tick = () => {
					const viewer = window?.OCA?.Viewer
					const handlers = viewer?.availableHandlers || []
					if (Array.isArray(handlers) && handlers.length > 0) {
						const match = handlers.find((h) => Array.isArray(h?.mimes) && h.mimes.includes(mimetype))
						if (match) {
							resolve(match)
							return
						}
					}

					if (Date.now() - start > timeoutMs) {
						resolve(null)
						return
					}
					window.setTimeout(tick, 150)
				}
				tick()
			})
		},

		reload() {
			this.initialize()
		},
		isViewerOverlayReady() {
			const viewer = window?.OCA?.Viewer
			const hasApi = typeof viewer?.openWith === 'function' && typeof viewer?.open === 'function'
			const handlers = viewer?.availableHandlers
			const hasWhiteboardHandler = Array.isArray(handlers) && handlers.some((h) => h?.id === 'whiteboard')
			return hasApi && hasWhiteboardHandler
		},
		waitForViewerOverlayReady(timeoutMs = 10000) {
			const start = Date.now()
			return new Promise((resolve) => {
				const tick = () => {
					if (this.isViewerOverlayReady()) {
						resolve(true)
						return
					}
					if (Date.now() - start > timeoutMs) {
						resolve(false)
						return
					}
					window.setTimeout(tick, 150)
				}
				tick()
			})
		},
		async openOverlay() {
			this.error = ''
			if (!this.whiteboardInfo) {
				await this.initialize()
			}
			if (!this.whiteboardInfo) {
				return
			}
			if (!this.userId) {
				this.error = 'Could not resolve current user.'
				return
			}

			const ready = await this.waitForViewerOverlayReady(10000)
			if (!ready) {
				this.error = 'Viewer overlay is not ready on this page. Try reloading.'
				return
			}

			const viewer = window.OCA.Viewer
			const fileInfo = {
				filename: this.whiteboardInfo.path,
				basename: this.whiteboardInfo.name,
				mime: this.whiteboardInfo.mimetype,
				fileid: this.whiteboardInfo.fileId,
				source: this.davSource,
				size: this.whiteboardInfo.size ?? 0,
				lastmod: this.whiteboardInfo.mtime ?? null,
				type: 'file',
			}

			// Stand the inline board down before the overlay takes the file over.
			this.overlayOpen = true
			await this.$nextTick()

			this.startAutosave()
			viewer.openWith('whiteboard', {
				fileInfo,
				list: [fileInfo],
				enableSidebar: false,
				canLoop: false,
				onClose: async () => {
					this.stopAutosave()
					await this.syncLocalSnapshotToServer({ force: true })
					await this.refreshInfo()
					this.overlayOpen = false
				},
			})
		},
		openPopout() {
			this.error = ''
			if (this.isPopoutWindow || this.openMode === 'overlay') {
				this.openOverlay()
				return
			}
			if (!this.normalizedProjectId) {
				this.error = 'No project selected.'
				return
			}
			const url = generateUrl(`/apps/projectcreatoraio/?popout=whiteboard&projectId=${encodeURIComponent(this.normalizedProjectId)}`)
			const w = window.open(url, '_blank', 'noopener')
			if (!w) {
				this.error = 'Popup blocked. Please allow popups for this site.'
			}
		},
		openInFiles() {
			if (!this.whiteboardInfo) {
				return
			}
			const url = generateUrl(`/apps/files/f/${encodeURIComponent(String(this.whiteboardInfo.fileId))}?openfile=true`)
			window.open(url, '_blank')
		},
	},
}
</script>

<style scoped>
.whiteboard-board {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.whiteboard-board__top {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
}

.whiteboard-board__title {
	display: flex;
	align-items: center;
	gap: 8px;
}

.whiteboard-board__title-text {
	font-weight: 700;
}

.whiteboard-board__badge {
	font-size: 12px;
	line-height: 1;
	padding: 4px 8px;
	border-radius: 999px;
	border: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
}

.whiteboard-board__subtitle {
	margin-top: 4px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.whiteboard-board__actions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.whiteboard-board__frame {
	position: relative;
	border: 1px solid var(--color-border);
	border-radius: 12px;
	overflow: hidden;
	background: var(--color-main-background);
	min-height: 420px;
}

.whiteboard-board__loading,
.whiteboard-board__error {
	position: absolute;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 10px;
	padding: 16px;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	background: color-mix(in srgb, var(--color-main-background) 92%, transparent);
	backdrop-filter: blur(2px);
}

.whiteboard-board__hint {
	padding: 14px 16px;
	color: var(--color-text-maxcontrast);
}

.whiteboard-board__preview-wrap {
	width: 100%;
	height: 560px;
	max-height: 72vh;
	min-height: 420px;
	position: relative;
	overflow: hidden;
}

.whiteboard-board__embedded {
	width: 100%;
	height: 100%;
	pointer-events: none;
}


.whiteboard-board__preview-overlay {
	position: absolute;
	inset: 0;
	border: 0;
	padding: 0;
	margin: 0;
	background: linear-gradient(180deg, transparent 55%, color-mix(in srgb, var(--color-main-background) 65%, transparent));
	cursor: pointer;
	display: flex;
	align-items: flex-end;
	justify-content: flex-start;
}

.whiteboard-board__preview-overlay:disabled {
	cursor: default;
}

.whiteboard-board__preview-overlay-label {
	margin: 0 0 12px 12px;
	padding: 6px 10px;
	border-radius: 999px;
	background: color-mix(in srgb, var(--color-primary-element) 85%, transparent);
	color: var(--color-primary-element-text);
	font-size: 12px;
	font-weight: 600;
}

::v-deep .whiteboard-board__embedded .whiteboard-viewer__embedding {
	width: 100%;
	height: 100%;
}

@media (max-width: 900px) {
	.whiteboard-board__top {
		flex-direction: column;
		align-items: stretch;
	}

	.whiteboard-board__frame {
		min-height: 340px;
	}

	.whiteboard-board__preview-wrap {
		height: 420px;
		max-height: 64vh;
		min-height: 340px;
	}
}
</style>
