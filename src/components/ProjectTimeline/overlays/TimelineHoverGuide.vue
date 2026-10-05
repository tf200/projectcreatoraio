<template>
	<div v-if="dayIndex !== null" class="hover-guide" aria-hidden="true">
		<!-- Vertical line through the header week row and all rows -->
		<div
			class="hover-guide__line"
			:style="{ left: lineX + 'px', top: (headerHeight - weekRowHeight) + 'px' }" />

		<!-- Pinned with the sticky timeline header while the rows scroll -->
		<div class="hover-guide__head">
			<!-- Hovered ISO week highlight in the header week row -->
			<div
				class="hover-guide__week"
				:style="{
					left: weekLeft + 'px',
					width: weekWidth + 'px',
					top: (headerHeight - weekRowHeight) + 'px',
					height: weekRowHeight + 'px',
				}" />

			<!-- Date chip above the week row -->
			<div
				class="hover-guide__chip"
				:style="{ left: chipX + 'px', top: Math.max(2, headerHeight - weekRowHeight - 24) + 'px' }">
				<span class="hover-guide__date">{{ dateLabel }}</span>
				<span class="hover-guide__week-tag">W{{ weekInfo.isoWeek }}</span>
				<span class="hover-guide__relative">{{ relativeLabel }}</span>
			</div>
		</div>
	</div>
</template>

<script>
import {
	DAY_MS,
	addDays,
	daysSinceMonday,
	formatRelativeDays,
	getIsoWeekInfo,
	toDateOnly,
} from '../timelineDates.js'

// Rough half-width of the chip, used to keep it inside the timeline edges
const CHIP_HALF_WIDTH = 110

export default {
	name: 'TimelineHoverGuide',
	props: {
		timelineStart: {
			type: Date,
			required: true,
		},
		dayWidth: {
			type: Number,
			required: true,
		},
		totalDays: {
			type: Number,
			required: true,
		},
		headerHeight: {
			type: Number,
			required: true,
		},
	},
	data() {
		return {
			// Hover state lives here (not in GanttChart) so pointer moves only re-render this overlay
			dayIndex: null,
			weekRowHeight: 22,
		}
	},
	computed: {
		hoveredDate() {
			return addDays(this.timelineStart, this.dayIndex)
		},
		weekInfo() {
			return getIsoWeekInfo(this.hoveredDate)
		},
		lineX() {
			return this.dayIndex * this.dayWidth + this.dayWidth / 2
		},
		chipX() {
			const max = this.totalDays * this.dayWidth - CHIP_HALF_WIDTH
			return Math.min(Math.max(this.lineX, CHIP_HALF_WIDTH), Math.max(CHIP_HALF_WIDTH, max))
		},
		weekLeft() {
			return Math.max(0, (this.dayIndex - daysSinceMonday(this.hoveredDate)) * this.dayWidth)
		},
		weekWidth() {
			const mondayIndex = this.dayIndex - daysSinceMonday(this.hoveredDate)
			const startPx = mondayIndex * this.dayWidth
			const endPx = Math.min((mondayIndex + 7) * this.dayWidth, this.totalDays * this.dayWidth)
			return endPx - Math.max(0, startPx)
		},
		dateLabel() {
			return this.hoveredDate.toLocaleDateString('default', {
				weekday: 'short',
				day: 'numeric',
				month: 'short',
				year: 'numeric',
			})
		},
		relativeLabel() {
			const diff = Math.round((toDateOnly(this.hoveredDate) - toDateOnly(new Date())) / DAY_MS)
			return formatRelativeDays(diff)
		},
	},
	methods: {
		/** @param {number} x pointer position in px relative to the timeline's left edge */
		setPointerX(x) {
			if (x < 0 || this.dayWidth <= 0) {
				this.clear()
				return
			}
			const index = Math.floor(x / this.dayWidth)
			this.dayIndex = index >= this.totalDays ? null : index
		},
		clear() {
			this.dayIndex = null
		},
	},
}
</script>

<style scoped>
.hover-guide {
	position: absolute;
	inset: 0;
	pointer-events: none;
	z-index: 21;
}

.hover-guide__head {
	position: sticky;
	top: 0;
	height: 0;
}

.hover-guide__week {
	position: absolute;
	background: var(--color-primary-element-light);
	opacity: 0.6;
}

.hover-guide__line {
	position: absolute;
	bottom: 0;
	width: 0;
	border-left: 1px dashed var(--color-primary-element);
	transform: translateX(-0.5px);
}

.hover-guide__chip {
	position: absolute;
	transform: translateX(-50%);
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 2px 8px;
	border-radius: 10px;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-size: 11px;
	font-weight: 600;
	white-space: nowrap;
	box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
}

.hover-guide__week-tag {
	font-weight: 800;
}

.hover-guide__relative {
	opacity: 0.8;
	font-weight: 500;
}
</style>
