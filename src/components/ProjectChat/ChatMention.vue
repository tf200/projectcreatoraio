<template>
	<NcUserBubble
		v-if="isUser"
		class="project-chat-mention"
		:user="parameter.id"
		:display-name="parameter.name"
		:primary="isCurrentUser" />
	<span
		v-else
		class="project-chat-mention project-chat-mention--group"
		:class="{ 'project-chat-mention--primary': isGroupMention }">
		@{{ parameter.name }}
	</span>
</template>

<script>
import NcUserBubble from '@nextcloud/vue/components/NcUserBubble'

export default {
	name: 'ChatMention',
	components: {
		NcUserBubble,
	},
	props: {
		parameter: {
			type: Object,
			required: true,
		},
		currentUserId: {
			type: String,
			default: '',
		},
	},
	computed: {
		isUser() {
			return this.parameter.type === 'user'
		},
		isCurrentUser() {
			return this.isUser && this.parameter.id === this.currentUserId
		},
		// "@all" in the room reaches everyone, the viewer included.
		isGroupMention() {
			return this.parameter.type === 'call'
		},
	},
}
</script>

<style scoped>
.project-chat-mention {
	vertical-align: middle;
}

.project-chat-mention--group {
	display: inline-flex;
	align-items: center;
	padding: 0 8px;
	border-radius: var(--border-radius-pill, 20px);
	background: var(--color-background-dark);
	font-weight: 600;
	line-height: 20px;
}

.project-chat-mention--primary {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}
</style>
