<template>
	<ChatFile v-if="!src || failed" :file="file" />
	<div v-else class="project-chat-media">
		<video
			v-if="kind === 'video'"
			class="project-chat-media__video"
			:src="src"
			controls
			preload="metadata"
			@error="failed = true" />
		<audio
			v-else
			class="project-chat-media__audio"
			:src="src"
			controls
			preload="metadata"
			@error="failed = true" />
	</div>
</template>

<script>
import { generateRemoteUrl } from '@nextcloud/router'
import ChatFile from './ChatFile.vue'
import { davFileUrl } from './chatMessage.js'

export default {
	name: 'ChatMedia',
	components: {
		ChatFile,
	},
	props: {
		file: {
			type: Object,
			required: true,
		},
		kind: {
			type: String,
			required: true,
		},
		currentUserId: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			// Someone outside the room has no copy to play, so they get the file card.
			failed: false,
		}
	},
	computed: {
		src() {
			if (!this.currentUserId) {
				return ''
			}
			return davFileUrl(generateRemoteUrl(`dav/files/${encodeURIComponent(this.currentUserId)}`), this.file)
		},
	},
}
</script>

<style scoped>
.project-chat-media {
	margin: 4px 0;
}

.project-chat-media__audio {
	width: min(100%, 360px);
}

.project-chat-media__video {
	display: block;
	max-width: min(100%, 480px);
	max-height: 320px;
	border-radius: var(--border-radius-large, 10px);
	background: #000;
}
</style>
