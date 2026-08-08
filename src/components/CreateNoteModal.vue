<template>
	<NcModal
		:show="show"
		size="large"
		@close="close">
		<div class="create-note-modal">
			<div class="create-note-modal__header">
				<h2 class="create-note-modal__title">
					<template v-if="isEditing">
						<Pencil :size="24" />
						Edit Note
					</template>
					<template v-else>
						<Plus :size="24" />
						Create New Note
					</template>
				</h2>
			</div>

			<div class="create-note-modal__content">
				<div class="create-note-modal__field">
					<label class="create-note-modal__label">
						Note Title
						<span class="create-note-modal__required">*</span>
					</label>
					<NcTextField
						v-model="noteTitle"
						placeholder="Enter note title..."
						:disabled="isSaving"
						@keyup.enter="focusContent" />
				</div>

				<div class="create-note-modal__field create-note-modal__field--editor">
					<div class="create-note-modal__label-row">
						<label class="create-note-modal__label">
							Content
							<span class="create-note-modal__required">*</span>
						</label>
						<span class="create-note-modal__hint">WYSIWYG formatting supported</span>
					</div>
					<WysiwygEditor
						ref="contentEditor"
						v-model="noteContent"
						:placeholder="contentPlaceholder"
						:disabled="isSaving"
						:toolbar="true"
						class="create-note-modal__editor" />
				</div>

				<div class="create-note-modal__field">
					<label class="create-note-modal__label" :for="isEditing ? 'edit-note-type' : 'create-note-type'">Type</label>
					<select
						:id="isEditing ? 'edit-note-type' : 'create-note-type'"
						v-model="noteType"
						class="create-note-modal__select"
						:disabled="isSaving">
						<option v-for="type in noteTypeOptions" :key="type.value" :value="type.value">
							{{ type.label }}
						</option>
					</select>
				</div>

				<div class="create-note-modal__field">
					<label class="create-note-modal__label">Visibility</label>
					<div class="create-note-modal__visibility-options">
						<button
							type="button"
							class="create-note-modal__visibility-btn"
							:class="{ 'create-note-modal__visibility-btn--active': noteVisibility === 'public' }"
							:disabled="isEditing"
							@click="noteVisibility = 'public'">
							<Earth :size="18" />
							<div class="create-note-modal__visibility-info">
								<span class="create-note-modal__visibility-label">Public</span>
								<span class="create-note-modal__visibility-desc">Visible to all project members</span>
							</div>
						</button>
						<button
							type="button"
							class="create-note-modal__visibility-btn"
							:class="{ 'create-note-modal__visibility-btn--active': noteVisibility === 'private' }"
							:disabled="isEditing || !canCreatePrivate"
							@click="noteVisibility = 'private'">
							<Lock :size="18" />
							<div class="create-note-modal__visibility-info">
								<span class="create-note-modal__visibility-label">Private</span>
								<span class="create-note-modal__visibility-desc">Only visible to you</span>
							</div>
						</button>
					</div>
				</div>
			</div>

			<div v-if="error" class="create-note-modal__error">
				<AlertCircle :size="18" />
				<span>{{ error }}</span>
			</div>

			<div class="create-note-modal__actions">
				<NcButton type="tertiary" :disabled="isSaving" @click="close">
					Cancel
				</NcButton>
				<NcButton type="primary" :disabled="!canSave || isSaving" @click="save">
					<template #icon>
						<NcLoadingIcon v-if="isSaving" :size="16" />
						<Check v-else :size="16" />
					</template>
					{{ isSaving ? (isEditing ? 'Saving...' : 'Creating...') : (isEditing ? 'Save Changes' : 'Create Note') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Earth from 'vue-material-design-icons/Earth.vue'
import Lock from 'vue-material-design-icons/Lock.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import { ProjectsService } from '../Services/projects.js'
import WysiwygEditor from './WysiwygEditor.vue'
import { DEFAULT_NOTE_TYPE, NOTE_TYPES } from '../constants/note-types.js'

const projectsService = ProjectsService.getInstance()

export default {
	name: 'CreateNoteModal',
	components: {
		NcModal,
		NcButton,
		NcTextField,
		NcLoadingIcon,
		Plus,
		Pencil,
		Check,
		Earth,
		Lock,
		AlertCircle,
		WysiwygEditor,
	},
	props: {
		show: {
			type: Boolean,
			default: false,
		},
		projectId: {
			type: Number,
			required: true,
		},
		note: {
			type: Object,
			default: null,
		},
		visibility: {
			type: String,
			default: 'public',
		},
		canCreatePrivate: {
			type: Boolean,
			default: true,
		},
	},
	data() {
		return {
			noteTitle: '',
			noteContent: '',
			noteVisibility: 'public',
			noteType: DEFAULT_NOTE_TYPE,
			isSaving: false,
			error: '',
		}
	},
	computed: {
		isEditing() {
			return this.note !== null
		},
		canSave() {
			return this.noteTitle.trim().length > 0 && this.hasContentText
		},
		hasContentText() {
			const html = this.noteContent || ''
			// Treat empty paragraphs / whitespace-only HTML as empty
			try {
				const doc = new DOMParser().parseFromString(html, 'text/html')
				const text = (doc.body?.textContent || '').replace(/\u00A0/g, ' ')
				return text.trim().length > 0
			} catch (e) {
				return String(html).replace(/<[^>]*>?/gm, ' ').trim().length > 0
			}
		},
		contentPlaceholder() {
			return this.noteVisibility === 'private'
				? 'Write your private notes here... (only you can see this)'
				: 'Write your notes here... (visible to all project members)'
		},
		noteTypeOptions() {
			return NOTE_TYPES
		},
	},
	watch: {
		show: {
			immediate: true,
			handler(isShown) {
				if (isShown) {
					this.resetForm()
				}
			},
		},
	},
	methods: {
		resetForm() {
			if (this.isEditing && this.note) {
				this.noteTitle = this.note.title || ''
				this.noteContent = this.note.content || ''
				this.noteVisibility = this.note.visibility || 'public'
				this.noteType = this.note.noteType || DEFAULT_NOTE_TYPE
			} else {
				this.noteTitle = ''
				this.noteContent = ''
				this.noteVisibility = this.visibility
				this.noteType = DEFAULT_NOTE_TYPE
			}
			this.error = ''
		},
		focusContent() {
			this.$refs.contentEditor?.focus()
		},
		close() {
			if (!this.isSaving) {
				this.$emit('close')
			}
		},
		async save() {
			if (!this.canSave || this.isSaving) {
				return
			}

			this.error = ''
			this.isSaving = true

			try {
				if (this.isEditing) {
					const result = await projectsService.updateNote(
						this.projectId,
						this.note.id,
						{
							title: this.noteTitle.trim(),
							content: this.noteContent,
							noteType: this.noteType,
						},
					)
					if (result) {
						this.$emit('updated', result)
					}
				} else {
					const result = await projectsService.createNote(
						this.projectId,
						{
							title: this.noteTitle.trim(),
							content: this.noteContent,
							visibility: this.noteVisibility,
							noteType: this.noteType,
						},
					)
					if (result) {
						this.$emit('created', result)
					}
				}
			} catch (err) {
				this.error = err?.response?.data?.message || 'Failed to save note. Please try again.'
			} finally {
				this.isSaving = false
			}
		},
	},
}
</script>

<style scoped>
.create-note-modal {
	display: flex;
	flex-direction: column;
	width: 100%;
}

.create-note-modal__header {
	padding: 24px 32px 16px;
}

.create-note-modal__title {
	margin: 0;
	display: flex;
	align-items: center;
	gap: 12px;
	font-size: 20px;
	font-weight: 700;
	color: var(--color-main-text);
}

.create-note-modal__content {
	padding: 0 32px 24px;
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.create-note-modal__field {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.create-note-modal__label {
	font-size: 13px;
	font-weight: 700;
	color: var(--color-text-lighter);
	margin-left: 2px;
}

.create-note-modal__label-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.create-note-modal__required {
	color: var(--color-error);
	margin-left: 2px;
}

.create-note-modal__hint {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

.create-note-modal__editor {
	min-height: 400px;
}

.create-note-modal__visibility-options {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 16px;
}

.create-note-modal__select {
	min-height: 44px;
	padding: 0 12px;
	border: 1px solid var(--color-border-dark);
	border-radius: 10px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 14px;
}

.create-note-modal__select:focus {
	border-color: var(--color-primary-element);
	outline: 2px solid var(--color-primary-element-light);
}

.create-note-modal__visibility-btn {
	display: flex;
	align-items: flex-start;
	gap: 14px;
	padding: 18px;
	border: 1px solid var(--color-border-dark);
	border-radius: 14px;
	background: var(--color-main-background);
	cursor: pointer;
	transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
	text-align: left;
}

.create-note-modal__visibility-btn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.create-note-modal__visibility-btn:hover:not(:disabled) {
	border-color: var(--color-primary-element);
	background: var(--color-background-hover);
}

.create-note-modal__visibility-btn--active {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element-light);
	border-width: 1px;
}

.create-note-modal__visibility-info {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.create-note-modal__visibility-label {
	font-size: 14px;
	font-weight: 700;
	color: var(--color-main-text);
}

.create-note-modal__visibility-desc {
	font-size: 12px;
	color: var(--color-text-lighter);
	line-height: 1.4;
}

.create-note-modal__error {
	display: flex;
	align-items: center;
	gap: 10px;
	margin: 0 32px 16px;
	padding: 14px 18px;
	background: var(--color-error-light);
	color: var(--color-error);
	border-radius: 10px;
	font-size: 13px;
	font-weight: 500;
}

.create-note-modal__actions {
	display: flex;
	justify-content: flex-end;
	gap: 12px;
	padding: 24px 32px;
	border-top: 1px solid var(--color-border);
}

@media (max-width: 600px) {
	.create-note-modal__header,
	.create-note-modal__content,
	.create-note-modal__actions,
	.create-note-modal__error {
		padding-left: 20px;
		padding-right: 20px;
	}

	.create-note-modal__editor {
		min-height: 300px;
	}

	.create-note-modal__visibility-options {
		grid-template-columns: 1fr;
	}

	.create-note-modal__actions {
		flex-direction: column-reverse;
	}

	.create-note-modal__actions button {
		width: 100%;
	}
}
</style>
