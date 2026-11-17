<template>
 <header class="pc-project-header">
  <div class="pc-header-main">
   <div class="pc-project-identity"><h1>{{ project.name }}</h1><div class="pc-project-meta"><span>#{{ project.number }}</span><span>{{ projectType }} project</span><span v-if="project.loc_city" ref="location" class="pc-location"><button ref="locationButton" type="button" class="pc-location__btn" :aria-expanded="String(locationOpen)" aria-controls="pc-location-pop" @click="toggleLocation"><MapMarkerOutline :size="17" />{{ project.loc_city }}<ChevronDown :size="14" class="pc-location__caret" /></button><div v-if="locationOpen" id="pc-location-pop" class="pc-location__pop" role="dialog" :aria-label="t('projectcreatoraio', 'Project location')"><LocationMenu :street="project.loc_street || ''" :city="project.loc_city || ''" :zip="project.loc_zip || ''" :search-url="locationUrl" @edit="editLocation" /></div></span><span>{{ project.client_name }}</span></div></div>
   <ProjectActions ref="actions" class="pc-header-actions" :project="project" :context="context" :chat-url="chatUrl" @navigate="$emit('navigate', $event)" @updated="$emit('updated')" @deleted="$emit('deleted', $event)" />
  </div>
  <div class="pc-badges">
   <span class="pc-badge" :class="{ 'pc-positive': Number(project.status) === 1 }"><CircleOutline :size="15" />{{ statusLabel }}</span>
   <button class="pc-badge" @click="$emit('navigate', 'planning')">{{ overview.planning.loading ? 'Loading phase…' : overview.planning.error ? 'Phase unavailable' : planning.currentPhase || 'Phase not set' }}</button>
   <button class="pc-badge pc-primary-tint" @click="$emit('navigate', 'planning')"><ChartDonut :size="18" />{{ overview.planning.loading ? 'Loading progress…' : progress.percent === null ? 'Progress unavailable' : progress.percent + '% checklist completed' }}</button>
   <button v-if="planning.conflict" class="pc-badge pc-tone-danger" @click="$emit('navigate', 'planning')"><AlertCircleOutline :size="17" />1 planning conflict</button>
   <button v-if="riskCount !== null" class="pc-badge pc-tone-warning" @click="$emit('navigate', 'notes')"><FlagOutline :size="17" />{{ riskCount }} risk {{ riskCount === 1 ? 'note' : 'notes' }}</button>
   <button class="pc-badge pc-badge--milestone" :class="{ 'pc-tone-danger': milestone && milestone.state === 'overdue', 'pc-tone-warning': milestone && milestone.state === 'today' }" :title="milestoneHint" @click="$emit('navigate', 'tasks')"><AlertCircleOutline v-if="milestone && milestone.state === 'overdue'" :size="17" /><CalendarMonthOutline v-else :size="17" />{{ milestoneText }}<span v-if="milestone && milestone.more" class="pc-badge-more">+{{ milestone.more }}</span></button>
  </div>
  <nav class="pc-tabs" :aria-label="t('projectcreatoraio', 'Project sections')"><a v-for="item in availableTabs" :key="item.id" :href="tabUrl(item.id)" :aria-current="tab === item.id ? 'page' : null" @click.prevent="$emit('navigate', item.id)"><component :is="item.icon" :size="18" />{{ item.label }}</a></nav>
 </header>
</template>
<script>
import { generateUrl } from '@nextcloud/router'
import ProjectActions from './ProjectActions.vue'
import LocationMenu from './LocationMenu.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import MapMarkerOutline from 'vue-material-design-icons/MapMarkerOutline.vue'
import CircleOutline from 'vue-material-design-icons/CircleOutline.vue'
import ChartDonut from 'vue-material-design-icons/ChartDonut.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import FlagOutline from 'vue-material-design-icons/FlagOutline.vue'
import { progressSummary, planningSummary, nextMilestoneOf } from './overview.js'

import ViewDashboardOutline from 'vue-material-design-icons/ViewDashboardOutline.vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import NoteTextOutline from 'vue-material-design-icons/NoteTextOutline.vue'
import CalendarMonthOutline from 'vue-material-design-icons/CalendarMonthOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import Draw from 'vue-material-design-icons/Draw.vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import History from 'vue-material-design-icons/History.vue'
import { t } from '@nextcloud/l10n'
import { PROJECT_TYPES } from '../macros/project-types.js'
import { getProjectStatusLabel } from '../constants/project-statuses.js'
import { interfaceUrl } from './navigation.js'
export default {
 components: { ProjectActions, LocationMenu, ChevronDown, MapMarkerOutline, CircleOutline, ChartDonut, AlertCircleOutline, FlagOutline, CalendarMonthOutline, AccountGroupOutline },
 props: { context: { type: Object, required: true }, overview: { type: Object, required: true }, project: { type: Object, required: true }, tab: { type: String, required: true }, base: { type: String, required: true } },
 data: () => ({ locationOpen: false }),
 computed: {
  chatUrl() { return this.context.features?.talk !== false && this.project.talk_conversation_token ? generateUrl('/call/' + encodeURIComponent(this.project.talk_conversation_token)) : null },
  progress() { return progressSummary(this.overview.planning.data) },
  planning() { return planningSummary(this.overview.planning.data) },
  // The open task whose due date comes up first (overview.js, nextMilestoneOf).
  milestone() { return this.overview.tasks?.data ? nextMilestoneOf(this.overview.tasks.data) : null },
  milestoneText() {
   if (this.overview.tasks?.loading) return 'Loading milestone…'
   if (this.overview.tasks?.error) return 'Milestone unavailable'
   return this.milestone ? 'Next milestone: ' + this.milestone.title + ' · ' + this.milestone.when : 'Next milestone not scheduled'
  },
  // Hovering lists the tasks the number counts.
  milestoneHint() {
   if (!this.milestone || !this.milestone.more) return 'Open Tasks'
   const heading = this.milestone.state === 'overdue' ? 'Overdue' : this.milestone.state === 'today' ? 'Due today' : 'Due ' + this.milestone.when
   return heading + ':\n' + this.milestone.tasks.map(title => '• ' + title).join('\n')
  },
  riskCount() { const count = this.overview.notes.data?.risks; return Number.isFinite(count) ? count : null },
  statusLabel() { return getProjectStatusLabel(this.project.status) },
  projectType() { return PROJECT_TYPES.find(p => p.id === Number(this.project.type))?.label || '' },
  locationUrl() { return 'https://www.openstreetmap.org/search?query=' + encodeURIComponent([this.project.loc_street, this.project.loc_zip, this.project.loc_city].filter(Boolean).join(' ')) },
  availableTabs() {
   return [
    { id: 'overview', label: t('projectcreatoraio', 'Overview'), icon: ViewDashboardOutline },
    { id: 'tasks', label: t('projectcreatoraio', 'Tasks'), icon: ClipboardCheckOutline },
    { id: 'notes', label: t('projectcreatoraio', 'Notes'), icon: NoteTextOutline },
    { id: 'planning', label: t('projectcreatoraio', 'Planning'), icon: CalendarMonthOutline },
    { id: 'documents', label: t('projectcreatoraio', 'Documents'), icon: FolderOutline },
    ...(Number(this.project.type) === 0 ? [{ id: 'intake', label: t('projectcreatoraio', 'Intake form'), icon: ClipboardCheckOutline }] : []),
    { id: 'whiteboard', label: t('projectcreatoraio', 'Whiteboard'), icon: Draw },
    { id: 'members', label: t('projectcreatoraio', 'Members'), icon: AccountGroupOutline },
    { id: 'activity', label: t('projectcreatoraio', 'Activity'), icon: History },
    { id: 'agenda', label: t('projectcreatoraio', 'Calendar'), icon: CalendarMonthOutline },
   ].filter(item => !this.disabledTabs.includes(item.id))
  },
  // Sections whose app this person cannot use, e.g. Calendar for an external collaborator.
  disabledTabs() {
   const features = this.context.features || {}
   return Object.entries({ tasks: 'deck', intake: 'deck', agenda: 'calendar', whiteboard: 'whiteboard' })
    .filter(([, feature]) => features[feature] === false).map(([tab]) => tab)
  },
 },
 watch: { 'project.id'() { this.locationOpen = false } },
 mounted() {
  document.addEventListener('click', this.onOutside, true)
  document.addEventListener('keydown', this.onKey)
 },
 beforeDestroy() {
  document.removeEventListener('click', this.onOutside, true)
  document.removeEventListener('keydown', this.onKey)
 },
 methods: {
  t,
  tabUrl(tab) { return interfaceUrl(this.base, { projectId: this.project.id, tab }) },
  // The city opens the location menu; a keyboard user lands on its first link.
  toggleLocation(event) {
   this.locationOpen = !this.locationOpen
   if (this.locationOpen && event?.detail === 0) this.$nextTick(() => this.$refs.location?.querySelector('.pc-location__pop a, .pc-location__pop button')?.focus())
  },
  onKey(event) {
   if (event.key === 'Escape' && this.locationOpen) { this.locationOpen = false; this.$refs.locationButton?.focus() }
  },
  onOutside(event) {
   if (this.locationOpen && !this.$refs.location?.contains(event.target)) this.locationOpen = false
  },
  editLocation() { this.locationOpen = false; this.$refs.actions.openClient() },
  // The overview's "View project details".
  openDetails() { this.$refs.actions.openDetails() },
 },
}
</script>

<style scoped>
.pc-location { position: relative; display: inline-flex; }
.pc-location__btn { display: inline-flex; align-items: center; gap: 5px; min-height: 0; margin: 0; padding: 2px 4px; border: 0; border-radius: var(--iz-radius); background: transparent; color: var(--color-primary-element); font: inherit; cursor: pointer; }
.pc-location__btn:hover { background: var(--iz-surface-subtle); }
.pc-location__btn[aria-expanded="true"] { background: var(--iz-accent-bg); color: var(--iz-accent-bg-text); }
.pc-location__caret { display: inline-flex; opacity: 0.7; }
.pc-location__pop { position: absolute; z-index: 60; inset-block-start: calc(100% + 6px); inset-inline-start: 0; width: 320px; max-width: calc(100vw - 32px); padding: 6px; border: 1px solid var(--iz-border); border-radius: var(--iz-radius-lg); background: var(--iz-surface); box-shadow: var(--iz-shadow-lift, 0 12px 32px rgba(0, 0, 0, 0.16)); font-size: var(--iz-fs-md); color: var(--iz-text); }
/* On a phone the city can sit mid-row; the menu then spans the row under it. */
@media (max-width: 700px) {
 .pc-project-meta { position: relative; }
 .pc-location { position: static; }
 .pc-location__pop { inset-inline: 0; width: auto; }
}
</style>
