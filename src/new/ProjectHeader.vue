<template>
 <header class="pc-project-header">
  <a class="pc-back" :href="base + '/new'" @click.prevent="$emit('back')">← {{ t('projectcreatoraio', 'All projects') }}</a>
  <div class="pc-header-main"><div><h1>{{ project.name }}</h1><div class="pc-project-meta"><span>#{{ project.number }}</span><span>{{ projectType }}</span><a v-if="project.loc_city" :href="locationUrl" target="_blank" rel="noopener noreferrer">{{ project.loc_city }}</a><span>{{ project.client_name }}</span></div></div><a class="pc-secondary" :href="legacyUrl">{{ t('projectcreatoraio', 'Edit in current interface') }}</a></div>
  <div class="pc-badges"><span class="pc-badge" :class="{ 'pc-positive': Number(project.status) === 1 }">{{ statusLabel }}</span></div>
  <nav class="pc-tabs" :aria-label="t('projectcreatoraio', 'Project sections')"><a v-for="item in availableTabs" :key="item.id" :href="tabUrl(item.id)" :aria-current="tab === item.id ? 'page' : null" @click.prevent="$emit('navigate', item.id)"><component :is="item.icon" :size="18" />{{ item.label }}</a></nav>
 </header>
</template>
<script>
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
 props: { project: { type: Object, required: true }, tab: { type: String, required: true }, base: { type: String, required: true }, legacyUrl: { type: String, required: true } },
 computed: {
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
 methods: { tabUrl(tab) { return interfaceUrl(this.base, { projectId: this.project.id, tab }) } },
}
</script>
