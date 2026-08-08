<template>
	<div class="project-location-map">
		<p class="project-location-map__title">Map preview</p>
		<p v-if="locationHint" class="project-location-map__status">
			{{ locationHint }}
		</p>
		<iframe
			v-else-if="mapEmbedUrl"
			class="project-location-map__frame"
			:title="`Map of ${locationQuery}`"
			:src="mapEmbedUrl"
			loading="lazy" />
		<p v-if="mapEmbedUrl" class="project-location-map__attribution">
			© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors
		</p>
	</div>
</template>

<script>
export default {
	name: 'ProjectLocationMap',
	props: {
		street: {
			type: String,
			default: '',
		},
		city: {
			type: String,
			default: '',
		},
		zip: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			location: null,
			loading: false,
			error: '',
			lookupTimer: null,
			lookupId: 0,
		}
	},
	computed: {
		locationQuery() {
			const street = this.street.trim()
			const city = this.city.trim()
			const zip = this.zip.trim()

			if (!street || (!city && !zip)) {
				return ''
			}

			return [street, [zip, city].filter(Boolean).join(' ')].filter(Boolean).join(', ')
		},
		mapEmbedUrl() {
			if (!this.location) {
				return ''
			}

			const latitude = Number(this.location.lat)
			const longitude = Number(this.location.lon)
			const bbox = [
				longitude - 0.008,
				latitude - 0.004,
				longitude + 0.008,
				latitude + 0.004,
			].join(',')
			const params = new URLSearchParams({
				bbox,
				layer: 'mapnik',
				marker: `${latitude},${longitude}`,
			})

			return `https://www.openstreetmap.org/export/embed.html?${params.toString()}`
		},
		locationHint() {
			if (!this.locationQuery) {
				return 'Enter a street and city or ZIP code to preview the project location.'
			}
			if (this.loading) {
				return 'Finding this location...'
			}
			return this.error
		},
	},
	watch: {
		locationQuery: {
			handler() {
				this.scheduleLookup()
			},
			immediate: true,
		},
	},
	beforeDestroy() {
		clearTimeout(this.lookupTimer)
	},
	methods: {
		scheduleLookup() {
			clearTimeout(this.lookupTimer)
			this.lookupId++
			this.location = null
			this.error = ''
			this.loading = false

			if (!this.locationQuery) {
				return
			}

			this.loading = true
			this.lookupTimer = setTimeout(() => this.lookupLocation(), 1000)
		},
		async lookupLocation() {
			const lookupId = this.lookupId
			try {
				const params = new URLSearchParams({
					q: this.locationQuery,
					format: 'jsonv2',
					limit: '1',
					addressdetails: '0',
				})
				const response = await fetch(`https://nominatim.openstreetmap.org/search?${params.toString()}`)
				if (!response.ok) {
					throw new Error('Location search failed')
				}

				const results = await response.json()
				if (lookupId !== this.lookupId) {
					return
				}

				this.location = results[0] || null
				this.error = this.location ? '' : 'No map location was found for this address.'
			} catch (error) {
				if (lookupId === this.lookupId) {
					this.error = 'The map preview is unavailable.'
				}
			} finally {
				if (lookupId === this.lookupId) {
					this.loading = false
				}
			}
		},
	},
}
</script>

<style scoped>
.project-location-map {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
	background: var(--color-background-dark);
}

.project-location-map__title,
.project-location-map__status,
.project-location-map__attribution {
	margin: 0;
	padding: 8px 12px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.project-location-map__title {
	font-weight: 700;
	color: var(--color-main-text);
	border-bottom: 1px solid var(--color-border);
}

.project-location-map__frame {
	display: block;
	width: 100%;
	height: 220px;
	border: 0;
}

.project-location-map__attribution {
	border-top: 1px solid var(--color-border);
}

.project-location-map__attribution a {
	color: var(--color-primary-element);
}
</style>
