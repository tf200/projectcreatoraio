<template>
	<section class="pc-wb-activity iz-app" aria-labelledby="pc-wb-activity-title">
		<div class="pc-wb-activity__head">
			<h3 id="pc-wb-activity-title" class="pc-wb-activity__title">
				Activity
			</h3>
			<span class="pc-wb-activity__hint">Saves less than 20 minutes apart are one session</span>
			<div v-if="days.length" class="pc-wb-activity__tools">
				<button type="button" class="iz-btn iz-btn--ghost iz-btn--sm" @click="expandAll">
					Expand all
				</button>
				<button type="button" class="iz-btn iz-btn--ghost iz-btn--sm" @click="collapseAll">
					Collapse all
				</button>
			</div>
		</div>

		<div v-if="loading && !events.length" class="iz-empty" role="status">
			Loading activity…
		</div>
		<div v-else-if="error && !events.length" class="iz-empty pc-view__failure" role="alert">
			<span>{{ error }}</span>
			<button type="button" class="iz-btn" @click="load">
				Try again
			</button>
		</div>
		<div v-else-if="!days.length" class="iz-empty">
			No one has edited the whiteboard yet.
		</div>

		<ul v-else class="pc-wb-days">
			<li v-for="day in days" :key="day.key" class="pc-wb-day">
				<h4 class="pc-wb-day__heading">
					<button type="button"
						class="pc-wb-day__toggle"
						:aria-expanded="String(isDayOpen(day))"
						:aria-controls="'pc-wb-day-' + day.key"
						@click="toggleDay(day)">
						<ChevronRight :size="18" class="pc-wb-chevron" :class="{ 'pc-wb-chevron--open': isDayOpen(day) }" />
						<span class="pc-wb-day__label">{{ day.label }}</span>
						<span class="pc-wb-day__summary">{{ daySummary(day) }}</span>
						<span class="pc-wb-faces" aria-hidden="true">
							<NcAvatar v-for="person in day.people"
								:key="person.actorUid"
								class="pc-wb-face"
								:user="person.actorUid"
								:display-name="person.displayName"
								:size="22"
								:show-user-status="false"
								:disable-menu="true"
								:disable-tooltip="true" />
						</span>
					</button>
				</h4>
				<ul v-if="isDayOpen(day)" :id="'pc-wb-day-' + day.key" class="pc-wb-sessions">
					<li v-for="session in day.sessions"
						:key="session.id"
						class="pc-wb-session"
						:class="{ 'pc-wb-session--open': isSessionOpen(session) }">
						<button type="button"
							class="pc-wb-session__toggle"
							:aria-expanded="String(isSessionOpen(session))"
							:aria-controls="'pc-wb-session-' + session.id"
							@click="toggleSession(session)">
							<ChevronRight :size="16" class="pc-wb-chevron" :class="{ 'pc-wb-chevron--open': isSessionOpen(session) }" />
							<span class="pc-wb-faces pc-wb-faces--session" aria-hidden="true">
								<NcAvatar v-for="lane in session.lanes"
									:key="lane.actorUid"
									class="pc-wb-face"
									:user="lane.actorUid"
									:display-name="lane.displayName"
									:size="28"
									:show-user-status="false"
									:disable-menu="true"
									:disable-tooltip="true" />
							</span>
							<span class="pc-wb-session__text">
								<strong>{{ names(session.lanes) }}</strong>{{ ' ' }}<span>{{ session.lanes.length > 1 ? 'edited together' : 'edited the board' }}</span>
							</span>
							<span class="pc-wb-session__range">{{ range(session) }}</span>
							<span class="iz-pill pc-wb-session__duration">{{ duration(minutesOf(session)) }}</span>
						</button>
						<div v-if="isSessionOpen(session)" :id="'pc-wb-session-' + session.id" class="pc-wb-session__detail">
							<ul class="pc-wb-lanes">
								<li v-for="lane in session.lanes" :key="lane.actorUid" class="pc-wb-lane">
									<NcAvatar :user="lane.actorUid"
										:display-name="lane.displayName"
										:size="20"
										:show-user-status="false"
										:disable-menu="true"
										:disable-tooltip="true" />
									<span class="pc-wb-lane__name">{{ lane.displayName }}</span>
									<span class="pc-wb-lane__range">{{ range(lane) }}</span>
									<span class="pc-wb-lane__saves">{{ lane.saves === 1 ? '1 save' : lane.saves + ' saves' }}</span>
								</li>
							</ul>
							<p class="pc-wb-session__facts">
								<span>{{ session.saves === 1 ? '1 save' : session.saves + ' saves' }}</span>
								<span v-if="sizeChange(session.sizeChange)">{{ sizeChange(session.sizeChange) }}</span>
							</p>
						</div>
					</li>
				</ul>
			</li>
		</ul>

		<div v-if="events.length && (hasMore || error)" class="pc-wb-activity__more">
			<span v-if="error" class="pc-wb-activity__error" role="alert">{{ error }}</span>
			<button v-if="hasMore"
				type="button"
				class="iz-btn iz-btn--sm"
				:disabled="loading"
				@click="loadOlder">
				{{ loading ? 'Loading…' : 'Older activity' }}
			</button>
		</div>
	</section>
</template>

<script>
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import { api, errorMessage } from './api.js'
import { sessionsOf, daysOf, minutesOf, duration, range, names, sizeChange } from './whiteboard-activity.js'

// The endpoint pages by save (at most 100 per read). One sitting can be
// dozens of saves, so the view reads this many at a time.
const PAGE = 100
const BATCH = 300

// Whiteboard activity for the modern interface: autosaves grouped into
// editing sessions, days and sessions both collapsible. Mounted through
// WhiteboardBoard's activityComponent prop; the current interface keeps
// WhiteboardActivity.
export default {
	name: 'NewWhiteboardActivity',
	components: { NcAvatar, ChevronRight },
	props: {
		projectId: { type: [String, Number], required: true },
		reader: { type: Function, default: null },
	},
	data() {
		return {
			events: [],
			hasMore: false,
			loading: false,
			error: '',
			request: 0,
			openDays: {},
			openSessions: {},
			touched: false,
		}
	},
	computed: {
		sessions() {
			const all = sessionsOf(this.events)
			// While older saves exist, the oldest session may reach further
			// back than what is loaded: hold it until the next read.
			return this.hasMore && all.length > 1 ? all.slice(0, -1) : all
		},
		days() { return daysOf(this.sessions) },
	},
	watch: {
		projectId: { immediate: true, handler() { this.load() } },
	},
	beforeDestroy() {
		this.request++
	},
	methods: {
		minutesOf,
		duration,
		range,
		names,
		sizeChange,
		read(offset) {
			return (this.reader || api.whiteboardActivity)(this.projectId, PAGE, offset)
		},
		// Reads pages until another BATCH of saves is loaded or there are no more.
		async readBatch(request) {
			const target = this.events.length + BATCH
			let hasMore = true
			while (hasMore && this.events.length < target) {
				const result = await this.read(this.events.length)
				if (request !== this.request) return
				if (!Array.isArray(result?.events)) throw new Error('Invalid activity response')
				this.events = [...this.events, ...result.events]
				hasMore = !!result.hasMore && result.events.length > 0
			}
			this.hasMore = hasMore
		},
		async load() {
			const request = ++this.request
			this.events = []
			this.hasMore = false
			this.error = ''
			this.openDays = {}
			this.openSessions = {}
			this.touched = false
			this.loading = true
			try {
				await this.readBatch(request)
			} catch (e) {
				if (request !== this.request) return
				this.events = []
				this.error = errorMessage(e)
			} finally {
				if (request === this.request) this.loading = false
			}
		},
		async loadOlder() {
			if (this.loading || !this.hasMore) return
			const request = this.request
			this.loading = true
			this.error = ''
			try {
				await this.readBatch(request)
			} catch (e) {
				if (request !== this.request) return
				this.error = 'Older activity could not be loaded. Please try again.'
			} finally {
				if (request === this.request) this.loading = false
			}
		},
		// Until someone opens or closes a day, the newest day, today and
		// yesterday are open and older days are closed.
		isDayOpen(day) {
			if (day.key in this.openDays) return this.openDays[day.key]
			return !this.touched && (day === this.days[0] || day.label === 'Today' || day.label === 'Yesterday')
		},
		toggleDay(day) {
			this.$set(this.openDays, day.key, !this.isDayOpen(day))
		},
		isSessionOpen(session) {
			return !!this.openSessions[session.id]
		},
		toggleSession(session) {
			this.$set(this.openSessions, session.id, !this.isSessionOpen(session))
		},
		expandAll() {
			this.touched = true
			this.openDays = Object.fromEntries(this.days.map(day => [day.key, true]))
			this.openSessions = Object.fromEntries(this.sessions.map(session => [session.id, true]))
		},
		collapseAll() {
			this.touched = true
			this.openDays = Object.fromEntries(this.days.map(day => [day.key, false]))
			this.openSessions = {}
		},
		daySummary(day) {
			const count = day.sessions.length
			return (count === 1 ? '1 session' : count + ' sessions') + ' · ' + duration(day.minutes)
		},
	},
}
</script>

<style scoped>
.pc-wb-activity { display: flex; flex-direction: column; gap: 8px; margin-top: var(--iz-gap); }
.pc-wb-activity__head { display: flex; align-items: center; gap: var(--iz-gap-tight); flex-wrap: wrap; }
.pc-wb-activity__title { margin: 0; font-family: 'Space Grotesk', system-ui, -apple-system, sans-serif; font-size: var(--iz-fs-lg); font-weight: 600; color: var(--iz-text); }
.pc-wb-activity__hint { font-size: var(--iz-fs-xs); color: var(--iz-text-secondary); }
.pc-wb-activity__tools { display: flex; gap: 6px; margin-inline-start: auto; }

.pc-wb-days { margin: 0; padding: 0; list-style: none; max-height: 420px; overflow-y: auto; overscroll-behavior: contain; border-top: 1px solid var(--iz-border); }
.pc-wb-day { border-bottom: 1px solid var(--iz-border); }
.pc-wb-day__heading { margin: 0; font: inherit; }
.pc-wb-day__toggle { display: flex; align-items: center; gap: var(--iz-gap-tight); width: 100%; min-height: 44px; margin: 0; padding: 6px 4px; border: 0; border-radius: var(--iz-radius-sm); background: transparent; color: var(--iz-text); font: inherit; text-align: start; cursor: pointer; }
.pc-wb-day__toggle:hover { background: var(--iz-surface-subtle); }
.pc-wb-day__label { font-size: var(--iz-fs-sm); font-weight: 700; }
.pc-wb-day__summary { font-size: var(--iz-fs-xs); color: var(--iz-text-secondary); }

.pc-wb-chevron { display: inline-flex; color: var(--iz-text-secondary); transition: transform var(--iz-transition, 150ms); }
.pc-wb-chevron--open { transform: rotate(90deg); }

.pc-wb-faces { display: flex; margin-inline-start: auto; padding-inline-start: 5px; }
.pc-wb-faces--session { margin-inline-start: 0; }
.pc-wb-face { margin-inline-start: -5px; border-radius: 50%; box-shadow: 0 0 0 2px var(--iz-surface); }

.pc-wb-sessions { margin: 0; padding: 0 0 8px 24px; list-style: none; display: flex; flex-direction: column; gap: 2px; }
.pc-wb-session { border-radius: var(--iz-radius); }
.pc-wb-session--open { box-shadow: inset 0 0 0 1px var(--iz-border-strong); }
.pc-wb-session__toggle { display: grid; grid-template-columns: 16px 52px minmax(0, 1fr) auto auto; align-items: center; gap: var(--iz-gap-tight); width: 100%; min-height: 44px; margin: 0; padding: 6px 10px; border: 0; border-radius: var(--iz-radius); background: transparent; color: var(--iz-text); font: inherit; text-align: start; cursor: pointer; }
.pc-wb-session__toggle:hover { background: var(--iz-surface-subtle); }
.pc-wb-session__text { min-width: 0; font-size: var(--iz-fs-md); overflow-wrap: anywhere; }
.pc-wb-session__text strong { font-weight: 600; }
.pc-wb-session__text span { color: var(--iz-text-secondary); }
.pc-wb-session__range { font-size: var(--iz-fs-sm); font-variant-numeric: tabular-nums; white-space: nowrap; }
.pc-wb-session__duration { justify-self: end; text-transform: none; white-space: nowrap; }
.pc-wb-day__toggle:focus-visible, .pc-wb-session__toggle:focus-visible { outline: 2px solid var(--iz-accent); outline-offset: -2px; }

.pc-wb-session__detail { display: flex; flex-direction: column; gap: 8px; padding: 2px 12px 12px 88px; }
.pc-wb-lanes { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 6px; }
.pc-wb-lane { display: grid; grid-template-columns: 20px minmax(0, 180px) 120px auto; justify-content: start; align-items: center; gap: var(--iz-gap-tight); font-size: var(--iz-fs-sm); }
.pc-wb-lane__name { font-weight: 600; overflow-wrap: anywhere; }
.pc-wb-lane__range { font-variant-numeric: tabular-nums; color: var(--iz-text); }
.pc-wb-lane__saves { color: var(--iz-text-secondary); }
.pc-wb-session__facts { display: flex; flex-wrap: wrap; gap: 4px 16px; margin: 0; font-size: var(--iz-fs-xs); color: var(--iz-text-secondary); }

.pc-wb-activity__more { display: flex; align-items: center; justify-content: flex-end; gap: var(--iz-gap-tight); }
.pc-wb-activity__error { font-size: var(--iz-fs-sm); color: var(--iz-danger-text); }
.iz-btn:disabled { cursor: default; opacity: 0.5; }

@media (max-width: 650px) {
	.pc-wb-activity__hint { display: none; }
	.pc-wb-sessions { padding-inline-start: 0; }
	.pc-wb-session__toggle { grid-template-columns: 16px auto minmax(0, 1fr); row-gap: 2px; }
	.pc-wb-session__range { grid-column: 3; }
	.pc-wb-session__duration { grid-column: 3; justify-self: start; }
	.pc-wb-session__detail { padding-inline-start: 36px; }
	.pc-wb-lane { grid-template-columns: 20px minmax(0, 1fr); }
	.pc-wb-lane__range, .pc-wb-lane__saves { grid-column: 2; }
	.pc-wb-days { max-height: 360px; }
}
</style>
