<template>
	<NcModal :show="show" size="normal" @close="handleClose">
		<div class="org-pdf-modal">
			<header class="org-pdf-modal__header">
				<div class="org-pdf-modal__header-content">
					<h2 class="org-pdf-modal__title">Default Project PDF</h2>
					<p class="org-pdf-modal__subtitle">
						Upload a custom PDF template that will be automatically added to the shared folder whenever a new project is created for your organization.
					</p>
				</div>
			</header>

			<div v-if="error" class="org-pdf-modal__error-banner" role="alert">
				<AlertCircle :size="20" />
				<span>{{ error }}</span>
			</div>

			<div v-if="loading" class="org-pdf-modal__loading">
				<NcLoadingIcon :size="32" />
				<p>Loading settings...</p>
			</div>

			<div v-else class="org-pdf-modal__body">
				<div class="org-pdf-modal__status-box" :class="{ 'org-pdf-modal__status-box--custom': hasCustomPdf }">
					<div class="org-pdf-modal__status-icon">
						<FileDocumentOutline :size="28" />
					</div>
					<div class="org-pdf-modal__status-info">
						<span class="org-pdf-modal__status-label">Current Template:</span>
						<strong v-if="hasCustomPdf" class="org-pdf-modal__status-value org-pdf-modal__status-value--custom">
							{{ currentFileName || 'Custom Organization PDF' }}
						</strong>
						<span v-else class="org-pdf-modal__status-value">
							System Default PDF (Fallback)
						</span>
					</div>
				</div>

				<div class="org-pdf-modal__upload-section">
					<label class="org-pdf-modal__label">Upload New PDF Template</label>
					<div 
						class="org-pdf-modal__dropzone"
						:class="{ 'org-pdf-modal__dropzone--has-file': selectedFile }"
						@click="triggerFileInput">
						<input 
							ref="fileInput" 
							type="file" 
							accept="application/pdf"
							class="org-pdf-modal__hidden-input"
							@change="onFileSelected" />
						
						<Upload :size="32" class="org-pdf-modal__drop-icon" />
						<div v-if="selectedFile" class="org-pdf-modal__file-info">
							<strong>{{ selectedFile.name }}</strong>
							<span>({{ formatFileSize(selectedFile.size) }})</span>
						</div>
						<div v-else class="org-pdf-modal__drop-text">
							<span>Click or drag a PDF file here to upload</span>
							<small>Only .pdf files are accepted</small>
						</div>
					</div>
				</div>

				<div v-if="selectedFile" class="org-pdf-modal__filename-section">
					<NcTextField
						v-model="fileName"
						label="Filename in new projects"
						:show-label="true"
						input-label="Filename in new projects"
						placeholder="e.g. Welcome guide.pdf"
						:disabled="uploading" />
					<p class="org-pdf-modal__filename-help">
						This name will be used in each new project's shared files. The .pdf extension is added if omitted.
					</p>
				</div>
			</div>

			<footer class="org-pdf-modal__footer">
				<NcButton 
					v-if="hasCustomPdf"
					type="tertiary"
					:disabled="uploading"
					@click="confirmDelete">
					<template #icon>
						<Delete :size="18" />
					</template>
					Reset to default
				</NcButton>

				<div class="org-pdf-modal__footer-actions">
					<NcButton type="tertiary" @click="handleClose">
						Cancel
					</NcButton>
					<NcButton 
						type="primary"
						:disabled="!selectedFile || uploading"
						@click="handleUpload">
						<template #icon>
							<NcLoadingIcon v-if="uploading" :size="18" />
							<Check v-else :size="18" />
						</template>
						{{ uploading ? 'Uploading...' : 'Save Template' }}
					</NcButton>
				</div>
			</footer>
		</div>
	</NcModal>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Check from 'vue-material-design-icons/Check.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'

import { ProjectsService } from '../Services/projects.js'

const projectsService = ProjectsService.getInstance()

export default {
	name: 'OrganizationPdfModal',
	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcTextField,
		FileDocumentOutline,
		Upload,
		Delete,
		Check,
		AlertCircle,
	},
	props: {
		show: {
			type: Boolean,
			default: false,
		},
		organizationId: {
			type: Number,
			default: null,
		},
	},
	data() {
		return {
			loading: false,
			uploading: false,
			hasCustomPdf: false,
			selectedFile: null,
			currentFileName: '',
			fileName: '',
			error: null,
		}
	},
	watch: {
		show(newVal) {
			if (newVal && this.organizationId) {
				this.fetchPdfInfo()
			} else {
				this.resetState()
			}
		},
	},
	methods: {
		resetState() {
			this.loading = false
			this.uploading = false
			this.hasCustomPdf = false
			this.selectedFile = null
			this.currentFileName = ''
			this.fileName = ''
			this.error = null
		},
		handleClose() {
			this.$emit('close')
		},
		async fetchPdfInfo() {
			if (!this.organizationId) return
			this.loading = true
			this.error = null
			try {
				const info = await projectsService.getOrganizationPdfInfo(this.organizationId)
				this.hasCustomPdf = !!(info && info.has_custom_pdf)
				this.currentFileName = info?.file_name || ''
			} catch (e) {
				console.error('Failed to load organization PDF info:', e)
				this.error = 'Failed to load document template settings.'
			} finally {
				this.loading = false
			}
		},
		triggerFileInput() {
			if (this.$refs.fileInput) {
				this.$refs.fileInput.click()
			}
		},
		onFileSelected(event) {
			const files = event.target.files
			if (files && files.length > 0) {
				const file = files[0]
				if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
					this.error = 'Please select a valid PDF document (.pdf).'
					this.selectedFile = null
					return
				}
				this.error = null
				this.selectedFile = file
				this.fileName = file.name
			}
		},
		normalizeFileName(fileName) {
			const normalized = String(fileName || '').trim()
			if (normalized === '') {
				return ''
			}
			return /\.pdf$/i.test(normalized) ? normalized : `${normalized}.pdf`
		},
		async handleUpload() {
			if (!this.selectedFile || !this.organizationId) return
			const fileName = this.normalizeFileName(this.fileName)
			if (fileName === '') {
				this.error = 'Enter a filename for the PDF template.'
				return
			}
			this.uploading = true
			this.error = null
			try {
				const response = await projectsService.uploadOrganizationPdf(this.organizationId, this.selectedFile, fileName)
				this.hasCustomPdf = true
				this.currentFileName = response?.file_name || fileName
				this.selectedFile = null
				this.fileName = ''
				if (this.$refs.fileInput) {
					this.$refs.fileInput.value = ''
				}
				this.$emit('updated')
				this.handleClose()
			} catch (e) {
				console.error('Failed to upload PDF template:', e)
				this.error = e.response?.data?.error || 'Failed to upload PDF template.'
			} finally {
				this.uploading = false
			}
		},
		async confirmDelete() {
			if (!this.organizationId) return
			if (!confirm('Are you sure you want to remove the custom PDF template and revert to the system default?')) {
				return
			}
			this.uploading = true
			this.error = null
			try {
				await projectsService.deleteOrganizationPdf(this.organizationId)
				this.hasCustomPdf = false
				this.selectedFile = null
				this.$emit('updated')
			} catch (e) {
				console.error('Failed to delete custom PDF template:', e)
				this.error = 'Failed to reset PDF template.'
			} finally {
				this.uploading = false
			}
		},
		formatFileSize(bytes) {
			if (!bytes || bytes === 0) return '0 B'
			const k = 1024
			const sizes = ['B', 'KB', 'MB', 'GB']
			const i = Math.floor(Math.log(bytes) / Math.log(k))
			return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
		},
	},
}
</script>

<style scoped>
.org-pdf-modal {
	padding: 24px;
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.org-pdf-modal__header-content {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.org-pdf-modal__title {
	font-size: 1.25rem;
	font-weight: 600;
	margin: 0;
}

.org-pdf-modal__subtitle {
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.org-pdf-modal__error-banner {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 14px;
	border-radius: 8px;
	background-color: var(--color-error-background, #fee2e2);
	color: var(--color-error, #dc2626);
	font-size: 0.9rem;
}

.org-pdf-modal__loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	padding: 30px;
}

.org-pdf-modal__body {
	display: flex;
	flex-direction: column;
	gap: 18px;
}

.org-pdf-modal__status-box {
	display: flex;
	align-items: center;
	gap: 14px;
	padding: 14px 18px;
	border-radius: 8px;
	background-color: var(--color-background-dark, #f3f4f6);
	border: 1px solid var(--color-border, #e5e7eb);
}

.org-pdf-modal__status-box--custom {
	background-color: var(--color-primary-element-light, #eff6ff);
	border-color: var(--color-primary-element, #3b82f6);
}

.org-pdf-modal__status-info {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.org-pdf-modal__status-label {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.org-pdf-modal__status-value {
	font-size: 0.95rem;
}

.org-pdf-modal__status-value--custom {
	color: var(--color-primary, #2563eb);
}

.org-pdf-modal__upload-section {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.org-pdf-modal__filename-section {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.org-pdf-modal__filename-help {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.8rem;
}

.org-pdf-modal__label {
	font-weight: 500;
	font-size: 0.9rem;
}

.org-pdf-modal__dropzone {
	border: 2px dashed var(--color-border, #d1d5db);
	border-radius: 8px;
	padding: 24px;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 8px;
	cursor: pointer;
	transition: all 0.2s ease;
	text-align: center;
}

.org-pdf-modal__dropzone:hover {
	border-color: var(--color-primary, #3b82f6);
	background-color: var(--color-background-hover, #f9fafb);
}

.org-pdf-modal__dropzone--has-file {
	border-style: solid;
	border-color: var(--color-primary, #3b82f6);
	background-color: var(--color-primary-element-light, #eff6ff);
}

.org-pdf-modal__hidden-input {
	display: none;
}

.org-pdf-modal__drop-text {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 0.9rem;
}

.org-pdf-modal__drop-text small {
	color: var(--color-text-maxcontrast);
	font-size: 0.8rem;
}

.org-pdf-modal__file-info {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 0.95rem;
}

.org-pdf-modal__footer {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-top: 10px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border, #e5e7eb);
}

.org-pdf-modal__footer-actions {
	display: flex;
	align-items: center;
	gap: 10px;
	margin-left: auto;
}
</style>
