<template>
	<section class="pc-view iz-app pc-calendar" aria-label="Calendar">
		<header class="pc-view__head">
			<div class="pc-view__heading">
				<h2 class="pc-view__title">
					Calendar
				</h2>
				<p class="pc-view__lede">
					Meetings for this project, and date proposals still waiting for answers.
				</p>
			</div>
		</header>

		<div v-if="loading" class="iz-empty" role="status">
			Loading calendar…
		</div>
		<div v-else-if="error && !events.length" class="iz-empty pc-view__failure" role="alert">
			<span>{{ error }}</span>
			<button type="button" class="iz-btn" @click="loadEvents">
				Try again
			</button>
		</div>
		<div v-else-if="!events.length" class="iz-panel pc-calendar__empty">
			<span class="pc-calendar__empty-icon" aria-hidden="true"><CalendarBlank :size="24" /></span>
			<p class="pc-calendar__empty-title">
				Nothing planned yet
			</p>
			<p class="pc-calendar__empty-text">
				Meetings linked to this project show up here once they are in your calendar, and so do the date proposals you send for it.
			</p>
		</div>

		<template v-else>
			<div class="iz-tabs" role="tablist" aria-label="Show">
				<button v-for="tab in tabs"
					:key="tab.key"
					type="button"
					role="tab"
					class="iz-tab"
					:class="{ 'iz-tab--active': filterType === tab.key }"
					:aria-selected="String(filterType === tab.key)"
					aria-controls="pc-calendar-list"
					@click="filterType = tab.key">
					{{ tab.label }}<span class="iz-tab__count">{{ tab.count }}</span>
				</button>
			</div>

			<section id="pc-calendar-list" class="iz-panel iz-panel--list pc-calendar__list" aria-label="Agenda">
				<p v-if="!groups.length" class="pc-calendar__none">
					{{ filterType === 'proposals' ? 'No date proposals for this project.' : 'No meetings for this project.' }}
				</p>
				<div v-for="group in groups" :key="group.key" class="pc-calendar__group">
					<h3 class="pc-calendar__group-title">
						{{ group.label }} · {{ group.items.length }}
					</h3>
					<ul class="pc-calendar__items">
						<li v-for="event in group.items"
							:key="keyOf(event)"
							class="pc-calendar__item"
							:class="{ 'pc-calendar__item--past': group.key === 'past', 'pc-calendar__item--open': isOpen(event) }">
							<button type="button"
								class="pc-calendar__row"
								:aria-expanded="String(isOpen(event))"
								:aria-controls="'pc-calendar-' + keyOf(event)"
								@click="toggle(event)">
								<span v-if="isProposal(event)" class="pc-calendar__block pc-calendar__block--proposal" aria-hidden="true">
									<span class="pc-calendar__block-big">{{ (event.dates || []).length }}</span>
									<span>{{ (event.dates || []).length === 1 ? 'option' : 'options' }}</span>
								</span>
								<span v-else class="pc-calendar__block" aria-hidden="true">
									<span class="pc-calendar__block-month">{{ dateBlock(event.startDate).month }}</span>
									<span class="pc-calendar__block-big">{{ dateBlock(event.startDate).day }}</span>
									<span>{{ dateBlock(event.startDate).weekday }}</span>
								</span>
								<span class="pc-calendar__text">
									<span class="pc-calendar__title-line">
										<span class="pc-calendar__title">{{ event.title || 'Untitled' }}</span>
										<span v-if="isProposal(event) && allPassed(event, now)" class="iz-pill pc-calendar__pill pc-calendar__pill--warning">
											{{ (event.dates || []).length === 1 ? 'The date has passed' : 'All ' + event.dates.length + ' dates have passed' }}
										</span>
									</span>
									<span class="pc-calendar__meta">
										<span v-if="isMeeting(event)" class="pc-sr-only">{{ longDay(event.startDate, now) }}, </span>{{ metaOf(event) }}
									</span>
								</span>
								<span class="pc-calendar__status">
									<template v-if="isProposal(event)">
										<span class="pc-calendar__status-text" :class="'pc-calendar__status-text--' + proposalStatus(event, now).tone">{{ proposalStatus(event, now).text }}</span>
										<span v-if="proposalStatus(event, now).bar"
											class="iz-meter iz-meter--thin pc-calendar__meter"
											role="img"
											:aria-label="answeredOf(event).answered + ' of ' + answeredOf(event).total + ' answered'"
											:title="answeredOf(event).answered + ' of ' + answeredOf(event).total + ' answered'">
											<span class="iz-meter__fill" :style="{ width: answeredWidth(event) }" />
										</span>
									</template>
									<span v-else class="pc-calendar__status-text pc-calendar__status-text--muted">{{ attendeeSummary(event) }}</span>
								</span>
								<ChevronRight :size="18" class="pc-calendar__chevron" />
							</button>

							<div v-if="isOpen(event)" :id="'pc-calendar-' + keyOf(event)" class="pc-calendar__detail">
								<p v-if="event.description" class="pc-calendar__description">
									{{ event.description }}
								</p>
								<div class="pc-calendar__columns">
									<div class="pc-calendar__column">
										<h4 class="pc-calendar__label">
											{{ isProposal(event) ? 'Date options' : 'When' }}
										</h4>
										<ol v-if="isProposal(event)" class="pc-calendar__options" :style="{ '--pc-rows': Math.ceil(optionsOf(event, now).length / 2) }">
											<li v-for="(option, index) in optionsOf(event, now)"
												:key="option.id"
												:class="{ 'pc-calendar__option--passed': option.passed }">
												<span class="pc-calendar__option-number">{{ index + 1 }}</span>
												<span>{{ option.label }}</span>
												<span v-if="option.passed" class="pc-sr-only">(passed)</span>
											</li>
										</ol>
										<p v-else class="pc-calendar__when">
											{{ longDay(event.startDate, now) }} · {{ timeRange(event) }}<span v-if="event.duration"> ({{ event.duration }} min)</span>
										</p>
									</div>
									<div class="pc-calendar__column">
										<h4 class="pc-calendar__label">
											Participants
										</h4>
										<p v-if="!(event.participants || []).length" class="pc-calendar__muted">
											No participants listed.
										</p>
										<ul v-else class="pc-calendar__people">
											<li v-for="person in peopleOf(event)" :key="person.key" class="pc-calendar__person">
												<span class="pc-calendar__person-name">{{ person.name }}<span v-if="person.address" class="pc-calendar__muted"> {{ person.address }}</span></span>
												<span class="iz-pill pc-calendar__pill" :class="'pc-calendar__pill--' + person.tone">{{ person.label }}</span>
											</li>
										</ul>
									</div>
								</div>
								<p v-if="isProposal(event)" class="pc-calendar__hint">
									<span>{{ proposalHint(event, now) }}</span>
									<a :href="calendarUrl" class="pc-calendar__link">Open Calendar</a>
								</p>
							</div>
						</li>
					</ul>
				</div>

				<div v-if="hasMore || error" class="pc-calendar__more">
					<span v-if="error" class="pc-calendar__error" role="alert">{{ error }}</span>
					<button v-if="hasMore"
						type="button"
						class="iz-btn iz-btn--sm"
						:disabled="loadingMore"
						@click="loadMore">
						{{ loadingMore ? 'Loading…' : 'Load older events' }}
					</button>
				</div>
			</section>
		</template>
	</section>
</template>

<script>
import ProjectCalendar from '../components/ProjectCalendar.vue'
import CalendarBlank from 'vue-material-design-icons/CalendarBlank.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import { generateUrl } from '@nextcloud/router'
import { isProposal, isMeeting, keyOf, groupsOf, dateBlock, longDay, timeRange, optionsOf, allPassed, answeredOf, peopleOf, attendeeSummary, metaOf, proposalStatus, proposalHint } from './calendar.js'

// The Calendar tab of the new layout. Extends ProjectCalendar for its loading,
// paging and All / Proposals / Meetings filter; the list is grouped by what
// needs doing, and each item opens for its dates and participants.
export default {
	name: 'NewCalendar',
	components: { CalendarBlank, ChevronRight },
	extends: ProjectCalendar,
	data() {
		return { open: {}, now: Date.now() }
	},
	computed: {
		groups() { return groupsOf(this.events, this.filterType, this.now) },
		// The organizer picks a date in the Calendar app; it has no link to one proposal.
		calendarUrl() { return generateUrl('/apps/calendar/') },
		tabs() {
			return [
				{ key: 'all', label: 'All', count: this.events.length },
				{ key: 'proposals', label: 'Proposals', count: this.proposalCount },
				{ key: 'meetings', label: 'Meetings', count: this.confirmedCount },
			]
		},
	},
	watch: {
		events() { this.now = Date.now() },
	},
	methods: {
		isProposal,
		isMeeting,
		keyOf,
		dateBlock,
		longDay,
		timeRange,
		optionsOf,
		allPassed,
		answeredOf,
		peopleOf,
		attendeeSummary,
		metaOf,
		proposalStatus,
		proposalHint,
		isOpen(event) { return !!this.open[keyOf(event)] },
		toggle(event) { this.$set(this.open, keyOf(event), !this.isOpen(event)) },
		answeredWidth(event) {
			const { answered, total } = answeredOf(event)
			return total ? Math.round(answered / total * 100) + '%' : '0%'
		},
	},
}
</script>

<style scoped>
.pc-calendar__empty { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 40px var(--iz-pad-panel); text-align: center; }
.pc-calendar__empty-icon { display: flex; align-items: center; justify-content: center; width: 48px; height: 48px; border-radius: var(--iz-radius-lg); background: var(--iz-accent-bg); color: var(--iz-accent-bg-text); }
.pc-calendar__empty-title { margin: 0; font-size: var(--iz-fs-lg); font-weight: 600; color: var(--iz-text); }
.pc-calendar__empty-text { max-width: 520px; margin: 0; font-size: var(--iz-fs-md); color: var(--iz-text-secondary); }

.pc-calendar__list { overflow: hidden; }
.pc-calendar__none { margin: 0; padding: 32px var(--iz-pad-panel); text-align: center; font-size: var(--iz-fs-md); color: var(--iz-text-secondary); }
.pc-calendar__group-title { margin: 0; padding: 10px var(--iz-pad-panel); background: var(--iz-surface-subtle); border-bottom: 1px solid var(--iz-border); font-size: var(--iz-fs-micro); font-weight: 700; color: var(--iz-text-secondary); text-transform: uppercase; letter-spacing: 0.5px; }
.pc-calendar__items { margin: 0; padding: 0; list-style: none; }
.pc-calendar__item { border-bottom: 1px solid var(--iz-border); }
.pc-calendar__item--past .pc-calendar__row { color: var(--iz-text-secondary); }
.pc-calendar__item--past .pc-calendar__title { color: var(--iz-text-secondary); }

.pc-calendar__row { display: grid; grid-template-columns: 48px minmax(0, 1fr) auto 18px; align-items: center; gap: 14px; width: 100%; min-height: 72px; margin: 0; padding: 12px var(--iz-pad-panel); border: 0; border-radius: 0; background: transparent; color: var(--iz-text); font: inherit; text-align: start; cursor: pointer; }
.pc-calendar__row:hover { background: var(--iz-surface-subtle); }
.pc-calendar__row:focus-visible { outline: 2px solid var(--iz-accent); outline-offset: -2px; }

.pc-calendar__block { display: flex; flex-direction: column; align-items: center; justify-content: center; width: 48px; height: 48px; box-sizing: border-box; border: 1px solid var(--iz-border); border-radius: var(--iz-radius); background: var(--iz-surface); font-size: 9px; line-height: 1.1; color: var(--iz-text-secondary); }
.pc-calendar__block--proposal { border: 0; background: var(--iz-accent-bg); color: var(--iz-accent-bg-text); font-size: 10px; font-weight: 700; }
.pc-calendar__block-month { font-weight: 700; color: var(--iz-accent); text-transform: uppercase; }
.pc-calendar__block-big { font-family: 'Space Grotesk', system-ui, -apple-system, sans-serif; font-size: 18px; font-weight: 600; color: var(--iz-text); }
.pc-calendar__block--proposal .pc-calendar__block-big { color: inherit; font-size: 17px; }

.pc-calendar__text { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.pc-calendar__title-line { display: flex; align-items: center; flex-wrap: wrap; gap: 4px 8px; }
.pc-calendar__title { font-size: var(--iz-fs-md); font-weight: 600; color: var(--iz-text); overflow-wrap: anywhere; }
.pc-calendar__meta { font-size: var(--iz-fs-sm); color: var(--iz-text-secondary); overflow-wrap: anywhere; }
.pc-calendar__status { display: flex; flex-direction: column; align-items: flex-end; gap: 5px; min-width: 130px; }
.pc-calendar__status-text { max-width: 260px; font-size: var(--iz-fs-sm); font-weight: 600; color: var(--iz-text); text-align: end; }
.pc-calendar__status-text--muted { font-weight: 500; color: var(--iz-text-secondary); }
.pc-calendar__status-text--accent { color: var(--iz-accent-bg-text); }
.pc-calendar__status-text--warning { color: var(--iz-warning-text); }
.pc-calendar__meter, .pc-calendar__meter .iz-meter__fill { display: block; }
.pc-calendar__meter { width: 120px; }
.pc-calendar__chevron { display: inline-flex; color: var(--iz-text-secondary); transition: transform var(--iz-transition, 150ms); }
.pc-calendar__item--open .pc-calendar__chevron { transform: rotate(90deg); }

.pc-calendar__pill { text-transform: none; white-space: nowrap; }
.pc-calendar__pill--success { background: var(--iz-success-bg); color: var(--iz-success-text); }
.pc-calendar__pill--warning { background: var(--iz-warning-bg); color: var(--iz-warning-text); }
.pc-calendar__pill--danger { background: var(--iz-danger-bg); color: var(--iz-danger-text); }
.pc-calendar__pill--muted { background: var(--iz-surface-inset); color: var(--iz-text-secondary); }

.pc-calendar__detail { display: flex; flex-direction: column; gap: 12px; padding: 0 var(--iz-pad-panel) 16px calc(var(--iz-pad-panel) + 62px); }
.pc-calendar__description { margin: 0; font-size: var(--iz-fs-md); color: var(--iz-text); white-space: pre-line; overflow-wrap: anywhere; }
.pc-calendar__columns { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 24px; }
.pc-calendar__column { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.pc-calendar__label { margin: 0; font-size: var(--iz-fs-micro); font-weight: 700; color: var(--iz-text-secondary); text-transform: uppercase; letter-spacing: 0.5px; }
.pc-calendar__options { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); grid-template-rows: repeat(var(--pc-rows, 1), auto); grid-auto-flow: column; gap: 4px 16px; margin: 0; padding: 0; list-style: none; font-size: var(--iz-fs-md); }
.pc-calendar__options li { display: flex; gap: 8px; }
.pc-calendar__option-number { min-width: 16px; color: var(--iz-text-secondary); }
.pc-calendar__option--passed { color: var(--iz-text-secondary); }
.pc-calendar__option--passed > span:nth-child(2) { text-decoration: line-through; }
.pc-calendar__when { margin: 0; font-size: var(--iz-fs-md); }
.pc-calendar__people { display: flex; flex-direction: column; gap: 6px; margin: 0; padding: 0; list-style: none; }
.pc-calendar__person { display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: var(--iz-fs-md); }
.pc-calendar__person-name { min-width: 0; overflow-wrap: anywhere; }
.pc-calendar__muted { margin: 0; color: var(--iz-text-secondary); font-size: var(--iz-fs-sm); }

.pc-calendar__hint { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px 16px; margin: 0; padding-top: 10px; border-top: 1px solid var(--iz-border); font-size: var(--iz-fs-sm); color: var(--iz-text-secondary); }
.pc-calendar__link { font-weight: 600; color: var(--iz-accent-bg-text); white-space: nowrap; }
.pc-calendar__more { display: flex; align-items: center; justify-content: center; gap: var(--iz-gap-tight); padding: 12px; }
.pc-calendar__error { font-size: var(--iz-fs-sm); color: var(--iz-danger-text); }
.iz-btn:disabled { cursor: default; opacity: 0.5; }

@media (max-width: 760px) {
	.pc-calendar__row { grid-template-columns: 48px minmax(0, 1fr) 18px; row-gap: 6px; padding-inline: var(--iz-pad-card); }
	.pc-calendar__status { grid-column: 2; grid-row: 2; align-items: flex-start; min-width: 0; }
	.pc-calendar__status-text { max-width: none; text-align: start; }
	.pc-calendar__chevron { grid-column: 3; grid-row: 1; }
	.pc-calendar__detail { padding-inline: var(--iz-pad-card); }
	.pc-calendar__columns, .pc-calendar__options { grid-template-columns: minmax(0, 1fr); }
	.pc-calendar__options { grid-template-rows: none; grid-auto-flow: row; }
	.pc-calendar__group-title { padding-inline: var(--iz-pad-card); }
}
</style>
