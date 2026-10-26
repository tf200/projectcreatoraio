<template>
	<a
		v-if="parameter.link"
		class="project-chat-object"
		:href="parameter.link"
		target="_blank"
		rel="noopener noreferrer">
		<component :is="icon" :size="16" />
		<span>{{ parameter.name }}</span>
	</a>
	<span v-else class="project-chat-object">
		<component :is="icon" :size="16" />
		<span>{{ parameter.name }}</span>
	</span>
</template>

<script>
import CardTextOutline from 'vue-material-design-icons/CardTextOutline.vue'
import MapMarker from 'vue-material-design-icons/MapMarker.vue'
import Poll from 'vue-material-design-icons/Poll.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'

// Shared objects in Talk: polls, locations, deck cards and anything apps add.
const ICONS = {
	'talk-poll': Poll,
	'geo-location': MapMarker,
	'deck-card': CardTextOutline,
}

export default {
	name: 'ChatObject',
	props: {
		parameter: {
			type: Object,
			required: true,
		},
	},
	computed: {
		icon() {
			return ICONS[this.parameter.type] || LinkVariant
		},
	},
}
</script>

<style scoped>
.project-chat-object {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 0 8px;
	border-radius: var(--border-radius-pill, 20px);
	background: var(--color-background-dark);
	color: var(--color-main-text);
	font-weight: 600;
	line-height: 22px;
	text-decoration: none;
	vertical-align: middle;
}

a.project-chat-object:hover {
	background: var(--color-background-hover);
}
</style>
