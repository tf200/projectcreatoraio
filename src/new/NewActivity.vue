<template>
	<section class="pc-view iz-app" aria-label="Project activity">
		<header class="pc-view__head">
			<div class="pc-view__heading">
				<h2 class="pc-view__title">
					Activity
				</h2>
				<p class="pc-view__lede">
					Everything that happened in this project, newest first.
				</p>
			</div>
		</header>

		<div class="iz-panel iz-panel--list pc-activity-panel">
			<div class="pc-activity-filters" role="group" aria-label="Filter by source">
				<button v-for="option in sourceOptions"
					:key="'source-' + option.value"
					type="button"
					class="iz-chip"
					:class="{ 'iz-chip--active': selectedSource === option.value }"
					:aria-pressed="String(selectedSource === option.value)"
					@click="selectSource(option.value)">
					<span v-if="option.value" class="iz-dot" :class="'pc-source-dot--' + option.value" />
					{{ option.label }}
				</button>
			</div>

			<div v-if="loading && !events.length" class="pc-activity-state" role="status">
				Loading activity…
			</div>
			<div v-else-if="error && !events.length" class="pc-activity-state" role="alert">
				<span>{{ error }}</span>
				<button type="button" class="iz-btn iz-btn--sm" @click="fetchEvents">
					Try again
				</button>
			</div>
			<div v-else-if="!events.length" class="pc-activity-state">
				{{ selectedSource ? 'No activity from this source yet.' : 'No activity yet.' }}
			</div>
			<template v-else>
				<section v-for="group in groupedEvents" :key="group.label" class="pc-activity-day">
					<h3 class="iz-section-title pc-activity-day__title">
						{{ group.label }}
					</h3>
					<ol class="pc-activity-list">
						<li v-for="event in group.events" :key="event.id" class="pc-activity-row">
							<time class="pc-activity-row__time" :datetime="event.occurredAt" :title="formatTime(event.occurredAt)">{{ clockTime(event.occurredAt) }}</time>
							<NcAvatar :user="event.actorUid"
								:display-name="event.actorDisplayName"
								:size="32"
								:show-user-status="false"
								class="pc-activity-row__avatar" />
							<!-- The explicit space survives the template's whitespace condensing. -->
							<p class="pc-activity-row__text">
								<strong>{{ event.actorDisplayName || 'Unknown user' }}</strong>{{ ' ' }}<span>{{ formatDescription(event) }}</span>
							</p>
							<span class="iz-pill pc-source" :class="'pc-source--' + (event.source || 'internal')">{{ formatSource(event.source) }}</span>
						</li>
					</ol>
				</section>
			</template>

			<div v-if="events.length" class="iz-pagination pc-activity-footer">
				<span>Showing {{ events.length }} {{ events.length === 1 ? 'event' : 'events' }}</span>
				<span v-if="error" class="pc-activity-footer__error" role="alert">{{ error }}</span>
				<button v-if="hasMore"
					type="button"
					class="iz-btn"
					:disabled="loading"
					@click="loadMore">
					{{ loading ? 'Loading…' : 'Load more' }}
				</button>
			</div>
		</div>
	</section>
</template>

<script>
import ProjectActivity from '../components/ProjectActivity/ProjectActivity.vue'
import { api, errorMessage } from './api.js'

// Grouping, descriptions (including redacted private notes and native Deck
// events) and source labels come from the component the current interface
// uses. Only the reads are replaced: its service turns every failure into an
// empty list, and here "no access" has to read as a failure.
export default {
	name: 'NewActivity',
	extends: ProjectActivity,
	data() {
		return { error: '', request: 0 }
	},
	computed: {
		sourceOptions() {
			return [
				{ value: '', label: 'All sources' },
				{ value: 'internal', label: 'Project' },
				{ value: 'deck', label: 'Deck' },
				{ value: 'files', label: 'Files' },
				{ value: 'talk', label: 'Talk' },
				{ value: 'whiteboard', label: 'Whiteboard' },
			]
		},
	},
	methods: {
		params(extra = {}) {
			return { limit: this.limit, ...(this.selectedSource ? { source: this.selectedSource } : {}), ...extra }
		},
		async fetchEvents() {
			if (!this.normalizedProjectId) {
				this.events = []
				return
			}
			const request = ++this.request
			this.loading = true
			this.error = ''
			try {
				const data = await api.activity(this.normalizedProjectId, this.params())
				if (request !== this.request) return
				if (!Array.isArray(data?.events)) throw new Error('Invalid activity response')
				this.events = data.events
				this.hasMore = !!data.hasMore
				this.nextCursor = data.nextCursor || null
				this.offset = data.events.length
			} catch (e) {
				if (request !== this.request) return
				this.events = []
				this.hasMore = false
				this.error = errorMessage(e)
			} finally {
				if (request === this.request) this.loading = false
			}
		},
		async loadMore() {
			if (this.loading || !this.hasMore) return
			const request = this.request
			this.loading = true
			this.error = ''
			try {
				const data = await api.activity(this.normalizedProjectId, this.params(this.nextCursor ? { cursor: this.nextCursor } : { offset: this.offset }))
				if (request !== this.request) return
				if (!Array.isArray(data?.events)) throw new Error('Invalid activity response')
				this.events = [...this.events, ...data.events]
				this.hasMore = !!data.hasMore
				this.nextCursor = data.nextCursor || null
				this.offset += data.events.length
			} catch (e) {
				if (request === this.request) this.error = 'More activity could not be loaded. Try again.'
			} finally {
				if (request === this.request) this.loading = false
			}
		},
		selectSource(value) {
			if (this.selectedSource === value) return
			this.selectedSource = value
			this.fetchEvents()
		},
		clockTime(value) {
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? '' : date.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
		},
	},
}
</script>

<style scoped>
/* clip, not hidden: a hidden overflow would stop the day headers sticking. */
.pc-activity-panel { overflow: clip; }
.pc-activity-filters { display: flex; flex-wrap: wrap; gap: 6px; padding: var(--iz-pad-card) var(--iz-pad-panel); border-bottom: 1px solid var(--iz-border); }
.pc-activity-state { display: flex; flex-direction: column; align-items: center; gap: var(--iz-gap-tight); padding: 40px var(--iz-pad-panel); font-size: var(--iz-fs-md); color: var(--iz-text-secondary); text-align: center; }
.pc-activity-day__title { position: sticky; top: 0; z-index: 1; margin: 0; padding: var(--iz-pad-cell); padding-inline: var(--iz-pad-panel); background: var(--iz-surface-subtle); border-bottom: 1px solid var(--iz-border); }
.pc-activity-list { margin: 0; padding: 0; list-style: none; }
.pc-activity-row { display: grid; grid-template-columns: 44px 32px minmax(0, 1fr) auto; align-items: center; gap: 12px; padding: var(--iz-pad-row); padding-inline: var(--iz-pad-panel); border-bottom: 1px solid var(--iz-border); }
.pc-activity-row__time { font-size: var(--iz-fs-xs); color: var(--iz-text-secondary); font-variant-numeric: tabular-nums; }
.pc-activity-row__text { margin: 0; min-width: 0; font-size: var(--iz-fs-md); overflow-wrap: anywhere; }
.pc-activity-row__text strong { font-weight: 600; }
.pc-activity-row__text span { color: var(--iz-text-secondary); }
.pc-activity-footer { padding: var(--iz-pad-row); padding-inline: var(--iz-pad-panel); }
.pc-activity-footer__error { color: var(--iz-danger-text); }

/* Sources take the theme's category colours, which differ in lightness too. */
.pc-source--internal { background: var(--iz-cat-2-bg); color: var(--iz-cat-2-text); }
.pc-source--deck { background: var(--iz-cat-1-bg); color: var(--iz-cat-1-text); }
.pc-source--files { background: var(--iz-cat-3-bg); color: var(--iz-cat-3-text); }
.pc-source--talk { background: var(--iz-cat-4-bg); color: var(--iz-cat-4-text); }
.pc-source--whiteboard { background: var(--iz-cat-5-bg); color: var(--iz-cat-5-text); }
.pc-source-dot--internal { color: var(--iz-cat-2); }
.pc-source-dot--deck { color: var(--iz-cat-1); }
.pc-source-dot--files { color: var(--iz-cat-3); }
.pc-source-dot--talk { color: var(--iz-cat-4); }
.pc-source-dot--whiteboard { color: var(--iz-cat-5); }
.pc-source { text-transform: none; }

@media (max-width: 650px) {
	.pc-activity-row { grid-template-columns: 32px minmax(0, 1fr); padding-inline: var(--iz-pad-card); }
	.pc-activity-row__time { grid-column: 2; grid-row: 2; }
	.pc-activity-row__avatar { grid-row: 1 / span 3; align-self: start; }
	.pc-activity-row .pc-source { grid-column: 2; justify-self: start; }
	.pc-activity-filters, .pc-activity-day__title, .pc-activity-footer { padding-inline: var(--iz-pad-card); }
}
</style>
