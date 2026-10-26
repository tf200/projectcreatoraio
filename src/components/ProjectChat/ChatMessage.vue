<template>
	<div class="project-chat-message project-notes-list__chat-message">
		<NcAvatar
			class="project-chat-message__avatar"
			:user="isUserActor ? message.actorId : undefined"
			:display-name="message.actorDisplayName || message.actorId"
			:is-no-user="!isUserActor"
			:show-user-status="false"
			:size="36" />
		<div class="project-chat-message__content project-notes-list__chat-content">
			<div class="project-chat-message__header project-notes-list__chat-header">
				<span class="project-chat-message__author project-notes-list__chat-author">{{ message.actorDisplayName || message.actorId }}</span>
				<span class="project-chat-message__time project-notes-list__chat-time">{{ timeLabel }}</span>
				<span
					v-if="editLabel"
					class="project-chat-message__edited"
					:title="editLabel">(edited)</span>
			</div>

			<div v-if="message.parent" class="project-chat-message__quote">
				<Reply :size="14" class="project-chat-message__quote-icon" />
				<div class="project-chat-message__quote-body">
					<span class="project-chat-message__quote-author">{{ message.parent.actorDisplayName }}</span>
					<span class="project-chat-message__quote-text" :class="{ 'project-chat-message__quote-text--deleted': message.parent.deleted }">{{ parentText }}</span>
				</div>
			</div>

			<NcRichText
				v-if="message.deleted"
				class="project-chat-message__text project-chat-message__text--deleted project-notes-list__chat-text"
				:text="message.message"
				:arguments="richArguments" />
			<template v-else>
				<ChatMedia
					v-if="fileShare.file && mediaKind"
					:file="fileShare.file"
					:kind="mediaKind"
					:current-user-id="currentUserId" />
				<ChatFile v-else-if="fileShare.file" :file="fileShare.file" />
				<NcRichText
					v-if="fileShare.caption"
					class="project-chat-message__text project-notes-list__chat-text"
					:text="fileShare.caption"
					:arguments="richArguments"
					:use-extended-markdown="true"
					:autolink="true" />
			</template>

			<div v-if="reactions.length" class="project-chat-message__reactions">
				<span
					v-for="reaction in reactions"
					:key="reaction.emoji"
					class="project-chat-message__reaction">
					{{ reaction.emoji }} {{ reaction.count }}
				</span>
			</div>
		</div>
	</div>
</template>

<script>
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcRichText from '@nextcloud/vue/components/NcRichText'
import Reply from 'vue-material-design-icons/Reply.vue'
import ChatFile from './ChatFile.vue'
import ChatMedia from './ChatMedia.vue'
import ChatMention from './ChatMention.vue'
import ChatObject from './ChatObject.vue'
import { buildRichArguments, mediaKind, plainText, reactionList, splitFileShare } from './chatMessage.js'

export default {
	name: 'ChatMessage',
	components: {
		ChatFile,
		ChatMedia,
		NcAvatar,
		NcRichText,
		Reply,
	},
	props: {
		message: {
			type: Object,
			required: true,
		},
		currentUserId: {
			type: String,
			default: '',
		},
		formatDate: {
			type: Function,
			required: true,
		},
	},
	computed: {
		isUserActor() {
			return this.message.actorType === 'users' && !!this.message.actorId
		},
		timeLabel() {
			return this.formatDate(this.message.timestamp * 1000)
		},
		mediaKind() {
			return mediaKind(this.message)
		},
		editLabel() {
			if (!this.message.lastEditTimestamp || this.message.deleted) {
				return ''
			}
			const when = this.formatDate(this.message.lastEditTimestamp * 1000)
			const who = this.message.lastEditActorDisplayName
			return who ? `Edited by ${who}, ${when}` : `Edited ${when}`
		},
		fileShare() {
			return splitFileShare(this.message)
		},
		richArguments() {
			return buildRichArguments(
				this.message.messageParameters,
				{ mention: ChatMention, file: ChatFile, object: ChatObject },
				this.currentUserId,
			)
		},
		parentText() {
			return plainText(this.message.parent)
		},
		reactions() {
			return reactionList(this.message.reactions)
		},
	},
}
</script>

<style scoped>
.project-chat-message {
	display: flex;
	gap: 12px;
	padding: 12px 16px;
	border-radius: 12px;
	transition: background 0.15s ease;
}

.project-chat-message:hover {
	background: var(--color-background-hover);
}

.project-chat-message__avatar {
	flex-shrink: 0;
}

.project-chat-message__content {
	flex: 1;
	min-width: 0;
}

.project-chat-message__header {
	display: flex;
	align-items: baseline;
	gap: 10px;
	margin-bottom: 4px;
}

.project-chat-message__author {
	font-size: 13px;
	font-weight: 700;
	color: var(--color-main-text);
}

.project-chat-message__time {
	font-size: 11px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.project-chat-message__edited {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.project-chat-message__quote {
	display: flex;
	gap: 6px;
	margin-bottom: 6px;
	padding: 4px 8px;
	border-inline-start: 3px solid var(--color-primary-element);
	border-radius: var(--border-radius, 6px);
	background: var(--color-background-dark);
	font-size: 13px;
}

.project-chat-message__quote-icon {
	color: var(--color-text-maxcontrast);
}

.project-chat-message__quote-body {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.project-chat-message__quote-author {
	font-weight: 700;
}

.project-chat-message__quote-text {
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.project-chat-message__text {
	font-size: 14px;
	color: var(--color-main-text);
	line-height: 1.5;
	overflow-wrap: anywhere;
}

.project-chat-message__text--deleted,
.project-chat-message__quote-text--deleted {
	font-style: italic;
	color: var(--color-text-maxcontrast);
}

.project-chat-message__reactions {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-top: 6px;
}

.project-chat-message__reaction {
	padding: 0 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 20px);
	font-size: 13px;
	line-height: 22px;
}
</style>
