<template>
	<section class="pc-progress iz-app" aria-label="Board progress">
		<div v-if="loading && !board" class="iz-empty" role="status">
			Loading progress…
		</div>
		<div v-else-if="error && !board" class="iz-empty pc-view__failure" role="alert">
			<span>{{ error }}</span>
			<button type="button" class="iz-btn" @click="load">
				Try again
			</button>
		</div>
		<template v-else-if="board">
			<div class="pc-progress__panel">
				<h3 class="pc-progress__title">
					Progress
				</h3>
				<div class="pc-progress__summaries">
					<div class="pc-progress__summary">
						<p class="pc-progress__headline">
							<strong>{{ counts.percent }}%</strong>
							<span>of all tasks done · {{ counts.completed }} of {{ counts.total }}</span>
							<span v-if="counts.overdue" class="pc-progress__overdue">· {{ counts.overdue }} overdue</span>
						</p>
						<div class="iz-meter"
							role="progressbar"
							aria-label="Tasks done"
							aria-valuemin="0"
							aria-valuemax="100"
							:aria-valuenow="counts.percent">
							<div class="iz-meter__fill" :style="{ width: counts.percent + '%' }" />
						</div>
					</div>
					<div class="pc-progress__summary">
						<p v-if="counts.criticalTotal" class="pc-progress__headline">
							<strong>{{ counts.criticalDone }} of {{ counts.criticalTotal }}</strong>
							<span>critical process steps done</span>
						</p>
						<p v-else class="pc-progress__headline pc-progress__headline--none">
							No critical process steps yet
						</p>
						<div v-if="counts.criticalTotal" class="pc-progress__segments" aria-hidden="true">
							<span v-for="step in segments"
								:key="step.id"
								class="pc-progress__segment"
								:title="step.title + ' — ' + step.column"
								:style="{ background: colorOf(step) }" />
						</div>
					</div>
				</div>

				<p v-if="!steps.length" class="pc-progress__none">
					No critical process steps on this board. Label a card “Kritieke Processtap” in Deck to follow it here.
				</p>
				<div v-else class="pc-progress__wrap" :class="{ 'pc-progress__wrap--more': more }">
					<div ref="scroller"
						class="pc-progress__scroll"
						tabindex="0"
						role="region"
						aria-label="Critical process steps, open first"
						@scroll="measure">
						<table class="pc-progress__table" :class="{ 'pc-progress__table--pills': pills }">
							<thead>
								<tr>
									<th scope="col">
										Critical step
									</th>
									<th scope="col" class="pc-progress__track-head">
										<span class="pc-sr-only">Column</span>
										<span v-if="!pills"
											class="pc-progress__stops"
											aria-hidden="true"
											:style="{ gridTemplateColumns: 'repeat(' + board.columns.length + ', minmax(0, 1fr))' }">
											<span v-for="column in board.columns" :key="column.id">{{ column.title }}</span>
										</span>
										<span v-else aria-hidden="true">Column</span>
									</th>
									<th scope="col" class="pc-progress__due-head">
										Due
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="step in steps" :key="step.id" :class="{ 'pc-progress__row--done': step.completed }">
									<th scope="row" class="pc-progress__step">
										{{ step.title }}
									</th>
									<td class="pc-progress__track-cell">
										<span class="pc-sr-only">{{ step.column }}</span>
										<span class="iz-pill pc-progress__pill" aria-hidden="true">{{ step.column }}</span>
										<span v-if="!pills"
											class="pc-progress__track"
											aria-hidden="true"
											:style="{ gridTemplateColumns: 'repeat(' + board.columns.length + ', minmax(0, 1fr))', '--pc-stops': board.columns.length, '--pc-reach': reachOf(step), '--pc-stage': colorOf(step) }">
											<span v-for="(column, index) in board.columns"
												:key="column.id"
												class="pc-progress__stop"
												:class="stopClass(step, index)" />
										</span>
									</td>
									<td class="pc-progress__due" :class="'pc-progress__due--' + dueOf(step).tone">
										{{ dueOf(step).text }}
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
				<p v-if="error" class="pc-progress__stale" role="alert">
					{{ error }}
					<button type="button" class="iz-btn iz-btn--sm" @click="load">
						Try again
					</button>
				</p>
			</div>
		</template>
	</section>
</template>

<script>
import { api, errorMessage } from './api.js'
import { boardOf, countsOf, stepsOf, dueOf } from './tasks-progress.js'

// The most columns the per-step track shows; a board with more gets a pill.
const MAX_STOPS = 8

// Task completion and the critical process steps of a project's Deck board, above
// the embedded board in the new layout's Tasks tab. refreshKey changes when the
// embedded board changes something, and the board is read again.
export default {
	name: 'TaskProgress',
	props: {
		boardId: { type: Number, required: true },
		refreshKey: { type: Number, default: 0 },
	},
	data() {
		return { board: null, loading: false, error: '', request: 0, more: false, now: Date.now() }
	},
	computed: {
		counts() { return countsOf(this.board.cards, this.now) },
		steps() { return stepsOf(this.board.cards) },
		// The bar above the list: furthest along first.
		segments() { return this.steps.slice().sort((a, b) => Number(b.completed) - Number(a.completed) || b.stage - a.stage) },
		pills() { return this.board.columns.length > MAX_STOPS },
	},
	watch: {
		boardId: { immediate: true, handler() { this.board = null; this.load() } },
		refreshKey() { this.load() },
	},
	beforeDestroy() {
		this.request++
	},
	methods: {
		dueOf(step) { return dueOf(step, this.now) },
		async load() {
			const request = ++this.request
			this.loading = true
			this.error = ''
			try {
				const stacks = await api.deckStacks(this.boardId)
				if (request !== this.request) return
				if (!Array.isArray(stacks)) throw new Error('Invalid board response')
				this.now = Date.now()
				this.board = boardOf(stacks)
				this.$nextTick(this.measure)
			} catch (e) {
				if (request !== this.request) return
				// A later read that fails keeps what is on screen and says so.
				this.error = this.board ? 'Progress could not be refreshed.' : errorMessage(e)
			} finally {
				if (request === this.request) this.loading = false
			}
		},
		// Whether the list has rows below the visible part, for the fade.
		measure() {
			const el = this.$refs.scroller
			this.more = !!el && el.scrollHeight - el.scrollTop - el.clientHeight > 2
		},
		// Columns that count as done read green; the rest deepen towards the accent.
		colorOf(step) {
			if (step.completed || this.board.columns[step.stage]?.done) return 'var(--iz-success)'
			const open = this.board.columns.filter(column => !column.done).length
			const share = open > 1 ? step.stage / (open - 1) : 1
			return `color-mix(in oklab, var(--iz-accent) ${Math.round(35 + share * 65)}%, var(--iz-border-strong))`
		},
		reachOf(step) {
			const span = this.board.columns.length - 1
			return span > 0 ? (step.stage / span * 100) + '%' : '0%'
		},
		stopClass(step, index) {
			if (index === step.stage) return 'pc-progress__stop--here'
			return index < step.stage ? 'pc-progress__stop--passed' : ''
		},
	},
}
</script>

<style scoped>
.pc-progress { display: flex; flex-direction: column; gap: 12px; margin-bottom: 14px; container: pc-progress / inline-size; }

.pc-progress__panel { display: flex; flex-direction: column; gap: 14px; padding: 16px 18px; border: 1px solid var(--iz-border); border-radius: var(--iz-radius-lg); }
.pc-progress__title { margin: 0; font-size: var(--iz-fs-md); font-weight: 700; color: var(--iz-text); }
.pc-progress__summaries { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 24px; }
.pc-progress__summary { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.pc-progress__headline { display: flex; align-items: baseline; flex-wrap: wrap; gap: 4px 8px; margin: 0; font-size: var(--iz-fs-sm); color: var(--iz-text-secondary); }
.pc-progress__overdue { font-weight: 700; color: var(--iz-danger-text); }
.pc-progress__headline strong { font-family: 'Space Grotesk', system-ui, -apple-system, sans-serif; font-size: var(--iz-fs-xl); font-weight: 600; color: var(--iz-text); }
.pc-progress__summary .iz-meter { height: 8px; }
.pc-progress__headline--none { min-height: 30px; align-items: center; }
.pc-progress__segments { display: flex; gap: 3px; height: 8px; }
.pc-progress__segment { flex: 1 1 0; border-radius: 3px; }
.pc-progress__none { margin: 0; padding: 16px; border: 1px dashed var(--iz-border-strong); border-radius: var(--iz-radius); font-size: var(--iz-fs-sm); color: var(--iz-text-secondary); text-align: center; }
.pc-progress__stale { display: flex; align-items: center; gap: var(--iz-gap-tight); margin: 0; font-size: var(--iz-fs-sm); color: var(--iz-danger-text); }

/* Eight rows, a Combi project's critical steps; more scroll inside, with a fade at the bottom edge. */
.pc-progress__wrap { position: relative; }
.pc-progress__wrap--more::after { content: ''; position: absolute; inset: auto 0 0; height: 40px; pointer-events: none; background: linear-gradient(to bottom, transparent, var(--iz-surface)); }
.pc-progress__scroll { max-height: 300px; overflow-y: auto; overscroll-behavior: contain; }
.pc-progress__scroll:focus-visible { outline: 2px solid var(--iz-accent); outline-offset: 2px; }
.pc-progress__table { width: 100%; border-collapse: collapse; table-layout: fixed; }
.pc-progress__table th, .pc-progress__table td { padding: 0 6px; height: 34px; border-bottom: 1px solid var(--iz-border); text-align: start; vertical-align: middle; }
.pc-progress__table thead th { position: sticky; top: 0; z-index: 1; height: auto; padding-block: 0 6px; background: var(--iz-surface); font-size: var(--iz-fs-micro); font-weight: 700; color: var(--iz-text-secondary); text-transform: uppercase; letter-spacing: 0.4px; vertical-align: bottom; }
.pc-progress__table thead th:first-child, .pc-progress__step { width: 220px; padding-inline-start: 0; }
.pc-progress__due-head, .pc-progress__due { width: 120px; text-align: end !important; padding-inline-end: 0 !important; }
.pc-progress__stops { display: grid; text-align: center; text-transform: none; letter-spacing: 0; font-size: 10px; }
.pc-progress__stops > span { padding: 0 2px; overflow-wrap: anywhere; }
.pc-progress__step { font-size: var(--iz-fs-sm); font-weight: 600; color: var(--iz-text); overflow-wrap: break-word; }
.pc-progress__row--done .pc-progress__step { color: var(--iz-text-secondary); }

.pc-progress__track { position: relative; display: grid; align-items: center; height: 18px; }
.pc-progress__track::before, .pc-progress__track::after { content: ''; position: absolute; top: 50%; height: 2px; margin-top: -1px; inset-inline-start: calc(50% / var(--pc-stops, 6)); }
.pc-progress__track::before { inset-inline-end: calc(50% / var(--pc-stops, 6)); background: var(--iz-border); }
.pc-progress__track::after { width: calc(var(--pc-reach) * (1 - 1 / var(--pc-stops, 6))); background: var(--pc-stage); }
.pc-progress__stop { position: relative; z-index: 1; justify-self: center; width: 8px; height: 8px; border-radius: 50%; background: var(--iz-surface); box-shadow: inset 0 0 0 1.5px var(--iz-border-strong); }
.pc-progress__stop--passed { background: var(--pc-stage); box-shadow: none; }
.pc-progress__stop--here { width: 14px; height: 14px; background: var(--pc-stage); box-shadow: 0 0 0 3px var(--iz-surface), 0 0 0 4px var(--pc-stage); }
.pc-progress__pill { text-transform: none; }
/* The pill stands in for the track when there are too many columns, and on a narrow screen. */
.pc-progress__table:not(.pc-progress__table--pills) .pc-progress__pill { display: none; }

.pc-progress__due { font-size: var(--iz-fs-sm); color: var(--iz-text); }
.pc-progress__due--done { color: var(--iz-success-text); }
.pc-progress__due--late { color: var(--iz-danger-text); font-weight: 700; }
.pc-progress__due--muted { color: var(--iz-text-secondary); }

@container pc-progress (max-width: 760px) {
	.pc-progress__summaries { grid-template-columns: minmax(0, 1fr); gap: 14px; }
	/* No scroll box inside a scrolling page on a narrow screen. */
	.pc-progress__scroll { max-height: none; }
	.pc-progress__wrap--more::after { display: none; }
	/* Each step on two lines: its name, then its column and due date. */
	.pc-progress__table, .pc-progress__table tbody { display: block; }
	.pc-progress__table thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
	.pc-progress__table tbody tr { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: 4px 8px; padding: 8px 0; border-bottom: 1px solid var(--iz-border); }
	.pc-progress__table tbody th, .pc-progress__table tbody td { display: block; width: auto; height: auto; padding: 0 !important; border: 0; }
	.pc-progress__step { grid-column: 1 / -1; overflow-wrap: break-word; }
	.pc-progress__stops, .pc-progress__track { display: none; }
	.pc-progress__table:not(.pc-progress__table--pills) .pc-progress__pill { display: inline-flex; }
}
</style>
