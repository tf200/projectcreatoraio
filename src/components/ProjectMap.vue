<template>
	<div class="project-map">
		<div ref="mapRoot" class="project-map__container" />
		<a
			:href="osmLink"
			class="project-map__link"
			target="_blank"
			rel="noopener noreferrer">Open in OpenStreetMap →</a>
	</div>
</template>

<script>
// Static imports — Leaflet is bundled into the main chunk Nextcloud already
// serves. Dynamic/lazy chunks do not deploy reliably on the production host,
// so we trade ~40kb gzipped for deploy reliability.
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

// Inline SVG pin via L.divIcon avoids shipping marker PNG assets as separate
// emitted files (same deploy-reliability concern as the lazy chunks).
const MARKER_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="#b91c1c" stroke="#7f1d1d" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 1 1 18 0z"/><circle cx="12" cy="10" r="3" fill="#fff" stroke="#7f1d1d"/></svg>'

export default {
	name: 'ProjectMap',
	props: {
		lat: { type: Number, required: true },
		lng: { type: Number, required: true },
		displayName: { type: String, default: null },
	},
	data() {
		return { mapInstance: null }
	},
	computed: {
		osmLink() {
			return `https://www.openstreetmap.org/?mlat=${this.lat}&mlon=${this.lng}#map=16/${this.lat}/${this.lng}`
		},
	},
	mounted() {
		this.mapInstance = L.map(this.$refs.mapRoot, { scrollWheelZoom: true })
			.setView([this.lat, this.lng], 16)
		L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			maxZoom: 19,
			attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors',
		}).addTo(this.mapInstance)

		const icon = L.divIcon({
			className: 'project-map__marker',
			html: MARKER_SVG,
			iconSize: [28, 28],
			iconAnchor: [14, 28],
			popupAnchor: [0, -24],
		})
		const marker = L.marker([this.lat, this.lng], { icon }).addTo(this.mapInstance)
		if (this.displayName) {
			// displayName comes from Nominatim (untrusted external data). Bind it
			// as a text node, not an HTML string, so Leaflet's popup can't become
			// an HTML-injection sink.
			const popupEl = document.createElement('div')
			popupEl.textContent = this.displayName
			marker.bindPopup(popupEl)
		}
	},
	beforeDestroy() {
		if (this.mapInstance) {
			this.mapInstance.remove()
			this.mapInstance = null
		}
	},
}
</script>

<style scoped>
.project-map {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.project-map__container {
  height: 280px;
  width: 100%;
  border-radius: 8px;
  overflow: hidden;
  background: #f0f1f5;
}
.project-map__link {
  font-size: 12px;
  color: var(--color-primary-element, #4a90d9);
  text-decoration: none;
  align-self: flex-end;
}
.project-map__link:hover {
  text-decoration: underline;
}
</style>

<style>
/* Unscoped: Leaflet builds the marker outside Vue's scoped subtree, so scoped
   class names don't reach it. Strip Leaflet's default marker background. */
.project-map__marker {
  background: transparent !important;
  border: none !important;
}
</style>
