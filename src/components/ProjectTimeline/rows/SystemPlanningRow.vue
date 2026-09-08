<template>
	<div v-if="systemPlanning" class="system-planning-row">
		<!-- 1. Deck Tasks Bar -->
		<div
			v-if="deckBarWidth > 0"
			class="sp-bar sp-bar--deck"
			:style="{ left: deckBarLeft + 'px', width: deckBarWidth + 'px' }"
			:title="`Deck tasks (${systemPlanning.deckTasks.weeks} weeks): ${formatDate(systemPlanning.deckTasks.startDate)} - ${formatDate(systemPlanning.deckTasks.endDate)}`">
			<span v-if="deckBarWidth > 50" class="sp-bar__label">
				Deck tasks ({{ systemPlanning.deckTasks.weeks }} weeks)
			</span>
		</div>

		<!-- 2. Preparation Bar -->
		<div
			v-if="prepBarWidth > 0"
			class="sp-bar sp-bar--prep"
			:style="{ left: prepBarLeft + 'px', width: prepBarWidth + 'px' }"
			:title="`Preparation (${systemPlanning.preparation.weeks} weeks): ${formatDate(systemPlanning.preparation.startDate)} - ${formatDate(systemPlanning.preparation.endDate)}`">
			<span v-if="prepBarWidth > 50" class="sp-bar__label">
				Preparation ({{ systemPlanning.preparation.weeks }} weeks)
			</span>
		</div>

		<!-- 3. Minimum Start Marker -->
		<div
			v-if="systemPlanning.minimumStart && systemPlanning.minimumStart.date"
			class="sp-marker sp-marker--min-start"
			:style="{ left: minStartLeft + 'px' }"
			:title="`Minimum start: ${formatDate(systemPlanning.minimumStart.date)}`">
			<div class="sp-marker__pin">
				<RhombusMedium :size="16" />
			</div>
			<div class="sp-marker__tag">
				<span class="sp-marker__tag-title">Minimum start</span>
				<span class="sp-marker__tag-date">{{ formatDate(systemPlanning.minimumStart.date) }}</span>
			</div>
			<div class="sp-marker__guide-line"></div>
		</div>

		<!-- 4. Float Bar (if desired start date is configured) -->
		<div
			v-if="floatBarWidth > 0"
			class="sp-bar sp-bar--float"
			:class="floatBarClass"
			:style="{ left: floatBarLeft + 'px', width: floatBarWidth + 'px' }"
			:title="floatTooltip">
			<span v-if="floatBarWidth > 40" class="sp-bar__label">
				{{ floatBarLabel }}
			</span>
		</div>

		<!-- 5. Desired Start Marker (if desired start date is configured) -->
		<div
			v-if="systemPlanning.desiredStart && systemPlanning.desiredStart.date"
			class="sp-marker sp-marker--desired-start"
			:style="{ left: desiredStartLeft + 'px' }"
			:title="`Desired start: ${formatDate(systemPlanning.desiredStart.date)}`">
			<div class="sp-marker__pin">
				<Play :size="14" />
			</div>
			<div class="sp-marker__tag">
				<span class="sp-marker__tag-title">Desired start</span>
				<span class="sp-marker__tag-date">{{ formatDate(systemPlanning.desiredStart.date) }}</span>
			</div>
			<div class="sp-marker__guide-line"></div>
		</div>

		<!-- 6. Mini Legend directly under the bars matching mockup -->
		<div class="sp-mini-legend" :style="{ left: Math.max(16, deckBarLeft) + 'px' }">
			<div class="sp-mini-legend__item">
				<span class="sp-mini-legend__swatch sp-mini-legend__swatch--deck" />
				<span>Deck tasks</span>
			</div>
			<div class="sp-mini-legend__item">
				<span class="sp-mini-legend__swatch sp-mini-legend__swatch--prep" />
				<span>Preparation</span>
			</div>
			<div class="sp-mini-legend__item">
				<span class="sp-mini-legend__swatch sp-mini-legend__swatch--float" />
				<span>Float</span>
			</div>
			<div class="sp-mini-legend__item">
				<span class="sp-mini-legend__icon sp-mini-legend__icon--min-start">◆</span>
				<span>Minimum start</span>
			</div>
			<div v-if="systemPlanning.desiredStart && systemPlanning.desiredStart.date" class="sp-mini-legend__item">
				<span class="sp-mini-legend__icon sp-mini-legend__icon--desired-start">▶</span>
				<span>Desired start</span>
			</div>
		</div>
	</div>
</template>

<script>
import RhombusMedium from 'vue-material-design-icons/RhombusMedium.vue'
import Play from 'vue-material-design-icons/Play.vue'

export default {
	name: 'SystemPlanningRow',
	components: {
		RhombusMedium,
		Play,
	},
	props: {
		systemPlanning: {
			type: Object,
			required: true,
		},
		timelineStart: {
			type: [Date, String],
			required: true,
		},
		dayWidth: {
			type: Number,
			default: 18,
		},
	},
	computed: {
		startDateObj() {
			if (this.timelineStart instanceof Date) {
				return this.timelineStart
			}
			return this.parseDate(this.timelineStart)
		},
		deckBarLeft() {
			return this.getLeft(this.systemPlanning.deckTasks?.startDate)
		},
		deckBarWidth() {
			return this.getWidth(
				this.systemPlanning.deckTasks?.startDate,
				this.systemPlanning.deckTasks?.endDate
			)
		},
		prepBarLeft() {
			return this.getLeft(this.systemPlanning.preparation?.startDate)
		},
		prepBarWidth() {
			return this.getWidth(
				this.systemPlanning.preparation?.startDate,
				this.systemPlanning.preparation?.endDate
			)
		},
		minStartLeft() {
			return this.getLeft(this.systemPlanning.minimumStart?.date)
		},
		desiredStartLeft() {
			return this.getLeft(this.systemPlanning.desiredStart?.date)
		},
		isPositiveFloat() {
			const floatDays = this.systemPlanning.float?.days
			return floatDays === null || floatDays === undefined || floatDays >= 0
		},
		floatBarClass() {
			return this.isPositiveFloat ? 'sp-bar--float-positive' : 'sp-bar--float-negative'
		},
		floatBarLeft() {
			if (this.isPositiveFloat) {
				return this.minStartLeft
			}
			return this.desiredStartLeft
		},
		floatBarWidth() {
			if (!this.systemPlanning.desiredStart?.date || !this.systemPlanning.minimumStart?.date) {
				return 0
			}
			const minD = this.parseDate(this.systemPlanning.minimumStart.date)
			const desD = this.parseDate(this.systemPlanning.desiredStart.date)
			const days = Math.abs(Math.floor((desD - minD) / 86400000))
			return days * this.dayWidth
		},
		floatBarLabel() {
			const weeks = this.systemPlanning.float?.weeks
			if (weeks === null || weeks === undefined) return ''
			if (this.isPositiveFloat) {
				return `Float (${weeks}w)`
			}
			return `Behind (${Math.abs(weeks)}w)`
		},
		floatTooltip() {
			const weeks = this.systemPlanning.float?.weeks
			if (weeks === null || weeks === undefined) return ''
			if (this.isPositiveFloat) {
				return `Available Float: +${weeks} week(s)`
			}
			return `Negative Float: ${weeks} week(s) behind schedule`
		},
	},
	methods: {
		parseDate(str) {
			if (!str || typeof str !== 'string') return new Date()
			const [y, m, d] = str.split('T')[0].split('-').map(Number)
			return new Date(y, m - 1, d, 0, 0, 0, 0)
		},
		getLeft(dateStr) {
			if (!dateStr) return 0
			const d = this.parseDate(dateStr)
			const diffDays = Math.floor((d - this.startDateObj) / (1000 * 60 * 60 * 24))
			return diffDays * this.dayWidth
		},
		getWidth(startStr, endStr) {
			if (!startStr || !endStr) return 0
			const d1 = this.parseDate(startStr)
			const d2 = this.parseDate(endStr)
			const diffDays = Math.max(1, Math.floor((d2 - d1) / (1000 * 60 * 60 * 24)))
			return diffDays * this.dayWidth
		},
		formatDate(str) {
			if (!str || typeof str !== 'string') return ''
			const parts = str.split('T')[0].split('-')
			if (parts.length === 3) {
				return `${parts[2]}/${parts[1]}/${parts[0]}`
			}
			return str
		},
	},
}
</script>

<style scoped>
.system-planning-row {
	position: relative;
	height: 76px;
	box-sizing: border-box;
	border-bottom: 1px solid var(--color-border);
	background: rgba(241, 245, 249, 0.4);
	display: flex;
	align-items: center;
	overflow: visible;
}

.sp-bar {
	position: absolute;
	height: 28px;
	top: 12px;
	border-radius: 6px;
	display: flex;
	align-items: center;
	padding: 0 8px;
	white-space: nowrap;
	overflow: hidden;
	box-sizing: border-box;
	z-index: 2;
	transition: all 0.2s ease;
}

.sp-bar__label {
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.2px;
	text-overflow: ellipsis;
	overflow: hidden;
	white-space: nowrap;
}

.sp-bar--deck {
	background: #1d4ed8;
	color: #ffffff;
	border-top-right-radius: 0;
	border-bottom-right-radius: 0;
}

.sp-bar--prep {
	background: #7c3aed;
	color: #ffffff;
	border-top-left-radius: 0;
	border-bottom-left-radius: 0;
}

.sp-bar--float {
	height: 24px;
	top: 14px;
	z-index: 1;
}

.sp-bar--float-positive {
	border: 2px dashed #10b981;
	background: rgba(16, 185, 129, 0.12);
	color: #047857;
}

.sp-bar--float-negative {
	border: 2px dashed #ef4444;
	background: rgba(239, 68, 68, 0.12);
	color: #b91c1c;
}

/* Markers */
.sp-marker {
	position: absolute;
	top: 0;
	height: 100%;
	z-index: 4;
	pointer-events: auto;
}

.sp-marker__pin {
	position: absolute;
	top: -4px;
	left: -8px;
	width: 16px;
	height: 16px;
	display: flex;
	align-items: center;
	justify-content: center;
}

.sp-marker--min-start .sp-marker__pin {
	color: #7c3aed;
}

.sp-marker--desired-start .sp-marker__pin {
	color: #4f46e5;
}

.sp-marker__tag {
	position: absolute;
	top: -38px;
	left: -35px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	padding: 2px 6px;
	border-radius: 4px;
	box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08);
	display: flex;
	flex-direction: column;
	align-items: center;
	white-space: nowrap;
	pointer-events: none;
	z-index: 5;
}

.sp-marker__tag-title {
	font-size: 9px;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
}

.sp-marker__tag-date {
	font-size: 10px;
	font-weight: 700;
	color: var(--color-main-text);
}

.sp-marker__guide-line {
	position: absolute;
	top: 10px;
	left: 0;
	width: 2px;
	height: 38px;
	opacity: 0.7;
}

.sp-marker--min-start .sp-marker__guide-line {
	background: #7c3aed;
}

.sp-marker--desired-start .sp-marker__guide-line {
	background: #4f46e5;
}

/* Mini Legend below bars */
.sp-mini-legend {
	position: absolute;
	top: 48px;
	display: flex;
	align-items: center;
	gap: 16px;
	font-size: 11px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	pointer-events: none;
	user-select: none;
	white-space: nowrap;
	z-index: 3;
}

.sp-mini-legend__item {
	display: inline-flex;
	align-items: center;
	gap: 6px;
}

.sp-mini-legend__swatch {
	width: 11px;
	height: 11px;
	border-radius: 3px;
	flex-shrink: 0;
}

.sp-mini-legend__swatch--deck {
	background: #1d4ed8;
}

.sp-mini-legend__swatch--prep {
	background: #7c3aed;
}

.sp-mini-legend__swatch--float {
	border: 1.5px dashed #10b981;
	background: rgba(16, 185, 129, 0.15);
}

.sp-mini-legend__icon {
	font-size: 11px;
	line-height: 1;
	display: flex;
	align-items: center;
}

.sp-mini-legend__icon--min-start {
	color: #7c3aed;
}

.sp-mini-legend__icon--desired-start {
	color: #4f46e5;
}
</style>
