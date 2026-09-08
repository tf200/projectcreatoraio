<template>
	<div class="timeline-v2">
		<!-- KPI Metric Header Bar (Step 1) -->
		<TimelineKpiBar
			v-if="kpisData"
			:kpis="kpisData"
			:can-edit="isAdmin"
			:saving="savingDesiredDate"
			@save-desired-date="onSaveDesiredDate"
			@save-prep-weeks="onSavePrepWeeks" />

		<!-- What-If Simulation Banner (Step 3) -->
		<TimelineSimulationBanner
			v-if="isSimulationMode"
			:scenario="simulationScenario"
			:active-strategy="simulationScenario?.strategy || 'accelerate'"
			:recovery-options="activeImpactAnalysis?.recoveryOptions"
			:applying="applyingScenario"
			@switch-strategy="switchSimulationStrategy"
			@apply="applyWhatIfScenario"
			@cancel="exitWhatIf" />

		<header class="timeline-v2__header">
			<div class="timeline-v2__title-group">
				<div class="title-with-badge">
					<h3 class="timeline-v2__title">
						Project Timeline
					</h3>
					<span v-if="hasActiveDelays" class="delay-warning-badge" title="Schedule slippage detected">
						<AlertCircle :size="14" />
						Delays Detected
					</span>
				</div>
				<p class="timeline-v2__subtitle">
					Plan and track phases, deck card dependencies, and milestones
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

				<!-- Schedule Impact Advisor Button (Mockup) -->
				<NcButton
					type="secondary"
					class="advisor-btn"
					:class="{ 'advisor-btn--alert': hasActiveDelays }"
					title="Schedule Impact & Recovery"
					@click="openImpactDrawer()">
					<template #icon>
						<AlertCircleOutline :size="18" />
					</template>
					Impact Advisor
				</NcButton>

				<!-- What-If Mode Toggle Button (Mockup) -->
				<NcButton
					v-if="!isSimulationMode"
					type="tertiary"
					class="whatif-btn"
					title="Test schedule scenarios without changing official plan"
					@click="startWhatIf()">
					<template #icon>
						<FlaskOutline :size="18" />
					</template>
					What-If Mode
				</NcButton>

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

			<div v-else-if="!hasTimelineContent" class="timeline-v2__empty">
				<ChartGantt :size="48" class="empty-icon" />
				<h4>No timeline items defined</h4>
				<p>Start by adding phases and milestones to visualize the timeline.</p>
				<NcButton v-if="isAdmin" type="primary" @click="openAddModal">
					Create first item
				</NcButton>
			</div>

			<div v-else class="gantt-v2" :class="{ 'gantt-v2--admin': isAdmin, 'gantt-v2--simulating': isSimulationMode }">
				<!-- Column 1: Sidebar (WBS Hierarchy) -->
				<div class="gantt-v2__sidebar">
					<div class="gantt-v2__header-cell" :style="{ height: timelineHeaderHeight + 'px' }">
						Timeline details
					</div>

					<!-- Row 1: System Planning (Calculated) -->
					<div v-if="systemPlanningData" class="phase-row phase-row--system-planning" title="System Planning (calculated)">
						<div class="sp-sidebar-badge">1</div>
						<div class="sp-sidebar-icon">
							<Cog :size="16" />
						</div>
						<div class="phase-row__content">
							<div class="phase-row__top">
								<span class="phase-row__name">System Planning</span>
								<span class="phase-row__duration">{{ systemPlanningDurationBadge }}</span>
							</div>
							<div class="phase-row__dates">{{ systemPlanningDatesText }}</div>
						</div>
					</div>

					<!-- Visible Hierarchical Rows -->
					<div
						v-for="row in visibleRows"
						:key="'sidebar-' + row.id"
						class="phase-row"
						:class="{
							'phase-row--phase-header': row.type === 'phase',
							'phase-row--task-child': row.type === 'task',
							'phase-row--custom-item': row.type === 'item',
						}"
						:style="{ height: row.height + 'px', borderLeftColor: row.type === 'phase' ? row.phase.color : undefined }"
						@click="row.type === 'phase' ? togglePhase(row.phase.category || row.phase.id) : null">
						<!-- Phase Header Row -->
						<template v-if="row.type === 'phase'">
							<button
								class="phase-toggle-btn"
								:aria-label="row.isExpanded ? 'Collapse phase' : 'Expand phase'"
								@click.stop="togglePhase(row.phase.category || row.phase.id)">
								<ChevronDown v-if="row.isExpanded" :size="16" />
								<ChevronRight v-else :size="16" />
							</button>
							<div class="phase-order-badge" :style="{ backgroundColor: row.phase.color }">
								{{ row.phase.order }}
							</div>
							<div class="phase-row__content">
								<div class="phase-row__top">
									<span class="phase-row__name" :title="row.phase.name">{{ row.phase.name }}</span>
									<span class="phase-row__duration">{{ formatPhaseDuration(row.phase) }}</span>
								</div>
								<div class="phase-row__dates">
									{{ formatDate(row.phase.startDate) }} – {{ formatDate(row.phase.endDate) }}
								</div>
							</div>
						</template>

						<!-- Task Child Row -->
						<template v-else-if="row.type === 'task'">
							<div class="task-tree-indicator">
								<div class="tree-line-v" />
								<div class="tree-line-h" />
							</div>
							<div v-if="row.task.deckCardId" class="task-deck-icon" title="Deck Card">
								<CardsVariant :size="14" />
							</div>
							<div class="phase-row__content">
								<div class="phase-row__top">
									<span
										class="task-name"
										:class="{
											'task-name--done': row.task.isDone,
											'task-name--delayed': row.task.isDelayed,
										}"
										:title="row.task.label">
										{{ row.task.label }}
									</span>
									<span v-if="row.task.delayDays > 0" class="task-delay-tag">
										+{{ formatDelayBadge(row.task.delayDays) }}
									</span>
									<span v-else class="phase-row__duration">{{ row.task.durationDays }}d</span>
								</div>
								<div class="phase-row__dates">
									{{ formatDate(row.task.startDate) }} – {{ formatDate(row.task.endDate) }}
								</div>
							</div>
						</template>

						<!-- Custom User Item -->
						<template v-else>
							<div v-if="isAdmin" class="drag-handle" title="Custom timeline item">
								<DragVariant :size="16" />
							</div>
							<div class="phase-row__content">
								<div class="phase-row__top">
									<span class="phase-row__name">{{ row.item.label }}</span>
									<span class="phase-row__duration">{{ formatItemBadge(row.item) }}</span>
								</div>
								<div class="phase-row__dates">{{ formatItemDates(row.item) }}</div>
							</div>
						</template>
					</div>
				</div>

				<!-- Column 2: Timeline Canvas (Bars, Connectors & Grid) -->
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

							<!-- SVG Dependency Connectors Layer -->
							<svg
								class="timeline-dependencies-svg"
								:style="{ width: totalTimelineWidth + 'px', height: canvasRowsHeight + 'px' }">
								<defs>
									<marker
										id="dep-arrow"
										markerWidth="6"
										markerHeight="6"
										refX="5"
										refY="3"
										orient="auto">
										<path d="M 0 0 L 6 3 L 0 6 z" fill="#94a3b8" />
									</marker>
									<marker
										id="dep-arrow-active"
										markerWidth="6"
										markerHeight="6"
										refX="5"
										refY="3"
										orient="auto">
										<path d="M 0 0 L 6 3 L 0 6 z" fill="#3b82f6" />
									</marker>
									<marker
										id="dep-arrow-delayed"
										markerWidth="6"
										markerHeight="6"
										refX="5"
										refY="3"
										orient="auto">
										<path d="M 0 0 L 6 3 L 0 6 z" fill="#ef4444" />
									</marker>
								</defs>
								<path
									v-for="dep in visibleDependencyPaths"
									:key="dep.key"
									:d="dep.d"
									class="dependency-line"
									:class="{
										'dependency-line--active': dep.isActive,
										'dependency-line--delayed': dep.isDelayed,
									}"
									:marker-end="dep.isDelayed ? 'url(#dep-arrow-delayed)' : (dep.isActive ? 'url(#dep-arrow-active)' : 'url(#dep-arrow)')" />
							</svg>

							<!-- Today Marker -->
							<div class="today-marker" :style="{ left: todayOffset + 'px' }">
								<div class="today-line" />
								<div class="today-badge">
									Today
								</div>
							</div>

							<!-- Full-Height Guide Lines for System Planning -->
							<template v-if="systemPlanningData">
								<!-- Minimum Start Date Guide Line -->
								<div
									v-if="minStartOffset !== null"
									class="timeline-guide-marker timeline-guide-marker--min-start"
									:style="{ left: minStartOffset + 'px' }"
									:title="`Minimum start: ${formatDate(systemPlanningData.minimumStart?.date)}`">
									<div class="timeline-guide-line timeline-guide-line--min-start" />
								</div>

								<!-- Desired Start Date Guide Line -->
								<div
									v-if="desiredStartOffset !== null"
									class="timeline-guide-marker timeline-guide-marker--desired-start"
									:style="{ left: desiredStartOffset + 'px' }"
									:title="`Desired start: ${formatDate(systemPlanningData.desiredStart?.date)}`">
									<div class="timeline-guide-line timeline-guide-line--desired-start" />
								</div>
							</template>

							<!-- Row 1: System Planning Row -->
							<SystemPlanningRow
								v-if="systemPlanningData"
								:system-planning="systemPlanningData"
								:timeline-start="timelineRange.start"
								:day-width="dayWidth" />

							<!-- Canvas Visible Rows -->
							<div
								v-for="row in visibleRows"
								:key="'canvas-' + row.id"
								class="timeline-row"
								:class="{
									'timeline-row--phase-header': row.type === 'phase',
									'timeline-row--task-child': row.type === 'task',
									'timeline-row--custom-item': row.type === 'item',
								}"
								:style="{ height: row.height + 'px' }">
								<!-- Phase Row Canvas: Summary Bar & Milestone Diamond -->
								<template v-if="row.type === 'phase'">
									<div
										class="phase-summary-bar"
										:style="getPhaseBarStyle(row.phase)"
										:title="`${row.phase.name}: ${formatDate(row.phase.startDate)} – ${formatDate(row.phase.endDate)}`">
										<span v-if="getPhaseDurationDays(row.phase) * dayWidth > 90" class="phase-summary-bar__label">
											{{ row.phase.name }}
										</span>
									</div>
									<div
										v-if="row.phase.milestone"
										class="phase-milestone-marker"
										:style="getPhaseMilestoneStyle(row.phase)"
										:title="`Milestone: ${row.phase.milestone.label} (${formatDate(row.phase.milestone.date)})`">
										<div class="phase-milestone-diamond" :style="{ backgroundColor: row.phase.color }" />
										<span class="phase-milestone-label">{{ row.phase.milestone.label }}</span>
									</div>
								</template>

								<!-- Task Child Row Canvas: Sequential Task Bar -->
								<template v-else-if="row.type === 'task'">
									<!-- Ghost Bar for Baseline if task has slipped -->
									<div
										v-if="hasGhostBar(row.task)"
										class="timeline-bar timeline-bar--ghost"
										:style="getTaskGhostBarStyle(row.task)"
										title="Original baseline schedule" />

									<div
										class="timeline-bar timeline-bar--task"
										:class="{
											'timeline-bar--task-done': row.task.isDone,
											'timeline-bar--task-at-risk': row.task.status === 'behind_at_risk' || row.task.isDelayed,
											'timeline-bar--simulated': isSimulationMode,
										}"
										:style="getTaskBarStyle(row.task, row.phase)"
										:title="getTaskBarTitle(row.task)"
										@click="onTaskClick(row.task)">
										<Check v-if="row.task.isDone" :size="14" class="task-done-icon" />
										<span v-if="row.task.durationDays * dayWidth > 40" class="timeline-bar__label">
											{{ row.task.label }}
										</span>
										<span v-if="row.task.delayDays > 0" class="task-delay-badge" title="Schedule slippage">
											+{{ formatDelayBadge(row.task.delayDays) }}
										</span>
									</div>
								</template>

								<!-- Custom User Item Canvas -->
								<template v-else>
									<div
										v-if="isMilestone(row.item)"
										class="timeline-milestone"
										:style="getMilestoneStyle(row.item)"
										:title="`${row.item.label}: ${formatDate(row.item.startDate)}`" />
									<div
										v-else
										class="timeline-bar"
										:class="{ 'timeline-bar--ongoing': isOngoing(row.item), 'timeline-bar--readonly': !canEditItem(row.item) }"
										:style="getBarStyle(row.item)"
										:title="barTitle(row.item)"
										@click="isAdmin && canEditItem(row.item) ? openEditModal(row.item) : null">
										<span v-if="getDurationDays(row.item) * dayWidth > 60" class="timeline-bar__label">
											{{ row.item.label }}
										</span>
									</div>
								</template>
							</div>
						</div>
					</div>
				</div>

				<!-- Column 3: STATUS Column (Right-Hand Traffic Light) -->
				<div class="gantt-v2__status">
					<div class="gantt-v2__header-cell gantt-v2__header-cell--center" :style="{ height: timelineHeaderHeight + 'px' }">
						Status
					</div>

					<!-- System Planning Status Row -->
					<div v-if="systemPlanningData" class="status-row status-row--system-planning">
						<span class="status-pill" :class="getStatusClass(systemPlanningStatus)">
							<span class="status-dot" />
							<span class="status-text">{{ systemPlanningStatusLabel }}</span>
						</span>
					</div>

					<!-- Visible Rows Status -->
					<div
						v-for="row in visibleRows"
						:key="'status-' + row.id"
						class="status-row"
						:class="{
							'status-row--phase-header': row.type === 'phase',
							'status-row--task-child': row.type === 'task',
							'status-row--custom-item': row.type === 'item',
						}"
						:style="{ height: row.height + 'px' }">
						<span
							v-if="row.type === 'phase'"
							class="status-pill status-pill--phase"
							:class="getStatusClass(row.phase.status)">
							<span class="status-dot" />
							<span class="status-text">{{ getStatusLabel(row.phase.status) }}</span>
						</span>
						<span
							v-else-if="row.type === 'task'"
							class="status-pill status-pill--task"
							:class="getStatusClass(row.task.status, row.task.isDone)">
							<span class="status-dot" />
							<span class="status-text">{{ getTaskStatusLabel(row.task) }}</span>
						</span>
						<span
							v-else
							class="status-pill status-pill--item"
							:class="getStatusClass(row.item.status || 'on_track')">
							<span class="status-dot" />
							<span class="status-text">{{ getStatusLabel(row.item.status || 'on_track') }}</span>
						</span>
					</div>
				</div>

				<!-- Column 4: Actions Column (Admin only) -->
				<div v-if="isAdmin" class="gantt-v2__actions">
					<div class="gantt-v2__header-cell" :style="{ height: timelineHeaderHeight + 'px' }">
						Actions
					</div>
					<!-- System Planning Action Placeholder -->
					<div v-if="systemPlanningData" class="action-row action-row--system-planning" title="System calculated planning row">
						<span class="action-row__locked-hint">
							<Lock :size="14" />
						</span>
					</div>
					<div
						v-for="row in visibleRows"
						:key="'actions-' + row.id"
						class="action-row"
						:class="{
							'action-row--phase-header': row.type === 'phase',
							'action-row--task-child': row.type === 'task',
							'action-row--custom-item': row.type === 'item',
						}"
						:style="{ height: row.height + 'px' }">
						<template v-if="row.type === 'phase'">
							<span class="action-row__locked-hint" title="System defined lifecycle phase">
								<Lock :size="14" />
							</span>
						</template>
						<template v-else-if="row.type === 'task'">
							<span v-if="row.task.deckCardId" class="action-row__deck-hint" title="Nextcloud Deck Card">
								<CardsVariant :size="14" />
							</span>
							<span v-else class="action-row__locked-hint" title="Phase Task">
								<Lock :size="14" />
							</span>
						</template>
						<template v-else>
							<NcButton
								v-if="canEditItem(row.item)"
								type="tertiary"
								title="Edit item"
								@click="openEditModal(row.item)">
								<template #icon>
									<Pencil :size="16" />
								</template>
							</NcButton>
							<NcButton
								v-if="!isSystemItem(row.item)"
								type="error"
								title="Delete item"
								@click="confirmDelete(row.item)">
								<template #icon>
									<Delete :size="16" />
								</template>
							</NcButton>
						</template>
					</div>
				</div>
			</div>

			<!-- Footer: Phase Jumper -->
			<footer v-if="hasTimelineContent" class="timeline-v2__footer">
				<div class="phase-jumper">
					<span class="jumper-label">Focus on:</span>
					<div class="jumper-chips">
						<button
							v-if="systemPlanningData"
							class="phase-chip"
							:style="{ '--phase-color': '#3b82f6' }"
							@click="navigateToDate(systemPlanningData.deckTasks?.startDate || systemPlanningData.requestDate)">
							System Planning
						</button>
						<button
							v-for="phase in effectivePhases"
							:key="'chip-' + phase.id"
							class="phase-chip"
							:style="{ '--phase-color': phase.color }"
							@click="navigateToDate(phase.startDate)">
							{{ phase.name }}
						</button>
					</div>
				</div>
			</footer>
		</div>

		<!-- Schedule Impact & Recovery Drawer (Step 3) -->
		<TimelineImpactDrawer
			v-if="showImpactDrawer"
			:analysis="activeImpactAnalysis"
			@select-option="onOptionSelected"
			@open-whatif="startWhatIf"
			@close="closeImpactDrawer" />

		<!-- Modal for Add/Edit Custom Item -->
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

import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import CardsVariant from 'vue-material-design-icons/CardsVariant.vue'
import ChartGantt from 'vue-material-design-icons/ChartGantt.vue'
import Check from 'vue-material-design-icons/Check.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import DragVariant from 'vue-material-design-icons/DragVariant.vue'
import FlaskOutline from 'vue-material-design-icons/FlaskOutline.vue'
import Lock from 'vue-material-design-icons/Lock.vue'
import MagnifyMinusOutline from 'vue-material-design-icons/MagnifyMinusOutline.vue'
import MagnifyPlusOutline from 'vue-material-design-icons/MagnifyPlusOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

import TimelineKpiBar from './header/TimelineKpiBar.vue'
import TimelineSimulationBanner from './header/TimelineSimulationBanner.vue'
import SystemPlanningRow from './rows/SystemPlanningRow.vue'
import TimelineImpactDrawer from './drawer/TimelineImpactDrawer.vue'

export default {
	name: 'GanttChart',
	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcTextField,
		AlertCircle,
		AlertCircleOutline,
		CardsVariant,
		ChartGantt,
		Check,
		ChevronDown,
		ChevronLeft,
		ChevronRight,
		Cog,
		Delete,
		DragVariant,
		FlaskOutline,
		Lock,
		MagnifyMinusOutline,
		MagnifyPlusOutline,
		Pencil,
		Plus,
		TimelineKpiBar,
		TimelineSimulationBanner,
		SystemPlanningRow,
		TimelineImpactDrawer,
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
			loading: true,
			saving: false,
			allItems: [],
			timelineSummary: null,
			savingDesiredDate: false,
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
			isDragging: false,
			dragStartX: 0,
			dragStartScrollLeft: 0,
			expandedPhases: {
				initiation: true,
				preparation: true,
				execution: true,
				handover: true,
			},
			// Step 3 What-If Simulation & Impact State
			isSimulationMode: false,
			simulationScenario: null,
			simulationApplication: null,
			simulatedPhases: null,
			applyingScenario: false,
			showImpactDrawer: false,
			activeImpactAnalysis: null,
		}
	},
	computed: {
		systemPlanningData() {
			return this.timelineSummary?.systemPlanning || null
		},
		kpisData() {
			if (!this.timelineSummary) {
				return null
			}
			const kpis = this.timelineSummary.kpis || {}
			const processCompleted = this.timelineSummary.processCompleted || kpis.processCompleted || {
				status: 'incomplete',
				date: null,
				doneCount: 0,
				totalRequired: 0,
				missingTitles: [],
			}
			const coordinationPendingPeriod = this.timelineSummary.coordinationPendingPeriod || kpis.coordinationPendingPeriod || null
			return {
				...kpis,
				processCompleted,
				coordinationPendingPeriod,
			}
		},
		phases() {
			return this.timelineSummary?.phases || []
		},
		effectivePhases() {
			if (this.isSimulationMode && this.simulatedPhases && this.simulatedPhases.length > 0) {
				return this.simulatedPhases
			}
			return this.phases
		},
		hasActiveDelays() {
			return !!(this.timelineSummary?.delayAnalysis?.hasActiveDelays)
		},
		activeDelayedTasks() {
			return this.timelineSummary?.delayAnalysis?.delayedTasks || []
		},
		hasTimelineContent() {
			return (this.effectivePhases && this.effectivePhases.length > 0) || this.items.length > 0 || !!this.systemPlanningData
		},
		items() {
			return (this.allItems || [])
				.filter((item) => {
					if (item.itemType === 'schedule_override' || String(item.systemKey || '').startsWith('schedule_override:')) {
						return false
					}
					if (this.systemPlanningData && this.isLegacySystemItem(item)) {
						return false
					}
					return item.systemKey !== 'process_completed'
				})
				.slice()
				.sort((a, b) => (Number(a.orderIndex) || 0) - (Number(b.orderIndex) || 0))
		},
		nonPhaseItems() {
			const phaseDeckCardIds = new Set()
			for (const phase of (this.effectivePhases || [])) {
				for (const task of (phase.tasks || [])) {
					if (task.deckCardId) {
						phaseDeckCardIds.add(Number(task.deckCardId))
					}
				}
			}
			return this.items.filter((item) => {
				if (item.deckCardId && phaseDeckCardIds.has(Number(item.deckCardId))) {
					return false
				}
				return true
			})
		},
		visibleRows() {
			const rows = []
			if (this.effectivePhases && this.effectivePhases.length > 0) {
				for (const phase of this.effectivePhases) {
					const phaseKey = phase.category || String(phase.id)
					const isExpanded = this.isPhaseExpanded(phaseKey)
					rows.push({
						type: 'phase',
						id: `phase-${phase.id}`,
						phase,
						isExpanded,
						height: 56,
					})
					if (isExpanded && phase.tasks && phase.tasks.length > 0) {
						for (const task of phase.tasks) {
							rows.push({
								type: 'task',
								id: `task-${task.id}`,
								phase,
								task,
								height: 44,
							})
						}
					}
				}
			}
			// Custom user items (if any exist outside of phase deck cards)
			for (const item of this.nonPhaseItems) {
				rows.push({
					type: 'item',
					id: `item-${item.id}`,
					item,
					height: 52,
				})
			}
			return rows
		},
		canvasRowsHeight() {
			let total = this.systemPlanningData ? 76 : 0
			for (const row of this.visibleRows) {
				total += row.height
			}
			return Math.max(200, total)
		},
		visibleDependencyPaths() {
			const deps = this.timelineSummary?.dependencies
			if (!deps || deps.length === 0) {
				return []
			}
			const { start } = this.timelineRange
			if (!start) return []

			const taskMap = {}
			let currentY = this.systemPlanningData ? 76 : 0

			for (const row of this.visibleRows) {
				if (row.type === 'task' && row.task) {
					const task = row.task
					const taskStart = this.parseDateOnly(task.startDate)
					const taskEnd = this.parseDateOnly(task.endDate)
					const offsetDays = Math.floor((taskStart - start) / (1000 * 60 * 60 * 24))
					const durationDays = Math.max(1, Math.floor((taskEnd - taskStart) / (1000 * 60 * 60 * 24)) + 1)
					const xStart = offsetDays * this.dayWidth
					const xEnd = xStart + durationDays * this.dayWidth
					const yMid = currentY + (row.height / 2)

					taskMap[String(task.id)] = {
						xStart,
						xEnd,
						yMid,
						task,
					}
					if (task.deckCardId) {
						taskMap[String(task.deckCardId)] = taskMap[String(task.id)]
					}
				}
				currentY += row.height
			}

			const paths = []
			for (const dep of deps) {
				const pred = taskMap[String(dep.predecessorId)]
				const succ = taskMap[String(dep.successorId)]
				if (!pred || !succ) {
					continue
				}

				const x1 = pred.xEnd
				const y1 = pred.yMid
				const x2 = succ.xStart
				const y2 = succ.yMid

				let d = ''
				if (x2 >= x1 + 10) {
					const midX = x1 + Math.max(6, Math.min(16, (x2 - x1) / 2))
					d = `M ${x1} ${y1} L ${midX} ${y1} L ${midX} ${y2} L ${x2} ${y2}`
				} else {
					const exitX = x1 + 10
					const entryX = Math.max(0, x2 - 10)
					const midY = (y1 + y2) / 2
					d = `M ${x1} ${y1} L ${exitX} ${y1} L ${exitX} ${midY} L ${entryX} ${midY} L ${entryX} ${y2} L ${x2} ${y2}`
				}

				paths.push({
					key: `dep-${dep.predecessorId}-${dep.successorId}`,
					d,
					isActive: !pred.task.isDone,
					isDelayed: !!pred.task.isDelayed,
				})
			}
			return paths
		},
		systemPlanningStatus() {
			return this.systemPlanningData?.float?.status || 'on_track'
		},
		systemPlanningStatusLabel() {
			return this.getStatusLabel(this.systemPlanningStatus)
		},
		systemPlanningDurationBadge() {
			const d = this.systemPlanningData
			if (!d) return ''
			const totalWeeks = (Number(d.deckTasks?.weeks) || 0) + (Number(d.preparation?.weeks) || 0)
			return `${totalWeeks}w`
		},
		systemPlanningDatesText() {
			const d = this.systemPlanningData
			if (!d) return 'Automatically calculated'
			const start = this.formatDate(d.deckTasks?.startDate || d.requestDate)
			const end = this.formatDate(d.desiredStart?.date || d.minimumStart?.date)
			return `${start} – ${end}`
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

			if (this.systemPlanningData) {
				const planningDates = [
					this.systemPlanningData.requestDate,
					this.systemPlanningData.deckTasks?.startDate,
					this.systemPlanningData.deckTasks?.endDate,
					this.systemPlanningData.minimumStart?.date,
					this.systemPlanningData.desiredStart?.date,
				].filter(Boolean)
				for (const dateStr of planningDates) {
					const d = this.parseDateOnly(dateStr)
					if (!minDate || d < minDate) minDate = d
					if (!maxDate || d > maxDate) maxDate = d
				}
			}

			if (this.effectivePhases && this.effectivePhases.length > 0) {
				for (const phase of this.effectivePhases) {
					if (phase.startDate) {
						const ps = this.parseDateOnly(phase.startDate)
						if (!minDate || ps < minDate) minDate = ps
					}
					if (phase.endDate) {
						const pe = this.parseDateOnly(phase.endDate)
						if (!maxDate || pe > maxDate) maxDate = pe
					}
					if (phase.milestone?.date) {
						const md = this.parseDateOnly(phase.milestone.date)
						if (!maxDate || md > maxDate) maxDate = md
					}
					if (phase.tasks && phase.tasks.length > 0) {
						for (const t of phase.tasks) {
							if (t.startDate) {
								const ts = this.parseDateOnly(t.startDate)
								if (!minDate || ts < minDate) minDate = ts
							}
							if (t.endDate) {
								const te = this.parseDateOnly(t.endDate)
								if (!maxDate || te > maxDate) maxDate = te
							}
						}
					}
				}
			}

			if (!minDate || !maxDate) {
				const now = new Date()
				return { start: new Date(now.getFullYear(), now.getMonth(), 1), end: new Date(now.getFullYear(), now.getMonth() + 6, 0) }
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
		minStartOffset() {
			const dateStr = this.systemPlanningData?.minimumStart?.date
			if (!dateStr) return null
			const { start } = this.timelineRange
			const d = this.parseDateOnly(dateStr)
			const days = Math.floor((d - start) / (1000 * 60 * 60 * 24))
			return days * this.dayWidth
		},
		desiredStartOffset() {
			const dateStr = this.systemPlanningData?.desiredStart?.date
			if (!dateStr) return null
			const { start } = this.timelineRange
			const d = this.parseDateOnly(dateStr)
			const days = Math.floor((d - start) / (1000 * 60 * 60 * 24))
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
		isPhaseExpanded(phaseKey) {
			if (this.expandedPhases[phaseKey] !== undefined) {
				return !!this.expandedPhases[phaseKey]
			}
			return true
		},
		togglePhase(phaseKey) {
			const current = this.isPhaseExpanded(phaseKey)
			this.expandedPhases = {
				...this.expandedPhases,
				[phaseKey]: !current,
			}
		},
		hasGhostBar(task) {
			if (!task?.plannedEndDate || !task?.endDate) return false
			return task.plannedEndDate !== task.endDate
		},
		getTaskGhostBarStyle(task) {
			const { start } = this.timelineRange
			const tStart = this.parseDateOnly(task.startDate)
			const tPlannedEnd = this.parseDateOnly(task.plannedEndDate)
			const offsetDays = Math.floor((tStart - start) / (1000 * 60 * 60 * 24))
			const durationDays = Math.max(1, Math.floor((tPlannedEnd - tStart) / (1000 * 60 * 60 * 24)) + 1)
			const leftPx = offsetDays * this.dayWidth
			const widthPx = durationDays * this.dayWidth
			return {
				left: `${leftPx}px`,
				width: `${widthPx}px`,
			}
		},
		formatDelayBadge(days) {
			const w = Math.round(days / 7)
			return w >= 1 ? `${w}w` : `${days}d`
		},
		async openImpactDrawer(task = null) {
			const targetTaskId = task ? task.id : (this.activeDelayedTasks[0]?.id || 'Permits')
			const delayDays = task ? (task.delayDays || 28) : 28
			try {
				const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${this.projectId}/timeline/impact`)
				const res = await axios.post(url, {
					taskId: targetTaskId,
					delayDays,
				})
				this.activeImpactAnalysis = res.data
			} catch (e) {
				console.error('Error fetching impact analysis:', e)
				this.activeImpactAnalysis = this.timelineSummary?.delayAnalysis?.defaultAnalysis || null
			}
			this.showImpactDrawer = true
		},
		closeImpactDrawer() {
			this.showImpactDrawer = false
		},
		onOptionSelected(option) {
			// Triggered when an option is highlighted in drawer
		},
		async startWhatIf(option = null) {
			this.showImpactDrawer = false
			this.isSimulationMode = true
			try {
				const targetTaskId = this.activeImpactAnalysis?.task?.id || 'Permits'
				const delayDays = this.activeImpactAnalysis?.task?.delayDays || 28
				const strategy = option?.id || 'accelerate'
				const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${this.projectId}/timeline/simulate`)
				const res = await axios.post(url, {
					rootTaskId: targetTaskId,
					delayDays,
					strategy,
					accelerateDays: option?.shortenDays || 14,
				})
				this.simulationScenario = res.data.scenario
				this.simulationApplication = res.data.application || null
				this.simulatedPhases = res.data.simulatedPhases
			} catch (e) {
				console.error('Error starting what-if simulation:', e)
			}
		},
		exitWhatIf() {
			this.isSimulationMode = false
			this.simulationScenario = null
			this.simulationApplication = null
			this.simulatedPhases = null
		},
		async switchSimulationStrategy(strategyId) {
			const targetTaskId = this.simulationApplication?.rootTaskId || this.activeImpactAnalysis?.task?.id || 'Permits'
			const delayDays = this.simulationApplication?.delayDays || this.activeImpactAnalysis?.task?.delayDays || 28
			const opt = (this.activeImpactAnalysis?.recoveryOptions || []).find(o => o.id === strategyId)
			try {
				const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${this.projectId}/timeline/simulate`)
				const res = await axios.post(url, {
					rootTaskId: targetTaskId,
					delayDays,
					strategy: strategyId,
					accelerateDays: opt?.shortenDays || 14,
				})
				this.simulationScenario = res.data.scenario
				this.simulationApplication = res.data.application || null
				this.simulatedPhases = res.data.simulatedPhases
			} catch (e) {
				console.error('Error switching what-if strategy:', e)
			}
		},
		async applyWhatIfScenario() {
			this.applyingScenario = true
			try {
				const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${this.projectId}/timeline/apply-recovery`)
				const strategy = this.simulationScenario?.strategy || 'accelerate'
				await axios.post(url, {
					strategy,
					rootTaskId: this.simulationApplication?.rootTaskId,
					delayDays: this.simulationApplication?.delayDays,
					accelerateDays: this.simulationApplication?.accelerateDays || 14,
				})
				this.exitWhatIf()
				await this.loadItems()
			} catch (e) {
				console.error('Error applying recovery strategy:', e)
			} finally {
				this.applyingScenario = false
			}
		},
		onTaskClick(task) {
			if (task.isDelayed || this.isSimulationMode || !task.deckCardId) {
				this.openImpactDrawer(task)
			}
		},
		getStatusLabel(status) {
			switch (status) {
			case 'behind_at_risk':
				return 'At risk'
			case 'attention_needed':
				return 'Attention'
			case 'completed':
				return 'Done'
			case 'not_started':
				return 'Not started'
			case 'on_track':
			default:
				return 'On track'
			}
		},
		getStatusClass(status, isDone = false) {
			if (isDone) return 'status-pill--completed'
			switch (status) {
			case 'behind_at_risk':
				return 'status-pill--at-risk'
			case 'attention_needed':
				return 'status-pill--attention'
			case 'not_started':
				return 'status-pill--not-started'
			case 'on_track':
			default:
				return 'status-pill--on-track'
			}
		},
		getTaskStatusLabel(task) {
			if (task.isDone) return 'Done'
			return this.getStatusLabel(task.status)
		},
		getPhaseDurationDays(phase) {
			if (!phase?.startDate || !phase?.endDate) return 1
			const s = this.parseDateOnly(phase.startDate)
			const e = this.parseDateOnly(phase.endDate)
			return Math.max(1, Math.floor((e - s) / (1000 * 60 * 60 * 24)) + 1)
		},
		formatPhaseDuration(phase) {
			const days = this.getPhaseDurationDays(phase)
			const weeks = days / 7
			if (weeks < 1) return `${days}d`
			const fixed = Math.abs(weeks - Math.round(weeks)) < 1e-9 ? String(Math.round(weeks)) : weeks.toFixed(1)
			return `${fixed}w`
		},
		getPhaseBarStyle(phase) {
			const { start } = this.timelineRange
			const pStart = this.parseDateOnly(phase.startDate)
			const pEnd = this.parseDateOnly(phase.endDate)
			const offsetDays = Math.floor((pStart - start) / (1000 * 60 * 60 * 24))
			const durationDays = Math.max(1, Math.floor((pEnd - pStart) / (1000 * 60 * 60 * 24)) + 1)
			const leftPx = offsetDays * this.dayWidth
			const widthPx = durationDays * this.dayWidth
			const color = phase.color || '#3b82f6'
			return {
				left: `${leftPx}px`,
				width: `${widthPx}px`,
				borderColor: color,
				backgroundColor: `${color}25`,
			}
		},
		getPhaseMilestoneStyle(phase) {
			const { start } = this.timelineRange
			const dateStr = phase.milestone?.date || phase.endDate
			const mDate = this.parseDateOnly(dateStr)
			const offsetDays = Math.floor((mDate - start) / (1000 * 60 * 60 * 24))
			const leftPx = offsetDays * this.dayWidth
			return {
				left: `${leftPx}px`,
			}
		},
		getTaskBarStyle(task, phase) {
			const { start } = this.timelineRange
			const tStart = this.parseDateOnly(task.startDate)
			const tEnd = this.parseDateOnly(task.endDate)
			const offsetDays = Math.floor((tStart - start) / (1000 * 60 * 60 * 24))
			const durationDays = Math.max(1, Math.floor((tEnd - tStart) / (1000 * 60 * 60 * 24)) + 1)
			const leftPx = offsetDays * this.dayWidth
			const widthPx = durationDays * this.dayWidth
			const color = phase?.color || '#3b82f6'
			return {
				left: `${leftPx}px`,
				width: `${widthPx}px`,
				backgroundColor: color,
				'--bar-color': color,
			}
		},
		getTaskBarTitle(task) {
			const status = this.getTaskStatusLabel(task)
			const delay = task.delayDays > 0 ? ` • Delayed by +${this.formatDelayBadge(task.delayDays)}` : ''
			return `${task.label}: ${this.formatDate(task.startDate)} – ${this.formatDate(task.endDate)} (${task.durationDays}d) • ${status}${delay}`
		},
		isSystemItem(item) {
			return !!(item && item.systemKey)
		},
		isLegacySystemItem(item) {
			const key = String(item?.systemKey || '').trim()
			return ['request_date', 'process_completed', 'prep_time', 'deck_schedule'].includes(key)
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
				const summaryUrl = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${this.projectId}/timeline/summary`)
				const [timelineRes, summaryRes] = await Promise.all([
					axios.get(timelineUrl).catch(() => ({ data: [] })),
					axios.get(summaryUrl).catch(() => ({ data: null })),
				])
				this.allItems = timelineRes.data || []
				this.timelineSummary = summaryRes.data || null
				this.activeImpactAnalysis = this.timelineSummary?.delayAnalysis?.defaultAnalysis || null
			} catch (error) {
				console.error('Error loading timeline data:', error)
			} finally {
				this.loading = false
			}
		},
		async onSaveDesiredDate(dateStr) {
			this.savingDesiredDate = true
			try {
				const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${this.projectId}/timeline/planning`)
				const response = await axios.put(url, {
					desired_start_date: dateStr || null,
				})
				if (response.data?.summary) {
					this.timelineSummary = response.data.summary
				} else {
					await this.loadItems()
				}
			} catch (error) {
				console.error('Error updating desired start date:', error)
			} finally {
				this.savingDesiredDate = false
			}
		},
		async onSavePrepWeeks(weeks) {
			this.savingDesiredDate = true
			try {
				const url = generateUrl(`/apps/projectcreatoraio/api/v1/projects/${this.projectId}/timeline/planning`)
				const response = await axios.put(url, {
					required_preparation_weeks: weeks,
				})
				if (response.data?.summary) {
					this.timelineSummary = response.data.summary
				}
				await this.loadItems()
			} catch (error) {
				console.error('Error updating preparation weeks:', error)
			} finally {
				this.savingDesiredDate = false
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
			const isoWeek = Math.ceil(((utcDate - yearStart) / (1000 * 60 * 60 * 24)) + 1) / 7
			return { isoWeek: Math.ceil(isoWeek), isoYear }
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
		navigateToDate(dateStr) {
			if (!dateStr) return
			const { start } = this.timelineRange
			const d = this.parseDateOnly(dateStr)
			const offsetDays = Math.floor((d - start) / (1000 * 60 * 60 * 24))
			const offsetPx = offsetDays * this.dayWidth
			const el = this.$refs.scrollEl
			if (!el) return
			try {
				el.scrollTo({ left: Math.max(0, offsetPx - 60), behavior: 'smooth' })
			} catch (e) {
				el.scrollLeft = Math.max(0, offsetPx - 60)
			}
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

.title-with-badge {
	display: flex;
	align-items: center;
	gap: 12px;
}

.timeline-v2__title {
	margin: 0;
	font-size: 22px;
	font-weight: 700;
}

.delay-warning-badge {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	background: rgba(239, 68, 68, 0.12);
	color: #dc2626;
	border: 1px solid rgba(239, 68, 68, 0.3);
	padding: 3px 10px;
	border-radius: 99px;
	font-size: 11px;
	font-weight: 800;
}

.timeline-v2__subtitle {
	margin: 4px 0 0;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.timeline-v2__controls {
	display: flex;
	align-items: center;
	gap: 10px;
	flex-wrap: wrap;
}

.advisor-btn {
	font-weight: 700;
}

.advisor-btn--alert {
	border-color: #ef4444 !important;
	color: #dc2626 !important;
}

.whatif-btn {
	font-weight: 700;
	color: #3b82f6 !important;
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
	grid-template-columns: 280px 1fr 110px;
	border-bottom: 1px solid var(--color-border);
	transition: background 0.2s ease;
}

.gantt-v2--admin {
	grid-template-columns: 280px 1fr 110px 80px;
}

.gantt-v2--simulating {
	background: rgba(59, 130, 246, 0.02);
}

.gantt-v2__header-cell {
	display: flex;
	align-items: center;
	padding: 0 16px;
	background: var(--color-background-dark);
	border-bottom: 1px solid var(--color-border);
	font-size: 12px;
	font-weight: 800;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	color: var(--color-text-maxcontrast);
}

.gantt-v2__header-cell--center {
	justify-content: center;
}

/* Sidebar / Phase List */
.gantt-v2__sidebar {
	border-right: 1px solid var(--color-border);
	background: var(--color-main-background);
}

.phase-row {
	display: flex;
	align-items: center;
	padding: 0 16px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-main-background);
	box-sizing: border-box;
	transition: background 0.15s ease;
}

.phase-row:hover {
	background: var(--color-background-hover);
}

/* System Planning Row */
.phase-row--system-planning {
	min-height: 76px;
	height: 76px;
	box-sizing: border-box;
	background: rgba(59, 130, 246, 0.05);
	border-left: 3px solid #3b82f6;
	cursor: default;
}

.sp-sidebar-badge {
	width: 20px;
	height: 20px;
	border-radius: 50%;
	background: #3b82f6;
	color: #ffffff;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 11px;
	font-weight: 800;
	margin-right: 8px;
	flex-shrink: 0;
}

.sp-sidebar-icon {
	display: flex;
	align-items: center;
	color: #3b82f6;
	margin-right: 8px;
	flex-shrink: 0;
}

/* Phase Header Row in Sidebar */
.phase-row--phase-header {
	border-left: 4px solid #3b82f6;
	background: var(--color-background-hover);
	cursor: pointer;
	user-select: none;
}

.phase-row--phase-header:hover {
	background: var(--color-background-dark);
}

.phase-toggle-btn {
	background: none;
	border: none;
	padding: 4px;
	margin-right: 4px;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: 4px;
	transition: background 0.15s ease;
}

.phase-toggle-btn:hover {
	background: rgba(0, 0, 0, 0.08);
	color: var(--color-main-text);
}

.phase-order-badge {
	width: 20px;
	height: 20px;
	border-radius: 50%;
	color: #ffffff;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 11px;
	font-weight: 800;
	margin-right: 8px;
	flex-shrink: 0;
}

/* Task Child Row in Sidebar */
.phase-row--task-child {
	padding-left: 32px;
	background: var(--color-main-background);
}

.phase-row--task-child:hover {
	background: var(--color-background-hover);
}

.task-tree-indicator {
	position: relative;
	width: 20px;
	height: 100%;
	margin-right: 4px;
	flex-shrink: 0;
}

.tree-line-v {
	position: absolute;
	left: 8px;
	top: 0;
	bottom: 0;
	width: 1px;
	background: var(--color-border);
}

.tree-line-h {
	position: absolute;
	left: 8px;
	top: 50%;
	width: 10px;
	height: 1px;
	background: var(--color-border);
}

.task-deck-icon {
	display: flex;
	align-items: center;
	color: var(--color-primary-element);
	margin-right: 6px;
	flex-shrink: 0;
	opacity: 0.85;
}

.task-name {
	font-size: 13px;
	font-weight: 600;
	color: var(--color-main-text);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.task-name--done {
	text-decoration: line-through;
	opacity: 0.7;
}

.task-name--delayed {
	color: #dc2626;
	font-weight: 700;
}

.task-delay-tag {
	font-size: 10px;
	font-weight: 800;
	background: #ef4444;
	color: #ffffff;
	padding: 1px 6px;
	border-radius: 99px;
	white-space: nowrap;
}

.drag-handle {
	cursor: grab;
	padding: 8px 4px;
	margin-right: 8px;
	color: var(--color-text-lighter);
	display: flex;
	align-items: center;
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
	font-size: 13px;
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

/* SVG Dependency Lines */
.timeline-dependencies-svg {
	position: absolute;
	top: 0;
	left: 0;
	pointer-events: none;
	z-index: 4;
}

.dependency-line {
	fill: none;
	stroke: #94a3b8;
	stroke-width: 1.5;
	stroke-linejoin: round;
	stroke-linecap: round;
	opacity: 0.8;
	transition: stroke 0.2s ease;
}

.dependency-line--active {
	stroke: #3b82f6;
	stroke-width: 2;
}

.dependency-line--delayed {
	stroke: #ef4444;
	stroke-dasharray: 4 2;
	stroke-width: 2;
}

/* Canvas Rows */
.timeline-row {
	display: flex;
	align-items: center;
	border-bottom: 1px solid var(--color-border);
	position: relative;
	box-sizing: border-box;
}

.timeline-row--phase-header {
	background: rgba(0, 0, 0, 0.015);
}

.timeline-row--task-child {
	background: var(--color-main-background);
}

/* Phase Summary Bar & Milestone */
.phase-summary-bar {
	position: absolute;
	top: 50%;
	transform: translateY(-50%);
	height: 18px;
	border-radius: 6px;
	border: 2px solid #3b82f6;
	display: flex;
	align-items: center;
	padding: 0 8px;
	box-sizing: border-box;
	z-index: 5;
	box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
}

.phase-summary-bar__label {
	font-size: 10px;
	font-weight: 800;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	color: var(--color-main-text);
}

.phase-milestone-marker {
	position: absolute;
	top: 50%;
	transform: translateY(-50%);
	display: flex;
	align-items: center;
	z-index: 8;
	pointer-events: auto;
}

.phase-milestone-diamond {
	width: 14px;
	height: 14px;
	transform: rotate(45deg);
	border-radius: 2px;
	border: 2px solid #ffffff;
	box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25);
	flex-shrink: 0;
	transition: transform 0.2s ease;
	cursor: pointer;
}

.phase-milestone-diamond:hover {
	transform: rotate(45deg) scale(1.25);
}

.phase-milestone-label {
	margin-left: 8px;
	font-size: 11px;
	font-weight: 700;
	white-space: nowrap;
	color: var(--color-main-text);
	background: var(--color-main-background);
	padding: 1px 6px;
	border-radius: 4px;
	border: 1px solid var(--color-border);
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

/* Ghost Bar for Original Baseline */
.timeline-bar--ghost {
	position: absolute;
	top: 50%;
	transform: translateY(-50%);
	height: 26px;
	border-radius: 6px;
	border: 1.5px dashed #94a3b8;
	background: rgba(148, 163, 184, 0.12);
	pointer-events: none;
	z-index: 3;
}

/* Task Bar */
.timeline-bar {
	position: absolute;
	top: 50%;
	height: 26px;
	border-radius: 6px;
	display: flex;
	align-items: center;
	padding: 0 10px;
	box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
	border: 1px solid rgba(255, 255, 255, 0.2);
	cursor: pointer;
	transition: filter 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
	z-index: 5;
	transform: translateY(-50%);
}

.timeline-bar:hover {
	filter: brightness(1.08);
	transform: translateY(-50%) scale(1.01);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.18);
	z-index: 10;
}

.timeline-bar--readonly {
	cursor: default;
}

.timeline-bar--readonly:hover {
	filter: none;
	transform: translateY(-50%);
	box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
}

.timeline-bar--task {
	gap: 6px;
}

.timeline-bar--task-done {
	opacity: 0.85;
}

.timeline-bar--task-at-risk {
	border: 2px solid #ef4444;
	box-shadow: 0 0 6px rgba(239, 68, 68, 0.4);
}

.timeline-bar--simulated {
	box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.6), 0 2px 8px rgba(0, 0, 0, 0.15);
}

.task-delay-badge {
	background: #ef4444;
	color: #ffffff;
	font-size: 9px;
	font-weight: 800;
	padding: 1px 5px;
	border-radius: 99px;
	margin-left: auto;
	box-shadow: 0 1px 3px rgba(239, 68, 68, 0.4);
	flex-shrink: 0;
}

.task-done-icon {
	color: #ffffff;
	flex-shrink: 0;
}

.timeline-bar__label {
	font-size: 11px;
	font-weight: 700;
	color: #ffffff;
	text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.timeline-milestone {
	position: absolute;
	top: 50%;
	width: 16px;
	height: 16px;
	background: var(--marker-color, #0f172a);
	transform: translate(-50%, -50%) rotate(45deg);
	border-radius: 3px;
	box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
	border: 2px solid #fff;
	z-index: 8;
	transition: transform 0.2s ease;
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

/* Guide Lines */
.timeline-guide-marker {
	position: absolute;
	top: 0;
	bottom: 0;
	z-index: 6;
	pointer-events: none;
}

.timeline-guide-line {
	width: 2px;
	height: 100%;
}

.timeline-guide-line--min-start {
	border-left: 2px dashed #7c3aed;
	opacity: 0.7;
}

.timeline-guide-line--desired-start {
	border-left: 2px dashed #4f46e5;
	opacity: 0.7;
}

/* Column 3: Status Column */
.gantt-v2__status {
	border-left: 1px solid var(--color-border);
	background: var(--color-main-background);
}

.status-row {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 0 10px;
	border-bottom: 1px solid var(--color-border);
	box-sizing: border-box;
}

.status-row--system-planning {
	height: 76px;
	background: rgba(59, 130, 246, 0.03);
}

.status-row--phase-header {
	background: var(--color-background-hover);
}

.status-pill {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 3px 8px;
	border-radius: 99px;
	font-size: 11px;
	font-weight: 700;
	border: 1px solid transparent;
	white-space: nowrap;
}

.status-dot {
	width: 7px;
	height: 7px;
	border-radius: 50%;
	flex-shrink: 0;
}

.status-pill--on-track {
	background: rgba(16, 185, 129, 0.12);
	color: #059669;
	border-color: rgba(16, 185, 129, 0.25);
}

.status-pill--on-track .status-dot {
	background: #10b981;
}

.status-pill--attention {
	background: rgba(245, 158, 11, 0.12);
	color: #d97706;
	border-color: rgba(245, 158, 11, 0.25);
}

.status-pill--attention .status-dot {
	background: #f59e0b;
}

.status-pill--at-risk {
	background: rgba(239, 68, 68, 0.12);
	color: #dc2626;
	border-color: rgba(239, 68, 68, 0.25);
}

.status-pill--at-risk .status-dot {
	background: #ef4444;
}

.status-pill--not-started {
	background: rgba(148, 163, 184, 0.12);
	color: #64748b;
	border-color: rgba(148, 163, 184, 0.25);
}

.status-pill--not-started .status-dot {
	background: #94a3b8;
}

.status-pill--completed {
	background: rgba(5, 150, 105, 0.15);
	color: #047857;
	border-color: rgba(5, 150, 105, 0.3);
}

.status-pill--completed .status-dot {
	background: #059669;
}

/* Column 4: Actions Column */
.gantt-v2__actions {
	border-left: 1px solid var(--color-border);
	background: rgba(0, 0, 0, 0.01);
}

.action-row {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 4px;
	border-bottom: 1px solid var(--color-border);
	box-sizing: border-box;
}

.action-row--system-planning {
	height: 76px;
	background: rgba(59, 130, 246, 0.03);
}

.action-row--phase-header {
	background: var(--color-background-hover);
}

.action-row__locked-hint,
.action-row__deck-hint {
	color: var(--color-text-lighter);
	display: flex;
	align-items: center;
	justify-content: center;
	opacity: 0.7;
}

.action-row__deck-hint {
	color: var(--color-primary-element);
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
	.gantt-v2__status { border-left: none; }
	.gantt-v2__actions { border-left: none; }
}
</style>
