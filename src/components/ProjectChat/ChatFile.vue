<template>
	<a
		class="project-chat-file"
		:class="{ 'project-chat-file--image': showPreview }"
		:href="file.link || undefined"
		target="_blank"
		rel="noopener noreferrer"
		:title="file.path || file.name">
		<img
			v-if="showPreview"
			class="project-chat-file__preview"
			:src="previewUrl"
			:alt="file.name"
			loading="lazy"
			@error="previewFailed = true">
		<img
			v-else-if="iconUrl"
			class="project-chat-file__icon"
			:src="iconUrl"
			alt="">
		<FileOutline v-else :size="32" class="project-chat-file__icon" />
		<span class="project-chat-file__meta">
			<span class="project-chat-file__name">{{ file.name }}</span>
			<span v-if="sizeLabel" class="project-chat-file__size">{{ sizeLabel }}</span>
		</span>
	</a>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import FileOutline from 'vue-material-design-icons/FileOutline.vue'
import { fileSizeLabel } from './chatMessage.js'

export default {
	name: 'ChatFile',
	components: {
		FileOutline,
	},
	props: {
		file: {
			type: Object,
			required: true,
		},
	},
	data() {
		return {
			previewFailed: false,
		}
	},
	computed: {
		showPreview() {
			return this.file['preview-available'] === 'yes' && !this.previewFailed
		},
		previewUrl() {
			const query = new URLSearchParams({ fileId: this.file.id, x: 480, y: 320, a: 1 })
			if (this.file.etag) {
				query.set('c', this.file.etag)
			}
			return `${generateUrl('/core/preview')}?${query}`
		},
		iconUrl() {
			return window.OC?.MimeType?.getIconUrl?.(this.file.mimetype) || ''
		},
		sizeLabel() {
			return fileSizeLabel(this.file.size)
		},
	},
}
</script>

<style scoped>
.project-chat-file {
	display: inline-flex;
	align-items: center;
	gap: 10px;
	max-width: 100%;
	margin: 4px 0;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 10px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	text-decoration: none;
	vertical-align: middle;
}

.project-chat-file:hover {
	background: var(--color-background-hover);
}

.project-chat-file--image {
	flex-direction: column;
	align-items: flex-start;
	padding: 6px;
}

.project-chat-file__preview {
	display: block;
	max-width: min(100%, 320px);
	max-height: 240px;
	border-radius: var(--border-radius, 6px);
	object-fit: contain;
}

.project-chat-file__icon {
	width: 32px;
	height: 32px;
	flex-shrink: 0;
}

.project-chat-file__meta {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.project-chat-file__name {
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.project-chat-file__size {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}
</style>
