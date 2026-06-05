<template>
	<section class="project-calendar">
		<!-- Summary Cards and Filters -->
		<header class="project-calendar__header">
			<div class="project-calendar__stats">
				<div class="project-calendar__stat-card project-calendar__stat-card--proposals">
					<span class="project-calendar__stat-value">{{ proposalCount }}</span>
					<span class="project-calendar__stat-label">Active Proposals</span>
				</div>
				<div class="project-calendar__stat-card project-calendar__stat-card--confirmed">
					<span class="project-calendar__stat-value">{{ confirmedCount }}</span>
					<span class="project-calendar__stat-label">Confirmed Meetings</span>
				</div>
			</div>

			<div class="project-calendar__filters">
				<button
					type="button"
					class="filter-pill"
					:class="{ 'filter-pill--active': filterType === 'all' }"
					@click="filterType = 'all'">
					All Events ({{ events.length }})
				</button>
				<button
					type="button"
					class="filter-pill"
					:class="{ 'filter-pill--active': filterType === 'proposals' }"
					@click="filterType = 'proposals'">
					Proposals ({{ proposalCount }})
				</button>
				<button
					type="button"
					class="filter-pill"
					:class="{ 'filter-pill--active': filterType === 'meetings' }"
					@click="filterType = 'meetings'">
					Confirmed ({{ confirmedCount }})
				</button>
			</div>
		</header>

		<!-- Main content -->
		<div v-if="loading" class="project-calendar__loading">
			<NcLoadingIcon :size="48" />
			<span>Loading calendar events...</span>
		</div>

		<div v-else-if="error" class="project-calendar__error-banner">
			<AlertCircle :size="24" />
			<span>{{ error }}</span>
			<NcButton type="secondary" @click="loadEvents">
				Retry
			</NcButton>
		</div>

		<div v-else-if="filteredEvents.length === 0" class="project-calendar__empty">
			<NcEmptyContent
				name="No events found"
				description="There are no calendar events or proposals associated with this project.">
				<template #icon>
					<Calendar :size="48" />
				</template>
			</NcEmptyContent>
		</div>

		<div v-else class="project-calendar__content">
			<div class="project-calendar__list">
				<article
					v-for="event in filteredEvents"
					:key="event.id + '-' + event['@type']"
					class="calendar-card"
					:class="event['@type'] === 'MeetingProposal' ? 'calendar-card--proposal' : 'calendar-card--meeting'">
					<!-- Card Header -->
					<div class="calendar-card__header">
						<span
							class="calendar-card__badge"
							:class="event['@type'] === 'MeetingProposal' ? 'calendar-card__badge--proposal' : 'calendar-card__badge--meeting'">
							{{ event['@type'] === 'MeetingProposal' ? 'Voting Proposal' : 'Confirmed Meeting' }}
						</span>
						<div class="calendar-card__meta">
							<span class="meta-item">
								<ClockOutline :size="16" class="meta-item__icon" />
								{{ event.duration }} min
							</span>
							<span v-if="event.location" class="meta-item">
								<MapMarker :size="16" class="meta-item__icon" />
								{{ event.location }}
							</span>
						</div>
					</div>

					<!-- Title & Description -->
					<h3 class="calendar-card__title">
						{{ event.title }}
					</h3>
					<p v-if="event.description" class="calendar-card__description">
						{{ event.description }}
					</p>

					<!-- Event Details Section -->
					<div class="calendar-card__details">
						<!-- Dates / Times -->
						<div class="calendar-card__section calendar-card__section--dates">
							<h4 class="section-title">
								<Calendar :size="16" />
								<span>{{ event['@type'] === 'MeetingProposal' ? 'Proposed Dates' : 'Date & Time' }}</span>
							</h4>

							<!-- Confirmed Meeting Date/Time -->
							<div v-if="event['@type'] === 'Meeting'" class="confirmed-date">
								{{ formatTimeRange(event.startDate, event.endDate) }}
							</div>

							<!-- Meeting Proposal Date Options -->
							<ul v-else class="proposed-dates-list">
								<li
									v-for="(dateOpt, index) in event.dates"
									:key="dateOpt.id"
									class="proposed-date-item">
									<span class="date-option-number">Option {{ index + 1 }}</span>
									<span class="date-option-value">{{ formatDateTime(dateOpt.date) }}</span>
								</li>
							</ul>
						</div>

						<!-- Participants -->
						<div v-if="event.participants && event.participants.length > 0" class="calendar-card__section calendar-card__section--participants">
							<h4 class="section-title">
								<AccountMultiple :size="16" />
								<span>Participants</span>
							</h4>
							<ul class="participants-list">
								<li
									v-for="(participant, pIdx) in event.participants"
									:key="pIdx"
									class="participant-item">
									<div class="participant-info">
										<span class="participant-name">{{ participant.name || participant.address }}</span>
										<span v-if="participant.name && participant.address" class="participant-email">
											({{ participant.address }})
										</span>
									</div>
									<span
										class="participant-status"
										:class="'participant-status--' + (participant.status || 'needs-action')">
										{{ formatStatus(participant.status) }}
									</span>
								</li>
							</ul>
						</div>
					</div>
				</article>
			</div>

			<!-- Pagination / Load More -->
			<div v-if="hasMore" class="project-calendar__load-more">
				<NcButton
					type="secondary"
					:disabled="loadingMore"
					@click="loadMore">
					<template #icon>
						<NcLoadingIcon v-if="loadingMore" :size="16" />
					</template>
					{{ loadingMore ? 'Loading more events...' : 'Load older events' }}
				</NcButton>
			</div>
		</div>
	</section>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

import Calendar from 'vue-material-design-icons/Calendar.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import MapMarker from 'vue-material-design-icons/MapMarker.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'

import { CalendarService } from '../Services/calendar.js'

const calendarService = CalendarService.getInstance()

export default {
	name: 'ProjectCalendar',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		Calendar,
		ClockOutline,
		MapMarker,
		AccountMultiple,
		AlertCircle,
	},
	props: {
		projectId: {
			type: [String, Number],
			required: true,
		},
	},
	data() {
		return {
			loading: false,
			loadingMore: false,
			events: [],
			offset: 0,
			limit: 20,
			hasMore: false,
			error: '',
			filterType: 'all',
		}
	},
	computed: {
		proposalCount() {
			return this.events.filter(e => e['@type'] === 'MeetingProposal').length
		},
		confirmedCount() {
			return this.events.filter(e => e['@type'] === 'Meeting').length
		},
		filteredEvents() {
			if (this.filterType === 'proposals') {
				return this.events.filter(e => e['@type'] === 'MeetingProposal')
			}
			if (this.filterType === 'meetings') {
				return this.events.filter(e => e['@type'] === 'Meeting')
			}
			return this.events
		},
	},
	watch: {
		projectId: {
			immediate: true,
			handler() {
				this.loadEvents()
			},
		},
	},
	methods: {
		async loadEvents() {
			const id = Number(this.projectId)
			if (!Number.isFinite(id) || id <= 0) {
				this.events = []
				return
			}

			this.loading = true
			this.error = ''
			this.offset = 0
			this.hasMore = false

			try {
				const result = await calendarService.getProjectEvents(id, {
					limit: this.limit,
					offset: 0,
				})
				this.events = Array.isArray(result) ? result : []
				this.hasMore = this.events.length === this.limit
			} catch (err) {
				console.error('Failed to load project calendar events:', err)
				this.error = 'Failed to retrieve calendar events. Please try again.'
			} finally {
				this.loading = false
			}
		},
		async loadMore() {
			if (this.loadingMore || !this.hasMore) return

			this.loadingMore = true
			this.error = ''
			const nextOffset = this.offset + this.limit

			try {
				const result = await calendarService.getProjectEvents(Number(this.projectId), {
					limit: this.limit,
					offset: nextOffset,
				})
				if (Array.isArray(result) && result.length > 0) {
					this.events.push(...result)
					this.offset = nextOffset
					this.hasMore = result.length === this.limit
				} else {
					this.hasMore = false
				}
			} catch (err) {
				console.error('Failed to load more calendar events:', err)
				this.error = 'Failed to retrieve more calendar events.'
			} finally {
				this.loadingMore = false
			}
		},
		formatDateTime(isoString) {
			if (!isoString) return ''
			try {
				const date = new Date(isoString)
				return date.toLocaleString(undefined, {
					weekday: 'short',
					year: 'numeric',
					month: 'short',
					day: 'numeric',
					hour: '2-digit',
					minute: '2-digit',
				})
			} catch (e) {
				return isoString
			}
		},
		formatTimeRange(startIso, endIso) {
			if (!startIso) return ''
			try {
				const start = new Date(startIso)
				const startDateStr = start.toLocaleDateString(undefined, {
					weekday: 'short',
					year: 'numeric',
					month: 'short',
					day: 'numeric',
				})
				const startTimeStr = start.toLocaleTimeString(undefined, {
					hour: '2-digit',
					minute: '2-digit',
				})

				if (!endIso) {
					return `${startDateStr} at ${startTimeStr}`
				}

				const end = new Date(endIso)
				const endTimeStr = end.toLocaleTimeString(undefined, {
					hour: '2-digit',
					minute: '2-digit',
				})

				const isSameDay = start.toDateString() === end.toDateString()
				if (isSameDay) {
					return `${startDateStr}, ${startTimeStr} - ${endTimeStr}`
				} else {
					const endDateStr = end.toLocaleDateString(undefined, {
						weekday: 'short',
						year: 'numeric',
						month: 'short',
						day: 'numeric',
					})
					return `${startDateStr}, ${startTimeStr} - ${endDateStr}, ${endTimeStr}`
				}
			} catch (e) {
				return `${startIso} - ${endIso || ''}`
			}
		},
		formatStatus(status) {
			if (!status) return 'Needs Action'
			const map = {
				'needs-action': 'Needs Action',
				accepted: 'Accepted',
				declined: 'Declined',
				tentative: 'Tentative',
			}
			return map[status.toLowerCase()] || status
		},
	},
}
</script>

<style scoped>
.project-calendar {
	display: flex;
	flex-direction: column;
	gap: 20px;
	padding: 8px 4px;
	color: var(--color-main-text);
}

/* Header & Stat Cards */
.project-calendar__header {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.project-calendar__stats {
	display: flex;
	gap: 16px;
	flex-wrap: wrap;
}

.project-calendar__stat-card {
	flex: 1;
	min-width: 180px;
	padding: 16px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	display: flex;
	flex-direction: column;
	gap: 4px;
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}

.project-calendar__stat-card--proposals {
	border-left: 4px solid var(--color-info, #0082c9);
}

.project-calendar__stat-card--confirmed {
	border-left: 4px solid var(--color-success, #46ba61);
}

.project-calendar__stat-value {
	font-size: 24px;
	font-weight: bold;
	color: var(--color-main-text);
}

.project-calendar__stat-label {
	font-size: 13px;
	color: var(--color-text-maxcontrast, #777);
}

/* Filters */
.project-calendar__filters {
	display: flex;
	gap: 8px;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 12px;
}

.filter-pill {
	background: transparent;
	border: 1px solid var(--color-border);
	color: var(--color-main-text);
	border-radius: 100px;
	padding: 6px 14px;
	font-size: 13px;
	cursor: pointer;
	transition: all 0.2s ease;
}

.filter-pill:hover {
	background: var(--color-background-hover);
	border-color: var(--color-border-hover);
}

.filter-pill--active {
	background: var(--color-primary-element, var(--color-primary));
	color: var(--color-primary-text, #fff);
	border-color: var(--color-primary-element, var(--color-primary));
}

.filter-pill--active:hover {
	background: var(--color-primary-element-light, var(--color-primary-hover, var(--color-primary)));
}

/* Loading, Error & Empty states */
.project-calendar__loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 48px 16px;
	gap: 12px;
	color: var(--color-text-maxcontrast, #777);
}

.project-calendar__error-banner {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	padding: 24px;
	background: var(--color-error-background, rgba(224, 78, 78, 0.1));
	border: 1px solid var(--color-error, #e04e4e);
	border-radius: var(--border-radius-large, 8px);
	color: var(--color-error, #e04e4e);
	text-align: center;
}

.project-calendar__empty {
	padding: 32px 16px;
}

/* Cards List */
.project-calendar__list {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.calendar-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: 20px;
	transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
	position: relative;
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}

.calendar-card:hover {
	transform: translateY(-2px);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
	border-color: var(--color-border-hover);
}

.calendar-card--proposal {
	border-left: 4px solid var(--color-info, #0082c9);
}

.calendar-card--meeting {
	border-left: 4px solid var(--color-success, #46ba61);
}

/* Card Header elements */
.calendar-card__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
	flex-wrap: wrap;
	gap: 10px;
}

.calendar-card__badge {
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	padding: 4px 8px;
	border-radius: 4px;
}

.calendar-card__badge--proposal {
	background: var(--color-info-background, rgba(0, 130, 201, 0.1));
	color: var(--color-info, #0082c9);
}

.calendar-card__badge--meeting {
	background: var(--color-success-background, rgba(70, 186, 97, 0.1));
	color: var(--color-success, #46ba61);
}

.calendar-card__meta {
	display: flex;
	gap: 16px;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #777);
}

.meta-item {
	display: flex;
	align-items: center;
	gap: 6px;
}

.meta-item__icon {
	color: var(--color-text-maxcontrast, #777);
}

/* Card Content elements */
.calendar-card__title {
	font-size: 18px;
	font-weight: 600;
	margin: 0 0 8px 0;
	color: var(--color-main-text);
}

.calendar-card__description {
	font-size: 14px;
	color: var(--color-text-maxcontrast, #555);
	margin: 0 0 16px 0;
	line-height: 1.45;
}

/* Details Section Layout */
.calendar-card__details {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
	gap: 16px;
	border-top: 1px dashed var(--color-border);
	padding-top: 16px;
}

.calendar-card__section {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.section-title {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 13px;
	font-weight: bold;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast, #777);
	margin: 0;
	letter-spacing: 0.5px;
}

/* Confirmed Date styling */
.confirmed-date {
	font-size: 14px;
	font-weight: 500;
	padding: 10px 12px;
	background: var(--color-success-background, rgba(70, 186, 97, 0.05));
	border: 1px solid var(--color-success-border, rgba(70, 186, 97, 0.2));
	border-radius: 6px;
	color: var(--color-success, #3ca053);
	display: inline-block;
}

/* Proposed dates list */
.proposed-dates-list {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.proposed-date-item {
	display: flex;
	align-items: center;
	gap: 10px;
	font-size: 13px;
	padding: 6px 10px;
	background: var(--color-background-hover);
	border-radius: 6px;
}

.date-option-number {
	font-size: 10px;
	font-weight: bold;
	text-transform: uppercase;
	padding: 2px 6px;
	background: var(--color-border);
	border-radius: 4px;
	color: var(--color-text-maxcontrast);
}

.date-option-value {
	color: var(--color-main-text);
}

/* Participants List */
.participants-list {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.participant-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	font-size: 13px;
	padding: 4px 0;
	border-bottom: 1px solid var(--color-background-hover);
}

.participant-item:last-child {
	border-bottom: none;
}

.participant-info {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	min-width: 0;
}

.participant-name {
	font-weight: 500;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.participant-email {
	color: var(--color-text-maxcontrast, #777);
	font-size: 12px;
}

.participant-status {
	font-size: 11px;
	padding: 2px 6px;
	border-radius: 4px;
	font-weight: 500;
	white-space: nowrap;
}

.participant-status--needs-action {
	background: var(--color-border);
	color: var(--color-text-maxcontrast, #777);
}

.participant-status--accepted {
	background: var(--color-success-background, rgba(70, 186, 97, 0.15));
	color: var(--color-success, #46ba61);
}

.participant-status--declined {
	background: var(--color-error-background, rgba(224, 78, 78, 0.15));
	color: var(--color-error, #e04e4e);
}

.participant-status--tentative {
	background: var(--color-warning-background, rgba(242, 179, 63, 0.15));
	color: var(--color-warning, #f2b33f);
}

/* Load More */
.project-calendar__load-more {
	display: flex;
	justify-content: center;
	margin-top: 12px;
	padding: 16px 0;
}
</style>
