<template>
	<div class="timeline-v2">
		<header class="timeline-v2__header">
			<div class="timeline-v2__title-group">
				<h3 class="timeline-v2__title">
					Project Timeline
				</h3>
				<p class="timeline-v2__subtitle">
					Plan and track phases and milestones
				</p>
			</div>

			<div class="timeline-v2__controls">
				<div class="control-group">
					<NcButton type="tertiary" aria-label="Previous" @click="navigatePrev">
						<template #icon>
							<ChevronLeft :size="20" />
						</template>
					</NcButton>
					<NcButton type="tertiary" class="today-btn" @click="navigateToday">
						Today
					</NcButton>
					<NcButton type="tertiary" aria-label="Next" @click="navigateNext">
						<template #icon>
							<ChevronRight :size="20" />
						</template>
					</NcButton>
				</div>

				<div class="control-group">
					<NcButton type="tertiary" title="Zoom out" @click="zoomOut">
						<template #icon>
							<MagnifyMinusOutline :size="18" />
						</template>
					</NcButton>
					<span class="zoom-indicator">{{ dayWidth }}px/d</span>
					<NcButton type="tertiary" title="Zoom in" @click="zoomIn">
						<template #icon>
							<MagnifyPlusOutline :size="18" />
						</template>
					</NcButton>
				</div>

				<NcButton v-if="isAdmin" type="primary" @click="openAddModal">
					<template #icon>
						<Plus :size="18" />
					</template>
					Add Item
				</NcButton>
			</div>
		</header>

		<div class="timeline-v2__container">
			<div v-if="loading" class="timeline-v2__loading">
				<NcLoadingIcon :size="32" />
				<p>Syncing timeline data...</p>
			</div>

			<div v-else-if="items.length === 0" class="timeline-v2__empty">
				<ChartGantt :size="48" class="empty-icon" />
				<h4>No timeline items defined</h4>
				<p>Start by adding phases and milestones to visualize the timeline.</p>
				<NcButton v-if="isAdmin" type="primary" @click="openAddModal">
					Create first item
				</NcButton>
			</div>

			<div v-else class="gantt-v2" :class="{ 'gantt-v2--admin': isAdmin }">
				<!-- Sidebar: Phase List (Draggable) -->
				<div class="gantt-v2__sidebar">
					<div class="gantt-v2__header-cell" :style="{ height: timelineHeaderHeight + 'px' }">
						Timeline details
					</div>

					<draggable
						v-model="draggableItems"
						v-bind="dragOptions"
						handle=".drag-handle"
						class="gantt-v2__phase-list"
						@end="onDragEnd">
						<div v-for="item in draggableItems" :key="itemKey(item)" class="phase-row" :class="{ 'phase-row--system': isSystemItem(item) }">
							<div v-if="isAdmin" class="drag-handle" title="Drag to reorder">
								<DragVariant :size="18" />
							</div>
							<div class="phase-row__content">
								<div class="phase-row__top">
									<span class="phase-row__name">{{ item.label }}</span>
									<span class="phase-row__duration">{{ formatItemBadge(item) }}</span>
								</div>
								<div class="phase-row__dates">{{ formatItemDates(item) }}</div>
							</div>
						</div>
					</draggable>
				</div>

				<!-- Timeline: Bars & Grid -->
				<div
					ref="scrollEl"
					class="gantt-v2__main"
					:class="{ 'gantt-v2__main--dragging': isDragging }"
					@pointerdown="onPointerDown"
					@pointermove="onPointerMove"
					@pointerup="onPointerUp"
					@pointercancel="onPointerUp"
					@pointerleave="onPointerUp">
					<div class="gantt-v2__timeline" :style="{ width: totalTimelineWidth + 'px' }">
						<!-- Timeline Header -->
						<div class="gantt-v2__timeline-header" :style="{ height: timelineHeaderHeight + 'px' }">
							<div v-if="spanMultipleYears" class="year-row">
								<span
									v-for="year in visibleYears"
									:key="year.key"
									class="year-label"
									:style="{ width: year.width + 'px' }">{{ year.label }}</span>
							</div>
							<div class="month-row">
								<span
									v-for="month in visibleMonths"
									:key="month.key"
									class="month-label"
									:class="{ compact: month.width < 60 }"
									:style="{ width: month.width + 'px' }">
									{{ month.width < 40 ? '' : month.label }}
								</span>
							</div>
							<div class="week-row">
								<span
									v-for="week in visibleIsoWeeks"
									:key="week.key"
									class="week-label"
									:style="{ width: week.width + 'px' }"
									:title="week.tooltip">
									{{ week.width < 16 ? '' : week.label }}
								</span>
							</div>
						</div>

						<!-- Content Area -->
						<div class="gantt-v2__content">
							<!-- Grid Lines Background -->
							<div class="gantt-v2__grid-lines">
								<div
									v-for="month in visibleMonths"
									:key="'grid-' + month.key"
									class="grid-column"
									:style="{ width: month.width + 'px' }" />
							</div>

							<!-- Today Marker -->
							<div class="today-marker" :style="{ left: todayOffset + 'px' }">
								<div class="today-line" />
								<div class="today-badge">
									Today
								</div>
							</div>

						<!-- Rows -->
						<div v-for="item in items" :key="itemKey(item)" class="timeline-row">
							<div
								v-if="isMilestone(item)"
								class="timeline-milestone"
								:style="getMilestoneStyle(item)"
								:title="`${item.label}: ${formatDate(item.startDate)}`" />
							<div
								v-else
								class="timeline-bar"
								:class="{ 'timeline-bar--ongoing': isOngoing(item), 'timeline-bar--readonly': !canEditItem(item) }"
								:style="getBarStyle(item)"
								:title="barTitle(item)"
								@click="isAdmin && canEditItem(item) ? openEditModal(item) : null">
								<span v-if="getDurationDays(item) * dayWidth > 60" class="timeline-bar__label">
									{{ item.label }}
								</span>
							</div>
						</div>
					</div>
				</div>
				</div>

				<!-- Actions Column (Admin only) -->
				<div v-if="isAdmin" class="gantt-v2__actions">
					<div class="gantt-v2__header-cell" :style="{ height: timelineHeaderHeight + 'px' }">
						Actions
					</div>
					<div v-for="item in items" :key="'actions-' + itemKey(item)" class="action-row">
						<NcButton
							v-if="canEditItem(item)"
							type="tertiary"
							title="Edit item"
							@click="openEditModal(item)">
							<template #icon>
								<Pencil :size="16" />
							</template>
						</NcButton>
						<NcButton
							v-else
							type="tertiary"
							:disabled="true"
							title="System item">
							<template #icon>
								<Lock :size="16" />
							</template>
						</NcButton>
						<NcButton
							v-if="!isSystemItem(item)"
							type="error"
							title="Delete item"
							@click="confirmDelete(item)">
							<template #icon>
								<Delete :size="16" />
							</template>
						</NcButton>
					</div>
				</div>
			</div>

			<footer v-if="items.length > 0" class="timeline-v2__footer">
				<div class="phase-jumper">
					<span class="jumper-label">Focus on:</span>
					<div class="jumper-chips">
						<button
							v-for="(item, index) in items"
							:key="'chip-' + item.id"
							class="phase-chip"
							:class="{ active: currentPhaseIndex === index }"
							:style="{ '--phase-color': item.color }"
							@click="navigateToPhase(index)">
							{{ item.label }}
						</button>
					</div>
				</div>
			</footer>
		</div>

		<!-- Modal for Add/Edit -->
		<NcModal v-if="showModal" size="normal" @close="closeModal">
			<div class="phase-form">
				<header class="phase-form__header">
					<h3>{{ editingItem ? 'Edit Item' : 'Add New Item' }}</h3>
					<p>{{ editingItem ? 'Update the details for this timeline item.' : 'Add a phase or milestone to your project timeline.' }}</p>
				</header>

				<div class="phase-form__content">
					<div class="form-field">
						<NcTextField
							v-model="form.label"
							label="Label"
							:show-label="true"
							:disabled="isEditingSystemItem"
							placeholder="e.g., Design, Approval" />
					</div>

					<div class="form-row">
						<div class="form-field form-field--inline">
							<label class="form-label" :for="`timeline-type-${projectId}`">Type</label>
							<select
								:id="`timeline-type-${projectId}`"
								v-model="form.itemType"
								class="form-select"
								:disabled="isEditingSystemItem">
								<option value="phase">Phase</option>
								<option value="milestone">Milestone</option>
							</select>
						</div>
						<div v-if="form.itemType === 'phase'" class="form-field form-field--inline form-field--checkbox">
							<label class="form-checkbox">
								<input v-model="form.isOngoing" type="checkbox" :disabled="isEditingSystemItem">
								<span>Ongoing</span>
							</label>
						</div>
					</div>

					<div class="form-row">
						<NcTextField
							v-model="form.startDate"
							type="date"
							:label="form.itemType === 'milestone' ? 'Date' : 'Start Date'"
							:show-label="true" />
						<NcTextField
							v-if="form.itemType === 'phase'"
							v-model="form.endDate"
							type="date"
							label="End Date"
							:disabled="form.isOngoing"
							:show-label="true" />
					</div>

					<div class="form-field">
						<label class="form-label">Color</label>
						<div class="color-grid">
							<button
								v-for="color in colorOptions"
								:key="color"
								type="button"
								class="color-swatch"
								:class="{ active: form.color === color }"
								:style="{ backgroundColor: color }"
								@click="form.color = color" />
						</div>
					</div>
				</div>

				<footer class="phase-form__footer">
					<NcButton type="secondary" @click="closeModal">
						Cancel
					</NcButton>
					<NcButton
						type="primary"
						:disabled="!canSave || saving"
						@click="saveItem">
						{{ saving ? 'Saving...' : (editingItem ? 'Update Item' : 'Create Item') }}
					</NcButton>
				</footer>
			</div>
		</NcModal>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { NcButton, NcLoadingIcon, NcModal, NcTextField } from '@nextcloud/vue'
import draggable from 'vuedraggable'

import ChartGantt from 'vue-material-design-icons/ChartGantt.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import DragVariant from 'vue-material-design-icons/DragVariant.vue'
import Lock from 'vue-material-design-icons/Lock.vue'
import MagnifyMinusOutline from 'vue-material-design-icons/MagnifyMinusOutline.vue'
import MagnifyPlusOutline from 'vue-material-design-icons/MagnifyPlusOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'GanttChart',
	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcTextField,
		draggable,
		ChartGantt,
		ChevronLeft,
		ChevronRight,
		Delete,
		DragVariant,
		Lock,
		MagnifyMinusOutline,
		MagnifyPlusOutline,
		Pencil,
		Plus,
	},
	props: {
		projectId: {
			type: Number,
			required: true,
		},
		isAdmin: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			showProcessCompletedItem: false, // Temporarily hidden from UI
			loading: true,
			saving: false,
			allItems: [],
			showModal: false,
			editingItem: null,
			form: {
				label: '',
				itemType: 'phase',
				startDate: '',
				endDate: '',
				isOngoing: false,
				color: '#3b82f6',
			},
			colorOptions: [
				'#3b82f6', '#10b981', '#f59e0b', '#ef4444',
				'#8b5cf6', '#ec4899', '#06b6d4', '#84cc16',
				'#27272a', '#71717a',
			],
			dayWidth: 4,
			currentPhaseIndex: 0,
			isDragging: false,
			dragStartX: 0,
			dragStartScrollLeft: 0,
		}
	},
	computed: {
		items() {
			return (this.allItems || [])
				.filter((item) => this.showProcessCompletedItem || item.systemKey !== 'process_completed')
				.slice()
				.sort((a, b) => (Number(a.orderIndex) || 0) - (Number(b.orderIndex) || 0))
		},
		draggableItems: {
			get() {
				return this.items
			},
			set(value) {
				// vuedraggable provides the array in the new UI order. Keep the UI stable by
				// immediately updating `orderIndex` locally, otherwise `items` (which sorts
				// by `orderIndex`) will snap back to the previous order.
				const reordered = (value || []).slice()
				reordered.forEach((item, index) => {
					item.orderIndex = index
				})
				this.allItems = reordered
			},
		},
		dragOptions() {
			return {
				animation: 200,
				group: 'description',
				disabled: !this.isAdmin,
				ghostClass: 'phase-row--ghost',
			}
		},
		canSave() {
			const labelOk = this.form.label.trim() !== ''
			const startOk = String(this.form.startDate || '').trim() !== ''
			const type = String(this.form.itemType || 'phase')
			if (!labelOk || !startOk) return false
			if (type === 'milestone') return true
			if (this.form.isOngoing) return true
			const endOk = String(this.form.endDate || '').trim() !== ''
			if (!endOk) return false
			const start = new Date(`${this.form.startDate}T00:00:00`)
			const end = new Date(`${this.form.endDate}T00:00:00`)
			if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return false
			return end.getTime() >= start.getTime()
		},
		isEditingSystemItem() {
			return !!(this.editingItem && this.editingItem.systemKey)
		},
		timelineRange() {
			if (this.items.length === 0) {
				const now = new Date()
				return { start: new Date(now.getFullYear(), now.getMonth(), 1), end: new Date(now.getFullYear(), now.getMonth() + 6, 0) }
			}
			let minDate = null
			let maxDate = null
			const today = this.toDateOnly(new Date())
			for (const item of this.items) {
				const start = this.parseDateOnly(item.startDate)
				const end = this.isMilestone(item)
					? start
					: (item.endDate ? this.parseDateOnly(item.endDate) : (today < start ? start : today))
				if (!minDate || start < minDate) minDate = start
				if (!maxDate || end > maxDate) maxDate = end
			}
			if (today < minDate) minDate = today
			if (today > maxDate) maxDate = today
			const paddedStart = new Date(minDate)
			paddedStart.setMonth(paddedStart.getMonth() - 1)
			paddedStart.setDate(1)
			const paddedEnd = new Date(maxDate)
			paddedEnd.setMonth(paddedEnd.getMonth() + 2)
			paddedEnd.setDate(0)
			return { start: paddedStart, end: paddedEnd }
		},
		totalDays() {
			const { start, end } = this.timelineRange
			return Math.ceil((end - start) / (1000 * 60 * 60 * 24))
		},
		totalTimelineWidth() {
			return this.totalDays * this.dayWidth
		},
		visibleMonths() {
			const months = []
			const { start, end } = this.timelineRange
			let cursor = new Date(start)
			while (cursor.getTime() <= end.getTime()) {
				const monthStart = new Date(cursor.getFullYear(), cursor.getMonth(), 1)
				const monthEnd = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0)
				const effectiveStart = monthStart < start ? start : monthStart
				const effectiveEnd = monthEnd > end ? end : monthEnd
				const days = Math.ceil((effectiveEnd - effectiveStart) / (1000 * 60 * 60 * 24)) + 1
				const width = days * this.dayWidth
				const labelFormat = this.spanMultipleYears ? { month: 'short' } : { month: 'short', year: 'numeric' }
				months.push({
					key: `${cursor.getFullYear()}-${cursor.getMonth()}`,
					label: cursor.toLocaleDateString('default', labelFormat),
					width,
					year: cursor.getFullYear(),
				})
				cursor = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1)
			}
			return months
		},
		spanMultipleYears() {
			const { start, end } = this.timelineRange
			return start.getFullYear() !== end.getFullYear()
		},
		timelineHeaderHeight() {
			return this.spanMultipleYears ? 80 : 54
		},
		visibleYears() {
			if (!this.spanMultipleYears) return []
			const years = []
			const { start, end } = this.timelineRange
			for (let year = start.getFullYear(); year <= end.getFullYear(); year++) {
				const yearStart = new Date(Math.max(start.getTime(), new Date(year, 0, 1).getTime()))
				const yearEnd = new Date(Math.min(end.getTime(), new Date(year, 11, 31).getTime()))
				const days = Math.ceil((yearEnd - yearStart) / (1000 * 60 * 60 * 24)) + 1
				years.push({ key: `year-${year}`, label: year.toString(), width: days * this.dayWidth })
			}
			return years
		},
		visibleIsoWeeks() {
			const weeks = []
			const { start, end } = this.timelineRange
			let cursor = this.toDateOnly(start)
			while (cursor.getTime() <= end.getTime()) {
				const weekInfo = this.getIsoWeekInfo(cursor)
				const effectiveStart = cursor < start ? start : cursor
				const spanDays = Math.min(7, Math.floor((end - effectiveStart) / (1000 * 60 * 60 * 24)) + 1)
				weeks.push({
					key: `${weekInfo.isoYear}-W${weekInfo.isoWeek}`,
					label: `W${weekInfo.isoWeek}`,
					tooltip: `ISO Week ${weekInfo.isoWeek}, ${weekInfo.isoYear}`,
					width: spanDays * this.dayWidth,
				})
				cursor = new Date(cursor)
				cursor.setDate(cursor.getDate() + spanDays)
			}
			return weeks
		},
		todayOffset() {
			const { start } = this.timelineRange
			const today = this.toDateOnly(new Date())
			const days = Math.floor((today - start) / (1000 * 60 * 60 * 24))
			return days * this.dayWidth
		},
	},
	watch: {
		projectId: {
			handler(val) {
				if (val) this.loadItems()
			},
			immediate: true,
		},
	},
	methods: {
		isSystemItem(item) {
			return !!(item && item.systemKey)
		},
		itemKey(item) {
			const sys = String(item?.systemKey || '').trim()
			if (sys) return `sys-${sys}`
			return `id-${item?.id}`
		},
		canEditItem(item) {
			return !this.isSystemItem(item) && typeof item?.id === 'number'
		},
		isMilestone(item) {
			return String(item?.itemType || 'phase') === 'milestone'
		},
		isOngoing(item) {
			if (this.isMilestone(item)) return false
			return !String(item?.endDate || '').trim()
		},
		async loadItems() {
			this.loading = true
			try {
				const timelineUrl = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${this.projectId}/timeline`)
				const response = await axios.get(timelineUrl)
				this.allItems = response.data || []
			} finally {
				this.loading = false
			}
		},
		formatDate(dateStr) {
			if (!dateStr) return '-'
			const date = this.parseDateOnly(dateStr)
			const day = date.getDate().toString().padStart(2, '0')
			const month = (date.getMonth() + 1).toString().padStart(2, '0')
			const year = date.getFullYear().toString().slice(-2)
			return `${day}/${month}/${year}`
		},
		formatItemDates(item) {
			if (!item) return '-'
			const futureHint = this.formatFutureStartHint(item)
			if (this.isMilestone(item)) {
				return futureHint
					? `${this.formatDate(item.startDate)} • ${futureHint}`
					: this.formatDate(item.startDate)
			}
			const start = this.formatDate(item.startDate)
			const end = this.isOngoing(item) ? 'Ongoing' : this.formatDate(item.endDate)
			return futureHint
				? `${start} – ${end} • ${futureHint}`
				: `${start} – ${end}`
		},
		formatItemBadge(item) {
			if (this.isMilestone(item)) return 'Milestone'
			return this.formatWeeks(item)
		},
		parseDateOnly(value) {
			if (!value) return this.toDateOnly(new Date())
			return new Date(`${value}T00:00:00`)
		},
		toDateOnly(date) {
			const d = new Date(date)
			d.setHours(0, 0, 0, 0)
			return d
		},
		getDaysUntilStart(item) {
			if (!item?.startDate) return null
			const today = this.toDateOnly(new Date())
			const start = this.parseDateOnly(item.startDate)
			const diff = Math.floor((start - today) / (1000 * 60 * 60 * 24))
			return diff > 0 ? diff : null
		},
		formatWeekCountFromDays(days) {
			const weeks = days / 7
			const fixed = Math.abs(weeks - Math.round(weeks)) < 1e-9 ? String(Math.round(weeks)) : weeks.toFixed(1)
			return `${fixed} week${weeks === 1 ? '' : 's'}`
		},
		formatFutureStartHint(item) {
			const days = this.getDaysUntilStart(item)
			if (!days) return ''
			return `Starts in ${this.formatWeekCountFromDays(days)}`
		},
		getIsoWeekInfo(date) {
			const d = this.toDateOnly(date)
			const utcDate = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()))
			const day = utcDate.getUTCDay() || 7
			utcDate.setUTCDate(utcDate.getUTCDate() + 4 - day)
			const isoYear = utcDate.getUTCFullYear()
			const yearStart = new Date(Date.UTC(isoYear, 0, 1))
			const isoWeek = Math.ceil(((utcDate - yearStart) / (1000 * 60 * 60 * 24) + 1) / 7)
			return { isoWeek, isoYear }
		},
		getBarStyle(item) {
			const { start } = this.timelineRange
			const itemStart = this.parseDateOnly(item.startDate)
			const today = this.toDateOnly(new Date())
			const rawEnd = String(item?.endDate || '').trim()
			const itemEnd = rawEnd ? this.parseDateOnly(rawEnd) : (today < itemStart ? itemStart : today)
			const offsetDays = Math.floor((itemStart - start) / (1000 * 60 * 60 * 24))
			const durationDays = Math.floor((itemEnd - itemStart) / (1000 * 60 * 60 * 24)) + 1
			const leftPx = offsetDays * this.dayWidth
			const color = item.color || '#3b82f6'
			return {
				left: `${leftPx}px`,
				width: `${Math.max(1, durationDays) * this.dayWidth}px`,
				backgroundColor: color,
				'--bar-color': color,
			}
		},
		getMilestoneStyle(item) {
			const { start } = this.timelineRange
			const itemDate = this.parseDateOnly(item.startDate)
			const offsetDays = Math.floor((itemDate - start) / (1000 * 60 * 60 * 24))
			const leftPx = offsetDays * this.dayWidth
			const color = item.color || '#0f172a'
			return {
				left: `${leftPx}px`,
				'--marker-color': color,
			}
		},
		getDurationDays(item) {
			const start = this.parseDateOnly(item.startDate)
			if (this.isMilestone(item)) return 1
			const today = this.toDateOnly(new Date())
			const rawEnd = String(item?.endDate || '').trim()
			const end = rawEnd ? this.parseDateOnly(rawEnd) : (today < start ? start : today)
			return Math.max(1, Math.floor((end - start) / (1000 * 60 * 60 * 24)) + 1)
		},
		formatWeeks(item) {
			const days = this.getDurationDays(item)
			const weeks = days / 7
			if (weeks < 1) return `${days}d`
			const fixed = Math.abs(weeks - Math.round(weeks)) < 1e-9 ? String(Math.round(weeks)) : weeks.toFixed(1)
			return `${fixed}w`
		},
		formatWeeksLabel(item) {
			const days = this.getDurationDays(item)
			const weeks = days / 7
			const fixed = Math.abs(weeks - Math.round(weeks)) < 1e-9 ? String(Math.round(weeks)) : weeks.toFixed(1)
			return `${fixed} week${weeks === 1 ? '' : 's'} (${days} day${days === 1 ? '' : 's'})`
		},
		barTitle(item) {
			const futureHint = this.formatFutureStartHint(item)
			if (this.isMilestone(item)) {
				return futureHint
					? `${item.label}: ${this.formatDate(item.startDate)} (${futureHint})`
					: `${item.label}: ${this.formatDate(item.startDate)}`
			}
			const endText = this.isOngoing(item) ? 'Ongoing' : this.formatDate(item.endDate)
			const baseTitle = `${item.label}: ${this.formatDate(item.startDate)} - ${endText} (${this.formatWeeksLabel(item)})`
			return futureHint ? `${baseTitle} (${futureHint})` : baseTitle
		},
		openAddModal() {
			this.editingItem = null
			this.form = { label: '', itemType: 'phase', startDate: '', endDate: '', isOngoing: false, color: '#3b82f6' }
			this.showModal = true
		},
		openEditModal(item) {
			if (!this.canEditItem(item)) {
				return
			}
			this.editingItem = item
			const type = String(item?.itemType || 'phase')
			this.form = {
				label: item.label,
				itemType: type === 'milestone' ? 'milestone' : 'phase',
				startDate: item.startDate,
				endDate: item.endDate || '',
				isOngoing: type !== 'milestone' && !String(item?.endDate || '').trim(),
				color: item.color || '#3b82f6',
			}
			this.showModal = true
		},
		closeModal() {
			this.showModal = false
			this.editingItem = null
		},
		async saveItem() {
			if (!this.canSave) return
			if (this.form.itemType === 'phase' && this.form.isOngoing) {
				this.form.endDate = ''
			}
			this.saving = true
			try {
				const baseUrl = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${this.projectId}/timeline`)
				const payload = {
					label: this.form.label,
					itemType: this.form.itemType,
					startDate: this.form.startDate,
					endDate: this.form.itemType === 'milestone' ? this.form.startDate : (this.form.isOngoing ? '' : this.form.endDate),
					color: this.form.color,
				}
				if (this.editingItem) {
					await axios.put(`${baseUrl}/${this.editingItem.id}`, payload)
				} else {
					await axios.post(baseUrl, payload)
				}
				this.closeModal()
				await this.loadItems()
			} catch (error) {
				console.error('Error saving timeline item:', error)
			} finally {
				this.saving = false
			}
		},
		async confirmDelete(item) {
			if (this.isSystemItem(item)) return
			if (!confirm(`Are you sure you want to delete "${item.label}"?`)) return
			try {
				const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${this.projectId}/timeline/${item.id}`)
				await axios.delete(url)
				await this.loadItems()
			} catch (error) {
				console.error('Error deleting timeline item:', error)
			}
		},
		async onDragEnd() {
			try {
				// Ensure v-model update is applied before persisting
				await this.$nextTick()
				const baseUrl = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${this.projectId}/timeline`)
				const visibleIds = this.items.map((item) => item.id)
				const allProjectIds = (this.allItems || []).map((item) => item.id)
				const reorderedIds = [
					...visibleIds,
					...allProjectIds.filter((id) => !visibleIds.includes(id)),
				]
				const response = await axios.put(`${baseUrl}/reorder`, { ids: reorderedIds })
				this.allItems = response.data || []
			} catch (error) {
				console.error('Error updating order:', error)
				await this.loadItems()
			}
		},
		navigatePrev() {
			const monthPx = 30 * this.dayWidth
			this.scrollBy(-monthPx)
		},
		navigateNext() {
			const monthPx = 30 * this.dayWidth
			this.scrollBy(monthPx)
		},
		navigateToday() {
			const el = this.$refs.scrollEl
			if (!el) return
			const containerWidth = el.clientWidth || 400
			const target = Math.max(0, this.todayOffset - containerWidth / 2)
			el.scrollLeft = target
		},
		navigateToPhase(index) {
			if (index < 0 || index >= this.items.length) return
			this.currentPhaseIndex = index
			const item = this.items[index]
			const { start } = this.timelineRange
			const itemStart = this.parseDateOnly(item.startDate)
			const offsetDays = Math.floor((itemStart - start) / (1000 * 60 * 60 * 24))
			const offsetPx = offsetDays * this.dayWidth
			const el = this.$refs.scrollEl
			if (!el) return
			el.scrollLeft = Math.max(0, offsetPx - 50)
		},
		scrollBy(px) {
			const el = this.$refs.scrollEl
			if (!el) return
			el.scrollLeft = Math.max(0, el.scrollLeft + px)
		},
		setZoom(nextDayWidth) {
			const el = this.$refs.scrollEl
			if (!el) {
				this.dayWidth = nextDayWidth
				return
			}
			const oldDayWidth = this.dayWidth
			const centerPx = el.scrollLeft + el.clientWidth / 2
			const centerDays = oldDayWidth > 0 ? centerPx / oldDayWidth : 0
			this.dayWidth = nextDayWidth
			this.$nextTick(() => {
				const target = Math.max(0, centerDays * this.dayWidth - el.clientWidth / 2)
				el.scrollLeft = target
			})
		},
		zoomIn() {
			const levels = [3, 4, 6, 8, 12, 16]
			const idx = levels.findIndex(l => l >= this.dayWidth)
			const next = levels[Math.min(levels.length - 1, idx + 1)]
			this.setZoom(next)
		},
		zoomOut() {
			const levels = [2, 3, 4, 6, 8, 12]
			const idx = levels.findIndex(l => l >= this.dayWidth)
			const next = levels[Math.max(0, idx - 1)]
			this.setZoom(next)
		},
		onPointerDown(e) {
			if (e.button !== undefined && e.button !== 0) return
			const el = this.$refs.scrollEl
			if (!el) return
			this.isDragging = true
			this.dragStartX = e.clientX
			this.dragStartScrollLeft = el.scrollLeft
			try {
				e.currentTarget.setPointerCapture(e.pointerId)
			} catch (err) {
				// ignore
			}
		},
		onPointerMove(e) {
			if (!this.isDragging) return
			const el = this.$refs.scrollEl
			if (!el) return
			e.preventDefault()
			const dx = e.clientX - this.dragStartX
			el.scrollLeft = Math.max(0, this.dragStartScrollLeft - dx)
		},
		onPointerUp() {
			this.isDragging = false
		},
	},
}
</script>

<style scoped>
.timeline-v2 {
	display: flex;
	flex-direction: column;
	gap: 20px;
	padding: 8px 0;
}

.timeline-v2__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 20px;
	flex-wrap: wrap;
}

.timeline-v2__title {
	margin: 0;
	font-size: 22px;
	font-weight: 700;
}

.timeline-v2__subtitle {
	margin: 4px 0 0;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.timeline-v2__controls {
	display: flex;
	align-items: center;
	gap: 12px;
}

.control-group {
	display: flex;
	align-items: center;
	gap: 2px;
	padding: 4px;
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: 14px;
}

.zoom-indicator {
	font-size: 11px;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
	padding: 0 8px;
	min-width: 50px;
	text-align: center;
}

.timeline-v2__container {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 20px;
	overflow: hidden;
	box-shadow: 0 4px 24px rgba(0, 0, 0, 0.05);
}

.timeline-v2__loading,
.timeline-v2__empty {
	padding: 80px 40px;
	text-align: center;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 16px;
	color: var(--color-text-maxcontrast);
}

.timeline-v2__empty h4 {
	margin: 0;
	font-size: 18px;
	color: var(--color-main-text);
}

.timeline-v2__empty p {
	margin: 0 0 8px;
	max-width: 300px;
	line-height: 1.5;
}

.empty-icon {
	color: var(--color-background-darker);
}

/* Gantt V2 Grid Layout */
.gantt-v2 {
	display: grid;
	grid-template-columns: 240px 1fr;
	border-bottom: 1px solid var(--color-border);
}

.gantt-v2--admin {
	grid-template-columns: 240px 1fr 100px;
}

.gantt-v2__header-cell {
	display: flex;
	align-items: center;
	padding: 0 20px;
	background: var(--color-background-dark);
	border-bottom: 1px solid var(--color-border);
	font-size: 12px;
	font-weight: 800;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	color: var(--color-text-maxcontrast);
}

/* Sidebar / Phase List */
.gantt-v2__sidebar {
	border-right: 1px solid var(--color-border);
}

.phase-row {
	display: flex;
	align-items: center;
	min-height: 68px;
	padding: 0 16px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-main-background);
	transition: all 0.2s ease;
}

.phase-row:hover {
	background: var(--color-background-hover);
	padding-left: 20px;
}

.phase-row--system {
	background: var(--color-background-dark);
	opacity: 0.95;
}

.phase-row--system:hover {
	background: var(--color-background-dark);
}

.phase-row--ghost {
	opacity: 0.5;
	background: var(--color-primary-element-light) !important;
}

.drag-handle {
	cursor: grab;
	padding: 8px 4px;
	margin-right: 8px;
	color: var(--color-text-lighter);
	display: flex;
	align-items: center;
	transition: color 0.2s ease;
}

.phase-row:hover .drag-handle {
	color: var(--color-primary-element);
}

.drag-handle--locked {
	cursor: default;
	color: var(--color-text-maxcontrast);
	opacity: 0.6;
}

.drag-handle:active {
	cursor: grabbing;
}

.phase-row__content {
	flex: 1;
	min-width: 0;
}

.phase-row__top {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	gap: 8px;
}

.phase-row__name {
	font-weight: 700;
	font-size: 14px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	color: var(--color-main-text);
}

.phase-row__duration {
	font-size: 10px;
	font-weight: 800;
	background: var(--color-background-darker);
	padding: 2px 6px;
	border-radius: 99px;
	color: var(--color-text-maxcontrast);
}

.phase-row__dates {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
}

/* Timeline / Main Area */
.gantt-v2__main {
	overflow-x: auto;
	overflow-y: hidden;
	cursor: grab;
	background-color: var(--color-main-background);
}

.gantt-v2__main--dragging {
	cursor: grabbing;
	user-select: none;
}

.gantt-v2__timeline {
	position: relative;
	min-height: 100%;
}

.gantt-v2__timeline-header {
	position: sticky;
	top: 0;
	z-index: 20;
	background: var(--color-background-dark);
	border-bottom: 1px solid var(--color-border);
}

.year-row, .month-row, .week-row {
	display: flex;
	border-bottom: 1px solid var(--color-border);
}

.year-label, .month-label, .week-label {
	display: flex;
	align-items: center;
	justify-content: center;
	border-right: 1px solid var(--color-border);
	white-space: nowrap;
	overflow: hidden;
}

.year-label { font-size: 13px; font-weight: 800; height: 32px; background: rgba(0,0,0,0.02); }
.month-label { font-size: 11px; font-weight: 600; height: 26px; }
.week-label { font-size: 9px; font-weight: 700; height: 22px; color: var(--color-text-lighter); }

.gantt-v2__content {
	position: relative;
	padding-bottom: 1px;
}

.gantt-v2__grid-lines {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	display: flex;
	pointer-events: none;
}

.grid-column {
	border-right: 1px solid var(--color-border);
	opacity: 0.3;
	height: 100%;
}

.timeline-row {
	height: 68px;
	display: flex;
	align-items: center;
	border-bottom: 1px solid var(--color-border);
	position: relative;
}

.timeline-bar {
	position: absolute;
	top: 50%;
	height: 32px;
	border-radius: 8px;
	display: flex;
	align-items: center;
	padding: 0 12px;
	box-shadow: 0 2px 6px rgba(0,0,0,0.1);
	border: 1px solid rgba(255, 255, 255, 0.15);
	cursor: pointer;
	transition: filter 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
	z-index: 5;
	transform: translateY(-50%);
}

.timeline-bar:hover {
	filter: brightness(1.1);
	transform: translateY(-50%) scale(1.02);
	box-shadow: 0 4px 12px rgba(0,0,0,0.2);
	z-index: 10;
}

.timeline-bar--readonly {
	cursor: default;
}

.timeline-bar--readonly:hover {
	filter: none;
	transform: translateY(-50%);
	box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

@keyframes progress-stripes {
	from { background-position: 1rem 0; }
	to { background-position: 0 0; }
}

.timeline-bar--ongoing {
	background-image: linear-gradient(
		45deg,
		rgba(255, 255, 255, 0.2) 25%,
		transparent 25%,
		transparent 50%,
		rgba(255, 255, 255, 0.2) 50%,
		rgba(255, 255, 255, 0.2) 75%,
		transparent 75%,
		transparent
	);
	background-size: 1rem 1rem;
	animation: progress-stripes 1s linear infinite;
	border-right: 2px dashed rgba(255, 255, 255, 0.8);
}

.timeline-milestone {
	position: absolute;
	top: 50%;
	width: 16px;
	height: 16px;
	background: var(--marker-color, #0f172a);
	transform: translate(-50%, -50%) rotate(45deg);
	border-radius: 3px;
	box-shadow: 0 2px 6px rgba(0,0,0,0.2);
	border: 2px solid #fff;
	z-index: 8;
	transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.timeline-milestone:hover {
	transform: translate(-50%, -50%) rotate(45deg) scale(1.2);
	box-shadow: 0 4px 12px rgba(0,0,0,0.3);
	z-index: 10;
}

.timeline-bar__label {
	font-size: 12px;
	font-weight: 700;
	color: #fff;
	text-shadow: 0 1px 2px rgba(0,0,0,0.2);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.today-marker {
	position: absolute;
	top: 0;
	bottom: 0;
	z-index: 15;
	pointer-events: none;
}

.today-line {
	width: 2px;
	height: 100%;
	background: #ef4444;
	box-shadow: 0 0 8px rgba(239, 68, 68, 0.4);
	position: relative;
}

.today-line::before {
	content: '';
	position: absolute;
	top: 0;
	left: 50%;
	transform: translateX(-50%);
	width: 8px;
	height: 8px;
	background: #ef4444;
	border-radius: 50%;
	box-shadow: 0 0 8px rgba(239, 68, 68, 0.6);
}

.today-badge {
	position: absolute;
	top: 12px;
	left: 50%;
	transform: translateX(-50%);
	background: #ef4444;
	color: #fff;
	font-size: 10px;
	font-weight: 800;
	text-transform: uppercase;
	padding: 3px 8px;
	border-radius: 12px;
	white-space: nowrap;
	box-shadow: 0 2px 6px rgba(239, 68, 68, 0.4);
}

/* Actions Column */
.gantt-v2__actions {
	border-left: 1px solid var(--color-border);
	background: rgba(0,0,0,0.01);
}

.action-row {
	height: 64px;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 4px;
	border-bottom: 1px solid var(--color-border);
}

/* Footer / Phase Jumper */
.timeline-v2__footer {
	padding: 16px 24px;
	background: var(--color-background-dark);
}

.phase-jumper {
	display: flex;
	align-items: center;
	gap: 16px;
}

.jumper-label {
	font-size: 13px;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
}

.jumper-chips {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.phase-chip {
	padding: 6px 14px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	transition: all 0.2s ease;
}

.phase-chip:hover {
	border-color: var(--phase-color);
	background: var(--color-background-hover);
}

.phase-chip.active {
	background: var(--phase-color);
	color: #fff;
	border-color: var(--phase-color);
	box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

/* Phase Form / Modal */
.phase-form {
	padding: 32px;
}

.phase-form__header {
	margin-bottom: 24px;
}

.phase-form__header h3 {
	margin: 0;
	font-size: 22px;
}

.phase-form__header p {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
}

.phase-form__content {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.form-select {
	width: 100%;
	height: 44px;
	border-radius: var(--border-radius-large);
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	padding: 0 12px;
	font-size: 14px;
}

.form-field--checkbox {
	display: flex;
	align-items: flex-end;
}

.form-checkbox {
	display: inline-flex;
	align-items: center;
	gap: 10px;
	height: 44px;
	padding: 0 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.form-checkbox input {
	width: 16px;
	height: 16px;
}

.form-label {
	display: block;
	font-size: 13px;
	font-weight: 700;
	margin-bottom: 8px;
	color: var(--color-text-maxcontrast);
}

.form-row {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.color-grid {
	display: flex;
	gap: 10px;
	flex-wrap: wrap;
}

.color-swatch {
	width: 36px;
	height: 36px;
	border-radius: 10px;
	border: 2px solid transparent;
	cursor: pointer;
	transition: transform 0.2s ease;
}

.color-swatch:hover {
	transform: scale(1.1);
}

.color-swatch.active {
	border-color: var(--color-main-text);
	box-shadow: 0 0 0 2px var(--color-main-background), 0 0 0 4px var(--color-primary-element);
}

.phase-form__footer {
	margin-top: 32px;
	display: flex;
	justify-content: flex-end;
	gap: 12px;
}

@media (max-width: 900px) {
	.gantt-v2, .gantt-v2--admin {
		grid-template-columns: 1fr;
	}

	.gantt-v2__sidebar { border-right: none; }
	.gantt-v2__actions { border-left: none; }
}
</style>
