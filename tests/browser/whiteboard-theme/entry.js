import Vue from '/app/node_modules/vue/dist/vue.esm.js'
import Board from '/app/src/components/ProjectWhiteboard/WhiteboardBoard.vue'
import '/app/src/new/new-ui.css'

window._oc_webroot = ''
window.__hits = {}

// Stands in for the whiteboard app's viewer handler: the real editor is not
// installed here, so this only proves the host chrome, not Excalidraw itself.
// It does mirror the one branch that matters — renderWhiteboardView.tsx picks
// ReadOnlyViewer over App whenever isEmbedded is set, toolbar and all.
const StubWhiteboard = {
	props: { filename: String, fileid: [String, Number], basename: String, source: String, isEmbedded: Boolean },
	data() { return { hits: 0 } },
	render(h) {
		const readOnly = this.isEmbedded
		return h('div', {
			class: ['stub-whiteboard', readOnly ? 'stub-whiteboard--read-only' : 'stub-whiteboard--editor'],
			style: 'width:100%;height:100%;position:relative;display:flex;align-items:center;justify-content:center;font:13px Arial;color:#52687a;background:repeating-linear-gradient(45deg,#fff,#fff 12px,#f6f8fa 12px,#f6f8fa 24px)',
			on: { pointerdown: () => { this.hits++; window.__hits[this.fileid] = (window.__hits[this.fileid] || 0) + 1 } },
		}, [
			h('span', `stub board ${this.fileid} · ${readOnly ? 'read-only viewer' : 'editor'} · pointerdown: ${this.hits}`),
			readOnly ? null : h('div', { class: 'stub-toolbar', style: 'position:absolute;top:12px;left:50%;transform:translateX(-50%);padding:6px 10px;border:1px solid #d9e2ea;border-radius:10px;background:#fff' }, 'palette'),
		])
	},
}

window.OCA = {
	Viewer: {
		availableHandlers: [{ id: 'whiteboard', mimes: ['application/vnd.excalidraw+json'], component: StubWhiteboard }],
		openWith(id, options) { window.__overlay = options; options.onClose && setTimeout(() => options.onClose(), 50) },
		open() {},
	},
}

const strictReader = async (projectId, limit, offset) => {
	const response = await fetch(`/apps/projectcreatoraio/api/v1/projects/${projectId}/whiteboard/activity?limit=${limit}&offset=${offset}`)
	if (!response.ok) throw new Error(String(response.status))
	return response.json()
}

Vue.mixin({ methods: { t: window.t, n: window.n } })

window.fixture = new Vue({
	el: '#app',
	render: h => h('div', [
		h('main', { class: 'pc-new' }, [
			h('section', { class: 'pc-module' }, [
				h(Board, {
					class: 'pc-whiteboard-theme',
					attrs: { id: 'modern' },
					props: { projectId: 21, userId: 'emma', inlineEditing: true, openMode: 'overlay', activityReader: strictReader },
				}),
			]),
			h('section', { class: 'pc-module' }, [
				h(Board, {
					class: 'pc-whiteboard-theme',
					attrs: { id: 'modern-error' },
					props: { projectId: 23, userId: 'emma', inlineEditing: true, openMode: 'overlay', activityReader: strictReader },
				}),
			]),
		]),
		h('div', { attrs: { id: 'legacy-host' }, style: 'padding:22px;background:#fff' }, [
			h(Board, { attrs: { id: 'legacy' }, props: { projectId: 22, userId: 'emma' } }),
		]),
	]),
})
