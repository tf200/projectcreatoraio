<template>
	<div class="pc-location-menu">
		<p class="pc-location-menu__label">
			{{ t('Location') }}
		</p>
		<div class="pc-location-menu__address">
			<strong v-if="street.trim()">{{ street.trim() }}</strong>
			<span v-if="placeLine">{{ placeLine }}</span>
		</div>
		<div class="pc-location-menu__map">
			<p v-if="!street.trim()" class="pc-location-menu__status">
				{{ t('Add a street to see this project on the map.') }}
			</p>
			<p v-else-if="locationHint" class="pc-location-menu__status" role="status">
				{{ locationHint }}
			</p>
			<iframe v-else-if="mapEmbedUrl"
				class="pc-location-menu__frame"
				:title="t('Map of {address}', { address: locationQuery })"
				:src="mapEmbedUrl"
				loading="lazy" />
		</div>
		<div class="pc-location-menu__sep" />
		<a class="pc-location-menu__item"
			:href="searchUrl"
			target="_blank"
			rel="noopener noreferrer">
			<MapMarkerOutline :size="16" />{{ t('Open in OpenStreetMap') }}<OpenInNew :size="14" class="pc-location-menu__out" />
		</a>
		<button type="button" class="pc-location-menu__item" @click="$emit('edit')">
			<PencilOutline :size="16" />{{ t('Edit client & address') }}
		</button>
	</div>
</template>

<script>
// The header's location menu in the new layout: the address, the map the
// old interface showed on its overview, and links out. It extends
// ProjectLocationMap for the address lookup and the OpenStreetMap embed; it is
// only mounted while the menu is open, so the address is looked up only then.
// The embed carries OpenStreetMap's own credit line.
import { t } from './l10n.mjs'
import MapMarkerOutline from 'vue-material-design-icons/MapMarkerOutline.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import PencilOutline from 'vue-material-design-icons/PencilOutline.vue'
import ProjectLocationMap from '../components/ProjectLocationMap.vue'

export default {
	name: 'LocationMenu',
	components: { MapMarkerOutline, OpenInNew, PencilOutline },
	extends: ProjectLocationMap,
	props: {
		searchUrl: { type: String, required: true },
	},
	emits: ['edit'],
	computed: {
		placeLine() { return [this.zip.trim(), this.city.trim()].filter(Boolean).join(' ') },
	},
	methods: { t },
}
</script>

<style scoped>
.pc-location-menu { display: flex; flex-direction: column; }
.pc-location-menu__label { margin: 0; padding: 8px 10px 4px; font-size: var(--iz-fs-micro); font-weight: 700; color: var(--iz-text-secondary); text-transform: uppercase; letter-spacing: 0.5px; }
.pc-location-menu__address { display: flex; flex-direction: column; gap: 2px; padding: 4px 10px 8px; font-size: var(--iz-fs-sm); color: var(--iz-text-secondary); overflow-wrap: anywhere; }
.pc-location-menu__address strong { font-size: var(--iz-fs-md); font-weight: 600; color: var(--iz-text); }
.pc-location-menu__map { padding: 4px 10px 6px; }
.pc-location-menu__frame { display: block; width: 100%; height: 210px; border: 1px solid var(--iz-border); border-radius: var(--iz-radius); background: var(--iz-surface-subtle); }
.pc-location-menu__status { margin: 0; padding: 14px 12px; border: 1px dashed var(--iz-border-strong); border-radius: var(--iz-radius); font-size: var(--iz-fs-sm); color: var(--iz-text-secondary); text-align: center; }
.pc-location-menu__sep { height: 1px; margin: 6px 4px; background: var(--iz-border); }
.pc-location-menu__item { display: flex; align-items: center; gap: 10px; width: 100%; min-height: 38px; margin: 0; padding: 7px 10px; border: 0; border-radius: var(--iz-radius); background: transparent; color: var(--iz-text); font: inherit; font-size: var(--iz-fs-md); text-align: start; text-decoration: none; cursor: pointer; }
.pc-location-menu__item:hover, .pc-location-menu__item:focus-visible { background: var(--iz-surface-subtle); outline: none; text-decoration: none; }
.pc-location-menu__item:focus-visible { box-shadow: inset 0 0 0 2px var(--iz-accent); }
.pc-location-menu__item:focus:not(:focus-visible):not(:hover) { background: transparent; }
.pc-location-menu__item > .material-design-icon { color: var(--iz-text-secondary); }
.pc-location-menu__out { margin-inline-start: auto; }
</style>
