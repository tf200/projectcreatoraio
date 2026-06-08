<template>
	<div class="project-files">
		<div class="project-files__header">
			<div class="project-files__tabs">
				<button
					:type="'button'"
					class="project-files__tab"
					:class="{ 'project-files__tab--active': scope === 'shared' }"
					@click="setScope('shared')">
					Shared
					<span class="project-files__tab-pill">{{ sharedFileCount }}</span>
				</button>
				<button
					:type="'button'"
					class="project-files__tab"
					:class="{ 'project-files__tab--active': scope === 'private' }"
					@click="setScope('private')">
					Private
					<span class="project-files__tab-pill">{{ privateFileCount }}</span>
				</button>
			</div>

			<div class="project-files__tools">
				<NcTextField
					v-model="search"
					label="Search files"
					input-label="Search files"
					placeholder="Search names">
					<template #icon>
						<Magnify :size="18" />
					</template>
				</NcTextField>
				<button
					v-if="search.trim().length > 0"
					:type="'button'"
					class="project-files__clear"
					@click="clearSearch">
					Clear
				</button>
			</div>
		</div>

		<div v-if="documentTypesLoading" class="project-files__muted">Loading OCR document types...</div>
		<div v-else-if="documentTypesError" class="project-files__muted">{{ documentTypesError }}</div>
		<div v-if="loading" class="project-files__muted">Loading file structure...</div>
		<div v-else-if="error" class="project-files__muted">{{ error }}</div>
		<div v-else class="project-files__grid">
			<div class="project-files__pane project-files__pane--left">
				<div class="project-files__pane-title">Folders</div>
				<div v-if="activeRoots.length === 0" class="project-files__empty">No folders found.</div>
				<div v-else class="project-files__tree">
					<FolderTreeItem
						v-for="root in folderRoots"
						:key="`${scope}-root-${root.id}`"
						:node="root"
						:depth="0"
						:expanded-ids="expandedIds"
						:selected-id="selectedFolderId"
						:show-counts="false"
						@toggle="toggleFolder"
						@select="selectFolder" />
				</div>
			</div>

			<div class="project-files__pane project-files__pane--right">
				<div class="project-files__right-top">
					<div class="project-files__breadcrumbs">
						<button
							v-for="crumb in selectedChain"
							:key="`crumb-${crumb.id}`"
							:type="'button'"
							class="project-files__crumb"
							@click="selectFolder(crumb)">
							{{ crumb.name }}
						</button>
						<span v-if="selectedChain.length === 0" class="project-files__muted">No folder selected</span>
					</div>

					<div class="project-files__actions">
						<button
							:type="'button'"
							class="project-files__btn"
							:disabled="!selectedFolderNode"
							@click="openNodeInFiles(selectedFolderNode)">
							<OpenInNew :size="18" />
							Open in Files
						</button>
						<button
							:type="'button'"
							class="project-files__btn project-files__btn--primary"
							:disabled="!selectedFolderNode || uploadBusy || documentTypesLoading || documentTypes.length === 0"
							@click="triggerUpload">
							<Upload :size="18" />
							{{ uploadBusy ? 'Uploading...' : 'Upload for OCR' }}
						</button>
						<button
							:type="'button'"
							class="project-files__btn project-files__btn--primary"
							:disabled="!selectedFolderNode || signingUploadBusy"
							@click="triggerSigningUpload">
							<FileDocumentOutline :size="18" />
							{{ signingUploadBusy ? 'Uploading...' : 'Upload for signing' }}
						</button>
						<button
							:type="'button'"
							class="project-files__btn project-files__btn--primary"
							:disabled="!selectedFolderNode"
							@click="downloadFolderZip(selectedFolderNode)">
							<Download :size="18" />
							Download ZIP
						</button>
					</div>
				</div>

				<input
					ref="uploadInput"
					class="project-files__upload-input"
					type="file"
					multiple
					@change="onFilesPicked">
				<input
					ref="signingUploadInput"
					class="project-files__upload-input"
					type="file"
					accept="application/pdf,.pdf"
					multiple
					@change="onSigningFilesPicked">

				<div v-if="showUploadModal" class="project-files__modal-overlay" @click="closeUploadModal">
					<div class="project-files__modal project-files__upload-modal" @click.stop>
						<div class="project-files__modal-header">
							<h3 class="project-files__modal-title">Upload Files</h3>
							<button class="project-files__modal-close" @click="closeUploadModal">
								<Close :size="20" />
							</button>
						</div>
						<div class="project-files__modal-content">
							<div class="project-files__modal-filename">{{ selectedFolderNode?.name || 'Selected folder' }}</div>
							<label class="project-files__upload-modal-label" for="project-files-upload-type">Document type</label>
							<select
								id="project-files-upload-type"
								v-model="uploadDocumentTypeId"
								class="project-files__upload-type-select"
								:disabled="documentTypesLoading || documentTypes.length === 0 || uploadBusy">
								<option value="">{{ documentTypes.length === 0 ? 'No document types' : 'Select document type...' }}</option>
								<option v-for="type in documentTypes" :key="`upload-doc-type-${type.id}`" :value="String(type.id)">
									{{ type.name }}
								</option>
							</select>
							<div class="project-files__upload-modal-row">
								<button
									:type="'button'"
									class="project-files__btn"
									:disabled="uploadBusy"
									@click="$refs.uploadInput?.click?.()">
									Choose files
								</button>
								<span class="project-files__upload-modal-hint">
									{{ selectedUploadFiles.length === 0 ? 'No files selected yet.' : `${selectedUploadFiles.length} file${selectedUploadFiles.length === 1 ? '' : 's'} selected.` }}
								</span>
							</div>
							<ul v-if="selectedUploadFiles.length > 0" class="project-files__upload-file-list">
								<li v-for="file in selectedUploadFiles" :key="`upload-file-${file.name}-${file.size}`" class="project-files__upload-file-item">
									<span>{{ file.name }}</span>
									<span>{{ formatBytes(file.size) }}</span>
								</li>
							</ul>
							<div class="project-files__modal-actions">
								<button
									:type="'button'"
									class="project-files__btn"
									:disabled="uploadBusy"
									@click="closeUploadModal">
									Cancel
								</button>
								<button
									:type="'button'"
									class="project-files__btn project-files__btn--primary"
									:disabled="uploadBusy || !uploadDocumentTypeId || selectedUploadFiles.length === 0"
									@click="uploadSelectedFiles">
									{{ uploadBusy ? 'Uploading...' : 'Upload and process' }}
								</button>
							</div>
						</div>
					</div>
				</div>

				<div v-if="showSigningUploadModal" class="project-files__modal-overlay" @click="closeSigningUploadModal">
					<div class="project-files__modal project-files__upload-modal" @click.stop>
						<div class="project-files__modal-header">
							<h3 class="project-files__modal-title">Upload for Signing</h3>
							<button class="project-files__modal-close" @click="closeSigningUploadModal">
								<Close :size="20" />
							</button>
						</div>
					<div class="project-files__modal-content">
						<div class="project-files__modal-filename">{{ selectedFolderNode?.name || 'Selected folder' }}</div>
						<div class="project-files__signer-picker">
							<div class="project-files__upload-modal-label">Project members</div>
							<div v-if="projectMembersLoading" class="project-files__muted">Loading members...</div>
							<div v-else-if="projectMembersError" class="project-files__muted">{{ projectMembersError }}</div>
							<div v-else-if="projectMembers.length === 0" class="project-files__muted">No project members found.</div>
							<label v-for="member in projectMembers" v-else :key="`signing-upload-member-${member.id}`" class="project-files__member-option">
								<input v-model="signingUploadDraft.memberIds" type="checkbox" :value="member.id" :disabled="signingUploadBusy">
								<span>{{ member.displayName || member.id }}</span>
								<span class="project-files__member-sub">{{ member.id }}</span>
							</label>
						</div>
						<label class="project-files__upload-modal-label" for="project-files-signing-upload-flow">Signing flow</label>
							<select id="project-files-signing-upload-flow" v-model="signingUploadDraft.flow" class="project-files__upload-type-select" :disabled="signingUploadBusy">
								<option value="parallel">Parallel</option>
								<option value="ordered_numeric">Ordered</option>
							</select>
						<label class="project-files__upload-modal-label" for="project-files-signing-upload-signers">External signer emails</label>
							<textarea
								id="project-files-signing-upload-signers"
								v-model="signingUploadDraft.signersText"
								class="project-files__textarea"
								:disabled="signingUploadBusy"
								placeholder="client@example.com\nmanager@example.com" />
							<div class="project-files__hint">Upload PDF files and immediately send them to LibreSign.</div>
							<div v-if="signingUploadSigners.length > 0" class="project-files__placements">
								<div class="project-files__upload-modal-label">Signature placement</div>
								<div class="project-files__hint">Coordinates are PDF points from the top-left of the page.</div>
								<div v-for="(signer, index) in signingUploadSigners" :key="`signing-upload-placement-${signer.signerKey}`" class="project-files__placement-row">
									<div class="project-files__placement-name">{{ signer.displayName || signer.email || signer.userId }}</div>
									<label>Page<input v-model.number="signingUploadDraft.placements[signer.signerKey].page" type="number" min="1" :disabled="signingUploadBusy"></label>
									<label>Left<input v-model.number="signingUploadDraft.placements[signer.signerKey].left" type="number" min="0" :disabled="signingUploadBusy"></label>
									<label>Top<input v-model.number="signingUploadDraft.placements[signer.signerKey].top" type="number" min="0" :disabled="signingUploadBusy"></label>
									<label>Width<input v-model.number="signingUploadDraft.placements[signer.signerKey].width" type="number" min="1" :disabled="signingUploadBusy"></label>
									<label>Height<input v-model.number="signingUploadDraft.placements[signer.signerKey].height" type="number" min="1" :disabled="signingUploadBusy"></label>
								</div>
							</div>
							<div class="project-files__upload-modal-row">
								<button
									:type="'button'"
									class="project-files__btn"
									:disabled="signingUploadBusy"
									@click="$refs.signingUploadInput?.click?.()">
									Choose PDFs
								</button>
								<span class="project-files__upload-modal-hint">
									{{ selectedSigningUploadFiles.length === 0 ? 'No PDFs selected yet.' : `${selectedSigningUploadFiles.length} PDF${selectedSigningUploadFiles.length === 1 ? '' : 's'} selected.` }}
								</span>
							</div>
							<ul v-if="selectedSigningUploadFiles.length > 0" class="project-files__upload-file-list">
								<li v-for="file in selectedSigningUploadFiles" :key="`signing-upload-file-${file.name}-${file.size}`" class="project-files__upload-file-item">
									<span>{{ file.name }}</span>
									<span>{{ formatBytes(file.size) }}</span>
								</li>
							</ul>
							<div v-if="signingUploadError" class="project-files__upload-error">{{ signingUploadError }}</div>
							<div class="project-files__modal-actions">
								<button :type="'button'" class="project-files__btn" :disabled="signingUploadBusy" @click="closeSigningUploadModal">Cancel</button>
								<button :type="'button'" class="project-files__btn project-files__btn--primary" :disabled="signingUploadBusy || selectedSigningUploadFiles.length === 0" @click="uploadSigningFiles">
									{{ signingUploadBusy ? 'Uploading...' : 'Upload and send' }}
								</button>
							</div>
						</div>
					</div>
				</div>

				<div v-if="uploadError" class="project-files__upload-error">{{ uploadError }}</div>
				<div v-else-if="uploadMessage" class="project-files__upload-success">{{ uploadMessage }}</div>
				<div v-if="signingUploadMessage" class="project-files__upload-success">{{ signingUploadMessage }}</div>

				<div class="project-files__list">
					<div v-if="showSearch" class="project-files__results">
						<div class="project-files__pane-title">Search results</div>
						<div v-if="searchResults.length === 0" class="project-files__empty">No matches.</div>
						<ul v-else class="project-files__rows">
							<li v-for="hit in searchResults" :key="`hit-${hit.node.id}`" class="project-files__row">
								<div class="project-files__row-main" @click="activateSearchHit(hit)">
									<FolderOutline v-if="hit.node.type === 'folder'" :size="20" class="project-files__row-icon" />
									<FileOutline v-else :size="20" class="project-files__row-icon" />
									<div class="project-files__row-info">
										<span class="project-files__row-name">{{ hit.node.name }}</span>
										<span class="project-files__row-sub">{{ hit.pathLabel || 'Root' }}</span>
									</div>
								</div>
							</li>
						</ul>
					</div>

					<div v-else>
						<div class="project-files__pane-title">Contents</div>
						<div v-if="!selectedFolderNode" class="project-files__empty">Select a folder to view its contents.</div>
						<div v-else-if="sortedEntries.length === 0" class="project-files__empty">This folder is empty.</div>
						<ul v-else class="project-files__rows">
							<li
								v-for="entry in sortedEntries"
								:key="`entry-${entry.id}`"
								class="project-files__row"
								:class="{ 'project-files__row--highlight': highlightedNodeId !== null && String(highlightedNodeId) === String(entry.id) }">

								<div
									class="project-files__row-main"
									@click="entry.type === 'folder' ? selectFolder(entry) : openFileInFiles(entry)">
									<FolderOutline v-if="entry.type === 'folder'" :size="20" class="project-files__row-icon" />
									<FileOutline v-else :size="20" class="project-files__row-icon" />

									<div class="project-files__row-info">
										<span class="project-files__row-name">{{ entry.name }}</span>
										<span class="project-files__row-sub">
											<span v-if="entry.type === 'folder'">{{ countFiles(entry) }} files</span>
											<span v-else>{{ formatBytes(entry.size) }}</span>
										</span>
									</div>
								</div>

								<div v-if="isSupportedFile(entry)" class="project-files__row-ocr" @click.stop>
									<span class="project-files__type-label-inline">
										{{ processingDocumentTypeLabel(entry.id) }}
									</span>

									<div class="project-files__status-inline" :class="statusBadgeClass(entry.id)" :title="statusLabel(entry.id) + (fileFeedback(entry.id) ? ' - ' + fileFeedback(entry.id) : '')">
										<component :is="statusIcon(entry.id)" :size="16" />
									</div>
								</div>
								<div v-else class="project-files__row-ocr project-files__row-ocr--empty"></div>

								<div class="project-files__row-actions" @click.stop>
									<button
										v-if="isSupportedFile(entry) && canOpenExtractedDataModal(entry.id)"
										:type="'button'"
										class="project-files__icon-btn"
										title="View Extracted Data"
										@click="openExtractedDataModal(entry.id)">
										<EyeOutline :size="18" />
									</button>
									<button
										v-if="isSupportedFile(entry) && canReprocess(entry.id)"
										:type="'button'"
										class="project-files__icon-btn"
										title="Reprocess OCR"
										@click="reprocessFile(entry)">
										<Refresh :size="18" />
									</button>
								<div v-if="isSupportedFile(entry) && isProcessingBusy(entry.id)" class="project-files__loading-wrap">
									<NcLoadingIcon :size="20" />
								</div>
								<button
									v-if="isSignableFile(entry)"
									:type="'button'"
									class="project-files__icon-btn"
									:title="signingTitle(entry.id)"
									@click="openSigningModal(entry)">
									<FileDocumentOutline :size="18" />
								</button>
								<span
									v-if="signingByFileId[String(entry.id)]"
									class="project-files__signing-pill"
									:class="signingBadgeClass(entry.id)">
									{{ signingStatusLabel(entry.id) }}
								</span>
								<button
									:type="'button'"
									class="project-files__icon-btn"
										title="Open in Files"
										@click="openNodeInFiles(entry)">
										<OpenInNew :size="18" />
									</button>
									<button
										v-if="entry.type === 'file'"
										:type="'button'"
										class="project-files__icon-btn"
										title="Download"
										@click="downloadFile(entry)">
										<Download :size="18" />
									</button>
								</div>
							</li>
						</ul>
					</div>
				</div>
			</div>
		</div>

		<!-- Extracted Data Modal -->
		<div v-if="activeSigningFile" class="project-files__modal-overlay" @click="closeSigningModal">
			<div class="project-files__modal" @click.stop>
				<div class="project-files__modal-header">
					<h3 class="project-files__modal-title">Request Signature</h3>
					<button class="project-files__modal-close" @click="closeSigningModal">
						<Close :size="20" />
					</button>
				</div>
				<div class="project-files__modal-content">
					<div class="project-files__modal-filename">{{ activeSigningFile.name }}</div>
					<div class="project-files__signer-picker">
						<div class="project-files__upload-modal-label">Project members</div>
						<div v-if="projectMembersLoading" class="project-files__muted">Loading members...</div>
						<div v-else-if="projectMembersError" class="project-files__muted">{{ projectMembersError }}</div>
						<div v-else-if="projectMembers.length === 0" class="project-files__muted">No project members found.</div>
						<label v-for="member in projectMembers" v-else :key="`signing-member-${member.id}`" class="project-files__member-option">
							<input v-model="signingDraft.memberIds" type="checkbox" :value="member.id" :disabled="signingBusy">
							<span>{{ member.displayName || member.id }}</span>
							<span class="project-files__member-sub">{{ member.id }}</span>
						</label>
					</div>
					<label class="project-files__upload-modal-label" for="project-files-signing-flow">Signing flow</label>
					<select id="project-files-signing-flow" v-model="signingDraft.flow" class="project-files__upload-type-select" :disabled="signingBusy">
						<option value="parallel">Parallel</option>
						<option value="ordered_numeric">Ordered</option>
					</select>

					<label class="project-files__upload-modal-label" for="project-files-signing-signers">External signer emails</label>
					<textarea
						id="project-files-signing-signers"
						v-model="signingDraft.signersText"
						class="project-files__textarea"
						:disabled="signingBusy"
						placeholder="client@example.com\nmanager@example.com" />
					<div class="project-files__hint">Add one signer email per line. LibreSign will send the signing flow.</div>
					<div v-if="signingSigners.length > 0" class="project-files__placements">
						<div class="project-files__upload-modal-label">Signature placement</div>
						<div class="project-files__hint">Coordinates are PDF points from the top-left of the page.</div>
						<div v-for="(signer, index) in signingSigners" :key="`signing-placement-${signer.signerKey}`" class="project-files__placement-row">
							<div class="project-files__placement-name">{{ signer.displayName || signer.email || signer.userId }}</div>
							<label>Page<input v-model.number="signingDraft.placements[signer.signerKey].page" type="number" min="1" :disabled="signingBusy"></label>
							<label>Left<input v-model.number="signingDraft.placements[signer.signerKey].left" type="number" min="0" :disabled="signingBusy"></label>
							<label>Top<input v-model.number="signingDraft.placements[signer.signerKey].top" type="number" min="0" :disabled="signingBusy"></label>
							<label>Width<input v-model.number="signingDraft.placements[signer.signerKey].width" type="number" min="1" :disabled="signingBusy"></label>
							<label>Height<input v-model.number="signingDraft.placements[signer.signerKey].height" type="number" min="1" :disabled="signingBusy"></label>
						</div>
					</div>
					<div v-if="signingError" class="project-files__upload-error">{{ signingError }}</div>
					<div class="project-files__modal-actions">
						<button :type="'button'" class="project-files__btn" :disabled="signingBusy" @click="closeSigningModal">Cancel</button>
						<button :type="'button'" class="project-files__btn project-files__btn--primary" :disabled="signingBusy" @click="createSigningRequest">
							{{ signingBusy ? 'Sending...' : 'Send signing request' }}
						</button>
					</div>
				</div>
			</div>
		</div>

		<!-- Extracted Data Modal -->
		<div v-if="activeExtractedFileId" class="project-files__modal-overlay" @click="closeExtractedDataModal">
			<div class="project-files__modal" @click.stop>
				<div class="project-files__modal-header">
					<h3 class="project-files__modal-title">Extracted Data</h3>
					<button class="project-files__modal-close" @click="closeExtractedDataModal">
						<Close :size="20" />
					</button>
				</div>
				<div class="project-files__modal-content">
					<div class="project-files__modal-filename">{{ activeExtractedFileName }}</div>
					<div v-if="activeExtractedData.length === 0" class="project-files__empty">No data extracted yet.</div>
					<div v-if="activeExtractedData.length > 0 && activeMissingFieldsCount > 0" class="project-files__modal-warning">
						{{ activeMissingFieldsCount }} field{{ activeMissingFieldsCount === 1 ? '' : 's' }} still missing.
					</div>
					<table v-if="activeExtractedData.length > 0" class="project-files__data-table">
						<tbody>
							<tr v-for="item in activeExtractedData" :key="item.key">
								<th>
									{{ item.name }}
									<span v-if="item.missing" class="project-files__missing-pill">Missing</span>
								</th>
								<td>
									<input
										class="project-files__field-input"
										:value="activeExtractedDraft[item.key] ?? ''"
										@input="setActiveExtractedDraft(item.key, $event.target.value)">
								</td>
							</tr>
						</tbody>
					</table>
					<div v-if="activeExtractedData.length > 0" class="project-files__modal-actions">
						<button
							:type="'button'"
							class="project-files__btn project-files__btn--primary"
							:disabled="isSavingExtracted(activeExtractedFileId)"
							@click="saveActiveExtractedData">
							{{ isSavingExtracted(activeExtractedFileId) ? 'Saving...' : 'Save extracted fields' }}
						</button>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { generateRemoteUrl, generateUrl } from '@nextcloud/router'
import { createClient } from 'webdav'

import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Download from 'vue-material-design-icons/Download.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import FileOutline from 'vue-material-design-icons/FileOutline.vue'
import FileQuestionOutline from 'vue-material-design-icons/FileQuestionOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import Upload from 'vue-material-design-icons/Upload.vue'

import { ProjectsService } from '../../Services/projects.js'
import FolderTreeItem from './FolderTreeItem.vue'

function getRequestToken() {
	// Nextcloud exposes it on `OC.requestToken` and as a meta tag.
	const token = window?.OC?.requestToken
		|| document?.querySelector?.('head meta[name="requesttoken"]')?.content
		|| ''
	return String(token || '')
}

const webdavClient = createClient(generateRemoteUrl('dav'), {
	withCredentials: true,
	headers: {
		requesttoken: getRequestToken(),
		'X-RequestToken': getRequestToken(),
	},
})
const projectsService = ProjectsService.getInstance()
const SUPPORTED_MIME_TYPES = [
	'application/pdf',
	'image/jpeg',
	'image/png',
	'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
	'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	'application/vnd.ms-excel',
]

export default {
	name: 'ProjectFilesBrowser',
	components: {
		AlertCircleOutline,
		CheckCircleOutline,
		ClockOutline,
		Close,
		Download,
		EyeOutline,
		FileDocumentOutline,
		FileOutline,
		FileQuestionOutline,
		FolderOutline,
		FolderTreeItem,
		Magnify,
		NcLoadingIcon,
		NcTextField,
		OpenInNew,
		Refresh,
		Sync,
		Upload,
	},
	props: {
		projectId: {
			type: Number,
			default: null,
		},
		sharedRoots: {
			type: Array,
			default: () => [],
		},
		privateRoots: {
			type: Array,
			default: () => [],
		},
		loading: {
			type: Boolean,
			default: false,
		},
		error: {
			type: String,
			default: '',
		},
		documentTypesVersion: {
			type: Number,
			default: 0,
		},
	},
	data() {
		return {
			scope: 'shared',
			search: '',
			highlightedNodeId: null,
			selectedFolderIdByScope: {
				shared: null,
				private: null,
			},
			expandedIdsByScope: {
				shared: [],
				private: [],
			},
			documentTypes: [],
			documentTypesLoading: false,
			documentTypesError: '',
			processingByFileId: {},
			processingLoadingByFileId: {},
			assigningByFileId: {},
			signingByFileId: {},
			signingLoadingByFileId: {},
			activeSigningFile: null,
			signingDraft: {
				flow: 'parallel',
				signersText: '',
				memberIds: [],
				placements: {},
			},
			signingBusy: false,
			signingError: '',
			projectMembers: [],
			projectMembersLoading: false,
			projectMembersError: '',
			feedbackByFileId: {},
			activeExtractedFileId: null,
			activeExtractedDraft: {},
			savingExtractedFileId: null,
			uploadBusy: false,
			uploadError: '',
			uploadMessage: '',
			showUploadModal: false,
			uploadDocumentTypeId: '',
			selectedUploadFiles: [],
			pendingUploadTargets: [],
			resolvingPendingUploads: false,
			showSigningUploadModal: false,
			signingUploadBusy: false,
			signingUploadError: '',
			signingUploadMessage: '',
			selectedSigningUploadFiles: [],
			pendingSigningUploadTargets: [],
			resolvingPendingSigningUploads: false,
			signingUploadDraft: {
				flow: 'parallel',
				signersText: '',
				memberIds: [],
				placements: {},
			},
		}
	},
	computed: {
		activeRoots() {
			return this.scope === 'shared' ? (this.sharedRoots || []) : (this.privateRoots || [])
		},
		folderRoots() {
			return (this.activeRoots || []).filter((node) => node && node.type === 'folder')
		},
		expandedIds() {
			return this.expandedIdsByScope[this.scope] || []
		},
		selectedFolderId() {
			return this.selectedFolderIdByScope[this.scope]
		},
		selectedChain() {
			if (this.selectedFolderId === null) {
				return []
			}
			return this.findChainById(this.activeRoots, this.selectedFolderId)
		},
		signingSigners() {
			return this.ensurePlacementDrafts(this.signingDraft, this.buildSignerPayload(this.signingDraft.memberIds, this.signingDraft.signersText))
		},
		signingUploadSigners() {
			return this.ensurePlacementDrafts(this.signingUploadDraft, this.buildSignerPayload(this.signingUploadDraft.memberIds, this.signingUploadDraft.signersText))
		},
		selectedFolderNode() {
			if (this.selectedChain.length === 0) {
				return null
			}
			return this.selectedChain[this.selectedChain.length - 1]
		},
		sortedEntries() {
			const children = Array.isArray(this.selectedFolderNode?.children) ? this.selectedFolderNode.children : []
			return children.slice().sort((a, b) => {
				const aFolder = a.type === 'folder'
				const bFolder = b.type === 'folder'
				if (aFolder !== bFolder) {
					return aFolder ? -1 : 1
				}
				return (a.name || '').localeCompare(b.name || '', undefined, { sensitivity: 'base' })
			})
		},
		showSearch() {
			return this.search.trim().length > 0
		},
		searchResults() {
			const q = this.search.trim().toLowerCase()
			if (q === '') {
				return []
			}

			const hits = []
			this.walkWithChain(this.activeRoots, [], (node, chain) => {
				const name = (node?.name || '').toLowerCase()
				if (!name.includes(q)) {
					return
				}
				const pathLabel = chain.slice(0, -1).map((n) => n.name).join(' / ')
				hits.push({ node, chain, pathLabel })
			})

			return hits.slice(0, 50)
		},
		sharedFileCount() {
			return this.countFilesInRoots(this.sharedRoots)
		},
		privateFileCount() {
			return this.countFilesInRoots(this.privateRoots)
		},
		activeExtractedData() {
			if (!this.activeExtractedFileId) return []
			return this.extractedEntries(this.activeExtractedFileId, true)
		},
		activeExtractedFileName() {
			if (!this.activeExtractedFileId) return ''
			const entry = this.sortedEntries.find((e) => String(e.id) === String(this.activeExtractedFileId))
			return entry ? entry.name : ''
		},
		activeMissingFieldsCount() {
			if (!this.activeExtractedFileId) return 0
			return this.missingFieldsCount(this.activeExtractedFileId)
		},
	},
	watch: {
		sharedRoots() {
			this.ensureSelection('shared')
			this.queueVisibleProcessingLoad()
			this.resolvePendingUploadedFiles()
			this.resolvePendingSigningUploads()
			this.clearUploadFeedback()
		},
		privateRoots() {
			this.ensureSelection('private')
			this.queueVisibleProcessingLoad()
			this.resolvePendingUploadedFiles()
			this.resolvePendingSigningUploads()
			this.clearUploadFeedback()
		},
		projectId: {
			immediate: true,
			handler() {
				this.resetOcrState()
				this.clearUploadFeedback()
				this.resetPendingUploadProcessing()
				this.loadDocumentTypes()
			},
		},
		documentTypesVersion() {
			this.loadDocumentTypes()
		},
		selectedFolderId() {
			this.queueVisibleProcessingLoad()
		},
		scope() {
			this.queueVisibleProcessingLoad()
		},
	},
	mounted() {
		this.ensureSelection('shared')
		this.ensureSelection('private')
	},
	methods: {
		resetOcrState() {
			this.documentTypes = []
			this.documentTypesLoading = false
			this.documentTypesError = ''
			this.processingByFileId = {}
			this.processingLoadingByFileId = {}
			this.assigningByFileId = {}
			this.signingByFileId = {}
			this.signingLoadingByFileId = {}
			this.activeSigningFile = null
			this.signingDraft = { flow: 'parallel', signersText: '', memberIds: [], placements: {} }
			this.signingBusy = false
			this.signingError = ''
			this.projectMembers = []
			this.projectMembersLoading = false
			this.projectMembersError = ''
			this.showSigningUploadModal = false
			this.signingUploadBusy = false
			this.signingUploadError = ''
			this.signingUploadMessage = ''
			this.selectedSigningUploadFiles = []
			this.signingUploadDraft = { flow: 'parallel', signersText: '', memberIds: [], placements: {} }
			this.feedbackByFileId = {}
			this.activeExtractedFileId = null
			this.activeExtractedDraft = {}
			this.savingExtractedFileId = null
		},
		clearUploadFeedback() {
			this.uploadError = ''
			this.uploadMessage = ''
		},
		resetPendingUploadProcessing() {
			this.pendingUploadTargets = []
			this.resolvingPendingUploads = false
			this.pendingSigningUploadTargets = []
			this.resolvingPendingSigningUploads = false
		},
		triggerSigningUpload() {
			if (!this.selectedFolderNode || this.signingUploadBusy) {
				return
			}
			this.signingUploadError = ''
			this.signingUploadMessage = ''
			this.selectedSigningUploadFiles = []
			this.signingUploadDraft = { flow: 'parallel', signersText: '', memberIds: [], placements: {} }
			this.showSigningUploadModal = true
			this.loadProjectMembers()
		},
		closeSigningUploadModal() {
			if (this.signingUploadBusy) {
				return
			}
			this.showSigningUploadModal = false
			this.selectedSigningUploadFiles = []
		},
		triggerUpload() {
			if (!this.selectedFolderNode || this.uploadBusy) {
				return
			}
			this.clearUploadFeedback()
			this.selectedUploadFiles = []
			this.showUploadModal = true
		},
		closeUploadModal() {
			if (this.uploadBusy) {
				return
			}
			this.showUploadModal = false
			this.selectedUploadFiles = []
		},
		async onFilesPicked(event) {
			const input = event?.target
			const files = Array.from(input?.files || [])
			// Allow selecting the same file twice in a row
			if (input) {
				input.value = ''
			}

			if (files.length === 0) {
				return
			}
			if (!this.showUploadModal) {
				this.triggerUpload()
			}
			if (!this.selectedFolderNode || this.uploadBusy) {
				return
			}
			this.selectedUploadFiles = files
		},
		async onSigningFilesPicked(event) {
			const input = event?.target
			const files = Array.from(input?.files || [])
			if (input) {
				input.value = ''
			}

			if (files.length === 0) {
				return
			}
			if (!this.showSigningUploadModal) {
				this.triggerSigningUpload()
			}
			if (!this.selectedFolderNode || this.signingUploadBusy) {
				return
			}
			this.selectedSigningUploadFiles = files.filter((file) => this.isPdfUploadFile(file))
			if (this.selectedSigningUploadFiles.length !== files.length) {
				this.signingUploadError = 'Only PDF files can be uploaded for signing.'
			}
		},
		async uploadSelectedFiles() {
			if (!this.selectedFolderNode || this.selectedUploadFiles.length === 0 || this.uploadBusy) {
				return
			}

			const uploadDocumentTypeId = Number(this.uploadDocumentTypeId)
			if (!Number.isFinite(uploadDocumentTypeId) || uploadDocumentTypeId <= 0) {
				this.uploadError = 'Select a document type before uploading.'
				return
			}

			const folderDavPath = this.normalizedDavPath(this.selectedFolderNode.path)
			if (!folderDavPath) {
				this.uploadError = 'Could not resolve destination folder.'
				return
			}

			this.uploadBusy = true
			this.uploadError = ''
			this.uploadMessage = ''

			const failures = []
			const uploadedTargets = []
			const reservedNames = this.folderFileNames(this.selectedFolderNode)
			let uploaded = 0

			for (const file of this.selectedUploadFiles) {
				const name = String(file?.name || '').trim()
				if (name === '' || name.includes('/')) {
					failures.push(name || '(unnamed file)')
					continue
				}
				const uploadName = this.uniqueUploadName(name, reservedNames)
				const target = `${folderDavPath.replace(/\/+$/, '')}/${uploadName}`
				try {
					// webdav-client treats unknown objects as JSON (resulting in "{}" being uploaded).
					// Always upload binary data as an ArrayBuffer.
					const data = await this.readFileAsArrayBuffer(file)
					const ok = await webdavClient.putFileContents(target, data, { overwrite: false })
					if (ok) {
						uploaded += 1
						uploadedTargets.push({
							path: this.joinNodePath(this.selectedFolderNode.path, uploadName),
							documentTypeId: uploadDocumentTypeId,
						})
					} else {
						failures.push(name)
					}
				} catch (error) {
					console.error('Upload failed:', error)
					failures.push(name)
				}
			}

			this.uploadBusy = false

			if (uploaded > 0) {
				this.pendingUploadTargets = [
					...this.pendingUploadTargets,
					...uploadedTargets,
				]
				this.uploadMessage = `Uploaded ${uploaded} file${uploaded === 1 ? '' : 's'}.`
				this.showUploadModal = false
				this.selectedUploadFiles = []
				this.$emit('refresh')
			}
			if (failures.length > 0) {
				const label = failures.length === 1 ? failures[0] : `${failures.length} files`
				this.uploadError = `Failed to upload ${label}.`
			}
		},
		async uploadSigningFiles() {
			if (!this.selectedFolderNode || this.selectedSigningUploadFiles.length === 0 || this.signingUploadBusy) {
				return
			}
			const signers = this.buildSignerPayload(this.signingUploadDraft.memberIds, this.signingUploadDraft.signersText)
			if (signers.length === 0) {
				this.signingUploadError = 'Select at least one project member or add an external signer email.'
				return
			}

			const folderDavPath = this.normalizedDavPath(this.selectedFolderNode.path)
			if (!folderDavPath) {
				this.signingUploadError = 'Could not resolve destination folder.'
				return
			}

			this.signingUploadBusy = true
			this.signingUploadError = ''
			this.signingUploadMessage = ''
			const failures = []
			const uploadedTargets = []
			const reservedNames = this.folderFileNames(this.selectedFolderNode)
			let uploaded = 0

			for (const file of this.selectedSigningUploadFiles) {
				const name = String(file?.name || '').trim()
				if (name === '' || name.includes('/') || !this.isPdfUploadFile(file)) {
					failures.push(name || '(unnamed file)')
					continue
				}
				const uploadName = this.uniqueUploadName(name, reservedNames)
				const target = `${folderDavPath.replace(/\/+$/, '')}/${uploadName}`
				try {
					const data = await this.readFileAsArrayBuffer(file)
					const ok = await webdavClient.putFileContents(target, data, { overwrite: false })
					if (ok) {
						uploaded += 1
						uploadedTargets.push({
							path: this.joinNodePath(this.selectedFolderNode.path, uploadName),
							flow: this.signingUploadDraft.flow,
							signers,
							placements: this.buildPlacementPayload(this.signingUploadDraft, signers),
						})
					} else {
						failures.push(name)
					}
				} catch (error) {
					console.error('Signing upload failed:', error)
					failures.push(name)
				}
			}

			this.signingUploadBusy = false
			if (uploaded > 0) {
				this.pendingSigningUploadTargets = [
					...this.pendingSigningUploadTargets,
					...uploadedTargets,
				]
				this.signingUploadMessage = `Uploaded ${uploaded} PDF${uploaded === 1 ? '' : 's'}. Sending signature request...`
				this.showSigningUploadModal = false
				this.selectedSigningUploadFiles = []
				this.$emit('refresh')
			}
			if (failures.length > 0) {
				const label = failures.length === 1 ? failures[0] : `${failures.length} files`
				this.signingUploadError = `Failed to upload ${label}.`
			}
		},
		readFileAsArrayBuffer(file) {
			if (file?.arrayBuffer) {
				return file.arrayBuffer()
			}
			// Fallback for older browsers
			return new Promise((resolve, reject) => {
				const reader = new FileReader()
				reader.onerror = () => reject(reader.error || new Error('Could not read file'))
				reader.onload = () => resolve(reader.result)
				reader.readAsArrayBuffer(file)
			})
		},
		folderFileNames(folderNode) {
			return new Set((folderNode?.children || [])
				.filter((child) => child?.type === 'file')
				.map((child) => String(child.name || '').toLowerCase()))
		},
		uniqueUploadName(name, reservedNames) {
			const cleanName = String(name || '').trim()
			const dotIndex = cleanName.lastIndexOf('.')
			const base = dotIndex > 0 ? cleanName.slice(0, dotIndex) : cleanName
			const extension = dotIndex > 0 ? cleanName.slice(dotIndex) : ''
			let candidate = cleanName
			let counter = 1
			while (reservedNames.has(candidate.toLowerCase())) {
				candidate = `${base} (${counter})${extension}`
				counter += 1
			}
			reservedNames.add(candidate.toLowerCase())
			return candidate
		},
		async loadDocumentTypes() {
			const projectId = Number(this.projectId)
			if (!Number.isFinite(projectId) || projectId <= 0) {
				this.documentTypes = []
				return
			}

			this.documentTypesLoading = true
			this.documentTypesError = ''
			try {
				this.documentTypes = await projectsService.listProjectDocumentTypes(projectId)
				this.queueVisibleProcessingLoad()
			} catch (error) {
				this.documentTypes = []
				this.documentTypesError = error?.response?.data?.message || 'Could not load OCR document types.'
			} finally {
				this.documentTypesLoading = false
			}
		},
		async loadProjectMembers() {
			const projectId = Number(this.projectId)
			if (!Number.isFinite(projectId) || projectId <= 0 || this.projectMembersLoading || this.projectMembers.length > 0) {
				return
			}
			this.projectMembersLoading = true
			this.projectMembersError = ''
			try {
				this.projectMembers = await projectsService.listMembers(projectId)
			} catch (error) {
				this.projectMembers = []
				this.projectMembersError = error?.response?.data?.message || 'Could not load project members.'
			} finally {
				this.projectMembersLoading = false
			}
		},
		queueVisibleProcessingLoad() {
			this.$nextTick(() => {
				this.preloadVisibleProcessing(this.sortedEntries)
			})
		},
		async preloadVisibleProcessing(entries) {
			const projectId = Number(this.projectId)
			if (!Number.isFinite(projectId) || projectId <= 0) {
				return
			}
			for (const entry of Array.isArray(entries) ? entries : []) {
				if (!this.isSupportedFile(entry)) {
					continue
				}
				const key = String(entry.id)
				if (Object.prototype.hasOwnProperty.call(this.processingByFileId, key) || this.processingLoadingByFileId[key]) {
					// Signing status is independent from OCR, so keep loading it below.
				} else {
					await this.loadFileProcessing(entry.id)
				}
				if (this.isSignableFile(entry) && !Object.prototype.hasOwnProperty.call(this.signingByFileId, key) && !this.signingLoadingByFileId[key]) {
					await this.loadFileSigning(entry.id)
				}
			}
		},
		async loadFileProcessing(fileId) {
			const projectId = Number(this.projectId)
			if (!Number.isFinite(projectId) || projectId <= 0) {
				return
			}
			const key = String(fileId)
			this.$set(this.processingLoadingByFileId, key, true)
			try {
				const payload = await projectsService.getFileProcessing(projectId, Number(fileId))
				if (payload?.processing) {
					this.$set(this.processingByFileId, key, payload.processing)
				}
			} catch (error) {
				this.$set(this.feedbackByFileId, key, error?.response?.data?.message || 'Could not load OCR status.')
			} finally {
				this.$set(this.processingLoadingByFileId, key, false)
			}
		},
		setScope(scope) {
			this.scope = scope
			this.highlightedNodeId = null
			this.ensureSelection(scope)
		},
		ensureSelection(scope) {
			const roots = scope === 'shared' ? (this.sharedRoots || []) : (this.privateRoots || [])
			if (!Array.isArray(roots) || roots.length === 0) {
				this.selectedFolderIdByScope[scope] = null
				this.expandedIdsByScope[scope] = []
				return
			}

			const currentId = this.selectedFolderIdByScope[scope]
			const hasSelection = currentId !== null && this.findChainById(roots, currentId).length > 0
			if (currentId === null || !hasSelection) {
				this.selectedFolderIdByScope[scope] = roots[0].id
			}

			if (!this.expandedIdsByScope[scope].some((id) => String(id) === String(roots[0].id))) {
				this.expandedIdsByScope[scope] = [roots[0].id, ...this.expandedIdsByScope[scope]]
			}
		},
		clearSearch() {
			this.search = ''
		},
		toggleFolder(node) {
			if (!node || node.type !== 'folder') {
				return
			}
			const list = this.expandedIdsByScope[this.scope] || []
			const exists = list.some((id) => String(id) === String(node.id))
			this.expandedIdsByScope[this.scope] = exists
				? list.filter((id) => String(id) !== String(node.id))
				: [...list, node.id]
		},
		selectFolder(node) {
			if (!node || node.type !== 'folder') {
				return
			}
			this.selectedFolderIdByScope[this.scope] = node.id
			const chain = this.findChainById(this.activeRoots, node.id)
			const expanded = new Set(this.expandedIdsByScope[this.scope] || [])
			for (const crumb of chain) {
				if (crumb && crumb.type === 'folder') {
					expanded.add(crumb.id)
				}
			}
			this.expandedIdsByScope[this.scope] = Array.from(expanded)
			this.highlightedNodeId = null
		},
		activateSearchHit(hit) {
			if (!hit || !hit.node) {
				return
			}
			if (hit.node.type === 'folder') {
				this.search = ''
				this.selectFolder(hit.node)
				return
			}

			const chain = Array.isArray(hit.chain) ? hit.chain : []
			const parent = chain.length >= 2 ? chain[chain.length - 2] : null
			if (parent && parent.type === 'folder') {
				this.search = ''
				this.selectFolder(parent)
				this.highlightedNodeId = hit.node.id
			}
		},
		openNodeInFiles(node) {
			if (!node || !node.path) {
				return
			}
			if (node.type === 'folder') {
				const dir = this.dirFromNodePath(node.path)
				const url = generateUrl(`/apps/files/?dir=${encodeURIComponent(dir)}`)
				window.open(url, '_blank')
				return
			}

			this.openFileInFiles(node)
		},
		openFileInFiles(fileNode) {
			if (!fileNode) {
				return
			}

			const chain = this.findChainById(this.activeRoots, fileNode.id)
			const parent = chain.length >= 2 ? chain[chain.length - 2] : null
			const dir = parent?.path ? this.dirFromNodePath(parent.path) : (fileNode.path ? this.dirFromNodePath(fileNode.path, true) : '/')
			const url = generateUrl(`/apps/files/?dir=${encodeURIComponent(dir)}&openfile=${encodeURIComponent(fileNode.id)}`)
			window.open(url, '_blank')
		},
		downloadFile(fileNode) {
			if (!fileNode?.path) {
				return
			}
			const davPath = this.normalizedDavPath(fileNode.path)
			const href = webdavClient.getFileDownloadLink(davPath)
			this.triggerDownload(href)
		},
		downloadFolderZip(folderNode) {
			if (!folderNode?.path) {
				return
			}
			const davPath = this.normalizedDavPath(folderNode.path)
			const url = new URL(webdavClient.getFileDownloadLink(davPath))
			url.searchParams.append('accept', 'zip')
			this.triggerDownload(url.href)
		},
		triggerDownload(href) {
			const link = document.createElement('a')
			link.href = href
			link.style.display = 'none'
			document.body.appendChild(link)
			link.click()
			link.remove()
		},
		dirFromNodePath(path, parent = false) {
			if (!path || typeof path !== 'string') {
				return '/'
			}
			const marker = '/files'
			const idx = path.indexOf(marker)
			let dir = idx >= 0 ? path.slice(idx + marker.length) : path
			if (!dir.startsWith('/')) {
				dir = `/${dir}`
			}
			if (parent) {
				const parts = dir.split('/').filter(Boolean)
				parts.pop()
				dir = '/' + parts.join('/')
				if (dir === '') {
					dir = '/'
				}
			}
			return dir
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
		joinNodePath(basePath, fileName) {
			const base = String(basePath || '').replace(/\/+$/, '')
			const name = String(fileName || '').replace(/^\/+/, '')
			return `${base}/${name}`
		},
		findNodeByPath(nodes, targetPath) {
			const normalizedTarget = String(targetPath || '').replace(/\/+$/, '')
			const list = Array.isArray(nodes) ? nodes : []
			for (const node of list) {
				if (!node) {
					continue
				}
				const nodePath = String(node.path || '').replace(/\/+$/, '')
				if (nodePath === normalizedTarget) {
					return node
				}
				if (Array.isArray(node.children) && node.children.length > 0) {
					const childMatch = this.findNodeByPath(node.children, normalizedTarget)
					if (childMatch) {
						return childMatch
					}
				}
			}
			return null
		},
		async resolvePendingUploadedFiles() {
			if (this.resolvingPendingUploads || this.pendingUploadTargets.length === 0) {
				return
			}
			const projectId = Number(this.projectId)
			if (!Number.isFinite(projectId) || projectId <= 0) {
				return
			}

			this.resolvingPendingUploads = true
			const remaining = []
			for (const pendingTarget of this.pendingUploadTargets) {
				const targetPath = pendingTarget?.path || ''
				const node = this.findNodeByPath([...this.sharedRoots, ...this.privateRoots], targetPath)
				if (!node) {
					remaining.push(pendingTarget)
					continue
				}
				if (!this.isSupportedFile(node)) {
					continue
				}
				await this.assignDocumentType(node, pendingTarget.documentTypeId, true)
			}
			this.pendingUploadTargets = remaining
			this.resolvingPendingUploads = false
		},
		async resolvePendingSigningUploads() {
			if (this.resolvingPendingSigningUploads || this.pendingSigningUploadTargets.length === 0) {
				return
			}
			const projectId = Number(this.projectId)
			if (!Number.isFinite(projectId) || projectId <= 0) {
				return
			}

			this.resolvingPendingSigningUploads = true
			const remaining = []
			let sent = 0
			for (const pendingTarget of this.pendingSigningUploadTargets) {
				const targetPath = pendingTarget?.path || ''
				const node = this.findNodeByPath([...this.sharedRoots, ...this.privateRoots], targetPath)
				if (!node) {
					remaining.push(pendingTarget)
					continue
				}
				if (!this.isSignableFile(node)) {
					continue
				}
				const request = await this.createSigningRequestForFile(node.id, pendingTarget.flow, pendingTarget.signers, pendingTarget.placements || [])
				if (request) {
					sent += 1
				}
			}
			this.pendingSigningUploadTargets = remaining
			this.resolvingPendingSigningUploads = false
			if (sent > 0) {
				this.signingUploadMessage = `Sent ${sent} signing request${sent === 1 ? '' : 's'}.`
			}
		},
		formatBytes(bytes) {
			const value = typeof bytes === 'number' ? bytes : Number(bytes)
			if (!Number.isFinite(value) || value <= 0) {
				return '0 B'
			}
			const units = ['B', 'KB', 'MB', 'GB', 'TB']
			let v = value
			let i = 0
			while (v >= 1024 && i < units.length - 1) {
				v /= 1024
				i += 1
			}
			const digits = i === 0 ? 0 : (v < 10 ? 1 : 0)
			return `${v.toFixed(digits)} ${units[i]}`
		},
		countFiles(node) {
			if (!node) {
				return 0
			}
			if (node.type === 'file') {
				return 1
			}
			const children = Array.isArray(node.children) ? node.children : []
			let count = 0
			for (const child of children) {
				count += this.countFiles(child)
			}
			return count
		},
		countFilesInRoots(roots) {
			const list = Array.isArray(roots) ? roots : []
			let count = 0
			for (const node of list) {
				count += this.countFiles(node)
			}
			return count
		},
		walkWithChain(nodes, chain, cb) {
			const list = Array.isArray(nodes) ? nodes : []
			for (const node of list) {
				if (!node) {
					continue
				}
				const nextChain = [...chain, node]
				cb(node, nextChain)
				if (Array.isArray(node.children) && node.children.length > 0) {
					this.walkWithChain(node.children, nextChain, cb)
				}
			}
		},
		findChainById(nodes, targetId) {
			const list = Array.isArray(nodes) ? nodes : []
			for (const node of list) {
				if (!node) {
					continue
				}
				if (String(node.id) === String(targetId)) {
					return [node]
				}
				if (Array.isArray(node.children) && node.children.length > 0) {
					const childChain = this.findChainById(node.children, targetId)
					if (childChain.length > 0) {
						return [node, ...childChain]
					}
				}
			}
			return []
		},
		isSupportedFile(node) {
			return !!(node && node.type === 'file' && SUPPORTED_MIME_TYPES.includes(String(node.mimetype || '').toLowerCase()))
		},
		isSignableFile(node) {
			return !!(node && node.type === 'file' && String(node.mimetype || '').toLowerCase() === 'application/pdf')
		},
		isPdfUploadFile(file) {
			const type = String(file?.type || '').toLowerCase()
			const name = String(file?.name || '').toLowerCase()
			return type === 'application/pdf' || name.endsWith('.pdf')
		},
		signingTitle(fileId) {
			const request = this.signingByFileId[String(fileId)] || null
			return request ? `Signing: ${this.signingStatusLabel(fileId)}` : 'Request signature'
		},
		signingStatusLabel(fileId) {
			const request = this.signingByFileId[String(fileId)] || null
			if (!request) return 'Not sent'
			if (request.status === 'failed') return 'Signing failed'
			if (request.status === 'completed' || request.status === 'signed') return 'Signed'
			if (request.status === 'cancelled') return 'Cancelled'
			return 'Signing pending'
		},
		signingBadgeClass(fileId) {
			const request = this.signingByFileId[String(fileId)] || null
			if (!request) return ''
			if (request.status === 'failed') return 'project-files__signing-pill--error'
			if (request.status === 'completed' || request.status === 'signed') return 'project-files__signing-pill--success'
			if (request.status === 'cancelled') return 'project-files__signing-pill--muted'
			return 'project-files__signing-pill--pending'
		},
		async loadFileSigning(fileId) {
			const projectId = Number(this.projectId)
			if (!Number.isFinite(projectId) || projectId <= 0) {
				return
			}
			const key = String(fileId)
			this.$set(this.signingLoadingByFileId, key, true)
			try {
				const payload = await projectsService.getFileSigningRequest(projectId, Number(fileId))
				if (payload?.request) {
					this.$set(this.signingByFileId, key, payload.request)
				}
			} finally {
				this.$set(this.signingLoadingByFileId, key, false)
			}
		},
		openSigningModal(node) {
			if (!this.isSignableFile(node)) {
				return
			}
			this.activeSigningFile = node
			this.signingDraft = { flow: 'parallel', signersText: '', memberIds: [], placements: {} }
			this.signingError = ''
			this.loadProjectMembers()
		},
		closeSigningModal() {
			if (this.signingBusy) {
				return
			}
			this.activeSigningFile = null
			this.signingError = ''
		},
		parseSigningSigners() {
			return this.buildSignerPayload(this.signingDraft.memberIds, this.signingDraft.signersText)
		},
		buildSignerPayload(memberIds, text) {
			const selected = new Set((Array.isArray(memberIds) ? memberIds : []).map((id) => String(id)))
			const memberSigners = this.projectMembers
				.filter((member) => selected.has(String(member?.id || '')))
				.map((member) => ({
					userId: member.id,
					displayName: member.displayName || member.id,
					signerKey: `account:${String(member.id).toLowerCase()}`,
				}))
			return [...memberSigners, ...this.parseSignerText(text)]
		},
		ensurePlacementDrafts(draft, signers) {
			if (!draft.placements || typeof draft.placements !== 'object') {
				this.$set(draft, 'placements', {})
			}
			signers.forEach((signer, index) => {
				const key = signer.signerKey || this.signerKey(signer)
				if (!key) return
				signer.signerKey = key
				if (!draft.placements[key]) {
					this.$set(draft.placements, key, {
						page: 1,
						left: 80,
						top: 120 + (index * 80),
						width: 180,
						height: 60,
					})
				}
			})
			return signers
		},
		buildPlacementPayload(draft, signers) {
			return signers
				.map((signer) => {
					const key = signer.signerKey || this.signerKey(signer)
					const placement = key ? draft.placements?.[key] : null
					if (!placement) return null
					return {
						signerKey: key,
						type: 'signature',
						page: Number(placement.page) || 1,
						left: Number(placement.left) || 0,
						top: Number(placement.top) || 0,
						width: Number(placement.width) || 180,
						height: Number(placement.height) || 60,
					}
				})
				.filter(Boolean)
		},
		signerKey(signer) {
			if (signer?.userId) {
				return `account:${String(signer.userId).toLowerCase()}`
			}
			if (signer?.email) {
				return `email:${String(signer.email).toLowerCase()}`
			}
			return ''
		},
		parseSignerText(text) {
			return String(text || '')
				.split(/[\n,;]/)
				.map((email) => email.trim())
				.filter((email) => email !== '')
				.map((email) => ({ email, displayName: email, signerKey: `email:${email.toLowerCase()}` }))
		},
		async createSigningRequestForFile(fileId, flow, signers, placements = []) {
			const projectId = Number(this.projectId)
			const normalizedFileId = Number(fileId)
			if (!Number.isFinite(projectId) || projectId <= 0 || !Number.isFinite(normalizedFileId) || normalizedFileId <= 0) {
				return null
			}
			try {
				const payload = await projectsService.createFileSigningRequest(projectId, normalizedFileId, {
					signature_flow: flow,
					signers,
					placements,
				})
				if (payload?.request) {
					this.$set(this.signingByFileId, String(normalizedFileId), payload.request)
					this.$set(this.feedbackByFileId, String(normalizedFileId), 'Signing request sent.')
					return payload.request
				}
			} catch (error) {
				const message = error?.response?.data?.message || 'Could not send signing request.'
				this.$set(this.feedbackByFileId, String(normalizedFileId), message)
				this.signingUploadError = message
				if (String(this.activeSigningFile?.id || '') === String(normalizedFileId)) {
					this.signingError = message
				}
			}
			return null
		},
		async createSigningRequest() {
			const projectId = Number(this.projectId)
			const fileId = Number(this.activeSigningFile?.id)
			if (!Number.isFinite(projectId) || projectId <= 0 || !Number.isFinite(fileId) || fileId <= 0) {
				return
			}
			const signers = this.parseSigningSigners()
			if (signers.length === 0) {
				this.signingError = 'Select at least one project member or add an external signer email.'
				return
			}
			this.signingBusy = true
			this.signingError = ''
			try {
				const request = await this.createSigningRequestForFile(fileId, this.signingDraft.flow, signers, this.buildPlacementPayload(this.signingDraft, signers))
				if (request) {
					this.activeSigningFile = null
				}
			} catch (error) {
				this.signingError = error?.response?.data?.message || 'Could not send signing request.'
			} finally {
				this.signingBusy = false
			}
		},
		documentTypeValue(fileId) {
			const record = this.processingByFileId[String(fileId)] || null
			return record?.document_type_id || ''
		},
		processingDocumentTypeLabel(fileId) {
			const record = this.processingByFileId[String(fileId)] || null
			const documentTypeId = Number(record?.document_type_id ?? 0)
			if (!Number.isFinite(documentTypeId) || documentTypeId <= 0) {
				return 'No type'
			}
			const documentType = this.documentTypes.find((type) => Number(type?.id) === documentTypeId) || null
			return documentType?.name || 'Unknown type'
		},
		statusLabel(fileId) {
			const record = this.processingByFileId[String(fileId)] || null
			if (!record) {
				return 'Not processed'
			}
			if (record.ocr_status === 'failed') {
				return 'Failed'
			}
			if (record.ocr_status === 'aborted') {
				const missingCount = this.missingFieldsCount(fileId)
				return missingCount > 0 ? `Aborted (${missingCount} missing)` : 'Aborted'
			}
			if (record.ocr_status === 'stale') {
				return 'Stale'
			}
			if (record.ocr_status === 'processing') {
				return 'Processing'
			}
			if (record.ocr_status === 'done') {
				if (this.hasPartialExtraction(fileId)) {
					const missingCount = this.missingFieldsCount(fileId)
					return missingCount > 0 ? `Partial (${missingCount} missing)` : 'Partial'
				}
				return 'Ready'
			}
			return 'Queued'
		},
		isProcessingBusy(fileId) {
			const key = String(fileId)
			return !!(this.processingLoadingByFileId[key] || this.assigningByFileId[key])
		},
		fileFeedback(fileId) {
			return this.feedbackByFileId[String(fileId)] || ''
		},
		extractedEntries(fileId, includeEmpty = false) {
			const record = this.processingByFileId[String(fileId)] || null
			const extracted = record?.extracted && typeof record.extracted === 'object' ? record.extracted : {}
			const entriesByKey = {}
			const ordered = []
			for (const name of this.expectedFieldNames(fileId)) {
				if (!entriesByKey[name]) {
					const payload = extracted[name] && typeof extracted[name] === 'object' ? extracted[name] : {}
					const value = payload.value ?? null
					entriesByKey[name] = {
						key: name,
						name,
						value,
						missing: value === null || String(value).trim() === '',
					}
					ordered.push(entriesByKey[name])
				}
			}

			for (const [key, payload] of Object.entries(extracted)) {
				const value = payload && typeof payload === 'object' ? payload.value : null
				const name = payload && typeof payload === 'object' ? (payload.name || payload.label || key) : key
				if (!entriesByKey[key]) {
					entriesByKey[key] = {
						key,
						name,
						value,
						missing: value === null || String(value).trim() === '',
					}
					ordered.push(entriesByKey[key])
				}
			}

			if (includeEmpty) {
				return ordered
			}

			return ordered.filter((item) => !item.missing)
		},
		expectedFieldNames(fileId) {
			const record = this.processingByFileId[String(fileId)] || null
			const documentTypeId = Number(record?.document_type_id ?? 0)
			if (!Number.isFinite(documentTypeId) || documentTypeId <= 0) {
				return []
			}

			const documentType = this.documentTypes.find((type) => Number(type?.id) === documentTypeId) || null
			const fields = Array.isArray(documentType?.fields) ? documentType.fields : []
			return fields
				.map((field) => {
					if (typeof field === 'string') {
						return field.trim()
					}
					const normalized = field && typeof field === 'object' ? field : {}
					return String(normalized?.name || normalized?.label || normalized?.key || '').trim()
				})
				.filter((name) => name !== '')
		},
		missingFieldsCount(fileId) {
			return this.extractedEntries(fileId, true).filter((item) => item.missing).length
		},
		hasPartialExtraction(fileId) {
			const record = this.processingByFileId[String(fileId)] || null
			if (!record || record.ocr_status !== 'done') {
				return false
			}
			const entries = this.extractedEntries(fileId, true)
			if (entries.length === 0) {
				return false
			}
			const filled = entries.filter((item) => !item.missing).length
			const missing = entries.length - filled
			return filled > 0 && missing > 0
		},
		isAssigning(fileId) {
			return !!this.assigningByFileId[String(fileId)]
		},
		statusIcon(fileId) {
			const record = this.processingByFileId[String(fileId)] || null
			if (!record) {
				return 'FileQuestionOutline'
			}
			if (record.ocr_status === 'failed') {
				return 'AlertCircleOutline'
			}
			if (record.ocr_status === 'aborted') {
				return 'AlertCircleOutline'
			}
			if (record.ocr_status === 'stale') {
				return 'ClockOutline'
			}
			if (record.ocr_status === 'processing') {
				return 'Sync'
			}
			if (record.ocr_status === 'done') {
				if (this.hasPartialExtraction(fileId)) {
					return 'AlertCircleOutline'
				}
				return 'CheckCircleOutline'
			}
			return 'ClockOutline'
		},
		statusBadgeClass(fileId) {
			const record = this.processingByFileId[String(fileId)] || null
			if (!record) return 'project-files__status-inline--muted'
			if (record.ocr_status === 'failed') return 'project-files__status-inline--error'
			if (record.ocr_status === 'aborted') return 'project-files__status-inline--partial'
			if (record.ocr_status === 'done' && this.hasPartialExtraction(fileId)) return 'project-files__status-inline--partial'
			if (record.ocr_status === 'done') return 'project-files__status-inline--success'
			if (record.ocr_status === 'processing') return 'project-files__status-inline--spin'
			return 'project-files__status-inline--pending'
		},
		isErrorFeedback(fileId) {
			const feedback = this.fileFeedback(fileId)
			if (!feedback) {
				return false
			}
			return feedback.toLowerCase().includes('could not') || feedback.toLowerCase().includes('failed')
		},
		setProcessingResult(fileId, payload, successMessage, queuedMessage) {
			const key = String(fileId)
			if (payload?.processing) {
				this.$set(this.processingByFileId, key, payload.processing)
			}

			const nextStatus = this.statusLabel(fileId)
			if (nextStatus.startsWith('Aborted')) {
				return payload?.processing?.error_message || 'Document processing aborted due to missing fields.'
			}
			if (nextStatus.startsWith('Partial')) {
				return 'Document processed with missing fields.'
			}
			if (nextStatus === 'Ready') {
				return successMessage
			}
			if (nextStatus === 'Failed') {
				return payload?.processing?.error_message || 'OCR processing failed.'
			}
			return queuedMessage
		},
		canReprocess(fileId) {
			const record = this.processingByFileId[String(fileId)] || null
			if (!record) {
				return false
			}
			if (this.isProcessingBusy(fileId)) {
				return false
			}
			return record.ocr_status !== 'processing'
		},
		canOpenExtractedDataModal(fileId) {
			const record = this.processingByFileId[String(fileId)] || null
			return !!(record && (record.document_type_id || this.extractedEntries(fileId, true).length > 0))
		},
		setActiveExtractedDraft(fieldName, value) {
			this.$set(this.activeExtractedDraft, fieldName, String(value ?? ''))
		},
		isSavingExtracted(fileId) {
			return String(this.savingExtractedFileId || '') === String(fileId || '')
		},
		async assignDocumentType(node, documentTypeId, silent = false) {
			if (!this.isSupportedFile(node)) {
				return
			}
			const projectId = Number(this.projectId)
			const normalizedDocumentTypeId = Number(documentTypeId)
			if (!Number.isFinite(projectId) || projectId <= 0 || !Number.isFinite(normalizedDocumentTypeId) || normalizedDocumentTypeId <= 0) {
				return
			}
			const key = String(node.id)
			this.$set(this.assigningByFileId, key, true)
			this.$delete(this.feedbackByFileId, key)
			try {
				const payload = await projectsService.assignFileDocumentType(projectId, Number(node.id), normalizedDocumentTypeId)
				const message = this.setProcessingResult(
					node.id,
					payload,
					'Document processed successfully.',
					'Document type assigned. OCR is queued.',
				)
				if (!silent || message) {
					this.$set(this.feedbackByFileId, key, message)
				}
			} catch (error) {
				this.$set(this.feedbackByFileId, key, error?.response?.data?.message || 'Could not assign document type.')
			} finally {
				this.$set(this.assigningByFileId, key, false)
			}
		},
		async reprocessFile(node) {
			if (!this.isSupportedFile(node)) {
				return
			}
			const projectId = Number(this.projectId)
			if (!Number.isFinite(projectId) || projectId <= 0) {
				return
			}
			const key = String(node.id)
			this.$set(this.assigningByFileId, key, true)
			this.$delete(this.feedbackByFileId, key)
			try {
				const payload = await projectsService.reprocessFileProcessing(projectId, Number(node.id))
				const message = this.setProcessingResult(
					node.id,
					payload,
					'Document reprocessed successfully.',
					'Document reprocessing is queued.',
				)
				this.$set(this.feedbackByFileId, key, message)
			} catch (error) {
				this.$set(this.feedbackByFileId, key, error?.response?.data?.message || 'Could not reprocess OCR for this file.')
			} finally {
				this.$set(this.assigningByFileId, key, false)
			}
		},
		openExtractedDataModal(fileId) {
			this.activeExtractedFileId = fileId
			const draft = {}
			for (const item of this.extractedEntries(fileId, true)) {
				draft[item.key] = item.value === null || item.value === undefined ? '' : String(item.value)
			}
			this.activeExtractedDraft = draft
		},
		closeExtractedDataModal() {
			this.activeExtractedFileId = null
			this.activeExtractedDraft = {}
			this.savingExtractedFileId = null
		},
		async saveActiveExtractedData() {
			const projectId = Number(this.projectId)
			const fileId = Number(this.activeExtractedFileId)
			if (!Number.isFinite(projectId) || projectId <= 0 || !Number.isFinite(fileId) || fileId <= 0) {
				return
			}

			this.savingExtractedFileId = fileId
			const key = String(fileId)
			this.$delete(this.feedbackByFileId, key)
			try {
				const payload = await projectsService.updateFileExtractedFields(projectId, fileId, this.activeExtractedDraft)
				if (payload?.processing) {
					this.$set(this.processingByFileId, key, payload.processing)
				}
				this.$set(this.feedbackByFileId, key, 'Extracted fields saved.')
			} catch (error) {
				this.$set(this.feedbackByFileId, key, error?.response?.data?.message || 'Could not save extracted fields.')
			} finally {
				this.savingExtractedFileId = null
			}
		},
	},
}
</script>

<style scoped>
.project-files {
	display: grid;
	gap: 16px;
}

.project-files__upload-input {
	display: none;
}

.project-files__upload-error,
.project-files__upload-success {
	font-size: 14px;
	padding: 10px 12px;
	border-radius: 8px;
	border: 1px solid var(--color-border);
	margin-top: 8px;
}

.project-files__upload-error {
	background: rgba(255, 0, 0, 0.06);
	color: var(--color-error-text, var(--color-text-maxcontrast));
	border-color: rgba(255, 0, 0, 0.25);
}

.project-files__upload-success {
	background: rgba(0, 128, 0, 0.06);
	color: var(--color-success-text, var(--color-text-maxcontrast));
	border-color: rgba(0, 128, 0, 0.25);
}

.project-files__header {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	align-items: center;
	justify-content: space-between;
}

.project-files__tabs {
	display: inline-flex;
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 4px;
	gap: 4px;
}

.project-files__tab {
	border: 0;
	background: transparent;
	padding: 8px 16px;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	font-weight: 700;
	display: inline-flex;
	align-items: center;
	gap: 8px;
	border-radius: 6px;
	transition: background-color 0.2s, color 0.2s, box-shadow 0.2s;
}

.project-files__tab--active {
	background: var(--color-main-background);
	color: var(--color-main-text);
	box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.project-files__tab-pill {
	min-width: 24px;
	padding: 2px 6px;
	border-radius: 12px;
	background: var(--color-background-hover);
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.project-files__tools {
	flex: 1;
	min-width: 260px;
	max-width: 620px;
	display: flex;
	gap: 12px;
	align-items: flex-end;
}

.project-files__clear {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	border-radius: 8px;
	padding: 8px 16px;
	cursor: pointer;
	font-weight: 600;
	transition: all 0.2s;
}

.project-files__clear:hover {
	background: var(--color-background-hover);
	border-color: var(--color-border-dark);
}

.project-files__muted {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.project-files__grid {
	display: grid;
	grid-template-columns: minmax(260px, 320px) minmax(0, 1fr);
	gap: 16px;
	min-height: 480px;
}

.project-files__pane {
	border: 1px solid var(--color-border);
	border-radius: 12px;
	background: var(--color-main-background);
	min-height: 0;
	display: flex;
	flex-direction: column;
	box-shadow: 0 2px 8px rgba(0,0,0,0.02);
}

.project-files__pane--left {
	overflow: hidden;
}

.project-files__pane-title {
	padding: 14px 16px;
	font-size: 12px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	color: var(--color-text-maxcontrast);
	border-bottom: 1px solid var(--color-border);
}

.project-files__tree {
	padding: 12px 8px;
	overflow: auto;
	min-height: 0;
}

.project-files__right-top {
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
	display: flex;
	gap: 16px;
	align-items: center;
	justify-content: space-between;
	flex-wrap: wrap;
	background: var(--color-background-hover);
	border-radius: 12px 12px 0 0;
}

.project-files__breadcrumbs {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	align-items: center;
	min-width: 0;
}

.project-files__crumb {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	border-radius: 8px;
	padding: 6px 12px;
	cursor: pointer;
	font-size: 13px;
	font-weight: 600;
	max-width: 240px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	transition: all 0.2s;
}

.project-files__crumb:hover {
	background: var(--color-background-hover);
	border-color: var(--color-primary-element);
}

.project-files__actions {
	display: inline-flex;
	gap: 12px;
}

.project-files__upload-type-select {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	border-radius: 8px;
	padding: 8px 12px;
	font-size: 13px;
	font-weight: 600;
	width: 100%;
}

.project-files__upload-type-select:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.project-files__textarea {
	width: 100%;
	min-height: 96px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 10px 12px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	resize: vertical;
}

.project-files__hint {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.project-files__signer-picker {
	display: grid;
	gap: 8px;
	padding: 10px 0;
}

.project-files__member-option {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-background-hover);
	font-size: 13px;
}

.project-files__member-sub {
	margin-left: auto;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.project-files__placements {
	display: grid;
	gap: 8px;
	margin-top: 8px;
	padding: 10px;
	border: 1px solid var(--color-border);
	border-radius: 10px;
}

.project-files__placement-row {
	display: grid;
	grid-template-columns: minmax(120px, 1fr) repeat(5, minmax(64px, 80px));
	gap: 8px;
	align-items: end;
}

.project-files__placement-row label {
	display: grid;
	gap: 4px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.project-files__placement-row input {
	min-width: 0;
	padding: 6px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.project-files__placement-name {
	font-size: 13px;
	font-weight: 600;
	color: var(--color-main-text);
}

@media (max-width: 720px) {
	.project-files__placement-row {
		grid-template-columns: 1fr 1fr;
	}

	.project-files__placement-name {
		grid-column: 1 / -1;
	}
}

.project-files__signing-pill {
	display: inline-flex;
	align-items: center;
	min-height: 24px;
	padding: 2px 8px;
	border-radius: 999px;
	font-size: 12px;
	font-weight: 700;
	white-space: nowrap;
	border: 1px solid var(--color-border);
}

.project-files__signing-pill--pending {
	background: rgba(255, 193, 7, 0.12);
	border-color: rgba(255, 193, 7, 0.35);
}

.project-files__signing-pill--success {
	background: rgba(0, 128, 0, 0.10);
	border-color: rgba(0, 128, 0, 0.35);
}

.project-files__signing-pill--error {
	background: rgba(255, 0, 0, 0.08);
	border-color: rgba(255, 0, 0, 0.30);
}

.project-files__signing-pill--muted {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.project-files__btn {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	border-radius: 8px;
	padding: 8px 14px;
	cursor: pointer;
	font-weight: 600;
	font-size: 13px;
	display: inline-flex;
	align-items: center;
	gap: 8px;
	transition: all 0.2s;
}

.project-files__btn--primary {
	background: var(--color-primary-element);
	color: var(--color-primary-text, #fff);
	border-color: var(--color-primary-element);
}

.project-files__btn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.project-files__btn:not(:disabled):hover {
	background: var(--color-background-hover);
	border-color: var(--color-border-dark);
}

.project-files__btn--primary:not(:disabled):hover {
	background: var(--color-primary-element-hover, var(--color-primary-element));
	filter: brightness(0.9);
}

.project-files__list {
	padding: 0;
	min-height: 0;
	overflow: auto;
}

/* Rows mapping standard file explorer layout */
.project-files__rows {
	list-style: none;
	margin: 0;
	padding: 0;
}

.project-files__row {
	display: flex;
	align-items: center;
	padding: 8px 16px;
	border-bottom: 1px solid var(--color-border);
	transition: background-color 0.1s;
}

.project-files__row:last-child {
	border-bottom: none;
}

.project-files__row:hover {
	background: var(--color-background-hover);
}

.project-files__row--highlight {
	background: var(--color-primary-element-light);
}

.project-files__row-main {
	flex: 1;
	min-width: 0;
	display: flex;
	align-items: center;
	gap: 12px;
	cursor: pointer;
}

.project-files__row-icon {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.project-files__row-info {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.project-files__row-name {
	font-weight: 600;
	color: var(--color-main-text);
	font-size: 14px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.project-files__row-sub {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

/* Row OCR Columns */
.project-files__row-ocr {
	flex: 0 0 auto;
	display: flex;
	align-items: center;
	gap: 16px;
	padding: 0 16px;
	margin-left: 16px;
}

.project-files__row-ocr--empty {
	visibility: hidden;
	pointer-events: none;
}

.project-files__type-label-inline {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	font-weight: 600;
	max-width: 160px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.project-files__status-inline {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 24px;
}

.project-files__status-inline--success {
	color: var(--color-success, #008200);
}

.project-files__status-inline--error {
	color: var(--color-error, #e9322d);
}

.project-files__status-inline--pending {
	color: var(--color-warning, #ffa500);
}

.project-files__status-inline--partial {
	color: #a06700;
}

.project-files__status-inline--muted {
	color: var(--color-text-maxcontrast);
}

.project-files__status-inline--spin {
	color: var(--color-primary-element);
	animation: project-files-spin 2s linear infinite;
}

@keyframes project-files-spin {
	from { transform: rotate(0deg); }
	to { transform: rotate(360deg); }
}

/* Actions inline */
.project-files__row-actions {
	flex: 0 0 auto;
	display: flex;
	align-items: center;
	gap: 4px;
	min-width: 100px; /* reserving space so rows don't shift too much */
	justify-content: flex-end;
}

.project-files__icon-btn {
	background: transparent;
	border: none;
	border-radius: 4px;
	width: 32px;
	height: 32px;
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	opacity: 0;
	transition: all 0.15s;
}

.project-files__row:hover .project-files__icon-btn {
	opacity: 1;
}

.project-files__icon-btn:hover {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.project-files__loading-wrap {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
}

/* Extracted Data Modal styles */
.project-files__modal-overlay {
	position: fixed;
	top: 0; left: 0; right: 0; bottom: 0;
	background: rgba(0, 0, 0, 0.4);
	z-index: 10000;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 16px;
}

.project-files__modal {
	background: var(--color-main-background);
	border-radius: 12px;
	width: 100%;
	max-width: 500px;
	box-shadow: 0 8px 24px rgba(0,0,0,0.15);
	display: flex;
	flex-direction: column;
	max-height: 90vh;
}

.project-files__upload-modal {
	max-width: 560px;
}

.project-files__modal-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 16px 20px;
	border-bottom: 1px solid var(--color-border);
}

.project-files__modal-title {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
	color: var(--color-main-text);
}

.project-files__modal-close {
	background: transparent;
	border: none;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 4px;
	border-radius: 4px;
}

.project-files__modal-close:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.project-files__modal-content {
	padding: 20px;
	overflow-y: auto;
}

.project-files__modal-filename {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
	word-break: break-all;
}

.project-files__upload-modal-label {
	display: block;
	font-size: 13px;
	font-weight: 700;
	color: var(--color-main-text);
	margin-bottom: 8px;
}

.project-files__upload-modal-row {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 16px;
}

.project-files__upload-modal-hint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.project-files__upload-file-list {
	list-style: none;
	margin: 16px 0 0;
	padding: 0;
	border: 1px solid var(--color-border);
	border-radius: 10px;
	max-height: 220px;
	overflow: auto;
}

.project-files__upload-file-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 10px 12px;
	border-bottom: 1px solid var(--color-border);
	font-size: 13px;
	color: var(--color-main-text);
}

.project-files__upload-file-item:last-child {
	border-bottom: none;
}

.project-files__modal-warning {
	margin-bottom: 12px;
	padding: 8px 10px;
	font-size: 13px;
	border-radius: 6px;
	background: rgba(255, 184, 0, 0.12);
	color: #a06700;
	border: 1px solid rgba(255, 184, 0, 0.35);
}

.project-files__data-table {
	width: 100%;
	border-collapse: collapse;
}

.project-files__data-table th,
.project-files__data-table td {
	padding: 12px 14px;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
	font-size: 14px;
}

.project-files__data-table tr:last-child th,
.project-files__data-table tr:last-child td {
	border-bottom: none;
}

.project-files__data-table th {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	width: 40%;
}

.project-files__missing-pill {
	display: inline-flex;
	margin-left: 8px;
	padding: 2px 6px;
	font-size: 11px;
	border-radius: 999px;
	background: rgba(255, 184, 0, 0.12);
	color: #a06700;
	border: 1px solid rgba(255, 184, 0, 0.35);
}

.project-files__field-input {
	width: 100%;
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	border-radius: 6px;
	padding: 8px 10px;
	font-size: 13px;
}

.project-files__modal-actions {
	display: flex;
	justify-content: flex-end;
	margin-top: 14px;
}

.project-files__empty {
	padding: 20px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

@media (max-width: 900px) {
	.project-files__grid {
		grid-template-columns: 1fr;
		min-height: auto;
	}

		.project-files__tools {
			min-width: 100%;
			max-width: none;
		}

		.project-files__row {
			flex-direction: column;
			align-items: stretch;
		}

	.project-files__row-ocr {
		margin-left: 0;
		padding: 8px 0;
		border-left: none;
		justify-content: flex-start;
	}

		.project-files__row-actions {
			justify-content: flex-start;
			padding-top: 8px;
		}

		.project-files__row:hover .project-files__icon-btn {
			opacity: 1; /* Always show or default to show on mobile logic via media queries */
		}
	.project-files__icon-btn {
		opacity: 1;
	}
}
</style>
