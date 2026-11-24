<template>
 <section class="pc-overview pc-dashboard" :aria-label="t('Project overview')">
  <OverviewPanel name="attention" :title="t('1. Agreements & attention')" icon="flag" :action="t('+ Add a note')" :link="t('View all notes')" :state="overview.notes" @open="open('notes')" @retry="retry('notes')">
   <div v-if="!notes.length" class="pc-panel-empty"><FlagOutline :size="28" /><strong>{{ t('No decisions or action points yet') }}</strong><p>{{ t('Record a decision, action point or risk in project notes.') }}</p></div>
   <ul v-else class="pc-summary-rows"><li v-for="note in notes" :key="note.id"><span class="pc-row-icon" :class="{ 'pc-tone-warning': note.noteType === 'risk_blocker' }"><FlagOutline :size="20" /></span><div class="pc-row-content"><button @click="open('notes')">{{ note.title }}</button><small>{{ noteLabel(note.noteType) }} · {{ note.userId }}</small></div><time>{{ date(note.updatedAt) }}</time></li></ul>
  </OverviewPanel>
  <OverviewPanel name="visits" :title="t('2. Since your last visit')" icon="eye" :link="t('View project activity')" @open="open('activity')">
   <div class="pc-panel-empty pc-empty-outline"><EyeOutline :size="28" /><strong>{{ t('No visit comparison available yet') }}</strong><p>{{ t('Project visits are not tracked yet. You can review recent changes in the activity feed.') }}</p></div>
  </OverviewPanel>
  <OverviewPanel name="activity" :title="t('3. Recent activity')" icon="history" :link="t('View all activity')" :state="overview.activity" @open="open('activity')" @retry="retry('activity')">
   <div v-if="!events.length" class="pc-panel-empty"><History :size="28" /><strong>{{ t('No activity yet') }}</strong><p>{{ t('Project updates will appear here.') }}</p></div>
   <ul v-else class="pc-summary-rows"><li v-for="event in events" :key="event.id"><span class="pc-row-icon"><History :size="20" /></span><div class="pc-row-content"><button @click="open('activity')">{{ activityText(event) }}</button><small>{{ event.actorDisplayName || event.actorUid || t('Project') }} · {{ source(event.source) }}</small></div><time>{{ date(event.occurredAt) }}</time></li></ul>
  </OverviewPanel>
  <OverviewPanel name="tasks" :title="t('4. Tasks')" icon="tasks" :link="t('View all tasks')" :state="overview.tasks" @open="open('tasks')" @retry="retry('tasks')">
   <div class="iz-tabs pc-task-tabs" role="tablist" :aria-label="t('Tasks')">
    <button v-for="view in taskViews" :id="'pc-task-tab-' + view.id" :key="view.id" type="button" role="tab" class="iz-tab" :class="{ 'iz-tab--active': taskView === view.id }" :aria-selected="String(taskView === view.id)" aria-controls="pc-task-panel" @click="taskView = view.id">{{ view.label }}<span class="iz-tab__count">{{ view.tasks.length }}</span></button>
   </div>
   <div id="pc-task-panel" role="tabpanel" :aria-labelledby="'pc-task-tab-' + taskView">
    <div v-if="!shownTasks.length" class="pc-panel-empty pc-empty-outline"><ClipboardCheckOutline :size="28" /><template v-if="taskView === 'mine'"><strong>{{ t('No open tasks assigned directly to you') }}</strong><p>{{ t('Switch to All tasks to see the rest of the board.') }}</p></template><template v-else><strong>{{ t('No open tasks on the board') }}</strong><p>{{ t('Everything on the task board is done or archived.') }}</p></template></div>
    <ol v-else class="pc-task-summary"><li v-for="(task, index) in shownTasks.slice(0, 3)" :key="task.id"><span>{{ index + 1 }}</span><div><button @click="open('tasks')">{{ task.title }}</button><small>{{ task.stackTitle }}</small></div></li></ol>
   </div>
  </OverviewPanel>
  <OverviewPanel name="timeline" :title="t('5. Timeline')" icon="progress" :state="overview.planning" @retry="retry('planning')">
   <p class="pc-timeline-status"><span class="iz-pill" :class="'iz-pill--' + timeline.status.tone">{{ timeline.status.label }}</span><span>{{ timeline.status.text }}</span></p>
   <div class="pc-timeline-overall"><p><strong><span v-if="timeline.overall.estimate" class="pc-timeline-approx" aria-hidden="true">≈</span><span v-if="timeline.overall.estimate" class="pc-sr-only">{{ t('About') }} </span>{{ timeline.overall.percent === null ? '—' : timeline.overall.percent + '%' }}</strong><span>{{ timeline.overall.text }}</span><span v-if="timeline.overall.estimate" class="iz-pill iz-pill--muted pc-timeline-estimate">{{ t('Estimate') }}</span></p><div v-if="timeline.overall.percent !== null" class="pc-timeline-meter" role="progressbar" :aria-label="timeline.overall.estimate ? t('Estimated share of the timeline passed') : t('Share of the timeline passed')" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="timeline.overall.percent"><i :class="{ 'pc-timeline-meter--estimate': timeline.overall.estimate }" :style="{ width: timeline.overall.percent + '%' }" /></div><div v-else class="pc-timeline-meter" aria-hidden="true" /><small v-if="timeline.overall.note">{{ timeline.overall.note }}</small></div>
   <div class="pc-timeline">
    <section class="pc-timeline-group" :class="stageClass(0)" aria-labelledby="pc-timeline-initiation"><h3 id="pc-timeline-initiation"><i aria-hidden="true" />{{ t('Initiation') }}<span class="pc-sr-only">{{ stageText(0) }}</span></h3><dl>
     <div><dt>{{ t('Process steps') }}</dt><dd>{{ timeline.initiation.configured ? t('{done} of {total} done', { done: timeline.initiation.done, total: timeline.initiation.total }) : t('Not configured') }}</dd><div v-if="timeline.initiation.configured" class="pc-timeline-meter" role="progressbar" :aria-label="t('Process steps done')" aria-valuemin="0" :aria-valuemax="timeline.initiation.total" :aria-valuenow="timeline.initiation.done"><i :style="{ width: timeline.initiation.percent + '%' }" /></div><small :class="{ 'pc-timeline-warn': timeline.initiation.warn }">{{ timeline.initiation.note }}</small></div>
     <div><dt>{{ t('Handover') }}</dt><dd :class="{ 'pc-timeline-unset': !timeline.initiation.handover }">{{ date(timeline.initiation.handover) }}</dd><small :class="{ 'pc-timeline-warn': timeline.initiation.handoverWarn }">{{ timeline.initiation.handoverNote }}</small></div>
    </dl></section>
    <section class="pc-timeline-group" :class="stageClass(1)" aria-labelledby="pc-timeline-execution"><h3 id="pc-timeline-execution"><i aria-hidden="true" />{{ t('Execution') }}<span class="pc-sr-only">{{ stageText(1) }}</span></h3><dl>
     <div><dt>{{ t('Request date') }}</dt><dd>{{ date(timeline.initiation.requestDate) }}</dd></div>
     <div><dt>{{ t('Minimum start') }}</dt><dd>{{ timeline.execution.minimumStart ? date(timeline.execution.minimumStart) : '—' }}</dd><small>{{ timeline.execution.note }}</small></div>
     <div><dt>{{ t('Desired start') }}</dt><dd :class="{ 'pc-timeline-unset': !timeline.execution.desired }">{{ date(timeline.execution.desired) }}</dd><small v-if="timeline.execution.desiredNote" class="pc-timeline-warn">{{ timeline.execution.desiredNote }}</small></div>
     <div><dt>{{ t('Actual start') }}</dt><dd :class="{ 'pc-timeline-unset': !timeline.execution.actual }">{{ date(timeline.execution.actual) }}</dd><small v-if="timeline.execution.actualNote" class="pc-timeline-warn">{{ timeline.execution.actualNote }}</small></div>
    </dl></section>
    <section class="pc-timeline-group" :class="stageClass(2)" aria-labelledby="pc-timeline-delivery"><h3 id="pc-timeline-delivery"><i aria-hidden="true" />{{ t('Delivery') }}<span class="pc-sr-only">{{ stageText(2) }}</span></h3><dl>
     <div><dt>{{ t('End date') }}</dt><dd :class="{ 'pc-timeline-unset': !timeline.delivery.end }"><template v-if="timeline.delivery.planned"><span v-if="plannedParts[0]" class="pc-timeline-unset">{{ plannedParts[0] }}</span>{{ date(timeline.delivery.end) }}<span v-if="plannedParts[1]" class="pc-timeline-unset">{{ plannedParts[1] }}</span></template><template v-else>{{ date(timeline.delivery.end) }}</template></dd><small>{{ timeline.delivery.note }}</small></div>
     <div><dt>{{ t('Weeks on site') }}</dt><dd :class="{ 'pc-timeline-unset': timeline.delivery.weeks === null }">{{ timeline.delivery.weeks === null ? t('Not set') : formatNumber(timeline.delivery.weeks) }}</dd></div>
    </dl></section>
   </div>
   <template #footer><button class="pc-panel-action" @click="open('tasks')">{{ t('View process steps') }} <span aria-hidden="true">→</span></button><button class="pc-panel-action" @click="open('planning')">{{ t('View full planning') }} <span aria-hidden="true">→</span></button></template>
  </OverviewPanel>
  <OverviewPanel name="scope" :title="t('6. Project purpose & scope')" icon="flag" :link="t('View project details')" @open="openDetails">
   <div class="pc-scope-grid"><div><h3>{{ t('Project purpose') }}</h3><p>{{ project.description || t('No project purpose has been added yet.') }}</p></div><div><h3>{{ t('Disciplines') }}</h3><ul v-if="disciplines.length" class="pc-discipline-list"><li v-for="discipline in disciplines" :key="discipline.value" class="iz-pill"><component :is="discipline.icon" :size="14" />{{ discipline.label }}</li></ul><p v-else class="pc-muted">{{ t('None chosen yet') }}</p><h3>{{ t('Scope & exclusions') }}</h3><p class="pc-muted">{{ t('Not recorded separately') }}</p></div></div>
  </OverviewPanel>
  <OverviewPanel name="documents" :title="t('7. Recent documents')" icon="files" :link="t('View all documents')" :state="overview.files" @open="open('documents')" @retry="retry('files')">
   <div v-if="!files.length" class="pc-panel-empty"><FolderOutline :size="28" /><strong>{{ t('No documents available') }}</strong><p>{{ t('Upload documents from the Documents tab.') }}</p></div>
   <ul v-else class="pc-summary-rows"><li v-for="file in files" :key="file.id"><span class="pc-row-icon"><FileDocumentOutline :size="22" /></span><div class="pc-row-content"><button @click="open('documents')">{{ file.name }}</button><small>{{ size(file.size) }} · {{ file.scope }}</small></div><time>{{ date(file.mtime) }}</time></li></ul>
  </OverviewPanel>
 </section>
</template>
<script>
import OverviewPanel from './OverviewPanel.vue'
import FlagOutline from 'vue-material-design-icons/FlagOutline.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import History from 'vue-material-design-icons/History.vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import { timelineOf, assignedTasks, openTasks, recentFiles, activityText, sourceLabel, dateLabel, fileSize } from './overview.js'
import { t, formatNumber } from './l10n.mjs'
import { DISCIPLINE_OPTIONS } from './discipline-options.js'
export default {
 components: { OverviewPanel, FlagOutline, EyeOutline, History, ClipboardCheckOutline, FolderOutline, FileDocumentOutline },
 props: { project: { type: Object, required: true }, context: { type: Object, required: true }, overview: { type: Object, required: true } },
 data: () => ({ taskView: 'mine' }),
 computed: {
  timeline() { return timelineOf(this.overview.planning.data) },
  disciplines() { return DISCIPLINE_OPTIONS.filter(option => (this.project.disciplines || []).includes(option.value)) },
  notes() { return this.overview.notes.data?.notes || [] },
  events() { return this.overview.activity.data || [] },
  tasks() { return assignedTasks(this.overview.tasks.data || [], this.context.userId) },
  allTasks() { return openTasks(this.overview.tasks.data || []) },
  taskViews() { return [{ id: 'mine', label: t('My tasks'), tasks: this.tasks }, { id: 'all', label: t('All tasks'), tasks: this.allTasks }] },
  // 'Planned {date}' around the date, so the word keeps its muted style in any language.
  plannedParts() { return t('Planned {date}', { date: '\u0000' }).split('\u0000') },
  shownTasks() { return this.taskView === 'mine' ? this.tasks : this.allTasks },
  files() { return this.overview.files.data ? recentFiles(this.overview.files.data) : [] },
 },
 methods: {
  activityText, source: sourceLabel, date: dateLabel, size: fileSize, formatNumber,
  open(tab) { this.$emit('navigate', tab) }, retry(section) { this.$emit('retry', section) },
  openDetails() { this.$emit('edit-details') },
  stageClass(index) { return { 'pc-timeline-group--done': index < this.timeline.stage, 'pc-timeline-group--now': index === this.timeline.stage } },
  stageText(index) { return index < this.timeline.stage ? t(', done') : index === this.timeline.stage ? t(', current') : '' },
  noteLabel(type) { return { decision: t('Decision'), risk_blocker: t('Risk / blocker note'), action_point: t('Action point') }[type] || t('Note') },
 },
}
</script>
