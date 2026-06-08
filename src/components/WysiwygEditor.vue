<template>
	<div
		class="wysiwyg"
		:class="{
			'wysiwyg--disabled': disabled,
			'wysiwyg--has-toolbar': toolbar,
			'wysiwyg--focused': isFocused
		}"
		@click="focus">
		<div v-if="toolbar" class="wysiwyg__toolbar">
			<button
				type="button"
				class="wysiwyg__tool"
				:class="{ 'is-active': editor && editor.isActive('bold') }"
				title="Bold"
				:disabled="disabled"
				@click="toggleBold">
				<FormatBold :size="18" />
			</button>
			<button
				type="button"
				class="wysiwyg__tool"
				:class="{ 'is-active': editor && editor.isActive('italic') }"
				title="Italic"
				:disabled="disabled"
				@click="toggleItalic">
				<FormatItalic :size="18" />
			</button>
			<button
				type="button"
				class="wysiwyg__tool"
				:class="{ 'is-active': editor && editor.isActive('underline') }"
				title="Underline"
				:disabled="disabled"
				@click="toggleUnderline">
				<FormatUnderline :size="18" />
			</button>
			<button
				type="button"
				class="wysiwyg__tool"
				:class="{ 'is-active': editor && editor.isActive('strike') }"
				title="Strikethrough"
				:disabled="disabled"
				@click="toggleStrike">
				<FormatStrikethrough :size="18" />
			</button>

			<span class="wysiwyg__sep" aria-hidden="true" />

			<button
				type="button"
				class="wysiwyg__tool"
				:class="{ 'is-active': editor && editor.isActive('bulletList') }"
				title="Bullet list"
				:disabled="disabled"
				@click="toggleBulletList">
				<FormatListBulleted :size="18" />
			</button>
			<button
				type="button"
				class="wysiwyg__tool"
				:class="{ 'is-active': editor && editor.isActive('orderedList') }"
				title="Numbered list"
				:disabled="disabled"
				@click="toggleOrderedList">
				<FormatListNumbered :size="18" />
			</button>
			<button
				type="button"
				class="wysiwyg__tool"
				:class="{ 'is-active': editor && editor.isActive('blockquote') }"
				title="Quote"
				:disabled="disabled"
				@click="toggleBlockquote">
				<FormatQuoteClose :size="18" />
			</button>

			<span class="wysiwyg__sep" aria-hidden="true" />

			<button
				type="button"
				class="wysiwyg__tool"
				:class="{ 'is-active': editor && editor.isActive('link') }"
				title="Link"
				:disabled="disabled"
				@click="setLink">
				<LinkVariant :size="18" />
			</button>
			<button
				type="button"
				class="wysiwyg__tool"
				title="Remove formatting"
				:disabled="disabled"
				@click="clearFormatting">
				<FormatClear :size="18" />
			</button>

			<span class="wysiwyg__spacer" />

			<button
				type="button"
				class="wysiwyg__tool"
				title="Undo"
				:disabled="disabled || !canUndo"
				@click="undo">
				<Undo :size="18" />
			</button>
			<button
				type="button"
				class="wysiwyg__tool"
				title="Redo"
				:disabled="disabled || !canRedo"
				@click="redo">
				<Redo :size="18" />
			</button>
		</div>

		<div class="wysiwyg__content-wrapper">
			<EditorContent :editor="editor" class="wysiwyg__content" />
		</div>
	</div>
</template>

<script>
import { Editor, EditorContent } from '@tiptap/vue-2'
import StarterKitExtension from '@tiptap/starter-kit'
import LinkExtension from '@tiptap/extension-link'
import UnderlineExtension from '@tiptap/extension-underline'
import PlaceholderExtension from '@tiptap/extension-placeholder'
import FormatBold from 'vue-material-design-icons/FormatBold.vue'
import FormatItalic from 'vue-material-design-icons/FormatItalic.vue'
import FormatUnderline from 'vue-material-design-icons/FormatUnderline.vue'
import FormatStrikethrough from 'vue-material-design-icons/FormatStrikethrough.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import FormatListNumbered from 'vue-material-design-icons/FormatListNumbered.vue'
import FormatQuoteClose from 'vue-material-design-icons/FormatQuoteClose.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import FormatClear from 'vue-material-design-icons/FormatClear.vue'
import Undo from 'vue-material-design-icons/Undo.vue'
import Redo from 'vue-material-design-icons/Redo.vue'

export default {
	name: 'WysiwygEditor',
	components: {
		EditorContent,
		FormatBold,
		FormatItalic,
		FormatUnderline,
		FormatStrikethrough,
		FormatListBulleted,
		FormatListNumbered,
		FormatQuoteClose,
		LinkVariant,
		FormatClear,
		Undo,
		Redo,
	},
	props: {
		value: {
			type: String,
			default: '',
		},
		placeholder: {
			type: String,
			default: '',
		},
		disabled: {
			type: Boolean,
			default: false,
		},
		toolbar: {
			type: Boolean,
			default: true,
		},
	},
	data() {
		return {
			editor: null,
			internalUpdate: false,
			isFocused: false,
		}
	},
	computed: {
		canUndo() {
			return !!this.editor && this.editor.can().chain().focus().undo().run()
		},
		canRedo() {
			return !!this.editor && this.editor.can().chain().focus().redo().run()
		},
	},
	watch: {
		value(newValue) {
			if (!this.editor || this.internalUpdate) {
				return
			}
			const current = this.editor.getHTML()
			if ((newValue || '') === current) {
				return
			}
			this.editor.commands.setContent(this.normalizeIncomingContent(newValue), false)
		},
		disabled(isDisabled) {
			if (this.editor) {
				this.editor.setEditable(!isDisabled)
			}
		},
	},
	mounted() {
		this.editor = new Editor({
			editable: !this.disabled,
			content: this.normalizeIncomingContent(this.value),
			extensions: [
				StarterKitExtension.configure({
					heading: {
						levels: [1, 2, 3, 4],
					},
				}),
				UnderlineExtension,
				LinkExtension.configure({
					openOnClick: false,
					autolink: false,
					linkOnPaste: true,
					HTMLAttributes: {
						rel: 'noopener noreferrer',
						target: '_blank',
					},
				}),
				PlaceholderExtension.configure({
					placeholder: this.placeholder || '',
				}),
			],
			onUpdate: ({ editor }) => {
				this.internalUpdate = true
				try {
					this.$emit('input', editor.getHTML())
				} finally {
					this.$nextTick(() => {
						this.internalUpdate = false
					})
				}
			},
			onFocus: () => {
				this.isFocused = true
			},
			onBlur: () => {
				this.isFocused = false
			},
			editorProps: {
				attributes: {
					class: 'wysiwyg__prosemirror',
				},
			},
		})
	},
	destroyed() {
		this.editor?.destroy()
	},
	methods: {
		focus() {
			this.editor?.commands?.focus()
		},
		normalizeIncomingContent(value) {
			const raw = (value || '').trim()
			if (raw === '') {
				return ''
			}
			// If it doesn't look like HTML, treat it as plain text and preserve newlines.
			if (!/<[a-z][\s\S]*>/i.test(raw)) {
				return this.plainTextToHtml(raw)
			}
			return raw
		},
		escapeHtml(text) {
			return String(text)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#039;')
		},
		plainTextToHtml(text) {
			const normalized = String(text).replace(/\r\n/g, '\n').replace(/\r/g, '\n')
			const paragraphs = normalized.split(/\n\n+/g)
			return paragraphs
				.map(p => `<p>${this.escapeHtml(p).replace(/\n/g, '<br>')}</p>`)
				.join('')
		},
		toggleBold() {
			this.editor?.chain().focus().toggleBold().run()
		},
		toggleItalic() {
			this.editor?.chain().focus().toggleItalic().run()
		},
		toggleUnderline() {
			this.editor?.chain().focus().toggleUnderline().run()
		},
		toggleStrike() {
			this.editor?.chain().focus().toggleStrike().run()
		},
		toggleBulletList() {
			this.editor?.chain().focus().toggleBulletList().run()
		},
		toggleOrderedList() {
			this.editor?.chain().focus().toggleOrderedList().run()
		},
		toggleBlockquote() {
			this.editor?.chain().focus().toggleBlockquote().run()
		},
		clearFormatting() {
			this.editor?.chain().focus().unsetAllMarks().clearNodes().run()
		},
		undo() {
			this.editor?.chain().focus().undo().run()
		},
		redo() {
			this.editor?.chain().focus().redo().run()
		},
		normalizeUrl(url) {
			const trimmed = String(url || '').trim()
			if (trimmed === '') return ''
			if (trimmed.startsWith('#') || trimmed.startsWith('/')) return trimmed
			if (/^(https?:|mailto:|tel:)/i.test(trimmed)) return trimmed
			// Basic convenience: treat as https if no scheme
			return `https://${trimmed}`
		},
		setLink() {
			if (!this.editor) return
			const existing = this.editor.getAttributes('link')?.href || ''
			const input = window.prompt('Link URL', existing)
			if (input === null) {
				return
			}
			const url = this.normalizeUrl(input)
			if (url === '') {
				this.editor.chain().focus().extendMarkRange('link').unsetLink().run()
				return
			}
			this.editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run()
		},
	},
}
</script>

<style scoped>
.wysiwyg {
	display: flex;
	flex-direction: column;
	background: var(--color-main-background);
	border: 1px solid var(--color-border-dark);
	border-radius: 12px;
	min-height: 0;
	height: 100%;
	width: 100%;
	overflow: hidden;
	transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.wysiwyg--focused {
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 3px var(--color-primary-element-light);
}

.wysiwyg__toolbar {
	display: flex;
	flex-wrap: wrap;
	gap: 2px;
	align-items: center;
	padding: 6px 14px;
	background: var(--color-background-hover);
}

.wysiwyg__tool {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
	border-radius: 6px;
	border: 1px solid transparent;
	background: transparent;
	color: var(--color-text-lighter);
	cursor: pointer;
	transition: all 0.15s ease;
}

.wysiwyg__tool:hover:not(:disabled) {
	background: var(--color-background-darker);
	color: var(--color-main-text);
}

.wysiwyg__tool:disabled {
	opacity: 0.3;
	cursor: not-allowed;
}

.wysiwyg__tool.is-active {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
	border-color: var(--color-primary-element-light);
}

.wysiwyg__sep {
	width: 1px;
	height: 20px;
	background: var(--color-border-dark);
	margin: 0 6px;
}

.wysiwyg__spacer {
	flex: 1;
}

.wysiwyg__content-wrapper {
	flex: 1;
	min-height: 0;
	display: flex;
	flex-direction: column;
	cursor: text;
	overflow-y: auto;
	border: 0;
	box-shadow: none;
	background: transparent;
}

.wysiwyg__content {
	padding: 0;
	flex: 1;
	display: flex;
	flex-direction: column;
	width: 100%;
	border: 0;
	box-shadow: none;
	background: transparent;
}

.wysiwyg--disabled {
	background: var(--color-background-hover);
	opacity: 0.7;
}

.wysiwyg--disabled .wysiwyg__content-wrapper {
	cursor: not-allowed;
}

/* ProseMirror base */
:deep(.wysiwyg__prosemirror) {
	outline: none;
	border: 0 !important;
	box-shadow: none !important;
	background: transparent !important;
	min-height: 100%;
	width: 100%;
	flex: 1;
	box-sizing: border-box;
	padding: 16px 20px;
	color: var(--color-main-text);
	font-family: var(--font-face);
	font-size: 15px;
	line-height: 1.7;
	-webkit-font-smoothing: antialiased;
}

:deep(.wysiwyg__prosemirror:focus),
:deep(.wysiwyg__prosemirror.ProseMirror-focused) {
	outline: none;
	border: 0 !important;
	box-shadow: none !important;
}

:deep(.wysiwyg__prosemirror p) {
	margin: 0 0 1em;
}

:deep(.wysiwyg__prosemirror p:last-child) {
	margin-bottom: 0;
}

:deep(.wysiwyg__prosemirror ul),
:deep(.wysiwyg__prosemirror ol) {
	padding-left: 1.5em;
	margin-bottom: 1em;
}

:deep(.wysiwyg__prosemirror blockquote) {
	border-left: 4px solid var(--color-primary-element-light);
	margin: 1.25em 0;
	padding: 0.5em 0 0.5em 1.25em;
	color: var(--color-text-lighter);
	font-style: italic;
	background: var(--color-background-hover);
	border-radius: 0 4px 4px 0;
}

:deep(.wysiwyg__prosemirror a) {
	color: var(--color-primary-element);
	text-decoration: underline;
	font-weight: 500;
}

:deep(.wysiwyg__prosemirror p.is-editor-empty:first-child::before) {
	content: attr(data-placeholder);
	float: left;
	color: var(--color-text-maxcontrast);
	pointer-events: none;
	height: 0;
	font-style: italic;
}

:deep(.wysiwyg__prosemirror strong) {
	font-weight: 700;
}
</style>
