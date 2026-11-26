<template>
	<div class="folder-tree-node">
		<button
			class="folder-tree-item"
			:class="{ 'folder-tree-item--selected': isSelected }"
			:type="'button'"
			:style="rowStyle"
			@click="$emit('select', node)"
		>
			<span class="folder-tree-item__chev-wrapper" @click.stop="$emit('toggle', node)">
				<span v-if="hasChildren" class="folder-tree-item__chev">
					<ChevronDown v-if="isExpanded" :size="18" />
					<ChevronRight v-else :size="18" />
				</span>
				<span v-else class="folder-tree-item__chev folder-tree-item__chev--empty" />
			</span>
			<FolderOutline class="folder-tree-item__icon" :class="{ 'folder-tree-item__icon--selected': isSelected }" :size="20" />
			<span class="folder-tree-item__name">{{ folderDisplayName(node.name) }}</span>
			<span v-if="fileCount !== null" class="folder-tree-item__meta">{{ fileCount }}</span>
		</button>

		<div v-show="isExpanded" class="folder-tree-item__children">
			<FolderTreeItem
				v-for="child in childFolders"
				:key="child.id"
				:node="child"
				:depth="depth + 1"
				:expanded-ids="expandedIds"
				:selected-id="selectedId"
				@toggle="$emit('toggle', $event)"
				@select="$emit('select', $event)"
			/>
		</div>
	</div>
</template>

<script>
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import { folderDisplayName } from '../../new/folder-names.mjs'

export default {
	name: 'FolderTreeItem',
	components: {
		ChevronDown,
		ChevronRight,
		FolderOutline,
	},
	props: {
		node: {
			type: Object,
			required: true,
		},
		depth: {
			type: Number,
			default: 0,
		},
		expandedIds: {
			type: Array,
			default: () => [],
		},
		selectedId: {
			type: [Number, String],
			default: null,
		},
		showCounts: {
			type: Boolean,
			default: false,
		},
	},
	computed: {
		isSelected() {
			return this.selectedId !== null && String(this.selectedId) === String(this.node.id)
		},
		isExpanded() {
			if (!this.hasChildren) {
				return false
			}
			return this.expandedIds.some((id) => String(id) === String(this.node.id))
		},
		hasChildren() {
			return Array.isArray(this.childFolders) && this.childFolders.length > 0
		},
		childFolders() {
			const children = Array.isArray(this.node.children) ? this.node.children : []
			return children
				.filter((child) => child && child.type === 'folder')
				.slice()
				.sort((a, b) => (a.name || '').localeCompare(b.name || '', undefined, { sensitivity: 'base' }))
		},
		rowStyle() {
			return {
				paddingLeft: `${8 + this.depth * 14}px`,
			}
		},
		fileCount() {
			if (!this.showCounts) {
				return null
			}
			return this.countFiles(this.node)
		},
	},
	methods: {
		folderDisplayName,
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
	},
}
</script>

<style scoped>
.folder-tree-node {
	display: flex;
	flex-direction: column;
}

.folder-tree-item {
	width: 100%;
	border: 0;
	background: transparent;
	color: var(--color-main-text);
	text-align: left;
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 6px 10px;
	border-radius: 8px;
	cursor: pointer;
	transition: background-color 0.15s ease, color 0.15s ease;
	margin-bottom: 2px;
}

.folder-tree-item:hover {
	background: var(--color-background-hover);
}

.folder-tree-item--selected {
	background: var(--color-primary-element-light, rgba(0, 130, 201, 0.1));
	color: var(--color-primary-element);
}

.folder-tree-item__chev-wrapper {
	display: inline-flex;
	align-items: center;
	justify-content: center;
}

.folder-tree-item__chev {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	border-radius: 4px;
	color: var(--color-text-maxcontrast);
	transition: background-color 0.15s, color 0.15s;
}

.folder-tree-item__chev:hover {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.folder-tree-item__chev--empty {
	visibility: hidden;
}

.folder-tree-item__icon {
	color: var(--color-text-maxcontrast);
	margin-right: 2px;
}

.folder-tree-item__icon--selected {
	color: var(--color-primary-element);
}

.folder-tree-item__name {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 14px;
	font-weight: 600;
}

.folder-tree-item__meta {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 11px;
	font-weight: 700;
	padding: 2px 6px;
	border-radius: 12px;
}

.folder-tree-item--selected .folder-tree-item__meta {
	background: var(--color-primary-element);
	color: var(--color-primary-text, #fff);
}

.folder-tree-item__children {
	margin-top: 2px;
}
</style>
