<template>
	<div class="pdf-studio-portal" style="display: none;">
		<transition name="studio-fade">
			<div v-show="visible" ref="studioContainer" class="pdf-studio">
				<!-- Header -->
				<div class="pdf-studio__header">
					<div class="pdf-studio__header-left">
						<div class="pdf-studio__doc-info">
							<span class="pdf-studio__doc-badge">PDF</span>
							<h2 class="pdf-studio__doc-title" :title="fileName">
								{{ fileName }}
							</h2>
						</div>
						<div class="pdf-studio__status-summary">
							Placed {{ placedCount }} of {{ signers.length }} signatures
						</div>
					</div>

					<div class="pdf-studio__header-center">
						<!-- Page Switcher -->
						<div class="pdf-studio__page-nav">
							<button type="button"
								class="pdf-studio__nav-btn"
								:disabled="currentPage <= 1 || loading"
								@click="changePage(-1)">
								<ChevronLeft :size="20" />
							</button>
							<span class="pdf-studio__page-indicator">Page {{ currentPage }} / {{ pageCount || '?' }}</span>
							<button type="button"
								class="pdf-studio__nav-btn"
								:disabled="currentPage >= pageCount || loading"
								@click="changePage(1)">
								<ChevronRight :size="20" />
							</button>
						</div>

						<!-- Zoom Controls -->
						<div class="pdf-studio__zoom-controls">
							<button type="button"
								class="pdf-studio__zoom-btn"
								:disabled="zoom <= 0.5 || loading"
								@click="changeZoom(-0.15)">
								<Minus :size="16" />
							</button>
							<span class="pdf-studio__zoom-label">{{ Math.round(zoom * 100) }}%</span>
							<button type="button"
								class="pdf-studio__zoom-btn"
								:disabled="zoom >= 2.0 || loading"
								@click="changeZoom(0.15)">
								<Plus :size="16" />
							</button>
							<button type="button"
								class="pdf-studio__zoom-btn"
								:disabled="loading"
								@click="resetZoom">
								<ArrowExpand :size="16" />
							</button>
						</div>
					</div>

					<div class="pdf-studio__header-right">
						<button type="button" class="pdf-studio__btn pdf-studio__btn--secondary" @click="handleCancel">
							Cancel
						</button>
						<button type="button" class="pdf-studio__btn pdf-studio__btn--primary" @click="handleDone">
							Save Placements
						</button>
					</div>
				</div>

				<!-- Main Workbench -->
				<div class="pdf-studio__workbench">
					<!-- Signers Sidebar -->
					<div class="pdf-studio__sidebar">
						<div class="pdf-studio__sidebar-header">
							<h3>Signers</h3>
							<p>Select a signer, then click on the PDF to place their signature block.</p>
						</div>
						<div class="pdf-studio__signer-list">
							<div
								v-for="signer in signers"
								:key="signer.signerKey"
								class="pdf-studio__signer-card"
								:class="{
									'pdf-studio__signer-card--active': activeSignerKey === signer.signerKey,
									'pdf-studio__signer-card--placed': placements[signer.signerKey]
								}"
								@click="selectSigner(signer.signerKey)">
								<div class="pdf-studio__signer-avatar" :style="signerIconStyle(signer)">
									{{ signerInitials(signer) }}
								</div>
								<div class="pdf-studio__signer-details">
									<strong class="pdf-studio__signer-name">{{ signer.displayName || signer.email || signer.userId }}</strong>
									<span class="pdf-studio__signer-badge" :class="placements[signer.signerKey] ? 'pdf-studio__signer-badge--placed' : 'pdf-studio__signer-badge--pending'">
										{{ placements[signer.signerKey] ? `Placed on Page ${placements[signer.signerKey].page}` : 'Not placed' }}
									</span>
								</div>
								<div class="pdf-studio__signer-actions">
									<button
										v-if="placements[signer.signerKey]"
										type="button"
										class="pdf-studio__action-btn"
										title="Remove placement"
										@click.stop="clearPlacement(signer.signerKey)">
										<Close :size="16" />
									</button>
								</div>
							</div>
						</div>
					</div>

					<!-- PDF Viewer Area -->
					<div class="pdf-studio__viewer-area">
						<div class="pdf-studio__canvas-container" :class="{ 'pdf-studio__canvas-container--ready': pdfReady }">
							<div
								v-show="pdfReady"
								ref="workspaceCanvas"
								class="pdf-studio__pdf-workspace"
								@click="handleWorkspaceClick">
								<!-- Canvas for PDF rendering -->
								<canvas ref="pdfRenderCanvas" class="pdf-studio__pdf-canvas" />

								<!-- Signature Box overlays -->
								<template v-for="signer in signers">
									<div
										v-if="placements[signer.signerKey] && placements[signer.signerKey].page === currentPage"
										:key="`box-${signer.signerKey}`"
										class="pdf-studio__sig-box"
										:class="{ 'pdf-studio__sig-box--active': activeSignerKey === signer.signerKey }"
										:style="[getBoxStyle(placements[signer.signerKey]), signerBoxBorderColor(signer)]"
										@click.stop
										@mousedown.stop="startDrag($event, signer.signerKey)">
										<div class="pdf-studio__sig-box-avatar" :style="signerIconStyle(signer)">
											{{ signerInitials(signer) }}
										</div>
										<div class="pdf-studio__sig-box-info">
											<strong class="pdf-studio__sig-box-title">Signature Element</strong>
											<span class="pdf-studio__sig-box-name">{{ signer.displayName || signer.email || signer.userId }}</span>
										</div>
										<button
											type="button"
											class="pdf-studio__sig-box-delete"
											title="Remove placement"
											@click.stop="clearPlacement(signer.signerKey)">
											<Close :size="12" />
										</button>
										<i class="pdf-studio__sig-box-resize" @mousedown.stop.prevent="startResize($event, signer.signerKey)" />
									</div>
								</template>
							</div>

							<!-- Loading / Error States -->
							<div v-if="loading" class="pdf-studio__state-overlay">
								<div class="pdf-studio__spinner" />
								<p>Rendering Document...</p>
							</div>
							<div v-else-if="error" class="pdf-studio__state-overlay pdf-studio__state-overlay--error">
								<AlertCircleOutline :size="48" />
								<p>{{ error }}</p>
							</div>
						</div>
					</div>
				</div>
			</div>
		</transition>
	</div>
</template>

<script>
import * as pdfjsLib from 'pdfjs-dist'
// eslint-disable-next-line import/no-unresolved
import pdfWorkerUrl from 'pdfjs-dist/build/pdf.worker.mjs?url'

import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Minus from 'vue-material-design-icons/Minus.vue'
import Close from 'vue-material-design-icons/Close.vue'
import ArrowExpand from 'vue-material-design-icons/ArrowExpand.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorkerUrl

export default {
	name: 'PdfPlacementStudio',
	components: {
		ChevronLeft,
		ChevronRight,
		Plus,
		Minus,
		Close,
		ArrowExpand,
		AlertCircleOutline,
	},
	props: {
		visible: { type: Boolean, required: true },
		fileName: { type: String, default: '' },
		file: { type: [Object, File], default: null },
		webdavClient: { type: Object, required: true },
		signers: { type: Array, required: true },
		value: { type: Object, default: () => ({}) },
	},
	data() {
		return {
			loading: false,
			error: '',
			pdfDoc: null,
			pageCount: 0,
			currentPage: 1,
			zoom: 1.0,
			pdfReady: false,
			canvasSize: { width: 0, height: 0 },
			pageSize: { width: 595, height: 842 }, // Original PDF page size in points
			activeSignerKey: '',
			dragState: null,
			justDragged: false,
			placements: {}, // Local copy of placements
		}
	},
	computed: {
		placedCount() {
			return Object.keys(this.placements).length
		},
	},
	watch: {
		visible: {
			immediate: true,
			handler(newVal) {
				if (newVal) {
					this.initStudio()
				} else {
					this.destroyStudio()
				}
			},
		},
		signers: {
			immediate: true,
			handler(newSigners) {
				if (newSigners && newSigners.length > 0) {
					// Default active signer to first unplaced signer
					const firstUnplaced = newSigners.find((s) => !this.placements[s.signerKey])
					this.activeSignerKey = firstUnplaced ? firstUnplaced.signerKey : newSigners[0].signerKey
				}
			},
		},
	},
	mounted() {
		if (this.$refs.studioContainer) {
			document.body.appendChild(this.$refs.studioContainer)
		}
	},
	beforeDestroy() {
		if (this.$refs.studioContainer && this.$refs.studioContainer.parentNode) {
			this.$refs.studioContainer.parentNode.removeChild(this.$refs.studioContainer)
		}
	},
	methods: {
		async initStudio() {
			this.placements = JSON.parse(JSON.stringify(this.value || {}))
			this.currentPage = 1
			this.zoom = 1.0
			this.error = ''
			this.pdfDoc = null
			this.pdfReady = false
			this.activeSignerKey = this.signers[0]?.signerKey || ''

			if (!this.file) {
				this.error = 'No file provided.'
				return
			}

			this.loading = true
			try {
				let buffer
				if (this.file instanceof File || this.file instanceof Blob) {
					buffer = await this.readFileAsArrayBuffer(this.file)
				} else if (this.file.path) {
					// We need to fetch via webdavClient
					const davPath = this.normalizedDavPath(this.file.path)
					const downloadLink = this.webdavClient.getFileDownloadLink(davPath)
					const response = await fetch(downloadLink, { credentials: 'include' })
					if (!response.ok) {
						throw new Error('Failed to download PDF preview.')
					}
					buffer = await response.arrayBuffer()
				} else {
					throw new Error('Unsupported file source.')
				}

				this.pdfDoc = await pdfjsLib.getDocument({ data: buffer }).promise
				this.pageCount = this.pdfDoc.numPages || 1
				await this.renderPage()
			} catch (err) {
				console.error('PDF Studio Load Error:', err)
				this.error = err.message || 'Could not load PDF document.'
			} finally {
				this.loading = false
			}
		},
		destroyStudio() {
			this.pdfDoc = null
			this.pdfReady = false
		},
		normalizedDavPath(path) {
			const parts = String(path).split('/')
			if (parts.length >= 3) {
				const second = parts[1]
				parts[1] = parts[2]
				parts[2] = second
			}
			return parts.join('/')
		},
		readFileAsArrayBuffer(file) {
			return new Promise((resolve, reject) => {
				const reader = new FileReader()
				reader.onload = () => resolve(reader.result)
				reader.onerror = () => reject(reader.error || new Error('File read error'))
				reader.readAsArrayBuffer(file)
			})
		},
		async renderPage() {
			if (!this.pdfDoc) return
			this.loading = true
			try {
				await this.$nextTick()
				const canvas = this.$refs.pdfRenderCanvas
				if (!canvas) return
				const page = await this.pdfDoc.getPage(this.currentPage)
				const baseViewport = page.getViewport({ scale: 1.0 })
				this.pageSize = { width: baseViewport.width, height: baseViewport.height }

				// Calculate scale to fit width or be standard size
				const maxWidth = 800
				const baseScale = Math.min(maxWidth / baseViewport.width, 1.5)
				const viewport = page.getViewport({ scale: baseScale * this.zoom })

				const context = canvas.getContext('2d')
				canvas.width = Math.round(viewport.width)
				canvas.height = Math.round(viewport.height)
				this.canvasSize = { width: canvas.width, height: canvas.height }

				await page.render({ canvasContext: context, viewport }).promise
				this.pdfReady = true
			} catch (err) {
				console.error('Render Page Error:', err)
				this.error = 'Failed to render PDF page.'
			} finally {
				this.loading = false
			}
		},
		async changePage(delta) {
			const next = Math.max(1, Math.min(this.pageCount, this.currentPage + delta))
			if (next === this.currentPage) return
			this.currentPage = next
			await this.renderPage()
		},
		async changeZoom(delta) {
			this.zoom = Math.max(0.5, Math.min(2.0, this.zoom + delta))
			await this.renderPage()
		},
		async resetZoom() {
			this.zoom = 1.0
			await this.renderPage()
		},
		selectSigner(signerKey) {
			this.activeSignerKey = signerKey
		},
		clearPlacement(signerKey) {
			this.$delete(this.placements, signerKey)
			if (this.activeSignerKey === signerKey || !this.activeSignerKey) {
				this.activeSignerKey = signerKey
			}
		},
		handleWorkspaceClick(event) {
			if (this.justDragged) {
				return
			}
			const key = this.activeSignerKey
			if (!key || !this.pdfReady) return

			const rect = this.$refs.workspaceCanvas?.getBoundingClientRect()
			if (!rect) return

			const width = 26
			const height = 9
			const left = Math.max(0, Math.min(100 - width, ((event.clientX - rect.left) / rect.width) * 100 - width / 2))
			const top = Math.max(0, Math.min(100 - height, ((event.clientY - rect.top) / rect.height) * 100 - height / 2))

			this.$set(this.placements, key, {
				page: this.currentPage,
				leftPct: left,
				topPct: top,
				widthPct: width,
				heightPct: height,
				left: Math.round((left / 100) * this.pageSize.width),
				top: Math.round((top / 100) * this.pageSize.height),
				width: Math.round((width / 100) * this.pageSize.width),
				height: Math.round((height / 100) * this.pageSize.height),
			})

			// Auto select next unplaced signer
			const nextUnplaced = this.signers.find((s) => !this.placements[s.signerKey])
			if (nextUnplaced) {
				this.activeSignerKey = nextUnplaced.signerKey
			}
		},
		getBoxStyle(box) {
			return {
				left: `${box.leftPct}%`,
				top: `${box.topPct}%`,
				width: `${box.widthPct}%`,
				height: `${box.heightPct}%`,
			}
		},
		signerInitials(signer) {
			const label = String(signer?.displayName || signer?.email || signer?.userId || '?').trim()
			const parts = label.split(/\s+/).filter(Boolean)
			if (parts.length >= 2) {
				return `${parts[0][0]}${parts[1][0]}`.toUpperCase()
			}
			return label.slice(0, 2).toUpperCase()
		},
		signerIconStyle(signer) {
			const key = signer?.signerKey || ''
			let hash = 0
			for (let i = 0; i < key.length; i++) {
				hash = key.charCodeAt(i) + ((hash << 5) - hash)
			}
			const hue = Math.abs(hash % 360)
			return {
				background: `hsl(${hue}, 45%, 25%)`,
				color: `hsl(${hue}, 90%, 85%)`,
				border: `1px solid hsl(${hue}, 60%, 45%)`,
			}
		},
		signerBoxBorderColor(signer) {
			const key = signer?.signerKey || ''
			let hash = 0
			for (let i = 0; i < key.length; i++) {
				hash = key.charCodeAt(i) + ((hash << 5) - hash)
			}
			const hue = Math.abs(hash % 360)
			return {
				borderColor: `hsl(${hue}, 65%, 45%)`,
				boxShadow: this.activeSignerKey === key
					? `0 0 0 3px hsl(${hue}, 65%, 45%), 0 16px 34px rgba(0,0,0,0.3)`
					: `0 8px 24px hsla(${hue}, 65%, 20%, 0.25)`,
			}
		},
		startDrag(event, signerKey) {
			this.selectSigner(signerKey)
			this.startPointer(event, signerKey, 'move')
		},
		startResize(event, signerKey) {
			this.selectSigner(signerKey)
			this.startPointer(event, signerKey, 'resize')
		},
		startPointer(event, signerKey, mode) {
			const stage = this.$refs.workspaceCanvas
			const box = this.placements[signerKey]
			if (!stage || !box) return

			this.dragState = {
				mode,
				signerKey,
				rect: stage.getBoundingClientRect(),
				startX: event.clientX,
				startY: event.clientY,
				start: { ...box },
			}

			window.addEventListener('mousemove', this.onPointerMove)
			window.addEventListener('mouseup', this.stopPointer)
		},
		onPointerMove(event) {
			const drag = this.dragState
			if (!drag) return

			const dx = ((event.clientX - drag.startX) / drag.rect.width) * 100
			const dy = ((event.clientY - drag.startY) / drag.rect.height) * 100
			const next = { ...drag.start }

			if (drag.mode === 'resize') {
				next.widthPct = Math.max(8, Math.min(70, (drag.start.widthPct || 26) + dx))
				next.heightPct = Math.max(5, Math.min(30, (drag.start.heightPct || 9) + dy))
			} else {
				next.leftPct = Math.max(0, Math.min(100 - (next.widthPct || 26), (drag.start.leftPct || 0) + dx))
				next.topPct = Math.max(0, Math.min(100 - (next.heightPct || 9), (drag.start.topPct || 0) + dy))
			}

			// Recompute absolute coordinates in points
			next.left = Math.round((next.leftPct / 100) * this.pageSize.width)
			next.top = Math.round((next.topPct / 100) * this.pageSize.height)
			next.width = Math.round((next.widthPct / 100) * this.pageSize.width)
			next.height = Math.round((next.heightPct / 100) * this.pageSize.height)

			this.$set(this.placements, drag.signerKey, next)
		},
		stopPointer(event) {
			if (this.dragState && event) {
				const dx = event.clientX - this.dragState.startX
				const dy = event.clientY - this.dragState.startY
				if (Math.sqrt(dx * dx + dy * dy) > 3) {
					this.justDragged = true
					setTimeout(() => {
						this.justDragged = false
					}, 50)
				}
			}
			this.dragState = null
			window.removeEventListener('mousemove', this.onPointerMove)
			window.removeEventListener('mouseup', this.stopPointer)
		},
		handleCancel() {
			this.$emit('close')
		},
		handleDone() {
			// Emit local placements back
			this.$emit('input', this.placements)
			this.$emit('done', this.placements)
		},
	},
}
</script>

<style scoped>
.pdf-studio {
	position: fixed;
	inset: 0;
	z-index: 99999;
	display: grid;
	grid-template-rows: 64px 1fr;
	background: #111115;
	color: #e2e8f0;
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

/* Header */
.pdf-studio__header {
	display: grid;
	grid-template-columns: 1fr auto 1fr;
	align-items: center;
	padding: 0 24px;
	background: #18181e;
	border-bottom: 1px solid rgba(255, 255, 255, 0.08);
	box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
}

.pdf-studio__header-left {
	display: flex;
	align-items: center;
	gap: 16px;
}

.pdf-studio__doc-info {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
}

.pdf-studio__doc-badge {
	background: #c28e36;
	color: #fff;
	font-size: 10px;
	font-weight: 800;
	padding: 3px 6px;
	border-radius: 4px;
	letter-spacing: 0.05em;
}

.pdf-studio__doc-title {
	font-size: 15px;
	font-weight: 700;
	color: #f8fafc;
	margin: 0;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.pdf-studio__status-summary {
	font-size: 12px;
	color: #94a3b8;
	background: rgba(255, 255, 255, 0.05);
	padding: 4px 10px;
	border-radius: 99px;
	border: 1px solid rgba(255, 255, 255, 0.08);
}

.pdf-studio__header-center {
	display: flex;
	align-items: center;
	gap: 24px;
}

.pdf-studio__page-nav {
	display: flex;
	align-items: center;
	background: rgba(0, 0, 0, 0.25);
	border: 1px solid rgba(255, 255, 255, 0.08);
	border-radius: 8px;
	padding: 4px;
}

.pdf-studio__nav-btn {
	background: transparent;
	border: none;
	color: #94a3b8;
	cursor: pointer;
	padding: 6px;
	border-radius: 6px;
	display: flex;
	align-items: center;
	transition: all 0.2s ease;
}

.pdf-studio__nav-btn:hover:not(:disabled) {
	background: rgba(255, 255, 255, 0.08);
	color: #f8fafc;
}

.pdf-studio__nav-btn:disabled {
	opacity: 0.4;
	cursor: not-allowed;
}

.pdf-studio__page-indicator {
	font-size: 13px;
	font-weight: 600;
	color: #cbd5e1;
	padding: 0 12px;
	min-width: 90px;
	text-align: center;
}

.pdf-studio__zoom-controls {
	display: flex;
	align-items: center;
	background: rgba(0, 0, 0, 0.25);
	border: 1px solid rgba(255, 255, 255, 0.08);
	border-radius: 8px;
	padding: 4px;
}

.pdf-studio__zoom-btn {
	background: transparent;
	border: none;
	color: #94a3b8;
	cursor: pointer;
	padding: 6px;
	border-radius: 6px;
	display: flex;
	align-items: center;
	transition: all 0.2s ease;
}

.pdf-studio__zoom-btn:hover:not(:disabled) {
	background: rgba(255, 255, 255, 0.08);
	color: #f8fafc;
}

.pdf-studio__zoom-btn:disabled {
	opacity: 0.4;
	cursor: not-allowed;
}

.pdf-studio__zoom-label {
	font-size: 12px;
	font-weight: 700;
	color: #cbd5e1;
	padding: 0 8px;
	min-width: 48px;
	text-align: center;
}

.pdf-studio__header-right {
	display: flex;
	justify-content: flex-end;
	gap: 12px;
}

.pdf-studio__btn {
	border-radius: 10px;
	font-size: 13px;
	font-weight: 700;
	padding: 8px 16px;
	cursor: pointer;
	transition: all 0.2s ease;
	border: none;
}

.pdf-studio__btn--secondary {
	background: rgba(255, 255, 255, 0.06);
	color: #cbd5e1;
	border: 1px solid rgba(255, 255, 255, 0.1);
}

.pdf-studio__btn--secondary:hover {
	background: rgba(255, 255, 255, 0.1);
	color: #fff;
}

.pdf-studio__btn--primary {
	background: linear-gradient(135deg, #d89e3f, #b98126);
	color: #111115;
	box-shadow: 0 4px 12px rgba(216, 158, 63, 0.2);
}

.pdf-studio__btn--primary:hover {
	transform: translateY(-1px);
	box-shadow: 0 6px 16px rgba(216, 158, 63, 0.3);
}

/* Workbench Layout */
.pdf-studio__workbench {
	display: grid;
	grid-template-columns: 280px 1fr;
	height: 100%;
	overflow: hidden;
}

/* Sidebar */
.pdf-studio__sidebar {
	background: #141419;
	border-right: 1px solid rgba(255, 255, 255, 0.05);
	display: grid;
	grid-template-rows: auto 1fr;
	overflow: hidden;
}

.pdf-studio__sidebar-header {
	padding: 20px;
	border-bottom: 1px solid rgba(255, 255, 255, 0.04);
}

.pdf-studio__sidebar-header h3 {
	font-size: 16px;
	font-weight: 700;
	color: #f1f5f9;
	margin: 0 0 6px 0;
}

.pdf-studio__sidebar-header p {
	font-size: 12px;
	color: #64748b;
	margin: 0;
	line-height: 1.4;
}

.pdf-studio__signer-list {
	padding: 16px;
	overflow-y: auto;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.pdf-studio__signer-card {
	display: grid;
	grid-template-columns: 36px 1fr auto;
	align-items: center;
	gap: 12px;
	padding: 12px;
	background: #1a1a22;
	border: 2px solid rgba(255, 255, 255, 0.05);
	border-radius: 14px;
	cursor: pointer;
	transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

.pdf-studio__signer-card:hover {
	background: #20202a;
	border-color: rgba(255, 255, 255, 0.15);
	transform: translateX(2px);
}

.pdf-studio__signer-card--active {
	border-color: #d89e3f !important;
	background: #1e1e28;
	box-shadow: 0 4px 16px rgba(216, 158, 63, 0.08);
}

.pdf-studio__signer-card--placed {
	background: rgba(46, 164, 79, 0.05);
	border-color: rgba(46, 164, 79, 0.2);
}

.pdf-studio__signer-avatar {
	width: 36px;
	height: 36px;
	border-radius: 10px;
	display: grid;
	place-items: center;
	font-size: 13px;
	font-weight: 800;
}

.pdf-studio__signer-details {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.pdf-studio__signer-name {
	font-size: 13px;
	font-weight: 600;
	color: #e2e8f0;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.pdf-studio__signer-badge {
	font-size: 11px;
	font-weight: 500;
	margin-top: 2px;
}

.pdf-studio__signer-badge--placed {
	color: #4ade80;
}

.pdf-studio__signer-badge--pending {
	color: #64748b;
}

.pdf-studio__signer-actions {
	display: flex;
	align-items: center;
}

.pdf-studio__action-btn {
	background: transparent;
	border: none;
	color: #64748b;
	padding: 4px;
	border-radius: 50%;
	cursor: pointer;
	display: grid;
	place-items: center;
	transition: all 0.2s;
}

.pdf-studio__action-btn:hover {
	background: rgba(255, 255, 255, 0.08);
	color: #f43f5e;
}

/* PDF Viewer Area */
.pdf-studio__viewer-area {
	display: grid;
	place-items: center;
	padding: 32px;
	overflow: auto;
	background: #0d0d10;
}

.pdf-studio__canvas-container {
	position: relative;
	background: #141419;
	border-radius: 20px;
	border: 1px solid rgba(255, 255, 255, 0.05);
	box-shadow: 0 30px 70px rgba(0, 0, 0, 0.5);
	display: flex;
	justify-content: center;
	align-items: center;
	transition: all 0.3s ease;
	min-width: 300px;
	min-height: 400px;
}

.pdf-studio__canvas-container--ready {
	background: transparent;
	border: none;
	box-shadow: none;
}

.pdf-studio__pdf-workspace {
	position: relative;
	display: inline-block;
	max-width: 100%;
	border-radius: 12px;
	overflow: hidden;
	box-shadow: 0 20px 50px rgba(0, 0, 0, 0.4);
	cursor: crosshair;
}

.pdf-studio__pdf-canvas {
	display: block;
	max-width: 100%;
	height: auto;
	background: #fff;
}

/* Signature Overlay Box */
.pdf-studio__sig-box {
	position: absolute;
	display: grid;
	grid-template-columns: 32px 1fr auto;
	align-items: center;
	column-gap: 8px;
	padding: 8px;
	border: 2px solid #cbd5e1;
	border-radius: 10px;
	background: rgba(255, 255, 255, 0.85);
	backdrop-filter: blur(8px);
	box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
	cursor: move;
	user-select: none;
	transition: border-color 0.15s, box-shadow 0.15s;
}

.pdf-studio__sig-box--active {
	background: rgba(255, 255, 255, 0.95);
	z-index: 100;
}

.pdf-studio__sig-box-avatar {
	width: 32px;
	height: 32px;
	border-radius: 8px;
	display: grid;
	place-items: center;
	font-size: 11px;
	font-weight: 800;
}

.pdf-studio__sig-box-info {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.pdf-studio__sig-box-title {
	font-size: 10px;
	font-weight: 700;
	color: #64748b;
	text-transform: uppercase;
	letter-spacing: 0.05em;
}

.pdf-studio__sig-box-name {
	font-size: 12px;
	font-weight: 600;
	color: #0f172a;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.pdf-studio__sig-box-delete {
	background: transparent;
	border: none;
	color: #64748b;
	cursor: pointer;
	padding: 4px;
	border-radius: 50%;
	display: grid;
	place-items: center;
	transition: all 0.2s;
}

.pdf-studio__sig-box-delete:hover {
	background: rgba(0, 0, 0, 0.05);
	color: #f43f5e;
}

.pdf-studio__sig-box-resize {
	position: absolute;
	right: -6px;
	bottom: -6px;
	width: 14px;
	height: 14px;
	background: #fff;
	border: 2px solid #0f172a;
	border-radius: 50%;
	cursor: nwse-resize;
	z-index: 10;
	box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
}

/* State Overlays */
.pdf-studio__state-overlay {
	position: absolute;
	inset: 0;
	display: flex;
	flex-direction: column;
	justify-content: center;
	align-items: center;
	gap: 16px;
	color: #94a3b8;
}

.pdf-studio__state-overlay--error {
	color: #f43f5e;
}

.pdf-studio__spinner {
	width: 40px;
	height: 40px;
	border: 4px solid rgba(255, 255, 255, 0.1);
	border-top-color: #d89e3f;
	border-radius: 50%;
	animation: spin 1s linear infinite;
}

@keyframes spin {
	to { transform: rotate(360deg); }
}

/* Transitions */
.studio-fade-enter-active,
.studio-fade-leave-active {
	transition: opacity 0.3s ease;
}

.studio-fade-enter,
.studio-fade-leave-to {
	opacity: 0;
}
</style>
