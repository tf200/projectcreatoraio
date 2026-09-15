<template>
 <section class="pc-overview pc-dashboard" aria-label="Project overview">
  <OverviewPanel name="attention" title="1. Agreements & attention" icon="flag" action="+ Add a note" link="View all notes" :state="overview.notes" @open="open('notes')" @retry="retry('notes')">
   <div v-if="!notes.length" class="pc-panel-empty"><FlagOutline :size="28" /><strong>No decisions or action points yet</strong><p>Record a decision, action point or risk in project notes.</p></div>
   <ul v-else class="pc-summary-rows"><li v-for="note in notes" :key="note.id"><span class="pc-row-icon" :class="{ 'pc-tone-warning': note.noteType === 'risk_blocker' }"><FlagOutline :size="20" /></span><div class="pc-row-content"><button @click="open('notes')">{{ note.title }}</button><small>{{ noteLabel(note.noteType) }} · {{ note.userId }}</small></div><time>{{ date(note.updatedAt) }}</time></li></ul>
  </OverviewPanel>
  <OverviewPanel name="visits" title="2. Since your last visit" icon="eye" link="View project activity" @open="open('activity')">
   <div class="pc-panel-empty pc-empty-outline"><EyeOutline :size="28" /><strong>No visit comparison available yet</strong><p>Project visits are not tracked yet. You can review recent changes in the activity feed.</p></div>
  </OverviewPanel>
  <OverviewPanel name="activity" title="3. Recent activity" icon="history" link="View all activity" :state="overview.activity" @open="open('activity')" @retry="retry('activity')">
   <div v-if="!events.length" class="pc-panel-empty"><History :size="28" /><strong>No activity yet</strong><p>Project updates will appear here.</p></div>
   <ul v-else class="pc-summary-rows"><li v-for="event in events" :key="event.id"><span class="pc-row-icon"><History :size="20" /></span><div class="pc-row-content"><button @click="open('activity')">{{ activityText(event) }}</button><small>{{ event.actorDisplayName || event.actorUid || 'Project' }} · {{ event.source || 'Project' }}</small></div><time>{{ date(event.occurredAt) }}</time></li></ul>
  </OverviewPanel>
  <OverviewPanel name="tasks" title="4. My tasks" icon="tasks" link="View all tasks" :state="overview.tasks" @open="open('tasks')" @retry="retry('tasks')">
   <div v-if="!tasks.length" class="pc-panel-empty pc-empty-outline"><ClipboardCheckOutline :size="28" /><strong>No open tasks assigned directly to you</strong><p>Open the task board to review team assignments.</p></div>
   <ol v-else class="pc-task-summary"><li v-for="(task, index) in tasks.slice(0, 3)" :key="task.id"><span>{{ index + 1 }}</span><div><button @click="open('tasks')">{{ task.title }}</button><small>{{ task.stackTitle }}</small></div></li></ol>
  </OverviewPanel>
  <OverviewPanel name="progress" title="5. Process progress" icon="progress" link="View process details" :state="overview.planning" @open="open('planning')" @retry="retry('planning')">
   <div class="pc-progress-layout"><div class="pc-progress-ring" :style="{ '--pc-progress': (progress.percent || 0) + '%' }" :aria-label="progress.percent === null ? 'Checklist not configured' : progress.percent + '% of required checklist completed'"><div><strong>{{ progress.percent === null ? '—' : progress.percent + '%' }}</strong><small>Required checklist</small></div></div><div class="pc-phase-area"><ol v-if="planning.phases.length" class="pc-phase-track"><li v-for="phase in planning.phases" :key="phase.id" :class="{ 'pc-phase-done': phase.tasks.length && phase.tasks.every(task => task.isDone) }"><span aria-hidden="true">{{ phase.tasks.length && phase.tasks.every(task => task.isDone) ? '✓' : '○' }}</span><small>{{ phase.name }}</small><strong>{{ phase.tasks.filter(task => task.isDone).length }} / {{ phase.tasks.length }}</strong></li></ol><p v-else class="pc-muted">No process phases configured.</p></div></div>
   <p class="pc-summary-notice" :class="{ 'pc-tone-warning': progress.missing.length }"><InformationOutline :size="17" /><span v-if="progress.percent === null">The required checklist is not configured.</span><span v-else>{{ progress.done }} of {{ progress.total }} required steps completed.<template v-if="progress.missing.length"> {{ progress.missing.length }} required cards are missing.</template></span></p>
  </OverviewPanel>
  <OverviewPanel name="planning" title="6. Planning" icon="calendar" link="View full planning" :state="overview.planning" @open="open('planning')" @retry="retry('planning')">
   <dl class="pc-planning-grid"><div><dt>Request date</dt><dd>{{ date(summary.requestDate) }}</dd></div><div><dt>Desired start</dt><dd>{{ date(summary.desiredStartDate) }}</dd></div><div><dt>Earliest planned start</dt><dd>{{ date(summary.minimumStartDate) }}</dd></div><div><dt>Next pending milestone</dt><dd>{{ planning.milestone ? date(planning.milestone.date) : 'Not scheduled' }}</dd><small v-if="planning.milestone">{{ planning.milestone.label }}</small></div></dl>
   <p class="pc-summary-notice" :class="{ 'pc-tone-danger': planning.conflict }"><InformationOutline :size="17" /><span>{{ planning.conflict === null ? 'Set a desired start date to check for a planning conflict.' : planning.conflict ? 'Planning conflict: the earliest start falls after the desired date.' : 'The desired start is within the current planning estimate.' }}</span></p>
  </OverviewPanel>
  <OverviewPanel name="scope" title="7. Project purpose & scope" icon="flag" link="View project details" @open="openDetails">
   <div class="pc-scope-grid"><div><h3>Project purpose</h3><p>{{ project.description || 'No project purpose has been added yet.' }}</p></div><div><h3>Disciplines</h3><p class="pc-muted">Not recorded separately</p><h3>Scope & exclusions</h3><p class="pc-muted">Not recorded separately</p></div></div>
  </OverviewPanel>
  <OverviewPanel name="documents" title="8. Recent documents" icon="files" link="View all documents" :state="overview.files" @open="open('documents')" @retry="retry('files')">
   <div v-if="!files.length" class="pc-panel-empty"><FolderOutline :size="28" /><strong>No documents available</strong><p>Upload documents from the Documents tab.</p></div>
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
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import { progressSummary, planningSummary, assignedTasks, recentFiles, activityText, dateLabel, fileSize } from './overview.js'
export default {
 components: { OverviewPanel, FlagOutline, EyeOutline, History, ClipboardCheckOutline, InformationOutline, FolderOutline, FileDocumentOutline },
 props: { project: { type: Object, required: true }, context: { type: Object, required: true }, overview: { type: Object, required: true }, legacyUrl: String },
 computed: {
  summary() { return this.overview.planning.data || {} },
  progress() { return progressSummary(this.overview.planning.data) },
  planning() { return planningSummary(this.overview.planning.data) },
  notes() { return this.overview.notes.data?.notes || [] },
  events() { return this.overview.activity.data || [] },
  tasks() { return assignedTasks(this.overview.tasks.data || [], this.context.userId) },
  files() { return this.overview.files.data ? recentFiles(this.overview.files.data) : [] },
 },
 methods: {
  activityText, date: dateLabel, size: fileSize,
  open(tab) { this.$emit('navigate', tab) }, retry(section) { this.$emit('retry', section) },
  openDetails() { window.location.assign(this.legacyUrl) },
  noteLabel(type) { return { decision: 'Decision', risk_blocker: 'Risk / blocker note', action_point: 'Action point' }[type] || 'Note' },
 },
}
</script>
