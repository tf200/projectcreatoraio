<template>
 <header class="pc-project-header">
  <div class="pc-header-main">
   <div class="pc-project-identity"><h1>{{ project.name }}</h1><div class="pc-project-meta"><span>#{{ project.number }}</span><span>{{ projectType }} project</span><a v-if="project.loc_city" :href="locationUrl" target="_blank" rel="noopener noreferrer"><MapMarkerOutline :size="17" />{{ project.loc_city }}</a><span>{{ project.client_name }}</span></div></div>
   <div class="pc-header-actions">
    <button class="pc-header-action" @click="$emit('navigate', 'activity')"><BellOutline :size="23" /><span>Project updates</span></button>
    <details class="pc-contact-menu"><summary class="pc-header-action"><AccountGroupOutline :size="24" /><span>Project contacts</span><small>Contact / chat / team</small></summary><div class="pc-contact-popover"><strong>{{ project.client_name || 'Project contact' }}</strong><a v-if="project.client_email" :href="'mailto:' + project.client_email">{{ project.client_email }}</a><a v-if="project.client_phone" :href="'tel:' + project.client_phone">{{ project.client_phone }}</a><button @click="$emit('navigate', 'members')">View project team</button><a v-if="chatUrl" :href="chatUrl">Open project chat</a></div></details>
    <a class="pc-header-action" :href="legacyUrl"><DotsHorizontal :size="24" /><span>Manage project</span></a>
   </div>
  </div>
  <div class="pc-badges">
   <span class="pc-badge" :class="{ 'pc-positive': Number(project.status) === 1 }"><CircleOutline :size="15" />{{ statusLabel }}</span>
   <button class="pc-badge" @click="$emit('navigate', 'planning')">{{ overview.planning.loading ? 'Loading phase…' : overview.planning.error ? 'Phase unavailable' : planning.currentPhase || 'Phase not set' }}</button>
   <button class="pc-badge pc-primary-tint" @click="$emit('navigate', 'planning')"><ChartDonut :size="18" />{{ overview.planning.loading ? 'Loading progress…' : progress.percent === null ? 'Progress unavailable' : progress.percent + '% checklist completed' }}</button>
   <button v-if="planning.conflict" class="pc-badge pc-tone-danger" @click="$emit('navigate', 'planning')"><AlertCircleOutline :size="17" />1 planning conflict</button>
   <button v-if="riskCount !== null" class="pc-badge pc-tone-warning" @click="$emit('navigate', 'notes')"><FlagOutline :size="17" />{{ riskCount }} risk {{ riskCount === 1 ? 'note' : 'notes' }}</button>
   <button class="pc-badge" @click="$emit('navigate', 'planning')"><CalendarMonthOutline :size="17" />{{ overview.planning.loading ? 'Loading milestone…' : overview.planning.error ? 'Milestone unavailable' : planning.milestone ? 'Next milestone: ' + date(planning.milestone.date) : 'Next milestone not scheduled' }}</button>
  </div>
  <nav class="pc-tabs" :aria-label="t('projectcreatoraio', 'Project sections')"><a v-for="item in availableTabs" :key="item.id" :href="tabUrl(item.id)" :aria-current="tab === item.id ? 'page' : null" @click.prevent="$emit('navigate', item.id)"><component :is="item.icon" :size="18" />{{ item.label }}</a></nav>
 </header>
</template>
<script>
import { generateUrl } from '@nextcloud/router'
import BellOutline from 'vue-material-design-icons/BellOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import MapMarkerOutline from 'vue-material-design-icons/MapMarkerOutline.vue'
import CircleOutline from 'vue-material-design-icons/CircleOutline.vue'
import ChartDonut from 'vue-material-design-icons/ChartDonut.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import FlagOutline from 'vue-material-design-icons/FlagOutline.vue'
import { progressSummary, planningSummary, dateLabel } from './overview.js'

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
 components: { BellOutline, DotsHorizontal, MapMarkerOutline, CircleOutline, ChartDonut, AlertCircleOutline, FlagOutline, CalendarMonthOutline, AccountGroupOutline },
 props: { context: { type: Object, required: true }, overview: { type: Object, required: true }, project: { type: Object, required: true }, tab: { type: String, required: true }, base: { type: String, required: true }, legacyUrl: { type: String, required: true } },
 computed: {
  chatUrl() { return this.context.features?.talk !== false && this.project.talk_conversation_token ? generateUrl('/call/' + encodeURIComponent(this.project.talk_conversation_token)) : null },
  progress() { return progressSummary(this.overview.planning.data) },
  planning() { return planningSummary(this.overview.planning.data) },
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
   ]
  },
 },
 methods: { date: dateLabel, tabUrl(tab) { return interfaceUrl(this.base, { projectId: this.project.id, tab }) } },
}
</script>
